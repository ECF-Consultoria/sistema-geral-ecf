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

        return $valorFaixa + self::somaContratosMensais($contratos);
    }

    /**
     * Cálculo Fase 141 (D-03): a mensalidade é o valor da FAIXA, e só isso
     * — nunca faixa + soma de contratos. É o oposto de `novo()` no ponto
     * exato que motivou a fase: BARAOSHOP VARIEDADES, agosto/2026, faturava
     * R$ 488.262,90 (faixa 1, valor R$ 3.000) e a tela cobrava R$ 5.500
     * (R$ 3.000 da faixa + R$ 2.500 do contrato mensal de Shopee), porque a
     * regra antiga soma MENSALIDADE. A regra nova soma FATURAMENTO (das
     * plataformas contratadas, antes de classificar a faixa — fora deste
     * método) e cobra só o valor da faixa resultante.
     *
     * `$classificacao` é o shape de `FechamentoFaixaResolver::classificar()`
     * (`['ordem', 'label', 'valor', 'valor_e_piso', 'limite_inferior',
     * 'limite_superior']`) — quando presente, a chave `'valor'` É a
     * mensalidade, ponto final.
     *
     * Quando a empresa não tem tabela nenhuma (`$classificacao === null` —
     * o caso de Mentoria, D-03 do CONTEXT), a mensalidade volta a ser o
     * valor fixo do contrato: soma de `valor_contratado` dos contratos
     * ativos com `tipo_cobranca = mensal` (mesmo filtro de `novo()`, via
     * `somaContratosMensais()` — não duplica a lógica).
     *
     * @param  array|null  $classificacao  Saída de `classificar()`, ou `null` quando a empresa não tem tabela.
     * @param  iterable    $contratos      Contratos da empresa (eager-loaded com `servico`) — só usado quando `$classificacao` é `null`.
     * @return float|null                  Valor da faixa, soma dos contratos mensais, ou `null` quando não há
     *                                     faixa NEM contrato mensal ativo nenhum — "não sei quanto cobrar" é
     *                                     estado diferente de "cobrar zero", nunca devolve 0.0 nesse caso.
     */
    public static function mensalidade(?array $classificacao, iterable $contratos): ?float
    {
        if ($classificacao !== null) {
            return (float) $classificacao['valor'];
        }

        $elegiveis = self::contratosMensaisElegiveis($contratos);

        // Zero contratos elegíveis é "não sei quanto cobrar" (null) — diferente
        // de "um contrato elegível de R$ 0,00" (soma 0.0, resposta válida).
        if ($elegiveis === []) {
            return null;
        }

        return array_sum($elegiveis);
    }

    /**
     * Soma `valor_contratado` dos contratos ATIVOS com `servico.tipo_cobranca
     * = mensal`. Usada por `novo()` (faixa + soma) — mantém a fórmula antiga
     * exatamente como era, via `contratosMensaisElegiveis()`.
     */
    private static function somaContratosMensais(iterable $contratos): float
    {
        return array_sum(self::contratosMensaisElegiveis($contratos));
    }

    /**
     * Filtra os contratos elegíveis para a soma de cobrança mensal: ATIVO,
     * com `servico` carregado, e `tipo_cobranca = mensal`. Único lugar que
     * sabe o que conta como "contrato mensal elegível" — `novo()` e
     * `mensalidade()` reusam via `somaContratosMensais()` e aqui.
     *
     * @return float[] Lista de `valor_contratado` (float) dos contratos elegíveis.
     */
    private static function contratosMensaisElegiveis(iterable $contratos): array
    {
        $valores = [];

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

            $valores[] = (float) $contrato->valor_contratado;
        }

        return $valores;
    }
}
