<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\TrilhaOnda;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Trilha\Ondas\OndasDaEmpresa;
use App\Support\Trilha\TrilhaDeTrabalho;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Trilha de produtos de UMA empresa (1ª entrega, 30/10/2026): cada produto do catálogo do cliente e cada
 * oferta numa trilha de 6 etapas com dono — Lista de produtos (cliente) → Mapeamento estrutural → Planejamento
 * → Preparação automática (agente) → Revisão e publicação → Protocolo e Jardinagem.
 *
 * SÓ LEITURA, montada com o que já está no banco: nenhuma chamada ao Mercado Livre, nenhum job, nenhuma escrita.
 * Por isso nada aqui chama `EditorRascunhoService::estado()`, `VitrineDeProdutosService::montar()`,
 * `sincronizarAgora()` nem `FichaTecnicaDoProduto::planejarParcial()` — todos gravam ou vão ao ML.
 *
 * ### Número FIXO de consultas
 * Qualquer que seja o tamanho do catálogo: a geração das sugestões (a mesma da tela de Planejamento, que já é de
 * consultas fixas), os produtos com família, ambientes, variações e volumes, a ficha gravada, a contagem de fotos,
 * as definições guardadas das categorias, as ofertas, os componentes e o que o Publicador sabe delas
 * ({@see SituacaoDasOfertas}). Leituras de cache em lote (`Cache::many`). Com a trilha de trabalho ligada, mais 3:
 * ondas, itens das ondas e produtos mapeados ({@see OndasDaEmpresa}).
 *
 * ### Regras de etapa (o porquê de cada uma está nas classes)
 * - Produto ({@see CompletudeDoProduto}): falta algo no cadastro → `lista`. Depois:
 *   - trilha de trabalho DESLIGADA (1ª entrega): sugestão PENDENTE do Planejamento que envolve alguma cor dele →
 *     `mapa`; senão `null` (mapeado: segue pelas ofertas);
 *   - LIGADA (2ª entrega, 02/11/2026): mapeado é o produto com "Concluir mapeamento" na trilha
 *     (`trilha_mapeamentos`) ou cuja Simples já andou; o resto completo fica em `mapa` ("falta mapear").
 * - Oferta ({@see OfertasDaTrilha} agrupa, {@see SituacaoDasOfertas} decide): Kit, Combit, Combo e oferta sem
 *   produto aparecem SEMPRE (aceitar já foi uma decisão). A Simples de um produto ainda na lista ou no
 *   mapeamento só aparece se já "andou" (tem anúncio, o rascunho passou do DRAFT ou o agente já mexeu) — ou, com
 *   a trilha de trabalho, se já está numa onda: senão ela é parte do produto e não aparece.
 * - Com a trilha de trabalho ligada, a oferta que ainda não andou segue a régua da ONDA: sem onda → Planejamento;
 *   numa onda → Preparação automática, "Na fila · começa em dd/mm" até o dia.
 *
 * Classe não final de propósito: o teste de acesso das portas troca este serviço por um dublê.
 */
class TrilhaDaEmpresa
{
    /** Quantas sugestões pendentes cada produto leva para a tela (o total vem à parte). */
    public const MAX_SUGESTOES = 30;

    private const ORDEM_DO_PRODUTO = ['lista' => 0, 'mapa' => 1, '' => 2];

    private const ORDEM_DA_OFERTA = ['plano' => 0, 'robo' => 1, 'revisao' => 2, 'lanc' => 3];

    private const ORDEM_DO_TIPO = ['Simples' => 0, 'Combo' => 1, 'Kit' => 2, 'Combit' => 3];

    public function __construct(private SugestoesService $sugestoes) {}

