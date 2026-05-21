# Phase 8: Fundação de Notificações — Pattern Map

**Mapped:** 2026-05-21
**Files analyzed:** 5 (1 CREATE migration · 2 CREATE classes · 1 EDIT support · 1 CREATE test)
**Analogs found:** 5 / 5

> Fonte de input: `08-CONTEXT.md` (D-01..D-11) + `08-RESEARCH.md` (§Code Locations, §Existing Patterns to Reuse, §Pitfalls).
> Comentários nos snippets de código permanecem como estão nos arquivos originais (pt-BR — já é a convenção do projeto). Identificadores em inglês conforme CLAUDE.md §Naming Patterns.

## File Classification

| Arquivo (novo/editado) | Role | Data Flow | Analog mais próximo | Match Quality |
|------------------------|------|-----------|---------------------|---------------|
| `database/migrations/2026_05_21_100001_create_notifications_table.php` | migration | schema-create | **Composto:** `vendor/.../notifications.stub` (canônico) + `database/migrations/2026_05_20_200005_create_setor_lideres_table.php` (convenção pt-BR do projeto) | exact (stub) + exact (estilo) |
| `app/Notifications/BaseNotification.php` | model (abstract notification) | transform → channel-database | `app/Http/Controllers/Controller.php` (única `abstract class` em `app/`) + contrato direto de `\Illuminate\Notifications\Notification` (`vendor/.../Notification.php`) | partial — sem analog direto no app; primeira "Notification class" do codebase |
| `app/Notifications/Categoria.php` | enum (value object) | typed-constant | (nenhum enum em `app/`) → seguir Laravel/PHP 8.1+ convention + padrão de constantes de domínio existente em `app/Support/Permissions.php` | no-direct-analog (primeiro enum do projeto) |
| `app/Support/Permissions.php` (EDIT) | support catalog | constant-lookup | **O próprio arquivo** — linhas 63 (constante `SISTEMA_*`), 78–82 (`AUTO_LIDERANCA`), 126–135 (grupo `'Sistema'` em `catalog()`) | exact (auto-analog: 3 edições espelhando padrão interno) |
| `tests/Feature/Notifications/Phase8FoundationTest.php` | test (feature) | assert schema + assert facade | `tests/Feature/FechamentoMigrationTest.php` (Test 1 — schema asserts) + `tests/Feature/AdminFechamentoControllerTest.php` (Tests 5/6 — UserFactory + admin/líder setup) | exact (literal templates) |

---

## Pattern Assignments

### 1. `database/migrations/2026_05_21_100001_create_notifications_table.php` (migration, schema-create)

**Estratégia:** Combinar duas fontes — **schema literal do stub canônico Laravel 12** + **estilo pt-BR/PHPDoc das migrations existentes do projeto**. RESEARCH §Code Examples e Pitfall 3/4 travam o schema; os comentários e nomenclatura `up()/down()` seguem o estilo das migrations recentes do dev.

#### Analog A — Schema literal (Laravel 12 canonical stub)

**Source:** `vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub` linhas 1–31 (arquivo completo)

**Padrão a copiar literalmente (corpo do `Schema::create`):**
```php
// Source: vendor/laravel/framework/src/Illuminate/Notifications/Console/stubs/notifications.stub linhas 14-21
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');     // gera notifiable_type + notifiable_id + índice composto
    $table->text('data');             // NÃO usar json() — quebraria cast 'array' do model (Pitfall 3)
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
});
```

**Why this analog:** É o output exato que `php artisan notifications:table` geraria em Laravel 12. RESEARCH Pitfall 3 trava `text('data')` (não `json`) — cast `array` no `DatabaseNotification::$casts` (linhas 47–50) já trata serialização. Pitfall 4 trava `uuid('id')->primary()` — PK é gerada pelo dispatcher via `Str::uuid()`.

#### Analog B — Estilo do projeto (PHPDoc pt-BR, return new class)

**Source:** `database/migrations/2026_05_20_200005_create_setor_lideres_table.php` linhas 1–32 (estruturalmente idêntico) + `database/migrations/2026_05_18_100001_create_adman_sync_logs_table.php` (PHPDoc nos métodos)

**Imports + abertura padrão (literal das migrations do projeto):**
```php
// Source: database/migrations/2026_05_20_200005_create_setor_lideres_table.php linhas 1-13
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Docblock pt-BR de 1–3 linhas explicando o propósito da tabela]
 */
return new class extends Migration
{
    public function up(): void
    {
        // ...
    }
```

**Padrão dos PHPDocs nos métodos `up()/down()` (estilo Adman):**
```php
// Source: database/migrations/2026_05_18_100001_create_adman_sync_logs_table.php linhas 9-12, 29-31
/**
 * Executa a migration: cria tabela de logs de sincronização Adman.
 */
public function up(): void { ... }

/**
 * Reverte a migration.
 */
public function down(): void { ... }
```

