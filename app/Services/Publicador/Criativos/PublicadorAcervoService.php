<?php

namespace App\Services\Publicador\Criativos;

use App\Models\MlAnuncioCriativo;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use App\Support\Publicador\Validacao\Problema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 171 (D4, ACERVO-01..05) — o acervo navegável das imagens já geradas
 * pelo Creative Engine, escopado pela CONTA (nunca pelo rascunho) e com
 * reaproveitamento por CÓPIA, nunca referência compartilhada.
 *
 * `listar()` cruza rascunhos de propósito (inclusive criativos ÓRFÃOS, cujo
 * `pub_rascunho_id` foi zerado pela exclusão do rascunho de origem — a FK é
 * `nullOnDelete`): a âncora que nunca muda é `company_id`/`mlb_empresa_id`
 * do `PubProduto` AUTORIZADO, nunca um id vindo da requisição.
 *
 * `reaproveitar()` é MOLDE LITERAL de
 * `PublicadorCriativoAprovacaoService::aprovarSlot()` — mesmo caminho real
 * de "imagem gerada virou foto do rascunho"
 * (`ImagemAssetService::receber()`, que roda `ValidadorImagem::problemas()`
 * de novo, nunca pula a validação por já ter sido aprovada antes) — com uma
 * diferença deliberada: o criativo de ORIGEM nunca é tocado (`->update()`),
 * porque ele pertence a outro rascunho (ou a nenhum, se órfão) e sua própria
 * aprovação/histórico não é afetada por um reaproveitamento em OUTRO anúncio.
 */
class PublicadorAcervoService
{
    /**
     * Teto técnico de itens por listagem — volume atual de produção é baixo.
     * NÃO é paginação: a tela avisa quando `total` excede este limite.
     */
    private const LIMITE = 60;

    public function __construct(
        private ImagemAssetService $imagens,
        private EditorRascunhoService $editor,
        private CreativeSlotCatalog $catalogo,
    ) {}

    /**
     * @return array{itens: list<array>, total: int, limite: int}
     */
    public function listar(PubRascunho $atual, bool $todaConta): array
    {
        $produto = $atual->produto;

        $query = MlAnuncioCriativo::query()
            ->whereNotNull('slot_indice')
            ->whereNotNull('imagem_path')
            ->where('company_id', $produto->company_id)
            ->where('mlb_empresa_id', $produto->mlb_empresa_id);

        if (! $todaConta) {
            $query->where('pub_rascunho_id', $atual->id);
        }

        $total = (clone $query)->count();

        $criativos = (clone $query)
            ->with(['pubRascunho.produto'])
            ->orderByDesc('created_at')
            ->limit(self::LIMITE)
            ->get();

        return [
            'itens' => $criativos->map(fn (MlAnuncioCriativo $c) => $this->paraItem($c, $atual))->values()->all(),
            'total' => $total,
            'limite' => self::LIMITE,
        ];
    }

    /** @return array{id: int, imagem_url: string, rotulo: string, criado_em: ?string, aprovada: bool, do_produto_atual: bool, produto_nome: ?string} */
    private function paraItem(MlAnuncioCriativo $c, PubRascunho $atual): array
    {
        $padrao = $this->catalogo->padraoDe((string) $c->slot) ?? [];
        $produtoDoItem = $c->pubRascunho?->produto;

        return [
            'id' => $c->id,
            // O {produto} da URL é sempre o da PÁGINA ATUAL, nunca o produto de origem do item
            // (mesma disciplina de endereçamento de PublicadorCriativoKitPresenter::paraTela()).
            'imagem_url' => route('mlb.anuncios.publicador.acervo.imagem', ['produto' => $atual->produto_id, 'criativo' => $c->id]),
            'rotulo' => $padrao['rotulo'] ?? $c->slot,
            // REND-02: nunca o objeto Carbon cru para a tela.
            'criado_em' => $c->created_at?->format('d/m/Y'),
            'aprovada' => $c->status === MlAnuncioCriativo::STATUS_APROVADO,
            'do_produto_atual' => $c->pub_rascunho_id !== null && (int) $c->pub_rascunho_id === (int) $atual->id,
            // null tanto para órfão (pubRascunho nulo) quanto para qualquer falha de resolução — nunca lança.
            'produto_nome' => $produtoDoItem?->nomeExibido(),
        ];
    }

    /**
     * Copia os bytes da imagem GERADA de `$origem` para uma `PubImagem` NOVA
     * do rascunho `$destino` — nunca referencia a `pub_imagem` original, e
     * nunca altera `$origem` (slot/criativo de outro rascunho).
     *
     * @return array{ok: bool, mensagem: ?string, imagem_id: ?int, repetida: bool}
     */
    public function reaproveitar(PubRascunho $destino, MlAnuncioCriativo $origem, string $grupo, User $u): array
    {
        if (in_array($destino->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
            return $this->recusa('Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
        }

        $disco = Storage::disk('local');
        if ($origem->imagem_path === null || ! $disco->exists($origem->imagem_path)) {
            return $this->recusa('Esta imagem do acervo não foi encontrada.');
        }

        // FORA de qualquer transação: pode fazer HTTP ao Mercado Livre (D26), igual a aprovarSlot().
        $res = $this->imagens->receber($destino, $disco->get($origem->imagem_path), "acervo-{$origem->token}.jpg");
        if ($res['imagem'] === null) {
            $bloqueio = collect($res['problemas'])->first(fn (Problema $p) => $p->bloqueia());
            $mensagem = $bloqueio !== null
                ? EditorRascunhoService::problemaParaTela($bloqueio)['mensagem']
                : 'A imagem do acervo não pôde ser usada como foto.';

            return $this->recusa($mensagem);
        }

        $this->editor->colocarFotoNoGrupo($destino, $res['imagem'], $grupo);

        // GEN-05: nunca o texto do grupo cru (pode ser até 600 caracteres de texto arbitrário) —
        // só os ids para rastrear quem reaproveitou o quê.
        Log::info("[Creative] Publicador: acervo — criativo {$origem->id} reaproveitado no rascunho {$destino->id}");

        // NUNCA $origem->update(...) — o criativo de origem pertence a outro rascunho (ou a
        // nenhum, se órfão) e a própria aprovação/histórico dele não é tocada por este reuso.
        return ['ok' => true, 'mensagem' => null, 'imagem_id' => $res['imagem']->id, 'repetida' => ! $res['nova']];
    }

    /** @return array{ok: bool, mensagem: ?string, imagem_id: ?int, repetida: bool} */
    private function recusa(string $mensagem): array
    {
        return ['ok' => false, 'mensagem' => $mensagem, 'imagem_id' => null, 'repetida' => false];
    }
}
