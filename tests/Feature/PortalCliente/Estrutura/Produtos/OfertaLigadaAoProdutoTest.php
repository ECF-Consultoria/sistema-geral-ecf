<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-07: cada variação gravada nasce com a sua oferta simples (D-08).
 *
 * Modos de falha que estes testes impedem: variação sem oferta (o Publicador não
 * enxerga o produto); oferta antiga ganhando produto por casamento de SKU (D-09,
 * duas verdades); renomear o código e perder os anúncios; duas ofertas para a
 * mesma variação; kit/combo entrando na tela de logística (D-18).
 */
class OfertaLigadaAoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function svc(): ProdutoCadastroService
    {
        return app(ProdutoCadastroService::class);
    }

    private function linha(string $codigo, ?string $valor = 'Natural', string $nome = 'Cristaleira 1014', string $grupo = '1014'): array
    {
        return ['grupo' => $grupo, 'codigo' => $codigo, 'nome' => $nome, 'valor' => $valor, 'eixo' => 'cor', 'volumes' => [['c' => 93, 'l' => 55, 'a' => 6, 'kg' => 9.5]]];
    }

    private function ofertasLigadas(Company $empresa)
    {
        return EstruturaOferta::where('company_id', $empresa->id)->whereNotNull('variacao_id')->orderBy('sku')->get();
    }

    public function test_cada_variacao_ganha_uma_oferta_simples_ligada_com_nome_produto_e_valor(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        $this->svc()->gravarLinhas($empresa, [
            $this->linha('1014-1', 'Natural'),
            $this->linha('1014-2', 'Preto'),
            $this->linha('2000-1', null, 'Mesa', '2000'),
        ], $ator);

        $ofertas = $this->ofertasLigadas($empresa)->keyBy('sku');
        $this->assertCount(3, $ofertas);
        $this->assertSame('Cristaleira 1014 — Natural', $ofertas['1014-1']->nome);
        $this->assertSame('Cristaleira 1014 — Preto', $ofertas['1014-2']->nome);
        $this->assertSame('Mesa', $ofertas['2000-1']->nome);
        $this->assertSame('simples', $ofertas['1014-1']->fase);
        $this->assertSame(EstruturaProdutoVariacao::where('codigo', '1014-1')->value('id'), $ofertas['1014-1']->variacao_id);
    }

    public function test_ofertas_antigas_ficam_intactas_e_sku_repetido_gera_outra_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->listaDoGabarito($empresa, $ator);
        $antes = EstruturaOferta::orderBy('id')->get(['id', 'sku', 'nome', 'fase', 'variacao_id', 'logistica', 'observacoes'])->toArray();
        $this->assertCount(9, $antes);

        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'CAD-01', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);
        // A espera 'CAD-01' já casou com a oferta antiga na criação do gabarito; recria para ver o motivo mudar.
        EstruturaAnuncioEspera::query()->delete();
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'CAD-01', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);

        $this->svc()->gravarLinhas($empresa, [$this->linha('CAD-01', 'Natural', 'Cadeira nova', 'CAD')], $ator);

        $depois = EstruturaOferta::whereIn('id', array_column($antes, 'id'))->orderBy('id')
            ->get(['id', 'sku', 'nome', 'fase', 'variacao_id', 'logistica', 'observacoes'])->toArray();
        $this->assertSame($antes, $depois);
        $this->assertSame(10, EstruturaOferta::count());
        $this->assertSame(1, $this->ofertasLigadas($empresa)->count());
        $this->assertSame(2, EstruturaOferta::where('sku', 'CAD-01')->count());
        $this->assertSame(EstruturaAnuncioEspera::MOTIVO_SKU_REPETIDO, EstruturaAnuncioEspera::first()->motivo);
    }

    public function test_espera_com_sku_de_variacao_nova_vira_anuncio_da_oferta_ligada(): void
    {
        $empresa = $this->empresaDoGabarito();
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => '1014-1', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);

        $r = $this->svc()->gravarLinhas($empresa, [$this->linha('1014-1')], $this->atorCliente($empresa));

        $oferta = $this->ofertasLigadas($empresa)->first();
        $this->assertSame(1, $oferta->anuncios()->count());
        $this->assertSame(0, EstruturaAnuncioEspera::count());
        $this->assertSame(1, $r['totais']['absorvidos_da_espera']);
    }

    public function test_mudar_codigo_ou_nome_acompanha_a_oferta_e_mantem_os_anuncios(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $r = $this->svc()->gravarLinhas($empresa, [$this->linha('1014-1', 'Natural'), $this->linha('1014-2', 'Preto')], $ator);
        $ids = array_column($r['linhas'], 'id', 'codigo');

        $oferta = EstruturaOferta::where('variacao_id', $ids['1014-1'])->first();
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB1', 'titulo' => 'x']);
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => '1014-9', 'motivo' => 'sem_oferta', 'tipo' => 'premium']);

        // Muda o código da variação 1.
        $this->svc()->gravarLinhas($empresa, [['id' => $ids['1014-1']] + $this->linha('1014-9', 'Natural')], $ator);
        $oferta->refresh();
        $this->assertSame('1014-9', $oferta->sku);
        $this->assertSame(2, $oferta->anuncios()->count(), 'o anúncio ligado fica e o da espera é absorvido');

        // Renomeia o produto: as duas ofertas acompanham.
        $this->svc()->gravarLinhas($empresa, [
            ['id' => $ids['1014-1']] + $this->linha('1014-9', 'Natural', 'Cristaleira Nova'),
        ], $ator);
        $nomes = $this->ofertasLigadas($empresa)->pluck('nome', 'sku')->all();
        $this->assertSame('Cristaleira Nova — Natural', $nomes['1014-9']);
        $this->assertSame('Cristaleira Nova — Preto', $nomes['1014-2']);
    }

    /**
     * BE-WR-03: a linha que renomeia o produto e TAMBÉM cria uma variação levava só a oferta nova
     * com o nome novo; as irmãs ficavam "Nome antigo — Valor" na Lista SKUs e no Publicador.
     */
    public function test_renomear_numa_linha_que_cria_variacao_acompanha_as_ofertas_irmas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $r = $this->svc()->gravarLinhas($empresa, [$this->linha('1014-1', 'Natural'), $this->linha('1014-2', 'Preto')], $ator);
        $produtoId = $r['linhas'][0]['produto_id'];

        // Ficha: variação nova pelo produto_id, com o produto renomeado.
        $this->svc()->gravarLinhas($empresa, [
            ['produto_id' => $produtoId, 'codigo' => '1014-3', 'nome' => 'Cristaleira Nova', 'valor' => 'Branco'],
        ], $ator);
        $this->assertSame([
            '1014-1' => 'Cristaleira Nova — Natural',
            '1014-2' => 'Cristaleira Nova — Preto',
            '1014-3' => 'Cristaleira Nova — Branco',
        ], $this->ofertasLigadas($empresa)->pluck('nome', 'sku')->all());

        // Importação: a variação nova vem ANTES das antigas, com outro nome no grupo.
        $this->svc()->gravarLinhas($empresa, [
            $this->linha('1014-4', 'Cinza', 'Cristaleira Clássica'),
            $this->linha('1014-1', 'Natural', 'Cristaleira Clássica'),
        ], $ator, ProdutoCadastroService::MODO_IMPORTACAO);
        $nomes = $this->ofertasLigadas($empresa)->pluck('nome', 'sku')->all();
        $this->assertSame('Cristaleira Clássica — Cinza', $nomes['1014-4']);
        $this->assertSame('Cristaleira Clássica — Natural', $nomes['1014-1']);
        $this->assertSame('Cristaleira Clássica — Preto', $nomes['1014-2']);
        $this->assertSame('Cristaleira Clássica — Branco', $nomes['1014-3']);
    }

    public function test_garantir_ofertas_recria_a_que_falta_e_o_unique_barra_a_segunda(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $r = $this->svc()->gravarLinhas($empresa, [$this->linha('1014-1'), $this->linha('1014-2', 'Preto')], $ator);
        $id = $r['linhas'][0]['id'];

        DB::table('estrutura_ofertas')->where('variacao_id', $id)->delete();
        $this->assertSame(1, $this->ofertasLigadas($empresa)->count());

        $this->assertSame(1, $this->svc()->garantirOfertas($empresa, $ator));
        $this->assertSame(2, $this->ofertasLigadas($empresa)->count());
        $this->assertSame(0, $this->svc()->garantirOfertas($empresa, $ator));
        $this->assertSame(2, $this->ofertasLigadas($empresa)->count());

        $this->expectException(QueryException::class);
        DB::table('estrutura_ofertas')->insert([
            'company_id' => $empresa->id, 'sku' => 'DUP', 'fase' => 'simples', 'variacao_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_combo_e_kit_nao_entram_nas_linhas_do_produtos_nem_ganham_logistica_provavel_ou_frete(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $r = $this->svc()->gravarLinhas($empresa, [$this->linha('1014-1')], $ator);
        $oferta = $this->ofertasLigadas($empresa)->first();

        [$combo] = app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => '1014-1-CB2', 'fase' => 'combo', 'componentes' => [['id' => $oferta->id, 'quantidade' => 2]],
        ], $ator);

        $linhas = app(\App\Services\Portal\Estrutura\Produtos\ProdutoLinhas::class)->paraProdutos($empresa, array_column($r['linhas'], 'produto_id'));
        $this->assertSame(['1014-1'], array_column($linhas, 'codigo'));

        $visao = EstruturaConjunto::daEmpresa($empresa)->oferta($combo->id);
        $this->assertArrayNotHasKey('frete', $visao);
        $this->assertArrayNotHasKey('logistica_provavel', $visao);
    }
}
