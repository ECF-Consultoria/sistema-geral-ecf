<?php

namespace App\Services\Contratos;

use App\Models\ContratoClicksignAssinado;
use App\Services\Clicksign\ClicksignClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fase 202-08 — lê o acervo de contratos ASSINADOS da Clicksign para saber o
 * término de verdade de cada contrato: prazo escrito no contrato + data da
 * assinatura (decisão do usuário, 09/11/2026).
 *
 * Por que existe: o sistema usava o `hs_recurring_billing_period` do HubSpot
 * como vigência, mas ele é o PLANO DE PAGAMENTO. A Brigada é vendida como
 * R$ 2.500 × `P4M` e o contrato assinado diz "pelo prazo de 12 (doze) meses"
 * — o término saía 8 meses cedo (Ab4 Store, Flipshop, medido em 09/11/2026).
 *
 * Escreve SÓ em `contratos_clicksign_assinados`. Quem transforma isso em
 * término é `TerminoContratoService`.
 *
 * Cuidados herdados da Fase 140 (ver `AcervoContratosClicksignService`):
 * - janela de 20 chamadas/min → pausa de 3,5 s entre chamadas à API;
 * - link de download vale ~299 s → baixa logo depois de listar o documento,
 *   nunca guarda o link; o binário é apagado do disco depois da leitura;
 * - a conta é o cofre da empresa inteira (contrato de funcionário, locação,
 *   memorando) → filtro local `tipoDoEnvelope()`. ⚠️ O `pareceGestaoDeAds()`
 *   da Fase 140 NÃO pega Brigada nem "Contrato — Gestão — X" (sem "ads"),
 *   por isso este filtro é próprio. Nunca filtrar por "prestação de
 *   serviços": isso pega contrato de funcionário.
 *
 * Incremental: envelope já lido com sucesso (qualquer situação diferente de
 * `ilegivel`) não é baixado de novo, salvo `--releer`. Erro num envelope vira
 * `situacao = ilegivel` + motivo; a rodada nunca morre no meio.
 */
class AcervoVigenciaClicksignService
{
    private const FUSO = 'America/Sao_Paulo';

    /** Vigência plausível de contrato de cliente (meses). Fora disso, não é prazo de vigência. */
    private const MESES_MIN = 1;
    private const MESES_MAX = 60;

    public function __construct(
        private readonly ClicksignClient $client,
        private readonly ExtratorTextoContratoService $extrator,
        private readonly TabelaProgressivaContratoParser $parser,
        private readonly EmpresaPalpiteService $palpite,
        private readonly int $pausaMs = 3500,
    ) {}

    /**
     * Varre a conta, lê os contratos novos e grava na tabela.
     *
     * @return array{candidatos: int, pulados: int, lidos: int, por_situacao: array<string, int>, linhas: array<int, array<string, mixed>>}
     */
    public function executar(?int $limite = null, bool $releer = false): array
    {
        $candidatos = $this->envelopesCandidatos();

        $jaLidos = ContratoClicksignAssinado::query()
            ->where('situacao', '!=', ContratoClicksignAssinado::SITUACAO_ILEGIVEL)
            ->pluck('envelope_id')
            ->flip();

        $resumo = ['candidatos' => count($candidatos), 'pulados' => 0, 'lidos' => 0, 'por_situacao' => [], 'linhas' => []];

        foreach ($candidatos as $envelope) {
            if (! $releer && $jaLidos->has($envelope['id'])) {
                $resumo['pulados']++;

                continue;
            }

            if ($limite !== null && $resumo['lidos'] >= $limite) {
                break;
            }

            $atributos = $this->processar($envelope);

            ContratoClicksignAssinado::updateOrCreate(['envelope_id' => $envelope['id']], $atributos);

            $resumo['lidos']++;
            $resumo['por_situacao'][$atributos['situacao']] = ($resumo['por_situacao'][$atributos['situacao']] ?? 0) + 1;
            $resumo['linhas'][] = ['envelope_id' => $envelope['id']] + $atributos;
        }

        return $resumo;
    }