**Padrão de `down()` (literal — Schema::dropIfExists):**
```php
// Source: database/migrations/2026_05_20_200005_create_setor_lideres_table.php linhas 28-31
public function down(): void
{
    Schema::dropIfExists('setor_lideres');   // ← trocar pelo nome da tabela
}
```

**Why analog B:** O projeto SEMPRE usa o padrão `return new class extends Migration` (anônima, Laravel 11+ style) com PHPDoc pt-BR no topo descrevendo o propósito de negócio da tabela. A migration nova deve combinar: corpo do `Schema::create` do stub canônico + envelope (imports, docblock pt-BR, métodos `up/down` documentados) do estilo da casa.

**Composição final esperada pelo executor:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela nativa do Laravel para o canal `database` de Notifications.
 * Polimórfica via notifiable_id/notifiable_type — qualquer modelo com trait
 * Notifiable persiste aqui. Phase 8 da fundação de Notificações (v3.0).
 */
return new class extends Migration
{
    /**
     * Executa a migration: cria tabela `notifications` no schema canônico Laravel 12.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');     // notifiable_id + notifiable_type + índice composto
            $table->text('data');             // cast 'array' no DatabaseNotification faz round-trip JSON
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverte a migration: dropa a tabela.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

---

### 2. `app/Notifications/BaseNotification.php` (abstract, transform → channel-database)

**Status do analog:** **Sem analog direto.** A única `abstract class` em `app/` é `app/Http/Controllers/Controller.php`, que é trivial (`abstract class Controller { // }`) — serve apenas como confirmação de que **`abstract` é uma palavra-chave já usada no codebase**, sem padrão estrutural a copiar.

**Estratégia:** Implementar contra o contrato direto da framework — `Illuminate\Notifications\Notification` (vendor source) — e seguir o padrão pt-BR de PHPDoc + naming PascalCase em `app/` (CLAUDE.md §Conventions). RESEARCH §Pattern 1 e D-02 dão o construtor literal a usar.

#### Analog A — Classe base do framework a estender

**Source:** `vendor/laravel/framework/src/Illuminate/Notifications/Notification.php` linhas 1–47 (arquivo completo, 47 linhas)

```php
// Source: vendor/laravel/framework/src/Illuminate/Notifications/Notification.php linhas 1-9
namespace Illuminate\Notifications;

use Illuminate\Queue\SerializesModels;

class Notification
{
    use SerializesModels;
    // ... id, locale, broadcastOn() — nada que precisamos sobrescrever
}
```

**Why this analog:** D-01 trava `extends Illuminate\Notifications\Notification`. A classe base é simples (`SerializesModels` trait + 3 props/métodos) — não precisa override de nada além de `via()` (D-01) e `toArray()` (D-02).

#### Analog B — Contrato do DatabaseChannel (o consumidor)

**Source:** `vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php` linhas 54–67

```php
// Source: vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php linhas 54-67
protected function getData($notifiable, Notification $notification)
{
    if (method_exists($notification, 'toDatabase')) {
        return is_array($data = $notification->toDatabase($notifiable))
            ? $data
            : $data->data;
    }

    if (method_exists($notification, 'toArray')) {
        return $notification->toArray($notifiable);   // ← caminho ativo para BaseNotification
    }

    throw new RuntimeException('Notification is missing toDatabase / toArray method.');
}
```

**Implicação:** Implementar APENAS `toArray()` — `toDatabase()` é opcional e D-02 escolhe `toArray()` como single source. Não criar ambos (geraria divergência de payload).

#### Analog C — Construtor promovido (PHP 8) usado no projeto

**Source:** Constructor property promotion já é usado em código moderno do projeto (CLAUDE.md §Code Style menciona "PHP 8.2+ features used: readonly properties, ... named arguments"). O analog mais próximo de **constructor promotion** é qualquer Service injetado via `__construct(private SomeService $svc)`, ex.:

**Source padrão de constructor promotion observado em `SugadorController` (CLAUDE.md §Function Design):**
```php
// Padrão referenciado em CLAUDE.md §Function Design (linhas literais variam por arquivo)
public function __construct(private SugadorAnalysisService $service) {}
```

**Padrão D-02 a aplicar literal (do CONTEXT.md/RESEARCH §Pattern 1):**
```php
// Source: 08-CONTEXT.md D-02 + 08-RESEARCH.md §Pattern 1 linhas 215-222
public function __construct(
    public string $titulo,
    public string $mensagem,
    public Categoria $categoria,
    public ?int $autorUserId = null,
    public ?string $url = null,
    public array $meta = [],
) {}
```

**Why this match:** Não há analog estrutural de "Notification class no app" — Phase 8 cria a primeira. O construtor promovido segue PHP 8 idioms que o projeto já adota, e os tipos (`string`, `Categoria`, `?int`, `?string`, `array`) são travados por D-02.

#### Padrão de PHPDoc + comentário pt-BR (CLAUDE.md §Comments)

**Source:** `app/Support/Permissions.php` linhas 5–18 (docblock de classe explicando responsabilidade do módulo) — o mesmo formato deve ser aplicado.

```php
// Source: app/Support/Permissions.php linhas 5-18 (formato do docblock de classe)
/**
 * Catálogo canônico de permission keys do sistema.
 *
 * Cada key representa uma "tela/funcionalidade" que pode ser concedida a um setor.
 * A lista é INTENCIONALMENTE estática (não no banco) porque adicionar uma key nova
 * exige código novo (rota, controller, frontend) — banco seria fonte falsa de verdade.
 *
 * Grupos (prefixos):
 *   ...
 */
class Permissions
```

**Adaptação esperada para BaseNotification:** Docblock pt-BR de 2–4 linhas explicando que é a abstrata canônica, que `via()` é fixo em `database`, que `toArray()` garante 6 chaves sempre. Comentários inline em pt-BR (CLAUDE.md trava idioma).

**Composição final esperada pelo executor (esqueleto, identificadores em inglês conforme CLAUDE.md):**
```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Classe abstrata base de todas as Notifications do MVP v3.0.
 *
 * Trava `via()` em `['database']` (MVP não tem mail/broadcast — v3.0 Out of Scope)
 * e expõe um payload canônico de 6 chaves em `toArray()` que o DatabaseChannel
 * consome direto. Subclasses concretas saem nas Phases 11 (auto) e 12 (manual).
 */
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

    /**
     * Payload canônico — 6 chaves SEMPRE presentes (meta defaulta a [], nunca null).
     * Consumido por DatabaseChannel::getData() (Laravel 12 vendor linhas 54-67).
     *
     * @return array{titulo:string,mensagem:string,categoria:string,autor_user_id:?int,url:?string,meta:array}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'titulo'        => $this->titulo,
            'mensagem'      => $this->mensagem,
            'categoria'     => $this->categoria->value,
            'autor_user_id' => $this->autorUserId,
            'url'           => $this->url,
            'meta'          => $this->meta,
        ];
    }
}
```

---

### 3. `app/Notifications/Categoria.php` (enum, typed-constant)

**Status do analog:** **Primeiro enum do projeto.** Grep em `app/` por `^enum\s+\w+` retornou 0 matches. O projeto representa "tipagem de valores fixos" hoje via **constantes públicas em PascalCase** (ver `app/Support/Permissions.php` ou as constantes `STATUS_*` em `app/Models/Sugador.php` mencionadas em CLAUDE.md §Conventions). RESEARCH D-03 trava enum backed (não const) para garantir round-trip `Categoria::from($string)` na Phase 9.

**Estratégia:** Aplicar idiom PHP 8.1+ backed enum, com PHPDoc de classe explicando "por que enum e não const" (segue o tom do docblock de `Permissions::class` linhas 5–18). Comentário pt-BR; identificadores PascalCase para a classe e SCREAMING_SNAKE_CASE para cases (CLAUDE.md §Naming Patterns trava SCREAMING_SNAKE para constantes — cases de enum se equiparam semanticamente).

#### Analog A — Padrão de "lista estática justificada em código" (mesmo princípio do enum)

**Source:** `app/Support/Permissions.php` linhas 5–11 (docblock que justifica catálogo estático)

```php
// Source: app/Support/Permissions.php linhas 5-11
/**
 * Catálogo canônico de permission keys do sistema.
 *
 * Cada key representa uma "tela/funcionalidade" que pode ser concedida a um setor.
 * A lista é INTENCIONALMENTE estática (não no banco) porque adicionar uma key nova
 * exige código novo (rota, controller, frontend) — banco seria fonte falsa de verdade.
 */
```

**Why this analog:** O argumento "lista estática porque adicionar exige código novo" é literalmente o mesmo que CONTEXT D-03 usa para justificar o enum (`Adicionar categoria nova exige código (mesmo princípio do catálogo Permissions estático).`). O docblock do enum deve ecoar esse raciocínio.

#### Analog B — Sintaxe PHP 8.1+ backed enum (RESEARCH §Pattern 2 + idiom Laravel/PHP padrão)

**Source:** `08-RESEARCH.md` §Pattern 2 linhas 252–266 + idiom PHP padrão (não há analog no codebase do projeto).

```php
// Source: 08-RESEARCH.md §Pattern 2 (idiom canônico PHP 8.1+)
namespace App\Notifications;

enum Categoria: string
{
    case META_ATRIBUIDA = 'meta_atribuida';
    case META_ATINGIDA  = 'meta_atingida';
    case MANUAL         = 'manual';
}
```

**Why `string`-backed:** D-03 trava. `BaseNotification::toArray()` faz `$this->categoria->value` para persistir (Pattern 1 linha 236) — exige `: string`, não `int`-backed. Phase 9 vai chamar `Categoria::from($data['categoria'])` para hidratar.

#### Padrão de comentário pt-BR por caso (estilo `Permissions.php` linhas 21–22)

**Source:** `app/Support/Permissions.php` linhas 21–22 (PHPDoc curto em pt-BR sobre cada constante)
```php
/** Acesso ao Dashboard principal ECF. */
public const CORE_DASHBOARD            = 'core.dashboard';
```

**Adaptação esperada para enum:** Cada `case` pode (opcional, mas alinha com o padrão da casa) receber um docblock de uma linha em pt-BR descrevendo quando é disparada. Ex.: `/** Disparada quando um líder atribui uma meta a um membro do setor (Phase 11). */`.

**Pitfall a evitar:** D-04 trava que **Phase 8 não cria subclasses concretas** — o enum tem 3 cases mas o codebase deste momento só tem `BaseNotification` abstrata. As classes `MetaAtribuidaNotification`, `MetaAtingidaNotification`, `ManualNotification` saem nas Phases 11/12.

---

### 4. `app/Support/Permissions.php` (EDIT — 3 edições cirúrgicas)

**Analog literal:** o próprio arquivo. As 3 edições espelham padrões internos já presentes nas linhas indicadas. **Não é refactor — é extensão homogênea.**

#### Edição 1: Constante nova (após `SISTEMA_SETORES`, antes da seção `LIDERANCA_*`)

**Analog interno (linha 63 — padrão exato a replicar):**
```php
// Source: app/Support/Permissions.php linha 63
public const SISTEMA_ACTIVITY_LOG      = 'sistema.activity_log';
```

**Outro exemplo do mesmo padrão (linhas 21–22) — PHPDoc de uma linha em pt-BR + const PascalCase com `=` alinhado:**
```php
// Source: app/Support/Permissions.php linhas 21-22
/** Acesso ao Dashboard principal ECF. */
public const CORE_DASHBOARD            = 'core.dashboard';
```

**Padrão a aplicar (a inserir após a linha 65 `SISTEMA_SETORES`):**
```php
/** Criar notificações manuais (admin sempre tem; líderes ganham via AUTO_LIDERANCA). */
public const NOTIFICACOES_CRIAR        = 'notificacoes.criar';
```

**Why this analog:** A linha 63 (`SISTEMA_ACTIVITY_LOG`) tem o **mesmo perfil semântico** da nova constante — uma feature "área interna do sistema" com chave dotted prefix. O alinhamento de `=` com espaços (CLAUDE.md §Code Style menciona "Alignment-style formatting for multi-key assignments") deve ser preservado: olhar para a coluna onde os `=` das vizinhas se alinham e usar o mesmo número de espaços.

#### Edição 2: Adicionar à constante array `AUTO_LIDERANCA`

**Analog interno literal (linhas 78–82 — estado atual do array):**
```php
// Source: app/Support/Permissions.php linhas 78-82
public const AUTO_LIDERANCA = [
    self::LIDERANCA_DASHBOARD_SETOR,
    self::LIDERANCA_DEFINIR_METAS,
    self::LIDERANCA_VER_MEMBROS,
];
```

**Padrão a aplicar (D-09 — adicionar 1 entrada antes do `]`):**
```php
public const AUTO_LIDERANCA = [
    self::LIDERANCA_DASHBOARD_SETOR,
    self::LIDERANCA_DEFINIR_METAS,
    self::LIDERANCA_VER_MEMBROS,
    self::NOTIFICACOES_CRIAR,            // ← linha nova
];
```

**Why this analog:** O array já existe — só receber +1 entrada. Trailing comma já é o padrão observado (linha 81 termina com `,`). Pitfall 5 trava: editar o **arquivo**, não tentar mutar em runtime.

#### Edição 3: Inserir grupo `'Notificações'` em `catalog()` entre Sistema e Liderança

**Analog interno literal (linhas 126–130 — grupo `'Sistema'`):**
```php
// Source: app/Support/Permissions.php linhas 126-130
'Sistema' => [
    ['key' => self::SISTEMA_ACTIVITY_LOG,    'label' => 'Activity Log',      'description' => 'Log de ações dos usuários'],
    ['key' => self::SISTEMA_DESENVOLVIMENTO, 'label' => 'Desenvolvimento',   'description' => 'Área interna de devs'],
    ['key' => self::SISTEMA_SETORES,         'label' => 'Setores',           'description' => 'Configuração de setores, cargos e permissões'],
],
```

**Posicionamento (entre Sistema linha 130 e Liderança linha 131):**
```php
// Source: app/Support/Permissions.php linhas 130-131 (ponto de inserção)
            ],
            // ← inserir AQUI o novo grupo 'Notificações'
            'Liderança (automático para líderes)' => [
```

**Padrão a aplicar (D-08 — grupo com 1 entrada):**
```php
'Notificações' => [
    ['key' => self::NOTIFICACOES_CRIAR, 'label' => 'Criar notificações', 'description' => 'Envia notificações manuais para usuários, setores, líderes ou todos'],
],
```

**Why this analog:** O grupo `'Sistema'` (linhas 126–130) é o exemplo mais próximo estruturalmente — mesma forma `'Nome do Grupo' => [ ['key' => ..., 'label' => ..., 'description' => ...] ]`. Trailing comma após o `]` final do grupo, alinhamento de `'label' =>` por espaços (cosmético — pode usar 1 espaço já que o grupo tem só 1 entrada, sem necessidade de coluna alinhada).

**Validação implícita pós-edição:** `Permissions::all()` (linhas 144–153) varre `catalog()` e coleta keys — a chave nova aparece automaticamente sem mudança em `all()` ou `isValid()`. Test 2 prova isso.

---

### 5. `tests/Feature/Notifications/Phase8FoundationTest.php` (test feature, schema + facade asserts)

**Estratégia:** **Dois analogs literais** cobrem todos os 7 testes da suíte.

#### Analog A — `tests/Feature/FechamentoMigrationTest.php` (template para Test 1)

**Source:** `tests/Feature/FechamentoMigrationTest.php` linhas 1–20 (arquivo completo, 20 linhas)

```php
// Source: tests/Feature/FechamentoMigrationTest.php linhas 1-20 (arquivo completo)
<?php

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

