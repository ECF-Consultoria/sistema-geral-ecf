<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada sincronização Adman por empresa.
 *
 * Armazena contagem de métricas criadas, atualizadas e ignoradas,
 * além de mensagem de erro quando o sync falha via HTTP.
 */
class AdmanSyncLog extends Model
{
    protected $fillable = [
        'company_id',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_message',
        'synced_at',
    ];

    protected $casts = [
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'synced_at'     => 'datetime',
    ];

    // Empresa à qual este log pertence
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
