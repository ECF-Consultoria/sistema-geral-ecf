<?php

namespace App\Services\Portal\Estrutura\Geracao;

/**
 * Nome, SKU, avisos e "porquê" sugeridos para cada oferta gerada (D-19).
 *
 * Classe pura: não lê config; os limites chegam por parâmetro. Os SKUs de Kit
 * ("KT-{a}-{b}") e Combit ("CT{n}-{fixo}-{repetido}") são suposição da pesquisa
 * (A1) e são editáveis; o do Combo segue o padrão "-CB{n}" que o
 * EstruturaOfertaService::criarCombos já usa. Título acima de 60 caracteres só
 * avisa; SKU acima de 120 bloqueia o aceite (a regra de bloqueio mora em
 * EstruturaOfertaService::campos).
 */
final class NomesSugeridos
{
    /** Igual ao nome da oferta simples da 167: "Cristaleira 1014 — Natural". */
    public const SEPARADOR_VALOR = ' — ';

    /**
     * @param  array{nome: string, plural: string}|null  $tipo
     * @return array{nome: string, sku: string}
     */
    public static function combo(string $produtoNome, string $sku, ?string $valor, int $n, ?array $tipo): array
    {
        $nome = "Combo {$n} {$produtoNome}";

        if ($tipo !== null) {
            $resto = self::semPalavraDoTipo($produtoNome, $tipo);
            if ($resto !== null && $resto !== '') {
                $nome = "Kit {$n} {$tipo['plural']} {$resto}";
            }
        }

        return [
            'nome' => $nome . self::sufixo([$valor]),
            'sku'  => "{$sku}-CB{$n}",
        ];
    }

    /**
     * @param  array{produto_nome: string, sku: string, valor: ?string}  $a
     * @param  array{produto_nome: string, sku: string, valor: ?string}  $b
     * @return array{nome: string, sku: string}
     */
    public static function kit(array $a, array $b): array
    {
        return [
            'nome' => "{$a['produto_nome']} + {$b['produto_nome']}" . self::sufixo([$a['valor'] ?? null, $b['valor'] ?? null]),
            'sku'  => "KT-{$a['sku']}-{$b['sku']}",
        ];
    }

    /**
     * Kit de 3 (08/10): mesmo padrão do Kit de 2, com todos os produtos e SKUs na ordem.
     *
     * @param  list<array{produto_nome: string, sku: string, valor: ?string}>  $itens
     * @return array{nome: string, sku: string}
     */
    public static function kitDeVarios(array $itens): array
    {
        return [
            'nome' => implode(' + ', array_column($itens, 'produto_nome')) . self::sufixo(array_map(fn ($i) => $i['valor'] ?? null, $itens)),
            'sku'  => 'KT-' . implode('-', array_column($itens, 'sku')),
        ];
    }

    /**
     * @param  array{produto_nome: string, sku: string, valor: ?string}  $fixo
     * @param  array{produto_nome: string, sku: string, valor: ?string}  $repetido
     * @param  array{nome: string, plural: string}  $tipoRepetido
     * @return array{nome: string, sku: string}
     */
    public static function combit(array $fixo, array $repetido, int $n, array $tipoRepetido): array
    {
        return [
            'nome' => "{$fixo['produto_nome']} + {$n} {$tipoRepetido['plural']}" . self::sufixo([$fixo['valor'] ?? null, $repetido['valor'] ?? null]),
            'sku'  => "CT{$n}-{$fixo['sku']}-{$repetido['sku']}",
        ];
    }

    /**
     * @param  array{max_titulo: int, max_sku: int}  $limites
     * @return list<array{codigo: string, valor: int}>
     */
    public static function avisos(string $nome, string $sku, array $limites): array
    {
        $avisos = [];

        $t = mb_strlen($nome);
        if ($t > $limites['max_titulo']) {
            $avisos[] = ['codigo' => 'titulo_longo', 'valor' => $t];
        }

        $s = mb_strlen($sku);
        if ($s > $limites['max_sku']) {
            $avisos[] = ['codigo' => 'sku_longo', 'valor' => $s];
        }

        return $avisos;
    }

