# Phase 8: Fundação de Notificações - Research

**Researched:** 2026-05-21
**Domain:** Laravel 12 Notifications API (canal database) + extensão do catálogo `App\Support\Permissions`
**Confidence:** HIGH

## Summary

Phase 8 é puramente backend de fundação e cabe inteiramente dentro de APIs nativas do Laravel 12 já instaladas (`laravel/framework ^12.0` em `composer.json` linha 11) — não há dependência externa nova, nem pacote adicional, nem mudança de stack. O stub canônico de migration `notifications` foi inspecionado diretamente em `vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub` [VERIFIED: vendor source] e é deterministicamente reproduzível por hand-written. O `Illuminate\Notifications\DatabaseChannel` lê via `toArray($notifiable)` quando `toDatabase` não existe (`DatabaseChannel.php` linhas 54–66) [VERIFIED: vendor source], o que confirma o contrato de D-02 — basta a `BaseNotification` ter `toArray()` retornando array com as 6 chaves.

Os 3 requisitos PERM-01 / PERM-02 / PERM-03 mapeiam para mudanças cirúrgicas em `app/Support/Permissions.php` (1 constante + 1 grupo no `catalog()` + 1 entrada em `AUTO_LIDERANCA`) e nenhuma mudança em `app/Models/User.php` — o short-circuit admin (`User::hasPermission()` linha 106) e o merge com `AUTO_LIDERANCA` (linhas 135–137) já cobrem PERM-02 e PERM-03 automaticamente, sem código novo no model. O `Notifiable` trait já está em `User` (linha 16) e expõe `$user->notifications`, `$user->unreadNotifications`, `$user->readNotifications` (`HasDatabaseNotifications.php`) [VERIFIED: vendor source] — disponível para Phase 9 assim que a migration rodar.

**Primary recommendation:** Não usar `php artisan notifications:table` (CLI requer ambiente PHP local que não está acessível neste agente). Em vez disso, copiar literalmente o conteúdo do stub canônico do Laravel 12 (mostrado em §Code Examples abaixo) num arquivo `database/migrations/2026_05_21_100001_create_notifications_table.php`, preservando `uuid('id')->primary()`, `morphs('notifiable')` e `text('data')` exatos. Para a `BaseNotification`, declarar `abstract` para impedir instanciação direta (somente subclasses concretas no Phase 11/12), expor `toArray()` com as 6 chaves do construtor canônico e `via()` retornando `['database']`. Para o enum, usar `enum Categoria: string` (backed) para garantir round-trip via `Categoria::from($valor)` no Phase 9.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Classe abstrata `App\Notifications\BaseNotification` extendendo `Illuminate\Notifications\Notification`. `via()` fixo em `['database']` (sem mail/broadcast no MVP). Phase 9/11/12 estendem com 1 linha por evento.
- **D-02:** Construtor padronizado:
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
  `toArray()` retorna sempre as 6 chaves (`meta` defaulta a `[]`, nunca `null`).
- **D-03:** Enum PHP 8.1 backed string `App\Notifications\Categoria` com 3 cases: `META_ATRIBUIDA = 'meta_atribuida'`, `META_ATINGIDA = 'meta_atingida'`, `MANUAL = 'manual'`. Construtor aceita `Categoria $categoria`; `toArray()` persiste `$this->categoria->value`.
- **D-04:** Phase 8 entrega APENAS a abstrata + enum. NÃO cria subclasses concretas. O smoke test usa uma `TestNotification` anônima dentro do próprio teste.
- **D-05:** Migration usa schema nativo Laravel: `id` (uuid char(36) PK), `type` (string), `notifiable_id` + `notifiable_type` com índice composto, `data` (json/text), `read_at` (timestamp nullable), `created_at` + `updated_at`. Sem coluna `categoria` dedicada — fica dentro de `data`.
- **D-06:** Modelo `Illuminate\Notifications\DatabaseNotification` é usado direto. Sem subclasse custom, sem `LogsActivity` na entidade.
- **D-07:** Nova constante `App\Support\Permissions::NOTIFICACOES_CRIAR = 'notificacoes.criar'`.
- **D-08:** Novo grupo `'Notificações'` em `Permissions::catalog()`, posicionado entre "Sistema" e "Liderança (automático para líderes)". Label: `'Criar notificações'`. Descrição: `'Envia notificações manuais para usuários, setores, líderes ou todos'`.
- **D-09:** `NOTIFICACOES_CRIAR` adicionada ao array `Permissions::AUTO_LIDERANCA`. Resultado: qualquer user em `setor_lideres` retorna `true` em `hasPermission('notificacoes.criar')` sem atribuição manual.
- **D-10:** Admin NÃO precisa de mudança — short-circuit `if ($this->isAdmin()) return true;` em `User::hasPermission()` já cobre PERM-02.
- **D-11:** Suíte ~5–7 testes em `tests/Feature/Notifications/Phase8FoundationTest.php`.

### Claude's Discretion

- Namespace exato dos artifacts (sugestão: `App\Notifications\BaseNotification` e `App\Notifications\Categoria` — Laravel default).
- Nome exato do arquivo de migration (sequência cronológica natural posterior a `2026_05_20_200008`).
- Nome do arquivo de teste e estrutura interna.
- Ordem exata do grupo "Notificações" dentro do array `catalog()` desde que fique entre "Sistema" e "Liderança".

### Deferred Ideas (OUT OF SCOPE)

- Categorias adicionais (`sync_falhado`, `sugador_detectado`, `nps_recebido`) — Out of Scope v3.0.
- Outros canais (`mail`, `broadcast`) — `via()` fixo em `['database']`.
- Coluna dedicada `categoria` em `notifications` — usar `data->>'categoria'` em Phases futuras.
- Validação de comprimento no construtor (`titulo`/`mensagem`) — pertence ao FormRequest da Phase 12.
- Trait `StoresInDatabase` (alternativa à herança) — não escolhida.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PERM-01 | Sistema registra nova permission_key `notificacoes.criar` no catálogo `App\Support\Permissions` com label e descrição em pt-BR, grupo "Notificações" | Padrão de `catalog()` em `app/Support/Permissions.php` linhas 90–137 + constante pública no padrão de `SISTEMA_ACTIVITY_LOG` (linha 63) |
| PERM-02 | Admin tem `notificacoes.criar` automaticamente via short-circuit já existente em `hasPermission` | `User::hasPermission()` linha 106 já retorna `true` para `isAdmin()` — não exige código novo |
| PERM-03 | Qualquer usuário em `setor_lideres` recebe `notificacoes.criar` automaticamente via `Permissions::AUTO_LIDERANCA` | `User::effectivePermissions()` linhas 135–137 já faz `array_merge(..., Permissions::AUTO_LIDERANCA)` quando `isLider()` |

