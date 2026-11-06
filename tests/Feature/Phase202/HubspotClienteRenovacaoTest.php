<?php

namespace Tests\Feature\Phase202;

use App\Services\Hubspot\HubspotErroApi;
use App\Services\HubspotApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Fase 202 (REN-03, REN-08) — cliente HubSpot da renovação, com Http::fake na
 * forma REAL da v3 (campo `id`, nunca a chave da v4).
 */
class HubspotClienteRenovacaoTest extends TestCase
{
    private HubspotApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->client = new HubspotApiClient('token-fake');
    }

    private function pipelineJson(): array
    {
        return [
            'id'     => '958410144',
            'label'  => 'Renovações contrato',
            'stages' => [
                ['id' => '1480135340', 'label' => 'Proximo do termino de contrato', 'metadata' => ['isClosed' => 'false']],
                ['id' => '1480135342', 'label' => 'Negociando', 'metadata' => ['isClosed' => 'false']],
                ['id' => '1480135341', 'label' => 'Negócio fechado', 'metadata' => ['isClosed' => 'true']],
            ],
        ];
    }

    public function test_criar_deal_envia_propriedades_e_associacoes(): void
    {
        Http::fake(['api.hubapi.com/crm/v3/objects/deals' => Http::response(
            ['id' => '123', 'properties' => ['dealname' => 'X'], 'createdAt' => '2026-11-06T10:00:00Z', 'archived' => false], 201
        )]);

        $r = $this->client->criarDeal(['dealname' => 'X', 'ecf_renovacao_chave' => 'k'], '55', '77');

        $this->assertSame('123', $r['id']);
        Http::assertSent(function (Request $req) {
            $d = $req->data();
            return $req->method() === 'POST'
                && str_ends_with($req->url(), '/crm/v3/objects/deals')
                && $d['properties']['ecf_renovacao_chave'] === 'k'
                && $d['associations'][0]['to']['id'] === '55'
                && $d['associations'][0]['types'][0]['associationTypeId'] === 5
                && $d['associations'][1]['to']['id'] === '77'
                && $d['associations'][1]['types'][0]['associationTypeId'] === 3;
        });
    }

    public function test_criar_deal_com_timeout_e_incerto_e_nao_repete(): void
    {
        $envios = 0;
        Http::fake(function () use (&$envios) {
            $envios++;
            throw new ConnectionException('cURL error 28: timeout');
        });

        try {
            $this->client->criarDeal(['dealname' => 'X'], '55', null);
            $this->fail('Deveria lançar HubspotErroApi');
        } catch (HubspotErroApi $e) {
            $this->assertTrue($e->ehIncerto());
        }

        // Uma única tentativa: a criação nunca é repetida às cegas.
        $this->assertSame(1, $envios);
    }

    public function test_criar_deal_500_e_incerto(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        try {
            $this->client->criarDeal(['dealname' => 'X'], null, null);
            $this->fail('Deveria lançar');
        } catch (HubspotErroApi $e) {
            $this->assertSame(HubspotErroApi::INCERTO, $e->tipo);
            $this->assertSame(500, $e->status);
        }
        Http::assertSentCount(1);
    }

    public function test_criar_deal_400_e_permanente(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'category' => 'VALIDATION_ERROR', 'message' => 'PROPERTY_DOESNT_EXIST'], 400)]);

        try {
            $this->client->criarDeal(['x' => 'y'], null, null);
            $this->fail('Deveria lançar');
        } catch (HubspotErroApi $e) {
            $this->assertTrue($e->ehPermanente());
            $this->assertStringContainsString('PROPERTY_DOESNT_EXIST', $e->getMessage());
            $this->assertStringNotContainsString('token-fake', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_criar_deal_429_respeita_retry_after_sem_repetir(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '7'])]);

        try {
            $this->client->criarDeal(['x' => 'y'], null, null);
            $this->fail('Deveria lançar');
        } catch (HubspotErroApi $e) {
            $this->assertTrue($e->ehLimite());
            $this->assertSame(7, $e->retryAfter);
        }
        Http::assertSentCount(1);
    }

    public function test_buscar_deals_por_propriedade_envia_filtro_eq_e_normaliza(): void
    {
        Http::fake(['*' => Http::response(['total' => 1, 'results' => [
            ['id' => '123', 'properties' => ['pipeline' => '958410144', 'dealstage' => '1480135340', 'ecf_renovacao_chave' => 'k'], 'archived' => false],
        ]], 200)]);

        $r = $this->client->buscarDealsPorPropriedade('ecf_renovacao_chave', 'k', ['pipeline', 'dealstage']);

        $this->assertSame('123', $r[0]['id']);
        $this->assertSame('1480135340', $r[0]['properties']['dealstage']);
        Http::assertSent(function (Request $req) {
            $f = $req->data()['filterGroups'][0]['filters'][0];
            return str_ends_with($req->url(), '/crm/v3/objects/deals/search')
                && $f['propertyName'] === 'ecf_renovacao_chave' && $f['operator'] === 'EQ' && $f['value'] === 'k'
                && $req->data()['limit'] === 10;
        });
    }

    public function test_busca_repete_apos_429_e_devolve(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '1'])
            ->push(['total' => 0, 'results' => []], 200)]);

        $this->assertSame([], $this->client->buscarDealsPorPropriedade('ecf_renovacao_chave', 'k', ['dealstage']));
        Http::assertSentCount(2);
    }

    public function test_busca_com_tres_500_vira_temporario(): void
    {
        Http::fake(['*' => Http::response(['message' => 'x'], 500)]);

        try {
            $this->client->buscarDealsPorPropriedade('ecf_renovacao_chave', 'k', ['dealstage']);
            $this->fail('Deveria lançar');
        } catch (HubspotErroApi $e) {
            $this->assertSame(HubspotErroApi::TEMPORARIO, $e->tipo);
        }
        Http::assertSentCount(3);
    }

    public function test_buscar_deal_ou_null(): void
    {
        Http::fake([
            '*/crm/v3/objects/deals/404*' => Http::response(['message' => 'nf'], 404),
            '*/crm/v3/objects/deals/200*' => Http::response(['id' => '200', 'properties' => ['dealstage' => '1480135341']], 200),
        ]);

        $this->assertNull($this->client->buscarDealOuNull('404', ['dealstage']));
        $this->assertSame('200', $this->client->buscarDealOuNull('200', ['dealstage'])['id']);
    }

    public function test_buscar_pipeline_normaliza_etapas(): void
    {
        Http::fake(['*/crm/v3/pipelines/deals/958410144' => Http::response($this->pipelineJson(), 200)]);

        $p = $this->client->buscarPipelineDeDeals('958410144');

        $this->assertSame('Renovações contrato', $p['label']);
        $this->assertCount(3, $p['stages']);
        $this->assertFalse($p['stages'][0]['fechado']);
        $this->assertTrue($p['stages'][2]['fechado']);
    }

    public function test_propriedade_404_e_null_e_criacao_envia_unicidade(): void
    {
        Http::fake([
            '*/crm/v3/properties/deals/ecf_renovacao_chave' => Http::response(['message' => 'nf'], 404),
            '*/crm/v3/properties/deals' => Http::response(['name' => 'ecf_renovacao_chave'], 201),
        ]);

        $this->assertNull($this->client->buscarPropriedadeDeDeal('ecf_renovacao_chave'));
        $this->client->criarPropriedadeDeDeal('ecf_renovacao_chave', 'Chave', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/crm/v3/properties/deals')
            && $r->data()['hasUniqueValue'] === true
            && $r->data()['type'] === 'string');
    }

    public function test_associar_deal_padrao_usa_put_v4(): void
    {
        Http::fake(['*' => Http::response(['fromObjectId' => 1], 200)]);

        $this->client->associarDealPadrao('9', 'companies', '55');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/crm/v4/objects/deals/9/associations/default/companies/55'));
    }

    public function test_nenhum_metodo_novo_faz_patch(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'properties' => [], 'results' => [], 'stages' => []], 200)]);

        $this->client->criarDeal(['a' => 'b'], '1', '2');
        $this->client->buscarDealsPorPropriedade('a', 'b', []);
        $this->client->buscarDealOuNull('1', []);
        $this->client->buscarPropriedadeDeDeal('a');
        $this->client->associarDealPadrao('1', 'contacts', '2');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // ─── Comando renovacao:hubspot-preparar ───

    public function test_comando_sem_aplicar_nao_cria_properties(): void
    {
        config(['services.hubspot.access_token' => 'token-fake']);
        Http::fake([
            '*/crm/v3/pipelines/deals/958410144' => Http::response($this->pipelineJson(), 200),
            '*/crm/v3/properties/deals/*' => Http::response(['message' => 'nf'], 404),
        ]);

        $this->artisan('renovacao:hubspot-preparar')
            ->expectsOutputToContain('SIMULAÇÃO — criaria a property ecf_renovacao_chave')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_comando_com_aplicar_cria_chave_unica(): void
    {
        config(['services.hubspot.access_token' => 'token-fake']);
        Http::fake([
            '*/crm/v3/pipelines/deals/958410144' => Http::response($this->pipelineJson(), 200),
            '*/crm/v3/properties/deals/*' => Http::response(['message' => 'nf'], 404),
            '*/crm/v3/properties/deals' => Http::response(['name' => 'ok'], 201),
        ]);

        $this->artisan('renovacao:hubspot-preparar', ['--aplicar' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/crm/v3/properties/deals')
            && $r->data()['name'] === 'ecf_renovacao_chave'
            && $r->data()['hasUniqueValue'] === true);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['name'] === 'ecf_negocio_original_id'
            && $r->data()['hasUniqueValue'] === false);
    }

    public function test_comando_com_etapa_ausente_falha(): void
    {
        config(['services.hubspot.access_token' => 'token-fake']);
        $p = $this->pipelineJson();
        array_pop($p['stages']); // some a etapa "Negócio fechado"
        Http::fake(['*/crm/v3/pipelines/deals/958410144' => Http::response($p, 200)]);

        $this->artisan('renovacao:hubspot-preparar')->assertExitCode(1);
    }
}
