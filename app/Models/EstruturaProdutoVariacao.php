<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Variação de um produto do Mapeamento Estrutural (ADR PORTAL-01, Fase 167): é ela
 * que tem código (SKU), custo, medidas e peso (D-04). O código é único por empresa.
 * A oferta simples nasce ligada à variação (`estrutura_ofertas.variacao_id`, D-08).
 */
class EstruturaProdutoVariacao extends Model
{
    /** D-20: mesma lista de VARIACAO_TIPOS do Onboarding; vira o atributo de variação do ML ao publicar. */
    public const EIXOS = [
        'cor'      => 'Cor',
        'tamanho'  => 'Tamanho',
        'voltagem' => 'Voltagem',
        'material' => 'Material',
        'sabor'    => 'Sabor',
        'outro'    => 'Outro',
    ];

    protected $table = 'estrutura_produto_variacoes';

    protected $fillable = ['produto_id', 'company_id', 'ordem', 'codigo', 'eixo', 'valor', 'custo'];

    protected $casts = [
        'custo' => 'float',
        'ordem' => 'integer',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'produto_id');
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(EstruturaProdutoVolume::class, 'variacao_id')->orderBy('ordem');
    }

    public function oferta(): HasOne
    {
        return $this->hasOne(EstruturaOferta::class, 'variacao_id');
    }
}
