<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;

/**
 * Logística provável e custo de um conjunto (Kit/Combit/Combo) — D-08.
 *
 * Só empilha os volumes de cada unidade e delega a conta a
 * LogisticaProduto::daVolumes (única implementação; nada reimplementado aqui).
 * Medido 194/194 classes iguais às digitadas pela equipe; a altura pode ser
 * superestimada em conjuntos grandes, por isso a tela diz "estimado".
 * Nada daqui é gravado como preço (D-19 da 167).
 */
final class ConjuntoLogistico
{
    /**
     * Cada UNIDADE leva os próprios volumes.
     *
     * @param  list<array{produto_id: int, produto_nome: string, quantidade: int, volumes: list<array{c: float|int, l: float|int, a: float|int, kg: float|int}>, custo: ?float}>  $itens
     * @return list<array{c: float|int, l: float|int, a: float|int, kg: float|int}>
     */
    public static function volumes(array $itens): array
    {
        $saida = [];

        foreach ($itens as $item) {
            for ($i = 0; $i < $item['quantidade']; $i++) {
                foreach ($item['volumes'] as $volume) {
                    $saida[] = $volume;
                }
            }
        }

        return $saida;
    }

    /**
     * @param  list<array{produto_id: int, produto_nome: string, quantidade: int, volumes: list<array>, custo: ?float}>  $itens
     * @return array<string, mixed>  retorno de LogisticaProduto::daVolumes + sem_medida
     */
    public static function avaliar(array $itens): array
    {
        $semMedida = [];

        foreach ($itens as $item) {
            if ($item['volumes'] === []) {
                $semMedida[] = ['id' => $item['produto_id'], 'nome' => $item['produto_nome']];
            }
        }

        $base = $semMedida === []
            ? LogisticaProduto::daVolumes(self::volumes($itens))
            : LogisticaProduto::avaliar(null);

        return $base + ['sem_medida' => $semMedida];
    }

    /** Σ quantidade × custo (2 casas); null se algum custo faltar. */
    public static function custo(array $itens): ?float
    {
        $total = 0.0;

        foreach ($itens as $item) {
            if ($item['custo'] === null) {
                return null;
            }
            $total += $item['quantidade'] * $item['custo'];
        }

        return round($total, 2);
    }
}
