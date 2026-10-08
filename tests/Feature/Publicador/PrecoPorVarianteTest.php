<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/** Fase 172-06 (D-06): cada cor do rascunho agrupado vale o preço da Precificação da SUA oferta. */
class PrecoPorVarianteTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private function snapshot(array $variantes): RascunhoSnapshot
    {
        return new RascunhoSnapshot('MLB193945', alvos: [new Alvo('gold_special', 'T')], variantes: $variantes);
    }

    private function varianteSnap(string $chave, ?string $sku, ?float $preco = null): Variante
    {
        $dados = ['precos' => $preco === null ? [] : ['gold_special' => $preco]];
        if ($sku !== null) {
            $dados['atributos'] = ['SELLER_SKU' => ['value_name' => $sku]];
        }

        return new Variante($chave, [], true, false, $dados);
    }

    // ── RascunhoSnapshot::comEfetivos ────────────────────────────────────

    public function test_cada_variante_recebe_o_preco_da_sua_oferta_o_digitado_vence_e_sem_casamento_cai_na_ancora(): void
    {
        $s = $this->snapshot([
            $this->varianteSnap('a', 'MESA-AZ'),
            $this->varianteSnap('b', 'mesa-vd'),
            $this->varianteSnap('c', 'MESA-DIGITADO', 77.0),
            $this->varianteSnap('d', 'TROCADO-PELA-EQUIPE'),
        ])->comEfetivos([], ['gold_special' => 100.0], [
            'mesa-az' => ['gold_special' => 110.0],
            'mesa-vd' => ['gold_special' => 120.0],
            'mesa-digitado' => ['gold_special' => 999.0],
        ]);

        $preco = fn (int $i) => $s->variantes[$i]->dados['precos']['gold_special'];
        $this->assertSame(110.0, $preco(0));
        $this->assertSame(120.0, $preco(1), 'o SKU casa sem diferenciar caixa');
        $this->assertSame(77.0, $preco(2), 'o preço digitado vence o efetivo');
        $this->assertSame(100.0, $preco(3), 'sem oferta correspondente cai no preço da âncora');
    }

    public function test_sem_o_terceiro_argumento_o_resultado_e_o_de_antes(): void
    {
        $s = $this->snapshot([$this->varianteSnap('a', 'MESA-AZ'), $this->varianteSnap('b', null, 55.0)])
            ->comEfetivos([], ['gold_special' => 100.0]);

        $this->assertSame(100.0, $s->variantes[0]->dados['precos']['gold_special']);
        $this->assertSame(55.0, $s->variantes[1]->dados['precos']['gold_special']);
    }

    // ── DadosEfetivosService::daProduto ──────────────────────────────────

    /** @return array{0: Company, 1: EstruturaProduto, 2: list<EstruturaOferta>} */
    private function grupo(int $n = 3, ?Company $c = null, string $codigo = 'MESA'): array
    {
        $c ??= Company::factory()->create();
        $p = EstruturaProduto::create(['company_id' => $c->id, 'codigo' => $codigo, 'nome' => 'Mesa '.$codigo]);
        $ofertas = [];
        for ($i = 1; $i <= $n; $i++) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $c->id, 'ordem' => $i, 'codigo' => $codigo.'-'.$i, 'eixo' => 'cor', 'valor' => 'Cor '.$i]);
            $ofertas[] = EstruturaOferta::create(['company_id' => $c->id, 'variacao_id' => $v->id, 'sku' => $codigo.'-'.$i, 'fase' => 'simples', 'nome' => 'Mesa '.$i]);
        }

        return [$c, $p, $ofertas];
    }

    private function precificacaoFalsa(array $porSkuNormalizado, array &$chamadas): void
    {
        $this->mock(EstruturaPrecificacaoService::class, function ($m) use ($porSkuNormalizado, &$chamadas) {
            $m->shouldReceive('pagina')->andReturnUsing(function ($empresa, array $ids) use ($porSkuNormalizado, &$chamadas) {
                $chamadas[] = count($ids);
                $por = [];
                foreach (EstruturaOferta::whereIn('id', $ids)->get() as $o) {
                    $por[$o->id] = ['classico' => ['anunciado' => $porSkuNormalizado[mb_strtolower($o->sku)] ?? null]];
                }

                return ['por_oferta' => $por];
            });
        });
    }
    public function test_produto_agrupado_devolve_o_mapa_por_sku_com_uma_so_chamada_para_o_grupo(): void
    {
        [$c, $p, $ofertas] = $this->grupo(3);
        $chamadas = [];
        $this->precificacaoFalsa(['mesa-1' => 100.0, 'mesa-2' => 110.0, 'mesa-3' => 120.0], $chamadas);
        $produto = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $ofertas[0]->id, 'estrutura_produto_id' => $p->id, 'sku' => 'MESA-1', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $e = app(DadosEfetivosService::class)->daProduto($produto);

        $this->assertSame([
            'mesa-1' => ['gold_special' => 100.0, 'gold_pro' => null],
            'mesa-2' => ['gold_special' => 110.0, 'gold_pro' => null],
            'mesa-3' => ['gold_special' => 120.0, 'gold_pro' => null],
        ], $e['precos_por_variante']);
        $this->assertSame(100.0, $e['precos']['gold_special'], 'o resto continua o da âncora');
        $this->assertSame(1, count(array_filter($chamadas, fn ($n) => $n === 3)), 'uma só chamada com os ids de todas as cores');
    }

    public function test_produto_nao_agrupado_nao_traz_a_chave(): void
    {
        [$c, , $ofertas] = $this->grupo(2);
        $chamadas = [];
        $this->precificacaoFalsa(['mesa-1' => 100.0], $chamadas);
        $produto = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $ofertas[0]->id, 'sku' => 'MESA-1', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $e = app(DadosEfetivosService::class)->daProduto($produto);

        $this->assertArrayNotHasKey('precos_por_variante', $e);
        $this->assertSame([1], $chamadas, 'só a chamada da âncora, como antes');
    }

    public function test_ofertas_de_outra_company_nunca_entram_no_mapa(): void
    {
        [$c, $p, $ofertas] = $this->grupo(2);
        // Variação do MESMO produto, mas da Company B, com oferta da B (dado inconsistente): não entra.
        $b = Company::factory()->create();
        $varB = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $b->id, 'ordem' => 9, 'codigo' => 'ALHEIA', 'eixo' => 'cor', 'valor' => 'Alheia']);
        EstruturaOferta::create(['company_id' => $b->id, 'variacao_id' => $varB->id, 'sku' => 'ALHEIA-B', 'fase' => 'simples', 'nome' => 'Alheia']);
        $chamadas = [];
        $this->precificacaoFalsa(['mesa-1' => 100.0, 'mesa-2' => 110.0, 'alheia-b' => 5.0], $chamadas);
        $produto = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $ofertas[0]->id, 'estrutura_produto_id' => $p->id, 'sku' => 'MESA-1', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $mapa = app(DadosEfetivosService::class)->daProduto($produto)['precos_por_variante'];

        $this->assertSame(['mesa-1', 'mesa-2'], array_keys($mapa));
    }

    // ── Editor e conferência (cenário da cadeira, ML simulado) ───────────

    public function test_estado_e_conferencia_usam_o_preco_da_variante(): void
    {
        $this->montarCenario();
        // A variante do cenário não tem preço digitado e o SKU dela é CAD-01.
        $unica = $this->repo->snapshot($this->r->fresh())->variantes[0];
        $this->repo->gravarVariacao($this->r->fresh(), [], [$unica->comDados(['estoque' => 3, 'precos' => [], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-01']]])]);
        $this->efetivos = [
            'titulos' => ['gold_special' => null, 'gold_pro' => null],
            'precos' => ['gold_special' => 100.0, 'gold_pro' => null],
            'mlbs' => [],
            'precos_por_variante' => ['cad-01' => ['gold_special' => 133.0, 'gold_pro' => null]],
        ];

        $estado = app(EditorRascunhoService::class)->estado($this->r->fresh());
        $this->assertSame(133.0, $estado['variantes'][0]['precos_efetivos']['gold_special']);
        $this->assertNull($estado['variantes'][0]['precos']['gold_special'] ?? null, 'o digitado continua vazio: o efetivo nunca é gravado');

        $metodo = new \ReflectionMethod(ConferenciaService::class, 'comEfetivos');
        $snap = $metodo->invoke(app(ConferenciaService::class), $this->r->fresh())['snapshot'];
        $this->assertSame(133.0, $snap->variantes[0]->dados['precos']['gold_special']);
    }
}
