<?php

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\PoloAdsStatus;
use App\Models\User;
use App\Services\Polos\AdsCampanhasPolos;
use App\Services\Polos\VinculoAdmanPolos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TKT-0014 — coluna e filtro "Vínculo Adman" (Vinculado / Não vinculado) no Painel Polos.
 *
 * Sem chamada nova à Adman: o sync já lê as contas M1–M4 duas vezes por dia e só grava
 * `polos_ads_status` quando a Adman responde. Conta sem vínculo responde "User is not
 * mentored by agency" e nunca ganha linha — é isso que vira "Não vinculado".
 *
 * O que estes testes protegem (e não é óbvio no código):
 *   - leitura velha (> FRESCOR_HORAS) não vale mais como vínculo: a Adman parou de responder;
 *   - fora das fases que o sync lê (ex.: M0) o sistema NÃO afirma "Não vinculado" — fica
 *     vazio, porque ninguém conferiu;
 *   - o cust do cadastro é normalizado antes de casar com a leitura ("123,0" = "123").
 *
 * @group polos
 */
class VinculoAdmanPainelTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $nome, ?string $cust, string $fase = 'M2'): MlbEmpresa
    {
        return MlbEmpresa::create([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => $fase,
            'cust_id' => $cust,
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ]);
    }

    private function leitura(string $cust, \DateTimeInterface $quando): void
    {
        PoloAdsStatus::create([
            'cust_id'          => $cust,
            'campanhas_ativas' => 1,
            'campanhas_total'  => 2,
            'verificado_em'    => $quando,
        ]);
    }

    /** Linhas do painel indexadas pelo id da empresa. */
    private function linhasDoPainel(): array
    {
        $resposta = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('mlb.polos-painel'));

        $resposta->assertOk();

        return collect($resposta->viewData('page')['props']['empresas'])->keyBy('id')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // A página Inertia não precisa do bundle para o teste ler as props.
        $this->withoutVite();
    }

    // ─── Regra ───────────────────────────────────────────────────────────────

    public function test_regra_vinculado_nao_vinculado_e_nao_verificado(): void
    {
        $this->leitura('111', now()->subHours(3));
        $this->leitura('222', now()->subHours(PoloAdsStatus::FRESCOR_HORAS + 1)); // velha

        $v = VinculoAdmanPolos::carregar();

        $this->assertSame(VinculoAdmanPolos::VINCULADO, $v->status('M2', '111'));
        // Cust do cadastro com sufixo decimal casa com a leitura normalizada.
        $this->assertSame(VinculoAdmanPolos::VINCULADO, $v->status('M3', '111,0'));
        // Leitura velha: a Adman parou de responder pela conta.
        $this->assertSame(VinculoAdmanPolos::NAO_VINCULADO, $v->status('M2', '222'));
        // Nunca respondeu, numa fase que o sync lê.
        $this->assertSame(VinculoAdmanPolos::NAO_VINCULADO, $v->status('M1', '333'));
        $this->assertSame(VinculoAdmanPolos::NAO_VINCULADO, $v->status('Fechamento', '333'));
        // Fase que o sync não lê: ninguém conferiu, então não se afirma nada.
        $this->assertNull($v->status('M0', '333'));
        $this->assertNull($v->status('Encerrado', '333'));
        // Sem Cust ID não há como conferir.
        $this->assertNull($v->status('M2', null));
        $this->assertNull($v->status('M2', '  '));
        // Mesmo fora das fases lidas, uma resposta fresca da Adman prova o vínculo.
        $this->assertSame(VinculoAdmanPolos::VINCULADO, $v->status('M0', '111'));

        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2} \d{2}:\d{2}$/', $v->lidoEm('111'));
        $this->assertNull($v->lidoEm('222'));
        $this->assertNull($v->lidoEm(null));
    }

    public function test_nao_vinculado_segue_as_fases_que_o_sync_le(): void
    {
        // Uma lista só decide as duas coisas: quem o sync lê e quem pode sair "Não vinculado".
        // Se o sync passar a ler M0, a coluna acompanha sem mexer na regra.
        $this->assertSame(['M1', 'M2', 'M3', 'M4', 'Fechamento'], AdsCampanhasPolos::FASES_LIDAS);

        $v = VinculoAdmanPolos::carregar();
        foreach (AdsCampanhasPolos::FASES_LIDAS as $fase) {
            $this->assertSame(VinculoAdmanPolos::NAO_VINCULADO, $v->status($fase, '999'), $fase);
        }
    }

    // ─── Payload do Painel ───────────────────────────────────────────────────

    public function test_painel_manda_o_vinculo_de_cada_empresa(): void
    {
        $vinculada   = $this->empresa('A Vinculada', '111');
        $semVinculo  = $this->empresa('B Sem vínculo', '222', 'M3');
        $velha       = $this->empresa('C Leitura velha', '333', 'M4');
        $entrante    = $this->empresa('D Entrante', '444', 'M0');
        $semCust     = $this->empresa('E Sem cust', null, 'M2');

        $this->leitura('111', now()->subHours(2));
        $this->leitura('333', now()->subDays(4));

        $linhas = $this->linhasDoPainel();

        $this->assertSame('Vinculado', $linhas[$vinculada->id]['adman_vinculo']);
        $this->assertNotNull($linhas[$vinculada->id]['adman_lido_em']);
        $this->assertSame('Não vinculado', $linhas[$semVinculo->id]['adman_vinculo']);
        $this->assertNull($linhas[$semVinculo->id]['adman_lido_em']);
        $this->assertSame('Não vinculado', $linhas[$velha->id]['adman_vinculo']);
        $this->assertNull($linhas[$entrante->id]['adman_vinculo']);
        $this->assertNull($linhas[$semCust->id]['adman_vinculo']);
    }

    public function test_cadastro_duplicado_com_o_mesmo_cust_recebe_o_mesmo_vinculo(): void
    {
        // Produção tem pares assim (mesmo cust em duas empresas): a leitura é por conta.
        $a = $this->empresa('Loja Um', '555');
        $b = $this->empresa('Loja Um (duplicada)', '555', 'M3');
        $this->leitura('555', now()->subHour());

        $linhas = $this->linhasDoPainel();

        $this->assertSame('Vinculado', $linhas[$a->id]['adman_vinculo']);
        $this->assertSame('Vinculado', $linhas[$b->id]['adman_vinculo']);
    }
}
