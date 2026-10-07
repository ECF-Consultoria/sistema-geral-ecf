<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste de geração por produto (Fase 168), 1:1 com `estrutura_produtos` (PK = produto_id).
 * Tabela própria para não alterar tabela da 167. `tipo_id` null = produto volta à inferência.
 */
class EstruturaProdutoGeracao extends Model
{
    protected $table = 'estrutura_produto_geracao';

    protected $primaryKey = 'produto_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['produto_id', 'company_id', 'tipo_id', 'qtd_combo', 'qtd_combit'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'produto_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(EstruturaTipoProduto::class, 'tipo_id');
    }
}
