<?php

namespace App\Services\Publicador\Fila;

use App\Jobs\Publicador\ConferirEmLoteJob;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PadroesDoRascunhoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\NaFilaDePublicacao;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Conferir selecionados" da publicação em lote (10/10/2026): a MESMA conferência do botão do editor, para
 * vários produtos, sem ninguém abrir um por um.
 *
 * Um `ConferirEmLoteJob` por produto, espaçados (`publicador.fila_publicacao.conferir_espaco_s`, 10 s) na fila
 * `high` — a conferência são várias chamadas ao ML por produto e 30 de uma vez seriam uma rajada. Cada Job faz o
 * que a tela faz antes de conferir: abre o rascunho (lê a conta se a leitura passou de 10 min), aplica os padrões
 * que o editor aplicaria ao abrir — EAN, Cor principal, fotos por variação e a regra do frete grátis obrigatório
 * IGUAL à do editor (`PadroesDoRascunhoService`, N9 de 22/10/2026) — e confere; no fim recalcula o resumo de
 * pendências que a lista de Produtos mostra (`estado()`). O resultado aparece na linha da visão rápida pelo polling.
 */
final class ConferenciaEmLoteService
{
    public function __construct(
        private ProgramasPublicadorService $programas,
        private EditorRascunhoService $editor,
        private ConferenciaService $conferencia,
        private PadroesDoRascunhoService $padroes,
    ) {}

    /**
     * Enfileira a conferência dos produtos pedidos que são DESTA conta (os de fora são ignorados: nunca
     * conferem conta de outro). Publicado, publicando ou na fila de publicação não confere de novo.
     *
     * @param  list<int>  $produtoIds
     * @return array{enfileirados: list<int>, ignorados: array<int, string>}
     */
    public function enfileirar(array $alvo, array $produtoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $produtoIds)));
        $produtos = $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $status = PubRascunho::query()->whereIn('produto_id', $produtos->keys()->all() ?: [0])->pluck('status', 'produto_id');
        $naFila = NaFilaDePublicacao::dentre($produtos->keys()->all());

        $espaco = max(0, (int) config('publicador.fila_publicacao.conferir_espaco_s', 10));
        $enfileirados = [];
        $ignorados = [];
        foreach ($ids as $id) {
            if (! $produtos->has($id)) {
                $ignorados[$id] = 'Este produto não é desta conta.';

                continue;
            }
            if (isset($naFila[$id])) {
                $ignorados[$id] = 'Está na fila de publicação: tire da fila para conferir de novo.';

                continue;
            }
            if (in_array($status[$id] ?? null, [PubRascunho::PUBLISHED, PubRascunho::PUBLISHING], true)) {
                $ignorados[$id] = $status[$id] === PubRascunho::PUBLISHED ? 'Já publicado.' : 'Está sendo publicado agora.';

                continue;
            }

            $quando = now()->addSeconds(count($enfileirados) * $espaco);
            Cache::put(ResumoRapidoService::chaveConferindo($id), now()->toIso8601String(), now()->addSeconds(count($enfileirados) * $espaco + 900));
            ConferirEmLoteJob::dispatch($id)->delay($quando);
            $enfileirados[] = $id;
        }

        return ['enfileirados' => $enfileirados, 'ignorados' => $ignorados];
    }

    /**
     * Um produto: abrir → padrões do editor (com o frete grátis obrigatório) → conferir → resumo. Nunca publica.
     *
     * @return string o que aconteceu (log e testes): sumiu, na_fila, intocavel, conferido:<RESULTADO>
     */
    public function executar(int $produtoId): string
    {
        try {
            $p = PubProduto::find($produtoId);
            if ($p === null || $this->programas->empresaDoProduto($p) === null) {
                return 'sumiu';
            }
            if (NaFilaDePublicacao::emUso($produtoId)) {
                return 'na_fila';
            }
            $atual = PubRascunho::where('produto_id', $p->id)->value('status');
            if (in_array($atual, [PubRascunho::PUBLISHED, PubRascunho::PUBLISHING], true)) {
                return 'intocavel';
            }

            $r = $this->editor->abrir($p);
            try {
                $this->padroes->aplicar($r, comFrete: true);
            } catch (\Throwable $e) {
                Log::warning("[Publicador] Lote: padrões do editor no rascunho {$r->id} antes da conferência: {$e->getMessage()}");
            }
            $v = $this->conferencia->conferir($r->fresh());

            try {
                // O resumo de bloqueios da lista de Produtos (`step_state.resumo`) é gravado pelo estado.
                $this->editor->estado($r->fresh());
            } catch (\Throwable $e) {
                Log::warning("[Publicador] Lote: o resumo de pendências do rascunho {$r->id} não foi recalculado: {$e->getMessage()}");
            }

            return 'conferido:'.$v->resultado;
        } finally {
            Cache::forget(ResumoRapidoService::chaveConferindo($produtoId));
        }
    }

    /**
     * A regra do editor antes de conferir (learnings §11), hoje no `PadroesDoRascunhoService` (o mesmo que roda no
     * Sincronizar, no preparo pela IA e no `ConferirRascunhoJob`). Fica aqui pelo nome que o lote sempre teve.
     */
    public function garantirFreteObrigatorio(PubRascunho $r): bool
    {
        return $this->padroes->garantirFreteObrigatorio($r);
    }
}
