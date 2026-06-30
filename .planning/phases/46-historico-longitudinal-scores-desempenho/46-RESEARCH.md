# Phase 46: Histórico longitudinal de scores — Research

**Pesquisado em:** 2026-06-30
**Domínio:** Persistência diária de score de carteira + UI de delta/tendência + drawer de detalhe
**Confiança:** HIGH (todos os patterns têm precedente no código real do projeto)

## Resumo

A research confirmou que **todos os 5 patterns necessários para a Phase 46 já existem no projeto** e podem ser reaproveitados sem inventar nada novo. A tabela `polos_faturamento_snapshots` (criada em 2026-06-22 na quick `260622-cq0`) serve como template direto para `desempenho_score_snapshots`. O comando `adman:sync-faturamento` é o template para `desempenho:snapshot-scores`. Para delta visual, há **dois componentes prontos** (`KpiCard` com setas `TrendingUp/Down` e `SparklineCrescimento` com setas Unicode `↑/↓`) — escolher por uso. Drawer lateral à direita já é convenção (`PoloDrawer` em `Polos/Index.jsx`). Endpoints JSON sob demanda seguem o pattern do `NotificacaoController::recentes/contador`.

**Recomendação primária:** copiar 1:1 o esqueleto de `polos_faturamento_snapshots` (migration), `SyncFaturamentoMensal.php` (command) e `PoloDrawer` (drawer); reusar `KpiCard` no header do drawer e copiar a paleta de cor de `SparklineCrescimento` para o badge de delta inline na tabela.

---

## 1. Pattern de Migration recente

**Evidência primária:** `database/migrations/2026_06_22_000000_create_polos_faturamento_snapshots_table.php:30-54` (mesmo padrão de snapshot que a Phase 46 precisa).

### Pattern observado (consistente em todas as migrations 2026)

```php
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desempenho_score_snapshots')) {
            return;                                    // guard idempotente
        }

        Schema::create('desempenho_score_snapshots', function (Blueprint $table) {
            $table->id();                              // PK auto-increment

            $table->foreignId('user_id')               // FK + cascade — padrão atual
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->date('ref_date')->index();         // index simples no campo temporal

            $table->unsignedTinyInteger('score');      // 0-100 cabe em tinyint
            $table->string('classificacao', 16);       // 'excelente'|'bom'|'atencao'|'critico'
            $table->unsignedSmallInteger('ranking_pos')->nullable();
            $table->boolean('tem_base_comparativa')->default(false);
            $table->unsignedSmallInteger('empresas_carteira')->default(0);
            $table->unsignedSmallInteger('empresas_eligiveis')->default(0);
            $table->json('breakdown_json');            // métricas detalhadas do compute()

            $table->timestamps();

            $table->unique(['user_id', 'ref_date']);   // 1 snapshot por user por dia
            $table->index(['ref_date', 'score']);      // ranking histórico por data
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desempenho_score_snapshots');
    }
};
```

### Convenções confirmadas
- **Guard idempotente** `Schema::hasTable(...)` no topo do `up()` — padrão consistente desde `2026_06_22_*` e `2026_06_25_400001_*:29`.
- **`foreignId(...)->constrained(...)->cascadeOnDelete()`** — usado em `2026_06_25_400001:34` para `company_id`. Recomendo o mesmo para `user_id` (se um user for hard-deletado, faz sentido apagar suas snapshots históricas).
- **Unique composto + index por campo temporal** — exato padrão do `polos_faturamento_snapshots:53` (`unique(['mes', 'cust_id'])` + `string('mes')->index()`).
- **Index composto opcional para query principal** — `2026_06_25_400001:63-66` usa nome explícito (`idx_company_ref_provider`). Para snapshots, recomendo `index(['ref_date', 'score'])` para a query "ranking de uma data específica ordenado por score".
- **`json` column** para payload variável — usada em `2026_06_25_400001:57` (`summary`) e `:89` (`motivos`) e `:93` (`metrics_json`). Cast automático no model: `protected $casts = ['breakdown_json' => 'array'];`.
- **Docblock no topo** explicando "Por que existe / Migration idempotente" é convenção forte (vide `polos_faturamento_snapshots:7-21` e `sugador_provider_runs:7-23`).

### Recomendação acionável
**Crie** `database/migrations/2026_06_30_000001_create_desempenho_score_snapshots_table.php` copiando estrutura literal de `polos_faturamento_snapshots`, trocando colunas. Não invente padrões novos.

---

## 2. Pattern de Artisan Command

