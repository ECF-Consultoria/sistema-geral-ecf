<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubspotEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Phase 113 Plan 113-02 (HUB-CONTATO-01/02 + HUB-DEDUP-03).
 *
 * Cobre o `<behavior>` da Tarefa 3 do plano:
 *  T1. Deal com 3 contatos (um com email+telefone) → nome_contato/cargo_contato
 *      vêm do contato com email+telefone; hubspot_contact_id = id dele.
 *  T2. Company sem phone → companies.telefone = contact.mobilephone.
 *  T3. companies.hubspot_snapshot é array e contém: deal, company, contacts
 *      (TODOS os 3, não só o principal), primary_contact_id, line_items,
 *      warnings, captured_at.
 *  T4. hubspot_deal_id/hubspot_company_id/hubspot_contact_id gravados.
 *
 * Reusa o padrão `Http::fake` de Phase35HubspotV2Test/Phase112HubspotHandoffWebhookTest
 * (POST + HMAC v3 calculado manualmente), mas com mock de MÚLTIPLOS contatos
 * (3 ids na association + 3 respostas de contacts/{id}).
 */
class Phase113HubspotEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

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
                'observacao'         => 'observacao',
            ],
            'services.hubspot.props.company' => [
                'name'   => 'name',
                'cnpj'   => 'cnpj',
                'email'  => 'email',
                'phone'  => 'phone',
                'domain' => 'domain',
            ],
            'services.hubspot.props.contact' => [
                'firstname'   => 'firstname',
                'lastname'    => 'lastname',
                'email'       => 'email',
                'phone'       => 'phone',
                'mobilephone' => 'mobilephone',
                'jobtitle'    => 'jobtitle',
            ],
        ]);
    }

    /**
     * Calcula assinatura v3 do mesmo jeito do controller:
     *   base64(hmac_sha256(secret, METHOD + URI + body + timestamp))
     */
    private function assinatura(string $body, string $ts): string
    {
        $url           = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::SECRET, true));
    }

    /**
     * Converte headers em formato $_SERVER (HTTP_X_*) — necessario quando
     * usamos $this->call() direto.
     */
    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    /**
     * Evento HubSpot padrao (overridable via $overrides).
     */
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
     * Helper p/ disparar o webhook com payload padrao + signature valida.
     */
    private function disparaWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Mocka deal + company (sem phone, com domain) + 3 contatos associados:
     *  - 301: sem email/phone/mobilephone (tier 0)
     *  - 302: email + mobilephone (sem phone) → tier 3 (o PRINCIPAL esperado)
     *  - 303: so email (tier 2)
     */
    private function mockaHubSpotComTresContatos(): void
    {
        $fakes = [];

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
            'results' => [['toObjectId' => 88001]],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/companies/88001*'] = Http::response([
            'id'         => '88001',
            'properties' => [
                'name'   => 'Cliente Multi Contato',
                'cnpj'   => '77788899000111',
                'email'  => 'financeiro@clientemulti.com',
                'phone'  => '', // Company SEM phone — forca fallback pro contato principal
                'domain' => 'clientemulti.com.br',
            ],
        ]);

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'] = Http::response([
            'results' => [
                ['toObjectId' => 301],
                ['toObjectId' => 302],
                ['toObjectId' => 303],
            ],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/contacts/301*'] = Http::response([
            'id'         => '301',
            'properties' => [
                'firstname' => 'Carlos',
                'lastname'  => 'Neutro',
            ],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/contacts/302*'] = Http::response([
            'id'         => '302',
            'properties' => [
                'firstname'   => 'Ana',
                'lastname'    => 'Costa',
                'email'       => 'ana@clientemulti.com',
                'mobilephone' => '11977776666',
                'jobtitle'    => 'Gerente Comercial',
            ],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/contacts/303*'] = Http::response([
            'id'         => '303',
            'properties' => [
                'firstname' => 'Pedro',
                'lastname'  => 'Só Email',
                'email'     => 'pedro@clientemulti.com',
            ],
        ]);

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items'] = Http::response([
            'results' => [],
        ]);

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => [
                'dealname'    => 'Cliente Multi Contato',
                'amount'      => '1000',
                'dealstage'   => 'closedwon',
                'servico_ecf' => 'Servico Inexistente',
                'observacao'  => 'Cliente estrategico — prioridade alta',
            ],
        ]);

        Http::fake($fakes);
    }

    public function test_contato_principal_escolhido_entre_3_e_campos_estruturados_gravados(): void
    {
        $this->mockaHubSpotComTresContatos();

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        $company = Company::first();
        $this->assertNotNull($company);

        // T1 — contato principal (302, tier 3: email + mobilephone) escolhido
        // entre os 3; nome_contato/cargo_contato/hubspot_contact_id conferem.
        $this->assertSame('Ana Costa', $company->nome_contato);
        $this->assertSame('Gerente Comercial', $company->cargo_contato);
        $this->assertSame('302', $company->hubspot_contact_id);

        // T2 — Company sem phone (phone='') usa contact.mobilephone (302 nao
        // tem 'phone', so 'mobilephone').
        $this->assertSame('11977776666', $company->telefone);

        // T4 — IDs HubSpot estruturados.
        $this->assertSame('9876', $company->hubspot_deal_id);
        $this->assertSame('88001', $company->hubspot_company_id);
        $this->assertSame('clientemulti.com.br', $company->hubspot_domain);
        $this->assertSame('Cliente estrategico — prioridade alta', $company->hubspot_observacao);

        // Linha legada em notes continua existindo (fonte legada preservada).
        $this->assertStringContainsString('Contato (HubSpot): Ana Costa', (string) $company->notes);
    }

    public function test_snapshot_completo_contem_todos_os_contatos_e_metadados(): void
    {
        $this->mockaHubSpotComTresContatos();

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $this->assertNotNull($company);

        $snapshot = $company->hubspot_snapshot;
        $this->assertIsArray($snapshot);

        // T3 — snapshot contem deal/company/TODOS os contatos/line_items/warnings/captured_at.
        $this->assertArrayHasKey('deal', $snapshot);
        $this->assertArrayHasKey('company', $snapshot);
        $this->assertArrayHasKey('contacts', $snapshot);
        $this->assertArrayHasKey('primary_contact_id', $snapshot);
        $this->assertArrayHasKey('line_items', $snapshot);
        $this->assertArrayHasKey('warnings', $snapshot);
        $this->assertArrayHasKey('captured_at', $snapshot);

        $this->assertCount(3, $snapshot['contacts'], 'Snapshot deve conter TODOS os contatos, nao so o principal');
        $this->assertSame('302', $snapshot['primary_contact_id']);
        $this->assertSame('Cliente Multi Contato', $snapshot['deal']['dealname'] ?? null);
        $this->assertSame('Cliente Multi Contato', $snapshot['company']['name'] ?? null);
        $this->assertNotEmpty($snapshot['captured_at']);

        // Servico "Servico Inexistente" nao bate no catalogo → warning legado
        // vira nota (motivo servico_nao_encontrado), nao entra em warnings do
        // snapshot com esse shape — mas o array warnings deve existir (pode
        // estar vazio aqui pois o motivo servico_nao_encontrado e tratado via
        // notes no persistirContratos, nao fica pendente no snapshot).
        $this->assertIsArray($snapshot['warnings']);
    }

    public function test_handoff_data_company_e_contact_preenchidos(): void
    {
        // Cobertura indireta de HUB-CONTATO-01/02 no DTO: o snapshot['company']
        // e o nome_contato estruturado só existem se HubspotDealHandoffService
        // recebeu e normalizou hubCompany/contatos (company_data/contact_data
        // deixam de ser null quando ha company/contatos).
        $this->mockaHubSpotComTresContatos();

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $this->assertNotNull($company->nome_contato);
        $this->assertNotNull($company->hubspot_snapshot['company']);
    }
}
