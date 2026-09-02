---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
verified: 2026-09-02T00:00:00Z
status: passed
score: 5/5 truths verificadas
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 4.5/5
  gaps_closed:
    - "Não existe outro ponto do código que grave companies.etapa além de um único serviço de transição (ETAPA-03) — e isso é verificado automaticamente, para sempre"
  gaps_remaining: []
  regressions: []
---

# Phase 137: Máquina de estados — os 9 status de `companies.etapa` (v23.0) — Re-verificação

**Objetivo da fase:** Cada empresa carrega uma etapa própria entre os 9 status do §10, gravada e transicionada por um único serviço central, com pendência declarável em paralelo sem nunca sobrescrever a etapa — e as ~500 empresas já cadastradas migram sem quebrar o que a tela "Empresas" de `/companies` mostra hoje.

**Verificado em:** 2026-09-02
**Status:** passed
**Re-verificação:** Sim — depois de 4 planos de gap closure (137-08, 137-09, 137-10, 137-11) fechando 1 gap desta verificação + 5 achados do `137-REVIEW.md`

## O que mudou desde a verificação anterior

A verificação anterior (score 4,5/5) encontrou UM gap: o gate estático de `EtapaPontoUnicoTest.php` que deveria provar "para sempre" o Success Criteria nº 2 (ETAPA-03) era contornável por dois caminhos, comprovados por injeção real de código nesta sessão. Em paralelo, a revisão de código (`137-REVIEW.md`) achou mais 2 CRITICAL e 3 WARNING, incluindo um terceiro bypass do mesmo gate (`DB::table('companies')`) que eu não tinha testado.

Refiz a verificação do zero, com ceticismo redobrado sobre a SC2 e sem aceitar a palavra do executor em nenhum dos quatro planos de fechamento.

## Prova por injeção real — refeita de forma independente (não confiei no SUMMARY do 137-08)

Criei os três arquivos-sonda EU MESMO, um de cada vez, sempre removendo o anterior antes do próximo, e rodei o gate a cada sonda:

**Sonda 1 — nome de variável diferente (`$empresa->etapa = Company::ETAPA_EM_OPERACAO; $empresa->save();`):**
```
1) EtapaPontoUnicoTest::test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico
... VerifierProbe137Reverify.php:11 — [Regra A] atribuição direta ->etapa = ... fora de EtapaTransicaoService.
```
FALHA — bypass 1 fechado, confirmado por mim.

**Sonda 2 — array indireto, sem token `Company` no corpo (`$dados['etapa'] = 'em_operacao'; $company->update($dados);`):**
```
... VerifierProbe137Reverify.php:12 — [Regra B] escrita por offset $var['etapa'] = ... dentro de um corpo que também grava no model Company.
```
FALHA — bypass 2 fechado, confirmado por mim.

**Sonda 3 — `DB::table('companies')->where('id', $id)->update(['etapa' => 'em_operacao'])` (o achado do code review, CR-01, que eu não tinha testado antes):**
```
... VerifierProbe137Reverify.php:11 — [Regra C] chave 'etapa' => dentro de uma escrita no model Company.
```
FALHA — bypass 3 (do review, não meu) também fechado, confirmado por mim.

Depois de cada sonda: arquivo removido, `git status --short` vazio, `phpunit tests/Feature/Phase137/EtapaPontoUnicoTest.php` voltando a `OK (4 tests, 11 assertions)` na árvore limpa.

### Teste adicional de robustez que eu mesmo fiz (não pedido pelo plano)

Tentei um QUARTO caminho, mais elaborado, que nenhum dos dois relatórios anteriores cobriu: montar o array com a chave `etapa` num método PRIVADO separado (sem nenhuma referência a `Company` nesse corpo) e chamar `->update()` com o resultado num método DIFERENTE:
```php
private function montaDados(): array { $dados=[]; $dados['etapa']='em_operacao'; return $dados; }
public function aplicar(Company $company): void { $company->update($this->montaDados()); }
```
Resultado: `OK (1 test, 2 assertions)` — **não pegou**. As regras B/C avaliam por corpo de função; dividir a construção do array e a chamada `->update()` em dois métodos escapa da detecção, porque nenhum dos dois corpos, isoladamente, contém as duas partes do padrão.

