<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A aba Onboarding de `/companies` respeita a MESMA régua de visibilidade do
 * painel `/onboarding` — e não a de `/companies`.
 *
 * Por que este teste existe: as duas telas tinham réguas diferentes. A
 * listagem mostra todas as empresas a quem tem `core.empresas`; o painel
 * restringe não-admin à própria carteira. Ao trazer o bloco de onboarding para
 * a listagem sem repetir essa régua, o acesso alargou em silêncio — e o que
 * vai passar a aparecer ali (investimento do cliente, telefone e e-mail dos
 * participantes das reuniões) é mais sensível do que a lista de empresas.
 */
class OnboardingEscopoEGateTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function criarEmpresa(): Company
    {
        return Company::create([
            'name'              => 'Empresa Escopo '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => 'colab.'.uniqid().'@ecf.test',
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);
    }

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
        $company = $this->criarEmpresa();

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        return [$company, Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail()];
    }

    /**
     * Cria um usuário não-admin com as permissions pedidas, via setor.
     *
     * @param  array<int,string>  $permissions
     */
    private function usuarioCom(array $permissions): User
    {
        $user = User::create([
            'name'     => 'Nao Admin '.uniqid(),
            'email'    => 'naoadmin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Setor Teste '.uniqid(),
            'slug'       => 'setor-teste-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($permissions as $key) {
            DB::table('setor_permissoes')->insert([
                'setor_id'       => $setorId,
                'permission_key' => $key,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        DB::table('user_setores')->insert([
            'user_id'    => $user->id,
            'setor_id'   => $setorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function vincularNaCarteira(Company $company, User $user): void
    {
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $this->servicoDeGestao()->id,
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function linhaDa(array $companies, int $id): ?array
    {
        foreach ($companies as $l) {
            if ($l['id'] === $id) {
                return $l;
            }
        }

        return null;
    }

    /** @test */
    public function sem_core_onboarding_a_empresa_aparece_mas_sem_nada_de_onboarding(): void
    {
        [$company] = $this->empresaComOnboarding();

        $user = $this->usuarioCom([Permissions::CORE_EMPRESAS]);
        $this->vincularNaCarteira($company, $user);
        $this->actingAs($user);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company) {
                $props = $page->toArray()['props'];
                $linha = $this->linhaDa($props['companies'], $company->id);

                // A empresa não é segredo — a linha continua na listagem.
                $this->assertNotNull($linha);
                // O onboarding é.
                $this->assertSame([], $linha['onboardings']);
                $this->assertNull($linha['onboarding_resumo']);
                $this->assertFalse($props['pode_ver_onboarding']);
            });
    }

    /** @test */
    public function com_core_onboarding_ve_o_onboarding_da_propria_carteira(): void
    {
        [$company] = $this->empresaComOnboarding();

        $user = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::CORE_ONBOARDING]);
        $this->vincularNaCarteira($company, $user);
        $this->actingAs($user);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company) {
                $props = $page->toArray()['props'];
                $linha = $this->linhaDa($props['companies'], $company->id);

                $this->assertTrue($props['pode_ver_onboarding']);
                $this->assertCount(1, $linha['onboardings']);
                $this->assertNotNull($linha['onboarding_resumo']);
            });
    }

    /**
     * O caso que motivou o teste: mesmo com as duas permissions, empresa FORA
     * da carteira não expõe onboarding para não-admin — é a régua que
     * `OnboardingController::index()` aplica desde a Fase 135.
     *
     * @test
     */
    public function empresa_fora_da_carteira_nao_expoe_onboarding_para_nao_admin(): void
    {
        [$minha] = $this->empresaComOnboarding();
        [$deOutro] = $this->empresaComOnboarding();

        $user = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::CORE_ONBOARDING]);
        $this->vincularNaCarteira($minha, $user);
        $this->actingAs($user);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($minha, $deOutro) {
                $companies = $page->toArray()['props']['companies'];

                $daCarteira = $this->linhaDa($companies, $minha->id);
                $this->assertCount(1, $daCarteira['onboardings']);

                $foraDaCarteira = $this->linhaDa($companies, $deOutro->id);
                $this->assertNotNull($foraDaCarteira, 'A empresa some da listagem — não era esse o objetivo.');
                $this->assertSame([], $foraDaCarteira['onboardings']);
                $this->assertNull($foraDaCarteira['onboarding_resumo']);
            });
    }

    /** Admin continua vendo tudo — o short-circuit de `hasPermission` vale aqui também. */
    /** @test */
    public function admin_ve_o_onboarding_de_qualquer_empresa(): void
    {
        [$company] = $this->empresaComOnboarding();

        $admin = User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($company) {
                $props = $page->toArray()['props'];
                $this->assertTrue($props['pode_ver_onboarding']);
                $this->assertCount(1, $this->linhaDa($props['companies'], $company->id)['onboardings']);
            });
    }

    /**
     * O que o §3 do PDF pede: o que o Comercial já coletou não é perguntado de
     * novo. SPIN e contexto chegam ao detalhe sem coluna nova.
     *
     * @test
     */
    public function detalhe_carrega_spin_e_contexto_vindos_do_comercial(): void
    {
        [$company, $onboarding] = $this->empresaComOnboarding();

        $company->update([
            'hubspot_observacao' => 'Cliente veio por indicação; opera só em ML.',
            'hubspot_snapshot'   => ['deal' => ['problema_principal_identificado' => 'Anúncios sem giro']],
        ]);

        $admin = User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        $this->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $onb = $page->toArray()['props']['onboarding'];

                $this->assertSame('Cliente veio por indicação; opera só em ML.', $onb['contexto']);
                $this->assertSame('Anúncios sem giro', $onb['spin']['problema']);
                // As 4 chaves do SPIN vêm sempre, null quando ausentes.
                $this->assertArrayHasKey('situacao', $onb['spin']);
                $this->assertNull($onb['spin']['situacao']);
            });
    }

    /**
     * `pronto_para_concluir` devolver false na v10 NÃO é bug — não existe mais
     * passo administrativo. Quem responde "pronta para operação" é o status
     * `concluido`, carimbado automaticamente quando o último passo fecha.
     *
     * @test
     */
    public function onboarding_da_v10_conclui_sozinho_sem_passar_por_pronto_para_concluir(): void
    {
        [, $onboarding] = $this->empresaComOnboarding();

        $engine = app(\App\Services\Onboarding\OnboardingEngineService::class);
        $engine->definirResponsaveis($onboarding, null, User::factory()->create());

        $onboarding->refresh();
        $situacao = app(\App\Services\Onboarding\OnboardingSituacaoService::class);

        $this->assertFalse(
            $situacao->prontoParaConcluir($onboarding->passos),
            'A v10 não tem passo administrativo — este estado não deve existir.'
        );

        // Resolve todos os passos: o onboarding tem de fechar sozinho.
        $onboarding->passos()->update([
            'status'   => \App\Models\OnboardingPasso::STATUS_CONCLUIDO,
            'feito_em' => now(),
        ]);
        $engine->reavaliar($onboarding->fresh());

        $this->assertSame(Onboarding::STATUS_CONCLUIDO, $onboarding->fresh()->status);
    }
}
