<?php

namespace Tests\Feature\Publicador;

use App\Models\PubPromocaoAutomatica;
use App\Models\PubPublicacao;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Demo 23/10/2026 (A1) — a lista final depois de publicar diz o que aconteceu com a promoção automática de
 * cada anúncio. Em 22/10, em produção, TODAS as dos anúncios novos voltaram `recusada` com "No candidates
 * found for item": a lista mostra isso em pt-BR em vez de deixar a Prévia prometer o desconto.
 *
 * O ML é simulado: nenhum anúncio de verdade nasce aqui.
 */
class PromocaoDoPublicadoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario();
        $this->fakeMl([
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [], 'tags' => [],
                'permalink' => 'https://produto.mercadolivre.com.br/x', 'shipping' => ['mode' => 'me2', 'free_shipping' => true, 'logistic_type' => 'drop_off']]),
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB80000000%02d', ++$this->posts), 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null], 201),
        ]);
        $this->efetivos = ['titulos' => ['gold_special' => null], 'precos' => ['gold_special' => 207.19], 'mlbs' => []];
    }

    private function publicar(): PubPublicacao
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), $ator, cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));

        return $p->fresh();
    }

    private function itemNaTela(): array
    {
        return app(EditorRascunhoService::class)->estado($this->r->fresh())['publicacao']['itens'][0];
    }

    /** Troca o(s) ciclo(s) do anúncio por um com este status/motivo (o que o Job deixaria gravado). */
    private function ciclo(string $mlb, string $status, ?string $motivo, int $ciclo = 1): void
    {
        PubPromocaoAutomatica::query()->create([
            'rascunho_id' => $this->r->id, 'produto_id' => $this->r->produto_id, 'conta_chave' => 'empresa-1',
            'ml_item_id' => $mlb, 'listing_type' => 'gold_special', 'preco_publicado' => 207.19, 'preco_promocao' => 172.66,
            'percentual' => 16.67, 'ciclo' => $ciclo, 'inicio' => '2026-10-22', 'fim' => '2026-11-04', 'status' => $status, 'motivo' => $motivo,
        ]);
    }

    public function test_recusa_no_candidates_aparece_em_portugues_no_item(): void
    {
        $this->publicar();
        $mlb = $this->itemNaTela()['ml_item_id'];
        PubPromocaoAutomatica::query()->where('ml_item_id', $mlb)->delete();
        $this->ciclo($mlb, PubPromocaoAutomatica::RECUSADA, 'No candidates found for item');

        $this->assertSame([
            'status' => 'recusada', 'tom' => 'aviso',
            'texto' => 'Promoção da Central não criada. O Mercado Livre não ofereceu desconto para este anúncio novo',
        ], $this->itemNaTela()['promocao']);
    }

    public function test_agendada_criada_e_o_ultimo_ciclo_vale(): void
    {
        $this->publicar();
        $mlb = $this->itemNaTela()['ml_item_id'];
        PubPromocaoAutomatica::query()->where('ml_item_id', $mlb)->delete();

        $this->ciclo($mlb, PubPromocaoAutomatica::AGENDADA, 'Aguardando o anúncio ficar ativo no Mercado Livre.');
        $this->assertSame('neutro', $this->itemNaTela()['promocao']['tom']);
        $this->assertStringContainsString('o sistema vai tentar criar', $this->itemNaTela()['promocao']['texto']);

        PubPromocaoAutomatica::query()->where('ml_item_id', $mlb)->update(['status' => PubPromocaoAutomatica::ENCERRADA]);
        $this->ciclo($mlb, PubPromocaoAutomatica::ATIVA, null, ciclo: 2);
        $p = $this->itemNaTela()['promocao'];
        $this->assertSame('ativa', $p['status']);
        $this->assertSame('ok', $p['tom']);
        $this->assertStringStartsWith('Promoção da Central criada: R$ 172,66 (−16,67%) até 04/11', $p['texto']);
    }

    public function test_recusa_com_outro_motivo_mostra_o_motivo_gravado(): void
    {
        $this->publicar();
        $mlb = $this->itemNaTela()['ml_item_id'];
        PubPromocaoAutomatica::query()->where('ml_item_id', $mlb)->delete();
        $this->ciclo($mlb, PubPromocaoAutomatica::RECUSADA, 'Conta não liberada para a promoção automática: as Alavancas não escrevem nesta conta do Mercado Livre.');

        $this->assertSame('Promoção da Central não criada. Conta não liberada para a promoção automática: as Alavancas não escrevem nesta conta do Mercado Livre',
            $this->itemNaTela()['promocao']['texto']);
    }

    public function test_sem_ciclo_nao_diz_nada(): void
    {
        $this->publicar();
        $mlb = $this->itemNaTela()['ml_item_id'];
        PubPromocaoAutomatica::query()->where('ml_item_id', $mlb)->delete();

        $this->assertNull($this->itemNaTela()['promocao']);
    }
}
