# Deferred Items — Fase 89 Plan 02 (CART-08)

Itens fora do escopo desta fase, encontrados ao rodar `php artisan test --filter=Companies`
como parte da regressão final. Nenhum toca `consultor`/`estrategista`/`analistaPerformance`/
`estrategistaPerformance` — todos são quebras pré-existentes de fixtures antigas contra
payloads/scopes já refatorados em fases anteriores. Não corrigidos (fora do escopo desta task,
per SCOPE BOUNDARY do executor).

| Teste | Motivo da falha | Causa raiz provável (pré-existente) |
|---|---|---|
| `Tests\Feature\Phase13ComercialTest::guard_duplicata_companies` | `assertSessionHasErrors(['nome'])` falha — erro de validação não é o esperado | Fixture desatualizada, não relacionada a responsável/pendência |
| `Tests\Feature\Phase13MigrationTest::companies_retroativas_tem_status_ativo` | Company esperada pela migration não encontrada | Migration/seed retroativa não roda mais como o teste espera |
| `Tests\Feature\Phase14MlbControllerFiltroTest::companies_index_filtra_pendentes_publicidade_gestao` | Espera propriedade `empresas_pendentes` no payload de `Companies/Index`, que não existe mais | Payload de `CompanyController::index()` foi refatorado em fases posteriores (35/37) — chave removida |
| `Tests\Feature\Phase18\CompaniesCustIdFilterTest::filtro_invalido_retorna_apenas_invalidas` | `companies` retorna 0 empresas, esperado 1 | Fixtures criam `Company::create()` sem contrato Performance ativo — excluídas pelo `whereHas('contratosServico', setor=performance)` introduzido na Fase 37-06 (`1df9874`), anterior a esta fase |
| `Tests\Feature\Phase18\CompaniesCustIdFilterTest::sem_filtro_retorna_todas_as_empresas` | `companies` retorna 0 empresas, esperado 3 | Mesma causa acima |

**Confirmação de pré-existência:** o filtro `whereHas('contratosServico', ... setor=performance)`
em `CompanyController::index()` foi introduzido no commit `1df9874` (feat(37-06)), muito antes
desta fase (89-02) e não foi tocado por este plano. `git log -S` confirma a origem do filtro.
