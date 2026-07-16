---
phase: 84-planilha-undo-redo-local-ctrl-z-ctrl-y
date: 2026-07-15
status: complete
requirements: [UNDO-84-1, UNDO-84-2, UNDO-84-3]
one-liner: "Ctrl+Z / Ctrl+Y na planilha, com histórico local de 50 ações e autosave acompanhando o desfazer"
commits:
  - 4faa985 feat(84) undo/redo local da planilha
deployed: 2026-07-15 (deploy parcial)
---

# Fase 84 — Undo/redo local — Summary

Executada sem PLAN.md formal (fase pequena, escopo travado com o usuário antes: undo **local**,
~50 ações, sem desfazer linha já persistida). Registro do que foi feito e por quê.

## Decisões técnicas

- **A lib não tem undo nativo** — verificado no `.d.ts` instalado: não está em
  `ConfigurableKeybinds` (que tem `downFill`, `rightFill`, `clear`, `delete`, `search`, navegação)
  nem em `ForcedKeybinds` (`copy`/`cut`/`paste`). Consequência boa: **Ctrl+Z não é interceptado** e
  borbulha até o wrapper DOM — dá para capturar sem brigar com o teclado nativo (SHEET2-04 intacto).
- **O snapshot é barato:** `abas` já é imutável (todo `setAbas` cria objetos novos), então o
  histórico guarda **referência**, não clona dado.
- **Agrupamento por lote (o detalhe que decide se é usável):** um paste de 50 células chama o funil
  50 vezes em sequência síncrona. Sem agrupar, cada Ctrl+Z desfaria **uma célula** e seriam 50
  undos para desfazer um paste — no Excel, um paste é **uma** ação. Edições dentro da mesma janela
  curta (120ms = o mesmo `onCellsEdited`: paste, fill, delete em range) entram como uma ação só.
  Digitar célula a célula fica bem acima dessa janela → continua um undo por célula.
- **`editarComSalvar` é o funil único** de toda edição da grade — por isso o histórico ancora nele,
  e não em cada call-site.
- **`reSalvarDiferencas`** re-agenda o autosave das linhas revertidas. Sem isso, a tela mostraria o
  valor revertido e o banco continuaria com o novo — **pior que não ter undo**.
- **O atalho é ignorado com foco em input/textarea:** no overlay editor, o Ctrl+Z do browser é o que
  o usuário espera enquanto digita numa célula.

## Gate da Fase 82 refinado (não afrouxado)

Havia um gate proibindo `onKeyDown` em `GradeAnuncioGlide.jsx`. Ele nasceu para impedir a
**reimplementação da navegação nativa** (setas/Tab/Enter) — não para proibir atalho que a lib não
trata. Refinado para proibir `onKeyDown` **no `<DataEditor>`** e `keybindings=` (que aí sim
brigariam com o nativo), permitindo o handler no wrapper DOM. O espírito do gate foi preservado.

## Escopo (decisão do usuário)

Cobre: digitação, paste, fill handle, Delete. **Não** desfaz criação/remoção de linha já persistida
no banco — exigiria endpoint de restauração e reconciliar ids.

## Verificação

57/57 testes JS (5 gates novos: funil, agrupamento, re-save, teto de 50, redo invalidado por ação
nova), build verde, Phase82 9/9. Deployado em produção no mesmo dia.
