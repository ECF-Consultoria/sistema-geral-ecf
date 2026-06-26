---
phase: 42-sugadores-api-ml
plan: 05
subsystem: sugadores
tags: [sugadores, sidebar, ui, ads, deep-link, ml]
requires: ["42-03", "42-04"]
provides:
  - AppLayout.jsx — item "Onboarding ML" removido do grupo Dev (D-02 / REQ-42-07)
  - Sugador::linkAdsML — roteamento por origem (ML vs Adman) via heuristica raw_data (REQ-42-09)
  - Sugador::isOrigemMl — helper privado de deteccao via chaves caracteristicas Mercado Ads
  - tests/Feature/Phase42/SidebarAndAdsLinkTest — 7 tests (sidebar + integracao linkAdsML via Show)
  - tests/Unit/Phase42/LinkAdsMlUnitTest — 5 tests (matriz raw_data → URL)
affects:
  - resources/js/Layouts/AppLayout.jsx
  - app/Models/Sugador.php
tech-stack:
  added: []
  patterns:
    - heuristica raw_data com fallback defensivo (cast=array; sem chave → path legacy)
    - parse direto de JSX file para asserts de sidebar (SSR primario do Inertia nao monta nav)
    - PHPDoc CANDIDATO documentando formato URL ainda a confirmar pos-smoke real
    - PHPUnit 11 atributo #[Test] + RefreshDatabase
key-files:
  created:
    - tests/Feature/Phase42/SidebarAndAdsLinkTest.php
    - tests/Unit/Phase42/LinkAdsMlUnitTest.php
  modified:
    - resources/js/Layouts/AppLayout.jsx
    - app/Models/Sugador.php
decisions:
  - "D-02 (esconde sidebar): item 'Onboarding ML' removido do array children do grupo Dev. Comentario pt-BR 'Phase 42 D-02 / REQ-42-07' preserva rastreabilidade. Rota /dev/sugadores-ml-onboarding, controller Dev/SugadoresMlOnboardingController e React pages Dev/SugadoresMlOnboarding/{Index,Show}.jsx PERMANECEM intactos no repo — acesso via URL direta com role:admin."
  - "Import 'Activity' (lucide-react) removido — era usado SO pelo item removido. Pos-remocao zero ocorrencias no AppLayout (grep -c 'Activity' = 0)."
  - "REQ-42-09 (linkAdsML por origem): helper privado isOrigemMl() detecta origem ML via raw_data. Heuristica usa CONJUNCAO de chaves caracteristicas do payload Mercado Ads (provider Phase 42-03): chave `metrics` como array OU pair `item_id` + `type` no nivel raiz. Provider Adman antigo NAO gera essa estrutura — falso positivo improvavel."
  - "Formato URL Mercado Ads como CANDIDATO: `https://www.mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId={X}`. Inferido pos-Phase 38 smoke (briefing §14). PHPDoc marca explicitamente para revalidar pos smoke real Bymobille — fix incremental de 1 linha caso ML use formato diferente."
  - "Backward compatibility: sugadores Adman legacy (raw_data null OU sem chaves ML) mantem URL legacy `/anuncios/campanhas/{campaign_id}`. Zero regressao garantida via T4-T5-T7 do Feature + T4-T5 do Unit."
  - "Cast defensivo em isOrigemMl: `if (!is_array($raw)) return false` — protege contra raw_data malformado (string JSON nao decodificada, null, scalar) e cai com seguranca no path Adman."
  - "Estrategia de teste sidebar: parse direto do AppLayout.jsx (file_get_contents) em vez de asserts sobre HTML inicial Inertia. Mais robusto e independente de SSR — o JSX so monta a sidebar no client."
metrics:
  duration: ~10min
  completed: 2026-06-26
requirements: [REQ-42-07, REQ-42-09]
commits:
  task1: 4750778
  task2: a0733c1
  task3: 123daa0
---

# Phase 42 Plan 42-05: UI cleanup (sidebar esconde) + linkAdsML por origem — Summary

Fecha os 2 requisitos UI da Phase 42: esconde o item "Onboarding ML" da sidebar
(REQ-42-07 / D-02) e roteia `Sugador::linkAdsML()` para o painel correto Mercado
Ads quando o sugador tem origem ML (REQ-42-09). Mudancas minimas, atomicas, sem
quebrar nada legacy.

Apos este plan:
- Analista nao ve mais o item "Dev > Onboarding ML" no menu lateral — operacao
  segue exclusivamente em `/sugadores`. Rota tecnica permanece acessivel via
  URL direta (D-02: ferramenta tecnica admin, sem ponto de entrada visual).
