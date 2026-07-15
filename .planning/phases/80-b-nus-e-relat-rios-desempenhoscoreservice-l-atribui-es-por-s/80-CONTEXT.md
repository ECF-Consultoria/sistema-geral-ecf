# Phase 80: Bônus e relatórios — DesempenhoScoreService lê atribuições por serviço (v16.0) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** `.planning/milestones/v16.0-brief.md` (DEC-B) + Ajuste 3 do usuário + validação real em prod (Decoral).

<domain>
## Phase Boundary

Última fase da v16.0 e a **mais sensível**: fazer o bônus/ranking/widgets lerem as **atribuições congeladas** (`nps_score_assignments`, criadas na Fase 79) em vez do cruzamento read-time atual (carteira × dimensão do cargo, só do modelo principal). É isso que faz a nota do NPS Shopee aparecer no ranking `/performance` e no widget analista/estrategista.

**Fato validado em produção (2026-07-14):** a resposta do NPS Shopee da empresa **Decoral** já gerou as atribuições corretas — Gustavo (role=consultor/Analista, setor=shopee, média 3.11) e Felipe (role=estrategista, setor=shopee, média 2.25). Os dados existem; falta o CONSUMO.

**IN SCOPE:**
1. `DesempenhoScoreService::computeNpsMedio` passa a somar as atribuições da pessoa (todas as áreas — ML + Shopee).
2. Aposentar "só o modelo principal conta" (`->principal()`): respostas de QUALQUER modelo contam, via atribuições.
3. Dual-path para não quebrar histórico (respostas pré-Fase 79 não têm atribuição).
4. Bump de versão do cache (v2 → v3).
5. Ranking `/performance`, widget NPS analista/estrategista e demais leitores por-pessoa passam a refletir as atribuições.
6. Recortes: por `service_setor` (ML vs Shopee), por `role`, por pessoa — com dedup.

**OUT:** motor/disparo/snapshot do NPS (Fase 79, feito); config UX (Fase 81, feito).
</domain>

<decisions>
## Implementation Decisions

### DEC-80-A — NPS da pessoa = média das atribuições dela (Ajuste 3 do usuário — LOCKED)
- A nota de NPS de um profissional no mês = média dos `nps_score_assignments.average_score` onde `user_id` = ele, no mês de referência — **somando TODAS as áreas** (ML **e** Shopee). O Gustavo NÃO cuida só de Shopee: os NPS das empresas de Mercado Livre dele **devem** agregar na média dele. NUNCA filtrar por um único `service_setor` no cálculo do bônus.
- A `role` da atribuição (`consultor`=Analista / `estrategista`) já encoda a dimensão — não depender mais do cargo do user para escolher a dimensão.
- **Dedup:** contar 1× por (`nps_response_id`, `role`) por pessoa. Se a mesma pessoa for responsável por 2 serviços cobertos da MESMA resposta, não inflar (o spec exige "1× por papel"; recortes por setor podem usar `service_setor`).

### DEC-80-B0 — Fonte do MÊS = JOIN até `nps_surveys.completed_at` (CORREÇÃO do RESEARCH)
- `nps_score_assignments` **não tem coluna de mês**. O mês do bônus DEVE sair de JOIN `assignments → nps_responses → nps_surveys.completed_at` (a MESMA fonte que o caminho legado usa) — nunca de `assigned_at` (um backfill futuro gravaria a data do backfill → a resposta migraria de mês e zeraria o bônus de alguém sem erro no log) nem de `survey.month_reference` (é o mês do DISPARO e é NULL no fixture Carlos de propósito).

### DEC-80-B1 — Predicado do dual-path: snapshot é AUTORITATIVO por papel (CONFIRMADO pelo usuário 2026-07-14)
- **NÃO usar o predicado literal** "se existe atribuição para (user, resposta)". Ele reabre a super-atribuição no sentido inverso: `User::companies()` não filtra por `servico_id` (User.php:206-220), então a Decoral (onde Gustavo é analista SHOPEE) está na carteira dele; o NPS Padrão (principal) da Decoral entra na query legada dele; ele não tem atribuição nessa resposta → cairia no legado e **receberia a nota de ML da Decoral** (que pertence ao analista ML). Gustavo ficaria (3.11 + ML)/2 em vez de 3.11.
- **Predicado correto (LOCKED):** pular a resposta no ramo legado quando ela **já tem atribuição no papel correspondente à dimensão do cargo do user**. Se o snapshot da Fase 79 nomeou os responsáveis daquele papel naquela resposta, a lista é **autoritativa** — quem não está nela não recebe nada dessa resposta.
- Propriedades: preserva 100% do histórico (resposta com zero atribuições → zero skip → legado normal); entrega **Gustavo = 3.11 exato** na Decoral; subsume o predicado literal (nenhuma resposta conta 2×).
- **Não contradiz o Ajuste 3:** o Gustavo continua somando o NPS de ML das empresas **onde ELE é o analista ML** (lá ele TEM a atribuição). O que ele não recebe é o NPS ML da Decoral, onde é só o analista Shopee.

