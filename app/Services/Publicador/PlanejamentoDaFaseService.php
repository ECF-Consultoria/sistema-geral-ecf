<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaTipoProduto;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Geracao\NomesSugeridos;
use App\Services\Portal\Estrutura\Geracao\TipoDoProduto;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Portal\CoresDoGrupo;
use App\Support\Publicador\Portal\VariantesPorCor;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Planejamento do Portal × Fase N do Publicador (decisões do usuário de 09/10/2026).
 *
 * O Planejamento (`estrutura_ofertas` + `estrutura_oferta_componentes`) é a fonte de QUAIS composições
 * existem — SKU, preço na Precificação, logística. O Publicador publica o combo (o mesmo produto × N) no
 * formato da Fase N: UM anúncio com todas as cores como variação (`pub_produtos.produto_base_id` +
 * `quantidade_kit`). Então cada oferta Combo de UMA cor do Portal é a VARIANTE daquela cor no kit da
 * família, e não um produto avulso.
 *
 * O vínculo oferta Combo ↔ variante do kit é DERIVADO, nunca gravado (sem tabela nova):
 * oferta Combo → componente (a oferta Simples da cor) → variação → produto do Portal → base agrupado
 * (`pub_produtos.estrutura_produto_id`) → kit com `quantidade_kit` = N → variante cujo valor de eixo é
 * a cor (`VariantesPorCor`, mesma régua do Sincronizar). Só contam as cores que entram no grupo
 * (`CoresDoGrupo`): a variação que vira produto separado nunca é cor do kit.
 *
 * Kit e Combit (produtos DIFERENTES juntos) ficam como estão: um `pub_produto` por oferta composta.
 *
 * Tudo aqui é escopado pela Company do produto (T-172-08): oferta, variação e componente de outra
 * empresa nunca entram.
 *
 * ⚠️ `estrutura_ofertas.fase` é o TIPO da oferta (`simples|combo|kit|combit`); `pub_produtos.fase` é o
 * NÚMERO da fase do Publicador. Toda consulta daqui que junta tabelas qualifica a coluna.
 */
class PlanejamentoDaFaseService
{
    /** Os tipos de oferta do Planejamento que juntam unidades (o que não é Fase 1 de ninguém). */
    public const TIPOS_COMPOSTOS = [EstruturaOferta::FASE_COMBO, EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT];

    /** `estrutura_ofertas.sku` e o SELLER_SKU das variantes são `string(120)`. */
    private const MAX_SKU = 120;

    public function __construct(private RascunhoRepository $repo) {}

    // ═══ Classificação (item C) ══════════════════════════════════════════════

    /**
     * O tipo da oferta do Planejamento (`combo|kit|combit`) de um produto que NÃO é kit da família;
     * null para o resto. O kit (`produto_base_id`) já é uma fase — mesmo o combo antigo vinculado — e
     * produto sem oferta não tem tipo. Lê `estrutura_ofertas.fase` pela relação (consulta só daquela
     * tabela; carregue `oferta` antes numa lista para não pagar uma por produto).
     */
    public static function tipoComposto(PubProduto $p): ?string
    {
        if ($p->produto_base_id !== null || $p->oferta_id === null) {
            return null;
        }
        $tipo = $p->oferta?->fase;

        return in_array($tipo, self::TIPOS_COMPOSTOS, true) ? $tipo : null;
    }

    /** "Combo do Planejamento", "Kit do Planejamento", "Combit do Planejamento". */
    public static function rotuloDoComposto(string $tipo): string
    {
        return (EstruturaOferta::FASES[$tipo] ?? 'Composto').' do Planejamento';
    }

    /** A mensagem da regra KIT-06: composto do Planejamento não é base de fase nenhuma. */
    public static function motivoKit06(string $tipo): string
    {
        $nome = EstruturaOferta::FASES[$tipo] ?? 'composto';

        return "Este produto é um {$nome} do Planejamento do Portal, não a Fase 1 de um produto: não dá para criar fases a partir dele. "
            .'Para vender mais unidades, crie a fase no produto de 1 unidade.';
    }

