<?php

namespace Tests\Feature\Phase123;

use App\Services\Desempenho\WarmDesempenhoDispatcher;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\AdmanMetricDiffService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate quente/frio das telas de DETALHE (2026-08-07).
 *
 * POR QUE EXISTE: `DesempenhoScoreService::compute()` faz HTTP SÍNCRONO à
 * Adman por empresa da carteira — 110s medidos em produção para um
 * profissional de 25 empresas numa competência fria. O ranking foi blindado na
 * Fase 106, mas `/performance/{id}` e `/admin/users/{id}/portfolio` chamavam
 * `computeCached()` direto e penduravam o navegador até o `max_execution_time`
 * de 300s do php-fpm. Era o "carregando infinito" ao trocar o mês.
 *
 * O contrato que estes testes travam: com cache FRIO, a tela responde na hora
 * e NÃO chama `compute()`. Quem calcula é o worker.
 */
class GateAquecimentoDesempenhoTest extends Phase123TestCase
{
    /**
     * Falha o teste se `compute()` for chamado — é literalmente o que o gate
     * existe para impedir. Um mock de `isCached=false` sem esta trava passaria
     * mesmo com a tela computando síncrono.
     */
    private function proibirCompute(): void
    {
        // `Queue::fake()` é OBRIGATÓRIO aqui: sob o driver `sync` dos testes,
        // o `Artisan::queue('desempenho:warm-cache')` do gate roda INLINE, e o
        // próprio comando de warm chama `computeCached()` — o teste acusava a
        // tela de computar quando quem computava era o warm. Em produção
        // (QUEUE_CONNECTION=database + workers) isso é assíncrono de verdade.
        Queue::fake();

        $this->mock(DesempenhoScoreService::class, function ($mock) {
            $mock->shouldReceive('isCached')->andReturn(false);
            $mock->shouldNotReceive('compute');
            $mock->shouldNotReceive('computeCached');
            $mock->shouldReceive('cacheKey')->andReturn('irrelevante');
        });
    }

    #[Test]
    public function performance_show_com_cache_frio_responde_calculando_sem_computar(): void
    {
        $alvo = $this->criarUserElegivel('Frio Com Carteira');
        $this->darCarteira($alvo);
        $this->adminLogado();

        $this->proibirCompute();

        $this->get(route('performance.show', $alvo) . '?mes=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('aquecendo', true)
                ->where('resultado.calculando', true)
            );
    }

