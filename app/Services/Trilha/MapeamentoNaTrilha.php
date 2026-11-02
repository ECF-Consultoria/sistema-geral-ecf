<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\FotosDasVariacoes;
use App\Services\Portal\Estrutura\Geracao\MontagemManualDeOferta;
use App\Services\Portal\Estrutura\Geracao\RetratoDoCatalogo;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Geracao\VariacoesEmParalelo;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O Mapeamento estrutural DENTRO da trilha (2ª entrega, 02/11/2026), como o painel "Mapear" do artefato: o produto
 * completo vira oferta simples sozinho (ela já nasceu no cadastro, uma por cor) e aqui se decide o que combina —
 * combo (o mesmo produto em mais unidades), kit (com outros produtos) e combit (com outros, algum em mais unidades),
 * ou "Juntar todos" numa oferta só.
 *
 * O artefato compõe por PRODUTO; o sistema, por COR (learnings da trilha §2) — quem expande é o servidor:
 * - Combo N → um combo por cor que tem Simples: [Simples da cor × N] (um item `c-{p}-{N}` na trilha);
 * - parceiro com 1× → um Kit por par de cores casado em PARALELO; com 2× ou mais, um Combit [P×1, X×q];
 * - "Juntar todos" → um Kit/Combit de 3 ou mais por cor da âncora ({@see VariacoesEmParalelo::casarComAncora()}).
 *
 * Cada composição passa pela MESMA régua do "Montar kit" do Portal ({@see MontagemManualDeOferta::analisar()}): a
 * fase, o nome e o SKU do sistema (KT-…, CT{n}-…, -CB{n}), o "já existe" (pela composição — inclusive o Kit N do
 * Publicador) e os avisos. A prévia e a gravação usam o mesmo {@see planejar()}: nunca divergem. A gravação é a do
 * aceite do Portal ({@see EstruturaOfertaService::criar()}), sob a trava da empresa, tudo ou nada.
 */
class MapeamentoNaTrilha
{
    /** As quantidades de combo que o painel sempre oferece (as do artefato). */
    public const COMBOS_PADRAO = [2, 3, 4, 6];

    public const MAX_QUANTIDADE_PARCEIRO = 6;

    public function __construct(
        private MontagemManualDeOferta $montagem,
        private RetratoDoCatalogo $retrato,
        private SugestoesService $sugestoes,
        private EstruturaOfertaService $ofertas,
        private FotosDasVariacoes $fotos,
    ) {}

    // ═══ O que o painel mostra ══════════════════════════════════════════════

