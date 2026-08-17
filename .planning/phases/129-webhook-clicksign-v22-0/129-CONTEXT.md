# Phase 129: Webhook Clicksign (v22.0) - Context

**Gathered:** 2026-08-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Receber o que a Clicksign avisa, provar que o aviso é legítimo, e decidir — **sempre por
reconsulta** — quando cada serviço da empresa está liberado para o operacional. Inclui: validação
de assinatura do webhook (gate bloqueante A1), gravação bruta de todo evento, idempotência por
`payload_hash`, processamento pesado em fila, e download do PDF assinado para storage próprio.

**Fora desta fase:** o alerta de contrato preso e o job de reconciliação (Fase 130), a tela
administrativa (Fase 131), e ligar o bloqueio do roteamento (Fase 133). Esta fase **produz o
estado** que a Fase 130 vai ler para alertar; não constrói canal de alerta novo.

</domain>

<decisions>
## Implementation Decisions

### Liberação operacional com N contratos por empresa

Fato do código que ancora toda esta seção: `ContratoClicksignService` faz um loop nos
`ContratoServico` ativos e cria **um envelope Clicksign por serviço** (D-06 da Fase 127). Uma
empresa com Gestão + Assessoria + Mentoria tem 3 contratos independentes, assinados em momentos
diferentes — ou nunca.

- **D-01: A liberação é POR SERVIÇO, cada um no seu tempo.** Cada serviço é liberado quando o
  contrato DELE é assinado. Recusadas: "só quando todos assinarem" (um serviço preso seguraria os
  outros, e o cliente que assinou Gestão ficaria esperando a Mentoria) e "no primeiro assinado"
  (a empresa estaria sendo atendida num serviço cujo contrato ainda não foi assinado).
  O roteador já tem a porta certa: `EmpresaOperacionalRouter::rotearServico(Company, string $nomeServico)`.

- **D-02: Uma ficha operacional só, enriquecida.** O primeiro contrato assinado cria a
  `MlbEmpresa`; os seguintes apenas acrescentam o que falta (implementação, projeto). Recusada
  "uma ficha por serviço" — a mesma empresa apareceria N vezes no operacional e a corrida da
  Fase 130 (liberação manual simultânea) ficaria muito mais difícil.
  ⚠️ **Consequência para o planejamento:** `rotearCadastro()` tem hoje o guard `guardPorEmpresa`
  que garante 1 `MlbEmpresa` para um cadastro com N serviços. Liberando um serviço por vez ao
  longo de semanas, esse guard **nunca vê os serviços juntos** — a garantia de ficha única precisa
  existir no caminho por-serviço, não pode ser herdada do guard atual.

