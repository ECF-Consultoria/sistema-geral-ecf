<?php

namespace Tests\Unit\Phase150;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 150 (plano 02, ETAPA-01) — prova os 9 valores, a ordem do §10, o
 * schema da coluna `companies.etapa` (nullable, sem default) e a superfície
 * de mass assignment (`$fillable`). Primeiro teste unitário de "constantes +
 * ordem" de um model neste projeto — sem molde direto a seguir.
 */
class CompanyEtapaConstantesTest extends TestCase
{
    use RefreshDatabase;

    public function test_etapas_tem_exatamente_9_elementos(): void
    {
        $this->assertCount(9, Company::ETAPAS);
    }

    public function test_ordem_posicional_bate_com_a_tabela_do_10(): void
    {
        $this->assertSame([
            Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
            Company::ETAPA_AGUARDANDO_ASSINATURA,
            Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
            Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::ETAPA_ONBOARDING_CONCLUIDO,
            Company::ETAPA_EM_OPERACAO,
        ], Company::ETAPAS);

        $this->assertSame('aguardando_administrativo', Company::ETAPAS[0]);
        $this->assertSame('em_operacao', Company::ETAPAS[8]);
    }

    public function test_etapa_em_operacao_vale_em_operacao(): void
    {
        // D-01: deliberadamente igual à chave que CompanyController já expõe
        // hoje — a Fase 155 troca a fonte sem trocar o nome.
        $this->assertSame('em_operacao', Company::ETAPA_EM_OPERACAO);
    }

    public function test_coluna_etapa_existe_no_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('companies', 'etapa'));
    }

    public function test_empresa_recem_criada_sem_etapa_explicita_fica_null(): void
    {
        // D-03: nullable, SEM default — NULL significa "empresa legada,
        // resolve pelo fallback derivado".
        $company = Company::create([
            'name'   => 'Empresa Teste Fase137',
            'cnpj'   => '11111111111111',
            'active' => true,
            'status' => 'ativo',
        ]);

        $this->assertNull($company->fresh()->etapa);
    }

    public function test_etapa_esta_no_fillable(): void
    {
        $this->assertContains('etapa', (new Company)->getFillable());
    }
}