    /**
     * Texto do porquê, no contrato da UI-SPEC.
     *
     * @param  array{familia?: ?string, ambientes?: list<string>, tipo_a?: ?string, tipo_b?: ?string, tipos?: list<string>, repete?: ?string, tipo?: ?string, quantidades?: list<int>, quantidades_do_produto?: bool}  $ctx
     */
    public static function porque(string $fase, array $ctx): string
    {
        if ($fase === 'combo') {
            $qtds = implode(', ', $ctx['quantidades'] ?? []);
            $base = 'Mesmo produto em mais unidades. ';

            if (! empty($ctx['quantidades_do_produto'])) {
                return "{$base}Quantidades do produto: {$qtds}.";
            }

            $tipo = $ctx['tipo'] ?? null;

            return $tipo !== null && $tipo !== ''
                ? "{$base}Tipo: {$tipo} ({$qtds})."
                : "{$base}Quantidades: {$qtds}.";
        }

        $partes = [];
        if (! empty($ctx['familia'])) {
            $partes[] = "Mesma família: {$ctx['familia']}.";
        }
        if (! empty($ctx['ambientes'])) {
            $partes[] = 'Ambiente em comum: ' . implode(', ', $ctx['ambientes']) . '.';
        }

        // Kit de 3 (08/10): os três pares entre os tipos estão na lista.
        if (! empty($ctx['tipos'])) {
            $nomes = array_map(fn ($t) => mb_strtolower((string) $t), $ctx['tipos']);
            $partes[] = 'Conjunto: ' . implode(' + ', $nomes) . '; todos os pares estão na lista.';

            return implode(' ', $partes);
        }

        $a = mb_strtolower((string) ($ctx['tipo_a'] ?? ''));
        $b = mb_strtolower((string) ($ctx['tipo_b'] ?? ''));
        $par = "Par: {$a} + {$b}";

        $repete = $ctx['repete'] ?? null;
        if ($fase === 'combit' && $repete !== null && $repete !== '') {
            $r = mb_strtolower($repete);
            $par .= '; ' . match (mb_substr($r, -1)) {
                'a'     => "a {$r}",
                'o'     => "o {$r}",
                default => $r,
            } . ' se repete';
        }

        $partes[] = $par . '.';

        return implode(' ', $partes);
    }

    /** Valores não vazios, sem repetição (comparação normalizada), unidos por " / ". */
    private static function sufixo(array $valores): string
    {
        $vistos = [];
        foreach ($valores as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $vistos[TipoDoProduto::normalizar($v)] ??= $v;
        }

        return $vistos === [] ? '' : self::SEPARADOR_VALOR . implode(' / ', $vistos);
    }

    /**
     * Nome sem os primeiros tokens quando eles são iguais ao nome ou ao plural
     * do tipo (token inteiro, nunca prefixo solto). Null = não começa pelo tipo.
     *
     * @param  array{nome: string, plural: string}  $tipo
     */
    private static function semPalavraDoTipo(string $produtoNome, array $tipo): ?string
    {
        $tokens = preg_split('/\s+/u', trim($produtoNome), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ([$tipo['plural'] ?? '', $tipo['nome'] ?? ''] as $palavra) {
            $alvo = TipoDoProduto::normalizar($palavra);
            if ($alvo === '') {
                continue;
            }
            $k = count(explode(' ', $alvo));
            if (count($tokens) < $k) {
                continue;
            }
            if (TipoDoProduto::normalizar(implode(' ', array_slice($tokens, 0, $k))) === $alvo) {
                return trim(implode(' ', array_slice($tokens, $k)));
            }
        }

        return null;
    }
}
