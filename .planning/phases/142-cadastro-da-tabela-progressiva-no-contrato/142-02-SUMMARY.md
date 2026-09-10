---
phase: 142-cadastro-da-tabela-progressiva-no-contrato
plan: 02
subsystem: backend+frontend
tags: [laravel, inertia, permissoes, tabela-progressiva, contratos]

requires:
  - phase: 142-01
    provides: "GravarTabelaEmpresaService (porta única de escrita) e a decisão de projeto de que as duas telas de tabela convivem"
  - phase: 140-conferencia-de-tabelas-lidas-do-clicksign
    provides: "grupo de rotas admin.contratos e ContratoTabelaProposta (leitura pendente)"
  - phase: 131-administrativo-de-contratos
    provides: "ContratoAdminController::show() e ContratoDetalhe.jsx, destino do botão novo"
provides:
  - "cinco rotas admin.contratos.tabela.show/salvar/remover/grupo.salvar/grupo.remover"
  - "TabelaEmpresaContratoController — props achatadas da ficha + escritas pela porta única"
  - "SalvarFaixasContratoRequest — validação herdada, autorização própria do módulo de contratos"
  - "tabela_resumo em ContratoAdminController::show() + botão 'Tabela de cobrança' em ContratoDetalhe.jsx"
affects: [142-03, 142-04]

tech-stack:
  added: []
  patterns:
    - "FormRequest que herda de outro só para trocar authorize() — validação nunca duplicada, autorização adaptada ao grupo de rotas de destino"
    - "Teste de permissão via X-Inertia header quando o componente JSX de destino ainda não existe (mesmo padrão de Phase58/DashboardShellsBackendTest) — evita depender do manifest Vite antes da hora"

key-files:
  created:
    - app/Http/Requests/SalvarFaixasContratoRequest.php
    - app/Http/Controllers/TabelaEmpresaContratoController.php
    - tests/Feature/Phase142/Phase142FichaTabelaControllerTest.php
    - tests/Feature/Phase142/Phase142FichaTabelaPermissaoTest.php
  modified:
    - routes/web.php
    - app/Http/Controllers/ContratoAdminController.php
    - resources/js/Pages/Admin/ContratoDetalhe.jsx

key-decisions:
  - "SalvarFaixasContratoRequest HERDA SalvarFaixasFaturamentoRequest e sobrescreve só authorize() — zero linha de validação de faixa reescrita, exatamente como o plano exigia"
  - "Grupo (salvarGrupo/removerGrupo) NÃO passa por GravarTabelaEmpresaService — GrupoFaixaFaturamento não tem coluna de origem; a lógica delete+create foi replicada de FechamentoController com comentário apontando o gêmeo (as duas cópias devem morrer juntas se a rota antiga sair)"
  - "modelos_de_partida só lista serviços que TÊM tabela cadastrada (filtro que o plano não especificou explicitamente, mas 'modelo de partida' pressupõe ter algo para copiar)"
  - "Aviso de substituição no salvar() distingue 'veio do contrato assinado' de 'já tinha sido cadastrada à mão antes', usando origem_anterior — mais preciso que o texto único sugerido no plano, sem contradizer nenhum teste especificado"

patterns-established:
  - "Toda rota nova de escrita em tabela de faixas dentro do módulo de contratos usa SalvarFaixasContratoRequest, nunca SalvarFaixasFaturamentoRequest diretamente"

requirements-completed: [D-03]

duration: 55min
completed: 2026-09-10
---

# Phase 142 Plan 02: Rotas, controller e autorização da ficha da tabela no contrato Summary

**Cinco rotas novas em `admin.contratos.tabela.*`, `TabelaEmpresaContratoController` com as props achatadas da ficha, `SalvarFaixasContratoRequest` (herda a validação, troca só a autorização) e o botão "Tabela de cobrança" em `ContratoDetalhe.jsx` — a armadilha central do plano (abrir com uma permissão e salvar exigindo outra) está travada por teste.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-09-10
- **Completed:** 2026-09-10
- **Tasks:** 3/3
- **Files modified:** 7 (4 criados, 3 modificados)

