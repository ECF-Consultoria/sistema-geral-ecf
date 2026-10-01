<?php

namespace App\Support\Publicador\Variacao;

/**
 * Refaz as variantes depois de uma mudança de eixos ou valores, sem perder o
 * que a pessoa digitou (`05` §4).
 *
 * | Mudança                 | Efeito                                                                  |
 * |-------------------------|-------------------------------------------------------------------------|
 * | valor novo              | chaves novas nascem vazias; as existentes ficam intactas                |
 * | valor removido          | as variantes dele viram órfãs desativadas, com os dados                 |
 * | eixo novo               | `copiarDados`: cada nova herda da antiga que ela contém (127V → 127V/Preto); senão começa vazia |
 * | eixo removido           | as que colapsam se juntam se tiverem os mesmos dados; se divergirem, é conflito e nada é somado |
 * | variante publicada      | nunca é descartada — no máximo vira órfã                                |
 *
 * Função pura: entra a lista de hoje, sai a de amanhã. Quem grava é o
 * repositório; quem resolve conflito e órfã é a pessoa (V-VAR-17).
 */
final class RegeneradorVariantes
{
    /**
     * @param  list<Variante>  $atuais
     * @param  list<Eixo>  $eixos
     */
    public static function regenerar(array $atuais, array $eixos, bool $copiarDados = true): ResultadoRegeneracao
    {
        $porChave = [];
        foreach ($atuais as $v) {
            $porChave[$v->chave] = $v;
        }

        $eixosComValor = array_map(fn (Eixo $e) => $e->chave, array_filter($eixos, fn (Eixo $e) => $e->valores !== []));
        sort($eixosComValor);

        $novas = [];
        $usadas = [];
        $absorvidas = [];
        $emConflito = [];
        $conflitos = [];

        foreach (GeradorCombinacoes::gerar($eixos) as $combo) {
            if (isset($porChave[$combo->chave])) {
                // A mesma chave continua existindo. Uma órfã que volta (o valor foi
                // readicionado) volta ativa: a pessoa acabou de pedir aquele valor.
                $v = $porChave[$combo->chave];
                $novas[] = $v->orfa ? $v->comOrfa(false)->comAtiva(true) : $v;
                $usadas[$combo->chave] = true;

                continue;
            }

            $nova = Variante::daCombinacao($combo);

            if ($copiarDados) {
                $pares = array_map(fn (ValorEixo $v) => $v->chave(), $combo->valores);

                $ancestral = self::ancestral($atuais, $pares);
                if ($ancestral) {
                    $nova = Variante::daCombinacao($combo, $ancestral->dados, $ancestral->ativa);
                    $absorvidas[$ancestral->chave] = true;
                } elseif ($descendentes = self::descendentes($atuais, $pares)) {
                    $assinaturas = array_unique(array_map(fn (Variante $d) => self::assinatura($d->dados), $descendentes));
                    if (count($assinaturas) === 1) {
                        $ativa = array_reduce($descendentes, fn (bool $a, Variante $d) => $a || $d->ativa, false);
                        $nova = Variante::daCombinacao($combo, $descendentes[0]->dados, $ativa);
                        foreach ($descendentes as $d) {
                            $absorvidas[$d->chave] = true;
                        }
                    } else {
                        $conflitos[$combo->chave] = array_map(fn (Variante $d) => $d->chave, $descendentes);
                        foreach ($descendentes as $d) {
                            $emConflito[$d->chave] = true;
                        }
                    }
                }
            }

            $novas[] = $nova;
        }

        $orfas = [];
        $descartadas = [];
        foreach ($atuais as $v) {
            if (isset($usadas[$v->chave])) {
                continue;
            }

            $eixosDela = array_keys($v->valores);
            sort($eixosDela);
            $eixosMudaram = $eixosDela !== $eixosComValor;

            // Sai só o que não é publicado, não está esperando a pessoa resolver
            // um conflito, e cujos dados passaram adiante — ou cujo eixo mudou
            // e a pessoa pediu para começar vazio. Valor removido vira órfã.
            $descartar = ! $v->publicada
                && ! isset($emConflito[$v->chave])
                && (isset($absorvidas[$v->chave]) || (! $copiarDados && $eixosMudaram));

            if ($descartar) {
                $descartadas[] = $v->chave;
            } else {
                $orfas[] = $v->comOrfa(true)->comAtiva(false);
            }
        }

        return new ResultadoRegeneracao([...$novas, ...$orfas], $conflitos, $descartadas);
    }

    /** A variante de hoje mais próxima que a combinação nova CONTÉM (um eixo foi acrescentado). */
    private static function ancestral(array $atuais, array $pares): ?Variante
    {
        $melhor = null;
        foreach ($atuais as $v) {
            if (self::contida($v->pares(), $pares) && ($melhor === null || count($v->valores) > count($melhor->valores))) {
                $melhor = $v;
            }
        }

        return $melhor;
    }

    /** As variantes de hoje que CONTÊM a combinação nova (um eixo foi removido). */
    private static function descendentes(array $atuais, array $pares): array
    {
        return array_values(array_filter($atuais, fn (Variante $v) => self::contida($pares, $v->pares())));
    }

    /** `$a` está estritamente contida em `$b` (mesmos eixos com os mesmos valores, e menos eixos). */
    private static function contida(array $a, array $b): bool
    {
        return count($a) < count($b) && array_intersect_assoc($a, $b) === $a;
    }

    /** Dados comparáveis independentemente da ordem das chaves. */
    private static function assinatura(array $dados): string
    {
        $ordenar = function (array $d) use (&$ordenar) {
            ksort($d);

            return array_map(fn ($x) => is_array($x) ? $ordenar($x) : $x, $d);
        };

        return json_encode($ordenar($dados));
    }
}
