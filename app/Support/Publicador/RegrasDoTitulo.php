<?php

namespace App\Support\Publicador;

use Illuminate\Support\Str;

/**
 * Regras determinísticas do TÍTULO gerado pela IA (09/10/2026, relato do usuário em produção).
 *
 * Caso que originou: o preparo pela IA gerou "Puff Sala Redondo Banqueta Moderno ECF 130 kg" e pôs o
 * MESMO título no Clássico e no Premium. Dois problemas, duas regras:
 *
 * 1. Sem marca e sem número de especificação (`limpar`): a marca do produto (atributo BRAND), "ECF"
 *    e número com unidade de peso/capacidade/potência ("130 kg", "2 l", "1000 W") saem SEMPRE. Medida
 *    em cm/m, dimensão ("160x90"), quantidade ("3 lugares") e número solto só ficam quando o nome do
 *    produto ou os termos de busca já os têm — "Mesa 160x90" mantém a medida porque ela é o nome.
 * 2. Dois títulos nunca iguais (`mesmo` / `diferenciar`): o Mercado Livre barra dois anúncios com o
 *    mesmo nome. "Igual" ignora caixa, acento e plural simples. A diferença gerada aqui não inventa
 *    nada: troca a ordem das duas últimas palavras (o produto principal continua no começo) e, só se
 *    não der, acrescenta/troca uma palavra de um termo de busca que passou no filtro dos fatos.
 *
 * O prompt pede o mesmo (`AnaliseAnuncioService::promptTituloPorTermos`); este filtro existe porque a
 * IA não obedece sempre. Puro: quem lê o rascunho é o `PalavrasChaveService`.
 */
final class RegrasDoTitulo
{
    /** Marca/loja que nunca entra no título, além da marca do produto. */
    public const MARCAS_SEMPRE = ['ECF'];

    /** Unidades de peso, capacidade, potência e tensão: o número com elas sai SEMPRE. */
    private const UNIDADES_DE_ESPECIFICACAO = 'kgs?|quilos?|kilos?|g|gr|grs|gramas?|mg|l|lt|lts|litros?|ml|w|watts?|kw|v|volts?|mah|btus?|hp|cv';

    /** Medida e quantidade: o número com elas fica só se o nome do produto ou os termos de busca o têm. */
    private const UNIDADES_DE_MEDIDA = 'mm|cm|m|mts?|metros?|pol|polegadas?|pecas?|peças?|pcs|pçs|unidades?|un|lugares?';

