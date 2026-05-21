# Phase 8: Fundação de Notificações - Context

**Gathered:** 2026-05-21
**Status:** Ready for planning

<domain>
## Phase Boundary

Entrega a infraestrutura mínima de notificações que viabiliza as Phases 9–12:

- Tabela `notifications` (schema nativo do Laravel) migrada e disponível
- Classe abstrata `App\Notifications\BaseNotification` com payload canônico + canal database
- Enum `App\Notifications\Categoria` com as 3 categorias do MVP
- Permission key `notificacoes.criar` registrada no catálogo `App\Support\Permissions`, no novo grupo "Notificações", herdada automaticamente por admin (short-circuit existente) e por líderes (via `AUTO_LIDERANCA`)

Tudo testável via PHPUnit antes de qualquer UI ou disparo real existir. Esta fase NÃO entrega: endpoints HTTP, polling, shared prop Inertia, sino no header, criação manual, observers de metas, scheduled cleanup, ou activity log de envios — todos saem nas Phases 9–12.

</domain>

<decisions>
## Implementation Decisions

### Classe Base de Notification

- **D-01:** Phase 8 entrega uma classe abstrata `App\Notifications\BaseNotification` extendendo `Illuminate\Notifications\Notification`. `via()` fica fixo em `['database']` (sem mail/broadcast no MVP, conforme v3.0 Out of Scope). Phase 9/11/12 estendem com 1 linha por evento.
- **D-02:** Construtor padronizado da `BaseNotification`:
  ```php
  public function __construct(
      public string $titulo,
      public string $mensagem,
      public Categoria $categoria,
      public ?int $autorUserId = null,
      public ?string $url = null,
      public array $meta = [],
  ) {}
  ```
  `toArray()` retorna sempre as 6 chaves (`meta` defaulta a `[]`, nunca `null`). Phase 9 lê esses campos no dropdown/listing com garantia de shape uniforme.
- **D-03:** Categoria tipada como **enum PHP 8.1** `App\Notifications\Categoria` com cases:
  - `META_ATRIBUIDA = 'meta_atribuida'`
  - `META_ATINGIDA = 'meta_atingida'`
  - `MANUAL = 'manual'`

  Construtor aceita `Categoria $categoria`; `toArray()` persiste `$this->categoria->value`. Adicionar categoria nova exige código (mesmo princípio do catálogo `Permissions` estático).
- **D-04:** Phase 8 entrega APENAS a abstrata + enum. **Não cria subclasses concretas** (`ManualNotification`, `MetaAtribuidaNotification`, etc.) — essas saem nas Phases 11 (auto) e 12 (manual). O smoke test desta fase usa uma `TestNotification` anônima dentro do próprio teste para provar que o fluxo `BaseNotification → notifications table` persiste/lê corretamente.

### Tabela `notifications`

- **D-05:** Migration usa o schema nativo Laravel (gerado por `php artisan notifications:table` ou hand-written equivalente): `id` (uuid char(36) PK), `type` (string), `notifiable_id` (unsignedBigInteger) + `notifiable_type` (string) com índice composto, `data` (json), `read_at` (timestamp nullable), `created_at` + `updated_at`. Sem campos custom (sem `categoria` em coluna dedicada — fica dentro de `data`).
- **D-06:** Modelo Eloquent do Laravel (`Illuminate\Notifications\DatabaseNotification`) é usado direto, sem subclasse custom no app, sem trait `LogsActivity` na própria notificação. Activity log só nas dispatch sites (Phase 12, conforme POLL-05 — só envios manuais).

### Permission Catalog

- **D-07:** Nova constante `App\Support\Permissions::NOTIFICACOES_CRIAR = 'notificacoes.criar'`.
- **D-08:** Novo grupo no catálogo: `'Notificações'` (chave do array em `Permissions::catalog()`), posicionado **antes de "Liderança (automático para líderes)"** e depois de "Sistema". Label do item: `'Criar notificações'`. Descrição: `'Envia notificações manuais para usuários, setores, líderes ou todos'`.
- **D-09:** `NOTIFICACOES_CRIAR` adicionada ao array `Permissions::AUTO_LIDERANCA`. Resultado: qualquer user em `setor_lideres` retorna `true` em `hasPermission('notificacoes.criar')` sem atribuição manual ao setor (caminho `isLider() → AUTO_LIDERANCA` em `User::effectivePermissions()`).
- **D-10:** Admin NÃO precisa de mudança — o short-circuit `if ($this->isAdmin()) return true;` em `User::hasPermission()` já cobre PERM-02 sem código novo.

