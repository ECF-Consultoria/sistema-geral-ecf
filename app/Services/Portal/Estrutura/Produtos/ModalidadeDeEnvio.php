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
 *
 * Da mesma leitura sai se a conta tem ME1 (21/10/2026). O pacote que passa do limite
 * do ME2 da conta NÃO tem frete do Mercado Livre: com ME1, vai pela transportadora do
 * vendedor; sem ME1, o anúncio sai "Combine a entrega" (`not_specified`). Medido em 4
 * contas com ME1 (481 anúncios ativos): nenhum anúncio em ME2 passa do limite da
 * modalidade da conta. O `shipping_options/free` devolve custo também para o pacote
 * que não cabe — número teórico, que não é cobrado (`frete-mercado-envios.md` §8).
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

    /** Para onde vai o pacote que passa do limite do ME2 da conta. */
    public const FORA_ME1 = 'me1';           // a conta tem ME1: a transportadora do vendedor

    public const FORA_COMBINAR = 'combinar'; // a conta não tem ME1: "Combine a entrega"

    public const FORA_SEM_SABER = 'fora';    // a preferência ainda não foi lida: não se sabe qual dos dois

    public static function chave(int $empresaId): string
    {
        return "estrutura:frete:modalidade:v1:{$empresaId}";
    }

    /** A modalidade já lida da conta; null = desconhecida (valem os limites padrão). */
    public static function emCache(Company $empresa): ?string
    {
        return self::doGuardado(Cache::get(self::chave((int) $empresa->id)));
    }

    /** Se a conta tem ME1, pelo cache; null = nunca lida (ou lida antes de 21/10/2026, quando isto não era guardado). */
    public static function me1EmCache(Company $empresa): ?bool
    {
        return self::me1DoGuardado(Cache::get(self::chave((int) $empresa->id)));
    }

    public static function me1DoGuardado(mixed $guardado): ?bool
    {
        $me1 = is_array($guardado) ? ($guardado['me1'] ?? null) : null;

        return is_bool($me1) ? $me1 : null;
    }

    /**
     * A conta tem ME1? Pelo corpo de `shipping_preferences`: `me1` em `modes` ou um bloco
     * `logistics[mode=me1]` com tipo ativo. Medido em 12/10/2026: 16 de 63 contas têm, todas
     * com a calculadora de frete do ML (`custom_calculator`); a #459 não tem.
     */
    public static function temMe1(array $corpo): bool
    {
        if (in_array('me1', (array) ($corpo['modes'] ?? []), true)) {
            return true;
        }
        foreach ((array) ($corpo['logistics'] ?? []) as $logistica) {
            if (is_array($logistica) && ($logistica['mode'] ?? null) === 'me1') {
                foreach ((array) ($logistica['types'] ?? []) as $t) {
                    if (is_array($t) && ($t['status'] ?? 'active') === 'active') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * O destino do pacote que NÃO cabe no ME2 da conta, a partir de ela ter ME1 (null = não lido).
     *
     * @return self::FORA_ME1|self::FORA_COMBINAR|self::FORA_SEM_SABER
     */
    public static function destinoFora(?bool $temMe1): string
    {
        return match ($temMe1) {
            true => self::FORA_ME1,
            false => self::FORA_COMBINAR,
            null => self::FORA_SEM_SABER,
        };
    }

    /** O valor guardado no cache → a modalidade (null quando nunca lida ou não reconhecida). */
    public static function doGuardado(mixed $guardado): ?string
    {
        $tipo = is_array($guardado) ? ($guardado['tipo'] ?? null) : null;

        return is_string($tipo) && $tipo !== '' ? $tipo : null;
    }

    /**
     * Guarda a leitura (inclusive "sem modalidade reconhecida", para não reler a cada clique).
     * Sem `$horas`, o cache longo do config. `$me1`: se a conta tem ME1 (null = não se sabe).
     */
    public static function guardar(int $empresaId, ?string $tipo, ?int $horas = null, ?bool $me1 = null): void
    {
        Cache::put(self::chave($empresaId), ['tipo' => $tipo, 'me1' => $me1, 'lido_em' => now()->toIso8601String()],
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
