<?php

namespace App\Support\Portal\Estrutura;

use Illuminate\Http\Request;

/**
 * As regras de leitura do cadastro de produtos que valem nas DUAS portas que gravam o catálogo: a do Portal
 * (`PortalEstruturaProdutosController`) e a da equipe na trilha (`Trilha\CadastroDaTrilhaController`, 3ª entrega,
 * 02/11/2026). Duas cópias da mesma regra já custaram caro: a trilha usa esta, e o Portal passou a usá-la também
 * (extração mecânica, comportamento igual).
 *
 * Só leitura de requisição: nada aqui grava, consulta o banco ou sabe de qual porta veio o pedido.
 */
final class RegrasDoCadastro
{
    /**
     * As linhas como o navegador mandou. No contrato de linha `null` explícito LIMPA
     * o campo (custo, eixo, valor, família) e texto vazio é "não mexi"; o middleware
     * global `ConvertEmptyStringsToNull` transformaria todo `''` em `null` e um campo
     * vazio por engano apagaria o dado. Por isso o JSON é lido cru — a estrutura é a
     * mesma que o `validate` de quem chama já conferiu.
     *
     * @return array<int, mixed>
     */
    public static function linhasComoVieram(Request $request): array
    {
        if ($request->isJson()) {
            $corpo = json_decode((string) $request->getContent(), true);
            if (is_array($corpo) && is_array($corpo['linhas'] ?? null)) {
                return array_values($corpo['linhas']);
            }
        }

        return (array) $request->input('linhas');
    }
}
