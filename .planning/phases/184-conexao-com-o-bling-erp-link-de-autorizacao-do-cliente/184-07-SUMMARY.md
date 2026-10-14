---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 07
subsystem: Bling ERP — link assinado, landing pública e callback com conferência de CNPJ
tags: [bling, oauth, signed-url, landing, cnpj, laravel]

requires:
  - phase: 184-04
    provides: "BlingOAuthService::urlDeAutorizacao()/consumirState()/trocarCode() — ciclo OAuth do Bling"
  - phase: 184-06
    provides: "BlingClient::dadosBasicos() — leitura do CNPJ e id da conta no Bling"
provides:
  - "BlingOAuthService::gerarLinkDeAutorizacao() — método único de geração do link, compartilhado pelo plano 11"
  - "BlingOAuthController::gerarLink()/landing()/callback() — a casca HTTP do caminho de conexão do cliente"
  - "rotas públicas bling.connect.landing (signed) e bling.oauth.callback"
  - "resources/views/oauth/bling-conectar.blade.php — landing com 4 estados"
affects:
  - "184-11 (MlbPublicadorBlingController): vai chamar BlingOAuthService::gerarLinkDeAutorizacao() e registrar a rota autenticada em routes/mlb_anuncios.php (role:admin) que dispara BlingOAuthController::gerarLink()"

tech-stack:
  added: []
  patterns:
    - "Link assinado de 7 dias + landing em blade — molde copiado literal de ShopeeOAuthController (D-01 do CONTEXT)"
    - "Geração do link mora no SERVICE (gerarLinkDeAutorizacao), não no controller — para o plano 11 compartilhar sem controller chamar controller"
    - "Link antigo sobrevivente à assinatura HMAC (não há revogação de assinatura) é tratado por comparação de link_url na landing, não por 403 — vira tela amigável"

key-files:
  created:
    - app/Http/Controllers/BlingOAuthController.php
    - resources/views/oauth/bling-conectar.blade.php
    - tests/Feature/Bling/BlingLinkEAutorizacaoTest.php
  modified:
    - app/Services/Bling/BlingOAuthService.php
    - routes/web.php

decisions:
  - "Regerar o link NUNCA apaga token existente — só atualiza link_url/link_gerado_em quando a conexão já está ativa (T-184-46)"
  - "Link antigo (assinatura ainda válida) não leva 403 ao ser regerado: a landing compara o link_url atual da empresa com a URL da requisição e, se diferente, renderiza a tela de erro amigável — porque o HMAC do Laravel não tem mecanismo de revogação por regeneração"
  - "landing() identifica 'já conectado' pela presença de access_token (lido pelo model, decifrado), não por comparar status via query builder — mantém grep -c STATUS_ATIVA = 1 (só o callback grava esse status) e evita a armadilha de comparar coluna encrypted pela query builder"
  - "Teste do papel exigido (role:admin) usa uma rota ad-hoc registrada dentro do próprio teste, no grupo de middleware 'web' (holder do SubstituteBindings) — sem o grupo 'web', o model binding implícito do {company} não ocorre e o container injeta um Company::class vazio, quebrando a geração da URL"

requirements-completed: [BLING-17, BLING-18, BLING-19, BLING-29]

duration: ~1h40min
completed: 2026-10-14
---

# Phase 184 Plan 07: Link assinado, landing pública e callback com conferência de CNPJ Summary

O caminho inteiro que o cliente percorre para conectar o Bling: a equipe gera um link assinado de 7
dias, o cliente abre uma landing pública guiada (quatro estados, amigável no sucesso e no erro) e
autoriza no Bling dele; no retorno, o CNPJ da conta autorizada é conferido contra o da empresa —
divergindo, a conexão fica `divergente` e **nenhum token é gravado**.

## Performance

- **Duration:** ~1h40min
- **Completed:** 2026-10-14
- **Tasks:** 3/3
- **Files modified:** 5 (3 criados + `BlingOAuthService.php` e `routes/web.php` com acréscimo)

## Accomplishments