**Why this analog (Test 1):** É **literal** para o Test 1 da suíte (`test_migration_cria_tabela_notifications`). RESEARCH §Test 1 linhas 580–589 e Validation Architecture confirmam: usar `Schema::hasTable('notifications')` + 8x `Schema::hasColumn(...)` no mesmo padrão das 4 linhas de assert acima. `RefreshDatabase` + namespace `Tests\Feature\Notifications` (subnamespace por causa do diretório novo) + `use Tests\TestCase`.

**Adaptações para Phase 8:**
- `namespace Tests\Feature\Notifications;` (subdiretório)
- Imports adicionais: `use App\Models\User;`, `use App\Models\Setor;`, `use App\Notifications\BaseNotification;`, `use App\Notifications\Categoria;`, `use App\Support\Permissions;`, `use Illuminate\Notifications\DatabaseNotification;`, `use Illuminate\Support\Facades\Notification;`
- 7 métodos de teste (Tests 1–7), todos com prefix `test_` e snake_case (mesmo estilo do exemplo).

#### Analog B — `tests/Feature/AdminFechamentoControllerTest.php` (template para Tests 5 e 6)

**Source:** `tests/Feature/AdminFechamentoControllerTest.php` linhas 1–34 (cabeçalho + helper + 1º teste)

