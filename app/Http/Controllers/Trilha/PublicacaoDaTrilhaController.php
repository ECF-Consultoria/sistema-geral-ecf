<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PublicacaoNaTrilha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aprovar e publicar na porta da EQUIPE da trilha (4ª entrega, fatia 4d, desenho §19.13). Fino: a regra — inclusive a
 * guarda da #459 no código (D48) — mora em {@see PublicacaoNaTrilha}.
 *
 * A guarda das duas rotas começa pela do cadastro ({@see CadastroNaTrilha::empresa()}): as duas chaves (404, antes de
 * ler a empresa) e a escrita no Portal da empresa (403). O resto — o item do endereço (404), a loja (403), o 409 e o
 * 422 — é do serviço. A porta do cliente não tem estas rotas (nem recebe os endereços).
 */
class PublicacaoDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private PublicacaoNaTrilha $publicacao,
    ) {}

    /** `POST /trilha/{company}/ofertas/{item}/publicar` — 202 `{publicacao: {situacao: 'conferindo', …}}`. */
    public function publicar(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->publicacao->pedir($empresa, $item, $request->all(), $request->user()), 202);
    }

    /** `GET /trilha/{company}/ofertas/{item}/publicacao` — 200 `{publicacao: {…}}` ou `{publicacao: null}`. */
    public function publicacao(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->publicacao->acompanhar($empresa, $item));
    }
}