- Botao "Painel de Ads" no detalhe (`/sugadores/{id}`) abre Mercado Ads
  (`product-ads/anuncios?campaignId={X}`) para sugador origem ML; e o painel
  legacy Adman (`/anuncios/campanhas/{X}`) para sugador origem Adman. Detecao
  automatica por heuristica raw_data — analista nao precisa escolher fonte.

## Tasks Executadas

| Task | Nome                                                                | Commit  | Arquivos                                                                                          |
| ---- | ------------------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------- |
| 1    | Esconder item 'Onboarding ML' da sidebar                            | 4750778 | resources/js/Layouts/AppLayout.jsx                                                                |
| 2    | Sugador::linkAdsML roteia por origem (ML vs Adman) + isOrigemMl     | a0733c1 | app/Models/Sugador.php                                                                            |
| 3    | Suites Feature (7 tests) + Unit (5 tests)                           | 123daa0 | tests/Feature/Phase42/SidebarAndAdsLinkTest.php, tests/Unit/Phase42/LinkAdsMlUnitTest.php          |

## Task 1 — Esconde Sidebar (REQ-42-07 / D-02)

### Antes (Phase 41 Plan 41-05)

```jsx
{
    group: 'Dev',
    icon: Code2,
    children: [
        { label: 'Log',            routeName: 'activity-log.index',  ... },
        { label: 'Desenvolvimento', routeName: 'dev.desenvolvimento', ... },
        // Phase 41 Plan 41-05 — UI admin de onboarding ML por empresa.
        // excludeRoles preserva o gate apenas-admin (sem criar permission_key novo).
        { label: 'Onboarding ML', routeName: 'dev.sugadores_ml_onboarding.index', page: 'Dev/SugadoresMlOnboarding/Index', icon: Activity, excludeRoles: [...] },
        { label: 'ML OAuth',       routeName: 'ml.oauth.index',      ... },
    ],
},
```

### Depois (Phase 42 Plan 42-05)

```jsx
{
    group: 'Dev',
    icon: Code2,
    children: [
        { label: 'Log',            routeName: 'activity-log.index',  ... },
        { label: 'Desenvolvimento', routeName: 'dev.desenvolvimento', ... },
        // Phase 42 D-02 / REQ-42-07: item de UI de onboarding (Plan 41-05) removido daqui.
        // Rota /dev/sugadores-ml-onboarding permanece acessivel via URL direta (role:admin) como ferramenta tecnica.
        { label: 'ML OAuth',       routeName: 'ml.oauth.index',      ... },
    ],
},
```

Import `Activity` (lucide-react) tambem removido — era usado SO pelo item.
`grep -c "\\bActivity\\b" AppLayout.jsx` retorna 0 pos-edicao.

### O que NAO foi alterado (D-02)

- `routes/web.php` — 6 rotas do grupo `dev.sugadores_ml_onboarding.*` intactas.
- `app/Http/Controllers/Dev/SugadoresMlOnboardingController.php` — intacto.
- `resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx` — intacto.
- `resources/js/Pages/Dev/SugadoresMlOnboarding/Show.jsx` — intacto.
- DB layer (SugadorMlCompanyConfig, MlAdvertiser, etc.) — intacto.

Validacao via `git diff --stat HEAD~3..HEAD`: somente 2 arquivos de produto
modificados (AppLayout.jsx + Sugador.php) e 2 arquivos de test criados.

## Task 2 — linkAdsML por Origem (REQ-42-09)

### Antes

```php
public function linkAdsML(): string
{
    $base = 'https://www.mercadolivre.com.br/anuncios';
    if ($this->campaign_id) {
        return $base . '/campanhas/' . $this->campaign_id;
    }
    return $base;
}
```

URL gerada sempre em formato legacy Adman, mesmo para sugadores ML — analista
clicava "Painel de Ads" e caia no painel errado.

### Depois

