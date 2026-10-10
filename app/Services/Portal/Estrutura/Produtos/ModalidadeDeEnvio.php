<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;

/**
 * A modalidade de envio da conta do cliente — o `logistic_type` do ME2 com que ela
 * despacha (Correios = drop_off, Agências = xd_drop_off, Coleta = cross_docking,
 * Full = fulfillment). Decide os limites do Mercado Envios ({@see LogisticaProduto})
 * e vai no `logistic_type` da cotação real.
 *
 * Vem de GET /users/{id}/shipping_preferences, lido SÓ pela cotação real
 * ({@see FreteMe2Service::cotar}, ação explícita) e guardado por
 * `frete.modalidade_cache_horas`. Aqui não há requisição: a leitura do cache e a
 * interpretação da resposta. Sem conta ou sem a preferência lida, valem os limites
 * padrão (Correios, os mais estreitos) — a tela nunca promete ME2 que a conta não tem.
 */
final class ModalidadeDeEnvio
{
    /**
     * Tipos com que a conta DESPACHA o que não está no Full, na ordem de preferência
     * quando nenhum deles é o padrão da conta. Flex (self_service) é complemento, não
     * modalidade; o Full só vale quando é o único tipo ativo.
     */
    private const DE_DESPACHO = ['cross_docking', 'xd_drop_off', 'drop_off'];

    private const FULL = 'fulfillment';

    public static function chave(int $empresaId): string
    {
        return "estrutura:frete:modalidade:v1:{$empresaId}";
    }

    /** A modalidade já lida da conta; null = desconhecida (valem os limites padrão). */
    public static function emCache(Company $empresa): ?string
    {
        return self::doGuardado(Cache::get(self::chave((int) $empresa->id)));
    }

    /** O valor guardado no cache → a modalidade (null quando nunca lida ou não reconhecida). */
    public static function doGuardado(mixed $guardado): ?string
    {
        $tipo = is_array($guardado) ? ($guardado['tipo'] ?? null) : null;

        return is_string($tipo) && $tipo !== '' ? $tipo : null;
    }

    /**
     * Guarda a leitura (inclusive "sem modalidade reconhecida", para não reler a cada clique).
     * Sem `$horas`, o cache longo do config.
     */
    public static function guardar(int $empresaId, ?string $tipo, ?int $horas = null): void
    {
        Cache::put(self::chave($empresaId), ['tipo' => $tipo, 'lido_em' => now()->toIso8601String()],
            now()->addHours($horas ?? (int) config('estrutura_produtos.frete.modalidade_cache_horas')));
    }

    /**
     * A modalidade a partir do corpo de `shipping_preferences`: o tipo ATIVO do modo me2
     * marcado como padrão, se for de despacho; senão o primeiro ativo de despacho; senão o
     * Full, se for o único. Só valem tipos com limites no config.
     */
    public static function daPreferencia(array $corpo): ?string
    {
        $conhecidos = array_keys((array) config('estrutura_produtos.modalidades'));
        $ativos = [];
        $padrao = null;

        foreach ((array) ($corpo['logistics'] ?? []) as $logistica) {
            if (! is_array($logistica) || ($logistica['mode'] ?? null) !== 'me2') {
                continue;
            }
            foreach ((array) ($logistica['types'] ?? []) as $t) {
                $tipo = is_array($t) ? ($t['type'] ?? null) : null;
                if (! is_string($tipo) || ! in_array($tipo, $conhecidos, true) || ($t['status'] ?? 'active') !== 'active') {
                    continue;
                }
                $ativos[] = $tipo;
                if (! empty($t['default']) && in_array($tipo, self::DE_DESPACHO, true)) {
                    $padrao = $tipo;
                }
            }
        }

        if ($padrao !== null) {
            return $padrao;
        }
        foreach (self::DE_DESPACHO as $tipo) {
            if (in_array($tipo, $ativos, true)) {
                return $tipo;
            }
        }

        return in_array(self::FULL, $ativos, true) ? self::FULL : null;
    }
}
