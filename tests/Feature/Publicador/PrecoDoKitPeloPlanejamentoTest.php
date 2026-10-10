<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PlanejamentoDaFaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item B (decisões do usuário de 09/10/2026): o kit da Fase N cujo base é
 * agrupado recebe, por cor, o preço da Precificação da oferta Combo N DAQUELA cor no Portal. Casa pela
 * cor (a régua do Sincronizar), e a chave do mapa é o SKU que a variante tem hoje — o `-CB{N}` da
 * oferta ou o `-KIT{N}` do kit criado antes de 09/10. Sem Combo no Portal, o kit segue vazio como hoje.
 * O preço nunca é gravado no rascunho.
 */
class PrecoDoKitPeloPlanejamentoTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    // 10/10/2026: `promocoes` (o mínimo do Portal) e `sem_frete` acompanham `precos` — vazios aqui também.
    private const VAZIO = ['titulos' => ['gold_special' => null, 'gold_pro' => null], 'precos' => ['gold_special' => null, 'gold_pro' => null],
        'promocoes' => ['gold_special' => null, 'gold_pro' => null], 'sem_frete' => ['gold_special' => false, 'gold_pro' => false], 'mlbs' => []];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->montarPlanejamento();
    }

    private function efetivos(PubProduto $p): array
    {
        return app(DadosEfetivosService::class)->daProduto($p->fresh());
    }

    public function test_kit_antigo_com_sku_kit_recebe_o_preco_do_combo_de_cada_cor(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2);
        $this->aceitarCombos(2);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0, 'cad-br-cb2' => 320.0]);

        $e = $this->efetivos($kit);

        $this->assertSame([
            'cad-pt-kit2' => ['gold_special' => 300.0, 'gold_pro' => 320.0],
            'cad-az-kit2' => ['gold_special' => 310.0, 'gold_pro' => 330.0],
            'cad-br-kit2' => ['gold_special' => 320.0, 'gold_pro' => 340.0],
        ], $e['precos_por_variante']);
        $this->assertSame(self::VAZIO['precos'], $e['precos'], 'sem âncora: a cor sem Combo não herda o preço de outra');
        $this->assertSame([], $e['mlbs']);
    }

    public function test_kit_com_os_skus_das_ofertas_casa_pelo_sku_cb(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => 'CAD-PT-CB2', 'Azul' => 'CAD-AZ-CB2', 'Branco' => 'CAD-BR-CB2']);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0, 'cad-br-cb2' => 320.0]);

        $this->assertSame(['cad-pt-cb2', 'cad-az-cb2', 'cad-br-cb2'], array_keys($this->efetivos($kit)['precos_por_variante']));
    }

    public function test_sem_combo_no_portal_o_kit_segue_vazio_como_hoje(): void
    {
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2);
        $this->precificacaoPorSku([]);

        $this->assertSame(self::VAZIO, $this->efetivos($kit));
    }

    public function test_combo_de_outra_quantidade_nao_vale_para_o_kit(): void
    {
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2);
        $this->aceitarCombos(4);
        $this->precificacaoPorSku(['cad-pt-cb4' => 600.0]);

        $this->assertSame(self::VAZIO, $this->efetivos($kit));
    }

    public function test_cor_sem_combo_fica_sem_preco_e_as_outras_recebem(): void
    {
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2);
        $this->aceitarCombos(2, ['Preto', 'Azul']);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0]);

        $this->assertSame(['cad-pt-kit2', 'cad-az-kit2'], array_keys($this->efetivos($kit)['precos_por_variante']));
    }

    public function test_variante_sem_sku_nao_entra_no_mapa(): void
    {
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2, ['Preto' => null, 'Azul' => 'CAD-AZ-KIT2', 'Branco' => 'CAD-BR-KIT2']);
        $this->aceitarCombos(2);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0, 'cad-br-cb2' => 320.0]);

        $this->assertSame(['cad-az-kit2', 'cad-br-kit2'], array_keys($this->efetivos($kit)['precos_por_variante']));
    }

    public function test_kit_de_base_nao_agrupado_segue_vazio(): void
    {
        $base = PubProduto::create(['company_id' => $this->empresaP->id, 'sku' => 'MANUAL', 'nome' => 'Cadeira manual', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->rascunhoDeCores($base, ['Preto' => 'MANUAL-PT', 'Azul' => 'MANUAL-AZ'], 10);
        $kit = $this->kitAntigo($base, 2, ['Preto' => 'MANUAL-PT-KIT2', 'Azul' => 'MANUAL-AZ-KIT2']);
        $this->aceitarCombos(2);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0]);

        $this->assertSame(self::VAZIO, $this->efetivos($kit));
    }

    public function test_combo_de_outra_empresa_nunca_da_preco_ao_kit(): void
    {
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2);
        // Dado inconsistente de propósito: a oferta Combo é da empresa B, com componente da A.
        $b = Company::factory()->create();
        $alheio = EstruturaOferta::create(['company_id' => $b->id, 'sku' => 'CAD-PT-CB2', 'fase' => 'combo', 'nome' => 'Alheio']);
        $alheio->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 2]);
        $this->precificacaoPorSku(['cad-pt-cb2' => 999.0]);

        $this->assertSame(self::VAZIO, $this->efetivos($kit));
    }

    public function test_combo_da_variacao_que_ficou_fora_do_grupo_nao_e_cor_do_kit(): void
    {
        // Uma 4ª variação REPETIDA ("Preto" de novo) vira produto separado (`CoresDoGrupo`): o Combo dela não é do kit.
        $repetida = EstruturaProdutoVariacao::create(['produto_id' => $this->produtoP->id, 'company_id' => $this->empresaP->id, 'ordem' => 9,
            'codigo' => 'CAD-PT2', 'eixo' => 'cor', 'valor' => 'Preto']);
        $simples = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $repetida->id, 'sku' => 'CAD-PT2', 'fase' => 'simples', 'nome' => 'Repetida']);
        $combo = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'CAD-PT2-CB2', 'fase' => 'combo', 'nome' => 'Combo da repetida']);
        $combo->componentes()->create(['componente_id' => $simples->id, 'quantidade' => 2]);
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2);
        $this->precificacaoPorSku(['cad-pt2-cb2' => 999.0]);

        $this->assertSame(self::VAZIO, $this->efetivos($kit));
    }

    public function test_o_estado_do_editor_do_kit_mostra_o_preco_efetivo_e_o_digitado_continua_vazio(): void
    {
        $kit = $this->kitAntigo($this->grupoComRascunho(), 2);
        $this->aceitarCombos(2);
        $this->precificacaoPorSku(['cad-pt-cb2' => 300.0, 'cad-az-cb2' => 310.0, 'cad-br-cb2' => 320.0]);

        $estado = app(EditorRascunhoService::class)->estado(PubRascunho::where('produto_id', $kit->id)->firstOrFail());

        $porSku = collect($estado['variantes'])->keyBy(fn ($v) => $v['atributos']['SELLER_SKU']['value_name'] ?? '?');
        $this->assertSame(310.0, $porSku['CAD-AZ-KIT2']['precos_efetivos']['gold_special']);
        $this->assertSame(330.0, $porSku['CAD-AZ-KIT2']['precos_efetivos']['gold_pro']);
        $this->assertNull($porSku['CAD-AZ-KIT2']['precos']['gold_special'] ?? null, 'o efetivo nunca é gravado');
        $this->assertSame(0, \DB::table('pub_variante_precos')->whereNotNull('preco')->count());
    }

    public function test_combos_do_kit_casa_pela_cor_mesmo_com_caixa_e_acento_diferentes(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->variacoesP['Branco']->update(['valor' => 'BRANCO']);
        $kit = $this->kitAntigo($grupo, 2);
        $this->aceitarCombos(2);

        $combos = app(PlanejamentoDaFaseService::class)->combosDoKit($kit->fresh());

        $this->assertCount(3, $combos);
        $porSku = collect($combos)->keyBy('sku_da_variante');
        $this->assertSame('CAD-BR-CB2', $porSku['CAD-BR-KIT2']['sku']);
        $this->assertSame($this->variacoesP['Branco']->id, $porSku['CAD-BR-KIT2']['variacao_id']);
    }

    public function test_produto_do_portal_de_outra_empresa_nao_e_lido(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2);
        $this->aceitarCombos(2);
        // O grupo aponta para um produto do Portal de OUTRA empresa (vínculo cruzado): nada é lido.
        $b = Company::factory()->create();
        $alheio = EstruturaProduto::create(['company_id' => $b->id, 'codigo' => 'X', 'nome' => 'Alheio']);
        PubProduto::whereKey($grupo->id)->update(['estrutura_produto_id' => $alheio->id]);

        $this->assertSame([], app(PlanejamentoDaFaseService::class)->combosDoKit($kit->fresh()));
    }
}
