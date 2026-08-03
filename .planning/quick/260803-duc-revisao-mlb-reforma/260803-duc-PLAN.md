---
quick_id: 260803-duc
slug: revisao-mlb-reforma
date: 2026-08-03
status: em-execucao
---

# Quick 260803-duc — Reforma do módulo de Revisão MLB

## Problema

A tela `/mlb/revisao` não serve nem o líder (que revisa) nem o gestor (que supervisiona):

1. **`revisado` é um booleano sem autoria** — não existe quem revisou nem quando. O gestor
   não tem como saber o que o líder revisou. O filtro de pessoas da tela filtra por quem
   *publicou*, não por quem *revisou*.
2. **"Revisado" não é veredicto** — o gestor leu "revisado" como "está correto", mas revisar
   não garante que o anúncio esteja certo. Falta o resultado da revisão.
3. **Falta metade da autoria do problema** — existe (no WIP) `problema_resolvido_por`, mas
   nunca existiu `problema_por`: não se sabe quem abriu.
4. **Sem ciclo de reconferência** — o publicador "resolve" e acabou; o líder nunca confirma
   se a correção ficou boa.
5. **Nomenclatura duplicada** — "problema" designa duas entidades (anúncio e conta);
   "resolvido" designa dois eventos; "comentário"/"feedback" são a mesma coisa com dois nomes.

## Decisões travadas (usuário, 2026-08-03)

- **Unificar** "problema" e "comentário" numa entidade única: **pendência** com severidade
  (`bloqueio` | `ajuste` | `observacao`).
- **Uma tela com modos**: `/mlb/revisao` com abas **Fila** (líder) e **Supervisão** (gestor).
- **Vocabulário dos estados**: `Não revisado` → **`Aprovado`** / **`Em ajuste`** / **`Reconferir`**.
  A palavra "Revisado" deixa de ser estado.

## Máquina de estados

```
nao_revisado ──┬── líder aprova ──────────────→ aprovado
               └── líder abre pendência ──────→ em_ajuste   (bola: publicador)
                                                    │
                          publicador marca corrigida ↓
                                                reconferir  (bola: líder)
                                                    │
                              ┌─────────────────────┴──────────────────┐
                         líder confirma                          líder reabre
                              ↓                                       ↓
                          aprovado                                em_ajuste
```

`aprovado` → `em_ajuste` também é possível (líder reabre depois de aprovar).

## Tarefas

### T1 — Estrutura de dados
- Migration `create_mlb_revisoes_e_pendencias`:
  - `mlb_revisoes` — log de transições (publicacao_id, revisor_id, de_status, para_status, observacao)
  - `mlb_pendencias` — severidade, categoria, texto, aberta_por/em, status, corrigida_por/em, resolvida_por/em
  - cache em `mlb_publicacoes`: `status_revisao`, `revisado_por`, `revisado_em`, `pendencias_abertas`
- Colunas de status como `string`, **não enum** (enum já causou 500 em produção no NPS).
- Models `Revisao` e `Pendencia` + relações em `Publicacao`.

### T2 — Backfill
- Converte `comentario` → pendência `observacao`; `problema` → pendência `bloqueio`.
- Recupera a autoria histórica que nunca foi gravada a partir de `activity_log`
  (`log_name='mlb'`, casando `properties->mlb_code`).
- Deriva `status_revisao` do par (`revisado`, pendências abertas).
- Gera `mlb_revisoes` sintéticas para as revisões já existentes, para a Supervisão nascer com histórico.

### T3 — RevisaoService + endpoints
- Serviço com as transições; **dual-write** nas colunas antigas (`problema`, `comentario`, …)
  para não quebrar Histórico, Empresas e o score enquanto a transição não é concluída.
- Endpoints: aprovar, abrir pendência, marcar corrigida, resolver, reabrir, reverter, aprovar em lote.
- Permissões: líder/gestor/admin revisam; publicador dono marca corrigida.

### T4 — Controller
- `revisao()` reescrito com dois modos. Corrigir junto:
  - filtro de cargo quebrado (`whereHas('cargos')` vê o catálogo do setor → usar o pivot)
  - KPIs contados no banco, não sobre os 120 carregados; paginação real
  - `MlbEmpresa::get()` com projeção e pareamento por `mlb_empresa_id`
  - respostas parciais no lugar de recarga total a cada clique

### T5 — Tela nova
- `Revisao.jsx` reescrito do zero: aba Fila (lista densa, lote, pendência inline) e
  aba Supervisão (cobertura por líder, aging de pendências, tempo de ciclo, categorias).

### T6 — Integrações
- `PublicadorScoreService`: eixo Qualidade passa a ler pendências.
- `MeuPainel`: seção de problemas passa a listar pendências com o botão "Corrigi".

### T7 — Fechamento
- Rodar migrations, `npm run build`, SUMMARY.md, STATE.md.

## Fora de escopo

- Deploy (exige autorização explícita do usuário).
- Remoção das colunas legadas de `mlb_publicacoes` — ficam por segurança até validação em produção.
- "Problema da conta" (`mlb_empresas.problema`) — renomear para "ocorrência da conta" fica para depois.

## Notas de risco

- WIP anterior preservado em `git stash@{0}` ("wip revisao pre-reforma (260803-duc)").
- MySQL local estava parado no início da execução — subir antes de migrar.
