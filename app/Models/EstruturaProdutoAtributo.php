<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um campo da ficha técnica de um produto: uma linha por (produto, atributo da categoria).
 * `atributo_id` é interno (nunca mostrado ao cliente); `atributo_nome` é o rótulo que ele viu.
 *
 * `company_id` entra no `fillable` só porque o SERVIÇO o grava a partir do `PortalContexto`:
 * nenhum controller deve passá-lo vindo da requisição.
 */
class EstruturaProdutoAtributo extends Model
{
    protected $table = 'estrutura_produto_atributos';

    protected $fillable = [
        'company_id', 'produto_id', 'atributo_id', 'atributo_nome', 'valor', 'valor_id', 'unidade',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'produto_id');
    }
}
