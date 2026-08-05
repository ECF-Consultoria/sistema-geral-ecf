<?php

namespace Tests\Feature\Phase123;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prova da D-03/UIEM-03 — 123-02-PLAN.md Task 3. Ausência de detalhe por
 * empresa (mês ainda não congelado, snapshot anterior à Fase 122, ou
 * profissional sem carteira) vira sinal explícito no payload, NUNCA 500 e
 * nunca prop ausente.
 */
class PerformanceShowSemDetalheTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // Badge global de alertas críticos — infraestrutura alheia a esta
        // fase, pré-aquecida para não gerar ruído (mesmo padrão do resto da
        // suíte Phase123).
        Cache::put('alertas.criticos_nao_ackeados.count', 0, 300);
    }

    #[Test]
    public function competencia_fechada_com_snapshot_mensal_mas_sem_linha_por_empresa(): void
    {
        $alvo = $this->criarUserElegivel('Sem Linha');
        $this->adminLogado();

        // Snapshot mensal existe (competência congelada), mas nenhuma linha
        // foi gravada em `desempenho_company_score_snapshots` — cenário de
        // mês fechado no calendário sem consolidação por empresa.
        $this->seedMensal($alvo->id, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_score', [])
                ->where('empresas_score_resumo.entraram', 0)
                ->where('empresas_score_resumo.nao_entraram', 0)
            );
    }

    #[Test]
    public function competencia_fechada_sem_nenhum_snapshot(): void
    {
        // "Mês fechado no calendário que nunca teve consolidação" — nem
        // snapshot mensal, nem linha por empresa. Profissional sem carteira
        // mantém compute() no ramo sem_carteira, sem rede.
        $alvo = $this->criarUserElegivel('Sem Consolidacao');
        $this->adminLogado();

        $this->get(route('performance.show', $alvo) . '?mes=2026-05')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('periodo.is_closed', true)
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_score', [])
                ->where('empresas_score_resumo.entraram', 0)
                ->where('empresas_score_resumo.nao_entraram', 0)
            );
    }

    #[Test]
    public function isolamento_linhas_de_outro_profissional_nao_vazam(): void
    {
        $alvo  = $this->criarUserElegivel('Isolado');
        $outro = $this->criarUserElegivel('Outro Com Detalhe');
        $this->adminLogado();

        $this->seedMensal($alvo->id, '2026-06-01');
        $this->seedMensal($outro->id, '2026-06-01');

        $cOutro = $this->darCarteira($outro);
        $this->seedLinha($outro->id, $cOutro, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_score', [])
            );
    }

    #[Test]
    public function borda_sem_carteira_debora_lima_nao_gera_erro(): void
    {
        // Caso real (Débora Lima): profissional elegível pelo cargo, ZERO
        // empresas vinculadas. Cai em `sem_carteira` na consolidação — não
        // aparecer não é bug; quebrar É bug.
        $alvo = $this->criarUserElegivel('Debora Lima');
        $this->adminLogado();

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_score', [])
            );
    }
}
