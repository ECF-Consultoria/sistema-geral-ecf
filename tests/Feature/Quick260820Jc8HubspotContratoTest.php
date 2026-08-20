<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Quick task 260820-jc8 (HubSpot traz os dados do contrato
 * no ganho do negócio).
 *
 * Cobre a Tarefa 3 (gravação) ponta a ponta via webhook real (Http::fake +
 * assinatura HMAC v3, mesmo padrão de Phase113HubspotEnrichmentTest):
 *  T1. Deal ganho com as 8 props preenchidas + 1 line item mapeado → Company
 *      com razao_social/endereco compostos; ContratoServico com
 *      data_primeira_parcela/dia_vencimento corretos.
 *  T2. cnpj_da_empresa do deal como FALLBACK — company HubSpot sem cnpj →
 *      Company grava o cnpj do deal.
 *  T3. Empresa já existente (match forte) com razao_social/endereco/cnpj já
 *      preenchidos à mão → replay do webhook NÃO sobrescreve nenhum dos três.
 *  T4. Props ausentes/vazias → não quebra o fluxo; Company sem
 *      razao_social/endereco; ContratoServico com data_primeira_parcela e
 *      dia_vencimento null.
 *  T5. Deal com 2 line items mapeados → os 2 ContratoServico recebem a
 *      MESMA data_primeira_parcela/dia_vencimento.
 */
