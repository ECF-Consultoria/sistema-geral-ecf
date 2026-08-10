---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 03
subsystem: api
tags: [laravel, php, node-test, mercadolivre, phase134, nota-ecf, regra-de-negocio]

# Dependency graph
requires:
  - phase: 134-01
    provides: "Fixtures reais da API do ML (multiget-lote.json, multiget-item-com-variacoes.json) e veredito D-21"
  - phase: 134-02
    provides: "MlAcervoItem com as constantes MOTIVO_*/SEVERIDADE_* e as colunas nota_ecf/nota_sinais/motivos/severidade"
provides:
  - "AnuncioSaudeService::avaliar() — nota ECF em base 86, 7 sinais computáveis na camada barata, soma direta sem clamp"
  - "AnuncioSaudeService::triagem() — motivos e severidade a partir das constantes de MlAcervoItem, respeitando D-18 (buy box não avaliado nunca vira motivo)"
  - "Fixture compartilhado tests/fixtures/phase134/nota-ecf-casos.json (6 casos), consumido pelos dois lados"
  - "Guarda de regressão dupla (PHPUnit + node --test) provando que a nota fecha com a própria conta e que os dois scorers concordam"
affects: [134-04, 134-05, 134-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Serviço de transformação pura (sem HTTP/Eloquent/DB/Cache) — torna o teste de concordância PHP×JS possível sem Http::fake()"
    - "Asserção defensiva no construtor lógico do serviço (array_sum(PESOS) !== BASE lança LogicException) — trava alteração de peso sem decisão explícita"
    - "Teste de concordância cross-linguagem sobre fixture JSON compartilhado, lido por PHPUnit e por node --test"
    - "Gate de fonte (lerSemComentarios + contagem de add(...)) trava os 8 pesos originais do wizard sem editar o arquivo fonte"

key-files:
  created:
    - app/Services/Mlb/Acervo/AnuncioSaudeService.php
    - tests/fixtures/phase134/nota-ecf-casos.json
    - tests/Unit/Phase134/NotaEcfFecharComContaTest.php
    - tests/js/notaEcfConcordancia.test.js
    - .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/deferred-items.md
  modified: []

key-decisions:
  - "Asserção defensiva array_sum(PESOS) !== BASE lança LogicException — não estava explicitamente pedida como exceção de runtime no texto do plano, mas o plano pedia 'uma asserção defensiva' e LogicException é o tipo idiomático do projeto para violação de invariante interno (nunca deveria acontecer em produção, só em erro de programação)."
  - "Data provider do teste PHP calcula o caminho do fixture via dirname(__DIR__, 2) em vez de base_path() — base_path() ainda não existe quando o PHPUnit resolve data providers estáticos, antes de createApplication() rodar (erro 'Call to undefined method Container::basePath()' na primeira tentativa)."
  - "Os 6 casos do fixture foram construídos do zero (exceto o caso 3, payload real MLB5512320238 truncado) em vez de reaproveitar literalmente o exemplo do bloco <interfaces> do PLAN.md, que é ilustrativo e truncado — os valores de nota_ecf_86/score_wizard_100 de cada caso foram derivados a mão e depois confirmados rodando o AnuncioSaudeService real e o analisarAnuncio() real contra o fixture, antes de escrever os testes formais."
  - "PACKAGE_WEIGHT/LENGTH/WIDTH/HEIGHT (sem prefixo SELLER_) usados literalmente como pede o PLAN.md/RESEARCH.md — confirmado que aparecem no payload real (multiget-lote.json, itens de categoria MLB439421/MLB186151), coexistindo com SELLER_PACKAGE_* (o que o próprio wizard grava ao publicar). Os dois são atributos ML distintos; este plano não normaliza um a partir do outro."

requirements-completed: [D-09, D-10, D-12, D-18, D-22]

# Metrics
duration: ~50min
completed: 2026-08-10
---

# Phase 134 Plan 03: Nota ECF em PHP (base 86) + guarda de concordância PHP×JS Summary

**Port PHP de `calcularScore()` em base 86 explícita (`AnuncioSaudeService`), com fixture compartilhado de 6 casos e teste de concordância duplo (PHPUnit + `node --test`) provando `score_wizard_100 − 14×descricao_ok === nota_ecf_86` em todos eles.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-10T19:10Z (aprox.)
- **Completed:** 2026-08-10T20:00Z
- **Tasks:** 3/3
- **Files modified:** 5 (4 criados pelas tasks + 1 doc de item fora de escopo)

## Accomplishments

- `AnuncioSaudeService` (classe `final`, sem HTTP/Eloquent/DB/Cache) calcula os 7 sinais computáveis na camada barata do multiget — título, categoria, ficha obrigatória, ficha opcional, foto (com bifurcação variação×item simples), dimensões (extração numérica ignorando sufixo de unidade) e preço — somando os pesos direto, sem `min()`/clamp.
- Asserção defensiva (`array_sum(PESOS) !== BASE` → `LogicException`) provada manualmente: peso de `foto` alterado de 16→17 sem tocar `BASE` derrubou 7 dos 8 testes PHP da suíte (a exceção + a asserção direta `pesos_somam_a_base_declarada`); revertido, suíte voltou a verde.
- `triagem()` deriva motivos/severidade das constantes de `MlAcervoItem`, com o teste `buybox_nao_avaliado_nunca_vira_motivo` confirmando D-18: item de catálogo com `buyboxStatus` nulo nunca ganha `MOTIVO_PERDENDO_CATALOGO`, mesmo com `catalog_listing=true`.
- Fixture `tests/fixtures/phase134/nota-ecf-casos.json` com 6 casos cobrindo as fronteiras pedidas: item completo (nota 86), sem foto + ficha incompleta, variações com foto só em `picture_ids` (payload real `MLB5512320238` truncado), categoria sem opcionais (`opcPct=100` por definição), fronteira exata de 60% dos opcionais, e nota mínima (0, sem piso implícito). Cada `wizard` é derivado do `item_ml` do mesmo caso — nenhum `titulo`/`categoryId` diverge entre os dois blocos.
- Todos os 6 casos foram conferidos duas vezes antes de virar teste formal: uma vez rodando `AnuncioSaudeService::avaliar()` real via tinker, outra rodando `analisarAnuncio()` real via node — os dois batem exatamente com os valores hand-derived do fixture.
- `NotaEcfFecharComContaTest` (PHPUnit, `@group phase134`) e `notaEcfConcordancia.test.js` (`node --test`) leem o MESMO arquivo JSON e travam: (1) a nota bate com o esperado, (2) a soma do breakdown de sinais fecha exatamente com a nota, (3) o invariante `score_wizard − 14×descricao = nota_86`, (4) faixa `[0,86]`.
- Gate do lado JS (`pesos_do_wizard_nao_mudaram`) lê a fonte de `mlAnuncioRegras.js` sem comentários e trava os 8 pesos originais (`[4,8,12,12,14,14,16,20]`) por contagem, não por presença — captura tanto mudança de valor quanto remoção de um `add(...)`. Provado manualmente: peso de foto alterado de 16→17 derrubou 4 dos 7 testes JS da suíte nova; revertido — `git diff` confirmou `mlAnuncioRegras.js` byte-a-byte idêntico ao original (D-16 preservado).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: AnuncioSaudeService — nota em base 86 e triagem** - `a242c762` (feat)
2. **Task 2: Fixture compartilhado + teste PHP de "fecha com a própria conta"** - `f9d2fb43` (test)
3. **Task 3: Metade JS da guarda de concordância** - `fa82988b` (test)

**Plan metadata:** commit a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `app/Services/Mlb/Acervo/AnuncioSaudeService.php` - port PHP puro de `calcularScore()`, base 86, `avaliar()` + `triagem()`
- `tests/fixtures/phase134/nota-ecf-casos.json` - fixture compartilhado, 6 casos, consumido pelos dois lados
- `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` - guarda PHP: soma fecha com a nota + invariante de concordância + D-18
- `tests/js/notaEcfConcordancia.test.js` - guarda JS: mesmo fixture, mesmo invariante, mais gate de fonte dos 8 pesos
- `.planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/deferred-items.md` - registro do item fora de escopo (ver Deviations)

## Decisions Made

- **Asserção defensiva com `LogicException`** em vez de outro mecanismo de erro — segue o padrão do projeto de exceções tipadas explícitas em vez de retorno silencioso de valor inconsistente.
- **Caminho do fixture no data provider PHP calculado via `dirname(__DIR__, 2)`**, não `base_path()` — o helper do container ainda não existe quando o PHPUnit resolve providers estáticos, antes de `createApplication()`.
- **`PACKAGE_WEIGHT/LENGTH/WIDTH/HEIGHT` (sem `SELLER_` prefix)** usados literalmente conforme o texto do `134-03-PLAN.md` e do `134-RESEARCH.md` §5 — confirmados no payload real (`multiget-lote.json`, itens de categoria `MLB439421`/`MLB186151` trazem ambos os pares de atributo). Este plano não mistura os dois nem normaliza um a partir do outro.
- **Os 6 casos do fixture foram construídos e conferidos programaticamente** (não copiados do exemplo ilustrativo do bloco `<interfaces>`, que é truncado e não teria fechado a conta) — cada valor de `nota_ecf_86`/`score_wizard_100` foi validado rodando o serviço PHP real e a função JS real contra o fixture antes de formalizar os testes.

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio via Regras 1-3 durante a implementação — o plano foi seguido como escrito nas 3 tasks.

### Item fora de escopo registrado (não corrigido)

**1. `tests/js/estrutura-grade-glide.test.js` falhando — pré-existente, não é regressão desta execução**
- **Encontrado durante:** Task 3, ao rodar `npm run test:js` completo (não só o teste novo)
- **Sintoma:** o teste espera `RECOLHIDOS_INICIAIS = [G_SECUND]` na fonte de `GradeAnuncioGlide.jsx`, mas a fonte atual tem `RECOLHIDOS_INICIAIS = []`
- **Confirmação de que não é desta execução:** reproduzido isolando o arquivo novo desta task com `git stash push --include-untracked -- tests/js/notaEcfConcordancia.test.js`, rodando o teste de grade isoladamente (mesma falha, sem o arquivo novo no working tree) e restaurando com `git stash pop` em seguida
- **Não corrigido** — fora do escopo do Plano 03 (módulo de grade em massa, Fase 87); nenhum arquivo desse módulo foi tocado aqui. Registrado em `.planning/phases/134-.../deferred-items.md`

---

**Total deviations:** 0 auto-fixed; 1 item fora de escopo documentado (não corrigido, não é regressão)
**Impact on plan:** Nenhum. `npm run test:js` sai com 162/163 (o 1 que falha é o item acima, pré-existente).

## Issues Encountered

- Primeira versão do data provider do teste PHP usava `base_path()`, que ainda não está disponível quando o PHPUnit resolve `dataProvider` estático (roda antes de `createApplication()`). Resolvido calculando o caminho do fixture via `dirname(__DIR__, 2)` a partir do próprio arquivo de teste.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano.

## Next Phase Readiness

- `AnuncioSaudeService::avaliar()`/`triagem()` prontos para o job de coleta (planos 134-04/05) chamar por item do multiget e gravar `nota_ecf`/`nota_sinais`/`motivos`/`severidade` em `ml_acervo_itens` (schema já existe desde 134-02).
- A guarda de concordância dupla (PHPUnit + `node --test`) fica de sentinela: qualquer alteração futura de peso em qualquer um dos dois lados — `AnuncioSaudeService::PESOS` ou os 8 `add(...)` de `mlAnuncioRegras.js` — quebra pelo menos um teste, sem depender de revisão manual.
- `resources/js/lib/mlAnuncioRegras.js` permanece intacto, byte a byte (confirmado por `git diff`) — nenhum risco de regressão no wizard em produção (D-16).
- Item fora de escopo (`estrutura-grade-glide.test.js`) documentado em `deferred-items.md` para quem mexer na Fase 87 (grade em massa) da próxima vez.
- Nenhum bloqueio conhecido para os próximos planos da fase.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: app/Services/Mlb/Acervo/AnuncioSaudeService.php
- FOUND: tests/fixtures/phase134/nota-ecf-casos.json
- FOUND: tests/Unit/Phase134/NotaEcfFecharComContaTest.php
- FOUND: tests/js/notaEcfConcordancia.test.js
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-03-SUMMARY.md
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/deferred-items.md
- FOUND commit: a242c762
- FOUND commit: f9d2fb43
- FOUND commit: fa82988b
