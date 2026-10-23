<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\PlanejamentoDaFaseService;
use App\Services\Publicador\PreviaDaFaseService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item D (decisões do usuário de 09/10/2026): a Fase N do produto do Portal
 * puxa do Planejamento. Com as ofertas Combo N de cada cor, a prévia mostra e a criação grava o SKU
 * de cada cor = o da oferta (`-CB{N}`), o preço vem da Precificação delas e a quantidade sugerida vem
 * dos Combos aceitos. Base que não é agrupado segue a regra de antes (`-KIT{N}`, sem Portal).
 *
 * Desde 20/10/2026 (decisão do usuário) Combo, Kit e Combit nascem SÓ no Planejamento: o Publicador
 * não cria oferta no Portal (a cor sem Combo N só ganha o SKU sugerido) e o "Criar Fase N" por HTTP
 * recusa produto do Portal (KIT-08) — quem cria a fase dele é a criação automática
 * (`CombosDoPlanejamentoTest`). Por isso a prévia e a criação aqui são chamadas no serviço.
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

    private function previa(PubProduto $p, int $n = 2): array
    {
        return app(PreviaDaFaseService::class)->previa($p->fresh(), $n);
    }

    /** A fase com o que a prévia sugeriu — o que o Confirmar do painel mandava e o que a criação automática manda. */
    private function criar(PubProduto $p, int $n = 2): PubProduto
    {
        $previa = $this->previa($p, $n);

        return app(CriarFaseService::class)->criar($p->fresh(), [
            'quantidade' => $n, 'sku' => $previa['sku'], 'titulo_por_tipo' => $previa['titulo_por_tipo'], 'descricao' => $previa['descricao'],
            'seller_skus' => array_map(fn (array $v) => $v['seller_sku'], $previa['variantes']),
            'estoque_por_variante' => array_map(fn (array $v) => ['estoque' => $v['estoque'], 'depositos' => $v['depositos']], $previa['variantes']),
            // Mesmo COM pessoa nada nasce no Portal (20/10/2026).
            'user' => $this->equipeP,
        ]);
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

    // ═══ Sem as ofertas: o Publicador NÃO cria no Portal (20/10/2026) ═══════════

    public function test_previa_sem_combos_avisa_as_cores_que_ficam_de_fora_e_nao_promete_oferta_nova(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);

        $p = $this->previa($grupo);

        $this->assertSame(['Preto' => 'CAD-PT-CB2', 'Azul' => 'CAD-AZ-CB2', 'Branco' => 'CAD-BR-CB2'], $this->skusDaPrevia($p), 'o SKU no padrão do Planejamento');
        $this->assertTrue(collect($p['variantes'])->every(fn ($v) => $v['planejamento']['nova'] === true && $v['planejamento']['oferta_id'] === null));
        $chaves = array_column($p['avisos'], 'chave');
        $this->assertNotContains('ofertas_novas_no_portal', $chaves, 'nenhuma oferta nasce no Portal pelo Publicador');
        $aviso = collect($p['avisos'])->firstWhere('chave', 'cores_sem_combo');
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('Azul', $aviso['mensagem']);
        $this->assertCount(0, $this->combos());
    }

    public function test_criar_sem_combos_nao_cria_oferta_no_portal_nem_com_pessoa(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->precificacaoPorSku([]);

        $kit = $this->criar($grupo);

        $this->assertCount(0, $this->combos(), 'Combo nasce só no Planejamento do Portal');
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'portal')->where('description', 'like', 'Oferta %criada%')->count());
        $this->assertSame('CAD-CB2', $kit->sku);
        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'), 'a cor sem Combo fica com o SKU que a oferta teria');
    }

    public function test_criar_usa_a_oferta_que_o_cliente_aceitou_e_nao_cria_as_outras(): void
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

        $this->assertCount(1, $this->combos(), 'só a do cliente existe: Preto e Branco não nasceram');
        $this->assertSame('AZUL-DO-CLIENTE', $this->skuDaCor($kit, 'Azul'));
        $this->assertSame($azul->id, EstruturaOferta::where('sku', 'AZUL-DO-CLIENTE')->value('id'));
    }

    public function test_skus_das_ofertas_so_le_as_que_existem_e_nao_le_a_precificacao(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2, ['Azul']);
        $this->mock(\App\Services\Portal\Estrutura\EstruturaPrecificacaoService::class, fn ($m) => $m->shouldNotReceive('pagina'));

        $skus = app(PlanejamentoDaFaseService::class)->skusDasOfertas($grupo->fresh(), 2);

        $this->assertSame(['CAD-AZ-CB2'], array_values($skus));
        $this->assertCount(1, $this->combos());
    }

    public function test_segunda_criacao_do_mesmo_kit_e_recusada(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $this->criar($grupo);

        try {
            $this->criar($grupo);
            $this->fail('a segunda criação devia ser recusada');
        } catch (RegraViolada $e) {
            $this->assertSame('KIT-04', $e->regra);
        }

        $this->assertCount(3, $this->combos());
        $this->assertSame(1, PubProduto::where('produto_base_id', $grupo->id)->count());
    }

    public function test_se_o_kit_nao_nasce_nada_fica(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        PubProduto::creating(function (PubProduto $p) {
            if ($p->produto_base_id !== null) {
                throw new \RuntimeException('falha simulada ao criar o kit');
            }
        });

        try {
            $this->criar($grupo);
            $this->fail('a criação devia falhar');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada ao criar o kit', $e->getMessage());
        }

        $this->assertCount(3, $this->combos());
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

    public function test_le_ofertas_so_da_empresa_do_produto(): void
    {
        $outra = Company::factory()->create();
        $grupo = $this->grupoComRascunho();
        // Combo de OUTRA empresa apontando para a cor desta (não passa pelo serviço, que recusaria).
        EstruturaOferta::create(['company_id' => $outra->id, 'sku' => 'INTRUSO-CB2', 'fase' => 'combo', 'nome' => 'Intruso'])
            ->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 2]);

        $this->assertSame([], app(PlanejamentoDaFaseService::class)->skusDasOfertas($grupo->fresh(), 2));
        $kit = $this->criar($grupo);

        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'), 'o SKU sugerido, nunca o da outra empresa');
        $this->assertCount(1, $this->combos($outra));
    }
}
