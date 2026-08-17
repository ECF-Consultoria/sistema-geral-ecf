# Onboarding — decisão de schema do fluxo guiado (acessos → mapeamento → agendamento)

> Escrito em 2026-08-17, ANTES de qualquer migration, conforme CLAUDE.md
> ("Migration é difícil de desfazer; o desenho da tabela vai escrito antes de existir").
> Continua o que a Fase 135 entregou. Não reabre a 135 — soma em cima dela.
>
> **Estado de partida (verificado no banco local, não suposto):** 4 onboardings, o mais
> recente na `DefinicaoOnboarding::VERSAO = 5` com 15 passos; 4 links públicos; 6 contratos
> ativos de Gestão **sem** onboarding. `origin/main` não tem nenhum arquivo de Onboarding —
> nada disto está em produção, o que torna todo backfill barato agora e caro depois.

---

## 1. Decisões de negócio já travadas

| # | Decisão | Quem decidiu |
|---|---|---|
| N-01 | Os **4 itens de "Configuração de acessos"** são do cliente: colaborador ML, planilha ADMAN, Grant Consultoria e Grant Sistema ECF. Todos `dono=cliente` | usuário, 2026-08-17 |
| N-02 | "Medalha" são **duas coisas separadas**: a MercadoLíder da conta do cliente (`power_seller_status`) e a do programa de parceiros (`Company::activeGrant`). Cada uma com seu campo e seu nome | usuário, 2026-08-17 |
| N-03 | **Pontuação do Full é entrada manual.** Não existe endpoint público — a API de fulfillment só expõe estoque por `inventory_id` | pesquisa 2026-08-17 |
| N-04 | **Objetivos para a próxima medalha são calculados**, não digitados: `seller_reputation.metrics` (já baixado hoje e descartado) comparado contra régua fixa em código | pesquisa 2026-08-17 |
| N-05 | O cliente **confirma** o apurado; o que ele confirma e por qual canal fica registrado | usuário, 2026-08-17 |

**Ainda em aberto (não bloqueia o que vem abaixo):** `grant_de_ads` continua `dono=interno`.
Ele aparece na lista "Custos e acessos" do pedido, mas não estava entre os 4 acessos da decisão
N-01. Mudar depois é uma linha em `DefinicaoOnboarding` — não mexe em schema.

---

## 2. O que muda no schema

### 2.1 `onboarding_passos.etapa` — coluna nova

```
etapa  varchar(20)  NULL  (após `ordem`)
```

Catálogo fechado em `OnboardingPasso::ETAPAS`: `acessos` · `mapeamento` · `agendamento` ·
`administrativo`.

**Por que coluna e não só código:** `etapa` é ESTRUTURAL — decide em que bloco o passo aparece
e em que ordem o cliente encontra as coisas. Segue a mesma regra de `dono`/`sla_dias`/`depende_de`:
é COPIADA da definição no nascimento por `OnboardingEngineService::montarPassos()`. É isso que
faz um deploy de definição nova não reorganizar a tela debaixo de quem já está no meio do
onboarding. Um passo de versão antiga cuja chave saiu do código continua sabendo a que bloco
pertence.

**Por que `NULL` e não `NOT NULL`:** as 61 linhas de passo que já existem no local nasceram sem
o conceito. O backfill do §4 preenche todas, mas a coluna fica nullable para que uma linha órfã
de versão futura nunca derrube um `INSERT` — o front trata `null` como "outros".

**Sem enum** — varchar + constante em PHP. Enum em migration exige branch de SQLite e quebra os
testes (learnings §6).

### 2.2 `instrucao` — NÃO vai para o banco

Fica em `DefinicaoOnboarding`, consultada por `chave` na hora de montar o payload.

**Por quê, já que `etapa` vai:** instrução é TEXTO, não estrutura. Corrigir uma frase confusa ou
um passo-a-passo errado precisa alcançar **quem já está no meio do onboarding** — é exatamente
o cliente travado por não entender que se beneficia da correção. Congelar a instrução no
nascimento significaria que quem mais precisa da correção nunca a recebe. `passosDoCliente()`
já agrupa por `chave`, então a busca por chave é o caminho natural. Chave sem instrução em
código → `null`, e o portal simplesmente não renderiza a linha (comportamento de hoje).

### 2.3 Agendamento — 4 colunas em `onboardings`, sem tabela nova

```
reuniao_status         varchar(20)  NULL   -- solicitada | agendada
reuniao_solicitada_em  timestamp    NULL   -- quando o CLIENTE pediu
reuniao_agendada_para  datetime     NULL   -- data e hora combinadas
reuniao_agendada_por   bigint       NULL   -- FK users, nullOnDelete (exige nullable — learnings §6)
```

**Correção aplicada na implementação (2026-08-17):** este desenho previa três
estados, com `realizada` no fim. Ao implementar ficou claro que o terceiro
**duplicaria o passo** `reuniao_realizada`, que já responde "aconteceu?" com
`feito_em` e `feito_por` — exatamente a duplicação de verdade que o §2.4 abaixo
proíbe para o apurado. Ficaram **dois** estados; "realizada" é lido do passo.

**Por que em `onboardings` e não em tabela própria:** é UMA reunião de onboarding por onboarding,
com ciclo de vida curto (pedir → marcar → realizar). Tabela própria só se pagaria com histórico
de remarcações, que ninguém pediu. Se um dia precisar, a migração é aditiva e os dados de hoje
viram a primeira linha.

**Por que não dentro de `onboarding_passos.valor` (JSON):** a data precisa ser CONSULTÁVEL — "quais
reuniões de onboarding desta semana?" é a primeira pergunta que vão fazer. JSON em MariaDB não
indexa para esse tipo de filtro. Data de compromisso é dado de primeira classe, não anotação.

