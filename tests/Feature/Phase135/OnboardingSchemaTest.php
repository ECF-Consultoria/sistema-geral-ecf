<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertCount(5, OnboardingPasso::AUTO_FONTES);
        $this->assertSame([
            'adman_account_id_preenchido',
            'adman_grant_ativo',
            'ml_token_ativo',
            'acervo_coletado',
            'metricas_conta',
        ], OnboardingPasso::AUTO_FONTES);
    }

    /** @test */
    public function donos_tem_exatamente_3_valores_d14_nenhum_quarto_dono(): void
    {
        $this->assertCount(3, OnboardingPasso::DONOS);
        $this->assertContains('cliente', OnboardingPasso::DONOS);
        $this->assertContains('interno', OnboardingPasso::DONOS);
        $this->assertContains('sistema', OnboardingPasso::DONOS);
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
    public function passo_depende_de_volta_do_banco_como_array(): void
    {
        $servico = $this->criarServico();
        $company = Company::factory()->create();
        $onboarding = Onboarding::create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => 'grant_ecf',
            'titulo'        => 'Grant com o Sistema ECF',
            'dono'          => OnboardingPasso::DONO_CLIENTE,
            'depende_de'    => ['acesso_colaborador_ml'],
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_ML_TOKEN,
        ]);

        $passo->refresh();

        $this->assertIsArray($passo->depende_de);
        $this->assertSame(['acesso_colaborador_ml'], $passo->depende_de);
    }

    /** @test */
    public function onboarding_passo_disponivel_em_volta_do_banco_como_carbon(): void
    {
        $servico = $this->criarServico();
        $company = Company::factory()->create();
        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => 'acesso_colaborador_ml',
            'titulo'        => 'Acesso Colaborador ML',
            'dono'          => OnboardingPasso::DONO_CLIENTE,
            'disponivel_em' => now(),
        ]);

        $passo->refresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $passo->disponivel_em);
    }

    // ─── Constraints de unicidade impostas pelo banco ────────────────────

    /** @test */
    public function dois_onboardings_do_mesmo_contrato_lancam_query_exception_sc01(): void
    {
        $servico = $this->criarServico();
        $company = Company::factory()->create();

        // Inserção via DB::table (não Eloquent) de propósito: este teste prova a
        // constraint de UNICIDADE do BANCO, não o comportamento do
        // ContratoServicoObserver — que criaria sozinho o primeiro onboarding e
        // quebraria a sequência esperada abaixo.
        $contratoId = DB::table('contratos_servico')->insertGetId([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        $contrato = ContratoServico::find($contratoId);

        Onboarding::create([
            'company_id'           => $company->id,
            'servico_id'           => $servico->id,
            'contrato_servico_id'  => $contrato->id,
        ]);

        $this->expectException(QueryException::class);
        Onboarding::create([
            'company_id'           => $company->id,
            'servico_id'           => $servico->id,
            'contrato_servico_id'  => $contrato->id,
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

    /**
     * A trava de duplicidade agora é (onboarding_id, chave). Ela SUBSTITUI o
     * antigo (onboarding_id, template_passo_id): sem tabela de template, a
     * `chave` é o identificador estável do passo — e é por ela que dependência
     * e link público já se referenciavam.
     *
     * @test
     */
    public function dois_passos_com_a_mesma_chave_no_mesmo_onboarding_lancam_query_exception(): void
    {
        $servico = $this->criarServico();
        $company = Company::factory()->create();
        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
        ]);

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => 'reuniao_onboarding',
            'titulo'        => 'Agendar reunião de onboarding',
            'dono'          => OnboardingPasso::DONO_INTERNO,
        ]);

        $this->expectException(QueryException::class);
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 2,
            'chave'         => 'reuniao_onboarding',
            'titulo'        => 'Duplicata',
            'dono'          => OnboardingPasso::DONO_INTERNO,
        ]);
    }

    // ─── D-02: motor novo não referencia Polos ──────────────────────────────

    /** @test */
    public function models_novos_nao_referenciam_mlb_implementacao(): void
    {
        $arquivos = [
            app_path('Models/Onboarding.php'),
            app_path('Models/OnboardingPasso.php'),
            app_path('Models/OnboardingLink.php'),
            app_path('Support/Onboarding/DefinicaoOnboarding.php'),
        ];

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            $this->assertStringNotContainsString('MlbImplementacao', $conteudo);
            $this->assertStringNotContainsString('mlb_implementacoes', $conteudo);
        }
    }
}
