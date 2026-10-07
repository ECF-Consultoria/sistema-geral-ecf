<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaSugestaoDescartada;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-10: a prop `sugestoes` da tela. Painel sobre o CONJUNTO, página e filtros
 * no servidor.
 *
 * Modos de falha que estes testes impedem: contagem da aba sair da página (some ao
 * paginar), ordem instável entre requisições (item repetido ou perdido na virada de
 * página), filtro desconhecido derrubar a tela e o contrato de chaves mudar sem
 * aviso para as telas 168-14/15.
 */
class ListaDeSugestoesTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @return array{0: Company, 1: \App\Support\Portal\AtorDoPortal, 2: array} */
    private function cenario(): array
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $ator    = $this->atorCliente($empresa);

        return [$empresa, $ator, $this->catalogoSintetico($empresa, $ator)];
    }

    private function listar(Company $empresa, array $filtros = [], int $pagina = 1): array
    {
        return app(ListaDeSugestoes::class)->listar($empresa, $filtros, $pagina);
    }

    private function chaves(array $r): array
    {
        return array_column($r['itens'], 'chave');
    }

    private function valorDaFamilia(array $r, string $nome): string
    {
        foreach ($r['familias'] as $f) {
            if ($f['nome'] === $nome) {
                return $f['valor'];
            }
        }
        $this->fail("Família {$nome} fora das opções.");
    }

    public function test_contagens_das_abas_saem_do_conjunto_inteiro(): void
    {
        [$empresa] = $this->cenario();

        $p1 = $this->listar($empresa);
        $p2 = $this->listar($empresa, [], 2);
        $filtrada = $this->listar($empresa, ['fase' => 'kit', 'q' => 'polo']);

        $esperado = ['sugestoes' => 29, 'sem_tipo' => 1, 'descartadas' => 0];
        $this->assertSame($esperado, $p1['contagens']);
        $this->assertSame($esperado, $p2['contagens']);
        $this->assertSame($esperado, $filtrada['contagens']);
    }

    public function test_painel_de_fases_e_familias_da_familia_polo(): void
    {
        [$empresa] = $this->cenario();

        $base  = $this->listar($empresa);
        $polo  = $this->valorDaFamilia($base, 'Polo');
        $r     = $this->listar($empresa, ['familia' => $polo]);

        $this->assertSame(['todas' => 23, 'combo' => 9, 'kit' => 6, 'combit' => 8], $r['por_fase']);

        $totais = array_column($base['familias'], 'total', 'nome');
        $this->assertSame(23, $totais['Polo']);
        $this->assertSame(3, $totais['Solo A']);
        $this->assertSame(3, $totais['Sem família']);

        $ultima = end($base['familias']);
        $this->assertSame(['valor' => 'sem', 'nome' => 'Sem família', 'total' => 3], $ultima);
    }

    public function test_paginacao_estavel_20_e_9_sem_repetir(): void
    {
        [$empresa] = $this->cenario();

        $p1 = $this->listar($empresa);
        $p2 = $this->listar($empresa, [], 2);
        $de_novo = $this->listar($empresa);

        $this->assertCount(20, $p1['itens']);
        $this->assertCount(9, $p2['itens']);
        $this->assertSame($this->chaves($p1), $this->chaves($de_novo));

        $todas = array_merge($this->chaves($p1), $this->chaves($p2));
        $this->assertCount(29, array_unique($todas));
        $this->assertSame(['pagina' => 1, 'paginas' => 2, 'total' => 29, 'blocos' => 29, 'por_pagina' => 20], $p1['paginacao']);

        $ultima = $this->listar($empresa, [], 99);
        $this->assertSame(2, $ultima['paginacao']['pagina']);
        $this->assertSame($this->chaves($p2), $this->chaves($ultima));
    }

    public function test_familia_continua_so_quando_a_familia_vem_da_pagina_anterior(): void
    {
        [$empresa] = $this->cenario();

        $p1 = $this->listar($empresa);
        $p2 = $this->listar($empresa, [], 2);

        $this->assertNull($p1['familia_continua']);

        $ultimaDaP1 = end($p1['itens'])['familia']['id'];
        $primeiraDaP2 = $p2['itens'][0]['familia']['id'];
        $esperado = $ultimaDaP1 === $primeiraDaP2 ? ($primeiraDaP2 === null ? 'sem' : (string) $primeiraDaP2) : null;
        $this->assertSame($esperado, $p2['familia_continua']);
    }

    public function test_filtros_de_fase_tipo_e_busca(): void
    {
        [$empresa] = $this->cenario();

        $kit = $this->listar($empresa, ['fase' => 'kit']);
        $this->assertSame(6, $kit['paginacao']['total']);
        $this->assertSame(['kit'], array_values(array_unique(array_column($kit['itens'], 'fase'))));

        $cadeira = $this->listar($empresa, ['tipo' => 'cadeira']);
        $this->assertGreaterThan(0, $cadeira['paginacao']['total']);
        foreach ($cadeira['itens'] as $item) {
            $this->assertContains('cadeira', array_column($item['itens'], 'tipo'));
        }

        $todas = array_merge($this->listar($empresa)['itens'], $this->listar($empresa, [], 2)['itens']);
        $sku = null;
        foreach ($todas as $item) {
            foreach ($item['itens'] as $i) {
                if ($i['variacao_id'] === $this->ids($empresa)['V101']) {
                    $sku = $i['sku'];
                }
            }
        }
        $this->assertNotNull($sku);

        $porSku = $this->listar($empresa, ['q' => $sku]);
        $this->assertGreaterThan(0, $porSku['paginacao']['total']);
        foreach ($porSku['itens'] as $item) {
            $this->assertContains($this->ids($empresa)['V101'], array_column($item['itens'], 'variacao_id'));
        }

        $porNome = $this->listar($empresa, ['q' => 'MESA PÓLO']);
        $this->assertGreaterThan(0, $porNome['paginacao']['total']);
        foreach ($porNome['itens'] as $item) {
            $this->assertContains('Mesa Polo', array_column($item['itens'], 'produto_nome'));
        }
    }

    public function test_filtro_desconhecido_vira_sem_filtro(): void
    {
        [$empresa] = $this->cenario();

        $r = $this->listar($empresa, ['aba' => 'x', 'fase' => 'nada', 'familia' => '999999', 'tipo' => 'inexistente']);

        $this->assertSame('sugestoes', $r['aba']);
        $this->assertSame(29, $r['paginacao']['total']);
    }

    public function test_chaves_filtradas_so_com_filtro_ativo(): void
    {
        [$empresa] = $this->cenario();

        $this->assertSame([], $this->listar($empresa)['chaves_filtradas']);

        $polo = $this->valorDaFamilia($this->listar($empresa), 'Polo');
        $r = $this->listar($empresa, ['familia' => $polo]);

        $this->assertCount(23, $r['chaves_filtradas']);
        $this->assertSame(23, $r['paginacao']['total']);
    }

    public function test_chaves_filtradas_respeitam_o_lote(): void
    {
        [$empresa] = $this->cenario();
        config(['estrutura_geracao.lote_aceite' => 5]);

        $r = $this->listar($empresa, ['fase' => 'combo']);

        $this->assertCount(5, $r['chaves_filtradas']);
        $this->assertSame(5, $r['limites']['lote']);
    }

    public function test_teto_de_sugestoes(): void
    {
        [$empresa] = $this->cenario();
        config(['estrutura_geracao.teto_sugestoes' => 10]);

        $r = $this->listar($empresa);

        $this->assertTrue($r['excedeu_teto']);
        $this->assertSame(10, $r['teto']);
        $this->assertSame(10, $r['paginacao']['total']);
        $this->assertSame(29, $r['contagens']['sugestoes']);
    }

    public function test_aba_sem_tipo_e_candidatos(): void
    {
        [$empresa, $ator] = $this->cenario();

        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [[
            'grupo' => 'PX', 'codigo' => 'VX1', 'nome' => 'Assento Misto', 'eixo' => null, 'valor' => null,
            'volumes' => [], 'custo' => 10, 'familia' => 'Polo', 'ambientes' => ['Sala de jantar'], 'categoria_texto' => 'Bancos e Banquetas',
        ]], $ator);

        $r = $this->listar($empresa, ['aba' => 'sem_tipo']);

        $this->assertSame(2, $r['contagens']['sem_tipo']);
        $this->assertSame([], $r['itens']);
        $porNome = array_column($r['produtos_sem_tipo'], null, 'nome');
        $this->assertSame('Decoração', $porNome['Peça Decorativa Polo']['categoria']);
        $this->assertSame([], $porNome['Peça Decorativa Polo']['candidatos']);
        $this->assertSame([['slug' => 'banqueta', 'nome' => 'Banqueta'], ['slug' => 'banco', 'nome' => 'Banco']], $porNome['Assento Misto']['candidatos']);
    }

    public function test_descartada_vai_para_a_aba_descartadas(): void
    {
        [$empresa] = $this->cenario();
        $chave = $this->listar($empresa)['itens'][0]['chave'];
        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $chave, 'fase' => 'combo']);

        $vigentes = $this->listar($empresa);
        $this->assertSame(28, $vigentes['contagens']['sugestoes']);
        $this->assertSame(1, $vigentes['contagens']['descartadas']);
        $this->assertNotContains($chave, array_merge($this->chaves($vigentes), $this->chaves($this->listar($empresa, [], 2))));

        $d = $this->listar($empresa, ['aba' => 'descartadas']);
        $this->assertCount(1, $d['itens']);
        $this->assertSame($chave, $d['itens'][0]['chave']);
        $this->assertNotNull($d['itens'][0]['descartada_em']);
        $this->assertNull($d['itens'][0]['logistica']);
        $this->assertNull($d['itens'][0]['frete']);
        $this->assertNull($d['itens'][0]['custo']);
    }

    public function test_sku_repetido_quando_ja_existe_oferta_com_o_sku_sugerido(): void
    {
        [$empresa, $ator] = $this->cenario();
        $combo = null;
        foreach ($this->listar($empresa)['itens'] as $item) {
            if ($item['fase'] === 'combo') {
                $combo = $item;
                break;
            }
        }
        $this->assertNotNull($combo);
        $this->assertFalse($combo['sku_repetido']);

        app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => $combo['sku'], 'fase' => 'simples', 'nome' => 'Feita à mão', 'logistica' => 'mercado_envios',
        ], $ator);

        $depois = collect($this->listar($empresa)['itens'])->firstWhere('chave', $combo['chave']);
        $this->assertTrue($depois['sku_repetido']);
        $this->assertSame(1, collect($this->listar($empresa)['itens'])->where('sku_repetido', true)->count());
    }

    public function test_limites_vem_do_config(): void
    {
        [$empresa] = $this->cenario();

        $this->assertSame(['max_titulo' => 60, 'max_sku' => 120, 'lote' => 100, 'por_pagina' => 20], $this->listar($empresa)['limites']);
    }

    public function test_empresa_nao_ve_sugestao_de_outra(): void
    {
        $this->cenario();
        $outra = $this->empresaDoGabarito();

        $r = $this->listar($outra);

        $this->assertSame(['sugestoes' => 0, 'sem_tipo' => 0, 'descartadas' => 0], $r['contagens']);
        $this->assertFalse($r['tem_produtos']);
        $this->assertSame([], $r['itens']);
    }

    // ═══ Contrato com as telas 168-14/15 ═══

    private function chavesOrdenadas(array $a): array
    {
        $k = array_keys($a);
        sort($k);

        return $k;
    }

    public function test_contrato_do_item_da_pagina(): void
    {
        [$empresa] = $this->cenario();
        $item = $this->listar($empresa)['itens'][0];

        $this->assertSame(
            ['ambientes', 'avisos', 'chave', 'custo', 'descartada_em', 'familia', 'fase', 'frete', 'itens', 'logistica', 'nome', 'porque', 'sku', 'sku_repetido'],
            $this->chavesOrdenadas($item)
        );
        $this->assertSame(['id', 'nome'], $this->chavesOrdenadas($item['familia']));
        foreach ($item['itens'] as $i) {
            $this->assertSame(
                ['produto_id', 'produto_nome', 'quantidade', 'sku', 'tipo', 'tipo_nome', 'valor', 'variacao_id'],
                $this->chavesOrdenadas($i)
            );
        }
        $this->assertSame(['chave', 'pacote', 'peso_faturado', 'sem_medida'], $this->chavesOrdenadas($item['logistica']));
        $this->assertNull($item['descartada_em']);

        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $item['chave'], 'fase' => $item['fase']]);
        $d = $this->listar($empresa, ['aba' => 'descartadas'])['itens'][0];
        $this->assertSame($this->chavesOrdenadas($item), $this->chavesOrdenadas($d));
    }

    public function test_contrato_das_listas_produtos_sem_tipo_e_tipos(): void
    {
        [$empresa] = $this->cenario();
        $r = $this->listar($empresa);

        $this->assertNotEmpty($r['produtos']);
        foreach ($r['produtos'] as $id => $p) {
            $this->assertSame(
                ['ambientes', 'candidatos', 'categoria', 'familia', 'id', 'nome', 'qtd_combit', 'qtd_combo', 'tipo', 'tipo_escolhido', 'tipo_nome', 'tipo_origem'],
                $this->chavesOrdenadas($p)
            );
            $this->assertSame($id, $p['id']);
            foreach ($p['candidatos'] as $c) {
                $this->assertSame(['nome', 'slug'], $this->chavesOrdenadas($c));
            }
        }

        $this->assertNotEmpty($r['tipos']);
        foreach ($r['tipos'] as $t) {
            $this->assertSame(['id', 'nome', 'plural', 'qtd_combit', 'qtd_combo', 'slug'], $this->chavesOrdenadas($t));
        }

        $sem = $this->listar($empresa, ['aba' => 'sem_tipo'])['produtos_sem_tipo'];
        $this->assertNotEmpty($sem);
        foreach ($sem as $p) {
            $this->assertSame(
                ['ambientes', 'candidatos', 'categoria', 'familia', 'id', 'nome', 'qtd_combit', 'qtd_combo', 'tipo_escolhido'],
                $this->chavesOrdenadas($p)
            );
        }
    }

    /** @return array<string,int> variações por código */
    private function ids(Company $empresa): array
    {
        return \App\Models\EstruturaProdutoVariacao::where('company_id', $empresa->id)->pluck('id', 'codigo')->all();
    }
}
