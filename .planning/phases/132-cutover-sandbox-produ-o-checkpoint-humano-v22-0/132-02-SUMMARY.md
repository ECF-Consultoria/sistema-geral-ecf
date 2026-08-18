---
phase: 132-cutover-sandbox-produ-o-checkpoint-humano-v22-0
plan: 02
subsystem: infra
tags: [clicksign, cutover, env, producao, checkpoint-humano]

requires:
  - phase: 132 plano 01
    provides: "ClicksignAmbiente (normalização de grafia) e CongelamentoEmissaoService (interruptor D-07)"
provides:
  - "Produção apontada para a Clicksign real: 12 chaves CLICKSIGN_* no .env, ambiente production, painel de produção"
  - "Gate empírico #3 fechado por chamada real e somente-leitura contra https://app.clicksign.com/api/v3"
  - "Interruptor de emissão LIGADO antes de qualquer credencial mudar, conferido por reconsulta"
affects: [132-03, 132-04]

tech-stack:
  added: []
  patterns:
    - "Conferência por reconsulta ao config() no servidor, nunca por leitura do .env — entre os dois existe cache"
    - "Credencial conferida por comprimento e comparação booleana; valor nunca impresso"

key-files:
  created: []
  modified:
    - .planning/phases/132-cutover-sandbox-produ-o-checkpoint-humano-v22-0/132-GATE.md
---

## Accomplishments

- **Task 1** — publicadas as duas correções do plano 132-01. O deploy revelou que `main` local e
  `origin/main` estavam divergidas desde 11/08 e que **as fases 129, 130 e 131 nunca haviam ido
  para produção**. Com autorização explícita do usuário, o módulo Clicksign inteiro estreou no
  merge `99f83c75`, com 9 migrations aplicadas.
- **Task 2** — interruptor LIGADO às ~17:25, antes de qualquer credencial, conferido por
  reconsulta e reconferido após `config:cache` e restart dos workers. Backup do `.env` em
  `/root`, permissão 600. As 11 chaves gravadas; reconsulta confirma `production`,
  `app.clicksign.com/api/v3` e o painel de produção.
- **Task 3** — gate empírico #3 **CONFIRMADO** por `clicksign:sondar-modelo --listar --producao`;
  o modelo `modelo-contrato-gestao-ads-mercado-livre` está na conta certa. `CLICKSIGN_TEMPLATE_ID`
  gravado (36 chars por reconsulta).

## Decisions Made

- Deploy completo autorizado pelo usuário depois de medido que publicar só o 132-01 era
  tecnicamente impossível (ele altera arquivos que não existiam no servidor).
- Voltar atrás deixou de ser "restaurar credenciais de teste" e passou a ser "remover as chaves":
  produção nunca teve credencial nenhuma, o `sandbox` da reconsulta era default do config.

## Issues Encountered

- **`.env` inválido por alguns minutos.** O script montou o bloco sem aspas e três valores têm
  espaço (nomes dos signatários), quebrando o parser entre o `config:clear` e o `config:cache`.
  Corrigido por `sed`; site voltou em `200` e o log não registrou nenhum erro de dotenv. A lição
  ficou no GATE: validar o arquivo ANTES de limpar o cache do que está funcionando.
- **`supervisorctl restart` travou** com worker preso em `STOPPING`. Destravado com
  `signal KILL` + `start`. Conferir o status depois do restart passou a ser obrigatório.

## User Setup Required

Nenhum — as credenciais saíram do `.env` local da máquina de desenvolvimento e nunca passaram
pelo chat.

## Self-Check: PASSED

Reconsulta no servidor confirma ambiente, URL base, painel, comprimentos de token/secret,
template_id e o interruptor ligado. Coerência do passo 10 confere: não houve estado meio-virado.
