<?php

namespace App\Services\Publicador\Fila;

use App\Jobs\Publicador\ConferirEmLoteJob;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\NaFilaDePublicacao;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Conferir selecionados" da publicação em lote (10/10/2026): a MESMA conferência do botão do editor, para
 * vários produtos, sem ninguém abrir um por um.
 *
 * Um `ConferirEmLoteJob` por produto, espaçados (`publicador.fila_publicacao.conferir_espaco_s`, 10 s) na fila
 * `high` — a conferência são várias chamadas ao ML por produto e 30 de uma vez seriam uma rajada. Cada Job faz o
 * que a tela faz antes de conferir: abre o rascunho (lê a conta se a leitura passou de 10 min), aplica a regra
 * do frete grátis obrigatório IGUAL à do editor (`usePublicador.garantirFreteObrigatorio`, aqui no servidor) e
 * confere; no fim recalcula o resumo de pendências que a lista de Produtos mostra (`estado()`). O resultado
 * aparece na linha da visão rápida pelo polling.
 */
final class ConferenciaEmLoteService
{
    public function __construct(
        private ProgramasPublicadorService $programas,
        private EditorRascunhoService $editor,
        private ConferenciaService $conferencia,
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
     * Um produto: abrir → frete grátis obrigatório → conferir → resumo. Nunca publica.
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
            $this->garantirFreteObrigatorio($r);
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
     * A regra do editor antes de conferir (learnings §11): Mercado Envios, frete grátis desmarcado, conta lida e
     * categoria escolhida → pergunta ao ML se a faixa de preço exige frete grátis do vendedor e, se exigir, liga.
     * Sem resposta do ML, segue como está (a conferência ainda avisa com o 350).
     */
    public function garantirFreteObrigatorio(PubRascunho $r): bool
    {
        $envio = (array) ($r->envio ?? []);
        $conta = (array) ($r->step_state['conta'] ?? []);
        if (($envio['modo'] ?? 'me2') !== 'me2' || ! empty($envio['frete_gratis']) || $conta === [] || isset($conta['erro']) || ! $r->categoria_id) {
            return false;
        }
        try {
            $regra = $this->editor->freteGratis($r);
        } catch (RegraViolada $e) {
            Log::info("[Publicador] Lote: frete grátis do rascunho {$r->id} sem consulta ({$e->regra}).");

            return false;
        }
        if (! ($regra['obrigatorio'] ?? false)) {
            return false;
        }
        $this->editor->salvar($r, ['envio' => [...$envio, 'frete_gratis' => true]]);
        Log::info("[Publicador] Lote: frete grátis ligado no rascunho {$r->id} — a faixa de preço exige (mesma regra do editor).");

        return true;
    }
}
