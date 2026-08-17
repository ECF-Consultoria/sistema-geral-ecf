<?php

namespace App\Services\Clicksign;

use App\Models\Configuracao;

/**
 * CongelamentoEmissaoService — Fase 132 Plano 01 (D-07).
 *
 * Fecha a janela entre a troca de credenciais (plano 132-02) e a aprovação
 * final (plano 132-04, SC5) — janela que pode durar **horas ou dias**,
 * atravessando checkpoints humanos. Nesse intervalo o sistema já conversa
 * com a Clicksign de produção, mas ninguém deu o sinal verde final. Sem este
 * interruptor, qualquer pessoa com `admin.contratos` (que é TODO `role:admin`
 * desde a D-09 da Fase 131 — não é uma pessoa, é a equipe inteira) fazendo o
 * trabalho normal do dia, numa empresa real já com cadastro completo,
 * geraria um **contrato de verdade para um cliente de verdade** — e a API
 * não cancela envelope em andamento (medido, `CLICKSIGN-SANDBOX-EMPIRICO.md`
 * §15.2).
 *
 * ⚠️ **Não é a chave de bloqueio de emergência da Fase 128**
 * (`EmpresaOperacionalRouter::CHAVE_BLOQUEIO`, na tabela `configuracoes`).
 * Aquela barra a LIBERAÇÃO da empresa para o operacional (e
 * `liberarEmpresa()` a contorna de propósito). Esta barra a GERAÇÃO do
 * contrato, que acontece antes e em outro lugar — são dois interruptores
 * com propósitos distintos; um não cobre o outro. Chave PRÓPRIA desta fase.
 *
 * A chave nasce DESLIGADA porque o estado normal do sistema é emitir
 * contrato. A garantia da janela fechada **não vem do default** — vem do
 * passo obrigatório de `ligar()` antes de trocar as credenciais (roteiro em
 * `132-GATE.md`), conferido sempre por reconsulta ao banco, nunca por
 * ausência de mensagem de erro. Sem migration: a chave ausente já significa
 * desligado.
 *
 * Útil além desta fase: qualquer manutenção futura que precise congelar a
 * emissão de contratos usa a mesma chave.
 */
class CongelamentoEmissaoService
{
    /**
     * Chave em `configuracoes`. Convenção do projeto: booleano persistido
     * como string '1'/'0', nunca `true`/`false` nativo.
     */
    public const CHAVE = 'contratos_emissao_congelada';

    /** Lê o estado atual do interruptor. Chave ausente = desligado. */
    public function ativo(): bool
    {
        return Configuracao::get(self::CHAVE, '0') === '1';
    }

    /**
     * Liga o interruptor — ninguém mais gera contrato até `desligar()` ser
     * chamado. Passo obrigatório do plano 132-02, ANTES da troca de
     * credenciais.
     */
    public function ligar(): void
    {
        Configuracao::set(self::CHAVE, '1');
    }

    /**
     * Desliga o interruptor — volta ao estado normal de emitir contrato.
     * Último passo do SC5 (plano 132-04), só depois da aprovação final.
     */
    public function desligar(): void
    {
        Configuracao::set(self::CHAVE, '0');
    }
}
