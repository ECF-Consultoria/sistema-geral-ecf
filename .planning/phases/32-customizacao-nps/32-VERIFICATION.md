---
phase: 32-customizacao-nps
verified: 2026-06-12T00:00:00Z
status: human_needed
score: 15/15 must-haves verificadas (cobertura técnica completa); 4 UATs visuais aguardam validação humana
overrides_applied: 0
human_verification:
  - test: "Smoke real em produção — disparar `nps:disparar-mensal --dry-run` no VPS após deploy e confirmar que o cron 09:00 BRT executa o envio real para empresas com aniversário hoje"
    expected: "1 NpsEmailEnvio por empresa elegível com status=enviado; cliente recebe email com logo + textos atuais da config"
    why_human: "Exige SMTP real + monitoramento do supervisor + entrega na caixa de entrada — não verificável programaticamente em dev"
  - test: "Editar texto via /nps/configuracao e clicar 'Atualizar preview' — verificar que iframe srcdoc reflete a mudança imediatamente"
    expected: "Preview re-renderiza com texto novo; logo continua aparecendo; nenhuma falha de CSP/iframe"
    why_human: "UX interativa de form + iframe; comportamento de re-render só observável visualmente no browser real"
  - test: "Render visual do email no cliente (Gmail/Outlook) — abrir email recebido e conferir alinhamento do logo gradient, leitura do corpo, botão CTA clicável"
    expected: "Logo aparece como bloco horizontal: 'ECF' + barra colorida vertical + 'CONSULTORIA & ASSESSORIA' em uppercase; sem quebras visuais"
    why_human: "Clientes de email renderizam CSS inline de formas diferentes (Gmail desktop vs mobile, Outlook 2019 etc) — só conferindo no inbox real"
  - test: "Render visual do logo na página /nps/{token} — acessar como cliente e ver header"
    expected: "LogoEcf renderizado centralizado com Montserrat 700 e gradient vertical exato; fundo dark ecf-bg combina com card de pesquisa"
    why_human: "Carregamento Google Fonts + fallback em browsers que bloqueiam external CSS — verificação visual no browser"
---

# Phase 32: Customização NPS — Relatório de Verificação

**Phase Goal:** Função NPS Mensal Automatizado ganha identidade visual ECF (logo no email + página /nps/{token}) + personalização total dos textos via /nps/configuracao com preview live + rastreabilidade em /nps/emails-enviados. Mantém TUDO da Phase 31 funcionando.
**Verified:** 2026-06-12
**Status:** human_needed (PASS técnico — 4 UATs visuais pendentes)
**Re-verification:** No (verificação inicial)

## Cobertura das Decisões Locked

| Decisão | Descrição | Status | Evidência |
|---------|-----------|--------|-----------|
| **D-01** | Logo HTML/CSS exato (gradient azul→rosa→laranja→amarelo) | ENTREGUE | `resources/views/partials/logo-ecf.blade.php:26` usa `linear-gradient(0deg,#1e5ef3 0%,#e84393 40%,#f97316 70%,#facc15 100%)` (exato sem espaços); `resources/js/Components/LogoEcf.jsx:49` usa o mesmo gradient (com espaços após vírgulas — equivalente em CSS) |
| **D-02** | Admin-only para `/nps/configuracao` + `/nps/emails-enviados` | ENTREGUE | `route:list -v` confirma `EnsureUserHasRole:admin` em `nps.configuracao.index`, `nps.configuracao.update`, `nps.configuracao.preview` e `nps.emails-enviados.index` |
| **D-03** | 1 chave JSON `nps_textos` com 11 campos | ENTREGUE | `NpsTextRenderer::defaults()` retorna exatamente 11 chaves; tinker confirmou `count(getTextos()) === 11`; migration `200002_seed_nps_textos_configuracao` populou com defaults D-03 idênticos |
| **D-04** | Tabela `nps_email_envios` | ENTREGUE | Migration `200001_create_nps_email_envios_table` "Ran" no `migrate:status`; `Schema::hasTable('nps_email_envios') === true`; schema bate spec (survey_id nullable fk, company_id fk, destinatario/assunto/status enum/erro_msg nullable/timestamps) |
| **D-05** | Preview server-rendered em iframe | ENTREGUE | `NpsController::previewEmail()` renderiza `view('emails.nps.mensal',...)` com vars de exemplo D-05 e retorna JSON `{html, assunto}`; `Configuracao.jsx:417` usa `<iframe srcdoc=...>` para exibir |
| **D-06** | Filtros mês + busca em /nps/emails-enviados | ENTREGUE | `NpsController::emailsEnviados()` aceita `?mes=YYYY-MM` (12 meses via `meses_disponiveis`) e `?q=` (LIKE company.name OR destinatario); paginação 25/pg com `withQueryString()` |