**Padrão de imports + RefreshDatabase + helper `criarAdmin` (linhas 1–19):**
```php
// Source: tests/Feature/AdminFechamentoControllerTest.php linhas 1-19
<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFechamentoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
```

**Why this analog (Test 5):** Para `test_admin_tem_permissao_via_short_circuit` o setup é **literal igual** — `User::factory()->create(['role' => 'admin'])`. Pode usar o helper `criarAdmin()` ou inline (RESEARCH §Validation Architecture Test 5 usa inline). Não precisa criar empresa nem rota — é assertion direta em método de model.

**Padrão de teste com user não-admin (linha 124, do mesmo arquivo):**
```php
// Source: tests/Feature/AdminFechamentoControllerTest.php linhas 122-129
public function test_nao_admin_recebe_403(): void
{
    $consultor = User::factory()->create(['role' => 'consultor']);

    $response = $this->actingAs($consultor)->get('/administrativo/financeiro');

    $response->assertStatus(403);
}
```

**Why this analog (Test 6):** Para `test_lider_tem_permissao_via_auto_lideranca` — Test 6 usa `User::factory()->create(['role' => 'consultor'])` (explicitamente NÃO admin) e atribui setor liderado. O snippet acima mostra o idiom de "criar non-admin via factory" usado no projeto. A diferença é que Test 6 também faz `setoresLiderados()->attach(...)` (vide RESEARCH §Existing Patterns to Reuse linhas 493–504).

