<?php

namespace App\Services\Mlb\Acervo;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Services\MercadoLivreService;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Camada BARATA da coleta do acervo Mercado Livre (D-01/D-19/D-20).
 *
 * Varre TODOS os itens da conta por `scroll_id` (enumerarIds) e busca os
 * campos que vêm de graça no multiget de 20 ids (`GET /items?ids=`), sem
 * nenhuma chamada por item — é o que torna viável rodar TODO dia contra o
 * acervo inteiro (~20.347 chamadas / ~14min para 406.932 itens, D-19).
 *
 * Toda chamada HTTP passa por MercadoLivreService::get() — token, refresh e
 * retry 429 (Retry-After + backoff) já resolvidos lá. Nenhum verbo de
 * escrita neste arquivo (D-11): a tela é só leitura.
 */
class MlAcervoService
{
    /**
     * Colunas que a CAMADA BARATA é dona e, por isso, as ÚNICAS que o
     * upsert() atualiza em conflito (3º argumento, nunca omitido).
     *
     * `buybox_status`, `visitas_30d`, `performance_score`, `performance_level`,
     * `performance_acoes` e `detalhe_coletado_em` NÃO estão aqui de propósito:
     * pertencem à camada CARA (plano 134-05, rotação do D-23) e seriam
     * apagadas a cada execução diária/"Atualizar agora" se entrassem — é o
     * T-134-26 do threat model. `health_ml` ENTRA (D-21): vem de graça no
     * multiget, custo zero, então é desta camada.
     */
    private const COLUNAS_CAMADA_BARATA = [
        'title', 'category_id', 'status', 'sub_status', 'listing_type_id', 'price',
        'available_quantity', 'sold_quantity', 'permalink', 'thumbnail', 'fotos_count',
        'has_variations', 'variations', 'catalog_listing', 'catalog_product_id',
        'shipping', 'tags', 'health_ml', 'nota_ecf', 'nota_sinais', 'motivos', 'severidade',
        'origem', 'rascunho_id', 'publicacao_vendas_qty', 'publicacao_desconsiderado',
        'coletado_em', 'coleta_erro', 'updated_at',
    ];

    /**
     * Atributos pedidos ao multiget — confirmado por chamada real contra
     * produção (134-RESEARCH.md §"Code Examples"). `health` entra por causa
     * do D-21 (veredicto DISPONÍVEL da sondagem 134-01): vem de graça no
     * mesmo payload, sem custo extra.
     */
    private const ATRIBUTOS_MULTIGET = 'id,status,sub_status,available_quantity,sold_quantity,'
        . 'shipping,listing_type_id,tags,catalog_listing,catalog_product_id,'
        . 'variations,title,price,permalink,thumbnail,category_id,attributes,pictures,health';

    public function __construct(
        private MercadoLivreService $ml,
        private MlCatalogoMetaService $meta,
        private AnuncioSaudeService $saude,
    ) {}

    /**
     * Enumera TODOS os ids de item da conta por scroll_id (D-20). Nunca usa
     * offset — a API estoura em ~1000-1100 itens (erro real capturado na
     * pesquisa) e a maior conta tem 66.747.
     *
     * @return \Generator<int, string>  MLB ids, em streaming — nunca materializa
     *                                  66 mil ids em memória de uma vez
     */
    public function enumerarIds(Company $company, ?string $status = null): \Generator
    {
        $token = $company->mlToken;

        if (! $token) {
            throw new \RuntimeException("[MLB Anuncios] Empresa {$company->id} sem token ML — não é possível varrer o acervo.");
        }

        $limit = (int) config('mlb_acervo.pagina_scroll');

        yield from $this->scroll($company, (string) $token->ml_user_id, $limit, $status, reiniciado: false);
    }

