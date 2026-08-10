<?php

namespace Tests\Feature\Phase134;

use App\Jobs\SyncMlAcervoCompanyJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 134 (Plano 07) — rota `mlb.anuncios.meus`: listagem lida
 * EXCLUSIVAMENTE do banco (D-05), triagem agrupada por motivo (D-09),
 * selo de defasagem (D-08) e o botão "Atualizar agora" (V5/T-134-02).
 *
 * O que blinda:
 *   (a) D-01 — a tela lista o acervo inteiro da conta, não só o que o
 *       módulo publicou (item legado aparece igual a item ecf/time)
 *   (b) D-02/T-134-01 — escopo por empresa: nunca vaza item, nem na lista,
 *       nem na triagem, nem na busca (orWhere agrupado)
 *   (c) D-05 — zero chamada HTTP síncrona ao ML no request de leitura
 *   (d) D-08 — coleta velha/ausente produz selo, nunca resposta vazia
 *   (e) D-09 — triagem.total é anúncios distintos, nunca soma dos chips;
 *       clicar num motivo filtra a lista
 *   (f) D-15 — módulo continua role:admin
 *   (g) T-134-02 — "Atualizar agora" enfileira e rejeita empresa sem token
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake() — nunca ML real.
 *
 * @group phase134
 */
class MeusAnunciosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nenhuma chamada real ao ML nestes testes
    }

    /** @test */
    public function lista_itens_que_nao_vieram_deste_modulo(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000001',
            'title'      => 'Anúncio Legado do Cliente',
            'origem'     => MlAcervoItem::ORIGEM_LEGADO,
            'rascunho_id' => null,
        ]);

        $props = $this->propsDaTela($admin, $company);

        $itens = $props['anuncios']['data'];
        $this->assertCount(1, $itens, 'D-01: item que nunca passou por este módulo tem que aparecer no acervo');
        $this->assertSame('legado', $itens[0]['origem']);
    }

    /** @test */
    public function nao_vaza_anuncio_de_outra_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'title' => 'Meu Anúncio']);
        $this->criarItem($outra, [
            'ml_item_id' => 'MLB2000000002',
            'title'      => 'Anúncio Da Outra Empresa',
            'status'     => 'active',
            'available_quantity' => 0,
            'motivos'    => [MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);

        $props = $this->propsDaTela($admin, $company, ['status' => 'todos']);

        $ids = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();
        $this->assertContains('MLB1000000001', $ids);
        $this->assertNotContains('MLB2000000002', $ids, 'VAZAMENTO entre empresas na listagem');

        $this->assertSame(0, $props['triagem']['total'], 'VAZAMENTO entre empresas na triagem — motivo da outra empresa não pode contar aqui');
    }

    /** @test */
    public function busca_nao_fura_o_escopo_de_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'title' => 'Camiseta Válida']);
        $this->criarItem($outra, ['ml_item_id' => 'MLB2000000002', 'title' => 'Camiseta de Outra Empresa']);

        $props = $this->propsDaTela($admin, $company, ['busca' => 'Camiseta']);

        $titulos = collect($props['anuncios']['data'])->pluck('titulo')->all();
        $this->assertSame(['Camiseta Válida'], $titulos,
            'a busca tem que respeitar company_id — orWhere solto vazaria a outra empresa');
    }

    /** @test */
    public function empresa_sem_conta_ml_devolve_404(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create(); // sem MlToken

        $this->actingAs($admin)
            ->get(route('mlb.anuncios.meus', $company))
            ->assertNotFound();
    }

    /** @test */
    public function consultor_nao_acessa_meus_anuncios(): void
    {
        [$company] = $this->criarFixture();
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->get(route('mlb.anuncios.meus', $company))
            ->assertForbidden();
    }

    /** @test */
    public function nenhuma_chamada_sincrona_ao_ml_no_request(): void
    {
        [$company, , $admin] = $this->criarFixture();
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001']);

        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('mlb.anuncios.meus', $company))
            ->assertOk();

        // D-05: a tela lê exclusivamente do banco — nenhuma chamada ao Mercado
        // Livre no request de leitura. Escopado ao domínio do ML (não
        // assertNothingSent() bruto): o middleware global HandleInertiaRequests
        // já dispara uma chamada não relacionada (contagem de signals críticos
        // via EcfDriveService, prop compartilhada em TODA página Inertia do
        // app) — fora do escopo desta fase, não é o que o D-05 trava.
        $this->assertMlNaoChamado();
    }

    /** @test */
    public function selo_de_origem_cobre_os_tres_casos(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id'  => 'MLB1000000001',
            'origem'      => MlAcervoItem::ORIGEM_ECF,
            'rascunho_id' => 42,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000002',
            'origem'     => MlAcervoItem::ORIGEM_TIME,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000003',
            'origem'     => MlAcervoItem::ORIGEM_LEGADO,
        ]);

        $props = $this->propsDaTela($admin, $company);
        $itens = collect($props['anuncios']['data'])->keyBy('ml_item_id');

        $this->assertSame('ecf', $itens['MLB1000000001']['origem']);
        $this->assertSame(42, $itens['MLB1000000001']['rascunho_id']);
        $this->assertSame('time', $itens['MLB1000000002']['origem']);
        $this->assertSame('legado', $itens['MLB1000000003']['origem']);
    }

    /** @test */
    public function degradacao_graciosa_mostra_ultimo_snapshot_com_selo(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id'   => 'MLB1000000001',
            'coletado_em'  => now()->subDays(3),
            'coleta_erro'  => 'Token expirado',
        ]);

        $props = $this->propsDaTela($admin, $company);

        $this->assertTrue($props['defasagem']['defasado'], 'D-08: coleta com 3 dias tem que acender o selo de defasagem');
        $this->assertEqualsWithDelta(72, $props['defasagem']['horas'], 1, 'D-08: ~72h desde a última coleta');
        $this->assertSame('Token expirado', $props['defasagem']['motivo']);
        $this->assertFalse($props['defasagem']['nunca_coletado']);
        $this->assertNotEmpty($props['anuncios']['data'], 'D-08: nunca tela em branco — o último snapshot continua visível');

        // Empresa sem nenhuma linha de acervo — degradação total, ainda sem 500.
        [$vazia, , $adminVazia] = $this->criarFixture();

        $propsVazia = $this->propsDaTela($adminVazia, $vazia);

        $this->assertTrue($propsVazia['defasagem']['nunca_coletado']);
        $this->assertFalse($propsVazia['defasagem']['defasado']);
        $this->assertNull($propsVazia['defasagem']['horas']);
        $this->assertSame([], $propsVazia['anuncios']['data']);
    }

    /** @test */
    /**
     * @test
     *
     * O filtro default é `acionaveis` (active + paused), não `ativos`.
     *
     * O D-03 dizia "só ativos por padrão", mas a justificativa dele era custo
     * — e o volume morto do acervo é encerrado/inativo, não pausado. Com o
     * default em `active` puro, o chip "Pausado" do D-09 ficava em 0 para
     * sempre e o topo da ordenação do D-12 ("pausado primeiro") nunca tinha
     * pausado: dois requisitos travados anulados em silêncio por um default.
     * Decisão do usuário em 2026-08-10, emenda registrada no 134-CONTEXT.md.
     *
     * Encerrado continua fora do default — esse sim é volume morto, e ninguém
     * "precisa agir" sobre anúncio que já acabou.
     */
    public function default_da_tela_traz_pausados_e_deixa_encerrados_de_fora(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'status' => 'active']);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000002',
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000003', 'status' => 'closed']);

        // Sem nenhum parâmetro de status — o default é o que está sob teste.
        $props = $this->propsDaTela($admin, $company);

        $idsNaTela = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();

        $this->assertContains('MLB1000000001', $idsNaTela, 'ativo tem que aparecer no default');
        $this->assertContains('MLB1000000002', $idsNaTela, 'pausado tem que aparecer no default — é o sinal mais óbvio de "precisa de você"');
        $this->assertNotContains('MLB1000000003', $idsNaTela, 'encerrado fica atrás de filtro — é o volume morto que o D-03 quis evitar');

        // E o chip precisa contar de verdade, não nascer zerado.
        $chipsPorChave = collect($props['triagem']['chips'])->keyBy('chave');
        $this->assertSame(
            1,
            $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count'],
            'o chip "Pausado" conta sob o filtro default; se vier 0, o D-09 voltou a ter um chip morto'
        );
    }

    /** @test */
    public function triagem_agrupa_por_motivo_e_o_clique_filtra(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000001',
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000002',
            'status'     => 'active',
            'available_quantity' => 0,
            'motivos'    => [MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000003',
            'motivos'    => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA],
            'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000004', // saudável, nenhum motivo
        ]);

        // A triagem obedece o MESMO filtro de status da listagem, e o default
        // ('acionaveis' = active + paused) já cobre o item pausado — é o que
        // faz o chip "Pausado" do D-09 e o topo da ordenação do D-12
        // existirem de verdade. Ver o teste dedicado logo abaixo.
        $props = $this->propsDaTela($admin, $company, ['status' => 'todos']);

        $this->assertSame(3, $props['triagem']['total'], 'total = anúncios distintos com motivo, não soma dos chips');

        $chipsPorChave = collect($props['triagem']['chips'])->keyBy('chave');
        $this->assertSame(1, $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count']);
        $this->assertSame(1, $chipsPorChave[MlAcervoItem::MOTIVO_SEM_ESTOQUE]['count']);
        $this->assertSame(1, $chipsPorChave[MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]['count']);
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_PERDENDO_CATALOGO]['count']);
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE]['count']);

        // Item com DOIS motivos: total sobe só 1, dois chips sobem 1 cada.
        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000005',
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO, MlAcervoItem::MOTIVO_FICHA_INCOMPLETA],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);

        $props = $this->propsDaTela($admin, $company, ['status' => 'todos']);

        $this->assertSame(4, $props['triagem']['total'], 'a regra de contagem precisa fechar: +1 anúncio, não +2');
        $chipsPorChave = collect($props['triagem']['chips'])->keyBy('chave');
        $this->assertSame(2, $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count']);
        $this->assertSame(2, $chipsPorChave[MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]['count']);

        // Clique no chip "pausado" filtra a lista — só os 2 itens com esse motivo.
        $propsFiltrado = $this->propsDaTela($admin, $company, ['status' => 'todos', 'motivo' => MlAcervoItem::MOTIVO_PAUSADO]);
        $ids = collect($propsFiltrado['anuncios']['data'])->pluck('ml_item_id')->all();
        $this->assertEqualsCanonicalizing(['MLB1000000001', 'MLB1000000005'], $ids);
    }

    /** @test */
    public function atualizar_agora_enfileira_e_rejeita_empresa_sem_token(): void
    {
        Queue::fake();
        [$company, , $admin] = $this->criarFixture();

        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.atualizar', $company))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(SyncMlAcervoCompanyJob::class, 1);
        $this->assertMlNaoChamado(); // o enqueue não fala com o ML

        // Empresa sem MlToken — 404, nenhum job a mais enfileirado.
        $semToken = Company::factory()->create();
        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.atualizar', $semToken))
            ->assertNotFound();

        Queue::assertPushed(SyncMlAcervoCompanyJob::class, 1);

        // Usuário não-admin — 403.
        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)
            ->post(route('mlb.anuncios.meus.atualizar', $company))
            ->assertForbidden();
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * Props da página Inertia para a empresa/admin/query informados.
     *
     * X-Inertia simula navegação client-side (Inertia devolve JSON puro, sem
     * renderizar o Blade @vite) — evita depender do manifest Vite do
     * componente Mlb/MeusAnuncios.jsx, que só vai existir no plano 134-08
     * (backend puro nesta fase). X-Inertia-Version precisa bater com a
     * versão calculada pelo middleware, senão vira 409 (conflito de versão).
     * Mesmo padrão de tests/Feature/Phase58/DashboardShellsBackendTest.php.
     */
    private function propsDaTela(User $admin, Company $company, array $query = []): array
    {
        $url = route('mlb.anuncios.meus', $company);
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get($url)
            ->assertOk();

        return $response->json('props');
    }

    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    /**
     * D-05/D-11: nenhuma chamada ao domínio do Mercado Livre. Escopado (não
     * Http::assertNothingSent() bruto) porque o middleware global
     * HandleInertiaRequests dispara, em TODA página Inertia do app, uma
     * chamada não relacionada (contagem de signals críticos via
     * EcfDriveService) — ruído de uma feature global, não o que D-05 trava.
     */
    private function assertMlNaoChamado(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mercadolibre.com'));
    }

    private function criarItem(Company $company, array $overrides = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id'          => $company->id,
            'ml_item_id'          => 'MLB' . random_int(1000000000, 9999999999),
            'title'               => 'Produto de Teste',
            'status'              => 'active',
            'available_quantity'  => 10,
            'sold_quantity'       => 0,
            'nota_ecf'            => 60,
            'motivos'             => [],
            'severidade'          => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem'              => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em'         => now(),
        ], $overrides));
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria Company + MlToken ativo + MlbEmpresa (mesmo padrão de HistoricoAnunciosTest, Fase 86). */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => (string) random_int(100000000, 999999999),
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Meus Anuncios ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }
}
