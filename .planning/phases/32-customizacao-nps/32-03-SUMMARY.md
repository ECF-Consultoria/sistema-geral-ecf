---
phase: 32-customizacao-nps
plan: 03
subsystem: nps-public-page
tags: [nps, logo, customizacao, inertia, respond, jsx, montserrat]

# Dependency graph
requires:
  - phase: 32-customizacao-nps
    plan: 01
    provides: NpsTextRenderer + chave configuracoes.nps_textos + partial Blade logo-ecf
provides:
  - componente React resources/js/Components/LogoEcf.jsx (equivalente JSX do partial Blade)
  - import Google Fonts Montserrat (300+700) em resources/css/app.css
  - NpsController::respond() passa survey.textos com 6 textos renderizados pro front
  - Respond.jsx renderiza LogoEcf no header + 6 strings dinamicas das perguntas/labels
affects: [Wave 3 Plan 32-04 (Emails enviados — pode reaproveitar LogoEcf no futuro), futuras phases que precisem da marca ECF em paginas React]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "LogoEcf como componente React 1:1 com partial Blade — mesmo HTML/CSS inline garante paridade visual entre email e pagina web"
    - "Prop theme reservada para evolucao futura sem quebrar API (atualmente noop, igual ao partial Blade)"
    - "Textos da pagina pre-renderizados no backend antes do Inertia::render — mesmo padrao 'Mailable burro' do Plan 32-01 (zero logica de placeholder no React)"
    - "Fallback defensivo client-side: se survey.textos vier ausente (survey legacy), strings hardcoded equivalentes mantem o form funcionando"

key-files:
  created:
    - resources/js/Components/LogoEcf.jsx
  modified:
    - resources/css/app.css
    - app/Http/Controllers/NpsController.php
    - resources/js/Pages/Nps/Respond.jsx

key-decisions:
  - "LogoEcf usa style={{}} inline (nao classes Tailwind) — garante 1:1 com partial Blade que tambem usa inline (clientes de email exigem inline)"
  - "Import Montserrat no topo do app.css (antes das diretivas Tailwind) — Google Fonts so funciona em app web; no email Blade continua caindo em fallback Helvetica"
  - "Backend pre-renderiza placeholders e envia survey.textos pronto — front nao re-implementa lógica de str_replace"
  - "Fallback defensivo no front (textos.perg_X || hardcoded) — survey criada antes da Phase 32 que por algum motivo nao traga o payload novo continua funcionando"
  - "Removido subtitulo redundante 'ECF Consultoria' do header (logo ja carrega a marca); rodape 'ECF Consultoria · Suas respostas sao confidenciais' mantido para reforco de confidencialidade"

requirements-completed: [REQ-32-01, REQ-32-06]

# Metrics
duration: 8min
completed: 2026-06-11
---

# Phase 32 Plan 03: Logo no Respond.jsx + perguntas dinâmicas Summary

**Componente LogoEcf React + import Montserrat + NpsController::respond renderiza survey.textos + Respond.jsx usa logo no header e 6 textos dinamicos das perguntas/labels.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-06-11T21:05:00Z
- **Completed:** 2026-06-11T21:13:00Z
- **Tasks:** 3 commits atomicos (componente+css, controller, JSX)
- **Files modified:** 4 (1 criado + 3 modificados)

## Accomplishments

- `resources/js/Components/LogoEcf.jsx` criado seguindo spec D-01 (Montserrat 700 42px "ECF" + barra vertical 4px×32px gradient azul→rosa→laranja→amarelo + subtitulo "Consultoria & Assessoria" Montserrat 300 12px uppercase letter-spacing 0.12em) — 1:1 com partial Blade do Plan 32-01
- `resources/css/app.css` recebeu `@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap')` no topo (antes das diretivas Tailwind) — confirmei que nao existia import previo via grep
- `NpsController::respond()` agora carrega `NpsTextRenderer::getTextos()`, monta `$vars` com `nome_estrategista`/`nome_analista`/`nome_empresa` (+ `mes_referencia` e `bloco_analista` como string vazia pra evitar placeholder cru) e renderiza 6 textos (`perg_estrategista`, `perg_analista`, `perg_empresa`, `perg_comentario_label`, `perg_comentario_placeholder`, `perg_nome_label`) enviando como `survey.textos` no payload Inertia
- `Respond.jsx` substitui o quadradinho amarelo com letra "E" pelo `<LogoEcf />` centralizado no header; substitui as 3 perguntas hardcoded ("Como você avalia o trabalho do estrategista X?" / "...analista Y?" / "A ECF está atendendo suas expectativas?") + 3 labels/placeholders (nome, comentario label, comentario placeholder) pelos valores de `survey.textos.*` com fallback defensivo
- Bloco condicional `survey.tem_analista` preservado (mentoria pura continua omitindo a pergunta do analista)
- `npm run build` verde (8.09s) — asset `Respond-8KBdXD62.js` gerado
- 19 testes Phase 31 continuam verdes (110 assertions OK) — zero regressao
- Smoke Tinker validado: alterar `perg_estrategista` pra "Avalie o atendimento do nosso estrategista {nome_estrategista} para a {nome_empresa}" e renderizar com vars (Nathalia / Loja Maria) gera o texto esperado; restaurado default ao final

