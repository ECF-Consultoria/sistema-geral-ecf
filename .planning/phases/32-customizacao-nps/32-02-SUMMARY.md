---
phase: 32-customizacao-nps
plan: 02
subsystem: admin-ui
tags: [nps, customizacao, inertia, preview, role-admin, sidebar]

# Dependency graph
requires:
  - phase: 32-customizacao-nps
    plan: 01
    provides: NpsTextRenderer (getTextos + defaults + render + renderHtml) + chave configuracoes.nps_textos populada + template emails.nps.mensal + Configuracao KV
provides:
  - rota GET /nps/configuracao (admin only)
  - rota PUT /nps/configuracao (admin only) — persiste 11 textos em configuracoes.nps_textos
  - rota POST /nps/configuracao/preview (admin only) — renderiza email com vars de exemplo D-05
  - pagina admin Inertia Pages/Nps/Configuracao.jsx com form 11 campos + preview iframe srcdoc
  - sub-item de sidebar "Configuração NPS" no grupo NPS (admin only, icon Settings)
affects: [32-customizacao-nps Plan 04 (Emails enviados — adicionará outro sub-item ao mesmo grupo NPS), futuras phases que editarem textos NPS via UI]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Preview server-rendered via fetch ajax + iframe srcdoc para isolar CSS do email do Tailwind do app (D-05)"
    - "Conversão de item top-level do sidebar em grupo colapsável quando ganha sub-item admin-only"
    - "useForm() Inertia com .isDirty para desabilitar botao Salvar enquanto não há mudança"
    - "Validação de form: PUT requer 11 chaves (string max 5000); POST preview aceita 5 nullable (mesma whitelist)"

key-files:
  created:
    - resources/js/Pages/Nps/Configuracao.jsx
  modified:
    - app/Http/Controllers/NpsController.php
    - routes/web.php
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "NpsController ganha 3 métodos novos (configuracao/atualizarConfiguracao/previewEmail) — método respond() existente preservado para Plan 32-03 modificar"
  - "Grupo de rotas /nps/configuracao* declarado ANTES da rota pública /nps/{token} para evitar colisão com parâmetro dinâmico (mesmo padrão de /nps/generate já estabelecido)"
  - "Item NPS top-level do sidebar convertido em grupo colapsável com 2 filhos: Pesquisas (nps.index) e Configuração NPS (admin only) — Plan 32-04 adicionará Emails enviados ao mesmo grupo"
  - "Sub-item Configuração NPS restrito por excludeRoles ['consultor','mentor','publicador','analista','gestor','lider'] em vez de permission='core.admin' — coerente com o padrão usado por Painel Executivo / Concentração / Empresas (Admin) onde gating admin-only é via excludeRoles"
  - "Preview usa vars de exemplo FIXAS (D-05: Nathália / Igor / Empresa Exemplo Ltda / junho/2026) — sem opção de admin trocar (fora de escopo desta fase)"
  - "Preview com renderHtml em saudação/corpo/assinatura (HTML safe + nl2br) e render plano em assunto/CTA — mesma divisão do NpsMonthlyMail real (Plan 32-01)"
  - "Restaurar padrão exige confirm() do navegador (window.confirm) — destrutivo via UX, mesmo que o save final ainda precise ser clicado para persistir"
  - "Endpoint preview retorna {html, assunto} — frontend usa o assunto pra mostrar um banner separado acima do iframe (subject vai num <title> que iframe srcdoc não consome visualmente)"
  - "Botão Salvar usa isDirty pra desabilitar enquanto não houve mudança — evita PUTs no-op"

requirements-completed: [REQ-32-04]

# Metrics
duration: 6min
completed: 2026-06-11
---

# Phase 32 Plan 02: Página admin /nps/configuracao com preview live Summary