## Verificações Obrigatórias

### 1. Schema (migrate:status + Schema::hasTable + Configuracao::get)

| Verificação | Resultado | Status |
|-------------|-----------|--------|
| `migrate:status` lista `200001_create_nps_email_envios_table` como `Ran` | OK | VERIFICADO |
| `migrate:status` lista `200002_seed_nps_textos_configuracao` como `Ran` | OK | VERIFICADO |
| `Schema::hasTable('nps_email_envios')` | `1` (true) | VERIFICADO |
| `Configuracao::get('nps_textos')` retorna string JSON | `1` (true) | VERIFICADO |
| `NpsTextRenderer::getTextos()` retorna 11 chaves | `11` (todas D-03) | VERIFICADO |

### 2. Helper NpsTextRenderer

| Método | Status |
|--------|--------|
| `defaults()` retorna 11 chaves D-03 canônicas | VERIFICADO |
| `getTextos()` lê config + merge defensivo com defaults | VERIFICADO |
| `render(string, array)` faz str_replace silencioso | VERIFICADO |
| `renderHtml(string, array)` aplica `e()` + `nl2br` (XSS safe) | VERIFICADO |

### 3. Partial Blade logo-ecf

| Verificação | Status |
|-------------|--------|
| Arquivo `resources/views/partials/logo-ecf.blade.php` existe | VERIFICADO |
| Gradient exato presente (`linear-gradient(0deg,#1e5ef3 0%,#e84393 40%,#f97316 70%,#facc15 100%)`) | VERIFICADO |
| Texto "ECF" Montserrat 700 42px + subtítulo "Consultoria<br>&amp; Assessoria" 12px uppercase | VERIFICADO |

### 4. Componente React LogoEcf.jsx

| Verificação | Status |
|-------------|--------|
| `resources/js/Components/LogoEcf.jsx` existe (69 linhas) | VERIFICADO |
| Gradient equivalente (`linear-gradient(0deg, #1e5ef3 0%, ...)` — mesmas paradas com espaços após vírgulas) | VERIFICADO |
| Importado no Respond.jsx (`import LogoEcf from '@/Components/LogoEcf'`) | VERIFICADO |

### 5. Fonte Montserrat

| Verificação | Status |
|-------------|--------|
| `resources/css/app.css:5` tem `@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap')` | VERIFICADO |

### 6. NpsController métodos

| Método | Linha | Status |
|--------|-------|--------|
| `respond` | 212 (adiciona `survey.textos` no payload com 6 chaves renderizadas) | VERIFICADO |
| `configuracao` | 319 (Inertia::render com textos + defaults + placeholders_doc) | VERIFICADO |
| `atualizarConfiguracao` | 350 (valida 11 strings, salva via Configuracao::set com JSON_UNESCAPED_UNICODE) | VERIFICADO |
| `previewEmail` | 380 (renderiza template Blade real + retorna JSON html/assunto) | VERIFICADO |
| `emailsEnviados` | 449 (paginate 25 + filtros mês/busca + total_mes + meses_disponiveis) | VERIFICADO |

### 7. Routes + middlewares

| Rota | Middleware | Status |
|------|-----------|--------|
| `GET /nps/{token}` → `nps.respond` | web + auth (público) | VERIFICADO |
| `GET /nps/configuracao` → `nps.configuracao.index` | web + auth + verified + **role:admin** | VERIFICADO |
| `PUT /nps/configuracao` → `nps.configuracao.update` | web + auth + verified + **role:admin** | VERIFICADO |
| `POST /nps/configuracao/preview` → `nps.configuracao.preview` | web + auth + verified + **role:admin** | VERIFICADO |
| `GET /nps/emails-enviados` → `nps.emails-enviados.index` | web + auth + verified + **role:admin** | VERIFICADO |

### 8. UI Configuracao.jsx