O passo `agendar_reuniao_onboarding` continua existindo e continua sendo o que trava a etapa;
estas colunas são o CONTEÚDO dele. `reuniao_status = realizada` não fecha o passo sozinho —
quem fecha passo é o motor, pela regra que já existe.

### 2.4 `onboarding_mapeamentos` — tabela nova, uma linha por onboarding

```
id                bigint  PK
onboarding_id     bigint  UNIQUE, FK onboardings, cascadeOnDelete
full_pontuacao    smallint      NULL   -- 0..100, o único campo sem API (N-03)
confirmado_em     timestamp     NULL
confirmado_por    bigint        NULL   -- FK users, nullOnDelete; preenchido SÓ quando é interno em call
confirmado_canal  varchar(20)   NULL   -- cliente_portal | interno_call
observacoes       text          NULL
timestamps
```

Nome do índice `onboarding_mapeamentos_onboarding_id_unique` = 43 caracteres, abaixo do limite de
64 (learnings §6).

**O que esta tabela NÃO é:** ela não é a volta da `onboarding_fichas`, dropada em 14/08
(`2026_08_14_160000_drop_onboarding_fichas_table.php`) quando o negócio inverteu a premissa. A
ficha antiga eram 7 campos digitados à mão. Esta tabela guarda **um** campo digitado — o único
que a API comprovadamente não entrega — mais o registro de quem confirmou o apurado.

**O apurado NÃO é copiado para cá.** Faturamento, marketplace, Full, reputação e medalhas
continuam vivendo em `onboarding_passos.valor` do passo `metricas_da_conta`, com uma fonte só.
Copiar criaria duas versões da verdade e a pergunta "qual delas está certa?" — que é exatamente
o tipo de dúvida que custa caro seis meses depois.

**`confirmado_canal` importa** porque muda a confiabilidade do dado: cliente confirmando sozinho
pelo portal ≠ alguém da equipe confirmando por ele numa call. Isso vai ser perguntado.

### 2.5 `onboarding_passos.valor` do passo `metricas_da_conta` — shape novo

Não é mudança de schema (a coluna já é JSON), mas é contrato e precisa estar escrito:

| Chave | Origem | Situação |
|---|---|---|
| `nickname` | `fetchUserInfo()` | já existe |
| `reputacao.level_id` | idem | já existe |
| `reputacao.power_seller_status` | idem | já existe |
| **`medalha_conta`** | `power_seller_status` traduzido | **novo** — a MercadoLíder (N-02) |
| **`medalha_parceiro`** | `Company::activeGrant` | **renomeia** o bloco `programa`/`iniciativa`/`parceiro` de hoje (N-02) |
| **`reputacao.metrics`** | `seller_reputation.metrics` | **novo** — claims, cancellations, delayed_handling_time (rate+value+period) |
| **`reputacao.transactions`** | idem | **novo** — completed/canceled/total |
| **`proxima_medalha`** | régua em código × `metrics` | **novo** (N-04) |
| `full` | `tags` | já existe |
| `faturamento_3_meses` | Adman | já existe |
| `nao_obtidos` | derivado | já existe |

Renomear chave quebraria a leitura de linha antiga — **aceitável agora**: existem 4 linhas, todas
de demonstração, nenhuma em produção. O código de leitura ainda assim tolera as duas formas, para
não explodir num `valor` antigo.

---

## 3. O que muda em código (sem schema)

- **`DefinicaoOnboarding::VERSAO` → 6.** `planilha_custos_adman` e `grant_consultoria_adman`
  passam de `dono=sistema` para `dono=cliente` (N-01). **`auto_fonte` não muda** — o sistema
  continua detectando sozinho. É D-19 em ação: `dono` responde "de quem é a bola", `auto_fonte`
  responde "como o sistema sabe". Muda quem vê no portal e para quem o SLA aponta, não como se
  verifica.

- **`OnboardingLinkService::ACAO_INSTRUCAO` — ação nova, quarta do catálogo.** Sem ela os dois
  passos acima cairiam em `ACAO_NENHUMA`, que renderiza *"Nosso sistema verifica isso sozinho —
  você não precisa fazer nada"* — o oposto exato de N-01. `instrucao` mostra o passo-a-passo e a
  frase "assim que você concluir, detectamos automaticamente", **sem checkbox**: o passo tem
  `auto_fonte`, e D-19 proíbe conclusão manual.

- **`App\Support\Onboarding\ReguaMercadoLider`** — limiares por nível em código, comparados contra
  `metrics`. A API entrega as métricas do vendedor, nunca os limiares (N-04).

---

## 4. Backfill e ordem de aplicação

1. `etapa` — `ALTER TABLE ADD COLUMN`, depois `UPDATE` por `chave` com o mapa das 15 chaves da v5
   mais as chaves mortas das v1..v4 (`ficha_cliente_recebida`, `ficha_conta_preenchida`) para que
   os onboardings de demonstração não fiquem sem bloco. `DB::table()->where()->update()` puro —
   **nunca** `UPDATE <tabela> <alias> SET`, que derruba a suíte no SQLite (learnings §6).
2. Colunas de reunião em `onboardings` — aditivas, sem backfill.
3. `onboarding_mapeamentos` — tabela nova, sem backfill.

`down()` de cada uma dropa só o que criou. Nenhuma toca em tabela de Polos, de desempenho, de
snapshot ou de bônus.

---

## 5. Fora deste desenho

- Migrar o onboarding de Polos para este motor — D-02 da Fase 135 travou coexistência.
- Templates dos demais serviços — D-08.
- Backfill dos 6 contratos ativos sem onboarding: é comando, não schema. Vai separado, com
  `--dry-run` antes de qualquer escrita.
