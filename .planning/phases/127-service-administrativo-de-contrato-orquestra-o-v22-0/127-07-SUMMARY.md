---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 07
subsystem: integracao
tags: [clicksign, gate-humano, medicao-real, producao]

requires:
  - phase: 127 (planos 01-06)
    provides: "o fluxo completo: schema, client, checagem, modelo por servico, job, orquestrador"
provides:
  - "Os 3 gates da fase FECHADOS por medicao — nenhum NAO MEDIDO"
  - "Producao consultada pela 1a vez na milestone: plano TEM acesso a API"
  - "5o bug da milestone achado e corrigido (config da ECF nao validada)"
affects: [128, 129, 130, 131, 132]

key-files:
  created:
    - .planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-GATE.md
    - tests/Feature/Phase127/ConfiguracaoEcfBloqueiaTest.php
  modified:
    - app/Services/Contratos/ContratoDadosMinimosService.php
    - app/Services/Clicksign/ContratoClicksignService.php
    - .planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md

requirements-completed: [CLICK-08, DADOS-06]

duration: ~2h
completed: 2026-08-12
---

# Fase 127 Plano 07: Gates — todos FECHADOS por medição

**Os três gates da fase foram medidos contra a API real, nenhum ficou como NÃO MEDIDO. E o gate
achou o quinto bug da milestone — o quinto que `Http::fake()` não teria como pegar.**

## Placar

| Gate | Veredito |
|---|---|
| Task 1 — envelope real no sandbox | ✅ prazo, lembrete, `draft`, 1 doc, 4 signatários, 8 requisitos |
| GATE 1 — prazo sobrevive à ativação humana | ✅ **SIM**, idêntico ao segundo |
| GATE 2 — produção enxerga o modelo | ✅ **SIM**, `200` |
| GATE 3 — variáveis do modelo de produção | ✅ **7 ok, 0 sobrando** |

## O que cada gate resolveu

**GATE 1 fechou a última dúvida da D-03.** Havia o risco de a D-02 (sistema para no rascunho) e a
D-03 (prazo customizado) se anularem: se o prazo só valesse na ativação, ele se perderia quando o
Comercial ativasse pela interface. Medido com o usuário ativando de verdade — `deadline_at` idêntico
ao segundo, `remind_interval` intacto. **A Fase 130 pode confiar em `prazo_dias` do banco como o
prazo real**, sem reconsultar a Clicksign.

**GATE 2 descartou o maior risco em aberto da milestone.** A conta de produção nunca tinha sido
consultada. O primeiro retorno foi `403` e pareceu ser o gate de plano que a pesquisa previu — não
era: era o `403` de "e-mail do usuário da API não configurado", uma configuração de um minuto. O
plano de produção **tem** acesso à API.

**GATE 3 provou que o modelo de produção bate com o código.** Zero variáveis `sobrando`, que é a
única categoria perigosa — variável que o modelo pede e o código não emite vira campo em branco no
contrato, sem erro nenhum (§10.5).

## ⚠️ O achado que virou correção de código

O fluxo **falhou na primeira execução real**: `email - não pode ficar em branco`.

`config('services.clicksign.signatarios_ecf')` nasce com as 3 entradas **presentes e vazias** — as
chaves do `.env.example` vêm sem valor. A checagem de dados mínimos validava só a **empresa**, então
o fluxo passava pelo bloqueio, criava o envelope, criava o documento, e só então a API recusava o 1º
signatário: **3 chamadas queimadas** da janela medida de 20/min, mais rollback, por um dado sabível
sem nenhuma requisição.

Isso contradiz o Goal literal da fase ("nunca gasta uma chamada HTTP com dado que já sabia estar
incompleto"). Corrigido em `50415659` com `faltantesDaConfiguracaoEcf()`, devolvido em chave própria
(`configuracao`) — separada das pendências da empresa, porque pendência de empresa o Comercial
resolve na tela da Fase 131 e isto é `.env`, que só um admin resolve.

**Não é caso de borda:** é o estado padrão de qualquer ambiente recém-configurado.

## ⚠️ Achado que muda a Fase 130: rascunho expira em 7 dias

Só visível na interface — **nenhuma resposta de API menciona**. A tela de Rascunhos avisa: *"Os
rascunhos ficam disponíveis por 7 dias."*

Colide com a D-02: o sistema para no rascunho e o Comercial envia. Contrato parado esperando revisão
é o caso comum, e depois de 7 dias a Clicksign apaga enquanto nosso banco continua com
`status = rascunho` apontando para um envelope inexistente.

Entrada obrigatória da Fase 130: alertar **antes** dos 7 dias, e distinguir rascunho vivo de
rascunho apagado.

## Duas lições registradas no empírico

1. **Os dois 403 da Clicksign** (§11.3) — mesmo código, consequências opostas: um é configuração de
   um minuto, o outro é decisão comercial de trocar de plano. Distinguir pelo `detail`, nunca pelo
   status. Confundir custaria um pedido de troca de plano desnecessário.
2. **A interface chama de "documento" o que a API chama de "envelope"** (§11.4), e a lista de
   Rascunhos mostra o **nome do arquivo**, não o `name` do envelope — custou uma busca frustrada.

## Segurança

Token de produção lido de chave dedicada `CLICKSIGN_PROD_TOKEN` no `.env` (gitignored), por script
pontual que monta a URL de produção explicitamente. `CLICKSIGN_ENV`/`CLICKSIGN_BASE_URL` do projeto
continuaram no sandbox — nenhum teste, comando ou job passou a tocar a conta real. O token nunca foi
impresso nem colado no chat. Somente leitura: nenhum envelope criado em produção.

⚠️ **Remover `CLICKSIGN_PROD_TOKEN` do `.env`** quando não for mais necessário — o cutover de
verdade é a Fase 132, e lá a decisão é trocar as chaves no servidor, não manter uma paralela.

## Testes

`Phase125 + Phase126 + Phase127` = **214 verdes** (210 + 4 do bug do gate).

## O que segue NÃO MEDIDO (declarado)

- Se os 7 dias do rascunho contam da criação ou da última atualização → **Fase 130**
- Se a tela de envio, que memoriza preferência de prazo do operador, pode sobrescrever o prazo do
  sistema num documento seguinte → **Fase 131**
- `DELETE` de envelope já ativado (`running`) — o rollback desta fase só roda antes da ativação
