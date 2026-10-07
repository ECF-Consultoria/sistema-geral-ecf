<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma imagem da galeria de uma variação (a cor) do produto. `ordem` 0 é a capa.
 * O arquivo mora no disco privado `local`; `caminho` é relativo a ele e NUNCA sai para a
 * tela — o cliente vê só a rota autenticada (ver `VariacaoImagensService::url`).
 *
 * `company_id` e `produto_id` entram no `fillable` só porque o SERVIÇO os grava a partir da
 * variação já resolvida dentro da empresa do `PortalContexto`: nenhum controller deve
 * passá-los vindos da requisição.
 */
class EstruturaProdutoVariacaoImagem extends Model
{
    protected $table = 'estrutura_produto_variacao_imagens';

    protected $fillable = [
        'company_id', 'produto_id', 'variacao_id', 'caminho', 'nome_original', 'mime', 'tamanho', 'largura', 'altura', 'ordem',
    ];

    protected $casts = [
        'tamanho' => 'integer',
        'largura' => 'integer',
        'altura'  => 'integer',
        'ordem'   => 'integer',
    ];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(EstruturaProdutoVariacao::class, 'variacao_id');
    }
}
