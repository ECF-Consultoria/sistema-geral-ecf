<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsElegibilidadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 01 — Prova de equivalência do `NpsElegibilidadeService` com
 * o comportamento atual de `NpsDispararMensal` (DEC-79-A). Cada teste cobre
 * um item do bloco `<behavior>` do plano.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Services/Nps/NpsElegibilidadeService.php
 * @see app/Console/Commands/NpsDispararMensal.php
 */
class NpsElegibilidadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private NpsElegibilidadeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->service = app(NpsElegibilidadeService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (mesmo molde de tests/Feature/V16/CriaCenarioResponsaveis.php)
    // ═══════════════════════════════════════════════════════════════════

    private function criarServico(string $setor, bool $ativo = true): int
    {
        return (int) DB::table('servicos')->insertGetId([
            'nome'          => 'Serviço ' . ucfirst($setor) . ' ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => $ativo,
            'setor'         => $setor,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function criarContrato(int $companyId, int $servicoId, bool $ativo = true): int
    {
        return (int) DB::table('contratos_servico')->insertGetId([
            'company_id'       => $companyId,
            'servico_id'       => $servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $ativo,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function inserirPivot(int $companyId, int $userId, string $role, ?int $servicoId): int
    {
        return (int) DB::table('company_users')->insertGetId([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'role'        => $role,
            'servico_id'  => $servicoId,
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Monta empresa ativa + serviço/contrato ativo + modelo NPS
     * (active + envio_automatico_mensal) cobrindo aquele serviço.
     *
     * @return array{empresa: Company, servico: int, template: NpsTemplate}
     */
    private function criarCenarioElegivel(array $atributosEmpresa = []): array
    {
        $empresa = Company::factory()->create(array_merge([
            'active' => true,
        ], $atributosEmpresa));

        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        $template = NpsTemplate::factory()->create([
            'active'                  => true,
            'envio_automatico_mensal' => true,
        ]);
        $template->serviceScopes()->attach($servico);

        return ['empresa' => $empresa, 'servico' => $servico, 'template' => $template];
    }

    // ═══════════════════════════════════════════════════════════════════
    // empresasElegiveis()
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_ativa_com_estrategista_e_modelo_aplicavel_e_elegivel(): void
    {
        ['empresa' => $empresa, 'template' => $template] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);

        $itens = $this->service->empresasElegiveis(now());

        $item = $itens->firstWhere('company_id', $empresa->id);
        $this->assertNotNull($item, 'Empresa com estrategista + modelo aplicável deve ser elegível.');
        $this->assertSame($template->id, $item->template_id);
        $this->assertCount(1, $itens->where('company_id', $empresa->id), 'Deve gerar exatamente 1 item por (empresa, modelo).');
    }

    public function test_empresa_sem_estrategista_nao_e_elegivel(): void
    {
        $this->criarCenarioElegivel(); // sem atribuir estrategista

        $itens = $this->service->empresasElegiveis(now());

        $this->assertCount(0, $itens, 'Empresa sem estrategista não deve gerar nenhum item.');
    }

    public function test_empresa_sem_contrato_ativo_em_servico_coberto_nao_e_elegivel(): void
    {
        $empresa = Company::factory()->create(['active' => true]);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);

        // Modelo existe, mas a empresa não tem NENHUM contrato ativo.
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $template->serviceScopes()->attach($servico);

        $itens = $this->service->empresasElegiveis(now());

        $this->assertCount(0, $itens->where('company_id', $empresa->id), 'Empresa sem contrato ativo coberto não deve ser elegível.');
    }

    public function test_empresa_inativa_nao_e_elegivel(): void
    {
        ['empresa' => $empresa] = $this->criarCenarioElegivel(['active' => false]);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);

        $itens = $this->service->empresasElegiveis(now());

        $this->assertCount(0, $itens->where('company_id', $empresa->id), 'Empresa active=false não deve ser elegível.');
    }

    public function test_empresa_sem_canal_de_contato_e_elegivel_do_mesmo_jeito_com_tem_canal_false(): void
    {
        ['empresa' => $empresa] = $this->criarCenarioElegivel([
            'email_cliente'             => null,
            'digisac_group_contact_id'  => null,
        ]);
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);

        $itens = $this->service->empresasElegiveis(now());
        $item = $itens->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($item, 'D5 — empresa sem canal continua elegível.');
        $this->assertFalse($item->tem_canal, 'tem_canal deve ser false quando não há email nem digisac.');
    }

    public function test_analista_ausente_nao_afeta_elegibilidade(): void
    {
        ['empresa' => $empresa] = $this->criarCenarioElegivel();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);
        // Nenhum 'consultor' (analista) inserido de propósito.

        $itens = $this->service->empresasElegiveis(now());

        $this->assertNotNull($itens->firstWhere('company_id', $empresa->id), 'Analista ausente não deve impedir a elegibilidade.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // surveyExistenteNaCompetencia()
    // ═══════════════════════════════════════════════════════════════════

    public function test_survey_existente_na_competencia_encontra_month_reference_preenchida(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = Company::factory()->create();
        $template = NpsTemplate::factory()->create();

        $survey = NpsSurvey::factory()->for($empresa)->create([
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
        ]);

        $encontrado = $this->service->surveyExistenteNaCompetencia($empresa->id, $template->id, now());

        $this->assertNotNull($encontrado);
        $this->assertSame($survey->id, $encontrado->id);
    }

    public function test_survey_existente_na_competencia_encontra_month_reference_null_via_created_at(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = Company::factory()->create();
        $template = NpsTemplate::factory()->create();

        // Simula o disparo manual (NpsController::generate) — month_reference NULL.
        $survey = NpsSurvey::factory()->for($empresa)->create([
            'template_id'     => $template->id,
            'month_reference' => null,
        ]);

        $encontrado = $this->service->surveyExistenteNaCompetencia($empresa->id, $template->id, now());

        $this->assertNotNull($encontrado, 'Guard deve enxergar survey manual (month_reference NULL) via created_at.');
        $this->assertSame($survey->id, $encontrado->id);
    }

    public function test_survey_existente_na_competencia_nao_encontra_outro_modelo_nem_outro_mes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = Company::factory()->create();
        $templateA = NpsTemplate::factory()->create();
        $templateB = NpsTemplate::factory()->create();

        NpsSurvey::factory()->for($empresa)->create([
            'template_id'     => $templateA->id,
            'month_reference' => '2026-07-01',
        ]);
        NpsSurvey::factory()->for($empresa)->create([
            'template_id'     => $templateB->id,
            'month_reference' => '2026-06-01',
        ]);

        // Outro modelo, mesmo mês.
        $this->assertNull(
            $this->service->surveyExistenteNaCompetencia($empresa->id, $templateB->id, Carbon::create(2026, 7, 1)),
            'Não deve encontrar survey de OUTRO modelo.'
        );

        // Mesmo modelo, outro mês.
        $this->assertNull(
            $this->service->surveyExistenteNaCompetencia($empresa->id, $templateA->id, Carbon::create(2026, 6, 1)),
            'Não deve encontrar survey de OUTRO mês.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste de EQUIVALÊNCIA com NpsDispararMensal (Task 2 — adicionado aqui
    // conforme instrução do plano)
    // ═══════════════════════════════════════════════════════════════════

    public function test_equivalencia_empresasElegiveis_bate_com_contadores_do_comando_dry_run(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        // Empresa OK: elegível de verdade (estrategista + modelo aplicável),
        // criada há 1 ano com created_at HOJE (bate o gate de aniversário do
        // comando, que segue no comando e não foi extraído).
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $template->serviceScopes()->attach($servico);

        $empresaOk = Company::factory()->create([
            'active'        => true,
            'email_cliente' => 'ok@' . uniqid() . '.com',
        ]);
        $empresaOk->timestamps = false;
        $empresaOk->forceFill([
            'created_at' => now()->copy()->subYear(),
            'updated_at' => now()->copy()->subYear(),
        ])->save();
        $empresaOk->timestamps = true;
        $this->criarContrato($empresaOk->id, $servico, true);
        $estrategistaOk = User::factory()->create();
        $this->inserirPivot($empresaOk->id, $estrategistaOk->id, 'estrategista', null);

        // Empresa sem estrategista.
        $empresaSemEstrategista = Company::factory()->create([
            'active'        => true,
            'email_cliente' => 'semestrategista@' . uniqid() . '.com',
        ]);
        $empresaSemEstrategista->timestamps = false;
        $empresaSemEstrategista->forceFill([
            'created_at' => now()->copy()->subYear(),
            'updated_at' => now()->copy()->subYear(),
        ])->save();
        $empresaSemEstrategista->timestamps = true;
        $this->criarContrato($empresaSemEstrategista->id, $servico, true);

        // Empresa sem modelo aplicável (serviço sem cobertura nenhuma).
        $servicoSemCobertura = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $empresaSemModelo = Company::factory()->create([
            'active'        => true,
            'email_cliente' => 'semmodelo@' . uniqid() . '.com',
        ]);
        $empresaSemModelo->timestamps = false;
        $empresaSemModelo->forceFill([
            'created_at' => now()->copy()->subYear(),
            'updated_at' => now()->copy()->subYear(),
        ])->save();
        $empresaSemModelo->timestamps = true;
        $this->criarContrato($empresaSemModelo->id, $servicoSemCobertura, true);
        $estrategistaSemModelo = User::factory()->create();
        $this->inserirPivot($empresaSemModelo->id, $estrategistaSemModelo->id, 'estrategista', null);

        // empresasElegiveis() deve devolver exatamente a empresa OK.
        $itens = $this->service->empresasElegiveis(now());
        $companyIds = $itens->pluck('company_id')->unique()->values()->all();

        $this->assertSame([$empresaOk->id], $companyIds, 'empresasElegiveis() deve devolver exatamente a empresa OK.');

        // O comando real, em dry-run, deve reportar 1 elegível hoje, 1 pulada
        // por falta de estrategista, e 1 pulada por falta de modelo aplicável.
        Artisan::call('nps:disparar-mensal', ['--dry-run' => true]);
        $saida = Artisan::output();

        $this->assertStringContainsString('3 empresas elegiveis hoje', $saida);
        $this->assertStringContainsString('1 pulada(s) por nao ter estrategista atribuido', $saida);
        $this->assertStringContainsString('1 pulada(s) por nao ter modelo NPS aplicavel', $saida);
    }
}
