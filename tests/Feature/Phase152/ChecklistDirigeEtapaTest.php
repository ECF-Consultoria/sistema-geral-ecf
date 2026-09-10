<?php

namespace Tests\Feature\Phase152;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\MlToken;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use App\Services\ChecklistAdministrativo\ChecklistEtapaSincronizadorService;
use App\Services\ChecklistAdministrativo\FinalizarEntradaAdministrativaService;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 152 Plano 07 (D-15) —
 * `ChecklistEtapaSincronizadorService::sincronizar()`: as três transições
 * dirigidas pelo checklist (1→2, 2→3, 2/3→4), o salto 2→4 da empresa isenta
 * (D-07), a não-regressão de etapa, o legado e a prova de que o grafo de
 * injeção entre os três serviços do checklist é acíclico.
 *
 * Este teste exercita o sincronizador DIRETAMENTE, no nível de service. A
 * prova de que os caminhos HTTP disparam a sincronização é do plano 152-08
 * (`ChecklistEndpointsTest`).
 *
 * Setup copiado de `FinalizarTravaTest.php`/`FinalizarTransicaoEtapaTest.php`
 * (Fase 152 Plano 06) — mesma blindagem de `Http::fake()`/`Queue::fake()`/
 * `config(['services.clicksign.signatarios_ecf' => []])` contra efeito
 * colateral de Observer.
 */
class ChecklistDirigeEtapaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function servicoComContrato(): Servico
    {
        return Servico::create([
            'nome'           => 'Gestão de Tráfego (sincronizador 152-07)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function servicoIsento(): Servico
    {
        return Servico::create([
            'nome'           => 'Polos (sincronizador 152-07)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_POLOS,
            'exige_contrato' => false,
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
            'company_id'            => $c->id,
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'ativo'                 => true,
        ], $overrides)));
    }

    private function usuario(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function sincronizador(): ChecklistEtapaSincronizadorService
    {
        return app(ChecklistEtapaSincronizadorService::class);
    }

    private function checklistService(): ChecklistAdministrativoService
    {
        return app(ChecklistAdministrativoService::class);
    }

    /** Completa os 6 itens do grupo Entrada pelo estado real (nunca por status=concluido direto). */
    private function completarGrupoEntrada(Company $empresa, User $usuario, array $manuaisAIgnorar = []): void
    {
        foreach (['grupo_whatsapp_criado', 'email_colaborador_criado', 'link_adman_entregue', 'boas_vindas_enviada'] as $chave) {
            if (in_array($chave, $manuaisAIgnorar, true)) {
                continue;
            }

            $this->checklistService()->concluirManualmente($empresa, $chave, $usuario);
        }

        MlToken::create([
            'company_id'        => $empresa->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'token-sincronizador-152-07-' . $empresa->id]);
    }

    /** Completa os 3 itens do grupo Contrato — item 1 manual, 2/3 por envelope REALMENTE assinado. */
    private function completarGrupoContrato(Company $empresa, Servico $servico, User $usuario): void
    {
        $this->checklistService()->concluirManualmente($empresa, 'contrato_revisado', $usuario);

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDay(),
            'assinado_em' => now(),
        ]);
    }

    // ─── Caso 1 — 1º item manual concluído: etapa 1 → 2 ─────────────────────

    public function test_primeiro_item_concluido_leva_a_administrativo_andamento(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->checklistService()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([Company::ETAPA_ADMINISTRATIVO_ANDAMENTO], $resultado['transicoes']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $resultado['etapa_final']);

        // Reconsulta ao banco — nunca confiar só no objeto em memória.
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, Company::findOrFail($empresa->id)->etapa);

        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)
            ->where('etapa_nova', Company::ETAPA_ADMINISTRATIVO_ANDAMENTO)
            ->first();

        $this->assertNotNull($linha);
        $this->assertSame($usuario->id, $linha->user_id);
    }

    // ─── Caso 2 — envelope enviado: etapa 2 → 3 ──────────────────────────────

    public function test_envelope_enviado_leva_a_aguardando_assinatura(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em'  => now(),
            'assinado_em' => null,
        ]);

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([Company::ETAPA_AGUARDANDO_ASSINATURA], $resultado['transicoes']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ASSINATURA, Company::findOrFail($empresa->id)->etapa);
    }

    // ─── Caso 3 — tudo concluído + contrato assinado: etapa 3 → 4 ───────────

    public function test_tudo_concluido_e_contrato_assinado_leva_a_administrativo_concluido(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_AGUARDANDO_ASSINATURA]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([Company::ETAPA_ADMINISTRATIVO_CONCLUIDO], $resultado['transicoes']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, Company::findOrFail($empresa->id)->etapa);
    }

    // ─── Caso 4 — salto 2→4 (D-15/D-07): empresa isenta nunca passa pela 3 ──

    public function test_empresa_isenta_salta_de_administrativo_andamento_direto_para_concluido(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = $this->usuario();

        $this->completarGrupoEntrada($empresa, $usuario);

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([Company::ETAPA_ADMINISTRATIVO_CONCLUIDO], $resultado['transicoes']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, Company::findOrFail($empresa->id)->etapa);

        // Nunca passou por aguardando_assinatura — sem envelope não existe etapa 3 (D-07).
        $this->assertDatabaseMissing('company_etapa_transicoes', [
            'company_id' => $empresa->id,
            'etapa_nova' => Company::ETAPA_AGUARDANDO_ASSINATURA,
        ]);
    }

    // ─── Caso 5 — cadeia numa chamada só: 1 → 2 → 4, cada degrau com linha própria ──

    public function test_cadeia_numa_chamada_so_leva_de_aguardando_administrativo_a_concluido(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        // Tudo pronto ANTES da chamada — automáticos pelo estado real,
        // manuais marcados de propósito.
        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame(
            [Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, Company::ETAPA_ADMINISTRATIVO_CONCLUIDO],
            $resultado['transicoes']
        );
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, $resultado['etapa_final']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, Company::findOrFail($empresa->id)->etapa);

        // Cada degrau vira linha PRÓPRIA de histórico — 2 linhas, não 1.
        $this->assertSame(2, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
        $this->assertDatabaseHas('company_etapa_transicoes', [
            'company_id' => $empresa->id,
            'etapa_nova' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
        ]);
        $this->assertDatabaseHas('company_etapa_transicoes', [
            'company_id' => $empresa->id,
            'etapa_nova' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
        ]);
    }

    // ─── Caso 6 — não retrocede: desmarcar item na etapa 4 não devolve à 3 ──

    public function test_desmarcar_item_na_etapa_4_nao_retrocede_a_empresa(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);
        $this->checklistService()->reabrirItem($empresa, 'grupo_whatsapp_criado', $usuario);

        $totalAntes = CompanyEtapaTransicao::where('company_id', $empresa->id)->count();

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([], $resultado['transicoes']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, Company::findOrFail($empresa->id)->etapa);
        $this->assertSame($totalAntes, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }

    // ─── Caso 7 — legado: etapa null nunca é carimbada ──────────────────────

    public function test_empresa_legada_com_etapa_null_nunca_e_carimbada(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => null]);
        $usuario = $this->usuario();

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([], $resultado['transicoes']);
        $this->assertNull($resultado['etapa_final']);
        $this->assertNull(Company::findOrFail($empresa->id)->etapa);

        $this->assertDatabaseMissing('company_etapa_transicoes', [
            'company_id' => $empresa->id,
        ]);
    }

    // ─── Caso 8 — idempotência: 2ª chamada sem mudança não cria transição ──

    public function test_segunda_chamada_sem_mudanca_nao_cria_nova_transicao(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->checklistService()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);

        $primeiraChamada = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);
        $this->assertSame([Company::ETAPA_ADMINISTRATIVO_ANDAMENTO], $primeiraChamada['transicoes']);

        $totalAntes = CompanyEtapaTransicao::where('company_id', $empresa->id)->count();

        $segundaChamada = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([], $segundaChamada['transicoes']);
        $this->assertSame($totalAntes, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }

    // ─── Caso 9 — fora de escopo: etapa 5 não é responsabilidade deste service ──

    public function test_empresa_em_aguardando_distribuicao_nao_e_tocada(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_AGUARDANDO_DISTRIBUICAO]);
        $usuario = $this->usuario();

        $resultado = $this->sincronizador()->sincronizar($empresa->fresh(), $usuario);

        $this->assertSame([], $resultado['transicoes']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, Company::findOrFail($empresa->id)->etapa);
    }

    // ─── Caso 10 — grafo de injeção acíclico (defesa do BLOCKER) ────────────

    /**
     * Um construtor recíproco entre `ChecklistAdministrativoService`,
     * `FinalizarEntradaAdministrativaService` e
     * `ChecklistEtapaSincronizadorService` fecha um ciclo no autowiring do
     * container — `Illuminate\Contracts\Container\CircularDependencyException`
     * — e derruba com 500 toda rota que type-hinte qualquer um dos três.
     * Essa regressão é fácil de reintroduzir na execução (ex.: alguém
     * "conveniente" injeta o sincronizador de volta no
     * `ChecklistAdministrativoService`), NÃO falha em `php -l`, e só aparece
     * em runtime — por isso este teste resolve os três serviços pelo
     * container na mesma execução.
     */
    public function test_container_resolve_os_tres_servicos_do_checklist_sem_ciclo_de_injecao(): void
    {
        try {
            $checklist = app(ChecklistAdministrativoService::class);
            $finalizar = app(FinalizarEntradaAdministrativaService::class);
            $sincronizador = app(ChecklistEtapaSincronizadorService::class);
        } catch (CircularDependencyException $e) {
            $this->fail('Ciclo de injeção detectado entre os serviços do checklist administrativo: ' . $e->getMessage());

            return;
        }

        $this->assertInstanceOf(ChecklistAdministrativoService::class, $checklist);
        $this->assertInstanceOf(FinalizarEntradaAdministrativaService::class, $finalizar);
        $this->assertInstanceOf(ChecklistEtapaSincronizadorService::class, $sincronizador);
    }
}
