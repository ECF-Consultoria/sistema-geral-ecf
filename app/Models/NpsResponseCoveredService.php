<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Serviço CONGELADO como coberto por 1 NpsResponse — Phase 79 v16.0 (DEC-79-C).
 *
 * Cada linha registra um serviço que a empresa tinha ATIVO no momento da
 * resposta. A coluna `service_setor` (Servico::SETOR_*) é congelada para que
 * mudanças futuras no catálogo de serviços NÃO reescrevam o histórico do NPS.
 *
 * Model apenas de CONTRATO (schema + relations) para o `NpsSnapshotService`
 * (Plano 79-04). Sem LogsActivity: snapshot imutável.
 *
 * @property int $nps_response_id
 * @property int|null $servico_id
 * @property string $service_setor
 * @property \Illuminate\Support\Carbon $captured_at
 */
class NpsResponseCoveredService extends Model
{
    use HasFactory;

    protected $table = 'nps_response_covered_services';

    protected $fillable = [
        'nps_response_id',
        'servico_id',
        'service_setor',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];

    /**
     * Resposta NPS dona desta cobertura. Cascade on delete.
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(NpsResponse::class, 'nps_response_id');
    }

    /**
     * FK viva pro catálogo de serviços (nullOnDelete). O display histórico deve
     * usar `service_setor` como fonte canônica caso o serviço seja removido.
     */
    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class, 'servico_id');
    }
}
