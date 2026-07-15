<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 79 Plan 02 — Seed idempotente do modelo "NPS Shopee" (DEC-79-B) +
 * link dos serviços de setor performance ao "NPS Padrão" (DEC-79-A).
 *
 * Requisitos atendidos:
 *  - DEC-79-B — modelo "NPS Shopee" versionado, CLONE do modelo principal
 *    (is_default = 'NPS | Performance ECF...') com scope no serviço shopee.
 *    Copia as perguntas/opções da fonte (não hardcoded) — ajuste do usuário.
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
            // FONTE do clone = o modelo PRINCIPAL (is_default). Em produção é o
            // 'NPS | Performance ECF Consultoria & Assessoria'. O NPS Shopee é uma
            // CÓPIA FIEL dele (mesmas perguntas/opções), diferindo só no serviço
            // coberto (Shopee) e nos responsáveis (setor Shopee). Ajuste do usuário.
            $fonteId = DB::table('nps_templates')->where('is_default', true)->value('id');

            $shopeeRow = DB::table('nps_templates')
                ->where('nome', 'NPS Shopee')
                ->first();

            if ($shopeeRow) {
                $shopeeId = $shopeeRow->id;
            } else {
                $shopeeId = DB::table('nps_templates')->insertGetId([
                    'nome'                    => 'NPS Shopee',
                    'descricao'               => 'Cópia do modelo principal (Performance) com serviço coberto = Gestão de ADS Shopee. As respostas vão para os profissionais do setor Shopee.',
                    'active'                  => true,
                    'is_default'              => false,
                    'priority'                => 10,
                    'envio_automatico_mensal' => true,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }

            // ────────────────────────────────────────────────────────────────
            // Passo B — CLONA as perguntas/opções da FONTE (is_default) para o
            //   NPS Shopee. Idempotente: só clona se o NPS Shopee ainda não tem
            //   perguntas. Assim o NPS Shopee reflete EXATAMENTE o modelo real de
            //   Performance configurado (não perguntas genéricas hardcoded).
            // ────────────────────────────────────────────────────────────────
            $jaTemPerguntas = DB::table('nps_template_questions')
                ->where('template_id', $shopeeId)
                ->exists();

            if (! $jaTemPerguntas && $fonteId && $fonteId !== $shopeeId) {
                $perguntasFonte = DB::table('nps_template_questions')
                    ->where('template_id', $fonteId)
                    ->orderBy('ordem')
                    ->get();

                foreach ($perguntasFonte as $q) {
                    $novaQuestionId = DB::table('nps_template_questions')->insertGetId([
                        'template_id' => $shopeeId,
                        'texto'       => $q->texto,
                        'tipo'        => $q->tipo,
                        'dimensao'    => $q->dimensao,
                        'obrigatoria' => $q->obrigatoria,
                        'ordem'       => $q->ordem,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);

                    $opcoesFonte = DB::table('nps_template_options')
                        ->where('question_id', $q->id)
                        ->orderBy('ordem')
                        ->get();

                    foreach ($opcoesFonte as $o) {
                        DB::table('nps_template_options')->insert([
                            'question_id' => $novaQuestionId,
                            'label'       => $o->label,
                            'peso'        => $o->peso,
                            'ordem'       => $o->ordem,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                    }
                }
            } elseif (! $fonteId) {
                Log::warning('[Phase 79 Seed] Modelo principal (is_default) ausente — NPS Shopee criado SEM perguntas; configurar/duplicar na UI de configuração.');
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
