---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 11
subsystem: api
tags: [laravel, inertia, react, csrf-exception, file-upload, activity-log]

# Dependency graph
requires:
  - phase: 135-05
    provides: "Observer de ContratoServico + transição rascunho→andamento (D-05) — precondição para um onboarding chegar a `andamento` e o link do cliente passar a mostrar algo"
  - phase: 135-09
    provides: "OnboardingController (painel operacional) e a ordem de rotas `/onboarding/templates` antes de `/onboarding/{onboarding}` — o Plano 11 reusa o mesmo controller para hospedar `gerarLink()`"
provides:
  - "OnboardingLinkService: paraEmpresa() (token único por empresa, firstOrCreate, D-06), passosDoCliente() agregado por `chave` (D-10), marcarFeitoPorChave() com guarda de auto_fonte (D-19)"
  - "OnboardingPublicoController: workspace()/marcarFeito()/anexarFicha() — 3 rotas públicas sob `onboarding-cliente/*`, isentas de CSRF, sem nenhum dado operacional interno no payload"
  - "OnboardingController::gerarLink() — rota interna POST /onboarding/empresas/{company}/link, atrás do gate permission:core.onboarding"
  - "Onboarding/Publico.jsx — Tela 3: portal do cliente, componente real sem AppLayout"
affects: ["135-12"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Ação sem usuário autenticado recebe $ip em vez de User — marcarFeitoPorChave(Company, string, ?string $ip) é a contraparte pública de OnboardingEngineService::concluirManualmente(OnboardingPasso, User), mesma regra D-19, dono diferente de quem age"
    - "Agregação por chave como groupBy('chave') explícito + prioridade de status (aberto > indeterminado > aguardando_coleta > bloqueado, concluido só quando TODOS concluídos) — escrito para o dia em que dois serviços colidirem numa chave, não simulado pela v1 ter um template só"
    - "Upload público: disco SEMPRE 'local' (privado), nome gerado por Str::uuid() — nunca o nome original no filesystem; validação `mimes` fecha a porta pra executável disfarçado de documento"
    - "Controller cria classe-esqueleto antes da lógica quando a rota precisa dela (mesmo padrão do 135-09) — aplicado de novo aqui: OnboardingPublicoController nasceu com 3 métodos vazios na Task 1 para as rotas terem onde despachar antes da Task 2 escrever o corpo"

key-files:
  created:
    - app/Services/Onboarding/OnboardingLinkService.php
    - app/Http/Controllers/OnboardingPublicoController.php
    - resources/js/Pages/Onboarding/Publico.jsx
    - tests/Feature/Phase135/OnboardingPortalPublicoTest.php
  modified:
    - app/Http/Controllers/OnboardingController.php
    - routes/web.php
    - bootstrap/app.php

key-decisions:
  - "gerarLink() vive em OnboardingController, não em OnboardingPublicoController (deviation Rule 3) — o bloco <interfaces> do plano não amarrava a rota interna a um controller específico e o frontmatter files_modified não listava OnboardingController.php, mas a rota POST /onboarding/empresas/{company}/link precisa de uma classe existente; gerarLink() é ação INTERNA (atrás de permission:core.onboarding), então pertence ao controller do painel, não ao controller público (cujo nome e propósito são só as 3 rotas sem auth)"
  - "Estado 'Link inválido' do Publico.jsx é defensivo, não alcançável pelo fluxo real: workspace() usa firstOrFail() e devolve 404 ANTES de renderizar Inertia (mesma mecânica do precedente Polos). O ramo existe no componente porque a Task 3/UI-SPEC pedem a copy literal e o acceptance criteria verifica a string por grep no arquivo — não porque o backend chegue a renderizá-lo hoje"
  - "Botão 'Autorizar acesso' (passo grant_sistema_ecf) NÃO dispara OAuth de verdade nesta fase — documentado como Known Stub abaixo, ver seção própria"
  - "Bloco de upload da ficha só aparece quando há >=1 passo dono=cliente ativo OU ficha já enviada — evita prometer um upload que o backend recusaria com 422 (nenhum onboarding_passo de chave ficha_cliente_recebida existe se a empresa só tem onboarding em rascunho), no mesmo espírito de SC-04 (rascunho não expõe operação em curso)"
  - "Checkbox (Components/ui/checkbox) só é importado/renderizado no ramo manual (!tem_auto_fonte) do card — passo automático usa botão 'Autorizar acesso', nunca checkbox, tornando D-19 visível no código e não só na regra de negócio do backend"

patterns-established:
  - "Prefixo público novo (onboarding-cliente/*) isento de CSRF por entrada NOVA e distinta em bootstrap/app.php — nunca reusar/alargar 'implementacao/*' (D-02); diff comprovadamente só-adição"
  - "activity('onboarding')->performedOn($link) com IP em toda ação pública sem auth (marcarFeito/anexarFicha) — rastro mesmo sem usuário autenticado, mesmo padrão de auditoria do restante do módulo"

requirements-completed: [SC-04, SC-10, D-06, D-10, D-16, D-19]

# Metrics
duration: ~50min (estimado — sessão não cronometrada desde o dispatch)
completed: 2026-08-12
---

# Fase 135 Plano 11: Link público por empresa + Tela 3 (portal do cliente) Summary

**Um link único por empresa (não por onboarding) abre um portal React sem sidebar que agrega, por `chave` de template, todos os passos `dono=cliente` dos onboardings ativos daquela empresa — distinguindo no backend e na UI o passo que o cliente marca manualmente do passo que só o resolver automático fecha (D-19), com a ficha cadastral tratada como anexo cuja confirmação continua exclusiva do usuário interno (D-16).**

## Performance

- **Duration:** ~50 min (estimado)
- **Completed:** 2026-08-12T18:11:12Z
- **Tasks:** 3
- **Files modified:** 7 (4 criados, 3 modificados) + `.planning/ROADMAP.md` (tracking da fase)

## Accomplishments

- `OnboardingLinkService::paraEmpresa()` — `firstOrCreate` por `company_id`, token de 48 caracteres, nunca mais de um por empresa (D-06).
- `OnboardingLinkService::passosDoCliente()` — agrega passos `dono=cliente` de onboardings **em andamento** (rascunho nunca aparece, SC-04) por `chave` de verdade (`groupBy('chave')` explícito, testado com dois templates sintéticos colidindo na mesma chave), com prioridade de status que favorece o mais acionável para o cliente.
- `OnboardingLinkService::marcarFeitoPorChave()` — conclui todos os passos daquela chave nos onboardings ativos, recusa com `\DomainException` quando o `TemplatePasso` tem `auto_fonte` (D-19), chama `OnboardingEngineService::reavaliar()` uma vez por onboarding tocado (nunca por passo).
- `OnboardingController::gerarLink()` — rota interna que gera/devolve o token da empresa, com guarda de escopo por carteira (admin vê qualquer empresa; não-admin só a própria).
- `OnboardingPublicoController` — `workspace()` (payload sem `responsavel`/`sla_dias`/`dias_parado`, provado por teste), `marcarFeito()` (422 pt-BR em passo automático) e `anexarFicha()` (disco `local` privado, nome `Str::uuid()`, `mimes` fecha `.exe`/`.php`, **não** conclui o passo — D-16).
- `resources/js/Pages/Onboarding/Publico.jsx` — componente real (sem `AppLayout`), 4 estados com copy literal (link inválido, nada pendente, tudo concluído, conectando), card por chave com `Checkbox` só no ramo manual e botão "Autorizar acesso" (sem checkbox) no ramo automático.
- Prefixo `onboarding-cliente/*` isento de CSRF via entrada nova em `bootstrap/app.php` — diff comprovadamente só-adição, `implementacao/*` (Polos) intocado.
- 23 testes novos em `OnboardingPortalPublicoTest` (7 + 8 + verificação estrutural do front via build/manifest/grep) — suíte `Phase135` completa foi de 147 para 162 testes, todos verdes.

## Task Commits

1. **Task 1: OnboardingLinkService, geração do link e o prefixo público isento de CSRF** - `4f76ec40` (feat)
2. **Task 2: OnboardingPublicoController — workspace, marcar feito e anexo da ficha** - `c25f966f` (feat)
3. **Task 3: Tela 3 — portal do cliente (React)** - `7f9d1511` (feat)

**Plan metadata:** (commit de documentação a seguir, via este SUMMARY)

## Files Created/Modified

- `app/Services/Onboarding/OnboardingLinkService.php` - `paraEmpresa()`/`passosDoCliente()`/`marcarFeitoPorChave()`, injeta `OnboardingEngineService` para `reavaliar()`
- `app/Http/Controllers/OnboardingPublicoController.php` - `workspace()`/`marcarFeito()`/`anexarFicha()`, sem middleware `auth`
- `app/Http/Controllers/OnboardingController.php` - acrescenta `gerarLink()` (deviation Rule 3, ver key-decisions)
- `routes/web.php` - 3 rotas públicas (`onboarding-cliente/*`, com `throttle:20,1` nas de escrita) + 1 rota interna (`onboarding.link.gerar`)
- `bootstrap/app.php` - `onboarding-cliente/*` no array `except` de `validateCsrfTokens` (diff só-adição)
- `resources/js/Pages/Onboarding/Publico.jsx` - Tela 3, componente real
- `tests/Feature/Phase135/OnboardingPortalPublicoTest.php` - 15 testes PHP cobrindo link, agregação, conclusão manual, D-19 e upload

## Verificação de roteamento (routing_constraint)

`C:/xampp/php/php.exe artisan route:list --path=onboarding` (saída real, cores removidas):

```
GET|HEAD   onboarding ......................................... onboarding.painel.index › OnboardingController@index
GET|HEAD   onboarding-cliente/{token} ......... onboarding.publico.workspace › OnboardingPublicoController@workspace
POST       onboarding-cliente/{token}/ficha ..... onboarding.publico.ficha › OnboardingPublicoController@anexarFicha
PATCH      onboarding-cliente/{token}/passo ..... onboarding.publico.passo › OnboardingPublicoController@marcarFeito
POST       onboarding/empresas/{company}/link ............... onboarding.link.gerar › OnboardingController@gerarLink
POST       onboarding/passos/{passo}/concluir ...... onboarding.passos.concluir › OnboardingController@concluirPasso
GET|HEAD   onboarding/templates .................... onboarding.templates.index › OnboardingTemplateController@index
POST       onboarding/templates .................... onboarding.templates.store › OnboardingTemplateController@store
POST       onboarding/templates/{template}/migrar onboarding.templates.migrar › OnboardingTemplateController@migrar
GET|HEAD   onboarding/{onboarding} .............................. onboarding.painel.show › OnboardingController@show
POST       onboarding/{onboarding}/responsavel onboarding.responsavel.confirmar › OnboardingController@confirmarRes…

Showing [17] routes
```

`/onboarding/templates` (Plano 08, literal) continua registrada antes de `/onboarding/{onboarding}` (Plano 09, parâmetro) — nenhuma das novas rotas desta 135-11 tem o mesmo número de segmentos que colidiria: `onboarding/empresas/{company}/link` (3 segmentos) e `onboarding-cliente/*` (prefixo distinto) não disputam posição com nenhuma rota existente.

`GET|HEAD implementacao/{token}` e as demais 20 rotas de `mlb/implementacao*` seguem **idênticas** ao estado anterior à fase (D-02) — nenhum arquivo de Polos foi tocado (`git status --porcelain | grep -i polos` vazio em toda a execução).

## Verificação de CSRF (security_note)

**Antes desta plan** (`bootstrap/app.php`, array `except` de `validateCsrfTokens`):
```php
'implementacao/*',
'api/webhooks/*',
```

**Depois desta plan:**
```php
'implementacao/*',
'api/webhooks/*',
'onboarding-cliente/*', // Fase 135 Plano 11 — portal público do cliente por empresa (D-06); prefixo NOVO e distinto do prefixo do Polos (D-02)
```

`git diff -U0 <hash-antes-da-fase> -- bootstrap/app.php` mostra **1 linha adicionada, 0 removidas** — as duas entradas pré-existentes (`implementacao/*`, `api/webhooks/*`) permanecem intactas byte-a-byte. `grep -c "onboarding-cliente/\*"` = 1, `grep -c "'implementacao/\*'"` = 1 (sem duplicação por comentário — a primeira tentativa citava `'implementacao/*'` entre aspas dentro do comentário e inflava esse grep para 2; corrigido reescrevendo o comentário sem repetir a string entre aspas simples).

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: `gerarLink()` foi acrescentado a `OnboardingController` (não a `OnboardingPublicoController`) porque é ação interna atrás do gate `permission:core.onboarding` — o plano não deixava explícito o controller-alvo e o `files_modified` do frontmatter não listava esse arquivo, então isso foi tratado como Rule 3 (blocking issue), mesmo padrão de antecipação de classe já usado no 135-09. O botão "Autorizar acesso" é intencionalmente um stub visual nesta fase (ver "Known Stubs").

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking issue] Rota interna `onboarding.link.gerar` sem controller declarado no `files_modified`/Task 1**
- **Found during:** Task 1 (registrar as 4 rotas do bloco `<interfaces>`)
- **Issue:** O bloco `<interfaces>` lista `POST /onboarding/empresas/{company}/link → gerarLink (onboarding.link.gerar)` junto das 3 rotas públicas, mas não amarra explicitamente a um controller; o `files_modified` do frontmatter do plano lista só `OnboardingLinkService.php`, `OnboardingPublicoController.php`, `routes/web.php`, `bootstrap/app.php` e o teste — sem `OnboardingController.php`. Sem decidir onde `gerarLink()` mora, a rota não teria classe para despachar.
- **Fix:** `gerarLink()` foi acrescentado a `OnboardingController` (painel operacional, Plano 09) — é ação interna, atrás do mesmo gate `permission:core.onboarding` do resto do painel, e semanticamente pertence a quem já concentra as ações da Coordenação (`confirmarResponsavel`, `concluirPasso`). `OnboardingPublicoController` ficou reservado só para as 3 rotas sem `auth`, coerente com seu nome e com o artifact do plano (`contains: "firstOrFail"`, que `gerarLink()` não usa).
- **Files modified:** `app/Http/Controllers/OnboardingController.php`
- **Verification:** `post_link_cria_um_onboarding_link_com_token_de_48_caracteres` e `chamar_gerar_link_duas_vezes_mantem_um_unico_token` (Task 1) passam contra essa implementação.
- **Committed in:** `4f76ec40`

