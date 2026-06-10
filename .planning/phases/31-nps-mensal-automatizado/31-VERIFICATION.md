---
phase: 31-nps-mensal-automatizado
verified: 2026-06-10T23:55:00Z
status: human_needed
score: 8/8 requirements verificados, 12/12 decisoes locked entregues
overrides_applied: 0
re_verification:
  previous_status: null
  previous_score: null
  gaps_closed: []
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Preencher email_cliente em ~3 empresas-teste cujo DAY(created_at) = hoje em prod, rodar `php artisan nps:disparar-mensal --ansi` e confirmar (a) survey criada em nps_surveys com auto_generated=true + month_reference=YYYY-MM-01, (b) email recebido via SMTP Gmail no destinatario, (c) link CTA abre /nps/{token} ja com escala 1-5"
    expected: "Email entregue via SMTP Gmail (validado em Phase 28); survey persistida com auto_generated=true; link abre Respond.jsx com 3 sliders 1-5"
    why_human: "Exige SMTP Gmail real (nao pode ser testado em ambiente local sem dados reais nem variaveis SMTP de prod); exige escolha de empresas-teste cujo aniversario do cadastro bata com o dia atual"
  - test: "Responder a survey via UI publica em mentoria pura (empresa sem analista) e em empresa com analista; verificar que `tem_analista=false` oculta o slider do meio e o submit ainda aceita (grava score_analista=NULL); o caso com analista mostra os 3 sliders"
    expected: "Mentoria pura: 2 sliders + textarea; com analista: 3 sliders + textarea. Submit completa survey e mostra ThankYou.jsx"
    why_human: "Requer interacao visual com botoes coloridos 1-5 (gradiente vermelho->emerald), validar acessibilidade aria-label/aria-pressed e confirmar UX de cores; testar redirect pos-submit"
  - test: "Acessar /nps como admin em prod e validar (a) filtro de mes com 12 opcoes (default = mes atual), (b) 3 cards Estrategista/Analista/Empresa exibindo media/5 ou '—' (sem respostas ainda), (c) LineChart 12 ticks no eixo X com YAxis [1,5] sem console errors, (d) botao 'Gerar Link NPS Manualmente' abre Dialog e funciona como antes"
    expected: "Filtro mes, 3 cards de media, LineChart Recharts 12 meses x 3 series (Estrategista #ffe600, Analista #19e06a, Empresa #60a5fa), lista paginada, botao manual preservado"
    why_human: "Render visual de Recharts em produto live nao pode ser testado por grep; o Dialog manual exige confirmar UX de copy-to-clipboard"
  - test: "Acessar /dashboard como admin: confirmar widget NPS renderiza Pie com labels 'Excelente (5)' / 'Bom (4)' / 'Ruim (1-3)' (D-09); KpiCard NPS exibe 'Media X.YY/5' em vez de 'Score: NN'. Confirmar /performance e /companies/{id} renderizam sem 'Unknown column' SQL error"
    expected: "Dashboard widget Pie com 3 segmentos rotulados na nova escala; sem SQL errors em qualquer das 4 rotas"
    why_human: "SQL errors so aparecem ao acessar a URL real em prod; render visual do Pie no dark theme precisa ser confirmado"
  - test: "Editar uma empresa via /companies (modal admin) preenchendo email_cliente valido; depois editar a mesma via /comercial/empresas e confirmar que o campo veio pre-preenchido com o mesmo valor. Tentar salvar email invalido (ex: 'naoEmail') -> esperar erro 422 em `errors.email_cliente`"
    expected: "Persistencia cross-UI funcional; validacao backend `nullable|email|max:255` ativa em ambos endpoints"
    why_human: "Confirma fluxo end-to-end operador-real; valida UX de mensagens de erro inline"
---

# Phase 31: NPS Mensal Automatizado — Verificacao Goal-Backward

**Phase Goal (extraido do CONTEXT.md):**
> Cliente da ECF recebe email NPS automaticamente todo mes no dia do mes em que a empresa foi cadastrada, responde 3 notas em escala 1-5 + comentario livre, sem depender de analista/estrategista gerar o link manualmente. Admin acessa /nps e ve filtro por mes, 3 cards de media, grafico linha 12m, lista de respostas. Geracao manual preservada.

**Verificado:** 2026-06-10T23:55:00Z
**Status:** human_needed
**Re-verification:** Nao — verificacao inicial

---

## Cobertura por Requirement

