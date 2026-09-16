<?php

namespace Tests\Feature\Phase158;

use App\Http\Middleware\AposentaTokenDoPortal;
use App\Models\Company;
use App\Support\Portal\ModulosPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * Calculadora de Custo — o simulador do portal de Polos como módulo do Portal
 * do Cliente (14/09).
 *
 * A conta em si vive no JSX e é a mesma de `calcPreco()`. O que se prova aqui é
 * o que o backend decide: que o módulo existe, aparece no menu e não inventa
 * régua de permissão — a tela não grava nada, então não há escrita para
 * proteger. Desde 15/09/2026 ela abre só pela porta autenticada.
 */
class CalculadoraNoPortalTest extends TestCase
{
    use EntraNoPortal;
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function empresa(): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Calc '.$n,
            'cnpj'   => "15.815.815/{$n}-84",
        ]);
    }

    /** Props da calculadora, pela sessão do cliente da empresa. */
    private function props(Company $empresa): array
    {
        return $this->entrarNoPortal($empresa)
            ->get(route('portal.auth.calculadora'))
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ─── As portas ──────────────────────────────────────────────────────────

    /**
     * A rota por token segue registrada — ela está em links antigos — mas passa
     * pelo `AposentaTokenDoPortal`, que só leva ao login.
     */
    public function test_a_porta_autenticada_existe_e_a_do_token_esta_aposentada(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('portal.auth.calculadora'), 'falta a rota autenticada');

        $porToken = Route::getRoutes()->getByName('portal.calculadora');
        $this->assertNotNull($porToken, 'a rota antiga sumiu — links antigos cairiam em 404 em vez de irem ao login');
        $this->assertContains(AposentaTokenDoPortal::class, $porToken->gatherMiddleware());
    }

    /**
     * Ao contrário dos blocos do onboarding, a calculadora NÃO é só da equipe:
     * ela não grava nada, e o cliente sozinho tem tanto direito de simular
     * quanto nós. Uma régua aqui seria restrição sem nada para proteger.
     */
    public function test_o_cliente_logado_abre_a_calculadora(): void
    {
        $this->entrarNoPortal($this->empresa())
            ->get(route('portal.auth.calculadora'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/Calculadora', false));
    }

    // ─── O menu ─────────────────────────────────────────────────────────────

    public function test_o_modulo_aparece_no_menu_com_o_item_ativo_certo(): void
    {
        $porChave = collect($this->props($this->empresa())['modulos'])->keyBy('chave');

        $this->assertArrayHasKey(ModulosPortal::CALCULADORA, $porChave->all());
        $this->assertTrue($porChave[ModulosPortal::CALCULADORA]['ativo']);
        $this->assertSame('Calculadora de Custo', $porChave[ModulosPortal::CALCULADORA]['rotulo']);

        // Os outros continuam lá — o módulo novo não expulsou ninguém.
        foreach ([ModulosPortal::INICIO, ModulosPortal::ONBOARDING, ModulosPortal::PPA] as $chave) {
            $this->assertArrayHasKey($chave, $porChave->all());
            $this->assertFalse($porChave[$chave]['ativo']);
        }
    }

    /**
     * O ícone é resolvido por nome num mapa do layout. Nome fora do mapa cai
     * num genérico sem erro nenhum — some do radar exatamente como a etapa que
     * faltava em `ETAPAS_ORDEM`.
     */
    public function test_o_icone_declarado_existe_no_mapa_do_layout(): void
    {
        $icone = collect($this->props($this->empresa())['modulos'])
            ->firstWhere('chave', ModulosPortal::CALCULADORA)['icone'];

        $layout = file_get_contents(resource_path('js/Layouts/PortalClienteLayout.jsx'));

        $this->assertMatchesRegularExpression(
            "/'".preg_quote($icone, '/')."':\s*\w+/",
            $layout,
            "o ícone \"{$icone}\" não está no mapa ICONES de PortalClienteLayout.jsx"
        );
    }

    public function test_a_calculadora_nao_expoe_dado_de_operacao(): void
    {
        $props = $this->props($this->empresa());

        // A tela é uma régua de conversa: empresa e menu bastam. Passo,
        // responsável ou faturamento aqui seria vazamento sem motivo.
        foreach (['passos', 'blocos_operacao', 'fotografia', 'pessoas'] as $chave) {
            $this->assertArrayNotHasKey($chave, $props, "\"{$chave}\" não tem o que fazer na calculadora");
        }
    }
}