Além dos REQ-IDs explícitos, a fase entrega 2 artifacts não-marcados como requirement mas exigidos pelo Goal e Success Criteria (são pré-requisitos das Phases 9–12 e por isso vivem aqui): (1) migration `notifications` e (2) `BaseNotification` + enum `Categoria`. Esses são tratados como "infraestrutura de fundação", não REQ-IDs.
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Persistência de notificações (UUID, polimorfismo, payload JSON) | Database / Storage | — | Schema nativo Laravel; `notifications` table é a fonte única de verdade |
| Definição de payload canônico e canal database | API / Backend | — | `BaseNotification::toArray()` + `via()` no Application layer |
| Tipagem de categorias do MVP | API / Backend | — | Enum PHP backed em `app/Notifications/` |
| Resolução de permission `notificacoes.criar` | API / Backend | Database / Storage | Catálogo em código (`Permissions::catalog()`); resolução de membro/líder consulta `setor_permissoes` + `setor_lideres` |
| Verificação de identidade do solicitante (admin / líder) | API / Backend | — | `User::hasPermission()` no model; sem mudança aqui |
| Frontend / UI | (none — Phase 8 não toca frontend) | — | Sino e dropdown saem na Phase 10 |

**Sanity-check:** Phase 8 é 100% backend de fundação. Nenhum endpoint HTTP, nenhuma rota, nenhum JSX, nenhuma shared prop Inertia. O único contato com a UI vem por contrato implícito: a migration cria a tabela que a Phase 9 vai consultar.

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 (já instalado) | API completa de Notifications, Schema builder, Eloquent | Stack do projeto; ZERO dependência nova [VERIFIED: composer.json line 11] |
| `phpunit/phpunit` | ^11.5.50 (já instalado) | Suíte de testes Feature | Padrão do projeto; configurado em `phpunit.xml` [VERIFIED: composer.json line 28] |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `fakerphp/faker` | ^1.23 (dev, já instalado) | Geração de dados de teste via factory | Já usado em todos os Feature tests (ex.: `UserFactory`) [VERIFIED: composer.json line 22] |
| `Illuminate\Foundation\Testing\RefreshDatabase` | builtin Laravel | Migra DB em memória entre testes | Padrão observado em `FechamentoMigrationTest.php` linha 11 [VERIFIED: codebase] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `php artisan notifications:table` (CLI) | Migration hand-written copiando o stub | CLI exige ambiente PHP local; hand-written é determinístico e produz output idêntico. Optou-se por hand-written. |
| `Schema::create(...)` + `text('data')` (padrão Laravel 12) | `json('data')` (suportado em MySQL 8/PostgreSQL/SQLite 3.38+) | O stub canônico usa `text`. Mudar para `json` quebraria a contract com `DatabaseNotification::$casts['data'] = 'array'` — o cast já trata serialização/deserialização. **Manter `text` para compat máxima.** |
| Subclasse custom de `DatabaseNotification` | Usar a classe nativa direto | D-06 trava: usar nativa direto, sem subclasse, sem `LogsActivity` |

**Instalação:** Nenhuma. Todas as dependências já estão em `composer.json` e instaladas em `vendor/`.

**Version verification:**
- Laravel `^12.0` confirmado em `composer.json` linha 11 [VERIFIED: composer.json]
- Stub canônico de migration de notifications: `vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub` [VERIFIED: vendor file inspected]
- `DatabaseChannel::send` e `DatabaseChannel::getData`: `vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php` linhas 17–67 [VERIFIED: vendor source]
- `DatabaseNotification::$casts`: `vendor/laravel/framework/src/Illuminate/Notifications/DatabaseNotification.php` linhas 47–50 [VERIFIED: vendor source]

## Package Legitimacy Audit

> Phase 8 não instala pacotes novos. Esta seção está incluída para conformidade com o protocolo.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| (none) | — | — | — | — | — | Phase 8 não adiciona pacotes — usa apenas APIs builtin do `laravel/framework` já presente |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
                     ┌─────────────────────────────────────┐
                     │  PHP / Application                  │
                     │                                     │
                     │  App\Notifications\Categoria        │ ← enum backed string
                     │            ▲                        │
                     │            │ usa                    │
                     │  App\Notifications\BaseNotification │ ← abstract, extends \Illuminate\Notifications\Notification
                     │     │ via()     │ toArray()         │
                     │     ▼           ▼                   │
                     │   ['database']  array 6 chaves      │
                     └────────┬────────────────────────────┘
                              │
                              │ Notification::send($user, $instance)
                              │ (sync queue — phpunit.xml linha 31 'QUEUE_CONNECTION=sync')
                              ▼
                     ┌─────────────────────────────────────┐
                     │  Illuminate\Notifications\           │
                     │    Channels\DatabaseChannel          │ ← lê toArray, monta payload
                     └────────┬────────────────────────────┘
                              │
                              ▼  notifications.create([...])
                     ┌─────────────────────────────────────┐
                     │  Eloquent: DatabaseNotification     │
                     │  (casts: data => array,             │
                     │          read_at => datetime)       │
                     └────────┬────────────────────────────┘
                              │
                              ▼
                     ┌─────────────────────────────────────┐
                     │  notifications table (SQLite tests / │
                     │                       MySQL prod)    │
                     │  id (uuid) | type | notifiable_*    │
                     │  data (text, JSON-encoded) | read_at│
                     └─────────────────────────────────────┘

         ┌─────────────────────────────────────────────────────────┐
         │  Camada de permissão (independente do flow acima)        │
         │                                                          │
         │  App\Support\Permissions  ← constante + catalog + AUTO   │
         │            ▲                                             │
         │            │ checa                                       │
         │  App\Models\User::hasPermission('notificacoes.criar')    │
         │    ├─ isAdmin() → true (short-circuit)                  │
         │    └─ effectivePermissions() inclui AUTO_LIDERANCA       │
         │       quando isLider() === true                          │
         └─────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
app/
├── Notifications/                        ← diretório NOVO
│   ├── BaseNotification.php              ← abstract, 6-key construtor canônico
│   └── Categoria.php                     ← enum backed string com 3 cases
└── Support/
    └── Permissions.php                   ← EDITAR: +1 const +1 grupo catalog +1 AUTO_LIDERANCA

database/
└── migrations/
    └── 2026_05_21_100001_create_notifications_table.php  ← NOVO

tests/
└── Feature/
    └── Notifications/                    ← diretório NOVO
        └── Phase8FoundationTest.php      ← NOVO, 7 testes
```

### Pattern 1: BaseNotification abstrata com toArray() canônico

**What:** Classe abstrata em `App\Notifications` que estende `Illuminate\Notifications\Notification`, fixa `via()` em `['database']` e expõe `toArray()` que retorna sempre as 6 chaves canônicas (titulo, mensagem, categoria, autor_user_id, url, meta).

**When to use:** Toda subclasse concreta criada nas Phases 11 (auto) e 12 (manual) estende `BaseNotification` e não precisa duplicar `via()` nem `toArray()`. Eventos automáticos passam `Categoria::META_ATRIBUIDA` ou `Categoria::META_ATINGIDA` no construtor; criação manual passa `Categoria::MANUAL`.

**Example:**
```php
// Source: D-02 (CONTEXT.md) + DatabaseChannel.php linhas 54–66 (toArray usado se toDatabase ausente)
namespace App\Notifications;

