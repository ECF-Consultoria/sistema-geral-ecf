<?php

namespace Tests\Feature\Phase132;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Clicksign\CongelamentoEmissaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 132 Plano 01 (D-07, Task 2) — cobre `CongelamentoEmissaoService` e a
 * recusa em `ContratoAdminController::gerarContrato()`/`show()`.
 *
 * O caso mais importante desta suíte é o de NÃO-REGRESSÃO: com o
 * interruptor desligado, tudo se comporta exatamente como a Fase 131
 * entregou. Sem ele, o interruptor vira risco de quebrar a operação normal
 * em vez de proteger.
 */
class EmissaoCongeladaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        Bus::fake();

        // Mesma blindagem da suíte da Fase 131 — sem isto o Observer poderia
        // disparar um contrato de verdade como efeito colateral do setup.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (emissão congelada)'): Servico
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

    private function empresaCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'razao_social'  => 'Contato de Teste LTDA',
            'endereco'      => 'Rua de Teste, 123',
        ], $overrides));
    }

    private function empresaIncompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => null,
            'email_cliente' => null,
            'nome_contato'  => null,
        ], $overrides));
    }

    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
        ], $overrides)));
    }

    /** Os 3 signatários da D-08, preenchidos — o estado "configurado". */
    private function signatariosEcfOk(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ];
    }

    // ─── CongelamentoEmissaoService — o estado bruto ────────────────────────

    #[Test]
    public function chave_ausente_devolve_ativo_falso_estado_normal_e_emitir(): void
    {
        $service = app(CongelamentoEmissaoService::class);

        $this->assertFalse($service->ativo());
    }

    #[Test]
    public function set_com_1_deixa_ativo_verdadeiro(): void
    {
        Configuracao::set(CongelamentoEmissaoService::CHAVE, '1');

        $this->assertTrue(app(CongelamentoEmissaoService::class)->ativo());
    }

    #[Test]
    public function valores_diferentes_de_1_string_devolvem_ativo_falso(): void
    {
        $service = app(CongelamentoEmissaoService::class);

        foreach (['0', '', 'true', 'sim'] as $valor) {
            Configuracao::set(CongelamentoEmissaoService::CHAVE, $valor);
            $this->assertFalse($service->ativo(), "Valor \"{$valor}\" deveria manter ativo() falso.");
        }
    }

    #[Test]
    public function ligar_e_desligar_alternam_o_estado_e_sao_idempotentes(): void
    {
        $service = app(CongelamentoEmissaoService::class);

        $service->ligar();
        $this->assertTrue($service->ativo());
        $service->ligar(); // chamar duas vezes seguidas não quebra nada
        $this->assertTrue($service->ativo());

        $service->desligar();
        $this->assertFalse($service->ativo());
        $service->desligar();
        $this->assertFalse($service->ativo());
    }

    // ─── Interruptor LIGADO — recusa a geração ──────────────────────────────

    #[Test]
    public function ligado_recusa_gerar_contrato_para_empresa_apta_e_elegivel_sem_criar_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Apta Congelada']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        app(CongelamentoEmissaoService::class)->ligar();

        $antes = ContratoAssinatura::count();

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        // Reconsulta ao banco — nunca confia na mensagem de erro/sucesso.
        $this->assertSame($antes, ContratoAssinatura::count());
        $this->assertSame(0, ContratoAssinatura::where('company_id', $empresa->id)->count());

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function ligado_devolve_pode_gerar_contrato_falso_e_motivo_emissao_congelada_mesmo_com_cadastro_completo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Completa Congelada']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        app(CongelamentoEmissaoService::class)->ligar();

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('pode_gerar_contrato', false)
            ->where('motivo_bloqueio', 'emissao_congelada'));
    }

    #[Test]
    public function ligado_e_com_dados_faltando_motivo_bloqueio_continua_sendo_emissao_congelada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Incompleta Congelada']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        app(CongelamentoEmissaoService::class)->ligar();

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('pode_gerar_contrato', false)
            ->where('motivo_bloqueio', 'emissao_congelada'));
    }

    // ─── Interruptor DESLIGADO — não-regressão da Fase 131 ──────────────────

    #[Test]
    public function desligado_gerar_contrato_para_empresa_completa_se_comporta_como_a_fase_131(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Pronta Desligado']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        // Estado explícito — o padrão já é desligado, mas o teste não deixa
        // ao acaso do default.
        app(CongelamentoEmissaoService::class)->desligar();

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        // Reconsulta ao banco — nunca confia na mensagem de sucesso da tela.
        $contrato = ContratoAssinatura::where('company_id', $empresa->id)->first();
        $this->assertNotNull($contrato);
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $contrato->status);
    }

    #[Test]
    public function desligado_motivo_bloqueio_volta_a_ser_o_que_a_fase_131_devolvia(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Incompleta Desligado']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        app(CongelamentoEmissaoService::class)->desligar();

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('pode_gerar_contrato', false)
            ->where('motivo_bloqueio', 'dados_minimos'));
    }
}
