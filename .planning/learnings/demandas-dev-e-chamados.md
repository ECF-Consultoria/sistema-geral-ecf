# Demandas Dev e Chamados — o que não se deduz do código

Leitura recomendada antes de mexer em `/dev/demandas`, em `/chamados` ou nas
tabelas `dev_*` e `chamado*`. Escrito em 23/09/2026.

## 0. Na tela é "Ticket"; no código é `Chamado`

Em 23/09 o usuário pediu para o módulo se chamar **Ticket** (menu "Tickets",
URL `/tickets`, aba "Tickets" em `/dev/demandas?aba=tickets&ticket=ID`). O
domínio no código NÃO foi renomeado: tabelas `chamados*`, models `Chamado*`,
rotas nomeadas `chamados.*` e as ações internas `/dev/demandas/chamados/...`.
Texto novo que o usuário lê diz "ticket"; identificador novo segue `chamado`.

## 1. Chamado não é demanda — e isso é regra de produto

O chamado é o pedido de quem precisa de ajuda; a demanda é trabalho técnico
aceito no backlog. **Nunca crie demanda automaticamente a partir de chamado.**
A conversão é sempre um clique da equipe ("Criar demanda a partir deste
chamado"), que decide prioridade, critério de conclusão e prazo. O impacto que
o colaborador escolhe vira só uma *sugestão* de prioridade.

Depois da conversão, o chamado continua sendo a conversa com quem pediu. Quem
abriu não vê a demanda — nem o código DEV-xx. Isso é filtrado no servidor
(`ChamadoService::detalhe`), inclusive quando quem abriu é admin: a tela
`/chamados/{id}` é sempre a visão de solicitante.

`chamados.dev_demanda_id` é **único**: é o que impede, no banco, duas demandas
do mesmo chamado. A conversão ainda trava a linha (`lockForUpdate`) para o
segundo clique devolver a mesma demanda em vez de estourar a constraint.

## 2. Quem é "equipe dev"

Admin **ou** cargo Dev (`users.is_dev`, `isAdminDev()`). A lista "Para quem
deseja enviar?" é `is_dev` ativo — não há nome fixo no código. Dev que não é
admin atua nos chamados dele e nos da fila (sem responsável); criar demanda a
partir do chamado segue a regra de criar demanda, que hoje é **só admin**.

## 3. Área / Projeto não é tabela

É o texto `area` de `dev_demandas`, com a lista da planilha de gestão
(`DevDemanda::AREAS_PADRAO`) mais as áreas já usadas. Demandas e chamados usam
a MESMA fonte, `DevDemanda::areasDisponiveis()`. Não crie uma segunda lista.

## 4. O Tailwind do projeto só varre `.jsx`

`tailwind.config.js` tem `./resources/js/**/*.jsx` e **não** `*.js`. Um mapa de
classes (cor de status, por exemplo) colocado em `resources/js/lib/*.js` só
funciona se a mesma classe aparecer por acaso em algum `.jsx`; classe exclusiva
some do CSS calada, sem erro de build. Por isso os mapas de cor ficam em
`Components/DemandasDev/Selos.jsx` e `Components/Chamados/Partes.jsx`.

## 5. O sino passou a abrir o link da notificação

Até 23/09 o `NotificationBell` só marcava como lida. Agora, se `data.url` for
um caminho interno (`/...`, nunca `//` nem URL externa), ele navega até lá.
Notificação sem `url` (as de meta, por exemplo) continua como antes.

## 6. Anexos

Disco `local` (privado), em `chamados/{id}/`, saída só pela rota
`chamados.anexos.show`, que confere a permissão — anexo de nota interna nunca
sai para quem abriu. O tipo gravado é o detectado pelo servidor; imagem e PDF
abrem no navegador (`nosniff`), o resto baixa. SVG e HTML não são aceitos.

## 7. Nem todo Dev atende (29/09)

O cargo Dev (`users.is_dev`) dá o sistema inteiro — mas quem ATENDE ticket e
pode ser responsável por demanda é um recorte: `config/demandas_dev.php`
`atendimento_ids` (env `DEMANDAS_DEV_ATENDIMENTO_IDS`, padrão `2,24` = Barreto
e Maycon em produção). A Thalissa (#33) é Dev e ficou FORA por decisão do
usuário, sem perder o cargo. `Chamado::queryAtendimento()` é a fonte única:
"Quem atende", aviso da fila, transferência e o select de responsável da
demanda. `usuarios` (todos os ativos) continua existindo porque reunião
convida qualquer pessoa. Nos testes o env é forçado vazio = todo Dev atende.

## 8. Aviso no canto da tela é só para quem abriu

O sino sozinho passava despercebido. `AvisoTicketRespondido.jsx` (no
`AppLayout`) consulta `/api/notificacoes/tickets` a cada 30s e mostra as
ChamadoNotification NÃO lidas cuja `url` começa com `/tickets/` — é assim que
se distingue o aviso de quem abriu (os da equipe apontam para
`/dev/demandas?...`). Não há flag própria: se `avisar()` mudar a url do
solicitante, o cartão para de aparecer calado. Some ao abrir o ticket
(`ChamadoService::marcarAvisosLidos` no `show`) ou no X (marca lida via JSON).
A rota fica fora do `modulo:chamados` de propósito: senão toda página de quem
não vê o módulo daria 404 no polling.

## 9. Linha do tempo e métricas por dev: tudo sai do diário (29/09)

A aba **Tempo** (faixa por demanda, régua "prometido × entregue", números por
dev) foi pedida como "um Gantt, mas não feio", para ter métricas do time dev
com vistas a bonificação. Está **em observação**: nenhum número dali vira nota
ou meta. Antes de transformar em régua de bônus, leia
`.planning/learnings/metas-dev-regua.md` (Metas do Dev), que já errou cota duas vezes.

- **O prazo de entrega é dado pelos PRÓPRIOS devs** (decisão do usuário). Por
  isso "cumpriu o prazo" sozinho é gameável com prazo folgado, e a tela mostra a
  **sobra do prazo** (mediana de quanto antes a entrega saiu) como contrapeso.
  Não tire a sobra achando que é enfeite.
- **Não existe coluna de "prazo original".** O prazo dado é o
  `previsao_revisada` da **primeira atualização de trabalho** (status em
  `DevDemanda::STATUS_TRABALHO`, ou concluído). Como o diário não se edita nem
  se apaga, ele fica congelado de graça. Escolha deliberada: adicionar coluna em
  `dev_demandas` (tabela com dado em produção) exigiria fase GSD e ainda deixaria
  o valor editável.
- `dev_demandas.prazo` passou a ser o **prazo vigente**: o controller grava nele
  toda previsão registrada no diário. Até 29/09 a "previsão revisada" era só
  informativa e não mexia no prazo. Editar o prazo pela tela de demanda continua
  possível, mas não vira revisão no histórico. As métricas não usam esse campo.
- **Começar exige prazo** (`storeAtualizacao`, `jaComecou()`). Demanda que já
  tinha começado antes disso (todas as importadas da planilha) não é travada e
  fica **sem prazo dado**, fora da pontualidade. Não invente prazo retroativo
  para ela.
- **"Revisou antes de vencer" usa o `created_at` da atualização**, não a `data`
  digitada, porque dá para digitar data no passado. Já as fases usam a `data`
  ("status no fim do dia"), apenas forçada a nunca voltar antes do marco
  anterior. Registrar uma conclusão com data retroativa ainda melhora o desvio.
  Isso está aceito por ora: o `registrado_em` já vai no detalhe da demanda, mas
  a tela ainda não o mostra. Se virar régua de bônus, reveja.
- A cor de **validação** deixou de ser ocre (`#c98500`) e virou verde-azulado
  (`#2a9d8f` escuro / `#00897b` claro). Na faixa, validação encosta no laranja do
  bloqueio, e o par ocre × laranja reprovava no validador de paleta (ΔE 10,6,
  abaixo do piso 15 para visão normal). O par "a fazer" × "desenvolvimento"
  também reprova no escuro, mas "a fazer" não aparece na faixa (vira fila, em
  cinza). Nos selos da lista os dois sempre têm rótulo escrito.