### Escopo de Testes

- **D-11:** Suíte da Phase 8 (estimativa ~5–7 testes em `tests/Feature/Notifications/Phase8FoundationTest.php` ou similar):
  1. `notifications` table existe após migration (assertions de colunas via schema builder)
  2. `Permissions::all()` inclui `'notificacoes.criar'`
  3. `Permissions::AUTO_LIDERANCA` contém `'notificacoes.criar'`
  4. `Permissions::catalog()['Notificações']` existe com label + descrição em pt-BR
  5. Admin (`role=admin`) retorna `true` em `hasPermission('notificacoes.criar')` sem nenhuma atribuição
  6. User em `setor_lideres` retorna `true` em `hasPermission('notificacoes.criar')` sem atribuição manual
  7. Smoke test: `Notification::send($user, new TestNotification(...))` persiste linha em `notifications` com `data` contendo as 6 chaves canônicas (titulo, mensagem, categoria, autor_user_id, url, meta)

### Claude's Discretion

- Namespace exato dos artifacts (sugestão: `App\Notifications\BaseNotification` e `App\Notifications\Categoria` — Laravel default location).
- Nome exato do arquivo de migration (sequência cronológica natural: `2026_05_2X_XXXXXX_create_notifications_table.php`).
- Nome do arquivo de teste e estrutura interna (PHPUnit 11 idioma; vide testes da Phase 5 para padrão).
- Ordem exata do grupo "Notificações" dentro do array `catalog()` desde que fique entre "Sistema" e "Liderança".

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Roadmap e requisitos
- `.planning/ROADMAP.md` §Phase 8 — Goal + 5 success criteria oficiais
- `.planning/REQUIREMENTS.md` §Permissões (PERM) — PERM-01, PERM-02, PERM-03 (mapeados para Phase 8 no traceability)
- `.planning/PROJECT.md` §Key Decisions — confirma "Notificações usam tabela nativa Laravel" e "permission_key notificacoes.criar (admin always, líder via AUTO_LIDERANCA)"
- `.planning/STATE.md` §Accumulated Context — decisões herdadas do v3.0 (polling sem broadcast, targeting no dispatch, real-time = polling + revalidação Inertia)

### Código existente que será estendido
- `app/Support/Permissions.php` — catálogo onde a nova chave entra; estrutura de catalog(), constante AUTO_LIDERANCA, lista plana all()
- `app/Models/User.php` lines 100–140 — `hasPermission()` short-circuit admin, `effectivePermissions()` cache + merge com `AUTO_LIDERANCA` se `isLider()`
- `app/Models/User.php` line 16 — trait `Notifiable` já presente (não precisa adicionar)
- `app/Models/User.php` lines 74–80, 88–96 — relações `setoresLiderados()`, `isLider()` que decidem o `AUTO_LIDERANCA`
- `database/migrations/0001_01_01_000000_create_users_table.php` — referência da convenção de migrations Laravel native

### Convenções e arquitetura
- `.planning/codebase/ARCHITECTURE.md` — Layers (Model, Migration), padrões (Activity Log em modelos principais, queue database), entry points
- `CLAUDE.md` §Conventions §Naming Patterns — Models `PascalCase`, Database columns `snake_case`, PHPDoc em pt-BR
- `CLAUDE.md` §Constraints — stack Laravel 12 + Inertia + React; comentários pt-BR

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`App\Support\Permissions` static catalog** (`app/Support/Permissions.php`): padrão de constantes públicas + `catalog()` array agrupado + `all()` flat + `isValid()`. A nova chave segue exatamente esse padrão; só adicionar 1 constante, 1 entrada de grupo, 1 entrada em `AUTO_LIDERANCA`.
- **`User::hasPermission()` + `effectivePermissions()`** (`app/Models/User.php` 104–140): resolve admin → true automaticamente; resolve `AUTO_LIDERANCA` se `isLider()` automaticamente. Phase 8 não precisa tocar esses métodos.
- **`Illuminate\Notifications\Notifiable` trait** já presente em `User` (line 16): `$user->notify(...)` funciona assim que a tabela existir.
- **Padrão de migration do projeto** (`database/migrations/2026_05_18_100001_create_adman_sync_logs_table.php` e similares): `Schema::create()` + `Schema::dropIfExists()` no `down()`, timestamps padrão, índices declarados inline.

