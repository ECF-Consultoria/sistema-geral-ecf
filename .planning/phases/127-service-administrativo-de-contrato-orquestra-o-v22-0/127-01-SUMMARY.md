---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 01
subsystem: dados / contrato-assinatura
tags: [migration, model, factory, d-06, mariadb-pitfalls, tdd]
dependency-graph:
  requires: [125-01, 125-02, 126-01]
  provides: [contrato_assinaturas.servico_id, ContratoAssinatura::emAndamentoDoServico]
  affects: [127-02, 127-03, 127-04, 127-05, 127-06, 127-07]
tech-stack:
  added: []
  patterns:
    - "índice único composto (empresa+serviço) via 2 colunas espelho preenchidas por hook saving"
    - "criar índice novo ANTES de dropar o antigo (armadilha 1553 do MariaDB)"
key-files:
  created:
    - database/migrations/2026_08_12_100000_add_servico_e_prazo_to_contrato_assinaturas_table.php
    - tests/Feature/Phase127/ContratoAssinaturaServicoTest.php
    - tests/Feature/Phase127/MigrationsFase127ConvencoesTest.php
  modified:
    - app/Models/ContratoAssinatura.php
    - database/factories/ContratoAssinaturaFactory.php
    - tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php
    - tests/Feature/Phase126/ContratoPdfDadosTest.php
    - tests/Feature/Phase126/ContratoAssinaturaPdfPathsTest.php
decisions:
  - "D-06 aplicada: trava de unicidade 'em andamento' passa de (empresa) para (empresa+serviço)"
  - "comSnapshot() da factory muda o default de 3 itens para 1 (reflete D-06); testes da Fase 126 que dependiam do default de 3 passaram a informar a lista explicitamente"
metrics:
  duration: "~55min"
  completed: "2026-08-12"
---

# Phase 127 Plan 01: Migration da D-06 — trava composta (empresa + serviço) Summary

Nova migration + model + factory fazem `contrato_assinaturas` suportar N contratos por empresa
(um por serviço), trocando o índice único de 1 coluna (`ca_company_andamento_uniq`, Fase 125) para
uma chave composta `(company_id_em_andamento, servico_id_em_andamento)` — resolvendo a contradição
entre a Fase 125 (1 contrato por empresa) e a D-21 da Fase 126 (1 modelo `.docx` por serviço, logo
N contratos por empresa).

## O que foi construído

1. **Testes RED** (`tests/Feature/Phase127/`) escritos antes da migration: 6 comportamentos da D-06
   (dois serviços convivem, mesmo serviço trava, hook preenche as duas colunas juntas, sair de
   andamento libera o slot, `emAndamentoDoServico()`, `emAndamentoDaEmpresa()` continua funcionando)
   + guarda estática de convenções de schema (sem `enum`, `servico_id` nullable, índices ≤ 64
   caracteres, índice novo criado antes do drop do antigo).

2. **Migration** `2026_08_12_100000_add_servico_e_prazo_to_contrato_assinaturas_table.php`:
   - `servico_id` (FK `restrictOnDelete`, `nullable()` por causa de uma limitação do SQLite dos
     testes — `ADD COLUMN ... NOT NULL` sem default falha mesmo em tabela vazia).
   - `servico_id_em_andamento` (espelho derivado, sem FK própria — mesma disciplina de
     `company_id_em_andamento` da Fase 125).
   - `prazo_dias` e `lembrete_dias` (DADOS-06 — auditoria do prazo escolhido na criação do
     envelope, para a Fase 130 calcular "há quanto tempo é aceitável esperar").
   - Índice `ca_empresa_servico_andamento_uniq` **criado antes** de `dropUnique('ca_company_andamento_uniq')`
     — armadilha 1553 do MariaDB (dropar índice usado por FK falha), que o SQLite dos testes não
     detecta em nenhuma das duas ordens.

3. **Model `ContratoAssinatura`**: `$fillable` e `$casts` estendidos; hook `saving` agora preenche
   **as duas** colunas derivadas na mesma passagem e lança `\RuntimeException` se `servico_id`
   estiver vazio (guard de obrigatoriedade que o schema não pode dar, já que a coluna é `nullable()`
   por causa do SQLite); novo `emAndamentoDoServico(companyId, servicoId)`; `emAndamentoDaEmpresa()`
   mantido intacto para a suíte da Fase 125; relação `servico(): BelongsTo`.