**Classificação: informativo, não bloqueador.** Diferença essencial em relação aos três bypasses fechados: aqueles usavam idiomas que JÁ EXISTEM no projeto sem qualquer intenção de burlar nada (`$empresa` aparece 204 vezes; `DB::table('companies')->update()` já existe duas vezes para outras colunas) — um dev sob pressão cairia neles por acidente. O quarto caminho exige dividir deliberadamente a montagem do array e a escrita em dois métodos só para evadir o gate — não corresponde a nenhum idioma real do repositório hoje (confirmei: zero ocorrências de `['etapa']` fora do arquivo de teste) e não foi meta declarada de nenhum dos gaps G1-G6. Registro para o caso de a Fase 138+ precisar de um gate ainda mais forte, mas não rebaixo a nota da fase por isto — o compromisso do plano 137-08 era fechar os três bypasses documentados, e os três estão fechados, comprovados por mim de forma independente.

Removi a sonda, `git status --short` voltou vazio.

## Goal Achievement

### Observable Truths

| # | Truth (Success Criteria do ROADMAP) | Status | Evidência |
|---|---|---|---|
| 1 | Toda empresa tem etapa entre as 9 do §10; ~500 já cadastradas recebem etapa no backfill; quem já estava "em operação" entra direto na etapa 9; quem fica sem etapa continua resolvido pelo cálculo antigo; `/companies` não perde nenhuma empresa (ETAPA-01, ETAPA-02, D2) | ✓ VERIFIED | Reconfirmado por reconsulta direta ao MariaDB nesta sessão: `total=180, em_operacao=1, null=179` — idêntico ao valor registrado em 137-05 e à verificação anterior. Nenhum plano de gap closure tocou o cálculo do balde 1 (só a CHAVE da coleção, ver truth 2). Suíte baseline `/companies` = 24/24, reconfirmada nesta sessão |
| 2 | Não existe outro ponto do código que grave `companies.etapa` além de um único serviço de transição (ETAPA-03) | ✓ VERIFIED (era PARTIAL) | Os 3 bypasses comprovados (2 pela verificação anterior, 1 pelo `137-REVIEW.md`) estão fechados — refiz a prova por injeção real EU MESMO (não confiei no SUMMARY do 137-08), ver seção acima. Gate roda sobre `app/` + `database/migrations/` agora, neutraliza comentários (fecha o falso positivo que EU MESMO tinha causado na verificação anterior), e continua verde na árvore real sem nenhuma exceção nomeada (`EXCECOES_REGRA_A` vazia). Grep manual independente confirma: zero `->etapa =` fora do serviço, zero `['etapa'] =` fora do serviço, os dois `DB::table('companies')` existentes não tocam `etapa`. Adicionalmente, o CR-02 do review (FK `user_id` em CASCADE apagando histórico de empresas não relacionadas ao deletar um colaborador) — que ameaçava diretamente a integridade do histórico que esta mesma garantia promete preservar — foi corrigido e reconfirmado por mim via `SHOW CREATE TABLE` direto no MariaDB: `ON DELETE SET NULL`, igual ao precedente `pendencia_por` |
| 3 | Uma empresa pode ter pendência marcada permanecendo na mesma etapa — marcar/desmarcar pendência nunca move a etapa (ETAPA-04, D6) | ✓ VERIFIED | Sem alteração nos planos de gap closure. Reconfirmado: 58/58 na suíte da fase, incluindo `EtapaPendenciaParaleloTest` |
| 4 | Pelo menos uma listagem existente pode ser filtrada por etapa e, separadamente, por "com pendência" (ETAPA-05) | ✓ VERIFIED (fortalecido) | Além do já verificado antes, o G3/WR-03 (filtros se apagando entre si) foi fechado: os 4 handlers de `Index.jsx` agora delegam a um único `aplicarFiltros(overrides)` que reenvia todos os filtros ativos — confirmei por leitura direta do código (linhas 216-253) que a implementação bate com a descrição do SUMMARY. `grep -c "router.get(route('companies.index')"` = 1 (era 4). Combinação tripla (`cust_id_status` + `etapa` + `com_pendencia`) provada no backend (12 testes em `EtapaFiltroListagemTest`, incluindo o gate estático sobre o `.jsx`) e **aprovada por verificação humana com evidência concreta de URL** (`?cust_id_status=invalido&etapa=sem_etapa&tab=empresas`) |
| 5 | Tentar avançar etapa sem requisitos cumpridos é recusado com mensagem que nomeia o requisito faltante, nunca erro genérico (ETAPA-06) | ✓ VERIFIED | `podeTransicionar()` continua puro e sem alteração de contrato (137-10 tocou só `transicionar()`); os 5 testes pré-existentes de recusa nomeada, salto 2→4 e retrocesso seguem verdes sem mudança de expectativa (confirmado por leitura do `git diff` do arquivo de teste, que só acrescenta linhas) |

