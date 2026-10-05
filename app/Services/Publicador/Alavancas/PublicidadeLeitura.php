<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Cache;

/**
 * D-09 — publicidade SÓ LEITURA nesta fase. Caminhos da documentação de 06/07/2026; os legados de
 * Product Ads respondem 404 desde 27/05/2026 e as métricas por anúncio foram removidas em 30/05/2026
 * — a leitura atual é por Ad Group. Criar/pausar campanha fica fora até a permissão Advertising do
 * app e a prova na #459.
 *
 * Toda chamada daqui é GET.
 */
class PublicidadeLeitura
{
    public const ANUNCIANTE = '/advertising/advertisers';

    public const CAMPANHAS = '/advertising/MLB/advertisers/%s/product_ads/campaigns/search';

    public const AD_GROUPS = '/advertising/MLB/advertisers/%s/product_ads/ad_groups/search';

    public const BONIFICACOES = '/advertising/advertisers/bonifications';

    public const METRICAS = 'clicks,prints,ctr,cost,cpc,acos,roas,units_quantity,total_amount';

    private const SEM_PRODUCT_ADS = 'Product Ads não está ativo nesta conta.';

    public function __construct(
        private ClienteMlPublicador $cliente,
        private CacheAlavancas $cache,
    ) {}

    /**
     * O anunciante do site MLB da conta (guardado por 24 h só quando achou).
     *
     * @return array{advertiser_id: ?string, indisponivel: ?string}
     */
    public function anunciante(ContaAlavanca $c): array
    {
        $chave = "alavancas:ads:adv:{$c->chaveConta()}";
        $guardado = Cache::get($chave);
        if (is_string($guardado) && $guardado !== '') {
            return ['advertiser_id' => $guardado, 'indisponivel' => null];
        }

        $r = $this->cliente->daConta($c->conta, 'GET', self::ANUNCIANTE, ['product_id' => 'PADS'], null, true, ['Api-Version' => '1']);
        if ($motivo = $this->indisponivel($r, self::ANUNCIANTE)) {
            return ['advertiser_id' => null, 'indisponivel' => $motivo];
        }
        $this->exigirOk($c, self::ANUNCIANTE, $r);

        foreach ((array) ($r->corpo['advertisers'] ?? []) as $a) {
            if (is_array($a) && ($a['site_id'] ?? null) === 'MLB' && ! empty($a['advertiser_id'])) {
                $id = (string) $a['advertiser_id'];
                Cache::put($chave, $id, (int) config('publicador.alavancas.cache.anunciante', 86400));

                return ['advertiser_id' => $id, 'indisponivel' => null];
            }
        }

        return ['advertiser_id' => null, 'indisponivel' => self::SEM_PRODUCT_ADS];
    }

    /** Janela de datas aceita: até 90 dias para trás, sem futuro, de <= ate. */
    public function janela(string $de, string $ate): void
    {
        $hoje = DatasDoMl::hoje();
        $limite = (int) config('publicador.alavancas.limites.janela_publicidade_dias', 90);
        $dDe = DatasDoMl::ler($de);
        $dAte = DatasDoMl::ler($ate);

        if ($dDe === null || $dAte === null || $dDe->gt($dAte)
            || $dAte->startOfDay()->gt($hoje) || $dDe->startOfDay()->lt($hoje->subDays($limite))) {
            throw new RegraViolada('ALAV-DATA', "Escolha um período de até {$limite} dias, sem datas futuras.");
        }
    }

