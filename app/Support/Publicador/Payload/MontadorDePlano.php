<?php

namespace App\Support\Publicador\Payload;

use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\SchemaClassificado;

/**
 * A porta única para o PayloadPlan: o mesmo plano serve a Revisão e a
 * Publicação (RN-91). O modelo vem da conta (tag `user_product_seller`, RN-02)
 * — a pessoa não escolhe.
 *
 * Decisão D10 (usuário, 01/10/2026): só User Products na Fase 1. As 33 contas
 * de cliente medidas eram todas UP; conta no modelo antigo é recusada aqui com
 * mensagem clara, em vez de publicar por um caminho que ninguém testou.
 */
final class MontadorDePlano
{
    public const UP = 'USER_PRODUCTS';
    public const LEGADO = 'LEGADO';

    /** @param array<string, string> $fotosMl  id da foto no rascunho → `ml_picture_id` */
    public static function montar(RascunhoSnapshot $r, SchemaClassificado $schema, string $modelo, array $fotosMl = []): PayloadPlan
    {
        if ($modelo !== self::UP) {
            throw new RegraViolada('D10', 'Esta conta do Mercado Livre usa o modelo antigo de variações, que o Publicador ainda não atende. Publique pelo Mercado Livre por enquanto.');
        }

        return new PayloadPlan(self::UP, PayloadBuilderUserProducts::montar($r, $schema, $fotosMl), self::descricao($r->descricao));
    }

    /** Texto puro (RN-72): sem HTML, quebras normalizadas. Vai depois do item criado, em cada item (RN-73). */
    private static function descricao(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $limpo = trim(preg_replace("/\r\n?/", "\n", strip_tags($texto)));

        return $limpo === '' ? null : $limpo;
    }
}
