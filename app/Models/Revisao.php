<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro de um ato de revisão sobre um anúncio.
 *
 * Cada linha é uma transição de estado com autor e data. É este log que
 * permite ao gestor supervisionar o trabalho do líder — antes, `revisado`
 * era um booleano sem autoria e desmarcá-lo apagava o fato em silêncio.
 */
class Revisao extends Model
{
    protected $table = 'mlb_revisoes';

    // ─── Estados possíveis de uma publicação ───
    // "Revisado" deixou de ser estado de propósito: revisar não quer dizer que
    // está correto, e era exatamente essa a leitura errada que o gestor fazia.
    public const ST_NAO_REVISADO = 'nao_revisado';
    public const ST_APROVADO     = 'aprovado';   // conferido E correto
    public const ST_EM_AJUSTE    = 'em_ajuste';  // tem pendência — bola com o publicador
    public const ST_RECONFERIR   = 'reconferir'; // publicador corrigiu — bola com o líder

    public const STATUS = [
        self::ST_NAO_REVISADO => 'Não revisado',
        self::ST_APROVADO     => 'Aprovado',
        self::ST_EM_AJUSTE    => 'Em ajuste',
        self::ST_RECONFERIR   => 'Reconferir',
    ];

    /** Estados em que a bola está com o líder (aparecem na Fila). */
    public const NA_FILA_DO_LIDER = [self::ST_NAO_REVISADO, self::ST_RECONFERIR];

    protected $fillable = [
        'publicacao_id',
        'revisor_id',
        'de_status',
        'para_status',
        'observacao',
    ];

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(Publicacao::class, 'publicacao_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisor_id')->withTrashed();
    }

    public function pendencias(): HasMany
    {
        return $this->hasMany(Pendencia::class, 'revisao_id');
    }

    /** Transições que representam um veredicto do líder (exclui reversões). */
    public function scopeVeredicto(Builder $q): Builder
    {
        return $q->whereIn('para_status', [self::ST_APROVADO, self::ST_EM_AJUSTE]);
    }
}
