<?php

namespace App\Services\Mlb\Acervo;

use App\Models\Company;
use App\Services\MercadoLivreService;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use Illuminate\Support\Facades\Log;

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

        foreach ($this->enumerarIds($company) as $id) {
            $loteIds[] = $id;

            if (count($loteIds) >= $loteSize) {
                $lotes++;
                $bodies = $this->buscarLotes($company, $loteIds);
                $itens += count($bodies);
                $loteIds = [];
            }
        }

        if ($loteIds !== []) {
            $lotes++;
            $bodies = $this->buscarLotes($company, $loteIds);
            $itens += count($bodies);
        }

        // TODO (Task 2 deste plano): calcular nota/triagem por item, resolver
        // o selo de origem (D-04) e fazer o upsert da linha corrente + série
        // diária. Por ora esta camada só enumera e busca — nenhuma escrita.
        return ['itens' => $itens, 'lotes' => $lotes, 'falhas' => $falhas];
    }
}
