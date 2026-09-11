# Phase 150: Máquina de estados — os 9 status de `companies.etapa` (v23.0) - Discussion Log

> **Trilha de auditoria apenas.** Não usar como entrada para agentes de pesquisa,
> planejamento ou execução. As decisões estão no CONTEXT.md — este log preserva as
> alternativas consideradas e o caminho da conversa.

**Date:** 2026-09-01
**Phase:** 137-Máquina de estados — os 9 status de `companies.etapa` (v23.0)
**Areas discussed:** nenhuma — o usuário pediu verificação de entendimento do fluxo e
delegou todas as decisões de implementação

---

## Nota de ambiente

O comando foi invocado em `C:\xampp\htdocs\ecf_admin`, cujo `ROADMAP.md` termina na Fase
136 — `init.phase-op` devolveu `phase_found: false`. A Fase 150 vive no worktree
`C:\xampp\htdocs\ecf_fluxo_entrada` (branch `feat/fluxo-entrada-empresas`), que é onde a
milestone v23.0 foi aberta. Toda a discussão e os artefatos rodaram lá.

---

## Rodada 1 — seleção de áreas cinzentas

Quatro áreas foram propostas depois do scout do código:

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Backfill das ~500 empresas | Quem não satisfaz o cálculo atual fica sem etapa, entra na 9, ou recebe etapa deduzida por evidência? | |
| Pendência: campo ou derivada | Já existem 2 listas de pendência derivadas; a nova é declarada, agregada, ou as duas? | |
| Fronteira do serviço de transição | O serviço lê o gate da v22.0 ou só anda por chamada explícita? Etapa volta atrás? | |
| Onde entra o filtro por etapa | `/companies` (Performance) ou `/comercial/empresas/listagem`? | |

**Resposta do usuário (texto livre):** *"quero saber se vc entendeu o fluxo da empresa
chegar no onboarding"*

**Notas:** pedido de verificação de compreensão antes de qualquer decisão. Nenhuma área foi
selecionada nesta rodada; a seleção foi refeita depois da checagem.

### Todo cruzado

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Deixar fora | Fechar as 2 rotas que criam `MlbEmpresa` fora do router é decisão de negócio, não extensão da máquina de estados | ✓ |
| Dobrar na Fase 150 | Tratar as 2 rotas como parte do ponto único de escrita | |

**User's choice:** Deixar fora
**Notes:** registrado em `Reviewed Todos` do CONTEXT.md. Segue como todo solto, sem vínculo
de fase — o mesmo estado em que a abertura da milestone o deixou, de propósito.

---

## Rodada 2 — verificação do fluxo

Claude rastreou os 8 passos do "Fluxo operacional resumido" do PDF contra o código do
worktree e apresentou o mapa etapa × estado existente, mais três colisões:

1. **Distribuir já é "em operação"** — o derivado `analista OU estrategista`
   (`CompanyController.php:227`) dispara no mesmo passo em que a Coordenação distribui
   (§7), atropelando as etapas 6, 7 e 8.
2. **O onboarding não espera o Administrativo** — `ContratoServicoObserver.php:37` cria o
   `Onboarding` em `rascunho` no instante em que o contrato nasce.
3. **Onboarding é por serviço, etapa é por empresa** — e há dois lugares guardando
   analista/estrategista (pivô `company_users` × `onboardings.responsavel_*_id`).

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Bate, seguir | As 3 colisões estão corretas e são o problema a resolver | ✓ |
| Bate, mas falta coisa | Certo, mas falta passo ou regra do fluxo | |
| Tem erro — vou corrigir | Algum passo descrito errado | |

**User's choice:** Bate, seguir
**Notes:** valida as três colisões como **enunciado do problema**, não só como observação.
Reproduzidas em `<specifics>` do CONTEXT.md junto com o mapa etapa × estado.

---

## Rodada 2 — seleção de áreas (reformulada)

As mesmas áreas, reescritas com o que o rastreamento mostrou (a de backfill virou "backfill
e a colisão da etapa 9"; entrou "etapa por empresa vs onboarding por serviço" no lugar de
"onde entra o filtro"):

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Backfill e a colisão da etapa 9 | Empresa já distribuída com onboarding em andamento: etapa 9 literal ou etapa do estado real? | |
| Etapa por empresa vs onboarding por serviço | Empresa com 2 serviços tem 2 onboardings — manda o mais atrasado, o mais adiantado, ou todos? | |
| Pendência: campo ou derivada | Declarada à mão, agregação do que já se calcula, ou as duas? | |
| Fronteira do serviço de transição | Lê estado externo e sincroniza, ou só anda por chamada explícita? Volta atrás? | |

**User's choice:** *"nenhuma"* (texto livre)
**Notes:** delegação explícita depois de confirmar a leitura do fluxo. Todas as decisões
passaram para discricionariedade de Claude, com o raciocínio de cada uma escrito no
CONTEXT.md.

---

## Claude's Discretion

Todas as 23 decisões (D-01 a D-23) do CONTEXT.md. O usuário não selecionou nenhuma área e
respondeu "nenhuma" à seleção reformulada, depois de confirmar o entendimento do fluxo.

O CONTEXT.md separa o que o planejamento pode revisitar do que não pode:

- **Livre:** onde a pendência mora fisicamente (colunas × tabela); nome da classe do serviço
  e do método de registro; `activitylog` × tabela dedicada para o histórico; forma do
  componente de filtro.
- **Travado (mudar exige voltar ao usuário):** os 9 valores e a ordem (D-01); backfill em
  dois baldes sem etapa intermediária (D-04, D-05); ponto único de escrita (D-12);
  pendência declarada e não derivada (D-17); "manda o onboarding mais atrasado" (D-09).

Alternativas consideradas e **recusadas**, com o motivo, para não serem re-propostas:

- **Deduzir etapa 2..8 no backfill a partir de `ContratoAssinatura`/`Onboarding.status`** —
  recusada: quebra o Success Criteria nº 1, afirma histórico que não aconteceu, e produz
  duração fictícia no painel de SLA da Fase 156.
- **Etapa derivada de estado externo em vez de gravada** — recusada: HIST-03 precisa do
  instante da transição registrado, não recalculado.
- **Pendência como agregação das duas listas derivadas existentes** — recusada: faria o
  sinalizador se mover sozinho quando dado alheio mudasse, o modo de falha que
  `painel-polos-status-e-meta.md` §1 documenta.
- **Proibir retrocesso de etapa** — recusada: um FINALIZAR clicado por engano viraria
  problema irreversível de dado de produção com cliente real.
- **Filtro por etapa no cliente, como o `em_operacao` de hoje** — recusada: obrigaria a
  replicar o fallback derivado em JS.

## Deferred Ideas

- Múltiplas pendências simultâneas por empresa (D-18 trava uma por vez)
- Matriz de permissão por papel nas transições — Fases 151/139/141
- Reprocessar o legado para etapas intermediárias — decisão de negócio própria
- Painel de SLA agregado — já em Future Requirements do REQUIREMENTS-v23
- Fechar a v22.0 (Fase 133 / plano `133-05`, abertos desde 19/08) — outra milestone
- `260818-portas-extras-criam-mlbempresa-fora-do-router.md` — revisado e deixado fora por
  decisão do usuário
