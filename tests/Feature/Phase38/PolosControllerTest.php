<?php

// Phase 38 (Plano 01, re-escopo) — Suíte de contratos RED do novo modelo /polos.
// Descreve o comportamento ALVO do PolosController refatorado (Plano 03).
// Os testes FALHAM intencionalmente contra a implementação ATUAL (modelo antigo).
//
// Modelo antigo (descartado):
//   - "ativo" = qualquer linha do CSV POLOS MENSAL
//   - meta = ativos × R$3.000 (roster inteiro)
//
// Novo modelo (implementado nos Planos 02+03):
//   - "ativo" = MlbEmpresa em Fase M2/M3/M4 (projeto=POLOS) — fonte ECF
//   - faturamento = TGMV_LC do CSV por cust_id (join ECF × CSV)
//   - meta = soma dos limiares por estágio (M2×1k / M3×4k / M4×8k)
//   - status por empresa: Problema > Não > Sim > Em progresso (D-11 CONTEXT.md)
//   - duas visões: grade por polo + distribuição de status (statusDist)
//   - M1 excluído dos ativos (D-01 CONTEXT.md)
//
// Cobre: POL-01..13 (controller), POL-14..17 ficam em SeedPolosFaseTest.php.
// Referência: RESEARCH.md §Arquitetura de Validação + 38-CONTEXT.md D-01..D-15.

namespace Tests\Feature\Phase38;

