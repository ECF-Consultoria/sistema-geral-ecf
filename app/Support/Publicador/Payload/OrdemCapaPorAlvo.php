<?php

namespace App\Support\Publicador\Payload;

/**
 * Rotação de capa por alvo (D6, CAPA-01..04): publicando o mesmo produto como
 * Clássico e Premium, a capa (a 1ª foto enviada ao ML) não pode ser a mesma
 * nos dois. Regra: índice 0 NUNCA rotaciona (mantém a capa de hoje, CAPA-03);
 * o índice N rotaciona a lista para a esquerda em N posições — com o par
 * Clássico (0) + Premium (1) isso troca só qual foto fica em 1º lugar, sem
 * tocar na ordem relativa das demais. Generaliza para mais de 2 alvos.
 *
 * Lista com menos de 2 fotos não tem o que alternar: devolve como veio (0 ou
 * 1 foto aprovada é limite físico, não defeito). Nunca duplica nem descarta
 * foto (CAPA-02) e é determinística — função pura, sem relógio, sem banco,
 * sem estado (CAPA-04).
 */
final class OrdemCapaPorAlvo
{
    /**
     * @param  list<string>  $fotos
     * @return list<string>
     */
    public static function aplicar(array $fotos, int $indiceAlvo): array
    {
        if (count($fotos) < 2 || $indiceAlvo <= 0) {
            return $fotos;
        }

        $deslocamento = $indiceAlvo % count($fotos);

        return [...array_slice($fotos, $deslocamento), ...array_slice($fotos, 0, $deslocamento)];
    }
}
