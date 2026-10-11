<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\ConjuntoLogistico;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\PortalEquipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * "Montar kit" no Planejamento (09/10/2026): o colaborador, em reunião com o cliente, junta os
 * produtos à mão. A prévia só calcula (fase, nome/SKU do padrão do Planejamento, "já existe",
 * logística do pacote SOMADO, frete estimado sem ML, custo e "Terá estoque?"); gravar cria
 * pela regra da Lista SKUs, com quem fez no registro. A empresa vem sempre da sessão.
 */
class MontarKitAMaoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    /** @var array{produtos: array<string,int>, variacoes: array<string,int>, ofertas: array<string,int>} */
    private array $cat;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->empresa = $this->empresaDoGabarito();
        $this->cat = $this->catalogoSintetico($this->empresa, $this->atorCliente($this->empresa));
    }

    /** @param  array<string,int>  $porCodigo  código da variação => quantidade */
    private function componentes(array $porCodigo): array
    {
        $saida = [];
        foreach ($porCodigo as $codigo => $q) {
            $saida[] = ['variacao_id' => $this->cat['variacoes'][$codigo], 'quantidade' => $q];
        }

        return $saida;
    }

    private function previa(array $corpo, ?Company $empresa = null)
    {
        return $this->entrarNoPortal($empresa ?? $this->empresa)
            ->postJson(route('portal.auth.estrutura.sugestoes.montar.previa'), $corpo);
    }

    private function montar(array $corpo, ?Company $empresa = null)
    {
        return $this->entrarNoPortal($empresa ?? $this->empresa)
            ->postJson(route('portal.auth.estrutura.sugestoes.montar'), $corpo);
    }

    /** A sugestão que o gerador daria para a mesma composição (para comparar nome e SKU). */
    private function sugestaoDoGerador(array $porCodigo): ?array
    {
        $mapa = [];
        foreach ($porCodigo as $codigo => $q) {
            $mapa[$this->cat['variacoes'][$codigo]] = $q;
        }
        $chave = ChaveDeComposicao::de($mapa);

        return collect(app(SugestoesService::class)->gerar($this->empresa)['sugestoes'])->firstWhere('chave', $chave);
    }

    // ═══ Prévia: fase, nome e SKU ════════════════════════════════════════════

    public function test_a_previa_deduz_combo_kit_e_combit_e_sugere_o_nome_do_planejamento(): void
    {
        $combo = $this->previa(['componentes' => $this->componentes(['V201' => 4])])->assertOk();
        $combo->assertJson(['pronto' => true, 'fase' => 'combo', 'ja_existe' => null,
            'sugerido' => ['nome' => 'Combo 4 Cadeiras Polo — Natural', 'sku' => 'V201-CB4']]);

        $kit = $this->previa(['componentes' => $this->componentes(['V201' => 1, 'V101' => 1])])->assertOk();
        // A ordem da escolha não importa: a mesa (tipo de ordem menor) vem primeiro, como no gerador.
        $kit->assertJson(['fase' => 'kit', 'sugerido' => ['nome' => 'Mesa Polo + Cadeira Polo — Natural', 'sku' => 'KT-V101-V201']]);
        $this->assertSame(['V101', 'V201'], array_column($kit->json('itens'), 'sku'));

        $combit = $this->previa(['componentes' => $this->componentes(['V201' => 4, 'V101' => 1])])->assertOk();
        $combit->assertJson(['fase' => 'combit', 'sugerido' => ['nome' => 'Mesa Polo + 4 Cadeiras — Natural', 'sku' => 'CT4-V101-V201']]);

        // O padrão é o MESMO das sugestões: a montada à mão nasce com o nome da sugerida.
        foreach ([['V201' => 4], ['V101' => 1, 'V201' => 1], ['V101' => 1, 'V201' => 4]] as $comp) {
            $s = $this->sugestaoDoGerador($comp);
            $this->assertNotNull($s, 'o catálogo sintético sugere esta composição');
            $p = $this->previa(['componentes' => $this->componentes($comp)])->json();
            $this->assertSame([$s['nome'], $s['sku']], [$p['sugerido']['nome'], $p['sugerido']['sku']]);
        }
    }

    /**
     * A fase deduzida é a ÚNICA que a regra da Lista SKUs (`EstruturaOfertaService::composicao`) aceita
     * para a composição: se as duas regras divergirem, este teste acusa.
     */
    public function test_a_fase_deduzida_e_a_que_a_lista_skus_aceita(): void
    {
        $o = $this->cat['ofertas'];
        $svc = app(EstruturaOfertaService::class);
        $ator = $this->atorCliente($this->empresa);
        $casos = [
            [[$o['V201'] => 3]],
            [[$o['V101'] => 1, $o['V201'] => 1]],
            [[$o['V101'] => 1, $o['V201'] => 6]],
            [[$o['V201'] => 2, $o['V301'] => 2]],
            [[$o['V101'] => 1, $o['V201'] => 1, $o['V301'] => 1, $o['V401'] => 1]],
        ];

        foreach ($casos as $n => [$mapa]) {
            $fase = \App\Services\Portal\Estrutura\Geracao\RegrasDaMontagem::fase(array_values($mapa));
            $componentes = array_map(fn ($id, $q) => ['id' => $id, 'quantidade' => $q], array_keys($mapa), $mapa);
            foreach (['combo', 'kit', 'combit'] as $tentativa) {
                try {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($svc, $ator, $tentativa, $componentes, $n) {
                        $svc->criar($this->empresa, ['sku' => "T{$n}-{$tentativa}", 'fase' => $tentativa, 'componentes' => $componentes], $ator);
                        throw new \RuntimeException('aceitou');
                    });
                } catch (\RuntimeException) {
                    $this->assertSame($fase, $tentativa, "caso {$n}: a Lista aceitou {$tentativa}");
                } catch (\Illuminate\Validation\ValidationException) {
                    $this->assertNotSame($fase, $tentativa, "caso {$n}: a Lista recusou a fase deduzida {$tentativa}");
                }
            }
        }
    }

    public function test_um_item_so_com_uma_unidade_ainda_nao_e_oferta(): void
    {
        $r = $this->previa(['componentes' => $this->componentes(['V101' => 1])])->assertOk();
        $r->assertJson(['pronto' => false, 'fase' => null, 'sugerido' => null]);
        $this->assertStringContainsString('aumente a quantidade', $r->json('mensagem'));

        $this->montar(['componentes' => $this->componentes(['V101' => 1])])
            ->assertStatus(422)->assertJsonValidationErrors('componentes');
        $this->previa(['componentes' => []])->assertOk()->assertJson(['pronto' => false]);
    }

    public function test_tres_ou_mais_componentes_ate_seis(): void
    {
        $r = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4, 'V301' => 2])])->assertOk();
        $r->assertJson(['fase' => 'combit', 'sugerido' => ['nome' => 'Mesa Polo + 4 Cadeiras + 2 Bancos — Natural', 'sku' => 'CT-V101-V201x4-V301x2']]);
        // O banco não tem medidas: o conjunto fica pendente, com o nome de quem falta.
        $this->assertSame('pendente', $r->json('logistica.chave'));
        $this->assertSame(['Banco Polo'], array_column($r->json('logistica.sem_medida'), 'nome'));

        $kit3 = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1, 'V301' => 1])])->json();
        $this->assertSame(['kit', 'Mesa Polo + Cadeira Polo + Banco Polo — Natural', 'KT-V101-V201-V301'], [$kit3['fase'], $kit3['sugerido']['nome'], $kit3['sugerido']['sku']]);

        $seis = ['V101' => 1, 'V201' => 4, 'V301' => 1, 'V401' => 2, 'V701' => 1, 'V1001' => 1];
        $criada = $this->montar(['componentes' => $this->componentes($seis)])->assertOk();
        $oferta = EstruturaOferta::findOrFail($criada->json('oferta.id'));
        $this->assertSame('combit', $oferta->fase);
        $this->assertCount(6, $oferta->componentes()->get());

        $sete = $this->componentes($seis + ['V102' => 1]);
        $this->previa(['componentes' => $sete])->assertStatus(422)->assertJsonValidationErrors('componentes');
        $this->montar(['componentes' => $sete])->assertStatus(422);
    }

    /** A chave de 4 a 6 componentes passa na validação do descarte, da restauração e da cotação (teto subiu de 3 para 6). */
    public function test_descarte_restaurar_e_cotar_aceitam_chave_de_mais_de_tres(): void
    {
        $quatro = ChaveDeComposicao::de([
            $this->cat['variacoes']['V101'] => 1, $this->cat['variacoes']['V201'] => 4,
            $this->cat['variacoes']['V301'] => 1, $this->cat['variacoes']['V401'] => 2,
        ]);
        $sessao = $this->entrarNoPortal($this->empresa);

        // Não é sugestão do gerador: descartar não grava nada, restaurar e cotar não quebram.
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.descartar'), ['chaves' => [$quatro]])->assertOk()->assertJson(['descartadas' => 0]);
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.restaurar'), ['chaves' => [$quatro]])->assertOk()->assertJson(['restauradas' => 0]);
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.frete'), ['chaves' => [$quatro]])->assertOk()->assertJson(['fretes' => []]);

        // Kit de 3 do gerador (com o par cadeira + banco na lista, mesa + cadeira + banco sai) continua
        // descartável, restaurável e cotável.
        $ids = \App\Models\EstruturaTipoProduto::pluck('id', 'slug');
        [$a, $b] = $ids['cadeira'] <= $ids['banco'] ? ['cadeira', 'banco'] : ['banco', 'cadeira'];
        \App\Models\EstruturaTipoPar::create(['tipo_a_id' => $ids[$a], 'tipo_b_id' => $ids[$b], 'combit_repete' => null]);
        $tres = collect(app(SugestoesService::class)->gerar($this->empresa)['sugestoes'])->first(fn ($s) => count($s['itens']) === 3);
        $this->assertNotNull($tres, 'o catálogo sintético com o par cadeira + banco sugere um Kit de 3');
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.descartar'), ['chaves' => [$tres['chave']]])->assertOk()->assertJson(['descartadas' => 1]);
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.restaurar'), ['chaves' => [$tres['chave']]])->assertOk()->assertJson(['restauradas' => 1]);
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.frete'), ['chaves' => [$tres['chave']]])->assertOk()->assertJsonStructure(['fretes', 'conectado']);
    }

    // ═══ Duplicata ═══════════════════════════════════════════════════════════

    public function test_composicao_que_ja_existe_responde_o_sku_e_nao_grava(): void
    {
        app(EstruturaOfertaService::class)->criar($this->empresa, ['sku' => 'MEU-KIT', 'fase' => 'kit', 'componentes' => [
            ['id' => $this->cat['ofertas']['V101'], 'quantidade' => 1], ['id' => $this->cat['ofertas']['V201'], 'quantidade' => 1],
        ]], $this->atorCliente($this->empresa));
        $antes = EstruturaOferta::where('company_id', $this->empresa->id)->count();

        $this->previa(['componentes' => $this->componentes(['V201' => 1, 'V101' => 1])])
            ->assertOk()->assertJson(['ja_existe' => ['sku' => 'MEU-KIT']]);
        $this->montar(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1])])
            ->assertStatus(422)->assertJsonValidationErrors(['componentes' => 'Essa combinação já existe: SKU MEU-KIT.']);

        // Mesmos itens, outra quantidade: é outra composição.
        $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 2])])->assertOk()->assertJson(['ja_existe' => null, 'fase' => 'combit']);
        $this->assertSame($antes, EstruturaOferta::where('company_id', $this->empresa->id)->count());
    }

    /** O kit da Fase N do Publicador já é o Combo N de cada cor (RetratoDoCatalogo, 09/10/2026). */
    public function test_o_kit_da_fase_n_conta_como_existente(): void
    {
        $base = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-POLO', 'nome' => 'Cadeira Polo', 'origem' => PubProduto::ORIGEM_PORTAL,
            'estrutura_produto_id' => $this->cat['produtos']['Cadeira Polo'], 'oferta_id' => $this->cat['ofertas']['V201']]);
        PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-POLO-KIT4', 'nome' => 'Kit 4 Cadeira Polo', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => 4, 'fase' => 2]);

        foreach (['V201', 'V202'] as $cor) {
            $this->previa(['componentes' => $this->componentes([$cor => 4])])->assertOk()->assertJson(['ja_existe' => ['sku' => 'CAD-POLO-KIT4']]);
        }
        $this->previa(['componentes' => $this->componentes(['V201' => 2])])->assertOk()->assertJson(['ja_existe' => null]);
    }

    // ═══ Logística, frete, custo e estoque ═══════════════════════════════════

    public function test_logistica_do_pacote_somado_e_frete_estimado_sem_ml(): void
    {
        $r = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4])])->assertOk()->json();

        $conjunto = [
            ['produto_id' => 1, 'produto_nome' => 'Mesa', 'quantidade' => 1, 'volumes' => [['c' => 160, 'l' => 90, 'a' => 15, 'kg' => 40]], 'custo' => 300.0],
            ['produto_id' => 2, 'produto_nome' => 'Cadeira', 'quantidade' => 4, 'volumes' => [['c' => 50, 'l' => 50, 'a' => 20, 'kg' => 6]], 'custo' => 80.0],
        ];
        $esperado = ConjuntoLogistico::avaliar($conjunto);
        $this->assertSame($esperado['logistica'], $r['logistica']['chave']);
        $this->assertEquals($esperado['pacote'], $r['logistica']['pacote']);
        $this->assertEqualsWithDelta(620.0, $r['custo'], 0.001);
        $frete = app(FreteMe2Service::class)->estimar($this->empresa, ['x' => [
            'pacote' => $esperado['pacote'], 'peso_faturado' => $esperado['peso_faturado'], 'logistica' => $esperado['logistica'], 'custo' => 620.0,
        ]])['x'];
        $this->assertEquals($frete, $r['frete']);

        foreach (Http::recorded() as [$req]) {
            $this->assertDoesNotMatchRegularExpression('/mercadoli(vre|bre)\.com/i', $req->url(), 'a prévia não vai ao ML');
        }
    }

    public function test_tera_estoque_e_o_menor_estoque_dividido_pela_quantidade(): void
    {
        $estoque = fn (string $codigo, ?int $n) => EstruturaProdutoVariacao::where('id', $this->cat['variacoes'][$codigo])->update(['estoque' => $n]);
        $estoque('V101', 3);
        $estoque('V201', 10);

        $r = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4])])->json('estoque');
        $this->assertSame(2, $r['unidades']);
        $this->assertSame(['nome' => 'Cadeira Polo — Natural', 'estoque' => 10, 'por_unidade' => 4], $r['limitante']);

        $estoque('V201', null);
        $r = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4])])->json('estoque');
        $this->assertNull($r['unidades']);
        $this->assertSame(['Cadeira Polo — Natural'], $r['sem_informacao']);

        // Um componente que não monta nenhuma decide, mesmo com outro sem estoque informado.
        $estoque('V101', 0);
        $r = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4])])->json('estoque');
        $this->assertSame(0, $r['unidades']);
        $this->assertSame('Mesa Polo — Natural', $r['limitante']['nome']);
    }

    // ═══ Gravação ════════════════════════════════════════════════════════════

    public function test_gravar_cria_pela_regra_da_lista_com_nome_e_sku_editados_e_registra_o_cliente(): void
    {
        $r = $this->montar(['componentes' => $this->componentes(['V201' => 4, 'V101' => 1]), 'nome' => 'Conjunto Jantar Polo', 'sku' => 'CJ-POLO-4'])
            ->assertOk();

        $r->assertJson(['oferta' => ['sku' => 'CJ-POLO-4', 'nome' => 'Conjunto Jantar Polo', 'fase' => 'combit']]);
        $this->assertSame(route('portal.auth.estrutura.precificacao', ['q' => 'CJ-POLO-4']), $r->json('precificar_url'));

        $oferta = EstruturaOferta::findOrFail($r->json('oferta.id'));
        $this->assertSame($this->empresa->id, (int) $oferta->company_id);
        $this->assertNull($oferta->variacao_id);
        $this->assertSame(
            [$this->cat['ofertas']['V101'] => 1, $this->cat['ofertas']['V201'] => 4],
            $oferta->componentes()->orderBy('componente_id')->pluck('quantidade', 'componente_id')->all()
        );

        $log = Activity::query()->where('properties->evento', 'oferta_montada')->latest('id')->firstOrFail();
        $this->assertSame('cliente', $log->properties['origem']);
        $this->assertSame('planejamento', $log->properties['via']);
        $this->assertTrue($log->properties['nome_editado']);

        // Agora é existente: sai das sugestões do Planejamento e a prévia diz "já existe".
        $this->assertNull($this->sugestaoDoGerador(['V101' => 1, 'V201' => 4]));
        $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4])])->assertJson(['ja_existe' => ['sku' => 'CJ-POLO-4']]);
    }

    public function test_sem_nome_e_sku_vale_o_sugerido_e_a_equipe_fica_registrada(): void
    {
        $admin = User::create(['name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true]);
        $ticket = app(PortalEquipeService::class)->emitir($admin, $this->empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]));

        $this->postJson(route('portal.auth.estrutura.sugestoes.montar'), ['componentes' => $this->componentes(['V201' => 4])])
            ->assertOk()->assertJson(['oferta' => ['sku' => 'V201-CB4', 'nome' => 'Combo 4 Cadeiras Polo — Natural', 'fase' => 'combo']]);

        $log = Activity::query()->where('properties->evento', 'oferta_montada')->latest('id')->firstOrFail();
        $this->assertSame('interno', $log->properties['origem']);
        $this->assertFalse($log->properties['nome_editado']);
    }

    public function test_sku_repetido_avisa_e_sku_longo_bloqueia(): void
    {
        $p = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1]), 'sku' => 'v101'])->assertOk();
        $p->assertJson(['sku' => 'v101', 'sku_repetido' => true]);

        $longo = str_repeat('S', 121);
        $p = $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1]), 'sku' => $longo, 'nome' => str_repeat('n', 61)])->assertOk();
        $this->assertEqualsCanonicalizing(['titulo_longo', 'sku_longo'], array_column($p->json('avisos'), 'codigo'));
        $this->montar(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1]), 'sku' => $longo])->assertStatus(422);
    }

    /** Sigilo do Portal: nada do que a prévia e a gravação devolvem fala da plataforma. */
    public function test_as_respostas_nao_falam_da_plataforma(): void
    {
        $proibido = '/mercado\s*livre|an[uú]ncio|public(ar|a[cç][aã]o|ador)|\bMLB\b/iu';
        $respostas = [
            $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4, 'V301' => 2])])->getContent(),
            $this->previa(['componentes' => $this->componentes(['V101' => 1])])->getContent(),
            $this->previa(['componentes' => [['variacao_id' => $this->cat['variacoes']['V203'], 'quantidade' => 2]]])->getContent(),
            $this->montar(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1])])->getContent(),
            $this->montar(['componentes' => $this->componentes(['V101' => 1, 'V201' => 1])])->getContent(),   // já existe
        ];
        foreach ($respostas as $json) {
            $this->assertDoesNotMatchRegularExpression($proibido, json_encode(json_decode($json, true), JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_a_previa_nao_grava_nada(): void
    {
        // (entrar no portal cria a pessoa de teste, com o registro dela: só o do módulo conta)
        $registros = fn () => Activity::query()->where('properties->modulo', 'estrutura')->count();
        $antes = [EstruturaOferta::count(), $registros()];
        $this->previa(['componentes' => $this->componentes(['V101' => 1, 'V201' => 4])])->assertOk();
        $this->assertSame($antes, [EstruturaOferta::count(), $registros()]);
    }

    // ═══ Oferta sem produto (importada) ══════════════════════════════════════

    public function test_oferta_sem_variacao_entra_pelo_id_da_oferta(): void
    {
        $ator = $this->atorCliente($this->empresa);
        [$a] = app(EstruturaOfertaService::class)->criar($this->empresa, ['sku' => 'AV-MESA', 'fase' => 'simples', 'nome' => 'Mesa Antiga'], $ator);
        [$b] = app(EstruturaOfertaService::class)->criar($this->empresa, ['sku' => 'AV-BANCO', 'fase' => 'simples', 'nome' => 'Banco Antigo'], $ator);
        EstruturaPrecificacao::create(['oferta_id' => $a->id, 'custo' => 100]);

        $corpo = ['componentes' => [['oferta_id' => $a->id, 'quantidade' => 1], ['oferta_id' => $b->id, 'quantidade' => 2]]];
        $r = $this->previa($corpo)->assertOk();
        $r->assertJson(['fase' => 'combit', 'sugerido' => ['nome' => 'Mesa Antiga + 2 Banco Antigo', 'sku' => 'CT2-AV-MESA-AV-BANCO']]);
        $this->assertSame('pendente', $r->json('logistica.chave'));
        $this->assertSame([null, null], array_column($r->json('logistica.sem_medida'), 'id'), 'sem produto, sem link de ficha');
        $this->assertNull($r->json('custo'), 'falta o custo de um dos itens');
        $this->assertNull($r->json('estoque.unidades'));

        $this->montar($corpo)->assertOk();
        $this->previa($corpo)->assertJson(['ja_existe' => ['sku' => 'CT2-AV-MESA-AV-BANCO']]);

        // Misturado com variação, pela oferta da variação também.
        $misto = ['componentes' => [['oferta_id' => $this->cat['ofertas']['V101'], 'quantidade' => 1], ['oferta_id' => $a->id, 'quantidade' => 1]]];
        $this->previa($misto)->assertOk()->assertJson(['fase' => 'kit']);
        $this->assertSame([$this->cat['variacoes']['V101'], null], array_column($this->previa($misto)->json('itens'), 'variacao_id'));
    }

    public function test_variacao_sem_oferta_nao_entra(): void
    {
        $this->previa(['componentes' => [['variacao_id' => $this->cat['variacoes']['V203'], 'quantidade' => 2]]])
            ->assertStatus(422)->assertJsonValidationErrors(['componentes' => 'A variação V203 ainda não tem oferta. Salve o produto e tente de novo.']);
    }

    // ═══ Isolamento e validação ══════════════════════════════════════════════

    public function test_item_de_outra_empresa_responde_como_inexistente_e_nada_e_criado(): void
    {
        $outra = $this->empresaDoGabarito();
        $alheio = $this->catalogoSintetico($outra, $this->atorCliente($outra));
        $antes = [EstruturaOferta::where('company_id', $this->empresa->id)->count(), EstruturaOferta::where('company_id', $outra->id)->count()];

        foreach ([
            [['variacao_id' => $alheio['variacoes']['V101'], 'quantidade' => 1], ['variacao_id' => $this->cat['variacoes']['V201'], 'quantidade' => 1]],
            [['oferta_id' => $alheio['ofertas']['V101'], 'quantidade' => 1], ['variacao_id' => $this->cat['variacoes']['V201'], 'quantidade' => 1]],
            [['variacao_id' => 999999, 'quantidade' => 2]],
        ] as $componentes) {
            $this->previa(['componentes' => $componentes])->assertStatus(422)->assertJsonValidationErrors(['componentes' => 'Escolha produtos da sua lista.']);
            $this->montar(['componentes' => $componentes])->assertStatus(422);
        }

        // `company_id` no corpo não muda a empresa.
        $this->montar(['company_id' => $outra->id, 'componentes' => $this->componentes(['V101' => 1, 'V201' => 1])])->assertOk();
        $this->assertSame([$antes[0] + 1, $antes[1]], [EstruturaOferta::where('company_id', $this->empresa->id)->count(), EstruturaOferta::where('company_id', $outra->id)->count()]);
    }

    public function test_validacao_do_corpo(): void
    {
        $rota = route('portal.auth.estrutura.sugestoes.montar.previa');
        $sessao = $this->entrarNoPortal($this->empresa);

        $sessao->postJson($rota, [])->assertStatus(422);
        $sessao->postJson($rota, ['componentes' => [['quantidade' => 1]]])->assertStatus(422);
        $sessao->postJson($rota, ['componentes' => [['variacao_id' => $this->cat['variacoes']['V101'], 'quantidade' => 0]]])->assertStatus(422);
        $sessao->postJson($rota, ['componentes' => [['variacao_id' => $this->cat['variacoes']['V101'], 'quantidade' => 1000]]])->assertStatus(422);
        $sessao->postJson($rota, ['componentes' => [['variacao_id' => 'abc', 'quantidade' => 1]]])->assertStatus(422);
        $sessao->postJson($rota, ['componentes' => $this->componentes(['V101' => 1, 'V201' => 1]), 'nome' => str_repeat('n', 256)])->assertStatus(422);
        $mesmo = $this->cat['variacoes']['V101'];
        $sessao->postJson($rota, ['componentes' => [['variacao_id' => $mesmo, 'quantidade' => 1], ['oferta_id' => $this->cat['ofertas']['V101'], 'quantidade' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['componentes' => 'O mesmo item aparece duas vezes. Some as quantidades numa linha só.']);
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.montar'), [])->assertStatus(422)->assertJsonValidationErrors('componentes');
    }

    public function test_sem_sessao_manda_para_a_entrada(): void
    {
        $this->post(route('portal.auth.estrutura.sugestoes.montar.previa'), ['componentes' => []])->assertRedirect();
        $this->post(route('portal.auth.estrutura.sugestoes.montar'), ['componentes' => []])->assertRedirect();
    }

    // ═══ Catálogo da janela ══════════════════════════════════════════════════

    /** O botão "Montar kit" aparece quando há o que juntar — até para quem só tem ofertas importadas. */
    public function test_montar_disponivel_com_qualquer_oferta_simples(): void
    {
        $this->withoutVite()->entrarNoPortal($this->empresa)->get(route('portal.auth.estrutura.sugestoes'))
            ->assertInertia(fn ($page) => $page->where('montar_disponivel', true));

        $vazia = $this->empresaDoGabarito();
        $this->app['auth']->forgetGuards();
        $this->withoutVite()->entrarNoPortal($vazia)->get(route('portal.auth.estrutura.sugestoes'))
            ->assertInertia(fn ($page) => $page->where('montar_disponivel', false)->where('sugestoes.tem_produtos', false));

        app(EstruturaOfertaService::class)->criar($vazia, ['sku' => 'IMP-1', 'fase' => 'simples'], $this->atorCliente($vazia));
        $this->app['auth']->forgetGuards();
        $this->withoutVite()->entrarNoPortal($vazia)->get(route('portal.auth.estrutura.sugestoes'))
            ->assertInertia(fn ($page) => $page->where('montar_disponivel', true)->where('sugestoes.tem_produtos', false));
    }

    public function test_o_catalogo_vem_so_quando_a_janela_pede(): void
    {
        $ator = $this->atorCliente($this->empresa);
        app(EstruturaOfertaService::class)->criar($this->empresa, ['sku' => 'AV-1', 'fase' => 'simples', 'nome' => 'Importada'], $ator);

        $r = $this->withoutVite()->entrarNoPortal($this->empresa)
            ->withHeaders([
                'X-Inertia'                   => 'true',
                'X-Inertia-Version'           => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Data'      => 'montagem',
                'X-Inertia-Partial-Component' => 'Portal/EstruturaSugestoes',
            ])
            ->get(route('portal.auth.estrutura.sugestoes'))
            ->assertOk();

        $montagem = $r->json('props.montagem');
        $this->assertSame(6, $montagem['max_componentes']);
        $porNome = collect($montagem['produtos'])->keyBy('nome');
        // Só variação com oferta simples (D-09): a Branco (V203) da cadeira fica de fora.
        $this->assertSame(['V201', 'V202'], array_column($porNome['Cadeira Polo']['variacoes'], 'sku'));
        $this->assertSame('Cadeira', $porNome['Cadeira Polo']['tipo_nome']);
        $this->assertSame(['AV-1'], array_column($montagem['avulsas'], 'sku'));
        $this->assertArrayNotHasKey('sugestoes', $r->json('props'));
    }
}