    /**
     * A trilha da empresa, montada só com o que já está no banco (nenhuma chamada ao ML, nenhum job).
     * Número FIXO de consultas, qualquer que seja o tamanho do catálogo.
     *
     * @return array{produtos: list<array>, ofertas: list<array>, ondas: list<array>, trabalho: bool, gerado_em: string}
     */
    public function montar(Company $empresa): array
    {
        $empresaId = (int) $empresa->id;
        $ondas = TrilhaDeTrabalho::ligada($empresa) ? OndasDaEmpresa::ler($empresaId) : null;

        // Planejamento: a mesma geração da tela de sugestões (consultas fixas, nunca o ML).
        $pendentes = self::sugestoesPendentes($this->sugestoes->gerar($empresa)['sugestoes']);

        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresaId)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->orderBy('id')
            ->get();

        $salvos = EstruturaProdutoAtributo::query()
            ->where('company_id', $empresaId)
            ->get(['produto_id', 'atributo_id', 'valor', 'valor_id', 'unidade'])
            ->groupBy('produto_id');

        $fotos = self::fotosPorVariacao($empresaId);
        $definicoes = CompletudeDoProduto::definicoesGuardadas($produtos->pluck('categoria_ml_id'));
        // A mesma logística da tela de Produtos: limites da modalidade de envio já lida (só cache).
        $modalidade = ModalidadeDeEnvio::emCache($empresa);
        // 3ª entrega (pergunta 3): com a trilha de trabalho o "frete ME1" é aviso, não falta.
        $me1ComoAviso = $ondas !== null;

        [$ofertas, $componentes] = self::ofertasEComponentes($empresaId);

        $situacao = SituacaoDasOfertas::daEmpresa($empresa, array_map('intval', $produtos->modelKeys()));
        $grupos = OfertasDaTrilha::agrupar($produtos, $ofertas, $componentes);

        // ─── Produtos ───
        $saidaProdutos = [];
        $faltas = [];
        foreach ($produtos as $p) {
            $c = CompletudeDoProduto::avaliar($p, $salvos->get($p->id, collect()), $fotos, $definicoes, $modalidade, $me1ComoAviso);
            $faltas[(int) $p->id] = $c['faltas'];
            $sugestoes = $pendentes[(int) $p->id] ?? [];
            $codigo = trim((string) $p->codigo);
            $categoria = trim((string) $p->categoria_ml_nome);
            $ambientes = $p->ambientes->pluck('nome')->map(fn ($n) => (string) $n)->sort(fn ($a, $b) => self::chave($a) <=> self::chave($b))->values()->all();
            $marca = $ondas?->mapeamento((int) $p->id);

            $saidaProdutos[(int) $p->id] = [
                'id'                        => (int) $p->id,
                'nome'                      => (string) $p->nome,
                'codigo'                    => $codigo !== '' ? $codigo : null,
                'skus'                      => $p->variacoes->map(fn ($v) => (string) $v->codigo)->values()->all(),
                'familia'                   => $p->familia?->nome,
                'ambientes'                 => $ambientes,
                'categoria'                 => $categoria !== '' ? $categoria : null,
                'variacoes'                 => $p->variacoes->count(),
                'custo_min'                 => $c['custo_min'],
                'custo_max'                 => $c['custo_max'],
                'medidas'                   => $c['medidas'],
                'embalagem'                 => $c['embalagem'],
                'fotos_min'                 => $c['fotos_min'],
                'tem_descricao'             => $c['tem_descricao'],
                'ficha'                     => $c['ficha'],
                'faltas'                    => $c['faltas'],
                // O que não segura o produto na Lista, mas a tela avisa (com a trilha de trabalho: o "frete ME1").
                'avisos'                    => $c['avisos'],
                // Com a trilha de trabalho a etapa 2 é decidida depois das ofertas (a Simples andou?) — abaixo.
                'etapa'                     => $c['faltas'] !== [] ? 'lista' : ($ondas === null && $sugestoes !== [] ? 'mapa' : null),
                'sugestoes_pendentes'       => array_slice($sugestoes, 0, self::MAX_SUGESTOES),
                'sugestoes_pendentes_total' => count($sugestoes),
                'mapeado_em'                => $marca?->concluido_em?->copy()->setTimezone(TrilhaOnda::FUSO)->toIso8601String(),
                'mapeado_por'               => $marca?->autor?->name,
                'pub_produto_id'            => null,
            ];
        }