### DEC-80-B — Dual-path POR RESPOSTA (não quebrar o bônus histórico)
- Respostas anteriores à Fase 79 (e o mês de transição, que é misto) NÃO têm atribuição. Regra: para cada resposta no escopo do usuário no mês — **se existe atribuição para (user, resposta)** → usar `average_score` da atribuição; **senão** → cair no cálculo legado atual (carteira `company_users` × dimensão do cargo × `NpsScoreCalculator::compute`), preservando também o fallback das colunas legacy `score_*`.
- Isso mantém meses históricos idênticos e faz o mês de transição somar corretamente (respostas novas via atribuição + antigas via legado). NÃO usar corte por mês inteiro (perderia respostas pré-deploy do mês corrente).
- Preservar a semântica atual de "sem respostas → 0.0" (DESEMP-03 penaliza).

### DEC-80-C — Bump de cache
- `computeCached` usa a chave `desempenho.compute.v2.{user}.{mês}` — bumpar para **v3** (senão bônus servido do cache antigo). Precedente: bump v1→v2 em 2026-07-13 por correção de dimensão do NPS.

### DEC-80-D — "Só o principal conta" aposentado APENAS no caminho das atribuições (CORRIGIDO pelo RESEARCH)
- **O `->principal()` PERMANECE no ramo LEGADO.** Só o ramo das ATRIBUIÇÕES ignora o modelo (qualquer modelo com atribuição conta).
- **Por quê (armadilha crítica):** se removermos o `->principal()` do legado, a resposta do NPS Shopee da Decoral entra no escopo legado do **analista de ML da mesma empresa** — ele não tem atribuição nessa resposta, cai no fallback e **recebe a nota do Shopee**. Isso é exatamente a super-atribuição que o congelamento da Fase 79 existe para impedir. Uma leitura literal de "aposentar o principal" QUEBRA o isolamento por serviço.
- A memória `project_nps_modelo_principal` fica superada só nesse recorte (bônus via atribuições) — atualizar ao fim da fase com a nuance. O `is_default` segue sendo o fallback de resolução de template (Fase 79) — não confundir os papéis.

### DEC-80-E — Leitores/widgets: EM ESCOPO, mas em PLANO SEPARADO (RESEARCH OQ2)
- O usuário reportou que a nota do NPS Shopee (Decoral) não aparece **nem no ranking `/performance` nem no widget de NPS analista/estrategista** → os DOIS estão em escopo.
- **Ranking + headline `nps.media` se consertam sozinhos** com a mudança do service (consomem `computeCached`/`computeNpsMedio`) → ficam no plano do service.
- **Widgets que usam `->principal()` diretamente** (coluna NPS por empresa, últimas respostas, heatmap no `PerformanceController::dashboardCarteira` :298-446) precisam de trabalho explícito + `npm run build` → **plano SEPARADO**, para a cirurgia do bônus (plano do service) ficar isolada e de baixo risco.
- Recorte por `service_setor`/`role` onde fizer sentido, sem inflar a média da pessoa (dedup DEC-80-A).
- Preservar `desempenho_score_snapshots` já consolidados (histórico de bônus fechado) — NÃO reescrever o passado.

### Claude's Discretion
- Se o dual-path vira um método privado no `DesempenhoScoreService` ou um service novo.
- Como o `PerformanceController` (dashboardCarteira/index) e o widget consomem (podem reusar o service).
- Formato exato dos recortes por setor na UI (pode ser mínimo nesta fase).
</decisions>

