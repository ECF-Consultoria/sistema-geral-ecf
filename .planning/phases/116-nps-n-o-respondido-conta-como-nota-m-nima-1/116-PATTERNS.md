# Fase 116: NPS não respondido conta como nota mínima (1) - Mapa de Padrões

**Mapeado em:** 2026-07-27
**Arquivos analisados:** 17 (5 novos + 12 modificados/lidos como analog)
**Analogs encontrados:** 17 / 17

> Convenção reconfirmada nesta sessão (Q3 do RESEARCH): a arquitetura recomendada é a
> **terceira via** — tabela nova de grão `survey` (`nps_imputed_assignments` ou nome
> equivalente escolhido pelo plano), populada por um serviço + comando idempotente, e
> consumida como **3º ramo de união disjunta** nos ~11 call-sites já mapeados. Este
> PATTERNS.md assume essa arquitetura (é a única com precedente real no codebase); se o
> plano escolher outra, os analogs de migration/model/service ainda se aplicam ao
> "grão" — só muda o nome.

## Classificação de Arquivos

| Arquivo Novo/Modificado | Papel | Fluxo de Dados | Analog Mais Próximo | Qualidade do Match |
|---|---|---|---|---|
| `database/migrations/2026_XX_XX_create_nps_imputed_assignments_table.php` | migration | CRUD (snapshot append-only) | `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php` | exato |
| `app/Models/NpsImputedAssignment.php` (nome sugerido) | model | CRUD | `app/Models/NpsScoreAssignment.php` | exato |
| `app/Services/Nps/NpsImputationService.php` (nome sugerido) | service | batch / event-driven | `app/Services/Nps/NpsSnapshotService.php` (`registrar()` + `backfillAssignments()`) | exato |
| `app/Console/Commands/NpsMaterializarNaoRespondidos.php` (nome sugerido) | command | batch, idempotente, `--dry-run` | `app/Console/Commands/NpsBackfillAssignmentsConsolidado.php` | exato |
| `app/Services/DesempenhoScoreService.php` (novo método `notasImputadas()` + edição de `computeNpsMedio()` + bump `cacheKey()` v11→v12) | service | CRUD / agregação | o próprio arquivo — `notasPorAtribuicao()`/`notasLegado()` são o molde do 3º ramo | exato (auto-analog) |
| `app/Http/Controllers/NpsController.php` (`index()` — `$agregarMedia`/`$cards`/`$serieMeses`, + respeito a `bonus_invalidacoes` D5) | controller | request-response | `app/Http/Controllers/DesempenhoScoreService.php::notasPorAtribuicao` (para o filtro de invalidadas) + o próprio `index()` (para o padrão de card) | role-match |
| `app/Http/Controllers/PerformanceController.php` (`notasNpsDoUsuarioPorResposta()`, 2 ramos A/B) | controller | request-response | `DesempenhoScoreService::notasPorAtribuicao/notasLegado` (mesma união disjunta, replicada aqui) | exato |
| `app/Http/Controllers/DashboardController.php` (`adminDashboard`/`userDashboard`/`avgNotaDimensao`/`buildRanking`) | controller | request-response | idem — mesmo padrão de iterar surveys + `NpsScoreCalculator::compute()` | role-match |
| `app/Http/Controllers/PortfolioController.php` (histórico NPS mensal do profissional) | controller | request-response | implementação própria, single-path — sem `NpsScoreAssignment` | parcial (ajuste isolado) |
| `app/Http/Controllers/CompanyController.php` (`show()` — payload `nps_surveys`/`avgNps`) | controller | request-response | `NpsController::index()` (mesmo `NpsScoreCalculator::compute()` sobre `company->npsSurveys`) | role-match |
| `app/Jobs/CalculateGoalResults.php` (`computeNps()`) | batch/job | batch | mesmo padrão `NpsResponse::whereHas('survey',...)` + `NpsScoreCalculator::compute()` | role-match |
| `tests/Feature/Phase116/NpsFloorAreaNpsTest.php` (novo) | test | Feature | `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php` (1 teste por call-site) | exato |
| `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` (novo) | test | Feature | `tests/Feature/V18/JanelaNpsBonusTest.php` (mecânica de janela/boundary) | exato |
| `tests/Feature/Phase116/NpsFloorMultiModeloTest.php` (novo) | test | Feature | `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php` (fixture multi-survey/multi-serviço) | role-match |
| `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` (novo) | test | Console/Feature | testes de `NpsBackfillAssignmentsConsolidado` (não há arquivo de teste dedicado hoje — usar o padrão de Command test do Laravel) | parcial |
| `tests/Feature/BonusInvalidacaoEmpresaTest.php` (estender) | test | Feature | o próprio arquivo — adicionar cenário "empresa invalidada + survey sem resposta" | exato |
| `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php` (estender) | test | Feature | o próprio arquivo — adicionar cenário "responsável consolidado sem assignment não vira 1" | exato |
| `resources/js/Pages/Nps/Index.jsx` (texto explicativo + eventualmente `pendentes` no `StatCard`) | component | request-response (props Inertia) | o próprio arquivo — `StatCard` (L441-520ish) já renderiza rodapé "X respondidas · Y pendentes" | exato (auto-analog) |

