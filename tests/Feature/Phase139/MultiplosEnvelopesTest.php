<?php

namespace Tests\Feature\Phase139;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoAssinadoResolver;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoEnviadoResolver;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 139 Plano 04 (D-18) — "com 2+ envelopes ativos, manda o mais
 * atrasado". O Pitfall 3 do RESEARCH mediu que o schema permite 2+
 * envelopes simultâneos (um `ContratoAssinatura` por grupo de serviço,
 * `ContratoClicksignService::iniciarParaEmpresa()`) e que isso nunca foi
 * observado em produção — este teste é a única evidência que existe do
 * comportamento, e por isso as cinco combinações são escritas por extenso.
 */
class MultiplosEnvelopesTest extends TestCase
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

    private function servicoComContrato(string $nome): Servico
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

    /** Empresa com DOIS serviços que exigem contrato, ambos vinculados. */
    private function empresaComDoisServicos(): array
    {
        $empresa = $this->empresaCompleta();
        $servicoA = $this->servicoComContrato('Serviço A (múltiplos envelopes)');
        $servicoB = $this->servicoComContrato('Serviço B (múltiplos envelopes)');
        $this->vincularServico($empresa, $servicoA);
        $this->vincularServico($empresa, $servicoB);

        return [$empresa, $servicoA, $servicoB];
    }

    // ─── Caso 1 — A enviado, B sem envelope: item 2 pendente ───────────────

    public function test_servico_a_enviado_e_servico_b_sem_envelope_deixa_item_2_pendente(): void
    {
        [$empresa, $servicoA, $servicoB] = $this->empresaComDoisServicos();

        ContratoAssinatura::create([
            'company_id' => $empresa->id,
            'servico_id' => $servicoA->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now(),
        ]);

        $resultado = (new ContratoEnviadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehNaoColetado());
    }

    // ─── Caso 2 — A assinado, B aguardando: item 3 pendente ────────────────

    public function test_servico_a_assinado_e_servico_b_aguardando_deixa_item_3_pendente(): void
    {
        [$empresa, $servicoA, $servicoB] = $this->empresaComDoisServicos();

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servicoA->id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDay(),
            'assinado_em' => now(),
        ]);
        ContratoAssinatura::create([
            'company_id' => $empresa->id,
            'servico_id' => $servicoB->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now(),
        ]);

        $resultado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue(
            $resultado->ehNaoColetado(),
            'Um servico assinado + um pendente nao pode fechar o item 3 (D-18).'
        );
    }

    // ─── Caso 3 — os dois enviados fecha item 2; os dois assinados fecha item 3 ───

    public function test_os_dois_servicos_enviados_fecha_item_2_e_os_dois_assinados_fecha_item_3(): void
    {
        [$empresa, $servicoA, $servicoB] = $this->empresaComDoisServicos();

        foreach ([$servicoA, $servicoB] as $servico) {
            ContratoAssinatura::create([
                'company_id'  => $empresa->id,
                'servico_id'  => $servico->id,
                'status'      => ContratoAssinatura::STATUS_ASSINADO,
                'enviado_em'  => now()->subDay(),
                'assinado_em' => now(),
            ]);
        }

        $resultadoEnviado = (new ContratoEnviadoResolver())->resolver($empresa->fresh());
        $resultadoAssinado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultadoEnviado->ehConcluido());
        $this->assertTrue($resultadoAssinado->ehConcluido());
    }

    // ─── Caso 4 — A assinado por envelope, B fechado por liberação manual ──

    public function test_servico_a_assinado_por_envelope_e_servico_b_fechado_por_liberacao_manual_fecha_item_3(): void
    {
        [$empresa, $servicoA, $servicoB] = $this->empresaComDoisServicos();
        $admin = $this->admin();

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servicoA->id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDay(),
            'assinado_em' => now(),
        ]);

        app(EmpresaOperacionalRouter::class)->liberarEmpresa(
            $empresa,
            $servicoB,
            ContratoLiberacao::VIA_MANUAL,
            contrato: null,
            liberadoPorUserId: $admin->id,
            motivo: 'Cliente assinou fora do sistema, confirmado por e-mail.',
            motivoSlug: ContratoLiberacao::MOTIVO_ASSINOU_FORA_DO_SISTEMA,
        );

        $resultado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue(
            $resultado->ehConcluido(),
            'Cruzamento D-16 x D-18: um servico assinado por envelope + um fechado por liberacao fecha o item.'
        );
        $this->assertSame('assinatura', $resultado->valor[$servicoA->id] ?? null);
        $this->assertSame('liberacao', $resultado->valor[$servicoB->id] ?? null);
    }

    // ─── Caso 5 — defesa da linha morta: envelope ERRO antigo + assinado novo ──

    public function test_envelope_antigo_em_erro_nao_impede_fechamento_quando_o_vigente_e_o_mais_recente_assinado(): void
    {
        $empresa = $this->empresaCompleta();
        $servicoA = $this->servicoComContrato('Serviço A (linha morta)');
        $this->vincularServico($empresa, $servicoA);

        // Envelope MORTO — a primeira tentativa, que falhou tecnicamente.
        ContratoAssinatura::create([
            'company_id'    => $empresa->id,
            'servico_id'    => $servicoA->id,
            'status'        => ContratoAssinatura::STATUS_ERRO,
            'enviado_em'    => now()->subDays(5),
            'erro_mensagem' => 'Falha na integração Clicksign (linha morta de teste).',
        ]);

        // Envelope VIGENTE — a segunda tentativa, que teve sucesso. Id
        // maior, criado depois.
        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servicoA->id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDays(2),
            'assinado_em' => now(),
        ]);

        $resultado = (new ContratoAssinadoResolver())->resolver($empresa->fresh());

        $this->assertTrue(
            $resultado->ehConcluido(),
            'O envelope vigente e o mais recente por orderByDesc(id), nao a linha morta em erro.'
        );
        $this->assertSame('assinatura', $resultado->valor[$servicoA->id] ?? null);
    }
}
