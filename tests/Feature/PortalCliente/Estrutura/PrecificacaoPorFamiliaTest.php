<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\Company;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\EstruturaVisaoService;
use App\Services\Portal\Estrutura\FamiliasDasOfertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * 11/10/2026 — a Precificação agrupada por família. O usuário: "uma lista inteira sem saber o que é";
 * queria o produto com as variações dele embaixo, e o kit ("um produto de um e de outro") sem aparecer
 * duas vezes.
 *
 * O servidor diz a família de cada bloco e ordena por ela ANTES de paginar; a tela só desenha.
 */
class PrecificacaoPorFamiliaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** Liga a oferta a um produto do cadastro, com ou sem família. */
    private function noCadastro(Company $empresa, EstruturaOferta $oferta, ?string $familia): void
    {
        $f = $familia !== null ? EstruturaFamilia::firstOrCreate(['company_id' => $empresa->id, 'nome' => $familia]) : null;
        $p = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => $oferta->sku, 'nome' => $oferta->nome ?? $oferta->sku, 'familia_id' => $f?->id]);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $empresa->id, 'ordem' => 1, 'codigo' => $oferta->sku]);
        $oferta->forceFill(['variacao_id' => $v->id])->save();
    }

    private function blocos($sessao, array $query = []): array
    {
        return $sessao->get(route('portal.auth.estrutura.precificacao', $query))->assertOk()->viewData('page')['props']['estrutura']['blocos'];
    }

    public function test_cada_bloco_diz_a_familia_e_a_lista_vem_por_familia_com_os_conjuntos_no_fim(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        // A cadeira é da Sala de Jantar; a mesa fica sem família no cadastro.
        $this->noCadastro($empresa, $o['CAD-01'], 'Sala de Jantar');
        $this->noCadastro($empresa, $o['MSA-MR'], null);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $blocos = $this->blocos($sessao);
        $resumo = array_map(fn ($b) => [$b['principal']['sku'], $b['familia']['nome'] ?? null], $blocos);

        // Kit e combit têm a mesa (sem família) primeiro e a cadeira depois: ficam na família do primeiro
        // componente que TEM família, e depois do produto dela. "Sem família" vai por último.
        $this->assertSame([
            ['CAD-01', 'Sala de Jantar'],
            ['MSA-MR+CAD-01-KIT', 'Sala de Jantar'],
            ['MSA-MR+CAD-01-CBT4', 'Sala de Jantar'],
            ['MSA-MR', null],
        ], $resumo);

        // O produto vem com os combos dele no mesmo bloco, e sabe em que conjuntos entra.
        $cadeira = $blocos[0];
        $this->assertSame(['CAD-01', 'CAD-01-CB2', 'CAD-01-CB3', 'CAD-01-CB4', 'CAD-01-CB5', 'CAD-01-CB6'], array_column($cadeira['ofertas'], 'sku'));
        $this->assertEqualsCanonicalizing(['MSA-MR+CAD-01-KIT', 'MSA-MR+CAD-01-CBT4'], array_column($cadeira['tambem_em'], 'sku'));
        $this->assertSame(['id', 'nome'], array_keys($cadeira['familia']));
    }

    public function test_o_combo_herda_a_familia_do_produto_e_quem_nao_esta_no_cadastro_fica_sem_familia(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $this->noCadastro($empresa, $o['CAD-01'], 'Sala de Jantar');

        $familias = FamiliasDasOfertas::daEmpresa($empresa, \App\Services\Portal\Estrutura\EstruturaConjunto::daEmpresa($empresa));

        $this->assertSame('Sala de Jantar', $familias[$o['CAD-01']->id]['nome']);
        $this->assertSame('Sala de Jantar', $familias[$o['CAD-01-CB4']->id]['nome'], 'o combo não tem variação própria: herda a do produto');
        $this->assertSame('Sala de Jantar', $familias[$o['MSA-MR+CAD-01-KIT']->id]['nome'], 'a mesa não tem família; vale a da cadeira');
        $this->assertNull($familias[$o['MSA-MR']->id], 'oferta da Lista SKUs, fora do cadastro');
    }

    public function test_o_filtro_de_tipo_deixa_passar_um_tipo_so_e_tipo_invalido_nao_filtra(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $this->noCadastro($empresa, $o['CAD-01'], 'Sala de Jantar');
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $skus = fn (array $blocos) => array_merge(...array_map(fn ($b) => array_column($b['ofertas'], 'sku'), $blocos));

        // Só os combos: o bloco é o da cadeira (a família continua a dela), sem a própria cadeira.
        $combos = $this->blocos($sessao, ['tipo' => 'combo']);
        $this->assertSame(['CAD-01-CB2', 'CAD-01-CB3', 'CAD-01-CB4', 'CAD-01-CB5', 'CAD-01-CB6'], $skus($combos));
        $this->assertSame('Sala de Jantar', $combos[0]['familia']['nome']);

        $this->assertSame(['MSA-MR+CAD-01-KIT'], $skus($this->blocos($sessao, ['tipo' => 'kit'])));
        $this->assertCount(9, $skus($this->blocos($sessao, ['tipo' => 'qualquer-coisa'])));

        // O filtro volta para a tela, e a contagem dos tipos é a da empresa inteira (não a do filtro).
        $props = $sessao->get(route('portal.auth.estrutura.precificacao', ['tipo' => 'combo']))->viewData('page')['props'];
        $this->assertSame('combo', $props['filtros']['tipo']);
        $this->assertSame(['simples' => 2, 'combo' => 5, 'kit' => 1, 'combit' => 1], $props['estrutura']['painel']['por_fase']);
        $this->assertNull($sessao->get(route('portal.auth.estrutura.precificacao', ['tipo' => 'x']))->viewData('page')['props']['filtros']['tipo']);
    }

    /** A ordem por família é pedida só pela Precificação: as outras telas seguem por vendas, sem a chave nova. */
    public function test_as_outras_telas_do_mapeamento_nao_mudam(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $this->noCadastro($empresa, $o['MSA-MR'], 'Aaa primeira no alfabeto');

        $padrao = app(EstruturaVisaoService::class)->paginaOfertas($empresa, 'todas', '', 1);

        $this->assertSame(['CAD-01', 'MSA-MR', 'MSA-MR+CAD-01-KIT', 'MSA-MR+CAD-01-CBT4'], array_map(fn ($b) => $b['principal']['sku'], $padrao['blocos']));
        $this->assertArrayNotHasKey('familia', $padrao['blocos'][0]);
    }

    public function test_ordenar_e_estavel_familias_pelo_nome_produto_antes_de_conjunto_sem_familia_no_fim(): void
    {
        $b = fn (int $id, string $fase) => ['principal' => ['id' => $id, 'fase' => $fase]];
        $familias = [
            1 => null, 2 => ['id' => 9, 'nome' => 'Quarto'], 3 => ['id' => 4, 'nome' => 'cozinha'], 4 => ['id' => 9, 'nome' => 'Quarto'],
            5 => ['id' => 9, 'nome' => 'Quarto'], 6 => null, 7 => ['id' => 4, 'nome' => 'cozinha'],
        ];
        // Chegam na ordem de vendas: 1, 2 (kit do Quarto), 3, 4, 5, 6 (kit sem família), 7.
        $ordenados = FamiliasDasOfertas::ordenar([$b(1, 'simples'), $b(2, 'kit'), $b(3, 'simples'), $b(4, 'simples'), $b(5, 'simples'), $b(6, 'combit'), $b(7, 'simples')], $familias);

        // cozinha (3, 7) · Quarto: produtos (4, 5) e depois o kit (2) · sem família: produto (1) e depois o combit (6).
        $this->assertSame([3, 7, 4, 5, 2, 1, 6], array_map(fn ($x) => $x['principal']['id'], $ordenados));
    }
}
