<?php

namespace App\Console\Commands;

use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase 122, Plano 05 (SNAP-06) — conferência READ-ONLY de uma competência do
 * módulo Desempenho, por RECONSULTA direta às duas tabelas do fechamento.
 *
 * POR QUE ESTE COMANDO EXISTE (122-CONTEXT.md item 5): o gate FIXMARG-03 do
 * `desempenho:consolidar-mes` recusa persistir amostra degradada e reporta
 * apenas uma CONTAGEM no stdout ("Degradados: N") — os nomes só vão para
 * `Log::error`. Na sessão de 2026-08-03 o comando imprimiu
 * "OK: 11 · Falhas: 0 · Degradados: 0" e a conferência que valeu foi a
 * reconsulta manual ao banco. Este comando transforma essa disciplina em
 * ferramenta executável: NENHUMA linha do texto que ele mesmo imprime é
 * critério de verificação — o contrato real é a saída `--json` e o EXIT
 * CODE (D-122-12). `SUCCESS` só acontece com ZERO inconsistências.
 *
 * D-122-10 (READ-ONLY): este comando não grava nada — nenhuma escrita em
 * nenhuma tabela, nenhum cache aquecido, nenhuma chamada ao serviço que
 * monta o payload de nota (isso dispararia HTTP à Adman). Um verificador
 * que corrige o que encontra esconderia a inconsistência em vez de expô-la
 * — e a correção certa depende do motivo (rate-limit pede re-execução;
 * invalidação pede reconsolidação; divergência de contagem pede
 * investigação antes de pagar).
 *
 * D-122-11 (nomear, não contar): o `consolidar-mes` reporta "Degradados: N"
 * — N não diz QUEM, e um profissional sem row mensal zera silenciosamente a
 * cadeia de promoção DESEMP-08 (que lê o mensal de M-1). Este comando lista
 * `SEM_SNAPSHOT` com `user_id` e nome, nunca como uma contagem anônima.
 *
 * ── As 5 inconsistências detectadas e a ação operacional de cada uma ──────
 *
 *  SEM_SNAPSHOT — profissional elegível (mesmo filtro dos três comandos do
 *  fechamento: `active=true` + cargo analista/estrategista) sem row em
 *  `desempenho_score_snapshots` (modalidade mensal) na competência.
 *  Ação: consultar o `Log::error` do gate FIXMARG-03 correspondente (traz
 *  `cobertura`/`base_gate`/`cobertura_pp`/`cobertura_legado`) e re-rodar
 *  `php artisan desempenho:consolidar-mes --mes=` quando o rate-limit da
 *  Adman passar. Nunca inventar row placeholder.
 *
 *  SEM_LINHAS — a row mensal existe e o `breakdown_json` registra
 *  `empresas_score` não-vazio (o shadow rodou), mas
 *  `desempenho_company_score_snapshots` não tem NENHUMA linha daquele
 *  profissional na competência. Ação: reconsolidar
 *  (`desempenho:consolidar-mes --mes=`) — o writer não persistiu o que o
 *  cálculo já tinha pronto.
 *
 *  LINHAS_ORFAS — existem linhas em `desempenho_company_score_snapshots`
 *  para o profissional na competência, mas NENHUMA row mensal agregada.
 *  Ação: reconsolidar — o detalhe por empresa não tem resumo que o
 *  sustente (o Relatório de Bonificação lê o resumo).
 *
 *  DIVERGENCIA_EMPRESAS_SCORE — a contagem de linhas em
 *  `desempenho_company_score_snapshots` diverge de
 *  `count(breakdown_json['empresas_score'])` do resumo mensal. Ação:
 *  INVESTIGAR antes de pagar — pode ser reconsolidação parcial, invalidação
 *  que rodou entre as duas gravações, ou origem escrevendo sobre origem.
 *
 *  ORIGEM_NAO_CONGELADA — competência FECHADA (anterior ao mês corrente)
 *  com pelo menos uma linha por empresa cuja `origem` não é
 *  `consolidar_mes` (D-122-02: só `consolidar_mes` representa o fechamento
 *  oficial; `snapshot_diario`/`warm_cache` são leituras provisórias do mês
 *  em curso). No mês em curso, `snapshot_diario`/`warm_cache` são o
 *  esperado — este check só se aplica a competências fechadas. Ação:
 *  reconsolidar a competência.
 *
 * @see App\Console\Commands\ConsolidarMesDesempenho
 * @see App\Services\Desempenho\CompanyScoreSnapshotWriter
 * @see .planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-05-PLAN.md
 */
