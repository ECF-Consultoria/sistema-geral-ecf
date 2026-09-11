<?php

namespace App\Services\Fechamento;

use App\Models\Configuracao;

/**
 * FechamentoFonteFaturamento — interruptor único da fonte do faturamento do
 * mês FECHADO no fechamento mensal (quick 260911-eph).
 *
 * Ligada, a consolidação lê o faturamento de ML do `/performance` da Adman
 * (o mesmo número que a dashboard da Adman mostra, já com os ajustes
 * retroativos) em vez de `SUM(adman_metrics.revenue)`, nas empresas que NÃO
 * são `is_ml_driven` e têm `cust_id`. Medido em produção antes de existir:
 * DESK DESIGN, agosto/2026, SUM = R$ 167.537,54 contra R$ 170.363,19 na API,
 * com os 31 dias presentes — não é buraco de sync, são valores que
 * envelheceram. Na população Adman-driven inteira: 34 de 48 empresas
 * divergindo, +3,4%, e NENHUMA mudando de faixa em agosto.
 *
 * A chave NASCE e PERMANECE DESLIGADA: não existe seed nem migration
 * gravando valor — o "desligado" é o `$default` de `ativa()`. Mesmo padrão
 * de `FechamentoRegraTabela::CHAVE` (Fase 141) e de
 * `EmpresaOperacionalRouter::CHAVE_BLOQUEIO` (Fase 124).
 *
 * Por que uma chave, e não simplesmente "a consolidação passa `true`":
 *
 * 1. **O valor vira cobrança.** Toda mudança de número faturado neste
 *    projeto nasce desligada e é ligada depois da conferência do delta
 *    empresa a empresa — foi assim na Fase 141, e a virada de 2026-09-09/10
 *    moveu R$ 1,75 milhão no total a receber.
 * 2. **A consolidação tem DOIS acionadores.** Além do CLI, o botão
 *    "Refazer fechamento" da tela chama o mesmo comando
 *    (`FechamentoController` → `Artisan::call('fechamento:consolidar-mes')`;
 *    em 2026-09-03 o usuário clicou três vezes em poucos segundos). Se a
 *    fonte nova valesse só para uma invocação específica do CLI, um
 *    "Refazer" pela tela reescreveria a competência com o número ANTIGO e
 *    desfaria a correção em silêncio. A chave vale para os dois caminhos.
 * 3. **Desligar não exige deploy** — é a única forma de reverter rápido uma
 *    fonte externa que passou a mentir.
 *
 * ⚠️ Competência já congelada NUNCA muda ao virar a chave: o ramo de mês
 * fechado da tela lê `FechamentoSnapshot` gravado, não recalcula (D-11 da
 * Fase 137). Ligar a chave não reescreve o passado — só muda o que a
 * PRÓXIMA consolidação calcula. Reconsolidar competência já fechada
 * continua exigindo `--motivo=` (D-12).
 *
 * ⚠️ A chave não faz a tela chamar a API: `AdminController::fechamento()`,
 * `EnviarRelatorioFechamentoJob` e `CompararMensalidadeFechamento` continuam
 * no default `false` de `FechamentoRollupService::porEmpresa()`, sem passar
 * perto deste leitor. Quem lê esta chave é SÓ `ConsolidarMesFechamento`.
 */
class FechamentoFonteFaturamento
{
    /**
     * Chave em `configuracoes`. Ligar em produção é
     * `Configuracao::set(self::CHAVE, '1')` — decisão humana, depois da
     * conferência do delta.
     */
    public const CHAVE = 'fechamento_faturamento_da_api_ativo';

    /**
     * Memória da leitura desta instância. `null` = ainda não lida; não
     * confundir com "lida e desligada" (`false`).
     */
    private ?bool $memoria = null;

    /**
     * Convenção do projeto: booleano persistido como string '1'/'0', nunca
     * `true`/`false` nativo — qualquer valor diferente de '1' (inclusive
     * 'true', '' e '0') é lido como desligado.
     */
    public function ativa(): bool
    {
        if ($this->memoria === null) {
            $this->memoria = Configuracao::get(self::CHAVE, '0') === '1';
        }

        return $this->memoria;
    }

    /**
     * Limpa a memória desta instância — usado pelos testes, para o teste
     * seguinte poder virar a chave e ver o valor novo.
     */
    public function esquecer(): void
    {
        $this->memoria = null;
    }
}