    /**
     * @return array{campanhas: list<array>, resumo: array, indisponivel?: string}
     */
    public function campanhas(ContaAlavanca $c, string $de, string $ate): array
    {
        $this->janela($de, $ate);
        $adv = $this->anunciante($c);
        if ($adv['indisponivel'] !== null) {
            return ['campanhas' => [], 'resumo' => [], 'indisponivel' => $adv['indisponivel']];
        }

        $caminho = sprintf(self::CAMPANHAS, $adv['advertiser_id']);
        $query = ['limit' => 50, 'offset' => 0, 'date_from' => $de, 'date_to' => $ate, 'metrics' => self::METRICAS, 'metrics_summary' => 'true'];
        $r = $this->cliente->daConta($c->conta, 'GET', $caminho, $query, null, true, ['api-version' => '2']);
        if ($motivo = $this->indisponivel($r, $caminho)) {
            return ['campanhas' => [], 'resumo' => [], 'indisponivel' => $motivo];
        }
        $this->exigirOk($c, $caminho, $r);

        $campanhas = array_map(fn (array $k) => [
            'id' => (string) ($k['id'] ?? ''),
            'nome' => $k['name'] ?? null,
            'status' => $k['status'] ?? null,
            'orcamento_diario' => $k['daily_budget'] ?? null,
            'orcamento' => $k['budget'] ?? null,
            'estrategia' => $k['strategy'] ?? null,
            'acos_alvo' => $k['acos_target'] ?? null,
            'metricas' => $this->metricas((array) ($k['metrics'] ?? [])),
        ], array_values(array_filter((array) ($r->corpo['results'] ?? []), 'is_array')));

        return ['campanhas' => $campanhas, 'resumo' => $this->metricas((array) ($r->corpo['metrics_summary'] ?? []))];
    }

    /**
     * Métricas por Ad Group (a leitura por anúncio foi removida em 30/05/2026).
     *
     * @param  list<string>  $itens  MLBs a filtrar (até 20)
     * @return array{ad_groups: list<array>, resumo?: array, indisponivel?: string}
     */
    public function adGroups(ContaAlavanca $c, string $de, string $ate, array $itens = []): array
    {
        $this->janela($de, $ate);
        $adv = $this->anunciante($c);
        if ($adv['indisponivel'] !== null) {
            return ['ad_groups' => [], 'indisponivel' => $adv['indisponivel']];
        }

        $ids = array_slice(array_values(array_filter($itens, fn ($i) => is_string($i) && preg_match('/^MLB\d{1,17}$/D', $i))), 0, 20);
        $caminho = sprintf(self::AD_GROUPS, $adv['advertiser_id']);
        $query = ['date_from' => $de, 'date_to' => $ate, 'limit' => 50, 'sort' => 'desc', 'sort_by' => 'clicks', 'metrics' => self::METRICAS];
        if ($ids !== []) {
            $query['filters[item_ids]'] = implode(',', $ids);
        }

        $r = $this->cliente->daConta($c->conta, 'GET', $caminho, $query, null, true, ['api-version' => '2']);
        if ($motivo = $this->indisponivel($r, $caminho)) {
            return ['ad_groups' => [], 'indisponivel' => $motivo];
        }
        $this->exigirOk($c, $caminho, $r);

        $grupos = array_map(fn (array $g) => [
            'id' => (string) ($g['id'] ?? ''),
            'titulo' => $g['title'] ?? null,
            'status' => $g['status'] ?? null,
            'campanha_id' => (string) ($g['campaign_id'] ?? 0),
            // campaign_id 0 = o grupo não está em nenhuma campanha.
            'fora_de_campanha' => (int) ($g['campaign_id'] ?? 0) === 0,
            'metricas' => $this->metricas((array) ($g['metrics'] ?? [])),
        ], array_values(array_filter((array) ($r->corpo['results'] ?? []), 'is_array')));

        return ['ad_groups' => $grupos, 'resumo' => $this->metricas((array) ($r->corpo['metrics_summary'] ?? []))];
    }

