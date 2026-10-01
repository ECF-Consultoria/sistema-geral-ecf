<?php

namespace App\Support\Publicador\Erros;

use App\Support\Publicador\Payload\ItemPlano;
use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Validacao\ValidadorRascunho;

/**
 * Causa do ML → problema na tela (`09` §3): etapa, campo, atributo e
 * variante, mensagem traduzida pelo dicionário (`config/publicador_erros.php`)
 * e a causa crua junto, mostrada recolhida (`08` §3).
 *
 * O atributo afetado sai, nesta ordem:
 *   1. do índice em `references` — `item.attributes[16].values` é o 17º
 *      atributo DO PAYLOAD ENVIADO (N-17), por isso o item vem junto;
 *   2. dos ids citados na mensagem (`[BRAND, MODEL]`, `seller_package_height`,
 *      `Attribute MODEL has…`);
 *   3. dos nomes entre aspas (`"Altura do encosto"`).
 * Só vale id que existe no schema ou no payload: palavra solta não vira campo.
 *
 * Função pura.
 */
final class MapeadorErrosMl
{
    /** Regra da spec por `cause_id`; o resto é V-REM-01 (o validate recusou). */
    private const REGRA = [
        '3705' => 'V-TIT-03', '3715' => 'V-TIT-03', '462' => 'V-TIT-01',
        '109' => 'V-SAL-03', '5400' => 'V-SAL-06', '5402' => 'V-SAL-06',
        '7810' => 'V-ATT-08', '173' => 'V-IMG-04', '126' => 'V-CAT-01', '2610' => 'V-CAT-04',
        '7710' => 'V-VAR-14', '7711' => 'V-VAR-14', '7712' => 'V-VAR-14', '3701' => 'V-VAR-14',
    ];

    private const GTIN = ['7710', '7711', '7712', '3701', '7810'];

    private const FOTOS = ['173', '204', '3703', '3706'];

    /** @param array{cause_id?: ?int, code?: string, type?: string, message?: string, references?: list<string>} $causa */
    public static function problema(array $causa, ?ItemPlano $item, ?SchemaClassificado $schema, array $dicionario): Problema
    {
        $id = isset($causa['cause_id']) ? (string) $causa['cause_id'] : '';
        $code = (string) ($causa['code'] ?? '');
        $msg = (string) ($causa['message'] ?? '');
        $refs = array_map('strval', (array) ($causa['references'] ?? []));
        $aviso = strtolower((string) ($causa['type'] ?? 'error')) === 'warning';

        $atributos = self::atributosCitados($code, $msg, $refs, $item, $schema);

        return new Problema(
            self::REGRA[$id] ?? 'V-REM-01',
            $aviso ? Problema::AVISO : Problema::BLOQUEIO,
            self::mensagem($id, $code, $msg, $atributos, $schema, $dicionario),
            'L3',
            self::alvo($id, $code, $msg, $refs, $atributos, $item, $schema),
            $causa,
        );
    }

    /**
     * O mesmo erro em vários itens do plano vira UM problema, com a lista dos
     * itens em `alvo.itens` — num UP de 2 tipos × 6 variantes, "falta a marca"
     * viria 12 vezes.
     *
     * @param  list<Problema>  $problemas
     * @return list<Problema>
     */
    public static function agrupar(array $problemas): array
    {
        $grupos = [];
        foreach ($problemas as $p) {
            $alvo = $p->alvo;
            $itens = (array) ($alvo['itens'] ?? []);
            unset($alvo['itens']);
            $chave = json_encode([$p->regra, $p->severidade, $p->mensagem, $p->mlCausa['cause_id'] ?? null, $p->mlCausa['code'] ?? null, $alvo]);

            if (! isset($grupos[$chave])) {
                $grupos[$chave] = ['p' => $p, 'alvo' => $alvo, 'itens' => []];
            }
            $grupos[$chave]['itens'] = [...$grupos[$chave]['itens'], ...$itens];
        }

        return array_values(array_map(function ($g) {
            $alvo = $g['alvo'];
            if ($g['itens'] !== []) {
                $itens = array_values(array_unique($g['itens']));
                sort($itens);
                $alvo['itens'] = $itens;
            }

            return new Problema($g['p']->regra, $g['p']->severidade, $g['p']->mensagem, $g['p']->camada, $alvo, $g['p']->mlCausa);
        }, $grupos));
    }

    // ═══ Atributo citado ═════════════════════════════════════════════════════

    /** @return list<string> */
    private static function atributosCitados(string $code, string $msg, array $refs, ?ItemPlano $item, ?SchemaClassificado $schema): array
    {
        $doPayload = (array) ($item?->payload['attributes'] ?? []);

        $ids = [];
        foreach ($refs as $ref) {
            if (preg_match('/^item\.attributes\[(\d+)\]/', $ref, $m) && isset($doPayload[(int) $m[1]]['id'])) {
                $ids[] = (string) $doPayload[(int) $m[1]]['id'];
            }
        }
        if ($ids !== [] || ! self::falaDeAtributo($code, $refs)) {
            return array_values(array_unique($ids));
        }

        $conhecidos = array_flip([...array_filter(array_column($doPayload, 'id')), ...array_keys($schema?->atributos ?? [])]);
        preg_match_all('/[A-Za-z][A-Za-z0-9_]+/', $msg, $m);
        foreach ($m[0] as $palavra) {
            // ID do ML (BACKREST_HEIGHT) ou a forma minúscula com "_" que o ML às vezes usa (seller_package_height).
            $pareceId = preg_match('/^[A-Z][A-Z0-9_]+$/', $palavra) || preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $palavra);
            if ($pareceId && isset($conhecidos[strtoupper($palavra)])) {
                $ids[] = strtoupper($palavra);
            }
        }

