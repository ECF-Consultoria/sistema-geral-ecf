<?php

namespace App\Services\Portal\Estrutura;

use App\Jobs\ImportarAnunciosMlEstruturaJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\MlAcervoItem;
use App\Services\MercadoLivreService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Os anúncios que a empresa JÁ TEM no Mercado Livre, trazidos pelo OAuth — o
 * cliente não digita a lista de produtos: ela sai dos anúncios.
 *
 * ### Duas portas, uma regra
 * 1. **Puxar** (a regra): lê os anúncios mais vendidos que ainda não estão no
 *    módulo, cria uma oferta por SKU e junta nela TODOS os anúncios Clássico e
 *    Premium com aquele SKU. Os anúncios passam pelo MESMO
 *    `ColagemAnunciosService` da colagem (atualiza pelo MLB, não duplica):
 *    não há segundo mecanismo de casamento para manter.
 * 2. **Buscar e ligar** (a exceção): quando o SKU do anúncio é diferente do da
 *    oferta, ou não existe, a pessoa procura o anúncio pelo título/MLB no
 *    acervo local e liga à oferta com um clique.
 *
 * ### Por que o SKU e nada mais (medido em 24/09 e 25/09 com anúncios reais)
 * Nos anúncios reais da Fase 134 e nos 40 mais vendidos da CAMILLOPARTS (#131),
 * os anúncios do mesmo produto tinham o MESMO `SELLER_SKU` e
 * `user_product_id`/`family_id` DIFERENTES — cada anúncio tem o seu. Na #131 o
 * SKU `30069Full` tem 12 anúncios, 6 Clássico e 6 Premium, cada um com um
 * título. O SKU é o único vínculo que o próprio seller controla.
 *
 * ### Por lote dos mais vendidos, não a conta inteira
 * A #131 tem ~100 mil anúncios. Ler tudo seriam ~5.000 multigets (mais de uma
 * hora) e milhares de ofertas de uma vez. Cada "Puxar" traz até
 * {@see self::LOTE_SKUS} SKUs, dos anúncios mais vendidos que ainda não estão
 * no módulo; o próximo "Puxar" continua de onde parou, porque o que já foi
 * importado sai da lista de candidatos. Decisão do usuário em 25/09.
 *
 * ### Em fatias, porque a fila reentrega Job acima de 90 s
 * A leitura de um lote passa de 90 s (500 buscas por SKU a ~130 ms, mais os
 * multigets), e a fila `database` reentrega o Job reservado há mais que
 * `retry_after` — duas leituras da mesma rodada ao mesmo tempo. Cada Job
 * trabalha no máximo {@see self::ORCAMENTO_SEGUNDOS} s, guarda o progresso no
 * cache e despacha o seguinte. A `rodada` impede que uma leitura antiga,
 * ainda na fila, escreva por cima de uma nova.
 *
 * ### O SKU vem da API, não do acervo
 * `ml_acervo_itens` não guarda SKU, e acrescentar a coluna seria migration em
 * tabela com dado em produção (fase GSD obrigatória). O acervo dá a ORDEM (mais
 * vendidos) e os dados do anúncio; o SKU e a logística vêm do multiget.
 */
class AnunciosMercadoLivreService
{
    /** Estado da leitura por empresa: lendo → pronto | erro. Uma hora basta para revisar a prévia. */
    private const CHAVE = 'estrutura:importacao-ml:';
    private const VALIDADE_MINUTOS = 60;

    /** Quantos SKUs cada "Puxar" traz. */
    public const LOTE_SKUS = 500;

    /** Quantos anúncios, no máximo, uma leitura percorre procurando SKUs novos (250 multigets). */
    private const MAX_CANDIDATOS = 5000;

    /** Quanto cada Job trabalha antes de passar a vez — bem abaixo do `retry_after` de 90 s. */
    public const ORCAMENTO_SEGUNDOS = 45;

    /** Leitura sem progresso há mais que isso morreu (worker reiniciado no meio). */
    private const PARADA_MINUTOS = 3;

    /** Sem o teto de 5.000 da colagem manual: são até 500 anúncios POR SKU (10 páginas × 50). */
    private const MAX_LINHAS = 200000;

    /** Busca por SKU: 50 por página, no máximo 10 páginas (500 anúncios) por SKU. */
    private const POR_PAGINA = 50;
    private const PAGINAS_POR_SKU = 10;

