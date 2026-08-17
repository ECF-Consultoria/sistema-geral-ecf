<?php

namespace Tests\Feature\Phase128;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fix pós-verificação da Fase 128 (achado bloqueador da revisão/verificação,
 * D-05 em `128-CONTEXT.md`): atribuir um serviço a um GRUPO inteiro de
 * empresas (`CompanyGroupController::atribuirServico()`) NUNCA pode disparar
 * o gate administrativo de contrato — o `ContratoServicoGatilhoObserver`
 * (`#[ObservedBy]` no model `ContratoServico`, plano 128-05) capturaria cada
 * `create()` do laço automaticamente e um clique em grupo de N empresas
 * geraria N `ContratoAssinatura` reais e N envelopes reais na Clicksign.
 *
 * A prova é dupla: zero efeito colateral (contrato/job/HTTP) E o
 * comportamento de negócio original preservado (contratos_servico criados
 * normalmente, empresas já com o serviço são puladas).
 */
class CompanyGroupAtribuirServicoNaoGeraContratoTest extends TestCase
{
    use RefreshDatabase;

    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoQueExige(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'           => 'Serviço Grupo 128-fix '.uniqid(),
            'valor_padrao'   => 500,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ], $overrides));
    }

    private function companyCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'email_cliente' => 'cliente@empresa.com.br',
            'cnpj'          => sprintf('%08d/0001-%02d', random_int(1, 99999999), random_int(10, 99)),
            'nome_contato'  => 'Fulano de Tal',
        ], $overrides));
    }

    #[Test]
    public function atribuir_servico_a_grupo_de_n_empresas_nao_gera_nenhum_contrato_administrativo(): void
    {
        Bus::fake();
        Http::fake();

        $group = CompanyGroup::create(['name' => 'Grupo Teste 128-fix', 'color' => '#ffe600']);
        $servico = $this->servicoQueExige();

        // Grupo com N=3 empresas, todas com dados mínimos completos —
        // elegíveis ao gate se ele disparasse.
        $empresas = collect(range(1, 3))->map(
            fn ($i) => $this->companyCompleta(['company_group_id' => $group->id, 'name' => "Empresa Grupo {$i}"])
        );

        $response = $this->actingAs($this->userAdmin())->post("/company-groups/{$group->id}/atribuir-servico", [
            'servico_id'       => $servico->id,
            'valor_contratado' => 300,
            'data_contratacao' => '2026-08-01',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        // Comportamento de negócio original preservado: 3 ContratoServico criados.
        $this->assertSame(3, ContratoServico::where('servico_id', $servico->id)->where('ativo', true)->count());
        foreach ($empresas as $empresa) {
            $this->assertTrue(
                ContratoServico::where('company_id', $empresa->id)->where('servico_id', $servico->id)->exists()
            );
        }

        // Zero efeito colateral do gate administrativo.
        $this->assertSame(0, ContratoAssinatura::count());
        Bus::assertNotDispatched(GerarContratoAssinaturaJob::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function atribuir_servico_a_grupo_pula_empresas_que_ja_tem_o_servico_ativo_sem_gerar_contrato(): void
    {
        Bus::fake();
        Http::fake();

        $group = CompanyGroup::create(['name' => 'Grupo Teste 128-fix Pulados', 'color' => '#ffe600']);
        $servico = $this->servicoQueExige();

        $companyComServico = $this->companyCompleta(['company_group_id' => $group->id, 'name' => 'Empresa Ja Tem']);
        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'       => $companyComServico->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 300,
            'data_contratacao' => '2026-01-01',
            'ativo'            => true,
        ]));

        $companyNova = $this->companyCompleta(['company_group_id' => $group->id, 'name' => 'Empresa Nova']);

        $this->actingAs($this->userAdmin())->post("/company-groups/{$group->id}/atribuir-servico", [
            'servico_id'       => $servico->id,
            'valor_contratado' => 300,
            'data_contratacao' => '2026-08-01',
        ]);

        // Só 1 novo ContratoServico criado (a que já tinha foi pulada, não duplicada).
        $this->assertSame(2, ContratoServico::where('servico_id', $servico->id)->where('ativo', true)->count());
        $this->assertTrue(
            ContratoServico::where('company_id', $companyNova->id)->where('servico_id', $servico->id)->exists()
        );

        $this->assertSame(0, ContratoAssinatura::count());
        Bus::assertNotDispatched(GerarContratoAssinaturaJob::class);
        Http::assertNothingSent();
    }
}