    /**
     * Um laço de scroll completo (search_type=scan). Reinicia UMA única vez
     * se o scroll_id expirar no meio (TTL de 5min — 134-RESEARCH.md §2);
     * reiniciar é seguro e idempotente porque o consumo a jusante é upsert
     * por (company_id, ml_item_id). Na segunda expiração a exceção sobe —
     * repetir indefinidamente esconderia um problema real.
     *
     * @return \Generator<int, string>
     */
    private function scroll(Company $company, string $mlUserId, int $limit, ?string $status, bool $reiniciado): \Generator
    {
        $scrollId = null;
        $ids = [];

        do {
            $params = ['search_type' => 'scan', 'limit' => $limit];
            if ($scrollId !== null) {
                $params['scroll_id'] = $scrollId;
            }
            if ($status !== null) {
                $params['status'] = $status;
            }

            try {
                $r = $this->ml->get($company, "/users/{$mlUserId}/items/search", $params);
            } catch (\RuntimeException $e) {
                if (! $reiniciado && str_contains(strtolower($e->getMessage()), 'scroll_id')) {
                    Log::warning("[MLB Anuncios] scroll expirado, reiniciando — empresa {$company->id}");
                    yield from $this->scroll($company, $mlUserId, $limit, $status, reiniciado: true);

                    return;
                }

                throw $e;
            }

            $ids = $r['results'] ?? [];
            $scrollId = $r['scroll_id'] ?? null;

            foreach ($ids as $id) {
                yield $id;
            }

            // SEM sleep entre páginas de propósito: o scroll_id tem TTL de 5
            // minutos e a conta de 66.747 itens exige ~1.335 páginas
            // sequenciais — um delay "de segurança" aqui perde itens por
            // expiração de cursor antes de terminar a varredura. O throttle
            // desta fase mora entre EMPRESAS (fan-out do 134-07) e dentro da
            // camada CARA, nunca na enumeração de ids.
        } while (! empty($ids));
    }

    /**
     * Multiget de até `lote_multiget` ids por chamada. A resposta vem no
     * envelope [{code, body}, ...] — item com code != 200 é pulado com log,
     * nunca aborta o lote inteiro (fail-open, mesmo padrão de
     * MercadoLivreService::fetchItemStatus()): com centenas de milhares de
     * itens, algum vai falhar todo dia.
     *
     * @param  string[] $ids
     * @return array<int, array>  os `body` dos itens bem-sucedidos
     */
    private function buscarLotes(Company $company, array $ids): array
    {
        $r = $this->ml->get($company, '/items', [
            'ids' => implode(',', $ids),
            'attributes' => self::ATRIBUTOS_MULTIGET,
        ]);

        $itens = [];

        foreach ($r as $wrapped) {
            if (($wrapped['code'] ?? null) !== 200) {
                Log::warning("[MLB Anuncios] item falhou no multiget empresa {$company->id}: " . json_encode($wrapped));

                continue;
            }

            $itens[] = $wrapped['body'];
        }

        return $itens;
    }

