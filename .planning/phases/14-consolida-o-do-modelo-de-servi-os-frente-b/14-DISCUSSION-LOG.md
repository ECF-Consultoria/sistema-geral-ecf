---
phase: 14
date: 2026-05-26
type: discussion-log
---

# Phase 14 Discussion Log

Registro completo das perguntas, opções apresentadas, respostas do usuário e decisões finais. Para uso humano — não consumido por agentes downstream (que leem o CONTEXT.md).

## Áreas Apresentadas (multiSelect)

**Pergunta:** Quais áreas você quer discutir antes de planejar a Phase 14?

**Opções:**
- População do catálogo na migração
- Tabela de FAIXAS no Fechamento
- Empresas órfãs / sem additional_service
- Form do Comercial pós-refatoração

**Selecionadas pelo usuário:** População do catálogo na migração (apenas)

**Não selecionadas → propostas pelo Claude para confirmação rápida (FAIXAS, órfãs, form Comercial)**

---

## Área 1 — População do catálogo na migração

### Pergunta 1/2 — Mapeamento dos 6 valores legacy

**Pergunta:** Como os 6 valores legacy (publicacao/polos/assessoria/incubadora/publicidade/gestao) devem virar registros em `servicos`?

**Opções apresentadas:**
- A) Hard-coded na data migration (recomendada) — migration cria os 6 com nomes humanos, valor_padrao=0, tipo_cobranca=mensal, ativo=true. Determinístico, idempotente.
- B) Usuário cria manualmente antes da migration — mais flexibilidade mas risco de typo.
- C) Híbrido — migration cria + usuário ajusta valor_padrao depois pela UI.

**Resposta:** Hard-coded na data migration (Recomendado)

**Decisão capturada:** D-01 do CONTEXT.md

### Pergunta 2/2 — Dedupe de additional_service

**Pergunta:** `additional_service` é texto livre — provável ter variações do mesmo serviço ("Consultoria" vs "consultoria" vs " Consultoria "). Como deduplicar na migration?

**Opções apresentadas:**
- A) Normalização agressiva (recomendada) — trim + Title Case UTF-8 antes de firstOrCreate.
- B) Apenas trim, sem normalizar case — preserva case original, 2 variantes viram 2 servicos.
- C) Migration emite relatório dos distincts e PARA pra usuário definir mapping manual.

**Resposta:** Normalização agressiva (Recomendado)

**Decisão capturada:** D-02 do CONTEXT.md

---

## Áreas 2-4 — Propostas pré-cozidas pelo Claude (não selecionadas explicitamente)

Claude apresentou propostas concretas pra cada uma das 3 áreas não selecionadas, indicando que a decisão de FAIXAS tinha o maior risco financeiro.

### Pergunta sobre FAIXAS

**Pergunta:** FAIXAS do Fechamento pós-refatoração — como ficar?

**Opções apresentadas:**
- A) FAIXAS continua intacta + contratos são ADICIONAIS (recomendada) — `cobranca_mensal = faixaData['valor'] + SUM(contratos ativos mensais.valor_contratado)`. Equivalente ao cálculo atual.
- B) FAIXAS some, valores migram pro valor_contratado — perde progressão automática por faturamento.
- C) Outra estratégia (vou explicar).

**Resposta:** A) FAIXAS continua intacta + contratos são ADICIONAIS (Recomendado)

**Decisão capturada:** D-03 do CONTEXT.md

### Pergunta combinada — Empresas órfãs + Form do Comercial

**Pergunta:** Empresas órfãs e form do Comercial — aprova minhas propostas?

**Opções apresentadas:**
- A) Sim, aplica as duas propostas — (1) órfãs: cria 1 contrato por valor em service_type com data_contratacao=contract_start ?? created_at, valor=0; (2) form Comercial: substitui service_type por seletor multi do catálogo, roteamento por nome via switch de palavras-chave.
- B) Aprova só (1) órfãs; quero discutir (2) form.
- C) Quero discutir as duas.

**Resposta:** A) Sim, aplica as duas propostas

**Decisões capturadas:** D-04 (órfãs) e D-05 (form Comercial) do CONTEXT.md

---

## Ideias Diferidas (próximas fases)

1. Reescrita da tabela FAIXAS (movimento para modelo dinâmico)
2. UI dedicada para reativar contratos desativados
3. Permissão `sistema.servicos` para outros setores
4. Histórico/auditoria de contratos por empresa
5. Validação de unicidade de contrato ativo (servico × company × ativo)
6. Pre-data de valores realistas no catálogo

---

## Tempo & Estatísticas

- Áreas apresentadas: 4
- Áreas selecionadas explicitamente: 1
- Áreas resolvidas via proposta-pré-cozida do Claude: 3 (FAIXAS + órfãs + form Comercial)
- Perguntas totais respondidas: 4
- Decisões locked no CONTEXT.md: 8 (D-01 a D-08, sendo D-06/07/08 da discrição do Claude)
- Ideias diferidas: 6
- Modo de discussão: `discuss` (workflow.discuss_mode = "discuss")
- Overlays ativos: nenhum
