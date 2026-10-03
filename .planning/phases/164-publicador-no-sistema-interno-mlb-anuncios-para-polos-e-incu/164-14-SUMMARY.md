---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 14
subsystem: frontend
tags: [publicador, conferencia-visual, puppeteer, layout]
requires: [164-12, 164-13]
provides: [conferencia visual aprovada das telas A, B e do editor]
affects: []
tech-stack:
  added: []
  patterns: [servidor php -S isolado com SQLite fora do repo, capturas Puppeteer]
key-files:
  created: []
  modified:
    - resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx
    - resources/js/Components/Publicador/Mesa/LateralValidacao.jsx
    - resources/js/Components/Publicador/Mesa/LateralResumo.jsx
    - tests/js/publicador-editor.test.js
key-decisions:
  - "Barra do editor sticky -top-6 (compensa o p-6 do main) para colar no cabeçalho de 60px"
  - "Painel 'Como funciona' da tela A ao lado só a partir de 1600px (desvio do UI-SPEC, que dizia 1360px)"
  - "No celular (390px) a barra do editor quebra em linhas (142,5px); o Stitch não define mobile"
duration: n/d
completed: 2026-10-02
---

# Fase 164 Plano 14: Conferência visual do Publicador interno Summary

Conferência visual das telas A (empresas), B (produtos) e do editor em ambiente isolado; uma correção de layout (`ad56a461`) e aprovação do usuário em 2026-10-02.

## O que foi conferido

Ambiente: `php -S 127.0.0.1:8160` com banco SQLite fora do repo, semeado sob `Http::preventStrayRequests`, capturado com Puppeteer (`capturar.mjs`), em `C:/tmp/ecf-publicador-160-visual/`. Cobriu o que o 164-13 não pôde conferir: quebra de 1360px (a 1280px a lateral vira card recolhido e o amarelo passa ao "Publicar" da barra); faixa de produtos com rolagem horizontal e setas; popover "Ver todos"; conta não liberada (cadeado em Conferir dados/Publicar, conferência local cinza, foto pending com miniatura e nota neutra); tela B sem Company com abas apagadas e "Individual" ativa; D27 com pílula "Publicador".

Métricas:
- erros de console/pageerror: 0; respostas >= 400 da origem 127.0.0.1:8160: 0.
- requisições externas: só fontes do Google; 0 para mercadolibre/mlstatic/NVIDIA. O Puppeteer não clicou em Conferir/Publicar/IA/"Quanto eu recebo?"/busca de categoria (só "Ver todos").
- barra do editor: 56px a 1440px e 1280px; antes da correção o topo ficava em y=84 (cabeçalho termina em y=60, faixa de 24px por onde o conteúdo rolava); depois, y=60 no scroll 0 e com scrollTop=1707/3414. A 390px quebra em linhas (142,5px).
- `migrate:status` do MariaDB local: idêntico antes/depois (diff vazio); nada aplicado no banco compartilhado.
- `npm run test:js`: 623/625 (2 falhas pré-existentes da baseline); `publicador-editor.test.js` 29/29.

## Capturas (39 PNGs em `C:/tmp/ecf-publicador-160-visual/`)

Tela A: `A-{gestao,incubadora,polos}-{d,m}.png`. Tela B: `B-alfa-sem-company-{d,m}`, `B-beta-portal-{d,m}`, `B-gestao-d`, `B-incubadora-d`. Editor: `C-alfa-faixa-d`, `C-alfa-ver-todos-d`, `C-bloqueios-d`, `C-parcial-d`, `C-portal-beta-d`, `C-publicado-d`, `C-validado-d`, `C-cadeira-{1280,1280-viewport,d,d-viewport,d-rolado,d-rolado-fim,m,m-viewport}`, `C-camiseta-{1280,1280-viewport,d,d-viewport,m,m-viewport}`, `C-incubadora-nao-liberada-{1280,1280-viewport,d,d-viewport,m,m-viewport}`. Também no diretório: `relatorio.json`, `migrate-antes.txt`, `migrate-depois.txt`, `semear.php`, `capturar.mjs`, `subir.ps1`, `abrir-logado.mjs`.

## Commits

- `ad56a461` fix(160): barra do editor colada ao cabeçalho, tela A sem corte a 1440px e barra legível no celular

## Deviations from Plan

**1. [Rule 1 - Bug] Layout corrigido após a captura**
- **Found during:** Tarefa 1
- **Issue:** barra sticky do editor com topo em y=84 (faixa de 24px por onde o conteúdo rolava); Tela A cortada a 1440px; barra ilegível no celular.
- **Fix:** `BarraDoEditor` `sticky top-0` -> `sticky -top-6`; lateral `top-[80px]` -> `top-[56px]` e `max-h` `100vh-104px` -> `100vh-164px`; barra com `max-sm:h-auto max-sm:flex-wrap`; pílulas/botões da Tela A com `whitespace-nowrap`.
- **Desvio do UI-SPEC:** o painel "Como funciona" só fica ao lado a partir de 1600px (era 1360px); abaixo disso vira card no fim da página.
- **Commit:** `ad56a461`

## Aprovação do usuário

Tarefa 2 (checkpoint:human-verify), 2026-10-02: "aprovado", depois de conferir as capturas e o servidor local aberto já logado no Chrome (perfil separado).

## Servidor

Servidor local `php -S 127.0.0.1:8160` (PID 9304, conferido pela linha de comando) encerrado com `taskkill`; `curl` em `/login` retorna 000 (sem resposta). Nenhum outro processo php nem o Chrome foi tocado.

## Pendências conhecidas (não pedidas)

Registradas pelo orquestrador; o usuário aprovou sem pedir ajuste.
1. Conta não liberada: a lateral diz "Tudo pronto. Pode conferir no Mercado Livre." logo acima de "...A validação no Mercado Livre espera a liberação desta conta." — contraditório com o D26.
2. Celular (390px) nas telas A e B: tabelas com rolagem horizontal, colunas/abas cortadas, SKU quebrando em 3 linhas; o critério "nada cortado nem sobreposto" não é atendido nessas duas. Editor no celular ok.
3. Editor a 1440px: ~190px vazios à direita (margem esquerda 32px).
4. Faixa de produtos não rola até o produto atual (empresa com 16 produtos: o atual fica fora da vista).
5. Painel "Como funciona" ao lado só a partir de 1600px (desvio acima).
6. Chip "Rascunho" da tela B conta também produtos sem rascunho (segue os baldes do servidor de 164-06).

## Known Stubs

Nenhum.

## Self-Check: PASSED

- Commit `ad56a461` existe (`git log`).
- 39 capturas PNG existem em `C:/tmp/ecf-publicador-160-visual/`.
- Servidor da porta 8160 não responde mais.