    /**
     * Todos os envelopes `closed` que são contrato de cliente (gestão/brigada/mentoria).
     * `canceled`, `running`, `draft` nunca contam — só contrato assinado vale.
     *
     * @return array<int, array{id: string, nome: string, tipo: string}>
     */
    public function envelopesCandidatos(): array
    {
        $porPagina = ClicksignClient::ENVELOPES_TAMANHO_MAXIMO_PAGINA;
        $saida     = [];
        $pagina    = 1;

        while (true) {
            $this->pausar();
            $envelopes = $this->client->listarEnvelopes($pagina, $porPagina);

            foreach ($envelopes as $envelope) {
                $nome = (string) ($envelope['attributes']['name'] ?? '');
                $tipo = self::tipoDoEnvelope($nome);

                if ($tipo === null || ($envelope['attributes']['status'] ?? null) !== 'closed' || empty($envelope['id'])) {
                    continue;
                }

                $saida[] = ['id' => (string) $envelope['id'], 'nome' => $nome, 'tipo' => $tipo];
            }

            if (count($envelopes) < $porPagina) {
                break;
            }

            $pagina++;
        }

        return $saida;
    }

    /**
     * Filtro local: devolve `gestao`/`mentoria` quando o envelope é contrato de
     * cliente, `null` quando não é. Brigada é serviço de gestão (`gestao`).
     *
     * Padrões medidos (09/11/2026): `Contrato_Projeto_Brigadas_ECF - RAZÃO - 12 Meses`,
     * `Contrato — Brigada — Nome`, `Contrato — Gestão — Nome`, `Contrato Gestao de Ads ECF - ...`,
     * `contrato_gestao_ads_meli_...`, `Contrato — Mentoria — Nome`.
     */
    public static function tipoDoEnvelope(string $nome): ?string
    {
        $n = self::normalizar($nome);

        if (! str_contains($n, 'contrato')) {
            return null;
        }

        // Aditivo/distrato não carregam a vigência do contrato principal.
        foreach (['distrato', 'aditivo', 'rescisao'] as $fora) {
            if (str_contains($n, $fora)) {
                return null;
            }
        }

        if (str_contains($n, 'mentoria')) {
            return ContratoClicksignAssinado::TIPO_MENTORIA;
        }

        if (str_contains($n, 'brigada')) {
            return ContratoClicksignAssinado::TIPO_GESTAO;
        }

        if (str_contains($n, 'gestao') && preg_match('/\bads\b/', $n)) {
            return ContratoClicksignAssinado::TIPO_GESTAO;
        }

        // Nome que o próprio sistema dá ao envelope: "Contrato — Gestão — <nome>".
        if (str_starts_with($n, 'contrato gestao ')) {
            return ContratoClicksignAssinado::TIPO_GESTAO;
        }

        return null;
    }

    /**
     * Prazo de vigência em meses: primeiro pelo TEXTO do contrato, depois pelo
     * sufixo "- N Meses" do nome do envelope.
     *
     * Frases medidas: "terá vigência de 12 (doze) meses, contados da data de sua
     * assinatura" (Gestão), "pelo prazo de 12 (doze) meses" (Brigada), "vigência
     * de 6 (seis) meses" (Quater). ⚠️ A cláusula de não concorrência diz "pelo
     * período de 12 (doze) meses após o seu término" — não é vigência: "período"
     * não casa e qualquer prazo seguido de "após o término" é descartado.
     *
     * @return array{meses: ?int, fonte: ?string}
     */
    public static function lerPrazo(?string $texto, string $nomeEnvelope): array
    {
        if ($texto !== null && $texto !== '') {
            $t = self::normalizarTexto($texto);

            $regex = '/\b(?:vigencia|prazo)(?:\s+[a-z]+){0,3}?\s+de\s+(\d{1,3})\s*(?:\([^)]{0,30}\))?\s*mes(?:es)?\b/';

            if (preg_match_all($regex, $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $ocorrencia) {
                    $meses = (int) $ocorrencia[1][0];
                    $fim   = $ocorrencia[0][1] + strlen($ocorrencia[0][0]);
                    $depois = substr($t, $fim, 80);

                    if (preg_match('/\bapos\s+(?:o|a)\s+(?:seu\s+|sua\s+)?(?:termino|encerramento|fim|rescisao|extincao)/', $depois)) {
                        continue;
                    }

                    if ($meses >= self::MESES_MIN && $meses <= self::MESES_MAX) {
                        return ['meses' => $meses, 'fonte' => ContratoClicksignAssinado::FONTE_TEXTO];
                    }
                }
            }
        }

        if (preg_match('/\b(\d{1,2})\s+mes(?:es)?\b/', self::normalizar($nomeEnvelope), $m)) {
            $meses = (int) $m[1];

            if ($meses >= self::MESES_MIN && $meses <= self::MESES_MAX) {
                return ['meses' => $meses, 'fonte' => ContratoClicksignAssinado::FONTE_NOME];
            }
        }

        return ['meses' => null, 'fonte' => null];
    }

