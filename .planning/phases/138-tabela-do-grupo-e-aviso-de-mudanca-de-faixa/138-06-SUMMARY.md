---
phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
plan: 06
subsystem: fechamento / tela administrativa
tags: [laravel, inertia, react, phpunit, fechamento, faixas-de-faturamento]

# Dependency graph
requires:
  - phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
    plan: "01"
    provides: "GrupoFaixaFaturamento, FechamentoFaixaResolver::paraGrupo()/paraEmpresa() com precedência grupo → empresa → serviço"
  - phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
    plan: "04"
    provides: "faixas_por_grupo, tabela_grupo_nome, tabela_herdada_de_nome nas props de /administrativo/financeiro (ao vivo e congelado)"
provides:
  - "POST/DELETE /administrativo/financeiro/faixas/grupo/{grupo} sob role:admin"
  - "FechamentoController::salvarFaixasGrupo()/removerFaixasGrupo()"
  - "4º ramo em TabelaFaixasSection.jsx exclusivo de linha de grupo, com a herança dita em voz alta"
  - "Encadeamento de faixas_por_grupo por Financeiro.jsx → FechamentoList → FechamentoAccordion → TabelaFaixasSection"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Tabela de grupo é o terceiro caso do mesmo padrão CRUD de faixas (servico → empresa → grupo), sem FormRequest novo — SalvarFaixasFaturamentoRequest reaproveitado como está"
    - "Bloco de UI de grupo é uma CAMADA acima dos três estados por empresa já existentes, não uma substituição — quando o grupo herda, os botões de editar a tabela da empresa/serviço que mais faturou continuam funcionando abaixo do aviso de herança"

key-files:
  created:
    - tests/Feature/Phase138/Phase138FaixasGrupoCrudTest.php
  modified:
    - routes/web.php
    - app/Http/Controllers/FechamentoController.php
    - resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx
    - resources/js/Pages/Admin/Financeiro.jsx

key-decisions:
  - "As duas rotas novas foram inseridas imediatamente depois das rotas de empresa em routes/web.php, mesma ordem e mesmo grupo administrativo (role:admin) — sem rota nova de configuração"
  - "salvarFaixasGrupo()/removerFaixasGrupo() copiam exatamente a forma dos métodos de empresa (transação, delete-then-recreate, back()->with('success')) — nenhuma lógica nova, só o modelo trocado"
  - "O 4º ramo de TabelaFaixasSection.jsx é condicionado por empresa.tipo === 'grupo' (não por tabela_origem), e é renderizado ANTES dos três estados existentes — quando o grupo tem tabela própria (tabela_origem='grupo'), nenhum dos três estados antigos casa (nem 'servico', nem 'propria', nem null), então eles simplesmente não aparecem; quando o grupo herda, os três estados antigos continuam aparecendo abaixo, operando sobre a empresa que mais faturou (empresa.id no payload é o id dela, confirmado no AdminController)"
  - "Prefill de 'Criar tabela do grupo' usa servicoAplicado?.faixas (mesma fonte do Estado 1) — quando a herança vem de tabela PRÓPRIA da empresa-âncora (não do serviço), o formulário abre em branco, mesma limitação de dado já documentada e aceita em TabelaFaixasSection.jsx desde a Fase 137 (backend não expõe as linhas de uma tabela própria de empresa)"
  - "Termo 'âncora' evitado inclusive nos comentários pt-BR do JSX (não só na copy visível) — troquei por 'a empresa do grupo que mais faturou no mês' também no código, e um teste (Phase138FaixasGrupoCrudTest) trava a ausência da palavra no arquivo inteiro, não só no texto renderizado"

requirements-completed: [D-01]

# Metrics
duration: ~90min (inclui a espera do checkpoint humano)
completed: 2026-09-04
---

# Phase 138 Plan 06: Tabela do Grupo e Aviso de Mudança de Faixa — Tela Summary

**CRUD completo da tabela de faixas de um grupo pela tela de fechamento (rotas + `FechamentoController` + bloco em `TabelaFaixasSection.jsx`), com a herança da tabela da empresa-âncora dita em português claro em vez de invisível — fecha D-01 pela ponta da UI.**

## Performance

- **Duration:** ~90 min (inclui o tempo de espera do checkpoint humano, aprovado em produção)
- **Started:** 2026-09-03
- **Completed:** 2026-09-04
- **Tasks:** 4 (3 de código + 1 checkpoint humano bloqueante)
- **Files modified:** 5 (4 arquivos existentes ampliados, 1 teste novo)

## Accomplishments

- `POST/DELETE /administrativo/financeiro/faixas/grupo/{grupo}` (`admin.financeiro.faixas.grupo` /
  `admin.financeiro.faixas.grupo.remover`) sob o mesmo middleware `role:admin` das demais rotas de
  faixas, com route model binding implícito em `CompanyGroup $grupo`.