    /**
     * Camada barata completa de uma empresa: enumera, multiget em lotes de
     * 20, calcula nota/triagem, faz upsert da linha corrente e alimenta a
     * série diária.
     *
     * @return array{itens:int, lotes:int, falhas:int}
     */
    public function coletarCamadaBarata(Company $company): array
    {
        $loteSize = (int) config('mlb_acervo.lote_multiget');

        $itens = 0;
        $lotes = 0;
        $falhas = 0;
        $loteIds = [];

        // Mapas carregados 1x por empresa (D-04) — nunca consulta
        // MlAnuncioRascunho/Publicacao item a item: dezenas/centenas de
        // linhas por empresa contra dezenas de milhares de itens.
        $rascunhoPorMlItemId = $this->mapaRascunhos($company);
        $publicacaoPorMlbCode = $this->mapaPublicacoes($company);

        // Buy box já persistido pela camada CARA (134-05) — LEITURA ESTRITA,
        // usado só para alimentar triagem(); esta camada nunca o reescreve
        // (ver comentário do upsert() abaixo, D-23/D-18).
        $buyboxPorMlItemId = MlAcervoItem::where('company_id', $company->id)
            ->pluck('buybox_status', 'ml_item_id')
            ->all();

        try {
            foreach ($this->enumerarIds($company) as $id) {
                $loteIds[] = $id;

                if (count($loteIds) >= $loteSize) {
                    $lotes++;
                    [$processados, $falhasLote] = $this->processarLote(
                        $company, $loteIds, $rascunhoPorMlItemId, $publicacaoPorMlbCode, $buyboxPorMlItemId
                    );
                    $itens += $processados;
                    $falhas += $falhasLote;
                    $loteIds = [];
                }
            }

            if ($loteIds !== []) {
                $lotes++;
                [$processados, $falhasLote] = $this->processarLote(
                    $company, $loteIds, $rascunhoPorMlItemId, $publicacaoPorMlbCode, $buyboxPorMlItemId
                );
                $itens += $processados;
                $falhas += $falhasLote;
            }
        } catch (\Throwable $e) {
            // Falha em nível de EMPRESA (token inválido, scroll expirado 2x
            // etc.): grava coleta_erro nas linhas já existentes — update
            // nomeado só dessa coluna, nunca upsert de linha inteira — e
            // propaga. O D-08 exige que a tela saiba POR QUE a coleta está
            // velha, não só que está; quem decide o resto (retry, log) é o
            // job (SyncMlAcervoCompanyJob::failed()).
            // `coleta_erro` é varchar(255). Gravar a mensagem crua estourava a
            // coluna em MariaDB (SQLSTATE 22001) e o PRÓPRIO TRATAMENTO DE
            // ERRO derrubava o job — perdendo, junto, a causa original. Medido
            // em produção em 2026-08-17, travando a coleta de acervo do
            // onboarding. O SQLite dos testes não reclama de tamanho, então
            // isto só aparece em produção (learnings §6).
            //
            // A mensagem inteira vai para o log; a coluna fica com o começo,
            // que é o que a tela precisa mostrar.
            Log::error(
                "[MlAcervo] falha na coleta da empresa {$company->id} ({$company->name}): {$e->getMessage()}"
            );

            // Erro de concorrência (deadlock) NÃO carimba coleta_erro.
            //
            // Este carimbo é um UPDATE de FAIXA INTEIRA: uma transação que
            // marca TODAS as linhas da empresa (até 66.747). Aplicá-lo a um
            // deadlock produzia duas consequências, ambas medidas em produção
            // (.planning/debug/acervo-deadlock-upsert.md, E6):
            //
            //   1. MENTIRA NA TELA — um deadlock em UM upsert de 20 linhas
            //      marcava o acervo inteiro da empresa como falho. Os 136.432
            //      itens com `coleta_erro` de deadlock não eram 136 mil falhas,
            //      eram poucas dezenas de eventos multiplicados pelo tamanho do
            //      acervo. O banner do D-08 deve dizer defasagem REAL.
            //   2. REALIMENTAÇÃO — o próprio UPDATE de faixa inteira segura
            //      lock exclusivo em dezenas de milhares de linhas e entradas
            //      de índice, colidindo com todo job da camada cara da mesma
            //      empresa em curso. Deadlock gerava mais deadlock.
            //
            // Deadlock é transitório: o job retenta (tries=3) e a rotação do
            // D-23 é auto-corretiva. A exceção segue propagando — quem decide
            // retry e log continua sendo o job.
            if (! AcervoEscritaLock::ehErroDeConcorrencia($e)) {
                MlAcervoItem::where('company_id', $company->id)
                    ->update(['coleta_erro' => Str::limit($e->getMessage(), 250)]);
            }

            throw $e;
        }

        return ['itens' => $itens, 'lotes' => $lotes, 'falhas' => $falhas];
    }

