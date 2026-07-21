# Phase 106: Fix timeout do mês fechado — warm cache + degradação graciosa - Research

**Researched:** 2026-07-21
**Domain:** Laravel cache/queue (Cache facade, Schedule, Artisan command, Inertia partial reload)
**Confidence:** HIGH (todo achado vem de leitura direta do código do próprio projeto — não há biblioteca externa nova nesta fase)

## Summary

O bug tem causa raiz já confirmada (não reinvestigada aqui): `WarmDesempenhoCache` só aquece `Carbon::now()->startOfMonth()` (mês em curso); os modos "Bônus atual" e "Mês fechado" do `/performance` caem sempre em `computeCached()` frio, que pode levar ~8,9s/profissional (N+1 Adman via `AdmanMetricDiffService`) × ~11-14 profissionais sequenciais = >125s, estourando o timeout web de 300s.

A boa notícia: a infraestrutura de cache já expõe exatamente o gancho que a degradação graciosa precisa. `DesempenhoScoreService::cacheKey(int $userId, Carbon $mes): string` é **público** e determinístico — o controller pode checar `Cache::has($this->scoreService->cacheKey($u->id, $mes))` **sem** disparar o `compute()` caro. Isso viabiliza, sem nenhuma dependência nova: (1) estender `WarmDesempenhoCache` para também aquecer a competência do último mês fechado (SC1); e (2) no controller, separar usuários "quentes" (mostra nota) de "frios" (mostra "calculando…" e dispara aquecimento em background), evitando o cálculo síncrono na requisição (SC2). A UI já tem precedente idêntico de polling parcial via Inertia (`router.reload({ only: ['ranking'], ... })`, usado hoje no Modo TV a cada 5min) — reutilizável para o polling de "calculando…".

A Fase 105 (D2) resolve o problema de forma permanente e progressiva a partir de 2026-07-31 14:00 BRT (fim de julho): a partir daí, cada mês fechado passa a ganhar snapshot mensal (`desempenho_score_snapshots`, `mes_referencia` populada) e o `PerformanceController::index` **já prefere o snapshot ao compute()** quando existe (linha ~150). A Fase 106 é a ponte até lá — e continua necessária depois, porque o dropdown de meses (`meses_disponiveis`, últimos 6 meses) permite selecionar competências que nunca terão snapshot retroativo (o comando não faz backfill automático de meses antigos).

**Primary recommendation:** Estender `WarmDesempenhoCache` com um 2º ciclo de aquecimento para `last_closed_month` (via `MetricPeriodResolver`) mantendo o mesmo agendamento `*/8min 7h-22h` (SC1). No controller, checar `Cache::has()` por usuário ANTES de chamar `computeCached()` quando o período é fechado; para os frios, não computar — marcar `calculando=true` no payload e disparar `Artisan::queue('desempenho:warm-cache', [...])` (extensão do mesmo command, com novo `--mes=` e `--user=*` array) protegido por um lock curto (`Cache::add`) para não empilhar jobs a cada poll. No frontend, reaproveitar o padrão de `router.reload({ only: [...] })` já usado no Modo TV para poll enquanto `calculando=true`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Aquecer cache do mês fechado (cron) | Backend (Artisan Command / Scheduler) | — | `WarmDesempenhoCache` já é o dono deste aquecimento; só ganha um 2º mês-alvo |
| Checar "está em cache?" sem computar | Backend (Service — `DesempenhoScoreService`) | — | `cacheKey()` já é público; só falta um wrapper `Cache::has()` explícito |
| Decidir quente vs frio por usuário + montar payload | API/Backend (`PerformanceController::index`) | — | é onde o loop `$users->map()` já existe (linha ~143) |
| Disparar aquecimento em background sob demanda | Backend (Queue worker via `database` driver) | — | Supervisor já gerencia `ecf-worker:*` em prod; nenhuma infra nova |
| Exibir "calculando…" + poll | Browser/Client (React/Inertia) | — | reaproveita `router.reload({only:[...]})`, já usado no Modo TV |

## User Constraints

Não há `CONTEXT.md` para esta fase (discuss-phase foi pulado por preferência do projeto — lean planning). Não há decisões travadas nem áreas de discricionariedade explícitas; a fonte de verdade de escopo é o `ROADMAP.md` (Goal + 4 Success Criteria da Phase 106, reproduzidos abaixo) e o contexto de bug já diagnosticado passado pelo orquestrador.

### Success Criteria travados no ROADMAP (fonte de verdade)

1. `WarmDesempenhoCache` aquece o mês corrente E a competência do último mês fechado (via `MetricPeriodResolver` `last_closed_month`) — "Bônus atual" sempre quente
2. O ranking no modo fechado, quando o mês pedido está FRIO, não computa tudo ao vivo na requisição — degrada (estado "calculando…"/warm em background), sem estourar o timeout web
3. Nenhuma tela branca por timeout em nenhum modo; "Em curso" (já aquecido) intocado
4. Não regride os números (v18/105) nem a régua — só o CAMINHO de carregamento

