---
plan: 52-02
status: complete
completed_at: 2026-07-01
---

# Plan 52-02 — SUMMARY (Wave 2, UI cleanup)

Wave 2 (UI puro, sem TDD) — limpeza pesada em `resources/js/Pages/Sugadores/Index.jsx`
para retirar textos era-Adman, botões perigosos e o paradigma de tabela lista global.
Show.jsx não teve alteração (grep confirmou zero UI legacy real: as 3 mensagens de
"última sincronização" em Show.jsx:710/714/718 são legítimas, conforme RESEARCH §A2).

## Commits criados (4)

| SHA | Mensagem | Tarefa |
|---|---|---|
| `c6e332d` | feat(52-02): remove textos/badges da era Adman em /sugadores (A2) | T1 |
| `477afdc` | feat(52-02): remove botão Reanalisar do CompanyCard (A7 parte 1) | T2 |
| `e2b7ab2` | feat(52-02): remove botão global 'Rodar análise' do header (A7 parte 2) | T3 |
| `6fa9b98` | feat(52-02): remove tabela lista completa + toggle Cards/Lista (A9); A4 absorvida | T4 + T5 |

## Arquivos modificados

- `resources/js/Pages/Sugadores/Index.jsx` — **1415 → 514 linhas** (–901).
- `resources/js/Pages/Sugadores/Show.jsx` — sem alteração (nada era-Adman falso; sem tabela com coluna Empresa).

## Decisão A9 — Opção Z (remoção total)

Adotada a **Opção Z** (mais drástica) em vez da Opção Y (manter tabela quando `company_id` filtrado):

**Motivo:** o CONTEXT.md deixou explícito que o objetivo era remover a tabela inteira. O prompt do executor
reforçou "Remover o bloco inteiro da visualização 'lista'". Manter a tabela em modo condicional
(Opção Y) preservaria dívida técnica pesada — filtros, bulk actions, coluna Empresa condicional,
paginação — sem entregar valor claro. Opção Z simplifica radicalmente o Index e delega ações
per-sugador para a drilldown Show.jsx (que já é o destino natural do click).

**Consequências assumidas:**
- Reanálise per-empresa migra para o drilldown Show.jsx em wave posterior (era Wave 3 originalmente).
- Botão "Agir" inline (mudança de status por sugador) migra para o Show.jsx (já vive lá).
- Bulk move para SGI ficou removido do fluxo — reintroduzir se demanda concreta surgir.
- Chip "Continuar com [Empresa]" preservado mas hoje é "no-op de navegação" (persiste last_company_id
  em localStorage); passará a navegar ao Show.jsx quando reanálise per-empresa for adicionada lá.

## A4 (coluna Empresa) — absorvida por A9

A coluna "Empresa" alvo do A4 vivia dentro da tabela lista (Index.jsx:1194 header + 1244-1249 body).
Como a tabela toda foi removida em T4, A4 fica automaticamente resolvida.

Show.jsx **não** tem tabela com coluna Empresa (verificado via `grep -n "<th|<td" Show.jsx` — zero matches).
As 3 menções ao nome da empresa em Show.jsx são: header do sugador (linha 218) e mensagens de última
sincronização MLBs (710/714/718). Nenhuma dessas é a "coluna Empresa" citada no A4.

## Verificação (success_criteria)

- [x] Zero matches de textos legacy em código executável do Index.jsx
      (7 matches restantes são **comentários** de deprecation)
- [x] CompanyCard sem botão Reanalisar
- [x] Header sem botão "Rodar análise"
- [x] Toggle Cards/Lista removido
- [x] `enqueuedAt`, `reanalisarEmpresa`, `runAnalysis`, `switchView`, `AnaliseBadge` deletados
      + estados/imports órfãos limpos (RotateCw, PlayCircle, LayoutGrid, List, Filter,
      ArrowRightLeft, ListTree, ChevronLeft, ChevronRight, Link, useForm, useRef, MoveToSgiModal,
      Megaphone permanece porque `TIPO_ICONS` ainda o usa)
- [x] Coluna "Empresa" resolvida (A4 absorvida por A9 — sem tabela onde remover)
- [x] `npm run build` verde (built in ~11-16s, sem erros/warnings novos)
- [x] Decisão A9 documentada (Opção Z com justificativa)

## Débitos conhecidos (não escopo desta wave)

- **Backend `analise_diaria` / `sincronizou_hoje` no payload:** continua sendo enviado pelo
  `SugadorController::index` (linhas 207-211 e 241-246). Frontend só ignora. Remoção de campos
  do backend fica para Phase 53 (conforme RESEARCH §A2 nota impacto backend).
- **`can_analyze` no backend:** continua sendo calculado e enviado. Frontend também ignora.
- **Chip "Continuar com [Empresa]" no-op:** ganhará navegação real quando o Show.jsx receber
  botão de rodar análise per-empresa (Wave 3+).
- **`sugadores.analyze-all`:** rota backend preservada para Artisan/scripts, conforme
  RESEARCH §A8 linha 206.

## Notas finais

- Todos os commits em pt-BR conforme feedback_gsd_language_pt_br.
- ROADMAP.md e STATE.md não alterados (fora do escopo do executor conforme prompt).
- Deploy NÃO executado.