- `BlingOAuthService::gerarLinkDeAutorizacao()` — único método de geração do link, chamado pelo
  `BlingOAuthController` (este plano) e, no plano 11, por `MlbPublicadorBlingController`. Nasce
  `aguardando_cliente`; quando a empresa já tem token ativo, regerar só atualiza `link_url`/
  `link_gerado_em` e nunca apaga o token (T-184-46). Empresa sem CNPJ ainda gera link, com aviso em
  `last_error`.
- `BlingOAuthController::gerarLink()` é a casca fina HTTP sobre o service: devolve o link em JSON ou
  422 (nunca 500) quando falta credencial no `.env`.
- `BlingOAuthController::landing()` — rota pública (`signed`), decide entre os quatro estados: `.env`
  vazio → `nao_configurado`; já conectado (token presente) → `concluido`; link requisitado diferente
  do link atual da empresa → `erro` amigável (link substituído, sem 403 — a assinatura HMAC não tem
  revogação por regeneração); senão → `convite` com a URL de autorização do Bling.
- `BlingOAuthController::callback()` — para **antes** de qualquer chamada ao Bling quando o `state`
  já foi consumido (reusar um `code` ainda válido revogaria o cliente); troca o `code`; lê
  `dados-basicos`; só `Cnpj::COMPARACAO_IGUAL` grava `status = ativa` com os tokens. Os outros quatro
  estados (`mesma_empresa_outra_unidade`, `diferente`, `empresa_sem_cnpj`, `contrato_sem_cnpj`) ficam
  `divergente`, com `access_token`/`refresh_token`/`connected_at` **nulos na linha crua** — a lição do
  OAuth do ML nos Polos.
- Toda falha do callback (parâmetros ausentes, troca de code falhando, leitura de dados básicos
  falhando) renderiza a mesma blade no estado `erro`, **sempre status 200** — a landing é a tela que
  o cliente vê, e um erro HTTP cru contradiz a exigência da revisão humana da homologação do Bling.
- `resources/views/oauth/bling-conectar.blade.php` — blade autônoma, mesmo molde visual da
  `shopee-connect` (fundo `#050507`, card `#0f1116`, amarelo `#ffe600` do projeto), quatro estados
  (`convite`, `concluido`, `erro`, `nao_configurado`), sem jargão técnico, sem citar a plataforma de
  venda, sem saída não escapada. `erro` nunca renderiza botão (evita reuso de um `code` morto).
- `routes/web.php` ganhou `bling.connect.landing` (`signed` + `throttle:30,1,bling.landing`) e
  `bling.oauth.callback` (`throttle:30,1,bling.callback`) — prefixos de throttle próprios, para um
  cliente não consumir a cota do outro. Só acréscimo — `routes/mlb_anuncios.php` intocado.

## Task Commits

1. **Task 1: Gerar o link assinado e a rota pública da landing** — `73ba3520` (feat)
2. **Task 2: Callback com conferência de CNPJ** — `3a2dead8` (feat)
3. **Task 3: A blade da landing — quatro estados, amigável no erro** — `b81656cf` (feat)

**Plan metadata:** (este commit — docs)

_Nota: as três tasks têm `tdd="true"` e, como nas plans 04/06 desta fase, teste e código nasceram
juntos. `BlingOAuthController.php` e `tests/Feature/Bling/BlingLinkEAutorizacaoTest.php` são tocados
pelas três tasks (o controller ganha métodos novos em cada uma; o teste ganha casos novos) — o
conteúdo final foi escrito de uma vez para validar o desenho inteiro e depois **retroativamente
separado em três versões** (Task 1 só com `gerarLink`+`landing` e 9 casos; Task 2 acrescentando
`callback` e mais 9 casos; Task 3 acrescentando a blade e os 6 casos finais), preservando um commit
por task. A blade em si (`bling-conectar.blade.php`) ficou **untracked** no working tree durante os
commits das Tasks 1 e 2 — necessária para os testes rodarem (a landing e o callback renderizam a
view), mas só entra no `git add` da Task 3, que é quem a "cria" oficialmente no plano._

## Files Created/Modified

- `app/Http/Controllers/BlingOAuthController.php` (226 linhas) — `gerarLink`, `landing`, `callback`
- `resources/views/oauth/bling-conectar.blade.php` (90 linhas) — os quatro estados da landing
- `tests/Feature/Bling/BlingLinkEAutorizacaoTest.php` (24 testes) — os 23 casos do `<behavior>` do
  plano + 1 teste positivo extra (par da asserção negativa do papel exigido)
