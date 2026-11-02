<?php

namespace App\Services\Trilha\Ondas;

use App\Models\EstruturaOferta;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;

/**
 * O que a trilha guarda de uma empresa, lido de uma vez (3 consultas fixas): as ondas, qual item está em qual onda e
 * os produtos mapeados. Só leitura — quem grava é {@see OndasDaTrilha} (ondas e itens) e o mapeamento da trilha.
 *
 * "Hoje" é sempre o dia em São Paulo ({@see TrilhaOnda::hoje()}), passado na construção para uma leitura inteira
 * usar o mesmo dia.
 */
final class OndasDaEmpresa
{
    /**
     * @param  array<int, TrilhaOnda>  $ondas  id → onda, na ordem da tela (data, id)
     * @param  array<string, TrilhaOndaItem>  $linhas  chave do item → linha
     * @param  array<int, TrilhaMapeamento>  $mapeamentos  produto → marca
     */
    private function __construct(
        private array $ondas,
        private array $linhas,
        private array $mapeamentos,
        public readonly string $hoje,
    ) {}

    public static function ler(int $empresaId, ?string $hoje = null): self
    {
        $ondas = TrilhaOnda::query()
            ->where('company_id', $empresaId)
            ->with('autor:id,name')
            ->get()
            ->sortBy(fn (TrilhaOnda $o) => [$o->dia(), (int) $o->id])
            ->keyBy('id')
            ->all();

        $linhas = [];
        foreach (TrilhaOndaItem::query()->where('company_id', $empresaId)->orderBy('id')->get() as $l) {
            // A linha cuja onda é de outra empresa não vale (o serviço nunca grava assim; a leitura não confia).
            if (isset($ondas[(int) $l->onda_id])) {
                // A onda já lida, para `comecoEfetivo()` não consultar de novo por item.
                $l->setRelation('onda', $ondas[(int) $l->onda_id]);
                $linhas[$l->chave()] = $l;
            }
        }

        $mapeamentos = TrilhaMapeamento::query()
            ->where('company_id', $empresaId)
            ->with('autor:id,name')
            ->get()
            ->keyBy('produto_id')
            ->all();

        return new self($ondas, $linhas, $mapeamentos, $hoje ?? TrilhaOnda::hoje());
    }

    /**
     * Sem as linhas de Combo que não são do combo de hoje: o combo apagado (todas as cores) ou apagado e criado de
     * novo depois de a linha nascer ({@see SoltarOndas::comboRecriado()}). A soltura, o planejar e o "Concluir
     * mapeamento" apagam essas linhas; até lá a tela já não as conta — o combo novo nasce "Sem onda" (revisão de
     * 02/11/2026).
     *
     * @param  array<string, array>  $itens  chave → item com `oferta_ids` (os grupos da trilha)
     */
    public function semCombosRecriados(array $itens): self
    {
        // As datas das ofertas dos combos em onda numa consulta só: a trilha monta com número FIXO de consultas,
        // qualquer que seja o número de combos (RegrasDaTrilhaRevisaoTest conta).
        $ids = [];
        foreach ($this->linhas as $chave => $l) {
            if (str_starts_with((string) $chave, 'c-') && isset($itens[$chave])) {
                array_push($ids, ...array_map('intval', $itens[$chave]['oferta_ids'] ?? []));
            }
        }
        $criadasEm = $ids === [] ? [] : EstruturaOferta::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('created_at', 'id')
            ->all();

        $linhas = array_filter($this->linhas,
            fn (TrilhaOndaItem $l, $chave) => ! str_starts_with((string) $chave, 'c-')
                || (isset($itens[$chave]) && ! SoltarOndas::comboRecriado($l, $itens[$chave], $criadasEm)),
            ARRAY_FILTER_USE_BOTH);

        return new self($this->ondas, $linhas, $this->mapeamentos, $this->hoje);
    }

    /** A onda do item, ou null quando ele não está em nenhuma. */
    public function ondaDoItem(string $chave): ?TrilhaOnda
    {
        $linha = $this->linhas[$chave] ?? null;

        return $linha !== null ? ($this->ondas[(int) $linha->onda_id] ?? null) : null;
    }

    public function linhaDoItem(string $chave): ?TrilhaOndaItem
    {
        return $this->linhas[$chave] ?? null;
    }

    /** A marca do "Concluir mapeamento" do produto, ou null. */
    public function mapeamento(int $produtoId): ?TrilhaMapeamento
    {
        return $this->mapeamentos[$produtoId] ?? null;
    }

    /** @return list<TrilhaOnda> na ordem da tela: data e, no empate, id */
    public function ondas(): array
    {
        return array_values($this->ondas);
    }

    public function onda(int $id): ?TrilhaOnda
    {
        return $this->ondas[$id] ?? null;
    }

    /**
     * O contexto da onda que a régua de status do item lê ({@see \App\Services\Trilha\SituacaoDasOfertas::doItem()}).
     *
     * `desde` é o começo efetivo do item na onda ({@see TrilhaOndaItem::comecoEfetivo()}): o dia da onda ou, se ele
     * entrou depois, a entrada.
     *
     * @return array{tem: bool, id: ?int, nome: ?string, data: ?string, comecou: bool, pedido_em: ?\Illuminate\Support\Carbon, desde: ?\Illuminate\Support\Carbon}
     */
    public function contextoDoItem(string $chave): array
    {
        $onda = $this->ondaDoItem($chave);
        $linha = $this->linhaDoItem($chave);

        return [
            'tem'       => $onda !== null,
            'id'        => $onda !== null ? (int) $onda->id : null,
            'nome'      => $onda?->nome,
            'data'      => $onda?->dia(),
            'comecou'   => $onda !== null && $onda->comecou($this->hoje),
            'pedido_em' => $linha?->pedido_em,
            'desde'     => $linha?->comecoEfetivo(),
        ];
    }
}
