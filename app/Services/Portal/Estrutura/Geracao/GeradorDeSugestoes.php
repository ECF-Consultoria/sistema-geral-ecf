<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\EstruturaOferta;

/**
 * Gerador de sugestões Combo/Kit/Combit (Fase 168, PR168-01/02/03).
 *
 * ÚNICA implementação da regra: a tela e o aceite usam esta função (o aceite
 * regera e confere a chave). O trabalho de verdade é decidir o que NÃO sugerir:
 * sem a lista de pares o Kit vai de 91 para 136, sem direção o Combit vai de 28
 * para 140-168 (medido na planilha real).
 *
 * Função PURA: recebe o retrato em arrays e devolve a lista; sem Eloquent, sem
 * banco, sem config e sem relógio. Mesma saída qualquer que seja a ordem da
 * entrada.
 *
 * Regras (CONTEXT): só variação com oferta simples ligada (D-09); Combo vale
 * para qualquer produto, com as quantidades do produto ou do tipo (D-07); Kit e
 * Combit só dentro da mesma família não nula, com ambiente em comum, os dois com
 * tipo e o par de tipos na lista (D-05, D-06); Combit dirigido (D-14);
 * variações em paralelo, nunca cartesiano (D-17); composição que já existe nunca
 * sai e a descartada sai marcada (D-03).
 *
 * Kit de 3 (08/10, revê o D-16 a pedido do usuário): três produtos da mesma
 * família, de três tipos diferentes, com ambiente em comum aos TRÊS e os TRÊS
 * pares de tipo na lista (gabinete + espelho + lixeira). Regra conservadora: um
 * conjunto "conexo" (só dois pares) não basta. Só Kit, todos x1; Combit de 3 e
 * conjuntos de 4+ ficam fora.
 */
final class GeradorDeSugestoes
{
    /** Ordem das fases na saída. */
    private const ORDEM_FASE = [
        EstruturaOferta::FASE_COMBO  => 0,
        EstruturaOferta::FASE_KIT    => 1,
        EstruturaOferta::FASE_COMBIT => 2,
    ];

