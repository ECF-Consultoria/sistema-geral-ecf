<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Support\Collection;

/**
 * FechamentoFaixaResolver — responde duas perguntas do fechamento mensal
 * (Fase 137/138/141): "qual tabela de faixas vale para esta empresa (ou
 * para o grupo dela)?" (D-01, D-05, D-13, e a virada de regra da Fase 141)
 * e "em que faixa um faturamento cai?" (D-02b, faixa-piso).
 *
 * Serviço PURO de leitura: não grava nada, não decide faturamento — só
 * tabela e classificação. Quem soma ML+Shopee é o `FechamentoRollupService`
 * (Fase 137); quem congela o resultado é o writer da Fase 137.
 *
 * ### Shape único de retorno (`paraEmpresa()` e `paraGrupo()`)
 * As 9 chaves abaixo estão SEMPRE presentes — `null` quando não se aplica —
 * para o consumidor nunca precisar de coalesce:
 * `origem` ('grupo'|'propria'|'servico'), `servico_id`, `servico_nome`,
 * `grupo_id`, `grupo_nome`, `herdada_de_company_id`,
 * `herdada_de_company_name`, `faixas`, `procedencia`.
 *
 * `procedencia` (Fase 141, D-04/D-05) só é preenchida quando `origem ===
 * 'propria'`, a partir da coluna `origem` da PRIMEIRA linha da tabela de
 * empresa (`EmpresaFaixaFaturamento::ORIGEM_MANUAL`/`ORIGEM_CONTRATO`/
 * `ORIGEM_PRESUMIDA_SERVICO`) — nos demais casos (`'grupo'`, `'servico'`)
 * vale `null`: tabela de grupo é sempre cadastro humano (não tem coluna
 * `origem`) e tabela de serviço não é mais régua aplicável quando a flag
 * está ligada.
 *
 * ### paraEmpresa() — ordem de resolução
 *
 * **Com a flag `FechamentoRegraTabela::CHAVE` LIGADA (Fase 141, D-01/D-04)
 * — regra NOVA, definitiva:**
 * 1. **Tabela do GRUPO** (`GrupoFaixaFaturamento`) — se a empresa pertence a
 *    um grupo (`company_group_id`) e existe QUALQUER linha de faixa para
 *    esse grupo, ela vence sobre a tabela própria da empresa.
 * 2. Tabela PRÓPRIA da empresa (`EmpresaFaixaFaturamento`) — se existir
 *    QUALQUER linha, ela vale inteira (D-13, all-or-nothing).
 * 3. Sem tabela de grupo nem própria: `null` — a tabela do SERVIÇO nunca
 *    mais entra como régua. Vira estado visível no fechamento
 *    (`ESTADO_VALOR_FIXO` quando há contrato mensal ativo, `ESTADO_SEM_TABELA`
 *    quando não há), nunca faixa aproximada.
 *
 * **Com a flag DESLIGADA — regra ANTIGA, transitória (nasce ativa, existe
 * só até a virada do plano 141-07; sem a materialização do plano 141-03
 * aplicada em produção, 127 das 201 empresas medidas em 2026-09-09 ficariam
 * sem régua nenhuma se este degrau sumisse antes da hora):**
 * 1. Tabela do GRUPO — mesmo degrau 1 acima.
 * 2. Tabela PRÓPRIA da empresa — mesmo degrau 2 acima.
 * 3. Serviço candidato entre os contratos ativos da empresa — candidato é
 *    quem tem `plataforma` preenchida OU `setor` financeiro
 *    (`Servico::SETORES_FINANCEIROS`), critério em OU por robustez (ver
 *    comentário em `escolherServicoCandidato()`).
 * 4. Contrato combinado (D-05): se o candidato tem `contrato_junto_com_servico_id`
 *    apontando para outro candidato ativo da mesma empresa, o DONO vence —
 *    mesma regra de `ContratoClicksignService::iniciarParaEmpresa()`.
 * 5. `ServicoFaixaFaturamento` do serviço escolhido — vazio vira `null`
 *    (estado "A DEFINIR", nunca faixa aproximada).
 *
 * ### paraGrupo() — tabela aplicável ao GRUPO (Fase 138, D-01)
 * 1. Tabela do próprio grupo, quando houver — `herdada_de_*` fica `null`.
 * 2. Sem tabela de grupo: delega para `paraEmpresa($ancora)` e anexa
 *    `herdada_de_company_id`/`herdada_de_company_name` da âncora —
 *    `herdada_de_*` só é preenchido nesse caso, nunca quando a tabela do
 *    grupo existe. É essa informação que evita a herança invisível: a tela
 *    precisa dizer de qual empresa a tabela foi herdada. Com a flag da Fase
 *    141 ligada, essa herança só encontra alguma coisa quando a âncora tem
 *    tabela PRÓPRIA — `paraEmpresa($ancora)` já não olha mais o serviço.
 * 3. Sem tabela de grupo e sem âncora informada (ou âncora sem nenhuma
 *    tabela resolvida): `null` — estado "A DEFINIR", nunca faixa
 *    aproximada.
 *
 * ### classificar() — regra de corte (D-02b)
 * Primeira faixa (em ordem crescente) cujo `limite_superior` seja nulo
 * (faixa aberta) ou maior-ou-igual ao faturamento. `null` quando nenhuma
 * faixa cobre o valor — caso real: a tabela de Shopee não tem faixa aberta.
 */
