<?php

namespace App\Support\Publicador;

/**
 * A causa CURTA de uma etapa do preparo pela IA que terminou em erro (retorno do 1º teste da trilha, R4, 04/11/2026) —
 * o que `PreparoIaDoRascunhoService::executarEtapa` passa a guardar em `step_state.ia_preparo.etapas.{etapa}.motivo`,
 * de forma ADITIVA (sem a chave = erro antigo, causa desconhecida).
 *
 * Por que existe: em 04/11 o Gemini passou a responder 402 (a conta sem crédito) e a trilha só dizia "O agente parou:
 * título, descrição" — o erro ia para `Log::warning`, e a produção grava só `error`. Ninguém sabia o que fazer.
 *
 * ### De onde sai a causa
 * Do TEXTO da exceção que chega ao preparo: é a mensagem que `AnaliseAnuncioService::mensagemAmigavel()` (e os textos
 * de prazo/conexão do `chamarModelo`) monta para a falha do provedor — o serviço não guarda o status HTTP na exceção
 * (o `chamar()` relança só a mensagem). As frases casadas aqui são as DELE, todas falando da IA ("A IA respondeu com
 * erro 402", "O limite de uso da IA…"): um erro do Mercado Livre na mesma etapa (os termos da categoria) nunca vira
 * "a IA está sem crédito". Mudou o texto lá, muda aqui — o `PreparoNaTrilhaTest` passa a resposta de mentira do
 * provedor (402, 429, 503, sem conexão) pelo serviço de verdade e confere a causa gravada.
 *
 * Os códigos (a tela da trilha escreve o texto e o que fazer):
 * - `sem_credito` — 402 (pagamento/crédito da conta do provedor): avisar o responsável pela conta;
 * - `chave`       — 401/403 (a chave foi recusada): avisar o responsável pela conta;
 * - `limite`      — 429 (limite de uso): tentar de novo em alguns minutos;
 * - `indisponivel` — 408/5xx, sobrecarga, sem resposta no tempo, prazo da geração: tentar de novo;
 * - `outro`       — o resto (resposta vazia, JSON inválido, nada aproveitável…): tentar de novo.
 */
final class MotivoDaFalhaDaIa
{
    public const SEM_CREDITO = 'sem_credito';

    public const CHAVE = 'chave';

    public const LIMITE = 'limite';

    public const INDISPONIVEL = 'indisponivel';

    public const OUTRO = 'outro';

    public const TODOS = [self::SEM_CREDITO, self::CHAVE, self::LIMITE, self::INDISPONIVEL, self::OUTRO];

    public static function de(\Throwable $e): string
    {
        $texto = mb_strtolower($e->getMessage());

        // "A IA respondeu com erro 402. Tente novamente." — o ramo de reserva do `mensagemAmigavel` (o 402 cai nele).
        if (preg_match('/a ia respondeu com erro (\d{3})\b/u', $texto, $m) === 1) {
            return self::doStatus((int) $m[1]);
        }

        return match (true) {
            str_contains($texto, 'limite de uso da ia')                 => self::LIMITE,
            str_contains($texto, 'chave de api da ia')                  => self::CHAVE,
            str_contains($texto, 'provedor de ia está sobrecarregado'),
            str_contains($texto, 'a ia não respondeu em'),
            str_contains($texto, 'a geração passou do tempo limite')    => self::INDISPONIVEL,
            default                                                     => self::OUTRO,
        };
    }

    /** O status HTTP do provedor → a causa. */
    public static function doStatus(int $status): string
    {
        return match (true) {
            $status === 402                    => self::SEM_CREDITO,
            $status === 401 || $status === 403 => self::CHAVE,
            $status === 429                    => self::LIMITE,
            $status === 408 || $status >= 500  => self::INDISPONIVEL,
            default                            => self::OUTRO,
        };
    }
}
