<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Invalidação de resultado de uma empresa para BÔNUS, por competência
 * (item 3/4, 2026-07-21). A presença da linha = empresa invalidada naquela
 * competência; remover a linha = reverter. Ver migration
 * `create_bonus_invalidacoes_table`.
 *
 * A competência é sempre o MÊS FINANCEIRO M (1º dia do mês). O NPS de M,
 * coletado em M+1, também é excluído usando esta mesma competência M — não
 * o mês deslocado (ver `DesempenhoScoreService`).
 */
class BonusInvalidacao extends Model
{
    use LogsActivity;

    protected $table = 'bonus_invalidacoes';

    protected $fillable = [
        'company_id',
        'competencia',
        'motivo',
        'invalidated_by',
    ];

    protected $casts = [
        'competencia' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'competencia', 'motivo', 'invalidated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'invalidou empresa para bônus',
                'deleted' => 'reativou empresa para bônus',
                default   => "bônus invalidação {$event}",
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invalidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidated_by');
    }

    /**
     * IDs de empresas invalidadas numa competência (mês financeiro M).
     * Fonte única para o filtro do `DesempenhoScoreService` — usar sempre
     * `startOfMonth()` na chamada para bater com a chave gravada.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function companyIdsInvalidadas(Carbon $competencia): \Illuminate\Support\Collection
    {
        return static::query()
            ->whereDate('competencia', $competencia->copy()->startOfMonth()->toDateString())
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id);
    }
}