### Established Patterns
- **Models com `LogsActivity`** (PROJECT.md, ARCHITECTURE.md): convenção do projeto. **NÃO aplicado aqui** — D-06 exclui explicitamente activity log na entidade Notification para evitar inundação (POLL-05).
- **Constantes de domínio em PascalCase** + comentário PHPDoc em pt-BR em cada constante (ver `Permissions.php`).
- **Tests Feature em `tests/Feature/`** com PHPUnit 11 e Faker (PROJECT.md Stack). Migrations rodam via `RefreshDatabase` ou equivalente.
- **Carbon/DateTime serialization pattern** já estabelecido (Phase 5 D-15): `?->toDateString()` em props Inertia — não relevante para Phase 8 (sem Inertia), mas vale para Phase 9 quando expor `read_at`.

### Integration Points
- `App\Support\Permissions` — chave nova entra em 3 lugares (constante, catalog group, AUTO_LIDERANCA).
- `database/migrations/` — 1 novo arquivo com timestamp posterior a `2026_05_20_200008_rename_legacy_columns_in_users_table.php`.
- `app/Notifications/` — diretório novo (criar pela primeira vez) para `BaseNotification.php` e `Categoria.php`.
- `tests/Feature/` (e talvez `tests/Feature/Notifications/`) — suíte nova para os 5–7 testes da fundação.
- **Sem mudança em rotas, controllers, middleware, frontend (JSX), Vite/Tailwind, `HandleInertiaRequests`, ou layout** — Phase 8 é 100% backend de fundação.

</code_context>

<specifics>
## Specific Ideas

- Enum `Categoria` deve ser **backed** (`enum Categoria: string`) para que `->value` serialize corretamente no `toArray()` e na coluna `data` JSON. Phases 9/10 vão usar `Categoria::from($data['categoria'])` para hidratar do banco.
- `BaseNotification::toArray()` deve usar `array_filter` cautelosamente: NÃO remover chaves com valor `null` ou `[]`, porque Phase 9 espera shape fixa. Retornar todas as 6 chaves sempre.
- Migration de `notifications` segue padrão Laravel 11+ (uuid char(36) como PK). Se necessário usar `Schema::create('notifications', ...)` manualmente, espelhar o que `php artisan notifications:table` gera no Laravel 12.
- Smoke test usa `TestNotification` anônima dentro do método de teste — não criar arquivo separado em `app/Notifications/`. Padrão: `new class(...) extends BaseNotification {};`.

</specifics>

<deferred>
## Deferred Ideas

- **Categorias adicionais** (ex: `sync_falhado`, `sugador_detectado`, `nps_recebido`): explicitamente Out of Scope no v3.0 (REQUIREMENTS.md §Out of Scope v3.0). Adicionar caso a caso em milestones futuros, editando o enum `Categoria`.
- **Outros canais (`mail`, `broadcast`)**: Out of Scope v3.0. `via()` fica fixo em `['database']` na BaseNotification. Refatorar para aceitar override (ex: array $extraChannels) ficaria para v4.0+ se demanda surgir.
- **Coluna dedicada `categoria` na tabela `notifications`** (para indexar/filtrar por categoria via SQL): não justificado no MVP — `data->>'categoria'` em SQLite/MySQL JSON resolve queries futuras. Reavaliar se Phase 10/11 mostrarem dor de performance.
- **Validação de comprimento de `titulo`/`mensagem` no construtor** (max 100/1000 chars conforme ENVIO-03): essa validação pertence ao FormRequest da Phase 12 (criação manual), não à BaseNotification. Disparos automáticos (Phase 11) usam strings hardcoded, fora do limite de usuário.
- **Trait `App\Notifications\Concerns\StoresInDatabase`** (alternativa à herança): não escolhida; herança via BaseNotification ganhou. Reavaliar se aparecer um caso que precise compor com outra base.

</deferred>

---

*Phase: 8-Fundação de Notificações*
*Context gathered: 2026-05-21*