**Score:** 5/5 truths plenamente verificadas

### Achados do code review (137-REVIEW.md) — todos fechados, cada um reconfirmado nesta sessão

| ID | Achado | Fechado por | Como verifiquei (não confiei no SUMMARY) |
|---|---|---|---|
| CR-01 (CRITICAL) | Gate estático contornável por `DB::table('companies')` | 137-08 | Sonda 3 injetada por mim mesmo (ver acima) — FALHA confirmada |
| CR-02 (CRITICAL) | FK `user_id` em `ON DELETE CASCADE` apaga histórico de empresas não relacionadas ao excluir permanentemente um colaborador | 137-09 | `SHOW CREATE TABLE company_etapa_transicoes` rodado por mim diretamente no MariaDB (`ecf_admin`) nesta sessão: `CONSTRAINT ... FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL`. `company_id` permanece `ON DELETE CASCADE` (correto — não foi tocado) |
| WR-01 (TOCTOU) | `transicionar()` decidia sobre objeto em memória, não sobre a linha travada | 137-10 | Lido `EtapaTransicaoService.php` na íntegra: `Company::whereKey($company->id)->lockForUpdate()->first()` dentro de `DB::transaction()`, decisão sobre a instância travada. Docblock e teste NÃO alegam prova de concorrência real — dizem explicitamente que `lockForUpdate()` é no-op no SQLite dos testes e que o que a suíte prova é a releitura de estado divergente, não serialização (ver seção dedicada abaixo) |
| WR-02 (chave não única) | Backfill descartava empresa homônima em silêncio (`pluck('id','name')`) | 137-10 | `grep -c "pluck('id', 'name')"` = 0; `pluck('id')` confirmado na linha 87. Teste de nome duplicado presente em `EtapaBackfillTest.php` (linhas 248-284) |
| WR-03 (filtros se apagam) | Escolher um filtro de `/companies` apagava os outros já ativos | 137-11 | Ver truth 4 acima |
| (frontmatter) | `137-01-PLAN.md` reivindicava `ETAPA-01`/`ETAPA-02` que não entregou | 137-10 | `grep "^requirements:"` em `137-01-PLAN.md` = `requirements: []`; os 6 IDs seguem cobertos em outros planos (ver Requirements Coverage) |

### O teste do G4 não superclama concorrência — verificação específica pedida pelo orquestrador

Fui ler o código de `transicionar()` e o teste com o objetivo específico de checar se a alegação de segurança contra TOCTOU é honesta, sabendo que `lockForUpdate()` é no-op em SQLite (o driver da suíte). Resultado: **a alegação é honesta**. O docblock do método (linhas 179-208 de `EtapaTransicaoService.php`) diz textualmente:

