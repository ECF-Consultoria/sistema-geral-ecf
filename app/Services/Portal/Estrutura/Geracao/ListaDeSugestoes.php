<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;

/**
 * O que a tela de sugestões recebe (Fase 168): painel sobre o CONJUNTO, página no
 * servidor. Nunca filtrar nem contar no navegador (learnings §25/§27); só a PÁGINA
 * ganha logística e frete do conjunto.
 *
 * - Uma geração por chamada ({@see SugestoesService::gerar}); a empresa vem do
 *   contexto do portal, nunca do request.
 * - Frete na lista é só ESTIMATIVA pela tabela da ECF (nenhuma requisição ao ML);
 *   a cotação real existe só em {@see self::cotarPagina}, por ação explícita (D-18).
 * - Nada é gravado: frete é exibição (D-19 da 167).
 * - Status (D-28): "prontas" = sem nenhum aviso; "com_aviso" = título/SKU acima do
 *   limite, SKU já usado por oferta da empresa ou componente sem medida. O veredito
 *   usa as MESMAS fontes da página (NomesSugeridos::avisos, SKUs da empresa e
 *   ConjuntoLogistico::semMedida); nenhuma regra nova.
 * - Resumo (D-31) e ambientes do grupo (D-30) saem do conjunto, nunca da página.
 */
class ListaDeSugestoes
{
    private const ABAS  = ['sugestoes', 'sem_tipo', 'descartadas'];
    private const FASES = ['combo', 'kit', 'combit'];

    /** Valores aceitos do filtro Status (D-28); qualquer outro vira "sem filtro". */
    public const STATUS = ['prontas', 'com_aviso'];

    public function __construct(
        private SugestoesService $sugestoes,
        private FreteMe2Service $frete,
    ) {}

