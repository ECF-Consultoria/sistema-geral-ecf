<?php

namespace Tests\Unit\Publicador\Imagem;

use App\Support\Publicador\Imagem\ConversorParaJpg;
use PHPUnit\Framework\TestCase;

/** 172 D-15 — WebP vira JPG; JPG e PNG passam intactos. */
class ConversorParaJpgTest extends TestCase
{
    private static function webp(bool $transparente = false): string
    {
        $img = imagecreatetruecolor(800, 800);
        if ($transparente) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        } else {
            imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        }
        ob_start();
        imagewebp($img);

        return (string) ob_get_clean();
    }

    private static function png(): string
    {
        $img = imagecreatetruecolor(600, 600);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_webp_vira_jpg_e_e_deterministico(): void
    {
        $a = ConversorParaJpg::converter(self::webp());
        $b = ConversorParaJpg::converter(self::webp());

        $this->assertTrue($a['convertida']);
        $this->assertNull($a['erro']);
        $this->assertSame("\xFF\xD8", substr($a['conteudo'], 0, 2));
        $this->assertSame($a['conteudo'], $b['conteudo']);
        $this->assertSame([800, 800], array_slice(getimagesizefromstring($a['conteudo']), 0, 2));
    }

    public function test_jpg_e_png_passam_intactos(): void
    {
        $png = self::png();
        $r = ConversorParaJpg::converter($png);
        $this->assertFalse($r['convertida']);
        $this->assertSame($png, $r['conteudo']);

        $img = imagecreatetruecolor(600, 600);
        ob_start();
        imagejpeg($img);
        $jpg = (string) ob_get_clean();
        $r = ConversorParaJpg::converter($jpg);
        $this->assertFalse($r['convertida']);
        $this->assertSame($jpg, $r['conteudo']);
    }

    public function test_bytes_que_nao_sao_imagem_dao_erro(): void
    {
        $r = ConversorParaJpg::converter('isto nao e uma foto');

        $this->assertNull($r['conteudo']);
        $this->assertFalse($r['convertida']);
        $this->assertStringContainsString('JPG ou PNG', $r['erro']);
    }

    public function test_webp_transparente_ganha_fundo_branco(): void
    {
        $r = ConversorParaJpg::converter(self::webp(true));

        $img = imagecreatefromstring($r['conteudo']);
        $rgb = imagecolorat($img, 10, 10);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
        $this->assertGreaterThan(240, ($rgb >> 8) & 0xFF);
        $this->assertGreaterThan(240, $rgb & 0xFF);
    }
}