#### Padrão de UserFactory (já disponível, sem mudança)

**Source:** `database/factories/UserFactory.php` linhas 25–34 (verificado)

```php
// Source: database/factories/UserFactory.php linhas 25-34
public function definition(): array
{
    return [
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'email_verified_at' => now(),
        'password' => static::$password ??= Hash::make('password'),
        'remember_token' => Str::random(10),
    ];
}
```

**Implicação:** `User::factory()->create(['role' => 'admin'|'consultor'])` já preenche todos os campos NOT NULL — não precisa passar nada além de `role` quando importar. `role` é fillable (User.php linha 33).

#### Padrão de criar `Setor` + attach como líder (Test 6)

**Source:** `08-RESEARCH.md` §Existing Patterns to Reuse linhas 491–505 (verificado contra `app/Models/User.php` linhas 75–80 + `database/migrations/2026_05_20_200005_create_setor_lideres_table.php` linhas 17–24)

```php
// Source: 08-RESEARCH.md §Existing Patterns to Reuse linhas 493-503
$setor = \App\Models\Setor::create([
    'nome'   => 'Setor de Teste',
    'slug'   => 'setor-de-teste',           // booted() do Setor gera automático se vazio
    'active' => true,
]);
$user = User::factory()->create(['role' => 'consultor']);   // não-admin
$user->setoresLiderados()->attach($setor->id, [
    'assigned_by' => null,
    'assigned_at' => now(),
]);
// Agora $user->isLider() === true e hasPermission('notificacoes.criar') deve ser true
```

