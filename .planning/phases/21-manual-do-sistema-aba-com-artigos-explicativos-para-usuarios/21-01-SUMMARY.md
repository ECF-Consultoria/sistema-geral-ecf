---
phase: 21-manual-do-sistema-aba-com-artigos-explicativos-para-usuarios
plan: "01"
subsystem: manual
tags: [manual, artigos, cronograma, sidebar, inertia, react, feature]
dependency_graph:
  requires: []
  provides:
    - rota manual.index (GET /manual)
    - rota manual.show (GET /manual/{slug})
    - ManualController (index + show)
    - Manual/Index.jsx (lista artigos por categoria)
    - Manual/Show.jsx (wrapper lookup artigo)
    - Manual/Artigos/Cronograma.jsx (artigo Cronograma de horários)
    - Manual/artigos.js (catálogo central slug → Component)
    - Item sidebar "Manual do Sistema" (todos autenticados)
  affects:
    - AppLayout.jsx (sidebar — novo item rodapé)
    - routes/web.php (2 novas rotas)
tech_stack:
  added: []
  patterns:
    - Artigo hardcoded em JSX (sem CMS no banco)
    - Catálogo central JS (artigos.js como fonte única de verdade)
    - Controller "burro" (slug puro passado ao frontend)
    - Lookup dinâmico no frontend (buscarArtigo)
key_files:
  created:
    - app/Http/Controllers/ManualController.php
    - resources/js/Pages/Manual/artigos.js
    - resources/js/Pages/Manual/Index.jsx
    - resources/js/Pages/Manual/Show.jsx
    - resources/js/Pages/Manual/Artigos/Cronograma.jsx
    - tests/Feature/Phase21/ManualControllerTest.php
  modified:
    - routes/web.php
    - resources/js/Layouts/AppLayout.jsx
decisions:
  - "D-01: Catálogo de artigos em JS frontend — Component não é serializável pelo Inertia"
  - "D-02: Slug inválido → backend 200 + frontend mostra 'Artigo não encontrado' (extensibilidade)"
  - "D-03: Item sidebar como último entry de NAV_ITEMS com footerSeparatorBefore, não no User footer"
  - "D-04: Tabela HTML pura com Tailwind (não há Table shadcn no projeto)"
  - "D-05: Linguagem do artigo validada manualmente no W4 humano"
  - "D-06: Apenas AppLayout title= (sem Head separado — consistente com padrão do projeto)"
  - "D-07: ManualController extends Controller (26 de 27 controllers existentes estendem)"
metrics:
  duration: "~25 minutos"
  completed: "2026-06-05"
  tasks: 6
  files: 8
---

# Phase 21 Plan 01: Manual do Sistema Summary

**One-liner:** Nova área "Manual do Sistema" com artigo Cronograma de horários (14 rotinas em 5 blocos, tabela responsiva, zero jargão técnico), acessível a todos os autenticados via item na sidebar.

## Status

W1 (Backend), W2 (Frontend) e W3 (Testes) concluídos com sucesso. W4 (smoke visual em prod) aguarda ação humana.

## O que foi construído

### Backend (W1)

- **`ManualController`** com `index()` (renderiza `Manual/Index`) e `show(string $slug)` (passa slug puro para `Manual/Show`). Controller extends `Controller` por consistência com os demais (26/27 estendem).
- **2 rotas** `GET /manual` e `GET /manual/{slug}` no grupo `['auth', 'verified']`, fora de qualquer subgrupo de `role:admin` ou `permission:*`. Middleware confirmado via `route:list --json`: apenas `web, auth, verified`.

### Frontend (W2)

- **`artigos.js`**: catálogo central com `ARTIGOS`, `listarArtigos()` e `buscarArtigo(slug)`. Entry inicial: `cronograma` com titulo, categoria, descricao e Component.
- **`Index.jsx`**: lista artigos agrupados por categoria (`reduce` preservando ordem de inserção), grid responsivo 1/2/3 colunas, cards com hover amarelo.
- **`Show.jsx`**: recebe `slug` via prop Inertia, faz lookup em `artigos.js`, renderiza artigo ou mensagem "Artigo não encontrado" + link "Voltar ao Manual". Breadcrumb `Manual / {titulo}`.
- **`Cronograma.jsx`**: artigo completo com 14 rotinas em array `ROTINAS` (5 blocos), cabeçalho 3 parágrafos (inclui explicação D-1), tabela desktop (`hidden md:block`) + lista mobile (`md:hidden`), rodapé com "Última revisão: 2026-06-05".
- **`AppLayout.jsx`**: entry "Manual do Sistema" como último item de `NAV_ITEMS` sem `permission` (visível a todos), `footerSeparatorBefore: true` renderiza cabeçalho "Documentação".

### Testes (W3)

- **`ManualControllerTest`**: 4 testes, 36 assertions, todos passando (4/4).
  - `index_retorna_200_para_usuario_autenticado`
  - `index_redireciona_para_login_quando_guest`
  - `show_retorna_200_para_slug_valido_cronograma`
  - `show_retorna_200_para_slug_inexistente_frontend_lida`

## Desvios do Plano

Nenhum — plano executado exatamente como especificado.

## Commits da fase (W1–W3)

