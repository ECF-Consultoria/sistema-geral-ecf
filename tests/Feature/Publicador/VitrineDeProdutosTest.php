<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\VitrineDeProdutosService;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * A vitrine de produtos (20/10/2026, protótipo atrás de `publicador.vitrine`): um cartão por produto, com uma
 * bolinha por cor (a 1ª foto de cada uma), os preços mín–máx, os anúncios previstos e o chip que diz QUAL cor tem
 * pendência — pelo `por_cor` que o editor grava no resumo do rascunho.
 */
class VitrineDeProdutosTest extends TestCase
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

    private function mesa(): static
    {
        return $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function produto(): PubProduto
    {
        return PubProduto::daOferta($this->ofertas['CAD-01-CB3']);
    }

    private function rota(string $nome, array $extra = []): string
    {
        return route("mlb.anuncios.publicador.{$nome}", ['produto' => $this->produto()->id, ...$extra]);
    }

    /** A cadeira em Preto e Azul, com foto só no Preto: o Azul fica com "falta foto". */
    private function cadeiraPretaEAzulSemFotoNoAzul(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();
        $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [
            ['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true, 'valores' => [['id' => '52049', 'nome' => 'Preto'], ['id' => '52028', 'nome' => 'Azul']]],
        ]])->assertOk();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => [
            'COLOR=id:52049' => ['estoque' => 4, 'precos' => ['gold_special' => 199.9, 'gold_pro' => 214.9], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CB3-PRETO']]],
            'COLOR=id:52028' => ['estoque' => 2, 'precos' => ['gold_special' => 219.9, 'gold_pro' => 234.9], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CB3-AZUL']]],
        ]])->assertOk();
        $this->mesa()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image('preto.jpg', 1200, 1200), 'grupo' => 'COLOR=id:52049'], ['Accept' => 'application/json'])->assertOk();
        // O resumo (com o `por_cor`) sai do estado — a equipe abrindo o editor.
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
    }

    private function cartaoDaCadeira(): array
    {
        $alvo = app(ProgramasPublicadorService::class)->resolver('company-'.$this->empresa->id);
        $vitrine = app(VitrineDeProdutosService::class)->montar($alvo);

        return collect($vitrine['cartoes'])->firstWhere('produto_id', $this->produto()->id);
    }

    public function test_o_resumo_do_rascunho_guarda_o_que_falta_em_cada_cor(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();

        $resumo = (array) PubRascunho::where('produto_id', $this->produto()->id)->value('step_state')['resumo'];

        $this->assertSame(['COLOR=id:52028' => ['falta foto']], $resumo['por_cor']);
        $this->assertGreaterThanOrEqual(1, $resumo['bloqueios']);
    }

    public function test_cartao_tem_uma_bolinha_por_cor_com_a_foto_dela_e_o_chip_diz_qual_cor(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();

        $c = $this->cartaoDaCadeira();

        $this->assertSame(['Preto', 'Azul'], array_column($c['cores'], 'rotulo'));
        $this->assertStringStartsWith('https://http2.mlstatic.com/', (string) $c['cores'][0]['foto'], 'o Preto mostra a foto dele');
        $this->assertSame([], $c['cores'][0]['pendencias']);
        $this->assertSame(['falta foto'], $c['cores'][1]['pendencias']);
        $this->assertSame($c['cores'][0]['foto'], $c['foto'], 'a capa do cartão é a 1ª cor');
        $this->assertSame('pendencia', $c['grupo']);
        $this->assertStringStartsWith('Azul: falta foto', $c['chip']['texto']);
        $this->assertSame('ambar', $c['chip']['tom']);
        // 2 cores × Clássico e Premium; preços mín–máx entre as cores.
        $this->assertSame(4, $c['anuncios']);
        $this->assertSame(['min' => 199.9, 'max' => 219.9], $c['precos']['gold_special']);
        $this->assertSame(['min' => 214.9, 'max' => 234.9], $c['precos']['gold_pro']);
        $this->assertTrue($c['selecionavel']);
        $this->assertStringEndsWith('?etapa=previa', $c['url_previa']);
        $this->assertSame('Fase 1', $c['fase']);
    }

    public function test_publicado_vai_para_o_grupo_dele_e_nao_se_marca(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        PubRascunho::where('produto_id', $this->produto()->id)->update(['status' => PubRascunho::PUBLISHED]);

        $c = $this->cartaoDaCadeira();

        $this->assertSame('publicados', $c['grupo']);
        $this->assertSame(['texto' => 'Publicado', 'tom' => 'verde', 'detalhe' => null], $c['chip']);
        $this->assertFalse($c['selecionavel']);
    }

    public function test_a_conta_abre_na_vitrine_so_com_a_chave_ligada(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        $url = route('mlb.anuncios.publicador.produtos', ['conta' => 'company-'.$this->empresa->id]);

        $this->mesa()->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Mlb/Publicador/Produtos', false));

        config(['publicador.vitrine' => true]);
        $this->mesa()->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Mlb/Publicador/ProdutosVitrine', false)
            ->where('cartoes', fn ($cartoes) => collect($cartoes)->contains('produto_id', $this->produto()->id))
            ->where('contagens.todos', fn ($n) => $n >= 1)
            ->has('cabecalho.abas_ocultas'));
    }

    public function test_pendencias_por_cor_pela_variante_ou_pelo_grupo_de_fotos_e_o_que_e_do_produto_nao_entra(): void
    {
        $eixos = [new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('1', 'Preto'), new ValorEixo('2', 'Rosa')])];
        $preto = new Variante('COLOR=id:1', ['COLOR' => new ValorEixo('1', 'Preto')]);
        $rosa = new Variante('COLOR=id:2', ['COLOR' => new ValorEixo('2', 'Rosa')]);
        $s = new RascunhoSnapshot('MLB1', eixos: $eixos, variantes: [$preto, $rosa]);

        $porCor = EditorRascunhoService::pendenciasPorCor([
            Problema::bloqueio('V-IMG-05', 'Adicione ao menos 1 foto para Rosa.', ['etapa' => 'E6', 'grupo' => 'COLOR=id:2']),
            Problema::bloqueio('V-VAR-01', 'Informe o estoque.', ['etapa' => 'E5', 'variante' => 'COLOR=id:2', 'campo' => 'estoque']),
            Problema::bloqueio('V-ATT-01', 'Preencha «Marca».', ['etapa' => 'E3', 'atributo' => 'BRAND']),
            Problema::bloqueio('V-IMG-04', 'Galeria geral vazia.', ['etapa' => 'E6', 'grupo' => 'GENERAL']),
        ], $s);

        $this->assertSame(['COLOR=id:2' => ['falta foto', 'falta estoque']], $porCor);
    }
}
