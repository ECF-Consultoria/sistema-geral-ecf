<?php

namespace Tests\Feature\Quick260806;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Quick 260806-fnh — Check-in do Publicador por SKU + variações de produto.
 *
 * Dois contratos que este teste protege:
 *  1. O check-in vive em `dados.publicador_checkin` (chave top-level). Ele NÃO pode
 *     ser apagado quando o cliente salva a planilha de produtos, que reescreve o
 *     array `dados.itens.planilha_produtos.produtos` inteiro.
 *  2. Os campos de variação (variacao_grupo / variacao_tipo / variacao_valor) são
 *     campos NOVOS por linha — a lista continua plana, então a regra automática do
 *     ME1 e o resto do onboarding seguem funcionando.
 *
 * @group onboarding
 */
class CheckinEVariacoesTest extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(): MlbImplementacao
    {
        $criador = User::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'       => 'Loja Checkin ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => $criador->id,
        ]);

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ]);
    }

    private function checkin(MlbImplementacao $impl, string $sku, bool $feito)
    {
        return $this->patchJson(route('implementacao.publicador.checkin', $impl->token), [
            'sku'   => $sku,
            'feito' => $feito,
        ]);
    }

    private function salvarProdutos(MlbImplementacao $impl, array $produtos)
    {
        return $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => 'planilha_produtos',
            'campo' => 'produtos',
            'valor' => $produtos,
        ]);
    }

    /** @test */
    public function check_in_marca_e_desmarca_o_sku(): void
    {
        $impl = $this->criarImpl();

        $this->checkin($impl, 'CAD-001', true)
            ->assertOk()
            ->assertJson(['ok' => true, 'total' => 1]);

        $this->assertTrue($impl->fresh()->dados['publicador_checkin']['CAD-001']);

        $this->checkin($impl, 'CAD-001', false)
            ->assertOk()
            ->assertJson(['ok' => true, 'total' => 0]);

        $this->assertArrayNotHasKey('CAD-001', $impl->fresh()->dados['publicador_checkin']);
    }

    /** @test */
    public function check_in_exige_sku_e_flag(): void
    {
        $impl = $this->criarImpl();

        $this->checkin($impl, '', true)->assertStatus(422);
        $this->patchJson(route('implementacao.publicador.checkin', $impl->token), ['sku' => 'X1'])
            ->assertStatus(422);
    }

    /** @test */
    public function cliente_salvando_a_planilha_nao_apaga_o_check_in(): void
    {
        $impl = $this->criarImpl();

        $this->checkin($impl, 'CAD-001', true)->assertOk();

        // Cliente reescreve o array inteiro de produtos depois do check-in.
        $this->salvarProdutos($impl, [
            ['sku' => 'CAD-001', 'produto' => 'Cadeira Gamer'],
            ['sku' => 'CAD-002', 'produto' => 'Mesa'],
        ])->assertOk();

        $dados = $impl->fresh()->dados;
        $this->assertTrue($dados['publicador_checkin']['CAD-001'] ?? false);
        $this->assertCount(2, $dados['itens']['planilha_produtos']['produtos']);
    }

    /** @test */
    public function campos_de_variacao_persistem_e_chegam_na_visao_do_publicador(): void
    {
        $impl = $this->criarImpl();

        $this->salvarProdutos($impl, [
            ['sku' => 'CAD-001', 'produto' => 'Cadeira Gamer', 'descricao' => 'Boa cadeira',
             'variacao_grupo' => 'vabc123', 'variacao_tipo' => 'Cor', 'variacao_valor' => 'Preta'],
            ['sku' => 'CAD-002', 'produto' => 'Cadeira Gamer', 'descricao' => 'Boa cadeira',
             'variacao_grupo' => 'vabc123', 'variacao_tipo' => 'Cor', 'variacao_valor' => 'Azul'],
        ])->assertOk();

        $produtos = $impl->fresh()->dados['itens']['planilha_produtos']['produtos'];
        $this->assertSame('vabc123', $produtos[0]['variacao_grupo']);
        $this->assertSame('Azul',    $produtos[1]['variacao_valor']);

        $this->checkin($impl, 'CAD-001', true)->assertOk();

        $this->get(route('implementacao.publicador', $impl->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mlb/ImplementacaoPublicador')
                ->where('impl.checkin.CAD-001', true)
                ->where('impl.dados.itens.planilha_produtos.produtos.1.variacao_valor', 'Azul')
            );
    }

    /** @test */
    public function variacao_nao_atrapalha_a_regra_automatica_do_me1(): void
    {
        $impl = $this->criarImpl();

        // Duas variações do mesmo produto, uma delas com embalagem fora do Mercado Envios.
        $this->salvarProdutos($impl, [
            ['sku' => 'ARM-P', 'produto' => 'Armário', 'variacao_grupo' => 'g1', 'variacao_tipo' => 'Cor',
             'variacao_valor' => 'Branco', 'altura_emb' => '210', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5'],
            ['sku' => 'ARM-A', 'produto' => 'Armário', 'variacao_grupo' => 'g1', 'variacao_tipo' => 'Cor',
             'variacao_valor' => 'Amadeirado', 'altura_emb' => '50', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5'],
        ])->assertOk();

        $this->assertSame('Precisa de ME1', $impl->fresh()->me1);
    }
}
