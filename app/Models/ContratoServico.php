<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ContratoServico — pivot enriquecida N:N entre Company e Servico.
 *
 * Frente A (Módulo Serviços): registra cada contrato vigente (ou já
 * desativado, para histórico) entre empresa e serviço. valor_contratado
 * permite override do valor_padrao do catálogo (pricing por empresa).
 */
class ContratoServico extends Model
{
    use LogsActivity;

    protected $table = 'contratos_servico';

    protected $fillable = [
        'company_id',
        'servico_id',
        'valor_contratado',
        'data_contratacao',
        'data_vencimento',
        'ativo',
        'observacoes',
    ];

    protected $casts = [
        'valor_contratado' => 'decimal:2',
        'data_contratacao' => 'date:Y-m-d',
        'data_vencimento'  => 'date:Y-m-d',
        'ativo'            => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'servico_id',
                'valor_contratado',
                'data_contratacao',
                'data_vencimento',
                'ativo',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match ($eventName) {
                'created' => 'Contrato de serviço criado',
                'updated' => 'Contrato de serviço atualizado',
                'deleted' => 'Contrato de serviço excluído',
                default   => $eventName,
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    /**
     * Scope: apenas contratos ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('ativo', true);
    }
}
