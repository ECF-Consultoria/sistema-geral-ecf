<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PortalCodigoAcesso — um código de 6 dígitos, de vida curta, para entrar no
 * Portal.
 *
 * O código nunca é guardado em claro (`codigo_hash`), vale uma vez, expira em
 * minutos, tem teto de tentativas e é amarrado à sessão que o pediu. Ver o
 * docblock da migration `create_portal_codigos_acesso_table` — está lá a conta
 * que explica por que 6 dígitos bastam com esses quatro limites juntos, e por
 * que afrouxar qualquer um deles quebra a segurança do conjunto.
 */
class PortalCodigoAcesso extends Model
{
    protected $table = 'portal_codigos_acesso';

    protected $fillable = [
        'portal_usuario_id', 'codigo_hash', 'sessao_id', 'expira_em', 'tentativas', 'usado_em', 'ip',
    ];

    protected $casts = [
        'expira_em'  => 'datetime',
        'usado_em'   => 'datetime',
        'tentativas' => 'integer',
    ];

    /** Máximo de palpites antes de o código morrer. */
    public const MAX_TENTATIVAS = 5;

    public function usuario()
    {
        return $this->belongsTo(PortalUsuario::class, 'portal_usuario_id');
    }

    /**
     * Ainda serve? As três condições valem juntas — e é justamente a soma delas
     * que torna um código de 6 dígitos defensável.
     */
    public function utilizavel(): bool
    {
        return $this->usado_em === null
            && $this->expira_em->isFuture()
            && $this->tentativas < self::MAX_TENTATIVAS;
    }

    /** Códigos vivos de um usuário, do mais recente para o mais antigo. */
    public function scopeVivos($query)
    {
        return $query->whereNull('usado_em')
            ->where('expira_em', '>', now())
            ->where('tentativas', '<', self::MAX_TENTATIVAS)
            ->orderByDesc('id');
    }
}