class FechamentoFaixaResolver
{
    public function __construct(private FechamentoRegraTabela $regra)
    {
    }

    /**
     * Resolve a tabela de faixas aplicável a uma empresa (grupo → própria →
     * serviço [só com a flag da Fase 141 desligada], D-01).
     *
     * @return array{origem: string, servico_id: int|null, servico_nome: string|null, grupo_id: int|null, grupo_nome: string|null, herdada_de_company_id: int|null, herdada_de_company_name: string|null, faixas: Collection, procedencia: string|null}|null
     */
    public function paraEmpresa(Company $company): ?array
    {
        // Fase 138, D-01: tabela do GRUPO vence tudo abaixo dela.
        if ($company->company_group_id !== null) {
            $faixasDoGrupo = GrupoFaixaFaturamento::where('company_group_id', $company->company_group_id)
                ->ordenadas()
                ->get();

            if ($faixasDoGrupo->isNotEmpty()) {
                return $this->shapeGrupo($company->grupo, $faixasDoGrupo);
            }
        }

        $excecaoPropria = EmpresaFaixaFaturamento::where('company_id', $company->id)
            ->ordenadas()
            ->get();

        // D-13: a existência de QUALQUER linha própria substitui a tabela
        // inteira do serviço — nunca linha a linha.
        if ($excecaoPropria->isNotEmpty()) {
            return $this->shape(
                'propria',
                faixas: $excecaoPropria,
                procedencia: $excecaoPropria->first()->origem ?? null,
            );
        }

        // Fase 141 (D-01/D-04): com a flag ligada, a tabela do SERVIÇO
        // deixou de ser régua aplicável — sem tabela de grupo nem própria,
        // a empresa fica sem tabela nenhuma (estado visível no fechamento:
        // `ESTADO_VALOR_FIXO` com contrato mensal, `ESTADO_SEM_TABELA` sem
        // ele), nunca herda a tabela do serviço. Degrau 3-5 abaixo (a régua
        // ANTIGA) só executa com a flag desligada — mantido de propósito
        // para o rollback sem deploy e para a comparação ANTES×DEPOIS do
        // plano 141-05.
        if ($this->regra->ativa()) {
            return null;
        }

        $servicoEscolhido = $this->escolherServicoCandidato($company);

        if ($servicoEscolhido === null) {
            return null;
        }

        $faixasDoServico = ServicoFaixaFaturamento::where('servico_id', $servicoEscolhido->id)
            ->ordenadas()
            ->get();

        // Serviço candidato sem tabela cadastrada — estado "A DEFINIR" até o
        // cadastro do checkpoint do plano 10, nunca faixa aproximada.
        if ($faixasDoServico->isEmpty()) {
            return null;
        }

        return $this->shape(
            'servico',
            servicoId: $servicoEscolhido->id,
            servicoNome: $servicoEscolhido->nome,
            faixas: $faixasDoServico,
        );
    }

