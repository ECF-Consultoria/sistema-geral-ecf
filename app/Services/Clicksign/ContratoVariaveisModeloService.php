<?php

namespace App\Services\Clicksign;

use App\Models\ContratoAssinatura;
use App\Services\ContratoPdfService;
use Carbon\Carbon;

/**
 * ContratoVariaveisModeloService — Fase 126, Plano 126-09 (PDF-01).
 *
 * A ponte entre o que `ContratoPdfService::montarDados()` sabe sobre um
 * contrato (array ANINHADO) e o que `ClicksignClient::anexarDocumentoPorModelo()`
 * exige em `template.data` (hash PLANO, `{{chave}}` do `.docx`). Decisões
 * D-16/D-18/D-19 (`126-CONTEXT.md`), lista fechada em
 * `126-VARIAVEIS-DO-MODELO.md` §4 ("Lista final").
 *
 * **O mapa é explícito, uma chave por linha — não um achatamento genérico.**
 * Achatar automaticamente amarraria o nome de cada `{{variável}}` do `.docx`
 * à estrutura interna de `montarDados()`: quem renomear uma chave lá faria a
 * variável sumir do contrato assinado SEM erro nenhum da API — é o modo de
 * falha silencioso desta integração (T-126-38), mitigado só pelo comando de
 * sondagem do plano 126-10, que confronta `nomes()` com os `{{nomes}}` reais
 * do `.docx`. Mudar um nome aqui sem mudar o `.docx` tem o mesmo efeito.
 *
 * D-19 (opção B — servicos concatenados numa variável só, um envelope por
 * empresa): `servico_contratado` concatena TODOS os serviços do snapshot.
 * Não é um serviço por índice, não é tabela em loop (opção D, recusada e
 * NÃO MEDIDA — ver `126-VARIAVEIS-DO-MODELO.md` §4.3).
 *
 * Puro por decisão (T-126-40): não consulta `ContratoServico`, `Http`,
 * `Storage`, `Log` nem `Cache` — mesma entrada, mesma saída, sempre. Os
 * valores continuam vindo do `servicos_snapshot` congelado via
 * `ContratoPdfService::montarDados()` (D-04), nunca da tabela ao vivo.
 */
class ContratoVariaveisModeloService
{
    /**
     * Quick 260821-m9h — caso simples deliberado, NÃO a solução final do
     * parcelamento escalonado (que exigiria consolidar as duas linhas de
     * `ContratoServico` do mesmo serviço, somar valores e compor a frase
     * dinamicamente — fora de escopo deste quick).
     *
     * A guarda `servicos_duplicados` (commit `5af2b4d1`) recusa gerar
     * contrato quando o mesmo serviço aparece em mais de um
     * `ContratoServico` ativo, que é exatamente a forma do pagamento
     * escalonado. Logo, todo contrato que hoje CONSEGUE ser gerado tem uma
     * linha só por serviço — o caso simples — e esta frase constante cobre
     * 100% dos casos geráveis.
     *
     * ⚠️ Texto ACOPLADO ao modelo de Gestão (Cláusula 2.1.2 daquele `.docx`,
     * `contrato-kive-ESPECIFICACAO-VARIAVEIS.md` §"Os três casos", caso 1).
     * Se outro serviço passar a usar `{{plano_parcelas}}`, a frase estará
     * errada — não é genérica.
     */
    public const PLANO_PARCELAS_CASO_SIMPLES = 'As parcelas seguirão a faixa apurada na forma da Cláusula 2.1.2.';

    public function __construct(private ContratoPdfService $dados)
    {
    }

    /**
     * Traduz o contrato no hash plano que vai direto em `template.data` de
     * `anexarDocumentoPorModelo()`.
     *
     * @param  array{dia_vencimento?: string, forma_pagamento?: string, endereco?: string, bairro?: string, cidade?: string, estado?: string, cep?: string}  $complementos
     * @return array{variaveis: array<string, string>, campos_pendentes: array<int, string>}
     */
    public function montar(ContratoAssinatura $contrato, array $complementos = []): array
    {
        $dados = $this->dados->montarDados($contrato, $complementos);

        $variaveis = [];

        foreach (self::mapa() as $nome => $extrator) {
            $variaveis[$nome] = (string) $extrator($dados, $this);
        }

        return [
            'variaveis'        => $variaveis,
            // Repassada fielmente — a ponte não esconde nem inventa pendência,
            // só traduz o que montarDados() já apurou (D-05 herdado).
            'campos_pendentes' => $dados['campos_pendentes'],
        ];
    }