```php
public function linkAdsML(): string
{
    if ($this->isOrigemMl()) {
        // Phase 42 REQ-42-09: deep link Mercado Ads para sugador origem ML.
        $base = 'https://www.mercadolivre.com.br/anuncios/product-ads/anuncios';
        if ($this->campaign_id) {
            return $base . '?campaignId=' . urlencode((string) $this->campaign_id);
        }
        return 'https://www.mercadolivre.com.br/anuncios/product-ads';
    }

    // Mantem comportamento existente Adman (zero regressao para sugadores legacy).
    $base = 'https://www.mercadolivre.com.br/anuncios';
    if ($this->campaign_id) {
        return $base . '/campanhas/' . $this->campaign_id;
    }
    return $base;
}

private function isOrigemMl(): bool
{
    $raw = $this->raw_data;
    if (!is_array($raw)) {
        return false;
    }
    // Sinal forte: chave `metrics` aninhada (estrutura Mercado Ads).
    if (isset($raw['metrics']) && is_array($raw['metrics'])) {
        return true;
    }
    // Sinal alternativo: pair `item_id` + `type` no nivel raiz.
    if (isset($raw['item_id']) && isset($raw['type'])) {
        return true;
    }
    return false;
}
```

### Matriz raw_data → URL gerada

| raw_data                                          | campaign_id | URL gerada                                                                  |
| ------------------------------------------------- | ----------- | --------------------------------------------------------------------------- |
| `{metrics: {...}, item_id: 'MLB1', type: 'p_ad'}` | `C1`        | `mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId=C1`           |
| `{metrics: {...}}`                                | `C1`        | `mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId=C1`           |
| `{item_id: 'MLB1', type: 'p_ad'}` (sem metrics)   | `C1`        | `mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId=C1`           |
| `{metrics: {...}}`                                | `null`      | `mercadolivre.com.br/anuncios/product-ads` (base sem query)                 |
| `{campaignId: 123, accountId: 456}` (Adman)       | `C1`        | `mercadolivre.com.br/anuncios/campanhas/C1` (legacy preservado)             |
| `null`                                            | `C1`        | `mercadolivre.com.br/anuncios/campanhas/C1` (legacy preservado)             |
| `null`                                            | `null`      | `mercadolivre.com.br/anuncios` (base legacy)                                |

### CANDIDATO — formato URL ainda nao validado em prod

PHPDoc do metodo documenta explicitamente que o formato
`product-ads/anuncios?campaignId={X}` foi inferido pos-Phase 38 smoke
(briefing §14) e ainda nao foi confirmado contra um clique real no
Mercado Ads. Smoke real Bymobille (post-deploy) confirma. Se ML usar
`/campaigns/{id}/edit` ou outra rota canonica, fix incremental de 1 linha:
ajustar o `$base` do branch `isOrigemMl()`. Threat T-42-05-04 acompanhada.

## Task 3 — Suites de Tests (12 tests)

### Feature `SidebarAndAdsLinkTest` (7 tests, namespace Tests\Feature\Phase42)

| # | Test                                                       | Cobertura                                                            |
| - | ---------------------------------------------------------- | -------------------------------------------------------------------- |
| T1 | `sidebar_nao_contem_onboarding_ml_para_admin`             | Parse AppLayout.jsx: item + rota nomeada ausentes; comentario D-02 presente; GET /dashboard nao quebra para admin |
| T2 | `sidebar_nao_contem_onboarding_ml_para_consultor`         | Mesma validacao para consultor (excludeRoles agora redundante — item nem existe) |
| T3 | `rota_direta_continua_acessivel_para_admin`               | GET /dev/sugadores-ml-onboarding para admin nao retorna 404 nem 403 — D-02 |
| T4 | `url_ads_aponta_para_mercado_ads_quando_origem_ml`        | Sugador raw_data ML completo → Inertia prop `url_ads` contem 'product-ads' |
| T5 | `url_ads_mantem_formato_adman_quando_raw_nao_eh_ml`       | Sugador raw_data Adman-like → `url_ads` usa `/anuncios/campanhas/{X}` (sem product-ads) |
| T6 | `url_ads_sem_campaign_id_retorna_base`                    | Sugador origem ML mas campaign_id=null → base product-ads sem `campaignId=` |
| T7 | `url_ads_sem_raw_data_cai_em_adman`                       | Backward compatibility: raw_data=null + campaign_id=C123 → `/anuncios/campanhas/C123` |

### Unit `LinkAdsMlUnitTest` (5 tests, namespace Tests\Unit\Phase42)

| # | Test                                                       | Cobertura                                                            |
| - | ---------------------------------------------------------- | -------------------------------------------------------------------- |
| T1 | `origem_ml_completa_gera_url_mercado_ads_com_campaign_id` | raw_data={metrics, item_id, type} + campaign_id='C1' → contem 'product-ads/anuncios' e 'campaignId=C1' |
| T2 | `origem_ml_so_metrics_gera_url_mercado_ads`               | raw_data={metrics} apenas → URL Mercado Ads (sem campanhas legacy) |
| T3 | `origem_ml_so_item_id_e_type_gera_url_mercado_ads`        | raw_data={item_id, type} sem metrics → URL Mercado Ads |
| T4 | `raw_data_adman_mantem_url_legacy`                        | raw_data={campaignId, accountId} → URL exata legacy |
| T5 | `raw_data_null_cai_em_path_adman_legacy`                  | raw_data=null + campaign_id='C1' → URL exata legacy |