    #[Test]
    public function carteira_do_admin_com_cache_frio_responde_calculando_sem_computar(): void
    {
        $alvo = $this->criarUserElegivel('Frio Carteira Admin');
        $this->darCarteira($alvo);
        $this->adminLogado();

        $this->proibirCompute();

        $this->get(route('portfolio.show', $alvo) . '?mes=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolio/AdminCarteira')
                ->where('aquecendo', true)
                ->where('score.calculando', true)
            );
    }

    #[Test]
    public function sem_carteira_nao_entra_no_gate(): void
    {
        // Carteira vazia não tem fan-out nenhum a evitar: `compute()` corta em
        // `computeUniverso` e devolve `sem_carteira` na hora. Mandar essa tela
        // para "calculando…" mentiria sobre o motivo de não haver nota e ainda
        // enfileiraria um warm sem trabalho. Foi este teste que pegou a
        // primeira versão do gate, que engolia o `sem_carteira`.
        $alvo = $this->criarUserElegivel('Sem Carteira Nenhuma');
        $this->adminLogado();

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('aquecendo', false)
                ->where('resultado.sem_carteira', true)
            );
    }

    #[Test]
    public function cache_quente_nao_aciona_o_gate(): void
    {
        $alvo = $this->criarUserElegivel('Quente');
        $this->darCarteira($alvo);
        $this->adminLogado();

        $this->mock(DesempenhoScoreService::class, function ($mock) {
            $mock->shouldReceive('isCached')->andReturn(true);
            $mock->shouldReceive('computeCached')->andReturn([
                'componentes'        => [],
                'pontos_componentes' => ['nps' => 4.0, 'faturamento' => 3.0, 'margem' => 5.0],
                'nota_final'         => 4.0,
                'faixa_bonus'        => 'basico',
                'sem_carteira'       => false,
            ]);
        });

        $this->get(route('performance.show', $alvo) . '?mes=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('aquecendo', false)
                ->where('resultado.nota_final', 4)
            );
    }

    #[Test]
    public function o_lock_impede_empilhar_warm_a_cada_poll(): void
    {
        // O front polla de 6 em 6 segundos enquanto aquece. Sem o lock, cada
        // poll enfileiraria um job novo do mesmo mês (Fase 106, T-106-03).
        // O mock devolve um duplê que registra a fila: o warm PRECISA ir para
        // a `high`. Na `default` ele fica atrás dos syncs em lote (em
        // 2026-08-12 eram 111 jobs de acervo ML, de 2 a 5 min cada) e a tela
        // fica em "Calculando…" por horas.
        $filaEscolhida = null;
        $pending = Mockery::mock();
        $pending->shouldReceive('onQueue')->once()->andReturnUsing(function ($fila) use (&$filaEscolhida) {
            $filaEscolhida = $fila;
        });

        Artisan::shouldReceive('queue')->once()->with('desempenho:warm-cache', Mockery::any())->andReturn($pending);

        $alvo = $this->criarUserElegivel('Lock');
        $mes  = \Carbon\Carbon::parse('2026-07-01');

        $this->mock(DesempenhoScoreService::class, function ($mock) {
            $mock->shouldReceive('isCached')->andReturn(false);
        });

        $dispatcher = app(WarmDesempenhoDispatcher::class);

        $this->assertTrue($dispatcher->agendarWarm([$alvo->id], $mes), '1o request deve disparar');
        $this->assertFalse($dispatcher->agendarWarm([$alvo->id], $mes), '2o request nao pode disparar');
        $this->assertFalse($dispatcher->agendarWarm([$alvo->id], $mes), '3o request nao pode disparar');

        $this->assertSame('high', $filaEscolhida, 'O warm interativo nao pode cair na fila dos syncs em lote.');
    }

    /**
     * A carteira tem DOIS fan-outs independentes — a nota e a TABELA (o laço
     * que chama `MetricDiffDispatcher::compute()` por empresa). Blindar só o
     * primeiro deixou a página levando 115s, e foi a medição PÓS-DEPLOY que
     * expôs isso: com a nota gateada, o `/performance/{id}` caiu para 0,1s
     * mas `/admin/users/{id}/portfolio` continuou pendurando.
     */
    #[Test]
    public function carteira_com_diff_frio_nao_monta_a_tabela(): void
    {
        Queue::fake();

        $alvo = $this->criarUserElegivel('Tabela Fria');
        // Acima da TOLERANCIA_EMPRESAS_FRIAS (3) — o gate é proporcional, então
        // uma carteira de 1 empresa fria NÃO gateia (ver o teste da tolerância).
        for ($i = 0; $i < 4; $i++) {
            $this->darCarteira($alvo);
        }
        $this->adminLogado();

        $this->mock(AdmanMetricDiffService::class, function ($mock) {
            $mock->shouldReceive('isCached')->andReturn(false);
            // O gate existe exatamente para este método não ser chamado.
            $mock->shouldNotReceive('compute');
        });

        $this->get(route('portfolio.show', $alvo) . '?mes=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolio/AdminCarteira')
                ->where('aquecendo_tabela', true)
                ->where('aquecendo', true)
                ->where('empresas', [])
            );
    }

    #[Test]
    public function carteira_com_diff_quente_monta_a_tabela_normalmente(): void
    {
        $alvo = $this->criarUserElegivel('Tabela Quente');
        $this->darCarteira($alvo);
        $this->adminLogado();

        // Mock PARCIAL: só `isCached` é stubado; `compute()` continua real.
        // Um mock completo devolveria null de `compute()` e a tela dava 500 —
        // o teste passaria a medir o mock, não o gate.
        $this->partialMock(AdmanMetricDiffService::class, function ($mock) {
            $mock->shouldReceive('isCached')->andReturn(true);
        });

        $this->get(route('portfolio.show', $alvo) . '?mes=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolio/AdminCarteira')
                ->where('aquecendo_tabela', false)
            );
    }

    /**
     * O gate da tabela é PROPORCIONAL, não "qualquer uma fria".
     *
     * Medido em produção: o warm converge em ondas (25 → 9 → 1 de 25). Com a
     * regra binária, 1 retardatária escondia a tabela inteira com 24/25 dos
     * dados prontos — e, como o ERROR_SENTINEL do diff expira em 10min, uma
     * empresa que erre cronicamente faria a tabela piscar para sempre.
     */
    #[Test]
    public function poucas_empresas_frias_nao_escondem_a_tabela_mas_disparam_o_warm(): void
    {
        Queue::fake();

        $alvo = $this->criarUserElegivel('Uma Retardatária');
        $this->darCarteira($alvo);
        $this->adminLogado();

        // 1 fria (abaixo da tolerância): renderiza normal.
        $this->partialMock(AdmanMetricDiffService::class, function ($mock) {
            $mock->shouldReceive('isCached')->once()->andReturn(false);
            $mock->shouldReceive('isCached')->andReturn(true);
        });

        $this->get(route('portfolio.show', $alvo) . '?mes=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolio/AdminCarteira')
                ->where('aquecendo_tabela', false)
            );

        // ...e mesmo sem gatear, o warm foi agendado para a retardatária.
        $this->assertNotNull(
            Cache::get('adman.diff.warm.lock.2026-07'),
            'aquecer e gatear sao decisoes separadas: abaixo do limite ainda aquece',
        );
    }

    /**
     * O /dashboard e a LANDING PAGE de todo usuario logado — era o pior caso
     * do sistema inteiro. O widget "Score da equipe" percorria os
     * profissionais chamando computeCached(): 124s medidos em producao com o
     * cache frio (2026-08-07). "Sistema nao carrega nada" era daqui, nao de um
     * modulo especifico.
     */
    #[Test]
    public function dashboard_com_cache_frio_nao_computa_e_marca_calculando(): void
    {
        Queue::fake();

        $alvo = $this->criarUserElegivel('Frio no Dashboard');
        $this->darCarteira($alvo);
        $admin = $this->adminLogado();

        $this->mock(DesempenhoScoreService::class, function ($mock) {
            $mock->shouldReceive('isCached')->andReturn(false);
            // O gate existe exatamente para isto nao acontecer na landing page.
            $mock->shouldNotReceive('compute');
            $mock->shouldNotReceive('computeCached');
        });

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('performance_aquecendo', true)
                ->where('performance_equipe.0.calculando', true)
                ->where('performance_equipe.0.nota_final', null)
            );
    }

    #[Test]
    public function lista_vazia_de_frios_nao_dispara_job(): void
    {
        Artisan::shouldReceive('queue')->never();

        $dispatcher = app(WarmDesempenhoDispatcher::class);

        $this->assertFalse($dispatcher->agendarWarm([], \Carbon\Carbon::parse('2026-07-01')));
        $this->assertNull(Cache::get('desempenho.warm.lock.2026-07'), 'nao pode nem criar o lock');
    }
}
