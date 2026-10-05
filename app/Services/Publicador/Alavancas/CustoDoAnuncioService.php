<?php

namespace App\Services\Publicador\Alavancas;

use App\Models\EstruturaAnuncio;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use Illuminate\Support\Facades\DB;

/**
 * D-11 — margem só com custo do Portal; sem custo, nada de pedir para digitar.
 * Caminho oficial: MLB → estrutura_anuncios.codigo_mlb → oferta (da Company da tela).
 * Reserva: anúncio publicado pelo Publicador que ainda não virou codigo_mlb
 * (pub_publicacao_itens → … → pub_produtos.oferta_id). A ligação é por MLB, nunca por
 * SKU (SKU repetido gera mais de uma oferta).
 *
 * `codigo_mlb` é gravado normalizado ("MLB" + dígitos, sem hífen), igual ao id do ML
 * ({@see EstruturaAnuncio::normalizarMlb()}).
 */
class CustoDoAnuncioService
{
    public function __construct(private EstruturaPrecificacaoService $precificacao) {}

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array{custo: float, imposto_percentual: float, oferta_id: int}>
     */
    public function custos(ContaAlavanca $c, array $itemIds): array
    {
        // MlbEmpresa sem Company: não há Precificação, logo não há margem.
        $company = $c->company;
        if ($company === null) {
            return [];
        }

        $ids = array_values(array_unique(array_filter($itemIds, fn ($id) => is_string($id) && preg_match('/^MLB\d{1,17}$/D', $id))));
        if ($ids === []) {
            return [];
        }

        // MLB → oferta. Primeiro o caminho oficial, depois a reserva só para o que faltou.
        $ofertaDoMlb = [];
        $oficiais = EstruturaAnuncio::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_anuncios.oferta_id')
            ->where('o.company_id', $company->id)
            ->whereIn('estrutura_anuncios.codigo_mlb', $ids)
            ->get(['estrutura_anuncios.codigo_mlb as mlb', 'estrutura_anuncios.oferta_id as oferta_id']);
        foreach ($oficiais as $l) {
            $ofertaDoMlb[(string) $l->mlb] ??= (int) $l->oferta_id;
        }

        $faltam = array_values(array_diff($ids, array_keys($ofertaDoMlb)));
        if ($faltam !== []) {
            $reserva = DB::table('pub_publicacao_itens as i')
                ->join('pub_publicacoes as p', 'p.id', '=', 'i.publicacao_id')
                ->join('pub_rascunhos as r', 'r.id', '=', 'p.rascunho_id')
                ->join('pub_produtos as pp', 'pp.id', '=', 'r.produto_id')
                ->where('pp.company_id', $company->id)
                ->whereNotNull('pp.oferta_id')
                ->whereIn('i.ml_item_id', $faltam)
                ->get(['i.ml_item_id as mlb', 'pp.oferta_id as oferta_id']);
            foreach ($reserva as $l) {
                $ofertaDoMlb[(string) $l->mlb] ??= (int) $l->oferta_id;
            }
        }

        if ($ofertaDoMlb === []) {
            return [];
        }

        // A Precificação percorre a empresa inteira: UMA chamada por análise.
        $pagina = $this->precificacao->pagina($company, array_values(array_unique($ofertaDoMlb)));

        $saida = [];
        foreach ($ofertaDoMlb as $mlb => $ofertaId) {
            $calculo = $pagina['por_oferta'][$ofertaId] ?? null;
            $custo = $calculo['custo']['valor'] ?? null;
            if ($custo === null) {
                continue;
            }
            $saida[$mlb] = [
                'custo' => (float) $custo,
                'imposto_percentual' => (float) ($calculo['excecoes']['imposto'] ?? $pagina['parametros']['imposto'] ?? 0),
                'oferta_id' => $ofertaId,
            ];
        }

        return $saida;
    }
}