**Página `/nps/configuracao` (admin only) com form Inertia para os 11 textos NPS + preview iframe srcdoc server-rendered via 3 novas rotas e sub-item no sidebar.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-06-11T20:47:05Z
- **Completed:** 2026-06-11T20:53:03Z
- **Tasks:** 4 (controller methods → rotas → JSX → sidebar)
- **Files modified:** 4 (1 criado + 3 modificados)

## Accomplishments

- 3 métodos novos no `NpsController` (configuracao, atualizarConfiguracao, previewEmail) com validação de 11 chaves e renderização do template Blade real para o preview
- 3 rotas nomeadas (`nps.configuracao.index`, `nps.configuracao.update`, `nps.configuracao.preview`) protegidas por middleware `role:admin` — consultor/mentor recebem 403 automaticamente (smoke: `route:list -v` confirma EnsureUserHasRole:admin nas 3 rotas)
- Página `Pages/Nps/Configuracao.jsx` (~466 linhas) com layout 2 colunas desktop / stack mobile, Tabs shadcn (Email / Perguntas), painel lateral de placeholders, preview iframe srcdoc atualizável via fetch ajax sem reload
- Botão "Restaurar padrão" reseta os 11 campos para defaults canônicos D-03 (com confirm); botão "Salvar alterações" usa `useForm.put()` + `isDirty` para evitar PUTs no-op
- Sidebar `AppLayout.jsx`: item NPS top-level convertido em grupo colapsável com filhos "Pesquisas" e "Configuração NPS" (Plan 32-04 adicionará "Emails enviados" no mesmo grupo)
- Smoke end-to-end validado via Tinker: `getTextos()` retorna 11 chaves, view `emails.nps.mensal` renderiza 4525 chars de HTML contendo `linear-gradient` + "Nathália" + "junho/2026"; roundtrip de PUT atualiza e re-lê o JSON corretamente
- 19 testes Phase 31 continuam verdes (110 assertions OK) — zero regressão
- `npm run build` verde (`Configuracao-DbPZpe5T.js` gerado)

## Task Commits

1. **Controller methods:** `7443d9f` (feat)
   - `configuracao()`, `atualizarConfiguracao()`, `previewEmail()` + imports `Configuracao` / `NpsTextRenderer`
2. **Rotas:** `f9fdc7b` (feat)
   - 3 rotas `nps.configuracao.*` no grupo `['auth','verified','role:admin']` antes da rota pública `/nps/{token}`
3. **Página Inertia:** `8d9db7e` (feat)
   - `Pages/Nps/Configuracao.jsx` completa com Tabs, form, preview iframe, painel de placeholders
4. **Sidebar:** `f88ed3d` (feat)
   - `AppLayout.jsx`: NPS top-level → grupo colapsável + sub-item "Configuração NPS"

**Plan metadata commit:** pendente (será criado após este SUMMARY)

## Files Created/Modified

### Criados
- `resources/js/Pages/Nps/Configuracao.jsx` — Página admin com form + preview iframe + Tabs + painel de placeholders

### Modificados
- `app/Http/Controllers/NpsController.php` — +3 métodos (configuracao, atualizarConfiguracao, previewEmail) + 2 imports (Configuracao, NpsTextRenderer)
- `routes/web.php` — Grupo `['auth','verified','role:admin']` com 3 rotas inserido antes de `/nps/{token}`
- `resources/js/Layouts/AppLayout.jsx` — Item NPS top-level virou grupo colapsável com 2 filhos; ícone `Settings` importado do lucide

## Decisions Made

