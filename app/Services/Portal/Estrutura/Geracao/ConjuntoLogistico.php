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
 * Nada daqui é gravado. Desde 09/10/2026 (D-19 revogada) o pacote somado também é o
 * da oferta composta na Precificação, para o frete sugerido — provisório por decisão
 * do usuário ("por enquanto deixa somando").
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
     * @param  ?string  $modalidade  modalidade de envio da conta (limites do ME2); null = a padrão
     * @return array<string, mixed>  retorno de LogisticaProduto::daVolumes + sem_medida
     */
    public static function avaliar(array $itens, ?string $modalidade = null): array
    {
        $semMedida = self::semMedida($itens);

        $base = $semMedida === []
            ? LogisticaProduto::daVolumes(self::volumes($itens), $modalidade)
            : LogisticaProduto::avaliar(null);

        return $base + ['sem_medida' => $semMedida];
    }

    /**
     * Componentes sem nenhuma medida. Fonte ÚNICA do "sem medida": a logística da
     * página e o filtro Status (D-28) usam esta função.
     *
     * @param  list<array{produto_id: int, produto_nome: string, volumes: list<array>}>  $itens
     * @return list<array{id: int, nome: string}>
     */
    public static function semMedida(array $itens): array
    {
        $semMedida = [];

        foreach ($itens as $item) {
            if ($item['volumes'] === []) {
                $semMedida[] = ['id' => $item['produto_id'], 'nome' => $item['produto_nome']];
            }
        }

        return $semMedida;
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