## Task Commits

1. **LogoEcf + Montserrat:** `d6003eb` (feat)
   - `resources/js/Components/LogoEcf.jsx` criado + `resources/css/app.css` ganhou import Google Fonts Montserrat
2. **NpsController::respond:** `ee5a86c` (feat)
   - `respond()` ganha 19 linhas: carrega textos via helper, monta `$vars`, renderiza 6 chaves e adiciona `textos` ao payload `survey`
3. **Respond.jsx:** `1c89633` (feat)
   - Import `LogoEcf` + logo no header (substitui quadradinho amarelo) + 6 strings extraidas de `survey.textos` com fallback defensivo

**Plan metadata commit:** pendente (será criado apos este SUMMARY)

## Files Created/Modified

### Criados
- `resources/js/Components/LogoEcf.jsx` — Componente React do logo ECF (D-01)

### Modificados
- `resources/css/app.css` — Import Google Fonts Montserrat 300+700 no topo do arquivo
- `app/Http/Controllers/NpsController.php` — `respond()` ganha `survey.textos` renderizado no payload
- `resources/js/Pages/Nps/Respond.jsx` — Logo no header + 6 textos das perguntas/labels dinamicos

## Decisions Made

- **LogoEcf com style inline (em vez de Tailwind):** O partial Blade do Plan 32-01 usa CSS inline obrigatoriamente (clientes de email descartam style externo). Manter a versao React com style inline garante 1:1 visual entre email e pagina web — qualquer ajuste futuro no logo se faz em 2 lugares mas com a mesma estrutura. Trade-off: classes Tailwind seriam mais ergonomicas, mas a paridade entre os dois renderers vale mais.
- **Import Montserrat no app.css (web only):** No email Blade o partial ja cai em fallback Helvetica porque Google Fonts nao carrega em SMTP. Na web o import Google Fonts garante a renderizacao correta no `/nps/{token}`. Confirmei via grep que nao havia import previo de Montserrat — `Inter` e `Manrope` ja eram usados via system fonts.
- **Pre-renderizar textos no backend:** Mesmo padrao do Plan 32-01 (Mailable recebe array `$vars` ja renderizado pelo `NpsTextRenderer` no comando). Mantem **uma unica camada de logica de placeholder** (helper PHP) e o front fica burro — fácil de migrar a lógica de placeholder no futuro sem tocar React.
- **`mes_referencia` e `bloco_analista` como string vazia no `$vars` da pagina:** Esses dois placeholders so fazem sentido no email (assunto/corpo). Se o admin colocar `{mes_referencia}` por engano em alguma pergunta da pagina, o str_replace silencioso transforma em string vazia em vez de deixar o placeholder cru no texto.
- **Fallback defensivo client-side com strings equivalentes:** O backend SEMPRE manda `survey.textos` agora, mas se por qualquer motivo a prop nao chegar (ex: survey legacy renderizada antes do deploy desta wave, ou bug futuro no controller) o React mantem strings hardcoded equivalentes ao default — mensagem ao usuario nunca fica vazia.
- **Removido subtitulo redundante "ECF Consultoria" do header:** O LogoEcf ja contem "Consultoria & Assessoria" no proprio logo — mostrar "ECF Consultoria" abaixo dele e redundancia visual. Mantido o rodape "ECF Consultoria · Suas respostas sao confidenciais" porque a confidencialidade e a informacao chave la.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] mes_referencia e bloco_analista no $vars da pagina**
- **Found during:** Implementacao do `respond()`
- **Issue:** O plano so listou 3 vars (nome_estrategista, nome_analista, nome_empresa) mas se o admin colocar `{mes_referencia}` ou `{bloco_analista}` por engano em alguma das 6 perguntas da pagina, o str_replace nao tem o que substituir → placeholder cru aparece pro cliente
- **Fix:** Adicionado `mes_referencia` e `bloco_analista` como string vazia no array `$varsPagina` → placeholder vira string vazia (decisao silenciosa e defensiva)
- **Files modified:** `app/Http/Controllers/NpsController.php`
- **Verification:** Comentario inline no codigo explicando por que esses 2 estao vazios
- **Committed in:** ee5a86c (commit 2)

