<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Envio ao FULL registrado no Planejador — é a "Entrada pendente" que a API do
 * ML não entrega. Enquanto `a_caminho`, cada item soma (quantidade − recebido)
 * ao estoque a caminho; a coleta diária abate o recebido pelas operações
 * INBOUND_RECEPTION e fecha o envio quando tudo chegou.
 */
class FullEnvio extends Model
{
    use LogsActivity;

    protected $table = 'full_envios';

    public const STATUS_A_CAMINHO = 'a_caminho';
    public const STATUS_RECEBIDO  = 'recebido';
    public const STATUS_CANCELADO = 'cancelado';

    public const STATUS = [self::STATUS_A_CAMINHO, self::STATUS_RECEBIDO, self::STATUS_CANCELADO];

    public const ORIGEM_PLANEJADOR = 'planejador';
    public const ORIGEM_PLANILHA   = 'planilha';
    /** Registrado pelo cliente no link aberto do planejador (sem login, sem autor). */
    public const ORIGEM_CLIENTE    = 'cliente';

    protected $fillable = [
        'company_id', 'data_despacho', 'inbound_ml', 'chegadas_desde', 'status', 'origem', 'observacao',
        'criado_por', 'recebido_em', 'conferido_em',
    ];

    protected $casts = [
        'data_despacho' => 'date:Y-m-d',
        'chegadas_desde' => 'datetime',
        'recebido_em'   => 'datetime',
        'conferido_em'  => 'datetime',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(FullEnvioItem::class, 'envio_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('planejador_full')
            ->logOnly(['data_despacho', 'inbound_ml', 'status', 'observacao'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'Envio ao FULL registrado',
                'updated' => 'Envio ao FULL atualizado',
                'deleted' => 'Envio ao FULL excluído',
                default   => $evento,
            });
    }
}
