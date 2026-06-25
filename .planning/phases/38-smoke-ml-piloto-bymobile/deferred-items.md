# Phase 38 — Itens Diferidos (out-of-scope)

Itens descobertos durante execução do Plan 02 que são **fora do escopo** do smoke ML
(Milestone v11.0) e foram deixados para tratamento posterior.

## Pré-existentes — não introduzidos pela Phase 38 Smoke ML

### `Tests\Feature\Phase38\PolosControllerTest` — 6 tests falhando

- **Origem:** commit `ba6fc24 feat(polos): Faturamento por Polo vs Meta (Phase 38) — deploy isolado`
- **Escopo:** módulo Polos (outra "Phase 38" do outro dev em paralelo na mesma milestone, namespace coincidente em `tests/Feature/Phase38/`)
- **Por que NÃO foi corrigido aqui:** SCOPE BOUNDARY do executor — falhas pré-existentes em arquivos não relacionados ao smoke ML (`MercadoLivreAdsService`/`SugadoresMlSmoke`).
- **Tests falhando:**
  - `meta por estagio`
  - `status sim`
  - `status em progresso`
  - `status problema precedencia`
  - `m1 excluido`
  - `status dist`
- **Sintoma comum:** Inertia `->where('statusDist.Sim', 1)` falha (linha ~504 do `PolosControllerTest`) — provavelmente schema de seed/factory dos Polos divergiu do que o controller emite. Não bloqueia Phase 38 Smoke ML.
- **Ação futura sugerida:** outro dev (responsável pelo módulo Polos) deve revisar `PolosControllerTest` na próxima `gsd-quick` do escopo Polos.

## Phase 38 Smoke ML — zero regressão introduzida

- 4/4 tests do `MercadoLivreAdsServiceTest` (Plan 01): VERDES
- 4/4 tests do `MlSmokeCommandTest` (Plan 02): VERDES
- 0 alterações em arquivos de produção do Sugadores
