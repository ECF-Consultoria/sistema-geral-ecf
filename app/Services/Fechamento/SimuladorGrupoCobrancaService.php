<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Servico;
use App\Support\CobrancaCalculator;
use Illuminate\Support\Collection;

/**
 * SimuladorGrupoCobrancaService — responde, ANTES de salvar qualquer coisa:
 * *"se eu pendurar estes grupos neste pai, o que acontece com a cobrança?"*
 * (Fase 143, plano 02, T2).
 *
 * ## Por que este serviço existe
 * A Fase 143 muda cobrança **para baixo e em escala**. No caso que abriu a
 * fase (143-CONTEXT, D-02), juntar quatro grupos de um mesmo cliente derruba
 * a mensalidade de R$ 33.500 para R$ 21.000 — ou para R$ 12.000, a depender
 * de qual tabela governa. São R$ 150 mil a R$ 258 mil por ano, num cliente
 * só. A restrição permanente do CONTEXT é explícita: *"nenhuma mudança entra
 * sem um comparativo antes×depois por grupo, com gente conferindo antes de
 * valer"*. Este serviço é esse comparativo, no momento da decisão.
 *
 * ## ⚠️ Ele é PURO — não grava NADA
 * Nem snapshot, nem `parent_id`, nem log de cobrança, nem a flag da Fase
 * 141. A simulação da árvore é feita **em memória**: os models de
 * `CompanyGroup` recebem o `parent_id` hipotético como atributo e a relação
 * `pai` é injetada com `setRelation()` — e **nenhum `save()` é chamado em
 * lugar nenhum deste arquivo**. Quem grava de verdade é o controller do T3,
 * depois que um humano leu este resultado.
 *
 * ## ⛔ A matemática da faixa NÃO é reimplementada aqui
 * `FechamentoFaixaResolver::paraGrupo()` e `::classificar()` são usados como
 * estão, e a precedência de cobrança é a mesma de
 * `ConsolidarMesFechamento` (Passo 5) e de
 * `CompararMensalidadeFechamento::linhaDeGrupo()`. Uma segunda
 * implementação da régua é a forma mais rápida de a prévia mostrar um número
 * e a cobrança sair outro — e o erro só apareceria na fatura do cliente.
 *
 * ## A prévia diz DE ONDE VEM a tabela
 * Cada linha carrega `tabela_origem`, `procedencia`
 * (`manual`/`contrato`/`presumida_servico`), `tabela_grupo_nome` e
 * `tabela_herdada_de_nome`, todos vindos prontos do shape do resolver. No
 * caso real, a diferença entre R$ 21.000 e R$ 12.000 é exatamente *qual
 * tabela governa*: esconder isso transformaria uma decisão de R$ 9.000/mês
 * num número sem contexto.
 */
class SimuladorGrupoCobrancaService
{
    public function __construct(
        private FechamentoRollupService $rollupService,
        private FechamentoFaixaResolver $faixaResolver,
        private FechamentoRegraTabela $regra,
    ) {
    }

