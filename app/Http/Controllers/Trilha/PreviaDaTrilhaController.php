<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PreviaNaTrilha;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
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
     * é 404). A cópia guardada pelo único caminho das fotos do Publicador (`ArquivosDeImagem`); a foto antiga, que só
     * tem o endereço público do ML (`caminho` nulo, veio do Anunciar antigo), vai por redirecionamento. Cache `private`
     * (nunca em cache compartilhado), como a miniatura do editor.
     */
    public function foto(Request $request, int $company, string $item, int $imagem): Response
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);
        $img = $this->previa->imagem($empresa, $item, $imagem);

        $cabecalhos = ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'];
        if (is_string($img->mime) && str_starts_with($img->mime, 'image/')) {
            $cabecalhos['Content-Type'] = $img->mime;
        }
        $resposta = $img->caminho !== null ? ArquivosDeImagem::resposta($img->caminho, $cabecalhos) : null;
        if ($resposta !== null) {
            return $resposta;
        }
        if (($publica = self::emHttps($img->ml_url)) !== null) {
            return redirect()->away($publica)->withHeaders(['Cache-Control' => 'private, max-age=300']);
        }

        abort(404);
    }

    /**
     * O endereço público da foto SEMPRE em https: o Anunciar antigo pode ter guardado `http://`, e a página é https — o
     * navegador bloquearia a imagem (conteúdo misto). A mesma regra do `emHttps` da tela da F8 (`lib/trilhaImportarMl.js`)
     * e do `TradutorAnuncioMlParaPortal::url`. Outro esquema (ou vazio): nenhum endereço.
     */
    private static function emHttps(mixed $url): ?string
    {
        if (! is_string($url) || ! preg_match('#^https?://#i', trim($url))) {
            return null;
        }

        return (string) preg_replace('#^http://#i', 'https://', trim($url));
    }
}
