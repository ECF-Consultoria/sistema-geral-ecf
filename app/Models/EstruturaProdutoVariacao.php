<?php

namespace App\Models;

use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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

    /**
     * Eixo do portal → id do atributo da categoria que ele É. Uso interno (nunca vai à tela).
     * Uma fonte só para as duas pontas: a ficha técnica do portal tira esse atributo (ele já é
     * a variação) e o Sincronizar o usa como eixo do rascunho. "Outro" não tem atributo.
     */
    public const EIXO_PARA_ATRIBUTO = [
        'cor'      => 'COLOR',
        'tamanho'  => 'SIZE',
        'voltagem' => 'VOLTAGE',
        'material' => 'MATERIAL',
        'sabor'    => 'FLAVOR',
    ];

    protected $table = 'estrutura_produto_variacoes';

    protected $fillable = ['produto_id', 'company_id', 'ordem', 'codigo', 'eixo', 'valor', 'custo', 'estoque'];

    protected $casts = [
        'custo' => 'float',
        'ordem' => 'integer',
        'estoque' => 'integer',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'produto_id');
    }

    protected static function booted(): void
    {
        // As linhas das imagens saem por cascata no banco; os ARQUIVOS, só aqui. Depois do commit:
        // se a exclusão for desfeita (restrict de componente), as imagens continuam no disco.
        static::deleting(function (self $variacao) {
            $empresa = (int) $variacao->company_id;
            $produto = (int) $variacao->produto_id;
            $id = (int) $variacao->getKey();

            DB::afterCommit(fn () => VariacaoImagensService::apagarPasta($empresa, $produto, $id));
        });
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(EstruturaProdutoVolume::class, 'variacao_id')->orderBy('ordem');
    }

    /** A galeria da variação, na ordem escolhida (a 1ª, `ordem` 0, é a capa). */
    public function imagens(): HasMany
    {
        return $this->hasMany(EstruturaProdutoVariacaoImagem::class, 'variacao_id')->orderBy('ordem')->orderBy('id');
    }

    public function oferta(): HasOne
    {
        return $this->hasOne(EstruturaOferta::class, 'variacao_id');
    }
}