    /**
     * Simula pendurar `$grupoIds` em `$paiId` (ou despendurá-los, quando
     * `$paiId` é `null`) na competência `$mes` (`YYYY-MM`).
     *
     * @param  array<int, int|string>  $grupoIds  grupos a pendurar/despendurar
     * @param  int|null  $paiId  grupo-pai hipotético; `null` = despendurar
     * @param  string  $mes  competência `YYYY-MM`
     * @return array{
     *     mes: string,
     *     pai_id: int|null,
     *     pai_nome: string|null,
     *     grupo_ids: array<int, int>,
     *     antes: array{linhas: array<int, array>, total_cobranca: float},
     *     depois: array{linhas: array<int, array>, total_cobranca: float},
     *     delta: float
     * }
     *
     * @throws \InvalidArgumentException com a mensagem em pt-BR do próprio
     *         model quando o arranjo pedido violaria a trava de um nível ou
     *         criaria ciclo — a MESMA mensagem que a gravação recusaria, para
     *         a tela nunca oferecer uma prévia de algo que não pode ser salvo.
     */
    public function simular(array $grupoIds, ?int $paiId, string $mes): array
    {
        $grupoIds = collect($grupoIds)->map(fn ($id) => (int) $id)->unique()->values();

        // Catálogo inteiro de grupos: são 15 em produção, uma query só. Ter
        // todos em memória é o que permite montar as duas árvores (antes e
        // depois) sem nenhuma consulta dentro dos laços.
        $todosOsGrupos = CompanyGroup::query()->get()->keyBy('id');

        foreach ($grupoIds as $id) {
            if (! $todosOsGrupos->has($id)) {
                throw new \InvalidArgumentException("Grupo {$id} não existe.");
            }
        }

        if ($paiId !== null && ! $todosOsGrupos->has($paiId)) {
            throw new \InvalidArgumentException("Grupo-pai {$paiId} não existe.");
        }

        // ⚠️ Reusa a trava do model (143-01) — nunca uma segunda cópia da
        // regra. A prévia de um arranjo impossível seria pior que nenhuma
        // prévia: mostraria um número que a gravação vai recusar.
        if ($paiId !== null) {
            foreach ($grupoIds as $id) {
                $todosOsGrupos->get($id)->validarPaiOuFalhar($paiId);
            }
        }

        // ── As duas árvores, em memória ────────────────────────────────────
        // `parent_id` ANTES = o que está no banco; DEPOIS = o hipotético.
        $paisAntes  = $todosOsGrupos->mapWithKeys(fn (CompanyGroup $g) => [$g->id => $g->parent_id]);
        $paisDepois = $paisAntes->map(fn ($pai, $id) => $grupoIds->contains($id) ? $paiId : $pai);

        $raizAntes  = $this->mapaDeRaizes($paisAntes);
        $raizDepois = $this->mapaDeRaizes($paisDepois);

        // Escopo: todo grupo cuja raiz (antes OU depois) esteja entre as
        // raízes tocadas por esta mudança. Grupos de outros clientes ficam
        // de fora — a prévia responde sobre o que muda, não sobre o mundo.
        $raizesTocadas = $grupoIds
            ->merge($paiId !== null ? [$paiId] : [])
            ->flatMap(fn (int $id) => [$raizAntes[$id] ?? $id, $raizDepois[$id] ?? $id])
            ->unique();

        $gruposDoEscopo = $todosOsGrupos
            ->filter(fn (CompanyGroup $g) => $raizesTocadas->contains($raizAntes[$g->id] ?? $g->id)
                || $raizesTocadas->contains($raizDepois[$g->id] ?? $g->id))
            ->keys()
            ->map(fn ($id) => (int) $id);

        // ── As empresas do escopo, e o faturamento do mês ──────────────────
        $companies = Company::where('active', true)
            ->whereIn('company_group_id', $gruposDoEscopo->all())
            ->with(['contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico')])
            ->get();

        $regraNova = $this->regra->ativa();

        // Mesma fonte de faturamento da tela de fechamento
        // (`AdminController::fechamento()`) — nunca uma soma própria.
        $rollup = $this->rollupService->porEmpresa($mes, $companies, somenteContratadas: $regraNova);

        $antes  = $this->calcularLado($companies, $todosOsGrupos, $paisAntes, $raizAntes, $rollup, $regraNova);
        $depois = $this->calcularLado($companies, $todosOsGrupos, $paisDepois, $raizDepois, $rollup, $regraNova);

