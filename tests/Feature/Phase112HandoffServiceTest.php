<?php

namespace Tests\Feature;

use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use App\Services\Hubspot\HubspotDealHandoffService;
use App\Services\Hubspot\HubspotHandoffData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 112 Plan 112-02 (HubspotDealHandoffService + DTO).
 *
 * Exercita o handoff service ISOLADO (sem HTTP/webhook): monta $deal/$lineItems
 * no mesmo shape que HubspotApiClient::fetchDealLineItems e o controller
 * devolvem, resolve via HubspotLineItemMapping::paraNome + HubspotValueResolver
 * (plano 112-01) e verifica o DTO produzido.
 *
 * Cenarios:
 *  T1. 1 line item mapeado, monthly price 500 -> 1 contrato, valor 500.0, confidence 'high'.
 *  T2. 1 line item mapeado, annually price 36000 P1Y -> valor_contratado 3000.0 (mensal normalizado).
 *  T3. 2 line items mapeados (MAP + Polo) -> 2 contratos, hubspot_line_item_id distintos.
 *  T4. 2 line items com confidence mista (high + medium) -> DTO->confidence == 'medium' (a menor).
 *  T5. Line item sem mapping ativo -> 0 contratos + 1 warning.
 *  T6. Sem line items, fluxo legado com servico compativel -> 1 contrato via deal.amount.
 *  T7. Sem line items, servico do deal nao encontrado no catalogo -> 0 contratos + warning.
 */
