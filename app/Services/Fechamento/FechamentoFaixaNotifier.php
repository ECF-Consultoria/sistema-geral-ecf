<?php

namespace App\Services\Fechamento;

use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\User;
use App\Notifications\FaixaAlteradaNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * FechamentoFaixaNotifier — avisa os admins quando uma empresa ou um grupo
 * muda de faixa no fechamento mensal (Fase 138, D-02), nos dois sentidos
 * (subida E queda). Não calcula nada novo: `evolucao` e `faixa_ordem` já
 * saem prontos de `fechamento_snapshots`/`fechamento_grupo_snapshots` — este
 * serviço só SELECIONA, ENVIA (agregado) e CARIMBA.
 *
 * ── D-03: por que a seleção é exatamente esta ───────────────────────────
 * `notificado_em IS NULL OR notificado_faixa_ordem <> faixa_ordem` é a
 * decisão de idempotência da fase. Um "Refazer fechamento" que não muda
 * nada encontra `notificado_faixa_ordem` igual à `faixa_ordem` atual e não
 * avisa ninguém de novo. Um "Refazer" que corrige um erro real e move a
 * empresa de faixa encontra valores diferentes e avisa de novo — porque a
 * faixa nova é informação nova sobre quanto cobrar, e queda de faixa é
 * exatamente o tipo de mudança que ninguém percebe sozinho.
 *
 * `estado <> 'ok'` ou `faixa_ordem IS NULL` fica FORA do aviso por decisão
 * do usuário: é o caso de A DEFINIR, e com 74 empresas hoje sem faixa o
 * primeiro disparo seria só ruído.
 *
 * ── Por que o envio é agregado (uma notificação por rodada) ─────────────
 * Em produção são 127 empresas em estado 'ok'. Uma notificação por empresa
 * transformaria o primeiro fechamento de um mês em dezenas de itens no
 * sino — o mesmo problema de ruído que fez o usuário tirar A DEFINIR do
 * escopo. O carimbo continua sendo POR LINHA, então uma rodada posterior
 * que mexa numa única empresa gera um aviso curto só sobre ela.
 *
 * ── Concorrência (correção do plan-checker, 2026-09-03) ──────────────────
 * A trava `notificado_em`/`notificado_faixa_ordem` protege execuções
 * SEQUENCIAIS (inclusive o incidente real de "Refazer" clicado 3x
 * seguidas). Ela NÃO protege duas execuções de verdade em paralelo: ambas
 * podem rodar a SELECT antes de qualquer uma commitar o carimbo, e saem
 * avisos duplicados para todos os admins.
 *
 * Escolha: lock NOMEADO por competência
 * (`Cache::lock('fechamento:notificar:'.$mesStr, 60)`), tentado em modo
 * NÃO-bloqueante (`->get()` sem callback) ANTES de qualquer SELECT.
 * Justificativa da escolha, em vez de `lockForUpdate()` nas linhas
 * selecionadas (a outra saída aceita pelo plano): a seleção lê DUAS
 * tabelas (`fechamento_snapshots` e `fechamento_grupo_snapshots`) mais uma
 * 3ª consulta agregada da competência anterior para montar "faixa antiga →
 * faixa nova" — travar linhas com `lockForUpdate()` cobriria só as duas
 * primeiras e ainda deixaria a leitura da competência anterior fora da
 * exclusão mútua. Um lock nomeado por competência, guardando a rodada
 * INTEIRA (seleção + envio + carimbo) dentro do `try/finally`, fecha a
 * fresta inteira de uma vez, com o mesmo mecanismo (`Cache::lock`) já usado
 * em outros pontos do projeto (`RefreshGrossBillingCacheJob`,
 * `MercadoLivreService::renovarToken`).
 *
 * Modo NÃO-bloqueante (não `->block()`): se outra execução já está com o
 * lock, esta simplesmente registra `Log::info` e sai sem processar nada —
 * quem está com o lock vai ver (e carimbar) exatamente as mesmas mudanças
 * que esta encontraria, então não há necessidade de esperar. Bloquear
 * aumentaria o tempo do comando `fechamento:consolidar-mes` sem trazer
 * nenhum aviso a mais.
 */
class FechamentoFaixaNotifier
{
    /**
     * Sentidos elegíveis para aviso — D-02 (subida E queda, nunca "manteve").
     */
    private const EVOLUCOES_ELEGIVEIS = ['subiu', 'desceu'];

    /**
     * Máximo de nomes citados por sentido na mensagem — o resto vira
     * "e mais N" (mensagem de sino não é relatório).
     */
    private const MAX_NOMES_POR_SENTIDO = 10;