        // ─── Ofertas ───
        $decididos = self::decidirItens($situacao, $grupos, $ondas, $faltas, avulsasForaDasOndas: $ondas !== null);
        // 3ª entrega (pergunta 1): o produto que algum item já levou adiante fica só para leitura no cadastro da trilha —
        // a MESMA régua que as escritas do cadastro conferem ({@see travasDoCadastro()}). A porta do cliente não a recebe.
        $travas = self::travasDoCadastro(array_map(fn (string $chave) => [
            'chave'    => $chave,
            'nome'     => $decididos['itens'][$chave]['nome'],
            'andou'    => $decididos['andou'][$chave],
            'produtos' => self::produtosDoItem($decididos['itens'][$chave]),
        ], array_keys($decididos['itens'])));
        foreach ($saidaProdutos as $id => $p) {
            $saidaProdutos[$id]['cadastro_travado'] = isset($travas[$id]);
        }
        $saidaOfertas = [];
        foreach ($decididos['itens'] as $chave => $item) {
            $saidaOfertas[$chave] = self::item($item, $decididos['decisoes'][$chave], $ondas?->ondaDoItem($chave), $ondas);
        }
        // O link do produto para o Publicador é o da Simples dele (o grupo das cores; sem grupo, o legado).
        foreach ($decididos['pubs_da_simples'] as $produtoId => $pubs) {
            $saidaProdutos[(int) $produtoId]['pub_produto_id'] = $pubs['principal'];
        }

        // Trilha de trabalho: o produto completo sai do Mapeamento com o "Concluir mapeamento" da trilha ou quando a
        // Simples dele já andou (o que estava preparado, em revisão ou no ar antes das ondas não volta à etapa 2).
        if ($ondas !== null) {
            foreach ($saidaProdutos as $id => $p) {
                if ($p['etapa'] === null && $ondas->mapeamento($id) === null && ! ($decididos['andou']["s-{$id}"] ?? false)) {
                    $saidaProdutos[$id]['etapa'] = 'mapa';
                }
            }
        }

        // Quem aparece: a Simples de produto ainda na lista/no mapeamento só se já andou (ou, com a trilha de
        // trabalho, se já está numa onda — alguém a planejou — ou um combo dela está: o combo espera por ela, e ela
        // precisa estar à vista para alguém pô-la numa onda).
        $combosNaOnda = [];
        foreach ($grupos['combos'] as $combo) {
            if ($ondas?->ondaDoItem($combo['id']) !== null) {
                $combosNaOnda[(int) $combo['produto_id']] = true;
            }
        }
        foreach ($grupos['simples'] as $produtoId => $item) {
            if (($saidaProdutos[(int) $produtoId]['etapa'] ?? null) !== null && ! $decididos['andou'][$item['id']]
                && $ondas?->ondaDoItem($item['id']) === null && ! isset($combosNaOnda[(int) $produtoId])) {
                unset($saidaOfertas[$item['id']]);
            }
        }

        $listaProdutos = array_values($saidaProdutos);
        usort($listaProdutos, fn (array $a, array $b) => [self::ORDEM_DO_PRODUTO[$a['etapa'] ?? ''], self::chave($a['nome']), $a['id']]
            <=> [self::ORDEM_DO_PRODUTO[$b['etapa'] ?? ''], self::chave($b['nome']), $b['id']]);

        $listaOfertas = array_values($saidaOfertas);
        usort($listaOfertas, fn (array $a, array $b) => [self::ORDEM_DA_OFERTA[$a['etapa']] ?? 9, self::ORDEM_DO_TIPO[$a['tipo']] ?? 9, self::chave($a['nome']), $a['id']]
            <=> [self::ORDEM_DA_OFERTA[$b['etapa']] ?? 9, self::ORDEM_DO_TIPO[$b['tipo']] ?? 9, self::chave($b['nome']), $b['id']]);

