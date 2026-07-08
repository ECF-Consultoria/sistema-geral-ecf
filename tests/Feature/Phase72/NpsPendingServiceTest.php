<?php

namespace Tests\Feature\Phase72;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\User;
use App\Services\Nps\NpsPendingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 72 Plan 04 T1 — Suite Feature RED do NpsPendingService.
 *
 * Cobre SC #1 + SC #4 do ROADMAP Phase 72 + REQs NPS-E-01 e NPS-E-04:
 *  - diaCobranca(): default 25 + leitura Configuracao + clamp defensivo 1..31
 *  - isPendente(): guard temporal mes corrente (hoje >= diaCobranca) +
 *    reconhecimento de survey completed do mes
 *  - forCarteira(): escopo admin (todas as empresas) vs consultor
 *    (apenas pivot company_users) + shape completo do array retornado
 *
 * Setup implicito via RefreshDatabase:
 *  - Migration 100004 seeda "NPS Padrao" (is_default=true) — usado como
 *    fallback do NpsTemplateService::resolveForCompany
 *
 * Padrao herdado da Phase 71/69: Carbon::setTestNow para guard temporal +
 * tearDown limpando + PRAGMA foreign_keys.
 *
 * Referencias:
 *  - .planning/phases/72-dashboards-pendencias-dia-cobranca/72-01-PLAN.md
 *  - app/Services/Nps/NpsPendingService.php (contrato dos 3 metodos)
 *  - app/Models/Configuracao.php (key-value store)
 */
class NpsPendingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — padrao Phase 68/69/70/71.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Limpa Carbon::setTestNow entre testes — evita vazamento temporal
     * entre casos que fixam datas diferentes.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper: resolve service via container (DI-friendly, mesmo padrao dos
     * consumidores em produ��o).
     */
    private function service(): NpsPendingService
    {
        return $this->app->make(NpsPendingService::class);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 1 — diaCobranca(): default 25 quando sem config
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dia_cobranca_default_25_quando_sem_config(): void
    {
        // Sem row em configuracoes com chave nps_dia_cobranca, o service
        // deve retornar o default hardcoded 25 (Configuracao::get default).
        // Sanity check: garante que baseline nao tem a chave persistida.
        $this->assertNull(
            Configuracao::where('chave', 'nps_dia_cobranca')->value('valor'),
            'baseline: nps_dia_cobranca nao deveria estar persistida'
        );

        $this->assertSame(25, $this->service()->diaCobranca());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 2 — diaCobranca(): le config quando persistida
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dia_cobranca_le_config_quando_persistido(): void
    {
        // Persiste valor customizado (10) e verifica que o service reflete.
        // Cast (int) e responsabilidade do service — o store guarda string.
        Configuracao::set('nps_dia_cobranca', 10);

        $this->assertSame(10, $this->service()->diaCobranca());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 3 — diaCobranca(): clamp 1..31 quando valor corrompido no banco
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dia_cobranca_clamp_1_31_quando_valor_corrompido(): void
    {
        // Defesa em profundidade contra edicao manual no banco: valores fora
        // do range 1..31 sao normalizados ao limite mais proximo, garantindo
        // que a UI nunca crash ou entre em estado invalido.

        // Underflow → clamp para 1
        Configuracao::set('nps_dia_cobranca', 0);
        $this->assertSame(1, $this->service()->diaCobranca(),
            'valor 0 corrompido deveria ser clampado para 1');

        // Overflow → clamp para 31
        Configuracao::set('nps_dia_cobranca', 99);
        $this->assertSame(31, $this->service()->diaCobranca(),
            'valor 99 corrompido deveria ser clampado para 31');

        // Negativo → clamp para 1
        Configuracao::set('nps_dia_cobranca', -5);
        $this->assertSame(1, $this->service()->diaCobranca(),
            'valor negativo corrompido deveria ser clampado para 1');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 4 — isPendente(): false no mes corrente antes do diaCobranca
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_isPendente_false_no_mes_corrente_antes_do_dia_cobranca(): void
    {
        // Guard temporal: hoje (dia 10) < diaCobranca (25) => nao e pendente
        // no mes corrente, independente da existencia de survey. E o "aguardar
        // o dia da cobranca" — janela de tolerancia antes de acionar o widget.
        Configuracao::set('nps_dia_cobranca', 25);
        Carbon::setTestNow('2026-07-10 12:00:00');

        // Seed template default ja existe via migration 100004; garantimos.
        $this->assertNotNull(
            NpsTemplate::where('is_default', true)->first(),
            'seed NPS Padrao (migration 100004) deveria existir'
        );

        $company = Company::factory()->create();

        $this->assertFalse(
            $this->service()->isPendente($company),
            'mes corrente com dia<diaCobranca deveria retornar false'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 5 — isPendente(): true no mes corrente APOS diaCobranca sem completed
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_isPendente_true_no_mes_corrente_depois_do_dia_cobranca_sem_survey_completo(): void
    {
        // Cenario canonico: dia 26 > diaCobranca (25) + zero surveys completed
        // no mes => pendente. Este e o disparador principal do widget do
        // dashboard (Plan 72-02) e do badge de Portfolio/Companies (Plan 72-03).
        Configuracao::set('nps_dia_cobranca', 25);
        Carbon::setTestNow('2026-07-26 12:00:00');

        $company = Company::factory()->create();

        $this->assertTrue(
            $this->service()->isPendente($company),
            'mes corrente com dia>=diaCobranca sem survey completed deveria ser pendente'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 6 — isPendente(): false quando ha survey completed no mes
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_isPendente_false_quando_ha_survey_completed_no_mes(): void
    {
        // Survey completed no mes corrente (mesmo template resolvido pelo
        // NpsTemplateService) => NAO e pendente. Cobre o WHERE composto
        // (company_id + template_id + month_reference + status=completed).
        Configuracao::set('nps_dia_cobranca', 25);
        Carbon::setTestNow('2026-07-26 12:00:00');

        $company = Company::factory()->create();

        // Resolve o template default (seed migration 100004) para garantir
        // que o survey completed usa o MESMO template que o service resolve.
        $templateDefault = NpsTemplate::where('is_default', true)->firstOrFail();

        NpsSurvey::create([
            'token'           => (string) Str::uuid(),
            'company_id'      => $company->id,
            'template_id'     => $templateDefault->id,
            'status'          => 'completed',
            'month_reference' => Carbon::parse('2026-07-01')->startOfMonth()->toDateString(),
            'completed_at'    => Carbon::parse('2026-07-20 10:00:00'),
            'expires_at'      => Carbon::parse('2026-07-30'),
            'auto_generated'  => true,
        ]);

        $this->assertFalse(
            $this->service()->isPendente($company),
            'com survey completed no mes corrente, isPendente deveria retornar false'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 7 — forCarteira(admin): retorna todas empresas pendentes + shape completo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_forCarteira_admin_retorna_todas_empresas_pendentes(): void
    {
        // Admin ve todas as companies do sistema. 3 empresas criadas, todas
        // sem survey completed no mes corrente + hoje > diaCobranca => todas
        // pendentes. Shape do array retornado por forCarteira DEVE incluir
        // as 6 chaves documentadas (company_id, name, template_id,
        // template_nome, month_reference, dias_atraso) — contrato dos
        // consumidores Plans 72-02 e 72-03.
        Configuracao::set('nps_dia_cobranca', 25);
        Carbon::setTestNow('2026-07-28 09:00:00');

        $admin = User::factory()->create(['role' => 'admin']);

        Company::factory()->create(['name' => 'Empresa A']);
        Company::factory()->create(['name' => 'Empresa B']);
        Company::factory()->create(['name' => 'Empresa C']);

        $pendentes = $this->service()->forCarteira($admin);

        $this->assertCount(3, $pendentes,
            'admin deveria ver as 3 empresas pendentes');

        // Sanity check no shape do primeiro item — as chaves sao contrato
        // publico consumido pelos widgets e badges do Plan 72-03.
        $primeiro = $pendentes[0];
        $this->assertArrayHasKey('company_id',      $primeiro);
        $this->assertArrayHasKey('name',            $primeiro);
        $this->assertArrayHasKey('template_id',     $primeiro);
        $this->assertArrayHasKey('template_nome',   $primeiro);
        $this->assertArrayHasKey('month_reference', $primeiro);
        $this->assertArrayHasKey('dias_atraso',     $primeiro);

        // Sanity check nos valores: month_reference deve ser primeiro dia do
        // mes corrente (2026-07-01) e dias_atraso = hoje.day - diaCobranca
        // (28 - 25 = 3).
        $this->assertSame('2026-07-01', $primeiro['month_reference']);
        $this->assertSame(3,            $primeiro['dias_atraso']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenario 8 — forCarteira(consultor): retorna apenas empresas da carteira
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_forCarteira_consultor_retorna_apenas_carteira(): void
    {
        // Consultor (nao admin) ve apenas empresas do pivot company_users.
        // Setup: 5 empresas no total, mas apenas 2 vinculadas ao consultor.
        // forCarteira deve retornar SOMENTE as 2 vinculadas.
        Configuracao::set('nps_dia_cobranca', 25);
        Carbon::setTestNow('2026-07-28 09:00:00');

        $consultor = User::factory()->create(['role' => 'consultor']);

        $c1 = Company::factory()->create(['name' => 'Carteira 1']);
        $c2 = Company::factory()->create(['name' => 'Carteira 2']);

        // 3 empresas FORA da carteira do consultor — nao devem aparecer.
        Company::factory()->create(['name' => 'Fora 1']);
        Company::factory()->create(['name' => 'Fora 2']);
        Company::factory()->create(['name' => 'Fora 3']);

        // Vincula so as 2 primeiras na pivot com role=consultor. assigned_at
        // e obrigatorio no pivot company_users (padrao consolidado do projeto).
        $consultor->companies()->attach($c1->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);
        $consultor->companies()->attach($c2->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);

        $pendentes = $this->service()->forCarteira($consultor);

        $this->assertCount(2, $pendentes,
            'consultor deveria ver apenas as 2 empresas da propria carteira');

        // Verifica que os company_ids retornados sao EXATAMENTE os da carteira
        // (nao vazamento de empresas fora do pivot).
        $ids = array_column($pendentes, 'company_id');
        sort($ids);
        $this->assertSame([$c1->id, $c2->id], $ids,
            'company_ids retornados devem ser apenas os do pivot company_users');
    }
}
