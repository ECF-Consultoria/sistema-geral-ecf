<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarExplicacoesDeAtributosJob;
use App\Models\Company;
use App\Models\MlCategoriaSchema;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Trilha\CompletudeDoProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * Categoria e ficha técnica no cadastro da trilha (3ª entrega, fatia F2 — desenho §5.4, §5.5 e §6.2, rotas 5 a 8):
 * buscar pelo nome, navegar na árvore, os campos da categoria e os termos do título, na porta da EQUIPE.
 *
 * O que se prova:
 * - a busca diz `folha` E `anunciavel` (a chave nova do `CategoriaSugestaoService`); o ML fora, ou sem candidata, é
 *   `indisponivel` com 200 — nunca erro HTTP; o pedido malformado é 422;
 * - os campos saem da definição PURA: nenhum `GerarExplicacoesDeAtributosJob` na fila (a do Portal enfileira) e o
 *   filtro de sigilo continua (nenhum rótulo, opção ou unidade com a origem); ler os campos guarda a definição, e a
 *   Lista deixa de dizer "a conferir";
 * - os termos do título gravam `ml_categoria_schemas` e degradam para `indisponivel`;
 * - só o APP token sai daqui;
 * - a guarda: fora das duas chaves 404, quem não escreve no Portal da empresa 403, sem login vai ao login, quem não é
 *   Dev 404 (módulo oculto), categoria fora de `MLB` + 1..17 dígitos 404.
 *
 * Nenhuma chamada real: `Http::fake` por URL, com as respostas REAIS da sondagem de 01/10/2026
 * (`tests/fixtures-ml/sondagem/publico`, MLB193945 Cadeiras para Escritório) e o app token em cache.
 */
class CategoriasNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private const CADEIRA = 'MLB193945';

    /** O pai real da cadeira (Cadeiras, Sofás e Banquetas): não é folha. */
    private const PAI = 'MLB436389';

    /** Uma folha que NÃO aceita anúncio novo (`settings.listing_allowed: false`). */
    private const FECHADA = 'MLB999001';

    private Company $empresa;

    private User $dev;

    /** `ok`, `fora` (500) ou `vazio` (200 com []). */
    private string $preditor = 'ok';

    private bool $trendsFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        // Depois do relógio: o TTL conta a partir do "agora" do teste (antes dele, o token já nasceria vencido).
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();

        Http::fake(fn (Request $r) => $this->responder($r));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Rota 5: buscar pelo nome ───────────────────────────────────────────

    public function test_a_busca_devolve_as_candidatas_com_folha_e_anunciavel_so_com_o_app_token(): void
    {
        $r = $this->actingAs($this->dev)->getJson($this->url('/categorias?q=cadeira de escritório'))->assertOk();

        $this->assertFalse($r->json('indisponivel'));
        $porId = collect($r->json('categorias'))->keyBy('id');
        $this->assertSame([self::CADEIRA, self::PAI, self::FECHADA], $porId->keys()->all(), 'a ordem do preditor');

        // A folha anunciável: é a que a tela sugere.
        $this->assertSame(['id', 'nome', 'caminho', 'caminho_texto', 'folha', 'anunciavel'], array_keys($porId[self::CADEIRA]));
        $this->assertTrue($porId[self::CADEIRA]['folha']);
        $this->assertTrue($porId[self::CADEIRA]['anunciavel']);
        $this->assertSame('Casa, Móveis e Decoração > Móveis para Casa > Cadeiras, Sofás e Banquetas > Cadeiras para Escritório', $porId[self::CADEIRA]['caminho_texto']);
        $this->assertSame(self::CADEIRA, last($porId[self::CADEIRA]['caminho'])['id'], 'cada nível leva o id (a navegação abre nele)');
        // O nível de cima não é folha, e não é anunciável.
        $this->assertFalse($porId[self::PAI]['folha']);
        $this->assertFalse($porId[self::PAI]['anunciavel']);
        // A folha fechada para anúncio novo: folha, mas NÃO anunciável.
        $this->assertTrue($porId[self::FECHADA]['folha']);
        $this->assertFalse($porId[self::FECHADA]['anunciavel']);

        // Dado público: só o app token da ECF sai daqui.
        $this->assertNotEmpty(Http::recorded());
        Http::assertNotSent(fn (Request $req) => ! $req->hasHeader('Authorization', 'Bearer app-token-teste'));
        $this->assertStringNotContainsString('/portal/', $r->getContent());
    }

    public function test_a_chave_anunciavel_vale_igual_no_detalhe_e_no_lote(): void
    {
        $servico = app(CategoriaSugestaoService::class);

        $lote = $servico->detalhes([self::CADEIRA, self::PAI, self::FECHADA, 'MLB555']);
        foreach ([self::CADEIRA, self::PAI, self::FECHADA] as $id) {
            $this->assertSame($lote[$id], $servico->detalhe($id), "{$id}: detalhe e detalhes são a mesma régua");
        }
        $this->assertSame([true, false, false], [$lote[self::CADEIRA]['anunciavel'], $lote[self::PAI]['anunciavel'], $lote[self::FECHADA]['anunciavel']]);
        $this->assertSame([true, false, true], [$lote[self::CADEIRA]['folha'], $lote[self::PAI]['folha'], $lote[self::FECHADA]['folha']], '`folha` não muda');
        $this->assertNull($lote['MLB555'], 'a que o ML não devolveu continua nula');
        $this->assertNull($servico->detalhe('MLB555'));
    }

    public function test_com_o_ml_fora_ou_sem_candidata_a_busca_responde_200_indisponivel(): void
    {
        $this->preditor = 'fora';
        $this->actingAs($this->dev)->getJson($this->url('/categorias?q=cadeira fora'))
            ->assertOk()->assertExactJson(['categorias' => [], 'indisponivel' => true]);

        $this->preditor = 'vazio';
        $this->actingAs($this->dev)->getJson($this->url('/categorias?q=cadeira vazia'))
            ->assertOk()->assertExactJson(['categorias' => [], 'indisponivel' => true]);
    }

    public function test_a_busca_malformada_e_422_sem_chamar_o_ml(): void
    {
        $this->actingAs($this->dev)->getJson($this->url('/categorias?q=a'))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->actingAs($this->dev)->getJson($this->url('/categorias'))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->actingAs($this->dev)->getJson($this->url('/categorias?q='.str_repeat('a', 121)))->assertStatus(422)->assertJsonValidationErrors('q');

        Http::assertNothingSent();
    }

    // ─── Rota 6: a árvore ───────────────────────────────────────────────────

    public function test_a_arvore_navega_nivel_a_nivel_e_diz_quem_e_folha_anunciavel(): void
    {
        // O 1º nível: as raízes, nenhuma recebe anúncio.
        $raiz = $this->actingAs($this->dev)->getJson($this->url('/categorias/arvore'))->assertOk();
        $this->assertFalse($raiz->json('indisponivel'));
        $this->assertNull($raiz->json('atual'));
        $this->assertSame(['MLB1574', 'MLB1430'], array_column($raiz->json('filhas'), 'id'));
        $this->assertSame([false], array_values(array_unique(array_column($raiz->json('filhas'), 'anunciavel'))));

        // Um nível do meio: a cadeira é folha anunciável; o próprio nível, não.
        $meio = $this->actingAs($this->dev)->getJson($this->url('/categorias/arvore?pai='.self::PAI))->assertOk();
        $this->assertSame(self::PAI, $meio->json('atual.id'));
        $this->assertFalse($meio->json('atual.folha'));
        $this->assertFalse($meio->json('atual.anunciavel'));
        $cadeira = collect($meio->json('filhas'))->firstWhere('id', self::CADEIRA);
        $this->assertTrue($cadeira['folha']);
        $this->assertTrue($cadeira['anunciavel']);

        // A folha: anunciável, sem filhas.
        $folha = $this->actingAs($this->dev)->getJson($this->url('/categorias/arvore?pai='.self::CADEIRA))->assertOk();
        $this->assertTrue($folha->json('atual.anunciavel'));
        $this->assertSame([], $folha->json('filhas'));
        $this->assertSame('Cadeiras para Escritório', last($folha->json('caminho'))['nome']);
    }

    public function test_a_arvore_com_o_ml_fora_e_indisponivel_e_pai_malformado_e_422(): void
    {
        $this->actingAs($this->dev)->getJson($this->url('/categorias/arvore?pai=MLB555'))
            ->assertOk()->assertExactJson(['atual' => null, 'caminho' => [], 'filhas' => [], 'indisponivel' => true]);

        $chamadas = count(Http::recorded());
        foreach (['abc', 'MLB', 'MLB12/filhas', 'MLB'.str_repeat('1', 18)] as $pai) {
            $this->actingAs($this->dev)->getJson($this->url('/categorias/arvore?pai='.urlencode($pai)))
                ->assertStatus(422)->assertJsonValidationErrors('pai');
        }
        $this->assertCount($chamadas, Http::recorded(), '`pai` malformado não vira chamada ao ML');
    }

    // ─── Rota 7: os campos da categoria ─────────────────────────────────────

    public function test_os_campos_saem_sem_explicacao_e_nenhuma_ia_vai_para_a_fila(): void
    {
        $r = $this->actingAs($this->dev)->getJson($this->url('/categorias/'.self::CADEIRA.'/campos'))->assertOk();

        $this->assertFalse($r->json('indisponivel'));
        $campos = collect($r->json('grupos'))->flatMap(fn ($g) => $g['campos'])->keyBy('id');
        $this->assertNotEmpty($campos);
        $this->assertTrue($campos->contains(fn ($c) => $c['obrigatorio']), 'os obrigatórios vêm marcados (o * da tela)');
        $this->assertArrayNotHasKey(FichaTecnicaDaCategoria::ID_MODELO, $campos->all(), 'o Modelo é da IA, fora da ficha');
        foreach ($campos as $id => $campo) {
            $this->assertArrayNotHasKey('explicacao', $campo, "{$id}: a definição pura não traz explicação");
        }
        Queue::assertNotPushed(GerarExplicacoesDeAtributosJob::class);
        Queue::assertNothingPushed();

        // Contraprova: a rota do Portal (com explicações) enfileiraria a IA para esta mesma categoria.
        app(FichaTecnicaDaCategoria::class)->definicaoComExplicacoes(self::CADEIRA);
        Queue::assertPushed(GerarExplicacoesDeAtributosJob::class);
    }

    public function test_ler_os_campos_guarda_a_definicao_e_a_lista_deixa_de_dizer_a_conferir(): void
    {
        $this->assertSame([], CompletudeDoProduto::definicoesGuardadas([self::CADEIRA]), 'antes: nada guardado (a Lista diria "a conferir")');

        $this->actingAs($this->dev)->getJson($this->url('/categorias/'.self::CADEIRA.'/campos'))->assertOk();

        $this->assertTrue(Cache::has('ml_meta_atributos_'.self::CADEIRA), 'o cache de 7 dias do catálogo');
        $this->assertArrayHasKey(self::CADEIRA, CompletudeDoProduto::definicoesGuardadas([self::CADEIRA]));
    }

    public function test_nenhum_rotulo_dos_campos_revela_a_origem(): void
    {
        $r = $this->actingAs($this->dev)->getJson($this->url('/categorias/'.self::CADEIRA.'/campos'))->assertOk();
        $grupos = $r->json('grupos');
        $campos = collect($grupos)->flatMap(fn ($g) => $g['campos'])->keyBy('id');

        // As iscas que a resposta do catálogo ganhou (ver `atributosDaCadeira`): o rótulo que cita a plataforma sai
        // inteiro; a opção e a unidade que citam saem, e o campo fica com o resto.
        $this->assertArrayNotHasKey('DESTAQUE_PLATAFORMA', $campos->all());
        $this->assertSame(['L'], array_column($campos['VOLUME_DO_COPO']['unidades'], 'id'), 'a unidade `ml` sai');
        $this->assertSame('L', $campos['VOLUME_DO_COPO']['unidade_padrao']);
        $this->assertSame(['Fixo', 'Regulável'], array_column($campos['APOIO_DE_BRACO']['valores'], 'nome'), 'a opção que cita a plataforma sai');

        $rotulos = [];
        foreach ($grupos as $g) {
            $rotulos[] = $g['grupo'];
            foreach ($g['campos'] as $c) {
                $rotulos[] = $c['nome'];
                array_push($rotulos, ...array_column($c['valores'], 'nome'), ...array_column($c['unidades'], 'id'),
                    ...array_column($c['unidades'], 'nome'), ...$c['sugestoes']);
            }
        }
        $this->assertGreaterThan(50, count($rotulos), 'a varredura cobre a definição real inteira');
        foreach ($rotulos as $rotulo) {
            $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|publicar|\bmlb|\bml\b/iu', (string) $rotulo);
        }
    }

    public function test_os_campos_com_o_catalogo_fora_sao_indisponivel(): void
    {
        $this->actingAs($this->dev)->getJson($this->url('/categorias/MLB555/campos'))
            ->assertOk()->assertExactJson(['grupos' => [], 'indisponivel' => true]);
        Queue::assertNothingPushed();
    }

    // ─── Rota 8: os termos do título ────────────────────────────────────────

    public function test_os_termos_gravam_o_schema_da_categoria_e_nao_citam_a_plataforma(): void
    {
        $this->assertNull(MlCategoriaSchema::find(self::CADEIRA));

        $r = $this->actingAs($this->dev)->getJson($this->url('/categorias/'.self::CADEIRA.'/titulo?nome=Cadeira de escritório giratória'))->assertOk();

        $this->assertFalse($r->json('indisponivel'));
        $this->assertSame(60, $r->json('limite'));
        $this->assertCount(12, $r->json('termos'));
        $this->assertSame(['posicao', 'termo', 'grupo', 'relacionado', 'origem'], array_keys($r->json('termos.0')));
        $this->assertSame('Cadeiras para Escritório', last($r->json('categoria.caminho')));

        // O schema ficou guardado: é ele que o Publicador e a régua da Lista leem depois.
        $schema = MlCategoriaSchema::find(self::CADEIRA);
        $this->assertNotNull($schema);
        $this->assertNotEmpty($schema->atributos);
        $this->assertArrayHasKey(self::CADEIRA, CompletudeDoProduto::definicoesGuardadas([self::CADEIRA]));

        $this->assertDoesNotMatchRegularExpression('/mercado|MLB|an[uú]ncio|Publicador|"url"/iu', $r->getContent());
    }

    public function test_os_termos_fora_do_ar_degradam_para_indisponivel_com_o_limite(): void
    {
        // Os termos fora, o schema no ar: o limite vem da categoria.
        $this->trendsFora = true;
        $r = $this->actingAs($this->dev)->getJson($this->url('/categorias/'.self::CADEIRA.'/titulo'))->assertOk();
        $this->assertTrue($r->json('indisponivel'));
        $this->assertSame(60, $r->json('limite'));
        $this->assertNull($r->json('termos'));
        $this->assertDoesNotMatchRegularExpression('/mercado|MLB|trends|HTTP/iu', $r->getContent());

        // A categoria nem se lê: o limite padrão, e nada guardado.
        $this->actingAs($this->dev)->getJson($this->url('/categorias/MLB555/titulo?nome=x'))
            ->assertOk()->assertExactJson(['limite' => 60, 'indisponivel' => true]);
        $this->assertNull(MlCategoriaSchema::find('MLB555'));
    }

    public function test_o_nome_do_titulo_acima_de_255_e_422(): void
    {
        $this->actingAs($this->dev)->getJson($this->url('/categorias/'.self::CADEIRA.'/titulo?nome='.str_repeat('a', 256)))
            ->assertStatus(422)->assertJsonValidationErrors('nome');
        Http::assertNothingSent();
    }

    // ─── A guarda (desenho §6.1), nas quatro rotas ──────────────────────────

    /** @return array<string, array{0: string}> o caminho depois de `/trilha/{empresa}` */
    public static function rotas(): array
    {
        return [
            'buscar (rota 5)' => ['/categorias?q=cadeira'],
            'árvore (rota 6)' => ['/categorias/arvore?pai='.self::PAI],
            'campos (rota 7)' => ['/categorias/'.self::CADEIRA.'/campos'],
            'título (rota 8)' => ['/categorias/'.self::CADEIRA.'/titulo?nome=Cadeira'],
        ];
    }

    #[DataProvider('rotas')]
    public function test_a_equipe_que_escreve_no_portal_da_empresa_entra(string $caminho): void
    {
        $this->actingAs($this->dev)->getJson($this->url($caminho))->assertOk()->assertJsonPath('indisponivel', false);
    }

    #[DataProvider('rotas')]
    public function test_fora_das_duas_chaves_a_rota_nao_existe_e_o_ml_nao_e_chamado(string $caminho): void
    {
        // Na trilha de leitura (1ª entrega), sem a chave de trabalho.
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $this->actingAs($this->dev)->getJson($this->url($caminho, $soLeitura))->assertNotFound();

        // Fora de `trilha.empresas`, mesmo que estivesse na de trabalho: 404 antes de ler a empresa.
        $fora = $this->empresaDaTrilha();
        config(['trilha.empresas' => array_values(array_diff((array) config('trilha.empresas'), [$fora->id]))]);
        $this->actingAs($this->dev)->getJson($this->url($caminho, $fora))->assertNotFound();

        // Empresa que não existe, dentro das chaves.
        config(['trilha.empresas' => [...(array) config('trilha.empresas'), 999999], 'trilha.trabalho.empresas' => [...(array) config('trilha.trabalho.empresas'), 999999]]);
        $this->actingAs($this->dev)->getJson('/trilha/999999'.$caminho)->assertNotFound();

        Http::assertNothingSent();
    }

    #[DataProvider('rotas')]
    public function test_quem_nao_escreve_no_portal_da_empresa_recebe_403_e_o_ml_nao_e_chamado(string $caminho): void
    {
        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();

        $this->actingAs($semCarteira->refresh())->getJson($this->url($caminho))
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso ao portal desta empresa.');

        Http::assertNothingSent();
    }

    #[DataProvider('rotas')]
    public function test_sem_login_vai_para_o_login_e_quem_nao_e_dev_recebe_404(string $caminho): void
    {
        // Módulo oculto: o admin que não é Dev recebe 404, como se a rota não existisse.
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->getJson($this->url($caminho))->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->get($this->url($caminho))->assertRedirect(route('login'));
        $this->getJson($this->url($caminho))->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_categoria_fora_do_formato_no_endereco_e_404_sem_chamar_o_ml(): void
    {
        foreach (['MLB', 'abc', 'mlb193945', 'MLB'.str_repeat('1', 18), 'MLB12x'] as $categoria) {
            $this->actingAs($this->dev)->getJson($this->url("/categorias/{$categoria}/campos"))->assertNotFound();
            $this->actingAs($this->dev)->getJson($this->url("/categorias/{$categoria}/titulo"))->assertNotFound();
        }
        // 17 dígitos ainda é categoria (o formato do `where`).
        $this->actingAs($this->dev)->getJson($this->url('/categorias/MLB'.str_repeat('1', 17).'/campos'))
            ->assertOk()->assertJsonPath('indisponivel', true);
        // Empresa com 19 dígitos: 404, nunca 500.
        $this->actingAs($this->dev)->getJson('/trilha/1234567890123456789/categorias?q=cadeira')->assertNotFound();
    }

    // ─── apoio ───

    private function url(string $caminho, ?Company $empresa = null): string
    {
        return '/trilha/'.($empresa ?? $this->empresa)->id.$caminho;
    }

    /** O ML de mentira, por URL. O que não está aqui é 404 (a categoria que "não existe"). */
    private function responder(Request $r)
    {
        $url = $r->url();

        if (str_contains($url, '/domain_discovery/search')) {
            return match ($this->preditor) {
                'fora'  => Http::response(['message' => 'erro'], 500),
                'vazio' => Http::response([], 200),
                default => Http::response([
                    ['category_id' => self::CADEIRA, 'category_name' => 'Cadeiras para Escritório', 'domain_name' => 'Cadeiras de escritório'],
                    ['category_id' => self::PAI, 'category_name' => 'Cadeiras, Sofás e Banquetas', 'domain_name' => 'Cadeiras'],
                    ['category_id' => self::FECHADA, 'category_name' => 'Cadeiras antigas', 'domain_name' => 'Cadeiras'],
                ]),
            };
        }
        if (str_ends_with(parse_url($url, PHP_URL_PATH), '/sites/MLB/categories')) {
            return Http::response([['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'], ['id' => 'MLB1430', 'name' => 'Calçados, Roupas e Bolsas']]);
        }
        if (str_contains($url, '/trends/MLB/')) {
            if ($this->trendsFora) {
                return Http::response(['message' => 'erro'], 500);
            }

            return Http::response(array_map(fn (string $t) => ['keyword' => $t, 'url' => 'https://lista.mercadolivre.com.br/'.str_replace(' ', '-', $t)], [
                'cadeira escritorio', 'cadeira de escritorio giratoria', 'cadeira presidente', 'cadeira ergonomica', 'cadeira gamer',
                'cadeira de escritorio barata', 'cadeira diretor', 'cadeira secretaria', 'cadeira giratoria', 'cadeira com rodinha',
                'cadeira mesh', 'cadeira home office',
            ]));
        }
        if (preg_match('#/categories/(MLB\d+)(/[a-z_/]+)?$#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            return $this->categoria($m[1], $m[2] ?? '');
        }

        return Http::response([], 404);
    }

    private function categoria(string $id, string $sub)
    {
        if ($id === self::FECHADA && $sub === '') {
            return Http::response(['id' => self::FECHADA, 'name' => 'Cadeiras antigas', 'children_categories' => [],
                'settings' => ['listing_allowed' => false, 'max_title_length' => 60],
                'path_from_root' => [['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'], ['id' => self::FECHADA, 'name' => 'Cadeiras antigas']]]);
        }
        if ($id === self::PAI && $sub === '') {
            return Http::response($this->fixture(self::CADEIRA, 'pai_'.self::PAI.'.json'));
        }
        if ($id !== self::CADEIRA) {
            return Http::response(['message' => 'not_found'], 404);
        }

        return Http::response(match ($sub) {
            ''                        => $this->fixture(self::CADEIRA, 'categoria.json'),
            '/attributes'             => $this->atributosDaCadeira(),
            '/technical_specs/input'  => $this->fixture(self::CADEIRA, 'technical_specs_input.json'),
            '/sale_terms'             => $this->fixture(self::CADEIRA, 'sale_terms.json'),
            default                   => ['message' => 'not_found'],
        });
    }

    /**
     * Os atributos REAIS da cadeira, mais três iscas do sigilo: um rótulo que cita a plataforma, uma unidade `ml` e uma
     * opção que cita o anúncio.
     */
    private function atributosDaCadeira(): array
    {
        return [...$this->fixture(self::CADEIRA, 'atributos.json'),
            ['id' => 'DESTAQUE_PLATAFORMA', 'name' => 'Destaque no Mercado Livre', 'value_type' => 'string', 'tags' => []],
            ['id' => 'VOLUME_DO_COPO', 'name' => 'Volume do porta-copos', 'value_type' => 'number_unit', 'tags' => [],
                'allowed_units' => [['id' => 'ml', 'name' => 'ml'], ['id' => 'L', 'name' => 'L']], 'default_unit' => 'ml'],
            ['id' => 'APOIO_DE_BRACO', 'name' => 'Apoio de braço', 'value_type' => 'list', 'tags' => [],
                'values' => [['id' => '31', 'name' => 'Fixo'], ['id' => '32', 'name' => 'Regulável'], ['id' => '33', 'name' => 'Igual ao anúncio']]],
        ];
    }

    private function fixture(string $categoria, string $arquivo): array
    {
        return json_decode((string) file_get_contents(base_path("tests/fixtures-ml/sondagem/publico/categorias/{$categoria}/{$arquivo}")), true)['resposta'];
    }
}
