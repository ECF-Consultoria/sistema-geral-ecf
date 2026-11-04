<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\AjusteDoRoboNaTrilha;
use App\Services\Trilha\CadastroNaTrilha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Pedir ajuste ao robô" na porta da EQUIPE da trilha (4ª entrega, fatia 4b, desenho §19.14). Fino: a regra mora em
 * {@see AjusteDoRoboNaTrilha}.
 *
 * A guarda das duas rotas começa pela do cadastro ({@see CadastroNaTrilha::empresa()}): as duas chaves (404, antes de
 * ler a empresa) e a escrita no Portal da empresa (403). O resto — o item do endereço (404) antes do corpo, o 409 e o
 * 422 — é do serviço, que lê do corpo só `revisao` e `refazer` (nenhum texto livre segue adiante: o teste manda chaves a
 * mais e confere a IA). A porta do cliente não tem estas rotas (nem recebe o endereço).
 */
class AjusteDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private AjusteDoRoboNaTrilha $ajuste,
    ) {}

    /** `POST /trilha/{company}/ofertas/{item}/ajuste` — 202 `{ajuste: {situacao: 'fila', …}}`. */
    public function pedir(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->ajuste->pedir($empresa, $item, $request->all(), $request->user()), 202);
    }

    /** `GET /trilha/{company}/ofertas/{item}/ajuste` — 200 `{ajuste: {…}}` ou `{ajuste: null}`. */
    public function estado(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->ajuste->acompanharPedido($empresa, $item));
    }
}
