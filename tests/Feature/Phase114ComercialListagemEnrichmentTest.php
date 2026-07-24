<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Phase 114 Plan 01 Task 1 (UI Comercial: pendências novas —
 * HUB-UI-02).
 *
 * Cobre o endpoint GET /comercial/empresas/listagem:
 *  - 3 pendências novas, SÓ para origem HubSpot: sem_contato, valor_revisar,
 *    possivel_duplicidade.
 *  - INVARIANTE: empresa legada (não-HubSpot) não recebe NENHUMA pendência
 *    nova, mesmo com nome_contato null e contrato de confidence 'low'.
 *
 * Helpers copiados de Phase37ComercialListagemTest (mesmo racional de setup).
 * Task 2 (payload enriquecido) adiciona mais casos a esta mesma suite.
 */
class Phase114ComercialListagemEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers (copiados de Phase37ComercialListagemTest) ──────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase114 ' . uniqid(),
            'email'    => 'admin.p114-01.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function criarServico(string $nome, string $setor = Servico::SETOR_OUTROS, float $valorPadrao = 100): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => $valorPadrao,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa P114-01 ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ], $overrides));
    }

    private function marcarOrigemHubspot(Company $c, array $payload = []): HubspotEvento
    {
        return HubspotEvento::create([
            'signature_valid'   => true,
            'portal_id'         => 12345,
            'object_type'       => 'DEAL',
            'object_id'         => random_int(1000, 99999),
            'subscription_type' => 'deal.propertyChange',
            'property_name'     => 'dealstage',
            'property_value'    => 'closedwon',
            'payload'           => $payload,
            'status'            => 'processado',
            'company_id_criada' => $c->id,
            'processado_em'     => now(),
        ]);
    }

    private function criarContrato(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ], $overrides));
    }

    private function linhaDaEmpresa(array $props, int $companyId): ?array
    {
        return collect($props['companies']['data'])->firstWhere('id', $companyId);
    }

    // ─── 1. Pendência sem_contato (HUB-UI-02) ────────────────────────────────

    public function test_pendencia_sem_contato_quando_nome_contato_null(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Sem Contato', 'nome_contato' => null]);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertContains('sem_contato', $row['pendencias_comerciais']);
    }

    public function test_sem_contato_ausente_quando_nome_contato_preenchido(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Com Contato', 'nome_contato' => 'Fulano de Tal']);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertNotContains('sem_contato', $row['pendencias_comerciais']);
    }

    // ─── 2. Pendência valor_revisar (HUB-UI-02) ──────────────────────────────

    public function test_pendencia_valor_revisar_quando_confidence_low(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Valor Baixo Confianca', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Valor Low']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico, ['hubspot_valor_confidence' => 'low']);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertContains('valor_revisar', $row['pendencias_comerciais']);
    }

    public function test_pendencia_valor_revisar_quando_warning_nao_nulo(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Com Warning', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Valor Warning']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico, [
            'hubspot_valor_confidence' => 'high',
            'hubspot_valor_warning'    => 'amount_indecidivel',
        ]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertContains('valor_revisar', $row['pendencias_comerciais']);
    }

    public function test_valor_revisar_ausente_quando_confidence_high_sem_warning(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Confianca Alta', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Valor OK']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico, [
            'hubspot_valor_confidence' => 'high',
            'hubspot_valor_warning'    => null,
        ]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertNotContains('valor_revisar', $row['pendencias_comerciais']);
    }

    // ─── 3. Pendência possivel_duplicidade (HUB-UI-02) ───────────────────────

    public function test_pendencia_possivel_duplicidade_via_snapshot(): void
    {
        $this->actingAsAdmin();

        $candidata = $this->criarEmpresa(['name' => 'Empresa Candidata Original']);

        $e = $this->criarEmpresa([
            'name'             => 'HubSpot Duplicidade Snapshot',
            'hubspot_snapshot' => [
                'warnings' => [
                    [
                        'tipo'                 => 'possivel_duplicidade',
                        'candidate_company_id' => $candidata->id,
                        'via'                  => 'nome',
                        'nome_normalizado'      => 'empresa candidata original',
                    ],
                ],
            ],
        ]);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertContains('possivel_duplicidade', $row['pendencias_comerciais']);
    }

    public function test_pendencia_possivel_duplicidade_via_payload_evento(): void
    {
        $this->actingAsAdmin();

        $candidata = $this->criarEmpresa(['name' => 'Empresa Candidata Via Evento']);

        $e = $this->criarEmpresa(['name' => 'HubSpot Duplicidade Evento']);
        $this->marcarOrigemHubspot($e, [
            'possivel_duplicidade' => [
                'candidate_company_id' => $candidata->id,
                'via'                  => 'nome',
                'nome_normalizado'      => 'empresa candidata via evento',
            ],
        ]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertContains('possivel_duplicidade', $row['pendencias_comerciais']);
    }

    public function test_possivel_duplicidade_ausente_sem_marcacao(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Sem Duplicidade']);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertNotContains('possivel_duplicidade', $row['pendencias_comerciais']);
    }

    // ─── 4. Isolamento origem-HubSpot (gate crítico) ─────────────────────────

    public function test_empresa_legada_NAO_recebe_nenhuma_pendencia_nova(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Legado Low', Servico::SETOR_PERFORMANCE);

        // Empresa SEM HubspotEvento (legada) — nome_contato null + contrato
        // confidence low + snapshot com warning: NADA disso deve gerar
        // pendencia porque a guarda de origem no topo do metodo bloqueia tudo.
        $e = $this->criarEmpresa([
            'name'             => 'Legacy Sem Contato Nem Valor',
            'nome_contato'     => null,
            'hubspot_snapshot' => [
                'warnings' => [
                    ['tipo' => 'possivel_duplicidade', 'candidate_company_id' => 999, 'via' => 'nome', 'nome_normalizado' => 'x'],
                ],
            ],
        ]);
        $this->criarContrato($e, $servico, ['hubspot_valor_confidence' => 'low']);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_origem_hubspot']);
        $this->assertSame([], $row['pendencias_comerciais']);
    }
}