    /**
     * O produto, as quantidades de combo (as do artefato + as que o sistema sugere) e os parceiros possíveis: os
     * OUTROS produtos fora da Lista, primeiro os que o sistema sugere com este, depois os de ambiente em comum,
     * depois por nome. Nada vem marcado: a sugestão só define a ordem e a etiqueta.
     */
    public function opcoes(Company $empresa, EstruturaProduto $produto): array
    {
        $gerado = $this->sugestoes->gerar($empresa);
        $retrato = $gerado['retrato'];
        $doRetrato = self::produtosDoRetrato($retrato);
        $p = $doRetrato[(int) $produto->id] ?? null;
        $faltas = TrilhaDaEmpresa::faltasDosProdutos($empresa);
        $this->conferirForaDaLista($produto, $faltas);

        $sugeridos = self::sugeridos($gerado['sugestoes'], (int) $produto->id);
        $varsP = self::comOferta($p['variacoes'] ?? []);

        $quantidades = array_values(array_unique([...self::COMBOS_PADRAO, ...$sugeridos['combos'], ...(array) ($p['qtd_combo'] ?? [])]));
        sort($quantidades);
        $combos = [];
        foreach ($quantidades as $n) {
            $n = (int) $n;
            if ($n < 2) {
                continue;
            }
            $existem = 0;
            foreach ($varsP as $v) {
                $existem += isset($retrato['existentes'][ChaveDeComposicao::de([(int) $v['id'] => $n])]) ? 1 : 0;
            }
            $combos[] = [
                'quantidade' => $n,
                'sugerido'   => in_array($n, $sugeridos['combos'], true) || in_array($n, (array) ($p['qtd_combo'] ?? []), true),
                'existe'     => $varsP === [] || $existem === 0 ? 'nenhuma' : ($existem === count($varsP) ? 'todas' : 'algumas'),
            ];
        }

        $ambientesP = array_values((array) ($p['ambientes'] ?? []));
        $parceiros = [];
        foreach ($doRetrato as $id => $x) {
            if ($id === (int) $produto->id || ($faltas[$id] ?? ['?']) !== []) {
                continue;
            }
            $varsX = self::comOferta($x['variacoes']);
            if ($varsX === []) {
                continue;
            }
            $comum = array_values(array_intersect($ambientesP, array_values((array) $x['ambientes'])));
            $casa = $varsP !== [] && VariacoesEmParalelo::casar($varsP, $varsX) !== [];
            $parceiros[] = [
                'produto_id' => $id,
                'nome'       => (string) $x['nome'],
                'codigo'     => null,
                'variacoes'  => array_map(fn ($v) => (int) $v['id'], $varsX),
                'ambiente'   => $comum[0] ?? null,
                // Só a quantidade que o seletor oferece (1× a 6×): "sugerido · 8×" não teria como ser escolhido.
                'sugerido'   => ($q = $sugeridos['parceiros'][$id] ?? null) !== null && $q >= 1 && $q <= self::MAX_QUANTIDADE_PARCEIRO ? $q : null,
                'casa'       => $casa,
                'motivo'     => $casa ? null : 'As cores não casam com as deste produto.',
            ];
        }
        usort($parceiros, fn ($a, $b) => [$a['sugerido'] === null, $a['ambiente'] === null, mb_strtolower($a['nome']), $a['produto_id']]
            <=> [$b['sugerido'] === null, $b['ambiente'] === null, mb_strtolower($b['nome']), $b['produto_id']]);

        // Código e foto: o código não está no retrato; a foto é a da primeira cor que tiver.
        $codigos = EstruturaProduto::query()->where('company_id', $empresa->id)->pluck('codigo', 'id');
        $todasAsCores = array_merge(array_map(fn ($v) => (int) $v['id'], $varsP), ...array_column($parceiros, 'variacoes'));
        // A miniatura pela rota da porta da equipe (`FotosDaTrilhaController`): a do Portal, aqui, sai `/portal/0/…` e
        // cai no domínio do cliente, sem a sessão da equipe. Relativa, como a do Portal: a página está no mesmo domínio.
        $fotos = $this->fotos->capas($empresa, $todasAsCores,
            fn (int $variacao, int $imagem) => route('trilha.fotos', ['company' => (int) $empresa->id, 'variacao' => $variacao, 'imagem' => $imagem], false));
        foreach ($parceiros as &$x) {
            $x['codigo'] = self::texto($codigos[$x['produto_id']] ?? null);
            $x['foto'] = collect($x['variacoes'])->map(fn ($id) => $fotos[$id] ?? null)->filter()->first();
            unset($x['variacoes']);
        }
        unset($x);

        $marca = TrilhaMapeamento::query()->with('autor:id,name')->where('produto_id', $produto->id)->first();
        $cores = array_map(fn ($v) => [
            'variacao_id' => (int) $v['id'],
            'valor'       => $v['valor'] ?? null,
            'sku'         => (string) $v['sku'],
            'foto'        => $fotos[(int) $v['id']] ?? null,
            'tem_oferta'  => ! empty($v['oferta_id']),
        ], $p['variacoes'] ?? []);

        return [
            'produto'         => [
                'id'        => (int) $produto->id,
                'nome'      => (string) $produto->nome,
                'codigo'    => self::texto($produto->codigo),
                'familia'   => $p['familia'] ?? null,
                'ambientes' => $ambientesP,
                'foto'      => collect($cores)->pluck('foto')->filter()->first(),
                'cores'     => $cores,
            ],
            'combos'          => $combos,
            'parceiros'       => $parceiros,
            'mapeado_em'      => $marca?->concluido_em?->copy()->setTimezone(TrilhaOnda::FUSO)->toIso8601String(),
            'mapeado_por'     => $marca?->autor?->name,
            'max_componentes' => ChaveDeComposicao::MAXIMO_COMPONENTES,
        ];
    }

