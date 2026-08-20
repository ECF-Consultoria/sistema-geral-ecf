# Agenda das reuniões recorrentes → API do Google Calendar

Semente escrita em 2026-08-20. Decisão do negócio na mesma data: **manter o
formulário como está por enquanto**; a integração com a API do Google Calendar
entra em breve e é ela que resolve o problema de raiz.

Leitura obrigatória antes de mexer em `OnboardingAgenda`, no bloco "Agenda das
reuniões recorrentes" ou na integração de Google Calendar.

## O problema, dito pelo negócio

> "essa questão de agenda é mais complicado porque é tudo feito pelo agenda do
> google. o responsável vai olhar para a agenda dele, não para o sistema"

O formulário pede dia da semana, horário e periodicidade. O responsável **já
criou o evento no Google Calendar** — digitar de novo aqui é dupla entrada, e
quando as duas divergem a do sistema é a que está errada, porque não é a que
alguém consulta.

## Por que o formulário NÃO foi removido junto com o passo

Na v14 saiu o passo `agenda_quinzenal_definida` (o checklist que pedia
confirmação do que o formulário ao lado já respondia). O formulário ficou. Isto
foi decisão consciente, não esquecimento: removê-lo agora abriria um buraco que
só a integração fecha, e a integração está próxima.

**Não remova sem falar com o negócio.** E não gaste tempo redescobrindo que ele
"não é usado por ninguém" — está aqui.

## O que já existe (e economiza meio caminho)

| Peça | Estado |
|---|---|
| `GoogleCalendarService` | OAuth + `fetchEvents` + `syncToMeetings` |
| Tabela `meetings` | `company_id`, `scheduled_at`, `google_event_id`, `google_calendar_owner`, presenças |
| `GoogleCalendarController` | fluxo de conexão |
| `OnboardingAgenda` | a regra digitada à mão, hoje sem consumidor |

O caminho **Google → sistema já existe** e é por empresa: `syncToMeetings()`
extrai o nome do cliente do título/descrição do evento e casa com
`companies.name` por match parcial case-insensitive. Heurístico, mas funciona.

## O BLOQUEIO que vai morder — leia antes de estimar

O OAuth do repositório pede **apenas `calendar.readonly`**. Está documentado no
docblock de `OnboardingAgenda`:

> "não cria evento no Google Agenda: o OAuth do repo tem só o escopo
> `calendar.readonly`, e trocar o escopo forçaria reconsentimento de todos os
> usuários já conectados."

Ou seja:

- **Ler** a agenda do responsável e mostrar as próximas reuniões da empresa:
  funciona hoje, sem mexer em escopo.
- **Criar/alterar** evento a partir do sistema: exige `calendar.events`, e
  **todo usuário já conectado precisa reconsentir**. Não é uma linha de config —
  é migração de consentimento, com janela em que a sincronização para para quem
  não reconsentiu ainda.

Se o objetivo for só "o sistema mostra a agenda que já existe", o escopo atual
basta e o trabalho é pequeno. Se for "o sistema cria a recorrência", o escopo
vira o item mais caro do projeto.

## Estado medido no local (20/08)

- `google_tokens`: **0** — ninguém conectou.
- `meetings`: **0** — consequência do acima.
- `onboarding_agendas`: **1 linha, incompleta** (`dia_semana=5`, `horario=NULL`).

Isto importa para testar: **a integração não tem dado nenhum no local**. Qualquer
verificação de "mostra as próximas reuniões" precisa de uma conta Google
conectada antes, senão a tela vazia parece bug e é só ausência de token.

## Desenho sugerido (não decidido)

Trocar o formulário por uma leitura: no bloco de agendamento, listar as próximas
reuniões desta empresa vindas de `meetings`, com um aviso claro quando não há
token conectado ("conecte sua Agenda Google para ver aqui"). Zero digitação, e a
verdade continua no Google — que é o que o negócio descreveu.

O que precisa ser decidido junto com o negócio: se a ausência de agenda passa a
travar alguma coisa no onboarding, ou se é só informação. Hoje não trava nada —
o passo que travava saiu na v14.
