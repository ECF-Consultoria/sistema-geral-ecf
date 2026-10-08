<?php

namespace App\Support\Publicador\Imagem;

/**
 * Converte a foto do Portal para o formato que o Publicador aceita (172, D-15).
 *
 * O Portal aceita WebP; o Publicador só recebe JPG/PNG (ValidadorImagem). JPG e PNG passam
 * intactos; outro formato que o GD lê vira JPG (qualidade 90, fundo branco no lugar da
 * transparência). A reconversão do GD também descarta qualquer payload escondido no arquivo.
 * Determinístico: a mesma entrada gera os mesmos bytes (o dedupe por sha256 continua valendo).
 */
final class ConversorParaJpg
{
    private const ERRO = 'Formato de foto não aceito (envie JPG ou PNG).';

    /** @return array{conteudo: ?string, convertida: bool, erro: ?string} */
    public static function converter(string $conteudo): array
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($conteudo);

        if ($mime === 'image/jpeg' || $mime === 'image/png') {
            return ['conteudo' => $conteudo, 'convertida' => false, 'erro' => null];
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return self::falha();
        }

        $origem = @imagecreatefromstring($conteudo);
        if ($origem === false) {
            return self::falha();
        }

        $largura = imagesx($origem);
        $altura = imagesy($origem);
        $destino = imagecreatetruecolor($largura, $altura);
        if ($destino === false) {
            imagedestroy($origem);

            return self::falha();
        }

        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopy($destino, $origem, 0, 0, 0, 0, $largura, $altura);

        ob_start();
        $ok = imagejpeg($destino, null, 90);
        $jpg = (string) ob_get_clean();

        imagedestroy($origem);
        imagedestroy($destino);

        if (! $ok || $jpg === '') {
            return self::falha();
        }

        return ['conteudo' => $jpg, 'convertida' => true, 'erro' => null];
    }

    /** @return array{conteudo: null, convertida: false, erro: string} */
    private static function falha(): array
    {
        return ['conteudo' => null, 'convertida' => false, 'erro' => self::ERRO];
    }
}