## Atribuições de Padrão

### `database/migrations/2026_XX_XX_create_nps_imputed_assignments_table.php` (migration, CRUD)

**Analog:** `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php`

**Padrão de schema com FK obrigatória + FK opcional (linhas 38-92, 144-200):**
```php
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nps_response_scores')) {
            Schema::create('nps_response_scores', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar a NpsResponse apaga os scores junto.
                $table->foreignId('nps_response_id')
                    ->constrained('nps_responses')
                    ->cascadeOnDelete();
                ...
```

**ARMADILHA 1 (MySQL 1830) — toda FK com `nullOnDelete()` PRECISA de `->nullable()` (linhas 109-116 e 164-169):**
```php
// FK viva pro catálogo — nullOnDelete preserva o snapshot (service_setor)
// mesmo se o serviço for removido do catálogo depois.
// nullable() OBRIGATÓRIO: o MySQL exige coluna NULLABLE quando a FK é
// ON DELETE SET NULL (erro 1830) — o SQLite dos testes não pega.
$table->foreignId('servico_id')
    ->nullable()
    ->constrained('servicos')
    ->nullOnDelete();
```
Aplique este exato padrão ao novo `servico_id` da tabela imputada — **nunca** esquecer o `->nullable()`, mesmo que o SQLite local não acuse o erro (só aparece no MariaDB do VPS no deploy).

**ARMADILHA 2 (enum + SQLite/CHECK) — não coberta diretamente nesta migration (aqui os enums nascem já no `Schema::create`, não em `->change()`)**, mas se o plano decidir ADICIONAR um valor a um enum já existente em vez de criar um novo, o padrão correto (referenciado no CONTEXT C7, não lido nesta sessão por não haver exemplo direto nas migrations analisadas) exige branch por driver:
```php
if (DB::getDriverName() === 'sqlite') {
    // SQLite: sem CHECK — string() simples é suficiente e não quebra
    $table->string('coluna_enum')->change();
} else {
    // MySQL/MariaDB: enum real
    DB::statement("ALTER TABLE tabela MODIFY coluna_enum ENUM('a','b','novo_valor') NOT NULL");
}
```
Nesta fase 116 isso só se aplica se o plano optar por reaproveitar um enum existente (ex.: adicionar valor a `nps_score_assignments.role` ou a algum status). Se a tabela for 100% NOVA (recomendado), os enums nascem corretos desde o `Schema::create` e esta armadilha não se manifesta — **mas o campo `status` (`provisorio`/`definitivo`) da tabela nova, se um dia precisar de novo valor, cai nesta régua.**

**Índice único para idempotência (padrão, linhas 86-90, 128-129, 194-198):**
```php
// 1 linha por dimensão por resposta.
$table->unique(['nps_response_id', 'dimensao'], 'nps_resp_scores_dim_uniq');

// Acelera o JOIN de bônus (por pessoa+role).
$table->index(['user_id', 'role'], 'nps_score_assign_user_role_idx');
```
Para a tabela nova, o grão sugerido pela pesquisa (`survey_id`, `servico_id`, `role`, `dimensao`) deve virar um `unique()` composto — é o que garante que rodar o comando de materialização 2x não duplica.

---

### `app/Models/NpsImputedAssignment.php` (model, CRUD)

**Analog:** `app/Models/NpsScoreAssignment.php`

