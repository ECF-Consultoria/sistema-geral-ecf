<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Baixa as fotos de um anúncio do Mercado Livre para arquivos temporários (extraído do {@see ImportadorDeAnuncioMl} em
 * 03/11/2026, F8 da trilha, sem mudar o comportamento do Portal). SÓ BAIXA: quem guarda na galeria da variação
 * (`VariacaoImagensService::enviar`) é quem chama — o Portal guarda linha a linha logo depois de baixar; a trilha baixa
 * TUDO antes de abrir a transação e guarda depois do commit (nenhum HTTP dentro da trava, learnings §30).
 *
 * Um objeto por anúncio: cada foto é baixada UMA vez, mesmo repetida entre variações (a 2ª vez reusa o arquivo — ou a
 * falha). A foto vem em tamanho cheio (`url`); se ela não vier, a do anúncio (`url_reserva`, que pode ser pequena demais
 * para publicar). Foto da CDN, sem token. {@see limpar()} apaga os temporários — sempre num `finally`.
 */
final class FotosDoAnuncioMl
{
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const MAX_BYTES_FOTO = 10 * 1024 * 1024;

    /** @var array<string, array{0: string, 1: string}|false> id da foto => [arquivo temporário, mime] | false */
    private array $baixadas = [];

    /**
     * As fotos de UMA variação como arquivos de envio, na ordem do anúncio. A que não baixa fica de fora e conta em
     * `falhas` (por ocorrência: a mesma foto que falhou em duas variações conta duas vezes, como sempre contou).
     *
     * @param  list<array{id: string, url: string, url_reserva?: string}>  $fotos  `TradutorAnuncioMlParaPortal::traduzir()['fotos_por_linha'][i]`
     * @param  string  $prefixo  o começo do nome do arquivo (a Ref da variação)
     * @return array{arquivos: list<UploadedFile>, falhas: int}
     */
    public function arquivos(array $fotos, string $prefixo): array
    {
        $arquivos = [];
        $falhas = 0;
        foreach ($fotos as $n => $foto) {
            $baixada = $this->baixar($foto);
            if ($baixada === false) {
                $falhas++;

                continue;
            }
            [$caminho, $mime] = $baixada;
            $arquivos[] = new UploadedFile($caminho, $prefixo.'_'.($n + 1).'.'.self::MIMES[$mime], $mime, null, true);
        }

        return ['arquivos' => $arquivos, 'falhas' => $falhas];
    }

    /**
     * Uma foto, baixada uma vez por id: a de tamanho cheio; se ela não vier, a do anúncio.
     *
     * @param  array{id: string, url: string, url_reserva?: string}  $foto
     * @return array{0: string, 1: string}|false  [arquivo temporário, mime]
     */
    public function baixar(array $foto): array|false
    {
        return $this->baixadas[(string) $foto['id']] ??= $this->buscar($foto['url'])
            ?: (isset($foto['url_reserva']) && $foto['url_reserva'] !== $foto['url'] ? $this->buscar($foto['url_reserva']) : false);
    }

    /** Apaga os temporários baixados (e esquece-os). */
    public function limpar(): void
    {
        foreach ($this->baixadas as $b) {
            if (is_array($b)) {
                @unlink($b[0]);
            }
        }
        $this->baixadas = [];
    }

    /** @return array{0: string, 1: string}|false  [arquivo temporário, mime] */
    private function buscar(string $url): array|false
    {
        try {
            $r = Http::timeout(20)->retry(2, 500, throw: false)->get($url);
        } catch (\Throwable) {
            return false;
        }
        $corpo = $r->successful() ? $r->body() : '';
        if ($corpo === '' || strlen($corpo) > self::MAX_BYTES_FOTO) {
            return false;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($corpo);
        if (! isset(self::MIMES[$mime])) {
            return false;
        }
        $caminho = tempnam(sys_get_temp_dir(), 'mlfoto');
        if ($caminho === false || file_put_contents($caminho, $corpo) === false) {
            return false;
        }

        return [$caminho, $mime];
    }
}
