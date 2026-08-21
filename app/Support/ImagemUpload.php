<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ImagemUpload — redimensiona e guarda imagem enviada pelo usuário no disco
 * público, devolvendo o caminho salvo.
 *
 * Extraído de `UserController::salvarAvatarRedimensionado()` quando o Portal
 * do Cliente passou a precisar da MESMA rotina para a logo da empresa. É a
 * mesma lógica, palavra por palavra — copiá-la seria garantir que as duas
 * divergissem no dia em que uma ganhasse suporte a AVIF ou um limite de peso
 * diferente. `UserController` delega para cá desde então.
 *
 * A proporção NUNCA é alterada: o resize reduz o maior lado até `$maxDim` e
 * escala o outro junto (`min(1, ...)` também impede ampliar imagem pequena).
 * Isso é acidental para avatar — que a tela recorta em círculo — e essencial
 * para logo, que pode ser horizontal, quadrada ou vertical e não pode
 * esticar.
 *
 * Transparência é preservada em WebP/PNG. Sem `imagewebp()` no PHP o fallback
 * é JPEG, e aí a transparência vira BRANCO — decisão consciente e visível: no
 * Portal a logo é exibida sobre um "papel" claro justamente para que esse
 * fallback não apareça como um bloco branco solto no meio do menu escuro.
 */
class ImagemUpload
{
    /**
     * Redimensiona para no máximo `$maxDim` px no maior lado (só reduz),
     * re-encoda em WebP (ou JPEG, se o GD não tiver WebP) e guarda em
     * `$pasta` no disco `public`.
     *
     * Formato exótico ou arquivo corrompido que o GD não abre é guardado como
     * veio — perder o upload do usuário é pior do que guardar um arquivo maior
     * do que o ideal.
     *
     * @param  string  $pasta     pasta no disco público, ex: 'logos'
     * @param  string  $prefixo   prefixo do nome do arquivo, ex: "12" (id)
     * @return string  caminho relativo salvo, ex: 'logos/12_66c3f1e2.webp'
     */
    public static function salvarRedimensionada(
        UploadedFile $file,
        string $pasta,
        string $prefixo,
        int $maxDim = 512,
    ): string {
        $origem = $file->getRealPath();
        $mime   = $file->getMimeType();

        $src = match (true) {
            $mime === 'image/jpeg' && function_exists('imagecreatefromjpeg') => @imagecreatefromjpeg($origem),
            $mime === 'image/png'  && function_exists('imagecreatefrompng')  => @imagecreatefrompng($origem),
            $mime === 'image/webp' && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($origem),
            default => null,
        };

        if (! $src) {
            return $file->store($pasta, 'public');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $escala = min(1, $maxDim / max($w, $h)); // nunca amplia
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);   // preserva transparência (PNG/WebP)
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if (function_exists('imagewebp')) {
            $ext = 'webp';
            ob_start();
            imagewebp($dst, null, 82);
            $bin = ob_get_clean();
        } else {
            // Sem WebP: achata a transparência em branco e salva JPEG.
            $fundo  = imagecreatetruecolor($nw, $nh);
            $branco = imagecolorallocate($fundo, 255, 255, 255);
            imagefilledrectangle($fundo, 0, 0, $nw, $nh, $branco);
            imagecopy($fundo, $dst, 0, 0, 0, 0, $nw, $nh);
            $ext = 'jpg';
            ob_start();
            imagejpeg($fundo, null, 85);
            $bin = ob_get_clean();
            imagedestroy($fundo);
        }

        imagedestroy($src);
        imagedestroy($dst);

        $path = $pasta . '/' . $prefixo . '_' . uniqid() . '.' . $ext;
        Storage::disk('public')->put($path, $bin);

        return $path;
    }

    /**
     * Apaga o arquivo físico quando a URL aponta para um upload local
     * (`/storage/...`). URL externa é deixada em paz — não é nossa para
     * apagar.
     */
    public static function apagarSeLocal(?string $url): void
    {
        $url = (string) $url;

        if (str_starts_with($url, '/storage/')) {
            Storage::disk('public')->delete(substr($url, strlen('/storage/')));
        }
    }
}