**Requirements**: PERF-01 (rótulo genérico no ROADMAP — "a definir na fase"; os 4 SC acima são a especificação operacional real).
**Depends on:** Phase 102 (closed-month compute), Phase 104 (toggles Bônus atual/Mês fechado) — ambas já mescladas no código lido.

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| PERF-01 (SC1) | `WarmDesempenhoCache` aquece mês corrente + último mês fechado | §"Frente 1" abaixo — `MetricPeriodResolver::resolve(['period_key'=>'last_closed_month'])` já usado no controller para o mesmo fim; command precisa só de um 2º loop |
| PERF-01 (SC2) | Degradação graciosa: mês frio não computa ao vivo, mostra "calculando…" | §"Frente 2" abaixo — `cacheKey()` público habilita `Cache::has()`; padrão de poll já existe em `router.reload({only:[...]})` |
| PERF-01 (SC3) | Nenhuma tela branca; "Em curso" intocado | §"Frente 2" — mudança é condicionada a `$periodoResolvido['is_closed']===true`; caminho em-curso não é tocado |
| PERF-01 (SC4) | Não regride números/régua — só o caminho de carregamento | §"Common Pitfalls" — nunca chamar `compute()` puro fora de job/command (já documentado no service); nunca duplicar a lógica de cálculo, só orquestrar quente/frio |
</phase_requirements>

## Standard Stack

Nenhuma dependência nova. Toda a implementação usa infraestrutura Laravel já em uso no projeto:

| Peça | Já usada onde | Reuso proposto |
|------|---------------|-----------------|
| `Illuminate\Support\Facades\Cache` (`Cache::has`, `Cache::remember`, `Cache::add`) | `DesempenhoScoreService::computeCached` | Checar quente/frio + lock anti-duplicação de dispatch |
| `Illuminate\Support\Facades\Artisan` (`Artisan::queue`) | Não usado ainda neste módulo, mas é API estável do Laravel 12 (`vendor/laravel/framework`) | Disparar `desempenho:warm-cache` em background a partir do controller |
| `Schedule::command()->cron()->between()->onOneServer()->withoutOverlapping()->runInBackground()` | `routes/console.php:228-235` (o próprio `desempenho:warm-cache`) | Sem mudança de agendamento — só o command ganha um 2º alvo de mês |
| Queue `database` driver + Supervisor `ecf-worker:*` | Toda a infra de Jobs do projeto (`AnalyzeCompanySugadoresJob`, etc.) | Processa o `Artisan::queue()` disparado sob demanda |
| Inertia `router.reload({ only: [...], preserveScroll, preserveState })` | `Performance/Index.jsx:1685-1692` (Modo TV, poll 5min) | Poll do estado "calculando…" a cada poucos segundos até resolver |

Nenhuma instalação de pacote é necessária — Package Legitimacy Audit não se aplica a esta fase.

### Alternativas Consideradas

| Em vez de | Poderia usar | Tradeoff |
|------------|-----------|----------|
| `Artisan::queue()` reaproveitando `desempenho:warm-cache` | Criar `WarmDesempenhoUsersJob` dedicado (classe nova em `app/Jobs/`) | Job dedicado é mais "limpo" arquiteturalmente, mas duplica a lógica de warm já existente no command; reaproveitar via `--mes=` + `--user=*` é menos código e mantém 1 único lugar de verdade para "como aquecer" |
| `Cache::has()` explícito | Chamar `computeCached()` normalmente e medir tempo de resposta | Não serve — o objetivo é justamente NUNCA disparar o `compute()` caro na requisição síncrona |
| Poll via `router.reload` | WebSocket/SSE (Laravel Echo/Reverb) | Projeto não usa broadcasting em nenhum lugar hoje (nenhuma menção a `laravel/reverb`/Pusher no `composer.json` lido); poll simples é consistente com o padrão já existente e muito mais barato de entregar |

## Architecture Patterns

### Diagrama do fluxo (modo fechado, caso FRIO)

