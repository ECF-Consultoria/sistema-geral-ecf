---
phase: 140-extrair-tabelas-progressivas-do-clicksign
plan: 05
subsystem: web
tags: [laravel, inertia, react, clicksign, contratos, cobranca]

requires:
  - phase: 140-04
    provides: tabela `contrato_tabela_propostas` + model `ContratoTabelaProposta` + comando `clicksign:extrair-tabelas --gravar`
provides:
  - tela `/administrativo/contratos/tabelas` (Admin/TabelasContrato.jsx) — conferência contrato a contrato do que a leitura automática do Clicksign encontrou
  - `TabelasContratoController::index()/confirmar()/descartar()` — a ÚNICA porta de escrita desta fase para `empresa_faixas_faturamento` e `companies.cnpj/razao_social`
affects: []

tech-stack:
  added: []
  patterns:
    - "Confirmação auditada: DB::transaction all-or-nothing (apaga + recria EmpresaFaixaFaturamento) + confirmado_por/confirmado_em, mesma disciplina de FechamentoController::salvarFaixasEmpresa (Fase 137, D-13)"
    - "CNPJ/razão social completam companies só quando o campo já está vazio — nunca sobrescreve; CNPJ que já pertence a outra empresa não é gravado (coluna única) e vira aviso, sem bloquear a confirmação"

key-files:
  created:
    - app/Http/Controllers/TabelasContratoController.php
    - resources/js/Pages/Admin/TabelasContrato.jsx
    - tests/Feature/Phase140/Phase140TelaConferenciaTest.php
    - tests/Feature/Phase140/Phase140ConfirmacaoTabelaTest.php
  modified:
    - routes/web.php

key-decisions:
  - "Vocabulário da tela replica literalmente CONFIANCA_LABEL/TIPO_LABEL do relatório do plano 140-03 (redefinido como constante local no controller, não importado do Console\\Command, para não acoplar uma camada HTTP a um comando de console) — a tela nunca pode divergir do texto que o usuário já validou no relatório"
  - "Confiança 'incerto'/'provavel' nunca herda seleção automática no painel de conferência: o seletor de empresa nasce SEM nada marcado, mesmo quando a leitura já veio com um palpite — a pessoa precisa clicar (no candidato sugerido ou na busca) para o botão Confirmar habilitar, nunca 'confirmar sem olhar'"
  - "CNPJ já pertencente a outra empresa NÃO bloqueia a confirmação inteira — só o campo cnpj não é gravado, com aviso explícito na resposta (flash 'aviso'). Bloquear a confirmação toda faria a tabela de cobrança (o dado que MAIS importa) ficar refém de um conflito de cadastro secundário"
  - "tabela_em_uso (comparação lida × em uso hoje) usa FechamentoFaixaResolver::paraEmpresa() direto — nunca recalcula a regra de origem (grupo/própria/serviço) na tela, mesma fonte única da Fase 137/138"

requirements-completed: [TAB-08, TAB-09]

duration: ~50min
completed: 2026-09-09
---

# Fase 140 Plano 05: Tela de conferência e confirmação auditada das tabelas do Clicksign Summary

**A tela `/administrativo/contratos/tabelas` (conferência contrato a contrato) + `TabelasContratoController::confirmar()/descartar()` — a única porta de escrita que liga a leitura automática do Clicksign a `empresa_faixas_faturamento`/`companies`, sempre com confirmação humana explícita.**

## Performance

- **Duration:** ~50 min
- **Tasks:** 2/2 de código completas — Tarefa 3 (checkpoint humano) aguardando o usuário, ver seção própria abaixo
- **Files created:** 4 (controller, página React, 2 suítes de teste)
- **Files modified:** 1 (`routes/web.php`)

## Accomplishments

