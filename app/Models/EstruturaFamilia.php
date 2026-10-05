<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Família de produtos do Mapeamento Estrutural (ADR PORTAL-01, Fase 167).
 *
 * ATENÇÃO (D-07): aqui "família" é a LINHA DE DESIGN da planilha (ex.: Farmhouse),
 * lista própria da empresa. NÃO é "o mesmo produto em várias cores" do Onboarding
 * (`agruparFamilias` em `resources/js/lib/precificacaoProdutos.js`).
 */
class EstruturaFamilia extends Model
{
    protected $table = 'estrutura_familias';

    protected $fillable = ['company_id', 'nome'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(EstruturaProduto::class, 'familia_id');
    }
}
