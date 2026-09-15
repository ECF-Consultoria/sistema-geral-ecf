<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * Porta para o OAuth do Mercado Livre a partir do portal do cliente.
 *
 * O que estes testes protegem:
 *  1. O cliente sai do portal para o ML a partir da SESSÃO do portal — desde
 *     15/09/2026 o token do onboarding não identifica mais empresa nenhuma, e
 *     o link antigo de conectar leva ao login, nunca ao Mercado Livre.
 *  2. A URL de retorno vai no `state`, montada pelo servidor. Se um dia
 *     alguém aceitar isso do request, o callback vira open redirect.
 *  3. A tela sabe QUAL ação cada passo tem. "tem auto_fonte" não basta mais:
 *     a ficha da conta também é automática e se resolve por formulário.
 */
class OnboardingConectarMlTest extends TestCase
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

    private function empresaComOnboardingEmAndamento(): Company
    {
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $engine = app(OnboardingEngineService::class);
        $onboarding = $engine->criarParaContrato($contrato);
        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        return $company->fresh();
    }

    #[Test]
    public function cliente_logado_e_redirecionado_para_o_mercado_livre(): void
    {
        config(['services.mercadolivre.client_id' => 'client-teste', 'services.mercadolivre.redirect' => 'https://exemplo.test/oauth/mercadolivre/callback']);
        $company = $this->empresaComOnboardingEmAndamento();

        $response = $this->entrarNoPortal($company)->get(route('portal.auth.onboarding.conectar-ml'));

        $response->assertRedirect();
        $destino = $response->headers->get('Location');

        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization', $destino);
        $this->assertStringContainsString('code_challenge_method=S256', $destino, 'PKCE precisa continuar valendo na porta do portal.');
    }

    /**
     * O link antigo de conectar vivia no portal por token e pode ter sido
     * encaminhado. Ele não pode mais levar ao Mercado Livre: autorizar a partir
     * dele ligaria a conta de quem clicou à empresa do token. Real ou
     * inventado, o destino é o login.
     */
    #[Test]
    public function link_antigo_de_conectar_leva_ao_login_e_nunca_ao_mercado_livre(): void
    {
        config(['services.mercadolivre.client_id' => 'client-teste', 'services.mercadolivre.redirect' => 'https://exemplo.test/callback']);
        $company = $this->empresaComOnboardingEmAndamento();
        $tokenReal = app(OnboardingLinkService::class)->paraEmpresa($company)->token;

        foreach ([$tokenReal, str_repeat('z', 48)] as $token) {
            $response = $this->get(route('onboarding.publico.conectar-ml', $token));

            $response->assertRedirect(route('portal.entrada'));
            $this->assertStringNotContainsString('mercadolivre', $response->headers->get('Location'));
        }
    }

    #[Test]
    public function url_de_retorno_e_do_proprio_portal_e_vai_no_state_nao_na_query(): void
    {
        config(['services.mercadolivre.client_id' => 'client-teste', 'services.mercadolivre.redirect' => 'https://exemplo.test/callback']);
        $company = $this->empresaComOnboardingEmAndamento();

        $response = $this->entrarNoPortal($company)->get(route('portal.auth.onboarding.conectar-ml'));
        $destino = $response->headers->get('Location');

        // O retorno NÃO viaja na URL do ML — quem o guarda é o state no cache.
        $this->assertStringNotContainsString('/portal/onboarding', urldecode($destino));

        parse_str(parse_url($destino, PHP_URL_QUERY) ?: '', $query);
        $state = $query['state'] ?? null;
        $this->assertNotNull($state);

        $guardado = Cache::get("ml_oauth_state_{$state}");
        $this->assertSame($company->id, $guardado['company_id']);
        $this->assertSame(route('portal.auth.onboarding'), $guardado['retorno_url']);
    }

    #[Test]
    public function fluxo_do_painel_admin_continua_sem_url_de_retorno(): void
    {
        config(['services.mercadolivre.client_id' => 'client-teste', 'services.mercadolivre.redirect' => 'https://exemplo.test/callback']);
        $company = Company::factory()->create();

        // Chamada de sempre, sem o argumento novo — o /ml-oauth não pode mudar.
        $url = app(MercadoLivreService::class)->buildAuthUrl($company);

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $guardado = Cache::get("ml_oauth_state_{$query['state']}");

        $this->assertNull($guardado['retorno_url'], 'Sem retorno, o callback segue mostrando a página de resultado.');
    }

    // ─── A tela sabe qual ação cada passo tem ────────────────────────────────

    #[Test]
    public function cada_passo_do_cliente_traz_a_acao_que_ele_pode_tomar(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        $porChave = collect(app(OnboardingLinkService::class)->passosDoPortal($company))->keyBy('chave');

        $this->assertSame(OnboardingLinkService::ACAO_OAUTH_ML, $porChave['grant_sistema_ecf']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_MARCAR, $porChave['acesso_colaborador_ml']['acao']);
        // `custos_app_ecf` saiu do portal em 14/09 (ver CHAVES_NO_PORTAL) e por
        // isso deixou de ser veículo de teste aqui. O mapeamento da ação dele
        // não mudou — só não há mais card para exercitá-lo.
    }

    #[Test]
    public function passo_automatico_do_cliente_sem_acao_conhecida_nao_vira_botao_de_oauth(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        // Um passo do portal com auto_fonte que não é nem OAuth nem ficha:
        // antes do mapeamento explícito, a tela mostraria "Autorizar acesso".
        //
        // O veículo era `custos_app_ecf`, que saiu do portal em 14/09; passou a
        // ser `acesso_colaborador_ml`, que continua lá e serve ao mesmo
        // propósito — o que se prova é o MAPEAMENTO, não a chave.
        OnboardingPasso::where('chave', 'acesso_colaborador_ml')
            ->whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id))
            ->update(['auto_fonte' => OnboardingPasso::AUTO_FONTE_ACERVO]);

        $porChave = collect(app(OnboardingLinkService::class)->passosDoPortal($company))->keyBy('chave');

        $this->assertSame(OnboardingLinkService::ACAO_NENHUMA, $porChave['acesso_colaborador_ml']['acao']);
    }
}
