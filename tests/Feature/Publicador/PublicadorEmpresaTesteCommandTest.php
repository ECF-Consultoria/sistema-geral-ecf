<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fase 160 / 160-06: ferramenta do D20 (só no SQLite do teste; nunca executada contra banco real na fase). */
class PublicadorEmpresaTesteCommandTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $c = Company::factory()->create();
        Company::query()->whereKey($c->id)->update(['id' => 459]);

        return Company::findOrFail(459);
    }

    public function test_sem_confirmar_so_simula(): void
    {
        $this->company();

        $this->artisan('publicador:empresa-teste')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertExitCode(0);

        $this->assertSame(0, MlbEmpresa::count());
    }

    public function test_confirmar_cria_a_empresa_na_incubadora(): void
    {
        $this->company();

        $this->artisan('publicador:empresa-teste', ['--confirmar' => true])->assertExitCode(0);

        $e = MlbEmpresa::firstOrFail()->fresh();
        $this->assertSame('Dev 02 Testes API', $e->nome);
        $this->assertSame('INCUBADORA', $e->tipo);
        $this->assertSame('Incubadora', $e->projeto);
        $this->assertSame(459, $e->company_id);
        $this->assertSame('incubadora', $e->programaPublicador());
        $this->assertEmpty($e->cust_id);
    }

    public function test_rodar_de_novo_nao_cria_outra(): void
    {
        $this->company();
        $this->artisan('publicador:empresa-teste', ['--confirmar' => true])->assertExitCode(0);
        $id = MlbEmpresa::firstOrFail()->id;

        $this->artisan('publicador:empresa-teste', ['--confirmar' => true])
            ->expectsOutputToContain("#{$id}")
            ->assertExitCode(0);

        $this->assertSame(1, MlbEmpresa::count());
    }

    public function test_company_inexistente_sai_com_erro_sem_gravar(): void
    {
        $this->artisan('publicador:empresa-teste', ['--confirmar' => true])->assertExitCode(1);

        $this->assertSame(0, MlbEmpresa::count());
    }

    public function test_nao_altera_a_company(): void
    {
        $c = $this->company();
        $antes = Company::query()->whereKey($c->id)->first()->getAttributes();

        $this->artisan('publicador:empresa-teste', ['--confirmar' => true])->assertExitCode(0);

        $this->assertEquals($antes, Company::query()->whereKey($c->id)->first()->getAttributes());
    }
}
