---
phase: 156-historico-e-sla-v23-0
tipo: decisoes-de-desenho
data: 2026-09-10
requirements: [HIST-01, HIST-02, HIST-03]
---

# Fase 156 — decisões de desenho, escritas ANTES do código

Última fase da v23.0. Trabalho direto, **sem migration**: a timeline é leitura agregada de dado que
as fases 150-155 já gravam. Nenhuma tabela nova — se esta fase precisasse de uma, seria sinal de que
alguma fase anterior não registrou o que devia.

## D-A — Só fontes DURÁVEIS; o `activity_log` fica de fora

Decisão do usuário.

**Medido:** `config/activitylog.php` tem `delete_records_older_than_days => 365`. Foi exatamente por
isso que a Fase 150 criou `company_etapa_transicoes` em vez de usar o log — está escrito no D-16
daquela fase.

Usar o log aqui reintroduziria o problema pela porta dos fundos: a timeline de uma empresa antiga
**mudaria sozinha** com a poda, e o SLA do HIST-03 mediria períodos que somem. As três fontes são:

| Fonte | O que dá | Dura para sempre? |
|---|---|---|
| `company_etapa_transicoes` | as transições de etapa, com ator e instante | ✅ tabela própria, nunca podada |
| `contrato_assinaturas` | `enviado_em`, `assinado_em` | ✅ dado de negócio |
| `company_users` | vínculo de analista/estrategista, `created_at` | ✅ dado de negócio |

## D-B — Os 7 eventos do §12, e de onde cada um sai

Conferido um a um contra o que existe hoje. **Nenhum precisa de código novo de gravação** — todos já
são registrados pelas fases anteriores.

| §12 | Fonte | Ator |
|---|---|---|
| 1. Recebida do HubSpot | transição com `etapa_anterior` NULL → `aguardando_administrativo` | a conta de sistema do webhook (Fase 151, D-17) |
| 2. Contrato enviado | `contrato_assinaturas.enviado_em` | — (evento do Clicksign, não de pessoa) |
| 3. Contrato assinado | `contrato_assinaturas.assinado_em` | — (idem) |
| 4. Administrativo concluído | transição → `administrativo_concluido` | quem operou o checklist (Fase 152) |
| 5. Analista definido | `company_users` role=`analista` | **não registrado** — ver D-C |
| 6. Estrategista definido | `company_users` role=`estrategista` | **não registrado** — ver D-C |
| 7. Enviada para onboarding | transição → `aguardando_onboarding` | o coordenador que distribuiu (Fase 154) |

⚠️ **O evento 3 tem uma segunda fonte.** A D-16 da Fase 152 estabeleceu que "contrato assinado"
também fecha por `ContratoLiberacao` (liberação manual, sem envelope assinado). A timeline lê as
duas, senão uma empresa liberada manualmente apareceria sem o evento 3 — e o SLA a mostraria presa
numa etapa que ela já venceu.

## D-C — Autoria que a origem não guarda fica EXPLÍCITA, nunca inventada

Decisão do usuário. `company_users` não tem coluna de "quem atribuiu".

Os eventos 5 e 6 aparecem com data e ação, e o campo de usuário vem **`null` com rótulo próprio na
tela** ("não registrado"), em vez de ser preenchido pelo coordenador da transição 5→6 mais próxima.

Razão medida: existem **287 vínculos `consultor` e 1 `estrategista`** criados antes da Fase 154, que
não nasceram de distribuição nenhuma. Atribuí-los a um coordenador seria o histórico falso que a
D-14 desta milestone proíbe — e é justamente esta timeline que alguém vai usar para cobrar alguém.

O evento 7, logo ao lado, mostra o coordenador de verdade. Quem lê a tela junta os dois sem que o
sistema precise mentir.

## D-D — HIST-03: a duração sai da diferença entre transições consecutivas

Nenhuma coluna de duração é gravada. Para cada par de transições consecutivas em
`company_etapa_transicoes`, a duração da etapa anterior é `created_at` da seguinte menos o da atual.
A etapa corrente (última transição) tem duração **em aberto**, medida contra `now()`.

Duas consequências, ambas declaradas:

- **Empresa legada (`etapa` NULL) não tem timeline de etapa.** Ela nunca teve transição gravada.
  Aparecem só os eventos de contrato, se houver. É honesto: inventar retroativo é o que o backfill
  da Fase 150 deliberadamente **não** fez (`carimbarBackfill()` grava sem histórico exatamente para
  não fabricar duração fictícia — está escrito no teste daquela fase).
- **A etapa 8 (`onboarding_concluido`) vai medir ~0**, porque a D-C da Fase 155 a atravessa no mesmo
  ato. É correto: ela é um marco, não um período de trabalho.

## D-E — Onde aparece, e com que permissão

Na ficha única `admin.contratos.show` (`Admin/ContratoDetalhe`), abaixo do checklist.

Reusa a permissão em **OR** que a D-17 da Fase 152 já estabeleceu (`admin.contratos` OU
`comercial.entrada`) — **nenhuma chave nova**. Quem acompanha o fluxo de entrada já está nessa tela.

⚠️ **A timeline NÃO é recortada por permissão de módulo.** Diferente da seção Contrato, ela não expõe
dado contratual sensível: são datas, nomes de etapa e quem agiu. Os eventos 2 e 3 dizem *que* o
contrato foi enviado/assinado e *quando* — não trazem envelope, signatário nem valor. Se um dia
trouxerem, o recorte volta a ser necessário.

## Fora de escopo, declarado

- **Painel de SLA agregado** (tempo médio por etapa, ranking de gargalo) — o próprio
  `REQUIREMENTS-v23.md` o lista em Future Requirements. O HIST-03 entrega o dado **por empresa**.
- Exportar a timeline.
- Editar ou apagar evento — histórico não se edita.