- `GET /administrativo/contratos/tabelas` lista as propostas paginadas, filtro por situação (`pendente` por padrão) e por confiança, com resumo das quatro contagens do relatório (140-03) calculado sobre o universo INTEIRO — nunca o recorte filtrado, mesma disciplina de `ContratoAdminController::index()`.
- Cada linha manda já resolvido: empresa palpitada, candidatos alternativos, faixas formatadas em R$, e a tabela que a empresa usa HOJE (`FechamentoFaixaResolver::paraEmpresa()`) para comparação lado a lado.
- `POST .../confirmar`: `company_id` obrigatório mesmo quando o palpite já veio preenchido (D-05); recusa proposta que não está `pendente` (422); grava a tabela ALL-OR-NOTHING dentro de transação quando `tipo_cobranca === tabela`; NUNCA grava faixa para `valor_fixo`/`indefinido`/`ilegivel` (D-03); completa `cnpj`/`razao_social` só quando vazios, nunca sobrescreve; CNPJ já pertencente a outra empresa não é gravado e vira aviso sem travar a confirmação; marca `confirmado_por`/`confirmado_em`.
- `POST .../descartar`: marca `descartada`, registra quem e quando, não grava nada em cobrança nenhuma.
- Vocabulário sem jargão em toda a tela — nunca "proposta"/"parser"/"envelope"/"score"/"palpite" como rótulo técnico; o palpite incerto nunca aparece com check verde (só `certo`, que vem de CNPJ batendo, ganha o ícone de confirmado).
- `npm run build` executado — `Admin/TabelasContrato` confirmado no manifest do Vite; CSS compilado conferido por script Node contra as classes fracionadas proibidas (`px-4.5`/`gap-4.5`/`py-5.5`) — nenhuma encontrada.

## Task Commits

1. **Tarefa 1: tela de conferência**
   - `84d118d1` feat(140-05): tela de conferencia das tabelas lidas do Clicksign

2. **Tarefa 2: confirmação auditada (tdd="true")**
   - `b4d27f03` test(140-05): cobre confirmacao auditada da tabela (RED)
   - GREEN: **sem commit próprio** — ver "Deviations" abaixo, o ciclo RED/GREEN foi verificado manualmente porque a implementação de `confirmar()`/`descartar()` já estava dentro do commit `84d118d1` da Tarefa 1.

## Files Created/Modified
- `app/Http/Controllers/TabelasContratoController.php` — `index()` (listagem+resumo+comparação), `confirmar()` (transação all-or-nothing + completude de cadastro), `descartar()`
- `resources/js/Pages/Admin/TabelasContrato.jsx` — tela de conferência: resumo de 4 contagens, filtros, tabela de linhas, painel de conferência (Dialog) com busca de empresa, comparação lida × em uso hoje, aviso de valor implausível/escalonado em destaque
- `routes/web.php` — 3 rotas dentro do grupo `permission:admin.contratos` já existente (nunca `role:admin`, T-140-19)
- `tests/Feature/Phase140/Phase140TelaConferenciaTest.php` — 7 testes (403/200, props via `assertInertia`, filtro por situação, default da whitelist, marca de ambiguidade, `certo` sem aviso de ambiguidade quando não é ambíguo)
- `tests/Feature/Phase140/Phase140ConfirmacaoTabelaTest.php` — 14 testes, um por item do `<behavior>` da Tarefa 2 + guarda de permissão

## Decisions Made
Ver `key-decisions` no frontmatter.

## Deviations from Plan

### Processo — Tarefa 2 não teve um commit GREEN próprio (RED/GREEN verificado, mas sem novo diff)

