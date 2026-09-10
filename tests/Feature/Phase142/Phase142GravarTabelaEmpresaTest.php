<?php

namespace Tests\Feature\Phase142;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\User;
use App\Services\Fechamento\GravarTabelaEmpresaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 142 Plano 01 — Tarefa 1: `GravarTabelaEmpresaService`, a porta única de escrita em
 * `empresa_faixas_faturamento` (D-05 da Fase 141: trava de precedência; achado do 142-01-PLAN.md:
 * trilha de auditoria explícita, porque o delete de query builder não dispara `LogsActivity`).
 *
 * Toda asserção de persistência é por RECONSULTA ao banco — nunca pelo retorno do método sozinho.
 */
class Phase142GravarTabelaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function service(): GravarTabelaEmpresaService
    {
        return app(GravarTabelaEmpresaService::class);
    }

    private function faixas(float $valor = 1_500.00): array
    {
        return [
            ['ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => $valor, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => $valor * 2, 'valor_e_piso' => true],
        ];
    }

    private function criarLinhaExistente(Company $company, string $origem, float $valor = 999.00): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => null,
            'valor'           => $valor,
            'valor_e_piso'    => false,
            'origem'          => $origem,
        ]);
    }

    private function ultimaActivity(Company $company): ?Activity
    {
        return Activity::where('log_name', 'faixa_faturamento_tabela')
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->latest('id')
            ->first();
    }

    // ── 1. manual numa empresa sem tabela ────────────────────────────────

    #[Test]
    public function gravar_manual_numa_empresa_sem_tabela_cria_as_linhas(): void
    {
        $company = Company::factory()->create();

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        $this->assertFalse($resultado['substituiu_confirmada']);
        $this->assertNull($resultado['origem_anterior']);
        $this->assertSame(2, $resultado['quantidade']);

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->ordenadas()->get();
        $this->assertCount(2, $linhas);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linhas[0]->origem);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linhas[1]->origem);
    }

    // ── 2. manual sobre presumida_servico ────────────────────────────────

    #[Test]
    public function gravar_manual_sobre_presumida_servico_substitui_sem_marcar_confirmada(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        $this->assertFalse($resultado['substituiu_confirmada']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $resultado['origem_anterior']);

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => $l->origem === EmpresaFaixaFaturamento::ORIGEM_MANUAL));
    }

    // ── 3. manual sobre contrato ──────────────────────────────────────────

    #[Test]
    public function gravar_manual_sobre_contrato_substitui_marcando_substituiu_confirmada(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_CONTRATO);

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        $this->assertTrue($resultado['substituiu_confirmada']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $resultado['origem_anterior']);

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => $l->origem === EmpresaFaixaFaturamento::ORIGEM_MANUAL));
    }

    // ── 4. contrato sobre presumida_servico ou sobre manual ──────────────

    #[Test]
    public function gravar_contrato_sobre_presumida_servico_substitui_normalmente(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_CONTRATO);

        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $resultado['origem_anterior']);
        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => $l->origem === EmpresaFaixaFaturamento::ORIGEM_CONTRATO));
    }

    #[Test]
    public function gravar_contrato_sobre_manual_substitui_normalmente(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_CONTRATO);

        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $resultado['origem_anterior']);
        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => $l->origem === EmpresaFaixaFaturamento::ORIGEM_CONTRATO));
    }

    // ── 5/6. presumida_servico sobre manual/contrato lança exceção ───────

    #[Test]
    public function gravar_presumida_servico_sobre_manual_lanca_excecao_e_nao_altera_nada(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 777.00);

        $antes = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();

        try {
            $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $depois = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(1, $depois);
        $this->assertSame(777.00, (float) $depois[0]->valor);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $depois[0]->origem);
        $this->assertEquals($antes->pluck('id'), $depois->pluck('id'));
    }

    #[Test]
    public function gravar_presumida_servico_sobre_contrato_lanca_excecao_e_nao_altera_nada(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, 888.00);

        try {
            $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $depois = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(1, $depois);
        $this->assertSame(888.00, (float) $depois[0]->valor);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $depois[0]->origem);
    }

    // ── 7. presumida_servico numa empresa sem tabela ou sobre outra presumida ─

    #[Test]
    public function gravar_presumida_servico_numa_empresa_sem_tabela_funciona(): void
    {
        $company = Company::factory()->create();

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, servicoOrigemId: 5);

        $this->assertFalse($resultado['substituiu_confirmada']);
        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => $l->origem === EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO));
        $this->assertTrue($linhas->every(fn ($l) => (int) $l->servico_origem_id === 5));
    }

    #[Test]
    public function gravar_presumida_servico_sobre_outra_presumida_servico_funciona(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);

        $resultado = $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);

        $this->assertFalse($resultado['substituiu_confirmada']);
        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
    }

    // ── 8. toda gravação bem-sucedida cria exatamente uma entrada de auditoria ─

    #[Test]
    public function gravacao_bem_sucedida_cria_exatamente_uma_entrada_de_auditoria_com_antes_e_depois(): void
    {
        $company = Company::factory()->create(['name' => 'Empresa Auditoria Teste']);
        $user    = User::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, 500.00);

        $antesDaGravacao = Activity::where('log_name', 'faixa_faturamento_tabela')->count();

        $this->service()->gravar($company, $this->faixas(1_200.00), EmpresaFaixaFaturamento::ORIGEM_MANUAL, por: $user, feitoDe: 'fechamento');

        $depoisDaGravacao = Activity::where('log_name', 'faixa_faturamento_tabela')->count();
        $this->assertSame($antesDaGravacao + 1, $depoisDaGravacao);

        $activity = $this->ultimaActivity($company);
        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);

        $props = $activity->properties->toArray();
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $props['origem_anterior']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $props['origem_nova']);
        $this->assertSame('fechamento', $props['feito_de']);

        $this->assertCount(1, $props['antes']);
        $this->assertSame(500.00, (float) $props['antes'][0]['valor']);

        $this->assertCount(2, $props['depois']);
        $this->assertSame(1200.00, (float) $props['depois'][0]['valor']);
        $this->assertSame(2400.00, (float) $props['depois'][1]['valor']);
    }

    // ── 9. gravação que falha não deixa entrada de auditoria nem altera linha ─

    #[Test]
    public function gravacao_que_falha_por_precedencia_nao_deixa_entrada_de_auditoria(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        $antes = Activity::where('log_name', 'faixa_faturamento_tabela')->count();

        try {
            $this->service()->gravar($company, $this->faixas(), EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);
        } catch (\RuntimeException $e) {
            // esperado
        }

        $depois = Activity::where('log_name', 'faixa_faturamento_tabela')->count();
        $this->assertSame($antes, $depois);
    }

    // ── 10. array de faixas vazio lança exceção e é all-or-nothing ───────

    #[Test]
    public function gravar_com_array_de_faixas_vazio_lanca_excecao(): void
    {
        $company = Company::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service()->gravar($company, [], EmpresaFaixaFaturamento::ORIGEM_MANUAL);
    }

    #[Test]
    public function gravar_com_array_vazio_nao_altera_linha_existente(): void
    {
        $company = Company::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 321.00);

        try {
            $this->service()->gravar($company, [], EmpresaFaixaFaturamento::ORIGEM_MANUAL);
        } catch (\RuntimeException $e) {
            // esperado
        }

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(1, $linhas);
        $this->assertSame(321.00, (float) $linhas[0]->valor);
    }

    // ── 11. remover(Company) apaga a tabela e registra depois = [] ───────

    #[Test]
    public function remover_apaga_a_tabela_e_registra_depois_vazio(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();
        $this->criarLinhaExistente($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 654.00);

        $this->service()->remover($company, por: $user, feitoDe: 'fechamento');

        $this->assertSame(0, EmpresaFaixaFaturamento::where('company_id', $company->id)->count());

        $activity = $this->ultimaActivity($company);
        $this->assertNotNull($activity);

        $props = $activity->properties->toArray();
        $this->assertCount(1, $props['antes']);
        $this->assertSame(654.00, (float) $props['antes'][0]['valor']);
        $this->assertSame([], $props['depois']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $props['origem_anterior']);
        $this->assertNull($props['origem_nova']);
    }
}
