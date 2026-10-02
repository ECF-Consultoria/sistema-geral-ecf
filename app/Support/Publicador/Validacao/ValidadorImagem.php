<?php

namespace App\Support\Publicador\Validacao;

/**
 * L1 de UMA foto, pelos metadados do arquivo (`06` §7–8, [ML·S9]). Roda no
 * upload e de novo na validação do rascunho. O ML recusa abaixo de 500 px
 * (erro 3703) — melhor dizer antes de subir.
 */
final class ValidadorImagem
{
    private const FORMATOS = ['image/jpeg', 'image/jpg', 'image/png'];

    /**
     * @param  array{mime?: string, bytes?: int, largura?: int, altura?: int, cmyk?: bool}  $meta
     * @return list<Problema>
     */
    public static function problemas(string $id, array $meta, ContextoValidacao $ctx): array
    {
        $alvo = ['etapa' => 'E6', 'imagem' => $id];
        $p = [];

        if (! in_array(strtolower((string) ($meta['mime'] ?? '')), self::FORMATOS, true)) {
            $p[] = Problema::bloqueio('V-IMG-01', 'Use foto JPG ou PNG.', $alvo, 'L1');
        }
        if ((int) ($meta['bytes'] ?? 0) > $ctx->maxBytesImagem) {
            $p[] = Problema::bloqueio('V-IMG-02', 'A foto passa de 10 MB.', $alvo, 'L1');
        }

        $largura = (int) ($meta['largura'] ?? 0);
        $altura = (int) ($meta['altura'] ?? 0);
        if ($largura > 0 && $altura > 0) {
            if (min($largura, $altura) < $ctx->ladoMinimoImagem) {
                $p[] = Problema::bloqueio('V-IMG-03', "A foto tem {$largura}×{$altura} px; o mínimo é {$ctx->ladoMinimoImagem} px em cada lado.", $alvo, 'L1');
            }
            if (max($largura, $altura) < $ctx->ladoRecomendadoImagem) {
                $p[] = Problema::aviso('V-IMG-10', "Foto pequena para zoom: o recomendado é {$ctx->ladoRecomendadoImagem} px.", $alvo, 'L1');
            }
        }
        if (! empty($meta['cmyk'])) {
            $p[] = Problema::aviso('V-IMG-01', 'A foto está em CMYK: as cores podem sair diferentes. Prefira RGB.', $alvo, 'L1');
        }

        return $p;
    }
}
