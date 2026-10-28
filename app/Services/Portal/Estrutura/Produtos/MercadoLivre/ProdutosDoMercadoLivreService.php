<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use App\Jobs\Portal\ImportarAnuncioMlParaProdutoJob;
use App\Models\Company;
use App\Models\EstruturaProdutoOrigemMl;
use App\Models\MlAcervoItem;
use App\Services\MercadoLivreService;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Importar do Mercado Livre" (28/10/2026): o cliente que já vende no ML escolhe anúncios
 * e eles viram Produtos do Portal, para a IA jardinar pela metodologia ECF. É um CLONE para
 * melhorar — o anúncio original continua no ar, intocado (decisão do usuário: os dois ficam
 * ativos, porque título e o resto vão mudar).
 *
 * ### De onde vem a lista
 * Do acervo (`ml_acervo_itens`), que o `mlb:sync-acervo` já coleta todo dia de toda empresa com
 * conta conectada: título, vendas, nota ECF (0–86), saúde do ML, visitas de 30 dias (rotação).
 * Sem chamada ao ML para listar. TODOS os anúncios aparecem (decisão do usuário, 28/10/2026), com o
 * filtro de situação: ativos, inativos (pausado, em revisão, encerrado) ou todos. O de catálogo vem
 * marcado: a ficha dele é a do catálogo.
 *
 * ### O diagnóstico é da PÁGINA, ao vivo
 * Só os anúncios que estão na tela (até 30): um multiget para os campos, a ficha da categoria
 * (cache de 7 dias), as visitas de 30 dias (`time_window`, em paralelo) e as vendas de 30 dias da
 * loja (o MESMO cache do Mapeamento, `AnunciosMercadoLivreService::vendasEmCache`). Guardado 30 min.
 *
 * ### A importação é em segundo plano
 * Um Job por anúncio na fila `high` (quem clicou está olhando). O estado de cada anúncio fica no
 * cache (uma chave por anúncio, para dois Jobs não escreverem a mesma chave) e a tela consulta.
 */
class ProdutosDoMercadoLivreService
{
    public const POR_PAGINA = 30;

    /** Anúncios por clique em "Importar": cada um baixa até 12 fotos por variação. */
    public const MAX_POR_IMPORTACAO = 20;

    /** "oportunidade": a nota do cadastro mais baixa primeiro (rótulo da tela: "Maior oportunidade de melhoria"). */
    public const ORDENS = ['oportunidade', 'vendidos', 'visitas'];

    public const SITUACOES = ['todos', 'ativos', 'inativos'];

    private const VALIDADE_HORAS = 6;
    private const DIAGNOSTICO_MINUTOS = 30;
    private const IDS_POR_MULTIGET = 20;
    /** `date_created`/`start_time`: há quantos dias o anúncio está no ar (a janela das visitas e vendas). */
    private const CAMPOS_MULTIGET = 'id,title,category_id,status,seller_id,attributes,pictures,variations,shipping,catalog_listing,date_created,start_time';

    /** O nível de qualidade que o próprio ML dá ao anúncio (`/item/{id}/performance`), em português. */
    public const NIVEIS_ML = ['good' => 'Boa', 'medium' => 'Média', 'low' => 'Baixa', 'incomplete' => 'Incompleta'];

    /** Situações de um anúncio na importação. */
    public const NA_FILA = 'fila';
    public const IMPORTANDO = 'importando';
    public const PRONTO = 'pronto';
    public const JA_EXISTE = 'ja_existe';
    public const ERRO = 'erro';

    public function __construct(
        private MercadoLivreService $ml,
        private AnunciosMercadoLivreService $anuncios,
        private FichaTecnicaDaCategoria $fichas,
        private MlCatalogoMetaService $catalogo,
        private AnunciosNoPortal $noPortal,
    ) {}

    // ═══ Lista ══════════════════════════════════════════════════════════════

