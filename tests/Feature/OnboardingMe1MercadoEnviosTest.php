<?php

namespace Tests\Feature;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integração ponta-a-ponta: ao salvar a Planilha de Produtos pelo link público
 * (/implementacao), se ALGUMA embalagem excede o Mercado Envios (maior lado > 200cm,
 * soma dos lados > 300cm ou peso > 50kg), o campo me1 da empresa (coluna ME1 do
 * Painel Polos) vai automaticamente para "Precisa de ME1". Se não excede, a coluna
 * não é alterada.
 *
 * @group onboarding
 */
class OnboardingMe1MercadoEnviosTest extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(array $implOpts = []): MlbImplementacao
    {
        $criador = User::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'       => 'Loja ME ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => $criador->id,
        ]);

        return MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ], $implOpts));
    }

    /** Simula o front salvando a Planilha de Produtos (rows) via PATCH público. */
    private function salvarProdutos(MlbImplementacao $impl, array $produtos)
    {
        return $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => 'planilha_produtos',
            'campo' => 'produtos',
            'valor' => $produtos,
        ]);
    }

    public function test_embalagem_excedente_marca_me1_precisa_de_me1(): void
    {
        $impl = $this->criarImpl(); // me1 nulo

        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Item grande', 'altura_emb' => '210', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5'],
        ])->assertOk();

        $impl->refresh();
        $this->assertEquals('Precisa de ME1', $impl->me1);
    }

    public function test_embalagem_dentro_do_limite_nao_altera_me1(): void
    {
        $impl = $this->criarImpl(); // me1 nulo

        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Item ok', 'altura_emb' => '50', 'largura_emb' => '50', 'prof_emb' => '50', 'peso_emb_kg' => '10'],
        ])->assertOk();

        $impl->refresh();
        $this->assertNull($impl->me1); // coluna intocada
    }

    public function test_dentro_do_limite_nao_sobrescreve_me1_existente(): void
    {
        // ME1 já resolvido manualmente; planilha dentro do limite não deve mexer nele.
        $impl = $this->criarImpl(['me1' => 'Ativo']);

        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Item ok', 'altura_emb' => '50', 'largura_emb' => '50', 'prof_emb' => '50', 'peso_emb_kg' => '10'],
        ])->assertOk();

        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1); // inalterado
    }

    public function test_excedente_sobrescreve_me1_existente(): void
    {
        // Estava "Sem itens ainda"; planilha excedente força "Precisa de ME1".
        $impl = $this->criarImpl(['me1' => 'Sem itens ainda']);

        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Item pesado', 'altura_emb' => '10', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '90'],
        ])->assertOk();

        $impl->refresh();
        $this->assertEquals('Precisa de ME1', $impl->me1);
    }

    public function test_salvar_outro_item_nao_toca_me1(): void
    {
        // Um save que não é planilha_produtos/produtos jamais mexe no ME1.
        $impl = $this->criarImpl();

        $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => 'hub',
            'campo' => 'acesso',
            'valor' => 'hub.exemplo.com',
        ])->assertOk();

        $impl->refresh();
        $this->assertNull($impl->me1);
    }
}
