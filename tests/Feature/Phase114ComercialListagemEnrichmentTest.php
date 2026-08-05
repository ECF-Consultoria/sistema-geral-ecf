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
 *
 * Rastreabilidade Fase 115 Plano 02 — SC5 (HUB-TEST-05, parte listagem) → método:
 *  contato/observação por linha (nome_contato/cargo_contato/hubspot_observacao/
 *  hubspot_deal_id/hubspot_company_id) → test_payload_expoe_campos_de_contato_e_ids_hubspot
 *  bloco de valor confiança/warning por contrato → test_contrato_expoe_bloco_de_valor_hubspot
 *  gate crítico valor_revisar/pendências SÓ origem HubSpot → test_empresa_legada_NAO_recebe_nenhuma_pendencia_nova
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

    // ─── SPIN (Quick 260727-ijc) — campos do deal fluem do snapshot p/ payload ──

    public function test_campos_spin_do_snapshot_fluem_para_o_payload_da_listagem(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa([
            'name'             => 'HubSpot SPIN',
            'hubspot_snapshot' => [
                'deal' => [
                    'situacao_atual_do_cliente'       => 'Empresa com múltiplas contas em marketplaces',
                    'problema_principal_identificado' => 'Campanhas com desperdício de verba',
                    'implicacao_do_problema'          => 'Redução da lucratividade',
                    'necessidade_de_solucao'          => 'Reestruturar a publicidade',
                ],
            ],
        ]);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $e->id);
        $this->assertNotNull($row);
        $this->assertSame('Empresa com múltiplas contas em marketplaces', $row['spin']['situacao']);
        $this->assertSame('Campanhas com desperdício de verba', $row['spin']['problema']);
        $this->assertSame('Redução da lucratividade', $row['spin']['implicacao']);
        $this->assertSame('Reestruturar a publicidade', $row['spin']['necessidade']);
    }

    public function test_spin_vem_com_4_chaves_null_quando_snapshot_sem_deal(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot sem SPIN']);
        $this->marcarOrigemHubspot($e);

        $row = $this->linhaDaEmpresa(
            $this->get('/comercial/empresas/listagem')->viewData('page')['props'],
            $e->id
        );
        $this->assertNotNull($row);
        $this->assertSame(
            ['situacao' => null, 'problema' => null, 'implicacao' => null, 'necessidade' => null],
            $row['spin']
        );
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

    // ─── 5. Payload enriquecido — campos de company (HUB-UI-01, Task 2) ─────

    public function test_payload_expoe_campos_de_contato_e_ids_hubspot(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa([
            'name'                => 'HubSpot Payload Completo',
            'nome_contato'        => 'Ciclana Souza',
            'cargo_contato'       => 'Gerente',
            'hubspot_observacao'  => 'Observacao de teste',
            'hubspot_deal_id'     => 'deal-123',
            'hubspot_company_id'  => 'comp-456',
        ]);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertSame('Ciclana Souza', $row['nome_contato']);
        $this->assertSame('Gerente', $row['cargo_contato']);
        $this->assertSame('Observacao de teste', $row['hubspot_observacao']);
        $this->assertSame('deal-123', $row['hubspot_deal_id']);
        $this->assertSame('comp-456', $row['hubspot_company_id']);
    }

    /**
     * A coluna "Cadastrado em" da listagem mostra data E hora — o payload
     * precisa chegar em ISO 8601 completo, nao no 'Y-m-d' de antes, senao a
     * tela exibiria sempre 00:00 (o front so formata o que recebe).
     */
    public function test_payload_expoe_created_at_com_hora_em_iso8601(): void
    {
        $this->actingAsAdmin();

        $momento = \Carbon\Carbon::parse('2026-08-05 14:37:52');
        $e = $this->criarEmpresa(['name' => 'Empresa Com Horario']);
        $e->forceFill(['created_at' => $momento])->save();

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);

        // Nao pode ser so a data: 'Y-m-d' tem 10 chars e nao carrega hora.
        $this->assertNotSame('2026-08-05', $row['created_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $row['created_at'],
            'created_at deveria vir em ISO 8601 com hora.'
        );
        // A hora preservada precisa ser a que foi gravada.
        $this->assertSame(
            $momento->toIso8601String(),
            $row['created_at'],
            'created_at deveria refletir o horario real de criacao.'
        );
    }

    public function test_payload_campos_novos_sao_null_quando_ausentes(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa(['name' => 'HubSpot Payload Vazio']);
        $this->marcarOrigemHubspot($e);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertNull($row['nome_contato']);
        $this->assertNull($row['cargo_contato']);
        $this->assertNull($row['hubspot_observacao']);
        $this->assertNull($row['hubspot_deal_id']);
        $this->assertNull($row['hubspot_company_id']);
    }

    // ─── 6. Payload enriquecido — bloco de valor por contrato (HUB-UI-01) ───

    public function test_contrato_expoe_bloco_de_valor_hubspot(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Bloco Valor', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Bloco Valor']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico, [
            'hubspot_valor_original'           => 36000,
            'hubspot_valor_normalizado_mensal' => 3000,
            'hubspot_valor_confidence'         => 'medium',
            'hubspot_valor_warning'            => 'aviso teste',
            'hubspot_billing_frequency'        => 'annually',
        ]);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $ct = $row['contratos_servico'][0];
        $this->assertSame(36000.0, $ct['hubspot_valor_original']);
        $this->assertSame(3000.0, $ct['hubspot_valor_normalizado_mensal']);
        $this->assertSame('medium', $ct['hubspot_valor_confidence']);
        $this->assertSame('aviso teste', $ct['hubspot_valor_warning']);
        $this->assertSame('annually', $ct['hubspot_billing_frequency']);
    }

    public function test_contrato_bloco_de_valor_null_quando_ausente(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Sem Bloco Valor', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Sem Bloco Valor']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $ct = $row['contratos_servico'][0];
        $this->assertNull($ct['hubspot_valor_original']);
        $this->assertNull($ct['hubspot_valor_normalizado_mensal']);
        $this->assertNull($ct['hubspot_valor_confidence']);
        $this->assertNull($ct['hubspot_valor_warning']);
        $this->assertNull($ct['hubspot_billing_frequency']);
    }

    // ─── 7. pendencia_counts + whitelist do filtro (HUB-UI-02) ───────────────

    public function test_pendencia_counts_tem_8_chaves_e_conta_valor_revisar(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Contagem', Servico::SETOR_PERFORMANCE);

        $e1 = $this->criarEmpresa(['name' => 'Conta Valor Revisar 1']);
        $this->marcarOrigemHubspot($e1);
        $this->criarContrato($e1, $servico, ['hubspot_valor_confidence' => 'low']);

        $e2 = $this->criarEmpresa(['name' => 'Conta Valor Revisar 2']);
        $this->marcarOrigemHubspot($e2);
        $this->criarContrato($e2, $servico, ['hubspot_valor_confidence' => 'low']);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('sem_contato', $props['pendencia_counts']);
        $this->assertArrayHasKey('valor_revisar', $props['pendencia_counts']);
        $this->assertArrayHasKey('possivel_duplicidade', $props['pendencia_counts']);
        $this->assertSame(2, $props['pendencia_counts']['valor_revisar']);
    }

    public function test_filtro_pendencia_valor_revisar_filtra_lista(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Filtro', Servico::SETOR_PERFORMANCE);

        $e1 = $this->criarEmpresa(['name' => 'Filtro Valor Revisar']);
        $this->marcarOrigemHubspot($e1);
        $this->criarContrato($e1, $servico, ['hubspot_valor_confidence' => 'low']);

        $e2 = $this->criarEmpresa(['name' => 'Filtro Sem Pendencia']);
        $this->marcarOrigemHubspot($e2);
        $this->criarContrato($e2, $servico, ['hubspot_valor_confidence' => 'high']);

        $response = $this->get('/comercial/empresas/listagem?pendencia=valor_revisar');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['companies']['data']);
        $this->assertSame($e1->id, $props['companies']['data'][0]['id']);
    }

    // ─── 8. pendencias_detalhes (HUB-UI-02) ──────────────────────────────────

    public function test_detalhes_possivel_duplicidade_e_array_com_nome_real_da_candidata(): void
    {
        $this->actingAsAdmin();

        $candidata = $this->criarEmpresa(['name' => 'Nome Real Da Candidata']);

        $e = $this->criarEmpresa([
            'name'             => 'HubSpot Detalhe Duplicidade',
            'hubspot_snapshot' => [
                'warnings' => [
                    [
                        'tipo'                 => 'possivel_duplicidade',
                        'candidate_company_id' => $candidata->id,
                        'via'                  => 'nome',
                        'nome_normalizado'      => 'nome real da candidata',
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
        $this->assertIsArray($row['pendencias_detalhes']['possivel_duplicidade']);
        $this->assertSame(['Nome Real Da Candidata'], $row['pendencias_detalhes']['possivel_duplicidade']);
    }

    public function test_detalhes_possivel_duplicidade_fallback_nome_normalizado_quando_candidata_sumiu(): void
    {
        $this->actingAsAdmin();

        $e = $this->criarEmpresa([
            'name'             => 'HubSpot Candidata Sumiu',
            'hubspot_snapshot' => [
                'warnings' => [
                    [
                        'tipo'                 => 'possivel_duplicidade',
                        'candidate_company_id' => 999999,
                        'via'                  => 'nome',
                        'nome_normalizado'      => 'empresa que nao existe mais',
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
        $this->assertIsArray($row['pendencias_detalhes']['possivel_duplicidade']);
        $this->assertSame(['empresa que nao existe mais'], $row['pendencias_detalhes']['possivel_duplicidade']);
    }

    public function test_detalhes_valor_revisar_e_array_de_nomes_de_servico(): void
    {
        $this->actingAsAdmin();

        $servico = $this->criarServico('Servico Nome No Detalhe', Servico::SETOR_PERFORMANCE);

        $e = $this->criarEmpresa(['name' => 'HubSpot Detalhe Valor']);
        $this->marcarOrigemHubspot($e);
        $this->criarContrato($e, $servico, ['hubspot_valor_confidence' => 'low']);

        $response = $this->get('/comercial/empresas/listagem');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = $this->linhaDaEmpresa($props, $e->id);
        $this->assertNotNull($row);
        $this->assertIsArray($row['pendencias_detalhes']['valor_revisar']);
        $this->assertContains('Servico Nome No Detalhe', $row['pendencias_detalhes']['valor_revisar']);
    }
}
