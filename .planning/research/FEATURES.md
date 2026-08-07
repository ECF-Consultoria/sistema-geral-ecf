# Feature Landscape: Fluxo de Assinatura Eletrônica de Contrato (Clicksign) — v22.0

**Domínio:** integração de assinatura eletrônica (Clicksign, API v3 / Envelope) acoplada a um funil comercial B2B
**Pesquisado:** 2026-08-07
**Escopo:** apenas as capacidades NOVAS do fluxo de assinatura — não repete o que já existe (webhook HubSpot, cadastro Comercial, contratos internos, roteamento operacional)

## Sumário

Fluxos de assinatura eletrônica de contrato são um domínio maduro e bem padronizado (DocuSign, HelloSign/Dropbox Sign, Clicksign convergem no mesmo modelo mental: **Envelope → Documento(s) → Signatário(s) → Requisito(s) de assinatura**). A Clicksign especificamente expõe 4 estados de envelope (`draft`, `running`, `closed`, `canceled`), prazo de assinatura configurável (padrão 30 dias, até 90), lembretes automáticos nativos (`remind_interval`, até 3 disparos) e webhooks como mecanismo reativo primário — a plataforma já resolve boa parte do que costuma ser reinventado (e mal-reinventado) por quem integra na unha.

A implicação central para a v22.0: **o plano tem 9 status, mas 2 deles não têm evento correspondente na Clicksign e 2 estados reais da Clicksign não têm status correspondente no plano.** Isso não invalida o plano, mas deixa lacunas de tratamento que, se não forem fechadas antes da Fase 9 (webhook), viram silêncio operacional — exatamente o risco central já identificado no PROJECT.md ("empresa presa sem alarme").

## 1. Máquina de estados: plano vs. prática

### Estados canônicos de um fluxo de assinatura (padrão de mercado)

| Estado canônico | Equivalente Clicksign | Significado |
|---|---|---|
| Rascunho | `draft` | Envelope criado, documentos/signatários sendo montados, ninguém foi notificado |
| Enviado / Em andamento | `running` | Envelope ativado; notificações disparadas; assinaturas podem ocorrer a qualquer momento nessa janela |
| Parcialmente assinado | (sub-estado de `running`, não é status próprio na Clicksign) | Alguns signatários concluíram, outros não — Clicksign não nomeia isso separadamente, é inferido pela lista de signatários |
| Recusado | evento de recusa por signatário (estado terminal por parte do signatário, mas o envelope pode continuar `running` com outros signatários, ou ser cancelado manualmente) | Um signatário se recusa a assinar — **caminho de exceção distinto de cancelamento voluntário** |
| Expirado | prazo (`deadline`) vencido sem fechamento — link invalidado, mas não fica claro na documentação pública se a Clicksign muda o `status` do envelope automaticamente ou só invalida o link | Prazo estourou sem todas as assinaturas — **estado terminal distinto de erro técnico e de cancelamento** |
| Concluído | `closed` | Todos os signatários obrigatórios concluíram os requisitos; documento final disponível |
| Cancelado | `canceled` | Transação interrompida manualmente; links de assinatura invalidados |