class Quick260820Jc8HubspotContratoTest extends TestCase
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
                'servico'             => 'servico_ecf',
                'cnpj_da_empresa'     => 'cnpj_da_empresa',
                'razao_social'        => 'razao_social',
                'logradouro'          => 'logradouro',
                'bairro'              => 'bairro',
                'cidade'              => 'cidade',
                'estado'              => 'estado',
                'cep'                 => 'cep',
                'data_do_1_pagamento' => 'data_do_1_pagamento',
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

        // RefreshDatabase pode aplicar seeds — isola os cenarios limpando as
        // tabelas de catalogo (mesmo padrao de Phase112HandoffServiceTest).
        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('servicos')->delete();
    }

    private function criarServico(string $nome): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 1000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
    }

    private function criarMapping(string $lineItemName, Servico $servico): HubspotLineItemMapping
    {
        return HubspotLineItemMapping::create([
            'line_item_name' => $lineItemName,
            'servico_id'     => $servico->id,
            'ativo'          => true,
        ]);
    }

    private function assinatura(string $body, string $ts): string
    {
        $url           = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::SECRET, true));
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
     * Mocka deal (dealProps sobrescreve as properties do deal) + company (sem
     * associação de contatos/notas, pra manter o mock enxuto) + 1 line item
     * mapeado 'MAP'.
     */
    private function mockaHubSpot(array $dealProps, ?array $companyProps = null): void
    {
        $fakes = [];

        if ($companyProps !== null) {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
                'results' => [['id' => '88001']],
            ]);
            $fakes['api.hubapi.com/crm/v3/objects/companies/88001*'] = Http::response([
                'id'         => '88001',
                'properties' => $companyProps,
            ]);
        } else {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response(['results' => []]);
        }

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts']  = Http::response(['results' => []]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/notes']     = Http::response(['results' => []]);

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items'] = Http::response([
            'results' => [['id' => '5001']],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/line_items/5001*'] = Http::response([
            'id'         => '5001',
            'properties' => [
                'name'                      => 'MAP',
                'price'                     => '500',
                'quantity'                  => '1',
                'recurringbillingfrequency' => 'monthly',
                'hs_product_id'             => 'prod-1',
            ],
        ]);

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => array_merge(['dealname' => 'Cliente Quick jc8', 'amount' => '500', 'dealstage' => 'closedwon'], $dealProps),
        ]);

        Http::fake($fakes);
    }

    public function test_deal_ganho_com_as_8_props_preenchidas_grava_razao_social_endereco_e_data(): void
    {
        $gestao = $this->criarServico('Gestão');
        $this->criarMapping('MAP', $gestao);

        $msMeiaNoiteUtc = (int) (gmmktime(0, 0, 0, 9, 5, 2026) * 1000);

        $this->mockaHubSpot([
            'razao_social'        => 'Maderatto Moveis LTDA',
            'cnpj_da_empresa'     => '11222333000144',
            'logradouro'          => 'Rua das Industrias, 500',
            'bairro'              => 'Distrito Industrial',
            'cidade'              => 'Joinville',
            'estado'              => 'SC',
            'cep'                 => '89219-500',
            'data_do_1_pagamento' => (string) $msMeiaNoiteUtc,
        ], companyProps: ['name' => 'Cliente Quick jc8', 'cnpj' => '', 'email' => '', 'phone' => '', 'domain' => '']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status, (string) $evento->erro_msg);

        $company = Company::first();
        $this->assertNotNull($company);
        $this->assertSame('Maderatto Moveis LTDA', $company->razao_social);
        $this->assertSame(
            'Rua das Industrias, 500, Distrito Industrial, Joinville - SC, CEP 89219-500',
            $company->endereco,
        );

        $contrato = ContratoServico::where('company_id', $company->id)->first();
        $this->assertNotNull($contrato);
        $this->assertSame('2026-09-05', $contrato->data_primeira_parcela->toDateString());
        $this->assertSame(5, $contrato->dia_vencimento);
    }

    public function test_cnpj_da_empresa_do_deal_e_fallback_quando_company_hubspot_nao_traz_cnpj(): void
    {
        $gestao = $this->criarServico('Gestão');
        $this->criarMapping('MAP', $gestao);

        $this->mockaHubSpot(
            ['cnpj_da_empresa' => '99888777000166'],
            companyProps: ['name' => 'Cliente Sem CNPJ Company', 'cnpj' => '', 'email' => '', 'phone' => '', 'domain' => ''],
        );

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $this->assertSame('99888777000166', $company->cnpj);
    }

    public function test_dado_ja_preenchido_a_mao_nao_e_sobrescrito_pelo_replay(): void
    {
        $gestao = $this->criarServico('Gestão');
        $this->criarMapping('MAP', $gestao);

        // Empresa já existe, casada por hubspot_company_id (match forte), com
        // razao_social/endereco/cnpj JÁ preenchidos à mão pelo Administrativo.
        $company = Company::create([
            'name'               => 'Cliente Ja Existente',
            'cnpj'               => '00111222000133',
            'razao_social'       => 'Razao Social Manual LTDA',
            'endereco'           => 'Endereco Preenchido a Mao, 123',
            'hubspot_company_id' => '88001',
            'status'             => 'ativo',
            'active'             => true,
        ]);

        $this->mockaHubSpot([
            'razao_social'    => 'Nome Diferente Vindo do HubSpot',
            'cnpj_da_empresa' => '55566677000188',
            'cidade'          => 'Curitiba',
            'estado'          => 'PR',
        ], companyProps: ['name' => 'Cliente Ja Existente', 'cnpj' => '', 'email' => '', 'phone' => '', 'domain' => '']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company->refresh();
        $this->assertSame('Razao Social Manual LTDA', $company->razao_social, 'razao_social manual nao pode ser sobrescrita');
        $this->assertSame('Endereco Preenchido a Mao, 123', $company->endereco, 'endereco manual nao pode ser sobrescrito');
        $this->assertSame('00111222000133', $company->cnpj, 'cnpj manual nao pode ser sobrescrito pelo fallback do deal');
    }

    public function test_props_ausentes_vazias_nao_quebram_o_fluxo_e_ficam_null(): void
    {
        $gestao = $this->criarServico('Gestão');
        $this->criarMapping('MAP', $gestao);

        // Nenhuma das 8 props novas presente no payload do deal.
        $this->mockaHubSpot([], companyProps: null);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status, (string) $evento->erro_msg);

        $company = Company::first();
        $this->assertNotNull($company);
        $this->assertNull($company->razao_social);
        $this->assertNull($company->endereco);

        $contrato = ContratoServico::where('company_id', $company->id)->first();
        $this->assertNotNull($contrato);
        $this->assertNull($contrato->data_primeira_parcela);
        $this->assertNull($contrato->dia_vencimento);
    }

    public function test_deal_com_2_line_items_persiste_a_mesma_data_nos_2_contratos(): void
    {
        $gestao = $this->criarServico('Gestão');
        $polos  = $this->criarServico('Polos');
        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo', $polos);

        $msMeiaNoiteUtc = (int) (gmmktime(0, 0, 0, 10, 15, 2026) * 1000);

        $fakes = [];
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response(['results' => []]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts']  = Http::response(['results' => []]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/notes']     = Http::response(['results' => []]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items'] = Http::response([
            'results' => [['id' => '6001'], ['id' => '6002']],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/line_items/6001*'] = Http::response([
            'id'         => '6001',
            'properties' => ['name' => 'MAP', 'price' => '500', 'quantity' => '1', 'recurringbillingfrequency' => 'monthly'],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/line_items/6002*'] = Http::response([
            'id'         => '6002',
            'properties' => ['name' => 'Polo', 'price' => '1500', 'quantity' => '1', 'recurringbillingfrequency' => 'monthly'],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => [
                'dealname'            => 'Cliente 2 Itens Quick jc8',
                'amount'              => '2000',
                'dealstage'           => 'closedwon',
                'data_do_1_pagamento' => (string) $msMeiaNoiteUtc,
            ],
        ]);
        Http::fake($fakes);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company   = Company::first();
        $contratos = ContratoServico::where('company_id', $company->id)->orderBy('id')->get();
        $this->assertCount(2, $contratos);
        $this->assertSame('2026-10-15', $contratos[0]->data_primeira_parcela->toDateString());
        $this->assertSame('2026-10-15', $contratos[1]->data_primeira_parcela->toDateString());
        $this->assertSame(15, $contratos[0]->dia_vencimento);
        $this->assertSame(15, $contratos[1]->dia_vencimento);
    }
}
