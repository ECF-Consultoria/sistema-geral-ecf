<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 79 Plan 03 — Matriz do disparo ESTRITO por serviços cobertos (DEC-79-A).
 *
 * O comando `nps:disparar-mensal` deixa de "forçar o principal (is_default) para
 * TODAS as empresas" e passa a gerar 1 envio por modelo com
 * `envio_automatico_mensal=true` cujos "Serviços cobertos"
 * (`nps_template_service_scopes`) intersectam um contrato ATIVO da empresa.
 *
 * Casos cobertos (RESEARCH — Fluxo de dados / disparo estrito):
 *  (a) empresa só performance → 1 survey NPS Padrão, 0 do Shopee;
 *  (b) empresa só shopee → 1 survey NPS Shopee;
 *  (c) empresa performance+shopee → 2 surveys (um por modelo);
 *  (d) empresa elegível SEM serviço coberto → 0 surveys + Log::warning estruturado;
 *  (e) DEDUP por (company_id, mês, template_id) — 2ª execução não duplica nem
 *      bloqueia o 2º modelo (empresa performance+shopee continua com exatamente 2).
 *
 * Setup implícito via RefreshDatabase: todas as migrations rodam, incluindo o seed
 * do NPS Shopee + link performance ao NPS Padrão (Plano 79-02,
 * 2026_07_14_200002). Como o seed só linka os serviços que EXISTIAM no momento da
 * migration, cada teste cria seus próprios serviços de fixture e re-invoca o seed
 * (idempotente) para vincular os scopes — exceto o caso (d), que propositalmente
 * NÃO linka o serviço para simular a ausência de cobertura.
 *
 * Comentários pt-BR conforme convenção do projeto.
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see database/migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php
 */
class DisparoEstritoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        // Canal email ligado (default do projeto) — garante elegibilidade por email.
        Configuracao::set('nps_envio_email_ativo', '1');
        Configuracao::set('nps_envio_digisac_ativo', '0');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Re-invoca o seed 79-02 (idempotente) para linkar os scopes dos serviços
     * criados na fixture do teste ao NPS Padrão (performance) e NPS Shopee.
     */
    private function linkarScopes(): void
    {
        $m = require database_path('migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php');
        $m->up();
    }

    /**
     * Empresa ativa, com email_cliente e created_at fixado no dia de HOJE
     * (mockado por Carbon::setTestNow) — casa o `$diaAlvo` do comando.
     */
    private function criarEmpresaElegivelHoje(): Company
    {
        $agora = Carbon::now('America/Sao_Paulo');

        $empresa = Company::factory()->create([
            'email_cliente' => 'cliente@' . uniqid() . '.com',
        ]);

        $empresa->timestamps = false;
        $empresa->forceFill([
            'created_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
            'updated_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
        ])->save();
        $empresa->timestamps = true;
        $empresa->refresh();

        return $empresa;
    }

    /**
     * Atribui estrategista (guard obrigatório do comando) via pivot company_users
     * com servico_id null (slot consolidado — o guard estrategista() do comando
     * não filtra por serviço).
     */
    private function atribuirEstrategista(Company $empresa): User
    {
        $user = User::factory()->create();
        $this->inserirPivot($empresa->id, $user->id, 'estrategista', null);

        return $user;
    }

    private function npsPadraoId(): int
    {
        return (int) NpsTemplate::default()->value('id');
    }

    private function npsShopeeId(): int
    {
        return (int) NpsTemplate::where('nome', 'NPS Shopee')->value('id');
    }

    /**
     * Resolve o serviço performance ATIVO já semeado (ou cria se ausente).
     * O seed 79-02 linka TODOS os serviços performance ao NPS Padrão.
     */
    private function servicoPerformanceId(): int
    {
        $id = DB::table('servicos')
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('ativo', true)
            ->value('id');

        return $id !== null ? (int) $id : $this->criarServico(Servico::SETOR_PERFORMANCE, true);
    }

    /**
     * Resolve o serviço shopee ATIVO já semeado (Phase 75) — ou cria se ausente.
     * IMPORTANTE: o seed 79-02 linka o NPS Shopee a UM serviço shopee (value('id')),
     * então precisamos contratar exatamente esse (não um duplicado).
     */
    private function servicoShopeeId(): int
    {
        $id = DB::table('servicos')
            ->where('setor', Servico::SETOR_SHOPEE)
            ->where('ativo', true)
            ->value('id');

        return $id !== null ? (int) $id : $this->criarServico(Servico::SETOR_SHOPEE, true);
    }

    // ═══════════════════════════════════════════════════════════════════
    // (a) empresa só performance → 1 survey NPS Padrão, 0 do Shopee
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_so_performance_recebe_1_survey_do_nps_padrao(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $servicoPerf = $this->servicoPerformanceId();
        $this->servicoShopeeId();
        $this->linkarScopes();

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        Artisan::call('nps:disparar-mensal');

        $surveys = NpsSurvey::where('company_id', $empresa->id)->get();
        $this->assertCount(1, $surveys, 'Empresa só performance deve receber exatamente 1 survey.');
        $this->assertSame($this->npsPadraoId(), (int) $surveys->first()->template_id, 'Survey deve ser do NPS Padrão.');
        $this->assertSame(
            0,
            NpsSurvey::where('company_id', $empresa->id)->where('template_id', $this->npsShopeeId())->count(),
            'Empresa só performance NÃO deve receber survey do NPS Shopee.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // (b) empresa só shopee → 1 survey NPS Shopee
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_so_shopee_recebe_1_survey_do_nps_shopee(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $this->servicoPerformanceId();
        $servicoShopee = $this->servicoShopeeId();
        $this->linkarScopes();

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);
        $this->criarContrato($empresa->id, $servicoShopee, true);

        Artisan::call('nps:disparar-mensal');

        $surveys = NpsSurvey::where('company_id', $empresa->id)->get();
        $this->assertCount(1, $surveys, 'Empresa só shopee deve receber exatamente 1 survey.');
        $this->assertSame($this->npsShopeeId(), (int) $surveys->first()->template_id, 'Survey deve ser do NPS Shopee.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // (c) empresa performance+shopee → 2 surveys (um por modelo)
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_performance_e_shopee_recebe_2_surveys(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $servicoPerf   = $this->servicoPerformanceId();
        $servicoShopee = $this->servicoShopeeId();
        $this->linkarScopes();

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->criarContrato($empresa->id, $servicoShopee, true);

        Artisan::call('nps:disparar-mensal');

        $surveys = NpsSurvey::where('company_id', $empresa->id)->get();
        $this->assertCount(2, $surveys, 'Empresa performance+shopee deve receber 2 surveys (um por modelo).');

        $templateIds = $surveys->pluck('template_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $esperado    = collect([$this->npsPadraoId(), $this->npsShopeeId()])->sort()->values()->all();
        $this->assertSame($esperado, $templateIds, 'Deve haver 1 survey do NPS Padrão e 1 do NPS Shopee.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // (d) empresa elegível SEM serviço coberto → 0 surveys + Log::warning
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_servico_coberto_nao_gera_survey_e_loga_warning(): void
    {
        Mail::fake();
        Log::spy();
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        // Serviço criado APÓS o seed e NÃO linkado a nenhum scope (não re-invocamos
        // linkarScopes) → nenhum modelo aplicável para esta empresa.
        $servicoSemCobertura = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);
        $this->criarContrato($empresa->id, $servicoSemCobertura, true);

        Artisan::call('nps:disparar-mensal');

        $this->assertSame(
            0,
            NpsSurvey::where('company_id', $empresa->id)->count(),
            'Empresa sem serviço coberto NÃO deve receber nenhum survey (DEC-79-A estrito, sem fallback).'
        );

        Log::shouldHaveReceived('warning')->withArgs(function ($mensagem) use ($empresa) {
            $texto = strtolower((string) $mensagem);

            return str_contains($texto, 'sem modelo aplicável')
                && str_contains((string) $mensagem, (string) $empresa->id);
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // (e) DEDUP por (company, mês, template) — 2ª execução não duplica
    // ═══════════════════════════════════════════════════════════════════

    public function test_dedup_por_template_nao_duplica_nem_bloqueia_segundo_modelo(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $servicoPerf   = $this->servicoPerformanceId();
        $servicoShopee = $this->servicoShopeeId();
        $this->linkarScopes();

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->criarContrato($empresa->id, $servicoShopee, true);

        // 1ª execução → 2 surveys (um por modelo).
        Artisan::call('nps:disparar-mensal');
        $this->assertSame(2, NpsSurvey::where('company_id', $empresa->id)->count());

        // 2ª execução no mesmo mês → dedup por template_id impede duplicatas.
        Artisan::call('nps:disparar-mensal');
        $this->assertSame(
            2,
            NpsSurvey::where('company_id', $empresa->id)->count(),
            'Re-run no mesmo mês deve manter exatamente 2 surveys (um por modelo, sem duplicar).'
        );
    }
}
