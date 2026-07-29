<?php

namespace Tests\Feature\Phase119;

use App\Services\Desempenho\CompanyScoreService;
use App\Services\DesempenhoScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fase 119 Plano 02 (Task 1) — teste de equivalência das réguas duplicadas
 * (C-03/119-CONTEXT.md). `CompanyScoreService::reguaFaturamento()` e
 * `reguaMargem()` são cópias BYTE A BYTE das homônimas privadas de
 * `DesempenhoScoreService` — sem este teste, a duplicação vira dívida
 * silenciosa (ninguém percebe se uma cópia divergir da outra).
 *
 * Molde: `tests/Feature/Phase118/NpsJanelaResolverTest::test_resolver_concorda_com_computeNpsWindow_nos_tres_casos`.
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-02-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 */
class CompanyScoreServiceReguasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Boundaries obrigatórios do gate 2 do `119-VALIDATION.md`, cobrindo
     * `null` e todos os pontos de corte + 1 vizinho de cada lado.
     *
     * @return array<int, ?float>
     */
    private function boundaries(): array
    {
        return [null, -6.01, -6.0, -5.01, -5.0, -2.01, -2.0, -1.01, -1.0, -0.99, 0.0, 0.99, 1.0, 1.01, 3.39, 4.0, 4.01, 5.0, 5.01, 14.09];
    }

    private function invocarRegua(object $service, string $metodo, ?float $pct): ?float
    {
        $ref = new ReflectionMethod($service, $metodo);
        $ref->setAccessible(true);

        return $ref->invoke($service, $pct);
    }

    #[Test]
    public function test_regua_faturamento_concorda_com_desempenho_score_service_em_todos_os_boundaries(): void
    {
        $original = app(DesempenhoScoreService::class);
        $novo     = app(CompanyScoreService::class);

        foreach ($this->boundaries() as $pct) {
            $this->assertSame(
                $this->invocarRegua($original, 'reguaFaturamento', $pct),
                $this->invocarRegua($novo, 'reguaFaturamento', $pct),
                "reguaFaturamento diverge do original no boundary pct=" . var_export($pct, true)
            );
        }
    }

    #[Test]
    public function test_regua_margem_concorda_com_desempenho_score_service_em_todos_os_boundaries(): void
    {
        $original = app(DesempenhoScoreService::class);
        $novo     = app(CompanyScoreService::class);

        foreach ($this->boundaries() as $pct) {
            $this->assertSame(
                $this->invocarRegua($original, 'reguaMargem', $pct),
                $this->invocarRegua($novo, 'reguaMargem', $pct),
                "reguaMargem diverge do original no boundary pct=" . var_export($pct, true)
            );
        }
    }

    /**
     * Não-tautológico (119-PLAN §Task 1): além da comparação cruzada acima,
     * assere os valores LITERAIS esperados — senão duas implementações
     * igualmente quebradas (ex.: as duas sempre devolvendo null) passariam
     * no teste de concordância sem nenhuma das duas estar certa.
     */
    #[Test]
    public function test_regua_faturamento_devolve_os_valores_literais_esperados(): void
    {
        $novo = app(CompanyScoreService::class);

        $this->assertNull($this->invocarRegua($novo, 'reguaFaturamento', null));
        $this->assertSame(1.0, $this->invocarRegua($novo, 'reguaFaturamento', -6.01));
        $this->assertSame(1.0, $this->invocarRegua($novo, 'reguaFaturamento', -6.0));
        $this->assertSame(2.0, $this->invocarRegua($novo, 'reguaFaturamento', -5.0));
        $this->assertSame(2.0, $this->invocarRegua($novo, 'reguaFaturamento', -1.0));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaFaturamento', -0.99));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaFaturamento', 0.0));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaFaturamento', 0.99));
        $this->assertSame(4.0, $this->invocarRegua($novo, 'reguaFaturamento', 1.0));
        $this->assertSame(4.0, $this->invocarRegua($novo, 'reguaFaturamento', 4.0));
        $this->assertSame(4.0, $this->invocarRegua($novo, 'reguaFaturamento', 5.0));
        $this->assertSame(5.0, $this->invocarRegua($novo, 'reguaFaturamento', 5.01));
    }

    #[Test]
    public function test_regua_margem_devolve_os_valores_literais_esperados(): void
    {
        $novo = app(CompanyScoreService::class);

        // NOTA: o corte real é `<= -2` (byte a byte do original, provado pelo
        // teste de equivalência acima) — `-2.01`/`-2.0` caem em 2.0, e
        // `-1.99`/`-1.01` já caem no próximo corte (`<= 1` → 3.0). O texto do
        // plano 119-02 citava "-2.0 e -1.99 → 2.0", o que contradiz o corte
        // `<= -2`; os valores abaixo são os literais corretos, batendo 1:1
        // com o original via Reflection.
        $this->assertNull($this->invocarRegua($novo, 'reguaMargem', null));
        $this->assertSame(1.0, $this->invocarRegua($novo, 'reguaMargem', -5.01));
        $this->assertSame(1.0, $this->invocarRegua($novo, 'reguaMargem', -5.0));
        $this->assertSame(2.0, $this->invocarRegua($novo, 'reguaMargem', -2.01));
        $this->assertSame(2.0, $this->invocarRegua($novo, 'reguaMargem', -2.0));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaMargem', -1.99));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaMargem', -1.01));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaMargem', 0.0));
        $this->assertSame(3.0, $this->invocarRegua($novo, 'reguaMargem', 1.0));
        $this->assertSame(4.0, $this->invocarRegua($novo, 'reguaMargem', 1.01));
        $this->assertSame(4.0, $this->invocarRegua($novo, 'reguaMargem', 3.39));
        $this->assertSame(4.0, $this->invocarRegua($novo, 'reguaMargem', 4.0));
        $this->assertSame(5.0, $this->invocarRegua($novo, 'reguaMargem', 4.01));
        $this->assertSame(5.0, $this->invocarRegua($novo, 'reguaMargem', 14.09));
    }
}
