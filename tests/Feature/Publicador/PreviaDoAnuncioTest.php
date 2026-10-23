<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\PreviaDoAnuncioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * A etapa 5 do editor, "Prévia" (20/10/2026): o anúncio como o comprador vai ver no Mercado Livre,
 * tirado do MESMO plano da publicação (`PayloadBuilderUserProducts`). O que importa provar: a ordem
 * das fotos é a do plano (capa trocada no Premium, D6), o preço com a promoção da Central é a conta do
 * `PrecoDaPromocao`, as parcelas do Premium seguem a faixa da config, a ficha mostra só o que o ML
 * mostra, a cor vai ao fim do título, e foto que não subiu aparece pela cópia guardada.
 */
class PreviaDoAnuncioTest extends TestCase
{
    use CarregaSchemas;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    private array $ofertas;

    private int $fotosEnviadas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($this->empresa);
        $this->ofertas = $this->listaDoGabarito($this->empresa, $ator);
        $this->anunciosDoGabarito($this->ofertas, $ator);
        config(['publicador.contas_liberadas.companies' => [$this->empresa->id]]);

        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn () => null));

        $s = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $s->dominio(), 'categoria' => $s->categoria, 'atributos' => $s->atributos,
            'technical_specs' => $s->technicalSpecs, 'sale_terms' => $s->saleTerms, 'schema_hash' => $s->hash(), 'fetched_at' => now()]);

        $fixture = fn (string $r) => json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$r}.json")), true)['resposta'];
        Http::fake([
            '*/users/me' => Http::response($fixture('conta/usuario')),
            '*/shipping_preferences*' => Http::response($fixture('conta/shipping_preferences')),
            '*/pictures/items/upload' => function () {
                $this->fotosEnviadas++;

                return Http::response(['id' => "PIC-{$this->fotosEnviadas}", 'variations' => [['secure_url' => "https://http2.mlstatic.com/D_{$this->fotosEnviadas}-O.jpg"]]]);
            },
            '*/attributes/conditional*' => Http::response(['required_attributes' => [], 'callbacks' => [], 'status' => 200]),
            '*/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/shipping_options/free*' => Http::response(['coverage' => ['all_country' => ['list_cost' => 62.35]]]),
        ]);
    }

    /** A mesa: um admin logado, sem Vite. */
    private function mesa(): static
    {
        return $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function rota(string $nome, array $extra = []): string
    {
        return route("mlb.anuncios.publicador.{$nome}", ['produto' => PubProduto::daOferta($this->ofertas['CAD-01-CB3'])->id, ...$extra]);
    }

    /**
     * Os efetivos do Portal fixos (título planejado, preço anunciado, mínimo da Central e a marca de
     * preço sem frete) — a Precificação de verdade tem a suíte dela. Os outros métodos seguem reais.
     */
    private function efetivosDoPortal(array $efetivos): void
    {
        $this->app->instance(DadosEfetivosService::class, new class(app(EstruturaPrecificacaoService::class), $efetivos) extends DadosEfetivosService
        {
            public function __construct(EstruturaPrecificacaoService $p, private array $fixos)
            {
                parent::__construct($p);
            }

            public function daProduto(PubProduto $produto): array
            {
                return $this->fixos;
            }
        });
    }

    private function portalPadrao(): void
    {
        $this->efetivosDoPortal([
            'titulos' => ['gold_special' => 'Cadeira Executiva Ecf Giratória', 'gold_pro' => 'Cadeira Escritório Executiva Ecf'],
            'precos' => ['gold_special' => 207.19, 'gold_pro' => 230.5],
            'promocoes' => ['gold_special' => 172.66, 'gold_pro' => 199.0],
            // O Premium foi calculado sem frete: sem promoção para ele (o mínimo estaria subestimado).
            'sem_frete' => ['gold_special' => false, 'gold_pro' => true],
            'mlbs' => [],
        ]);
    }

    /** Categoria, ficha, garantia, envio, descrição e estoque — o rascunho pronto para a prévia. */
    private function rascunhoPreenchido(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();
        $this->mesa()->putJson($this->rota('salvar'), [
            'atributos' => ['BRAND' => ['value_name' => 'ECF'], 'MODEL' => ['value_name' => 'Executiva']],
            'condicao' => 'new',
            'garantia' => ['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'],
            'envio' => ['modo' => 'me2', 'frete_gratis' => true],
            'descricao' => "Cadeira <b>confortável</b>.\r\nMedidas: 45 cm.",
        ])->assertOk();
    }

    /** `lado` muda o tamanho: a mesa reconhece foto repetida (mesmos bytes) e não cria a segunda. */
    private function subirFoto(string $nome, int $lado = 1200): string
    {
        return (string) $this->mesa()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image($nome, $lado, $lado)], ['Accept' => 'application/json'])
            ->assertOk()->json('foto.id');
    }

    public function test_previa_sai_do_plano_com_promocao_parcelas_ficha_e_fotos_na_ordem(): void
    {
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => ['__single__' => ['estoque' => 4]]])->assertOk();
        $capa = $this->subirFoto('capa.jpg');
        $lado = $this->subirFoto('lado.jpg', 1100);

        $p = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json();

        $this->assertTrue($p['pronta']);
        $this->assertNull($p['motivo']);
        $this->assertSame(self::CADEIRA, $p['categoria']['id']);
        $this->assertNotEmpty($p['categoria']['caminho']);
        $this->assertSame("Cadeira confortável.\nMedidas: 45 cm.", $p['descricao'], 'o texto que vai ao ML: sem HTML, quebras normalizadas');
        $this->assertSame([['listing_type_id' => 'gold_special', 'nome' => 'Clássico'], ['listing_type_id' => 'gold_pro', 'nome' => 'Premium']], $p['tipos']);
        $this->assertCount(2, $p['anuncios'], 'um anúncio por tipo (sem variação)');
        [$classico, $premium] = $p['anuncios'];

        // Título: o planejado do Portal; sem variação, o ML não acrescenta nada.
        $this->assertSame('Cadeira Executiva Ecf Giratória', $classico['titulo']);
        $this->assertSame('Cadeira Executiva Ecf Giratória', $classico['titulo_no_ml']);
        $this->assertSame('Cadeira Escritório Executiva Ecf', $premium['titulo']);

        // Preço e promoção da Central (a conta do PrecoDaPromocao): 207,19 → 172,66 (−16,67%).
        $this->assertSame(207.19, $classico['preco']);
        $this->assertSame(['preco' => 172.66, 'percentual' => 16.67, 'ajustada_ao_minimo' => false], $classico['promocao']);
        $this->assertNull($classico['sem_promocao']);
        $this->assertNull($classico['parcelas'], 'Clássico não parcela sem juros');
        $this->assertSame(230.5, $premium['preco']);
        $this->assertNull($premium['promocao']);
        $this->assertSame('o preço do Portal foi calculado sem frete', $premium['sem_promocao']);
        $this->assertSame(['sem_promocao' => ['vezes' => 9, 'valor' => 25.61], 'com_promocao' => null], $premium['parcelas']);

        // Fotos na ordem do plano. 21/10/2026: o produto é o Combo 3 do Portal (um composto, CAD-01-CB3) — é kit, e a capa
        // de kit é a MESMA no Clássico e no Premium (sem a rotação D6, que vale para o produto de 1 unidade).
        $this->assertSame([$capa, $lado], array_column($classico['fotos'], 'id'));
        $this->assertSame([$capa, $lado], array_column($premium['fotos'], 'id'));
        $this->assertStringStartsWith('https://http2.mlstatic.com/', $classico['fotos'][0]['url']);
        $this->assertTrue($classico['fotos'][0]['no_ml']);

        // Ficha: só o que o ML mostra (Marca e Modelo sim; SKU e embalagem nunca).
        $itens = collect($classico['caracteristicas'])->flatMap(fn ($g) => $g['itens']);
        $this->assertContains(['nome' => 'Marca', 'valor' => 'ECF'], $itens->all());
        $this->assertContains(['nome' => 'Modelo', 'valor' => 'Executiva'], $itens->all());
        $this->assertFalse($itens->contains(fn ($i) => preg_match('/sku|embalagem|pacote/i', $i['nome']) === 1), json_encode($itens));

        $this->assertStringEndsWith(': 90 dias', (string) $classico['garantia']);
        $this->assertSame(['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false, 'aviso' => null], $classico['envio']);
        $this->assertSame(4, $classico['estoque']);
        $this->assertSame('Novo', $classico['condicao']);
    }

    public function test_a_cor_vai_ao_fim_do_titulo_e_cada_cor_e_um_anuncio_por_tipo(): void
    {
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [
            ['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true, 'valores' => [['id' => '52049', 'nome' => 'Preto'], ['id' => '52028', 'nome' => 'Azul']]],
        ]])->assertOk();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => [
            'COLOR=id:52049' => ['estoque' => 2, 'precos' => ['gold_special' => 199.9]],
            'COLOR=id:52028' => ['estoque' => 3],
        ]])->assertOk();

        $p = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json();

        $this->assertTrue($p['pronta']);
        $this->assertSame([['chave' => 'COLOR', 'nome' => 'Cor']], $p['eixos']);
        $this->assertSame(['Preto', 'Azul'], array_column($p['variantes'], 'rotulo'));
        $this->assertCount(4, $p['anuncios'], '2 cores × Clássico/Premium');
        $preto = collect($p['anuncios'])->first(fn ($a) => $a['listing_type_id'] === 'gold_special' && $a['variante']['chave'] === 'COLOR=id:52049');
        $azul = collect($p['anuncios'])->first(fn ($a) => $a['listing_type_id'] === 'gold_special' && $a['variante']['chave'] === 'COLOR=id:52028');

        $this->assertSame('Cadeira Executiva Ecf Giratória Preto', $preto['titulo_no_ml']);
        $this->assertSame('Cadeira Executiva Ecf Giratória Azul', $azul['titulo_no_ml']);
        $this->assertSame([['eixo' => 'Cor', 'valor' => 'Preto']], $preto['variante']['valores']);
        // O digitado vence o Portal: a promoção segue o mesmo percentual sobre ele, nunca abaixo do mínimo.
        $this->assertSame(199.9, $preto['preco']);
        $this->assertSame(172.66, $preto['promocao']['preco']);
        $this->assertTrue($preto['promocao']['ajustada_ao_minimo']);
        $this->assertSame(207.19, $azul['preco']);
        $this->assertSame(2, $preto['estoque']);
        // A cor aparece na ficha, como no ML.
        $itens = collect($preto['caracteristicas'])->flatMap(fn ($g) => $g['itens']);
        $this->assertContains(['nome' => 'Cor', 'valor' => 'Preto'], $itens->all());
    }

    public function test_foto_que_nao_subiu_aparece_pela_copia_guardada(): void
    {
        // D26: conta não liberada guarda a foto aqui e não envia ao ML.
        config(['publicador.contas_liberadas.companies' => []]);
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        $id = $this->subirFoto('capa.jpg');

        $foto = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json('anuncios.0.fotos.0');

        $this->assertSame($id, $foto['id']);
        $this->assertFalse($foto['no_ml']);
        $this->assertSame($this->rota('fotos.arquivo', ['imagem' => $id]), $foto['url']);
        $this->assertSame(0, $this->fotosEnviadas, 'a prévia nunca envia foto ao ML');
    }

    /** A última conferência do rascunho, gravada como a `ConferenciaService` grava. */
    private function conferenciaGravada(array $issues, array $respostas): void
    {
        $r = PubProduto::daOferta($this->ofertas['CAD-01-CB3'])->rascunho()->firstOrFail();
        PubValidacao::create(['rascunho_id' => $r->id, 'revisao' => $r->revisao, 'camada' => 'L3', 'resultado' => 'AVISOS', 'plano_hash' => str_repeat('a', 64),
            'issues' => $issues, 'respostas_ml' => $respostas]);
    }

    /** N1 (demo de 23/10): o aviso 4057 tira o Mercado Envios; a prévia não pode prometer frete grátis (§25). */
    public function test_conferencia_que_tira_o_mercado_envios_vira_entrega_a_combinar(): void
    {
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        $this->conferenciaGravada([[
            'regra' => 'ML', 'severidade' => 'aviso', 'mensagem' => 'User/Catalog has not intersected me2 logistics', 'camada' => 'L3', 'alvo' => [],
            'ml_causa' => ['code' => 'shipping.lost_me2_by_intersected_logistics', 'cause_id' => 4057],
        ]], ['frete' => ['aplicavel' => false, 'motivo' => 'sem_mercado_envios']]);

        $envio = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json('anuncios.0.envio');

        $this->assertSame('not_specified', $envio['modo']);
        $this->assertFalse($envio['frete_gratis']);
        // 22/10/2026: sem aviso — "frete grátis é para o cliente; ainda assim a gente paga o frete".
        $this->assertNull($envio['aviso']);
    }

    public function test_pacote_fora_do_me2_com_transportadora_vira_envio_pelo_vendedor_e_conferencia_limpa_nao_muda_nada(): void
    {
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        $this->conferenciaGravada([], ['frete' => ['aplicavel' => false, 'motivo' => 'fora_do_me2', 'destino' => 'me1']]);

        $envio = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json('anuncios.0.envio');
        $this->assertSame('me1', $envio['modo']);
        $this->assertFalse($envio['frete_gratis']);

        $this->conferenciaGravada([], ['frete' => ['aplicavel' => true, 'linhas' => []]]);
        $envio = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json('anuncios.0.envio');
        $this->assertSame(['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false, 'aviso' => null], $envio, 'vale a ÚLTIMA conferência');
    }

    /**
     * 23/10/2026 (teste do Publicador, P-08): o combit da Opala (pacote 360 cm, conta sem ME1) dizia "Combine a entrega"
     * em Condições e a Prévia, ainda sem conferência, mostrava "O comprador paga o frete, calculado pelo CEP". Sem
     * conferência, valem as medidas do pacote; com conferência, vale ela.
     */
    public function test_sem_conferencia_o_pacote_fora_do_me2_ja_aparece_a_combinar(): void
    {
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        // O pacote do combit da Opala: 4 caixas empilhadas.
        $this->mesa()->putJson($this->rota('salvar'), ['atributos' => [
            'SELLER_PACKAGE_HEIGHT' => ['value_name' => '360 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '85 cm'],
            'SELLER_PACKAGE_LENGTH' => ['value_name' => '70 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '48000 g'],
        ]])->assertOk();
        \App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio::guardar((int) $this->empresa->id, 'drop_off', me1: false);

        $envio = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json('anuncios.0.envio');
        $this->assertSame('not_specified', $envio['modo'], 'a combinar, sem precisar conferir');
        $this->assertFalse($envio['frete_gratis']);

        // A conferência diz que o Mercado Envios vale: vale ela.
        $this->conferenciaGravada([], ['frete' => ['aplicavel' => true, 'linhas' => []]]);
        $this->assertSame('me2', $this->mesa()->getJson($this->rota('previa'))->assertOk()->json('anuncios.0.envio.modo'));
    }

    public function test_sem_categoria_a_previa_diz_o_que_falta(): void
    {
        $this->portalPadrao();
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();

        $p = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json();

        $this->assertFalse($p['pronta']);
        $this->assertSame('Escolha a categoria na etapa Produto para ver a prévia.', $p['motivo']);
        $this->assertSame([], $p['anuncios']);
    }

    public function test_sem_tipo_ligado_a_previa_diz_o_que_falta(): void
    {
        $this->portalPadrao();
        $this->rascunhoPreenchido();
        $this->mesa()->putJson($this->rota('salvar'), ['alvos' => [
            ['listing_type_id' => 'gold_special', 'ativo' => false], ['listing_type_id' => 'gold_pro', 'ativo' => false],
        ]])->assertOk();

        $p = $this->mesa()->getJson($this->rota('previa'))->assertOk()->json();

        $this->assertFalse($p['pronta']);
        $this->assertStringStartsWith('Nenhum tipo de anúncio ligado', $p['motivo']);
    }

    public function test_so_admin_e_produto_que_nao_existe_da_404(): void
    {
        $this->portalPadrao();
        $this->withoutVite()->actingAs(User::factory()->create(['role' => 'consultor']))->getJson($this->rota('previa'))->assertForbidden();
        $this->mesa()->getJson(route('mlb.anuncios.publicador.previa', ['produto' => 999999]))->assertNotFound();
    }

    public function test_parcelas_do_premium_pela_faixa_de_preco_da_config(): void
    {
        $this->assertSame(['vezes' => 9, 'valor' => 33.33], PreviaDoAnuncioService::parcelasPremium(299.99));
        $this->assertSame(['vezes' => 10, 'valor' => 30.0], PreviaDoAnuncioService::parcelasPremium(300.0));
        $this->assertSame(['vezes' => 10, 'valor' => 150.0], PreviaDoAnuncioService::parcelasPremium(1499.99));
        $this->assertSame(['vezes' => 12, 'valor' => 125.0], PreviaDoAnuncioService::parcelasPremium(1500.0));
        $this->assertNull(PreviaDoAnuncioService::parcelasPremium(null));
        $this->assertNull(PreviaDoAnuncioService::parcelasPremium(0.0));

        // A faixa é config: se o ML mudar, muda-se o valor, não o código.
        config(['publicador.previa.parcelas_premium' => [['ate' => null, 'vezes' => 6]]]);
        $this->assertSame(['vezes' => 6, 'valor' => 50.0], PreviaDoAnuncioService::parcelasPremium(300.0));
    }
}