    /** Campos pedidos no multiget — só o que a colagem e a oferta usam. */
    private const CAMPOS = 'id,title,listing_type_id,status,catalog_listing,attributes,variations,seller_custom_field,shipping';

    public const TIPOS_ML = [
        'gold_special' => 'Clássico',
        'gold_pro'     => 'Premium',
    ];

    public const STATUS_ML = [
        'active'       => 'Ativo',
        'paused'       => 'Pausado',
        'under_review' => 'Pausado',
        'closed'       => 'Inativo',
        'inactive'     => 'Inativo',
    ];

    public function __construct(
        private MercadoLivreService $ml,
        private ColagemAnunciosService $colagem,
        private EstruturaAnuncioService $anuncios,
        private EstruturaOfertaService $ofertas,
    ) {
    }

    public static function conectado(Company $empresa): bool
    {
        return $empresa->mlToken?->status === 'active';
    }

    // ═══ Puxar (a regra) ════════════════════════════════════════════════════

    /**
     * Começa uma leitura em segundo plano. As ofertas cadastradas à mão que
     * ainda não têm anúncio entram primeiro no lote: quem cadastrou a oferta
     * antes de puxar recebe os anúncios dela.
     */
    public function iniciar(Company $empresa, int $loteSkus = self::LOTE_SKUS): void
    {
        if (! self::conectado($empresa)) {
            throw ValidationException::withMessages([
                'importacao' => 'A conta do Mercado Livre desta empresa não está conectada. Conecte pelo Onboarding.',
            ]);
        }

        // Dois cliques (ou duas abas) não abrem duas leituras.
        if ($this->lendoAinda(Cache::get(self::CHAVE.$empresa->id))) {
            return;
        }

        $lote = [];
        EstruturaOferta::where('company_id', $empresa->id)
            ->whereDoesntHave('anuncios')
            ->orderBy('id')->limit($loteSkus)->pluck('sku')
            ->each(function ($sku) use (&$lote) {
                $chave = EstruturaOferta::normalizarSku($sku);
                if ($chave !== null) {
                    $lote[$chave] ??= ['sku' => $sku, 'nome' => null, 'logistica' => null];
                }
            });

        $rodada = (string) Str::uuid();

        $this->guardar($empresa, [
            'estado'      => 'lendo',
            'rodada'      => $rodada,
            'limite'      => $loteSkus,
            'etapa'       => 'procurar',
            'cursor'      => 0,
            'acabou'      => false,
            'lote'        => $lote,
            'ids'         => [],
            'sem_sku'     => [],
            'feitos'      => 0,
        ]);

        ImportarAnunciosMlEstruturaJob::dispatch($empresa->id, $rodada);
    }

