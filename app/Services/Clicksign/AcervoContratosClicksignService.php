<?php

namespace App\Services\Clicksign;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fase 140 Plan 140-01 (TAB-01) — coleta o acervo de contratos de gestão de
 * ADS já existentes na conta Clicksign de produção. Nasce da investigação de
 * viabilidade de 2026-09-08 (`140-CONTEXT.md`): 429 envelopes na conta, 123
 * de gestão de ADS, 85 candidatos fechados — todos MEDIDOS, não re-medir.
 *
 * ⚠️ Este serviço **não lê PDF, não casa com empresa e não escreve nada no
 * banco** (fora de escopo do plano 140-01). Ele para no binário: devolve a
 * lista de envelopes candidatos e sabe baixar o arquivo de um envelope. O
 * plano 140-02 lê o texto; a fase de escrita/confirmação humana vem depois
 * (D-06 de `140-CONTEXT.md`).
 *
 * **A conta é o cofre da empresa inteira, não um repositório de contratos de
 * cliente** (D-01) — convivem ali contrato de funcionário, locação de
 * auditório, mentoria, memorando. `pareceGestaoDeAds()` é o filtro local que
 * separa os 123 que interessam do resto; NÃO existe filtro do lado do
 * servidor para isso (não foi medido — ver aviso em
 * `ClicksignClient::listarEnvelopes()`).
 *
 * **Janela de 20 chamadas/min (docblock de `ClicksignClient`) e validade de
 * ~299s do link de download (D-02, medido).** Os dois cuidados vivem aqui:
 * a pausa entre chamadas (`$pausaMs`) e o download acontecendo na mesma
 * execução da listagem de documentos (`baixarArquivo()`), nunca guardando o
 * link para depois.
 *
 * Este serviço roda dentro de um COMANDO de console, não de um job da fila —
 * `usleep()` bloqueante aqui é aceitável e é exatamente o oposto do que o
 * docblock de `ClicksignClient` recomenda para jobs (D-14: sem `sleep()`
 * longo dentro de um worker).
 */
class AcervoContratosClicksignService
{
    /**
     * Quantidade de envelopes buscada por página na varredura — usa o MESMO
     * teto do client (`ClicksignClient::ENVELOPES_TAMANHO_MAXIMO_PAGINA`),
     * em vez de declarar um número solto aqui. **Não duplicar o valor.** A
     * duplicação (100 aqui, contra o teto real de 50) foi exatamente o que
     * quebrou a primeira rodada real do comando `clicksign:extrair-tabelas`
     * em 2026-09-08 — `Http::fake()` aceita qualquer tamanho de página,
     * então nenhum teste pegou até a chamada bater na API de verdade.
     */
    private const ENVELOPES_POR_PAGINA = ClicksignClient::ENVELOPES_TAMANHO_MAXIMO_PAGINA;

    public function __construct(
        private readonly ClicksignClient $client,
        private readonly int $pausaMs = 3500,
    ) {
    }

    /**
     * Varre TODOS os envelopes da conta, página por página, e devolve só os
     * que (a) parecem gestão de ADS pelo nome e (b) estão numa das
     * `$situacoes` pedidas (default só `closed` — o candidato "fechado" que
     * interessa ao relatório, D-01).
     *
     * ⚠️ `enviar()` (dentro do client) descarta o `meta`/`links` do topo da
     * resposta JSON:API — não existe contador total disponível. O fim da
     * varredura é detectado quando uma página volta vazia ou com menos itens
     * que `ENVELOPES_POR_PAGINA` — nunca por um número de páginas fixo.
     *
     * `$limite`, quando informado, para a varredura assim que o resultado
     * atinge essa quantidade — útil para não gastar a janela de 20/min
     * inteira quando só se quer uma amostra.
     *
     * @param  array<int, string>  $situacoes
     * @return array<int, array{id: string, nome: string, situacao: ?string, data: ?string}>
     */
    public function envelopesDeGestaoDeAds(array $situacoes = ['closed'], ?int $limite = null): array
    {
        $resultado = [];
        $pagina    = 1;

        while (true) {
            $this->pausar();

            $envelopes = $this->client->listarEnvelopes($pagina, self::ENVELOPES_POR_PAGINA);

            foreach ($envelopes as $envelope) {
                $atributos = $envelope['attributes'] ?? [];
                $nome      = $atributos['name'] ?? '';
                $situacao  = $atributos['status'] ?? null;

                if (!$this->pareceGestaoDeAds($nome)) {
                    continue;
                }

                if (!in_array($situacao, $situacoes, true)) {
                    continue;
                }

                $resultado[] = [
                    'id'       => $envelope['id'] ?? null,
                    'nome'     => $nome,
                    'situacao' => $situacao,
                    // Cadeia defensiva (140-CONTEXT.md §envelopesDeGestaoDeAds
                    // da PLAN): o nome exato do campo de data NÃO foi medido —
                    // mesmo padrão de robustez da cadeia de links do
                    // `BaixarPdfContratoAssinadoJob`.
                    'data' => $atributos['finished_at'] ?? $atributos['updated_at'] ?? $atributos['created_at'] ?? null,
                ];

                if ($limite !== null && count($resultado) >= $limite) {
                    return $resultado;
                }
            }

            if (count($envelopes) < self::ENVELOPES_POR_PAGINA) {
                break;
            }

            $pagina++;
        }

        return $resultado;
    }

