---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
plan: 04
subsystem: admin
tags: [laravel, inertia, react, contratos, admin, tailwind, clicksign]

# Dependency graph
requires:
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 01
    provides: "Permissions::ADMIN_CONTRATOS, resources/js/lib/contratoStatus.js (rótulos/cores/formatação dos 7 estados), colunas de cancelamento solicitado em contrato_assinaturas"
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 03
    provides: "ContratoAdminController::index(), grupo de rotas admin/contratos sob permission:admin.contratos, tela Admin/Contratos.jsx (lista + resumo/filtro)"
provides:
  - "ContratoAdminController::show()/atualizarCadastro()/gerarContrato() — o coração da fase: onde o Administrativo completa cadastro (ADM-01/02) e dispara a geração do contrato (UI-02)"
  - "Rotas admin.contratos.show/cadastro/gerar, sob permission:admin.contratos"
  - "Tela Admin/ContratoDetalhe.jsx — formulário de completar cadastro + bloco de pendências + botão primário, ponto focal por estado (D-03)"
  - "Correção do BLOCKER: gerarContrato() só anuncia sucesso quando resultado.ok===true — nunca quando status='disparado' com configuração da ECF faltando"
  - "Link da linha da lista (Admin/Contratos.jsx) para o detalhe"
affects: [131-05, 131-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Revalidação no servidor do gate de negócio (estaPronta() + avaliar()) mesmo com o botão do client desabilitado — o disabled do client nunca é controle real"
    - "Distinção explícita entre 'status disparado' (o gate decidiu tentar) e 'sucesso real' (resultado.ok===true) — nunca tratar a presença de uma chave como prova de sucesso"
    - "IDOR guard em duas passagens: valida pertencimento de TODOS os itens do payload antes de gravar qualquer um (evita gravação parcial em caso de abort no meio do loop)"
    - "Blindagem de teste contra Observer síncrono (Fase 128): config(['services.clicksign.signatarios_ecf' => []]) no setUp() bloqueia qualquer disparo real de contrato como efeito colateral de salvar Company/ContratoServico dentro da suíte"

key-files:
  created:
    - tests/Feature/Phase131/ContratoAdminDetalheTest.php
    - resources/js/Pages/Admin/ContratoDetalhe.jsx
  modified:
    - app/Http/Controllers/ContratoAdminController.php
    - routes/web.php
    - resources/js/Pages/Admin/Contratos.jsx

key-decisions:
  - "gerarContrato() mapeia o retorno de dispararSeElegivel() em 5 ramos distintos (sucesso real, configuração ECF faltando, outra falha de iniciarParaEmpresa, erro de exceção, status inesperado) — o BLOCKER que a verificação da fase pegou: 'disparado' sozinho NUNCA é tratado como sucesso"
  - "motivo_bloqueio prioriza 'dados_minimos' sobre os demais motivos quando ambos coexistem — é a razão mais fundamental e a única que a lista de faltantes() explica item a item"
  - "IDOR guard em atualizarCadastro() faz uma passagem de validação de pertencimento ANTES de qualquer gravação (nem Company nem ContratoServico), para que um abort no meio do array não deixe gravação parcial"
  - "email_colaborador_pendente e configuracao_ecf_faltante ficam em props PRÓPRIAS, nunca dentro de faltantes — D-11 e a distinção 'pendência da empresa vs. configuração da ECF' são invariantes que o backend impõe, não que a tela decide"

patterns-established:
  - "Placeholder mínimo de página React na task que cria o backend (mesmo padrão do 131-03) — necessário porque o teste Feature precisa do componente resolvido no manifest do Vite antes da tela completa existir"

requirements-completed: [ADM-01, ADM-02, UI-02, UI-06]

# Metrics
duration: ~50min
completed: 2026-08-14
---

# Phase 131 Plan 04: Tela de detalhe da empresa — completar cadastro e gerar contrato Summary

**`ContratoAdminController::show()/atualizarCadastro()/gerarContrato()` + `Admin/ContratoDetalhe.jsx`, com a correção do BLOCKER que a verificação pegou: `gerarContrato()` nunca anuncia "Contrato gerado" quando `dispararSeElegivel()` devolve `status: disparado` mas `resultado.ok` é falso por configuração da ECF faltando.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-14 (sessão única)
- **Completed:** 2026-08-14
- **Tasks:** 3/3
- **Files modified:** 5 (2 criados, 3 modificados)

## Accomplishments

- `ContratoAdminController::show()` monta TODAS as props que `Admin/ContratoDetalhe.jsx` consome sem
  recalcular nada no client: `faltantes` (retorno cru de `ContratoDadosMinimosService::faltantes()`),
  `pode_gerar_contrato` (só `true` quando `estaPronta()` E `avaliar()['status'] === 'elegivel'`),
  `motivo_bloqueio` (`dados_minimos`/`ja_em_andamento`/`aguardando_comercial`/`isento`/`null`),
  `email_colaborador_pendente` (prop PRÓPRIA, D-11 — nunca dentro de `faltantes`),
  `configuracao_ecf_faltante` (prop PRÓPRIA — `.env` da ECF, nunca misturada com pendência da empresa)
  e `contratos` com `signatarios` achatados (`id`/`nome`/`papel`/`situacao` apenas — nunca e-mail/CPF)
- `atualizarCadastro()` (ADM-01) grava CNPJ, e-mail do cliente, nome de quem assina, e-mail do
  colaborador e as datas de início/término por serviço, com guard de IDOR em DUAS passagens (valida
  o pertencimento de TODOS os `ContratoServico` informados antes de gravar qualquer um) — molde
  literal do `ContratoLiberacaoManualController::store()` (T-131-04-01)
- **O BLOCKER corrigido:** `gerarContrato()` revalida no servidor (nunca confia no botão do client) e
  mapeia o retorno de `dispararSeElegivel()` em 5 ramos — só anuncia "Contrato gerado" quando
  `resultado.ok === true`; quando `status === 'disparado'` mas `resultado.configuracao` não vazio
  (configuração dos signatários fixos da ECF faltando), devolve ERRO explicando que a pendência é
  interna da ECF e que o time técnico precisa ser avisado — nunca mente que o contrato foi gerado
- `Admin/ContratoDetalhe.jsx`: página cheia (D-01) com o bloco "Falta completar antes de gerar o
  contrato" + botão desabilitado ADJACENTES quando há pendência (D-03), botão ativo sozinho em
  `ecf-yellow` quando não há (ponto focal por estado, UI-SPEC), formulário de cadastro com o aviso de
  `email_colaborador` que explicitamente NÃO bloqueia o botão (D-11), bloco separado de configuração
  da ECF, e lista de contratos com coluna "Ações" preparada e vazia (reenviar/ajustar/cancelar entram
  no 131-05, liberar manualmente no 131-06— sem rota ainda, sem botão)
- `Admin/Contratos.jsx`: a linha da lista agora abre `admin.contratos.show` — link "Abrir" + linha
  inteira clicável, cor neutra (não `ecf-yellow`, para não competir com o ponto focal do grid de
  resumo/filtro daquela tela)
- `npm run build` verde, as duas páginas confirmadas no manifest do Vite
- `ContratoAdminDetalheTest`: 11 testes cobrindo os 11 casos do plano — incluindo o teste que prova
  o BLOCKER (empresa elegível + `signatarios_ecf` vazio → `session('error')`, nunca `session('success')`,
  `ContratoAssinatura::count() === 0` por reconsulta ao banco) e o teste que trava a D-11 (fica
  vermelho se `email_colaborador` for acrescentado a `faltantes()`)
- Suíte `Phase126|Phase129|Phase130|Phase131` = **326 testes verdes** (era 315 ao fim do 131-03;
  +11 testes novos desta task, zero regressão)

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: show(), atualizarCadastro() e gerarContrato() + as 3 rotas** - `e7430e52` (feat)
2. **Task 2: Tela Admin/ContratoDetalhe.jsx + link da linha da lista** - `a2b2c2c4` (feat)
3. **Task 3: ContratoAdminDetalheTest — props, edição e a regra D-11** - `0722d79a` (test)

**Plan metadata:** commit deste SUMMARY + STATE.md + ROADMAP.md (a seguir)

## Files Created/Modified

- `app/Http/Controllers/ContratoAdminController.php` - `show()`, `atualizarCadastro()`,
  `gerarContrato()` — a lógica de detalhe, edição de cadastro e disparo de geração, com o mapeamento
  de 5 ramos do retorno de `dispararSeElegivel()`
- `routes/web.php` - 3 rotas novas (`admin.contratos.show`/`cadastro`/`gerar`), todas herdando
  `permission:admin.contratos` do grupo, nenhuma `role:admin`
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` - tela completa: cabeçalho, bloco de
  pendências/botão (ponto focal por estado), formulário de cadastro (ADM-01), bloco separado de
  configuração ECF, lista de contratos (ações vazias)
- `resources/js/Pages/Admin/Contratos.jsx` - linha da lista agora linka para
  `admin.contratos.show`
- `tests/Feature/Phase131/ContratoAdminDetalheTest.php` - 11 testes: props do `show()` (casos
  1-4, 10), edição de cadastro + IDOR + validação (casos 5-7), disparo de geração nos dois sentidos
  (casos 8, 9, 11)

## Decisions Made

- Nenhuma decisão de produto nova além das já travadas pelo CONTEXT/UI-SPEC/PATTERNS — este plano
  seguiu D-01/D-03/D-11 literalmente e implementou a correção do BLOCKER exatamente como o plano
  especificou (mapeamento de `resultado.ok`, nunca da presença de `resultado`)
- Decisões técnicas de execução (ver Deviations): placeholder mínimo de `Admin/ContratoDetalhe.jsx`
  na Task 1, correção da fixture de teste para não violar a constraint NOT NULL de
  `data_contratacao`, e blindagem de teste contra o Observer síncrono da Fase 128

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Placeholder mínimo de `Admin/ContratoDetalhe.jsx` criado na Task 1**
- **Found during:** Task 1
- **Issue:** O teste `ContratoAdminDetalheTest` (que a própria Task 1 exige, pela regra "o teste
  nasce na mesma task do código que ele prova") faz `GET` na rota `show` e espera `200`. O
  `Inertia::render('Admin/ContratoDetalhe', ...)` precisa resolver o componente no manifest do
  Vite — que só existiria depois da Task 2 criar e buildar o arquivo. Sem isso, o teste falha com
  `Unable to locate file in Vite manifest` antes mesmo de testar as props que é o objetivo real da
  Task 1. Mesmo padrão já documentado no SUMMARY do 131-03.
- **Fix:** Criado um placeholder mínimo (`AppLayout` + `<main className="p-6" />`, sem lógica) em
  `resources/js/Pages/Admin/ContratoDetalhe.jsx` na Task 1, seguido de `npm run build`. A Task 2
  substituiu o conteúdo pela tela completa no MESMO arquivo (mesmo diff de arquivo, sem duplicação).
- **Files modified:** `resources/js/Pages/Admin/ContratoDetalhe.jsx` (Task 1: criação mínima; Task 2:
  conteúdo completo)
- **Commit:** `e7430e52` (Task 1), substituído em `a2b2c2c4` (Task 2)

**2. [Rule 1 - Bug de fixture] `data_contratacao` NOT NULL rejeitava `null` explícito na fixture de teste**
- **Found during:** Task 1 (durante a escrita do teste)
- **Issue:** O primeiro rascunho da fixture de "empresa incompleta" passava
  `vincularServico($empresa, $servico, ['data_contratacao' => null])` para forçar uma pendência
  extra de data. A coluna `data_contratacao` é `date()` NOT NULL no schema (migration
  `2026_05_26_120002`, sem `->nullable()`), então o `null` explícito quebrava o `INSERT` com
  `QueryException` antes mesmo de o teste rodar — nada a ver com o código de produção sendo testado.
- **Fix:** Removida a sobrescrita — a fixture já ficava incompleta o suficiente só com
  `cnpj`/`email_cliente`/`nome_contato` nulos na `Company`, sem precisar também quebrar o
  `ContratoServico`. (Documentado no projeto: string vazia `''`, não `null`, é o jeito correto de
  representar "sem data" nessa coluna — ver `ContratoDadosMinimosTest::contrato_ativo_sem_data_contratacao_reprova_com_servico_id`
  da Fase 127, que já cobre esse caso à parte.)
- **Files modified:** `tests/Feature/Phase131/ContratoAdminDetalheTest.php`
- **Commit:** `e7430e52`

**3. [Rule 2 - Missing Critical] Blindagem de teste contra o Observer síncrono da Fase 128**
- **Found during:** Task 1 (análise antes de escrever o teste, não descoberta por falha)
- **Issue:** `CompanyGatilhoContratoObserver::updated()` e `ContratoServicoGatilhoObserver::updated()`
  (Fase 128) chamam `GatilhoContratoAdministrativoService::dispararSeElegivel()` SINCRONAMENTE
  (fora de `DB::afterCommit()`) sempre que `email_cliente`/`cnpj`/`nome_contato` (Company) ou
  `ativo`/`valor_contratado`/`data_contratacao`/`servico_id` (ContratoServico) mudam. Como
  `atualizarCadastro()` desta task salva exatamente esses campos, um teste que deixasse a empresa
  completa e elegível poderia disparar um `ContratoAssinatura` real como efeito colateral do
  Observer — não do que o próprio teste estava medindo — se `signatarios_ecf` do ambiente de teste
  tivesse, por acaso, valor herdado de um `.env` local.
- **Fix:** `setUp()` da suíte agora faz `config(['services.clicksign.signatarios_ecf' => []])` por
  padrão, blindando qualquer disparo automático via Observer. Cada teste que precisa do caminho
  feliz (`gerarContrato()`, caso 9) sobrescreve esse config explicitamente, no molde já estabelecido
  por `ContratoClicksignServiceTest`/`ConfiguracaoEcfBloqueiaTest` da Fase 127.
- **Files modified:** `tests/Feature/Phase131/ContratoAdminDetalheTest.php`
- **Commit:** `e7430e52`

---

**Total deviations:** 3 (1 Rule 3 de placeholder, 1 Rule 1 de fixture, 1 Rule 2 de blindagem de
teste)
**Impact on plan:** Nenhuma mudança de escopo de produto. As três são ajustes de execução/teste —
nenhuma delas altera o comportamento do `ContratoAdminController` além do que o plano já especificava.

## Issues Encountered

Nenhum bloqueio real. As três situações acima foram resolvidas dentro da própria execução, sem
precisar de decisão do usuário.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ContratoAdminController` pronto para receber `reenviar()`/`ajustar()`/`registrarCancelamento()`
  no plano 131-05 — a coluna "Ações" da lista de contratos em `Admin/ContratoDetalhe.jsx` está
  deliberadamente vazia, com comentário apontando para onde cada ação entra
- A prop `contratos[].signatarios` já sai achatada (`id`/`nome`/`papel`/`situacao`) — o plano 131-05
  consome a MESMA prop, sem precisar que `show()` mude de forma
- `configuracao_ecf_faltante` já é prop própria e testada — qualquer tela futura que precise avisar
  sobre configuração da ECF reusa a mesma fonte (`ContratoDadosMinimosService::faltantesDaConfiguracaoEcf()`)
- O padrão de mapeamento de retorno em ramos explícitos (`resultado.ok` como única prova de sucesso)
  fica disponível como referência para qualquer ação futura que também dependa de
  `ContratoClicksignService`

Nenhum bloqueio identificado para os próximos planos.

---
*Phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os arquivos criados/modificados confirmados em disco e os 3 commits de task (`e7430e52`,
`a2b2c2c4`, `0722d79a`) confirmados em `git log --oneline --all`.
