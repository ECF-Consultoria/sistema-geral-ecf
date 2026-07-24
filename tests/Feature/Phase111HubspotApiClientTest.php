<?php

namespace Tests\Feature;

use App\Services\HubspotApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Suite Feature — Phase 111 Plan 111-02 (HUB-API-03).
 *
 * Cobre os 5 metodos NOVOS de HubspotApiClient (associacoes plurais + batch),
 * que coexistem com os metodos singulares atuais (Phase 34/35) sem quebra-los:
 *   - fetchAssociatedCompanyIds(dealId)      — TODOS os company IDs associados
 *   - fetchAssociatedContactIds(dealId)      — TODOS os contact IDs associados
 *   - fetchAssociations(fromObject, fromId, toObject) — GET generico de associacoes
 *   - fetchCompanies(ids, properties)        — multiplas companies
 *   - fetchContacts(ids, properties)         — multiplos contacts
 *
 * Estrategia de teste: Http::fake mocka os endpoints v3; HubspotApiClient
 * instanciado com token explicito 'fake-token'; sem RefreshDatabase (HTTP
 * wrapper puro). Nenhuma chamada real ao HubSpot.
 */
class Phase111HubspotApiClientTest extends TestCase
{
    private const DEAL_ID = '999';

    private function client(): HubspotApiClient
    {
        return new HubspotApiClient(token: 'fake-token');
    }

    // ─── fetchAssociatedCompanyIds ───

    public function test_fetch_associated_company_ids_retorna_todos_os_ids(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/companies' => Http::response([
                'results' => [
                    ['toObjectId' => 111, 'type' => 'deal_to_company'],
                    ['toObjectId' => 222, 'type' => 'deal_to_company'],
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchAssociatedCompanyIds(self::DEAL_ID);

        $this->assertSame(['111', '222'], $out);
    }

    public function test_fetch_associated_company_ids_404_retorna_array_vazio(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/companies' => Http::response([], 404),
        ]);

        $this->assertSame([], $this->client()->fetchAssociatedCompanyIds(self::DEAL_ID));
    }

    public function test_fetch_associated_company_ids_500_retorna_array_vazio(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/companies' => Http::response([], 500),
        ]);

        $this->assertSame([], $this->client()->fetchAssociatedCompanyIds(self::DEAL_ID));
    }

    // ─── fetchAssociatedContactIds ───

    public function test_fetch_associated_contact_ids_retorna_todos_os_ids(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/contacts' => Http::response([
                'results' => [
                    ['toObjectId' => 333, 'type' => 'deal_to_contact'],
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchAssociatedContactIds(self::DEAL_ID);

        $this->assertSame(['333'], $out);
    }

    public function test_fetch_associated_contact_ids_404_retorna_array_vazio(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/contacts' => Http::response([], 404),
        ]);

        $this->assertSame([], $this->client()->fetchAssociatedContactIds(self::DEAL_ID));
    }

    // ─── fetchAssociations (metodo generico) ───

    public function test_fetch_associations_monta_url_e_retorna_results(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/line_items' => Http::response([
                'results' => [
                    ['toObjectId' => 555, 'type' => 'deal_to_line_item'],
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchAssociations('deals', self::DEAL_ID, 'line_items');

        $this->assertSame([['toObjectId' => 555, 'type' => 'deal_to_line_item']], $out);
    }

    public function test_fetch_associations_falha_retorna_array_vazio(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/line_items' => Http::response([], 500),
        ]);

        $this->assertSame([], $this->client()->fetchAssociations('deals', self::DEAL_ID, 'line_items'));
    }

    // ─── fetchCompanies ───

    public function test_fetch_companies_retorna_mapa_id_para_properties(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/companies/111*' => Http::response([
                'id'         => '111',
                'properties' => ['name' => 'Empresa A', 'domain' => 'a.com'],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/companies/222*' => Http::response([
                'id'         => '222',
                'properties' => ['name' => 'Empresa B', 'domain' => 'b.com'],
            ], 200),
        ]);

        $out = $this->client()->fetchCompanies(['111', '222'], ['name', 'domain']);

        $this->assertSame([
            '111' => ['name' => 'Empresa A', 'domain' => 'a.com'],
            '222' => ['name' => 'Empresa B', 'domain' => 'b.com'],
        ], $out);
    }

    public function test_fetch_companies_ids_vazio_retorna_array_vazio(): void
    {
        Http::fake();

        $this->assertSame([], $this->client()->fetchCompanies([], ['name']));
    }

    public function test_fetch_companies_pula_id_com_falha_individual(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/companies/111*' => Http::response([
                'id'         => '111',
                'properties' => ['name' => 'Empresa A'],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/companies/222*' => Http::response([], 404),
        ]);

        $out = $this->client()->fetchCompanies(['111', '222'], ['name']);

        $this->assertSame(['111' => ['name' => 'Empresa A']], $out);
    }

    // ─── fetchContacts ───

    public function test_fetch_contacts_retorna_mapa_id_para_properties(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/333*' => Http::response([
                'id'         => '333',
                'properties' => ['firstname' => 'Joao', 'email' => 'joao@ex.com'],
            ], 200),
        ]);

        $out = $this->client()->fetchContacts(['333'], ['firstname', 'email']);

        $this->assertSame([
            '333' => ['firstname' => 'Joao', 'email' => 'joao@ex.com'],
        ], $out);
    }

    public function test_fetch_contacts_ids_vazio_retorna_array_vazio(): void
    {
        Http::fake();

        $this->assertSame([], $this->client()->fetchContacts([], ['firstname']));
    }

    // ─── Metodos singulares atuais continuam existindo (nao foram removidos) ───

    public function test_metodo_singular_fetch_associated_company_id_continua_existindo(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/companies' => Http::response([
                'results' => [['toObjectId' => 111]],
            ], 200),
        ]);

        $this->assertSame('111', $this->client()->fetchAssociatedCompanyId(self::DEAL_ID));
    }

    // ─── Nenhum token vaza em log (T-111-03) ───

    public function test_nenhum_metodo_novo_vaza_token_em_log_de_falha(): void
    {
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->withArgs(function ($message, $context = []) {
                $ctxJson = json_encode($context);
                return !str_contains((string) $message, 'fake-token')
                    && !str_contains((string) $message, 'Bearer')
                    && !str_contains($ctxJson, 'fake-token')
                    && !str_contains($ctxJson, 'Bearer ');
            })
            ->zeroOrMoreTimes();
        $logger->shouldReceive('info')->andReturnNull();

        Log::shouldReceive('channel')
            ->with('ecf-webhooks')
            ->andReturn($logger);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/companies' => Http::response([], 500),
            'https://api.hubapi.com/crm/v3/objects/companies/111*' => Http::response([], 500),
        ]);

        $this->assertSame([], $this->client()->fetchAssociatedCompanyIds(self::DEAL_ID));
        $this->assertSame([], $this->client()->fetchCompanies(['111'], ['name']));
    }
}