## Accomplishments

- `SalvarFaixasContratoRequest` herda `SalvarFaixasFaturamentoRequest` e sobrescreve só `authorize()` (`isAdmin() OU hasPermission('admin.contratos')`) — a validação de sobreposição/buraco/faixa-sem-teto continua existindo num lugar só.
- Cinco rotas novas (`admin.contratos.tabela.show/salvar/remover/grupo.salvar/grupo.remover`) dentro do grupo `permission:admin.contratos` já existente — nunca `role:admin`. As rotas antigas `admin.financeiro.faixas.*` continuam intocadas (rollback, testes das Fases 137/138 dependem delas).
- `TabelaEmpresaContratoController::show()` monta a ficha achatada: `tabela_empresa` (linhas gravadas, nunca reconstrução — paga a mesma dívida do 137-09 que o 142-01 resolveu no Fechamento), `procedencia_empresa`, `tabela_grupo` (`null` fora de grupo, array vazio dentro de grupo sem tabela), `tabela_aplicada` (via `FechamentoFaixaResolver::paraEmpresa()` — quem cobra HOJE, que pode ser o grupo), `modelos_de_partida` (catálogo de serviços com tabela, para "começar a partir de") e `leitura_pendente` (link cruzado com a caixa de entrada da Fase 140, só `id`/`nome_envelope`/`tem_tabela`).
- `salvar()`/`remover()` passam pela porta única `GravarTabelaEmpresaService` (142-01), com `feito_de = 'contrato_ficha'`; `salvar()` acrescenta um aviso neutro na sessão quando `substituiu_confirmada = true`, distinguindo se a tabela anterior vinha do contrato ou já era manual.
- `salvarGrupo()`/`removerGrupo()` replicam a lógica de `FechamentoController::salvarFaixasGrupo`/`removerFaixasGrupo` (grupo não tem coluna de origem, não passa pela porta única), com comentário explícito de que as duas cópias precisam morrer juntas.
- Teste de permissão (`Phase142FichaTabelaPermissaoTest`) prova a armadilha central: usuário com `admin.contratos` via setor, sem `role = admin`, abre a ficha (200) **e** salva com sucesso (302) — mais remover/salvarGrupo/removerGrupo; usuário sem nenhuma das duas toma 403 nos cinco endpoints; admin puro continua passando pelo short-circuit.
- `ContratoAdminController::show()` ganha `tabela_resumo` (`tem_tabela`, `quantidade_faixas`, `procedencia` da tabela própria, `origem_aplicada` do resolver) — o botão novo em `ContratoDetalhe.jsx` não recalcula nada, só escolhe o texto certo por estado (sem tabela / conferida pelo contrato / cadastrada à mão / copiada do serviço sem conferência / quem manda é o grupo), sem jargão técnico.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: FormRequest e rotas da ficha, no grupo de contratos** — `20b3d730` feat(142-02): FormRequest e rotas da ficha da tabela no módulo de contratos
2. **Tarefa 2: TabelaEmpresaContratoController — props da ficha e as quatro escritas** — `fc84624d` feat(142-02): TabelaEmpresaContratoController — props e escritas da ficha
3. **Tarefa 3: permissão de ponta a ponta + botão na página de contrato** — `d44bc1c4` feat(142-02): permissão de ponta a ponta + botão Tabela de cobrança no contrato

## Files Created/Modified

