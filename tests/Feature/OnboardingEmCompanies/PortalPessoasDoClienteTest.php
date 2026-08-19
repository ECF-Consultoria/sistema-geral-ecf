<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O cliente informa, pelo portal, quem devemos acionar (§13.2) e quem
 * participa das reuniões com os Gmails (§16).
 *
 * A rota é PÚBLICA, por token, isenta de CSRF e sem sessão — o que faz dela
 * uma superfície de escrita anônima. Estes testes cobram os limites disso:
 * só adiciona (nunca apaga), o token só alcança a própria empresa, e
 * participante sem e-mail é recusado, porque o objetivo declarado do §16 é
 * enviar o convite.
 */
class PortalPessoasDoClienteTest extends TestCase
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

    /** @return array{0:Company,1:Onboarding,2:string} */
    private function cenario(): array
    {
        $company = Company::create([
            'name'   => 'Empresa Portal '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        app(OnboardingEngineService::class)
            ->definirResponsaveis($onboarding, null, User::factory()->create());

        $link = app(OnboardingLinkService::class)->paraEmpresa($company);

        return [$company, $onboarding->fresh(), $link->token];
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->firstOrFail();
    }

    /** @test */
    public function os_dois_itens_chegam_ao_portal_do_cliente(): void
    {
        [$company, , $token] = $this->cenario();

        $chaves = collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->pluck('chave')
            ->all();

        $this->assertContains('ponto_contato_definido', $chaves);
        $this->assertContains('participantes_reuniao_cadastrados', $chaves);
    }

    /**
     * Sem a ação `pessoas`, os dois itens cairiam em `nenhuma`, que renderiza
     * "você não precisa fazer nada" — o oposto de "solicitar os Gmails".
     *
     * @test
     */
    public function os_dois_itens_oferecem_a_acao_de_cadastrar_pessoas(): void
    {
        [$company] = $this->cenario();

        $porChave = collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->keyBy('chave');

        $this->assertSame(OnboardingLinkService::ACAO_PESSOAS, $porChave['ponto_contato_definido']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_PESSOAS, $porChave['participantes_reuniao_cadastrados']['acao']);
    }

    /** @test */
    public function cliente_cadastra_participante_e_o_item_fecha_na_hora(): void
    {
        [, $onboarding, $token] = $this->cenario();

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'participantes_reuniao_cadastrados')->status
        );

        $this->post(route('onboarding.publico.pessoas', $token), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Joana Cliente',
            'email' => 'joana@gmail.com',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'participantes_reuniao_cadastrados')->status,
            'O item não fechou na hora — o cliente veria pendente por até 10 minutos.'
        );
    }

    /**
     * §16 existe para mandar convite. Participante sem e-mail não recebe nada,
     * então a rota recusa — não é validação decorativa.
     *
     * @test
     */
    public function participante_sem_email_e_recusado(): void
    {
        [, $onboarding, $token] = $this->cenario();

        $this->post(route('onboarding.publico.pessoas', $token), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Sem Email',
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'participantes_reuniao_cadastrados')->status
        );
    }

    /** Ponto de contato aceita só telefone — nem todo mundo dá e-mail. */
    /** @test */
    public function ponto_de_contato_sem_email_e_aceito(): void
    {
        [, $onboarding, $token] = $this->cenario();

        $this->post(route('onboarding.publico.pessoas', $token), [
            'papel'    => 'ponto_de_contato',
            'nome'     => 'Fulano Contato',
            'telefone' => '11999998888',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'ponto_contato_definido')->status
        );
    }

    /** §16: "deve ser possível cadastrar mais de um participante". */
    /** @test */
    public function cliente_cadastra_varios_participantes(): void
    {
        [, $onboarding, $token] = $this->cenario();

        foreach (['Ana', 'Bruno', 'Carla'] as $nome) {
            $this->post(route('onboarding.publico.pessoas', $token), [
                'papel' => 'participante_reuniao',
                'nome'  => $nome,
                'email' => strtolower($nome).'@gmail.com',
            ])->assertRedirect();
        }

        $this->assertSame(
            3,
            OnboardingContato::where('onboarding_id', $onboarding->id)
                ->where('papel', OnboardingContato::PAPEL_PARTICIPANTE)
                ->count()
        );
    }

    /**
     * O token vale para UMA empresa. Sem esta trava, um token válido escreveria
     * no onboarding de outra empresa.
     *
     * @test
     */
    public function token_de_uma_empresa_nao_escreve_na_outra(): void
    {
        [, $onboardingA, $tokenA] = $this->cenario();
        [, $onboardingB] = $this->cenario();

        $this->post(route('onboarding.publico.pessoas', $tokenA), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Da Empresa A',
            'email' => 'a@gmail.com',
        ])->assertRedirect();

        $this->assertSame(1, OnboardingContato::where('onboarding_id', $onboardingA->id)->count());
        $this->assertSame(0, OnboardingContato::where('onboarding_id', $onboardingB->id)->count());
    }

    /** Token inexistente é 404, nunca 500 nem escrita silenciosa. */
    /** @test */
    public function token_inexistente_da_404(): void
    {
        $this->post(route('onboarding.publico.pessoas', 'token-que-nao-existe'), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Ninguem',
            'email' => 'x@gmail.com',
        ])->assertNotFound();
    }

    /**
     * A rota pública só ADICIONA. Não existe caminho de apagar por ali — este
     * é um link sem senha, e apagar cadastro de terceiros é mais poder do que
     * "informe quem participa".
     *
     * @test
     */
    public function nao_existe_rota_publica_de_remover_pessoa(): void
    {
        $rotas = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'onboarding-cliente/'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        $this->assertNotContains('DELETE', $rotas);
        $this->assertNotContains('PUT', $rotas);
    }
}
