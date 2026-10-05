---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 02
subsystem: portal-cliente / mapeamento-estrutural
tags: [logistica, cubagem, frete, config, funcoes-puras]
requires: [167-01]
provides:
  - config/estrutura_produtos.php (regras globais do ML + tabela de frete reserva 29x8)
  - LogisticaProduto (pacote empilhado, peso cubado/faturado, pendente/me1/me2/me2_full)
  - TabelaFreteEcf (faixas/valor, MATCH com -0,0001)
  - PendenciasDoProduto (lista fixa de pendências por linha)
  - NumeroBr e VolumesTexto (leitura estrita de números e do texto de volumes)
affects: [167-03..167-17]
tech-stack:
  added: []
  patterns: [classe final com métodos estáticos, parâmetros do ML em config, gate de fonte por token_get_all]
key-files:
  created:
    - config/estrutura_produtos.php
    - app/Services/Portal/Estrutura/Produtos/NumeroBr.php
    - app/Services/Portal/Estrutura/Produtos/VolumesTexto.php
    - app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php
    - app/Services/Portal/Estrutura/Produtos/TabelaFreteEcf.php
    - app/Services/Portal/Estrutura/Produtos/PendenciasDoProduto.php
    - tests/Unit/PortalEstrutura/NumeroBrTest.php
    - tests/Unit/PortalEstrutura/VolumesTextoTest.php
    - tests/Unit/PortalEstrutura/LogisticaProdutoTest.php
    - tests/Unit/PortalEstrutura/TabelaFreteEcfTest.php
    - tests/Unit/PortalEstrutura/PendenciasDoProdutoTest.php
  modified: []
key-decisions:
  - "D-15/D-17: ME2 e Full pelo peso REAL; faturado só usa o cubado acima do mínimo do config"
  - "D-16: tabela ECF (reputação verde, vigente desde 2026-03-02) é reserva e sempre rotulada estimativa"
  - "Faixas de preço do config são limites de COLUNA, não a regra de frete grátis; gate de fonte barra 79 e 6000 em código"
requirements-completed: [PR167-09, PR167-10, PR167-11]
duration: ~25min
completed: 2026-10-05
---

# Phase 167 Plan 02: Regras de envio e leitura de números Summary

Logística provável, peso cubado/faturado, frete reserva da ECF e pendências por linha em funções puras, com todos os limites do ML num config global e o gabarito da planilha reproduzido nos testes.

## Commits

- `82c13379` — leitores de número BR e de volumes da planilha (Task 1)
- `d70f0898` — logística provável, frete reserva e pendências do produto (Task 2)

## O que foi feito

- Config com `fator_cubagem` 6000, `peso_cubado_minimo` 5, `me2`, `full` e `frete` (vigência, cache, lote, faixas, tabela 29x8).
- Tabela copiada SOMENTE da aba "Frete ML Verde" (script no scratchpad, `setLoadSheetsOnly`, apagado ao fim). Conferido: área C5:J33 = 29x8 e célula 79–99,99 / até 0,3 kg = 12,35.
- `NumeroBr` recusa o que não é número (evita o `floatval('27,8')` = 27); `VolumesTexto` lê `×`/`x`/`*` e `·`/`-`/`kg`, separadores `|`, `;` e quebra de linha.
- Gabarito medido na planilha (9 casos: 93×55×6, 91×59×20, 89×57×52, 65,5×47×10,5, 74,5×28×6, 131×48×17, 187×44×12, pacote empilhado, sem volume) está no `LogisticaProdutoTest`.

## Verificação

- `tests/Unit/PortalEstrutura`: 45 testes, 128 asserções, OK.
- `tests/Feature/PortalCliente/Estrutura` (pasta inteira, depois do último commit): 93 testes, 768 asserções, OK (baseline era 83; os 10 a mais vêm do 167-01).
- `git status` sem `.xlsx` nem script de leitura no repositório.

## Deviations from Plan

Nenhuma de regra. Observação: o passo "ver o teste falhar antes" (RED) não foi feito em separado — código e testes foram escritos juntos e commitados juntos, como o plano pede (teste no mesmo commit). Também não rodei `artisan tinker` nos critérios de aceite; conferi `config('estrutura_produtos.me2.soma')` e a forma 29x8 por teste (`TabelaFreteEcfTest::test_forma_da_tabela_29_por_8`) e por `include` do arquivo.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum.

## Self-Check: PASSED

Arquivos e commits `82c13379` e `d70f0898` conferidos no git.
