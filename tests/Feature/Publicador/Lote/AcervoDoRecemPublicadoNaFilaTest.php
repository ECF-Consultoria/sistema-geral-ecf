<?php

namespace Tests\Feature\Publicador\Lote;

use App\Jobs\Publicador\SincronizarAcervoDoPublicadoJob;
use App\Models\PubPublicacao;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quick 261010-nke / NKE-02 — a fila em RODADAS também dispara o sync do
 * acervo.
 *
 * Sem este teste, metade do problema ficaria viva: o caminho avulso (clique em
 * Publicar) e a fila em rodadas (`publicador:fila-publicacao`) são DOIS pontos
 * de entrada. Eles convergem em `PublicacaoService::executarFatia()` →
 * `concluir()`/`encerrar()`, e é exatamente por isso que o disparo mora lá e
 * NÃO no `AgendadorDaFila` (ali duplicaria).
 *
 * Mesmo relógio e mesmo ML simulado do `FilaEmRodadasTest`.
 */
class AcervoDoRecemPublicadoNaFilaTest extends TestCase
{
    use CenarioDaFila;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Notification::fake();
        $this->withoutVite();
        $this->montarFila(1);
    }

    public function test_fila_em_rodadas_despacha_o_sync_do_acervo_do_mlb_criado(): void
    {
        $this->conferirTodas();
        $this->agendar(null, ['ciente' => true, 'produtos_por_rodada' => 1])->assertSuccessful();
        $this->passada();

        $produto = $this->cadeiras[0];
        $pub = $this->terminarPublicacao($produto);

        $this->assertSame(PubPublicacao::PUBLISHED, $pub->status);
        $this->assertSame(['MLB9000000001'], $this->criados($pub));

        $syncs = Queue::pushed(SincronizarAcervoDoPublicadoJob::class)->all();

        $this->assertCount(1, $syncs, 'a fila em rodadas passa pelo MESMO executarFatia() — tem que disparar igual');
        $this->assertSame($produto->company_id, $syncs[0]->companyId);
        $this->assertSame(['MLB9000000001'], $syncs[0]->mlItemIds);
    }
}
