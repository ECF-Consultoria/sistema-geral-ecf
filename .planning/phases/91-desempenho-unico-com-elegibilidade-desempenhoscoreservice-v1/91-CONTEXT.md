# Fase 91 — Contexto de decisões (proveniência)

## D-91-01 · blocked ⇒ `nota_final=null` + `faixa_bonus=null`

**Proveniência:**
- Em **2026-07-16**, via AskUserQuestion, o usuário escolheu a opção: **"Nota bloqueada até definir régua — aparece no ranking mas com status bloqueada, SEM NOTA OFICIAL até a diretoria aprovar como pontuar Shopee sem financeiro"**.
- O orquestrador **derivou** dessa escolha: `nota_final=null` + `faixa_bonus=null` (sem nota oficial = não exibir nem derivar faixa de bônus).
- A derivação foi **comunicada ao usuário na mesma conversa, antes do planejamento, sem objeção** — tratada como decisão travada (não reabrir).

**Consequências travadas nos planos:**
- Profissional só-Shopee permanece no ranking (`sem_carteira=false`) com `score_status='blocked'`.
- Com `nota_final=null`, a ordenação existente (`sortByDesc(nota ?? -1)`) já posiciona blocked no fim do ranking — **sem tratamento especial de sort**.
- A exibição do status (badge/rotulagem) é trabalho da Fase 92 (UI).

## D-91-02 · Semântica dos 3 status (resolvida pelo orquestrador a partir das decisões do usuário)

- `official` = todos os componentes disponíveis. Profissional MISTO (ML+Shopee) é OFFICIAL — o financeiro vem do subconjunto elegível dele. (Corrige a proposta do 91-RESEARCH.md, que sugeria `partial` para misto.)
- `partial` = tem vínculo financeiro elegível, mas algum componente financeiro indisponível no período. Mecanismo pronto pra futuro.
- `blocked` = ZERO vínculos financeiros elegíveis (só-Shopee) → D-91-01.
