<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * "O fluxo tem de chegar ao Publicador completo e sozinho" (09/10/2026): aceitar uma sugestão do
 * Planejamento, montar um kit à mão e criar uma oferta composta na Lista SKUs agendam o preparo
 * (`PreparoIaAgenda::aoSalvar`) pelos PRODUTOS dos componentes — e a sincronização por produto
 * leva a composta junto. Ninguém clica em "Sincronizar do Portal".
 *
 * A fila é `Queue::fake()` (o `aoSalvar` não agenda na `sync`): os Jobs são rodados à mão, como o
 * worker faria depois da espera.
 */
class KitDoPlanejamentoChegaSozinhoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    private array $cat;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->empresa = $this->empresaDoGabarito();
        $this->cat = $this->catalogoSintetico($this->empresa, $this->atorCliente($this->empresa));
        // Só o que acontece DEPOIS do catálogo pronto conta (o cadastro dos produtos também agenda).
        Queue::fake();
    }

    /** @return list<int> os produtos agendados, em ordem */
    private function produtosAgendados(): array
    {
        $ids = Queue::pushed(PrepararProdutoNoPublicadorJob::class)
            ->map(fn (PrepararProdutoNoPublicadorJob $j) => $j->estruturaProdutoId)->values()->all();
        sort($ids);

        return $ids;
    }

    /** Roda os Jobs agendados (a espera passou), na ordem. @return list<string> */
    private function rodarOsJobs(): array
    {
        return Queue::pushed(PrepararProdutoNoPublicadorJob::class)
            ->map(fn (PrepararProdutoNoPublicadorJob $j) => app(PreparoIaDoRascunhoService::class)
                ->preparar($j->companyId, $j->estruturaProdutoId, $j->marca, $j->adiamentos))
            ->values()->all();
    }

    private function produtos(string ...$nomes): array
    {
        $ids = array_map(fn ($n) => (int) $this->cat['produtos'][$n], $nomes);
        sort($ids);

        return $ids;
    }

    public function test_a_sugestao_aceita_chega_ao_publicador_sem_sincronizar(): void
    {
        $chave = ChaveDeComposicao::de([$this->cat['variacoes']['V101'] => 1, $this->cat['variacoes']['V201'] => 4]);

        $r = $this->entrarNoPortal($this->empresa)
            ->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => [['chave' => $chave]]])
            ->assertOk()->assertJsonCount(1, 'criadas');
        $ofertaId = (int) $r->json('criadas.0.oferta_id');

        // Uma agenda por produto dos componentes (mesa e cadeira), com a espera de sempre, na fila do preparo.
        $this->assertSame($this->produtos('Mesa Polo', 'Cadeira Polo'), $this->produtosAgendados());
        Queue::assertPushedOn('publicador-ia', PrepararProdutoNoPublicadorJob::class);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->delay !== null && $j->companyId === $this->empresa->id);
        $this->assertSame(0, PubProduto::where('oferta_id', $ofertaId)->count(), 'nada no Publicador antes da espera');

        // A espera passou: o preparo sincroniza o produto e o kit vai junto.
        $this->assertContains('pronto', $this->rodarOsJobs());
        $pub = PubProduto::where('oferta_id', $ofertaId)->first();
        $this->assertNotNull($pub, 'o combit aceito virou produto do Publicador sem ninguém clicar em Sincronizar');
        $this->assertSame($this->empresa->id, (int) $pub->company_id);
        $this->assertSame(1, PubProduto::where('oferta_id', $ofertaId)->count(), 'os dois Jobs não duplicam');
    }

    public function test_varias_aceitas_agendam_uma_vez_por_produto(): void
    {
        $v = $this->cat['variacoes'];
        $chaves = [
            ChaveDeComposicao::de([$v['V101'] => 1, $v['V201'] => 4]),
            ChaveDeComposicao::de([$v['V101'] => 1, $v['V201'] => 1]),
            ChaveDeComposicao::de([$v['V102'] => 1, $v['V202'] => 1]),
        ];

        $this->entrarNoPortal($this->empresa)
            ->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => array_map(fn ($c) => ['chave' => $c], $chaves)])
            ->assertOk()->assertJsonCount(3, 'criadas');

        $this->assertSame($this->produtos('Mesa Polo', 'Cadeira Polo'), $this->produtosAgendados());
    }

    public function test_o_kit_montado_a_mao_chega_ao_publicador(): void
    {
        $r = $this->entrarNoPortal($this->empresa)->postJson(route('portal.auth.estrutura.sugestoes.montar'), ['componentes' => [
            ['variacao_id' => $this->cat['variacoes']['V101'], 'quantidade' => 1],
            ['variacao_id' => $this->cat['variacoes']['V301'], 'quantidade' => 2],
            ['variacao_id' => $this->cat['variacoes']['V201'], 'quantidade' => 4],
        ]])->assertOk();

        $this->assertSame($this->produtos('Mesa Polo', 'Cadeira Polo', 'Banco Polo'), $this->produtosAgendados());
        $this->rodarOsJobs();
        $this->assertSame(1, PubProduto::where('oferta_id', $r->json('oferta.id'))->count());
    }

    public function test_a_oferta_composta_da_lista_skus_e_os_combos_em_lote_tambem(): void
    {
        $sessao = $this->entrarNoPortal($this->empresa);

        $sessao->post(route('portal.auth.estrutura.ofertas.criar'), [
            'sku' => 'KIT-LISTA', 'fase' => 'kit', 'nome' => 'Kit da Lista',
            'componentes' => [['id' => $this->cat['ofertas']['V102'], 'quantidade' => 1], ['id' => $this->cat['ofertas']['V401'], 'quantidade' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->produtos('Mesa Polo', 'Banqueta Polo'), $this->produtosAgendados());

        // Combos em lote de um produto: UMA agenda, pelo produto da base.
        Queue::fake();
        $sessao->post(route('portal.auth.estrutura.ofertas.combos', $this->cat['ofertas']['V202']), ['quantidades' => [2, 3]])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->produtos('Cadeira Polo'), $this->produtosAgendados());
    }

    public function test_simples_composta_sem_produto_e_falha_nao_agendam(): void
    {
        $ator = $this->atorCliente($this->empresa);
        $svc = app(EstruturaOfertaService::class);

        [$a] = $svc->criar($this->empresa, ['sku' => 'AV-1', 'fase' => 'simples'], $ator);
        [$b] = $svc->criar($this->empresa, ['sku' => 'AV-2', 'fase' => 'simples'], $ator);
        // Componentes sem produto (ofertas importadas): seguem pelo Sincronizar, como antes.
        $svc->criar($this->empresa, ['sku' => 'KIT-AV', 'fase' => 'kit', 'componentes' => [['id' => $a->id, 'quantidade' => 1], ['id' => $b->id, 'quantidade' => 1]]], $ator);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);

        // Montagem recusada (já existe) não agenda nada.
        $this->entrarNoPortal($this->empresa)->postJson(route('portal.auth.estrutura.sugestoes.montar'), ['componentes' => [
            ['oferta_id' => $a->id, 'quantidade' => 1], ['oferta_id' => $b->id, 'quantidade' => 1],
        ]])->assertStatus(422);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
        $this->assertSame(1, EstruturaOferta::where('company_id', $this->empresa->id)->where('sku', 'KIT-AV')->count());
    }

    public function test_desligado_nao_agenda(): void
    {
        config(['publicador.preparo_ia.ativo' => false]);
        $chave = ChaveDeComposicao::de([$this->cat['variacoes']['V101'] => 1, $this->cat['variacoes']['V201'] => 1]);

        $this->entrarNoPortal($this->empresa)
            ->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => [['chave' => $chave]]])->assertOk();

        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
    }
}
