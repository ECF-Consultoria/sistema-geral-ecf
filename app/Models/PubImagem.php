<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma foto do rascunho (`image_asset`). `caminho` nulo = veio do Anunciar
 * antigo, que só guardava o id do ML: não dá para revalidar nem reenviar.
 */
class PubImagem extends Model
{
    protected $table = 'pub_imagens';

    public const PENDENTE = 'pending';
    public const ENVIADA = 'uploaded';
    public const FALHOU = 'failed';

    protected $guarded = ['id'];

    protected $casts = ['upload_erro' => 'array', 'bytes' => 'integer', 'largura' => 'integer', 'altura' => 'integer'];

    public function atribuicoes(): HasMany
    {
        return $this->hasMany(PubImagemAtribuicao::class, 'imagem_id');
    }
}
