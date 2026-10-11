<?php

namespace App\Services\Publicador;

use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\Fila\ResumoRapidoService;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\NaFilaDePublicacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Excluir produtos do Publicador, um ou vários (pedido do usuário, 10/10/2026: limpar os produtos de teste).
 * Só o que NUNCA foi publicado: o que já virou anúncio no Mercado Livre fica — é o único registro do que foi
 * enviado.
 *
 * ### Quando pode
 * 1. **Solto do Portal** (`oferta_id` e `estrutura_produto_id` nulos). O que ainda existe no Portal VOLTA:
 *    o Sincronizar recria o produto no próximo save do cliente (~15 s) ou no próximo clique — por isso a ordem é
 *    excluir no Portal primeiro (lá o item daqui fica solto, D27) e depois aqui.
 * 2. **Nada que possa existir no ML**: decide pelo FATO, não só pelo status do rascunho. Uma publicação que deu
 *    timeout ou 5xx vira `FAILED` e devolve o rascunho a `DRAFT` sem saber se o anúncio nasceu
 *    ({@see itemPodeEstarNoMl}).
 * 3. **Ninguém mexendo agora**: fora da fila de publicação, sem conferência em lote rodando e sem editor aberto.
 * 4. **Base de kit** (a Fase 1 de um kit da Fase N) só sai junto com os kits — a FK é SET NULL e soltaria o
 *    kit calado. Por isso os kits vão primeiro.
 *
 * ### O que vai junto
 * O rascunho e tudo dele caem pela cascata do banco (títulos, atributos, variantes, preços, fotos, conferências e
 * as publicações que falharam sem criar anúncio). Os ARQUIVOS das fotos não caem na cascata: são apagados aqui,
 * depois do commit. Itens já terminados da fila, criativos e análises de IA ficam sem vínculo (SET NULL).
 *
 * Cada produto em transação própria (a falha de um não desfaz os outros), travando o rascunho e depois o
 * produto — a mesma ordem de `PublicacaoService::iniciar`, para não cruzar travas.
 */
final class ExcluirProdutoService
{
    /** Teto de produtos por pedido; a tela manda a seleção inteira. */
    public const MAXIMO = 200;

    private const DISCO = 'local';

    public function __construct(private ProgramasPublicadorService $programas) {}

    /**
     * O que sai e o que fica, sem tocar em nada.
     *
     * @param  array{mlb_empresa?: mixed, company?: mixed}  $alvo  saída de `ProgramasPublicadorService::resolver`
     * @param  array<int, int|string>  $produtoIds
     * @return array{
     *     excluiveis: list<array{id: int, sku: ?string, nome: string, kit: bool, fotos: int}>,
     *     recusados: list<array{id: int, sku: ?string, nome: string, regra: string, motivo: string}>,
     *     totais: array{excluiveis: int, recusados: int, fotos: int},
     *     nao_encontrados: int,
     * }
     */
    public function previa(array $alvo, array $produtoIds): array
    {
        $ids = $this->ids($produtoIds);
        $produtos = $this->produtos($alvo, $ids);
        $rascunhos = PubRascunho::query()->whereIn('produto_id', $produtos->pluck('id'))->get()->keyBy('produto_id');

        $excluiveis = [];
        $recusados = [];
        $saem = [];   // ids que passaram: o base só sai se TODOS os kits dele estiverem aqui
        foreach ($this->kitsPrimeiro($produtos) as $p) {
            $r = $rascunhos->get($p->id);
            $motivo = $this->impedimento($p, $r, $saem);
            if ($motivo !== null) {
                $recusados[] = ['id' => (int) $p->id, 'sku' => $p->sku, 'nome' => (string) $p->nome, ...$motivo];

                continue;
            }
            $saem[(int) $p->id] = true;
            $excluiveis[] = [
                'id'    => (int) $p->id,
                'sku'   => $p->sku,
                'nome'  => (string) $p->nome,
                'kit'   => $p->produto_base_id !== null,
                'fotos' => $r === null ? 0 : $r->imagens()->count(),
            ];
        }

        return [
            'excluiveis' => $excluiveis,
            'recusados'  => $recusados,
            'totais'     => ['excluiveis' => count($excluiveis), 'recusados' => count($recusados), 'fotos' => array_sum(array_column($excluiveis, 'fotos'))],
            'nao_encontrados' => count($ids) - $produtos->count(),
        ];
    }

    /**
     * Exclui o que pode ser excluído e diz por que o resto ficou.
     *
     * @param  array{mlb_empresa?: mixed, company?: mixed, chave?: string}  $alvo
     * @param  array<int, int|string>  $produtoIds
     * @return array{excluidos: list<int>, recusados: list<array{id: int, sku: ?string, nome: string, regra: string, motivo: string}>, nao_encontrados: int}
     */
    public function excluir(array $alvo, array $produtoIds, User $quem): array
    {
        $ids = $this->ids($produtoIds);
        $produtos = $this->produtos($alvo, $ids);

        $excluidos = [];
        $recusados = [];
        foreach ($this->kitsPrimeiro($produtos) as $p) {
            $r = $this->excluirUm((int) $p->id, $alvo, $quem);
            if ($r === null) {
                $excluidos[] = (int) $p->id;
            } else {
                $recusados[] = ['id' => (int) $p->id, 'sku' => $p->sku, 'nome' => (string) $p->nome, ...$r];
            }
        }

        return ['excluidos' => $excluidos, 'recusados' => $recusados, 'nao_encontrados' => count($ids) - $produtos->count()];
    }

