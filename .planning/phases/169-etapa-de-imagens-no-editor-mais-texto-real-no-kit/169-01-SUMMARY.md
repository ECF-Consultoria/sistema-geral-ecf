---
phase: 169-etapa-de-imagens-no-editor-mais-texto-real-no-kit
plan: 01
subsystem: api
tags: [creative-engine, product-truth, publicador, laravel, eloquent]

requires:
  - phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
    provides: "ContextoCriativoDoPublicador::montar() e CreativeContextBuilder::paraPublicador() (ramo do Publicador no Creative Engine)"
  - phase: 160
    provides: "ProductTruth/ProductTruthBuilder (TRUTH-01 a TRUTH-04) e o campo beneficiosVerificados (declarado, nunca populado)"
provides:
  - "Tabela pub_produto_fatos_criativo (fato confirmado pelo operador: beneficio/medida, por produto, FK nullOnDelete)"
  - "ContextoCriativoDoPublicador::fatosHumanos() — leitura isolada dos fatos humanos de um produto"
  - "CreativeContext::fatosHumanosBeneficios/fatosHumanosMedidas (opcionais, fora de paraAuditoria())"
  - "ProductTruth::medidasConfirmadas (novo) + beneficiosVerificados (agora populado de verdade)"
affects: [169-02, 169-03]

tech-stack:
  added: []
  patterns:
    - "Fato humano conferido entra no ProductTruth por CAMPO separado (beneficiosVerificados/medidasConfirmadas), nunca misturado no array de fatosVerificados/contagens — distinção por estrutura, não por string de origem"

key-files:
  created:
    - database/migrations/2026_10_07_150000_create_pub_produto_fatos_criativo_table.php
    - app/Models/PubProdutoFatoCriativo.php
    - tests/Unit/Phase169/PubProdutoFatoCriativoMigrationGuardaTest.php
    - tests/Feature/Phase169/PubProdutoFatoCriativoTest.php
    - tests/Unit/Phase169/ContextoCriativoDoPublicadorFatosHumanosTest.php
  modified:
    - app/Services/Creative/Dto/CreativeContext.php
    - app/Services/Publicador/Criativos/ContextoCriativoDoPublicador.php
    - app/Services/Creative/CreativeContextBuilder.php
    - app/Services/Creative/Dto/ProductTruth.php
    - app/Services/Creative/ProductTruthBuilder.php
    - tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php
    - tests/Unit/Phase160/ProductTruthBuilderTest.php

key-decisions:
  - "beneficiosVerificados confirmado como campo orfão desde a Fase 160/161 (grep em CreativePlanner.php e CreativePromptBuilder.php não encontrou nenhuma leitura) — reaproveitado em vez de criar campo paralelo, como o plano previa"
  - "fatosHumanos(int $produtoId) assinado non-nullable: produto_id em pub_rascunhos é NOT NULL desde a migration de backfill da Fase 164 (2026_10_02_100100), confirmado por leitura antes de implementar"

requirements-completed: [TXT-01, TXT-02, TXT-05]

duration: ~25min
completed: 2026-10-07
---

# Phase 169 Plano 01: Fatos confirmados chegam ao Product Truth Summary

**Tabela `pub_produto_fatos_criativo` (ponto forte/medida confirmados pelo operador) alimenta `ProductTruth::beneficiosVerificados`/`medidasConfirmadas` via o adaptador do Publicador, sem tocar `fatosVerificados()`/`contagens()` (TRUTH-01/02 intactas).**

## Performance

- **Duration:** ~25 min
- **Tasks:** 2/2 completas
- **Files modified:** 7 (+ 5 arquivos de teste novos/estendidos)

