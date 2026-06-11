---
phase: 32-customizacao-nps
plan: 01
subsystem: email
tags: [nps, email, blade, configuracao, kv-store, mailable]

# Dependency graph
requires:
  - phase: 31-nps-mensal-automatizado
    provides: NpsMonthlyMail + NpsDispararMensal + nps_surveys schema + Configuracao KV
provides:
  - tabela nps_email_envios (log de disparos com status enviado/falha)
  - chave configuracoes.nps_textos populada com 11 defaults (D-03)
  - helper App\Support\NpsTextRenderer (render + renderHtml + getTextos + defaults)
  - model App\Models\NpsEmailEnvio
  - partial Blade resources/views/partials/logo-ecf.blade.php (logo ECF inline)
  - template emails/nps/mensal.blade.php table-based com logo + textos customizaveis
  - NpsMonthlyMail reescrito (assinatura array $vars)
  - NpsDispararMensal renderiza placeholders e grava NpsEmailEnvio
affects: [32-customizacao-nps Plan 02, 32-customizacao-nps Plan 03, 32-customizacao-nps Plan 04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Helper estatico em app/Support/ para logica reaproveitavel sem servico/DI"
    - "Mailable burro: recebe array de vars ja renderizadas (separa render do envio)"
    - "Partial Blade com CSS 100% inline para compatibilidade com clientes de email"
    - "renderHtml = e() + nl2br para preservar quebras de linha do textarea sem XSS"
    - "Log de disparo dentro do try/catch interno de Mail::send (sucesso e falha)"

key-files:
  created:
    - database/migrations/2026_06_11_200001_create_nps_email_envios_table.php
    - database/migrations/2026_06_11_200002_seed_nps_textos_configuracao.php
    - app/Models/NpsEmailEnvio.php
    - app/Support/NpsTextRenderer.php
    - resources/views/partials/logo-ecf.blade.php
  modified:
    - app/Mail/NpsMonthlyMail.php
    - resources/views/emails/nps/mensal.blade.php
    - app/Console/Commands/NpsDispararMensal.php
    - tests/Feature/Phase31NpsMonthlyMailTest.php
    - tests/Feature/Phase31NpsDispararMensalTest.php

key-decisions:
  - "Mailable reescrito com array \$vars em vez de 5 named args para isolar render no comando"
  - "renderHtml aplica nl2br depois do e() para preservar quebras de linha do textarea (admin escreve texto plano)"
  - "mesLabelPt agora em minusculo (combina com 'satisfacao ECF — junho/2026' do email_assunto default)"
  - "Log de envio (NpsEmailEnvio) dentro do try/catch interno de Mail::send — garante registro mesmo em falha de envio"
  - "Defaults do CONTEXT D-03 listam 11 chaves (nao 12 como prompt menciona); LOCKED no D-03 prevalece"

patterns-established:
  - "Logo ECF: partial Blade com HTML/CSS inline, suporta prop \$theme = dark|light, gradient vertical azul→rosa→laranja→amarelo (D-01)"
  - "Textos customizaveis NPS: 1 chave JSON 'nps_textos' em configuracoes, defaults via NpsTextRenderer::defaults() + merge defensivo em getTextos()"
  - "Placeholders suportados: {nome_estrategista}, {nome_analista}, {nome_empresa}, {mes_referencia}, {bloco_analista}"

requirements-completed: [REQ-32-01, REQ-32-02, REQ-32-03, REQ-32-05]

# Metrics
duration: 45min
completed: 2026-06-11
---

# Phase 32 Plan 01: Fundacao — schema, helper, log, email com logo Summary

**Tabela nps_email_envios + helper NpsTextRenderer + partial logo ECF + email template reescrito com placeholders customizaveis lidos da chave configuracoes.nps_textos**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-06-11T19:55:00Z
- **Completed:** 2026-06-11T20:40:54Z
- **Tasks:** 8 (todas as truths do must_haves)
- **Files modified:** 10 (5 criados + 5 modificados)

## Accomplishments
- Schema do log de envios pronto (nps_email_envios com index company_id+created_at)
- Helper NpsTextRenderer com defaults D-03 + render() + renderHtml() (e()+nl2br) + getTextos() com merge defensivo
- Partial Blade resources/views/partials/logo-ecf.blade.php seguindo spec exato D-01 (gradient vertical azul→rosa→laranja→amarelo, Montserrat bold 42px + uppercase 12px)
- Template emails/nps/mensal.blade.php reescrito em estrutura table-based para compatibilidade com Outlook/Gmail, header dark com logo + card branco com saudacao/corpo/CTA/assinatura
- NpsMonthlyMail reescrito com assinatura `array $vars` (assuntoRender + 5 textos renderizados + linkPesquisa + mesReferencia)
- NpsDispararMensal carrega textos da config antes do loop, renderiza por empresa com placeholders, grava NpsEmailEnvio status=enviado em sucesso ou status=falha em catch interno
- 19 testes Phase 31 continuam verdes (5 do Mailable + 7 do comando + 7 do Submit/Respond)
- Smoke manual local validado: empresa com aniversario hoje → 1 survey + 1 NpsEmailEnvio status=enviado + email renderizado com logo e textos no log

## Task Commits

Cada commit corresponde a um bloco coeso do escopo:

1. **Schema + helper + model:** `ad59a67` (feat)
   - 2 migrations (tabela + seed) + NpsEmailEnvio + NpsTextRenderer
2. **Logo partial + template email + Mailable:** `f8a18fd` (feat)
   - partials/logo-ecf.blade.php + mensal.blade.php reescrito + NpsMonthlyMail nova assinatura
3. **Comando + ajustes nos testes Phase 31:** `e897b59` (feat)
   - NpsDispararMensal renderiza via NpsTextRenderer + grava NpsEmailEnvio + testes ajustados pra nova assinatura

**Plan metadata commit:** pendente (sera criado apos este SUMMARY)

## Files Created/Modified

### Criados
- `database/migrations/2026_06_11_200001_create_nps_email_envios_table.php` — Tabela do log de envios
- `database/migrations/2026_06_11_200002_seed_nps_textos_configuracao.php` — Popula chave nps_textos com 11 defaults D-03
- `app/Models/NpsEmailEnvio.php` — Model do log (fillable + relacionamentos survey/company)
- `app/Support/NpsTextRenderer.php` — Helper de render com placeholders + defaults + getTextos
- `resources/views/partials/logo-ecf.blade.php` — Logo ECF Blade reutilizavel (inline CSS para emails)

### Modificados
- `app/Mail/NpsMonthlyMail.php` — Reescrito: recebe array $vars com textos ja renderizados
- `resources/views/emails/nps/mensal.blade.php` — Reescrito: table-based, header dark com logo, card branco com vars customizaveis
- `app/Console/Commands/NpsDispararMensal.php` — Carrega textos + renderiza por empresa + grava NpsEmailEnvio
- `tests/Feature/Phase31NpsMonthlyMailTest.php` — Ajustado para nova assinatura array $vars
- `tests/Feature/Phase31NpsDispararMensalTest.php` — T7 ajustado para verificar vars['corpoRender']

## Decisions Made

- **Mailable burro com array $vars:** Em vez de manter 5 named args + lógica condicional no template (Phase 31), o NpsMonthlyMail agora recebe os textos JÁ renderizados pelo NpsTextRenderer no comando. Isola toda a lógica de substituição de placeholders em um único lugar (helper) e mantém o template Blade puro.
- **renderHtml = e() + nl2br:** O admin edita os textos como textarea (texto plano com quebras de linha). Para preservar a apresentação no email sem abrir buraco de XSS, escapamos cada variável com `e()` ANTES do `str_replace`, depois aplicamos `nl2br` no resultado. Resultado: nomes de empresa com `<script>` não viram script, mas quebras `\n` viram `<br>`.
- **mesLabelPt em minúsculo:** O default do `email_assunto` é "Pesquisa mensal de satisfação ECF — {mes_referencia}". Em minúsculo combina com a frase. Caso o admin reescreva o assunto e queira maiúscula, é só usar `Junho/2026` no template literal — não precisa que o helper devolva exatamente um formato.
- **Log de envio dentro do try/catch interno de Mail::send:** O `try` externo do loop por empresa captura falhas de DB (criação do survey, etc); o `try` interno em volta do `Mail::send` captura falhas SMTP/render e garante que o NpsEmailEnvio é gravado mesmo em falha — admin sempre vê o que aconteceu na futura página `/nps/emails-enviados`.
- **11 chaves (não 12) no JSON `nps_textos`:** O CONTEXT D-03 (LOCKED) lista 11 chaves explícitas (5 do email + 6 da página). O prompt do orquestrador mencionou "12" — usei o canônico D-03 = 11. Plans subsequentes (W2 página /nps/configuracao, W3 página /nps/emails-enviados) devem refletir 11.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Renderização defensiva no Mailable**
- **Found during:** Implementação do `content()` no NpsMonthlyMail
- **Issue:** Se o array `$vars` chegar com uma chave ausente (bug no chamador), a view quebra com `Undefined index`
- **Fix:** Adicionado `??` em cada chave com default seguro (`''` para textos, `'#'` para link, fallback `'Pesquisa de satisfação ECF'` para o assunto)
- **Files modified:** `app/Mail/NpsMonthlyMail.php`
- **Verification:** Defensive checks visíveis nas linhas `envelope()` e `content()`
- **Committed in:** f8a18fd (commit 2)

**2. [Rule 2 - Missing Critical] mesLabelPt em minúsculo + aspas literais no template**
- **Found during:** Verificação de coerência texto "junho/2026" no email
- **Issue:** mesLabelPt da Phase 31 retorna "Junho/2026" (maiúsculo), mas o `email_assunto` default no D-03 escreve "satisfação ECF — {mes_referencia}" naturalmente com minúsculo. Disparidade visual.
- **Fix:** mesLabelPt agora devolve em minúsculo. Plans subsequentes do W2 (página de config) podem expor formato pro admin via doc do placeholder se necessário.
- **Files modified:** `app/Console/Commands/NpsDispararMensal.php`
- **Verification:** Smoke local: assunto gravado em NpsEmailEnvio = "Pesquisa mensal de satisfação ECF — junho/2026"
- **Committed in:** e897b59 (commit 3)

---

**Total deviations:** 2 auto-fixed (1 bug fix defensivo, 1 critical alinhamento texto)
**Impact on plan:** Ambos os fixes são pequenos e diretamente alinhados com os defaults D-03. Nenhuma mudança de escopo.

## Issues Encountered

- **IDE diagnostics stale:** Após reescrever Mailable e testes, o IDE reportou "Unknown named argument $companyName" — falsos positivos comparando com a versão anterior do arquivo. Linter PHP (`php -l`) confirma 0 erros de sintaxe. Suite Phase 31 verde (19/19 OK, 110 assertions).
- **Mail driver = log em dev:** Foi possível fazer smoke completo (1 empresa → 1 survey + 1 NpsEmailEnvio status=enviado) sem SMTP real. Email rendered foi inspecionado em `storage/logs/laravel.log` e confere com expectativa (logo gradient inline + textos com `nl2br`).
- **Migrations rodadas localmente (dev DB):** Tabela `nps_email_envios` existe localmente. Em produção (VPS) precisa rodar `php artisan migrate` no deploy.

## Self-Check: PASSED

- [x] Migration tabela executou (`nps_email_envios` existe — verificado via `Schema::hasTable`)
- [x] Migration seed populou chave (`nps_textos` com 11 chaves — verificado via `array_keys(NpsTextRenderer::getTextos())`)
- [x] Helper renderiza placeholders (smoke: `render('Olá {nome}', ['nome' => 'Maria'])` → "Olá Maria")
- [x] Template renderiza com logo (`grep -c "linear-gradient"` no output → 1)
- [x] Template renderiza CTA renderizado (`grep -c "Responder"` → 1)
- [x] Comando dispara survey + log (smoke: 1 NpsEmailEnvio status=enviado criado)
- [x] Suite Phase 31 verde (19 testes / 110 assertions OK)
- [x] Commits ad59a67, f8a18fd, e897b59 existem (`git log`)

## Known Stubs

Nenhum stub. Todos os textos do email são lidos da config (com defaults defensivos em runtime via `NpsTextRenderer::defaults()`).

## User Setup Required

None nesta fase — defaults são populados via migration de seed. Em produção, ao rodar `php artisan migrate` no deploy, a chave `nps_textos` já estará populada com os defaults D-03. Plans W2/W3 trarão UI para editar.

## Next Phase Readiness

- **Wave 2 (Plan 02 — página /nps/configuracao):** Pode começar — helper getTextos() retorna array pronto pro form; NpsTextRenderer::render() já está disponível para o endpoint de preview.
- **Wave 3 (Plan 03 — página /nps/emails-enviados):** Pode começar — model NpsEmailEnvio + relacionamentos survey/company prontos; index `(company_id, created_at)` cobre o filtro principal.
- **Wave 3 (Plan 04 — logo no Respond.jsx):** Pode começar — spec D-01 do logo está formalizada no partial Blade; portar pra JSX é trivial.

### Gotchas para próximos plans

- **Mailable agora recebe `array $vars`.** Se algum outro lugar do código instanciar `NpsMonthlyMail`, precisará ajustar. Verificado: só o `NpsDispararMensal` instancia o Mailable (`grep -r "new NpsMonthlyMail"` retorna só o comando).
- **`mes_referencia` está em minúsculo.** Se o W2 expuser preview, o exemplo fixo do D-05 ("nome_estrategista = 'Nathália'" etc.) deve usar "junho/2026" (não "Junho/2026") para refletir o que o admin verá no email real.
- **renderHtml aplica nl2br no resultado.** Se W2 fizer preview no iframe, isso já está embutido no helper — não precisa duplicar a lógica no controller.

---
*Phase: 32-customizacao-nps*
*Completed: 2026-06-11*