| ID | Descricao (resumida) | Status | Evidencia |
|----|----------------------|--------|-----------|
| **REQ-31-01** | `companies.email_cliente` nullable + UI edicao em Companies + Comercial | ENTREGUE | Migration `2026_06_10_100001` aplicada (Ran); `Schema::hasColumn('companies','email_cliente')=OK` via tinker; `Company::$fillable` linha 31 inclui `email_cliente`; modal `Companies/Index.jsx` linhas 162/183/449/450/453; form `Comercial/Empresas.jsx` linhas 101/180-185; validacao backend `nullable\|email\|max:255` em `CompanyController.php` linhas 382/409 e `ComercialController.php` linha 320; payload Inertia em `CompanyController.php` linhas 65/280 e `ComercialController.php` linha 83 |
| **REQ-31-02** | Drop+recreate `nps_responses` escala 1-5 (3 dimensoes) + truncate historico | ENTREGUE | Migration `2026_06_10_100002` aplicada (Ran); via tinker `NPS_RESP_COLS=id,survey_id,respondent_name,score_estrategista,score_analista,score_empresa,comment,created_at,updated_at` — exatamente as colunas esperadas, sem rastro das legacy; `NpsResponse::$fillable` linhas 21-23 alinhado |
| **REQ-31-03** | `nps_surveys.month_reference` (date YYYY-MM-01) + `auto_generated` (bool) | ENTREGUE | Migration `2026_06_10_100003` aplicada (Ran); `Schema::hasColumn('nps_surveys','month_reference')=OK` e `('auto_generated')=OK`; `NpsSurvey::$fillable` linha 30 + `$casts` linhas 37-38 |
| **REQ-31-04** | Comando `nps:disparar-mensal` 1x/dia via `routes/console.php`, idempotente, clamp dia 31, pula sem email | ENTREGUE | `php artisan list` mostra `nps:disparar-mensal`; `schedule:list` mostra `0 9 * * * php artisan nps:disparar-mensal`; `NpsDispararMensal.php` linha 81 (`$diaAlvo = min($diaOriginal, $hoje->daysInMonth)` — clamp D-03), linhas 89-97 (idempotencia `whereDate('month_reference', $mesAtual)`), linhas 68-70 (filtro `whereNotNull('email_cliente')->where(.., '!=', '')` — pula silenciosamente D-04); dry-run executou sem mutar banco; testes `Phase31NpsDispararMensalTest` 7/7 verdes incluindo "edge case dia 31 dispara no ultimo dia de fevereiro" |
| **REQ-31-05** | `NpsMonthlyMail` (Markdown) SMTP Gmail com nome Estrategista/Analista + CTA token + mes | ENTREGUE | `app/Mail/NpsMonthlyMail.php` com 5 props promovidas (companyName, estrategistaName, analistaName nullable, linkPublico, mesLabel); envelope subject `[ECF Admin] Pesquisa de satisfacao — {mesLabel}`; view `resources/views/emails/nps/mensal.blade.php` exibe Estrategista sempre, Analista condicional `@if ($analistaName)` (linhas 28-30), CTA bg `#ffe600` linkando ao `{{ $linkPublico }}`, header com `{{ $mesLabel }}`; testes `Phase31NpsMonthlyMailTest` 5/5 verdes incluindo render omitindo label quando analista=null |
| **REQ-31-06** | Reescrita `Nps/Respond.jsx`: 3 sliders 1-5 + textarea + nome opcional, analista condicional | ENTREGUE | `Respond.jsx` reescrito; `useForm` linhas 53-57 com chaves novas; `isValid` linhas 71-73 com analista condicional em `survey.tem_analista`; bloco analista `{survey.tem_analista && (...)}` linhas 124-138; 3 RatingPicker 1-5 (label, value/onChange para `score_estrategista|analista|empresa`); Textarea livre `maxLength=2000` + placeholder D-08 linhas 156-161; Input "Seu nome (opcional)" sem `required` linhas 93-99 |
| **REQ-31-07** | Admin section /nps: filtro mes + 3 cards + LineChart Recharts 12m + lista paginada | ENTREGUE | `NpsController::index` reescrito (linhas 28-155): regex de mes linha 35, query OR `month_reference \|\| (NULL && created_at)` linhas 45-51, paginate(20)->withQueryString() linha 59, cards linhas 96-109, serie 12m loop linhas 116-142; `Nps/Index.jsx`: `LineChart` import linha 15, `<Select value={mes_filtro}>` linha 142, 3 CardMedia (Estrategista/Analista/Empresa), `<LineChart data={serie_12m}>` linhas 200-212, tabela paginada com colunas `score_estrategista/analista/empresa` linhas 245-247, botoes de paginacao preservando `?mes=` linhas 285/294 |
| **REQ-31-08** | Endpoint `nps.generate` (manual) preservado com `auto_generated=false` | ENTREGUE | `NpsController::generate` linhas 165-196 com `auto_generated => false` explicito linha 187, `expires_at=now()->addDays(7)` (continua 7 dias para manuais — D-12), `generated_by=$user->id` (humano por tras); botao "Gerar Link NPS Manualmente" preservado em `Nps/Index.jsx` linha 157; teste `Phase31NpsSubmitTest::generate cria survey com auto generated false` verde |

