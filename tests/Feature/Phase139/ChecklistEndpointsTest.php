<?php

namespace Tests\Feature\Phase139;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\MlToken;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 139 Plano 08 (ADMIN-04/ADMIN-05/ADMIN-06, D-09/D-15) — os 4 endpoints do
 * checklist pela porta HTTP.
 *
 * Duas coisas são provadas em cada caso de mutação: o efeito no checklist E o
 * avanço da etapa. O segundo é o que impede a D-15 de morrer em silêncio — sem
 * a sincronização, a empresa fica presa na etapa 1, o FINALIZAR nunca habilita,
 * e não aparece erro nenhum na tela.
 *
 * Todas as asserções de etapa por RECONSULTA ao banco.
 */
class ChecklistEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Endpoints Teste',
            'slug'   => 'endpoints-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    private function servicoComContrato(): Servico
    {
        return Servico::create([
            'nome'           => 'Performance (endpoints 139-08)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    /** `companies.cnpj` e UNIQUE — cada empresa da suite precisa do seu. */
    private static int $sequenciaCnpj = 0;

    private function empresa(array $overrides = []): Company
    {
        $n = str_pad((string) (++self::$sequenciaCnpj), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create(array_merge([
            'active'        => true,
            'etapa'         => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            'cnpj'          => "11.222.333/{$n}-81",
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

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $c->id,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));
    }

    /** Empresa com serviço que exige contrato, já vinculado. */
    private function empresaComServico(array $overrides = []): Company
    {
        $empresa = $this->empresa($overrides);
        $this->vincularServico($empresa, $this->servicoComContrato());

        return $empresa->fresh();
    }

    private function checklistService(): ChecklistAdministrativoService
    {
        return app(ChecklistAdministrativoService::class);
    }

    /** Completa os 6 itens do grupo Entrada pelo ESTADO REAL, nunca gravando status direto. */
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

        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'token-endpoints-139-08-'.$empresa->id]);
    }

    /** Completa os 3 itens do grupo Contrato — item 1 manual, 2/3 por envelope assinado. */
    private function completarGrupoContrato(Company $empresa, User $usuario): void
    {
        $this->checklistService()->concluirManualmente($empresa, 'contrato_revisado', $usuario);

        ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $empresa->contratosServico()->first()->servico_id,
            'status'      => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'  => now()->subDay(),
            'assinado_em' => now(),
        ]);
    }

    private function etapaDe(Company $empresa): ?string
    {
        return Company::findOrFail($empresa->id)->etapa;
    }

    // ─── Caso 1 — concluir item avança a etapa (ADMIN-04 + D-15 pela rota) ──

    public function test_concluir_item_grava_autoria_e_avanca_a_etapa(): void
    {
        $empresa = $this->empresaComServico();
        $user    = $this->admin();

        $response = $this->actingAs($user)->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'grupo_whatsapp_criado'])
        );

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $linha = ChecklistAdministrativoItem::where('company_id', $empresa->id)
            ->where('chave', 'grupo_whatsapp_criado')
            ->firstOrFail();

        $this->assertSame(ChecklistAdministrativoItem::STATUS_CONCLUIDO, $linha->status);
        $this->assertSame($user->id, $linha->feito_por);
        $this->assertNotNull($linha->feito_em);

        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $this->etapaDe($empresa));
    }

    // ─── Caso 2 — user_id no corpo é IGNORADO (ADMIN-04, T-137-02) ──────────

    public function test_user_id_enviado_no_corpo_nao_vira_autoria(): void
    {
        $empresa  = $this->empresaComServico();
        $user     = $this->admin();
        $terceiro = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($user)->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'grupo_whatsapp_criado']),
            ['user_id' => $terceiro->id, 'feito_por' => $terceiro->id]
        )->assertStatus(302);

        $linha = ChecklistAdministrativoItem::where('company_id', $empresa->id)
            ->where('chave', 'grupo_whatsapp_criado')
            ->firstOrFail();

        $this->assertSame($user->id, $linha->feito_por, 'A autoria é sempre a do usuário AUTENTICADO — corpo é forjável.');
        $this->assertNotSame($terceiro->id, $linha->feito_por);
    }

    // ─── Caso 3 — item automático é recusado e nada muda ────────────────────

    public function test_concluir_item_automatico_e_recusado_sem_gravar_nem_mover_a_etapa(): void
    {
        $empresa = $this->empresaComServico();

        $response = $this->actingAs($this->admin())->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'contrato_enviado'])
        );

        $response->assertStatus(302);
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('checklist_administrativo_itens', [
            'company_id' => $empresa->id,
            'chave'      => 'contrato_enviado',
            'status'     => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
        ]);

        // O funil NÃO sincroniza no ramo de exceção — nada mudou.
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $this->etapaDe($empresa));
    }

    // ─── Caso 4 — chave fora do catálogo: 404 (T-139-08-04) ─────────────────

    public function test_chave_inexistente_devolve_404(): void
    {
        $empresa = $this->empresaComServico();

        $this->actingAs($this->admin())->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'item_inventado'])
        )->assertStatus(404);

        $this->assertSame(0, ChecklistAdministrativoItem::where('company_id', $empresa->id)->count());
    }

    // ─── Caso 5 — Entrada não age em item do grupo Contrato (D-09) ──────────

    public function test_usuario_de_entrada_nao_pode_agir_em_item_do_grupo_contrato(): void
    {
        $empresa = $this->empresaComServico();
        $user    = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        $this->actingAs($user)->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'contrato_revisado'])
        )->assertStatus(403);

        $this->assertSame(0, ChecklistAdministrativoItem::where('company_id', $empresa->id)->count());

        // Mas age normalmente num item do grupo Entrada — a rota é a mesma.
        $this->actingAs($user)->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'grupo_whatsapp_criado'])
        )->assertStatus(302);

        // Contar a CHAVE, nunca o total: `paraEmpresa()` — chamada por dentro do
        // sincronizador — persiste linha propria para cada um dos 4 itens
        // automaticos, entao o total desta empresa nao e 1.
        $this->assertDatabaseHas('checklist_administrativo_itens', [
            'company_id' => $empresa->id,
            'chave'      => 'grupo_whatsapp_criado',
            'status'     => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
        ]);
    }

    // ─── Caso 6 — reabrir zera a autoria e não retrocede a etapa ────────────

    public function test_reabrir_item_zera_autoria_e_nao_retrocede_a_etapa(): void
    {
        $empresa = $this->empresaComServico();
        $user    = $this->admin();

        $this->checklistService()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $user);
        $this->actingAs($user)->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'email_colaborador_criado'])
        )->assertStatus(302);

        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $this->etapaDe($empresa));

        $this->actingAs($user)->post(
            route('admin.contratos.checklist.reabrir', ['company' => $empresa, 'chave' => 'grupo_whatsapp_criado'])
        )->assertStatus(302)->assertSessionHas('success');

        $linha = ChecklistAdministrativoItem::where('company_id', $empresa->id)
            ->where('chave', 'grupo_whatsapp_criado')
            ->firstOrFail();

        $this->assertSame(ChecklistAdministrativoItem::STATUS_ABERTO, $linha->status);
        $this->assertNull($linha->feito_por);
        $this->assertNull($linha->feito_em);

        // O sincronizador só avança — a etapa fica onde está.
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $this->etapaDe($empresa));
    }

    // ─── Caso 7 — conexão ECF idempotente e avança a etapa (D-14/D-15) ──────

    public function test_conexao_ecf_e_idempotente_e_avanca_a_etapa(): void
    {
        $empresa = $this->empresaComServico();
        $user    = $this->admin();

        $this->actingAs($user)->post(route('admin.contratos.checklist.conexao-ecf', $empresa))
            ->assertStatus(302)->assertSessionHas('success');

        // Era o primeiro item concluído da empresa — a etapa avançou.
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $this->etapaDe($empresa));

        $this->actingAs($user)->post(route('admin.contratos.checklist.conexao-ecf', $empresa))
            ->assertStatus(302);

        $this->assertSame(1, OnboardingLink::where('company_id', $empresa->id)->count(), 'gerarConexaoEcf() é idempotente (D-14).');
    }

    // ─── Caso 8 — finalizar com item pendente: recusado, etapa intacta ──────

    public function test_finalizar_com_item_pendente_e_recusado_sem_mover_a_etapa(): void
    {
        $empresa = $this->empresaComServico(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);

        $response = $this->actingAs($this->admin())->post(route('admin.contratos.finalizar-entrada', $empresa));

        $response->assertStatus(302);
        $response->assertSessionHas('error');

        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, $this->etapaDe($empresa));
    }

    // ─── Caso 9 — finalizar na etapa 4 move para a 5 (ADMIN-06) ─────────────

    public function test_finalizar_na_etapa_4_move_para_aguardando_distribuicao(): void
    {
        $empresa = $this->empresaComServico(['etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $user    = $this->admin();

        $this->completarGrupoContrato($empresa, $user);
        $this->completarGrupoEntrada($empresa, $user);

        $totalEmpresasAntes = Company::count();

        $this->actingAs($user)->post(route('admin.contratos.finalizar-entrada', $empresa))
            ->assertStatus(302)->assertSessionHas('success');

        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $this->etapaDe($empresa));

        // ADMIN-06 — mesma empresa, nenhum cadastro novo.
        $this->assertSame($totalEmpresasAntes, Company::count());
    }

    // ─── Caso 10 — a rota sincroniza ANTES de finalizar (D-15) ──────────────

    /**
     * ⚠️ Isto NÃO contradiz o caso 5 de `FinalizarTransicaoEtapaTest` (plano
     * 139-06), que afirma `recusado` para a mesma situação: lá se testa
     * `finalizar()` SOZINHO, no nível de service; aqui se testa a ROTA, que
     * sincroniza antes. Não "corrigir" um dos dois.
     */
    public function test_finalizar_a_partir_da_etapa_2_sincroniza_antes_e_registra_cada_degrau(): void
    {
        $empresa = $this->empresaComServico(['etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $user    = $this->admin();

        $this->completarGrupoContrato($empresa, $user);
        $this->completarGrupoEntrada($empresa, $user);

        $this->actingAs($user)->post(route('admin.contratos.finalizar-entrada', $empresa))
            ->assertStatus(302)->assertSessionHas('success');

        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $this->etapaDe($empresa));

        // Nada de pulo sem linha: o degrau intermediário tem histórico próprio.
        $this->assertDatabaseHas('company_etapa_transicoes', [
            'company_id' => $empresa->id,
            'etapa_nova' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
        ]);
        $this->assertDatabaseHas('company_etapa_transicoes', [
            'company_id' => $empresa->id,
            'etapa_nova' => Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ]);
    }

    // ─── Caso 11 — a mutação atinge só a empresa da rota (T-139-08-03) ──────

    public function test_a_marcacao_atinge_apenas_a_empresa_da_rota(): void
    {
        $empresaA = $this->empresaComServico();
        $empresaB = $this->empresaComServico();

        $this->actingAs($this->admin())->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresaA, 'chave' => 'grupo_whatsapp_criado'])
        )->assertStatus(302);

        $this->assertDatabaseHas('checklist_administrativo_itens', [
            'company_id' => $empresaA->id,
            'chave'      => 'grupo_whatsapp_criado',
            'status'     => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
        ]);
        // A empresa B nao foi tocada por NENHUMA linha — nem manual, nem dos
        // resolvers automaticos (que so rodam para a empresa da rota).
        $this->assertSame(0, ChecklistAdministrativoItem::where('company_id', $empresaB->id)->count());
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $this->etapaDe($empresaB));
    }

    // ─── Caso 12 — legado: etapa NULL marca item mas nunca é carimbada ──────

    public function test_empresa_legada_marca_item_mas_nunca_ganha_etapa(): void
    {
        $empresa = $this->empresaComServico(['etapa' => null]);

        $this->actingAs($this->admin())->post(
            route('admin.contratos.checklist.concluir', ['company' => $empresa, 'chave' => 'grupo_whatsapp_criado'])
        )->assertStatus(302)->assertSessionHas('success');

        $this->assertSame(1, ChecklistAdministrativoItem::where('company_id', $empresa->id)->count());
        $this->assertNull($this->etapaDe($empresa));
        $this->assertSame(0, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }
}