- **Encontrado durante:** Tarefa 2, ao preparar o ciclo TDD.
- **O que aconteceu:** Escrevi o `TabelasContratoController.php` inteiro (incluindo `confirmar()`/`descartar()`) numa única passada na Tarefa 1, e o commit `84d118d1` (Tarefa 1) acabou incluindo a implementação da Tarefa 2 também — não só o `index()`.
- **Correção aplicada:** Antes de escrever os testes da Tarefa 2, removi temporariamente `confirmar()`/`descartar()` do controller e as duas rotas POST de `routes/web.php` (sem commitar essa remoção), escrevi `Phase140ConfirmacaoTabelaTest.php`, rodei a suíte e confirmei RED genuíno (14/14 falhando com `RouteNotFoundException`). Commitei SÓ o arquivo de teste (`b4d27f03`, RED). Restaurei os dois métodos e as duas rotas ao estado exato do commit `84d118d1` — `git status --porcelain` confirmou zero diff nesses dois arquivos (implementação idêntica à já commitada). Rodei a suíte de novo: 14/14 GREEN. Como não havia diff para commitar, não existe um commit `feat(140-05)` separado para a Tarefa 2 — a implementação já está em `84d118d1`.
- **Por que não recriei um commit redundante:** Reescrever os mesmos bytes só para ter um commit `feat` separado criaria histórico artificial sem mudança real de conteúdo. O que importa — prova de RED genuíno antes do GREEN — foi preservado.
- **Impacto:** Nenhum funcional. Rastreável: `git log` mostra `84d118d1` (feat, contém index+confirmar+descartar) seguido de `b4d27f03` (test, RED confirmado por remoção temporária e restauração, sem diff residual).

### Nenhum outro desvio

Os dois testes cobrem exatamente os itens do `<behavior>` da Tarefa 2 (9 itens) mais o caso extra de "descartar proposta já conferida" e o guard de permissão — nenhum comportamento foi adicionado ou removido do que o plano pediu.

## TDD Gate Compliance

- Commit `test(...)` (RED): `b4d27f03` — confirmado vermelho (14/14 falhas por rota inexistente) antes de existir qualquer commit de implementação NOVO para a Tarefa 2.
- Commit `feat(...)` (GREEN): a implementação já existia em `84d118d1` (commit da Tarefa 1) — não há commit `feat` posterior porque não havia diff a commitar após restaurar o código idêntico. Ver seção "Deviations" acima para o detalhamento completo do porquê.

## Known Stubs

Nenhum. A tela não tem dado mockado nem prop vazia por padrão — `propostas`/`resumo`/`empresas` sempre vêm resolvidos do banco.

## Threat Flags

Nenhum. As mitigações T-140-19 a T-140-23 do `<threat_model>` do plano foram implementadas literalmente (permissão própria testada, `company_id` obrigatório e validado, unicidade de CNPJ respeitada, `confirmado_por`/`confirmado_em` + `LogsActivity` já herdado dos models, props achatadas sem texto de contrato nem link da Clicksign).

## Verificação (itens 1-4 do plano, medidos)

1. `phpunit --filter="Phase140"` — **117 testes / 516 asserções / 0 falhas**.
2. `phpunit --filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140"` — **465 testes / 2281 asserções / 0 falhas** (baseline era 444/2165 — cresceu exatamente os 7+14 testes desta fase, gate preservado).
3. `grep -n "admin.contratos.tabelas" routes/web.php` — encontrado na linha do comentário que nomeia as três rotas, todas dentro do grupo `permission:admin.contratos`.
4. `npm run build` — concluído; `Admin/TabelasContrato` confirmado no manifest do Vite via script Node; CSS compilado sem nenhuma classe fracionada proibida.

## User Setup Required

Nenhum — nenhum acesso a produção, nenhuma credencial nova. `Http::fake()` não foi necessário (esta tela não chama a API do Clicksign, só lê `contrato_tabela_propostas` já gravada pelo comando do plano 140-04).

## Next Phase Readiness

Aguardando a **Tarefa 3 — checkpoint humano bloqueante** (ver roteiro completo na mensagem de retorno do executor). Esta fase (140) fecha quando o checkpoint for aprovado. Não há próxima fase mapeada além disso no ROADMAP para o tema Clicksign/tabelas de cobrança.

---
*Phase: 140-extrair-tabelas-progressivas-do-clicksign*
*Completed: 2026-09-09*

## Self-Check: PASSED

Os 4 arquivos declarados (controller, página JSX, 2 suítes de teste) foram confirmados no disco. Os 2 commits (`84d118d1`, `b4d27f03`) foram confirmados em `git log --oneline --all`. Nenhum item faltando.
