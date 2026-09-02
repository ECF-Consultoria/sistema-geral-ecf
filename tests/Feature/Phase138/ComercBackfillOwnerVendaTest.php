<?php

namespace Tests\Feature\Phase138;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Fase 138 Plano 04 (COMERC-02, D-10).
 *
 * Prova `hubspot:backfill-owner-venda`:
 *  1. Dry-run (sem `--apply`) não grava nada.
 *  2. `--apply` preenche `data_venda` a partir do snapshot já persistido,
 *     sem NENHUMA requisição HTTP (Passagem 1 é zero-custo de API).
 *  3. `--apply` preenche `hubspot_owner_id`/`hubspot_owner_nome` para
 *     empresa com `hubspot_deal_id` (Passagem 2, via `fetchDeal()` real).
 *  4. Empresa sem `hubspot_deal_id` fica intocada e é contada em
 *     `sem_deal_id` no sumário do comando.
 *  5. Falha HTTP num deal não aborta o laço — as demais empresas ainda são
 *     gravadas.
 *  6. Nenhuma empresa tem seu campo de estágio da máquina de estados
 *     alterado pelo comando (D-14).
 *
 * `Http::fake()` em todo teste — nenhum teste desta suíte chama a API real.
 */
class ComercBackfillOwnerVendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.access_token' => 'token-fake',
        ]);
    }

    public function test_dry_run_nao_grava_nada(): void
    {
        $comDataVenda = Company::factory()->create([
            'hubspot_snapshot' => ['deal' => ['closedate' => '2026-08-01']],
            'data_venda'       => null,
        ]);
        $comOwner = Company::factory()->create([
            'hubspot_deal_id'    => '7001',
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
        ]);

        Http::fake(); // dry-run não deveria nem chegar a chamar rede

        Artisan::call('hubspot:backfill-owner-venda');

        $this->assertDatabaseHas('companies', [
            'id'         => $comDataVenda->id,
            'data_venda' => null,
        ]);
        $this->assertDatabaseHas('companies', [
            'id'                 => $comOwner->id,
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
        ]);

        Http::assertNothingSent();
    }

    public function test_apply_preenche_data_venda_do_snapshot_sem_nenhuma_requisicao_http(): void
    {
        $company = Company::factory()->create([
            'hubspot_snapshot' => ['deal' => ['closedate' => '2026-08-24']],
            'data_venda'       => null,
            'hubspot_deal_id'  => null, // fora da Passagem 2 de propósito
        ]);

        Http::fake(); // qualquer request nesta suíte falha o teste abaixo

        Artisan::call('hubspot:backfill-owner-venda', ['--apply' => true]);

        $company->refresh();
        $this->assertNotNull($company->data_venda);
        $this->assertSame('2026-08-24', $company->data_venda->format('Y-m-d'));

        Http::assertNothingSent();
    }

    public function test_apply_preenche_hubspot_owner_para_empresa_com_deal_id(): void
    {
        $company = Company::factory()->create([
            'hubspot_deal_id'    => '7002',
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
            'hubspot_snapshot'   => null,
            'data_venda'         => null,
        ]);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/7002*' => Http::response([
                'id'         => '7002',
                'properties' => [
                    'hubspot_owner_id' => '900100',
                    'closedate'        => '2026-08-10',
                ],
            ]),
            'api.hubapi.com/crm/v3/owners/900100*' => Http::response([
                'id'        => '900100',
                'email'     => 'carla@ecfconsultoria.com.br',
                'firstName' => 'Carla',
                'lastName'  => 'Nunes',
            ]),
        ]);

        Artisan::call('hubspot:backfill-owner-venda', ['--apply' => true]);

        $company->refresh();
        $this->assertSame('900100', $company->hubspot_owner_id);
        $this->assertSame('Carla Nunes', $company->hubspot_owner_nome);
        // Rede de segurança da Passagem 2: sem snapshot, closedate do fetch
        // preenche data_venda também.
        $this->assertNotNull($company->data_venda);
        $this->assertSame('2026-08-10', $company->data_venda->format('Y-m-d'));
    }

    public function test_empresa_sem_deal_id_fica_intocada_e_e_contada_em_sem_deal_id(): void
    {
        $semDealId = Company::factory()->create([
            'hubspot_deal_id'    => null,
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
        ]);

        Http::fake();

        Artisan::call('hubspot:backfill-owner-venda', ['--apply' => true]);

        $semDealId->refresh();
        $this->assertNull($semDealId->hubspot_owner_id);
        $this->assertNull($semDealId->hubspot_owner_nome);

        $saida = Artisan::output();
        $this->assertStringContainsString('sem_deal_id', $saida);

        Http::assertNothingSent();
    }

    public function test_falha_http_num_deal_nao_aborta_o_laco(): void
    {
        $falha = Company::factory()->create([
            'hubspot_deal_id'    => '7003',
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
        ]);
        $sucesso = Company::factory()->create([
            'hubspot_deal_id'    => '7004',
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
        ]);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/7003*' => Http::response(['message' => 'Server Error'], 500),
            'api.hubapi.com/crm/v3/objects/deals/7004*' => Http::response([
                'id'         => '7004',
                'properties' => [
                    'hubspot_owner_id' => '900200',
                    'closedate'        => '2026-08-11',
                ],
            ]),
            'api.hubapi.com/crm/v3/owners/900200*' => Http::response([
                'id'        => '900200',
                'email'     => 'diego@ecfconsultoria.com.br',
                'firstName' => 'Diego',
                'lastName'  => 'Alves',
            ]),
        ]);

        Artisan::call('hubspot:backfill-owner-venda', ['--apply' => true]);

        $falha->refresh();
        $sucesso->refresh();

        $this->assertNull($falha->hubspot_owner_id, 'Deal com falha HTTP não pode gravar owner');
        $this->assertSame('900200', $sucesso->hubspot_owner_id, 'Falha em uma empresa não pode impedir a gravação das demais');
        $this->assertSame('Diego Alves', $sucesso->hubspot_owner_nome);
    }

    public function test_nenhuma_empresa_tem_estagio_alterado_pelo_comando(): void
    {
        $company1 = Company::factory()->create([
            'hubspot_snapshot' => ['deal' => ['closedate' => '2026-08-05']],
            'data_venda'       => null,
        ]);
        $company2 = Company::factory()->create([
            'hubspot_deal_id'    => '7005',
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
        ]);

        $this->assertNull($company1->etapa);
        $this->assertNull($company2->etapa);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/7005*' => Http::response([
                'id'         => '7005',
                'properties' => ['hubspot_owner_id' => '900300'],
            ]),
            'api.hubapi.com/crm/v3/owners/900300*' => Http::response([
                'id' => '900300', 'email' => 'x@ecfconsultoria.com.br', 'firstName' => 'X', 'lastName' => 'Y',
            ]),
        ]);

        Artisan::call('hubspot:backfill-owner-venda', ['--apply' => true]);

        $company1->refresh();
        $company2->refresh();

        $this->assertNull($company1->etapa);
        $this->assertNull($company2->etapa);
    }
}
