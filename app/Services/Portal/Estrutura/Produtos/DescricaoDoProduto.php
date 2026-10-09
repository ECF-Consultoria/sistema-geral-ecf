<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;

/**
 * Grava a descrição de UM produto (texto livre escrito pelo cliente na ficha).
 *
 * Regras: trim; vazio vira NULL; só escreve (e só audita) quando o texto mudou.
 *
 * ### Sigilo
 * Nenhuma mensagem daqui cita a origem do campo. A descrição é guardada crua e nunca
 * renderizada como HTML (o React escapa).
 */
class DescricaoDoProduto
{
    /** @return ?string o valor que ficou salvo (null quando limpo) */
    public function gravar(Company $empresa, EstruturaProduto $produto, ?string $texto, AtorDoPortal $ator): ?string
    {
        $novo = $texto === null ? '' : trim($texto);
        $novo = $novo === '' ? null : $novo;

        if ($produto->descricao === $novo) {
            return $novo;
        }

        $produto->descricao = $novo;
        $produto->save();

        RegistroEstrutura::registrar($ator, $empresa, $produto, 'descricao_gravada',
            "Descrição de “{$produto->nome}” gravada", ['produto_id' => (int) $produto->id, 'tamanho' => $novo === null ? 0 : mb_strlen($novo)]);

        return $novo;
    }
}
