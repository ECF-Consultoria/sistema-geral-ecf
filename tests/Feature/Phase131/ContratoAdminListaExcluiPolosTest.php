<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 131 Plano 03 (UI-01 / D9 da milestone) — a lista de contratos NUNCA
 * inclui empresa cujo único serviço ativo é isento de contrato (Polos).
 *
 * Regra do UI-SPEC: empresa com um serviço isento E um que exige contrato
 * aparece — com a linha do serviço que exige, sem linha para o isento.
 * Nunca omite a empresa inteira nesse caso misto.
 */
class ContratoAdminListaExcluiPolosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (exclui polos)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function servicoIsento(string $nome = 'Polos (exclui polos)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 0,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_OUTROS,
            'exige_contrato' => false,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    private function nomesDaLista(array $props): array
    {
        return collect($props['linhas']['data'])->pluck('company_nome')->unique()->values()->all();
    }

    public function test_empresa_cujo_unico_servico_ativo_e_isento_nunca_aparece_na_lista(): void
    {
        $servico = $this->servicoIsento();
        $empresa = $this->empresa(['name' => 'Empresa Só Polos']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertNotContains('Empresa Só Polos', $this->nomesDaLista($props));
    }

    public function test_empresa_com_servico_isento_e_servico_que_exige_contrato_aparece_so_com_a_linha_que_exige(): void
    {
        $servicoIsento = $this->servicoIsento();
        $servicoExige  = $this->servicoComContrato();
        $empresa       = $this->empresa(['name' => 'Empresa Mista Polos e Performance']);
        $this->vincularServico($empresa, $servicoIsento);
        $this->vincularServico($empresa, $servicoExige);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linhasDaEmpresa = collect($props['linhas']['data'])
            ->where('company_nome', 'Empresa Mista Polos e Performance')
            ->values();

        $this->assertCount(
            1,
            $linhasDaEmpresa,
            'A empresa mista deve aparecer com UMA linha só — a do serviço que exige contrato.'
        );
        $this->assertSame($servicoExige->id, $linhasDaEmpresa[0]['servico_id']);
    }
}
