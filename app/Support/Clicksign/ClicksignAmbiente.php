<?php

namespace App\Support\Clicksign;

use Illuminate\Support\Str;

/**
 * ClicksignAmbiente — Fase 132 Plano 01 (D-01).
 *
 * Fecha a armadilha de grafia encontrada durante o discuss desta fase: o
 * `config/services.php` comparava `env('CLICKSIGN_ENV') === 'producao'`
 * (português), mas o SC1 do ROADMAP manda escrever `production` (inglês).
 * Quem seguisse o ROADMAP à risca fazia o botão "Registrar e ir para a
 * Clicksign" (Fase 131) apontar para o painel de TESTE enquanto o sistema
 * já operava em produção — em silêncio, sem nenhum erro visível.
 *
 * Esta classe normaliza a grafia (`ehProducao()`) e resolve a URL do painel
 * a partir dela (`painelUrl()`). Classe PURA, sem I/O — mesmo molde de
 * `ClicksignHmacVarredura`: estática, sem estado, docblock em pt-BR.
 *
 * Motivo de a regra morar aqui e não inline no `config/services.php`: config
 * é avaliado no boot e fica em cache, o que torna a regra impossível de
 * testar sem recarregar a aplicação inteira. Com a regra na classe, o teste
 * é direto e o config vira uma linha de fiação.
 *
 * ⛔ Grafia desconhecida (erro de digitação) NUNCA leva ao painel de
 * produção — o default seguro é sempre o painel de teste. É essa a garantia
 * que fecha a armadilha: quem erra a grafia continua em sandbox, nunca cai
 * em produção por acidente.
 */
final class ClicksignAmbiente
{
    /**
     * As grafias aceitas como "produção", já normalizadas (minúsculas, sem
     * acento). Fonte única — o teste usa esta mesma constante, ninguém
     * precisa repetir a lista em dois lugares.
     */
    public const GRAFIAS_PRODUCAO = ['producao', 'production', 'prod'];

    /** URL do painel de produção da Clicksign. */
    public const PAINEL_PRODUCAO = 'https://app.clicksign.com';

    /** URL do painel de teste (sandbox) da Clicksign. */
    public const PAINEL_SANDBOX = 'https://sandbox.clicksign.com';

    /**
     * Normaliza `$env` (trim, `Str::ascii` para tirar acento/til, minúsculas,
     * nesta ordem) e diz se o resultado é uma das grafias de produção.
     *
     * Valor nulo, vazio ou qualquer grafia fora de `GRAFIAS_PRODUCAO` devolve
     * `false` — inclusive uma grafia parecida mas errada (`producaozinho`).
     */
    public static function ehProducao(?string $env): bool
    {
        if ($env === null) {
            return false;
        }

        $normalizado = mb_strtolower(Str::ascii(trim($env)));

        return in_array($normalizado, self::GRAFIAS_PRODUCAO, true);
    }

    /**
     * Resolve a URL do painel: `$painelUrlExplicita` vence quando preenchida
     * (permite override manual via `CLICKSIGN_PAINEL_URL`); senão, produção
     * quando `ehProducao($env)` for verdadeiro, e sandbox em qualquer outro
     * caso — inclusive grafia desconhecida, de propósito (default seguro).
     */
    public static function painelUrl(?string $painelUrlExplicita, ?string $env): string
    {
        if (filled($painelUrlExplicita)) {
            return $painelUrlExplicita;
        }

        return self::ehProducao($env) ? self::PAINEL_PRODUCAO : self::PAINEL_SANDBOX;
    }
}
