<?php

namespace App\Support;

/**
 * CustId — helper estático puro para normalização de identificadores de empresa.
 *
 * Phase 38 (Plano 01): resolve o Pitfall central do re-escopo da página /polos.
 *
 * PROBLEMA (RESEARCH §Pitfall 1):
 *   O CSV ECF Drive grava CUS_CUST_ID_SEL como "2425054445,0" (float com
 *   vírgula decimal, padrão brasileiro). A planilha XLSX grava o mesmo campo
 *   como inteiro 2425054445. MlbEmpresa.cust_id armazena "2425054445" (string
 *   sem sufixo). Um join sem normalização produz zero matches → todos os ativos
 *   aparecem com faturamento R$0 e status "Não".
 *
 * SOLUÇÃO:
 *   normaliza() remove o sufixo decimal em qualquer formato (vírgula ou ponto,
 *   qualquer quantidade de zeros) e retorna sempre o inteiro em string.
 *
 * CONSUMIDORES:
 *   - SeedPolosFase (Plano 02): normaliza cust_id ao ler coluna D do XLSX.
 *   - PolosController (Plano 03): normaliza CUS_CUST_ID_SEL antes de indexar
 *     as linhas do CSV para o join com os ativos do ECF.
 *
 * IMPORTANTE: Entrada vazia retorna vazia (nunca '0').
 */
class CustId
{
    /**
     * Normaliza um cust_id bruto para o formato canônico usado em MlbEmpresa.cust_id.
     *
     * Formatos aceitos:
     *   "2425054445,0"   → "2425054445"  (CSV ECF Drive — vírgula decimal BR)
     *   "2425054445,00"  → "2425054445"  (variante com 2 zeros)
     *   "2425054445"     → "2425054445"  (já normalizado — idempotente)
     *   "2425054445.0"   → "2425054445"  (PhpSpreadsheet pode retornar float como string)
     *   "  2425054445,0  " → "2425054445"  (trim de espaços)
     *   ""               → ""            (vazio permanece vazio; nunca retorna '0')
     *
     * @param  string  $raw  Cust ID bruto (pode ter vírgula decimal, ponto, espaços).
     * @return string        Cust ID normalizado (inteiro em string) ou string vazia.
     */
    public static function normaliza(string $raw): string
    {
        // Trim de espaços em branco antes de qualquer processamento
        $valor = trim($raw);

        // Caso especial: entrada vazia retorna vazia (nunca '0')
        if ($valor === '') {
            return '';
        }

        // Converte vírgula decimal BR → ponto decimal (padrão PHP/IEEE 754)
        // "2425054445,0" → "2425054445.0"
        $valor = str_replace(',', '.', $valor);

        // Remove zeros à direita e ponto SOMENTE quando há separador decimal.
        // Sem esta guarda, cust_ids que terminam em '0' (ex: "9876543210") seriam
        // erroneamente truncados para "987654321".
        //
        // "2425054445.0"   → contém '.' → rtrim('0') → "2425054445." → rtrim('.') → "2425054445"
        // "2425054445.00"  → contém '.' → rtrim('0') → "2425054445." → rtrim('.') → "2425054445"
        // "9876543210"     → sem '.' → mantido intacto → "9876543210"
        // "2425054445"     → sem '.' → mantido intacto → "2425054445"
        if (str_contains($valor, '.')) {
            $valor = rtrim(rtrim($valor, '0'), '.');
        }

        // Proteção extra: se ainda houver ponto (ex: "2425054445.5"), converte para int
        // Isso não deve ocorrer com cust_ids reais (são inteiros), mas garante robustez.
        if (str_contains($valor, '.')) {
            $valor = (string)(int) $valor;
        }

        return $valor;
    }
}
