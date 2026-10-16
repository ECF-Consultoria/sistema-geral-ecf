<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAcervoItem;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * As ofertas compostas que a empresa JÁ tem — os combos, kits e combits aceitos ou montados no
 * Planejamento — para a aba "Criadas" (13/10/2026).
 *
 * O usuário: "em Planejamento deveriam ficar os produtos que planejamos… aceitou, ele fica ali". Até aqui a
 * sugestão aceita sumia da tela (as abas eram só Pendentes, Sem tipo e Descartadas) e o único rastro era um
 * aviso de alguns segundos; quem montava um combo não tinha onde vê-lo de novo dentro do Planejamento.
 *
 * Nada é gravado e nada novo é guardado: a lista sai de `estrutura_ofertas` + componentes, e o preço e o
 * envio de cada uma saem da mesma conta da Precificação ({@see EstruturaPrecificacaoService::pagina()}),
 * para as duas telas nunca discordarem. "Criada" é toda oferta composta da empresa, venha de onde vier —
 * a tabela não guarda a origem (ver o activity log para "veio do Planejamento").
 *
 * 16/10/2026 — cada componente leva a sua `foto`. O usuário, vendo a lista só com códigos: "não dá para saber
 * quais são os produtos, quais foram os kits… visualmente pela foto". A foto é a capa da galeria da variação
 * (a mesma da lista de Produtos), na MINIATURA de {@see VariacaoImagensService::MINIATURA}px — as originais
 * de produção têm 280–690 KB para um quadro de 132 px — e, sem galeria, a miniatura do anúncio no acervo do
 * Mercado Livre. Nada é buscado na API.
 *
 * 16/10/2026 — filtros, ordem e páginas ("deveria ter filtros, modo lista também"). Saiu o teto de 300 mais
 * recentes: com 338 criadas, os 300 mais recentes eram todos Combos e os Kits e Combits SUMIAM da aba, sem
 * aviso. Agora a lista inteira é filtrada (tipo, situação do preço, envio, busca), ordenada e paginada aqui.
 * A Precificação já calcula TODAS as ofertas da empresa a cada página (o resumo dela precisa), então filtrar
 * pela situação não custa uma conta a mais. Cada filtro conta as opções com os OUTROS filtros aplicados.
 */
class OfertasCriadas
{
    /** A ordem dos grupos na tela: os grupos de tipo continuam contíguos de uma página para a outra. */
    private const TIPOS = [
        EstruturaOferta::FASE_COMBO  => 'Combos',
        EstruturaOferta::FASE_KIT    => 'Kits',
        EstruturaOferta::FASE_COMBIT => 'Combits',
    ];

    /** Situação do preço: `pendente` junta as três pendências. */
    public const SITUACOES = ['com_preco', 'pendente', 'sem_custo', 'sem_frete', 'impossivel'];

    /** Envio da oferta, como o selo da Precificação: `declarada` é transportadora ou "a combinar" da Lista SKUs. */
    public const ENVIOS = ['me2', 'me2_full', 'me1', 'pendente', 'declarada'];

    public const ORDENS = ['recentes', 'pendentes', 'nome', 'preco_maior', 'preco_menor'];

    public const POR_PAGINA = 60;

    public function __construct(private EstruturaPrecificacaoService $precos) {}

    public function contar(Company $empresa): int
    {
        return $this->consulta($empresa)->count();
    }

