<?php

namespace App\Services\PlanejadorFull;

use App\Models\Company;
use App\Models\FullEnvio;
use App\Models\FullEnvioItem;
use App\Services\MercadoLivreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Entrada pendente" do Planejador FULL — o que foi enviado ao FULL e ainda não chegou.
 *
 * A API do ML não expõe o envio (inbound) de vendedor do Brasil: /inbounds/{id} dá 403 e
 * o estoque do FULL só mostra o que já está lá dentro (medido em 15/10/2026). O que a API
 * mostra é cada CHEGADA, como operação INBOUND_RECEPTION do Código ML, com o número do
 * envio (`inbound_id`) em external_references. Então o envio é registrado no Planejador e
 * a coleta diária distribui as chegadas entre os envios registrados:
 *
 *  1. chegada com o mesmo número de envio do ML vai para esse envio;
 *  2. o resto vai, por ordem de data (o envio mais antigo primeiro), para os envios sem
 *     número cujo início de contagem já passou quando a chegada aconteceu.
 *
 * A distribuição é refeita do zero a cada coleta (idempotente). Envios já recebidos entram
 * nela também: senão as chegadas que eram deles iriam para o envio mais novo.
 */
class FullEnviosService
{
    /** A API aceita no máximo ~60 dias por consulta de operações. */
    private const JANELA_CONSULTA_DIAS = 60;

    /** Envios já recebidos há mais do que isto saem da redistribuição. */
    private const DIAS_ENVIOS_FECHADOS = 90;

    public function __construct(private MercadoLivreService $ml) {}

    /**
     * Pendente por Código ML: soma de (quantidade − recebido) dos envios a caminho.
     *
     * @return array<string, int>
     */
    public function pendentesPorInventario(int $companyId): array
    {
        return FullEnvioItem::query()
            ->join('full_envios', 'full_envios.id', '=', 'full_envio_itens.envio_id')
            ->where('full_envios.company_id', $companyId)
            ->where('full_envios.status', FullEnvio::STATUS_A_CAMINHO)
            ->get(['full_envio_itens.inventory_id', 'full_envio_itens.quantidade', 'full_envio_itens.recebido'])
            ->groupBy('inventory_id')
            ->map(fn (Collection $itens) => (int) $itens->sum(fn ($i) => max(0, $i->quantidade - $i->recebido)))
            ->filter(fn (int $q) => $q > 0)
            ->all();
    }

    /**
     * Confere as chegadas dos envios a caminho e fecha os que chegaram por inteiro.
     *
     * @return array{envios:int, inventarios:int, fechados:int}
     */
    public function conciliar(Company $company, string $sellerId, ?CarbonImmutable $agora = null): array
    {
        $agora ??= CarbonImmutable::now();

        $abertos = FullEnvio::query()
            ->where('company_id', $company->id)
            ->where('status', FullEnvio::STATUS_A_CAMINHO)
            ->with('itens')
            ->get();

        if ($abertos->isEmpty()) {
            return ['envios' => 0, 'inventarios' => 0, 'fechados' => 0];
        }

        $inventarios = $abertos->flatMap(fn (FullEnvio $e) => $e->itens->pluck('inventory_id'))->unique()->values();

        // Recebidos recentes que dividem Código ML com algum envio aberto.
        $recebidos = FullEnvio::query()
            ->where('company_id', $company->id)
            ->where('status', FullEnvio::STATUS_RECEBIDO)
            ->where('data_despacho', '>=', $agora->subDays(self::DIAS_ENVIOS_FECHADOS)->toDateString())
            ->with('itens')
            ->get();

        $envios = $abertos->concat($recebidos);
        $atualizacoes = [];

        foreach ($inventarios as $inventoryId) {
            $destinos = [];

            foreach ($envios as $envio) {
                $item = $envio->itens->firstWhere('inventory_id', $inventoryId);

                if ($item) {
                    $destinos[] = [
                        'id'         => $item->id,
                        'desde'      => $this->inicioDaContagem($envio),
                        'inbound'    => $envio->inbound_ml ? (string) $envio->inbound_ml : null,
                        'quantidade' => (int) $item->quantidade,
                    ];
                }
            }

            $desde = collect($destinos)->min('desde');

            try {
                $chegadas = $this->buscarChegadas($company, $sellerId, $inventoryId, CarbonImmutable::parse($desde), $agora);
            } catch (\Throwable $e) {
                // Uma falha num Código ML não segura a baixa dos outros: mantém o recebido anterior.
                Log::warning("[PlanejadorFull] chegadas de {$inventoryId} falharam — empresa {$company->id}: " . mb_substr($e->getMessage(), 0, 300));

                continue;
            }

            foreach (self::alocarChegadas($destinos, $chegadas) as $itemId => $recebido) {
                $atualizacoes[$itemId] = $recebido;
            }
        }

        $fechados = 0;

        DB::transaction(function () use ($atualizacoes, $abertos, $agora, &$fechados) {
            foreach ($atualizacoes as $itemId => $recebido) {
                FullEnvioItem::whereKey($itemId)->update(['recebido' => $recebido]);
            }

            foreach ($abertos as $envio) {
                $envio->load('itens');
                $completo = $envio->itens->isNotEmpty()
                    && $envio->itens->every(fn (FullEnvioItem $i) => $i->recebido >= $i->quantidade);

                $envio->conferido_em = $agora;

                if ($completo) {
                    $envio->status = FullEnvio::STATUS_RECEBIDO;
                    $envio->recebido_em = $agora;
                    $fechados++;
                }

                $envio->save();
            }
        });

        return ['envios' => $abertos->count(), 'inventarios' => $inventarios->count(), 'fechados' => $fechados];
    }

    /**
     * Distribui as chegadas de UM Código ML entre os itens de envio dele.
     *
     * @param  array<int, array{id:int, desde:string, inbound:?string, quantidade:int}>  $destinos
     * @param  array<int, array{em:string, inbound:?string, quantidade:int}>  $chegadas
     * @return array<int, int>  recebido por id de item de envio
     */
    public static function alocarChegadas(array $destinos, array $chegadas): array
    {
        usort($destinos, fn ($a, $b) => [$a['desde'], $a['id']] <=> [$b['desde'], $b['id']]);
        usort($chegadas, fn ($a, $b) => $a['em'] <=> $b['em']);

        $recebido = [];
        foreach ($destinos as $d) {
            $recebido[$d['id']] = 0;
        }

        foreach ($chegadas as $chegada) {
            $resta = (int) $chegada['quantidade'];

            // 1º: o envio que tem o mesmo número do ML.
            if ($chegada['inbound'] !== null) {
                foreach ($destinos as $d) {
                    if ($resta <= 0) {
                        break;
                    }
                    if ($d['inbound'] === $chegada['inbound']) {
                        $cabe = $d['quantidade'] - $recebido[$d['id']];
                        $toma = max(0, min($cabe, $resta));
                        $recebido[$d['id']] += $toma;
                        $resta -= $toma;
                    }
                }
            }

            // 2º: envios sem número, o mais antigo primeiro, se já contavam quando chegou.
            foreach ($destinos as $d) {
                if ($resta <= 0) {
                    break;
                }
                if ($d['inbound'] === null && $chegada['em'] >= $d['desde']) {
                    $cabe = $d['quantidade'] - $recebido[$d['id']];
                    $toma = max(0, min($cabe, $resta));
                    $recebido[$d['id']] += $toma;
                    $resta -= $toma;
                }
            }
            // O que sobra é de envio que não foi registrado aqui — fica de fora.
        }

        return $recebido;
    }

    /** Instante (UTC, ISO) a partir do qual as chegadas contam para o envio. */
    private function inicioDaContagem(FullEnvio $envio): string
    {
        $desde = $envio->chegadas_desde
            ? CarbonImmutable::parse($envio->chegadas_desde)
            : CarbonImmutable::parse($envio->data_despacho->format('Y-m-d') . ' 00:00:00', config('app.timezone'));

        return $desde->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Chegadas (INBOUND_RECEPTION) de um Código ML entre duas datas, paginando por scroll e
     * quebrando em janelas de 60 dias.
     *
     * @return array<int, array{em:string, inbound:?string, quantidade:int}>
     */
    private function buscarChegadas(Company $company, string $sellerId, string $inventoryId, CarbonImmutable $desde, CarbonImmutable $ate): array
    {
        $chegadas = [];
        $inicio = $desde->setTimezone(config('app.timezone'))->startOfDay();
        $fim = $ate->setTimezone(config('app.timezone'))->startOfDay();

        while ($inicio->lte($fim)) {
            $fimJanela = $inicio->addDays(self::JANELA_CONSULTA_DIAS - 1)->min($fim);
            $scroll = null;
            $paginas = 0;

            do {
                $query = [
                    'seller_id'    => $sellerId,
                    'inventory_id' => $inventoryId,
                    'type'         => 'INBOUND_RECEPTION',
                    'date_from'    => $inicio->toDateString(),
                    'date_to'      => $fimJanela->toDateString(),
                    'limit'        => 50,
                ];
                if ($scroll !== null) {
                    $query['scroll'] = $scroll;
                }

                $r = $this->ml->get($company, '/stock/fulfillment/operations/search', $query);
                $resultados = $r['results'] ?? [];

                foreach ($resultados as $op) {
                    $chegadas[] = self::lerChegada($op);
                }

                $scroll = $r['paging']['scroll'] ?? null;
                $paginas++;
            } while ($resultados !== [] && $scroll && $paginas < 100);

            $inicio = $fimJanela->addDay();
        }

        return $chegadas;
    }

    /**
     * Uma operação INBOUND_RECEPTION vira {em, inbound, quantidade}. A quantidade soma o que
     * entrou disponível e o que entrou indisponível (avaria na conferência ainda é unidade que
     * chegou — não pode continuar pendente).
     *
     * @return array{em:string, inbound:?string, quantidade:int}
     */
    public static function lerChegada(array $op): array
    {
        $qtd = max(0, (int) ($op['detail']['available_quantity'] ?? 0));

        foreach ($op['detail']['not_available_detail'] ?? [] as $d) {
            $qtd += max(0, (int) ($d['quantity'] ?? 0));
        }

        $inbound = null;
        foreach ($op['external_references'] ?? [] as $ref) {
            if (($ref['type'] ?? null) === 'inbound_id') {
                $inbound = (string) $ref['value'];
            }
        }

        return [
            'em'         => CarbonImmutable::parse($op['date_created'])->utc()->format('Y-m-d\TH:i:s\Z'),
            'inbound'    => $inbound,
            'quantidade' => $qtd,
        ];
    }
}
