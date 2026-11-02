<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\Schema\CategorySchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * A Trilha de produtos de uma empresa (1ª entrega, 30/10/2026): o serviço que monta produtos e ofertas nas 6
 * etapas só com o que já está no banco — nenhuma requisição, nenhum job, nenhuma escrita, consultas fixas.
 *
 * Os dados são montados pelos models, sem passar pelos serviços do Portal: o que importa aqui é como a trilha
 * LÊ o que existe. Os nomes evitam as palavras dos tipos de produto (mesa, cadeira…) para o gerador de sugestões
 * só sugerir o que o teste pede (Combo pelas quantidades do próprio produto).
 */
class TrilhaDaEmpresaTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
    }

    // ═══ Produto: a lista ═══════════════════════════════════════════════════

    public function test_produto_incompleto_fica_na_lista_com_as_faltas_na_ordem_e_com_os_rotulos(): void
    {
        $empresa = $this->empresa();
        $schema = $this->guardarSchema(self::schema(self::CADEIRA));
        $p = $this->produto($empresa, 'Vaso Alfa', 'VA-1', ['Azul', 'Verde'], [
            'titulo' => null, 'familia_id' => null, 'descricao' => '', 'categoria_ml_id' => self::CADEIRA,
        ], ambiente: false);
        [$azul, $verde] = $p['variacoes'];

        // Azul: sem custo, sem volume e com 1 foto; Verde: custo, 4 fotos e uma caixa que só vai por transportadora.
        $azul->update(['custo' => null]);
        $azul->volumes()->delete();
        EstruturaProdutoVariacaoImagem::where('variacao_id', $azul->id)->orderByDesc('id')->limit(2)->get()->each->delete();
        $verde->volumes()->update(['comprimento' => 200, 'largura' => 200, 'altura' => 200, 'peso' => 120]);
        $this->fotos($empresa, $verde, 1);

        // A ficha inteira, menos Marca e "É gamer".
        $this->preencherFicha($empresa, $p['produto'], $schema, ['cor'], fora: ['BRAND', 'IS_GAMER']);

        $produto = $this->produtoDaTrilha($this->trilha($empresa), $p['produto']->id);

        $this->assertSame('lista', $produto['etapa']);
        $this->assertSame(
            ['medidas', 'custo', 'título', 'família', 'ambiente', 'frete ME1', 'descrição', 'fotos (1 de 3)', 'ficha: marca', 'ficha: é gamer'],
            $produto['faltas'],
        );
        $this->assertSame('incompleta', $produto['ficha']);
        $this->assertSame(1, $produto['fotos_min']);
        $this->assertFalse($produto['tem_descricao']);
        $this->assertSame([110.0, 110.0], [$produto['custo_min'], $produto['custo_max']]);
        $this->assertNull($produto['embalagem'], 'a 1ª cor (Azul) não tem volume');
        $this->assertSame(['VA-1-1', 'VA-1-2'], $produto['skus']);
        $this->assertSame(2, $produto['variacoes']);

        // "Não se aplica" não vale em obrigatório; o valor vale.
        $this->atributo($empresa, $p['produto'], 'BRAND', null, FichaTecnicaDoProduto::NAO_SE_APLICA);
        $this->assertContains('ficha: marca', $this->produtoDaTrilha($this->trilha($empresa), $p['produto']->id)['faltas']);
        EstruturaProdutoAtributo::where('produto_id', $p['produto']->id)->where('atributo_id', 'BRAND')->update(['valor' => 'ACME', 'valor_id' => null]);
        $faltas = $this->produtoDaTrilha($this->trilha($empresa), $p['produto']->id)['faltas'];
        $this->assertNotContains('ficha: marca', $faltas);
        $this->assertContains('ficha: é gamer', $faltas);
    }

    public function test_os_dois_eixos_das_variacoes_saem_da_ficha(): void
    {
        $empresa = $this->empresa();
        // A Cor obrigatória na categoria: só o produto que NÃO varia por cor precisa informá-la na ficha.
        $schema = $this->guardarSchema(self::schema(self::CADEIRA, function (array $f) {
            foreach ($f['atributos'] as &$a) {
                if (($a['id'] ?? null) === 'COLOR') {
                    $a['tags']['required'] = true;
                }
            }
            unset($a);

            return $f;
        }));

        $porTamanho = $this->produto($empresa, 'Vaso Tamanhos', 'VT-1', ['P'], ['categoria_ml_id' => self::CADEIRA]);
        $porTamanho['variacoes'][0]->update(['eixo' => 'tamanho', 'valor' => 'P']);
        $tamanhoECor = $this->produto($empresa, 'Vaso Tamanho e Cor', 'VC-1', ['P'], ['categoria_ml_id' => self::CADEIRA]);
        // Cor no 2º eixo: o Funil olhava só o 1º e cobraria a Cor na ficha.
        $tamanhoECor['variacoes'][0]->update(['eixo' => 'tamanho', 'valor' => 'P', 'eixo_2' => 'cor', 'valor_2' => 'Azul']);
        foreach ([$porTamanho, $tamanhoECor] as $p) {
            $this->preencherFicha($empresa, $p['produto'], $schema, [], fora: ['COLOR']);
        }

        $trilha = $this->trilha($empresa);
        $this->assertSame(['ficha: cor'], $this->produtoDaTrilha($trilha, $porTamanho['produto']->id)['faltas']);
        $this->assertSame([], $this->produtoDaTrilha($trilha, $tamanhoECor['produto']->id)['faltas']);
        $this->assertSame('completa', $this->produtoDaTrilha($trilha, $tamanhoECor['produto']->id)['ficha']);
    }

    public function test_categoria_sem_definicao_guardada_fica_nao_conferida_sem_falta_de_ficha(): void
    {
        $empresa = $this->empresa();
        $naoGuardada = $this->produto($empresa, 'Vaso Sem Definição', 'VS-1', [null], ['categoria_ml_id' => 'MLB999001']);
        $semCategoria = $this->produto($empresa, 'Vaso Sem Categoria', 'VS-2', [null], ['categoria_ml_id' => null, 'categoria_ml_nome' => null]);
        // Só no cache de 7 dias (sem schema na tabela): conferida pelo cache.
        $soNoCache = $this->produto($empresa, 'Vaso Do Cache', 'VS-3', [null], ['categoria_ml_id' => 'MLB999002']);
        Cache::put('ml_meta_atributos_MLB999002', self::schema(self::CADEIRA)->atributos, now()->addDay());

        $trilha = $this->trilha($empresa);

        $p = $this->produtoDaTrilha($trilha, $naoGuardada['produto']->id);
        $this->assertSame('nao_conferida', $p['ficha']);
        $this->assertSame([], $p['faltas']);
        $this->assertNull($p['etapa'], 'nada falta e nada a decidir: mapeado');

        $p = $this->produtoDaTrilha($trilha, $semCategoria['produto']->id);
        $this->assertSame('sem_categoria', $p['ficha']);
        $this->assertSame(['categoria'], $p['faltas']);
        $this->assertSame('lista', $p['etapa']);
        $this->assertNull($p['categoria']);

        $p = $this->produtoDaTrilha($trilha, $soNoCache['produto']->id);
        $this->assertSame('incompleta', $p['ficha']);
        $this->assertContains('ficha: marca', $p['faltas']);

        Http::assertNothingSent();
    }

    public function test_dados_da_planilha_da_lista(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Planilha', 'VP-1', ['Natural', 'Mel']);
        [$natural, $mel] = $p['variacoes'];
        $natural->update(['custo' => 290]);
        $mel->update(['custo' => 310]);
        $natural->volumes()->update(['comprimento' => 86, 'largura' => 46, 'altura' => 18, 'peso' => 12]);
        EstruturaProdutoVolume::create(['variacao_id' => $natural->id, 'ordem' => 2, 'comprimento' => 60, 'largura' => 40, 'altura' => 10.5, 'peso' => 3]);
        foreach (['LENGTH' => '180', 'WIDTH' => '90', 'HEIGHT' => '76.5'] as $id => $valor) {
            $this->atributo($empresa, $p['produto'], $id, $valor, null, 'cm');
        }
        $p['produto']->ambientes()->attach(EstruturaAmbiente::create(['company_id' => $empresa->id, 'nome' => 'Área externa'])->id);

        $produto = $this->produtoDaTrilha($this->trilha($empresa), $p['produto']->id);

        $this->assertSame('180 × 90 × 76,5 cm', $produto['medidas']);
        $this->assertSame('86 × 46 × 18 cm · 12 kg · 2 volumes', $produto['embalagem']);
        $this->assertSame([290.0, 310.0], [$produto['custo_min'], $produto['custo_max']]);
        $this->assertSame(3, $produto['fotos_min']);
        $this->assertTrue($produto['tem_descricao']);
        $this->assertSame('VP-1', $produto['codigo']);
        $this->assertSame('Decoração', $produto['familia']);
        $this->assertSame(['Área externa', 'Sala de estar'], $produto['ambientes']);
        $this->assertSame('Objetos de decoração', $produto['categoria']);
        $this->assertSame([], $produto['faltas']);
    }

    // ═══ Produto: o mapeamento ═══════════════════════════════════════════════

    public function test_produto_completo_com_sugestao_pendente_fica_no_mapeamento(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Beta', 'VB-1', ['Natural', 'Preto']);
        $this->quantidadesDeCombo($empresa, $p['produto'], '2');

        $sugestoes = app(SugestoesService::class)->gerar($empresa)['sugestoes'];
        $this->assertCount(2, $sugestoes, 'Combo 2 de cada cor');

        $trilha = $this->trilha($empresa);
        $produto = $this->produtoDaTrilha($trilha, $p['produto']->id);

        $this->assertSame('mapa', $produto['etapa']);
        $this->assertSame(2, $produto['sugestoes_pendentes_total']);
        $this->assertSame(
            array_map(fn ($s) => ['fase' => 'combo', 'nome' => $s['nome'], 'sku' => $s['sku']], $sugestoes),
            $produto['sugestoes_pendentes'],
        );
        $this->assertNull($this->oferta($trilha, "s-{$p['produto']->id}"), 'a Simples ainda é parte do produto');
    }

    public function test_produto_completo_sem_sugestao_pendente_sai_do_mapeamento_e_a_simples_espera_onda(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Gama', 'VG-1', ['Natural', 'Preto']);
        $this->quantidadesDeCombo($empresa, $p['produto'], '2');
        foreach (app(SugestoesService::class)->gerar($empresa)['sugestoes'] as $s) {
            EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $s['chave'], 'fase' => $s['fase']]);
        }

        $trilha = $this->trilha($empresa);
        $produto = $this->produtoDaTrilha($trilha, $p['produto']->id);
        $this->assertNull($produto['etapa']);
        $this->assertSame([[], 0], [$produto['sugestoes_pendentes'], $produto['sugestoes_pendentes_total']]);

        // As duas cores numa oferta só, como no Publicador.
        $simples = array_values(array_filter($trilha['ofertas'], fn ($o) => str_starts_with($o['id'], 's-')));
        $this->assertCount(1, $simples);
        $this->assertSame([
            'id' => "s-{$p['produto']->id}", 'tipo' => 'Simples', 'nome' => 'Vaso Gama', 'sku' => 'VG-1', 'skus' => ['VG-1-1', 'VG-1-2'],
            'componentes' => [['produto_id' => $p['produto']->id, 'quantidade' => 1, 'sku' => 'VG-1', 'nome' => 'Vaso Gama']],
            'etapa' => 'plano', 'status' => 'Sem onda', 'alerta' => false, 'anuncios' => [], 'preparo' => null,
            'onda_id' => null, 'sem_produto' => false, 'planejavel' => false, 'pode_tirar' => false, 'pub_produto_id' => null,
        ], $simples[0]);
    }

    public function test_simples_de_produto_na_lista_so_aparece_depois_de_andar(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Delta', 'VD-1', [null], ['descricao' => null]);
        $id = "s-{$p['produto']->id}";

        $this->assertSame('lista', $this->produtoDaTrilha($this->trilha($empresa), $p['produto']->id)['etapa']);
        $this->assertNull($this->oferta($this->trilha($empresa), $id), 'sem nada andado, a Simples é parte do produto');

        // Rascunho ainda em DRAFT e sem o agente: continua escondida.
        $pub = $this->pubDoGrupo($empresa, $p);
        $r = $this->rascunho($pub);
        $this->assertNull($this->oferta($this->trilha($empresa), $id));

        // A publicação falhou: andou, aparece — e na revisão, com alerta.
        $r->update(['status' => PubRascunho::FAILED]);
        $oferta = $this->oferta($this->trilha($empresa), $id);
        $this->assertSame(['revisao', 'A publicação falhou', true], [$oferta['etapa'], $oferta['status'], $oferta['alerta']]);
        $this->assertSame($pub->id, $oferta['pub_produto_id']);
        $this->assertSame(['titulo' => null, 'modelo' => null, 'descricao' => null, 'imagens' => null], $oferta['preparo']);
        $this->assertSame($pub->id, $this->produtoDaTrilha($this->trilha($empresa), $p['produto']->id)['pub_produto_id']);
    }

    // ═══ Ofertas: Combo, Kit, sem produto ════════════════════════════════════

    public function test_combo_2_das_duas_cores_vira_uma_oferta_que_espera_a_simples(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Combo', 'VCB-1', ['Natural', 'Preto']);
        $combos = [];
        foreach ($p['simples'] as $simples) {
            $combos[] = $this->composta($empresa, 'combo', "{$simples->sku}-CB2", "Combo 2 {$simples->nome}", [[$simples, 2]]);
        }

        $trilha = $this->trilha($empresa);
        $doCombo = array_values(array_filter($trilha['ofertas'], fn ($o) => $o['tipo'] === 'Combo'));
        $this->assertCount(1, $doCombo);
        $this->assertSame([
            'id' => "c-{$p['produto']->id}-2", 'tipo' => 'Combo', 'nome' => 'Combo 2 · Vaso Combo', 'sku' => 'VCB-1-1-CB2',
            'skus' => ['VCB-1-1-CB2', 'VCB-1-2-CB2'],
            'componentes' => [['produto_id' => $p['produto']->id, 'quantidade' => 2, 'sku' => 'VCB-1', 'nome' => 'Vaso Combo']],
            'etapa' => 'plano', 'status' => 'Espera a oferta simples ir ao ar', 'alerta' => false, 'anuncios' => [], 'preparo' => null,
            'onda_id' => null, 'sem_produto' => false, 'planejavel' => false, 'pode_tirar' => false, 'pub_produto_id' => null,
        ], $doCombo[0]);

        // A Simples no ar: o Combo passa a esperar só a onda.
        $this->anuncioDaRegua($p['simples'][0], 'classico', 'MLB5000001');
        $trilha = $this->trilha($empresa);
        $this->assertSame('lanc', $this->oferta($trilha, "s-{$p['produto']->id}")['etapa']);
        $this->assertSame('Sem onda', $this->oferta($trilha, "c-{$p['produto']->id}-2")['status']);

        // No Publicador, o Combo 2 é a fase 2 da Simples (o kit de 2 do grupo das cores).
        $grupo = $this->pubDoGrupo($empresa, $p);
        $fase = PubProduto::create(['company_id' => $empresa->id, 'produto_base_id' => $grupo->id, 'quantidade_kit' => 2, 'fase' => 2,
            'sku' => 'VCB-1-KIT2', 'nome' => 'Kit 2 Vaso Combo', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $r = $this->rascunho($fase, PubRascunho::PUBLISHING);
        $oferta = $this->oferta($this->trilha($empresa), "c-{$p['produto']->id}-2");
        $this->assertSame(['revisao', 'Publicando agora', $fase->id], [$oferta['etapa'], $oferta['status'], $oferta['pub_produto_id']]);

        // O agente não escreve o texto do kit da fase: o que falta nele é do Publicador, não "Preparando" no robô.
        $r->update(['status' => PubRascunho::DRAFT, 'step_state' => ['resumo' => $this->resumo(2, 1)]]);
        $oferta = $this->oferta($this->trilha($empresa), "c-{$p['produto']->id}-2");
        $this->assertSame(['revisao', 'Faltam 2 itens para publicar', true], [$oferta['etapa'], $oferta['status'], $oferta['alerta']]);
        $this->assertSame(['titulo' => null, 'modelo' => null, 'descricao' => null, 'imagens' => null], $oferta['preparo']);
    }

    public function test_combo_de_produto_com_uma_cor_so_leva_o_nome_da_propria_oferta(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Unico', 'VU-1');
        $this->composta($empresa, 'combo', 'VU-1-CB3', 'Trio de vasos únicos', [[$p['simples'][0], 3]]);

        $oferta = $this->oferta($this->trilha($empresa), "c-{$p['produto']->id}-3");
        $this->assertSame(['Trio de vasos únicos', 'VU-1-CB3', ['VU-1-CB3']], [$oferta['nome'], $oferta['sku'], $oferta['skus']]);
    }

    public function test_kit_aceito_vira_oferta_propria_no_planejamento(): void
    {
        $empresa = $this->empresa();
        $a = $this->produto($empresa, 'Vaso Kit A', 'VKA');
        $b = $this->produto($empresa, 'Vaso Kit B', 'VKB');
        $kit = $this->composta($empresa, 'kit', 'KT-VKA-VKB', 'Kit vaso A + vaso B', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);

        $this->assertSame([
            'id' => "o-{$kit->id}", 'tipo' => 'Kit', 'nome' => 'Kit vaso A + vaso B', 'sku' => 'KT-VKA-VKB', 'skus' => ['KT-VKA-VKB'],
            'componentes' => [
                ['produto_id' => $a['produto']->id, 'quantidade' => 1, 'sku' => 'VKA', 'nome' => 'Vaso Kit A'],
                ['produto_id' => $b['produto']->id, 'quantidade' => 1, 'sku' => 'VKB', 'nome' => 'Vaso Kit B'],
            ],
            // O Kit não espera a simples (a regra da fase N é só do Combo).
            'etapa' => 'plano', 'status' => 'Sem onda', 'alerta' => false, 'anuncios' => [], 'preparo' => null,
            'onda_id' => null, 'sem_produto' => false, 'planejavel' => false, 'pode_tirar' => false, 'pub_produto_id' => null,
        ], $this->oferta($this->trilha($empresa), "o-{$kit->id}"));

        // No Publicador o Kit é um produto da própria oferta.
        $pub = PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $kit->id, 'sku' => 'KT-VKA-VKB', 'nome' => 'Kit', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $this->rascunho($pub, PubRascunho::DRAFT, ['resumo' => $this->resumo(0, 1)]);
        $oferta = $this->oferta($this->trilha($empresa), "o-{$kit->id}");
        $this->assertSame(['revisao', 'Pré-pronto · esperando a revisão', $pub->id], [$oferta['etapa'], $oferta['status'], $oferta['pub_produto_id']]);
    }

    public function test_oferta_sem_produto_aparece_marcada(): void
    {
        $empresa = $this->empresa();
        $avulsa = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => 'NICHO-01', 'fase' => 'simples', 'nome' => 'Nicho avulso']);

        $oferta = $this->oferta($this->trilha($empresa), "o-{$avulsa->id}");
        $this->assertTrue($oferta['sem_produto']);
        $this->assertSame(['Simples', 'Nicho avulso', 'NICHO-01'], [$oferta['tipo'], $oferta['nome'], $oferta['sku']]);
        $this->assertSame([['produto_id' => null, 'quantidade' => 1, 'sku' => 'NICHO-01', 'nome' => 'Nicho avulso']], $oferta['componentes']);
        $this->assertSame(['plano', 'Sem onda'], [$oferta['etapa'], $oferta['status']]);
    }

    // ═══ Anúncios ════════════════════════════════════════════════════════════

    public function test_anuncios_da_regua_do_portal(): void
    {
        $empresa = $this->empresa();
        $dois = $this->produto($empresa, 'Vaso Dois Tipos', 'VDT');
        $this->anuncioDaRegua($dois['simples'][0], 'classico', 'mlb7000001');
        $this->anuncioDaRegua($dois['simples'][0], 'premium', 'MLB7000002');
        // Inativo e sem MLB (planejado) não contam.
        $this->anuncioDaRegua($dois['simples'][0], 'premium', 'MLB7000003', 'inativo');
        $this->anuncioDaRegua($dois['simples'][0], 'classico', null);

        $soClassico = $this->produto($empresa, 'Vaso Só Clássico', 'VSC');
        $this->anuncioDaRegua($soClassico['simples'][0], 'classico', 'MLB7000010', 'pausado');

        $trilha = $this->trilha($empresa);

        $oferta = $this->oferta($trilha, "s-{$dois['produto']->id}");
        $this->assertSame(['lanc', 'No ar · Clássico e Premium', false], [$oferta['etapa'], $oferta['status'], $oferta['alerta']]);
        $this->assertSame([
            ['tipo' => 'Clássico', 'mlb' => 'MLB7000001', 'situacao' => null],
            ['tipo' => 'Premium', 'mlb' => 'MLB7000002', 'situacao' => null],
        ], $oferta['anuncios']);

        $oferta = $this->oferta($trilha, "s-{$soClassico['produto']->id}");
        $this->assertSame(['lanc', 'No ar · falta o Premium'], [$oferta['etapa'], $oferta['status']]);
        $this->assertSame([['tipo' => 'Clássico', 'mlb' => 'MLB7000010', 'situacao' => 'Pausado']], $oferta['anuncios']);
    }

    public function test_publicador_com_item_criado_poe_no_ar_com_o_mlb_sem_repetir_o_espelho(): void
    {
        $empresa = $this->empresa();
        $p = $this->produto($empresa, 'Vaso Publicado', 'VPB');
        $pub = $this->pubDoGrupo($empresa, $p);
        $r = $this->rascunho($pub, PubRascunho::PUBLISHED);
        $this->publicado($r, 'gold_pro', 'MLB8000001', ['status' => 'paused', 'sub_status' => ['paused_by_seller']]);
        $this->publicado($r, 'gold_special', 'MLB8000002');
        $this->publicado($r, 'gold_special', 'MLB8000099', status: PubPublicacaoItem::FAILED);
        // O espelho do mesmo anúncio na régua do Portal não repete.
        $this->anuncioDaRegua($p['simples'][0], 'premium', 'MLB8000001');

        $soPremium = $this->produto($empresa, 'Vaso Só Premium', 'VSP');
        $this->publicado($this->rascunho($this->pubDoGrupo($empresa, $soPremium), PubRascunho::PUBLISHED), 'gold_pro', 'MLB8000010');

        $trilha = $this->trilha($empresa);

        $oferta = $this->oferta($trilha, "s-{$p['produto']->id}");
        $this->assertSame(['lanc', 'No ar · Clássico e Premium', $pub->id], [$oferta['etapa'], $oferta['status'], $oferta['pub_produto_id']]);
        $this->assertSame([
            ['tipo' => 'Clássico', 'mlb' => 'MLB8000002', 'situacao' => null],
            ['tipo' => 'Premium', 'mlb' => 'MLB8000001', 'situacao' => 'Pausado'],
        ], $oferta['anuncios']);
        $this->assertSame($pub->id, $this->produtoDaTrilha($trilha, $p['produto']->id)['pub_produto_id']);

        $oferta = $this->oferta($trilha, "s-{$soPremium['produto']->id}");
        $this->assertSame(['lanc', 'No ar · falta o Clássico'], [$oferta['etapa'], $oferta['status']]);
        $this->assertSame([['tipo' => 'Premium', 'mlb' => 'MLB8000010', 'situacao' => null]], $oferta['anuncios']);
    }

    /**
     * Revisão de 31/10/2026 (F1): "no ar" é o anúncio ativo ou pausado — a decisão do contrato (o exemplo s-10) e a
     * régua do Portal ("Pausado CONTA"). Em revisão, aguardando pagamento, ainda não ativado e o status que o ML
     * inventar não estão à venda: a trilha dizia "No ar · …", sem alerta, ao lado de um anúncio "Fora do ar". É o
     * caso de logo depois de publicar (o H21 do `SituacaoDoAnuncioMl`: o ML põe em revisão depois de criar).
     */
    public function test_so_ativo_e_pausado_contam_como_no_ar(): void
    {
        $empresa = $this->empresa();
        $n = 0;
        $publicado = function (string $nome, array $anuncios) use ($empresa, &$n): array {
            $p = $this->produto($empresa, $nome, 'VF'.(++$n));
            $r = $this->rascunho($this->pubDoGrupo($empresa, $p), PubRascunho::PUBLISHED);
            foreach ($anuncios as [$tipo, $statusNoMl]) {
                $this->publicado($r, $tipo, 'MLB61000'.(++$n), $statusNoMl !== null ? ['status' => $statusNoMl] : null);
            }

            return $p;
        };

        $emRevisao = $publicado('Vaso Em Revisao', [['gold_special', 'under_review'], ['gold_pro', 'under_review']]);
        $desconhecido = $publicado('Vaso Status Novo', [['gold_special', 'status_desconhecido']]);
        $metade = $publicado('Vaso Metade', [['gold_special', null], ['gold_pro', 'under_review']]);
        $aguardando = $publicado('Vaso Aguardando', [['gold_special', 'payment_required'], ['gold_pro', 'not_yet_active']]);
        $encerradoERevisao = $publicado('Vaso Encerrado E Revisao', [['gold_special', 'closed'], ['gold_pro', 'under_review']]);
        $pausado = $publicado('Vaso Pausado', [['gold_special', 'paused']]);
        $encerrado = $publicado('Vaso Encerrado', [['gold_special', 'closed']]);

        $trilha = $this->trilha($empresa);
        $situacao = function (array $p) use ($trilha): array {
            $o = $this->oferta($trilha, "s-{$p['produto']->id}");

            return [$o['etapa'], $o['status'], $o['alerta'], array_column($o['anuncios'], 'situacao')];
        };

        // Nada à venda, mas nada encerrado: continua na etapa 6 (já publicado), com o alerta e sem dizer "No ar".
        $this->assertSame(['lanc', 'Fora do ar · Clássico e Premium', true, ['Em revisão', 'Em revisão']], $situacao($emRevisao));
        $this->assertSame(['lanc', 'Fora do ar · Clássico', true, ['Fora do ar']], $situacao($desconhecido));
        $this->assertSame(['lanc', 'Fora do ar · Clássico e Premium', true, ['Aguardando pagamento', 'Ainda não ativado']], $situacao($aguardando));
        $this->assertSame(['lanc', 'Fora do ar · Premium', true, ['Encerrado', 'Em revisão']], $situacao($encerradoERevisao));
        // Um tipo à venda e o outro parado: "no ar", com o alerta.
        $this->assertSame(['lanc', 'No ar · falta o Premium', true, [null, 'Em revisão']], $situacao($metade));
        // O pausado conta (a decisão do contrato) e o encerrado segue como era.
        $this->assertSame(['lanc', 'No ar · falta o Premium', false, ['Pausado']], $situacao($pausado));
        $this->assertSame(['lanc', 'Anúncios encerrados', true, ['Encerrado']], $situacao($encerrado));
        foreach ([$emRevisao, $desconhecido, $aguardando, $encerradoERevisao] as $p) {
            $this->assertStringNotContainsString('No ar', $this->oferta($trilha, "s-{$p['produto']->id}")['status']);
        }
    }

    // ═══ Preparação automática e revisão ═════════════════════════════════════

    public function test_o_agente_trabalhando_poe_a_oferta_no_robo(): void
    {
        $empresa = $this->empresa();

        $escrevendo = $this->comRascunho($empresa, 'Vaso Escrevendo', 'VE1', ['ia_preparo' => ['hash' => 'h', 'etapas' => [
            'titulo' => $this->etapa('ok', 5), 'modelo' => $this->etapa('rodando', 2),
        ]]]);
        $agendado = $this->comRascunho($empresa, 'Vaso Agendado', 'VE2');
        Cache::put(PreparoIaAgenda::chaveDoAgendado($agendado['produto']->id), now()->timestamp, now()->addMinute());
        $parou = $this->comRascunho($empresa, 'Vaso Parou', 'VE3', ['ia_preparo' => ['hash' => 'h', 'etapas' => [
            'titulo' => $this->etapa('ok', 30), 'modelo' => $this->etapa('ok', 30), 'descricao' => $this->etapa('erro', 20),
        ]]]);
        // `rodando` há 4 horas: o Job morreu sem avisar (o preparo também a trata como incompleta).
        $morreu = $this->comRascunho($empresa, 'Vaso Morreu', 'VE4', ['ia_preparo' => ['hash' => 'h', 'etapas' => [
            'titulo' => $this->etapa('rodando', 240),
        ]]]);
        $kit = MlAnuncioCriativoKit::create(['token' => Str::random(32), 'status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $imagens = $this->comRascunho($empresa, 'Vaso Imagens', 'VE5', [
            'ia_preparo'     => ['hash' => 'h', 'etapas' => ['titulo' => $this->etapa('ok', 9), 'modelo' => $this->etapa('ok', 8), 'descricao' => $this->etapa('ok', 7)]],
            'criativos_auto' => ['grupos' => ['geral' => ['hash' => 'x', 'kit_id' => $kit->id, 'em' => now()->toIso8601String(), 'tentativas' => 1]]],
        ]);

        $trilha = $this->trilha($empresa);

        $o = $this->oferta($trilha, "s-{$escrevendo['produto']->id}");
        $this->assertSame(['robo', 'O agente está escrevendo título e descrição', false], [$o['etapa'], $o['status'], $o['alerta']]);
        $this->assertSame(['titulo' => 'pronto', 'modelo' => 'fazendo', 'descricao' => 'fila', 'imagens' => null], $o['preparo']);

        $o = $this->oferta($trilha, "s-{$agendado['produto']->id}");
        $this->assertSame(['robo', 'Na fila do agente'], [$o['etapa'], $o['status']]);
        $this->assertSame(['titulo' => 'fila', 'modelo' => 'fila', 'descricao' => 'fila', 'imagens' => null], $o['preparo']);

        $o = $this->oferta($trilha, "s-{$parou['produto']->id}");
        $this->assertSame(['robo', 'O agente parou: descrição', true], [$o['etapa'], $o['status'], $o['alerta']]);
        $this->assertSame('erro', $o['preparo']['descricao']);

        $o = $this->oferta($trilha, "s-{$morreu['produto']->id}");
        $this->assertSame(['robo', 'O agente parou: título', true], [$o['etapa'], $o['status'], $o['alerta']]);
        $this->assertSame(['titulo' => 'erro', 'modelo' => 'fila', 'descricao' => 'fila', 'imagens' => null], $o['preparo']);

        $o = $this->oferta($trilha, "s-{$imagens['produto']->id}");
        $this->assertSame(['robo', 'O agente está gerando as imagens'], [$o['etapa'], $o['status']]);
        $this->assertSame(['titulo' => 'pronto', 'modelo' => 'pronto', 'descricao' => 'pronto', 'imagens' => 'fazendo'], $o['preparo']);
    }

    public function test_o_resumo_do_editor_decide_a_revisao(): void
    {
        $empresa = $this->empresa();
        $texto = ['hash' => 'h', 'etapas' => ['titulo' => $this->etapa('ok', 9), 'modelo' => $this->etapa('pulado', 8), 'descricao' => $this->etapa('ok', 7)]];

        $prePronto = $this->comRascunho($empresa, 'Vaso Pre Pronto', 'VR1', ['resumo' => $this->resumo(0, 3)], 3);
        $faltamDois = $this->comRascunho($empresa, 'Vaso Faltam Dois', 'VR2', ['ia_preparo' => $texto, 'resumo' => $this->resumo(2, 1)]);
        $faltaUm = $this->comRascunho($empresa, 'Vaso Falta Um', 'VR3', ['ia_preparo' => $texto, 'resumo' => $this->resumo(1, 1)]);
        // Resumo de outra revisão: vencido.
        $aConferir = $this->comRascunho($empresa, 'Vaso A Conferir', 'VR4', ['ia_preparo' => $texto, 'resumo' => $this->resumo(0, 1)], 2);
        // Sem o texto do agente: segue preparando.
        $preparando = $this->comRascunho($empresa, 'Vaso Preparando', 'VR5', ['resumo' => $this->resumo(2, 1)]);
        // Zero bloqueios sem categoria (o editor não validou nada) não é pré-pronto.
        $semCategoria = $this->comRascunho($empresa, 'Vaso Sem Validar', 'VR6', ['resumo' => $this->resumo(0, 1, validado: false)]);
        // Resumo de uma versão antiga do formato: vencido.
        $versaoVelha = $this->comRascunho($empresa, 'Vaso Versao Velha', 'VR7', ['resumo' => ['versao' => EditorRascunhoService::VERSAO_DO_RESUMO - 1] + $this->resumo(0, 1)]);

        $trilha = $this->trilha($empresa);
        $situacao = fn (array $p) => [
            $this->oferta($trilha, "s-{$p['produto']->id}")['etapa'],
            $this->oferta($trilha, "s-{$p['produto']->id}")['status'],
            $this->oferta($trilha, "s-{$p['produto']->id}")['alerta'],
        ];

        $this->assertSame(['revisao', 'Pré-pronto · esperando a revisão', false], $situacao($prePronto));
        $this->assertSame(['revisao', 'Faltam 2 itens para publicar', true], $situacao($faltamDois));
        $this->assertSame(['revisao', 'Falta 1 item para publicar', true], $situacao($faltaUm));
        $this->assertSame(['revisao', 'Pré-pronto · a conferir', false], $situacao($aConferir));
        $this->assertSame(['robo', 'Preparando · faltam 2 itens', false], $situacao($preparando));
        $this->assertSame(['robo', 'Preparando', false], $situacao($semCategoria));
        $this->assertSame(['robo', 'Preparando', false], $situacao($versaoVelha));

        // Fora do robô, etapa do texto sem registro não diz nada.
        $this->assertSame(['titulo' => null, 'modelo' => null, 'descricao' => null, 'imagens' => null], $this->oferta($trilha, "s-{$prePronto['produto']->id}")['preparo']);
        $this->assertSame(['titulo' => 'pronto', 'modelo' => 'pulado', 'descricao' => 'pronto', 'imagens' => null], $this->oferta($trilha, "s-{$faltamDois['produto']->id}")['preparo']);
    }

    /**
     * Revisão de 31/10/2026 (D1): o resumo do editor não cobre as imagens automáticas — com as fotos do cliente ele
     * diz 0 bloqueio. Quando as imagens não vieram, a vitrine do Publicador NÃO diz "Pronto" ("Imagens amanhã" /
     * "Imagens não geradas", `VitrineDeProdutosService::naoEstaPronto`), e a etapa 4 só fecha com elas
     * ("Título, descrição e imagens prontos"). A trilha dizia "Pré-pronto", sem alerta.
     */
    public function test_imagens_que_nao_vieram_seguram_o_pre_pronto(): void
    {
        $empresa = $this->empresa();
        $texto = ['hash' => 'h', 'etapas' => ['titulo' => $this->etapa('ok', 9), 'modelo' => $this->etapa('ok', 8), 'descricao' => $this->etapa('ok', 7)]];
        $kit = fn (string $status) => MlAnuncioCriativoKit::create(['token' => Str::random(32), 'status' => $status]);
        $grupo = fn (MlAnuncioCriativoKit $k, int $tentativas, array $mais = []) => ['grupos' => ['geral' => [
            'hash' => 'x', 'kit_id' => $k->id, 'em' => now()->subHours(2)->toIso8601String(), 'tentativas' => $tentativas,
        ] + $mais]];
        $teto = ['adiado' => ['dia' => now()->toDateString(), 'motivo' => 'limite']];
        $estado = fn (array $criativos, int $bloqueios = 0, ?array $preparo = null) => [
            'ia_preparo' => $preparo ?? $texto, 'resumo' => $this->resumo($bloqueios, 1), 'criativos_auto' => $criativos,
        ];

        // O teto do dia barrou as imagens: o robô volta amanhã.
        $adiado = $this->comRascunho($empresa, 'Vaso Teto', 'VI1', $estado($teto));
        // O kit falhou por erro técnico e ainda cabe a 2ª tentativa, amanhã cedo.
        $novaTentativa = $this->comRascunho($empresa, 'Vaso Nova Tentativa', 'VI2', $estado($grupo($kit(MlAnuncioCriativoKit::STATUS_ERRO), 1)));
        // As duas tentativas falharam: o robô não tenta mais sozinho.
        $falhou = $this->comRascunho($empresa, 'Vaso Falhou', 'VI3', $estado($grupo($kit(MlAnuncioCriativoKit::STATUS_ERRO), 2)));
        // Teto do dia com o resumo de outra revisão: o "Pré-pronto · a conferir" também não vale.
        $adiadoVencido = $this->comRascunho($empresa, 'Vaso Teto Vencido', 'VI4', $estado($teto), 2);
        // Teto do dia com itens faltando: segue no robô, com a conta do que falta.
        $adiadoComFalta = $this->comRascunho($empresa, 'Vaso Teto Com Falta', 'VI5', $estado($teto, 2));
        // Texto e imagens pararam juntos: um aviso só, na ordem do preparo.
        $tudoParou = $this->comRascunho($empresa, 'Vaso Tudo Parou', 'VI6', $estado($grupo($kit(MlAnuncioCriativoKit::STATUS_ERRO), 2), 0,
            ['hash' => 'h', 'etapas' => ['titulo' => $this->etapa('ok', 9), 'modelo' => $this->etapa('ok', 8), 'descricao' => $this->etapa('erro', 7)]]));
        // Controle: as imagens entraram no anúncio — o pré-pronto vale.
        $aplicadas = $this->comRascunho($empresa, 'Vaso Aplicadas', 'VI7', $estado($grupo($kit(MlAnuncioCriativoKit::STATUS_PRONTO), 1,
            ['aplicado_em' => now()->toIso8601String(), 'aplicadas' => 2])));
        // Dois grupos (cores): o teto do dia barrou um e o kit do outro anda há uma hora. A pílula diz "fazendo" (vem
        // antes da fila), mas a etapa não fecha — o Publicador diz "Imagens amanhã".
        $kitAndando = $kit(MlAnuncioCriativoKit::STATUS_GERANDO);
        $kitAndando->forceFill(['created_at' => now()->subHour()])->save();
        $duasCores = $this->comRascunho($empresa, 'Vaso Duas Cores', 'VI8', $estado($teto + $grupo($kitAndando, 1)));

        $trilha = $this->trilha($empresa);
        $situacao = function (array $p) use ($trilha): array {
            $o = $this->oferta($trilha, "s-{$p['produto']->id}");

            return [$o['etapa'], $o['status'], $o['alerta'], $o['preparo']['imagens']];
        };

        $this->assertSame(['robo', 'Preparando', false, 'fila'], $situacao($adiado));
        $this->assertSame(['robo', 'Preparando', false, 'fila'], $situacao($novaTentativa));
        $this->assertSame(['robo', 'O agente parou: imagens', true, 'erro'], $situacao($falhou));
        $this->assertSame(['robo', 'Preparando', false, 'fila'], $situacao($adiadoVencido));
        $this->assertSame(['robo', 'Preparando · faltam 2 itens', false, 'fila'], $situacao($adiadoComFalta));
        $this->assertSame(['robo', 'O agente parou: descrição, imagens', true, 'erro'], $situacao($tudoParou));
        $this->assertSame(['revisao', 'Pré-pronto · esperando a revisão', false, 'pronto'], $situacao($aplicadas));
        $this->assertSame(['robo', 'Preparando', false, 'fazendo'], $situacao($duasCores));
        $this->assertSame(['titulo' => 'pronto', 'modelo' => 'pronto', 'descricao' => 'pronto', 'imagens' => 'fila'],
            $this->oferta($trilha, "s-{$adiado['produto']->id}")['preparo']);

        // A mesma régua do Publicador: é o que a vitrine dele lê para não dizer "Pronto".
        $this->assertSame('adiado', CriativosAutomaticosService::situacaoDasImagens($adiado['rascunho']));
        $this->assertSame('nova_tentativa', CriativosAutomaticosService::situacaoDasImagens($novaTentativa['rascunho']));
        $this->assertSame('falhou', CriativosAutomaticosService::situacaoDasImagens($falhou['rascunho']));
        $this->assertSame('adiado', CriativosAutomaticosService::situacaoDasImagens($duasCores['rascunho']));
        $this->assertNull(CriativosAutomaticosService::situacaoDasImagens($aplicadas['rascunho']));
    }

    // ═══ Ordem, consultas e nada gravado ═════════════════════════════════════

    public function test_a_saida_vem_na_ordem_da_trilha(): void
    {
        $empresa = $this->empresa();
        $this->produto($empresa, 'Zebra incompleta', 'Z1', [null], ['descricao' => null]);
        $this->produto($empresa, 'Ábaco incompleto', 'Z2', [null], ['descricao' => null]);
        $mapa = $this->produto($empresa, 'Mapa pendente', 'Z3', ['Natural', 'Preto']);
        $this->quantidadesDeCombo($empresa, $mapa['produto'], '2');
        $noAr = $this->produto($empresa, 'Bem no ar', 'Z4');
        $this->anuncioDaRegua($noAr['simples'][0], 'classico', 'MLB9000001');
        $this->comRascunho($empresa, 'Com o robô', 'Z5', ['ia_preparo' => ['hash' => 'h', 'etapas' => ['titulo' => $this->etapa('rodando', 1)]]]);
        $this->comRascunho($empresa, 'Em revisão', 'Z6', ['resumo' => $this->resumo(0, 1)]);
        $semOnda = $this->produto($empresa, 'Aguardando', 'Z7');
        $this->composta($empresa, 'kit', 'KT-Z7-Z4', 'Kit aguardando', [[$semOnda['simples'][0], 1], [$noAr['simples'][0], 1]]);

        $trilha = $this->trilha($empresa);

        $this->assertSame(
            [['lista', 'Ábaco incompleto'], ['lista', 'Zebra incompleta'], ['mapa', 'Mapa pendente']],
            array_map(fn ($p) => [$p['etapa'], $p['nome']], array_slice($trilha['produtos'], 0, 3)),
        );
        $this->assertSame([null], array_values(array_unique(array_column(array_slice($trilha['produtos'], 3), 'etapa'))));
        $this->assertSame(
            [['plano', 'Simples', 'Aguardando'], ['plano', 'Kit', 'Kit aguardando'], ['robo', 'Simples', 'Com o robô'],
                ['revisao', 'Simples', 'Em revisão'], ['lanc', 'Simples', 'Bem no ar']],
            array_map(fn ($o) => [$o['etapa'], $o['tipo'], $o['nome']], $trilha['ofertas']),
        );
        $this->assertSame([], $trilha['ondas']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-03:00$/', $trilha['gerado_em']);
    }

    public function test_numero_de_consultas_nao_cresce_com_o_catalogo_e_nada_e_gravado(): void
    {
        $schema = $this->guardarSchema(self::schema(self::CADEIRA));
        $pequena = $this->empresa();
        $grande = $this->empresa();
        $this->catalogoCompleto($pequena, 2, $schema);
        $this->catalogoCompleto($grande, 6, $schema);

        $servico = app(TrilhaDaEmpresa::class);
        $servico->montar($pequena);

        $consultas = function (Company $empresa) use ($servico): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $trilha = $servico->montar($empresa);
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return [$trilha, array_column($log, 'query')];
        };

        [$trilhaPequena, $daPequena] = $consultas($pequena);
        [$trilhaGrande, $daGrande] = $consultas($grande);

        // O catálogo é de verdade: todos os pedaços da trilha aparecem nos dois.
        $this->assertCount(2, $trilhaPequena['produtos']);
        $this->assertCount(6, $trilhaGrande['produtos']);
        $this->assertSame(['plano', 'revisao', 'lanc'], array_values(array_unique(array_column($trilhaGrande['ofertas'], 'etapa'))));

        $this->assertSame(count($daPequena), count($daGrande), 'consultas: '.count($daPequena).' × '.count($daGrande));
        $this->assertLessThanOrEqual(40, count($daGrande));
        foreach ($daGrande as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, "a trilha só lê: {$sql}");
        }

        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    private function trilha(Company $empresa): array
    {
        return app(TrilhaDaEmpresa::class)->montar($empresa);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name'   => 'Loja da Trilha '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function guardarSchema(CategorySchema $schema): CategorySchema
    {
        MlCategoriaSchema::create(['category_id' => $schema->categoriaId, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        return $schema;
    }

    /**
     * Um produto COMPLETO pela régua da lista (família, ambiente, título, descrição, custo, caixa que cabe no
     * Mercado Envios e 3 fotos em cada cor), numa categoria sem definição guardada (ficha "não conferida", que não
     * é falta), com uma oferta simples por cor — como o cadastro do Portal cria. `$cores` com `null` = sem eixo.
     *
     * @param  list<?string>  $cores
     * @return array{produto: EstruturaProduto, variacoes: list<EstruturaProdutoVariacao>, simples: list<EstruturaOferta>}
     */
    private function produto(Company $empresa, string $nome, string $codigo, array $cores = [null], array $dados = [], bool $ambiente = true): array
    {
        $familia = EstruturaFamilia::firstOrCreate(['company_id' => $empresa->id, 'nome' => 'Decoração']);
        $produto = EstruturaProduto::create(array_merge([
            'company_id' => $empresa->id, 'codigo' => $codigo, 'nome' => $nome, 'titulo' => "Título de venda {$nome}",
            'familia_id' => $familia->id, 'categoria_ml_id' => 'MLB100200', 'categoria_ml_nome' => 'Objetos de decoração',
            'descricao' => 'Uma descrição do produto.',
        ], $dados));
        if ($ambiente) {
            $produto->ambientes()->attach(EstruturaAmbiente::firstOrCreate(['company_id' => $empresa->id, 'nome' => 'Sala de estar'])->id);
        }

        $variacoes = [];
        $simples = [];
        foreach (array_values($cores) as $i => $cor) {
            $v = EstruturaProdutoVariacao::create([
                'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => $i + 1,
                'codigo' => count($cores) > 1 ? "{$codigo}-".($i + 1) : $codigo,
                'eixo' => $cor !== null ? 'cor' : null, 'valor' => $cor, 'custo' => 100 + 10 * $i,
            ]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 1, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 5]);
            $this->fotos($empresa, $v, 3);
            $variacoes[] = $v;
            $simples[] = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => $v->id, 'sku' => $v->codigo,
                'fase' => EstruturaOferta::FASE_SIMPLES, 'nome' => trim("{$nome} {$cor}")]);
        }

        return ['produto' => $produto->fresh(), 'variacoes' => $variacoes, 'simples' => $simples];
    }

    private function fotos(Company $empresa, EstruturaProdutoVariacao $v, int $quantas): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            EstruturaProdutoVariacaoImagem::create([
                'company_id' => $empresa->id, 'produto_id' => $v->produto_id, 'variacao_id' => $v->id,
                'caminho' => 'estrutura/'.Str::random(8).'.jpg', 'nome_original' => "foto{$i}.jpg", 'mime' => 'image/jpeg',
                'tamanho' => 1000, 'ordem' => $i,
            ]);
        }
    }

    private function atributo(Company $empresa, EstruturaProduto $p, string $id, ?string $valor, ?string $valorId = null, ?string $unidade = null): void
    {
        EstruturaProdutoAtributo::create(['company_id' => $empresa->id, 'produto_id' => $p->id, 'atributo_id' => $id,
            'atributo_nome' => $id, 'valor' => $valor, 'valor_id' => $valorId, 'unidade' => $unidade]);
    }

    /**
     * Preenche os obrigatórios da ficha da categoria (como valem para o produto), menos os de `$fora`.
     *
     * @param  list<string>  $eixos
     * @param  list<string>  $fora
     */
    private function preencherFicha(Company $empresa, EstruturaProduto $p, CategorySchema $schema, array $eixos, array $fora = []): void
    {
        $campos = FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto(FichaTecnicaDaCategoria::daAtributos($schema->atributos), $eixos));
        foreach ($campos as $id => $campo) {
            if (! $campo['obrigatorio'] || $id === 'MODEL' || in_array($id, $fora, true)) {
                continue;
            }
            $opcao = $campo['valores'][0] ?? null;
            $this->atributo($empresa, $p, (string) $id, $opcao['nome'] ?? '3', $opcao !== null ? (string) $opcao['id'] : null);
        }
    }

    private function quantidadesDeCombo(Company $empresa, EstruturaProduto $p, string $quantidades): void
    {
        EstruturaProdutoGeracao::create(['produto_id' => $p->id, 'company_id' => $empresa->id, 'tipo_id' => null, 'qtd_combo' => $quantidades, 'qtd_combit' => null]);
    }

    /** @param  list<array{0: EstruturaOferta, 1: int}>  $componentes */
    private function composta(Company $empresa, string $fase, string $sku, string $nome, array $componentes): EstruturaOferta
    {
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => $sku, 'fase' => $fase, 'nome' => $nome]);
        foreach ($componentes as [$componente, $quantidade]) {
            EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $componente->id, 'quantidade' => $quantidade]);
        }

        return $oferta;
    }

    private function anuncioDaRegua(EstruturaOferta $oferta, string $tipo, ?string $mlb, string $status = 'ativo'): void
    {
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => $tipo, 'status' => $status, 'codigo_mlb' => $mlb]);
    }

    /** O pub_produto do grupo das cores, como o Sincronizar cria (ancorado na oferta da 1ª cor). */
    private function pubDoGrupo(Company $empresa, array $p): PubProduto
    {
        return PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $p['simples'][0]->id, 'estrutura_produto_id' => $p['produto']->id,
            'sku' => (string) $p['produto']->codigo, 'nome' => $p['produto']->nome, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    private function rascunho(PubProduto $pub, string $status = PubRascunho::DRAFT, array $stepState = [], int $revisao = 1): PubRascunho
    {
        return PubRascunho::create(['produto_id' => $pub->id, 'status' => $status, 'revisao' => $revisao, 'step_state' => $stepState]);
    }

    /** Produto completo (mapeado) com o grupo no Publicador e um rascunho em DRAFT. */
    private function comRascunho(Company $empresa, string $nome, string $codigo, array $stepState = [], int $revisao = 1): array
    {
        $p = $this->produto($empresa, $nome, $codigo);
        $p['rascunho'] = $this->rascunho($this->pubDoGrupo($empresa, $p), PubRascunho::DRAFT, $stepState, $revisao);

        return $p;
    }

    private function publicado(PubRascunho $r, string $listingType, string $mlb, ?array $estado = null, string $status = PubPublicacaoItem::CREATED): void
    {
        $publicacao = PubPublicacao::create(['rascunho_id' => $r->id, 'revisao' => (int) $r->revisao, 'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);
        PubPublicacaoItem::create(['publicacao_id' => $publicacao->id, 'indice' => 0, 'listing_type_id' => $listingType, 'variante_chave' => 'unica',
            'status' => $status, 'ml_item_id' => $mlb, 'payload' => [], 'avisos' => $estado !== null ? ['estado' => $estado] : null]);
    }

    /** Uma etapa do preparo pela IA, registrada há `$minutos`. */
    private function etapa(string $status, int $minutos): array
    {
        return ['status' => $status, 'em' => now()->subMinutes($minutos)->toIso8601String()];
    }

    /** O resumo do que falta, como o editor grava (`EditorRascunhoService::gravarResumo`). */
    private function resumo(int $bloqueios, int $revisao, bool $validado = true): array
    {
        return ['bloqueios' => $bloqueios, 'revisao' => $revisao, 'por_cor' => [], 'validado' => $validado, 'itens' => [],
            'versao' => EditorRascunhoService::VERSAO_DO_RESUMO];
    }

    /**
     * `$n` produtos iguais na forma: 2 cores, ficha completa na categoria guardada, Combo 2 das duas cores, um Kit
     * com o vizinho, o grupo no Publicador com rascunho (preparo, resumo e kit de imagens), uma publicação e um
     * anúncio na régua — para a contagem de consultas comparar catálogos do mesmo jeito e tamanhos diferentes.
     */
    private function catalogoCompleto(Company $empresa, int $n, CategorySchema $schema): void
    {
        $kitDeImagem = MlAnuncioCriativoKit::create(['token' => Str::random(32), 'status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
        $anterior = null;
        for ($i = 1; $i <= $n; $i++) {
            $codigo = "C{$empresa->id}-{$i}";
            $p = $this->produto($empresa, "Peça {$i}", $codigo, ['Natural', 'Preto'], ['categoria_ml_id' => self::CADEIRA]);
            $this->preencherFicha($empresa, $p['produto'], $schema, ['cor']);
            foreach ($p['simples'] as $s) {
                $this->composta($empresa, 'combo', "{$s->sku}-CB2", "Combo 2 {$s->nome}", [[$s, 2]]);
            }
            if ($anterior !== null && $i % 2 === 0) {
                $kit = $this->composta($empresa, 'kit', "KT-{$codigo}", "Kit {$i}", [[$anterior['simples'][0], 1], [$p['simples'][0], 1]]);
                $this->rascunho(PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $kit->id, 'sku' => "KT-{$codigo}",
                    'nome' => "Kit {$i}", 'origem' => PubProduto::ORIGEM_PORTAL]), PubRascunho::DRAFT, ['resumo' => $this->resumo(0, 1)]);
            }
            $r = $this->rascunho($this->pubDoGrupo($empresa, $p), PubRascunho::PUBLISHED, [
                'ia_preparo'     => ['hash' => 'h', 'etapas' => ['titulo' => $this->etapa('ok', 50), 'modelo' => $this->etapa('ok', 50), 'descricao' => $this->etapa('ok', 50)]],
                'resumo'         => $this->resumo(0, 1),
                'criativos_auto' => ['grupos' => ['geral' => ['hash' => 'x', 'kit_id' => $kitDeImagem->id, 'em' => now()->toIso8601String()]]],
            ]);
            $this->publicado($r, 'gold_special', "MLB{$empresa->id}0{$i}");
            $this->anuncioDaRegua($p['simples'][1], 'premium', "MLB{$empresa->id}9{$i}");
            $anterior = $p;
        }
    }

    private function produtoDaTrilha(array $trilha, int $id): ?array
    {
        foreach ($trilha['produtos'] as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    private function oferta(array $trilha, string $id): ?array
    {
        foreach ($trilha['ofertas'] as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }

        return null;
    }
}
