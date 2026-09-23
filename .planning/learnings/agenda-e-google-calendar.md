# Agenda e Google Agenda — o que custou descobrir

Leitura recomendada antes de tocar em `/agenda`, no cartão "Agenda" da ficha do
onboarding, em `AgendaGoogleService`, `AgendaService`, `GoogleCalendarService` ou
na tabela `onboarding_eventos_google`. Escrito em 16/09/2026, quando a Agenda
virou agenda de verdade.

---

## 1. De onde vêm os eventos (e o que NÃO é cópia)

- **O Google é a verdade.** O sistema não guarda os compromissos de ninguém. A
  página `/agenda` lê o Google de quem está logado, ao vivo, a cada período.
- **`onboarding_eventos_google` é o VÍNCULO** entre um evento que o sistema criou e
  o onboarding, mais um **retrato** (título, início, fim, plataforma, link,
  participantes). O retrato serve para a ficha desenhar sem chamar a API, para quem
  não conectou o Google e para quando o Google está fora do ar.
- **O retrato é conferido contra o Google** quando fica velho (10 min) —
  `AgendaService::reconciliar()`, no máximo 5 eventos por abertura da ficha — e
  sempre que o dono da agenda lê a própria semana. Evento apagado ou cancelado no
  Google vira `status = cancelado` aqui. Não "corrija" o retrato à mão: ele volta.
- **A reunião de onboarding tem duas verdades que andam juntas:**
  `onboardings.reuniao_agendada_para` (o que o cliente vê no portal e o checklist lê)
  e a linha `chave = kickoff`. Só `AgendaGoogleService` escreve nas duas. Cancelar o
  convite **não** desmarca a data — desmarcar é decisão de negócio.
- **`meetings` (módulo Reuniões) é outra coisa**: presença por empresa, alimenta
  Dashboard e Performance. A Agenda não grava lá. Quem leva evento para `meetings` é
  o botão "Sincronizar" de Reuniões, pela marca `[Cliente: X]` na descrição — que a
  reunião de onboarding, o mapeamento e a apresentação levam de propósito (decisão de
  16/09), e "Outro evento" não.

## 2. Quem organiza, quem vê

- O evento do onboarding sai da agenda do **analista ou do estrategista** do
  onboarding (escolha no drawer). Nunca da de outra pessoa — a rota de
  disponibilidade recusa `organizador` fora desses dois.
- Editar e cancelar vão para a agenda de **quem organizou** (`calendar_owner_user_id`),
  mesmo que outra pessoa clique. Se o dono desconectou o Google, a edição falha com
  frase, e ninguém mais consegue mexer no evento pelo sistema.
- **Uma conta Google por pessoa** (`google_tokens.user_id` é único). Não existe
  "conectar outra conta" — só conectar e reconectar.
- **Quem conduz sem a empresa na carteira vê o evento, mas não edita** — a régua é
  `EscopoOnboarding` (admin ou carteira), a mesma da ficha. Medido em produção em
  16/09/2026: 2 de 6 papéis de responsável (1 analista, 1 estrategista) estavam
  assim, e essas pessoas também não abrem a ficha. É lacuna antiga, não da Agenda.

## 3. Armadilhas da API do Google

- **Sem `conferenceDataVersion=1` na URL, o pedido de Meet é ignorado em silêncio**
  — o evento nasce sem sala e sem erro. O `createRequest.requestId` precisa ser novo
  a cada pedido. Pedir Meet de novo num evento que já tem troca o link que o cliente
  recebeu: por isso o PATCH só pede quando a plataforma MUDA para Meet.
- **`sendUpdates=all` é o que manda e-mail.** Todo POST, PATCH e DELETE leva.
- **PATCH de convidados apaga o "aceito"** se mandar o convidado sem
  `responseStatus`. `EventoGoogle::mesclarConvidados()` reaproveita o objeto que o
  Google devolveu — busque o evento antes de editar.
- **PATCH não apaga campo omitido.** Para tirar o local, mande `location: ''`.
- **Descrição escrita no Google Agenda vem em HTML.** Editar evento próprio só
  reescreve a descrição se o texto mudou, senão a formatação some.
- **Ocorrência de série tem id com sufixo** (`id_20260916T170000Z`) e
  `recurringEventId`. O vínculo casa pelos dois. Evento de série não se edita pelo
  sistema — a tela manda para o Google, onde dá para escolher quais datas mudam.
- **Evento de dia inteiro tem `date`, não `dateTime`**, e o fim é exclusivo. O
  servidor sempre devolve ISO com fuso; simular a data crua no navegador põe o
  evento no dia anterior (meia-noite UTC).
- Quem conectou antes de 15/09/2026 tem só `calendar.readonly`: lê, mas a escrita
  volta 403 `insufficientPermissions` (`GoogleCalendarService::ESCOPO_INSUFICIENTE`).

## 4. Banco

- **O único antigo `(onboarding_id, tipo)` era o índice que sustentava a FK de
  `onboarding_id`** — a migration de 15/09 não cria índice separado para ela.
  Dropá-lo sozinho dá erro 1553. A migration de 16/09 cria `oeg_onboarding_idx`
  ANTES do drop. Conferido no MariaDB 10.4.32 local, com ida, volta e segunda
  execução.