    /**
     * Resolve a tabela de faixas aplicável a um GRUPO (Fase 138, D-01):
     * tabela própria do grupo quando houver, senão a tabela da empresa
     * âncora com a herança marcada explicitamente.
     *
     * @return array{origem: string, servico_id: int|null, servico_nome: string|null, grupo_id: int|null, grupo_nome: string|null, herdada_de_company_id: int|null, herdada_de_company_name: string|null, faixas: Collection, procedencia: string|null}|null
     */
    public function paraGrupo(CompanyGroup $grupo, ?Company $ancora): ?array
    {
        $faixasDoGrupo = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)
            ->ordenadas()
            ->get();

        if ($faixasDoGrupo->isNotEmpty()) {
            return $this->shapeGrupo($grupo, $faixasDoGrupo);
        }

        if ($ancora === null) {
            return null;
        }

        $resultadoAncora = $this->paraEmpresa($ancora);

        if ($resultadoAncora === null) {
            return null;
        }

        // Herança invisível é o defeito que D-01 veio corrigir: quem lê
        // precisa saber de qual empresa a tabela foi herdada.
        $resultadoAncora['herdada_de_company_id']   = $ancora->id;
        $resultadoAncora['herdada_de_company_name'] = $ancora->name;

        return $resultadoAncora;
    }

    /**
     * Monta o shape de retorno com origem 'grupo' — usado por
     * `paraEmpresa()` (degrau novo) e `paraGrupo()` (tabela própria).
     */
    private function shapeGrupo(?CompanyGroup $grupo, Collection $faixas): array
    {
        return $this->shape(
            'grupo',
            grupoId: $grupo?->id,
            grupoNome: $grupo?->name,
            faixas: $faixas,
        );
    }

    /**
     * Monta o shape único de retorno com as 9 chaves sempre presentes
     * (Fase 141 acrescentou `procedencia`).
     */
    private function shape(
        string $origem,
        ?int $servicoId = null,
        ?string $servicoNome = null,
        ?int $grupoId = null,
        ?string $grupoNome = null,
        ?int $herdadaDeCompanyId = null,
        ?string $herdadaDeCompanyName = null,
        Collection $faixas = new Collection(),
        ?string $procedencia = null,
    ): array {
        return [
            'origem'                   => $origem,
            'servico_id'               => $servicoId,
            'servico_nome'             => $servicoNome,
            'grupo_id'                 => $grupoId,
            'grupo_nome'               => $grupoNome,
            'herdada_de_company_id'    => $herdadaDeCompanyId,
            'herdada_de_company_name'  => $herdadaDeCompanyName,
            'faixas'                   => $faixas,
            'procedencia'              => $procedencia,
        ];
    }

    /**
     * Escolhe o serviço "dono" da tabela entre os contratos ativos da
     * empresa, aplicando o contrato combinado (D-05).
     */
    private function escolherServicoCandidato(Company $company): ?Servico
    {
        $contratosAtivos = $company->contratosServico()
            ->where('ativo', true)
            ->with('servico')
            ->get()
            ->filter(fn (ContratoServico $contrato) => $contrato->servico !== null);

        // Candidato: serviço ativo com `plataforma` preenchida OU `setor`
        // financeiro. Critério em OU (não só setor) por robustez — os três
        // serviços com tabela progressiva própria, medidos em produção em
        // 2026-09-02 (Gestão id 6, Gestão de ADS Shopee id 9, Brigada id
        // 10), já caem em SETORES_FINANCEIROS, então o OU não corrige uma
        // falha viva; ele evita que o resolver dependa de `setor` estar
        // correto num serviço novo — `setor` é configuração preenchida à
        // mão em produção, pode nascer errada.
        $candidatos = $contratosAtivos->filter(function (ContratoServico $contrato) {
            $servico = $contrato->servico;

            return $servico->plataforma !== null
                || in_array($servico->setor, Servico::SETORES_FINANCEIROS, true);
        });

        if ($candidatos->isEmpty()) {
            return null;
        }

        $idsCandidatos = $candidatos->pluck('servico_id')->unique();

        // Chave de agrupamento: o DONO do contrato combinado, se ele também
        // estiver entre os candidatos ativos desta empresa (D-05); senão o
        // próprio serviço. Mesma regra de
        // `ContratoClicksignService::iniciarParaEmpresa()::$grupoDoServico`.
        $chaveDoGrupo = function (Servico $servico) use ($idsCandidatos): int {
            $donoId = $servico->contrato_junto_com_servico_id;

            if ($donoId !== null && $idsCandidatos->contains($donoId)) {
                return (int) $donoId;
            }

            return (int) $servico->id;
        };

        $servicosPorChave = $candidatos
            ->groupBy(fn (ContratoServico $contrato) => $chaveDoGrupo($contrato->servico))
            ->map(function (Collection $membros, int $chave) {
                // Representante do grupo: o dono (quando ele próprio é
                // candidato — sempre é, pela condição de $chaveDoGrupo), ou
                // o único membro quando o grupo tem 1 serviço sem dono ativo.
                $dono = $membros->first(fn (ContratoServico $c) => (int) $c->servico_id === $chave);

                return ($dono ?? $membros->first())->servico;
            })
            ->values();

        if ($servicosPorChave->count() === 1) {
            return $servicosPorChave->first();
        }

        // Desempate determinístico entre grupos independentes (sem dono em
        // comum): plataforma Mercado Livre antes de Shopee; persistindo
        // empate, o menor servicos.id.
        return $servicosPorChave
            ->sort(function (Servico $a, Servico $b) {
                $prioridadeA = $a->plataforma === 'Mercado Livre' ? 0 : 1;
                $prioridadeB = $b->plataforma === 'Mercado Livre' ? 0 : 1;

                if ($prioridadeA !== $prioridadeB) {
                    return $prioridadeA <=> $prioridadeB;
                }

                return $a->id <=> $b->id;
            })
            ->first();
    }

    /**
     * Classifica um faturamento na tabela de faixas informada.
     *
     * Percorre em ordem crescente e devolve a PRIMEIRA faixa cujo
     * `limite_superior` seja nulo (faixa aberta) ou maior-ou-igual ao
     * faturamento. `null` quando `$faixas` está vazia OU quando nenhuma
     * faixa cobre o valor — a tabela de Shopee não tem faixa aberta
     * (última faixa tem teto), então empresa acima do teto fica sem faixa;
     * isso é estado visível ("sem_tabela"/"A DEFINIR"), nunca a última
     * faixa por aproximação nem R$ 0.
     *
     * @return array{ordem: int, label: string, valor: float, valor_e_piso: bool, limite_inferior: float, limite_superior: float|null}|null
     */
    public function classificar(float $faturamento, Collection $faixas): ?array
    {
        if ($faixas->isEmpty()) {
            return null;
        }

        $limiteInferior = 0.0;

        foreach ($faixas->sortBy('ordem')->values() as $faixa) {
            $limiteSuperior = $faixa->limite_superior !== null
                ? (float) $faixa->limite_superior
                : null;

            if ($limiteSuperior === null || $limiteSuperior >= $faturamento) {
                return [
                    'ordem'           => (int) $faixa->ordem,
                    'label'           => $limiteSuperior === null ? 'maxima' : ('faixa_' . $faixa->ordem),
                    'valor'           => (float) $faixa->valor,
                    'valor_e_piso'    => (bool) $faixa->valor_e_piso,
                    'limite_inferior' => $limiteInferior,
                    'limite_superior' => $limiteSuperior,
                ];
            }

            $limiteInferior = $limiteSuperior;
        }

        return null;
    }
}
