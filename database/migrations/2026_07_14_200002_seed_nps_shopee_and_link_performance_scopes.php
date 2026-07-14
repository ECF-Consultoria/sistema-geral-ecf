<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 79 Plan 02 — Seed idempotente do modelo "NPS Shopee" (DEC-79-B) +
 * link dos serviços de setor performance ao "NPS Padrão" (DEC-79-A).
 *
 * Requisitos atendidos:
 *  - DEC-79-B — modelo "NPS Shopee" versionado, espelhando o "NPS Padrão"
 *    (3 perguntas escala + 5 opções peso 1..5) com scope no serviço shopee.
 *  - DEC-79-A — o "NPS Padrão" (is_default=true) passa a cobrir TODOS os
 *    serviços ativos setor=performance via nps_template_service_scopes.
 *
 * Motivação (RESEARCH Pitfall 1):
 *   O disparo estrito do Plano 79-03 gera 1 envio por modelo cujos serviços
 *   cobertos batem com um serviço ATIVO da empresa. Sem os scopes de
 *   performance no NPS Padrão, TODAS as empresas ML ficariam SEM NPS. Por isso
 *   este seed entra no MESMO deploy da mudança de disparo. O scope shopee no
 *   NPS Shopee habilita o 2º NPS para empresas ML + Shopee.
 *
 * Espelha o molde da Phase 68 (2026_07_07_100004_seed_nps_template_padrao...):
 *   DB::table puro, tudo dentro de DB::transaction, guards idempotentes,
 *   now() nos timestamps. Coluna FK das opções é `question_id`.
 *
 * Idempotência (safe rodar 2x consecutivas):
 *   - Template: guard `where('nome', 'NPS Shopee')->first()`.
 *   - Pergunta: guard `where(template_id, dimensao)->first()`.
 *   - Opção: guard `where(question_id, peso)->exists()`.
 *   - Scopes: `updateOrInsert` por (template_id, servico_id) — 2ª execução
 *     atualiza timestamps sem duplicar (unique nps_tpl_scope_uniq protege DB-level).
 *
 * Blindagem (Assumption A2):
 *   O serviço shopee (setor='shopee', ativo=true) foi semeado na Phase 75. O
 *   guard `if ($servicoShopeeId)` cobre a ausência (Log::warning, não aborta).
 *
 * down(): no-op intencional — reverter destruiria o template + FKs cascade
 * dropariam questions/options, e os scopes semânticos seriam perdidos. Mesmo
 * padrão do 100004 para migrations de dados semânticos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // ────────────────────────────────────────────────────────────────
            // Passo A — Template "NPS Shopee" — idempotente por `nome`.
            //   is_default=false (NÃO principal — o bônus continua no NPS Padrão
            //   por DEC-79-E). priority=10 (>0, DEC-79-B) para preceder o
            //   fallback se necessário. envio_automatico_mensal=true.
            // ────────────────────────────────────────────────────────────────
            $shopeeRow = DB::table('nps_templates')
                ->where('nome', 'NPS Shopee')
                ->first();

            if ($shopeeRow) {
                $shopeeId = $shopeeRow->id;
            } else {
                $shopeeId = DB::table('nps_templates')->insertGetId([
                    'nome'                    => 'NPS Shopee',
                    'descricao'               => 'Modelo NPS para o serviço Shopee (Gestão de ADS Shopee). Espelha o NPS Padrão: 3 perguntas escala (estrategista, analista, empresa).',
                    'active'                  => true,
                    'is_default'              => false,
                    'priority'                => 10,
                    'envio_automatico_mensal' => true,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }

            // ────────────────────────────────────────────────────────────────
            // Passo B — 3 perguntas fixas (idempotente por (template_id, dimensao)),
            //   com os MESMOS textos/tipo/obrigatoriedade/ordem do molde 100004.
            //   Placeholders {nome_estrategista}/{nome_analista} resolvidos em
            //   runtime pelo NpsTextRenderer — NÃO hardcodar nomes.
            //   Para cada, loop for i=1..5 semeando as opções (escala).
            // ────────────────────────────────────────────────────────────────
            $perguntas = [
                [
                    'dimensao'    => 'estrategista',
                    'texto'       => 'O atendimento do {nome_estrategista}',
                    'obrigatoria' => true,
                    'ordem'       => 1,
                ],
                [
                    // Renderização condicional em runtime (via company.analista_id).
                    // obrigatoria=false: quando renderizada é opcional; quando não
                    // renderizada não bloqueia o submit.
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
                    ->where('template_id', $shopeeId)
                    ->where('dimensao', $spec['dimensao'])
                    ->first();

                if ($existente) {
                    $questionId = $existente->id;
                } else {
                    $questionId = DB::table('nps_template_questions')->insertGetId([
                        'template_id' => $shopeeId,
                        'texto'       => $spec['texto'],
                        'tipo'        => 'escala',
                        'dimensao'    => $spec['dimensao'],
                        'obrigatoria' => $spec['obrigatoria'],
                        'ordem'       => $spec['ordem'],
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }

                // 5 opções (escala 1..5) — idempotente por (question_id, peso).
                // Coluna FK correta: `question_id` (NÃO template_question_id).
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
            // Passo C — Scope NPS Shopee → serviço setor=shopee (DEC-79-B).
            //   Guard A2: se o serviço shopee não estiver semeado, não aborta —
            //   loga warning estruturado e segue (o link performance é o crítico).
            // ────────────────────────────────────────────────────────────────
            $servicoShopeeId = DB::table('servicos')
                ->where('setor', 'shopee')
                ->where('ativo', true)
                ->value('id');

            if ($servicoShopeeId) {
                DB::table('nps_template_service_scopes')->updateOrInsert(
                    ['template_id' => $shopeeId, 'servico_id' => $servicoShopeeId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            } else {
                Log::warning('[Phase 79 Seed] Serviço shopee (setor=shopee, ativo=true) ausente — scope do NPS Shopee não linkado', [
                    'nps_shopee_template_id' => $shopeeId,
                ]);
            }

            // ────────────────────────────────────────────────────────────────
            // Passo D — Link NPS Padrão → TODOS os serviços ativos performance
            //   (DEC-79-A). Sem isso o disparo estrito deixaria empresas ML sem
            //   NPS. updateOrInsert por (template_id, servico_id) = idempotente.
            // ────────────────────────────────────────────────────────────────
            $padraoId = DB::table('nps_templates')
                ->where('is_default', true)
                ->value('id');

            $scopesPerformance = 0;

            if ($padraoId) {
                $servicosPerformance = DB::table('servicos')
                    ->where('setor', 'performance')
                    ->where('ativo', true)
                    ->get();

                foreach ($servicosPerformance as $servico) {
                    DB::table('nps_template_service_scopes')->updateOrInsert(
                        ['template_id' => $padraoId, 'servico_id' => $servico->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    $scopesPerformance++;
                }
            } else {
                Log::warning('[Phase 79 Seed] Template NPS Padrão (is_default=true) ausente — link performance não aplicado');
            }

            Log::info('[Phase 79 Seed] NPS Shopee semeado + scopes performance vinculados ao NPS Padrão', [
                'nps_shopee_template_id'    => $shopeeId,
                'nps_padrao_template_id'    => $padraoId,
                'scope_shopee_criado'       => (bool) $servicoShopeeId,
                'scopes_performance_padrao' => $scopesPerformance,
            ]);
        });
    }

    /**
     * Reversão: no-op intencional.
     *
     * Reverter apagaria o template "NPS Shopee" (FKs cascade dropariam
     * questions/options) e os scopes semânticos de performance no NPS Padrão —
     * quebrando o disparo estrito. Mesmo padrão consolidado do 100004 para
     * migrations de dados semânticos. Para reverter em dev, dropar as tabelas
     * NPS v15 via migration 100001.down() e recomeçar.
     */
    public function down(): void
    {
        Log::info('[Phase 79 Seed] down() é no-op intencional — reverter destruiria o NPS Shopee + scopes de performance. Ver docblock desta migration.');
    }
};
