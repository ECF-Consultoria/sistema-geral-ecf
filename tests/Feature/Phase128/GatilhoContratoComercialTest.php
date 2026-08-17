<?php

namespace Tests\Feature\Phase128;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Fase 128 Plano 04 (SC1 estrutural / SC2 / SC3) — prova, pelo caminho HTTP
 * REAL de `ComercialController::store()`, que o cadastro manual chega ao
 * MESMO gate administrativo do caminho HubSpot.
 *
 * ⚠️ Esta suíte NÃO é a prova do Success Criteria 1. `Bus::fake()` confirma
 * alegremente um payload errado — cinco bugs desta milestone nasceram
 * exatamente disso. O que estas suítes provam é a FIAÇÃO: o gate é chamado,
 * na ordem certa, com o resultado certo. A prova do envelope real é o gate
 * humano do plano 06, contra o sandbox.
 */
class GatilhoContratoComercialTest extends TestCase
{
    use RefreshDatabase;

    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function fakeSignatariosEcf(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Socio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Socio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ]]);
    }

    private function servicoGestao(): Servico
    {
        return Servico::create([
            'nome'           => 'Gestão',
            'valor_padrao'   => 1000,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    // ─── Cenário 1 — dados completos (SC2: mesmo resultado do HubSpot) ─────

    /**
     * `store()` com serviço que exige contrato e dados completos — inclusive
     * `nome_contato`, que o Comercial coleta na rota — dispara o MESMO
     * resultado do caminho HubSpot: 1 ContratoAssinatura, 1 job.
     */
    public function test_store_com_dados_completos_dispara_contrato_e_job(): void
    {
        $this->fakeSignatariosEcf();
        Bus::fake();

        $servico = $this->servicoGestao();

        $response = $this->actingAs($this->userAdmin())->post('/comercial/empresas', [
            'nome'          => 'Empresa Gate 128 Comercial Completa',
            'cnpj'          => '12.345.678/0001-99',
            'email_cliente' => 'cliente@empresagate128.com.br',
            'nome_contato'  => 'Fulano de Tal',
            'servicos'      => [
                ['servico_id' => $servico->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Gate 128 Comercial Completa')->firstOrFail();

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->count());
        Bus::assertDispatched(GerarContratoAssinaturaJob::class, 1);
    }

    // ─── Cenário 2 — nome_contato vazio bloqueia (SC3 pelo controller real) ─

    /**
     * `store()` com `nome_contato` vazio: zero ContratoAssinatura — o gate
     * fica `aguardando_comercial` no 1º portão (pendência `sem_contato`),
     * mesmo com todos os outros dados completos. Prova o SC3 pelo caminho
     * HTTP real do controller, não pela chamada direta ao service.
     */
    public function test_store_com_nome_contato_vazio_nao_gera_contrato(): void
    {
        $this->fakeSignatariosEcf();
        Bus::fake();

        $servico = $this->servicoGestao();

        $response = $this->actingAs($this->userAdmin())->post('/comercial/empresas', [
            'nome'          => 'Empresa Gate 128 Comercial Sem Contato',
            'cnpj'          => '98.765.432/0001-88',
            'email_cliente' => 'cliente@empresagate128semcontato.com.br',
            'servicos'      => [
                ['servico_id' => $servico->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Gate 128 Comercial Sem Contato')->firstOrFail();

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
        Bus::assertNotDispatched(GerarContratoAssinaturaJob::class);
    }
}
