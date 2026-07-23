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
