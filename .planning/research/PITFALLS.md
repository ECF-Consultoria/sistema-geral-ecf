# Pitfalls Research — Assinatura Eletrônica Bloqueante no Funil de Entrada

**Domínio:** Adicionar um gate de assinatura eletrônica (Clicksign) bloqueante a um funil de
entrada (HubSpot → Comercial → Operacional) já em produção, sem checkpoint humano no meio.
**Milestone:** v22.0 Administrativo + Clicksign
**Pesquisado:** 2026-08-07
**Confiança:** MEDIUM-HIGH — HMAC/idempotência verificados contra o código real do projeto
(`HubspotWebhookController.php`) e contra a documentação oficial da Clicksign (`developers.clicksign.com`).
Retry/ordem de entrega da Clicksign: a documentação oficial **não publica** SLA de retry nem
garantia de ordem — tratado abaixo como LOW confidence e resolvido pela via mais segura
(assumir o pior caso, que é o padrão da indústria para webhooks).

## Contexto verificado que muda a leitura de risco

Antes das armadilhas, três fatos verificados no código que mudam a prioridade de cada uma:

1. **A Clicksign não documenta retry nem ordem de entrega.** Fetch em
   `developers.clicksign.com/docs/melhores-praticas-webhooks` e `introducao-a-webhooks`
   não retornou número de tentativas, intervalo, nem garantia de sequência — só a
   recomendação de "responder rápido e processar em background". Isso **não é uma lacuna
   de pesquisa, é o dado**: como o provedor não garante ordem, o sistema tem que assumir
   desordem por padrão, não como caso extremo.
2. **O HMAC da Clicksign é estruturalmente mais simples e mais fraco que o do HubSpot já
   implementado no projeto.** HubSpot: `X-HubSpot-Signature-v3` = `base64(hmac_sha256(secret,
   METHOD+URI+body+timestamp))`, com timestamp obrigatório e janela de replay de 5 min
   (`HubspotWebhookController::REPLAY_WINDOW_MS`). Clicksign: header `Content-Hmac:
   sha256=<hash>` = `hmac_sha256(secret, body)` — **sem timestamp, sem method/URI, sem
   replay window nativo**. Copiar o padrão do HubSpot 1:1 vai quebrar (formato de header e
   fórmula do hash são diferentes); copiar a *disciplina* (raw body, `hash_equals`, log sem
   secret) é o que deve ser reaproveitado — a replay window precisa ser construída à mão
   porque a Clicksign não fornece timestamp para isso.
3. **O projeto já tem os dois blocos de infraestrutura que esta milestone mais precisa,
   prontos para reuso:** `Configuracao::get/set` (`app/Models/Configuracao.php`) é um
   key-value store já usado como feature flag em produção — é o kill switch sem deploy
   pedido pelo PROJECT.md, não precisa ser inventado. E `RelatorioMensalPdfService` +
   `resources/views/pdf/relatorio-bonificacao.blade.php` já resolveram DomPDF + acentuação
   pt-BR + CSS inline em produção — é o precedente direto para o PDF do contrato.

## Critical Pitfalls

### Pitfall 1: A ordem das Fases 8 e 9 do plano cria uma janela em que TODA empresa nova trava sem chance de liberação