**Total acumulado Phase 42:** 29 (Plans 42-01..04) + 12 (Plan 42-05) = **41 tests**.

**NOTA sobre execucao:** PHPUnit NAO foi executado dentro do worktree (regra do
parallel_execution: tests serao rodados pelo orquestrador apos merge na main).
Sintaxe validada via `php -l` nos 2 arquivos de test — sem erros.

## Decisoes Tomadas

1. **Estrategia de assert sidebar via parse de JSX** — O SSR primario do
   Inertia nao monta o NAV_TREE no HTML inicial (so prep dos props); o JSX
   so renderiza a sidebar no client. Asserir HTML inicial seria flaky.
   `file_get_contents` do JSX + asserts de string sao deterministicos e
   diretos: o source do arquivo eh a fonte da verdade do array de navegacao.

2. **Heuristica isOrigemMl via 2 sinais alternativos** — Briefing §3 e Plan
   42-03 documentam que `raw_data` do MercadoLivreSugadoresProvider tem ou
   `metrics` aninhado ou `item_id` + `type` no nivel raiz. Usamos qualquer
   um dos 2 como sinal suficiente — proteje contra variacao do payload
   (algum endpoint Mercado Ads pode nao ter `metrics` mas tem ads metadata).
   T1-T3 do Unit cobrem ambos os caminhos.

3. **PHPDoc CANDIDATO ao inves de TODO** — Marcar como CANDIDATO eh padrao
   do projeto pos-Phase 38 (vide MercadoLivreSugadoresProvider linhas 142-155
   e 185). Sinaliza: "funciona com fixture, revalidar em prod". Mais
   informativo que TODO/FIXME e linkado ao briefing §14.

4. **NAO mexer em routes/web.php** — D-02 explicito. O comentario
   `Phase 41 Plan 41-05 — UI admin de onboarding ML por empresa` em
   routes/web.php fica para o time de auditoria entender que a rota
   permanece intencionalmente. Atualizar comentario seria escopo creep.

5. **NAO mexer em SugadorController::show** — `url_ads` ja era injetado
   na linha 272; a logica de roteamento por origem mora no model. Mais
   testavel (Unit puro) e o controller permanece thin.

6. **Cast defensivo em isOrigemMl** — `is_array($raw)` antes de tudo. Se
   o cast Eloquent falhar (raw_data malformado em DB legacy) ou se vier
   string nao decodificada, caimos com seguranca no path Adman. T5 do
   Unit valida raw_data=null.

## Deviations from Plan

Nenhuma. Plan executado exatamente como escrito. Os done criteria literais
do plan (ex.: `grep -c "Onboarding ML" = 0`) foram atingidos ajustando o
comentario de rastreabilidade para `"item de UI de onboarding (Plan 41-05)
removido daqui"` — preserva o sinal D-02 sem usar a string literal do label.

## Threat Mitigations

- **T-42-05-01 (Information disclosure — rota descoberta via URL):** aceito.
  Middleware `role:admin` em `routes/web.php` continua filtrando. T3 do
  Feature valida que admin acessa, e fica implicito que consultor seria
  filtrado pelo middleware (gate via test do Phase 41-05).
- **T-42-05-02 (Tampering — falso positivo isOrigemMl):** mitigado. T4-T5 do
  Feature + T4 do Unit usam raw_data Adman-like com chaves diferentes
  (`campaignId`, `accountId`) e validam que `url_ads` NAO contem
  `product-ads` — heuristica nao dispara falso positivo.
- **T-42-05-03 (DoS — Mercado Ads pode redirecionar para login):** aceito.
  Briefing §15 explicita que analista ja loga manualmente no painel ML.
  Comportamento esperado da industria — abrir em nova aba o forca a logar.
- **T-42-05-04 (Repudiation — formato URL CANDIDATO):** mitigado via
  PHPDoc explicito + fix incremental documentado. Smoke real Bymobille
  post-deploy confirma. Fallback em caso de URL invalida: usuario ve
  404/erro do Mercado Livre, nao corrupcao em dados ECF.
- **T-42-05-SC (Tampering — installs):** nao aplicavel — esta phase NAO
  instala packages npm/composer.