Fontes: [Clicksign — Envelope](https://developers.clicksign.com/docs/envelope) (estrutura e 4 estados), [Clicksign — Prazo para assinar documentos](https://ajuda.clicksign.com/article/216-prazo-para-assinar-documentos) (prazo padrão 30 dias, extensível a 90, link inválido após vencimento) — confiança MÉDIA (conteúdo obtido via fetch resumido, não a página HTML completa; o comportamento exato de expiração automática **não está documentado publicamente de forma explícita** — precisa ser confirmado em sandbox antes da Fase 9).

### Confronto com os 9 status do plano

| # | Status do plano | Existe evento/estado Clicksign correspondente? | Veredito |
|---|---|---|---|
| 1 | `aguardando_comercial` | Não aplicável — é estado pré-Clicksign, interno | Correto manter (fora do domínio da Clicksign) |
| 2 | `pronto_para_contrato` | Não aplicável — interno | Correto manter |
| 3 | `gerando_contrato` | Mapeia a `draft` (envelope sendo montado) | Correto, mas **transitório demais para persistir com confiança** — se a chamada HTTP falhar no meio (documento criado, signatário não), a empresa fica presa em `gerando_contrato` sem sinal claro do que falhou. Precisa idempotência por etapa, não só no fim |
| 4 | `enviado` | Mapeia à transição `draft` → `running` (ativação) | **Redundante com o próximo estado.** Na Clicksign não existe um estado intermediário entre "ativado" e "aguardando assinatura" — no instante em que o envelope vira `running`, ele já está aguardando assinaturas. Ter dois status para o mesmo evento webhook cria ambiguidade sobre qual dispara em que callback. Recomendação: usar `enviado` só como confirmação síncrona da própria chamada à API (antes de qualquer webhook), e `aguardando_assinaturas` como o estado de fato, atualizado pelo primeiro webhook de progresso — documentar isso explicitamente no REQ, não deixar implícito |
| 5 | `aguardando_assinaturas` | Mapeia a `running` (com signatários pendentes) | Correto — é o estado canônico "parcialmente assinado" |
| 6 | `assinado` | Mapeia a `closed` | Correto |
| 7 | `liberado_operacional` | Não aplicável — interno, pós-Clicksign | Correto manter |
| 8 | `cancelado` | Mapeia a `canceled` — **mas também está absorvendo dois casos que a prática trata separado: cancelamento voluntário (admin decide parar) e recusa de signatário (evento externo, não é decisão do admin)** | **Sobrecarregado.** Recusa de assinatura e cancelamento administrativo têm remediação diferente: recusa exige o Comercial contatar o cliente e entender o motivo (pode ser erro de dados, pode ser desistência real); cancelamento é decisão interna. Tratar os dois como `cancelado` perde essa distinção na tela administrativa e no alerta da rede de segurança |
| 9 | `erro` | Não é um estado Clicksign — é catch-all para falha técnica (HTTP, timeout, payload inválido) **e também, por ausência de estado próprio, provavelmente vai acabar absorvendo expiração de prazo** | **Sobrecarregado.** Expirar o prazo de assinatura não é uma falha técnica — é um evento de negócio esperado (cliente demorou demais). Cair em `erro` junto com "a Clicksign retornou 500" mistura duas categorias com ações de remediação totalmente diferentes: erro técnico pode pedir retry automático; expiração exige decisão humana (estender prazo? gerar novo envelope? contatar cliente?) |

**Conclusão da comparação:** a máquina de 9 status do plano é estruturalmente sólida no lado "interno" (pré e pós-Clicksign), mas no lado "Clicksign" ela tem 1 redundância (`enviado`/`aguardando_assinaturas`) e 2 lacunas reais (`recusado`, `expirado` sem estado próprio). Recomenda-se **não expandir para uma máquina paralela completa** (seria over-engineering para a v22.0), mas sim:
- Tratar `recusado` como status próprio (10º), porque a ação do admin diante de uma recusa é visivelmente diferente de um cancelamento — TABLE STAKES.
- Tratar `expirado` como status próprio (11º) ou, no mínimo, como sub-razão dentro de `erro`/`cancelado` com campo `motivo` estruturado (não string livre) — TABLE STAKES, pois sem isso a tela de auditoria de contratos presos (item da rede de segurança) não consegue diferenciar "trava técnica que precisa de admin" de "cliente não assinou a tempo, precisa de novo envio comercial".

## 2. Papéis de signatário e tratamento de e-mail/pessoa errada

Papéis usuais em contratos B2B com assinatura eletrônica: **contratante (cliente)**, **contratada (empresa/ECF)** e, opcionalmente, **testemunha(s)** — o plano já propõe exatamente `cliente`, `ecf`, `testemunha`, o que está alinhado com a prática (testemunha normalmente é opcional e só entra se o tipo de documento/valor exigir reforço jurídico; para contrato de prestação de serviço simples, a maioria das plataformas não exige testemunha para validade).

**Signatário não recebe o e-mail:** table stakes é o **reenvio manual de notificação** (o plano já prevê `reenviarNotificacao()`). A Clicksign também dispara lembretes automáticos nativos via `remind_interval` configurável no envelope — ver seção 3.

**E-mail errado:** a Clicksign permite corrigir erro de digitação no e-mail do signatário **depois de o envelope já ter sido enviado** (confirmado via central de ajuda). Isso muda a resposta correta a um erro comum: não é preciso cancelar e recriar o envelope inteiro por causa de um typo — dá para corrigir e reenviar a notificação. TABLE STAKES: a tela administrativa precisa expor essa correção (endpoint que atualiza e-mail do signatário existente + dispara novo convite), senão o único caminho vira "cancelar e gerar de novo", desnecessariamente destrutivo e que reinicia o prazo/histórico.

**Pessoa que precisa ser trocada depois do envio** (não é typo, é uma pessoa diferente — ex.: contato saiu da empresa cliente): a documentação da Clicksign é mais restritiva aqui — a interface humana permite editar/remover signatário durante o envio, mas trocar a pessoa depois que ela já foi adicionada ao envelope tem limitações; o caminho seguro e amplamente usado no mercado é **cancelar o envelope e gerar um novo** quando a pessoa (não só o e-mail) muda. O plano já cobre isso implicitamente (`cancelar()` + reemissão), mas não deixa explícito que "trocar e-mail" e "trocar pessoa" são operações de complexidade e risco diferentes. TABLE STAKES: distinguir os dois casos na UI (edição simples vs. cancelar-e-regerar) evita que o admin tente "editar" uma pessoa via um caminho que a API não sustenta.

## 3. Reenvio de notificação e lembretes automáticos

**Veredito: TABLE STAKES, não diferencial.** Isso é esperado por qualquer usuário que já usou qualquer plataforma de assinatura eletrônica (DocuSign, Clicksign, Autentique) — a ausência de lembrete automático é motivo comum de reclamação e de contrato "esquecido" na caixa de entrada do signatário.

A Clicksign já resolve a maior parte disso nativamente:
- **Lembretes automáticos** configuráveis por intervalo de dias (`remind_interval` no payload de criação do envelope) — a plataforma notifica signatários pendentes automaticamente, **até 3 vezes** ao longo do prazo de assinatura, sem custo adicional (exceto lembretes via WhatsApp).
- **Reenvio manual** sempre disponível como ação humana adicional, independente do automático.

Implicação para a v22.0: **não é preciso construir um scheduler próprio de lembretes** — basta configurar `remind_interval` corretamente na criação do envelope e expor o botão de reenvio manual (que o plano já lista em Fase 6/11). Construir lógica de lembrete própria (job agendado que dispara e-mail custom) seria duplicar algo que a própria Clicksign já faz — ANTI-FEATURE se implementado do zero; TABLE STAKES apenas configurar o parâmetro nativo corretamente e não deixá-lo no default sem decisão consciente.

Complexidade: BAIXA (é um campo no payload de criação do envelope + um botão que chama `POST /reenviar`). Dependência: nenhuma além do client Clicksign já planejado (Fase 5).

## 4. Expiração de envelope

**Prática comum:** prazo padrão de 30 dias corridos a partir do envio, configurável entre um mínimo curto e até 90 dias. Ao vencer, o link de assinatura fica inválido — o signatário que ainda não assinou não consegue mais acessar o documento pelo link antigo.

**O que não está claro na documentação pública** (e precisa ser confirmado em sandbox antes de ir a produção, não presumido): se o `status` do envelope muda automaticamente para algo detectável via webhook quando o prazo vence, ou se a única forma de saber é reconciliação ativa (consultar o envelope e comparar a data-limite com "agora"). Essa é uma lacuna real de conhecimento — **marcar como item de verificação da Fase 4/5**, não assumir que existe um evento de expiração disparado sozinho.

**O que fazer quando expira** (prática de mercado, não específica da Clicksign): normalmente **não se reabre o mesmo envelope** — o padrão é gerar um novo envelope (novo prazo, novo link) reaproveitando os mesmos dados/documento. Isso é coerente com o próprio plano, que já modela `erro`/`cancelado` como os únicos estados que permitem regenerar contrato.

Classificação:
- **Detectar expiração** (via webhook, se existir, OU via job de reconciliação periódica que compara `sent_at + prazo` com `now()` para envelopes ainda em `aguardando_assinaturas`) — TABLE STAKES. Sem isso, um contrato que simplesmente não foi assinado a tempo fica silenciosamente em `aguardando_assinaturas` para sempre, que é exatamente o cenário "empresa presa sem alarme" que o PROJECT.md já identificou como risco central da milestone.
- **Reemissão automática ao expirar** (gerar novo envelope sozinho, sem intervenção humana) — ANTI-FEATURE nesta fase. Expiração geralmente significa que algo no processo comercial travou (cliente sumiu, dado errado, desistência) — regenerar contrato automaticamente sem revisão humana pode reenviar um contrato errado repetidas vezes. O correto é alertar o admin/Comercial e deixar a reemissão como ação manual — consistente com a "rede de segurança" do plano (alerta de contrato preso além de N dias).
- **Prazo configurável por contrato** (não hardcoded) — DIFERENCIAL. Não é essencial para o MVP da v22.0 (pode nascer com um prazo fixo institucional, ex. 15 ou 30 dias), mas vale já deixar o campo no schema (`contrato_assinaturas` não tem campo de prazo hoje — só tem `sent_at`, `signed_at`, `canceled_at`) para não exigir migration nova quando isso virar requisito.

Dependência: o job de reconciliação (se a expiração não vier por webhook) precisa do `ClicksignClient::consultarEnvelope()` já previsto na Fase 5 — não é trabalho novo de integração, só de agendamento (`routes/console.php`, padrão já usado no projeto para `SyncAdmanData` etc.).

## 5. Auditoria e compliance

Este é um dos pontos mais consolidados do domínio — toda plataforma séria de assinatura eletrônica (DocuSign, HelloSign/Dropbox Sign, Clicksign) converge para o mesmo pacote mínimo, e é exatamente isso que dá valor legal ao processo (princípio de não-repúdio):

| Evidência | Por que importa | Já coberto pelo plano? |
|---|---|---|
| Documento final assinado (PDF com certificação/carimbo) | É o produto final — sem ele não há contrato válido para exibir/baixar | Parcialmente — `clicksign_download_url` existe na tabela, mas não há campo indicando se o PDF foi baixado e persistido localmente ou só linkado (ver Anti-features, item de dependência externa) |
| Log de eventos brutos do provedor (quem fez o quê, quando) | Rastreabilidade e replay de webhook | Coberto — `contrato_assinatura_eventos` com `payload` completo já está bem desenhado no plano |
| Certificado/evidência de autenticação do signatário (IP, timestamp, método de autenticação) | É o que sustenta a validade jurídica em caso de disputa — a "Certidão de Conclusão" no jargão DocuSign | A Clicksign gera isso nativamente e disponibiliza via API/documento — TABLE STAKES é **persistir referência a esse certificado** (ou o próprio arquivo), não confiar só no link temporário da Clicksign |
| Quem, no ECF Admin, disparou cada ação (gerar contrato, reenviar, cancelar, liberar manual) | Auditoria interna, já é convenção do projeto (`created_by`/`updated_by`, `spatie/laravel-activitylog`) | Coberto — campos `created_by`/`updated_by` já previstos na tabela + activity log já é padrão do projeto |

Fontes (confiança MÉDIA, generalização de mercado, não específica da Clicksign): práticas descritas por DocuSign/Dropbox Sign sobre "Certificate of Completion" — IP, timestamps, método de autenticação, retenção de longo prazo. A Clicksign segue o mesmo padrão de mercado (autenticação por biometria facial, e-mail, etc., conforme estrutura de `requirements` do envelope), mas o formato exato do certificado de assinatura da Clicksign não foi verificado em detalhe nesta pesquisa — TABLE STAKES verificar em sandbox qual endpoint retorna essa evidência antes de fechar o schema de `contrato_assinaturas`/`clicksign_document_id`.

**Ponto de atenção prático:** o plano guarda `clicksign_download_url` mas não menciona baixar e persistir o PDF final localmente (S3/disco). Depender de URL da Clicksign como fonte permanente do documento assinado é um risco de disponibilidade de longo prazo (se a conta mudar, expirar token, ou a Clicksign descontinuar acesso a documentos antigos, o ECF Admin perde o contrato). TABLE STAKES: baixar o PDF assinado quando `closed`/webhook de conclusão chegar, e persistir localmente (ou no disco já configurado do projeto) — não só guardar a URL.

## 6. Cancelamento de envelope parcialmente assinado

Prática de mercado (Clicksign explicitamente, e convergente com DocuSign/HelloSign): cancelar um envelope **invalida os links de assinatura de todos os signatários pendentes** e interrompe a transação — mesmo que alguns signatários já tenham assinado, **o documento não se torna um contrato juridicamente completo**, porque a validade normalmente depende de todos os requisitos obrigatórios serem cumpridos (`closed`). Assinaturas parciais coletadas até o cancelamento continuam registradas no log de eventos (para fins de auditoria — "fulano assinou às X, depois foi cancelado"), mas não geram o documento final utilizável.

Implicação direta para o plano: `EmpresaOperacionalRouter::liberarEmpresa()` só pode ser chamado a partir do webhook de **conclusão total** (`closed`/todos assinaram), nunca de assinatura parcial — o plano já modela isso corretamente na Fase 9 ("Quando todos obrigatorios assinarem ou envelope estiver finalizado"). TABLE STAKES confirmar isso: **nenhum caminho no código deve permitir liberação operacional com assinatura parcial**, mesmo em caso de erro de webhook fora de ordem (ex.: dois signatários assinam quase simultaneamente e os webhooks chegam fora de sequência) — o gate correto é sempre reconsultar o estado agregado do envelope, não confiar cegamente no payload do evento mais recente. É uma aplicação direta do aprendizado já registrado no projeto sobre "conferir consolidação por reconsulta ao banco, nunca por stdout/evento isolado" (ver `.planning/learnings/desempenho-bonificacao.md`), agora aplicado a webhooks em vez de jobs financeiros.

**O que acontece com um envelope cancelado após parcialmente assinado, do lado do plano:** o status vai para `cancelado`, `MlbEmpresa`/implementação operacional nunca são criadas (correto, pois `liberarEmpresa()` nunca foi chamado), e a reemissão de um novo contrato deve criar um **novo** `ContratoAssinatura` (novo envelope), preservando o cancelado como histórico — não reaproveitar o mesmo registro. O plano já modela isso ("Permitir gerar novamente apenas se status for erro, cancelado...").

## 7. Anti-features (o que NÃO fazer)

| Anti-feature | Por que times se arrependem | O plano já evita? |
|---|---|---|
| **Polling como mecanismo principal** de status (em vez de webhook) | Atraso na liberação operacional, custo de API, e mais superfície de bug (rate limit, jobs perdidos) — o próprio domínio já converge para webhook como padrão | Sim — o plano já é explícito: "Nao usar polling como fluxo principal". Reconciliação periódica como *rede de segurança* (não como mecanismo primário) é diferente e é recomendada (ver seção 4) |
| **Gerar contrato antes de validar dados mínimos** (e-mail, nome do contato, CNPJ) | Envelope criado com dado errado na Clicksign não pode ser silenciosamente "corrigido" sem reenviar — gera trabalho duplo, pode até notificar a pessoa errada | Sim — o plano já cobre: "Se faltar e-mail do cliente ou nome do contato, devolver erro claro para o Comercial corrigir". Faltando: validação de **formato** de e-mail e CPF/CNPJ (não só presença) no mesmo guard, senão o erro só aparece depois que a Clicksign rejeitar a chamada |
| **Reenvio automático agressivo** (lembrete diário, por exemplo) além do nativo da plataforma | Fadiga de notificação, cliente ignora ou marca como spam, pode até reduzir taxa de assinatura | Plano não menciona lembrete próprio — correto por omissão; só formalizar que `remind_interval` nativo é suficiente e não deve ser complementado por lógica própria sem necessidade comprovada |
| **Confiar só na URL/link da Clicksign como fonte permanente do documento assinado** | Risco de indisponibilidade de longo prazo, dependência de terceiro para provar um contrato já fechado | Não — plano guarda só `clicksign_download_url` (ver recomendação na seção 5: baixar e persistir o PDF) |
| **Processar webhook de forma síncrona e pesada** (gerar PDF, chamar `EmpresaOperacionalRouter`, mandar e-mail, tudo na mesma requisição HTTP do webhook) | Provedores de assinatura costumam ter timeout curto e retry agressivo em caso de erro/timeout — processamento pesado inline aumenta risco de duplicação (mesmo evento reprocessado por timeout falso) | Plano não especifica explicitamente — recomenda-se registrar o evento raw imediatamente (rápido, já é o padrão do plano) e despachar o processamento pesado (liberação operacional) para uma Job da fila, seguindo o padrão já estabelecido no projeto (`AnalyzeCompanySugadoresJob`, etc.) — TABLE STAKES pela convenção arquitetural já existente do projeto, não por ser exigência do domínio |
| **Um único status para "erro técnico" e "expirado"/"recusado"** | Já discutido na seção 1 — mistura remediação automática (retry) com remediação humana (contatar cliente) | Não — é o que o plano faz hoje (ver recomendação de separar, seção 1) |
| **Deixar o webhook público sem validar HMAC/assinatura** | Qualquer um pode forjar "contrato assinado" e liberar operacional de empresa sem contrato de verdade | Sim, mitigado — plano já prevê validação HMAC na Fase 9 ("Validar HMAC/assinatura conforme documentacao oficial"); só reforçar que isso é bloqueante, não best-effort |

## Classificação consolidada

### Table Stakes (obrigatório para a v22.0 funcionar)

| Capacidade | Complexidade | Dependências |
|---|---|---|
| Estado explícito para recusa de assinatura (`recusado`), distinto de `cancelado` | Baixa (valor de enum + branch no handler do webhook) | Fase 9 (webhook), Fase 2 (schema) — inserir antes de fechar a migration de `contrato_assinaturas.status` |
| Estado explícito ou motivo estruturado para expiração de prazo, distinto de `erro` técnico | Baixa-Média | Requer confirmar em sandbox se a Clicksign expõe evento de expiração ou se depende de reconciliação (job) |
| Reenvio de notificação (manual) | Baixa | `ClicksignClient` (Fase 5) — já previsto no plano |
| Configuração de lembrete automático nativo (`remind_interval`) na criação do envelope | Baixa | Payload de criação de envelope (Fase 5/6) |
| Correção de e-mail de signatário após envio (typo) | Baixa-Média | Endpoint de atualização de signatário na Clicksign — confirmar suporte via API v3 (central de ajuda confirma via UI humana; API precisa validação) |
| Distinção clara na UI entre "corrigir e-mail" (edição simples) e "trocar pessoa" (cancelar + regerar) | Baixa | UI (Fase 11) |
| Download e persistência local do PDF assinado final (não só a URL da Clicksign) | Média | Storage já configurado do projeto; trigger no webhook de conclusão (Fase 9) |
| Gate de liberação operacional só a partir de estado agregado reconsultado (nunca do payload isolado do evento) | Média | Reaplica padrão já em uso no projeto para consolidação financeira — Fase 9 |
| Processamento pesado do webhook via Job da fila (não inline na requisição HTTP) | Baixa | Padrão já usado no projeto (`AnalyzeCompanySugadoresJob`) — Fase 9 |
| Validação de HMAC/assinatura do webhook | Baixa-Média | Documentação oficial Clicksign — já previsto no plano (Fase 9) |
| Validação de formato de e-mail/documento antes de chamar a Clicksign (não só presença) | Baixa | `PendenciasComerciaisService` (Fase 1) — endurecer regra existente |

### Diferenciais (bom ter, pode ficar para fase futura)

| Capacidade | Complexidade | Dependências |
|---|---|---|
| Prazo de assinatura configurável por contrato (não fixo institucional) | Baixa | Campo novo em `contrato_assinaturas` (schema, Fase 2) |
| Job de reconciliação periódica de envelopes "presos" (complementa o webhook, não substitui) | Média | `ClicksignClient::consultarEnvelope()` (Fase 5) + scheduler (`routes/console.php`) |
| Dashboard de taxa de assinatura/tempo médio até assinatura (métrica operacional) | Média | Dados já existentes em `contrato_assinaturas` (`sent_at`/`signed_at`) — só agregação |
| Suporte a testemunha obrigatória configurável por tipo de contrato | Baixa-Média | Já modelado no schema (`papel` = testemunha); só falta regra de quando exigir |

### Anti-features (não fazer)

| Anti-feature | Motivo |
|---|---|
| Polling como mecanismo primário de status | Webhook já é o padrão do domínio; polling puro atrasa liberação e aumenta custo/risco |
| Lembrete automático próprio, duplicando o nativo da Clicksign (`remind_interval`) | Risco de notificação duplicada/excessiva; a plataforma já resolve isso |
| Reemissão automática de contrato ao expirar, sem revisão humana | Expiração geralmente sinaliza problema no dado ou no processo comercial — reemitir sozinho pode repetir o erro |
| Gerar envelope antes de validar dados mínimos (e-mail, nome, formato) | Retrabalho, risco de notificar contato errado, contrato preso em `gerando_contrato`/`erro` sem causa clara |
| Confiar só na URL temporária da Clicksign como fonte definitiva do PDF assinado | Risco de perda de evidência jurídica se o acesso à Clicksign mudar no futuro |
| Um único status genérico (`erro`/`cancelado`) cobrindo casos com remediação humana totalmente diferente (recusa, expiração, falha técnica) | Esvazia o valor da tela de auditoria e do alerta de "contrato preso" — a própria rede de segurança da milestone depende de conseguir diferenciar esses casos |

## Gaps a validar antes de codificar (não assumir)

- Confirmar em sandbox se a Clicksign emite um evento/estado distinguível para **prazo expirado**, ou se isso só é detectável por reconciliação ativa (consulta ao envelope comparando `deadline` com `now()`).
- Confirmar em sandbox se a Clicksign emite um evento distinguível para **recusa de signatário** e como ele aparece no payload do webhook.
- Confirmar o endpoint exato para correção de e-mail de signatário via API v3 (documentado apenas via interface humana na central de ajuda consultada).
- Confirmar o formato/endpoint do certificado de autenticação do signatário (evidência para auditoria) na API v3 — não confirmado em detalhe nesta pesquisa.

## Fontes

- [Clicksign — Envelope (docs)](https://developers.clicksign.com/docs/envelope) — MÉDIA confiança (estrutura e 4 estados do envelope)
- [Clicksign — Prazo para assinar documentos](https://ajuda.clicksign.com/article/216-prazo-para-assinar-documentos) — MÉDIA confiança (prazo padrão 30 dias, extensível a 90, link inválido após vencimento; comportamento exato de expiração automática não documentado explicitamente)
- [Clicksign — Como enviar lembretes de assinatura](https://ajuda.clicksign.com/article/210-lembretes-automaticos) — MÉDIA confiança (lembrete automático configurável, até 3 disparos, gratuito exceto WhatsApp)
- [Clicksign — Tipos de notificações do signatário](https://developers.clicksign.com/docs/tipos-de-notificacoes-do-signatario) — MÉDIA confiança
- [Clicksign — Erro de digitação no cadastro do signatário](https://ajuda.clicksign.com/erro-de-digita%C3%A7%C3%A3o-no-cadastro-do-signat%C3%A1rio-aprenda-a-editar) — MÉDIA confiança (correção de e-mail pós-envio suportada)
- [Clicksign — Como editar um signatário durante e após o envio](https://ajuda.clicksign.com/alterar-signatario) — MÉDIA confiança
- [Clicksign — Evento Close](https://developers.clicksign.com/docs/evento-close) — MÉDIA confiança (evento disparado ao finalizar documento manualmente)
- [Clicksign — Referência de Eventos](https://developers.clicksign.com/reference/api-eventos) — BAIXA confiança (taxonomia completa de eventos não obtida nesta pesquisa; precisa consulta direta em `https://developers.clicksign.com/llms.txt` ou sandbox)
- Práticas gerais de mercado (DocuSign "Certificate of Completion", Dropbox Sign/HelloSign) sobre auditoria e evidência de autenticação — BAIXA-MÉDIA confiança, generalização via busca, não específica da Clicksign — [DocuSign — What is an Audit Trail](https://www.docusign.com/blog/what-is-an-audit-trail)
- `plano-administrativo-clicksign.md` (raiz do repo) — plano canônico da milestone, usado como baseline de comparação

**Nota:** este arquivo substitui a versão anterior de `.planning/research/FEATURES.md`, que documentava o módulo de Fechamento/Billing da v2.0 (já entregue). O conteúdo anterior está preservado no histórico do git.