---

**Total deviations:** 1 auto-fixed (Rule 3 — mesma classe de gap do 135-09, resolvida do mesmo jeito).
**Impact on plan:** Nenhum no conteúdo funcional — só a decisão de EM QUAL arquivo o método nasce, documentada aqui para o Plano 12 (que constrói o botão "Gerar link" na Tela 1) saber onde encontrá-lo.

## Known Stubs

**1. Botão "Autorizar acesso" (passo `grant_sistema_ecf`, `resources/js/Pages/Onboarding/Publico.jsx`, `PassoCard.autorizarAcesso()`)**
- **O que existe:** o card renderiza o botão com a copy exata, e o clique mostra o estado transitório "Conectando…" (spinner) por ~2,5s antes de voltar ao estado normal.
- **O que NÃO existe:** nenhuma chamada real ao fluxo OAuth do Mercado Livre é disparada. A rota existente (`ml.oauth.initiate`, `routes/web.php:635`) exige sessão autenticada (`auth` middleware) — o portal público, por definição, não tem uma. Criar um endpoint de iniciar OAuth seguro para um visitante anônimo por posse de token é uma superfície nova (mais uma rota pública, mais uma decisão de segurança) que não estava nos `files_modified` nem nas `<interfaces>` desta plan.
- **Por que não foi resolvido agora:** o passo `grant_sistema_ecf` continua fechando sozinho por `ml_tokens.status = active` (D-19) — o motor de automação já funciona; falta só o atalho de UX de iniciar o OAuth a partir do portal em vez de outro canal. É trabalho de fase futura, não deste plano de "link + portal".
- **Quem resolve:** fase futura teria que desenhar e registrar um endpoint público de iniciar OAuth escopado por token/empresa — decisão de arquitetura (Rule 4), não algo a decidir por conta própria dentro desta execução.

