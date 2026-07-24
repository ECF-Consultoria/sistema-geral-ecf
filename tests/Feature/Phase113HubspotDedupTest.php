<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Hubspot\HubspotCompanyMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Phase 113 Plan 113-03 (HUB-DEDUP-01/02).
 *
 * Tarefa 1 (unit-style, RefreshDatabase): cobre `HubspotCompanyMatcher::encontrar()`
 * isoladamente — ordem de precedência hubspot_company_id > cnpj > email > domain >
 * nome normalizado, classificação forte/fraco e anti-falso-positivo.
 *
 * Tarefa 3 (E2E via webhook, adicionada depois): dedup real disparada pelo
 * `HubspotWebhookController` — match forte (cnpj/hubspot_company_id) enriquece
 * sem duplicar; match fraco (nome) cria empresa nova + grava warning
 * `possivel_duplicidade` no payload do evento.
 */
class Phase113HubspotDedupTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tarefa 1 — HubspotCompanyMatcher (unit-style) ─────────────────────────

    public function test_nenhum_criterio_bate_retorna_sem_match(): void
    {
        Company::factory()->create(['name' => 'Empresa Qualquer', 'cnpj' => '11222333000144']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => 'hs-999',
            'cnpj'               => '99988877000166',
            'email'              => 'naoexiste@teste.com',
            'domain'             => 'naoexiste.com.br',
            'name'               => 'Nome Completamente Diferente',
        ]);

        $this->assertNull($resultado['company']);
        $this->assertNull($resultado['match']);
        $this->assertNull($resultado['via']);
    }

    public function test_match_por_hubspot_company_id_e_forte(): void
    {
        $company = Company::factory()->create(['hubspot_company_id' => 'hs-12345']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => 'hs-12345',
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('forte', $resultado['match']);
        $this->assertSame('hubspot_company_id', $resultado['via']);
    }

    public function test_match_por_cnpj_e_forte_com_formatacao_diferente(): void
    {
        $company = Company::factory()->create(['cnpj' => '11.222.333/0001-44']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'cnpj' => '11222333000144', // mesmos digitos, sem formatacao
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('forte', $resultado['match']);
        $this->assertSame('cnpj', $resultado['via']);
    }

    public function test_match_por_email_e_fraco(): void
    {
        $company = Company::factory()->create(['email_cliente' => 'contato@empresateste.com']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'email' => 'contato@empresateste.com',
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('fraco', $resultado['match']);
        $this->assertSame('email', $resultado['via']);
    }

    public function test_match_por_domain_e_fraco(): void
    {
        $company = Company::factory()->create(['hubspot_domain' => 'empresateste.com.br']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'domain' => 'empresateste.com.br',
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('fraco', $resultado['match']);
        $this->assertSame('domain', $resultado['via']);
    }

    public function test_match_por_nome_normalizado_e_fraco(): void
    {
        $company = Company::factory()->create(['name' => 'Padaria do José Ltda.']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'name' => 'PADARIA DO JOSE LTDA', // sem acento/pontuacao/caixa diferente
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('fraco', $resultado['match']);
        $this->assertSame('nome', $resultado['via']);
    }

    public function test_nomes_distintos_nao_casam_por_nome(): void
    {
        Company::factory()->create(['name' => 'Padaria do Zé']);
        Company::factory()->create(['name' => 'Padaria da Ana']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'name' => 'Padaria do João',
        ]);

        $this->assertNull($resultado['company']);
        $this->assertNull($resultado['match']);
    }

    public function test_precedencia_hubspot_company_id_sobre_cnpj(): void
    {
        $viaId   = Company::factory()->create(['hubspot_company_id' => 'hs-77', 'cnpj' => '11111111000111']);
        $viaCnpj = Company::factory()->create(['cnpj' => '22222222000122']);

        // Critérios batem em AMBAS (hs-77 na primeira, cnpj só existe na segunda,
        // mas o cnpj informado é o da PRIMEIRA — garante que hubspot_company_id
        // vence antes mesmo de avaliar o cnpj).
        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => 'hs-77',
            'cnpj'               => '11111111000111',
        ]);

        $this->assertSame($viaId->id, $resultado['company']->id);
        $this->assertSame('hubspot_company_id', $resultado['via']);
    }

    public function test_precedencia_cnpj_sobre_email(): void
    {
        $viaCnpj = Company::factory()->create(['cnpj' => '33333333000133', 'email_cliente' => 'x@x.com']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'cnpj'  => '33333333000133',
            'email' => 'x@x.com',
        ]);

        $this->assertSame($viaCnpj->id, $resultado['company']->id);
        $this->assertSame('forte', $resultado['match']);
        $this->assertSame('cnpj', $resultado['via']);
    }

    public function test_criterios_vazios_nao_geram_match_espurio(): void
    {
        // Empresa sem cnpj/email/domain preenchidos (null) — critérios vazios
        // no lado do HubSpot NUNCA devem "casar" com colunas vazias no banco.
        Company::factory()->create(['cnpj' => null, 'email_cliente' => null, 'hubspot_domain' => null, 'name' => 'Empresa X']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => null,
            'cnpj'               => '',
            'email'              => '',
            'domain'             => '',
            'name'               => '',
        ]);

        $this->assertNull($resultado['company']);
        $this->assertNull($resultado['match']);
    }
}