**What goes wrong:**
O plano canônico (`plano-administrativo-clicksign.md`) descreve a Fase 8 ("Gatilhos do
Fluxo") como o momento em que `criarEmpresa()`/`ComercialController::store()` **param de
chamar o roteamento operacional automaticamente**, e só a Fase 9 ("Webhook Clicksign") cria
o mecanismo que libera a empresa de volta. Se essas duas fases forem deployadas em momentos
diferentes — o que é o padrão natural de entrega incremental do projeto —, existe uma janela
de deploy em que o bloqueio já está ativo mas **nada no sistema sabe liberar uma empresa**.
Toda empresa que entrar nesse intervalo fica presa permanentemente até alguém perceber e
liberar na mão.

**Why it happens:**
O plano foi desenhado como uma lista sequencial de fases sem marcar explicitamente qual
subconjunto tem que ir a produção **atomicamente** (no mesmo deploy). É natural, ao planejar
o roadmap, quebrar "bloquear o funil" e "criar o webhook que libera" em fases separadas
porque tecnicamente são dois PRs distintos — mas operacionalmente são um único evento.

**How to avoid:**
- O roadmap precisa marcar Fase 8 e Fase 9 (ou os nomes equivalentes que a numeração real
  do projeto usar, a partir da Fase 124) como **inseparáveis no deploy** — mesma janela,
  mesmo dia, idealmente o bloqueio automático entra **atrás de uma flag** (`Configuracao::get`)
  que só é ligada depois que o webhook + rede de segurança já estão em produção e
  verificados em sandbox ponta a ponta.
- Ordem recomendada: construir o webhook receiver ANTES de desligar o roteamento automático,
  não depois. O plano descreve a ordem inversa — vale inverter na hora do roadmap.
- A rede de segurança (kill switch, alerta de contrato preso, liberação manual — já
  elencada como requisito de primeira classe no PROJECT.md) precisa estar pronta e testada
  **antes** de qualquer flag de bloqueio ser ligada em produção, nunca depois "para cobrir
  o buraco".

**Warning signs:**
Se o roadmap tiver uma fase "bloquear roteamento automático" e uma fase "webhook Clicksign"
separadas por qualquer fase intermediária (ex.: "estrutura de dados", "PDF") sem uma flag
que mantenha o roteamento automático ligado nesse meio-tempo, o sinal de alerta já está lá
antes mesmo de escrever código.

**Phase to address:**
Estrutural — decide a ordenação de TODAS as fases da milestone. Deve ser resolvido no
roadmap, não numa fase específica: ou (a) fundir bloqueio + webhook + rede de segurança
numa única fase de "ir ao ar", ou (b) manter o bloqueio atrás de flag `Configuracao` desligada
por padrão até a fase de webhook e rede de segurança fecharem.

---

### Pitfall 2: Webhook "assinado" chega antes do "enviado" (ou o "enviado" nunca chega) e a assinatura não pode ser localizada

**What goes wrong:**
O fluxo desenhado persiste `clicksign_envelope_id` no momento em que o sistema CRIA o
envelope (chamada síncrona de saída). O webhook de evento de assinatura chega depois, via
rede, e busca a `ContratoAssinatura` por `clicksign_envelope_id`. Isso já elimina o caso
clássico de "webhook chegou antes do registro existir" (o registro é local e síncrono, o
webhook é assíncrono e sempre depois). O risco real e mais provável, dado que a Clicksign
não documenta ordem de eventos, é outro: dentro da SEQUÊNCIA de eventos de um mesmo
envelope (ex.: `document_closed` antes de um evento de assinatura individual, ou dois
eventos de assinantes diferentes fora de ordem), a lógica de "quando todos assinaram, libera"
pode disparar cedo demais (lida com estado parcial como se fosse final) ou nunca disparar
(espera um evento que já passou).

**Why it happens:**
É natural implementar a máquina de estado assumindo que os eventos chegam na ordem em que
fazem sentido lógico ("enviado" → "visualizado" → "assinado por A" → "assinado por B" →
"documento fechado"). Provedores de webhook em geral (e a Clicksign, pela ausência de
garantia documentada, se enquadra aqui) entregam em *at-least-once* sem ordem garantida —
duas requisições HTTP concorrentes podem ser processadas fora de ordem no lado do
receptor mesmo que tenham sido enviadas em ordem.

**How to avoid:**
- **Nunca inferir estado por "qual evento chegou por último"** — sempre reconsultar o
  estado real. Ao receber qualquer evento de assinatura, a decisão de liberar o operacional
  deve ser baseada em **consultar a Clicksign** (`consultarEnvelope`) ou em contar
  signatários com `signed_at` preenchido no banco local, nunca em "o evento X chegou, logo
  está tudo assinado".
  aplicar aqui a mesma disciplina que o projeto já usa para desempenho/bônus (ver
  `.planning/learnings/desempenho-bonificacao.md`: "conferir consolidação por reconsulta ao
  banco e nunca por stdout/logs") — traduzido para este domínio: **conferir liberação por
  reconsulta ao estado agregado, nunca pelo evento recebido isoladamente**.
- Tratar "libera operacional" como um cálculo idempotente disparado a cada evento relevante
  (`sign`, `document_closed`, `auto_close`), não como uma transição de estado feita só no
  evento "esperado". Se o evento de "assinado" chegar antes do de "enviado" simplesmente
  porque a rede reordenou, o sistema tem que aceitar e processar mesmo assim — o "enviado"
  correspondente, quando chegar depois, é apenas registrado (não deve reabrir nem regredir
  o estado).
- Regra explícita: **status nunca regride**. Se `ContratoAssinatura.status` já é `assinado`
  ou `liberado_operacional`, um evento tardio de estágio anterior (`enviado`, `visualizado`)
  é gravado em `contrato_assinatura_eventos` para auditoria mas não deve sobrescrever o
  status corrente. Mesmo padrão que `Sugador::STATUS_TRAVADOS` já usa no projeto para
  proteger status human-reviewed de sobrescrita por reprocessamento.

**Warning signs:**
`ContratoAssinatura` com `signed_at` preenchido mas `sent_at` nulo; empresa liberada ao
operacional com menos signatários "assinado" do que os obrigatórios cadastrados;
`released_to_operational_at` mais antigo que `signed_at` de algum signatário.

**Phase to address:**
Fase 9 (Webhook Clicksign) do plano canônico — a lógica de "quando libera" deve ser escrita
como recomputação de estado agregado a cada evento, com teste explícito simulando
`document_closed` chegando antes de `sign` de um signatário.

---

### Pitfall 3: Webhook duplicado ou retentativa do provedor cria efeito colateral duas vezes

**What goes wrong:**
A Clicksign, como qualquer provedor de webhook sem garantia documentada de entrega única,
pode reenviar o mesmo evento (falha de rede, timeout na resposta do receptor, retry
automático). Sem idempotência real, cada reentrega dispara `EmpresaOperacionalRouter::
liberarEmpresa()` de novo, criando uma segunda `MlbEmpresa`, disparando implementação
operacional duas vezes, ou reenviando notificação ao setor operacional duas vezes.

**Why it happens:**
"Webhook chegou, processa" é o caminho mais direto de implementar, e funciona bem em teste
manual (um evento por vez, no ritmo do desenvolvedor). O bug só aparece sob reentrega real,
que é rara e não aparece em ambiente de dev.

**How to avoid:**
- A tabela `contrato_assinatura_eventos` já foi desenhada no plano com `payload_hash`
  **unique** — isso é o guard de idempotência de INGESTÃO (não processar o mesmo payload
  bruto duas vezes). Precedente direto: `hubspot_eventos` já resolve isso de forma
  parecida hoje via idempotência de `object_id` + `company_id_criada NOT NULL`
  (`HubspotWebhookController::processar()`, guard "D-04").
- Isso não é suficiente sozinho: dois eventos DIFERENTES (ex.: `sign` do signatário A e
  depois `document_closed`) podem, cada um, tentar chamar `liberarEmpresa()`. A idempotência
  de INGESTÃO de evento não impede duplo efeito colateral entre eventos distintos que levam
  à mesma ação. É necessário um segundo guard, de EFEITO: `EmpresaOperacionalRouter::
  liberarEmpresa()` precisa checar se `MlbEmpresa` já existe para a empresa (o plano já
  prevê essa regra: "Não criar MlbEmpresa duplicada se a empresa já tiver uma") e se
  `ContratoAssinatura.released_to_operational_at` já está preenchido, ANTES de agir —
  igual ao guard que `HubspotWebhookController::rotearImplementacao()` já faz hoje
  (`MlbEmpresa::where('company_id', $company->id)->exists()`).
- Regra geral a aplicar: todo efeito colateral (criar `MlbEmpresa`, disparar implementação,
  notificar operacional) precisa ser idempotente **por si só**, e não depender só da
  idempotência do evento que o disparou.

**Warning signs:**
Duas linhas em `mlb_empresas` para o mesmo `company_id`; log de "implementação criada" duas
vezes para a mesma empresa no mesmo dia; `contrato_assinatura_eventos` com dois
`payload_hash` diferentes referenciando o mesmo `provider_event_id`.

**Phase to address:**
Fase 1 (Refatoração — `EmpresaOperacionalRouter::liberarEmpresa()`) precisa nascer
idempotente desde o primeiro commit, porque é reusada tanto pelo fluxo antigo quanto pelo
novo webhook. Fase 9 (Webhook) adiciona a camada de idempotência de ingestão por cima.

---

### Pitfall 4: Webhook que nunca chega — o risco central da milestone, sem alarme

**What goes wrong:**
Este é o risco central descrito no PROJECT.md: se a Clicksign não entregar o webhook
(instabilidade do provedor, URL de callback errada, firewall, mudança de token invalidando
a assinatura), a empresa fica para sempre em `aguardando_assinaturas` mesmo que todos os
signatários já tenham assinado do lado da Clicksign. Sem observabilidade dedicada, ninguém
percebe — a operação simplesmente para de receber empresas novas, silenciosamente.

**Why it happens:**
Webhooks são "fire and forget" do ponto de vista do sistema que recebe: não existe reação
natural a "evento que deveria ter chegado e não chegou", porque a ausência de um evento não
gera nenhum sinal por si só. É o tipo de falha que só aparece quando alguém pergunta
manualmente "cadê a empresa X".

**How to avoid:**
- **Reconciliação ativa, não só reativa a webhook.** Um comando agendado (padrão já usado
  no projeto para `SyncAdmanData`, `SyncGrantsFromSftp`) que consulta periodicamente
  (ex.: a cada 30-60 min) todo `ContratoAssinatura` em status `enviado` ou
  `aguardando_assinaturas` contra `ClicksignClient::consultarEnvelope()`, e corrige o
  estado local se a Clicksign já mostra `assinado`/finalizado e o webhook simplesmente não
  chegou. Isso é o "self-healing" que faz o webhook ser uma otimização de latência, não a
  única via de verdade.
- **Alerta de contrato preso além de N dias** — já é requisito de primeira classe no
  PROJECT.md ("rede de segurança"). Implementar como comando agendado que verifica
  `ContratoAssinatura` sem `released_to_operational_at` e `sent_at` mais antigo que N dias,
  e notifica via o mecanismo de notificação já existente no projeto (sino/Notification).
- **Nunca ligar o bloqueio sem essas duas peças em produção primeiro** — ver Pitfall 1.

**Warning signs:**
`ContratoAssinatura.status IN ('enviado', 'aguardando_assinaturas')` com `sent_at` há mais
de N dias e nenhum evento novo em `contrato_assinatura_eventos`.

**Phase to address:**
Rede de segurança — fase própria, deve ser fase-irmã do webhook (Fase 9), não uma fase
posterior "se sobrar tempo". PROJECT.md já trata isso como requisito de primeira classe;
o roadmap precisa dar a ela uma fase dedicada antes de qualquer flag de bloqueio ligar em
produção real (não sandbox).

---

### Pitfall 5: Validação HMAC copiada do padrão HubSpot sem ajustar para o formato real da Clicksign

**What goes wrong:**
O precedente do projeto (`HubspotWebhookController`) é sólido, mas a fórmula é específica
do HubSpot: `base64(hmac_sha256(secret, METHOD+URI+body+timestamp))` com header
`X-HubSpot-Signature-v3` e `X-HubSpot-Request-Timestamp`. A Clicksign usa
`hmac_sha256(secret, body)` (sem method/URI/timestamp), representado em hex e prefixado
`sha256=` no header `Content-Hmac`. Copiar a fórmula do HubSpot 1:1 (incluir método+URI+
timestamp no HMAC, ou esperar base64 em vez de `sha256=<hex>`) faz TODA validação falhar —
o `hash_equals` nunca bate, todo webhook real da Clicksign é rejeitado como inválido, e
ninguém percebe até checar os logs de erro 401.

**Why it happens:**
Reusar código que já funciona é o instinto certo, mas cada provedor de webhook desenha sua
própria fórmula de assinatura — não existe padrão universal. O erro mais provável não é
"esquecer HMAC", é "implementar HMAC errado com confiança alta porque já funcionou antes
para outro provedor".

**How to avoid:**
- Confirmar a fórmula exata na documentação oficial da Clicksign **no momento da
  implementação** (a API pode evoluir) — não confiar de memória no que está documentado
  aqui. No momento desta pesquisa: `Content-Hmac: sha256=<hex(hmac_sha256(secret, rawBody))>`.
- Reusar do HubSpot apenas os princípios, não a fórmula:
  - ler `$request->getContent()` (raw body), nunca `$request->all()` / `$request->input()`
    — body parseado pelo Laravel pode reordenar chaves JSON ou normalizar espaços, e o
    HMAC é calculado sobre os BYTES exatos recebidos;
  - comparar com `hash_equals()`, nunca `===` (timing attack);
  - nunca logar o secret, mesmo em erro;
  - truncar o raw body ao gravar evento inválido (o projeto já faz isso em 65KB).
- **Replay window: a Clicksign não fornece timestamp no payload/header para isso.** Não dá
  para replicar a checagem "> 5 min de diferença" do HubSpot porque não existe campo de
  timestamp de assinatura para comparar. Duas saídas realistas: (a) aceitar que HMAC sem
  timestamp não protege contra replay de uma requisição capturada — mitigar isso com
  idempotência de evento (Pitfall 3) em vez de replay window, já que o pior caso de um
  replay é reprocessar um evento já visto, o que a idempotência já cobre; ou (b) usar
  `event.occurred_at` do próprio payload (depois de validado o HMAC) como sinal de idade,
  sabendo que isso é auto-declarado pelo emissor e não é proteção criptográfica.

**Warning signs:**
100% dos webhooks de teste real (sandbox) retornando 401 mesmo com o secret certo
configurado; testes automatizados que só simulam o HMAC do jeito que o código calcula (dão
falso verde) em vez de fixture com HMAC calculado independentemente a partir da doc oficial.

**Phase to address:**
Fase 9 (Webhook Clicksign) — a validação HMAC deve ter teste automatizado com um payload +
HMAC **calculados fora do código de produção** (fixture fixa, não gerada pelo mesmo código
que valida), para garantir que a fórmula bate com a documentação e não só consigo mesma.

---

### Pitfall 6: Ligar o bloqueio sem observabilidade prévia — dashboard e alertas não são "nice to have"

**What goes wrong:**
Se o bloqueio for ligado em produção e a única forma de saber "quantas empresas estão
presas, há quanto tempo, em qual estágio" for consultar o banco na mão via Tinker/SQL, o
tempo até detectar um problema real é medido em dias, não minutos — exatamente o cenário
que o PROJECT.md descreve como risco central.

**Why it happens:**
Observabilidade é frequentemente tratada como polimento de UI ("fase de tela admin depois
que a lógica funciona"), quando neste caso ela é pré-condição de segurança operacional.

**How to avoid, métricas/alertas mínimos ANTES de ligar o bloqueio:**
- Contagem de empresas por status administrativo (`aguardando_comercial`,
  `pronto_para_contrato`, `gerando_contrato`, `enviado`, `aguardando_assinaturas`,
  `assinado`, `liberado_operacional`, `erro`) visível sem query manual — o plano já prevê
  isso na Fase 11 (cards de resumo na tela administrativa), mas a tela pode nascer DEPOIS
  do bloqueio estar ligado se a ordem de fases não for cuidada.
- Alerta de "contrato preso além de N dias" (Pitfall 4) — precisa existir e já ter disparado
  pelo menos uma vez em sandbox antes de confiar nele em produção.
- Alerta específico de "zero empresas liberadas ao operacional nas últimas 24h" — sinal
  indireto de que o webhook parou de chegar, mesmo sem saber a causa raiz ainda. É a rede
  de segurança que pega o caso "todo mundo está com HMAC quebrado desde o deploy" que os
  alertas por empresa individual demorariam a acumular.
- Log estruturado com o mesmo padrão do projeto (`Log::channel('ecf-webhooks')` já existe
  para HubSpot — reusar canal dedicado, ex.: `ecf-clicksign`, para poder isolar volume e
  erro por integração).

**Warning signs:**
Roadmap com a fase "bloqueio ligado" antes da fase "tela/alertas administrativos"; ausência
de qualquer teste manual do alerta de "preso" em sandbox antes do primeiro deploy real.

**Phase to address:**
Rede de segurança + Fase 11 (UI Administrativa) do plano — precisam anteceder ou, no mínimo,
ser simultâneas à fase que desliga o roteamento automático (Pitfall 1).

---

### Pitfall 7: Sandbox vs produção — configuração que só quebra no cutover

**What goes wrong:**
Vários erros de configuração da Clicksign só se manifestam na troca de sandbox para
produção, porque em sandbox tudo "funciona" com dados fictícios e endpoints diferentes:
- **Token trocado**: `CLICKSIGN_ACCESS_TOKEN` de sandbox continua no `.env` de produção (ou
  vice-versa) — a chamada HTTP para criar envelope falha com 401/403, mas só em produção.
- **`CLICKSIGN_BASE_URL` esquecida**: o plano já prevê a env
  (`https://sandbox.clicksign.com/api/v3` como default) — se o deploy não sobrescrever
  para a URL de produção, o sistema continua criando envelopes em sandbox silenciosamente
  depois de "ir ao ar", e contratos reais nunca chegam ao cliente.
- **URL de webhook não registrada no ambiente novo**: o webhook é cadastrado manualmente
  (via painel Clicksign ou API) por AMBIENTE — sandbox e produção são contas/URLs de
  callback separadas. Testar em sandbox e assumir que o cadastro "migra" para produção é o
  erro mais comum neste tipo de cutover.
- **Dados de teste vazando**: signatário de teste (email fictício da equipe) cadastrado
  como fallback/placeholder em código, esquecido, e usado em produção — ou o inverso,
  CNPJ/nome de empresa real usado em teste de sandbox e ficando em log/payload de teste.

**Why it happens:**
Sandbox e produção da Clicksign são ecossistemas paralelos e completamente independentes —
diferente de, por exemplo, trocar só uma flag de ambiente. Cada um tem token, webhook
cadastrado e (possivelmente) formato de signatário próprios. É fácil verificar "funciona em
sandbox" e assumir que a única mudança para produção é a URL base.

**How to avoid:**
- Checklist de cutover explícito e MANUAL (não automatizável de forma confiável) antes do
  primeiro envelope real: confirmar `CLICKSIGN_ENV=production`, `CLICKSIGN_BASE_URL` de
  produção, `CLICKSIGN_ACCESS_TOKEN` de produção, `CLICKSIGN_WEBHOOK_SECRET` de produção, E
  a URL `/api/webhooks/clicksign` cadastrada manualmente no painel Clicksign de produção
  (não só sandbox) — nenhum desses 4 itens tem detecção automática de "esqueceu".
  Documentar checklist na tela administrativa ou no runbook de deploy (o projeto já tem
  padrão de checklist em `deploy.sh`/scripts de deploy).
- Regra de "nunca logar token" já é convenção do projeto (aplicada no HubSpot) — replicar
  literalmente para Clicksign, incluindo em mensagens de erro do `ClicksignClient` (erros de
  HTTP client não podem ecoar o header `Authorization`).
- Fazer o primeiro envelope de produção com uma empresa de teste real controlada (ex.: a
  própria ECF Consultoria como cliente), não com uma empresa real de cliente, para validar
  o cutover sem risco de vazamento de contrato incorreto para um cliente de verdade.

**Warning signs:**
Envelope criado aparecendo no painel sandbox da Clicksign depois do "go-live" anunciado;
zero webhooks recebidos em produção nas primeiras horas após o cutover (sinal de URL não
cadastrada no ambiente certo).

**Phase to address:**
Fase 4 (Configuração Clicksign) define a estrutura; o CUTOVER em si (trocar de sandbox para
produção) deveria ser o próprio último passo, tratado como uma fase/checkpoint humano
dedicado — não embutido silenciosamente dentro de outra fase técnica.

---

### Pitfall 8: DomPDF — armadilhas específicas de gerar contrato jurídico em pt-BR

**What goes wrong:**
O projeto já tem dois precedentes de sucesso com DomPDF
(`RelatorioMensalPdfService`/`relatorio-bonificacao.blade.php`), então as armadilhas mais
básicas (fonte sem suporte a acentuação, CSS Tailwind incompatível) já estão resolvidas e
documentadas em código — o risco real para ESTE caso de uso é diferente, porque um contrato
jurídico tem características que um relatório interno não tem:
- **Texto longo com quebra de página mal controlada**: cláusulas contratuais geram páginas
  múltiplas; sem `page-break-inside: avoid` em blocos de cláusula, uma cláusula pode ser
  cortada no meio entre duas páginas de forma ilegível.
- **Tabelas de valores/serviços que estouram a largura da página** quando o nome do serviço
  ou o valor formatado é mais longo que o esperado em teste.
- **Caracteres especiais em nome de empresa/razão social** (aspas curvas, "&", símbolos
  vindos de copy-paste do HubSpot) que quebram o layout ou geram erro de parsing se não
  forem escapados corretamente na Blade (`{{ }}` já escapa, mas dados vindos de
  `hubspot_snapshot` como JSON bruto interpolado sem escape são um risco).
- **Tamanho do arquivo**: logo em base64 inline (padrão já adotado no projeto, ver
  `RelatorioMensalPdfService::gerar()`) é a prática certa para evitar dependência de rede
  no Dompdf, mas se o contrato tiver mais de uma imagem (ex.: assinatura de exemplo, selo),
  o binário pode crescer rápido — vale medir o tamanho final do PDF em teste antes de
  assumir que o approach escala.

**Why it happens:**
Relatórios internos (o precedente do projeto) são gerados, lidos e descartados — ninguém
audita quebra de página num relatório mensal. Um contrato é um documento legal que o
cliente vai ler linha por linha e potencialmente contestar — o padrão de qualidade exigido
é mais alto, mas o código reusa o mesmo padrão de "PDF interno".

**How to avoid:**
- Reusar literalmente os fundamentos já validados no projeto: `<meta charset="UTF-8">`,
  `font-family: 'DejaVu Sans', sans-serif` (fonte padrão do Dompdf com suporte UTF-8
  completo — comentário já presente em `mensal-pdf.blade.php`), CSS inline na Blade (nunca
  Tailwind/classes utilitárias), logo em base64 inline.
- Isolar o texto jurídico das cláusulas em um arquivo/config separado da lógica de
  montagem de dados (o plano já pede isso explicitamente na Fase 7: "o texto jurídico deve
  ficar isolado para troca futura") — isso também facilita revisão jurídica sem tocar em
  código.
- Adicionar `page-break-inside: avoid` nos blocos de cláusula e testar com um contrato de
  conteúdo realisticamente longo (múltiplos serviços, cláusulas completas), não só com
  fixture curta de teste.
- Testar geração de PDF com nome de empresa/contato reais extremos do banco atual (nomes
  muito longos, com caracteres especiais) antes de considerar a fase pronta — não confiar
  só em fixture "Empresa Teste Ltda".

**Warning signs:**
PDF gerado em teste automatizado sempre com a mesma fixture curta (nunca testado com dado
"feio" real); ausência de teste que meça o tamanho do arquivo final; cláusula cortada ao
meio visível em revisão manual do primeiro PDF real gerado.

**Phase to address:**
Fase 7 (Geração do PDF do Contrato) — testar cedo com dados reais extraídos do banco atual
(nomes de empresa existentes), não só com fixtures sintéticas.

---

### Pitfall 9: Dados faltando na hora de gerar o contrato — validar ANTES de criar o envelope, não depois

**What goes wrong:**
Nem toda `Company` no banco atual tem `email_cliente`, `cnpj` ou `nome_contato` preenchidos
— o próprio webhook HubSpot já lida com isso hoje como campos nullable e opcionais
(`enriquecerEmpresaExistente` só preenche se "vazio", tolerando ausência). Se
`ContratoClicksignService::iniciarParaEmpresa()` tentar criar o envelope sem checar esses
campos antes, duas coisas ruins acontecem: (a) a chamada HTTP para a Clicksign falha
depois de já ter gerado o PDF (trabalho desperdiçado, e potencialmente um envelope
"órfão" criado do lado da Clicksign sem documento/signatário completo); ou (b) pior, o
envelope é criado com um signatário com e-mail vazio/inválido e a Clicksign aceita mas
nunca notifica ninguém — o contrato fica "enviado" para sempre sem chance real de ser
assinado, indistinguível de um webhook que não chegou (Pitfall 4).

**Why it happens:**
O caminho feliz de teste sempre usa uma empresa com todos os campos preenchidos. Empresas
reais legadas ou criadas via HubSpot com dados incompletos (o próprio `PendenciasComerciaisService`
mencionado no plano já reconhece isso como classe de problema: `sem_contato`,
`servico_nao_reconhecido` etc.) só aparecem em produção.

**How to avoid:**
- Validação de pré-condição **explícita e centralizada** antes de qualquer chamada HTTP à
  Clicksign — o plano já prevê isso ("Se faltar e-mail do cliente ou nome do contato,
  devolver erro claro para o Comercial corrigir"), mas o ponto crítico é a ORDEM: validar
  ANTES de gerar o PDF e ANTES de criar o envelope, não deixar a Clicksign ser quem
  descobre o dado faltando.
  Reusar a mesma lista de pendências que `PendenciasComerciaisService` já centraliza
  (o plano já desenha esse service compartilhado entre Comercial/HubSpot/Administrativo) —
  não criar uma segunda lista de validação divergente só para o Clicksign.
- CNPJ merece validação de formato, não só presença — CNPJ malformado (dígito verificador
  errado, ex.: copiado errado do HubSpot) pode ser aceito por um campo de texto livre e só
  falhar (ou pior, ser aceito incorretamente) do lado da Clicksign.
- Estado `erro` com `error_message` legível é o fallback correto quando a validação falha
  DEPOIS de já ter tentado — mas o objetivo é nunca chegar lá: falhar cedo, antes de gastar
  a chamada HTTP.

**Warning signs:**
`ContratoAssinatura.status = 'erro'` com mensagem vinda da API da Clicksign em vez de vinda
da validação local; qualquer empresa passando por `iniciarParaEmpresa()` sem
`email_cliente` ter sido checado antes.

**Phase to address:**
Fase 6 (Service Administrativo de Contrato) — a validação de pré-condição deve ser o
PRIMEIRO passo de `iniciarParaEmpresa()`, com teste automatizado cobrindo "empresa sem
e-mail não gera envelope" como caso obrigatório (o plano já lista isso na Fase 13 de testes
— importa garantir que a implementação segue a ordem, não só o resultado).

---

### Pitfall 10: Empresas já roteadas antes do cutover não podem ser "pegas" pela nova regra retroativamente

**What goes wrong:**
O plano já identifica esse risco corretamente ("Empresas antigas que já possuem
`mlb_empresas` não devem ser alteradas"), mas o ponto de atenção é COMO essa exceção é
implementada. Se a lógica for "pula empresa se `MlbEmpresa` existir" checada dentro do
próprio fluxo de criação (`criarEmpresa`), isso protege bem o caso de uma empresa NOVA que
está sendo processada. O risco mais sutil é um REPROCESSAMENTO de evento antigo (o projeto
já tem o comando `hubspot:reprocess-event` para isso, ver
`HubspotWebhookController::reprocessarEvento()`) de uma empresa que já foi roteada há
meses — se o reprocessamento passar pelo mesmo caminho que agora exige contrato assinado,
uma empresa antiga e legítima pode ficar "presa" retroativamente esperando um contrato que
nunca foi gerado porque ela entrou antes dessa exigência existir.

**Why it happens:**
`reprocessarEvento()` já existe e é usado ativamente para corrigir dados de empresas
antigas (dedup, contratos faltando) — é natural que ele passe pelo mesmo `criarEmpresa()` /
`persistirContratos()` que vai ganhar a nova checagem administrativa. Sem um critério
temporal explícito de "quando essa empresa foi criada", o replay de um evento de 2026-05
pode ser avaliado com a regra de 2026-08.

**How to avoid:**
- Critério explícito de "empresa migrada/legada": se `MlbEmpresa` já existe para a
  `company_id` (ou, alternativa mais robusta, se a `Company` foi criada antes da data de
  corte do cutover), o roteamento operacional NÃO deve exigir `ContratoAssinatura` — a
  checagem de "está pronta pro operacional" precisa ser condicional a essa exceção, não só
  a criação de `MlbEmpresa` ser pulada por já existir.
- Rodar uma auditoria/relatório ANTES do cutover: quantas empresas estão em qual estágio
  hoje (todas roteadas = todas "legadas" por definição), para que o critério de corte seja
  verificável contra dados reais, não assumido.
- Testar explicitamente `hubspot:reprocess-event` contra uma empresa antiga real (ou
  fixture que simule uma) DEPOIS que a checagem administrativa existir, não só testar
  criação de empresa nova.

**Warning signs:**
Empresa com `mlb_empresas` criada há meses e `contrato_assinaturas` vazio ficando bloqueada
num fluxo de "reenviar operacional" ou qualquer ação administrativa que passe pela nova
checagem.

**Phase to address:**
Fase 1 (Refatoração) — o guard de "empresa legada" precisa ser parte do design de
`EmpresaOperacionalRouter` desde o início, não um patch adicionado depois que alguém relatar
uma empresa antiga travada.

---

### Pitfall 11: Rollback tarde demais — empresas presas em "aguardando_assinaturas" sem saída limpa

**What goes wrong:**
Se a milestone precisar ser revertida depois de já estar em produção (bug crítico, decisão
de negócio, problema de integração irrecuperável), reverter só o CÓDIGO (deploy do
commit anterior) não resolve o problema de DADOS: empresas que já entraram no fluxo novo e
estão em `enviado`/`aguardando_assinaturas` ficam órfãs — o código antigo não sabe o que
fazer com uma `Company` sem `MlbEmpresa` e sem contrato assinado, porque essa combinação de
estado não existia antes desta milestone.

**Why it happens:**
Rollback de código é reversível por natureza (é só voltar o deploy); rollback de ESTADO não
é — uma vez que uma empresa está em `aguardando_assinaturas` com um envelope real criado
na Clicksign (possivelmente já parcialmente assinado por um cliente), simplesmente reverter
o código não desfaz isso, e o cliente pode já ter recebido o e-mail da Clicksign.

**How to avoid:**
- Rollback de código sozinho **nunca** deve ser o plano de saída — o requisito de "kill
  switch sem deploy" já cobre o caso de precisar desligar o bloqueio SEM reverter código:
  usar `Configuracao::set('clicksign.bloqueio_ativo', false)` para voltar ao comportamento
  antigo (roteamento automático) sem precisar reverter nenhum commit, preservando o
  histórico de contratos já criados.
- Definir, como parte da rede de segurança (não como afterthought), a ação de "liberação
  manual pelo admin com registro de quem liberou e por quê" (já é requisito do PROJECT.md)
  — essa é literalmente a via de saída para as empresas presas no meio do caminho se a
  decisão for abandonar a exigência de assinatura para elas: um admin libera manualmente,
  registrado, sem esperar a Clicksign.
- Ter uma via explícita de "cancelar processo administrativo e voltar a empresa para o
  fluxo antigo" (o plano já prevê status `cancelado` em `ContratoAssinatura`) — cancelar
  não é o mesmo que assinar, então o cancelamento precisa, opcionalmente, poder liberar a
  empresa ao operacional mesmo sem assinatura (decisão de negócio, registrada) em vez de
  deixá-la travada para sempre em `cancelado`.
- Nunca depender de deletar dados como estratégia de rollback — `contrato_assinaturas`/
  `contrato_assinatura_eventos` de empresas já processadas são histórico permanente
  (auditoria), mesmo que a milestone seja abandonada depois.

**Warning signs:**
Qualquer plano de rollback que mencione só "reverter o deploy" sem mencionar o que
acontece com `ContratoAssinatura` em estado intermediário; ausência de teste manual da
liberação manual admin ANTES do primeiro deploy real.

**Phase to address:**
Rede de segurança — o kill switch e a liberação manual admin são, ao mesmo tempo, a
proteção operacional do dia a dia E o plano de rollback. Precisam existir e estar testados
antes de qualquer deploy que ligue o bloqueio de verdade.

---

## Technical Debt Patterns

| Atalho | Benefício imediato | Custo de longo prazo | Quando é aceitável |
|--------|--------------------|-----------------------|---------------------|
| Copiar `HubspotWebhookController` como template sem adaptar a fórmula HMAC | Velocidade de implementação | Todo webhook real rejeitado como inválido (Pitfall 5) | Nunca — adaptar a fórmula é obrigatório, não opcional |
| Ligar bloqueio automático e webhook no mesmo PR sem flag intermediária | Menos complexidade de código | Sem via de saída rápida (kill switch) se algo falhar no primeiro dia | Só se a rede de segurança (Pitfall 6/11) já estiver validada em sandbox com testes de carga leve |
| Não testar reprocessamento de evento antigo contra a nova regra | Menos trabalho de teste | Empresa legada trava retroativamente (Pitfall 10) | Nunca — é um teste barato de escrever |
| Validar dados faltando só do lado da Clicksign (deixar a API rejeitar) | Menos código de validação local | Envelope órfão, erro tardio, desperdício de PDF gerado (Pitfall 9) | Nunca — validação local é mais barata e mais rápida que uma chamada HTTP |
| PDF sem `page-break-inside: avoid` nas cláusulas | Menos CSS pra escrever | Contrato jurídico com cláusula cortada, problema de imagem legal da empresa | Só em rascunho interno, nunca no contrato que vai ao cliente |

## Integration Gotchas

| Integração | Erro comum | Abordagem correta |
|------------|------------|--------------------|
| Clicksign — HMAC | Reusar a fórmula do HubSpot (`method+uri+body+timestamp`, base64) | `hmac_sha256(secret, rawBody)` em hex, header `Content-Hmac: sha256=<hash>` — confirmar na doc oficial no momento da implementação |
| Clicksign — replay window | Assumir que existe timestamp pra comparar, como no HubSpot | Não existe timestamp de assinatura nativo; mitigar via idempotência de evento, não via janela de tempo |
| Clicksign — ordem de eventos | Assumir que "enviado" sempre chega antes de "assinado" | Tratar cada evento como possivelmente fora de ordem; decisão de liberar sempre por reconsulta de estado agregado, nunca por qual evento chegou |
| Clicksign — sandbox/produção | Assumir que cadastro de webhook "migra" junto com o deploy | Cadastro de webhook é por ambiente/conta — precisa ser recriado manualmente em produção |
| DomPDF | Usar classes/CSS do Tailwind na Blade do PDF | CSS inline, `DejaVu Sans`, `<meta charset="UTF-8">` — padrão já validado em `mensal-pdf.blade.php` |
| DomPDF | Carregar logo/imagem por URL externa | Base64 inline (padrão já usado em `RelatorioMensalPdfService`) |

## Security Mistakes

| Erro | Risco | Prevenção |
|------|-------|-----------|
| Logar `CLICKSIGN_ACCESS_TOKEN` ou `CLICKSIGN_WEBHOOK_SECRET`, mesmo em mensagem de erro | Vazamento de credencial em log persistente | Nunca interpolar env de credencial em `Log::` — mesma disciplina já aplicada ao secret do HubSpot |
| Comparar HMAC com `===` em vez de `hash_equals()` | Timing attack (viabiliza forjar assinatura por medição de tempo) | Sempre `hash_equals()`, nunca comparação direta de string |
| Validar HMAC sobre `$request->input()`/`$request->all()` em vez do raw body | Assinatura nunca bate de forma consistente, ou pior, bate por engano com payload adulterado se o parsing normalizar diferenças | Sempre `$request->getContent()` |
| Rota do webhook Clicksign sem throttle | Superfície de negação de serviço / brute-force de assinatura | Aplicar o mesmo throttle (60/min) já usado na rota do webhook HubSpot |
| CNPJ/e-mail de cliente indo para o PDF sem sanitização de dado vindo de fonte externa (HubSpot) | Injeção de conteúdo malformado no documento legal | Escapar via Blade (`{{ }}`) sempre; nunca interpolar `hubspot_snapshot` bruto sem passar por um accessor validado |

## UX Pitfalls

| Armadilha | Impacto no usuário | Abordagem melhor |
|-----------|---------------------|---------------------|
| Comercial não sabe por que uma empresa "sumiu" do fluxo operacional depois do fechamento | Confusão, retrabalho de "onde está essa empresa?" | Badge de status administrativo na listagem Comercial (já previsto na Fase 11 do plano) — visível desde o primeiro dia do bloqueio, não numa fase futura |
| Admin só descobre que faltou e-mail do cliente quando o envelope já falhou | Perda de tempo, contrato tem que ser regenerado do zero | Mensagem de erro clara apontando exatamente o campo faltando, direcionando para onde corrigir (Comercial), não um erro genérico da API |
| Cliente recebe e-mail de assinatura da Clicksign em ambiente de sandbox por engano | Confusão externa, risco de imagem da empresa | Checklist de cutover (Pitfall 7) + teste do primeiro envelope de produção com destinatário interno controlado |

## "Looks Done But Isn't" Checklist

- [ ] **Validação HMAC:** parece pronta se passar em teste com fixture gerada pelo próprio
  código — verificar se existe um teste com HMAC calculado independentemente (fora do
  código de produção) a partir da fórmula real da Clicksign.
- [ ] **Idempotência do webhook:** parece pronta se `payload_hash` unique existir na tabela
  — verificar se também existe guard de EFEITO colateral (não só de ingestão) em
  `EmpresaOperacionalRouter::liberarEmpresa()`.
- [ ] **Rede de segurança:** parece pronta se a tela admin mostrar os cards de status —
  verificar se o alerta de "preso há N dias" já disparou pelo menos uma vez em sandbox, e
  se o kill switch (`Configuracao`) já foi testado desligando o bloqueio sem deploy.
- [ ] **PDF do contrato:** parece pronto se renderizar com a fixture de teste — verificar
  com nome de empresa real extremo (longo, com caractere especial) e medir quebra de
  página com conteúdo de cláusulas completo, não só lorem ipsum curto.
- [ ] **Cutover sandbox→produção:** parece pronto se os 4 valores de env estiverem no
  `.env.example` — verificar se o webhook foi cadastrado manualmente na conta de PRODUÇÃO
  da Clicksign (isso não é automatizado por nenhuma migration/seed).
- [ ] **Empresas legadas:** parece protegido se `criarEmpresa()` pular ao ver
  `MlbEmpresa` existente — verificar se `hubspot:reprocess-event` contra uma empresa antiga
  também não a prende retroativamente na nova checagem administrativa.

## Recovery Strategies

| Pitfall | Custo de recuperação | Passos |
|---------|------------------------|--------|
| Bloqueio ligado sem webhook pronto (Pitfall 1) | ALTO se não detectado rápido | Desligar `Configuracao` de bloqueio imediatamente; auditar todas as empresas criadas na janela e roteá-las manualmente ao operacional; comunicar ao time |
| Webhook nunca chega para uma empresa específica (Pitfall 4) | BAIXO com reconciliação ativa, ALTO sem ela | Comando de reconciliação consulta `consultarEnvelope` e corrige estado; sem esse comando, correção é manual via Tinker/admin |
| HMAC mal implementado, todos os webhooks 401 (Pitfall 5) | MÉDIO | Corrigir a fórmula, reprocessar manualmente os eventos que a Clicksign registrou como enviados mas que retornaram 401 (consultar o painel Clicksign para saber quais) |
| Empresa legada presa por reprocessamento (Pitfall 10) | BAIXO | Liberação manual admin (Pitfall 11) resolve caso a caso; se for padrão recorrente, ajustar o guard temporal |
| Rollback com empresas presas em `aguardando_assinaturas` (Pitfall 11) | ALTO sem kill switch pronto, BAIXO com ele | Kill switch desliga bloqueio; liberação manual admin resolve as presas uma a uma, com registro de motivo |

## Pitfall-to-Phase Mapping

| Armadilha | Fase de prevenção (nomes do plano canônico) | Como verificar que foi prevenida |
|-----------|----------------------------------------------|-----------------------------------|
| Janela sem liberação entre bloqueio e webhook | Ordenação do roadmap — Fase 8 e Fase 9 devem ser atômicas ou atrás de flag | Nenhuma empresa criada em produção pode ficar presa sem via de liberação em nenhum momento entre deploys |
| "Assinado" antes de "enviado" / eventos fora de ordem | Fase 9 (Webhook Clicksign) | Teste automatizado simulando `document_closed` antes de `sign` de um signatário |
| Webhook duplicado / retry duplica efeito colateral | Fase 1 (`EmpresaOperacionalRouter`) + Fase 9 (idempotência de ingestão) | Teste automatizado chamando `liberarEmpresa()` duas vezes para a mesma empresa — resultado deve ser idêntico ao de uma chamada |
| Webhook que nunca chega | Rede de segurança (fase própria, simultânea à Fase 9) | Comando de reconciliação existe, roda agendado, e um teste manual em sandbox confirma que ele corrige um `ContratoAssinatura` travado |
| HMAC copiado errado do HubSpot | Fase 9 (Webhook Clicksign) | Teste com HMAC calculado fora do código de produção, a partir da fórmula documentada |
| Bloqueio sem observabilidade | Rede de segurança + Fase 11 (UI Administrativa) | Cards de status e alerta de "preso" testados em sandbox ANTES do primeiro deploy com bloqueio real ligado |
| Cutover sandbox→produção mal configurado | Fase 4 (Configuração) + checkpoint humano dedicado de cutover | Checklist manual de 4 itens (env/base_url/token/webhook cadastrado) confirmado por humano, não assumido |
| DomPDF — quebra de página, dado extremo | Fase 7 (Geração do PDF) | PDF gerado e revisado manualmente com dado real extremo (nome longo, caractere especial), não só fixture curta |
| Dados faltando gerando envelope quebrado | Fase 6 (Service Administrativo de Contrato) | Teste automatizado: empresa sem `email_cliente` não chega a chamar a Clicksign |
| Empresa legada presa retroativamente | Fase 1 (Refatoração) | Teste de `hubspot:reprocess-event` contra empresa antiga real/fixture, após a checagem administrativa existir |
| Rollback sem via de saída limpa | Rede de segurança (kill switch + liberação manual) | Kill switch testado desligando bloqueio sem deploy; liberação manual admin testada ponta a ponta antes do primeiro deploy real |

## Sources

- Código do projeto (fonte primária, HIGH confidence):
  `app/Http/Controllers/Api/HubspotWebhookController.php` — precedente de HMAC, replay
  window, idempotência de ingestão e de efeito colateral, guard anti-duplicidade
  (`rotearImplementacao`), padrão de log sem secret.
  `app/Models/Configuracao.php` — precedente de kill switch/feature flag via DB.
  `app/Services/RelatorioMensalPdfService.php` +
  `resources/views/emails/relatorios/mensal-pdf.blade.php` +
  `resources/views/pdf/relatorio-bonificacao.blade.php` — precedente de DomPDF + pt-BR +
  CSS inline + logo base64, já validado em produção.
  `plano-administrativo-clicksign.md` (raiz do repo) — plano canônico da milestone,
  citado nas fases referenciadas acima.
  `.planning/PROJECT.md` — contexto de risco central e requisitos de rede de segurança da
  v22.0.
- Documentação oficial Clicksign (MEDIUM confidence — via WebFetch, conteúdo resumido por
  modelo intermediário, revalidar contra a doc completa no momento da implementação):
  `https://developers.clicksign.com/docs/introducao-a-webhooks`
  `https://developers.clicksign.com/docs/melhores-praticas-webhooks`
  `https://developers.clicksign.com/reference/evento-document-closed.md`
  `https://ajuda.clicksign.com/article/737-adicionar-webhooks`
- LOW confidence / lacuna documentada: retry policy (número de tentativas, intervalo) e
  garantia de ordem de entrega da Clicksign não foram encontrados em nenhuma fonte
  consultada — tratado como "assumir o pior caso" (sem garantia), que é a prática segura
  padrão da indústria de webhooks quando o provedor não documenta explicitamente uma
  garantia mais forte.

---
*Pitfalls research for: assinatura eletrônica bloqueante (Clicksign) em funil de entrada já em produção*
*Pesquisado: 2026-08-07*