    /**
     * @param  array<string,mixed>  $filtros  aba, fase, familia, tipo, status, q (valores desconhecidos = sem filtro)
     * @return array<string,mixed>  além do painel e da página, traz `por_status` (D-28), `resumo` do conjunto
     *                              inteiro (D-31), `familia_ambientes` (D-30) e `gerado_em` (D-31)
     */
    public function listar(Company $empresa, array $filtros, int $pagina): array
    {
        ['sugestoes' => $todas, 'retrato' => $retrato] = $this->sugestoes->gerar($empresa);

        $detalhes   = $retrato['detalhes'];
        $porPagina  = max(1, (int) config('estrutura_geracao.por_pagina'));
        $lote       = (int) config('estrutura_geracao.lote_aceite');
        $teto       = (int) config('estrutura_geracao.teto_sugestoes');

        $vigentes    = array_values(array_filter($todas, fn ($s) => ! $s['descartada']));
        $descartadas = array_values(array_filter($todas, fn ($s) => $s['descartada']));
        $semTipo     = $this->produtosSemTipo($retrato);

        $f = $this->normalizar($filtros, $todas, $semTipo, $detalhes);

        $contagens = [
            'sugestoes'   => count($vigentes),
            'sem_tipo'    => count($semTipo),
            'descartadas' => count($descartadas),
        ];

        // ─── Conjunto da aba, filtrado no servidor ───
        $baseAba = match ($f['aba']) {
            'sem_tipo'    => $semTipo,
            'descartadas' => $descartadas,
            default       => $vigentes,
        };
        $ehSugestao = $f['aba'] !== 'sem_tipo';

        // Uma consulta só: SKUs das ofertas da empresa, para marcar o SKU sugerido que já existe.
        $skus = $f['aba'] === 'sem_tipo' ? [] : array_flip(array_filter(
            EstruturaOferta::query()->where('company_id', $empresa->id)->pluck('sku')
                ->map(fn ($x) => EstruturaOferta::normalizarSku($x))->all()
        ));

        // Veredito de aviso (D-28) sobre as pendentes: só a aba Pendentes tem status.
        $comAviso = [];
        if ($f['aba'] === 'sugestoes') {
            foreach ($vigentes as $s) {
                $comAviso[$s['chave']] = $this->temAviso($s, $detalhes, $skus);
            }
        }

        // Opções de família: aba com fase/tipo/busca, sem a família.
        $paraFamilias = $ehSugestao
            ? $this->filtrar($baseAba, $f, ['familia'], $comAviso)
            : $this->filtrarSemTipo($baseAba, $f, ['familia']);
        $familias = $this->opcoesDeFamilia($paraFamilias, $ehSugestao);

        // Contagem por fase: aba com família/tipo/busca, sem a fase.
        $semFase = $ehSugestao ? $this->filtrar($baseAba, $f, ['fase'], $comAviso) : [];
        $porFase = ['todas' => count($semFase), 'combo' => 0, 'kit' => 0, 'combit' => 0];
        foreach ($semFase as $s) {
            $porFase[$s['fase']]++;
        }

        // Contagem por status: aba com família/fase/tipo/busca, sem o status.
        $porStatus = ['todas' => 0, 'prontas' => 0, 'com_aviso' => 0];
        if ($f['aba'] === 'sugestoes') {
            foreach ($this->filtrar($baseAba, $f, ['status'], $comAviso) as $s) {
                $porStatus['todas']++;
                $porStatus[$comAviso[$s['chave']] ? 'com_aviso' : 'prontas']++;
            }
        }

        $filtrado = $ehSugestao ? $this->filtrar($baseAba, $f, [], $comAviso) : $this->filtrarSemTipo($baseAba, $f);

        $excedeu = count($filtrado) > $teto;
        if ($excedeu) {
            $filtrado = array_slice($filtrado, 0, $teto);
        }

        $familiaTotais = [];
        foreach ($filtrado as $linha) {
            $v = $this->valorFamilia($linha['familia_id'] ?? null);
            $familiaTotais[$v] = ($familiaTotais[$v] ?? 0) + 1;
        }

        // Ambientes de cada grupo de família (D-30): união sem repetir, em ordem alfabética.
        $familiaAmbientes = $this->ambientesPorFamilia($filtrado);

        // Resumo dos cartões (D-31): conjunto inteiro da aba Pendentes, sem filtro.
        $resumo = ['total' => count($vigentes), 'combo' => 0, 'kit' => 0, 'combit' => 0];
        foreach ($vigentes as $s) {
            $resumo[$s['fase']]++;
        }

        // ─── Página ───
        $total   = count($filtrado);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina  = min(max(1, $pagina), $paginas);
        $inicio  = ($pagina - 1) * $porPagina;
        $recorte = array_slice($filtrado, $inicio, $porPagina);

        $familiaContinua = null;
        if ($inicio > 0 && $recorte !== []) {
            $anterior = $filtrado[$inicio - 1];
            $primeira = $this->valorFamilia($recorte[0]['familia_id'] ?? null);
            if ($this->valorFamilia($anterior['familia_id'] ?? null) === $primeira) {
                $familiaContinua = $primeira;
            }
        }

        $itens = [];
        $produtosSemTipo = [];
        $produtos = [];

        if ($f['aba'] === 'sem_tipo') {
            foreach ($recorte as $p) {
                $produtosSemTipo[] = $this->produtoSemTipoParaPagina($p, $detalhes['tipos']);
            }
        } else {
            foreach ($recorte as $s) {
                $itens[] = $this->paraPagina($s, $detalhes, $skus);
            }
            if ($f['aba'] === 'sugestoes') {
                $itens = $this->enriquecer($empresa, $itens, $detalhes);
            }
            foreach ($recorte as $s) {
                foreach ($s['itens'] as $i) {
                    $produtos[$i['produto_id']] ??= $this->produtoParaPagina($i['produto_id'], $detalhes);
                }
            }
        }

        // ─── Lote do "aceitar os filtrados" ───
        $chavesFiltradas = [];
        $comFiltro = $f['fase'] !== null || $f['familia'] !== null || $f['tipo'] !== null || $f['status'] !== null || $f['q'] !== '';
        if ($f['aba'] === 'sugestoes' && $comFiltro) {
            foreach ($filtrado as $s) {
                if (count($chavesFiltradas) >= $lote) {
                    break;
                }
                if (! in_array('sku_longo', array_column($s['avisos'], 'codigo'), true)) {
                    $chavesFiltradas[] = $s['chave'];
                }
            }
        }

        return [
            'aba'               => $f['aba'],
            'tem_produtos'      => (bool) $detalhes['tem_produtos'],
            'contagens'         => $contagens,
            'por_fase'          => $porFase,
            'por_status'        => $porStatus,
            'resumo'            => $resumo,
            'familia_ambientes' => $familiaAmbientes,
            'gerado_em'         => now()->toIso8601String(),
            'familias'          => $familias,
            'tipos'             => $this->tiposParaPagina($detalhes),
            'itens'             => $itens,
            'produtos_sem_tipo' => $produtosSemTipo,
            'produtos'          => $produtos,
            'familia_totais'    => $familiaTotais,
            'familia_continua'  => $familiaContinua,
            'paginacao'         => ['pagina' => $pagina, 'paginas' => $paginas, 'total' => $total, 'blocos' => $total, 'por_pagina' => $porPagina],
            'chaves_filtradas'  => $chavesFiltradas,
            'excedeu_teto'      => $excedeu,
            'teto'              => $teto,
            'limites'           => [
                'max_titulo' => (int) config('estrutura_geracao.max_titulo'),
                'max_sku'    => (int) config('estrutura_geracao.max_sku'),
                'lote'       => $lote,
                'por_pagina' => $porPagina,
            ],
        ];
    }