class VerificarConsolidacaoDesempenho extends Command
{
    protected $signature = 'desempenho:verificar-consolidacao
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}
        {--json : saída em JSON, parseável, sem nenhum outro texto}';

    protected $description = 'Confere uma competencia do modulo Desempenho por RECONSULTA (read-only) as duas tabelas do fechamento. Exit code e o veredito, nunca o texto impresso.';

    public function handle(): int
    {
        $mesOption = $this->option('mes');

        if ($mesOption) {
            try {
                // Mesma regra de `ConsolidarMesDesempenho`: NUNCA
                // `createFromFormat('Y-m', ...)` — sem o dia explícito, o
                // PHP preenche com o dia de hoje e estoura para o mês
                // seguinte quando o mês alvo é mais curto.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[VerificarConsolidacao] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        } else {
            $mes = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        }

        $mesStr    = $mes->toDateString();
        $mesLabel  = $mes->format('Y-m');
        $mesFechado = $mes->lt(Carbon::today()->startOfMonth());

        $relatorio = $this->montarRelatorio($mes, $mesStr, $mesLabel, $mesFechado);

        if ($this->option('json')) {
            $this->line(json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->imprimirRelatorioHumano($relatorio);
        }

        return $relatorio['resumo']['total_inconsistencias'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Monta o relatório inteiro por RECONSULTA — nenhuma escrita, nenhum
     * cache, nenhuma chamada ao serviço que monta o payload de nota.
     */
    private function montarRelatorio(Carbon $mes, string $mesStr, string $mesLabel, bool $mesFechado): array
    {
        // Users elegíveis — mesmo filtro dos três comandos do fechamento
        // (ConsolidarMesDesempenho::handle()).
        $usersElegiveis = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->get()
            ->keyBy('id');

        $idsElegiveis = $usersElegiveis->keys();

        $rowsMensais = DesempenhoScoreSnapshot::mensal()
            ->whereDate('mes_referencia', $mesStr)
            ->get()
            ->keyBy('user_id');

        $rowsPorEmpresa = DesempenhoCompanyScoreSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->get()
            ->groupBy('user_id');

        // Universo de user_id a examinar: elegíveis (para pegar quem
        // deveria ter row mensal e não tem) UNIÃO quem tem QUALQUER dado
        // gravado nesta competência (para pegar linhas órfãs de user que
        // não é mais elegível hoje).
        $todosIds = $idsElegiveis
            ->merge($rowsMensais->keys())
            ->merge($rowsPorEmpresa->keys())
            ->unique()
            ->sort()
            ->values();

        $idsFaltandoNome = $todosIds->diff($idsElegiveis);
        $nomesExtras     = $idsFaltandoNome->isNotEmpty()
            ? User::query()->whereIn('id', $idsFaltandoNome)->pluck('name', 'id')
            : collect();

        $usuarios              = [];
        $inconsistenciasFlat   = [];
        $contagemPorTipo       = [];

        foreach ($todosIds as $userId) {
            $elegivel    = $idsElegiveis->contains($userId);
            $mensal      = $rowsMensais->get($userId);
            $companyRows = $rowsPorEmpresa->get($userId, collect());
            $nome        = $usersElegiveis->get($userId)?->name
                ?? $nomesExtras->get($userId)
                ?? "user #{$userId}";

            $inconsistencias = $this->detectarInconsistencias($elegivel, $mensal, $companyRows, $mesFechado);

            foreach ($inconsistencias as $tipo) {
                $inconsistenciasFlat[] = [
                    'tipo'      => $tipo,
                    'user_id'   => $userId,
                    'user_name' => $nome,
                ];
                $contagemPorTipo[$tipo] = ($contagemPorTipo[$tipo] ?? 0) + 1;
            }

            $breakdown            = $mensal?->breakdown_json ?? [];
            $margemAmostra        = $breakdown['margem_amostra'] ?? [];
            $empresasScoreBreakdown = $breakdown['empresas_score'] ?? [];

            $usuarios[] = [
                'user_id'   => $userId,
                'user_name' => $nome,
                'elegivel'  => $elegivel,
                'snapshot_mensal' => [
                    'existe'                          => $mensal !== null,
                    'updated_at'                       => $mensal?->updated_at?->toIso8601String(),
                    'score'                            => $mensal?->score,
                    'classificacao'                    => $mensal?->classificacao,
                    'ranking_pos'                      => $mensal?->ranking_pos,
                    'nota_final'                       => $breakdown['nota_final'] ?? null,
                    'score_status'                     => $breakdown['score_status'] ?? null,
                    'margem_amostra_cobertura'         => $margemAmostra['cobertura'] ?? null,
                    'margem_amostra_base'               => $margemAmostra['base'] ?? null,
                    'margem_amostra_legado_cobertura'  => $margemAmostra['legado']['cobertura'] ?? null,
                    'empresas_score_count'             => is_array($empresasScoreBreakdown) ? count($empresasScoreBreakdown) : 0,
                ],
                'linhas_por_empresa' => [
                    'total'             => $companyRows->count(),
                    'por_status'        => $companyRows->countBy(fn ($r) => $r->status ?? 'sem_status')->all(),
                    'com_margem_var_pp' => $companyRows->filter(fn ($r) => $r->margem_var_pp !== null)->count(),
                    'origens'           => $companyRows->pluck('origem')->unique()->values()->all(),
                    'max_updated_at'    => $companyRows->max('updated_at')?->toIso8601String(),
                ],
                'inconsistencias' => $inconsistencias,
            ];
        }

        return [
            'mes'             => $mesLabel,
            'mes_referencia'  => $mesStr,
            'mes_fechado'     => $mesFechado,
            'gerado_em'       => now()->toIso8601String(),
            'usuarios'        => $usuarios,
            'inconsistencias' => $inconsistenciasFlat,
            'resumo'          => [
                'total_usuarios'        => count($usuarios),
                'total_inconsistencias' => count($inconsistenciasFlat),
                'por_tipo'              => $contagemPorTipo,
            ],
        ];
    }

    /**
     * As 5 inconsistências (ver docblock da classe para o significado
     * operacional e a ação de cada uma).
     *
     * @param  Collection<int, DesempenhoCompanyScoreSnapshot>  $companyRows
     * @return array<int, string>
     */
    private function detectarInconsistencias(bool $elegivel, ?DesempenhoScoreSnapshot $mensal, Collection $companyRows, bool $mesFechado): array
    {
        $achadas = [];

        if ($elegivel && $mensal === null) {
            $achadas[] = 'SEM_SNAPSHOT';
        }

        $companyCount = $companyRows->count();
        $breakdown    = $mensal?->breakdown_json ?? [];
        $empresasScoreBreakdown = $breakdown['empresas_score'] ?? [];
        $breakdownCount = is_array($empresasScoreBreakdown) ? count($empresasScoreBreakdown) : 0;

        if ($mensal !== null) {
            if ($companyCount === 0 && $breakdownCount > 0) {
                $achadas[] = 'SEM_LINHAS';
            } elseif ($companyCount !== $breakdownCount) {
                $achadas[] = 'DIVERGENCIA_EMPRESAS_SCORE';
            }
        }

        if ($mensal === null && $companyCount > 0) {
            $achadas[] = 'LINHAS_ORFAS';
        }

        if ($mesFechado && $companyCount > 0) {
            $origensDistintas = $companyRows->pluck('origem')->unique()->values();

            if ($origensDistintas->count() !== 1 || $origensDistintas->first() !== CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES) {
                $achadas[] = 'ORIGEM_NAO_CONGELADA';
            }
        }

        return $achadas;
    }

    /**
     * Saída padrão (sem `--json`) — uma tabela por profissional mais um
     * bloco final listando as inconsistências com id e nome. Esta saída é
     * CONVENIÊNCIA HUMANA — nenhum teste desta suíte pode depender dela
     * (122-CONTEXT.md item 5); o `--json` é o contrato.
     */
    private function imprimirRelatorioHumano(array $relatorio): void
    {
        $this->info(sprintf(
            '[VerificarConsolidacao] Competência %s (%s) — %d profissional(is) examinado(s).',
            $relatorio['mes'],
            $relatorio['mes_fechado'] ? 'fechada' : 'em curso',
            $relatorio['resumo']['total_usuarios']
        ));

        $rows = [];
        foreach ($relatorio['usuarios'] as $u) {
            $rows[] = [
                $u['user_name'],
                $u['elegivel'] ? 'sim' : 'não',
                $u['snapshot_mensal']['existe'] ? 'sim' : 'não',
                $u['snapshot_mensal']['nota_final'] !== null ? number_format((float) $u['snapshot_mensal']['nota_final'], 2) : '—',
                $u['snapshot_mensal']['empresas_score_count'].' / '.$u['linhas_por_empresa']['total'],
                implode(',', $u['linhas_por_empresa']['origens']) ?: '—',
                $u['inconsistencias'] === [] ? 'OK' : implode(', ', $u['inconsistencias']),
            ];
        }

        $this->table(
            ['Profissional', 'Elegível', 'Snapshot mensal', 'Nota final', 'Empresas (breakdown/tabela)', 'Origens', 'Inconsistências'],
            $rows
        );

        if ($relatorio['inconsistencias'] === []) {
            $this->info('[VerificarConsolidacao] Nenhuma inconsistência encontrada.');

            return;
        }

        $this->error(sprintf('[VerificarConsolidacao] %d inconsistência(s) encontrada(s):', $relatorio['resumo']['total_inconsistencias']));
        foreach ($relatorio['inconsistencias'] as $inc) {
            $this->line(sprintf('  - %s: %s (user_id=%d)', $inc['tipo'], $inc['user_name'], $inc['user_id']));
        }

        $this->warn('[VerificarConsolidacao] AVISO: esta tabela é CONVENIÊNCIA HUMANA. A conferência OFICIAL é o EXIT CODE (0 = sem inconsistências) ou a saída --json — nunca este texto.');
    }
}
