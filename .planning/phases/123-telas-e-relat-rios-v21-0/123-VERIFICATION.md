---
phase: 123-telas-e-relat-rios-v21-0
verified: 2026-08-04T18:40:00Z
status: gaps_found
score: 5/7 truths verificadas (5 Success Criteria do ROADMAP passam literalmente; 2 achados Critical do code review — não cobertos por nenhum must_have explícito — bloqueiam o fechamento seguro da fase)
overrides_applied: 0
gaps:
  - truth: "Dados sensíveis por empresa (nome da empresa cliente, faturamento em R$, margem e nota) que a Fase 123 passou a expor em /performance/{user} são acessíveis só a quem tem autorização sobre o profissional-alvo"
    status: failed
    reason: "PerformanceController::show() é gateado só por middleware('permission:core.performance') — permissão que NÃO é admin-only (concedida também a líder de setor Performance e a setores inteiros via setor_permissoes) — e não faz nenhuma checagem adicional de admin-ou-dono. index() já declara a regra oposta ('cada um vê a sua página') e redireciona o não-admin; show() aceita qualquer {user} via route model binding. A Fase 123 acrescentou a esse payload nome de empresa cliente, faturamento_atual/anterior em R$, margem_pct_atual/anterior e nota_empresa por linha — dado financeiro de terceiro e dado de compensação. Nenhum dos 41 testes Phase123 exercita autorização (todos chamam adminLogado() antes do GET). Confirmado por leitura direta do código e da rota (routes/web.php:520-522: middleware('permission:core.performance'), sem 'role:admin')."
    artifacts:
      - path: "app/Http/Controllers/PerformanceController.php"
        issue: "show() (linha 1268) e evolucao() (routes/web.php:535-537, mesmo gate) não checam abort_unless(admin || próprio usuário)"
    missing:
      - "abort_unless($request->user()->isAdmin() || $request->user()->id === $user->id, 403, 'Você só pode ver o seu próprio desempenho.') no topo de show() — e o mesmo em evolucao()"
      - "Teste de feature cobrindo os dois lados: não-admin com core.performance vendo o próprio (200) e vendo o de outro (403)"
      - "Considerar remover faturamento_atual/faturamento_anterior/componentes_presentes do shape de CompanyScoreSnapshotReader::mapear() (WR-03 do REVIEW) — nenhum consumidor JSX os usa; reduz a superfície exposta mesmo depois do fix de autorização"
  - truth: "Na Auditoria de Bônus — tela usada para decidir invalidação de empresa para pagamento — todo número exibido é identificável quanto à sua safra (congelado no fechamento vs. recalculado ao vivo), para não induzir a leitura de erro de cálculo onde não há"
    status: failed
    reason: "BonusAuditoriaController::index() (linha 87) continua resolvendo nota_final via computeCached() — recomputo ao vivo com cache Redis — enquanto a Fase 123 passou a exibir, na mesma linha da mesma tela, nota_empresa por empresa lida de desempenho_company_score_snapshots (congelado por desempenho:consolidar-mes). Nada na tela (nem prop, nem rótulo) distingue as duas safras. .planning/learnings/desempenho-bonificacao.md §2 documenta caso real: a releitura da MESMA competência fechada, 14h depois, mudou a margem de 4,24% para 2,52% e tirou o bônus de alguém sem nenhuma mudança de código. Nesta tela, um admin vendo nota_final não bater com as notas por empresa abaixo vai concluir erro de cálculo e agir sobre isso. O mesmo padrão (fallback ao vivo ao lado de detalhe congelado) existe, em versão mais estreita, em RelatorioBonificacaoController quando o profissional não tem snapshot mensal (linha 122-125)."
    artifacts:
      - path: "app/Http/Controllers/BonusAuditoriaController.php"
        issue: "index() linha 87 usa computeCached() sem checar snapshot mensal primeiro, ao contrário de RelatorioBonificacaoController::montarLinhas() (linha 122-125) que já é snapshot-first"
    missing:
      - "Opção A (recomendada): tornar a Auditoria snapshot-first, mesmo padrão de RelatorioBonificacaoController::montarLinhas() — usar breakdown_json do snapshot mensal quando existir, computeCached() só como fallback, e expor a flag ao JSX"
      - "Opção B mínima: rotular visualmente quando nota_final vem de recomputo ao vivo (selo 'recalculada'), para o admin nunca comparar as duas safras sem saber"
