<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\HubspotLineItemMapping;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 135 Plano 05 (Task 3) — prova os 4 call-sites REAIS de `ContratoServico`
 * (SC-03): cada rota/controller que cria contrato dispara o
 * `ContratoServicoObserver` (nunca chamado diretamente aqui) e o onboarding
 * nasce em rascunho, com 14 passos, apontando pra versão ativa do template.
 *
 * Cobre também o negativo de D-08: serviço sem template publicado não gera
 * onboarding nenhum — é o que mantém a v1 restrita a Gestão e protege as
 * demais suítes que criam `ContratoServico` pelo projeto inteiro.
 */
class OnboardingObserverCallSitesTest extends TestCase
{
    use RefreshDatabase;

    private const HUBSPOT_SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const HUBSPOT_URL = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

    }

    // ─── Helpers compartilhados ─────────────────────────────────────────────

    /** O Servico "Gestão" real, publicado pelas migrations do catálogo. */
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase135-05 ' . uniqid(),
            'email'    => 'admin.p135-05.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Asserção comum aos 4 cenários: onboarding em rascunho, 14 passos
     * montados, versão da definição carimbada — SEM nenhum teste chamar o
     * Observer diretamente (a criação vem sempre da rota real).
     */
    private function assertOnboardingDeGestaoEmRascunho(Company $company, Servico $servico): Onboarding
    {
        $onboarding = Onboarding::where('company_id', $company->id)
            ->where('servico_id', $servico->id)
            ->firstOrFail();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertSame(DefinicaoOnboarding::VERSAO, $onboarding->definicao_versao);
        $this->assertSame($this->totalDePassosDaDefinicao(), OnboardingPasso::where('onboarding_id', $onboarding->id)->count());

        return $onboarding;
    }

    // ═══ 1. Webhook HubSpot — Api\HubspotWebhookController::persistirContratos() ═══

    private function assinaturaHubspot(string $body, string $ts): string
    {
        $url = url(self::HUBSPOT_URL);
        $methodUriBody = 'POST' . $url . $body . $ts;

        return base64_encode(hash_hmac('sha256', $methodUriBody, self::HUBSPOT_SECRET, true));
    }

    /** Converte headers em formato $_SERVER — necessário para $this->call(). */
    private function servidorHubspot(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }

        return $out;
    }

    public function test_webhook_hubspot_dispara_observer_e_gera_onboarding_em_rascunho(): void
    {
        config([
            'services.hubspot.client_secret'          => self::HUBSPOT_SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => 'closedwon',
            'services.hubspot.props.deal'             => ['servico' => 'servico_ecf'],
            'services.hubspot.props.company'          => [
                'name' => 'name', 'cnpj' => 'cnpj', 'email' => 'email', 'phone' => 'phone',
            ],
            'services.hubspot.props.contact' => [
                'firstname' => 'firstname', 'lastname' => 'lastname', 'email' => 'email', 'phone' => 'phone',
            ],
        ]);

        $servico = $this->servicoDeGestao();
        HubspotLineItemMapping::create([
            'line_item_name' => 'MAP-135',
            'servico_id'     => $servico->id,
            'ativo'          => true,
        ]);

        $dealId = 900135;
        $companyHubId = 700135;

        Http::fake([
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/companies" => Http::response([
                'results' => [['toObjectId' => $companyHubId]],
            ]),
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/contacts" => Http::response(['results' => []]),
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/line_items" => Http::response([
                'results' => [['id' => '910135']],
            ]),
            'api.hubapi.com/crm/v3/objects/line_items/910135*' => Http::response([
                'id'         => '910135',
                'properties' => [
                    'name'                       => 'MAP-135',
                    'price'                      => '3000',
                    'recurringbillingfrequency'  => 'monthly',
                ],
            ]),
            "api.hubapi.com/crm/v3/objects/companies/{$companyHubId}*" => Http::response([
                'id'         => (string) $companyHubId,
                'properties' => [
                    'name'  => 'Empresa Webhook Onboarding 135 LTDA',
                    'cnpj'  => '77777777000107',
                    'email' => 'webhook135@cliente.com',
                    'phone' => '11999990107',
                ],
            ]),
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}*" => Http::response([
                'id'         => (string) $dealId,
                'properties' => ['dealname' => 'Cliente Onboarding Observer', 'dealstage' => 'closedwon'],
            ]),
        ]);

        $body = json_encode([[
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => $dealId,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => 'closedwon',
        ]]);
        $ts = (string) (int) (microtime(true) * 1000);

        $resp = $this->call('POST', self::HUBSPOT_URL, [], [], [], $this->servidorHubspot([
            'X-HubSpot-Signature-v3'      => $this->assinaturaHubspot($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);

        $resp->assertStatus(200);

        $company = Company::where('hubspot_company_id', (string) $companyHubId)->firstOrFail();
        $this->assertSame(1, ContratoServico::where('company_id', $company->id)->count());

        $this->assertOnboardingDeGestaoEmRascunho($company, $servico);
    }

    // ═══ 2. Cadastro Comercial — ComercialController::store() ═══

    public function test_comercial_store_dispara_observer_e_gera_onboarding_em_rascunho(): void
    {
        $this->actingAsAdmin();
        $servico = $this->servicoDeGestao();

        $resp = $this->post(route('comercial.empresas.store'), [
            'nome'     => 'Empresa Comercial Onboarding ' . uniqid(),
            'servicos' => [
                ['servico_id' => $servico->id],
            ],
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $company = Company::whereHas('contratosServico', fn ($q) => $q->where('servico_id', $servico->id))
            ->latest('id')
            ->firstOrFail();

        $this->assertOnboardingDeGestaoEmRascunho($company, $servico);
    }

    // ═══ 3. Contrato avulso na ficha da empresa — CompanyController::storeContrato() ═══

    public function test_company_store_contrato_dispara_observer_e_gera_onboarding_em_rascunho(): void
    {
        $this->actingAsAdmin();
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();

        $resp = $this->post(route('empresas.contratos.store', $company), [
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $this->assertOnboardingDeGestaoEmRascunho($company, $servico);
    }

    // ═══ 4. Atribuição em massa por grupo — CompanyGroupController::atribuirServico() ═══

    public function test_atribuir_servico_por_grupo_com_3_empresas_gera_3_onboardings_sem_rede(): void
    {
        Http::fake();

        $this->actingAsAdmin();
        $servico = $this->servicoDeGestao();

        $group = CompanyGroup::create(['name' => 'Grupo Onboarding ' . uniqid(), 'color' => '#ffe600']);
        $companies = Company::factory()->count(3)->create(['company_group_id' => $group->id]);

        $resp = $this->post(route('company-groups.atribuir-servico', $group), [
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $this->assertSame(3, ContratoServico::where('servico_id', $servico->id)->count());
        $this->assertSame(3, Onboarding::where('servico_id', $servico->id)->count());

        foreach ($companies as $company) {
            $this->assertOnboardingDeGestaoEmRascunho($company, $servico);
        }

        // Pitfall 5 — N contratos em loop sem transação: o Observer não pode
        // fazer NENHUMA chamada de rede, mesmo repetido 3 vezes na mesma request.
        Http::assertNothingSent();
    }

    // ═══ Negativo — D-08: serviço sem template publicado não cria onboarding ═══

    public function test_contrato_de_servico_sem_template_publicado_nao_cria_onboarding(): void
    {
        $this->actingAsAdmin();

        $servicoSemTemplate = Servico::create([
            'nome'          => 'Serviço sem template ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        $company = Company::factory()->create();

        $resp = $this->post(route('empresas.contratos.store', $company), [
            'servico_id'       => $servicoSemTemplate->id,
            'valor_contratado' => 500,
            'data_contratacao' => now()->toDateString(),
        ]);

        $resp->assertSessionHasNoErrors();

        $this->assertSame(1, ContratoServico::where('servico_id', $servicoSemTemplate->id)->count());
        $this->assertSame(0, Onboarding::where('servico_id', $servicoSemTemplate->id)->count());
    }

    /**
     * Quantos passos a definição de Gestão tem AGORA. Lido da própria fonte —
     * um passo novo não pode quebrar testes que não falam sobre contagem.
     */
    private function totalDePassosDaDefinicao(): int
    {
        return count(\App\Support\Onboarding\DefinicaoOnboarding::paraServico($this->servicoDeGestao()));
    }
}