        return [
            'mes'       => $mes,
            'pai_id'    => $paiId,
            'pai_nome'  => $paiId !== null ? $todosOsGrupos->get($paiId)->name : null,
            'grupo_ids' => $grupoIds->all(),
            'antes'     => $antes,
            'depois'    => $depois,
            'delta'     => round($depois['total_cobranca'] - $antes['total_cobranca'], 2),
        ];
    }

    /**
     * O retrato de HOJE, para TODOS os grupos — as mesmas linhas de cobrança
     * que a competência `$mes` produziria se fosse congelada agora, sem
     * nenhuma mudança de hierarquia (Fase 143, plano 04, T1).
     *
     * ## Por que ele existe, e por que não é uma conta nova
     * A tela de montagem (`Admin/GruposCobranca.jsx`) precisa mostrar, antes
     * de qualquer seleção, **quantas empresas e quanto de cobrança** cada
     * grupo representa — é o que dá noção de escala a quem vai juntar
     * grupos. Esse número tem de ser o MESMO que a prévia mostra depois e o
     * MESMO que o fechamento congela. Por isso aqui não há nem soma própria
     * nem régua própria: é `calcularLado()`, o mesmo método que serve os
     * dois lados de `simular()`, chamado com a árvore REAL (o `parent_id`
     * que está no banco). Uma segunda conta na listagem seria o jeito mais
     * fácil de a tela dizer R$ 33.500 e a fatura sair outra coisa.
     *
     * ⚠️ PURO como o resto da classe — não grava nada.
     *
     * @return array{linhas: array<int, array>, total_cobranca: float}
     */
    public function estadoAtual(string $mes): array
    {
        $todosOsGrupos = CompanyGroup::query()->get()->keyBy('id');

        $pais   = $todosOsGrupos->mapWithKeys(fn (CompanyGroup $g) => [$g->id => $g->parent_id]);
        $raizes = $this->mapaDeRaizes($pais);

        // Todas as empresas que estão em algum grupo — mesmo recorte de
        // `simular()` (ativas), para as duas telas contarem igual.
        $companies = Company::where('active', true)
            ->whereNotNull('company_group_id')
            ->with(['contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico')])
            ->get();

        $regraNova = $this->regra->ativa();

        $rollup = $this->rollupService->porEmpresa($mes, $companies, somenteContratadas: $regraNova);

        return $this->calcularLado($companies, $todosOsGrupos, $pais, $raizes, $rollup, $regraNova);
    }

    /**
     * Mapa `id do grupo => id da raiz` a partir de um mapa de `parent_id`.
     * Um nível só (D-05 do CONTEXT), então a raiz é o pai ou o próprio id —
     * a mesma conta de `CompanyGroup::raizId()`, sem caminhada.
     *
     * @param  Collection<int, int|null>  $pais
     * @return Collection<int, int>
     */
    private function mapaDeRaizes(Collection $pais): Collection
    {
        return $pais->mapWithKeys(fn (?int $pai, $id) => [(int) $id => (int) ($pai ?? $id)]);
    }

    /**
     * Calcula um lado inteiro (uma linha de cobrança por raiz) sob a árvore
     * descrita por `$pais`/`$raizes`.
     *
     * @param  Collection<int, Company>  $companies
     * @param  Collection<int, CompanyGroup>  $todosOsGrupos
     * @param  Collection<int, int|null>  $pais
     * @param  Collection<int, int>  $raizes
     * @param  array<int, array>  $rollup
     * @return array{linhas: array<int, array>, total_cobranca: float}
     */
    private function calcularLado(
        Collection $companies,
        Collection $todosOsGrupos,
        Collection $pais,
        Collection $raizes,
        array $rollup,
        bool $regraNova,
    ): array {
        // ⚠️ Clones dos models com o `parent_id` HIPOTÉTICO — é o que faz
        // `FechamentoFaixaResolver` e `CompanyGroup::raiz()` enxergarem a
        // árvore simulada sem nenhuma escrita no banco. São clones de
        // propósito: mutar os originais deixaria models sujos circulando
        // pela requisição, e um `save()` acidental em qualquer outro ponto
        // gravaria a hierarquia que ninguém aprovou.
        $gruposSimulados = $todosOsGrupos->mapWithKeys(function (CompanyGroup $g) use ($pais) {
            $clone = clone $g;
            $clone->parent_id = $pais->get($g->id);

            return [$g->id => $clone];
        });

        foreach ($gruposSimulados as $grupo) {
            $grupo->setRelation('pai', $grupo->parent_id !== null ? $gruposSimulados->get($grupo->parent_id) : null);
        }

        // Empresas com a relação `grupo` trocada pelo clone simulado. Também
        // clonadas: a coleção `$companies` é compartilhada pelos dois lados.
        $companiesSimuladas = $companies->map(function (Company $c) use ($gruposSimulados) {
            $clone = clone $c;
            $clone->setRelation('grupo', $gruposSimulados->get($c->company_group_id));

            return $clone;
        });

        $linhas = [];

        $porRaiz = $companiesSimuladas->groupBy(
            fn (Company $c) => $raizes->get($c->company_group_id, $c->company_group_id)
        );

        foreach ($porRaiz as $raizId => $membros) {
            $linhas[] = $this->linhaDeGrupo(
                (int) $raizId,
                $membros,
                $gruposSimulados,
                $rollup,
                $regraNova
            );
        }

        // Ordem estável: a maior cobrança primeiro, empate pelo id da raiz —
        // quem lê a prévia procura o número que mais muda.
        usort($linhas, function (array $a, array $b) {
            $valorA = $a['cobranca_mensal'] ?? -INF;
            $valorB = $b['cobranca_mensal'] ?? -INF;

            return $valorA === $valorB
                ? $a['company_group_id'] <=> $b['company_group_id']
                : $valorB <=> $valorA;
        });

        $total = 0.0;
        foreach ($linhas as $linha) {
            $total += $linha['cobranca_mensal'] ?? 0.0;
        }

        return ['linhas' => $linhas, 'total_cobranca' => round($total, 2)];
    }

    /**
     * Monta a linha de cobrança de UMA raiz — espelho deliberado do Passo 5
     * de `ConsolidarMesFechamento` e de
     * `CompararMensalidadeFechamento::linhaDeGrupo()`: âncora é a
     * empresa-membro de maior faturamento (empate pelo menor id), e é a
     * tabela resolvida para ela que classifica a SOMA.
     *
     * @param  Collection<int, Company>  $membros
     * @param  Collection<int, CompanyGroup>  $gruposSimulados
     * @param  array<int, array>  $rollup
     */
    private function linhaDeGrupo(
        int $raizId,
        Collection $membros,
        Collection $gruposSimulados,
        array $rollup,
        bool $regraNova,
    ): array {
        $faturamentoDe = fn (Company $c): ?float => $rollup[$c->id]['faturamento_total'] ?? null;

        $faturamentoTotal = null;
        foreach ($membros as $membro) {
            $fat = $faturamentoDe($membro);

            if ($fat !== null) {
                $faturamentoTotal = ($faturamentoTotal ?? 0.0) + (float) $fat;
            }
        }

        $ancora = $membros->sort(function (Company $a, Company $b) use ($faturamentoDe) {
            $fatA = (float) ($faturamentoDe($a) ?? -INF);
            $fatB = (float) ($faturamentoDe($b) ?? -INF);

            return $fatA === $fatB ? $a->id <=> $b->id : $fatB <=> $fatA;
        })->first();

        // ⛔ A régua vem do resolver, como está. `paraGrupo()` recebe o grupo
        // DIRETO da âncora (que pode ser um subgrupo) porque ele resolve a
        // árvore por dentro — raiz primeiro, subgrupo depois. Passar a raiz
        // aqui perderia o 2º degrau quando só o subgrupo tem tabela.
        $faixa = $ancora?->grupo !== null
            ? $this->faixaResolver->paraGrupo($ancora->grupo, $ancora)
            : ($ancora !== null ? $this->faixaResolver->paraEmpresa($ancora) : null);

        $classificacao = ($faixa !== null && $faturamentoTotal !== null)
            ? $this->faixaResolver->classificar($faturamentoTotal, $faixa['faixas'])
            : null;

        $todosContratos = $membros->flatMap(fn (Company $c) => $c->contratosServico);
        $temContratoMensal = $todosContratos->contains(
            fn ($ct) => $ct->ativo === true && $ct->servico !== null && $ct->servico->tipo_cobranca === Servico::TIPO_MENSAL
        );

        // Mesma precedência de cobrança do comando de consolidação — é o que
        // faz a prévia bater com o que a competência vai congelar.
        $cobranca = $regraNova
            ? CobrancaCalculator::mensalidade($classificacao, $todosContratos)
            : (($classificacao !== null || $temContratoMensal)
                ? (CobrancaCalculator::novo($classificacao, $todosContratos) ?: null)
                : null);

        $raiz = $gruposSimulados->get($raizId);

        // Composição da linha: quais grupos do cadastro estão dentro desta
        // raiz. É o que deixa visível que "MPozenato" passou a conter também
        // DRossi, Gran Belo e Lyam.
        $subgruposDaLinha = $membros
            ->pluck('company_group_id')
            ->unique()
            ->sort()
            ->map(fn ($id) => [
                'id'          => (int) $id,
                'nome'        => $gruposSimulados->get((int) $id)?->name,
                'eh_a_raiz'   => (int) $id === $raizId,
                'empresas'    => $membros->where('company_group_id', $id)->count(),
            ])
            ->values()
            ->all();

        return [
            'company_group_id'  => $raizId,
            'grupo_nome'        => $raiz?->name,
            'empresa_ancora_id' => $ancora?->id,
            'empresas_count'    => $membros->count(),
            'subgrupos'         => $subgruposDaLinha,
            'faturamento_total' => $faturamentoTotal,
            'faixa_ordem'       => $classificacao['ordem'] ?? null,
            'faixa_label'       => $classificacao['label'] ?? null,
            'valor_faixa'       => $classificacao['valor'] ?? null,
            'cobranca_mensal'   => $cobranca,
            // ⚠️ De onde vem a tabela — a informação que separa uma decisão
            // consciente de R$ 9.000/mês de um número solto na tela.
            'tabela_origem'          => $faixa['origem'] ?? null,
            'procedencia'            => $faixa['procedencia'] ?? null,
            'tabela_grupo_nome'      => ($faixa['origem'] ?? null) === 'grupo' ? ($faixa['grupo_nome'] ?? null) : null,
            'tabela_servico_nome'    => $faixa['servico_nome'] ?? null,
            'tabela_herdada_de_nome' => ($faixa !== null && $faixa['origem'] !== 'grupo')
                ? ($faixa['herdada_de_company_name'] ?? null)
                : null,
        ];
    }
}
