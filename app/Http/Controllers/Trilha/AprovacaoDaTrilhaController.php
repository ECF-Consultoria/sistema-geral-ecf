<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\AprovacaoDoClienteNaTrilha;
use App\Services\Trilha\CadastroNaTrilha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mandar ao cliente" na porta da EQUIPE da trilha (4ª entrega, fatia 4e, desenho §19.16). Fino: a regra mora em
 * {@see AprovacaoDoClienteNaTrilha}.
 *
 * A guarda começa pela do cadastro ({@see CadastroNaTrilha::empresa()}): as duas chaves da trilha (404, antes de ler a
 * empresa) e a escrita no Portal da empresa (403). O resto — o item do endereço (404), a chave da aprovação desligada
 * (409), o estado e a revisão (409/422) e o Premium = Clássico (422) — é do serviço. A porta do cliente tem as rotas DELA
 * ({@see Cliente\AprovacaoDoClienteController}).
 */
class AprovacaoDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private AprovacaoDoClienteNaTrilha $aprovacao,
    ) {}

    /** `POST /trilha/{company}/ofertas/{item}/cliente` `{revisao}` — 200 com a prévia nova (§19.5) e a `aprovacao`. */
    public function mandar(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->aprovacao->mandar($empresa, $item, $request->all(), $request->user()));
    }
}