    /**
     * @return array{conectado: bool, total_acervo: int, atualizado_em: ?string, pagina: int, paginas: int, total: int, itens: list<array>}
     */
    public function candidatos(Company $empresa, string $busca = '', string $ordem = 'oportunidade', int $pagina = 1, bool $soNovos = false, string $situacao = 'todos'): array
    {
        $ordem = in_array($ordem, self::ORDENS, true) ? $ordem : 'oportunidade';
        $situacao = in_array($situacao, self::SITUACOES, true) ? $situacao : 'todos';

        $base = MlAcervoItem::query()->where('company_id', $empresa->id);
        match ($situacao) {
            'ativos'   => $base->where('status', 'active'),
            'inativos' => $base->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'active')),
            default    => null,
        };

        $busca = trim($busca);
        if ($busca !== '') {
            $mlb = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $busca));
            $base->where(fn ($q) => $q->where('title', 'like', '%'.addcslashes($busca, '%_\\').'%')->orWhere('ml_item_id', $mlb));
        }
        // "Esconder os que já estão no Portal": importados, publicados pelo Publicador e ligados no Mapeamento.
        if ($soNovos && ($jaNoPortal = array_keys($this->noPortal->daEmpresa($empresa))) !== []) {
            $base->whereNotIn('ml_item_id', $jaNoPortal);
        }

        $total = (clone $base)->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);

        match ($ordem) {
            // Mais oportunidade de melhoria primeiro (nota do cadastro mais baixa); no empate, quem tem mais
            // visita (mais a ganhar arrumando).
            'oportunidade' => $base->orderByRaw('CASE WHEN nota_ecf IS NULL THEN 1 ELSE 0 END')->orderBy('nota_ecf')
                ->orderByRaw('CASE WHEN visitas_30d IS NULL THEN 1 ELSE 0 END')->orderByDesc('visitas_30d')->orderByDesc('sold_quantity'),
            'vendidos'     => $base->orderByDesc('sold_quantity'),
            'visitas'      => $base->orderByRaw('CASE WHEN visitas_30d IS NULL THEN 1 ELSE 0 END')->orderByDesc('visitas_30d')->orderByDesc('sold_quantity'),
        };

        $itens = $base->orderBy('id')->forPage($pagina, self::POR_PAGINA)->get();

        $noPortal = $this->noPortal->daEmpresa($empresa, $itens->pluck('ml_item_id')->all());

        return [
            'conectado'     => AnunciosMercadoLivreService::conectado($empresa),
            'total_acervo'  => MlAcervoItem::query()->where('company_id', $empresa->id)->count(),
            'atualizado_em' => MlAcervoItem::query()->where('company_id', $empresa->id)->max('coletado_em'),
            'pagina'        => $pagina,
            'paginas'       => $paginas,
            'total'         => $total,
            'itens'         => $itens->map(function (MlAcervoItem $i) use ($noPortal) {
                return [
                    'mlb'          => $i->ml_item_id,
                    'titulo'       => $i->title,
                    'thumbnail'    => $i->thumbnail ? preg_replace('#^http://#', 'https://', $i->thumbnail) : null,
                    'permalink'    => $i->permalink,
                    'preco'        => $i->price !== null ? (float) $i->price : null,
                    'tipo'         => AnunciosMercadoLivreService::TIPOS_ML[$i->listing_type_id] ?? null,
                    'ativo'        => $i->status === 'active',
                    'status'       => AnunciosMercadoLivreService::STATUS_ML[$i->status] ?? ($i->status ? ucfirst((string) $i->status) : null),
                    'catalogo'     => (bool) $i->catalog_listing,
                    'estoque'      => $i->available_quantity,
                    'vendas_total' => (int) $i->sold_quantity,
                    'visitas_30d'  => $i->visitas_30d,
                    'fotos'        => $i->fotos_count,
                    // A nota ECF (0–86) ordena a lista mas NÃO vai para a tela: crua, "64 de 86" não diz nada ao
                    // cliente (usuário, 28/10/2026), e virar % é renormalizar a régua (proibido, D-22). Os
                    // problemas do diagnóstico explicam o cadastro; a qualidade que aparece é a do próprio ML.
                    'saude_ml'     => $i->performance_score,
                    'saude_nivel'  => self::NIVEIS_ML[$i->performance_level] ?? null,
                    'acoes_ml'     => array_slice(array_values(array_filter(array_map(
                        fn ($a) => is_array($a) ? ($a['title'] ?? null) : (is_string($a) ? $a : null),
                        (array) ($i->performance_acoes ?? []),
                    ))), 0, 3),
                    // Já tem o produto no Portal (importado, publicado pelo Publicador ou ligado no Mapeamento):
                    // não é recomendado nem importado de novo.
                    'no_portal'    => $noPortal[$i->ml_item_id] ?? null,
                    // Publicado pelo assistente antigo de anúncio da ECF (acervo `origem = ecf`): já é da metodologia.
                    'feito_pela_ecf' => $i->origem === MlAcervoItem::ORIGEM_ECF,
                ];
            })->values()->all(),
        ];
    }

    // ═══ Diagnóstico da página ══════════════════════════════════════════════

    /**
     * @param  list<string>  $mlbs  os anúncios da página (até {@see self::POR_PAGINA})
     * @return array{diagnosticos: array<string, array>, vendas_prontas: bool}
     */
    public function diagnosticar(Company $empresa, array $mlbs): array
    {
        $mlbs = $this->daEmpresa($empresa, array_slice($mlbs, 0, self::POR_PAGINA));
        if ($mlbs === [] || ! AnunciosMercadoLivreService::conectado($empresa)) {
            return ['diagnosticos' => [], 'vendas_prontas' => true];
        }

        $vendas = $this->anuncios->vendasEmCache($empresa);
        if ($vendas === null) {
            $this->anuncios->aquecerVendas($empresa);
        }

        $saida = [];
        $faltam = [];
        foreach ($mlbs as $mlb) {
            $guardado = Cache::get($this->chaveDiagnostico($empresa, $mlb));
            // O guardado sem vendas é refeito quando as vendas ficam prontas.
            if (is_array($guardado) && ($vendas === null || $guardado['metricas']['vendas_30d'] !== null)) {
                $saida[$mlb] = $guardado;
            } else {
                $faltam[] = $mlb;
            }
        }

        if ($faltam !== []) {
            $corpos = $this->multiget($empresa, $faltam);
            $visitas = $this->visitas($empresa, array_keys($corpos));

            foreach ($faltam as $mlb) {
                $item = $corpos[$mlb] ?? null;
                if ($item === null) {
                    $saida[$mlb] = ['erro' => 'O Mercado Livre não devolveu este anúncio agora.'];

                    continue;
                }
                $categoria = (string) ($item['category_id'] ?? '');
                $campos = $categoria === '' ? [] : FichaTecnicaDaCategoria::camposPorId($this->fichas->definicao($categoria));
                $max = $categoria === '' ? null : ((int) ($this->catalogo->categoria($categoria)['settings']['max_title_length'] ?? 0) ?: null);

                $diag = DiagnosticoDoAnuncio::avaliar($item, $campos, [
                    'vendas_30d'  => $vendas === null ? null : (int) array_sum((array) ($vendas[$mlb] ?? [])),
                    'visitas_30d' => $visitas[$mlb] ?? null,
                ], $max);

                Cache::put($this->chaveDiagnostico($empresa, $mlb), $diag, now()->addMinutes(self::DIAGNOSTICO_MINUTOS));
                $saida[$mlb] = $diag;
            }
        }

        return ['diagnosticos' => $saida, 'vendas_prontas' => $vendas !== null];
    }

    // ═══ Importação ═════════════════════════════════════════════════════════

    /**
     * Põe os anúncios na fila de importação. O que já foi importado ou já está na fila não entra
     * de novo. Só anúncio do acervo DESTA empresa (o Job confere de novo o dono no ML).
     *
     * @param  list<string>  $mlbs
     * @return array estado (ver {@see self::estado()})
     *
     * @throws ValidationException
     */
    public function iniciar(Company $empresa, array $mlbs, AtorDoPortal $ator): array
    {
        if (! AnunciosMercadoLivreService::conectado($empresa)) {
            throw ValidationException::withMessages(['mlbs' => 'Conecte a conta do Mercado Livre para importar os anúncios.']);
        }

        $pedidos = array_values(array_unique(array_filter(array_map(fn ($m) => strtoupper(trim((string) $m)), $mlbs))));
        if ($pedidos === []) {
            throw ValidationException::withMessages(['mlbs' => 'Escolha pelo menos um anúncio.']);
        }
        if (count($pedidos) > self::MAX_POR_IMPORTACAO) {
            throw ValidationException::withMessages(['mlbs' => 'Escolha até '.self::MAX_POR_IMPORTACAO.' anúncios por vez.']);
        }

        $validos = $this->daEmpresa($empresa, $pedidos);
        if ($validos === []) {
            throw ValidationException::withMessages(['mlbs' => 'Esses anúncios não estão na sua conta.']);
        }

        $lote = Cache::get($this->chaveLote($empresa));
        $lote = is_array($lote) ? $lote : ['mlbs' => [], 'iniciado_em' => now()->toIso8601String()];
        $titulos = MlAcervoItem::query()->where('company_id', $empresa->id)->whereIn('ml_item_id', $validos)->pluck('title', 'ml_item_id');
        $jaNoPortal = $this->noPortal->daEmpresa($empresa, $validos);
        $quem = self::quem($ator);

        foreach ($validos as $mlb) {
            $atual = $this->situacao($empresa, $mlb);
            if (in_array($atual['situacao'] ?? null, [self::NA_FILA, self::IMPORTANDO], true)) {
                continue;
            }
            if (! in_array($mlb, $lote['mlbs'], true)) {
                $lote['mlbs'][] = $mlb;
            }
            if (isset($jaNoPortal[$mlb])) {
                $p = $jaNoPortal[$mlb];
                $this->marcar($empresa, $mlb, $p['origem'] === AnunciosNoPortal::IMPORTADO
                    ? ['situacao' => self::PRONTO, 'titulo' => $titulos[$mlb] ?? $mlb, 'produto_id' => $p['produto_id'], 'mensagem' => 'Este anúncio já tinha sido importado.']
                    : ['situacao' => self::JA_EXISTE, 'titulo' => $titulos[$mlb] ?? $mlb, 'produto_id' => $p['produto_id'],
                        'mensagem' => 'Este anúncio já está no Portal'.($p['nome'] ? ": “{$p['nome']}”" : '').'.']);

                continue;
            }
            $this->marcar($empresa, $mlb, ['situacao' => self::NA_FILA, 'titulo' => $titulos[$mlb] ?? $mlb]);
            ImportarAnuncioMlParaProdutoJob::dispatch((int) $empresa->id, $mlb, $quem);
        }

        Cache::put($this->chaveLote($empresa), $lote, now()->addHours(self::VALIDADE_HORAS));

        return $this->estado($empresa);
    }

    /**
     * @return array{itens: list<array{mlb: string, situacao: string, titulo: ?string, produto_id: ?int, mensagem: ?string, avisos: list<string>, campos_ia: ?int}>, em_andamento: bool}
     */
    public function estado(Company $empresa): array
    {
        $lote = Cache::get($this->chaveLote($empresa));
        $mlbs = is_array($lote) ? (array) ($lote['mlbs'] ?? []) : [];

        $origens = $mlbs === [] ? collect() : EstruturaProdutoOrigemMl::query()
            ->where('company_id', $empresa->id)->whereIn('ml_item_id', $mlbs)->get()->keyBy('ml_item_id');

        $itens = [];
        foreach ($mlbs as $mlb) {
            $s = $this->situacao($empresa, $mlb);
            $origem = $origens->get($mlb);
            $itens[] = [
                'mlb'        => $mlb,
                'situacao'   => $s['situacao'] ?? self::ERRO,
                'titulo'     => $s['titulo'] ?? null,
                'produto_id' => $s['produto_id'] ?? ($origem ? (int) $origem->produto_id : null),
                'mensagem'   => $s['mensagem'] ?? ($s === [] ? 'A importação expirou. Tente de novo.' : null),
                'avisos'     => array_values((array) ($s['avisos'] ?? [])),
                // null = a IA ainda não passou; número = quantos campos ela completou.
                'campos_ia'  => $origem && is_array($origem->jardinagem) && ! isset($origem->jardinagem['erro']) ? $origem->camposDaIa() : null,
                'ia_erro'    => $origem && is_array($origem->jardinagem) && isset($origem->jardinagem['erro']),
            ];
        }

        return [
            'itens'        => $itens,
            'em_andamento' => collect($itens)->contains(fn ($i) => in_array($i['situacao'], [self::NA_FILA, self::IMPORTANDO], true)
                || ($i['situacao'] === self::PRONTO && $i['campos_ia'] === null && ! $i['ia_erro'])),
        ];
    }

    /** Esquece o painel do lote (os produtos importados ficam). */
    public function limpar(Company $empresa): void
    {
        $lote = Cache::get($this->chaveLote($empresa));
        foreach ((array) ($lote['mlbs'] ?? []) as $mlb) {
            $s = $this->situacao($empresa, (string) $mlb);
            if (! in_array($s['situacao'] ?? null, [self::NA_FILA, self::IMPORTANDO], true)) {
                Cache::forget($this->chaveItem($empresa, (string) $mlb));
            }
        }
        $restantes = array_values(array_filter((array) ($lote['mlbs'] ?? []), fn ($m) => Cache::has($this->chaveItem($empresa, (string) $m))));
        $restantes === [] ? Cache::forget($this->chaveLote($empresa))
            : Cache::put($this->chaveLote($empresa), ['mlbs' => $restantes] + (array) $lote, now()->addHours(self::VALIDADE_HORAS));
    }

    /** O Job escreve a situação do SEU anúncio (chave própria: dois Jobs nunca disputam a mesma). */
    public function marcar(Company $empresa, string $mlb, array $dados): void
    {
        $atual = $this->situacao($empresa, $mlb);
        Cache::put($this->chaveItem($empresa, $mlb), array_merge($atual, $dados), now()->addHours(self::VALIDADE_HORAS));
    }

    public function situacao(Company $empresa, string $mlb): array
    {
        $s = Cache::get($this->chaveItem($empresa, $mlb));

        return is_array($s) ? $s : [];
    }

    /** Quem clicou, num formato que atravessa a fila. */
    public static function quem(AtorDoPortal $ator): array
    {
        return ['equipe' => $ator->equipe, 'id' => $ator->modelo?->getKey(), 'nome' => $ator->nome];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** Só os MLBs que estão no acervo da empresa (nunca de outra). @return list<string> */
    private function daEmpresa(Company $empresa, array $mlbs): array
    {
        $mlbs = array_values(array_unique(array_map(fn ($m) => strtoupper(trim((string) $m)), $mlbs)));
        if ($mlbs === []) {
            return [];
        }
        $existem = MlAcervoItem::query()->where('company_id', $empresa->id)->whereIn('ml_item_id', $mlbs)->pluck('ml_item_id')->all();

        return array_values(array_intersect($mlbs, $existem));
    }

    /** @return array<string, array> mlb => corpo */
    private function multiget(Company $empresa, array $mlbs): array
    {
        $corpos = [];
        foreach (array_chunk($mlbs, self::IDS_POR_MULTIGET) as $ids) {
            try {
                $resposta = $this->ml->get($empresa, '/items', ['ids' => implode(',', $ids), 'attributes' => self::CAMPOS_MULTIGET]);
            } catch (\Throwable $e) {
                Log::warning("[Estrutura Produtos ML] multiget do diagnóstico falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

                continue;
            }
            foreach ((array) $resposta as $r) {
                $corpo = (array) ($r['body'] ?? []);
                if ((int) ($r['code'] ?? 0) === 200 && isset($corpo['id'])) {
                    $corpos[strtoupper((string) $corpo['id'])] = $corpo;
                }
            }
        }

        return $corpos;
    }

    /** Visitas dos últimos 30 dias, em paralelo. @return array<string, int> */
    private function visitas(Company $empresa, array $mlbs): array
    {
        if ($mlbs === []) {
            return [];
        }
        $pedidos = [];
        foreach ($mlbs as $mlb) {
            $pedidos[$mlb] = ["/items/{$mlb}/visits/time_window", ['last' => 30, 'unit' => 'day']];
        }

        $saida = [];
        try {
            foreach ($this->ml->getMany($empresa, $pedidos) as $mlb => $r) {
                if (is_array($r)) {
                    $saida[$mlb] = isset($r['results'])
                        ? (int) array_sum(array_column(AnunciosMercadoLivreService::serieDeVisitas($r), 'visitas'))
                        : (int) ($r['total_visits'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[Estrutura Produtos ML] visitas do diagnóstico falharam — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");
        }

        return $saida;
    }

    private function chaveLote(Company $empresa): string
    {
        return 'estrutura:produtos-ml:lote:'.$empresa->id;
    }

    private function chaveItem(Company $empresa, string $mlb): string
    {
        return 'estrutura:produtos-ml:item:'.$empresa->id.':'.$mlb;
    }

    private function chaveDiagnostico(Company $empresa, string $mlb): string
    {
        return 'estrutura:produtos-ml:diag2:'.$empresa->id.':'.$mlb;
    }
}
