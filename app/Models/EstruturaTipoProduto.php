<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de produto da ECF (Fase 168, D-06): vocabulário GLOBAL, semeado de
 * `config/estrutura_geracao.php` e mantido pela tela admin. `palavras` guarda as palavras
 * normalizadas separadas por ", "; `qtd_*` são listas de quantidades em texto ("2, 4, 6").
 */
class EstruturaTipoProduto extends Model
{
    protected $table = 'estrutura_tipos_produto';

    protected $fillable = ['slug', 'nome', 'plural', 'palavras', 'qtd_combo', 'qtd_combit', 'ordem'];

    protected $casts = [
        'ordem' => 'integer',
    ];
}
