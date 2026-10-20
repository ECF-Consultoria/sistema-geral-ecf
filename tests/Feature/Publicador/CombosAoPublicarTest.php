<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Models\EstruturaProduto;
use App\Models\PubPublicacao;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * 20/10/2026 — a Fase 1 de um produto do Portal foi ao ar: a publicação AGENDA a criação dos combos do
 * Planejamento (`CriarCombosDoPlanejamentoJob`, fila `high`). O que nasce é do `CombosDoPlanejamentoTest`;
 * aqui se trava o DISPARO, pelo caminho real da publicação (ML simulado, nenhum anúncio de verdade).
 */
class CombosAoPublicarTest extends TestCase
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
            '*/items/*/description' => fn () => Http::response(['text' => '', 'plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)),
                'status' => 'active', 'sub_status' => [], 'tags' => [], 'permalink' => 'https://produto.mercadolivre.com.br/x']),
            '*/items' => fn (Request $q) => Http::response([
                'id' => sprintf('MLB90000000%02d', ++$this->posts), 'user_product_id' => "MLBU{$this->posts}", 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null,
            ], 201),
        ]);
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

    public function test_fase_1_do_portal_publicada_agenda_os_combos_do_planejamento_uma_vez(): void
    {
        $portal = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'CAD-01', 'nome' => 'Cadeira']);
        $this->produto->update(['estrutura_produto_id' => $portal->id]);

        $this->assertSame(PubPublicacao::PUBLISHED, $this->publicar()->status);

        Queue::assertPushedOn('high', CriarCombosDoPlanejamentoJob::class, fn (CriarCombosDoPlanejamentoJob $j) => $j->produtoId === $this->produto->id);
        $this->assertCount(1, Queue::pushed(CriarCombosDoPlanejamentoJob::class));
    }

    public function test_produto_que_nao_e_do_portal_nao_agenda_nada(): void
    {
        $this->assertSame(PubPublicacao::PUBLISHED, $this->publicar()->status);

        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);
    }
}