| Verificação | Resultado | Status |
|-------------|-----------|--------|
| Arquivo existe e tem ≥150 linhas | 466 linhas | VERIFICADO |
| Componente Textarea importado de `@/Components/ui/textarea` | linha 7 | VERIFICADO |
| Tabs Email/Perguntas (`<Tabs defaultValue="email">`) | linha 183-320 | VERIFICADO |
| Iframe srcdoc para preview | linha 417 (`<iframe srcDoc=...>`) | VERIFICADO |
| Botão "Atualizar preview" | linha 392 | VERIFICADO |
| Botão "Restaurar padrão" | linhas 161 + 333 | VERIFICADO |
| Botão Salvar via useForm() PUT | confirmado em todo o form | VERIFICADO |
| 11 textareas (5 email + 6 perguntas) | 5 na tab Email + 6 na tab Perguntas | VERIFICADO |

### 9. UI Respond.jsx

| Verificação | Status |
|-------------|--------|
| Importa LogoEcf (`import LogoEcf from '@/Components/LogoEcf'` — linha 6) | VERIFICADO |
| Renderiza `<LogoEcf theme="dark" />` no header (linha 102) | VERIFICADO |
| 3 perguntas vindas de `survey.textos.perg_estrategista/perg_analista/perg_empresa` (linhas 82-88) | VERIFICADO |
| Labels e placeholders dinâmicos (`perg_comentario_label`, `perg_comentario_placeholder`, `perg_nome_label`) | VERIFICADO |
| `survey.tem_analista === true` ainda controla exibição do bloco analista (linha 145) | VERIFICADO |
| Fallback defensivo client-side (`textos.perg_X \|\| 'hardcoded'`) — survey legacy continua funcionando | VERIFICADO (defensivo) |

### 10. UI EmailsEnviados.jsx

| Verificação | Resultado | Status |
|-------------|-----------|--------|
| Arquivo existe e tem ≥100 linhas | 303 linhas | VERIFICADO |
| Select de mês com 12 opções (`meses_disponiveis.map`) | linha 136 | VERIFICADO |
| Input busca com debounce 300ms (`setTimeout` em useEffect, ref) | linhas 40-54 | VERIFICADO |
| Tabela paginada 25/pg + botões Anterior/Próxima | linhas 282-296 | VERIFICADO |
| Badge status verde "Enviado" / vermelho "Falha" + AlertTriangle/CheckCircle2 | linhas 225-231 | VERIFICADO |
| Link survey "Ver" só quando `!isFalha && envio.survey && envio.survey.token` | linha 182 | VERIFICADO |
| Linha expansível com `erro_msg` quando status=falha | linha 255 | VERIFICADO |

### 11. Sidebar AppLayout.jsx

| Verificação | Status |
|-------------|--------|
| Grupo NPS colapsável com children (Pesquisas + Configuração NPS + Emails enviados) | VERIFICADO (linhas 54-63) |
| Sub-item "Configuração NPS" com `excludeRoles: ['consultor','mentor','publicador','analista','gestor','lider']` (admin-only) | VERIFICADO |
| Sub-item "Emails enviados" com mesma gating excludeRoles | VERIFICADO |
| Ícones lucide importados (`Settings`, `Inbox`) | VERIFICADO |

### 12. Comando NpsDispararMensal

| Verificação | Linha | Status |
|-------------|-------|--------|
| Lê textos via `NpsTextRenderer::getTextos()` antes do loop | 65 | VERIFICADO |
| Monta `$vars` com 5 placeholders (nome_estrategista, nome_analista, nome_empresa, mes_referencia, bloco_analista) | 145-151 | VERIFICADO |
| Renderiza 5 textos do email via `render()` + `renderHtml()` | 156-160 | VERIFICADO |
| Dispara Mailable `NpsMonthlyMail($mailVars)` | 177 | VERIFICADO |
| GRAVA `NpsEmailEnvio::create([status=enviado])` em sucesso | 179-186 | VERIFICADO |
| GRAVA `NpsEmailEnvio::create([status=falha, erro_msg=...])` em catch interno | 192-199 | VERIFICADO |
| Idempotência preservada via `whereDate('month_reference', ...)` | 99-101 | VERIFICADO |

### 13. Email template (emails/nps/mensal.blade.php)

| Verificação | Linha | Status |
|-------------|-------|--------|
| Inclui partial logo (`@include('partials.logo-ecf', ['theme' => 'dark'])`) | 38 | VERIFICADO |
| Renderiza `$saudacaoRender` / `$corpoRender` / `$assinaturaRender` com `{!! !!}` (HTML-safe via renderHtml) | 50/55/78 | VERIFICADO |
| CTA usa `$ctaRender` (texto puro) com link `$linkPesquisa` | 62-65 | VERIFICADO |
| Rodapé com `$mesReferencia` | 89 | VERIFICADO |
| Estrutura table-based para compatibilidade Outlook/Gmail | linhas 30+ | VERIFICADO |

