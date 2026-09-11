<?php

namespace Tests\Feature\Phase152;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\CompanyMarketplace;
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
 * Fase 152 Plano 06 (ADMIN-06, D-12) —
 * `FinalizarEntradaAdministrativaService::finalizar()`: transição 4→5 pela
 * única porta permitida, mesmo cadastro (nenhum registro novo), histórico
 * gravado, e destino de marketplace determinístico mesmo com `is_primary`
 * duplicado na pivot.
 *
 * Setup copiado de `FinalizarTravaTest.php` (mesmo plano) — mesma
 * blindagem de `Http::fake()`/`Queue::fake()`/`config(['services.clicksign
 * .signatarios_ecf' => []])` contra efeito colateral de Observer.
 */
class FinalizarTransicaoEtapaTest extends TestCase
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
            'nome'           => 'Gestão de Tráfego (transição 152-06)',
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
            'nome'           => 'Polos (transição 152-06)',
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

        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'token-transicao-152-06-' . $empresa->id]);
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

    // ─── Caso 1 — etapa 4 com os 9 itens: finalizado, etapa = 5 por reconsulta ──

    public function test_com_checklist_completo_na_etapa_4_finaliza_e_grava_etapa_5_por_reconsulta(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $resultado = $this->service()->finalizar($empresa->fresh(), $usuario);

        $this->assertSame('finalizado', $resultado['status']);
        $this->assertNull($resultado['requisito_faltante']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $resultado['etapa']);

        // Reconsulta ao banco — nunca confiar só na resposta do método.
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, Company::findOrFail($empresa->id)->etapa);
    }

    // ─── Caso 2 — ADMIN-06: mesmo company_id, nenhum registro novo ─────────

    public function test_finalizar_move_a_mesma_empresa_sem_criar_cadastro_novo(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $totalAntes = Company::count();
        $idAntes = $empresa->id;

        $resultado = $this->service()->finalizar($empresa->fresh(), $usuario);

        $this->assertSame('finalizado', $resultado['status']);
        $this->assertSame($idAntes, $empresa->id);
        $this->assertSame($totalAntes, Company::count());
        $this->assertSame(1, Company::where('id', $idAntes)->count());
    }

    // ─── Caso 3 — linha nova em company_etapa_transicoes com o user_id certo ──

    public function test_finalizar_grava_linha_de_historico_com_o_user_id_do_ator(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        $this->service()->finalizar($empresa->fresh(), $usuario);

        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_DISTRIBUICAO)
            ->first();

        $this->assertNotNull($linha);
        $this->assertSame($usuario->id, $linha->user_id);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, $linha->etapa_anterior);
    }

    // ─── Caso 4 — etapa 4 com 1 item pendente: recusado, etapa continua 4 ──

    public function test_com_1_item_pendente_na_etapa_4_e_recusado_e_etapa_continua_4(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        // 'boas_vindas_enviada' fica de fora — 1 item pendente de propósito.
        $this->completarGrupoEntrada($empresa, $usuario, ['boas_vindas_enviada']);

        $resultado = $this->service()->finalizar($empresa->fresh(), $usuario);

        $this->assertSame('recusado', $resultado['status']);
        $this->assertNotNull($resultado['requisito_faltante']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, Company::findOrFail($empresa->id)->etapa);
    }

    // ─── Caso 5 — etapa 2 com checklist completo: recusado pela máquina de estados ──

    /**
     * ⚠️ Isto mede o service SOZINHO. Pela ROTA, o mesmo cenário termina em
     * `aguardando_distribuicao`, porque o controller sincroniza a etapa
     * antes de chamar `finalizar()` (caso do plano 152-08). Os dois casos
     * são verdadeiros e complementares — a trava do checklist não contorna
     * a trava da máquina de estados quando chamada isoladamente.
     */
    public function test_com_checklist_completo_mas_na_etapa_2_e_recusado_pela_maquina_de_estados(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        $usuario = $this->usuario();

        $this->completarGrupoContrato($empresa, $servico, $usuario);
        $this->completarGrupoEntrada($empresa, $usuario);

        // A régua do checklist, isolada, libera — a recusa abaixo vem da
        // máquina de estados, não do checklist.
        $avaliacaoChecklist = $this->service()->podeFinalizar($empresa->fresh());
        $this->assertTrue($avaliacaoChecklist['permitido']);

        $resultado = $this->service()->finalizar($empresa->fresh(), $usuario);

        $this->assertSame('recusado', $resultado['status']);
        $this->assertNotNull($resultado['requisito_faltante']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, Company::findOrFail($empresa->id)->etapa);
    }

    // ─── Caso 6 — D-12 defesa: is_primary duplicado, destino determinístico ──

    public function test_is_primary_duplicado_resolve_destino_pela_linha_de_menor_id_de_forma_deterministica(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = $this->usuario();

        $this->completarGrupoEntrada($empresa, $usuario);

        $marketplaceMenorId = CompanyMarketplace::create([
            'company_id' => $empresa->id,
            'marketplace' => 'meli',
            'is_primary' => true,
            'active' => true,
            'integracao_status' => 'ativa',
        ]);
        $marketplaceMaiorId = CompanyMarketplace::create([
            'company_id' => $empresa->id,
            'marketplace' => 'shopee',
            'is_primary' => true,
            'active' => true,
            'integracao_status' => 'ativa',
        ]);

        $this->assertTrue($marketplaceMenorId->id < $marketplaceMaiorId->id);

        $resultado = $this->service()->finalizar($empresa->fresh(), $usuario);

        $this->assertSame('finalizado', $resultado['status']);
        $this->assertSame('meli', $resultado['marketplace_destino']);

        // Roda a asserção duas vezes na mesma execução — afirma que o
        // desempate é DETERMINÍSTICO, não que aconteceu de dar 'meli' uma
        // vez por acaso.
        $segundaLeitura = $this->service()->marketplaceDestino($empresa->fresh());
        $this->assertSame($resultado['marketplace_destino'], $segundaLeitura);
        $this->assertSame('meli', $segundaLeitura);
    }

    // ─── Caso 7 — sem nenhuma linha em company_marketplaces: cai para a coluna flat ──

    public function test_sem_nenhuma_linha_em_company_marketplaces_cai_para_a_coluna_flat_e_nao_e_nulo(): void
    {
        $empresa = $this->empresaCompleta(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, 'marketplace' => 'meli']);
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = $this->usuario();

        $this->completarGrupoEntrada($empresa, $usuario);

        $this->assertSame(0, CompanyMarketplace::where('company_id', $empresa->id)->count());

        $destino = $this->service()->marketplaceDestino($empresa->fresh());

        $this->assertNotNull($destino);
        $this->assertSame('meli', $destino);
    }
}
