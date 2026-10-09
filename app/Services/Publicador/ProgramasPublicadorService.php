<?php

namespace App\Services\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Support\Publicador\ContasLiberadas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Entrada do Publicador (tela A): empresas por programa — Polos, Incubadora e
 * Gestão (D13/D23). O programa é DERIVADO da `MlbEmpresa`, nunca gravado.
 *
 * Cada linha devolvida por `empresas()`:
 * `{ chave, tipo: 'mlb_empresa'|'company', id, nome, identificador, company_id,
 *    tem_token, token_expirado, token: 'ativo'|'expirado'|'sem_token', link_reconexao,
 *    portal: { situacao, novas, sincronizado_em }, produtos, publicados, prontos,
 *    liberada, publicados_mes }` (`publicados_mes` é extra, alimenta os indicadores).
 *
 * Os agregados saem de consultas agrupadas — nunca uma consulta por linha.
 * O carimbo de OAuth da implementação só serve para "autorizou, falta reconectar";
 * jamais é prova de token (RESEARCH §3.2).
 */
class ProgramasPublicadorService
{
    public const PROGRAMAS = ['polos', 'incubadora', 'gestao'];

    /** Os status de `prontidao()` que valem "tem anúncio no ar" nos buckets por fase (§7). */
    public const STATUS_NO_AR = ['publicado', 'parcial'];

    private const LOTE = 1000;

    /**
     * Resolvido só quando a lista precisa (`produtosParaTela`), NUNCA pelo construtor.
     *
     * ⚠️ `SugestaoDeKitService::__construct` recebe ESTA classe: injetar o serviço de
     * sugestão no construtor daqui fecha um ciclo no container e o resolve estoura em
     * recursão infinita. O PLAN.md pedia injeção no construtor — a dependência circular
     * é real e medida, então a resolução é preguiçosa (registrada no SUMMARY).
     */
    private ?SugestaoDeKitService $sugestoes = null;

    private function sugestoes(): SugestaoDeKitService
    {
        return $this->sugestoes ??= app(SugestaoDeKitService::class);
    }

    /**
     * O rótulo da fase na lista e nos cartões: "1 unidade" para o base, "Kit N" para o
     * kit. Fonte ÚNICA do texto — `PainelVisaoGeralService::ultimasPublicacoes()` lê
     * daqui também, para o mesmo produto nunca aparecer com dois rótulos na mesma tela.
     */
    public static function rotuloFase(?int $quantidadeKit): string
    {
        $n = (int) $quantidadeKit;

        return $n <= 1 ? '1 unidade' : "Kit {$n}";
    }

    /**
     * Linhas base (conta + token), sem agregados.
     *
     * @return Collection<int, array>
     */
    private function base(string $programa): Collection
    {
        return $programa === 'gestao' ? $this->baseGestao() : $this->baseEmpresas($programa);
    }

    private function baseEmpresas(string $programa): Collection
    {
        $empresas = MlbEmpresa::query()->ativas()->programa($programa)
            ->with(['mlToken', 'company.mlToken', 'implementacao:id,empresa_id,token'])
            ->get();

        if ($empresas->isEmpty()) {
            return collect();
        }

        // "Autorizou" = passou pelo OAuth mas o token não existe (falta reconectar).
        $autorizou = MlbImplementacao::query()
            ->whereIn('empresa_id', $empresas->pluck('id'))
            ->whereNotNull('dados->ml_oauth')
            ->pluck('empresa_id')
            ->flip();

        return $empresas->map(function (MlbEmpresa $e) use ($autorizou) {
            $ancora = PubProduto::ancoraComToken($e, $e->company);
            if ($ancora === null && ! $autorizou->has($e->id)) {
                return null;
            }

            $company = $e->company;
            $identificador = $company?->cnpj ?: ($e->cust_id ? 'CUST '.$e->cust_id : '#'.$e->id);

            return [
                'chave' => $e->chaveContaMl(),
                'tipo' => 'mlb_empresa',
                'id' => $e->id,
                'nome' => (string) $e->nome,
                'identificador' => $identificador,
                'company_id' => $e->company_id,
                'ancora' => $ancora,
                'link_reconexao' => $e->implementacao?->token
                    ? route('implementacao.conectar-ml', ['token' => $e->implementacao->token])
                    : null,
                'mlb_empresa_id' => $e->id,
            ];
        })->filter()->values();
    }