**Why this analog:** As colunas `assigned_by` (nullable, FK) e `assigned_at` (timestamp) são exatamente as do pivot `setor_lideres` (migration linhas 19–20). `setoresLiderados()` relation (User.php linhas 75–80) faz `withPivot('assigned_by', 'assigned_at')->withTimestamps()` — passar essas duas chaves explicitamente cobre todos os campos NOT NULL do pivot. Após o attach, chamar `$user->refresh()` para invalidar `effectivePermissionsCache` (User.php linha 57, RESEARCH Test 6).

#### Padrão de smoke test com classe anônima (Test 7) — sem analog no codebase

**Status:** O codebase atual **não tem nenhum teste que usa `Notification::send` real** (Phase 8 cria o primeiro). RESEARCH §Pitfall 1 trava o anti-pattern `Notification::fake()`; §Pitfall 2 trava o uso de `get_class()` sobre `class@anonymous`. RESEARCH §Validation Architecture Test 7 linhas 638–665 dá o template **literal**:

```php
// Source: 08-RESEARCH.md §Validation Architecture Test 7 (template literal a replicar)
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
// NÃO usar Notification::fake() — queremos persistência real (Pitfall 1)

$this->assertDatabaseCount('notifications', 1);
$row = \Illuminate\Notifications\DatabaseNotification::query()->first();
$this->assertSame($user->id,                (int) $row->notifiable_id);
$this->assertSame(\App\Models\User::class,  $row->notifiable_type);
$this->assertNull($row->read_at);

// data é castado como 'array' pelo DatabaseNotification — vem deserializado
$this->assertSame('Título teste',     $row->data['titulo']);
$this->assertSame('Mensagem teste',   $row->data['mensagem']);
$this->assertSame('manual',           $row->data['categoria']);   // enum->value persistido
$this->assertNull($row->data['autor_user_id']);
$this->assertSame('/notificacoes',    $row->data['url']);
$this->assertSame(['ref' => 'phase-8-smoke'], $row->data['meta']);
```

**Why this template (no codebase analog):** Test 7 é o primeiro teste do projeto a exercer o flow `Notification::send → DatabaseChannel → notifications row`. O template segue named arguments (PHP 8 idiom já usado no projeto — CLAUDE.md §Code Style "named arguments"), classe anônima (`new class(...) extends BaseNotification {};`) para não criar arquivo separado (D-04 trava), e `assertDatabaseCount` + leitura via Eloquent (não raw JSON — Pitfall 3 trava).

**Setup phpunit garantido (não-acionável — apenas verificar):**
- `phpunit.xml` linha 31 já tem `QUEUE_CONNECTION=sync` → `Notification::send` executa imediatamente (vendor `DatabaseChannel::send` linha 19).
- `phpunit.xml` linhas 27–28 já têm `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` → `RefreshDatabase` migra tudo em memória.

---

## Shared Patterns

Padrões cross-cutting que aplicam a múltiplos arquivos da Phase 8.

### Idioma de comentários e PHPDoc: pt-BR

**Source:** CLAUDE.md §Conventions §Comments + `app/Support/Permissions.php` (exemplo literal) + todas as migrations existentes.

