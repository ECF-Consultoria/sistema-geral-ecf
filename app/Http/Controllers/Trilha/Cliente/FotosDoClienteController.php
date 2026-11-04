<?php

namespace App\Http\Controllers\Trilha\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\PortalUsuario;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\FotosDoClienteNaTrilha;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\Estrutura\RegrasDoCadastro;
use App\Support\Portal\PortalContexto;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * As fotos do produto na porta do CLIENTE da trilha (4ª entrega, fatia 4f, 04/11/2026 — desenho §19.15, D57/D58): a
 * 1ª ESCRITA do cliente (R9). Fino: lê o `PortalContexto` (a empresa que o `portal.auth` conferiu e o `PortalUsuario`) e
 * chama os MESMOS serviços da equipe com `Company` + `AtorDoPortal::cliente` ({@see CadastroNaTrilha},
 * {@see FotosDoClienteNaTrilha}). Quando a trilha for para o outro sistema, só esta camada troca (D57).
 *
 * ### A guarda, nesta ordem (§19.15)
 * 1. `portal.auth` (a rota): cliente autenticado e com vínculo com a empresa do endereço;
 * 2. o cliente DE VERDADE: a sessão de equipe dentro do Portal (passagem ou link aberto) recebe 404 — não há
 *    `PortalUsuario` para ser o ator, e a equipe tem a gaveta dela na porta da equipe (a página do cliente também não
 *    lhe passa `cliente_escrita`);
 * 3. 404 sem as duas chaves ({@see CadastroNaTrilha::empresaDoCliente()});
 * 4. 404 produto/variação/imagem de outra empresa (ou de outro produto/variação do endereço) ou inexistente — ANTES de
 *    olhar o corpo;
 * 5. 409 `{message}` no produto que andou ou saiu da Lista ({@see FotosDoClienteNaTrilha::exigirEditavel()});
 * 6. 422: o envio que o PHP descartou e as regras de imagem do Portal (`RegrasDoCadastro`), e o teto/tudo-ou-nada do
 *    serviço. Sempre em JSON e com a `message` = o 1º erro (sem o "(and N more errors)" do Laravel, em inglês). O 413 do
 *    `post_max_size` não chega aqui: o `bootstrap/app.php` o traduz para o mesmo 422 `errors.imagens`.
 *
 * O CSRF vale como em toda rota do `web` (o grupo `portal/*` não está nas exceções), e cada rota tem linha própria na
 * allowlist do domínio do cliente (`RestringeDominioDoPortal::PERMITIDO_COM_ID`).
 */
class FotosDoClienteController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private FotosDoClienteNaTrilha $fotos,
        private VariacaoImagensService $imagens,
    ) {}

    /** `GET portal/{pe}/trilha/produtos/{produto}/fotos` — o quadro de fotos do produto (§19.15). */
    public function quadro(int $produto): JsonResponse
    {
        [$empresa] = $this->doCliente();
        $alvo = $this->cadastro->produto($empresa, $produto);

        return response()->json($this->fotos->quadro($empresa, $alvo, self::urlDaFoto($empresa)));
    }

    /**
     * `GET portal/{pe}/trilha/fotos/{variacao}/{imagem}` — o arquivo da foto, só da empresa do endereço. Sem parâmetro,
     * a miniatura (uma semana de cache: o id da imagem nunca troca de conteúdo); `?original=1`, o arquivo enviado (uma
     * hora). Sempre `private` e `nosniff` — a mesma resposta da rota da equipe.
     */
    public function ver(Request $request, int $variacao, int $imagem): Response
    {
        [$empresa] = $this->doCliente();

        $img = EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->where('variacao_id', $variacao)
            ->whereKey($imagem)
            ->firstOrFail();
        abort_unless($this->imagens->existe($img), 404);

        if (! $request->boolean('original') && ($miniatura = $this->imagens->miniatura($img)) !== null) {
            return Storage::disk(VariacaoImagensService::DISCO)->response($miniatura, null, [
                'Content-Type'           => 'image/jpeg',
                'Cache-Control'          => 'private, max-age=604800',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Storage::disk(VariacaoImagensService::DISCO)->response($img->caminho, null, [
            'Content-Type'           => $img->mime,
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * `POST portal/{pe}/trilha/produtos/{produto}/variacoes/{variacao}/fotos` (multipart `imagens[]`) — envia para o
     * fim da galeria da variação. 200 com o quadro novo e `avisos`.
     */
    public function enviar(Request $request, int $produto, int $variacao): JsonResponse
    {
        [$empresa, $usuario] = $this->doCliente();
        $alvo = $this->cadastro->produto($empresa, $produto);
        $v = $this->cadastro->variacao($empresa, $alvo, $variacao);
        $this->fotos->exigirEditavel($empresa, $alvo);

        try {
            RegrasDoCadastro::recusarEnvioQueNaoChegou($request);
            $request->validate(RegrasDoCadastro::imagens(), RegrasDoCadastro::mensagensDasImagens());

            return response()->json($this->fotos->enviar($empresa, $alvo, $v, array_values((array) $request->file('imagens')),
                AtorDoPortal::cliente($usuario), self::urlDaFoto($empresa)));
        } catch (ValidationException $e) {
            return self::recusa($e);
        }
    }

    /**
     * `DELETE portal/{pe}/trilha/produtos/{produto}/variacoes/{variacao}/fotos/{imagem}` — exclui a foto (as que sobram
     * são renumeradas; a 1ª vira a capa). 200 com o quadro novo.
     */
    public function excluir(int $produto, int $variacao, int $imagem): JsonResponse
    {
        [$empresa, $usuario] = $this->doCliente();
        $alvo = $this->cadastro->produto($empresa, $produto);
        $v = $this->cadastro->variacao($empresa, $alvo, $variacao);
        $img = $this->cadastro->imagem($empresa, $v, $imagem);
        $this->fotos->exigirEditavel($empresa, $alvo);

        return response()->json($this->fotos->excluir($empresa, $alvo, $v, $img, AtorDoPortal::cliente($usuario), self::urlDaFoto($empresa)));
    }

    /**
     * A empresa conferida pelo `portal.auth` e o cliente que está nela — só o cliente DE VERDADE (a sessão de equipe do
     * Portal é 404) e só com as duas chaves (404).
     *
     * @return array{0: Company, 1: PortalUsuario}
     */
    private function doCliente(): array
    {
        $usuario = PortalContexto::usuario();
        abort_if(PortalContexto::ator()->equipe || $usuario === null, 404);

        return [$this->cadastro->empresaDoCliente(PortalContexto::empresa()), $usuario];
    }

    /** A URL da foto nesta porta — relativa, com a empresa da aba (§19.15: `/portal/{pe}/trilha/fotos/{v}/{i}`). */
    private static function urlDaFoto(Company $empresa): Closure
    {
        return fn (int $variacao, int $imagem): string => route('portal.trilha.fotos.ver', [
            PortalContexto::PARAMETRO => (int) $empresa->id,
            'variacao'                => $variacao,
            'imagem'                  => $imagem,
        ], false);
    }

    /** O 422 sempre em JSON, no formato de erro do Laravel (`errors`), com a `message` = o 1º erro. */
    private static function recusa(ValidationException $e): JsonResponse
    {
        $erros = $e->errors();
        $primeiro = (string) (array_values($erros)[0][0] ?? 'Confira as fotos e tente de novo.');

        return response()->json(['message' => $primeiro, 'errors' => $erros], 422);
    }
}
