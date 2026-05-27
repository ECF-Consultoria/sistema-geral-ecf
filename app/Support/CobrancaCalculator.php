<?php

namespace App\Support;

use App\Models\Servico;

/**
 * CobrancaCalculator — helper estático puro para cálculo da cobrança mensal.
 *
 * Phase 14 (Frente B): este helper isola a aritmética da cobrança mensal das
 * empresas de forma testável sem container Laravel. É consumido pelo comando
 * `phase14:verificar-cobranca` (pre-flight do drop das colunas legacy) e
 * pelos 3 call-sites de cálculo em AdminController (Plan 14-03).
 *
 * Per CONTEXT.md D-03 / D-08 / D-10 e RESEARCH §3 (helper puro).
 *
 * Decisão semântica: ambos os métodos retornam float (incluindo 0.0).
 * A semântica legacy de "null quando nenhum componente existe" é responsabilidade
 * do caller (ex: ?: null no array Inertia retornado por AdminController::fechamento).
 * Mantém o helper trivial e testável sem branching null/zero.
 */
class CobrancaCalculator
{
    /**
     * Cálculo antigo: soma direta entre `faixaData['valor']` e o valor extra da empresa.
     *
     * Esta é a fórmula que vigora hoje em AdminController (linhas ~280, ~506, ~648).
     * Mantida durante a Phase 14 apenas para servir de base de comparação contra `novo()`
     * no comando `phase14:verificar-cobranca`.
     *
     * @param  array|null  $faixaData                  Resultado de calcularFaixa() — ['faixa' => ..., 'valor' => ...] ou null.
     * @param  float|null  $additionalServicePrice     Valor extra usado na comparação pré-drop.
     * @return float                                   Soma (faixa + adicional). Sempre float; nunca null.
     */
    public static function legacy(?array $faixaData, ?float $additionalServicePrice): float
    {
        return (float) ($faixaData['valor'] ?? 0) + (float) ($additionalServicePrice ?? 0);
    }

    /**
     * Cálculo novo: faixa + SUM dos contratos ativos com `servico.tipo_cobranca = mensal`.
     *
     * Esta é a fórmula que vigorará pós-Phase 14. O componente "adicional" não vem mais
     * de um campo único na empresa — vem da soma de N contratos do modelo `contratos_servico`.
     *
     * Aceita `iterable` para permitir tanto Collection eager-loaded (em produção) quanto
     * array de objetos anônimos (em testes unitários puros, evitando o N+1 do Pitfall 2
     * do RESEARCH). Cada item DEVE ter as propriedades `ativo` (bool), `valor_contratado`
     * (float|string decimal) e a relação `servico` com `tipo_cobranca` (string).
     *
     * @param  array|null  $faixaData    Resultado de calcularFaixa() — ['faixa' => ..., 'valor' => ...] ou null.
     * @param  iterable    $contratos    Coleção/array de contratos da empresa (eager-loaded com `servico`).
     * @return float                     Soma (faixa + SUM contratos ativos mensais). Sempre float.
     */
    public static function novo(?array $faixaData, iterable $contratos): float
    {
        $valorFaixa = (float) ($faixaData['valor'] ?? 0);

        $somaContratos = 0.0;
        foreach ($contratos as $contrato) {
            // Filtra: contrato precisa estar ativo, ter servico carregado, e ser tipo_cobranca=mensal.
            // Único (Servico::TIPO_UNICA) e demais tipos não entram na cobrança mensal.
            if ($contrato->ativo !== true) {
                continue;
            }
            if (!isset($contrato->servico) || !$contrato->servico) {
                continue;
            }
            if ($contrato->servico->tipo_cobranca !== Servico::TIPO_MENSAL) {
                continue;
            }

            $somaContratos += (float) $contrato->valor_contratado;
        }

        return $valorFaixa + $somaContratos;
    }
}
