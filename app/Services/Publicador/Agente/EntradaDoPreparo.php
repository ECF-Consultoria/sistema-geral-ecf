<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Log;

/**
 * A entrada ÚNICA do preparo pelo agente (AG-02, 20/10/2026): o save no Portal, o "Preparar agora" e a
 * varredura agendada (AG-06) chegam todos aqui. Não é um segundo produtor de IA: quem gera texto continua
 * sendo `PreparoIaDoRascunhoService::avaliarIa()` — com o hash dos fatos, as etapas em andamento e a regra de
 * só escrever em campo vazio ou no último valor da própria IA (edição da equipe fica).
 *
 * O que esta classe acrescenta, nesta ordem:
 *  1. elegibilidade ANTES da IA — produto incompleto vira execução `bloqueada` com os motivos, sem gasto;
 *  2. uma execução VIVA por produto (`ExecucoesDoAgente`) — save + "Preparar agora" juntos não abrem duas
 *     cadeias: só quem ADMITIU chama a IA;
 *  3. fatos que mudaram com a execução ainda viva atualizam a mesma execução e pedem o preparo de novo (a
 *     cadeia antiga fica `superado` sozinha, pelo hash).
 *
 * Conta sem o agente ligado não muda nada: `aoPreparar()` devolve null e o preparo segue como antes.
 * Conta com o agente ligado, mas pausada/suspensa, NÃO chama a IA pelo caminho antigo — quem decide é o agente.
 */
class EntradaDoPreparo
{
    public function __construct(
        private ExecucoesDoAgente $execucoes,
        private ElegibilidadeDoAgente $elegibilidade,
    ) {}

    /**
     * @return array{execucao: PubAgenteExecucao, nova: bool, elegibilidade: array}
     *
     * @throws RegraViolada quando a política da loja recusa (desligado, pausado, outra conta, escopo, janela)
     */
    public function pedir(array $conta, PubProduto $pub, string $origem, ?User $quem = null): array
    {
        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->first();
        $eleg = $this->elegibilidade->avaliar($pub, $cfg);

        $r = $this->execucoes->admitir($conta, $pub, $origem, $quem, $eleg['versao_dos_fatos']);
        $execucao = $r['execucao'];

        if ($r['nova']) {
            $this->seguir($execucao, $pub, $eleg);
        } elseif ($execucao->viva() && $execucao->versao_dos_fatos !== $eleg['versao_dos_fatos']) {
            // Fatos novos com a execução no meio: a mesma execução segue com os fatos de agora.
            $execucao->update([
                'versao_dos_fatos' => $eleg['versao_dos_fatos'],
                'chave_logica' => ExecucoesDoAgente::chaveLogica($conta['chave'], $pub->id, $cfg?->modalidade, $eleg['versao_dos_fatos']),
            ]);
            $this->seguir($execucao, $pub, $eleg);
        }

        return ['execucao' => $execucao->fresh(), 'nova' => $r['nova'], 'elegibilidade' => $eleg];
    }

    /**
     * O gancho do save no Portal (`PreparoIaDoRascunhoService::preparar`). null = a conta não tem o agente
     * ligado e o preparo segue o caminho de sempre; senão, o que o agente fez (para o log).
     */
    public function aoPreparar(array $conta, PubProduto $pub): ?string
    {
        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->first();
        if ($cfg === null || ! $cfg->habilitado) {
            return null;
        }

        try {
            $r = $this->pedir($conta, $pub, PubAgenteExecucao::ORIGEM_AO_SALVAR);

            return "agente: {$r['execucao']->estado}".($r['execucao']->etapa ? " ({$r['execucao']->etapa})" : '');
        } catch (RegraViolada $e) {
            return "agente: {$e->regra}";
        } catch (\Throwable $e) {
            // Nada aqui pode quebrar o preparo do save do cliente.
            Log::warning("[Publicador Agente] Preparo do produto {$pub->id} ({$conta['chave']}) não entrou no agente: ".$e->getMessage());

            return 'agente: erro';
        }
    }

    private function seguir(PubAgenteExecucao $execucao, PubProduto $pub, array $eleg): void
    {
        if (! $eleg['elegivel']) {
            $execucao->update(['bloqueios' => $eleg['pendencias'], 'etapa' => 'elegibilidade']);
            $this->execucoes->encerrar($execucao, PubAgenteExecucao::BLOQUEADA,
                implode(' ', array_map(fn ($p) => $p['mensagem'], $eleg['pendencias'])));

            return;
        }

        $situacao = $this->preparo()->avaliarIa($pub);
        $etapa = match (true) {
            str_starts_with($situacao, 'gerando') => 'gerando_texto',
            $situacao === 'em_dia' => 'texto_pronto',
            default => $situacao,
        };

        // Sem IA disponível (cota diária da empresa, preparo desligado): bloqueia com o motivo, não finge pronto.
        if (in_array($situacao, ['limite', 'desligado'], true)) {
            $execucao->update(['etapa' => $etapa, 'bloqueios' => null]);
            $this->execucoes->encerrar($execucao, PubAgenteExecucao::BLOQUEADA, $situacao === 'limite'
                ? 'Limite diário de gerações de texto da empresa atingido.'
                : 'O preparo pela IA está desligado no sistema.');

            return;
        }

        $execucao->update(['estado' => PubAgenteExecucao::PREPARANDO, 'etapa' => $etapa, 'bloqueios' => null, 'heartbeat_em' => now()]);

        // Texto já em dia: vai à Aprovação agora. Cadeia rodando: o `publicador:agente-encaminhar` leva quando terminar.
        if ($etapa === 'texto_pronto') {
            app(PreparadorDaAprovacao::class)->encaminhar($execucao);
        }
    }

    private function preparo(): PreparoIaDoRascunhoService
    {
        return app(PreparoIaDoRascunhoService::class);
    }
}
