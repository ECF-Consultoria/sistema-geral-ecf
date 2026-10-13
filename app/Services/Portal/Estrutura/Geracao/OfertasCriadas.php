<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;

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
            ->with(['componentes.componente:id,sku,nome'])
            ->orderByDesc('id')
            ->limit(self::TETO)
            ->get(['id', 'sku', 'fase', 'nome', 'created_at']);

        $calculos = $ofertas->isEmpty() ? [] : $this->precos->pagina($empresa, $ofertas->pluck('id')->all())['por_oferta'];

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

    private function consulta(Company $empresa)
    {
        return EstruturaOferta::query()->where('company_id', $empresa->id)->whereIn('fase', array_keys(self::TIPOS));
    }
}
