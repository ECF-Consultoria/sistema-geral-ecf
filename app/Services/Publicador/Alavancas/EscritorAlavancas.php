<?php

namespace App\Services\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Models\User;
use App\Services\Publicador\Alavancas\Acoes\AcaoAlavanca;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Log;

/**
 * O ÚNICO caminho de escrita das Alavancas ao ML (AL166-05, CR-B01 da 164).
 * Ordem: linha PENDENTE → trava (sem HTTP) → vendedor (/users/me) → validar → preparo (GET)
 * → enviado_em → escrita (sem repetir em 5xx/rede; 423 até N envios) → resultado.
 * Nenhuma outra classe chama daConta com método diferente de GET — há teste de fonte.
 */
class EscritorAlavancas
{
    /** POSTs que só CONSULTAM (não escrevem no ML); lista fechada. */
    public const CONSULTAS_POR_POST = ['/prices-per-quantity/v1/recommendations'];

    private \Closure $dormir;

    /** @var array<string, true> contas cujo vendedor já foi conferido nesta instância */
    private array $vendedoresConferidos = [];

    public function __construct(
        private ClienteMlPublicador $cliente,
        private LeitorContaAlavancas $leitor,
        private CacheAlavancas $cache,
        ?\Closure $dormir = null,
    ) {
        $this->dormir = $dormir ?? fn (int $segundos) => sleep($segundos);
    }

    /** Cria a linha PENDENTE (existe antes de qualquer HTTP). */
    public function abrirLinha(AcaoAlavanca $acao, User $ator, ?string $lote = null): PubAlavancaEscrita
    {
        $conta = $acao->conta();

        return PubAlavancaEscrita::create([
            'lote_uuid' => $lote,
            'mlb_empresa_id' => $conta->mlbEmpresa?->id,
            'company_id' => $conta->company?->id,
            'conta_chave' => $conta->chaveConta(),
            'ml_seller_id' => $conta->sellerId,
            'user_id' => $ator->id,
            'ator_nome' => mb_substr((string) $ator->name, 0, 120),
            'alavanca' => $acao->alavanca(),
            'acao' => $acao::nome(),
            'promotion_type' => $acao->promotionType(),
            'promotion_id' => $acao->promotionId(),
            'item_id' => $acao->itemId(),
            'payload' => ['dados' => $acao->dados()],
            'resultado' => PubAlavancaEscrita::PENDENTE,
        ]);
    }

    public function executar(AcaoAlavanca $acao, User $ator, ?string $lote = null, ?PubAlavancaEscrita $linha = null): PubAlavancaEscrita
    {
        $linha ??= $this->abrirLinha($acao, $ator, $lote);

        if ($linha->resultado !== PubAlavancaEscrita::PENDENTE) {
            return $linha;
        }
        // Reentrega de job: a escrita pode ter saído; nunca reenvia.
        if ($linha->enviado_em !== null) {
            return $linha->marcar(PubAlavancaEscrita::INCERTO, [
                'mensagem' => 'A escrita pode ter saído antes de uma interrupção; confira o estado no Mercado Livre antes de repetir.',
            ])->fresh();
        }

        $conta = $acao->conta();

        try {
            AlavancasLiberadas::exigir($conta->conta);
            $this->conferirVendedor($conta);
            $acao->validar();

            if ($preparo = $acao->preparo()) {
                $r = $this->cliente->daConta($conta->conta, $preparo->metodo, $preparo->caminho, $preparo->query, $preparo->corpo, true, $preparo->cabecalhos);
                if (! $r->ok()) {
                    return $this->falhar($linha, $acao, $r, $preparo->caminho, PubAlavancaEscrita::ERRO);
                }
                $acao->aplicarPreparo($r);
            }

            $req = $acao->escrita();
            if (! $req->ehEscrita()) {
                throw new \LogicException('A escrita da ação precisa de método diferente de GET.');
            }

            // Gravado ANTES do HTTP: se o processo cair daqui para frente, a linha mostra que pode ter saído.
            $linha->update([
                'metodo' => $req->metodo,
                'caminho' => mb_substr($req->caminho, 0, 255),
                'payload' => ['dados' => $acao->dados(), ...$req->paraHistorico()],
                'enviado_em' => now(),
            ]);

            $r = $this->enviar($conta, $req);

            if ($acao->sucesso($r)) {
                $campos = ['http_status' => $r->status, 'resposta' => $this->resposta($r)];
                if (($criada = $acao->promotionIdCriada($r)) !== null) {
                    $campos['promotion_id'] = mb_substr($criada, 0, 40);
                }
                $linha->marcar(PubAlavancaEscrita::OK, $campos);
                $this->cache->invalidar($conta);
                $this->darBaixaNaTarefa($linha);

                return $linha->fresh();
            }

            $resultado = ($r->status === 0 || $r->status >= 500) ? PubAlavancaEscrita::INCERTO : PubAlavancaEscrita::ERRO;
            $this->falhar($linha, $acao, $r, $req->caminho, $resultado);
            Log::warning("[Alavancas] {$acao::nome()} {$acao->itemId()} conta {$conta->chaveConta()} → {$resultado} HTTP {$r->status}");

            return $linha->fresh();
        } catch (RegraViolada $e) {
            $linha->marcar(PubAlavancaEscrita::RECUSADA, [
                'erro_codigo' => mb_substr($e->regra, 0, 80),
                'mensagem' => mb_substr($e->getMessage(), 0, 500),
                'enviado_em' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error("[Alavancas] {$acao::nome()} quebrou: ".$e->getMessage());
            $linha->refresh();
            if ($linha->enviado_em !== null) {
                $linha->marcar(PubAlavancaEscrita::INCERTO, ['erro_codigo' => 'ALAV-INTERNO', 'mensagem' => 'Erro depois de enviar; confira o estado no Mercado Livre antes de repetir.']);
            } else {
                $linha->marcar(PubAlavancaEscrita::ERRO, ['erro_codigo' => 'ALAV-INTERNO', 'mensagem' => 'Erro interno antes de enviar; nada foi enviado.']);
            }
        }

        return $linha->fresh();
    }

    /**
     * POST que só CONSULTA (ex.: recomendações de preço por quantidade). Não escreve no ML,
     * por isso não tem linha de histórico — mas segue a mesma trava e o mesmo vendedor (D-03).
     */
    public function consultaPorPost(ContaAlavanca $conta, string $caminho, array $corpo): RespostaMl
    {
        if (! in_array($caminho, self::CONSULTAS_POR_POST, true)) {
            throw new \LogicException("POST de consulta fora da lista fechada: {$caminho}");
        }
        AlavancasLiberadas::exigir($conta->conta);
        $this->conferirVendedor($conta);

        return $this->cliente->daConta($conta->conta, 'POST', $caminho, [], $corpo, true);
    }

    /** Envia sem repetir às cegas: 5xx/rede não repetem; 423 repete até o limite de envios. */
    private function enviar(ContaAlavanca $conta, RequisicaoMl $req): RespostaMl
    {
        $limite = (int) config('publicador.alavancas.limites.tentativas_423', 3);
        $envios = 0;

        do {
            $r = $this->cliente->daConta($conta->conta, $req->metodo, $req->caminho, $req->query, $req->corpo, false, $req->cabecalhos);
            $envios++;
            $repetir = $r->status === 423 && $envios < $limite;
            if ($repetir) {
                ($this->dormir)((int) config('publicador.alavancas.limites.espera_423_segundos', 2));
            }
        } while ($repetir);

        return $r;
    }

    /**
     * 09/10/2026 — escrita OK num MLB de tarefa pós-publicação aberta: o item do checklist ganha a baixa
     * (`TarefasPosPublicacao::baixaPorEscrita`). A escrita JÁ saiu e está OK no histórico: falhar aqui
     * só fica no log, nunca muda o resultado da linha nem repete nada.
     */
    private function darBaixaNaTarefa(PubAlavancaEscrita $linha): void
    {
        try {
            app(TarefasPosPublicacao::class)->baixaPorEscrita($linha->fresh());
        } catch (\Throwable $e) {
            Log::warning("[Alavancas] escrita {$linha->id} OK, mas a baixa da tarefa pós-publicação falhou: {$e->getMessage()}");
        }
    }

    /** O token gravado precisa ser do vendedor da âncora (V-ACC-03). */
    private function conferirVendedor(ContaAlavanca $conta): void
    {
        $chave = $conta->chaveConta();
        if (isset($this->vendedoresConferidos[$chave])) {
            return;
        }

        $vendedor = (string) $this->leitor->ler($conta, fresco: true)['seller_id'];
        $doToken = (string) $conta->conta->mlToken?->ml_user_id;
        if ($vendedor === '' || $vendedor !== $doToken || $vendedor !== $conta->sellerId) {
            throw new RegraViolada('V-ACC-03', 'A conexão desta empresa agora é de outro vendedor do Mercado Livre. Nada foi enviado: reconecte a conta certa e confira de novo.');
        }
        $this->vendedoresConferidos[$chave] = true;
    }

    /** Fecha a linha com a resposta crua e a tradução do erro. */
    private function falhar(PubAlavancaEscrita $linha, AcaoAlavanca $acao, RespostaMl $r, string $caminho, string $resultado): PubAlavancaEscrita
    {
        $erro = MapeadorErroAlavanca::traduzir($r, $caminho);

        return $linha->marcar($resultado, [
            'http_status' => $r->status,
            'resposta' => $this->resposta($r),
            'erro_codigo' => $erro['codigo'] !== null ? mb_substr($erro['codigo'], 0, 80) : null,
            'mensagem' => mb_substr($erro['mensagem'], 0, 500),
        ])->fresh();
    }

    /** O corpo cru: array fica como veio; texto vira {"_texto": ...}; nulo fica nulo. */
    private function resposta(RespostaMl $r): ?array
    {
        if (is_array($r->corpo)) {
            return $r->corpo;
        }
        if ($r->corpo === null) {
            return null;
        }

        return ['_texto' => mb_substr((string) $r->corpo, 0, 5000)];
    }
}
