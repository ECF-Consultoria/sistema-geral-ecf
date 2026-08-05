<?php

namespace Tests\Feature\Phase123;

use App\Models\DesempenhoScoreSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * O seletor de mês de `PerformanceController::show()` precisa ser USÁVEL.
 *
 * História: a D-02 (123-02-PLAN.md Task 1) trocou um corte fixo de data
 * (`>= 2026-08-01`) pela existência de linha em `desempenho_score_snapshots`,
 * porque o corte deixava o dropdown VAZIO em produção (2026-06 < 2026-08-01).
 * A dor era a mesma de sempre: o usuário sem opção para escolher.
 *
 * 2026-08-05 — a lista passou a ser os ÚLTIMOS 6 MESES, não mais os meses
 * congelados. Enquanto existia o toggle "Em curso / Bônus atual / Mês
 * fechado", o dropdown só precisava cobrir os fechados; os outros dois
 * períodos tinham botão próprio. Com o toggle removido, esta lista virou o
 * ÚNICO controle de período da tela, e derivá-la dos congelados voltava a
 * produzir a mesma dor por outro caminho: em produção só 2026-06 está
 * consolidada, então sobrava UMA opção e não havia como ver o mês corrente.
 *
 * `resolveContextoPeriodo()` sempre aceitou `?mes=YYYY-MM` de qualquer mês —
 * a limitação era só da lista oferecida.
 *
 * Usa um profissional SEM carteira (compute() retorna sem_carteira cedo, sem
 * HTTP) — mesmo truque de `tests/Feature/PerformanceShowPeriodoTest.php` —
 * porque estas asserções são só sobre `meses_disponiveis`.
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

    /** Os 6 meses que a tela deve oferecer, do corrente para trás. */
    private function seisMesesEsperados(): array
    {
        $corrente = Carbon::now()->startOfMonth();

        return collect(range(0, 5))
            ->map(fn (int $i) => $corrente->copy()->subMonthsNoOverflow($i)->format('Y-m'))
            ->all();
    }

    #[Test]
    public function test_lista_os_ultimos_seis_meses_com_o_corrente_marcado(): void
    {
        Http::fake();
        $this->semRuidoDeAlertas();

        $this->adminLogado();
        $alvo = $this->criarUserElegivel('Alvo Seis Meses');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where(
                    'meses_disponiveis',
                    fn ($meses) => collect($meses)->pluck('value')->all() === $this->seisMesesEsperados()
                )
                // Exatamente UM mês marcado como em curso, e é o primeiro.
                ->where(
                    'meses_disponiveis',
                    fn ($meses) => collect($meses)->where('em_curso', true)->count() === 1
                        && collect($meses)->first()['em_curso'] === true
                )
                // Cada item traz label legível — o front não formata a data.
                ->where(
                    'meses_disponiveis',
                    fn ($meses) => collect($meses)->every(fn ($m) => filled($m['label'] ?? null))
                )
            );

        Http::assertNothingSent();
    }

    #[Test]
    public function test_sem_snapshot_mensal_a_lista_continua_utilizavel(): void
    {
        Http::fake();
        $this->semRuidoDeAlertas();

        $this->adminLogado();
        // Nenhum snapshot consolidado — o caso que antes devolvia lista VAZIA
        // e deixava a tela sem nenhum controle de período.
        $alvo = $this->criarUserElegivel('Alvo Sem Consolidacao');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('meses_disponiveis', fn ($meses) => count($meses) === 6)
            );
    }

    #[Test]
    public function test_lista_nao_depende_de_snapshot_de_outro_usuario_nem_de_diario(): void
    {
        Http::fake();
        $this->semRuidoDeAlertas();

        $this->adminLogado();
        $alvo  = $this->criarUserElegivel('Alvo Isolado');
        $outro = $this->criarUserElegivel('Outro Profissional');

        $this->seedMensal($alvo->id, '2026-06-01');
        $this->seedMensal($outro->id, '2026-07-01');

        // Snapshot DIÁRIO (mes_referencia null) — nunca foi mês selecionável.
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

        // A lista é derivada do CALENDÁRIO, então nada disso a altera — o que
        // é justamente a garantia: um profissional não vê meses a menos só
        // porque a consolidação dele ainda não rodou.
        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where(
                    'meses_disponiveis',
                    fn ($meses) => collect($meses)->pluck('value')->all() === $this->seisMesesEsperados()
                )
            );
    }
}
