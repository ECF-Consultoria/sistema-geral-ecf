<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SugadorAcao extends Model
{
    protected $table = 'sugador_acoes';

    /** Tabela de audit log — só guarda quando foi criado, não atualiza. */
    public $timestamps = false;
    protected $dates = ['created_at'];

    protected $fillable = [
        'sugador_id', 'user_id', 'acao',
        'status_anterior', 'status_novo', 'observacao',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ACAO_MARCOU_EM_ACAO     = 'marcou_em_acao';
    public const ACAO_MARCOU_RESOLVIDO   = 'marcou_resolvido';
    public const ACAO_MARCOU_IGNORADO    = 'marcou_ignorado';
    public const ACAO_VOLTOU_PENDENTE    = 'voltou_pendente';
    public const ACAO_EDITOU_OBSERVACAO  = 'editou_observacao';
    public const ACAO_MOVEU              = 'moveu';

    public function sugador(): BelongsTo
    {
        return $this->belongsTo(Sugador::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
