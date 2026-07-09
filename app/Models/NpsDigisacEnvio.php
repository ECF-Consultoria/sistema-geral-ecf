<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de cada disparo do canal WhatsApp/Digisac do comando `nps:disparar-mensal`
 * (v15.5 — espelho de NpsEmailEnvio para o canal Digisac).
 *
 * Persistido por NpsDigisacDispatchService após a chamada à API Digisac —
 * um registro por (survey, canal digisac) por execução.
 *
 * Status enum:
 *   - 'enviado' — API Digisac retornou 2xx (não garante entrega no aparelho)
 *   - 'falha'   — exceção lançada pelo client (HTTP 5xx, timeout, token invalido)
 *   - 'skipped' — canal digisac ativo mas empresa sem grupo mapeado; motivo em erro_msg
 *
 * Relacionamento `survey_id` é nullable pelo mesmo motivo do NpsEmailEnvio:
 * preserva log de auditoria mesmo se a survey for deletada.
 *
 * @see app/Services/Digisac/NpsDigisacDispatchService.php
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see database/migrations/2026_07_08_180001_create_nps_digisac_envios_table.php
 *
 * @property int|null       $survey_id
 * @property int            $company_id
 * @property string|null    $service_id
 * @property string|null    $user_id
 * @property string|null    $contact_id
 * @property string|null    $grupo_nome_snapshot
 * @property string|null    $mensagem
 * @property string         $status
 * @property string|null    $provider_message_id
 * @property string|null    $erro_msg
 * @property array|null     $metadata
 */
class NpsDigisacEnvio extends Model
{
    protected $table = 'nps_digisac_envios';

    protected $fillable = [
        'survey_id',
        'company_id',
        'service_id',
        'user_id',
        'contact_id',
        'grupo_nome_snapshot',
        'mensagem',
        'status',
        'provider_message_id',
        'erro_msg',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public const STATUS_ENVIADO = 'enviado';
    public const STATUS_FALHA   = 'falha';
    public const STATUS_SKIPPED = 'skipped';

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