---

# Fase 123: Telas e relatórios (v21.0) — Verification Report

**Phase Goal:** As telas explicam a regra nova em linguagem simples e mostram a nota de cada empresa, sem quebrar snapshots antigos.
**Verified:** 2026-08-04T18:40:00Z
**Status:** gaps_found
**Re-verification:** Não — verificação inicial

## Goal Achievement

### Observable Truths (Success Criteria do ROADMAP)

| # | Truth (ROADMAP) | Status | Evidência |
|---|---|---|---|
| 1 | A margem é rotulada e explicada em linguagem simples, sem termo não auto-explicativo | ✓ VERIFIED | `desempenhoLabels.js:159-161` (`MARGEM_CARD_TITULO='Margem'`, `MARGEM_CARD_SUBLABEL` sem `percentageMargin`) + `fraseVarMargemPp()` condicional (linhas 131-146). Wired em `Performance/Show.jsx:516-521`. Checkpoint humano aprovado nos itens B.3-B.5 contra dado real de Felipe (2026-06). |
| 2 | O detalhe do profissional lista as empresas da carteira com a nota de cada uma e seus três componentes | ✓ VERIFIED | `EmpresasScoreTabela.jsx` renderiza colunas NPS/Faturamento/Margem/Nota (linhas 194-217); wired em `Show.jsx:566-567` via `empresas_score`/`empresas_score_resumo`. `PerformanceShowEmpresasScoreTest` (8 testes, todos verdes nesta sessão). Checkpoint aprovado contra Felipe (9/30), Matheus Estrela (Shopee 15/15), Ana Julia (23/24), Renan Bassetto (34/34). |
| 3 | Snapshot antigo sem `empresas_score` renderiza no visual anterior; sem `var_margem_pp`, exibe `var_margem_pct` com rótulo legado | ✓ VERIFIED (com ressalva WR-02, ver Gaps Summary) | `RetrocompatSnapshotAntigoTest` (4 testes, todos verdes nesta sessão) prova payload pré-Fase-120 (sem `var_margem_pp`, sem `empresas_score` na raiz) renderiza sem erro e sem chave inventada. `PerformanceShowSemDetalheTest` (4 testes verdes) prova ausência de detalhe vira aviso, nunca erro/silêncio — **exceto** quando `resultado.sem_carteira === true` (ver WR-02 abaixo: a seção inteira, aviso incluso, some sem nenhum sinal). |
| 4 | Relatório de Bonificação e Auditoria de Bônus exibem `nota_empresa` lendo a mesma fonte que o ranking | ✓ VERIFIED mecanicamente / ⚠ risco não resolvido (CR-02, ver gap 2) | Confirmado por leitura de código: os três controllers (`PerformanceController`, `BonusAuditoriaController`, `RelatorioBonificacaoController`) chamam `CompanyScoreSnapshotReader::paraUsuario()`/`paraUsuarios()` — nunca reimplementam a query. `AuditoriaBonusNotaEmpresaTest` (6/6) e `RelatorioBonificacaoEmpresasTest` (6/6) verdes nesta sessão. Checkpoint: Relatório confirmado com dado real (25=25, mesmo total que a tela individual do mesmo profissional). Auditoria de Bônus **NÃO** confirmada com dado real (ver Human Verification abaixo) — só fixture sintética. A literal "mesma fonte para `nota_empresa`" se sustenta; o achado CR-02 (nota_final ao vivo vs. congelada sem rótulo) é um risco adicional, não coberto por esta truth. |
| 5 | `npm run build` rodado e checkpoint visual aprovado | ✓ VERIFIED | `npm run build` re-executado nesta sessão via `npm run test:js` (build não roda nele, mas o `123-CHECKPOINT-VISUAL.md` registra `✓ built in 31.98s`, exit 0, reconferido). Usuário aprovou explicitamente ("aprovado", 2026-08-04) os 9 itens de julgamento visual. **Ressalva registrada e não escondida no próprio checkpoint:** a verificação visual da Auditoria de Bônus com dado real foi deliberadamente adiada para pós-deploy — decisão do usuário, documentada, sustentada por cobertura automatizada (não é item silenciosamente fechado). |

