<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAcervoItem;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;

/**
 * As ofertas compostas que a empresa JÁ tem — os combos, kits e combits aceitos ou montados no
 * Planejamento — para a aba "Criadas" (13/10/2026).
 *
 * O usuário: "em Planejamento deveriam ficar os produtos que planejamos… aceitou, ele fica ali". Até aqui a
 * sugestão aceita sumia da tela (as abas eram só Pendentes, Sem tipo e Descartadas) e o único rastro era um
 * aviso de alguns segundos; quem montava um combo não tinha onde vê-lo de novo dentro do Planejamento.
 *
 * Nada é gravado e nada novo é guardado: a lista sai de `estrutura_ofertas` + componentes, e o preço e o
 * envio de cada uma saem da mesma conta da Precificação ({@see EstruturaPrecificacaoService::pagina()}),
 * para as duas telas nunca discordarem. "Criada" é toda oferta composta da empresa, venha de onde vier —
 * a tabela não guarda a origem (ver o activity log para "veio do Planejamento").
 *
 * 16/10/2026 — cada componente leva a sua `foto`. O usuário, vendo a lista só com códigos: "não dá para saber
 * quais são os produtos, quais foram os kits… visualmente pela foto". A foto é a capa da galeria da variação
 * (a mesma da lista de Produtos) e, sem galeria, a miniatura do anúncio no acervo do Mercado Livre — nada é
 * buscado na API.
 */
class OfertasCriadas
{
    /** A ordem dos grupos na tela. */
    private const TIPOS = [
        EstruturaOferta::FASE_COMBO  => 'Combos',
        EstruturaOferta::FASE_KIT    => 'Kits',
        EstruturaOferta::FASE_COMBIT => 'Combits',
    ];

    /** Acima disto a tela mostra as mais recentes e avisa; a Precificação tem a lista inteira, paginada. */
    public const TETO = 300;

    public function __construct(private EstruturaPrecificacaoService $precos) {}

    public function contar(Company $empresa): int
    {
        return $this->consulta($empresa)->count();
    }

    /**
     * @return array{total: int, mostrando: int, grupos: list<array{fase: string, rotulo: string, itens: list<array<string, mixed>>}>}
     */
    public function listar(Company $empresa): array
    {
        $total = $this->contar($empresa);
        $ofertas = $this->consulta($empresa)
            ->with(['componentes.componente:id,sku,nome,variacao_id'])
            ->orderByDesc('id')
            ->limit(self::TETO)
            ->get(['id', 'sku', 'fase', 'nome', 'created_at']);

        $calculos = $ofertas->isEmpty() ? [] : $this->precos->pagina($empresa, $ofertas->pluck('id')->all())['por_oferta'];
        $fotos = $this->fotos($empresa, $ofertas->flatMap(fn ($o) => $o->componentes->pluck('componente'))->filter()->unique('id')->values()->all());

        $grupos = [];
        foreach (self::TIPOS as $fase => $rotulo) {
            $itens = [];
            foreach ($ofertas->where('fase', $fase) as $o) {
                $c = $calculos[$o->id] ?? null;
                $itens[] = [
                    'id'         => (int) $o->id,
                    'sku'        => $o->sku,
                    'nome'       => $o->nome,
                    'fase'       => $o->fase,
                    'criada_em'  => $o->created_at?->toIso8601String(),
                    'composicao' => $o->componentes->map(fn ($x) => [
                        'id'         => (int) $x->componente_id,
                        'sku'        => $x->componente?->sku,
                        'nome'       => $x->componente?->nome,
                        'quantidade' => (int) $x->quantidade,
                        'foto'       => $fotos[(int) $x->componente_id] ?? null,
                    ])->values()->all(),
                    // A pendência do preço (null = precificada) e o envio, como a Precificação os mostra.
                    'pendencia'  => $c['pendencia'] ?? null,
                    'sem_calculo' => $c === null,
                    'envio'      => $c['envio'] ?? null,
                    'preco'      => $c === null ? null : [
                        'classico' => $c['classico']['anunciado'] ?? null,
                        'premium'  => $c['premium']['anunciado'] ?? null,
                    ],
                ];
            }
            if ($itens !== []) {
                $grupos[] = ['fase' => $fase, 'rotulo' => $rotulo, 'itens' => $itens];
            }
        }

        return ['total' => $total, 'mostrando' => $ofertas->count(), 'grupos' => $grupos];
    }

    /**
     * A foto de cada componente, em duas consultas para a lista inteira: a capa da galeria da variação
     * (ordem 0, como em {@see \App\Services\Portal\Estrutura\Produtos\ProdutoLinhas}) e, para quem não tem
     * galeria, a miniatura do acervo do primeiro anúncio ativo com MLB. Sem nenhuma das duas, fica de fora.
     *
     * @param  list<EstruturaOferta>  $componentes
     * @return array<int, string> oferta_id do componente => URL
     */
    private function fotos(Company $empresa, array $componentes): array
    {
        if ($componentes === []) {
            return [];
        }

        $capaDaVariacao = [];
        $variacoes = array_values(array_unique(array_filter(array_map(fn ($c) => $c->variacao_id ? (int) $c->variacao_id : null, $componentes))));
        if ($variacoes !== []) {
            EstruturaProdutoVariacaoImagem::query()
                ->where('company_id', $empresa->id)
                ->whereIn('variacao_id', $variacoes)
                ->orderBy('variacao_id')->orderBy('ordem')->orderBy('id')
                ->get(['id', 'variacao_id'])
                ->each(function (EstruturaProdutoVariacaoImagem $i) use (&$capaDaVariacao) {
                    $capaDaVariacao[(int) $i->variacao_id] ??= VariacaoImagensService::url((int) $i->variacao_id, (int) $i->id);
                });
        }

        $fotos = [];
        $semGaleria = [];
        foreach ($componentes as $c) {
            $capa = $c->variacao_id ? ($capaDaVariacao[(int) $c->variacao_id] ?? null) : null;
            if ($capa !== null) {
                $fotos[(int) $c->id] = $capa;
            } else {
                $semGaleria[] = (int) $c->id;
            }
        }

        if ($semGaleria !== []) {
            $anuncios = EstruturaAnuncio::query()
                ->whereIn('oferta_id', $semGaleria)
                ->where('status', '!=', EstruturaAnuncio::STATUS_INATIVO)
                ->whereNotNull('codigo_mlb')
                ->orderBy('id')
                ->get(['oferta_id', 'codigo_mlb']);

            $miniaturas = $anuncios->isEmpty() ? collect() : MlAcervoItem::query()
                ->where('company_id', $empresa->id)
                ->whereIn('ml_item_id', $anuncios->pluck('codigo_mlb')->unique()->values()->all())
                ->whereNotNull('thumbnail')
                ->pluck('thumbnail', 'ml_item_id');

            foreach ($anuncios as $a) {
                $miniatura = $miniaturas[$a->codigo_mlb] ?? null;
                if ($miniatura && ! isset($fotos[(int) $a->oferta_id])) {
                    $fotos[(int) $a->oferta_id] = preg_replace('#^http://#', 'https://', $miniatura);
                }
            }
        }

        return $fotos;
    }

    private function consulta(Company $empresa)
    {
        return EstruturaOferta::query()->where('company_id', $empresa->id)->whereIn('fase', array_keys(self::TIPOS));
    }
}