### 14. Testes Phase 31 (regressão zero)

| Comando | Resultado | Status |
|---------|-----------|--------|
| `php artisan test --filter=Phase31` | **19 passed (110 assertions) em 26.36s** | VERIFICADO |

Suítes verdes:
- `Phase31NpsDispararMensalTest` (7 testes)
- `Phase31NpsMonthlyMailTest` (5 testes)
- `Phase31NpsSubmitTest` (7 testes)

### 15. Build npm

| Verificação | Status |
|-------------|--------|
| Assets das 3 páginas Phase 32 presentes em `public/build/assets/` (`Configuracao-*.js`, `EmailsEnviados-*.js`, `Respond-*.js`) | VERIFICADO |

## Gaps de Implementação

**Nenhum gap crítico identificado.** Os 15 itens da checklist obrigatória estão verificados no código. Defaults D-03 conferem 11 chaves (alinhado com o SUMMARY 32-01 que documenta corretamente: o prompt do orquestrador mencionou "11 campos" e os artefatos entregam 11 — consistente).

Observação cosmética (não-bloqueante): o gradient no `LogoEcf.jsx` tem espaços após vírgulas (`linear-gradient(0deg, #1e5ef3 0%, ...)`) enquanto o partial Blade não tem espaços (`linear-gradient(0deg,#1e5ef3 0%,...)`). Tecnicamente equivalente em CSS — ambos produzem o mesmo render visual. Mantido para preservar paridade conceitual.

## Verificações Humanas Necessárias (UATs)

### 1. Smoke real em produção (cron 09:00 BRT)

**Teste:** Após `git pull` + `npm run build` + `php artisan migrate --force` no VPS, aguardar o cron 09:00 BRT ou executar manualmente `php artisan nps:disparar-mensal --dry-run` no servidor.
**Esperado:** Empresas elegíveis aparecem no dry-run; em execução real, 1 `NpsEmailEnvio` por empresa elegível com status=enviado; clientes recebem email com logo + textos.
**Por que humano:** Exige SMTP real + monitoramento do supervisor + verificação da caixa de entrada do cliente.

### 2. Edição de texto + preview live

**Teste:** Logar como admin, acessar `/nps/configuracao`, editar `email_corpo` adicionando texto novo, clicar "Atualizar preview".
**Esperado:** Iframe srcdoc re-renderiza imediatamente com o texto editado; logo continua visível no header do email; sem erros de CSP no console.
**Por que humano:** Comportamento interativo de form + fetch + iframe só observável visualmente; tooltip/UX de "Salvar dirty state" também precisa validação prática.

### 3. Render visual do email no cliente real

**Teste:** Disparar 1 NPS de teste para um email Gmail e outro Outlook; abrir os emails recebidos.
**Esperado:** Logo aparece como bloco horizontal: "ECF" + barra colorida vertical + "CONSULTORIA & ASSESSORIA" uppercase; CTA "Responder pesquisa" clicável; corpo legível.
**Por que humano:** Clientes de email renderizam CSS inline de formas diferentes (Gmail desktop vs mobile, Outlook 2019, Apple Mail) — fonte Montserrat cai em fallback Helvetica e a aparência precisa validação visual.

### 4. Render do logo na /nps/{token}

**Teste:** Acessar a URL de um survey ativo em browser (Chrome + Firefox).
**Esperado:** LogoEcf centralizado com Montserrat 700 + barra gradient vertical + subtítulo uppercase 12px; combinando com fundo dark ecf-bg.
**Por que humano:** Carregamento Google Fonts + fallback de fontes + responsividade do header — verificação visual no browser real.

## Recomendação

**APROVADO PARA DEPLOY** — todos os 15 itens da checklist técnica estão entregues no código com evidência verificável. Os 4 UATs visuais não bloqueiam o deploy técnico; recomenda-se executá-los pós-deploy com o cron real disparando amanhã (12/jun 09:00 BRT) ou via `nps:disparar-mensal` manual em produção como smoke.

**Phase 31 preservada:** 19/19 testes verdes (110 assertions) — zero regressão.

---

*Verificado: 2026-06-12*
*Verificador: Claude (gsd-verifier)*
