<?php

namespace Tests\Feature;

use App\Jobs\GerarAnaliseAnuncioIaJob;
use App\Models\Company;
use App\Models\MlAnuncioIaAnalise;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * "Anunciar por IA" cadastrando o anúncio INTEIRO (30/09/2026): depois da
 * análise, títulos e descrição, a IA escolhe a categoria, preenche a ficha
 * técnica, as variações, o pacote e a garantia — e grava tudo como RASCUNHO.
 *
 * A regra que estes testes guardam: a IA nunca publica. Nenhum POST ao
 * Mercado Livre sai daqui; o anúncio fica em `rascunho` para o publicador
 * conferir, subir as fotos e publicar.
 *
 * `Http::fake()` ACUMULA e o PRIMEIRO stub que casa vence — por isso cada
 * teste monta o seu conjunto inteiro em `fakes()`, sem stub genérico no setUp.
 */
class AnuncioIaRascunhoTest extends TestCase
{
    use RefreshDatabase;

    /** 58 caracteres, sem preposição, sem loja: o único dentro da régua ECF. */
    private const TITULO_NA_REGRA = 'Cadeira Gamer Ergonomica Reclinavel 180 Graus Encosto Alto';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Http::preventStrayRequests();

        // App token do ML já em cache: os testes não passam pelo OAuth.
        Cache::put('ml_app_token_coleta', 'APP_TOKEN_TESTE', 3600);