    private function baseGestao(): Collection
    {
        // Company ligada a MlbEmpresa ativa de Polos/Incubadora pertence a esse programa.
        $ligadas = collect(['polos', 'incubadora'])
            ->flatMap(fn ($p) => MlbEmpresa::query()->ativas()->programa($p)->whereNotNull('company_id')->pluck('company_id'))
            ->unique();

        return Company::query()
            ->whereHas('mlToken')
            ->with('mlToken')
            ->when($ligadas->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ligadas->all()))
            ->get()
            ->map(function (Company $c) {
                $ancora = PubProduto::ancoraComToken(null, $c);

                return [
                    'chave' => $c->chaveContaMl(),
                    'tipo' => 'company',
                    'id' => $c->id,
                    'nome' => (string) $c->name,
                    'identificador' => $c->cnpj ?: ($c->ml_store_id ? 'CUST '.$c->ml_store_id : '#'.$c->id),
                    'company_id' => $c->id,
                    'ancora' => $ancora,
                    'link_reconexao' => null,
                    'mlb_empresa_id' => null,
                ];
            })->values();
    }

    /**
     * Linhas completas da tela A para o programa.
     *
     * @return Collection<int, array>
     */
    public function empresas(string $programa): Collection
    {
        $linhas = $this->base($programa);
        if ($linhas->isEmpty()) {
            return collect();
        }

        $empresaIds = $linhas->pluck('mlb_empresa_id')->filter()->unique()->values()->all();
        $companyIds = $linhas->pluck('company_id')->filter()->unique()->values()->all();

        // Produtos de todas as linhas numa só leitura (regra OU: mlb_empresa_id = X ou company_id = X.company_id).
        $produtos = PubProduto::query()
            ->where(function ($q) use ($empresaIds, $companyIds) {
                $q->whereIn('mlb_empresa_id', $empresaIds ?: [0])
                    ->orWhereIn('company_id', $companyIds ?: [0]);
            })
            ->get(['id', 'mlb_empresa_id', 'company_id', 'oferta_id', 'origem', 'created_at']);

        $ids = $produtos->pluck('id')->all();
        $prontosPor = [];
        $publicadosPor = [];
        $mesPor = [];
        $inicioMes = now()->startOfMonth();

        foreach (array_chunk($ids, self::LOTE) as $lote) {
            PubRascunho::query()->whereIn('produto_id', $lote)->where('status', PubRascunho::VALIDATED)
                ->selectRaw('produto_id, COUNT(*) as total')->groupBy('produto_id')->get()
                ->each(function ($r) use (&$prontosPor) {
                    $prontosPor[$r->produto_id] = (int) $r->total;
                });

            PubPublicacaoItem::query()
                ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
                ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
                ->whereIn('pub_rascunhos.produto_id', $lote)
                ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
                ->selectRaw('pub_rascunhos.produto_id as produto_id, COUNT(*) as total, SUM(CASE WHEN pub_publicacoes.concluida_em >= ? THEN 1 ELSE 0 END) as no_mes', [$inicioMes->toDateTimeString()])
                ->groupBy('pub_rascunhos.produto_id')->get()
                ->each(function ($r) use (&$publicadosPor, &$mesPor) {
                    $publicadosPor[$r->produto_id] = (int) $r->total;
                    $mesPor[$r->produto_id] = (int) $r->no_mes;
                });
        }

        // Portal: ofertas por Company e as que já viraram produto.
        $ofertasPor = $companyIds === [] ? collect() : DB::table('estrutura_ofertas')
            ->whereIn('company_id', $companyIds)->selectRaw('company_id, COUNT(*) as total')->groupBy('company_id')
            ->pluck('total', 'company_id');
        $comProdutoPor = $this->ofertasCobertas($companyIds);

        $cacheChaves = collect($companyIds)->mapWithKeys(fn ($id) => [$id => 'publicador.portal_sincronizado_em.company-'.$id]);
        $emCache = $cacheChaves->isEmpty() ? [] : Cache::many($cacheChaves->values()->all());
        $maisRecentePor = $produtos->where('origem', PubProduto::ORIGEM_PORTAL)->whereNotNull('company_id')
            ->groupBy('company_id')->map(fn ($g) => $g->max('created_at'));

        return $linhas->map(function (array $l) use ($produtos, $prontosPor, $publicadosPor, $mesPor, $ofertasPor, $comProdutoPor, $cacheChaves, $emCache, $maisRecentePor) {
            $meus = $produtos->filter(fn ($p) => ($l['mlb_empresa_id'] !== null && (int) $p->mlb_empresa_id === (int) $l['mlb_empresa_id'])
                || ($l['company_id'] !== null && (int) $p->company_id === (int) $l['company_id']))->pluck('id');

            $ancora = $l['ancora'];
            $token = $ancora === null ? 'sem_token' : ($ancora->mlToken?->isExpired() ? 'expirado' : 'ativo');

            $companyId = $l['company_id'];
            $totalOfertas = (int) ($companyId ? ($ofertasPor[$companyId] ?? 0) : 0);
            $comProduto = (int) ($companyId ? ($comProdutoPor[$companyId] ?? 0) : 0);
            if ($totalOfertas === 0) {
                $situacao = 'sem_portal';
                $novas = 0;
            } elseif ($comProduto === 0) {
                $situacao = 'nunca';
                $novas = $totalOfertas;
            } elseif ($comProduto < $totalOfertas) {
                $situacao = 'novas';
                $novas = $totalOfertas - $comProduto;
            } else {
                $situacao = 'sincronizado';
                $novas = 0;
            }

            $sincronizadoEm = null;
            if ($companyId) {
                $valor = $emCache[$cacheChaves[$companyId]] ?? $maisRecentePor[$companyId] ?? null;
                $sincronizadoEm = $valor ? \Illuminate\Support\Carbon::parse($valor)->toIso8601String() : null;
            }

            return [
                'chave' => $l['chave'],
                'tipo' => $l['tipo'],
                'id' => $l['id'],
                'nome' => $l['nome'],
                'identificador' => $l['identificador'],
                'company_id' => $l['company_id'],
                'tem_token' => $token !== 'sem_token',
                'token_expirado' => $token === 'expirado',
                'token' => $token,
                'link_reconexao' => $token === 'ativo' ? null : $l['link_reconexao'],
                'portal' => ['situacao' => $situacao, 'novas' => $novas, 'sincronizado_em' => $sincronizadoEm],
                'produtos' => $meus->count(),
                'publicados' => (int) $meus->sum(fn ($id) => $publicadosPor[$id] ?? 0),
                'prontos' => (int) $meus->sum(fn ($id) => $prontosPor[$id] ?? 0),
                'liberada' => ContasLiberadas::libera($ancora),
                'publicados_mes' => (int) $meus->sum(fn ($id) => $mesPor[$id] ?? 0),
            ];
        })->values();
    }

    /**
     * Contagem de produtos por situação — MESMO `match` que hoje mora em
     * `MlbPublicadorEntradaController::produtos()`, extraído aqui para que
     * Produtos.jsx e (nas próximas plans da Fase 173) a Visão geral leiam
     * exatamente o mesmo número, nunca duas implementações do mesmo cálculo.
     * `sem_oferta` é um `array_filter` adicional sobre o MESMO array recebido
     * (produtos com `oferta_id === null`) — não dispara nenhuma query nova.
     *
     * ═══ `por_fase` (Fase 175 plano 08, §7 da ETAPA-3) ═══════════════════════
     *
     * Bucket NOVO e ADITIVO: os 6 buckets de cima continuam calculados do mesmo
     * jeito, no mesmo laço, com os mesmos valores — a Etapa 1 e a Etapa 2 estão em
     * produção e leem todos eles. `por_fase` é o bloco "Produtos por fase" da
     * Visão geral, e por isso sai DAQUI e não de uma contagem própria: é o que faz
     * "a Visão geral bate com a lista" ser verdadeiro por construção.
     *
     * Nenhum status novo foi inventado: a cadeia de decisão usa só `fase`, `eh_kit`
     * e a `status.chave` que `EditorRascunhoService::prontidao()` já devolve. Cada
     * produto cai em EXATAMENTE um bucket (a soma dos 5 é `todos`), nesta ordem:
     *
     * 1. `fase3_mais`      — qualquer membro com `fase >= 3`, em qualquer status;
     * 2. `fase2_publicada` — kit (fase 2) com anúncio no ar;
     * 3. `fase2_preparacao`— kit (fase 2) em rascunho/conferir/pronto/publicando;
     * 4. `fase1_publicada` — base com anúncio no ar;
     * 5. `sem_oferta`      — base sem nenhum anúncio no ar.
     *
     * ⚠️ `por_fase['sem_oferta']` NÃO é o `sem_oferta` de cima. O de cima é
     * "produto sem oferta do Portal" (`oferta_id === null`, o que a Visão geral já
     * mostra como indicador); o do `por_fase` é o rótulo da §7 ("Sem oferta") e
     * significa "produto que ainda não tem anúncio nenhum no ar". Os dois nomes
     * coincidem porque vêm de documentos diferentes; os números são diferentes de
     * propósito e nenhum dos dois pode mudar.
     *
     * Lista no shape ANTIGO (sem `fase`/`eh_kit`/`anuncios`) continua funcionando:
     * sem as chaves novas todo produto é base de fase 1 — exatamente o que ele é
     * no banco depois da migration sem backfill.
     *
     * @param  list<array>  $produtos  shape de `produtosParaTela()`
     * @return array{todos: int, rascunho: int, conferidos: int, publicados: int, com_problema: int, sem_oferta: int,
     *     por_fase: array{sem_oferta: int, fase1_publicada: int, fase2_preparacao: int, fase2_publicada: int, fase3_mais: int}}
     */
    public function contagemProdutos(array $produtos): array
    {
        $contagens = ['todos' => count($produtos), 'rascunho' => 0, 'conferidos' => 0, 'publicados' => 0, 'com_problema' => 0, 'sem_oferta' => 0];
        $porFase = ['sem_oferta' => 0, 'fase1_publicada' => 0, 'fase2_preparacao' => 0, 'fase2_publicada' => 0, 'fase3_mais' => 0];

        foreach ($produtos as $p) {
            match ($p['status']['chave']) {
                'pronto' => $contagens['conferidos']++,
                'publicado', 'parcial' => $contagens['publicados']++,
                'erro' => $contagens['com_problema']++,
                default => $contagens['rascunho']++,
            };
            if ($p['oferta_id'] === null) {
                $contagens['sem_oferta']++;
            }

            $porFase[$this->bucketDaFase($p)]++;
        }

        return $contagens + ['por_fase' => $porFase];
    }

    /**
     * O bucket por fase de UM produto — if-else encadeado, exclusivo e total.
     *
     * @param  array  $p  shape de `produtosParaTela()` (ou o shape antigo, sem as chaves de fase)
     */
    private function bucketDaFase(array $p): string
    {
        if ((int) ($p['fase'] ?? 1) >= 3) {
            return 'fase3_mais';
        }

        // "No ar" = o status derivado diz publicado/parcial OU existe anúncio CREATED
        // na lista (`anuncios` é montado SÓ de itens CREATED com ml_item_id). O segundo
        // ramo cobre o kit/base que falhou numa republicação mas tem anúncio vivo.
        $noAr = in_array($p['status']['chave'] ?? null, self::STATUS_NO_AR, true)
            || ($p['anuncios'] ?? []) !== [];

        if (($p['eh_kit'] ?? false) === true) {
            return $noAr ? 'fase2_publicada' : 'fase2_preparacao';
        }

        return $noAr ? 'fase1_publicada' : 'sem_oferta';
    }

    /** Empresas com token por programa (as abas da tela A). */
    public function contagens(): array
    {
        $saida = [];
        foreach (self::PROGRAMAS as $p) {
            $saida[$p] = $this->base($p)->filter(fn ($l) => $l['ancora'] !== null)->count();
        }

        return $saida;
    }

    /** Indicadores do programa selecionado a partir das linhas já montadas. */
    public function indicadores(string $programa, Collection $linhas): array
    {
        $comToken = $linhas->where('tem_token', true);
        $comPortal = $linhas->filter(fn ($l) => $l['portal']['situacao'] !== 'sem_portal');
        $sincronizadas = $comPortal->filter(fn ($l) => $l['portal']['situacao'] === 'sincronizado')->count();

        return [
            'empresas' => $comToken->count(),
            'com_portal' => $comPortal->count(),
            'pct_sincronizado' => $comPortal->isEmpty() ? 0 : (int) floor($sincronizadas / $comPortal->count() * 100),
            'prontos' => (int) $linhas->sum('prontos'),
            'publicados_mes' => (int) $linhas->sum('publicados_mes'),
        ];
    }

    /**
     * Resolve a chave da conta (`empresa-N` / `company-N`) para as âncoras e o programa.
     * `company-N` ligada a MlbEmpresa ativa de Polos/Incubadora devolve a chave canônica
     * `empresa-<id>` (quem chama redireciona). O token NÃO é exigido aqui.
     *
     * @return array{mlb_empresa: ?MlbEmpresa, company: ?Company, programa: string, chave: string}|null
     */
    public function resolver(string $chave): ?array
    {
        if (preg_match('/^empresa-(\d+)$/', $chave, $m)) {
            $e = MlbEmpresa::query()->ativas()->with('company')->find((int) $m[1]);
            $programa = $e?->programaPublicador();

            return $programa === null ? null : [
                'mlb_empresa' => $e, 'company' => $e->company, 'programa' => $programa, 'chave' => $e->chaveContaMl(),
            ];
        }

        if (preg_match('/^company-(\d+)$/', $chave, $m)) {
            $company = Company::find((int) $m[1]);
            if ($company === null) {
                return null;
            }

            $ligada = MlbEmpresa::query()->ativas()->where('company_id', $company->id)->orderBy('id')->get()
                ->first(fn (MlbEmpresa $e) => $e->programaPublicador() !== null);
            if ($ligada !== null) {
                return [
                    'mlb_empresa' => $ligada, 'company' => $company,
                    'programa' => $ligada->programaPublicador(), 'chave' => $ligada->chaveContaMl(),
                ];
            }

            return ['mlb_empresa' => null, 'company' => $company, 'programa' => 'gestao', 'chave' => $company->chaveContaMl()];
        }

        return null;
    }

    /** Produtos da conta: `mlb_empresa_id = e.id` OU `company_id = c.id`. */
    public function produtosQuery(?MlbEmpresa $e, ?Company $c): Builder
    {
        return PubProduto::query()->where(function ($q) use ($e, $c) {
            if ($e !== null) {
                $q->orWhere('mlb_empresa_id', $e->id);
            }
            if ($c !== null) {
                $q->orWhere('company_id', $c->id);
            }
            if ($e === null && $c === null) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Ofertas do Portal já cobertas por um produto do Publicador, por Company. Coberta = tem pub_produto
     * pela própria oferta OU pelo grupo do produto do Portal (Fase 172, D-06): a cor de um produto já
     * agrupado entra no rascunho pelo preenchimento, não como produto novo. Uma regra só para a lista de
     * empresas e para a situação da empresa (review 172 WR-08), senão as duas telas se contradizem.
     *
     * @param  list<int>  $companyIds
     * @return Collection<int, int> company_id → ofertas cobertas
     */
    private function ofertasCobertas(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return collect();
        }

        return DB::table('estrutura_ofertas as eo')
            ->whereIn('eo.company_id', $companyIds)
            ->where(function ($q) {
                $q->whereExists(function ($s) {
                    $s->select(DB::raw(1))->from('pub_produtos as pp')->whereColumn('pp.oferta_id', 'eo.id');
                })->orWhereExists(function ($s) {
                    $s->select(DB::raw(1))->from('estrutura_produto_variacoes as epv')
                        ->join('pub_produtos as pg', 'pg.estrutura_produto_id', '=', 'epv.produto_id')
                        ->whereColumn('epv.id', 'eo.variacao_id')
                        ->whereColumn('pg.company_id', 'eo.company_id');
                });
            })
            ->selectRaw('eo.company_id as company_id, COUNT(*) as total')
            ->groupBy('eo.company_id')
            ->pluck('total', 'company_id')
            ->map(fn ($n) => (int) $n);
    }

    /**
     * Situação do Portal de UMA Company (mesma regra das linhas da tela A):
     * sem_portal | nunca | novas | sincronizado.
     *
     * @return array{situacao: string, novas: int, sincronizado_em: ?string}
     */
    public function situacaoPortal(?Company $company): array
    {
        if ($company === null) {
            return ['situacao' => 'sem_portal', 'novas' => 0, 'sincronizado_em' => null];
        }

        $total = (int) DB::table('estrutura_ofertas')->where('company_id', $company->id)->count();
        $comProduto = (int) ($this->ofertasCobertas([(int) $company->id])[$company->id] ?? 0);

        if ($total === 0) {
            $situacao = 'sem_portal';
            $novas = 0;
        } elseif ($comProduto === 0) {
            $situacao = 'nunca';
            $novas = $total;
        } elseif ($comProduto < $total) {
            $situacao = 'novas';
            $novas = $total - $comProduto;
        } else {
            $situacao = 'sincronizado';
            $novas = 0;
        }

        $valor = Cache::get('publicador.portal_sincronizado_em.company-'.$company->id)
            ?? PubProduto::query()->where('origem', PubProduto::ORIGEM_PORTAL)->where('company_id', $company->id)->max('created_at');

        return [
            'situacao' => $situacao,
            'novas' => $novas,
            'sincronizado_em' => $valor ? \Illuminate\Support\Carbon::parse($valor)->toIso8601String() : null,
        ];
    }

    /**
     * A empresa "dona" do produto, para autorizar e montar o cabeçalho: a MlbEmpresa ativa
     * com programa (D13) ou, sem ela, a Company (Gestão). Arquivada/sem dono = null (404).
     *
     * @return array{mlb_empresa: ?MlbEmpresa, company: ?Company, programa: string, chave: string}|null
     */
    public function empresaDoProduto(PubProduto $p): ?array
    {
        if ($p->mlb_empresa_id) {
            $e = MlbEmpresa::query()->ativas()->find($p->mlb_empresa_id);

            return $e?->programaPublicador() === null ? null : $this->resolver('empresa-'.$e->id);
        }

        if ($p->company_id) {
            return $this->resolver('company-'.$p->company_id);
        }

        return null;
    }

    /**
     * O objeto `empresa` do contrato das telas B e do editor. Nunca devolve access/refresh token.
     *
     * WR-B01: com `$produto` (o editor), os campos da CONTA — `token`, `link_reconexao`,
     * `conta_nome`, `conta_ml_id` — saem da âncora do PRODUTO, a mesma que confere e publica
     * (`PubProduto::conta()`); o resto continua sendo da empresa da tela. Sem `$produto`
     * (tela B), a conta é a da empresa.
     */
    public function empresaParaTela(array $alvo, ?PubProduto $produto = null): array
    {
        /** @var ?MlbEmpresa $e */
        $e = $alvo['mlb_empresa'];
        /** @var ?Company $c */
        $c = $alvo['company'];

        $ancora = $produto !== null ? $produto->contaOuNula() : PubProduto::ancoraComToken($e, $c);
        $token = $ancora === null ? 'sem_token' : ($ancora->mlToken?->isExpired() ? 'expirado' : 'ativo');

        // A reconexão é a da MlbEmpresa que daria a conta: a da tela ou, no editor, a do próprio produto
        // (reconectar uma MlbEmpresa que o produto não tem não mudaria a conta dele).
        $daReconexao = $produto !== null ? $produto->mlbEmpresa : $e;
        $linkReconexao = null;
        if ($daReconexao !== null && $token !== 'ativo') {
            $imp = MlbImplementacao::query()->where('empresa_id', $daReconexao->id)->first(['id', 'token']);
            $linkReconexao = $imp?->token ? route('implementacao.conectar-ml', ['token' => $imp->token]) : null;
        }

        $identificador = $e !== null
            ? ($c?->cnpj ?: ($e->cust_id ? 'CUST '.$e->cust_id : '#'.$e->id))
            : ($c?->cnpj ?: ($c?->ml_store_id ? 'CUST '.$c->ml_store_id : '#'.$c?->id));

        return [
            'chave' => $alvo['chave'],
            'tipo' => $e !== null ? 'mlb_empresa' : 'company',
            'id' => $e?->id ?? $c?->id,
            'nome' => $e !== null ? (string) $e->nome : (string) $c?->name,
            'identificador' => $identificador,
            'programa' => $alvo['programa'],
            'programa_rotulo' => ['polos' => 'Polos', 'incubadora' => 'Incubadora', 'gestao' => 'Gestão'][$alvo['programa']],
            'company_id' => $c?->id,
            'token' => $token,
            'link_reconexao' => $linkReconexao,
            'portal' => $this->situacaoPortal($c),
            'conta_nome' => $ancora?->nomeContaMl(),
            'conta_ml_id' => $ancora?->mlToken?->ml_user_id !== null ? (string) $ancora->mlToken->ml_user_id : null,
        ];
    }

    /**
     * Produtos da conta para a tela B e a faixa do editor, em consultas agrupadas.
     *
     * ═══ Fases e kits na lista (Fase 175 plano 08, §7) ═══════════════════════
     *
     * O retorno ganhou `fase`, `quantidade_kit`, `produto_base_id`, `eh_kit`,
     * `rotulo_fase`, `url_produto`, `sugestao_kit`, `kits` e `base`. **Nenhuma
     * chave antiga saiu nem mudou de tipo** — `Produtos.jsx`, `Editor.jsx`,
     * `contagemProdutos()` e `PainelVisaoGeralService` leem todas elas em
     * produção, e `tests/Feature/Publicador/ListaPorFaseTest.php` guarda a lista
     * contra um array literal.
     *
     * A FAMÍLIA sai em memória, sem nenhuma consulta nova: um kit herda as âncoras
     * do base (`CriarFaseService`/`VinculoDeKitService`), então os dois estão
     * sempre no MESMO escopo de conta que esta consulta já trouxe. Kit cujo base
     * foi apagado (SET NULL) fica com `base = null` e vira linha de topo — nunca
     * um órfão invisível.
     *
     * A SUGESTÃO de vínculo vem de `SugestaoDeKitService::candidatosDaConta()`,
     * UMA passada para a conta inteira (T-175-34): o número de consultas é
     * constante com 3 e com 12 produtos.
     *
     * `$chaveConta` (`empresa-N`/`company-N`) é parâmetro NOVO com default null só
     * para montar `url_produto`; sem ela, `url_produto` é null e nenhum chamador
     * antigo quebra.
     *
     * @return list<array>
     */
    public function produtosParaTela(?MlbEmpresa $e, ?Company $c, ?string $chaveConta = null): array
    {
        // `oferta` entra no eager load porque `skuExibido()`/`nomeExibido()` a leem por
        // produto (inclusive no `base` de cada kit): sem isso seria um N+1 por linha.
        $produtos = $this->produtosQuery($e, $c)->with(['mlbEmpresa.mlToken', 'company.mlToken', 'oferta'])->get();
        if ($produtos->isEmpty()) {
            return [];
        }

        // A família, em memória (ver o docblock): quem é base de quem.
        $porId = $produtos->keyBy('id');
        $kitsPorBase = [];
        foreach ($produtos as $q) {
            if ($q->produto_base_id !== null && $porId->has($q->produto_base_id)) {
                $kitsPorBase[(int) $q->produto_base_id][] = (int) $q->id;
            }
        }
        // Mesma ordem da relação `PubProduto::kits()`: fase, depois id.
        foreach ($kitsPorBase as $baseId => $ids) {
            usort($ids, fn (int $a, int $b) => [(int) $porId[$a]->fase, $a] <=> [(int) $porId[$b]->fase, $b]);
            $kitsPorBase[$baseId] = $ids;
        }

        // UMA passada de sugestão para a conta inteira — nunca uma por produto.
        $sugestoes = $this->sugestoes()->candidatosDaConta($e, $c);
        // WR-B01: a conta que a tela mostra é a da empresa; a que publica é a do produto.
        $chaveDaEmpresa = PubProduto::ancoraComToken($e, $c)?->chaveContaMl();

        $ids = $produtos->pluck('id')->all();
        $rascunhos = collect();
        $validacoes = [];
        $anuncios = [];
        $parciais = [];

        foreach (array_chunk($ids, self::LOTE) as $lote) {
            $rascunhos = $rascunhos->concat(PubRascunho::query()->whereIn('produto_id', $lote)->orderBy('id')->get());
        }
        $rascunhos = $rascunhos->groupBy('produto_id')->map(fn ($g) => $g->last());
        $rascunhoIds = $rascunhos->pluck('id')->all();

        foreach (array_chunk($rascunhoIds, self::LOTE) as $lote) {
            // Última validação por rascunho (maior id).
            PubValidacao::query()->whereIn('rascunho_id', $lote)->orderBy('id')->get()
                ->each(function ($v) use (&$validacoes) {
                    $validacoes[$v->rascunho_id] = $v;
                });

            PubPublicacaoItem::query()
                ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
                ->whereIn('pub_publicacoes.rascunho_id', $lote)
                ->orderBy('pub_publicacao_itens.id')
                ->get(['pub_publicacoes.rascunho_id as rascunho_id', 'pub_publicacao_itens.status', 'pub_publicacao_itens.ml_item_id',
                    'pub_publicacao_itens.listing_type_id', 'pub_publicacao_itens.variante_chave'])
                ->each(function ($i) use (&$anuncios, &$parciais) {
                    $chave = $i->listing_type_id.'|'.$i->variante_chave;
                    $parciais[$i->rascunho_id][$chave] = ($parciais[$i->rascunho_id][$chave] ?? false) || $i->status === PubPublicacaoItem::CREATED;
                    if ($i->status === PubPublicacaoItem::CREATED && $i->ml_item_id) {
                        $anuncios[$i->rascunho_id][$i->ml_item_id] = ['ml_item_id' => $i->ml_item_id, 'listing_type_id' => $i->listing_type_id];
                    }
                });
        }

        return $produtos->map(function (PubProduto $p) use ($rascunhos, $validacoes, $anuncios, $parciais, $chaveDaEmpresa, $porId, $kitsPorBase, $sugestoes, $chaveConta) {
            $r = $rascunhos[$p->id] ?? null;
            $conta = $p->contaOuNula();
            $status = EditorRascunhoService::prontidao($r, $r ? ($validacoes[$r->id] ?? null) : null);
            $parcial = null;
            if ($r && $r->status === PubRascunho::PARTIALLY_PUBLISHED) {
                $mapa = $parciais[$r->id] ?? [];
                $parcial = ['publicados' => count(array_filter($mapa)), 'total' => count($mapa)];
            }
            $atualizado = $p->updated_at;
            if ($r?->updated_at && (! $atualizado || $r->updated_at->gt($atualizado))) {
                $atualizado = $r->updated_at;
            }

            // O base deste kit, quando ele existe no escopo (SET NULL deixa `base` null).
            /** @var ?PubProduto $base */
            $base = $p->produto_base_id !== null ? ($porId[$p->produto_base_id] ?? null) : null;

            return [
                'id' => $p->id,
                'sku' => $p->skuExibido(),
                'nome' => $p->nomeExibido(),
                'origem' => $p->origem,
                'oferta_id' => $p->oferta_id,
                'rascunho_id' => $r?->id,
                'status' => $status,
                'status_rascunho' => $r?->status,
                'anuncios' => $r ? array_values($anuncios[$r->id] ?? []) : [],
                'parcial' => $parcial,
                'atualizado_em' => $atualizado?->toIso8601String(),
                // WR-B01: a conta do PRODUTO (a que confere e publica) e se ela difere da do cabeçalho.
                'conta_nome' => $conta?->nomeContaMl(),
                'conta_diferente' => $conta?->chaveContaMl() !== $chaveDaEmpresa,
                'liberada' => ContasLiberadas::libera($conta),

                // ─── §7 (plano 175-08): o que a coluna Fases precisa saber ───
                // Escalares, sempre: um objeto inesperado aqui derrubou a página
                // inteira em 07/10 ("Objects are not valid as a React child").
                'fase' => (int) $p->fase,
                'quantidade_kit' => (int) $p->quantidade_kit,
                'produto_base_id' => $p->produto_base_id !== null ? (int) $p->produto_base_id : null,
                'eh_kit' => $p->ehKit(),
                'rotulo_fase' => self::rotuloFase($p->quantidade_kit),
                'url_produto' => $chaveConta === null ? null
                    : route('mlb.anuncios.publicador.produto', ['conta' => $chaveConta, 'produto' => $p->id]),
                // Estruturas conhecidas (o 175-10 as lê campo por campo, nunca como texto):
                // `kits` é lista de ids, `base` é {id, sku, nome} e `sugestao_kit` é o
                // payload do 175-03 (ou null).
                'kits' => $kitsPorBase[(int) $p->id] ?? [],
                'base' => $base === null ? null : [
                    'id' => (int) $base->id, 'sku' => $base->skuExibido(), 'nome' => $base->nomeExibido(),
                ],
                'sugestao_kit' => $sugestoes[(int) $p->id] ?? null,
            ];
        })->sortByDesc('atualizado_em')->values()->all();
    }
}
