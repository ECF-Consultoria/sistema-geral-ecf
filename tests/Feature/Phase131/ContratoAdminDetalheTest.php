<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Contratos\ContratoDadosMinimosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 131 Plano 04 (ADM-01/ADM-02/UI-02, D-01/D-03/D-11) —
 * ContratoAdminController::show()/atualizarCadastro()/gerarContrato().
 *
 * Nasce na Task 1 com os casos 1, 2, 8 e 11 (o núcleo que aquela task
 * entrega — incluindo o BLOCKER da correção: `gerarContrato()` não pode
 * anunciar sucesso quando `faltantesDaConfiguracaoEcf()` bloqueia por dentro
 * de `iniciarParaEmpresa()`) e é COMPLETADO na Task 3, no MESMO arquivo —
 * regra do "teste nasce na mesma task do código que ele prova" (armadilha do
 * `--filter` sem match que sai 0 e varre a suíte).
 *
 * Mesma disciplina do resto da fase: conferência por RECONSULTA ao banco,
 * nunca por stdout nem pela mensagem de sucesso da tela.
 */
class ContratoAdminDetalheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Blindagem por padrão: a reavaliação automática do Observer (Fase
        // 128) roda SÍNCRONA quando Company/ContratoServico são salvos com
        // campos-gatilho alterados — inclusive dentro desta suíte, que só
        // faz `atualizarCadastro()`/`show()`. Sem isto, um teste que deixa a
        // empresa completa e elegível poderia disparar um contrato de
        // verdade como efeito colateral do Observer, fora do que o próprio
        // teste está medindo. Cada teste que precisa do caminho feliz
        // (`gerarContrato()`) sobrescreve este config explicitamente.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
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

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (detalhe admin)'): Servico
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

    private function empresaIncompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => null,
            'email_cliente' => null,
            'nome_contato'  => null,
        ], $overrides));
    }

    private function empresaCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
        ], $overrides));
    }

    /**
     * `withoutEvents`: sem isto, `ContratoServico::create()` dispara o
     * `ContratoServicoGatilhoObserver` como efeito colateral do SETUP, antes
     * da chamada explícita que cada teste está medindo — mesmo cuidado do
     * `ContratoClicksignServiceTest` da Fase 127.
     */
    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ], $overrides)));
    }

    // ─── Caso 1 — empresa incompleta: 200 + componente + faltantes bate + pode_gerar_contrato false ───

    public function test_show_de_empresa_incompleta_devolve_200_componente_e_faltantes_batendo_com_o_service(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Incompleta Detalhe']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/ContratoDetalhe'));

        $props = $response->viewData('page')['props'];

        $esperado = app(ContratoDadosMinimosService::class)->faltantes($empresa->fresh());
        $this->assertSame($esperado, $props['faltantes']);
        $this->assertNotEmpty($props['faltantes'], 'a fixture precisa ficar incompleta de propósito para este caso.');
        $this->assertFalse($props['pode_gerar_contrato']);
    }

    // ─── Caso 2 — empresa completa, sem contrato em andamento: pode_gerar_contrato true e faltantes vazio ───

    public function test_show_de_empresa_completa_sem_contrato_em_andamento_permite_gerar(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Completa Detalhe']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame([], $props['faltantes']);
        $this->assertTrue($props['pode_gerar_contrato']);
        $this->assertNull($props['motivo_bloqueio']);
    }

    // ─── Caso 8 — POST gerar para empresa incompleta: 422 e ZERO ContratoAssinatura ───

    public function test_gerar_contrato_para_empresa_incompleta_devolve_422_e_nao_cria_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Incompleta Gerar']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertStatus(422);
        // Reconsulta ao banco — nunca confia só no status HTTP.
        $this->assertSame(0, ContratoAssinatura::where('company_id', $empresa->id)->count());
    }

    // ─── Caso 11 — o BLOCKER: elegível, mas configuração da ECF faltando ───
    // `disparado` NÃO é sucesso quando `resultado.ok` é falso — a tela nunca
    // pode dizer "Contrato gerado" com zero ContratoAssinatura criado.

    public function test_gerar_contrato_com_empresa_elegivel_mas_configuracao_da_ecf_faltando_devolve_erro_sem_criar_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Elegível Sem Config ECF']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        // Estado padrão de qualquer ambiente recém-configurado (ver docblock
        // de ContratoDadosMinimosService::faltantesDaConfiguracaoEcf()) —
        // reforçado explicitamente aqui, não deixado ao acaso do setUp.
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => '', 'email' => '', 'papel' => 'contratada'],
        ]]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        $mensagem = session('error');
        $this->assertStringContainsString('configuração interna da ECF', $mensagem);
        $this->assertStringContainsString('time técnico', $mensagem);

        // Reconsulta ao banco — a prova real de que nada foi criado, nunca a
        // mensagem de sucesso/erro da tela.
        $this->assertSame(0, ContratoAssinatura::where('company_id', $empresa->id)->count());

        Http::assertNothingSent();
    }
}
