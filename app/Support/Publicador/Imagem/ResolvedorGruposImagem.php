<?php

namespace App\Support\Publicador\Imagem;

use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * Galeria geral, grupos de imagem e a lista final de fotos de cada variante (`06`).
 *
 * O grupo de uma variante é o valor dos eixos que definem a foto
 * (`defines_picture`): todas as variantes Preto caem no grupo "COLOR=id:52049",
 * e a foto é atribuída ao GRUPO. Por construção, duas variantes da mesma cor não
 * têm como ficar com fotos diferentes (RN-61). Sem eixo que defina foto, todas
 * usam a galeria geral — ou, se a pessoa ligou "fotos por variante", cada
 * combinação é o próprio grupo.
 *
 * Fotos de uma variante = as do grupo, depois a galeria geral (RN-66), sem
 * repetir. A capa é a 1ª. Limite estourado BLOQUEIA e diz quais sobram — nunca
 * corta em silêncio (RN-63).
 *
 * As fotos são ids opacos (o do `image_asset`): o resolvedor não sabe de upload.
 */
final class ResolvedorGruposImagem
{
    /** O grupo da galeria geral (no banco, `grupo_chave = 'GENERAL'`, não NULL — `16` §4.1). */
    public const GERAL = 'GENERAL';

    /** @param list<Eixo> $eixos */
    public static function chaveDoGrupo(Variante $v, array $eixos, bool $fotosPorVariante): string
    {
        if ($v->valores === []) {
            return self::GERAL;
        }

        $definem = [];
        foreach ($eixos as $e) {
            if ($e->definesPicture && isset($v->valores[$e->chave])) {
                $definem[$e->chave] = $v->valores[$e->chave]->chave();
            }
        }

        return match (true) {
            $definem !== [] => ChaveCanonica::combinacao($definem),
            $fotosPorVariante => $v->chave,
            default => self::GERAL,
        };
    }

    /**
     * @param  list<Variante>  $variantes
     * @param  list<Eixo>  $eixos
     * @param  list<array{imagem: string, grupo: string, posicao: int}>  $atribuicoes
     */
    public static function resolver(array $variantes, array $eixos, array $atribuicoes, OpcoesImagem $op): ResolucaoImagens
    {
        $eixos = Eixo::ordenar($eixos);
        $porGrupo = self::fotosPorGrupo($atribuicoes);
        $geral = $porGrupo[self::GERAL] ?? [];
        $definemFoto = (bool) array_filter($eixos, fn (Eixo $e) => $e->definesPicture && $e->valores !== []);

        $consideradas = array_values(array_filter($variantes, fn (Variante $v) => ! $v->orfa));
        $ativas = array_values(array_filter($consideradas, fn (Variante $v) => $v->ativa));

        // As colunas da tela: um grupo por valor que define a foto (de variantes não órfãs).
        $grupos = [];
        foreach ($consideradas as $v) {
            $g = self::chaveDoGrupo($v, $eixos, $op->fotosPorVariante);
            if ($g === self::GERAL) {
                continue;
            }
            $grupos[$g] ??= ['chave' => $g, 'rotulo' => self::rotuloDoGrupo($v, $eixos, $op->fotosPorVariante), 'definePicture' => $definemFoto, 'variantes' => [], 'usadaEm' => [], 'imagens' => $porGrupo[$g] ?? []];
            if ($v->ativa) {
                $grupos[$g]['variantes'][] = $v->chave;
                $grupos[$g]['usadaEm'][] = self::rotuloSemOsEixosDoGrupo($v, $eixos);
            }
        }

        $problemas = [];
        $porVariante = [];
        $gruposSemFoto = [];

        // V-IMG-05: grupo que define a foto e não tem foto própria — a galeria geral não serve,
        // mostrar a foto de outra cor engana o comprador. Um aviso por grupo, não por variante.
        foreach ($grupos as $g) {
            if ($definemFoto && $g['variantes'] !== [] && $g['imagens'] === []) {
                $gruposSemFoto[$g['chave']] = true;
                $problemas[] = Problema::bloqueio('V-IMG-05', "Adicione ao menos 1 foto para {$g['rotulo']}.", ['grupo' => $g['chave']]);
            }
        }

        // No legado com mais de uma variante ativa o limite é por variação; um item
        // simples (ou cada item do UP) usa o limite do item.
        $porVariacao = $op->modelo === OpcoesImagem::LEGADO && count($ativas) > 1;
        $limite = $porVariacao ? $op->maxPorVariacao : $op->maxPorItem;

        foreach ($ativas as $v) {
            $g = self::chaveDoGrupo($v, $eixos, $op->fotosPorVariante);
            $proprias = $g === self::GERAL ? [] : ($porGrupo[$g] ?? []);
            $fotos = self::semRepetir([...$proprias, ...($op->incluirGeral || $g === self::GERAL ? $geral : [])]);
            $porVariante[$v->chave] = $fotos;

            if ($fotos === [] && ! isset($gruposSemFoto[$g])) {
                $problemas[] = Problema::bloqueio('V-IMG-04', $v->valores === [] ? 'Adicione ao menos 1 foto.' : 'A variação '.$v->rotulo($eixos).' está sem foto.', ['variante' => $v->chave]);
            }
            if ($limite !== null && count($fotos) > $limite) {
                $regra = $porVariacao ? 'V-IMG-06' : 'V-IMG-07';
                $problemas[] = Problema::bloqueio($regra, "Fotos demais: o limite é {$limite}. Remova ".(count($fotos) - $limite).'.', ['variante' => $v->chave, 'excedentes' => array_slice($fotos, $limite)]);
            }
        }

        $uniao = $op->modelo === OpcoesImagem::LEGADO ? self::uniaoLegado($ativas, $porVariante, $eixos, $porGrupo, $geral, $op) : [];
        if ($porVariacao && $op->maxPorItem !== null && count($uniao) > $op->maxPorItem) {
            $problemas[] = Problema::bloqueio('V-IMG-07', "O anúncio soma ".count($uniao)." fotos; o limite é {$op->maxPorItem}.", ['excedentes' => array_slice($uniao, $op->maxPorItem)]);
        }

        // V-IMG-11: foto atribuída que nenhuma variante ativa usa.
        $usadas = array_flip(array_merge([], ...array_values($porVariante)));
        foreach (self::semRepetir(array_column($atribuicoes, 'imagem')) as $id) {
            if (! isset($usadas[$id])) {
                $problemas[] = Problema::info('V-IMG-11', 'Esta foto não aparece em nenhuma variação ativa.', ['imagem' => $id]);
            }
        }

        return new ResolucaoImagens(array_values($grupos), $geral, $porVariante, $uniao, $problemas);
    }