use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification
{
    public function __construct(
        public string $titulo,
        public string $mensagem,
        public Categoria $categoria,
        public ?int $autorUserId = null,
        public ?string $url = null,
        public array $meta = [],
    ) {}

    /** Canal único do MVP: apenas database. */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** Payload canônico — 6 chaves SEMPRE presentes (meta defaulta a []). */
    public function toArray(object $notifiable): array
    {
        return [
            'titulo'         => $this->titulo,
            'mensagem'       => $this->mensagem,
            'categoria'      => $this->categoria->value,   // string 'meta_atribuida' | 'meta_atingida' | 'manual'
            'autor_user_id'  => $this->autorUserId,
            'url'            => $this->url,
            'meta'           => $this->meta,
        ];
    }
}
```

### Pattern 2: Enum backed string para Categoria

**What:** `enum Categoria: string` (PHP 8.1+) com 3 cases. Backed garante `$enum->value` retornar string serializável para JSON e `Categoria::from($string)` deserializar de volta.

**When to use:** Construtor de `BaseNotification` aceita `Categoria $categoria` (tipagem forte impede valores inválidos em compile/static analysis). Phase 9 vai usar `Categoria::from($notification->data['categoria'])` para hidratar o tipo ao expor para o frontend.

**Example:**
```php
// Source: D-03 (CONTEXT.md), PHP 8.1+ backed enums
namespace App\Notifications;

/**
 * Categorias do MVP v3.0. Adicionar nova categoria exige código (mesmo princípio
 * do catálogo Permissions estático).
 */