**2. [Rule 1 - Cosmético] Removido subtitulo redundante "ECF Consultoria" do header**
- **Found during:** Refatoracao do `Respond.jsx` para receber o logo
- **Issue:** O header antigo tinha o quadradinho amarelo + "Avaliação de Atendimento" + nome empresa + "ECF Consultoria" (linha extra). Com o LogoEcf agora carregando "Consultoria & Assessoria" dentro do proprio logo, repetir "ECF Consultoria" abaixo do nome da empresa fica redundante
- **Fix:** Removida a linha `<p className="text-white/40 text-sm mt-1">ECF Consultoria</p>` — header agora tem logo + titulo + nome empresa, mais limpo
- **Files modified:** `resources/js/Pages/Nps/Respond.jsx`
- **Verification:** Rodape `ECF Consultoria · Suas respostas são confidenciais` mantido porque ali a info chave é a confidencialidade
- **Committed in:** 1c89633 (commit 3)

---

**Total deviations:** 2 auto-fixed (1 Rule 2 — robustez defensiva, 1 Rule 1 — limpeza visual sem mudanca de escopo).
**Impact on plan:** Nenhuma mudanca contratual ou de escopo. Ambas melhoram qualidade sem alterar API ou comportamento esperado pelo usuario final.

## Issues Encountered

- **PHP CLI fora do PATH:** Como nos plans anteriores, usei `/c/xampp/php/php.exe` para `php -l` e `php artisan tinker`. Nao bloqueante.
- **Asset bundle Respond foi regenerado:** Como `public/build/` esta no `.gitignore`, o asset novo `Respond-8KBdXD62.js` nao entra em commit — deploy de producao precisa rodar `npm run build` no VPS apos `git pull`.
- **Sem teste automatizado para Respond.jsx:** A Phase 31 ja tinha 19 testes Feature/Unit que cobrem o controller + Mailable + comando; este plan nao adicionou testes pq toda a logica nova esta concentrada no `respond()` (que se beneficia da estrutura existente — o teste manual via Tinker confirma que helper renderiza corretamente) e na camada React (sem suite Jest no projeto).

## Self-Check: PASSED

- [x] `resources/js/Components/LogoEcf.jsx` existe (39 linhas, exporta default)
- [x] `resources/css/app.css` tem import Montserrat na linha 5 (antes das diretivas Tailwind)
- [x] `NpsController::respond()` carrega `NpsTextRenderer::getTextos()` (verificado via grep)
- [x] `survey.textos` adicionado ao payload Inertia com 6 chaves renderizadas
- [x] `Respond.jsx` importa `LogoEcf` de `@/Components/LogoEcf` (verificado via grep)
- [x] `<LogoEcf />` renderizado no header centralizado
- [x] Bloco `survey.tem_analista` preservado (mentoria pura omite pergunta analista)
- [x] `npm run build` verde (8.09s, asset `Respond-8KBdXD62.js` gerado)
- [x] 19 testes Phase 31 verdes (110 assertions OK)
- [x] Smoke Tinker: roundtrip de edicao de texto + render com placeholders confirmado
- [x] Commits `d6003eb`, `ee5a86c`, `1c89633` existem (`git log`)

## Known Stubs

Nenhum stub. Todos os 6 textos da pagina sao carregados de `survey.textos` (que vem do helper); fallback hardcoded so existe como rede de seguranca defensiva.

## User Setup Required

None. A chave `configuracoes.nps_textos` ja foi populada pela migration do Plan 32-01 com defaults D-03. Em producao (VPS), depois do proximo `git pull` + `npm run build`, qualquer cliente que acessar `/nps/{token}` vai ver o logo novo + textos atuais da config (defaults se ninguem editou ainda, ou textos editados via `/nps/configuracao` do Plan 32-02).

## Next Phase Readiness

- **Wave 3 (Plan 32-04 — pagina `/nps/emails-enviados`):** Pode prosseguir. Sem sobreposicao de arquivos com este plan — Plan 32-04 vai criar `Pages/Nps/EmailsEnviados.jsx`, adicionar rota nova e sub-item no sidebar (grupo NPS ja existe, Plan 32-02 deixou pronto).
- **`LogoEcf` disponivel para reuso:** Qualquer pagina React que precisar da marca ECF oficial pode importar de `@/Components/LogoEcf`.

### Gotchas para próximos plans

- **`survey.textos` pode ser undefined em fluxos paralelos.** O fallback defensivo no Respond.jsx cobre isso, mas qualquer codigo que toque o payload do `respond()` deve preservar a chave `textos`.
- **Import Montserrat aumenta o tempo de FCP em ~50ms (Google Fonts).** Sem impacto em UX percebido (display=swap), mas se for problema medido no futuro, considerar self-hosting da fonte ou preload.
- **Spec D-01 do logo agora em 2 lugares.** `resources/views/partials/logo-ecf.blade.php` (email) e `resources/js/Components/LogoEcf.jsx` (web). Mudancas visuais no logo precisam ser feitas nos 2 arquivos — manter sincronia.

---
*Phase: 32-customizacao-nps*
*Completed: 2026-06-11*