class Phase112HandoffServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $propsDeal = [
        'servico'            => 'servico_ecf',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase pode aplicar seeds — isola os cenarios limpando as
        // tabelas de catalogo (mesmo padrao do Phase37WebhookLineItemsTest).
        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('servicos')->delete();
    }

    private function criarServico(
        string $nome,
        string $tipoCobranca = Servico::TIPO_MENSAL,
        float $valorPadrao = 0,
        string $setor = Servico::SETOR_OUTROS,
    ): Servico {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => $valorPadrao,
            'tipo_cobranca' => $tipoCobranca,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    private function criarMapping(string $lineItemName, Servico $servico, bool $ativo = true): HubspotLineItemMapping
    {
        return HubspotLineItemMapping::create([
            'line_item_name' => $lineItemName,
            'servico_id'     => $servico->id,
            'ativo'          => $ativo,
        ]);
    }

    private function service(): HubspotDealHandoffService
    {
        return app(HubspotDealHandoffService::class);
    }

    public function test_build_com_line_item_mensal_monthly_resolve_valor_operacional(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $this->criarMapping('MAP', $gestao);

        $deal = ['properties' => ['dealname' => 'Cliente Monthly']];
        $lineItems = [
            ['id' => '1001', 'name' => 'MAP', 'price' => '500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly', 'hs_product_id' => 'prod-1'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDeal);

        $this->assertInstanceOf(HubspotHandoffData::class, $dto);
        $this->assertCount(1, $dto->contracts_to_create);
        $this->assertEmpty($dto->warnings);
        $this->assertSame('high', $dto->confidence);

        $contrato = $dto->contracts_to_create[0];
        $this->assertEqualsWithDelta(500.0, $contrato['valor_contratado'], 0.01);
        $this->assertSame('high', $contrato['hubspot_valor_confidence']);
        $this->assertSame('1001', $contrato['hubspot_line_item_id']);
        $this->assertSame($gestao->id, $contrato['servico_id']);
        $this->assertSame('Gestão', $contrato['servico_nome']);
    }

    public function test_build_com_line_item_annually_p1y_normaliza_para_mensal(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 3000);
        $this->criarMapping('MAP', $gestao);

        $deal = ['properties' => ['dealname' => 'Cliente Annual']];
        $lineItems = [
            ['id' => '2001', 'name' => 'MAP', 'price' => '36000', 'quantity' => 1, 'recurringbillingfrequency' => 'annually', 'hs_recurring_billing_period' => 'P1Y'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDeal);

        $this->assertCount(1, $dto->contracts_to_create);
        $contrato = $dto->contracts_to_create[0];
        $this->assertEqualsWithDelta(3000.0, $contrato['valor_contratado'], 0.01);
        $this->assertEqualsWithDelta(36000.0, $contrato['hubspot_valor_original'], 0.01);
        $this->assertSame('annually', $contrato['hubspot_billing_frequency']);
        $this->assertSame('high', $contrato['hubspot_valor_confidence']);
    }

    public function test_build_com_2_line_items_mapeados_cria_2_contratos_ids_distintos(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $polos  = $this->criarServico('Polos', Servico::TIPO_MENSAL, 2000);
        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo', $polos);

        $deal = ['properties' => ['dealname' => 'Cliente 2 Itens']];
        $lineItems = [
            ['id' => '3001', 'name' => 'MAP',  'price' => '500',  'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
            ['id' => '3002', 'name' => 'Polo', 'price' => '1500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDeal);

        $this->assertCount(2, $dto->contracts_to_create);
        $ids = array_column($dto->contracts_to_create, 'hubspot_line_item_id');
        $this->assertSame(['3001', '3002'], $ids);
        $this->assertNotSame($ids[0], $ids[1], 'Contratos separados por hubspot_line_item_id distinto');
    }

    public function test_build_com_confidence_mista_dto_confidence_e_a_menor(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $polos  = $this->criarServico('Polos', Servico::TIPO_MENSAL, 3000);
        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo', $polos);

        $deal = ['properties' => ['dealname' => 'Cliente Confidence Mista']];
        $lineItems = [
            // item A: monthly + price -> resolve confidence 'high'.
            ['id' => '4001', 'name' => 'MAP', 'price' => '500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
            // item B: annually + price SEM hs_recurring_billing_period=P1Y -> resolve confidence 'medium'.
            ['id' => '4002', 'name' => 'Polo', 'price' => '36000', 'quantity' => 1, 'recurringbillingfrequency' => 'annually'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDeal);

        $this->assertCount(2, $dto->contracts_to_create);
        $this->assertSame('high', $dto->contracts_to_create[0]['hubspot_valor_confidence']);
        $this->assertSame('medium', $dto->contracts_to_create[1]['hubspot_valor_confidence']);
        $this->assertSame('medium', $dto->confidence, 'DTO->confidence deve ser a MENOR entre os contratos');
    }

    public function test_build_line_item_sem_mapping_ativo_gera_warning_sem_contrato(): void
    {
        // Nenhum mapping cadastrado para 'XYZ Premium'.
        $deal = ['properties' => ['dealname' => 'Cliente Sem Mapping']];
        $lineItems = [
            ['id' => '5001', 'name' => 'XYZ Premium', 'price' => '999', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDeal);

        $this->assertCount(0, $dto->contracts_to_create);
        $this->assertCount(1, $dto->warnings);
        $this->assertSame('XYZ Premium', $dto->warnings[0]['name']);
    }

    public function test_build_line_item_com_mapping_inativo_trata_como_nao_mapeado(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $this->criarMapping('MAP', $gestao, ativo: false);

        $deal = ['properties' => ['dealname' => 'Cliente Mapping Inativo']];
        $lineItems = [
            ['id' => '5002', 'name' => 'MAP', 'price' => '500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDeal);

        $this->assertCount(0, $dto->contracts_to_create);
        $this->assertCount(1, $dto->warnings);
    }

    public function test_build_sem_line_items_fluxo_legado_servico_compativel(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 3000);

        $deal = ['properties' => [
            'dealname'    => 'Cliente Legado',
            'amount'      => '36000',
            'servico_ecf' => 'Gestão',
        ]];

        $dto = $this->service()->build($deal, [], $this->propsDeal);

        $this->assertCount(1, $dto->contracts_to_create);
        $contrato = $dto->contracts_to_create[0];
        $this->assertEqualsWithDelta(3000.0, $contrato['valor_contratado'], 0.01);
        $this->assertSame($gestao->id, $contrato['servico_id']);
        $this->assertNull($contrato['hubspot_line_item_id'], 'Fluxo legado nao tem line item de origem');
        $this->assertNotNull($contrato['hubspot_valor_warning'], 'Inferencia amount/12 deve deixar rastro de warning');
    }

    public function test_build_sem_line_items_servico_nao_encontrado_gera_warning(): void
    {
        // Nenhum Servico cadastrado com esse nome no catalogo.
        $deal = ['properties' => [
            'dealname'    => 'Cliente Servico Desconhecido',
            'amount'      => '1000',
            'servico_ecf' => 'Servico Inexistente',
        ]];

        $dto = $this->service()->build($deal, [], $this->propsDeal);

        $this->assertCount(0, $dto->contracts_to_create);
        $this->assertCount(1, $dto->warnings);
        $this->assertSame('Servico Inexistente', $dto->warnings[0]['name']);
    }

    /**
     * Quick 260820-jc8 — Tarefas 1 e 2: props do deal (razão social, CNPJ,
     * endereço, data da 1ª parcela) que o Comercial criou pro handoff de
     * contrato no ganho.
     */
    private array $propsDealCompleto = [
        'servico'             => 'servico_ecf',
        'cnpj_da_empresa'     => 'cnpj_da_empresa',
        'razao_social'        => 'razao_social',
        'logradouro'          => 'logradouro',
        'bairro'              => 'bairro',
        'cidade'              => 'cidade',
        'estado'              => 'estado',
        'cep'                 => 'cep',
        'data_do_1_pagamento' => 'data_do_1_pagamento',
    ];

    // ── Tarefa 1 (HUB-CONTRATO-01) — razão social/CNPJ/endereço do deal ────

    public function test_deal_contract_data_com_endereco_completo_compoe_string_limpa(): void
    {
        $deal = ['properties' => [
            'dealname'        => 'Cliente Endereco Completo',
            'razao_social'    => 'Maderatto Moveis LTDA',
            'cnpj_da_empresa' => '11222333000144',
            'logradouro'      => 'Rua das Industrias, 500',
            'bairro'          => 'Distrito Industrial',
            'cidade'          => 'Joinville',
            'estado'          => 'SC',
            'cep'             => '89219-500',
        ]];

        $dto = $this->service()->build($deal, [], $this->propsDealCompleto);

        $this->assertSame('Maderatto Moveis LTDA', $dto->deal_contract_data['razao_social']);
        $this->assertSame('11222333000144', $dto->deal_contract_data['cnpj']);
        $this->assertSame(
            'Rua das Industrias, 500, Distrito Industrial, Joinville - SC, CEP 89219-500',
            $dto->deal_contract_data['endereco'],
        );
    }

    public function test_deal_contract_data_com_endereco_parcial_so_cidade_e_estado_sem_pontuacao_solta(): void
    {
        $deal = ['properties' => [
            'dealname' => 'Cliente Endereco Parcial',
            'cidade'   => 'Blumenau',
            'estado'   => 'SC',
        ]];

        $dto = $this->service()->build($deal, [], $this->propsDealCompleto);

        $this->assertSame('Blumenau - SC', $dto->deal_contract_data['endereco']);
        $this->assertStringNotContainsString(',,', $dto->deal_contract_data['endereco']);
        $this->assertStringNotContainsString(', -', $dto->deal_contract_data['endereco']);
        $this->assertNotSame(',', substr($dto->deal_contract_data['endereco'], -1), 'Nao pode terminar com virgula solta');
    }

    public function test_deal_contract_data_sem_nenhuma_parte_de_endereco_fica_null(): void
    {
        $deal = ['properties' => ['dealname' => 'Cliente Sem Endereco']];

        $dto = $this->service()->build($deal, [], $this->propsDealCompleto);

        $this->assertNull($dto->deal_contract_data['endereco']);
        $this->assertNull($dto->deal_contract_data['razao_social']);
        $this->assertNull($dto->deal_contract_data['cnpj']);
    }

    // ── Tarefa 2 (HUB-CONTRATO-02) — data da 1a parcela + dia de vencimento ─

    /**
     * O formato REAL, MEDIDO contra a conta da ECF em 2026-08-20.
     *
     * Lido pela propria API no deal da Maderatto Moveis (`64133858455`):
     *
     *   data_do_1_pagamento  (tipo `date`)      => '2026-08-24'
     *   closedate            (tipo `datetime`)  => '2026-08-31T11:49:23.585Z'
     *
     * Property `date` chega como **string 'Y-m-d'** — NAO como epoch em
     * milissegundos. A primeira versao do parser assumia epoch ms como
     * formato principal e tratava 'Y-m-d' como "fallback para o futuro"; era
     * o contrario. O comportamento estava certo por causa desse fallback,
     * mas nenhum teste exercitava o formato que de fato ocorre — este passou
     * a exercitar.
     *
     * O teste irmao abaixo cobre epoch ms, que continua sendo aceito como
     * ramo EXCEPCIONAL.
     */
    public function test_data_primeira_parcela_no_formato_real_y_m_d_prova_o_dia_correto(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $this->criarMapping('MAP', $gestao);

        $deal = ['properties' => [
            'dealname'            => 'Maderatto Móveis (payload medido)',
            // Valor LITERAL medido na conta real — nao normalizar, nao "arrumar".
            'data_do_1_pagamento' => '2026-08-24',
        ]];
        $lineItems = [
            ['id' => '9101', 'name' => 'MAP', 'price' => '3000', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDealCompleto);

        $contrato = $dto->contracts_to_create[0];
        $this->assertSame('2026-08-24', $contrato['data_primeira_parcela']);
        $this->assertSame(24, $contrato['dia_vencimento'], 'dia_vencimento deve ser o dia de data_primeira_parcela');
    }

    /**
     * Ramo EXCEPCIONAL — epoch ms. NAO e o formato que a conta da ECF
     * devolve (ver o teste acima, medido), mas continua aceito.
     *
     * 2025-08-31T00:00:00Z escolhido de proposito: em America/Sao_Paulo
     * (UTC-3) meia-noite UTC de 31/08 vira 21h de 30/08. Sem forcar UTC no
     * parse, o teste pegaria dia 30 (ERRADO) em vez de 31.
     */
    public function test_data_primeira_parcela_epoch_ms_meia_noite_utc_prova_o_dia_correto(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $this->criarMapping('MAP', $gestao);

        $msMeiaNoiteUtc = (int) (gmmktime(0, 0, 0, 8, 31, 2025) * 1000);

        $deal = ['properties' => [
            'dealname'            => 'Cliente Data 1a Parcela',
            'data_do_1_pagamento' => (string) $msMeiaNoiteUtc,
        ]];
        $lineItems = [
            ['id' => '9001', 'name' => 'MAP', 'price' => '500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDealCompleto);

        $contrato = $dto->contracts_to_create[0];
        $this->assertSame('2025-08-31', $contrato['data_primeira_parcela']);
        $this->assertSame(31, $contrato['dia_vencimento'], 'dia_vencimento deve ser o dia de data_primeira_parcela');
    }

    public function test_data_primeira_parcela_vazia_deixa_os_dois_campos_null_sem_default(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $this->criarMapping('MAP', $gestao);

        $deal = ['properties' => ['dealname' => 'Cliente Sem Data']];
        $lineItems = [
            ['id' => '9002', 'name' => 'MAP', 'price' => '500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDealCompleto);

        $contrato = $dto->contracts_to_create[0];
        $this->assertNull($contrato['data_primeira_parcela']);
        $this->assertNull($contrato['dia_vencimento']);
    }

    public function test_deal_com_2_line_items_recebem_a_mesma_data_primeira_parcela(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::TIPO_MENSAL, 1000);
        $polos  = $this->criarServico('Polos', Servico::TIPO_MENSAL, 2000);
        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo', $polos);

        $msMeiaNoiteUtc = (int) (gmmktime(0, 0, 0, 6, 10, 2025) * 1000);

        $deal = ['properties' => [
            'dealname'            => 'Cliente 2 Itens Mesma Data',
            'data_do_1_pagamento' => (string) $msMeiaNoiteUtc,
        ]];
        $lineItems = [
            ['id' => '9101', 'name' => 'MAP',  'price' => '500',  'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
            ['id' => '9102', 'name' => 'Polo', 'price' => '1500', 'quantity' => 1, 'recurringbillingfrequency' => 'monthly'],
        ];

        $dto = $this->service()->build($deal, $lineItems, $this->propsDealCompleto);

        $this->assertCount(2, $dto->contracts_to_create);
        $this->assertSame('2025-06-10', $dto->contracts_to_create[0]['data_primeira_parcela']);
        $this->assertSame('2025-06-10', $dto->contracts_to_create[1]['data_primeira_parcela']);
        $this->assertSame(10, $dto->contracts_to_create[0]['dia_vencimento']);
        $this->assertSame(10, $dto->contracts_to_create[1]['dia_vencimento']);
    }
}
