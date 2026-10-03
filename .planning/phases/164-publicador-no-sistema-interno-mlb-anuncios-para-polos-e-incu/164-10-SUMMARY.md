---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 10
subsystem: frontend
tags: [inertia, react, publicador, mlb, tela-a]
requires: [164-03, 164-06, 164-07]
provides:
  - Components/Mlb/Publicador/* (selos, aviso de conta não liberada, sincronizar, reconexão, seletor, indicadores, painel)
  - Tela A reescrita em /mlb/anuncios
affects: [164-11, 164-13]
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/tempo.js
    - resources/js/Components/Mlb/Publicador/SeloConta.jsx
    - resources/js/Components/Mlb/Publicador/SeloPortal.jsx
    - resources/js/Components/Mlb/Publicador/AvisoContaTravada.jsx
    - resources/js/Components/Mlb/Publicador/LinkReconexao.jsx
    - resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx
    - resources/js/Components/Mlb/Publicador/SeletorPrograma.jsx
    - resources/js/Components/Mlb/Publicador/IndicadoresDoPrograma.jsx
    - resources/js/Components/Mlb/Publicador/PainelComoFunciona.jsx
    - tests/js/publicador-entrada.test.js
  modified:
    - resources/js/Pages/Mlb/AnunciosEmpresas.jsx
decisions:
  - "Conta não liberada: textos dizem que validação E publicação esperam a liberação (D26), ajustando a UI-SPEC §9"
  - "Mensagem de sincronizar fica numa região aria-live única acima da tabela, prefixada com o nome da empresa"
metrics:
  tasks: 2
  completed: 2026-10-02
---

# Phase 164 Plan 10: Tela A do Publicador (entrada) Summary

Entrada de `/mlb/anuncios` reescrita como lista densa por programa (Polos · Incubadora · Gestão) com indicadores, filtros, busca, paginação e sincronizar do Portal, mais 9 arquivos compartilhados em `Components/Mlb/Publicador/` para as telas B e C.

## Commits

| Tarefa | Commit |
|--------|--------|
| 1. Componentes compartilhados + gate de fonte | 190602e5 |
| 2. Tela A + build | c760859d |

## Componentes compartilhados (para 164-11 e 164-13)

- `tempo.js`: `haQuanto(iso, agora?)`.
- `SeloConta({ token, compacto })`: 'ativo' | 'expirado' | 'sem_token'.
- `SeloPortal({ portal })`: sincronizado | novas | nunca | sem_portal.
- `AvisoContaTravada({ variante: 'selo'|'faixa'|'nota'|'linha', className, children })`: `nota` tem `id="nota-conta-travada"`; `linha` recebe o texto por children (conferência local do editor).
- `LinkReconexao({ link })`, `BotaoSincronizarPortal({ conta, onConcluido, onErro, className })`.
- `SeletorPrograma`, `IndicadoresDoPrograma`, `PainelComoFunciona({ variante })`.
- `tests/js/publicador-entrada.test.js`: array `ARQUIVOS` no topo para 164-11/13 acrescentarem os seus (gates de tipografia, peso, amarelo sólido, select Radix, HTML injetado).

## Verificação

- `npm run test:js`: 578 testes, 576 passam, 2 falham, ambas da baseline ("Características secundárias nasce recolhido (é o grupo que mais infla)" e "FASES_TERMINAIS cobre as três fases de saída..."). Nenhuma falha nova.
- `npm run build`: exit 0; manifest regravado (14:42); `resources/js/Pages/Mlb/AnunciosEmpresas.jsx` presente, apontando para `assets/AnunciosEmpresas-Dfa58RFf.js`, que existe.

## Deviations from Plan

- Textos de conta não liberada ajustados pelo D26 (já previsto no plano; registrado aqui).
- Fora do plano: estado de busca/filtro que não achou nada mostra "Nenhuma empresa neste filtro." quando não há texto de busca (a UI-SPEC só cobre busca vazia).
- Gate de "sem fundo vermelho na linha" olha só o `className` da `<tr>` (`h-14`).
- Não validado em navegador (sem sessão); checagem visual fica para a verificação da fase.

## Known Stubs

Nenhum.

## Self-Check: PASSED
