<?php

namespace Tests\Unit\Phase170;

use App\Models\Company;
use App\Models\CreativeIdentidade;
use App\Models\MlAnuncioCriativo;
use App\Models\MlbEmpresa;
use App\Services\Creative\CreativeIdentidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CreativeIdentidadeService::paraCriativo()` (Fase 170, Task 1, IDENT-02..05).
 *
 * `RefreshDatabase` porque `CreativeIdentidade::paraAncora()` consulta a
 * tabela `creative_identidades_conta` de verdade — mesma convenção de
 * `tests/Unit/Phase168/CreativePromptBuilderAmbienteTest.php`.
 */
class CreativeIdentidadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CreativeIdentidadeService
    {
        return new CreativeIdentidadeService();
    }

    private function criativo(array $overrides = []): MlAnuncioCriativo
    {
        return MlAnuncioCriativo::make(array_merge([
            'token'          => str_repeat('a', 32),
            'company_id'     => null,
            'mlb_empresa_id' => null,
        ], $overrides));
    }

    public function test_conta_por_company_id_com_identidade_devolve_o_texto(): void
    {
        $company = Company::factory()->create();

        CreativeIdentidade::create([
            'company_id' => $company->id,
            'texto'      => 'Cor principal #0A2342, fonte Montserrat.',
        ]);

        $texto = $this->service()->paraCriativo($this->criativo(['company_id' => $company->id]));

        $this->assertSame('Cor principal #0A2342, fonte Montserrat.', $texto);
    }

    public function test_conta_por_mlb_empresa_id_com_identidade_devolve_o_texto(): void
    {
        $mlbEmpresa = MlbEmpresa::create(['nome' => 'Empresa Teste Identidade', 'tipo' => 'ASSESSORIA']);

        CreativeIdentidade::create([
            'mlb_empresa_id' => $mlbEmpresa->id,
            'texto'          => 'Paleta escura, acabamento fosco.',
        ]);

        $texto = $this->service()->paraCriativo($this->criativo(['mlb_empresa_id' => $mlbEmpresa->id]));

        $this->assertSame('Paleta escura, acabamento fosco.', $texto);
    }

    public function test_conta_sem_registro_nenhum_devolve_null(): void
    {
        $company = Company::factory()->create();

        $texto = $this->service()->paraCriativo($this->criativo(['company_id' => $company->id]));

        $this->assertNull($texto);
    }

    public function test_registro_existente_com_texto_vazio_devolve_null(): void
    {
        $company = Company::factory()->create();

        CreativeIdentidade::create(['company_id' => $company->id, 'texto' => '   ']);

        $texto = $this->service()->paraCriativo($this->criativo(['company_id' => $company->id]));

        $this->assertNull($texto);
    }

    public function test_registro_existente_com_texto_null_devolve_null(): void
    {
        $company = Company::factory()->create();

        CreativeIdentidade::create(['company_id' => $company->id, 'texto' => null]);

        $texto = $this->service()->paraCriativo($this->criativo(['company_id' => $company->id]));

        $this->assertNull($texto);
    }

    public function test_para_ancora_null_null_devolve_null_sem_consultar_linha_arbitraria(): void
    {
        $company = Company::factory()->create();

        // Linha existente qualquer no banco — se `paraAncora(null, null)`
        // fizesse um WHERE vazio, devolveria esta linha por engano.
        CreativeIdentidade::create(['company_id' => $company->id, 'texto' => 'Não pode vir esta.']);

        $this->assertNull(CreativeIdentidade::paraAncora(null, null));
    }
}
