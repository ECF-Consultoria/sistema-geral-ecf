<?php

namespace App\Http\Controllers;

use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\PreviaDaFaseService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\SugestaoKitIaService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Fases do produto no Publicador (§3 e §4 da ETAPA-3, Fase 175).
 *
 * - A TELA do Produto — `mostrar()`, plano 175-04.
 * - A PRÉVIA e a CRIAÇÃO da fase — `previa()` e `criar()`, plano 175-05.
 * - "SUGERIR COM IA" — `pedirIa()` e `iaStatus()`, plano 175-06 (só título e
 *   descrição; assíncrono, com o resultado no cache do pedido).
 *
 * O vínculo de combos chega nas plans 175-07 a 175-10 e mora aqui, neste mesmo
 * controller.
 *
 * ═══ Escopo (D-13) ══════════════════════════════════════════════════════════
 *
 * `{conta}` vem SEMPRE do resolver, nunca do corpo da requisição, e a própria
 * rota recusa o que não casa com `(empresa|company)-[0-9]+`. O produto é
 * buscado DENTRO de `produtosQuery($alvo['mlb_empresa'], $alvo['company'])`:
 * produto de outra conta simplesmente não existe neste escopo e sai como
 * **404, nunca 403** — 403 confirmaria a existência do id para quem só trocou
 * o número na URL.
 *
 * Zero chamada ao Mercado Livre neste request (regra herdada da Etapa 2): tudo
 * o que a tela mostra já está gravado.
 *
 * ═══ O que NUNCA vem do corpo da requisição ═════════════════════════════════
 *
 * - **Âncoras** (`mlb_empresa_id`, `company_id`, `produto_base_id`): copiadas do
 *   BASE dentro do `CriarFaseService` (T-175-17). Este controller nem lê.
 * - **Estoque** (T-175-18): recalculado no servidor pela `PreviaDaFaseService`
 *   na hora do POST. O corpo não tem campo de estoque, e o que vier é ignorado.
 * - **Ator** (T-175-21): o usuário logado, via `AtorDoPortal::daEquipe()`.
 */
class MlbPublicadorFaseController extends Controller
{
    /**
     * Os status de `pub_rascunhos` que contam como "Fase 1 publicada" no gate da
     * §4/§9 — `PARTIALLY_PUBLISHED` incluído ("Parte publicada").
     *
     * São as duas chaves que `EditorRascunhoService::prontidao()` mapeia para
     * `publicado`/`parcial`; o ramo de validação do `prontidao()` só vale para
     * rascunho `DRAFT`, então ler o status direto dá o MESMO resultado que o
     * `FamiliaDeFasesService::proximaFase()` usa para habilitar o botão — sem a
     * consulta extra de validações.
     */
    private const PUBLICADO = [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED];

    public function __construct(
        private ProgramasPublicadorService $programas,
        private FamiliaDeFasesService $familia,
        private PreviaDaFaseService $previas,
        private CriarFaseService $clone,
        private SugestaoKitIaService $sugestoes,
    ) {}