        if ($ids === [] && $schema && preg_match_all('/"([^"]+)"/u', $msg, $m)) {
            foreach ($m[1] as $nome) {
                foreach ($schema->atributos as $a) {
                    if (mb_strtolower($a->nome) === mb_strtolower(trim($nome))) {
                        $ids[] = $a->id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function falaDeAtributo(string $code, array $refs): bool
    {
        if (str_contains($code, 'attribute') || str_contains($code, 'product_identifier') || $code === 'field.constraint.violated') {
            return true;
        }

        return (bool) array_filter($refs, fn ($r) => str_starts_with($r, 'item.attributes') || $r === 'item.name');
    }

    // ═══ Onde mostrar ════════════════════════════════════════════════════════

    private static function alvo(string $id, string $code, string $msg, array $refs, array $atributos, ?ItemPlano $item, ?SchemaClassificado $schema): array
    {
        $ref = implode(' ', $refs);
        $tem = fn (string ...$trechos) => (bool) array_filter($trechos, fn ($t) => str_contains($code, $t) || str_contains($ref, $t));

        $alvo = match (true) {
            in_array($id, self::GTIN, true) || array_intersect($atributos, ['GTIN', 'EMPTY_GTIN_REASON']) !== [] => ['etapa' => 'E5', 'atributo' => 'GTIN'],
            $id === '109' || $tem('item.price') => ['etapa' => 'E10', 'campo' => 'preco'],
            in_array($id, self::FOTOS, true) || $tem('picture') => ['etapa' => 'E6'],
            $tem('item.title', 'item.family_name', 'family_name') || str_contains($msg, '[family_name]') => ['etapa' => 'E7', 'campo' => 'titulo'],
            $tem('seller.package', 'seller_package') => ['etapa' => 'E10', 'campo' => 'embalagem'],
            $tem('available_quantity') || str_contains($msg, '[available_quantity]') => ['etapa' => 'E5', 'campo' => 'estoque'],
            $tem('shipping') => ['etapa' => 'E10', 'campo' => 'envio'],
            $tem('sale_terms', 'warranty') => ['etapa' => 'E10', 'campo' => 'garantia'],
            $id === '3707' || $tem('description') => ['etapa' => 'E9', 'campo' => 'descricao'],
            $tem('category_id') => ['etapa' => 'E2'],
            $tem('listing_type_id') => ['etapa' => 'E10', 'campo' => 'tipo'],
            $atributos !== [] => self::alvoDoAtributo($atributos, $schema),
            default => ['etapa' => 'OUTROS'],
        };

        if ($item === null) {
            return $alvo;
        }

        // Título e preço são por tipo de anúncio; estoque, GTIN, preço e dado de variante, por variante.
        if (in_array($alvo['campo'] ?? null, ['titulo', 'preco', 'tipo'], true)) {
            $alvo['listing_type'] = $item->listingTypeId;
        }
        $secao = isset($alvo['atributo']) ? $schema?->atributo($alvo['atributo'])?->secao : null;
        if (in_array($alvo['campo'] ?? null, ['estoque', 'preco'], true) || ($alvo['atributo'] ?? null) === 'GTIN' || in_array($secao, [A::SECAO_EIXO, A::SECAO_VARIANTE], true)) {
            $alvo['variante'] = $item->varianteChave;
        }
        $alvo['itens'] = [$item->indice];

        return $alvo;
    }

    private static function alvoDoAtributo(array $atributos, ?SchemaClassificado $schema): array
    {
        $secao = $schema?->atributo($atributos[0])?->secao;
        $alvo = ['etapa' => ValidadorRascunho::ETAPA_DA_SECAO[$secao] ?? 'E8', 'atributo' => $atributos[0]];
        if (count($atributos) > 1) {
            $alvo['atributos'] = $atributos;
        }

        return $alvo;
    }

    // ═══ O que dizer ═════════════════════════════════════════════════════════

    private static function mensagem(string $id, string $code, string $msg, array $atributos, ?SchemaClassificado $schema, array $dicionario): string
    {
        $modelo = ($id !== '' ? ($dicionario['por_codigo'][$id] ?? null) : null) ?? $dicionario['por_codigo'][$code] ?? null;
        if ($modelo === null) {
            $palheiro = mb_strtolower($code.' '.$msg);
            foreach ((array) ($dicionario['por_trecho'] ?? []) as $trecho => $traducao) {
                if (str_contains($palheiro, mb_strtolower($trecho))) {
                    $modelo = $traducao;
                    break;
                }
            }
        }
        if ($modelo === null) {
            return $msg !== '' ? $msg : 'O Mercado Livre recusou o anúncio sem dizer o motivo.';
        }

        $nomes = $atributos !== []
            ? implode(', ', array_map(fn ($a) => '«'.($schema?->atributo($a)?->nome ?? $a).'»', $atributos))
            : (preg_match('/\[([^\]]+)\]/', $msg, $m) ? $m[1] : 'um campo');
        $valor = preg_match('/price\D{0,5}(\d+(?:\.\d+)?)/i', $msg, $m) ? 'R$ '.number_format((float) $m[1], 2, ',', '.') : '';

        $texto = str_replace(['{atributos}', ' ({valor})', '{valor}'], [$nomes, $valor !== '' ? " ({$valor})" : '', $valor], $modelo);

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }
}
