<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PreviaNaTrilha;
use App\Support\Trilha\FotoDaPrevia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A prévia do anúncio na porta da EQUIPE da trilha (4ª entrega, fatia 4a, desenho §19.4): o JSON do painel `v:{item}` e
 * as fotos dele. Fino: a regra mora em {@see PreviaNaTrilha}. Só leitura — nenhuma das duas rotas grava, chama a IA ou
 * marca o editor do Publicador como em uso.
 *
 * A guarda é a do cadastro ({@see CadastroNaTrilha::empresa()}): as duas chaves (404, antes de ler a empresa) e a régua
 * de escrita do Portal da empresa (403) — a prévia é trabalho de quem revisa; depois, o item do ENDEREÇO (404: o de
 * outra loja não existe na régua desta). Nenhuma validação de corpo (as duas são GET).
 */
class PreviaDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private PreviaNaTrilha $previa,
    ) {}

    /** `GET /trilha/{company}/ofertas/{item}/previa` — o contrato da §19.5. Item fora da revisão: 200 com `pronta: false`. */
    public function previa(Request $request, int $company, string $item): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->previa->montar($empresa, $item));
    }

    /**
     * `GET /trilha/{company}/ofertas/{item}/fotos/{imagem}` — o arquivo da foto do rascunho do item (o de outro rascunho
     * é 404). A resposta é a de {@see FotoDaPrevia::resposta()} (a mesma da porta do cliente na 4e): a cópia guardada
     * pelo único caminho das fotos do Publicador; a foto antiga, só com o endereço público do ML, por redirecionamento
     * em https. Cache `private`, como a miniatura do editor.
     *
     * R3 (04/11/2026): `?miniatura=1` serve a versão pequena (a grade da galeria); o `If-None-Match` com o ETag da foto
     * recebe 304 — sempre DEPOIS da guarda e da régua do item.
     */
    public function foto(Request $request, int $company, string $item, int $imagem): Response
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return FotoDaPrevia::resposta($this->previa->imagem($empresa, $item, $imagem), $request->boolean('miniatura'), $request);
    }
}
