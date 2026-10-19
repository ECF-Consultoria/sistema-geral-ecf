<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\PortalUsuario;
use App\Models\Servico;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A aba "Portal do cliente" de `/companies` (19/10/2026).
 *
 * Antes a equipe só entrava no portal pelo menu de uma linha do Onboarding —
 * empresa sem onboarding não tinha porta pela tela. A aba nova lista as
 * empresas com a entrada de cada uma.
 *
 * O que estes testes protegem:
 *
 *  - **a lista é a régua de `PortalEquipeService::podeEntrar()`**: toda
 *    empresa listada abre (o teste clica em todas), e a de fora da carteira
 *    nem aparece para quem não é admin;
 *  - **a contagem de logins não mente**: desativado não conta, e quem nunca
 *    entrou é contado à parte;
 *  - **sair do portal devolve à aba de onde a pessoa entrou**.
 */
class AbaPortalEmCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    /** @param  array<int,string>  $permissions */
    private function usuarioCom(array $permissions): User
    {
        $user = User::create([
            'name' => 'Nao Admin '.uniqid(), 'email' => 'naoadmin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor '.uniqid(), 'slug' => 'setor-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permissions as $key) {
            DB::table('setor_permissoes')->insert([
                'setor_id' => $setorId, 'permission_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Empresa que aparece em `/companies`: só Performance com contrato ativo. */
    private function empresa(string $nome = 'Empresa'): Company
    {
        $empresa = Company::create([
            'name' => $nome.' '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);

        ContratoServico::create([
            'company_id' => $empresa->id, 'servico_id' => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500, 'data_contratacao' => now()->toDateString(), 'ativo' => true,
        ]);

        return $empresa;
    }

    private function naCarteira(User $user, Company $empresa): void
    {
        DB::table('company_users')->insert([
            'company_id' => $empresa->id, 'user_id' => $user->id, 'role' => 'consultor',
            'servico_id' => $this->servicoDeGestao()->id, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loginDoCliente(Company $empresa, bool $ativo = true, ?string $ultimoAcesso = null): PortalUsuario
    {
        $pessoa = PortalUsuario::create([
            'nome' => 'Cliente '.uniqid(), 'email' => 'c.'.uniqid().'@empresa.test', 'ativo' => $ativo,
        ]);
        $pessoa->forceFill([
            'primeiro_acesso_em' => $ultimoAcesso,
            'ultimo_acesso_em'   => $ultimoAcesso,
        ])->save();
        $pessoa->empresas()->attach($empresa->id, ['principal' => true]);

        return $pessoa;
    }

    /** @return array<int, array<string,mixed>> */
    private function abaPortal(User $quem): array
    {
        $linhas = null;

        $this->actingAs($quem)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$linhas) {
                $linhas = $page->toArray()['props']['portal_empresas'];
            });

        return $linhas;
    }

    // ─── Quem aparece ───────────────────────────────────────────────────

    #[Test]
    public function admin_ve_todas_as_empresas_da_pagina(): void
    {
        $a = $this->empresa('Alfa');
        $b = $this->empresa('Beta');

        $this->actingAs($this->admin())
            ->get(route('companies.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pode_ver_portal', true));

        $ids = array_column($this->abaPortal($this->admin()), 'id');
        sort($ids);

        $this->assertSame([$a->id, $b->id], $ids);
    }

    /**
     * A empresa sem onboarding é exatamente a que ficava sem porta — a aba
     * existe por causa dela.
     */
    #[Test]
    public function empresa_sem_onboarding_tambem_aparece(): void
    {
        $empresa = $this->empresa();
        DB::table('onboardings')->where('company_id', $empresa->id)->delete();

        $this->assertContains($empresa->id, array_column($this->abaPortal($this->admin()), 'id'));
    }

    #[Test]
    public function nao_admin_ve_so_a_propria_carteira(): void
    {
        $minha = $this->empresa('Minha');
        $deOutro = $this->empresa('De outro');

        $analista = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::CORE_ONBOARDING]);
        $this->naCarteira($analista, $minha);

        $ids = array_column($this->abaPortal($analista), 'id');

        $this->assertSame([$minha->id], $ids);
        $this->assertNotContains($deOutro->id, $ids);
    }

    /**
     * A régua da LISTA e a da ROTA são a mesma: toda empresa listada abre.
     * Se alguém alargar a lista sem mexer em `podeEntrar()`, a pessoa veria
     * uma empresa e levaria 403 ao clicar — é isso que este teste pega.
     */
    #[Test]
    public function toda_empresa_listada_abre_pela_porta_da_equipe(): void
    {
        $analista = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::CORE_ONBOARDING]);
        foreach (['Um', 'Dois'] as $nome) {
            $this->naCarteira($analista, $this->empresa($nome));
        }
        $this->empresa('Fora da carteira');

        $linhas = $this->abaPortal($analista);
        $this->assertCount(2, $linhas);

        foreach ($linhas as $linha) {
            $this->actingAs($analista)
                ->get(route('companies.portal.abrir', $linha['id']))
                ->assertRedirect();
        }
    }

    #[Test]
    public function sem_core_onboarding_a_aba_nao_existe(): void
    {
        $empresa = $this->empresa();
        $usuario = $this->usuarioCom([Permissions::CORE_EMPRESAS]);
        $this->naCarteira($usuario, $empresa);

        $this->actingAs($usuario)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pode_ver_portal', false)
                ->where('portal_empresas', []));
    }

    // ─── Logins do cliente ──────────────────────────────────────────────

    #[Test]
    public function logins_contam_so_os_ativos_e_separam_quem_nunca_entrou(): void
    {
        $comLogin = $this->empresa('Com login');
        $semLogin = $this->empresa('Sem login');

        $this->loginDoCliente($comLogin, ultimoAcesso: '2026-10-10 08:00:00');
        $this->loginDoCliente($comLogin, ultimoAcesso: '2026-10-14 09:12:00');
        $this->loginDoCliente($comLogin);                                      // convidado, nunca entrou
        $this->loginDoCliente($comLogin, ativo: false, ultimoAcesso: '2026-10-18 10:00:00'); // desativado

        $linhas = collect($this->abaPortal($this->admin()))->keyBy('id');

        $this->assertSame(
            ['logins' => 3, 'nunca_entrou' => 1, 'ultimo_login' => '14/10/2026 09:12'],
            $linhas[$comLogin->id]['logins'],
            'O desativado não pode contar nem empurrar a data do último login.'
        );
        $this->assertSame(
            ['logins' => 0, 'nunca_entrou' => 0, 'ultimo_login' => null],
            $linhas[$semLogin->id]['logins']
        );
    }

    // ─── Endereços que mudaram de lugar ─────────────────────────────────

    /**
     * Com domínio separado, sair do portal devolve a pessoa ao admin — à aba
     * de onde ela entrou, e não mais ao cockpit de onboardings.
     */
    #[Test]
    public function sair_do_portal_volta_para_a_aba_portal(): void
    {
        config(['portal.dominio_cliente' => 'cliente.ecf.test', 'app.url' => 'http://admin.ecf.test']);

        $destino = $this->post('http://cliente.ecf.test/equipe/sair')->headers->get('Location');

        $this->assertSame('http://admin.ecf.test/companies?tab=portal', $destino);
    }
}
