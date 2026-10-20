<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\PlanejamentoDaFaseService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\VitrineDeProdutosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * As fases na vitrine (20/10/2026, decisões do usuário): a régua do "Como funciona" do Portal — Fase 1 Simples,
 * Fase 2 Combo (todo combo, qualquer N), Fase 3 Kit, Fase 4 Combit. O combo fica no cartão do produto; o Kit e o
 * Combit, no cartão de CADA componente. O combo planejado no Portal sem anúncio vira uma face de espera na Fase 2.
 */
class VitrineFasesTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Queue::fake();
        $this->montarPlanejamento();
    }

    /** @return array{cartoes: list<array>, planejados: list<array>, contagens: array} */
    private function vitrine(): array
    {
        $alvo = app(ProgramasPublicadorService::class)->resolver('company-'.$this->empresaP->id);

        return app(VitrineDeProdutosService::class)->montar($alvo);
    }

    /** @return Collection<int, array> os cartões pelo id do produto */
    private function cartoes(): Collection
    {
        return collect($this->vitrine()['cartoes'])->keyBy('produto_id');
    }

    /**
     * A Mesa Redonda no Portal (uma cor, com a oferta Simples) e o produto dela no Publicador.
     *
     * @return array{0: PubProduto, 1: EstruturaOferta, 2: EstruturaProduto}
     */
    private function mesa(): array
    {
        $produto = EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => 'MESA-RD', 'nome' => 'Mesa Redonda']);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $produto->id, 'company_id' => $this->empresaP->id, 'ordem' => 0,
            'codigo' => 'MESA-RD-BR', 'eixo' => 'cor', 'valor' => 'Branca', 'custo' => 300, 'estoque' => 3]);
        $simples = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $v->id, 'sku' => 'MESA-RD-BR',
            'fase' => 'simples', 'nome' => 'Mesa Redonda — Branca']);
        $pub = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'estrutura_produto_id' => $produto->id,
            'oferta_id' => $simples->id, 'sku' => 'MESA-RD', 'nome' => 'Mesa Redonda', 'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$pub->fresh(), $simples, $produto];
    }

    /**
     * Uma oferta composta do Planejamento e o produto à parte dela no Publicador (como o Sincronizar cria).
     *
     * @param  list<array{0: EstruturaOferta, 1: int}>  $componentes  [oferta Simples, quantidade]
     */
    private function composto(string $tipo, string $sku, array $componentes, ?Company $daOferta = null): PubProduto
    {
        $oferta = EstruturaOferta::create(['company_id' => ($daOferta ?? $this->empresaP)->id, 'sku' => $sku, 'fase' => $tipo, 'nome' => $sku]);
        foreach ($componentes as [$simples, $quantidade]) {
            $oferta->componentes()->create(['componente_id' => $simples->id, 'quantidade' => $quantidade]);
        }

        return PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'oferta_id' => $oferta->id,
            'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PORTAL])->fresh();
    }

    public function test_todo_combo_e_fase_2_no_cartao_do_produto_e_kit_e_combit_vao_no_cartao_de_cada_componente(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $kit2 = $this->kitAntigo($grupo, 2);
        $kit4 = $this->kitAntigo($grupo, 4);
        [$mesa, $mesaSimples] = $this->mesa();
        $kit = $this->composto('kit', 'KT-MESA-CAD', [[$mesaSimples, 1], [$this->simplesP['Preto'], 1]]);
        $combit = $this->composto('combit', 'CT4-MESA-CAD', [[$mesaSimples, 1], [$this->simplesP['Preto'], 4]]);

        $c = $this->cartoes();
        $ver = fn (PubProduto $p) => [$c[$p->id]['tipo'], $c[$p->id]['fase_numero'], $c[$p->id]['fase'], $c[$p->id]['familias']];

        $this->assertSame(['simples', 1, 'Fase 1', [$grupo->id]], $ver($grupo));
        $this->assertSame(['simples', 1, 'Fase 1', [$mesa->id]], $ver($mesa));
        $this->assertSame(['combo', 2, 'Fase 2 · Combo 2 un', [$grupo->id]], $ver($kit2));
        $this->assertSame(4, $kit4->fase, 'no resto do Publicador o Kit 4 continua Fase 4');
        $this->assertSame(['combo', 2, 'Fase 2 · Combo 4 un', [$grupo->id]], $ver($kit4), 'na vitrine todo combo é Fase 2');
        $this->assertSame(4, $c[$kit4->id]['quantidade']);
        $this->assertSame(['kit', 3, 'Fase 3 · Kit', [$mesa->id, $grupo->id]], $ver($kit));
        $this->assertSame('Mesa Redonda — Branca + Cadeira Jantar — Preto', $c[$kit->id]['composicao']);
        $this->assertSame(['combit', 4, 'Fase 4 · Combit', [$mesa->id, $grupo->id]], $ver($combit));
        $this->assertSame('Mesa Redonda — Branca + 4× Cadeira Jantar — Preto', $c[$combit->id]['composicao']);
        $this->assertSame($c[$kit->id]['familias'][0], $c[$kit->id]['familia_id']);
    }

    public function test_combo_do_planejamento_a_parte_entra_na_familia_e_composto_sem_componente_no_publicador_fica_sozinho(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $combo = $this->composto('combo', 'CAD-AZ-CB3', [[$this->simplesP['Azul'], 3]]);
        $banqueta = EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => 'BQ', 'nome' => 'Banqueta']);
        $vb = EstruturaProdutoVariacao::create(['produto_id' => $banqueta->id, 'company_id' => $this->empresaP->id, 'ordem' => 0,
            'codigo' => 'BQ-PT', 'eixo' => 'cor', 'valor' => 'Preta', 'custo' => 80, 'estoque' => 5]);
        $bqSimples = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $vb->id, 'sku' => 'BQ-PT', 'fase' => 'simples', 'nome' => 'Banqueta Preta']);
        $sozinho = $this->composto('combit', 'CT-BQ', [[$bqSimples, 2]]);

        $c = $this->cartoes();

        $this->assertSame(['combo', 2, 'Fase 2 · Combo 3 un', [$grupo->id], 3],
            [$c[$combo->id]['tipo'], $c[$combo->id]['fase_numero'], $c[$combo->id]['fase'], $c[$combo->id]['familias'], $c[$combo->id]['quantidade']],
            'o Combo de uma cor que virou produto à parte vai no cartão do produto pela variação');
        $this->assertSame([$sozinho->id], $c[$sozinho->id]['familias'], 'a banqueta não está no Publicador: o combit fica no próprio cartão');
        $this->assertSame('2× Banqueta Preta', $c[$sozinho->id]['composicao']);
    }

    public function test_combo_planejado_sem_anuncio_e_uma_face_de_espera_na_fase_2(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $this->aceitarCombos(2);
        $this->aceitarCombos(3, ['Preto']);
        $this->kitAntigo($grupo, 2);

        $planejados = $this->vitrine()['planejados'];

        $this->assertCount(1, $planejados, 'o Combo 2 já tem anúncio; só o 3 espera');
        $p = $planejados[0];
        $this->assertSame([true, $grupo->id, [$grupo->id], 'combo', 2, 3, 'Fase 2 · Combo 3 un'],
            [$p['planejado'], $p['familia_id'], $p['familias'], $p['tipo'], $p['fase_numero'], $p['quantidade'], $p['fase']]);
        $this->assertSame(['Espera a Fase 1 ser publicada', 'neutro'], [$p['chip']['texto'], $p['chip']['tom']]);
        $this->assertSame('Cadeira Jantar', $p['nome']);
        $this->assertArrayNotHasKey('produto_id', $p, 'a face de espera não é um produto: não se marca nem abre prévia');

        PubRascunho::where('produto_id', $grupo->id)->update(['status' => PubRascunho::PUBLISHED]);
        $publicada = $this->vitrine()['planejados'][0];

        $this->assertSame(['Planejado · ainda sem anúncio', 'ambar'], [$publicada['chip']['texto'], $publicada['chip']['tom']]);
    }

    public function test_quantidades_em_lote_sao_as_mesmas_de_um_produto_por_vez(): void
    {
        $grupo = $this->grupoP();
        $this->aceitarCombos(2);
        $this->aceitarCombos(4, ['Azul']);
        [, $mesaSimples, $mesaProduto] = $this->mesa();
        EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'MESA-RD-BR-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Mesas'])
            ->componentes()->create(['componente_id' => $mesaSimples->id, 'quantidade' => 2]);
        // Uma variação de OUTRO eixo vira produto separado no Sincronizar: o combo dela não é da família.
        $tamanho = EstruturaProdutoVariacao::create(['produto_id' => $this->produtoP->id, 'company_id' => $this->empresaP->id, 'ordem' => 9,
            'codigo' => 'CAD-GG', 'eixo' => 'tamanho', 'valor' => 'GG', 'custo' => 100, 'estoque' => 1]);
        $gg = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $tamanho->id, 'sku' => 'CAD-GG', 'fase' => 'simples', 'nome' => 'Cadeira GG']);
        EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'CAD-GG-CB5', 'fase' => 'combo', 'nome' => 'Combo 5 GG'])
            ->componentes()->create(['componente_id' => $gg->id, 'quantidade' => 5]);

        $svc = app(PlanejamentoDaFaseService::class);
        $lote = $svc->quantidadesDoPlanejamentoEmLote($this->empresaP->id, [$this->produtoP->id, $mesaProduto->id]);

        $this->assertSame([2, 4], $lote[$this->produtoP->id]);
        $this->assertSame($svc->quantidadesDoPlanejamento($grupo), $lote[$this->produtoP->id]);
        $this->assertSame([2], $lote[$mesaProduto->id]);
        $this->assertSame([], $svc->quantidadesDoPlanejamentoEmLote($this->empresaP->id, []));
    }

    public function test_oferta_de_outra_empresa_nao_entra_nem_no_planejado_nem_na_familia(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        [$mesa, $mesaSimples] = $this->mesa();
        $outra = Company::factory()->create();
        // Combo de OUTRA empresa apontando para a cor desta (não passa pelo serviço, que recusaria).
        EstruturaOferta::create(['company_id' => $outra->id, 'sku' => 'INTRUSO-CB6', 'fase' => 'combo', 'nome' => 'Intruso'])
            ->componentes()->create(['componente_id' => $this->simplesP['Branco']->id, 'quantidade' => 6]);
        $alheio = $this->composto('kit', 'KT-ALHEIO', [[$mesaSimples, 1], [$this->simplesP['Preto'], 1]], $outra);

        $v = $this->vitrine();

        $this->assertSame([], $v['planejados']);
        $c = collect($v['cartoes'])->keyBy('produto_id');
        $this->assertSame([$alheio->id], $c[$alheio->id]['familias'], 'composição de outra empresa não junta o produto às famílias desta');
        $this->assertNull($c[$alheio->id]['composicao']);
        $this->assertSame([$grupo->id], $c[$grupo->id]['familias']);
        $this->assertSame([$mesa->id], $c[$mesa->id]['familias']);
    }
}