- **D-03: "Liberada" é estado próprio, gravado independente de gerar ficha.** Medido na Fase 128:
  "Gestão" sozinho **não** gera `MlbEmpresa` — só Polos, Assessoria e Incubadora disparam ficha
  (`ComercialController::servicoDisparaImplementacao()`). Uma empresa só-Gestão pode assinar o
  contrato e, do ponto de vista do operacional, nada acontecer. Criar ficha é **consequência**
  da liberação, nunca a definição dela. Sem isto, o alerta da Fase 130 ("empresa sem liberação há
  X dias") acusaria falso positivo eterno em toda empresa só-Gestão / só-Mentoria.

- **D-04: Contrato recusado ou expirado NÃO mexe no cadastro.** Empresa já liberada pela Gestão,
  contrato da Mentoria recusado: o `ContratoServico` continua ativo, o contrato fica em
  `recusado`/`expirado` (estados próprios pela D5 da milestone) e vira alerta para o
  Administrativo. A decisão de tirar o serviço da empresa é **humana**. Recusada a desativação
  automática — o sistema apagaria sozinho algo que o Comercial cadastrou, sem desfazer fácil.

- **D-05: Tabela nova de liberações, com histórico.** Cada liberação vira uma linha registrando
  quem, quando, por quê e **por qual via** (webhook automático ou liberação manual). Recusadas:
  campo em `ContratoAssinatura` (perderia histórico e não acomodaria a liberação manual auditada
  da Fase 130) e campo em `ContratoServico` (separaria a liberação da evidência que a causou).
  Esta tabela é o contrato de leitura para a Fase 130 (alerta + liberação manual) e para a
  Fase 131 (tela).

- **D-06: A reconsulta da D7 é do envelope QUE MUDOU, não de todos os da empresa.** Chegou webhook
  do envelope da Gestão → reconsulta aquele envelope e seus signatários → decide a liberação
  daquele serviço. Os outros serviços têm os próprios webhooks. Motivo concreto: rate limit
  **medido** de 20 chamadas/min no sandbox; reconsultar N envelopes por evento faria uma empresa
  de 3 serviços gastar 3 chamadas por evento e um lote de assinaturas estouraria o limite.
  Isto **não** enfraquece a D7 da milestone — "estado agregado" significa o estado agregado
  daquele envelope (todos os seus signatários), nunca o payload isolado do evento.

### Gate A1 — a fórmula do HMAC (BLOQUEANTE)

- **D-07: O webhook real chega por TÚNEL para a máquina local.** O usuário sobe o túnel
  (ngrok/cloudflared) e cola a URL no painel do sandbox Clicksign; Claude escreve a rota que
  recebe e calcula as fórmulas. **Recusado o VPS**: um deploy publica tudo que estiver commitado
  na árvore, inclusive trabalho de outras sessões e do outro dev (ver
  `project_deploy_working_tree_compartilhado` na memória do projeto) — risco desproporcional para
  medir um hash. Bônus: com túnel dá para repetir o teste quantas vezes for preciso.

- **D-08 (discrição do Claude): varrer VÁRIAS variantes num único webhook.** A A1 lista duas
  fórmulas candidatas (`hash('sha256', body.secret)` vs `hex(hmac_sha256(secret, body))`), mas a
  doc oficial desta integração já errou duas vezes em pontos medidos (`Authorization` sem
  `Bearer`, `content_base64` exigindo Data URI). A rota loga de uma vez: hex vs base64, ordem
  `body+secret` vs `secret+body`, com e sem o header de timestamp no material assinado — nunca o
  secret. Motivo: cada webhook real custa configuração manual do usuário e cota do sandbox; gastar
  um por tentativa é desperdício. **Se a varredura ampla também não bater, a fase PARA** e abre
  investigação dedicada — não improvisar sobre gate bloqueante.

- **D-09 (discrição do Claude): a rota-sonda é temporária; a capacidade vira comando Artisan.**
  A rota que recebe o webhook do túnel some depois da medição. Como a DADOS-03 obriga gravar
  **todo evento bruto**, a verificação vira um comando que pega qualquer evento já gravado e diz
  se a assinatura confere e por qual fórmula. Sem superfície pública, reaproveitável no cutover
  de produção (o secret de produção é **outro**), e testável contra evento real em vez de fixture
  inventado. Padrão já existente no projeto: `app/Console/Commands/ClicksignSondarModelo.php`.

- Lembrete do ROADMAP (SC1, não é decisão desta discussão): o fixture de HMAC do teste
  automatizado **deve** ser calculado fora do código de produção.

### Resposta HTTP ao provedor (A3)

- **D-10: A resposta depende do TIPO de erro.** 5xx quando o erro é transitório (banco
  indisponível, falha ao enfileirar) — reenviar resolve, e o dedup por `payload_hash` torna o
  reenvio seguro. 200 quando o erro é do payload em si — reenviar não conserta e viraria loop até
  a Clicksign desistir (a política de retry dela é o gate empírico #11, **ainda aberto**).

- **⚠️ Precisão que limita o alcance da D-10:** como a CLICK-06 manda o processamento pesado para
  a fila, **a resposta HTTP sai antes de processar**. A janela síncrona é estreita: validar
  assinatura, gravar o evento bruto, enfileirar. Só isso pode ser classificado no momento da
  resposta. O planejamento não deve desenhar classificação de erro para coisas que já rodam
  depois do 200.

- **D-11 (discrição do Claude): job morto marca estado de falha legível + log de erro, sem canal
  de alerta novo.** Job falhou em todas as tentativas e a Clicksign já foi embora com 200: o
  evento fica com estado de falha **que o alerta da REDE-03 (Fase 130) consegue ler**. A 129 não
  constrói o alerta — é escopo da 130 — mas garante que o sinal exista. Recusado "só marca e a
  reconciliação resolve": contrato assinado que não processou é exatamente o silêncio que a D4 da
  milestone existe para impedir; esperar a próxima passada da reconciliação deixa a empresa presa.

### PDF assinado (CLICK-11 / D6)

Contexto medido que domina esta seção: os links `files.original` / `files.signed` / `files.ziped`
são URLs S3 pré-assinadas com **`X-Amz-Expires=300`** — valem **5 minutos**
(`CLICKSIGN-SANDBOX-EMPIRICO.md` §7).

- **D-12 (discrição do Claude): todo retry de download reconsulta o envelope para obter link
  fresco.** Nunca reusar o link que veio no payload. Retry com link de mais de 5 minutos falha
  100% das vezes — seria retry decorativo. Custa 1 chamada a mais por tentativa; é o preço de o
  retry existir de verdade.

- **D-13 (discrição do Claude): disco privado, servido por rota autenticada.** `storage/app`, não
  `public`. É evidência jurídica e não pode ficar acessível por URL adivinhável.
  As colunas `contrato_assinaturas.pdf_path` e `pdf_assinado_path` **já existem** (migration
  `2026_08_10_120000`, Fase 126) e **nada ainda escreve nelas** — esta fase é a primeira a usar
  `pdf_assinado_path`.

- **D-14 (discrição do Claude): falha permanente de download NÃO prende a liberação da empresa.**
  O cliente assinou; a liberação acontece. O contrato fica com estado próprio de "assinado, PDF
  pendente" e o Administrativo é avisado pela mesma via da REDE-03. Amarrar a liberação ao
  download transformaria uma falha de rede em empresa presa.

### Claude's Discretion

O usuário decidiu explicitamente as escolhas estruturais (D-01 a D-07, D-10) e delegou as de
detalhe. Estão marcadas acima como "discrição do Claude": **D-08, D-09, D-11, D-12, D-13, D-14**.
Cada uma traz o motivo registrado — o planejamento pode divergir, mas precisa dizer por quê.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Verdade empírica sobre a Clicksign (precedência sobre a pesquisa)
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — medições reais contra o sandbox. **Tem
  precedência sobre a pesquisa onde houver divergência** (dois pontos da doc oficial estavam
  errados). §7 documenta o `X-Amz-Expires=300` dos links de PDF; §10 e §11 as sessões de gate
  das Fases 126 e 127.
- `.planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-GATE.md` — 3 envelopes reais
  criados no sandbox pelo caminho do webhook; catálogo de serviços medido em MariaDB real.

### Decisões da milestone (não reperguntar, não contradizer)
- `.planning/REQUIREMENTS-v22.md` §"Decisões travadas (LOCKED)" — D5 (recusado/expirado são
  estados próprios), D6 (PDF baixado e guardado localmente), D7 (liberação só por estado
  reconsultado), D1 (geração de contrato é sempre manual nesta milestone).
- `.planning/REQUIREMENTS-v22.md` §"DECISÕES EM ABERTO" — A1 e A3 eram as abertas desta fase;
  **ambas resolvidas neste CONTEXT** (D-07/D-08/D-09 e D-10/D-11).
- `.planning/REQUIREMENTS-v22.md` §"Gate empírico de sandbox" — gates **#6 e #7 seguem abertos**
  (recusa e expiração emitem evento distinguível?) e **#11 segue aberto** (política de retry e
  garantia de ordem). O planejamento deve tratar #11 como pior caso: entrega fora de ordem,
  at-least-once, sem garantia.

### Fases anteriores desta cadeia
- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-CONTEXT.md` — D-12: o client
  cancela o envelope que criou ao falhar no meio (resolveu a A2).
- `.planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-GATE.md` — molde
  de documento de gate; prazo definido na criação sobrevive à ativação.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Http/Controllers/Api/HubspotWebhookController.php` — **o molde direto desta fase.** Valida
  HMAC com `hash_equals` timing-safe (linha ~87) e, quando a assinatura falha, grava
  `HubspotEvento(signature_valid=false)` com motivo + raw truncado (`gravarInvalido()`, ~linha
  1015). Isso é exatamente o SC2 da 129: recusar mas gravar bruto assim mesmo.
- `app/Services/Operacional/EmpresaOperacionalRouter.php` — já expõe
  `rotearServico(Company, string $nomeServico)` (porta por serviço, usada hoje pelo webhook
  HubSpot) e `rotearCadastro(Company, iterable $nomes)` com o guard `guardPorEmpresa`. A D-01
  encaixa em `rotearServico`; a D-02 exige repensar onde o guard de ficha única vive.
- `app/Console/Commands/ClicksignSondarModelo.php` — padrão de comando-sonda do projeto; molde
  para o comando de verificação de assinatura da D-09.
- `app/Services/Clicksign/ClicksignClient.php` — client já existente (Fase 126). Token PURO no
  `Authorization`, **nunca** `Http::withToken()` (gate #2, medido).
- `app/Exceptions/ClicksignException.php` — tipo de exceção já existente, útil para a
  classificação de erro da D-10.

### Established Patterns
- Tabela de eventos brutos: `hubspot_eventos` (migration `2026_06_12_300002`) é o precedente de
  schema para gravar evento de provedor com flag de assinatura válida. **Não existe tabela de
  eventos Clicksign ainda** — esta fase cria.
- `payload_hash` **não existe em lugar nenhum do código** — o mecanismo de dedup da CLICK-04 é
  todo novo.
- `contrato_assinaturas.pdf_path` / `pdf_assinado_path` existem desde a Fase 126 e estão vazias:
  nenhum código escreve nelas hoje.
- Não há nenhuma rota `clicksign` em `routes/` — a superfície HTTP desta integração é toda nova.

### Integration Points
- Entrada: rota nova de webhook (pública, sem CSRF — mesmo tratamento das rotas de webhook já
  existentes) → validação HMAC → gravação bruta → enfileirar.
- Saída: `EmpresaOperacionalRouter::rotearServico()` para criar/enriquecer a ficha, e a tabela
  nova de liberações (D-05) como contrato de leitura para as Fases 130 e 131.
- A Fase 130 espera `EmpresaOperacionalRouter::liberarEmpresa()` (SC3 da 130) — **esse método não
  existe ainda**. O planejamento precisa decidir se a 129 já o cria como ponto único (o fluxo
  automático e o manual devem compartilhá-lo) ou se deixa para a 130.

</code_context>

<specifics>
## Specific Ideas

- O usuário pediu explicitamente linguagem simples quando o assunto foi infraestrutura de túnel —
  vale para qualquer material de UI ou instrução operacional que esta fase gerar. Alinha com a
  regra registrada de evitar jargão sem explicação.
- Ambiente da máquina: PHP não está no PATH (`C:\xampp\php\php.exe`); MariaDB local foi reparado
  em 2026-08-12 restaurando as tabelas de sistema do backup de fábrica do XAMPP, e uma migration
  (`2026_07_07_100005_add_dedup_key_to_nps_surveys`) está marcada como aplicada **sem ter rodado**
  — MariaDB 10.4.32 rejeita coluna gerada com `date_format()` (erro 1901). Testes rodam em SQLite
  e não são afetados.

</specifics>

<deferred>
## Deferred Ideas

- **Alerta de contrato preso e job de reconciliação** — Fase 130 (REDE-02/03/04). Esta fase só
  produz o estado que eles vão ler.
- **Liberação manual auditada** — Fase 130 (SC3). A tabela de liberações da D-05 já nasce com o
  campo "por qual via" para acomodá-la sem migration nova.
- **Tela do Administrativo mostrando contratos e liberações** — Fase 131.
- **Ligar o bloqueio do roteamento operacional** — Fase 133. Até lá o fluxo segue em modo
  observação, como a Fase 128 entregou.

</deferred>

---

*Phase: 129-Webhook Clicksign (v22.0)*
*Context gathered: 2026-08-12*
