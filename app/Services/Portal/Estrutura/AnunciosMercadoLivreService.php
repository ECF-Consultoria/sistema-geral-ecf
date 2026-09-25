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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Os anúncios que a empresa JÁ TEM no Mercado Livre, trazidos pelo OAuth — o
 * passo 2 da aula ("cole aqui os anúncios que você já tem") sem colar nada.
 *
 * ### Duas portas, uma regra
 * 1. **Importar** (a regra): para cada SKU de oferta, pergunta ao ML quais
 *    anúncios têm aquele SKU, e passa as linhas pelo MESMO
 *    `ColagemAnunciosService` da colagem (atualiza pelo MLB, não duplica).
 *    Não há segundo mecanismo de casamento para manter.
 * 2. **Buscar e ligar** (a exceção): quando o SKU do anúncio é diferente do da
 *    oferta, ou não existe, a pessoa procura o anúncio pelo título/MLB no
 *    acervo local e liga à oferta com um clique.
 *
 * ### Por que o SKU e nada mais (medido em 24/09 com anúncios reais)
 * Nos anúncios reais da Fase 134, os pares Clássico + Premium do mesmo produto
 * tinham o MESMO `SELLER_SKU` e `user_product_id`/`family_name` DIFERENTES — cada
 * anúncio tem o seu. O título muda de propósito ("mesmo SKU, títulos
 * diferentes", regra de ouro da aula). O SKU é o único vínculo que o próprio
 * seller controla; o que não casar por ele fica para a busca manual.
 *
 * ### O SKU vem da API, não do acervo
 * `ml_acervo_itens` não guarda SKU, e acrescentar a coluna seria migration em
 * tabela com dado em produção (fase GSD obrigatória). A importação pergunta à
 * API pelo `seller_sku`, em Job; o resto dos dados vem do acervo. A busca
 * manual usa o acervo como ele está — título, MLB, tipo e status bastam.
 */
class AnunciosMercadoLivreService
{
    /** Estado da importação por empresa: lendo → pronto | erro. Uma hora basta para revisar a prévia. */
    private const CHAVE = 'estrutura:importacao-ml:';
    private const VALIDADE_MINUTOS = 60;

    /** Sem o teto de 5.000 da colagem manual: são até 500 anúncios POR oferta (10 páginas × 50). */
    private const MAX_LINHAS = 200000;

    /** Busca por SKU: 50 por página, no máximo 10 páginas (500 anúncios) por SKU. */
    private const POR_PAGINA = 50;
    private const PAGINAS_POR_SKU = 10;

    /** Campos pedidos no multiget — só o que a colagem usa. */
    private const CAMPOS = 'id,title,listing_type_id,status,catalog_listing,attributes,variations,seller_custom_field';

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
    ) {
    }

    public static function conectado(Company $empresa): bool
    {
        return $empresa->mlToken?->status === 'active';
    }

    // ═══ Importar (a regra) ═════════════════════════════════════════════════

    /**
     * Começa a leitura em segundo plano. A API pode levar minutos numa conta
     * grande (2.688 anúncios = ~135 chamadas): fora do request, em Job.
     */
    public function iniciar(Company $empresa): void
    {
        if (! self::conectado($empresa)) {
            throw ValidationException::withMessages([
                'importacao' => 'A conta do Mercado Livre desta empresa não está conectada. Conecte pelo Onboarding.',
            ]);
        }

        if (! EstruturaOferta::where('company_id', $empresa->id)->exists()) {
            throw ValidationException::withMessages([
                'importacao' => 'Cadastre as ofertas primeiro: a importação procura no Mercado Livre os anúncios pelo SKU de cada uma.',
            ]);
        }

        Cache::put(self::CHAVE.$empresa->id, ['estado' => 'lendo', 'iniciado_em' => now()->toIso8601String()], now()->addMinutes(self::VALIDADE_MINUTOS));

        ImportarAnunciosMlEstruturaJob::dispatch($empresa->id);
    }

    /**
     * O que o Job faz: procura no ML os anúncios de cada SKU de oferta e guarda
     * o texto pronto para a colagem.
     *
     * ### Por SKU, e não a conta inteira (medido em 25/09)
     * A CAMILLOPARTSFILIALSCCAMILLO (#131) tem ~100 mil anúncios segundo o
     * próprio ML (87.930 ativos + 10.830 pausados) — autopeças, um anúncio por
     * peça × compatibilidade × tipo. Ler a conta inteira seriam ~5.000
     * multigets (mais de meia hora, e a fila `database` reentrega Job acima de
     * 90 s) e dezenas de milhares de linhas em "aguardando oferta". Pelo SKU,
     * é UMA busca por oferta (`/users/{id}/items/search?seller_sku=`, 128 ms
     * medidos na #131), e só volta o que interessa ao método: os anúncios de
     * cada oferta. O que não casar por SKU se acha pela busca manual.
     *
     * Tipo, status, título e catálogo vêm do ACERVO local (sincronizado todo
     * dia); só os anúncios que ainda não estão lá — publicados depois do sync
     * da madrugada — passam por um multiget.
     */
    public function ler(Company $empresa): void
    {
        try {
            $mlUserId = (string) $empresa->mlToken->ml_user_id;

            // O SKU como a oferta o tem (é o que a colagem casa), um por forma
            // normalizada — SKU repetido em duas ofertas é UMA busca.
            $skus = EstruturaOferta::where('company_id', $empresa->id)->pluck('sku')
                ->unique(fn ($s) => EstruturaOferta::normalizarSku($s))
                ->filter(fn ($s) => EstruturaOferta::normalizarSku($s) !== null)
                ->values();

            $idsPorSku = [];
            foreach ($skus as $sku) {
                $idsPorSku[$sku] = $this->idsDoSku($empresa, $mlUserId, $sku);
            }

            // Um anúncio numa oferta só: o primeiro SKU que o trouxe.
            $skuDoId = [];
            foreach ($idsPorSku as $sku => $ids) {
                foreach ($ids as $id) {
                    $skuDoId[$id] ??= $sku;
                }
            }

            $detalhes = $this->detalhes($empresa, array_keys($skuDoId));

            $linhas = [];
            $ignorados = 0;
            foreach ($skuDoId as $id => $sku) {
                $linha = isset($detalhes[$id]) ? self::linhaDoAnuncio($detalhes[$id]) : null;
                if ($linha === null) {
                    $ignorados++;
                    continue;
                }
                $linha['sku'] = $sku;
                $linhas[] = $linha;
            }

            Cache::put(self::CHAVE.$empresa->id, [
                'estado'      => 'pronto',
                'texto'       => self::texto($linhas),
                'total'       => count($linhas),
                'ignorados'   => $ignorados,
                'skus'        => $skus->count(),
                'sem_anuncio' => count(array_filter($idsPorSku, fn ($ids) => $ids === [])),
                'lido_em'     => now()->toIso8601String(),
            ], now()->addMinutes(self::VALIDADE_MINUTOS));
        } catch (\Throwable $e) {
            Log::error("[Estrutura] importação do ML falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            Cache::put(self::CHAVE.$empresa->id, [
                'estado' => 'erro',
                'erro'   => 'Não foi possível ler os anúncios no Mercado Livre agora. Tente de novo em alguns minutos.',
            ], now()->addMinutes(self::VALIDADE_MINUTOS));
        }
    }

    /**
     * Os MLB com este SKU na conta, página a página. Teto de páginas por SKU:
     * um SKU com centenas de anúncios é raro, e sem teto um SKU genérico
     * ("1", "A") poderia varrer a conta inteira por outra porta.
     *
     * @return array<int, string>
     */
    private function idsDoSku(Company $empresa, string $mlUserId, string $sku): array
    {
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
     * Tipo, status, título e catálogo de cada MLB — do acervo local, e da API
     * só para os que ainda não estão nele.
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

        $faltam = array_values(array_diff($ids, array_keys($porId)));
        foreach (array_chunk($faltam, 20) as $lote) {
            $resposta = $this->ml->get($empresa, '/items', ['ids' => implode(',', $lote), 'attributes' => self::CAMPOS]);
            foreach ($resposta as $envelope) {
                if (($envelope['code'] ?? null) === 200 && ! empty($envelope['body']['id'])) {
                    $porId[$envelope['body']['id']] = $envelope['body'];
                }
            }
        }

        return $porId;
    }

    /**
     * O estado para a tela. Quando pronto, já vem com a prévia — a MESMA da
     * colagem, no modo acrescentar/atualizar (a importação nunca remove).
     */
    public function estado(Company $empresa): array
    {
        $estado = Cache::get(self::CHAVE.$empresa->id) ?? ['estado' => 'nenhum'];

        if ($estado['estado'] !== 'pronto') {
            return $estado;
        }

        return [
            'estado'      => 'pronto',
            'total'       => $estado['total'],
            'ignorados'   => $estado['ignorados'],
            'skus'        => $estado['skus'] ?? null,
            'sem_anuncio' => $estado['sem_anuncio'] ?? null,
            'lido_em'     => $estado['lido_em'],
            'previa'    => $this->colagem->previa($empresa, $estado['texto'], ColagemAnunciosService::MODO_ACRESCENTAR, self::MAX_LINHAS),
        ];
    }

    /** Grava o que a prévia mostrou — refazendo o plano a partir do texto guardado no servidor. */
    public function aplicar(Company $empresa, AtorDoPortal $ator): array
    {
        $estado = Cache::get(self::CHAVE.$empresa->id);

        if (($estado['estado'] ?? null) !== 'pronto') {
            throw ValidationException::withMessages(['importacao' => 'Leia os anúncios do Mercado Livre de novo antes de confirmar.']);
        }

        $totais = $this->colagem->aplicar($empresa, $estado['texto'], ColagemAnunciosService::MODO_ACRESCENTAR, $ator, self::MAX_LINHAS);
        Cache::forget(self::CHAVE.$empresa->id);

        return $totais;
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
