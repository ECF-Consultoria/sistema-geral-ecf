<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * O cliente informa, pelo portal, quem devemos acionar (§13.2) e quem
 * participa das reuniões com os Gmails (§16).
 *
 * Até 15/09/2026 a rota era PÚBLICA, por token, sem sessão — uma superfície de
 * escrita anônima. Desde então só a porta autenticada grava, e os limites que
 * estes testes cobram continuam valendo por cima do login: só adiciona (nunca
 * apaga), a sessão só alcança a própria empresa, e participante sem e-mail é
 * recusado, porque o objetivo declarado do §16 é enviar o convite.
 */
class PortalPessoasDoClienteTest extends TestCase
{
    use EntraNoPortal;
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** @return array{0:Company,1:Onboarding} */
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

        return [$company, $onboarding->fresh()];
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
        [$company] = $this->cenario();

        $chaves = collect(app(OnboardingLinkService::class)->passosDoPortal($company))
            ->pluck('chave')
            ->all();

        // 14/09 — INVERTIDO de propósito. Os dois eram `dono=cliente` e por
        // isso apareciam no portal; a decisão de negócio é que são cadastro
        // INTERNO: a ECF preenche com o que o cliente responde na reunião.
        // O dado continua sendo coletado, só não é mais pedido na tela dele.
        $this->assertNotContains('ponto_contato_definido', $chaves);
        $this->assertNotContains('participantes_reuniao_cadastrados', $chaves);
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

        $porChave = collect(app(OnboardingLinkService::class)->passosDoPortal($company))
            ->keyBy('chave');

