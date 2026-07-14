<?php

namespace Tests\Feature\V16;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova que a CARTEIRA que alimenta o bônus NÃO dobra (DEC-A2, prova "c"/"e") quando
 * a mesma empresa tem responsável em 2 serviços (ML + Shopee da Phase 78).
 *
 * Exercita exatamente o Pitfall 4 do RESEARCH: a linha Shopee é inserida com
 * `assigned_at`/timestamps DIVERGENTES da linha ML — o que furaria um `->distinct()`
 * ingênuo. A blindagem é `->select('companies.*')->distinct()` em User::companies()/
 * consultorCompanies()/estrategistaCompanies().
 */
class CarteiraBonusNaoDobraTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    public function test_carteira_conta_empresa_uma_vez_com_ml_e_shopee(): void
    {
        $cenario  = $this->criarCenarioMlComResponsaveis();
        $analista = $cenario['analista'];
        $company  = $cenario['company'];

        // Baseline (só ML): a empresa aparece 1x na carteira.
        $this->assertSame(1, $analista->companies()->count(), 'Baseline: carteira ML deve ter 1 empresa.');
        $this->assertSame(1, $analista->consultorCompanies()->count(), 'Baseline: carteira de analista deve ter 1 empresa.');

        // Adiciona a linha Shopee (mesma empresa + mesmo analista, role consultor).
        $shopee = $this->inserirLinhaShopee($company->id, $analista->id, 'consultor');

        // Força assigned_at + timestamps DIVERGENTES na linha Shopee — este é o vetor
        // que fura um distinct sem select('companies.*') (Pitfall 4).
        DB::table('company_users')->where('id', $shopee['row'])->update([
            'assigned_at' => now()->subDays(3)->toDateString(),
            'created_at'  => now()->subDays(3),
            'updated_at'  => now()->subDays(3),
        ]);

        // Recarrega o model pra descartar qualquer relação já resolvida.
        $analista = User::find($analista->id);

        // A carteira CONTINUA 1 — via ->count() (COUNT no banco) e via ->get()->count()
        // (hidratação dos models), cobrindo os dois caminhos de leitura.
        $this->assertSame(
            1,
            $analista->companies()->count(),
            'companies()->count() deve permanecer 1 com assigned_at divergente (select(companies.*)->distinct()).'
        );
        $this->assertSame(
            1,
            $analista->companies()->get()->count(),
            'companies()->get() deve hidratar a empresa 1x mesmo com linha ML+Shopee divergente.'
        );
        $this->assertSame(
            1,
            $analista->consultorCompanies()->count(),
            'consultorCompanies()->count() deve permanecer 1 — carteira do bônus não dobra.'
        );
        $this->assertSame(
            1,
            $analista->consultorCompanies()->get()->count(),
            'consultorCompanies()->get() deve hidratar a empresa 1x.'
        );
    }

    public function test_carteira_estrategista_nao_dobra_com_ml_e_shopee(): void
    {
        $cenario      = $this->criarCenarioMlComResponsaveis();
        $estrategista = $cenario['estrategista'];
        $company      = $cenario['company'];

        $this->assertSame(1, $estrategista->estrategistaCompanies()->count(), 'Baseline: carteira de estrategista deve ter 1 empresa.');

        $shopee = $this->inserirLinhaShopee($company->id, $estrategista->id, 'estrategista');
        DB::table('company_users')->where('id', $shopee['row'])->update([
            'assigned_at' => now()->subDays(5)->toDateString(),
            'created_at'  => now()->subDays(5),
            'updated_at'  => now()->subDays(5),
        ]);

        $estrategista = User::find($estrategista->id);

        $this->assertSame(
            1,
            $estrategista->estrategistaCompanies()->count(),
            'estrategistaCompanies()->count() deve permanecer 1 com linha Shopee divergente.'
        );
        $this->assertSame(
            1,
            $estrategista->estrategistaCompanies()->get()->count(),
            'estrategistaCompanies()->get() deve hidratar a empresa 1x.'
        );
    }
}