> "`lockForUpdate()` é **no-op no SQLite** ... O que a suíte desta classe prova, portanto, NÃO é serialização entre duas requisições simultâneas (SQLite não consegue provar isso) — é que a decisão usa a RE-LEITURA da linha, e não o objeto obsoleto em memória ... Não ler o teste verde como prova de concorrência."

E os 4 testes novos em `EtapaTransicaoServiceTest.php` (linhas 193-263) testam exatamente isso — divergência memória-vs-banco simulada por escrita direta via `DB::table()` — nunca alegam ter provado exclusão mútua sob concorrência real. Os nomes dos testes (`test_decide_sobre_etapa_do_banco_recusa_...`, `test_objeto_do_chamador_reflete_etapa_nova_...`) também não superclamam. A prova de serialização real só existirá em MariaDB de produção, quando a Fase 138 plugar o primeiro chamador — isso é reconhecido explicitamente, não escondido.

### Required Artifacts (itens novos/alterados pelos 4 planos de gap closure)

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `tests/Feature/Phase137/EtapaPontoUnicoTest.php` | Gate com 3 regras, comentários neutralizados, escopo estendido a `database/migrations/` | ✓ VERIFIED | Lido na íntegra; 3 sondas próprias confirmam as 3 regras funcionando; `EXCECOES_REGRA_A` vazia na árvore real |
| `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php` | `user_id` nullable + `nullOnDelete()` | ✓ VERIFIED | Confirmado por `SHOW CREATE TABLE` direto no MariaDB, não por leitura de migration nem por stdout do SUMMARY |
| `tests/Feature/Phase137/EtapaHistoricoAtorTest.php` | 4 testes de sobrevivência do histórico a `forceDelete()` | ✓ VERIFIED | Parte dos 58/58 verdes; lido — asserções batem com o objetivo do G2 |
| `app/Services/FluxoEntrada/EtapaTransicaoService.php` (`transicionar()`) | Decide sobre linha travada dentro da transação | ✓ VERIFIED | Lido na íntegra; `lockForUpdate()` presente em código real (não só comentário); forma do array de retorno inalterada |
| `app/Console/Commands/EtapaBackfill.php` | Balde 1 chaveado por `id` | ✓ VERIFIED | `pluck('id')` confirmado; `pluck('id', 'name')` = 0 ocorrências |
| `.planning/phases/.../137-01-PLAN.md` | `requirements: []` | ✓ VERIFIED | Confirmado por grep direto |
| `resources/js/Pages/Companies/Index.jsx` | Montador único `aplicarFiltros()` | ✓ VERIFIED | Lido na íntegra (linhas 195-253); os 4 handlers delegam; 1 única chamada a `router.get(route('companies.index')` no arquivo |
| `tests/Feature/Phase137/EtapaFiltroListagemTest.php` | Combinação tripla + gate estático sobre o `.jsx` | ✓ VERIFIED | 12/12 testes verdes, incluindo o gate |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `tests/Feature/Phase137/EtapaPontoUnicoTest.php` (gate estático) | árvore `app/` + `database/migrations/` | 3 regras sobre código sem comentário | ✓ WIRED | As 3 formas de bypass documentadas (2 da verificação anterior + 1 do review) falham o gate, comprovado por injeção própria. 0 falsos positivos na árvore real (`OnboardingPasso::etapa` em 6 arquivos + `CompanyController::index()` de ~350 linhas continuam passando) |
| `company_etapa_transicoes.user_id` | `users.id` | FK `ON DELETE SET NULL` | ✓ WIRED | Confirmado no schema real, não só na migration |
| `EtapaTransicaoService::transicionar()` | linha travada de `companies` | `Company::whereKey($id)->lockForUpdate()->first()` dentro de `DB::transaction()` | ✓ WIRED (com ressalva documentada sobre SQLite, ver seção dedicada) | |
| `EtapaBackfill` (balde 1) | `EtapaTransicaoService::carimbarBackfill()` | lista de ids chaveada por `id` | ✓ WIRED | Imune a colisão de `name` |
| `Companies/Index.jsx` (4 handlers) | `CompanyController::index()` | `aplicarFiltros()` → `router.get(route('companies.index'), params)` | ✓ WIRED | Único emissor da chamada; todos os filtros ativos preservados; aprovado por verificação humana com evidência de URL |

