<?php

namespace App\Models;

use App\Observers\ContratoServicoGatilhoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
// Fase 128 (plano 05, D-04) — reavalia o gate administrativo de contrato
// quando um serviço novo é vinculado ou um campo-gatilho muda (ver
// docblock do Observer).
#[ObservedBy(ContratoServicoGatilhoObserver::class)]
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
        // Phase 111 — origem/valor HubSpot (HUB-SCHEMA-02). Colunas de
        // proveniência, sem uso ainda; valor_contratado segue como o valor
        // operacional (não mexer).
        'hubspot_line_item_id',
        'hubspot_product_id',
        'hubspot_billing_frequency',
        'hubspot_billing_period',
        'hubspot_currency',
        'hubspot_valor_original',
        'hubspot_valor_original_tipo',
        'hubspot_valor_normalizado_mensal',
        'hubspot_valor_confidence',
        'hubspot_valor_warning',
        'hubspot_snapshot',
    ];

    protected $casts = [
        'valor_contratado' => 'decimal:2',
        'data_contratacao' => 'date:Y-m-d',
        'data_vencimento'  => 'date:Y-m-d',
        'ativo'            => 'boolean',
        // Phase 111 — casts decimal/json das novas colunas HubSpot (HUB-SCHEMA-02).
        'hubspot_valor_original'           => 'decimal:2',
        'hubspot_valor_normalizado_mensal' => 'decimal:2',
        'hubspot_snapshot'                 => 'array',
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
