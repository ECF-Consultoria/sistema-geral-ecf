<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Phase 111 Plan 111-01 (HUB-API-02).
 *
 * Cobre o comando `hubspot:inspect-properties` (Properties API HubSpot),
 * SEM chamada real (Http::fake). 3 cenários:
 *
 *  1. Caminho feliz: /crm/v3/properties/deals responde 200 com results
 *     contendo name/label/type/fieldType — o comando imprime os 4 campos.
 *  2. Resiliência a falha por status: companies responde 403 — o comando
 *     não crasha, reporta e segue exibindo os demais objetos, exit code 0.
 *  3. Resiliência a exceção de rede: contacts lança ConnectionException
 *     (timeout) — o comando captura via try/catch e segue, exit code 0.
 *
 * Também prova que o token de teste e a string 'Bearer' NUNCA aparecem
 * na saída do comando (T-111-01).
 *
 * Padrão de teste de comando Artisan do projeto (ver Phase18/DiagnoseCustIdTest):
 * Artisan::call() + Artisan::output() — NÃO usar $this->artisan()->run(),
 * cujo mock interno de OutputStyle não expõe fetch() e sempre devolve
 * Artisan::output() vazio.
 */
class Phase111InspectPropertiesTest extends TestCase
{
    private const TOKEN = 'fake-hubspot-token-secreto';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hubspot.access_token' => self::TOKEN]);
    }

    public function test_objeto_unico_imprime_nome_interno_label_type_fieldtype(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/properties/deals*' => Http::response([
                'results' => [
                    [
                        'name'      => 'hs_mrr',
                        'label'     => 'MRR mensal recorrente',
                        'type'      => 'number',
                        'fieldType' => 'number',
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('hubspot:inspect-properties', ['--objects' => 'deals']);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('hs_mrr', $output);
        $this->assertStringContainsString('MRR mensal recorrente', $output);
        $this->assertStringContainsString('number', $output);
    }

    public function test_falha_por_status_em_um_objeto_nao_impede_os_demais(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/properties/deals*' => Http::response([
                'results' => [
                    ['name' => 'dealname', 'label' => 'Nome do negócio', 'type' => 'string', 'fieldType' => 'text'],
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/properties/companies*' => Http::response([
                'status'  => 'error',
                'message' => 'Forbidden',
            ], 403),
        ]);

        $exitCode = Artisan::call('hubspot:inspect-properties', ['--objects' => 'deals,companies']);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        // Deals segue aparecendo mesmo com companies falhando.
        $this->assertStringContainsString('dealname', $output);
        $this->assertStringContainsString('companies', $output);
        $this->assertStringContainsString('403', $output);
    }

    public function test_excecao_de_rede_nao_crasha_o_comando(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/properties/deals*' => Http::response([
                'results' => [
                    ['name' => 'dealname', 'label' => 'Nome do negócio', 'type' => 'string', 'fieldType' => 'text'],
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/properties/contacts*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout');
            },
        ]);

        $exitCode = Artisan::call('hubspot:inspect-properties', ['--objects' => 'deals,contacts']);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        // Deals segue aparecendo mesmo com contacts lançando exceção de rede.
        $this->assertStringContainsString('dealname', $output);
        $this->assertStringContainsString('contacts', $output);
    }

    public function test_saida_nunca_contem_o_token_nem_a_string_bearer(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/properties/deals*' => Http::response([
                'results' => [
                    ['name' => 'hs_mrr', 'label' => 'MRR', 'type' => 'number', 'fieldType' => 'number'],
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/properties/companies*' => Http::response(['status' => 'error'], 403),
            'https://api.hubapi.com/crm/v3/properties/contacts*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout ' . self::TOKEN);
            },
        ]);

        $exitCode = Artisan::call('hubspot:inspect-properties', ['--objects' => 'deals,companies,contacts']);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString('Bearer', $output);
    }

    public function test_help_lista_a_opcao_objects(): void
    {
        $exitCode = Artisan::call('help', ['command_name' => 'hubspot:inspect-properties']);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--objects', $output);
    }
}