    // ═══ Prévia (só calcula) ════════════════════════════════════════════════

    /**
     * O que vai ser criado com esta escolha — nada é gravado.
     *
     * @param  array{combos: list<int>, parceiros: list<array{produto_id: int, quantidade: int}>, juntar: bool}  $escolha
     * @return array{linhas: list<array>, novas: int, pode_concluir: bool, mensagem: ?string}
     */
    public function previa(Company $empresa, EstruturaProduto $produto, array $escolha): array
    {
        $this->conferirForaDaLista($produto, TrilhaDaEmpresa::faltasDosProdutos($empresa));
        $plano = $this->planejar($empresa, $produto, $escolha, $this->retrato->daEmpresa($empresa));
        unset($plano['criar']);

        return $plano;
    }

    // ═══ Concluir mapeamento ════════════════════════════════════════════════

    /**
     * Grava o mapeamento: as composições novas (pela regra do aceite do Portal), a varredura da espera e o Sincronizar
     * uma vez no fim, o descarte das sugestões que sobraram do produto (reversível no Portal), a marca de "mapeado" e
     * o rastro. Tudo numa transação, sob a trava da empresa: ou tudo, ou nada. O robô NÃO começa aqui: as ofertas
     * novas ficam "Sem onda" no Planejamento.
     *
     * @return array{criadas: list<array{chave: string, tipo: string, nome: string, skus: list<string>}>, descartadas: int, mensagem: string}
     */
    public function concluir(Company $empresa, EstruturaProduto $produto, array $escolha, User $quem): array
    {
        $ator = AtorDoPortal::daEquipe($quem);

        return DB::transaction(function () use ($empresa, $produto, $escolha, $quem, $ator) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();
            $this->conferirForaDaLista($produto, TrilhaDaEmpresa::faltasDosProdutos($empresa));
            // Um combo apagado no Portal deixa a linha da onda dele: o Combo N criado agora não pode herdá-la.
            OndasDaTrilha::limparCombosOrfaos($empresa, TrilhaDaEmpresa::itens($empresa));

            $plano = $this->planejar($empresa, $produto, $escolha, $this->retrato->daEmpresa($empresa));
            if (! $plano['pode_concluir']) {
                throw ValidationException::withMessages(['mapeamento' => $plano['mensagem'] ?? 'Uma das combinações não dá: confira a prévia.']);
            }

            $criadas = [];
            $skus = [];
            $componentes = [];
            foreach ($plano['criar'] as $c) {
                [$oferta] = $this->ofertas->criar($empresa, [
                    'sku'         => $c['sku'],
                    'nome'        => $c['nome'],
                    'fase'        => $c['fase'],
                    'componentes' => $c['componentes'],
                ], $ator, varrerEspera: false, prepararNoPublicador: false);
                $skus[] = (string) $oferta->sku;
                array_push($componentes, ...array_column($c['componentes'], 'id'));

                // O Combo N de cada cor é UM item da trilha (`c-{p}-{N}`); o resto, um por oferta.
                $chave = $c['item'] ?? 'o-'.$oferta->id;
                $criadas[$chave] ??= ['chave' => $chave, 'tipo' => EstruturaOferta::FASES[$oferta->fase] ?? $oferta->fase, 'nome' => (string) $oferta->nome, 'skus' => []];
                $criadas[$chave]['skus'][] = (string) $oferta->sku;
            }

            // Uma vez no fim, como o aceite das sugestões: a espera e o Sincronizar (o kit chega ao Publicador sem
            // IA — a trava da onda segura o robô até a data).
            if ($skus !== []) {
                $this->ofertas->varrerEspera($empresa, $skus);
                $this->ofertas->prepararNoPublicador($empresa, $componentes);
            }

            $descartadas = $this->descartarPendentes($empresa, (int) $produto->id, $ator);

            TrilhaMapeamento::query()->updateOrCreate(
                ['produto_id' => $produto->id],
                ['company_id' => $empresa->id, 'concluido_em' => now(), 'concluido_por' => $quem->id],
            );
            $n = count($criadas);
            RegistroEstrutura::registrar($ator, $empresa, $produto, 'trilha_mapeamento_concluido',
                "Mapeamento de {$produto->nome} concluído na trilha: {$n} oferta(s) criada(s)",
                ['via' => 'trilha', 'criadas' => array_keys($criadas), 'skus' => $skus, 'descartadas' => $descartadas]);

            return [
                'criadas'     => array_values($criadas),
                'descartadas' => $descartadas,
                // Sem afirmar ONDE elas estão: a oferta pode ter entrado numa onda junto do combo, e o "já existe" segue
                // onde estava — a lista das criadas, recarregada, mostra o status de cada uma.
                'mensagem'    => $n === 0
                    ? 'Mapeamento concluído: nenhuma oferta nova.'
                    : "{$n} ".($n === 1 ? 'oferta criada.' : 'ofertas criadas.'),
            ];
        });
    }

    // ═══ A régua única da prévia e da gravação ══════════════════════════════

    /**
     * @param  array{combos?: list<int>, parceiros?: list<array{produto_id: int, quantidade: int}>, juntar?: bool}  $escolha
     * @return array{linhas: list<array>, novas: int, pode_concluir: bool, mensagem: ?string, criar: list<array>}
     */
    private function planejar(Company $empresa, EstruturaProduto $produto, array $escolha, array $retrato): array
    {
        $doRetrato = self::produtosDoRetrato($retrato);
        $p = $doRetrato[(int) $produto->id] ?? throw ValidationException::withMessages(['produto' => MontagemManualDeOferta::MENSAGEM_ITEM_INVALIDO]);
        $faltas = TrilhaDaEmpresa::faltasDosProdutos($empresa);
        $varsP = self::comOferta($p['variacoes']);
        $semOferta = array_values(array_filter($p['variacoes'], fn ($v) => empty($v['oferta_id'])));

        $linhas = [];
        $criar = [];

        // ─── A Simples: nasceu no cadastro, uma por cor ───
        $linhas[] = [
            'chave'      => "s-{$produto->id}",
            'tipo'       => 'Simples',
            'nome'       => (string) $produto->nome,
            'skus'       => array_map(fn ($v) => (string) $v['sku'], $varsP),
            'composicao' => null,
            'estado'     => $varsP === [] ? 'nao_da' : 'sempre',
            'detalhe'    => match (true) {
                $varsP === []     => 'Nenhuma cor deste produto tem oferta ainda: salve o produto no cadastro.',
                $semOferta !== [] => 'A cor '.implode(', ', array_map(fn ($v) => (string) ($v['valor'] ?? $v['sku']), $semOferta)).' ainda não tem oferta: salve o produto no cadastro.',
                default           => 'Já existe desde o cadastro, uma por cor.',
            },
            'avisos'     => [],
        ];

        // ─── Combos ───
        foreach (self::quantidades($escolha['combos'] ?? []) as $n) {
            $novas = [];
            $existentes = [];
            $avisos = [];
            $bloqueio = null;
            foreach ($varsP as $v) {
                $a = $this->montagem->analisar($empresa, [['variacao_id' => (int) $v['id'], 'quantidade' => $n]], null, null, $retrato);
                if ($a['ja_existe'] !== null) {
                    $existentes[] = $a['ja_existe']['sku'];

                    continue;
                }
                [$aviso, $bloqueio] = self::avisos($a, $bloqueio);
                array_push($avisos, ...$aviso);
                $novas[] = $a;
            }
            $linhas[] = [
                'chave'      => "c-{$produto->id}-{$n}",
                'tipo'       => 'Combo',
                'nome'       => (string) ($novas[0]['nome'] ?? "Combo {$n} · {$produto->nome}"),
                'skus'       => $novas !== [] ? array_map(fn ($a) => (string) $a['sku'], $novas) : $existentes,
                'composicao' => $varsP !== [] ? "{$n}× ".$varsP[0]['sku'] : null,
                'estado'     => match (true) {
                    $varsP === []      => 'nao_da',
                    $bloqueio !== null => 'nao_da',
                    $novas === []      => 'ja_existe',
                    $existentes !== [] => 'nova_parcial',
                    default            => 'nova',
                },
                'detalhe'    => match (true) {
                    $varsP === []      => 'Sem oferta simples para combinar.',
                    $bloqueio !== null => $bloqueio,
                    $novas === []      => 'Já existe: '.implode(', ', $existentes).'.',
                    $existentes !== [] => 'Nova em '.count($novas).' de '.count($varsP).' cores (as outras já existem).',
                    count($varsP) > 1  => 'Uma por cor ('.count($varsP).'); no Publicador as cores viram um anúncio só.',
                    default            => null,
                },
                'avisos'     => array_values(array_unique($avisos)),
            ];
            foreach ($novas as $a) {
                $criar[] = ['item' => "c-{$produto->id}-{$n}", 'sku' => $a['sku'], 'nome' => $a['nome'], 'fase' => $a['fase'],
                    'componentes' => array_map(fn ($i) => ['id' => (int) $i['oferta_id'], 'quantidade' => (int) $i['quantidade']], $a['itens'])];
            }
        }

        // ─── Kits e combits ───
        $parceiros = self::parceiros($escolha['parceiros'] ?? [], (int) $produto->id);
        foreach ($parceiros as $x) {
            if (! isset($doRetrato[$x['produto_id']]) || ($faltas[$x['produto_id']] ?? ['?']) !== []) {
                throw ValidationException::withMessages(['parceiros' => MontagemManualDeOferta::MENSAGEM_ITEM_INVALIDO]);
            }
        }

        $juntar = (bool) ($escolha['juntar'] ?? false) && count($parceiros) >= 2;
        $grupos = $juntar ? [$parceiros] : array_map(fn ($x) => [$x], $parceiros);
        $novo = 0;
        foreach ($grupos as $grupo) {
            $listas = [$varsP];
            $quantidades = [1];
            $nomes = [];
            foreach ($grupo as $x) {
                $listas[] = self::comOferta($doRetrato[$x['produto_id']]['variacoes']);
                $quantidades[] = $x['quantidade'];
                $nomes[] = (string) $doRetrato[$x['produto_id']]['nome'];
            }
            if (count($listas) > ChaveDeComposicao::MAXIMO_COMPONENTES) {
                throw ValidationException::withMessages(['parceiros' => 'Um kit pode juntar até '.ChaveDeComposicao::MAXIMO_COMPONENTES.' produtos.']);
            }

            $combinacoes = count($listas) === 2
                ? array_map(fn ($par) => [$par[0], $par[1]], VariacoesEmParalelo::casar($listas[0], $listas[1]))
                : VariacoesEmParalelo::casarComAncora($listas);
            if ($combinacoes === []) {
                $linhas[] = ['chave' => 'novo-'.(++$novo), 'tipo' => 'Kit', 'nome' => (string) $produto->nome.' + '.implode(' + ', $nomes),
                    'skus' => [], 'composicao' => null, 'estado' => 'nao_da',
                    'detalhe' => $juntar ? 'As cores não casam entre todos os produtos marcados.' : "As cores de {$nomes[0]} não casam com as deste produto.",
                    'avisos' => []];

                continue;
            }

            foreach ($combinacoes as $combinacao) {
                $componentes = [];
                foreach ($combinacao as $pos => $v) {
                    $componentes[] = ['variacao_id' => (int) $v['id'], 'quantidade' => $quantidades[$pos]];
                }
                $a = $this->montagem->analisar($empresa, $componentes, null, null, $retrato);
                [$avisos, $bloqueio] = self::avisos($a, null);
                $existe = $a['ja_existe'];
                $linhas[] = [
                    'chave'      => $existe !== null ? self::chaveDaExistente($empresa, $existe['sku']) : 'novo-'.(++$novo),
                    'tipo'       => EstruturaOferta::FASES[$a['fase']] ?? 'Kit',
                    'nome'       => (string) $a['nome'],
                    'skus'       => [(string) ($existe['sku'] ?? $a['sku'])],
                    'composicao' => implode(' + ', array_map(fn ($i) => "{$i['quantidade']}× {$i['sku']}", $a['itens'])),
                    'estado'     => $bloqueio !== null ? 'nao_da' : ($existe !== null ? 'ja_existe' : 'nova'),
                    'detalhe'    => $bloqueio ?? ($existe !== null ? "Já existe: {$existe['sku']}." : null),
                    'avisos'     => $avisos,
                ];
                if ($existe === null && $bloqueio === null) {
                    $criar[] = ['item' => null, 'sku' => $a['sku'], 'nome' => $a['nome'], 'fase' => $a['fase'],
                        'componentes' => array_map(fn ($i) => ['id' => (int) $i['oferta_id'], 'quantidade' => (int) $i['quantidade']], $a['itens'])];
                }
            }
        }

        $naoDa = array_values(array_filter($linhas, fn ($l) => $l['estado'] === 'nao_da'));

        return [
            'linhas'        => $linhas,
            'novas'         => count(array_filter($linhas, fn ($l) => in_array($l['estado'], ['nova', 'nova_parcial'], true))),
            'pode_concluir' => $naoDa === [],
            'mensagem'      => $naoDa !== [] ? (string) $naoDa[0]['detalhe'] : null,
            'criar'         => $criar,
        ];
    }

    // ═══ Apoio ══════════════════════════════════════════════════════════════

    /** Produto da Lista (com falta no cadastro) não se mapeia: 422. */
    private function conferirForaDaLista(EstruturaProduto $produto, array $faltas): void
    {
        if (($faltas[(int) $produto->id] ?? ['?']) !== []) {
            throw ValidationException::withMessages(['produto' => 'Este produto ainda tem pendência na Lista: complete o cadastro antes de mapear.']);
        }
    }

    /**
     * Os avisos da composição e o que a bloqueia: título acima do limite só avisa; SKU acima do limite ou repetido
     * (de outra composição) bloqueia.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private static function avisos(array $a, ?string $bloqueio): array
    {
        $avisos = [];
        foreach ($a['avisos'] as $x) {
            if (($x['codigo'] ?? null) === 'titulo_longo') {
                $avisos[] = "Título com {$x['valor']} caracteres (máx. {$a['limites']['max_titulo']}): ajuste depois na Lista SKUs.";
            }
            if (($x['codigo'] ?? null) === 'sku_longo') {
                $bloqueio ??= "SKU com {$x['valor']} caracteres (máx. {$a['limites']['max_sku']}).";
            }
        }
        if ($a['sku_repetido'] && $a['ja_existe'] === null) {
            $bloqueio ??= "O SKU {$a['sku']} já existe noutra oferta.";
        }

        return [$avisos, $bloqueio];
    }

    /** O item da trilha da oferta que já existe com esse SKU (para a linha "já existe" apontar para ela). */
    private static function chaveDaExistente(Company $empresa, string $sku): string
    {
        $id = EstruturaOferta::query()->where('company_id', $empresa->id)->where('sku', $sku)->value('id');

        return $id !== null ? 'o-'.$id : 'existente-'.$sku;
    }

    /**
     * Descarta as sugestões PENDENTES que envolvem alguma cor do produto e não viraram oferta (decisão de 02/11/2026,
     * como recomendado: sem isso o Planejamento do Portal seguiria dizendo "esperando decisão" para um produto que a
     * trilha mostra mapeado). Reversível: ficam em "Descartadas" no Portal.
     */
    private function descartarPendentes(Company $empresa, int $produtoId, AtorDoPortal $ator): int
    {
        $chaves = [];
        foreach ($this->sugestoes->gerar($empresa)['sugestoes'] as $s) {
            if (! empty($s['descartada'])) {
                continue;
            }
            foreach ((array) ($s['itens'] ?? []) as $i) {
                if ((int) ($i['produto_id'] ?? 0) === $produtoId) {
                    $chaves[] = (string) $s['chave'];
                    break;
                }
            }
        }
        if ($chaves === []) {
            return 0;
        }

        return (int) app(DecisoesDasSugestoes::class)->descartar($empresa, $chaves, $ator)['descartadas'];
    }

    /**
     * O que o sistema sugere para o produto: as quantidades de combo e, por parceiro, a quantidade dele.
     *
     * @return array{combos: list<int>, parceiros: array<int, int>}
     */
    private static function sugeridos(array $sugestoes, int $produtoId): array
    {
        $combos = [];
        $parceiros = [];
        foreach ($sugestoes as $s) {
            if (! empty($s['descartada'])) {
                continue;
            }
            $itens = (array) ($s['itens'] ?? []);
            $doProduto = array_values(array_filter($itens, fn ($i) => (int) ($i['produto_id'] ?? 0) === $produtoId));
            if ($doProduto === []) {
                continue;
            }
            if (count($itens) === 1) {
                $combos[] = (int) $doProduto[0]['quantidade'];

                continue;
            }
            foreach ($itens as $i) {
                $outro = (int) ($i['produto_id'] ?? 0);
                if ($outro > 0 && $outro !== $produtoId) {
                    $parceiros[$outro] ??= (int) $i['quantidade'];
                }
            }
        }

        return ['combos' => array_values(array_unique($combos)), 'parceiros' => $parceiros];
    }

    /** @return array<int, array> produto → a entrada dele no retrato do catálogo */
    private static function produtosDoRetrato(array $retrato): array
    {
        $saida = [];
        foreach ($retrato['produtos'] as $p) {
            $saida[(int) $p['id']] = $p;
        }

        return $saida;
    }

    /** As cores que já têm oferta simples (só elas combinam), na ordem do Portal. */
    private static function comOferta(array $variacoes): array
    {
        $saida = array_values(array_filter($variacoes, fn ($v) => ! empty($v['oferta_id'])));
        usort($saida, fn ($a, $b) => [(int) ($a['ordem'] ?? 0), (int) $a['id']] <=> [(int) ($b['ordem'] ?? 0), (int) $b['id']]);

        return $saida;
    }

    /** @return list<int> as quantidades de combo pedidas, válidas e sem repetição */
    private static function quantidades(array $pedidas): array
    {
        $saida = [];
        foreach ($pedidas as $n) {
            $n = (int) $n;
            if ($n < 2 || $n > 999) {
                throw ValidationException::withMessages(['combos' => 'Um combo tem de 2 a 999 unidades.']);
            }
            $saida[$n] = true;
        }
        $saida = array_keys($saida);
        sort($saida);

        return $saida;
    }

    /** @return list<array{produto_id: int, quantidade: int}> os parceiros pedidos, sem o próprio produto nem repetição */
    private static function parceiros(array $pedidos, int $produtoId): array
    {
        $saida = [];
        foreach ($pedidos as $x) {
            $id = (int) ($x['produto_id'] ?? 0);
            $q = (int) ($x['quantidade'] ?? 1);
            if ($id <= 0 || $id === $produtoId) {
                throw ValidationException::withMessages(['parceiros' => MontagemManualDeOferta::MENSAGEM_ITEM_INVALIDO]);
            }
            if ($q < 1 || $q > self::MAX_QUANTIDADE_PARCEIRO) {
                throw ValidationException::withMessages(['parceiros' => 'A quantidade de cada produto vai de 1 a '.self::MAX_QUANTIDADE_PARCEIRO.'.']);
            }
            if (isset($saida[$id])) {
                throw ValidationException::withMessages(['parceiros' => 'O mesmo produto aparece duas vezes.']);
            }
            $saida[$id] = ['produto_id' => $id, 'quantidade' => $q];
        }

        return array_values($saida);
    }

    private static function texto(mixed $valor): ?string
    {
        $t = trim((string) $valor);

        return $t !== '' ? $t : null;
    }
}