enum Categoria: string
{
    case META_ATRIBUIDA = 'meta_atribuida';
    case META_ATINGIDA  = 'meta_atingida';
    case MANUAL         = 'manual';
}
```

### Pattern 3: Permission catalog extension

**What:** Adicionar uma nova chave no catálogo estático requer 3 edições cirúrgicas em `app/Support/Permissions.php`:
1. Constante pública nova com PHPDoc em pt-BR (padrão linhas 21–72)
2. Entrada nova em `catalog()` dentro de um novo grupo `'Notificações'` (padrão linhas 92–135)
3. Adicionar à constante array `AUTO_LIDERANCA` (linhas 78–82)

**When to use:** Sempre que uma feature nova precisa de uma chave atribuível por setor. Phase 8 exemplifica o caso "líder ganha automaticamente" via inclusão em `AUTO_LIDERANCA`.

**Example:** Ver §Existing Patterns to Reuse abaixo.

### Anti-Patterns to Avoid

- **NÃO criar subclasses concretas (`ManualNotification`, `MetaAtribuidaNotification`) na Phase 8.** D-04 trava isso explicitamente — essas saem nas Phases 11/12. Phase 8 entrega só a abstrata.
- **NÃO adicionar `LogsActivity` trait ao `DatabaseNotification` ou a uma subclasse Eloquent.** D-06 trava: activity log de notificações fica apenas no dispatch site da criação manual (Phase 12, POLL-05).
- **NÃO usar `json('data')` na migration.** O stub canônico Laravel 12 usa `text('data')` e o cast `array` no model já trata JSON encode/decode. Mudar para `json` introduz dependência de versão SQLite e contradiz o padrão Laravel.
- **NÃO instanciar `BaseNotification` direto no smoke test.** Por ser `abstract`, isso lança fatal. Usar classe anônima (`new class(...) extends BaseNotification {};`).
- **NÃO usar `Notification::fake()` no smoke test.** D-11 #7 quer **persistência REAL** (linha em `notifications` no banco). `Notification::fake()` substitui o dispatcher e impede o channel real de rodar, fazendo o teste passar sem provar que o flow `toArray → DB row` funciona.
- **NÃO adicionar coluna `categoria` dedicada na migration.** D-05 trava: categoria vive dentro de `data` JSON. Adicionar coluna dedicada exigiria refactor downstream em Phase 9 (read path) e contradiz "schema nativo Laravel".

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Persistência de notificações | Tabela custom + model custom | Schema `notifications` nativo + `Illuminate\Notifications\DatabaseNotification` | Resolve UUID PK, polimorfismo `notifiable_*`, casts JSON, scopes `read()`/`unread()`, `markAsRead()` — tudo de graça |
| Acesso a notificações de um user | Query manual | `$user->notifications`, `$user->unreadNotifications`, `$user->readNotifications` (do trait `HasDatabaseNotifications`, já incluso no `Notifiable` trait que está em `User` linha 16) | Já ordenado por `latest()` em `HasDatabaseNotifications` linha 14 |
| Dispatch + persistência | Inserir linha à mão na tabela | `Notification::send($user, $instance)` ou `$user->notify($instance)` | Resolve canais, payload, type, polimorfismo. Em testes com `QUEUE_CONNECTION=sync` (phpunit.xml linha 31), executa imediatamente. |
| Permission resolution para admin / líder | If/else em controller | `User::hasPermission('notificacoes.criar')` | Short-circuit admin + merge `AUTO_LIDERANCA` quando `isLider()` já implementados em `app/Models/User.php` linhas 104–140 |
| Tipagem das 3 categorias do MVP | Const array + string check | `enum Categoria: string` | PHP 8.1 backed enums dão validação em compile time e round-trip via `->value` / `::from()` |

**Key insight:** Phase 8 não constrói NADA de novo conceitualmente — apenas configura artifacts que o Laravel 12 já espera. O risco real é desviar do stub canônico (ex.: usar `json` em vez de `text`) ou criar abstrações desnecessárias (subclasse de `DatabaseNotification`).

## Common Pitfalls

### Pitfall 1: Smoke test usando Notification::fake() em vez de Notification::send() real

**What goes wrong:** `Notification::fake()` swappa o dispatcher por uma versão que apenas registra chamadas em memória sem persistir nada na DB. O teste passa mas a assertion `assertDatabaseHas('notifications', ...)` falha — ou pior, o desenvolvedor pula essa assertion e o teste vira teatro.

**Why it happens:** `Notification::fake()` é o padrão em testes Laravel para **provar que uma notificação foi enviada** (sem se importar com persistência). O caso da Phase 8 é o oposto: queremos provar que o flow real **persiste** corretamente.

**How to avoid:** Usar `Notification::send($user, new TestNotification(...))` direto (sem fake). Com `QUEUE_CONNECTION=sync` no phpunit.xml (linha 31), a chain `DatabaseChannel::send` → `notifiable->routeNotificationFor('database', ...)->create(...)` executa imediatamente e persiste a linha. Depois fazer `$this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'type' => get_class($notification)])` e ler de volta a linha para conferir as 6 chaves em `data` (cast `array` já decoda).

**Warning signs:** Se o teste chama `Notification::fake()` E depois `Notification::assertSentTo(...)`, está provando apenas dispatch, não persistência.

### Pitfall 2: TestNotification anônima referenciada por `get_class` instável

**What goes wrong:** PHP gera nomes opacos para classes anônimas tipo `class@anonymous /path/to/file.php:42$0`. Se o teste tenta `assertDatabaseHas('notifications', ['type' => MyAnonClass::class])`, pode falhar em ambientes onde o offset muda (ex.: linha do `new class` diferente entre versões PHP).

**Why it happens:** Classes anônimas têm nome derivado da localização do código no momento da declaração.

**How to avoid:** Capturar a referência da classe via `$notif = new class(...) extends BaseNotification {};` e usar `$notif::class` (ou `get_class($notif)`) na assertion — assim o nome casa pelo runtime, não hardcoded. Ou, alternativamente, assertir apenas pelo `notifiable_id` + presença de `data->titulo` esperado, sem ancorar no `type`.

**Warning signs:** Assertion com string literal contendo "@anonymous" ou hash hex.

### Pitfall 3: Casting de `data` em SQLite vs MySQL

**What goes wrong:** O stub canônico declara `text('data')` (não `json`). Em SQLite ambos viram TEXT/CLOB internamente. O cast `data => array` no `DatabaseNotification::$casts` (linhas 47–50) serializa via `json_encode` no write e `json_decode(..., true)` no read. **Não há query SQL `->>'campo'` em Phase 8** — apenas leitura via Eloquent. Funciona idêntico em SQLite e MySQL para o que Phase 8 testa.

**Why it happens:** Mistura conceitual entre "coluna JSON nativa" (queries `where('data->categoria', '=', ...)`) e "coluna text com cast array" (round-trip via Eloquent). Phase 8 só usa o segundo.

**How to avoid:** Confiar no cast `array` do model nativo. Assert via `$notif->data['titulo']` (já deserializado), não via raw SQL. Phase 10/11/12 podem precisar de `data->...->'...'` query — mas isso é problema dessas phases (e SQLite 3.38+ suporta `json_extract` nativamente; MySQL 5.7+ suporta `->>` operator).

**Warning signs:** Assertion fazendo `$this->assertDatabaseHas('notifications', ['data' => json_encode([...])])` — frágil porque ordem de chaves em JSON não é garantida. Em vez disso, ler via `DatabaseNotification::query()->first()->data` (já decoded array) e fazer asserts campo-a-campo.

### Pitfall 4: UUID PK em SQLite vs MySQL

**What goes wrong:** `$table->uuid('id')->primary()` em Laravel 12 mapeia para `char(36)` (string) em ambos drivers. **Não há diferença comportamental relevante** — `DatabaseNotification` declara `protected $keyType = 'string'` e `public $incrementing = false` (linhas 19–26), e `DatabaseChannel::buildPayload` linha 34 passa `id => $notification->id` (que é setado por trait `SerializesModels` ou geração explícita — na verdade pela trait `Notification` base + dispatcher que setam um UUID via `Str::uuid()`).

**Why it happens:** Confusão por modelos custom com `bigint id`. O `notifications` é o caso oposto: UUID gerado pela aplicação, não auto-increment.

**How to avoid:** Não tocar no PK. O `Notification::send` interno passa `id` automaticamente. Em testes, assertir presença de qualquer linha (`assertDatabaseCount('notifications', 1)`) ou recuperar via `$user->notifications()->first()`.

**Warning signs:** Tentativa de fazer `DatabaseNotification::create(['id' => 123, ...])` manualmente.

### Pitfall 5: Permissions::AUTO_LIDERANCA é const — não pode ser mutado em runtime

**What goes wrong:** Tentar `Permissions::AUTO_LIDERANCA[] = self::NOTIFICACOES_CRIAR;` ou usar trait que estende em runtime. PHP rejeita modificação de constantes de classe.

**Why it happens:** Falsa intuição vinda de linguagens dinâmicas.

**How to avoid:** Editar literalmente o array no arquivo `app/Support/Permissions.php` linhas 78–82, adicionando a constante nova:
```php
public const AUTO_LIDERANCA = [
    self::LIDERANCA_DASHBOARD_SETOR,
    self::LIDERANCA_DEFINIR_METAS,
    self::LIDERANCA_VER_MEMBROS,
    self::NOTIFICACOES_CRIAR,            // ← linha nova
];
```
Isto é uma edição estática em commit, não runtime — exatamente o desenho intencional (catálogo é fonte única de verdade, fica em código).

**Warning signs:** Código tipo `Permissions::AUTO_LIDERANCA[] = ...;` ou test setup tentando hackear via Reflection.

### Pitfall 6: Migration timestamp anterior à última existente

**What goes wrong:** Se a nova migration tem timestamp menor que `2026_05_20_200008` (última migration atual — verificada em Glob acima), Laravel pode tentar rodá-la fora de ordem ou ignorar dependências.

**Why it happens:** Cópia descuidada de timestamps de migrations vizinhas.

**How to avoid:** Usar timestamp estritamente posterior. Sugestão: `2026_05_21_100001_create_notifications_table.php` (dia seguinte; sequência `100001` evita colisão com `200008` do dia anterior).

**Warning signs:** `php artisan migrate` exibe a migration nova rodando antes de uma já existente.

## Code Examples

Patterns verificados a partir do código vivo do projeto e do `vendor/`:

### Stub canônico de migration (Laravel 12)

```php
// Source: vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');     // gera notifiable_type + notifiable_id + índice composto
            $table->text('data');             // text + cast 'array' no model = JSON round-trip
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

**Notas pt-BR para o planner:**
- Adicionar comentário PHPDoc no topo em pt-BR (convenção CLAUDE.md): `/** Tabela nativa de notificações do Laravel. Polimórfica via notifiable_id/type. */`
- Comentários inline sobre `morphs` e `text` em pt-BR.

### DatabaseChannel — contrato consumido pelo BaseNotification

```php
// Source: vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php linhas 54-66
protected function getData($notifiable, Notification $notification)
{
    if (method_exists($notification, 'toDatabase')) {
        return is_array($data = $notification->toDatabase($notifiable))
            ? $data
            : $data->data;
    }

    if (method_exists($notification, 'toArray')) {
        return $notification->toArray($notifiable);   // ← fallback que BaseNotification usa
    }

    throw new RuntimeException('Notification is missing toDatabase / toArray method.');
}
```

**Implicação:** Como D-02 trava `toArray()` (não `toDatabase()`), o fallback acima é o caminho ativo. Não precisamos implementar `toDatabase()`.

### Padrão de Permission group em catalog (a estender)