    /**
     * Data da assinatura pelos eventos do documento: `document_closed` > `auto_close`
     * > o último `sign`. Devolve no fuso de São Paulo (Ab4: `document_closed`
     * 2026-07-30T09:16:01-03:00). A listagem de envelopes não tem data de fechamento.
     *
     * @param  array<int, array<string, mixed>>  $eventos
     */
    public static function dataAssinatura(array $eventos): ?Carbon
    {
        $porNome = [];

        foreach ($eventos as $evento) {
            $nome    = $evento['attributes']['name'] ?? null;
            $criado  = $evento['attributes']['created'] ?? null;

            if (! is_string($nome) || ! is_string($criado) || $criado === '') {
                continue;
            }

            try {
                $porNome[$nome][] = Carbon::parse($criado)->setTimezone(self::FUSO);
            } catch (Throwable) {
                continue;
            }
        }

        foreach (['document_closed', 'auto_close', 'sign'] as $preferido) {
            if (! empty($porNome[$preferido])) {
                return collect($porNome[$preferido])->sortBy(fn (Carbon $c) => $c->getTimestamp())->last();
            }
        }

        return null;
    }

    /**
     * Nome do cliente sem os prefixos/sufixos dos padrões de envelope — é o que
     * vai para o casamento por nome do `EmpresaPalpiteService`.
     */
    public static function nomeDoCliente(string $nomeEnvelope): string
    {
        $nome = trim($nomeEnvelope);

        $padroes = [
            '/^contrato[\s_]*projeto[\s_]*brigadas?[\s_]*ecf\s*[-–—_]\s*/iu',
            '/^contrato\s*[-–—]\s*(?:gest[aã]o|brigada|mentoria)\s*[-–—]\s*/iu',
            '/^contrato[\s_]+gest[aã]o[\s_]+(?:de[\s_]+)?ads(?:[\s_]+ecf)?(?:[\s_]+meli)?\s*[-–—_]*\s*/iu',
        ];

        foreach ($padroes as $padrao) {
            $nome = preg_replace($padrao, '', $nome) ?? $nome;
        }

        $nome = preg_replace('/\s*\(\d+\)\s*$/u', '', $nome) ?? $nome;                 // re-envio "(1)"
        $nome = preg_replace('/\s*[-–—]\s*\d{1,2}\s*mes(?:es)?\s*$/iu', '', $nome) ?? $nome; // "- 12 Meses"

        return trim($nome) !== '' ? trim($nome) : $nomeEnvelope;
    }