```
Usuário clica "Bônus atual" / seleciona mês fechado
        │
        ▼
GET /performance?modo=bonus_atual (ou ?mes=YYYY-MM)
        │
        ▼
PerformanceController::index()
  ├─ resolve $periodoResolvido via MetricPeriodResolver (já existe)
  ├─ is_closed? ──NÃO──► fluxo atual intocado (computeCached direto) ──► SC3
  │       │SIM
  │       ▼
  │  para cada user do ranking:
  │     Cache::has(scoreService->cacheKey(user.id, mes))?
  │        ├─ SIM (quente) → computeCached() [cache hit, <1s] → linha completa
  │        └─ NÃO (frio)   → NÃO chama computeCached() → linha placeholder
  │                             { calculando: true, nota_final: null, ... }
  │  se houve >=1 frio:
  │     lock = Cache::add("desempenho.warm.lock.{mes}", true, TTL curto)
  │     se lock adquirido → Artisan::queue('desempenho:warm-cache',
  │                            ['--mes' => mes, '--user' => [ids frios]])
  ▼
Inertia::render('Performance/Index', [ ..., 'aquecendo' => bool ])
        │
        ▼
Performance/Index.jsx
  ├─ aquecendo === true → mostra badge "Calculando…" nas linhas frias
  │      useEffect: setInterval(() => router.reload({only:['ranking','aquecendo']}), 5-8s)
  └─ aquecendo === false → limpa interval, renderização normal
        │
        ▼ (em paralelo, fora do request HTTP)
Queue worker (Supervisor ecf-worker) processa o Artisan::queue()
  → desempenho:warm-cache --mes=X --user=[frios]
  → computeCached() por usuário (aquece Cache::remember, TTL 7 dias)
  → próximo poll do frontend já vem quente
```

### Recommended Project Structure

Nenhum arquivo novo obrigatório — todas as mudanças são em arquivos já existentes:

```
app/Console/Commands/WarmDesempenhoCache.php   # +loop last_closed_month, +--mes, +--user=* array
routes/console.php                              # +1 Schedule::command() para o mês fechado (ou reuso do mesmo com 2 chamadas)
app/Services/DesempenhoScoreService.php         # +isCached(User, Carbon): bool  (wrapper de Cache::has)
app/Http/Controllers/PerformanceController.php  # index(): separa quente/frio, monta payload 'aquecendo'
resources/js/Pages/Performance/Index.jsx        # poll condicional + badge "Calculando…" por linha
```

### Pattern 1: Checagem de cache sem computar (a peça central de SC2)

**What:** expõe um método que reusa a MESMA chave de `computeCached()` para responder "está pronto?" sem custo.
**When to use:** sempre que o controller precisar decidir "computo ou não" antes de pagar o custo do `compute()`.
**Example:**
```php
// Fonte: DesempenhoScoreService.php:273-280 (cacheKey já existe, é público)
public function cacheKey(int $userId, Carbon $mes): string
{
    $mes         = $mes->copy()->startOfMonth();
    $mesCorrente = Carbon::now()->startOfMonth();
    $periodKey   = $mes->equalTo($mesCorrente) ? 'current_month' : $mes->format('Y-m');
    return sprintf('desempenho.compute.v6.%d.%s', $userId, $periodKey);
}

// Novo — wrapper fino, mesmo padrão dos outros métodos públicos do service:
public function isCached(User $user, Carbon $mes): bool
{
    return Cache::has($this->cacheKey($user->id, $mes));
}
```
Nenhum outro método de `compute()`/`computeCached()` precisa mudar — a chave já é estável e versionada (`v6`, bump documentado no próprio arquivo quando a régua muda).

### Pattern 2: Dispatch de warm sob demanda com lock anti-duplicação

**What:** evita que cada poll do frontend (a cada 5-8s) dispare N jobs redundantes enquanto o aquecimento ainda está em curso.
**When to use:** no branch "há usuários frios" do controller.
**Example:**
```php
// PerformanceController::index(), só quando $periodoResolvido['is_closed'] === true
$lockKey = "desempenho.warm.lock.{$mesReferencia->format('Y-m')}";
if (!empty($usuariosFrios) && Cache::add($lockKey, true, now()->addMinutes(2))) {
    Artisan::queue('desempenho:warm-cache', [
        '--mes'  => $mesReferencia->format('Y-m'),
        '--user' => $usuariosFrios, // array — requer {--user=* : ...} no signature
    ]);
}
```
`Cache::add()` só grava (e retorna `true`) se a chave ainda não existir — é o mecanismo idiomático do Laravel para lock leve sem precisar de `Cache::lock()` (que exige driver com suporte a lock atômico; `Cache::add` funciona em qualquer driver, incluindo `database`).

### Pattern 3: Poll parcial via Inertia (já existe no projeto — só reaproveitar)

**What:** recarrega só as props necessárias em intervalo curto, sem full page reload.
**When to use:** enquanto `aquecendo === true`.
**Example:**
```javascript
// Fonte: Performance/Index.jsx:1685-1692 (Modo TV — reaproveitar o MESMO padrão)
useEffect(() => {
    if (!aquecendo) return;
    const id = setInterval(() => {
        router.reload({ only: ['ranking', 'aquecendo'], preserveScroll: true, preserveState: true });
    }, 6000); // 6s — mais frequente que o poll de 5min do TV, pois é um estado transitório curto
    return () => clearInterval(id);
}, [aquecendo]);
```

### Anti-Patterns to Avoid

