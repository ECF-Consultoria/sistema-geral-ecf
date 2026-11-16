<?php

namespace App\Services\Polos;

use App\Models\Configuracao;
use App\Services\MlCategoriaService;

/**
 * Quais categorias do Mercado Livre contam para a META dos Polos (TKT-0036, DEV-76).
 *
 * Até out/2026 só contava a raiz "Casa, Móveis e Decoração" (MLB1574). Em 16/11/2026 a
 * Débora pediu para incluir Construção, Indústria e Comércio, Bebês e Brinquedos > Mesas e
 * Cadeiras, e o Maycon decidiu: lista configurável, valendo de nov/2026, SEM recalcular mês
 * fechado. Os outros itens do pedido (Banheiros; Camas, Colchões e Acessórios; Cozinha;
 * Organização para Casa; Móveis para Jardim) já moram dentro de MLB1574 e já contavam.
 *
 * Construção, Indústria e Comércio e Bebês entram INTEIRAS, como ela escreveu (quando quis
 * só um pedaço, escreveu "Brinquedos e Hobbies - Mesas e Cadeiras"). Restringir depois é
 * trocar a lista em `Configuracao` (chave `polo_meta_categorias`, ids separados por
 * vírgula), sem deploy. Cada id pode ser raiz ou subcategoria: vale a subárvore inteira.
 *
 * O valor apurado continua gravado em `polos_faturamento_snapshots.faturamento_moveis`
 * (renomear a coluna seria migration em tabela com dado de produção). Desde 202611 o nome
 * da coluna quer dizer "categorias da meta", não "só móveis".
 */
class CategoriasDaMetaPolos
{
    /** Primeiro mês (TIM_MONTH_ID 'YYYYMM') em que vale a lista ampliada. */
    public const MES_INICIO = '202611';

    /** Chave em `Configuracao` com a lista vigente (ids separados por vírgula). */
    public const CHAVE = 'polo_meta_categorias';

    /**
     * Lista do pedido de 16/11/2026: Casa, Móveis e Decoração; Construção; Indústria e
     * Comércio (inclui Gastronomia e Hotelaria, MLB268412); Bebês; Brinquedos e Hobbies >
     * Mesas e Cadeiras.
     */
    public const PADRAO = [
        MlCategoriaService::RAIZ_CASA_MOVEIS_DECORACAO, // Casa, Móveis e Decoração
        'MLB1500',   // Construção
        'MLB1499',   // Indústria e Comércio
        'MLB1384',   // Bebês
        'MLB270072', // Brinquedos e Hobbies > Mesas e Cadeiras
    ];

    /**
     * Categorias que contam para a meta no mês. Mês anterior a MES_INICIO fica só com
     * Casa, Móveis e Decoração: o botão "Sincronizar" de um mês fechado não pode
     * reescrevê-lo com a regra nova.
     *
     * Configuração vazia ou sem nenhum id válido cai no PADRAO em vez de zerar a meta de
     * todo mundo.
     *
     * @return array<int,string>
     */
    public static function doMes(string $mes): array
    {
        if ($mes < self::MES_INICIO) {
            return [MlCategoriaService::RAIZ_CASA_MOVEIS_DECORACAO];
        }

        $ids = collect(preg_split('/[\s,;]+/', strtoupper((string) Configuracao::get(self::CHAVE, ''))))
            ->filter(fn ($id) => preg_match('/^MLB\d+$/', $id) === 1)
            ->unique()
            ->values()
            ->all();

        return $ids !== [] ? $ids : self::PADRAO;
    }
}
