<?php

namespace App\Support\Trilha;

use App\Models\AcervoImagem;
use App\Models\PubImagem;
use App\Services\Publicador\AcervoDeImagensService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A resposta HTTP de uma foto da prévia do anúncio — a MESMA nas duas portas da trilha: a da equipe (4a,
 * `trilha.previa.foto`) e a do cliente (4e, `portal.trilha.aprovacao.foto`). Extraída do controller da 4a sem mudar o
 * comportamento: quem decide QUAL foto pode sair (a do rascunho do item, da empresa) é o serviço de cada porta.
 *
 * A cópia guardada pelo único caminho das fotos do Publicador (`ArquivosDeImagem`); a foto antiga, que só tem o endereço
 * público do ML (`caminho` nulo, veio do Anunciar antigo), vai por redirecionamento — sempre em https. Cache `private`
 * (nunca em cache compartilhado) e `nosniff`, como a miniatura do editor.
 *
 * ### R3 — a prévia que demorava (retorno do 1º teste, 04/11/2026)
 * - **Cache do navegador:** a foto de uma linha de `pub_imagens` não muda (trocar a foto cria outra linha), então ela
 *   leva `ETag` (o id e o sha256 dela) e `Last-Modified`, e fica `private` por {@see MAX_AGE} s (a miniatura, por
 *   {@see MAX_AGE_MINIATURA} s — os mesmos prazos da foto do cadastro, `FotosDaTrilhaController::ver`). O pedido que
 *   chega com o `If-None-Match` certo recebe 304 SEM abrir o disco (no R2 da produção, cada `exists` é uma ida à rede).
 *   O 304 só sai DEPOIS da guarda: quem chama já achou a foto pela régua do item da empresa.
 * - **Miniatura** (`$miniatura`, a grade da galeria e a bolinha da cor): a do ACERVO do Publicador
 *   (`AcervoDeImagensService::miniatura`, WebP de 360 px, gerada na 1ª vez e guardada — o arquivo e
 *   `acervo_imagens.miniatura_caminho`, a mesma escrita que a tela do acervo já faz), só quando o ativo é o MESMO arquivo
 *   da foto (sha256 igual). Sem ativo, sem GD ou sem miniatura: a foto inteira. A foto antiga só no ML: a variação
 *   pequena do CDN de lá (`ProgramasPublicadorService::miniaturaDaFoto`).
 */
final class FotoDaPrevia
{
    /** A foto inteira: 1 hora no navegador (e o ETag revalida sem baixar de novo). */
    public const MAX_AGE = 3600;

    /** A miniatura: 7 dias (o arquivo dela é endereçado pelo sha256 — não muda). */
    public const MAX_AGE_MINIATURA = 604800;

    /** O redirecionamento para o endereço público (a foto antiga): como antes. */
    private const MAX_AGE_REDIRECIONAMENTO = 300;

    public static function resposta(PubImagem $img, bool $miniatura = false, ?Request $pedido = null): Response
    {
        $etag = self::etag($img, $miniatura);
        $cache = 'private, max-age='.($miniatura ? self::MAX_AGE_MINIATURA : self::MAX_AGE);
        if ($etag !== null && $pedido !== null && in_array($etag, $pedido->getETags(), true)) {
            return response('', 304)->withHeaders(['ETag' => $etag, 'Cache-Control' => $cache]);
        }

        $cabecalhos = ['Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff'];
        if ($etag !== null) {
            $cabecalhos['ETag'] = $etag;
        }
        if ($img->created_at !== null) {
            $cabecalhos['Last-Modified'] = $img->created_at->copy()->setTimezone('UTC')->format('D, d M Y H:i:s').' GMT';
        }

        if ($miniatura && ($pequena = self::miniatura($img)) !== null) {
            $resposta = ArquivosDeImagem::resposta($pequena, [...$cabecalhos, 'Content-Type' => 'image/webp']);
            if ($resposta !== null) {
                return $resposta;
            }
        }

        if (is_string($img->mime) && str_starts_with($img->mime, 'image/')) {
            $cabecalhos['Content-Type'] = $img->mime;
        }
        $resposta = $img->caminho !== null ? ArquivosDeImagem::resposta($img->caminho, $cabecalhos) : null;
        if ($resposta !== null) {
            return $resposta;
        }
        if (($publica = self::emHttps($img->ml_url)) !== null) {
            $destino = $miniatura ? (ProgramasPublicadorService::miniaturaDaFoto($publica) ?? $publica) : $publica;

            return redirect()->away($destino)->withHeaders(['Cache-Control' => 'private, max-age='.self::MAX_AGE_REDIRECIONAMENTO]);
        }

        abort(404);
    }

    /**
     * O endereço público da foto SEMPRE em https: o Anunciar antigo pode ter guardado `http://`, e a página é https — o
     * navegador bloquearia a imagem (conteúdo misto). A mesma regra do `emHttps` da tela da F8 (`lib/trilhaImportarMl.js`)
     * e do `TradutorAnuncioMlParaPortal::url`. Outro esquema (ou vazio): nenhum endereço.
     */
    public static function emHttps(mixed $url): ?string
    {
        if (! is_string($url) || ! preg_match('#^https?://#i', trim($url))) {
            return null;
        }

        return (string) preg_replace('#^http://#i', 'https://', trim($url));
    }

    /**
     * O ETag da foto (forte, entre aspas): o id e o sha256 da linha — o conteúdo não muda sem mudar um dos dois. A
     * miniatura tem o seu. Sem caminho (a foto antiga, que vai por redirecionamento): nenhum.
     */
    private static function etag(PubImagem $img, bool $miniatura): ?string
    {
        if ($img->caminho === null) {
            return null;
        }
        $sha = substr((string) $img->sha256, 0, 16);

        return '"pi-'.$img->id.'-'.($sha !== '' ? $sha : 'x').($miniatura ? '-m' : '').'"';
    }

    /** O caminho da miniatura do ativo do acervo que É esta foto (mesmo sha256); null = servir a foto inteira. */
    private static function miniatura(PubImagem $img): ?string
    {
        if ($img->caminho === null || $img->acervo_imagem_id === null) {
            return null;
        }
        $ativo = AcervoImagem::query()->find($img->acervo_imagem_id);
        if ($ativo === null || (string) $ativo->sha256 === '' || (string) $ativo->sha256 !== (string) $img->sha256) {
            return null;
        }
        try {
            return app(AcervoDeImagensService::class)->miniatura($ativo);
        } catch (\Throwable) {
            // Disco fora do ar ou imagem que o GD não lê: a foto inteira serve.
            return null;
        }
    }
}
