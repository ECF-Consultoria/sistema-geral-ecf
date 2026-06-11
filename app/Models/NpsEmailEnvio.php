<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de cada disparo do comando `nps:disparar-mensal` (Phase 32, Plan 01).
 *
 * Persistido pelo comando logo após o `Mail::to(...)->send(...)` (em sucesso)
 * ou no `catch \Throwable` (em falha). Powered pela tabela `nps_email_envios`
 * (D-04 LOCKED).
 *
 * Status enum:
 *   - 'enviado' — SMTP aceitou (não garante delivery; só ack do MTA)
 *   - 'falha'   — exceção lançada por Mail::send; erro_msg contém substring(65000) da exceção
 *
 * Relacionamento `survey_id` é nullable porque o cascade delete da survey
 * preserva o log de auditoria (FK nullable + cascade onDelete na migration).
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see database/migrations/2026_06_11_200001_create_nps_email_envios_table.php
 * @see .planning/phases/32-customizacao-nps/32-CONTEXT.md (D-04)
 */
class NpsEmailEnvio extends Model
{
    protected $table = 'nps_email_envios';

    protected $fillable = [
        'survey_id',
        'company_id',
        'destinatario',
        'assunto',
        'status',
        'erro_msg',
    ];

    /**
     * Survey gerada por este envio (pode ser null se a survey foi deletada).
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    /**
     * Empresa destinatária — sempre presente.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
