<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 202-08 — contrato ASSINADO lido do acervo da Clicksign
 * (`contratos:ler-clicksign`). É a fonte da verdade do término do contrato:
 * `assinado_em` + `vigencia_meses` (decisão do usuário, 09/11/2026).
 *
 * Só conta para o término quando `situacao = casado` (empresa segura, prazo e
 * data de assinatura lidos). As demais situações ficam para conferência.
 */
class ContratoClicksignAssinado extends Model
{
    protected $table = 'contratos_clicksign_assinados';

    public const TIPO_GESTAO   = 'gestao';
    public const TIPO_MENTORIA = 'mentoria';

    public const SITUACAO_CASADO      = 'casado';
    public const SITUACAO_DUVIDOSO    = 'duvidoso';
    public const SITUACAO_SEM_EMPRESA = 'sem_empresa';
    public const SITUACAO_ILEGIVEL    = 'ilegivel';
    public const SITUACAO_SEM_PRAZO   = 'sem_prazo';

    public const FONTE_TEXTO = 'texto';
    public const FONTE_NOME  = 'nome';

    protected $fillable = [
        'envelope_id', 'envelope_nome', 'tipo', 'company_id', 'confianca',
        'cnpj_lido', 'razao_social_lida', 'assinado_em', 'vigencia_meses',
        'vigencia_fonte', 'situacao', 'motivo',
    ];

    protected $casts = [
        'assinado_em'    => 'datetime',
        'vigencia_meses' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