    /**
     * Cotação real do frete (D-18): só as chaves vigentes pedidas, no máximo uma página.
     * Único caminho que pode chegar ao ML, e só por GET na conta do cliente.
     *
     * @param  list<string>  $chaves
     * @return array{fretes: array<string, array>, conectado: bool, falhou: bool, pendentes: int}
     */
    public function cotarPagina(Company $empresa, array $chaves): array
    {
        ['sugestoes' => $todas, 'retrato' => $retrato] = $this->sugestoes->gerar($empresa);

        $porPagina = max(1, (int) config('estrutura_geracao.por_pagina'));
        $pedidas   = array_flip(array_map('strval', $chaves));
        $itens     = [];

        foreach ($todas as $s) {
            if ($s['descartada'] || ! isset($pedidas[$s['chave']])) {
                continue;
            }
            if (count($itens) >= $porPagina) {
                break;
            }
            $itens[(string) $s['chave']] = $this->itemDeFrete($this->avaliar($s, $retrato['detalhes']));
        }

        if ($itens === []) {
            return ['fretes' => [], 'conectado' => AnunciosMercadoLivreService::conectado($empresa), 'falhou' => false, 'pendentes' => 0];
        }

        $r = $this->frete->cotar($empresa, $itens);

        return [
            'fretes'    => $r['fretes'],
            'conectado' => $r['conectado'],
            'falhou'    => $r['falhou'],
            'pendentes' => $r['pendentes'],
        ];
    }

    // ═══ Filtros ═══

    /**
     * Valores contra listas fechadas (T-168-27): desconhecido vira "sem filtro".
     *
     * @return array{aba: string, fase: ?string, familia: ?string, tipo: ?string, status: ?string, q: string}
     */
    private function normalizar(array $filtros, array $todas, array $semTipo, array $detalhes): array
    {
        $aba = (string) ($filtros['aba'] ?? 'sugestoes');
        $aba = in_array($aba, self::ABAS, true) ? $aba : 'sugestoes';

        $fase = $filtros['fase'] ?? null;
        $fase = is_string($fase) && in_array($fase, self::FASES, true) ? $fase : null;

        $tipo = $filtros['tipo'] ?? null;
        $tipo = is_string($tipo) && isset($detalhes['tipos'][$tipo]) ? $tipo : null;

        $validas = [];
        foreach ($todas as $s) {
            $validas[$this->valorFamilia($s['familia_id'] ?? null)] = true;
        }
        foreach ($semTipo as $p) {
            $validas[$this->valorFamilia($p['familia_id'] ?? null)] = true;
        }
        $familia = $filtros['familia'] ?? null;
        $familia = (is_string($familia) || is_int($familia)) && isset($validas[(string) $familia]) ? (string) $familia : null;

        $status = $filtros['status'] ?? null;
        $status = is_string($status) && in_array($status, self::STATUS, true) ? $status : null;

        $q = TipoDoProduto::normalizar(is_string($filtros['q'] ?? null) ? mb_substr($filtros['q'], 0, 120) : '');

        return ['aba' => $aba, 'fase' => $fase, 'familia' => $familia, 'tipo' => $tipo, 'status' => $status, 'q' => $q];
    }

