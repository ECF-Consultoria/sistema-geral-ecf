<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Notifications\EmpresaHubspotPendenteNotification;
use App\Support\AudienciaComercial;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Suite Feature — Phase 35 Plan 35-03 (Notificacao Comercial pos-webhook).
 *
 * Cobre:
 *  T1. Empresa criada via webhook SEM pendencias → Notification nao disparada.
 *  T2. Empresa criada via webhook COM pendencias → Notification enviada a
 *      audiencia (lideres do setor Comercial + users com permission).
 *  T3. Audiencia contem 2 tipos: lider via setor_lideres + permissionado via
 *      setor_permissoes — ambos recebem 1 notification.
 *  T4. Idempotencia: 2 webhooks do mesmo deal — apenas 1 notification disparada
 *      (2o cai como "ignorado" antes do dispatch — Plan 34-04 D-04).
 *
 * IMPORTANTE: usa Http::fake para impedir tentativas reais a api.hubapi.com.
 */
class Phase35HubspotNotifyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-35-zzzzzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        // Config previsivel — mesmo padrao da Phase34HubspotWebhookTest.
        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => 'closedwon',
            'services.hubspot.props.deal' => [
                'nicho'              => 'nicho',
                'dor'                => 'dor',
                'vende_ml'           => 'vende_ml',
                'faturamento_mensal' => 'faturamento_mensal',
                'servico'            => 'servico_ecf',
            ],
            'services.hubspot.props.company' => [
                'name'  => 'name',
                'cnpj'  => 'cnpj',
                'email' => 'email',
                'phone' => 'phone',
            ],
            'services.hubspot.props.contact' => [
                'firstname' => 'firstname',
                'lastname'  => 'lastname',
                'email'     => 'email',
                'phone'     => 'phone',
            ],
        ]);

        // Garante servico para vincular ContratoServico no webhook.
        Servico::firstOrCreate(
            ['nome' => 'Mentoria Avançada'],
            ['valor_padrao' => 999.99, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true],
        );
    }

    /**
     * Calcula assinatura v3 da mesma forma que o controller.
     */
    private function assinatura(string $body, string $ts, string $secret = self::SECRET): string
    {
        $url           = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));
    }

    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    private function eventoPadrao(array $overrides = []): array
    {
        return array_merge([
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => 9876,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => 'closedwon',
        ], $overrides);
    }

    /**
     * Mocks padrao do HubSpot — deal + company + contact + associacoes.
     * Subscribers podem sobrescrever com Http::fake() depois pra cenarios
     * especificos, mas todos os 4 mocks ficam disponiveis.
     */
    private function fakeHubspot(array $companyProps = [], array $contactProps = []): void
    {
        $defaultCompany = array_merge([
            'name'  => 'Cliente Teste LTDA',
            'cnpj'  => '12345678000199',
            'email' => 'contato@cliente.com',
            'phone' => '11999998888',
        ], $companyProps);

        $defaultContact = array_merge([
            'firstname' => 'João',
            'lastname'  => 'Silva',
            'email'     => 'joao@cliente.com',
            'phone'     => '11999991111',
        ], $contactProps);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 55501]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts' => Http::response([
                'results' => [['toObjectId' => 77701]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => [
                    'dealname'    => 'Cliente Teste LTDA',
                    'amount'      => '1500.00',
                    'dealstage'   => 'closedwon',
                    'servico_ecf' => 'Mentoria Avançada',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/55501*' => Http::response([
                'id'         => '55501',
                'properties' => $defaultCompany,
            ]),
            'api.hubapi.com/crm/v3/objects/contacts/77701*' => Http::response([
                'id'         => '77701',
                'properties' => $defaultContact,
            ]),
        ]);
    }

    /**
     * Helper: dispara 1 POST valido para o webhook (HMAC OK + ts atual).
     */
    private function postWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Setup de audiencia minima: 1 lider do setor Comercial via setor_lideres.
     * Garante que ha 1 destinatario nao-admin para Notification::send.
     */
    private function criarSetorComercialComLider(): User
    {
        $setor = Setor::firstOrCreate(
            ['slug' => 'comercial'],
            ['nome' => 'Comercial', 'active' => true, 'is_system' => false],
        );

        // Vincula permission comercial.cadastrar_empresa ao setor (espelha seed
        // da migration 2026_05_25_100003).
        SetorPermissao::firstOrCreate([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::COMERCIAL_CADASTRAR_EMPRESA,
        ]);

        $lider = User::factory()->create([
            'role'   => 'consultor',
            'active' => true,
        ]);
        $setor->lideres()->attach($lider->id, [
            'assigned_at' => now(),
        ]);

        return $lider;
    }

    // ─── T1: empresa completa nao notifica ───────────────────────────────────

    /**
     * Empresa SEM pendencias nao deve gerar notificacao.
     *
     * Como o webhook sempre cria empresa com pendencias (sem responsavel, sem
     * cust_id, sem email_colaborador), testamos a regra direto via
     * AudienciaComercial + EmpresaHubspotPendenteNotification: criamos
     * manualmente uma empresa completa, chamamos a logica de calcularPendencias
     * via reflection (helper privado) e validamos que o dispatch nao ocorre.
     */
    public function test_empresa_completa_nao_notifica(): void
    {
        Notification::fake();
        $this->fakeHubspot();
        $this->criarSetorComercialComLider();

        // Cria empresa "completa" antes — vai entrar em conflito? Nao, o webhook
        // cria com object_id 9876 → company_id novo. Mas vamos validar que a
        // empresa criada SEM os campos abaixo gera notification (T2), e neste
        // T1 verificar diretamente que calcularPendencias retorna [] quando a
        // empresa tem todos os campos.

        $servico = Servico::where('nome', 'Mentoria Avançada')->first();
        $responsavel = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $company = Company::create([
            'name'              => 'Empresa Completa',
            'email_colaborador' => 'colab@ecfconsultoria.com.br',
            'adman_account_id'  => '12345',
            'empresa_nova'      => true,
            'status'            => 'pendente',
            'active'            => true,
        ]);
        $company->consultor()->attach($responsavel->id, [
            'role'        => 'consultor',
            'assigned_at' => now(),
        ]);
        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 100.0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        // Invoca calcularPendencias via reflection — helper privado.
        $controller = new \App\Http\Controllers\Api\HubspotWebhookController();
        $metodo = (new \ReflectionClass($controller))->getMethod('calcularPendencias');
        $metodo->setAccessible(true);
        $pendencias = $metodo->invoke($controller, $company);

        $this->assertSame([], $pendencias, 'Empresa com responsavel + cust_id + email_colaborador + servico ativo nao deve ter pendencias.');

        // E mais: confirma que `notificarComercialSePendente` (que chama
        // calcularPendencias internamente) nao envia nada nesse caso.
        $evento = HubspotEvento::create([
            'signature_valid' => true,
            'object_id'       => 'fake',
            'status'          => 'processado',
            'payload'         => [],
        ]);
        $metodoDispatch = (new \ReflectionClass($controller))->getMethod('notificarComercialSePendente');
        $metodoDispatch->setAccessible(true);
        $metodoDispatch->invoke($controller, $company, $evento);

        Notification::assertNothingSent();
    }

    // ─── T2: empresa com pendencias notifica ─────────────────────────────────

    /**
     * Webhook cria empresa "crua" (sem responsavel, sem cust_id, sem
     * email_colaborador) — mas COM servico via ContratoServico. Ainda assim
     * tem ao menos 3 pendencias → notification disparada para a audiencia.
     */
    public function test_empresa_sem_responsavel_notifica(): void
    {
        Notification::fake();
        $this->fakeHubspot();
        $lider = $this->criarSetorComercialComLider();

        $resposta = $this->postWebhook([$this->eventoPadrao()]);
        $resposta->assertStatus(200);

        // Empresa criada com pendencias (sem responsavel, sem cust_id, sem email_colaborador).
        $company = Company::where('name', 'Cliente Teste LTDA')->first();
        $this->assertNotNull($company);

        Notification::assertSentTo(
            $lider,
            EmpresaHubspotPendenteNotification::class,
            function ($notification) use ($company) {
                $meta = $notification->meta ?? [];
                return ($meta['company_id'] ?? null) === $company->id
                    && ($meta['fonte'] ?? null) === 'hubspot'
                    && in_array('sem_responsavel', $meta['pendencias'] ?? [], true)
                    && in_array('sem_cust_id', $meta['pendencias'] ?? [], true)
                    && in_array('sem_email_colaborador', $meta['pendencias'] ?? [], true);
            },
        );
    }

    // ─── T3: audiencia inclui lider + permissionado ─────────────────────────

    /**
     * Audiencia une 2 fontes distintas:
     *   - Lider via setor_lideres (sem ser membro do setor)
     *   - Permissionado via membro do setor Comercial (tem
     *     permission `comercial.cadastrar_empresa` via setor_permissoes)
     * Ambos devem receber 1 notification.
     */
    public function test_audiencia_inclui_lideres_e_permissionados(): void
    {
        Notification::fake();
        $this->fakeHubspot();

        $setor = Setor::firstOrCreate(
            ['slug' => 'comercial'],
            ['nome' => 'Comercial', 'active' => true, 'is_system' => false],
        );
        SetorPermissao::firstOrCreate([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::COMERCIAL_CADASTRAR_EMPRESA,
        ]);

        // (a) Lider — via setor_lideres (nao membro).
        $lider = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->lideres()->attach($lider->id, ['assigned_at' => now()]);

        // (b) Permissionado — membro do setor Comercial (herda a permission
        // via setor_permissoes pelo effectivePermissions).
        $permissionado = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($permissionado->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        // Sanity: AudienciaComercial cobre os 2.
        $audiencia = AudienciaComercial::lideresEPermissionados();
        $ids = $audiencia->pluck('id')->all();
        $this->assertContains($lider->id, $ids, 'Lider do setor Comercial deve estar na audiencia');
        $this->assertContains($permissionado->id, $ids, 'Membro do setor Comercial (com permission) deve estar na audiencia');

        $resposta = $this->postWebhook([$this->eventoPadrao()]);
        $resposta->assertStatus(200);

        Notification::assertSentTo($lider, EmpresaHubspotPendenteNotification::class);
        Notification::assertSentTo($permissionado, EmpresaHubspotPendenteNotification::class);
    }

    // ─── T4: idempotencia — 2 webhooks, 1 notification ──────────────────────

    /**
     * 2 webhooks do mesmo deal (objectId 9876) — Plan 34-04 marca o 2o como
     * ignorado antes de chamar criarEmpresa. Logo, dispatch da notification
     * acontece SOMENTE no 1o evento processado.
     */
    public function test_idempotencia_retry_nao_dispara_segunda_notificacao(): void
    {
        Notification::fake();
        $this->fakeHubspot();
        $lider = $this->criarSetorComercialComLider();

        // 1a entrega.
        $this->postWebhook([$this->eventoPadrao()])->assertStatus(200);

        // 2a entrega (mesmo objectId, novo ts/sig).
        $this->postWebhook([$this->eventoPadrao()])->assertStatus(200);

        // Apenas 1 Company criada.
        $this->assertSame(1, Company::where('name', 'Cliente Teste LTDA')->count());

        // 2 eventos no banco: 1 processado, 1 ignorado.
        $this->assertSame(1, HubspotEvento::where('status', 'processado')->count());
        $this->assertSame(1, HubspotEvento::where('status', 'ignorado')->count());

        // Apenas 1 EmpresaHubspotPendenteNotification disparada para o lider.
        Notification::assertSentToTimes(
            $lider,
            EmpresaHubspotPendenteNotification::class,
            1,
        );
    }
}