**Aplica a:** todos os 5 arquivos da fase (migration, BaseNotification, Categoria, edit em Permissions, test).

**Padrão literal:**
```php
// Source: app/Support/Permissions.php linhas 21-22 (cosmético do projeto)
/** Acesso ao Dashboard principal ECF. */
public const CORE_DASHBOARD            = 'core.dashboard';
```

CLAUDE.md trava: "All comments written in **Portuguese (pt-BR)** — consistent with the business domain". Aplicar em (1) docblock de classe/enum/migration, (2) docblock dos métodos públicos, (3) comentários inline em regras de negócio (ex.: explicar por que `via()` é fixo em database, por que `meta` defaulta a `[]` em vez de `null`).

### Naming Pattern

**Source:** CLAUDE.md §Naming Patterns (verificado contra todos os arquivos lidos).

**Aplica a:** BaseNotification, Categoria, migration, test, edit em Permissions.

| Elemento | Convenção | Exemplo desta fase |
|----------|-----------|--------------------|
| Class | PascalCase | `BaseNotification`, `Categoria`, `Phase8FoundationTest` |
| Method | camelCase | `via()`, `toArray()` |
| Constant | SCREAMING_SNAKE_CASE | `NOTIFICACOES_CRIAR`, `META_ATRIBUIDA`, `META_ATINGIDA`, `MANUAL` |
| DB column | snake_case (já vem do stub) | `read_at`, `notifiable_id`, `notifiable_type` |
| Test method | `test_` + snake_case | `test_migration_cria_tabela_notifications` |
| Migration file | `YYYY_MM_DD_HHMMSS_verb_noun_table.php` | `2026_05_21_100001_create_notifications_table.php` |

### `RefreshDatabase` + `Tests\TestCase` + namespaced subdirectory

**Source:** Todos os 2 testes analogs (`FechamentoMigrationTest`, `AdminFechamentoControllerTest`) + `composer.json` PSR-4 autoload (`Tests\\` → `tests/`).

**Aplica a:** Test file (Phase8FoundationTest).

```php
// Padrão fixo de qualquer Feature test do projeto
namespace Tests\Feature\Notifications;   // ← subdiretório novo (Phase 8 cria)

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase8FoundationTest extends TestCase
{
    use RefreshDatabase;
    // ...
}
```

### Padrão de "migration anônima Laravel 11+" (return new class)

**Source:** Todas as migrations recentes do projeto (`2026_05_18_*`, `2026_05_20_*`).

**Aplica a:** Migration nova.

```php
return new class extends Migration { ... };
```

Nunca `class CreateNotificationsTable extends Migration { ... }` (estilo Laravel 5–8) — o projeto usa exclusivamente o estilo anônimo de Laravel 11+.

### Padrão "não tocar `User::hasPermission` nem `User::effectivePermissions`"

**Source:** `app/Models/User.php` linhas 100–140 + RESEARCH §Existing Patterns to Reuse linhas 729–757.

**Aplica a:** Edição em Permissions (impacto indireto).

A edição de `app/Support/Permissions.php` **resolve PERM-02 e PERM-03 sem código novo em User** — o short-circuit admin (User.php linha 106 `if ($this->isAdmin()) return true;`) e o merge `array_merge(..., Permissions::AUTO_LIDERANCA)` (User.php linhas 135–137) já estão lá. Esta é a razão de Phase 8 ser tão pequena.

**Anti-pattern explícito:** Não criar `User::hasNotificationPermission()` ou similar — usa o `hasPermission('notificacoes.criar')` existente.

---

## No Analog Found

Arquivos sem match estrutural direto no codebase do projeto (executor deve seguir RESEARCH.md + spec direto da framework):

| Arquivo | Role | Data Flow | Reason | Compensação |
|---------|------|-----------|--------|-------------|
| `app/Notifications/BaseNotification.php` | abstract notification | transform → channel-database | Primeira Notification class do projeto. `Controller.php` (única `abstract` em `app/`) é trivial. | Contrato direto do `\Illuminate\Notifications\Notification` (vendor) + RESEARCH §Pattern 1 + D-02 + estilo PHPDoc pt-BR de `Permissions.php` |
| `app/Notifications/Categoria.php` | enum (PHP 8.1 backed) | typed-constant | Primeiro enum do projeto (grep `^enum\s+\w+` em `app/` retorna 0). | Idiom canônico PHP 8.1+ + docblock pt-BR estilo `Permissions::class` justificando lista estática (mesma justificativa que `Permissions` usa internamente) |
| Smoke test Test 7 (`test_base_notification_persiste_payload_canonico`) | test (Notification::send + assert DB) | facade → channel → row | Primeiro teste do projeto exercendo `Notification::send` real. | Template literal em RESEARCH §Validation Architecture Test 7 (já testado pelo researcher contra vendor source) + 2 pitfalls travados (não-fake, evitar `get_class` instável) |

