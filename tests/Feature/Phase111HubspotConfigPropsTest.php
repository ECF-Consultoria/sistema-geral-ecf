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

        // Chave antiga (Phase 34/35) que sobreviveu.
        $this->assertArrayHasKey('servico', $deal);

        // Quick task 260805-eqk — nicho/dor/vende_ml/faturamento_mensal saíram:
        // as properties não existem na conta HubSpot da ECF e as colunas
        // correspondentes foram removidas de `companies`.
        $this->assertArrayNotHasKey('nicho', $deal);
        $this->assertArrayNotHasKey('dor', $deal);
        $this->assertArrayNotHasKey('vende_ml', $deal);
        $this->assertArrayNotHasKey('faturamento_mensal', $deal);

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

        // Quick task 260805-eqk — origem do lead (e irmãs) vivem no CONTATO.
        $this->assertArrayHasKey('origem_do_lead', $contact);
        $this->assertArrayHasKey('campanha_origem', $contact);
        $this->assertArrayHasKey('criativo_origem', $contact);
    }

    /**
     * Quick task 260805-eqk — as Notes (observações) do deal ganharam bloco
     * próprio de props, porque a property `observacao` não existe no HubSpot.
     */
    public function test_props_note_existe_com_body_e_timestamp(): void
    {
        $note = config('services.hubspot.props.note');

        $this->assertIsArray($note);
        $this->assertArrayHasKey('body', $note);
        $this->assertArrayHasKey('timestamp', $note);
        $this->assertSame('hs_note_body', $note['body']);
        $this->assertSame('hs_timestamp', $note['timestamp']);
    }

    public function test_defaults_seguros_sem_env_configurado(): void
    {
        $this->assertSame('hs_additional_emails', config('services.hubspot.props.contact.additional_emails'));
        $this->assertSame('origem_do_lead', config('services.hubspot.props.contact.origem_do_lead'));
        $this->assertSame('hs_mrr', config('services.hubspot.props.deal.hs_mrr'));
        $this->assertSame('domain', config('services.hubspot.props.company.domain'));
    }
}
