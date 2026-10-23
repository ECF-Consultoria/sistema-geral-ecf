<?php

namespace App\Services\Publicador;

use App\Models\PubProduto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * As FASES da vitrine (20/10/2026, decisões do usuário): o que cada produto do Publicador é na metodologia da ECF e
 * em quais cartões ele aparece.
 *
 * - Fase 1 · Simples: o produto de 1 unidade.
 * - Fase 2 · Combo: o MESMO produto × N — o kit da família no Publicador (`produto_base_id`) ou o Combo do
 *   Planejamento que virou produto à parte. Todo combo é Fase 2, qualquer que seja o N.
 * - Fase 3 · Kit: produtos DIFERENTES juntos (mesa + cadeira).
 * - Fase 4 · Combit: kit com algum item × N (mesa + 4 cadeiras).
 *
 * É a régua do "Como funciona" do Portal (`ComoFunciona.jsx`), e NÃO a coluna `pub_produtos.fase`: no Publicador ela
 * é a quantidade ("Kit N é a Fase N", 10/10/2026) e segue valendo nas outras telas. Nada aqui grava.
 *
 * Cartões: o combo vai no cartão do produto; o Kit e o Combit, no cartão de CADA componente que está no Publicador
 * (a mesma face nos dois — marcar num marca no outro, porque a seleção é pelo id). Composto sem nenhum componente
 * no Publicador fica no próprio cartão.
 *
 * Combo, Kit e Combit são decididos no Planejamento do Portal (decisão de 20/10/2026); o combo ACEITO lá que ainda não
 * é fase entra como uma face de espera na Fase 2 ({@see planejados()}): vira fase sozinho com a Fase 1 no ar.
 */
final class FasesDaVitrineService
{
    public const SIMPLES = 'simples';

    public const COMBO = 'combo';

    public const KIT = 'kit';

    public const COMBIT = 'combit';

    /** O número da fase de cada tipo — o mesmo do "Como funciona" do Portal. */
    public const NUMERO = [self::SIMPLES => 1, self::COMBO => 2, self::KIT => 3, self::COMBIT => 4];

    private const ROTULO = [self::COMBO => 'Combo', self::KIT => 'Kit', self::COMBIT => 'Combit'];

    public function __construct(private PlanejamentoDaFaseService $planejamento) {}

    /**
     * @param  Collection<int, PubProduto>  $produtos  os da vitrine, indexados pelo id, com a `oferta` carregada
     * @return array<int, array{tipo: string, numero: int, quantidade: ?int, familias: list<int>, composicao: ?string, selo: string}>
     */
    public function classificar(Collection $produtos): array
    {
        [$porProdutoDoPortal, $porOferta] = self::bases($produtos);
        $componentes = $this->componentes($produtos);

        $saida = [];
        foreach ($produtos as $id => $p) {
            $id = (int) $id;
            if ($p->produto_base_id !== null) {
                // O kit da família no Publicador é o mesmo produto × N. Base que não está na conta: cartão próprio.
                $base = (int) $p->produto_base_id;
                $saida[$id] = self::fase(self::COMBO, max(2, (int) $p->quantidade_kit), $produtos->has($base) ? [$base] : [$id], null);

                continue;
            }

            $tipo = PlanejamentoDaFaseService::tipoComposto($p);
            if ($tipo === null) {
                $saida[$id] = self::fase(self::SIMPLES, null, [$id], null);

                continue;
            }

            $doComposto = $componentes[(int) $p->oferta_id] ?? [];
            $familias = [];
            foreach ($doComposto as $c) {
                $base = ($c['produto_id'] !== null ? ($porProdutoDoPortal[$c['produto_id']] ?? null) : null) ?? ($porOferta[$c['componente_id']] ?? null);
                if ($base !== null && $base !== $id && ! in_array($base, $familias, true)) {
                    $familias[] = $base;
                }
            }
            $quantidade = $tipo === self::COMBO ? (int) ($doComposto[0]['quantidade'] ?? 0) : 0;
            $saida[$id] = self::fase($tipo, $quantidade >= 2 ? $quantidade : null, $familias !== [] ? $familias : [$id],
                $tipo === self::COMBO ? null : self::composicao($doComposto));
        }

        return $saida;
    }

