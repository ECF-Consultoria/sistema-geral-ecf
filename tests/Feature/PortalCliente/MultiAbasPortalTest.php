<?php

namespace Tests\Feature\PortalCliente;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\Company;
use App\Models\EstruturaFamilia;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Support\Portal\PortalContexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PortalComTodosOsModulos;
use Tests\TestCase;

/**
 * Vários portais abertos ao mesmo tempo, um por aba (26/10/2026).
 *
 * A empresa do portal vinha só da sessão, que é uma para todas as abas: abrir
 * o portal de uma segunda empresa trocava a primeira, e o "salvar" da aba
 * antiga gravava na ÚLTIMA empresa aberta. A empresa passou a ir no endereço
 * (`/portal/{empresa}/…`), conferida a cada requisição.
 *
 * O que estes testes protegem:
 *
 *  - **cada aba fica na sua empresa**, para ler e para gravar;
 *  - **o número do endereço não abre nada sozinho**: o cliente só vê as
 *    empresas dele; a equipe só as que abriu pela porta da equipe — e trocar
 *    o número não derruba as outras abas;
 *  - **endereço antigo e "0" continuam funcionando**, pela empresa da sessão,
 *    sem refazer escrita velha;
 *  - **os links gerados dentro da aba levam a empresa dela** (Ziggy).
 */