**Riscos mitigados:** Cada item no-analog tem (a) contrato vendor verificado pelo researcher, (b) decisão travada em CONTEXT, (c) pitfall documentado em RESEARCH. Nenhum item está em "área cinza".

---

## Metadata

**Analog search scope:**
- `app/` (todos os arquivos PHP — Grep por `^abstract class`, `^enum`, `public function __construct\(\s*public`)
- `database/migrations/` (migrations recentes pra estilo `return new class extends Migration`)
- `tests/Feature/` (todos os tests — leitura de `FechamentoMigrationTest.php` e `AdminFechamentoControllerTest.php`)
- `vendor/laravel/framework/src/Illuminate/Notifications/` (stub canônico de migration, `Notification.php`, `Channels/DatabaseChannel.php`)
- `app/Support/Permissions.php` (auto-analog — edit em si mesmo)

**Files scanned:** 8 (Permissions.php, Controller.php, User.php cabeçalho, UserFactory.php, 2 migrations, 2 testes, 3 vendor sources).

**Pattern extraction date:** 2026-05-21.

**Confidence breakdown:**
- Migration: HIGH (stub canônico + 2 analogs estilísticos do projeto verificados)
- BaseNotification: HIGH-MEDIUM (contrato vendor verificado; sem analog estrutural mas decisão D-02 trava o construtor literal)
- Categoria: HIGH-MEDIUM (idiom PHP padrão; primeiro enum mas docblock e naming espelham `Permissions`)
- Permissions edit: HIGH (auto-analog literal; 3 edições cirúrgicas com linhas-fonte exatas)
- Test: HIGH (2 analogs literais — FechamentoMigrationTest para schema, AdminFechamentoControllerTest para UserFactory+admin/líder)

---

## PATTERN MAPPING COMPLETE

**Phase:** 08 — Fundação de Notificações
**Files classified:** 5
**Analogs found:** 5 / 5 (todos com fonte literal — 2 do vendor, 2 do código do projeto, 1 auto-analog)

### Coverage
- Files with exact analog: 3 (migration, edit em Permissions, test file)
- Files with role-match analog: 1 (BaseNotification — abstract concept + vendor contract)
- Files with no analog (primeiro do tipo no projeto): 1 (Categoria — primeiro enum) + 1 sub-pattern dentro do test (smoke Test 7)

### Key Patterns Identified
- **Migration anônima Laravel 11+** (`return new class extends Migration`) + **schema literal do stub canônico Laravel 12** (uuid PK, morphs, text data — não json) + **PHPDoc pt-BR no topo** explicando propósito de negócio (padrão das migrations `2026_05_*`).
- **Constructor property promotion** (PHP 8) com tipos explícitos para o construtor da `BaseNotification` (já é idiom usado no projeto em Services); `via()` fixo em `['database']`; `toArray()` retornando array com 6 chaves estáveis consumido por `DatabaseChannel::getData` fallback (vendor linhas 54–67).
- **Permission catalog extension homogênea** — 3 edições cirúrgicas (constante + array `AUTO_LIDERANCA` + entrada em `catalog()`), cada uma com analog literal interno no próprio `Permissions.php`. Nenhuma mudança em `User.php` (PERM-02/03 resolvidos por código já existente).
- **PHP 8.1 backed enum** como primeiro enum do projeto, justificado pelo mesmo raciocínio do catálogo `Permissions::all()` estático (lista canônica em código, mudança exige commit).
- **Test feature pattern**: `RefreshDatabase` + `Tests\TestCase` + namespaced subdir + `User::factory()->create(['role' => ...])`; smoke test usa `Notification::send` real (NÃO `Notification::fake()`) com `QUEUE_CONNECTION=sync` já configurado em `phpunit.xml`.

### File Created
`C:\xampp\htdocs\ecf_admin\ecf_admin\.planning\phases\08-funda-o-de-notifica-es\08-PATTERNS.md`

### Ready for Planning
Mapeamento de padrões completo. O `gsd-planner` pode agora referenciar:
- 1 stub canônico vendor (migration) + 2 analogs estilísticos do projeto (migration)
- 1 contrato vendor (Notification base) + 1 contrato vendor (DatabaseChannel) + 1 padrão de PHPDoc do projeto (BaseNotification)
- 1 idiom PHP padrão + 1 padrão de docblock do projeto (enum Categoria)
- 3 analogs internos literais com linhas-fonte exatas (edição em Permissions)
- 2 testes-template literais do projeto (Phase8FoundationTest)