**Score (Success Criteria do ROADMAP):** 5/5 passam literalmente.

**Score (verificação inclui 2 truths derivadas dos achados Critical do code review, fora do texto literal do ROADMAP mas dentro do escopo de "goal achieved" com segurança):** 5/7 — ver Gaps abaixo.

### Truths derivadas (não estão no texto literal do ROADMAP/REQUIREMENTS, mas o goal-backward exige checar se o que foi entregue é seguro de usar)

| # | Truth | Status | Evidência |
|---|---|---|---|
| 6 | Dado sensível por empresa (nome, faturamento R$, margem, nota) só é visível a quem tem autorização sobre o profissional-alvo | ✗ FAILED | `PerformanceController::show()` sem `abort_unless`; rota gateada só por `permission:core.performance` (não admin-only). Ver gap 1. |
| 7 | Na Auditoria de Bônus, nenhum número exibido pode ser confundido entre "congelado" e "recalculado ao vivo" | ✗ FAILED | `BonusAuditoriaController::index()` usa `computeCached()` para `nota_final` ao lado de `nota_empresa` congelada, sem rótulo. Ver gap 2. |

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `app/Services/Desempenho/CompanyScoreSnapshotReader.php` | Leitura pura (SELECT) — fonte única dos 3 controllers | ✓ VERIFIED | Só `SELECT` via Eloquent + `map()`; nunca instancia `CompanyScoreService`/`DesempenhoScoreService`. `leitura_nunca_dispara_chamada_http` (teste) verde. Ordenação em PHP evita divergência SQLite×MariaDB (learnings §6). Shape de 17 chaves confirmado — mas inclui `faturamento_atual`/`faturamento_anterior` que nenhum JSX consome (WR-03, agrava o gap 1). |
| `resources/js/lib/desempenhoLabels.js` | Vocabulário pt-BR + formatadores + regras puras de particionamento | ✓ VERIFIED | `MOTIVO_LABEL`, `fmtMargemAntesDepois`, `fraseVarMargemPp`, `ehPlaceholderShopee`, `dividirPorEntrada`, `carteiraTodaShopeeNaEntrada`, `deveColapsarNaoEntraram` todos presentes e exportados; usados em `EmpresasScoreTabela.jsx` e `Show.jsx`. |
| `app/Http/Controllers/PerformanceController.php` (props `empresas_score`/`tem_detalhe_empresas`/`empresas_score_resumo`) | Detalhe por empresa em competência fechada | ✓ VERIFIED (mecanicamente) / ✗ gap de autorização | `show()` (linha 1268-1360) monta o payload corretamente; wiring com `CompanyScoreSnapshotReader` confirmado. Falta o `abort_unless` (gap 1). |
| `app/Http/Controllers/BonusAuditoriaController.php` | `nota_empresa` + componentes por empresa + `tem_detalhe_empresas` | ✓ VERIFIED (mecanicamente) / ⚠ risco de vintage mismatch | `paraUsuarios()` chamado uma vez fora do `map()` (linha 77) — sem N+1. `nota_final` do profissional continua `computeCached()` (gap 2). Rota é `role:admin` (correta, ao contrário de `PerformanceController::show()`). |
| `app/Http/Controllers/RelatorioBonificacaoController.php` | `empresas_score`/`empresas_score_resumo`/`tem_detalhe_empresas` por contemplado | ✓ VERIFIED | Snapshot-first (linha 122-125), `pdf()` comprovadamente sem `empresas_score`/`nota_empresa` (D-09, confirmado por leitura da blade + teste + checkpoint com PDF real de 882KB). Rota `role:admin`. |
| `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` | Tabela de 2 seções, denominador explícito, formato antes→depois, selo Shopee | ✓ VERIFIED | Reusado por `Performance/Show.jsx`, `Auditoria.jsx` e `RelatorioBonificacao.jsx`. `min_lines: 120` — arquivo tem 273 linhas. WR-07 (resumo×linhas podem divergir se um consumidor futuro não montar as duas juntas) é risco estrutural real mas não alcançável hoje (os 3 controllers sempre montam ambas). |
| `resources/js/Pages/Performance/Show.jsx` | Card de margem sem jargão + seção "Empresas da carteira" + aviso de ausência | ✓ VERIFIED, com WR-02 (seção inteira, aviso incluso, dentro do ramo `!semCarteira` — some sem sinal se `sem_carteira && tem_detalhe_empresas`) | Ver linhas 483-583. |
| `tests/Feature/Phase123/*` (7 suítes) | Prova de UIEM-01..04 e das D-0x | ✓ VERIFIED | 41/41 passed, 482 assertions — reexecutado nesta sessão (`php artisan test --filter=Phase123`), resultado idêntico ao alegado no checkpoint. |
| `tests/js/*` (4 arquivos novos/tocados da fase) | Gates estruturais JS | ✓ VERIFIED | `npm run test:js` reexecutado nesta sessão: 124 pass / 1 fail — a única falha é `estrutura-grade-glide.test.js` (módulo MLB Grade em massa, `RECOLHIDOS_INICIAIS`), não tocado por nenhum plano da Fase 123, confirmado pré-existente. |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `CompanyScoreSnapshotReader` | `DesempenhoCompanyScoreSnapshot` | scopes `daCompetencia()`/`doUsuario()` | ✓ WIRED | Confirmado no código (linhas 39-40, 57-58). |
| `PerformanceController::show()` | `CompanyScoreSnapshotReader` | injeção no construtor + `paraUsuario()` dentro de `show()` | ✓ WIRED | Linha 1335. |
| `PerformanceController::show()` | prop Inertia `empresas_score` | `Inertia::render('Performance/Show', [...])` | ✓ WIRED | Linhas 1356-1358. |
| `BonusAuditoriaController::index()` | `CompanyScoreSnapshotReader::paraUsuarios()` | uma vez, fora do `map()` de profissionais | ✓ WIRED | Linha 77 — confirmado, sem N+1. |
| `RelatorioBonificacaoController::montarLinhas()` | `CompanyScoreSnapshotReader::paraUsuarios()` | uma vez, fora do `map()` | ✓ WIRED | Linha 115. |
| `Show.jsx` / `Auditoria.jsx` / `RelatorioBonificacao.jsx` | `EmpresasScoreTabela.jsx` | import + render | ✓ WIRED | Confirmado nos três arquivos (`Show.jsx:567`, `Auditoria.jsx:205`, `RelatorioBonificacao.jsx` grep confirma import). |
| `EmpresasScoreTabela.jsx` | `desempenhoLabels.js` | import dos formatadores/textos | ✓ WIRED | Linhas 4-10. |
| `/performance/{user}` (rota) | Gate de autorização por usuário-alvo | `abort_unless` | ✗ NOT_WIRED | Ver gap 1 — não existe checagem. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variável | Fonte | Dado real? | Status |
|---|---|---|---|---|
| `EmpresasScoreTabela` (em `Show.jsx`) | `empresas_score` | `CompanyScoreSnapshotReader::paraUsuario()` contra `desempenho_company_score_snapshots` real (MySQL local, dado puxado da VPS, 862 linhas) | Sim — reconferido nesta sessão via teste de feature + confirmado no checkpoint com 5 profissionais reais | ✓ FLOWING |
| `EmpresasScoreTabela` (em `RelatorioBonificacao.jsx`) | `empresas_score` por contemplado | mesmo reader | Sim — confirmado com dado real (25=25 entre Relatório e tela individual) | ✓ FLOWING |
| `EmpresasScoreTabela` (em `Auditoria.jsx`) | `empresas.nota_empresa` | mesmo reader | Mecanicamente sim (fixture de teste prova o caminho); **dado real de produção não confirmado neste checkpoint** — `company_users` não sincronizado localmente, decisão do usuário de não puxar | ⚠ STATIC (coberto só por fixture, não por produção local) |

