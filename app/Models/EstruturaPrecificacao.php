<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que se grava da precificação de UMA oferta: custo digitado, os dois fretes
 * e as exceções aos parâmetros da empresa. O preço não é coluna — sai da
 * {@see \App\Services\Portal\Estrutura\PrecificacaoEstrutura} a cada leitura
 * (ADR PORTAL-02).
 *
 * Percentuais em PONTO PERCENTUAL (`11.50` = 11,5%). NULL = vale o da empresa;
 * `custo` NULL em combo/kit/combit = calculado pelos componentes.
 */
class EstruturaPrecificacao extends Model
{
    protected $table = 'estrutura_precificacoes';

    /** As exceções que um produto pode ter aos parâmetros da empresa. */
    public const EXCECOES = ['comissao_classico', 'comissao_premium', 'imposto', 'margem_contribuicao', 'lucro_liquido'];

    protected $fillable = ['oferta_id', 'custo', 'frete_classico', 'frete_premium', ...self::EXCECOES];

    protected $casts = [
        'custo'               => 'float',
        'frete_classico'      => 'float',
        'frete_premium'       => 'float',
        'comissao_classico'   => 'float',
        'comissao_premium'    => 'float',
        'imposto'             => 'float',
        'margem_contribuicao' => 'float',
        'lucro_liquido'       => 'float',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }
}
