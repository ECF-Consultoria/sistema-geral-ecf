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
 * Painel Polos) vai automaticamente para "Precisa de ME1" — a MENOS que o ME1 esteja
 * travado por edição manual do consultor (me1_manual). Se não excede, a coluna não é
 * alterada.
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

    /** Produto excedente conveniente (maior lado > 200cm). */
    private function produtoExcedente(): array
    {
        return ['sku' => 'A1', 'produto' => 'Item grande', 'altura_emb' => '210', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5'];
    }

    // ─── Regra automática ────────────────────────────────────────────────────

    public function test_embalagem_excedente_marca_me1_precisa_de_me1(): void
    {
        $impl = $this->criarImpl(); // me1 nulo, não travado

        $this->salvarProdutos($impl, [$this->produtoExcedente()])->assertOk();

        $impl->refresh();
        $this->assertEquals('Precisa de ME1', $impl->me1);
        $this->assertFalse($impl->me1_manual); // marca automática NÃO trava
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

    public function test_dentro_do_limite_limpa_me1_automatico(): void
    {
        $impl = $this->criarImpl(); // me1 nulo, não travado

        // 1) Excede → marca "Precisa de ME1".
        $this->salvarProdutos($impl, [$this->produtoExcedente()])->assertOk();
        $impl->refresh();
        $this->assertEquals('Precisa de ME1', $impl->me1);
        $this->assertFalse($impl->me1_manual);

        // 2) Volta a caber (50x50x50 / 30kg) → limpa o valor automático (reativo).
        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Item ok', 'altura_emb' => '50', 'largura_emb' => '50', 'prof_emb' => '50', 'peso_emb_kg' => '30'],
        ])->assertOk();
        $impl->refresh();
        $this->assertNull($impl->me1);
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

    // ─── Trava manual (me1_manual) ───────────────────────────────────────────

    public function test_excedente_nao_sobrescreve_me1_travado_manual(): void
    {
        // ME1 já travado manualmente — a regra automática não toca.
        $impl = $this->criarImpl(['me1' => 'Ativo', 'me1_manual' => true]);

        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Pesado', 'altura_emb' => '10', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '90'],
        ])->assertOk();

        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
    }

    public function test_dentro_do_limite_nao_limpa_me1_manual(): void
    {
        // ME1 travado manualmente ("Ativo"); planilha dentro do limite NÃO deve limpar.
        $impl = $this->criarImpl(['me1' => 'Ativo', 'me1_manual' => true]);

        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Item ok', 'altura_emb' => '50', 'largura_emb' => '50', 'prof_emb' => '50', 'peso_emb_kg' => '30'],
        ])->assertOk();

        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
    }

    public function test_edicao_manual_via_ficha_trava_e_regra_nao_reverte(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $impl  = $this->criarImpl();

        // 1) Cliente sobe planilha excedente → auto marca "Precisa de ME1" (não travado).
        $this->salvarProdutos($impl, [$this->produtoExcedente()])->assertOk();
        $impl->refresh();
        $this->assertEquals('Precisa de ME1', $impl->me1);
        $this->assertFalse($impl->me1_manual);

        // 2) Consultor ativa o ME1 manualmente pela ficha → trava.
        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.logistica', $impl), ['me1' => 'Ativo'])
            ->assertRedirect();
        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
        $this->assertTrue($impl->me1_manual);

        // 3) Cliente re-salva planilha ainda excedente → NÃO reverte (respeita a trava).
        $this->salvarProdutos($impl, [
            ['sku' => 'A1', 'produto' => 'Maior', 'altura_emb' => '260', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5'],
        ])->assertOk();
        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
    }

    public function test_edicao_manual_via_painel_polos_trava_a_regra(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $impl  = $this->criarImpl();
        $empresaId = $impl->empresa_id;

        // Consultor ativa o ME1 manualmente pelo Painel Polos (edição em massa) → trava.
        $this->actingAs($admin)
            ->post(route('mlb.polos-painel.bulk'), [
                'items' => [
                    ['id' => $empresaId, 'changes' => ['me1' => 'Ativo']],
                ],
            ])
            ->assertOk();

        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
        $this->assertTrue($impl->me1_manual);

        // Cliente sobe planilha excedente → NÃO reverte.
        $this->salvarProdutos($impl, [$this->produtoExcedente()])->assertOk();
        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
    }

    public function test_limpar_me1_na_ficha_destrava_a_regra(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $impl  = $this->criarImpl(['me1' => 'Ativo', 'me1_manual' => true]);

        // Consultor limpa o ME1 → destrava.
        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.logistica', $impl), ['me1' => null])
            ->assertRedirect();
        $impl->refresh();
        $this->assertNull($impl->me1);
        $this->assertFalse($impl->me1_manual);

        // Cliente sobe planilha excedente → auto volta a marcar.
        $this->salvarProdutos($impl, [$this->produtoExcedente()])->assertOk();
        $impl->refresh();
        $this->assertEquals('Precisa de ME1', $impl->me1);
    }
}
