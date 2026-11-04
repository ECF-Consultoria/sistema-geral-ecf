<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PreparoNaTrilha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O [Tentar de novo] da "Preparação automática" na porta da EQUIPE da trilha (retorno do 1º teste, R4, 04/11/2026). Fino:
 * a regra mora em {@see PreparoNaTrilha}.
 *
 * A guarda é a do cadastro ({@see CadastroNaTrilha::empresa()}): as duas chaves (404, antes de ler a empresa) e a escrita
 * no Portal da empresa (403); depois, o item do endereço (404) e o 409 do serviço. Sem corpo: nada da requisição segue
 * adiante. A porta do cliente não tem esta rota (nem recebe o endereço).
 */
class PreparoDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private PreparoNaTrilha $preparo,
    ) {}

    /** `POST /trilha/{company}/ofertas/{item}/preparo` — 202 `{preparo: {situacao: 'fila', mensagem, etapas}}`. */
    public function tentarDeNovo(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->preparo->pedir($empresa, $item, $request->user()), 202);
    }
}
