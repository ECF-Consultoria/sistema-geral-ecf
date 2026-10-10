<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Publicador\SugestaoDeKitService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-08 (§6 da ETAPA-3) — o vínculo de combo JÁ cadastrado:
 * `PUT/DELETE …/produtos/{produto}/vinculo` e `POST …/vinculo/recusar`.
 *
 * A prova central deste arquivo é o critério de aceite da §9: **"Vincular não
 * altera rascunho, estoque nem MLBs"**. Vincular é o contrário de criar fase —
 * criar CLONA um rascunho inteiro; vincular só registra parentesco em três
 * colunas. O teste compara o snapshot do rascunho do combo e as linhas de
 * `pub_publicacao_itens` antes e depois.
 *
 * D-13: `{conta}` vem do resolver e o `base_id` — o ÚNICO id de entidade que
 * vem do CORPO nesta fase — é resolvido DENTRO de `produtosQuery()`. Fora do
 * escopo da conta: **404, nunca 403**.
 *
 * ⚠️ `Http::fake()` sem `assertNothingSent()` estrito: o `HandleInertiaRequests`
 * busca os sinais do ECF Drive em requisição que passa pelo middleware web.
 *
 * @group phase175
 */
class VinculoDeKitTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/mlb/anuncios/publicador';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array{0: MlbEmpresa, 1: Company} */
    private function conta(string $nome = 'Polo dos Combos'): array
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company->id]);
        MlToken::create([
            'company_id' => $company->id, 'ml_user_id' => (string) random_int(1000, 9999),
            'access_token' => 'fake-access', 'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addHours(5), 'status' => 'active',
        ]);

        return [$empresa->fresh(), $company];
    }

    private function produto(MlbEmpresa $e, Company $c, string $sku, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'mlb_empresa_id' => $e->id,
            'company_id' => $c->id,
            'sku' => $sku,
            'nome' => $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function schema(string $categoria = 'MLB193945'): void
    {
        if (MlCategoriaSchema::where('category_id', $categoria)->exists()) {
            return;
        }
        MlCategoriaSchema::create([
            'category_id' => $categoria,
            'categoria' => [
                'id' => $categoria, 'name' => 'Cadeiras de Escritório',
                'settings' => ['max_title_length' => 60],
                'path_from_root' => [['id' => $categoria, 'name' => 'Cadeiras de Escritório']],
            ],
            'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
            'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
        ]);
    }

    /** O rascunho completo do combo: categoria, alvo, variante com estoque e SELLER_SKU. */
    private function rascunhoCompleto(PubProduto $p, int $estoque = 11, string $status = PubRascunho::PUBLISHED): PubRascunho
    {
        $this->schema();
        $r = PubRascunho::create([
            'produto_id' => $p->id, 'status' => $status, 'revisao' => 1,
            'categoria_id' => 'MLB193945', 'condicao' => 'new',
            'descricao' => 'Combo de 2 cadeiras já cadastrado à mão.',
        ]);
        $r->alvos()->create(['listing_type_id' => 'gold_special', 'titulo' => 'Combo 2 Cadeiras', 'ativo' => true, 'posicao' => 0]);
        $v = $r->variantes()->create([
            'combinacao_chave' => ChaveCanonica::UNICA,
            'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA),
            'ativa' => true,
            'estoque' => $estoque,
        ]);
        $v->atributos()->create(['attribute_id' => 'SELLER_SKU', 'value_name' => $p->sku.'-UN']);

        return $r->fresh();
    }

    /** O anúncio no ar do combo: uma publicação concluída com um item CREATED. */
    private function publicado(PubRascunho $r, string $mlItemId = 'MLB111222'): PubPublicacaoItem
    {
        $pub = PubPublicacao::create([
            'rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => now()->subDay(), 'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev'],
        ]);

        return PubPublicacaoItem::create([
            'publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => $mlItemId, 'payload' => ['family_name' => 'Combo 2 Cadeiras'],
        ]);
    }

    private function url(MlbEmpresa $e, PubProduto $p, string $sufixo = ''): string
    {
        return self::BASE."/empresas/empresa-{$e->id}/produtos/{$p->id}/vinculo".$sufixo;
    }

    /**
     * O rascunho inteiro serializado — o "byte a byte" do critério de aceite da §9.
     *
     * ⚠️ `unidadesPorOferta` fica FORA da assinatura, e isso não afrouxa nada: ele
     * não é dado do rascunho, é a leitura de `pub_produtos.quantidade_kit` que o
     * `snapshot()` passou a expor (09/10/2026, para a capa do combo não rotacionar
     * entre Clássico e Premium). Vincular GRAVA essa coluna — é o que a §6 manda —
     * então o valor muda de 1 para 2 por definição, e exigi-lo igual seria exigir
     * que vincular não fizesse o seu trabalho. O que a §9 protege continua
     * protegido ao pé da letra: vincular não escreve NADA nas tabelas do rascunho,
     * e qualquer deriva em categoria, atributos, eixos, variantes, estoque, alvos,
     * fotos, descrição, envio ou garantia ainda reprova aqui.
     *
     * A mudança de 1 para 2 é afirmada à parte, no teste do vincular, para não
     * virar omissão silenciosa.
     */
    private function assinaturaDoRascunho(PubRascunho $r): string
    {
        $s = (array) app(RascunhoRepository::class)->snapshot($r->fresh());
        unset($s['unidadesPorOferta']);

        return (string) json_encode($s);
    }

    /** @return list<array> as linhas de publicação do rascunho, com MLB e payload */
    private function linhasDePublicacao(PubRascunho $r): array
    {
        return DB::table('pub_publicacao_itens')
            ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
            ->where('pub_publicacoes.rascunho_id', $r->id)
            ->orderBy('pub_publicacao_itens.id')
            ->get(['pub_publicacao_itens.id', 'pub_publicacao_itens.status', 'pub_publicacao_itens.ml_item_id',
                'pub_publicacao_itens.listing_type_id', 'pub_publicacao_itens.payload', 'pub_publicacao_itens.updated_at'])
            ->map(fn ($l) => (array) $l)
            ->all();
    }

    // ═══ As rotas ════════════════════════════════════════════════════════════

    public function test_as_tres_rotas_vivem_no_grupo_admin_com_throttle_nomeado(): void
    {
        $salvar = Route::getRoutes()->getByName('mlb.anuncios.publicador.vinculo.salvar');
        $remover = Route::getRoutes()->getByName('mlb.anuncios.publicador.vinculo.remover');
        $recusar = Route::getRoutes()->getByName('mlb.anuncios.publicador.vinculo.recusar');

        foreach (['salvar' => $salvar, 'remover' => $remover, 'recusar' => $recusar] as $nome => $rota) {
            $this->assertNotNull($rota, "a rota do vínculo '$nome' precisa existir com nome");
            $this->assertContains('role:admin', $rota->gatherMiddleware());
            $this->assertSame('(empresa|company)-[0-9]+', $rota->wheres['conta'] ?? null, 'D-13: {conta} morre na própria rota');
            $this->assertSame('[0-9]+', $rota->wheres['produto'] ?? null);
        }

        $this->assertContains('throttle:60,1,publicador.vinculo', $salvar->gatherMiddleware());
        $this->assertContains('throttle:60,1,publicador.vinculo', $remover->gatherMiddleware());
        $this->assertContains('throttle:60,1,publicador.vinculo.recusar', $recusar->gatherMiddleware());

        $this->assertSame(['PUT'], $salvar->methods());
        $this->assertSame(['DELETE'], $remover->methods());
        $this->assertSame(['POST'], $recusar->methods());
    }

    public function test_os_tres_endpoints_exigem_admin(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $combo = $this->produto($e, $c, 'CAD-CB2');
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->putJson($this->url($e, $combo), ['base_id' => $base->id, 'quantidade' => 2])->assertForbidden();
        $this->actingAs($consultor)->deleteJson($this->url($e, $combo))->assertForbidden();
        $this->actingAs($consultor)->postJson($this->url($e, $combo, '/recusar'))->assertForbidden();

        $this->assertNull($combo->fresh()->produto_base_id);
        $this->assertNull($combo->fresh()->kit_sugestao_recusada_em);
    }

    // ═══ Vincular ════════════════════════════════════════════════════════════

    public function test_vincular_grava_so_as_tres_colunas_e_nao_toca_rascunho_estoque_publicacoes_nem_mlbs(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->rascunhoCompleto($base, 25);

        $combo = $this->produto($e, $c, 'CAD-CB2', ['sku' => 'CAD-CB2', 'nome' => 'Combo 2 Cadeiras']);
        $rascunhoDoCombo = $this->rascunhoCompleto($combo, 11);
        $this->publicado($rascunhoDoCombo);

        $antesRascunho = $this->assinaturaDoRascunho($rascunhoDoCombo);
        $antesPublicacao = $this->linhasDePublicacao($rascunhoDoCombo);
        $antesDoRascunhoRow = DB::table('pub_rascunhos')->where('id', $rascunhoDoCombo->id)->first();

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $base->id, 'quantidade' => 2])
            ->assertOk()
            ->assertJsonPath('produto.produto_base_id', $base->id)
            ->assertJsonPath('produto.quantidade_kit', 2)
            ->assertJsonPath('produto.fase', 2)
            ->assertJsonPath('produto.eh_kit', true)
            ->assertJsonPath('produto.estoque_calculado', false)
            ->assertJsonPath('produto.rotulo_fase', 'Kit 2');

        $combo->refresh();
        $this->assertSame($base->id, (int) $combo->produto_base_id);
        $this->assertSame(2, (int) $combo->quantidade_kit);
        $this->assertSame(2, (int) $combo->fase, 'fase = quantidade: Kit 2 é a Fase 2');
        $this->assertFalse((bool) $combo->estoque_calculado, '§6: o combo vinculado MANTÉM o próprio estoque');

        // Nada do que era do produto mudou (SKU, nome, origem, oferta, âncoras).
        $this->assertSame('CAD-CB2', $combo->sku);
        $this->assertSame('Combo 2 Cadeiras', $combo->nome);
        $this->assertSame(PubProduto::ORIGEM_PUBLICADOR, $combo->origem);
        $this->assertNull($combo->oferta_id);
        $this->assertSame($e->id, (int) $combo->mlb_empresa_id);
        $this->assertSame($c->id, (int) $combo->company_id);
        $this->assertNull($combo->kit_sugestao_recusada_em);

        // O critério de aceite da §9: rascunho, estoque, publicações e MLBs intocados.
        $this->assertSame($antesRascunho, $this->assinaturaDoRascunho($rascunhoDoCombo), 'o rascunho do combo tem de ficar byte a byte igual');
        $this->assertSame($antesPublicacao, $this->linhasDePublicacao($rascunhoDoCombo), 'nenhuma linha de publicação (nem o ml_item_id) pode mudar');
        $this->assertEquals($antesDoRascunhoRow, DB::table('pub_rascunhos')->where('id', $rascunhoDoCombo->id)->first(), 'nem o updated_at do rascunho é tocado');
        $this->assertSame(11, (int) $rascunhoDoCombo->variantes()->first()->estoque, 'o estoque do combo continua o dele');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre'));

        // A ÚNICA coisa que a assinatura deixa de fora, afirmada aqui: o combo
        // passou a ser lido como oferta de 2 unidades. É consequência direta das
        // três colunas que vincular grava — não é escrita no rascunho — e é o que
        // faz a capa dele parar de rotacionar entre Clássico e Premium se ele for
        // republicado algum dia. O anúncio que já está no ar NÃO é atualizado por
        // isso (§7 da ETAPA-3: atualizar o ML não é desta etapa).
        $this->assertSame(2, app(RascunhoRepository::class)->snapshot($rascunhoDoCombo->fresh())->unidadesPorOferta);
    }

    /**
     * A fase vem da QUANTIDADE vinculada, não da ordem de criação: antes este vínculo de 5
     * unidades devolvia `fase: 2` com `rotulo_fase: 'Kit 5'` — dois números do mesmo produto.
     */
    public function test_vincular_cinco_unidades_na_familia_sem_kit_nasce_na_fase_5(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->rascunhoCompleto($base, 25);
        $combo = $this->produto($e, $c, 'CAD-CB5', ['sku' => 'CAD-CB5', 'nome' => 'Combo 5 Cadeiras']);
        $this->rascunhoCompleto($combo, 7);

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $base->id, 'quantidade' => 5])
            ->assertOk()
            ->assertJsonPath('produto.fase', 5)
            ->assertJsonPath('produto.quantidade_kit', 5)
            ->assertJsonPath('produto.rotulo_fase', 'Kit 5');

        $this->assertSame(5, (int) $combo->fresh()->fase, 'o banco confirma: Kit 5 é a Fase 5');
    }

    public function test_vincular_a_base_de_outra_conta_da_404_e_nao_grava(): void
    {
        [$e, $c] = $this->conta();
        $combo = $this->produto($e, $c, 'CAD-CB2');
        [$outraE, $outraC] = $this->conta('Outro Polo');
        $baseDeFora = $this->produto($outraE, $outraC, 'CAD');

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $baseDeFora->id, 'quantidade' => 2])
            ->assertNotFound();

        $this->assertNull($combo->fresh()->produto_base_id, 'T-175-32: base de outra empresa não existe neste escopo');
    }

    public function test_produto_da_url_de_outra_conta_da_404(): void
    {
        [$e, $c] = $this->conta();
        [$outraE, $outraC] = $this->conta('Outro Polo');
        $deFora = $this->produto($outraE, $outraC, 'FORA');
        $base = $this->produto($e, $c, 'CAD');

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $deFora), ['base_id' => $base->id, 'quantidade' => 2])
            ->assertNotFound();
        $this->actingAs($this->admin())->deleteJson($this->url($e, $deFora))->assertNotFound();
        $this->actingAs($this->admin())->postJson($this->url($e, $deFora, '/recusar'))->assertNotFound();
    }

    public function test_vincular_a_um_base_que_ja_e_kit_da_422_sem_cadeia(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $kit = $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $combo = $this->produto($e, $c, 'CAD-CB4');

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $kit->id, 'quantidade' => 4])
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-02');

        $this->assertNull($combo->fresh()->produto_base_id);
    }

    public function test_vincular_o_produto_a_si_mesmo_da_422(): void
    {
        [$e, $c] = $this->conta();
        $combo = $this->produto($e, $c, 'CAD-CB2');

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $combo->id, 'quantidade' => 2])
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-01');

        $this->assertNull($combo->fresh()->produto_base_id);
    }

    public function test_vincular_sem_quantidade_ou_com_quantidade_menor_que_dois_da_422_no_campo(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $combo = $this->produto($e, $c, 'CAD-CB2');
        $admin = $this->admin();

        $this->actingAs($admin)->putJson($this->url($e, $combo), ['base_id' => $base->id])
            ->assertStatus(422)->assertJsonValidationErrors('quantidade');
        $this->actingAs($admin)->putJson($this->url($e, $combo), ['base_id' => $base->id, 'quantidade' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('quantidade');
        $this->actingAs($admin)->putJson($this->url($e, $combo), ['quantidade' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('base_id');

        $this->assertNull($combo->fresh()->produto_base_id);
    }

    public function test_vincular_com_quantidade_que_a_familia_ja_tem_da_422_e_nao_grava(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $combo = $this->produto($e, $c, 'CAD-CB2');

        $r = $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $base->id, 'quantidade' => 2])
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-04')
            ->assertJsonPath('campo', 'quantidade');

        $this->assertSame('Já existe Kit 2 deste produto.', $r->json('message'));
        $this->assertNull($combo->fresh()->produto_base_id);
    }

    public function test_vincular_produto_que_ja_e_base_de_outras_fases_da_422(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $outroBase = $this->produto($e, $c, 'MESA');
        // O 'CAD' já é base do próprio kit: vinculá-lo a 'MESA' criaria cadeia.
        $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $base), ['base_id' => $outroBase->id, 'quantidade' => 3])
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-05');

        $this->assertNull($base->fresh()->produto_base_id);
    }

    public function test_vincular_produto_ja_vinculado_da_422(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $outroBase = $this->produto($e, $c, 'MESA');
        $kit = $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $kit), ['base_id' => $outroBase->id, 'quantidade' => 3])
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-06');

        $this->assertSame($base->id, (int) $kit->fresh()->produto_base_id);
    }

    // ═══ Desvincular ═════════════════════════════════════════════════════════

    public function test_desvincular_zera_as_tres_colunas_e_nao_toca_rascunho_nem_mlb(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $kit = $this->produto($e, $c, 'CAD-CB2', [
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2,
            'kit_sugestao_recusada_em' => now()->subDays(3),
        ]);
        $rascunhoDoKit = $this->rascunhoCompleto($kit, 11);
        $this->publicado($rascunhoDoKit, 'MLB333444');

        $antesRascunho = $this->assinaturaDoRascunho($rascunhoDoKit);
        $antesPublicacao = $this->linhasDePublicacao($rascunhoDoKit);
        $recusadoEm = $kit->fresh()->kit_sugestao_recusada_em;

        $this->actingAs($this->admin())->deleteJson($this->url($e, $kit))
            ->assertOk()
            ->assertJsonPath('produto.produto_base_id', null)
            ->assertJsonPath('produto.quantidade_kit', 1)
            ->assertJsonPath('produto.fase', 1)
            ->assertJsonPath('produto.eh_kit', false);

        $kit->refresh();
        $this->assertNull($kit->produto_base_id);
        $this->assertSame(1, (int) $kit->quantidade_kit);
        $this->assertSame(1, (int) $kit->fase);
        $this->assertEquals($recusadoEm, $kit->kit_sugestao_recusada_em, 'desfazer o vínculo NÃO mexe no carimbo do "Não é kit"');
        $this->assertSame($antesRascunho, $this->assinaturaDoRascunho($rascunhoDoKit));
        $this->assertSame($antesPublicacao, $this->linhasDePublicacao($rascunhoDoKit));
    }

    public function test_desvincular_produto_que_nunca_foi_kit_e_inocuo(): void
    {
        [$e, $c] = $this->conta();
        $solto = $this->produto($e, $c, 'CAD');

        $this->actingAs($this->admin())->deleteJson($this->url($e, $solto))->assertOk();

        $solto->refresh();
        $this->assertNull($solto->produto_base_id);
        $this->assertSame(1, (int) $solto->quantidade_kit);
        $this->assertSame(1, (int) $solto->fase);
    }

    // ═══ "Não é kit" ═════════════════════════════════════════════════════════

    public function test_recusar_grava_a_data_e_a_segunda_recusa_preserva_a_primeira(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $combo = $this->produto($e, $c, 'CAD-CB2');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->url($e, $combo, '/recusar'))
            ->assertOk()
            ->assertJsonPath('produto.sugestao_kit', null);

        $primeira = $combo->fresh()->kit_sugestao_recusada_em;
        $this->assertNotNull($primeira, 'T-175-36: a recusa tem data');

        $this->travel(2)->days();
        $this->actingAs($admin)->postJson($this->url($e, $combo, '/recusar'))->assertOk();
        $this->travelBack();

        $this->assertEquals($primeira, $combo->fresh()->kit_sugestao_recusada_em, 'a segunda recusa é idempotente: preserva a primeira data');
        $this->assertNull($combo->fresh()->produto_base_id, 'recusar não vincula nada');
        $this->assertNotNull($base->id);
    }

    public function test_depois_de_recusar_a_sugestao_nunca_volta(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $combo = $this->produto($e, $c, 'CAD-CB2');
        $sugestoes = app(SugestaoDeKitService::class);

        $this->assertNotNull($sugestoes->sugerirPara($combo), 'antes de recusar, CAD-CB2 recebe "Kit de CAD"');

        $this->actingAs($this->admin())->postJson($this->url($e, $combo, '/recusar'))->assertOk();

        $this->assertNull($sugestoes->sugerirPara($combo->fresh()), '§6: "Não é kit" não volta a sugerir');
        $this->assertSame([], $sugestoes->candidatosDaConta($e, $c), 'nem no lote da lista');
        $this->assertNotNull($base->id);
    }

    public function test_a_lista_para_de_sugerir_depois_do_vinculo(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $combo = $this->produto($e, $c, 'CAD-CB2');

        $this->actingAs($this->admin())
            ->putJson($this->url($e, $combo), ['base_id' => $base->id, 'quantidade' => 2])->assertOk();

        $lista = collect(app(\App\Services\Publicador\ProgramasPublicadorService::class)
            ->produtosParaTela($e, $c, 'empresa-'.$e->id))->keyBy('id');

        $this->assertNull($lista[$combo->id]['sugestao_kit'], 'produto já vinculado não recebe sugestão');
        $this->assertTrue($lista[$combo->id]['eh_kit']);
        $this->assertSame([$combo->id], $lista[$base->id]['kits']);
        $this->assertSame(['id' => $base->id, 'sku' => 'CAD', 'nome' => 'CAD'], $lista[$combo->id]['base']);
    }

    // ═══ §6 — "Usar estoque calculado" (quick 261009-uec) ═════════════════════
    //
    // A ação explícita da §6: o combo vinculado MANTÉM o próprio estoque, e
    // quem decide adotar o calculado é a pessoa, num clique, por kit. É por
    // isso que a ação grava `estoque_calculado = true` ANTES de chamar o
    // recálculo — o `RecalculoEstoqueDoKitService` pula de propósito quem está
    // em `false`, e esse contrato continua valendo depois desta ação.

    public function test_a_rota_do_estoque_calculado_vive_no_grupo_admin_com_o_throttle_da_familia(): void
    {
        $rota = Route::getRoutes()->getByName('mlb.anuncios.publicador.vinculo.estoque-calculado');

        $this->assertNotNull($rota, 'a rota de "Usar estoque calculado" precisa existir com nome');
        $this->assertContains('role:admin', $rota->gatherMiddleware());
        $this->assertSame('(empresa|company)-[0-9]+', $rota->wheres['conta'] ?? null, 'D-13: {conta} morre na própria rota');
        $this->assertSame('[0-9]+', $rota->wheres['produto'] ?? null);
        $this->assertContains('throttle:60,1,publicador.vinculo', $rota->gatherMiddleware(), 'é a mesma família de ação do vínculo');
        $this->assertSame(['POST'], $rota->methods());
    }

    public function test_usar_estoque_calculado_exige_admin(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $kit = $this->produto($e, $c, 'CAD-CB3', ['produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2]);

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->postJson($this->urlEstoque($e, $kit))
            ->assertForbidden();

        $this->assertFalse((bool) $kit->fresh()->estoque_calculado);
    }

    public function test_usar_estoque_calculado_de_produto_de_outra_conta_da_404_nunca_403(): void
    {
        [$e, $c] = $this->conta();
        [$outraE, $outraC] = $this->conta('Outro Polo');
        $baseDeFora = $this->produto($outraE, $outraC, 'CAD');
        $kitDeFora = $this->produto($outraE, $outraC, 'CAD-CB3', [
            'produto_base_id' => $baseDeFora->id, 'quantidade_kit' => 3, 'fase' => 2,
        ]);
        $this->assertNotNull($c->id);

        $this->actingAs($this->admin())->postJson($this->urlEstoque($e, $kitDeFora))->assertNotFound();

        $this->assertFalse((bool) $kitDeFora->fresh()->estoque_calculado, 'D-13: produto fora do escopo não existe aqui');
    }

    /**
     * ⚠️ O caso que distingue as duas contas possíveis: o recálculo divide CADA
     * DEPÓSITO e só depois soma. Com A=5, B=5 e N=3 isso dá `{A:1, B:1}` = 2;
     * somar primeiro (`floor(10 ÷ 3)`) daria 3. Se um dia alguém trocar a ordem,
     * este teste é o que cai.
     */
    public function test_usar_estoque_calculado_grava_a_coluna_e_divide_cada_deposito_antes_de_somar(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $rascunhoDoBase = $this->rascunhoCompleto($base, 10);
        $this->comDepositos($rascunhoDoBase, ['A' => 5, 'B' => 5]);

        $kit = $this->produto($e, $c, 'CAD-CB3', [
            'produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2,
        ]);
        $rascunhoDoKit = $this->rascunhoCompleto($kit, 11);

        $this->actingAs($this->admin())
            ->postJson($this->urlEstoque($e, $kit))
            ->assertOk()
            ->assertJsonPath('produto.id', $kit->id)
            ->assertJsonPath('produto.estoque_calculado', true)
            ->assertJsonPath('produto.produto_base_id', $base->id)
            ->assertJsonPath('produto.quantidade_kit', 3)
            ->assertJsonPath('produto.fase', 2)
            ->assertJsonPath('produto.eh_kit', true);

        $this->assertTrue((bool) $kit->fresh()->estoque_calculado, 'a coluna é gravada ANTES do recálculo, senão ele pularia o kit');

        $variante = $rascunhoDoKit->fresh()->variantes()->first();
        $this->assertSame(['A' => 1, 'B' => 1], $variante->estoque_depositos, 'floor por depósito');
        $this->assertSame(2, (int) $variante->estoque, 'soma dos divididos (2), nunca floor(soma ÷ N) (3)');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre'));
    }

    public function test_usar_estoque_calculado_nao_muda_sku_nome_precos_publicacoes_nem_mlb_do_kit(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $rascunhoDoBase = $this->rascunhoCompleto($base, 10);
        $this->comDepositos($rascunhoDoBase, ['A' => 5, 'B' => 5]);

        $kit = $this->produto($e, $c, 'CAD-CB3', [
            'produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2,
            'nome' => 'Combo 3 Cadeiras', 'sku' => 'CAD-CB3',
        ]);
        $rascunhoDoKit = $this->rascunhoCompleto($kit, 11);
        $this->comPreco($rascunhoDoKit, 349.9);
        $this->publicado($rascunhoDoKit, 'MLB999888');

        $antesSemEstoque = $this->assinaturaSemEstoque($rascunhoDoKit);
        $antesPublicacao = $this->linhasDePublicacao($rascunhoDoKit);
        $antesProduto = $this->linhaDoProduto($kit);
        $revisaoAntes = (int) $rascunhoDoKit->fresh()->revisao;

        $this->actingAs($this->admin())->postJson($this->urlEstoque($e, $kit))->assertOk();

        // O que a ação PODE mudar: o estoque das variantes e a revisão (a
        // conferência antiga do kit não vale para o estoque novo).
        $this->assertSame(2, (int) $rascunhoDoKit->fresh()->variantes()->value('estoque'));
        $this->assertGreaterThan($revisaoAntes, (int) $rascunhoDoKit->fresh()->revisao);

        // O que a ação NÃO pode mudar.
        $this->assertSame($antesSemEstoque, $this->assinaturaSemEstoque($rascunhoDoKit), 'categoria, título, descrição, preços e SELLER_SKU do kit ficam byte a byte iguais');
        $this->assertSame($antesPublicacao, $this->linhasDePublicacao($rascunhoDoKit), 'nenhuma linha de publicação (nem o ml_item_id) pode mudar');
        $this->assertSame($antesProduto, $this->linhaDoProduto($kit), 'nada do produto fora de `estoque_calculado` muda');

        $kit->refresh();
        $this->assertSame('CAD-CB3', $kit->sku);
        $this->assertSame('Combo 3 Cadeiras', $kit->nome);
        $this->assertNull($kit->oferta_id);
        $this->assertSame(349.9, (float) $rascunhoDoKit->fresh()->variantes()->first()->precos()->value('preco'));
    }

    public function test_usar_estoque_calculado_duas_vezes_e_idempotente(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $rascunhoDoBase = $this->rascunhoCompleto($base, 10);
        $this->comDepositos($rascunhoDoBase, ['A' => 5, 'B' => 5]);

        $kit = $this->produto($e, $c, 'CAD-CB3', [
            'produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2,
        ]);
        $rascunhoDoKit = $this->rascunhoCompleto($kit, 11);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->urlEstoque($e, $kit))->assertOk();
        $depoisDaPrimeira = DB::table('pub_rascunhos')->where('id', $rascunhoDoKit->id)->first();

        $this->travel(2)->minutes();
        $this->actingAs($admin)->postJson($this->urlEstoque($e, $kit))->assertOk();
        $this->travelBack();

        $this->assertTrue((bool) $kit->fresh()->estoque_calculado);
        $this->assertEquals(
            $depoisDaPrimeira,
            DB::table('pub_rascunhos')->where('id', $rascunhoDoKit->id)->first(),
            'a segunda vez não grava de novo nem recalcula: a revisão e o updated_at do rascunho ficam parados',
        );
        $this->assertSame(2, (int) $rascunhoDoKit->fresh()->variantes()->value('estoque'));
    }

    public function test_usar_estoque_calculado_em_produto_que_nao_e_kit_da_422(): void
    {
        [$e, $c] = $this->conta();
        $solto = $this->produto($e, $c, 'CAD');
        $this->rascunhoCompleto($solto, 10);

        $r = $this->actingAs($this->admin())->postJson($this->urlEstoque($e, $solto))
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-07');

        $this->assertStringNotContainsString('estoque_calculado', (string) $r->json('message'), 'mensagem em pt-BR, sem jargão de coluna');
        $this->assertFalse((bool) $solto->fresh()->estoque_calculado);
    }

    public function test_usar_estoque_calculado_em_kit_cujo_base_foi_apagado_da_422(): void
    {
        [$e, $c] = $this->conta();
        // `produto_base_id` NULL com `quantidade_kit >= 2` é exatamente o estado
        // que o SET NULL deixa quando o base é apagado (mesma leitura do
        // `base_excluido` do `FamiliaDeFasesService`).
        $orfao = $this->produto($e, $c, 'CAD-CB3', ['quantidade_kit' => 3, 'fase' => 2]);

        $this->actingAs($this->admin())->postJson($this->urlEstoque($e, $orfao))
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-08');

        $this->assertFalse((bool) $orfao->fresh()->estoque_calculado);
    }

    public function test_usar_estoque_calculado_sem_rascunho_no_base_da_422(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $kit = $this->produto($e, $c, 'CAD-CB3', [
            'produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2,
        ]);
        $this->rascunhoCompleto($kit, 11);

        $this->actingAs($this->admin())->postJson($this->urlEstoque($e, $kit))
            ->assertStatus(422)
            ->assertJsonPath('regra', 'VINC-09');

        $this->assertFalse((bool) $kit->fresh()->estoque_calculado, 'sem rascunho no base não há de onde ler estoque');
    }

    public function test_adotar_num_kit_nao_adota_no_combo_irmao_que_segue_com_estoque_proprio(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $rascunhoDoBase = $this->rascunhoCompleto($base, 10);
        $this->comDepositos($rascunhoDoBase, ['A' => 5, 'B' => 5]);

        $kit3 = $this->produto($e, $c, 'CAD-CB3', ['produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2]);
        $this->rascunhoCompleto($kit3, 11);
        $irmao = $this->produto($e, $c, 'CAD-CB4', ['produto_base_id' => $base->id, 'quantidade_kit' => 4, 'fase' => 3]);
        $rascunhoDoIrmao = $this->rascunhoCompleto($irmao, 7);

        $this->actingAs($this->admin())->postJson($this->urlEstoque($e, $kit3))->assertOk();

        $this->assertTrue((bool) $kit3->fresh()->estoque_calculado);
        $this->assertFalse((bool) $irmao->fresh()->estoque_calculado, 'a ação é POR KIT: o irmão continua com estoque próprio');
        $this->assertSame(7, (int) $rascunhoDoIrmao->fresh()->variantes()->value('estoque'), 'o recálculo continua PULANDO quem tem estoque_calculado = false');
    }

    // ═══ Apoio de "Usar estoque calculado" ═══════════════════════════════════

    private function urlEstoque(MlbEmpresa $e, PubProduto $p): string
    {
        return self::BASE."/empresas/empresa-{$e->id}/produtos/{$p->id}/estoque-calculado";
    }

    /**
     * Põe estoque POR DEPÓSITO na variante única — o caso que separa "divide
     * cada depósito e soma" de "soma e divide".
     */
    private function comDepositos(PubRascunho $r, array $depositos): void
    {
        $v = $r->variantes()->first();
        $v->estoque_depositos = $depositos;
        $v->estoque = array_sum(array_map('intval', $depositos));
        $v->save();
    }

    /** Um preço na variante única, para provar que a ação não mexe em preço. */
    private function comPreco(PubRascunho $r, float $preco): void
    {
        $r->fresh()->variantes()->first()->precos()->create([
            'alvo_id' => $r->fresh()->alvos()->value('id'),
            'preco' => $preco,
        ]);
    }

    /**
     * O rascunho serializado SEM os campos de estoque — adotar o calculado muda
     * o estoque de propósito, e tudo o mais tem de ficar byte a byte igual.
     */
    private function assinaturaSemEstoque(PubRascunho $r): string
    {
        $dados = json_decode((string) json_encode(app(RascunhoRepository::class)->snapshot($r->fresh())), true);
        foreach (array_keys($dados['variantes'] ?? []) as $i) {
            unset($dados['variantes'][$i]['dados']['estoque'], $dados['variantes'][$i]['dados']['estoque_depositos']);
        }

        return (string) json_encode($dados);
    }

    /** A linha do produto sem `estoque_calculado` nem `updated_at` — o resto não muda. */
    private function linhaDoProduto(PubProduto $p): array
    {
        $linha = (array) DB::table('pub_produtos')->where('id', $p->id)->first();
        unset($linha['estoque_calculado'], $linha['updated_at']);

        return $linha;
    }
}