**Estrutura completa a copiar (linhas 1-99):**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpsScoreAssignment extends Model
{
    use HasFactory;

    protected $table = 'nps_score_assignments';

    protected $fillable = [
        'nps_response_id',
        'nps_response_score_id',
        'company_id',
        'servico_id',
        'service_setor',
        'role',
        'user_id',
        'average_score',
        'assigned_at',
    ];

    protected $casts = [
        'average_score' => 'float',
        'assigned_at'   => 'datetime',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(NpsResponse::class, 'nps_response_id');
    }
    // ... demais relations belongsTo (score, company, user, servico)
}
```
Para `NpsImputedAssignment`: trocar `nps_response_id`/`nps_response_score_id` por `survey_id` (FK obrigatória — survey SEMPRE existe por construção, D3), adicionar `dimensao` e `status`/`locked_at` ao `$fillable`/`$casts`, e **sem `LogsActivity`** (mesmo padrão do analog — snapshot/imputação não é editável por humano, não precisa de audit trail de edição).

**Docblock de contrato (linhas 9-35)** — copiar o estilo de `@property` documentando cada coluna, incluindo a decisão travada sobre o que cada campo representa (mesmo nível de detalhe do analog).

---

### `app/Services/Nps/NpsImputationService.php` (service, batch/event-driven)

**Analog:** `app/Services/Nps/NpsSnapshotService.php` — em especial `registrar()` (linhas 95-212) e `backfillAssignments()` (linhas 235-326)

**Padrão de resolução do responsável com fallback consolidado — REUSAR, NUNCA REIMPLEMENTAR (linhas 166-211, ênfase 180-195):**
```php
// ─── 3. Interseção cobertos ∩ ativos → nps_score_assignments ────────
// Assignment SÓ para serviços cobertos que também são contrato ATIVO da
// empresa (blindagem T-79-04-01: nunca atribui a serviço não contratado).
$ativos = $company->contratosServico()->active()->pluck('servico_id')->all();
$intersecao = $cobertos->filter(fn ($servico) => in_array($servico->id, $ativos, true));

foreach ($intersecao as $servico) {
    foreach (self::DIMENSAO_ROLE as $dimensao => $role) {
        $score = $scoresPorDimensao[$dimensao] ?? null;
        if (! $score) {
            continue;
        }

        // Fallback consolidado (debug nps-assignment-consolidado): cai pro
        // responsável servico_id NULL quando não há linha específica do
        // serviço — ver docblock da classe.
        $responsaveis = $company->responsavelDoServicoOuConsolidado($role, $servico->id);

        if ($responsaveis->isEmpty()) {
            // Responsável faltante: NÃO cria assignment vazio — registra
            // pendência para reconciliação.
            Log::warning('[NPS Snapshot] responsável faltante — atribuição não gerada', [
                'company_id' => $company->id,
                'servico_id' => $servico->id,
                'role'       => $role,
                'dimensao'   => $dimensao,
            ]);
            continue;
        }

        foreach ($responsaveis as $user) {
            NpsScoreAssignment::create([...]);
        }
    }
}
```
**Regra de ouro (Pitfall 3 do RESEARCH):** usar SEMPRE `Company::responsavelDoServicoOuConsolidado($role, $servicoId)` — nunca `consultorDoServico()`/`estrategistaDoServico()` puros. Fonte:
```php
// Source: app/Models/Company.php:246-263
public function responsavelDoServicoOuConsolidado(string $role, int $servicoId): \Illuminate\Support\Collection
{
    $especificos = $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', $role)
        ->wherePivot('servico_id', $servicoId)
        ->distinct('users.id')
        ->get();

    if ($especificos->isNotEmpty()) {
        return $especificos;
    }

    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', $role)
        ->wherePivotNull('servico_id')
        ->distinct('users.id')
        ->get();
}
```

**Padrão idempotente de "criar só se não existe" (dry-run interno), linhas 235-326:**
```php
public function backfillAssignments(NpsResponse $response, bool $dryRun = false): array
{
    $stats = ['criados' => 0, 'pulos_ja_existentes' => 0, 'pulos_sem_responsavel' => 0, 'detalhe' => []];
    ...
    foreach ($cobertos as $coberto) {
        foreach (self::DIMENSAO_ROLE as $dimensao => $role) {
            $score = $scoresPorDimensao->get($dimensao);
            if (! $score) { continue; }

            $jaExiste = NpsScoreAssignment::where('nps_response_id', $response->id)
                ->where('servico_id', $coberto->servico_id)
                ->where('role', $role)
                ->exists();

            if ($jaExiste) {
                $stats['pulos_ja_existentes']++;
                continue;
            }

            $responsaveis = $company->responsavelDoServicoOuConsolidado($role, $coberto->servico_id);
            if ($responsaveis->isEmpty()) {
                $stats['pulos_sem_responsavel']++;
                Log::warning(...);
                continue;
            }

            foreach ($responsaveis as $user) {
                $stats['detalhe'][] = [...];
                if (! $dryRun) {
                    NpsScoreAssignment::create([...]);
                }
                $stats['criados']++;
            }
        }
    }

    return $stats;
}
```
`NpsImputationService::materializar(NpsSurvey $survey, bool $dryRun = false): array` deve seguir EXATAMENTE esta forma (assinatura + shape de retorno `['criados' => ..., 'pulos_ja_existentes' => ..., ...]`), trocando o gate de entrada:
- Analog entra por `NpsResponse` (resposta já existe).
- Novo serviço entra por `NpsSurvey::where('status', '!=', 'completed')` (Caso 1 do RESEARCH Q5 — nunca `Company::all()`, isso por si só garante o invariante D3).

**Gate de "não respondido" correto (nunca confiar em `status='expired'` isolado) — `NpsController.php:513-521`:**
```php
$statusEfetivo = function ($s) {
    if ($s->status === 'completed') {
        return 'completed';
    }
    if ($s->expires_at && $s->expires_at->isPast()) {
        return 'expired';
    }
    return 'pending';
};
```
A condição de negócio para "candidato a imputação" é `$survey->status !== 'completed'` — nunca `$survey->status === 'expired'`.

**Gate de fechamento por DATA (nunca timestamp cru) — `DesempenhoScoreService.php:734`:**
```php
// CORRETO — imune ao boundary do cron que congela às 14h do último dia:
$janelaFechada = now()->startOfDay()->gte($mesReferencia->copy()->endOfMonth()->startOfDay());
// ERRADO — daria sempre falso no instante exato 14:00 < 23:59:59:
// $janelaFechada = $mesReferencia->copy()->endOfMonth()->lt(now());
```
Usar este exato padrão para decidir quando uma linha imputada `provisorio` vira `definitivo` (D2/NPSFLOOR-07).

---

### `app/Console/Commands/NpsMaterializarNaoRespondidos.php` (command, batch idempotente)

**Analog:** `app/Console/Commands/NpsBackfillAssignmentsConsolidado.php` (arquivo inteiro é o molde, 241 linhas)

**Assinatura + fluxo `handle()` (linhas 44-105):**
```php
class NpsBackfillAssignmentsConsolidado extends Command
{
    protected $signature = 'nps:backfill-assignments-consolidado
        {--dry-run : Só mostra o diff, sem gravar}
        {--force   : Pula a confirmação interativa}';

