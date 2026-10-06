<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\EstruturaProdutoVariacao;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A ÚNICA leitura de uma linha de produto: grade, celular e planilha passam por aqui.
 *
 * Função pura (sem banco, sem HTTP): recebe a linha crua e devolve campos
 * validados, a lista do que a linha realmente trouxe (`presentes`), erros por
 * campo e avisos. Quem grava é o `ProdutoCadastroService`.
 *
 * ### Por que a coluna "Variação" é ordinal (D-20)
 * Na planilha real a coluna "Variação" traz "1", "2" ou "única" — a posição da
 * variação dentro do grupo — e não "Cor: Natural". Por isso o ordinal é aceito
 * e vira `ordem`; o formato "Eixo: valor" também é aceito; um texto solto vira
 * o `valor` da variação. Eixo explícito na linha vence o da coluna Variação.
 *
 * ### `presentes`
 * É a base de "não mexer" (importação) e da cópia da 1ª variação (D-04). Texto
 * vazio NÃO conta como presente (célula em branco não apaga dado); `null`
 * explícito em `custo`, `eixo`, `valor` e `familia` e lista vazia em `volumes`
 * contam (limpar de propósito — a ficha manda `null` quando a pessoa esvazia o
 * campo, FE-CR-03/BE-IN-05). Chave ausente = não mexer.
 * "SEM MEDIDAS" em `volumes_texto` é célula em branco, não "limpar" (BE-CR-02).
 *
 * Nomes lógicos em `presentes`: grupo, nome, eixo, valor, ordem, familia,
 * ambientes, categoria, volumes, custo.
 */
final class NormalizadorDeLinha
{
    private const MAX_CODIGO    = 120;
    private const MAX_NOME      = 255;
    private const MAX_GRUPO     = 120;
    private const MAX_VALOR     = 80;
    private const MAX_VOLUMES   = 20;
    private const MAX_MEDIDA    = 999.99;
    private const MAX_PESO      = 9999.999;
    private const MAX_CUSTO     = 9999999999;
    private const MSG_SEPARADOR = 'Não use / , | no nome. Escolha um nome simples.';

