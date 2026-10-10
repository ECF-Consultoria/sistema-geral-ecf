<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\EstruturaOferta;

/**
 * As regras do "Montar kit" do Planejamento (09/10/2026), em funções puras: sem banco,
 * sem config e sem relógio. Quem lê o banco e grava é a {@see MontagemManualDeOferta}.
 *
 * - A fase sai da composição pela MESMA regra da Lista SKUs
 *   (`EstruturaOfertaService::composicao`): 1 item com 2+ unidades é Combo; 2+ itens
 *   todos ×1 é Kit; 2+ itens com algum ×2+ é Combit. A gravação passa por aquela regra
 *   de novo — se as duas um dia divergirem, a criação recusa (rede de segurança) e o
 *   teste de consistência acusa.
 * - Nome e SKU sugeridos seguem o padrão do Planejamento ({@see NomesSugeridos}), o mesmo
 *   das sugestões: a oferta montada à mão fica com o mesmo nome da sugerida.
 * - "Terá estoque?" é o menor ⌊estoque ÷ quantidade⌋ entre os componentes, com o
 *   estoque do Portal (por variação).
 */
final class RegrasDaMontagem
{
    /**
     * A fase da composição, ou null quando ela ainda não é uma oferta composta (nenhum
     * item, ou um item só com 1 unidade — isso é a própria oferta simples).
     *
     * @param  list<int>  $quantidades  uma por item (itens distintos)
     */
    public static function fase(array $quantidades): ?string
    {
        $n = count($quantidades);
        if ($n === 0) {
            return null;
        }
        $algumMaiorQueUm = max($quantidades) >= 2;

        if ($n === 1) {
            return $algumMaiorQueUm ? EstruturaOferta::FASE_COMBO : null;
        }

        return $algumMaiorQueUm ? EstruturaOferta::FASE_COMBIT : EstruturaOferta::FASE_KIT;
    }

    /** O que falta para a composição virar oferta (null = já é Combo, Kit ou Combit). */
    public static function mensagem(array $quantidades): ?string
    {
        if ($quantidades === []) {
            return 'Escolha os produtos que entram juntos.';
        }
        if (self::fase($quantidades) === null) {
            return 'Com um produto só, aumente a quantidade (vira um Combo) ou escolha mais um produto (vira um Kit).';
        }

        return null;
    }

    /**
     * Ordem dos itens no nome sugerido: a mesma orientação do gerador (pela ordem do tipo,
     * mesa antes de cadeira; desempate pelo produto), com os itens sem tipo no fim e, entre
     * eles, a ordem em que a pessoa escolheu.
     *
     * @param  list<array{tipo_ordem?: ?int, produto_id?: ?int}>  $itens
     * @return list<array>
     */
    public static function ordenar(array $itens): array
    {
        $comPosicao = [];
        foreach (array_values($itens) as $pos => $item) {
            $comPosicao[] = [$item, $pos];
        }

        usort($comPosicao, fn ($x, $y) => [
            $x[0]['tipo_ordem'] ?? PHP_INT_MAX, $x[0]['produto_id'] ?? PHP_INT_MAX, $x[1],
        ] <=> [
            $y[0]['tipo_ordem'] ?? PHP_INT_MAX, $y[0]['produto_id'] ?? PHP_INT_MAX, $y[1],
        ]);

        return array_map(fn ($p) => $p[0], $comPosicao);
    }

    /**
     * Nome e SKU sugeridos para a composição, pelo padrão do Planejamento.
     *
     * @param  list<array{produto_nome: string, sku: string, valor: ?string, quantidade: int, tipo_nome?: ?string, tipo_plural?: ?string}>  $itens  já ordenados
     * @return array{nome: string, sku: string}|null  null quando a composição não tem fase
     */
    public static function nomeado(array $itens): ?array
    {
        $fase = self::fase(array_map(fn ($i) => (int) $i['quantidade'], $itens));
        $paraNome = fn (array $i) => ['produto_nome' => $i['produto_nome'], 'sku' => $i['sku'], 'valor' => $i['valor'] ?? null];
        $tipo = fn (array $i) => ($i['tipo_nome'] ?? null) !== null
            ? ['nome' => (string) $i['tipo_nome'], 'plural' => (string) ($i['tipo_plural'] ?? $i['tipo_nome'])]
            : null;

        if ($fase === EstruturaOferta::FASE_COMBO) {
            $i = $itens[0];

            return NomesSugeridos::combo($i['produto_nome'], $i['sku'], $i['valor'] ?? null, (int) $i['quantidade'], $tipo($i));
        }

        if ($fase === EstruturaOferta::FASE_KIT) {
            return count($itens) === 2
                ? NomesSugeridos::kit($paraNome($itens[0]), $paraNome($itens[1]))
                : NomesSugeridos::kitDeVarios(array_map($paraNome, $itens));
        }

        if ($fase === EstruturaOferta::FASE_COMBIT) {
            $repetidos = array_values(array_filter($itens, fn ($i) => (int) $i['quantidade'] >= 2));
            if (count($itens) === 2 && count($repetidos) === 1) {
                $rep  = $repetidos[0];
                $fixo = (int) $itens[0]['quantidade'] >= 2 ? $itens[1] : $itens[0];

                return NomesSugeridos::combit($paraNome($fixo), $paraNome($rep), (int) $rep['quantidade'],
                    $tipo($rep) ?? ['nome' => $rep['produto_nome'], 'plural' => $rep['produto_nome']]);
            }

            return NomesSugeridos::combitDeVarios(array_map(fn ($i) => $paraNome($i) + [
                'quantidade' => (int) $i['quantidade'],
                'plural'     => $tipo($i)['plural'] ?? null,
            ], $itens));
        }

        return null;
    }

    /**
     * "Terá estoque?": quantas unidades da oferta o estoque de hoje monta — o menor
     * ⌊estoque ÷ quantidade⌋ entre os componentes. Um componente com estoque 0 (ou menor
     * que a quantidade) já decide: 0, mesmo que outro não tenha estoque informado. Sem
     * esse caso, qualquer estoque não informado deixa a resposta em aberto (null).
     *
     * @param  list<array{nome: string, quantidade: int, estoque: ?int}>  $itens
     * @return array{unidades: ?int, limitante: ?array{nome: string, estoque: int, por_unidade: int}, sem_informacao: list<string>}
     */
    public static function estoque(array $itens): array
    {
        $menor = null;
        $limitante = null;
        $semInformacao = [];

        foreach ($itens as $i) {
            $q = max(1, (int) $i['quantidade']);
            if ($i['estoque'] === null) {
                $semInformacao[] = (string) $i['nome'];
                continue;
            }
            $monta = intdiv(max(0, (int) $i['estoque']), $q);
            if ($menor === null || $monta < $menor) {
                $menor = $monta;
                $limitante = ['nome' => (string) $i['nome'], 'estoque' => (int) $i['estoque'], 'por_unidade' => $q];
            }
        }

        if ($itens === [] || ($semInformacao !== [] && $menor !== 0)) {
            return ['unidades' => null, 'limitante' => null, 'sem_informacao' => $semInformacao];
        }

        return ['unidades' => $menor, 'limitante' => $limitante, 'sem_informacao' => $semInformacao];
    }
}