        // A ação `pessoas` continua existindo e continua correta — o que mudou
        // é ONDE ela é oferecida. Nenhum dos dois chega mais ao portal, então
        // o que se prova aqui é a ausência; o mapeamento em si segue coberto
        // por `acaoDoCliente()`.
        $this->assertArrayNotHasKey('ponto_contato_definido', $porChave->all());
        $this->assertArrayNotHasKey('participantes_reuniao_cadastrados', $porChave->all());
    }

    /**
     * v20 — este teste media o item de checklist fechando na hora.
     * `participantes_reuniao_cadastrados` saiu da régua, então o que sobrou é o
     * que sempre foi o essencial: o cadastro GRAVA, pelo portal do cliente.
     *
     * O portão do endpoint deixou de exigir o passo junto com a v20: exigi-lo
     * passaria a devolver 422 para todo mundo, e o bloco de contatos do portal
     * — que continua existindo — morreria calado.
     *
     * @test
     */
    public function cliente_cadastra_participante_pelo_portal(): void
    {
        [$company, $onboarding] = $this->cenario();

        $this->entrarNoPortal($company)->post(route('portal.auth.onboarding.pessoas'), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Joana Cliente',
            'email' => 'joana@gmail.com',
        ])->assertRedirect();

        $contato = OnboardingContato::where('onboarding_id', $onboarding->id)
            ->where('papel', OnboardingContato::PAPEL_PARTICIPANTE)
            ->firstOrFail();

        $this->assertSame('Joana Cliente', $contato->nome);
        $this->assertSame('joana@gmail.com', $contato->email);
    }

    /**
     * §16 existe para mandar convite. Participante sem e-mail não recebe nada,
     * então a rota recusa — não é validação decorativa.
     *
     * @test
     */
    public function participante_sem_email_e_recusado(): void
    {
        [$company, $onboarding] = $this->cenario();

        $this->entrarNoPortal($company)->post(route('portal.auth.onboarding.pessoas'), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Sem Email',
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            0,
            OnboardingContato::where('onboarding_id', $onboarding->id)->count(),
            'recusado é recusado: nada pode ter sido gravado'
        );
    }

    /** Ponto de contato aceita só telefone — nem todo mundo dá e-mail. */
    /** @test */
    public function ponto_de_contato_sem_email_e_aceito(): void
    {
        [$company, $onboarding] = $this->cenario();

        $this->entrarNoPortal($company)->post(route('portal.auth.onboarding.pessoas'), [
            'papel'    => 'ponto_de_contato',
            'nome'     => 'Fulano Contato',
            'telefone' => '11999998888',
        ])->assertRedirect();

        $contato = OnboardingContato::where('onboarding_id', $onboarding->id)
            ->where('papel', OnboardingContato::PAPEL_PONTO_CONTATO)
            ->firstOrFail();

        $this->assertSame('11999998888', $contato->telefone);
        $this->assertNull($contato->email);
    }

    /**
     * O ponto de contato COM e-mail já entra como participante das reuniões —
     * é ele quem sempre participa, e obrigar o cliente a redigitar os mesmos
     * dados no item seguinte era a parte mais confusa do portal.
     */
    /** @test */
    public function ponto_de_contato_com_email_ja_entra_como_participante(): void
    {
        [$company, $onboarding] = $this->cenario();

        $this->entrarNoPortal($company)->post(route('portal.auth.onboarding.pessoas'), [
            'papel'  => 'ponto_de_contato',
            'nome'   => 'Fulano Contato',
            'email'  => 'fulano@empresa.com',
            'funcao' => 'Sócio',
        ])->assertRedirect();

        $participantes = OnboardingContato::where('onboarding_id', $onboarding->id)
            ->where('papel', OnboardingContato::PAPEL_PARTICIPANTE)
            ->get();

        $this->assertCount(1, $participantes);
        $this->assertSame('Fulano Contato', $participantes->first()->nome);
        $this->assertSame('fulano@empresa.com', $participantes->first()->email);
    }

    /**
     * Sem e-mail não há espelho: o §16 existe para ENVIAR o convite, e
     * participante sem Gmail não recebe encontro nenhum. O portal oferece a
     * pessoa no seletor, pedindo o e-mail que falta.
     */
    /** @test */
    public function ponto_de_contato_sem_email_nao_vira_participante(): void
    {
        [$company, $onboarding] = $this->cenario();

        $this->entrarNoPortal($company)->post(route('portal.auth.onboarding.pessoas'), [
            'papel'    => 'ponto_de_contato',
            'nome'     => 'Fulano Contato',
            'telefone' => '11999998888',
        ])->assertRedirect();

        $this->assertSame(
            0,
            OnboardingContato::where('onboarding_id', $onboarding->id)
                ->where('papel', OnboardingContato::PAPEL_PARTICIPANTE)
                ->count()
        );
    }

    /** Cadastrar o mesmo ponto de contato duas vezes não duplica o convite. */
    /** @test */
    public function espelho_do_ponto_de_contato_nao_duplica(): void
    {
        [$company, $onboarding] = $this->cenario();
        $cliente = $this->clienteDoPortal($company);

        $payload = [
            'papel' => 'ponto_de_contato',
            'nome'  => 'Fulano Contato',
            'email' => 'fulano@empresa.com',
        ];

        $this->entrarNoPortal($company, $cliente)->post(route('portal.auth.onboarding.pessoas'), $payload)->assertRedirect();
        $this->entrarNoPortal($company, $cliente)->post(route('portal.auth.onboarding.pessoas'), $payload)->assertRedirect();

        $this->assertSame(
            1,
            OnboardingContato::where('onboarding_id', $onboarding->id)
                ->where('papel', OnboardingContato::PAPEL_PARTICIPANTE)
                ->count()
        );
    }

    /** §16: "deve ser possível cadastrar mais de um participante". */
    /** @test */
    public function cliente_cadastra_varios_participantes(): void
    {
        [$company, $onboarding] = $this->cenario();
        $cliente = $this->clienteDoPortal($company);

        foreach (['Ana', 'Bruno', 'Carla'] as $nome) {
            $this->entrarNoPortal($company, $cliente)->post(route('portal.auth.onboarding.pessoas'), [
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
     * A sessão vale para as empresas DA PESSOA. Sem esta trava, quem entrou
     * na empresa A escreveria no onboarding de outra empresa.
     *
     * @test
     */
    public function sessao_de_uma_empresa_nao_escreve_na_outra(): void
    {
        [$companyA, $onboardingA] = $this->cenario();
        [, $onboardingB] = $this->cenario();

        $this->entrarNoPortal($companyA)->post(route('portal.auth.onboarding.pessoas'), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Da Empresa A',
            'email' => 'a@gmail.com',
        ])->assertRedirect();

        $this->assertSame(1, OnboardingContato::where('onboarding_id', $onboardingA->id)->count());
        $this->assertSame(0, OnboardingContato::where('onboarding_id', $onboardingB->id)->count());
    }

    /**
     * Sem login não grava — nem pela porta nova, nem pelo link antigo. Era a
     * escrita anônima que o fim do token veio fechar.
     *
     * @test
     */
    public function sem_login_ninguem_grava_pessoa(): void
    {
        [, $onboarding] = $this->cenario();

        $payload = ['papel' => 'participante_reuniao', 'nome' => 'Ninguem', 'email' => 'x@gmail.com'];

        $semLogin = $this->post(route('portal.auth.onboarding.pessoas'), $payload);
        $this->assertFalse($semLogin->isSuccessful(), 'A porta autenticada aceitou escrita sem login.');

        $this->post(route('onboarding.publico.pessoas', str_repeat('a', 48)), $payload)->assertStatus(410);

        $this->assertSame(0, OnboardingContato::where('onboarding_id', $onboarding->id)->count());
    }

    /**
     * O portal só ADICIONA pessoa. Não existe caminho de apagar nem de
     * sobrescrever pelo prefixo antigo — e desde 15/09/2026 as rotas que
     * sobraram ali só redirecionam ou recusam, mas o cadeado fica: se alguém
     * reabrir um DELETE sob `portal-cliente/`, isto quebra.
     *
     * O prefixo é `portal-cliente/` desde 21/08/2026. Isto NÃO é cosmético: com
     * o prefixo antigo o filtro passou a devolver lista vazia, e as duas
     * asserções passavam por vacuidade.
     *
     * @test
     */
    public function nao_existe_rota_publica_de_remover_pessoa(): void
    {
        $rotas = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'portal-cliente/'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Guarda contra o próprio teste esvaziar de novo: se o prefixo mudar
        // e ninguém atualizar aqui, isto quebra em vez de passar em branco.
        $this->assertNotEmpty($rotas, 'Nenhuma rota sob portal-cliente/ — o filtro está olhando para o prefixo errado.');

        $this->assertNotContains('DELETE', $rotas);
        $this->assertNotContains('PUT', $rotas);
    }
}