    /**
     * @param  array{fase?: ?string, situacao?: ?string, envio?: ?string, q?: ?string, ordem?: ?string}  $filtros
     * @return array{total: int, filtrado: int, contagens: array{fase: array<string, int>, situacao: array<string, int>, envio: array<string, int>}, grupos: list<array{fase: string, rotulo: string, itens: list<array<string, mixed>>}>, paginacao: array{pagina: int, paginas: int, por_pagina: int, blocos: int}}
     */
    public function listar(Company $empresa, array $filtros = [], int $pagina = 1): array
    {
        // Linhas cruas, não modelos: com 3.000 criadas, hidratar Eloquent com os componentes custava ~0,5 s (medido).
        $ofertas = DB::table('estrutura_ofertas')
            ->where('company_id', $empresa->id)->whereIn('fase', array_keys(self::TIPOS))
            ->orderByDesc('id')
            ->get(['id', 'sku', 'fase', 'nome', 'created_at']);
        $componentes = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as dona', 'dona.id', '=', 'c.oferta_id')
            ->leftJoin('estrutura_ofertas as comp', 'comp.id', '=', 'c.componente_id')
            ->where('dona.company_id', $empresa->id)->whereIn('dona.fase', array_keys(self::TIPOS))
            ->orderBy('c.id')
            ->get(['c.oferta_id', 'c.componente_id', 'c.quantidade', 'comp.sku', 'comp.nome', 'comp.variacao_id']);

        $porOferta = [];
        $idsDosComponentes = [];
        foreach ($componentes as $c) {
            $porOferta[(int) $c->oferta_id][] = $c;
            $idsDosComponentes[(int) $c->componente_id] = true;
        }

        // Uma conta só para as compostas E os componentes: o custo de cada componente diz qual falta.
        $ids = array_keys(array_flip(array_map('intval', $ofertas->pluck('id')->all())) + $idsDosComponentes);
        $calculos = $ids === [] ? [] : $this->precos->pagina($empresa, $ids)['por_oferta'];

        $todos = [];
        foreach ($ofertas as $o) {
            $todos[] = $this->item($o, $porOferta[(int) $o->id] ?? [], $calculos);
        }

        $f = [
            'fase'     => in_array($filtros['fase'] ?? null, array_keys(self::TIPOS), true) ? $filtros['fase'] : null,
            'situacao' => in_array($filtros['situacao'] ?? null, self::SITUACOES, true) ? $filtros['situacao'] : null,
            'envio'    => in_array($filtros['envio'] ?? null, self::ENVIOS, true) ? $filtros['envio'] : null,
            'q'        => self::normalizar((string) ($filtros['q'] ?? '')),
        ];
        $ordem = in_array($filtros['ordem'] ?? null, self::ORDENS, true) ? $filtros['ordem'] : 'recentes';

        // Tirar acento custa: só com busca (medido em 3.000 criadas, era o grosso do tempo além da conta de preço).
        if ($f['q'] !== '') {
            $todos = array_map(fn ($i) => ['_busca' => self::normalizar($i['_busca'])] + $i, $todos);
        }

        $filtrados = array_values(array_filter($todos, fn ($i) => $this->passa($i, $f)));
        $filtrados = $this->ordenar($filtrados, $ordem);

        $paginas = max(1, (int) ceil(count($filtrados) / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $daPagina = array_slice($filtrados, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        // As fotos e a data só da página: é o que vai para a tela.
        $daTela = [];
        foreach ($daPagina as $item) {
            foreach ($porOferta[$item['id']] ?? [] as $c) {
                $daTela[(int) $c->componente_id] = (object) ['id' => (int) $c->componente_id, 'variacao_id' => $c->variacao_id];
            }
        }
        $fotos = $this->fotos($empresa, array_values($daTela));

        $grupos = [];
        foreach ($daPagina as $item) {
            $item['composicao'] = array_map(fn ($c) => $c + ['foto' => $fotos[$c['id']] ?? null], $item['composicao']);
            $item['criada_em'] = $item['criada_em'] ? Carbon::parse($item['criada_em'])->toIso8601String() : null;
            unset($item['_busca']);
            $grupos[$item['fase']] ??= ['fase' => $item['fase'], 'rotulo' => self::TIPOS[$item['fase']], 'itens' => []];
            $grupos[$item['fase']]['itens'][] = $item;
        }

        return [
            'total'     => count($todos),
            'filtrado'  => count($filtrados),
            'contagens' => $this->contagens($todos, $f),
            'grupos'    => array_values($grupos),
            'paginacao' => ['pagina' => $pagina, 'paginas' => $paginas, 'por_pagina' => self::POR_PAGINA, 'blocos' => count($filtrados)],
        ];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * O item da lista, sem foto (ela vem só para a página). `_busca` é o texto em que a busca procura.
     *
     * @param  object  $o  linha de `estrutura_ofertas`
     * @param  list<object>  $componentes  linhas de `estrutura_oferta_componentes` com sku/nome do componente
     */
    private function item(object $o, array $componentes, array $calculos): array
    {
        $c = $calculos[(int) $o->id] ?? null;
        $composicao = array_map(fn ($x) => [
            'id'         => (int) $x->componente_id,
            'sku'        => $x->sku,
            'nome'       => $x->nome,
            'quantidade' => (int) $x->quantidade,
        ], $componentes);

        // Qual componente está sem custo — o custo vem de Produtos, e é lá que a pessoa resolve.
        $faltamCusto = $c !== null && ($c['pendencia'] ?? null) === PrecificacaoEstrutura::PENDENCIA_SEM_CUSTO
            ? array_values(array_filter(array_map(
                fn ($x) => array_key_exists($x['id'], $calculos) && ($calculos[$x['id']]['custo']['valor'] ?? null) === null ? $x['sku'] : null,
                $composicao)))
            : [];

        return [
            'id'           => (int) $o->id,
            'sku'          => $o->sku,
            'nome'         => $o->nome,
            'fase'         => $o->fase,
            'criada_em'    => $o->created_at,   // vira ISO 8601 só na página
            'composicao'   => $composicao,
            // A pendência do preço (null = precificada) e o envio, como a Precificação os mostra.
            'pendencia'    => $c['pendencia'] ?? null,
            'sem_calculo'  => $c === null,
            'envio'        => $c['envio'] ?? null,
            'preco'        => $c === null ? null : [
                'classico' => $c['classico']['anunciado'] ?? null,
                'premium'  => $c['premium']['anunciado'] ?? null,
            ],
            'faltam_custo' => $faltamCusto,
            // Cru: só é normalizado quando há busca.
            '_busca'       => implode(' ', array_filter([$o->sku, $o->nome, ...array_column($composicao, 'sku'), ...array_column($composicao, 'nome')])),
        ];
    }

    /** Situação do preço do item, no vocabulário do filtro. */
    private static function situacao(array $i): string
    {
        if ($i['sem_calculo']) {
            return 'sem_calculo';
        }

        return $i['pendencia'] ?? 'com_preco';
    }

    /** Envio do item, no vocabulário do filtro (o mesmo do selo `seloDoEnvio` da tela). */
    private static function envio(array $i): ?string
    {
        if (($i['envio']['declarada'] ?? null) !== null) {
            return 'declarada';
        }

        return $i['envio']['chave'] ?? null;
    }

    /** O item passa nos filtros? `$ignorar` deixa um filtro de fora — é assim que cada um conta as suas opções. */
    private function passa(array $i, array $f, ?string $ignorar = null): bool
    {
        if ($ignorar !== 'fase' && $f['fase'] !== null && $i['fase'] !== $f['fase']) {
            return false;
        }
        if ($ignorar !== 'situacao' && $f['situacao'] !== null) {
            $s = self::situacao($i);
            if ($f['situacao'] === 'pendente' ? $s === 'com_preco' : $s !== $f['situacao']) {
                return false;
            }
        }
        if ($ignorar !== 'envio' && $f['envio'] !== null && self::envio($i) !== $f['envio']) {
            return false;
        }

        return $f['q'] === '' || str_contains($i['_busca'], $f['q']);
    }

    /** As contagens de cada opção de cada filtro, com os outros filtros aplicados. */
    private function contagens(array $todos, array $f): array
    {
        $fase = ['todas' => 0] + array_fill_keys(array_keys(self::TIPOS), 0);
        $situacao = ['todas' => 0] + array_fill_keys(self::SITUACOES, 0);
        $envio = ['todas' => 0] + array_fill_keys(self::ENVIOS, 0);

        foreach ($todos as $i) {
            if ($this->passa($i, $f, 'fase')) {
                $fase['todas']++;
                $fase[$i['fase']]++;
            }
            if ($this->passa($i, $f, 'situacao')) {
                $situacao['todas']++;
                $s = self::situacao($i);
                if (isset($situacao[$s])) {
                    $situacao[$s]++;
                }
                if ($s !== 'com_preco') {
                    $situacao['pendente']++;
                }
            }
            if ($this->passa($i, $f, 'envio')) {
                $envio['todas']++;
                $e = self::envio($i);
                if ($e !== null && isset($envio[$e])) {
                    $envio[$e]++;
                }
            }
        }

        return ['fase' => $fase, 'situacao' => $situacao, 'envio' => $envio];
    }

    /** Ordena dentro de cada tipo (Combos, Kits, Combits, nessa ordem); o desempate é o mais recente primeiro. */
    private function ordenar(array $itens, string $ordem): array
    {
        $posicao = array_flip(array_keys(self::TIPOS));
        $preco = fn ($i) => $i['pendencia'] === null && ! $i['sem_calculo'] ? ($i['preco']['classico'] ?? null) : null;
        // A chave do nome uma vez por item, não a cada comparação.
        $nomes = $ordem === 'nome' ? array_column(array_map(fn ($i) => [$i['id'], self::normalizar($i['nome'] ?: $i['sku'])], $itens), 1, 0) : [];

        usort($itens, function ($a, $b) use ($ordem, $posicao, $preco, $nomes) {
            $porTipo = $posicao[$a['fase']] <=> $posicao[$b['fase']];
            if ($porTipo !== 0) {
                return $porTipo;
            }

            $criterio = match ($ordem) {
                'pendentes'   => (self::situacao($a) === 'com_preco') <=> (self::situacao($b) === 'com_preco'),
                'nome'        => strnatcasecmp($nomes[$a['id']], $nomes[$b['id']]),
                // Sem preço vai para o fim nas duas ordens de preço.
                'preco_maior' => [$preco($a) === null, -($preco($a) ?? 0)] <=> [$preco($b) === null, -($preco($b) ?? 0)],
                'preco_menor' => [$preco($a) === null, $preco($a) ?? 0] <=> [$preco($b) === null, $preco($b) ?? 0],
                default       => 0,
            };

            return $criterio !== 0 ? $criterio : $b['id'] <=> $a['id'];
        });

        return $itens;
    }

    /** Minúsculas e sem acento: "Luminária" acha "luminaria". */
    private static function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii(trim($texto)));
    }

    /**
     * A foto de cada componente, em duas consultas para a página inteira: a capa da galeria da variação
     * (ordem 0, como em {@see \App\Services\Portal\Estrutura\Produtos\ProdutoLinhas}), na miniatura, e, para
     * quem não tem galeria, a miniatura do acervo do primeiro anúncio ativo com MLB. Sem nenhuma das duas, fica
     * de fora.
     *
     * @param  list<object{id: int, variacao_id: ?int}>  $componentes
     * @return array<int, string> oferta_id do componente => URL
     */
    private function fotos(Company $empresa, array $componentes): array
    {
        if ($componentes === []) {
            return [];
        }

        $capaDaVariacao = [];
        $variacoes = array_values(array_unique(array_filter(array_map(fn ($c) => $c->variacao_id ? (int) $c->variacao_id : null, $componentes))));
        if ($variacoes !== []) {
            EstruturaProdutoVariacaoImagem::query()
                ->where('company_id', $empresa->id)
                ->whereIn('variacao_id', $variacoes)
                ->orderBy('variacao_id')->orderBy('ordem')->orderBy('id')
                ->get(['id', 'variacao_id'])
                ->each(function (EstruturaProdutoVariacaoImagem $i) use (&$capaDaVariacao) {
                    $capaDaVariacao[(int) $i->variacao_id] ??= VariacaoImagensService::urlMiniatura((int) $i->variacao_id, (int) $i->id);
                });
        }

        $fotos = [];
        $semGaleria = [];
        foreach ($componentes as $c) {
            $capa = $c->variacao_id ? ($capaDaVariacao[(int) $c->variacao_id] ?? null) : null;
            if ($capa !== null) {
                $fotos[(int) $c->id] = $capa;
            } else {
                $semGaleria[] = (int) $c->id;
            }
        }

        if ($semGaleria !== []) {
            $anuncios = EstruturaAnuncio::query()
                ->whereIn('oferta_id', $semGaleria)
                ->where('status', '!=', EstruturaAnuncio::STATUS_INATIVO)
                ->whereNotNull('codigo_mlb')
                ->orderBy('id')
                ->get(['oferta_id', 'codigo_mlb']);

            $miniaturas = $anuncios->isEmpty() ? collect() : MlAcervoItem::query()
                ->where('company_id', $empresa->id)
                ->whereIn('ml_item_id', $anuncios->pluck('codigo_mlb')->unique()->values()->all())
                ->whereNotNull('thumbnail')
                ->pluck('thumbnail', 'ml_item_id');

            foreach ($anuncios as $a) {
                $miniatura = $miniaturas[$a->codigo_mlb] ?? null;
                if ($miniatura && ! isset($fotos[(int) $a->oferta_id])) {
                    $fotos[(int) $a->oferta_id] = preg_replace('#^http://#', 'https://', $miniatura);
                }
            }
        }

        return $fotos;
    }

    private function consulta(Company $empresa)
    {
        return EstruturaOferta::query()->where('company_id', $empresa->id)->whereIn('fase', array_keys(self::TIPOS));
    }
}
