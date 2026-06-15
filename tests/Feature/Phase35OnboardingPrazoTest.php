<?php

namespace Tests\Feature;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Suíte de testes feature para o Onboarding (Phase 35).
 *
 * Cobre: ONB-11 (status 'concluida' para empresa 100% concluída),
 * ONB-09 (prazo 7 dias + fora do prazo — adicionado nos planos subsequentes).
 *
 * @group phase35
 */
class Phase35OnboardingPrazoTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria uma MlbEmpresa + MlbImplementacao associada.
     * Adapta o helper homônimo de Phase33OnboardingFichaTest.
     *
     * @param  array $empresaOpts  Sobrescreve campos da empresa (ex: ['nome' => 'X'])
     * @param  array $implOpts    Sobrescreve campos da implementação (ex: ['dados' => [...]])
     * @return array [$empresa (MlbEmpresa), $impl (MlbImplementacao)]
     */
    private function criarEmpresaComImpl(array $empresaOpts = [], array $implOpts = []): array
    {
        $empresa = MlbEmpresa::create(array_merge([
            'nome'       => 'Empresa Teste ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => 1,
        ], $empresaOpts));

        $impl = MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ], $implOpts));

        return [$empresa, $impl];
    }

    /**
     * Retorna o array de dados com todos os 15 itens do checklist marcados como feito=true.
     * Usado para simular empresa 100% concluída.
     */
    private function dadosTodosFeitos(): array
    {
        $dados = MlbImplementacao::dadosPadrao();
        foreach ($dados['itens'] as $id => &$item) {
            $item['feito'] = true;
        }
        unset($item);
        return $dados;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONB-11 — Fix do bug: status 'concluida' para empresa 100% concluída
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Empresa com todos os 15 itens marcados como feito=true deve aparecer
     * como status='concluida' na prop empresas[0].status e incrementar
     * status_counts.concluida para 1.
     *
     * Antes do fix ONB-11: round() retornava float 100.0; === 100 era false;
     * empresa caía em 'parada' ou 'em_andamento', nunca em 'concluida'.
     */
    public function test_empresa_100_pct_aparece_como_concluida_nos_indicadores(): void
    {
        $admin = $this->criarAdmin();

        // Cria empresa com todos os itens feitos
        $this->criarEmpresaComImpl([], [
            'dados' => $this->dadosTodosFeitos(),
        ]);

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.indicadores'))
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/ImplementacaoIndicadores')
                ->where('status_counts.concluida', 1)
                ->has('empresas', 1, fn(Assert $empresa) => $empresa
                    ->where('status', 'concluida')
                    ->where('progresso.pct', 100)
                    ->etc()
                )
            );
    }

    /**
     * Empresa com dados padrão (0 itens feitos) deve aparecer como
     * status='nao_iniciada' e incrementar status_counts.nao_iniciada para 1.
     * Testa não-regressão do branch zero após o fix.
     */
    public function test_empresa_zero_pct_aparece_como_nao_iniciada_nos_indicadores(): void
    {
        $admin = $this->criarAdmin();

        // Cria empresa com dados padrão (nenhum item feito)
        $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.indicadores'))
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/ImplementacaoIndicadores')
                ->where('status_counts.nao_iniciada', 1)
                ->has('empresas', 1, fn(Assert $empresa) => $empresa
                    ->where('status', 'nao_iniciada')
                    ->where('progresso.pct', 0)
                    ->etc()
                )
            );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONB-09 / ONB-10 — Prazo e filtro fora_do_prazo no index()
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * index() sem filtro deve retornar todas as empresas com as props
     * fora_do_prazo, dias_restantes e dias_decorridos presentes em cada empresa.
     */
    public function test_index_expoe_props_de_prazo_por_empresa(): void
    {
        $admin = $this->criarAdmin();

        // Empresa dentro do prazo (data_solicitacao há 2 dias)
        $this->criarEmpresaComImpl([], [
            'data_solicitacao' => now()->subDays(2)->toDateString(),
        ]);

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.index'))
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/Implementacao')
                ->has('empresas', 1, fn(Assert $e) => $e
                    ->has('fora_do_prazo')
                    ->has('dias_restantes')
                    ->has('dias_decorridos')
                    ->etc()
                )
                ->has('filtros.fora_do_prazo')
            );
    }

    /**
     * GET ?fora_do_prazo=1 com 1 empresa vencida (não concluída, 8 dias)
     * e 1 dentro do prazo → retorna apenas a empresa vencida.
     */
    public function test_filtro_fora_do_prazo_retorna_apenas_empresas_vencidas(): void
    {
        $admin = $this->criarAdmin();

        // Empresa vencida: não concluída, data_solicitacao há 8 dias
        $this->criarEmpresaComImpl(
            ['nome' => 'Empresa Vencida'],
            ['data_solicitacao' => now()->subDays(8)->toDateString()]
        );

        // Empresa dentro do prazo: não concluída, data_solicitacao há 3 dias
        $this->criarEmpresaComImpl(
            ['nome' => 'Empresa Dentro do Prazo'],
            ['data_solicitacao' => now()->subDays(3)->toDateString()]
        );

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.index', ['fora_do_prazo' => 1]))
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/Implementacao')
                ->has('empresas', 1, fn(Assert $e) => $e
                    ->where('nome', 'Empresa Vencida')
                    ->where('fora_do_prazo', true)
                    ->etc()
                )
                ->where('filtros.fora_do_prazo', true)
            );
    }

    /**
     * Filtro ?fora_do_prazo=1 combinado com ?polo= preserva o filtro de Polo.
     * Cria 1 empresa vencida em Arapongas + 1 vencida em outro polo
     * → deve retornar apenas a de Arapongas.
     * Cobre Pitfall 3 (composição de filtros).
     */
    public function test_filtro_fora_do_prazo_preserva_filtro_polo(): void
    {
        $admin = $this->criarAdmin();

        // Empresa vencida em Arapongas
        $this->criarEmpresaComImpl(
            ['nome' => 'Vencida Arapongas', 'polo' => 'Arapongas'],
            ['data_solicitacao' => now()->subDays(10)->toDateString()]
        );

        // Empresa vencida em outro polo
        $this->criarEmpresaComImpl(
            ['nome' => 'Vencida Outro Polo', 'polo' => 'Bento Gonçalves'],
            ['data_solicitacao' => now()->subDays(10)->toDateString()]
        );

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.index', ['polo' => 'Arapongas', 'fora_do_prazo' => 1]))
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/Implementacao')
                ->has('empresas', 1, fn(Assert $e) => $e
                    ->where('nome', 'Vencida Arapongas')
                    ->where('polo', 'Arapongas')
                    ->where('fora_do_prazo', true)
                    ->etc()
                )
                ->where('filtros.polo', 'Arapongas')
                ->where('filtros.fora_do_prazo', true)
            );
    }

    /**
     * Empresa concluída tardiamente (100% feito, 30 dias) NÃO deve aparecer
     * quando o filtro ?fora_do_prazo=1 está ativo.
     */
    public function test_empresa_concluida_nao_aparece_no_filtro_fora_do_prazo(): void
    {
        $admin = $this->criarAdmin();

        // Empresa concluída há 30 dias (tardia, mas concluída)
        $this->criarEmpresaComImpl(
            ['nome' => 'Empresa Concluída'],
            [
                'dados'            => $this->dadosTodosFeitos(),
                'data_solicitacao' => now()->subDays(30)->toDateString(),
            ]
        );

        // Empresa não concluída e vencida
        $this->criarEmpresaComImpl(
            ['nome' => 'Empresa Vencida'],
            ['data_solicitacao' => now()->subDays(10)->toDateString()]
        );

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.index', ['fora_do_prazo' => 1]))
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/Implementacao')
                ->has('empresas', 1, fn(Assert $e) => $e
                    ->where('nome', 'Empresa Vencida')
                    ->etc()
                )
            );
    }
}