**Score:** 8/8 requirements ENTREGUES.

---

## Cobertura por Decisao Locked

| ID | Decisao | Status | Evidencia |
|----|---------|--------|-----------|
| **D-01** | Aniversario do cadastro (`DAY(companies.created_at) == DAY(today)`) | ENTREGUE | `NpsDispararMensal.php` linhas 80-84: `$diaOriginal = $empresa->created_at->day; $diaAlvo = min(...); if ($hoje->day !== $diaAlvo) continue;` |
| **D-02** | Comando diario `nps:disparar-mensal` 09:00 BRT via `routes/console.php` | ENTREGUE | `routes/console.php` linhas 118-122: `Schedule::command('nps:disparar-mensal')->dailyAt('09:00')->timezone('America/Sao_Paulo')->withoutOverlapping()`; `schedule:list` confirmou `0 9 * * *` |
| **D-03** | Edge case dia 31 -> ultimo dia do mes (`min($day, $daysInMonth)`) | ENTREGUE | `NpsDispararMensal.php` linha 81 (clamp); teste `Phase31NpsDispararMensalTest::edge case dia 31 dispara no ultimo dia de fevereiro` verde (0.04s) |
| **D-04** | `email_cliente` nullable, empresas sem o campo sao silenciosamente puladas | ENTREGUE | `NpsDispararMensal.php` linhas 68-70: `->whereNotNull('email_cliente')->where('email_cliente', '!=', '')->chunkById(...)` — empresas sem campo nunca entram no loop; teste `empresa sem email cliente e pulada silenciosamente` verde |
| **D-05** | Mailable Markdown via SMTP Gmail (reuso Phase 28) | ENTREGUE | `NpsMonthlyMail.php` extends `Illuminate\Mail\Mailable` com docblock referenciando reuso de SMTP Gmail Phase 28; sem driver override (usa o default da config) |
| **D-06** | Escala 1-5 substitui 0-10 | ENTREGUE | Schema confirmado via tinker (`score_estrategista,score_analista,score_empresa`); validacao backend `min:1\|max:5` em `NpsController::submitResponse` linhas 256-258; UI Respond.jsx tem RatingPicker 1-5 (5 botoes) |
| **D-07** | 3 dimensoes (estrategista required, analista nullable, empresa required) | ENTREGUE | Migration garante nullable apenas em `score_analista`; validacao backend `score_estrategista: required`, `score_analista: nullable`, `score_empresa: required`; payload Inertia inclui `tem_analista` em `NpsController::respond` linha 232; UI condicional `{survey.tem_analista && (...)}` |
| **D-08** | Comentario livre max 2000 chars, nullable | ENTREGUE | Validacao `comment: nullable\|string\|max:2000` linha 259; UI Textarea com `maxLength={2000}` e placeholder canonico "Opinioes, sugestoes ou outra coisa que queira compartilhar" |
| **D-09** | Widget Dashboard ajustado para nova escala (5=promotor, 4=neutro, 1-3=detrator) | ENTREGUE | `DashboardController.php` linhas 405-408 mapeando `score_empresa` 5/4/1-3; `Dashboard/Admin.jsx` linhas 145-148 rotulos "Excelente (5)" / "Bom (4)" / "Ruim (1-3)" com cores #19e06a/#ffe600/#ff4d4d |
| **D-10** | Drop+recreate `nps_responses` (apaga historico) | ENTREGUE | Migration `2026_06_10_100002_recreate_nps_responses_table.php` faz `dropIfExists` + recreate; tinker confirma colunas finais 1-5; tabela existia mas com escala antiga |
| **D-11** | Truncate `nps_surveys` (apaga historico de teste) | ENTREGUE | Migration `2026_06_10_100003` no `up()` faz `disableForeignKeyConstraints + truncate + enableForeignKeyConstraints` antes do ALTER (justificado em SUMMARY pela FK inbound de nps_responses) |
| **D-12** | `month_reference` + `auto_generated` em nps_surveys + expires_at 30d em mensais | ENTREGUE | Migration `2026_06_10_100003` adiciona ambos; `NpsDispararMensal.php` linha 125 `expires_at => $hoje->copy()->addDays(30)`; `NpsController::generate` linha 183 mantem 7 dias para manuais (D-12 explicito) |