    /**
     * Créditos de Product Ads (certificação, Seller Startup, Smart Benefits, manual). A doc não pede
     * parâmetro nem cabeçalho de versão neste GET.
     *
     * @return array{saldo_total: float, itens: list<array>, indisponivel?: string}
     */
    public function bonificacoes(ContaAlavanca $c): array
    {
        $r = $this->cliente->daConta($c->conta, 'GET', self::BONIFICACOES);
        if ($motivo = $this->indisponivel($r, self::BONIFICACOES)) {
            return ['saldo_total' => 0.0, 'itens' => [], 'indisponivel' => $motivo];
        }
        $this->exigirOk($c, self::BONIFICACOES, $r);

        $itens = array_map(fn (array $b) => [
            'status' => $b['status'] ?? null,
            'nivel' => $b['level'] ?? null,
            'valor' => (float) ($b['amount'] ?? 0),
            'saldo' => (float) ($b['balance'] ?? 0),
            'fim' => $b['end_date'] ?? null,
            'dias_restantes' => $b['days_remaining'] ?? null,
            'beneficio' => $b['benefit_name'] ?? null,
            'campanha' => $b['campaign_name'] ?? null,
        ], array_values(array_filter((array) ($r->corpo['bonification'] ?? []), 'is_array')));

        $ativas = array_filter($itens, fn (array $b) => ($b['status'] ?? null) === 'ACTIVE');

        return ['saldo_total' => (float) array_sum(array_column($ativas, 'saldo')), 'itens' => $itens];
    }

    /**
     * Resumo dos últimos 30 dias para o panorama.
     *
     * @return array{campanhas_ativas?: int, investimento?: float, vendas?: float, acos?: ?float, bonificacao_saldo?: float, indisponivel?: string}
     */
    public function resumo(ContaAlavanca $c, bool $atualizar = false): array
    {
        return $this->cache->lembrar($c, 'publicidade', [], (int) config('publicador.alavancas.cache.panorama', 120), function () use ($c): array {
            $ate = DatasDoMl::hoje();
            $k = $this->campanhas($c, $ate->subDays(29)->format('Y-m-d'), $ate->format('Y-m-d'));
            if (isset($k['indisponivel'])) {
                return ['indisponivel' => $k['indisponivel']];
            }
            $b = $this->bonificacoes($c);

            return [
                'campanhas_ativas' => count(array_filter($k['campanhas'], fn (array $x) => $x['status'] === 'active')),
                'investimento' => (float) ($k['resumo']['investimento'] ?? 0),
                'vendas' => (float) ($k['resumo']['vendas'] ?? 0),
                'acos' => $k['resumo']['acos'] ?? null,
                'bonificacao_saldo' => $b['saldo_total'],
            ];
        }, $atualizar);
    }

    /** @return array{cliques: int, impressoes: int, ctr: ?float, investimento: float, cpc: ?float, acos: ?float, roas: ?float, unidades: int, vendas: float} */
    private function metricas(array $m): array
    {
        return [
            'cliques' => (int) ($m['clicks'] ?? 0),
            'impressoes' => (int) ($m['prints'] ?? 0),
            'ctr' => isset($m['ctr']) ? (float) $m['ctr'] : null,
            'investimento' => (float) ($m['cost'] ?? 0),
            'cpc' => isset($m['cpc']) ? (float) $m['cpc'] : null,
            'acos' => isset($m['acos']) ? (float) $m['acos'] : null,
            'roas' => isset($m['roas']) ? (float) $m['roas'] : null,
            'unidades' => (int) ($m['units_quantity'] ?? 0),
            'vendas' => (float) ($m['total_amount'] ?? 0),
        ];
    }

    /** Conta sem Product Ads (404 "No permissions found") ou app sem permissão (403): explicação, não erro. */
    private function indisponivel(RespostaMl $r, string $caminho): ?string
    {
        if ($r->status === 404 && stripos((string) json_encode($r->corpo, JSON_UNESCAPED_UNICODE), 'No permissions found') !== false) {
            return self::SEM_PRODUCT_ADS;
        }
        if ($r->status === 403) {
            return MapeadorErroAlavanca::traduzir($r, $caminho)['mensagem'];
        }

        return null;
    }

    private function exigirOk(ContaAlavanca $c, string $caminho, RespostaMl $r): void
    {
        if (! $r->ok() || ! is_array($r->corpo)) {
            throw new \RuntimeException("[Alavancas] leitura {$caminho} falhou conta {$c->chaveConta()}: HTTP {$r->status}");
        }
    }
}