- `FechamentoController::salvarFaixasGrupo()`/`removerFaixasGrupo()` — mesma disciplina
  all-or-nothing dos métodos de empresa (transação, apaga tudo e recria a partir de
  `SalvarFaixasFaturamentoRequest::validated('faixas')`), guard duplo (`role:admin` do grupo de
  rotas + `authorize()`/`abort_unless`).
- `TabelaFaixasSection.jsx` ganhou o 4º bloco, exclusivo de linha de grupo, com dois casos: (A) grupo
  com tabela própria — selo "Tabela deste grupo", lista somente leitura, "Substituir tabela do grupo"
  e "Voltar a usar a tabela da empresa"; (B) grupo sem tabela própria — frase nomeando a empresa de
  quem a tabela foi herdada (`tabela_herdada_de_nome`) + explicação de por que isso muda sozinho +
  "Criar tabela do grupo". Os três estados por empresa que já existiam continuam intactos, servindo
  para editar a tabela da empresa-âncora quando o grupo herda dela.
- `Financeiro.jsx` encadeia `faixas_por_grupo` por dois níveis (`FechamentoList` →
  `FechamentoAccordion` → `TabelaFaixasSection`), mesmo molde de `faixas_por_servico`, e o rótulo de
  "Composição do grupo" ganhou o terceiro caso ("tabela do grupo"), evitando que um membro com tabela
  própria de grupo apareça rotulado errado como "tabela do serviço".
- Copy sem jargão: nem a tela nem os comentários pt-BR do JSX usam "âncora", "snapshot",
  "competência", "reconsolidação", "rollup" ou "faixa piso" — travado por teste.
- `Phase138FaixasGrupoCrudTest`: 7 testes / 29 asserções cobrindo criar, substituir (all-or-nothing),
  sobreposição recusada pela régua reaproveitada, 403 para não-admin nas duas rotas novas, DELETE
  devolvendo o resolver ao estado de herança, e a trava de arquivo contra regressão silenciosa de UI.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Rotas e CRUD da tabela do grupo** - `745aec1b` (feat)
2. **Tarefa 2: Bloco da tabela do grupo na tela, com a herança dita em voz alta** - `41ef6c91` (feat)
3. **Tarefa 3: Teste de CRUD e de contrato da tela** - `a01cc390` (test)
4. **Tarefa 4: checkpoint humano bloqueante** — sem commit de código (nenhum arquivo alterado); aprovado pelo usuário em 2026-09-04, verificação feita em PRODUÇÃO após deploy do orquestrador (ver seção "Conferência em produção" abaixo)

**Plan metadata:** commit deste arquivo + STATE.md/ROADMAP.md/REQUIREMENTS.md (ver final)

## Files Created/Modified

