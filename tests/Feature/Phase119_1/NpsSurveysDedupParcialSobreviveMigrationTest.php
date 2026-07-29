<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 05 — Teste de regressão do índice parcial de dedup
 * (`nps_surveys_dedup_uniq`) apos a migration de `group_survey_id`.
 *
 * -----------------------------------------------------------------------------
 * Contexto do bug (fix nesta mesma fase)
 * -----------------------------------------------------------------------------
 *
 *   A migration `2026_07_29_100001_add_group_survey_id_to_nps_surveys.php`
 *   usa `Schema::table()` com `foreignId(...)->constrained()`. No SQLite,
 *   isso obriga o Laravel a RECONSTRUIR a tabela `nps_surveys` (cria nova,
 *   copia dados, dropa a antiga, renomeia) — e a reconstrução recria os
 *   índices SEM o predicado `WHERE` do índice parcial criado na Fase 68
 *   (`database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php`).
 *   O parcial (só bloqueia `status = 'completed'`) degradava silenciosamente
 *   para um UNIQUE TOTAL em (company_id, month_reference, template_id),
 *   bloqueando também surveys `pending` — quebrando a idempotência do
 *   `nps:disparar-mensal` e derrubando 6 testes da suíte.
 *
 *   Este teste é a rede que impede a PRÓXIMA migration de repetir o erro:
 *   prova que, apos rodar TODAS as migrations (incluindo a de
 *   `group_survey_id`), o índice de dedup continua PARCIAL — pending
 *   duplicado permitido, completed duplicado bloqueado.
 *
 * @see database/migrations/2026_07_29_100001_add_group_survey_id_to_nps_surveys.php
 * @see database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php
 * @see tests/Feature/Phase68/NpsSchemaTest.php (molde original — mesma semantica)
 */
class NpsSurveysDedupParcialSobreviveMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function permite_duas_surveys_pending_com_mesma_tupla_apos_migration_group_survey_id(): void
    {
        // Prova que o índice NÃO degradou para unique total: 2 surveys
        // pending com a MESMA tupla (company_id, month_reference,
        // template_id) devem coexistir sem QueryException.
        $company  = Company::factory()->create();
        $template = NpsTemplate::default()->first();
        $this->assertNotNull($template, 'template padrao deveria estar seedado');

        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'pending',
            'completed_at'    => null,
        ]);

        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'pending',
            'completed_at'    => null,
        ]);

        // whereDate: month_reference e' persistido com componente de hora
        // ("2026-07-01 00:00:00", cast 'date' do Model gera datetime zerado),
        // where() com string pura de data nao bate por causa da parte de hora.
        $this->assertEquals(
            2,
            NpsSurvey::where('company_id', $company->id)
                ->whereDate('month_reference', '2026-07-01')
                ->where('template_id', $template->id)
                ->count(),
            '2 surveys pending com tupla identica deveriam coexistir (indice parcial, nao total)'
        );
    }

    #[Test]
    public function bloqueia_duas_surveys_completed_com_mesma_tupla_apos_migration_group_survey_id(): void
    {
        // Prova que o índice continua ATIVO para o caso que ele deve
        // bloquear: 2 surveys completed com a mesma tupla disparam
        // QueryException 23xxx (regra original da Fase 68, inalterada).
        $company  = Company::factory()->create();
        $template = NpsTemplate::default()->first();
        $this->assertNotNull($template, 'template padrao deveria estar seedado');

        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'completed',
            'completed_at'    => now(),
        ]);

        $this->expectException(QueryException::class);

        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'month_reference' => '2026-07-01',
            'status'          => 'completed',
            'completed_at'    => now(),
        ]);
    }
}