        return [
            'produtos'  => $listaProdutos,
            'ofertas'   => $listaOfertas,
            'ondas'     => $ondas !== null ? self::ondas($ondas) : [],
            'trabalho'  => $ondas !== null,
            'gerado_em' => now()->setTimezone('America/Sao_Paulo')->toIso8601String(),
        ];
    }

    /**
     * Os itens de oferta da empresa pela chave — a MESMA régua de {@see montar()} (agrupamento e "andou"), sem a
     * lista, o Planejamento nem as ondas. É o que a trava do robô, a soltura das ondas e o serviço das ondas leem.
     * Consultas fixas: produtos com as cores, ofertas, componentes e o que o Publicador sabe delas.
     *
     * `$comoNaTela` (4ª entrega, 4a — a prévia da revisão, 03/11/2026): a etapa e o status COMO A TELA os mostra. Com a
     * trilha de trabalho, a tela decide o item que não andou pela régua da ONDA (Planejamento/na fila) e escreve
     * "pendência na Lista" pelas faltas do cadastro; sem o parâmetro, a decisão é a de sempre (a que a trava e a soltura
     * leem — elas não querem a onda). Custa mais as 3 consultas das ondas e as fixas das faltas ({@see faltasDosProdutos()}).
     * O `pub_produto_id` (o que representa o item no Publicador) não depende da onda: é o mesmo nos dois modos.
     *
     * @return array<string, array{chave: string, tipo: string, nome: string, produto_id: ?int, quantidade: ?int,
     *     oferta_id: ?int, sem_produto: bool, andou: bool, sem_texto: bool, etapa: string, status: string, alerta: bool,
     *     pub_produto_id: ?int, preparo: ?array, produtos: list<int>, depende_de: list<string>}>
     */
    public static function itens(Company $empresa, bool $comoNaTela = false): array
    {
        $empresaId = (int) $empresa->id;
        $produtos = EstruturaProduto::query()->where('company_id', $empresaId)->with('variacoes')->orderBy('id')->get();
        [$ofertas, $componentes] = self::ofertasEComponentes($empresaId);

        $situacao = SituacaoDasOfertas::daEmpresa($empresa, array_map('intval', $produtos->modelKeys()));
        $ondas = $comoNaTela && TrilhaDeTrabalho::ligada($empresa) ? OndasDaEmpresa::ler($empresaId) : null;
        $faltas = $ondas !== null ? self::faltasDosProdutos($empresa) : [];
        $decididos = self::decidirItens($situacao, OfertasDaTrilha::agrupar($produtos, $ofertas, $componentes), $ondas, $faltas);

        $saida = [];
        foreach ($decididos['itens'] as $chave => $item) {
            $d = $decididos['decisoes'][$chave];
            $saida[$chave] = [
                'chave'          => $chave,
                'tipo'           => $item['tipo'],
                'nome'           => $item['nome'],
                'produto_id'     => $item['produto_id'],
                'quantidade'     => $item['quantidade'],
                'oferta_id'      => str_starts_with($chave, 'o-') ? (int) substr($chave, 2) : null,
                'sem_produto'    => $item['sem_produto'],
                'oferta_ids'     => $item['oferta_ids'],
                'andou'          => $decididos['andou'][$chave],
                'sem_texto'      => (bool) ($d['sem_texto'] ?? false),
                'etapa'          => $d['etapa'],
                // 4a (03/11/2026): o que a prévia da revisão lê — o status do item, o pub_produto que o representa (o
                // rascunho da prévia) e o preparo do robô, na régua da tela.
                'status'         => $d['status'],
                'alerta'         => (bool) $d['alerta'],
                'pub_produto_id' => $d['pub_produto_id'],
                'preparo'        => $d['preparo'],
                'produtos'       => self::produtosDoItem($item),
                'depende_de'     => $item['depende_de'],
            ];
        }

        return $saida;
    }

    /**
     * O que falta no cadastro de cada produto da empresa — a régua da etapa 1 ({@see CompletudeDoProduto}), sem montar
     * a trilha inteira. É o que o Mapeamento da trilha lê para saber quem saiu da Lista (o produto e os parceiros).
     *
     * @return array<int, list<string>> produto → faltas (vazio = completo)
     */
    public static function faltasDosProdutos(Company $empresa): array
    {
        $empresaId = (int) $empresa->id;
        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresaId)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->orderBy('id')
            ->get();
        $salvos = EstruturaProdutoAtributo::query()
            ->where('company_id', $empresaId)
            ->get(['produto_id', 'atributo_id', 'valor', 'valor_id', 'unidade'])
            ->groupBy('produto_id');
        $fotos = self::fotosPorVariacao($empresaId);
        $definicoes = CompletudeDoProduto::definicoesGuardadas($produtos->pluck('categoria_ml_id'));
        $modalidade = ModalidadeDeEnvio::emCache($empresa);
        $me1ComoAviso = TrilhaDeTrabalho::ligada($empresa);

        $saida = [];
        foreach ($produtos as $p) {
            $saida[(int) $p->id] = CompletudeDoProduto::avaliar($p, $salvos->get($p->id, collect()), $fotos, $definicoes, $modalidade, $me1ComoAviso)['faltas'];
        }

        return $saida;
    }

    /**
     * O que falta no cadastro de UM produto — a régua de {@see faltasDosProdutos()} (e da etapa 1 da tela), sem ler o
     * catálogo inteiro: o cadastro da trilha (3ª entrega, 02/11/2026) pergunta depois de cada escrita se o produto saiu
     * da Lista. Consultas fixas: o produto com família, ambientes, variações e volumes, a ficha gravada, as fotos das
     * variações e a definição guardada da categoria (tabela e cache).
     *
     * @return list<string> vazio = completo
     */
    public static function faltasDoProduto(Company $empresa, int $produtoId): array
    {
        return self::cadastroDoProduto($empresa, $produtoId)['faltas'];
    }

    /**
     * As faltas e os avisos de UM produto, pela régua de {@see faltasDoProduto()} — o retrato da gaveta do cadastro lê os
     * dois. Com a trilha de trabalho o "frete ME1" vem em `avisos` (pergunta 3), não em `faltas`.
     *
     * @return array{faltas: list<string>, avisos: list<string>}
     */
    public static function cadastroDoProduto(Company $empresa, int $produtoId): array
    {
        $empresaId = (int) $empresa->id;
        $produto = EstruturaProduto::query()
            ->where('company_id', $empresaId)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->findOrFail($produtoId);
        $salvos = EstruturaProdutoAtributo::query()
            ->where('company_id', $empresaId)
            ->where('produto_id', $produto->id)
            ->get(['produto_id', 'atributo_id', 'valor', 'valor_id', 'unidade']);
        $fotos = self::fotosPorVariacao($empresaId, array_map('intval', $produto->variacoes->modelKeys()));
        $definicoes = CompletudeDoProduto::definicoesGuardadas([$produto->categoria_ml_id]);
        $c = CompletudeDoProduto::avaliar($produto, $salvos, $fotos, $definicoes, ModalidadeDeEnvio::emCache($empresa), TrilhaDeTrabalho::ligada($empresa));

        return ['faltas' => $c['faltas'], 'avisos' => $c['avisos']];
    }

    /**
     * Quais produtos têm o cadastro TRAVADO na trilha (3ª entrega, pergunta 1, decisão do usuário de 02/11/2026): o
     * produto que QUALQUER item da trilha já levou adiante — a Simples, o Combo N, o kit e o combit que o levam — fica só
     * para leitura, porque mudar o cadastro dele faria o robô refazer texto e imagens (IA paga) e não muda o anúncio no
     * ar. "Andou" é a régua única {@see SituacaoDasOfertas::andou()}: tem anúncio, o rascunho saiu do DRAFT, o robô já
     * mexeu ou é o Kit N. A tela ({@see montar()}) e as escritas do cadastro perguntam por aqui.
     *
     * @param  iterable<array{chave: string, nome: string, andou: bool, produtos: list<int>}>  $itens  a forma de {@see itens()}
     * @return array<int, array{chave: string, nome: string}> produto → o primeiro item que andou e o leva
     */
    public static function travasDoCadastro(iterable $itens): array
    {
        $travas = [];
        foreach ($itens as $item) {
            if (! $item['andou']) {
                continue;
            }
            foreach ($item['produtos'] as $produtoId) {
                $travas[(int) $produtoId] ??= ['chave' => (string) $item['chave'], 'nome' => (string) $item['nome']];
            }
        }

        return $travas;
    }

    /**
     * Agrupa as ofertas nos itens e decide cada um: as Simples primeiro (o Combo depende da Simples dele), depois a
     * oferta sem produto, os Combos e o resto.
     *
     * Com `$ondas` (a trilha de trabalho ligada), o item que ainda não andou e tem produto segue a régua da onda; o
     * Combo espera a Simples dele ficar PRONTA (decisão de 31/10/2026: o combo não espera a simples ser publicada —
     * o robô a prepara antes), não ir ao ar.
     *
     * @param  array<int, list<string>>  $faltas  produto → faltas da lista (vazio = completo; só com `$ondas`)
     * @param  bool  $avulsasForaDasOndas  o composto feito só de ofertas sem produto sai `sem_produto` — a régua da trilha
     *         de trabalho: sempre em {@see itens()} (só a trava, a soltura e o serviço das ondas a leem) e, na tela, só com
     *         a chave (revisão de 02/11/2026: fora dela a 1ª entrega segue como era)
     * @return array{itens: array<string, array>, decisoes: array<string, array>, andou: array<string, bool>, pubs_da_simples: array<int, array>}
     */
    private static function decidirItens(SituacaoDasOfertas $situacao, array $grupos, ?OndasDaEmpresa $ondas, array $faltas, bool $avulsasForaDasOndas = true): array
    {
        $itens = [];
        $decisoes = [];
        $andou = [];
        $pubsDaSimples = [];

        $decidir = function (array $item, array $pubs, bool $esperaSimples) use (&$itens, &$decisoes, &$andou, $situacao, $ondas, $faltas, $avulsasForaDasOndas): void {
            $chave = $item['id'];
            // Trilha de trabalho: o composto feito SÓ de ofertas sem produto (Lista SKUs, importação) também fica fora
            // das ondas — a soltura não teria produto para pedir e ele ficaria "na fila" para sempre (revisão de
            // 02/11/2026). Sem a chave, a tela da 1ª entrega segue como era.
            if ($avulsasForaDasOndas && self::produtosDoItem($item) === []) {
                $item['sem_produto'] = true;
            }
            $onda = $ondas !== null && ! $item['sem_produto'] ? $ondas->contextoDoItem($chave) : null;
            // O Combo cuja Simples não está em onda nenhuma (nem andou) espera algo que ninguém vai fazer: avisar.
            if ($onda !== null && $esperaSimples && str_starts_with($chave, 'c-')) {
                $simples = 's-'.$item['produto_id'];
                $onda['simples_sem_onda'] = ! ($andou[$simples] ?? false) && $ondas->ondaDoItem($simples) === null;
            }
            $incompleto = false;
            foreach (self::produtosDoItem($item) as $produtoId) {
                $incompleto = $incompleto || ($faltas[$produtoId] ?? []) !== [];
            }
            $d = $situacao->doItem($pubs, $item['oferta_ids'], $esperaSimples, $onda, $incompleto);
            // Trilha de trabalho: a oferta sem produto na Lista fica fora das ondas (nesta entrega); o "Sem onda" de
            // antes faria parecer que falta alguém escolher a data dela — e o combo dela, que esperava a simples,
            // também não entra em onda.
            if ($ondas !== null && $item['sem_produto'] && $d['etapa'] === 'plano' && ! $d['andou']) {
                $d['status'] = 'Fora das ondas · sem produto na Lista';
                $d['alerta'] = false;
            }
            $itens[$chave] = $item;
            $decisoes[$chave] = $d;
            $andou[$chave] = $d['andou'];
        };

        foreach ($grupos['simples'] as $produtoId => $item) {
            $pubsDaSimples[$produtoId] = $situacao->pubsDaSimples((int) $produtoId, $item['oferta_ids']);
            $decidir($item, $pubsDaSimples[$produtoId], false);
        }
        foreach ($grupos['sem_produto'] as $item) {
            $decidir($item, $situacao->pubsDaOferta($item['oferta_ids'][0]), false);
        }

        // Sem a trilha de trabalho o Combo espera a Simples ir AO AR (a regra da fase N); com ela, ficar PRONTA — a
        // régua do robô (`Ondas\SimplesPronta`) vista pela tela: na revisão ou no ar, com o texto do robô, e nem
        // publicando nem com a publicação falhada (aí o Kit N também não nasce).
        $prontas = $ondas !== null ? ['revisao', 'lanc'] : ['lanc'];
        $simplesPronta = function (array $dependeDe) use (&$decisoes, $prontas, $ondas): bool {
            foreach ($dependeDe as $id) {
                $d = $decisoes[$id] ?? null;
                if (! in_array($d['etapa'] ?? null, $prontas, true)) {
                    return false;
                }
                if ($ondas !== null && $d['etapa'] === 'revisao'
                    && (($d['sem_texto'] ?? false) || in_array($d['status'], ['A publicação falhou', 'Publicando agora'], true))) {
                    return false;
                }
            }

            return true;
        };
        foreach ($grupos['combos'] as $item) {
            $pubs = $situacao->pubsDoCombo($pubsDaSimples[$item['produto_id']] ?? ['principal' => null, 'todos' => []], (int) $item['quantidade'], $item['oferta_ids']);
            $decidir($item, $pubs, ! $simplesPronta($item['depende_de']));
        }
        foreach ($grupos['avulsas'] as $item) {
            $decidir($item, $situacao->pubsDaOferta($item['oferta_ids'][0]), $item['depende_de'] !== [] && ! $simplesPronta($item['depende_de']));
        }

        return ['itens' => $itens, 'decisoes' => $decisoes, 'andou' => $andou, 'pubs_da_simples' => $pubsDaSimples];
    }

    /**
     * Os produtos do Portal cujo preparo prepara o item: o próprio produto na Simples e no Combo; os das peças no
     * Kit e no Combit (o Sincronizar de cada peça leva o composto junto). Oferta sem produto: nenhum.
     *
     * @return list<int>
     */
    private static function produtosDoItem(array $item): array
    {
        if ($item['produto_id'] !== null) {
            return [(int) $item['produto_id']];
        }
        $ids = [];
        foreach ($item['componentes'] as $c) {
            if (($c['produto_id'] ?? null) !== null) {
                $ids[(int) $c['produto_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * As ofertas da empresa e os componentes delas (com a empresa na ponta da oferta: o componente é sempre uma
     * simples da mesma empresa).
     *
     * @return array{0: Collection<int, EstruturaOferta>, 1: Collection<int, object>}
     */
    private static function ofertasEComponentes(int $empresaId): array
    {
        $ofertas = EstruturaOferta::query()
            ->where('company_id', $empresaId)
            ->orderBy('id')
            ->get(['id', 'variacao_id', 'sku', 'fase', 'nome']);

        $componentes = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->where('o.company_id', $empresaId)
            ->orderBy('c.id')
            ->get(['c.oferta_id', 'c.componente_id', 'c.quantidade']);

        return [$ofertas, $componentes];
    }

    /**
     * As ondas para a tela, na ordem de data. Quem criou vai cru: a porta do cliente corta pela lista de campos
     * (`ApresentacaoDaTrilha::ONDA_CLIENTE`).
     *
     * @return list<array{id: int, nome: string, data: string, comecou: bool, criada_por: ?string, criada_em: ?string}>
     */
    private static function ondas(OndasDaEmpresa $ondas): array
    {
        return array_map(fn (TrilhaOnda $o) => [
            'id'         => (int) $o->id,
            'nome'       => (string) $o->nome,
            'data'       => $o->dia(),
            'comecou'    => $o->comecou($ondas->hoje),
            'criada_por' => $o->autor?->name,
            'criada_em'  => $o->created_at?->copy()->setTimezone(TrilhaOnda::FUSO)->toIso8601String(),
        ], $ondas->ondas());
    }

    /**
     * O item no formato do contrato.
     */
    private static function item(array $item, array $s, ?TrilhaOnda $onda, ?OndasDaEmpresa $ondas): array
    {
        $trabalho = $ondas !== null;

        return [
            'id'             => $item['id'],
            'tipo'           => $item['tipo'],
            'nome'           => $item['nome'],
            'sku'            => $item['sku'],
            'skus'           => $item['skus'],
            'componentes'    => $item['componentes'],
            'etapa'          => $s['etapa'],
            'status'         => $s['status'],
            'alerta'         => $s['alerta'],
            'anuncios'       => $s['anuncios'],
            'preparo'        => $s['preparo'],
            'onda_id'        => $onda !== null ? (int) $onda->id : null,
            'sem_produto'    => $item['sem_produto'],
            // Pode entrar, mudar ou sair de onda (a mesma régua do serviço das ondas): trilha de trabalho ligada, tem
            // produto e não andou.
            'planejavel'     => $trabalho && ! $item['sem_produto'] && ! $s['andou'],
            // Pode SAIR da onda dela enquanto a onda não começou — também a que andou (alguém preparou à mão antes da
            // data) e a que ficou sem produto: o serviço aceita tirar as duas (revisão de 02/11/2026). Onda que
            // começou não devolve ninguém.
            'pode_tirar'     => $trabalho && $onda !== null && ! $onda->comecou($ondas->hoje),
            'pub_produto_id' => $s['pub_produto_id'],
        ];
    }

    /**
     * As sugestões PENDENTES (não descartadas) de cada produto envolvido nelas, na ordem da tela de
     * Planejamento. Aceita, a sugestão vira oferta e sai da geração; descartada, vem marcada.
     *
     * @param  list<array<string, mixed>>  $sugestoes  saída de `SugestoesService::gerar()['sugestoes']`
     * @return array<int, list<array{fase: string, nome: string, sku: ?string}>>
     */
    private static function sugestoesPendentes(array $sugestoes): array
    {
        $porProduto = [];
        foreach ($sugestoes as $s) {
            if (! empty($s['descartada'])) {
                continue;
            }
            $produtos = [];
            foreach ((array) ($s['itens'] ?? []) as $i) {
                if (isset($i['produto_id'])) {
                    $produtos[(int) $i['produto_id']] = true;
                }
            }
            $sku = trim((string) ($s['sku'] ?? ''));
            foreach (array_keys($produtos) as $produtoId) {
                $porProduto[$produtoId][] = ['fase' => (string) $s['fase'], 'nome' => (string) $s['nome'], 'sku' => $sku !== '' ? $sku : null];
            }
        }

        return $porProduto;
    }

    /**
     * Quantas fotos cada variação tem, numa consulta agrupada — da empresa toda ou só das variações pedidas.
     *
     * @param  list<int>|null  $variacaoIds  null = todas as da empresa
     * @return array<int, int>
     */
    private static function fotosPorVariacao(int $empresaId, ?array $variacaoIds = null): array
    {
        if ($variacaoIds === []) {
            return [];
        }
        $saida = [];
        $linhas = DB::table('estrutura_produto_variacao_imagens')
            ->where('company_id', $empresaId)
            ->when($variacaoIds !== null, fn ($q) => $q->whereIn('variacao_id', $variacaoIds))
            ->groupBy('variacao_id')
            ->select('variacao_id', DB::raw('count(*) as total'))
            ->get();
        foreach ($linhas as $l) {
            $saida[(int) $l->variacao_id] = (int) $l->total;
        }

        return $saida;
    }

    /** Chave de ordem do nome: sem acento e sem caixa ("Ábaco" junto de "abacate"). */
    private static function chave(string $nome): string
    {
        return Str::lower(Str::ascii($nome));
    }
}
