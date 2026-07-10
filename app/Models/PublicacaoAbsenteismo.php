<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de absenteísmo do publicador num mês (Plano de Metas do Time de
 * Publicação). Lançado manualmente pelo líder/gestor, já que o sistema não tem
 * controle de ponto. A nota 1-5 é derivada no PlanoMetasPublicacaoService a
 * partir de `faltas_nao_justificadas` + `atrasos_pontuais`.
 */
class PublicacaoAbsenteismo extends Model
{
    protected $table = 'publicacao_absenteismos';

    protected $fillable = [
        'user_id',
        'mes',
        'faltas_nao_justificadas',
        'atrasos_pontuais',
        'observacao',
        'registrado_por',
    ];

    protected $casts = [
        'faltas_nao_justificadas' => 'integer',
        'atrasos_pontuais'        => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
