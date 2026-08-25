<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O cliente não fecha passo INTERNO — e a equipe fecha, mas assinado.
 *
 * ### A falha que isto corrige
 * `marcarFeitoPorChave()` buscava o passo só pela CHAVE, que vem do corpo da
 * requisição, sem conferir de quem era o passo. Verificado contra a base real
 * em 21/08/2026: um PATCH sem sessão e sem CSRF levou
 * `adman_preenchimento_interno` (dono `interno`, status `bloqueado`) para
 * `concluido` — e o registro não guardava sinal de que a origem tinha sido o
 * cliente. O dado de teste foi revertido na hora.
 *
 * ### Por que a equipe PODE, e o cliente não
 * Decisão de 25/08/2026: analista e estrategista entram no portal para
 * orientar, e podem agir. O que separa um caso do outro não é a permissão de
 * escrever, é a ASSINATURA: quando quem fecha é a ECF,
 * `declarado_pelo_cliente` fica falso e o nome de quem fechou fica gravado.
 * Sem isso, "o cliente confirmou" e "confirmamos por ele" seriam a mesma
 * linha no banco.
 */
class PassoInternoProtegidoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: Onboarding} */
    private function cenario(): array
    {
        $servico = Servico::create([
            'nome' => 'Serviço '.uniqid(), 'valor_padrao' => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
            'setor' => Servico::SETOR_OUTROS,
        ]);

        $company = Company::factory()->create();

        $onboarding = Onboarding::create([
            'company_id' => $company->id, 'servico_id' => $servico->id,
            'status' => Onboarding::STATUS_ANDAMENTO, 'iniciado_em' => now(),
        ]);

        // Um de cada dono, os dois com `auto_fonte` de instrução — que é o
        // unico tipo que alguem pode DECLARAR como feito.
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id, 'ordem' => 1,
            'chave' => 'passo_do_cliente', 'titulo' => 'Do cliente',
            'dono' => OnboardingPasso::DONO_CLIENTE,
            'auto_fonte' => OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
            'status' => OnboardingPasso::STATUS_ABERTO,
        ]);

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id, 'ordem' => 2,
            'chave' => 'passo_interno', 'titulo' => 'Interno da ECF',
            'dono' => OnboardingPasso::DONO_INTERNO,
            'auto_fonte' => OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
            'status' => OnboardingPasso::STATUS_ABERTO,
        ]);

        return [$company, $onboarding];
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->firstOrFail();
    }

    /** O que a falha permitia: fechar passo interno com um PATCH e a chave. */
    #[Test]
    public function cliente_nao_fecha_passo_interno(): void
    {
        [$company, $onboarding] = $this->cenario();

        $fechados = app(OnboardingLinkService::class)
            ->marcarFeitoPorChave($company, 'passo_interno', '127.0.0.1');

        $this->assertSame(0, $fechados);
        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'passo_interno')->status,
        );
    }

    /** Pela rota pública, que é por onde a falha era explorável. */
    #[Test]
    public function o_patch_publico_nao_fecha_passo_interno(): void
    {
        [$company, $onboarding] = $this->cenario();

        $link = OnboardingLink::create([
            'company_id' => $company->id, 'token' => \Illuminate\Support\Str::random(48),
        ]);

        $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'passo_interno']);

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'passo_interno')->status,
        );
    }

    /** E o passo DELE continua funcionando — a trava não pode fechar tudo. */
    #[Test]
    public function cliente_continua_fechando_o_proprio_passo(): void
    {
        [$company, $onboarding] = $this->cenario();

        $fechados = app(OnboardingLinkService::class)
            ->marcarFeitoPorChave($company, 'passo_do_cliente', '127.0.0.1');

        $this->assertSame(1, $fechados);

        $valor = $this->passo($onboarding, 'passo_do_cliente')->valor;
        $this->assertTrue($valor['declarado_pelo_cliente']);
    }

    /** A equipe fecha o interno — e fica assinado como dela. */
    #[Test]
    public function equipe_fecha_passo_interno_e_a_marca_fica_no_nome_dela(): void
    {
        [$company, $onboarding] = $this->cenario();

        $membro = User::create([
            'name' => 'Ana da ECF', 'email' => 'ana.'.uniqid().'@ecf.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);
        $ator = \App\Support\Portal\AtorDoPortal::daEquipe($membro);

        $fechados = app(OnboardingLinkService::class)
            ->marcarFeitoPorChave($company, 'passo_interno', '127.0.0.1', $ator);

        $this->assertSame(1, $fechados);

        $valor = $this->passo($onboarding, 'passo_interno')->valor;

        // A distinção que importa: NÃO foi o cliente que declarou.
        $this->assertFalse($valor['declarado_pelo_cliente']);
        $this->assertStringContainsString('Ana da ECF', $valor['declarado_por']);
        $this->assertStringContainsString('equipe ECF', $valor['declarado_por']);
    }

    /** Quando a equipe fecha um passo do CLIENTE, também fica assinado. */
    #[Test]
    public function equipe_fechando_passo_do_cliente_nao_vira_declaracao_do_cliente(): void
    {
        [$company, $onboarding] = $this->cenario();

        $membro = User::create([
            'name' => 'Bruno da ECF', 'email' => 'bruno.'.uniqid().'@ecf.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);

        app(OnboardingLinkService::class)->marcarFeitoPorChave(
            $company,
            'passo_do_cliente',
            '127.0.0.1',
            \App\Support\Portal\AtorDoPortal::daEquipe($membro),
        );

        $valor = $this->passo($onboarding, 'passo_do_cliente')->valor;

        $this->assertFalse($valor['declarado_pelo_cliente']);
        $this->assertStringContainsString('Bruno da ECF', $valor['declarado_por']);
    }
}