    /**
     * Processa um lote de até 20 ids: multiget, nota/triagem, selo de
     * origem, upsert da linha corrente e série diária.
     *
     * @return array{0:int,1:int}  [itens processados, falhas]
     */
    private function processarLote(
        Company $company,
        array $ids,
        array $rascunhoPorMlItemId,
        array $publicacaoPorMlbCode,
        array $buyboxPorMlItemId,
    ): array {
        $bodies = $this->buscarLotes($company, $ids);

        $linhas = [];
        $falhas = 0;
        $hoje = now()->toDateString();
        $agora = now();

        foreach ($bodies as $item) {
            try {
                $mlItemId = (string) ($item['id'] ?? '');
                if ($mlItemId === '') {
                    continue;
                }

                $categoryId = (string) ($item['category_id'] ?? '');
                $atributosCategoria = $categoryId !== '' ? $this->meta->atributos($categoryId) : [];

                $avaliacao = $this->saude->avaliar($item, $atributosCategoria);
                $buyboxStatus = $buyboxPorMlItemId[$mlItemId] ?? null;
                $triagem = $this->saude->triagem($item, $avaliacao['sinais'], $buyboxStatus);

                $origem = $this->resolverOrigem($mlItemId, $rascunhoPorMlItemId, $publicacaoPorMlbCode);

                $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];
                $pictures = is_array($item['pictures'] ?? null) ? $item['pictures'] : [];

                $linhas[] = [
                    'company_id' => $company->id,
                    'ml_item_id' => $mlItemId,

                    'title' => $item['title'] ?? null,
                    'category_id' => $categoryId !== '' ? $categoryId : null,
                    'status' => $item['status'] ?? null,
                    'sub_status' => json_encode(is_array($item['sub_status'] ?? null) ? $item['sub_status'] : []),
                    'listing_type_id' => $item['listing_type_id'] ?? null,
                    'price' => $item['price'] ?? null,
                    // D-17: available_quantity/sold_quantity vêm do NÍVEL DO
                    // ITEM-PAI — é onde o ML já entrega o agregado das
                    // variações. Nunca somar variações[] à mão.
                    'available_quantity' => isset($item['available_quantity']) ? (int) $item['available_quantity'] : null,
                    'sold_quantity' => isset($item['sold_quantity']) ? (int) $item['sold_quantity'] : null,
                    'permalink' => $item['permalink'] ?? null,
                    'thumbnail' => $item['thumbnail'] ?? null,
                    'fotos_count' => count($pictures),
                    'has_variations' => $variations !== [],
                    'variations' => json_encode($variations),
                    'catalog_listing' => (bool) ($item['catalog_listing'] ?? false),
                    'catalog_product_id' => $item['catalog_product_id'] ?? null,
                    'shipping' => json_encode(is_array($item['shipping'] ?? null) ? $item['shipping'] : []),
                    'tags' => json_encode(is_array($item['tags'] ?? null) ? $item['tags'] : []),
                    // D-21: campo `health` do multiget, de graça. null aqui é
                    // "não se aplica" (catálogo/encerrado) ou "não avaliado"
                    // — MlAcervoItem::saudeMlNaoSeAplica()/saudeMlNaoAvaliada()
                    // distinguem os dois casos. Nunca inventar valor.
                    'health_ml' => $item['health'] ?? null,
                    'nota_ecf' => $avaliacao['nota'],
                    'nota_sinais' => json_encode($avaliacao['sinais']),
                    'motivos' => json_encode($triagem['motivos']),
                    'severidade' => $triagem['severidade'],
                    'origem' => $origem['origem'],
                    'rascunho_id' => $origem['rascunho_id'],
                    'publicacao_vendas_qty' => $origem['publicacao_vendas_qty'],
                    'publicacao_desconsiderado' => $origem['publicacao_desconsiderado'],
                    'coletado_em' => $agora,
                    'coleta_erro' => null,
                    'updated_at' => $agora,
                ];

                $this->gravarSerieDiaria($company, $mlItemId, $item, $avaliacao['nota'], $hoje);
            } catch (\Throwable $e) {
                $falhas++;
                Log::warning(
                    "[MLB Anuncios] falha ao processar item empresa {$company->id} MLB " . ($item['id'] ?? '?') . ': ' . $e->getMessage()
                );
            }
        }

