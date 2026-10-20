<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Log;

/**
 * O combo planejado no Portal vira anúncio sozinho (decisões do usuário de 20/10/2026).
 *
 * Criar Combo, Kit e Combit é SÓ no Planejamento do Portal; o Publicador monta o anúncio de cada um. O Combo N
 * (o mesmo produto × N) vai ao ML como o Kit N da família — UM anúncio com as cores como variação — e nasce
 * quando a Fase 1 do produto está publicada (a regra do KIT-05 virou gatilho). Dois caminhos chegam aqui:
 * o fim da publicação da Fase 1 (`PublicacaoService`) e o Sincronizar, quando o Combo é planejado depois de a
 * Fase 1 já estar no ar (`PublicadorSincronizaPortalService`). Os dois só AGENDAM ({@see agendar()}); o Job
 * confere tudo de novo e cria ({@see criarOsQueFaltam()}).
 *
 * O kit nasce como o "Criar Fase N" o criava — o MESMO `CriarFaseService`, com o que a `PreviaDaFaseService`
 * calcula (SKU, título por tipo, descrição, SKU de cada cor, estoque) —, com três diferenças:
 * - **só as cores com o Combo N no Portal ficam ativas** (o Portal decide quais composições existem; a cor sem
 *   Combo N fica inativa no kit, e nada é criado no Portal);
 * - **cor cujo Combo N já está no ar como produto avulso fica de fora** (o Sincronizar de antes criava um
 *   produto por oferta; o kit com a mesma cor duplicaria o anúncio no ML);
 * - **sem capa do conjunto** (a imagem por IA tem custo e é da equipe pedir, no editor do kit).
 * Depois de criar, o estado do editor é calculado uma vez, para a lista e a vitrine já mostrarem o que falta
 * (sem isso o kit que ninguém abriu apareceria "Pronto").
 *
 * Produto que não é do Portal (Polos sem empresa cadastrada, produto criado direto no Publicador) segue com o
 * "Criar Fase N" de sempre, à mão. Nada aqui publica: o kit nasce rascunho, para a equipe revisar.
 */
final class CombosDoPlanejamentoService
{
    /** Os status da Fase 1 que contam como "no ar" — os mesmos do gate do "Criar Fase N" (KIT-05). */
    public const PUBLICADO = [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED];

    /** Por que o "Criar Fase N" não existe para o produto do Portal (KIT-08 e a tela do Produto). */
    public const MOTIVO_PORTAL = 'Este produto vem do Portal: combo, kit e combit se criam no Planejamento do Portal, '
        .'e o anúncio do combo nasce sozinho quando a Fase 1 é publicada.';

    /** Quem criou o rascunho do kit (`pub_rascunhos.ator`): ninguém clicou, foi o Planejamento. */
    public const ATOR = ['origem' => 'planejamento_do_portal', 'nome' => 'Automático (Planejamento do Portal)'];

    public function __construct(
        private PlanejamentoDaFaseService $planejamento,
        private PreviaDaFaseService $previas,
        private CriarFaseService $clone,
    ) {}

    /** O produto de 1 unidade que veio do Portal (o grupo das cores): o combo dele se planeja lá. */
    public static function doPortal(PubProduto $p): bool
    {
        return $p->estrutura_produto_id !== null && $p->produto_base_id === null;
    }

    /**
     * Põe na fila a criação dos combos do produto, se ele é do Portal e a Fase 1 está no ar. Depois do commit:
     * quem chama pode estar dentro de uma transação, e o Job precisa ler o que ela gravou.
     *
     * @return bool se agendou
     */
    public function agendar(int $produtoId): bool
    {
        $p = PubProduto::query()->with('rascunho:id,produto_id,status')->find($produtoId);
        if ($p === null || ! self::doPortal($p) || ! in_array((string) $p->rascunho?->status, self::PUBLICADO, true)) {
            return false;
        }

        CriarCombosDoPlanejamentoJob::dispatch((int) $p->id)->afterCommit();

        return true;
    }

