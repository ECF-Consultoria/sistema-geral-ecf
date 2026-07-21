<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite unitária do wrapper `DesempenhoScoreService::isCached()` — Fase 106
 * Plan 01 (Gate isCached, metade do SC2).
 *
 * `isCached(User, Carbon): bool` responde "está pronto?" via `Cache::has()`
 * na MESMA chave que `computeCached()` escreve (`cacheKey()`), SEM nunca
 * chamar `compute()`/`computeCached()` — é o gancho que o Plan 106-02 usa
 * pra decidir "computo ou degrado" no controller sem pagar o custo do
 * compute() caro (HTTP síncrono à Adman/ML).
 *
 * Cenários cobertos:
 *  1. Cache vazio → false.
 *  2. Cache populado com a MESMA chave de cacheKey() → true.
 *  3. Zero-compute: Http::preventStrayRequests() SEM fake não estoura ao
 *     chamar isCached() com um user "frio" — prova que não bate na Adman/ML.
 *     Cobre mês corrente (chave 'current_month') e mês fechado (chave
 *     'YYYY-MM').
 */
class DesempenhoScoreServiceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): DesempenhoScoreService
    {
        return app(DesempenhoScoreService::class);
    }

    private function criarUser(): User
    {
        return User::create([
            'name'     => 'User Cache ' . uniqid(),
            'email'    => 'cache.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Cache vazio → isCached retorna false
    // ═══════════════════════════════════════════════════════════════════

    public function test_isCached_retorna_false_quando_cache_vazio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21', 'America/Sao_Paulo'));

        $user = $this->criarUser();
        $mes  = Carbon::now()->startOfMonth();

        $this->assertFalse(
            $this->service()->isCached($user, $mes),
            'Sem nada em cache, isCached deve retornar false.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. Cache populado na MESMA chave de cacheKey() → isCached retorna true
    // ═══════════════════════════════════════════════════════════════════

    public function test_isCached_retorna_true_quando_chave_de_cacheKey_populada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21', 'America/Sao_Paulo'));

        $user    = $this->criarUser();
        $mes     = Carbon::now()->startOfMonth();
        $service = $this->service();

        // Popula a MESMA chave que computeCached() escreve — sem chamar
        // computeCached() (que dispararia HTTP externo).
        Cache::put($service->cacheKey($user->id, $mes), ['fake' => 'payload'], 60);

        $this->assertTrue(
            $service->isCached($user, $mes),
            'Com a chave de cacheKey() populada, isCached deve retornar true.'
        );
    }

    public function test_isCached_retorna_true_para_mes_fechado_com_chave_populada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21', 'America/Sao_Paulo'));

        $user    = $this->criarUser();
        $mesFechado = Carbon::parse('2026-06-01'); // mês fechado (não é o corrente)
        $service = $this->service();

        Cache::put($service->cacheKey($user->id, $mesFechado), ['fake' => 'payload'], 60);

        $this->assertTrue(
            $service->isCached($user, $mesFechado),
            'Com a chave de cacheKey() do mês fechado populada, isCached deve retornar true.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. Zero-compute — user frio não dispara HTTP externo (Adman/ML)
    // ═══════════════════════════════════════════════════════════════════

    public function test_isCached_nao_computa_para_user_frio_no_mes_corrente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21', 'America/Sao_Paulo'));

        // Sem Http::fake — se isCached() disparasse compute() (que faz
        // chamadas HTTP síncronas à Adman/ML), preventStrayRequests()
        // lançaria uma exception imediatamente.
        Http::preventStrayRequests();

        $user = $this->criarUser();
        $mesCorrente = Carbon::now()->startOfMonth();

        $resultado = $this->service()->isCached($user, $mesCorrente);

        $this->assertFalse($resultado, 'User frio (sem cache) deve retornar false, nunca computar.');
    }

    public function test_isCached_nao_computa_para_user_frio_no_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21', 'America/Sao_Paulo'));

        Http::preventStrayRequests();

        $user       = $this->criarUser();
        $mesFechado = Carbon::parse('2026-06-01'); // mês fechado, distinto do corrente

        $resultado = $this->service()->isCached($user, $mesFechado);

        $this->assertFalse($resultado, 'User frio (mês fechado, sem cache) deve retornar false, nunca computar.');
    }
}
