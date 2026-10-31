<?php

namespace App\Support\Trilha;

use App\Models\Company;

/**
 * A empresa está ligada à Trilha de produtos? É a chave `trilha.empresas` (env `TRILHA_EMPRESAS`).
 *
 * As duas portas perguntam aqui, e a resposta "não" vira 404 nas duas — como se a tela não existisse, que é o
 * que ela é para quem não está ligado: a trilha está na 1ª entrega e é escondida (sem item de menu em lugar
 * nenhum). Na porta do cliente esta é a ÚNICA trava além do login do Portal, por isso a régua é estrita.
 *
 * A lista é relida da configuração a cada pergunta (nada guardado em propriedade estática): a lista nova vale na
 * requisição seguinte ao `config:cache` (em produção, trocar só o `.env` não basta — a config fica em cache), e
 * os testes trocam a lista com `config(['trilha.empresas' => [...]])`.
 */
final class TrilhaLigada
{
    /**
     * Os ids ligados, só inteiros positivos e sem repetição.
     *
     * O `config/trilha.php` já limpa o que vem do `.env`, mas a lista pode chegar por outro caminho
     * (`config([...])` num teste, um `config:cache` antigo): a limpeza é repetida aqui para que uma entrada
     * torta ("abc", null) nunca vire o id 0 e "ligue" algo por engano.
     *
     * @return list<int>
     */
    public static function empresas(): array
    {
        $ids = [];
        foreach ((array) config('trilha.empresas', []) as $id) {
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }
            $texto = trim((string) $id);
            if (ctype_digit($texto) && (int) $texto > 0) {
                $ids[(int) $texto] = true;
            }
        }

        return array_keys($ids);
    }

    /** A empresa (ou o id dela) está na lista? */
    public static function para(Company|int $empresa): bool
    {
        $id = $empresa instanceof Company ? (int) $empresa->getKey() : $empresa;

        return $id > 0 && in_array($id, self::empresas(), true);
    }
}
