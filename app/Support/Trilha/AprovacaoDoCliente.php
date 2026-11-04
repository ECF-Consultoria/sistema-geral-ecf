<?php

namespace App\Support\Trilha;

use App\Models\Company;

/**
 * A loja aprova os anúncios da trilha COM O CLIENTE? (4ª entrega, fatia 4e, 04/11/2026 — desenho §19.16, D53.) É a chave
 * `trilha.aprovacao_cliente.empresas` (env `TRILHA_APROVACAO_CLIENTE_EMPRESAS`), cruzada com {@see TrilhaDeTrabalho}
 * (que já cruza com {@see TrilhaLigada}): só vale para empresa em que a trilha é lugar de trabalho.
 *
 * Ligada: o primário da revisão da equipe vira [Mandar ao cliente], o "Aprovar e publicar" direto da equipe responde 409
 * (exceto o item que o cliente já aprovou e não publicou — D56), e o cliente recebe a prévia para aprovar ou pedir
 * ajuste. Desligada: tudo exatamente como na 4d — cada ponto pergunta aqui primeiro, e a pergunta só lê a configuração
 * (nenhuma consulta ao banco).
 *
 * Relida da configuração a cada pergunta, como as outras chaves da trilha: os testes trocam a lista com `config()` e a
 * produção vale a lista nova depois do `config:cache`.
 */
final class AprovacaoDoCliente
{
    /**
     * Os ids ligados: os da chave que também estão na trilha de trabalho, sem repetição.
     *
     * @return list<int>
     */
    public static function empresas(): array
    {
        $deTrabalho = TrilhaDeTrabalho::empresas();
        $ids = [];
        foreach ((array) config('trilha.aprovacao_cliente.empresas', []) as $id) {
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }
            $texto = trim((string) $id);
            if (ctype_digit($texto) && (int) $texto > 0 && in_array((int) $texto, $deTrabalho, true)) {
                $ids[(int) $texto] = true;
            }
        }

        return array_keys($ids);
    }

    /** A empresa (ou o id dela) aprova com o cliente? Nulo dá falso. */
    public static function ligada(Company|int|null $empresa): bool
    {
        if ($empresa === null) {
            return false;
        }
        $id = $empresa instanceof Company ? (int) $empresa->getKey() : $empresa;

        return $id > 0 && in_array($id, self::empresas(), true);
    }
}
