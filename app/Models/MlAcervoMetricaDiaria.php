<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Linha imutável da série diária enxuta do acervo Mercado Livre (D-07b) —
 * responde "como este anúncio evoluiu" (visitas, vendas, nota, saúde do ML).
 * Gravada uma vez pelo sync diário e nunca atualizada depois (sem
 * updated_at), com retenção da ordem de 90 dias via comando de limpeza
 * (plano 134-07/09).
 *
 * Sem trait de auditoria do Spatie: mesma razão de MlAcervoItem — tabela de
 * snapshot alimentada por job, não por ação humana a auditar.
 */
class MlAcervoMetricaDiaria extends Model
{
    protected $table = 'ml_acervo_metricas_diarias';

    // Grava só created_at — a linha é imutável (mesmo padrão de
    // CompanyManagerHistory, log append-only).
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'ml_item_id',
        'data',
        'visitas',
        'sold_quantity',
        'nota_ecf',
        'health_ml',
    ];

    protected $casts = [
        'data'          => 'date',
        'visitas'       => 'integer',
        'sold_quantity' => 'integer',
        'nota_ecf'      => 'integer',
        'health_ml'     => 'decimal:2',
    ];
}