- **Chamar `compute()` (não `computeCached()`) dentro do fluxo de degradação:** o docblock do próprio `computeCached()` já avisa "Não use dentro de jobs/commands de snapshot ou consolidação — chame `compute()` direto pra garantir dado fresco" — o inverso também vale: o warm job/command deve usar `computeCached()` (para escrever no MESMO cache que o controller lê), nunca `compute()` puro.
- **Recalcular "está fechado?" no lugar de ler `$periodoResolvido['is_closed']`:** o `PerformanceController` já resolve isso 1× via `MetricPeriodResolver` (linha ~103-111) — reusar esse booleano, nunca comparar `now()`/mês inline de novo (é exatamente o pitfall que a Fase 102/BON-04 already existe para evitar).
- **Dispatch de job sem lock:** sem o `Cache::add()` de 2min, cada poll de 6s durante um warm de 30-60s dispararia 5-10 jobs redundantes para os MESMOS usuários frios — desperdiça rate limit da Adman sem nenhum ganho de velocidade (o gargalo é o HTTP externo, não a fila).
- **Restringir o dropdown a só os meses aquecidos (opção "c" do brief):** contradiz o SC2 explícito do ROADMAP ("degrada... sem estourar o timeout" — não "bloqueia"); também regride uma capacidade hoje existente (auditar qualquer um dos últimos 6 meses).

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|----------|-------------|--------------------|-----|
| "Está em cache?" | Nova convenção de chave/flag separada em `desempenho_cache_status` (tabela ou Redis set próprio) | `Cache::has($scoreService->cacheKey(...))` | A chave já existe, é versionada e a MESMA fonte de verdade que `computeCached()` escreve — duplicar a lógica de chave é o tipo de divergência que já causou 3 bumps de versão (v1→v6) documentados no próprio arquivo |
| Disparo de warm em background | Job/classe nova espelhando 90% do `WarmDesempenhoCache` | Estender o command existente com `--mes=`/`--user=*` e chamar via `Artisan::queue()` | Um único lugar de verdade para "como aquecer 1 usuário 1 mês" — o command já faz isso hoje (`--option=` single-user já existe para debug pontual) |
| Polling do frontend | Nova lib de polling/websocket | `router.reload({ only: [...] })` | Padrão já em produção no Modo TV da mesma página; zero dependência nova |
| Lock anti-duplicação de dispatch | `Cache::lock()` (exige driver com suporte, ex. Redis/DynamoDB) ou tabela de "jobs em andamento" | `Cache::add($key, true, $ttl)` | Funciona em qualquer cache driver configurado no projeto (`database` local, Redis em prod) sem exigir feature específica do driver |

**Key insight:** a fase inteira é reorquestração de peças que já existem (cache key pública, comando de warm, padrão de poll) — o risco real não é técnico/novidade, é RE-USAR errado (ex.: chamar `compute()` puro no lugar de `computeCached()`, ou recalcular `is_closed` na mão) e assim divergir dos números já corrigidos pela Fase 102/105.

## Common Pitfalls

### Pitfall 1: Rollover de mês fechado durante a janela sem cron (22h–7h)
**What goes wrong:** `resolveLastClosedMonth()` é sempre "mês calendário anterior ao corrente" — no instante em que o relógio vira o dia 1, a competência "última fechada" muda instantaneamente. O cron de warm só roda `7:00–22:00`.
**Why it happens:** o command precisa recalcular `last_closed_month` a cada execução (nunca fixar um valor); entre 00:00 e 07:00 do dia 1, ninguém está aquecendo a NOVA competência.
**How to avoid:** não é um problema NOVO desta fase (o mês corrente já tem essa janela morta hoje) — mas o SC2 (degradação graciosa) é exatamente a rede de segurança para esse gap: se alguém abrir "Bônus atual" às 6h59 do dia 1, cai no caminho frio-com-calculando em vez de tela branca. Não vale a pena estender o range do cron para 24h só por causa disso — o degrade cobre.
**Warning signs:** reclamação de "calculando…" demorado especificamente na madrugada/manhã cedo do dia 1 de cada mês.

### Pitfall 2: `Artisan::queue()` sem queue worker ativo localmente
**What goes wrong:** em dev local (XAMPP, sem `php artisan queue:work` rodando), `Artisan::queue()` enfileira mas nunca processa — o "calculando…" nunca vira quente e o poll roda para sempre.
**Why it happens:** o projeto usa `QUEUE_CONNECTION=database` por padrão; sem worker, jobs ficam parados na tabela `jobs`.
**How to avoid:** documentar no plan que testes manuais locais precisam de `php artisan queue:work` rodando (ou usar `QUEUE_CONNECTION=sync` temporariamente para smoke test, mas isso reintroduz o bloqueio síncrono — só serve para validar que o job EXECUTA corretamente, nunca para medir performance). Em prod, o Supervisor já gerencia `ecf-worker:*` (mencionado em CLAUDE.md) — sem ação extra.
**Warning signs:** payload `aquecendo:true` que nunca vira `false` em teste local.

