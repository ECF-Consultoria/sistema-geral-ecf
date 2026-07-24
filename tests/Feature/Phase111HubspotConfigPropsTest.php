<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Suite Feature — Phase 111 Plan 111-01 (HUB-API-01).
 *
 * Prova que `config('services.hubspot.props')` ganhou as novas chaves de
 * deal/company/contact do handoff Comercial v20.0 SEM perder as antigas
 * (consumidor legado do HubspotWebhookController acessa por chave nomeada).
 *
 * Não usa RefreshDatabase — só lê config, sem tocar em banco.
 */
class Phase111HubspotConfigPropsTest extends TestCase
{
    public function test_props_deal_mantem_chaves_antigas_e_ganha_as_novas(): void
    {
        $deal = config('services.hubspot.props.deal');

        // Chaves antigas (Phase 34/35) — nunca podem sumir.
        $this->assertArrayHasKey('nicho', $deal);
        $this->assertArrayHasKey('dor', $deal);
        $this->assertArrayHasKey('vende_ml', $deal);
        $this->assertArrayHasKey('faturamento_mensal', $deal);
        $this->assertArrayHasKey('servico', $deal);

        // Chaves novas (Phase 111).
        $this->assertArrayHasKey('observacao', $deal);
        $this->assertArrayHasKey('description', $deal);
        $this->assertArrayHasKey('closed_won_reason', $deal);
        $this->assertArrayHasKey('closedate', $deal);
        $this->assertArrayHasKey('pipeline', $deal);
        $this->assertArrayHasKey('hs_mrr', $deal);
        $this->assertArrayHasKey('hs_arr', $deal);
        $this->assertArrayHasKey('hs_tcv', $deal);
        $this->assertArrayHasKey('hs_acv', $deal);
        $this->assertArrayHasKey('hs_currency', $deal);
    }

    public function test_props_company_mantem_chaves_antigas_e_ganha_as_novas(): void
    {
        $company = config('services.hubspot.props.company');

        $this->assertArrayHasKey('name', $company);
        $this->assertArrayHasKey('cnpj', $company);
        $this->assertArrayHasKey('email', $company);
        $this->assertArrayHasKey('phone', $company);

        $this->assertArrayHasKey('domain', $company);
        $this->assertArrayHasKey('industry', $company);
        $this->assertArrayHasKey('annualrevenue', $company);
        $this->assertArrayHasKey('city', $company);
        $this->assertArrayHasKey('state', $company);
        $this->assertArrayHasKey('country', $company);
    }

    public function test_props_contact_mantem_chaves_antigas_e_ganha_as_novas(): void
    {
        $contact = config('services.hubspot.props.contact');

        $this->assertArrayHasKey('firstname', $contact);
        $this->assertArrayHasKey('lastname', $contact);
        $this->assertArrayHasKey('email', $contact);
        $this->assertArrayHasKey('phone', $contact);

        $this->assertArrayHasKey('mobilephone', $contact);
        $this->assertArrayHasKey('jobtitle', $contact);
        $this->assertArrayHasKey('additional_emails', $contact);
    }

    public function test_defaults_seguros_sem_env_configurado(): void
    {
        $this->assertSame('hs_additional_emails', config('services.hubspot.props.contact.additional_emails'));
        $this->assertSame('hs_mrr', config('services.hubspot.props.deal.hs_mrr'));
        $this->assertSame('domain', config('services.hubspot.props.company.domain'));
    }
}
