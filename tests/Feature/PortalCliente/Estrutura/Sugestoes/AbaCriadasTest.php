<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAcervoItem;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\FotosDasVariacoes;
use App\Services\Portal\Estrutura\Geracao\OfertasCriadas;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * 13/10/2026 — a aba "Criadas" do Planejamento. O usuário: "em Planejamento deveriam ficar os produtos que
 * planejamos… aceitou, ele fica ali". A sugestão aceita sumia da tela e não havia onde rever, dentro do
 * Planejamento, os combos, kits e combits que já existem.
 *
 * A lista é a das ofertas compostas da empresa, por tipo, com do que cada uma é feita, e com o preço e o
 * envio saídos da MESMA conta da Precificação. Nenhuma chamada ao Mercado Livre.
 */
class AbaCriadasTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(fn () => Http::response(['message' => 'não deveria chamar'], 500));
        // Criar oferta agenda o preparo no editor interno (outra tela, outro teste): aqui não roda.
        Queue::fake();
    }

    private function simples(Company $empresa, string $codigo, array $volume, ?string $custo): EstruturaOferta
    {
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [[
            'grupo' => 'G'.$codigo, 'codigo' => $codigo, 'nome' => 'Produto '.$codigo, 'valor' => null, 'eixo' => 'cor',
            'volumes' => [$volume], ...($custo !== null ? ['custo' => $custo] : []),
        ]], $this->atorCliente($empresa));

        return EstruturaOferta::where('company_id', $empresa->id)->where('sku', $codigo)->whereNotNull('variacao_id')->firstOrFail();
    }

    /** Uma poltrona que cabe no envio padrão, uma mesa, e três compostas: Combo 2, Kit e Combit. */
    private function cenario(): array
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $nina = $this->simples($empresa, 'NINA', ['c' => 53, 'l' => 60, 'a' => 80, 'kg' => 8], '100');
        $mesa = $this->simples($empresa, 'MESA', ['c' => 20, 'l' => 20, 'a' => 10, 'kg' => 2], null);

        $ofertas = app(EstruturaOfertaService::class);
        [$combo] = $ofertas->criar($empresa, ['sku' => 'NINA-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Nina', 'componentes' => [['id' => $nina->id, 'quantidade' => 2]]], $ator);
        [$kit] = $ofertas->criar($empresa, ['sku' => 'NINA+MESA', 'fase' => 'kit', 'nome' => 'Kit Nina e Mesa',
            'componentes' => [['id' => $nina->id, 'quantidade' => 1], ['id' => $mesa->id, 'quantidade' => 1]]], $ator);
        [$combit] = $ofertas->criar($empresa, ['sku' => 'NINA+MESA-CBT4', 'fase' => 'combit', 'nome' => 'Combit 4 Nina e Mesa',
            'componentes' => [['id' => $nina->id, 'quantidade' => 4], ['id' => $mesa->id, 'quantidade' => 1]]], $ator);

        return [$empresa, $nina, $mesa, $combo, $kit, $combit];
    }

    public function test_lista_as_compostas_por_tipo_com_a_composicao_o_envio_e_o_preco_da_precificacao(): void
    {
        [$empresa, $nina, $mesa, $combo, $kit] = $this->cenario();

        $r = app(OfertasCriadas::class)->listar($empresa);

        $this->assertSame(3, $r['total']);
        $this->assertSame(3, $r['filtrado']);
        $this->assertSame(['pagina' => 1, 'paginas' => 1, 'por_pagina' => OfertasCriadas::POR_PAGINA, 'blocos' => 3], $r['paginacao']);
        $this->assertSame(['combo', 'kit', 'combit'], array_column($r['grupos'], 'fase'), 'a ordem dos grupos; as ofertas simples não entram');
        $this->assertSame(['Combos', 'Kits', 'Combits'], array_column($r['grupos'], 'rotulo'));

        $doCombo = $r['grupos'][0]['itens'][0];
        $this->assertSame([$combo->id, 'NINA-CB2', 'Combo 2 Nina', 'combo'], [$doCombo['id'], $doCombo['sku'], $doCombo['nome'], $doCombo['fase']]);
        $this->assertSame([['id' => $nina->id, 'sku' => 'NINA', 'nome' => 'Produto NINA', 'quantidade' => 2, 'foto' => null]], $doCombo['composicao'], 'sem galeria e sem anúncio: sem foto');
        // O envio é o da Precificação: as duas caixas somadas passam do limite e viram ME1.
        $this->assertSame(LogisticaProduto::ME1, $doCombo['envio']['chave']);
        $this->assertSame(['c' => 53.0, 'l' => 60.0, 'a' => 160.0, 'peso' => 16.0], $doCombo['envio']['pacote']);
        $this->assertSame('sem_frete', $doCombo['pendencia'], 'fora do envio padrão não há frete a sugerir');

        $doKit = $r['grupos'][1]['itens'][0];
        $this->assertSame($kit->id, $doKit['id']);
        $this->assertSame(['NINA', 'MESA'], array_column($doKit['composicao'], 'sku'));
        $this->assertSame('sem_custo', $doKit['pendencia'], 'a mesa não tem custo: o do kit não fecha');
        $this->assertSame(['MESA'], $doKit['faltam_custo'], 'diz QUAL produto está sem custo — é no cadastro dele que se resolve');
        $this->assertSame([], $doCombo['faltam_custo']);
        $this->assertFalse($doKit['sem_calculo']);
        $this->assertArrayNotHasKey('_busca', $doKit, 'o texto interno da busca não vai para a tela');
    }

    /**
     * 16/10/2026 — "deveria ter filtros, modo lista também". Tipo, situação do preço, envio e busca; cada filtro
     * conta as suas opções com os OUTROS aplicados. No cenário: o combo está sem frete (ME1); kit e combit, sem
     * custo (a mesa não tem).
     */
    public function test_filtros_e_contagens_de_cada_opcao_com_os_outros_filtros_aplicados(): void
    {
        [$empresa, , , $combo, $kit, $combit] = $this->cenario();
        $svc = app(OfertasCriadas::class);
        $ids = fn (array $r) => array_merge(...array_map(fn ($g) => array_column($g['itens'], 'id'), $r['grupos'] ?: [['itens' => []]]));

        $tudo = $svc->listar($empresa);
        $this->assertSame(['todas' => 3, 'combo' => 1, 'kit' => 1, 'combit' => 1], $tudo['contagens']['fase']);
        $this->assertSame(['todas' => 3, 'com_preco' => 0, 'pendente' => 3, 'sem_custo' => 2, 'sem_frete' => 1, 'impossivel' => 0], $tudo['contagens']['situacao']);
        $this->assertSame(3, $tudo['contagens']['envio']['todas']);

        $this->assertSame([$kit->id], $ids($svc->listar($empresa, ['fase' => 'kit'])));
        $this->assertSame([$kit->id, $combit->id], $ids($svc->listar($empresa, ['situacao' => 'sem_custo'])), 'kit e combit, na ordem dos tipos');
        $this->assertSame([$combo->id, $kit->id, $combit->id], $ids($svc->listar($empresa, ['situacao' => 'pendente'])));
        $this->assertSame([], $ids($svc->listar($empresa, ['situacao' => 'com_preco'])));
        $this->assertSame([$combo->id], $ids($svc->listar($empresa, ['envio' => LogisticaProduto::ME1, 'fase' => 'combo'])));

        // Com o tipo Kit ligado, a situação conta só o kit; o tipo continua contando todos (é ele o filtro).
        $soKit = $svc->listar($empresa, ['fase' => 'kit']);
        $this->assertSame(1, $soKit['filtrado']);
        $this->assertSame(['todas' => 1, 'com_preco' => 0, 'pendente' => 1, 'sem_custo' => 1, 'sem_frete' => 0, 'impossivel' => 0], $soKit['contagens']['situacao']);
        $this->assertSame(['todas' => 3, 'combo' => 1, 'kit' => 1, 'combit' => 1], $soKit['contagens']['fase']);
        $semCusto = $svc->listar($empresa, ['situacao' => 'sem_custo']);
        $this->assertSame(['todas' => 2, 'combo' => 0, 'kit' => 1, 'combit' => 1], $semCusto['contagens']['fase'], 'o tipo conta com a situação aplicada');

        // Valor fora da lista não filtra (nem quebra).
        $this->assertSame(3, $svc->listar($empresa, ['fase' => 'xyz', 'situacao' => 'tudo', 'envio' => 'drone'])['filtrado']);
    }

    public function test_busca_acha_pelo_item_ou_por_um_produto_dele_sem_ligar_para_acento_ou_maiuscula(): void
    {
        [$empresa, , , $combo, $kit, $combit] = $this->cenario();
        $svc = app(OfertasCriadas::class);
        $ids = fn (string $q) => array_merge(...array_map(fn ($g) => array_column($g['itens'], 'id'), $svc->listar($empresa, ['q' => $q])['grupos'] ?: [['itens' => []]]));

        $this->assertSame([$kit->id, $combit->id], $ids('mesa'), 'pelo componente');
        $this->assertSame([$combo->id], $ids('nina-cb2'), 'pelo código do item');
        $this->assertSame([$kit->id], $ids('KIT NINA'), 'pelo nome, sem ligar para maiúscula');
        $this->assertSame([$combo->id, $kit->id, $combit->id], $ids('  '), 'só espaço não filtra');
        $this->assertSame([], $ids('Luminária'));

        // Acento: "Cômoda" no nome é achada por "comoda".
        $combo->update(['nome' => 'Combo 2 Nina Cômoda']);
        $this->assertSame([$combo->id], $ids('comoda'));
    }

    public function test_ordem_dentro_de_cada_tipo_e_paginas_de_60_sem_teto(): void
    {
        [$empresa, $nina, , $combo, $kit, $combit] = $this->cenario();
        $ofertas = app(EstruturaOfertaService::class);
        $ator = $this->atorCliente($empresa);
        // Mais 60 combos (Combo 3 a Combo 62 da Nina): 61 combos + 1 kit + 1 combit = 63 criadas, 2 páginas.
        $novos = [];
        foreach (range(3, 62) as $n) {
            [$novos[]] = $ofertas->criar($empresa, ['sku' => "NINA-CB{$n}", 'fase' => 'combo', 'nome' => "Combo {$n} Nina",
                'componentes' => [['id' => $nina->id, 'quantidade' => $n]]], $ator);
        }
        $svc = app(OfertasCriadas::class);

        $p1 = $svc->listar($empresa);
        $this->assertSame(63, $p1['total']);
        $this->assertSame(['pagina' => 1, 'paginas' => 2, 'por_pagina' => 60, 'blocos' => 63], $p1['paginacao']);
        $this->assertSame(['combo'], array_column($p1['grupos'], 'fase'), 'os tipos ficam contíguos: os combos vêm antes');
        $this->assertSame(end($novos)->id, $p1['grupos'][0]['itens'][0]['id'], 'mais recente primeiro');

        // Antes (teto de 300 mais recentes) os Kits e Combits sumiam atrás dos Combos; agora estão na página 2.
        $p2 = $svc->listar($empresa, [], 2);
        $this->assertSame(['combo', 'kit', 'combit'], array_column($p2['grupos'], 'fase'));
        $this->assertSame([$combo->id], array_column($p2['grupos'][0]['itens'], 'id'), 'o combo mais antigo fecha a lista de combos');
        $this->assertSame(2, $svc->listar($empresa, [], 99)['paginacao']['pagina'], 'página além da última cai na última');

        $porNome = $svc->listar($empresa, ['ordem' => 'nome', 'fase' => 'combo']);
        $this->assertSame(['Combo 2 Nina', 'Combo 3 Nina', 'Combo 4 Nina'], array_slice(array_column($porNome['grupos'][0]['itens'], 'nome'), 0, 3), 'ordem natural: 2, 3, 4… e não 10, 11');

        $pendentesPrimeiro = $svc->listar($empresa, ['ordem' => 'pendentes']);
        $this->assertSame(63, $pendentesPrimeiro['filtrado']);
    }

    /**
     * 16/10/2026 — "não dá para saber quais são os produtos, quais foram os kits… visualmente pela foto". Cada
     * componente leva a foto: a capa da galeria (ordem 0, como na lista de Produtos) e, sem galeria, a miniatura
     * do anúncio ATIVO no acervo. Tudo do banco: nenhuma chamada ao Mercado Livre (o Http::fake do setUp estoura).
     */
    public function test_cada_componente_leva_a_foto_capa_da_galeria_ou_miniatura_do_acervo(): void
    {
        [$empresa, $nina, $mesa] = $this->cenario();
        $variacao = EstruturaProdutoVariacao::findOrFail($nina->variacao_id);
        $imagem = fn (int $ordem) => EstruturaProdutoVariacaoImagem::create([
            'company_id' => $empresa->id, 'produto_id' => $variacao->produto_id, 'variacao_id' => $variacao->id,
            'caminho' => "teste/{$ordem}.jpg", 'nome_original' => "{$ordem}.jpg", 'mime' => 'image/jpeg', 'tamanho' => 10, 'ordem' => $ordem,
        ]);
        $imagem(1);                 // gravada primeiro, mas não é a capa
        $capa = $imagem(0);

        // A mesa não tem galeria: vale a miniatura do anúncio ativo; a do inativo, não.
        EstruturaAnuncio::create(['oferta_id' => $mesa->id, 'tipo' => 'classico', 'status' => 'inativo', 'codigo_mlb' => 'MLB9', 'titulo' => 'x']);
        EstruturaAnuncio::create(['oferta_id' => $mesa->id, 'tipo' => 'premium', 'status' => 'ativo', 'codigo_mlb' => 'MLB2', 'titulo' => 'x']);
        foreach (['MLB9' => 'velha', 'MLB2' => 'D_2-I'] as $mlb => $arquivo) {
            MlAcervoItem::create(['company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => 'Mesa', 'listing_type_id' => 'gold_special',
                'status' => 'active', 'thumbnail' => "http://http2.mlstatic.com/{$arquivo}.jpg"]);
        }

        $r = app(OfertasCriadas::class)->listar($empresa);
        $urlDaCapa = VariacaoImagensService::urlMiniatura($variacao->id, $capa->id);

        $this->assertSame([$urlDaCapa], array_column($r['grupos'][0]['itens'][0]['composicao'], 'foto'), 'Combo: a capa da galeria');
        $this->assertSame([$urlDaCapa, 'https://http2.mlstatic.com/D_2-I.jpg'], array_column($r['grupos'][1]['itens'][0]['composicao'], 'foto'),
            'Kit: a capa da poltrona e, para a mesa sem galeria, a miniatura do anúncio ativo, já em https');
        $this->assertSame([$urlDaCapa, 'https://http2.mlstatic.com/D_2-I.jpg'], array_column($r['grupos'][2]['itens'][0]['composicao'], 'foto'), 'Combit: as mesmas');
    }

    /**
     * 21/10/2026 — "em Criadas fica muito oculto, então parece que não tem nada": sem abas, as criadas vêm sempre,
     * embaixo das sugestões, com tipo, busca e página próprios (`criadas_*`), que não mexem nos das sugestões.
     */
    public function test_a_pagina_traz_sempre_as_criadas_com_filtros_proprios(): void
    {
        [$empresa] = $this->cenario();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura.sugestoes'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaSugestoes', false)
                ->where('sugestoes.aba', 'sugestoes')
                ->has('sugestoes.contagens.sugestoes')
                ->has('sugestoes.previa')
                ->where('criadas.total', 3)
                ->where('criadas.grupos.0.fase', 'combo')
                ->where('criadas.grupos.0.itens.0.sku', 'NINA-CB2')
                ->where('criadas.grupos.2.itens.0.composicao.0.quantidade', 4)
                ->where('filtros_criadas', ['fase' => null, 'situacao' => null, 'envio' => null, 'ordem' => 'recentes', 'q' => '']));

        // Os filtros das criadas pela URL, com prefixo; o que não está na lista vira "sem filtro". Os das sugestões
        // (`fase`, `q`) não mexem nas criadas, e os das criadas não mexem nas sugestões.
        $sessao->get(route('portal.auth.estrutura.sugestoes', ['criadas_fase' => 'kit', 'situacao' => 'sem_custo', 'envio' => 'drone',
            'ordem' => 'nome', 'criadas_q' => 'nina', 'fase' => 'combo', 'q' => 'zzz']))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros_criadas', ['fase' => 'kit', 'situacao' => 'sem_custo', 'envio' => null, 'ordem' => 'nome', 'q' => 'nina'])
                ->where('criadas.filtrado', 1)
                ->where('criadas.grupos.0.itens.0.sku', 'NINA+MESA')
                ->where('filtros.fase', 'combo')->where('filtros.q', 'zzz'));

        // A recarga parcial de uma lista não traz a outra (as duas são preguiçosas).
        $sessao->get(route('portal.auth.estrutura.sugestoes'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->reloadOnly(['criadas', 'filtros_criadas'], fn ($r) => $r->missing('sugestoes')->where('criadas.total', 3))
                ->reloadOnly(['sugestoes', 'filtros'], fn ($r) => $r->missing('criadas')->has('sugestoes.previa')));

        // `?aba=criadas` de link antigo só abre a tela.
        $sessao->get(route('portal.auth.estrutura.sugestoes', ['aba' => 'criadas']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('filtros.aba', 'sugestoes')->where('criadas.total', 3));

        // A página não consulta o Mercado Livre (o que sai é só o aviso interno do layout, de outro serviço).
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'mercadolibre') || str_contains($req->url(), 'mercadolivre'));
    }

    /** 21/10/2026 — o cartão da sugestão mostra a foto de cada produto pela mesma régua das criadas. */
    public function test_a_foto_da_variacao_no_cartao_da_sugestao_segue_a_regua_das_criadas(): void
    {
        [$empresa, $nina, $mesa] = $this->cenario();
        $variacao = EstruturaProdutoVariacao::findOrFail($nina->variacao_id);
        $imagem = fn (int $ordem) => EstruturaProdutoVariacaoImagem::create([
            'company_id' => $empresa->id, 'produto_id' => $variacao->produto_id, 'variacao_id' => $variacao->id,
            'caminho' => "teste/{$ordem}.jpg", 'nome_original' => "{$ordem}.jpg", 'mime' => 'image/jpeg', 'tamanho' => 10, 'ordem' => $ordem,
        ]);
        $imagem(1);
        $capa = $imagem(0);
        // A mesa não tem galeria: vale a miniatura do anúncio ativo da oferta simples dela; a do inativo, não.
        EstruturaAnuncio::create(['oferta_id' => $mesa->id, 'tipo' => 'classico', 'status' => 'inativo', 'codigo_mlb' => 'MLB9', 'titulo' => 'x']);
        EstruturaAnuncio::create(['oferta_id' => $mesa->id, 'tipo' => 'premium', 'status' => 'ativo', 'codigo_mlb' => 'MLB2', 'titulo' => 'x']);
        foreach (['MLB9' => 'velha', 'MLB2' => 'D_2-I'] as $mlb => $arquivo) {
            MlAcervoItem::create(['company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => 'Mesa', 'listing_type_id' => 'gold_special',
                'status' => 'active', 'thumbnail' => "http://http2.mlstatic.com/{$arquivo}.jpg"]);
        }

        $fotos = app(FotosDasVariacoes::class)->capas($empresa, [$nina->variacao_id, $mesa->variacao_id, $nina->variacao_id, 999999]);

        $this->assertSame([
            (int) $nina->variacao_id => VariacaoImagensService::urlMiniatura($variacao->id, $capa->id),
            (int) $mesa->variacao_id => 'https://http2.mlstatic.com/D_2-I.jpg',
        ], $fotos, 'a capa da galeria; sem galeria, a miniatura do anúncio ativo, já em https; quem não tem nada fica de fora');
        $this->assertSame([], app(FotosDasVariacoes::class)->capas($empresa, []));
        $this->assertSame([], app(FotosDasVariacoes::class)->capas($this->empresaDoGabarito(), [$nina->variacao_id]), 'a galeria é da empresa');
    }

    public function test_empresa_sem_nada_criado_e_escopo_por_empresa(): void
    {
        [$empresa] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $this->simples($outra, 'SO', ['c' => 10, 'l' => 10, 'a' => 10, 'kg' => 1], '5');

        $vazia = app(OfertasCriadas::class)->listar($outra);
        $this->assertSame([0, 0, []], [$vazia['total'], $vazia['filtrado'], $vazia['grupos']], 'só oferta simples: nada criado');
        $this->assertSame(0, app(OfertasCriadas::class)->contar($outra));
        $this->assertSame(3, app(OfertasCriadas::class)->contar($empresa));
    }
}
