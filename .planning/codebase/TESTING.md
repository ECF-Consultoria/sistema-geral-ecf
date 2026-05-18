# Testing Patterns

**Analysis Date:** 2026-05-18

## Test Framework

**Runner:**
- PHPUnit 11.5.x
- Config: `phpunit.xml` (project root)

**Assertion Library:**
- PHPUnit built-in assertions + Laravel `TestCase` HTTP assertion helpers

**Run Commands:**
```bash
composer test              # Clears config cache, then runs php artisan test
php artisan test           # Run all tests (Feature + Unit)
php artisan test --filter ProfileTest   # Run specific test class
php artisan test tests/Feature          # Run only Feature suite
```

**JavaScript:**
- No JS testing framework configured (no jest, vitest, or Playwright config found)
- Frontend testing is not practiced in this codebase

## Test File Organization

**Location:**
- Tests are in a separate `tests/` directory, not co-located with source files

**Naming:**
- Test classes: `PascalCase` + `Test` suffix — `ProfileTest`, `ExampleTest`
- Test methods: `snake_case` prefixed with `test_` — `test_profile_page_is_displayed()`
- Namespace mirrors directory: `Tests\Feature`, `Tests\Unit`

**Structure:**
```
tests/
├── TestCase.php           # Base test case (extends Laravel's TestCase)
├── Unit/
│   └── ExampleTest.php    # Placeholder unit test
└── Feature/
    ├── ExampleTest.php    # Basic smoke test (GET / → 200)
    └── ProfileTest.php    # Auth profile CRUD tests
```

## Test Structure

**Suite Organization:**
```php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }
}
```

**Patterns:**
- Feature tests extend `Tests\TestCase` (which extends `Illuminate\Foundation\Testing\TestCase`)
- Unit tests extend `PHPUnit\Framework\TestCase` directly (no Laravel bootstrap)
- `RefreshDatabase` trait used in Feature tests that touch the database
- All test methods typed as `: void`
- `actingAs($user)` chained directly before the HTTP verb call

## Mocking

**Framework:** Mockery (`mockery/mockery ^1.6`) — available but not yet used in any test file

**Current State:**
- No mocking is practiced in the existing tests
- `ExampleTest.php` tests are scaffolded placeholders only
- `ProfileTest.php` uses real factory-created models against the in-memory SQLite test database

**What Would Be Mocked (recommended pattern):**
```php
// External HTTP calls (AdmanService)
Http::fake([
    'api.ad-man.io/*' => Http::response(['summarizedData' => [...]], 200),
]);

// Mocking a service in a controller test
$this->mock(AdmanService::class, function ($mock) {
    $mock->shouldReceive('syncCompany')->once()->andReturn(new AdmanMetric());
});
```

**What NOT to Mock:**
- Eloquent models — use factories against in-memory SQLite
- Laravel facades (Log, Cache, Session) — use built-in Laravel fake/spy helpers

## Fixtures and Factories

**Test Data:**
```php
// Only UserFactory exists — defined in database/factories/UserFactory.php
$user = User::factory()->create();

// State override
$user = User::factory()->unverified()->create();

// Inline attribute override
$user = User::factory()->create(['role' => 'admin']);
```

**Location:**
- Factories: `database/factories/` (only `UserFactory.php` exists)
- Seeders: `database/seeders/DatabaseSeeder.php` (creates single admin user via `firstOrCreate`)
- No factory definitions for Company, Sugador, AdmanMetric, or other domain models

## Coverage

**Requirements:** None enforced (no minimum threshold configured)

**Configuration:** `phpunit.xml` includes source coverage with `<include><directory>app</directory></include>` — enables coverage reports when Xdebug or PCOV is active

**View Coverage:**
```bash
php artisan test --coverage         # Terminal coverage summary
php artisan test --coverage-html coverage/   # HTML report
```

## Test Database

**Driver:** SQLite in-memory (`:memory:`)

**Configured in `phpunit.xml`:**
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

**Other test-specific env overrides:**
- `CACHE_STORE` → `array`
- `QUEUE_CONNECTION` → `sync` (jobs run synchronously in tests)
- `SESSION_DRIVER` → `array`
- `MAIL_MAILER` → `array`
- `BCRYPT_ROUNDS` → `4` (faster password hashing)

## Test Types

**Unit Tests (`tests/Unit/`):**
- Only placeholder `ExampleTest` exists (`assertTrue(true)`)
- Intended for pure PHP logic with no framework dependencies
- Would suit: `SugadorAnalysisService` detection logic, `AdmanService` data-mapping methods, model scope queries

**Feature/Integration Tests (`tests/Feature/`):**
- `ProfileTest.php` — full HTTP request lifecycle with database
- Tests auth flows, form submission, session, redirects
- Would suit: controller endpoints, middleware behavior, job handling (`ShouldQueue` sync mode)

**E2E Tests:**
- Not used — no Dusk, Playwright, or Cypress configured

## Coverage Gaps

The test suite is minimal relative to the codebase size. The following areas have zero coverage:

**Critical business logic (no tests):**
- `app/Services/SugadorAnalysisService.php` — detection criteria, threshold evaluation, idempotency (STATUS_TRAVADOS)
- `app/Services/AdmanService.php` — HTTP retry logic, rate-limit backoff, data mapping
- `app/Http/Controllers/MlbController.php` — all publication, sync, and KPI endpoints
- `app/Http/Controllers/SugadorController.php` — status update, Gate authorization, pagination filters
- `app/Http/Controllers/DashboardController.php` — admin/user dashboard data aggregation
- `app/Policies/SugadorPolicy.php` — access rules (viewAny, view, analyze, manage)

**No factories for domain models:**
- `Company`, `Sugador`, `AdmanMetric`, `AdmanCampaignMetric`, `Meeting`, `NpsSurvey`, `Ppa`, `Publicacao`
- Adding factories is a prerequisite for writing meaningful Feature tests for these modules

**Auth and middleware:**
- `EnsureUserHasRole` middleware — not tested
- `HandleInertiaRequests` middleware — shared props not tested

## Common Patterns (from existing tests)

**Authenticated HTTP test:**
```php
$user = User::factory()->create();

$response = $this
    ->actingAs($user)
    ->patch('/profile', ['name' => 'New Name', 'email' => 'x@x.com']);

$response
    ->assertSessionHasNoErrors()
    ->assertRedirect('/profile');

$this->assertSame('New Name', $user->fresh()->name);
```

**Unauthenticated guard test:**
```php
$this->assertGuest();
$this->assertNull($user->fresh());
```

**Validation error assertion:**
```php
$response->assertSessionHasErrors('password');
```

---

*Testing analysis: 2026-05-18*
