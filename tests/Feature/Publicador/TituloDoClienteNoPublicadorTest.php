<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaProduto;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\Fila\EfetivosEmLote;
use App\Services\Publicador\PalavrasChaveService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * O título de venda do cadastro do Portal no Publicador (19/10/2026). O Publicador LÊ o título do
 * produto do Portal (sem cópia): ele é o planejado do Clássico; o Premium fica para a IA, diferente.
 * O que a equipe digita no editor vence, e a IA nunca escreve por cima do título do cliente — nem
 * quando o cliente o dá depois de ela ter escrito. Todo título sai com a 1ª letra de cada palavra maiúscula.
 */
class TituloDoClienteNoPublicadorTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['publicador.preparo_ia.ativo' => true]);
        $this->montarPreparo();
    }

    /** @return array<string, ?string> */
    private function titulos(PubRascunho $r): array
    {
        return $r->alvos()->orderBy('posicao')->pluck('titulo', 'listing_type_id')->all();
    }

    public function test_o_titulo_do_cliente_vira_o_classico_e_a_ia_escreve_so_o_premium(): void
    {
        $p = $this->produtoDoPortal();
        $p->update(['titulo' => 'cadeira gamer reclinável azul']);
        $this->salvarERodar($p);

        $pub = $this->pubDo($p);
        $this->assertSame('Cadeira Gamer Reclinável Azul', $pub->tituloDoCadastro());
        $efetivos = app(DadosEfetivosService::class)->daProduto($pub->fresh());
        $this->assertSame('Cadeira Gamer Reclinável Azul', $efetivos['titulos']['gold_special']);
        $this->assertNull($efetivos['titulos']['gold_pro']);

        $titulos = $this->titulos($this->rascunhoDo($p));
        $this->assertNull($titulos['gold_special'], 'a IA não escreve o Clássico: vale o do cliente, lido na hora');
        $this->assertNotEmpty($titulos['gold_pro']);
        $this->assertNotSame('Cadeira Gamer Reclinável Azul', $titulos['gold_pro']);

        // O editor mostra o do cliente no Clássico.
        $snap = app(RascunhoRepository::class)->snapshot($this->rascunhoDo($p))->comEfetivosDe($efetivos);
        $this->assertSame('Cadeira Gamer Reclinável Azul', collect($snap->alvos)->firstWhere('listingTypeId', 'gold_special')->titulo);
    }

    public function test_o_cliente_da_o_titulo_depois_e_o_da_ia_sai_do_classico(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $this->assertNotNull($this->titulos($r)['gold_special'], 'sem título do cliente, a IA escreve os dois');

        $p->update(['titulo' => 'cadeira gamer reclinável']);
        $this->salvarERodar($p);

        $titulos = $this->titulos($r->fresh());
        $this->assertNull($titulos['gold_special'], 'o Clássico que a IA tinha escrito sai para valer o do cliente');
        $this->assertNotNull($titulos['gold_pro']);
        $this->assertSame('Cadeira Gamer Reclinável', app(DadosEfetivosService::class)->daProduto($this->pubDo($p))['titulos']['gold_special']);
    }

    public function test_o_titulo_que_a_equipe_digitou_nao_sai(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        (new RascunhoRepository())->gravarTitulos($r, ['gold_special' => 'Cadeira da equipe']);

        $p->update(['titulo' => 'cadeira gamer reclinável']);
        $this->salvarERodar($p);

        $this->assertSame('Cadeira Da Equipe', $this->titulos($r->fresh())['gold_special']);
    }

    public function test_todo_titulo_gravado_e_enviado_sai_com_a_primeira_letra_maiuscula(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $repo = new RascunhoRepository();

        // O que o editor grava (os alvos) e o que a IA grava (gravarTitulos).
        $repo->gravarAlvos($r, [new Alvo('gold_special', 'cadeira gamer'), new Alvo('gold_pro', 'ébano poltrona')]);
        $this->assertSame(['gold_special' => 'Cadeira Gamer', 'gold_pro' => 'Ébano Poltrona'], $this->titulos($r->fresh()));
        $repo->gravarTitulos($r, ['gold_pro' => 'ônix cadeira']);
        $this->assertSame('Ônix Cadeira', $this->titulos($r->fresh())['gold_pro']);

        // Um título antigo, gravado minúsculo antes da regra, sai maiúsculo no snapshot (conferência e payload).
        DB::table('pub_rascunho_alvos')->where('rascunho_id', $r->id)->where('listing_type_id', 'gold_special')->update(['titulo' => 'cadeira antiga']);
        $snap = $repo->snapshot($r->fresh());
        $this->assertSame('Cadeira Antiga', collect($snap->alvos)->firstWhere('listingTypeId', 'gold_special')->titulo);

        // O título que a IA sugere também (o corte do módulo).
        $this->assertSame('Ébano Mesa Lateral', PalavrasChaveService::ajustarTitulo('ébano mesa lateral', 60));
    }

    public function test_a_visao_em_lote_le_o_titulo_do_cliente_igual_ao_servico_de_origem(): void
    {
        $p = $this->produtoDoPortal();
        $p->update(['titulo' => 'cadeira gamer reclinável']);
        $this->salvarERodar($p);
        $pub = PubProduto::with(['oferta', 'estruturaProduto'])->findOrFail($this->pubDo($p)->id);

        $lote = app(EfetivosEmLote::class)->carregar(collect([$pub]), collect([$pub->id => $pub]), [])['efetivos'][$pub->id];

        $this->assertSame(app(DadosEfetivosService::class)->daProduto($pub), $lote);
        $this->assertSame('Cadeira Gamer Reclinável', $lote['titulos']['gold_special']);
    }

    public function test_produto_de_outra_empresa_nao_empresta_o_titulo(): void
    {
        $p = $this->produtoDoPortal();
        $p->update(['titulo' => 'Cadeira gamer']);
        $this->salvarERodar($p);
        $pub = $this->pubDo($p);
        $outra = \App\Models\Company::factory()->create();
        EstruturaProduto::whereKey($p->id)->update(['company_id' => $outra->id]);

        $this->assertNull($pub->fresh()->tituloDoCadastro());
    }
}
