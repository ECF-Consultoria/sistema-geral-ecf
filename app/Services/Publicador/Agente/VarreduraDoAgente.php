<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Log;

/**
 * A varredura LIMITADA do agente (AG-06, 20/10/2026): descobre produto que ainda não passou pelo agente e o pede
 * pela mesma `EntradaDoPreparo` do save e do "Preparar agora", com origem `automatica` (a janela da loja vale).
 *
 * Só DESCOBRE: produto que já tem oferta na Aprovação, execução viva ou execução que chegou à revisão fica de
 * fora — mudança de fato em produto já preparado chega pelo save do Portal, não por aqui. Produto bloqueado nas
 * últimas 24 h também fica de fora (senão cada passada abriria outra execução bloqueada com o mesmo motivo); o
 * "Preparar agora" continua valendo para ele a qualquer hora.
 *
 * Limites: no máximo `POR_PASSADA` produtos por loja em cada passada; o limite diário e o teto da fila da loja são
 * conferidos na admissão, e o primeiro "não cabe" encerra a loja nesta passada.
 *
 * Também recupera execução `admitida` sem sinal há `ADMITIDA_PARADA_MIN` minutos (o processo morreu entre a
 * admissão e o pedido do preparo) — a `preparando` parada é do `PreparadorDaAprovacao`.
 */
class VarreduraDoAgente
{
    public const POR_PASSADA = 5;

    public const ADMITIDA_PARADA_MIN = 10;

    /** Recusas que valem para a loja inteira nesta passada: as outras são do produto e a varredura segue. */
    private const PARAM_A_LOJA = [
        PoliticaDoAgente::REGRA_DESLIGADO, PoliticaDoAgente::REGRA_CONTROLE, PoliticaDoAgente::REGRA_JANELA,
        ExecucoesDoAgente::REGRA_LIMITE_DIARIO, ExecucoesDoAgente::REGRA_TETO_FILA,
    ];

    public function __construct(
        private ProgramasPublicadorService $programas,
        private EntradaDoPreparo $entrada,
    ) {}

    /** @return array{lojas: int, pedidos: int, novas: int, recusas: int, recuperadas: int} */
    public function rodar(): array
    {
        $resumo = ['lojas' => 0, 'pedidos' => 0, 'novas' => 0, 'recusas' => 0, 'recuperadas' => 0];
        $resumo['recuperadas'] = $this->recuperarAdmitidas();

        PubAgenteConfiguracao::query()
            ->where('habilitado', true)->where('controle', PubAgenteConfiguracao::ATIVO)
            ->orderBy('id')->get()
            ->each(function (PubAgenteConfiguracao $cfg) use (&$resumo) {
                $conta = $this->programas->resolver($cfg->conta_chave);
                if ($conta === null) {
                    return;
                }
                $resumo['lojas']++;
                // ⚠️ O cooldown é lido DA CONFIGURAÇÃO DESTA LOJA, dentro do laço — cada loja tem o
                // seu, e `null` resolve para as mesmas 24 h de antes.
                foreach ($this->candidatos($conta, $cfg->cooldownEfetivo()) as $pub) {
                    $resumo['pedidos']++;
                    try {
                        $r = $this->entrada->pedir($conta, $pub, PubAgenteExecucao::ORIGEM_AUTOMATICA);
                        $resumo['novas'] += $r['nova'] ? 1 : 0;
                    } catch (RegraViolada $e) {
                        $resumo['recusas']++;
                        if (in_array($e->regra, self::PARAM_A_LOJA, true)) {
                            break;
                        }
                    } catch (\Throwable $e) {
                        Log::error("[Publicador Agente] Varredura: produto {$pub->id} ({$cfg->conta_chave}) falhou: ".$e->getMessage());
                    }
                }
            });

        return $resumo;
    }

    /** @return iterable<PubProduto> */
    private function candidatos(array $conta, int $cooldown): iterable
    {
        return $this->programas->produtosQuery($conta['mlb_empresa'], $conta['company'])
            ->whereNull('produto_base_id')
            ->whereIn('id', PubRascunho::query()->select('produto_id'))
            ->whereNotIn('id', PubAprovacaoOferta::query()->whereNotNull('produto_id')->select('produto_id'))
            // ⚠️⚠️ **O COOLDOWN (26/10/2026), E ELE FECHOU UM LAÇO DE GASTO.**
            //
            // Antes esta exclusão tinha um cooldown **de fato** de 24 h — mas **só para
            // `BLOQUEADA`**. ⚠️ **`FALHOU` não era excluído por nada**, então um produto cuja
            // preparação falhou podia ser repescado na **passada seguinte** do agendador, e cada
            // passada gasta chamada de IA **paga**. O plano é explícito: *"não trocar segurança
            // contra velocidade aparente"*.
            //
            // ➡️ Agora o cooldown é **por loja** e cobre **os dois desfechos de "não deu certo"**.
            // ⚠️ `CANCELADA` fica **de fora de propósito**: cancelar não é falhar, e quem cancela
            // pode querer pedir de novo em seguida — pôr cooldown ali faria o sistema recusar o
            // próprio gesto da pessoa, sem explicação.
            //
            // ⚠️ O corte usa `cooldownEfetivo()`, e **`null` na coluna resolve para as mesmas 24 h
            // de antes** — é o que faz esta mudança não alterar nenhuma loja existente.
            ->whereNotIn('id', PubAgenteExecucao::query()->whereNotNull('produto_id')
                ->where(fn ($q) => $q->whereIn('estado', [...PubAgenteExecucao::VIVAS, ...PubAgenteExecucao::TERMINADAS_COM_RESULTADO])
                    ->orWhere(fn ($b) => $b
                        ->whereIn('estado', [PubAgenteExecucao::BLOQUEADA, PubAgenteExecucao::FALHOU])
                        // ⚠️ `>=` sobre o corte: com cooldown **0** o corte é `now()` e a linha com
                        // `concluida_em` exatamente agora ainda sai da lista nesta passada — a
                        // seguinte já a aceita. Zero é "sem espera", não "no mesmo instante".
                        ->where('concluida_em', '>=', now()->subMinutes($cooldown))))
                ->select('produto_id'))
            ->orderBy('id')
            ->limit(self::POR_PASSADA)
            ->get();
    }

    private function recuperarAdmitidas(): int
    {
        $n = 0;
        PubAgenteExecucao::query()
            ->where('estado', PubAgenteExecucao::ADMITIDA)
            ->where('heartbeat_em', '<', now()->subMinutes(self::ADMITIDA_PARADA_MIN))
            ->orderBy('heartbeat_em')->limit(20)->get()
            ->each(function (PubAgenteExecucao $e) use (&$n) {
                try {
                    $this->entrada->continuar($e);
                    $n++;
                } catch (\Throwable $ex) {
                    Log::error("[Publicador Agente] Varredura: a execução {$e->id} ({$e->conta_chave}) parada em `admitida` não continuou: ".$ex->getMessage());
                }
            });

        return $n;
    }
}
