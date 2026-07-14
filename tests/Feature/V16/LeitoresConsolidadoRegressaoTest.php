<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressão FINAL dos leitores consolidados reais (DEC-A2, Plano 76-04).
 *
 * Prova, exercitando as MESMAS relações/queries que os ~15 leitores de
 * produção usam, que a dimensão de serviço (linha ML + linha Shopee da
 * Phase 78) NÃO altera o comportamento consolidado — o bônus não dobra e o
 * responsável não troca. Cada assert referencia o leitor Grupo A/B que blinda.
 *
 * Não reimplementa a lógica dos leitores: apenas roda as relações que eles
 * consomem (Company::consultor()/estrategista(), User::companies()/
 * *Companies(), Company::whereHas('users', role)) antes e depois da linha
 * Shopee — sempre com a linha Shopee usando assigned_at/timestamps
 * DIVERGENTES (vetor do Pitfall 4 do RESEARCH).
 */
class LeitoresConsolidadoRegressaoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /**
     * Grupo A — leitores que leem consultor()/estrategista()->first()
     * (NpsDispararMensal:198,206; Goal/CalculateGoalResults; Dashboard self-join).
     * O responsável consolidado é IDÊNTICO antes e depois da linha Shopee.
     */
    public function test_grupo_a_responsavel_consolidado_identico_com_linha_shopee(): void
    {
        $cenario      = $this->criarCenarioMlComResponsaveis();
        $company      = $cenario['company'];
        $analista     = $cenario['analista'];
        $estrategista = $cenario['estrategista'];

        // Snapshot do responsável consolidado ANTES da linha Shopee — é isso que
        // NpsDispararMensal::handle() lê via $empresa->estrategista()->first() e
        // $empresa->consultor()->first().
        $estrategistaAntes = $company->estrategista()->first();
        $analistaAntes     = $company->consultor()->first();

        $this->assertSame($estrategista->id, $estrategistaAntes->id, 'Baseline: estrategista consolidado deve ser o do cenário.');
        $this->assertSame($analista->id, $analistaAntes->id, 'Baseline: analista (role consultor) consolidado deve ser o do cenário.');

        // Simula a duplicação por-serviço da Phase 78: MESMO analista e MESMO
        // estrategista ganham a linha Shopee (servico_id divergente).
        $this->inserirLinhaShopee($company->id, $analista->id, 'consultor');
        $this->inserirLinhaShopee($company->id, $estrategista->id, 'estrategista');

        // Recarrega para descartar relações já resolvidas em cache.
        $company = Company::find($company->id);

        // NpsDispararMensal continua enxergando 1 responsável, o mesmo de antes.
        $this->assertSame(1, $company->consultor()->count(), 'NpsDispararMensal: consultor()->count() deve permanecer 1 (dedup por distinct).');
        $this->assertSame(1, $company->estrategista()->count(), 'NpsDispararMensal: estrategista()->count() deve permanecer 1.');
        $this->assertSame(
            $analistaAntes->id,
            $company->consultor()->first()->id,
            'NpsDispararMensal: consultor()->first() deve continuar apontando para o mesmo analista após a linha Shopee.'
        );
        $this->assertSame(
            $estrategistaAntes->id,
            $company->estrategista()->first()->id,
            'NpsDispararMensal: estrategista()->first() deve continuar apontando para o mesmo estrategista após a linha Shopee.'
        );
    }

    /**
     * Grupo B — carteira do bônus via companies()/consultorCompanies()
     * (DesempenhoScoreService:251,289; PortfolioController:517-518,615-616).
     * A carteira conta a empresa 1x mesmo com assigned_at divergente entre ML e Shopee.
     */
    public function test_grupo_b_carteira_conta_empresa_uma_vez_apos_shopee(): void
    {
        $cenario  = $this->criarCenarioMlComResponsaveis();
        $company  = $cenario['company'];
        $analista = $cenario['analista'];

        // Baseline (só ML) — vetor iterado por DesempenhoScoreService::computeUniverso.
        $this->assertSame(1, $analista->companies()->get()->count(), 'Baseline: companies()->get() deve trazer 1 empresa.');
        $this->assertSame(1, $analista->consultorCompanies()->get()->count(), 'Baseline: consultorCompanies()->get() deve trazer 1 empresa.');

        // Linha Shopee com assigned_at/timestamps DIVERGENTES — furaria um distinct ingênuo.
        $shopee = $this->inserirLinhaShopee($company->id, $analista->id, 'consultor');
        DB::table('company_users')->where('id', $shopee['row'])->update([
            'assigned_at' => now()->subDays(7)->toDateString(),
            'created_at'  => now()->subDays(7),
            'updated_at'  => now()->subDays(7),
        ]);

        $analista = User::find($analista->id);

        // DesempenhoScoreService / PortfolioController iteram ->get(); provamos que
        // a carteira não dobra pelos dois caminhos (COUNT no banco e hidratação).
        $this->assertSame(1, $analista->companies()->count(), 'DesempenhoScoreService: companies()->count() não pode dobrar (vetor do bônus).');
        $this->assertSame(1, $analista->companies()->get()->count(), 'DesempenhoScoreService: companies()->get() deve hidratar a empresa 1x.');
        $this->assertSame(1, $analista->consultorCompanies()->count(), 'PortfolioController: consultorCompanies()->count() não pode dobrar.');
        $this->assertSame(1, $analista->consultorCompanies()->get()->count(), 'PortfolioController: consultorCompanies()->get() deve hidratar a empresa 1x.');
    }

    /**
     * Grupo B — PortfolioGoal::getCarteira (PortfolioGoal:98-103) usa
     * Company::whereHas('users', role)->get(). whereHas não faz join na companies,
     * então não duplica — mas asserimos explicitamente para blindar o vetor do bônus.
     */
    public function test_portfolio_goal_wherehas_conta_empresa_uma_vez(): void
    {
        $cenario  = $this->criarCenarioMlComResponsaveis();
        $company  = $cenario['company'];
        $analista = $cenario['analista'];

        // Replica exatamente a query de PortfolioGoal::getCarteira (role consultor).
        $carteiraQuery = fn () => Company::whereHas('users', function ($q) use ($analista) {
            $q->where('users.id', $analista->id)
              ->where('company_users.role', 'consultor');
        })->where('active', true)->get();

        $this->assertSame(1, $carteiraQuery()->count(), 'Baseline: PortfolioGoal whereHas deve contar a empresa 1x.');

        // Linha Shopee do mesmo analista/role.
        $this->inserirLinhaShopee($company->id, $analista->id, 'consultor');

        $this->assertSame(
            1,
            $carteiraQuery()->count(),
            'PortfolioGoal::getCarteira: whereHas não pode duplicar a empresa mesmo com a linha Shopee.'
        );
    }

    /**
     * W3 (Assumption A2) — PortfolioController:670-673 re-declara
     * companies()->withPivot('role') e lê $c->pivot->role em :849. Como
     * companies() usa ->select('companies.*'), precisamos garantir que o
     * withPivot('role') do call-site AINDA resolve o role (não vem null) após
     * o dedup da relação base.
     */
    public function test_w3_pivot_role_continua_resolvendo_apos_dedup(): void
    {
        $cenario  = $this->criarCenarioMlComResponsaveis();
        $company  = $cenario['company'];
        $analista = $cenario['analista'];

        // Linha Shopee (mesmo par, role consultor) — cenário pós-dedup.
        $this->inserirLinhaShopee($company->id, $analista->id, 'consultor');

        $analista = User::find($analista->id);

        // Exatamente o padrão de PortfolioController::show: companies()->withPivot('role')->...->get().
        $primeira = $analista->companies()->withPivot('role')->first();

        $this->assertNotNull($primeira, 'A carteira do analista deve trazer a empresa.');
        $this->assertNotNull(
            $primeira->pivot->role,
            'PortfolioController:849 lê $c->pivot->role — não pode vir null após ->select(companies.*)->distinct().'
        );
        $this->assertSame(
            'consultor',
            $primeira->pivot->role,
            'PortfolioController: o pivot->role da carteira do analista deve resolver para "consultor".'
        );
    }
}