### Behavioral Spot-Checks

| Behavior | Comando | Resultado | Status |
|---|---|---|---|
| Suíte de feature da fase passa | `php artisan test --filter=Phase123` (reexecutado nesta sessão) | 41 passed, 482 assertions | ✓ PASS |
| Suíte JS da fase passa (exceto baseline conhecida) | `npm run test:js` (reexecutado nesta sessão) | 124 pass / 1 fail (falha pré-existente não relacionada, `estrutura-grade-glide.test.js`) | ✓ PASS |
| Rota `/performance/{user}` é admin-only | `grep -n "performance" routes/web.php` | `middleware('permission:core.performance')` — **não** `role:admin` | ✗ FAIL (confirma gap 1) |
| Rotas Auditoria/Relatório de Bonificação são admin-only | `grep -n "role:admin" routes/web.php` (linhas 543/546/554/557) | `role:admin` presente nas 4 rotas | ✓ PASS |

### Probe Execution

Nenhum probe declarado ou convencional (`scripts/*/tests/probe-*.sh`) associado a esta fase — SKIPPED (fase de UI/relatórios, não migração/tooling).

### Requirements Coverage

| Requirement | Source Plan | Descrição | Status | Evidência |
|---|---|---|---|---|
| UIEM-01 | 123-01, 123-04 | Margem rotulada/explicada em linguagem simples | ✓ SATISFIED | `desempenhoLabels.js` + checkpoint aprovado (itens B.3-B.5) |
| UIEM-02 | 123-01, 123-02, 123-04 | Detalhe do profissional lista empresas com nota + 3 componentes | ✓ SATISFIED | `EmpresasScoreTabela.jsx` + `PerformanceShowEmpresasScoreTest` (8/8) + checkpoint (Felipe, Matheus Estrela, Ana Julia, Renan Bassetto) |
| UIEM-03 | 123-02, 123-04 | Snapshot antigo renderiza no visual anterior, rótulo legado | ✓ SATISFIED (literal) — ⚠ WR-02 relacionado não coberto | `RetrocompatSnapshotAntigoTest` (4/4) + `PerformanceShowSemDetalheTest` (4/4) |
| UIEM-04 | 123-01, 123-03, 123-05 | Relatório + Auditoria exibem `nota_empresa` da mesma fonte | ✓ SATISFIED (mecanicamente) — ✗ CR-02 (vintage mismatch) não resolvido; Auditoria sem confirmação visual com dado real | `AuditoriaBonusNotaEmpresaTest` (6/6) + `RelatorioBonificacaoEmpresasTest` (6/6); checkpoint confirma Relatório com dado real, Auditoria só fixture |

