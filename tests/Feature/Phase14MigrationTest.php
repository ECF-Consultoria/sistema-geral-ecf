<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Testes das migrations 1 (seed catálogo) e 2 (data legacy → contratos)
 * do Plan 14-02.
 *
 * Cobre:
 *  - Criação correta dos 6 servicos canônicos (D-01)
 *  - 1 contrato por slug em service_type
 *  - 1 contrato adicional com normalização Title Case (D-02)
 *  - Estado neutro: empresas sem service_type nem additional_service
 *  - Idempotência: re-run não duplica contratos
 *
 * Per Plan 14-02 Task 3 + CONTEXT.md D-01/D-02/D-04.
 *
 * @group phase14
 */
class Phase14MigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Path do arquivo da Migration 1 (seed do catálogo).
     */
    private const MIGRATION_SEED_PATH = 'database/migrations/2026_05_27_100001_seed_servicos_catalog.php';

    /**
     * Path do arquivo da Migration 2 (data legacy → contratos).
     */
    private const MIGRATION_DATA_PATH = 'database/migrations/2026_05_27_100002_migrate_legacy_service_data.php';

    /**
     * Lista canônica dos 6 nomes pt-BR (D-01).
     */
    private const NOMES_CANONICOS = [
        'Publicação',
        'Polos',
        'Assessoria',
        'Incubadora',
        'Publicidade',
        'Gestão',
    ];

    /**
     * Cria empresa com campos legacy preenchidos (helper de teste).
     *
     * Nota: factory de Company não existe no projeto — usamos Company::create()
     * direto, consistente com Phase13MigrationTest e Phase14VerificarCobrancaTest.
     */
    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste',
            'active' => true,
            'status' => 'ativo',
        ], $overrides));
    }

    /**
     * Executa o método up() de uma migration diretamente (não passa pelo migrator).
     * Útil para re-rodar a migration na mesma suíte sem `migrate:fresh`.
     */
    private function runMigrationUp(string $migrationPath): void
    {
        $migration = require database_path(str_replace('database/', '', $migrationPath));
        $migration->up();
    }

    /**
     * TEST 1 — Migration 1 cria os 6 servicos canônicos com valor_padrao=0,
     * tipo_cobranca='mensal' e ativo=true.
     */
    public function test_migration_cria_6_servicos_canonicos(): void
    {
        // RefreshDatabase + migrate normal já rodaram TODAS as migrations,
        // incluindo a 100001 — então o catálogo já está populado quando
        // este teste roda. Verifica o estado pós-migração.

        $this->assertGreaterThanOrEqual(
            6,
            Servico::count(),
            'Deve existir ao menos 6 servicos no catálogo após a Migration 1.',
        );

        foreach (self::NOMES_CANONICOS as $nome) {
            $servico = Servico::where('nome', $nome)->first();
            $this->assertNotNull($servico, "Servico '{$nome}' deve existir após a Migration 1.");
            $this->assertEquals('mensal', $servico->tipo_cobranca, "Servico '{$nome}' deve ter tipo_cobranca='mensal'.");
            $this->assertEquals(0.0, (float) $servico->valor_padrao, "Servico '{$nome}' deve ter valor_padrao=0.");
            $this->assertTrue((bool) $servico->ativo, "Servico '{$nome}' deve estar ativo.");
        }
    }

    /**
     * TEST 2 — Empresa com service_type=['polos','gestao'] recebe 2 contratos
     * (1 Polos + 1 Gestão), ambos com valor_contratado=0, datas vindo de
     * contract_start/contract_end.
     */
    public function test_migration_cria_contratos_para_empresa_com_service_type(): void
    {
        // Catálogo já populado pelo RefreshDatabase + migrate. Cria empresa
        // depois (campos legacy AINDA existem nesta wave — drop só no Plan 14-06).
        $company = $this->criarEmpresa([
            'name'           => 'Empresa A',
            'service_type'   => ['polos', 'gestao'],
            'contract_start' => '2026-01-01',
            'contract_end'   => '2026-12-31',
        ]);

        // Re-roda a Migration 2 manualmente (a empresa foi criada DEPOIS do
        // migrate inicial, então a migration original não viu ela).
        $this->runMigrationUp(self::MIGRATION_DATA_PATH);

        $contratos = ContratoServico::where('company_id', $company->id)->get();
        $this->assertCount(2, $contratos, 'Empresa com 2 slugs em service_type deve receber 2 contratos.');

        foreach ($contratos as $contrato) {
            $this->assertEquals(0.0, (float) $contrato->valor_contratado, 'Contratos de service_type vêm com valor_contratado=0.');
            $this->assertEquals('2026-01-01', $contrato->data_contratacao->toDateString(), 'data_contratacao deve vir de contract_start.');
            $this->assertEquals('2026-12-31', $contrato->data_vencimento->toDateString(), 'data_vencimento deve vir de contract_end.');
            $this->assertTrue((bool) $contrato->ativo, 'Contratos migrados devem ficar ativos.');
        }

        // Confirma que os nomes mapearam corretamente (D-01: polos→Polos, gestao→Gestão).
        $nomesDosContratos = $contratos->load('servico')->pluck('servico.nome')->sort()->values()->all();
        $this->assertEquals(['Gestão', 'Polos'], $nomesDosContratos, 'Contratos devem apontar para Polos e Gestão.');
    }

    /**
     * TEST 3 — Empresa com additional_service em caixa mista + espaços recebe
     * 1 contrato com nome normalizado em Title Case e valor_contratado=preço.
     * data_contratacao fallback para created_at quando contract_start é null.
     */
    public function test_migration_cria_contrato_adicional_com_normalizacao_title_case(): void
    {
        $company = $this->criarEmpresa([
            'name'                     => 'Empresa Adicional',
            'service_type'             => [],
            'additional_service'       => '  TREINAMENTO PERSONALIZADO  ',
            'additional_service_price' => 350.00,
            'contract_start'           => null,
            'contract_end'             => null,
        ]);

        $this->runMigrationUp(self::MIGRATION_DATA_PATH);

        // D-02 — normalização agressiva: trim + Title Case UTF-8.
        $servicoNovo = Servico::where('nome', 'Treinamento Personalizado')->first();
        $this->assertNotNull(
            $servicoNovo,
            "Servico 'Treinamento Personalizado' deve ser criado via firstOrCreate (D-02 Title Case).",
        );

        $contratos = ContratoServico::where('company_id', $company->id)->get();
        $this->assertCount(1, $contratos, 'Empresa só com additional_service recebe exatamente 1 contrato.');

        $contrato = $contratos->first();
        $this->assertEquals(350.00, (float) $contrato->valor_contratado, 'valor_contratado deve refletir additional_service_price.');
        $this->assertEquals(
            $company->created_at->toDateString(),
            $contrato->data_contratacao->toDateString(),
            'Sem contract_start, data_contratacao deve cair para created_at.',
        );
        $this->assertNull($contrato->data_vencimento, 'Sem contract_end, data_vencimento deve ficar null.');
        $this->assertEquals($servicoNovo->id, $contrato->servico_id, 'Contrato adicional deve apontar para o servico normalizado.');
    }

    /**
     * TEST 4 — Empresa com ambos os campos vazios não recebe nenhum contrato.
     * Estado neutro (D-04 item 3) — útil para cadastros em status 'pendente'
     * do setor Comercial.
     */
    public function test_migration_skip_empresa_sem_service_type_nem_additional(): void
    {
        $company = $this->criarEmpresa([
            'name'               => 'Empresa Vazia',
            'service_type'       => [],
            'additional_service' => null,
        ]);

        $this->runMigrationUp(self::MIGRATION_DATA_PATH);

        $this->assertEquals(
            0,
            ContratoServico::where('company_id', $company->id)->count(),
            'Empresa sem service_type nem additional_service não deve receber nenhum contrato.',
        );
    }

    /**
     * TEST 5 — Idempotência: rodar a Migration 2 duas vezes não duplica contratos.
     *
     * Cenário cumulativo (D-04): 1 contrato de Polos (valor=0) + 1 contrato
     * adicional "Consultoria" (valor=100). Re-run mantém o count em 2.
     */
    public function test_migration_pode_rodar_2x_sem_duplicar(): void
    {
        $company = $this->criarEmpresa([
            'name'                     => 'Empresa Idempotente',
            'service_type'             => ['polos'],
            'additional_service'       => 'Consultoria',
            'additional_service_price' => 100.00,
            'contract_start'           => '2026-01-01',
        ]);

        // 1ª execução cria os 2 contratos.
        $this->runMigrationUp(self::MIGRATION_DATA_PATH);

        $countApos1 = ContratoServico::where('company_id', $company->id)->count();
        $this->assertEquals(
            2,
            $countApos1,
            'Primeira execução deve criar 2 contratos (1 Polos + 1 Consultoria).',
        );

        // 2ª execução — os guards `where(...)->exists()` devem prevenir duplicação.
        $this->runMigrationUp(self::MIGRATION_DATA_PATH);

        $countApos2 = ContratoServico::where('company_id', $company->id)->count();
        $this->assertEquals(
            2,
            $countApos2,
            'Re-run da Migration 2 NÃO deve duplicar contratos (idempotência via where(...)->exists()).',
        );

        // Sanity: também checa o catálogo — Consultoria foi criado 1x, não 2x.
        $this->assertEquals(
            1,
            Servico::where('nome', 'Consultoria')->count(),
            'Servico Consultoria deve ser criado 1x via firstOrCreate, não duplicado em re-run.',
        );
    }
}
