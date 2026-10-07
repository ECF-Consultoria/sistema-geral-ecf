<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fato confirmado pelo OPERADOR sobre um produto do Publicador (Fase 169,
 * TXT-01/TXT-02): ponto forte (benefício) ou medida, quando o cadastro
 * automático do Mercado Livre não basta. Entrada humana conferida — nunca
 * misturada com `fatosVerificados()`/`contagens()` do `ProductTruthBuilder`
 * (TRUTH-01/02 seguem lendo só `$contexto->atributos`).
 *
 * Lido por `ContextoCriativoDoPublicador::fatosHumanos()`. Escrito pelo
 * endpoint do 169-02 (fora de escopo deste plano).
 */
class PubProdutoFatoCriativo extends Model
{
    protected $table = 'pub_produto_fatos_criativo';

    public const TIPO_BENEFICIO = 'beneficio';
    public const TIPO_MEDIDA = 'medida';

    protected $fillable = ['pub_produto_id', 'tipo', 'texto', 'confirmado_por_id'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'pub_produto_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por_id');
    }
}