Nenhum requisito órfão: os 4 IDs de `REQUIREMENTS-v21.md` §UIEM aparecem declarados em pelo menos um `requirements:` de PLAN da fase, e a tabela de rastreabilidade (linhas 172-175 do mesmo arquivo) já marca os 4 como "Complete" — confirmado consistente com o código, com as ressalvas acima.

### Anti-Patterns Found (cruzado com `123-REVIEW.md`, achados confirmados por leitura direta de código nesta sessão)

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| `app/Http/Controllers/PerformanceController.php` | 1268 (`show()`) | Ausência de checagem de autorização por usuário-alvo (rota não é admin-only) | 🛑 BLOCKER | Confirmado — CR-01 do REVIEW. Ver gap 1. |
| `app/Http/Controllers/BonusAuditoriaController.php` | 87 | `computeCached()` ao vivo ao lado de `nota_empresa` congelada, sem rótulo de safra | 🛑 BLOCKER | Confirmado — CR-02 do REVIEW. Ver gap 2. |
| `app/Http/Controllers/PerformanceController.php` | 1234-1236 | `?mes=` sem validar mês 01-12 → overflow silencioso → 500 (`?mes=9999-99`) | ⚠️ WARNING | Confirmado por leitura — regex `\d{4}-\d{2}` aceita `00`-`99`. O próprio arquivo já tem o padrão certo em `indexPolos()` (linha 994) mas não foi reaproveitado em `resolveContextoPeriodo()`. Não bloqueia o goal da fase; é robustez de input. |
| `resources/js/Pages/Performance/Show.jsx` | 483-583 | Seção "Empresas da carteira" (tabela + aviso de ausência) inteira dentro do ramo `!semCarteira` | ⚠️ WARNING | Confirmado por leitura — se `sem_carteira===true` E `tem_detalhe_empresas===true` (profissional tinha carteira no fechamento, não tem mais hoje), a seção some sem nenhum aviso. Viola o espírito de D-03 ("nunca some silenciosamente"), embora a truth literal do ROADMAP (snapshot antigo sem chave) continue passando. |
| `app/Services/Desempenho/CompanyScoreSnapshotReader.php` | 143-144, 151 | `faturamento_atual`/`faturamento_anterior`/`componentes_presentes` no shape sem nenhum consumidor JSX | ⚠️ WARNING | Confirmado — `grep` em `resources/js` não encontra uso. Agrava o gap 1 (mais dado sensível trafegando do que o necessário). |
| `app/Http/Controllers/BonusAuditoriaController.php` | 93 | Lista de empresas vem de `CarteiraContextService::forUser()` (carteira ATUAL), pareada com nota CONGELADA | ⚠️ WARNING | Confirmado — mistura de safras na composição da lista, não só na nota agregada. Relacionado ao gap 2. |
| — | — | Nenhum `TBD`/`FIXME`/`XXX` nos arquivos tocados pela fase | ℹ️ INFO | `grep -rn -E "TBD\|FIXME\|XXX"` nos 9 arquivos principais tocados: nenhuma ocorrência. |