    /**
     * O item de uma publicação pode existir no Mercado Livre? Criado, enviado ou sem resposta: pode. Falha com
     * recusa certa (4xx) ou que nem saiu daqui (nenhuma tentativa): não. Falha por timeout ou 5xx, depois de
     * tentar: pode — o ML às vezes cria e responde erro.
     */
    public static function itemPodeEstarNoMl(string $status, ?string $mlItemId, int $tentativas, ?int $httpStatus): bool
    {
        if ($mlItemId !== null && trim($mlItemId) !== '') {
            return true;
        }
        if (in_array($status, [PubPublicacaoItem::CREATED, PubPublicacaoItem::SENT, PubPublicacaoItem::UNKNOWN], true)) {
            return true;
        }
        if ($status === PubPublicacaoItem::FAILED && $tentativas >= 1) {
            return $httpStatus === null || $httpStatus < 400 || $httpStatus >= 500;
        }

        return false;
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * Por que este produto não pode sair agora (null = pode). A primeira regra que casar.
     *
     * @param  array<int, true>  $saemJunto  ids que saem no mesmo pedido (os kits de um base contam como já fora)
     * @return array{regra: string, motivo: string}|null
     */
    private function impedimento(PubProduto $p, ?PubRascunho $r, array $saemJunto = []): ?array
    {
        $id = (int) $p->id;
        $recusa = fn (string $regra, string $motivo) => ['regra' => $regra, 'motivo' => $motivo];

        if ($r !== null) {
            if (in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true)) {
                return $recusa('EXC-02', 'Já tem anúncio no Mercado Livre e não pode ser excluído.');
            }
            $publicacoes = PubPublicacao::query()->where('rascunho_id', $r->id)->get(['id', 'status']);
            if ($r->status === PubRascunho::PUBLISHING || $publicacoes->contains('status', PubPublicacao::RUNNING)) {
                return $recusa('EXC-03', 'Está sendo publicado agora.');
            }
            $itens = $publicacoes->isEmpty() ? collect() : PubPublicacaoItem::query()
                ->whereIn('publicacao_id', $publicacoes->pluck('id'))->get(['status', 'ml_item_id', 'tentativas', 'http_status']);
            if ($itens->contains(fn ($i) => $i->status === PubPublicacaoItem::CREATED || trim((string) $i->ml_item_id) !== '')) {
                return $recusa('EXC-02', 'Já tem anúncio no Mercado Livre e não pode ser excluído.');
            }
            if ($itens->contains(fn ($i) => self::itemPodeEstarNoMl((string) $i->status, $i->ml_item_id, (int) $i->tentativas, $i->http_status === null ? null : (int) $i->http_status))) {
                return $recusa('EXC-04', 'Uma publicação deste produto ficou sem confirmação do Mercado Livre. Confira lá se o anúncio existe antes de excluir.');
            }
        }
        // Defesa: tarefa de alavancas e promoção só nascem de produto publicado.
        if (DB::table('pub_tarefas')->where('produto_id', $id)->exists() || DB::table('pub_promocoes_automaticas')->where('produto_id', $id)->exists()) {
            return $recusa('EXC-02', 'Já tem anúncio no Mercado Livre e não pode ser excluído.');
        }
        if (NaFilaDePublicacao::emUso($id)) {
            return $recusa('EXC-05', 'Está na fila de publicação: tire da fila antes de excluir.');
        }
        if ($p->oferta_id !== null || $p->estrutura_produto_id !== null) {
            return $recusa('EXC-01', 'Ainda existe no Portal do Cliente. Exclua lá primeiro: apagado só aqui, ele voltaria no próximo Sincronizar.');
        }
        // SELECT à parte, nunca subconsulta dentro do DELETE da própria tabela (erro 1093 do MariaDB).
        $kits = PubProduto::query()->where('produto_base_id', $id)->pluck('id')->map(fn ($k) => (int) $k);
        $ficam = $kits->reject(fn (int $k) => isset($saemJunto[$k]))->count();
        if ($ficam > 0) {
            return $recusa('EXC-06', $ficam === 1
                ? 'É a base de 1 kit: exclua o kit junto ou desfaça o vínculo antes.'
                : "É a base de {$ficam} kits: exclua os kits junto ou desfaça o vínculo antes.");
        }
        if (Cache::has(ResumoRapidoService::chaveConferindo($id))) {
            return $recusa('EXC-07', 'Está sendo conferido com o Mercado Livre agora. Tente de novo em instantes.');
        }
        if (EditorEmUso::emUso($id)) {
            return $recusa('EXC-08', 'O editor deste produto está aberto (ou foi aberto há poucos minutos). Feche e tente de novo.');
        }

        return null;
    }