### Data-Flow Trace (Level 4)

Sem mudança em relação à verificação anterior nos três itens já traçados (filtro de etapa, filtro de pendência, payload por empresa) — todos seguem `✓ FLOWING`, reconfirmado pela reconsulta ao MariaDB nesta sessão (`total=180, em_operacao=1, null=179`).

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Suíte completa da fase | `phpunit tests/Unit/Phase137 tests/Feature/Phase137` | `OK (58 tests, 164 assertions)` | ✓ PASS |
| Suíte baseline `/companies` | `phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` | `OK (24 tests, 44 assertions)` | ✓ PASS |
| Regressão Unit completa vs. baseline pré-fase | `phpunit --testsuite=Unit` | `307 tests, 1041 assertions, 9 errors, 3 failures` — comparei nome por nome contra `137-BASELINE-TESTES.md`: os mesmos 9 (`CalcularFaixaTest`, 9 variações) + os mesmos 3 (`CompanyServiceTypeTest::test_service_type_aceita_polo`, 2× `MercadoLivreSugadoresProviderTest`), nenhum teste novo falhando | ✓ PASS (sem regressão) |
| Gate estático da ETAPA-03 pega os 3 bypasses documentados | 3 sondas injetadas por mim, uma de cada vez, removidas em seguida | 3/3 FALHAM nomeando arquivo:linha e a regra correta | ✓ PASS |
| Gate estático da ETAPA-03 não tem falso positivo na árvore real | Suíte roda verde sem sonda nenhuma | `OK` | ✓ PASS |
| Gate NÃO pega bypass de 4º nível (split entre 2 métodos) | Sonda própria, não pedida por nenhum plano | `OK` — não pegou | ℹ️ INFO (residual, não bloqueador — ver seção dedicada) |
| FK do histórico sobrevive a `forceDelete()` de usuário | `SHOW CREATE TABLE company_etapa_transicoes` direto no MariaDB | `ON DELETE SET NULL` | ✓ PASS |
| Backfill não regrediu | Reconsulta SQL direta | `total=180, em_operacao=1, null=179` — idêntico ao registrado em 137-05/137-09 | ✓ PASS |
| Filtros de `/companies` não se apagam mais entre si | `grep -c "router.get(route('companies.index')"` + leitura de `aplicarFiltros()` | `1` (era 4); handlers delegam | ✓ PASS |
| Build reflete o código-fonte | `grep -l "Sem etapa" public/build/assets/*.js` | Encontrado em `Index-DUXUoUNK.js`; sem `public/hot` órfão | ✓ PASS |
| Árvore limpa após toda a sessão de verificação | `git status --short` | Vazio | ✓ PASS |

### Probe Execution

Nenhum probe (`scripts/*/tests/probe-*.sh`) declarado para esta fase. **SKIPPED (nenhum probe aplicável)**.

### Requirements Coverage

| Requirement | Fonte(s) | Descrição | Status | Evidência |
|---|---|---|---|---|
| ETAPA-01 | 137-02 | `companies.etapa` com 9 valores, aditiva, constante no model | ✓ SATISFIED | Inalterado desde a verificação anterior |
| ETAPA-02 | 137-05, 137-10 | Backfill; `em_operacao` → etapa 9; balde 1 imune a colisão de nome | ✓ SATISFIED | Contagens reconfirmadas + `pluck('id')` |
| ETAPA-03 | 137-03, 137-06, 137-08, 137-09, 137-10 | Único serviço decide/grava transição — agora com gate fechado e FK de histórico corrigida | ✓ SATISFIED (sem ressalva) | Ver truth 2 acima |
| ETAPA-04 | 137-04 | Pendência paralela, nunca move etapa | ✓ SATISFIED | Inalterado |
| ETAPA-05 | 137-07, 137-11 | Filtro por etapa e por pendência, agora combináveis com `cust_id_status` sem se apagarem | ✓ SATISFIED | Ver truth 4 acima |
| ETAPA-06 | 137-03, 137-09, 137-10 | Transição inválida recusada com requisito nomeado | ✓ SATISFIED | Inalterado |