```php
// Source: app/Support/Permissions.php linhas 126-135 (grupo "Sistema" como modelo)
'Sistema' => [
    ['key' => self::SISTEMA_ACTIVITY_LOG,    'label' => 'Activity Log',      'description' => 'Log de ações dos usuários'],
    ['key' => self::SISTEMA_DESENVOLVIMENTO, 'label' => 'Desenvolvimento',   'description' => 'Área interna de devs'],
    ['key' => self::SISTEMA_SETORES,         'label' => 'Setores',           'description' => 'Configuração de setores, cargos e permissões'],
],
// ← inserir AQUI o novo grupo "Notificações"
'Liderança (automático para líderes)' => [
    // ...
],
```

**Forma final esperada do novo grupo (a inserir entre Sistema e Liderança):**
```php
'Notificações' => [
    ['key' => self::NOTIFICACOES_CRIAR, 'label' => 'Criar notificações', 'description' => 'Envia notificações manuais para usuários, setores, líderes ou todos'],
],
```

### Padrão de teste Feature com RefreshDatabase + Schema::hasColumn

```php
// Source: tests/Feature/FechamentoMigrationTest.php (referência literal para test 1 da suíte)
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FechamentoMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adiciona_colunas(): void
    {
        $this->assertTrue(Schema::hasColumn('companies', 'service_type'));
        $this->assertTrue(Schema::hasColumn('companies', 'contract_start'));
        $this->assertTrue(Schema::hasColumn('companies', 'contract_end'));
        $this->assertTrue(Schema::hasColumn('companies', 'additional_service'));
    }
}
```

### Padrão de teste Feature usando UserFactory

```php
// Source: tests/Feature/DevControllerTest.php linhas 22-34 (referência literal para tests 5 e 6 da suíte)
$admin = User::factory()->create(['role' => 'admin']);
// ...
$consultor = User::factory()->create(['role' => 'consultor']);
```

`UserFactory::definition()` (linhas 25–34 de `database/factories/UserFactory.php`) já preenche name/email/password — só passar `role` explicitamente quando necessário.

### Criação de relação líder em setor (para test 6)