    /**
     * Exclui um produto na transação dele. Devolve o motivo da recusa, ou null quando saiu.
     *
     * @return array{regra: string, motivo: string}|null
     */
    private function excluirUm(int $id, array $alvo, User $quem): ?array
    {
        $caminhos = [];
        $retrato = [];

        $recusa = DB::transaction(function () use ($id, &$caminhos, &$retrato) {
            // Rascunho antes do produto: a mesma ordem de quem publica e de quem preenche.
            $r = PubRascunho::query()->where('produto_id', $id)->lockForUpdate()->first();
            $p = PubProduto::query()->whereKey($id)->lockForUpdate()->first();
            if ($p === null) {
                return ['regra' => 'EXC-00', 'motivo' => 'Não existe mais.'];
            }
            $motivo = $this->impedimento($p, $r);
            if ($motivo !== null) {
                return $motivo;
            }

            $caminhos = $r === null ? [] : $r->imagens()->whereNotNull('caminho')->pluck('caminho')->all();
            $retrato = [
                'produto_id' => $id, 'sku' => $p->sku, 'nome' => $p->nome, 'origem' => $p->origem,
                'kit' => $p->produto_base_id !== null, 'quantidade_kit' => $p->quantidade_kit,
                'rascunho_id' => $r?->id, 'categoria_id' => $r?->categoria_id, 'status' => $r?->status,
                'titulos' => $r === null ? [] : $r->alvos()->pluck('titulo', 'listing_type_id')->filter()->all(),
                'fotos' => $r === null ? 0 : $r->imagens()->count(),
                'conferencias' => $r === null ? 0 : $r->validacoes()->count(),
                'publicacoes_sem_anuncio' => $r === null ? 0 : $r->publicacoes()->count(),
            ];

            // Condicional: se alguém ligou o produto ao Portal ou o pôs na fila entre a checagem e aqui, não sai.
            $apagados = DB::table('pub_produtos')->where('id', $id)
                ->whereNull('oferta_id')->whereNull('estrutura_produto_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from((new PubFilaPublicacaoItem)->getTable())->whereColumn('produto_ativo', 'pub_produtos.id'))
                ->delete();
            if ($apagados !== 1) {
                return ['regra' => 'EXC-00', 'motivo' => 'Mudou enquanto você confirmava. Confira de novo.'];
            }

            return null;
        });

        if ($recusa !== null) {
            return $recusa;
        }

        // Banco confirmado: agora os arquivos (fora da cascata) e as marcas de uso.
        foreach ($caminhos as $caminho) {
            rescue(fn () => Storage::disk(self::DISCO)->delete($caminho), fn (\Throwable $e) => Log::warning("[Publicador] Produto {$id} excluído, mas a foto {$caminho} ficou no disco: ".$e->getMessage()), false);
        }
        if ($retrato['rascunho_id'] !== null) {
            rescue(function () use ($retrato) {
                $pasta = "publicador/{$retrato['rascunho_id']}";
                if (Storage::disk(self::DISCO)->exists($pasta) && Storage::disk(self::DISCO)->allFiles($pasta) === []) {
                    Storage::disk(self::DISCO)->deleteDirectory($pasta);
                }
            }, null, false);
        }
        Cache::forget(EditorEmUso::chave($id));
        Cache::forget(ResumoRapidoService::chaveConferindo($id));

        $conta = (string) ($alvo['chave'] ?? '');
        Log::info("[Publicador] Produto {$id} ({$retrato['sku']} — {$retrato['nome']}) da conta {$conta} excluído por {$quem->name} (#{$quem->id}): rascunho ".($retrato['rascunho_id'] ?? 'nenhum').", {$retrato['fotos']} foto(s), {$retrato['conferencias']} conferência(s), {$retrato['publicacoes_sem_anuncio']} publicação(ões) sem anúncio.");
        rescue(fn () => activity('publicador')->causedBy($quem)->withProperties([...$retrato, 'conta' => $conta, 'evento' => 'produto_excluido'])
            ->log("Produto {$retrato['nome']} excluído do Publicador"), null, false);

        return null;
    }

    /** Os produtos DESTA conta entre os pedidos; id de outra conta conta como id que não existe. */
    private function produtos(array $alvo, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $this->programas->produtosQuery($alvo['mlb_empresa'] ?? null, $alvo['company'] ?? null)->whereIn('id', $ids)->orderBy('id')->get();
    }

    /** Os kits antes das bases: com os kits fora, a base deixa de ser base. */
    private function kitsPrimeiro(Collection $produtos): Collection
    {
        return $produtos->sortBy(fn (PubProduto $p) => [$p->produto_base_id === null ? 1 : 0, (int) $p->id])->values();
    }

    /** @return list<int> ids positivos, sem repetição */
    private function ids(array $valores): array
    {
        $ids = [];
        foreach ($valores as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[(int) $v] = true;
            }
        }

        return array_keys($ids);
    }
}
