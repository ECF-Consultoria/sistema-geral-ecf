<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma foto do rascunho (`image_asset`) — um USO de um ativo do acervo (v28.0). `caminho` nulo = veio do Anunciar
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

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(PubRascunho::class, 'rascunho_id');
    }

    /** O ativo do acervo da conta (v28.0). Null = foto anterior ao acervo, com arquivo só dela. */
    public function acervoImagem(): BelongsTo
    {
        return $this->belongsTo(AcervoImagem::class, 'acervo_imagem_id');
    }

    /**
     * O arquivo que é SÓ desta linha e pode ir embora com ela: o caminho próprio quando a foto não tem
     * ativo, ou quando tem mas o arquivo dela é uma cópia antiga (backfill) diferente do objeto do ativo.
     * O objeto do ativo nunca sai por aqui — é do acervo (exclusão segura, Fase 199).
     */
    public function arquivoProprio(): ?string
    {
        if ($this->caminho === null) {
            return null;
        }
        if ($this->acervo_imagem_id === null) {
            return $this->caminho;
        }

        return $this->caminho === $this->acervoImagem?->caminho ? null : $this->caminho;
    }
}
