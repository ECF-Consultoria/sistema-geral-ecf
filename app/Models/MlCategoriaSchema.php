<?php

namespace App\Models;

use App\Support\Publicador\Schema\CategorySchema;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache do schema de uma categoria: as 4 respostas cruas do ML e o hash
 * (RN-22, TTL de 24h). Uma falha do ML nunca é gravada aqui — o cache antigo
 * guardava `[]` por 7 dias (V13).
 */
class MlCategoriaSchema extends Model
{
    protected $table = 'ml_categoria_schemas';

    protected $primaryKey = 'category_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'categoria' => 'array',
        'atributos' => 'array',
        'technical_specs' => 'array',
        'sale_terms' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function paraSchema(): CategorySchema
    {
        return CategorySchema::dasFontes($this->category_id, $this->categoria ?? [], $this->atributos ?? [], $this->technical_specs ?? [], $this->sale_terms ?? []);
    }
}