- `app/Services/Bling/BlingOAuthService.php` — acrescentado `gerarLinkDeAutorizacao()`
- `routes/web.php` — acrescentadas `bling.connect.landing` e `bling.oauth.callback`

## Decisions Made

Ver frontmatter `decisions`. Dois destaques:

1. **Link antigo não leva 403.** O plano pedia que, ao "Regerar", "a antiga deixe de conectar" e "o
   cliente que usar o link velho caia na tela de erro amigável". Como a assinatura HMAC do Laravel
   não tem mecanismo de revogação por regeneração (duas chamadas a `temporarySignedRoute` geram URLs
   cripograficamente válidas e independentes, cada uma até o próprio prazo), a única forma de tornar
   o link antigo inoperante é uma checagem EXPLÍCITA em `landing()`: comparar o `link_url` atual da
   empresa com a URL da requisição recebida. Diferença → tela de erro amigável (não 403 — 403 viria do
   middleware `signed`, que não tem motivo para rejeitar uma assinatura ainda válida).
2. **Teste do papel exigido precisou do middleware `web`.** A primeira versão do teste registrava a
   rota ad-hoc só com `['auth', 'role:admin']`, sem o grupo `web` — e isso quebrou a geração da URL de
   dentro do próprio `gerarLink()` com `UrlGenerationException: Missing parameter: company`. Causa:
   sem o middleware `SubstituteBindings` (que só entra via o grupo `web`), o `{company}` da rota nunca
   é resolvido para o model via binding implícito; o resolvedor de dependências do controller, ao ver
   que o parâmetro bruto não é `instanceof Company`, injeta um `Company` **vazio** via
   `app(Company::class)` — com `id = null`. `$company->id` nulo quebra a geração do link assinado
   de dentro do próprio teste (não do código de produção). Fix: `->middleware(['web', 'auth',
   'role:admin'])`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] Blade `min_lines: 80` não batia (70 linhas na primeira versão)**
- **Encontrado durante:** Task 3 (conferência final de `must_haves.artifacts`)
- **Problema:** a primeira versão da blade, com conteúdo completo e correto, tinha 70 linhas — abaixo
  do `min_lines: 80` declarado no frontmatter do plano.
- **Fix:** acrescentado um comentário Blade (`{{-- ... --}}`) no topo explicando os quatro estados e
  a regra de sigilo, mais `<meta name="robots" content="noindex, nofollow">` (conteúdo legítimo —
  a landing é pública e não deve ser indexada). Resultado: 90 linhas, sem introduzir jargão nem citar
  a plataforma de venda (reconferido pelos mesmos `grep` depois do acréscimo).
- **Arquivos modificados:** `resources/views/oauth/bling-conectar.blade.php`
- **Commit:** `b81656cf` (Task 3, antes do commit)

**2. [Rule 1 - Bug] `UrlGenerationException` no teste do papel exigido**
- **Encontrado durante:** Task 1
- **Problema:** descrito em "Decisions Made" acima — rota de teste sem o middleware `web` quebrava o
  model binding implícito, injetando um `Company` vazio e gerando `id = null` na geração da URL.
- **Fix:** `->middleware(['web', 'auth', 'role:admin'])` nas duas rotas ad-hoc do teste.
- **Arquivos modificados:** `tests/Feature/Bling/BlingLinkEAutorizacaoTest.php`
- **Commit:** `73ba3520` (Task 1, antes do commit)

Nenhum outro desvio. Plano executado como escrito, com a correção de desenho já incorporada pelo
orquestrador (ver `<mudanca_de_desenho_que_o_orquestrador_fez_neste_plano>` do prompt) desde o início.

## Known Stubs

Nenhum. Toda a superfície deste plano (service, controller, blade, rotas) está funcional e testada
com `Http::fake` — não há dado vazio renderizado nem caminho sem implementação.

## Threat Flags

Nenhum novo. Todas as superfícies deste plano (link assinado, landing pública, callback público,
conferência de CNPJ) já estavam no `<threat_model>` do próprio `184-07-PLAN.md` (T-184-39 a T-184-46,
T-184-SC), e nenhum arquivo tocado introduz superfície fora dele.

