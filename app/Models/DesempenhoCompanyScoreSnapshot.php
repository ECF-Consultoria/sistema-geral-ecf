<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fato por empresa da Fase 122 (SNAP-02) — 1 row por
 * `(user_id, company_id, mes_referencia)`, descrevendo como a nota do
 * profissional naquela competência foi formada, empresa por empresa. É a
 * versão consultável do que hoje só existe dentro do `breakdown_json` de
 * `DesempenhoScoreSnapshot` como um blob.
 *
 * Quem escreve é SEMPRE `App\Services\Desempenho\CompanyScoreSnapshotWriter`
 * — nunca um controller, nunca `updateOrCreate` direto fora do writer.
 *
 * Decisões de design (122-01-PLAN.md):
 *   D-122-01 — `mes_referencia` é sempre `YYYY-MM-01` e NOT NULL. Diferente
 *   de `DesempenhoScoreSnapshot` (que usa `mes_referencia = NULL` para
 *   marcar a modalidade diária), esta tabela descreve a COMPETÊNCIA, nunca
 *   um dia isolado.
 *   D-122-02 — trava de congelamento por `origem`: uma linha escrita por
 *   `consolidar_mes` nunca é sobrescrita por `snapshot_diario`/`warm_cache`
 *   na mesma competência.
 *   D-122-03 — `sync()` do writer é upsert + prune numa transação, nunca
 *   insert-only: re-execução sobre a mesma competência CONVERGE para o
 *   mesmo conjunto de linhas, nunca acumula nem deixa fantasma de empresa
 *   que saiu da carteira.
 *
 * @see .planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-01-PLAN.md
 * @see App\Services\Desempenho\CompanyScoreSnapshotWriter
 */
class DesempenhoCompanyScoreSnapshot extends Model
{
    protected $table = 'desempenho_company_score_snapshots';

    protected $fillable = [
        'user_id',
        'company_id',
        'mes_referencia',
        'company_name',
        'fonte_financeira',
        'status',
        'nps_pontos',
        'faturamento_atual',
        'faturamento_anterior',
        'faturamento_var_pct',
        'faturamento_pontos',
        'margem_pct_atual',
        'margem_pct_anterior',
        'margem_var_pp',
        'margem_pontos',
        'componentes_presentes',
        'nota_empresa',
        'nota_empresa_parcial',
        'quality',
        'origem',
        'gerado_em',
    ];

    protected $casts = [
        'mes_referencia'        => 'date',
        'gerado_em'             => 'datetime',
        'quality'               => 'array',
        'nps_pontos'            => 'float',
        'faturamento_atual'     => 'float',
        'faturamento_anterior'  => 'float',
        'faturamento_var_pct'   => 'float',
        'faturamento_pontos'    => 'float',
        'margem_pct_atual'      => 'float',
        'margem_pct_anterior'   => 'float',
        'margem_var_pp'         => 'float',
        'margem_pontos'         => 'float',
        'nota_empresa'          => 'float',
        'nota_empresa_parcial'  => 'float',
        'componentes_presentes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope: linhas da competência informada — compara pelo 1º dia do mês,
     * ignorando qualquer componente de dia que porventura chegue em `$mes`.
     */
    public function scopeDaCompetencia(Builder $query, Carbon|string $mes): Builder
    {
        $mesStr = $mes instanceof Carbon
            ? $mes->copy()->startOfMonth()->toDateString()
            : Carbon::parse($mes)->startOfMonth()->toDateString();

        return $query->whereDate('mes_referencia', $mesStr);
    }

    /**
     * Scope: linhas de um profissional específico.
     */
    public function scopeDoUsuario(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