    /**
     * @return array{campos: array<string, mixed>, presentes: list<string>, erros: array<string, string>, avisos: list<string>}
     */
    public static function normalizar(array $bruta): array
    {
        $campos = [
            'id'              => self::inteiro($bruta['id'] ?? null),
            'produto_id'      => self::inteiro($bruta['produto_id'] ?? null),
            'chave'           => null,
            'grupo'           => null,
            'codigo'          => '',
            'nome'            => '',
            'eixo'            => null,
            'valor'           => null,
            'ordem'           => null,
            'familia'         => null,
            'ambientes'       => [],
            'categoria_ml_id' => null,
            'categoria_texto' => null,
            'volumes'         => [],
            'custo'           => null,
        ];
        $presentes = [];
        $erros     = [];
        $avisos    = [];

        $chave = self::texto($bruta['chave'] ?? null);
        $campos['chave'] = $chave === '' ? null : mb_substr($chave, 0, 64);

        // ─── Código e nome (obrigatórios) ───
        $codigo = self::texto($bruta['codigo'] ?? null);
        if ($codigo === '') {
            $erros['codigo'] = 'Informe o código (Ref).';
        } elseif (mb_strlen($codigo) > self::MAX_CODIGO) {
            $erros['codigo'] = 'O código pode ter no máximo '.self::MAX_CODIGO.' caracteres.';
        }
        $campos['codigo'] = $codigo;

        $nome = self::texto($bruta['nome'] ?? null);
        if ($nome === '') {
            $erros['nome'] = 'Informe o nome do produto.';
        } elseif (mb_strlen($nome) > self::MAX_NOME) {
            $erros['nome'] = 'O nome pode ter no máximo '.self::MAX_NOME.' caracteres.';
        }
        $campos['nome'] = $nome;
        if ($nome !== '') {
            $presentes[] = 'nome';
        }

        // ─── Grupo ───
        $grupo = self::texto($bruta['grupo'] ?? null);
        if ($grupo !== '') {
            if (mb_strlen($grupo) > self::MAX_GRUPO) {
                $erros['grupo'] = 'O grupo pode ter no máximo '.self::MAX_GRUPO.' caracteres.';
            }
            $campos['grupo'] = $grupo;
            $presentes[] = 'grupo';
        }

        // ─── Variação: ordinal, "Eixo: valor" ou valor solto ───
        $ordem = null;
        $eixoDaColuna = null;
        $valorDaColuna = null;

        $variacao = self::texto($bruta['variacao'] ?? null);
        if ($variacao !== '') {
            $simples = Str::lower(Str::ascii($variacao));

            if (in_array($simples, ['unica', 'unico'], true)) {
                $ordem = 1;
            } elseif (preg_match('/^\d{1,4}$/', $variacao)) {
                $ordem = max(1, (int) $variacao);
            } elseif (str_contains($variacao, ':')) {
                [$rotulo, $resto] = array_map('trim', explode(':', $variacao, 2));
                $eixo = self::eixo($rotulo);
                if ($eixo === null) {
                    $eixo = 'outro';
                    $avisos[] = "Variação “{$variacao}”: o eixo “{$rotulo}” não está na lista, usamos “Outro”.";
                }
                $eixoDaColuna  = $eixo;
                $valorDaColuna = $resto;
            } else {
                $valorDaColuna = $variacao;
            }
        }

        // Ordem explícita vence o ordinal da coluna.
        $ordemExplicita = self::inteiro($bruta['ordem'] ?? null);
        if ($ordemExplicita !== null && $ordemExplicita > 0) {
            $ordem = $ordemExplicita;
        }
        if ($ordem !== null) {
            $campos['ordem'] = $ordem;
            $presentes[] = 'ordem';
        }

        // ─── Eixo ───
        $eixoBruto = self::texto($bruta['eixo'] ?? null);
        if (self::nuloExplicito($bruta, 'eixo')) {
            // null explícito = tirar o eixo de propósito (a ficha escolheu "—"); vence a coluna Variação.
            $campos['eixo'] = null;
            $presentes[] = 'eixo';
        } elseif ($eixoBruto !== '') {
            $eixo = self::eixo($eixoBruto);
            if ($eixo === null) {
                $erros['eixo'] = 'Escolha o eixo: '.implode(', ', array_values(EstruturaProdutoVariacao::EIXOS)).'.';
            } else {
                $campos['eixo'] = $eixo;
                $presentes[] = 'eixo';
            }
        } elseif ($eixoDaColuna !== null) {
            $campos['eixo'] = $eixoDaColuna;
            $presentes[] = 'eixo';
        }

        // ─── Valor ───
        $valor = self::texto($bruta['valor'] ?? null);
        if ($valor === '' && $valorDaColuna !== null && ! self::nuloExplicito($bruta, 'valor')) {
            $valor = $valorDaColuna;
        }
        if (self::nuloExplicito($bruta, 'valor')) {
            // null explícito = apagar o valor; a oferta ligada passa a se chamar só pelo produto.
            $campos['valor'] = null;
            $presentes[] = 'valor';
        } elseif ($valor !== '') {
            if (mb_strlen($valor) > self::MAX_VALOR) {
                $erros['valor'] = 'O valor pode ter no máximo '.self::MAX_VALOR.' caracteres.';
            }
            $campos['valor'] = $valor;
            $presentes[] = 'valor';
        }

        // ─── Família ───
        $familia = ListasDaEmpresaService::limpar(self::texto($bruta['familia'] ?? null));
        if (self::nuloExplicito($bruta, 'familia')) {
            // null explícito = produto sem família (BE-IN-05); a família continua na lista da empresa.
            $campos['familia'] = null;
            $presentes[] = 'familia';
        } elseif ($familia !== '') {
            if (preg_match('/[\/,|]/u', $familia)) {
                $erros['familia'] = self::MSG_SEPARADOR;
            } elseif (mb_strlen($familia) > 80) {
                $erros['familia'] = 'O nome pode ter no máximo 80 caracteres.';
            }
            $campos['familia'] = $familia;
            $presentes[] = 'familia';
        }

        // ─── Ambientes ───
        if (array_key_exists('ambientes', $bruta)) {
            $nomes = self::ambientes($bruta['ambientes']);
            foreach ($nomes as $n) {
                if (mb_strlen($n) > 80) {
                    $erros['ambientes'] = 'O nome pode ter no máximo 80 caracteres.';
                    break;
                }
            }
            if ($nomes !== []) {
                $campos['ambientes'] = $nomes;
                $presentes[] = 'ambientes';
            } elseif (is_array($bruta['ambientes'])) {
                // Lista vazia enviada de propósito = tirar todos os ambientes.
                $presentes[] = 'ambientes';
            }
        }

        // ─── Categoria ───
        if (array_key_exists('categoria_ml_id', $bruta) || array_key_exists('categoria_texto', $bruta)) {
            $id    = self::texto($bruta['categoria_ml_id'] ?? null);
            $texto = self::texto($bruta['categoria_texto'] ?? null);

            if ($id !== '' && preg_match('/^MLB\d{1,17}$/i', $id)) {
                $campos['categoria_ml_id'] = strtoupper($id);
            } elseif ($id !== '') {
                // Texto no campo do id (colado do Excel): fica "a confirmar", nunca vira id.
                $campos['categoria_texto'] = mb_substr($id, 0, 255);
            } elseif ($texto !== '') {
                $campos['categoria_texto'] = mb_substr($texto, 0, 255);
            }
            // Os dois vazios = limpar (presente, mas sem id nem texto).
            $presentes[] = 'categoria';
        }

        // ─── Custo ───
        if (array_key_exists('custo', $bruta)) {
            $custoBruto = $bruta['custo'];
            $vazioTexto = is_string($custoBruto) && trim($custoBruto) === '';

            if ($custoBruto === null) {
                $presentes[] = 'custo';
            } elseif (! $vazioTexto) {
                try {
                    $custo = NumeroBr::interpretar($custoBruto, NumeroBr::DINHEIRO);
                    if ($custo !== null && $custo > self::MAX_CUSTO) {
                        $erros['custo'] = 'O custo é alto demais. Confira o valor.';
                    } else {
                        $campos['custo'] = $custo;
                        $presentes[] = 'custo';
                    }
                } catch (InvalidArgumentException) {
                    $erros['custo'] = NumeroBr::MENSAGEM;
                }
            }
        }

        // ─── Volumes ───
        if (isset($bruta['volumes']) && is_array($bruta['volumes'])) {
            [$volumes, $erroVol] = self::volumes($bruta['volumes']);
            if ($erroVol !== null) {
                $erros['volumes'] = $erroVol;
            } else {
                $campos['volumes'] = $volumes;
                $presentes[] = 'volumes';
            }
        } elseif (($volumesTexto = self::texto($bruta['volumes_texto'] ?? null)) !== '' && ! VolumesTexto::semMedidas($volumesTexto)) {
            // "SEM MEDIDAS" é célula em branco: reimportar não apaga as medidas digitadas (BE-CR-02).
            $lido = VolumesTexto::interpretar((string) $bruta['volumes_texto']);
            if (! $lido['valido']) {
                // Código do aviso: medidas_ilegiveis. A linha segue sem mexer nas medidas.
                $avisos[] = 'Não entendi as medidas. Use 186×43×12 · 27,8 (comprimento × largura × altura · peso em kg).';
            } else {
                [$volumes, $erroVol] = self::volumes($lido['volumes']);
                if ($erroVol !== null) {
                    $erros['volumes'] = $erroVol;
                } else {
                    $campos['volumes'] = $volumes;
                    $presentes[] = 'volumes';
                }
            }
        }

        return [
            'campos'    => $campos,
            'presentes' => array_values(array_unique($presentes)),
            'erros'     => $erros,
            'avisos'    => $avisos,
        ];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    private static function texto(mixed $v): string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    }

