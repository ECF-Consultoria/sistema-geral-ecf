---
quick_id: 260622-itn
description: "Onboarding — tutorial em texto (passo a passo Adman) no card App ECF da workspace pública"
date: 2026-06-22
status: complete
commit: 453c42b (código) — deploy VPS autorizado pelo usuário
---

# Quick Task 260622-itn — Resumo

## O que foi feito

Adicionado um **tutorial em texto** (passo a passo de cadastro na Adman) no card
do item **App ECF** (`id: app_ecf`) da workspace pública do Onboarding
(`resources/js/Pages/Mlb/ImplementacaoPublica.jsx`), no formato escolhido pelo
usuário: **botão discreto → modal sobreposto** (não accordion, não texto solto no
fluxo).

### Frontend (`ImplementacaoPublica.jsx`)
- **`ADMAN_PASSO_A_PASSO`** — constante com o conteúdo fixo (título, saudação, 5
  passos, caixa de atenção). Texto institucional, então mora no front (zero backend).
- **`PassoAPassoBtn`** — botão discreto "Passo a passo" (ícone `BookOpen` já
  importado, cor `ecf-yellow` para se distinguir do `TutorialBtn` de vídeo, que é
  vermelho). Posicionado ao lado do título do item, no mesmo lugar do botão de
  tutorial de vídeo.
- **`PassoAPassoModal`** — modal sobreposto (`fixed inset-0 z-50`, backdrop
  `bg-black/80 backdrop-blur-sm`) espelhando o padrão do `VideoModal`: fecha no X
  ou no clique do fundo (`stopPropagation` no card interno). Corpo: saudação +
  lista numerada (`<ol>` com badges circulares `ecf-yellow`) + caixa de "Atenção"
  em amber (`bg-amber-500/[0.08]` + `AlertCircle`).
- **`ChecklistItem`** — novo prop `onOpenPassoAPasso`; renderiza o `PassoAPassoBtn`
  **somente quando `item.id === 'app_ecf'`** (nenhum outro item do checklist ganha
  o botão).
- **`ImplementacaoPublica`** — estado `passoAPasso` (conteúdo|null), prop
  `onOpenPassoAPasso={() => setPassoAPasso(ADMAN_PASSO_A_PASSO)}` no map dos itens,
  e render do `PassoAPassoModal` no top-level (mesmo padrão dos modais de
  vídeo/produtos/precificação).

## Arquivos alterados
- `resources/js/Pages/Mlb/ImplementacaoPublica.jsx`

## Verificação
- `npm run build` verde (14.25s) — `ImplementacaoPublica-BRgUyb2x.js` recompilado.
- Sem mudança de backend, migração ou rota. Conteúdo 100% no front.
- Botão exclusivo do item `app_ecf` (condicional `item.id === 'app_ecf'`).

## Fora de escopo (intocado)
- Backend (`MlbImplementacao`, controller, configs) — texto é fixo no front.
- Tutorial de vídeo existente (`TutorialBtn`/`VideoModal`) e demais itens do checklist.
- Link global do App ECF (Adman) — segue como está (quick 260618-jcb).

## Notas
- Commit `453c42b` (código). **Deploy para a VPS autorizado pelo usuário** via
  `deploy.sh` (push origin/main → reset --hard + `npx vite build` na VPS).
- Sugestão de verificação visual: abrir o link público de uma implementação,
  localizar o card "App ECF", clicar em "Passo a passo" e conferir os 5 passos +
  a caixa de Atenção.