- `routes/web.php` - duas rotas novas de faixas de grupo, no mesmo grupo administrativo das demais
- `app/Http/Controllers/FechamentoController.php` - `salvarFaixasGrupo()`/`removerFaixasGrupo()`, docblock da classe atualizado com o terceiro caso do CRUD
- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` - 4º ramo condicionado por `empresa.tipo === 'grupo'`, dois diálogos novos (`criar-grupo`/`editar-grupo`), handlers `salvarGrupo()`/`voltarParaEmpresaDoGrupo()`
- `resources/js/Pages/Admin/Financeiro.jsx` - encadeamento de `faixasPorGrupo` por dois componentes intermediários; rótulo "tabela do grupo" na Composição do grupo
- `tests/Feature/Phase138/Phase138FaixasGrupoCrudTest.php` - 7 testes novos

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: nenhuma decisão arquitetural nova — o plano previu
literalmente o encadeamento de props e o molde dos métodos/rotas a espelhar (seção "Precisão que
faltava" do PLAN.md), então a execução seguiu o roteiro sem desvio de Regra 4.

## Deviations from Plan

None — plano executado exatamente como escrito, incluindo o encadeamento de dois níveis de
`faixas_por_grupo` descrito na seção final do PLAN.md ("Precisão que faltava"). O único ajuste feito
foi de disciplina própria (não do plano): reescrevi 3 comentários pt-BR do JSX que originalmente
usavam a palavra "âncora" internamente, para que o próprio arquivo ficasse livre do termo por
completo — não só o texto visível na tela — antes de escrever o teste de trava de arquivo.

## Conferência em produção (checkpoint da Tarefa 4)

O ambiente local tem 0 grupos de empresa cadastrados, o que não permitiria abrir nenhum accordion de
grupo para o teste visual. Por isso o orquestrador fez o deploy da Fase 138 inteira e conduziu a
conferência em produção. Resultado, repassado pelo orquestrador em 2026-09-04:

- Copy da tela lida diretamente do JSX antes da aprovação: a frase nomeia a empresa de quem a tabela
  foi herdada ("Este grupo está usando a tabela da empresa X") e explica a instabilidade ("Quem manda
  é a empresa do grupo que mais faturou no mês — se outra empresa passar na frente, a tabela muda
  junto"). Nenhuma palavra de jargão proibido chega na tela — as duas ocorrências de "competência" no
  arquivo são nome de prop no código (`competenciaFechada`), não texto visível.
- Migrations das Fases 138-01/138-02 rodaram no MariaDB de produção sem erro: tabela
  `grupo_faixas_faturamento` criada (0 linhas — ninguém cadastrou tabela de grupo ainda em produção);
  colunas `notificado_em`/`notificado_faixa_ordem` presentes em `fechamento_snapshots` (201 linhas) e
  `fechamento_grupo_snapshots` (15 linhas).
- 0 notificações de mudança de faixa enviadas até o momento da conferência.
- **Palavra literal de aprovação do usuário:** "Aprovado".

**Deploy e verificação em produção foram feitos pelo orquestrador, não por este executor** — este
plano não rodou `plink`/`pscp`/`deploy.sh` nem tocou `.env`, conforme a trava do próprio plano.

## Observação operacional — primeiro aviso de mudança de faixa

Os 201 snapshots de agosto/2026 em produção estão com `notificado_em` NULL, porque foram gravados
antes de o aviso existir (Fase 138-02/138-05 entraram depois). Consequência esperada, registrada aqui
para não ser lida como bug depois: o **primeiro** "Refazer fechamento" que alguém rodar para
agosto/2026 vai comparar agosto contra julho e disparar o aviso inicial de mudança de faixa para
todas as empresas/grupos que mudaram — mesmo que a mudança real já tenha acontecido há semanas. É um
efeito de "primeira carga", não uma notificação incorreta.

## Verificação

- `route:list --name=financeiro.faixas.grupo`: as duas rotas aparecem sob `role:admin`.
- `Phase138FaixasGrupoCrudTest`: 7 testes / 29 asserções / 0 falhas.
- Gate `--filter="Phase122|Phase136|Phase137|Phase138"`: **276 testes / 1452 asserções / 0 falhas**
  (medido pelo orquestrador após a aprovação do checkpoint, árvore limpa) — sem regressão do baseline
  de 260/1377 medido antes deste plano.
- `npm run build`: sucesso.
- Checkpoint visual aprovado pelo usuário em produção.

## Known Stubs

Nenhum. O cadastro da tabela do grupo é funcional de ponta a ponta: salva em
`grupo_faixas_faturamento`, é lido por `FechamentoFaixaResolver::paraGrupo()` (Fase 138-01),
classificado por `ConsolidarMesFechamento` (Fase 138-03) e exibido pela tela com os dois casos
(própria/herdada). Em produção ainda não existe nenhuma tabela de grupo cadastrada de verdade (0
linhas) — não é stub, é o estado real de uso: ninguém precisou dela ainda.

## Threat Flags

Nenhum novo — a única fronteira de confiança nova
(`navegador (admin) → POST/DELETE .../faixas/grupo/{grupo}`) já está registrada e mitigada no
`<threat_model>` do próprio plano (T-138-16 a T-138-19), sem disposição pendente.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. Deploy e migrations já rodaram em
produção (conduzidos pelo orquestrador, fora do escopo deste executor).

## Next Phase Readiness

- D-01 está fechado: grupo pode ter tabela própria, cadastrável pela tela, com precedência sobre a
  tabela da empresa-âncora e a do serviço, e a herança agora é visível em vez de silenciosa.
- D-02/D-03 (aviso de mudança de faixa) já estavam fechados pelos planos 138-02/138-05, que rodaram
  em paralelo a este.
- Com este plano, todos os 6 planos da Fase 138 estão concluídos — fase pronta para ser marcada como
  completa no ROADMAP.md/STATE.md.

---
*Phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa*
*Completed: 2026-09-04*

## Self-Check: PASSED

- `routes/web.php` (rotas `admin.financeiro.faixas.grupo`/`.remover`) — FOUND (confirmado via `route:list` antes do checkpoint)
- `app/Http/Controllers/FechamentoController.php` (`salvarFaixasGrupo`/`removerFaixasGrupo`) — FOUND
- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` (4º ramo, `tabela_herdada_de_nome`) — FOUND
- `resources/js/Pages/Admin/Financeiro.jsx` (`faixasPorGrupo`, `faixas_por_grupo`) — FOUND
- `tests/Feature/Phase138/Phase138FaixasGrupoCrudTest.php` — FOUND
- Commit `745aec1b` — FOUND
- Commit `41ef6c91` — FOUND
- Commit `a01cc390` — FOUND
