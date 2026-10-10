<?php

namespace Tests\Feature\Publicador\Lote;

use App\Models\Company;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Support\Publicador\NaFilaDePublicacao;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * As duas tabelas da fila de publicação em lote (10/10/2026): é o BANCO que garante uma fila viva por conta e um
 * produto numa fila só (colunas-sombra `conta_ativa`/`produto_ativo`, unique com NULL repetindo), a fila apaga os
 * itens em cascata e as chaves novas da config nascem com o gatilho de imagens DESLIGADO.
 */
class FilaDePublicacaoSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function fila(string $chave, string $status = PubFilaPublicacao::ATIVA): PubFilaPublicacao
    {
        return PubFilaPublicacao::create([
            'conta_chave' => $chave,
            'conta_ativa' => in_array($status, PubFilaPublicacao::VIVAS, true) ? $chave : null,
            'status' => $status,
        ]);
    }

    private function produto(Company $c, string $sku): PubProduto
    {
        return PubProduto::create(['company_id' => $c->id, 'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    public function test_as_colunas_do_desenho_existem(): void
    {
        $this->assertTrue(Schema::hasColumns('pub_filas_publicacao', [
            'conta_chave', 'conta_ativa', 'company_id', 'mlb_empresa_id', 'status', 'intervalo_minutos', 'janela_inicio', 'janela_fim',
            'proximo_em', 'motivo_pausa', 'criada_por', 'iniciada_em', 'concluida_em',
        ]));
        $this->assertTrue(Schema::hasColumns('pub_fila_publicacao_itens', [
            'fila_id', 'produto_id', 'rascunho_id', 'produto_ativo', 'posicao', 'status', 'validacao_id', 'plano_hash', 'revisao', 'ciente',
            'resumo', 'publicacao_id', 'iniciado_em', 'concluido_em', 'motivo',
        ]));
        $this->assertSame(10, (int) PubFilaPublicacao::create(['conta_chave' => 'company-1'])->fresh()->intervalo_minutos, 'o intervalo padrão é 10 minutos');
    }

    public function test_uma_fila_viva_por_conta_e_as_terminadas_nao_contam(): void
    {
        $this->fila('company-7');
        $this->fila('company-7', PubFilaPublicacao::CONCLUIDA);
        $this->fila('company-7', PubFilaPublicacao::CANCELADA);
        $this->fila('company-8');

        $this->expectException(QueryException::class);
        $this->fila('company-7', PubFilaPublicacao::PAUSADA);
    }

    public function test_um_produto_em_um_item_vivo_so_e_a_fila_leva_os_itens_junto(): void
    {
        $c = Company::factory()->create();
        $p = $this->produto($c, 'CAD-01');
        $a = $this->fila('company-'.$c->id);
        $b = $this->fila('company-99');

        $a->itens()->create(['produto_id' => $p->id, 'produto_ativo' => $p->id, 'posicao' => 1]);
        // Itens que já saíram da fila (sem `produto_ativo`) repetem à vontade.
        $b->itens()->create(['produto_id' => $p->id, 'produto_ativo' => null, 'posicao' => 1, 'status' => PubFilaPublicacaoItem::PUBLICADO]);
        $b->itens()->create(['produto_id' => $p->id, 'produto_ativo' => null, 'posicao' => 2, 'status' => PubFilaPublicacaoItem::CANCELADO]);
        $this->assertTrue(NaFilaDePublicacao::emUso($p->id));
        $this->assertSame([$p->id => true], NaFilaDePublicacao::dentre([$p->id, 999]));

        try {
            $b->itens()->create(['produto_id' => $p->id, 'produto_ativo' => $p->id, 'posicao' => 3]);
            $this->fail('o mesmo produto entrou vivo em duas filas');
        } catch (QueryException) {
            // esperado: `pubfilai_produto_ativo_uq`
        }

        $a->delete();
        $this->assertSame(0, PubFilaPublicacaoItem::where('fila_id', $a->id)->count(), 'a fila leva os itens (CASCADE)');
        $this->assertFalse(NaFilaDePublicacao::emUso($p->id));
    }

    public function test_apagar_o_produto_mantem_o_item_com_o_historico(): void
    {
        $c = Company::factory()->create();
        $p = $this->produto($c, 'MES-01');
        $item = $this->fila('company-'.$c->id)->itens()->create(['produto_id' => $p->id, 'posicao' => 1, 'status' => PubFilaPublicacaoItem::PUBLICADO,
            'resumo' => ['nome' => 'Mesa', 'mlbs' => [['ml_item_id' => 'MLB1']]]]);

        $p->delete();

        $item->refresh();
        $this->assertNull($item->produto_id, 'SET NULL');
        $this->assertSame('MLB1', $item->resumo['mlbs'][0]['ml_item_id']);
    }

    public function test_janela_curta_e_config_com_o_gatilho_de_imagens_desligado(): void
    {
        $f = PubFilaPublicacao::create(['conta_chave' => 'company-1', 'janela_inicio' => '08:00:00', 'janela_fim' => '20:30:00']);
        $this->assertSame(['inicio' => '08:00', 'fim' => '20:30'], $f->fresh()->janela());
        $this->assertNull(PubFilaPublicacao::create(['conta_chave' => 'company-2'])->janela());

        $this->assertSame(10, config('publicador.fila_publicacao.intervalo_minutos'));
        $this->assertSame(2, config('publicador.fila_publicacao.teto_inicios_por_minuto'));
        $this->assertFalse(config('publicador.criativos_auto.ativo'), 'as imagens por IA automáticas nascem DESLIGADAS');
    }
}