### Pitfall 3: Lock preso (`Cache::add` nunca expira porque o job falhou)
**What goes wrong:** se o job de warm falhar (exception) antes de terminar, o lock de 2min expira sozinho (TTL curto é intencional) — mas se o TTL for longo demais, uma falha trava novos dispatches por muito tempo, prolongando a experiência "calculando…" sem nenhum warm de fato em andamento.
**Why it happens:** TTL do lock desalinhado com o tempo real de warm (que pode ser >2min se muitos usuários estiverem frios simultaneamente, ex.: primeira abertura de um mês nunca aquecido).
**How to avoid:** medir o tempo real do `desempenho:warm-cache` hoje (o próprio command já loga `$total` em segundos no `$this->info()` final) para calibrar o TTL do lock com folga (ex.: TTL = tempo esperado + 50%); ou usar `Cache::lock()->block()` se o driver de cache em prod for Redis (CLAUDE.md confirma Redis configurado para produção) — mas manter o fallback `Cache::add` para compatibilidade com `database` driver em dev/testes.
**Warning signs:** "calculando…" preso por >5min sem nenhum job novo na tabela `jobs`/`failed_jobs`.

### Pitfall 4: Placeholder "calculando" sendo ordenado como se fosse `nota_final=null` real
**What goes wrong:** o `PerformanceController::index` já faz `sortByDesc(fn ($r) => $r['nota_final'] ?? -1)` — uma linha frio com `nota_final: null` cairia no fim do ranking mesmo que a nota real seja alta, criando "salto" visual quando o warm terminar e a posição mudar.
**Why it happens:** a semântica de `null` hoje é "sem nota" (ex.: `sem_carteira`/`blocked`); reusar o mesmo `null` para "ainda não computado" mistura dois significados diferentes.
**How to avoid:** manter a linha frio na posição/ordem que ela teria SE já tivesse dado (ex.: usar a posição do snapshot mensal anterior como proxy de ordenação, ou simplesmente não reordenar até resolver — mostrar todas as linhas na ordem de `$users` original enquanto `aquecendo=true`, e só aplicar o `sortByDesc` quando o mês estiver 100% quente). Decisão de UX a confirmar no plan — mas o campo `calculando: true` PRECISA existir separado de `nota_final: null` para a tela distinguir "sem nota" de "nota a caminho".
**Warning signs:** ranking "pulando" posições a cada poll enquanto aquece.

### Pitfall 5: Duplicar cálculo entre o warm agendado (SC1) e o warm sob demanda (SC2)
**What goes wrong:** se o cron de warm (SC1) e o dispatch sob demanda (SC2) rodarem ao mesmo tempo para o MESMO usuário/mês, os dois chamam `computeCached()` (que internamente já usa `Cache::remember`) — não há corrupção de dado, mas há 2x custo de Adman desperdiçado.
**Why it happens:** o cron roda a cada 8min fixo; o dispatch sob demanda é acionado por qualquer request de usuário no meio desse intervalo.
**How to avoid:** o lock do Pitfall 3 (`Cache::add` por mês) já mitiga isso para múltiplos requests HTTP concorrentes, mas NÃO impede a colisão entre cron e sob-demanda. Como `Cache::remember` é idempotente (2ª chamada com cache já quente é praticamente grátis), o pior caso é 1 corrida perdida ocasional — aceitável, não vale complexidade extra de lock cross-processo.
**Warning signs:** nenhum sintoma visível ao usuário — é só custo/rate-limit; monitorar `Log::warning` do command se aparecer taxa de falha maior.

## Runtime State Inventory

Não aplicável — Phase 106 não é rename/refactor/migração; não mexe em nomes de tabelas, chaves de config, ou identificadores externos. Nenhum dado armazenado muda de formato.

## Code Examples

### Extensão proposta do `WarmDesempenhoCache` (SC1 — 2º alvo de mês)

```php
// Fonte: app/Console/Commands/WarmDesempenhoCache.php (arquivo já lido — padrão a seguir)
// Ideia: reusar o MESMO loop de $users, só variando $mesReferencia.
// Adicionar MetricPeriodResolver como dependência (já injetável — mesmo
// service usado no PerformanceController).

public function __construct(
    private DesempenhoScoreService $scoreService,
    private MetricPeriodResolver $periodResolver, // NOVO
) {
    parent::__construct();
}

public function handle(): int
{
    $mesQueryOpt = $this->option('mes'); // NOVO — opcional, catch-up manual
    $mesesAlvo = $mesQueryOpt
        ? [Carbon::createFromFormat('Y-m', $mesQueryOpt)->startOfMonth()]
        : [
            Carbon::now()->startOfMonth(), // mês corrente (comportamento atual, preservado)
            Carbon::parse(
                $this->periodResolver->resolve(['period_key' => 'last_closed_month'])['bonus_competence_month'] . '-01'
            )->startOfMonth(), // NOVO — último mês fechado (SC1)
        ];

    // ... resto do loop $users existente, agora aninhado em foreach ($mesesAlvo as $mesReferencia)
}
```

