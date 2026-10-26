<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PortalUsuario;
use App\Models\Servico;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 *  - **só admin** vê as linhas de Polos e libera;
 *  - **o contato sugerido é do CLIENTE**: vem da conta do ML conectada ou do
 *    ECF Drive, e e-mail de conta da ECF nunca entra (o código de login iria
 *    para a ECF); falha das fontes não impede liberar.
 */
class PolosNoPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        Cache::flush();
        // Nenhuma chamada de verdade: cada teste diz o que o ML e o ECF Drive respondem.
        Http::preventStrayRequests();
    }

    /**
     * Fakes do Mercado Livre (`/users/me`) e do ECF Drive (`/clientes/{cust}`).
     * Uma chamada só por teste — `Http::fake()` ACUMULA, e o primeiro padrão
     * registrado vence.
     */
    private function fontes(?array $usuarioMl = null, ?array $clienteEcf = null, int $statusMl = 200): void
    {
        Http::fake([
            'api.mercadolibre.com/users/me*' => Http::response($usuarioMl ?? [], $usuarioMl === null ? 404 : $statusMl),
            '*/clientes/*' => $clienteEcf === null
                ? Http::response(['message' => 'não encontrado'], 404)
                : Http::response($clienteEcf, 200),
        ]);
    }

    private function contaMlConectada(MlbEmpresa $empresa): void
    {
        MlToken::create([
            'company_id'     => null,
            'mlb_empresa_id' => $empresa->id,
            'ml_user_id'     => '465723451',
            'access_token'   => 'APP_USR-x',
            'refresh_token'  => 'TG-x',
            'expires_at'     => now()->addHours(5),
            'status'         => 'active',
        ]);
    }

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
        $this->fontes();
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
        $this->fontes();
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

    // ─── Contato do cliente ─────────────────────────────────────────────

    #[Test]
    public function contato_vem_da_conta_do_mercado_livre(): void
    {
        $polo = $this->polo('Loja ML');
        $this->contaMlConectada($polo);
        $this->fontes([
            'id' => 465723451, 'first_name' => 'Maria', 'last_name' => 'Souza',
            'email' => 'Maria@LojaML.com.br', 'phone' => ['area_code' => '11', 'number' => '987654321'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('portal.polos.liberar', $polo))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'conta do Mercado Livre'));

        $company = Company::findOrFail($polo->fresh()->company_id);
        $this->assertSame('maria@lojaml.com.br', $company->email_cliente);
        $this->assertSame('(11) 98765-4321', $company->telefone);
        $this->assertSame('Maria Souza', $company->nome_contato);
        // E-mail e telefone já vieram do ML: o ECF Drive nem é consultado.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/clientes/'));
    }

    #[Test]
    public function email_de_conta_da_ecf_nunca_vira_contato_e_o_ecf_drive_completa(): void
    {
        $polo = $this->polo('Loja ECF', ['gmail' => 'lojaecf@gmail.com', 'cust_id' => '777000111']);
        $this->contaMlConectada($polo);
        // A conta do ML foi aberta com o e-mail da ECF: não serve para o login do cliente.
        $this->fontes(
            ['first_name' => 'Joao', 'last_name' => 'Lima', 'email' => 'lojaecf@gmail.com', 'phone' => []],
            ['custId' => '777000111', 'email' => 'dono@cliente.com.br', 'telefone' => '+55 41 3333-4444'],
        );

        $this->actingAs($this->admin())
            ->post(route('portal.polos.liberar', $polo))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ECF Drive'));

        $company = Company::findOrFail($polo->fresh()->company_id);
        $this->assertSame('dono@cliente.com.br', $company->email_cliente);
        $this->assertSame('(41) 3333-4444', $company->telefone);
        $this->assertSame('Joao Lima', $company->nome_contato);
    }

    #[Test]
    public function dominios_da_ecf_e_alias_do_ml_sao_descartados(): void
    {
        $admin = $this->admin();

        foreach (['loja@ecfconsultoria.com.br', 'loja@polosmoveis.com.br', 'x123@mail.mercadolivre.com'] as $i => $email) {
            $polo = $this->polo("Descarte {$i}");
            $this->contaMlConectada($polo);
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::preventStrayRequests();
            $this->fontes(['first_name' => 'Ana', 'email' => $email]);

            $this->actingAs($admin)->post(route('portal.polos.liberar', $polo));

            $this->assertNull(Company::findOrFail($polo->fresh()->company_id)->email_cliente, $email);
        }
    }

    #[Test]
    public function sem_fonte_libera_e_avisa_para_digitar(): void
    {
        $polo = $this->polo('Sem Fonte');
        $this->fontes();

        $this->actingAs($this->admin())
            ->post(route('portal.polos.liberar', $polo))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'digite à mão'));

        $this->assertNull(Company::findOrFail($polo->fresh()->company_id)->email_cliente);
    }

    #[Test]
    public function falha_do_mercado_livre_nao_impede_liberar(): void
    {
        $polo = $this->polo('ML Fora');
        $this->contaMlConectada($polo);
        $this->fontes(['error' => 'internal'], null, 500);

        $this->actingAs($this->admin())->post(route('portal.polos.liberar', $polo))->assertRedirect();

        $this->assertNotNull($polo->fresh()->company_id);
    }

    #[Test]
    public function polo_ja_liberada_completa_so_o_contato_vazio(): void
    {
        $cadastro = $this->empresa('Ja Liberada', ['email_cliente' => 'digitado@cliente.com.br']);
        $polo = $this->polo('Ja Liberada', ['company_id' => $cadastro->id]);
        $this->contaMlConectada($polo);
        $this->fontes([
            'first_name' => 'Pedro', 'last_name' => 'Alves',
            'email' => 'outro@cliente.com.br', 'phone' => ['area_code' => '47', 'number' => '991112222'],
        ]);

        $this->actingAs($this->admin())->post(route('portal.polos.liberar', $polo))->assertRedirect();

        $cadastro->refresh();
        // O que alguém digitou fica; só o vazio é completado.
        $this->assertSame('digitado@cliente.com.br', $cadastro->email_cliente);
        $this->assertSame('(47) 99111-2222', $cadastro->telefone);
        $this->assertSame('Pedro Alves', $cadastro->nome_contato);
    }

    #[Test]
    public function o_dar_acesso_recebe_o_contato_sugerido(): void
    {
        $polo = $this->polo('Sugestao');
        $this->contaMlConectada($polo);
        $this->fontes(['first_name' => 'Carla', 'last_name' => 'Dias', 'email' => 'carla@sugestao.com.br']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portal.polos.liberar', $polo));
        $companyId = $polo->fresh()->company_id;

        $sugestao = null;
        $this->actingAs($admin)
            ->get(route('companies.index', ['tab' => 'portal', 'sub' => 'acessos']))
            ->assertInertia(function (AssertableInertia $page) use (&$sugestao, $companyId) {
                $page->reloadOnly('portal_acessos', function (AssertableInertia $r) use (&$sugestao, $companyId) {
                    $sugestao = collect($r->toArray()['props']['portal_acessos']['empresas'])->firstWhere('id', $companyId)['contato'] ?? null;
                });
            });

        $this->assertSame('carla@sugestao.com.br', $sugestao['email']);
        $this->assertSame('Carla Dias', $sugestao['nome']);
    }

    // ─── O cadastro do portal não suja outras telas ─────────────────────

    #[Test]
    public function cadastro_do_portal_fica_fora_do_comercial_e_da_aba_empresas(): void
    {
        $this->fontes();
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
