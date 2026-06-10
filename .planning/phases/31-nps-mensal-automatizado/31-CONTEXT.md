# Phase 31: NPS Mensal Automatizado — Context

**Gathered:** 2026-06-10
**Status:** Ready for planning
**Source:** Conversational discuss (4 perguntas críticas + investigação inline do estado atual via VPS)

<domain>
## Phase Boundary

### O que esta fase entrega

Cliente da ECF recebe email NPS automaticamente todo mês no **dia do mês em que a empresa
foi cadastrada** (`DAY(companies.created_at)`), responde **3 notas em escala 1-5** (Estrategista,
Analista quando houver, Empresa "está atendendo sua expectativa?") + **comentário livre** sem
depender de analista/estrategista gerar o link manualmente.

Admin acessa `/nps` e vê:
- Filtro por mês
- 3 cards de média (Estrategista / Analista / Empresa) — média daquele mês
- Gráfico de linha 12 meses com 3 séries — variação histórica
- Lista paginada de respostas do mês com nome, empresa, 3 notas e comentário

A capacidade de **gerar link manualmente** (fluxo atual) é PRESERVADA — operadores podem
continuar criando surveys ad-hoc via `nps.generate`. Estes surveys ficam marcados
`auto_generated=false` para distinguir do fluxo mensal.

### Estado atual investigado (2026-06-10)

- `companies` tem `id, parent_company_id, name, cnpj, adman_account_id, marketplace,
  cust_id_status, adman_store_id, ml_store_id, ml_link_generated_at, ml_link_url, segment,
  active, status, notes, valor_fixo, created_at, updated_at`. **NÃO tem coluna email**.
- `nps_surveys`: `id, token (UUID), company_id, generated_by, expires_at, completed_at,
  status (pending|completed|expired), timestamps`. `expires_at` hoje = 7 dias.
- `nps_responses`: `id, survey_id, respondent_name, score_consultant (0-10), score_mentor
  (0-10), score_overall (0-10), comment, timestamps`.
- `NpsController` tem `index` (admin/profissional), `generate` (manual), `respond` (público),
  `submitResponse`. Já lê estrategista via `wherePivot('role','estrategista')` e consultor
  via `wherePivot('role','consultor')` — alinhado com taxonomia atual (company_users).
- Existem páginas `Nps/Index.jsx`, `Nps/Respond.jsx`, `Nps/AlreadyCompleted.jsx`,
  `Nps/Expired.jsx`, `Nps/ThankYou.jsx`.
- **Não há Mailable NPS** (`app/Mail/` não tem `*nps*`).
- **Não há schedule NPS** em `routes/console.php`.
- SMTP Gmail já está validado em produção (Phase 28 enviou Relatório Mensal Executivo PDF
  por email).
- Sidebar tem item "NPS" com permission `core.nps`.
- Dashboard widget NPS (distribution `promotores/neutros/detratores`) consome `score_overall`
  — vai precisar ser ajustado quando a escala mudar (ver Decisão D-09).

</domain>

<decisions>
## Implementation Decisions

### Frequência e disparo (D-01, D-02, D-03)

**D-01 — Aniversário do cadastro (LOCKED).**
Email dispara no **`DAY(companies.created_at) == DAY(today)`** todo mês. Ex: empresa
cadastrada 18/06/2026 recebe nos dias 18 de cada mês subsequente. Sem janelas configuráveis
adicionais.

**D-02 — Cadência diária (LOCKED).**
Comando `nps:disparar-mensal` roda **1x/dia** via `routes/console.php` (idiomático Laravel 11+).
Horário sugerido: cedo da manhã (ex: 09:00 BRT) — não conflita com outros jobs (sync Adman
11h, refresh cache gross billing 12h30, ECF Drive sync, etc).

**D-03 — Edge cases de dia do mês (LOCKED).**
- Empresa cadastrada dia 31 → no mês que não tem dia 31, dispara no **último dia do mês**.
- Empresa cadastrada dia 30 → fevereiro dispara no dia 28 (ou 29 em bissexto).
- Implementação: `Carbon::create($year, $month, min($day, $daysInMonth))`.

### Email (D-04, D-05)

**D-04 — Coluna `companies.email_cliente` (LOCKED).**
Migration adiciona `email_cliente` (varchar 255 nullable) em `companies`. Preenchível via
UI de edição de empresa (sites: `Companies/Show.jsx` e `Comercial/Empresas.jsx` — a serem
auditados pelo planner). Empresas sem `email_cliente` são **silenciosamente puladas** pelo
comando (não erra, não loga warning — é estado esperado de empresas que ainda não tiveram o
campo preenchido).

