<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingAcessosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Link do App ECF e e-mail do colaborador: os dois são de CADA EMPRESA.
 *
 * ### O que este teste protege
 * Que não volte a existir padrão global. A primeira versão desta feature tinha
 * um, copiado do onboarding de Polos; o negócio corrigiu. Um valor único para a
 * base inteira mandaria todo cliente para o mesmo endereço, e o erro só
 * apareceria dias depois — quando o acesso não chegasse a ninguém.
 *
 * O outro invariante é o `null`: campo apagado grava `null`, nunca string
 * vazia. `""` faria o portal renderizar um botão que não leva a lugar nenhum,
 * em vez do aviso de "ainda não configurado".
 */
class AcessosDoClienteTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
        $servico = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();

        $company = Company::create([
            'name'              => 'Empresa Acessos '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => null,
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        return [$company, Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail()];
    }

    private function svc(): OnboardingAcessosService
    {
        return app(OnboardingAcessosService::class);
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'adm.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
    }

    /** @test */
    public function cada_empresa_tem_os_seus_valores(): void
    {
        [$a] = $this->empresaComOnboarding();
        [$b] = $this->empresaComOnboarding();

        $this->svc()->salvarDaEmpresa($a, 'https://app-a.test', 'a@ecf.test');
        $this->svc()->salvarDaEmpresa($b, 'https://app-b.test', 'b@ecf.test');

        $this->assertSame('https://app-a.test', $this->svc()->paraEmpresa($a->fresh())['app_ecf_link']);
        $this->assertSame('a@ecf.test', $this->svc()->paraEmpresa($a->fresh())['email_colaborador']);

        $this->assertSame('https://app-b.test', $this->svc()->paraEmpresa($b->fresh())['app_ecf_link']);
        $this->assertSame('b@ecf.test', $this->svc()->paraEmpresa($b->fresh())['email_colaborador']);
    }

    /**
     * Empresa sem valor fica SEM — nada de herdar de lugar nenhum. É o teste
     * que quebra no dia em que alguém reintroduzir um padrão global.
     *
     * @test
     */
    public function empresa_sem_valor_nao_herda_de_outra_nem_de_padrao(): void
    {
        [$configurada] = $this->empresaComOnboarding();
        [$vazia] = $this->empresaComOnboarding();

        $this->svc()->salvarDaEmpresa($configurada, 'https://app.test', 'alguem@ecf.test');

        $r = $this->svc()->paraEmpresa($vazia->fresh());

        $this->assertNull($r['app_ecf_link'], 'A empresa sem link herdou de algum lugar.');
        $this->assertNull($r['email_colaborador'], 'A empresa sem e-mail herdou de algum lugar.');
    }

    /**
     * Campo apagado grava `null`, nunca `""` — senão o portal mostraria um
     * botão que não leva a lugar nenhum.
     *
     * @test
     */
    public function apagar_o_campo_grava_nulo_e_nao_string_vazia(): void
    {
        [$company] = $this->empresaComOnboarding();

        $this->svc()->salvarDaEmpresa($company, 'https://app.test', 'alguem@ecf.test');
        $this->svc()->salvarDaEmpresa($company->fresh(), '', '   ');

        $company = $company->fresh();

        $this->assertNull($company->app_ecf_link);
        $this->assertNull($company->email_colaborador);
    }

    /** @test */
    public function portal_do_cliente_recebe_os_valores_da_empresa(): void
    {
        [$company] = $this->empresaComOnboarding();
        $this->svc()->salvarDaEmpresa($company, 'https://app.test', 'alguem@ecf.test');

        $token = OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => Str::random(48)]
        )->token;

        $props = $this->get(route('portal.onboarding', $token))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('https://app.test', $props['empresa']['app_ecf_link']);
        $this->assertSame('alguem@ecf.test', $props['empresa']['email_colaborador']);
    }

    /**
     * A rota de padrão global não existe mais. Se alguém a recriar sem pensar,
     * este teste não pega — mas o de herança acima pega.
     *
     * @test
     */
    public function nao_existe_rota_de_padrao_global(): void
    {
        $this->assertFalse(
            app('router')->has('onboarding.acessos.padroes'),
            'Voltou a existir uma rota de padrão global de acessos.'
        );
    }

    /** URL malformada não entra — o cliente clicaria num link quebrado. */
    /** @test */
    public function link_invalido_e_recusado(): void
    {
        [, $onboarding] = $this->empresaComOnboarding();

        $this->actingAs($this->admin())
            ->put(route('onboarding.acessos.empresa', $onboarding->id), [
                'app_ecf_link' => 'isto-nao-e-url',
            ])
            ->assertSessionHasErrors('app_ecf_link');
    }

    /** @test */
    public function salvar_pela_rota_reflete_no_portal(): void
    {
        [$company, $onboarding] = $this->empresaComOnboarding();

        $this->actingAs($this->admin())
            ->put(route('onboarding.acessos.empresa', $onboarding->id), [
                'app_ecf_link'      => 'https://app.salvo.test',
                'email_colaborador' => 'salvo@ecf.test',
            ])
            ->assertRedirect();

        $this->assertSame('https://app.salvo.test', $company->fresh()->app_ecf_link);
        $this->assertSame('salvo@ecf.test', $company->fresh()->email_colaborador);
    }
}
