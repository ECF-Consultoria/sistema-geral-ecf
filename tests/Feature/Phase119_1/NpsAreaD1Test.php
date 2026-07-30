<?php

namespace Tests\Feature\Phase119_1;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 04 — Prova de D1 na ÁREA NPS (`NpsController::index()`):
 * empresa ELEGÍVEL sem NENHUM link no mês conta nota 1 nos cards por
 * dimensão (a MESMA régua do bônus, via `NpsSemLinkService`), e cada item de
 * `faltantes` diz se está pesando na média (`conta_nota_1`).
 * Quick task 260730-jzx (ajuste 1) removeu a chave `motivo` do item — o
 * envio é manual e contato cadastrado não muda nada (D5 continua valendo).
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Http/Controllers/NpsController.php
 * @see app/Services/Desempenho/NpsSemLinkService.php
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-04-PLAN.md
 */
class NpsAreaD1Test extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Molde de `NpsFloorAreaNpsTest::criarTemplateEscopado()` — questões + opções 1..5. */
    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template 119.1-04 ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    /**
     * Monta empresa ATIVA + serviço/contrato ativo + modelo automático
     * aplicável (active + envio_automatico_mensal, cobrindo o serviço,
     * dimensão EMPRESA) + estrategista atribuído — cenário ELEGÍVEL (D1).
     *
     * @return array{empresa: Company, servico: int, template: NpsTemplate, estrategista: User}
     */
    private function criarCenarioElegivel(array $atributosEmpresa = []): array
    {
        $empresa = Company::factory()->create(array_merge(['active' => true], $atributosEmpresa));

        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], [$servico]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);

        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servico);

        return [
            'empresa'      => $empresa,
            'servico'      => $servico,
            'template'     => $template->fresh(),
            'estrategista' => $estrategista,
        ];
    }

    /** GET /nps pelo caminho REAL — devolve as props Inertia como array puro. */
    private function propsDoIndex(User $user, array $query = []): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('nps.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — mês fechado + elegível sem link → conta nota 1 no card empresa
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_fechado_e_empresa_elegivel_sem_link_conta_nota_1_no_card_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00'));
        $admin = $this->admin();

        $this->criarCenarioElegivel();

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $this->assertEquals(1.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — mês ainda aberto → não conta nada
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_de_coleta_ainda_aberto_nao_conta_nota_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        $admin = $this->admin();

        $this->criarCenarioElegivel();

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $this->assertEquals(0, $props['cards']['empresa']['media']);
        $this->assertEquals(0, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — empresa NÃO elegível (sem estrategista) sem link nunca conta,
    //     mesmo com o mês fechado (NPSMAN-07, contrapeso de D1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_nao_elegivel_sem_estrategista_nunca_conta_mesmo_com_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00'));
        $admin = $this->admin();

        $empresa = Company::factory()->create(['active' => true]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], [$servico]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);
        // Sem estrategista atribuído — não é elegível (D-07/NPSMAN-07).

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $this->assertEquals(0, $props['cards']['empresa']['media']);
        $this->assertEquals(0, $props['cards']['empresa']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — a mesma empresa aparece em faltantes E contribui com a nota 1
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_elegivel_sem_link_aparece_em_faltantes_e_conta_nota_1_ao_mesmo_tempo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00'));
        $admin = $this->admin();

        ['empresa' => $empresa] = $this->criarCenarioElegivel();

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($faltante, 'empresa elegível sem link precisa continuar aparecendo em faltantes.');
        $this->assertTrue($faltante['conta_nota_1']);
        $this->assertEquals(1.0, $props['cards']['empresa']['media']);
        $this->assertEquals(1, $props['contadores']['contam_nota_1']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — quick task 260730-jzx (ajuste 1): o "motivo" (sem_contato/sem_link)
    //     SAIU da tela — o envio é manual, contato cadastrado ou não não
    //     muda nada. D5 continua valendo: falta de contato NÃO tira a
    //     empresa da regra de nota 1. Testes reescritos (ex-motivo
    //     sem_contato/sem_link) para provar exatamente isso.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_faltante_sem_contato_nao_expoe_motivo_e_continua_contando_nota_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00'));
        $admin = $this->admin();

        ['empresa' => $empresa] = $this->criarCenarioElegivel([
            'email_cliente'            => null,
            'digisac_group_contact_id' => null,
        ]);

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($faltante);
        $this->assertArrayNotHasKey('motivo', $faltante);
        // D5 — falta de contato NÃO tira a empresa da regra: continua contando 1.
        $this->assertTrue($faltante['conta_nota_1']);
    }

    #[Test]
    public function test_faltante_com_contato_cadastrado_tambem_nao_expoe_motivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00'));
        $admin = $this->admin();

        ['empresa' => $empresa] = $this->criarCenarioElegivel([
            'email_cliente' => 'cliente@example.com',
        ]);

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($faltante);
        $this->assertArrayNotHasKey('motivo', $faltante);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — conta_nota_1 = false enquanto o mês ainda está aberto
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_faltante_traz_conta_nota_1_false_quando_mes_ainda_esta_aberto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        $admin = $this->admin();

        ['empresa' => $empresa] = $this->criarCenarioElegivel();

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($faltante);
        $this->assertFalse($faltante['conta_nota_1']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — empresa invalidada na competência não entra na média nem
    //     aparece com conta_nota_1=true
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_invalidada_na_competencia_nao_conta_nota_1_nem_na_media(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00'));
        $admin = $this->admin();

        ['empresa' => $empresa] = $this->criarCenarioElegivel();

        // Competência da invalidação = mês do NPS (junho) MENOS 1 (maio) —
        // mesma régua D5/119.1-CONTEXT.md.
        BonusInvalidacao::create([
            'company_id'  => $empresa->id,
            'competencia' => '2026-05-01',
            'motivo'      => 'teste 119.1-04',
        ]);

        $props = $this->propsDoIndex($admin, ['mes' => '2026-06']);

        $this->assertEquals(0, $props['cards']['empresa']['media']);
        $this->assertEquals(0, $props['cards']['empresa']['total']);

        $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresa->id);
        $this->assertNotNull($faltante);
        $this->assertFalse($faltante['conta_nota_1']);
    }
}
