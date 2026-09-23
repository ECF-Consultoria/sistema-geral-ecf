<?php

namespace App\Services\Portal\Estrutura;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;

/**
 * A régua do Mapeamento Estrutural — o que a aba "Mapeamento Estrutural" da
 * planilha calcula (colunas E:H e o painel K5:K15), num lugar só.
 *
 * Funções puras sobre as linhas que {@see EstruturaConjunto} monta: nada aqui
 * consulta o banco, e por isso a tela, a agenda e o teste do gabarito leem a
 * MESMA conta. Recalcular isto dentro de uma página é como duas telas passam a
 * discordar (`precificacao-onboarding-duas-telas.md` §1).
 *
 * ### O gabarito
 * O exemplo preenchido da planilha (cadeira + mesa) é fixture em
 * `tests/Feature/PortalCliente/Estrutura/ReguaDoGabaritoTest.php`:
 * `9 · 2/5/1/1 · 18 · 4 · 14 · 1 · 22,2%`. Se a régua não reproduzir isso, a
 * régua está errada — não o gabarito.
 *
 * A linha "Kit virtual" do painel da planilha (K10) NÃO existe aqui, de
 * propósito: era `COUNTIF(FASE = "KIT VIRTUAL")`, e kit virtual deixou de ser
 * fase (ADR §Kit virtual).
 */
final class ReguaEstrutura
{
    public const SITUACAO_OK             = 'ok';
    public const SITUACAO_FALTA_CLASSICO = 'falta_classico';
    public const SITUACAO_FALTA_PREMIUM  = 'falta_premium';
    public const SITUACAO_PUBLICAR       = 'publicar';

    /** Os textos da coluna H da planilha, literalmente. */
    public const SITUACOES = [
        self::SITUACAO_OK             => 'OK - Clássico + Premium',
        self::SITUACAO_FALTA_CLASSICO => 'Falta Clássico',
        self::SITUACAO_FALTA_PREMIUM  => 'Falta Premium',
        self::SITUACAO_PUBLICAR       => 'Publicar Clássico + Premium',
    ];

    /**
     * A coluna H: decide só por Clássico e Premium. Catálogo e kit virtual
     * nunca entram — são avaliados, não cobrados.
     */
    public static function situacao(int $classicos, int $premiums): string
    {
        return match (true) {
            $classicos > 0 && $premiums > 0 => self::SITUACAO_OK,
            $classicos === 0 && $premiums === 0 => self::SITUACAO_PUBLICAR,
            $classicos === 0 => self::SITUACAO_FALTA_CLASSICO,
            default => self::SITUACAO_FALTA_PREMIUM,
        };
    }

    /**
     * "Unid. no anúncio": 1 na simples; nas demais, a soma das quantidades dos
     * componentes. Reproduz os 9 valores da planilha (1,2,3,4,5,6,1,2,5).
     *
     * @param  array<int, array{quantidade: int}>  $componentes
     */
    public static function unidades(string $fase, array $componentes): int
    {
        if ($fase === EstruturaOferta::FASE_SIMPLES) {
            return 1;
        }

        return array_sum(array_column($componentes, 'quantidade'));
    }

    /**
     * As contagens de uma oferta — as colunas E, F e G da planilha, mais o kit
     * virtual (flag do anúncio).
     *
     * Conta cada anúncio que NÃO é inativo (pausado conta). O catálogo conta
     * de qualquer tipo, então um Premium de catálogo aparece em Premium E em
     * Catálogo — igual à planilha, onde a MSA-MR tem Premium 1 e Catálogo 1
     * pelo mesmo anúncio.
     *
     * @param  array<int, array{tipo: string, status: string, catalogo: bool, kit_virtual: bool}>  $anuncios
     * @return array{classicos: int, premiums: int, catalogos: int, kits_virtuais: int}
     */
    public static function contagens(array $anuncios): array
    {
        $c = ['classicos' => 0, 'premiums' => 0, 'catalogos' => 0, 'kits_virtuais' => 0];

        foreach ($anuncios as $a) {
            if (! EstruturaAnuncio::conta($a['status'])) {
                continue;
            }

            if ($a['tipo'] === EstruturaAnuncio::TIPO_CLASSICO) {
                $c['classicos']++;
            } elseif ($a['tipo'] === EstruturaAnuncio::TIPO_PREMIUM) {
                $c['premiums']++;
            }

            if ($a['catalogo']) {
                $c['catalogos']++;
            }

            if ($a['kit_virtual']) {
                $c['kits_virtuais']++;
            }
        }

        return $c;
    }

    /**
     * O painel (K5:K15 menos K10), sobre o conjunto INTEIRO da empresa —
     * nunca sobre a página que a tela mostra.
     *
     * - `ofertas`: uma por linha; SKU repetido conta duas, como na planilha.
     * - `publicados`: por oferta, 1 se tem Clássico + 1 se tem Premium.
     *   Duplicado do mesmo tipo não soma; catálogo não soma (nota J17).
     * - `percentual`: fração 0..1; 0 sem oferta (a planilha fazia o mesmo com
     *   `IF(K11=0,0,…)`).
     *
     * @param  array<int, array{fase: string, classicos: int, premiums: int}>  $ofertas
     */
    public static function painel(array $ofertas): array
    {
        $porFase = array_fill_keys(array_keys(EstruturaOferta::FASES), 0);
        $publicados = 0;
        $completas = 0;

        foreach ($ofertas as $o) {
            $porFase[$o['fase']] = ($porFase[$o['fase']] ?? 0) + 1;

            $publicados += ($o['classicos'] > 0 ? 1 : 0) + ($o['premiums'] > 0 ? 1 : 0);

            if (self::situacao($o['classicos'], $o['premiums']) === self::SITUACAO_OK) {
                $completas++;
            }
        }

        $total = count($ofertas);
        $necessarios = $total * 2;

        return [
            'ofertas'     => $total,
            'por_fase'    => $porFase,
            'necessarios' => $necessarios,
            'publicados'  => $publicados,
            'a_publicar'  => $necessarios - $publicados,
            'completas'   => $completas,
            'percentual'  => $necessarios === 0 ? 0.0 : $publicados / $necessarios,
        ];
    }
}
