# Fase 131 — Itens fora de escopo descobertos durante a execução

Itens encontrados durante a execução de um plano desta fase que **não** foram
corrigidos por estarem fora do escopo da task que os descobriu (Scope
Boundary do executor: só corrigir o que a task atual causou).

---

## 1. N+1 pré-existente em `ComercialController::listagem()` — accessors de marketplace

**Descoberto em:** 131-02 Task 3 (teste de ausência de N+1 do badge de contrato).

**O quê:** `Company::getAdmanAccountIdAttribute()` e `Company::getMlStoreIdAttribute()`
(`app/Models/Company.php`) são *accessors* que rodam `$this->marketplaces()->where('marketplace',
'meli')->first()` — uma query em `company_marketplaces` CADA. O payload da listagem lê os dois
campos por empresa (`'adman_account_id' => $c->adman_account_id`, `'ml_store_id' => $c->ml_store_id`
— linhas ~406-407 de `ComercialController::listagem()`), então a resposta HTTP inteira faz **2
queries em `company_marketplaces` por empresa da página**, não 1 fixa.

**Como foi medido:** `DB::enableQueryLog()` ao redor de `GET /comercial/empresas/listagem` com 2
empresas vs. 6 empresas (mesmo teste que mede o badge de contrato) — total de queries foi 22 vs. 29
(diferença de 7, não de 0), e o log mostrou 12 ocorrências de
`select * from "company_marketplaces" where "company_marketplaces"."company_id" = ? ... limit 1`
para as 6 empresas (2 por empresa).

**Por que não foi corrigido nesta task:** é um N+1 pré-existente, no MESMO método que esta fase
edita, mas em código e comportamento que já existiam antes do plano 131-02 e não têm relação com o
badge de contrato (D-08). Corrigir estaria fora do escopo da Scope Boundary do executor — a task
só deve arrumar o que ela mesma causou.

**Consequência para o teste desta fase:** `EmpresasListagemBadgeContratoTest::test_contagem_de_queries_de_contrato_assinaturas_nao_escala_com_numero_de_empresas`
não pôde usar a contagem TOTAL de queries da resposta como o texto literal do plano 131-02 sugeria
— teria dado falso negativo por causa deste N+1 alheio. O teste filtra a contagem pelas queries que
tocam a tabela `contrato_assinaturas` (mesmo padrão de
`tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php`), o que prova exatamente o que a
D-08/T-131-02-03 exige (a montagem do badge não escala) sem depender da correção deste outro
problema.

**Sugestão de correção futura (fora desta fase):** eager-loar `marketplaces` (ou pelo menos a linha
`marketplace = 'meli'`) na query base de `Company::with([...])` de `listagem()`, e trocar os
accessors por leitura da relação já carregada em vez de nova query — mesmo padrão de N+1 já
resolvido para `contratosServico`/`consultor`/`estrategista` no mesmo método.

**Escala do problema:** ~2 queries × número de empresas ATIVAS carregadas na página atual (até 50
por página, `$perPage = 50`). Em produção, com a listagem completa de ~149 empresas paginada a 50,
isso significa até ~100 queries extras por carregamento de página — não é crítico hoje (a página já
tem outras 20+ queries fixas), mas escala linearmente e é candidato a otimização se a listagem
crescer ou se page size aumentar.