    /**
     * Segundos de TTL do lock nomeado — folga generosa sobre o tempo real
     * de uma rodada (seleção + envio + carimbo de ~150 linhas é sub-
     * segundo), só para nunca travar a competência indefinidamente se o
     * processo morrer no meio.
     */
    private const LOCK_TTL_SEGUNDOS = 60;

    /**
     * Ponto de entrada — chamado por `fechamento:consolidar-mes` (Passo 8)
     * logo após o `FechamentoSnapshotWriter::sync()` retornar com sucesso.
     *
     * @return array{empresas: int, grupos: int, notificacoes: int}
     */
    public function notificar(Carbon $mes): array
    {
        $mesStr = $mes->copy()->startOfMonth()->toDateString();

        $resumoVazio = ['empresas' => 0, 'grupos' => 0, 'notificacoes' => 0];

        $lock = Cache::lock('fechamento:notificar:'.$mesStr, self::LOCK_TTL_SEGUNDOS);

        if (! $lock->get()) {
            Log::info("[Fechamento] Aviso de mudança de faixa da competência {$mesStr} já está sendo processado por outra execução — esta rodada não processa (evita aviso duplicado, D-03).");

            return $resumoVazio;
        }

        try {
            return DB::transaction(fn () => $this->processar($mesStr, $mes));
        } finally {
            $lock->release();
        }
    }

    /**
     * Roda inteira dentro do lock nomeado E de uma transação — o envio e o
     * carimbo precisam acontecer juntos (T-138-14): se o processo cair
     * entre um e outro, a transação desfaz os dois, nunca fica carimbado
     * sem ter avisado.
     *
     * @return array{empresas: int, grupos: int, notificacoes: int}
     */
    private function processar(string $mesStr, Carbon $mes): array
    {
        $resumo = ['empresas' => 0, 'grupos' => 0, 'notificacoes' => 0];

        $linhasEmpresa = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->where('estado', FechamentoSnapshot::ESTADO_OK)
            ->whereNotNull('faixa_ordem')
            ->whereIn('evolucao', self::EVOLUCOES_ELEGIVEIS)
            ->where(function ($q) {
                $q->whereNull('notificado_em')
                    ->orWhereColumn('notificado_faixa_ordem', '<>', 'faixa_ordem');
            })
            ->get();

        $linhasGrupo = FechamentoGrupoSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->where('origem', FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->where('estado', FechamentoSnapshot::ESTADO_OK)
            ->whereNotNull('faixa_ordem')
            ->whereIn('evolucao', self::EVOLUCOES_ELEGIVEIS)
            ->where(function ($q) {
                $q->whereNull('notificado_em')
                    ->orWhereColumn('notificado_faixa_ordem', '<>', 'faixa_ordem');
            })
            ->get();

        if ($linhasEmpresa->isEmpty() && $linhasGrupo->isEmpty()) {
            return $resumo;
        }

        $admins = User::where('role', 'admin')->get();

        if ($admins->isEmpty()) {
            // Carimbar sem destinatário faria a mudança sumir para sempre —
            // sai SEM carimbar, só registra, para a próxima rodada (com
            // admin cadastrado) ainda encontrar e avisar.
            Log::warning("[Fechamento] {$linhasEmpresa->count()} empresa(s) e {$linhasGrupo->count()} grupo(s) mudaram de faixa na competência {$mesStr}, mas não há nenhum admin cadastrado (role=admin) — aviso NÃO enviado e NÃO carimbado.");

            return $resumo;
        }

        // Faixa anterior — UMA consulta por tabela na competência anterior,
        // indexada por company_id/company_group_id (nunca uma consulta por
        // linha). Só para enriquecer a mensagem com "3ª → 4ª faixa"; quando
        // não existe snapshot anterior, o texto cai para "subiu de faixa".
        $mesAnterior = $mes->copy()->subMonthNoOverflow()->startOfMonth();

        $ordemAnteriorPorEmpresa = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesAnterior->toDateString())
            ->pluck('faixa_ordem', 'company_id');

        $ordemAnteriorPorGrupo = FechamentoGrupoSnapshot::query()
            ->whereDate('mes_referencia', $mesAnterior->toDateString())
            ->pluck('faixa_ordem', 'company_group_id');

        $itens = [];

        foreach ($linhasEmpresa as $linha) {
            $ordemAnterior = $ordemAnteriorPorEmpresa->get($linha->company_id);

            $itens[] = [
                'nome'           => (string) $linha->company_name,
                'evolucao'       => $linha->evolucao,
                'ordem_anterior' => $ordemAnterior !== null ? (int) $ordemAnterior : null,
                'ordem_atual'    => (int) $linha->faixa_ordem,
            ];
        }

        foreach ($linhasGrupo as $linha) {
            $ordemAnterior = $ordemAnteriorPorGrupo->get($linha->company_group_id);

            $itens[] = [
                'nome'           => 'Grupo '.($linha->grupo_name ?? ('#'.$linha->company_group_id)),
                'evolucao'       => $linha->evolucao,
                'ordem_anterior' => $ordemAnterior !== null ? (int) $ordemAnterior : null,
                'ordem_atual'    => (int) $linha->faixa_ordem,
            ];
        }

        $subiram  = [];
        $desceram = [];

        foreach ($itens as $item) {
            $texto = $this->formatarItem($item['nome'], $item['evolucao'], $item['ordem_anterior'], $item['ordem_atual']);

            if ($item['evolucao'] === 'subiu') {
                $subiram[] = $texto;
            } else {
                $desceram[] = $texto;
            }
        }

        sort($subiram, SORT_STRING | SORT_FLAG_CASE);
        sort($desceram, SORT_STRING | SORT_FLAG_CASE);

        [$titulo, $mensagem] = $this->montarCopy($mes, count($itens), $subiram, $desceram);

        Notification::send($admins, new FaixaAlteradaNotification(
            titulo:   $titulo,
            mensagem: $mensagem,
            meta:     [
                'source'         => 'fechamento_faixa',
                'mes_referencia' => $mesStr,
                'total'          => count($itens),
                'subiram'        => count($subiram),
                'desceram'       => count($desceram),
            ],
        ));

        // Carimbo POR LINHA — só o que foi efetivamente citado no aviso.
        $agora = now();

        foreach ($linhasEmpresa as $linha) {
            $linha->update(['notificado_em' => $agora, 'notificado_faixa_ordem' => $linha->faixa_ordem]);
        }

        foreach ($linhasGrupo as $linha) {
            $linha->update(['notificado_em' => $agora, 'notificado_faixa_ordem' => $linha->faixa_ordem]);
        }

        $resumo['empresas']     = $linhasEmpresa->count();
        $resumo['grupos']       = $linhasGrupo->count();
        $resumo['notificacoes'] = $admins->count();

        return $resumo;
    }