    /**
     * `item.pictures` do legado: as fotos do grupo da 1ª variante ativa (é a
     * capa do anúncio), depois os outros grupos na ordem das variantes, por fim
     * a galeria geral — se alguma variante a usa (`06` §6).
     */
    private static function uniaoLegado(array $ativas, array $porVariante, array $eixos, array $porGrupo, array $geral, OpcoesImagem $op): array
    {
        $lista = [];
        $usaGeral = false;
        foreach ($ativas as $v) {
            $g = self::chaveDoGrupo($v, $eixos, $op->fotosPorVariante);
            if ($g === self::GERAL) {
                $usaGeral = true;

                continue;
            }
            $lista = [...$lista, ...($porGrupo[$g] ?? [])];
            $usaGeral = $usaGeral || $op->incluirGeral;
        }

        return self::semRepetir([...$lista, ...($usaGeral ? $geral : [])]);
    }

    /** @return array<string, list<string>> grupo → ids, pela posição */
    private static function fotosPorGrupo(array $atribuicoes): array
    {
        usort($atribuicoes, fn ($a, $b) => ((int) ($a['posicao'] ?? 0)) <=> ((int) ($b['posicao'] ?? 0)));

        $grupos = [];
        foreach ($atribuicoes as $a) {
            $grupos[(string) $a['grupo']][] = (string) $a['imagem'];
        }

        return array_map(fn ($ids) => self::semRepetir($ids), $grupos);
    }

    private static function rotuloDoGrupo(Variante $v, array $eixos, bool $fotosPorVariante): string
    {
        $nomes = [];
        foreach ($eixos as $e) {
            if ($e->definesPicture && isset($v->valores[$e->chave])) {
                $nomes[] = $v->valores[$e->chave]->valueName;
            }
        }

        return $nomes !== [] ? implode(' / ', $nomes) : $v->rotulo($eixos);
    }

    /** "P", "M", "G" — o que a variante tem além dos eixos que definem a foto (o "usada em" da coluna). */
    private static function rotuloSemOsEixosDoGrupo(Variante $v, array $eixos): string
    {
        $resto = [];
        foreach ($eixos as $e) {
            if (! $e->definesPicture && isset($v->valores[$e->chave])) {
                $resto[] = $v->valores[$e->chave]->valueName;
            }
        }

        return $resto !== [] ? implode(' / ', $resto) : $v->rotulo($eixos);
    }

    /** @param list<string> $ids */
    private static function semRepetir(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
