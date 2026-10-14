<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 184 (BLING-02) — vínculo entre um produto/variação do Bling e uma
 * variação do Mapeamento Estrutural (`estrutura_produto_variacoes`).
 *
 * O vínculo casa pelo `bling_produto_id` (id do Bling), nunca pelo SKU
 * (lição de `EstruturaOferta`: SKU não identifica de forma estável).
 */
class BlingVinculo extends Model
{
    protected $table = 'bling_vinculos';

    public const SITUACAO_VINCULADO = 'vinculado';

    public const SITUACAO_IGNORADO = 'ignorado';

    public const SITUACAO_ERRO = 'erro';

    /** Produto saiu do Bling (apagado ou inativado): nada é apagado no Portal. */
    public const SITUACAO_REMOVIDO_NO_BLING = 'removido_no_bling';

    public const FORMATO_SIMPLES = 'S';

    public const FORMATO_VARIACAO = 'V';

    public const FORMATO_KIT = 'E';

    protected $fillable = [
        'company_id',
        'bling_produto_id',
        'bling_pai_id',
        'variacao_id',
        'codigo_bling',
        'formato',
        'gtin',
        'escrito',
        'saldo',
        'saldo_lido_em',
        'situacao',
        'motivo',
    ];

    protected $casts = [
        'saldo'         => 'integer',
        'saldo_lido_em' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(EstruturaProdutoVariacao::class, 'variacao_id');
    }

    /**
     * Fase 184-10 (CONTEXT §4.1) — as DUAS travas da variação vinculada, decididas num SÓ
     * lugar e chamadas tanto pela escrita (`ProdutoCadastroService::carregar`/`gravarLinha`)
     * quanto pela leitura (`ProdutoLinhas::paraProdutos`). Duas implementações divergindo já
     * aconteceu neste projeto com o ERP declarado — este método existe para não repetir.
     *
     * `sku_travado`: há vínculo `vinculado` E a conexão está `ativa` — é por ele que o Bling
     * casa com o anúncio; trava independente de quem manda no estoque.
     * `estoque_travado`: a mesma condição, E a conexão tem `estoque_ativo` — o cliente pode
     * ter ligado o Bling só para ler produto/SKU, sem mandar estoque.
     *
     * Vínculo `removido_no_bling`, `ignorado` ou `erro`, ou conexão fora de `ativa`
     * (`divergente`, `revogada`, `erro`, `aguardando_cliente`, `a_conectar`): os dois `false`.
     *
     * @return array{estoque_travado: bool, sku_travado: bool}
     */
    public static function travas(?BlingConexao $conexao, ?self $vinculo): array
    {
        $vinculado = $vinculo !== null
            && $vinculo->situacao === self::SITUACAO_VINCULADO
            && $conexao !== null
            && $conexao->status === BlingConexao::STATUS_ATIVA;

        return [
            'sku_travado'     => $vinculado,
            'estoque_travado' => $vinculado && (bool) $conexao?->estoque_ativo,
        ];
    }
}
