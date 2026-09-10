---
phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 10
subsystem: verificacao
tags: [checklist-administrativo, fluxo-entrada, gate-humano, regressao, fechamento-de-fase]

# Dependency graph
requires:
  - phase: 139-09
    provides: "a tela — sem ela não há o que conferir no navegador"
  - phase: 139-01
    provides: "139-BASELINE-TESTES.md — o 'antes' contra o qual a regressão final é comparada"
provides:
  - ".planning/phases/139-.../139-REGRESSAO-FINAL.md — o 'depois', com comparação nominal"
  - "Fase 139 COMPLETA: os 6 requirements ADMIN-01..06 fechados e marcados em REQUIREMENTS-v23.md"
affects: [140, 141, 142, 143]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate humano conferido contra o BANCO, não contra o relato da tela: o histórico de company_etapa_transicoes é a evidência, e foi ele que mostrou que o FINALIZAR tinha funcionado quando a tela exibia uma recusa"
    - "Mensagem de recusa não é sinônimo de falha — a trava de duplicidade da máquina de estados produz texto de erro no caminho FELIZ (segundo clique), e distinguir os dois casos exige olhar o histórico, não a mensagem"

key-files:
  created:
    - .planning/phases/139-checklist-administrativo-trava-de-finaliza-o-v23-0/139-REGRESSAO-FINAL.md
  modified:
    - .planning/REQUIREMENTS-v23.md

key-decisions:
  - "Task 1: regressão final verde — fase 92/364; regressão 131+137+138 245/910, com a parte 137/138 número por número idêntica à baseline (123/444). Comparação nominal: zero falhas contra zero falhas"
  - "Migration [106] Ran e o índice cai_company_chave_unique conferido por SHOW INDEX no MariaDB — o erro 1059 não deixou a tabela sem a trava de duplicidade"
  - "Tasks 2 e 3 APROVADAS pelo usuário, com toda asserção reconferida por reconsulta ao banco (nunca por stdout da tela)"
  - "O caminho da D-16 (contrato_assinado fechando por ContratoLiberacao, sem envelope assinado) NÃO foi exercitado visualmente — o item foi fechado pela fonte primária (assinado_em no envelope), porque o usuário não tinha dado real de liberação para preencher o modal sem inventar. Segue coberto por ContratoAssinadoPorLiberacaoTest. Declarado, não silenciado"
  - "As 2 empresas e o 1 usuário criados como fixture do gate PERMANECEM no MariaDB local — são a evidência do gate. Como remover está escrito abaixo, para não repetir o caso do usuário de review da Shopee que ficou em produção porque ninguém anotou"

requirements-completed: [ADMIN-01, ADMIN-02, ADMIN-03, ADMIN-04, ADMIN-05, ADMIN-06]

# Metrics
duration: ~45min (Task 1 automatizada + 2 gates humanos)
completed: 2026-09-10
---

# Phase 139 Plan 10: fechamento da fase Summary

**A regressão final fecha idêntica à baseline, e os dois checkpoints humanos foram aprovados — com o histórico de `company_etapa_transicoes` provando os quatro degraus da D-15 disparando um a um em uso real, cada um com linha própria.**

## Performance

- **Duration:** ~45 min
- **Completed:** 2026-09-10
- **Tasks:** 3/3 (1 automatizada, 2 gates humanos aprovados)
- **Files modified:** 2

## Task 1 — regressão final (automatizada)

| Suíte | Resultado | Contra a baseline |
|---|---|---|
| `tests/Unit/Phase139` + `tests/Feature/Phase139` | **92 testes / 364 assertions**, exit 0 | — (fase nova) |
| `Phase137` + `Phase138` + `Phase131` | **245 / 910**, exit 0 | a parte 137/138 fecha **123/444**, número por número **idêntica** |

Comparação **nominal**: a baseline não registra nenhum teste falho, e a execução final também não produziu nenhum. Zero contra zero — nenhuma falha "conhecida" sendo carregada, nenhuma falha nova sendo relatada como herdada.

`Phase131` entrou no comando porque o plano 139-08 tirou `admin.contratos.show` do grupo de permissão; os 122 testes daquela fase seguem verdes, `ContratoAdminPermissaoTest` incluído.

Migration `2026_09_09_170000_create_checklist_administrativo_itens_table` **[106] Ran**, e o índice único composto `cai_company_chave_unique` **existe** sobre (`company_id`, `chave`) — conferido por `SHOW INDEX` contra o MariaDB, não por `migrate:status` (que não veria a diferença).

