<?php

namespace Tests\Feature\Phase123;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prova de UIEM-02, D-06 (denominador) e D-07 (Shopee dentro da conta) +
 * regressão de fan-out — 123-02-PLAN.md Task 3.
 *
 * Fixture hermética obrigatória em todos os casos: `seedMensal()` ANTES do
 * `GET` — com o snapshot mensal presente, `show()` usa `breakdown_json` e
 * nunca cai no `computeCached()`, o que torna os testes determinísticos e
 * sem rede.
 */
class PerformanceShowEmpresasScoreTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // Badge global de alertas críticos (`HandleInertiaRequests
        // ::countAlertasCriticos()`) é infraestrutura alheia a esta fase —
        // pré-aquecer evita ruído nas asserções de `Http::assertNothingSent()`.
        Cache::put('alertas.criticos_nao_ackeados.count', 0, 300);
    }

    #[Test]
    public function caminho_comum_tres_empresas_duas_completas_uma_parcial(): void
    {
        $alvo = $this->criarUserElegivel('Caminho Comum');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        $c1 = $this->darCarteira($alvo);
        $c2 = $this->darCarteira($alvo);
        $c3 = $this->darCarteira($alvo);

        $this->seedLinha($alvo->id, $c1, '2026-06-01', ['status' => 'complete']);
        $this->seedLinha($alvo->id, $c2, '2026-06-01', ['status' => 'complete']);
        $this->seedLinha($alvo->id, $c3, '2026-06-01', [
            'status'                => 'partial',
            'nota_empresa'          => null,
            'componentes_presentes' => 1,
        ]);

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', true)
                ->has('empresas_score', 3)
                ->where('empresas_score_resumo.entraram', 2)
                ->where('empresas_score_resumo.nao_entraram', 1)
            );
    }

    #[Test]
    public function tres_componentes_visiveis_uiem_02(): void
    {
        $alvo = $this->criarUserElegivel('Tres Componentes');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        $c1 = $this->darCarteira($alvo);
        $this->seedLinha($alvo->id, $c1, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->has('empresas_score.0.nota_empresa')
                ->has('empresas_score.0.nps_pontos')
                ->has('empresas_score.0.faturamento_pontos')
                ->has('empresas_score.0.margem_pontos')
                ->has('empresas_score.0.margem_pct_atual')
                ->has('empresas_score.0.margem_pct_anterior')
                ->has('empresas_score.0.margem_var_pp')
                ->has('empresas_score.0.quality.motivos')
                ->missing('empresas_score.0.origem')
                ->missing('empresas_score.0.gerado_em')
            );
    }

    #[Test]
    public function regressao_fan_out_zero_chamada_http_com_detalhe_presente(): void
    {
        $alvo = $this->criarUserElegivel('Fan Out');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        $c1 = $this->darCarteira($alvo);
        $c2 = $this->darCarteira($alvo);
        $this->seedLinha($alvo->id, $c1, '2026-06-01');
        $this->seedLinha($alvo->id, $c2, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', true)
            );

        // O mais importante da suíte: um teste desta fase que precisasse de
        // resposta fake real seria sinal de que algo voltou a chamar o
        // dispatcher ao vivo — D-01 exige custo zero de API.
        Http::assertNothingSent();
    }

    #[Test]
    public function shopee_dentro_da_conta_d07(): void
    {
        $alvo = $this->criarUserElegivel('Shopee D07');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        $c1 = $this->darCarteira($alvo);
        $this->seedLinhaShopee($alvo->id, $c1, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->has('empresas_score', 1)
                ->where('empresas_score.0.fonte_financeira', 'shopee')
                ->where('empresas_score.0.quality.margin_source', 'placeholder_shopee')
                ->where('empresas_score_resumo.entraram', 1)
                ->where('empresas_score_resumo.nao_entraram', 0)
            );
    }

    #[Test]
    public function fixture_matheus_estrela_carteira_so_shopee(): void
    {
        $alvo = $this->criarUserElegivel('Matheus Estrela');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        $c1 = $this->darCarteira($alvo);
        $c2 = $this->darCarteira($alvo);
        $this->seedLinhaShopee($alvo->id, $c1, '2026-06-01');
        $this->seedLinhaShopee($alvo->id, $c2, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('empresas_score_resumo.entraram', 2)
                ->where('empresas_score_resumo.nao_entraram', 0)
                ->where('empresas_score', fn ($linhas) => $linhas->every(
                    fn (array $linha) => ($linha['quality']['margin_source'] ?? null) === 'placeholder_shopee'
                ))
            );
    }

    #[Test]
    public function fixture_felipe_denominador_pequeno_3_de_30(): void
    {
        $alvo = $this->criarUserElegivel('Felipe');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        for ($i = 0; $i < 3; $i++) {
            $c = $this->darCarteira($alvo);
            $this->seedLinha($alvo->id, $c, '2026-06-01', ['status' => 'complete']);
        }

        for ($i = 0; $i < 27; $i++) {
            $c = $this->darCarteira($alvo);
            $this->seedLinha($alvo->id, $c, '2026-06-01', [
                'status'                => 'partial',
                'nota_empresa'          => null,
                'componentes_presentes' => 1,
            ]);
        }

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->has('empresas_score', 30)
                ->where('empresas_score_resumo.entraram', 3)
                ->where('empresas_score_resumo.nao_entraram', 27)
            );
    }

    #[Test]
    public function em_curso_nao_lista_mesmo_com_linhas_gravadas_d01(): void
    {
        $alvo = $this->criarUserElegivel('Em Curso D01');
        $this->adminLogado();

        // Sem carteira (nenhum vínculo company_users) — só uma empresa
        // "solta" com linha gravada na competência CORRENTE (2026-08, "hoje"
        // fixado em 2026-08-15 pelo Phase123TestCase). Mantém compute()
        // no ramo sem_carteira, sem qualquer dependência de rede, enquanto
        // ainda prova que a linha existe e mesmo assim não aparece.
        $company = Company::factory()->create();
        $this->seedLinha($alvo->id, $company->id, '2026-08-01');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('periodo.is_closed', false)
                ->where('empresas_score', [])
                ->where('tem_detalhe_empresas', false)
            );
    }

    #[Test]
    public function ordenacao_nota_desc_com_null_por_ultimo(): void
    {
        $alvo = $this->criarUserElegivel('Ordenacao Show');
        $this->adminLogado();
        $this->seedMensal($alvo->id, '2026-06-01');

        $cA = $this->darCarteira($alvo);
        $cB = $this->darCarteira($alvo);
        $cC = $this->darCarteira($alvo);

        $this->seedLinha($alvo->id, $cA, '2026-06-01', ['company_name' => 'Empresa A', 'nota_empresa' => 3.5]);
        $this->seedLinha($alvo->id, $cB, '2026-06-01', ['company_name' => 'Empresa B', 'nota_empresa' => 4.8]);
        $this->seedLinha($alvo->id, $cC, '2026-06-01', [
            'company_name'          => 'Empresa C',
            'nota_empresa'          => null,
            'status'                => 'partial',
            'componentes_presentes' => 1,
        ]);

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('empresas_score.0.nota_empresa', 4.8)
                ->where('empresas_score.1.nota_empresa', 3.5)
                ->where('empresas_score.2.nota_empresa', null)
            );
    }
}