    /**
     * Os Combos ACEITOS no Planejamento que ainda não são fase na família: uma face de ESPERA na Fase 2 do cartão
     * do produto. Família que já tem um combo com aquele N (kit do Publicador ou Combo do Planejamento à parte)
     * não ganha a face. Três consultas por empresa, qualquer que seja o número de produtos.
     *
     * @param  Collection<int, PubProduto>  $produtos  os da vitrine, indexados pelo id
     * @param  array<int, array{tipo: string, quantidade: ?int, familias: list<int>}>  $fases  saída de {@see classificar()}
     * @param  array<int, array{nome?: string, foto?: ?string, grupo?: string}>  $cartoes  o cartão de cada produto, pelo id
     * @return list<array<string, mixed>>
     */
    public function planejados(Collection $produtos, array $fases, array $cartoes): array
    {
        $jaTem = [];
        foreach ($fases as $f) {
            if ($f['tipo'] === self::COMBO && $f['quantidade'] !== null) {
                foreach ($f['familias'] as $familia) {
                    $jaTem[$familia][] = $f['quantidade'];
                }
            }
        }

        $bases = $produtos->filter(fn (PubProduto $p, $id) => ($fases[(int) $id]['tipo'] ?? null) === self::SIMPLES
            && $p->estrutura_produto_id !== null && $p->company_id !== null);

        $saida = [];
        foreach ($bases->groupBy('company_id') as $companyId => $daEmpresa) {
            $planejadas = $this->planejamento->quantidadesDoPlanejamentoEmLote((int) $companyId, $daEmpresa->pluck('estrutura_produto_id')->all());
            foreach ($daEmpresa as $base) {
                $id = (int) $base->id;
                $cartao = $cartoes[$id] ?? [];
                $publicada = ($cartao['grupo'] ?? null) === VitrineDeProdutosService::PUBLICADOS;
                foreach ($planejadas[(int) $base->estrutura_produto_id] ?? [] as $n) {
                    if (in_array($n, $jaTem[$id] ?? [], true)) {
                        continue;
                    }
                    $saida[] = [
                        'chave' => "planejado-{$id}-{$n}",
                        'planejado' => true,
                        'familia_id' => $id,
                        'familias' => [$id],
                        'tipo' => self::COMBO,
                        'fase_numero' => self::NUMERO[self::COMBO],
                        'quantidade' => $n,
                        'fase' => "Fase 2 · Combo {$n} un",
                        'nome' => (string) ($cartao['nome'] ?? $base->nome),
                        'composicao' => "{$n} unidades do mesmo produto",
                        'foto' => $cartao['foto'] ?? null,
                        // Com a Fase 1 no ar o anúncio é criado sozinho (`CombosDoPlanejamentoService`): a face só
                        // continua aqui se a criação não rodou ou foi pulada — o Sincronizar agenda de novo.
                        'chip' => $publicada
                            ? ['texto' => 'Aceito · ainda não é fase', 'tom' => 'ambar',
                                'detalhe' => "O Combo de {$n} foi aceito no Planejamento e a Fase 1 já está no ar: a fase é criada sozinha. Se continuar aqui, use “Sincronizar do Portal”."]
                            : ['texto' => 'Espera a Fase 1 ser publicada', 'tom' => 'neutro',
                                'detalhe' => "O Combo de {$n} foi aceito no Planejamento do Portal. Ele vira fase aqui quando a Fase 1 for publicada."],
                    ];
                }
            }
        }

        return $saida;
    }

