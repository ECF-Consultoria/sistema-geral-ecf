<?php

namespace App\Support\Publicador\Payload;

/**
 * A capa de cada alvo (D6, CAPA-01..04; refeita em 22/10/2026). Publicando o mesmo produto como Clássico e Premium, a
 * sequência de fotos é a MESMA nos dois; só a 1ª (a capa) muda no Premium — e nunca para uma foto de fundo branco nem de
 * detalhe. O usuário: "a sequência das imagens do Premium é diferente do Clássico; a foto principal do Premium pode ser
 * diferente, porém não uma com fundo branco — só a foto 1 principal".
 *
 * Antes (Fase 168) o Premium GIRAVA a lista inteira para a esquerda: a capa ia para o fim e todas as outras subiam uma
 * posição. Na galeria automática de móvel [ambientada, fundo branco, textura] o Premium abria com o fundo branco; com 2
 * fotos numa cor [ambientada, textura], com a textura.
 *
 * Regra: o alvo 0 (o Clássico) fica como está. O alvo N troca a 1ª foto com a N-ésima CAPA SEM FUNDO BRANCO da lista
 * (fora a 1ª), pelo tipo do criativo que gerou a foto: a preferida é a 2ª ambientada que o kit automático gera para o
 * Premium (`composicao`), depois as outras ambientadas, na ordem da galeria. É uma TROCA de posição — a capa do Clássico
 * vai para o lugar dela e o resto não se mexe. Sem candidata (foto do cliente, cujo fundo não se sabe; ou uma ambientada
 * só, que já é a capa), o Premium fica igual ao Clássico.
 *
 * Nunca duplica nem descarta foto (CAPA-02); determinística, função pura — sem relógio, banco nem estado (CAPA-04).
 */
final class OrdemCapaPorAlvo
{
    /** Os tipos de criativo que podem abrir o anúncio do Premium, em ordem de preferência (nunca fundo branco nem detalhe). */
    public const CAPAS_DO_PREMIUM = ['composicao', 'lifestyle_uso', 'lifestyle'];

    /**
     * @param  list<string>  $fotos
     * @param  array<string, string>  $tipos  id da foto → tipo do criativo que a gerou (ausente = foto do cliente)
     * @return list<string>
     */
    public static function aplicar(array $fotos, int $indiceAlvo, array $tipos = []): array
    {
        $fotos = array_values($fotos);
        if (count($fotos) < 2 || $indiceAlvo <= 0) {
            return $fotos;
        }

        $candidatas = [];
        foreach (self::CAPAS_DO_PREMIUM as $tipo) {
            foreach ($fotos as $posicao => $id) {
                if ($posicao > 0 && ($tipos[$id] ?? null) === $tipo) {
                    $candidatas[] = $posicao;
                }
            }
        }
        if ($candidatas === []) {
            return $fotos;
        }

        $posicao = $candidatas[($indiceAlvo - 1) % count($candidatas)];
        [$fotos[0], $fotos[$posicao]] = [$fotos[$posicao], $fotos[0]];

        return $fotos;
    }
}
