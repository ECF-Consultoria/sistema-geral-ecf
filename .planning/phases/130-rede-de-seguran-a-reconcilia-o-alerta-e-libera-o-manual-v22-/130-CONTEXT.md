# Phase 130: Rede de segurança — reconciliação, alerta e liberação manual (v22.0) - Context

**Gathered:** 2026-08-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Garantir que uma falha silenciosa da Clicksign vire alarme, e que sempre exista um jeito de
destravar uma empresa presa — com autor e motivo registrados. Inclui: varredura de reconciliação,
alerta de contrato preso, liberação manual auditada, e o auto-monitoramento da própria varredura.

**Fora desta fase:** a tela definitiva do Administrativo e a permissão `admin.contratos`
(Fase 131), e ligar o bloqueio do roteamento (Fase 133). Esta fase entrega uma **superfície
mínima e descartável** para a liberação manual; o backend é que é definitivo.

</domain>

<decisions>
## Implementation Decisions

### Alerta de contrato preso (REDE-02)

- **D-01: Canal é o sino in-app, como o resto do sistema.** `BaseNotification::via()` já devolve
  só `['database']`; esta fase segue o padrão e não introduz e-mail nem WhatsApp.
  ⚠️ **Consequência assumida e registrada:** o goal do ROADMAP diz *"alguém sabe em minutos, não
  em dias"* — com sino apenas, o alerta chega **quando alguém abre o sistema**. A redação do goal
  é que precisa ser ajustada; não é falha da implementação. Recusados nesta rodada: e-mail
  (quebraria o canal único do projeto) e Digisac/WhatsApp (existe e alcançaria em minutos, mas
  hoje só serve ao NPS e manda mensagem para pessoa real).

- **D-02: Audiência = `role:admin` ∪ usuários ATIVOS vinculados ao setor `comercial`.**
  Decisão literal do usuário; ele criará usuários comerciais depois se faltarem.
  ⚠️ **NÃO reuse `AudienciaComercial::lideresEPermissionados()` como está** — esse helper devolve
  apenas os **líderes** do setor mais os permissionados, o que estreitaria a audiência sem
  ninguém perceber. Não existe setor "Administrativo" no sistema (os semeados são Comercial,
  Desenvolvimento, Dev e Shopee); `role:admin` é a mesma trava que a Fase 129 usou para a rota do
  PDF, e sai de cena quando a Fase 131 criar a permissão dedicada.

- **D-03: O gatilho é "o que vier primeiro" entre dois critérios** — um número de dias
  configurável (igual para todos) **ou** uma fração do prazo do próprio contrato (que é por
  contrato desde a D3 da milestone). Cobre contrato curto e contrato longo. Recusados: só o
  número fixo (ignora prazo por contrato) e só a fração (não avisa nunca em contrato muito longo).

- **D-04: O alerta REPETE em intervalo até resolver.** Avisar uma vez só faria o aviso morrer se
  ninguém viu na hora — exatamente o silêncio que a fase existe para impedir. Exige registrar
  quando avisou pela última vez, para não repetir todo dia.

- **D-05: O alerta cobre TUDO que não terminou bem**, não só o que a reconciliação consegue
  corrigir. Os estados reais em `ContratoAssinatura` são `rascunho`, `aguardando_assinaturas`,
  `assinado`, `recusado`, `expirado`, `cancelado`, `erro`. A regra vira **"empresa sem liberação
  há tempo demais"**, qualquer que seja a causa — e a causa aparece na mensagem. Isso inclui
  `erro` (o job morreu), `rascunho` parado (criado e nunca ativado — e a Fase 129 mediu que
  rascunho é INERTE, não dispara webhook), e `recusado`/`expirado` (o cliente decidiu, mas a
  empresa segue parada e alguém precisa agir).

### Reconciliação (REDE-04)

- **D-06: Roda uma vez por dia**, como todo o agendamento do projeto (`dailyAt`). Um webhook
  perdido demora até 24h para ser recuperado — aceitável, porque o webhook é o mecanismo
  principal e a reconciliação é rede de segurança (posição já travada em REQUIREMENTS-v22.md,
  §"Polling como mecanismo primário" está fora de escopo). Bônus: zero risco de estourar o rate
  limit **medido** de 20 chamadas/min. Recusado: de hora em hora (exigiria lote e pausa).

- **D-07: Ao achar divergência, CORRIGE SOZINHA pelo mesmo caminho.** Chama o mesmo
  `EmpresaOperacionalRouter::liberarEmpresa()`, registrando `via='reconciliacao'` — um terceiro
  valor além de `webhook` e `manual`, para o histórico distinguir as três origens.
  É seguro porque a Fase 129 deixou o método **idempotente e protegido por lock por empresa**
  (`lockDaEmpresa()`), então não duplica ficha nem briga com um webhook atrasado.
  Recusado: só marcar e chamar humano — a empresa continuaria presa até alguém agir.

