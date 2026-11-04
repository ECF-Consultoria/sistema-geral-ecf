<?php

namespace App\Http\Controllers\Trilha\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Trilha\AprovacaoDoClienteNaTrilha;
use App\Services\Trilha\CadastroNaTrilha;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\PortalContexto;
use App\Support\Trilha\AprovacaoDoCliente;
use App\Support\Trilha\FotoDaPrevia;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A aprovação do anúncio na porta do CLIENTE da trilha (4ª entrega, fatia 4e, 04/11/2026 — desenho §19.16, D57): a
 * prévia que a equipe mandou, as fotos dela, [Aprovar anúncio] e [Pedir ajuste]. Fino: lê o `PortalContexto` (a empresa
 * que o `portal.auth` conferiu e quem está ali) e chama o MESMO serviço da equipe ({@see AprovacaoDoClienteNaTrilha}) com
 * `Company` + `AtorDoPortal`. Quando a trilha for para o outro sistema, só esta camada troca (D57).
 *
 * ### A guarda, nesta ordem
 * 1. `portal.auth` (a rota): o cliente autenticado com vínculo com a empresa do endereço — ou alguém da ECF que entrou
 *    nela pela porta da equipe (o modo equipe do Portal);
 * 2. as duas chaves da trilha ({@see CadastroNaTrilha::empresaDoCliente()}) E a da aprovação ({@see AprovacaoDoCliente}):
 *    404 sem elas, como se a rota não existisse;
 * 3. o item do endereço (404, o de outra loja não existe na régua desta) e o envio a ele (404: o cliente só vê o que a
 *    equipe mandou) — antes do corpo;
 * 4. 409 (não está com ele; já seguiu para a publicação; D55; a aba velha; o robô refazendo agora) → 422 (`revisao`,
 *    `texto`), sempre em JSON e com a `message` = o 1º erro.
 *
 * ### O modo equipe do Portal APROVA pelo cliente (diferente da 4f)
 * Nas fotos (4f) o modo equipe dava 404: não havia `PortalUsuario` para ser o ator. Aqui o desenho (§19.1) pede que a ECF
 * possa aprovar (ou pedir ajuste) em nome do cliente, e a tabela tem onde guardar: `decidido_por_equipe`. O ator é o de
 * `PortalContexto::ator()`, que dá PREFERÊNCIA ao membro da equipe — uma sessão com as duas marcas (a do cliente "por
 * baixo") grava como equipe, nunca em nome do cliente o que a ECF fez.
 *
 * O CSRF vale como em toda rota do `web` (o grupo `portal/*` não está nas exceções), e cada rota tem linha própria na
 * allowlist do domínio do cliente (`RestringeDominioDoPortal::PERMITIDO_COM_ID`, com o marcador `{item}`).
 */
class AprovacaoDoClienteController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private AprovacaoDoClienteNaTrilha $aprovacao,
    ) {}

    /** `GET portal/{pe}/trilha/ofertas/{item}/previa` — a prévia na versão do cliente (§19.16). */
    public function previa(string $item): JsonResponse
    {
        [$empresa] = $this->naPorta();

        return response()->json($this->aprovacao->previaDoCliente($empresa, $item, self::urlDaFoto($empresa, $item)));
    }

    /** `GET portal/{pe}/trilha/ofertas/{item}/fotos/{imagem}` — a foto do rascunho do item mandado a ele (a resposta da 4a). */
    public function foto(string $item, int $imagem): Response
    {
        [$empresa] = $this->naPorta();

        return FotoDaPrevia::resposta($this->aprovacao->imagemDoCliente($empresa, $item, $imagem));
    }

    /** `POST portal/{pe}/trilha/ofertas/{item}/aprovar` `{revisao}` — 200 `{aprovacao, publicacao}`. */
    public function aprovar(Request $request, string $item): JsonResponse
    {
        [$empresa, $ator] = $this->naPorta();

        try {
            return response()->json($this->aprovacao->aprovar($empresa, $item, $request->all(), $ator));
        } catch (ValidationException $e) {
            return self::recusa($e);
        }
    }

    /** `POST portal/{pe}/trilha/ofertas/{item}/ajuste` `{revisao, texto}` — 200 `{aprovacao}`. */
    public function ajuste(Request $request, string $item): JsonResponse
    {
        [$empresa, $ator] = $this->naPorta();

        try {
            return response()->json($this->aprovacao->pedirAjuste($empresa, $item, $request->all(), $ator));
        } catch (ValidationException $e) {
            return self::recusa($e);
        }
    }

    /**
     * A empresa conferida pelo `portal.auth` e quem está nela (o cliente, ou a ECF no modo equipe) — só com as duas chaves
     * da trilha e a da aprovação (404).
     *
     * @return array{0: Company, 1: AtorDoPortal}
     */
    private function naPorta(): array
    {
        $ator = PortalContexto::ator();
        $empresa = $this->cadastro->empresaDoCliente(PortalContexto::empresa());
        abort_unless(AprovacaoDoCliente::ligada($empresa), 404);

        return [$empresa, $ator];
    }

    /** A URL da foto nesta porta — relativa, com a empresa da aba (`/portal/{pe}/trilha/ofertas/{item}/fotos/{imagem}`). */
    private static function urlDaFoto(Company $empresa, string $item): Closure
    {
        return fn (int $imagem): string => route('portal.trilha.aprovacao.foto', [
            PortalContexto::PARAMETRO => (int) $empresa->id,
            'item'                    => $item,
            'imagem'                  => $imagem,
        ], false);
    }

    /** O 422 sempre em JSON, no formato de erro do Laravel (`errors`), com a `message` = o 1º erro (a regra da 4f). */
    private static function recusa(ValidationException $e): JsonResponse
    {
        $erros = $e->errors();
        $primeiro = (string) (array_values($erros)[0][0] ?? 'Confira e tente de novo.');

        return response()->json(['message' => $primeiro, 'errors' => $erros], 422);
    }
}
