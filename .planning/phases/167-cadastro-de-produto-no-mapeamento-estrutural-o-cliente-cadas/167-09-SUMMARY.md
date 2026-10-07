---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 09
subsystem: portal-cliente / mapeamento-estrutural
tags: [produtos, importacao, xlsx, planilha-modelo, previa]
requires: [167-06, 167-07]
provides:
  - ModeloProdutosXlsx (CABECALHOS com 11 colunas, gerar(): Spreadsheet)
  - LeitorPlanilhaProdutos::ler (limites, assinatura zip, sem calcular fórmula)
  - ImportadorProdutos::previa / aplicar (stateless, refaz o plano na confirmação)
affects: [167-10]
tech-stack:
  added: []
  patterns: [plano/aplicar stateless como a colagem de anúncios]
key-files:
  created:
    - app/Services/Portal/Estrutura/Produtos/ModeloProdutosXlsx.php
    - app/Services/Portal/Estrutura/Produtos/LeitorPlanilhaProdutos.php
    - app/Services/Portal/Estrutura/Produtos/ImportadorProdutos.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/ModeloEImportacaoTest.php
  modified: []
key-decisions:
  - "A confirmação manda ao gravarLinhas TODAS as linhas válidas na ordem do arquivo (inclusive as classificadas como sem mudança), não só novos + atualizados: assim a 1ª linha de cada grupo continua definindo o produto. Os totais devolvidos vêm do que o serviço de escrita de fato fez."
  - "Célula em branco é retirada da linha antes de normalizar (null explícito em custo/categoria limparia o dado); 'SEM MEDIDAS' é o único jeito de limpar volumes."
  - "Aviso de Nº volumes e Peso total só quando a linha trouxe volumes legíveis; tolerância 0,05 kg."
requirements-completed: [PR167-08]
duration: ~35min
completed: 2026-10-05
---

# Phase 167 Plan 09: Planilha-modelo e importação com prévia (D-13, D-14) Summary

O cliente baixa um modelo .xlsx com as 11 colunas da aba Produtos e importa a planilha (inclusive a aba Produtos do Planejamento, sem reformatar) com prévia que não grava; a confirmação reenvia o arquivo, refaz o plano e grava pelo mesmo serviço da grade.

## Commits

- `5994d938` — modelo .xlsx e leitor seguro (testes do modelo e do leitor)
- `0140e147` — `ImportadorProdutos` (prévia e aplicação) e testes

## Deviations from Plan

**1. [Ajuste de desenho] `aplicar()` envia também as linhas "sem mudança" ao `gravarLinhas`.** O plano dizia "novos + atualizados". Pular as sem mudança faria a linha seguinte de um grupo virar a "primeira" do produto e redefinir nome/família. O serviço devolve `sem_mudanca` por conta própria e nada é gravado nelas.

**2. [Ajuste] Teste de auditoria** lê o evento por `getExtraProperty('evento')` (o log do portal usa `log_name = portal`), como já faz o `CadastroDeProdutoTest`.

Sem desvios de Regra 1-4.

## Verificação

- `tests/Feature/PortalCliente/Estrutura/Produtos`: 97 testes, 491 asserções, OK (19 em `ModeloEImportacaoTest`).
- Regressão: `tests/Feature/PortalCliente/Estrutura` 180 testes, 1215 asserções, EXIT=0; `tests/Unit/PortalEstrutura` 54 testes, 189 asserções, EXIT=0.
- `getCalculatedValue` no leitor: 0; `->delete(`/`::destroy(` no importador: 0; o teste não cita a planilha real.

## Known Stubs

Nenhum. (Rota/controller/tela do upload ficam no plano 167-10.)

## Threat Flags

Nenhum além do modelo do plano (T-167-34..39 mitigados: limites e assinatura zip, leitura sem calcular, plano refeito na confirmação, células do modelo em texto, mensagem fixa ao cliente com detalhe só no log, fixture sintética).

## Self-Check: PASSED

Arquivos e commits `5994d938`, `0140e147` conferidos.
