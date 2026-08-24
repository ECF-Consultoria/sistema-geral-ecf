<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\OnboardingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O domínio do Portal do Cliente não serve o sistema interno.
 *
 * O Portal roda na MESMA aplicação do admin, num segundo domínio. Sem
 * `RestringeDominioDoPortal`, todas as rotas internas respondem nos dois
 * endereços — e até 24/08/2026 respondiam: `cliente.ecfconsultoria.com.br/dashboard`
 * levava à tela de login do admin, e um login ali entregava o sistema interno
 * inteiro no endereço que divulgamos para cliente.
 *
 * A regra é ALLOWLIST: no domínio do cliente só existe o que está em
 * `RestringeDominioDoPortal::PERMITIDO`. Estes testes protegem os dois lados —
 * que o interno não vaze, e que o portal continue funcionando.
 */
class DominioDoPortalTest extends TestCase
{
    use RefreshDatabase;

    private const DOMINIO = 'cliente.ecfconsultoria.com.br';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.dominio_cliente' => self::DOMINIO]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa Portal '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function token(Company $company): string
    {
        return OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => Str::random(48)]
        )->token;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    /** Requisição como se viesse do domínio do cliente. */
    private function noDominioDoCliente(string $uri)
    {
        return $this->get('http://'.self::DOMINIO.$uri);
    }

    // ─── O que NÃO pode existir no domínio do cliente ───────────────────────

    /**
     * O caso que motivou tudo: o usuário viu que logar em
     * `cliente.ecfconsultoria.com.br` caía na tela do admin.
     */
    #[Test]
    public function tela_de_login_do_admin_nao_existe_no_dominio_do_cliente(): void
    {
        $this->noDominioDoCliente('/login')->assertNotFound();
    }

    /**
     * Varredura ampla. Se alguém acrescentar uma rota interna e ela vazar, é
     * aqui que aparece.
     */
    #[Test]
    public function nenhuma_rota_interna_responde_no_dominio_do_cliente(): void
    {
        $internas = [
            '/login', '/register', '/dashboard', '/companies', '/ppa', '/mlb/dashboard',
            '/nps', '/desempenho', '/polos', '/dev/desenvolvimento', '/users',
            '/admin/financeiro', '/painel-executivo', '/onboarding', '/servicos',
            '/profile', '/activity-log', '/mlb/polos-ppa',
        ];

        foreach ($internas as $uri) {
            $this->noDominioDoCliente($uri)->assertNotFound(
                "A rota interna {$uri} respondeu no domínio do cliente."
            );
        }
    }

    /**
     * 404 e não 403: dizer "proibido" confirmaria que a rota existe em algum
     * lugar. No domínio do cliente ela simplesmente não existe.
     */
    #[Test]
    public function rota_interna_devolve_404_e_nao_403(): void
    {
        $this->noDominioDoCliente('/dashboard')->assertStatus(404);
    }

    /**
     * Nem com sessão interna válida. Se um funcionário da ECF estiver logado e
     * abrir o domínio do cliente, ainda assim não há sistema interno lá.
     */
    #[Test]
    public function nem_usuario_interno_logado_alcanca_o_admin_pelo_dominio_do_cliente(): void
    {
        $this->actingAs($this->admin())
            ->get('http://'.self::DOMINIO.'/dashboard')
            ->assertNotFound();
    }

    /** Escrita também: POST/PUT/DELETE internos não existem lá. */
    #[Test]
    public function rotas_de_escrita_internas_nao_existem_no_dominio_do_cliente(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://'.self::DOMINIO.'/ppa', [])->assertNotFound();
        $this->delete('http://'.self::DOMINIO.'/companies/1')->assertNotFound();
    }

    // ─── O que PRECISA continuar existindo ──────────────────────────────────

    #[Test]
    public function o_portal_funciona_no_dominio_do_cliente(): void
    {
        $company = $this->empresa();
        $token = $this->token($company);

        $this->noDominioDoCliente('/portal-cliente/'.$token)->assertOk();
        $this->noDominioDoCliente('/portal-cliente/'.$token.'/onboarding')->assertOk();
        $this->noDominioDoCliente('/portal-cliente/'.$token.'/ppa')->assertOk();
    }

    /** Links antigos estão no WhatsApp de clientes e não podem morrer. */
    #[Test]
    public function o_link_legado_continua_redirecionando_no_dominio_do_cliente(): void
    {
        $token = $this->token($this->empresa());

        $this->noDominioDoCliente('/onboarding-cliente/'.$token)->assertStatus(301);
    }

    /**
     * Quem digita só o domínio vai parar na tela de entrada — nunca num 404,
     * que faria o cliente achar que o portal saiu do ar.
     */
    #[Test]
    public function a_raiz_do_dominio_do_cliente_leva_a_tela_de_entrada(): void
    {
        $this->noDominioDoCliente('/')->assertRedirect(route('portal.entrada'));

        $this->noDominioDoCliente('/entrar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/Entrada'));
    }

    // ─── O domínio interno não muda ─────────────────────────────────────────

    #[Test]
    public function o_dominio_interno_continua_servindo_tudo(): void
    {
        $this->get('http://admin.ecfconsultoria.com.br/login')->assertOk();

        $this->actingAs($this->admin())
            ->get('http://admin.ecfconsultoria.com.br/dashboard')
            ->assertSuccessful();
    }

    /** A raiz do domínio interno continua indo para o dashboard. */
    #[Test]
    public function a_raiz_do_dominio_interno_continua_indo_para_o_dashboard(): void
    {
        $this->get('http://admin.ecfconsultoria.com.br/')
            ->assertRedirect(route('dashboard'));
    }

    /**
     * A sessão do cliente dura muito mais que a da equipe — senão ele pediria
     * código novo a cada duas horas, reintroduzindo o atrito que a mudança veio
     * remover.
     */
    #[Test]
    public function a_sessao_do_cliente_dura_mais_que_a_interna(): void
    {
        config(['session.lifetime' => 120, 'portal.sessao_minutos' => 43200]);

        $this->noDominioDoCliente('/entrar')->assertOk();
        $this->assertSame(43200, config('session.lifetime'));

        // No domínio interno, o lifetime curto continua valendo.
        config(['session.lifetime' => 120]);
        $this->get('http://admin.ecfconsultoria.com.br/login')->assertOk();
        $this->assertSame(120, config('session.lifetime'));
    }

    /**
     * O botão de emergência: sem `PORTAL_CLIENTE_DOMINIO` configurado, tudo
     * volta a responder em qualquer host — é assim que o ambiente local
     * funciona e é como se reverte a mudança sem deploy.
     */
    #[Test]
    public function sem_dominio_configurado_a_restricao_nao_age(): void
    {
        config(['portal.dominio_cliente' => null]);

        $this->get('http://'.self::DOMINIO.'/login')->assertOk();
    }
}
