<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 68 Plan 03 — Seed do template "NPS Padrão" (fallback) + retro-associação
 * de 100% das nps_surveys legadas ao template padrão via `template_id`.
 *
 * Requisitos atendidos:
 *  - NPS-A-03 — seed retroativo + fallback default
 *  - SC #3/5 do ROADMAP v15.0 — histórico legado 100% coberto, zero regressão
 *
 * Motivação:
 *   Sem esta migration, dashboards existentes ficariam quebrados após a Phase 68
 *   porque nps_surveys históricas (v3.0/v13.0) não teriam template. O template
 *   "NPS Padrão" (`is_default=true`) é o fallback semântico do
 *   NpsTemplateService::resolveForCompany (Phase 69) quando nenhum service_scope
 *   bate — indispensável para o disparo mensal automatizado nunca ficar sem
 *   template resolvido (NPS-B-04).
 *
 * Estrutura do template padrão (research §4 + §5):
 *   1 template (is_default=true, priority=0, active=true)
 *     ├─ 3 perguntas fixas (tipo escala, herdadas de NpsTextRenderer::defaults()):
 *     │    ├─ dimensao=estrategista  — obrigatoria=true,  ordem=1
 *     │    ├─ dimensao=analista      — obrigatoria=false, ordem=2  (renderização
 *     │    │                            condicional em runtime — Phase 71 decide
 *     │    │                            via company.analista_id)
 *     │    └─ dimensao=empresa       — obrigatoria=true,  ordem=3
 *     └─ 5 opções por pergunta (escala 1..5, label="1".."5", peso=1..5, ordem=1..5)
 *
 * Placeholders {nome_estrategista}/{nome_analista} ficam no texto da pergunta e
 * são resolvidos em runtime pelo NpsTextRenderer::render() — a mesma lógica que
 * a Phase 31 usa hoje. NÃO hardcodar "Rafael" ou nome real de estrategista.
 *
 * Idempotência (safe rodar 2x consecutivas):
 *   - Guard `where('is_default', true)->first()` antes de inserir template
 *   - Guard `where(template_id, dimensao)->first()` antes de inserir pergunta
 *   - Guard `where(question_id, peso)->exists()` antes de inserir option
 *   - Retro-associação com `WHERE template_id IS NULL` — 2ª execução afeta 0 rows
 *
 * Pre-check anti-dupes:
 *   Antes da retro-associação, verifica se existem 2+ surveys COMPLETED no
 *   mesmo (company_id, month_reference) SEM template_id — se sim, ambas
 *   receberiam template_id=PADRAO e o unique parcial da Plan 68-04 (migration
 *   100005) falharia em prod. Falhamos CEDO com mensagem clara pra proteger o
 *   deploy do unique index.
 *
 * Ordem em prod (fixada pelos timestamps):
 *   100001 — cria 5 tabelas
 *   100002 — adiciona nps_surveys.template_id
 *   100003 — score_estrategista/empresa NULLABLE
 *   100004 — [este arquivo] seed + retro-associação
 *   100005 — dedup_key com unique parcial (rodada por último; pre-check protege)
 *
 * Transacional: DB::transaction() envolve seed + retro-associação — falha
 * parcial reverte tudo. Fallback defensivo pra corrupção individual de survey:
 * catch por row + log warning + skip, sem abortar batch (spec ROADMAP).
 *
 * Referências:
 *  - .planning/research/v15-nps-templates-schema.md §4 (is_default fallback + priority)
 *  - .planning/research/v15-nps-templates-schema.md §5 (escala com 5 options auto-geradas)
 *  - app/Support/NpsTextRenderer.php linhas 43-45 (fonte dos textos default)
 *  - database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php (5 tabelas base)
 *  - database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php (unique parcial protegido)
 *
 * down(): no-op intencional — reverter apagaria o template padrão + FKs cascade
 * dropariam questions/options, e nps_surveys ficariam com template_id órfão.
 * Padrão do projeto para migrations de dados semânticos (Phase 31 D-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // ────────────────────────────────────────────────────────────────
            // 1. Criar (ou reutilizar) o template "NPS Padrão" — idempotente
            //    Guard where('is_default', true) garante que 2ª execução reusa
            //    o mesmo template sem duplicar. O unique parcial em is_default
            //    (Plan 68-01) já protege DB-level, mas o guard evita atingir
            //    o SQLSTATE 23000 em re-runs limpos.
            // ────────────────────────────────────────────────────────────────
            $padraoRow = DB::table('nps_templates')
                ->where('is_default', true)
                ->first();

            if ($padraoRow) {
                $padraoId = $padraoRow->id;
            } else {
                $padraoId = DB::table('nps_templates')->insertGetId([
                    'nome'                    => 'NPS Padrão',
                    'descricao'               => 'Template padrão herdado da v3.0/v13.0: 3 perguntas fixas (estrategista, analista, empresa) escala 1-5. Fallback para empresas sem template específico associado via service_scopes.',
                    'active'                  => true,
                    'is_default'              => true,
                    'priority'                => 0,
                    'envio_automatico_mensal' => true,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }

            // ────────────────────────────────────────────────────────────────
            // 2. Semear 3 perguntas fixas — idempotente por (template_id, dimensao)
            //    Textos vindos de NpsTextRenderer::defaults() (fonte da verdade
            //    legacy) — placeholders {nome_estrategista}/{nome_analista}
            //    resolvidos em runtime pelo renderer, NÃO hardcodar nomes.
            // ────────────────────────────────────────────────────────────────
            $perguntas = [
                [
                    'dimensao'    => 'estrategista',
                    'texto'       => 'O atendimento do {nome_estrategista}',
                    'obrigatoria' => true,
                    'ordem'       => 1,
                ],
                [
                    // Renderização condicional em runtime — Phase 71 decide via
                    // company.analista_id se essa pergunta aparece no form.
                    // Por isso obrigatoria=false: quando renderizada é opcional
                    // e quando NÃO renderizada não bloqueia submit.
                    'dimensao'    => 'analista',
                    'texto'       => 'O atendimento do {nome_analista}',
                    'obrigatoria' => false,
                    'ordem'       => 2,
                ],
                [
                    'dimensao'    => 'empresa',
                    'texto'       => 'A ECF está atendendo suas expectativas?',
                    'obrigatoria' => true,
                    'ordem'       => 3,
                ],
            ];

            foreach ($perguntas as $spec) {
                $existente = DB::table('nps_template_questions')
                    ->where('template_id', $padraoId)
                    ->where('dimensao', $spec['dimensao'])
                    ->first();

                if ($existente) {
                    $questionId = $existente->id;
                } else {
                    $questionId = DB::table('nps_template_questions')->insertGetId([
                        'template_id' => $padraoId,
                        'texto'       => $spec['texto'],
                        'tipo'        => 'escala',
                        'dimensao'    => $spec['dimensao'],
                        'obrigatoria' => $spec['obrigatoria'],
                        'ordem'       => $spec['ordem'],
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }

                // ────────────────────────────────────────────────────────────
                // 3. Semear 5 options (escala 1..5) — idempotente por (question_id, peso)
                //    Coluna FK correta: `question_id` (Plan 68-01, migration 100001).
                //    NÃO usar `template_question_id` — esse nome existe em
                //    nps_response_answers, tabela distinta.
                // ────────────────────────────────────────────────────────────
                for ($i = 1; $i <= 5; $i++) {
                    $optExiste = DB::table('nps_template_options')
                        ->where('question_id', $questionId)
                        ->where('peso', $i)
                        ->exists();

                    if (! $optExiste) {
                        DB::table('nps_template_options')->insert([
                            'question_id' => $questionId,
                            'label'       => (string) $i,
                            'peso'        => $i,
                            'ordem'       => $i,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                    }
                }
            }

            // ────────────────────────────────────────────────────────────────
            // 3.5. PRE-CHECK anti-dupes legadas
            //
            // Motivo: Plan 68-04 (migration 100005) cria unique parcial em
            // (company_id, month_reference, template_id) filtrando WHERE
            // status='completed' AND completed_at IS NOT NULL. Se o legado
            // tem 2+ surveys COMPLETED no mesmo (company_id, month_reference)
            // SEM template_id, a retro-associação abaixo populará template_id
            // = PADRAO em ambas → 100005 falha ao criar o unique index em prod.
            //
            // Falhar CEDO aqui é MUITO melhor que quebrar deploy no unique.
            // Mensagem lista até 5 pares afetados pra facilitar o cleanup manual
            // (script keep-latest-per-key delete others antes de re-migrar).
            // ────────────────────────────────────────────────────────────────
            $dupes = DB::table('nps_surveys')
                ->select('company_id', 'month_reference', DB::raw('COUNT(*) as qtd'))
                ->whereNull('template_id')
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->groupBy('company_id', 'month_reference')
                ->having('qtd', '>', 1)
                ->get();

            if ($dupes->isNotEmpty()) {
                $exemplos = $dupes->take(5)
                    ->map(fn ($d) => "company_id={$d->company_id} month={$d->month_reference} (qtd={$d->qtd})")
                    ->implode('; ');

                throw new \RuntimeException(
                    "[Phase 68 Seed] Detectadas {$dupes->count()} dupes legadas de nps_surveys COMPLETED no mesmo (company_id, month_reference). "
                    . "A retro-associação populaia template_id=PADRAO em ambas e o unique parcial (Plan 68-04, migration 100005) falharia em prod. "
                    . "Resolver manualmente antes de rodar migrations (script: keep-latest-per-key delete others). Exemplos: {$exemplos}"
                );
            }

            // ────────────────────────────────────────────────────────────────
            // 4. Retro-associação de surveys legadas — WHERE template_id IS NULL
            //
            // Rodar como UPDATE em massa (uma query) — atômico dentro da
            // transaction. Se por corrupção rara o UPDATE em massa falhar,
            // fallback defensivo: iterar por survey isolando falha individual
            // + log warning + skip individual, sem abortar batch inteiro.
            // ────────────────────────────────────────────────────────────────
            $afetados = 0;

            try {
                $afetados = DB::table('nps_surveys')
                    ->whereNull('template_id')
                    ->update([
                        'template_id' => $padraoId,
                        'updated_at'  => now(),
                    ]);
            } catch (\Throwable $e) {
                // Fallback: iterar por survey pra isolar falha individual
                Log::warning('[Phase 68 Seed] UPDATE em massa falhou; caindo pra iteração por survey', [
                    'error' => $e->getMessage(),
                ]);

                DB::table('nps_surveys')
                    ->whereNull('template_id')
                    ->orderBy('id')
                    ->each(function ($survey) use ($padraoId, &$afetados) {
                        try {
                            DB::table('nps_surveys')
                                ->where('id', $survey->id)
                                ->update([
                                    'template_id' => $padraoId,
                                    'updated_at'  => now(),
                                ]);
                            $afetados++;
                        } catch (\Throwable $inner) {
                            Log::warning('[Phase 68 Seed] Falha ao retro-associar survey individual', [
                                'survey_id' => $survey->id,
                                'error'     => $inner->getMessage(),
                            ]);
                            // NÃO abort — segue pra próxima survey
                        }
                    });
            }

            Log::info('[Phase 68 Seed] Template NPS Padrão semeado e retro-associação concluída', [
                'template_padrao_id' => $padraoId,
                'surveys_afetados'   => $afetados,
                'orphans_restantes'  => DB::table('nps_surveys')->whereNull('template_id')->count(),
            ]);
        });
    }

    /**
     * Reversão: no-op intencional.
     *
     * Reverter este seed apagaria o template padrão. As FKs cascade
     * (nps_template_questions.template_id, nps_template_options.question_id)
     * dropariam questions/options junto. Além disso, nps_surveys.template_id
     * é nullOnDelete — surveys ficariam com template_id NULL e re-migrate
     * subsequente tentaria retro-associar de novo com um novo padrão_id
     * (dessincronia com histórico de snapshot).
     *
     * Se precisar reverter esta migration em desenvolvimento, dropar as 5
     * tabelas via migration 100001.down() (cascade completo) e recomeçar.
     *
     * Padrão consolidado do projeto para migrations de dados semânticos:
     * Phase 31 D-10 (score_analista nullable) e Phase 68 Plan 01 (score_*
     * nullable) usam a mesma estratégia de down() no-op informativo.
     */
    public function down(): void
    {
        Log::info('[Phase 68 Seed] down() é no-op intencional — reverter destruiria template padrão + histórico snapshot. Ver docblock desta migration para instruções de rollback em dev.');
    }
};
