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
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /** A cadeira em Preto e Azul, com foto só no Preto: o Azul fica com "falta imagem". */
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

        $this->assertSame(['COLOR=id:52028' => ['falta imagem']], $resumo['por_cor']);
        $this->assertGreaterThanOrEqual(1, $resumo['bloqueios']);
    }

    public function test_cartao_tem_uma_bolinha_por_cor_com_a_foto_dela_e_o_chip_diz_qual_cor(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();

        $c = $this->cartaoDaCadeira();

        $this->assertSame(['Preto', 'Azul'], array_column($c['cores'], 'rotulo'));
        $this->assertStringStartsWith('https://http2.mlstatic.com/', (string) $c['cores'][0]['foto'], 'o Preto mostra a foto dele');
        $this->assertSame([], $c['cores'][0]['pendencias']);
        $this->assertSame(['falta imagem'], $c['cores'][1]['pendencias']);
        $this->assertSame($c['cores'][0]['foto'], $c['foto'], 'a capa do cartão é a 1ª cor');
        $this->assertSame('pendencia', $c['grupo']);
        $this->assertStringStartsWith('Azul: falta imagem', $c['chip']['texto']);
        $this->assertSame('ambar', $c['chip']['tom']);
        // 2 cores × Clássico e Premium; preços mín–máx entre as cores.
        $this->assertSame(4, $c['anuncios']);
        $this->assertSame(['min' => 199.9, 'max' => 219.9], $c['precos']['gold_special']);
        $this->assertSame(['min' => 214.9, 'max' => 234.9], $c['precos']['gold_pro']);
        $this->assertTrue($c['selecionavel']);
        $this->assertStringEndsWith('?etapa=previa', $c['url_previa']);
        // O produto do teste é o Combo 3 do gabarito à parte: Fase 2 na régua do Portal (as famílias estão no VitrineFasesTest).
        $this->assertSame(['combo', 2, 'Fase 2 · Combo 3 un'], [$c['tipo'], $c['fase_numero'], $c['fase']]);
    }

    /** Tira o resumo do rascunho, como nos que nasceram pela planilha, pelo kit ou pelo Portal sem abrir o editor. */
    private function semResumo(): PubRascunho
    {
        $r = PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail();
        $estado = (array) $r->step_state;
        unset($estado['resumo']);
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);

        return $r->fresh();
    }

    public function test_sem_resumo_e_sem_foto_nao_e_pronto_e_a_visita_refaz_o_resumo(): void
    {
        // 20/10/2026, o usuário: "está dizendo que está pronto, mas nem imagem tem".
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();
        $r = $this->semResumo();

        $c = $this->cartaoDaCadeira();

        $this->assertNull($c['foto']);
        $this->assertSame('pendencia', $c['grupo'], 'resumo que não existe não é "nada falta"');
        $this->assertSame('Falta imagem', $c['chip']['texto']);
        $resumo = (array) $r->fresh()->step_state['resumo'];
        $this->assertSame((int) $r->fresh()->revisao, $resumo['revisao'], 'a visita refez o resumo nesta revisão');
        $this->assertTrue($resumo['validado']);
        $this->assertGreaterThanOrEqual(1, $resumo['bloqueios'], 'sem foto é bloqueio');
    }

    public function test_sem_categoria_nada_foi_validado_e_o_cartao_diz_falta_categoria(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image('geral.jpg', 1200, 1200), 'grupo' => 'GENERAL'], ['Accept' => 'application/json'])->assertOk();
        $this->semResumo();

        $c = $this->cartaoDaCadeira();

        $this->assertNotNull($c['foto']);
        $this->assertSame('pendencia', $c['grupo']);
        $this->assertSame('Falta categoria', $c['chip']['texto']);
        $this->assertFalse(PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail()->step_state['resumo']['validado']);
    }

    /**
     * 22/10/2026, o usuário: "por que não gerou as imagens e ainda deixou como Pronto". O limite diário de imagens por IA
     * barrou o produto: com só as fotos do cliente, o cartão diz "Imagens amanhã" e leva à etapa Imagens.
     */
    public function test_barrado_pelo_limite_de_imagens_da_ia_o_cartao_diz_imagens_amanha(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();
        $this->mesa()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image('geral.jpg', 1200, 1200), 'grupo' => 'GENERAL'], ['Accept' => 'application/json'])->assertOk();
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->assertNotSame(VitrineDeProdutosService::IMAGENS_AMANHA, $this->cartaoDaCadeira()['chip']['texto']);

        $r = PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail();
        $estado = (array) $r->step_state;
        $estado['criativos_auto']['adiado'] = ['dia' => '2026-10-22', 'motivo' => 'limite'];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);

        $c = $this->cartaoDaCadeira();

        $this->assertSame('pendencia', $c['grupo'], 'não é pronto');
        $this->assertSame('Imagens amanhã', $c['chip']['texto']);
        $this->assertCount(1, $c['resolver']);
        $this->assertStringContainsString('etapa=imagens', $c['resolver'][0]['url']);
        $this->assertStringContainsString('amanhã cedo', $c['resolver'][0]['texto']);
        $this->assertSame(['dia' => '2026-10-22', 'motivo' => 'limite'], $r->fresh()->step_state['criativos_auto']['adiado'], 'a visita não apaga a marca');
    }

    /**
     * 23/10/2026 (teste do Publicador, P-10): o cartão do Sofá mostrava SFCHST (o código do produto no Portal) e o anúncio
     * levava SFCHST-2 (o SKU da variação). Com UMA variação, o cartão mostra o SKU que vai ao anúncio.
     */
    public function test_com_uma_variacao_o_cartao_mostra_o_sku_do_anuncio(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => [
            '__single__' => ['estoque' => 2, 'atributos' => ['SELLER_SKU' => ['value_name' => 'CB3-ANUNCIO']]],
        ]])->assertOk();

        $this->assertSame('CB3-ANUNCIO', $this->cartaoDaCadeira()['sku']);
    }

    public function test_resumo_de_outra_revisao_e_refeito_antes_de_dizer_pronto(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        // Um resumo velho dizendo "nada falta" — o rascunho mudou depois (Portal, IA) sem ninguém abrir o editor.
        $r = PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail();
        $estado = (array) $r->step_state;
        $estado['resumo'] = ['bloqueios' => 0, 'revisao' => (int) $r->revisao - 1, 'por_cor' => [], 'validado' => true,
            'versao' => EditorRascunhoService::VERSAO_DO_RESUMO];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);

        $c = $this->cartaoDaCadeira();

        $this->assertSame('pendencia', $c['grupo']);
        $this->assertStringStartsWith('Azul: falta imagem', $c['chip']['texto']);
        $this->assertSame((int) $r->revisao, $r->fresh()->step_state['resumo']['revisao']);
    }

    public function test_produto_que_saiu_do_portal_avisa_e_pode_ser_marcado_para_excluir(): void
    {
        // O cliente excluiu no Portal: a FK `nullOnDelete` solta as duas ligações e o produto fica aqui.
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        // O id ANTES de soltar: `produto()` acha o produto pela oferta, que deixa de existir aqui.
        $id = $this->produto()->id;
        PubProduto::whereKey($id)->update(['oferta_id' => null, 'estrutura_produto_id' => null]);

        $alvo = app(ProgramasPublicadorService::class)->resolver('company-'.$this->empresa->id);
        $c = collect(app(VitrineDeProdutosService::class)->montar($alvo)['cartoes'])->firstWhere('produto_id', $id);

        $this->assertTrue($c['saiu_do_portal']);
        $this->assertFalse($c['do_portal'], 'o selo "Portal" mentiria');
        $this->assertSame('pendencia', $c['grupo']);
        $this->assertSame(['texto' => 'Saiu do Portal', 'tom' => 'vermelho', 'detalhe' => 'Marque e exclua daqui também'], $c['chip']);
        $this->assertTrue($c['selecionavel'], 'marca-se para a exclusão da barra');
    }

    public function test_com_a_ia_atualizando_o_cartao_diz_atualizando_no_lugar_da_pendencia(): void
    {
        // 20/10/2026, o usuário: "se estiver em pendência e a IA estiver trabalhando, sinalizar que está atualizando".
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        $r = PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail();
        $estado = (array) $r->step_state;
        $estado['ia_preparo']['etapas']['titulo'] = ['status' => 'rodando', 'em' => now()->toIso8601String()];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);

        $c = $this->cartaoDaCadeira();

        $this->assertSame('escrevendo', $c['ia']);
        $this->assertSame('pendencia', $c['grupo']);
        $this->assertSame(['texto' => 'Atualizando…', 'tom' => 'azul', 'detalhe' => 'A IA está escrevendo título e descrição'], $c['chip']);

        // O editor recebe o mesmo na carga: abre só leitura e relê quando o carimbo mudar.
        $doEditor = $this->mesa()->getJson($this->rota('abrir'))->assertOk()->json();
        $this->assertSame('escrevendo', $doEditor['ia_por_fora'] ?? null);
        $this->assertIsString($doEditor['carimbo_por_fora'] ?? null);
    }

    public function test_o_chip_do_que_falta_leva_a_etapa_do_editor_onde_se_resolve(): void
    {
        // 21/10/2026, o usuário: "nesses que falam que faltam algum item, tem que ser clicável me induzindo a resolver".
        $this->cadeiraPretaEAzulSemFotoNoAzul();

        $c = $this->cartaoDaCadeira();

        $this->assertSame('pendencia', $c['grupo']);
        $this->assertNotEmpty($c['resolver']);
        $foto = collect($c['resolver'])->first(fn ($i) => str_contains($i['url'], 'etapa=imagens'));
        $this->assertNotNull($foto, 'a falta de foto do Azul leva à etapa de Imagens');
        $this->assertStringEndsWith('&corrigir=1', $foto['url'], 'e abre com os campos marcados');
        $this->assertStringContainsString('Azul', $foto['texto']);
        $detalhes = collect($c['resolver'])->first(fn ($i) => str_contains($i['url'], 'etapa=detalhes'));
        $this->assertMatchesRegularExpression('/^Detalhes: \d+ itens — /u', $detalhes['texto'], 'uma linha por etapa, com quantos faltam');
        $this->assertSame(EditorRascunhoService::VERSAO_DO_RESUMO, PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail()->step_state['resumo']['versao']);
    }

    public function test_imagens_da_ia_reprovadas_aparecem_no_cartao_com_o_motivo(): void
    {
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        $r = PubRascunho::where('produto_id', $this->produto()->id)->firstOrFail();
        $kit = MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => $this->empresa->id, 'pub_rascunho_id' => $r->id,
            'pub_grupo' => 'GENERAL', 'status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        MlAnuncioCriativo::create(['token' => Str::random(32), 'company_id' => $this->empresa->id, 'kit_id' => $kit->id, 'slot' => 'hero',
            'slot_indice' => 0, 'status' => MlAnuncioCriativo::STATUS_PRONTO, 'validacao_status' => MlAnuncioCriativo::VALIDACAO_REPROVADA,
            'validacao' => ['status' => 'reprovada', 'mensagem' => 'Risco: A foto do Portal é de outro produto.. Confira antes de aprovar.']]);
        $estado = (array) $r->step_state;
        $estado['criativos_auto']['grupos']['GENERAL'] = ['kit_id' => $kit->id, 'aplicadas' => 0, 'aplicado_em' => now()->toIso8601String()];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);

        $c = $this->cartaoDaCadeira();

        $this->assertSame(['todas' => true, 'reprovadas' => 1, 'geradas' => 1, 'motivos' => ['A foto do Portal é de outro produto.']], $c['ia_reprovada']);
        $doEditor = $this->mesa()->getJson($this->rota('abrir'))->assertOk()->json();
        $this->assertSame(['A foto do Portal é de outro produto.'], $doEditor['ia_reprovada']['motivos'] ?? null, 'o editor recebe o mesmo aviso');
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

    public function test_a_vitrine_liga_so_nas_contas_da_lista(): void
    {
        // "Só na #459 primeiro": a lista pela chave da URL; as outras contas seguem na lista de hoje.
        $this->cadeiraPretaEAzulSemFotoNoAzul();
        $chave = 'company-'.$this->empresa->id;
        $url = route('mlb.anuncios.publicador.produtos', ['conta' => $chave]);

        config(['publicador.vitrine' => false, 'publicador.vitrine_contas' => ['company-999999']]);
        $this->mesa()->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Mlb/Publicador/Produtos', false));

        config(['publicador.vitrine_contas' => ['company-999999', $chave]]);
        $this->mesa()->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Mlb/Publicador/ProdutosVitrine', false));
        $this->assertTrue(VitrineDeProdutosService::ligadaPara($chave));
        $this->assertFalse(VitrineDeProdutosService::ligadaPara('company-999998'));
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

        $this->assertSame(['COLOR=id:2' => ['falta imagem', 'falta estoque']], $porCor);
    }
}
