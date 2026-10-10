<?php

namespace Tests\Feature\Phase134;

use App\Jobs\SyncMlAcervoCompanyJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Mlb\Acervo\AnuncioSaudeService;
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
    public function expoe_health_ml_e_performance_score_sem_conversao(): void
    {
        // D-21 (emenda 2026-08-10): a tela precisa das DUAS medidas de saúde
        // do ML, cada uma em sua própria escala, mais o booleano que distingue
        // "não se aplica" (catálogo/encerrado) de "ainda não avaliado" (rotação
        // não chegou lá) — plano 134-08 consome isto na coluna Saúde.
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id'         => 'MLB1000000001',
            'status'             => 'active',
            'catalog_listing'    => false,
            'health_ml'          => 0.72,
            'performance_score'  => 91,
            'performance_level'  => 'good',
            'performance_acoes'  => [['key' => 'UP_PICTURES', 'title' => 'Melhore as fotos para ter mais visitas']],
        ]);
        $this->criarItem($company, [
            'ml_item_id'      => 'MLB1000000002',
            'status'          => 'active',
            'catalog_listing' => true, // item de catálogo — ML não pontua (D-21)
        ]);
        $this->criarItem($company, [
            'ml_item_id'      => 'MLB1000000003',
            'status'          => 'active',
            'catalog_listing' => false, // fora de catálogo, mas rotação ainda não chegou
        ]);

        $props = $this->propsDaTela($admin, $company, ['status' => 'todos']);
        $itens = collect($props['anuncios']['data'])->keyBy('ml_item_id');

        $this->assertSame(0.72, $itens['MLB1000000001']['health_ml'], 'health_ml chega intacto, sem virar percentual de outra escala');
        $this->assertSame(91, $itens['MLB1000000001']['performance_score']);
        $this->assertSame('good', $itens['MLB1000000001']['performance_level']);
        $this->assertSame('Melhore as fotos para ter mais visitas', $itens['MLB1000000001']['performance_acoes'][0]['title'], 'title do ML preservado, nunca reescrito');
        $this->assertFalse($itens['MLB1000000001']['saude_ml_nao_se_aplica']);

        $this->assertTrue($itens['MLB1000000002']['saude_ml_nao_se_aplica'], 'catálogo: "não se aplica", não "não avaliado"');
        $this->assertNull($itens['MLB1000000002']['health_ml']);
        $this->assertNull($itens['MLB1000000002']['performance_score']);

        $this->assertFalse($itens['MLB1000000003']['saude_ml_nao_se_aplica'], 'fora de catálogo: rotação pode não ter chegado ainda, mas o conceito se aplica');
        $this->assertNull($itens['MLB1000000003']['performance_score'], '"não avaliado" — rotação da camada cara ainda não cobriu este item');
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

    /**
     * @test
     *
     * Fase 173-02: `?comVenda=1` filtra a LISTAGEM paginada por
     * `sold_quantity > 0`, mas os chips de triagem (D-09) continuam mostrando
     * o universo completo do status filtrado — senão o número do chip
     * mudaria sozinho quando o indicador "Com venda" da Visão geral linkasse
     * para aqui, contradizendo o próprio chip.
     */
    public function com_venda_filtra_a_listagem_sem_mudar_a_triagem(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id'    => 'MLB1000000001',
            'status'        => 'paused',
            'sold_quantity' => 5,
            'motivos'       => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade'    => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, [
            'ml_item_id'    => 'MLB1000000002',
            'status'        => 'active',
            'sold_quantity' => 0,
        ]);

        $props = $this->propsDaTela($admin, $company, ['comVenda' => '1']);

        $ids = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();
        $this->assertSame(['MLB1000000001'], $ids, 'comVenda=1 só deixa passar sold_quantity > 0 na listagem');
        $this->assertTrue($props['filtros']['com_venda']);

        // Chip "Pausado" continua contando o universo completo (os dois itens
        // acionáveis), não só o filtrado por comVenda.
        $chipsPorChave = collect($props['triagem']['chips'])->keyBy('chave');
        $this->assertSame(1, $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count'], 'triagem não pode ser afetada por comVenda');

        // Sem o parâmetro, default continua devolvendo os dois (regressão zero).
        $propsSemFiltro = $this->propsDaTela($admin, $company);
        $idsSemFiltro   = collect($propsSemFiltro['anuncios']['data'])->pluck('ml_item_id')->all();
        $this->assertEqualsCanonicalizing(['MLB1000000001', 'MLB1000000002'], $idsSemFiltro);
        $this->assertFalse($propsSemFiltro['filtros']['com_venda']);
    }

    // ═══ Quick 261010-nke — `under_review` entra em `acionaveis` ════════════
    //
    // O anúncio recém-publicado nasce `under_review [waiting_for_patch]` nessa
    // conta (§21 dos learnings do Publicador). Com o default cobrindo só
    // `active` + `paused`, ele continuava invisível MESMO depois de a linha
    // passar a existir no acervo — e a busca, montada DENTRO do mesmo builder
    // de `escopo()`, herdava o filtro de status e também não o achava. Era a
    // queixa literal do usuário: "não aparece nem a busca acha".

    /** @test */
    public function default_da_tela_traz_under_review(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'status' => 'active']);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB5366398961',
            'title'      => 'Poltrona Beny Recém Publicada',
            'status'     => 'under_review',
            'sub_status' => ['waiting_for_patch'],
        ]);

        // Sem nenhum parâmetro de status — o default é o que está sob teste.
        $props = $this->propsDaTela($admin, $company);

        $idsNaTela = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();

        $this->assertContains(
            'MLB5366398961',
            $idsNaTela,
            'o anúncio recém-publicado nasce under_review: fora do default, o usuário não o encontra'
        );
        $this->assertContains('MLB1000000001', $idsNaTela, 'ativo continua no default');
    }

    /** @test */
    public function busca_acha_under_review_sem_trocar_o_filtro(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id' => 'MLB5366398961',
            'title'      => 'Poltrona Beny Verde Musgo',
            'status'     => 'under_review',
        ]);

        // Busca SEM querystring de status: a busca mora dentro do mesmo builder
        // que recebe o whereIn('status', ...), então herda o filtro padrão.
        $props = $this->propsDaTela($admin, $company, ['busca' => 'Poltrona Beny']);

        $idsNaTela = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();

        $this->assertSame(['MLB5366398961'], $idsNaTela, 'o critério de aceite do usuário: achar sem saber trocar filtro');
    }

    /** @test */
    public function filtro_ativos_continua_sem_trazer_under_review(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'status' => 'active']);
        $this->criarItem($company, ['ml_item_id' => 'MLB5366398961', 'status' => 'under_review']);

        $props = $this->propsDaTela($admin, $company, ['status' => 'ativos']);

        $idsNaTela = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();

        $this->assertSame(['MLB1000000001'], $idsNaTela, 'os filtros estreitos recortam UM status só — não mudaram');
    }

    /**
     * @test
     *
     * A emenda de 2026-08-10 ao D-03 (pausado no default) fica INTACTA: esta
     * mudança só acrescenta.
     */
    public function pausado_segue_no_default_e_o_chip_segue_contando(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id' => 'MLB1000000002',
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, ['ml_item_id' => 'MLB5366398961', 'status' => 'under_review']);

        $props = $this->propsDaTela($admin, $company);

        $idsNaTela = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();
        $this->assertContains('MLB1000000002', $idsNaTela);

        $chipsPorChave = collect($props['triagem']['chips'])->keyBy('chave');
        $this->assertSame(
            1,
            $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count'],
            'o chip "Pausado" continua contando o pausado — under_review no default não o afeta'
        );
    }

    /**
     * @test
     *
     * Os chips do D-09 não passam a mentir: `AnuncioSaudeService::triagem()` só
     * carimba `pausado` quando `status === 'paused'` e `sem_estoque` quando
     * `status === 'active'`. Um `under_review` saudável entra no universo da
     * triagem sem inflar nenhum chip crítico.
     */
    public function under_review_saudavel_nao_recebe_motivo_pausado_nem_sem_estoque(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $saude = app(AnuncioSaudeService::class);
        $itemDoMl = [
            'id'                 => 'MLB5366398961',
            'title'              => 'Poltrona Beny Verde Musgo Base Giratória',
            'status'             => 'under_review',
            'sub_status'         => ['waiting_for_patch'],
            'available_quantity' => 5,
            'pictures'           => [['id' => 'P1'], ['id' => 'P2'], ['id' => 'P3'], ['id' => 'P4'], ['id' => 'P5'], ['id' => 'P6']],
            'attributes'         => [],
            'price'              => 1299.9,
        ];
        $avaliacao = $saude->avaliar($itemDoMl, []);
        $triagem   = $saude->triagem($itemDoMl, $avaliacao['sinais'], null);

        $this->assertNotContains(MlAcervoItem::MOTIVO_PAUSADO, $triagem['motivos']);
        $this->assertNotContains(MlAcervoItem::MOTIVO_SEM_ESTOQUE, $triagem['motivos']);

        // E na tela: aparece no default sem inflar os dois chips críticos.
        $this->criarItem($company, [
            'ml_item_id' => 'MLB5366398961',
            'title'      => $itemDoMl['title'],
            'status'     => 'under_review',
            'motivos'    => $triagem['motivos'],
            'severidade' => $triagem['severidade'],
        ]);

        $props = $this->propsDaTela($admin, $company);

        $idsNaTela = collect($props['anuncios']['data'])->pluck('ml_item_id')->all();
        $this->assertContains('MLB5366398961', $idsNaTela);

        $chipsPorChave = collect($props['triagem']['chips'])->keyBy('chave');
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count']);
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_SEM_ESTOQUE]['count']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Quick 261010-rie — a tela: busca por SKU, filtro "Em revisão" e o vazio
    // honesto quando o SKU ainda não foi coletado.
    // ═══════════════════════════════════════════════════════════════════════

    /** @test T28 */
    public function busca_por_sku_acha_o_under_review_sem_trocar_o_filtro(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id' => 'MLB5366398961',
            'title'      => 'Poltrona Beny Verde Musgo',
            'status'     => 'under_review',
            'skus'       => ['POLT-BENY-VM'],
        ]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'skus' => ['OUTRO-SKU']]);

        // Sem querystring de status: coluna + busca + filtro padrão juntos —
        // o caminho completo do pedido do usuário.
        $props = $this->propsDaTela($admin, $company, ['busca' => 'POLT-BENY-VM']);

        $this->assertSame(
            ['MLB5366398961'],
            collect($props['anuncios']['data'])->pluck('ml_item_id')->all(),
            'RIE-01: achar pelo SKU da planilha, sem saber trocar filtro'
        );
    }

    /** @test T29 */
    public function filtro_em_revisao_lista_so_under_review_e_valor_invalido_cai_no_default(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'status' => 'active']);
        $this->criarItem($company, ['ml_item_id' => 'MLB5366398961', 'status' => 'under_review']);

        $props = $this->propsDaTela($admin, $company, ['status' => 'em_revisao']);

        $this->assertSame(['MLB5366398961'], collect($props['anuncios']['data'])->pluck('ml_item_id')->all());
        $this->assertSame('em_revisao', $props['filtros']['status']);

        // Valor fora da lista fechada continua caindo no default — nunca
        // interpolado em SQL.
        $propsInvalido = $this->propsDaTela($admin, $company, ['status' => 'xpto']);

        $this->assertSame('acionaveis', $propsInvalido['filtros']['status']);
        $this->assertCount(2, $propsInvalido['anuncios']['data'], 'acionaveis segue o default e segue cobrindo os três status');
    }

    /** @test T30 */
    public function prop_skus_de_cada_linha_e_sempre_array(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB-COM', 'skus' => ['A-1', 'A-2']]);
        $this->criarItem($company, ['ml_item_id' => 'MLB-SEM']); // skus NULL no banco

        $props     = $this->propsDaTela($admin, $company);
        $porItemId = collect($props['anuncios']['data'])->keyBy('ml_item_id');

        $this->assertSame(['A-1', 'A-2'], $porItemId['MLB-COM']['skus']);
        $this->assertSame(
            [],
            $porItemId['MLB-SEM']['skus'],
            'a tela recebe array sempre — o aviso de cobertura é prop separada, não null na linha'
        );
    }

    /** @test T31 */
    public function ordenacao_nao_muda_com_o_filtro_em_revisao(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, [
            'ml_item_id' => 'MLB-REVISAO-SAUDAVEL',
            'status'     => 'under_review',
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'nota_ecf'   => 80,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB-REVISAO-CRITICA',
            'status'     => 'under_review',
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'nota_ecf'   => 20,
        ]);

        $props = $this->propsDaTela($admin, $company, ['status' => 'em_revisao']);

        $this->assertSame(
            ['MLB-REVISAO-CRITICA', 'MLB-REVISAO-SAUDAVEL'],
            collect($props['anuncios']['data'])->pluck('ml_item_id')->all(),
            'D-12: severidade desc, depois nota_ecf — nenhum critério olha status, então a opção nova não muda nada'
        );
    }

    /** @test T32 */
    public function busca_sem_resultado_em_acervo_sem_sku_avisa_que_o_sku_nao_foi_coletado(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // Linhas gravadas antes desta mudança: skus NULL em TODAS.
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001']);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000002']);

        $props = $this->propsDaTela($admin, $company, ['busca' => 'ABC-1']);

        $this->assertCount(0, $props['anuncios']['data']);
        $this->assertTrue(
            $props['skuNaoColetado'],
            'RIE-04: sem isso a tela responde "não achei" como se o anúncio não existisse — mentira por omissão'
        );
    }

    /** @test T33 */
    public function busca_sem_resultado_com_sku_ja_coletado_nao_avisa(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'skus' => []]); // coletado, anúncio sem SKU
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000002']);

        $props = $this->propsDaTela($admin, $company, ['busca' => 'NAO-EXISTE-MESMO']);

        $this->assertCount(0, $props['anuncios']['data']);
        $this->assertFalse(
            $props['skuNaoColetado'],
            'com ao menos uma linha coletada, o "não achei" é verdade — nada a ressalvar'
        );
    }

    /** @test T34 */
    public function busca_que_acha_nao_avisa_nada(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'title' => 'Poltrona Beny']);

        $props = $this->propsDaTela($admin, $company, ['busca' => 'Poltrona']);

        $this->assertCount(1, $props['anuncios']['data']);
        $this->assertFalse($props['skuNaoColetado']);
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
