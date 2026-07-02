---
plan: 52-03B
status: complete
completed_at: 2026-07-02
---

# Plan 52-03B — SUMMARY (Wave 3.5, restauração drilldown por empresa)

Wave de correção: a Wave 2 (Opção Z) removeu a tabela lista global e o
click no CompanyCard virou no-op. A Wave 3 entregou features no drilldown
individual (Show.jsx) mas T1/T2 ficaram superseded. Esta wave restaura a
navegação clicando no card empresa → nova página `EmpresaListagem` que
lista sugadores da empresa + entrega A4/A5/A6 sobre eles.

## Commits (4)

| SHA | Mensagem | Tarefa |
|---|---|---|
| `622df27` | test(52-03B): RED — listagem de sugadores por empresa | T5 RED |
| `ea41c33` | feat(52-03B): endpoint porEmpresa + rota sugadores.empresa.listagem | T1+T2 GREEN |
| `46b08df` | feat(52-03B): EmpresaListagem.jsx + navegacao real do CompanyCard | T3+T4 |
| `24cc93d` | test(52-03B): assertion do component via extractInertiaPage | T5 fix assertion |

## Arquivos

**Novos:**
- `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (~440 linhas)
- `tests/Feature/Phase52/SugadorPorEmpresaListagemTest.php` (7 cenários)

**Modificados:**
- `app/Http/Controllers/SugadorController.php` — método `porEmpresa()` (T1)
- `routes/web.php` — rota `GET /sugadores/empresa/{company}` (T2)
- `resources/js/Pages/Sugadores/Index.jsx` — `abrirDrilldown` volta a navegar (T4)

## Testes

```
Tests\Feature\Phase52\SugadorPorEmpresaListagemTest — 7/7 GREEN (36 assertions)
Suite Phase 52 completa — 25/25 GREEN (79 assertions)
```

Cenários cobertos: admin 200 + component correto; filtro por empresa;
props obrigatórias (company, sugadores, sugador_config, can_manage_config,
can_analyze) + payload compacto da config; filtra `pendente + em_acao`;
403 sem permissão; 403 analista fora da carteira; ordena por `reference_date desc`.

## Build

`npm run build` verde em ~14s. Manifesto contém `EmpresaListagem-BU5Efrxd.js`
(~10-12 kB antes do gzip, dentro do esperado — reusa AppLayout, lucide-react
tree-shaken, sem novas deps).

## Entrega A4/A5/A6 nesta wave

- **A4 (sem coluna empresa):** tabela nova só tem `Checkbox | Produto | Status |
  Detectado | Ações` — contexto empresa já vem fixado no header. Confirma
  a intenção do briefing pt-BR (contexto claro sem repetição visual).
- **A5 (copiar MLBs por linha):** botão "MLBs" per-linha chama
  `sugadores.mlbs-hint` (endpoint Wave 1). Feedback local por sugador com
  total, "Sem MLBs" ou erro. Reset automático após 4s.
- **A6 (bulk copy MLBs):** checkbox por linha + "Selecionar todos os adgroups";
  quando ≥1 selecionado, aparece barra sticky "Copiar MLBs dos selecionados"
  chamando `sugadores.bulk-copy-mlbs`. Auto-clear do feedback em 5s. Sugadores
  tipo=campanha ficam desabilitados no checkbox (sem MLBs para copiar).

Bonus reusado da Wave 3:
- **ConfigResumoCard lateral:** badge ATIVA/INATIVA/DEFAULT + resumo de
  thresholds + botão Configurar.
- **Cronômetro 30s** no botão "Rodar análise" (`sugadores.analyze-company`)
  com `router.reload({only:['sugadores']})` ao fim.

## Desvios / decisões

- **Ordem das rotas em `web.php`:** declarei `/sugadores/empresa/{company}`
  ANTES de `/sugadores/{sugador}` — o model-binding do sugador aceita numérico
  e ficaria genérico demais se declarasse depois. Solução localizada, sem
  regex constraint (mais simples de ler).
- **Sem bulk "marcar em ação":** o prompt marcava-o como opcional. `bulk-move`
  já existente exige campanha destino no schema; introduzir bulk `updateStatus`
  seria expandir escopo. Deixado fora — cada sugador segue mudando status
  via Show individual. Não regride nada.
- **`localStorage.last_company_id` preservado:** removi o comentário "no-op"
  mas mantive a persistência — alimenta o chip "Continuar com [X]" que é
  utilidade legítima e agora tem alvo real de navegação.
- **Sem `route` do sugador collision:** testes cobrem que `sugadores.show`
  continua funcionando (Link em cada linha "Detalhes"). Se colidisse com a
  nova rota, o teste `test_admin_recebe_200_com_pagina_correta` falharia
  no boot; 7/7 verde confirma isolamento.

## Success criteria

- [x] T1: `SugadorController::porEmpresa` implementado (autoriza viewAny +
      carteira, retorna sugadores pendente/em_acao ordenados, props
      completas).
- [x] T2: rota `sugadores.empresa.listagem` registrada e ativa.
- [x] T3: `EmpresaListagem.jsx` criada, dark theme + tokens `ecf-*`, `cn()`,
      lucide icons, ConfigResumoCard + cronômetro reusados do padrão Show.jsx.
- [x] T4: `Index.jsx::abrirDrilldown` navega para a nova rota; chip
      "Continuar com [X]" e card empresa clicam ambos no mesmo handler.
- [x] T5: 7 testes Feature verdes + suite Phase 52 completa 25/25.
- [x] T6: `npm run build` verde, sem warnings novos.

## Notas finais

- Todos os commits em pt-BR.
- ROADMAP.md / STATE.md não alterados (fora do escopo).
- Deploy NÃO executado.
- Próximo natural: Wave 4 (UAT) com foco no fluxo click card → drilldown
  empresa → copiar MLBs por linha e em massa → rodar análise 30s.

## Self-Check: PASSED

- `resources/js/Pages/Sugadores/EmpresaListagem.jsx` — criado.
- `tests/Feature/Phase52/SugadorPorEmpresaListagemTest.php` — criado.
- `.planning/phases/52-melhorias-ui-ux-comportamento-sugadores/52-03B-SUMMARY.md` — criado.
- Commits presentes no git log: `622df27`, `ea41c33`, `46b08df`, `24cc93d`.
- 7/7 testes verdes; suite Phase 52 25/25 verdes.
- `npm run build` verde, manifest inclui `EmpresaListagem-*.js`.

