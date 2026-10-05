<?php

namespace App\Services\Portal\Estrutura\Produtos;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Lê a aba "Produtos" de um .xlsx enviado pelo cliente, sem confiar nele (D-13).
 *
 * - Confere tamanho e assinatura zip (PK) ANTES de abrir; leitor fixo Xlsx.
 * - `setReadDataOnly(true)` + `toArray(null, false, false, false)`: nada de
 *   calcular fórmula — "=1+1" volta como texto e cai na validação do custo.
 * - Qualquer exceção do PhpSpreadsheet vira a mensagem fixa; o detalhe só vai
 *   para o log (o cliente nunca vê o texto da exceção).
 *
 * As colunas são casadas pelo nome normalizado (sem caixa/acento), então a
 * ordem não importa e a planilha original do Planejamento entra como está.
 */
final class LeitorPlanilhaProdutos
{
    public const MAX_LINHAS = 1000;
    public const MAX_BYTES  = 2 * 1024 * 1024;

    private const MSG_ILEGIVEL = 'Não conseguimos ler este arquivo. Use o modelo (.xlsx) e confira se a aba se chama Produtos.';

    /** Campos cujo valor numérico da célula deve virar texto (código "1014" vem como número). */
    private const TEXTUAIS = ['codigo', 'grupo', 'variacao', 'nome', 'familia', 'ambientes', 'categoria', 'volumes_texto'];

    /**
     * @return array{erro_geral: ?string, colunas: list<string>, linhas: list<array{numero: int, bruta: array<string, mixed>}>}
     */
    public function ler(string $caminho): array
    {
        $vazio = fn (?string $erro, array $colunas = [], array $linhas = []) => ['erro_geral' => $erro, 'colunas' => $colunas, 'linhas' => $linhas];

        if (! is_file($caminho) || filesize($caminho) === false || filesize($caminho) === 0) {
            return $vazio(self::MSG_ILEGIVEL);
        }
        if (filesize($caminho) > self::MAX_BYTES) {
            return $vazio('O arquivo passa de 2 MB. Divida em arquivos menores.');
        }

        $f = fopen($caminho, 'rb');
        $assinatura = $f ? fread($f, 4) : '';
        if ($f) {
            fclose($f);
        }
        if ($assinatura !== "PK\x03\x04") {
            return $vazio(self::MSG_ILEGIVEL);
        }

        try {
            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $planilha = $leitor->load($caminho);

            $folha = null;
            foreach ($planilha->getWorksheetIterator() as $aba) {
                if (Str::lower(trim($aba->getTitle())) === 'produtos') {
                    $folha = $aba;
                    break;
                }
            }
            $folha ??= $planilha->getSheet(0);

            $matriz = $folha->toArray(null, false, false, false);
        } catch (Throwable $e) {
            Log::warning('[Estrutura Produtos] falha ao ler a planilha: '.$e->getMessage());

            return $vazio(self::MSG_ILEGIVEL);
        }

        if ($matriz === []) {
            return $vazio(self::MSG_ILEGIVEL);
        }

        // ─── Cabeçalho (primeira linha) ───
        $mapa = [];
        foreach (array_values($matriz[0]) as $i => $titulo) {
            $campo = self::campoDoCabecalho((string) $titulo);
            if ($campo !== null && ! in_array($campo, $mapa, true)) {
                $mapa[$i] = $campo;
            }
        }

        if (! in_array('codigo', $mapa, true) || ! in_array('nome', $mapa, true)) {
            return $vazio(self::MSG_ILEGIVEL);
        }

        // ─── Linhas de dados ───
        $linhas = [];
        foreach ($matriz as $indice => $celulas) {
            if ($indice === 0) {
                continue;
            }

            $bruta = [];
            $temAlgo = false;
            foreach (array_values($celulas) as $i => $valor) {
                if (! isset($mapa[$i])) {
                    continue;
                }
                $campo = $mapa[$i];
                $valor = self::valor($valor, $campo);
                if ($valor !== null && $valor !== '') {
                    $temAlgo = true;
                }
                $bruta[$campo] = $valor;
            }

            if (! $temAlgo) {
                continue;
            }

            $linhas[] = ['numero' => $indice + 1, 'bruta' => $bruta];

            if (count($linhas) > self::MAX_LINHAS) {
                return $vazio('A planilha tem mais de 1.000 linhas. Divida em arquivos menores.', array_values($mapa));
            }
        }

        return $vazio(null, array_values($mapa), $linhas);
    }

    /** Campo lógico a partir do nome da coluna; null para colunas que não importamos. */
    private static function campoDoCabecalho(string $titulo): ?string
    {
        $t = Str::lower(Str::ascii($titulo));
        $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $t)));

        return match (true) {
            $t === '' => null,
            $t === 'ref', $t === 'codigo', $t === 'sku', str_starts_with($t, 'ref ') => 'codigo',
            str_starts_with($t, 'grupo') => 'grupo',
            str_starts_with($t, 'variacao') => 'variacao',
            $t === 'produto', $t === 'nome', str_starts_with($t, 'produto ') => 'nome',
            str_starts_with($t, 'familia') => 'familia',
            str_starts_with($t, 'ambiente') => 'ambientes',
            str_starts_with($t, 'categoria') => 'categoria',
            (bool) preg_match('/^(n|no|num|numero|qtd|qtde)( de)? volumes/', $t) => 'n_volumes',
            str_starts_with($t, 'volumes') => 'volumes_texto',
            str_starts_with($t, 'peso total') => 'peso_total',
            str_starts_with($t, 'custo') => 'custo',
            default => null,
        };
    }

    private static function valor(mixed $v, string $campo): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        }
        if (is_string($v)) {
            $v = trim($v);

            return $v === '' ? null : $v;
        }
        if (is_int($v) || is_float($v)) {
            if (in_array($campo, self::TEXTUAIS, true)) {
                return is_float($v) && floor($v) !== $v ? rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') : (string) (int) $v;
            }

            return $v;
        }

        return null;
    }
}