```php
// Source: app/Models/User.php linhas 75-79 (relação setoresLiderados) + app/Models/Setor.php
// Para tornar um user "líder" em teste, vincular via setor_lideres pivot:
$setor = \App\Models\Setor::create([
    'nome'   => 'Setor de Teste',
    'slug'   => 'setor-de-teste',           // o booted() do Setor gera automático se vazio
    'active' => true,
]);
$user = User::factory()->create(['role' => 'consultor']);   // não-admin
$user->setoresLiderados()->attach($setor->id, [
    'assigned_by' => null,
    'assigned_at' => now(),
]);
// Agora $user->isLider() === true e $user->hasPermission('notificacoes.criar') deve ser true
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `php artisan notifications:table` (Laravel 5–8) gerava migration imediatamente em `database/migrations/` | Em Laravel 12, o command ainda existe (`make:notifications-table` com alias `notifications:table`, `vendor/.../Console/NotificationTableCommand.php` linha 8) e usa o mesmo stub. Stub do Laravel 12 inspecionado é canônico. | Estável desde Laravel 9+ | Hand-written da migration usando o stub é seguro e equivalente. |
| `toArray()` opcional / `toDatabase()` opcional | `toArray()` é fallback se `toDatabase()` não existe (DatabaseChannel.php linhas 54-66). Pode ter um ou outro, não precisa dos dois. | Comportamento estável | D-02 escolhe `toArray()` — single source of truth. |
| Notifiable trait separado de HasDatabaseNotifications | `Notifiable` é trait composto que já inclui `HasDatabaseNotifications` + `RoutesNotifications`. `User` linha 16 já usa `Notifiable`. | Composição estável | Não precisa adicionar trait extra. |

**Deprecated/outdated:**
- Nada removido nesta fase.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | A próxima migration deve usar timestamp `2026_05_21_100001` (estritamente posterior a `2026_05_20_200008`) | Recommended Project Structure | Se o planner escolher outro timestamp posterior, sem impacto. Se escolher anterior, migration roda fora de ordem. |
| A2 | `Schema::hasColumn` retorna `true` para colunas geradas por `morphs('notifiable')` no SQLite — assumindo comportamento padrão Laravel | Validation Architecture (test 1) | Se falhar para `notifiable_id` em SQLite específico, fallback é `Schema::getColumnListing('notifications')` e checar `in_array` |
| A3 | `notifications:table` artisan command ainda existe em Laravel 12 com alias `make:notifications-table` | State of the Art | Verificado em `vendor/.../NotificationTableCommand.php` linhas 6-23 [VERIFIED: vendor source], `assumed` removido — confirmado |
| A4 | A classe anônima usada como `TestNotification` pode ser instanciada com argumentos posicionais matching `BaseNotification::__construct` | Validation Architecture (test 7) | Apenas convenção PHP — funciona em 8.2+ |

**Itens removidos do log original após verificação:** A3 (confirmado via vendor source).

## Open Questions

Nenhuma — todos os pontos estavam ou em CONTEXT.md (decisões travadas) ou foram resolvidos com leitura do `vendor/` e do código existente.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI runtime (`php` binary) | Rodar `php artisan migrate`, `php artisan test` | ✗ no agente | — | Migration hand-written sem CLI; o planner deve sinalizar que execução exige PHP no host (XAMPP local do dev) |
| Laravel 12 framework | Toda a fase | ✓ | `^12.0` (vendor instalado) | — [VERIFIED: composer.json + vendor inspecionado] |
| PHPUnit 11 | Suíte de testes | ✓ | `^11.5.50` (vendor instalado) | — [VERIFIED: composer.json line 28] |
| SQLite (in-memory) para testes | RefreshDatabase em tests | ✓ | configurado em `phpunit.xml` linhas 27-28 (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) | — [VERIFIED: phpunit.xml] |
| Diretório `app/Notifications/` | BaseNotification + Categoria | ✗ (não existe ainda) | — | Criar pela primeira vez — autoload PSR-4 já cobre `App\` → `app/` (composer.json linha 32) |
| Diretório `tests/Feature/Notifications/` | Phase8FoundationTest | ✗ (não existe ainda) | — | Criar pela primeira vez — autoload PSR-4 já cobre `Tests\` → `tests/` (composer.json linha 39) |
| `Illuminate\Foundation\Testing\RefreshDatabase` | Tests | ✓ | builtin Laravel | — [VERIFIED: usado em FechamentoMigrationTest.php linha 5] |
| `User::factory()` | Tests | ✓ | `database/factories/UserFactory.php` | — [VERIFIED: codebase] |
| Tabela `setores` + `setor_lideres` para criar líder em test | Test 6 (PERM-03) | ✓ | migrations `2026_05_20_200001` e `2026_05_20_200005` | — [VERIFIED: migrations existem] |

**Missing dependencies with no fallback:** Nenhuma.

**Missing dependencies with fallback:** Os 2 diretórios novos (`app/Notifications/`, `tests/Feature/Notifications/`) — o planner deve incluir tarefas explícitas de criação.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.50 [VERIFIED: composer.json line 28] |
| Config file | `phpunit.xml` (testsuites: Unit, Feature; SQLite in-memory; QUEUE_CONNECTION=sync) [VERIFIED: phpunit.xml] |
| Quick run command | `php artisan test --filter=Phase8FoundationTest` |
| Full suite command | `php artisan test` (executa `tests/Unit` + `tests/Feature` conforme `phpunit.xml`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| (infra) | Tabela `notifications` criada com colunas canônicas | feature | `php artisan test --filter=Phase8FoundationTest::test_migration_cria_tabela_notifications` | ❌ Wave 0 |
| PERM-01 | `Permissions::all()` inclui `'notificacoes.criar'` | feature | `php artisan test --filter=Phase8FoundationTest::test_permissions_all_inclui_notificacoes_criar` | ❌ Wave 0 |
| PERM-01 | `Permissions::catalog()` tem grupo `'Notificações'` com label e descrição pt-BR | feature | `php artisan test --filter=Phase8FoundationTest::test_catalog_inclui_grupo_notificacoes` | ❌ Wave 0 |
| PERM-03 | `Permissions::AUTO_LIDERANCA` contém `'notificacoes.criar'` | feature | `php artisan test --filter=Phase8FoundationTest::test_auto_lideranca_inclui_notificacoes_criar` | ❌ Wave 0 |
| PERM-02 | Admin retorna `true` em `hasPermission('notificacoes.criar')` sem atribuição | feature | `php artisan test --filter=Phase8FoundationTest::test_admin_tem_permissao_via_short_circuit` | ❌ Wave 0 |
| PERM-03 | Líder retorna `true` automaticamente | feature | `php artisan test --filter=Phase8FoundationTest::test_lider_tem_permissao_via_auto_lideranca` | ❌ Wave 0 |
| (infra) | Smoke test: `Notification::send` persiste linha com `data` contendo as 6 chaves canônicas | feature | `php artisan test --filter=Phase8FoundationTest::test_base_notification_persiste_payload_canonico` | ❌ Wave 0 |

### Test specifications detalhadas (7 testes)

Todos em `tests/Feature/Notifications/Phase8FoundationTest.php`, com `use RefreshDatabase;`.

**Test 1 — `test_migration_cria_tabela_notifications` (cobre Success Criterion #1)**
```php
$this->assertTrue(Schema::hasTable('notifications'));
$this->assertTrue(Schema::hasColumn('notifications', 'id'));
$this->assertTrue(Schema::hasColumn('notifications', 'type'));
$this->assertTrue(Schema::hasColumn('notifications', 'notifiable_id'));
$this->assertTrue(Schema::hasColumn('notifications', 'notifiable_type'));
$this->assertTrue(Schema::hasColumn('notifications', 'data'));
$this->assertTrue(Schema::hasColumn('notifications', 'read_at'));
$this->assertTrue(Schema::hasColumn('notifications', 'created_at'));
$this->assertTrue(Schema::hasColumn('notifications', 'updated_at'));
```
**Prova:** Success Criterion #1 (tabela existe e migrada).

**Test 2 — `test_permissions_all_inclui_notificacoes_criar` (cobre PERM-01, Success Criterion #2 parte 1)**
```php
$this->assertContains('notificacoes.criar', \App\Support\Permissions::all());
$this->assertSame('notificacoes.criar', \App\Support\Permissions::NOTIFICACOES_CRIAR);
$this->assertTrue(\App\Support\Permissions::isValid('notificacoes.criar'));
```
**Prova:** PERM-01 + Success Criterion #2 (constante e lista plana).

**Test 3 — `test_catalog_inclui_grupo_notificacoes` (cobre PERM-01, Success Criterion #2 parte 2)**
```php
$catalog = \App\Support\Permissions::catalog();
$this->assertArrayHasKey('Notificações', $catalog);
$entry = collect($catalog['Notificações'])->firstWhere('key', 'notificacoes.criar');
$this->assertNotNull($entry);
$this->assertSame('Criar notificações', $entry['label']);
$this->assertStringContainsString('manuais', $entry['description']);
```
**Prova:** PERM-01 + Success Criterion #2 (label e descrição em pt-BR, grupo "Notificações").

**Test 4 — `test_auto_lideranca_inclui_notificacoes_criar` (cobre PERM-03, Success Criterion #5)**
```php
$this->assertContains('notificacoes.criar', \App\Support\Permissions::AUTO_LIDERANCA);
```
**Prova:** Success Criterion #5 + PERM-03 (presença no array constante).

**Test 5 — `test_admin_tem_permissao_via_short_circuit` (cobre PERM-02, Success Criterion #3)**
```php
$admin = User::factory()->create(['role' => 'admin']);
$this->assertTrue($admin->hasPermission('notificacoes.criar'));
// Não atribui setor nem permissão manual — prova short-circuit
```
**Prova:** PERM-02 + Success Criterion #3.

**Test 6 — `test_lider_tem_permissao_via_auto_lideranca` (cobre PERM-03, Success Criterion #4)**
```php
$setor = \App\Models\Setor::create(['nome' => 'Setor Teste', 'active' => true]);
$user  = User::factory()->create(['role' => 'consultor']);   // explicitamente NÃO admin
$user->setoresLiderados()->attach($setor->id, ['assigned_by' => null, 'assigned_at' => now()]);
$user->refresh();   // invalida o cache effectivePermissionsCache
$this->assertTrue($user->isLider());
$this->assertTrue($user->hasPermission('notificacoes.criar'));
```
**Prova:** PERM-03 + Success Criterion #4. Crítico: NÃO atribuir setor membro nem permissão manual — só `setoresLiderados()`.

**Test 7 — `test_base_notification_persiste_payload_canonico` (smoke test, cobre Success Criterion #1 end-to-end)**
```php
$user = User::factory()->create(['role' => 'consultor']);

$notif = new class(
    titulo: 'Título teste',
    mensagem: 'Mensagem teste',
    categoria: \App\Notifications\Categoria::MANUAL,
    autorUserId: null,
    url: '/notificacoes',
    meta: ['ref' => 'phase-8-smoke'],
) extends \App\Notifications\BaseNotification {};

\Illuminate\Support\Facades\Notification::send($user, $notif);
// NÃO usar Notification::fake() — queremos persistência real

$this->assertDatabaseCount('notifications', 1);
$row = \Illuminate\Notifications\DatabaseNotification::query()->first();
$this->assertSame($user->id,                (int) $row->notifiable_id);
$this->assertSame(\App\Models\User::class,  $row->notifiable_type);
$this->assertNull($row->read_at);