    /**
     * @param  list<array<string,mixed>>  $lista
     * @param  list<string>  $ignorar  filtros que não entram (para as contagens das facetas)
     * @param  array<string,bool>  $comAviso  chave → tem aviso (só preenchido na aba sugestoes)
     * @return list<array<string,mixed>>
     */
    private function filtrar(array $lista, array $f, array $ignorar = [], array $comAviso = []): array
    {
        $saida = [];

        foreach ($lista as $s) {
            if ($f['aba'] === 'sugestoes' && ! in_array('status', $ignorar, true) && $f['status'] !== null
                && ($comAviso[$s['chave']] ?? false) !== ($f['status'] === 'com_aviso')) {
                continue;
            }
            if (! in_array('familia', $ignorar, true) && $f['familia'] !== null
                && $this->valorFamilia($s['familia_id'] ?? null) !== $f['familia']) {
                continue;
            }
            if (! in_array('fase', $ignorar, true) && $f['fase'] !== null && $s['fase'] !== $f['fase']) {
                continue;
            }
            if ($f['tipo'] !== null && ! in_array($f['tipo'], array_column($s['itens'], 'tipo'), true)) {
                continue;
            }
            if ($f['q'] !== '' && ! str_contains($this->textoDeBusca($s), $f['q'])) {
                continue;
            }
            $saida[] = $s;
        }

        return $saida;
    }

    /** @return list<array<string,mixed>> */
    private function filtrarSemTipo(array $lista, array $f, array $ignorar = []): array
    {
        $saida = [];

        foreach ($lista as $p) {
            if (! in_array('familia', $ignorar, true) && $f['familia'] !== null
                && $this->valorFamilia($p['familia_id'] ?? null) !== $f['familia']) {
                continue;
            }
            if ($f['q'] !== '' && ! str_contains(TipoDoProduto::normalizar($p['nome'].' '.($p['categoria'] ?? '')), $f['q'])) {
                continue;
            }
            $saida[] = $p;
        }

        return $saida;
    }

    private function textoDeBusca(array $s): string
    {
        $partes = [$s['nome'], $s['sku']];
        foreach ($s['itens'] as $i) {
            $partes[] = $i['produto_nome'];
            $partes[] = $i['sku'];
        }

        return TipoDoProduto::normalizar(implode(' ', array_map('strval', $partes)));
    }

    /**
     * Ambientes de cada valor de família, sem repetir pela forma normalizada e em
     * ordem alfabética (guarda o texto da primeira ocorrência).
     *
     * @param  list<array<string,mixed>>  $lista
     * @return array<string, list<string>>
     */
    private function ambientesPorFamilia(array $lista): array
    {
        $porFamilia = [];
        foreach ($lista as $linha) {
            $v = $this->valorFamilia($linha['familia_id'] ?? null);
            $porFamilia[$v] ??= [];
            foreach ($linha['ambientes'] ?? [] as $a) {
                $porFamilia[$v][TipoDoProduto::normalizar((string) $a)] ??= (string) $a;
            }
        }

        foreach ($porFamilia as $v => $ambientes) {
            ksort($ambientes);
            $porFamilia[$v] = array_values($ambientes);
        }

        return $porFamilia;
    }

    private function valorFamilia(?int $id): string
    {
        return $id === null ? 'sem' : (string) $id;
    }

    /**
     * @return list<array{valor: string, nome: string, total: int}>
     */
    private function opcoesDeFamilia(array $lista, bool $ehSugestao): array
    {
        $contagem = [];
        $nomes    = [];

        foreach ($lista as $linha) {
            $v = $this->valorFamilia($linha['familia_id'] ?? null);
            $contagem[$v] = ($contagem[$v] ?? 0) + 1;
            $nomes[$v]    = $linha['familia'] ?? 'Sem família';
        }

        $opcoes = [];
        foreach ($contagem as $valor => $total) {
            if ($valor !== 'sem') {
                $opcoes[] = ['valor' => (string) $valor, 'nome' => (string) $nomes[$valor], 'total' => $total];
            }
        }
        usort($opcoes, fn ($a, $b) => [TipoDoProduto::normalizar($a['nome']), (int) $a['valor']] <=> [TipoDoProduto::normalizar($b['nome']), (int) $b['valor']]);

        if (isset($contagem['sem'])) {
            $opcoes[] = ['valor' => 'sem', 'nome' => 'Sem família', 'total' => $contagem['sem']];
        }

        return $opcoes;
    }