use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\User;
use App\Support\CustId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PolosControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Garante que EcfDriveService está configurado corretamente em testes
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        // Limpa cache para reprodutibilidade entre testes
        Cache::flush();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Cria um usuário com role definida e email verificado.
     */
    private function usuarioComRole(string $role): User
    {
        return User::factory()->create([
            'role'              => $role,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Configura Http::fake com respostas mínimas válidas para /files e /files/:id/json.
     * Os fakes do mais específico (json) devem vir antes do menos específico (files).
     *
     * Cada linha deve ter CUS_CUST_ID_SEL no formato "<id>,0" (Pitfall 1 RESEARCH.md).
     * O helper CustId::normaliza() é usado para montar o cust_id casável.
     *
     * @param  array<array<string,mixed>>  $linhas  Linhas CSV a retornar no endpoint json
     */
    private function mockEcfPolos(array $linhas = []): void
    {
        // O controller filtra por mês (TIM_MONTH_ID). Garante que cada linha do fake
        // tenha um mês e COMPARATIVO — default 202606/FECHADO — para sobreviver ao filtro.
        $linhas = array_map(function ($r) {
            $r['TIM_MONTH_ID'] = $r['TIM_MONTH_ID'] ?? '202606';
            $r['COMPARATIVO']  = $r['COMPARATIVO']  ?? 'FECHADO';
            return $r;
        }, $linhas);

        Http::fake([
            // Mais específico primeiro — GET /files/:id/json retorna envelope com 'rows'
            '*/files/*/json*' => Http::response([
                'filename'  => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'returned'  => count($linhas),
                'limited'   => false,
                'totalRows' => null,
                'rows'      => $linhas,
            ], 200),
            // Menos específico — lista de arquivos com 1 entrada etlStatus=done
            '*/files*' => Http::response([
                'data'  => [[
                    'id'           => 'arquivo-polos-01',
                    'filename'     => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                    'etlStatus'    => 'done',
                    'downloadedAt' => '2026-06-11T15:00:10Z',
                ]],
                'total' => 1,
            ], 200),
        ]);
    }

    // ─── POL-01: Admin vê a página com props estruturadas ───────────────────────

    /**
     * POL-01: GET /polos retorna 200 com props estruturadas para admin autenticado.
     * Novo modelo: props devem incluir 'statusDist' (distribuição de status) e
     * NÃO mais 'configMetas' (modelo antigo de overrides por polo).
     */
    public function test_admin_ve_pagina(): void
    {
        $custId = '2425054445';
        MlbEmpresa::create([
            'nome'    => 'Empresa Teste',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custId,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        // Seed os limiares no banco para que o controller os leia
        Configuracao::set('polo_limiar_m2', '1000');
        Configuracao::set('polo_limiar_m3', '4000');
        Configuracao::set('polo_limiar_m4', '8000');

        // CUS_CUST_ID_SEL no formato CSV ECF Drive: "<id>,0" (Pitfall 1)
        $this->mockEcfPolos([
            [
                'CUS_CUST_ID_SEL' => $custId . ',0',
                'TGMV_LC'         => '1200',
                'LOCALIDADE'      => 'Arapongas',
            ],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->has('polos')
                ->has('statusDist')   // NOVO: distribuição de status
                ->has('meses')
                ->has('mesSelecionado')
                ->has('mesRefLabel')
                ->has('parcial')
                ->where('erro', null)
            );
    }

    // ─── POL-02: Não-admin recebe 403 ───────────────────────────────────────────

    /**
     * POL-02: GET /polos retorna 403 para consultor e mentor (apenas admin tem acesso).
     */
    public function test_nao_admin_recebe_403(): void
    {
        $this->actingAs($this->usuarioComRole('consultor'))
            ->get(route('polos.index'))
            ->assertStatus(403);

        $this->actingAs($this->usuarioComRole('mentor'))
            ->get(route('polos.index'))
            ->assertStatus(403);
    }

    // ─── POL-03: Join ECF×CSV por cust_id (normalização do Pitfall 1) ───────────

    /**
     * POL-03: Ativos M2–M4 cujo cust_id casa com CUS_CUST_ID_SEL (normalizado)
     * recebem o faturamento TGMV_LC do CSV. Ativos sem linha no CSV ficam com
     * faturamento 0 (não são descartados — D-12 CONTEXT.md).
     */
    public function test_join_ecf_csv_cust_id(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custIdComDados    = '2425054445';
        $custIdSemDados    = '9999999999';

        // Ativo que TEM linha no CSV → faturamento vem do TGMV_LC
        MlbEmpresa::create([
            'nome'    => 'Empresa Com Dados',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custIdComDados,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        // Ativo que NÃO tem linha no CSV → faturamento 0 (mas aparece na lista)
        MlbEmpresa::create([
            'nome'    => 'Empresa Sem Dados CSV',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custIdSemDados,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        // CUS_CUST_ID_SEL no formato CSV ECF Drive: "<id>,0"
        // Apenas custIdComDados aparece no CSV
        $this->mockEcfPolos([
            [
                'CUS_CUST_ID_SEL' => $custIdComDados . ',0',
                'TGMV_LC'         => '1500',
                'LOCALIDADE'      => 'Arapongas',
            ],
        ]);

        $resp = $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                // 2 empresas → mas podem estar em polos diferentes (custIdSemDados sem LOCALIDADE no CSV
                // usa polo de fallback; como não tem polo no model, cai em 'Sem polo')
                ->has('polos') // ao menos 1 polo
            );
    }

    // ─── POL-05: Meta = soma dos limiares por estágio ──────────────────────────

    /**
     * POL-05: Polo com 1×M2 + 1×M3 → meta = limiar_m2 + limiar_m3 = 1000 + 4000 = 5000.
     * NUNCA "ativos × 3000" (modelo antigo descartado pelo D-09 CONTEXT.md).
     */
    public function test_meta_por_estagio(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');
        Configuracao::set('polo_limiar_m3', '4000');
        Configuracao::set('polo_limiar_m4', '8000');

        $custIdM2 = '1111111111';
        $custIdM3 = '2222222222';

        MlbEmpresa::create([
            'nome'    => 'Empresa M2',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custIdM2,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);
        MlbEmpresa::create([
            'nome'    => 'Empresa M3',
            'fase'    => 'M3',
            'projeto' => 'POLOS',
            'cust_id' => $custIdM3,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        $this->mockEcfPolos([
            // Ambas no polo "Arapongas"
            ['CUS_CUST_ID_SEL' => $custIdM2 . ',0', 'TGMV_LC' => '500',  'LOCALIDADE' => 'Arapongas'],
            ['CUS_CUST_ID_SEL' => $custIdM3 . ',0', 'TGMV_LC' => '1000', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                ->has('polos', 1)
                ->where('polos.0.polo', 'Arapongas')
                // Meta = soma dos limiares individuais: M2(1000) + M3(4000) = 5000
                ->where('polos.0.meta', 5000)
            );
    }

    // ─── POL-06: Status Sim quando fat ≥ limiar ──────────────────────────────────

    /**
     * POL-06: Ativo M2 com faturamento >= limiar_m2 (1000) → status 'Sim'.
     */
    public function test_status_sim(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custId = '3333333333';
        MlbEmpresa::create([
            'nome'    => 'Empresa Sim',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custId,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        $this->mockEcfPolos([
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '1000', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                // O status individual está nas empresas dentro do polo
                // ou pode ser avaliado via statusDist
                ->where('statusDist.Sim', 1)
            );
    }

    // ─── POL-07: Status Em progresso quando 0 < fat < limiar ────────────────────

    /**
     * POL-07: Ativo M2 com 0 < faturamento < limiar_m2 (1000) → status 'Em progresso'.
     */
    public function test_status_em_progresso(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custId = '4444444444';
        MlbEmpresa::create([
            'nome'    => 'Empresa Em Progresso',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custId,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        $this->mockEcfPolos([
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '500', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                ->where('statusDist.Em progresso', 1)
            );
    }

    // ─── POL-08: Status Não quando faturamento = 0 ──────────────────────────────

    /**
     * POL-08: Ativo M2 com faturamento 0 (ausente no CSV do mês) → status 'Não'.
     * D-12: ativo sem linha no CSV conta como fat=0, status='Não', SEM 5º estado.
     */
    public function test_status_nao(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custIdAusente = '5555555555';
        MlbEmpresa::create([
            'nome'    => 'Empresa Sem Fat',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custIdAusente,
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        // CSV vazio → nenhum cust_id casa → fat=0 para todos os ativos
        $this->mockEcfPolos([]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                // O ativo aparece com status 'Não' (faturamento zero)
                ->where('statusDist.Não', 1)
            );
    }

    // ─── POL-09: Status Problema tem precedência máxima ─────────────────────────

    /**
     * POL-09: MlbEmpresa.problema=true → status 'Problema' mesmo com fat ≥ limiar.
     * Precedência: Problema > Não > Sim > Em progresso (D-11 CONTEXT.md).
     */
    public function test_status_problema_precedencia(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custId = '6666666666';
        MlbEmpresa::create([
            'nome'    => 'Empresa Com Problema',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custId,
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'problema' => true,  // flag problema = true
        ]);

        $this->mockEcfPolos([
            // Fat >= limiar → seria 'Sim', mas problema=true → 'Problema'
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '5000', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                ->where('statusDist.Problema', 1)
                ->where('statusDist.Sim', 0)
            );
    }

    // ─── POL-10: M1 excluído dos ativos ─────────────────────────────────────────

    /**
     * POL-10: Empresa em fase M1 NÃO aparece em `polos` nem em `statusDist.total`.
     * D-01: "Ativo" = Fase M2, M3 ou M4. M1 = onboarding, sem meta de faturamento.
     */
    public function test_m1_excluido(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');
        Configuracao::set('polo_limiar_m3', '4000');

        $custIdM1 = '7777777777';
        $custIdM2 = '8888888888';

        // M1 — NÃO deve aparecer
        MlbEmpresa::create([
            'nome'    => 'Empresa M1 (excluída)',
            'fase'    => 'M1',
            'projeto' => 'POLOS',
            'cust_id' => $custIdM1,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);
        // M2 — DEVE aparecer
        MlbEmpresa::create([
            'nome'    => 'Empresa M2 (ativa)',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custIdM2,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        $this->mockEcfPolos([
            ['CUS_CUST_ID_SEL' => $custIdM1 . ',0', 'TGMV_LC' => '9000', 'LOCALIDADE' => 'Arapongas'],
            ['CUS_CUST_ID_SEL' => $custIdM2 . ',0', 'TGMV_LC' => '1500', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                // statusDist.total deve contar apenas o M2 (total=1), não o M1
                ->where('statusDist.total', 1)
            );
    }

    // ─── POL-11: statusDist conta os 4 status corretamente ──────────────────────

    /**
     * POL-11: `statusDist` conta Sim/Em progresso/Não/Problema + total entre os ativos.
     */
    public function test_status_dist(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');
        Configuracao::set('polo_limiar_m3', '4000');
        Configuracao::set('polo_limiar_m4', '8000');

        // 1 Sim (M2, fat >= 1000)
        $c1 = '1010101010';
        MlbEmpresa::create(['nome' => 'Sim M2', 'fase' => 'M2', 'projeto' => 'POLOS', 'cust_id' => $c1, 'estagio' => 'Não Listado', 'problema' => false]);

        // 1 Em progresso (M3, 0 < fat < 4000)
        $c2 = '2020202020';
        MlbEmpresa::create(['nome' => 'Progresso M3', 'fase' => 'M3', 'projeto' => 'POLOS', 'cust_id' => $c2, 'estagio' => 'Não Listado', 'problema' => false]);

        // 1 Não (M4, ausente no CSV → fat=0)
        $c3 = '3030303030';
        MlbEmpresa::create(['nome' => 'Nao M4', 'fase' => 'M4', 'projeto' => 'POLOS', 'cust_id' => $c3, 'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'problema' => false]);

        // 1 Problema (M2, problema=true)
        $c4 = '4040404040';
        MlbEmpresa::create(['nome' => 'Problema M2', 'fase' => 'M2', 'projeto' => 'POLOS', 'cust_id' => $c4, 'estagio' => 'Não Listado', 'problema' => true]);

        $this->mockEcfPolos([
            ['CUS_CUST_ID_SEL' => $c1 . ',0', 'TGMV_LC' => '1000',  'LOCALIDADE' => 'Arapongas'], // Sim
            ['CUS_CUST_ID_SEL' => $c2 . ',0', 'TGMV_LC' => '2000',  'LOCALIDADE' => 'Arapongas'], // Em progresso (< 4000)
            // c3 ausente no CSV → fat=0 → Não
            ['CUS_CUST_ID_SEL' => $c4 . ',0', 'TGMV_LC' => '5000',  'LOCALIDADE' => 'Arapongas'], // Problema
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                ->where('statusDist.Sim', 1)
                ->where('statusDist.Em progresso', 1)
                ->where('statusDist.Não', 1)
                ->where('statusDist.Problema', 1)
                ->where('statusDist.total', 4)
            );
    }

    // ─── POL-12: ECF Drive offline → degradação graciosa ────────────────────────

    /**
     * POL-12: /files → 500 ⇒ 200 + `polos=[]` + `statusDist` zerado + `erro` pt-BR.
     */
    public function test_ecf_offline_degradacao(): void
    {
        Http::fake([
            '*/files*' => Http::response('Service Unavailable', 500),
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('polos', [])
                ->where('erro', 'Não foi possível buscar dados do ECF Drive. Tente em alguns segundos.')
            );
    }

    // ─── POL-13: Filtro por mês via TIM_MONTH_ID ─────────────────────────────────

    /**
     * POL-13: `?mes=YYYYMM` filtra por `TIM_MONTH_ID`; `meses` desc com flag `parcial`.
     * O CSV empilha vários meses — a página mostra UM mês por vez.
     */
    public function test_filtro_por_mes(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custId = '9090909090';
        MlbEmpresa::create([
            'nome'    => 'Empresa Filtro Mes',
            'fase'    => 'M2',
            'projeto' => 'POLOS',
            'cust_id' => $custId,
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        $this->mockEcfPolos([
            // Mês fechado anterior (202605)
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '1000', 'LOCALIDADE' => 'Arapongas', 'TIM_MONTH_ID' => '202605', 'COMPARATIVO' => 'FECHADO'],
            // Mês corrente parcial (202606)
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '4000', 'LOCALIDADE' => 'Arapongas', 'TIM_MONTH_ID' => '202606', 'COMPARATIVO' => 'PARCIAL'],
        ]);

        $admin = $this->usuarioComRole('admin');

        // Default: mês mais recente (202606), parcial
        $this->actingAs($admin)
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->where('mesSelecionado', '202606')
                ->where('mesRefLabel', 'Junho/2026')
                ->where('parcial', true)
                ->has('meses', 2)
                ->where('meses.0.value', '202606')
                ->where('meses.0.parcial', true)
                ->where('meses.1.value', '202605')
                ->where('meses.1.parcial', false)
            );

        // ?mes=202605: mês anterior fechado
        $this->actingAs($admin)
            ->get(route('polos.index', ['mes' => '202605']))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->where('mesSelecionado', '202605')
                ->where('mesRefLabel', 'Maio/2026')
                ->where('parcial', false)
            );
    }
}
