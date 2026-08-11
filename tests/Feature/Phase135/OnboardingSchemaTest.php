<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 Plano 02 — schema do motor de Onboarding geral.
 *
 * Cobre: round-trip dos casts de array/datetime, os catálogos fechados
 * (dono/auto_fonte/status) e as 4 constraints de unicidade impostas pelo
 * banco (não pela aplicação) — versão ativa por serviço (D-07/SC-09), chave
 * por template, contrato por onboarding (SC-01) e link por empresa (D-06).
 */
class OnboardingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function criarServico(string $nome = 'Gestao'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
    }

    // ─── Catálogos fechados ───────────────────────────────────────────────

    /** @test */
    public function auto_fontes_tem_exatamente_5_valores_do_catalogo_fechado(): void
    {
        $this->assertCount(5, TemplatePasso::AUTO_FONTES);
        $this->assertSame([
            'adman_account_id_preenchido',
            'adman_grant_ativo',
            'ml_token_ativo',
            'acervo_coletado',
            'metricas_conta',
        ], TemplatePasso::AUTO_FONTES);
    }

    /** @test */
    public function donos_tem_exatamente_3_valores_d14_nenhum_quarto_dono(): void
    {
        $this->assertCount(3, TemplatePasso::DONOS);
        $this->assertContains('cliente', TemplatePasso::DONOS);
        $this->assertContains('interno', TemplatePasso::DONOS);
        $this->assertContains('sistema', TemplatePasso::DONOS);
    }

    /** @test */
    public function onboarding_passo_statuses_tem_6_valores_incluindo_aguardando_coleta_e_indeterminado(): void
    {
        $this->assertCount(6, OnboardingPasso::STATUSES);
        $this->assertContains('aguardando_coleta', OnboardingPasso::STATUSES);
        $this->assertContains('indeterminado', OnboardingPasso::STATUSES);
        $this->assertContains('nao_aplicavel', OnboardingPasso::STATUSES);
    }

    // ─── Casts ────────────────────────────────────────────────────────────

    /** @test */
    public function template_passo_depende_de_volta_do_banco_como_array(): void
    {
        $servico = $this->criarServico();
        $template = OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);

        $passo = TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => 'grant_ecf',
            'titulo'      => 'Grant com o Sistema ECF',
            'dono'        => TemplatePasso::DONO_CLIENTE,
            'depende_de'  => ['acesso_colaborador_ml'],
            'auto_fonte'  => TemplatePasso::AUTO_FONTE_ML_TOKEN,
        ]);

        $passo->refresh();

        $this->assertIsArray($passo->depende_de);
        $this->assertSame(['acesso_colaborador_ml'], $passo->depende_de);
    }

    /** @test */
    public function onboarding_passo_disponivel_em_volta_do_banco_como_carbon(): void
    {
        $servico = $this->criarServico();
        $template = OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);
        $templatePasso = TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => 'acesso_colaborador_ml',
            'titulo'      => 'Acesso Colaborador ML',
            'dono'        => TemplatePasso::DONO_CLIENTE,
        ]);
        $company = Company::factory()->create();
        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'template_id' => $template->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id'      => $onboarding->id,
            'template_passo_id'  => $templatePasso->id,
            'chave'              => $templatePasso->chave,
            'disponivel_em'      => now(),
        ]);

        $passo->refresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $passo->disponivel_em);
    }

    // ─── Constraints de unicidade impostas pelo banco ────────────────────

    /** @test */
    public function duas_versoes_ativas_do_mesmo_servico_lancam_query_exception(): void
    {
        $servico = $this->criarServico();
        OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);

        $this->expectException(QueryException::class);
        OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 2, 'ativo' => true]);
    }

    /** @test */
    public function versao_ativa_e_inativa_do_mesmo_servico_convivem_sem_erro(): void
    {
        $servico = $this->criarServico();
        OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);
        $v2 = OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 2, 'ativo' => false]);

        $this->assertDatabaseHas('onboarding_templates', ['id' => $v2->id, 'ativo' => false]);
    }

    /** @test */
    public function chave_duplicada_no_mesmo_template_lanca_query_exception(): void
    {
        $servico = $this->criarServico();
        $template = OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);
        TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => 'ficha_cliente',
            'titulo'      => 'Ficha do cliente recebida',
            'dono'        => TemplatePasso::DONO_INTERNO,
        ]);

        $this->expectException(QueryException::class);
        TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 2,
            'chave'       => 'ficha_cliente',
            'titulo'      => 'Duplicata',
            'dono'        => TemplatePasso::DONO_INTERNO,
        ]);
    }

    /** @test */
    public function dois_onboardings_do_mesmo_contrato_lancam_query_exception_sc01(): void
    {
        $servico = $this->criarServico();
        $template = OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($servico)
            ->create(['company_id' => $company->id]);

        Onboarding::create([
            'company_id'           => $company->id,
            'servico_id'           => $servico->id,
            'contrato_servico_id'  => $contrato->id,
            'template_id'          => $template->id,
        ]);

        $this->expectException(QueryException::class);
        Onboarding::create([
            'company_id'           => $company->id,
            'servico_id'           => $servico->id,
            'contrato_servico_id'  => $contrato->id,
            'template_id'          => $template->id,
        ]);
    }

    /** @test */
    public function dois_links_da_mesma_empresa_lancam_query_exception_d06(): void
    {
        $company = Company::factory()->create();
        OnboardingLink::create(['company_id' => $company->id, 'token' => str_repeat('a', 48)]);

        $this->expectException(QueryException::class);
        OnboardingLink::create(['company_id' => $company->id, 'token' => str_repeat('b', 48)]);
    }

    /** @test */
    public function dois_onboarding_passos_do_mesmo_par_lancam_query_exception(): void
    {
        $servico = $this->criarServico();
        $template = OnboardingTemplate::create(['servico_id' => $servico->id, 'versao' => 1, 'ativo' => true]);
        $templatePasso = TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => 'reuniao_onboarding',
            'titulo'      => 'Agendar reunião de onboarding',
            'dono'        => TemplatePasso::DONO_INTERNO,
        ]);
        $company = Company::factory()->create();
        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'template_id' => $template->id,
        ]);

        OnboardingPasso::create([
            'onboarding_id'     => $onboarding->id,
            'template_passo_id' => $templatePasso->id,
            'chave'             => $templatePasso->chave,
        ]);

        $this->expectException(QueryException::class);
        OnboardingPasso::create([
            'onboarding_id'     => $onboarding->id,
            'template_passo_id' => $templatePasso->id,
            'chave'             => $templatePasso->chave,
        ]);
    }

    // ─── D-02: motor novo não referencia Polos ──────────────────────────────

    /** @test */
    public function models_novos_nao_referenciam_mlb_implementacao(): void
    {
        $arquivos = [
            app_path('Models/OnboardingTemplate.php'),
            app_path('Models/Onboarding.php'),
            app_path('Models/OnboardingPasso.php'),
            app_path('Models/OnboardingLink.php'),
            app_path('Models/TemplatePasso.php'),
        ];

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            $this->assertStringNotContainsString('MlbImplementacao', $conteudo);
            $this->assertStringNotContainsString('mlb_implementacoes', $conteudo);
        }
    }
}