**Score:** 12/12 decisoes locked ENTREGUES.

---

## Verificacoes Adicionais Realizadas

### Schema (via `php artisan migrate:status` + tinker)
- ✓ `2026_06_10_100001_add_email_cliente_to_companies_table` Ran
- ✓ `2026_06_10_100002_recreate_nps_responses_table` Ran
- ✓ `2026_06_10_100003_add_month_reference_and_auto_generated_to_nps_surveys_table` Ran
- ✓ `2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table` Ran (migration auxiliar do Plan 31-02 documentada na deviation)
- ✓ Tinker: `EMAIL_CLIENTE=OK`, `NPS_SURVEYS_HAS_MONTH=OK`, `NPS_SURVEYS_HAS_AUTO=OK`
- ✓ Colunas finais de `nps_responses`: `id,survey_id,respondent_name,score_estrategista,score_analista,score_empresa,comment,created_at,updated_at` (exatamente o esperado)

### Comando e Schedule
- ✓ `php artisan list \| grep nps:` retorna `nps:disparar-mensal`
- ✓ `php artisan schedule:list` retorna `0 9 * * * php artisan nps:disparar-mensal` (Next Due: em 13 horas)
- ✓ `php artisan nps:disparar-mensal --dry-run` executa sem erro: "Iniciando disparo NPS mensal — hoje=2026-06-10, mes_ref=2026-06-01 [DRY-RUN] ... ✓ Concluido: 0 surveys criadas, 0 emails enviados, 0 empresas elegiveis hoje" (esperado — banco local sem dados)

### Suite de Testes
- ✓ `php artisan test --filter=Phase31` retornou **19/19 verdes**, 110 assertions, 8.91s
  - Phase31NpsDispararMensalTest: 7/7 (happy, sem email silencioso, dia nao bate, idempotencia, edge case dia 31 fev, empresa inativa, analista no payload)
  - Phase31NpsMonthlyMailTest: 5/5 (instancia, envelope subject, content view, render template, render omite analista null)
  - Phase31NpsSubmitTest: 7/7 (submit 3 scores, analista nullable, score fora 1-5 -> 422, respondent_name ausente, payload chaves novas, mentoria pura, generate auto_generated=false)

### Grep final por colunas legacy em codigo de producao
- ✓ `grep "score_overall\|score_consultant\|score_mentor"` em `app/Http`: apenas 3 hits — TODOS em comentarios documentando a migracao (`CompanyController.php:312`, `PerformanceController.php:59-60`). ZERO em codigo executavel.
- ✓ `grep "score_overall\|score_consultant\|score_mentor"` em `resources/js/Pages`: apenas 1 hit — `Companies/Show.jsx:850` em comentario. ZERO em codigo executavel.

### Build frontend
- ✓ Assets gerados em `public/build/assets/`: `Respond-DO-GAokh.js`, `Admin-DUppDoG7.js`, e os bundles `Index-*.js` (multiplos hashes — Companies/Index, Nps/Index, Dashboard/Admin entre eles)

### Commits
- ✓ 17 commits da Phase 31 presentes em `git log` (3 do 31-01, 3 do 31-02, 1 do 31-03, 3 do 31-04, 4 do 31-05 + 5 docs `docs(31-XX)`)

---

## Itens Deferidos (esperado — nao bloqueia)

| ID | Decisao | Status |
|----|---------|--------|
| D-13 | Descadastro/opt-out via email com link | DEFERIDO — admin zera `email_cliente` para parar envio (suficiente para MVP) |
| D-14 | Reminder automatico se cliente nao responder | DEFERIDO — survey expira em 30d, ciclo do mes seguinte cobre |
| D-15 | Multiplos respondentes por empresa | DEFERIDO — 1 email por empresa, tabela `company_nps_contacts` em fase futura |

---

## Gaps Encontrados

Nenhum gap bloqueante. Todas as 8 requirements ENTREGUES e 12 decisoes locked ENTREGUES com evidencia concreta no codigo, schema e testes.