4. **Factory**: `servico_id` resolvido do catálogo semeado por migration (`Servico` não tem
   `HasFactory`); `comSnapshot()` muda o default de 3 itens para 1 (reflete a D-06 — um contrato
   representa um serviço só).

5. **Task 4 — os 2 testes da Fase 125 que quebram por construção**, adaptados:
   - `indices_tem_nome_explicito_e_cabem_em_64_caracteres`: aponta para o índice novo.
   - `indice_unico_barra_segundo_contrato_em_andamento` → renomeado para
     `indice_unico_barra_segundo_contrato_do_mesmo_servico`: preenche `servico_id_em_andamento`
     nos dois inserts crus (sem isso as duas linhas ficavam `(company_id, NULL)`, e índice único
     com `NULL` numa coluna nunca rejeita duplicata — SQL padrão, vale igual no MariaDB). Mantido
     o insert cru via `DB::table()` de propósito: o valor deste teste é provar que a trava é do
     **banco**, não do hook do model.
   - Teste novo `dois_contratos_da_mesma_empresa_em_servicos_diferentes_convivem_em_andamento`:
     prova executável de que a D-06 permite N contratos por empresa (um por serviço).

## Resultado da suíte

`Phase127 + Phase125 + Phase126` = **159 testes verdes** (baseline de 147 intacto + 11 testes novos
da Fase 127 + 1 teste novo na Fase 125). Zero regressão.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1/instrução do plano] Dois testes da Fase 126 dependiam do default de 3 itens de `comSnapshot()`**
- **Encontrado em:** Task 3, ao rodar a suíte combinada.
- **Arquivos:** `tests/Feature/Phase126/ContratoPdfDadosTest.php` (helper `contratoComSnapshot()`,
  usado pelo teste `contrato_com_tres_servicos_no_snapshot_...` que faz `assertCount(3, ...)`) e
  `tests/Feature/Phase126/ContratoAssinaturaPdfPathsTest.php` (teste
  `comsnapshot_devolve_array_com_pelo_menos_dois_servicos_no_formato_esperado`).
- **Fix:** seguindo a instrução explícita do plano ("se depender, ajustar os testes afetados para
  passar a lista explicitamente em vez de mudar a semântica do model"), os dois arquivos passaram a
  chamar `comSnapshot([...])` com a lista de serviços explícita (a mesma forma que era o default
  antigo), em vez de depender do default da factory. O comportamento testado (snapshot com múltiplos
  serviços) continua coberto — só deixou de ser o *default*, que agora reflete a D-06 (um contrato =
  um serviço).
- **Commit:** `d01577f5`.
- Não estava listado em `files_modified` do plano, mas a ação do Task 3 instruía explicitamente essa
  adaptação.

Nenhum outro desvio. O resto foi executado exatamente como o plano descreveu.

## Known Stubs

Nenhum. Este plano é puramente schema + model + factory, sem UI nem dado exibido ao usuário final.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano — a única superfície nova (`servico_id`,
FK para `servicos`, e o índice composto) já estava listada em T-127-01/T-127-02/T-127-03.

## Ambiente / limitação observada

`artisan migrate --pretend` não pôde ser executado contra o MySQL/MariaDB local (fora do ar, conforme
já registrado no `environment_notes` deste plano — `Connection refused` na porta 3306). A verificação
de ordem (índice novo antes do drop do antigo) foi coberta pela guarda estática
`MigrationsFase127ConvencoesTest::indice_composto_novo_e_criado_antes_de_dropar_o_indice_antigo()`,
que lê o texto do arquivo por `strpos()` — cobertura equivalente, sem depender de conexão de banco.
O deploy real (onde a armadilha 1553 se manifestaria) só acontece depois do gate do plano 127-07,
conforme a seção `<verification>` do plano.

## Self-Check: PASSED

- FOUND: database/migrations/2026_08_12_100000_add_servico_e_prazo_to_contrato_assinaturas_table.php
- FOUND: app/Models/ContratoAssinatura.php (com emAndamentoDoServico)
- FOUND: database/factories/ContratoAssinaturaFactory.php
- FOUND: tests/Feature/Phase127/ContratoAssinaturaServicoTest.php
- FOUND: tests/Feature/Phase127/MigrationsFase127ConvencoesTest.php
- FOUND: tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php (adaptado)
- FOUND commit 7db2fd83 (test RED)
- FOUND commit 8257a617 (migration)
- FOUND commit d01577f5 (model+factory)
- FOUND commit 7b172779 (adapta Fase 125)
