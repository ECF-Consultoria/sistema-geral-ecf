---
phase: 154-distribuicao-pela-coordenacao-v23-0
tipo: decisoes-de-desenho
data: 2026-09-10
requirements: [DISTRIB-01, DISTRIB-02, DISTRIB-03, DISTRIB-04, RESP-01, RESP-02]
---

# Fase 154 — decisões de desenho, escritas ANTES do código

Fase conduzida como **trabalho direto** (`CLAUDE.md` → "GSD por RISCO"). O gate de GSD obrigatório
seria disparado por "migration que altere tabela existente **com dado em produção**" — e a D-C
abaixo é justamente o que evita isso: **esta fase não tem migration nenhuma**.

## O que já existe (medido no banco, não suposto)

| Peça | Situação |
|---|---|
| `company_users` (pivot) | `role` já é `enum('consultor','estrategista','analista')` — as duas funções desta fase já existem |
| `company_users.servico_id` | **100% preenchido**: 287/287 `consultor`, 1/1 `estrategista`. O vínculo é por serviço na prática |
| `company_etapa_transicoes` | grava `user_id` + `created_at` a cada transição — quem e quando, de graça |
| `Company::ETAPA_AGUARDANDO_ONBOARDING` | constante já definida na Fase 150 |
| `users.active` | existe (`tinyint(1)`) |
| Cargo canônico | `user_setores → cargos.slug` ∈ {`analista`,`estrategista`}, a fonte que `User::cargoDesempenhoSlug()` já usa |

## D-A — Um par por EMPRESA, replicado nos serviços ativos

Decisão do usuário. A Coordenação escolhe **um** analista e **um** estrategista; o sistema grava
uma linha em `company_users` para **cada serviço ativo** da empresa.

Por quê assim e não `servico_id` nulo: 100% das 288 linhas existentes têm serviço preenchido.
Gravar nulo divergiria do acervo inteiro e quebraria consulta que assume o campo. E por que não um
par por serviço: o Success Criteria pede "um clique"; a estrutura permite divergir por serviço
depois, se um dia precisarem.

## D-B — Elegibilidade: prefere o setor do serviço, cai para todos quando vazio

Decisão do usuário, tomada **depois** de uma medição que contradisse a premissa inicial.

**O que foi medido:** `servicos.setor` e `setores.slug` não casam.

| Setor do serviço | Empresas ativas | Setor existe? | Users com o cargo lá |
|---|---|---|---|
| `performance` | **9** (o maior) | ❌ não existe | — |
| `polos` | 6 | ✅ | 1 analista |
| `publicacao` | 2 | ✅ | **0** |
| `outros` | 1 | ❌ não existe | — |
| `shopee` | 0 | ✅ | **0** |

Os cargos com gente de verdade estão quase todos no setor `desenvolvimento` (4 analistas, 5
estrategistas), para onde nenhum serviço aponta. **Filtro estrito deixaria o seletor vazio para
praticamente toda empresa** — a fase nasceria inutilizável. O gap de `performance` é estrutural
(não existe linha em `setores` com esse slug), não buraco de dado local.

**Regra final:**

1. Resolve o setor pelo `slug` == `servicos.setor` dos serviços ativos da empresa.
2. Se resolveu **e** há colaborador ativo com o cargo ali, lista **só** esses.
3. Senão, lista **todo** colaborador ativo com o cargo, em qualquer setor — e a tela **diz por quê**
   ("nenhum analista no setor Publicação — mostrando todos").

O aviso não é enfeite: select que muda de universo em silêncio faz o coordenador achar que aquele é
o time daquele serviço. Melhora sozinho conforme os cargos forem sendo cadastrados nos setores
certos.

## D-C — A auditoria reusa `company_etapa_transicoes`; ZERO migration

Decisão do usuário. "O coordenador que distribuiu, data e horário" (DISTRIB-03) é exatamente o que a
linha de transição 5→6 já grava: `user_id`, `created_at`, `etapa_anterior`, `etapa_nova`.