| Hash | Mensagem |
|------|----------|
| `2b0e496` | feat(21-01): ManualController + rotas manual.index e manual.show no grupo auth |
| `56efdd7` | feat(21-01): catálogo central artigos.js (slug → metadata + Component) |
| `019e2c3` | feat(21-01): Manual/Index.jsx (lista por categoria) + Manual/Show.jsx (wrapper lookup) |
| `7846ec9` | feat(21-01): artigo Cronograma com 14 rotinas em 5 blocos (responsivo) |
| `83f2184` | feat(21-01): item Manual do Sistema no rodapé da sidebar (todos autenticados) |
| `110a950` | test(21-01): ManualControllerTest com 4 testes (auth, guest, slug válido, slug inválido) |

## Checklist pré-W4

- [x] `php artisan test --filter=Phase21` → 4/4 passing
- [x] `npm run build` → 0 errors (build OK em ~12s)
- [x] `route:list --name=manual` → middleware: `web, auth, verified` (sem role/permission)
- [x] Grep de termos proibidos em Cronograma.jsx → zero matches (cron, cache, queue, worker, background, fetch, endpoint, schedule:run)
- [x] Grep de nomes de classes PHP e Artisan commands → zero matches
- [x] 8 arquivos criados + 2 modificados conforme PLAN

## Conteúdo do artigo Cronograma (14 rotinas)

| Horário | Nome amigável | Bloco |
|---------|---------------|-------|
| 03:00 | Atualiza grants do Mercado Livre | Madrugada |
| 03:20 | Limpa histórico antigo de sincronizações | Madrugada |
| 04:00 | Limpa avisos antigos do sininho | Madrugada |
| 08:00 | Renova permissões do Mercado Livre | Manhã |
| 11:00 | Busca dados da Adman | Manhã |
| 11:05 | Busca dados direto do Mercado Livre | Manhã |
| 11:30 | Atualiza faturamento mensal | Manhã |
| 11:45 | Recalcula metas individuais | Manhã |
| 11:55 | Recalcula metas de setor | Manhã |
| 12:00 | Detecta sugadores do dia | Início da tarde |
| 12:30 | Fecha sugadores resolvidos | Início da tarde |
| 12:45 | Prepara dados da Dashboard | Início da tarde |
| Uma vez por dia | Limpa pesquisas NPS pendentes | Em momentos variados do dia |
| Mensal — dia e hora configurados | Envia relatório mensal de fechamento | Configurável pelo admin |

## Validação de linguagem (pré-W4)

Termos proibidos verificados automaticamente via `grep -iE` — **zero matches** em `Cronograma.jsx`:
- `cron`, `cache`, `queue`, `worker`, `background`, `fetch`, `endpoint`, `schedule:run`
- `RefreshGrossBillingCacheJob`, `AdmanService`, `SugadorAnalysisService`
- `adman:sync`, `grants:sync-ecf`, `sugadores:analyze`, `notifications:cleanup`, `ml:sync`, `ml:refresh`, `goals:calculate`, `mlb:sync`

Termos "API" e "job" não aparecem no conteúdo visível (apenas em comentários de código). Validação final do conteúdo visível pelo usuário fica para W4 humano.

## Riscos residuais

- **R-01 (linguagem):** texto autoritativo do PLAN seguido exatamente; W4 valida visualmente.
- **R-02 (cronograma desatualizado):** aceito como débito; rodapé do artigo informa "Última revisão: 2026-06-05" e pede aviso ao time se divergir.
- **R-03 (layout sidebar):** sidebar já tem `overflow-y-auto`, item tem label curto (18 chars). W4 valida em mobile + collapsed.
- **R-04 (slug sem regex):** aceitável — controller não executa nada com slug; lookup JS retorna null para string inválida.
- **R-05 (tokens públicos):** tokens `/implementacao/*` não passam pelo AppLayout — não afetados.

## Confirmação de escopo aditivo

- Nenhuma migration criada
- Nenhuma alteração de schema
- Nenhuma mudança em rotas/lógica existente
- Nenhuma nova dependência npm ou Composer
- Nenhum activity log (sem CRUD)

## Ponteiros para evolução

- **Phase 22+**: novos artigos do Manual (ex: "Como interpretar a Dashboard", "Glossário de termos", "Cust ID e marketplace") — basta criar `Manual/Artigos/Foo.jsx` + entry em `artigos.js`
- **Phase futura** (se hardcode virar manutenção pesada): auto-extração de horários de `routes/console.php` parseando AST PHP, ou CMS no banco

## W4 — Aguardando ação humana

Smoke visual em prod com pelo menos 3 roles (admin + consultor/mentor + publicador MLB) cobrindo:
1. Sidebar: item "Manual do Sistema" visível com cabeçalho "Documentação"
2. `/manual`: card Cronograma de horários
3. `/manual/cronograma`: 14 rotinas em 5 blocos, linguagem sem jargão
4. `/manual/lorem-ipsum`: mensagem "Artigo não encontrado"
5. Responsividade mobile: tabela vira lista de cards
6. Sidebar collapsed: apenas ícone BookOpen visível

Ver bloco `W4-T1` no PLAN para checklist completo (18 passos de validação).

## Self-Check: PASSED
