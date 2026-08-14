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
use Tests\TestCase;

/**
 * Porta pública para o OAuth do Mercado Livre a partir do portal do cliente.
 *
 * O que estes testes protegem:
 *  1. O cliente sai do portal para o ML sem login e sem link assinado — o
 *     token do onboarding já identifica a empresa.
 *  2. A URL de retorno vai no `state`, montada pelo servidor. Se um dia
 *     alguém aceitar isso do request, o callback vira open redirect.
 *  3. A tela sabe QUAL ação cada passo tem. "tem auto_fonte" não basta mais:
 *     a ficha da conta também é automática e se resolve por formulário.
 */
class OnboardingConectarMlTest extends TestCase
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

    private function tokenPublico(Company $company): string
    {
        return app(OnboardingLinkService::class)->paraEmpresa($company)->token;
    }

    #[Test]
    public function cliente_sem_login_e_redirecionado_para_o_mercado_livre(): void
    {
        config(['services.mercadolivre.client_id' => 'client-teste', 'services.mercadolivre.redirect' => 'https://exemplo.test/oauth/mercadolivre/callback']);
        $company = $this->empresaComOnboardingEmAndamento();

        $response = $this->get(route('onboarding.publico.conectar-ml', $this->tokenPublico($company)));

        $response->assertRedirect();
        $destino = $response->headers->get('Location');

        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization', $destino);
        $this->assertStringContainsString('code_challenge_method=S256', $destino, 'PKCE precisa continuar valendo na porta pública.');
    }

    #[Test]
    public function token_inexistente_devolve_404_e_nao_gera_url_de_autorizacao(): void
    {
        $this->get(route('onboarding.publico.conectar-ml', str_repeat('z', 48)))
            ->assertNotFound();
    }

    #[Test]
    public function url_de_retorno_e_do_proprio_portal_e_vai_no_state_nao_na_query(): void
    {
        config(['services.mercadolivre.client_id' => 'client-teste', 'services.mercadolivre.redirect' => 'https://exemplo.test/callback']);
        $company = $this->empresaComOnboardingEmAndamento();
        $token = $this->tokenPublico($company);

        $response = $this->get(route('onboarding.publico.conectar-ml', $token));
        $destino = $response->headers->get('Location');

        // O retorno NÃO viaja na URL do ML — quem o guarda é o state no cache.
        $this->assertStringNotContainsString('onboarding-cliente', $destino);

        parse_str(parse_url($destino, PHP_URL_QUERY) ?: '', $query);
        $state = $query['state'] ?? null;
        $this->assertNotNull($state);

        $guardado = Cache::get("ml_oauth_state_{$state}");
        $this->assertSame($company->id, $guardado['company_id']);
        $this->assertSame(route('onboarding.publico.workspace', $token), $guardado['retorno_url']);
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

        $porChave = collect(app(OnboardingLinkService::class)->passosDoCliente($company))->keyBy('chave');

        $this->assertSame(OnboardingLinkService::ACAO_OAUTH_ML, $porChave['grant_sistema_ecf']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_FICHA, $porChave['ficha_conta_preenchida']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_MARCAR, $porChave['acesso_colaborador_ml']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_MARCAR, $porChave['custos_app_ecf']['acao']);
    }

    #[Test]
    public function passo_automatico_do_cliente_sem_acao_conhecida_nao_vira_botao_de_oauth(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        // Um passo `dono=cliente` com auto_fonte que não é nem OAuth nem ficha:
        // antes do mapeamento explícito, a tela mostraria "Autorizar acesso".
        OnboardingPasso::where('chave', 'custos_app_ecf')
            ->whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id))
            ->update(['auto_fonte' => OnboardingPasso::AUTO_FONTE_ACERVO]);

        $porChave = collect(app(OnboardingLinkService::class)->passosDoCliente($company))->keyBy('chave');

        $this->assertSame(OnboardingLinkService::ACAO_NENHUMA, $porChave['custos_app_ecf']['acao']);
    }
}
