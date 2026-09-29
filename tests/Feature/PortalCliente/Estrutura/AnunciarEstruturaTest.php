<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\Company;
use App\Models\EstruturaPublicacao;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\EstruturaPublicacaoService;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Anunciar do Mapeamento Estrutural (ADR PORTAL-03): o par Clássico + Premium
 * de uma oferta publicado pelo portal, sobre o gabarito da planilha e com o
 * Mercado Livre inteiro em `Http::fake` — nenhuma chamada real, nunca.
 *
 * A oferta de trabalho é a CAD-01-CB3 (sem anúncio no gabarito, "Publicar
 * Clássico + Premium"): ela ganha os dois títulos planejados na aba Anúncios
 * e o custo na Precificação, e o Anunciar tem de completar exatamente esses
 * registros — não criar segundos.
 */
class AnunciarEstruturaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const CATEGORIA = [
        'id' => 'MLB1234', 'name' => 'Cadeiras',
        'path_from_root' => [['id' => 'MLB1', 'name' => 'Casa, Móveis e Decoração'], ['id' => 'MLB1234', 'name' => 'Cadeiras']],
        'settings' => ['max_title_length' => 60], 'children_categories' => [],
    ];

    private const ATRIBUTOS = [
        ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
        ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => ['required' => true]],
        // Aceita variação: fica de fora da ficha do par (é grade de variação, como no wizard admin).
        ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'list', 'tags' => ['required' => true, 'allow_variations' => true]],
        ['id' => 'SIZE_GRID_ID', 'name' => 'Grade', 'value_type' => 'string', 'tags' => ['required' => true]],
        ['id' => 'GTIN', 'name' => 'Código universal', 'value_type' => 'string', 'tags' => []],
    ];

    protected function conectar(Company $empresa): void
    {
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
    }

    /**
     * O Mercado Livre inteiro, falso. `$items` é a sequência de respostas do
     * POST /items; `$validate`, a do POST /items/validate (Clássico primeiro,
     * Premium depois, a cada conferência). `Http::fake()` acumula e o
     * primeiro stub que casa vence — por isso tudo entra de uma vez.
     */
    protected function fakeMl(array $items = [['id' => 'MLB111'], ['id' => 'MLB222']], array $validate = [204, 204]): void
    {
        $seqItems = Http::sequence();
        foreach ($items as $r) {
            is_int($r) ? $seqItems->push(['message' => 'Validation error', 'error' => 'validation_error', 'cause' => [['code' => 'item.price.invalid', 'message' => 'Invalid price', 'type' => 'error']]], $r) : $seqItems->push($r, 201);
        }
        // Esgotada a sequência, o ML aprova: a maioria dos testes confere mais
        // de uma vez e só se importa com as primeiras respostas.
        $seqValidate = Http::sequence()->whenEmpty(Http::response('', 204));
        foreach ($validate as $r) {
            is_int($r) ? $seqValidate->push('', $r) : $seqValidate->push($r, 400);
        }

        Http::fake([
            '*/oauth/token'                   => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/users/*'                       => Http::response(['id' => 436501796, 'tags' => []]),
            '*/domain_discovery/search*'      => Http::response([['domain_id' => 'MLB-CHAIRS', 'domain_name' => 'Cadeiras', 'category_id' => 'MLB1234', 'category_name' => 'Cadeiras']]),
            '*/categories/MLB1234/attributes' => Http::response(self::ATRIBUTOS),
            '*/categories/MLB1234'            => Http::response(self::CATEGORIA),
            '*/pictures/items/upload'         => Http::response(['id' => 'PIC1', 'variations' => [['secure_url' => 'https://http2.mlstatic.com/D_PIC1-O.jpg']]]),
            '*/items/validate'                => $seqValidate,
            '*/items/*/description'           => Http::response([]),
            '*/items'                         => $seqItems,
        ]);
    }

    /** O gabarito + a CB3 pronta para anunciar: títulos planejados e custo. */
    protected function cenario(bool $conectar = true): array
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        if ($conectar) {
            $this->conectar($empresa);
        }
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $cb3 = $ofertas['CAD-01-CB3'];

        // A aba Anúncios: os dois títulos, ainda sem MLB (planejados).
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $cb3->id), ['tipo' => 'classico', 'titulo' => 'Kit 3 Cadeiras 01 Madeira Maciça'])->assertSessionHasNoErrors();
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $cb3->id), ['tipo' => 'premium', 'titulo' => 'Kit 3 Cadeiras de Jantar Estofadas'])->assertSessionHasNoErrors();
        // A Precificação: custo e fretes da CB3.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $cb3->id), ['custo' => '300', 'frete_classico' => '20', 'frete_premium' => '25'])->assertSessionHasNoErrors();

        return [$empresa, $ofertas, $sessao];
    }

    /** O rascunho completo da CB3 — tudo o que a conferência local pede. */
    protected function rascunhoCompleto(array $extra = []): array
    {
        return array_replace_recursive([
            'categoria_id' => 'MLB1234', 'categoria_nome' => 'Casa, Móveis e Decoração › Cadeiras', 'categoria_origem' => 'sugerida',
            'atributos' => ['BRAND' => ['value_name' => 'Móveis Brasil'], 'MODEL' => ['value_name' => 'Milano']],
            'fotos' => [['id' => 'PIC1', 'url' => 'https://http2.mlstatic.com/D_PIC1-O.jpg']],
            'estoque' => 5, 'condicao' => 'new',
            'envio' => ['modo' => 'me2', 'frete_gratis' => false],
            'embalagem' => ['peso_g' => 7200, 'altura_cm' => 55, 'largura_cm' => 50, 'comprimento_cm' => 95],
            'descricao' => 'Kit com 3 cadeiras.',
        ], $extra);
    }

    protected function postsEm(string $sufixo): int
    {
        return count(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), $sufixo)));
    }

    // ═══ A página ═══════════════════════════════════════════════════════════

    public function test_a_pagina_abre_marca_o_submodulo_e_separa_a_anunciar_de_publicados(): void
    {
        [$empresa, $ofertas, $sessao] = $this->cenario(conectar: false);

        // Sem conta do ML: a página abre (estado vazio na tela), o submódulo é o Anunciar.
        $sessao->get(route('portal.auth.estrutura.anunciar'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaAnunciar')
                ->where('ml_conectado', false)
                ->where('anunciar.contagens', ['a_anunciar' => 8, 'publicados' => 1])
                ->where('anunciar.filtro', 'a_anunciar')
                ->count('anunciar.ofertas', 8)
                ->where('modulos', function ($m) {
                    $sub = collect(collect($m)->firstWhere('chave', 'estrutura')['submodulos'])->firstWhere('chave', 'anunciar');

                    return $sub['ativo'] && ! $sub['em_breve'] && $sub['url'] === route('portal.auth.estrutura.anunciar');
                })
            );

        // O que falta, em português, por oferta: a CB2 (falta Premium) ainda não tem categoria.
        $lista = $sessao->get(route('portal.auth.estrutura.anunciar'))->viewData('page')['props']['anunciar']['ofertas'];
        $cb2 = collect($lista)->firstWhere('sku', 'CAD-01-CB2');
        $this->assertSame('falta_premium', $cb2['situacao']);
        $this->assertSame(['chave' => 'categoria', 'rotulo' => 'falta categoria'], $cb2['prontidao']);
        $this->assertSame('MLB0000000003', $cb2['anuncios']['classico']);
        // A CB3 tem preço: o anunciado da Precificação chega pronto do servidor.
        $cb3 = collect($lista)->firstWhere('sku', 'CAD-01-CB3');
        $this->assertSame(PrecificacaoEstrutura::preco(300, 20, 11.5, 19, 0, 0, 20)['anunciado'], $cb3['preco_classico']);

        // "Publicados": a CAD-01, apagada, com os dois MLB.
        $sessao->get(route('portal.auth.estrutura.anunciar', ['filtro' => 'publicados']))
            ->assertInertia(fn ($page) => $page
                ->count('anunciar.ofertas', 1)
                ->where('anunciar.ofertas.0.sku', 'CAD-01')
                ->where('anunciar.ofertas.0.prontidao.chave', 'publicado')
                ->where('anunciar.ofertas.0.anuncios', ['classico' => 'MLB0000000001', 'premium' => 'MLB0000000002'])
            );

        // Busca no servidor.
        $sessao->get(route('portal.auth.estrutura.anunciar', ['q' => 'mesa']))
            ->assertInertia(fn ($page) => $page->count('anunciar.ofertas', 3)->where('anunciar.contagens.a_anunciar', 8));
    }

    // ═══ Rascunho ═══════════════════════════════════════════════════════════

    public function test_abrir_pre_preenche_titulo_da_aba_anuncios_e_preco_da_precificacao_e_o_rascunho_salva(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        $r = $sessao->getJson(route('portal.auth.estrutura.publicacao.abrir', $cb3->id))->assertOk()->json();

        $this->assertSame('Kit 3 Cadeiras 01 Madeira Maciça', $r['dados']['tipos']['classico']['titulo']);
        $this->assertSame('Kit 3 Cadeiras de Jantar Estofadas', $r['dados']['tipos']['premium']['titulo']);
        $this->assertSame(PrecificacaoEstrutura::preco(300, 20, 11.5, 19, 0, 0, 20)['anunciado'], $r['dados']['tipos']['classico']['preco']);
        $this->assertSame(PrecificacaoEstrutura::preco(300, 25, 16.5, 19, 0, 0, 20)['anunciado'], $r['dados']['tipos']['premium']['preco']);
        $this->assertSame(PrecificacaoEstrutura::preco(300, 20, 11.5, 19, 0, 0, 20)['minimo'], $r['referencia']['classico']['preco_minimo']);
        $this->assertSame('Kit 3 Cadeiras 01 Madeira Maciça', $r['referencia']['classico']['titulo_planejado']);

        // Categoria sugerida pelo título, com a meta: caminho, limite e SÓ os obrigatórios sem variação/grade.
        $this->assertSame('MLB1234', $r['dados']['categoria_id']);
        $this->assertSame('sugerida', $r['dados']['categoria_origem']);
        // O nome gravado é o caminho inteiro — o mesmo que o card e a lista mostram.
        $this->assertSame('Casa, Móveis e Decoração › Cadeiras', $r['dados']['categoria_nome']);
        $this->assertSame(['Casa, Móveis e Decoração', 'Cadeiras'], $r['sugestoes'][0]['caminho']);
        $this->assertSame(['Casa, Móveis e Decoração', 'Cadeiras'], $r['categoria']['caminho']);
        $this->assertSame(60, $r['categoria']['max_titulo']);
        $this->assertSame(['BRAND', 'MODEL'], array_column($r['categoria']['atributos'], 'id'));
        $this->assertSame(['fotos', 'estoque', 'ficha', 'ficha'], array_column($r['pendencias'], 'campo'));
        $this->assertFalse($r['publicacao']['conferida']);
        // GET não grava.
        $this->assertSame(0, EstruturaPublicacao::count());

        // Salvar o rascunho: o que a pessoa digitou fica; o resto continua vindo do módulo.
        // A resposta traz o que a tela precisa depois de salvar: a conferência
        // ainda vale? o que falta? (sem `pendencias` a tela caía em `undefined.length`).
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto([
            'tipos' => ['premium' => ['titulo' => 'Kit 3 Cadeiras Estofadas Premium']],
        ]))->assertOk()->assertJsonPath('status', 'rascunho')->assertJsonPath('publicacao.conferida', false)->assertJsonPath('pendencias', []);

        $r = $sessao->getJson(route('portal.auth.estrutura.publicacao.abrir', $cb3->id))->json();
        $this->assertSame('Kit 3 Cadeiras Estofadas Premium', $r['dados']['tipos']['premium']['titulo']);
        $this->assertSame('Kit 3 Cadeiras 01 Madeira Maciça', $r['dados']['tipos']['classico']['titulo']);
        $this->assertSame(5, $r['dados']['estoque']);
        $this->assertSame(['value_id' => null, 'value_name' => 'Móveis Brasil'], $r['dados']['atributos']['BRAND']);
        $this->assertSame([], $r['pendencias']);
        $this->assertSame([], $r['sugestoes']);

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'publicacao_rascunho')->first();
        $this->assertSame('cliente', $log->properties['origem']);
    }

    public function test_foto_sobe_para_o_ml_e_entra_no_rascunho(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        // `->image()`, não `->create()`: o `create()` gera arquivo VAZIO, e o
        // multipart do Guzzle recusa `contents` vazio antes de qualquer fake.
        $sessao->post(route('portal.auth.estrutura.publicacao.fotos', $cb3->id), ['imagem' => UploadedFile::fake()->image('cadeira.jpg', 600, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('fotos.0.id', 'PIC1')
            ->assertJsonPath('fotos.0.url', 'https://http2.mlstatic.com/D_PIC1-O.jpg');

        $this->assertSame(1, $this->postsEm('/pictures/items/upload'));
        $this->assertSame([['id' => 'PIC1', 'url' => 'https://http2.mlstatic.com/D_PIC1-O.jpg']], $cb3->publicacao()->first()->dados['fotos']);

        // Não é imagem: nem chega ao ML.
        $sessao->post(route('portal.auth.estrutura.publicacao.fotos', $cb3->id), ['imagem' => UploadedFile::fake()->create('lista.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(1, $this->postsEm('/pictures/items/upload'));
    }

    /**
     * A ordem das fotos é a do anúncio: a 1ª é a capa e o ML recebe
     * `pictures` nesta sequência, nos dois tipos. Reordenar (arraste, ◀ ▶,
     * tornar capa) depois de conferir é edição como outra qualquer: o hash
     * denuncia, e publicar espera nova conferência.
     */
    public function test_a_ordem_das_fotos_e_a_do_anuncio_e_reordenar_exige_conferir_de_novo(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];
        $foto = fn ($id) => ['id' => $id, 'url' => "https://http2.mlstatic.com/D_{$id}-O.jpg"];
        $picturesEnviadas = fn (string $sufixo) => Http::recorded(fn (Request $q) => str_ends_with($q->url(), $sufixo))
            ->map(fn ($par) => array_column($par[0]->data()['pictures'], 'id'))->values()->all();

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), array_replace($this->rascunhoCompleto(), ['fotos' => [$foto('PIC1'), $foto('PIC2'), $foto('PIC3')]]))->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);
        $this->assertSame([['PIC1', 'PIC2', 'PIC3'], ['PIC1', 'PIC2', 'PIC3']], $picturesEnviadas('/items/validate'));

        // Arrastou a 3ª para a capa: o rascunho guarda a nova ordem e a conferência caduca.
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), array_replace($this->rascunhoCompleto(), ['fotos' => [$foto('PIC3'), $foto('PIC1'), $foto('PIC2')]]))
            ->assertOk()->assertJsonPath('publicacao.conferida', false)->assertJsonPath('status', 'rascunho');
        $this->assertSame(['PIC3', 'PIC1', 'PIC2'], array_column($cb3->publicacao()->first()->dados['fotos'], 'id'));
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertStatus(422)
            ->assertJsonPath('errors.publicacao.0', 'Confira o anúncio no Mercado Livre antes de publicar.');
        $this->assertSame(0, $this->postsEm('/items'));

        // Conferiu de novo e publicou: Clássico e Premium saem com a capa nova, na mesma sequência.
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertOk()->assertJsonPath('publicacao.status', 'publicado');
        $this->assertSame([['PIC3', 'PIC1', 'PIC2'], ['PIC3', 'PIC1', 'PIC2']], $picturesEnviadas('/items'));
    }

    /**
     * A lista de sugestões traz o CAMINHO inteiro de cada categoria (29/09,
     * pedido do usuário: "Caixa de Direção" e "Caixas de Direção Hidráulica"
     * só se distinguem pela árvore, e ele precisa vê-la ANTES de escolher).
     * Os caminhos são lidos em paralelo e aquecem o cache do
     * `MlCatalogoMetaService`; uma categoria que falha aparece sem caminho,
     * e as outras não caem.
     */
    public function test_sugestoes_de_categoria_trazem_o_caminho_completo_e_uma_falha_nao_derruba_as_outras(): void
    {
        $arvore = fn ($id, $nome) => ['id' => $id, 'name' => $nome, 'settings' => ['max_title_length' => 60], 'children_categories' => [],
            'path_from_root' => [['id' => 'MLB5672', 'name' => 'Acessórios para Veículos'], ['id' => 'MLB1747', 'name' => 'Peças de Carros e Caminhonetes'],
                ['id' => 'MLB22693', 'name' => 'Direção'], ['id' => $id, 'name' => $nome]]];
        $dominio = 'Caixas de direção para veículos';

        Http::fake([
            '*/oauth/token'              => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/domain_discovery/search*' => Http::response([
                ['domain_id' => 'MLB-STEERING', 'domain_name' => $dominio, 'category_id' => 'MLB193420', 'category_name' => 'Caixa de Direção'],
                ['domain_id' => 'MLB-STEERING', 'domain_name' => $dominio, 'category_id' => 'MLB456920', 'category_name' => 'Caixas de Direção Hidráulica'],
                ['domain_id' => 'MLB-STEERING', 'domain_name' => $dominio, 'category_id' => 'MLB447370', 'category_name' => 'Cajas de Dirección Hidráulica'],
                // O preditor repete a mesma categoria em dois domínios: aparece uma vez.
                ['domain_id' => 'MLB-OUTRO', 'domain_name' => 'Outro', 'category_id' => 'MLB193420', 'category_name' => 'Caixa de Direção'],
            ]),
            '*/categories/MLB193420'     => Http::response($arvore('MLB193420', 'Caixa de Direção')),
            '*/categories/MLB456920'     => Http::response($arvore('MLB456920', 'Caixas de Direção Hidráulica')),
            '*/categories/MLB447370'     => Http::response(['message' => 'internal error'], 500),
        ]);
        [$empresa, $ofertas, $sessao] = $this->cenario();
        // Só o GET /categories/{id} — o `/categories/{id}/attributes` da meta é outra leitura.
        $chamadas = fn () => count(Http::recorded(fn (Request $q) => preg_match('#/categories/MLB\d+$#', $q->url()) === 1));

        $r = $sessao->getJson(route('portal.auth.estrutura.anunciar.categorias', ['q' => 'caixa de direção']))->assertOk()->json();

        $this->assertSame(['MLB193420', 'MLB456920', 'MLB447370'], array_column($r, 'id'));
        $this->assertSame(['Acessórios para Veículos', 'Peças de Carros e Caminhonetes', 'Direção', 'Caixa de Direção'], $r[0]['caminho']);
        $this->assertSame('Caixas de Direção Hidráulica', end($r[1]['caminho']));
        // A que falhou: o nome do preditor, sem caminho — e a lista inteira de pé.
        $this->assertSame(['id' => 'MLB447370', 'nome' => 'Cajas de Dirección Hidráulica', 'dominio' => $dominio, 'caminho' => []], $r[2]);
        // Uma chamada por categoria, todas de uma vez.
        $this->assertSame(3, $chamadas());

        // Segunda busca: os caminhos vêm do cache; só a que falhou é lida de novo.
        $sessao->getJson(route('portal.auth.estrutura.anunciar.categorias', ['q' => 'caixa de direção']))
            ->assertOk()->assertJsonPath('0.caminho.3', 'Caixa de Direção')->assertJsonPath('2.caminho', []);
        $this->assertSame(4, $chamadas());

        // O aquecimento serve ao resto do formulário: a meta da escolhida vem do cache.
        $sessao->getJson(route('portal.auth.estrutura.anunciar.categoria', 'MLB456920'))
            ->assertOk()->assertJsonPath('caminho.3', 'Caixas de Direção Hidráulica')->assertJsonPath('max_titulo', 60);
        $this->assertSame(4, $chamadas());
    }

    // ═══ Conferência ════════════════════════════════════════════════════════

    public function test_conferir_lista_pendencias_locais_sem_chamar_o_ml_e_traduz_os_erros_do_ml(): void
    {
        // 1ª conferência: Clássico passa (204), Premium volta 400; 2ª: os dois passam.
        $this->fakeMl(validate: [204, ['cause' => [['code' => 'item.attributes.missing_required', 'message' => 'Missing required [BRAND]', 'type' => 'error']]], 204, 204]);
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        // Sem foto e sem estoque: pendências locais, e o ML nem é consultado.
        // (`array_replace_recursive` não esvazia uma lista — por isso o `array_replace`.)
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), array_replace($this->rascunhoCompleto(), ['fotos' => [], 'estoque' => null]))->assertOk();
        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertOk()->json();
        $this->assertFalse($r['valido']);
        $this->assertSame(['fotos', 'estoque'], array_column($r['erros']['local'], 'campo'));
        $this->assertSame(0, $this->postsEm('/items/validate'));

        // Títulos iguais nos dois: erro da aula, antes do ML.
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto(['tipos' => ['classico' => ['titulo' => 'Mesmo Título'], 'premium' => ['titulo' => 'mesmo título']]]))->assertOk();
        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->json();
        $this->assertStringContainsString('títulos diferentes', $r['erros']['local'][0]['mensagem']);
        $this->assertSame(0, $this->postsEm('/items/validate'));

        // Tudo preenchido: o ML recusa o Premium, e a recusa chega traduzida.
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertOk();
        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->json();
        $this->assertFalse($r['valido']);
        $this->assertArrayNotHasKey('classico', $r['erros']);
        $this->assertSame('BRAND', $r['erros']['premium'][0]['campo']);
        $this->assertSame('Faltam atributos obrigatórios da categoria (preencha todos os campos marcados).', $r['erros']['premium'][0]['mensagem']);
        $this->assertSame(2, $this->postsEm('/items/validate'));
        $this->assertSame('rascunho', $r['publicacao']['status']);
        $this->assertFalse($r['publicacao']['conferida']);

        // Publicar sem conferência aprovada: recusado, sem POST /items.
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertStatus(422);
        $this->assertSame(0, $this->postsEm('/items'));

        // Agora o ML aprova os dois.
        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->json();
        $this->assertTrue($r['valido']);
        $this->assertSame('validado', $r['publicacao']['status']);
        $this->assertTrue($r['publicacao']['conferida']);

        // O payload conferido é o do par: gold_special e gold_pro, SELLER_SKU nos dois, foto por id.
        $validados = Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/items/validate'))->map(fn ($par) => $par[0]->data());
        $this->assertSame(['gold_special', 'gold_pro', 'gold_special', 'gold_pro'], $validados->pluck('listing_type_id')->all());
        $ultimo = $validados->last();
        $this->assertSame('Kit 3 Cadeiras de Jantar Estofadas', $ultimo['title']);
        $this->assertSame('MLB1234', $ultimo['category_id']);
        $this->assertSame([['id' => 'PIC1']], $ultimo['pictures']);
        $this->assertContains(['id' => 'SELLER_SKU', 'value_name' => 'CAD-01-CB3'], $ultimo['attributes']);
        $this->assertContains(['id' => 'SELLER_PACKAGE_WEIGHT', 'value_name' => '7200 g'], $ultimo['attributes']);
        $this->assertContains(['id' => 'BRAND', 'value_name' => 'Móveis Brasil'], $ultimo['attributes']);
        $this->assertSame(['mode' => 'me2', 'local_pick_up' => false, 'free_shipping' => false], $ultimo['shipping']);
    }

    // ═══ Publicação ═════════════════════════════════════════════════════════

    public function test_publicar_o_par_grava_os_dois_mlb_completa_os_planejados_e_a_oferta_vira_ok(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);

        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertOk()->json();
        $this->assertSame('publicado', $r['publicacao']['status']);
        $this->assertSame('MLB111', $r['publicacao']['ml_item_classico']);
        $this->assertSame('MLB222', $r['publicacao']['ml_item_premium']);
        $this->assertSame([], $r['erros']);

        // Dois POST /items (um por tipo) e duas descrições; o preço de cada tipo é o da Precificação.
        $this->assertSame(2, $this->postsEm('/items'));
        $this->assertSame(1, $this->postsEm('/items/MLB111/description'));
        $this->assertSame(1, $this->postsEm('/items/MLB222/description'));
        $enviados = Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/items'))->map(fn ($par) => $par[0]->data())->values();
        $this->assertSame(PrecificacaoEstrutura::preco(300, 20, 11.5, 19, 0, 0, 20)['anunciado'], $enviados[0]['price']);
        $this->assertSame(PrecificacaoEstrutura::preco(300, 25, 16.5, 19, 0, 0, 20)['anunciado'], $enviados[1]['price']);

        // A aba Anúncios: os DOIS planejados foram completados — nada de segundo Clássico.
        $anuncios = $cb3->anuncios()->orderBy('id')->get();
        $this->assertCount(2, $anuncios);
        $this->assertSame(['MLB111', 'MLB222'], $anuncios->pluck('codigo_mlb')->all());
        $this->assertSame(['classico', 'premium'], $anuncios->pluck('tipo')->all());
        $this->assertSame('Kit 3 Cadeiras 01 Madeira Maciça', $anuncios[0]->titulo);

        // A régua: a CB3 virou OK, o painel ganhou 2 publicados, a lista trocou de lado.
        $sessao->get(route('portal.auth.estrutura.mapeamento', ['q' => 'CAD-01-CB3']))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.painel.publicados', 6)
                ->where('estrutura.blocos.0.ofertas.0.situacao', 'ok'));
        $sessao->get(route('portal.auth.estrutura.anunciar'))
            ->assertInertia(fn ($page) => $page->where('anunciar.contagens', ['a_anunciar' => 7, 'publicados' => 2]));

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'par_publicado')->first();
        $this->assertSame('cliente', $log->properties['origem']);
        $this->assertSame('MLB222', $log->properties['premium']);
    }

    public function test_publicar_duas_vezes_nao_chama_o_ml_de_novo(): void
    {
        $this->fakeMl(items: [['id' => 'MLB111'], ['id' => 'MLB222'], ['id' => 'MLB333'], ['id' => 'MLB444']]);
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);

        // Alguém já está publicando (outra aba): recusa, sem tocar o ML.
        $pub = $cb3->publicacao()->first();
        $pub->update(['status' => EstruturaPublicacao::STATUS_PUBLICANDO, 'publicando_em' => now()]);
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))
            ->assertStatus(422)->assertJsonPath('errors.publicacao.0', 'Esta oferta já está sendo publicada — aguarde e recarregue a página.');
        $this->assertSame(0, $this->postsEm('/items'));

        // Trava órfã (o request de antes morreu há mais de 15 min): retoma e publica.
        $pub->update(['publicando_em' => now()->subMinutes(EstruturaPublicacaoService::PUBLICANDO_VENCE_MINUTOS + 1)]);
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertOk()->assertJsonPath('publicacao.status', 'publicado');
        $this->assertSame(2, $this->postsEm('/items'));

        // Duplo clique / segunda aba depois de publicado: nada vai para o ML.
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))
            ->assertStatus(422)->assertJsonPath('errors.publicacao.0', 'Esta oferta já foi publicada.');
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertStatus(422);
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertStatus(422);
        $this->assertSame(2, $this->postsEm('/items'));
        $this->assertSame(['MLB111', 'MLB222'], [$pub->fresh()->ml_item_classico, $pub->fresh()->ml_item_premium]);
    }

    public function test_falha_no_premium_deixa_parcial_e_o_retry_publica_so_o_premium(): void
    {
        // POST /items: Clássico ok, Premium 400, e na retomada o Premium ok.
        $this->fakeMl(items: [['id' => 'MLB111'], 400, ['id' => 'MLB222']]);
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);

        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertOk()->json();
        $this->assertSame('parcial', $r['publicacao']['status']);
        $this->assertSame('MLB111', $r['publicacao']['ml_item_classico']);
        $this->assertNull($r['publicacao']['ml_item_premium']);
        $this->assertSame('Preço inválido para esta categoria (fora da faixa permitida).', $r['erros']['premium'][0]['mensagem']);

        // O Clássico já está na aba Anúncios; o Premium continua planejado.
        $this->assertSame(['MLB111', null], $cb3->anuncios()->orderBy('id')->pluck('codigo_mlb')->all());
        $lista = $sessao->get(route('portal.auth.estrutura.anunciar'))->viewData('page')['props']['anunciar']['ofertas'];
        $this->assertSame(['chave' => 'parcial', 'rotulo' => 'falta publicar o Premium'], collect($lista)->firstWhere('sku', 'CAD-01-CB3')['prontidao']);

        // Tentar de novo: só o Premium vai para o ML; o Clássico não é republicado.
        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertOk()->json();
        $this->assertSame('publicado', $r['publicacao']['status']);
        $this->assertSame(['MLB111', 'MLB222'], [$r['publicacao']['ml_item_classico'], $r['publicacao']['ml_item_premium']]);
        $this->assertSame(3, $this->postsEm('/items'));
        $this->assertSame('gold_pro', Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/items'))->last()[0]->data()['listing_type_id']);
        $this->assertSame(['MLB111', 'MLB222'], $cb3->anuncios()->orderBy('id')->pluck('codigo_mlb')->all());
        $this->assertNotNull(Activity::where('log_name', 'portal')->where('properties->evento', 'par_parcial')->first());
    }

    public function test_o_que_mudou_depois_da_conferencia_precisa_ser_conferido_de_novo(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb3 = $ofertas['CAD-01-CB3'];

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);

        // Mexeu no formulário: o servidor sabe, e o botão desabilitado não é a única trava.
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto(['estoque' => 9]))
            ->assertOk()->assertJsonPath('publicacao.conferida', false)->assertJsonPath('status', 'rascunho');
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))
            ->assertStatus(422)->assertJsonPath('errors.publicacao.0', 'Confira o anúncio no Mercado Livre antes de publicar.');

        // Conferiu de novo; depois o custo mudou na PRECIFICAÇÃO — o preço efetivo mudou, e o hash denuncia.
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertJsonPath('valido', true);
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $cb3->id), ['custo' => '350', 'frete_classico' => '20', 'frete_premium' => '25'])->assertSessionHasNoErrors();
        $sessao->getJson(route('portal.auth.estrutura.publicacao.abrir', $cb3->id))->assertJsonPath('publicacao.conferida', false);
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))
            ->assertStatus(422)->assertJsonPath('errors.publicacao.0', 'O anúncio mudou depois da última conferência. Confira de novo no Mercado Livre antes de publicar.');
        $this->assertSame(0, $this->postsEm('/items'));
    }

    /**
     * "Falta Premium" publica SÓ o Premium: a CB2 do gabarito já tem o
     * Clássico (MLB0000000003, importado/colado), e um segundo Clássico seria
     * duplicata no ML. O Clássico existente aparece como "já no ar".
     */
    public function test_oferta_com_classico_importado_publica_so_o_premium(): void
    {
        $this->fakeMl(items: [['id' => 'MLB777']]);
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cb2 = $ofertas['CAD-01-CB2'];
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $cb2->id), ['tipo' => 'premium', 'titulo' => 'Kit 2 Cadeiras Estofadas Premium'])->assertSessionHasNoErrors();

        $r = $sessao->getJson(route('portal.auth.estrutura.publicacao.abrir', $cb2->id))->assertOk()->json();
        $this->assertSame(['premium'], $r['tipos_pendentes']);
        $this->assertSame('MLB0000000003', $r['referencia']['classico']['publicado']);
        $this->assertNull($r['referencia']['premium']['publicado']);
        // As pendências são só do Premium (o Clássico não precisa de título nem preço aqui).
        $this->assertNotContains('titulo_classico', array_column($r['pendencias'], 'campo'));

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb2->id), $this->rascunhoCompleto(['tipos' => ['premium' => ['preco' => 399.9]]]))->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb2->id))->assertOk()->assertJsonPath('valido', true);
        $this->assertSame(1, $this->postsEm('/items/validate'));

        $r = $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb2->id))->assertOk()->json();
        $this->assertSame('publicado', $r['publicacao']['status']);
        $this->assertNull($r['publicacao']['ml_item_classico']);
        $this->assertSame('MLB777', $r['publicacao']['ml_item_premium']);
        $this->assertSame(1, $this->postsEm('/items'));
        $this->assertSame('gold_pro', Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/items'))->first()[0]->data()['listing_type_id']);

        // A aba Anúncios: o Clássico de sempre e o Premium recém-publicado — dois, não três.
        $this->assertSame(['classico' => 'MLB0000000003', 'premium' => 'MLB777'], $cb2->anuncios()->orderBy('id')->pluck('codigo_mlb', 'tipo')->all());
        $sessao->get(route('portal.auth.estrutura.anunciar', ['filtro' => 'publicados']))
            ->assertInertia(fn ($page) => $page->where('anunciar.contagens.publicados', 2));
    }

    /** Oferta já OK (os dois no ar): nada a publicar, e o ML não é tocado. */
    public function test_oferta_completa_nao_publica_de_novo(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $cad = $ofertas['CAD-01'];

        $r = $sessao->getJson(route('portal.auth.estrutura.publicacao.abrir', $cad->id))->assertOk()->json();
        $this->assertSame([], $r['tipos_pendentes']);
        $this->assertSame([], $r['sugestoes']);   // sem nada a publicar, o preditor não é chamado
        $this->assertSame('MLB0000000002', $r['referencia']['premium']['publicado']);

        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cad->id))->assertStatus(422);
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cad->id))->assertStatus(422);
        $this->assertSame(0, $this->postsEm('/items/validate'));
        $this->assertSame(0, $this->postsEm('/items'));
        $this->assertSame(0, count(Http::recorded(fn (Request $q) => str_contains($q->url(), 'domain_discovery'))));
    }

    // ═══ Acesso ═════════════════════════════════════════════════════════════

    public function test_oferta_de_outra_empresa_responde_404(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $alheia = $this->listaDoGabarito($outra, $this->atorCliente($outra))['CAD-01'];

        $sessao->getJson(route('portal.auth.estrutura.publicacao.abrir', $alheia->id))->assertNotFound();
        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $alheia->id), $this->rascunhoCompleto())->assertNotFound();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $alheia->id))->assertNotFound();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $alheia->id))->assertNotFound();
        $sessao->post(route('portal.auth.estrutura.publicacao.fotos', $alheia->id), ['imagem' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, EstruturaPublicacao::count());
    }

    /** Sem conta do ML: o rascunho até salva, mas conferir, foto e publicar recusam — e nada vai para o ML. */
    public function test_sem_conta_do_ml_nada_vai_para_o_ml(): void
    {
        $this->fakeMl();
        [$empresa, $ofertas, $sessao] = $this->cenario(conectar: false);
        $cb3 = $ofertas['CAD-01-CB3'];

        $sessao->putJson(route('portal.auth.estrutura.publicacao.salvar', $cb3->id), $this->rascunhoCompleto())->assertOk();
        $sessao->postJson(route('portal.auth.estrutura.publicacao.validar', $cb3->id))->assertStatus(422)
            ->assertJsonPath('errors.publicacao.0', 'A conta do Mercado Livre desta empresa não está conectada. Conecte pelo Onboarding e volte aqui.');
        $sessao->post(route('portal.auth.estrutura.publicacao.fotos', $cb3->id), ['imagem' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')], ['Accept' => 'application/json'])->assertStatus(422);
        $sessao->postJson(route('portal.auth.estrutura.publicacao.publicar', $cb3->id))->assertStatus(422);
        $this->assertSame(0, $this->postsEm('/items/validate'));
        $this->assertSame(0, $this->postsEm('/pictures/items/upload'));
        $this->assertSame(0, $this->postsEm('/items'));
    }
}
