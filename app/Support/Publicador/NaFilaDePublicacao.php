<?php

namespace App\Support\Publicador;

use App\Models\PubFilaPublicacaoItem;
use Illuminate\Database\QueryException;

/**
 * "Este produto está na fila de publicação?" (10/10/2026) — o irmão do `EditorEmUso`.
 *
 * Produto `agendado` ou `publicando` numa fila foi conferido e vai ao Mercado Livre como está: o preparo
 * pela IA (salvar no Portal) e o Sincronizar do Portal NÃO escrevem no rascunho dele enquanto isso — esperam,
 * como esperam o editor aberto. Se mesmo assim algo mudar (a equipe editou), a fila não publica: o item vira
 * `precisa_revisar` (e a `PublicacaoService` ainda recusa plano diferente do conferido).
 *
 * Lê a coluna-sombra `produto_ativo`, que só vale enquanto o item vive. Sem a tabela (entre o deploy do código e
 * o `migrate`), responde "não está" — como `ExplicacaoDeAtributos::salvos`: nenhuma tela quebra por isso.
 */
final class NaFilaDePublicacao
{
    public static function emUso(int $produtoId): bool
    {
        try {
            return PubFilaPublicacaoItem::query()->where('produto_ativo', $produtoId)->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Quais destes produtos estão na fila agora — UMA consulta para a lista inteira.
     *
     * @param  list<int>  $produtoIds
     * @return array<int, true>
     */
    public static function dentre(array $produtoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $produtoIds))));
        if ($ids === []) {
            return [];
        }
        try {
            return PubFilaPublicacaoItem::query()->whereIn('produto_ativo', $ids)->pluck('produto_ativo')
                ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        } catch (QueryException) {
            return [];
        }
    }
}
