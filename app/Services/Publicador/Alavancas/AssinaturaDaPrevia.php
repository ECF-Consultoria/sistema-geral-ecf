<?php

namespace App\Services\Publicador\Alavancas;

/**
 * D-04 — a prévia assina o que vai ao ML; confirmar recomputa e compara.
 * Sem estado em banco.
 *
 * Números: o controller normaliza preços com `round((float) $v, 2)` ANTES de assinar
 * e de confirmar, para a mesma entrada dar o mesmo canônico.
 *
 * A assinatura é de USO ÚNICO: o `confirmar` a queima com
 * `Cache::add("alavancas:assinatura:".sha1($assinatura), 1, <validade>)` e um
 * segundo envio responde 409 (feito no controller, 166-11).
 */
final class AssinaturaDaPrevia
{
    /** Ordem das chaves de cada item não importa; a ordem dos itens importa. */
    public static function canonico(string $acao, array $itens): array
    {
        return ['acao' => $acao, 'itens' => array_map(fn ($item) => self::ordenar($item), array_values($itens))];
    }

    public static function gerar(array $canonico, string $chaveTela, int $userId, ?int $expiraEm = null): string
    {
        $exp = $expiraEm ?? now()->addMinutes((int) config('publicador.alavancas.previa_validade_minutos', 10))->getTimestamp();

        return self::hmac($canonico, $chaveTela, $userId, $exp).'.'.$exp;
    }

    public static function confere(?string $assinatura, array $canonico, string $chaveTela, int $userId): bool
    {
        if (! is_string($assinatura) || ! preg_match('/^([0-9a-f]{64})\.(\d+)$/', $assinatura, $m)) {
            return false;
        }
        $exp = (int) $m[2];
        if ($exp < now()->getTimestamp()) {
            return false;
        }

        return hash_equals(self::hmac($canonico, $chaveTela, $userId, $exp), $m[1]);
    }

    private static function hmac(array $canonico, string $chaveTela, int $userId, int $exp): string
    {
        $json = json_encode([$canonico, $chaveTela, $userId, $exp], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return hash_hmac('sha256', (string) $json, (string) config('app.key'));
    }

    private static function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $valor = array_map(fn ($v) => self::ordenar($v), $valor);
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }
}
