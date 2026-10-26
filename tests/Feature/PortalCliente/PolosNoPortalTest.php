<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
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
 * Empresas dos Polos na aba "Portal do cliente" de `/companies` (26/10/2026).
 *
 * O portal é todo por `Company`, e as empresas dos Polos moram em
 * `mlb_empresas` — quase nenhuma tem cadastro. A aba passa a listá-las para o
 * admin, e "Liberar portal" cria o cadastro mínimo na hora.
 *
 * O que estes testes protegem:
 *
 *  - **o recorte**: ativas do projeto Polos, sem Churn — arquivada, Churn e
 *    outro projeto ficam fora da lista E da rota;
 *  - **o cadastro nasce mínimo**: sem Cust ID (senão o sync da Adman passa a
 *    puxar a conta), sem contrato, sem etapa, sem "empresa nova";
 *  - **liberar é idempotente**: dois cliques não criam dois cadastros;
 *  - **o cadastro do portal não suja** a aba Empresas de `/companies` nem a
 *    listagem "Cadastro de Empresas" do Comercial;
 *  - **só admin** vê as linhas de Polos e libera.
 */
class PolosNoPortalTest extends TestCase
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

    private function polo(string $nome, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'cust_id' => (string) random_int(100000000, 999999999),
        ], $opts));
    }

    private function empresa(string $nome, array $opts = []): Company
    {
        return Company::create(array_merge([
            'name' => $nome.' '.uniqid(),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ], $opts));
    }

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
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

    private function linhasPolos(User $quem): \Illuminate\Support\Collection
    {
        return collect($this->abaPortal($quem))->filter(fn ($l) => $l['polos'] !== null)->values();
    }

    // ─── A lista ────────────────────────────────────────────────────────

    #[Test]
    public function admin_ve_as_polos_ativas_sem_churn(): void
    {
        $beta  = $this->polo('Beta');
        $alfa  = $this->polo('Alfa', ['projeto' => null, 'fase' => 'M1']);
        $delta = $this->polo('Delta', ['fase' => 'Protocolo Churn']);
        $semFase = $this->polo('Epsilon', ['fase' => null]);
        $this->polo('Arquivada', ['arquivado_em' => now()]);
        $this->polo('Assessoria', ['projeto' => 'Assessoria', 'fase' => 'ASSESSORIA']);
        $this->polo('Churn', ['fase' => 'Churn']);
        $this->polo('Churn caixa', ['fase' => ' churn ']);

        $ids = $this->linhasPolos($this->admin())->pluck('mlb_empresa_id')->all();

        $this->assertSame([$alfa->id, $beta->id, $delta->id, $semFase->id], $ids);
    }

    #[Test]
    public function polo_com_cadastro_vem_com_logins_e_sem_cadastro_vem_sem_id(): void
    {
        $cadastro = $this->empresa('Com cadastro');
        $comCadastro = $this->polo('Com cadastro', ['company_id' => $cadastro->id]);
        $semCadastro = $this->polo('Sem cadastro');

        $pessoa = PortalUsuario::create(['nome' => 'Cliente', 'email' => 'c.'.uniqid().'@x.test', 'ativo' => true]);
        $pessoa->empresas()->attach($cadastro->id, ['principal' => true]);

        $linhas = $this->linhasPolos($this->admin())->keyBy('mlb_empresa_id');

        $a = $linhas[$comCadastro->id];
        $this->assertSame($cadastro->id, $a['id']);
        $this->assertSame("company-{$cadastro->id}", $a['chave']);
        $this->assertSame(1, $a['logins']['logins']);
        $this->assertSame('M2', $a['polos']['fase']);
        $this->assertSame('Arapongas', $a['polos']['regiao']);

        $b = $linhas[$semCadastro->id];
        $this->assertNull($b['id']);
        $this->assertSame("polos-{$semCadastro->id}", $b['chave']);
        $this->assertSame(0, $b['logins']['logins']);
    }

    #[Test]
    public function polo_com_cadastro_listado_abre_pela_porta_da_equipe(): void
    {
        $cadastro = $this->empresa('Abre');
        $this->polo('Abre', ['company_id' => $cadastro->id]);
        $admin = $this->admin();

        foreach ($this->linhasPolos($admin)->whereNotNull('id') as $linha) {
            $this->actingAs($admin)
                ->get(route('companies.portal.abrir', $linha['id']))
                ->assertRedirect();
        }
    }

    #[Test]
    public function nao_admin_nao_ve_as_polos(): void
    {
        $this->polo('Alfa');
        $equipe = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::CORE_ONBOARDING, 'mlb.projetos']);

        $this->assertCount(0, $this->linhasPolos($equipe));
    }

    // ─── Liberar portal ─────────────────────────────────────────────────

    #[Test]
    public function liberar_cria_cadastro_minimo_e_leva_ao_dar_acesso(): void
    {
        $polo = $this->polo('Moveis Liberados', ['cust_id' => '123456789']);
        MlbImplementacao::create([
            'empresa_id' => $polo->id, 'token' => 'tok-'.uniqid(), 'nome_contato' => 'Joana Cliente',
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('portal.polos.liberar', $polo));

        $polo->refresh();
        $this->assertNotNull($polo->company_id);
        $company = Company::findOrFail($polo->company_id);

        $resposta->assertRedirect(route('companies.index', [
            'tab' => 'portal', 'sub' => 'acessos', 'portal_company' => $company->id,
        ]));

        $this->assertSame('Moveis Liberados', $company->name);
        $this->assertSame('Joana Cliente', $company->nome_contato);
        $this->assertTrue((bool) $company->active);
        $this->assertFalse((bool) $company->empresa_nova);
        $this->assertNull($company->etapa);
        // Sem Cust: o sync da Adman e o faturamento só pegam Company com cust.
        $this->assertNull($company->adman_account_id);
        $this->assertNull($company->ml_store_id);
        $this->assertSame(0, $company->contratosServico()->count());

        // Já entra na lista do "Dar acesso" e a linha passa a ter id.
        $linha = $this->linhasPolos($this->admin())->firstWhere('mlb_empresa_id', $polo->id);
        $this->assertSame($company->id, $linha['id']);
    }

    #[Test]
    public function liberar_duas_vezes_nao_cria_dois_cadastros(): void
    {
        $polo = $this->polo('Duplo');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portal.polos.liberar', $polo))->assertRedirect();
        $antes = Company::count();
        $this->actingAs($admin)->post(route('portal.polos.liberar', $polo))->assertRedirect();

        $this->assertSame($antes, Company::count());
    }

    #[Test]
    public function liberar_fora_do_recorte_da_404(): void
    {
        $admin = $this->admin();

        foreach ([
            $this->polo('Arquivada', ['arquivado_em' => now()]),
            $this->polo('Churn', ['fase' => 'Churn']),
            $this->polo('Assessoria', ['projeto' => 'Assessoria', 'fase' => 'ASSESSORIA']),
        ] as $fora) {
            $this->actingAs($admin)->post(route('portal.polos.liberar', $fora))->assertNotFound();
            $this->assertNull($fora->fresh()->company_id);
        }
    }

    #[Test]
    public function so_admin_libera(): void
    {
        $polo = $this->polo('Alfa');
        $equipe = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::CORE_ONBOARDING, 'mlb.projetos']);

        $this->actingAs($equipe)->post(route('portal.polos.liberar', $polo))->assertForbidden();
        $this->assertNull($polo->fresh()->company_id);
    }

    // ─── O cadastro do portal não suja outras telas ─────────────────────

    #[Test]
    public function cadastro_do_portal_fica_fora_do_comercial_e_da_aba_empresas(): void
    {
        $polo = $this->polo('So Portal');
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('portal.polos.liberar', $polo));
        $doPortal = $polo->fresh()->company_id;

        // Empresa do HubSpot ainda sem contrato CONTINUA na fila do Comercial.
        $semContrato = $this->empresa('HubSpot sem contrato');
        // Empresa Polos que veio do Comercial (com contrato) também continua.
        $comContrato = $this->empresa('Polos do Comercial');
        ContratoServico::create([
            'company_id' => $comContrato->id, 'servico_id' => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500, 'data_contratacao' => now()->toDateString(), 'ativo' => false,
        ]);
        $this->polo('Polos do Comercial', ['company_id' => $comContrato->id]);

        $idsComercial = Company::query()->where('active', true)->excetoCadastroDoPortal()->pluck('id')->all();
        $this->assertNotContains($doPortal, $idsComercial);
        $this->assertContains($semContrato->id, $idsComercial);
        $this->assertContains($comContrato->id, $idsComercial);

        // E a tela do Comercial usa o mesmo recorte.
        $this->actingAs($admin)
            ->get(route('comercial.empresas.listagem'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($doPortal, $semContrato) {
                $ids = collect($page->toArray()['props']['companies']['data'] ?? [])->pluck('id')->all();
                $this->assertNotContains($doPortal, $ids);
                $this->assertContains($semContrato->id, $ids);
            });

        // Aba Empresas de /companies: continua sem empresa ligada a Polos.
        $this->actingAs($admin)
            ->get(route('companies.index'))
            ->assertInertia(function (AssertableInertia $page) use ($doPortal) {
                $this->assertNotContains($doPortal, array_column($page->toArray()['props']['companies'], 'id'));
            });
    }
}