    // ═══ Produtos e tipos ═══

    /**
     * Produtos sem tipo efetivo (nem escolhido nem inferido sem ambiguidade).
     *
     * @return list<array<string,mixed>>
     */
    private function produtosSemTipo(array $retrato): array
    {
        $familiaIds = [];
        foreach ($retrato['produtos'] as $p) {
            $familiaIds[$p['id']] = $p['familia_id'] ?? null;
        }

        $saida = [];
        foreach ($retrato['detalhes']['produtos'] as $id => $d) {
            if ($d['tipo'] !== null) {
                continue;
            }
            $saida[] = ['id' => (int) $id, 'familia_id' => $familiaIds[$id] ?? null] + $d;
        }

        usort($saida, fn ($a, $b) => [TipoDoProduto::normalizar($a['familia'] ?? ''), $a['familia'] === null ? 1 : 0, TipoDoProduto::normalizar($a['nome']), $a['id']]
            <=> [TipoDoProduto::normalizar($b['familia'] ?? ''), $b['familia'] === null ? 1 : 0, TipoDoProduto::normalizar($b['nome']), $b['id']]);

        return $saida;
    }

    /** @return list<array{slug: string, nome: string}> */
    private function candidatos(array $d, array $tipos): array
    {
        $saida = [];
        foreach ($d['candidatos'] ?? [] as $slug) {
            if (isset($tipos[$slug])) {
                $saida[] = ['slug' => (string) $slug, 'nome' => $tipos[$slug]['nome']];
            }
        }

        return $saida;
    }

    private function produtoSemTipoParaPagina(array $p, array $tipos): array
    {
        return [
            'id'             => $p['id'],
            'nome'           => $p['nome'],
            'categoria'      => $p['categoria'],
            'familia'        => $p['familia'],
            'ambientes'      => $p['ambientes'],
            'candidatos'     => $this->candidatos($p, $tipos),
            'tipo_escolhido' => $p['tipo_escolhido'],
            'qtd_combo'      => $p['qtd_combo'],
            'qtd_combit'     => $p['qtd_combit'],
        ];
    }