    /** A chave veio na linha com `null` — "limpar de propósito", diferente de ausente ou texto vazio. */
    private static function nuloExplicito(array $bruta, string $campo): bool
    {
        return array_key_exists($campo, $bruta) && $bruta[$campo] === null;
    }

    private static function inteiro(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d{1,18}$/', trim($v))) {
            return (int) trim($v);
        }

        return null;
    }

    /** Chave de EIXOS a partir da chave ou do rótulo ("Tamanho" → 'tamanho'). */
    private static function eixo(string $texto): ?string
    {
        $t = Str::lower(Str::ascii(trim($texto)));
        foreach (EstruturaProdutoVariacao::EIXOS as $chave => $rotulo) {
            if ($t === $chave || $t === Str::lower(Str::ascii($rotulo))) {
                return $chave;
            }
        }

        return null;
    }

    /** @return list<string> nomes limpos, sem repetir (por chave de comparação) */
    private static function ambientes(mixed $v): array
    {
        $itens = is_array($v) ? $v : [$v];
        $saida = [];

        foreach ($itens as $item) {
            foreach (preg_split('/[\/,;|]+/u', self::texto($item)) as $parte) {
                $nome = ListasDaEmpresaService::limpar($parte);
                if ($nome !== '') {
                    $saida[ListasDaEmpresaService::chave($nome)] ??= $nome;
                }
            }
        }

        return array_values($saida);
    }

    /**
     * @param  array<int, mixed>  $brutos
     * @return array{0: list<array{c: float, l: float, a: float, kg: float}>, 1: ?string}
     */
    private static function volumes(array $brutos): array
    {
        if (count($brutos) > self::MAX_VOLUMES) {
            return [[], 'Use no máximo '.self::MAX_VOLUMES.' volumes por variação.'];
        }

        $saida = [];
        foreach ($brutos as $v) {
            if (! is_array($v)) {
                return [[], 'Informe comprimento, largura, altura e peso maiores que zero.'];
            }

            try {
                $c  = NumeroBr::interpretar($v['c'] ?? null, NumeroBr::MEDIDA);
                $l  = NumeroBr::interpretar($v['l'] ?? null, NumeroBr::MEDIDA);
                $a  = NumeroBr::interpretar($v['a'] ?? null, NumeroBr::MEDIDA);
                $kg = NumeroBr::interpretar($v['kg'] ?? null, NumeroBr::MEDIDA);
            } catch (InvalidArgumentException) {
                return [[], NumeroBr::MENSAGEM];
            }

            if (! $c || ! $l || ! $a || ! $kg) {
                return [[], 'Informe comprimento, largura, altura e peso maiores que zero.'];
            }
            if ($c > self::MAX_MEDIDA || $l > self::MAX_MEDIDA || $a > self::MAX_MEDIDA) {
                return [[], 'Cada medida pode ter no máximo 999,99 cm.'];
            }
            if ($kg > self::MAX_PESO) {
                return [[], 'O peso pode ter no máximo 9.999,999 kg.'];
            }

            $saida[] = ['c' => $c, 'l' => $l, 'a' => $a, 'kg' => $kg];
        }

        return [$saida, null];
    }
}
