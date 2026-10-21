<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\FamiliasDasOfertas;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * A Precificação dentro da ficha do produto e no desenho do simulador do onboarding (20/10/2026).
 *
 * Modos de falha que estes testes impedem: a ficha mostrar oferta de OUTRO produto (ou de outra
 * empresa); a prévia com o custo digitado GRAVAR o custo antes do "Salvar produto"; a repartição
 * do preço ("para onde vai cada real") não fechar com o preço; o salvar por JSON da ficha
 * devolver redirect; o que falta precificar ficar para o fim da lista; a rota nova não existir
 * no domínio do cliente (ou abrir mais do que o id numérico).
 */
class PrecificacaoNaFichaDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
    }

    /** Um produto com as variações dadas (código → custo, null = sem custo); devolve o id do produto. */
    private function produto(Company $empresa, string $grupo, array $variacoes): int
    {
        $linhas = [];
        foreach ($variacoes as $codigo => $custo) {
            $l = ['chave' => $codigo, 'grupo' => $grupo, 'codigo' => $codigo, 'nome' => "Produto {$grupo}", 'eixo' => 'cor', 'valor' => "Cor {$codigo}",
                'volumes' => [['c' => 50, 'l' => 40, 'a' => 10, 'kg' => 5]]];
            if ($custo !== null) {
                $l['custo'] = $custo;
            }
            $linhas[] = $l;
        }
        $r = app(ProdutoCadastroService::class)->gravarLinhas($empresa, $linhas, $this->atorCliente($empresa));
        $this->assertSame([], $r['erros'] ?? []);

        return (int) EstruturaProdutoVariacao::where('company_id', $empresa->id)->where('codigo', array_key_first($variacoes))->value('produto_id');
    }

    private function oferta(Company $empresa, string $sku): EstruturaOferta
    {
        return EstruturaOferta::where('company_id', $empresa->id)->where('sku', $sku)->firstOrFail();
    }

    /**
     * 21/10/2026 — o usuário viu, na ficha da cadeira, o kit com a mesa (outro produto) e os combos, e pediu
     * "apenas do produto que estou cadastrando": a ficha traz só a oferta de cada variação.
     */
    public function test_a_ficha_traz_so_as_variacoes_do_produto_sem_combo_kit_nem_outro_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $cadeira = $this->produto($empresa, 'CAD', ['CAD-AZ' => '100', 'CAD-VD' => '100']);
        $this->produto($empresa, 'MSA', ['MSA-1' => '300']);
        $svc = app(EstruturaOfertaService::class);
        $svc->criar($empresa, ['sku' => 'CAD-AZ-CB2', 'fase' => 'combo', 'nome' => 'Combo 2', 'componentes' => [['id' => $this->oferta($empresa, 'CAD-AZ')->id, 'quantidade' => 2]]], $ator);
        $svc->criar($empresa, ['sku' => 'KIT-MC', 'fase' => 'kit', 'nome' => 'Kit', 'componentes' => [
            ['id' => $this->oferta($empresa, 'MSA-1')->id, 'quantidade' => 1], ['id' => $this->oferta($empresa, 'CAD-VD')->id, 'quantidade' => 1]]], $ator);

        $r = app(EstruturaPrecificacaoService::class)->doProduto($empresa, $cadeira);

        $skus = array_map(fn ($x) => $x['oferta']['sku'], $r['ofertas']);
        sort($skus);
        $this->assertSame(['CAD-AZ', 'CAD-VD'], $skus);
        $this->assertSame(2, $r['resumo']['total']);
        // Sem frete digitado e com medidas, o frete vem sugerido pela tabela: as duas já têm preço.
        $this->assertSame(2, $r['resumo']['precificadas']);

        $porSku = array_column(array_map(fn ($x) => ['sku' => $x['oferta']['sku'], 'x' => $x], $r['ofertas']), 'x', 'sku');
        $this->assertTrue($porSku['CAD-AZ']['calculo']['do_produto']);
        $this->assertSame(100.0, $porSku['CAD-AZ']['calculo']['custo']['valor']);
        $this->assertSame(EstruturaProdutoVariacao::where('codigo', 'CAD-AZ')->value('id'), $porSku['CAD-AZ']['oferta']['variacao_id']);
        $this->assertSame('simples', $porSku['CAD-VD']['oferta']['fase']);
    }

    public function test_as_partes_repartem_o_preco_e_fecham_com_ele(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa, 'P', ['P-1' => '100']);
        app(EstruturaPrecificacaoService::class)->salvarOferta($this->oferta($empresa, 'P-1'), ['frete_classico' => '20', 'frete_premium' => '25'], $this->atorCliente($empresa));

        $c = app(EstruturaPrecificacaoService::class)->doProduto($empresa, $produto)['ofertas'][0]['calculo'];
        $t = $c['classico'];

        $this->assertSame(['custo', 'frete', 'comissao', 'imposto', 'margem_contribuicao', 'lucro_liquido'], array_keys($t['partes']));
        $this->assertSame(100.0, $t['partes']['custo']);
        $this->assertSame(20.0, $t['partes']['frete']);
        $this->assertEqualsWithDelta($t['minimo'], array_sum($t['partes']), 0.05);
        $this->assertEqualsWithDelta($t['minimo'] * $c['percentuais']['lucro_liquido'] / 100, $t['partes']['lucro_liquido'], 0.01);
        $this->assertSame(25.0, $c['premium']['partes']['frete']);
        // Sem custo, nada a repartir.
        $this->assertNull(PrecificacaoEstrutura::partes(null, null, 20.0, 11.5, 7, 6, 6));
    }

    public function test_a_previa_usa_o_custo_digitado_sem_gravar_e_vazio_e_sem_custo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa, 'P', ['P-1' => '100']);
        $variacao = EstruturaProdutoVariacao::where('codigo', 'P-1')->firstOrFail();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $url = fn (array $q = []) => route('portal.auth.estrutura.produtos.precificacao', ['produto' => $produto, ...$q]);

        $salvo = $sessao->getJson($url())->assertOk()->json('ofertas.0.calculo');
        $previa = $sessao->getJson($url(['custos' => [$variacao->id => '150.00']]))->assertOk()->json('ofertas.0.calculo');

        $this->assertSame(100, (int) $salvo['custo']['valor']);
        $this->assertSame(150, (int) $previa['custo']['valor']);
        $this->assertGreaterThan($salvo['classico']['minimo'], $previa['classico']['minimo']);
        // A prévia não grava: o custo do produto continua o salvo.
        $this->assertSame(100.0, (float) $variacao->fresh()->custo);

        // Campo apagado na ficha = sem custo (não cai no salvo).
        $vazio = $sessao->getJson($url(['custos' => [$variacao->id => '']]))->assertOk()->json('ofertas.0.calculo');
        $this->assertNull($vazio['custo']['valor']);
        $this->assertSame('sem_custo', $vazio['pendencia']);

        // Custo inválido é recusado; custo de variação de OUTRO produto é ignorado.
        $sessao->getJson($url(['custos' => [$variacao->id => '-1']]))->assertStatus(422);
        $outro = $this->produto($empresa, 'Q', ['Q-1' => '50']);
        $deOutro = EstruturaProdutoVariacao::where('codigo', 'Q-1')->value('id');
        $this->assertSame(100, (int) $sessao->getJson($url(['custos' => [$deOutro => '999']]))->json('ofertas.0.calculo.custo.valor'));
        $this->assertNotSame($produto, $outro);

        // Abrir a precificação não pergunta nada ao Mercado Livre.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'mercadolibre.com'));
    }

    public function test_produto_de_outra_empresa_e_404(): void
    {
        $empresa = $this->empresaDoGabarito();
        $alheia = $this->empresaDoGabarito();
        $doOutro = $this->produto($alheia, 'X', ['X-1' => '10']);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.produtos.precificacao', $doOutro))->assertNotFound();
    }

    public function test_salvar_por_json_responde_json_e_grava_e_o_inertia_segue_com_redirect(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->produto($empresa, 'P', ['P-1' => '100']);
        $o = $this->oferta($empresa, 'P-1');
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->putJson(route('portal.auth.estrutura.precificacao.oferta', $o->id), ['frete_classico' => '31.5', 'frete_premium' => '', 'imposto' => '9'])
            ->assertOk()->assertJson(['mensagem' => 'Preço de P-1 salvo.']);
        $linha = EstruturaPrecificacao::where('oferta_id', $o->id)->firstOrFail();
        $this->assertSame(31.5, (float) $linha->frete_classico);
        $this->assertNull($linha->frete_premium);
        $this->assertSame(9.0, (float) $linha->imposto);

        // Erro de validação também em JSON (a ficha mostra a mensagem ao lado do "Salvo").
        $sessao->putJson(route('portal.auth.estrutura.precificacao.oferta', $o->id), ['frete_classico' => '-3'])
            ->assertStatus(422)->assertJsonValidationErrors('frete_classico');

        // A tela da Precificação (formulário comum) continua voltando com redirect.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o->id), ['frete_classico' => '30'])->assertRedirect();
    }

    public function test_o_que_falta_precificar_vem_primeiro_sem_quebrar_a_familia(): void
    {
        $bloco = fn (int $id, string $fase = 'simples') => ['principal' => ['id' => $id, 'fase' => $fase], 'ofertas' => [['id' => $id]]];
        $blocos = [$bloco(1), $bloco(2), $bloco(3), $bloco(4, 'kit'), $bloco(5)];
        $familias = [1 => ['id' => 10, 'nome' => 'Aparador'], 2 => ['id' => 10, 'nome' => 'Aparador'], 3 => ['id' => 20, 'nome' => 'Banco'],
            4 => ['id' => 20, 'nome' => 'Banco'], 5 => null];
        $ids = fn (array $b) => array_map(fn ($x) => $x['principal']['id'], $b);

        // Sem pendência: a ordem de sempre (família pelo nome, conjunto no fim, "sem família" por último).
        $this->assertSame([1, 2, 3, 4, 5], $ids(FamiliasDasOfertas::ordenar($blocos, $familias)));
        // O banco tem pendência no kit: a família inteira vem primeiro, e o kit continua depois do produto dela.
        $this->assertSame([3, 4, 1, 2, 5], $ids(FamiliasDasOfertas::ordenar($blocos, $familias, [4 => true])));
        // Dentro da família, o produto pendente passa à frente do pronto.
        $this->assertSame([2, 1, 3, 4, 5], $ids(FamiliasDasOfertas::ordenar($blocos, $familias, [2 => true])));
        // "Sem família" com pendência sobe na frente das famílias prontas.
        $this->assertSame([5, 1, 2, 3, 4], $ids(FamiliasDasOfertas::ordenar($blocos, $familias, [5 => true])));
    }

    public function test_a_pagina_da_precificacao_poe_o_pendente_na_frente_e_aceita_a_vista(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->produto($empresa, 'A', ['A-1' => '100']);
        $this->produto($empresa, 'B', ['B-1' => null]);   // sem custo: pendente
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $props = $sessao->get(route('portal.auth.estrutura.precificacao', ['vista' => 'lista']))->assertOk()->viewData('page')['props'];
        $primeiro = $props['estrutura']['blocos'][0]['principal']['sku'];
        $this->assertSame('B-1', $primeiro);
        $this->assertSame('lista', $props['filtros']['vista']);
        $this->assertNull($sessao->get(route('portal.auth.estrutura.precificacao', ['vista' => 'outra']))->viewData('page')['props']['filtros']['vista']);
    }

    public function test_a_rota_nova_existe_no_dominio_do_cliente_so_com_id_numerico(): void
    {
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/18/precificacao'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/abc/precificacao'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/18/precificacao/x'));
    }
}
