<?php

namespace App\Http\Controllers;

use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\EditorEmUso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Descrição do anúncio pela IA (MAG T8) no editor do Publicador (Fase 172, D-09/D-11).
 * O POST só enfileira; o GET devolve o estado do pedido (cache). Quem aplica o texto no
 * rascunho é a tela, pelo caminho normal de edição.
 */
class MlbPublicadorDescricaoController extends Controller
{
    public function __construct(
        private ProgramasPublicadorService $programas,
        private DescricaoIaService $descricao,
    ) {}

    /** Pede a descrição. `automatico` (D-11) só vale uma vez por rascunho vazio e com texto do cliente. */
    public function pedir(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate(['automatico' => ['sometimes', 'boolean']]);
        $r = $this->rascunho($produto);
        $automatico = (bool) ($dados['automatico'] ?? false);

        $pedido = $this->descricao->pedir($r, $automatico);
        if ($pedido === null) {
            // Já pedido antes, ou sem material (descrição preenchida / cliente não escreveu nada).
            $jaPedido = trim((string) $r->descricao) === '' && cache()->has(DescricaoIaService::chaveAuto($r->id));

            return response()->json(['status' => $jaPedido ? 'ja_pedido' : 'nao_se_aplica']);
        }

        return response()->json(['pedido' => $pedido, 'status' => 'rodando'], 202);
    }

    public function estado(int $produto): JsonResponse
    {
        return response()->json($this->descricao->estado($this->rascunho($produto)) ?? ['status' => 'nenhum']);
    }

    /** O rascunho do produto autorizado: inexistente, de empresa arquivada ou sem dono → 404. */
    private function rascunho(int $id): PubRascunho
    {
        $p = PubProduto::findOrFail($id);
        abort_if($this->programas->empresaDoProduto($p) === null, 404);
        // A tela está aberta: o preparo pela IA (salvar no Portal) espera (EditorEmUso).
        EditorEmUso::marcar((int) $p->id);

        return PubRascunho::where('produto_id', $p->id)->firstOrFail();
    }
}