## Accomplishments
- Tabela nova `pub_produto_fatos_criativo` com disciplina `nullOnDelete()` nas duas FKs (produto e usuário) — apagar qualquer um dos dois preserva o histórico de fatos confirmados.
- `ContextoCriativoDoPublicador::fatosHumanos()` lê e separa beneficio/medida por produto, em ordem estável, com `trim()`.
- `CreativeContext` ganhou os dois campos novos (`fatosHumanosBeneficios`/`fatosHumanosMedidas`), opcionais e fora de `paraAuditoria()` — o ramo antigo do assistente nunca os preenche.
- `ProductTruth` finalmente popula `beneficiosVerificados` (campo dormente desde a Fase 160) e ganhou `medidasConfirmadas` — ambos expostos em `paraPrompt()`.
- Regressão zero confirmada por teste: sem nenhuma linha de fato humano, o `ProductTruth`/`CreativeContext` resultante é idêntico ao que existia antes deste plano.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Tabela e model dos fatos confirmados pelo operador** — `fa2102ff` (feat)
2. **Task 2: Os fatos confirmados chegam ao ProductTruth do Publicador** — `72ab6219` (feat)

**Plan metadata:** não commitado (SUMMARY/STATE/ROADMAP ficam fora de git por instrução explícita deste plano).

_Nenhuma task teve tdd formal RED→GREEN separado em commits distintos — os testes foram escritos e verificados junto com a implementação de cada task (molde já usado nas fases 160/161/165 para este módulo), com todos os testes passando antes do commit._

## Files Created/Modified

- `database/migrations/2026_10_07_150000_create_pub_produto_fatos_criativo_table.php` - tabela nova, FKs nullOnDelete, índice `pubfc_produto_tipo_ix`
- `app/Models/PubProdutoFatoCriativo.php` - constantes TIPO_BENEFICIO/TIPO_MEDIDA, relações produto()/confirmadoPor()
- `app/Services/Creative/Dto/CreativeContext.php` - dois parâmetros novos opcionais, fora de paraAuditoria()
- `app/Services/Publicador/Criativos/ContextoCriativoDoPublicador.php` - método fatosHumanos(), montar() inclui chave fatos_humanos
- `app/Services/Creative/CreativeContextBuilder.php` - paraPublicador() lê fatos_humanos e passa ao CreativeContext
- `app/Services/Creative/Dto/ProductTruth.php` - campo medidasConfirmadas novo, paraPrompt() expõe medidas_confirmadas
- `app/Services/Creative/ProductTruthBuilder.php` - paraContexto() liga os dois campos ao CreativeContext; fatosVerificados()/contagens() inalterados
- `tests/Unit/Phase169/PubProdutoFatoCriativoMigrationGuardaTest.php` - guarda de conteúdo da migration nova
- `tests/Feature/Phase169/PubProdutoFatoCriativoTest.php` - feature da tabela/model nova
- `tests/Unit/Phase169/ContextoCriativoDoPublicadorFatosHumanosTest.php` - fatosHumanos() isolado
- `tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` - 2 casos novos (com/sem fato humano no contexto)
- `tests/Unit/Phase160/ProductTruthBuilderTest.php` - 2 casos novos (passagem ao ProductTruth e regressão zero)

## Decisions Made

- Confirmado por leitura (não só confiança no plano) que `beneficiosVerificados` era de fato órfão: `grep -rn "beneficiosVerificados|beneficios_verificados" app/` só encontrou as duas ocorrências no próprio `ProductTruth.php`/`ProductTruthBuilder.php` (a declaração e o `[]` fixo) — nenhuma leitura em `CreativePlanner.php` nem `CreativePromptBuilder.php`. A afirmação do planner se confirmou integralmente.
- `ContextoCriativoDoPublicador::fatosHumanos(int $produtoId)` ficou non-nullable conforme a assinatura do plano: confirmado que `pub_rascunhos.produto_id` é `NOT NULL` desde o backfill da migration `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php` (Fase 164) — `$r->produto_id` nunca é `null` no estado atual do schema.
- Testes das Task 1/2 escritos no molde literal dos guardas de migration já existentes (`PubColunasMigrationGuardaTest`), reaproveitando a lógica de "remover linhas de comentário antes de dividir por `;`" para evitar falso positivo/negativo do docblock mencionando `nullOnDelete()`/`nullable()` em prosa.

## Deviations from Plan

None — plano executado exatamente como escrito. Nenhum arquivo fora de `files_modified` do frontmatter foi tocado; `fatosVerificados()`/`contagens()` não sofreram nenhuma alteração de linha.

## Confirmações pedidas no prompt de execução

