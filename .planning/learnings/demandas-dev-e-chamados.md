# Demandas Dev e Chamados — o que não se deduz do código

Leitura recomendada antes de mexer em `/dev/demandas`, em `/chamados` ou nas
tabelas `dev_*` e `chamado*`. Escrito em 23/09/2026.

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
