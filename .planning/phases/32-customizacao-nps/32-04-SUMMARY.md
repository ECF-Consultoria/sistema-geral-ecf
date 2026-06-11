---
phase: 32-customizacao-nps
plan: 04
subsystem: admin-ui
tags: [nps, emails-enviados, log, paginacao, filtro, debounce, role-admin, sidebar]

# Dependency graph
requires:
  - phase: 32-customizacao-nps
    plan: 01
    provides: model NpsEmailEnvio + tabela nps_email_envios + relacionamentos survey/company + comando NpsDispararMensal gravando log por empresa
  - phase: 32-customizacao-nps
    plan: 02
    provides: grupo NPS colapsavel no sidebar com permission 'core.nps' + middleware role:admin no grupo de rotas /nps/* (admin only) ja declarado em routes/web.php
provides:
  - rota GET /nps/emails-enviados (admin only) — paginacao 25/pg com filtros mes+busca
  - pagina admin Inertia Pages/Nps/EmailsEnviados.jsx (303 linhas) com header + filtros + tabela + paginacao
  - sub-item de sidebar "Emails enviados" no grupo NPS (admin only, icone Inbox)
  - metodo NpsController::emailsEnviados(Request) com query base + eager loading + ultimos 12 meses + total do mes
affects: [futuras phases que precisem inspecionar log de envios NPS, ex: triagem de bounces, retry manual de falhas]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Debounce 300ms em Input de busca com useEffect + useRef pra timer, navegacao via router.get preserveState"
    - "Filtro mes via Select shadcn alimentado por translatedFormat('M/y') em PHP (locale pt_BR)"
    - "Linha expandivel para exibir erro_msg em status=falha — React.Fragment com key no map (TableBody nao aceita div wrapper)"
    - "Total do mes ignora filtro de busca — header mostra contexto real do mes, busca filtra apenas a tabela"
    - "Paginacao manual prev/proximo com router.get preservando ?mes+?q (ao inves de envios.links)"

key-files:
  created:
    - resources/js/Pages/Nps/EmailsEnviados.jsx
  modified:
    - app/Http/Controllers/NpsController.php
    - routes/web.php
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Total do mes calculado em query separada (sem clone do query com busca) — proposito eh mostrar tamanho do mes, nao do filtro"
  - "Mes invalido cai silenciosamente no mes atual (try/catch Carbon::createFromFormat) — sem 422, sem mensagem; usuario simplesmente ve o mes corrente"
  - "Sub-item do sidebar usa excludeRoles identico ao 'Configuracao NPS' do Plan 32-02 (consultor/mentor/publicador/analista/gestor/lider) — coerencia visual no grupo NPS"
  - "Linhas com status=falha tem fundo bg-red-500/[0.02] sutil para distinguir visualmente sem ser agressivo"
  - "Trunca assunto em 60 chars com title= original (tooltip nativo do browser) ao inves de Radix Tooltip — mais leve, suficiente"
  - "Botao Survey 'Ver' so aparece se status=enviado E survey existe (nao apenas survey_id nao-null) — evita link quebrado se survey foi deletada (cascade onDelete null)"

requirements-completed: [REQ-32-07]

# Metrics
duration: 6min
completed: 2026-06-11
---

# Phase 32 Plan 04: Página admin /nps/emails-enviados Summary

**Página admin paginada com filtros mês+busca para inspecionar o log dos disparos do `nps:disparar-mensal` (NpsEmailEnvio do Plan 32-01) + sub-item no sidebar.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-06-11T21:06:28Z
- **Completed:** 2026-06-11T21:12:44Z
- **Tasks:** 4 (controller → rota → JSX → sidebar)
- **Files modified:** 4 (1 criado + 3 modificados)

## Accomplishments

- Método `NpsController::emailsEnviados(Request)` lê `?mes=YYYY-MM` (default mês corrente) com fallback silencioso pro mês atual em formatos inválidos, aplica `?q=` via `whereHas('company') OR LIKE destinatario`, paginar 25/pg preservando query string
- Eager loading de `company:id,name` e `survey:id,token,status,company_id` — payload Inertia enxuto sem JOINs explícitos
- Últimos 12 meses populados em `meses_disponiveis` via `translatedFormat('M/y')` (locale pt_BR já configurado no app)
- `total_mes` calculado em query separada — mostra envios reais do mês ignorando o filtro de busca atual (UX: usuário sabe o tamanho do mês)
- Rota `GET /nps/emails-enviados` reusa o grupo middleware `['auth','verified','role:admin']` criado pelo Plan 32-02; posicionada ANTES de `/nps/{token}` para evitar colisão com parâmetro dinâmico (mesmo padrão da rota `/nps/configuracao*`)
- Página `Pages/Nps/EmailsEnviados.jsx` (303 linhas) com: header (título + contador "N envios em jun/26"), filtros lado a lado (Select mês + Input busca com debounce 300ms via useEffect+useRef), tabela shadcn com 7 colunas (chevron expand, data, empresa link nova aba, destinatário, assunto truncado, status badge, botão Ver Survey), paginação manual prev/próximo
- Linhas com status=falha permitem expandir o `erro_msg` (React.Fragment com key — TableBody não aceita div wrapper)
- Sidebar `AppLayout.jsx`: sub-item "Emails enviados" adicionado no grupo NPS (criado pelo Plan 32-02), ícone `Inbox` (lucide), gating `excludeRoles` idêntico ao "Configuração NPS"
- Smoke end-to-end via Tinker validado: 30 envios fake (24 enviado + 6 falha) → controller retorna `envios.total=30 + per_page=25 + last_page=2 + total_mes=30`; busca `?q=teste5` retorna 1 registro mantendo `total_mes=30`; `?mes=invalido` cai pro mês atual; `?mes=2025-12` retorna 0; props vêm com `company.name` e `survey.token` corretamente eager-loaded
- 19 testes Phase 31 continuam verdes (110 assertions) — zero regressão
- `npm run build` verde (`EmailsEnviados-BJ0QMIlE.js` gerado, 14.84s)

## Task Commits

1. **Controller method:** `33a6cbb` (feat)
   - `NpsController::emailsEnviados()` + import `NpsEmailEnvio` + import `Carbon`
2. **Rota:** `2204985` (feat)
   - `GET /nps/emails-enviados` no grupo `['auth','verified','role:admin']` antes da rota pública `/nps/{token}`
3. **Página Inertia:** `e8748db` (feat)
   - `Pages/Nps/EmailsEnviados.jsx` (303 linhas) — header, filtros, tabela, paginação
4. **Sidebar:** `37f468d` (feat)
   - `AppLayout.jsx`: sub-item "Emails enviados" + import ícone `Inbox`

**Plan metadata commit:** pendente (será criado após este SUMMARY)

## Files Created/Modified

### Criados
- `resources/js/Pages/Nps/EmailsEnviados.jsx` — Página admin paginada com filtros + tabela + expand de erro

### Modificados
- `app/Http/Controllers/NpsController.php` — +1 método `emailsEnviados()` (77 linhas) + 2 imports (NpsEmailEnvio, Carbon)
- `routes/web.php` — +1 rota `GET /nps/emails-enviados` no grupo `role:admin` existente
- `resources/js/Layouts/AppLayout.jsx` — +1 sub-item `Emails enviados` no grupo NPS + import `Inbox` do lucide

## Decisions Made

- **Total do mês ignora filtro de busca.** O contador no header (`{total} envios em jun/26`) mostra o tamanho real do mês selecionado, não o resultado da busca. Trade-off consciente: 1 query extra (`COUNT` separado) por request, mas a UX fica clara — usuário vê "30 envios em jun/26" no header e os 5 resultados que casam com `?q=`. Se a busca também filtrasse o total, ficaria "5 envios em jun/26" enganoso.
- **Fallback silencioso para mês inválido.** `Carbon::createFromFormat('Y-m', $mes)` lança exceção em formatos inválidos. Em vez de 422, capturamos e caímos no mês atual. UX: usuário cola URL antiga ou edita manualmente errado → vê dados atuais sem mensagem de erro. Coerente com o pattern de `mesFiltro` no `NpsController::index()` (Plan 31-04).
- **Eager loading com colunas específicas.** `with(['company:id,name', 'survey:id,token,status,company_id'])` em vez de `with(['company', 'survey'])` reduz drasticamente o payload Inertia — empresas têm muitos campos (50+ na tabela), surveys têm dados que a página não usa. Sem efeito colateral porque o JSX só lê `.name` e `.token`.
- **Botão Survey 'Ver' guarda contra survey null.** O Plan 32-01 deixou `nps_email_envios.survey_id` nullable com `onDelete('cascade set null')` — preservar auditoria mesmo após apagar survey. O guard `envio.survey && envio.survey.token` impede link quebrado.
- **Linhas com status=falha expandem erro_msg.** Optei por chevron + Fragment ao invés de tooltip Radix porque (a) erro_msg pode ser longo (stack trace) e tooltip trunca, (b) usuário pode querer copiar a mensagem. O fundo `bg-red-500/[0.04]` na linha expandida diferencia visualmente da linha principal sem ser agressivo.
- **Trunca assunto em 60 chars com `title=` nativo.** Tooltip do browser é suficiente — assunto raramente é longo o bastante para exigir Radix Tooltip. Decisão de simplicidade: menos imports, menos JS.
- **Paginação manual prev/próximo (não usa `envios.links`).** Plan 32-04 PLAN sugeriu Laravel pagination links, mas a página atual `Nps/Index.jsx` já usa o pattern manual (`Button + router.get` com `current_page`/`last_page`). Mantive o pattern existente para consistência visual e simplicidade — `envios.links` exigiria componente shadcn de Pagination separado.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `Fragment` necessário em map com sibling rows**
- **Found during:** Implementação do JSX (Task 3)
- **Issue:** O `data.map(envio => (...))` precisava retornar 2 elementos irmãos (linha principal + linha expand do erro) quando `isFalha && isExpanded`. Usei `<>...</>` short-syntax inicialmente — mas Fragment short não aceita `key`, e React precisa de key estável por item do array. Sem isso, warning de console + risco de re-render bug.
- **Fix:** Trocado por `<Fragment key={envio.id}>...</Fragment>` (`Fragment` importado de `react`). Linha expand não precisa de key adicional porque está dentro do Fragment.
- **Files modified:** `resources/js/Pages/Nps/EmailsEnviados.jsx`
- **Verification:** Build verde + bundle gerado sem warnings de chave duplicada.
- **Committed in:** e8748db (commit 3)

**2. [Rule 2 - Missing Critical] Trim na busca**
- **Found during:** Implementação do controller
- **Issue:** `?q= teste ` (com espaços) ou `?q=` vazio iria adicionar `WHERE LIKE '% %'` ou `'%%'` ao SQL — primeiro caso filtra errado, segundo é no-op mas custa CPU.
- **Fix:** `$q = trim((string) $request->input('q'))` + check truthy antes de aplicar o filtro. Strings vazias e só-whitespace caem fora sem afetar a query.
- **Files modified:** `app/Http/Controllers/NpsController.php` — método `emailsEnviados`
- **Verification:** Smoke Tinker com `?q=teste5` retorna 1 resultado correto; sem `?q=` retorna 30 (não filtra).
- **Committed in:** 33a6cbb (commit 1)

---

**Total deviations:** 2 auto-fixed (1 bug fix React Fragment, 1 critical robustez de busca).
**Impact on plan:** Nenhuma mudança de escopo. Ambas decisões internas que melhoram robustez.

## Issues Encountered

- **`Company::first()` retornou null inicialmente, mas Company id=1 existia.** Provavelmente scope global ou condição `active=true` no model. Solução: criei `Empresa Teste 32-04` com `active=true` para o smoke (id=2). Não bloqueante — o controller usa `with(['company:id,name'])` sem scope adicional, e em produção haverá empresas reais.
- **`getProps()` não disponível no Inertia\Response.** Para inspecionar props no smoke test, usei reflection direta (`ReflectionProperty('props')`). Funciona mas é workaround — em testes formais com `assertInertia()` o Pest tem helpers próprios. Sem impacto na funcionalidade.
- **Locale pt_BR confirmado no `translatedFormat`.** `now()->translatedFormat('M/y')` retornou "jun/26", "mai/26", etc — exatamente o esperado. Config de locale no `config/app.php` (`'locale' => 'pt_BR'`) já estava aplicada.

## Self-Check: PASSED

- [x] Rota `nps.emails-enviados.index` registrada em `route:list` (confirmado)
- [x] Rota protegida por `EnsureUserHasRole:admin` (confirmado via `route:list -v`)
- [x] `Pages/Nps/EmailsEnviados.jsx` existe e foi bundled (`public/build/assets/EmailsEnviados-BJ0QMIlE.js`)
- [x] `npm run build` verde (14.84s + rebuild com sidebar 14.38s, ambos sem erros)
- [x] Smoke Tinker: 30 envios criados → controller retorna `envios.total=30`, `per_page=25`, `last_page=2`, `total_mes=30`
- [x] Smoke Tinker: `?q=teste5` retorna 1 registro mantendo `total_mes=30`
- [x] Smoke Tinker: `?mes=invalido` cai pro mês atual; `?mes=2025-12` retorna 0
- [x] Smoke Tinker: `meses_disponiveis` tem 12 entries com labels "jun/26".."jul/25"
- [x] Smoke Tinker: eager loading `company.name` e `survey.token` populados nas linhas
- [x] PHP lint verde (`php -l NpsController.php`)
- [x] Suite Phase 31 verde (19 testes / 110 assertions OK — zero regressão)
- [x] Commits 33a6cbb, 2204985, e8748db, 37f468d existem (`git log --oneline -8`)
- [x] Dados fake limpos pós-smoke (`NpsEmailEnvio::count() == 0`)

## Known Stubs

Nenhum stub. Todos os dados da página vêm de queries reais no banco. Quando rodar o `nps:disparar-mensal` em produção (cron 09:00 BRT), os registros começam a aparecer.

## User Setup Required

None. Em produção (VPS), após `git pull` + `npm run build`:
- Admin acessa `/nps/emails-enviados` via sidebar (NPS → Emails enviados)
- Como o primeiro disparo NPS Mensal é amanhã 09:00 BRT (CONTEXT), a página vai estar vazia até lá — esperado
- Filtros mês + busca funcionam imediatamente

## Phase 32 Completeness

**Phase 32 (Customização NPS) está ready for verification:**

| Plan | Status | Provides |
|------|--------|----------|
| 32-01 | DONE | Tabela log + helper render + partial logo + template email |
| 32-02 | DONE | Página /nps/configuracao + preview + sidebar Configuração NPS |
| 32-03 | DONE | LogoEcf + perguntas dinâmicas em Respond.jsx |
| 32-04 | DONE | Página /nps/emails-enviados + sidebar Emails enviados |

Próximo passo natural: rodar `/gsd:verify-phase 32` ou monitorar primeiro disparo do cron NPS amanhã 09:00 BRT para validar end-to-end com dados reais.

### Gotchas para próximas phases

- **`Nps/EmailsEnviados` é um identifier novo no AppLayout.** Coerente com o padrão estabelecido pelo Plan 32-02 (`Nps/Index`, `Nps/Configuracao`). Se futura phase usar `usePage().component === 'Nps'` (string nua), não casará — tem que usar `startsWith('Nps/')` ou nome completo.
- **`NpsEmailEnvio.survey_id` é nullable.** Componentes que consomem o relacionamento `survey` devem sempre testar `envio.survey && envio.survey.token` — survey pode ter sido deletada (cascade onDelete set null preserva auditoria).
- **`meses_disponiveis` é construído em PHP com `translatedFormat`.** Funciona porque `config/app.php` tem `'locale' => 'pt_BR'`. Se algum lugar mudar isso, os labels viram em inglês ("Jun/26" → "Jun/26" continua igual neste caso, mas datas mais longas mudariam).

---
*Phase: 32-customizacao-nps*
*Completed: 2026-06-11*
