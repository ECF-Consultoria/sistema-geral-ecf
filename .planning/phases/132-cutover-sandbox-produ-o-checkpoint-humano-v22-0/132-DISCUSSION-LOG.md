# Phase 132 — Discussion Log

**Data:** 2026-08-17
**Modo:** discuss (padrão) — 4 áreas selecionadas, 6 decisões capturadas

> Registro humano da conversa. **Não é consumido** por researcher, planner ou executor — esses leem
> o `132-CONTEXT.md`.

---

## Achados do scouting, antes de qualquer pergunta

Duas coisas foram descobertas ao ler o código e mudaram a discussão:

1. **`CLICKSIGN_PROD_TOKEN` e `CLICKSIGN_PROD_WEBHOOK_SECRET` são variáveis mortas** — nada em
   `config/services.php` as lê. A virada é substituir valores, não trocar de chave.
2. **Conflito de grafia em `CLICKSIGN_ENV`** — o código compara `'producao'` (pt), o ROADMAP manda
   escrever `production` (en). Seguir o roadmap à risca mandaria o botão da Fase 131 para o painel
   de sandbox enquanto o sistema opera em produção. **Virou a primeira pergunta.**

---

## Área 1 — Mecânica da virada e rollback

**P1: Como resolver o conflito de grafia de `CLICKSIGN_ENV`?**
Opções: **aceitar as variantes no código** · padronizar em `producao` · padronizar em `production`
→ **Escolhido: aceitar as variantes** (D-01) — elimina a classe do problema em vez de escolher um
lado, e ninguém precisa lembrar da convenção na hora do cutover.

**P2: Se der errado depois do 1º envelope, como voltar?**
⚠️ **O usuário pediu explicação:** *"Explique de forma mais simples, não sei o que é cutover"*.
A pergunta foi reformulada em linguagem simples ("virar a chave"), com a explicação de que são 4
linhas do `.env` e de que erro em produção custa caro porque cancelar contrato em andamento não
existe na API.
Opções: **voltar as 4 linhas e resolver à mão** · o mesmo + desligar o gatilho automático · você decide
→ **Escolhido: voltar as 4 linhas** (D-02), com a ressalva registrada de que isso não impede um job
já enfileirado de sair.

---

## Área 2 — A empresa-cobaia

**P1: Qual empresa usar no primeiro contrato de verdade?**
Opções: ECF Consultoria com contrato real · **empresa fictícia com e-mails reais de vocês** ·
cliente real avisado antes
→ **Escolhido: empresa fictícia com e-mails reais** (D-03) — exercita o fluxo inteiro sem envolver
ato jurídico; custo aceito é lixo na base de produção.

---

## Área 3 — Provar a rede de segurança no mesmo movimento

**P1: Usar o mesmo envelope para fechar o SC1 da Fase 130?**
Opções: **sim, no mesmo envelope** · não, um envelope separado depois · deixar para depois
→ **Escolhido: mesmo envelope** (D-04) — um envelope, duas pendências. A D4 da milestone exige a
rede provada antes do bloqueio da Fase 133, e produção é o único ambiente onde dá para provar.

---

## Área 4 — O aviso automático

**P1: Como saber rapidamente se o aviso de produção está chegando?**
Opções: **conferir no banco a cada passo** · esperar e ver se a empresa é liberada · as duas coisas
→ **Escolhido: reconsulta ao banco** (D-05) — mesma disciplina do resto do projeto; acusa na hora e
distingue "não chegou" de "chegou e falhou".

**P2: Em que ponto abortar?**
Opções: **se o aviso não chegar** · só se impedir de criar o contrato · qualquer diferença
→ **Escolhido: aviso não chegar** (D-06) — é o único item que sozinho invalida o cutover.

---

## O que NÃO foi perguntado, e por quê

- **Se o cutover deve acontecer** — está no roadmap, é decisão anterior.
- **Qual a URL de produção** — o gate empírico #3 já registra `https://app.clicksign.com/api/v3`;
  o que falta é confirmar contra o ambiente real, e isso é execução, não decisão.
- **Se a rede de segurança é necessária** — a D4 da milestone já travou isso.
- **Onde cadastrar o webhook** — a rota `POST api/webhooks/clicksign` já existe; cadastrá-la no
  painel é execução.

---

## Riscos levantados durante a conversa

1. **A grafia de `CLICKSIGN_ENV` mandaria o admin para o painel errado** — falha silenciosa,
   resolvida pela D-01 antes de existir.
2. **As variáveis `_PROD_` são lixo silencioso** e podem confundir quem executar o checklist sob
   pressão. O plano precisa decidir o destino delas.
3. **Restaurar o `.env` não para um job já enfileirado** — janela curta, mas real. Conferir o painel
   após reverter.
4. **O envelope de teste da Fase 130 foi cancelado** na medição do CLICK-10 — a D-04 precisa de um
   envelope novo.
5. **Os e-mails dos signatários são reais desde 2026-08-17** — qualquer envelope emitido daqui manda
   e-mail de verdade para Thiago, Emerson e Jessica.

---

## Nenhuma ideia fora de escopo foi levantada

O usuário não propôs capacidades novas. Os itens em `<deferred>` do CONTEXT vieram de consequências
das escolhas desta rodada, não de scope creep.