    /**
     * Filtro local de gestão de ADS (D-01, MEDIDO): normaliza o nome
     * (minúsculas, sem acento, sem pontuação, espaços colapsados) e exige a
     * presença de **gestao** E **ads** — os três padrões observados na conta
     * batem nessa regra:
     *
     *   contrato_gestao_ads_meli_WEHOUSE_SERVICOS_DIGITAIS_LTDA
     *   Contrato Gestao de Ads ECF - KAITON COMERCIO LTDA
     *   Contrato Gestão de ADS _ ECF - ALUMEN COMERCIO
     *
     * ⚠️ **Não filtrar por "prestação de serviços"** — essa expressão pega
     * contrato de FUNCIONÁRIO. Medido: "CONTRATO PRESTAÇÃO DE SERVIÇOS -
     * Jessica De Oliveira" é um contrato de trabalho, não de gestão de ADS
     * de cliente, e cairia no filtro errado se a regra fosse essa.
     */
    public function pareceGestaoDeAds(string $nome): bool
    {
        $normalizado = $this->normalizar($nome);

        return str_contains($normalizado, 'gestao') && str_contains($normalizado, 'ads');
    }

    /**
     * Lista o(s) documento(s) do envelope e baixa o arquivo do primeiro na
     * MESMA execução — nunca em duas etapas separadas.
     *
     * ⚠️ **299 segundos (D-02, MEDIDO).** O link de download é uma URL S3
     * pré-assinada que expira em ~5 minutos. Por isso: o link nunca é
     * guardado em propriedade do serviço, nunca é devolvido ao chamador,
     * nunca é gravado em log ou arquivo. Quem quiser o arquivo de novo tem
     * que listar de novo — não existe "usar depois".
     *
     * ⚠️ **O arquivo baixado pode não ser PDF** — 3 dos 14 da amostra vieram
     * ZIP (header `PK`). Este método não decide o que é: só grava o binário
     * e devolve `ok: true`. Não copiar a checagem `%PDF` do
     * `BaixarPdfContratoAssinadoJob` aqui — ela apagaria justamente os ZIPs
     * que o plano 140-02 precisa examinar.
     *
     * @return array{ok: bool, motivo: ?string, nome_arquivo: ?string}
     */
    public function baixarArquivo(string $envelopeId, string $caminhoAbsoluto): array
    {
        $this->pausar();

        $documentos = $this->client->listarDocumentos($envelopeId);

        $documento = $documentos[0] ?? null;

        if ($documento === null) {
            return [
                'ok'           => false,
                'motivo'       => 'Envelope sem nenhum documento associado.',
                'nome_arquivo' => null,
            ];
        }

        $link = $documento['links']['files']['original']
            ?? $documento['attributes']['files']['original']
            ?? $documento['links']['files']['signed']
            ?? $documento['attributes']['files']['signed']
            ?? $documento['links']['files']['ziped']
            ?? $documento['attributes']['files']['ziped']
            ?? null;

        if ($link === null) {
            return [
                'ok'           => false,
                'motivo'       => 'Documento sem bloco de arquivo — envelope ainda não materializou o arquivo.',
                'nome_arquivo' => null,
            ];
        }

        // Download por streaming direto pra disco, IMEDIATAMENTE após pedir
        // o link — janela de ~299s (D-02). NUNCA ->body() (carregaria o
        // arquivo inteiro em memória) — mesmo padrão de
        // `BaixarPdfContratoAssinadoJob`.
        $resposta = Http::withOptions(['sink' => $caminhoAbsoluto])->timeout(60)->get($link);

        if (!$resposta->successful()) {
            return [
                'ok'           => false,
                'motivo'       => "Download falhou com status HTTP {$resposta->status()}.",
                'nome_arquivo' => null,
            ];
        }

        $nomeArquivo = $documento['attributes']['filename'] ?? null;

        return [
            'ok'           => true,
            'motivo'       => null,
            'nome_arquivo' => $nomeArquivo,
        ];
    }

    /**
     * Pausa entre chamadas à API Clicksign — nunca antes do download S3
     * (`baixarArquivo()` só pausa antes de `listarDocumentos()`, não depois).
     * A conta tem janela MEDIDA de 20 chamadas/min (docblock de
     * `ClicksignClient`) e uma varredura completa passa de 130 chamadas —
     * sem pausa, a rodada toma 429 no meio e volta pela metade, o que é pior
     * do que não rodar: parece que rodou.
     */
    private function pausar(): void
    {
        if ($this->pausaMs > 0) {
            usleep($this->pausaMs * 1000);
        }
    }

    /**
     * Minúsculas, sem acento (`Str::ascii()`), sem pontuação, espaços
     * colapsados — normalização usada só por `pareceGestaoDeAds()`.
     */
    private function normalizar(string $texto): string
    {
        $semAcento    = Str::ascii($texto);
        $minusculo    = mb_strtolower($semAcento);
        $semPontuacao = preg_replace('/[^a-z0-9]+/', ' ', $minusculo) ?? $minusculo;

        return trim(preg_replace('/\s+/', ' ', $semPontuacao) ?? $semPontuacao);
    }
}
