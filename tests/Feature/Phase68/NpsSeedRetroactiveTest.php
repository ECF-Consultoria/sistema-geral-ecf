<?php

namespace Tests\Feature\Phase68;

use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 68 Plan 05 — Suite Feature do seed retroativo "NPS Padrao".
 *
 * Valida SC #3 e #4 do ROADMAP:
 *  - SC #3: seed cria template padrao + 3 perguntas + 15 options; retro-associa
 *           100% das nps_surveys legadas (template_id=NULL → PADRAO); idempotente
 *  - SC #4 parcial: snapshot per-row preservado apos edicao do texto do template
 *
 * Estrategia: como `RefreshDatabase` roda TODAS as migrations no setUp (incluindo
 * a 100004 do seed), a fixture inicial ja tem o template padrao seedado. Para
 * testar retro-associacao + idempotencia + pre-check dupes, incluimos a migration
 * como classe anonima e chamamos `up()` novamente (idempotente por design).
 *
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-05-PLAN.md
 * @see database/migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php
 */
class NpsSeedRetroactiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Carrega a migration de seed (Plan 68-03) como instancia anonima.
     * Migration files retornam `new class extends Migration {...}` — include
     * devolve o objeto pronto pra chamar up()/down() manualmente.
     *
     * Padrao usado nos testes de idempotencia/retro-associacao — permite disparar
     * o seed sob demanda sem depender de artisan (que exige DB refresh completo).
     */
    private function loadSeedMigration(): object
    {
        return include database_path(
            'migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php'
        );
    }

    /**
     * Insere survey legada DIRETO no DB (bypass Model + factory) — simula rows
     * criadas antes da Phase 68 (v3.0/v13.0). template_id=NULL e o estado
     * legitimo pre-migration 100002; a factory default tambem retorna NULL,
     * mas usamos DB::table pra deixar explicito que estamos simulando legado.
     */
    private function inserirSurveyLegado(int $companyId, string $status = 'completed', ?string $mes = null): int
    {
        return DB::table('nps_surveys')->insertGetId([
            'token'           => (string) Str::uuid(),
            'company_id'      => $companyId,
            'generated_by'    => null,
            'status'          => $status,
            'expires_at'      => now()->addDays(7),
            'completed_at'    => $status === 'completed' ? now() : null,
            'auto_generated'  => false,
            'month_reference' => $mes,
            'template_id'     => null,  // legado pre-Phase-68
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #3 parte 1 — Template padrao seedado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_seed_cria_template_padrao_com_is_default_true(): void
    {
        // RefreshDatabase ja rodou as 5 migrations. Baseline: exatamente 1
        // template com is_default=true. Unique parcial garante DB-level (Plan 68-01).
        $count = NpsTemplate::where('is_default', true)->count();
        $this->assertEquals(1, $count, 'seed deve criar exatamente 1 template padrao');

        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao);
        $this->assertEquals('NPS Padrão', $padrao->nome);
        $this->assertTrue($padrao->active);
        $this->assertEquals(0, $padrao->priority);
        $this->assertTrue(
            $padrao->envio_automatico_mensal,
            'envio_automatico_mensal=true e requisito NPS-B-04 (padrao participa do ciclo mensal)'
        );
    }

    #[Test]
    public function test_seed_cria_3_perguntas_fixas_com_dimensoes_corretas(): void
    {
        $padrao = NpsTemplate::default()->first();

        $this->assertEquals(3, $padrao->questions()->count(), 'padrao deve ter 3 perguntas fixas');

        // Ordem semantica: est → ana → empresa (research §4 + defaults NpsTextRenderer).
        $dimensoes = $padrao->questions()->orderBy('ordem')->pluck('dimensao')->all();
        $this->assertEquals(
            ['estrategista', 'analista', 'empresa'],
            $dimensoes,
            'perguntas devem estar na ordem estrategista → analista → empresa'
        );

        // Obrigatoriedade — plan 68-03 fixou est=true, analista=false (mentoria pura), empresa=true.
        $porDim = $padrao->questions()->pluck('obrigatoria', 'dimensao');
        $this->assertTrue((bool) $porDim['estrategista'], 'pergunta estrategista deve ser obrigatoria');
        $this->assertFalse((bool) $porDim['analista'],   'pergunta analista NAO e obrigatoria (renderizacao condicional Phase 71)');
        $this->assertTrue((bool) $porDim['empresa'],     'pergunta empresa deve ser obrigatoria');

        // Todas do tipo escala.
        $tipos = $padrao->questions()->pluck('tipo')->unique()->values()->all();
        $this->assertEquals(['escala'], $tipos, 'todas as perguntas devem ser tipo=escala');
    }

    #[Test]
    public function test_seed_cria_5_options_por_pergunta_com_pesos_1_a_5(): void
    {
        $padrao = NpsTemplate::default()->first();

        foreach ($padrao->questions as $q) {
            $options = $q->options()->orderBy('peso')->get();
            $this->assertCount(5, $options, "pergunta {$q->dimensao} deve ter 5 options");

            $pesos  = $options->pluck('peso')->all();
            $labels = $options->pluck('label')->all();
            $this->assertEquals([1, 2, 3, 4, 5], $pesos,  "pesos devem ser 1..5 em ordem para {$q->dimensao}");
            $this->assertEquals(['1','2','3','4','5'], $labels, "labels devem ser 1..5 para {$q->dimensao}");
        }

        // Total: 3 perguntas x 5 options = 15 options do padrao.
        $totalOptions = NpsTemplateOption::whereIn(
            'question_id',
            $padrao->questions->pluck('id')
        )->count();
        $this->assertEquals(15, $totalOptions);
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #3 parte 2 — Retro-associacao de surveys legadas
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_retro_associa_surveys_legadas_com_template_id_null(): void
    {
        // Cenario: apos migrations iniciais, criamos 5 surveys "legadas" (INSERT
        // direto via DB::table simulando rows pre-Phase-68). Depois disparamos
        // a migration up() novamente — deve retro-associar TODAS ao padrao.
        //
        // Comportamento validado: WHERE template_id IS NULL → UPDATE template_id = PADRAO_ID.
        $companies = Company::factory()->count(3)->create();

        // 5 surveys legadas em diferentes companies/status/meses distintos (evita
        // colisao no pre-check dupes que so bloqueia COMPLETED no mesmo mes).
        $this->inserirSurveyLegado($companies[0]->id, 'completed', '2026-04-01');
        $this->inserirSurveyLegado($companies[0]->id, 'completed', '2026-05-01');
        $this->inserirSurveyLegado($companies[1]->id, 'completed', '2026-06-01');
        $this->inserirSurveyLegado($companies[2]->id, 'pending',   '2026-07-01');
        $this->inserirSurveyLegado($companies[2]->id, 'expired',   null);

        $this->assertEquals(
            5,
            DB::table('nps_surveys')->whereNull('template_id')->count(),
            'baseline: 5 surveys legadas sem template_id'
        );

        // Roda o seed novamente — idempotente + retro-associa nulls.
        $this->loadSeedMigration()->up();

        $padrao = NpsTemplate::default()->first();

        // ZERO orfaos apos rerun — 100% retro-associados (SC #3).
        $this->assertEquals(
            0,
            DB::table('nps_surveys')->whereNull('template_id')->count(),
            'retro-associacao deveria zerar orfaos (template_id NULL)'
        );

        // Todas as 5 receberam template_id = padrao.
        $this->assertEquals(
            5,
            DB::table('nps_surveys')->where('template_id', $padrao->id)->count()
        );
    }

    #[Test]
    public function test_seed_e_idempotente_no_duplicates_em_rerun(): void
    {
        // SC #3 parte 3: rodar o seed 2x consecutivas nao pode duplicar linhas.
        // Guards `where->first` no seed reutilizam rows existentes.
        $tplAntes  = NpsTemplate::where('is_default', true)->count();
        $qAntes    = NpsTemplateQuestion::count();
        $optAntes  = NpsTemplateOption::count();

        $this->loadSeedMigration()->up();

        $tplDepois = NpsTemplate::where('is_default', true)->count();
        $qDepois   = NpsTemplateQuestion::count();
        $optDepois = NpsTemplateOption::count();

        $this->assertEquals($tplAntes,  $tplDepois, 'template padrao nao pode duplicar em rerun');
        $this->assertEquals($qAntes,    $qDepois,   'perguntas do padrao nao podem duplicar');
        $this->assertEquals($optAntes,  $optDepois, 'options do padrao nao podem duplicar');

        // Rodada 3 pra reforcar — segue igual.
        $this->loadSeedMigration()->up();
        $this->assertEquals($tplAntes,  NpsTemplate::where('is_default', true)->count());
        $this->assertEquals($qAntes,    NpsTemplateQuestion::count());
        $this->assertEquals($optAntes,  NpsTemplateOption::count());
    }

    #[Test]
    public function test_pre_check_dupes_detecta_e_falha_com_runtime_exception(): void
    {
        // Cenario que quebraria o unique parcial da Plan 68-04 (migration 100005)
        // em prod: 2+ surveys COMPLETED com mesma (company_id, month_reference)
        // SEM template_id. A retro-associacao popularia template_id = PADRAO em
        // ambas → unique dispara SQLSTATE 23000 em prod.
        //
        // O seed FALHA CEDO com RuntimeException listando dupes — mensagem
        // acionavel pra recovery manual.
        $company = Company::factory()->create();

        // 2 surveys COMPLETED no mesmo mes, sem template_id — cenario de dupe legado.
        $this->inserirSurveyLegado($company->id, 'completed', '2026-05-01');
        $this->inserirSurveyLegado($company->id, 'completed', '2026-05-01');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Detectadas.*dupes/');

        // Roda o seed — pre-check deve capturar o cenario e abortar.
        $this->loadSeedMigration()->up();
    }

    // ═══════════════════════════════════════════════════════════════════
    // SC #4 — snapshot per-row congelado apos edit do padrao
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_snapshot_freeze_survives_edit_do_padrao_e_delete_de_option(): void
    {
        // SC #4: mesmo apos admin editar/deletar itens do template padrao, o
        // snapshot na NpsResponseAnswer historica NAO se altera.
        // O dashboard NPS-E-05 le SEMPRE do snapshot.
        $padrao   = NpsTemplate::default()->first();
        $qEmpresa = $padrao->questions()->where('dimensao', 'empresa')->first();
        $opt4     = $qEmpresa->options()->where('peso', 4)->first();

        $response = NpsResponse::factory()->create();
        $answer   = NpsResponseAnswer::factory()->create([
            'response_id'                => $response->id,
            'template_question_id'       => $qEmpresa->id,
            'template_option_id'         => $opt4->id,
            'question_texto_snapshot'    => 'Texto ORIGINAL da pergunta empresa',
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'      => '4',
            'option_peso_snapshot'       => 4,
        ]);

        // Admin edita texto da pergunta original.
        $qEmpresa->update(['texto' => 'Texto EDITADO no CRUD Phase 70']);

        // Admin deleta a option escolhida.
        $opt4->delete();

        $answer->refresh();

        // Snapshot congelado — dashboard le disso, nao das FKs.
        $this->assertEquals('Texto ORIGINAL da pergunta empresa', $answer->question_texto_snapshot);
        $this->assertEquals('empresa', $answer->question_dimensao_snapshot);
        $this->assertEquals('4',       $answer->option_label_snapshot);
        $this->assertEquals(4,         $answer->option_peso_snapshot);

        // FK viva da option zerada (nullOnDelete); pergunta permanece pois soh
        // foi editada.
        $this->assertNull(
            $answer->template_option_id,
            'template_option_id zerado por nullOnDelete apos delete da option'
        );
        $this->assertEquals(
            $qEmpresa->id,
            $answer->template_question_id,
            'template_question_id preservado (pergunta soh foi editada, nao deletada)'
        );
    }
}