    /**
     * @param  array<string,mixed>  $retrato  produtos, tipos, pares, existentes, descartadas, limites
     * @return list<array<string,mixed>>
     */
    public static function gerar(array $retrato): array
    {
        $tipos       = $retrato['tipos'] ?? [];
        $existentes  = $retrato['existentes'] ?? [];
        $descartadas = $retrato['descartadas'] ?? [];
        $limites     = $retrato['limites'] ?? ['max_titulo' => 60, 'max_sku' => 120];

        // Só variações com oferta simples ligada; ordem por id para ser estável.
        $produtos = [];
        foreach ($retrato['produtos'] ?? [] as $p) {
            $p['variacoes'] = array_values(array_filter(
                $p['variacoes'] ?? [],
                fn ($v) => ! empty($v['oferta_id'])
            ));
            if ($p['variacoes'] === []) {
                continue;
            }
            $produtos[] = $p;
        }
        usort($produtos, fn ($x, $y) => $x['id'] <=> $y['id']);

        $pares = self::indexarPares($retrato['pares'] ?? []);

        $saida = [];

        // ─── Combo: por produto, família pode ser nula ───
        foreach ($produtos as $p) {
            $tipo = $p['tipo'] ?? null;
            $qtds = Quantidades::efetivas($p['qtd_combo'] ?? null, $tipos[$tipo]['qtd_combo'] ?? []);
            foreach (self::variacoesOrdenadas($p['variacoes']) as $v) {
                foreach ($qtds as $q) {
                    if ($q < 2) {
                        continue;
                    }
                    $nomeado = NomesSugeridos::combo($p['nome'], $v['sku'], $v['valor'] ?? null, $q, self::tipoParaNome($tipos, $tipo));
                    $saida[] = self::montar(
                        EstruturaOferta::FASE_COMBO,
                        $p,
                        self::ambientesDe($p['ambientes'] ?? []),
                        $tipo !== null ? [$tipo] : [],
                        null,
                        [self::item($p, $v, $q, $tipo)],
                        $nomeado,
                        NomesSugeridos::porque(EstruturaOferta::FASE_COMBO, [
                            'quantidades'            => $qtds,
                            'quantidades_do_produto' => ($p['qtd_combo'] ?? null) !== null,
                            'tipo'                   => $tipos[$tipo]['nome'] ?? null,
                        ]),
                        $limites
                    );
                }
            }
        }

        // ─── Kit e Combit: pares de produtos dentro da família ───
        $porFamilia = [];
        foreach ($produtos as $p) {
            if (($p['familia_id'] ?? null) !== null && ($p['tipo'] ?? null) !== null) {
                $porFamilia[$p['familia_id']][] = $p;
            }
        }

        foreach ($porFamilia as $grupo) {
            $n = count($grupo);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    self::paraOPar($grupo[$i], $grupo[$j], $tipos, $pares, $limites, $saida);
                    for ($k = $j + 1; $k < $n; $k++) {
                        self::paraOTrio([$grupo[$i], $grupo[$j], $grupo[$k]], $tipos, $pares, $limites, $saida);
                    }
                }
            }
        }

        // ─── Duplicadas, existentes e descartadas ───
        $final = [];
        foreach ($saida as $s) {
            if (isset($existentes[$s['chave']]) || isset($final[$s['chave']])) {
                continue;
            }
            if (isset($descartadas[$s['chave']])) {
                $s['descartada']    = true;
                $s['descartada_em'] = (string) $descartadas[$s['chave']];
            }
            $final[$s['chave']] = $s;
        }

        return self::ordenar(array_values($final));
    }

    /** Kit e Combit de um par de produtos da mesma família. */
    private static function paraOPar(array $p, array $q, array $tipos, array $pares, array $limites, array &$saida): void
    {
        $comum = array_intersect_key($p['ambientes'] ?? [], $q['ambientes'] ?? []);
        if ($comum === []) {
            return;
        }

        $par = $pares[self::chaveDoPar($p['tipo'], $q['tipo'])] ?? null;
        if ($par === null) {
            return;
        }

        // Orienta pela ordem do tipo (mesa antes de cadeira); desempate por id.
        $x = $p;
        $y = $q;
        $ox = $tipos[$x['tipo']]['ordem'] ?? 0;
        $oy = $tipos[$y['tipo']]['ordem'] ?? 0;
        if ([$oy, $y['id']] < [$ox, $x['id']]) {
            [$x, $y] = [$y, $x];
        }

        $ambientes = self::ambientesDe($comum);
        $slugs     = [$x['tipo'], $y['tipo']];
        $parSaida  = ['a' => $par['a'], 'b' => $par['b'], 'repete' => $par['repete']];
        $familia   = $x['familia'] ?? null;

        // Quais lados se repetem no Combit (D-14).
        $repetidos = self::ladosQueRepetem($par, $x, $y);

        foreach (VariacoesEmParalelo::casar($x['variacoes'], $y['variacoes']) as [$vx, $vy]) {
            $nx = ['produto_nome' => $x['nome'], 'sku' => $vx['sku'], 'valor' => $vx['valor'] ?? null];
            $ny = ['produto_nome' => $y['nome'], 'sku' => $vy['sku'], 'valor' => $vy['valor'] ?? null];

            $saida[] = self::montar(
                EstruturaOferta::FASE_KIT,
                $x,
                $ambientes,
                $slugs,
                $parSaida,
                [self::item($x, $vx, 1, $x['tipo']), self::item($y, $vy, 1, $y['tipo'])],
                NomesSugeridos::kit($nx, $ny),
                NomesSugeridos::porque(EstruturaOferta::FASE_KIT, [
                    'familia'   => $familia,
                    'ambientes' => $ambientes,
                    'tipo_a'    => $tipos[$x['tipo']]['nome'] ?? $x['tipo'],
                    'tipo_b'    => $tipos[$y['tipo']]['nome'] ?? $y['tipo'],
                ]),
                $limites
            );

            foreach ($repetidos as $lado) {
                $rep  = $lado === 'x' ? $x : $y;
                $vrep = $lado === 'x' ? $vx : $vy;
                $fixo = $lado === 'x' ? $y : $x;
                $vfix = $lado === 'x' ? $vy : $vx;

                $qtds = Quantidades::efetivas($rep['qtd_combit'] ?? null, $tipos[$rep['tipo']]['qtd_combit'] ?? []);
                foreach ($qtds as $n) {
                    if ($n < 2) {
                        continue;
                    }
                    $nfixo = ['produto_nome' => $fixo['nome'], 'sku' => $vfix['sku'], 'valor' => $vfix['valor'] ?? null];
                    $nrep  = ['produto_nome' => $rep['nome'], 'sku' => $vrep['sku'], 'valor' => $vrep['valor'] ?? null];
                    $tipoRep = self::tipoParaNome($tipos, $rep['tipo']) ?? ['nome' => (string) $rep['tipo'], 'plural' => (string) $rep['tipo']];

                    // Itens na ordem do par (x, y) para a lista ficar legível.
                    $itens = $lado === 'x'
                        ? [self::item($x, $vx, $n, $x['tipo']), self::item($y, $vy, 1, $y['tipo'])]
                        : [self::item($x, $vx, 1, $x['tipo']), self::item($y, $vy, $n, $y['tipo'])];

                    $saida[] = self::montar(
                        EstruturaOferta::FASE_COMBIT,
                        $x,
                        $ambientes,
                        $slugs,
                        $parSaida,
                        $itens,
                        NomesSugeridos::combit($nfixo, $nrep, $n, $tipoRep),
                        NomesSugeridos::porque(EstruturaOferta::FASE_COMBIT, [
                            'familia'   => $familia,
                            'ambientes' => $ambientes,
                            'tipo_a'    => $tipos[$x['tipo']]['nome'] ?? $x['tipo'],
                            'tipo_b'    => $tipos[$y['tipo']]['nome'] ?? $y['tipo'],
                            'repete'    => $tipos[$rep['tipo']]['nome'] ?? $rep['tipo'],
                        ]),
                        $limites
                    );
                }
            }
        }
    }

    /**
     * Kit de 3 produtos: tipos distintos, os três pares na lista e ambiente em comum
     * aos três. As variações casam pela âncora (o produto com mais variações): cada
     * variação dela casa em paralelo com cada um dos outros dois, e só sai o kit em
     * que os dois casaram. Assim cada variação da âncora aparece uma vez, nunca cartesiano.
     *
     * @param  array{0: array, 1: array, 2: array}  $trio
     */
    private static function paraOTrio(array $trio, array $tipos, array $pares, array $limites, array &$saida): void
    {
        $slugs = array_column($trio, 'tipo');
        if (count(array_unique($slugs)) !== 3) {
            return;
        }

        foreach ([[0, 1], [0, 2], [1, 2]] as [$a, $b]) {
            if (! isset($pares[self::chaveDoPar($slugs[$a], $slugs[$b])])) {
                return;
            }
        }

        $comum = array_intersect_key($trio[0]['ambientes'] ?? [], $trio[1]['ambientes'] ?? [], $trio[2]['ambientes'] ?? []);
        if ($comum === []) {
            return;
        }

        // Orienta pela ordem do tipo (gabinete, espelho, lixeira); desempate por id.
        usort($trio, fn ($x, $y) => [$tipos[$x['tipo']]['ordem'] ?? 0, $x['id']] <=> [$tipos[$y['tipo']]['ordem'] ?? 0, $y['id']]);

        // Âncora: mais variações; empate, a primeira na orientação.
        $ancora = 0;
        foreach ($trio as $pos => $p) {
            if (count($p['variacoes']) > count($trio[$ancora]['variacoes'])) {
                $ancora = $pos;
            }
        }

        $casadas = [];
        foreach ($trio as $pos => $p) {
            if ($pos === $ancora) {
                continue;
            }
            foreach (VariacoesEmParalelo::casar($trio[$ancora]['variacoes'], $p['variacoes']) as [$va, $vp]) {
                $casadas[$pos][$va['id']] ??= $vp;
            }
        }

        $ambientes = self::ambientesDe($comum);
        $slugs     = array_column($trio, 'tipo');
        $nomesTipo = array_map(fn ($p) => $tipos[$p['tipo']]['nome'] ?? $p['tipo'], $trio);

        foreach (self::variacoesOrdenadas($trio[$ancora]['variacoes']) as $va) {
            $escolhidas = [];
            foreach ($trio as $pos => $p) {
                $v = $pos === $ancora ? $va : ($casadas[$pos][$va['id']] ?? null);
                if ($v === null) {
                    continue 2;
                }
                $escolhidas[$pos] = $v;
            }

            $itens = [];
            $paraNome = [];
            foreach ($trio as $pos => $p) {
                $v = $escolhidas[$pos];
                $itens[] = self::item($p, $v, 1, $p['tipo']);
                $paraNome[] = ['produto_nome' => $p['nome'], 'sku' => $v['sku'], 'valor' => $v['valor'] ?? null];
            }

            $saida[] = self::montar(
                EstruturaOferta::FASE_KIT,
                $trio[0],
                $ambientes,
                $slugs,
                null,
                $itens,
                NomesSugeridos::kitDeVarios($paraNome),
                NomesSugeridos::porque(EstruturaOferta::FASE_KIT, [
                    'familia'   => $trio[0]['familia'] ?? null,
                    'ambientes' => $ambientes,
                    'tipos'     => $nomesTipo,
                ]),
                $limites
            );
        }
    }

    /**
     * 'x' / 'y' para cada lado que se repete. Par sem direção (repete nulo) =
     * só Kit. Mesmo tipo dos dois lados com repete não nulo = os dois lados.
     *
     * @return list<string>
     */
    private static function ladosQueRepetem(array $par, array $x, array $y): array
    {
        $repete = $par['repete'] ?? null;
        if ($repete === null || $repete === '') {
            return [];
        }
        if ($x['tipo'] === $y['tipo'] || $repete === 'ambos') {
            return ['x', 'y'];
        }

        $slugRepetido = $repete === 'a' ? $par['a'] : $par['b'];

        return $x['tipo'] === $slugRepetido ? ['x'] : ['y'];
    }

    /** @return array<string,array{a:string,b:string,repete:?string}> */
    private static function indexarPares(array $pares): array
    {
        $indice = [];
        foreach ($pares as $par) {
            $indice[self::chaveDoPar($par['a'], $par['b'])] ??= [
                'a'      => $par['a'],
                'b'      => $par['b'],
                'repete' => $par['repete'] ?? null,
            ];
        }

        return $indice;
    }

    private static function chaveDoPar(string $a, string $b): string
    {
        $slugs = [$a, $b];
        sort($slugs);

        return implode('|', $slugs);
    }

    /** @return list<string> nomes dos ambientes em ordem alfabética */
    private static function ambientesDe(array $ambientes): array
    {
        $nomes = array_values(array_map('strval', $ambientes));
        sort($nomes, SORT_STRING | SORT_FLAG_CASE);

        return $nomes;
    }

    /** @return array{nome:string,plural:string}|null */
    private static function tipoParaNome(array $tipos, ?string $slug): ?array
    {
        if ($slug === null || ! isset($tipos[$slug])) {
            return null;
        }

        return ['nome' => $tipos[$slug]['nome'], 'plural' => $tipos[$slug]['plural']];
    }

    private static function variacoesOrdenadas(array $variacoes): array
    {
        usort($variacoes, fn ($x, $y) => [$x['ordem'] ?? 0, $x['id']] <=> [$y['ordem'] ?? 0, $y['id']]);

        return $variacoes;
    }

    private static function item(array $produto, array $variacao, int $quantidade, ?string $tipo): array
    {
        return [
            'variacao_id'  => (int) $variacao['id'],
            'oferta_id'    => (int) $variacao['oferta_id'],
            'produto_id'   => (int) $produto['id'],
            'produto_nome' => $produto['nome'],
            'valor'        => $variacao['valor'] ?? null,
            'sku'          => $variacao['sku'],
            'quantidade'   => $quantidade,
            'tipo'         => $tipo,
        ];
    }

    /** @param array{nome:string,sku:string} $nomeado */
    private static function montar(string $fase, array $produto, array $ambientes, array $tipos, ?array $par, array $itens, array $nomeado, string $porque, array $limites): array
    {
        $mapa = [];
        foreach ($itens as $i) {
            $mapa[$i['variacao_id']] = $i['quantidade'];
        }

        return [
            'chave'         => ChaveDeComposicao::de($mapa),
            'fase'          => $fase,
            'familia_id'    => $produto['familia_id'] ?? null,
            'familia'       => $produto['familia'] ?? null,
            'ambientes'     => $ambientes,
            'tipos'         => $tipos,
            'par'           => $par,
            'itens'         => $itens,
            'nome'          => $nomeado['nome'],
            'sku'           => $nomeado['sku'],
            'avisos'        => NomesSugeridos::avisos($nomeado['nome'], $nomeado['sku'], $limites),
            'porque'        => $porque,
            'descartada'    => false,
            'descartada_em' => null,
        ];
    }

    /** Família pelo nome normalizado (desempate id), nula por último; depois fase e chave. */
    private static function ordenar(array $lista): array
    {
        usort($lista, function ($a, $b) {
            $fa = $a['familia_id'];
            $fb = $b['familia_id'];
            if (($fa === null) !== ($fb === null)) {
                return $fa === null ? 1 : -1;
            }

            return [TipoDoProduto::normalizar($a['familia']), (int) $fa, self::ORDEM_FASE[$a['fase']], $a['chave']]
                <=> [TipoDoProduto::normalizar($b['familia']), (int) $fb, self::ORDEM_FASE[$b['fase']], $b['chave']];
        });

        return $lista;
    }
}
