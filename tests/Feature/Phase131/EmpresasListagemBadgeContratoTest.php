<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 131 Plano 02 (UI-03/D-08) — badge de contrato na listagem do
 * Comercial (`ComercialController::listagem()`).
 *
 * Nasce na Task 1 (3 casos de conteúdo do badge) e é COMPLETADO na Task 3
 * (contrato mais recente + ausência de N+1) — mesmo arquivo, sem reescrita.
 *
 * Mesma disciplina do resto da fase: conferência por reconsulta ao banco, e
 * tempo fixado com `Carbon::setTestNow()` para os dias virem determinísticos.
 */
class EmpresasListagemBadgeContratoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (badge)'): Servico
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

    /** Serviço isento de contrato — caso Polos (D9 da milestone). */
    private function servicoIsento(string $nome = 'Polos (badge)'): Servico
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

    private function linhaDaEmpresa(array $props, int $companyId): ?array
    {
        return collect($props['companies']['data'])->firstWhere('id', $companyId);
    }

    // ─── Task 1: os três casos de conteúdo ────────────────────────────────

    public function test_empresa_com_contrato_aguardando_assinaturas_ha_6_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Badge Aguardando']);
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDays(6),
        ]);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $row['contrato_badge']['status']);
        $this->assertSame(6, $row['contrato_badge']['dias']);
    }

    public function test_empresa_com_servico_que_exige_contrato_e_sem_contrato_assinatura_mostra_aguardando_administrativo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Badge Sem Contrato']);
        $empresa->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertSame('aguardando_administrativo', $row['contrato_badge']['status']);
        $this->assertSame(3, $row['contrato_badge']['dias']);
    }

    public function test_empresa_cujo_unico_servico_ativo_e_isento_de_contrato_mostra_badge_nulo(): void
    {
        $servico = $this->servicoIsento();
        $empresa = $this->empresa(['name' => 'Empresa Badge Polos']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertNull(
            $row['contrato_badge'],
            'Empresa cujo único serviço ativo é isento (D9) nunca deve receber "aguardando_administrativo".'
        );
    }
}
