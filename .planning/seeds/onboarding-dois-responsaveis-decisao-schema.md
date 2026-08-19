# Onboarding — decisão de schema dos DOIS responsáveis (estrategista + analista)

> Escrito em 2026-08-19, ANTES da migration, conforme CLAUDE.md ("Migration é difícil de
> desfazer; o desenho da tabela vai escrito antes de existir").
>
> Continua [onboarding-fluxo-decisao-schema.md](onboarding-fluxo-decisao-schema.md). Não
> reabre a Fase 135 — soma em cima dela.
>
> **Diferença de custo em relação àquele documento:** ele foi escrito quando "nada disto está
> em produção, o que torna todo backfill barato agora e caro depois". Isso mudou — a Fase 135
> foi deployada em 2026-08-19 (`11ea482d`) e `onboardings` **tem dado em produção**. Por isso
> tudo aqui é **aditivo e nullable**: nenhuma coluna existente muda de tipo, muda de nome ou
> é dropada.

---

## 1. A decisão de negócio

| # | Decisão | Quem decidiu |
|---|---|---|
| R-01 | O onboarding passa a ter **dois responsáveis**: um estrategista e um analista. Não é mais um único `responsavel_id` | usuário, 2026-08-19 |
| R-02 | **Qualquer um dos dois liga o SLA.** Confirmar estrategista OU analista tira o onboarding de rascunho, carimba `iniciado_em` e libera o portal do cliente. A tela cobra o papel que faltar como pendência | usuário, 2026-08-19 |
| R-03 | Os rascunhos legados (contratos que já existiam quando o motor entrou) **aparecem na tela nova como rascunho**. Não são escondidos por data de corte, não são dispensados e não são apagados | usuário, 2026-08-19 |

R-02 não foi escolhido por conveniência: é a régua que `/companies` **já** aplica e documenta
em código. `em_operacao` é "tem pelo menos um dos dois papéis" e `sem_responsavel` é "falta
algum dos dois" — a distinção está comentada em
`CompanyController::index()` justamente porque é proposital. Exigir os dois para ligar o SLA
criaria uma segunda régua de "quem cuida desta empresa", divergente da que a tela ao lado usa.

---

## 2. O que muda no schema

### 2.1 Duas colunas novas em `onboardings`

```
responsavel_estrategista_id  bigint  NULL  FK users  nullOnDelete   (após responsavel_id)
responsavel_analista_id      bigint  NULL  FK users  nullOnDelete   (após a de cima)
```

Nome de índice mais longo gerado: `onboardings_responsavel_estrategista_id_foreign` = 47
caracteres, abaixo do limite de 64 (learnings §6).

`nullOnDelete` **exige** coluna nullable — a mesma armadilha já registrada em learnings §6 e
já respeitada por `reuniao_agendada_por`.

### 2.2 `responsavel_id` PERMANECE — vira o responsável principal

Não é dropada, não é renomeada, não vira derivada em banco.

**Invariante, mantida em código pelo engine:** se algum dos dois slots está preenchido,
`responsavel_id` aponta para **um deles**. Regra de escolha: mantém o valor atual se ele ainda
ocupar um dos dois slots; senão adota `estrategista ?? analista`.

Por que manter:

- **É dado em produção** e `LogsActivity` já gravou histórico sobre ela
  (`logOnly(['status', 'responsavel_id'])`). Dropar apagaria o sentido de linhas de
  `activity_log` já escritas.
- Os leitores atuais são poucos mas reais e continuam funcionando sem tocar em nada:
  `OnboardingController` (payload do painel e do detalhe, `confirmarResponsavel`),
  `Onboarding::responsavel()`, `OnboardingEngineService::podeIniciar()` e
  `Components/Onboarding/Painel/EmpresaCard.jsx`.
- O portal do cliente e o detalhe mostram **um** nome, não dois. Ter um "principal" explícito
  evita que cada tela invente sua própria regra de desempate.

### 2.3 O que foi descartado, e por quê

**Tabela pivot `onboarding_responsaveis (onboarding_id, user_id, papel)`** — descartada. São
exatamente dois papéis fixos, com significado diferente entre si, não uma lista aberta. Pivot
só se pagaria com N papéis ou com histórico de troca, e ninguém pediu histórico. O custo é
real: toda leitura de "quem é o analista deste onboarding" viraria join, e o payload da
listagem de `/companies` — que já carrega 5 relações por empresa — ganharia mais uma.

**Reaproveitar `company_users` sem coluna nenhuma** — descartada, e esta é a decisão que mais
importa. O vínculo da empresa é o estado ATUAL da carteira e muda com o tempo; o responsável
do onboarding é um **carimbo daquele onboarding**. Se o analista da carteira mudar em outubro,
o onboarding fechado em agosto não pode mudar de dono retroativamente — seria reescrever quem
atendeu. Além disso `company_users` não tem como registrar "confirmado", que é justamente o
ato que liga o SLA (D-05).

**Renomear `responsavel_id` para `responsavel_estrategista_id`** — descartada. Renomear coluna
com dado em produção para dar a ela um significado que os valores atuais não têm (não sabemos
o papel de quem está lá) é o caminho mais curto para dado errado silencioso.

---

## 3. Backfill

Para cada onboarding com `responsavel_id` preenchido, o papel é decidido pelo vínculo daquele
usuário com aquela empresa em `company_users`:

1. Vínculo `role = 'estrategista'` (e nenhum `'consultor'`) → `responsavel_estrategista_id`.
2. Caso contrário → `responsavel_analista_id`.

O default do passo 2 não é arbitrário: `Onboarding::ROLES_RESPONSAVEL_SUGERIDO` é
`['consultor', 'estrategista']` **nesta ordem**, então quem está hoje em `responsavel_id`
chegou lá majoritariamente pelo papel `'consultor'` — que é o analista de Performance. Um caso
classificado errado é corrigível pela própria tela nova, sem migration.

`responsavel_id` **não é alterado** pelo backfill: ele já satisfaz a invariante do §2.2 por
construção (o valor vai para um dos dois slots).

Backfill em **PHP puro** (`DB::table()->where()->update()` linha a linha), nunca
`UPDATE <tabela> <alias> SET` — sintaxe que MariaDB aceita, SQLite recusa, e que já derrubou a
suíte deste projeto (learnings §6). Em teste as tabelas nascem vazias e o laço não itera.

`down()` dropa as duas FKs e as duas colunas, e só isso.

---

## 4. O que muda em código (sem schema)

- **`OnboardingEngineService::definirResponsaveis(Onboarding, ?User $estrategista, ?User $analista)`**
  — método novo. Exige pelo menos um dos dois. Grava os slots, mantém a invariante de
  `responsavel_id`, e:
  - status `rascunho` → vira `andamento`, carimba `iniciado_em`, chama `reavaliar()` (R-02);
  - status `andamento` → só atualiza os slots. É o caminho para preencher depois o papel que
    faltava — sem ele, "a tela cobra o que falta" seria uma cobrança sem botão;
  - status `concluido` → `DomainException`.

- **`OnboardingEngineService::confirmarResponsavel()` fica como está**, inclusive lançando
  `DomainException` fora do rascunho. É contrato travado por teste
  (`OnboardingTransicaoStatusTest::confirmar_responsavel_sobre_onboarding_ja_em_andamento_lanca_domain_exception`)
  e continua sendo o caminho do botão de um clique do painel atual. Passa a delegar para
  `definirResponsaveis`, escolhendo o slot pelo vínculo — mesma regra do backfill.

- **`podeIniciar()`** passa a considerar os três campos, não só `responsavel_id`.

---

## 5. Fora deste desenho

- Redesenhar os passos / o fluxo do onboarding — o usuário entrega o fluxo completo depois.
- Alargar o escopo de `/companies` para outros setores — decidido manter Performance-only.
- Desativar `/onboarding` — decidido que coexiste por enquanto.
- Qualquer alteração em `mlb_implementacoes` (o Onboarding de Polos) — intocado, D-02.
