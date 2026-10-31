<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\User;
use App\Services\EcfDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Concerns\EntraNoPortal;
use Tests\Concerns\PortalComTodosOsModulos;
use Tests\TestCase;

/**
 * A contagem de alertas críticos não vai ao Portal do cliente (31/10/2026).
 *
 * `alertas_criticos_count` é o badge "Alertas Estratégicos" do menu INTERNO: os alertas críticos da carteira
 * inteira no ECF Drive. Como prop compartilhada do `HandleInertiaRequests`, era resolvida em toda carga de página
 * sem olhar quem pedia — ia no data-page do Portal do cliente, e a carga dele perguntava ao ECF Drive
 * (GET /signals; o cache de 5 min só espaça as chamadas). Agora só sai para quem tem usuário `web`; o menu
 * interno (`AppLayout`) já tratava null como 0.
 *
 * Achado na revisão da Trilha de produtos, cuja porta do cliente tem varredura própria de TODAS as compartilhadas
 * (`Tests\Feature\Trilha\AcessoATrilhaTest`). Este teste não depende da trilha: vale para o Portal inteiro.
 */
class AlertasCriticosSoNaEquipeTest extends TestCase
{
    use EntraNoPortal, PortalComTodosOsModulos, RefreshDatabase;

    /** Quantas vezes o ECF Drive foi chamado. */
    private int $chamadas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Nada sai para a rede: o dublê responde pelo ECF Drive. Total diferente de zero de propósito — um zero
        // vazado passaria por "vazio".
        Http::preventStrayRequests();
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')
            ->andReturnUsing(function () {
                $this->chamadas++;

                return ['total' => 4242];
            }));
    }

    public function test_o_portal_do_cliente_nao_leva_a_contagem_nem_pergunta_ao_ecf_drive(): void
    {
        $empresa = Company::factory()->create();

        $props = $this->props($this->entrarNoPortal($empresa)->get(route('portal.auth.inicio'))->assertOk());

        $this->assertNull($props['auth']['user'] ?? null, 'a página é do cliente: sem usuário interno');
        $this->assertArrayHasKey('alertas_criticos_count', $props);
        $this->assertNull($props['alertas_criticos_count']);
        $this->assertSame(0, $this->chamadas, 'a página do cliente não pergunta nada ao ECF Drive');
    }

    public function test_quem_esta_logado_no_sistema_interno_continua_com_a_contagem(): void
    {
        $usuario = User::factory()->create(['role' => 'admin', 'active' => true]);

        $props = $this->props($this->actingAs($usuario)->get(route('profile.edit'))->assertOk());

        $this->assertSame(4242, $props['alertas_criticos_count']);
        $this->assertSame(1, $this->chamadas);
    }

    /** As props da página Inertia como o navegador as recebe. */
    private function props(TestResponse $resposta): array
    {
        return json_decode(json_encode($resposta->viewData('page')), true)['props'];
    }
}