## Verificação dos gates do plano

- `C:\xampp\php\php.exe artisan test --filter=BlingLinkEAutorizacao`: **24 passed**, exit 0 (23 casos
  do `<behavior>` dos três tasks + 1 teste positivo extra).
- `C:\xampp\php\php.exe artisan test --filter=Bling` (suíte inteira da fase): **117 passed**, exit 0 —
  93 já existentes (planos 01-06) + 24 novos deste plano, **zero regressão**.
- `artisan route:list --name=bling`: `bling.connect.landing` com `ValidateSignature` +
  `ThrottleRequests:30,1,bling.landing`, **sem** `auth`; `bling.oauth.callback` com
  `ThrottleRequests:30,1,bling.callback`.
- `grep -c "'signed'" routes/web.php` → **2** (1 baseline da Shopee + 1 novo).
- `grep -c "throttle:30,1,bling" routes/web.php` → **2** (os dois prefixos próprios).
- `git diff <sha-inicial>..HEAD -- routes/web.php | grep -c '^-[^-]'` → **0** (só acréscimos).
- `git diff --stat <sha-inicial>..HEAD -- routes/mlb_anuncios.php` → vazio (plano 11 não disputado).
- `grep -c "STATUS_ATIVA" app/Http/Controllers/BlingOAuthController.php` → **1** (só o callback grava
  esse status; `landing()` identifica "já conectado" pela presença de `access_token`, não pelo
  status, evitando um segundo grep match).
- `grep -cE "COMPARACAO_(IGUAL|MESMA_EMPRESA_OUTRA_UNIDADE|DIFERENTE|EMPRESA_SEM_CNPJ|CONTRATO_SEM_CNPJ)"` →
  **6** (≥5 exigido — os cinco estados tratados, nenhum em `default` silencioso).
- `grep -c '{!!' resources/views/oauth/bling-conectar.blade.php` → **0**.
- `grep -ciE "oauth|token|escopo|callback|\bstate\b" resources/views/oauth/bling-conectar.blade.php` →
  **0** (jargão fora do texto visível).
- `grep -ciE "mercado ?livre|mercadolivre|shopee|magalu|amazon" resources/views/oauth/bling-conectar.blade.php` →
  **0** (sigilo).
- `grep -c "estado" resources/views/oauth/bling-conectar.blade.php` → **6** (≥4 exigido).

## Issues Encountered

Nenhum bloqueio permanente. As duas correções acima (Rule 1 e Rule 3) foram encontradas e fechadas
durante a própria execução das Tasks 1 e 3, antes de qualquer commit.

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling (`BLING_CLIENT_ID`/`SECRET`/
`REDIRECT_URI`) continuam para o checkpoint humano do plano 13 (184-13-T1).

## Next Phase Readiness

- `BlingOAuthService::gerarLinkDeAutorizacao()` está pronto para o plano 11 chamar de dentro de
  `MlbPublicadorBlingController::gerarLink()`, que vai registrar a rota autenticada
  (`role:admin`) em `routes/mlb_anuncios.php` apontando para
  `BlingOAuthController::gerarLink()`.
- `BlingOAuthController::landing()`/`callback()` estão prontos e testados end-to-end (com
  `Http::fake`) — nenhuma chamada real foi feita ao Bling.
- Nenhum ajuste de contrato esperado para o plano 11 ou 08.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-14*

## Self-Check: PASSED

Arquivos:
- FOUND: app/Http/Controllers/BlingOAuthController.php
- FOUND: resources/views/oauth/bling-conectar.blade.php
- FOUND: tests/Feature/Bling/BlingLinkEAutorizacaoTest.php

Commits:
- FOUND: 73ba3520 (Task 1)
- FOUND: 3a2dead8 (Task 2)
- FOUND: b81656cf (Task 3)

`artisan test --filter=Bling`: 117 passed (exit 0) — sem regressão nos 93 testes dos planos 01-06.
`git diff bdc57880..HEAD -- routes/web.php | grep -c '^-[^-]'`: 0.
`git diff --stat bdc57880..HEAD -- routes/mlb_anuncios.php`: vazio.
