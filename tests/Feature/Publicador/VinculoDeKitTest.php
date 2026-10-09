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

    /** O rascunho inteiro serializado — o "byte a byte" do critério de aceite da §9. */
    private function assinaturaDoRascunho(PubRascunho $r): string
    {
        return (string) json_encode(app(RascunhoRepository::class)->snapshot($r->fresh()));
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
        $this->assertSame(2, (int) $combo->fase, 'fase = max(fase da família) + 1');
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
}
