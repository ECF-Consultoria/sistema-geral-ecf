<?php

namespace Tests\Feature\Phase86;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 86 / Fase 173-05 — Histórico dos anúncios publicados (base do
 * "Anunciar semelhante").
 *
 * Fase 173-05: a fonte mudou de `ml_anuncio_rascunhos` (assistente antigo)
 * para `pub_publicacoes`/`pub_publicacao_itens` (editor novo) — medido em
 * produção, o antigo tem ZERO publicações em qualquer empresa. As fixtures
 * abaixo montam a cadeia PubProduto → PubRascunho → PubPublicacao →
 * PubPublicacaoItem que a query nova lê.
 *
 * O que blinda:
 *   (a) só `pub_publicacao_itens.status = CREATED` entra — SENT/PENDING/
 *       FAILED/UNKNOWN (equivalentes a rascunho/erro/publicando) ficam de fora
 *   (b) escopo por empresa (via pub_produtos.company_id/mlb_empresa_id):
 *       anúncio de outra empresa nunca aparece
 *   (c) a BUSCA (título OU SKU) não fura o escopo — o orWhere precisa estar
 *       agrupado, senão o `or` sobe ao topo do WHERE e vaza anúncio de outra
 *       empresa/status
 *   (d) ordenação por concluída_em desc
 *   (e) empresa sem MlToken → 404 (mesma trava do wizard e da grade)
 *   (f) consultor → 403 (middleware role:admin)
 *   (g) agrupamento por lote (categoria do rascunho + dia de conclusão) e
 *       paginação por lote
 *   (h) todo item devolvido tem pode_duplicar=false (não existe rotina de
 *       clonar PubRascunho — ver 173-05-PLAN.md)
 *   (i) empresa sem nenhum item CREATED → mensagem de vazio
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake — nunca ML real.
 *
 * @group phase86
 */
class HistoricoAnunciosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nenhuma chamada real ao ML nestes testes
    }

    /** @test */
    public function historico_traz_apenas_os_itens_criados_de_verdade_no_ml(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Camiseta Publicada', status: PubPublicacaoItem::CREATED);
        $this->criarAnuncioPub($company, 'Camiseta Pendente', status: PubPublicacaoItem::PENDING);
        $this->criarAnuncioPub($company, 'Camiseta Enviada', status: PubPublicacaoItem::SENT);
        $this->criarAnuncioPub($company, 'Camiseta Com Erro', status: PubPublicacaoItem::FAILED);
        $this->criarAnuncioPub($company, 'Camiseta Incerta', status: PubPublicacaoItem::UNKNOWN);

        $titulos = $this->titulosDoHistorico($admin, $company);

        $this->assertContains('Camiseta Publicada', $titulos);
        $this->assertNotContains('Camiseta Pendente', $titulos, 'pending nao e historico');
        $this->assertNotContains('Camiseta Enviada', $titulos, 'sent nao e historico (ainda em voo)');
        $this->assertNotContains('Camiseta Com Erro', $titulos, 'failed nao e historico');
        $this->assertNotContains('Camiseta Incerta', $titulos, 'unknown nao e historico');
    }

    /** @test */
    public function item_do_historico_traz_titulo_preco_e_foto_do_payload(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Bota de Couro', preco: 199.9, fotoId: 'ml-pic-1', fotoUrl: 'https://x/bota.jpg');

        $grupos = $this->gruposDoHistorico($admin, $company);
        $item = $grupos[0]['itens'][0];

        $this->assertSame('Bota de Couro', $item['titulo']);
        $this->assertSame(199.9, $item['preco']);
        $this->assertSame('https://x/bota.jpg', $item['foto']);
        $this->assertFalse($item['pode_duplicar'], 'item do editor novo nunca pode duplicar hoje');
    }

    /** @test */
    public function historico_nao_vaza_anuncio_de_outra_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Minha Camiseta');
        $this->criarAnuncioPub($outra, 'Camiseta da Outra Empresa');

        $titulos = $this->titulosDoHistorico($admin, $company);

        $this->assertContains('Minha Camiseta', $titulos);
        $this->assertNotContains('Camiseta da Outra Empresa', $titulos, 'VAZAMENTO entre empresas');
    }

    /** @test */
    public function busca_por_titulo_filtra_sem_furar_o_escopo(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Camiseta Válida');
        $this->criarAnuncioPub($outra, 'Camiseta de Outra Empresa');
        $this->criarAnuncioPub($company, 'Camiseta Pendente', status: PubPublicacaoItem::PENDING);

        $titulos = $this->titulosDoHistorico($admin, $company, ['busca' => 'Camiseta']);

        $this->assertSame(['Camiseta Válida'], $titulos,
            'a busca tem que respeitar empresa E status — orWhere solto vazaria os outros dois');
    }

    /** @test */
    public function busca_por_sku_filtra_sem_furar_o_escopo(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Produto A', sku: 'SKU-ALVO-123');
        $this->criarAnuncioPub($company, 'Produto B', sku: 'SKU-OUTRO-999');
        $this->criarAnuncioPub($outra, 'Produto C', sku: 'SKU-ALVO-123');

        $titulos = $this->titulosDoHistorico($admin, $company, ['busca' => 'SKU-ALVO']);

        $this->assertSame(['Produto A'], $titulos, 'busca por SKU tem que respeitar o escopo de empresa');
    }

    /** @test */
    public function historico_ordena_do_mais_recente_para_o_mais_antigo(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Antigo', concluidaEm: now()->subDays(5));
        $this->criarAnuncioPub($company, 'Recente', concluidaEm: now()->subHour());

        $this->assertSame(['Recente', 'Antigo'], $this->titulosDoHistorico($admin, $company));
    }

    /** @test */
    public function empresa_sem_conta_ml_devolve_404(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create(); // sem MlToken

        $this->actingAs($admin)
            ->get("/mlb/anuncios/historico/{$company->id}")
            ->assertNotFound();
    }

    /** @test */
    public function consultor_nao_acessa_o_historico(): void
    {
        [$company] = $this->criarFixture();
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->get("/mlb/anuncios/historico/{$company->id}")
            ->assertForbidden();
    }

    /** @test */
    public function empresa_sem_nenhum_item_criado_mostra_vazio(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // Só um item ainda não criado no ML (equivalente a "rascunho") — não deve aparecer.
        $this->criarAnuncioPub($company, 'Ainda Não Publicado', status: PubPublicacaoItem::PENDING);

        $grupos = $this->gruposDoHistorico($admin, $company);

        $this->assertSame([], $grupos, 'sem nenhum item CREATED, o histórico tem que vir vazio');
    }

    /** @test */
    public function anuncios_da_mesma_categoria_e_dia_colapsam_num_unico_lote(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // 3 meias publicadas em massa (mesma categoria, mesmo dia) → 1 lote de total=3
        $this->criarAnuncioPub($company, 'Meia P', categoriaId: 'MLB108791');
        $this->criarAnuncioPub($company, 'Meia M', categoriaId: 'MLB108791');
        $this->criarAnuncioPub($company, 'Meia G', categoriaId: 'MLB108791');

        $grupos = $this->gruposDoHistorico($admin, $company);

        $this->assertCount(1, $grupos, 'as 3 meias do mesmo lote têm que virar 1 grupo');
        $this->assertSame(3, $grupos[0]['total']);
        $this->assertCount(3, $grupos[0]['itens']);
    }

    /** @test */
    public function mesma_categoria_em_dias_diferentes_sao_lotes_separados(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarAnuncioPub($company, 'Meia Ontem', categoriaId: 'MLB108791', concluidaEm: now()->subDay());
        $this->criarAnuncioPub($company, 'Meia Hoje', categoriaId: 'MLB108791', concluidaEm: now());

        $grupos = $this->gruposDoHistorico($admin, $company);

        $this->assertCount(2, $grupos, 'mesma categoria em dias diferentes = 2 lotes');
        // mais recente primeiro (concluida_em desc)
        $this->assertSame('Meia Hoje', $grupos[0]['itens'][0]['titulo']);
    }

    /** @test */
    public function paginacao_traz_12_lotes_por_pagina(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // 13 lotes distintos (categoria diferente cada um → 13 grupos de total=1)
        for ($i = 1; $i <= 13; $i++) {
            $this->criarAnuncioPub($company, "Produto {$i}", categoriaId: "MLB{$i}");
        }

        $pagina1 = $this->actingAs($admin)
            ->get("/mlb/anuncios/historico/{$company->id}")
            ->assertOk()
            ->viewData('page')['props']['grupos'];

        $this->assertCount(12, $pagina1['data'], 'página 1 tem que trazer exatamente 12 lotes');
        $this->assertSame(13, $pagina1['total']);

        $pagina2 = $this->actingAs($admin)
            ->get("/mlb/anuncios/historico/{$company->id}?page=2")
            ->assertOk()
            ->viewData('page')['props']['grupos'];

        $this->assertCount(1, $pagina2['data'], 'página 2 tem que trazer o 13º lote restante');
    }

    // ─── helpers ───

    /** @test */
    public function anunciar_semelhante_em_massa_clona_o_lote_inteiro_como_rascunho(): void
    {
        // Este endpoint (duplicar-lote) NÃO foi tocado pelo plano 173-05 — ele só
        // existe para MlAnuncioRascunho (assistente antigo). Mantido aqui tal como
        // estava, sem relação com a fonte de dados do historico().
        [$company, , $admin] = $this->criarFixture();

        $a = $this->criarAnuncioLegado($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Meia P', now(), 'MLB108791');
        $b = $this->criarAnuncioLegado($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Meia M', now(), 'MLB108791');

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/empresa/{$company->id}/duplicar-lote", [
                'rascunho_ids' => [$a->id, $b->id],
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'criados' => 2]);

        // 2 clones novos: status rascunho, ml_item_id zerado, mesma categoria e título copiado
        $clones = MlAnuncioRascunho::where('company_id', $company->id)
            ->where('status', MlAnuncioRascunho::STATUS_RASCUNHO)
            ->get();

        $this->assertCount(2, $clones, 'o lote inteiro tem que virar 2 rascunhos novos');
        $this->assertTrue($clones->every(fn ($r) => $r->ml_item_id === null), 'clone nao pode carregar ml_item_id');
        $this->assertTrue($clones->every(fn ($r) => $r->category_id === 'MLB108791'), 'clone mantem a categoria do lote');
        $this->assertEqualsCanonicalizing(['Meia P', 'Meia M'], $clones->pluck('payload.title')->all());
    }

    /** @test */
    public function anunciar_semelhante_em_massa_nao_clona_anuncio_de_outra_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $meu    = $this->criarAnuncioLegado($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Meu', now(), 'MLB108791');
        $alheio = $this->criarAnuncioLegado($outra, MlAnuncioRascunho::STATUS_PUBLICADO, 'Alheio', now(), 'MLB108791');

        // pede um id da OUTRA empresa junto → 403, nada é clonado
        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/empresa/{$company->id}/duplicar-lote", [
                'rascunho_ids' => [$meu->id, $alheio->id],
            ])
            ->assertForbidden();

        $this->assertSame(0, MlAnuncioRascunho::where('status', MlAnuncioRascunho::STATUS_RASCUNHO)->count(),
            'nenhum clone pode ser criado quando um id e de outra empresa');
    }

    // ─── helpers ───

    /** Achata os títulos de todos os grupos, preservando a ordem (grupo → item). */
    private function titulosDoHistorico(User $admin, Company $company, array $query = []): array
    {
        return collect($this->gruposDoHistorico($admin, $company, $query))
            ->flatMap(fn ($g) => collect($g['itens'])->pluck('titulo'))
            ->all();
    }

    /** Grupos crus (lotes) do histórico. */
    private function gruposDoHistorico(User $admin, Company $company, array $query = []): array
    {
        $url = "/mlb/anuncios/historico/{$company->id}";
        if ($query) $url .= '?' . http_build_query($query);

        $response = $this->actingAs($admin)->get($url)->assertOk();

        return $response->viewData('page')['props']['grupos']['data'];
    }

    /**
     * Monta a cadeia inteira do editor novo: PubProduto → PubRascunho →
     * PubPublicacao → PubPublicacaoItem (CREATED = "publicado de verdade").
     */
    private function criarAnuncioPub(
        Company $company,
        string $titulo,
        string $categoriaId = 'MLB123456',
        $concluidaEm = null,
        float $preco = 49.9,
        string $fotoId = 'ml-pic-default',
        ?string $fotoUrl = 'https://x/foto.jpg',
        ?string $sku = null,
        string $status = PubPublicacaoItem::CREATED,
    ): PubPublicacaoItem {
        $produto = PubProduto::create([
            'company_id' => $company->id,
            'mlb_empresa_id' => null,
            'sku' => $sku ?? ('SKU-' . Str::random(8)),
            'nome' => $titulo,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        $rascunho = PubRascunho::create([
            'produto_id' => $produto->id,
            'status' => PubRascunho::PUBLISHED,
            'revisao' => 1,
            'categoria_id' => $categoriaId,
            'condicao' => 'new',
        ]);

        if ($fotoUrl !== null) {
            PubImagem::create([
                'rascunho_id' => $rascunho->id,
                'ml_picture_id' => $fotoId,
                'ml_url' => $fotoUrl,
                'upload_status' => PubImagem::ENVIADA,
            ]);
        }

        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => 1,
            'modelo_publicacao' => 'user_products',
            'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => $concluidaEm ?? now(),
        ]);

        return PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id,
            'indice' => 0,
            'listing_type_id' => 'gold_special',
            'variante_chave' => '__single__',
            'payload' => [
                'family_name' => $titulo,
                'price' => $preco,
                'pictures' => $fotoUrl !== null ? [['id' => $fotoId]] : [],
            ],
            'status' => $status,
            'ml_item_id' => $status === PubPublicacaoItem::CREATED ? 'MLB' . random_int(100000, 999999) : null,
        ]);
    }

    /** Fixture do assistente ANTIGO — só para os 2 testes de duplicar-lote, que não mudaram. */
    private function criarAnuncioLegado(Company $company, string $status, string $titulo, $publishedAt = null, string $categoryId = 'MLB123456'): MlAnuncioRascunho
    {
        return MlAnuncioRascunho::create([
            'company_id'   => $company->id,
            'user_id'      => User::factory()->create()->id,
            'category_id'  => $categoryId,
            'listing_tier' => 'gold_special',
            'status'       => $status,
            'published_at' => $status === MlAnuncioRascunho::STATUS_PUBLICADO ? ($publishedAt ?? now()) : null,
            'ml_item_id'   => $status === MlAnuncioRascunho::STATUS_PUBLICADO ? 'MLB999' : null,
            'payload'      => [
                'title'    => $titulo,
                'price'    => 49.9,
                'pictures' => [['source' => 'https://x/foto.jpg']],
            ],
        ]);
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',
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
            'nome'           => 'Empresa Historico ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }
}