## Issues Encountered

Um ajuste de conteúdo (não de lógica) no comentário do `bootstrap/app.php`: a primeira versão citava `'implementacao/*'` entre aspas simples dentro do próprio comentário da linha nova, o que inflava `grep -c "'implementacao/\*'"` para 2 e violava o critério de aceite da Task 1 ("retorna 1"). Reescrito sem repetir a string entre aspas — sem impacto funcional, só uma correção de texto antes do commit.

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- O Plano 12 (Tela 1 — painel operacional React + item de menu) pode adicionar um botão "Gerar link" que chama `route('onboarding.link.gerar', company.id)` — o backend já responde com o token pronto via flash `success`.
- `Onboarding/Publico.jsx` está pronto para ser exercitado manualmente: criar uma empresa com onboarding de Gestão em `andamento` (via `OnboardingTemplateGestaoSeeder` + `criarParaContrato` + `confirmarResponsavel`), gerar o link, e visitar `/onboarding-cliente/{token}` **pelo vhost Apache** (não `artisan serve` — CORS mata o bundle em página pública, armadilha já documentada nas notas de ambiente).
- O botão "Autorizar acesso" precisa de decisão de arquitetura antes de virar funcional (ver "Known Stubs") — registrar como candidato a fase futura se o produto quiser essa UX.
- `.planning/STATE.md` não foi tocado por esta execução (owned pelo orquestrador/outra sessão em paralelo na Fase 136) — atualização central fica para o fim da fase, conforme instruído.
- `git status --porcelain | grep -i polos` permaneceu vazio em toda a execução (D-02); `route:list --path=implementacao` idêntico ao estado anterior à fase.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: `app/Services/Onboarding/OnboardingLinkService.php`
- FOUND: `app/Http/Controllers/OnboardingPublicoController.php`
- FOUND: `resources/js/Pages/Onboarding/Publico.jsx`
- FOUND: `tests/Feature/Phase135/OnboardingPortalPublicoTest.php`
- FOUND: `app/Http/Controllers/OnboardingController.php`
- FOUND: `routes/web.php`
- FOUND: `bootstrap/app.php`
- FOUND: commit `4f76ec40`
- FOUND: commit `c25f966f`
- FOUND: commit `7f9d1511`