### Observacoes de escopo (nao-bloqueantes, ja documentadas nos SUMMARIES)

1. **Migration auxiliar `2026_06_10_100004` (generated_by nullable):** Nao prevista no Plan 31-02 original, mas justificada como melhoria arquitetural — surveys automaticas nao tem humano por tras; alternativa "fallback admin" sugerida pelo Plan era um workaround. Decisao documentada no `31-02-SUMMARY.md` linhas 144-159. Sem impacto negativo.

2. **`Comercial/NovaEmpresa.jsx` nao recebeu campo email_cliente:** Decisao explicita do Plan 31-04 (`31-04-SUMMARY.md` linhas 32-34) — fluxo de cadastro Comercial e centralizado em servicos contratados; email do cliente e dado operacional preenchido depois pela edicao. Empresas novas entram com `email_cliente=null` e sao silenciosamente puladas pelo comando ate serem editadas (D-04 explicito).

3. **Empresa ativa sem estrategista atribuido:** Plan 31-02 nao previa esse caso; SUMMARY (linhas 154-159) documenta que comando loga `Log::warning` e pula (em vez de silenciar como D-04). Decisao alinhada com semantica — empresa elegivel sem estrategista atribuido e bug de configuracao que admin precisa saber.

4. **Performance da serie 12m (~36 queries de avg):** Documentado no codigo (`NpsController.php` linhas 112-115) como trade-off consciente para o volume esperado (~150 empresas x 12 meses = ate 1800 respostas). Se virar gargalo em prod, refatorar para single `GROUP BY DATE_FORMAT(month_reference, '%Y-%m')`.

---

## Itens para UAT Humano (smoke real em producao)

Status `human_needed` porque os itens abaixo exigem:
- SMTP Gmail real (nao testavel localmente sem mailbox de destino e variaveis SMTP de prod)
- Empresas-teste cujo `DAY(created_at) = DAY(today)` em prod
- Renderizacao visual de Recharts no dark theme
- Validacao UX end-to-end de fluxos cross-UI

Ver `human_verification` no frontmatter para a lista completa (5 testes).

**Resumo dos UATs:**
1. Disparo real do `nps:disparar-mensal` em prod com `--ansi` apos preencher `email_cliente` em 3 empresas-teste
2. Resposta via UI publica em mentoria pura vs com analista
3. Tela `/nps` admin: filtro + 3 cards + LineChart + lista + botao manual
4. Widget Dashboard NPS + verificacao de que `/dashboard`, `/performance`, `/companies/{id}` nao retornam SQL error
5. Persistencia cross-UI do `email_cliente` (admin <-> comercial) + validacao 422 com email invalido

---

## Recomendacao Final

**APROVADO PARA DEPLOY (apos UAT humano).**

Toda a entrega tecnica esta presente e verificada no codigo, schema e testes:
- 4 migrations aplicadas
- 1 Mailable + 1 view Blade
- 1 comando Artisan + schedule diario
- 4 controllers refatorados (NpsController, CompanyController, ComercialController, DashboardController, PerformanceController)
- 5 paginas JSX (Nps/Respond, Nps/Index, Companies/Index, Comercial/Empresas, Dashboard/Admin, Companies/Show)
- 19/19 testes verdes
- Zero rastro de colunas legacy em codigo de producao
- Geracao manual preservada (REQ-31-08)
- Build npm valido com assets gerados

O status `human_needed` reflete apenas necessidade de smoke UAT em ambiente real (SMTP Gmail vivo, dados de prod, renderizacao visual), nao gaps tecnicos.

**Deploy agrupado obrigatorio:** Plans 31-01..31-05 devem subir juntos. Plan 31-01 isolado em prod quebraria `/dashboard`, `/performance`, `/companies/{id}` ate o Plan 31-05 fechar o cleanup das colunas legacy (ja confirmado: cleanup completo).

**Pos-deploy:**
1. `php artisan migrate --force` no VPS
2. `php artisan cache:clear && config:cache && route:cache && view:cache`
3. Preencher `email_cliente` proativamente nas empresas ativas (cerca de 170) — sem isso o primeiro disparo automatico tem volume baixo
4. Confirmar via `schedule:list` que `nps-disparar-mensal` esta agendado para 09:00 BRT
5. Primeira semana, monitorar `storage/logs/laravel.log` por mensagens `[NPS Mensal]`

---

*Verificado: 2026-06-10T23:55:00Z*
*Verificador: Claude Code (gsd-verifier, Opus 4.7)*
