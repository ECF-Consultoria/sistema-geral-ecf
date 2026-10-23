<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
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

    /**
     * O worker da cadeia de texto pergunta pelo RASCUNHO (AG-06): se há execução viva do agente para o produto
     * dele, vale o controle da loja; sem execução viva o rascunho não é do agente e nada muda (preparo antigo).
     *
     * @return array{regra: string, mensagem: string}|null
     */
    public function recusaParaRascunho(PubRascunho $r): ?array
    {
        $viva = PubAgenteExecucao::query()
            ->where('produto_id', $r->produto_id)
            ->whereIn('estado', PubAgenteExecucao::VIVAS)
            ->first();

        return $viva === null ? null : $this->recusaParaSeguir($viva);
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

    /**
     * A janela de preparo: **DIA da semana** e **HORA**. Fuso de São Paulo nos dois.
     *
     * ⚠️ **Sem restrição = sempre**, nas duas dimensões: `dias_semana` nulo ou vazio vale todo dia, e
     * hora ausente (ou início igual ao fim) vale a toda hora. É a convenção que a AG-01 já tinha, e é
     * o que faz esta mudança **não alterar comportamento de nenhuma loja existente** — todas têm a
     * coluna nova em `null`.
     *
     * ⚠️ Início > fim = janela que **passa da meia-noite** (ex.: 22:00–02:00).
     *
     * ═══ ⚠️⚠️ A DECISÃO DELICADA: de que DIA é a madrugada de uma janela que cruza a meia-noite ═══
     *
     * Com `dias_semana = [1]` (segunda) e janela 22:00–02:00, **01:00 de terça está DENTRO** — porque
     * a janela **começou na segunda**. O dia é avaliado contra o dia em que a janela ABRIU, não contra o
     * dia do relógio.
     *
     * ➡️ **Por que assim:** avaliar pelo dia do relógio faria a janela da loja **parar na meia-noite**
     * sem ninguem ter pedido — ela escolheu "segunda, das 22h às 2h" e receberia duas horas que viram
     * zero. Seria um recorte silencioso, e recorte silencioso é o defeito que esta tela toda evita.
     *
     * ⚠️ Consequência que fica DITA: nessa configuração, **terça 01:00 prepara e segunda 01:00 não** —
     * a madrugada de segunda pertence ao domingo, que não está marcado. Há teste para as duas pontas.
     */
    public static function dentroDaJanela(PubAgenteConfiguracao $cfg, Carbon $agora): bool
    {
        $emSp = $agora->copy()->setTimezone('America/Sao_Paulo');
        $hora = $emSp->format('H:i');

        $inicio = substr((string) $cfg->janela_inicio, 0, 5);
        $fim = substr((string) $cfg->janela_fim, 0, 5);
        $temHora = $inicio !== '' && $fim !== '' && $inicio !== $fim;

        // ⚠️ Filtra 1..7: valor fora da faixa no banco (dado antigo, escrita por fora) não pode travar o
        // agente — ele é descartado, e aí a lista vazia volta a significar "todo dia".
        $dias = array_values(array_filter(
            array_map('intval', (array) ($cfg->dias_semana ?? [])),
            fn (int $d) => $d >= 1 && $d <= 7,
        ));

        if ($dias !== []) {
            // ⚠️ O dia em que a janela ABRIU (ver o docblock): na madrugada de uma janela que cruza a
            // meia-noite, o dia de referência é o de ontem.
            $naMadrugadaDaVespera = $temHora && $inicio > $fim && $hora < $fim;
            $diaDeReferencia = $naMadrugadaDaVespera
                ? (int) $emSp->copy()->subDay()->isoWeekday()
                : (int) $emSp->isoWeekday();

            if (! in_array($diaDeReferencia, $dias, true)) {
                return false;
            }
        }

        if (! $temHora) {
            return true;
        }

        return $inicio < $fim
            ? $hora >= $inicio && $hora < $fim
            : $hora >= $inicio || $hora < $fim;
    }
}