Achados adicionais do REVIEW (WR-05 a WR-08, IN-01 a IN-06) são reais mas não ameaçam nenhum must_have literal da fase — texto factualmente impreciso sobre "a partir de agosto/2026" (WR-05), teste sem gate negativo contra regressão de denominador (WR-08), `pdf()` monta dado que descarta (IN-02), imports órfãos (IN-04), etc. Não reproduzidos individualmente aqui; ver `123-REVIEW.md` para o detalhe completo — nenhum eleva a Critical na minha leitura independente.

### Human Verification Required

### 1. Auditoria de Bônus — conferência visual com dado real de produção

**Test:** Abrir `/desempenho/auditoria-bonus?mes=2026-06` (ou competência fechada equivalente) em produção, expandir ao menos 2 profissionais reais, e conferir que a nota por empresa exibida bate com o que a tela individual (`/performance/{id}`) do mesmo profissional mostra para as mesmas empresas.
**Expected:** Números idênticos entre as duas telas para a mesma empresa/competência; nenhuma empresa da carteira congelada ausente sem explicação; nenhuma empresa da carteira atual sem detalhe aparecendo como "erro" em vez de "—".
**Why human:** A verificação local ficou bloqueada porque `company_users` (vínculo profissional×empresa) não foi trazido da VPS — decisão explícita do usuário de não copiar mais uma tabela de produção. A cobertura hoje é só fixture sintética (`AuditoriaBonusNotaEmpresaTest`, 6 testes verdes) + analogia estrutural com o Relatório de Bonificação (que FOI confirmado com dado real). Isso é suficiente para confiar na mecânica, mas não substitui ver a tela real — o próprio time já registrou isso como dívida de verificação conhecida em `123-CHECKPOINT-VISUAL.md`, não como item silenciosamente fechado. Estou reafirmando aqui para não virar "verificado" por transitividade.

