<?php

namespace Tests\Feature\Phase171;

use App\Models\MlAnuncioCriativo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 171, Plano 01, Task 2 — ACERVO-04: `creative:limpar-referencias`
 * (varredura diária de 48h) continua restrito às fotos de REFERÊNCIA
 * (`referencias`/`referencias_apagadas_em`) e NUNCA toca a imagem GERADA
 * (`imagem_path`/`imagem_mime`/`imagem_bytes`) — o acervo é sobre tornar
 * navegável o que já não se apaga, não sobre decidir guardar. Prova por
 * teste, não por leitura de código.
 */
class AcervoNuncaApagaPelaLimpezaDeReferenciasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private static function jpegBytes(int $lado = 600): string
    {
        $img = imagecreatetruecolor($lado, $lado);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** Criativo com imagem GERADA em disco + referência ANTIGA (72h, acima da janela de 48h). */
    private function criativoComImagemGeradaEReferenciaAntiga(): MlAnuncioCriativo
    {
        $token = Str::random(32);
        $disco = Storage::disk('local');

        $caminhoGerado = "creative-geradas/{$token}/1.jpg";
        $disco->put($caminhoGerado, self::jpegBytes());

        $caminhoReferencia = "creative-referencias/{$token}/0.jpg";
        $disco->put($caminhoReferencia, self::jpegBytes());

        $criativo = MlAnuncioCriativo::create([
            'token' => $token,
            'slot' => 'hero',
            'slot_indice' => 1,
            'status' => MlAnuncioCriativo::STATUS_APROVADO,
            'imagem_path' => $caminhoGerado,
            'imagem_mime' => 'image/jpeg',
            'imagem_bytes' => strlen($disco->get($caminhoGerado)),
            'referencias' => [[
                'indice' => 0, 'path' => $caminhoReferencia, 'mime' => 'image/jpeg',
                'bytes' => strlen($disco->get($caminhoReferencia)), 'nome' => 'ref.jpg', 'hash' => 'x',
            ]],
            'referencias_apagadas_em' => null,
        ]);

        // 72h atrás — acima da janela padrão de 48h (services.creative.retencao_referencias_horas).
        MlAnuncioCriativo::whereKey($criativo->id)->update(['created_at' => now()->subHours(72)]);

        return $criativo->fresh();
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $criativo = $this->criativoComImagemGeradaEReferenciaAntiga();
        $imagemPathAntes = $criativo->imagem_path;
        $imagemMimeAntes = $criativo->imagem_mime;
        $imagemBytesAntes = $criativo->imagem_bytes;

        Artisan::call('creative:limpar-referencias', ['--dry-run' => true]);

        $criativo->refresh();
        $this->assertSame($imagemPathAntes, $criativo->imagem_path);
        $this->assertSame($imagemMimeAntes, $criativo->imagem_mime);
        $this->assertSame($imagemBytesAntes, $criativo->imagem_bytes);
        $this->assertNull($criativo->referencias_apagadas_em);
        $this->assertTrue(Storage::disk('local')->exists($imagemPathAntes));
        $this->assertTrue(Storage::disk('local')->exists("creative-referencias/{$criativo->token}"));
    }

    public function test_execucao_real_apaga_so_a_referencia_nunca_a_imagem_gerada(): void
    {
        $criativo = $this->criativoComImagemGeradaEReferenciaAntiga();
        $imagemPathAntes = $criativo->imagem_path;
        $imagemMimeAntes = $criativo->imagem_mime;
        $imagemBytesAntes = $criativo->imagem_bytes;

        Artisan::call('creative:limpar-referencias');

        $criativo->refresh();
        $this->assertSame($imagemPathAntes, $criativo->imagem_path);
        $this->assertSame($imagemMimeAntes, $criativo->imagem_mime);
        $this->assertSame($imagemBytesAntes, $criativo->imagem_bytes);
        $this->assertNotNull($criativo->referencias_apagadas_em);

        // A imagem GERADA continua em disco — só a varredura de REFERÊNCIA foi executada.
        $this->assertTrue(Storage::disk('local')->exists($imagemPathAntes));
        $this->assertFalse(Storage::disk('local')->exists("creative-referencias/{$criativo->token}"));
    }
}
