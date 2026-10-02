<?php

namespace App\Support\Publicador\Schema;

use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Variacao\ChaveCanonica;

/**
 * O valor de UM atributo: se vale (L1, `08` V-ATT-02 a 06) e como vai no
 * payload (`03` §5). Um valor é a linha do rascunho:
 * `['value_id' => ?, 'value_name' => ?, 'value_number' => ?, 'value_unit' => ?]`.
 *
 * Os erros que isto evita foram medidos na sondagem de 01/10: texto livre em
 * lista fechada (3510), número sem unidade ou com unidade estranha (3708/344),
 * texto acima de 255 (154/394) e "Não se aplica" em obrigatório (100).
 *
 * Vazio NÃO é problema de campo: obrigatório vazio é regra do rascunho (L2).
 */
final class ValorAtributo
{
    public const NAO_SE_APLICA = '-1';

    /** @return array{regra: string, mensagem: string}|null */
    public static function problema(A $a, array $valor): ?array
    {
        $id = trim((string) ($valor['value_id'] ?? ''));

        if ($id === self::NAO_SE_APLICA) {
            return $a->aceitaNaoSeAplica ? null : self::erro('V-ATT-06', "«{$a->nome}» não aceita «Não se aplica».");
        }
        if (self::vazio($valor)) {
            return null;
        }

        if ($id !== '') {
            return self::daLista($a, $id) || $a->valores === []
                ? null
                : self::erro('V-ATT-03', "Escolha um valor da lista para «{$a->nome}».");
        }

        if (in_array($a->valueType, ['number', 'number_unit'], true)) {
            $numero = self::numero($valor);
            if ($numero === null) {
                return self::erro('V-ATT-02', "«{$a->nome}» precisa ser um número.");
            }
            if ($a->valueType === 'number_unit' && self::unidade($a, $valor) === null) {
                $aceitas = implode(', ', $a->unidades);

                return self::erro('V-ATT-04', "Unidade inválida em «{$a->nome}». Use: {$aceitas}.");
            }

            return null;
        }

        $nome = trim((string) ($valor['value_name'] ?? ''));
        if ($a->valores !== [] && ! $a->aceitaTextoLivre && self::pelaLista($a, $nome) === null) {
            return self::erro('V-ATT-03', "Escolha um valor da lista para «{$a->nome}».");
        }
        if (mb_strlen($nome) > $a->maxLength) {
            return self::erro('V-ATT-05', "«{$a->nome}» aceita até {$a->maxLength} caracteres.");
        }

        return null;
    }

    /**
     * O atributo no formato do ML, ou nulo se não há valor. Supõe `problema() === null`.
     * - N/A: `{"id", "value_id": "-1", "value_name": null}` (TC-39)
     * - lista: sempre por `value_id` (texto que bate com a lista vira o id)
     * - número com unidade: `"23 cm"`, com a unidade padrão quando faltar (TC-31)
     */
    public static function paraPayload(A $a, array $valor): ?array
    {
        $id = trim((string) ($valor['value_id'] ?? ''));

        if ($id === self::NAO_SE_APLICA) {
            return ['id' => $a->id, 'value_id' => self::NAO_SE_APLICA, 'value_name' => null];
        }
        if (self::vazio($valor)) {
            return null;
        }
        if ($id !== '') {
            return ['id' => $a->id, 'value_id' => $id];
        }

        if ($a->valueType === 'number_unit') {
            return ['id' => $a->id, 'value_name' => self::formatarNumero(self::numero($valor)).' '.self::unidade($a, $valor)];
        }
        if ($a->valueType === 'number') {
            return ['id' => $a->id, 'value_name' => self::formatarNumero(self::numero($valor))];
        }

        $nome = trim((string) ($valor['value_name'] ?? ''));
        $daLista = self::pelaLista($a, $nome);

        return $daLista !== null ? ['id' => $a->id, 'value_id' => $daLista['id']] : ['id' => $a->id, 'value_name' => $nome];
    }

    public static function vazio(array $valor): bool
    {
        return trim((string) ($valor['value_id'] ?? '')) === ''
            && trim((string) ($valor['value_name'] ?? '')) === ''
            && ($valor['value_number'] ?? null) === null;
    }

    /** O número digitado: `value_number`, ou o começo de `value_name` ("23,5 cm"). */
    private static function numero(array $valor): ?float
    {
        if (isset($valor['value_number']) && is_numeric($valor['value_number'])) {
            return (float) $valor['value_number'];
        }
        if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(.*)$/u', (string) ($valor['value_name'] ?? ''), $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    /** A unidade digitada, na grafia do ML; a padrão quando não há; nula quando não é aceita. */
    private static function unidade(A $a, array $valor): ?string
    {
        $digitada = trim((string) ($valor['value_unit'] ?? ''));
        if ($digitada === '' && preg_match('/^\s*\d+(?:[.,]\d+)?\s*(.*)$/u', (string) ($valor['value_name'] ?? ''), $m)) {
            $digitada = trim($m[1]);
        }
        if ($digitada === '') {
            return $a->unidadePadrao ?? ($a->unidades[0] ?? null);
        }
        foreach ($a->unidades as $u) {
            if (mb_strtolower($u) === mb_strtolower($digitada)) {
                return $u;
            }
        }

        return null;
    }

    private static function formatarNumero(?float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    }

    private static function daLista(A $a, string $id): bool
    {
        foreach ($a->valores as $v) {
            if ($v['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** O valor da lista com o mesmo nome (normalizado) do texto. */
    private static function pelaLista(A $a, string $nome): ?array
    {
        $alvo = ChaveCanonica::texto($nome);
        foreach ($a->valores as $v) {
            if ($alvo !== '' && ChaveCanonica::texto($v['name']) === $alvo) {
                return $v;
            }
        }

        return null;
    }

    private static function erro(string $regra, string $mensagem): array
    {
        return ['regra' => $regra, 'mensagem' => $mensagem];
    }
}
