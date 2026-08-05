<?php

namespace Tests\Feature\Phase123;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prova de UIEM-03/D-11 — 123-04-PLAN.md Task 1. Payload de snapshot
 * gravado ANTES da Fase 120 (sem `var_margem_pp`, sem `empresas_score` na
 * raiz do `breakdown_json`) renderiza sem erro e sem chave inventada — o
 * controller é passthrough do `breakdown_json`, nunca preenche o que o
 * snapshot não tinha. `empresas_score`/`tem_detalhe_empresas` no topo vêm
 * SEMPRE de `CompanyScoreSnapshotReader` contra a tabela dedicada — nunca
 * do conteúdo de `breakdown_json` — por isso o comportamento é idêntico
 * com ou sem a chave `empresas_score` no payload legado.
 */
class RetrocompatSnapshotAntigoTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // Badge global de alertas críticos — infraestrutura alheia a esta
        // fase, pré-aquecida para não gerar ruído (mesmo padrão do resto
        // da suíte Phase123, ver 123-02-SUMMARY.md deviation 2).
        Cache::put('alertas.criticos_nao_ackeados.count', 0, 300);
    }

    #[Test]
    public function snapshot_anterior_a_fase_120_sem_var_margem_pp_e_sem_empresas_score(): void
    {
        $alvo = $this->criarUserElegivel('Snapshot Antigo');
        $this->adminLogado();

        // Simula um breakdown_json gravado antes da Fase 120: `componentes`
        // sem a chave `var_margem_pp` e sem `empresas_score` na raiz.
        $this->seedMensal($alvo->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => [
                    'cobertura' => 0.8,
                    'base'      => 'margem_var_pp',
                    'legado'    => ['cobertura' => 0.75],
                ],
                'componentes'    => [
                    'nps_medio'           => 4.0,
                    'var_faturamento_pct' => 5.0,
                    'var_margem_pct'      => 2.5,
                ],
            ],
        ]);

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('resultado.componentes.var_margem_pct', 2.5)
                ->missing('resultado.componentes.var_margem_pp')
                ->missing('resultado.empresas_score')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_score', [])
            );
    }

    #[Test]
    public function backend_nao_inventa_chave_var_margem_pp_no_payload_legado(): void
    {
        $alvo = $this->criarUserElegivel('Sem Chave Inventada');
        $this->adminLogado();

        $this->seedMensal($alvo->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'  => 3.5,
                'componentes' => [
                    'nps_medio'           => 3.5,
                    'var_faturamento_pct' => -1.0,
                    'var_margem_pct'      => -0.5,
                ],
            ],
        ]);

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                // O controller é passthrough — se um dia preencher chave
                // que o snapshot não tinha, o teste dourado da Fase 120
                // (PayloadBaselineFlagOffTest) é o próximo a quebrar.
                ->where('resultado.componentes.var_margem_pct', -0.5)
                ->missing('resultado.componentes.var_margem_pp')
            );
    }

    #[Test]
    public function empresas_score_presente_e_vazio_no_breakdown_json_comporta_se_identico(): void
    {
        $alvo = $this->criarUserElegivel('Empresas Score Vazio Pos 120');
        $this->adminLogado();

        // Caso pós-Fase 120 sem shadow: a chave existe no breakdown_json,
        // mas vazia. `empresas_score`/`tem_detalhe_empresas` no topo NUNCA
        // dependem dessa chave — vêm sempre de CompanyScoreSnapshotReader
        // contra a tabela dedicada, então o comportamento observável é
        // idêntico ao caso sem a chave (teste anterior).
        $this->seedMensal($alvo->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'componentes'    => [
                    'nps_medio'           => 4.0,
                    'var_faturamento_pct' => 5.0,
                    'var_margem_pct'      => 2.0,
                    'var_margem_pp'       => 2.0,
                ],
                'empresas_score' => [],
            ],
        ]);

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_score', [])
            );
    }

    #[Test]
    public function modo_em_curso_sem_carteira_nao_quebra(): void
    {
        $alvo = $this->criarUserElegivel('Em Curso Sem Carteira');
        $this->adminLogado();

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('resultado.sem_carteira', true)
                ->where('empresas_score', [])
            );
    }
}
