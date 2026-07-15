<?php

namespace Tests\Feature;

use App\Mail\NpsMonthlyMail;
use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Suite Feature — comando `nps:disparar-mensal` (Phase 31, Plan 02).
 *
 * Cobre os 6 cenários do <behavior> do plan:
 *  T1. happy path — empresa elegível recebe survey+email
 *  T2. sem email_cliente — silenciosamente pulada (D-04)
 *  T3. dia nao bate — nada acontece
 *  T4. idempotência — 2 runs no mesmo dia geram só 1 survey
 *  T5. edge case dia 31 → fevereiro (clamp via min(day, daysInMonth) — D-03)
 *  T6. empresa inativa — nada acontece
 *
 * Usa Mail::fake + Carbon::setTestNow + Company::create direto (não há
 * CompanyFactory no projeto).
 */
class Phase31NpsDispararMensalTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    private function criarEmpresa(array $overrides = []): Company
    {
        $data = array_merge([
            'name'          => 'Empresa Teste ' . uniqid(),
            'cnpj'          => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'        => true,
            'email_cliente' => 'cliente@empresa-teste.com',
        ], $overrides);

        // created_at/updated_at não estão no fillable; aplicar via forceFill após new
        // para garantir que o override do teste prevaleça (Eloquent senão usa now()).
        $createdAt = $data['created_at'] ?? null;
        $updatedAt = $data['updated_at'] ?? null;
        unset($data['created_at'], $data['updated_at']);

        $empresa = Company::create($data);

        if ($createdAt !== null || $updatedAt !== null) {
            $empresa->timestamps = false;
            $empresa->forceFill([
                'created_at' => $createdAt ?? $empresa->created_at,
                'updated_at' => $updatedAt ?? $empresa->updated_at,
            ])->save();
            $empresa->timestamps = true;
            $empresa->refresh();
        }

        return $empresa;
    }

    private function atribuirEstrategista(Company $empresa, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $empresa->users()->attach($user->id, ['role' => 'estrategista', 'assigned_at' => now()]);
        return $user;
    }

    private function atribuirAnalista(Company $empresa, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $empresa->users()->attach($user->id, ['role' => 'consultor', 'assigned_at' => now()]);
        return $user;
    }

    /**
     * T1 — Happy path: empresa ativa COM email_cliente E aniversário hoje → cria
     * 1 survey auto_generated=true e dispara 1 NpsMonthlyMail para email_cliente.
     */
    public function test_empresa_elegivel_recebe_survey_e_email(): void
    {
        Mail::fake();

        // Mock hoje = 2026-06-18
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'created_at' => '2025-04-18 10:00:00',
            'updated_at' => '2025-04-18 10:00:00',
        ]);
        $this->atribuirEstrategista($empresa);
        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        $this->contratarServicoNpsCoberto($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertSame($empresa->id, $survey->company_id);
        $this->assertTrue((bool) $survey->auto_generated);
        $this->assertSame('2026-06-01', $survey->month_reference->toDateString());
        $this->assertSame('pending', $survey->status);
        $this->assertNull($survey->generated_by);
        $this->assertNotEmpty($survey->token);
        // expires_at = hoje + 30 dias
        $this->assertSame('2026-07-18', $survey->expires_at->toDateString());

        Mail::assertSent(NpsMonthlyMail::class, function ($mail) use ($empresa) {
            return $mail->hasTo($empresa->email_cliente);
        });
    }

    /**
     * T2 — Empresa ativa, dia bate, MAS email_cliente é null → silenciosamente
     * pulada (D-04): nenhum survey, nenhum email.
     */
    public function test_empresa_sem_email_cliente_e_pulada_silenciosamente(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'email_cliente' => null,
            'created_at'    => '2025-04-18 10:00:00',
            'updated_at'    => '2025-04-18 10:00:00',
        ]);
        $this->atribuirEstrategista($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 0);
        Mail::assertNothingSent();
    }

    /**
     * T3 — Dia hoje != DAY(created_at) → nada acontece.
     */
    public function test_dia_nao_bate_nada_e_criado(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'created_at' => '2025-04-05 10:00:00',
            'updated_at' => '2025-04-05 10:00:00',
        ]);
        $this->atribuirEstrategista($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 0);
        Mail::assertNothingSent();
    }

    /**
     * T4 — Idempotência: 2 runs no mesmo dia produzem só 1 survey e 1 email.
     */
    public function test_idempotencia_dois_runs_no_mesmo_dia(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'created_at' => '2025-04-18 10:00:00',
            'updated_at' => '2025-04-18 10:00:00',
        ]);
        $this->atribuirEstrategista($empresa);
        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        $this->contratarServicoNpsCoberto($empresa);

        // Run 1
        $this->artisan('nps:disparar-mensal')->assertSuccessful();
        $this->assertDatabaseCount('nps_surveys', 1);
        Mail::assertSentCount(1);

        // Run 2 — mesmo dia
        $this->artisan('nps:disparar-mensal')->assertSuccessful();
        $this->assertDatabaseCount('nps_surveys', 1); // continua 1
        Mail::assertSentCount(1); // não foi enviado de novo
    }

    /**
     * T5 — Edge case D-03: empresa criada dia 31, hoje é 28/02 (não-bissexto).
     * Clamp via min(31, 28) = 28 → bate com hoje.day=28 → dispara.
     */
    public function test_edge_case_dia_31_dispara_no_ultimo_dia_de_fevereiro(): void
    {
        Mail::fake();
        // 2027 = não bissexto → fevereiro tem 28 dias
        Carbon::setTestNow(Carbon::create(2027, 2, 28, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'created_at' => '2026-01-31 10:00:00',
            'updated_at' => '2026-01-31 10:00:00',
        ]);
        $this->atribuirEstrategista($empresa);
        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        $this->contratarServicoNpsCoberto($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);
        Mail::assertSentCount(1);
    }

    /**
     * T6 — Empresa inativa, dia bate, tem email → nada criado (whereActive=true filtra).
     */
    public function test_empresa_inativa_e_ignorada(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'active'     => false,
            'created_at' => '2025-04-18 10:00:00',
            'updated_at' => '2025-04-18 10:00:00',
        ]);
        $this->atribuirEstrategista($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 0);
        Mail::assertNothingSent();
    }

    /**
     * T7 — Empresa com analista atribuído: NpsMonthlyMail recebe vars com
     * `corpoRender` contendo o nome do analista (Phase 32 — assinatura mudou
     * para array $vars com os textos JÁ renderizados, então a verificação
     * passa a ser por substring no corpoRender em vez de propriedade
     * estática `analistaName`).
     */
    public function test_empresa_com_analista_passa_nome_no_mailable(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresa([
            'created_at' => '2025-04-18 10:00:00',
            'updated_at' => '2025-04-18 10:00:00',
        ]);
        $estrategista = User::factory()->create(['name' => 'Nathalia']);
        $analista     = User::factory()->create(['name' => 'Bruno']);
        $this->atribuirEstrategista($empresa, $estrategista);
        $this->atribuirAnalista($empresa, $analista);
        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        $this->contratarServicoNpsCoberto($empresa);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        Mail::assertSent(NpsMonthlyMail::class, function ($mail) {
            return str_contains($mail->vars['corpoRender'], 'Nathalia')
                && str_contains($mail->vars['corpoRender'], 'Bruno');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // limpa mock
        parent::tearDown();
    }
}