**Evidência primária:** `app/Console/Commands/SyncFaturamentoMensal.php:10-37` (template ideal: itera users/companies + despacha trabalho + loga prefixo).

### Pattern observado

```php
class SnapshotDesempenhoScores extends Command
{
    protected $signature   = 'desempenho:snapshot-scores
        {--data= : Data de referência YYYY-MM-DD (padrão: hoje)}
        {--user= : Snapshot apenas para 1 user (ID)}';

    protected $description = 'Grava snapshot diário do score de desempenho de cada analista/estrategista.';

    public function __construct(private PortfolioScoreService $scoreService) {}

    public function handle(): int
    {
        $refDate = $this->option('data')
            ? Carbon::createFromFormat('Y-m-d', $this->option('data'))
            : Carbon::today();

        // Mesmo filtro do PerformanceController::index — users com cargo analista/estrategista.
        $users = User::query()
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores')
                  ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
                  ->whereColumn('user_setores.user_id', 'users.id')
                  ->whereIn('cargos.slug', ['analista', 'estrategista']);
            })
            ->when($this->option('user'), fn($q, $id) => $q->where('id', (int) $id))
            ->get();

        $this->info("[Desempenho] Snapshot {$refDate->toDateString()} — {$users->count()} users.");

        $ok = 0; $fail = 0;
        foreach ($users as $user) {
            try {
                $result = $this->scoreService->compute($user);
                ScoreSnapshot::updateOrCreate(
                    ['user_id' => $user->id, 'ref_date' => $refDate->toDateString()],
                    [
                        'score'                => $result['score'],
                        'classificacao'        => $result['classificacao'],
                        'tem_base_comparativa' => $result['tem_base_comparativa'],
                        'empresas_carteira'    => $result['empresas_carteira'],
                        'empresas_eligiveis'   => $result['empresas_eligiveis'],
                        'breakdown_json'       => $result['metricas'],
                    ]
                );
                $ok++;
            } catch (\Throwable $e) {
                Log::error("[Desempenho] Falha snapshot user {$user->id} ({$user->name}): {$e->getMessage()}");
                $fail++;
                continue;                              // continua o lote, não aborta
            }
        }

        // 2º passo: calcular ranking_pos da data atual (1 update por data).
        DB::statement("UPDATE desempenho_score_snapshots ds
            JOIN (SELECT user_id, ROW_NUMBER() OVER (ORDER BY score DESC) AS pos
                  FROM desempenho_score_snapshots WHERE ref_date = ?) r ON r.user_id = ds.user_id
            SET ds.ranking_pos = r.pos
            WHERE ds.ref_date = ?", [$refDate->toDateString(), $refDate->toDateString()]);

        $this->info("[Desempenho] OK: {$ok} · Falhas: {$fail}");
        return self::SUCCESS;
    }
}
```

### Convenções confirmadas
- **Signature com opções nomeadas** — `SyncFaturamentoMensal:12` (`--mes=`), `CalculateGoals:11-16` (`--period=`, `--goal=` etc.). Use `--data=` para data de referência.
- **Description em pt-BR** começando com verbo no presente — vide `SyncFaturamentoMensal:13` "Sincroniza faturamento bruto mensal...".
- **Constructor injection via promoted property** — convenção da Architecture (`PortfolioScoreService` já existe).
- **Log prefix com bracketed module tag** — `[Faturamento]` em `SyncFaturamentoMensal:28`. Use `[Desempenho]`.
- **Erro por item: log + continue, não abort** — convenção da Architecture (`SyncFaturamentoMensal` despacha jobs por empresa sem try/catch porque o erro fica no job; aqui rodamos síncrono então `try { ... } catch (\Throwable) { Log + continue; }`).
- **Retorno `self::SUCCESS`** (int) — `CalculateGoals:46`, `WarmPolosFaturamento:36`, `SyncFaturamentoMensal:35` (esse último usa `Command::SUCCESS` — equivalente).
- **Logging final com sumário** — "X jobs despachados" / "OK: X · Falhas: Y".

### Agendamento
Adicionar em `routes/console.php` após a cascata D-1 (linha ~165), no slot livre **13:30 BRT**:

```php
// Snapshot diário do score de desempenho às 13:30 BRT — depois da cascata D-1
// (PolosFaturamento termina às 13:00). Lê dados já consolidados do dia.
Schedule::command('desempenho:snapshot-scores')
    ->dailyAt('13:30')
    ->name('desempenho-snapshot-scores')
    ->withoutOverlapping();
```

Mesmo pattern de `routes/console.php:15-18` (`adman-sync-d1`).

---

## 3. Pattern de UI delta/badge

