<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\RegraViolada;

/**
 * D-10 — atacado em % B2B (`pxq-porcentagem-b2b`, atualizada 01/10/2026). O endpoint absoluto de PxQ é
 * descontinuado para B2B em 27/10/2026 e NUNCA é chamado aqui. As recomendações são um POST na conta do
 * cliente: passam pelo EscritorAlavancas::consultaPorPost, sob a trava das Alavancas (D-03 literal; mesmo
 * critério do D26 da 164 para o /items/validate).
 *
 * Toda chamada direta daqui é GET e NUNCA usa cache: a versão do preço muda a cada escrita.
 */
class AtacadoLeitura
{
    private const AVISO_SEM_FAIXAS = 'O Mercado Livre não devolveu as faixas atuais deste anúncio; confira no Mercado Livre antes de gravar.';

    public function __construct(
        private ClienteMlPublicador $cliente,
        private LeitorContaAlavancas $conta,
        private EscritorAlavancas $escritor,
    ) {}

    /** A conta tem a tag `business` (o ML libera o preço por quantidade por convite). */
    public function habilitado(ContaAlavanca $c, bool $atualizar = false): bool
    {
        return (bool) $this->conta->ler($c, $atualizar)['business'];
    }

    /**
     * Faixas atuais do anúncio, com a versão do preço.
     *
     * @return array{versao: ?string, preco_padrao: ?float, faixas: ?list<array{id: string, percentual: float, quantidade_minima: int}>, tem_faixas: bool, tem_absoluto: bool, aviso: ?string}
     */
    public function faixas(ContaAlavanca $c, string $itemId): array
    {
        $this->itemValido($itemId);

        $r = $this->cliente->daConta($c->conta, 'GET', '/items/'.rawurlencode($itemId).'/prices',
            ['display_version' => 'true'], null, true, ['show-all-prices' => 'true']);
        if (! $r->ok() || ! is_array($r->corpo)) {
            throw new \RuntimeException("[Alavancas] preços de {$itemId} falharam: HTTP {$r->status}");
        }
        $corpo = $r->corpo;

        // `version` na raiz da resposta (doc: "Identificar versão de preços").
        $versao = isset($corpo['version']) && $corpo['version'] !== '' ? (string) $corpo['version'] : null;
        $padrao = null;
        $absoluto = false;
        foreach ((array) ($corpo['prices'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $minimo = (int) ($p['conditions']['min_purchase_unit'] ?? 0);
            // [ASSUMED A1] a doc não mostra o PxQ absoluto no GET: entrada de prices[] com quantidade mínima > 1 e sem percentual.
            if ($minimo > 1 && ! isset($p['percentage'])) {
                $absoluto = true;

                continue;
            }
            if (($p['type'] ?? null) === 'standard' && $padrao === null && isset($p['amount'])) {
                $padrao = (float) $p['amount'];
            }
        }

        if (array_key_exists('price_per_quantity', $corpo)) {
            $faixas = $this->normalizar((array) $corpo['price_per_quantity']);

            return ['versao' => $versao, 'preco_padrao' => $padrao, 'faixas' => $faixas, 'tem_faixas' => $faixas !== [],
                'tem_absoluto' => $absoluto, 'aviso' => null];
        }

        // Reserva: sem as faixas na resposta, a tag do anúncio ao menos diz se existem.
        $item = $this->cliente->daConta($c->conta, 'GET', '/items/'.rawurlencode($itemId));
        $tags = $item->ok() && is_array($item->corpo) ? (array) ($item->corpo['tags'] ?? []) : [];
        if (in_array('standard_price_by_quantity', $tags, true)) {
            return ['versao' => $versao, 'preco_padrao' => $padrao, 'faixas' => null, 'tem_faixas' => true,
                'tem_absoluto' => $absoluto, 'aviso' => self::AVISO_SEM_FAIXAS];
        }

        return ['versao' => $versao, 'preco_padrao' => $padrao, 'faixas' => [], 'tem_faixas' => false,
            'tem_absoluto' => $absoluto, 'aviso' => null];
    }

    /**
     * Recomendações de faixa do ML (POST de consulta, não altera nada).
     *
     * @param  list<int|float>  $quantidades
     * @return array{recomendacoes: list<array{quantidade: int, valor: ?float, percentual: ?float, incoerente: bool, lucro: ?float, frete: ?float}>, sem_recomendacao: bool}
     */
    public function recomendacoes(ContaAlavanca $c, string $itemId, array $quantidades, float $preco): array
    {
        // A trava é a primeira linha: conta não liberada não faz nenhuma chamada, nem o GET de /users/me.
        AlavancasLiberadas::exigir($c->conta);
        $this->itemValido($itemId);

        $q = array_values($quantidades);
        if ($q === [] || count($q) > 5 || count(array_unique($q)) !== count($q)) {
            throw new RegraViolada('ALAV-B2B-01', 'Peça de 1 a 5 quantidades diferentes para a recomendação.');
        }
        foreach ($q as $n) {
            if (! is_int($n) || $n < 1 || $n > 100) {
                throw new RegraViolada('ALAV-B2B-01', 'Cada quantidade é um número inteiro de 1 a 100.');
            }
        }

        if (! $this->habilitado($c)) {
            throw new RegraViolada('ALAV-B2B-02', 'Esta conta não tem o preço por quantidade liberado pelo Mercado Livre (o ML libera por convite).');
        }

        $r = $this->escritor->consultaPorPost($c, '/prices-per-quantity/v1/recommendations', [
            'item_id' => $itemId,
            'range_item_quantities' => $q,
            'price' => ['standard_amount' => $preco, 'currency' => 'BRL'],
        ]);

        if ($r->status === 204) {
            return ['recomendacoes' => [], 'sem_recomendacao' => true];
        }
        if (! $r->ok() || ! is_array($r->corpo)) {
            $m = MapeadorErroAlavanca::traduzir($r, '/prices-per-quantity/v1/recommendations');
            throw new \RuntimeException((string) ($m['mensagem'] ?? "O Mercado Livre não devolveu a recomendação (HTTP {$r->status})."));
        }

        $lista = array_map(fn ($x) => [
            'quantidade' => (int) ($x['quantity'] ?? 0),
            'valor' => isset($x['amount']) ? (float) $x['amount'] : null,
            'percentual' => isset($x['discount']['percentage']) ? (float) $x['discount']['percentage'] : null,
            'incoerente' => (bool) ($x['is_incoherent_quantity'] ?? false),
            'lucro' => isset($x['profit']['amount']) ? (float) $x['profit']['amount'] : null,
            'frete' => isset($x['shipping']['cost']) ? (float) $x['shipping']['cost'] : null,
        ], array_values(array_filter((array) ($r->corpo['recommendations'] ?? []), 'is_array')));

        return ['recomendacoes' => $lista, 'sem_recomendacao' => $lista === []];
    }

    /** @return list<array{id: string, percentual: float, quantidade_minima: int}> */
    private function normalizar(array $bruto): array
    {
        $faixas = [];
        foreach ($bruto as $f) {
            if (! is_array($f)) {
                continue;
            }
            $faixas[] = ['id' => (string) ($f['id'] ?? ''), 'percentual' => (float) ($f['percentage'] ?? 0),
                'quantidade_minima' => (int) ($f['conditions']['min_purchase_unit'] ?? 0)];
        }
        usort($faixas, fn ($a, $b) => $a['quantidade_minima'] <=> $b['quantidade_minima']);

        return $faixas;
    }

    private function itemValido(string $itemId): void
    {
        if (! preg_match('/^MLB\d+$/', $itemId)) {
            throw new RegraViolada('ALAV-ENT', 'Identificador de anúncio inválido.');
        }
    }
}
