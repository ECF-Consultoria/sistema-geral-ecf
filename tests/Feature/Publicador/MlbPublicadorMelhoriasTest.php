<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarPalavrasChaveIaJob;
use App\Models\Company;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PalavrasChaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Melhoria do Publicador de 03/10/2026 (`melhoria_publicador.docx`) pelo HTTP:
 * termos mais buscados da categoria, a IA do Modelo e do título (fila + pedido
 * no cache), o frete grátis obrigatório lido do ML pela faixa de preço e o
 * aviso 4053 (`lost_me1_by_user`) fora da tela. O ML e a IA são simulados.
 */
class MlbPublicadorMelhoriasTest extends TestCase
{
    use CarregaSchemas;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    private array $ofertas;

    /** O que a IA simulada responde (conteúdo da mensagem). */
    private string $respostaIa = '{"modelo":"x"}';

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
        config(['services.llm.base_url' => 'https://ia.teste/v1', 'services.llm.key' => 'k', 'services.llm.model' => 'modelo-a', 'services.llm.fallbacks' => '']);

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
            '*/attributes/conditional*' => Http::response(['required_attributes' => [], 'callbacks' => [], 'status' => 200]),
            '*/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/trends/MLB/*' => Http::response([
                ['keyword' => 'cadeira gamer', 'url' => 'https://lista.mercadolivre.com.br/cadeira-gamer'],
                ['keyword' => 'cadeira escritorio giratoria', 'url' => null],
                ['keyword' => 'poltrona husky', 'url' => null],
            ]),
            // As respostas reais da sondagem: abaixo da faixa o ML banca (`free_shipping_by_meli`); a partir dela é do vendedor.
            '*/shipping_options/free*' => function (Request $q) use ($fixture) {
                parse_str((string) parse_url($q->url(), PHP_URL_QUERY), $query);

                return Http::response($fixture((float) $query['item_price'] >= 79 ? 'conta/shipping_options_free_79' : 'conta/shipping_options_free_78_99'));
            },
            'https://ia.teste/*' => fn () => Http::response(['model' => 'modelo-a', 'choices' => [['message' => ['content' => $this->respostaIa]]]]),
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

    private function comCategoria(): PubRascunho
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->putJson($this->rota('categoria'), ['categoria_id' => self::CADEIRA])->assertOk();