Consequências, todas desejadas:

- **Nenhuma migration.** A fase não toca `companies` (~500 linhas em produção) nem a pivot, então
  não dispara o gate de GSD obrigatório do `CLAUDE.md`.
- **A Fase 156 (SLA) recebe o dado de graça** — foi para isso que a Fase 150 criou a tabela em vez
  de usar o activity log, que é podado em 365 dias (`config/activitylog.php`).
- Redistribuição futura não tem histórico próprio. Fora de escopo hoje, declarado.

## D-D — A fila deriva da ETAPA, não reconfere contrato

DISTRIB-01 pede "concluíram o Administrativo, têm contrato assinado e ainda não têm responsáveis".

A fila é: **`etapa == aguardando_distribuicao`** (5) **e** sem linha `analista`/`estrategista` na
pivot. As duas primeiras condições **não** são reconferidas: a empresa só chega à etapa 5 passando
pelo FINALIZAR da Fase 152, que exige o checklist completo **e** o contrato assinado.

Não é atalho — é a máquina de estados ser a fonte única. Reconferir "contrato assinado" ao vivo faria
um contrato cancelado depois **sumir a empresa da fila no meio da distribuição**, sem ninguém
entender por quê. A etapa é a palavra da máquina; o resto é derivação.

## D-E — Distribuir = pivot primeiro, transição depois

Ordem, e por quê:

1. Valida tudo (empresa na etapa certa, os dois users elegíveis).
2. Grava os vínculos na pivot, dentro da **própria** transação.
3. Chama `EtapaTransicaoService::transicionar()` para 5→6.

⚠️ **Nunca envolver `transicionar()` num `DB::transaction()` externo** — o service já é transacional
com `lockForUpdate()` por dentro (mesma proibição da Fase 152). Por isso a pivot tem transação
própria, e a transição vem depois, fora dela.

Se a transição for recusada, os vínculos ficam gravados sem a etapa ter mudado. É aceito e
**declarado**: a empresa continua na fila, e distribuir de novo é idempotente (a pivot usa
`updateOrCreate` por `company_id`+`servico_id`+`role`). O inverso — etapa movida sem responsável —
seria pior: a empresa sumiria da fila sem ter sido distribuída.

## D-F — RESP-01/RESP-02 derivam, não ganham coluna

- **"Aparece em Minhas Empresas"** (RESP-01): consequência automática do vínculo na pivot. Nenhum
  código novo de listagem — é o mesmo `company_users` que a carteira já lê.
- **"Novo cliente"** (RESP-02): derivado da linha de transição 5→6 recente (janela de 14 dias).
  Sem coluna `is_novo`, que precisaria de alguém para desligar e ficaria acesa para sempre.
- **"Onboarding pendente"** (RESP-02): derivado de `etapa ∈ {aguardando_onboarding,
  onboarding_andamento}`. A Fase 155 é dona do onboarding em si; aqui só se lê a etapa.

## D-G — Permissão

Chave própria **`coordenacao.distribuir`**, acrescentada ao catálogo de `Permissions`.

É a única chave nova de toda a milestone até aqui, e é justificada: distribuir empresa é ato de
Coordenação, não de Administrativo (`admin.contratos`) nem de Comercial (`comercial.entrada`).
Reusar uma daquelas daria à Entrada o poder de escolher o time — que é exatamente a separação que o
§10 do PDF estabelece.

Fora de `role:admin`, pelo mesmo motivo já documentado em `admin.contratos` e `comercial.entrada`:
liberável por setor sem deploy.

## Fora de escopo, declarado

- **Redistribuir** (trocar responsável depois). A fase entrega a primeira distribuição.
- **Notificar** analista/estrategista. RESP-01 diz "aparece em Minhas Empresas", não "recebe aviso".
- O **onboarding** em si — é a Fase 155.