### Checagem quente/frio no controller (SC2)

```php
// Fonte: PerformanceController::index() — trecho do loop atual (linha ~143-160)
// Hoje: SEMPRE chama computeCached() (bloqueante se frio).
// Proposto: só chama quando o período NÃO é fechado, OU quando já está quente.

$usuariosFrios = [];

$rankingRaw = $users->map(function ($u) use (/* ... */ &$usuariosFrios, $periodoResolvido) {
    // ... resolução de $cargoSlug, $snap como já existe ...

    if ($periodoResolvido['is_closed'] && !$this->scoreService->isCached($u, $mesReferencia)) {
        $usuariosFrios[] = $u->id;
        return [/* placeholder: calculando=true, nota_final=null, ... */];
    }

    $resultado = $this->scoreService->computeCached($u, $mesReferencia); // já quente ou mês em curso
    // ... resto igual ...
});

if (!empty($usuariosFrios)) {
    $lockKey = "desempenho.warm.lock." . $mesReferencia->format('Y-m');
    if (Cache::add($lockKey, true, now()->addMinutes(3))) {
        Artisan::queue('desempenho:warm-cache', [
            '--mes'  => $mesReferencia->format('Y-m'),
            '--user' => $usuariosFrios,
        ]);
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| `WarmDesempenhoCache` aquece só `now()->startOfMonth()` | Aquece mês corrente + último mês fechado | Proposto nesta fase (SC1) | "Bônus atual" deixa de depender do band-aid manual aplicado em prod (cache de junho aquecido à mão, expira em ~7 dias) |
| Ranking do mês fechado sempre chama `computeCached()` na requisição | Checa `isCached()` antes; frio → placeholder + warm em background | Proposto nesta fase (SC2) | Elimina o caminho que gera timeout >300s; pior caso vira "calculando…" com poll, nunca tela branca |
| 0 snapshots mensais hoje (2026-07-21) | 1º snapshot mensal (junho) grava em 2026-07-31 14:00 BRT (Fase 105 D2) | Futuro próximo, já agendado | A partir daí, `PerformanceController::index` já prefere snapshot (código existente, linha ~150) — zero custo Adman para aquele mês daquele momento em diante. Fase 106 continua necessária para meses SEM snapshot (histórico pré-Ago/2026 e qualquer gap de consolidação) |

**Deprecated/outdated:**
- Band-aid manual de cache aquecido à mão em prod: deve ser substituído pelo warm automático (SC1) assim que este plan for deployado — documentar no plan que o band-aid pode ser removido/deixado expirar naturalmente.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|----------------|
| A1 | `Artisan::queue()` é a forma recomendada de disparar um Artisan Command via o queue driver `database` já configurado, sem precisar criar uma classe `Job` dedicada — comportamento padrão do Laravel 12 (`Illuminate\Console\Application::queue()` → `QueuedCommand`), não verificado via Context7/doc oficial nesta sessão (só treinamento) | Standard Stack / Pattern 2 | Se o comportamento divergir (ex.: precisar de config extra para serializar `--user` como array), o plan precisa de um fallback simples: job dedicado que injeta `DesempenhoScoreService` e itera os IDs — mesmo efeito, só 1 classe a mais |
| A2 | `{--user=* : ...}` (opção array) funciona sem mudança de comportamento no `--user=<id>` single já existente usado hoje para debug pontual | Code Examples | Baixo risco — é sintaxe padrão de `Illuminate\Console\Command`; se quebrar compat, o plan cria uma opção nova (`--users=` CSV) em vez de reaproveitar `--user` |
| A3 | O TTL do lock (`Cache::add`) sugerido (2-3min) é suficiente para o pior caso de N usuários frios simultâneos — não medido nesta sessão (o comando não rodou ao vivo) | Common Pitfalls #3 | Se o warm real demorar mais, o lock libera cedo demais e permite dispatch duplicado (custo extra de Adman, não corrupção de dado) — mitigável ajustando o TTL após 1ª observação em prod |

**Se esta tabela estivesse vazia:** não está — as 3 assunções acima precisam de confirmação leve (rodar `php artisan tinker` ou um teste manual do `Artisan::queue()` com array option) antes ou durante a execução do plan; nenhuma é bloqueante para começar a implementação.

## Open Questions (RESOLVED — travadas nos plans: Q1 aceita salto, Q2 teto ~20, Q3 cron intocado)

1. **A linha "calculando…" deve manter a posição do ranking anterior (mês fechado já consolidado antes) ou ficar sem posição até resolver?**
   - O que sabemos: o `sortByDesc(nota_final ?? -1)` empurra `null` para o fim — Pitfall 4 documenta o "salto visual".
   - O que não está claro: se a UX aceitável é (a) mostrar a linha na posição alfabética/original até resolver, (b) esconder a linha do ranking principal e listá-la à parte ("N profissionais ainda calculando"), ou (c) aceitar o salto (mais simples).
   - Recomendação: (b) é a mais consistente com o padrão já existente de "bloco de transparência" que a tela já tem para `sem_carteira` (linha 24-26 do cabeçalho do JSX) — reaproveitar o mesmo componente visual para "calculando".

2. **O poll do frontend deve ter um teto de tentativas/tempo (timeout de UX) para o caso raro de o warm falhar permanentemente?**
   - O que sabemos: o Modo TV já usa `setInterval` sem teto (mas é um poll de 5min "para sempre", cenário diferente).
   - O que não está claro: se após, digamos, 5 tentativas (30-40s) sem resolver, a tela deveria mostrar uma mensagem de erro/permitir retry manual em vez de continuar pollando silenciosamente.
   - Recomendação: adicionar um teto simples (ex.: 10 tentativas) que, ao esgotar, mostra "Demorando mais que o esperado — tente novamente" com botão de reload manual. Baixo custo, evita poll infinito órfão se o job falhar e o log não for monitorado.

3. **Vale estender o range do cron de warm (`between('7:00','22:00')`) especificamente para cobrir a virada de mês (Pitfall 1)?**
   - O que sabemos: hoje o range já deixa ~9h/dia sem warm; SC2 cobre esse gap via degradação.
   - O que não está claro: se a expectativa de negócio é que "Bônus atual" esteja SEMPRE pré-aquecido logo cedo no dia 1 (ex.: reunião de bônus de manhã).
   - Recomendação: não mexer no range nesta fase — SC2 já é rede de segurança suficiente; revisitar só se houver reclamação real de lentidão na madrugada/manhã do dia 1.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|--------------|-----------|---------|----------|
| Queue worker (`php artisan queue:work` / Supervisor `ecf-worker:*`) | `Artisan::queue()` processar o warm sob demanda (SC2) | ✓ em prod (Supervisor, per CLAUDE.md); não confirmado ativo em dev local nesta sessão | — | Testes locais: `php artisan queue:work` manual, ou `QUEUE_CONNECTION=sync` só para smoke test funcional (não de performance) |
| Cache driver com suporte a `Cache::has`/`Cache::add` | `isCached()` + lock anti-duplicação | ✓ — `database` (dev/test) e Redis (prod), ambos suportam `has`/`add` nativamente | — | — |
| `MetricPeriodResolver` (Fase 100) | Resolver `last_closed_month` no command de warm | ✓ já em produção, usado pelo `PerformanceController` | — | — |

**Missing dependencies with no fallback:** nenhuma.
**Missing dependencies with fallback:** queue worker local (fallback documentado acima).

## Validation Architecture

`.planning/config.json` não define `workflow.nyquist_validation` explicitamente — tratado como habilitado (default).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `php artisan test --filter=Phase106` (ou nome real da suite escolhida no plan) |
| Full suite command | `php artisan test` |

Nota de ambiente Windows: usar `php` do XAMPP (`C:\xampp\php\php.exe`) se `php` não estiver no PATH global — confirmar no plan qual binário o shell resolve por padrão antes de rodar.

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|---------------------|--------------|
| PERF-01 (SC1) | `desempenho:warm-cache` aquece mês corrente E último mês fechado (2 chamadas de `computeCached`, chaves distintas) | unit/feature | `php artisan test --filter=WarmDesempenhoCacheTest` | ❌ Wave 0 — nenhum teste existente para este command (não encontrado em `tests/`) |
| PERF-01 (SC2) | Controller: usuário frio em mês fechado NÃO chama `compute()` síncrono; retorna `calculando=true` e dispara warm | feature | `php artisan test --filter=PerformanceControllerWarmDegradationTest` | ❌ Wave 0 |
| PERF-01 (SC2) | `DesempenhoScoreService::isCached()` reflete corretamente `Cache::has()` na MESMA chave de `computeCached()` | unit | `php artisan test --filter=DesempenhoScoreServiceCacheTest` | ❌ Wave 0 |
| PERF-01 (SC3) | Modo "Em curso" não é afetado — ranking segue chamando `computeCached()` direto, sem checagem de `isCached` | feature | reusa `PerformanceControllerWarmDegradationTest` (caso negativo) | ❌ Wave 0 |
| PERF-01 (SC4) | Números do ranking (nota_final, faixa_bonus) para usuário JÁ quente permanecem idênticos ao baseline pré-106 | regressão | suite completa `php artisan test` (delta 0) | ✓ (suite existente cobre `DesempenhoScoreServiceTest`, `PerformanceController*Test` se existirem) |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase106` (ou nome real definido no plan)
- **Per wave merge:** `php artisan test` (suite completa)
- **Phase gate:** Suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase106/WarmDesempenhoCacheTest.php` — cobre SC1 (2 competências aquecidas, chaves de cache distintas verificáveis via `Cache::has`)
- [ ] `tests/Feature/Phase106/PerformanceControllerWarmDegradationTest.php` — cobre SC2/SC3 (frio→placeholder+dispatch; quente→número normal; em-curso intocado). Necessita `Queue::fake()` para asserção do `Artisan::queue()` sem processar de verdade
- [ ] `tests/Unit/DesempenhoScoreServiceCacheTest.php` (ou adicionar casos ao arquivo Unit/Feature existente do service) — cobre `isCached()` como novo método público

*(Nenhum framework novo — PHPUnit + `Queue::fake()`/`Cache::` já são padrão Laravel usados no projeto.)*

## Security Domain

`security_enforcement` não está setado para `false` em `.planning/config.json` — tratado como habilitado. Esta fase não introduz superfície de ataque nova: não há input de usuário não validado sendo passado ao `Artisan::queue()` (os IDs de usuário vêm de uma query interna já filtrada por `active=true` + cargo, não de request), e o cache não expõe dado sensível novo (mesmo shape já cacheado hoje).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-------------------|
| V2 Authentication | Não (rota já protegida por middleware de permissão existente, não alterado) | — |
| V4 Access Control | Não (nenhuma nova rota; `/performance` já é gated) | — |
| V5 Input Validation | Sim, parcial | `--mes=`/`--user=` do Artisan command devem validar formato (`YYYY-MM`, ints) antes de usar em query — mesmo padrão já aplicado em `PerformanceController::index` (`preg_match('/^\d{4}-\d{2}$/', $mesQuery)`) |
| V6 Cryptography | Não aplicável | — |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|------------------------|
| Dispatch de job em loop sem lock (esgotamento de fila / rate limit externo) | Denial of Service (auto-infligido, não malicioso) | Lock `Cache::add` (Pattern 2) + `Log::warning` já presente no command se falhar |

## Sources

### Primary (HIGH confidence — leitura direta do código do projeto)
- `app/Console/Commands/WarmDesempenhoCache.php` — implementação atual completa
- `routes/console.php` (linhas 180-235) — agendamento real de todos os crons do módulo Desempenho, incl. `desempenho:warm-cache` (`*/8 * * * *` entre 7h-22h) e `desempenho:consolidar-mes` (`lastDayOfMonth('14:00')`, mudança da Fase 105)
- `app/Services/Metrics/MetricPeriodResolver.php` — `resolveLastClosedMonth()` completo (linhas 185-211)
- `app/Http/Controllers/PerformanceController.php` — `index()` completo (o loop de ranking, resolução de período, payload)
- `app/Services/DesempenhoScoreService.php` — `computeCached()`, `cacheKey()`, `compute()` (o bloco de docblocks documenta os 6 bumps de versão de cache — histórico relevante para não regredir números)
- `app/Models/DesempenhoScoreSnapshot.php` — scopes `mensal()`/`diario()`, contrato de snapshot mensal
- `resources/js/Pages/Performance/Index.jsx` — padrão de toggle de período (linhas 233-263) e padrão de poll parcial via `router.reload` (linhas 1685-1692, Modo TV)
- `.planning/ROADMAP.md` — Goal + 4 Success Criteria da Phase 106 (linhas 1020-1036), origem do bug e diagnóstico já confirmado

### Secondary (MEDIUM confidence)
- Nenhuma — esta fase não depende de documentação externa/biblioteca nova.

### Tertiary (LOW confidence)
- A1/A2 do Assumptions Log (`Artisan::queue()` com opção array) — conhecimento de treinamento sobre Laravel 12, não verificado via Context7 nesta sessão (ambiente não expôs `mcp__context7__*` nesta chamada; WebSearch não foi necessário dado que 100% da implementação é reuso de padrões já existentes no próprio código-fonte do projeto, que é a fonte mais confiável possível para este caso).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero dependência nova, tudo lido diretamente do código-fonte do projeto
- Architecture: HIGH — os 3 patterns propostos reusam mecanismos já em produção (cache key pública, Schedule/Artisan, poll Inertia)
- Pitfalls: HIGH para os pitfalls 1-2 e 4-5 (derivados de leitura direta do código); MEDIUM para o pitfall 3 (TTL do lock é uma estimativa, não medição)

**Research date:** 2026-07-21
**Valid until:** ~14 dias (30/07 aproximadamente) — a Fase 105 muda o comportamento do `desempenho:consolidar-mes` em 31/07 14:00 BRT, o que altera o cenário de fundo (1º snapshot mensal passa a existir) logo após essa janela; replanejar/revisar se a fase não for executada antes disso.
