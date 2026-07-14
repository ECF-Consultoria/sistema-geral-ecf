<?php

namespace Tests\Feature\Phase75;

use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 75 / Plan 75-01 (DEC-1) — Suíte de migração + seed do setor "Shopee".
 *
 * Prova o fix do Pitfall 1 do RESEARCH: o SQLite (driver de teste) ENFORÇA o
 * CHECK constraint do enum `servicos.setor`. A migração de enum desta phase
 * NÃO pode pular o SQLite (como a de 'polos' fez), senão persistir um
 * Servico com setor='shopee' quebra com `SQLSTATE[23000] CHECK constraint failed`.
 *
 * Cobre:
 *  - Teste A: Servico com setor='shopee' persiste no SQLite sem CHECK failure.
 *  - Teste B: seed idempotente — o catálogo tem exatamente 1 "Shopee"; rodar
 *    o mesmo firstOrCreate de novo não duplica.
 *  - Teste C: constante Servico::SETOR_SHOPEE + array SETORES + label pt-BR.
 *
 * @group phase75
 */
class Phase75MigracaoSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TEST A — Prova do fix do Pitfall 1.
     *
     * Criar um Servico com setor='shopee' deve persistir no SQLite sem lançar
     * `CHECK constraint failed`. Se a migração de enum pular o SQLite, este
     * teste falha no INSERT.
     */
    public function test_servico_shopee_persiste_sem_check_constraint(): void
    {
        $servico = Servico::create([
            'nome'          => 'Shopee Teste ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => 'shopee',
        ]);

        $this->assertDatabaseHas('servicos', [
            'id'    => $servico->id,
            'setor' => 'shopee',
        ]);
    }

    /**
     * TEST B — Seed idempotente do serviço "Shopee".
     *
     * Após o RefreshDatabase (que roda a migração de seed 1×), o catálogo deve
     * ter exatamente 1 registro nome='Shopee' com setor='shopee'. Rodar o mesmo
     * firstOrCreate uma 2ª vez NÃO deve duplicar.
     */
    public function test_seed_shopee_idempotente(): void
    {
        // A migração de seed já rodou via RefreshDatabase.
        $this->assertEquals(
            1,
            Servico::where('nome', 'Shopee')->count(),
            'A migração de seed deve criar exatamente 1 serviço "Shopee".',
        );

        // Rodar o mesmo firstOrCreate de novo (simula re-execução do seed).
        Servico::firstOrCreate(
            ['nome' => 'Shopee'],
            [
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_SHOPEE,
            ],
        );

        $shopees = Servico::where('nome', 'Shopee')->get();
        $this->assertCount(1, $shopees, 'Rodar o seed 2× não pode duplicar "Shopee".');
        $this->assertEquals('shopee', $shopees->first()->setor);
        $this->assertTrue((bool) $shopees->first()->ativo, 'O serviço "Shopee" deve ser ativo.');
    }

    /**
     * TEST C — Constante + catálogo + label pt-BR expostos no model.
     */
    public function test_constante_e_labels_shopee_expostos(): void
    {
        $this->assertEquals('shopee', Servico::SETOR_SHOPEE);

        $this->assertContains(
            'shopee',
            Servico::SETORES,
            'Servico::SETORES deve incluir shopee (espelha o enum do schema).',
        );

        $labels = Servico::setoresLabels();
        $this->assertArrayHasKey('shopee', $labels);
        $this->assertEquals('Shopee', $labels['shopee']);
    }
}