    // ═══ Quantidade sugerida do "Criar Fase N" (item D) ══════════════════════

    /**
     * A menor quantidade dos Combos do Planejamento que a família ainda não tem; sem nenhuma, a regra de
     * sempre (`PubProduto::proximaQuantidade`). Pura.
     *
     * @param  list<int>  $doPlanejamento  as quantidades dos Combos do Portal deste produto
     * @param  list<int>  $daFamilia  `familia()->pluck('quantidade_kit')`
     */
    public static function quantidadeSugerida(array $doPlanejamento, array $daFamilia): int
    {
        $tomadas = array_map('intval', $daFamilia);
        $livres = array_values(array_filter(array_map('intval', $doPlanejamento), fn (int $n) => $n >= 2 && ! in_array($n, $tomadas, true)));
        sort($livres);

        return $livres[0] ?? PubProduto::proximaQuantidade($tomadas);
    }

    /**
     * As quantidades dos Combos do Planejamento para o produto do Portal deste base (qualquer cor),
     * em ordem. Base que não é agrupado não tem Planejamento: lista vazia.
     *
     * @return list<int>
     */
    public function quantidadesDoPlanejamento(PubProduto $base): array
    {
        $produto = $this->produtoAgrupado($base);
        if ($produto === null) {
            return [];
        }

        return array_keys($this->combosPorQuantidade((int) $base->company_id, $this->coresDoProduto((int) $base->company_id, (int) $produto->id)));
    }

    // ═══ Leitura do Portal ═══════════════════════════════════════════════════

    /**
     * O produto do Portal de um base agrupado — só da MESMA Company do base. Kit não é base.
     */
    public function produtoAgrupado(?PubProduto $base): ?EstruturaProduto
    {
        if ($base === null || $base->produto_base_id !== null || $base->estrutura_produto_id === null || $base->company_id === null) {
            return null;
        }

        return EstruturaProduto::query()->where('company_id', $base->company_id)->find($base->estrutura_produto_id);
    }

    /**
     * As cores do produto do Portal que entram no grupo (a regra do Sincronizar, `CoresDoGrupo`), na
     * ordem do Portal, cada uma com a sua oferta Simples.
     *
     * @return array<int, array{valor: string, oferta_id: int, sku: string, codigo: ?string}> variacao_id → a cor
     */
    public function coresDoProduto(int $companyId, int $produtoId): array
    {
        $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $companyId)->where('produto_id', $produtoId)
            ->orderBy('ordem')->orderBy('id')->get(['id', 'eixo', 'valor', 'codigo', 'ordem']);
        if ($variacoes->isEmpty()) {
            return [];
        }

        $ofertas = EstruturaOferta::query()->where('company_id', $companyId)->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('variacao_id', $variacoes->pluck('id'))->orderBy('id')->get(['id', 'variacao_id', 'sku'])
            ->unique('variacao_id')->keyBy('variacao_id');

        $comOferta = $variacoes->filter(fn (EstruturaProdutoVariacao $v) => $ofertas->has($v->id))->values();
        $separacao = CoresDoGrupo::separar($comOferta->map(fn (EstruturaProdutoVariacao $v) => [
            'id' => (int) $v->id, 'eixo' => $v->eixo, 'valor' => $v->valor, 'codigo' => $v->codigo,
        ])->all());
        $entram = array_flip($separacao['agrupaveis']);

        $cores = [];
        foreach ($comOferta as $v) {
            if (! isset($entram[(int) $v->id])) {
                continue;
            }
            $oferta = $ofertas->get($v->id);
            $cores[(int) $v->id] = [
                'valor' => trim((string) $v->valor),
                'oferta_id' => (int) $oferta->id,
                'sku' => (string) $oferta->sku,
                'codigo' => $v->codigo,
            ];
        }

