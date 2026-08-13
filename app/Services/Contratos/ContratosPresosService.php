<?php

namespace App\Services\Contratos;

use App\Models\ContratoAssinatura;
use App\Models\Configuracao;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Phase 130 Plano 02 (D-03, D-05) — ContratosPresosService.
 *
 * Responde a UMA pergunta: "esta empresa está parada há tempo demais, e por
 * quê?". É o recorte compartilhado entre o alerta de contrato preso
 * (REDE-02, plano 130-05) e a tela de liberação manual (REDE-03, plano
 * 130-04) — sem um lugar único, os dois divergiriam, e a tela de liberação
 * manual esconderia justamente `recusado`/`expirado`/`erro`, os casos em
 * que o admin mais precisa agir.
 *
 * ESCOPO DO ALERTA, largo — não confundir com o escopo da varredura de
 * reconciliação (plano 130-03). A varredura é ESTREITA de propósito
 * (`aguardando_assinaturas` + PDF assinado pendente) porque só nesses casos
 * faz sentido reconsultar a Clicksign. Este serviço cobre os 7 estados —
 * `cancelado` entra na lista porque a regra da D-05 é "empresa sem
 * liberação há tempo demais, qualquer que seja a causa"; a causa aparece na
 * mensagem e diz o que fazer. Os dois recortes não devem ser uniformizados
 * (fronteira da D-08 do CONTEXT.md).
 *
 * ⚠️ Existe no model um método irmão que devolve o intervalo de LEMBRETE
 * nativo da Clicksign (o e-mail automático que a própria Clicksign manda ao
 * SIGNATÁRIO cliente) — público e propósito diferentes do alerta interno da
 * equipe ECF que este serviço alimenta. Este serviço NÃO lê aquele valor
 * (ver docblock de `ContratoAssinatura` para o nome exato e o porquê de não
 * confundir os dois).
 *
 * O cooldown de repetição do alerta (D-04 — carimbo de "quando avisamos
 * pela última vez") fica FORA deste serviço, numa coluna própria do
 * contrato. Este serviço responde "está preso?", não "já avisei?" — o
 * cooldown é política de disparo e vive no comando de alerta (plano
 * 130-05).
 */
class ContratosPresosService
{
    /** Chaves de Configuracao (D-03) — configuráveis sem deploy. */
    public const CHAVE_DIAS_FIXO    = 'rede_alerta_dias_fixo';
    public const CHAVE_FRACAO_PRAZO = 'rede_alerta_fracao_prazo';

    /**
     * Defaults do gatilho "o que vier primeiro" (D-03).
     *
     * DEFAULT_DIAS_FIXO é 5, deliberadamente MENOR que 7: a Clicksign apaga
     * rascunho sozinha em 7 dias (medido em CLICKSIGN-SANDBOX-EMPIRICO.md
     * §11.2). Quem trocar este default precisa manter essa folga — senão o
     * alerta de "rascunho parado" chega depois que o rascunho já não existe
     * mais do lado da Clicksign.
     */
    public const DEFAULT_DIAS_FIXO    = 5;
    public const DEFAULT_FRACAO_PRAZO = 0.5;

    /** Causas por estado (D-05) — insumo da mensagem em linguagem simples. */
    public const CAUSA_RASCUNHO_PARADO           = 'rascunho_parado';
    public const CAUSA_AGUARDANDO_ALEM_DO_PRAZO  = 'aguardando_alem_do_prazo';
    public const CAUSA_ASSINADO_SEM_LIBERACAO    = 'assinado_sem_liberacao';
    public const CAUSA_RECUSADO                  = 'recusado_pelo_cliente';
    public const CAUSA_EXPIRADO                  = 'prazo_expirado';
    public const CAUSA_CANCELADO                 = 'cancelado';
    public const CAUSA_ERRO                      = 'erro_tecnico';

    /**
     * Data base a partir da qual se conta "há quanto tempo está parado",
     * por estado.
     */
    public function dataBase(ContratoAssinatura $c): CarbonInterface
    {
        return match ($c->status) {
            ContratoAssinatura::STATUS_RASCUNHO               => $c->created_at,
            ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS => $c->enviado_em ?? $c->created_at,
            ContratoAssinatura::STATUS_ASSINADO                => $c->assinado_em ?? $c->updated_at,
            default                                             => $c->updated_at,
        };
    }

    /**
     * Limiar de dias parado que dispara o alerta — "o que vier primeiro"
     * (D-03): dias fixos configuráveis OU fração do prazo do próprio
     * contrato, o que for MENOR. Nunca devolve menos que 1 dia — um
     * contrato com prazo de 1 dia não pode gerar limiar 0 e alertar no
     * mesmo instante em que foi criado.
     */
    public function limiarDias(ContratoAssinatura $c): int
    {
        $diasFixo    = (int) Configuracao::get(self::CHAVE_DIAS_FIXO, self::DEFAULT_DIAS_FIXO);
        $fracaoPrazo = (float) Configuracao::get(self::CHAVE_FRACAO_PRAZO, self::DEFAULT_FRACAO_PRAZO);

        $limiar = min($diasFixo, (int) round($c->prazoDiasEfetivo() * $fracaoPrazo));

        return max(1, $limiar);
    }

    /** Dias inteiros corridos entre a data base e agora. */
    public function diasParado(ContratoAssinatura $c): int
    {
        return (int) $this->dataBase($c)->diffInDays(now());
    }

    /** Causa legível do estado atual (D-05). */
    public function causa(ContratoAssinatura $c): string
    {
        return match ($c->status) {
            ContratoAssinatura::STATUS_RASCUNHO               => self::CAUSA_RASCUNHO_PARADO,
            ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS => self::CAUSA_AGUARDANDO_ALEM_DO_PRAZO,
            ContratoAssinatura::STATUS_ASSINADO                => self::CAUSA_ASSINADO_SEM_LIBERACAO,
            ContratoAssinatura::STATUS_RECUSADO                => self::CAUSA_RECUSADO,
            ContratoAssinatura::STATUS_EXPIRADO                => self::CAUSA_EXPIRADO,
            ContratoAssinatura::STATUS_CANCELADO               => self::CAUSA_CANCELADO,
            ContratoAssinatura::STATUS_ERRO                    => self::CAUSA_ERRO,
            default                                             => self::CAUSA_ERRO,
        };
    }

    /** Está preso: sem liberação E parado há tempo igual ou maior que o limiar. */
    public function estaPreso(ContratoAssinatura $c): bool
    {
        return $c->liberado_em === null && $this->diasParado($c) >= $this->limiarDias($c);
    }

    /**
     * Lista todos os contratos "presos" — o recorte largo dos 7 estados
     * (D-05), sem liberação, filtrado pelo gatilho "o que vier primeiro"
     * (D-03).
     *
     * O filtro final é feito EM MEMÓRIA (não em SQL): o volume real é de
     * dezenas de linhas, e `limiarDias()` depende de `prazoDiasEfetivo()`,
     * que tem fallback de `config()` — algo que o SQL não enxerga sem
     * duplicar a regra na query.
     *
     * @return Collection<int, ContratoAssinatura>
     */
    public function listar(): Collection
    {
        return ContratoAssinatura::whereIn('status', ContratoAssinatura::STATUS_TODOS)
            ->whereNull('liberado_em')
            ->with(['company', 'servico'])
            ->get()
            ->filter(fn (ContratoAssinatura $c) => $this->estaPreso($c))
            ->values();
    }
}