    /**
     * Uma fatia da leitura — o que o Job faz. Trabalha até o orçamento acabar
     * e guarda o progresso. Devolve `true` quando não há mais o que fazer
     * (pronto, erro, ou a rodada já não é esta).
     */
    public function passo(Company $empresa, string $rodada, float $orcamento = self::ORCAMENTO_SEGUNDOS): bool
    {
        $estado = Cache::get(self::CHAVE.$empresa->id);

        if (($estado['estado'] ?? null) !== 'lendo' || ($estado['rodada'] ?? null) !== $rodada) {
            return true;
        }

        $fim = microtime(true) + $orcamento;

        try {
            // Pelo menos uma unidade de trabalho por fatia: orçamento curto não trava a leitura.
            do {
                match ($estado['etapa']) {
                    'procurar' => $this->procurar($empresa, $estado),
                    'irmaos'   => $this->irmaos($empresa, $estado),
                };

                if ($estado['etapa'] === 'fechar') {
                    $this->fechar($empresa, $estado);

                    return true;
                }
            } while (microtime(true) < $fim);

            $this->guardar($empresa, $estado);

            return false;
        } catch (\Throwable $e) {
            Log::error("[Estrutura] leitura do ML falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            Cache::put(self::CHAVE.$empresa->id, [
                'estado' => 'erro',
                'erro'   => 'Não foi possível ler os anúncios no Mercado Livre agora. Tente de novo em alguns minutos.',
            ], now()->addMinutes(self::VALIDADE_MINUTOS));

            return true;
        }
    }

    /**
     * Etapa 1, uma unidade: os próximos 20 anúncios mais vendidos que ainda
     * não estão no módulo → o SKU de cada um. SKU novo entra no lote com o
     * título do anúncio mais vendido (o primeiro a aparecer) e a logística dele.
     */
    private function procurar(Company $empresa, array &$e): void
    {
        $candidatos = count($e['lote']) < $e['limite'] && $e['cursor'] < self::MAX_CANDIDATOS
            ? $this->candidatos($empresa)->offset($e['cursor'])->limit(20)->pluck('ml_item_id')->all()
            : [];

        if ($candidatos === []) {
            $e['acabou'] = count($e['lote']) < $e['limite'] && $e['cursor'] < self::MAX_CANDIDATOS;
            $e['etapa'] = 'irmaos';

            return;
        }

        $e['cursor'] += count($candidatos);
        $corpos = $this->multiget($empresa, $candidatos);

        foreach ($candidatos as $id) {
            $corpo = $corpos[$id] ?? null;
            if ($corpo === null || self::linhaDoAnuncio($corpo) === null) {
                continue;
            }

            $sku = self::skuDoAnuncio($corpo);
            if ($sku === null) {
                // Sem SKU não junta com nada: vai para "aguardando oferta" e se liga à mão.
                $e['sem_sku'][$id] = true;
                continue;
            }

            $chave = EstruturaOferta::normalizarSku($sku);
            if (! isset($e['lote'][$chave])) {
                if (count($e['lote']) >= $e['limite']) {
                    continue;
                }
                $e['lote'][$chave] = ['sku' => $sku, 'nome' => $corpo['title'] ?? null, 'logistica' => self::logisticaDoAnuncio($corpo)];
            }

            // O próprio anúncio entra mesmo que a busca por SKU não o devolva.
            $e['ids'][$id] ??= $chave;
        }
    }

    /** Etapa 2, uma unidade: os anúncios de UM SKU do lote — os irmãos Clássico e Premium. */
    private function irmaos(Company $empresa, array &$e): void
    {
        $chaves = array_keys($e['lote']);

        if ($e['feitos'] >= count($chaves)) {
            $e['etapa'] = 'fechar';

            return;
        }

        $chave = $chaves[$e['feitos']];
        foreach ($this->idsDoSku($empresa, $e['lote'][$chave]['sku']) as $id) {
            $e['ids'][$id] ??= $chave;
        }
        $e['feitos']++;
    }

    /** Etapa 3: o texto da colagem, as ofertas a criar e a contagem por tipo. */
    private function fechar(Company $empresa, array $e): void
    {
        $detalhes = $this->detalhes($empresa, [...array_keys($e['ids']), ...array_keys($e['sem_sku'])]);

        $linhas = [];
        $contagem = [];
        $ignorados = 0;

        foreach ($e['ids'] as $id => $chave) {
            $linha = isset($detalhes[$id]) ? self::linhaDoAnuncio($detalhes[$id]) : null;
            if ($linha === null) {
                $ignorados++;
                continue;
            }
            $linha['sku'] = $e['lote'][$chave]['sku'];
            $linhas[] = $linha;
            $campo = $linha['tipo'] === 'Clássico' ? 'classicos' : 'premiums';
            $contagem[$chave][$campo] = ($contagem[$chave][$campo] ?? 0) + 1;
        }

        foreach (array_keys($e['sem_sku']) as $id) {
            if (isset($detalhes[$id]) && ($linha = self::linhaDoAnuncio($detalhes[$id])) !== null) {
                $linhas[] = [...$linha, 'sku' => null];
            }
        }

        Cache::put(self::CHAVE.$empresa->id, [
            'estado'      => 'pronto',
            'texto'       => self::texto($linhas),
            'total'       => count($linhas),
            'ignorados'   => $ignorados,
            'skus'        => count($e['lote']),
            'sem_anuncio' => count(array_diff_key($e['lote'], $contagem)),
            'sem_sku'     => count($e['sem_sku']),
            'lidos'       => $e['cursor'],
            'acabou'      => $e['acabou'],
            'limite'      => $e['limite'],
            'ofertas'     => array_filter($e['lote'], fn ($o) => $o['nome'] !== null),
            'contagem'    => $contagem,
            'lido_em'     => now()->toIso8601String(),
        ], now()->addMinutes(self::VALIDADE_MINUTOS));
    }

    /**
     * Os anúncios Clássico/Premium da empresa que ainda não estão no módulo
     * (nem como anúncio, nem aguardando oferta), dos mais vendidos para os
     * menos. Encerrados ficam de fora: não se publica de novo o que foi fechado.
     */
    private function candidatos(Company $empresa): Builder
    {
        return MlAcervoItem::where('company_id', $empresa->id)
            ->whereIn('listing_type_id', array_keys(self::TIPOS_ML))
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['closed', 'inactive']))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('estrutura_anuncios as a')
                ->join('estrutura_ofertas as o', 'o.id', '=', 'a.oferta_id')
                ->where('o.company_id', $empresa->id)
                ->whereColumn('a.codigo_mlb', 'ml_acervo_itens.ml_item_id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('estrutura_anuncios_espera as w')
                ->where('w.company_id', $empresa->id)
                ->whereColumn('w.codigo_mlb', 'ml_acervo_itens.ml_item_id'))
            ->orderByDesc('sold_quantity')->orderBy('id');
    }

    /**
     * Os MLB com este SKU na conta, página a página. Teto de páginas por SKU:
     * um SKU com centenas de anúncios é raro, e sem teto um SKU genérico
     * ("1", "A") poderia varrer a conta inteira por outra porta.
     *
     * @return array<int, string>
     */
    private function idsDoSku(Company $empresa, string $sku): array
    {
        $mlUserId = (string) $empresa->mlToken->ml_user_id;
        $ids = [];
        $offset = 0;

        for ($pagina = 0; $pagina < self::PAGINAS_POR_SKU; $pagina++) {
            $r = $this->ml->get($empresa, "/users/{$mlUserId}/items/search", [
                'seller_sku' => $sku, 'limit' => self::POR_PAGINA, 'offset' => $offset,
            ]);

            $lote = $r['results'] ?? [];
            array_push($ids, ...$lote);
            $offset += count($lote);

            if ($lote === [] || $offset >= (int) ($r['paging']['total'] ?? 0)) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * O corpo de cada MLB pedido, pela API (20 por chamada).
     *
     * @param  array<int, string>  $ids
     * @return array<string, array> MLB → corpo
     */
    private function multiget(Company $empresa, array $ids): array
    {
        $porId = [];
        $pedidos = array_flip($ids);

        foreach (array_chunk($ids, 20) as $lote) {
            $resposta = $this->ml->get($empresa, '/items', ['ids' => implode(',', $lote), 'attributes' => self::CAMPOS]);
            foreach ($resposta as $envelope) {
                $id = $envelope['body']['id'] ?? null;
                if (($envelope['code'] ?? null) === 200 && $id !== null && isset($pedidos[$id])) {
                    $porId[$id] = $envelope['body'];
                }
            }
        }

        return $porId;
    }

    /**
     * Tipo, status, título e catálogo de cada MLB — do acervo local, e da API
     * só para os que ainda não estão nele (publicados depois do sync da madrugada).
     *
     * @param  array<int, string>  $ids
     * @return array<string, array> MLB → corpo no formato da API
     */
    private function detalhes(Company $empresa, array $ids): array
    {
        $porId = [];

        foreach (array_chunk($ids, 1000) as $bloco) {
            MlAcervoItem::where('company_id', $empresa->id)->whereIn('ml_item_id', $bloco)
                ->get(['ml_item_id', 'title', 'listing_type_id', 'status', 'catalog_listing'])
                ->each(function (MlAcervoItem $i) use (&$porId) {
                    $porId[$i->ml_item_id] = [
                        'id' => $i->ml_item_id, 'title' => $i->title, 'listing_type_id' => $i->listing_type_id,
                        'status' => $i->status, 'catalog_listing' => (bool) $i->catalog_listing,
                    ];
                });
        }

        return $porId + $this->multiget($empresa, array_values(array_diff($ids, array_keys($porId))));
    }

    private function guardar(Company $empresa, array $estado): void
    {
        Cache::put(self::CHAVE.$empresa->id, [...$estado, 'atualizado_em' => now()->toIso8601String()], now()->addMinutes(self::VALIDADE_MINUTOS));
    }

    private function lendoAinda(?array $estado): bool
    {
        return ($estado['estado'] ?? null) === 'lendo'
            && Carbon::parse($estado['atualizado_em'])->gt(now()->subMinutes(self::PARADA_MINUTOS));
    }

    /**
     * O estado para a tela — nunca o estado interno inteiro (a lista de MLBs
     * da leitura não vai para o navegador). Quando pronto, já vem com as
     * ofertas a criar e a prévia dos anúncios, a MESMA da colagem, no modo
     * acrescentar/atualizar (puxar nunca remove).
     */
    public function estado(Company $empresa): array
    {
        $estado = Cache::get(self::CHAVE.$empresa->id) ?? ['estado' => 'nenhum'];

        if ($estado['estado'] === 'lendo') {
            if (! $this->lendoAinda($estado)) {
                return ['estado' => 'erro', 'erro' => 'A leitura parou no meio. Clique em "Puxar de novo".'];
            }

            return [
                'estado'     => 'lendo',
                'etapa'      => $estado['etapa'],
                'lidos'      => $estado['cursor'],
                'skus'       => count($estado['lote']),
                'procurados' => $estado['feitos'],
            ];
        }

        if ($estado['estado'] !== 'pronto') {
            return $estado;
        }

        $novas = $this->ofertasNovas($empresa, $estado);
        $pares = collect($novas)->filter(fn ($o) => $o['classicos'] > 0 && $o['premiums'] > 0)->count();

        return [
            'estado'        => 'pronto',
            'total'         => $estado['total'],
            'ignorados'     => $estado['ignorados'],
            'skus'          => $estado['skus'],
            'sem_anuncio'   => $estado['sem_anuncio'],
            'sem_sku'       => $estado['sem_sku'],
            'lidos'         => $estado['lidos'],
            'acabou'        => $estado['acabou'],
            'limite'        => $estado['limite'],
            'lido_em'       => $estado['lido_em'],
            'ofertas_novas' => [
                'total'  => count($novas),
                'pares'  => $pares,
                'itens'  => array_slice($novas, 0, 200),
            ],
            'previa' => $this->colagem->previa($empresa, $estado['texto'], ColagemAnunciosService::MODO_ACRESCENTAR,
                self::MAX_LINHAS, array_column($novas, 'sku')),
        ];
    }

    /**
     * As ofertas que a leitura vai criar: SKU do lote que nenhuma oferta da
     * empresa tem AGORA — conferido a cada chamada, porque entre a leitura e o
     * confirmar alguém pode ter criado a oferta à mão.
     *
     * @return array<int, array{sku: string, nome: ?string, logistica: ?string, logistica_rotulo: ?string, classicos: int, premiums: int}>
     */
    private function ofertasNovas(Company $empresa, array $estado): array
    {
        $existentes = EstruturaOferta::where('company_id', $empresa->id)->pluck('sku')
            ->map(fn ($s) => EstruturaOferta::normalizarSku($s))->flip();

        $novas = [];
        foreach ($estado['ofertas'] as $chave => $o) {
            if (isset($existentes[$chave]) || mb_strlen($o['sku']) > 120) {
                continue;
            }
            $novas[] = [
                'sku'              => $o['sku'],
                'nome'             => $o['nome'] === null ? null : mb_substr($o['nome'], 0, 255),
                'logistica'        => $o['logistica'],
                'logistica_rotulo' => EstruturaOferta::LOGISTICAS[$o['logistica']] ?? null,
                'classicos'        => $estado['contagem'][$chave]['classicos'] ?? 0,
                'premiums'         => $estado['contagem'][$chave]['premiums'] ?? 0,
            ];
        }

        return $novas;
    }

    /**
     * Grava o que a prévia mostrou: cria as ofertas novas (Fase 1, simples —
     * combo e kit o cliente ajusta; não se adivinha pelo SKU) e passa os
     * anúncios pela colagem, refeita a partir do texto guardado no servidor.
     *
     * @return array<string, int>
     */
    public function aplicar(Company $empresa, AtorDoPortal $ator): array
    {
        $estado = Cache::get(self::CHAVE.$empresa->id);

        if (($estado['estado'] ?? null) !== 'pronto') {
            throw ValidationException::withMessages(['importacao' => 'Leia os anúncios do Mercado Livre de novo antes de confirmar.']);
        }

        $totais = DB::transaction(function () use ($empresa, $ator, $estado) {
            $novas = $this->ofertasNovas($empresa, $estado);

            foreach ($novas as $o) {
                EstruturaOferta::create([
                    'company_id' => $empresa->id,
                    'sku'        => $o['sku'],
                    'fase'       => EstruturaOferta::FASE_SIMPLES,
                    'nome'       => $o['nome'],
                    'logistica'  => $o['logistica'],
                ]);
            }

            if ($novas) {
                RegistroEstrutura::registrar($ator, $empresa, null, 'ofertas_puxadas_ml',
                    count($novas).' oferta(s) criada(s) a partir dos anúncios do Mercado Livre',
                    ['skus' => array_column($novas, 'sku')]);
            }

            $totais = $this->colagem->aplicar($empresa, $estado['texto'], ColagemAnunciosService::MODO_ACRESCENTAR, $ator, self::MAX_LINHAS);

            // Linha antiga da espera com o SKU de uma oferta que acabou de nascer.
            $this->ofertas->varrerEspera($empresa, array_column($novas, 'sku'));

            return [...$totais, 'ofertas' => count($novas)];
        });

        Cache::forget(self::CHAVE.$empresa->id);

        return $totais;
    }

    /**
     * A logística do anúncio no vocabulário da planilha: Full, Flex, ME1 ou
     * Mercado Envios. `null` quando o ML não diz (`not_specified`) — a pessoa
     * escolhe depois; não se chuta.
     */
    public static function logisticaDoAnuncio(array $item): ?string
    {
        $envio = $item['shipping'] ?? [];

        return match (true) {
            ($envio['logistic_type'] ?? null) === 'fulfillment'  => 'full',
            ($envio['logistic_type'] ?? null) === 'self_service' => 'flex',
            ($envio['mode'] ?? null) === 'me1'                    => 'transportadora_me1',
            ($envio['mode'] ?? null) === 'me2'                    => 'mercado_envios',
            default                                                => null,
        };
    }

    /**
     * Um anúncio da API → uma linha da colagem, ou `null` se não é Clássico
     * nem Premium (o método da aula só trabalha com os dois).
     *
     * SKU, em ordem: o atributo `SELLER_SKU`; o campo antigo
     * `seller_custom_field`; e, em anúncio com variações, o SKU das variações
     * quando TODAS têm o mesmo — senão fica sem SKU e vai para a espera.
     *
     * @return array{sku: ?string, mlb: string, titulo: string, tipo: string, catalogo: bool, status: string}|null
     */
    public static function linhaDoAnuncio(array $item): ?array
    {
        $tipo = self::TIPOS_ML[$item['listing_type_id'] ?? ''] ?? null;
        if ($tipo === null || empty($item['id'])) {
            return null;
        }

        return [
            'sku'      => self::skuDoAnuncio($item),
            'mlb'      => $item['id'],
            'titulo'   => (string) ($item['title'] ?? ''),
            'tipo'     => $tipo,
            'catalogo' => (bool) ($item['catalog_listing'] ?? false),
            'status'   => self::STATUS_ML[$item['status'] ?? ''] ?? 'Ativo',
        ];
    }

    public static function skuDoAnuncio(array $item): ?string
    {
        $doAtributo = fn (array $attrs) => collect($attrs)->firstWhere('id', 'SELLER_SKU')['value_name'] ?? null;

        $sku = $doAtributo($item['attributes'] ?? []) ?: ($item['seller_custom_field'] ?? null);
        if ($sku) {
            return trim($sku);
        }

        $daVariacao = collect($item['variations'] ?? [])
            ->map(fn ($v) => $doAtributo($v['attributes'] ?? []) ?: ($v['seller_custom_field'] ?? null))
            ->filter()->map(fn ($s) => trim($s))->unique()->values();

        return $daVariacao->count() === 1 ? $daVariacao->first() : null;
    }

    /** As linhas no formato da colagem, com o cabeçalho da aba Anúncios da planilha. */
    public static function texto(array $linhas): string
    {
        $limpa = fn (?string $s) => trim(str_replace(["\t", "\r", "\n"], ' ', (string) $s));

        return collect($linhas)
            ->map(fn ($l) => implode("\t", [$limpa($l['sku']), $l['mlb'], $limpa($l['titulo']), $l['tipo'], $l['catalogo'] ? 'Sim' : 'Não', $l['status']]))
            ->prepend("SKU\tCÓDIGO MLB\tTÍTULO DO ANÚNCIO\tTIPO\tCATÁLOGO?\tSTATUS")
            ->implode("\n");
    }

    // ═══ Buscar e ligar (a exceção) ═════════════════════════════════════════

    /**
     * Os anúncios da empresa no acervo local que casam com a busca (título ou
     * MLB), com a informação de onde cada um já está ligado.
     */
    public function buscar(Company $empresa, string $busca, ?string $tipo = null): array
    {
        $busca = trim($busca);

        $consulta = MlAcervoItem::where('company_id', $empresa->id)
            ->whereIn('listing_type_id', array_keys(self::TIPOS_ML));

        if ($tipo !== null) {
            $consulta->where('listing_type_id', $tipo === EstruturaAnuncio::TIPO_CLASSICO ? 'gold_special' : 'gold_pro');
        }

        if ($busca !== '') {
            $mlb = EstruturaAnuncio::normalizarMlb($busca);
            $consulta->where(fn ($q) => $q->where('title', 'like', '%'.$busca.'%')
                ->orWhere('ml_item_id', 'like', '%'.strtoupper($busca).'%')
                ->when($mlb, fn ($q) => $q->orWhere('ml_item_id', $mlb)));
        }

        $itens = $consulta->orderBy('title')->limit(30)
            ->get(['ml_item_id', 'title', 'listing_type_id', 'status', 'catalog_listing', 'thumbnail', 'permalink']);

        $mlbs = $itens->pluck('ml_item_id')->all();
        $ligados = EstruturaAnuncio::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_anuncios.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->whereIn('estrutura_anuncios.codigo_mlb', $mlbs)
            ->pluck('o.sku', 'estrutura_anuncios.codigo_mlb');
        $naEspera = EstruturaAnuncioEspera::where('company_id', $empresa->id)->whereIn('codigo_mlb', $mlbs)->pluck('codigo_mlb')->flip();

        return [
            'total_acervo' => MlAcervoItem::where('company_id', $empresa->id)->count(),
            'itens' => $itens->map(fn (MlAcervoItem $i) => [
                'mlb'        => $i->ml_item_id,
                'titulo'     => $i->title,
                'tipo'       => self::TIPOS_ML[$i->listing_type_id],
                'status'     => self::STATUS_ML[$i->status] ?? $i->status,
                'catalogo'   => (bool) $i->catalog_listing,
                'thumbnail'  => $i->thumbnail,
                'permalink'  => $i->permalink,
                'ligado_a'   => $ligados[$i->ml_item_id] ?? null,
                'na_espera'  => isset($naEspera[$i->ml_item_id]),
            ])->all(),
        ];
    }

    /**
     * Liga um anúncio do acervo à oferta. Os dados vêm do REGISTRO do acervo,
     * nunca do navegador. Se o anúncio estava aguardando oferta, sai de lá; se
     * já está em OUTRA oferta, recusa — mudar de oferta é editar, não ligar.
     */
    public function ligar(EstruturaOferta $oferta, string $mlItemId, AtorDoPortal $ator): EstruturaAnuncio
    {
        $empresa = $oferta->company;
        $item = MlAcervoItem::where('company_id', $empresa->id)->where('ml_item_id', $mlItemId)->firstOrFail();

        $linha = self::linhaDoAnuncio([
            'id' => $item->ml_item_id, 'title' => $item->title, 'listing_type_id' => $item->listing_type_id,
            'status' => $item->status, 'catalog_listing' => $item->catalog_listing,
        ]);

        if ($linha === null) {
            throw ValidationException::withMessages(['ml_item_id' => 'Este anúncio não é Clássico nem Premium.']);
        }

        $dados = [
            'tipo'       => $linha['tipo'] === 'Clássico' ? EstruturaAnuncio::TIPO_CLASSICO : EstruturaAnuncio::TIPO_PREMIUM,
            'codigo_mlb' => $linha['mlb'],
            'titulo'     => $linha['titulo'],
            'catalogo'   => $linha['catalogo'],
            'status'     => ['Ativo' => 'ativo', 'Pausado' => 'pausado', 'Inativo' => 'inativo'][$linha['status']],
        ];

        $dono = $this->anuncios->localizarMlb($empresa, $linha['mlb']);

        if ($dono instanceof EstruturaAnuncioEspera) {
            return $this->anuncios->vincularEspera($dono, $oferta, $ator);
        }

        if ($dono instanceof EstruturaAnuncio) {
            throw ValidationException::withMessages([
                'ml_item_id' => $dono->oferta_id === $oferta->id
                    ? 'Este anúncio já está nesta oferta.'
                    : "Este anúncio já está na oferta {$dono->oferta->sku}.",
            ]);
        }

        return $this->anuncios->cadastrar($oferta, $dados, $ator);
    }
}