        if ($linhas !== []) {
            // 3º argumento OBRIGATÓRIO e LITERAL — nunca omitir. No Laravel
            // 12 (Illuminate\Database\Eloquent\Builder::upsert()), omitir o
            // 3º argumento faz o conflito atualizar TODAS as chaves
            // presentes no array de valores. Esta camada roda todo dia
            // (D-06) E a cada clique em "Atualizar agora" (134-07 despacha
            // só este job, nunca o da camada cara) — se buybox_status/
            // visitas_30d/performance_*/detalhe_coletado_em entrassem aqui,
            // cada passagem apagaria o trabalho da rotação do D-23: a
            // economia de 587 mil para 84 mil chamadas/dia iria a zero, e
            // todo item já avaliado voltaria ao "não avaliado" do D-18 —
            // que passaria a MENTIR por omissão em vez de dizer a verdade.
            // Ordenação determinística por (company_id, ml_item_id): o lote vem
            // na ordem de retorno do multiget do ML, que varia entre execuções.
            // É HIGIENE, não a correção do deadlock — o par que de fato colide
            // é este upsert contra o update de UMA linha da camada cara, que
            // não tem "ordem de lote" a alinhar (ver
            // .planning/debug/acervo-deadlock-upsert.md). Quem serializa é o
            // AcervoEscritaLock abaixo.
            usort($linhas, static fn ($a, $b) => [$a['company_id'], $a['ml_item_id']] <=> [$b['company_id'], $b['ml_item_id']]);

            // Serializado por empresa e com retry de deadlock — as duas camadas
            // do acervo escrevem nas mesmas linhas e o ShouldBeUnique não as
            // separa (a chave dele inclui a classe do job). Ver AcervoEscritaLock.
            AcervoEscritaLock::naEmpresa(
                $company->id,
                static fn () => MlAcervoItem::upsert($linhas, ['company_id', 'ml_item_id'], self::COLUNAS_CAMADA_BARATA)
            );
        }

