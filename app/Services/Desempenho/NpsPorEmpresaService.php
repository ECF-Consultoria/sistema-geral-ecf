<?php

namespace App\Services\Desempenho;

use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\Nps\NpsElegibilidadeService;
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsJanelaResolver;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Agregador de LEITURA que devolve a nota de NPS de um profissional POR
 * `company_id` — Fase 118 (NPSE-01..06), insumo direto da Fase 119.
 *
 * Consome as 3 fontes de verdade existentes (`NpsScoreAssignment`, o cruzamento
 * legado read-time e `NpsImputationService`) e NÃO reimplementa nenhuma delas —
 * apenas reagrupa por empresa, com a mesma janela M+1 e as mesmas invalidações
 * que `DesempenhoScoreService::computeNpsMedio()`/`computeNpsWindow()` já
 * praticam hoje. Não escreve nada; não materializa linha em
 * `nps_imputed_assignments`.
 *
 * ─── Regras travadas (118-CONTEXT.md) ────────────────────────────────────
 *  - D-01 · a nota da empresa usa a dimensão do PAPEL do profissional
 *    (`consultor`/`estrategista`, valor da pivot `company_users`) — NUNCA a
 *    dimensão `empresa`. Estruturalmente garantido: os 3 ramos só produzem
 *    linhas com `role` não-nulo (a dimensão `empresa` nasce sem `user_id`).
 *  - D-02 · profissional que acumula papéis (estrategista E analista) na
 *    MESMA empresa entra com a MÉDIA dos papéis — a empresa pesa 1× na
 *    carteira dele, nunca 2×. A média roda DEPOIS das dedupes internas dos
 *    3 ramos.
 *  - D-03 · empresa com mais de um survey na competência usa o survey do
 *    SERVIÇO DO VÍNCULO. Fallback OBRIGATÓRIO: quando o vínculo
 *    `(company_id, role)` é consolidado (`servico_id NULL`) ou inexistente
 *    (empresa só via fonte B — atribuição congelada fora da carteira viva),
 *    NENHUM filtro de serviço se aplica — usa a média de TODAS as notas do
 *    papel na competência.
 *  - D-04 · empresa da carteira viva sem NENHUM NPS na competência entra com
 *    nota 1 (inclusive sem disparo) QUANDO a janela M+1 já encerrou E a
 *    empresa é ELEGÍVEL a NPS naquela competência (Fase 119.1 · D1 — ver
 *    nota "Aditividade" abaixo); se a janela ainda está em coleta, a empresa
 *    é EXCLUÍDA (`nota = null`), com o MESMO tratamento para elegível ou não
 *    (a checagem de janela vem ANTES da de elegibilidade). Empresa NÃO
 *    elegível com janela fechada também é EXCLUÍDA (`nota = null`,
 *    `origem = 'nao_elegivel'`) — nunca "nota zero". É um fallback de
 *    LEITURA, nunca materializado em `nps_imputed_assignments`.
 *
 * ─── Aditividade ──────────────────────────────────────────────────────────
 * `DesempenhoScoreService` é ESPELHADO, não substituído — nenhum número de
 * produção muda nesta fase. `NpsJanelaResolver` (régua `gte` de leitura da
 * janela M+1) é consultado aqui, mas `computeNpsWindow()` NÃO foi refatorado
 * para chamá-lo (gate de hash da Fase 118 — ver `NpsJanelaResolver`).
 *
 * ⚠ ATUALIZAÇÃO (Fase 119.1 · D1, 2026-07-29): a frase acima ("nenhum número
 * de produção muda nesta fase") descrevia a Fase 118. A Fase 119.1 muda esse
 * contrato DE PROPÓSITO — o D-04 passou a filtrar por elegibilidade
 * (`NpsElegibilidadeService`) antes de atribuir `nota = 1.0`. Motivo: depois
 * que o bônus (`DesempenhoScoreService::computeNpsMedio()` ramo (D), via
 * `NpsSemLinkService`) passou a exigir elegibilidade para a mesma regra
 * "sem link conta 1", uma empresa NÃO elegível continuaria valendo 1 aqui
 * (relatório por empresa) e nada no bônus — divergência silenciosa entre as
 * duas telas. Esta é a ÚNICA mudança de número de produção introduzida por
 * este arquivo desde a Fase 118: empresa não elegível deixa de valer 1 no
 * relatório por empresa. O ramo `mes_em_curso` (passo 3, abaixo) segue SEM
 * filtro de elegibilidade, de propósito — não alimenta bônus fechado.
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-CONTEXT.md D1
 * @see app/Services/DesempenhoScoreService.php:748-1063 (os 3 ramos + janela — reusados, não reescritos)
 * @see app/Services/Nps/NpsImputationService.php (ramo 3 — delegado, nunca reimplementado)
 * @see app/Services/Nps/NpsJanelaResolver.php (régua de leitura da janela M+1)
 * @see app/Services/Nps/NpsElegibilidadeService.php (fonte única de elegibilidade — MESMA do ramo D do bônus)
 */
class NpsPorEmpresaService
{
    public function __construct(
        private CarteiraContextService $carteiraContext,
        private NpsImputationService $imputationService,
        private NpsScoreCalculator $npsCalculator,
        private NpsJanelaResolver $janela,
        private NpsElegibilidadeService $elegibilidade,
    ) {
    }

    /**
     * Nota de NPS por empresa do profissional na competência `$mes`
     * (competência FINANCEIRA M — o deslocamento para o mês de coleta do NPS,
     * M+1, acontece DENTRO deste método, espelhando
     * `DesempenhoScoreService::computeNpsWindow()`).
     *
     * `$invalidadas` é chaveado pela competência M, SEM deslocar — mesma
     * regra de `computeNpsWindow:757-759`.
     *
     * Valores possíveis de `origem`:
     *  - `mes_em_curso` — `$mesFechado=false`; nenhum ramo é consultado.
     *  - `atribuicao` / `legado` / `imputada` — a nota veio de um único ramo.
     *  - `misto` — a nota da empresa combina papéis alimentados por ramos
     *    diferentes.
     *  - `janela_aberta` — sem nota, M+1 ainda em coleta ⇒ `nota = null`
     *    (empresa EXCLUÍDA do denominador — NUNCA "nota zero").
     *  - `sem_nps` — sem nota, M+1 encerrada, empresa ELEGÍVEL ⇒ `nota = 1.0` (D-04).
     *  - `nao_elegivel` — sem nota, M+1 encerrada, mas a empresa NÃO era
     *    elegível a NPS na competência (sem contrato ativo em serviço
     *    coberto, sem modelo aplicável, sem estrategista, ou invalidada na
     *    competência) ⇒ `nota = null`, EXCLUÍDA do denominador (Fase 119.1 ·
     *    D1/NPSMAN-07) — mesmo tratamento de `janela_aberta`, nunca "nota zero".
     *
     * Semântica travada dos contadores (o Plano 118-02 depende disto):
     *  - `notas_brutas` guarda TODAS as notas coletadas pelos 3 ramos ANTES
     *    do filtro da D-03 — é o que a reconciliação da NPSE-02 compara com
     *    os ramos originais de `DesempenhoScoreService`.
     *  - `por_ramo` conta essas MESMAS notas brutas, por ramo.
     *  - `total_notas` conta só as notas CONSIDERADAS (pós-filtro D-03), que
     *    são as que formaram `nota`. Nos cenários sem filtro de serviço (esta
     *    fase) os dois coincidem; no multi-serviço (Plano 118-02) divergem
     *    de propósito.
     *
     * @return Collection<int, object{
     *   company_id: int,
     *   nota: ?float,
     *   origem: string,
     *   total_notas: int,
     *   por_ramo: array{atribuicao: int, legado: int, imputada: int},
     *   por_papel: array<string, float>,
     *   papeis: array<int, string>,
     *   servico_ids: array<int, int>,
     *   consolidado: bool,
     *   notas_brutas: array<int, float>,
     *   houve_survey: bool,
     * }> chaveada por `company_id`.
     */
    public function notasNpsPorEmpresa(User $user, Carbon $mes, bool $mesFechado, ?Collection $invalidadas = null): Collection
    {
        // 1. Empresas invalidadas na competência M (mesmo set repassado aos
        //    3 ramos, sem deslocar — regra de computeNpsWindow:757-759).
        $invalidadas = $invalidadas ?? collect();

        // 2. Universo vivo (fonte A, D-04) — SEMPRE via forUser(), nunca join
        //    direto em company_users.servico_id (contrato de uso do
        //    CarteiraContextService). Rejeita imediatamente empresa invalidada.
        $vinculos = $this->carteiraContext->forUser($user, ['active' => true])
            ->reject(fn (array $v) => $invalidadas->contains($v['company_id']));

        // forUser() NÃO colapsa vínculos (Pitfall 1) — deduplicar aqui.
        $companiesUniverso = $vinculos->pluck('company_id')->unique()->values();

        // 3. Caso 1 da janela (NPSE-03): mês em curso — piso 1.0 para TODA a
        //    carteira, SEM consultar nenhum ramo (espelha computeNpsWindow:750-755).
        //    Fase 119.1 (D1) — este ramo segue SEM filtro de elegibilidade,
        //    DE PROPÓSITO: mês em curso nunca alimenta bônus fechado, então
        //    a divergência que a Task 3 fecha (D-04, abaixo) não se aplica
        //    aqui. Divergência conhecida e aceita (ver docblock de classe).
        if (! $mesFechado) {
            return $companiesUniverso->mapWithKeys(fn (int $companyId) => [
                $companyId => $this->linhaSemNota($companyId, 1.0, 'mes_em_curso', false),
            ]);
        }

        // 4. Desloca para o mês de COLETA do NPS (M+1) — régua de leitura.
        $mesNps = $this->janela->mesDeColeta($mes);
        $inicio = $mesNps->copy()->startOfMonth();
        $fim    = $mesNps->copy()->endOfMonth();

        // 5. Os 3 ramos, cada um numa query própria (nunca reimplementando a
        //    regra dos originais — só adaptando o shape pra incluir company_id).
        $todasAsNotas = collect()
            ->merge($this->notasAtribuicaoPorEmpresa($user, $inicio, $fim, $invalidadas))
            ->merge($this->notasLegadoPorEmpresa($user, $inicio, $fim, $invalidadas))
            ->merge($this->notasImputadasPorEmpresa($user, $inicio, $fim, $invalidadas));

        // notas_brutas / por_ramo — sempre as notas CRUAS, antes do filtro
        // da D-03 (contrato de auditoria da NPSE-02, D-05).
        $brutoPorEmpresa = [];
        foreach ($todasAsNotas as $n) {
            $brutoPorEmpresa[$n->company_id]['notas_brutas'][]        = $n->nota;
            $brutoPorEmpresa[$n->company_id]['por_ramo'][$n->ramo] = ($brutoPorEmpresa[$n->company_id]['por_ramo'][$n->ramo] ?? 0) + 1;
        }

        // 6. Merge → agrupa por (company_id, role).
        $porParGrupo = $todasAsNotas->groupBy(fn ($n) => $n->company_id . '|' . $n->role);

        $porPapelPorEmpresa   = []; // company_id => [role => nota]
        $totalNotasPorEmpresa = []; // company_id => int
        $ramosUsadosPorEmpresa = []; // company_id => [ramo => true]

        foreach ($porParGrupo as $grupo) {
            $companyId = (int) $grupo->first()->company_id;
            $role      = (string) $grupo->first()->role;

            // 7. D-03 — resolve o survey do serviço do vínculo (com fallback
            //    consolidado obrigatório) para o par (company_id, role).
            $servicosVivos = $this->servicosVivosDoPar($vinculos, $companyId, $role);

            if ($servicosVivos === null) {
                // Sem vínculo OU vínculo consolidado — nenhum filtro se aplica.
                $considerado = $grupo;
            } else {
                $filtrado = $grupo->filter(function ($n) use ($servicosVivos) {
                    // servico_ids === null (decisão 5) — nota sem serviço
                    // conhecido (ex.: ramo legado) NUNCA é descartada.
                    if ($n->servico_ids === null) {
                        return true;
                    }

                    return count(array_intersect($n->servico_ids, $servicosVivos)) > 0;
                });

                // Filtro nunca pode zerar o papel inteiro — cai pra média de
                // todas as notas do grupo (nunca perder o papel por completo).
                $considerado = $filtrado->isEmpty() ? $grupo : $filtrado;
            }

            $porPapelPorEmpresa[$companyId][$role] = round((float) $considerado->avg('nota'), 2);
            $totalNotasPorEmpresa[$companyId]       = ($totalNotasPorEmpresa[$companyId] ?? 0) + $considerado->count();

            foreach ($considerado->pluck('ramo')->unique() as $ramo) {
                $ramosUsadosPorEmpresa[$companyId][$ramo] = true;
            }
        }

        // 8. D-02 — média dos papéis por empresa (empresa pesa 1×).
        $resultado = [];
        foreach ($porPapelPorEmpresa as $companyId => $porPapel) {
            $nota  = round((float) collect($porPapel)->avg(), 2);
            $ramos = array_keys($ramosUsadosPorEmpresa[$companyId] ?? []);

            $origem = count($ramos) === 1 ? $ramos[0] : 'misto';

            // servico_ids/consolidado no nível da EMPRESA (não do papel):
            // duas situações levam ao mesmo fallback "sem serviço conhecido"
            // (Plano 118-02, D-03): (a) empresa que veio só pela fonte B
            // (nenhum vínculo vivo, decisão 3 do Plano 118-01), OU (b) o
            // vínculo vivo é o slot CONSOLIDADO (`company_users.servico_id
            // NULL`) — o caminho OBRIGATÓRIO da D-03, que não pode ficar
            // marcado como "vínculo específico" só porque a linha existe.
            $vinculosDaEmpresa    = $vinculos->where('company_id', $companyId);
            $temVinculoConsolidado = $vinculosDaEmpresa->contains(fn (array $v) => $v['servico_id'] === null);
            $consolidado          = $vinculosDaEmpresa->isEmpty() || $temVinculoConsolidado;
            $servicoIds           = $consolidado
                ? []
                : $vinculosDaEmpresa->pluck('servico_id')->filter()->unique()->values()->all();

            $resultado[$companyId] = (object) [
                'company_id'   => $companyId,
                'nota'         => $nota,
                'origem'       => $origem,
                'total_notas'  => $totalNotasPorEmpresa[$companyId],
                'por_ramo'     => array_merge(
                    ['atribuicao' => 0, 'legado' => 0, 'imputada' => 0],
                    $brutoPorEmpresa[$companyId]['por_ramo'] ?? []
                ),
                'por_papel'    => $porPapel,
                'papeis'       => array_keys($porPapel),
                'servico_ids'  => $servicoIds,
                'consolidado'  => $consolidado,
                'notas_brutas' => $brutoPorEmpresa[$companyId]['notas_brutas'] ?? [],
                'houve_survey' => true,
            ];
        }

        // 9. D-04 — só agora, para empresas do universo (passo 2) que não
        //    apareceram no passo 8. Ordem fixa (Pitfall 3): invalidadas já
        //    saíram no passo 2, D-03/D-02 já rodaram — D-04 é o ÚLTIMO passo.
        $semNota = $companiesUniverso->reject(fn ($id) => isset($resultado[$id]));

        if ($semNota->isNotEmpty()) {
            $houveSurveyMap = $this->houveSurveyPorEmpresa($semNota, $inicio, $fim);
            $janelaFechada  = $this->janela->fechada($mesNps);

            // Fase 119.1 (D1) — elegibilidade calculada UMA ÚNICA VEZ, FORA
            // do foreach abaixo (é query em companies/contratos — nunca
            // repetir por empresa dentro do laço). Usa SEMPRE `$mesNps` (mês
            // de COLETA, já calculado no passo 4) — NUNCA `$mes` (financeiro):
            // é a convenção de argumento travada desta fase, a MESMA que
            // `NpsSemLinkService` usa no ramo (D) do bônus. Um descasamento
            // de mês aqui faria este serviço e o bônus discordarem sobre quem
            // é elegível.
            $elegiveis = $this->elegibilidade->empresasElegiveis($mesNps)
                ->pluck('company_id')->unique()->flip();

            foreach ($semNota as $companyId) {
                $houveSurvey = $houveSurveyMap[$companyId] ?? false;

                // Ordem fixa (Fase 119.1 · D1): janela ABERTA vence sobre
                // elegibilidade (inalterado) — senão os testes de janela da
                // Fase 118 mudariam de veredito sem necessidade. Só depois de
                // saber que a janela FECHOU é que a elegibilidade decide entre
                // `sem_nps` (1.0) e `nao_elegivel` (null, NPSMAN-07).
                $resultado[$companyId] = match (true) {
                    ! $janelaFechada             => $this->linhaSemNota($companyId, null, 'janela_aberta', false),
                    ! $elegiveis->has($companyId) => $this->linhaSemNota($companyId, null, 'nao_elegivel', $houveSurvey),
                    default                       => $this->linhaSemNota($companyId, 1.0, 'sem_nps', $houveSurvey),
                };

                // T-118-03 (Plano 118-02) — gap de atribuição do responsável
                // consolidado (memória `project_nps_assignment_consolidado_gap`,
                // corrigido NA ORIGEM em 2026-07-22, com backfill de
                // competências ANTERIORES a essa data ainda pendente): houve
                // disparo/resposta na janela, mas nenhuma das 3 fontes
                // produziu nota para ESTE usuário. A nota devolvida CONTINUA
                // 1.0 — a D-04 é decisão do usuário e não admite exceção
                // silenciosa — o que muda é que o sintoma passa a aparecer no
                // log em vez de se esconder atrás de um número que parece
                // normal. Nível warning (não error): é dado suspeito, não
                // falha de execução. Só o contexto interno (IDs/competência)
                // é logado — nunca nome de empresa/cliente, e-mail, telefone
                // ou texto de resposta de NPS (T-118-09).
                //
                // Fase 119.1 (D1) — este log é DELIBERADAMENTE independente
                // da elegibilidade: continua disparando mesmo quando a
                // empresa é `nao_elegivel`, porque é sobre outro problema (o
                // gap de atribuição do responsável), não sobre a regra nova.
                if ($janelaFechada && $houveSurvey) {
                    Log::warning('[NPS por Empresa] empresa com survey na janela mas sem nota para o usuário — possível gap de atribuição', [
                        'user_id'     => $user->id,
                        'company_id'  => $companyId,
                        'competencia' => $mes->format('Y-m'),
                        'mes_coleta'  => $mesNps->format('Y-m'),
                    ]);
                }
            }
        }

        // 10.
        return collect($resultado)->keyBy('company_id');
    }

    /**
     * (A) Réplica de `DesempenhoScoreService::notasPorAtribuicao():883`, com
     * os MESMOS joins/filtros, mas SEM `selectRaw`/`MAX(servico_id)` (C-04):
     * `MAX(servico_id)` escolheria um serviço arbitrário e descartaria em
     * silêncio o dado que a D-03 precisa. A dedupe migra para PHP
     * (`Collection::groupBy()`, mesmo idioma de `PerformanceController.php:552-559`),
     * preservando TODOS os `servico_id` do grupo como lista. `MAX(average_score)`
     * continua sendo o critério — as N linhas de um mesmo (resposta, papel)
     * vêm do MESMO `nps_response_score` e têm score idêntico.
     *
     * A dedupe ORIGINAL em `notasPorAtribuicao()` fica INTOCADA — esta é uma
     * query PRÓPRIA do serviço novo (NPSE-02, fase aditiva).
     *
     * @return Collection<int, object{company_id: int, role: string, servico_ids: ?array<int,int>, nota: float, ramo: string}>
     */
    private function notasAtribuicaoPorEmpresa(User $user, Carbon $inicio, Carbon $fim, Collection $invalidadas): Collection
    {
        $linhasCruas = NpsScoreAssignment::query()
            ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
            ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
            ->where('nps_score_assignments.user_id', $user->id)
            ->where('s.status', 'completed')
            ->whereBetween('s.completed_at', [$inicio, $fim])
            ->whereNull('r.invalidated_at')
            ->when($invalidadas->isNotEmpty(), fn ($q) => $q->whereNotIn('s.company_id', $invalidadas->all()))
            ->select(
                'nps_score_assignments.nps_response_id',
                'nps_score_assignments.role',
                'nps_score_assignments.company_id',
                'nps_score_assignments.servico_id',
                'nps_score_assignments.average_score',
            )
            ->get();

        return $linhasCruas
            ->groupBy(fn ($row) => $row->nps_response_id . '|' . $row->role)
            ->map(function ($grupo) {
                $first     = $grupo->first();
                $naoNulos  = $grupo->pluck('servico_id')->filter(fn ($v) => $v !== null)->unique()->values();

                return (object) [
                    'company_id'  => (int) $first->company_id,
                    'role'        => (string) $first->role,
                    'servico_ids' => $naoNulos->isEmpty() ? null : $naoNulos->all(),
                    'nota'        => (float) $grupo->max('average_score'),
                    'ramo'        => 'atribuicao',
                ];
            })
            ->values();
    }

    /**
     * (B) Réplica FIEL de `DesempenhoScoreService::notasLegado():953`, linha a
     * linha — inclusive o `->principal()` (isolamento por serviço — NÃO
     * "limpar" este scope) e o skip `$cobertasNoPapel` derivado dos surveys
     * JÁ CARREGADOS (nunca de uma 2ª query com filtro de mês próprio).
     *
     * Decisão 4 do plano: este ramo usa `$user->companies()` (carteira
     * CONSOLIDADA legada), NÃO `forUser()` — é a MESMA fonte que o original
     * usa. Trocar para `forUser()` "corrigiria" um bug que não existe e
     * QUEBRARIA a reconciliação da NPSE-02 (o original usa esta MESMA query
     * e é contra ELE que reconciliamos).
     *
     * Única diferença: em vez de `$notas->push($nota)`, empurra o objeto com
     * `company_id`/`role`/`servico_ids=null` (ramo service-agnostic por
     * construção, decisão 5)/`ramo='legado'`.
     *
     * @return Collection<int, object{company_id: int, role: string, servico_ids: null, nota: float, ramo: string}>
     */
    private function notasLegadoPorEmpresa(User $user, Carbon $inicio, Carbon $fim, Collection $invalidadas): Collection
    {
        $dim = $user->dimensaoNpsDesempenho();

        $companyIds = $user->companies()
            ->where('active', true)
            ->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return collect();
        }

        $surveys = NpsSurvey::with(['response' => fn ($q) => $q->valida()])
            ->principal()
            ->whereIn('company_id', $companyIds)
            ->when($invalidadas->isNotEmpty(), fn ($q) => $q->whereNotIn('company_id', $invalidadas->all()))
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$inicio, $fim])
            ->get();

        $papelDaDimensao = $dim === 'estrategista' ? 'estrategista' : 'consultor';
        $responseIds     = $surveys->pluck('response.id')->filter()->values();

        $cobertasNoPapel = $responseIds->isEmpty()
            ? collect()
            : NpsScoreAssignment::whereIn('nps_response_id', $responseIds)
                ->where('role', $papelDaDimensao)
                ->pluck('nps_response_id')
                ->flip();

        $notas = collect();

        foreach ($surveys as $survey) {
            /** @var \App\Models\NpsResponse|null $response */
            $response = $survey->response;
            if ($response === null) {
                continue;
            }

            if ($cobertasNoPapel->has($response->id)) {
                continue;
            }

            $nota = $this->npsCalculator->compute($response, $dim);

            if ($nota === null) {
                $legacyField = $dim === 'estrategista' ? 'score_estrategista' : 'score_analista';
                $legacyScore = $response->{$legacyField} ?? null;
                if ($legacyScore !== null && $legacyScore > 0) {
                    $nota = (float) $legacyScore;
                }
            }

            if ($nota !== null) {
                $notas->push((object) [
                    'company_id'  => (int) $survey->company_id,
                    'role'        => $papelDaDimensao,
                    'servico_ids' => null,
                    'nota'        => (float) $nota,
                    'ramo'        => 'legado',
                ]);
            }
        }

        return $notas;
    }

    /**
     * (C) Delega 100% para `NpsImputationService::notasDoUsuario()` (a dedupe
     * `(survey_id, role)` e o `vigentes()` já vêm de lá) e só adapta o shape.
     * NUNCA consultar `nps_imputed_assignments` diretamente.
     *
     * @return Collection<int, object{company_id: int, role: string, servico_ids: ?array<int,int>, nota: float, ramo: string}>
     */
    private function notasImputadasPorEmpresa(User $user, Carbon $inicio, Carbon $fim, Collection $invalidadas): Collection
    {
        return $this->imputationService
            ->notasDoUsuario($user, $inicio, $fim, $invalidadas)
            ->map(fn ($linha) => (object) [
                'company_id'  => (int) $linha->company_id,
                'role'        => (string) $linha->role,
                'servico_ids' => $linha->servico_id === null ? null : [$linha->servico_id],
                'nota'        => (float) $linha->nota,
                'ramo'        => 'imputada',
            ]);
    }

    /**
     * D-03 — conjunto de `servico_id` dos vínculos VIVOS de um par
     * `(company_id, role)`. Retorna `null` quando NENHUM filtro deve ser
     * aplicado: (a) não há vínculo vivo para o par (decisão 3 — empresa só
     * via fonte B), ou (b) qualquer vínculo do par é consolidado
     * (`servico_id NULL`, decisão 6 — o consolidado é o superconjunto e vence).
     *
     * @return ?array<int,int>
     */
    private function servicosVivosDoPar(Collection $vinculos, int $companyId, string $role): ?array
    {
        $doPar = $vinculos->filter(fn (array $v) => $v['company_id'] === $companyId && $v['role'] === $role);

        if ($doPar->isEmpty()) {
            return null;
        }

        if ($doPar->contains(fn (array $v) => $v['servico_id'] === null)) {
            return null;
        }

        return $doPar->pluck('servico_id')->unique()->values()->all();
    }

    /**
     * `houve_survey` (T-118-03) das empresas SEM nota — UMA única query com
     * `whereIn`, fora de qualquer loop. Distingue "nunca disparou" (D-04
     * genuína) de "disparou, existe resposta, mas faltou a atribuição" (o
     * gap conhecido de produção — memória `project_nps_assignment_consolidado_gap`).
     *
     * @param  Collection<int,int>  $companyIds
     * @return array<int,bool>
     */
    private function houveSurveyPorEmpresa(Collection $companyIds, Carbon $inicio, Carbon $fim): array
    {
        $ids = $companyIds->values()->all();

        if (empty($ids)) {
            return [];
        }

        $comSurvey = NpsSurvey::query()
            ->whereIn('company_id', $ids)
            ->where(function ($q) use ($inicio, $fim) {
                $q->where(function ($qq) use ($inicio, $fim) {
                    $qq->where('status', 'completed')
                        ->whereBetween('completed_at', [$inicio, $fim]);
                })->orWhereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()]);
            })
            ->pluck('company_id')
            ->unique()
            ->all();

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = in_array($id, $comSurvey, true);
        }

        return $map;
    }

    /**
     * Monta a linha de retorno para empresa SEM nota nenhuma (casos
     * `mes_em_curso`, `janela_aberta`, `sem_nps`) — nenhum ramo alimentou
     * `por_papel`/`notas_brutas`.
     */
    private function linhaSemNota(int $companyId, ?float $nota, string $origem, bool $houveSurvey): object
    {
        return (object) [
            'company_id'   => $companyId,
            'nota'         => $nota,
            'origem'       => $origem,
            'total_notas'  => 0,
            'por_ramo'     => ['atribuicao' => 0, 'legado' => 0, 'imputada' => 0],
            'por_papel'    => [],
            'papeis'       => [],
            'servico_ids'  => [],
            'consolidado'  => false,
            'notas_brutas' => [],
            'houve_survey' => $houveSurvey,
        ];
    }
}