- **Sidebar: converter NPS em grupo (ao invés de criar novo grupo "Admin NPS").** O plano pediu sub-item no "grupo NPS", mas no estado atual NPS era top-level. Optei por converter top-level → grupo com filho "Pesquisas" (preserva o link original) + filho "Configuração NPS" (novo, admin only). Plan 32-04 já está alinhado pra adicionar "Emails enviados" no mesmo grupo (ver tags affects no frontmatter). Trade-off consciente: usuários não-admin ganham 1 click extra (grupo NPS precisa expandir), mas em troca o grupo fica auto-extended quando a rota corresponde (lógica já existente em `openGroups`).
- **Gating do sub-item via excludeRoles em vez de permission.** Coerente com o padrão usado em Painel Executivo / Concentração / Empresas (Admin) — `excludeRoles: ['consultor','mentor','publicador','analista','gestor','lider']` em vez de criar uma permission key nova. Como `excludeRoles` é avaliado antes de `permission`, o efeito final é o mesmo que admin-only.
- **Endpoint preview retorna assunto separado.** O subject do email vai num `<title>` que o iframe srcdoc não renderiza visualmente, então mostro o assunto num banner separado acima do iframe. Permite o admin verificar o subject sem precisar inspecionar headers.
- **isDirty para desabilitar Salvar.** useForm().isDirty já compara o estado atual com o initial — desabilita o botão até que algo mude. Evita PUTs no-op e dá feedback visual ao admin de que tem mudança pendente.
- **Preview server-rendered (D-05) ao invés de client-side.** Mantém **um único caminho de renderização** — o mesmo template Blade que vai ser usado no email real. Risco zero de divergência preview vs. envio. Custo: 1 HTTP request por refresh do preview; aceito porque o admin não fica spammando preview a cada tecla (botão explícito).
- **Restaurar padrão exige confirm() + ainda exige Salvar.** Dupla camada de segurança: confirm avisa que vai sobrescrever, mas o reset só altera o `useForm` localmente — admin ainda precisa clicar Salvar pra persistir. Reduz risco de clique acidental destruir customizações.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Endpoint preview valida e aceita campos vazios**
- **Found during:** Implementação do `previewEmail`
- **Issue:** Se o admin abre a página recém-criada e clica em "Atualizar preview" antes de digitar nada, ou se algum campo está em branco temporariamente, o request pode chegar sem alguma chave — exception "Undefined index" no controller é uma resposta ruim.
- **Fix:** Validação do POST preview usa `nullable|string|max:5000` em vez de `required` (PUT usa required). Frontend manda o que tiver; backend renderiza com `?? ''` para chaves ausentes. Admin vê o preview com placeholders vazios — feedback claro do que aconteceria no email real.
- **Files modified:** `app/Http/Controllers/NpsController.php` — método `previewEmail`
- **Verification:** Smoke Tinker mostrou que `view->render()` funciona mesmo com strings vazias (4525 chars de HTML com logo gradient + estrutura sempre presente).
- **Committed in:** 7443d9f (commit 1)

**2. [Rule 2 - Missing Critical] JSON_UNESCAPED_UNICODE no Configuracao::set**
- **Found during:** Implementação do `atualizarConfiguracao`
- **Issue:** Sem `JSON_UNESCAPED_UNICODE`, caracteres pt-BR (á, é, ç, ã, etc.) ficam codificados como `á`. Funciona, mas torna o JSON ilegível para inspeção manual no banco — admin que abrir a tabela `configuracoes` via SQL vê hieróglifos em vez do texto que escreveu.
- **Fix:** `json_encode($validated, JSON_UNESCAPED_UNICODE)` preserva acentos como UTF-8 puro.
- **Files modified:** `app/Http/Controllers/NpsController.php` — método `atualizarConfiguracao`
- **Verification:** Smoke Tinker confirmou roundtrip — texto "Olá!" persistido e re-lido preservando o acento.
- **Committed in:** 7443d9f (commit 1)

---

**Total deviations:** 2 auto-fixed (ambas Rule 2 — UX/legibilidade críticas).
**Impact on plan:** Nenhuma mudança de escopo. Decisões internas que melhoram robustez sem alterar interface ou contratos.

## Issues Encountered