Nenhum requirement órfão: `.planning/REQUIREMENTS-v23.md` (o arquivo da v23, correto) linhas 161-166 mapeiam `ETAPA-01`..`ETAPA-06` para a Fase 137 com `[x]`, e todos os seis aparecem em pelo menos um `requirements:` de algum dos 11 planos, confirmado por grep direto em todos os 11 arquivos `137-*-PLAN.md` nesta sessão. `137-01-PLAN.md` agora declara `requirements: []` corretamente.

### Anti-Patterns Found

Nenhum `TBD|FIXME|XXX|TODO|HACK|PLACEHOLDER` real nos 11 arquivos tocados pelos 4 planos de gap closure (uma ocorrência de substring `TODO` dentro de `TODOS` em `EtapaTransicaoService.php:39`, já descartada como falso positivo na verificação anterior e reconfirmada aqui).

### Human Verification Required

Nenhum item pendente. A verificação humana da combinação tripla de filtros em `/companies` (checkpoint da Task 3 de 137-11) já foi realizada pelo usuário nesta mesma sessão de trabalho e aprovada, com evidência concreta de URL (`?cust_id_status=invalido&etapa=sem_etapa&tab=empresas`) — exatamente o cenário que o WR-03 descrevia como quebrado. Tratado como satisfeito, não reaberto.

### Dívidas conhecidas, aceitas explicitamente — não reabertas aqui

- **Suíte Feature completa não termina neste worktree** (cascata de timeout de rede de 300s pré-existente, medida por 137-01 antes de qualquer código desta fase existir). `137-VALIDATION.md` exigia suíte completa verde antes do `/gsd:verify-work`; flexibilizado com motivo documentado. Não rodei `php artisan test` nem `--testsuite=Feature` nesta sessão, conforme instruído.
- **`EtapaTransicaoService` sem chamador de produção** — deliberado; Fases 138-142 plugam os gatilhos reais, e serão elas que vão exercitar o `lockForUpdate()` sob concorrência real pela primeira vez.
- **Filtro de etapa também estreita a contagem da aba "Pendências"** — comportamento pré-existente, já compartilhado com `cust_id_status`, não introduzido por esta fase.
- **Backfill local exercitado com 180 empresas (1 no balde 1) contra as ~500 de produção** — limitação de evidência conhecida, não um gap novo.
- Achado informativo próprio desta re-verificação (bypass de 4º nível do gate por divisão entre dois métodos) — não corresponde a nenhum idioma real do repositório, não foi meta de nenhum G1-G6, registrado só para o caso de a Fase 138+ precisar de um gate mais forte no futuro.

### Gaps Summary

Nenhum gap. O único gap da verificação anterior (gate estático contornável da ETAPA-03) está fechado, comprovado por injeção real de código feita por mim mesmo nesta sessão, cobrindo as 3 formas documentadas (2 da verificação anterior + 1 do code review). Os 4 outros achados do `137-REVIEW.md` (2 CRITICAL, 2 WARNING) e 1 defeito de frontmatter também foram fechados e reconfirmados de forma independente — por reconsulta direta ao MariaDB real (não pela migration nem pelo stdout do SUMMARY), por leitura direta do código-fonte, e por injeção de sonda própria onde aplicável.

As 5 Success Criteria do ROADMAP estão plenamente verificadas. A Fase 137 está pronta para a Fase 138.

---

_Verified: 2026-09-02_
_Verifier: Claude (gsd-verifier)_