        config([
            'services.llm.base_url'   => 'http://llm.teste/v1',
            'services.llm.key'        => 'chave-de-teste',
            'services.llm.model'      => 'modelo-de-teste',
            'services.llm.fallbacks'  => '',
            'services.llm.timeout'    => 30,
            'services.llm.max_tokens' => 16000,
        ]);
    }

    // ─── Montagem ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function companyConectada(string $nome = 'Casa Conforto'): Company
    {
        $company = Company::factory()->create(['name' => $nome]);

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'USER_' . uniqid(),
            'access_token'  => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at'    => now()->addHours(5),
            'status'        => 'active',
        ]);

        return $company;
    }

    private function analise(Company $company, User $user, array $resultado = []): MlAnuncioIaAnalise
    {
        return MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'produto'    => 'Cadeira Gamer Reclinável',
            'loja'       => 'Casa Conforto',
            'specs'      => "Estrutura em aço\nLargura 45 cm\nPeso 16 kg\nGarantia de 12 meses\nCores: preto e branco",
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
            'resultado'  => $resultado ?: null,
        ]);
    }

    private function rodar(MlAnuncioIaAnalise $analise, ?GerarAnaliseAnuncioIaJob $job = null): void
    {
        ($job ?? new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(AnaliseAnuncioService::class));
    }

    private function resposta(array $payload): array
    {
        return [
            'model'   => 'modelo-que-respondeu',
            'usage'   => ['prompt_tokens' => 900, 'completion_tokens' => 1200],
            'choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
        ];
    }

    /** Catálogo de atributos da categoria MLB1234, no formato de /categories/{id}/attributes. */
    private function atributosCategoria(): array
    {
        return [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true],
                'values' => [['id' => '9344', 'name' => 'Unity']]],
            ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => [],
                'values' => [['id' => '2748', 'name' => 'Aço'], ['id' => '2749', 'name' => 'Madeira']]],
            ['id' => 'WIDTH', 'name' => 'Largura', 'value_type' => 'number_unit', 'tags' => [],
                'default_unit' => 'cm', 'allowed_units' => [['id' => 'cm', 'name' => 'cm'], ['id' => 'mm', 'name' => 'mm']]],
            ['id' => 'IS_RECLINABLE', 'name' => 'É reclinável', 'value_type' => 'boolean', 'tags' => [],
                'values' => [['id' => '242085', 'name' => 'Sim'], ['id' => '242084', 'name' => 'Não']]],
            ['id' => 'PIECES_NUMBER', 'name' => 'Quantidade de peças', 'value_type' => 'number', 'tags' => []],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'list', 'tags' => ['allow_variations' => true],
                'values' => [['id' => '52049', 'name' => 'Preto'], ['id' => '52055', 'name' => 'Branco']]],
            // Fora da ficha da IA: código de barras é do produto físico…
            ['id' => 'GTIN', 'name' => 'Código universal de produto', 'value_type' => 'string', 'tags' => ['required' => true]],
            // …e secundário oculto o wizard nem mostra.
            ['id' => 'ITEM_HIDDEN', 'name' => 'Oculto', 'value_type' => 'string', 'tags' => ['hidden' => true]],
        ];
    }

    /**
     * Encena tudo: ML (preditor, categoria, atributos) e as QUATRO chamadas à
     * IA na ordem do job — análise, títulos, descrição, ficha.
     */
    private function fakes(array $ficha, ?array $candidatos = null): void
    {
        $candidatos ??= [['category_id' => 'MLB1234', 'category_name' => 'Cadeiras Gamer', 'domain_id' => 'MLB-GAMER_CHAIRS']];

        Http::fake([
            'api.mercadolibre.com/sites/MLB/domain_discovery/search*' => Http::response($candidatos),
            'api.mercadolibre.com/categories/MLB1234/attributes'      => Http::response($this->atributosCategoria()),
            'api.mercadolibre.com/categories/MLB1234'                 => Http::response([
                'id'             => 'MLB1234',
                'name'           => 'Cadeiras Gamer',
                'path_from_root' => [['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'], ['id' => 'MLB1234', 'name' => 'Cadeiras Gamer']],
                'settings'       => ['max_title_length' => 60],
            ]),
            'llm.teste/*' => Http::sequence()
                ->push($this->resposta(['analise' => ['puv' => 'Conforto que dura', 'jtbd' => 'Jogar sem dor']]))
                ->push($this->resposta(['titulos' => [
                    // O curto vem primeiro de propósito: o rascunho tem que
                    // escolher o que está DENTRO da régua, não o primeiro.
                    ['texto' => 'Cadeira Gamer Reclinavel'],
                    ['texto' => self::TITULO_NA_REGRA],
                ]]))
                ->push($this->resposta(['descricao' => "Olá! Bem-vindo à Casa Conforto.\nCadeira para longas partidas."]))
                ->push($this->resposta($ficha)),
        ]);
    }

    private function fichaCompleta(array $extra = []): array
    {
        return array_replace([
            'atributos' => [
                'BRAND'         => 'unity',       // grafia oficial vem do catálogo
                'MODEL'         => 'Pro X',
                'MATERIAL'      => 'aco',         // sem acento casa com "Aço"
                'WIDTH'         => '45 cm',
                'IS_RECLINABLE' => 'sim',
                'PIECES_NUMBER' => '1',
            ],
            'variacoes' => [],
            'pacote'    => ['peso_g' => 18000, 'comprimento_cm' => 70, 'largura_cm' => 65, 'altura_cm' => 40],
            'garantia'  => '12 meses',
        ], $extra);
    }

    private function attr(array $payload, string $id): ?array
    {
        return collect($payload['attributes'] ?? [])->firstWhere('id', $id);
    }

    // ─── Testes ──────────────────────────────────────────────────────────────

    public function test_ia_cadastra_o_anuncio_inteiro_e_deixa_em_rascunho(): void
    {
        $this->fakes($this->fichaCompleta());
        $company = $this->companyConectada();
        $analise = $this->analise($company, $this->admin());

        $this->rodar($analise);

        $analise->refresh();
        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $analise->status);
        $this->assertNotNull($analise->rascunhoId());

        $rascunho = MlAnuncioRascunho::findOrFail($analise->rascunhoId());
        $p        = $rascunho->payload;

        // Rascunho, e só rascunho.
        $this->assertSame(MlAnuncioRascunho::STATUS_RASCUNHO, $rascunho->status);
        $this->assertNull($rascunho->ml_item_id);
        $this->assertSame($company->id, $rascunho->company_id);
        $this->assertSame('MLB1234', $rascunho->category_id);

        $this->assertSame(self::TITULO_NA_REGRA, $p['title']);
        $this->assertSame('MLB1234', $p['category_id']);
        $this->assertStringContainsString('Casa Conforto', $p['description']);
        $this->assertSame('new', $p['condition']);
        $this->assertSame('gold_special', $p['listing_type_id']);
        $this->assertSame([], $p['pictures'], 'Foto é do publicador — a IA não inventa imagem.');

        // Ficha conferida contra o catálogo: lista vira value_id, texto vira value_name.
        $this->assertSame(['id' => 'BRAND', 'value_name' => 'Unity'], $this->attr($p, 'BRAND'));
        $this->assertSame(['id' => 'MODEL', 'value_name' => 'Pro X'], $this->attr($p, 'MODEL'));
        $this->assertSame(['id' => 'MATERIAL', 'value_id' => '2748'], $this->attr($p, 'MATERIAL'));
        $this->assertSame(['id' => 'WIDTH', 'value_name' => '45 cm'], $this->attr($p, 'WIDTH'));
        $this->assertSame(['id' => 'IS_RECLINABLE', 'value_id' => '242085'], $this->attr($p, 'IS_RECLINABLE'));
        $this->assertSame(['id' => 'PIECES_NUMBER', 'value_name' => '1'], $this->attr($p, 'PIECES_NUMBER'));

        // Pacote estimado pela IA (não havia planilha do cliente).
        $this->assertSame('18000 g', $this->attr($p, 'SELLER_PACKAGE_WEIGHT')['value_name']);
        $this->assertSame('70 cm', $this->attr($p, 'SELLER_PACKAGE_LENGTH')['value_name']);

        $this->assertSame('12 meses', collect($p['sale_terms'])->firstWhere('id', 'WARRANTY_TIME')['value_name']);
        $this->assertSame('me2', $p['shipping']['mode']);

        // Selo de origem no wizard: o publicador vê o que a IA preencheu.
        $this->assertSame('ia', $p['meta_campos']['title']);
        $this->assertSame('ia', $p['meta_campos']['attr:BRAND']);
        $this->assertSame('ia', $p['meta_campos']['pesoG']);

        // NADA publicado: nenhum POST/PUT ao Mercado Livre, nenhum /items.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre') && $r->method() !== 'GET');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/items'));

        // A ficha pediu à IA a categoria certa, com o caminho legível.
        // (lê o prompt decodificado: o corpo JSON escapa os acentos)
        Http::assertSent(fn ($r) => str_contains($r->url(), 'llm.teste')
            && str_contains((string) data_get($r->data(), 'messages.0.content'), 'Casa, Móveis e Decoração › Cadeiras Gamer'));
    }

    public function test_valor_fora_do_catalogo_e_descartado_nao_ajustado(): void
    {
        // Opção que não existe, atributo que não é da categoria e unidade não
        // aceita: tudo fica VAZIO para o publicador — nunca "o mais parecido".
        $this->fakes($this->fichaCompleta(['atributos' => [
            'BRAND'      => 'Unity',
            'MATERIAL'   => 'Titânio',
            'INVENTADO'  => 'qualquer',
            'WIDTH'      => '45 polegadas',
            'GTIN'       => '7891234567895',
        ]]));
        $analise = $this->analise($this->companyConectada(), $this->admin());

        $this->rodar($analise);

        $p = MlAnuncioRascunho::findOrFail($analise->fresh()->rascunhoId())->payload;
        $this->assertNotNull($this->attr($p, 'BRAND'));
        $this->assertNull($this->attr($p, 'MATERIAL'));
        $this->assertNull($this->attr($p, 'INVENTADO'));
        $this->assertNull($this->attr($p, 'WIDTH'));
        $this->assertNull($this->attr($p, 'GTIN'), 'GTIN é do produto físico: a IA não preenche.');

        $ficha = $analise->fresh()->resultado['ficha'];
        $this->assertEqualsCanonicalizing(['MATERIAL', 'INVENTADO', 'WIDTH', 'GTIN'], $ficha['descartados']);
        // MODEL é obrigatório e ficou sem valor: a tela diz o que falta.
        $this->assertSame(['Modelo'], $analise->fresh()->resumoFicha()['obrigatorios_faltando']);
    }

    public function test_variacoes_nascem_com_gtin_valido_e_sem_foto(): void
    {
        $this->fakes($this->fichaCompleta(['variacoes' => [
            ['COLOR' => 'Preto'],
            ['COLOR' => 'branco'],
            ['COLOR' => 'Preto'],   // repetida: some
            ['COLOR' => 'Roxo'],    // fora da lista: some
        ]]));
        $analise = $this->analise($this->companyConectada(), $this->admin());

        $this->rodar($analise);

        $p = MlAnuncioRascunho::findOrFail($analise->fresh()->rascunhoId())->payload;

        $this->assertCount(2, $p['variations']);
        $this->assertSame(0, $p['available_quantity'], 'Com variações o estoque fica nelas.');
        $this->assertSame(['52049', '52055'], array_map(fn ($v) => $v['attribute_combinations'][0]['value_id'], $p['variations']));
        $this->assertSame('Branco', $p['variations'][1]['attribute_combinations'][0]['value_name']);

        $gtins = [];
        foreach ($p['variations'] as $v) {
            $this->assertSame([], $v['picture_ids']);
            $gtin = collect($v['attributes'])->firstWhere('id', 'GTIN')['value_name'];
            $this->assertMatchesRegularExpression('/^789\d{10}$/', $gtin);

            // Dígito verificador do EAN-13 (o mesmo cálculo do wizard).
            $soma = 0;
            for ($i = 0; $i < 12; $i++) {
                $soma += (int) $gtin[$i] * (($i + 1) % 2 === 0 ? 3 : 1);
            }
            $this->assertSame((10 - $soma % 10) % 10, (int) $gtin[12]);
            $gtins[] = $gtin;
        }
        $this->assertCount(2, array_unique($gtins));

        // ML erro 146: o atributo da variação não pode ficar também no item.
        $this->assertNull($this->attr($p, 'COLOR'));
    }

    public function test_produto_do_cliente_da_preco_estoque_e_medidas_ao_rascunho(): void
    {
        $company = $this->companyConectada();
        $mlb     = MlbEmpresa::create(['nome' => 'Casa Conforto', 'tipo' => 'ASSESSORIA', 'company_id' => $company->id]);
        MlbImplementacao::create([
            'empresa_id' => $mlb->id,
            'token'      => 'tok_' . uniqid(),
            'dados'      => ['itens' => [
                'planilha_produtos' => ['produtos' => [[
                    'sku' => 'CG-01', 'produto' => 'Cadeira Gamer', 'altura' => '95', 'largura' => '55',
                    'profundidade' => '60', 'peso_kg' => '2.5', 'estoque' => '8',
                ]]],
                'precificacao' => [
                    'classico' => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'  => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0, 'lucro_liquido' => 0, 'acrescimo' => 0.20,
                    'produtos' => [['sku' => 'CG-01', 'custo' => '200', 'frete_classico' => '30', 'frete_premium' => '40']],
                ],
            ]],
        ]);

        // O pedido só diz QUAL SKU; preço e medidas o servidor lê da planilha.
        \Illuminate\Support\Facades\Queue::fake();
        $id = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $company->id,
            'produto'    => 'Cadeira Gamer Reclinável',
            'sku'        => 'CG-01',
        ])->assertStatus(202)->json('id');

        $this->fakes($this->fichaCompleta());
        $this->rodar(MlAnuncioIaAnalise::findOrFail($id));

        $analise  = MlAnuncioIaAnalise::findOrFail($id);
        $rascunho = MlAnuncioRascunho::findOrFail($analise->rascunhoId());
        $p        = $rascunho->payload;

        // (200 + 30) / (1 − 0,115 − 0,19) × 1,20 = 397,12
        $this->assertEqualsWithDelta(397.12, $p['price'], 0.001);
        $this->assertSame(8, $p['available_quantity']);
        $this->assertSame('CG-01', $rascunho->sku_origem);

        // Medida do cliente vence a estimativa da IA.
        $this->assertSame('2500 g', $this->attr($p, 'SELLER_PACKAGE_WEIGHT')['value_name']);
        $this->assertSame('60 cm', $this->attr($p, 'SELLER_PACKAGE_LENGTH')['value_name']);
        $this->assertSame('cliente', $p['meta_campos']['pesoG']);
        $this->assertSame('cliente', $p['meta_campos']['price']);

        // Acima de R$ 79 o ME2 exige frete grátis.
        $this->assertTrue($p['shipping']['free_shipping']);
    }

    /** O corte do frete grátis obrigatório vem do config (era um 79 fixo aqui e no wizard; 09/10/2026). */
    public function test_frete_gratis_do_rascunho_segue_o_corte_do_config(): void
    {
        config(['estrutura_produtos.frete.gratis_obrigatorio_a_partir' => 500]);
        $company = $this->companyConectada();
        $mlb     = MlbEmpresa::create(['nome' => 'Casa Conforto', 'tipo' => 'ASSESSORIA', 'company_id' => $company->id]);
        MlbImplementacao::create([
            'empresa_id' => $mlb->id,
            'token'      => 'tok_' . uniqid(),
            'dados'      => ['itens' => [
                'planilha_produtos' => ['produtos' => [[
                    'sku' => 'CG-01', 'produto' => 'Cadeira Gamer', 'altura' => '95', 'largura' => '55',
                    'profundidade' => '60', 'peso_kg' => '2.5', 'estoque' => '8',
                ]]],
                'precificacao' => [
                    'classico' => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'  => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0, 'lucro_liquido' => 0, 'acrescimo' => 0.20,
                    'produtos' => [['sku' => 'CG-01', 'custo' => '200', 'frete_classico' => '30', 'frete_premium' => '40']],
                ],
            ]],
        ]);

        \Illuminate\Support\Facades\Queue::fake();
        $id = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $company->id,
            'produto'    => 'Cadeira Gamer Reclinável',
            'sku'        => 'CG-01',
        ])->assertStatus(202)->json('id');

        $this->fakes($this->fichaCompleta());
        $this->rodar(MlAnuncioIaAnalise::findOrFail($id));

        $p = MlAnuncioRascunho::findOrFail(MlAnuncioIaAnalise::findOrFail($id)->rascunhoId())->payload;

        // R$ 397,12 fica abaixo do corte de R$ 500: sem frete grátis.
        $this->assertEqualsWithDelta(397.12, $p['price'], 0.001);
        $this->assertFalse($p['shipping']['free_shipping']);
    }

    public function test_sku_que_nao_esta_na_planilha_e_recusado(): void
    {
        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
            'sku'        => 'NAO-EXISTE',
        ])->assertStatus(422);

        $this->assertSame(0, MlAnuncioIaAnalise::count());
    }

    public function test_sem_categoria_sugerida_o_rascunho_sai_mesmo_assim(): void
    {
        // Preditor vazio: não há ficha a preencher, então a 4ª chamada à IA
        // nem acontece — e o rascunho sai com título e descrição.
        $this->fakes($this->fichaCompleta(), candidatos: []);
        $analise = $this->analise($this->companyConectada(), $this->admin());

        $this->rodar($analise);

        $analise->refresh();
        $rascunho = MlAnuncioRascunho::findOrFail($analise->rascunhoId());

        $this->assertNull($rascunho->category_id);
        $this->assertSame(self::TITULO_NA_REGRA, $rascunho->payload['title']);
        $this->assertStringContainsString('escolha no passo 1', $analise->resumoFicha()['aviso']);
        Http::assertSentCount(3 + 2); // 3 à IA + preditor pelo produto e pelo título
    }

    public function test_ultima_tentativa_sem_ficha_ainda_grava_o_rascunho(): void
    {
        // A IA caiu na ficha e não há mais tentativa: o rascunho sai com
        // categoria, título e descrição, e o aviso diz o que faltou.
        Http::fake([
            'api.mercadolibre.com/sites/MLB/domain_discovery/search*' => Http::response([['category_id' => 'MLB1234', 'category_name' => 'Cadeiras Gamer']]),
            'api.mercadolibre.com/categories/MLB1234/attributes'      => Http::response($this->atributosCategoria()),
            'api.mercadolibre.com/categories/MLB1234'                 => Http::response(['id' => 'MLB1234', 'name' => 'Cadeiras Gamer', 'settings' => ['max_title_length' => 60]]),
            'llm.teste/*' => Http::sequence()
                ->push($this->resposta(['analise' => ['puv' => 'x']]))
                ->push($this->resposta(['titulos' => [['texto' => self::TITULO_NA_REGRA]]]))
                ->push($this->resposta(['descricao' => 'Olá!']))
                ->push('sobrecarga', 503),
        ]);

        $analise = $this->analise($this->companyConectada(), $this->admin());
        $job     = new GerarAnaliseAnuncioIaJob($analise->id);
        $fila    = Mockery::mock(Job::class);
        $fila->shouldReceive('attempts')->andReturn($job->tries);
        $job->setJob($fila);

        $this->rodar($analise, $job);

        $analise->refresh();
        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $analise->status);
        $rascunho = MlAnuncioRascunho::findOrFail($analise->rascunhoId());
        $this->assertSame('MLB1234', $rascunho->category_id);
        $this->assertStringContainsString('complete no passo 2', $analise->resumoFicha()['aviso']);
    }

    public function test_primeira_tentativa_sem_ficha_retenta_sem_gravar_rascunho(): void
    {
        Http::fake([
            'api.mercadolibre.com/sites/MLB/domain_discovery/search*' => Http::response([['category_id' => 'MLB1234']]),
            'api.mercadolibre.com/categories/MLB1234/attributes'      => Http::response($this->atributosCategoria()),
            'api.mercadolibre.com/categories/MLB1234'                 => Http::response(['id' => 'MLB1234']),
            'llm.teste/*' => Http::sequence()
                ->push($this->resposta(['analise' => ['puv' => 'x']]))
                ->push($this->resposta(['titulos' => [['texto' => self::TITULO_NA_REGRA]]]))
                ->push($this->resposta(['descricao' => 'Olá!']))
                ->push('sobrecarga', 503),
        ]);

        $analise = $this->analise($this->companyConectada(), $this->admin());

        try {
            $this->rodar($analise);
            $this->fail('A 1ª tentativa deveria devolver a falha para o job retentar.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($analise->fresh()->rascunhoId());
        $this->assertSame(0, MlAnuncioRascunho::count());
        // O que já ficou pronto fica para a retentativa.
        $this->assertSame('Olá!', $analise->fresh()->descricao());
    }

    public function test_retentativa_nao_duplica_o_rascunho(): void
    {
        $company  = $this->companyConectada();
        $user     = $this->admin();
        $rascunho = MlAnuncioRascunho::create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'payload' => ['title' => 'x'], 'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
        $analise = $this->analise($company, $user, [
            'analise'     => ['puv' => 'x'],
            'titulos'     => [['texto' => self::TITULO_NA_REGRA]],
            'descricao'   => 'Olá!',
            'ficha'       => ['category_id' => null],
            'rascunho_id' => $rascunho->id,
        ]);

        $this->rodar($analise);

        Http::assertNothingSent();
        $this->assertSame(1, MlAnuncioRascunho::count());
        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $analise->fresh()->status);
    }

    public function test_status_entrega_o_rascunho_para_o_wizard_abrir(): void
    {
        $this->fakes($this->fichaCompleta());
        $analise = $this->analise($this->companyConectada(), $admin = $this->admin());
        $this->rodar($analise);

        $this->actingAs($admin)
            ->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $analise->id]))
            ->assertOk()
            ->assertJsonPath('rascunho.id', $analise->fresh()->rascunhoId())
            ->assertJsonPath('rascunho.status', MlAnuncioRascunho::STATUS_RASCUNHO)
            ->assertJsonPath('rascunho.payload.title', self::TITULO_NA_REGRA)
            ->assertJsonPath('ficha.category_id', 'MLB1234')
            ->assertJsonPath('ficha.caminho', 'Casa, Móveis e Decoração › Cadeiras Gamer')
            ->assertJsonPath('ficha.atributos', 6)
            ->assertJsonPath('ficha.obrigatorios_faltando', [])
            ->assertJsonPath('ficha.pacote_completo', true)
            // Conferência da régua ECF chega à tela (antes era descartada).
            ->assertJsonPath('titulos.0.dentro_da_regra', false)
            ->assertJsonPath('titulos.1.dentro_da_regra', true);
    }

    public function test_rascunho_de_outra_empresa_nao_vaza_pelo_status(): void
    {
        $outra    = $this->companyConectada('Outra Loja');
        $user     = $this->admin();
        $alheio   = MlAnuncioRascunho::create([
            'company_id' => $outra->id, 'user_id' => $user->id,
            'payload' => ['title' => 'segredo'], 'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
        $analise = $this->analise($this->companyConectada(), $user, [
            'titulos' => [['texto' => 'x']], 'rascunho_id' => $alheio->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $analise->id]))
            ->assertOk()
            ->assertJsonPath('rascunho', null);
    }
}
