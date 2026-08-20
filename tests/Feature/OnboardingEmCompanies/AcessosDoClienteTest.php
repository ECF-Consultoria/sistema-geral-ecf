<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\Configuracao;
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
 * Link do App ECF e e-mail do colaborador: padrão global com override por
 * empresa.
 *
 * ### O que este teste protege
 * A regra do `null`. Campo vazio na empresa significa "segue o padrão", nunca
 * "sem valor" — e é isso que permite trocar o endereço em UM lugar quando ele
 * mudar. Se um dia alguém gravar o padrão copiado dentro de cada empresa, a
 * cópia que ficar para trás manda o cliente para um link morto e o portal
 * continua mostrando um link, só que o errado. Os testes de fallback e de
 * "apagar volta ao padrão" são os que seguram isso.
 */
class AcessosDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
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
            'servico_id'       => $this->servico()->id,
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

    /** @test */
    public function empresa_sem_valor_proprio_recebe_o_padrao(): void
    {
        [$company] = $this->empresaComOnboarding();

        $this->svc()->salvarPadroes('https://app.ecf.test');

        $r = $this->svc()->paraEmpresa($company->fresh());

        $this->assertSame('https://app.ecf.test', $r['app_ecf_link']);
        $this->assertSame('padrao', $r['origem']['app_ecf_link']);

        // O e-mail NÃO tem padrão global: cada cliente concede acesso a um
        // endereço criado para ele. Empresa sem o seu fica sem, e a tela avisa.
        $this->assertNull($r['email_colaborador']);
        $this->assertSame('ausente', $r['origem']['email_colaborador']);
    }

    /** @test */
    public function valor_da_empresa_vence_o_padrao(): void
    {
        [$company] = $this->empresaComOnboarding();

        $this->svc()->salvarPadroes('https://app.ecf.test');
        $this->svc()->salvarDaEmpresa($company, 'https://app.proprio.test', 'proprio@ecf.test');

        $r = $this->svc()->paraEmpresa($company->fresh());

        $this->assertSame('https://app.proprio.test', $r['app_ecf_link']);
        $this->assertSame('proprio@ecf.test', $r['email_colaborador']);
        $this->assertSame('empresa', $r['origem']['app_ecf_link']);
    }

    /**
     * O caminho de VOLTA: apagar o campo devolve a empresa ao padrão. Sem isto,
     * quem preenchesse por engano ficaria preso ao valor próprio para sempre.
     *
     * @test
     */
    public function apagar_o_campo_faz_a_empresa_voltar_ao_padrao(): void
    {
        [$company] = $this->empresaComOnboarding();

        $this->svc()->salvarPadroes('https://app.ecf.test');
        $this->svc()->salvarDaEmpresa($company, 'https://app.proprio.test', 'proprio@ecf.test');

        // String vazia é o que o formulário manda quando o usuário apaga.
        $this->svc()->salvarDaEmpresa($company->fresh(), '', '   ');

        $company = $company->fresh();

        $this->assertNull($company->app_ecf_link, 'Campo apagado tem de virar null, não string vazia.');
        $this->assertNull($company->email_colaborador);

        $r = $this->svc()->paraEmpresa($company);
        $this->assertSame('https://app.ecf.test', $r['app_ecf_link']);
        $this->assertSame('padrao', $r['origem']['app_ecf_link']);
    }

    /** Sem padrão e sem valor próprio: `null`, e a tela avisa em vez de mentir. */
    /** @test */
    public function sem_padrao_e_sem_valor_proprio_devolve_nulo(): void
    {
        [$company] = $this->empresaComOnboarding();

        $r = $this->svc()->paraEmpresa($company);

        $this->assertNull($r['app_ecf_link']);
        $this->assertSame('ausente', $r['origem']['app_ecf_link']);
    }

    /** @test */
    public function portal_do_cliente_recebe_o_valor_resolvido(): void
    {
        [$company] = $this->empresaComOnboarding();
        $this->svc()->salvarPadroes('https://app.ecf.test');

        $token = OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => Str::random(48)]
        )->token;

        $props = $this->get(route('onboarding.publico.workspace', $token))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('https://app.ecf.test', $props['empresa']['app_ecf_link']);
        $this->assertNull($props['empresa']['email_colaborador'], 'E-mail não tem padrão global.');
    }

    /**
     * Mexer no padrão muda o que TODA empresa vê de uma vez. Só admin.
     *
     * @test
     */
    public function nao_admin_nao_altera_o_padrao_global(): void
    {
        $consultor = User::create([
            'name'     => 'Consultor '.uniqid(),
            'email'    => 'c.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        $this->svc()->salvarPadroes('https://original.test');

        $this->actingAs($consultor)
            ->put(route('onboarding.acessos.padroes'), ['app_ecf_link' => 'https://invasor.test'])
            ->assertForbidden();

        $this->assertSame('https://original.test', Configuracao::get(OnboardingAcessosService::CHAVE_APP_ECF));
    }

    /**
     * A rota do PADRÃO não aceita e-mail. Se alguém mandar, o campo é ignorado
     * — nunca vira um endereço único para a base inteira.
     *
     * @test
     */
    public function padrao_global_nao_guarda_email_de_colaborador(): void
    {
        [$company] = $this->empresaComOnboarding();

        $admin = User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'adm.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        $this->actingAs($admin)
            ->put(route('onboarding.acessos.padroes'), [
                'app_ecf_link'      => 'https://app.ecf.test',
                'email_colaborador' => 'ninguem@ecf.test',
            ])
            ->assertRedirect();

        $this->assertNull(Configuracao::get('onboarding_email_colaborador'));
        $this->assertNull($this->svc()->paraEmpresa($company->fresh())['email_colaborador']);
    }

    /** URL malformada não entra — o cliente clicaria num link quebrado. */
    /** @test */
    public function link_invalido_e_recusado(): void
    {
        [, $onboarding] = $this->empresaComOnboarding();

        $admin = User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'a.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        $this->actingAs($admin)
            ->put(route('onboarding.acessos.empresa', $onboarding->id), [
                'app_ecf_link' => 'isto-nao-e-url',
            ])
            ->assertSessionHasErrors('app_ecf_link');
    }
}
