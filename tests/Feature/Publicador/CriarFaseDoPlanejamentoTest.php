<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\PlanejamentoDaFaseService;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item D (decisões do usuário de 09/10/2026): o "Criar Fase N" do Publicador
 * puxa do Planejamento do Portal. Com as ofertas Combo N de cada cor, a prévia mostra e a criação
 * grava o SKU de cada cor = o da oferta (`-CB{N}`), o preço vem da Precificação delas e a quantidade
 * sugerida vem dos Combos aceitos. Sem as ofertas, a criação as CRIA no Portal (Combo por cor, pelo
 * caminho da Lista SKUs), na mesma transação do kit: idempotente e escopado pela empresa.
 * Base que não é agrupado segue a regra de antes (`-KIT{N}`, sem Portal).
 */
class CriarFaseDoPlanejamentoTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->montarPlanejamento();
    }

    private function urlPrevia(PubProduto $p, int $n = 2): string
    {
        return "/mlb/anuncios/publicador/empresas/empresa-{$this->mlbP->id}/produtos/{$p->id}/fases/previa?quantidade={$n}";
    }

    private function urlCriar(PubProduto $p): string
    {
        return "/mlb/anuncios/publicador/empresas/empresa-{$this->mlbP->id}/produtos/{$p->id}/fases";
    }

    private function previa(PubProduto $p, int $n = 2): array
    {
        return $this->actingAs($this->equipeP)->getJson($this->urlPrevia($p, $n))->assertOk()->json();
    }

    /** O Confirmar do painel: manda o que a prévia sugeriu, como `PainelCriarFase` faz. */
    private function criar(PubProduto $p, int $n = 2): PubProduto
    {
        $previa = $this->previa($p, $n);
        $seller = [];
        foreach ($previa['variantes'] as $chave => $v) {
            $seller[$chave] = $v['seller_sku'];
        }
        $id = $this->actingAs($this->equipeP)->postJson($this->urlCriar($p), [
            'quantidade' => $n, 'sku' => $previa['sku'], 'titulo' => null, 'descricao' => null, 'seller_skus' => $seller,
        ])->assertCreated()->json('produto.id');

        return PubProduto::findOrFail($id);
    }

    private function combos(?Company $empresa = null): \Illuminate\Support\Collection
    {
        return EstruturaOferta::where('company_id', ($empresa ?? $this->empresaP)->id)->where('fase', 'combo')->orderBy('id')->get();
    }

    /** @return array<string, string> cor → SKU da variante na prévia */
    private function skusDaPrevia(array $previa): array
    {
        $porSku = [];
        foreach ($previa['variantes'] as $v) {
            $porSku[$v['planejamento']['cor'] ?? '?'] = $v['seller_sku'];
        }

        return $porSku;
    }

    // ═══ Com as ofertas no Portal ════════════════════════════════════════════

    public function test_previa_com_os_combos_aceitos_mostra_sku_e_preco_de_cada_cor(): void
    {
        $grupo = $this->grupoComRascunho();
        $combos = $this->aceitarCombos(2);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0, 'cad-br-cb2' => 320.0]);

        $p = $this->previa($grupo);

        $this->assertSame('CAD-CB2', $p['sku'], 'o SKU do kit no padrão do Planejamento, não -KIT2');
        $this->assertSame(['Preto' => 'CAD-PT-CB2', 'Azul' => 'CAD-AZ-CB2', 'Branco' => 'CAD-BR-CB2'], $this->skusDaPrevia($p));
        $preto = collect($p['variantes'])->first(fn ($v) => $v['planejamento']['cor'] === 'Preto');
        $this->assertSame($combos['Preto']->id, $preto['planejamento']['oferta_id']);
        $this->assertFalse($preto['planejamento']['nova']);
        $this->assertEquals(['gold_special' => 300.0, 'gold_pro' => 320.0], $preto['planejamento']['precos'], 'o JSON entrega 300.0 como 300');
        $chaves = array_column($p['avisos'], 'chave');
        $this->assertContains('preco_do_planejamento', $chaves);
        $this->assertNotContains('preco_vazio', $chaves, 'o kit não nasce sem preço: ele vem da Precificação');
        $this->assertNotContains('ofertas_novas_no_portal', $chaves);
    }

    public function test_criar_com_os_combos_aceitos_grava_os_skus_das_ofertas_e_o_preco_vem_da_precificacao(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0, 'cad-br-cb2' => 320.0]);

        $kit = $this->criar($grupo);

        $this->assertSame('CAD-CB2', $kit->sku);
        $this->assertSame($grupo->id, $kit->produto_base_id);
        $this->assertSame(2, $kit->quantidade_kit);
        $this->assertNull($kit->oferta_id);
        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'));
        $this->assertSame('CAD-AZ-CB2', $this->skuDaCor($kit, 'Azul'));
        $this->assertSame('CAD-BR-CB2', $this->skuDaCor($kit, 'Branco'));
        $this->assertCount(3, $this->combos(), 'nenhuma oferta nova: as três já existiam');
        $this->assertSame(0, DB::table('pub_variante_precos')->whereNotNull('preco')->count(), 'o preço não é gravado no kit');
        $this->assertSame(
            ['cad-pt-cb2' => ['gold_special' => 300.0, 'gold_pro' => 320.0], 'cad-az-cb2' => ['gold_special' => 310.0, 'gold_pro' => 330.0], 'cad-br-cb2' => ['gold_special' => 320.0, 'gold_pro' => 340.0]],
            app(DadosEfetivosService::class)->daProduto($kit->fresh())['precos_por_variante'],
        );
    }

    // ═══ Sem as ofertas: a Fase N cria no Portal ═════════════════════════════

    public function test_previa_sem_combos_anuncia_as_ofertas_que_vao_nascer_no_portal(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);

        $p = $this->previa($grupo);

        $this->assertSame(['Preto' => 'CAD-PT-CB2', 'Azul' => 'CAD-AZ-CB2', 'Branco' => 'CAD-BR-CB2'], $this->skusDaPrevia($p));
        $this->assertTrue(collect($p['variantes'])->every(fn ($v) => $v['planejamento']['nova'] === true && $v['planejamento']['oferta_id'] === null));
        $aviso = collect($p['avisos'])->firstWhere('chave', 'ofertas_novas_no_portal');
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('CAD-AZ-CB2', $aviso['mensagem']);
        $this->assertCount(0, $this->combos(), 'a prévia não grava nada');
    }

    public function test_criar_sem_combos_cria_as_ofertas_combo_por_cor_no_portal_na_lista_skus(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);
        // O que o Planejamento do Portal sugere hoje para o Combo 2 de cada cor (nome e SKU).
        $sugeridas = [];
        foreach (app(SugestoesService::class)->gerar($this->empresaP)['sugestoes'] as $s) {
            if ($s['fase'] === 'combo' && $s['itens'][0]['quantidade'] === 2) {
                $sugeridas[$s['itens'][0]['variacao_id']] = ['nome' => $s['nome'], 'sku' => $s['sku']];
            }
        }
        $this->assertCount(3, $sugeridas, 'a cadeira tem Combo 2 no vocabulário da ECF');

        $kit = $this->criar($grupo);

        $combos = $this->combos();
        $this->assertSame(['CAD-PT-CB2', 'CAD-AZ-CB2', 'CAD-BR-CB2'], $combos->pluck('sku')->all());
        foreach (['Preto', 'Azul', 'Branco'] as $i => $cor) {
            $oferta = $combos[$i];
            $this->assertSame($sugeridas[$this->variacoesP[$cor]->id], ['nome' => $oferta->nome, 'sku' => $oferta->sku],
                'nome e SKU no padrão do Planejamento: o mesmo que a tela de sugestões mostraria');
            $this->assertNull($oferta->variacao_id, 'combo nunca é ligado a variação');
            $comp = $oferta->componentes()->get();
            $this->assertCount(1, $comp);
            $this->assertSame($this->simplesP[$cor]->id, $comp[0]->componente_id);
            $this->assertSame(2, $comp[0]->quantidade);
            $this->assertSame($oferta->sku, $this->skuDaCor($kit, $cor), 'a variante usa o SKU da oferta criada');
        }
        // O histórico do Portal registra a criação como da equipe (não do cliente).
        $registros = DB::table('activity_log')->where('log_name', 'portal')->where('description', 'like', 'Oferta CAD-%-CB2 criada%')->get();
        $this->assertCount(3, $registros);
        $this->assertTrue($registros->every(fn ($r) => json_decode($r->properties, true)['origem'] === 'interno'));
    }

    public function test_criar_cria_so_a_cor_que_falta_e_usa_a_que_o_cliente_ja_aceitou(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);
        // O cliente aceitou o Combo 2 Azul no Planejamento com outro SKU.
        $ator = \App\Support\Portal\AtorDoPortal::daEquipe($this->equipeP);
        [$azul] = app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class)->criar($this->empresaP, [
            'sku' => 'AZUL-DO-CLIENTE', 'fase' => 'combo', 'nome' => 'Combo 2 azul',
            'componentes' => [['id' => $this->simplesP['Azul']->id, 'quantidade' => 2]],
        ], $ator);

        $kit = $this->criar($grupo);

        $this->assertCount(3, $this->combos(), 'só Preto e Branco nasceram');
        $this->assertSame('AZUL-DO-CLIENTE', $this->skuDaCor($kit, 'Azul'));
        $this->assertSame($azul->id, EstruturaOferta::where('sku', 'AZUL-DO-CLIENTE')->value('id'));
    }

    public function test_segunda_criacao_do_mesmo_kit_e_recusada_e_nao_cria_oferta(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);
        $this->criar($grupo);

        $this->actingAs($this->equipeP)->postJson($this->urlCriar($grupo), ['quantidade' => 2, 'sku' => 'CAD-CB2'])
            ->assertStatus(422)->assertJsonPath('regra', 'KIT-04');

        $this->assertCount(3, $this->combos());
        $this->assertSame(1, PubProduto::where('produto_base_id', $grupo->id)->count());
    }

    public function test_se_o_kit_nao_nasce_as_ofertas_tambem_nao(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);
        PubProduto::creating(function (PubProduto $p) {
            if ($p->produto_base_id !== null) {
                throw new \RuntimeException('falha simulada ao criar o kit');
            }
        });

        $previa = $this->previa($grupo);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->equipeP)->postJson($this->urlCriar($grupo), ['quantidade' => 2, 'sku' => $previa['sku']]);
            $this->fail('a criação devia falhar');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada ao criar o kit', $e->getMessage());
        }

        $this->assertCount(0, $this->combos(), 'mesma transação: o rollback leva as ofertas do Portal junto');
        $this->assertSame(0, PubProduto::where('produto_base_id', $grupo->id)->count());
    }

    public function test_produto_de_uma_cor_so_casa_a_variante_unica_com_o_combo(): void
    {
        $puff = \App\Models\EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => 'PUFF', 'nome' => 'Puff Redondo']);
        $v = \App\Models\EstruturaProdutoVariacao::create(['produto_id' => $puff->id, 'company_id' => $this->empresaP->id, 'ordem' => 0,
            'codigo' => 'PUFF-AZ', 'eixo' => 'cor', 'valor' => 'Azul', 'custo' => 50]);
        $simples = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $v->id, 'sku' => 'PUFF-AZ', 'fase' => 'simples', 'nome' => 'Puff']);
        $combo = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'PUFF-AZ-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Puff']);
        $combo->componentes()->create(['componente_id' => $simples->id, 'quantidade' => 2]);
        $base = PubProduto::create(['estrutura_produto_id' => $puff->id, 'oferta_id' => $simples->id, 'company_id' => $this->empresaP->id,
            'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'PUFF', 'nome' => 'Puff Redondo', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $r = $this->repoP()->criar($base, [new \App\Support\Publicador\Payload\Alvo('gold_special', 'Puff Redondo Azul')]);
        $this->repoP()->gravarVariacao($r->fresh(), [], [new \App\Support\Publicador\Variacao\Variante(
            \App\Support\Publicador\Variacao\ChaveCanonica::UNICA, [], dados: ['estoque' => 8, 'atributos' => ['SELLER_SKU' => ['value_name' => 'PUFF-AZ']]])]);
        PubRascunho::whereKey($r->id)->update(['status' => PubRascunho::PUBLISHED, 'categoria_id' => 'MLB193945']);
        $this->precificacaoPorSku(['puff-az-cb2' => 150.0]);

        $p = $this->previa($base->fresh());

        $unica = $p['variantes'][\App\Support\Publicador\Variacao\ChaveCanonica::UNICA];
        $this->assertSame('PUFF-AZ-CB2', $unica['seller_sku']);
        $this->assertSame($combo->id, $unica['planejamento']['oferta_id']);
        $this->assertSame('PUFF-CB2', $p['sku']);
        $this->assertSame(4, $unica['estoque'], 'o estoque segue o floor(÷ N) do base');
    }

    // ═══ A quantidade sugerida vem dos Combos aceitos ════════════════════════

    public function test_quantidade_sugerida_e_a_do_planejamento_que_a_familia_ainda_nao_tem(): void
    {
        $grupo = $this->grupoComRascunho();
        $alvo = app(ProgramasPublicadorService::class)->resolver('empresa-'.$this->mlbP->id);
        $tela = fn () => app(FamiliaDeFasesService::class)->paraTela($grupo->fresh(), $alvo, app(ProgramasPublicadorService::class)->empresaParaTela($alvo, $grupo));

        $this->assertSame(2, $tela()['proxima_fase']['quantidade_sugerida'], 'sem Combos: a regra de sempre');
        $this->assertSame([], $tela()['proxima_fase']['quantidades_do_planejamento']);

        $this->aceitarCombos(4);
        $this->aceitarCombos(6, ['Preto']);
        $this->assertSame(4, $tela()['proxima_fase']['quantidade_sugerida']);
        $this->assertSame([4, 6], $tela()['proxima_fase']['quantidades_do_planejamento']);

        $this->kitAntigo($grupo, 4);
        $this->assertSame(6, $tela()['proxima_fase']['quantidade_sugerida'], 'o Kit 4 já existe: o próximo do Planejamento');
    }

    public function test_quantidade_sugerida_pura(): void
    {
        $this->assertSame(4, PlanejamentoDaFaseService::quantidadeSugerida([4, 2], [1, 2]));
        $this->assertSame(3, PlanejamentoDaFaseService::quantidadeSugerida([2], [1, 2]), 'tudo do Planejamento já existe: a próxima de sempre');
        $this->assertSame(2, PlanejamentoDaFaseService::quantidadeSugerida([], [1]));
    }

    // ═══ O que NÃO muda e o escopo ═══════════════════════════════════════════

    public function test_base_nao_agrupado_segue_o_kit_do_publicador(): void
    {
        $base = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'MANUAL',
            'nome' => 'Cadeira manual', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->rascunhoDeCores($base, ['Preto' => 'MANUAL-PT', 'Azul' => 'MANUAL-AZ'], 10);
        PubRascunho::where('produto_id', $base->id)->update(['status' => PubRascunho::PUBLISHED, 'categoria_id' => 'MLB193945']);
        $this->aceitarCombos(2);

        $p = $this->previa($base->fresh());

        $this->assertSame('MANUAL-KIT2', $p['sku']);
        $this->assertSame(['MANUAL-PT-KIT2', 'MANUAL-AZ-KIT2'], array_values(array_column($p['variantes'], 'seller_sku')));
        $this->assertContains('preco_vazio', array_column($p['avisos'], 'chave'));
        $this->assertTrue(collect($p['variantes'])->every(fn ($v) => ! array_key_exists('planejamento', $v)));
    }

    public function test_cria_ofertas_so_na_empresa_do_produto(): void
    {
        $outra = Company::factory()->create();
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);

        $this->criar($grupo);

        $this->assertCount(3, $this->combos());
        $this->assertCount(0, $this->combos($outra));
        $this->assertSame(0, EstruturaOferta::where('company_id', '!=', $this->empresaP->id)->count());
    }
}