        return $cores;
    }

    /**
     * As ofertas Combo do Portal de UMA cor do grupo, por quantidade: Combo com exatamente um componente
     * (a oferta Simples da cor) de quantidade 2 ou mais, da MESMA Company. Duas ofertas para a mesma cor
     * e a mesma quantidade (montadas à mão): vale a mais antiga.
     *
     * @param  array<int, array{oferta_id: int}>  $cores  saída de {@see coresDoProduto()}
     * @return array<int, array<int, array{oferta_id: int, sku: string, nome: string, componente_id: int}>> N → variacao_id → a oferta
     */
    public function combosPorQuantidade(int $companyId, array $cores): array
    {
        $corDaOferta = [];
        foreach ($cores as $variacaoId => $cor) {
            $corDaOferta[(int) $cor['oferta_id']] = (int) $variacaoId;
        }
        if ($corDaOferta === []) {
            return [];
        }

        $linhas = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->where('o.company_id', $companyId)
            ->where('o.fase', EstruturaOferta::FASE_COMBO)
            ->whereIn('c.componente_id', array_keys($corDaOferta))
            ->where('c.quantidade', '>=', 2)
            // Combo é UM componente; composição montada à mão com dois não é o combo de uma cor.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('estrutura_oferta_componentes as c2')
                ->whereColumn('c2.oferta_id', 'c.oferta_id')->whereColumn('c2.id', '<>', 'c.id'))
            ->orderBy('o.id')
            ->get(['c.oferta_id', 'c.componente_id', 'c.quantidade', 'o.sku', 'o.nome']);

        $saida = [];
        foreach ($linhas as $l) {
            $variacaoId = $corDaOferta[(int) $l->componente_id];
            $saida[(int) $l->quantidade][$variacaoId] ??= [
                'oferta_id' => (int) $l->oferta_id,
                'sku' => (string) $l->sku,
                'nome' => (string) ($l->nome ?? ''),
                'componente_id' => (int) $l->componente_id,
            ];
        }
        ksort($saida);

        return $saida;
    }

    // ═══ Casamento com o rascunho ════════════════════════════════════════════

    /**
     * Chave da variante → variacao_id da cor, pela régua de `VariantesPorCor`.
     *
     * @param  array<int, array{valor: string}>  $cores
     * @return array<string, int>
     */
    public function casar(RascunhoSnapshot $s, array $cores): array
    {
        $variantes = array_map(fn (Variante $v) => [
            'chave' => $v->chave,
            'nomes' => array_values(array_map(fn (ValorEixo $x) => $x->valueName, $v->valores)),
            'orfa' => $v->orfa,
        ], $s->variantes);

        return VariantesPorCor::casar($variantes, array_map(fn (array $c) => $c['valor'], $cores));
    }

    // ═══ O kit que já existe (itens A e B) ═══════════════════════════════════

    /**
     * As ofertas Combo do Portal que são as variantes deste kit: chave da variante → a oferta da cor
     * (com `quantidade` = `quantidade_kit` do kit) e o SKU que a variante tem hoje.
     *
     * Vazio quando o produto não é kit, o base não é agrupado, o kit não tem rascunho ou nenhuma cor do
     * kit tem Combo N no Portal. `$snap` deixa quem já leu o rascunho sob a trava reaproveitar a leitura.
     *
     * @return array<string, array{variacao_id: int, cor: string, oferta_id: int, sku: string, nome: string, componente_id: int, sku_da_variante: ?string}>
     */
    public function combosDoKit(PubProduto $kit, ?RascunhoSnapshot $snap = null): array
    {
        if (! $kit->ehKit()) {
            return [];
        }
        $base = $kit->base;
        if ($base === null || (int) $base->company_id !== (int) $kit->company_id) {
            return [];
        }
        $produto = $this->produtoAgrupado($base);
        if ($produto === null) {
            return [];
        }

        $companyId = (int) $kit->company_id;
        $cores = $this->coresDoProduto($companyId, (int) $produto->id);
        $combos = $this->combosPorQuantidade($companyId, $cores)[(int) $kit->quantidade_kit] ?? [];
        if ($combos === []) {
            return [];
        }

        if ($snap === null) {
            $r = PubRascunho::where('produto_id', $kit->id)->first();
            if ($r === null) {
                return [];
            }
            $snap = $this->repo->snapshot($r);
        }

        $skuDoProduto = $snap->atributos['SELLER_SKU']['value_name'] ?? null;
        $porChave = $this->casar($snap, $cores);
        $saida = [];
        foreach ($snap->variantes as $v) {
            $variacaoId = $porChave[$v->chave] ?? null;
            $combo = $variacaoId !== null ? ($combos[$variacaoId] ?? null) : null;
            if ($combo === null) {
                continue;
            }
            $sku = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? $skuDoProduto ?? ''));
            $saida[$v->chave] = ['variacao_id' => $variacaoId, 'cor' => $cores[$variacaoId]['valor'], ...$combo, 'sku_da_variante' => $sku === '' ? null : $sku];
        }

        return $saida;
    }

    // ═══ "Criar Fase N" (item D) ═════════════════════════════════════════════

    /**
     * O que o Planejamento diz sobre a Fase N deste base: para cada variante do rascunho do base que é
     * uma cor do Portal, a oferta Combo N daquela cor — a que já existe (SKU e preço da Precificação) ou
     * a que vai nascer no Portal ao confirmar (SKU e nome no padrão do Planejamento, `NomesSugeridos`).
     *
     * Null quando o base não é agrupado, não tem rascunho ou nenhuma variante dele é cor do Portal: aí o
     * "Criar Fase N" segue a regra de antes (decisão 5 do outro dev — SKU `-KIT{N}` só no Publicador).
     * Nada é gravado aqui.
     *
     * `$comPrecos = false` pula a Precificação (os preços saem nulos): é o que `garantirOfertas` usa,
     * dentro da transação do kit, para não segurar as travas do rascunho e da Company montando o
     * conjunto da empresa inteira por um número que ninguém grava.
     *
     * @return ?array{produto_id: int, quantidade: int,
     *     por_variante: array<string, array{variacao_id: int, cor: string, oferta_id: ?int, sku: string, nome: string, componente_id: int, nova: bool, precos: array<string, ?float>}>,
     *     sem_cor: list<string>}
     */
    public function daFase(PubProduto $base, int $n, bool $comPrecos = true): ?array
    {
        $produto = $this->produtoAgrupado($base);
        $r = $produto !== null ? $base->rascunho : null;
        if ($produto === null || $r === null || $n < 2) {
            return null;
        }

        $companyId = (int) $base->company_id;
        $cores = $this->coresDoProduto($companyId, (int) $produto->id);
        if ($cores === []) {
            return null;
        }
        $combos = $this->combosPorQuantidade($companyId, $cores)[$n] ?? [];

        $snap = $this->repo->snapshot($r);
        $porChave = $this->casar($snap, $cores);
        if ($porChave === []) {
            return null;
        }

        $tipo = null;
        $tipoLido = false;
        $porVariante = [];
        $semCor = [];
        foreach ($snap->variantes as $v) {
            if ($v->orfa) {
                continue;
            }
            $variacaoId = $porChave[$v->chave] ?? null;
            if ($variacaoId === null) {
                $semCor[] = $v->chave;

                continue;
            }
            $cor = $cores[$variacaoId];
            $combo = $combos[$variacaoId] ?? null;
            if ($combo !== null) {
                $porVariante[$v->chave] = ['variacao_id' => $variacaoId, 'cor' => $cor['valor'], ...$combo, 'nova' => false];

                continue;
            }
            if (! $tipoLido) {
                $tipo = $this->tipoParaNome($produto);
                $tipoLido = true;
            }
            $nomeado = NomesSugeridos::combo((string) $produto->nome, $cor['sku'], $cor['valor'] !== '' ? $cor['valor'] : null, $n, $tipo);
            $porVariante[$v->chave] = [
                'variacao_id' => $variacaoId, 'cor' => $cor['valor'], 'oferta_id' => null,
                'sku' => self::skuNoTeto($nomeado['sku'], "-CB{$n}"), 'nome' => mb_substr($nomeado['nome'], 0, 255),
                'componente_id' => $cor['oferta_id'], 'nova' => true,
            ];
        }

        $precos = $comPrecos ? $this->precosDasOfertas($base, array_values(array_filter(array_column($porVariante, 'oferta_id')))) : [];
        foreach ($porVariante as $chave => $item) {
            $porVariante[$chave]['precos'] = $precos[$item['oferta_id'] ?? 0] ?? DadosEfetivosService::precosAnunciados(null);
        }

        return ['produto_id' => (int) $produto->id, 'quantidade' => $n, 'por_variante' => $porVariante, 'sem_cor' => $semCor];
    }

    /**
     * Garante no Portal as ofertas Combo N de cada cor do base e devolve o SKU de cada variante que é
     * cor do Portal — o que a Fase N grava como SELLER_SKU (o Planejamento é a fonte do SKU).
     *
     * Roda DENTRO da transação do `CriarFaseService` (a mesma transação lógica do kit): se o kit não
     * nascer, as ofertas também não. Idempotente: relê os Combos sob a trava da Company (a mesma do
     * "Aceitar" do Planejamento, `DecisoesDasSugestoes`) e só cria a cor que ainda não tem — a que o
     * cliente aceitou entre a prévia e o Confirmar é usada como está. Cria pelo caminho da Lista SKUs
     * (`EstruturaOfertaService::criar`, regra de composição e registro no histórico do Portal, como
     * equipe), e varre a espera de anúncios uma vez no fim, com os SKUs novos.
     *
     * Sem `$user` (chamada direta, sem pessoa) nada é criado: só as cores que já têm Combo N recebem o SKU.
     *
     * @return array<string, string> chave da variante do base → SKU da oferta Combo N daquela cor
     *
     * @throws RegraViolada KIT-07 quando o Portal recusa criar uma oferta
     */
    public function garantirOfertas(PubProduto $base, int $n, ?User $user): array
    {
        $plano = $this->daFase($base, $n, comPrecos: false);
        if ($plano === null) {
            return [];
        }

        $porVariante = $plano['por_variante'];
        $novas = array_filter($porVariante, fn (array $i) => $i['nova']);
        if ($novas !== [] && $user === null) {
            Log::warning("[Publicador] Criar Fase {$n} do produto {$base->id} sem pessoa: as ofertas Combo {$n} que faltam no Portal não foram criadas.");
        } elseif ($novas !== []) {
            $empresa = Company::query()->whereKey($base->company_id)->lockForUpdate()->first();
            if ($empresa === null) {
                return [];
            }
            $agora = $this->combosPorQuantidade((int) $base->company_id, $this->coresDoProduto((int) $base->company_id, (int) $plano['produto_id']))[$n] ?? [];
            $ator = AtorDoPortal::daEquipe($user);
            $ofertas = app(EstruturaOfertaService::class);
            $criados = [];

            foreach ($novas as $chave => $item) {
                $existente = $agora[$item['variacao_id']] ?? null;
                if ($existente !== null) {
                    $porVariante[$chave] = [...$item, ...$existente, 'nova' => false];

                    continue;
                }
                try {
                    [$oferta] = $ofertas->criar($empresa, [
                        'sku' => $item['sku'],
                        'fase' => EstruturaOferta::FASE_COMBO,
                        'nome' => $item['nome'],
                        'componentes' => [['id' => $item['componente_id'], 'quantidade' => $n]],
                    ], $ator, varrerEspera: false);
                } catch (ValidationException $e) {
                    $motivo = (string) collect($e->errors())->flatten()->first();
                    throw new RegraViolada('KIT-07', "O Portal não aceitou criar a oferta Combo {$n} da cor \"{$item['cor']}\" ({$item['sku']}): {$motivo}");
                }
                $porVariante[$chave] = [...$item, 'oferta_id' => (int) $oferta->id, 'sku' => (string) $oferta->sku, 'nova' => false];
                $criados[] = (string) $oferta->sku;
            }

            if ($criados !== []) {
                $ofertas->varrerEspera($empresa, $criados);
                Log::info("[Publicador] Criar Fase {$n} do produto {$base->id}: ".count($criados)." oferta(s) Combo {$n} criada(s) no Portal da empresa {$empresa->id} ("
                    .implode(', ', $criados).').');
            }
        }

        $skus = [];
        foreach ($porVariante as $chave => $item) {
            if ($item['oferta_id'] !== null) {
                $skus[$chave] = $item['sku'];
            }
        }

        return $skus;
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Preço anunciado de cada oferta pela Precificação do Portal, numa chamada.
     *
     * @param  list<int>  $ofertaIds
     * @return array<int, array<string, ?float>> oferta_id → listing_type_id → preço
     */
    private function precosDasOfertas(PubProduto $base, array $ofertaIds): array
    {
        $empresa = $ofertaIds === [] ? null : Company::find($base->company_id);
        if ($empresa === null) {
            return [];
        }
        $porOferta = app(EstruturaPrecificacaoService::class)->pagina($empresa, $ofertaIds)['por_oferta'] ?? [];

        $saida = [];
        foreach ($ofertaIds as $id) {
            $saida[$id] = DadosEfetivosService::precosAnunciados($porOferta[$id] ?? null);
        }

        return $saida;
    }

    /**
     * O tipo do produto (nome e plural) que o Planejamento usaria no nome do Combo: a escolha guardada
     * vence a inferência pela categoria e pelo nome — a mesma régua do `RetratoDoCatalogo`.
     *
     * @return ?array{nome: string, plural: string}
     */
    private function tipoParaNome(EstruturaProduto $produto): ?array
    {
        $tipos = [];
        $paraInferir = [];
        $slugPorId = [];
        foreach (EstruturaTipoProduto::query()->orderBy('ordem')->orderBy('id')->get() as $t) {
            $slugPorId[$t->id] = $t->slug;
            $tipos[$t->slug] = ['nome' => (string) $t->nome, 'plural' => (string) $t->plural];
            $paraInferir[$t->slug] = ['palavras' => TipoDoProduto::palavras((string) $t->palavras), 'ordem' => (int) $t->ordem];
        }

        $ajuste = EstruturaProdutoGeracao::query()->where('company_id', $produto->company_id)->where('produto_id', $produto->id)->first();
        $escolhido = $ajuste && $ajuste->tipo_id !== null ? ($slugPorId[$ajuste->tipo_id] ?? null) : null;
        $slug = TipoDoProduto::efetivo($escolhido, TipoDoProduto::inferir($produto->categoria_ml_nome, $produto->nome, $paraInferir), $tipos)['slug'];

        return $slug !== null && isset($tipos[$slug]) ? $tipos[$slug] : null;
    }

    /** SKU no teto da coluna, cortado pelo COMEÇO: o sufixo `-CB{N}` é o que identifica o combo. */
    private static function skuNoTeto(string $sku, string $sufixo): string
    {
        if (mb_strlen($sku) <= self::MAX_SKU) {
            return $sku;
        }
        $semSufixo = str_ends_with($sku, $sufixo) ? mb_substr($sku, 0, mb_strlen($sku) - mb_strlen($sufixo)) : $sku;

        return mb_substr($semSufixo, 0, max(1, self::MAX_SKU - mb_strlen($sufixo))).$sufixo;
    }
}
