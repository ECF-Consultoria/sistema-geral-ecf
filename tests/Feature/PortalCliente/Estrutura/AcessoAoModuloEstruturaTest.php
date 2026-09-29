<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Quem vê o quê. A empresa vem SEMPRE da sessão; um id de outra empresa
 * responde 404, igual a um id que não existe.
 */
class AcessoAoModuloEstruturaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    public function test_o_modulo_aparece_no_menu_de_toda_empresa_e_a_pagina_abre_vazia(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaMapeamento')
                ->where('estrutura.painel.ofertas', 0)
                ->where('estrutura.blocos', [])
                ->where('modulos', fn ($modulos) => collect($modulos)->contains(fn ($m) => $m['chave'] === 'estrutura' && $m['ativo']))
            );
    }

    /**
     * Os submódulos (29/09), na ordem de quem começa do zero. A entrada do
     * módulo abre a Lista SKUs; os links antigos (`?abrir=` da agenda e da
     * Jardinagem) vão para o Mapeamento, com a query intacta.
     */
    public function test_a_entrada_abre_a_lista_e_os_links_antigos_vao_para_o_mapeamento(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.lista'));
        $sessao->get(route('portal.auth.estrutura', ['q' => 'CAD-01', 'abrir' => 7, 'metricas' => 1]))
            ->assertRedirect(route('portal.auth.estrutura.mapeamento', ['q' => 'CAD-01', 'abrir' => 7, 'metricas' => 1]));

        $sessao->get(route('portal.auth.estrutura.lista'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaLista')
                ->where('modulos', function ($modulos) {
                    $estrutura = collect($modulos)->firstWhere('chave', 'estrutura');

                    return $estrutura['ativo']
                        && collect($estrutura['submodulos'])->pluck('chave')->all() === ['lista', 'precificacao', 'anuncios', 'planejamento', 'mapeamento', 'anunciar']
                        && collect($estrutura['submodulos'])->firstWhere('chave', 'lista')['ativo']
                        // 29/09: o Anunciar deixou de ser "em breve" (ADR PORTAL-03).
                        && collect($estrutura['submodulos'])->every(fn ($s) => ! $s['em_breve'] && $s['url'] !== null)
                        && collect($estrutura['submodulos'])->firstWhere('chave', 'anunciar')['url'] === route('portal.auth.estrutura.anunciar');
                })
            );

        // Cada submódulo marca a si mesmo como ativo.
        foreach (['anuncios' => 'Portal/EstruturaAnuncios', 'agenda' => 'Portal/EstruturaAgenda', 'mapeamento' => 'Portal/EstruturaMapeamento', 'anunciar' => 'Portal/EstruturaAnunciar'] as $rota => $componente) {
            $sub = $rota === 'agenda' ? 'planejamento' : $rota;
            $sessao->get(route("portal.auth.estrutura.{$rota}"))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component($componente)
                    ->where('modulos', fn ($m) => collect(collect($m)->firstWhere('chave', 'estrutura')['submodulos'])->firstWhere('ativo', true)['chave'] === $sub)
                );
        }

        // Os outros módulos não ganham submódulo.
        $sessao->get(route('portal.auth.estrutura.lista'))
            ->assertInertia(fn ($page) => $page
                ->where('modulos', fn ($m) => collect($m)->where('chave', '!=', 'estrutura')->every(fn ($x) => $x['submodulos'] === []))
            );
    }

    /**
     * Anúncio sem código MLB é PLANEJADO (decisão de 29/09): está na aba
     * Anúncios, mas não conta como publicado. Com o código, o mesmo registro
     * vira publicado — o "Concluir" da agenda não cria um segundo.
     */
    public function test_anuncio_sem_mlb_e_planejado_e_o_mlb_o_completa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        // O título do Clássico da CB3, antes de publicar.
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $ofertas['CAD-01-CB3']->id),
            ['tipo' => 'classico', 'titulo' => 'Kit 3 Cadeiras 01 Madeira'])->assertSessionHasNoErrors();

        $sessao->get(route('portal.auth.estrutura.anuncios'))
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaAnuncios')
                // O gabarito continua: 4 publicados de 18. O planejado não soma.
                ->where('estrutura.painel.publicados', 4)
                ->where('estrutura.anuncios_resumo', ['publicados' => 4, 'planejados' => 1, 'sem_titulo' => 13])
            );

        // Publicou: concluir pela agenda com o MLB completa o planejado.
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $ofertas['CAD-01-CB3']->id),
            ['tipo' => 'classico', 'codigo_mlb' => 'MLB0000000077', 'via_agenda' => true])->assertSessionHasNoErrors();

        $anuncios = $ofertas['CAD-01-CB3']->anuncios()->get();
        $this->assertCount(1, $anuncios);
        $this->assertSame('MLB0000000077', $anuncios[0]->codigo_mlb);
        $this->assertSame('Kit 3 Cadeiras 01 Madeira', $anuncios[0]->titulo);

        $sessao->get(route('portal.auth.estrutura.anuncios'))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.painel.publicados', 5)
                ->where('estrutura.anuncios_resumo', ['publicados' => 5, 'planejados' => 0, 'sem_titulo' => 13])
            );
    }

    /**
     * Lista SKUs: o produto simples nasce com os combos marcados na pergunta
     * "dá combo? em quantas unidades?" — o CAD-01 e os CB2…CB6 da planilha
     * numa ação só, no padrão de SKU da aula.
     */
    public function test_produto_nasce_com_os_combos_da_pergunta(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.ofertas.criar'), [
                'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira 01', 'logistica' => 'mercado_envios',
                'combos' => [2, 3, 4, 5, 6],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Produto CAD-01 criado com 5 combo(s): CAD-01-CB2, CAD-01-CB3, CAD-01-CB4, CAD-01-CB5, CAD-01-CB6.');

        $ofertas = EstruturaOferta::where('company_id', $empresa->id)->orderBy('id')->get();
        $this->assertSame(['CAD-01', 'CAD-01-CB2', 'CAD-01-CB3', 'CAD-01-CB4', 'CAD-01-CB5', 'CAD-01-CB6'], $ofertas->pluck('sku')->all());
        $this->assertSame(['simples', 'combo', 'combo', 'combo', 'combo', 'combo'], $ofertas->pluck('fase')->all());
        $this->assertSame('Combo 4 Cadeira 01', $ofertas[3]->nome);
        $this->assertSame('mercado_envios', $ofertas[3]->logistica);
        $this->assertSame(4, $ofertas[3]->componentes()->first()->quantidade);

        // Quantidade fora da faixa: nada é criado — nem o produto.
        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.ofertas.criar'), ['sku' => 'MSA-MR', 'fase' => 'simples', 'combos' => [1]])
            ->assertSessionHasErrors('combos.0');
        $this->assertSame(0, EstruturaOferta::where('company_id', $empresa->id)->where('sku', 'MSA-MR')->count());
    }

    public function test_a_agenda_abre_com_as_quatro_secoes(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.agenda'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaAgenda')
                ->has('agenda.secoes.atrasadas')
                ->has('agenda.secoes.hoje')
                ->has('agenda.secoes.proximas')
                ->has('agenda.secoes.concluidas')
                ->where('vocabulario.dias_ate_jardinagem', 7)
            );
    }

    public function test_sem_sessao_vai_para_a_entrada(): void
    {
        $this->get(route('portal.auth.estrutura.mapeamento'))->assertRedirect();
    }

    public function test_a_pagina_entrega_o_gabarito_em_blocos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaMapeamento')
                ->where('estrutura.painel.publicados', 4)
                ->where('estrutura.contadores', ['todas' => 9, 'publicar' => 6, 'falta' => 2, 'completas' => 1])
                // CAD-01 (+5 combos), MSA-MR, kit, combit.
                ->count('estrutura.blocos', 4)
                ->count('estrutura.blocos.0.ofertas', 6)
                ->where('estrutura.blocos.0.tambem_em.0.sku', 'MSA-MR+CAD-01-KIT')
            );
    }

    /** Filtro e busca no SERVIDOR; o painel continua sendo do conjunto inteiro. */
    public function test_filtro_e_busca_recortam_os_blocos_mas_nao_o_painel(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento', ['situacao' => 'falta']))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.painel.ofertas', 9)
                ->count('estrutura.blocos', 2) // CB2 (bloco CAD-01) e MSA-MR
            );

        // A busca acha pelo MLB — "onde está o MLB tal?" leva à oferta.
        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento', ['q' => 'mlb0000000004']))
            ->assertInertia(fn ($page) => $page
                ->count('estrutura.blocos', 1)
                ->where('estrutura.blocos.0.ofertas.0.sku', 'MSA-MR')
            );
    }

    /**
     * O bloco nasce recolhido; o cabeçalho vive do resumo. O resumo é do bloco
     * INTEIRO — filtrar "Falta um lado" esconde CB3..CB6, mas o cabeçalho
     * continua dizendo "4 a publicar".
     */
    public function test_resumo_do_bloco_e_do_conjunto_inteiro_mesmo_filtrado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        app(\App\Services\Portal\Estrutura\EstruturaAgendaService::class)
            ->agendar($ofertas['CAD-01-CB3'], '2026-10-01', 'publicacao', $ator);

        $esperado = ['total' => 5, 'ok' => 0, 'falta' => 1, 'publicar' => 4, 'sem_agenda' => 4];

        foreach (['todas', 'falta'] as $filtro) {
            $this->withoutVite()->entrarNoPortal($empresa)
                ->get(route('portal.auth.estrutura.mapeamento', ['situacao' => $filtro]))
                ->assertInertia(fn ($page) => $page
                    ->where('estrutura.blocos.0.principal.sku', 'CAD-01')
                    ->where('estrutura.blocos.0.principal.situacao', 'ok')
                    ->where('estrutura.blocos.0.resumo_combos', $esperado)
                    ->where('estrutura.blocos.0.uso', ['kits' => 1, 'combits' => 1])
                    ->where('estrutura.blocos.0.quantidades_combo', [2, 3, 4, 5, 6])
                    ->where('estrutura.resumo_kits', ['total' => 2, 'ok' => 0, 'falta' => 0, 'publicar' => 2, 'sem_agenda' => 2])
                );
        }
    }

    /**
     * O produto FECHADO da tela nova: ofertas do bloco inteiro (produto +
     * combos), quantas OK e quantas pendentes — e, com uma pendência só, qual
     * é. A foto vem do acervo do ML, em https. A coluna "Agenda" traz as
     * contagens e as próximas tarefas não feitas, atrasadas primeiro.
     */
    public function test_produto_fechado_foto_e_coluna_da_agenda(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        \App\Models\MlAcervoItem::create(['company_id' => $empresa->id, 'ml_item_id' => 'MLB0000000001', 'title' => 'Cadeira',
            'listing_type_id' => 'gold_special', 'status' => 'active', 'thumbnail' => 'http://http2.mlstatic.com/D_1-I.jpg']);

        $agenda = app(\App\Services\Portal\Estrutura\EstruturaAgendaService::class);
        $agenda->agendar($ofertas['CAD-01-CB3'], today()->addDay()->format('Y-m-d'), 'publicacao', $ator);
        \App\Models\EstruturaAgendaItem::query()->update(['data' => today()->subDay()->format('Y-m-d')]);   // venceu ontem
        $agenda->agendar($ofertas['CAD-01-CB4'], today()->format('Y-m-d'), 'publicacao', $ator);
        $agenda->agendar($ofertas['CAD-01'], today()->addDays(3)->format('Y-m-d'), 'jardinagem', $ator);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.blocos.0.principal.sku', 'CAD-01')
                ->where('estrutura.blocos.0.resumo_bloco', ['total' => 6, 'ok' => 1, 'falta' => 1, 'publicar' => 4, 'sem_agenda' => 3, 'pendentes' => 5, 'unica_situacao' => null])
                ->where('estrutura.blocos.0.combos', 5)
                ->where('estrutura.blocos.0.foto', 'https://http2.mlstatic.com/D_1-I.jpg')
                ->where('estrutura.blocos.1.principal.sku', 'MSA-MR')
                ->where('estrutura.blocos.1.resumo_bloco.unica_situacao', 'falta_classico')
                ->where('estrutura.blocos.1.foto', null)
                ->where('estrutura.agenda.contagem', ['atrasadas' => 1, 'hoje' => 1, 'jardinagem' => 1])
                ->where('estrutura.agenda.itens', fn ($itens) => collect($itens)->map(fn ($i) => $i['secao'].':'.$i['oferta']['sku'])->all()
                    === ['atrasadas:CAD-01-CB3', 'hoje:CAD-01-CB4', 'proximas:CAD-01'])
            );

        // O seletor do kit mostra a mesma capa: escolher entre SKUs parecidos é pela foto.
        $opcoes = collect(app(\App\Services\Portal\Estrutura\EstruturaVisaoService::class)->opcoesDeOfertas($empresa))->keyBy('sku');
        $this->assertSame('https://http2.mlstatic.com/D_1-I.jpg', $opcoes['CAD-01']['foto']);
        $this->assertNull($opcoes['MSA-MR']['foto']);
    }

    /**
     * Estoque é FAIXA: os anúncios do mesmo SKU no ML podem ter estoques
     * diferentes (medido na #131). A oferta leva min/max dos que contam
     * (Inativo não conta); cada anúncio, o seu.
     */
    public function test_estoque_da_oferta_e_faixa_e_cada_anuncio_tem_o_seu(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        app(\App\Services\Portal\Estrutura\EstruturaAnuncioService::class)->cadastrar($ofertas['CAD-01'],
            ['tipo' => 'premium', 'codigo_mlb' => 'MLB0000000009', 'status' => 'inativo', 'catalogo' => false], $ator);
        foreach (['MLB0000000001' => 40, 'MLB0000000002' => 120, 'MLB0000000003' => 7, 'MLB0000000009' => 0] as $mlb => $qtd) {
            \App\Models\MlAcervoItem::create(['company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => 'x',
                'listing_type_id' => 'gold_special', 'status' => 'active', 'available_quantity' => $qtd,
                // O Premium da CAD-01 está no Full: o estoque dele está no galpão do ML.
                'shipping' => ['mode' => 'me2', 'logistic_type' => $mlb === 'MLB0000000002' ? 'fulfillment' : 'cross_docking']]);
        }

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.blocos.0.principal.sku', 'CAD-01')
                // 40 e 120 contam; o Inativo (0) não derruba o mínimo.
                ->where('estrutura.blocos.0.estoque', ['min' => 40, 'max' => 120])
                ->where('estrutura.blocos.0.ofertas.0.estoque', ['min' => 40, 'max' => 120])
                ->where('estrutura.blocos.0.ofertas.0.anuncios', fn ($as) => collect($as)->pluck('estoque', 'codigo_mlb')->all()
                    === ['MLB0000000001' => 40, 'MLB0000000002' => 120, 'MLB0000000009' => 0])
                ->where('estrutura.blocos.0.ofertas.0.anuncios', fn ($as) => collect($as)->pluck('estoque_full', 'codigo_mlb')->all()
                    === ['MLB0000000001' => false, 'MLB0000000002' => true, 'MLB0000000009' => false])
                ->where('estrutura.blocos.0.ofertas.1.sku', 'CAD-01-CB2')
                ->where('estrutura.blocos.0.ofertas.1.estoque', ['min' => 7, 'max' => 7])
                ->where('estrutura.blocos.0.ofertas.2.estoque', null)   // CB3 não tem anúncio
            );

        // O seletor do kit mostra o mesmo estoque — é com ele que se decide o kit.
        $opcoes = collect(app(\App\Services\Portal\Estrutura\EstruturaVisaoService::class)->opcoesDeOfertas($empresa))->keyBy('sku');
        $this->assertSame(['min' => 40, 'max' => 120], $opcoes['CAD-01']['estoque']);
        $this->assertNull($opcoes['MSA-MR']['estoque']);
    }

    /**
     * O trabalho na ordem das vendas: a lista e a proposta da agenda começam
     * pelo produto que mais vende. Cada anúncio traz preço, vendas, fotos e os
     * alertas do acervo; a oferta avisa Premium mais barato que o Clássico.
     */
    public function test_vendas_ordenam_a_lista_e_a_proposta_e_precos_avisam_par_invertido(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $acervo = fn ($mlb, $vendas, $preco, $fotos = 6, $motivos = []) => \App\Models\MlAcervoItem::create([
            'company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => 'x', 'listing_type_id' => 'gold_special', 'status' => 'active',
            'sold_quantity' => $vendas, 'price' => $preco, 'fotos_count' => $fotos, 'motivos' => $motivos]);
        $acervo('MLB0000000001', 10, 199.90);                                  // CAD-01 Clássico
        $acervo('MLB0000000002', 5, 149.90, 2, ['foto_insuficiente', 'pausado']); // CAD-01 Premium: mais barato!
        $acervo('MLB0000000004', 500, 899.00);                                 // MSA-MR Premium

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))
            ->assertInertia(fn ($page) => $page
                // A Mesa vende 500: passa na frente da Cadeira (15).
                ->where('estrutura.blocos.0.principal.sku', 'MSA-MR')
                ->where('estrutura.blocos.0.vendas', 500)
                ->where('estrutura.blocos.1.principal.sku', 'CAD-01')
                ->where('estrutura.blocos.1.vendas', 15)
                ->where('estrutura.blocos.1.ofertas.0.vendas', 15)
                ->where('estrutura.blocos.1.ofertas.0.precos', ['classico' => 199.9, 'premium' => 149.9, 'invertido' => true])
                ->where('estrutura.blocos.1.ofertas.0.anuncios.1.ml', ['miniatura' => null, 'vendas' => 5, 'preco' => 149.9, 'fotos' => 2, 'alertas' => ['foto_insuficiente']])
                ->where('estrutura.blocos.0.ofertas.0.precos', ['classico' => null, 'premium' => 899, 'invertido' => false])   // no JSON, 899.0 vira 899
            );

        // A proposta da agenda segue a mesma ordem: a Mesa (falta Clássico) primeiro.
        $proposta = app(\App\Services\Portal\Estrutura\EstruturaAgendaService::class)->proposta($empresa);
        $this->assertSame('MSA-MR', $proposta[0]['sku']);
    }

    /**
     * A estação do produto: a família inteira (produto + combos, ou o kit e
     * seus componentes) numa resposta, independente do filtro da lista.
     */
    public function test_estacao_traz_a_familia_inteira(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $sessao = $this->entrarNoPortal($empresa);

        // Pela CB3 chega-se ao produto CAD-01 com os 5 combos, na ordem das unidades.
        $r = $sessao->getJson(route('portal.auth.estrutura.ofertas.estacao', $ofertas['CAD-01-CB3']->id))->assertOk()->json();
        $this->assertSame('CAD-01', $r['principal']['sku']);
        $this->assertFalse($r['kit']);
        $this->assertSame(['CAD-01', 'CAD-01-CB2', 'CAD-01-CB3', 'CAD-01-CB4', 'CAD-01-CB5', 'CAD-01-CB6'], array_column($r['ofertas'], 'sku'));
        $this->assertSame([2, 3, 4, 5, 6], $r['quantidades_combo']);
        $this->assertEqualsCanonicalizing(['MSA-MR+CAD-01-KIT', 'MSA-MR+CAD-01-CBT4'], array_column($r['tambem_em'], 'sku'));
        $this->assertSame('ok', $r['ofertas'][0]['situacao']);
        $this->assertCount(2, $r['ofertas'][0]['anuncios']);

        // O kit: ele mesmo, com os componentes e a situação de cada um.
        $k = $sessao->getJson(route('portal.auth.estrutura.ofertas.estacao', $ofertas['MSA-MR+CAD-01-KIT']->id))->json();
        $this->assertTrue($k['kit']);
        $this->assertSame(['MSA-MR+CAD-01-KIT'], array_column($k['ofertas'], 'sku'));
        $this->assertSame([['MSA-MR', 1, 'falta_classico'], ['CAD-01', 1, 'ok']], array_map(fn ($c) => [$c['sku'], $c['quantidade'], $c['situacao']], $k['componentes']));

        // Oferta de outra empresa: 404, como toda rota do módulo.
        $outra = $this->empresaDoGabarito();
        $alheia = $this->listaDoGabarito($outra, $this->atorCliente($outra))['CAD-01'];
        $sessao->getJson(route('portal.auth.estrutura.ofertas.estacao', $alheia->id))->assertNotFound();
    }

    /**
     * A faixa "próximo passo": UMA coisa a fazer, em ordem de urgência, sobre o
     * conjunto inteiro. Percorre os estados na ordem em que um cliente passa
     * por eles.
     */
    public function test_proximo_passo_segue_a_ordem_de_urgencia(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $agenda = app(\App\Services\Portal\Estrutura\EstruturaAgendaService::class);
        $passo = fn () => $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))
            ->viewData('page')['props']['estrutura']['proximo_passo'];

        // Nada cadastrado.
        $this->assertSame('cadastrar', $passo()['tipo']);

        // Gabarito sem agenda: 8 ofertas com buraco e nenhuma data.
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $this->assertSame(['tipo' => 'agendar', 'quantidade' => 8], $passo());

        // Algo para hoje passa na frente de tudo.
        $agenda->agendar($ofertas['CAD-01-CB3'], today()->format('Y-m-d'), 'publicacao', $ator);
        $p = $passo();
        $this->assertSame('hoje', $p['tipo']);
        $this->assertSame('CAD-01-CB3', $p['primeira']['sku']);

        // Tudo com data e nada para hoje: sobram os produtos sem variação?
        // No gabarito, os dois simples entram em kit — então está em dia.
        \App\Models\EstruturaAgendaItem::query()->delete();
        $agenda->agendarProposta($empresa, array_map(fn ($o) => $o->id, array_values($ofertas)), $ator);
        \App\Models\EstruturaAgendaItem::query()->update(['data' => today()->addDays(3)->format('Y-m-d')]);
        $this->assertSame('em_dia', $passo()['tipo']);

        // Um produto novo, sem combo nem kit, e já com data: vira "variacoes".
        [$novo] = app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class)
            ->criar($empresa, ['sku' => 'NOVO-1', 'fase' => 'simples'], $ator);
        $agenda->agendar($novo, today()->addDays(9)->format('Y-m-d'), 'publicacao', $ator);
        $this->assertSame(['tipo' => 'variacoes', 'quantidade' => 1], $passo());
    }

    public function test_pagina_com_mais_de_25_blocos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class);
        foreach (range(1, 30) as $i) {
            $svc->criar($empresa, ['sku' => "P{$i}", 'fase' => 'simples'], $ator);
        }

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento', ['pagina' => 2]))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.paginacao', ['pagina' => 2, 'paginas' => 2, 'blocos' => 30, 'por_pagina' => 25])
                ->count('estrutura.blocos', 5)
                ->where('estrutura.painel.ofertas', 30)
            );
    }

    public function test_id_de_outra_empresa_responde_404_em_toda_escrita(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $ator = $this->atorCliente($outra);
        $alheias = $this->listaDoGabarito($outra, $ator);
        $this->anunciosDoGabarito($alheias, $ator);
        $anuncio = $alheias['CAD-01']->anuncios()->first();
        $espera = EstruturaAnuncioEspera::create(['company_id' => $outra->id, 'sku_colado' => 'Z', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);
        $agenda = $alheias['CAD-01']->agenda()->create(['data' => '2026-09-30', 'acao' => 'jardinagem']);

        $sessao = $this->entrarNoPortal($minha);

        $sessao->put(route('portal.auth.estrutura.ofertas.atualizar', $alheias['CAD-01']->id), ['sku' => 'X', 'fase' => 'simples'])->assertNotFound();
        $sessao->delete(route('portal.auth.estrutura.ofertas.excluir', $alheias['CAD-01-CB6']->id))->assertNotFound();
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $alheias['CAD-01']->id), ['tipo' => 'classico'])->assertNotFound();
        $sessao->delete(route('portal.auth.estrutura.anuncios.excluir', $anuncio->id))->assertNotFound();
        $sessao->delete(route('portal.auth.estrutura.espera.descartar', $espera->id))->assertNotFound();
        $sessao->post(route('portal.auth.estrutura.agenda.criar'), ['oferta_id' => $alheias['CAD-01']->id, 'data' => '2026-10-01', 'acao' => 'publicacao'])->assertNotFound();
        $sessao->patch(route('portal.auth.estrutura.agenda.jardinagem', $agenda->id), ['feita' => true])->assertNotFound();

        // Vincular linha MINHA a oferta ALHEIA também não.
        $minhaLinha = EstruturaAnuncioEspera::create(['company_id' => $minha->id, 'sku_colado' => 'Z', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);
        $sessao->post(route('portal.auth.estrutura.espera.vincular', $minhaLinha->id), ['oferta_id' => $alheias['CAD-01']->id])->assertNotFound();

        $this->assertSame(9, EstruturaOferta::where('company_id', $outra->id)->count());
        $this->assertSame('CAD-01', $alheias['CAD-01']->fresh()->sku);
        $this->assertNull($agenda->fresh()->concluida_em);
    }

    /**
     * A allowlist tem de cobrir cada rota do módulo — `DominioLiberaTodoModuloTest`
     * já varre todas as `portal.auth.*`; este afirma que as do módulo EXISTEM
     * para aquela varredura (sem isto, um prefixo trocado a deixaria vazia e
     * verde, `portal-do-cliente.md` §3).
     */
    public function test_as_rotas_do_modulo_existem_e_estao_na_allowlist(): void
    {
        $permitido = (new \ReflectionClass(RestringeDominioDoPortal::class))->getConstant('PERMITIDO');

        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => Str::startsWith((string) $r->getName(), 'portal.auth.estrutura'));

        $this->assertGreaterThanOrEqual(18, $rotas->count());
        $this->assertNotContains('portal/*', $permitido);

        foreach ($rotas as $rota) {
            $this->assertTrue(
                collect($permitido)->contains(fn ($p) => Str::is($p, $rota->uri())),
                "{$rota->uri()} fora da allowlist"
            );
        }
    }

    /** Toda escrita registra de que lado veio — o causer não distingue. */
    public function test_escrita_registra_a_origem(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.ofertas.criar'), ['sku' => 'CAD-01', 'fase' => 'simples'])
            ->assertSessionHasNoErrors();

        $log = \Spatie\Activitylog\Models\Activity::where('log_name', 'portal')->latest('id')->first();
        $this->assertSame('cliente', $log->properties['origem']);
        $this->assertSame('oferta_criada', $log->properties['evento']);
    }
}
