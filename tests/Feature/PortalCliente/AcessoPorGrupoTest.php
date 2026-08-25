<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dar acesso ao portal por GRUPO de empresas.
 *
 * Empresa de grupo é o caso comum na carteira: em produção, 45 das 197 empresas
 * ativas pertencem a um dos 15 grupos, e o maior deles tem 7 (medido em
 * 24/08/2026). Dar acesso a alguém do Camillo Parts normalmente significa as 7
 * — sem esta opção, o operador repetiria a mesma operação sete vezes e
 * esqueceria a sétima.
 */
class AcessoPorGrupoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function empresa(string $nome, ?CompanyGroup $grupo = null, bool $ativa = true): Company
    {
        return Company::create([
            'name' => $nome.' '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => $ativa, 'status' => $ativa ? 'ativo' : 'inativo', 'empresa_nova' => false,
            'company_group_id' => $grupo?->id,
        ]);
    }

    /**
     * Os headers de um partial reload.
     *
     * A versão vem do próprio middleware — `Inertia::getVersion()` devolveria
     * vazio, porque quem a define é o `handle()`, que ainda não rodou. Versão
     * errada não dá 200 com dado faltando: dá **409**, e o teste morre antes de
     * chegar na asserção.
     */
    private function cabecalhosDoPartial(string $props = 'portal_acessos'): array
    {
        return [
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => $props,
            'X-Inertia-Partial-Component' => 'Companies/Index',
        ];
    }

    /** Não-admin com `core.empresas` — o perfil que chega em /companies. */
    private function usuarioCom(array $permissions): User
    {
        $user = User::create([
            'name' => 'Nao Admin '.uniqid(), 'email' => 'naoadmin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor Teste '.uniqid(), 'slug' => 'setor-teste-'.uniqid(),
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

    #[Test]
    public function dar_acesso_a_um_grupo_vincula_todas_as_empresas_dele(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $this->empresa('Camillo A', $grupo);
        $this->empresa('Camillo B', $grupo);
        $this->empresa('Camillo C', $grupo);
        $this->empresa('Outra sem grupo');

        $this->actingAs($this->admin())
            ->post(route('portal.usuarios.store'), [
                'nome' => 'Gestor do Grupo',
                'email' => 'gestor@camillo.test',
                'company_group_id' => $grupo->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $usuario = PortalUsuario::where('email', 'gestor@camillo.test')->first();

        $this->assertNotNull($usuario);
        $this->assertSame(3, $usuario->empresas()->count());
    }

    /**
     * Empresa inativa do grupo NÃO entra: ela não deve aparecer no portal de
     * ninguém, e incluí-la daria acesso a algo que a própria ECF já encerrou.
     */
    #[Test]
    public function empresa_inativa_do_grupo_fica_de_fora(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Misto']);
        $this->empresa('Ativa 1', $grupo);
        $this->empresa('Ativa 2', $grupo);
        $this->empresa('Encerrada', $grupo, ativa: false);

        $this->actingAs($this->admin())
            ->post(route('portal.usuarios.store'), [
                'nome' => 'Pessoa', 'email' => 'p@grupo.test', 'company_group_id' => $grupo->id,
            ]);

        $usuario = PortalUsuario::where('email', 'p@grupo.test')->first();

        $this->assertSame(2, $usuario->empresas()->count());
    }

    #[Test]
    public function dar_acesso_a_uma_empresa_so_continua_funcionando(): void
    {
        $empresa = $this->empresa('Sozinha');

        $this->actingAs($this->admin())
            ->post(route('portal.usuarios.store'), [
                'nome' => 'Pessoa', 'email' => 'p2@empresa.test', 'company_id' => $empresa->id,
            ])
            ->assertSessionHasNoErrors();

        $usuario = PortalUsuario::where('email', 'p2@empresa.test')->first();

        $this->assertSame(1, $usuario->empresas()->count());
        $this->assertTrue($usuario->podeVer($empresa->id));
    }

    /** Vincular um grupo a quem já tem uma empresa dele não pode estourar. */
    #[Test]
    public function vincular_grupo_a_quem_ja_tem_uma_empresa_dele_nao_duplica(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo X']);
        $a = $this->empresa('X A', $grupo);
        $this->empresa('X B', $grupo);

        $usuario = PortalUsuario::create(['nome' => 'P', 'email' => 'p3@x.test', 'ativo' => true]);
        $usuario->empresas()->attach($a->id, ['principal' => true]);

        $this->actingAs($this->admin())
            ->post(route('portal.usuarios.vincular', $usuario), ['company_group_id' => $grupo->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $usuario->fresh()->empresas()->count());
    }

    #[Test]
    public function sem_empresa_nem_grupo_o_cadastro_e_recusado(): void
    {
        $this->actingAs($this->admin())
            ->post(route('portal.usuarios.store'), ['nome' => 'P', 'email' => 'p4@x.test'])
            ->assertSessionHasErrors();

        $this->assertNull(PortalUsuario::where('email', 'p4@x.test')->first());
    }

    /**
     * A tela mudou de casa: vive na sub-aba "Acessos do portal" de
     * `/companies`. A lista é prop `optional` — só vem em partial reload,
     * que é o que este teste imita.
     *
     * As asserções são de JSON, e não `assertInertia`: aquele helper faz
     * `assertViewHas('page')`, ou seja, só entende resposta HTML. Partial
     * reload devolve JSON puro e ele falha com "Not a valid Inertia
     * response" — que soa como bug do código, mas é limitação do helper.
     */
    #[Test]
    public function a_sub_aba_recebe_os_grupos_e_o_grupo_de_cada_empresa(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Visível']);
        $this->empresa('Do grupo A', $grupo);
        $this->empresa('Do grupo B', $grupo);
        $this->empresa('Avulsa');

        $this->actingAs($this->admin())
            ->withHeaders($this->cabecalhosDoPartial())
            ->get(route('companies.index'))
            ->assertOk()
            ->assertJsonPath('component', 'Companies/Index')
            ->assertJsonCount(1, 'props.portal_acessos.grupos')
            ->assertJsonPath('props.portal_acessos.grupos.0.nome', 'Grupo Visível')
            ->assertJsonPath('props.portal_acessos.grupos.0.empresas', 2)
            ->assertJsonCount(3, 'props.portal_acessos.empresas');
    }

    /** Grupo sem nenhuma empresa ativa não aparece — seria um alvo vazio. */
    #[Test]
    public function grupo_sem_empresa_ativa_nao_aparece_no_seletor(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Vazio']);
        $this->empresa('Só inativa', $grupo, ativa: false);

        $this->actingAs($this->admin())
            ->withHeaders($this->cabecalhosDoPartial())
            ->get(route('companies.index'))
            ->assertOk()
            ->assertJsonCount(0, 'props.portal_acessos.grupos');
    }

    /**
     * A lista NÃO vem na carga normal de /companies. É o que separa "a tela
     * mudou de lugar" de "a tela mudou de lugar e ficou cara": quem abre
     * /companies para ver empresas não paga as consultas do portal.
     */
    #[Test]
    public function a_lista_nao_vem_na_carga_normal_da_pagina(): void
    {
        $this->empresa('Qualquer uma');

        $this->actingAs($this->admin())
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pode_gerir_portal', true)
                ->has('portal_acessos_total')
                ->missing('portal_acessos')
            );
    }

    /** Quem não é admin não vê a sub-aba — as escritas dela são `role:admin`. */
    #[Test]
    public function nao_admin_nao_recebe_a_sub_aba(): void
    {
        $consultor = $this->usuarioCom([Permissions::CORE_EMPRESAS]);

        $this->actingAs($consultor)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pode_gerir_portal', false));
    }

    /** O endereço antigo continua levando a pessoa ao lugar certo. */
    #[Test]
    public function o_endereco_antigo_redireciona_para_a_sub_aba(): void
    {
        $this->actingAs($this->admin())
            ->get(route('portal.usuarios.index'))
            ->assertRedirect(route('companies.index', ['tab' => 'onboarding', 'sub' => 'acessos']));
    }
}