        return PubRascunho::firstOrFail();
    }

    // ═══ Termos mais buscados (docx §2/§3) ═══════════════════════════════════

    public function test_termos_pedem_categoria_e_marcam_os_relacionados_ao_produto(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->getJson($this->rota('termos'))->assertUnprocessable()->assertJson(['regra' => 'V-CAT-01']);

        $this->comCategoria();
        $r = $this->mesa()->getJson($this->rota('termos'))->assertOk()->json();

        $this->assertSame(self::CADEIRA, $r['categoria']['id']);
        $this->assertSame(['cadeira gamer', 'cadeira escritorio giratoria', 'poltrona husky'], array_column($r['termos'], 'termo'));
        $this->assertSame(3, $r['total']);
        $this->assertFalse($r['com_grupos'], 'lista curta: a doc do ML não diz como ela se divide');
    }

    public function test_termos_com_o_ml_fora_dao_502_com_mensagem(): void
    {
        $this->comCategoria();
        Http::fake(['*/trends/MLB/*' => Http::response(['message' => 'fora'], 500)]);
        // O 1º stub vence (Http::fake acumula): troca o cliente por um que só conhece a falha.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/trends/MLB/*' => Http::response(['message' => 'fora'], 500),
            '*' => Http::response([], 200),
        ]);

        $this->mesa()->getJson($this->rota('termos'))->assertStatus(502)->assertJsonStructure(['message']);
    }

    // ═══ IA do Modelo e do título ════════════════════════════════════════════

    public function test_pedido_da_ia_do_modelo_vai_para_a_fila_e_o_job_grava_o_resultado_cortado_em_120(): void
    {
        $r = $this->comCategoria();

        $pedido = $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo'])->assertStatus(202)->json('pedido');
        Queue::assertPushedOn('high', GerarPalavrasChaveIaJob::class);
        $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->assertOk()->assertJson(['status' => 'rodando', 'pedido' => $pedido]);

        // A IA erra a contagem e repete termo: o servidor normaliza e corta no último termo inteiro.
        $this->respostaIa = json_encode(['modelo' => 'Cadeira Escritório, cadeira escritorio, cadeira giratória, cadeira home office, cadeira presidente, cadeira ergonomica, cadeira para trabalho, cadeira com rodinhas, cadeira executiva preta']);
        (new GerarPalavrasChaveIaJob($r->id, 'modelo', $pedido))->handle(app(PalavrasChaveService::class));

        $estado = $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->assertOk()->json();
        $this->assertSame('pronto', $estado['status']);
        $this->assertSame($pedido, $estado['pedido']);
        $this->assertLessThanOrEqual(120, strlen($estado['valor']));
        $this->assertStringStartsWith('cadeira escritorio, cadeira giratoria, cadeira home office', $estado['valor']);
        $this->assertStringNotContainsString('cadeira escritorio, cadeira escritorio', $estado['valor']);

        // O prompt levou os termos do ML, os relacionados ao produto primeiro.
        $prompt = Http::recorded(fn (Request $q) => str_contains($q->url(), 'ia.teste'))->first()[0]->data()['messages'][0]['content'];
        $this->assertStringContainsString('cadeira escritorio giratoria', $prompt);
        $this->assertStringContainsString('120', $prompt);
        // Sem título ainda: o Modelo sai sem a regra (e sem o filtro) do título.
        $this->assertStringNotContainsString('Não repita termo cujas palavras', $prompt);
        // Nada foi gravado no rascunho por trás da pessoa: quem aplica é a tela.
        $this->assertNull(PubRascunho::firstOrFail()->atributos()->where('attribute_id', 'MODEL')->first());
    }

    public function test_modelo_nao_repete_palavras_dos_titulos_ativos_salvos_nem_do_titulo_da_tela(): void
    {
        $r = $this->comCategoria();
        // O Premium está desligado: o título dele não conta.
        $this->mesa()->putJson($this->rota('salvar'), ['alvos' => [
            ['listing_type_id' => 'gold_special', 'ativo' => true, 'titulo' => 'Cadeira Gamer Giratória Reclinável'],
            ['listing_type_id' => 'gold_pro', 'ativo' => false, 'titulo' => 'Cadeira Presidente Couro'],
        ]])->assertOk();

        // O título da tela (ainda não salvo) se SOMA aos gravados.
        $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo', 'titulo' => 'Cadeira Ergonômica'])->assertStatus(202);
        $job = Queue::pushed(GerarPalavrasChaveIaJob::class)->last();
        $this->assertSame('Cadeira Ergonômica', $job->titulo);

        $this->respostaIa = json_encode(['modelo' => 'cadeira gamer, cadeira giratoria reclinavel, cadeira ergonomica, cadeira gamer preta, cadeiras gamers, cadeira presidente, cadeira para escritorio']);
        $job->handle(app(PalavrasChaveService::class));

        $estado = $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->json();
        $this->assertSame('pronto', $estado['status']);
        $this->assertSame('cadeira gamer preta, cadeira presidente, cadeira para escritorio', $estado['valor']);

        $prompt = Http::recorded(fn (Request $q) => str_contains($q->url(), 'ia.teste'))->first()[0]->data()['messages'][0]['content'];
        $this->assertStringContainsString('Cadeira Gamer Giratória Reclinável', $prompt);
        $this->assertStringContainsString('Cadeira Ergonômica', $prompt);
        $this->assertStringContainsString('Não repita termo cujas palavras já estão todas no título', $prompt);
        $this->assertStringNotContainsString('Cadeira Presidente Couro', $prompt);
    }

    public function test_modelo_com_tudo_no_titulo_vira_erro_claro_e_titulo_longo_demais_e_recusado(): void
    {
        $this->comCategoria();
        $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo', 'titulo' => str_repeat('a', 256)])->assertUnprocessable();

        $pedido = $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo', 'titulo' => 'Cadeira Gamer Escritório'])->json('pedido');
        $this->respostaIa = json_encode(['modelo' => 'cadeira gamer, cadeira de escritorio, cadeiras gamer']);
        Queue::pushed(GerarPalavrasChaveIaJob::class)->last()->handle(app(PalavrasChaveService::class));

        $e = $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->json();
        $this->assertSame($pedido, $e['pedido']);
        $this->assertSame('erro', $e['status']);
        $this->assertStringContainsString('já estão no título', $e['erro']);
    }

    public function test_titulo_pela_ia_respeita_o_maximo_da_categoria_e_tira_caractere_especial(): void
    {
        $r = $this->comCategoria();
        $pedido = $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'titulo_gold_special', 'escolhidos' => ['cadeira gamer']])->assertStatus(202)->json('pedido');

        $this->respostaIa = json_encode(['titulo' => 'Cadeira Escritório Giratória (Ergonômica) - Home Office Presidente Reclinável Executiva']);
        (new GerarPalavrasChaveIaJob($r->id, 'titulo_gold_special', $pedido, ['cadeira gamer']))->handle(app(PalavrasChaveService::class));

        $valor = $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'titulo_gold_special']))->json('valor');
        $maximo = (int) (self::schema(self::CADEIRA)->settings()['max_title_length'] ?? 60);
        $this->assertLessThanOrEqual($maximo, mb_strlen($valor));
        $this->assertDoesNotMatchRegularExpression('/[()\-]/', $valor);
        $this->assertStringStartsWith('Cadeira Escritório Giratória Ergonômica', $valor);
        $prompt = Http::recorded(fn (Request $q) => str_contains($q->url(), 'ia.teste'))->first()[0]->data()['messages'][0]['content'];
        $this->assertStringContainsString('priorize', $prompt, 'os termos escolhidos na tela vão para a IA');
    }

    public function test_ia_que_falha_deixa_o_pedido_em_erro_com_mensagem_e_pedido_velho_nao_pisa_no_novo(): void
    {
        $r = $this->comCategoria();
        $velho = $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo'])->json('pedido');
        $novo = $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo'])->json('pedido');

        // O velho termina depois que o novo foi pedido: perdeu a vez.
        $this->respostaIa = json_encode(['modelo' => 'cadeira velha']);
        (new GerarPalavrasChaveIaJob($r->id, 'modelo', $velho))->handle(app(PalavrasChaveService::class));
        $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->assertJson(['status' => 'rodando', 'pedido' => $novo]);

        $this->respostaIa = 'isto não é json';
        (new GerarPalavrasChaveIaJob($r->id, 'modelo', $novo))->handle(app(PalavrasChaveService::class));
        $e = $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->json();
        $this->assertSame('erro', $e['status']);
        $this->assertNotEmpty($e['erro']);
    }

    public function test_pedido_da_ia_valida_o_alvo_e_pede_categoria(): void
    {
        $this->mesa()->getJson($this->rota('abrir'))->assertOk();
        $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'modelo'])->assertUnprocessable()->assertJson(['regra' => 'V-CAT-01']);
        $this->mesa()->postJson($this->rota('palavras-ia'), ['alvo' => 'descricao'])->assertUnprocessable();
        $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'descricao']))->assertNotFound();
        $this->mesa()->getJson($this->rota('palavras-ia.status', ['alvo' => 'modelo']))->assertOk()->assertJson(['status' => 'nenhum']);
        Queue::assertNotPushed(GerarPalavrasChaveIaJob::class);
    }

    // ═══ Frete grátis obrigatório (docx §5) ══════════════════════════════════

    private function comPacote(): void
    {
        $this->mesa()->putJson($this->rota('salvar'), ['atributos' => [
            'SELLER_PACKAGE_HEIGHT' => ['value_name' => '15 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '15 cm'],
            'SELLER_PACKAGE_LENGTH' => ['value_name' => '20 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '500 g'],
        ], 'alvos' => [['listing_type_id' => 'gold_special', 'ativo' => true], ['listing_type_id' => 'gold_pro', 'ativo' => false]]])->assertOk();
    }

    public function test_frete_gratis_e_obrigatorio_quando_o_ml_diz_mandatory_sem_bancar(): void
    {
        $this->comCategoria();
        $this->comPacote();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => ['__single__' => ['precos' => ['gold_special' => 150]]]])->assertOk();

        $f = $this->mesa()->getJson($this->rota('frete'))->assertOk()->json('frete_gratis');

        $this->assertTrue($f['conhecido']);
        $this->assertTrue($f['obrigatorio']);
        $this->assertFalse($f['parcial']);
        $q = Http::recorded(fn (Request $q) => str_contains($q->url(), 'shipping_options/free'))->first()[0]->data();
        $this->assertSame('15x15x20,500', $q['dimensions']);
        $this->assertSame('gold_special', $q['listing_type_id']);
    }

    public function test_frete_gratis_e_opcional_abaixo_da_faixa_e_parcial_quando_so_a_variacao_cara_passa(): void
    {
        $this->comCategoria();
        $this->comPacote();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => ['__single__' => ['precos' => ['gold_special' => 50]]]])->assertOk();

        $f = $this->mesa()->getJson($this->rota('frete'))->json('frete_gratis');
        $this->assertTrue($f['conhecido']);
        $this->assertFalse($f['obrigatorio'], 'abaixo da faixa o ML banca o frete (free_shipping_by_meli)');

        $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [
            ['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true, 'valores' => [['id' => '52049', 'nome' => 'Preto'], ['id' => '52028', 'nome' => 'Azul']]],
        ]])->assertOk();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => [
            'COLOR=id:52049' => ['precos' => ['gold_special' => 50]],
            'COLOR=id:52028' => ['precos' => ['gold_special' => 150]],
        ]])->assertOk();

        $f = $this->mesa()->getJson($this->rota('frete'))->json('frete_gratis');
        $this->assertFalse($f['obrigatorio']);
        $this->assertTrue($f['parcial']);
        // O JSON leva 50.0 como 50: compara o valor, não o tipo.
        $this->assertEquals(['menor' => 50, 'maior' => 150], array_intersect_key($f['por_tipo']['gold_special'], ['menor' => 0, 'maior' => 0]));
    }

    public function test_frete_fora_do_mercado_envios_nao_consulta_o_ml(): void
    {
        $this->comCategoria();
        $this->mesa()->putJson($this->rota('salvar'), ['envio' => ['modo' => 'custom', 'frete_gratis' => false]])->assertOk();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => ['__single__' => ['precos' => ['gold_special' => 150]]]])->assertOk();

        $f = $this->mesa()->getJson($this->rota('frete'))->json('frete_gratis');
        $this->assertSame(['conhecido' => true, 'obrigatorio' => false, 'parcial' => false, 'por_tipo' => []], $f);
        $this->assertCount(0, Http::recorded(fn (Request $q) => str_contains($q->url(), 'shipping_options/free')));
    }

    // ═══ Variações e fotos juntas, como no ML (03/10) ═════════════════════════

    public function test_primeira_variacao_herda_os_dados_e_cada_uma_tem_o_proprio_grupo_de_fotos(): void
    {
        $this->comCategoria();
        $this->mesa()->putJson($this->rota('variantes'), ['variantes' => ['__single__' => ['estoque' => 5, 'precos' => ['gold_special' => 150]]]])->assertOk();

        // O caminho da tela: 1º a que já existe ganha o valor (fica com os dados), depois nasce a nova.
        $cor = ['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true];
        $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [$cor + ['valores' => [['id' => '52049', 'nome' => 'Preto']]]]])->assertOk();
        $r = $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [$cor + ['valores' => [['id' => '52049', 'nome' => 'Preto'], ['id' => '52028', 'nome' => 'Azul']]]]])->assertOk()->json();

        [$preto, $azul] = $r['variantes'];
        $this->assertSame(['COLOR' => ['id' => '52049', 'nome' => 'Preto']], $preto['valores'], 'a tela tira e restaura pelo valor');
        $this->assertSame(5, $preto['estoque'], 'a variação que já existia ficou com os dados');
        $this->assertSame('CAD-01-CB3', $preto['atributos']['SELLER_SKU']['value_name']);
        $this->assertNull($azul['estoque'], 'a nova nasce vazia');
        $this->assertSame([], $r['variantes'][1]['atributos']);
        // Cor define a foto: um grupo por cor, cada cartão acha o seu.
        $this->assertSame([[$preto['chave']], [$azul['chave']]], array_column($r['grupos_imagem'], 'variantes'));

        // Eixo que NÃO define foto: com "fotos por variação" (a tela liga sozinha) cada uma tem o próprio grupo.
        $this->mesa()->putJson($this->rota('eixos'), ['eixos' => [
            ['chave' => '~custom', 'nome' => 'Estampa', 'defines_picture' => false, 'valores' => [['nome' => 'Lisa'], ['nome' => 'Listrada']]],
        ]])->assertOk();
        $sem = $this->mesa()->getJson($this->rota('abrir'))->json('grupos_imagem');
        $this->assertSame([], $sem, 'sem fotos por variação, todas usam a galeria geral');
        $r = $this->mesa()->putJson($this->rota('salvar'), ['fotos_por_variante' => true])->assertOk()->json();
        $this->assertTrue($r['rascunho']['fotos_por_variante']);
        $this->assertSame(array_map(fn ($v) => [$v['chave']], array_values(array_filter($r['variantes'], fn ($v) => ! $v['orfa']))), array_column($r['grupos_imagem'], 'variantes'));
    }

    // ═══ Aviso 4053 fora da tela (docx §5) ═══════════════════════════════════

    public function test_conferencia_gravada_com_o_aviso_4053_nao_o_mostra_mais(): void
    {
        $r = $this->comCategoria();
        $aviso = fn (int $id, string $code) => ['regra' => 'V-REM-01', 'severidade' => 'WARNING', 'mensagem' => $code, 'camada' => 'L3', 'alvo' => ['etapa' => 'E10'],
            'ml_causa' => ['cause_id' => $id, 'code' => $code, 'type' => 'warning', 'message' => $code]];
        $r->validacoes()->create(['revisao' => $r->revisao, 'camada' => 'L3', 'resultado' => ConferenciaService::AVISOS,
            'issues' => [$aviso(4053, 'shipping.lost_me1_by_user'), $aviso(350, 'item.shipping.mandatory_free_shipping')]]);

        $issues = $this->mesa()->getJson($this->rota('abrir'))->assertOk()->json('conferencia.issues');

        $this->assertSame(['item.shipping.mandatory_free_shipping'], array_column(array_column($issues, 'ml_causa'), 'code'));
    }
}