class MultiAbasPortalTest extends TestCase
{
    use PortalComTodosOsModulos, RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function empresa(string $nome): Company
    {
        return Company::create([
            'name' => $nome.' '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    /** Entra pela porta da equipe, como o "Entrar no portal" de /companies. */
    private function entrarComoEquipe(User $membro, Company $empresa)
    {
        $token = app(\App\Services\Portal\PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');

        return $this->get(route('portal.equipe.entrar', ['t' => $token]));
    }

    private function inicio(Company $empresa): string
    {
        return route('portal.auth.inicio', [PortalContexto::PARAMETRO => $empresa->id]);
    }

    private function codigoDe(PortalUsuario $usuario): string
    {
        \Illuminate\Support\Facades\Notification::fake();

        $this->post(route('portal.codigo'), ['email' => $usuario->email])->assertRedirect();

        $codigo = null;
        \Illuminate\Support\Facades\Notification::assertSentTo(
            $usuario,
            \App\Notifications\PortalCodigoDeAcesso::class,
            function ($n) use (&$codigo) {
                $codigo = (new \ReflectionProperty($n, 'codigo'))->getValue($n);

                return true;
            }
        );

        return $codigo;
    }

    private function clienteDe(Company ...$empresas): PortalUsuario
    {
        $cliente = PortalUsuario::create(['nome' => 'Cliente', 'email' => 'c.'.uniqid().'@empresa.test', 'ativo' => true]);
        foreach ($empresas as $i => $e) {
            $cliente->empresas()->attach($e->id, ['principal' => $i === 0]);
        }

        // Login real, pelo fluxo do cliente.
        $this->post(route('portal.validar'), ['email' => $cliente->email, 'codigo' => $this->codigoDe($cliente)])
            ->assertRedirect();

        return $cliente;
    }

    // ─── Equipe ─────────────────────────────────────────────────────────

    #[Test]
    public function equipe_abre_duas_empresas_e_cada_aba_fica_na_sua(): void
    {
        $a = $this->empresa('Aba A');
        $b = $this->empresa('Aba B');
        $membro = $this->admin();

        $this->entrarComoEquipe($membro, $a)->assertRedirect($this->inicio($a));
        $this->entrarComoEquipe($membro, $b)->assertRedirect($this->inicio($b));

        // A aba da primeira continua na primeira — era aqui que ela virava a segunda.
        $this->get($this->inicio($a))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('empresa.nome', $a->name));
        $this->get($this->inicio($b))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('empresa.nome', $b->name));
    }

    #[Test]
    public function salvar_na_aba_antiga_grava_na_empresa_da_aba(): void
    {
        $a = $this->empresa('Grava A');
        $b = $this->empresa('Grava B');
        $membro = $this->admin();

        $this->entrarComoEquipe($membro, $a);
        $this->entrarComoEquipe($membro, $b);

        // A aba de A, aberta antes, salva DEPOIS de B ter sido aberta.
        $this->postJson(
            route('portal.auth.estrutura.produtos.familias.criar', [PortalContexto::PARAMETRO => $a->id]),
            ['nome' => 'Cadeiras']
        )->assertOk();

        $this->assertTrue(EstruturaFamilia::where('company_id', $a->id)->where('nome', 'Cadeiras')->exists());
        $this->assertFalse(EstruturaFamilia::where('company_id', $b->id)->exists());
    }

    #[Test]
    public function equipe_nao_abre_empresa_em_que_nao_entrou_e_as_outras_abas_seguem(): void
    {
        $a = $this->empresa('Entrou');
        $outra = $this->empresa('Nao entrou');
        $membro = $this->admin();

        $this->entrarComoEquipe($membro, $a);

        // Admin pode entrar em qualquer empresa — mas pela porta, que registra a visita.
        $this->get($this->inicio($outra))->assertForbidden();
        $this->postJson(
            route('portal.auth.estrutura.produtos.familias.criar', [PortalContexto::PARAMETRO => $outra->id]),
            ['nome' => 'Mesas']
        )->assertForbidden();
        $this->assertFalse(EstruturaFamilia::where('company_id', $outra->id)->exists());

        // A sessão não caiu por causa do número digitado.
        $this->get($this->inicio($a))->assertOk();
    }

    #[Test]
    public function sessao_de_equipe_de_antes_do_multi_abas_continua_valendo(): void
    {
        $a = $this->empresa('Antiga');
        $membro = $this->admin();

        // Como a sessão era gravada até hoje: sem a lista de empresas.
        $this->withSession([
            PortalContexto::SESSAO_EQUIPE => $membro->id,
            'portal_empresa_id'           => $a->id,
        ]);

        $this->get($this->inicio($a))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('empresa.nome', $a->name));

        // E a próxima entrada soma a ela, em vez de apagá-la.
        $b = $this->empresa('Nova');
        $this->entrarComoEquipe($membro, $b);
        $this->get($this->inicio($a))->assertOk();
        $this->get($this->inicio($b))->assertOk();
    }

    // ─── Cliente ────────────────────────────────────────────────────────

    #[Test]
    public function cliente_com_duas_empresas_usa_as_duas_em_abas(): void
    {
        $a = $this->empresa('Cliente A');
        $b = $this->empresa('Cliente B');
        $this->clienteDe($a, $b);

        $this->get($this->inicio($b))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('empresa.nome', $b->name));
        $this->get($this->inicio($a))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('empresa.nome', $a->name));
    }

    #[Test]
    public function cliente_nao_ve_empresa_de_outro_trocando_o_numero(): void
    {
        $minha = $this->empresa('Minha');
        $deOutro = $this->empresa('De outro');
        $this->clienteDe($minha);

        // Leitura: volta para a empresa dele, sem mostrar nada da outra.
        $this->get($this->inicio($deOutro))->assertRedirect($this->inicio($minha));

        // Escrita: recusada, e nada gravado na outra.
        $this->postJson(
            route('portal.auth.estrutura.produtos.familias.criar', [PortalContexto::PARAMETRO => $deOutro->id]),
            ['nome' => 'Invasao']
        )->assertForbidden();
        $this->assertFalse(EstruturaFamilia::where('company_id', $deOutro->id)->exists());
    }

    // ─── Endereços sem a empresa ────────────────────────────────────────

    #[Test]
    public function endereco_antigo_leva_ao_mesmo_caminho_na_empresa_da_sessao(): void
    {
        $a = $this->empresa('Legado');
        $this->entrarComoEquipe($this->admin(), $a);

        $this->get('/portal/ppa?plano=12')->assertRedirect(url("/portal/{$a->id}/ppa?plano=12"));
    }

    #[Test]
    public function escrita_por_endereco_antigo_nao_e_refeita(): void
    {
        $a = $this->empresa('Legado escrita');
        $this->entrarComoEquipe($this->admin(), $a);

        $this->post('/portal/estrutura/produtos/familias', ['nome' => 'Velha'])
            ->assertRedirect($this->inicio($a));

        $this->assertFalse(EstruturaFamilia::where('nome', 'Velha')->exists());
    }

    #[Test]
    public function endereco_com_zero_usa_a_sessao_e_a_pagina_ganha_o_endereco_real(): void
    {
        $a = $this->empresa('Zero');
        $this->entrarComoEquipe($this->admin(), $a);

        $this->get('/portal/0/inicio')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('empresa.nome', $a->name)
                ->url("/portal/{$a->id}/inicio"));
    }

    // ─── Links da aba ───────────────────────────────────────────────────

    #[Test]
    public function os_links_gerados_na_aba_levam_a_empresa_dela(): void
    {
        $a = $this->empresa('Links A');
        $b = $this->empresa('Links B');
        $membro = $this->admin();
        $this->entrarComoEquipe($membro, $a);
        $this->entrarComoEquipe($membro, $b);

        // O Ziggy da página (`@routes`) nasce com a empresa da aba como padrão:
        // é ele que monta todos os links e formulários do portal no navegador.
        //
        // O Ziggy imprime a configuração completa (com os padrões) só na
        // PRIMEIRA página do processo e guarda isso numa estática; no teste as
        // páginas de antes ficaram no mesmo processo. Em produção cada
        // requisição começa do zero.
        \Tighten\Ziggy\BladeRouteGenerator::$generated = false;

        $this->get($this->inicio($a))
            ->assertOk()
            ->assertSee('"'.PortalContexto::PARAMETRO.'":"'.$a->id.'"', false);
    }

    #[Test]
    public function o_dominio_do_cliente_libera_o_caminho_com_empresa_e_so_ele(): void
    {
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/463/inicio'));
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/0/inicio'));
        // Endereço antigo continua liberado (vira redirecionamento).
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/inicio'));
        // Tirar o número não abre o que já era fechado.
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/463/usuarios'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/abc/inicio'));
    }
}