**D-05 — `NpsMonthlyMail` Markdown via SMTP Gmail (LOCKED).**
Mailable novo `NpsMonthlyMail` (Markdown template) usando o SMTP Gmail já validado em produção
desde Phase 28. Conteúdo do email:
- Saudação personalizada ("Olá!").
- Texto explicando que é a pesquisa mensal de satisfação ECF.
- Nome do **Estrategista** designado.
- Nome do **Analista** designado (omitir se não houver — caso mentoria).
- CTA destacado pro link público `/nps/{token}`.
- Mês de referência ("Pesquisa de **junho/2026**").
- Footer com link de descadastro? **DEFERIDO** (D-13).

### Escala e perguntas (D-06, D-07, D-08, D-09)

**D-06 — Escala 1-5 (LOCKED, substitui 0-10).**
Todas as notas passam pra escala 1-5. Validação backend: `integer|min:1|max:5`. Frontend usa
sliders ou botões grandes (1 a 5) — não dropdown.

**D-07 — 3 dimensões de nota (LOCKED).**
- `score_estrategista` (1-5, **required**) — "Como você avalia o trabalho do estrategista [Nome]?"
- `score_analista` (1-5, **nullable**) — só aparece e é exigido se a empresa tem analista
  atribuído. Mentoria pura (só estrategista) omite o campo.
- `score_empresa` (1-5, **required**) — "A ECF está atendendo suas expectativas?"

**D-08 — Comentário livre (LOCKED).**
1 textarea com placeholder "Opiniões, sugestões ou outra coisa que queira compartilhar"
(max 2000 chars, nullable).

**D-09 — Quebra do widget NPS no Dashboard (LOCKED — aceite).**
O widget atual `nps_distribution` (promotores/neutros/detratores baseado em `score_overall`
0-10) vai parar de funcionar quando `nps_responses` for recriada. Como o histórico atual é
descartado (D-11), o widget volta a popular conforme novas respostas chegarem na nova escala.
**Substituir lógica do widget**: usar `score_empresa` (1-5) com mapeamento promotores=5,
neutros=4, detratores=1-3 — ou simplesmente remover o widget se ele não fizer mais sentido
(planner decide).

### Histórico e migração (D-10, D-11)

**D-10 — Migration drop+recreate `nps_responses` (LOCKED).**
- Drop tabela `nps_responses` existente (truncate + drop).
- Recreate com novo schema: `survey_id, respondent_name (nullable), score_estrategista,
  score_analista (nullable), score_empresa, comment, timestamps`.

**D-11 — Truncate `nps_surveys` (LOCKED).**
Apagar todos os surveys existentes (são testes). Reset auto_increment opcional.

### Schema de surveys (D-12)

**D-12 — Adicionar `month_reference` + `auto_generated` em `nps_surveys` (LOCKED).**
- `month_reference` (date YYYY-MM-01, nullable) — apenas surveys auto_generated têm valor;
  manuais ficam null.
- `auto_generated` (boolean, default false).
- `expires_at` permanece, mas para surveys mensais expira em **30 dias** (próximo disparo)
  em vez dos 7 atuais. Manual continua 7 dias (ou conforme `generate()`).

### Pontos abertos / deferidos (D-13, D-14, D-15)

**D-13 — Descadastro / opt-out via email — DEFERIDO.**
Não há tabela de unsubscribe nem footer link. Empresas que não quiserem mais receber: admin
zera o `email_cliente`. Suficiente pro MVP.

**D-14 — Reminder se não responder — DEFERIDO.**
Não há reminder. Survey expira em 30 dias, próximo mês entra na fila novamente.

**D-15 — Email pra múltiplos respondentes — DEFERIDO.**
1 email por empresa (campo único). Múltiplos contatos por empresa fica pra fase futura via
tabela `company_nps_contacts`.

### Claude's Discretion

- Layout exato dos sliders 1-5 (botões redondos com cor verde→amarelo→vermelho, sliders
  HTML, ou ECFRating component — planner escolhe).
- Estética do email Markdown (planner segue padrão do email de Relatório Mensal Phase 28).
- Estrutura interna da admin section em `/nps` — abas vs cards vs filtros expansíveis.
- Bibliotecas de chart (já é Recharts no resto do projeto — usar).
- Tratamento de empresas sem email_cliente em massa após migração: planner decide se faz
  comando one-shot pra importar ou se preenche on-demand via UI.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Backend de email/notificação (referência arquitetural)
