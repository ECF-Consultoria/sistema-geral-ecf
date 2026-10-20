<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Support\Carbon;

/**
 * Decide se uma etapa do agente pode começar para aquela conta (AG-01, 20/10/2026).
 *
 * Devolve o MOTIVO da recusa (`regra` + `mensagem`) ou null quando pode. Não grava nada: quem admite é
 * `ExecucoesDoAgente`, que chama esta política dentro da transação. A política é lida do BANCO a cada chamada —
 * é ela o desligamento sem deploy (sem `config:cache`, sem reinício de worker).
 *
 * Pausado e suspenso recusam admissão e etapa seguinte. A diferença entre os dois (o que fazer com a chamada
 * já em voo) é da AG-06.
 */
class PoliticaDoAgente
{
    public const REGRA_DESLIGADO = 'AG-01-DESLIGADO';
    public const REGRA_CONTROLE = 'AG-01-CONTROLE';
    public const REGRA_CONTA = 'AG-01-CONTA';
    public const REGRA_ESCOPO = 'AG-01-ESCOPO';
    public const REGRA_JANELA = 'AG-01-JANELA';

    public function __construct(private ProgramasPublicadorService $programas) {}

    /** @return array{regra: string, mensagem: string}|null */
    public function recusaParaAdmitir(array $conta, ?PubAgenteConfiguracao $cfg, PubProduto $produto, string $origem): ?array
    {
        if ($recusa = $this->recusaDoControle($cfg)) {
            return $recusa;
        }

        if (! $this->programas->produtosQuery($conta['mlb_empresa'], $conta['company'])->whereKey($produto->id)->exists()) {
            return ['regra' => self::REGRA_CONTA, 'mensagem' => 'Este produto não pertence a esta loja.'];
        }

        $escopo = (array) ($cfg->escopo ?? []);
        $incluir = (array) ($escopo['incluir'] ?? []);
        if (in_array($produto->id, (array) ($escopo['excluir'] ?? []), true)
            || ($incluir !== [] && ! in_array($produto->id, $incluir, true))) {
            return ['regra' => self::REGRA_ESCOPO, 'mensagem' => 'Este produto está fora do escopo configurado para o agente.'];
        }

        // A janela vale para o que o agente faz sozinho; quem pede "Preparar agora" decide a hora.
        if ($origem === PubAgenteExecucao::ORIGEM_AUTOMATICA && ! self::dentroDaJanela($cfg, now())) {
            return ['regra' => self::REGRA_JANELA, 'mensagem' => 'Fora da janela de preparo configurada para esta loja.'];
        }

        return null;
    }

    /**
     * Uma execução já admitida pode seguir para a próxima etapa? Rechecado pelo worker antes de cada etapa
     * (inclusive a paga), relendo a configuração do banco.
     *
     * @return array{regra: string, mensagem: string}|null
     */
    public function recusaParaSeguir(PubAgenteExecucao $execucao): ?array
    {
        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $execucao->conta_chave)->first();

        return $this->recusaDoControle($cfg);
    }

    /** @return array{regra: string, mensagem: string}|null */
    private function recusaDoControle(?PubAgenteConfiguracao $cfg): ?array
    {
        if ($cfg === null || ! $cfg->exists || ! $cfg->habilitado) {
            return ['regra' => self::REGRA_DESLIGADO, 'mensagem' => 'O agente está desligado para esta loja.'];
        }
        if ($cfg->controle !== PubAgenteConfiguracao::ATIVO) {
            return ['regra' => self::REGRA_CONTROLE, 'mensagem' => $cfg->controle === PubAgenteConfiguracao::SUSPENSO
                ? 'O agente está suspenso nesta loja.'
                : 'O agente está pausado nesta loja.'];
        }

        return null;
    }

    /** Sem janela = sempre. Fuso de São Paulo; início > fim = janela que passa da meia-noite. */
    public static function dentroDaJanela(PubAgenteConfiguracao $cfg, Carbon $agora): bool
    {
        $inicio = substr((string) $cfg->janela_inicio, 0, 5);
        $fim = substr((string) $cfg->janela_fim, 0, 5);
        if ($inicio === '' || $fim === '' || $inicio === $fim) {
            return true;
        }

        $hora = $agora->copy()->setTimezone('America/Sao_Paulo')->format('H:i');

        return $inicio < $fim
            ? $hora >= $inicio && $hora < $fim
            : $hora >= $inicio || $hora < $fim;
    }
}
