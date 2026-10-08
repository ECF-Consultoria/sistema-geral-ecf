<?php

namespace App\Support\Publicador\Portal;

use App\Services\Portal\Estrutura\Geracao\ConjuntoLogistico;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;

/**
 * Contas de composição de Combo/Kit/Combit para o rascunho do Publicador (172, D-07/D-12).
 * Puro: sem banco, sem HTTP. A soma de pacote é a MESMA da 168 (reuso, nada reimplementado).
 */
final class ComposicaoDoPortal
{
    /**
     * Estoque do conjunto (D-07): menor piso(estoque ÷ quantidade) entre os componentes.
     * Combo (1 componente, N unidades) cai no mesmo caso. Estoque desconhecido em qualquer
     * componente = desconhecido (null) — nunca inventa estoque.
     *
     * @param  list<array{estoque: ?int, quantidade: int}>  $itens
     */
    public static function estoque(array $itens): ?int
    {
        if ($itens === []) {
            return null;
        }

        $menor = null;
        foreach ($itens as $item) {
            if ($item['estoque'] === null) {
                return null;
            }
            $quantidade = $item['quantidade'] > 0 ? $item['quantidade'] : 1;
            $conta = intdiv(max(0, (int) $item['estoque']), $quantidade);
            $menor = $menor === null ? $conta : min($menor, $conta);
        }

        return $menor;
    }

    /**
     * Índice do componente PRINCIPAL (D-12): o lado que NÃO repete no par de tipo.
     * Exemplo: mesa (1) + cadeira (4) com par {a: cadeira, b: mesa, repete: 'a'} -> a mesa.
     * Sem par conhecido, 'ambos' ou empate: o de maior custo × quantidade; empate -> menor índice.
     *
     * @param  list<array{tipo: ?string, quantidade: int, custo: ?float}>  $itens
     * @param  list<array{a: string, b: string, repete: ?string}>  $pares
     */
    public static function principal(array $itens, array $pares): int
    {
        $repetido = [];
        $emPar = [];

        foreach ($itens as $i => $item) {
            foreach ($itens as $j => $outro) {
                if ($i === $j || $item['tipo'] === null || $outro['tipo'] === null || $item['tipo'] === $outro['tipo']) {
                    continue;
                }
                foreach ($pares as $par) {
                    $repete = $par['repete'] ?? null;
                    if ($repete !== 'a' && $repete !== 'b') {
                        continue;
                    }
                    // O par é guardado sem ordem: olha nos dois sentidos.
                    if ($par['a'] === $item['tipo'] && $par['b'] === $outro['tipo']) {
                        $ladoDeI = 'a';
                    } elseif ($par['b'] === $item['tipo'] && $par['a'] === $outro['tipo']) {
                        $ladoDeI = 'b';
                    } else {
                        continue;
                    }
                    $emPar[$i] = true;
                    if ($repete === $ladoDeI) {
                        $repetido[$i] = true;
                    }
                }
            }
        }

        $candidatos = array_values(array_filter(array_keys($itens), fn ($i) => isset($emPar[$i]) && ! isset($repetido[$i])));
        if (count($candidatos) === 1) {
            return $candidatos[0];
        }
        if ($candidatos === []) {
            $candidatos = array_keys($itens);
        }

        $melhor = null;
        $melhorValor = null;
        foreach ($candidatos as $i) {
            $valor = ((float) ($itens[$i]['custo'] ?? 0)) * max(1, (int) $itens[$i]['quantidade']);
            if ($melhorValor === null || $valor > $melhorValor) {
                $melhor = $i;
                $melhorValor = $valor;
            }
        }

        return $melhor ?? 0;
    }

    /**
     * Pacote de um grupo de variações: iguais -> o primeiro; diferentes -> o de maior peso, divergem = true.
     *
     * @param  list<?array{c: float, l: float, a: float, peso_real: float}>  $pacotes
     * @return array{pacote: ?array, divergem: bool}
     */
    public static function pacoteDoGrupo(array $pacotes): array
    {
        $validos = array_values(array_filter($pacotes, fn ($p) => is_array($p)));
        if ($validos === []) {
            return ['pacote' => null, 'divergem' => false];
        }

        $maior = $validos[0];
        $divergem = false;
        foreach ($validos as $p) {
            if ($p != $validos[0]) {
                $divergem = true;
            }
            if ($p['peso_real'] > $maior['peso_real']) {
                $maior = $p;
            }
        }

        return ['pacote' => $divergem ? $maior : $validos[0], 'divergem' => $divergem];
    }

    /**
     * Pacote de um conjunto: a mesma conta que a 168 mostra ao cliente. Item sem volumes = null.
     *
     * @param  list<array{produto_id: int, produto_nome: string, quantidade: int, volumes: list<array>, custo: ?float}>  $itens
     */
    public static function pacoteDoConjunto(array $itens): ?array
    {
        if ($itens === []) {
            return null;
        }
        foreach ($itens as $item) {
            if ($item['volumes'] === []) {
                return null;
            }
        }

        return LogisticaProduto::pacote(ConjuntoLogistico::volumes($itens));
    }

    /**
     * Descrições das partes em um texto: "Nome: texto", separadas por linha em branco; só as não vazias.
     *
     * @param  list<array{nome: string, descricao: ?string}>  $partes
     */
    public static function descricoes(array $partes): ?string
    {
        $linhas = [];
        foreach ($partes as $parte) {
            $texto = trim((string) ($parte['descricao'] ?? ''));
            if ($texto !== '') {
                $linhas[] = trim($parte['nome']).': '.$texto;
            }
        }

        return $linhas === [] ? null : implode("\n\n", $linhas);
    }
}
