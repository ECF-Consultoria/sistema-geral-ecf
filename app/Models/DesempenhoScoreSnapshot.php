<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot de score de desempenho — 1 row por combinação (user, ref_date, mes_referencia).
 *
 * A tabela `desempenho_score_snapshots` armazena DUAS modalidades de row a partir da
 * Phase 74 (D-02 do 74-CONTEXT.md):
 *
 *   • Modalidade DIÁRIO (Phase 46, PRESERVADA):
 *       - `ref_date` populada (D-1 do run).
 *       - `mes_referencia = NULL`.
 *       - Gravada pelo comando `desempenho:snapshot-scores` (13:30 BRT diário).
 *       - Usada para deltas rolling (delta_vs_ontem, gráfico 30d).
 *
 *   • Modalidade MENSAL FECHADO (Phase 74, NOVA):
 *       - `ref_date = mes_referencia = YYYY-MM-01`.
 *       - Gravada pelo comando `desempenho:consolidar-mes` (dia 1 às 14:00 BRT).
 *       - Usada para ranking mensal, artigo dinâmico do Manual e cálculo da faixa
 *         de bônus + regra "2 meses consecutivos intermediário → máximo" (DESEMP-08).
 *
 * O unique key `(user_id, ref_date, mes_referencia)` (D-03) garante idempotência dos
 * `updateOrCreate` em ambos os fluxos e permite a mesma dupla (user, data) aparecer
 * 1x como diário e 1x como mensal sem colisão.
 *
 * Scopes de conveniência: `mensal()` filtra rows do fechamento mensal;
 * `diario()` filtra rows do snapshot diário legado.
 *
 * Referências:
 *  - `.planning/phases/74-.../74-CONTEXT.md` §D-01, D-02, D-03
 *  - `.planning/phases/74-.../74-SPEC.md` DESEMP-09
 */
class DesempenhoScoreSnapshot extends Model
{
    protected $table = 'desempenho_score_snapshots';

    protected $fillable = [
        'user_id',
        'ref_date',
        'mes_referencia',
        'score',
        'classificacao',
        'ranking_pos',
        'tem_base_comparativa',
        'empresas_carteira',
        'empresas_eligiveis',
        'breakdown_json',
    ];

    protected $casts = [
        'ref_date'             => 'date',
        'mes_referencia'       => 'date',
        'breakdown_json'       => 'array',
        'tem_base_comparativa' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: apenas snapshots MENSAIS FECHADOS (Phase 74 D-02).
     *
     * Filtra rows onde `mes_referencia IS NOT NULL`. Uso típico:
     *   DesempenhoScoreSnapshot::mensal()->where('mes_referencia', $mes)->get()
     *
     * Consumido pelo `PerformanceController::index`, pelo widget
     * `performance_equipe` do admin dashboard e pelo `Performance/Dashboard.jsx`
     * (view mensal default — D-18).
     */
    public function scopeMensal($query)
    {
        return $query->whereNotNull('mes_referencia');
    }

    /**
     * Scope: apenas snapshots DIÁRIOS legados (Phase 46, D-02).
     *
     * Filtra rows onde `mes_referencia IS NULL`. Uso típico:
     *   DesempenhoScoreSnapshot::diario()->where('ref_date', $data)->get()
     *
     * Consumido pelo toggle "Ver diário" do `Performance/Dashboard.jsx` para
     * exibir série rolling 30d histórica.
     */
    public function scopeDiario($query)
    {
        return $query->whereNull('mes_referencia');
    }
}