// data é castado como 'array' pelo DatabaseNotification — vem deserializado
$this->assertSame('Título teste',     $row->data['titulo']);
$this->assertSame('Mensagem teste',   $row->data['mensagem']);
$this->assertSame('manual',           $row->data['categoria']);   // ← enum->value persistido
$this->assertNull($row->data['autor_user_id']);
$this->assertSame('/notificacoes',    $row->data['url']);
$this->assertSame(['ref' => 'phase-8-smoke'], $row->data['meta']);
```
**Prova:** Success Criterion #1 end-to-end (a tabela não só existe — ela aceita escrita via DatabaseChannel + lê 6 chaves canônicas). Também prova `BaseNotification.toArray()` e `Categoria::MANUAL->value === 'manual'` implicitamente.

### Sampling Rate

- **Per task commit:** `php artisan test --filter=Phase8FoundationTest` (suíte da fase apenas — ~7 testes, deve rodar em <2s com SQLite in-memory)
- **Per wave merge:** `php artisan test --testsuite=Feature` (toda a Feature suite — garante que nada quebrou em Adman/Fechamento)
- **Phase gate:** `php artisan test` (Unit + Feature) verde antes de `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/Notifications/` (diretório novo) — criar antes do primeiro teste
- [ ] `tests/Feature/Notifications/Phase8FoundationTest.php` — cobre todos os 7 testes acima
- [ ] Sem necessidade de shared fixtures / `conftest.py` equivalente — setup inline com `User::factory()` e `Setor::create()` é suficiente para 7 testes
- [ ] Framework PHPUnit 11 já instalado — sem `composer require` adicional

*(Nenhuma instalação de pacote adicional necessária — todo o stack já existe.)*

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (indireto) | Phase 8 não toca auth diretamente; depende de `EnsureUserHasRole` middleware nas Phases 9+. PERM-02/03 são authorization, não authentication. |
| V3 Session Management | no | Phase 8 não interage com sessão |
| V4 Access Control | yes (CORE) | `notificacoes.criar` é a primary access control para Phases 11/12. Phase 8 estabelece o **gate** mas não o aplica em nenhuma rota (rotas saem nas Phases 9+). PERM-02 (admin via short-circuit) e PERM-03 (líder via AUTO_LIDERANCA) **são** o controle de acesso. |
| V5 Input Validation | yes (parcial) | Construtor `BaseNotification` tipa `Categoria` (impede valor inválido em compile time). Validação de comprimento de `titulo`/`mensagem` é deferida para FormRequest da Phase 12 (D-deferred). |
| V6 Cryptography | no | Nenhum cripto nesta fase |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Permission escalation via mutação de `AUTO_LIDERANCA` | Elevation of Privilege | `AUTO_LIDERANCA` é `public const` em PHP — imutável em runtime. Mudança requer commit + deploy. [VERIFIED: app/Support/Permissions.php linha 78] |
| Notification flooding / Spam (DoS) | Denial of Service | Out of scope nesta fase; rate limiting fica em Phase 12 (criação manual). Disparos automáticos da Phase 11 são gated por eventos de domínio (criação de Goal), não por user. |
| Cross-tenant notification leak (user A vê notificações de user B) | Information Disclosure | `notifiable_id` + `notifiable_type` polimórfico + scoping via `$user->notifications` (que filtra por morphTo). Phase 9 endpoint MUST scope `Auth::id()` no read path. Phase 8 só estabelece a coluna; aplicação é Phase 9. |
| Hidden categories (categoria inválida bypass) | Tampering | Enum `Categoria: string` rejeita valores não declarados em compile time. Phase 9 deve usar `Categoria::tryFrom($value)` com fallback explícito se ler de input não-confiável (não aplicável em Phase 8). |

## Existing Patterns to Reuse

### Padrão de constante + PHPDoc pt-BR em Permissions

Adicionar entre as constantes existentes (após `SISTEMA_SETORES` linha 65, antes da seção `LIDERANCA_*`):
```php
// Source: app/Support/Permissions.php — padrão das linhas 21–72
/** Criar notificações manuais (admin sempre tem; líderes ganham via AUTO_LIDERANCA). */
public const NOTIFICACOES_CRIAR        = 'notificacoes.criar';
```

### Padrão de array AUTO_LIDERANCA

```php
// Source: app/Support/Permissions.php linhas 74-82 (estado atual)
public const AUTO_LIDERANCA = [
    self::LIDERANCA_DASHBOARD_SETOR,
    self::LIDERANCA_DEFINIR_METAS,
    self::LIDERANCA_VER_MEMBROS,
    self::NOTIFICACOES_CRIAR,            // ← linha a adicionar (D-09)
];
```

### Padrão de hasPermission e effectivePermissions (NÃO MEXER)

```php
// Source: app/Models/User.php linhas 104-140
public function hasPermission(string $key): bool
{
    if ($this->isAdmin()) return true; // short-circuit superuser ← cobre PERM-02 automaticamente

    return \in_array($key, $this->effectivePermissions(), true);
}

