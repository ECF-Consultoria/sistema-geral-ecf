<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarSugestaoKitIaJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\SugestaoKitIaService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-05 (§4 da ETAPA-3) — os dois endpoints do painel
 * "Criar Fase 2": `publicador.fases.previa` (lê, nunca grava) e
 * `publicador.fases.criar` (grava numa transação).
 *
 * Disciplinas que este arquivo guarda:
 *
 * 1. **Produto de outra conta é 404, nunca 403** (D-13). O produto é buscado
 *    DENTRO de `produtosQuery()`: fora do escopo ele não existe.
 * 2. **Nenhuma âncora vem do corpo** (T-175-17). Mandar `mlb_empresa_id`,
 *    `company_id` ou `produto_base_id` no POST não muda nada.
 * 3. **O estoque é recalculado no servidor** (T-175-18): o corpo não tem campo
 *    de estoque, e o que mandarem é ignorado.
 * 4. **Zero job disparado nesta plan** (`Queue::fake()`): a capa é só uma flag
 *    registrada na resposta; quem dispara é o 175-07.
 *
 * ⚠️ `Http::fake()` SEM `assertNothingSent()` estrito: numa requisição que passa
 * pelo middleware web o `HandleInertiaRequests` busca os sinais do ECF Drive. As
 * asserções de "nada falou com o ML" filtram por `mercadolibre`.
 *
 * @group phase175
 */