    private function produtoParaPagina(int $id, array $detalhes): array
    {
        $d = $detalhes['produtos'][$id] ?? [];
        $tipos = $detalhes['tipos'];

        return [
            'id'             => $id,
            'nome'           => $d['nome'] ?? '',
            'tipo'           => $d['tipo'] ?? null,
            'tipo_nome'      => isset($d['tipo'], $tipos[$d['tipo']]) ? $tipos[$d['tipo']]['nome'] : null,
            'tipo_origem'    => $d['tipo_origem'] ?? null,
            'tipo_escolhido' => $d['tipo_escolhido'] ?? null,
            'candidatos'     => $this->candidatos($d, $tipos),
            'qtd_combo'      => $d['qtd_combo'] ?? null,
            'qtd_combit'     => $d['qtd_combit'] ?? null,
            'familia'        => $d['familia'] ?? null,
            'ambientes'      => $d['ambientes'] ?? [],
            'categoria'      => $d['categoria'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function tiposParaPagina(array $detalhes): array
    {
        $saida = [];
        foreach ($detalhes['tipos'] as $slug => $t) {
            $saida[] = [
                'id'         => $t['id'],
                'slug'       => (string) $slug,
                'nome'       => $t['nome'],
                'plural'     => $t['plural'],
                'qtd_combo'  => $t['qtd_combo_texto'],
                'qtd_combit' => $t['qtd_combit_texto'],
            ];
        }

        return $saida;
    }

    // ═══ Item da página (único ponto de tradução do gerador para a tela) ═══

    /**
     * @return array<string,mixed>
     */
    private function paraPagina(array $s, array $detalhes, array $skus): array
    {
        $tipos = $detalhes['tipos'];

        return [
            'chave'         => $s['chave'],
            'fase'          => $s['fase'],
            'familia'       => ['id' => $s['familia_id'] ?? null, 'nome' => $s['familia'] ?? null],
            'ambientes'     => $s['ambientes'],
            'itens'         => array_map(fn ($i) => [
                'variacao_id'  => $i['variacao_id'],
                'produto_id'   => $i['produto_id'],
                'produto_nome' => $i['produto_nome'],
                'valor'        => $i['valor'],
                'sku'          => $i['sku'],
                'quantidade'   => $i['quantidade'],
                'tipo'         => $i['tipo'],
                'tipo_nome'    => $i['tipo'] !== null && isset($tipos[$i['tipo']]) ? $tipos[$i['tipo']]['nome'] : null,
            ], $s['itens']),
            'nome'          => $s['nome'],
            'sku'           => $s['sku'],
            'porque'        => $s['porque'],
            'avisos'        => $s['avisos'],
            'sku_repetido'  => $this->skuRepetido($s['sku'], $skus),
            'logistica'     => null,
            'frete'         => null,
            'custo'         => null,
            'descartada_em' => ($s['descartada'] ?? false) ? ($s['descartada_em'] ?: null) : null,
        ];
    }

    private function skuRepetido(?string $sku, array $skus): bool
    {
        $normal = EstruturaOferta::normalizarSku($sku);

        return $normal !== null && isset($skus[$normal]);
    }

    /**
     * Mesmo veredito da página (D-28): aviso do gerador, SKU já usado ou componente sem medida.
     *
     * @param  array<string,mixed>  $s  sugestão do gerador
     */
    private function temAviso(array $s, array $detalhes, array $skus): bool
    {
        return $s['avisos'] !== []
            || $this->skuRepetido($s['sku'], $skus)
            || ConjuntoLogistico::semMedida($this->conjunto($s, $detalhes)) !== [];
    }

    /**
     * Logística e frete estimado só da PÁGINA, numa única chamada ao FreteMe2Service
     * (sem HTTP). Nada é gravado.
     */
    private function enriquecer(Company $empresa, array $itens, array $detalhes): array
    {
        if ($itens === []) {
            return $itens;
        }

        $avaliados = [];
        $paraFrete = [];

        foreach ($itens as $k => $item) {
            $av = $this->avaliar($item, $detalhes);
            $avaliados[$k] = $av;
            $paraFrete[(string) $item['chave']] = $this->itemDeFrete($av);
        }

        $fretes = $this->frete->estimar($empresa, $paraFrete);

        foreach ($itens as $k => $item) {
            $av = $avaliados[$k];
            $itens[$k]['logistica'] = [
                'chave'         => $av['logistica']['logistica'],
                'pacote'        => $av['logistica']['pacote'],
                'peso_faturado' => $av['logistica']['peso_faturado'],
                'sem_medida'    => $av['logistica']['sem_medida'],
            ];
            $itens[$k]['frete'] = $fretes[(string) $item['chave']] ?? null;
            $itens[$k]['custo'] = $av['custo'];
        }

        return $itens;
    }

    /**
     * @param  array<string,mixed>  $s  sugestão (do gerador ou já no formato da página: ambos têm `itens`)
     * @return array{logistica: array, custo: ?float}
     */
    private function avaliar(array $s, array $detalhes): array
    {
        $conjunto = $this->conjunto($s, $detalhes);

        return [
            'logistica' => ConjuntoLogistico::avaliar($conjunto),
            'custo'     => ConjuntoLogistico::custo($conjunto),
        ];
    }

    /**
     * Componentes da sugestão com volumes e custo por variação.
     *
     * @return list<array{produto_id: int, produto_nome: string, quantidade: int, volumes: list<array>, custo: ?float}>
     */
    private function conjunto(array $s, array $detalhes): array
    {
        $conjunto = [];
        foreach ($s['itens'] as $i) {
            $v = $detalhes['variacoes'][$i['variacao_id']] ?? ['volumes' => [], 'custo' => null];
            $conjunto[] = [
                'produto_id'   => $i['produto_id'],
                'produto_nome' => $i['produto_nome'],
                'quantidade'   => (int) $i['quantidade'],
                'volumes'      => $v['volumes'],
                'custo'        => $v['custo'] !== null ? (float) $v['custo'] : null,
            ];
        }

        return $conjunto;
    }

    /** @return array{pacote: ?array, peso_faturado: ?float, logistica: string, custo: ?float} */
    private function itemDeFrete(array $av): array
    {
        return [
            'pacote'        => $av['logistica']['pacote'],
            'peso_faturado' => $av['logistica']['peso_faturado'],
            'logistica'     => $av['logistica']['logistica'],
            'custo'         => $av['custo'],
        ];
    }
}