**(a) `beneficiosVerificados` era de fato órfão?** Sim, confirmado por grep antes de implementar — nenhum arquivo além de `ProductTruth.php`/`ProductTruthBuilder.php` referenciava `beneficiosVerificados`/`beneficios_verificados` no código de produção (`app/`). A busca cobriu `CreativePlanner.php` e `CreativePromptBuilder.php` especificamente, como o planner havia indicado.

**(b) `fatosVerificados()`/`contagens()` continuam lendo só os atributos?** Sim — nenhuma linha desses dois métodos foi alterada nesta plano. `ProductTruthBuilder::paraContexto()` só ganhou duas linhas novas na construção do `ProductTruth` (`beneficiosVerificados: $contexto->fatosHumanosBeneficios` e `medidasConfirmadas: $contexto->fatosHumanosMedidas`), sempre fora do corpo dos dois métodos sensíveis.

**(c) Nada de `MODEL`/SEO pode entrar como fato?** Confirmado por construção: `fatosHumanosBeneficios`/`fatosHumanosMedidas` só podem conter o que está persistido em `pub_produto_fatos_criativo.texto` — uma tabela nova, vazia por padrão, escrita só por ação explícita do operador (169-02, fora de escopo deste plano). O caminho de `atributos`/`MODEL` do cadastro ML nunca alimenta esses dois campos; são populações inteiramente independentes dentro de `CreativeContext`.

## Issues Encountered

Um erro de teste próprio (não do código de produção) durante a Task 2: a primeira versão de `test_sem_fatos_humanos_os_dois_campos_ficam_vazios_como_antes` esperava `fatosVerificados === ['Material' => 'MDF']` com um atributo `DOOR_QUANTITY` no contexto — mas `DOOR_QUANTITY` legitimamente também entra em `fatosVerificados` (ele é um atributo com valor, e `fatosVerificados()` não filtra por "não ser também um id de contagem"). Corrigido o assert do teste para `['Material' => 'MDF', 'Quantidade de portas' => '2']`; nenhuma linha de código de produção mudou por causa disso.

## Verificação executada (resultado real)

Rodado isoladamente, conforme pedido (`<verification>` do plano), em sequência, sem paralelismo:

```
tests/Unit/Phase169 tests/Feature/Phase169 tests/Unit/Phase160 tests/Unit/Phase161 \
tests/Feature/Phase165 tests/Unit/Phase165
→ Tests: 1 incomplete, 198 passed (1021 assertions)
```

O 1 incomplete é o caso já documentado em `.planning/phases/168-*/deferred-items.md`
(`RotasAntigasComKitDoPublicadorTest::guarda das rotas antigas se liga sozinha quando chegar`,
instabilidade conhecida de Windows, não é regressão deste plano).

Também rodado (não exigido pela `<verification>` do plano, mas pelas `<restricoes_inviolaveis>`
do prompt de execução, para confirmar zero regressão no restante do Creative Engine e no
Publicador):

```
tests/Unit/Phase162 tests/Feature/Phase162 tests/Unit/Phase168
→ Tests: 74 passed (317 assertions)

tests/Feature/Publicador tests/Unit/Publicador
→ Tests: 816 passed (4093 assertions)  — bate exatamente com o número documentado
```

Nenhuma falha nova em nenhuma suíte.

## User Setup Required

None - nenhuma configuração de serviço externo. Migration ainda não rodou em produção (fora de
escopo deste plano — não houve deploy nem comando de VPS).

## Next Phase Readiness

Pronto para 169-02 (endpoint HTTP que escreve em `pub_produto_fatos_criativo`) e 169-03 (tela de
confirmação): o modelo, a tabela e a fiação até `ProductTruth` já existem e estão testados. Nenhum
endpoint HTTP foi criado neste plano (fora de escopo, como o próprio objetivo registrava) — a
tabela só é lida por `ContextoCriativoDoPublicador::fatosHumanos()`, nunca escrita por rota alguma
ainda.

---
*Phase: 169-etapa-de-imagens-no-editor-mais-texto-real-no-kit*
*Completed: 2026-10-07*

## Self-Check: PASSED

Todos os 6 arquivos criados confirmados em disco e os 2 commits (`fa2102ff`, `72ab6219`)
confirmados em `git log --oneline --all`. Nada ausente.
