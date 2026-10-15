<?php

namespace App\Http\Controllers;

use App\Models\AcervoImagem;
use App\Models\MlAnuncioCriativo;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\AcervoDeImagensService;
use App\Services\Publicador\AcervoExclusaoService;
use App\Services\Publicador\Criativos\PublicadorAcervoService;
use App\Services\Publicador\Criativos\PublicadorCriativoAprovacaoService;
use App\Services\Publicador\Criativos\PublicadorCriativoReferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 171 (D4, ACERVO-01..05) — o acervo navegável das imagens já geradas
 * desta CONTA (e, opcionalmente, de toda a conta, não só do produto aberto).
 *
 * Mesma disciplina D-13 de `MlbPublicadorCriativoController`: `{criativo}`
 * é sempre um id NUMÉRICO, nunca um token de 32 caracteres, nem na URL nem
 * no JSON — e toda rota devolve 404 (nunca 403) quando o id existe mas é de
 * outra conta, para não revelar a existência do registro.
 *
 * **Diferença deliberada em relação ao Creative Engine do Publicador
 * (165-04).** Lá o escopo é por `pub_rascunho_id` (um kit pertence a UM
 * anúncio). Aqui o escopo é pela CONTA do produto AUTORIZADO
 * (`company_id`/`mlb_empresa_id`) — o acervo precisa listar e servir itens
 * de QUALQUER rascunho da mesma conta, vivo ou já apagado (criativo órfão,
 * `pub_rascunho_id` nulo). Por isso `criativoDaConta()` nunca filtra por
 * `pub_rascunho_id`.
 */
class MlbPublicadorAcervoController extends Controller
{
    public function __construct(
        private CreativeEngineAtivo $chave,
        private ProgramasPublicadorService $programas,
        private PublicadorAcervoService $acervo,
        private PublicadorCriativoReferenciaService $referencias,
        private AcervoDeImagensService $biblioteca,
        private ImagemAssetService $imagens,
        private EditorRascunhoService $editor,
        private AcervoExclusaoService $exclusao,
    ) {}

    /** A listagem do acervo — do produto aberto, ou de toda a conta com `toda_conta=1`. */
    public function listar(Request $request, int $produto): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $todaConta = $request->boolean('toda_conta');

