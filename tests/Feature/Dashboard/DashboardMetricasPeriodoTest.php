<?php

namespace Tests\Feature\Dashboard;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fase 97 Plan 01 — Backend do redesign da Dashboard Mercado Livre.
 *
 * Cobre: (1) janelas período-anterior (delta % de faturamento, delta pp de
 * margem ponderada), (2) margin_chart com eixo diário, (3) contagem de
 * "empresas novas no mês" (D3 — início de contrato, não created_at), (4)
 * correção da base do bug do marketplace (`filters.marketplace` +
 * `dashboard_route_name`) incluindo a NÃO-REGRESSÃO da `/dashboard` genérica.
 *
 * `Http::preventStrayRequests()` — nenhuma chamada HTTP real deve ocorrer;
 * as empresas de fixture não têm cust_id, então os batches de cache Adman
 * ficam vazios e o controller cai 100% no fallback DB (adman_metrics).
 */
class DashboardMetricasPeriodoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Empresa do universo Performance (contrato ativo, setor performance).
     * SEM cust_id (nem ml_store_id nem adman_account_id) — garante fallback
     * DB puro em adman_metrics, sem depender de cache Adman.
     */
    private function criarEmpresaPerformance(
        string $name,
        string $cnpj,
        ?string $dataContratacao = null,
        string $marketplace = 'meli',
    ): Company {
        // Coluna `companies.marketplace` é NOT NULL com default 'meli' — as
        // asserções de recorte ML usam justamente esse valor (o filtro
        // `marketplace` do dashboard é sobre esta coluna).
        $company = Company::create([
            'name'        => $name,
            'cnpj'        => $cnpj,
            'active'      => true,
            'marketplace' => $marketplace,
        ]);

        $servico = Servico::create([
            'nome'          => 'Gestao Fase97 ' . uniqid(),
            'valor_padrao'  => 1000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1000,
            'data_contratacao' => $dataContratacao ?? now()->subYears(2)->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    /**
     * Seed de adman_metrics em um dia específico ($diasAtras dias atrás),
     * com revenue e margem de contribuição controlados.
     */
    private function seedMetricaDia(Company $company, int $diasAtras, float $revenue, float $marginPct): void
    {
        AdmanMetric::create([
            'company_id'               => $company->id,
            'reference_date'           => now()->subDays($diasAtras)->toDateString(),
            'revenue'                  => $revenue,
            'ad_spend'                 => 10.0,
            'contribution_margin_pct'  => $marginPct,
            'synced_at'                => now(),
        ]);
    }

    /**
     * TEST 1 — Janela atual (30d) vs. anterior (30d imediatamente antes):
     * delta % de faturamento e delta pp de margem ponderada.
     *
     * Empresa A: revenue 1000/dia atual, 500/dia anterior; margem 20% atual, 10% anterior.
     * Empresa B: revenue 1000/dia atual, 500/dia anterior; margem 30% atual, 20% anterior.
     * (revenue igual entre A e B em cada janela → pesos 1:1, matemática limpa)
     *
     * Total atual = 2000/dia × 30 = 60000; total anterior = 1000/dia × 30 = 30000.
     * Delta % = (60000-30000)/30000×100 = 100.00.
     *
     * Margem ponderada atual = (1000×20 + 1000×30)/2000 = 25.00.
     * Margem ponderada anterior = (500×10 + 500×20)/1000 = 15.00.
     * Delta pp = 25.00 - 15.00 = 10.00.
     */
    public function test_janela_periodo_anterior_calcula_deltas_de_faturamento_e_margem(): void
    {
        $admin = $this->criarAdmin();
        $empresaA = $this->criarEmpresaPerformance('Empresa Alfa 97', '11111111000111');
        $empresaB = $this->criarEmpresaPerformance('Empresa Beta 97', '22222222000122');

        // Janela ATUAL (dias 1..30 atrás) — cobre exatamente [from, to] de period=30.
        for ($i = 1; $i <= 30; $i++) {
            $this->seedMetricaDia($empresaA, $i, 1000.0, 20.0);
            $this->seedMetricaDia($empresaB, $i, 1000.0, 30.0);
        }
        // Janela ANTERIOR (dias 31..60 atrás) — mesmo tamanho, imediatamente antes.
        for ($i = 31; $i <= 60; $i++) {
            $this->seedMetricaDia($empresaA, $i, 500.0, 10.0);
            $this->seedMetricaDia($empresaB, $i, 500.0, 20.0);
        }

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('stats.total_revenue_delta_pct', 100)
            ->where('stats.avg_margin_delta_pp', 10)
            ->where('stats.avg_margin', 25)
            ->has('margin_chart', 31)
        );
    }

    /**
     * TEST 2 — Delta de faturamento é `null` quando a janela anterior não
     * tem faturamento (anterior = 0) — front trata como "sem base" em vez
     * de uma divisão por zero disfarçada de 0%/infinito.
     */
    public function test_delta_faturamento_e_null_quando_janela_anterior_sem_dados(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresaPerformance('Empresa Sem Historico 97', '33333333000133');

        // Só semeia a janela ATUAL — a anterior fica vazia (anterior = 0).
        for ($i = 1; $i <= 30; $i++) {
            $this->seedMetricaDia($empresa, $i, 100.0, 15.0);
        }

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_revenue_delta_pct', null)
        );
    }

    /**
     * TEST 3 — `novas_empresas_count` (D3): conta pelo início do CONTRATO
     * (`data_contratacao`), não `companies.created_at`. Empresa com contrato
     * iniciado neste mês conta; empresa com contrato antigo não conta.
     */
    public function test_novas_empresas_count_usa_inicio_de_contrato(): void
    {
        $admin = $this->criarAdmin();

        $empresaNova = $this->criarEmpresaPerformance(
            'Empresa Nova 97',
            '44444444000144',
            Carbon::now()->startOfMonth()->addDays(2)->toDateString(),
        );
        $empresaAntiga = $this->criarEmpresaPerformance(
            'Empresa Antiga 97',
            '55555555000155',
            now()->subYears(3)->toDateString(),
        );

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.novas_empresas_count', 1)
        );
    }

    /**
     * TEST 4 — `/dashboard/mercadolivre` preserva o recorte `marketplace=meli`
     * no payload (`filters.marketplace`) e expõe `dashboard_route_name` para
     * o front navegar sem perder o contexto ML ao aplicar filtros.
     */
    public function test_dashboard_mercadolivre_preserva_marketplace_no_payload(): void
    {
        $admin = $this->criarAdmin();
        $this->criarEmpresaPerformance('Empresa ML 97', '66666666000166', null, 'meli');

        $response = $this->actingAs($admin)->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('filters.marketplace', 'meli')
            ->where('dashboard_route_name', 'mercadolivre.dashboard')
        );
    }

    /**
     * TEST 5 — NÃO-REGRESSÃO: `/dashboard` genérica (sem query `marketplace`)
     * continua funcionando — `filters.marketplace` deve ser `null` (todo o
     * setor, sem recorte ML) e `dashboard_route_name` deve ser `dashboard`.
     * Fecha o blocker do plan-check (a metade genérica do fix precisa de
     * asserção própria).
     */
    public function test_dashboard_generica_sem_marketplace_nao_regride(): void
    {
        $admin = $this->criarAdmin();
        $this->criarEmpresaPerformance('Empresa Generica 97', '77777777000177');

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('filters.marketplace', null)
            ->where('dashboard_route_name', 'dashboard')
            ->has('stats')
            ->has('revenue_chart')
            ->has('tacos_chart')
            ->has('margin_chart')
        );
    }
}