### 2. Decisão sobre CR-01 (autorização) e CR-02 (vintage mismatch) antes de deploy

**Test:** Revisar os dois achados Critical do `123-REVIEW.md` (CR-01, CR-02) e decidir: corrigir antes do deploy, ou aceitar o risco explicitamente com uma decisão registrada (override).
**Expected:** Uma das duas: (a) fix aplicado + teste de regressão, ou (b) override formal em `123-VERIFICATION.md` com justificativa e responsável, ciente de que CR-01 expõe dado financeiro de cliente e de compensação a usuários não-admin, e CR-02 pode levar a decisão errada de invalidação de bônus.
**Why human:** É uma decisão de risco de negócio/segurança (quem pode ver o quê, e como comunicar uma nota recalculada), não uma questão de sintaxe verificável por grep.

## Gaps Summary

A fase entrega, na mecânica, exatamente o que os 5 Success Criteria do ROADMAP pedem: os 41 testes `--filter=Phase123` passam (reexecutados nesta sessão, não só confiados via SUMMARY), a suíte JS está na mesma baseline conhecida, o `CompanyScoreSnapshotReader` é comprovadamente leitura pura e única fonte para os três controllers, a ordenação é estável entre SQLite e MariaDB, o formato de margem sem jargão está implementado e foi aprovado pelo usuário contra dado real de 2026-06, e o detalhe por empresa (nota + 3 componentes) está corretamente lido e renderizado nas três telas.

O que bloqueia o status `passed` não é nenhuma das quatro truths UIEM-01..04 no sentido literal — é o que a fase **acrescentou** ao redor delas, confirmado por leitura direta de código nesta verificação (não apenas relatado pelo REVIEW):

1. **CR-01 (autorização) — tratado aqui como gap de fase, não como dívida pré-existente isolada.** É verdade que `show()` já vazava `nota_final`/`faixa_bonus` antes da Fase 123 sem checagem — isso é dívida pré-existente. Mas a fase colocou, no MESMO endpoint sem controle de acesso adicional, nome de empresa cliente, faturamento em R$ e margem por empresa — dado qualitativamente mais sensível (financeiro de terceiro) do que o que já vazava. A rota (`permission:core.performance`) é estruturalmente diferente das rotas de Auditoria/Relatório de Bonificação, que a mesma fase manteve corretamente em `role:admin`. Ampliar a superfície de dado sensível de um endpoint já sabidamente sem controle de acesso, sem adicionar o controle, é uma falha de "wiring seguro" do próprio entregável desta fase — por isso entra como gap, não como nota de rodapé.
2. **CR-02 (nota ao vivo vs. congelada sem rótulo) — também tratado como gap.** O padrão `computeCached()` em si é anterior à fase, mas colocar `nota_empresa` CONGELADA ao lado dele, na mesma linha da Auditoria de Bônus, sem nenhum sinal de safra, é uma decisão de composição desta fase — e o domínio (bonificação, com precedente real de perda de bônus por recompute) torna esse tipo de ambiguidade visual particularmente perigoso.

Nenhum dos dois gaps invalida a mecânica de leitura, a ordenação, o vocabulário pt-BR ou a renderização — a "engenharia" da fase está sólida e bem testada. O que falta é fechar a superfície de exposição/confusão que a fase abriu ao redor de dado sensível, antes de considerar o objetivo "mostrar a nota de cada empresa" cumprido com segurança suficiente para produção.

**Achado à parte, não bloqueador:** a verificação visual da Auditoria de Bônus com dado real de produção segue pendente (adiada para pós-deploy por decisão explícita e documentada do usuário) — não é tratada como gap porque a decisão de adiar já foi tomada conscientemente com cobertura automatizada como base, mas está listada em Human Verification para não desaparecer da vista de quem for decidir o deploy.

---

_Verified: 2026-08-04T18:40:00Z_
_Verifier: Claude (gsd-verifier)_