- **D-08: A reconciliação também redispara o download de PDF pendente.** Fecha uma lacuna real
  deixada entre as fases: a D-14 da Fase 129 decidiu que falha de download **não** prende a
  liberação (o contrato fica assinado com `pdf_assinado_erro` preenchido), mas **nenhum código
  agia nesse sinal**. Funciona mesmo dias depois porque a D-12 da 129 já obriga todo retry a
  reconsultar o envelope para obter link fresco (os links da Clicksign morrem em 5 minutos).

- **Escopo da varredura ≠ escopo do alerta.** Reconsultar a Clicksign só faz sentido em
  `aguardando_assinaturas` (o único estado em que algo pode ter mudado lá sem chegar aqui) e nos
  PDFs pendentes (D-08). O alerta é mais largo (D-05). O plano não deve uniformizar os dois.

### Auto-monitoramento da rede de segurança

- **D-09: Registro de execução + checagem de ausência.** A varredura grava quando rodou, quantos
  contratos viu e o que corrigiu; se estourar exceção, dispara alerta com o erro. **Além disso**,
  uma verificação acusa quando a última execução é mais velha que o esperado — isso cobre o caso
  do **cron parado**, em que ninguém grava nada e ninguém sabe, que é a falha mais silenciosa de
  todas. Custa guardar um carimbo (tabela ou configuração).
  Motivo: sem isto, a própria rede de segurança pode cair no silêncio que ela existe para
  eliminar. O alerta in-app continua funcionando mesmo com a Clicksign fora do ar.

### Liberação manual (REDE-03 / DADOS-05)

- **D-10: Rota mínima com formulário simples, só-admin.** Empresa, serviço e motivo. O
  Administrativo consegue usar de verdade desde já, e o SC3 (“testada ponta a ponta pelo menos
  uma vez”) fica verificável NESTA fase. **A tela é deliberadamente descartável** — a Fase 131
  reescreve; o backend é o mesmo e permanece. Recusados: comando artisan (só quem tem acesso ao
  servidor usaria, não o Administrativo) e adiar tudo para a 131 (deixaria a rede de segurança
  sem o "destravar" e o SC3 não verificável).

- **D-11: A liberação manual IGNORA o gate, mas mostra o estado real antes e registra.**
  Ela existe **porque** o gate automático não liberou; passar pelo mesmo gate a tornaria inútil.
  O admin pode liberar em qualquer estado — inclusive contrato `recusado` pelo cliente — e a tela
  **deve exibir o estado real com destaque antes de confirmar** (ex.: "este contrato foi RECUSADO
  pelo cliente"). A prestação de contas é o motivo obrigatório mais o registro de autor.
  Recusado: bloquear em `recusado` (protegeria contra o erro mais caro, mas criaria um caso que
  nem o admin destrava).

- **D-12: Motivo = lista de motivos + campo de detalhe obrigatório.** A lista permite agrupar e
  enxergar padrão ("80% das manuais são webhook perdido"); o texto livre preserva o caso
  específico. Sugestões de partida para a lista (o plano pode refinar): `webhook_nao_chegou`,
  `cliente_assinou_fora_do_sistema`, `decisao_comercial`, `outro`.
  Recusado: só texto livre — responder "por que estamos liberando tanto na mão?" exigiria ler tudo.

### Claude's Discretion

O usuário decidiu todas as 12 acima explicitamente. Nenhuma foi delegada nesta rodada.
Ficam a critério do planejamento: onde exatamente mora o carimbo da D-09 (tabela nova vs.
`Configuracao`), os valores default da D-03, o intervalo da D-04, e o refinamento da lista da D-12.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Fundação construída na Fase 129 (leitura obrigatória — esta fase reusa, não reimplementa)
- `.planning/phases/129-webhook-clicksign-v22-0/129-CONTEXT.md` — D-01 a D-14. Em especial:
  D-05 (a tabela `contrato_liberacoes` já nasceu com a coluna `via`, então `manual` e
  `reconciliacao` cabem SEM migration nova) e D-14 (o PDF pendente que a D-08 desta fase adota).
- `.planning/phases/129-webhook-clicksign-v22-0/129-VERIFICATION.md` — o que ficou provado e o
  que segue como gate humano aberto.
- `.planning/phases/129-webhook-clicksign-v22-0/129-REVIEW.md` — **CR-01**: a corrida que criava
  `MlbEmpresa` duplicada, corrigida com `Cache::lock()` por `company_id`
  (`EmpresaOperacionalRouter::lockDaEmpresa()`, commit `f50e123c`).
  ⚠️ **Isso já resolve o SC4 desta fase** — a corrida entre liberação manual e webhook usa o
  MESMO lock. O plano deve PROVAR que resolve, não reimplementar.

### Verdade empírica sobre a Clicksign (precedência sobre qualquer documentação)
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — §7 (links de PDF expiram em 5 min),
  §8 gate #10 (a consulta de envelope É suficiente para reconciliação: `status` +
  `meta.record_count` + eventos paginados; rate limit **20/min**), §12 (fórmula do HMAC medida).
- `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md` — rascunho é inerte; a ativação
  entrega eventos retroativos em rajada; `consultarEnvelope()` devolve o recurso DESEMBRULHADO
  (`attributes` no TOPO, não em `['data']` — já mordeu uma vez).