        return response()->json([...$this->acervo->listar($r, $todaConta), 'toda_conta' => $todaConta]);
    }

    /** O binário da imagem gerada de um item do acervo — mesma disciplina de `MlbPublicadorCriativoController::imagem()`. */
    public function imagem(int $produto, int $criativo): Response
    {
        $r = $this->rascunhoAutorizado($produto);
        $origem = $this->criativoDaConta($r, $criativo);

        abort_if($origem->imagem_path === null, 404, 'Imagem ainda não foi gerada.');

        $disco = Storage::disk('local');
        abort_unless($disco->exists($origem->imagem_path), 404, 'Imagem não encontrada.');

        return response($disco->get($origem->imagem_path), 200, [
            'Content-Type' => $origem->imagem_mime ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Copia uma imagem do acervo para o rascunho ATUAL, no grupo pedido — nunca gasta geração de IA. */
    public function usar(Request $request, int $produto, int $criativo): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $origem = $this->criativoDaConta($r, $criativo);

        $dados = $request->validate(['grupo' => ['required', 'string', 'max:600']]);

        if (! in_array($dados['grupo'], $this->referencias->gruposValidos($r), true)) {
            return $this->recusa('Este grupo de fotos não existe mais neste anúncio. Recarregue a página.');
        }

        $res = $this->acervo->reaproveitar($r, $origem, $dados['grupo'], $request->user());

        if (! $res['ok']) {
            return $this->recusa((string) $res['mensagem']);
        }

        // imagem_id como STRING — mesma convenção de PublicadorCriativoKitPresenter::paraTela()
        // (evita perda de precisão em id grande no JS, consistência de contrato).
        return response()->json(['ok' => true, 'imagem_id' => (string) $res['imagem_id'], 'repetida' => $res['repetida']]);
    }

    // ═══ Acervo da conta v28.0 (Fase 198) — `acervo_imagens`: uploads + IA aprovada ═══════════════
    //
    // Fonte nova do acervo: o ativo da conta (`AcervoImagem`), não mais o criativo. Por isso estes
    // endpoints NÃO dependem da chave do Creative Engine — upload manual também é acervo. Mesma
    // disciplina D-13: `{ativo}` é id numérico e ativo de outra conta dá 404, nunca 403.
    // Os endpoints por `{criativo}` acima ficam como legado (Fase 171) até a tela antiga sair.

    /** A página do acervo com filtros (origem, só este produto, busca, período, ordem). */
    public function ativos(Request $request, int $produto): JsonResponse
    {
        $r = $this->rascunhoDoProduto($produto);
        $f = $request->validate([
            'origem' => ['nullable', 'in:upload,ia'],
            'so_este_produto' => ['nullable', 'boolean'],
            'busca' => ['nullable', 'string', 'max:120'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'ordem' => ['nullable', 'in:recentes,antigas'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->biblioteca->listar($r, [...$f, 'so_este_produto' => $request->boolean('so_este_produto')]));
    }

    /** A miniatura WebP da grade (gerada na primeira vez); sem ela, o original. */
    public function ativoMiniatura(int $produto, int $ativo)
    {
        $a = $this->ativoDoProduto($produto, $ativo);
        $caminho = $this->biblioteca->miniatura($a) ?? $a->caminho;

        return ArquivosDeImagem::resposta($caminho, ['Cache-Control' => 'private, max-age=86400']) ?? abort(404);
    }

    /** O arquivo original, para a visualização ampliada. */
    public function ativoOriginal(int $produto, int $ativo)
    {
        $a = $this->ativoDoProduto($produto, $ativo);

        return ArquivosDeImagem::resposta($a->caminho, ['Cache-Control' => 'private, max-age=300']) ?? abort(404);
    }

    /** Usa o ativo no rascunho ATUAL, no grupo pedido — sem cópia física e sem gerar nada. */
    public function ativoUsar(Request $request, int $produto, int $ativo): JsonResponse
    {
        $r = $this->rascunhoDoProduto($produto);
        $a = $this->biblioteca->ativoDaConta($r, $ativo) ?? abort(404, 'Imagem não encontrada.');
        $dados = $request->validate(['grupo' => ['required', 'string', 'max:600']]);

        if (in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
            return $this->recusa('Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
        }
        if (! in_array($dados['grupo'], $this->referencias->gruposValidos($r), true)) {
            return $this->recusa('Este grupo de fotos não existe mais neste anúncio. Recarregue a página.');
        }

        // FORA de transação: pode subir a foto ao Mercado Livre (D26).
        $res = $this->imagens->usarDoAcervo($r, $a);
        $this->editor->colocarFotoNoGrupo($r, $res['imagem'], $dados['grupo']);

        return response()->json(['ok' => true, 'imagem_id' => (string) $res['imagem']->id, 'repetida' => ! $res['nova']]);
    }

    // ─── Exclusão segura (v28.0 Fase 199) ────────────────────────────────────────────────────────

    /** Antes de confirmar: onde a imagem está em uso e se a exclusão é permitida. */
    public function ativoExclusao(int $produto, int $ativo): JsonResponse
    {
        return response()->json($this->exclusao->analisar($this->ativoDoProduto($produto, $ativo)));
    }

    /** Exclui do acervo (o objeto sai do storage por job). Em uso em anúncio não publicado = 422 com a lista. */
    public function ativoExcluir(int $produto, int $ativo): JsonResponse
    {
        $res = $this->exclusao->pedir($this->ativoDoProduto($produto, $ativo));

        return $res['ok']
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false, 'bloqueios' => $res['analise']['bloqueios'],
                'erros' => [['mensagem' => 'Esta imagem está em anúncio que ainda não foi publicado. Tire a foto de lá antes de excluir.']]], 422);
    }

    /** Exclusão em lote: cada imagem passa pela mesma regra; devolve o que saiu e o que ficou (e por quê). */
    public function ativosExcluirLote(Request $request, int $produto): JsonResponse
    {
        $r = $this->rascunhoDoProduto($produto);
        $dados = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.AcervoExclusaoService::MAXIMO_LOTE],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $excluidas = [];
        $recusadas = [];
        foreach (array_values(array_unique($dados['ids'])) as $id) {
            $a = $this->biblioteca->ativoDaConta($r, (int) $id);
            if ($a === null) {
                $recusadas[] = ['id' => (int) $id, 'motivo' => 'Imagem não encontrada nesta conta.'];

                continue;
            }
            $res = $this->exclusao->pedir($a);
            $res['ok']
                ? $excluidas[] = $a->id
                : $recusadas[] = ['id' => $a->id, 'motivo' => 'Em anúncio ainda não publicado.', 'bloqueios' => $res['analise']['bloqueios']];
        }

        return response()->json(['ok' => true, 'excluidas' => $excluidas, 'recusadas' => $recusadas]);
    }

    /** O produto autorizado (programa ativo) + o rascunho — sem a chave do Creative Engine. */
    private function rascunhoDoProduto(int $produto): PubRascunho
    {
        $p = PubProduto::findOrFail($produto);
        abort_if($this->programas->empresaDoProduto($p) === null, 404);

        return PubRascunho::where('produto_id', $p->id)->firstOrFail();
    }

    private function ativoDoProduto(int $produto, int $ativo): AcervoImagem
    {
        return $this->biblioteca->ativoDaConta($this->rascunhoDoProduto($produto), $ativo) ?? abort(404, 'Imagem não encontrada.');
    }

    /** O produto autorizado + o rascunho do Publicador — chave desligada ou fora do escopo dão 404. */
    private function rascunhoAutorizado(int $produto): PubRascunho
    {
        abort_unless($this->chave->ativa(), 404);

        $p = PubProduto::findOrFail($produto);
        abort_if($this->programas->empresaDoProduto($p) === null, 404);

        return PubRascunho::where('produto_id', $p->id)->firstOrFail();
    }

    /**
     * O criativo do acervo, escopado pela CONTA do produto AUTORIZADO
     * (nunca por `pub_rascunho_id` — o acervo cruza rascunhos de propósito,
     * inclusive criativos órfãos cujo rascunho de origem já foi apagado).
     */
    private function criativoDaConta(PubRascunho $r, int $criativo): MlAnuncioCriativo
    {
        $produto = $r->produto;

        $c = MlAnuncioCriativo::whereKey($criativo)
            ->whereNotNull('slot_indice')
            ->where('company_id', $produto->company_id)
            ->where('mlb_empresa_id', $produto->mlb_empresa_id)
            ->first();

        abort_if($c === null, 404, 'Imagem não encontrada.');

        return $c;
    }

    private function recusa(string $mensagem, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'erros' => [['mensagem' => $mensagem]]], $status);
    }
}
