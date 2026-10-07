<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\EstruturaTipoProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\RetratoDoCatalogo;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-08: o retrato do catálogo no banco e a geração real.
 *
 * Modos de falha que estes testes impedem: sugerir de novo o que já existe (até o
 * que foi montado à mão na Lista SKUs), vazar variação/oferta/descarte de outra
 * empresa, a carga do retrato crescer com o número de produtos (N+1) e o tipo do
 * produto ignorar a escolha guardada.
 */
class RetratoDoCatalogoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function sugestoes($empresa): array
    {
        return app(SugestoesService::class)->gerar($empresa)['sugestoes'];
    }

    private function porFase(array $sugestoes): array
    {
        $f = ['combo' => 0, 'kit' => 0, 'combit' => 0];
        foreach ($sugestoes as $s) {
            $f[$s['fase']]++;
        }

        return $f;
    }

    /** @return array{0: \App\Models\Company, 1: \App\Support\Portal\AtorDoPortal, 2: array} */
    private function cenario(): array
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        return [$empresa, $ator, $this->catalogoSintetico($empresa, $ator)];
    }

    public function test_a_geracao_real_da_15_combo_6_kit_e_8_combit(): void
    {
        [$empresa] = $this->cenario();

        $sugestoes = $this->sugestoes($empresa);

        $this->assertSame(['combo' => 15, 'kit' => 6, 'combit' => 8], $this->porFase($sugestoes));
        $this->assertCount(29, $sugestoes);
    }

    public function test_a_variacao_sem_oferta_nao_aparece_em_nenhuma_chave(): void
    {
        [$empresa, , $mapa] = $this->cenario();

        $v203 = $mapa['variacoes']['V203'];
        foreach ($this->sugestoes($empresa) as $s) {
            $this->assertStringNotContainsString("v{$v203}*", $s['chave']);
        }
    }

    public function test_tipo_inferido_pela_categoria_em_estado_a_confirmar(): void
    {
        [$empresa, , $mapa] = $this->cenario();

        $detalhes = app(RetratoDoCatalogo::class)->daEmpresa($empresa)['detalhes']['produtos'];
        $p1 = $detalhes[$mapa['produtos']['Mesa Polo']];

        $this->assertSame('mesa', $p1['tipo']);
        $this->assertSame('inferido', $p1['tipo_origem']);
        $this->assertSame('Mesas de Jantar', $p1['categoria']);
        $this->assertSame('Polo', $p1['familia']);
        $this->assertSame(['Sala de jantar'], $p1['ambientes']);
        $this->assertNull($p1['tipo_escolhido']);
    }

    public function test_a_escolha_guardada_vence_a_inferencia(): void
    {
        [$empresa, , $mapa] = $this->cenario();
        $p7 = $mapa['produtos']['Peça Decorativa Polo'];

        $semAjuste = app(RetratoDoCatalogo::class)->daEmpresa($empresa)['detalhes']['produtos'][$p7];
        $this->assertNull($semAjuste['tipo']);
        $this->assertNull($semAjuste['tipo_origem']);

        EstruturaProdutoGeracao::create([
            'produto_id' => $p7, 'company_id' => $empresa->id,
            'tipo_id' => EstruturaTipoProduto::where('slug', 'cadeira')->value('id'),
        ]);

        $retrato = app(RetratoDoCatalogo::class)->daEmpresa($empresa);
        $d = $retrato['detalhes']['produtos'][$p7];

        $this->assertSame('cadeira', $d['tipo']);
        $this->assertSame('escolhido', $d['tipo_origem']);
        $this->assertSame('cadeira', $d['tipo_escolhido']);

        $noRetrato = collect($retrato['produtos'])->firstWhere('id', $p7);
        $this->assertSame('cadeira', $noRetrato['tipo']);
    }

    public function test_tipo_excluido_volta_para_a_inferencia(): void
    {
        [$empresa, , $mapa] = $this->cenario();
        $p2 = $mapa['produtos']['Cadeira Polo'];

        $tipo = EstruturaTipoProduto::create(['slug' => 'raro', 'nome' => 'Raro', 'plural' => 'Raros', 'palavras' => 'raro', 'ordem' => 999]);
        EstruturaProdutoGeracao::create(['produto_id' => $p2, 'company_id' => $empresa->id, 'tipo_id' => $tipo->id]);

        $this->assertSame('raro', app(RetratoDoCatalogo::class)->daEmpresa($empresa)['detalhes']['produtos'][$p2]['tipo']);

        $tipo->delete();

        $d = app(RetratoDoCatalogo::class)->daEmpresa($empresa)['detalhes']['produtos'][$p2];
        $this->assertSame('cadeira', $d['tipo']);
        $this->assertSame('inferido', $d['tipo_origem']);
        $this->assertNull($d['tipo_escolhido']);
    }

    public function test_kit_feito_a_mao_entra_em_existentes_e_nao_e_sugerido(): void
    {
        [$empresa, $ator, $mapa] = $this->cenario();

        app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'KIT-MAO', 'fase' => 'kit', 'nome' => 'Kit feito à mão',
            'componentes' => [
                ['id' => $mapa['ofertas']['V101'], 'quantidade' => 1],
                ['id' => $mapa['ofertas']['V201'], 'quantidade' => 1],
            ],
        ], $ator);

        $chave = 'v'.$mapa['variacoes']['V101'].'*1+v'.$mapa['variacoes']['V201'].'*1';
        $retrato = app(RetratoDoCatalogo::class)->daEmpresa($empresa);

        $this->assertArrayHasKey($chave, $retrato['existentes']);
        $this->assertCount(1, $retrato['existentes']);

        $sugestoes = $this->sugestoes($empresa);
        $this->assertSame(['combo' => 15, 'kit' => 5, 'combit' => 8], $this->porFase($sugestoes));
        $this->assertNotContains($chave, array_column($sugestoes, 'chave'));
    }

    public function test_combo_sobre_oferta_antiga_sem_produto_nao_e_comparavel_e_nao_quebra(): void
    {
        [$empresa, $ator] = $this->cenario();
        $svc = app(EstruturaOfertaService::class);

        [$antiga] = $svc->criar($empresa, ['sku' => 'ANTIGA-1', 'fase' => 'simples', 'nome' => 'Oferta antiga'], $ator);
        $svc->criar($empresa, [
            'sku' => 'ANTIGA-1-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 antiga',
            'componentes' => [['id' => $antiga->id, 'quantidade' => 2]],
        ], $ator);

        $retrato = app(RetratoDoCatalogo::class)->daEmpresa($empresa);

        $this->assertSame([], $retrato['existentes']);
        $this->assertCount(29, $this->sugestoes($empresa));
    }

    public function test_descarte_gravado_volta_marcado_com_a_data(): void
    {
        [$empresa, , $mapa] = $this->cenario();

        $chave = 'v'.$mapa['variacoes']['V201'].'*4';
        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $chave, 'fase' => EstruturaOferta::FASE_COMBO]);

        $retrato = app(RetratoDoCatalogo::class)->daEmpresa($empresa);
        $this->assertSame(now()->format('Y-m-d'), $retrato['descartadas'][$chave]);

        $descartadas = array_values(array_filter($this->sugestoes($empresa), fn ($s) => $s['descartada']));
        $this->assertCount(1, $descartadas);
        $this->assertSame($chave, $descartadas[0]['chave']);
    }

    public function test_o_retrato_de_uma_empresa_nunca_traz_dados_da_outra(): void
    {
        [$a, $atorA, $mapaA] = $this->cenario();
        [$b, , $mapaB] = $this->cenario();

        // Descarte e composição existente da B não podem aparecer na A.
        EstruturaSugestaoDescartada::create(['company_id' => $b->id, 'chave' => 'v'.$mapaB['variacoes']['V201'].'*2', 'fase' => 'combo']);
        app(EstruturaOfertaService::class)->criar($b, [
            'sku' => 'KIT-B', 'fase' => 'kit', 'nome' => 'Kit B',
            'componentes' => [['id' => $mapaB['ofertas']['V101'], 'quantidade' => 1], ['id' => $mapaB['ofertas']['V201'], 'quantidade' => 1]],
        ], $this->atorCliente($b));

        $retratoA = app(RetratoDoCatalogo::class)->daEmpresa($a);
        $retratoB = app(RetratoDoCatalogo::class)->daEmpresa($b);

        $variacoesA = array_values($mapaA['variacoes']);
        $variacoesB = array_values($mapaB['variacoes']);
        $this->assertSame([], array_intersect($variacoesA, $variacoesB));

        $idsDe = fn (array $retrato) => collect($retrato['produtos'])->flatMap(fn ($p) => array_column($p['variacoes'], 'id'))->sort()->values()->all();
        $this->assertSame(collect($variacoesA)->sort()->values()->all(), array_values(array_intersect($idsDe($retratoA), $variacoesA)));
        $this->assertSame([], array_intersect($idsDe($retratoA), $variacoesB));
        $this->assertSame([], array_intersect($idsDe($retratoB), $variacoesA));

        $this->assertSame([], $retratoA['existentes']);
        $this->assertSame([], $retratoA['descartadas']);
        $this->assertCount(1, $retratoB['existentes']);
        $this->assertCount(1, $retratoB['descartadas']);
        $this->assertCount(29, $this->sugestoes($a));

        // Nenhum id de oferta da B nas ofertas da A.
        $ofertasA = collect($retratoA['produtos'])->flatMap(fn ($p) => array_column($p['variacoes'], 'oferta_id'))->filter()->all();
        $this->assertSame([], array_intersect($ofertasA, array_values($mapaB['ofertas'])));
        $this->assertSame([], array_intersect(array_keys($retratoA['detalhes']['produtos']), array_values($mapaB['produtos'])));
    }

    public function test_o_numero_de_consultas_nao_cresce_com_o_numero_de_produtos(): void
    {
        [$empresa, $ator] = $this->cenario();

        $contar = function () use ($empresa): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(RetratoDoCatalogo::class)->daEmpresa($empresa);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $antes = $contar();

        $linhas = [];
        for ($i = 1; $i <= 10; $i++) {
            $linhas[] = [
                'grupo' => "X{$i}", 'codigo' => "X{$i}-1", 'nome' => "Cadeira Extra {$i}", 'familia' => 'Extra',
                'ambientes' => ['Sala de jantar'], 'categoria_texto' => 'Cadeiras',
                'volumes' => [['c' => 50, 'l' => 50, 'a' => 20, 'kg' => 6]], 'custo' => 80,
            ];
        }
        $this->assertSame([], app(ProdutoCadastroService::class)->gravarLinhas($empresa, $linhas, $ator)['erros']);

        $depois = $contar();

        $this->assertGreaterThan(0, $antes);
        $this->assertSame($antes, $depois);
    }
}