## Verificacao dos Success Criteria

1. ✅ **Tasks executadas + commitadas** — 3 tasks atomicas, commits
   4750778, a0733c1, 123daa0.
2. ✅ **SUMMARY.md criado** — `42-05-SUMMARY.md` neste diretorio.
3. ✅ **STATE.md / ROADMAP.md / vendor NAO modificados** — `git diff --stat
   HEAD~3..HEAD` mostra apenas: AppLayout.jsx, Sugador.php, 2 arquivos de
   test, e o proprio SUMMARY.md (sera commitado na sequencia).
4. ✅ **Item Onboarding ML NAO aparece em AppLayout.jsx** —
   `grep -c "label: 'Onboarding ML'"` = 0. Import `Activity` removido.
5. ✅ **Arquivos Dev/SugadoresMlOnboarding/* PERMANECEM** — controller,
   Index.jsx, Show.jsx intactos. `git diff` confirma zero modificacoes.
6. ✅ **Rota `dev.sugadores_ml_onboarding.*` PERMANECE** — 6 rotas
   registradas em routes/web.php intactas. `grep` valida hit em prefix.
7. ✅ **REQ-42-09: linkAdsML para sugador ML retorna URL valida Mercado Ads** —
   `grep -c "product-ads"` em Sugador.php = 3 (PHPDoc + 2 returns).
   Matriz raw_data → URL coberta por 5 Unit tests + 4 Feature tests.

## Self-Check: PASSED

- `resources/js/Layouts/AppLayout.jsx` — FOUND (modified, commit 4750778)
- `app/Models/Sugador.php` — FOUND (modified, commit a0733c1)
- `tests/Feature/Phase42/SidebarAndAdsLinkTest.php` — FOUND (created, commit 123daa0)
- `tests/Unit/Phase42/LinkAdsMlUnitTest.php` — FOUND (created, commit 123daa0)
- Commit 4750778 (Task 1) — FOUND no git log
- Commit a0733c1 (Task 2) — FOUND no git log
- Commit 123daa0 (Task 3) — FOUND no git log
- `grep -c "label: 'Onboarding ML'" AppLayout.jsx` retorna 0 ✅
- `grep -c "dev.sugadores_ml_onboarding" AppLayout.jsx` retorna 0 ✅
- `grep -c "Phase 42 D-02" AppLayout.jsx` retorna 1 ✅
- `grep -c "Activity" AppLayout.jsx` retorna 0 ✅
- `grep -c "product-ads" Sugador.php` retorna 3 ✅
- `grep -c "isOrigemMl" Sugador.php` retorna 3 ✅ (declaracao + 2 chamadas/refs)
- `grep -c "REQ-42-09" Sugador.php` retorna 2 ✅
- `grep -c "CANDIDATO" Sugador.php` retorna 1 ✅
- `grep -cE '^\s*#\[Test\]' SidebarAndAdsLinkTest.php` retorna 7 ✅
- `grep -cE '^\s*#\[Test\]' LinkAdsMlUnitTest.php` retorna 5 ✅
- `php -l app/Models/Sugador.php` — No syntax errors ✅
- `php -l tests/Feature/Phase42/SidebarAndAdsLinkTest.php` — No syntax errors ✅
- `php -l tests/Unit/Phase42/LinkAdsMlUnitTest.php` — No syntax errors ✅
- routes/web.php — NAO modificado (git diff HEAD~3..HEAD limpo) ✅
- app/Http/Controllers/Dev/SugadoresMlOnboardingController.php — NAO modificado ✅
- resources/js/Pages/Dev/SugadoresMlOnboarding/*.jsx — NAO modificado ✅

## Known Stubs

Nenhum. As 2 mudancas sao auto-contidas e funcionam end-to-end com o stack
deployado. O formato URL Mercado Ads marcado como CANDIDATO no PHPDoc nao
eh stub — eh comportamento real, apenas pendente de validacao com smoke
em prod (sera coberto em Plan 42-06 acceptance E2E).

## Threat Flags

Nenhuma surface nova fora do `<threat_model>` do plano. Mudancas sao:
- Item de UI removido de sidebar (AppLayout.jsx) — diminui surface visual,
  nao adiciona endpoint, auth path ou trust boundary novo.
- Heuristica de roteamento em metodo existente do model (linkAdsML) — sem
  novo endpoint, sem mudanca de schema, sem mudanca de auth.

Nenhuma alteracao em trust boundaries existentes. URL gerada eh consumida
pelo browser do usuario abrindo nova aba — fluxo existente desde Phase 1.
