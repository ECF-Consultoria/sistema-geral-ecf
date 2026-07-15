---
phase: 82-planilha-excel-like-na-grade-de-anuncio-em-massa-glide-data-
plan: 01
date: 2026-07-15
status: complete
type: execute
requirements: [SHEET2-06, SHEET2-07]
one-liner: "Fundação da planilha: 5 deps instaladas, portal DOM no Blade e helpers puros extraídos para gradeMassaUtils.js — sem renderizar grade nenhuma"
files-modified:
  - package.json
  - package-lock.json
  - resources/views/app.blade.php
  - resources/js/Pages/Mlb/gradeMassaUtils.js (novo)
  - resources/js/Pages/Mlb/AnunciarMassa.jsx
commits:
  - 247e893 feat(82-01) instala glide-data-grid 6.0.3 + peers
  - a7c8a48 feat(82-01) portal DOM exigido pelo overlay editor
  - bb4842c feat(82-01) extrai helpers puros para gradeMassaUtils.js
---

# 82-01 — Fundação da grade em canvas — Summary

## O que foi feito

Preparado o terreno para a grade em canvas. **Nenhuma grade foi renderizada** — isso nasce no
Plan 02. A página segue com a `<table>` HTML antiga, byte-idêntica em comportamento.

### Task 1 — Dependências (`247e893`)

5 pacotes em `dependencies`, versão estável (nenhum `6.0.4-alphaXX`, vetado pelo research):

| Pacote | Versão |
|--------|--------|
| `@glideapps/glide-data-grid` | ^6.0.3 |
| `@glideapps/glide-data-grid-cells` | ^6.0.3 |
| `lodash` | ^4.18.1 |
| `marked` | **^4.3.0** (ver gotcha) |
| `react-responsive-carousel` | ^3.2.23 |

### Task 2 — Portal DOM (`a7c8a48`)

`<div id="portal"></div>` como último filho de `<body>` em `resources/views/app.blade.php`.
Mitiga o Pitfall 1 do research (verificado no código-fonte da lib): sem esse nó, o overlay
editor faz `getElementById("portal")`, não acha, loga no console e retorna — **nenhuma edição
de célula ou dropdown abre, sem nenhum sinal visual do problema**.

### Task 3 — Helpers puros (`bb4842c`)

`resources/js/Pages/Mlb/gradeMassaUtils.js` novo, exportando os 11 símbolos:
`gerarEan13`, `nomeCurto`, `ATTR_MARCA`, `ATTR_MODELO`, `errosLocaisLinha`, `linhaPublicavel`,
`semAcento`, `normalizarTipoAnuncio`, `parseDimensoes`, `casarValueList`, `linhaVazia`.

Move puro — sem refactor, sem renomear, sem mudar ordem de checagem (regra dura do plano:
qualquer alteração aqui seria regressão de validação silenciosa, com anúncio inválido indo pro
Mercado Livre). `AnunciarMassa.jsx` importa via `@/Pages/Mlb/gradeMassaUtils`. Continuam na
página: `iniciais`, `SEM_CATEGORIA`, `montarPayloadLinha`, `linhaDeRascunho`, `colunasColaveis`,
`colarNaGrade` (os 2 últimos só morrem no Plan 03, quando o paste nativo estiver provado).

## GOTCHA que custou ~40 min — `marked` DEVE ficar na v4

`@glideapps/glide-data-grid@6.0.3` exige `peer marked@"^4.0.10"`. Instalar `marked` sem fixar
versão traz a **v18**, e com esse conflito na árvore o npm entra em **backtracking exaustivo**
tentando reconciliar: o install pendura **10+ minutos sem escrever um byte de log** e é morto
por timeout. Diagnóstico enganoso — parece rede, antivírus ou lock do `node_modules`.

Provas que isolaram a causa:
- `npm install --dry-run` da lib sozinha: **8s** (resolução não era o problema)
- lib principal sozinha com `--prefer-offline`: **7s**, exit 0
- `lodash` e `marked` sozinhos: **3s** cada
- `glide-data-grid-cells` com `marked@18` na árvore: **ERESOLVE** (`Conflicting peer
  dependency: marked@4.3.0`)
- após `npm install marked@^4.0.10`: o mesmo `glide-data-grid-cells` instalou em **5s**

**Regra para o futuro:** instalar os pacotes um a um e fixar `marked@^4.0.10`. Nunca `marked@*`.

## Bundle (incógnita A3 do research — fechada)

`AnunciarMassa-*.js`: **29.90 kB antes → 29.90 kB depois (delta 0)**. Esperado: a grade ainda
não importa a lib, então o custo real do canvas só aparece no Plan 02. A página é lazy-loaded
por rota Inertia, então o peso afetará só quem abre `/mlb/anuncios/massa/{id}`.

## Desvios do plano

1. **Ordem das tasks trocada** (2 e 3 antes da 1): o `npm install` pendurou, então as tarefas
   independentes foram feitas e commitadas primeiro para não perder trabalho validado.
2. **Bug meu no move, pego pelo gate:** a extração por fatia de linhas parou uma linha antes e
   deixou a chave de fechamento do `casarValueList` de fora → `npm run build` falhou com
   `'export' cannot be used outside of module code`. Corrigido. O move foi feito por script
   extraindo bytes do original (não redigitado) porque `semAcento` tem um regex de combining
   marks Unicode (`/[̀-ͯ]/g`) que, redigitado errado, quebraria a normalização de acentos em
   silêncio — comparado antes/depois: **idêntico**.
3. **O `<verify>` automatizado deste plano está quebrado** (afeta os 7 planos da fase): os gates
   foram escritos como `node -e "...\\\\s..."` inline e o bash consome as barras, fazendo o
   regex não casar nada — o gate "passa" por motivo errado. Rodados via arquivo `.cjs`
   (5/5 verdes). Os próximos planos devem usar arquivo, não `node -e` inline.

## Verificação

- `npm run build` verde (29.63s).
- `php artisan test --filter=Phase82` **9/9** (24 assertions) — igual ao baseline pré-mudança.
- Gate de deps: 5/5 em `dependencies`, nenhuma pre-release.
- Gate do portal: comprovadamente o último filho de `<body>`.
- Gate da extração (via arquivo): 11/11 exportados, import presente, zero duplicata, e o que
  não devia mover continua na página.

## Success Criteria

- [x] 5 pacotes em `dependencies`, versão estável, lockfile atualizado
- [x] Portal DOM presente e posicionado corretamente
- [x] `gradeMassaUtils.js` exporta os 11 símbolos; `AnunciarMassa.jsx` consome sem duplicar
- [x] Zero mudança de comportamento observável (a grade antiga segue idêntica)
- [x] Delta de bundle registrado (0 kB — o custo entra no Plan 02)

## Próximo

Plan 02 — o `<DataEditor>` em canvas nasce: tema `ecf-*`, colunas dinâmicas da aba ativa,
`getCellContent`/`onCellsEdited` e o autosave por linha preservado.
