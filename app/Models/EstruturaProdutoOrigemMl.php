<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O anúncio do Mercado Livre de onde um produto do Portal foi importado (28/10/2026).
 *
 * `retrato` guarda o anúncio como estava na importação (título, Modelo, campos, medidas,
 * fotos, métricas) — é o "antes" da jardinagem e o fato que a IA usa. `jardinagem` guarda o
 * que a IA completou depois (`campos_ia`, `preenchidos`, `faltavam`, `em`). O vínculo é o que
 * acende o selo "Importado do Mercado Livre" na lista e na ficha.
 */
class EstruturaProdutoOrigemMl extends Model
{
    protected $table = 'estrutura_produto_origens_ml';

    protected $fillable = [
        'company_id', 'produto_id', 'ml_item_id', 'titulo_original',
        'retrato', 'jardinagem', 'importado_por', 'importado_em',
    ];

    protected $casts = [
        'retrato'      => 'array',
        'jardinagem'   => 'array',
        'importado_em' => 'datetime',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'produto_id');
    }

    /** Quantos campos a IA completou na ficha (0 enquanto ela não rodou). */
    public function camposDaIa(): int
    {
        return count((array) ($this->jardinagem['campos_ia'] ?? []));
    }
}
