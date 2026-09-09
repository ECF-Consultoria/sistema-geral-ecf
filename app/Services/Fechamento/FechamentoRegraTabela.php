<?php

namespace App\Services\Fechamento;

use App\Models\Configuracao;

/**
 * FechamentoRegraTabela — interruptor único da regra nova de cobrança
 * (Fase 141, D-01/D-02/D-03 do CONTEXT).
 *
 * A flag `self::CHAVE` liga, de uma vez, as três mudanças da Fase 141:
 * tabela progressiva por EMPRESA/GRUPO (nunca mais por serviço, D-01),
 * soma do faturamento de cada plataforma contratada com tabela (D-02) e
 * mensalidade igual ao valor da faixa — e só isso, sem somar contrato
 * nenhum por cima (D-03, o caso BARAOSHOP que abriu esta fase).
 *
 * A chave NASCE e PERMANECE DESLIGADA: não existe seed nem migration
 * gravando valor — o "desligado" é o `$default` de `ativa()`. Ligá-la em
 * produção é decisão do plano 141-07, depois da conferência do delta
 * empresa a empresa (plano 141-05). Mesmo padrão de
 * `EmpresaOperacionalRouter::CHAVE_BLOQUEIO` (Fase 124).
 *
 * ⚠️ Competência já congelada NUNCA muda ao virar a flag (D-11 da Fase
 * 137): o ramo de mês fechado lê `FechamentoSnapshot` gravado, não
 * recalcula — então ligar/desligar esta chave não reescreve o passado,
 * só muda o que o fechamento calcula dali pra frente.
 */
class FechamentoRegraTabela
{
    /**
     * Chave em `configuracoes`. Contrato entre os planos 141-02
     * (este leitor), 141-03/141-04 (consumidores) e 141-07 (ativação em
     * produção) — não renomear sem atualizar as três pontas.
     */
    public const CHAVE = 'fechamento_tabela_por_empresa_ativa';

    /**
     * Memória da leitura desta instância. `null` = ainda não lida nesta
     * instância; não confundir com "lida e desligada" (`false`).
     */
    private ?bool $memoria = null;

    /**
     * Lê a flag, memoizando o resultado na instância.
     *
     * A memoização não é enfeite: este leitor é chamado dentro do laço de
     * 201 empresas do fechamento (`AdminController::fechamento()` e
     * `fechamento:consolidar-mes`) — sem memoizar seriam 201+ consultas
     * ao banco por página/execução, uma por empresa, para ler sempre o
     * mesmo valor.
     *
     * Convenção do projeto: booleano persistido como string '1'/'0',
     * nunca `true`/`false` nativo — qualquer valor diferente de '1'
     * (inclusive 'true', '' e '0') é lido como desligado.
     */
    public function ativa(): bool
    {
        if ($this->memoria === null) {
            $this->memoria = Configuracao::get(self::CHAVE, '0') === '1';
        }

        return $this->memoria;
    }

    /**
     * Limpa a memória desta instância. Usado pelos testes (para o teste
     * seguinte poder virar a flag e ver o valor novo) e por comandos de
     * execução longa que viram a flag no meio da própria execução.
     */
    public function esquecer(): void
    {
        $this->memoria = null;
    }
}
