# Phase 44 — Discussion Log

**Data:** 2026-06-26
**Operador:** dev.01@ecfconsultoria.com.br
**Modo:** discuss (4 perguntas focadas na Política de SGI)

## Gray areas apresentadas (multiSelect)

1. ☐ Política de campanha SGI ← **selecionada**
2. ☐ Política de undo + persistência do estado anterior ← Claude's discretion
3. ☐ Tratamento de erro/PATCH + rollback ← Claude's discretion
4. ☐ Feature flag + escopo do token OAuth ← Claude's discretion

Operador escolheu discutir apenas **Política de campanha SGI** — as outras 3 áreas foram capturadas no CONTEXT.md sob "Claude's Discretion" com defaults razoáveis.

## Discussão — Política de campanha SGI

### Pergunta 1/4 — SGI destino: como o sistema escolhe

**Opções apresentadas:**
- A) Combobox com todas as SGI da conta + botão "Criar nova SGI" ← **escolhida**
- B) Sempre criar SGI nova `SGI [YYYY-MM]` por padrão + override pra combobox
- C) Combobox obrigatório + só opera em SGI já manual (sem criar pelo sistema)

**Decisão:** combobox flexível com opção de criar nova. Reaproveita heurística `QUARANTINE_NAME_REGEX` para listar SGIs existentes; botão "Criar nova SGI" preenche o gap quando a conta não tem nenhuma OU operador quer organização diferente.

### Pergunta 2/4 — Criar SGI: estado inicial + nome padrão

**Opções apresentadas:**
- A) Pausada + nome `SGI [YYYY-MM]` (sugerido, editável) ← **escolhida**
- B) Pausada + nome livre (input vazio, obrigatório)
- C) Ativa + nome `SGI [YYYY-MM]` (operador decide pausar depois)

**Decisão:** SGI nova nasce **pausada** (garante adgroup movido não gasta) com nome pré-preenchido editável `SGI 2026-06` (ano-mês corrente). Combina automação + flexibilidade.

### Pergunta 3/4 — Relação entre as 2 ações originais (mover SGI vs pausar in-place)

**Opções apresentadas:**
- A) 2 botões separados lado a lado
- B) 1 botão "Tomar ação" com dropdown
- C) **Apenas "Mover pra SGI" agora (Phase 44a); "Pausar in-place" fica pra Phase 44b/45** ← **escolhida**

**Decisão:** **escopo da Phase 44 REDUZIDO** — só "Mover pra SGI". "Pausar in-place" virou deferred idea. Justificativa do operador implícita: mover pra SGI já é a ação organizacional canônica (SGI é a campanha pausada onde quarentenas vivem); pausar in-place agrega pouco no momento.

### Pergunta 4/4 — Botão "Desfazer" após o move

**Opções apresentadas:**
- A) Toast com "Desfazer" por 10s (só enquanto operador está na página) ← **escolhida**
- B) Botão "Reverter pra campanha original" permanente (persiste `campaign_id_anterior`)
- C) Sem desfazer (operar pelo painel ML pra reverter)

**Decisão:** toast 10s, padrão Gmail/material. **Sem migration, sem persistência DB**. `campaign_id` original fica em memória JS do toast handler. Após 10s, reverter passa a ser via painel ML. Simplifica muito a phase e cobre 95% dos casos "cliquei errado".

### Sub-pergunta junto da 2 — SGI ativa: comportamento

**Opções apresentadas:**
- A) Avisa no modal + permite seguir ← **escolhida**
- B) Pausa a SGI automaticamente antes (PATCH duplo)
- C) Bloqueia ação

**Decisão:** aviso não-bloqueante. Operador conscientemente decide. Cobre casos legítimos (mover pra SGI ativa de teste).

## Deferred ideas capturadas

- **Pausar adgroup in-place** → Phase 44b ou 45 (originalmente parte do escopo Phase 44; reduzido na pergunta 3)
- **Ações em lote** (selecionar N sugadores no Index) → Phase futura
- **Botão "Reverter" permanente** (persistir campaign_id_anterior) → Phase futura
- **Auto-pause se SGI escolhida está ativa** → descartado (aviso é suficiente)

## Claude's Discretion (3 áreas não discutidas)

Defaults documentados no CONTEXT.md `<decisions>`:

1. **Feature flag inicial** → `features.sugadores_mover_sgi`, habilitar pra `role:admin` primeiro
2. **Escopo do token OAuth** → validar no plan 44-01 (smoke); se exigir re-auth, plan 44-04 trata a UX
3. **Tratamento de erro PATCH** → mapeamento por código (401 refresh+retry, 403 fix-scope, 404 auto-resolve, 5xx backoff, timeout abort)

## Validação prévia (gate antes do /gsd-plan-phase 44)

Plan 44-01 DEVE fazer smoke do PATCH na API ML pra validar 4 pontos antes de qualquer planejamento:
1. Endpoint exato e schema do PATCH em adgroup
2. Endpoint pra criar campanha SGI
3. Comportamento de erro por código (401/403/404/5xx)
4. Escopo do token OAuth atual (write vs só read)
