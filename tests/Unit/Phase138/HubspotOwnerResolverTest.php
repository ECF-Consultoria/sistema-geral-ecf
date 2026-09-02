<?php

namespace Tests\Unit\Phase138;

use App\Services\Hubspot\HubspotOwnerResolver;
use App\Services\HubspotApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Unit — Fase 138 Plano 03 (COMERC-02, D-08).
 *
 * Prova `HubspotOwnerResolver::resolverNome()`:
 *  1. Nome composto de firstName+lastName.
 *  2. Fallback para email quando firstName/lastName vêm vazios.
 *  3. `null` quando o id é `null`.
 *  4. `null` quando a API devolve 403 (escopo `crm.objects.owners.read`
 *     NÃO CONFIRMADO na conta real — Pitfall 3).
 *  5. `null` quando a API devolve 404 (owner arquivado/removido).
 *  6. Segunda chamada com o mesmo id não dispara nova requisição HTTP —
 *     prova do cache de 7 dias (`Http::assertSentCount(1)`).
 *
 * `Http::fake()` em todo teste — nenhum teste desta fase pode chamar a API
 * real (mesmo padrão de `tests/Feature/Phase34HubspotWebhookTest.php`).
 */
class HubspotOwnerResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cache::remember usa chave por owner id — limpar entre testes evita
        // vazamento de estado (cache array-driver persiste só durante o
        // processo do teste, mas cada teste roda em processo isolado do
        // PHPUnit; ainda assim, flush explícito documenta a intenção).
        Cache::flush();
    }

    private function resolver(): HubspotOwnerResolver
    {
        return new HubspotOwnerResolver(new HubspotApiClient('token-fake'));
    }

    public function test_nome_composto_de_firstname_e_lastname(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/owners/501*' => Http::response([
                'id'        => '501',
                'email'     => 'ana@ecfconsultoria.com.br',
                'firstName' => 'Ana',
                'lastName'  => 'Souza',
            ]),
        ]);

        $nome = $this->resolver()->resolverNome('501');

        $this->assertSame('Ana Souza', $nome);
    }

    public function test_fallback_para_email_quando_nome_vem_vazio(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/owners/502*' => Http::response([
                'id'        => '502',
                'email'     => 'sememail@ecfconsultoria.com.br',
                'firstName' => '',
                'lastName'  => '',
            ]),
        ]);

        $nome = $this->resolver()->resolverNome('502');

        $this->assertSame('sememail@ecfconsultoria.com.br', $nome);
    }

    public function test_id_nulo_devolve_null_sem_chamada_http(): void
    {
        Http::fake();

        $nome = $this->resolver()->resolverNome(null);

        $this->assertNull($nome);
        Http::assertNothingSent();
    }

    public function test_id_string_vazia_devolve_null_sem_chamada_http(): void
    {
        Http::fake();

        $nome = $this->resolver()->resolverNome('');

        $this->assertNull($nome);
        Http::assertNothingSent();
    }

    public function test_403_devolve_null(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/owners/503*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $nome = $this->resolver()->resolverNome('503');

        $this->assertNull($nome);
    }

    public function test_404_devolve_null(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/owners/504*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $nome = $this->resolver()->resolverNome('504');

        $this->assertNull($nome);
    }

    public function test_segunda_chamada_com_mesmo_id_nao_dispara_nova_requisicao_http(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/owners/505*' => Http::response([
                'id'        => '505',
                'email'     => 'bruno@ecfconsultoria.com.br',
                'firstName' => 'Bruno',
                'lastName'  => 'Lima',
            ]),
        ]);

        $resolver = $this->resolver();

        $primeira  = $resolver->resolverNome('505');
        $segunda   = $resolver->resolverNome('505');

        $this->assertSame('Bruno Lima', $primeira);
        $this->assertSame('Bruno Lima', $segunda);
        Http::assertSentCount(1);
    }
}
