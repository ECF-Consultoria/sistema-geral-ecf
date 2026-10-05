---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 02
subsystem: publicador
tags: [alavancas, cliente-http, erros, contexto-conta, cache, testes]
requires: [166-01]
provides:
  - ClienteMlPublicador::daConta com cabeçalhos por chamada, DELETE sem corpo, host fixo em produção
  - MapeadorErroAlavanca (AL166-20)
  - ContaAlavanca, ContextoAlavancas, LeitorContaAlavancas, CacheAlavancas
  - trait de teste CenarioAlavancas
affects: [166-03, 166-04]
key-files:
  created:
    - app/Services/Publicador/Alavancas/MapeadorErroAlavanca.php
    - app/Services/Publicador/Alavancas/ContaAlavanca.php
    - app/Services/Publicador/Alavancas/ContextoAlavancas.php
    - app/Services/Publicador/Alavancas/LeitorContaAlavancas.php
    - app/Services/Publicador/Alavancas/CacheAlavancas.php
    - tests/Feature/Publicador/Alavancas/Concerns/CenarioAlavancas.php
    - tests/Feature/Publicador/Alavancas/ClienteCabecalhosTest.php
    - tests/Feature/Publicador/Alavancas/ContextoAlavancasTest.php
    - tests/Unit/Publicador/Alavancas/MapeadorErroAlavancaTest.php
    - tests/fixtures-ml/alavancas/doc/erros.json
  modified:
    - app/Services/Publicador/ClienteMlPublicador.php
metrics:
  completed: 2026-10-04
  tasks: 3
---

# Fase 166 Plano 02: Infraestrutura das Alavancas Summary

Cliente HTTP do Publicador com cabeçalhos por chamada e host fixo em produção, tradutor de erros do ML em pt-BR com código, contexto de conta para as duas âncoras, leitura enxuta de `/users/me`, cache por conta com invalidação por versão e o cenário de teste compartilhado.

## Commits

| Task | Commit | Descrição |
|---|---|---|
| 1 | `21f6212b` | feat(166-02): cliente do Publicador aceita cabeçalhos por chamada |
| 2 | `e506f60b` | feat(166-02): erros do ML das alavancas em pt-BR |
| 3 | `9cde8137` | feat(166-02): contexto da conta das Alavancas e cenário de teste |


## Grep de DELETE

`grep -rn "'DELETE'" app/Services/Publicador app/Http/Controllers` antes da mudança: **nenhuma ocorrência** (exit 1). Nenhum chamador atual usa DELETE, então o ramo "DELETE sem corpo" não altera nenhuma chamada existente.

## Origem das fixtures de erro

`tests/fixtures-ml/alavancas/doc/erros.json` tem `"origem": "doc-oficial-resumo"`: os corpos vêm do RESEARCH (§2 e §8 item 6), sem reler a doc por curl. 10 casos. Grafia conferida no índice: `tests/fixtures-ml/alavancas/doc/erros.json`. Na hora de ligar a UI/escrita, vale reler as páginas e trocar por `doc-oficial`.

## Testes

- `tests/Unit/Publicador/Alavancas` + `tests/Feature/Publicador/Alavancas`: 42 testes, 153 asserções, exit 0.
- `tests/Feature/Publicador`: 255 testes, 1562 asserções, exit 0 (baseline 238 antes da fase; 244 após 166-01).
- `tests/Unit/Publicador`: 181 testes, 587 asserções, exit 0 (baseline 156; 165 após 166-01).
- CamadaMlTest, ConferenciaTest e PublicacaoTest (chamadas existentes) verdes na Task 1.

## Decisões

- `LeitorContaAlavancas::ler(fresco: true)` chama o ML e também renova o valor em cache (usa `atualizar` do `CacheAlavancas`).
- `MapeadorErroAlavanca`: status 0/423/429/5xx mandam sobre o corpo; 403 usa o caminho; para erros só com texto em inglês (ex.: "Maximum 5 price_per_quantity…") procura o trecho na mensagem original e mantém o `code` do corpo como código.
- `RespostaMl` não foi alterado.

## Deviations from Plan

None - plano executado como escrito. (`ClienteMlPublicador.php` está em CRLF no disco; o git avisa na normalização, sem efeito no diff.)

## Known Stubs

Nenhum.

## Notas

Nenhuma chamada de rede ao ML (tudo por `Http::fake`); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED
