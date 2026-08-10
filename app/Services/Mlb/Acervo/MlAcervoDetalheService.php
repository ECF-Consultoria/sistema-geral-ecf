<?php

namespace App\Services\Mlb\Acervo;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Log;

/**
 * Camada CARA da coleta do acervo Mercado Livre (D-19/D-23) — visitas por
 * item, status de buy box (`price_to_win`) e saúde do ML (`performance`,
 * D-21). As três são 1 chamada HTTP por item, sem lote (confirmado por
 * chamada real: `/visits/items?ids=` promete lote de 20 na documentação e a
 * API recusa com limite 1) — inviável rodar contra o acervo inteiro todo
 * dia (~587 mil chamadas). O D-23 resolve isso com rotação por fatia: cada
 * execução cobre 1/N do acervo ATIVO da empresa, e o próprio
 * `detalhe_coletado_em` é o cursor — não existe tabela de controle de
 * rotação. Quem foi coletado hoje vai para o fim da fila (timestamp mais
 * recente); quem falhou continua com o valor antigo (ou nulo) e volta a ser
 * o mais antigo, auto-corretivo por construção.
 *
 * Decisão do planner: a camada cara cobre só itens com status=active.
 * Pausado e encerrado já têm o motivo conhecido pela camada barata sem
 * urgência de visitas/buy box — incluí-los infla a rotação sem ganho de
 * triagem (D-03: a tela é leitura de ativos por padrão).
 *
 * Toda chamada sai por MercadoLivreService::get() — nunca Http:: direto,
 * nunca verbo de escrita (D-11). comRetry429() já honra Retry-After com
 * backoff; esta classe NUNCA implementa sleep manual.
 */
class MlAcervoDetalheService
{
    /**
     * As ÚNICAS colunas que a camada cara escreve em `ml_acervo_itens`.
     * Espelho da proibição registrada no plano 134-04, agora na direção
     * inversa: `title`, `status`, `price`, `available_quantity`,
     * `sold_quantity`, `nota_ecf`, `nota_sinais`, `origem`, `rascunho_id`,
     * `coletado_em`, `coleta_erro` (entre outras) são da camada BARATA e
     * NUNCA entram neste array — é a camada barata quem sabe o valor
     * fresco delas. `motivos`/`severidade` são compartilhadas: esta camada
     * as recalcula a partir de um pseudo-payload montado das colunas JÁ
     * PERSISTIDAS pela camada barata (status/available_quantity/
     * catalog_listing) — nunca de um array vazio, senão apagaria em
     * silêncio o motivo que a camada barata tinha acabado de escrever.
     *
     * `performance_score`/`performance_level`/`performance_acoes` entram
     * aqui (D-21, veredicto DISPONÍVEL da sondagem 134-01) — mesma classe
     * de custo de visitas/buy box, 1 call/item, sem lote.
     */
    private const COLUNAS_CAMADA_CARA = [
        'visitas_30d', 'buybox_status', 'performance_score', 'performance_level',
        'performance_acoes', 'motivos', 'severidade', 'detalhe_coletado_em',
    ];

    /** T-134-14: só estes 4 valores são aceitos — qualquer outro deixa a coluna nula. */
    private const BUYBOX_STATUS_VALIDOS = ['winning', 'sharing_first_place', 'losing', 'listed'];

    public function __construct(
        private MercadoLivreService $ml,
        private AnuncioSaudeService $saude,
    ) {}

    /**
     * Seleciona a fatia da rotação desta execução: 1/N do acervo ATIVO da
     * empresa, os menos recentemente detalhados primeiro (nulos na
     * frente). `orderByRaw('detalhe_coletado_em IS NULL DESC')` em vez de
     * `NULLS FIRST` porque este último não é portável entre MariaDB
     * (produção) e o SQLite dos testes.
     *
     * @return string[]  MLB ids
     */
    public function selecionarFatia(Company $company, int $n): array
    {
        $n = max(1, $n);

        $total = MlAcervoItem::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->count();

        if ($total === 0) {
            return [];
        }

        $tamanhoFatia = (int) ceil($total / $n);

        return MlAcervoItem::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderByRaw('detalhe_coletado_em IS NULL DESC')
            ->orderBy('detalhe_coletado_em')
            ->orderBy('ml_item_id')
            ->limit($tamanhoFatia)
            ->pluck('ml_item_id')
            ->all();
    }

    /**
     * Coleta visitas, buy box e performance (D-21) para um lote de ids e
     * atualiza a linha corrente + a série diária. Fail-open por item: uma
     * falha em qualquer chamada HTTP do item aborta SÓ a escrita daquele
     * item (nenhuma coluna da camada cara é tocada) — o item continua
     * "não avaliado" e volta ao topo da fila na próxima execução (D-18).
     *
     * @param  string[] $mlItemIds
     * @return array{itens:int, falhas:int}
     */
    public function coletarDetalhe(Company $company, array $mlItemIds): array
    {
        if ($mlItemIds === []) {
            return ['itens' => 0, 'falhas' => 0];
        }

        $linhas = MlAcervoItem::query()
            ->where('company_id', $company->id)
            ->whereIn('ml_item_id', $mlItemIds)
            ->get();

        $itens = 0;
        $falhas = 0;
        $agora = now();
        $hoje = $agora->toDateString();

        foreach ($linhas as $linha) {
            try {
                $this->coletarItem($company, $linha, $agora, $hoje);
                $itens++;
            } catch (\Throwable $e) {
                $falhas++;
                Log::warning("[MLB Anuncios] detalhe falhou — empresa {$company->id}, item {$linha->ml_item_id}: {$e->getMessage()}");
            }
        }

        return ['itens' => $itens, 'falhas' => $falhas];
    }

    /**
     * Coleta de UM item. Tudo dentro do try/catch do chamador — qualquer
     * exceção aqui (visits, price_to_win ou performance) impede QUALQUER
     * escrita para este item, inclusive visitas_30d: é a fronteira de
     * "erro não vira dado" do D-08/D-18. Não escrever nada em vez de
     * escrever pela metade evita um estado inconsistente (ex.: visitas
     * atualizadas mas buybox_status desatualizado) que seria mais difícil
     * de diagnosticar do que simplesmente repetir o item na próxima fatia.
     */
    private function coletarItem(Company $company, MlAcervoItem $linha, \DateTimeInterface $agora, string $hoje): void
    {
        // ── 1) visitas — janela móvel de 30 dias terminando na coleta ──────
        // Decisão do planner: a métrica é a janela de 30 dias, não "visitas
        // de ontem". Com a rotação tocando cada item a cada N dias, uma
        // contagem de 1 dia seria ruído — a janela de 30 dias é comparável
        // entre coletas espaçadas no tempo.
        $dateTo = now()->startOfDay();
        $dateFrom = $dateTo->copy()->subDays(30);
        $formatarData = static fn ($c) => $c->format('Y-m-d') . 'T00:00:00.000-00:00';

        $visitasResposta = $this->ml->get($company, "/items/{$linha->ml_item_id}/visits", [
            'date_from' => $formatarData($dateFrom),
            'date_to' => $formatarData($dateTo),
        ]);

        $visitas30d = isset($visitasResposta['visits_detail'])
            ? (int) collect($visitasResposta['visits_detail'])->sum(fn ($d) => (int) ($d['quantity'] ?? 0))
            : (int) ($visitasResposta['total_visits'] ?? 0);

        // ── 2) buy box — só item de catálogo. Fora disso, fica nulo para ───
        // sempre (o modelo distingue isso de "não avaliado" via
        // catalog_listing, ver MlAcervoItem::naoAvaliadoBuyBox()).
        $buyboxStatus = $linha->buybox_status;
        if ($linha->catalog_listing) {
            $priceToWin = $this->ml->get($company, "/items/{$linha->ml_item_id}/price_to_win", ['version' => 'v2']);
            $statusRecebido = $priceToWin['status'] ?? null;
            // T-134-14: só os 4 valores documentados são aceitos — qualquer
            // outro valor (ou ausência) deixa a coluna nula, nunca inventado.
            $buyboxStatus = in_array($statusRecebido, self::BUYBOX_STATUS_VALIDOS, true) ? $statusRecebido : null;
        }

        // ── 2b) saúde do ML (D-21) — só onde o ML de fato pontua. Catálogo ──
        // e encerrado NUNCA têm ficha própria (achado da sondagem 134-01,
        // materializado em MlAcervoItem::saudeMlNaoSeAplica()) — chamar
        // performance nesses casos é chamada cara desperdiçada.
        $performanceScore = $linha->performance_score;
        $performanceLevel = $linha->performance_level;
        $performanceAcoes = $linha->performance_acoes ?? [];
        if (! $linha->saudeMlNaoSeAplica()) {
            $performance = $this->ml->get($company, "/item/{$linha->ml_item_id}/performance");
            $performanceScore = isset($performance['score']) ? (int) round((float) $performance['score']) : null;
            $performanceLevel = $performance['level'] ?? null;
            $performanceAcoes = $this->extrairAcoesPendentes($performance['buckets'] ?? []);
        }

        // ── 3) triagem recomputada a partir das colunas JÁ PERSISTIDAS ──────
        // pela camada barata (status/available_quantity/catalog_listing) —
        // NUNCA de um payload vazio. Chamar a triagem com um array sem
        // dados apagaria em silêncio o motivo que a camada barata tinha
        // acabado de escrever (espelho do T-134-26 do plano 134-04, na
        // direção inversa).
        $pseudoPayload = [
            'status' => $linha->status,
            'available_quantity' => $linha->available_quantity,
            'catalog_listing' => $linha->catalog_listing,
        ];
        $triagem = $this->saude->triagem($pseudoPayload, $linha->nota_sinais ?? [], $buyboxStatus);

        // ── 4/5) escrita nomeada — só as colunas de COLUNAS_CAMADA_CARA. ────
        // Proibido upsert() e proibido hidratar o model/save(): a linha
        // necessariamente já existe (a fatia sai de ml_acervo_itens), e
        // escrita de linha inteira com payload parcial é exatamente o
        // mecanismo pelo qual uma camada apaga a outra.
        MlAcervoItem::query()
            ->where('company_id', $company->id)
            ->where('ml_item_id', $linha->ml_item_id)
            ->update([
                'visitas_30d' => $visitas30d,
                'buybox_status' => $buyboxStatus,
                'performance_score' => $performanceScore,
                'performance_level' => $performanceLevel,
                'performance_acoes' => json_encode($performanceAcoes),
                'motivos' => json_encode($triagem['motivos']),
                'severidade' => $triagem['severidade'],
                'detalhe_coletado_em' => $agora,
            ]);

        $this->gravarVisitasSerieDiaria($company, $linha->ml_item_id, $visitas30d, $hoje);
    }

    /**
     * D-21: extrai só as ações PENDENTES de `GET /item/{id}/performance` —
     * `variables[]` com `status=PENDING` alimentam a triagem do D-09.
     * `title` é preservado EXATAMENTE como veio do ML (já redigido em
     * pt-BR pelo próprio ML) — nunca reescrito.
     *
     * @param  array $buckets  `buckets[]` da resposta de performance
     * @return array<int, array{key: ?string, title: ?string}>
     */
    private function extrairAcoesPendentes(array $buckets): array
    {
        $acoes = [];

        foreach ($buckets as $bucket) {
            foreach ($bucket['variables'] ?? [] as $variable) {
                if (($variable['status'] ?? null) === 'PENDING') {
                    $acoes[] = [
                        'key' => $variable['key'] ?? null,
                        'title' => $variable['title'] ?? null,
                    ];
                }
            }
        }

        return $acoes;
    }

    /**
     * D-07b — série diária: grava `visitas` (mesmo valor de `visitas_30d`)
     * pela mesma regra "só quando muda" do plano 134-04 — reexecução no
     * mesmo dia sempre atualiza a linha do dia; se a última linha é de dia
     * anterior, só cria linha nova quando o valor muda. `updateOrCreate`
     * grava SÓ a chave `visitas` além da chave lógica
     * (company_id, ml_item_id, data): passar `sold_quantity`/`nota_ecf`
     * nulos aqui apagaria o que a camada barata já tivesse escrito na
     * linha do mesmo dia.
     */
    private function gravarVisitasSerieDiaria(Company $company, string $mlItemId, int $visitas30d, string $hoje): void
    {
        $ultima = MlAcervoMetricaDiaria::where('company_id', $company->id)
            ->where('ml_item_id', $mlItemId)
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->first(['data', 'visitas']);

        $jaExisteHoje = $ultima !== null && $ultima->data->toDateString() === $hoje;

        if (! $jaExisteHoje && $ultima !== null && $ultima->visitas === $visitas30d) {
            // Mesmo valor do último dado conhecido — nenhuma linha nova.
            return;
        }

        MlAcervoMetricaDiaria::updateOrCreate(
            ['company_id' => $company->id, 'ml_item_id' => $mlItemId, 'data' => $hoje],
            ['visitas' => $visitas30d]
        );
    }
}
