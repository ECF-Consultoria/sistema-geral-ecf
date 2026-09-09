<?php

namespace Tests\Feature\Phase139;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoAssinadoResolver;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoEnviadoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 139 Plano 04 (ADMIN-02, D-16, D-18, D5 da milestone) —
 * ContratoEnviadoResolver (item 2) e ContratoAssinadoResolver (item 3).
 *
 * Setup copiado de `tests/Feature/Phase131/ContratoAdminDetalheTest.php`
 * (`servicoComContrato()`, `empresaCompleta()`, `vincularServico()` com
 * `ContratoServico::withoutEvents()`, `Http::fake()`/`Queue::fake()` no
 * `setUp()`) — mesma blindagem contra efeito colateral de Observer.
 */
class ChecklistGrupoContratoAutoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem de ContratoAdminDetalheTest: sem isto, o
        // Observer de gatilho (Fase 128) poderia disparar geração de
        // contrato de verdade como efeito colateral do SETUP.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers (copiados de ContratoAdminDetalheTest) ────────────────────

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (checklist auto)'): Servico
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
            'razao_social'  => 'Contato de Teste LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'             => $c->id,
            'servico_id'             => $s->id,
            'valor_contratado'       => 100,
            'data_contratacao'       => now()->toDateString(),
            'data_primeira_parcela'  => now()->addMonth()->toDateString(),
            'dia_vencimento'         => 10,
            'ativo'                  => true,
        ], $overrides)));
    }

    // ─── Caso 1 — sem ContratoAssinatura: itens 2 e 3 naoColetado ──────────

    public function test_sem_contrato_assinatura_itens_2_e_3_ficam_nao_coletados(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $resultadoEnviado = (new ContratoEnviadoResolver())->resolver($empresa->fresh());
        $resultadoAssinado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultadoEnviado->ehNaoColetado());
        $this->assertTrue($resultadoAssinado->ehNaoColetado());
    }

    // ─── Caso 2 — enviado_em preenchido, assinado_em nulo ──────────────────

    public function test_envelope_enviado_mas_nao_assinado_fecha_item_2_e_deixa_item_3_pendente(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em'  => now(),
            'assinado_em' => null,
        ]);

        $resultadoEnviado = (new ContratoEnviadoResolver())->resolver($empresa->fresh());
        $resultadoAssinado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultadoEnviado->ehConcluido());
        $this->assertTrue($resultadoAssinado->ehNaoColetado());
    }

    // ─── Caso 3 — status assinado + assinado_em preenchido ─────────────────

    public function test_envelope_assinado_fecha_itens_2_e_3(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDay(),
            'assinado_em' => now(),
        ]);

        $resultadoEnviado = (new ContratoEnviadoResolver())->resolver($empresa->fresh());
        $resultadoAssinado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultadoEnviado->ehConcluido());
        $this->assertTrue($resultadoAssinado->ehConcluido());
        $this->assertSame('assinatura', $resultadoAssinado->valor[$servico->id] ?? null);
    }

    // ─── Caso 4 — D5: nada é escrito em contrato_assinaturas, nenhum HTTP ──

    public function test_d5_resolvers_nao_escrevem_em_contrato_assinaturas_e_nao_disparam_http(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $envelope = ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDay(),
            'assinado_em' => now(),
        ]);

        // ->toArray() (não ->only() direto no model) porque serializa as
        // colunas de data como string ISO — comparar Carbon por ===
        // quebraria mesmo com o mesmo instante, por serem instâncias
        // diferentes.
        $chaves = ['status', 'enviado_em', 'assinado_em', 'updated_at'];
        $snapshotAntes = array_intersect_key($envelope->fresh()->toArray(), array_flip($chaves));

        (new ContratoEnviadoResolver())->resolver($empresa->fresh());
        (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        // Esta é a asserção da D5 da milestone: o grupo Contrato LÊ o
        // envelope entregue pela v22.0, nunca o reconstrói — reconsulta ao
        // banco, nunca confiança no retorno do resolver.
        $snapshotDepois = array_intersect_key($envelope->fresh()->toArray(), array_flip($chaves));

        $this->assertSame($snapshotAntes, $snapshotDepois);
        Http::assertNothingSent();
    }
}