- `app/Mail/RelatorioMensalMail.php` (Phase 28) — padrão Mailable+Markdown+SMTP Gmail já validado
- `app/Console/Commands/EnviarRelatorioMensal.php` (Phase 28) — padrão comando schedule
- `routes/console.php` — onde adicionar o schedule
- `app/Notifications/AlertaEcfNotification.php` (Phase 29) — referência se quisermos notificar
  admins sobre respostas recebidas (não obrigatório nesta fase)

### NPS atual (a ser modificado)
- `app/Http/Controllers/NpsController.php` — controller alvo
- `app/Models/NpsSurvey.php` — model survey
- `app/Models/NpsResponse.php` — model response (será reescrito)
- `database/migrations/2026_04_26_152218_create_nps_surveys_table.php` — schema original
- `database/migrations/2026_04_26_152219_create_nps_responses_table.php` — schema original
- `resources/js/Pages/Nps/Index.jsx` — admin/profissional list
- `resources/js/Pages/Nps/Respond.jsx` — form público alvo da reescrita
- `resources/js/Pages/Nps/ThankYou.jsx` / `AlreadyCompleted.jsx` / `Expired.jsx` — estados terminais
- `resources/views/emails/` — diretório onde o template Markdown vai (se ainda não existir, criar)

### Companies (coluna email_cliente)
- `app/Models/Company.php` — model alvo
- `resources/js/Pages/Companies/Show.jsx` — UI edição admin
- `resources/js/Pages/Comercial/Empresas.jsx` — UI edição comercial
- `app/Http/Controllers/CompanyController.php` — endpoint update

### Dashboard widget NPS (a ser ajustado ou removido)
- `app/Http/Controllers/DashboardController.php` (linhas ~400-404) — `nps_distribution` calculation
- `resources/js/Pages/Dashboard/Admin.jsx` (linhas ~340-380 aprox) — render do widget

### Permissions / sidebar
- `app/Support/Permissions.php` — `core.nps` permission
- `resources/js/Layouts/AppLayout.jsx` — item NPS no sidebar

### company_users (pra resolver estrategista/analista do email)
- `app/Models/Company.php` métodos `consultor()` (analista) e `estrategista()` — pivot
  `company_users.role`. Comando vai usar isso pra montar payload do email.

</canonical_refs>

<specifics>
## Specific Ideas

- **Padrão de slugs no agendador**: usar mesma cadência Laravel 11+ (closure em `console.php`)
  do `php artisan schedule:run` — não criar arquivo Kernel.php.
- **Token UUID continua**: padrão atual via `Str::uuid()->toString()` está bom. Só mudar
  expires_at para 30 dias quando `auto_generated=true`.
- **Naming consistency**: NÃO renomear coluna `score_consultant` no banco — drop+recreate
  trocar para `score_analista` (semântica nova). Idem `score_mentor` → `score_estrategista`.
  `score_overall` desaparece, substituído por `score_empresa`.
- **Idempotência do comando**: antes de criar survey, fazer `where('company_id', $c->id)
  ->where('month_reference', $mesAtual)->exists()` — evita duplicata se comando rodar 2x no
  mesmo dia (ex: reboot worker).
- **Audiência admin**: filtrar respostas por mês via `nps_responses.created_at` (mês civil)
  ou via `nps_surveys.month_reference`. **Usar `month_reference`** porque é semanticamente
  o "mês de referência da survey" — independente de quando o cliente respondeu.
- **Cliente respondeu fora do mês de referência**: ex: survey de junho mas cliente responde
  em 5 de julho. A resposta conta pro **mês de junho** no admin (alinhado a `month_reference`).

</specifics>

<deferred>
## Deferred Ideas

- Unsubscribe / opt-out via email com link (D-13)
- Reminder automático se não responder (D-14)
- Múltiplos emails de respondente por empresa (D-15)
- Notificação no sino do admin quando uma resposta nova chega
- Trends/insights agregados por estrategista (ex: "Nathalia teve queda de 0,5 pontos no mês")
  — futuro
- Export CSV das respostas
- Filtro de busca por empresa/profissional na lista admin

</deferred>

---

*Phase: 31-nps-mensal-automatizado*
*Context gathered: 2026-06-10 via conversational discuss + VPS inspection*
