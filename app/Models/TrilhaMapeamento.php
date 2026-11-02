<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O produto "mapeado" na Trilha de produtos (2ª entrega, 02/11/2026): o último "Concluir mapeamento" dele, por
 * quem. Um para um com `estrutura_produtos`, numa tabela própria para o produto não receber ALTER.
 */
class TrilhaMapeamento extends Model
{
    protected $table = 'trilha_mapeamentos';

    protected $fillable = ['company_id', 'produto_id', 'concluido_em', 'concluido_por'];

    protected $casts = [
        'produto_id'   => 'integer',
        'concluido_em' => 'datetime',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'produto_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }
}
