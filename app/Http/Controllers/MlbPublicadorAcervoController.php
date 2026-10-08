<?php

namespace App\Http\Controllers;

use App\Models\MlAnuncioCriativo;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\Criativos\PublicadorAcervoService;
use App\Services\Publicador\Criativos\PublicadorCriativoReferenciaService;
use App\Services\Publicador\ProgramasPublicadorService;
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