    /**
     * Cria o Kit N de cada Combo N do Planejamento que a família ainda não tem.
     *
     * @return array{criados: list<array{quantidade: int, produto_id: int, cores: list<string>}>, pulados: list<array{quantidade: int, motivo: string}>}
     */
    public function criarOsQueFaltam(PubProduto $base): array
    {
        $saida = ['criados' => [], 'pulados' => []];
        if (! self::doPortal($base) || PlanejamentoDaFaseService::tipoComposto($base) !== null
            || ! in_array((string) $base->rascunho?->status, self::PUBLICADO, true)) {
            return $saida;
        }

        $naFamilia = $base->familia()->where('produto_base_id', $base->id)->pluck('quantidade_kit')->map(fn ($q) => (int) $q)->all();
        foreach ($this->planejamento->quantidadesDoPlanejamento($base) as $n) {
            if (in_array($n, $naFamilia, true)) {
                continue;
            }

            $previa = $this->previas->previa($base->fresh(), $n);
            [$ativas, $cores, $noAr] = $this->coresDoKit($base, $previa);
            if ($ativas === []) {
                $saida['pulados'][] = ['quantidade' => $n, 'motivo' => $noAr !== []
                    ? 'o Combo '.$n.' de '.implode(', ', $noAr).' já está no ar como produto avulso'
                    : 'nenhuma cor ativa do produto tem o Combo '.$n.' no Portal'];

                continue;
            }

            try {
                $kit = $this->clone->criar($base->fresh(), [
                    'quantidade' => $n,
                    'sku' => $previa['sku'],
                    'titulo_por_tipo' => $previa['titulo_por_tipo'],
                    'descricao' => $previa['descricao'],
                    'seller_skus' => array_map(fn (array $v) => $v['seller_sku'], $previa['variantes']),
                    'estoque_por_variante' => array_map(fn (array $v) => ['estoque' => $v['estoque'], 'depositos' => $v['depositos']], $previa['variantes']),
                    'variantes_ativas' => $ativas,
                    'ator' => self::ATOR,
                    'capa' => false,
                ]);
            } catch (RegraViolada $e) {
                // KIT-04 é a corrida com o outro gatilho (o mesmo Kit N nasceu entre a leitura e a criação): já está feito.
                $saida['pulados'][] = ['quantidade' => $n, 'motivo' => "{$e->regra}: {$e->getMessage()}"];

                continue;
            }

            $this->calcularEstado($kit);
            $saida['criados'][] = ['quantidade' => $n, 'produto_id' => (int) $kit->id, 'cores' => $cores];
            Log::info("[Publicador] Combo {$n} do Planejamento virou o Kit {$n} (produto {$kit->id}) do produto {$base->id} ({$base->nome}), com "
                .count($cores).' cor(es): '.implode(', ', $cores).($noAr !== [] ? '; fora, já no ar como avulso: '.implode(', ', $noAr) : '').'.');
        }

        foreach ($saida['pulados'] as $p) {
            Log::info("[Publicador] Combo {$p['quantidade']} do Planejamento do produto {$base->id} não virou kit: {$p['motivo']}.");
        }

        return $saida;
    }

    /**
     * As cores que entram ativas no kit: a variante ativa do base cuja cor tem o Combo N no Portal, menos a cor
     * cujo Combo N já está no ar (ou publicando) como produto avulso.
     *
     * @param  array{variantes: array<string, array>}  $previa
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} chaves ativas, as cores delas, as cores já no ar
     */
    private function coresDoKit(PubProduto $base, array $previa): array
    {
        $ofertaDaChave = [];
        foreach ($previa['variantes'] as $chave => $v) {
            $plano = $v['planejamento'] ?? null;
            if (($v['ativa'] ?? false) && is_array($plano) && ! $plano['nova'] && $plano['oferta_id'] !== null) {
                $ofertaDaChave[(string) $chave] = (int) $plano['oferta_id'];
            }
        }
        if ($ofertaDaChave === []) {
            return [[], [], []];
        }

        $noAr = PubProduto::query()->where('company_id', $base->company_id)->whereNull('produto_base_id')
            ->whereIn('oferta_id', array_values($ofertaDaChave))
            ->whereHas('rascunho', fn ($q) => $q->whereIn('status', [...self::PUBLICADO, PubRascunho::PUBLISHING]))
            ->pluck('oferta_id')->map(fn ($id) => (int) $id)->all();

        $ativas = [];
        $cores = [];
        $fora = [];
        foreach ($ofertaDaChave as $chave => $oferta) {
            $cor = (string) $previa['variantes'][$chave]['planejamento']['cor'];
            if (in_array($oferta, $noAr, true)) {
                $fora[] = $cor;

                continue;
            }
            $ativas[] = $chave;
            $cores[] = $cor;
        }

        return [$ativas, $cores, $fora];
    }

    /** O resumo do que falta (lista e vitrine). Falhar aqui não desfaz o kit: abrir o editor recalcula. */
    private function calcularEstado(PubProduto $kit): void
    {
        try {
            $r = $kit->rascunho()->first();
            if ($r !== null) {
                app(EditorRascunhoService::class)->estado($r);
            }
        } catch (\Throwable $e) {
            Log::warning("[Publicador] Kit {$kit->id} criado do Planejamento, mas o resumo do editor não foi calculado: {$e->getMessage()}");
        }
    }
}
