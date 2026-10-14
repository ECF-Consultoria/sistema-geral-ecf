<?php

namespace App\Services\Bling;

use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;

/**
 * Normaliza o saldo de estoque do Bling (`GET /estoques/saldos`) para o inteiro que o Portal grava
 * em `estrutura_produto_variacoes.estoque`. Função pura: **sem** banco e **sem** HTTP — recebe os
 * arrays já lidos pelo cliente HTTP (plano 06); quem grava é um caminho próprio do
 * `ProdutoCadastroService` (plano 09), não esta classe.
 *
 * Suposições NÃO medidas do RESEARCH §5 — cada uma com o fallback que este normalizador escolheu,
 * nunca como fato:
 * - **suposição 3** (físico × virtual): o padrão desta classe é o **virtual** (`saldoVirtualTotal`),
 *   porque é o físico menos o reservado. Se a medição do checkpoint humano (plano 12) mostrar que o
 *   virtual já desconta o que não deveria, a troca é um valor na coluna `bling_conexoes.saldo`, sem
 *   deploy — por isso a preferência entra como parâmetro, nunca uma constante fixa aqui.
 * - **suposição 6** (`filtroSaldoEstoque` omitido traz só os positivos): este normalizador **não
 *   depende** dessa suposição — produto ausente da resposta devolve `null`, nunca `0`, qualquer que
 *   seja o motivo da ausência.
 * - **suposição 2** (qual saldo vem ao consultar o pai de um produto com variação): o pai do Bling
 *   não tem estoque (seed §4); o plano 09 só pede saldo dos ids de **variação**. Este docblock
 *   registra que essa leitura espera confirmação do checkpoint humano, não é tratada aqui.
 */
final class SaldoDoBling
{
    public const PREFERENCIA_VIRTUAL = 'virtual';

    public const PREFERENCIA_FISICO = 'fisico';

    /**
     * `$saldoDoProduto` é o item de `data[]` de `/estoques/saldos` para UM produto — `[]` quando o
     * produto não apareceu na resposta (ausência ≠ zero, T-184-27).
     *
     * @param  array<string, mixed>  $saldoDoProduto
     * @param  list<int>  $idsAIgnorar  ids de `depositosParaIgnorar()`, descontados quando
     *                                  `$depositosEscolhidos` é nulo/vazio (a conexão usa o total)
     * @return array{estoque: ?int, avisos: list<string>}
     */
    public static function normalizar(array $saldoDoProduto, ?string $depositosEscolhidos, string $preferencia, array $idsAIgnorar = []): array
    {
        $avisos = [];

        if (! in_array($preferencia, [self::PREFERENCIA_VIRTUAL, self::PREFERENCIA_FISICO], true)) {
            $avisos[] = "Preferência de saldo \"{$preferencia}\" desconhecida para esta conexão: usamos \"".self::PREFERENCIA_VIRTUAL.'".';
            $preferencia = self::PREFERENCIA_VIRTUAL;
        }

        if ($saldoDoProduto === []) {
            // Produto ausente da resposta de /estoques/saldos: NULL = "não informado", NUNCA 0 — um
            // zero aqui tiraria o anúncio do ar por um dado que ninguém digitou (T-184-27).
            return ['estoque' => null, 'avisos' => $avisos];
        }

        $idsEscolhidos = self::idsEscolhidos($depositosEscolhidos);
        $depositos     = $saldoDoProduto['depositos'] ?? [];
        if (! is_array($depositos)) {
            $depositos = [];
        }

        $campoDeposito = $preferencia === self::PREFERENCIA_FISICO ? 'saldoFisico' : 'saldoVirtual';

        if ($idsEscolhidos === null) {
            // Conexão sem depósitos específicos: usa o total do Bling, descontando os depósitos que
            // a empresa marcou para ignorar (desconsiderarSaldo) em /depositos.
            $campoTotal = $preferencia === self::PREFERENCIA_FISICO ? 'saldoFisicoTotal' : 'saldoVirtualTotal';
            $total      = $saldoDoProduto[$campoTotal] ?? null;

            if ($total === null || ! is_numeric($total)) {
                return ['estoque' => null, 'avisos' => $avisos];
            }

            $total = (float) $total;
            if ($idsAIgnorar !== []) {
                foreach ($depositos as $deposito) {
                    if (in_array($deposito['id'] ?? null, $idsAIgnorar, true)) {
                        $total -= (float) ($deposito[$campoDeposito] ?? 0);
                    }
                }
            }

            return self::finalizar($total, $avisos);
        }

        // Conexão com depósitos escolhidos: soma SÓ eles, ignorando os demais — mesmo que algum
        // deles esteja também em $idsAIgnorar (a escolha explícita da conexão vence o padrão).
        $soma            = 0.0;
        $algumEncontrado = false;

        foreach ($depositos as $deposito) {
            if (in_array($deposito['id'] ?? null, $idsEscolhidos, true)) {
                $algumEncontrado = true;
                $soma += (float) ($deposito[$campoDeposito] ?? 0);
            }
        }

        if (! $algumEncontrado) {
            $avisos[] = 'Nenhum dos depósitos escolhidos para esta conexão apareceu na resposta do Bling.';

            return ['estoque' => null, 'avisos' => $avisos];
        }

        return self::finalizar($soma, $avisos);
    }

    /**
     * `bling_conexoes.depositos`: texto com ids separados por vírgula, ou `null`/vazio = "usar o
     * total". `@return` `null` significa "sem escolha específica"; `list<int>` vazia não existe
     * (texto vazio já cai no `null`).
     *
     * @return ?list<int>
     */
    private static function idsEscolhidos(?string $depositosEscolhidos): ?array
    {
        $texto = trim((string) $depositosEscolhidos);
        if ($texto === '') {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (string $id): int => (int) trim($id), explode(',', $texto)),
            static fn (int $id): bool => $id > 0
        ));
    }

    /**
     * Piso em zero (nunca negativo), piso na casa inteira (nunca arredondamento: vender o que não
     * tem é pior que deixar de vender uma unidade) e teto em `NormalizadorDeLinha::MAX_ESTOQUE`.
     *
     * @return array{estoque: ?int, avisos: list<string>}
     */
    private static function finalizar(int|float $total, array $avisos): array
    {
        if ($total < 0) {
            // Saldo negativo do Bling NUNCA grava negativo no Portal (T-184-28).
            $total = 0;
        }

        $inteiro = (int) floor($total);

        if ($inteiro > NormalizadorDeLinha::MAX_ESTOQUE) {
            $avisos[] = 'Saldo do Bling acima do teto aceito pelo Portal: gravamos o teto.';
            $inteiro = NormalizadorDeLinha::MAX_ESTOQUE;
        }

        return ['estoque' => $inteiro, 'avisos' => $avisos];
    }

    /**
     * Ids de depósito com `desconsiderarSaldo` marcado, de `GET /depositos`. Esses depósitos ficam
     * fora da soma quando a conexão não escolheu depósitos específicos (`idsEscolhidos() === null`).
     *
     * @param  array<int, array{id?: mixed, desconsiderarSaldo?: mixed}>  $respostaDeDepositos  `data[]` de `/depositos`
     * @return list<int>
     */
    public static function depositosParaIgnorar(array $respostaDeDepositos): array
    {
        $ids = [];
        foreach ($respostaDeDepositos as $deposito) {
            if (($deposito['desconsiderarSaldo'] ?? false) === true && isset($deposito['id'])) {
                $ids[] = (int) $deposito['id'];
            }
        }

        return $ids;
    }
}
