---
phase: 138
slug: tabela-do-grupo-e-aviso-de-mudanca-de-faixa
created: 2026-09-03
origem: uso real do fechamento de agosto/2026, no mesmo dia do deploy da Fase 137
---

# Fase 138 — Contexto

Duas lacunas que **só o uso revelou**, no dia em que a Fase 137 foi para produção e agosto/2026 foi
fechado. Não são regressões: são coisas que ninguém tinha como saber antes de rodar com dado real.

---

## D-01 — Grupo passa a ter tabela própria, com precedência

**Estado atual (medido no código, 2026-09-03).** O grupo NÃO tem tabela. Ele é classificado pela
tabela da **empresa-âncora**: o membro que mais faturou na competência, com desempate por menor id
(`ConsolidarMesFechamento`, ~linha 271, `$ancora = $membros->sort(...)->first()`).

Dois defeitos disso:

1. Um grupo que negociou tabela própria **não tem onde registrá-la**.
2. Se as empresas-irmãs têm tabelas diferentes, **quem manda muda de mês para mês** conforme quem
   faturou mais — silenciosamente, sem nada na tela indicando a troca.

**Decisão do usuário (2026-09-03):** criar tabela de grupo, cadastrável pela tela, com precedência:

```
1. tabela própria do GRUPO      <- NOVA (esta fase)
2. tabela própria da EMPRESA    <- já existe: empresa_faixas_faturamento
3. tabela do SERVIÇO (padrão)   <- já existe: servico_faixas_faturamento
```

**Reusar, não reescrever:** `FechamentoFaixaResolver` (`paraEmpresa()` / `classificar()`),
`FechamentoRollupService`, `FechamentoSnapshotWriter`, `FechamentoController` (já tem o CRUD de
faixas por serviço e por empresa, e o `SalvarFaixasFaturamentoRequest` com a regra de sobreposição),
e `TabelaFaixasSection.jsx`. A tabela de grupo é o terceiro caso do mesmo padrão, não um padrão novo.

> ⚠️ Quando a tabela do grupo **não** existir, o comportamento de âncora continua — mas a tela
> precisa dizer de qual empresa a tabela foi herdada. Herança invisível foi metade do problema.

---

## D-02 — Aviso de mudança de faixa para os admins

**O dado já é calculado.** O snapshot grava `evolucao` comparando a competência com a anterior
(visto no payload de `fechamento_reconsolidacoes.snapshot_anterior`). Falta apenas notificar a partir
dele — não há cálculo novo nesta fase.

**Decisão do usuário (2026-09-03): avisar nos DOIS sentidos, subida e queda.** O usuário pediu
inicialmente só a subida; ao ver que **cair de faixa significa cobrar menos**, escolheu os dois.
Queda é justamente o que ninguém percebe sozinho.

**Fora de escopo por decisão:** entrar/sair de `A DEFINIR`. Com 74 empresas hoje sem faixa, viraria
ruído no primeiro disparo.

### Infraestrutura que JÁ EXISTE — usar, não recriar

Medido em 2026-09-03:

| peça | onde |
|---|---|
| tabela | `notifications` (migration `2026_05_21_100001`) |
| classe base | `app/Notifications/BaseNotification.php` |
| enum de categoria | `app/Notifications/Categoria.php` |
| sino na interface | `NotificationBell` em `AppLayout.jsx` |
| controller | `NotificacaoController` |
| limpeza | `NotificationsCleanup` |

**O análogo mais próximo é `MetaAtingidaNotification`** — notificação automática, sem autor
(`autorUserId: null`), disparada por job após o cálculo. Copiar a forma dela.

**Destinatários:** o padrão do projeto é `User::where('role','admin')->get()` +
`Notification::send(...)` (ver `CalculateGoalResults`, ~linha 99).

**Categoria nova exige três coisas juntas**, e o docblock do enum diz isso explicitamente: novo
`case` no enum + subclasse de Notification + label na UI. Não adicionar categoria no banco.

---

## D-03 — Idempotência do aviso (a armadilha desta fase)

⚠️ **`fechamento:consolidar-mes` roda de novo a cada "Refazer fechamento".** Sem trava, todo refazer
re-notifica todos os admins sobre as mesmas mudanças de faixa.

Isso não é hipotético: em 2026-09-03 o usuário clicou "Refazer" **três vezes** em poucos segundos
(porque a tela não dava confirmação — corrigido no quick `260903-la4`). Com notificação e sem trava,
teriam sido três avalanches de aviso para todos os admins.

**O projeto já tem o padrão pronto:** as metas usam uma coluna `notificado_em` no próprio registro de
resultado, gravada logo após o dispatch, e o disparo é condicionado a `notificado_em IS NULL`
(migrations `2026_05_21_2000{01,02,03}`). Aplicar o mesmo em `fechamento_snapshots`.

Decisão a tomar no planejamento: se o **refazer** deve ou não re-notificar quando a faixa **mudou de
novo** em relação ao que já foi avisado. Um refazer que corrige um erro real pode legitimamente
produzir um aviso novo — mas um refazer que não muda nada não pode produzir aviso nenhum.

---

## Estado medido em produção (2026-09-03, agosto/2026 fechado)

| item | valor |
|---|---|
| empresas no snapshot | 201 |
| — `ok` | 127 |
| — `sem_integracao` | 69 |
| — `sem_tabela` | 4 |
| — `sem_faturamento` | 1 |
| grupos | 15 (0 divergências entre soma das empresas e snapshot do grupo) |
| empresas na faixa aberta | 1 |
| faixas cadastradas | 25 (Gestão 7, Brigada 7, Shopee 11) |

---

## Restrições permanentes do projeto

- Copy e comentários em **pt-BR**, **sem jargão** na interface — quem lê é o time Administrativo.
  Nada de "snapshot", "competência", "reconsolidação", "rollup" na tela.
- Árvore compartilhada com outro dev: nunca `git add -A` / `git add .` / `git commit -a` /
  `git stash`.
- Deploy só com autorização explícita do usuário.
- Gate atual: `--filter="Phase122|Phase136|Phase137"` em **241 testes / 1220 asserções / 0 falhas**.