**Evidência primária:**
- `resources/js/Components/Carteira/SparklineCrescimento.jsx:22-49` (helper `getColor` com setas Unicode `↑/↓/→`).
- `resources/js/Pages/PainelExecutivo/components/KpiCard.jsx:101-114` (badge com ícones Lucide `TrendingUp/Down/Minus` + texto colorido).

### Já existe — não criar novo componente reusável

O projeto tem **dois patterns convivendo**, escolha por contexto:

#### Pattern A — Inline em linha de tabela (recomendado para coluna "Δ vs ontem" no `RankingConsultoria`)
Copiar exatamente o estilo de `SparklineCrescimento.jsx:22-49`:

| Faixa | Cor texto | Símbolo |
|-------|-----------|---------|
| `> +2` | `text-emerald-400` | `↑` |
| `−2 ≤ x ≤ +2` | `text-white/40` | `→` (ou nada) |
| `< -2` | `text-red-400` | `↓` |
| `null` | `text-white/40` | `—` |

Para delta de score (não percentual), recomendo thresholds `> +1 / < -1` — coerente com a sensibilidade do score 0-100. Renderização:

```jsx
function ScoreDelta({ delta }) {                       // delta = score_hoje - score_ontem
    if (delta === null || delta === undefined) return <span className="text-white/20 font-bold">—</span>;
    const cls = delta > 1 ? 'text-emerald-400' : delta < -1 ? 'text-red-400' : 'text-white/40';
    const arrow = delta > 1 ? '↑' : delta < -1 ? '↓' : '→';
    const sign = delta > 0 ? '+' : '';
    return (
        <span className={cn('inline-flex items-center gap-0.5 text-[11px] font-semibold tabular-nums', cls)}>
            <span aria-hidden="true">{arrow}</span>{sign}{delta}
        </span>
    );
}
```

#### Pattern B — Card grande no header do drawer (recomendado para `Performance/Show.jsx` ao abrir o profissional)
Reusar `KpiCard` direto: `<KpiCard label="Score atual" valor={score} deltaPct={delta_pct_30d} isCount />` — já renderiza ícone Lucide + texto colorido + label MoM (`KpiCard:103-113`).

### Recomendação acionável
**Não criar `DeltaIndicator` reusável.** Definir `ScoreDelta` localmente no arquivo onde for usado (segue convenção da Architecture: "Local sub-components defined in the same file as the page if used only within that page"). Reusar `KpiCard` para o card grande.

---

## 4. Pattern de Drawer/Modal

**Evidência primária:** `resources/js/Pages/Polos/Index.jsx:399-490+` (`PoloDrawer` — drawer lateral direito feito sem dependência de Radix Sheet).

### Pattern observado (drawer lateral à direita)

O projeto **não usa** um componente `Sheet` do shadcn — todos os drawers laterais são construídos manualmente com Tailwind. Radix Dialog existe (`Components/ui/dialog.jsx`) mas é usado só para modais centralizados.

```jsx
function DesempenhoDrawer({ user, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            {/* backdrop com blur — Polos/Index.jsx:421 */}
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            {/* painel à direita */}
            <aside className="relative h-full w-full max-w-xl overflow-y-auto border-l border-white/[0.1] bg-ecf-bg shadow-2xl">
                {/* Header sticky — Polos/Index.jsx:425 */}
                <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-white/[0.08] bg-ecf-bg/95 px-5 py-4 backdrop-blur">
                    <div>
                        <h2 className="text-white font-display font-extrabold text-lg leading-tight">{user.name}</h2>
                        <p className="text-white/40 text-xs">{user.cargo_label}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-white/50 hover:bg-white/[0.06] hover:text-white">
                        <X size={18} />
                    </button>
                </div>

                {/* Conteúdo do drawer — grafico Recharts + breakdown */}
            </aside>
        </div>
    );
}
```

### Convenções confirmadas
- **Wrapper:** `fixed inset-0 z-50 flex justify-end` — encosta o painel à direita.
- **Backdrop:** `absolute inset-0 bg-black/60 backdrop-blur-sm` com `onClick={onClose}`.
- **Painel:** `<aside>` com `max-w-xl` (ou `max-w-2xl` para gráfico Recharts mais largo), `bg-ecf-bg`, `border-l border-white/[0.1]`, `overflow-y-auto`.
- **Header sticky** com botão `X` Lucide canto superior direito.
- **State:** controlado por `useState(null)` no componente pai — quando `null` não renderiza o drawer.

