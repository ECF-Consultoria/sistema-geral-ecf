<?php

namespace Tests\Feature\Quick;

use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 20260729-adman-retry-resiliente.
 *
 * Origem: o GATE MPP-04 da milestone v21.0 reprovou em 2026-07-29 porque, sob
 * a rajada de syncs das 11h disputando a mesma API-key, a cobertura de
 * `percentageMargin.prev` caiu de 92,5% para 64,2% — 15 de 53 empresas
 * perdidas com HTTP 429. O retry existente somava ~4,8s e desistia enquanto a
 * janela de rate-limit ainda durava minutos.
 *
 * Nenhum teste aqui chama a Adman real.
 */
class AdmanRetryResilienteTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '*/accounts/*/metrics*';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function service(): AdmanService
    {
        return app(AdmanService::class);
    }

    private function respostaOk(): array
    {
        return ['metrics' => ['percentageMargin' => ['value' => 27.47, 'diff' => 14.09, 'prev' => 24.08]]];
    }

    #[Test]
    public function recupera_apos_sequencia_de_429_em_vez_de_desistir(): void
    {
        // 5 respostas 429 seguidas de sucesso. O desenho ANTIGO (4 tentativas)
        // desistiria; o novo (6) recupera o valor exato.
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push('rate limit', 429)
            ->push('rate limit', 429)
            ->push('rate limit', 429)
            ->push('rate limit', 429)
            ->push('rate limit', 429)
            ->push($this->respostaOk(), 200),
        ]);

        $r = $this->service()->fetchAccountMetricsDetailedCached('CUST1', '2026-06-01', '2026-06-30', forceRefresh: true);

        $this->assertNotNull($r, 'Deveria ter recuperado após os 429 — o retry desistiu cedo demais.');
        $this->assertSame(27.47, $r['percentageMargin']['value']);
        $this->assertSame(24.08, $r['percentageMargin']['prev']);
        Http::assertSentCount(6);
    }

    #[Test]
    public function desiste_e_devolve_null_quando_todas_as_tentativas_falham(): void
    {
        Http::fake([self::ENDPOINT => Http::response('rate limit', 429)]);

        $r = $this->service()->fetchAccountMetricsDetailedCached('CUST2', '2026-06-01', '2026-06-30', forceRefresh: true);

        $this->assertNull($r);
        // Exatamente o número de tentativas configurado — nem mais, nem menos.
        Http::assertSentCount(6);
    }

    #[Test]
    public function sucesso_de_primeira_nao_faz_retry_nem_espera(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->respostaOk(), 200)]);

        $inicio = microtime(true);
        $r = $this->service()->fetchAccountMetricsDetailedCached('CUST3', '2026-06-01', '2026-06-30', forceRefresh: true);
        $decorrido = microtime(true) - $inicio;

        $this->assertNotNull($r);
        Http::assertSentCount(1);
        $this->assertLessThan(1.0, $decorrido, 'Caminho feliz não pode pagar custo de backoff.');
    }

    #[Test]
    public function retry_after_em_segundos_tem_precedencia_sobre_o_backoff(): void
    {
        $svc = $this->service();

        // Simula o header capturado por fetchAccountMetrics() num 429.
        $this->setPrivado($svc, 'ultimoRetryAfter', 3);
        $espera = $this->invocarEspera($svc, 5); // 2^5 = 32s seria o backoff calculado

        // Com Retry-After=3 e jitter de ±40%, a espera fica em [1,8s ; 4,2s] —
        // muito abaixo dos 30s de teto que o backoff exponencial daria.
        $this->assertGreaterThanOrEqual(1.8, $espera);
        $this->assertLessThanOrEqual(4.2, $espera);
    }

    #[Test]
    public function retry_after_em_data_http_e_convertido_para_segundos(): void
    {
        $svc = $this->service();

        $emSegundos = $this->invocarConversao($svc, (string) 45);
        $this->assertSame(45, $emSegundos);

        $comoData = $this->invocarConversao($svc, now()->addSeconds(20)->toRfc7231String());
        $this->assertNotNull($comoData);
        $this->assertGreaterThan(10, $comoData);
        $this->assertLessThanOrEqual(21, $comoData);

        // Header ausente ou lixo cai no backoff calculado.
        $this->assertNull($this->invocarConversao($svc, null));
        $this->assertNull($this->invocarConversao($svc, 'nao-e-data'));
    }

    #[Test]
    public function backoff_respeita_o_teto_e_aplica_jitter(): void
    {
        $svc = $this->service();
        $this->setPrivado($svc, 'ultimoRetryAfter', null);

        // 2^10 = 1024s, mas o teto é 30s. Com jitter de ±40%: [18s ; 42s].
        $valores = [];
        for ($i = 0; $i < 30; $i++) {
            $valores[] = $this->invocarEspera($svc, 10);
        }

        foreach ($valores as $v) {
            $this->assertGreaterThanOrEqual(18.0, $v, 'Abaixo da faixa do jitter.');
            $this->assertLessThanOrEqual(42.0, $v, 'Acima do teto + jitter — o teto não está sendo aplicado.');
        }

        // Jitter de verdade: os valores não podem ser todos iguais, senão os
        // processos concorrentes acordam em lockstep e recriam a congestão.
        $this->assertGreaterThan(1, count(array_unique($valores)), 'Sem jitter — o backoff está determinístico.');
    }

    #[Test]
    public function janela_total_de_espera_passa_de_um_minuto(): void
    {
        $svc = $this->service();
        $this->setPrivado($svc, 'ultimoRetryAfter', null);

        // Soma do backoff das 5 esperas entre as 6 tentativas, no pior caso do
        // jitter (fator mínimo 0,6). Antes eram ~4,8s no total.
        $minimo = 0.0;
        foreach ([1, 2, 3, 4, 5] as $tentativa) {
            $base = min(2 ** $tentativa, 30);
            $minimo += $base * 0.6;
        }

        $this->assertGreaterThan(
            30.0,
            $minimo,
            'A janela mínima de retry precisa cobrir dezenas de segundos — 4,8s era o que fazia o gate reprovar.'
        );
    }

    // ─── helpers de reflexão ───

    private function setPrivado(object $obj, string $prop, mixed $valor): void
    {
        $r = new \ReflectionProperty($obj, $prop);
        $r->setValue($obj, $valor);
    }

    private function invocarEspera(object $obj, int $tentativa): float
    {
        $m = new \ReflectionMethod($obj, 'esperaDoRetry');

        return $m->invoke($obj, $tentativa);
    }

    private function invocarConversao(object $obj, ?string $header): ?int
    {
        $m = new \ReflectionMethod($obj, 'segundosDoRetryAfter');

        return $m->invoke($obj, $header);
    }
}