public function effectivePermissions(): array
{
    if ($this->effectivePermissionsCache !== null) {
        return $this->effectivePermissionsCache;
    }
    if ($this->isAdmin()) {
        return $this->effectivePermissionsCache = Permissions::all();
    }
    $keys = \App\Models\SetorPermissao::query()
        ->whereIn('setor_id', $this->setores()->pluck('setores.id'))
        ->pluck('permission_key')->unique()->values()->all();

    if ($this->isLider()) {
        $keys = array_values(array_unique(array_merge($keys, Permissions::AUTO_LIDERANCA)));
        // ← cobre PERM-03 automaticamente
    }
    return $this->effectivePermissionsCache = $keys;
}
```
**Phase 8 NÃO modifica este método.** PERM-02/03 funcionam só pela edição do catálogo.

### Padrão de isLider (NÃO MEXER)

```php
// Source: app/Models/User.php linhas 88-91
public function isLider(): bool
{
    return $this->setoresLiderados()->exists();
}
```

### Padrão de migration hand-written

```php
// Source: database/migrations/2026_05_20_200005_create_setor_lideres_table.php (referência estrutural)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setor_lideres', function (Blueprint $table) {
            // ...
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setor_lideres');
    }
};
```

### Padrão de teste Feature com criação de admin

```php
// Source: tests/Feature/AdminFechamentoControllerTest.php linhas 16-19
private function criarAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}
```

## Code Locations

Paths exatos que o planner deve usar:

| Tipo | Path |
|------|------|
| Migration | `database/migrations/2026_05_21_100001_create_notifications_table.php` (timestamp sugerido — posterior a `2026_05_20_200008`) |
| Abstract class | `app/Notifications/BaseNotification.php` |
| Enum | `app/Notifications/Categoria.php` |
| Permissions catalog edit | `app/Support/Permissions.php` (edita: linhas 65–66 para inserir constante; linhas 78–82 para AUTO_LIDERANCA; linhas 130–131 para inserir grupo "Notificações" entre Sistema e Liderança) |
| Test file | `tests/Feature/Notifications/Phase8FoundationTest.php` (diretório `Notifications/` é novo) |

## Phase Coverage Map

| Success Criterion / REQ-ID | Coberto por (código) | Coberto por (teste) |
|----------------------------|----------------------|----------------------|
| SC#1 — Tabela `notifications` migrada | `database/migrations/2026_05_21_100001_create_notifications_table.php` (copy do stub canônico) | Test 1 (`test_migration_cria_tabela_notifications`) + Test 7 (smoke E2E) |
| SC#2 — `Permissions::all()` inclui `notificacoes.criar` com grupo "Notificações", label + descrição pt-BR | `app/Support/Permissions.php` (constante `NOTIFICACOES_CRIAR` + grupo `'Notificações'` em `catalog()`) | Test 2 + Test 3 |
| SC#3 — Admin retorna true sem atribuição | (nenhum código novo — short-circuit existente em `User::hasPermission()`) | Test 5 (`test_admin_tem_permissao_via_short_circuit`) |
| SC#4 — Líder retorna true sem atribuição manual | `app/Support/Permissions.php::AUTO_LIDERANCA` recebe `NOTIFICACOES_CRIAR` (merge existente em `User::effectivePermissions()`) | Test 6 (`test_lider_tem_permissao_via_auto_lideranca`) |
| SC#5 — `notificacoes.criar` consta em `Permissions::AUTO_LIDERANCA` | `app/Support/Permissions.php::AUTO_LIDERANCA` (edição literal do array) | Test 4 (`test_auto_lideranca_inclui_notificacoes_criar`) |
| **PERM-01** — registrar permission_key no catálogo com label/descrição pt-BR | `app/Support/Permissions.php` (3 edições: const + catalog + AUTO) | Test 2 + Test 3 + Test 4 |
| **PERM-02** — Admin tem via short-circuit | (zero código novo) | Test 5 |
| **PERM-03** — Líder tem automaticamente via AUTO_LIDERANCA | `app/Support/Permissions.php::AUTO_LIDERANCA` | Test 4 (presença no array) + Test 6 (resolução end-to-end via `hasPermission`) |
| **Infra-extra** — BaseNotification abstrata + enum Categoria + persistência funcional | `app/Notifications/BaseNotification.php` + `app/Notifications/Categoria.php` + migration | Test 7 (smoke end-to-end com classe anônima) |

**Cobertura:** 5/5 Success Criteria + 3/3 REQ-IDs cobertos por código + teste. Zero requirement sem teste.

## Sources

### Primary (HIGH confidence)

- `vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub` — stub canônico de migration [VERIFIED: arquivo inspecionado]
- `vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php` — contrato de `toArray()` fallback [VERIFIED: linhas 54-66]
- `vendor/laravel/framework/src/Illuminate/Notifications/DatabaseNotification.php` — model com casts `data => array` [VERIFIED: linhas 47-50]
- `vendor/laravel/framework/src/Illuminate/Notifications/HasDatabaseNotifications.php` — relação `notifications()` polimórfica [VERIFIED: arquivo inspecionado]
- `vendor/laravel/framework/src/Illuminate/Notifications/Console/NotificationTableCommand.php` — alias `notifications:table` confirmado [VERIFIED: linha 8]
- `app/Support/Permissions.php` — catálogo a estender [VERIFIED: codebase]
- `app/Models/User.php` — hasPermission / effectivePermissions / Notifiable trait [VERIFIED: codebase linhas 16, 100-140]
- `database/migrations/2026_05_20_200005_create_setor_lideres_table.php` — pivot setor_lideres [VERIFIED: codebase]
- `tests/Feature/FechamentoMigrationTest.php` — padrão para Test 1 [VERIFIED: codebase]
- `tests/Feature/AdminFechamentoControllerTest.php` — padrão de uso de UserFactory + factory state [VERIFIED: codebase]
- `phpunit.xml` — config SQLite in-memory + QUEUE_CONNECTION=sync [VERIFIED: codebase]
- `composer.json` — versões Laravel 12.x, PHPUnit 11.x [VERIFIED: codebase]
- `.planning/phases/08-funda-o-de-notifica-es/08-CONTEXT.md` — D-01 até D-11 [VERIFIED: arquivo de input]

### Secondary (MEDIUM confidence)

Nenhum — todas as fontes primárias foram suficientes.

### Tertiary (LOW confidence)

Nenhum.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — zero pacote novo; tudo verificado em `vendor/` instalado
- Architecture: HIGH — fluxo `Notification::send → DatabaseChannel → notifications table` inspecionado linha a linha no `vendor/`
- Pitfalls: HIGH — 6 armadilhas concretas, cada uma com warning sign verificável

**Research date:** 2026-05-21
**Valid until:** 2026-06-20 (30 dias — Laravel 12 é estável; mudança nos internals de Notifications improvável neste horizonte)

---

## RESEARCH COMPLETE

**Phase:** 8 - Fundação de Notificações
**Confidence:** HIGH

### Key Findings

- O stub canônico de migration `notifications` do Laravel 12 está disponível em `vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub` e usa `text('data')` (não `json`) — copiar literalmente, sem `php artisan` CLI.
- `DatabaseChannel::getData()` linhas 54–66 confirma: `toArray($notifiable)` é o fallback ativo quando não há `toDatabase()` — D-02 está alinhada com o framework sem modificações.
- PERM-02 e PERM-03 já têm cobertura no `User::hasPermission()` / `effectivePermissions()` — Phase 8 NÃO toca `app/Models/User.php`. Resolução é puramente via edição do catálogo `Permissions`.
- `QUEUE_CONNECTION=sync` em `phpunit.xml` (linha 31) garante que `Notification::send` no smoke test (Test 7) persiste imediatamente — sem `Notification::fake()`, com `assertDatabaseHas` real.
- 5 pitfalls críticos documentados (smoke test fake vs real, classe anônima e `get_class`, SQLite vs MySQL JSON, `AUTO_LIDERANCA` imutabilidade, timestamp da migration) — cada um com warning sign e mitigação concreta.

### File Created

`.planning/phases/08-funda-o-de-notifica-es/08-RESEARCH.md`

### Confidence Assessment

| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | Zero pacote novo; Laravel 12 e PHPUnit 11 confirmados em composer.json + vendor/ |
| Architecture | HIGH | DatabaseChannel + DatabaseNotification inspecionados linha a linha; flow Notification::send → DB row totalmente mapeado |
| Pitfalls | HIGH | 6 armadilhas concretas, cada uma com fonte (vendor file ou codebase line) e mitigação |
| Validation Architecture | HIGH | 7 testes especificados com asserts exatos; cobertura 5/5 Success Criteria + 3/3 REQ-IDs |

### Open Questions

Nenhuma. Toda área cinza foi resolvida por: (a) decisões travadas em CONTEXT.md (D-01..D-11), ou (b) leitura direta de `vendor/` e código existente.

### Ready for Planning

Research completa. O planner pode agora montar PLAN.md(s) sabendo:
- Exatamente quais 4 artifacts criar (migration + abstract class + enum + test file) e onde
- Exatamente quais 3 edições fazer em `app/Support/Permissions.php` e em quais linhas
- O contrato exato de `toArray()` que `DatabaseChannel` consome
- A estrutura dos 7 testes da suíte e o que cada um prova
- Os 6 pitfalls a evitar e seus warning signs verificáveis
