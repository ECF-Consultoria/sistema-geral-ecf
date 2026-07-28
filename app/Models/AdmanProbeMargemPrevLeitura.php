<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo Eloquent insert-only de uma leitura do probe de estabilidade de
 * `percentageMargin.prev` da Adman (Fase 117, gate MPP-04).
 *
 * Cada linha é o fato bruto de UMA leitura HTTP (sem cache — `forceRefresh:
 * true` no `AdmanService::fetchAccountMetricsDetailedCached()`) de UMA
 * empresa da amostra, num momento real (`lida_em`). É escrita exclusivamente
 * pelo comando `adman:probe-margem-prev` e reconsultada depois pelo modo
 * `--relatorio` do mesmo comando para agregar o veredito do gate — nunca
 * conferida por stdout (D-10 do CONTEXT).
 *
 * Sem `LogsActivity`: não é um modelo de domínio auditável, é o próprio log
 * — mesma filosofia de `MlbSyncVendasLog`.
 */
class AdmanProbeMargemPrevLeitura extends Model
{
    // ─── Campos preenchíveis ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'periodo_key',
        'lida_em',
        'janela_esperada',
        'value',
        'prev',
        'diff_nativo',
        'margem_var_pp',
        'nota_regua',
        'leitura_hash',
        'http_falhou',
    ];

    // ─── Casts de tipos ───────────────────────────────────────────────────────

    protected $casts = [
        'lida_em'       => 'datetime',
        'value'         => 'float',
        'prev'          => 'float',
        'diff_nativo'   => 'float',
        'margem_var_pp' => 'float',
        'nota_regua'    => 'integer',
        'http_falhou'   => 'boolean',
    ];

    // ─── Relações ─────────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