    /**
     * Lê UM envelope de ponta a ponta. Nunca lança: falha vira `ilegivel` + motivo.
     *
     * @param  array{id: string, nome: string, tipo: string}  $envelope
     * @return array<string, mixed>
     */
    private function processar(array $envelope): array
    {
        $base = [
            'envelope_nome'     => mb_substr($envelope['nome'], 0, 255),
            'tipo'              => $envelope['tipo'],
            'company_id'        => null,
            'confianca'         => null,
            'cnpj_lido'         => null,
            'razao_social_lida' => null,
            'assinado_em'       => null,
            'vigencia_meses'    => null,
            'vigencia_fonte'    => null,
        ];

        try {
            $this->pausar();
            $documento = $this->client->listarDocumentos($envelope['id'])[0] ?? null;

            if ($documento === null || empty($documento['id'])) {
                return $base + ['situacao' => ContratoClicksignAssinado::SITUACAO_ILEGIVEL, 'motivo' => 'envelope sem documento'];
            }

            [$texto, $motivoTexto] = $this->baixarELer($documento);

            $prazo = self::lerPrazo($texto, $envelope['nome']);

            $this->pausar();
            $assinadoEm = self::dataAssinatura($this->client->listarEventosDoDocumento($envelope['id'], (string) $documento['id']));

            $analise = $texto !== null ? $this->parser->analisar($texto) : ['cnpj' => null, 'razao_social' => null];
            $palpite = $this->palpite->palpitar($analise['cnpj'] ?? null, $analise['razao_social'] ?? null, self::nomeDoCliente($envelope['nome']));

            $seguro = $palpite['company_id'] !== null
                && ($palpite['confianca'] === 'certo' || ($palpite['confianca'] === 'provavel' && ! $palpite['ambiguo']));

            $atributos = array_merge($base, [
                'confianca'         => $palpite['confianca'],
                'cnpj_lido'         => $analise['cnpj'] ?? null,
                'razao_social_lida' => isset($analise['razao_social']) ? mb_substr((string) $analise['razao_social'], 0, 255) : null,
                'assinado_em'       => $assinadoEm?->format('Y-m-d H:i:s'),
                'vigencia_meses'    => $prazo['meses'],
                'vigencia_fonte'    => $prazo['fonte'],
                'company_id'        => $seguro ? $palpite['company_id'] : null,
            ]);

            $motivos = array_filter([$motivoTexto]);

            if (! $seguro) {
                $candidatos = collect($palpite['candidatos'] ?? [])
                    ->map(fn ($c) => "{$c['nome']} (#{$c['company_id']}, ".round((float) $c['pontuacao']).'%)')
                    ->implode('; ');

                $situacao  = $candidatos === '' ? ContratoClicksignAssinado::SITUACAO_SEM_EMPRESA : ContratoClicksignAssinado::SITUACAO_DUVIDOSO;
                $motivos[] = $candidatos === '' ? 'nenhuma empresa parecida' : "empresa não confirmada — candidatos: {$candidatos}";
            } elseif ($prazo['meses'] === null) {
                $situacao  = ContratoClicksignAssinado::SITUACAO_SEM_PRAZO;
                $motivos[] = 'prazo de vigência não encontrado no texto nem no nome';
            } elseif ($assinadoEm === null) {
                // Volta a ser lido na próxima rodada (ilegivel não conta como lido).
                $situacao  = ContratoClicksignAssinado::SITUACAO_ILEGIVEL;
                $motivos[] = 'não deu para achar a data da assinatura nos eventos';
            } else {
                $situacao = ContratoClicksignAssinado::SITUACAO_CASADO;
            }

            if ($texto === null && $prazo['meses'] === null) {
                $situacao = ContratoClicksignAssinado::SITUACAO_ILEGIVEL;
            }

            return $atributos + [
                'situacao' => $situacao,
                'motivo'   => $motivos === [] ? null : mb_substr(implode(' | ', $motivos), 0, 1000),
            ];
        } catch (Throwable $e) {
            // Só id do envelope e motivo curto — nunca texto do contrato nem o link S3.
            Log::channel('ecf-webhooks')->error("[Renovacao] falha ao ler contrato assinado do envelope {$envelope['id']}: ".mb_substr($e->getMessage(), 0, 200));

            return $base + [
                'situacao' => ContratoClicksignAssinado::SITUACAO_ILEGIVEL,
                'motivo'   => 'falha na leitura: '.mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * Baixa o arquivo na sequência imediata da listagem (link vale ~299 s) e
     * extrai o texto. O binário nunca fica no disco.
     *
     * @return array{0: ?string, 1: ?string} [texto, motivo]
     */
    private function baixarELer(array $documento): array
    {
        $link = $documento['links']['files']['original']
            ?? $documento['attributes']['files']['original']
            ?? $documento['links']['files']['signed']
            ?? $documento['attributes']['files']['signed']
            ?? $documento['links']['files']['ziped']
            ?? $documento['attributes']['files']['ziped']
            ?? null;

        if ($link === null) {
            return [null, 'documento sem arquivo para baixar'];
        }

        $temporario = tempnam(sys_get_temp_dir(), 'contrato_vigencia_');

        try {
            $resposta = Http::withOptions(['sink' => $temporario])->timeout(60)->get($link);

            if (! $resposta->successful()) {
                return [null, "download falhou (HTTP {$resposta->status()})"];
            }

            $binario = file_get_contents($temporario);
        } finally {
            @unlink($temporario);
        }

        if ($binario === false || $binario === '') {
            return [null, 'arquivo baixado vazio'];
        }

        $extraido = $this->extrator->extrair($binario);

        return [$extraido['texto'], $extraido['texto'] === null ? ($extraido['motivo'] ?? 'texto ilegível') : null];
    }

    private function pausar(): void
    {
        if ($this->pausaMs > 0) {
            usleep($this->pausaMs * 1000);
        }
    }

    private static function normalizar(string $texto): string
    {
        $n = mb_strtolower(Str::ascii($texto));
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;

        return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    }

    /** Texto do contrato: minúsculas, sem acento, espaços colapsados (mantém parênteses). */
    private static function normalizarTexto(string $texto): string
    {
        $n = mb_strtolower(Str::ascii($texto));

        return preg_replace('/\s+/', ' ', $n) ?? $n;
    }
}
