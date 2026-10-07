<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Ambiente (cozinha, sala, quarto...) da empresa no Mapeamento Estrutural
 * (ADR PORTAL-01, Fase 167). Lista da empresa; um produto pode estar em vários
 * ambientes (N:N, D-05).
 */
class EstruturaAmbiente extends Model
{
    protected $table = 'estrutura_ambientes';

    protected $fillable = ['company_id', 'nome'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function produtos(): BelongsToMany
    {
        return $this->belongsToMany(EstruturaProduto::class, 'estrutura_produto_ambiente', 'ambiente_id', 'produto_id');
    }
}
