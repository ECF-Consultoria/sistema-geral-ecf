---
phase: 155-onboarding-na-maquina-de-estados-v23-0
tipo: decisoes-de-desenho
data: 2026-09-10
requirements: [ONBRD-01, ONBRD-02, ONBRD-03, ONBRD-04]
---

# Fase 155 — decisões de desenho, escritas ANTES do código

Trabalho direto (`CLAUDE.md` → "GSD por RISCO"). Nenhum item da lista de GSD obrigatório é tocado, e
**esta fase não tem migration**.

## 🔒 O limite desta fase, e ele é rígido

`.planning/learnings/onboarding-regua-congelada.md` é leitura obrigatória e define o que **não** se
faz aqui:

- **`DefinicaoOnboarding` NÃO é alterada. `VERSAO` NÃO sobe.** A régua está na 17 e é reusada
  (ONBRD-03). Esta fase mexe no **gatilho** e na **leitura de etapa em volta** do motor — nunca no
  motor.
- Nenhuma tela ou tabela nova de checklist nasce. O checklist continua sendo o que
  `OnboardingEngineService::montarPassos()` monta a partir da definição congelada.
- Como nada muda na definição, **nenhum dos três comandos de realinhamento**
  (`aplicar-passos-novos`, `remover-passos-fora-da-regua`, `sincronizar-dependencias`) precisa rodar.
  Se alguém achar que precisa, é sinal de que esta fase saiu do escopo.

## Onde estão os dois ganchos (medido no código)

| Momento | Onde | O que fazer |
|---|---|---|
| Onboarding vai a `andamento` | `OnboardingEngineService::definirResponsaveis()`, o ramo `$ligouAgora` (linha ~202) | etapa 6→7 |
| Onboarding vai a `concluido` | `OnboardingEngineService::avaliarConclusaoDoOnboarding()` (linha ~700) | etapa 7→8→9, se **todos** concluíram |

**Medido:** nenhum código de produção escreve hoje as etapas 7, 8 ou 9 — só o comando de backfill
carimba `em_operacao` retroativamente. Esta fase é a primeira a escrevê-las em runtime.

## D-A — Empresa com N serviços: entra no primeiro, sai no último

Decisão do usuário. O onboarding é **por serviço**; a etapa é da **empresa**.

- **6→7** quando **qualquer** onboarding da empresa passa a `andamento`.
- **7→8** somente quando **todos** os onboardings não-concluídos da empresa acabarem.

É o que "a empresa terminou o onboarding" significa para quem lê a etapa. Avançar no primeiro que
terminasse afirmaria algo falso com serviço ainda em andamento — e a Fase 156 mediria SLA errado.

**Hoje 100% das empresas têm 1 onboarding** (medido: 4 empresas, 1 cada), então na prática as duas
regras coincidem. A diferença só aparece quando a segunda nascer — e é justamente aí que ninguém
lembraria de decidir.

## D-B — ONBRD-04: bloqueia só quando faltam OS DOIS

Decisão do usuário, leitura literal do "**e**" do requisito.

A trava é sobre os responsáveis da **empresa** (`company_users`, roles `analista`/`estrategista` —
os que a Fase 154 grava), não sobre os slots do próprio onboarding.

Por que a leitura lenient: empresa legada que hoje tem só `consultor` vinculado roda onboarding
normalmente. Bloquear por "falta qualquer um" quebraria o que funciona, para cumprir uma leitura que
o texto não obriga. Depois da Fase 154 toda empresa distribuída tem os dois, então na prática a trava
só pega quem nunca passou pela distribuição — que é exatamente o alvo.

## D-C — 8→9 automático e imediato, com as DUAS linhas de histórico

Decisão do usuário. Concluir o último onboarding produz, no mesmo ato, `onboarding_concluido` e
depois `em_operacao` — cada uma com sua linha em `company_etapa_transicoes`.

A etapa 8 dura milissegundos, e isso é **honesto**: ela é um marco, não um período de trabalho. A
Fase 156 vai medir duração ~0 para ela, e o registro existe para provar que a empresa passou por lá.

## D-D — Quem é o ATOR das transições

`EtapaTransicaoService::transicionar()` exige `User $por` não-nulo — não há sobrecarga que aceite
nulo (T-139-07-01).

- **6→7**: quem chamou `definirResponsaveis()`. O engine não conhece a sessão, então o ator é
  **passado como parâmetro** por quem chama.
- **7→8→9**: a conclusão pode ser disparada por um resolver automático (`reavaliar()` rodando no
  agendador a cada 10 min), sem sessão. Nesses casos o ator é a **conta de sistema** já existente,
  `config('services.hubspot.webhook_user_id')` — a mesma conta não-logável criada na Fase 151 (D-17)
  pelo mesmo motivo: atribuir a uma pessoa uma transição que ela não fez é o histórico falso que a
  D-14 proíbe.

⚠️ Se a conta de sistema não estiver configurada, a transição é **pulada com `Log::warning`**, nunca
atribuída a um admin qualquer. Melhor a etapa ficar para trás e alguém notar do que o histórico
mentir — a Fase 156 lê exatamente essas linhas.

## D-E — O acoplamento é one-way, e o engine não vira dono da etapa

O `OnboardingEngineService` ganha `EtapaTransicaoService` por construtor. A seta é **só nessa
direção**: `EtapaTransicaoService` não conhece onboarding, e nunca pode conhecer.

Toda escrita de etapa continua passando por `transicionar()` — nenhum `update(['etapa' => ...])`
nasce aqui. Quando a transição é recusada (empresa em etapa que não permite o destino, ou empresa
legada com `etapa` NULL), o engine **loga e segue**: o onboarding não pode falhar porque a máquina de
estados recusou. O onboarding é o processo real; a etapa é o retrato dele.

**Empresa com `etapa` NULL nunca é carimbada** — mesma disciplina da D-05 da Fase 150 e da D-14 da
151. Onboarding de empresa legada roda igual, só não mexe em etapa.

## Fora de escopo, declarado

- Reabrir onboarding concluído e o efeito disso na etapa (retrocesso continua proibido).
- Qualquer alteração em `DefinicaoOnboarding`.
- A tela do onboarding — ONBRD-03 é explicitamente "não nasce tela nova".
