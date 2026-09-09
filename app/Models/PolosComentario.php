<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentário de performance de uma empresa num mês — só aparece em /polos/empresas.
 *
 * Chaveado por cust_id normalizado + mes ('YYYYMM'); ver o docblock da migration
 * 2026_09_09_140000_create_polos_comentarios_table para o porquê de cada decisão.
 */
class PolosComentario extends Model
{
    protected $table = 'polos_comentarios';

    protected $fillable = [
        'cust_id', 'mes', 'user_id', 'autor_nome', 'texto', 'editado_em',
    ];

    protected $casts = [
        'editado_em' => 'datetime',
    ];

    /** Autor. Pode ser null se o usuário foi removido — daí vale o snapshot `autor_nome`. */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Nome de exibição: usuário vivo primeiro, snapshot como rede de segurança. */
    public function autorNome(): string
    {
        return $this->autor?->name ?? ($this->autor_nome ?: 'Usuário removido');
    }
}