class CriarFaseEndpointTest extends TestCase
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
    private function conta(): array
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo das Fases', 'projeto' => 'POLOS', 'company_id' => $company->id]);
        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => '1555596317',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        return [$empresa->fresh(), $company];
    }

    private function base(MlbEmpresa $e, ?Company $c = null, string $sku = 'CAD'): PubProduto
    {
        return PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'company_id' => $c?->id,
            'sku' => $sku,
            'nome' => 'Cadeira Escritório',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function schema(string $categoria = 'MLB193945'): void
    {
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

    /** O base pronto para virar kit: rascunho PUBLICADO, dois tipos, variante única com estoque 7. */
    private function rascunhoPublicado(PubProduto $base, string $status = PubRascunho::PUBLISHED): PubRascunho
    {
        $this->schema();
        $r = PubRascunho::create([
            'produto_id' => $base->id,
            'status' => $status,
            'revisao' => 1,
            'categoria_id' => 'MLB193945',
            'condicao' => 'new',
            'descricao' => 'Cadeira executiva com apoio lombar.',
        ]);
        $r->alvos()->create(['listing_type_id' => 'gold_special', 'titulo' => 'Cadeira Escritório Executiva', 'ativo' => true, 'posicao' => 0]);
        $r->alvos()->create(['listing_type_id' => 'gold_pro', 'titulo' => 'Cadeira Escritório Premium', 'ativo' => true, 'posicao' => 1]);

        $v = $r->variantes()->create([
            'combinacao_chave' => ChaveCanonica::UNICA,
            'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA),
            'ativa' => true,
            'estoque' => 7,
        ]);
        $v->atributos()->create(['attribute_id' => 'SELLER_SKU', 'value_name' => 'CAD-UN']);

        return $r;
    }

    private function urlPrevia(MlbEmpresa $e, PubProduto $p, string $query = '?quantidade=2'): string
    {
        return self::BASE."/empresas/empresa-{$e->id}/produtos/{$p->id}/fases/previa{$query}";
    }

    private function urlCriar(MlbEmpresa $e, PubProduto $p): string
    {
        return self::BASE."/empresas/empresa-{$e->id}/produtos/{$p->id}/fases";
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return [
            'quantidade' => 2,
            'sku' => 'CAD-KIT2',
            'titulo' => 'Kit 2 Cadeira Escritório Executiva',
            'descricao' => "Este kit contém 2 unidades de Cadeira Escritório.\n\nCadeira executiva com apoio lombar.",
            ...$extra,
        ];
    }

    private function semChamadaAoMl(): void
    {
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre'));
    }

    // ═══ As rotas: grupo, throttle e restrições ══════════════════════════════

    public function test_as_duas_rotas_vivem_no_grupo_admin_com_throttle_nomeado(): void
    {
        $previa = Route::getRoutes()->getByName('mlb.anuncios.publicador.fases.previa');
        $criar = Route::getRoutes()->getByName('mlb.anuncios.publicador.fases.criar');

        $this->assertNotNull($previa, 'a rota da prévia precisa existir com nome');
        $this->assertNotNull($criar, 'a rota da criação precisa existir com nome');

        $this->assertContains('role:admin', $previa->gatherMiddleware());
        $this->assertContains('role:admin', $criar->gatherMiddleware());
        $this->assertContains('throttle:120,1,publicador.fases.previa', $previa->gatherMiddleware());
        // Mesmo teto do `publicador.produtos.criar` — a §8 manda seguir o padrão dele.
        $this->assertContains('throttle:60,1,publicador.fases.criar', $criar->gatherMiddleware());

        foreach ([$previa, $criar] as $rota) {
            $this->assertSame('(empresa|company)-[0-9]+', $rota->wheres['conta'] ?? null, 'T-175-15: {conta} morre na rota');
            $this->assertSame('[0-9]+', $rota->wheres['produto'] ?? null);
        }
        $this->assertSame(['GET', 'HEAD'], $previa->methods());
        $this->assertSame(['POST'], $criar->methods());
    }

    public function test_os_dois_endpoints_exigem_admin(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->getJson($this->urlPrevia($e, $base))->assertForbidden();
        $this->actingAs($consultor)->postJson($this->urlCriar($e, $base), $this->corpo())->assertForbidden();
        $this->assertSame(1, PubProduto::count());
    }

    /** `{conta}` fora do padrão morre na própria rota, antes do controller. */
    public function test_conta_fora_do_padrao_nao_casa_a_rota(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);

        $this->actingAs($this->admin())
            ->getJson(self::BASE."/empresas/abc/produtos/{$base->id}/fases/previa?quantidade=2")
            ->assertNotFound();
    }

    // ═══ A prévia ════════════════════════════════════════════════════════════

    public function test_previa_devolve_o_payload_e_nao_grava_nada(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $antes = [PubProduto::count(), PubRascunho::count()];

        $json = $this->actingAs($this->admin())->getJson($this->urlPrevia($e, $base))->assertOk()->json();

        $this->assertSame(2, $json['quantidade']);
        $this->assertSame('CAD-KIT2', $json['sku']);
        $this->assertSame(['gold_special', 'gold_pro'], $json['tipos']);
        $this->assertSame('Kit 2 Cadeira Escritório Executiva', $json['titulo_por_tipo']['gold_special']);
        $this->assertStringStartsWith('Este kit contém 2 unidades de Cadeira Escritório.', $json['descricao']);
        $this->assertSame(3, $json['variantes'][ChaveCanonica::UNICA]['estoque'], 'floor(7 ÷ 2)');
        $this->assertSame('CAD-UN-KIT2', $json['variantes'][ChaveCanonica::UNICA]['seller_sku']);
        $this->assertSame(60, $json['max_title_length']);
        $this->assertNull($json['erro_campo']);
        $this->assertContains('preco_vazio', array_column($json['avisos'], 'chave'));

        $this->assertSame($antes, [PubProduto::count(), PubRascunho::count()], 'a prévia NÃO grava');
        $this->semChamadaAoMl();
    }

    /**
     * ⚠️ Lição da tela preta: o painel exibe `avisos[].mensagem` e `erro_campo`
     * direto. Se algum chegasse como objeto, a página do operador apagaria.
     */
    public function test_previa_devolve_tudo_o_que_a_tela_exibe_como_texto(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);

        $json = $this->actingAs($this->admin())->getJson($this->urlPrevia($e, $base))->assertOk()->json();

        $this->assertIsString($json['sku']);
        $this->assertIsString($json['descricao']);
        $this->assertIsInt($json['quantidade']);
        $this->assertIsInt($json['max_title_length']);
        $this->assertIsArray($json['avisos']);
        foreach ($json['avisos'] as $aviso) {
            $this->assertIsString($aviso['chave']);
            $this->assertIsString($aviso['mensagem']);
        }
        foreach ($json['titulo_por_tipo'] as $titulo) {
            $this->assertIsString($titulo);
        }
    }

    /** @return list<array{0: mixed}> */
    public static function quantidadesRecusadas(): array
    {
        return [
            'ausente' => [null],
            'um' => [1],
            'zero' => [0],
            'negativa' => [-3],
            'nao numerica' => ['dois'],
            'fracionaria' => [2.5],
            'acima do unsignedSmallInteger' => [65536],
        ];
    }

    /**
     * A quantidade é validada nos DOIS endpoints. 65536 estouraria o
     * `unsignedSmallInteger` de `quantidade_kit` (T-175-20).
     */
    #[DataProvider('quantidadesRecusadas')]
    public function test_quantidade_invalida_e_422_com_mensagem_de_campo(mixed $quantidade): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $this->actingAs($this->admin());

        $query = $quantidade === null ? '' : '?quantidade='.urlencode((string) $quantidade);
        $this->getJson($this->urlPrevia($e, $base, $query))->assertStatus(422)->assertJsonValidationErrors('quantidade');

        $corpo = $this->corpo();
        if ($quantidade === null) {
            unset($corpo['quantidade']);
        } else {
            $corpo['quantidade'] = $quantidade;
        }
        $this->postJson($this->urlCriar($e, $base), $corpo)->assertStatus(422)->assertJsonValidationErrors('quantidade');

        $this->assertSame(1, PubProduto::count(), 'nada criado numa recusa de validação');
    }

    public function test_previa_de_produto_de_outra_conta_e_404(): void
    {
        [$e] = $this->conta();
        [$outra] = $this->conta();
        $daOutra = $this->base($outra, null, 'OUTRO');
        $this->rascunhoPublicado($daOutra);

        $this->actingAs($this->admin())->getJson($this->urlPrevia($e, $daOutra))->assertNotFound();
        $this->actingAs($this->admin())->postJson($this->urlCriar($e, $daOutra), $this->corpo())->assertNotFound();
    }

    /** Abrir um KIT pede a prévia do BASE da família — a mesma regra do `mostrar()`. */
    public function test_previa_pedida_por_um_kit_responde_sobre_o_base(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $kit = PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => 'CAD-KIT2', 'nome' => 'Kit 2 Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2,
        ]);

        $json = $this->actingAs($this->admin())->getJson($this->urlPrevia($e, $kit, '?quantidade=3'))->assertOk()->json();

        $this->assertSame('CAD-KIT3', $json['sku'], 'o SKU parte do SKU do BASE, não do kit aberto');
        $this->assertSame(2, $json['variantes'][ChaveCanonica::UNICA]['estoque'], 'floor(7 ÷ 3)');
        $this->assertNull($json['erro_campo']);
    }

    public function test_previa_marca_quantidade_ja_existente_como_erro_de_campo(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => 'CAD-KIT2', 'nome' => 'Kit 2 Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2,
        ]);

        $json = $this->actingAs($this->admin())->getJson($this->urlPrevia($e, $base))->assertOk()->json();

        $this->assertSame('Já existe Kit 2 deste produto.', $json['erro_campo']);
    }

    // ═══ A criação ═══════════════════════════════════════════════════════════

    public function test_criar_responde_201_com_a_url_do_editor_na_etapa_condicoes(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);

        $json = $this->actingAs($this->admin())
            ->postJson($this->urlCriar($e, $base), $this->corpo())
            ->assertStatus(201)
            ->json();

        $kit = PubProduto::where('produto_base_id', $base->id)->firstOrFail();
        $this->assertSame($kit->id, $json['produto']['id']);
        $this->assertSame(route('mlb.anuncios.publicador.editor', ['produto' => $kit->id]).'?etapa=condicoes', $json['url']);
        $this->assertFalse($json['capa_pedida']);

        // O que o clone gravou a partir do que a prévia calculou.
        $this->assertSame('CAD-KIT2', $kit->sku);
        $this->assertSame(2, $kit->quantidade_kit);
        $this->assertSame(2, $kit->fase);
        $this->assertTrue($kit->estoque_calculado);
        $this->assertNull($kit->oferta_id, 'decisão 5: o SKU do kit existe só no Publicador');

        $rk = $kit->rascunho;
        $this->assertSame(PubRascunho::DRAFT, $rk->status);
        $this->assertSame(['gold_special', 'gold_pro'], $rk->alvos->pluck('listing_type_id')->all());
        $this->assertSame('Kit 2 Cadeira Escritório Executiva', $rk->alvos->firstWhere('listing_type_id', 'gold_special')->titulo);
        $this->assertStringStartsWith('Este kit contém 2 unidades de', $rk->descricao);
        $this->assertSame(3, (int) $rk->variantes->first()->estoque, 'floor(7 ÷ 2)');
        $this->assertSame('CAD-UN-KIT2', $rk->variantes->first()->atributos->firstWhere('attribute_id', 'SELLER_SKU')->value_name);

        // O preço nasce vazio (§5) — a decisão de 2026-10-08 manda avisar, não bloquear.
        $this->assertGreaterThan(0, $rk->variantes->first()->precos->count());
        $this->assertNull($rk->variantes->first()->precos->first()->preco);

        $this->semChamadaAoMl();
        Queue::assertNothingPushed();
    }

    /** T-175-21: quem criou a fase fica gravado, e vem do usuário logado — nunca do corpo. */
    public function test_ator_gravado_e_o_usuario_logado(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $admin = $this->admin();
        $outro = User::factory()->create(['role' => 'admin', 'name' => 'Quem Não Clicou']);

        $this->actingAs($admin)
            ->postJson($this->urlCriar($e, $base), $this->corpo(['ator' => ['equipe' => true, 'id' => $outro->id, 'nome' => $outro->name]]))
            ->assertStatus(201);

        $ator = PubProduto::where('produto_base_id', $base->id)->firstOrFail()->rascunho->ator;
        $this->assertTrue($ator['equipe']);
        $this->assertSame($admin->id, $ator['id'], 'o ator do corpo é ignorado');
        $this->assertSame($admin->name, $ator['nome']);
    }

    public function test_quantidade_duplicada_e_422_com_mensagem_de_campo_e_nada_e_criado(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => 'CAD-KIT2-ANTIGO', 'nome' => 'Kit 2 Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2,
        ]);
        $antes = PubProduto::count();

        $json = $this->actingAs($this->admin())
            ->postJson($this->urlCriar($e, $base), $this->corpo())
            ->assertStatus(422)
            ->json();

        $this->assertSame('Já existe Kit 2 deste produto.', $json['message']);
        $this->assertSame('KIT-04', $json['regra']);
        $this->assertSame('quantidade', $json['campo'], 'o painel marca o campo da quantidade');
        $this->assertSame($antes, PubProduto::count());
    }

    public function test_base_nao_publicado_e_422_publique_a_fase_1_primeiro(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base, PubRascunho::DRAFT);

        $json = $this->actingAs($this->admin())
            ->postJson($this->urlCriar($e, $base), $this->corpo())
            ->assertStatus(422)
            ->json();

        $this->assertSame('Publique a Fase 1 primeiro', $json['message']);
        $this->assertSame(1, PubProduto::count());
    }

    /** `PARTIALLY_PUBLISHED` ("Parte publicada") conta como publicado, como a §9 manda. */
    public function test_base_parcialmente_publicado_pode_criar_fase(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base, PubRascunho::PARTIALLY_PUBLISHED);

        $this->actingAs($this->admin())->postJson($this->urlCriar($e, $base), $this->corpo())->assertStatus(201);
        $this->assertSame(2, PubProduto::count());
    }

    public function test_base_sem_rascunho_e_422_kit_01(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);

        $json = $this->actingAs($this->admin())
            ->postJson($this->urlCriar($e, $base), $this->corpo())
            ->assertStatus(422)
            ->json();

        $this->assertSame(1, PubProduto::count());
        $this->assertNotSame('', (string) $json['message']);
    }

    /** T-175-17: âncora no corpo não cria kit em conta alheia. */
    public function test_ancoras_do_corpo_sao_ignoradas(): void
    {
        [$e, $c] = $this->conta();
        [$outra, $outraCompany] = $this->conta();
        $base = $this->base($e, $c);
        $this->rascunhoPublicado($base);
        $alheio = $this->base($outra, $outraCompany, 'ALHEIO');

        $this->actingAs($this->admin())->postJson($this->urlCriar($e, $base), $this->corpo([
            'mlb_empresa_id' => $outra->id,
            'company_id' => $outraCompany->id,
            'produto_base_id' => $alheio->id,
            'origem' => PubProduto::ORIGEM_PORTAL,
            'oferta_id' => 999,
            'fase' => 99,
            'estoque_calculado' => false,
        ]))->assertStatus(201);

        $kit = PubProduto::where('sku', 'CAD-KIT2')->firstOrFail();
        $this->assertSame($e->id, $kit->mlb_empresa_id, 'a âncora é a do BASE');
        $this->assertSame($c->id, $kit->company_id);
        $this->assertSame($base->id, $kit->produto_base_id);
        $this->assertSame(PubProduto::ORIGEM_PUBLICADOR, $kit->origem);
        $this->assertNull($kit->oferta_id);
        $this->assertSame(2, $kit->fase);
        $this->assertTrue($kit->estoque_calculado);
    }

    /** T-175-18: o estoque é recalculado no servidor; o corpo não tem como mexer nele. */
    public function test_estoque_do_corpo_e_ignorado_e_recalculado_no_servidor(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);

        $this->actingAs($this->admin())->postJson($this->urlCriar($e, $base), $this->corpo([
            'estoque_por_variante' => [ChaveCanonica::UNICA => ['estoque' => 9999, 'depositos' => ['X' => 9999]]],
            'variantes' => [ChaveCanonica::UNICA => ['estoque' => 9999]],
            'estoque' => 9999,
        ]))->assertStatus(201);

        $v = PubProduto::where('produto_base_id', $base->id)->firstOrFail()->rascunho->variantes->first();
        $this->assertSame(3, (int) $v->estoque, 'floor(7 ÷ 2) do base, não o 9999 do corpo');
        $this->assertNull($v->estoque_depositos);
    }

    /** O título e a descrição do corpo mandam (são editáveis no painel); vazio cai na sugestão do servidor. */
    public function test_titulo_e_descricao_do_corpo_valem_e_vazio_cai_na_sugestao(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $this->actingAs($this->admin());

        $this->postJson($this->urlCriar($e, $base), $this->corpo([
            'titulo' => 'Kit 2 Cadeira Mandada à Mão',
            'descricao' => 'Descrição escrita à mão.',
        ]))->assertStatus(201);

        $rk = PubProduto::where('produto_base_id', $base->id)->firstOrFail()->rascunho;
        $this->assertSame('Kit 2 Cadeira Mandada à Mão', $rk->alvos->firstWhere('listing_type_id', 'gold_special')->titulo);
        $this->assertSame('Kit 2 Cadeira Mandada à Mão', $rk->alvos->firstWhere('listing_type_id', 'gold_pro')->titulo, 'um campo de título no painel vale para todos os tipos');
        $this->assertSame('Descrição escrita à mão.', $rk->descricao);

        $corpo = $this->corpo(['quantidade' => 3, 'sku' => 'CAD-KIT3']);
        unset($corpo['titulo'], $corpo['descricao']);
        $this->postJson($this->urlCriar($e, $base), $corpo)->assertStatus(201);

        $rk3 = PubProduto::where('quantidade_kit', 3)->firstOrFail()->rascunho;
        $this->assertSame('Kit 3 Cadeira Escritório Executiva', $rk3->alvos->firstWhere('listing_type_id', 'gold_special')->titulo);
        $this->assertSame('Kit 3 Cadeira Escritório Premium', $rk3->alvos->firstWhere('listing_type_id', 'gold_pro')->titulo, 'sem título no corpo cada tipo fica com a sugestão dele');
        $this->assertStringStartsWith('Este kit contém 3 unidades de', $rk3->descricao);
    }

    public function test_seller_skus_do_corpo_valem_por_variante(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);

        $this->actingAs($this->admin())->postJson($this->urlCriar($e, $base), $this->corpo([
            'seller_skus' => [ChaveCanonica::UNICA => 'MEU-CODIGO-KIT2'],
        ]))->assertStatus(201);

        $v = PubProduto::where('produto_base_id', $base->id)->firstOrFail()->rascunho->variantes->first();
        $this->assertSame('MEU-CODIGO-KIT2', $v->atributos->firstWhere('attribute_id', 'SELLER_SKU')->value_name);
    }

    /**
     * A capa é RECEBIDA como flag e devolvida na resposta; quem dispara a geração
     * é o 175-07. `Queue::fake()` prova que nada foi enfileirado aqui.
     */
    public function test_capa_pedida_volta_na_resposta_e_nenhum_job_e_disparado(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);

        $json = $this->actingAs($this->admin())
            ->postJson($this->urlCriar($e, $base), $this->corpo(['capa' => true]))
            ->assertStatus(201)
            ->json();

        $this->assertTrue($json['capa_pedida']);
        Queue::assertNothingPushed();
        $this->semChamadaAoMl();
    }

    public function test_sku_e_obrigatorio_na_criacao(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $corpo = $this->corpo();
        unset($corpo['sku']);

        $this->actingAs($this->admin())->postJson($this->urlCriar($e, $base), $corpo)
            ->assertStatus(422)->assertJsonValidationErrors('sku');
        $this->assertSame(1, PubProduto::count());
    }

    public function test_criar_pedido_pelo_id_de_um_kit_nasce_do_base_sem_cadeia(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        // Kit com o base APAGADO (SET NULL) não é mais kit; este aponta para o base.
        $kit = PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => 'CAD-KIT2', 'nome' => 'Kit 2 Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2,
        ]);

        // Pedir pelo id do kit resolve no BASE (regra do `mostrar()`), então cria o Kit 3.
        $this->actingAs($this->admin())
            ->postJson($this->urlCriar($e, $kit), $this->corpo(['quantidade' => 3, 'sku' => 'CAD-KIT3']))
            ->assertStatus(201);

        $novo = PubProduto::where('quantidade_kit', 3)->firstOrFail();
        $this->assertSame($base->id, $novo->produto_base_id, 'nunca há cadeia de kits: o novo aponta para o BASE');
        $this->assertSame(3, $novo->fase);
    }

    // ═══ "Sugerir com IA" — plano 175-06 ═════════════════════════════════════

    private function urlIa(MlbEmpresa $e, PubProduto $p): string
    {
        return self::BASE."/empresas/empresa-{$e->id}/produtos/{$p->id}/fases/ia";
    }

    public function test_as_rotas_de_ia_vivem_no_grupo_admin_com_throttle_nomeado(): void
    {
        $pedir = Route::getRoutes()->getByName('mlb.anuncios.publicador.fases.ia');
        $status = Route::getRoutes()->getByName('mlb.anuncios.publicador.fases.ia.status');

        $this->assertNotNull($pedir);
        $this->assertNotNull($status);
        $this->assertContains('role:admin', $pedir->gatherMiddleware());
        $this->assertContains('role:admin', $status->gatherMiddleware());
        // Mesmos tetos do `publicador.palavras-ia`: gastar IA é caro, perguntar não é.
        $this->assertContains('throttle:20,1,publicador.fases.ia', $pedir->gatherMiddleware());
        $this->assertContains('throttle:240,1,publicador.fases.ia.status', $status->gatherMiddleware());

        foreach ([$pedir, $status] as $rota) {
            $this->assertSame('(empresa|company)-[0-9]+', $rota->wheres['conta'] ?? null);
            $this->assertSame('[0-9]+', $rota->wheres['produto'] ?? null);
        }
        $this->assertSame('titulo|descricao', $status->wheres['alvo'] ?? null, 'o alvo morre na própria rota');
        $this->assertSame(['POST'], $pedir->methods());
        $this->assertSame(['GET', 'HEAD'], $status->methods());
    }

    public function test_pedir_ia_responde_202_e_enfileira_o_job_sem_gravar_nada(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $r = $this->rascunhoPublicado($base);

        $resposta = $this->actingAs($this->admin())
            ->postJson($this->urlIa($e, $base), ['alvo' => 'titulo', 'quantidade' => 2])
            ->assertStatus(202)
            ->assertJsonPath('status', 'rodando');

        $pedido = $resposta->json('pedido');
        $this->assertNotEmpty($pedido);
        Queue::assertPushed(GerarSugestaoKitIaJob::class, fn ($job) => $job->rascunhoBaseId === $r->id && $job->quantidade === 2);
        // O pedido é escopado ao rascunho do BASE: nenhum produto novo nasce aqui.
        $this->assertSame(1, PubProduto::count());
        $this->assertSame('Cadeira Escritório Executiva', (string) $r->alvos()->orderBy('posicao')->first()->titulo);
        $this->semChamadaAoMl();
    }

    public function test_ia_recusa_alvo_fora_da_lista_e_quantidade_invalida(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->urlIa($e, $base), ['alvo' => 'modelo', 'quantidade' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('alvo');
        $this->actingAs($admin)->postJson($this->urlIa($e, $base), ['alvo' => 'titulo', 'quantidade' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('quantidade');
        Queue::assertNotPushed(GerarSugestaoKitIaJob::class);
    }

    public function test_status_de_alvo_nunca_pedido_responde_nenhum_nunca_404(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);

        $this->actingAs($this->admin())
            ->getJson($this->urlIa($e, $base).'/descricao?quantidade=2')
            ->assertOk()->assertJsonPath('status', 'nenhum');
    }

    public function test_status_devolve_o_pedido_da_quantidade_certa(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $r = $this->rascunhoPublicado($base);
        Cache::put(SugestaoKitIaService::chave($r->id, 'titulo', 4), ['pedido' => 'p4', 'status' => 'pronto', 'valor' => 'Kit 4 Cadeira', 'erro' => null], 60);

        $this->actingAs($this->admin())->getJson($this->urlIa($e, $base).'/titulo?quantidade=4')
            ->assertOk()->assertJsonPath('valor', 'Kit 4 Cadeira');
        // O painel de outra quantidade não enxerga este resultado.
        $this->actingAs($this->admin())->getJson($this->urlIa($e, $base).'/titulo?quantidade=2')
            ->assertOk()->assertJsonPath('status', 'nenhum');
    }

    public function test_ia_de_produto_de_outra_conta_e_404_nunca_403(): void
    {
        [$e] = $this->conta();
        [$outra] = $this->conta();
        $deOutra = $this->base($outra, null, 'ALHEIO');
        $this->rascunhoPublicado($deOutra);

        $this->actingAs($this->admin())
            ->postJson($this->urlIa($e, $deOutra), ['alvo' => 'titulo', 'quantidade' => 2])
            ->assertNotFound();
        $this->actingAs($this->admin())
            ->getJson($this->urlIa($e, $deOutra).'/titulo?quantidade=2')
            ->assertNotFound();
        Queue::assertNotPushed(GerarSugestaoKitIaJob::class);
    }

    public function test_ia_sem_rascunho_no_base_recusa_com_o_conselho_do_kit_01(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);

        $this->actingAs($this->admin())
            ->postJson($this->urlIa($e, $base), ['alvo' => 'titulo', 'quantidade' => 2])
            ->assertStatus(422)->assertJsonPath('regra', 'KIT-01');
        Queue::assertNotPushed(GerarSugestaoKitIaJob::class);
    }

    public function test_ia_exige_admin(): void
    {
        [$e] = $this->conta();
        $base = $this->base($e);
        $this->rascunhoPublicado($base);
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->postJson($this->urlIa($e, $base), ['alvo' => 'titulo', 'quantidade' => 2])->assertForbidden();
        $this->actingAs($consultor)->getJson($this->urlIa($e, $base).'/titulo?quantidade=2')->assertForbidden();
    }
}