### Decisões da milestone
- `.planning/REQUIREMENTS-v22.md` §"Decisões travadas (LOCKED)" — D3 (o job de reconciliação é
  diferencial aceito, e existe porque a pesquisa não confirmou se a Clicksign emite webhook de
  prazo expirado), D4 (a rede de segurança é requisito, não melhoria futura), D5
  (`recusado`/`expirado` são estados próprios).
- `.planning/REQUIREMENTS-v22.md` §"Out of Scope" — **polling como mecanismo primário está
  explicitamente fora**; a reconciliação é rede de segurança, nunca o mecanismo principal.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Services/Operacional/EmpresaOperacionalRouter.php` — `liberarEmpresa()` (ponto único,
  idempotente) e `lockDaEmpresa(int $companyId): Lock` (protected, `Cache::lock()` por empresa,
  TTL 10s). Foi deixado reusável **de propósito para esta fase**.
- `app/Models/ContratoLiberacao.php` + tabela `contrato_liberacoes` — já tem `via`, autor,
  motivo e o índice único `cl_empresa_servico_uniq`.
- `app/Notifications/EmpresaHubspotPendenteNotification.php` — **o análogo mais próximo do
  alerta desta fase**: "empresa parada esperando alguém". Ver como é disparado em
  `HubspotWebhookController` (~linha 984-1000), incluindo o `Log::warning` quando a audiência
  vem vazia — mesma disciplina vale aqui.
- `app/Notifications/BaseNotification.php` — `via()` → `['database']`, payload canônico de 6
  chaves. Herdar, não reinventar.
- `app/Support/.../AudienciaComercial.php` — molde de classe de audiência. ⚠️ Ver D-02: o método
  existente é mais estreito que a audiência desta fase.
- `app/Services/Clicksign/ClicksignClient.php` — `consultarEnvelope()`, `consultarDocumento()`.
- `app/Jobs/BaixarPdfContratoAssinadoJob.php` — o job que a D-08 vai redisparar. Já reconsulta
  para link fresco a cada tentativa.
- `routes/console.php` — todo o agendamento do projeto, padrão `Schedule::command(...)->dailyAt()`.

### Established Patterns
- Alerta = notificação `database`, nunca e-mail. Consistente em todas as notificações existentes.
- Comandos agendados vivem em `routes/console.php` (Laravel 11+), não em `App\Console\Kernel`.
- Migrations: nomear TODA FK e índice à mão (MariaDB recusa nome > 64 chars e deixa a migration
  Pending com a tabela SEM o índice — quebrou na Fase 122); `string()` em vez de `enum` de banco
  (o SQLite dos testes não pega o CHECK); FK `nullOnDelete` exige coluna `nullable()` (erro 1830).

### Integration Points
- Entrada: comando agendado novo (reconciliação) + rota mínima só-admin (liberação manual).
- Saída: `liberarEmpresa()` com `via` em `reconciliacao`/`manual`; notificação para a audiência
  da D-02; redisparo de `BaixarPdfContratoAssinadoJob`.
- A Fase 131 vai substituir a tela da D-10 e trocar `role:admin` pela permissão `admin.contratos`.
  Deixe esses dois pontos isolados para a troca ser barata.

</code_context>

<specifics>
## Specific Ideas

- O usuário pediu explicitamente linguagem simples quando o assunto foi infraestrutura na fase
  anterior. Vale para qualquer texto de UI ou mensagem de alerta que esta fase gerar — a mensagem
  do alerta deve dizer **o que fazer a seguir**, não só que algo está errado (é a razão de ser da
  D5 da milestone, que separa "cliente recusou" de "a API caiu").
- Ambiente: PHP não está no PATH (`C:\xampp\php\php.exe`); MariaDB local é instável nesta máquina
  (foi reparado em 2026-08-12 restaurando as tabelas de sistema do XAMPP e caiu de novo);
  a migration `2026_07_07_100005_add_dedup_key_to_nps_surveys` está marcada como aplicada **sem
  ter rodado** (MariaDB 10.4.32 rejeita coluna gerada com `date_format()`, erro 1901).
  Testes rodam em SQLite e não são afetados.

</specifics>

<deferred>
## Deferred Ideas

- **Alerta por e-mail ou WhatsApp** — recusado na D-01 por quebrar o canal único do projeto.
  Se a experiência mostrar que o sino não é suficiente, vira fase própria (o `DigisacClient` já
  existe e hoje serve só ao NPS).
- **Tela definitiva do Administrativo e permissão `admin.contratos`** — Fase 131. A tela da D-10
  é assumidamente descartável.
- **Setor "Administrativo" como estrutura organizacional** — não existe hoje; a D-02 contorna com
  `role:admin` + setor comercial. Criar o setor é decisão de organização, não desta fase.
- **Painel de taxa/tempo de assinatura** — já estava fora de escopo pela D3 da milestone.
- **Ligar o bloqueio do roteamento** — Fase 133.

</deferred>

---

*Phase: 130-Rede de segurança — reconciliação, alerta e liberação manual (v22.0)*
*Context gathered: 2026-08-13*