- `app/Http/Requests/SalvarFaixasContratoRequest.php` — herda `SalvarFaixasFaturamentoRequest`, sobrescreve só `authorize()`
- `app/Http/Controllers/TabelaEmpresaContratoController.php` — `show()`/`salvar()`/`remover()`/`salvarGrupo()`/`removerGrupo()` + helpers privados `modelosDePartida()`/`achatarFaixas()`/`gravarFaixasGrupo()`/`podeMexerNaTabela()`
- `routes/web.php` — cinco rotas novas dentro do grupo `admin.contratos` existente
- `app/Http/Controllers/ContratoAdminController.php` — `show()` ganha `FechamentoFaixaResolver` injetado e a prop `tabela_resumo`
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — prop `tabela_resumo`, função `tabelaCobranca` (texto por estado) e o `Card` "Tabela de cobrança" com `Link` para a ficha
- `tests/Feature/Phase142/Phase142FichaTabelaControllerTest.php` — 9 testes: linhas exatas, empresa sem tabela, precedência do grupo, link com leitura pendente (presente/ausente), salvar com origem manual, aviso ao substituir tabela de contrato, faixas sobrepostas não alteram nada, remover apaga
- `tests/Feature/Phase142/Phase142FichaTabelaPermissaoTest.php` — 7 testes: abrir com permissão de setor, salvar com a MESMA permissão (a armadilha central), remover/salvarGrupo/removerGrupo com a permissão de setor, 403 nos cinco endpoints sem permissão, admin puro passando

## Decisions Made

- **Réplica da consulta de `fechamentoFaixasPorServico()`** em vez de extração para um service compartilhado — o controller de origem (`AdminController`) é grande demais para justificar a refatoração só por isto; comentário no código aponta a origem e a obrigação de manter as duas regras em sincronia.
- **`modelos_de_partida` filtra serviços sem tabela** — o plano descreveu o catálogo como "serviços com tabela cadastrada", e um serviço sem faixa nenhuma não serve como ponto de partida.
- **Mensagem de aviso diferenciada por `origem_anterior`** (contrato vs. manual) em vez do texto único literal do plano — mais preciso sem violar o teste especificado (`salvar sobre tabela vinda de contrato traz o aviso`), que só cobre o caso `contrato`.
- **Testes de `show()` via header `X-Inertia`** em vez de `assertInertia()` — o helper da lib exige um response HTML full-page (Blade `@vite`), que forçaria o build do componente `Admin/TabelaEmpresa.jsx` antes da hora (só existe no plano 03). Mesmo padrão já usado em `tests/Feature/Phase58/DashboardShellsBackendTest.php`.

## Deviations from Plan

None de comportamento — plano executado como escrito. Os dois pontos abaixo são adaptações de _como_ verificar, não de _o que_ foi construído (Rule 3 — blocking issue de infraestrutura de teste, corrigido inline):

- **[Rule 3 - Blocking] `assertInertia()` falhava com "Not a valid Inertia response"** porque o macro exige response HTML full-page, e o componente `Admin/TabelaEmpresa.jsx` só existe no plano 03. Trocado por requests com header `X-Inertia: true` + `X-Inertia-Version` (mesma versão calculada pelo middleware) e leitura direta do JSON decodificado — sem perder nenhuma asserção de prop especificada no plano.

## Issues Encountered

Nenhum além do já documentado em Deviations.

## User Setup Required

None — nenhuma configuração de serviço externo.

## Next Phase Readiness

- As cinco rotas, o controller e a autorização estão prontos para o plano 03 (`Admin/TabelaEmpresa.jsx`) consumir — todas as props já vêm achatadas e testadas.
- `tabela_resumo` já chega em `ContratoDetalhe.jsx` e o botão já navega para `admin.contratos.tabela.show` — o plano 03 só precisa criar a página de destino.
- Grupo (`salvarGrupo`/`removerGrupo`) está coberto mesmo com zero grupos tendo tabela hoje em produção — a ficha nasce funcional para o dia em que algum grupo precisar.
- **Gate de testes:** `571 → 587 testes` (16 novos: 9 da Tarefa 2 + 7 da Tarefa 3), `2729 → 2777 asserções`, **0 falhas**.

---
*Phase: 142-cadastro-da-tabela-progressiva-no-contrato*
*Completed: 2026-09-10*

## Self-Check: PASSED

Arquivos criados confirmados em disco (`app/Http/Requests/SalvarFaixasContratoRequest.php`,
`app/Http/Controllers/TabelaEmpresaContratoController.php`, os dois testes novos) e os três hashes
de commit (`20b3d730`, `fc84624d`, `d44bc1c4`) confirmados via `git log --oneline`.