        return [count($linhas), $falhas];
    }

    /**
     * D-04: mapa MLB id → rascunho_id, carregado 1x por empresa. Cobre os 3
     * ids possíveis do rascunho (clássico/premium podem divergir do
     * ml_item_id "principal" — DUP-04).
     *
     * @return array<string,int>
     */
    private function mapaRascunhos(Company $company): array
    {
        $mapa = [];

        MlAnuncioRascunho::where('company_id', $company->id)
            ->get(['id', 'ml_item_id', 'ml_item_id_classico', 'ml_item_id_premium'])
            ->each(function (MlAnuncioRascunho $rascunho) use (&$mapa) {
                foreach ([$rascunho->ml_item_id, $rascunho->ml_item_id_classico, $rascunho->ml_item_id_premium] as $mlbId) {
                    if ($mlbId !== null && $mlbId !== '') {
                        $mapa[$mlbId] = $rascunho->id;
                    }
                }
            });

        return $mapa;
    }

    /**
     * D-04: mapa Publicacao.mlb_code → [vendas_qty, desconsiderado],
     * carregado 1x por empresa. O scope considerado() NÃO entra aqui — a
     * tela LISTA, não conta (Publicacao.php:171). Publicacao não tem
     * company_id direto: o vínculo é via MlbEmpresa.company_id.
     *
     * @return array<string,array{vendas_qty:?int,desconsiderado:bool}>
     */
    private function mapaPublicacoes(Company $company): array
    {
        $mlbEmpresaId = MlbEmpresa::where('company_id', $company->id)->value('id');

        if ($mlbEmpresaId === null) {
            return [];
        }

        return Publicacao::where('mlb_empresa_id', $mlbEmpresaId)
            ->get(['mlb_code', 'vendas_qty', 'desconsiderado'])
            ->keyBy('mlb_code')
            ->map(fn (Publicacao $publicacao) => [
                'vendas_qty' => $publicacao->vendas_qty,
                'desconsiderado' => (bool) $publicacao->desconsiderado,
            ])
            ->all();
    }

    /**
     * D-04: selo de origem — ecf (rascunho deste módulo) > time (Publicacao,
     * time publicou) > legado (nenhum dos dois, cliente já tinha).
     *
     * @return array{origem:string,rascunho_id:?int,publicacao_vendas_qty:?int,publicacao_desconsiderado:bool}
     */
    private function resolverOrigem(string $mlItemId, array $rascunhoPorMlItemId, array $publicacaoPorMlbCode): array
    {
        if (isset($rascunhoPorMlItemId[$mlItemId])) {
            return [
                'origem' => MlAcervoItem::ORIGEM_ECF,
                'rascunho_id' => $rascunhoPorMlItemId[$mlItemId],
                'publicacao_vendas_qty' => null,
                'publicacao_desconsiderado' => false,
            ];
        }

        if (isset($publicacaoPorMlbCode[$mlItemId])) {
            $publicacao = $publicacaoPorMlbCode[$mlItemId];

            return [
                'origem' => MlAcervoItem::ORIGEM_TIME,
                'rascunho_id' => null,
                'publicacao_vendas_qty' => $publicacao['vendas_qty'],
                'publicacao_desconsiderado' => $publicacao['desconsiderado'],
            ];
        }

        return [
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'rascunho_id' => null,
            'publicacao_vendas_qty' => null,
            'publicacao_desconsiderado' => false,
        ];
    }

    /**
     * D-07b — série diária ENXUTA: só grava linha nova quando o ESTADO do
     * item (sold_quantity, nota_ecf, health_ml) difere da última linha
     * conhecida. Gravar sempre chegaria a ~45 milhões de linhas em regime
     * (500 mil itens × 90 dias de retenção) — a maioria idêntica ao dia
     * anterior em contas de catálogo estático (autopeças, que são as
     * maiores). A série tem buracos por desenho: sold_quantity/nota_ecf/
     * health_ml são ESTADO e o consumidor (134-10) preenche para frente;
     * `visitas` é FLUXO (campo da camada cara — NUNCA escrito aqui) e um
     * buraco ali é ausência real de coleta, exibido como buraco mesmo
     * (connectNulls={false} no gráfico, 134-UI-SPEC.md).
     *
     * updateOrCreate por (company_id, ml_item_id, data): reexecuções no
     * MESMO dia atualizam a mesma linha em vez de duplicar. `visitas` NUNCA
     * entra no array de valores — passar null aqui apagaria a visita que a
     * camada cara já tivesse gravado no mesmo dia.
     */
    private function gravarSerieDiaria(Company $company, string $mlItemId, array $item, int $notaEcf, string $hoje): void
    {
        $soldQuantity = isset($item['sold_quantity']) ? (int) $item['sold_quantity'] : null;
        $healthMl = isset($item['health']) && $item['health'] !== null ? (float) $item['health'] : null;

        $ultima = MlAcervoMetricaDiaria::where('company_id', $company->id)
            ->where('ml_item_id', $mlItemId)
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->first(['id', 'data', 'sold_quantity', 'nota_ecf', 'health_ml']);

        $jaExisteHoje = $ultima !== null && $ultima->data->toDateString() === $hoje;

        if (! $jaExisteHoje) {
            $healthAnterior = $ultima?->health_ml !== null ? round((float) $ultima->health_ml, 2) : null;
            $healthAtual = $healthMl !== null ? round($healthMl, 2) : null;

            $mudou = $ultima === null
                || $ultima->sold_quantity !== $soldQuantity
                || $ultima->nota_ecf !== $notaEcf
                || $healthAnterior !== $healthAtual;

            if (! $mudou) {
                // ESTADO igual ao último dia conhecido — nenhuma linha nova
                // (é exatamente o que evita os ~45 milhões em regime).
                return;
            }
        }

        $valores = [
            'sold_quantity' => $soldQuantity,
            'nota_ecf'      => $notaEcf,
            'health_ml'     => $healthMl,
        ];

        // NÃO usar `updateOrCreate(['data' => $hoje], ...)` aqui. A coluna
        // `data` tem cast `date`, e o setter do Eloquent grava o formato
        // completo do grammar (`Y-m-d H:i:s`) mesmo assim — só o getter
        // trunca na leitura. A comparação SQL crua
        // `'2026-08-10' = '2026-08-10 00:00:00'` nunca bate, então a linha de
        // hoje jamais é encontrada e toda reexecução no mesmo dia vira um
        // INSERT que colide com o UNIQUE (company_id, ml_item_id, data).
        // O `try/catch` fail-open de `processarLote()` engolia isso: a
        // contagem de linhas continuava "certa" pelo motivo errado, e o
        // sintoma só aparecia como Log::warning. Achado durante o plano
        // 134-05 — ver deferred-items.md.
        //
        // `$jaExisteHoje` já foi decidido acima por comparação em memória de
        // objetos Carbon, que é imune ao problema de formato. Reusar.
        if ($jaExisteHoje) {
            $ultima->forceFill($valores)->save();

            return;
        }

        MlAcervoMetricaDiaria::create($valores + [
            'company_id' => $company->id,
            'ml_item_id' => $mlItemId,
            'data'       => $hoje,
        ]);
    }
}