Detalhe em `139-REGRESSAO-FINAL.md`.

## Tasks 2 e 3 — gates humanos APROVADOS

### O que o usuário exercitou, e o que o banco provou

O histórico de `company_etapa_transicoes` da empresa 418 registrou os **quatro** degraus, cada um
com linha própria:

| # | Transição | Hora | Disparado por |
|---|---|---|---|
| 31 | 1 → 2 | 10:10:19 | marcação manual de "Contrato revisado" na tela |
| 33 | 2 → 3 | 10:34:38 | abertura da ficha após `enviado_em` ser gravado |
| 34 | 3 → 4 | 10:37:14 | abertura da ficha após `assinado_em` ser gravado |
| 35 | 4 → 5 | 10:37:18 | **clique no FINALIZAR** |

Os degraus #33 e #34 são a prova mais valiosa do gate: nenhuma ação de checklist aconteceu neles —
a etapa avançou porque **abrir a ficha observa o evento externo**, exatamente o que a D-15 previa e
o que motivou `show()` a sincronizar (plano 139-08).

### Critérios confirmados

- **Botão desabilitado sempre acompanhado da frase** — `podeFinalizar()` devolveu
  `requisito_faltante = "O contrato ainda não está assinado."`
- **A frase muda de contagem para contrato** — a empresa isenta 419 exibia "Faltam N de 6 itens";
  a 418, com só o contrato pendente, exibia a frase de CONTRATO. É o caso especial implementado no
  plano 139-06.
- **O botão habilita no instante certo** — `permitido` virou `true` somente após `assinado_em`, com
  o progresso indo a 9/9.
- **Empresa em Aguardando Distribuição** — `companies.etapa = aguardando_distribuicao`, por
  reconsulta.
- **Saiu da listagem Entrada, continua na Contrato** — confirmado por consulta com o mesmo recorte
  de cada listagem. É a decisão D-06 da Fase 138 (a listagem Contrato **não** tem corte por etapa) se
  comportando como especificado.
- **Nenhum cadastro novo** (ADMIN-06) — mesma linha, mesmo CNPJ `13.913.913/0001-39`, sem duplicata.
- **D-07 com dado real** — a empresa isenta 419 instanciou **6 itens, todos do grupo Entrada**.
  Nenhum item de Contrato existe para ela: não é item marcado "não aplicável", é item que nem nasce.
- **D-05 com dado real** — em 419, `grant_consultoria_ml` permaneceu **aberto** mesmo depois de o
  botão "Copiar link de autorização" ser usado. O item só fecha quando o cliente **conecta**, nunca
  quando o link é gerado. Era exatamente a confusão que a D-05 existe para impedir.
- **D-14 com dado real** — `conexao_ecf_gerada` fechou pelo botão "Gerar conexão" na 419.

### O susto que não era bug

O usuário reportou a mensagem **"A empresa já está na etapa 'aguardando_distribuicao'"** e leu como
falha. O banco mostrou o contrário: a transição #35 já tinha acontecido, e a mensagem veio de uma
**segunda** tentativa — a trava de duplicidade da máquina de estados recusando corretamente. Não
nasceu linha #36.

**Lição que vale além desta fase:** neste sistema, texto de erro na tela aparece no caminho FELIZ
(clique repetido), e a única forma de distinguir "falhou" de "já tinha dado certo" é o histórico de
transições. Diagnosticar por mensagem teria produzido uma investigação de bug inexistente — ou pior,
uma "correção" que afrouxaria a trava.

## Deviations from Plan

### 1. O caminho da D-16 não foi exercitado visualmente — declarado, não silenciado

O plano oferecia dois caminhos para fechar "Contrato assinado" no passo 3: gravar `assinado_em` no
envelope, **ou** registrar liberação manual pela própria tela. A liberação manual exercitaria a
**D-16** (o item fecha por `ContratoLiberacao::existeParaServico()`, sem envelope assinado), que é a
segunda fonte do item 3.

O usuário informou não ter dado real de liberação para preencher o modal — e preencher com motivo
inventado gravaria no histórico de liberações uma justificativa falsa, que é o tipo de registro que
a própria milestone proíbe (D-14). Optou-se pela fonte primária.

**Consequência declarada:** o ramo D-16 segue coberto **apenas** por
`ContratoAssinadoPorLiberacaoTest` (automatizado), sem conferência visual. Não é lacuna de
implementação — é lacuna de *verificação humana*, e fica escrita aqui em vez de a fase se declarar
100% conferida na tela.

