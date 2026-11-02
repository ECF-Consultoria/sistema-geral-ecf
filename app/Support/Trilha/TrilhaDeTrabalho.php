<?php

namespace App\Support\Trilha;

use App\Models\Company;

/**
 * A empresa usa a trilha como LUGAR DE TRABALHO (2ª entrega, 02/11/2026)? É a chave `trilha.trabalho.empresas`
 * (env `TRILHA_TRABALHO_EMPRESAS`), cruzada com {@see TrilhaLigada}: só vale para empresa cuja trilha está aberta.
 *
 * Com ela ligada a equipe mapeia e planeja dentro da trilha, e o robô do Publicador só prepara a oferta quando
 * chega o dia da onda dela. Desligada, TUDO segue exatamente o caminho de antes — cada gancho no Publicador
 * pergunta aqui primeiro, e a pergunta só lê a configuração (nenhuma consulta ao banco).
 *
 * Relida da configuração a cada pergunta, como {@see TrilhaLigada}: os testes trocam a lista com `config()` e a
 * produção vale a lista nova depois do `config:cache` (e do `queue:restart`, para o worker do preparo).
 */
final class TrilhaDeTrabalho
{
    /**
     * Os ids ligados: os da chave de trabalho que também estão em `trilha.empresas`, sem repetição.
     *
     * @return list<int>
     */
    public static function empresas(): array
    {
        $abertas = TrilhaLigada::empresas();
        $ids = [];
        foreach ((array) config('trilha.trabalho.empresas', []) as $id) {
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }
            $texto = trim((string) $id);
            if (ctype_digit($texto) && (int) $texto > 0 && in_array((int) $texto, $abertas, true)) {
                $ids[(int) $texto] = true;
            }
        }

        return array_keys($ids);
    }

    /** A empresa (ou o id dela) está ligada? Conta sem empresa (Polos sem cadastro) dá falso. */
    public static function ligada(Company|int|null $empresa): bool
    {
        if ($empresa === null) {
            return false;
        }
        $id = $empresa instanceof Company ? (int) $empresa->getKey() : $empresa;

        return $id > 0 && in_array($id, self::empresas(), true);
    }
}