    /**
     * Os nomes das variáveis que `montar()` emite — sem precisar montar um
     * contrato de verdade. Derivado do mesmo `mapa()` que `montar()` usa:
     * não existe uma segunda lista de nomes mantida à mão neste arquivo.
     *
     * Consumido pelo comando de sondagem do plano 126-10, que confronta esta
     * lista com os `{{nomes}}` reais do `.docx` cadastrado na Clicksign.
     *
     * @return array<int, string>
     */
    public static function nomes(): array
    {
        return array_keys(self::mapa());
    }

    /**
     * O mapa único: nome da variável → closure que extrai o valor do array
     * de `montarDados()`. Fonte única para `montar()` (que executa as
     * closures) e `nomes()` (que só lê as chaves, sem precisar de um array
     * `$dados` de verdade).
     *
     * A ordem aqui segue `126-VARIAVEIS-DO-MODELO.md` §4.1.
     *
     * @return array<string, \Closure(array, self): mixed>
     */
    private static function mapa(): array
    {
        return [
            'razao_social'          => fn (array $d) => $d['empresa']['razao_social'],
            'cnpj'                  => fn (array $d) => $d['empresa']['cnpj'],
            'endereco'              => fn (array $d) => $d['empresa']['endereco'],
            // Quick 260821-cq0 — endereço volta a ser 5 pedaços separados: o
            // contrato de Gestão do jurídico usa `{{bairro}}`, `{{cidade}}`,
            // `{{estado}}` e `{{cep}}` como variáveis próprias, ao lado de
            // `{{endereco}}` (que agora é só o logradouro).
            'bairro'                => fn (array $d) => $d['empresa']['bairro'],
            'cidade'                => fn (array $d) => $d['empresa']['cidade'],
            'estado'                => fn (array $d) => $d['empresa']['estado'],
            'cep'                   => fn (array $d) => $d['empresa']['cep'],
            'servico_contratado'    => fn (array $d, self $self) => $self->concatenarServicos($d['servicos']),
            'valor_mensal'          => fn (array $d) => $d['totais']['valor_mensal_formatado'],
            'vigencia_inicio'       => fn (array $d) => $d['vigencia']['inicio'],
            'vigencia_fim'          => fn (array $d) => $d['vigencia']['fim'],
            // Quick 260819-guy (2026-08-19) — deixou de ser fixo A DEFINIR
            // (território previsto para a Fase 131 em
            // 126-VARIAVEIS-DO-MODELO.md §4.1, adiado até então). Lê o dado
            // real via montarDados()/ContratoPdfService, com o mesmo
            // placeholder como rede de segurança quando ausente.
            'data_primeira_parcela' => fn (array $d) => $d['pagamento']['data_primeira_parcela'],
            'dia_vencimento'        => fn (array $d) => $d['pagamento']['dia_vencimento'],
            'data_assinatura'       => fn (array $d, self $self) => $self->dataPorExtenso($d['gerado_em']),
            // Quick 260821-m9h — modelo de Gestão substituído pelo jurídico
            // com {{plano_parcelas}}; caso simples deliberado (ver docblock
            // da constante). Não lê ContratoServico nem calcula nada —
            // devolve sempre a mesma constante, mantendo a classe pura.
            'plano_parcelas'        => fn () => self::PLANO_PARCELAS_CASO_SIMPLES,
        ];
    }

    /**
     * D-19 (opção B): concatena os nomes de todos os serviços do snapshot
     * numa string única. Um serviço só devolve o próprio nome; dois ou mais
     * são unidos por vírgula, com " e " antes do último — não é tabela em
     * loop, não é índice fixo.
     *
     * @param  array<int, array{servico: string}>  $servicos
     */
    private function concatenarServicos(array $servicos): string
    {
        $nomes = array_map(fn (array $servico) => $servico['servico'], $servicos);

        if (count($nomes) <= 1) {
            return $nomes[0] ?? ContratoPdfService::PLACEHOLDER;
        }

        $ultimo = array_pop($nomes);

        return implode(', ', $nomes) . ' e ' . $ultimo;
    }

    /**
     * `data_assinatura` por extenso em pt-BR, derivada de `gerado_em`
     * (`d/m/Y H:i`) — nunca uma segunda leitura de `now()`. Nome de mês por
     * helper privado nesta própria classe, mesmo precedente de
     * `RelatorioMensalPdfService::mesLabelPt()` — sem criar helper global novo.
     */
    private function dataPorExtenso(string $geradoEm): string
    {
        $data = Carbon::createFromFormat('d/m/Y H:i', $geradoEm);

        return "{$data->day} de {$this->mesPorExtenso($data->month)} de {$data->year}";
    }

    /**
     * Nome do mês por extenso, minúsculo, pt-BR — precedente:
     * `RelatorioMensalPdfService::mesLabelPt()`.
     */
    private function mesPorExtenso(int $mes): string
    {
        $meses = [
            1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
            5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
            9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
        ];

        return $meses[$mes] ?? (string) $mes;
    }
}
