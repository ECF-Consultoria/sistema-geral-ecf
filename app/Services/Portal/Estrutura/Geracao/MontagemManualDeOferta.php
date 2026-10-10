<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Montar kit" no Planejamento (pedido do usuário, 09/10/2026): na reunião, o colaborador
 * pergunta ao cliente "faz sentido esse kit? vai ter estoque?", pega um produto, pega o
 * outro, conecta — e a oferta nasce com tudo preenchido, pronta para a Precificação e,
 * depois dela, para a equipe preparar a venda.
 *
 * - Componentes: as ofertas SIMPLES da empresa ligadas a variação (D-09), escolhidas pela
 *   variação; a oferta simples SEM variação (importada, como as da #131) entra pelo id da
 *   oferta. Até {@see ChaveDeComposicao::MAXIMO_COMPONENTES} itens.
 * - A fase, o nome/SKU sugeridos e o "Terá estoque?" saem de {@see RegrasDaMontagem}.
 * - Duplicata: a mesma composição (mesmos itens, mesmas quantidades) que já é oferta da
 *   empresa, comparada pelas ofertas e pela chave de variação do {@see RetratoDoCatalogo} —
 *   que também enxerga os kits da Fase N (o Combo N de cada cor). Responde "já existe: SKU X".
 * - Logística e frete do conjunto: o pacote SOMADO dos componentes × quantidade
 *   ({@see ConjuntoLogistico}, decisão provisória do usuário) e a estimativa de
 *   {@see FreteMe2Service::estimar} — nenhuma requisição externa. Nada disso é gravado.
 * - Gravação pela regra da Lista SKUs ({@see EstruturaOfertaService::criar}), sob a mesma
 *   trava da empresa do "Aceitar" das sugestões, com o registro de quem fez (cliente ou
 *   equipe). O que a tela manda vale só como escolha: tudo é recalculado aqui.
 *
 * A empresa vem SEMPRE do chamador (contexto do portal); id de outra empresa cai na mesma
 * mensagem de um id que não existe.
 */
class MontagemManualDeOferta
{
    public const MENSAGEM_ITEM_INVALIDO = 'Escolha produtos da sua lista.';

    public function __construct(
        private RetratoDoCatalogo $retrato,
        private EstruturaOfertaService $ofertas,
        private FreteMe2Service $frete,
    ) {}

    // ═══ O que a janela lista ═══════════════════════════════════════════════

    /**
     * Os itens que podem entrar num kit: cada produto com as variações que já têm oferta
     * simples, e as ofertas simples sem produto (importadas). Carregado só quando a
     * janela abre.
     *
     * @return array{produtos: list<array>, avulsas: list<array>, max_componentes: int}
     */
    public function catalogo(Company $empresa): array
    {
        $r = $this->retrato->daEmpresa($empresa);
        $tipos = $r['tipos'];
        $variacoes = $r['detalhes']['variacoes'];

        $produtos = [];
        foreach ($r['produtos'] as $p) {
            $doProduto = [];
            foreach ($p['variacoes'] as $v) {
                if (empty($v['oferta_id'])) {
                    continue;
                }
                $doProduto[] = [
                    'variacao_id' => (int) $v['id'],
                    'valor'       => $v['valor'],
                    'sku'         => $v['sku'],
                    'ordem'       => (int) $v['ordem'],
                    'estoque'     => $variacoes[$v['id']]['estoque'] ?? null,
                ];
            }
            if ($doProduto === []) {
                continue;
            }
            usort($doProduto, fn ($x, $y) => [$x['ordem'], $x['variacao_id']] <=> [$y['ordem'], $y['variacao_id']]);

            $produtos[] = [
                'produto_id' => (int) $p['id'],
                'nome'       => $p['nome'],
                'familia'    => $p['familia'],
                'tipo_nome'  => $p['tipo'] !== null ? ($tipos[$p['tipo']]['nome'] ?? null) : null,
                'variacoes'  => array_map(fn ($v) => array_diff_key($v, ['ordem' => true]), $doProduto),
            ];
        }
        usort($produtos, fn ($x, $y) => [TipoDoProduto::normalizar($x['nome']), $x['produto_id']] <=> [TipoDoProduto::normalizar($y['nome']), $y['produto_id']]);

        $avulsas = EstruturaOferta::query()
            ->where('company_id', $empresa->id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereNull('variacao_id')
            ->orderBy('sku')->orderBy('id')
            ->get(['id', 'sku', 'nome'])
            ->map(fn (EstruturaOferta $o) => ['oferta_id' => (int) $o->id, 'sku' => $o->sku, 'nome' => $o->nome])
            ->values()
            ->all();

        return ['produtos' => $produtos, 'avulsas' => $avulsas, 'max_componentes' => ChaveDeComposicao::MAXIMO_COMPONENTES];
    }

    // ═══ Prévia (só calcula) ════════════════════════════════════════════════

    /**
     * A prévia ao vivo: fase, nome/SKU sugeridos, "já existe", logística, frete estimado,
     * custo do conjunto e "Terá estoque?". Nada é gravado.
     *
     * @param  list<array{variacao_id?: ?int, oferta_id?: ?int, quantidade: int}>  $componentes
     * @return array<string, mixed>
     */
    public function previa(Company $empresa, array $componentes, ?string $nome = null, ?string $sku = null): array
    {
        $a = $this->analisar($empresa, $componentes, $nome, $sku);

        $conjunto = array_map(fn ($i) => [
            'produto_id'   => $i['produto_id'],
            'produto_nome' => $i['nome'],
            'quantidade'   => $i['quantidade'],
            'volumes'      => $i['volumes'],
            'custo'        => $i['custo'],
        ], $a['itens']);

        $logistica = null;
        $frete = null;
        $custo = null;
        if ($conjunto !== []) {
            // Limites do envio pela modalidade da conta já lida (cache); sem ela, os padrão.
            $av = ConjuntoLogistico::avaliar($conjunto, ModalidadeDeEnvio::emCache($empresa));
            $custo = ConjuntoLogistico::custo($conjunto);
            $logistica = [
                'chave'         => $av['logistica'],
                'pacote'        => $av['pacote'],
                'peso_faturado' => $av['peso_faturado'],
                'sem_medida'    => $av['sem_medida'],
            ];
            $frete = $this->frete->estimar($empresa, ['montagem' => [
                'pacote'        => $av['pacote'],
                'peso_faturado' => $av['peso_faturado'],
                'logistica'     => $av['logistica'],
                'custo'         => $custo,
            ]])['montagem'] ?? null;
        }

        return [
            'pronto'       => $a['fase'] !== null,
            'mensagem'     => RegrasDaMontagem::mensagem(array_column($a['itens'], 'quantidade')),
            'fase'         => $a['fase'],
            'itens'        => array_map(fn ($i) => array_diff_key($i, ['volumes' => true, 'tipo_ordem' => true, 'tipo_plural' => true]), $a['itens']),
            'ja_existe'    => $a['ja_existe'],
            'sugerido'     => $a['sugerido'],
            'nome'         => $a['nome'],
            'sku'          => $a['sku'],
            'avisos'       => $a['avisos'],
            'sku_repetido' => $a['sku_repetido'],
            'logistica'    => $logistica,
            'frete'        => $frete,
            'custo'        => $custo,
            'estoque'      => RegrasDaMontagem::estoque($a['itens']),
            'limites'      => $a['limites'],
        ];
    }

    // ═══ Gravação ═══════════════════════════════════════════════════════════

    /**
     * Cria a oferta montada à mão. Tudo é recalculado sob a trava da empresa (a mesma do
     * "Aceitar"): composição incompleta ou já existente é recusada com a mensagem da prévia.
     *
     * @param  list<array{variacao_id?: ?int, oferta_id?: ?int, quantidade: int}>  $componentes
     * @return array{oferta: array{id: int, sku: string, nome: ?string, fase: string}, absorvidos: int}
     */
    public function gravar(Company $empresa, array $componentes, ?string $nome, ?string $sku, AtorDoPortal $ator): array
    {
        return DB::transaction(function () use ($empresa, $componentes, $nome, $sku, $ator) {
            Company::whereKey($empresa->id)->lockForUpdate()->first();

            $a = $this->analisar($empresa, $componentes, $nome, $sku);

            if ($a['fase'] === null) {
                throw ValidationException::withMessages(['componentes' => RegrasDaMontagem::mensagem(array_column($a['itens'], 'quantidade'))]);
            }
            if ($a['ja_existe'] !== null) {
                throw ValidationException::withMessages(['componentes' => "Essa combinação já existe: SKU {$a['ja_existe']['sku']}."]);
            }

            [$oferta, $absorvidos] = $this->ofertas->criar($empresa, [
                'sku'         => $a['sku'],
                'nome'        => $a['nome'],
                'fase'        => $a['fase'],
                'componentes' => array_map(fn ($i) => ['id' => $i['oferta_id'], 'quantidade' => $i['quantidade']], $a['itens']),
            ], $ator);

            RegistroEstrutura::registrar($ator, $empresa, $oferta, 'oferta_montada',
                "Oferta {$oferta->sku} montada à mão no Planejamento ({$oferta->fase})", [
                    'via'          => 'planejamento',
                    'componentes'  => array_map(fn ($i) => ['oferta_id' => $i['oferta_id'], 'quantidade' => $i['quantidade']], $a['itens']),
                    'nome_editado' => $a['sugerido'] !== null && $a['nome'] !== $a['sugerido']['nome'],
                    'sku_editado'  => $a['sugerido'] !== null && $a['sku'] !== $a['sugerido']['sku'],
                ]);

            return [
                'oferta'     => ['id' => (int) $oferta->id, 'sku' => $oferta->sku, 'nome' => $oferta->nome, 'fase' => $oferta->fase],
                'absorvidos' => $absorvidos,
            ];
        });
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * Resolve os componentes DENTRO da empresa, deduz a fase e confere a duplicata e os
     * nomes. A ordem dos itens é a do nome sugerido ({@see RegrasDaMontagem::ordenar}).
     *
     * @return array{itens: list<array>, fase: ?string, ja_existe: ?array{sku: string}, sugerido: ?array{nome: string, sku: string}, nome: ?string, sku: ?string, avisos: list<array>, sku_repetido: bool, limites: array}
     */
    private function analisar(Company $empresa, array $componentes, ?string $nome, ?string $sku): array
    {
        $limites = [
            'max_titulo'      => (int) config('estrutura_geracao.max_titulo', 60),
            'max_sku'         => (int) config('estrutura_geracao.max_sku', 120),
            'max_componentes' => ChaveDeComposicao::MAXIMO_COMPONENTES,
        ];

        if (count($componentes) > ChaveDeComposicao::MAXIMO_COMPONENTES) {
            throw ValidationException::withMessages(['componentes' => 'Um kit pode juntar até '.ChaveDeComposicao::MAXIMO_COMPONENTES.' produtos.']);
        }

        $retrato = $this->retrato->daEmpresa($empresa);
        $itens = RegrasDaMontagem::ordenar($this->resolver($empresa, $componentes, $retrato));

        $quantidades = array_column($itens, 'quantidade');
        $fase = RegrasDaMontagem::fase($quantidades);
        $sugerido = RegrasDaMontagem::nomeado($itens);

        $nome = trim((string) $nome);
        $sku = trim((string) $sku);
        $nomeEfetivo = $nome !== '' ? mb_substr($nome, 0, 255) : ($sugerido['nome'] ?? null);
        $skuEfetivo = $sku !== '' ? $sku : ($sugerido['sku'] ?? null);

        return [
            'itens'        => $itens,
            'fase'         => $fase,
            'ja_existe'    => $fase === null ? null : $this->jaExiste($empresa, $itens, $retrato),
            'sugerido'     => $sugerido,
            'nome'         => $nomeEfetivo,
            'sku'          => $skuEfetivo,
            'avisos'       => $fase === null ? [] : NomesSugeridos::avisos((string) $nomeEfetivo, (string) $skuEfetivo, $limites),
            'sku_repetido' => $fase !== null && $this->skuRepetido($empresa, $skuEfetivo),
            'limites'      => $limites,
        ];
    }

    /**
     * Os componentes como itens da prévia, todos da empresa. Variação sem oferta simples
     * não entra (D-09); a oferta escolhida pelo id que é ligada a variação vira a variação.
     *
     * @return list<array<string, mixed>>
     */
    private function resolver(Company $empresa, array $componentes, array $retrato): array
    {
        $porVariacao = [];
        foreach ($retrato['produtos'] as $p) {
            foreach ($p['variacoes'] as $v) {
                $porVariacao[(int) $v['id']] = [$p, $v];
            }
        }

        $idsDeOferta = array_values(array_filter(array_map(fn ($c) => (int) ($c['oferta_id'] ?? 0), $componentes)));
        $ofertas = $idsDeOferta === [] ? collect() : EstruturaOferta::query()
            ->where('company_id', $empresa->id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('id', $idsDeOferta)
            ->get(['id', 'sku', 'nome', 'variacao_id'])
            ->keyBy('id');

        $itens = [];
        $vistas = [];
        foreach ($componentes as $c) {
            $q = (int) ($c['quantidade'] ?? 0);
            if ($q < 1 || $q > 999) {
                throw ValidationException::withMessages(['componentes' => 'Cada item precisa de uma quantidade entre 1 e 999.']);
            }

            $variacaoId = (int) ($c['variacao_id'] ?? 0);
            $ofertaId = (int) ($c['oferta_id'] ?? 0);
            $avulsa = null;

            if ($variacaoId <= 0 && $ofertaId > 0) {
                $o = $ofertas->get($ofertaId) ?? throw ValidationException::withMessages(['componentes' => self::MENSAGEM_ITEM_INVALIDO]);
                if ($o->variacao_id !== null) {
                    $variacaoId = (int) $o->variacao_id;
                } else {
                    $avulsa = $o;
                }
            }

            if ($avulsa !== null) {
                $item = $this->itemAvulso($avulsa, $q);
            } else {
                [$p, $v] = $porVariacao[$variacaoId] ?? [null, null];
                if ($p === null) {
                    throw ValidationException::withMessages(['componentes' => self::MENSAGEM_ITEM_INVALIDO]);
                }
                if (empty($v['oferta_id'])) {
                    throw ValidationException::withMessages(['componentes' => "A variação {$v['sku']} ainda não tem oferta. Salve o produto e tente de novo."]);
                }
                $item = $this->itemDaVariacao($p, $v, $q, $retrato);
            }

            if (isset($vistas[$item['oferta_id']])) {
                throw ValidationException::withMessages(['componentes' => 'O mesmo item aparece duas vezes. Some as quantidades numa linha só.']);
            }
            $vistas[$item['oferta_id']] = true;
            $itens[] = $item;
        }

        return $itens;
    }

    private function itemDaVariacao(array $p, array $v, int $q, array $retrato): array
    {
        $tipo = $p['tipo'] !== null ? ($retrato['tipos'][$p['tipo']] ?? null) : null;
        $det = $retrato['detalhes']['variacoes'][$v['id']] ?? ['volumes' => [], 'custo' => null, 'estoque' => null];
        $valor = $v['valor'] !== null && trim((string) $v['valor']) !== '' ? (string) $v['valor'] : null;

        return [
            'item'         => 'v'.$v['id'],
            'variacao_id'  => (int) $v['id'],
            'oferta_id'    => (int) $v['oferta_id'],
            'produto_id'   => (int) $p['id'],
            'produto_nome' => $p['nome'],
            'valor'        => $valor,
            'nome'         => $p['nome'].($valor !== null ? NomesSugeridos::SEPARADOR_VALOR.$valor : ''),
            'sku'          => $v['sku'],
            'quantidade'   => $q,
            'tipo'         => $p['tipo'],
            'tipo_nome'    => $tipo['nome'] ?? null,
            'tipo_plural'  => $tipo['plural'] ?? null,
            'tipo_ordem'   => $tipo['ordem'] ?? null,
            'familia'      => $p['familia'],
            'estoque'      => $det['estoque'] ?? null,
            'custo'        => $det['custo'] !== null ? (float) $det['custo'] : null,
            'volumes'      => $det['volumes'],
        ];
    }

    /** Oferta simples sem produto (importada): sem medidas nem estoque do Portal; o custo é o da Precificação. */
    private function itemAvulso(EstruturaOferta $o, int $q): array
    {
        $nome = $o->nome ?: $o->sku;
        $custo = EstruturaPrecificacao::where('oferta_id', $o->id)->value('custo');

        return [
            'item'         => 'o'.$o->id,
            'variacao_id'  => null,
            'oferta_id'    => (int) $o->id,
            'produto_id'   => null,
            'produto_nome' => $nome,
            'valor'        => null,
            'nome'         => $nome,
            'sku'          => $o->sku,
            'quantidade'   => $q,
            'tipo'         => null,
            'tipo_nome'    => null,
            'tipo_plural'  => null,
            'tipo_ordem'   => null,
            'familia'      => null,
            'estoque'      => null,
            'custo'        => $custo !== null ? (float) $custo : null,
            'volumes'      => [],
        ];
    }

    /**
     * A mesma composição já é oferta? Primeiro pelas ofertas (mesmos componentes e
     * quantidades — vale também para item sem variação); depois pela chave de variação
     * do retrato, que enxerga os kits da Fase N.
     *
     * @return array{sku: string}|null
     */
    private function jaExiste(Company $empresa, array $itens, array $retrato): ?array
    {
        $alvo = [];
        foreach ($itens as $i) {
            $alvo[(int) $i['oferta_id']] = (int) $i['quantidade'];
        }
        ksort($alvo);

        $linhas = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->whereIn('c.oferta_id', fn ($q) => $q->select('oferta_id')->from('estrutura_oferta_componentes')->whereIn('componente_id', array_keys($alvo)))
            ->orderBy('c.oferta_id')
            ->get(['c.oferta_id', 'o.sku', 'c.componente_id', 'c.quantidade']);

        $porOferta = [];
        $skus = [];
        foreach ($linhas as $l) {
            $porOferta[(int) $l->oferta_id][(int) $l->componente_id] = (int) $l->quantidade;
            $skus[(int) $l->oferta_id] = (string) $l->sku;
        }
        foreach ($porOferta as $ofertaId => $mapa) {
            ksort($mapa);
            if ($mapa === $alvo) {
                return ['sku' => $skus[$ofertaId]];
            }
        }

        $porVariacao = [];
        foreach ($itens as $i) {
            if ($i['variacao_id'] === null) {
                return null;
            }
            $porVariacao[(int) $i['variacao_id']] = (int) $i['quantidade'];
        }
        $chave = ChaveDeComposicao::de($porVariacao);
        if (isset($retrato['existentes'][$chave])) {
            return ['sku' => (string) ($retrato['detalhes']['existentes_sku'][$chave] ?? '')];
        }

        return null;
    }

    private function skuRepetido(Company $empresa, ?string $sku): bool
    {
        $normal = EstruturaOferta::normalizarSku($sku);
        if ($normal === null) {
            return false;
        }

        return EstruturaOferta::query()->where('company_id', $empresa->id)->pluck('sku')
            ->contains(fn ($s) => EstruturaOferta::normalizarSku($s) === $normal);
    }
}
