# Itens deferidos — Fase 80

## Falha PRÉ-EXISTENTE (fora do escopo do 80-01)

**`Tests\Feature\PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200`**

- **Sintoma:** `Expected response status code [200] but received 403` (`tests/Feature/PublicacaoDesempenhoRouteTest.php:149`).
- **Quando aparece:** capturada pelo `--filter=Desempenho` apenas por coincidência de nome de classe — é um teste de rota/permissão do módulo de PUBLICAÇÃO, não da régua de bônus.
- **Pré-existente:** confirmada falhando em `HEAD 47f38d5` com a árvore de trabalho limpa (zero alterações minhas), ANTES de qualquer edição do Plan 80-01.
- **Relação com esta fase:** nenhuma. Não toca `DesempenhoScoreService` nem `nps_score_assignments`.
- **Ação:** NÃO corrigida aqui (SCOPE BOUNDARY — só se auto-corrige o que a task corrente causou). Fica registrada para triagem própria.

**Âncoras de regressão da fase seguem verdes:** `--filter=DesempenhoScoreServiceTest` 14/14 (inclui `test_fixture_carlos_retorna_nota_4_08_basico`) e `--filter=AtribuicaoPorServico` 8/8.

---

## Descobertas do Plan 80-02 (todas PRÉ-EXISTENTES, fora do escopo)

Todas medidas como **idênticas com a chave de cache em v2 e em v3, no MESMO ambiente**
(experimento de variável única) — nenhuma é efeito do 80-02. Argumento estrutural
independente: `CACHE_STORE=array` deixa o cache vazio em todo teste, o único arquivo do
repo que hardcoda `desempenho.compute` é o teste novo do 80-02, e nenhum dos itens abaixo
referencia `computeCached`/`DesempenhoScore`.

### 1. Suite completa (`vendor/bin/phpunit` sem filtro) não termina nesta máquina

- **Sintoma:** `Fatal error: Maximum execution time of 300 seconds exceeded`
  (morre onde estiver — tipicamente `MercadoLivreAdsService.php:215`).
- **Causa raiz:** o `php.ini` do CLI tem `max_execution_time=0`, mas `set_time_limit(300)`
  é re-armado em runtime pelos comandos de Grants (`SyncGrantsFromSftp.php:22`,
  `SyncGrantsFromEcfDrive.php:23`, `GrantController.php:287/327`). A partir daí o processo
  INTEIRO do phpunit passa a ter 300s de orçamento. No **Windows** o `usleep` conta
  wall-clock contra esse limite (no Linux não conta), então o backoff exponencial dos
  testes de Sugadores estoura o resto do orçamento.
- **NÃO é teste quebrado:** `MercadoLivreAdsServiceBackoffTest` 13/13 e
  `MercadoLivreAdsServiceTest` 4/4 passam isolados.
- **Pré-existente:** reproduzido em worktree no commit `b90bf1f` (antes do 80-02).
- **Workaround:** rodar em chunks (`--testsuite=Unit`, depois `tests/Feature/<dir>`).
- **Ação futura sugerida:** isolar os testes de backoff com fake de sleep, ou não chamar
  `set_time_limit(300)` quando `app()->runningUnitTests()`.

### 2. Falhas pré-existentes por chunk (v2 ≡ v3, assertion count incluso)

| Chunk | Resultado (idêntico nas duas chaves) |
|---|---|
| Phase18 | 25T, 273A, 2F |
| Phase38 | 26T, 234A, 5F |
| Phase38Publicador | 5T, 68A, 2F |
| Phase42 | 42T, 236A, 5F, 1S |
| Phase75 | 43T, 150A, 3F |
| Phase77 | 33T, 87A, 1F |
| Polos | 4T, 32A, 2E/2F/2R |

### 3. `tests/Unit` — 9 erros + 3 falhas pré-existentes

- `CalcularFaixaTest` (9 erros): `ArgumentCountError` — `AdminController::__construct()`
  passou a exigir 1 argumento e o teste instancia sem nenhum. Teste desatualizado.
- `CompanyServiceTypeTest::test_service_type_aceita_polo`: a armadilha conhecida do enum
  `polos` no SQLite (memória `project_enum_setor_sqlite_check`).
- `MercadoLivreSugadoresProviderTest` (2): normalização de payload do ML Ads.
- `tests/Unit/` tem **zero** referências a `DesempenhoScore`.

### 4. `DevControllerTest` + `ExampleTest` (arquivos soltos de `tests/Feature`)

- 404 em rotas `/dev/*` — rotas alteradas/removidas, testes não atualizados.

### 5. Aviso de worktree para baselines

Worktree criado por `git worktree add` **não** herda `public/build/manifest.json` nem o
scaffolding de `storage/` → a renderização Inertia falha cedo e o baseline reporta MAIS
falhas que o HEAD (Phase18: 14F vs 2F; 65 vs 273 assertions). Um baseline assim leva à
conclusão falsa de que a fase "consertou" dezenas de testes. Para comparar, preferir
**alternar a variável suspeita no ambiente real**.

---

## Descobertas do Plan 80-03

### 6. `PortfolioController` — série histórica de NPS ainda no `->principal()` (FOLLOW-UP RECOMENDADO)

O `<output>` do 80-03 pede o veredito explícito: **sim, deve virar follow-up — mas NÃO nesta
fase** (está fora do escopo declarado do 80-CONTEXT, que nomeia só os 3 widgets do
`dashboardCarteira`).

- **Onde:** `app/Http/Controllers/PortfolioController.php:1374` — mesmo padrão que o 80-03
  acabou de aposentar: `->principal()` + dimensão derivada do cargo (`$npsDim`).
- **Sintoma esperado:** a série histórica de NPS da carteira (`avg`/`count`/`ultima_nota` por
  mês) **não enxerga resposta de NPS Shopee** — exatamente o sintoma que o usuário reportou
  no widget, sobrevivendo em outra tela. O `dashboardCarteira` e o `PortfolioController`
  passam a contar histórias DIFERENTES sobre o mesmo NPS.
- **Agravante encontrado nesta leitura (não estava mapeado no CONTEXT nem no RESEARCH):**
  o agrupamento por mês usa
  `groupBy(fn ($s) => $s->month_reference?->format('Y-m') ?? $s->completed_at?->format('Y-m'))`
  — ou seja, **`month_reference` tem precedência sobre `completed_at`**. Isso conflita
  frontalmente com o **DEC-80-B0** (mês SEMPRE de `nps_surveys.completed_at`; `month_reference`
  é o mês do DISPARO e é NULL de propósito no fixture Carlos). Uma resposta disparada em um mês
  e respondida no seguinte é contabilizada no mês do disparo aqui e no mês da resposta no
  `dashboardCarteira`. **Corrigir os dois no mesmo follow-up** — mexer só no `->principal()`
  deixaria a divergência de mês de pé.
- **Por que NÃO auto-corrigir agora (SCOPE BOUNDARY):** não é bug causado pelo 80-03 (é
  pré-existente e intocado — `git diff --stat 694096f..HEAD` = 3 arquivos, nenhum deles o
  `PortfolioController`), e a mudança do mês-fonte altera número exibido de carteira. Merece
  plano + teste próprios, não um fix de carona.
- **Cache:** o `PortfolioController` **já está coberto pelo bump v3** do 80-02 (consome
  `computeCached` em `:1251`/`:1277`) — o follow-up não precisa de novo bump, só do alinhamento
  da query.
