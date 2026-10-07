<?php

namespace Tests\Feature\Phase169;

use App\Models\PubProduto;
use App\Models\PubProdutoFatoCriativo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 169, Plano 01, Task 1 — tabela e model de fatos confirmados pelo
 * operador (TXT-01/TXT-02). Prova a disciplina de FK `nullOnDelete()`
 * (T-169-02 do threat model): apagar o produto NUNCA apaga o histórico.
 */
class PubProdutoFatoCriativoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_beneficio_e_medida_para_o_mesmo_produto(): void
    {
        $produto = PubProduto::create(['sku' => 'SKU-169', 'nome' => 'Produto de teste']);
        $user = User::factory()->create();

        $beneficio = PubProdutoFatoCriativo::create([
            'pub_produto_id'    => $produto->id,
            'tipo'              => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'             => 'Estrutura reforçada, suporta até 150kg',
            'confirmado_por_id' => $user->id,
        ]);

        $medida = PubProdutoFatoCriativo::create([
            'pub_produto_id'    => $produto->id,
            'tipo'              => PubProdutoFatoCriativo::TIPO_MEDIDA,
            'texto'             => 'Largura 80cm, altura 45cm, profundidade 40cm',
            'confirmado_por_id' => $user->id,
        ]);

        $this->assertDatabaseHas('pub_produto_fatos_criativo', ['id' => $beneficio->id, 'pub_produto_id' => $produto->id, 'tipo' => 'beneficio']);
        $this->assertDatabaseHas('pub_produto_fatos_criativo', ['id' => $medida->id, 'pub_produto_id' => $produto->id, 'tipo' => 'medida']);

        $this->assertSame($produto->id, $beneficio->produto->id);
        $this->assertSame($user->id, $beneficio->confirmadoPor->id);
    }

    public function test_apagar_o_produto_zera_a_coluna_e_preserva_as_linhas(): void
    {
        $produto = PubProduto::create(['sku' => 'SKU-169-DEL', 'nome' => 'Produto a apagar']);

        $beneficio = PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produto->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'          => 'Ponto forte qualquer',
        ]);
        $medida = PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produto->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_MEDIDA,
            'texto'          => 'Medida qualquer',
        ]);

        $produto->delete();

        $this->assertDatabaseHas('pub_produto_fatos_criativo', ['id' => $beneficio->id, 'pub_produto_id' => null]);
        $this->assertDatabaseHas('pub_produto_fatos_criativo', ['id' => $medida->id, 'pub_produto_id' => null]);
    }

    public function test_usuario_removido_nao_impede_a_leitura_do_fato(): void
    {
        $produto = PubProduto::create(['sku' => 'SKU-169-USR', 'nome' => 'Produto qualquer']);
        $user = User::factory()->create();

        $fato = PubProdutoFatoCriativo::create([
            'pub_produto_id'    => $produto->id,
            'tipo'              => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'             => 'Fato confirmado',
            'confirmado_por_id' => $user->id,
        ]);

        // forceDelete(): User usa SoftDeletes — um delete() comum só marca
        // deleted_at e NÃO aciona o nullOnDelete() da FK (que só dispara em
        // DELETE real). O cenário que a FK protege é a exclusão definitiva.
        $user->forceDelete();

        $this->assertDatabaseHas('pub_produto_fatos_criativo', ['id' => $fato->id, 'confirmado_por_id' => null]);
        $this->assertNull($fato->fresh()->confirmadoPor);
    }
}
