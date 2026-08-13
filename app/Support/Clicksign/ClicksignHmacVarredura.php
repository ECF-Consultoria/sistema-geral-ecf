<?php

namespace App\Support\Clicksign;

/**
 * ClicksignHmacVarredura — Fase 129, gate A1 (D-08/D-09).
 *
 * Classe PURA, sem I/O: nenhum método chama `config()`, grava log ou toca o
 * banco. O secret entra sempre por parâmetro — é isso que torna a classe
 * testável por unidade e reusável pelo comando `clicksign:verificar-
 * assinatura`, pela rota-sonda e pelo receiver de produção (plano 129-02/03)
 * sem três cópias da mesma conta.
 *
 * A pesquisa mostrou que a MESMA página oficial da Clicksign produz duas
 * leituras contraditórias sobre a fórmula do `Content-Hmac`, conforme a
 * pergunta (129-RESEARCH.md §"Lacuna Prioritária" §2). Como o gate A1 só se
 * resolve por medição contra um webhook real — e cada webhook real custa
 * configuração manual do usuário e cota do sandbox — esta classe varre as
 * 4 candidatas de uma vez em vez de apostar numa só (D-08).
 *
 * ⚠️ `FORMULA_CONFIRMADA` nasce `null` de propósito. Ela só muda no plano
 * 129-02, DEPOIS da medição contra webhook real — trocar essa constante por
 * leitura de documentação é exatamente o que a D-08 proíbe. Enquanto for
 * `null`, `confere()` lança `\RuntimeException`: é o fusível que impede
 * alguém de ligar o receiver de produção com fórmula chutada antes do gate
 * A1 fechar.
 */
class ClicksignHmacVarredura
{
    /**
     * As 4 fórmulas candidatas, nesta ordem. Representação hex confirmada
     * (base64 já descartado — 129-RESEARCH.md §2), então nenhuma varre esse
     * eixo.
     */
    public const CANDIDATAS = [
        'soma_body_secret'       => "hash('sha256', body . secret)",
        'soma_secret_body'       => "hash('sha256', secret . body)",
        'hmac_body_chave_secret' => "hash_hmac('sha256', body, secret)",
        'hmac_secret_chave_body' => "hash_hmac('sha256', secret, body)",
    ];

    /**
     * Fica `null` neste plano — só o plano 129-02 (depois da medição real
     * contra webhook do sandbox) troca por uma das 4 chaves de `CANDIDATAS`.
     */
    public const FORMULA_CONFIRMADA = null;

    /** O header `Content-Hmac` vem como `sha256=<hex>` (convergente em todas as fontes). */
    public const PREFIXO = 'sha256=';

    /**
     * Calcula UMA candidata sobre o corpo bruto.
     *
     * @throws \InvalidArgumentException se `$formula` não existir em `CANDIDATAS`.
     */
    public static function calcular(string $rawBody, string $secret, string $formula): string
    {
        if (!array_key_exists($formula, self::CANDIDATAS)) {
            throw new \InvalidArgumentException("Fórmula desconhecida: \"{$formula}\". Candidatas válidas: " . implode(', ', array_keys(self::CANDIDATAS)) . '.');
        }

        $hex = match ($formula) {
            'soma_body_secret'       => hash('sha256', $rawBody . $secret),
            'soma_secret_body'       => hash('sha256', $secret . $rawBody),
            'hmac_body_chave_secret' => hash_hmac('sha256', $rawBody, $secret),
            'hmac_secret_chave_body' => hash_hmac('sha256', $secret, $rawBody),
        };

        return self::PREFIXO . $hex;
    }

    /**
     * @return array<string, string> chave da candidata => 'sha256=<hex>'
     */
    public static function calcularTodas(string $rawBody, string $secret): array
    {
        $resultado = [];

        foreach (array_keys(self::CANDIDATAS) as $formula) {
            $resultado[$formula] = self::calcular($rawBody, $secret, $formula);
        }

        return $resultado;
    }

    /**
     * Compara cada candidata com o header recebido via `hash_equals()` —
     * NUNCA `===` (timing attack, ASVS V6).
     *
     * @return array<string, bool> chave da candidata => bate ou não
     */
    public static function identificarTodas(string $rawBody, string $secret, string $headerRecebido): array
    {
        $veredito = [];

        foreach (self::calcularTodas($rawBody, $secret) as $formula => $calculado) {
            $veredito[$formula] = hash_equals($calculado, $headerRecebido);
        }

        return $veredito;
    }

    /**
     * Chave da primeira candidata que bate, ou `null` se nenhuma bater.
     */
    public static function vencedora(string $rawBody, string $secret, string $headerRecebido): ?string
    {
        foreach (self::identificarTodas($rawBody, $secret, $headerRecebido) as $formula => $bateu) {
            if ($bateu) {
                return $formula;
            }
        }

        return null;
    }

    /**
     * Verificação de produção — usa `FORMULA_CONFIRMADA`. Lança enquanto o
     * gate A1 não fechou (constante `null`), impedindo o receiver de
     * produção (plano 129-03) de ser ligado com fórmula chutada.
     *
     * @throws \RuntimeException enquanto `FORMULA_CONFIRMADA` for `null`.
     */
    public static function confere(string $rawBody, string $secret, string $headerRecebido): bool
    {
        if (self::FORMULA_CONFIRMADA === null) {
            throw new \RuntimeException('Gate A1 não fechado: a fórmula do Content-Hmac ainda não foi medida contra webhook real.');
        }

        $calculado = self::calcular($rawBody, $secret, self::FORMULA_CONFIRMADA);

        return hash_equals($calculado, $headerRecebido);
    }
}
