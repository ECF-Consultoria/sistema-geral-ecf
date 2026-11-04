<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PrecoNaTrilha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O preço ajustável da revisão na porta da EQUIPE da trilha (4ª entrega, fatia 4c, desenho §19.11). Fino: a regra mora
 * em {@see PrecoNaTrilha}.
 *
 * A guarda é a da prévia ({@see CadastroNaTrilha::empresa()}): as duas chaves (404, antes de ler a empresa) e a escrita no
 * Portal da empresa (403). O resto da ordem — o item do endereço (404) ANTES do corpo, o 409 do estado e da revisão e só
 * então o 422 — é do serviço, porque o 409 da revisão precisa do rascunho do item. A porta do cliente não tem esta rota
 * (e não recebe o endereço).
 */
class PrecoDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private PrecoNaTrilha $preco,
    ) {}

    /** `PUT /trilha/{company}/ofertas/{item}/preco` — 200 com a prévia nova (§19.5, `preco` ampliado) e `avisos`. */
    public function ajustar(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->preco->ajustar($empresa, $item, $request->all(), $request->user()));
    }
}