    /** @return array{tipo: string, numero: int, quantidade: ?int, familias: list<int>, composicao: ?string, selo: string} */
    private static function fase(string $tipo, ?int $quantidade, array $familias, ?string $composicao): array
    {
        $numero = self::NUMERO[$tipo];
        $selo = match ($tipo) {
            self::SIMPLES => 'Fase 1',
            self::COMBO => $quantidade !== null ? "Fase 2 · Combo {$quantidade} un" : 'Fase 2 · Combo',
            default => "Fase {$numero} · ".self::ROTULO[$tipo],
        };

        return ['tipo' => $tipo, 'numero' => $numero, 'quantidade' => $quantidade, 'familias' => $familias, 'composicao' => $composicao, 'selo' => $selo];
    }

    /**
     * Onde mora o produto de 1 unidade de cada componente: pelo produto do Portal (o grupo das cores) ou, no legado
     * de um `pub_produto` por cor, pela oferta Simples.
     *
     * @return array{0: array<int, int>, 1: array<int, int>} produto do Portal → id; oferta Simples → id
     */
    private static function bases(Collection $produtos): array
    {
        $porProdutoDoPortal = [];
        $porOferta = [];
        foreach ($produtos as $id => $p) {
            if ($p->produto_base_id !== null || PlanejamentoDaFaseService::tipoComposto($p) !== null) {
                continue;
            }
            if ($p->estrutura_produto_id !== null) {
                $porProdutoDoPortal[(int) $p->estrutura_produto_id] ??= (int) $id;
            }
            if ($p->oferta_id !== null) {
                $porOferta[(int) $p->oferta_id] ??= (int) $id;
            }
        }

        return [$porProdutoDoPortal, $porOferta];
    }

    /**
     * Os componentes de cada oferta composta da vitrine (Kit, Combit e o Combo do Planejamento) numa consulta só,
     * na ordem da composição, cada um com o produto do Portal da variação. Só o que é da MESMA Company do produto
     * (T-172-08): componente ou variação de outra empresa nunca entra.
     *
     * @return array<int, list<array{componente_id: int, quantidade: int, produto_id: ?int, nome: string}>> oferta → componentes
     */
    private function componentes(Collection $produtos): array
    {
        $empresaDaOferta = [];
        foreach ($produtos as $p) {
            if ($p->company_id !== null && PlanejamentoDaFaseService::tipoComposto($p) !== null) {
                $empresaDaOferta[(int) $p->oferta_id] = (int) $p->company_id;
            }
        }
        if ($empresaDaOferta === []) {
            return [];
        }

        $linhas = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->join('estrutura_ofertas as s', fn ($j) => $j->on('s.id', '=', 'c.componente_id')->on('s.company_id', '=', 'o.company_id'))
            ->leftJoin('estrutura_produto_variacoes as v', fn ($j) => $j->on('v.id', '=', 's.variacao_id')->on('v.company_id', '=', 's.company_id'))
            ->whereIn('c.oferta_id', array_keys($empresaDaOferta))
            ->orderBy('c.id')
            ->get(['c.oferta_id', 'o.company_id', 'c.componente_id', 'c.quantidade', 'v.produto_id', 's.nome', 's.sku']);

        $saida = [];
        foreach ($linhas as $l) {
            if ((int) $l->company_id !== $empresaDaOferta[(int) $l->oferta_id]) {
                continue;
            }
            $saida[(int) $l->oferta_id][] = [
                'componente_id' => (int) $l->componente_id,
                'quantidade' => max(1, (int) $l->quantidade),
                'produto_id' => $l->produto_id !== null ? (int) $l->produto_id : null,
                'nome' => trim((string) ($l->nome ?: $l->sku)),
            ];
        }

        return $saida;
    }

    /** "Mesa Redonda + 4× Cadeira Jantar — Preto" */
    private static function composicao(array $componentes): ?string
    {
        $partes = [];
        foreach ($componentes as $c) {
            if ($c['nome'] !== '') {
                $partes[] = ($c['quantidade'] > 1 ? "{$c['quantidade']}× " : '').$c['nome'];
            }
        }

        return $partes === [] ? null : implode(' + ', $partes);
    }
}
