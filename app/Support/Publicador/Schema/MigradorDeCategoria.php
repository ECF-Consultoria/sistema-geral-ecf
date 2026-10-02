<?php

namespace App\Support\Publicador\Schema;

use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;

/**
 * Troca de categoria (RN-21, TC-71): reaproveita só o que existe e vale no
 * schema novo — marcado `migrated` e "a revisar" — e devolve, com o nome que
 * a pessoa conhecia, a lista do que ficou para trás e por quê.
 *
 * Eixo que deixa de ser elegível sai; as variantes se refazem depois pelo
 * {@see \App\Support\Publicador\Variacao\RegeneradorVariantes}.
 */
final class MigradorDeCategoria
{
    /**
     * @param  array<string, array>  $atributosProduto  attribute_id → valor
     * @param  list<Eixo>  $eixos
     */
    public static function migrar(SchemaClassificado $anterior, SchemaClassificado $novo, array $atributosProduto, array $eixos): ResultadoMigracao
    {
        $mantidos = [];
        $descartados = [];
        foreach ($atributosProduto as $id => $valor) {
            $nome = $anterior->atributo($id)?->nome ?? $novo->atributo($id)?->nome ?? $id;
            $alvo = $novo->atributo($id);

            if ($alvo === null) {
                $descartados[] = ['id' => $id, 'nome' => $nome, 'motivo' => 'nao_existe', 'regra' => 'RN-21', 'mensagem' => "«{$nome}» não existe na categoria nova."];
            } elseif (! $alvo->editavel()) {
                $descartados[] = ['id' => $id, 'nome' => $nome, 'motivo' => 'sistema', 'regra' => 'RN-14', 'mensagem' => "Na categoria nova, «{$nome}» é preenchido pelo Mercado Livre."];
            } elseif ($problema = ValorAtributo::problema($alvo, $valor)) {
                $descartados[] = ['id' => $id, 'nome' => $nome, 'motivo' => 'valor_invalido', 'regra' => $problema['regra'], 'mensagem' => $problema['mensagem']];
            } else {
                $mantidos[$id] = [...$valor, 'origem' => 'migrated', 'revisar' => true];
            }
        }

        $eixosMantidos = [];
        $eixosRemovidos = [];
        foreach (Eixo::ordenar($eixos) as $eixo) {
            if ($eixo->ehCustomizado()) {
                if (self::nomeColide($eixo->nome, $novo)) {
                    $eixosRemovidos[] = ['chave' => $eixo->chave, 'nome' => $eixo->nome, 'motivo' => 'conflito_com_atributo'];
                } else {
                    $eixosMantidos[] = $eixo;
                }

                continue;
            }

            $alvo = $novo->atributo($eixo->chave);
            if ($alvo === null || ! $alvo->podeSerEixo) {
                $eixosRemovidos[] = ['chave' => $eixo->chave, 'nome' => $eixo->nome, 'motivo' => 'nao_e_eixo'];

                continue;
            }

            // Fica com o nome e o defines_picture da categoria nova, e só com os valores que valem lá.
            $valores = array_values(array_filter($eixo->valores, fn (ValorEixo $v) => ValorAtributo::problema($alvo, ['value_id' => $v->valueId, 'value_name' => $v->valueName]) === null));
            $eixosMantidos[] = new Eixo($eixo->chave, $alvo->nome, 0, $alvo->definePicture, $valores);
        }

        return new ResultadoMigracao(
            $mantidos,
            $descartados,
            array_map(fn (Eixo $e, int $i) => $e->naPosicao($i), $eixosMantidos, array_keys($eixosMantidos)),
            $eixosRemovidos,
        );
    }

    private static function nomeColide(string $nome, SchemaClassificado $novo): bool
    {
        $texto = ChaveCanonica::texto($nome);
        foreach ($novo->atributos as $a) {
            if ($texto === ChaveCanonica::texto($a->id) || $texto === ChaveCanonica::texto($a->nome)) {
                return true;
            }
        }

        return false;
    }
}
