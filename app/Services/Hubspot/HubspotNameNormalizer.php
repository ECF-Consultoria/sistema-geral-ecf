<?php

namespace App\Services\Hubspot;

/**
 * HubspotNameNormalizer — normaliza nome de empresa para o match FRACO de
 * dedup (Fase 113, plano 01 — HUB-DEDUP-02, building block).
 *
 * Unidade pura: recebe/devolve apenas string, sem tocar DB/config/HTTP.
 *
 * IMPORTANTE (proteção contra falso positivo): a normalização é AGRESSIVA
 * só o suficiente para neutralizar caixa/acento/pontuação/espaçamento —
 * ela NUNCA remove tokens (ex.: "ltda", "me", "sa" continuam presentes).
 * Isso é o que impede que "Silva Ltda" e "Silva Ltda ME" colapsem na
 * mesma string, e que empresas com nomes parecidos mas distintos (ex.:
 * "Padaria do Zé" vs "Padaria da Ana") sejam tratadas como a mesma.
 */
class HubspotNameNormalizer
{
    /**
     * Normaliza um nome de empresa: minúsculas, sem acento, sem
     * pontuação (substituída por espaço), espaços colapsados e trim.
     * Entrada null/vazia retorna string vazia.
     */
    public static function normalizar(?string $nome): string
    {
        if ($nome === null || trim($nome) === '') {
            return '';
        }

        $normalizado = mb_strtolower($nome, 'UTF-8');
        $normalizado = self::removerAcentos($normalizado);

        // Tudo que não for letra ascii ou dígito vira espaço (pontuação,
        // "&", símbolos etc.) — preserva os tokens, só limpa separadores.
        $normalizado = preg_replace('/[^a-z0-9]+/', ' ', $normalizado);

        // Colapsa espaços múltiplos em um só e faz trim.
        $normalizado = trim(preg_replace('/\s+/', ' ', $normalizado));

        return $normalizado;
    }

    /**
     * Remove acentos/diacríticos de uma string UTF-8 minúscula, usando
     * uma tabela de substituição manual — robusta independentemente da
     * locale configurada no ambiente (iconv TRANSLIT depende de locale
     * e pode não estar disponível/consistente em todo servidor).
     */
    private static function removerAcentos(string $texto): string
    {
        static $mapa = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        ];

        return strtr($texto, $mapa);
    }
}
