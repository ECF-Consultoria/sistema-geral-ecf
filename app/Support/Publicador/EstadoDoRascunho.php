<?php

namespace App\Support\Publicador;

use App\Models\PubRascunho;
use Illuminate\Support\Facades\DB;

/**
 * A porta para gravar o `step_state` do rascunho sem apagar o que outro processo gravou (23/10/2026, teste do
 * Publicador, P-04). O jeito antigo — `$r->update(['step_state' => [...$r->step_state, chave => …]])` — regravava o
 * JSON INTEIRO a partir da cópia lida no começo da requisição, muitas vezes depois de segundos de chamada ao Mercado
 * Livre: o `kit_id` das imagens automáticas, o `portal_escrito`, o andamento da IA e a marca de imagens adiadas que um
 * Job gravasse nesse meio sumiam. E o `update` tocava o `updated_at`, que é o "Atualizado há…" e a ordem da vitrine —
 * só abrir o editor punha o produto no topo.
 *
 * Aqui: relê do banco sob a trava da linha, muda só o que a função pede e grava sem tocar `updated_at` nem revisão
 * (estado do editor não é edição do anúncio). O modelo em mãos, se vier, fica com o estado gravado.
 */
final class EstadoDoRascunho
{
    /**
     * @param  callable(array): array  $mudar  recebe o estado ATUAL do banco e devolve o novo
     * @return array o estado gravado
     */
    public static function alterar(PubRascunho|int $rascunho, callable $mudar): array
    {
        $id = $rascunho instanceof PubRascunho ? (int) $rascunho->id : (int) $rascunho;
        $novo = DB::transaction(function () use ($id, $mudar) {
            $atual = json_decode((string) DB::table('pub_rascunhos')->where('id', $id)->lockForUpdate()->value('step_state'), true) ?: [];
            $novo = $mudar($atual);
            if ($novo !== $atual) {
                DB::table('pub_rascunhos')->where('id', $id)->update(['step_state' => json_encode($novo, JSON_UNESCAPED_UNICODE)]);
            }

            return $novo;
        });
        if ($rascunho instanceof PubRascunho) {
            $rascunho->setAttribute('step_state', $novo);
            $rascunho->syncOriginalAttribute('step_state');
        }

        return $novo;
    }

    /** Uma chave só do `step_state`; `null` apaga a chave. */
    public static function gravar(PubRascunho|int $rascunho, string $chave, mixed $valor): array
    {
        return self::alterar($rascunho, function (array $estado) use ($chave, $valor) {
            if ($valor === null) {
                unset($estado[$chave]);
            } else {
                $estado[$chave] = $valor;
            }

            return $estado;
        });
    }
}
