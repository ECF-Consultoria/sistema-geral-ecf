<?php

namespace Tests\Feature\Phase123;

use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prova da D-02 (123-02-PLAN.md Task 1) — o dropdown "Mês fechado" de
 * `PerformanceController::show()` passa a derivar `meses_disponiveis` da
 * EXISTÊNCIA de linha em `desempenho_score_snapshots`, nunca de um corte
 * fixo de data. Antes desta correção o corte `>= 2026-08-01` deixava o
 * dropdown vazio em produção (2026-06 < 2026-08-01).
 *
 * Usa um profissional SEM carteira (compute() retorna sem_carteira cedo,
 * sem HTTP) — mesmo truque de `tests/Feature/PerformanceShowPeriodoTest.php`
 * — porque estas asserções são só sobre `meses_disponiveis`, independentes
 * de carteira/detalhe por empresa.
 */
class PerformanceShowMesesDisponiveisTest extends Phase123TestCase
{
    /**
     * O badge global de alertas críticos da sidebar (`HandleInertiaRequests
     * ::countAlertasCriticos()`) faz UMA chamada HTTP ao ECF Drive em
     * QUALQUER página autenticada — infraestrutura preexistente, alheia a
     * esta fase. Pré-aquecer o cache (mesma chave/TTL do middleware) evita
     * esse ruído sem tocar em código de produção, deixando
     * `Http::assertNothingSent()` medir só o que interessa: se `show()`
     * disparou alguma chamada por causa do detalhe por empresa.
     */
    private function semRuidoDeAlertas(): void
    {
        Cache::put('alertas.criticos_nao_ackeados.count', 0, 300);
    }

    #[Test]
    public function test_meses_disponiveis_reflete_snapshots_reais_sem_corte_fixo(): void
    {
        Http::fake();
        $this->semRuidoDeAlertas();

        $this->adminLogado();
        $alvo = $this->criarUserElegivel('Alvo Sem Corte');

        $this->seedMensal($alvo->id, '2026-06-01');
        $this->seedMensal($alvo->id, '2026-05-01');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('meses_disponiveis', ['2026-06', '2026-05'])
            );

        Http::assertNothingSent();
    }

    #[Test]
    public function test_snapshot_diario_nao_aparece_na_lista(): void
    {
        Http::fake();

        $this->adminLogado();
        $alvo = $this->criarUserElegivel('Alvo Diario');

        $this->seedMensal($alvo->id, '2026-06-01');
        // Snapshot DIÁRIO — mes_referencia null, não deve entrar na lista.
        DesempenhoScoreSnapshot::create([
            'user_id'              => $alvo->id,
            'ref_date'             => '2026-08-14',
            'mes_referencia'       => null,
            'score'                => 80,
            'classificacao'        => 'intermediario',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['nota_final' => 4.0],
        ]);

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('meses_disponiveis', ['2026-06'])
            );
    }

    #[Test]
    public function test_snapshot_mensal_de_outro_usuario_nao_aparece(): void
    {
        Http::fake();

        $this->adminLogado();
        $alvo  = $this->criarUserElegivel('Alvo Isolado');
        $outro = $this->criarUserElegivel('Outro Profissional');

        $this->seedMensal($alvo->id, '2026-06-01');
        $this->seedMensal($outro->id, '2026-07-01');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('meses_disponiveis', ['2026-06'])
            );
    }

    #[Test]
    public function test_sem_snapshot_mensal_lista_vazia_e_200(): void
    {
        Http::fake();

        $this->adminLogado();
        $alvo = $this->criarUserElegivel('Alvo Vazio');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('meses_disponiveis', [])
            );
    }
}