- A unicidade passou para `(onboarding_id, chave)`: `chave` repete o tipo em
  kickoff e rotina e é NULA nos avulsos — NULL não conflita em índice único.
- O `down` recusa reverter com eventos avulsos gravados, de propósito: apagar
  evento para caber no índice antigo destruiria o rastro de convite enviado.
- **`php artisan migrate` do zero não roda no MariaDB 10.4 do XAMPP**:
  `2026_07_07_100005_add_dedup_key_to_nps_surveys` falha com 1901 (coluna gerada
  com `date_format`). Para testar migration nova no MariaDB local, monte só as
  tabelas que ela precisa e chame `up()`/`down()` por script.

## 5. Tela

- `capitalize` do CSS em data por extenso escreve "16 De Setembro". Use
  `primeiraMaiuscula()` de `Components/Agenda/agenda.js`.
- A semana começa na segunda na grade e no domingo no mini calendário e no mês —
  é o que a referência visual pedia. Se unificar, mude `intervaloDaVisao` e o
  `doOnboarding()` do servidor juntos.
- `useJson` não limpa `dados` ao trocar a URL: durante a troca, a tela mostra os
  dados anteriores com o indicador de carregamento. É o comportamento desejado na
  grade (não pisca), mas cuidado ao ler `dados` para decidir alguma coisa.

## 6. Correções de 23/09/2026 — conexão, troca de usuário e recorrência

- **O OAuth não tinha `state`.** O callback gravava o token em quem estivesse
  logado na VOLTA do Google. Trocar de usuário no mesmo navegador no meio do
  consentimento ligava o Google de A à conta de B. Agora `connect` guarda
  `{state, user_id}` na sessão e o callback (com `auth`) recusa o que não casar.
- **Conta Google errada é o sintoma mais provável de "agenda de outra pessoa".**
  `prompt=consent select_account` + `login_hint` forçam a escolha; conta
  diferente do e-mail do sistema conecta, mas o aviso sai pelo canal `error`
  (o toast do AppLayout só desenha `success`/`error` — `warning` morreria calado).
  A conta Google conectada NÃO é gravada: exigiria migration em `google_tokens`
  (tabela viva). Duas pessoas ligadas à mesma conta seguem indetectáveis.
- **`invalid_grant` apaga o token.** Antes ele ficava e todo `exists()` dizia
  "conectado" para sempre. 401 antes do vencimento renova e tenta de novo UMA
  vez (`comToken`). A exceção mantém a frase "renovar token": é por ela que
  `AgendaService`/`AgendaGoogleService::explicar()` reconhecem o caso.
- **Rotina: a série nascia na data do kickoff mesmo no passado**, e o PATCH do
  "Atualizar convite" movia a série INTEIRA (apagava o histórico). Agora: série
  já iniciada com dia/horário mudado é ENCERRADA com `UNTIL` (as linhas `EXDATE`
  são preservadas) e nasce outra; sem mudança, o PATCH não leva `start`/`end`/
  `recurrence`. Comparação é por dia da semana + hora + regra, nunca pela data
  da primeira ocorrência (ela anda sozinha com o tempo).
- **Analista trocado era um beco** — `enviar()` mandava "ajustar pela Agenda", e
  a Agenda recusa rotina. Agora a série sai da agenda antiga (cancelada se não
  começou, encerrada se começou) e nasce na do analista atual.
- **Projeção do retrato**: respeita `UNTIL`/`COUNT`; para o DONO com o Google
  lido, série não é mais projetada (tudo que existe já veio da leitura — projetar
  desenhava cópia fantasma depois de um "este e os seguintes").

## 7. A EQUIPE marca a reunião pelo Portal — o cliente NÃO agenda (23/09/2026)

- **A primeira versão foi recusada no mesmo dia.** Ela deixava o CLIENTE escolher
  horário livre. O negócio: "o cliente não tem que agendar nada pra gente, a
  gente que agenda com eles". O pedido real era outro: a equipe conduz o
  onboarding COM o cliente pela tela do portal, e precisava marcar a reunião
  dali, no lugar do "estamos definindo a data". "Agendar pela jornada do
  onboarding, no portal" soava como autoatendimento e não era — na dúvida sobre
  QUEM age numa tela compartilhada, pergunte.
- **Cliente:** "estamos definindo" ou a reunião inteira — data por extenso,
  início e fim, botão do Meet, aviso do convite. Sem formulário, sem rota: as
  duas rotas (`portal/onboarding/horarios|agendar`) exigem equipe e dão 403 a
  ele. O payload dele não leva `organizadores` (e-mails internos).
- **Equipe:** o formulário aparece de primeira (data, hora, duração, agenda de
  quem). As sugestões são os horários livres de analista E estrategista pelo
  `freeBusy` — atalho, NÃO trava.
- **Mesmo `criar()` da ficha, com `data_so_com_convite`.** O `criar()`/
  `atualizar()` gravam a data ANTES do Google (na ficha, "só a data" é uso
  legítimo). No portal isso fazia o cliente ver "reunião marcada" sem link
  quando o Google recusava — pego na conferência visual, não no teste. Com a
  opção, a data só vale depois do evento aceito. Organizador sem Google é
  recusado antes de tudo.
- `Cache::lock` por onboarding contra dois cliques (dois convites ao cliente).
