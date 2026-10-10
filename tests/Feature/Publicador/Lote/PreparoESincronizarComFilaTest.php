<?php

namespace Tests\Feature\Publicador\Lote;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PreencherRascunhoDoPortalJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\PubFilaPublicacaoItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Não mexer no produto enquanto agendado" (10/10/2026): com o produto `agendado`/`publicando` na fila de
 * publicação, o preparo pela IA (salvar no Portal) e o Sincronizar do Portal NÃO escrevem no rascunho — esperam,
 * como esperam o editor aberto. Saiu da fila, voltam a valer.
 */
class PreparoESincronizarComFilaTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->montarPreparo();
    }

    public function test_preparo_inteiro_espera_o_produto_sair_da_fila(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $pub = $this->pubDo($p);
        $descricao = $this->rascunhoDo($p)->descricao;
        $this->agendarNaFila($pub);

        $p->update(['descricao' => 'Mudou com o produto na fila.']);
        [$preparos, $ia] = $this->salvarERodar($p);

        $this->assertSame(['adiado'], $preparos);
        $this->assertSame([], $ia, 'nada da IA com o produto na fila');
        $this->assertSame($descricao, $this->rascunhoDo($p)->descricao, 'nada foi escrito');
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->adiamentos === 1 && $j->delay !== null);

        // Publicou (saiu da fila): o adiado segue.
        PubFilaPublicacaoItem::query()->update(['status' => PubFilaPublicacaoItem::PUBLICADO, 'produto_ativo' => null]);
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
    }

    public function test_escrita_da_ia_espera_com_o_valor_ja_gerado(): void
    {
        $p = $this->produtoDoPortal();
        app(\App\Services\Publicador\PreparoIaAgenda::class)->aoSalvar((int) $p->company_id, [$p->id]);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->agendarNaFila($this->pubDo($p)); // entrou na fila com a cadeia da IA já na fila de jobs

        $this->assertSame(['adiado', 'adiado', 'adiado'], $this->rodar(GerarPreparoIaJob::class));
        $this->assertSame([], array_filter($this->rascunhoDo($p)->alvos()->pluck('titulo')->all()), 'título não escrito');
        Queue::assertPushed(GerarPreparoIaJob::class, fn ($j) => $j->etapa === 'titulo' && $j->adiamentos === 1 && $j->valorPronto !== null);
    }

    public function test_sincronizar_do_portal_nao_preenche_o_produto_na_fila(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $pub = $this->pubDo($p);
        $outro = $this->produtoDoPortal('MES');
        $this->salvarERodar($outro);
        $this->agendarNaFila($pub);
        $admin = User::factory()->create(['role' => 'admin']);

        $r = $this->actingAs($admin)->postJson(route('mlb.anuncios.publicador.sincronizar', ['conta' => 'company-'.$this->empresa->id]))->assertOk()->json();

        $this->assertSame([$pub->id], $r['na_fila_de_publicacao']);
        Queue::assertNotPushed(PreencherRascunhoDoPortalJob::class, fn ($j) => $j->produtoId === $pub->id);
        Queue::assertPushed(PreencherRascunhoDoPortalJob::class, fn ($j) => $j->produtoId === $this->pubDo($outro)->id);
        $this->assertStringContainsString('1 produto na fila de publicação ficou como estava.', $r['mensagem']);
    }
}
