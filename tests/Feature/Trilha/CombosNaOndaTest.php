<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * O combo na trilha (2ª entrega, 02/11/2026): o Kit N do Combo N espera o dia da onda dele, e na empresa com a trilha
 * de trabalho ligada nasce da Simples PRONTA, sem esperar a publicação (decisão do usuário de 31/10/2026). Fora da
 * chave, a regra de sempre: só com a Fase 1 publicada, todos os Combos aceitos de uma vez.
 */
class CombosNaOndaTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        Http::fake();
        Queue::fake();
        $this->montarPlanejamento();
        config(['trilha.empresas' => [$this->empresaP->id], 'trilha.trabalho.empresas' => [$this->empresaP->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_publicar_a_simples_cria_so_o_kit_do_combo_cuja_onda_comecou(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $this->aceitarCombos(3);
        $this->naOnda(2, '2026-11-05');
        $this->naOnda(3, '2026-11-09');

        $r = $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame([2], array_column($r['criados'], 'quantidade'));
        $this->assertSame([['quantidade' => 3, 'motivo' => 'espera a onda da trilha']], $r['pulados']);
    }

    public function test_combo_sem_onda_tambem_espera(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);

        $r = $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame([], $r['criados']);
        $this->assertSame('espera a onda da trilha', $r['pulados'][0]['motivo']);
    }

    public function test_na_trilha_o_kit_nasce_da_simples_pronta_antes_da_publicacao(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $this->aceitarCombos(2);
        $this->naOnda(2, '2026-11-05');

        // O agente ainda não terminou o texto: nada nasce.
        $this->assertFalse($this->servico()->agendar($grupo->id));
        $this->assertSame([], $this->servico()->criarOsQueFaltam($grupo->fresh())['criados']);

        $this->textoPronto($grupo->rascunho);

        $this->assertTrue($this->servico()->agendar($grupo->id));
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class);
        $this->assertSame([2], array_column($this->servico()->criarOsQueFaltam($grupo->fresh())['criados'], 'quantidade'));
    }

    public function test_fora_da_chave_a_simples_pronta_nao_basta_e_publicada_cria_todos(): void
    {
        config(['trilha.trabalho.empresas' => []]);
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $this->aceitarCombos(2);
        $this->aceitarCombos(3);
        $this->naOnda(3, '2026-11-09');
        $this->textoPronto($grupo->rascunho);

        $this->assertFalse($this->servico()->agendar($grupo->id), 'fora da trilha o combo espera a publicação');
        $this->assertSame([], $this->servico()->criarOsQueFaltam($grupo->fresh())['criados']);

        PubRascunho::where('produto_id', $grupo->id)->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame([2, 3], array_column($this->servico()->criarOsQueFaltam($grupo->fresh())['criados'], 'quantidade'),
            'a onda não vale fora da chave');
    }

    // ─── apoio ───

    private function servico(): CombosDoPlanejamentoService
    {
        return app(CombosDoPlanejamentoService::class);
    }

    private function naOnda(int $n, string $data): void
    {
        $onda = TrilhaOnda::create(['company_id' => $this->empresaP->id, 'nome' => "Onda {$data}", 'data' => $data]);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresaP->id, 'produto_id' => $this->produtoP->id, 'quantidade' => $n]);
    }

    /** O preparo do texto completo para os fatos de agora, como a cadeia da IA deixa. */
    private function textoPronto(PubRascunho $r): void
    {
        $preparo = app(PreparoIaDoRascunhoService::class);
        $r = $r->fresh();
        $em = now()->subMinutes(5)->toIso8601String();
        $estado = (array) $r->step_state;
        $estado['ia_preparo'] = ['hash' => $preparo->hashDosFatos($r), 'em' => $em, 'etapas' => [
            'titulo' => ['status' => 'ok', 'em' => $em], 'modelo' => ['status' => 'ok', 'em' => $em], 'descricao' => ['status' => 'ok', 'em' => $em],
        ]];
        $r->forceFill(['step_state' => $estado])->save();
    }
}