    protected $description = '...';

    public function __construct(private NpsSnapshotService $snapshotService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $this->info('...' . ($dryRun ? ' (DRY-RUN)' : ''));

        // ─── Passo 1: preview SEMPRE em modo dry-run (read-only) ─────────────
        [$candidatos, $stats, $detalheTotal] = $this->analisar();

        $this->exibirDiff($detalheTotal);
        $this->exibirStats($stats);

        if ($stats['criados'] === 0) {
            $this->info('Nada a corrigir.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('DRY-RUN — nada foi gravado.');
            return self::SUCCESS;
        }

        if (! $force) {
            $confirmado = $this->confirm('Criar ...?', false);
            if (! $confirmado) {
                $this->warn('Operação cancelada pelo operador — nada foi gravado.');
                return self::SUCCESS;
            }
        }

        // ─── Passo 2: gravação real + cache-bust ─────────────────────────────
        $statsReais = $this->aplicar($candidatos);

        $this->info('Concluído:');
        $this->exibirStats($statsReais);

        Log::info('... execução concluída', $statsReais);

        return self::SUCCESS;
    }
```

**Padrão `chunkById` para não estourar memória + coleta de IDs candidatos no passo 1 (linhas 115-145):**
```php
private function analisar(): array
{
    $stats = ['criados' => 0, 'pulos_ja_existentes' => 0, 'pulos_sem_responsavel' => 0];
    $detalheTotal = [];
    $responseIdsComPendencia = [];

    NpsResponse::query()
        ->whereHas('survey', fn ($q) => $q->whereNotNull('template_id'))
        ->with('survey.company')
        ->orderBy('id')
        ->chunkById(200, function ($responses) use (&$stats, &$detalheTotal, &$responseIdsComPendencia) {
            foreach ($responses as $response) {
                $resultado = $this->snapshotService->backfillAssignments($response, dryRun: true);
                $stats['criados'] += $resultado['criados'];
                ...
                if ($resultado['criados'] > 0) {
                    $responseIdsComPendencia[] = $response->id;
                    $detalheTotal = array_merge($detalheTotal, $resultado['detalhe']);
                }
            }
        });

    return [$responseIdsComPendencia, $stats, $detalheTotal];
}
```
Para o comando novo: trocar `NpsResponse::whereHas('survey', ...)` por `NpsSurvey::where('status', '!=', 'completed')->whereHas('company')` — o "candidato" aqui é o survey não respondido, não a resposta.

**Tabela de diff antes de gravar (linhas 213-231):**
```php
private function exibirDiff(array $detalhe): void
{
    if (empty($detalhe)) { return; }

    $this->info('Diff (ANTES de gravar):');
    $this->table(
        ['nps_response_id', 'company_id', 'servico_id', 'role', 'user_id', 'average_score'],
        collect($detalhe)->map(fn ($d) => [...])->toArray()
    );
}
```
**Requisito NOVO desta fase (D1 — mais forte que o analog):** o `--dry-run` precisa mostrar **antes/depois por pessoa e competência** (nota média sem vs. com a imputação, e se muda a faixa de bônus via `BonusFaixa::classificar()`), não só "quantas linhas seriam criadas". O analog só mostra o assignment em si — o comando novo precisa de uma tabela adicional (`$this->table([...pessoa/competência/nota_antes/nota_depois/faixa_antes/faixa_depois...])`).

**Cache-busting (linhas 184-211) — duplicar a régua, não chamar o controller:**
```php
private function bustarCacheDoBonus(NpsResponse $response, DesempenhoScoreService $scoreService): int
{
    $mesCompletado = $response->survey?->completed_at?->copy()->startOfMonth();
    if (! $mesCompletado) { return 0; }

    $mesCompetencia = $mesCompletado->copy()->subMonthNoOverflow()->startOfMonth();

    $userIds = NpsScoreAssignment::where('nps_response_id', $response->id)->pluck('user_id')->unique();

    foreach ($userIds as $userId) {
        Cache::forget($scoreService->cacheKey($userId, $mesCompetencia));
    }

    return $userIds->count();
}
```
Adaptar para: mês de competência = `$survey->month_reference` (ou `created_at` fallback, D6) menos 1 mês (mesma régua NPSWIN-03), pois é o survey que vira definitivo, não uma resposta que chega.

---

### `app/Services/DesempenhoScoreService.php` (3º ramo `notasImputadas()` + bump cacheKey v11→v12)

**Analog:** o próprio arquivo — `computeNpsMedio()` já tem o ponto de extensão marcado no código real (linhas 770-796):
```php
private function computeNpsMedio(User $user, Carbon $mes, ?Collection $invalidadas = null): float
{
    $inicio = $mes->copy()->startOfMonth();
    $fim    = $mes->copy()->endOfMonth();
    $invalidadas = $invalidadas ?? collect();

    $notas = collect();

    // ── (A) Atribuições congeladas da Fase 79 — todas as áreas ───────────
    $notas = $notas->merge(
        $this->notasPorAtribuicao($user, $inicio, $fim, $invalidadas)->pluck('average_score')
    );

    // ── (B) Caminho legado — só as respostas que o snapshot não cobriu ───
    $notas = $notas->merge($this->notasLegado($user, $inicio, $fim, $invalidadas));

    // ── (C) NOVO — nesta fase: notas imputadas (não respondido = 1) ──────
    // $notas = $notas->merge($this->notasImputadas($user, $inicio, $fim, $invalidadas));

    if ($notas->isEmpty()) {
        return 0.0; // DESEMP-03 — sem respostas no mês força nps = 0
    }

    return round($notas->avg(), 2);
}
```

**Molde de `notasPorAtribuicao()` para escrever `notasImputadas()` (linhas 827-855) — MESMO padrão de JOIN + filtro de invalidadas (Pitfall 5 — obrigatório repassar `$invalidadas`):**
```php
private function notasPorAtribuicao(User $user, Carbon $inicio, Carbon $fim, ?Collection $invalidadas = null): Collection
{
    return NpsScoreAssignment::query()
        ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
        ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
        ->where('nps_score_assignments.user_id', $user->id)
        ->where('s.status', 'completed')
        ->whereBetween('s.completed_at', [$inicio, $fim])
        ->whereNull('r.invalidated_at')
        ->when($invalidadas && $invalidadas->isNotEmpty(),
            fn ($q) => $q->whereNotIn('s.company_id', $invalidadas->all()))
        ->groupBy('nps_score_assignments.nps_response_id', 'nps_score_assignments.role')
        ->selectRaw('...')
        ->get()
        ->map(fn ($row) => (object) [...]);
}
```
`notasImputadas()` troca o JOIN por `NpsImputedAssignment` (ou tabela nova) join `nps_surveys`, filtra `user_id`, filtra pelo mês (usar `month_reference`/`created_at` fallback OU o mês em que a linha virou `definitivo` — decisão do plano, mas a régua de `$invalidadas` é OBRIGATÓRIA e idêntica).

**Bump de versão do cache — `cacheKey()` linhas 274-301, seguir o MESMO padrão de comentário histórico ao bumpar:**
```php
public function cacheKey(int $userId, Carbon $mes): string
{
    $mes         = $mes->copy()->startOfMonth();
    $mesCorrente = Carbon::now()->startOfMonth();
    $periodKey   = $mes->equalTo($mesCorrente) ? 'current_month' : $mes->format('Y-m');

    // v11 (2026-07, Fase 110 · FIXMARG-01/02): ...
    // v12 (2026-07-27, Fase 116 · NPSFLOOR): NPS não respondido passa a contar
    // nota 1 na média do bônus — o valor muda para quem tem surveys sem
    // resposta na competência. Sem este bump o Redis continuaria servindo o
    // bônus antigo por até 7 dias mesmo com código novo em prod.
    return sprintf('desempenho.compute.v12.%d.%s', $userId, $periodKey);
}
```
**Atualizar em conjunto (Q8 do RESEARCH) — 4 asserções hardcoded que QUEBRAM:**
- `tests/Feature/DesempenhoShopeeScoreTest.php:363`
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php:246,347`
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php:232,258,260,277`

Todos assumem `'desempenho.compute.v11.'.$user->id.'.current_month'` literal — trocar para `v12`.

**Invalidação por competência já resolvida em `compute()` (linhas 376-386) — `notasImputadas()` DEVE receber o mesmo `$invalidadas`:**
```php
// ── Invalidação por competência (item 3/4 · 2026-07-21) ──────────────
$invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);
if ($invalidadas->isNotEmpty()) {
    $companies = $companies->reject(fn ($c) => $invalidadas->contains($c->id))->values();
}
```
Fonte de `companyIdsInvalidadas` (`app/Models/BonusInvalidacao.php:68-74`):
```php
public static function companyIdsInvalidadas(Carbon $competencia): \Illuminate\Support\Collection
{
    return static::query()
        ->whereDate('competencia', $competencia->copy()->startOfMonth()->toDateString())
        ->pluck('company_id')
        ->map(fn ($id) => (int) $id);
}
```

---

### `app/Http/Controllers/NpsController.php` (`index()` — cards + série + D5 nova capacidade)

**Analog:** o próprio arquivo — padrão de agregação de cards (linhas 606-619):
```php
$agregarMedia = function ($responses, string $dimensao) use ($notaDe) {
    $notas = $responses->map(fn($r) => $notaDe($r, $dimensao))
        ->filter(fn($n) => $n !== null);
    return [
        'media' => $notas->isEmpty() ? 0 : round((float) $notas->avg(), 2),
        'total' => $notas->count(),
    ];
};

$cards = [
    'estrategista' => $agregarMedia($responsesMes, 'estrategista'),
    'analista'     => $agregarMedia($responsesMes, 'analista'),
    'empresa'      => $agregarMedia($responsesMes, 'empresa'),
];
```
A imputação precisa injetar 1 "nota sintética 1.0" por dimensão aplicável (incluindo `empresa`, D7) ANTES do `$agregarMedia`, ou adaptar o helper para aceitar um segundo array de notas imputadas e fazer merge (mesmo padrão `$notas->merge(...)` do `DesempenhoScoreService`).

**Padrão de "faltantes" que NUNCA deve virar 1 (invariante D3) — linhas 447-501, trecho-chave:**
```php
// Empresas que JÁ têm survey de QUALQUER modelo deste setor no mês.
$comSurvey = NpsSurvey::query()
    ->whereIn('template_id', $templateIds)
    ->where(function ($s) use ($mesInicio, $mesFim) {
        $s->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
          ->orWhere(function ($ss) use ($mesInicio, $mesFim) {
              $ss->whereNull('month_reference')
                 ->whereBetween('created_at', [$mesInicio, $mesFim]);
          });
    })
    ->distinct()
    ->pluck('company_id');

$q->whereNotIn('id', $comSurvey);
```
Esta MESMA condição (mês por `month_reference` com fallback `created_at`, D6) deve ser reusada pela imputação para decidir a competência de um survey manual sem `month_reference`.

**Capacidade NOVA (D5) — a área NPS ainda não filtra por `bonus_invalidacoes`; o analog do filtro a copiar é o de `DesempenhoScoreService::compute()` (linhas 376-386, já citado acima)** — importar `BonusInvalidacao::companyIdsInvalidadas($mes)` e aplicar `whereNotIn('company_id', ...)` no `$responsesFilter`/`$faltantes`/`$q` de `index()`. É capacidade nova, não correção de bug (A2 do RESEARCH) — comentar explicitamente no código o motivo (`// Fase 116 D5 — área NPS passa a respeitar bonus_invalidacoes`).

**Cache-busting existente do controller (linhas 1875-1895) — molde a reusar/adaptar para o "survey vira definitivo":**
```php
private function bustarCacheDoBonus(NpsResponse $response, NpsSurvey $survey): void
{
    $mesCompletado = $survey->completed_at?->copy()->startOfMonth();
    if (!$mesCompletado) { return; }

    $mesCompetencia = $mesCompletado->copy()->subMonthNoOverflow()->startOfMonth();

    $userIds = \App\Models\NpsScoreAssignment::where('nps_response_id', $response->id)
        ->pluck('user_id')->unique();

    $scoreService = app(\App\Services\DesempenhoScoreService::class);

    foreach ($userIds as $userId) {
        \Illuminate\Support\Facades\Cache::forget($scoreService->cacheKey($userId, $mesCompetencia));
    }
}
```

**`generate()` (disparo manual) — confirma D6, `month_reference` fica NULL (linhas 909-922):**
```php
$survey = NpsSurvey::create([
    'token'          => Str::uuid()->toString(),
    'company_id'     => $data['company_id'],
    'generated_by'   => $user->id,
    'expires_at'     => now()->endOfMonth(),
    'status'         => 'pending',
    'auto_generated' => false,
    'template_id'    => $template->id,
    // month_reference fica null para manuais (D-12) — só surveys
    // mensais automatizadas carregam o mês de referência semântico.
]);
```
Confirma a preocupação do RESEARCH: o comando/serviço de imputação precisa cobrir o fallback `created_at` para estes surveys.

---

### `resources/js/Pages/Nps/Index.jsx` (UI explicativa, D9)

**Analog:** o próprio arquivo — `StatCard` já tem rodapé "X respondidas · Y pendentes" (linhas 441+) e os 3 cards são montados em (linhas 1610-1639):
```jsx
<div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
    <StatCard
        kicker="ESTRATEGISTA"
        icon={Briefcase}
        color={COL_ESTRATEGISTA_LINE}
        valor={cards.estrategista?.media}
        total={cards.estrategista?.total ?? 0}
        pendentes={pendentesTotal}
        delta={deltaEst}
    />
    <StatCard kicker="ANALISTA" ... />
    <StatCard kicker="EMPRESA" ... />
</div>
```
Sugestão de implementação (Claude's Discretion no CONTEXT): adicionar uma linha de texto simples logo abaixo do grid de `StatCard` (sem jargão — nunca "assignment"/"imputação"/"penalização"), no estilo dos comentários de seção já usados no arquivo (`// ─── Stat cards ... ────`). Texto sugerido no espírito do pedido do usuário: "NPS enviado e não respondido conta nota 1 na média." O backend precisa expor 1-2 chaves novas no payload (`cards.estrategista.pendentes_como_1` ou similar) se o design quiser distinguir visualmente "respondidos" de "contados como 1" — decisão de UI do plano.

---

## Padrões Compartilhados

### União disjunta (extensível a N ramos)
**Fonte:** `app/Services/DesempenhoScoreService.php:770-796` (`computeNpsMedio`)
**Aplicar em:** `DesempenhoScoreService` (3º ramo), `PerformanceController::notasNpsDoUsuarioPorResposta` (réplica dos 2 ramos A/B — precisa do 3º também), qualquer call-site que hoje faça `$notas->merge(...)`.
```php
$notas = collect();
$notas = $notas->merge($this->notasPorAtribuicao(...)->pluck('average_score'));
$notas = $notas->merge($this->notasLegado(...));
$notas = $notas->merge($this->notasImputadas(...)); // NOVO — Fase 116
```

### Resolução de responsável com fallback consolidado
**Fonte:** `app/Models/Company.php:246-263` (`responsavelDoServicoOuConsolidado`)
**Aplicar em:** `NpsImputationService` (é o requisito de ouro — Pitfall 3), qualquer lugar que precise decidir "quem é o dono deste serviço/empresa".

### Invalidação por competência (bônus)
**Fonte:** `app/Models/BonusInvalidacao.php:68-74` (`companyIdsInvalidadas`) + `app/Services/DesempenhoScoreService.php:381` (uso em `compute()`)
**Aplicar em:** `DesempenhoScoreService::notasImputadas()` (obrigatório, Pitfall 5) e, por decisão D5, também em `NpsController::index()` (capacidade nova).

### Gate de fechamento por DATA, nunca timestamp cru
**Fonte:** `app/Services/DesempenhoScoreService.php:734` (`computeNpsWindow`)
```php
$janelaFechada = now()->startOfDay()->gte($mesReferencia->copy()->endOfMonth()->startOfDay());
```
**Aplicar em:** `NpsImputationService`/comando (decidir quando uma linha `provisorio` vira `definitivo`), e em qualquer novo teste de boundary (molde `JanelaNpsBonusTest::test_boundary_ultimo_dia_m_mais_1_14h_zero_respostas_penaliza`).

### Molde de comando de backfill (`--dry-run`/`--force`, tabela de diff, confirm, chunkById, cache-bust, Log::info)
**Fonte:** `app/Console/Commands/NpsBackfillAssignmentsConsolidado.php` (arquivo inteiro)
**Aplicar em:** `NpsMaterializarNaoRespondidos` (novo comando desta fase) — único desvio: o `--dry-run` precisa do relatório antes/depois por pessoa/competência (D1), mais forte que o analog.

### 1 teste por call-site (checklist executável)
**Fonte:** `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php` (estrutura de setUp/tearDown com `Carbon::setTestNow`, trait `CriaCenarioResponsaveis`, helpers `criarTemplateEscopado`/`payloadComPeso`/`responder` via fluxo REAL `POST /nps/{token}`)
**Aplicar em:** `tests/Feature/Phase116/NpsFloorAreaNpsTest.php`, `NpsFloorDesempenhoTest.php` — usar a MESMA trait `Tests\Feature\V16\CriaCenarioResponsaveis` e o mesmo padrão de fixture (survey via `NpsSurvey::create` direto quando o teste for "não respondido", já que não há resposta real a submeter).

## Sem Analog Encontrado

| Arquivo | Papel | Fluxo de Dados | Motivo |
|---|---|---|---|
| `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` | test | Console/Feature | Nenhum dos 2 comandos de backfill NPS existentes (`NpsBackfillAssignmentsConsolidado`, `NpsBackfillDivisorTextoLivre`) tem teste de Console dedicado no repositório — o plano deve criar o padrão do zero, seguindo o estilo geral de Feature test do projeto (`RefreshDatabase` + `$this->artisan(...)->assertExitCode(0)` + asserts de banco) |
| UI de "antes/depois por competência" do `--dry-run` | — | — | Requisito mais forte que qualquer comando existente no módulo (Q9 do RESEARCH); nenhum analog de "relatório de impacto financeiro no console" foi encontrado — construir a tabela adicional do zero usando `$this->table()` (Laravel Console nativo), sem novo pacote |

## Metadados

**Escopo da busca de analog:** `app/Models/`, `app/Services/Nps/`, `app/Console/Commands/`, `app/Http/Controllers/`, `database/migrations/`, `resources/js/Pages/Nps/`, `tests/Feature/Phase96/`, `tests/Feature/V16/`, `tests/Feature/V18/`
**Arquivos escaneados:** 12 (leitura direta nesta sessão) + confirmação por grep de +6 métodos/linhas
**Data de extração de padrões:** 2026-07-27

**Aviso herdado do RESEARCH (Assumption A1):** os números de linha citados acima refletem o estado do código em 2026-07-27. `DesempenhoScoreService`/`PerformanceController`/`NpsController` têm sofrido edições frequentes — o plano/executor deve re-grep pelo NOME do método antes de editar, nunca confiar cegamente no número de linha.