    /** Palavras de ligação que não podem sobrar na ponta depois do filtro. */
    private const LIGACAO = ['de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'e', 'em', 'no', 'na', 'por', 'ate', 'suporta'];

    /**
     * O título sem marca, sem "ECF" e sem número de especificação, com os caracteres que o ruleset ECF
     * proíbe trocados por espaço. NÃO corta no limite (quem corta é o `ajustarTitulo`).
     *
     * @param  list<string>  $marcas  a marca do produto (BRAND); "ECF" entra sempre
     * @param  string  $referencia  nome do produto + termos de busca: o que eles têm de medida fica
     */
    public static function limpar(string $bruto, array $marcas, string $referencia): string
    {
        // 0. Termo que o ML veta ("criado-mudo"; infração de linguagem, 10/10/2026) vira o aceito.
        $bruto = TermosVetados::trocar($bruto);
        $unidadesEspec = self::UNIDADES_DE_ESPECIFICACAO;
        $unidadesMedida = self::UNIDADES_DE_MEDIDA;
        $antes = '(?<![\p{L}\p{N}])';
        $depois = '(?![\p{L}\p{N}])';
        $numero = '\d+(?:[.,]\d+)?';

        // 1. Peso/capacidade/potência: sai sempre ("130 kg", "130kg", "110/220v", "1,5 l"). "5G" de celular não é grama.
        $t = (string) preg_replace("/{$antes}(?![2-5]g{$depois}){$numero}(?:\s*\/\s*{$numero})*\s*(?:{$unidadesEspec}){$depois}/iu", ' ', $bruto);

        // 2. Medida, dimensão, quantidade e número solto: fica só se o nome/termos já o têm.
        $conhecidos = self::numerosDe($referencia);
        $padraoMedida = "/{$antes}{$numero}(?:\s*[x×]\s*{$numero})*(?:\s*(?:{$unidadesMedida}))?{$depois}/iu";
        $t = (string) preg_replace_callback($padraoMedida, fn ($m) => isset($conhecidos[self::chaveDoNumero($m[0])]) ? $m[0] : ' ', $t);

        // 3. Caracteres proibidos e a marca (frase inteira, sem caixa nem acento).
        $tokens = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = self::semMarcas($tokens, [...self::MARCAS_SEMPRE, ...$marcas]);

        // 4. Ligação que sobrou na ponta ("Puff Banqueta Suporta" depois de tirar "130 kg").
        while ($tokens !== [] && in_array(self::normal(end($tokens)), self::LIGACAO, true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }

    /**
     * Dois títulos são "o mesmo" para o Mercado Livre? Mesma sequência de palavras, ignorando caixa,
     * acento e plural simples ("Puffs" × "Puff"). Ordem diferente = título diferente.
     */
    public static function mesmo(?string $a, ?string $b): bool
    {
        $pa = FatosDoProduto::palavras((string) $a);
        $pb = FatosDoProduto::palavras((string) $b);
        if ($pa === [] || $pb === [] || count($pa) !== count($pb)) {
            return false;
        }
        foreach ($pa as $i => $p) {
            if (array_intersect(self::formas($p), self::formas($pb[$i])) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * O título, diferente de `outro`. Igual a ele, tenta nesta ordem: (1) trocar a ordem das duas
     * últimas palavras, com 3 ou mais (o produto principal no começo não se move); (2) acrescentar
     * uma palavra de termo de busca coerente que caiba no máximo, ou trocar a última por ela. Sem
     * saída, devolve o título como veio — quem chama decide (nunca grava dois iguais).
     *
     * @param  list<string>  $candidatos  palavras de termos de busca já filtradas (`candidatos()`)
     */
    public static function diferenciar(string $titulo, string $outro, array $candidatos, int $maximo): string
    {
        if (! self::mesmo($titulo, $outro)) {
            return $titulo;
        }
        $tokens = preg_split('/\s+/u', trim($titulo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($tokens);
        if ($n >= 3) {
            $trocado = $tokens;
            [$trocado[$n - 2], $trocado[$n - 1]] = [$trocado[$n - 1], $trocado[$n - 2]];
            $novo = implode(' ', $trocado);
            if (! self::mesmo($novo, $outro)) {
                return $novo;
            }
        }
        $doTitulo = self::formasDe($titulo);
        foreach ($candidatos as $palavra) {
            if (array_intersect(self::formas(self::normal($palavra)), $doTitulo) !== []) {
                continue;
            }
            $palavra = mb_convert_case($palavra, MB_CASE_TITLE);
            $novo = trim("{$titulo} {$palavra}");
            if (mb_strlen($novo) <= $maximo) {
                return $novo;
            }
            if ($n >= 2) {
                $novo = implode(' ', [...array_slice($tokens, 0, $n - 1), $palavra]);
                if (mb_strlen($novo) <= $maximo && ! self::mesmo($novo, $outro)) {
                    return $novo;
                }
            }
        }

        return $titulo;
    }

    /**
     * Cada título diferente dos já decididos: primeiro os `fixos` (da equipe, ou o do outro tipo que
     * a pessoa já preencheu), depois os anteriores da própria lista, na ordem dela (Clássico →
     * Premium). O que nem `diferenciar` salva sai VAZIO — quem chama não o grava.
     *
     * @param  array<string, string>  $titulos  listing_type_id → título
     * @param  array<array-key, string>  $fixos
     * @param  list<string>  $candidatos
     * @return array<string, string>
     */
    public static function semRepetir(array $titulos, array $fixos, array $candidatos, int $maximo): array
    {
        $decididos = array_values(array_filter(array_map(fn ($t) => trim((string) $t), $fixos)));
        $saida = [];
        foreach ($titulos as $tipo => $titulo) {
            $titulo = trim((string) $titulo);
            foreach ($decididos as $d) {
                $titulo = self::diferenciar($titulo, $d, $candidatos, $maximo);
            }
            $repetido = false;
            foreach ($decididos as $d) {
                $repetido = $repetido || self::mesmo($titulo, $d);
            }
            $saida[$tipo] = $repetido ? '' : $titulo;
            if ($saida[$tipo] !== '') {
                $decididos[] = $saida[$tipo];
            }
        }

        return $saida;
    }

    /**
     * Palavras que podem diferenciar um título: as dos termos de busca RELACIONADOS ao produto (têm
     * palavra do nome dele) que passam no filtro dos fatos — nunca cor, número, ligação nem marca.
     *
     * @param  list<string>  $termos  do mais buscado para o menos
     * @param  list<string>  $marcas
     * @return list<string>
     */
    public static function candidatos(array $termos, string $produto, ?FatosDoProduto $fatos, array $marcas): array
    {
        $doProduto = self::formasDe($produto);
        $daMarca = [];
        foreach ([...self::MARCAS_SEMPRE, ...$marcas] as $m) {
            foreach (FatosDoProduto::palavras($m) as $p) {
                $daMarca[$p] = true;
            }
        }
        $saida = [];
        foreach ($termos as $termo) {
            $palavras = FatosDoProduto::palavras((string) $termo);
            $relacionado = false;
            foreach ($palavras as $p) {
                if (array_intersect(self::formas($p), $doProduto) !== []) {
                    $relacionado = true;
                }
            }
            if (! $relacionado || $fatos?->motivoParaDescartar((string) $termo) !== null) {
                continue;
            }
            foreach ($palavras as $p) {
                if (strlen($p) < 3 || preg_match('/\d/', $p) || in_array($p, self::LIGACAO, true) || isset($daMarca[$p])
                    || FatosDoProduto::ehCor($p) || array_intersect(self::formas($p), $doProduto) !== []) {
                    continue;
                }
                $saida[$p] = true;
            }
        }

        return array_keys($saida);
    }

    /**
     * Singular simples para comparar: a palavra, sem o "s" e sem o "es" final (o mesmo critério do
     * Modelo no `PalavrasChaveService`).
     *
     * @return list<string>
     */
    public static function formas(string $p): array
    {
        $formas = [$p];
        if (strlen($p) > 3 && str_ends_with($p, 's')) {
            $formas[] = substr($p, 0, -1);
        }
        if (strlen($p) > 4 && str_ends_with($p, 'es')) {
            $formas[] = substr($p, 0, -2);
        }

        return $formas;
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** @return list<string> todas as formas das palavras do texto */
    private static function formasDe(string $texto): array
    {
        $saida = [];
        foreach (FatosDoProduto::palavras($texto) as $p) {
            array_push($saida, ...self::formas($p));
        }

        return $saida;
    }

    /**
     * Tira as marcas como FRASE inteira ("Casa Moderna" sai junto; "moderna" sozinha fica).
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $marcas
     * @return list<string>
     */
    private static function semMarcas(array $tokens, array $marcas): array
    {
        $frases = [];
        foreach ($marcas as $m) {
            $palavras = FatosDoProduto::palavras((string) $m);
            if ($palavras !== []) {
                $frases[implode(' ', $palavras)] = $palavras;
            }
        }
        // A frase mais longa primeiro: "ECF Móveis" sai inteira antes de "ECF".
        uasort($frases, fn ($a, $b) => count($b) <=> count($a));

        foreach ($frases as $frase) {
            $k = count($frase);
            $saida = [];
            for ($i = 0; $i < count($tokens); $i++) {
                $trecho = array_map(fn ($t) => self::normal($t), array_slice($tokens, $i, $k));
                if ($trecho === $frase) {
                    $i += $k - 1;

                    continue;
                }
                $saida[] = $tokens[$i];
            }
            $tokens = $saida;
        }

        return $tokens;
    }

    /** @return array<string, true> as chaves dos números (com medida) que o texto tem */
    private static function numerosDe(string $texto): array
    {
        $saida = [];
        preg_match_all('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?(?:\s*[x×]\s*\d+(?:[.,]\d+)?)*/u', $texto, $m);
        foreach ($m[0] as $achado) {
            $saida[self::chaveDoNumero($achado)] = true;
        }

        return $saida;
    }

    /** "160 x 90 cm" → "160x90"; "1,5 m" → "1.5". */
    private static function chaveDoNumero(string $trecho): string
    {
        // Nenhuma unidade de medida tem "x": o que sobra é só o número e o "x" da dimensão.
        return (string) preg_replace('/[^0-9x.]/', '', str_replace(['×', ','], ['x', '.'], mb_strtolower($trecho)));
    }

    private static function normal(string $palavra): string
    {
        return implode('', FatosDoProduto::palavras($palavra)) ?: Str::lower($palavra);
    }
}
