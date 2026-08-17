# Phase 131 — Discussion Log

**Data:** 2026-08-14
**Modo:** discuss (padrão) — 4 áreas selecionadas pelo usuário, 10 decisões capturadas

> Registro humano da conversa. **Não é consumido** por researcher, planner ou executor —
> esses leem o `131-CONTEXT.md`.

---

## Áreas oferecidas

Quatro áreas cinzentas foram apresentadas. O usuário selecionou **todas as quatro**:

1. Onde o Administrativo completa o cadastro
2. Como a situação do contrato é dita sem jargão
3. Corrigir e-mail vs trocar signatário
4. Quanto o Comercial enxerga

---

## Área 1 — Onde o Administrativo completa o cadastro

**P1: O Administrativo vê que falta CNPJ numa empresa. Onde ele preenche isso?**
Opções: editar na própria linha da lista · **tela de detalhe da empresa** · reusar `Admin/Empresas.jsx`
→ **Escolhido: tela de detalhe da empresa** (D-01)

**P2: O campo Gmail sai do Comercial. O Comercial ainda deve VER o dado?**
Opções: **some por completo** · somente-leitura · você decide
→ **Escolhido: some por completo** (D-02)

**P3: Com pendência, o botão "Gerar contrato" fica como?**
Opções: **visível e desabilitado dizendo o que falta** · não aparece até estar pronto
→ **Escolhido: visível e desabilitado** (D-03)

---

## Área 2 — Como a situação do contrato é dita sem jargão

**P1: Os 7 estados técnicos viram quantas situações na tela?**
Opções: agrupar por "o que eu faço agora" · **um rótulo por estado em português claro**
→ **Escolhido: um rótulo por estado** (D-04)

**P2: Como o Administrativo deve ver o estado `erro` (falha técnica nossa)?**
Opções: diz o que fazer · nomeia a falha e manda avisar o time · **as duas coisas**
→ **Escolhido: as duas coisas** — assume a falha, oferece tentar de novo, e só depois orienta
avisar o time (D-05)

---

## Área 3 — Corrigir e-mail vs trocar signatário

Área apresentada com o alerta de que o **gate empírico #8 está ABERTO** — não se sabe se a v3
tem endpoint para corrigir e-mail sem cancelar.

**P1: Se a Clicksign NÃO permitir corrigir e-mail sem cancelar, o que a tela faz?**
Opções: vira "cancelar e reemitir" assistido · **não oferece a ação e explica o porquê** ·
CLICK-09 sai da fase
→ **Escolhido: não oferece a ação** — não prometer o que a API não entrega (D-06)

**P2: Onde a tela explica que corrigir e-mail ≠ trocar a pessoa?**
Opções: **no momento da ação, com as duas opções lado a lado** · texto fixo ao lado da lista ·
você decide
→ **Escolhido: no momento da ação** — é quando o usuário está prestes a errar (D-07)

---

## Área 4 — Quanto o Comercial enxerga

Área apresentada com a nuance de que o Comercial **não terá** `admin.contratos`, então um link
levaria a 403.

**P1: Quanto sobre o contrato aparece na listagem do Comercial?**
Opções: só a situação, sem link · **situação + há quanto tempo**
→ **Escolhido: situação + há quanto tempo** — o Comercial percebe sozinho quando travou (D-08)

---

## Rodada final — o que o planner precisaria e ninguém tinha decidido

**P1: Quem recebe `admin.contratos` de largada?**
Opções: **todo mundo que hoje é `role:admin`** · só quem você indicar depois · admins +
`admin.empresas`
→ **Escolhido: todo `role:admin`** — ninguém perde acesso no dia do deploy (D-09)

**P2: O que acontece com a tela descartável de liberação manual da Fase 130?**
Opções: **absorvida como ação dentro da tela nova** · continua separada
→ **Escolhido: absorvida**, rota antiga removida (D-10)

---

## Contexto que dispensou perguntas

O scouting encontrou três coisas que evitaram discutir o que já estava resolvido:

- **`Permissions.php` já tem `admin.empresas`, `admin.relatorio`, `admin.financeiro`,
  `admin.inventario`** — `admin.contratos` é seguir padrão, não desenhar caminho novo. Não foi
  perguntado "como fazer a permissão".
- **`ContratoDadosMinimosService::faltantes()` foi construído para esta fase** e o docblock diz
  isso. Não foi perguntado "como saber o que falta".
- **`resources/js/Pages/Admin/` já é uma família de telas** e `Comercial/EmpresasListagem.jsx` /
  `NovaEmpresa.jsx` são os pontos exatos de integração. Não foi perguntado "onde mexer".

Decisões carregadas das fases anteriores, também não repetidas: canal é o sino (D-01/130),
`role:admin` é provisório (D-02/130), a tela da 130 é descartável mas o backend não (D-10/130),
`recusado`/`expirado` são estados próprios (D5 da milestone).

---

## Riscos levantados durante a conversa

1. **Gate #8 aberto decide o escopo do CLICK-09.** A D-06 é o plano B. Há um envelope de teste
   ativo com 4 signatários para medir (`f010d235-…`, válido até 12/09) e medir isso **não** exige
   concluir assinatura — é um `PATCH` num signatário.
2. **A sandbox da Clicksign travou a Fase 130** (não conclui assinatura, não envia e-mail). Qualquer
   gate desta fase precisa considerar isso ao planejar medição.
3. **O CLICK-07 tem uma armadilha documentada:** a v3 não expõe link de assinatura — o link só sai
   por e-mail (achado 2 do `129-GATE.md`). A tela não pode prometer mostrar o link.

---

## Nenhuma ideia fora de escopo foi levantada

O usuário não propôs capacidades novas durante a conversa. Os itens em `<deferred>` do CONTEXT.md
vieram de decisões anteriores ou de consequências das escolhas desta rodada, não de scope creep.
