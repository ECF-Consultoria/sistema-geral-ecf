<?php

namespace App\Support\Trilha;

use App\Models\PubImagem;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Symfony\Component\HttpFoundation\Response;

/**
 * A resposta HTTP de uma foto da prévia do anúncio — a MESMA nas duas portas da trilha: a da equipe (4a,
 * `trilha.previa.foto`) e a do cliente (4e, `portal.trilha.aprovacao.foto`). Extraída do controller da 4a sem mudar o
 * comportamento: quem decide QUAL foto pode sair (a do rascunho do item, da empresa) é o serviço de cada porta.
 *
 * A cópia guardada pelo único caminho das fotos do Publicador (`ArquivosDeImagem`); a foto antiga, que só tem o endereço
 * público do ML (`caminho` nulo, veio do Anunciar antigo), vai por redirecionamento — sempre em https. Cache `private`
 * (nunca em cache compartilhado) e `nosniff`, como a miniatura do editor.
 */
final class FotoDaPrevia
{
    public static function resposta(PubImagem $img): Response
    {
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
    public static function emHttps(mixed $url): ?string
    {
        if (! is_string($url) || ! preg_match('#^https?://#i', trim($url))) {
            return null;
        }

        return (string) preg_replace('#^http://#i', 'https://', trim($url));
    }
}