### 2. Ambiente: `ASSET_URL` do worktree derrubava a conferência antes de ela começar

A primeira tentativa de abrir `http://127.0.0.1:8139` devolveu **tela branca sem erro**.

Causa medida: o `.env` deste worktree tinha `ASSET_URL=http://localhost/ecf_fluxo_entrada/public`
(caminho do Apache). O `artisan serve` na 8139 servia HTML correto, mas com `<script src>` apontando
para a **porta 80** — e o Apache do XAMPP não estava rodando (`curl` devolveu `HTTP 000`). Assets não
carregam, o React não monta, a página fica branca **sem nada no HTML denunciando o problema**.

Correção: `ASSET_URL` **vazio**, para o Laravel derivar a URL do request atual — funciona no
`artisan serve` e pelo Apache, sem precisar alternar. Backup do original em `.env.bak-139`. O `.env`
é ignorado pelo git; nada disso entra em commit.

É reincidência de uma armadilha já conhecida de worktree novo, agora medida nesta fase.

**Total deviations:** 0 mudanças de comportamento do produto; 1 lacuna de verificação declarada;
1 correção de ambiente.

## Fixtures do gate — permanecem no MariaDB local

Criados para viabilizar a conferência e **mantidos** como evidência:

| O quê | Id | Observação |
|---|---|---|
| Empresa `ZZ Conferência 139 (com contrato)` | **418** | hoje em `aguardando_distribuicao`, com 4 linhas de histórico |
| Empresa `ZZ Conferência 139 (isenta)` | **419** | serviço Polos, 6 itens, em `administrativo_andamento` |
| Usuário `entrada139@ecfconsultoria.com.br` | **50** | senha `Entrada@139`, setor `conferencia-entrada-139`, só `comercial.entrada` |

**São locais** — MariaDB `ecf_admin` desta máquina, nada em produção. Para remover quando não forem
mais úteis: apagar as linhas de `checklist_administrativo_itens`, `company_etapa_transicoes`,
`contrato_assinaturas`, `ml_tokens`, `onboarding_links` e `contratos_servico` das empresas 418/419,
depois as próprias empresas; e o usuário 50 junto do setor `conferencia-entrada-139` e sua
`SetorPermissao`.

Isto está escrito porque o precedente custou caro: o usuário de review da Shopee (`users.id=30`)
segue ativo **em produção** desde 16/07 porque ninguém anotou que precisava sair.

## Fase 139 — COMPLETA

**10 de 10 planos executados.** Os 6 requirements `ADMIN-01..06` estão fechados e marcados em
`REQUIREMENTS-v23.md` — o único ponto da fase em que os checkboxes são tocados, seguindo o
precedente estabelecido nos planos anteriores (a camada de serviço existir não fecha requirement; o
comportamento fim-a-fim fecha).

⚠️ **NADA DEPLOYADO.** Tudo permanece na branch `feat/fluxo-entrada-empresas`, no worktree
`C:\xampp\htdocs\ecf_fluxo_entrada`. A fase acrescenta **uma** migration
(`checklist_administrativo_itens`) que ainda não existe em produção.

## Next Phase Readiness

- Próxima fase da milestone: **140** — a mensagem de boas-vindas. Ela segue dona do motor da
  mensagem; a 139 entregou apenas o **item de checklist** (`boas_vindas_enviada`, manual). Essa
  separação de risco foi decidida na Fase 138 e continua valendo.
- O que a 140 herda pronto: `ChecklistAdministrativoService` para marcar o item, o funil
  `executarMutacaoChecklist()` como o lugar por onde qualquer endpoint novo de checklist deve passar
  (senão a D-15 morre em silêncio), e `LinhaChecklistItem.jsx` como o molde da linha.
- **Proibição herdada:** não injetar `ChecklistEtapaSincronizadorService` dentro de
  `ChecklistAdministrativoService` nem de `FinalizarEntradaAdministrativaService` — o caso 10 de
  `ChecklistDirigeEtapaTest` falha se alguém fizer isso, e a falha é intencional.

---
*Phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-10*

## Self-Check: PASSED

- FOUND: `139-REGRESSAO-FINAL.md`
- CONFIRMADO: `REQUIREMENTS-v23.md` com ADMIN-01..06 marcados `[x]`
- CONFIRMADO: `companies.etapa = aguardando_distribuicao` na empresa 418, por reconsulta ao banco
- CONFIRMADO: 4 linhas em `company_etapa_transicoes` para a 418, uma por degrau