    /**
     * `GET publicador/empresas/{conta}/produtos/{produto}` — a tela do Produto:
     * cabeçalho, cartões de fase, ofertas no ar, histórico e as duas laterais.
     *
     * Abrir um KIT por esta rota leva à tela do BASE com a fase do kit
     * destacada (§3) — nunca a uma tela de kit solta. Quem decide isso é o
     * `FamiliaDeFasesService`, que também marca `base_excluido` quando o base
     * de um kit foi apagado (`produto_base_id` NULL depois do SET NULL).
     */
    public function mostrar(string $conta, int $produto)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        // `company-N` ligada a MlbEmpresa ativa de Polos/Incubadora tem chave
        // canônica `empresa-N`: redireciona preservando o {produto}.
        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.produto', [
                'conta' => $alvo['chave'],
                'produto' => $produto,
            ]);
        }

        // D-13: o escopo é a própria query. Fora dele, 404 — NUNCA abort(403).
        $p = $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])
            ->whereKey($produto)
            ->first();
        abort_if($p === null, 404);

        // A barra da conta mostra a conta do PRODUTO quando ela difere da da
        // empresa resolvida (WR-B01) — mesma regra do editor.
        $empresa = $this->programas->empresaParaTela($alvo, $p);
        $payload = $this->familia->paraTela($p, $alvo, $empresa);

        return Inertia::render('Mlb/Publicador/Produto', [
            'empresa' => $empresa,
            'liberada' => ContasLiberadas::libera($p->contaOuNula()),
            // O contrato da tela chama o cabeçalho de `produto` (é o produto
            // base da família); o serviço devolve a mesma coisa em `base`.
            'produto' => $payload['base'],
            'fase_destacada' => $payload['fase_destacada'],
            'fases' => $payload['fases'],
            'proxima_fase' => $payload['proxima_fase'],
            'ofertas' => $payload['ofertas'],
            'historico' => $payload['historico'],
            'criativos' => $payload['criativos'],
            'mapeamento' => $payload['mapeamento'],
            'abas' => ['company_id' => $alvo['company']?->id],
        ]);
    }

    // ═══ §4 — o painel "Criar Fase N" (plano 175-05) ═════════════════════════

    /**
     * `GET …/produtos/{produto}/fases/previa?quantidade=N` — o que vai nascer,
     * antes de confirmar: SKU, título por tipo, descrição, estoque por variante
     * e por depósito, avisos e o erro de campo da quantidade duplicada.
     *
     * **Não grava nada.** É o mesmo cálculo que o `criar()` roda de novo no
     * servidor, pela mesma classe — a prévia não pode divergir do Confirmar.
     */
    public function previa(Request $request, string $conta, int $produto): JsonResponse
    {
        $base = $this->baseDaConta($conta, $produto);
        $dados = $request->validate([
            'quantidade' => ['required', 'integer', 'min:2', 'max:'.PreviaDaFaseService::MAX_QUANTIDADE],
        ]);

        return response()->json($this->previas->previa($base, (int) $dados['quantidade']));
    }

    /**
     * `POST …/produtos/{produto}/fases` — cria a fase (o kit de N unidades) numa
     * transação e devolve a URL do editor do kit na etapa "Condições de venda".
     *
     * O que o corpo pode mandar: `quantidade`, `sku`, `titulo`, `descricao`,
     * `seller_skus` e a flag `capa`. Tudo o mais é calculado aqui — ver o
     * docblock da classe.
     *
     * ⚠️ A capa é **recebida como flag e devolvida na resposta**; quem dispara a
     * geração é o 175-07, de propósito: a decisão de produto sobre a capa
     * ambientada não pode travar a criação da fase.
     */
    public function criar(Request $request, string $conta, int $produto): JsonResponse
    {
        $base = $this->baseDaConta($conta, $produto);

        $dados = $request->validate([
            'quantidade' => ['required', 'integer', 'min:2', 'max:'.PreviaDaFaseService::MAX_QUANTIDADE],
            'sku' => ['required', 'string', 'max:120'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'seller_skus' => ['nullable', 'array'],
            'seller_skus.*' => ['nullable', 'string', 'max:120'],
            'capa' => ['nullable', 'boolean'],
        ]);

        $quantidade = (int) $dados['quantidade'];

        // Gate da §4 ("Abrir: só com base Publicado, inclui parcial") conferido no
        // SERVIDOR: o painel só ESCONDE o botão, e esconder não é impedir.
        //
        // Base SEM rascunho passa por aqui de propósito: a recusa dele é o KIT-01
        // do `CriarFaseService` ("Abra a Fase 1 no editor antes"), que diz o que
        // fazer — "Publique a Fase 1 primeiro" seria o conselho errado para quem
        // ainda não começou a preencher.
        $rascunhoDoBase = $base->rascunho;
        if ($rascunhoDoBase !== null && ! in_array((string) $rascunhoDoBase->status, self::PUBLICADO, true)) {
            return $this->recusa(new RegraViolada('KIT-05', FamiliaDeFasesService::MOTIVO_FASE_1));
        }

        // O MESMO cálculo da prévia, refeito aqui: é daqui que sai o estoque
        // (T-175-18) e as sugestões de título/SELLER_SKU que o corpo não mandou.
        $previa = $this->previas->previa($base, $quantidade);

        try {
            $kit = $this->clone->criar($base, [
                'quantidade' => $quantidade,
                'sku' => $dados['sku'],
                'titulo_por_tipo' => $this->titulosParaGravar($previa, $dados['titulo'] ?? null),
                'descricao' => $this->texto($dados['descricao'] ?? null) ?? $previa['descricao'],
                'seller_skus' => $this->sellerSkusParaGravar($previa, (array) ($dados['seller_skus'] ?? [])),
                'estoque_por_variante' => $this->estoqueParaGravar($previa),
                'ator' => $this->ator($request),
            ]);
        } catch (RegraViolada $e) {
            return $this->recusa($e);
        }

        return response()->json([
            'produto' => ['id' => $kit->id],
            // ✅ Conferido em `resources/js/Components/Publicador/apoio.js`: o
            // `Editor.jsx` lê `?etapa=` da URL (`PARAMETRO_ETAPA`) e passa por
            // `etapaValida()`, cujas chaves são produto|detalhes|imagens|condicoes.
            // `condicoes` é "Condições de venda" — exatamente o que a §4 pede.
            'url' => route('mlb.anuncios.publicador.editor', ['produto' => $kit->id]).'?etapa=condicoes',
            // Registrada, não disparada: o job da capa é do 175-07.
            'capa_pedida' => (bool) ($dados['capa'] ?? false),
        ], 201);
    }

    // ═══ §4 — "Sugerir com IA" (plano 175-06) ════════════════════════════════

    /**
     * `POST …/produtos/{produto}/fases/ia` — põe na fila o pedido de título OU
     * descrição do kit por IA e responde **202** na hora: a IA leva de segundos
     * a minutos e nunca roda dentro da requisição.
     *
     * ⚠️ O pedido é escopado ao rascunho do **BASE**: no momento do painel o
     * rascunho do kit ainda não existe. E a IA mexe SÓ em título e descrição —
     * SKU, SELLER_SKU e estoque seguem sendo da `PreviaDaFaseService`.
     *
     * Nada é gravado no rascunho: o resultado fica no cache do pedido e quem o
     * aplica é a tela, pelo caminho normal de edição (T-175-27).
     */
    public function pedirIa(Request $request, string $conta, int $produto): JsonResponse
    {
        $base = $this->baseDaConta($conta, $produto);

        $dados = $request->validate([
            'alvo' => ['required', 'string', Rule::in(SugestaoKitIaService::ALVOS)],
            'quantidade' => ['required', 'integer', 'min:2', 'max:'.PreviaDaFaseService::MAX_QUANTIDADE],
        ]);

        $rascunhoDoBase = $base->rascunho;
        if ($rascunhoDoBase === null) {
            // Mesma recusa (e mesmo conselho) do KIT-01: sem a Fase 1 preparada não
            // há título nem descrição de onde partir.
            return $this->recusa(new RegraViolada('KIT-01', 'Abra a Fase 1 no editor antes de pedir sugestões para o kit.'));
        }

        $pedido = $this->sugestoes->pedir($rascunhoDoBase, $dados['alvo'], (int) $dados['quantidade']);

        return response()->json(['pedido' => $pedido, 'status' => 'rodando'], 202);
    }

    /**
     * `GET …/produtos/{produto}/fases/ia/{alvo}?quantidade=N` — o polling do
     * pedido. Alvo nunca pedido responde `{status: 'nenhum'}`, nunca 404: "não
     * pedi nada ainda" é estado normal da tela, não erro.
     *
     * A quantidade é obrigatória porque ela faz parte da chave do pedido —
     * o resultado de "Kit 2" não responde ao painel de "Kit 4".
     */
    public function iaStatus(Request $request, string $conta, int $produto, string $alvo): JsonResponse
    {
        $base = $this->baseDaConta($conta, $produto);

        $dados = $request->validate([
            'quantidade' => ['required', 'integer', 'min:2', 'max:'.PreviaDaFaseService::MAX_QUANTIDADE],
        ]);

        $rascunhoDoBase = $base->rascunho;
        if ($rascunhoDoBase === null) {
            return response()->json(['status' => 'nenhum']);
        }

        return response()->json(
            $this->sugestoes->estado($rascunhoDoBase, $alvo, (int) $dados['quantidade']) ?? ['status' => 'nenhum']
        );
    }

    // ═══ Apoio dos endpoints ═════════════════════════════════════════════════

    /**
     * O produto BASE da família, escopado pela conta. Mesma disciplina do
     * `mostrar()`: `resolver()` + `produtosQuery()->whereKey()` + 404.
     *
     * Pedir pelo id de um KIT responde sobre o BASE (§3: abrir um kit leva à tela
     * do base), e é o que impede uma cadeia de kits: a fase nova sempre nasce do
     * produto de 1 unidade.
     */
    private function baseDaConta(string $conta, int $produto): PubProduto
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        // D-13: o escopo é a própria query. Fora dele, 404 — NUNCA abort(403).
        $p = $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])
            ->whereKey($produto)
            ->first();
        abort_if($p === null, 404);

        return $p->base ?? $p;
    }

    /**
     * A recusa no formato que o módulo já usa (`MlbPublicadorController::responder()`),
     * mais o `campo` que o painel precisa para marcar o input.
     *
     * ⚠️ `campo` sai do `contexto` da `RegraViolada`, não fixo em `quantidade`:
     * KIT-03 e KIT-04 marcam o campo da quantidade, KIT-01/02/05 são recusas do
     * produto inteiro e não têm campo nenhum para marcar.
     */
    private function recusa(RegraViolada $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'regra' => $e->regra,
            'campo' => $e->contexto['campo'] ?? null,
        ], 422);
    }

    /**
     * O título por tipo de anúncio. O painel tem UM campo de título (§4), então
     * o que vier no corpo vale para todos os tipos; sem nada no corpo, cada tipo
     * fica com a sugestão dele — que preserva a diferença entre os títulos do
     * base quando ela existe.
     *
     * @return array<string, string>
     */
    private function titulosParaGravar(array $previa, ?string $doCorpo): array
    {
        $titulo = $this->texto($doCorpo);
        if ($titulo === null) {
            return $previa['titulo_por_tipo'];
        }

        return array_fill_keys(array_keys($previa['titulo_por_tipo']), $titulo);
    }

    /**
     * O SELLER_SKU por variante: o do corpo quando a pessoa editou, senão o
     * `{SELLER_SKU}-KIT{N}` que a prévia calculou. Chave que a prévia não conhece
     * é descartada — o corpo não inventa variante.
     *
     * @param  array<string, mixed>  $doCorpo
     * @return array<string, string>
     */
    private function sellerSkusParaGravar(array $previa, array $doCorpo): array
    {
        $skus = [];
        foreach ($previa['variantes'] as $chave => $v) {
            $skus[$chave] = $this->texto($doCorpo[$chave] ?? null) ?? $v['seller_sku'];
        }

        return $skus;
    }

    /**
     * O estoque de cada variante, **só** do que a prévia calculou (T-175-18). O
     * corpo não participa: `floor(estoque do base ÷ N)` é derivado, não digitado.
     *
     * @return array<string, array{estoque: ?int, depositos: ?array}>
     */
    private function estoqueParaGravar(array $previa): array
    {
        $estoques = [];
        foreach ($previa['variantes'] as $chave => $v) {
            $estoques[$chave] = ['estoque' => $v['estoque'], 'depositos' => $v['depositos']];
        }

        return $estoques;
    }

    /**
     * Quem criou a fase (T-175-21), no mesmo formato de
     * `PublicacaoService::atorParaGravar()` — que é `private static` lá e não
     * pode ser reaproveitado daqui. Os dois têm de continuar iguais.
     *
     * @return array{equipe: bool, id: int, nome: string}
     */
    private function ator(Request $request): array
    {
        $ator = AtorDoPortal::daEquipe($request->user());

        return ['equipe' => $ator->equipe, 'id' => $ator->modelo->getKey(), 'nome' => $ator->nome];
    }

    /** Texto do corpo que vale: `null` e string em branco são a mesma coisa (= não mandou). */
    private function texto(?string $valor): ?string
    {
        $limpo = trim((string) $valor);

        return $limpo === '' ? null : $limpo;
    }
}
