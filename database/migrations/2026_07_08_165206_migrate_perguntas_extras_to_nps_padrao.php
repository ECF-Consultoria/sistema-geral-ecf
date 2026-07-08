<?php

use App\Models\NpsPerguntaCustomizada;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migração pós-Phase 73 (Milestone v15.0) — restaura as "perguntas extras"
 * (NpsPerguntaCustomizada, v13.0) dentro do template "NPS Padrão" (Phase 68-03).
 *
 * Motivação (feedback UX 2026-07-08): o admin sentiu que "o modelo NPS que
 * rodava em produção sumiu" — as 3 perguntas fixas (estrategista/analista/
 * empresa) foram seedadas na Phase 68, mas as N perguntas extras cadastradas
 * pelo admin ao longo dos meses ficaram órfãs no template legacy — o
 * template v15 "NPS Padrão" nasceu sem elas.
 *
 * Esta migração copia idempotentemente:
 *   NpsPerguntaCustomizada (ativa=true) → NpsTemplateQuestion (dimensao=geral)
 *   .opcoes                             → NpsTemplateOption (label + peso)
 *
 * Mapeamento de tipos:
 *   escala_1_5 → tipo=escala, auto-gera 5 options (labels 1..5, pesos 1..5)
 *   sim_nao    → tipo=opcoes, 2 options: [Sim peso=5, Não peso=1]
 *   multipla   → tipo=opcoes, N options com labels de $opcoes e peso=3 (neutro)
 *   texto      → PULADO (v15 template não suporta texto livre; comment field
 *                do Respond.jsx cobre esse caso separadamente)
 *
 * Idempotente por chave (template_id + texto): se pergunta já existe no
 * template com o mesmo texto, pula. Reruns são no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: tabela pode não existir em ambientes fresh (raro, mas seguro).
        if (! DB::getSchemaBuilder()->hasTable('nps_perguntas_customizadas')) {
            Log::info('[migrate_perguntas_extras] nps_perguntas_customizadas ausente — nada a migrar.');
            return;
        }

        $padrao = NpsTemplate::where('is_default', true)->first();
        if (! $padrao) {
            Log::warning('[migrate_perguntas_extras] NPS Padrão ausente — abortar (seed 100004 deveria ter criado).');
            return;
        }

        $extras = NpsPerguntaCustomizada::where('ativa', true)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get();

        if ($extras->isEmpty()) {
            Log::info('[migrate_perguntas_extras] Nenhuma pergunta extra ativa — nada a migrar.');
            return;
        }

        DB::transaction(function () use ($padrao, $extras) {
            $ordemBase = (int) NpsTemplateQuestion::where('template_id', $padrao->id)->max('ordem');

            foreach ($extras as $extra) {
                // Pula tipo=texto (v15 sem suporte a texto livre em pergunta).
                if ($extra->tipo === 'texto') {
                    Log::info('[migrate_perguntas_extras] pulando tipo=texto', [
                        'extra_id' => $extra->id,
                        'texto'    => $extra->texto,
                    ]);
                    continue;
                }

                // Idempotência: guard por (template_id, texto).
                $existente = NpsTemplateQuestion::where('template_id', $padrao->id)
                    ->where('texto', $extra->texto)
                    ->first();

                if ($existente) {
                    continue;
                }

                $ordemBase++;
                $tipoV15 = match ($extra->tipo) {
                    'escala_1_5' => 'escala',
                    'sim_nao'    => 'opcoes',
                    'multipla'   => 'opcoes',
                    default      => 'opcoes',
                };

                $pergunta = NpsTemplateQuestion::create([
                    'template_id' => $padrao->id,
                    'texto'       => $extra->texto,
                    'tipo'        => $tipoV15,
                    'dimensao'    => 'geral',
                    'obrigatoria' => (bool) $extra->obrigatorio,
                    'ordem'       => $ordemBase,
                ]);

                // Popula opções conforme tipo original.
                switch ($extra->tipo) {
                    case 'escala_1_5':
                        for ($i = 1; $i <= 5; $i++) {
                            NpsTemplateOption::create([
                                'question_id' => $pergunta->id,
                                'label'       => (string) $i,
                                'peso'        => $i,
                                'ordem'       => $i,
                            ]);
                        }
                        break;

                    case 'sim_nao':
                        NpsTemplateOption::create([
                            'question_id' => $pergunta->id,
                            'label'       => 'Sim',
                            'peso'        => 5,
                            'ordem'       => 1,
                        ]);
                        NpsTemplateOption::create([
                            'question_id' => $pergunta->id,
                            'label'       => 'Não',
                            'peso'        => 1,
                            'ordem'       => 2,
                        ]);
                        break;

                    case 'multipla':
                        $opcoes = is_array($extra->opcoes) ? $extra->opcoes : [];
                        foreach ($opcoes as $idx => $label) {
                            NpsTemplateOption::create([
                                'question_id' => $pergunta->id,
                                'label'       => (string) $label,
                                'peso'        => 3, // neutro — admin ajusta na UI
                                'ordem'       => $idx + 1,
                            ]);
                        }
                        break;
                }

                Log::info('[migrate_perguntas_extras] migrada', [
                    'extra_id'    => $extra->id,
                    'question_id' => $pergunta->id,
                    'tipo_v13'    => $extra->tipo,
                    'tipo_v15'    => $tipoV15,
                ]);
            }
        });
    }

    /**
     * Down: não remove — as perguntas migradas viram parte legítima do template
     * NPS Padrão. Reverter apagaria trabalho válido do admin.
     */
    public function down(): void
    {
        // no-op intencional
    }
};
