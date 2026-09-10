<?php

namespace Tests\Feature\Phase152;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoAssinadoResolver;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 152 Plano 04 (D-16) — sem esta segunda fonte, uma empresa liberada
 * pela via `manual` (`ContratoAdminController::liberarManual()` ->
 * `EmpresaOperacionalRouter::liberarEmpresa()`) ficaria PERMANENTEMENTE
 * impedida de finalizar a entrada administrativa, sem saída pela tela — a
 * via manual existe exatamente para "Clicksign fora do ar, cliente assinou
 * fora do sistema" e nunca toca `contrato_assinaturas`.
 */
class ContratoAssinadoPorLiberacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        config(['services.clicksign.signatarios_ecf' => []]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (liberação D-16)'): Servico
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

    public function test_empresa_liberada_pela_via_manual_fecha_item_3_sem_tocar_contrato_assinaturas(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        // Via manual — sem nenhum envelope, sem status assinado, exatamente
        // o cenário "Clicksign fora do ar, cliente assinou fora do sistema"
        // que a D-16 cobre.
        app(EmpresaOperacionalRouter::class)->liberarEmpresa(
            $empresa,
            $servico,
            ContratoLiberacao::VIA_MANUAL,
            contrato: null,
            liberadoPorUserId: $admin->id,
            motivo: 'Cliente assinou fora do sistema, confirmado por e-mail.',
            motivoSlug: ContratoLiberacao::MOTIVO_ASSINOU_FORA_DO_SISTEMA,
        );

        $resultado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame('liberacao', $resultado->valor[$servico->id] ?? null);

        // Reconsulta ao banco — nenhuma linha de contrato_assinaturas nasceu
        // ou ganhou assinado_em/status=assinado como efeito da liberação
        // manual. É a prova de que a segunda fonte era mesmo necessária,
        // não um atalho.
        $this->assertSame(
            0,
            ContratoAssinatura::where('company_id', $empresa->id)
                ->where('servico_id', $servico->id)
                ->where(function ($query) {
                    $query->whereNotNull('assinado_em')
                        ->orWhere('status', ContratoAssinatura::STATUS_ASSINADO);
                })
                ->count()
        );
        $this->assertSame(
            0,
            ContratoAssinatura::where('company_id', $empresa->id)->where('servico_id', $servico->id)->count(),
            'A via manual nao cria nenhuma linha de contrato_assinaturas.'
        );
    }
}