- **PHP CLI fora do PATH.** `php` direto no Bash falhou; usei `/c/xampp/php/php.exe` para artisan + lint. Não bloqueante.
- **Sidebar — divergência de `page` identifier.** O item NPS antigo tinha `page: 'Nps'` (top-level match via startsWith). Como agora há 2 filhos sob `Nps/`, atualizei para `page: 'Nps/Index'` e `page: 'Nps/Configuracao'` para que `isActive` distinga as duas. Side-effect potencial: se algum lugar do código verificava `usePage().component === 'Nps'` (sem o sufixo), pode quebrar. Grep não encontrou nenhum uso direto — única referência ao identifier `'Nps'` no `AppLayout.jsx` era a entrada da NAV_TREE que eu atualizei.
- **`isDirty` do useForm.** Conferi com `data` inicial idêntico ao `textos` prop. Quando admin abre a página e nada mudou, isDirty=false → botão Salvar desabilitado (correto).

## Self-Check: PASSED

- [x] 3 rotas `nps.configuracao.*` registradas em `route:list` (confirmado)
- [x] 3 rotas protegidas por `EnsureUserHasRole:admin` (confirmado via `route:list -v`)
- [x] `Pages/Nps/Configuracao.jsx` existe e foi bundled (`public/build/assets/Configuracao-DbPZpe5T.js`)
- [x] `npm run build` verde (8.11s, sem erros)
- [x] Smoke Tinker: `NpsTextRenderer::getTextos()` retorna 11 chaves
- [x] Smoke Tinker: `view('emails.nps.mensal', ...)->render()` produz HTML com `linear-gradient` + nomes de exemplo
- [x] Smoke Tinker: `Configuracao::set('nps_textos', ...)` + read-back funcionam (roundtrip preservado)
- [x] Suite Phase 31 verde (19 testes / 110 assertions OK — zero regressão)
- [x] Commits 7443d9f, f9fdc7b, 8d9db7e, f88ed3d existem (`git log --oneline -6`)

## Known Stubs

Nenhum stub. Todos os 11 campos do form carregam de `textos` prop (que vem do helper); preview renderiza o template Blade real; submit persiste via Configuracao::set.

## User Setup Required

None. A chave `configuracoes.nps_textos` foi populada pela migration do Plan 32-01 com os 11 defaults D-03; admin já consegue acessar `/nps/configuracao` e ver os textos atuais carregados no form.

Em produção (VPS), depois do próximo `git pull` + `npm run build`, a página estará disponível via sidebar (NPS → Configuração NPS) para admins.

## Next Phase Readiness

- **Wave 3 (Plan 32-03 — logo + textos custom em Respond.jsx):** Pode prosseguir em paralelo se ainda não rodou. Sem sobreposição: 32-03 modifica `NpsController::respond()` (não tocado aqui), `Pages/Nps/Respond.jsx` (novo), `app/css/app.css`, `Components/LogoEcf.jsx`. **AppLayout NÃO foi tocado por 32-03**; se foi paralelo, este commit é o último a tocá-lo nesta wave.
- **Wave 3 (Plan 32-04 — página /nps/emails-enviados):** Pode prosseguir. Vai adicionar mais 1 sub-item no MESMO grupo NPS do sidebar — o grupo já está pronto pra receber.

### Gotchas para próximos plans

- **`page: 'Nps'` virou 2 identifiers separados.** Se Plan 32-04 adicionar sub-item, usar `page: 'Nps/EmailsEnviados'` (não `'Nps'`) pra que `isActive` distinga.
- **Grupo NPS tem `permission: 'core.nps'`.** Quem não tem essa permission não vê o grupo inteiro. Admin sempre tem (via short-circuit isAdmin); consultor/mentor têm; analistas/publicadores/gestores/líderes não — coerente com o comportamento legado do item NPS.
- **Plan 32-04 deve gerar sub-item "Emails enviados" com `excludeRoles` idêntico ao "Configuração NPS"** se for admin-only — mesmo padrão de gating.

---
*Phase: 32-customizacao-nps*
*Completed: 2026-06-11*
