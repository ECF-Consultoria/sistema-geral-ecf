<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\MlAcervoItem;
use Illuminate\Support\Facades\DB;

/**
 * Quais anúncios da conta JÁ têm o produto no Portal (28/10/2026). A tela "Importar do Mercado
 * Livre" não pode recomendar importar o que nasceu aqui — o usuário viu isso em produção: os
 * anúncios que o próprio Portal + Publicador publicaram apareciam como "Recomendado importar".
 *
 * Três caminhos, nesta ordem de precedência:
 * 1. **importado** — veio por esta tela (`estrutura_produto_origens_ml`);
 * 2. **publicador** — o Publicador publicou a partir de um produto do Portal
 *    (`pub_publicacao_itens.ml_item_id` → publicação → rascunho → `pub_produtos.estrutura_produto_id`);
 * 3. **mapeamento** — o anúncio está ligado, no Mapeamento, à oferta de uma variação do produto
 *    (`estrutura_anuncios.codigo_mlb` → oferta com `variacao_id`). Oferta sem produto não conta: o
 *    produto ainda não existe em Produtos, e importar faz sentido.
 *
 * O produto tem de ser DESTA empresa (o MLB é único no ML, mas o produto é que diz o Portal).
 */
class AnunciosNoPortal
{
    public const IMPORTADO = 'importado';
    public const PUBLICADOR = 'publicador';
    public const MAPEAMENTO = 'mapeamento';

    /**
     * @param  list<string>|null  $mlbs  só estes; null = todos os anúncios do acervo da empresa
     * @return array<string, array{produto_id: int, nome: ?string, origem: string}>
     */
    public function daEmpresa(Company $empresa, ?array $mlbs = null): array
    {
        if ($mlbs === []) {
            return [];
        }
        $filtro = function ($q, string $coluna) use ($empresa, $mlbs) {
            $mlbs !== null
                ? $q->whereIn($coluna, $mlbs)
                : $q->whereIn($coluna, MlAcervoItem::query()->where('company_id', $empresa->id)->select('ml_item_id'));
        };

        $achados = [];
        $guardar = function (string $mlb, int $produtoId, string $origem) use (&$achados) {
            $achados[strtoupper($mlb)] ??= ['produto_id' => $produtoId, 'origem' => $origem];
        };

        DB::table('estrutura_produto_origens_ml')->where('company_id', $empresa->id)
            ->tap(fn ($q) => $filtro($q, 'ml_item_id'))
            ->get(['ml_item_id', 'produto_id'])
            ->each(fn ($r) => $guardar((string) $r->ml_item_id, (int) $r->produto_id, self::IMPORTADO));

        DB::table('pub_publicacao_itens as i')
            ->join('pub_publicacoes as p', 'p.id', '=', 'i.publicacao_id')
            ->join('pub_rascunhos as r', 'r.id', '=', 'p.rascunho_id')
            ->join('pub_produtos as pp', 'pp.id', '=', 'r.produto_id')
            ->join('estrutura_produtos as ep', 'ep.id', '=', 'pp.estrutura_produto_id')
            ->where('ep.company_id', $empresa->id)
            ->whereNotNull('i.ml_item_id')
            ->tap(fn ($q) => $filtro($q, 'i.ml_item_id'))
            ->orderBy('i.id')
            ->get(['i.ml_item_id', 'ep.id as produto_id'])
            ->each(fn ($r) => $guardar((string) $r->ml_item_id, (int) $r->produto_id, self::PUBLICADOR));

        DB::table('estrutura_anuncios as a')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'a.oferta_id')
            ->join('estrutura_produto_variacoes as v', 'v.id', '=', 'o.variacao_id')
            ->where('o.company_id', $empresa->id)
            ->whereNotNull('a.codigo_mlb')
            ->tap(fn ($q) => $filtro($q, 'a.codigo_mlb'))
            ->orderBy('a.id')
            ->get(['a.codigo_mlb', 'v.produto_id'])
            ->each(fn ($r) => $guardar((string) $r->codigo_mlb, (int) $r->produto_id, self::MAPEAMENTO));

        if ($achados === []) {
            return [];
        }
        $nomes = EstruturaProduto::query()->where('company_id', $empresa->id)
            ->whereIn('id', array_unique(array_column($achados, 'produto_id')))->pluck('nome', 'id');

        foreach ($achados as $mlb => $a) {
            $achados[$mlb]['nome'] = $nomes[$a['produto_id']] ?? null;
        }

        return $achados;
    }
}
