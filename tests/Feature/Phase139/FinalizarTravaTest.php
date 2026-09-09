<?php

namespace Tests\Feature\Phase139;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\MlToken;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use App\Services\ChecklistAdministrativo\FinalizarEntradaAdministrativaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 139 Plano 06 (ADMIN-05, D-07) —
 * `FinalizarEntradaAdministrativaService::podeFinalizar()`: a régua PURA da
 * trava do FINALIZAR. Recusa enquanto falta item ou contrato, libera no
 * instante exato, e libera empresa isenta sem nenhum contrato (D-07).
 *
 * Setup copiado de `ChecklistMarcacaoManualAutoriaTest.php` (Fase 139 Plano
 * 05) — `servicoComContrato()`/`empresaCompleta()`/`vincularServico()` com
 * `ContratoServico::withoutEvents()`, `Http::fake()`/`Queue::fake()` no
 * `setUp()`, mesma blindagem contra efeito colateral de Observer.
 */
class FinalizarTravaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem de ContratoAdminDetalheTest (Fase 131).
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function servicoComContrato(): Servico
    {
        return Servico::create([
            'nome'           => 'Gestão de Tráfego (trava 139-06)',
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
            'nome'           => 'Polos (trava 139-06)',
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

    private function service(): FinalizarEntradaAdministrativaService
    {
        return app(FinalizarEntradaAdministrativaService::class);
    }

    private function checklistService(): ChecklistAdministrativoService
    {
        return app(ChecklistAdministrativoService::class);
    }

    /**
     * Completa os 6 itens manuais/automáticos do grupo Entrada — os 4
     * itens manuais (D-13) via `concluirManualmente()`, e os itens 7/8
     * fechando os automáticos pelo ESTADO REAL (nunca gravando
     * `status = concluido` direto na tabela do checklist).
     */
    private function completarGrupoEntrada(Company $empresa, User $usuario): void
    {
        foreach (['grupo_whatsapp_criado', 'email_colaborador_criado', 'link_adman_entregue', 'boas_vindas_enviada'] as $chave) {
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

        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'token-trava-139-06-' . $empresa->id]);
    }

    /**
     * Completa os 3 itens do grupo Contrato: item 1 manual, itens 2/3
     * automáticos por envelope Clicksign REALMENTE assinado (nunca gravando
     * o item direto).
     */
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

    // ─── Caso 1 — empresa recém-criada, nenhum item concluído ──────────────

    public function test_empresa_recem_criada_sem_nenhum_item_e_recusada(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $resultado = $this->service()->podeFinalizar($empresa->fresh());

        $this->assertFalse($resultado['permitido']);
        $this->assertNotNull($resultado['requisito_faltante']);
    }

    // ─── Caso 2 — 8 de 9 concluídos, faltando só contrato_assinado ─────────

    public function test_faltando_so_contrato_assinado_e_recusada_com_requisito_falando_de_contrato(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->checklistService()->concluirManualmente($empresa, 'contrato_revisado', $usuario);
        // Envelope ENVIADO (fecha item 2), mas NÃO assinado (item 3 continua pendente).
        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em'  => now(),
            'assinado_em' => null,
        ]);
        $this->completarGrupoEntrada($empresa, $usuario);

        $resultado = $this->service()->podeFinalizar($empresa->fresh());

        $this->assertFalse($resultado['permitido']);
        $this->assertStringContainsString('contrato', mb_strtolower((string) $resultado['requisito_faltante']));
        $this->assertStringNotContainsString('falta', mb_strtolower((string) $resultado['requisito_faltante']));
    }

    // ─── Caso 3 — os 9 concluídos: permitido no instante exato ─────────────

    public function test_com_os_9_itens_concluidos_e_permitido_e_requisito_faltante_e_nulo(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $resultado = $this->service()->podeFinalizar($empresa->fresh());

        $this->assertTrue($resultado['permitido']);
        $this->assertNull($resultado['requisito_faltante']);
    }

    // ─── Caso 4 — empresa ISENTA com os 6 itens concluídos: permitido sem contrato (D-07) ──

    public function test_empresa_isenta_com_os_6_itens_e_permitida_mesmo_sem_nenhum_contrato_assinatura(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = $this->usuario();

        $this->completarGrupoEntrada($empresa, $usuario);

        $this->assertSame(0, ContratoAssinatura::where('company_id', $empresa->id)->count());

        $resultado = $this->service()->podeFinalizar($empresa->fresh());

        $this->assertTrue($resultado['permitido']);
        $this->assertNull($resultado['requisito_faltante']);
    }

    // ─── Caso 5 — empresa isenta com 5 de 6: recusada ───────────────────────

    public function test_empresa_isenta_com_5_de_6_itens_e_recusada(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = $this->usuario();

        foreach (['grupo_whatsapp_criado', 'email_colaborador_criado', 'link_adman_entregue'] as $chave) {
            $this->checklistService()->concluirManualmente($empresa, $chave, $usuario);
        }
        // 'boas_vindas_enviada' fica de fora, de propósito — mas os itens
        // automáticos 7/8 fecham normalmente.
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
        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'token-trava-139-06-caso5-' . $empresa->id]);

        $resultado = $this->service()->podeFinalizar($empresa->fresh());

        $this->assertFalse($resultado['permitido']);
        $this->assertNotNull($resultado['requisito_faltante']);
    }

    // ─── Caso 6 — desmarcar um item já concluído volta permitido para false ──

    public function test_desmarcar_um_item_ja_concluido_volta_permitido_para_false(): void
    {
        $empresa = $this->empresaCompleta();
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $resultadoAntes = $this->service()->podeFinalizar($empresa->fresh());
        $this->assertTrue($resultadoAntes['permitido']);

        $this->checklistService()->reabrirItem($empresa, 'grupo_whatsapp_criado', $usuario);

        $resultadoDepois = $this->service()->podeFinalizar($empresa->fresh());

        $this->assertFalse($resultadoDepois['permitido']);
        $this->assertNotNull($resultadoDepois['requisito_faltante']);
    }
}
