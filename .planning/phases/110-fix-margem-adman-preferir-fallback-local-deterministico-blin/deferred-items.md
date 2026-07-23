# Deferred Items — Fase 110

## Falha pré-existente fora de escopo — `PublicacaoDesempenhoRouteTest`

**Detectado durante:** regressão ampla do 110-01 (V16/V18/Nps/Desempenho).

**Teste:** `Tests\Feature\PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200`
**Erro:** `Expected response status code [200] but received 403.`

**Por que está fora de escopo:** este plano só tocou `app/Services/Metrics/AdmanMetricDiffService.php`,
`app/Services/DesempenhoScoreService.php` e 4 arquivos de teste (margem % + cacheKey). Nenhum desses
arquivos tem relação com a rota `/publicacao/desempenho`, `EnsureUserHasRole` ou permissões MLB
dashboard. `git log` confirma que `routes/web.php`/`EnsureUserHasRole.php`/o próprio arquivo de teste
não foram tocados por nenhum commit do plano 110-01 — a última alteração nesses arquivos é de um
commit anterior e não relacionado (`797366cb feat(shopee): botão "Sincronizar agora"`).

**Ação:** NÃO corrigido (Scope Boundary — só auto-fix de issues causadas pelo task atual). Registrado
aqui para investigação futura fora da Fase 110.

## Falhas pré-existentes fora de escopo — testes de NPS sensíveis a horário de execução (regressão ampla do 110-02)

**Detectado durante:** regressão ampla do 110-02 (`--filter="Desempenho|V18|Nps"`, 451 testes, 646s).

1. **`Tests\Feature\Phase31NpsSubmitTest::generate_cria_survey_com_auto_generated_false`**
   Erro: `Failed asserting that false is true.` em `assertTrue($survey->expires_at->between(now()->addDays(6), now()->addDays(8)))`.
2. **`Tests\Feature\Phase69\NpsPhase69IntegrationTest::fluxo_2_generate_manual_por_admin_estrategista`**
   Erro: `Expected '2026-07-30' Actual '2026-07-31'` em assert de `expires_at` (+7 dias, REQ-31-08).

**Por que estão fora de escopo:** nenhum dos dois testes usa `Carbon::setTestNow()` no método que falhou
(ambos comparam `expires_at` contra `now()` REAL no momento da asserção, não contra um relógio congelado)
— são testes sensíveis ao instante exato de execução (cruzamento de fronteira de dia entre a criação do
survey e a asserção), não a uma regressão de código. Este plano (110-02) só tocou
`app/Services/DesempenhoScoreService.php`, `app/Console/Commands/ConsolidarMesDesempenho.php` e
`tests/Feature/Phase110/ConsolidarMesMargemResilienteTest.php` — nenhuma relação com geração/expiração de
NPS survey manual (`NpsController`/`generate`). `git log` confirma que nenhum arquivo dessas duas suites
foi tocado por commits deste plano.

**Ação:** NÃO corrigido (Scope Boundary). Registrado aqui para investigação futura fora da Fase 110 —
candidato a `Carbon::setTestNow()` ausente nesses 2 métodos específicos.