<constraints>
## Constraints
- **ÁREA CRÍTICA — a régua de bônus.** Qualquer erro altera bonificação de pessoas reais. Exigir: teste de regressão que prove que meses SEM atribuição mantêm a nota idêntica à atual; e teste que prove o mês misto somando os dois caminhos.
- **Fixture âncora Carlos** (Phase 74 / DESEMP-01) — valor CORRIGIDO pelo RESEARCH: o teste real é `test_fixture_carlos_retorna_nota_4_08_basico` em `tests/Feature/Phase74/DesempenhoScoreServiceTest.php:388` → trava **`nota_final=4.08` + `faixa_bonus=basico`** (o 3.35/sem_bonus do brief foi SUPERADO em 2026-07-09 pelas réguas 1-5). Se quebrar, o cálculo divergiu da decisão da diretoria — investigar, NÃO "consertar o teste". **Bônus:** o fixture cria surveys via factory sem passar pelo `NpsSnapshotService` → zero atribuições → ele JÁ É a prova de regressão do caminho legado.
- **Dual-path é FALLBACK PERMANENTE, não só ponte histórica** (RESEARCH): empresas sem contrato performance ativo ficaram com `company_users.servico_id=NULL` no backfill → não geram atribuição → sempre caem no legado. Não tratar o ramo legado como código temporário.
- **Blast radius do cache (v3):** além de `PerformanceController`, o **`PortfolioController` também consome `computeCached`** (:1251, :1277) — RESEARCH; conferir todos os consumidores ao bumpar.
- Testes em `tests/Feature/V16/`.
- Cross-driver; cache bump obrigatório; `npm run build` se tocar frontend.
- Dev em paralelo (anunciar-ml) — reconciliar antes de deploy. pt-BR.
</constraints>

<canonical_refs>
## Canonical References
- **Alvo principal:** `app/Services/DesempenhoScoreService.php` — `computeNpsMedio` :281-348 (dim por cargo :287; carteira :289-291; surveys `->principal()` :301-309; `compute` :321; fallback legacy `score_*` :328-334; sem respostas → 0.0 :341-345); `compute`/`faixa_bonus` :158-238; cache `computeCached` :139-141 (chave v2).
- **Fonte nova (Fase 79):** `nps_score_assignments` (nps_response_id, nps_response_score_id, company_id, servico_id, service_setor, role[consultor|estrategista], user_id, average_score, assigned_at) + `nps_response_scores` (dimensao, score_sum, question_count, average_score) + `nps_response_covered_services`. Models: `NpsScoreAssignment`, `NpsResponseScore`, `NpsResponseCoveredService`. Escritor: `app/Services/Nps/NpsSnapshotService.php`.
- **Leitores a alinhar:** `PerformanceController` (index ranking :98-141; dashboardCarteira :298-446 — dimensão por cargo + widget respostas recentes + heatmap); `NpsController::index` :240-253 (cards por dimensão — agregação, avaliar); `DashboardController` (NPS agregado); `CalculateGoalResults` (metric nps usa dimensão fixa 'empresa' — provavelmente intocado).
- **Snapshots persistidos:** `SnapshotDesempenhoScores` (diário), `ConsolidarMesDesempenho` (mensal, fecha bônus) — gravam `desempenho_score_snapshots.score` + `breakdown_json`.
- `User::cargoDesempenhoSlug/dimensaoNpsDesempenho` :79-98 (base do caminho legado).
- Memórias: [[project_nps_modelo_principal]] (a ser superada em parte), [[project_desempenho_compute_cache]] (usar computeCached em controllers).
</canonical_refs>

<validation>
## Validation Architecture (Nyquist)
Feature tests em `tests/Feature/V16/`:
1. **Ajuste 3 (âncora):** usuário que é analista de empresa ML **e** de empresa Shopee → a média dele soma as DUAS atribuições (o ML conta). Cenário espelhando Gustavo.
2. **Shopee acende:** resposta de NPS Shopee com atribuição → entra na média da pessoa (hoje não entra).
3. **Dedup:** mesma pessoa com 2 atribuições da MESMA resposta+role (2 serviços cobertos) → conta 1×.
4. **Dual-path/regressão histórica:** mês SEM atribuições → nota IDÊNTICA à do cálculo atual (legado). Mês MISTO → soma os dois caminhos (novas via atribuição, antigas via legado).
5. **Sem respostas → 0.0** preservado.
6. **Cache:** chave bumpada p/ v3 (nota nova não vem do cache antigo).
7. **Fixture Carlos (Phase 74)** continua verde (`nota_final=3.35`, `sem_bonus`).
8. Regressão: `--filter=Desempenho`, `--filter=Performance`, `--filter=Nps`.
</validation>

<deferred>
## Deferred
- Atualizar a memória `project_nps_modelo_principal` ao fim da fase (regra "só o principal conta" superada no bônus).
- Display por-serviço no `respond()` (deferido da Fase 79) — polish.
</deferred>

---
*Phase: 80 — v16.0 (v2) — FASE CRÍTICA (bônus)*