    /**
     * "Fulano: 3ª → 4ª faixa" quando a faixa anterior é conhecida;
     * "Fulano: subiu de faixa" / "Fulano: desceu de faixa" quando não é.
     */
    private function formatarItem(string $nome, string $evolucao, ?int $ordemAnterior, int $ordemAtual): string
    {
        if ($ordemAnterior !== null) {
            return "{$nome}: {$ordemAnterior}ª → {$ordemAtual}ª faixa";
        }

        $verbo = $evolucao === 'subiu' ? 'subiu' : 'desceu';

        return "{$nome}: {$verbo} de faixa";
    }

    /**
     * Título + mensagem sem jargão técnico — quem lê é o time
     * Administrativo. Nada de "snapshot", "competência", "reconsolidação",
     * "ordem" ou "faixa piso".
     *
     * @param  string[]  $subiram
     * @param  string[]  $desceram
     * @return array{0: string, 1: string}
     */
    private function montarCopy(Carbon $mes, int $total, array $subiram, array $desceram): array
    {
        $mesExtenso = $mes->copy()->locale('pt_BR')->isoFormat('MMMM [de] YYYY');

        $titulo = "Mudança de faixa — {$mesExtenso}";

        $totalSubiu  = count($subiram);
        $totalDesceu = count($desceram);

        $frases = [
            sprintf(
                '%d %s de faixa em %s — %d %s, %d %s.',
                $total,
                $total === 1 ? 'mudança' : 'mudanças',
                $mesExtenso,
                $totalSubiu,
                $totalSubiu === 1 ? 'subiu' : 'subiram',
                $totalDesceu,
                $totalDesceu === 1 ? 'desceu' : 'desceram',
            ),
        ];

        if ($totalSubiu > 0) {
            $frases[] = 'Subiram: '.$this->formatarLista($subiram).'.';
        }

        if ($totalDesceu > 0) {
            $frases[] = 'Desceram: '.$this->formatarLista($desceram).'.';
        }

        return [$titulo, implode(' ', $frases)];
    }

    /**
     * Junta os itens por vírgula, limitando a `MAX_NOMES_POR_SENTIDO` e
     * fechando com "e mais N" — mensagem de sino não é relatório.
     *
     * @param  string[]  $itens
     */
    private function formatarLista(array $itens): string
    {
        $total    = count($itens);
        $exibidos = array_slice($itens, 0, self::MAX_NOMES_POR_SENTIDO);
        $texto    = implode(', ', $exibidos);
        $restante = $total - count($exibidos);

        if ($restante > 0) {
            $texto .= " e mais {$restante}";
        }

        return $texto;
    }
}
