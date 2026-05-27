<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Servico — entrada no catálogo de serviços oferecidos pela ECF.
 *
 * Frente A (Módulo Serviços): catálogo mestre que alimenta o modal de
 * contrato em Companies/Show. Activity log via spatie/laravel-activitylog
 * (consistente com User/Company/Goal).
 */
class Servico extends Model
{
    use LogsActivity;

    protected $table = 'servicos';

    protected $fillable = [
        'nome',
        'valor_padrao',
        'tipo_cobranca',
        'ativo',
    ];

    protected $casts = [
        'valor_padrao' => 'decimal:2',
        'ativo'        => 'boolean',
    ];

    // ─── Constants de tipo de cobrança ──────────────────────────────────────
    public const TIPO_MENSAL = 'mensal';
    public const TIPO_UNICA  = 'unica';

    /**
     * Mapeamento tipo → label para UI/relatórios.
     */
    public static function tiposCobranca(): array
    {
        return [
            self::TIPO_MENSAL => 'Mensal',
            self::TIPO_UNICA  => 'Única',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome', 'valor_padrao', 'tipo_cobranca', 'ativo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match ($eventName) {
                'created' => 'Serviço criado',
                'updated' => 'Serviço atualizado',
                'deleted' => 'Serviço excluído',
                default   => $eventName,
            });
    }

    /**
     * Contratos vinculados a este serviço (ativos + inativos).
     */
    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoServico::class);
    }

    /**
     * Scope: apenas serviços ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('ativo', true);
    }
}