### Recomendação acionável
**Copiar 1:1** a estrutura do `PoloDrawer` (`Polos/Index.jsx:399-436`). Largura `max-w-2xl` para acomodar `<ResponsiveContainer>` do Recharts. **Não importar** `Sheet` ou outra primitiva — não existe no projeto e Radix Sheet não está nas dependências (verificado em `package.json` via stack listado no CLAUDE.md).

---

## 5. Pattern de endpoint JSON sob demanda

**Evidência primária:** `app/Http/Controllers/NotificacaoController.php:85-95` (`recentes()`) e `:129-134` (`contador()`).

### Pattern observado

```php
// Em PerformanceController.php (controller já existe)
public function historicoUser(Request $request, int $userId): JsonResponse
{
    $user = User::findOrFail($userId);

    // Autorização: admin OU o próprio user (mesma policy do PerformanceController::index)
    abort_unless(
        $request->user()->isAdmin() || $request->user()->id === $user->id,
        403
    );

    $dias = (int) ($request->query('dias', 90));
    $dias = max(7, min($dias, 365));                   // clamp 7..365

    $serie = ScoreSnapshot::where('user_id', $userId)
        ->where('ref_date', '>=', now()->subDays($dias))
        ->orderBy('ref_date')
        ->get(['ref_date', 'score', 'classificacao', 'ranking_pos', 'tem_base_comparativa']);

    return response()->json([
        'user_id' => $userId,
        'dias'    => $dias,
        'serie'   => $serie,
    ]);
}
```

### Rota correspondente
Adicionar em `routes/web.php` dentro do grupo autenticado (~linha 137+ onde `dashboard` está):

```php
Route::get('/api/performance/{user}/historico', [PerformanceController::class, 'historicoUser'])
    ->name('performance.historico');
```

### Convenções confirmadas
- **Type hint `JsonResponse`** no método — `NotificacaoController:85,129`.
- **`response()->json([...])`** com array associativo de payload — `NotificacaoController:94, 131-133`.
- **Autorização inline com `abort()` ou `abort_unless()`** — convenção da Architecture (vide `MlbController` que usa `checkPubAccess()`).
- **Sem CSRF token na URL** — endpoints GET autenticados na sessão; o middleware web já cuida disso.
- **Prefixo `/api/`** para endpoints JSON dentro de sessão web (não confundir com `routes/api.php` que exigiria Sanctum) — convenção em `routes/web.php:151-152` (`notificacoes.contador`, `notificacoes.recentes`).

### Consumo no frontend (drawer)
```jsx
const [historico, setHistorico] = useState(null);

useEffect(() => {
    if (!userSelecionado) return;
    fetch(route('performance.historico', userSelecionado.id) + '?dias=90', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    })
        .then(r => r.json())
        .then(data => setHistorico(data.serie));
}, [userSelecionado]);
```

(`axios` está no projeto via `bootstrap.js`, mas fetch nativo é mais leve para 1 chamada — convenção mista no projeto.)

### Recomendação acionável
**Adicionar método `historicoUser(Request, int)` ao `PerformanceController` existente** (sem novo controller). Rota `performance.historico` em `routes/web.php`. Payload curto e direto — Recharts consome diretamente o array `serie`.

---

## Resumo executivo dos 5 pontos

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | Pattern de migration | Copiar `polos_faturamento_snapshots` (2026_06_22). `foreignId->constrained->cascadeOnDelete` + `unique(['user_id','ref_date'])` + `index(['ref_date','score'])` + guard `Schema::hasTable`. |
| 2 | Pattern de command | Copiar `SyncFaturamentoMensal`. Signature `desempenho:snapshot-scores`, prefix `[Desempenho]`, try/catch + continue, `self::SUCCESS`. Schedule `dailyAt('13:30')`. |
| 3 | Delta/badge | **Não criar reusável.** Definir `ScoreDelta` local copiando paleta de `SparklineCrescimento.jsx:22-49`. `KpiCard` para card grande no header do drawer. |
| 4 | Drawer | Copiar 1:1 `PoloDrawer` de `Polos/Index.jsx:399-436`. `max-w-2xl` para gráfico Recharts. Sem dependência nova. |
| 5 | Endpoint JSON | Método `historicoUser` em `PerformanceController` + rota `/api/performance/{user}/historico` em `routes/web.php`. `response()->json([...])`, autorização inline `abort_unless`. |

## Confiança

- Migration / Command / Drawer / Endpoint: **HIGH** — todos têm pelo menos 2 precedentes diretos no código atual.
- Delta/badge: **HIGH** — 2 patterns prontos, decisão é só qual reusar onde.

Nenhum gap. Pronto para `/gsd:plan-phase` ir direto para criação do PLAN.
