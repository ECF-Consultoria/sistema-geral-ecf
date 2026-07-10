<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rascunho de anúncio do Mercado Livre montado no nosso sistema.
 *
 * Guarda o anúncio em construção (payload completo) e seu ciclo de vida até a
 * publicação na conta do cliente. A API do ML só é chamada para validar e
 * publicar — todo o resto acontece sobre este rascunho.
 */
class MlAnuncioRascunho extends Model
{
    protected $table = 'ml_anuncio_rascunhos';

    // ─── Status (espelham o enum da migration) ───
    public const STATUS_RASCUNHO   = 'rascunho';    // em edição
    public const STATUS_VALIDADO   = 'validado';    // passou no /items/validate
    public const STATUS_PUBLICANDO = 'publicando';  // POST /items em andamento (trava anti-duplicidade)
    public const STATUS_PUBLICADO  = 'publicado';   // criado no ML (ml_item_id preenchido)
    public const STATUS_ERRO       = 'erro';        // falha na publicação

    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'category_id',
        'payload',
        'validation_errors',
        'ml_item_id',
        'published_at',
    ];

    protected $casts = [
        'payload'           => 'array',
        'validation_errors' => 'array',
        'published_at'      => 'datetime',
    ];

    /** Conta ML de destino (empresa cliente). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Funcionário que está montando o anúncio. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
