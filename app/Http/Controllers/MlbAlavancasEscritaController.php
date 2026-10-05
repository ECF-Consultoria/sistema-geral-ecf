<?php

namespace App\Http\Controllers;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\ContaAlavanca;
use App\Services\Publicador\Alavancas\ContextoAlavancas;
use App\Services\Publicador\Alavancas\PreviaAlavancasService;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Fase 166 — escrita das Alavancas: prévia assinada e confirmação (D-04).
 * A recusa é do SERVIDOR (D-03): 403 com {message, regra}; a tela só desliga o botão.
 * Só admins (o grupo de rotas aplica role:admin); a conta vem do resolver, nunca do corpo.
 */
class MlbAlavancasEscritaController extends Controller
{
    public function __construct(
        private ContextoAlavancas $contexto,
        private PreviaAlavancasService $previa,
    ) {}

    public function previa(Request $request, string $conta): JsonResponse
    {
        $dados = $request->validate([
            'acao' => ['required', 'string', 'max:32'],
            'itens' => ['required', 'array', 'min:1'],
        ]);

        return $this->comConta($conta, fn (ContaAlavanca $c) => response()->json(
            $this->previa->previa($c, $request->user(), $dados['acao'], $dados['itens']),
        ));
    }

    public function confirmar(Request $request, string $conta): JsonResponse
    {
        // Sem assinatura a trava ainda é avaliada e gravada (RECUSADA) antes de qualquer outra coisa.
        $dados = $request->validate([
            'acao' => ['required', 'string', 'max:32'],
            'itens' => ['required', 'array', 'min:1'],
            'assinatura' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->comConta($conta, function (ContaAlavanca $c) use ($request, $dados) {
            $r = $this->previa->confirmar($c, $request->user(), $dados['acao'], $dados['itens'], $dados['assinatura'] ?? null);

            if ($r['tipo'] === 'recusado') {
                return response()->json([
                    'message' => AlavancasLiberadas::MOTIVO,
                    'regra' => AlavancasLiberadas::REGRA,
                    'escritas' => array_map(fn (PubAlavancaEscrita $l) => $this->paraTela($l), $r['linhas']),
                ], 403);
            }

            if ($r['tipo'] === 'lote') {
                return response()->json([
                    'lote' => $r['lote'],
                    'total' => $r['total'],
                    'url' => route('mlb.anuncios.publicador.alavancas.lotes', ['conta' => $c->chaveTela, 'lote' => $r['lote']]),
                ], 202);
            }

            $linha = $r['escrita'];
            $tela = $this->paraTela($linha);
            if ($linha->resultado === PubAlavancaEscrita::RECUSADA) {
                $trava = in_array($linha->erro_codigo, [AlavancasLiberadas::REGRA, 'V-ACC-03'], true);

                return response()->json(
                    ['message' => $linha->mensagem, 'regra' => $linha->erro_codigo, 'escrita' => $tela],
                    $trava ? 403 : 422,
                );
            }

            // OK, ERRO e INCERTO: a requisição foi processada; a tela mostra o resultado e a mensagem.
            return response()->json(['escrita' => $tela]);
        });
    }

    /** Acompanhamento do lote pela contagem por resultado — só da empresa da tela (IDOR). */
    public function lote(string $conta, string $lote): JsonResponse
    {
        $alvo = $this->contexto->resolver($conta);
        abort_if($alvo === null, 404);

        $linhas = PubAlavancaEscrita::query()->daEmpresa($alvo['mlb_empresa'], $alvo['company'])
            ->where('lote_uuid', $lote)->orderBy('id')->get();
        abort_if($linhas->isEmpty(), 404);

        $por = array_fill_keys(PubAlavancaEscrita::RESULTADOS, 0);
        foreach ($linhas as $l) {
            $por[$l->resultado] = ($por[$l->resultado] ?? 0) + 1;
        }

        return response()->json([
            'total' => $linhas->count(),
            'por_resultado' => $por,
            'terminado' => $por[PubAlavancaEscrita::PENDENTE] === 0,
            'itens' => $linhas->map(fn (PubAlavancaEscrita $l) => [
                'item_id' => $l->item_id,
                'resultado' => $l->resultado,
                'mensagem' => $l->mensagem,
            ])->values(),
        ]);
    }

    private function paraTela(PubAlavancaEscrita $l): array
    {
        return [
            'id' => $l->id,
            'resultado' => $l->resultado,
            'item_id' => $l->item_id,
            'acao' => $l->acao,
            'alavanca' => $l->alavanca,
            'mensagem' => $l->mensagem,
            'http_status' => $l->http_status,
            'erro_codigo' => $l->erro_codigo,
            'promotion_id' => $l->promotion_id,
        ];
    }

    /**
     * Resolve a conta e roda o passo. Conta sem token = 409 V-ACC-01; RegraViolada = 422
     * (403 para a trava e o vendedor; 409 para a assinatura já usada); falha inesperada = 502.
     */
    private function comConta(string $conta, \Closure $fn): JsonResponse
    {
        $alvo = $this->contexto->resolver($conta);
        abort_if($alvo === null, 404);

        $ctx = $this->contexto->daTela($alvo);
        if ($ctx === null) {
            return response()->json([
                'message' => 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.',
                'regra' => 'V-ACC-01',
            ], 409);
        }

        try {
            return $fn($ctx);
        } catch (RegraViolada $e) {
            $status = match (true) {
                in_array($e->regra, [AlavancasLiberadas::REGRA, 'V-ACC-03'], true) => 403,
                $e->regra === 'ALAV-ASSIN-USADA' => 409,
                default => 422,
            };

            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra, 'contexto' => $e->contexto], $status);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning("[Alavancas] escrita {$alvo['chave']} (".request()->route()?->getName().'): '.$e->getMessage());

            return response()->json(['message' => 'Não deu para concluir agora. Nada foi confirmado; tente de novo em instantes.'], 502);
        }
    }
}
