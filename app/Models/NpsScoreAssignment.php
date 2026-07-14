<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribuição CONGELADA média×pessoa×role×serviço de 1 NpsResponse — Phase 79
 * v16.0 (DEC-79-C).
 *
 * Liga a média de uma dimensão (nps_response_scores) à PESSOA responsável
 * (`user_id`) naquele serviço/role no momento da resposta. É a base DIRETA do
 * cálculo de bônus da Fase 80 (JOIN por user_id + role).
 *
 * DECISÃO TRAVADA (DEC-79-C / A3 do RESEARCH): a coluna `role` guarda o valor da
 * pivot `company_users` — 'consultor' (=Analista de Performance) ou 'estrategista'
 * — para JOIN direto na Fase 80. NÃO usa analyst/strategist.
 *
 * `service_setor` (Servico::SETOR_*) é congelado para independer do catálogo.
 *
 * Model apenas de CONTRATO (schema + relations) para o `NpsSnapshotService`
 * (Plano 79-04). Sem LogsActivity: snapshot imutável.
 *
 * @property int $nps_response_id
 * @property int $nps_response_score_id
 * @property int $company_id
 * @property int|null $servico_id
 * @property string $service_setor
 * @property string $role
 * @property int $user_id
 * @property float $average_score
 * @property \Illuminate\Support\Carbon $assigned_at
 */
class NpsScoreAssignment extends Model
{
    use HasFactory;

    protected $table = 'nps_score_assignments';

    protected $fillable = [
        'nps_response_id',
        'nps_response_score_id',
        'company_id',
        'servico_id',
        'service_setor',
        'role',
        'user_id',
        'average_score',
        'assigned_at',
    ];

    protected $casts = [
        'average_score' => 'float',
        'assigned_at'   => 'datetime',
    ];

    /**
     * Resposta NPS dona desta atribuição. Cascade on delete.
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(NpsResponse::class, 'nps_response_id');
    }

    /**
     * Score da dimensão de onde a média foi atribuída (via nps_response_score_id).
     * Cascade on delete — apagar o score apaga a atribuição.
     */
    public function score(): BelongsTo
    {
        return $this->belongsTo(NpsResponseScore::class, 'nps_response_score_id');
    }

    /**
     * Empresa do snapshot.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Pessoa responsável (analista/estrategista) congelada nesta atribuição.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * FK viva pro catálogo (nullOnDelete). Fonte histórica é `service_setor`.
     */
    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class, 'servico_id');
    }
}
