# Resumo da Pesquisa do Projeto

**Projeto:** ECF Admin — Setor Dev
**Milestone:** v22.0 Administrativo + Clicksign
**Domínio:** Integração de assinatura eletrônica (Clicksign API v3 / Envelope) como gate bloqueante entre o fechamento comercial e a liberação operacional, num Laravel 12 + Inertia + React já em produção
**Pesquisado:** 2026-08-07
**Confiança geral:** MÉDIA — arquitetura e código do projeto verificados diretamente (HIGH); comportamento externo da Clicksign triangulado via documentação oficial, mas com pontos ambíguos que exigem validação empírica em sandbox antes de codificar

## Sumário Executivo

A v22.0 insere um ponto de espera (contrato assinado) num funil que hoje é 100% síncrono: duas rotas de entrada (`HubspotWebhookController::criarEmpresa()` e `ComercialController::store()`) criam `Company` e já roteiam para o operacional na mesma transação. As quatro pesquisas convergem num veredito comum: a integração técnica com a Clicksign é simples e não exige nenhuma dependência Composer nova (client HTTP fino sobre `Http::`, igual ao `HubspotApiClient` já existente), mas o risco real da milestone não está na Clicksign — está no **sequenciamento do rollout** e na **máquina de estados** que o plano canônico desenhou de forma incompleta. Se o roteamento automático for desligado antes do webhook + rede de segurança estarem prontos e testados, toda empresa nova fica presa sem alarme — esse é o risco central já identificado no PROJECT.md, e as quatro pesquisas o confirmam de ângulos diferentes (arquitetura, pitfalls, features).

A boa notícia é que o projeto já resolveu, em produção, quase toda a infraestrutura de que esta milestone precisa: idempotência de webhook em duas camadas (`HubspotWebhookController`), kill switch sem deploy (`Configuracao`), override manual auditado (`BonusInvalidacao`/`BonusAuditoriaController`) e DomPDF em pt-BR com acentuação e CSS inline (`RelatorioMensalPdfService`). Isso reduz a v22.0, na prática, a um exercício de **reuso disciplinado** desses padrões aplicados a um domínio novo, não a construção do zero. A recomendação central é inverter a ordem sugerida pelo plano: construir o webhook receiver e a rede de segurança (kill switch, alerta de contrato preso, liberação manual) **antes** de desligar o roteamento automático, e manter o corte atrás de uma flag `Configuracao` desligada por padrão até tudo estar validado em produção em modo observação.

O principal risco não resolvido por esta pesquisa é uma **contradição direta entre STACK.md e PITFALLS.md sobre o algoritmo de validação HMAC do webhook** — ver seção dedicada abaixo. Isso é tratado como decisão bloqueante da fase do webhook, não como escolha de conveniência: implementar o algoritmo errado faz 100% dos webhooks reais falharem silenciosamente, e só um teste com webhook real de sandbox decide qual dos dois está certo.

## CONTRADIÇÃO EXPLÍCITA — Algoritmo de validação do webhook Clicksign

Os dois pesquisadores que tocaram nesse ponto (STACK.md e PITFALLS.md) leram a mesma documentação oficial (`developers.clicksign.com/docs/seguranca-de-webhooks`) e chegaram a **fórmulas matematicamente diferentes**, ambas com confiança MÉDIA:

| Fonte | Fórmula proposta | Leitura da doc |
|---|---|---|
| STACK.md | `hash('sha256', $rawBody . $secret)` — concatenação de string simples seguida de SHA256 | A doc diz textualmente "a Clicksign calcula o Hash SHA256 da soma do Body da requisição com o Secret" — lida literalmente como concatenação, não HMAC clássico |
| PITFALLS.md | `hex(hmac_sha256($secret, $rawBody))` — HMAC criptográfico clássico, com padding interno/externo via `hash_hmac()` | Trata o nome do header/mecanismo ("HMAC SHA256 Secret") como indicação de que é HMAC de verdade, apesar da mesma ambiguidade textual da doc |

**Por que isso importa de verdade:** as duas fórmulas produzem hashes diferentes para o mesmo `$rawBody` + `$secret`. Implementar a errada não gera erro de sintaxe nem exceção — gera um `hash_equals()` que **nunca bate**, silenciosamente, para 100% dos webhooks reais. Ninguém percebe até checar logs de 401 ou perceber que nenhuma empresa nunca é liberada automaticamente.

**Decisão obrigatória, não escolha de conveniência:**
1. Implementar `hash('sha256', $rawBody . $secret)` como primeira tentativa (é a leitura mais literal do texto da doc).
2. Antes de confiar nessa validação em qualquer ambiente que não seja teste isolado, **disparar um webhook real do sandbox Clicksign** e comparar o `Content-Hmac` recebido contra os dois cálculos (concatenação simples E `hash_hmac()` clássico), logando os dois valores calculados (nunca o secret) até um bater.
3. Escrever o teste automatizado da Fase 9 com um fixture de HMAC **calculado fora do código de produção** (não gerado pelo mesmo código que valida) — um teste que só se autoconfirma dá falso verde nos dois cenários.
4. Este teste empírico é **gate bloqueante da Fase do webhook (Fase 9 do plano / a partir da Fase 124 real do projeto)** — não deployar a validação HMAC em produção sem esse teste ter sido feito contra um evento real do sandbox.

Nenhuma outra divergência de fato entre os 4 arquivos foi encontrada além desta — os demais pontos de baixa confiança (URL base de produção, formato do prefixo `Bearer`, formato do `content_base64`, evento de expiração/recusa) são lacunas de conhecimento unânimes entre os pesquisadores, não conclusões conflitantes, e estão listados na seção "Gate empírico" abaixo.

## Principais Achados

### Stack recomendada

Nenhuma dependência Composer nova. O client Clicksign segue literalmente o padrão já em produção no `HubspotApiClient.php`: `Http::` facade, sem SDK (o pacote oficial `clicksign/clicksign-php` está descontinuado desde 2020 e cobre só a API Classic pré-Envelope; o pacote comunitário `mateusgalasso/clicksign` fala com endpoints `/api/v1/*` legados, não com o conceito de Envelope da v3 exigido pela milestone).

**Tecnologias-núcleo:**
- Laravel HTTP Client (`Http::`) — client HTTP para a Clicksign v3, mesmo padrão do `HubspotApiClient`. Atenção: **não usar `Http::withToken()`** — esse helper injeta `Bearer ` automaticamente, e a doc oficial mostra o exemplo canônico sem esse prefixo (ambiguidade real entre páginas da própria doc; usar `Http::withHeaders(['Authorization' => $token])` e validar contra o sandbox).
- `hash()`/`hash_equals()` nativos do PHP — validação do webhook (algoritmo exato: ver contradição acima).
- `base64_encode()` nativo — empacotar o PDF do DomPDF (`$pdf->output()`) para o campo `content_base64` da Clicksign; não confirmado com certeza se o prefixo `data:application/pdf;base64,` é obrigatório ou se a string base64 pura basta — testar os dois contra o sandbox.

**Rate limits confirmados:** produção 50 req/10s, sandbox 20 req/10s — volume da ECF fica muito abaixo, mas o `ClicksignClient` precisa tratar 429 explicitamente (a criação de um envelope é uma sequência de N chamadas, e replay de webhook pode acumular).

### Funcionalidades esperadas

O domínio (assinatura eletrônica B2B) é maduro e padronizado — Clicksign converge com DocuSign/HelloSign no modelo Envelope → Documento → Signatário → Requisito. Table stakes já bem cobertos pelo plano: reenvio manual de notificação, papéis `cliente`/`ecf`/`testemunha`, cancelamento invalida assinaturas parciais.

**Table stakes que o plano NÃO cobre e precisam entrar no schema/roadmap:**
- Estado explícito para **recusa de signatário**, distinto de cancelamento administrativo (remediação diferente: recusa exige o Comercial contatar o cliente; cancelamento é decisão interna).
- Estado ou motivo estruturado para **expiração de prazo**, distinto de erro técnico (expiração é evento de negócio esperado, não falha de sistema — misturar os dois no status `erro` esvazia o valor da tela de auditoria de contratos presos).
- **Configurar `remind_interval` nativo** da Clicksign (até 3 lembretes automáticos, grátis exceto WhatsApp) em vez de construir um scheduler de lembrete próprio — construir do zero seria duplicar algo que a plataforma já resolve (ANTI-FEATURE se implementado à mão).
- **Correção de e-mail de signatário após envio** (typo) via endpoint de atualização — distinto de "trocar a pessoa", que exige cancelar e regerar; a UI precisa diferenciar os dois caminhos.
- **Download e persistência local do PDF assinado** — o plano guarda só `clicksign_download_url`; depender de URL temporária de terceiro é risco de disponibilidade de longo prazo para um documento com valor jurídico.

**Anti-features confirmadas:** polling como mecanismo primário (webhook é o padrão do domínio), reemissão automática de contrato ao expirar sem revisão humana, lembrete próprio duplicando o nativo, processar o webhook de forma síncrona e pesada na mesma requisição HTTP (deve ir para Job da fila).

### Abordagem de arquitetura

O sistema tem hoje dois caminhos de entrada de empresa (HubSpot webhook e cadastro manual Comercial) que duplicam a mecânica de roteamento operacional mas compartilham a mesma regra de negócio (`ComercialController::servicoDisparaImplementacao()`, já estático e reusado). A extração proposta pelo plano (`PendenciasComerciaisService` + `EmpresaOperacionalRouter`) é correta em direção, mas tem lacunas de contrato de dados que a pesquisa de arquitetura mapeou linha a linha.

**Componentes principais:**
1. `PendenciasComerciaisService::calcular(Company $company)` — centraliza a regra de pendência comercial hoje espalhada em `ComercialController::calcularPendenciasComerciais()`.
2. `EmpresaOperacionalRouter::liberarEmpresa(Company $company)` — único ponto de entrada para "mandar empresa pro operacional", chamado por 3 disparadores (webhook Clicksign, botão manual do admin, kill switch desligado chamando direto).
3. `ClicksignClient` — client HTTP puro, sem lógica de negócio, espelhando `HubspotApiClient`.
4. `ContratoClicksignService` — orquestra client + PDF + persistência de estado.
5. `ClicksignWebhookController` — espelha estruturalmente `HubspotWebhookController` (raw body, HMAC, idempotência em duas camadas), mas com fórmula de assinatura DIFERENTE (ver contradição).

### Pitfalls críticos

1. **Fases 8 e 9 do plano não podem ser deployadas separadamente sem flag** — se o bloqueio do roteamento automático for ligado antes do webhook existir, toda empresa nova fica presa permanentemente sem chance de liberação.
2. **Eventos do webhook podem chegar fora de ordem** (a Clicksign não documenta garantia de ordem nem de retry) — a liberação operacional deve sempre recomputar o estado agregado (reconsultar signatários/envelope), nunca confiar em "qual evento chegou por último". Status nunca deve regredir.
3. **Webhook que nunca chega é o risco central da milestone** — exige reconciliação ativa (comando agendado consultando `consultarEnvelope`) como rede de segurança, não só reação passiva ao webhook.
4. **Algoritmo HMAC copiado do padrão HubSpot quebra tudo** — HubSpot usa `base64(hmac_sha256(secret, method+uri+body+timestamp))` com replay window por timestamp; Clicksign não tem timestamp nativo para replay window, e o algoritmo em si é a contradição documentada acima. Reusar só a disciplina (raw body, `hash_equals`, nunca logar secret), não a fórmula.
5. **Validar dados mínimos ANTES de gerar PDF e criar envelope**, não deixar a Clicksign rejeitar depois — envelope órfão com signatário de e-mail vazio pode ficar "enviado" para sempre, indistinguível de um webhook que não chegou.

## O que o plano canônico erra ou omite

Consolidação das correções encontradas pelas quatro pesquisas em relação a `plano-administrativo-clicksign.md`:

1. **Máquina de estados com 1 redundância e 2 lacunas.** `enviado` e `aguardando_assinaturas` mapeiam ambos para o mesmo evento Clicksign (`running`) — não existe estado intermediário real entre "ativado" e "aguardando assinatura" na plataforma; recomenda-se usar `enviado` só como confirmação síncrona da própria chamada à API, e `aguardando_assinaturas` como o estado atualizado pelo primeiro webhook. Faltam dois estados: **recusa de signatário** (hoje cairia em `cancelado`, perdendo a distinção entre decisão do cliente e decisão do admin) e **expiração de prazo** (hoje cairia em `erro`, misturando falha técnica com evento de negócio esperado).

2. **Fases 8 e 9 precisam ser atômicas no deploy, ou o bloqueio nasce atrás de flag desligada.** O plano descreve como uma sequência natural de PRs, mas operacionalmente são um único evento — se o bloqueio (Fase 8) for ao ar sem o webhook (Fase 9) pronto, toda empresa criada no intervalo fica presa sem via de liberação.

3. **`PendenciasComerciaisService` hoje só calcula pendência para origem HubSpot.** A UI de listagem (`ComercialController::listagem()`) já restringe por `is_origem_hubspot`, e o plano herda essa restrição sem diferenciar. Para o GATE de liberação (decidir se manda pro Clicksign), a regra precisa avaliar TODA empresa nova, HubSpot ou manual — senão empresa cadastrada à mão pula o gate inteiro. Decisão de negócio pendente, não questão técnica: pendência-para-GATE ≠ pendência-para-UI, mesmo cálculo, usos diferentes.

4. **Risco de regressão ao unificar `rotearImplementacao($company, $nomeServico)` em `liberarEmpresa($company)`.** Hoje o método é chamado em loop, uma vez por serviço, e `ComercialController::criarImplementacaoPolo()` passa `$handoff` (dados do wizard, incluindo `gmail_colaborador`) que o caminho HubSpot nunca teve. Se a nova assinatura não acomodar contexto extra opcional, o caminho Comercial perde silenciosamente o preenchimento de `gmail_colaborador` — vale marcar como teste obrigatório explícito, não confiar que "Comercial segue o mesmo fluxo do HubSpot" (item 8 da Fase 13) cobre isso.

5. **Schema de `contrato_assinaturas` não tem `released_by`/`release_reason`.** O plano prevê `released_to_operational_at` mas não os campos exigidos pelo requisito de rede de segurança do PROJECT.md ("liberação manual com registro de quem liberou e por quê") — adicionar na migration da Fase 2.

6. **O plano guarda só `clicksign_download_url`, não persiste o PDF assinado localmente.** Depender de URL temporária de terceiro como fonte permanente de um documento com valor jurídico é risco de disponibilidade de longo prazo — baixar e persistir o PDF (disco/storage já configurado do projeto) quando o webhook de conclusão chegar.

## O que já existe no projeto e deve ser REUSADO (reduz escopo de verdade)

| Padrão existente | Onde | Reuso na v22.0 |
|---|---|---|
| `Configuracao` (chave/valor) | `app/Models/Configuracao.php` | Kill switch sem deploy (`administrativo_bloqueio_ativo`) — já em produção como feature flag |
| `BonusInvalidacao` / `BonusAuditoriaController::toggle()` | `app/Http/Controllers/BonusAuditoriaController.php` | Molde de override manual auditado — copiar o par `invalidated_by`/`motivo` para `released_by`/`release_reason`, mas tornando o motivo obrigatório (é exceção ao fluxo de segurança, não correção de dado) |
| Guard `MlbEmpresa::where('company_id')->exists()` | `HubspotWebhookController::rotearImplementacao()`, linha ~938 | Backstop final de idempotência — já testado em produção, deve continuar sendo o primeiro passo de `EmpresaOperacionalRouter::liberarEmpresa()` |
| `RelatorioMensalPdfService` + `relatorio-bonificacao.blade.php` | `app/Services/`, `resources/views/pdf/` | Precedente resolvido de DomPDF com `DejaVu Sans`, `<meta charset="UTF-8">`, CSS inline, logo base64 — reusar literalmente para o PDF do contrato |
| `remind_interval` nativo da Clicksign | Payload de criação de envelope | Substitui qualquer scheduler próprio de lembrete — configurar corretamente, não construir do zero |
| Duas camadas de idempotência do webhook HubSpot | `HubspotWebhookController::processar()` | Camada 1 (ingestão, por `payload_hash`/`provider_event_id`) + Camada 2 (efeito, guard `MlbEmpresa::exists()`) — o plano já desenha os dois campos certos na tabela de eventos, mas a pesquisa de arquitetura confirma que os DOIS guards são necessários, não só o de `payload_hash` |
| `Log::channel('ecf-webhooks')` | Padrão de log do projeto | Canal dedicado equivalente (`ecf-clicksign`) para isolar volume/erro por integração |

## Lista consolidada — validar empiricamente em sandbox ANTES de codificar

Esta lista junta todos os "não confirmado" dos 4 arquivos. Cada item trava a fase indicada — não presumir, testar contra o sandbox real.

| # | O que validar | Trava qual fase | Por que importa |
|---|---|---|---|
| 1 | **Algoritmo de HMAC** — concatenação simples (`hash('sha256', body+secret)`) vs. HMAC clássico (`hash_hmac('sha256', body, secret)`) | Webhook Clicksign (Fase 9 / ~124+) | CONTRADIÇÃO EXPLÍCITA entre STACK.md e PITFALLS.md — ver seção dedicada acima. Gate bloqueante. |
| 2 | Formato do header `Authorization` — token puro vs. `Bearer <token>` | Client Clicksign (Fase 5) | Doc oficial tem as duas variantes em páginas diferentes; `Http::withToken()` adiciona `Bearer` automaticamente, pode causar 401 silencioso |
| 3 | URL base de produção — `https://app.clicksign.com/api/v3` | Configuração (Fase 4) / Cutover | Confiança MÉDIA, sem citação literal encontrada em página oficial fetchada diretamente |
| 4 | Formato de `content_base64` — precisa do prefixo `data:application/pdf;base64,` ou string base64 pura basta | PDF/Envio de documento (Fase 5/7) | Doc mostra os dois formatos em trechos diferentes |
| 5 | Se a Clicksign emite evento/estado distinguível para **prazo expirado**, ou se só é detectável por reconciliação ativa | Webhook (Fase 9) + Reconciliação | Determina se "expirado" é um branch do webhook ou exclusivamente do job de reconciliação |
| 6 | Se a Clicksign emite evento distinguível para **recusa de signatário**, e como aparece no payload | Webhook (Fase 9) | Sem isso não dá para diferenciar recusa de cancelamento administrativo no handler |
| 7 | Endpoint exato para correção de e-mail de signatário via API v3 (só confirmado via central de ajuda/UI humana) | Service Administrativo (Fase 6) | Feature table stakes; sem confirmação de endpoint, a UI de correção não pode ser construída |
| 8 | Formato/endpoint do certificado de autenticação do signatário (evidência de auditoria) | Schema (Fase 2) / Auditoria | Sustenta a validade jurídica em caso de disputa — formato exato não verificado nesta pesquisa |
| 9 | Limite de tamanho de arquivo para upload de documento | Client Clicksign (Fase 5) | Não confirmado na doc pública consultada |
| 10 | Retry policy e garantia de ordem de entrega de webhook da Clicksign | Webhook (Fase 9) | Não documentado publicamente — tratar como pior caso (sem garantia) por padrão de segurança |

## Ordem de construção recomendada

Reconciliando a proposta do ARCHITECTURE.md (ordem de dependência técnica) com os riscos de sequenciamento do PITFALLS.md (Pitfall 1, 4, 6, 11):

1. **Extração pura dos services, sem mudar comportamento.** `PendenciasComerciaisService` e `EmpresaOperacionalRouter` nascem como wrappers que os dois controllers passam a chamar, mas o resultado observável continua idêntico ao atual (liberação segue acontecendo na hora). Isola o risco de regressão mecânica do risco de mudança de comportamento; pode ir a produção com zero risco de bloquear operação porque nada muda de fato. Já reduz a duplicação hoje existente.

2. **Kill switch instalado antes de qualquer bloqueio existir.** `Configuracao::get/set` com chave `administrativo_bloqueio_ativo` (default `false`). Todo código que decide bloquear já nasce condicionado a essa chave, desligada.

3. **Estrutura de dados + client Clicksign + PDF + webhook, rodando em modo observação.** Tabelas, models, `ClicksignClient`, `ContratoClicksignService`, PDF, e o `ClicksignWebhookController` entram em produção com o corte já presente mas sem efeito real (a chamada a `liberarEmpresa()` continua acontecendo imediatamente após `persistirContratos()` enquanto a flag estiver desligada). Valida o pipeline inteiro (envelope, PDF, webhook, idempotência, o teste empírico do HMAC do item 1 acima) contra dados reais de produção sem risco de parar a operação — o pior caso de bug aqui é um `ContratoAssinatura` com status errado, nunca uma empresa presa fora do operacional.

4. **Rede de segurança: reconciliação ativa + alerta de contrato preso + liberação manual admin.** Construída e testada ainda com o kill switch desligado. O alerta de N dias precisa ter disparado pelo menos uma vez em sandbox; a liberação manual precisa ter sido testada ponta a ponta em produção antes do próximo passo. Isso é, ao mesmo tempo, a proteção operacional do dia a dia e o plano de rollback (reverter só o deploy não desfaz `ContratoAssinatura` em estado intermediário).

5. **Tela administrativa de contratos + badge na listagem Comercial.** Observabilidade não é polimento de UI nesta milestone — é pré-condição de segurança operacional; deve anteceder ou ser simultânea à liberação do bloqueio, nunca posterior.

6. **Liga o bloqueio** (`administrativo_bloqueio_ativo = true`) — só depois dos passos 3-5 terem rodado em produção por tempo suficiente para confirmar: (a) webhook chega de forma confiável, (b) alerta de contrato preso funciona, (c) liberação manual foi testada em produção ao menos uma vez. Dali em diante o corte nos dois controllers deixa de chamar `liberarEmpresa()` direto e passa a depender de `iniciarParaEmpresa()` + webhook/manual.

**Divergência notável em relação ao plano canônico:** o plano trata a rede de segurança como item da Fase 1 sem formalizá-la como GATE de rollout entre as fases seguintes. O roadmap precisa tratar kill switch + reconciliação + alerta + liberação manual como infraestrutura que bloqueia a Fase "liga o bloqueio", não como itens paralelos de prioridade equivalente às demais fases.

## Avaliação de Confiança

| Área | Confiança | Notas |
|------|------------|-------|
| Stack | MÉDIA | Documentação oficial da Clicksign como fonte primária, mas várias páginas de endpoint retornaram conteúdo parcial/404 via fetch automatizado; itens sensíveis (HMAC, auth) triangulados em 3+ fetches mas ainda ambíguos — ver contradição documentada |
| Features | MÉDIA | Estrutura de envelope/estados confirmada na doc oficial; comportamento de expiração/recusa não documentado explicitamente, práticas de auditoria generalizadas de DocuSign/Dropbox Sign (não Clicksign-específicas) |
| Arquitetura | ALTA | Baseada em leitura direta e completa do código do projeto (`HubspotWebhookController.php` 1069 linhas, `ComercialController.php` 982 linhas), não do plano — achados concretos com número de linha |
| Pitfalls | MÉDIA-ALTA | HMAC/idempotência verificados contra código real e doc oficial; retry/ordem de entrega da Clicksign é LOW confidence explícito (não documentado publicamente, tratado como pior caso por padrão de segurança) |

**Confiança geral:** MÉDIA — a arquitetura interna (o que muda no código do projeto) tem confiança alta porque foi verificada linha a linha; o comportamento externo da Clicksign tem confiança média porque a documentação pública tem lacunas e uma ambiguidade real e não resolvida (o HMAC). Nenhum código de produção deve ser escrito para o webhook antes do gate empírico da seção de contradição.

### Gaps a Endereçar

- **Algoritmo de HMAC (contradição explícita)** — gate bloqueante, resolver com webhook real de sandbox antes de codificar a Fase do webhook (ver seção dedicada).
- **10 itens da lista de validação empírica** (tabela acima) — cada um trava uma fase específica; nenhum deve ser assumido a partir da documentação isoladamente.
- **Decisão de negócio pendente:** `PendenciasComerciaisService` como GATE deve avaliar toda empresa nova (HubSpot + manual) ou só HubSpot como hoje na UI — precisa de decisão explícita no discuss-phase da fase de refatoração, não é questão técnica.
- **Decisão de negócio pendente:** se `liberarEmpresa($company)` deve re-derivar serviços ativos no momento da liberação (correto para o caso Clicksign, dias depois) em vez de receber a lista do momento da criação (comportamento atual) — mudança de comportamento, não extração pura, precisa ser marcada como decisão consciente.
- **Rollback do lado Clicksign quando uma etapa intermediária de montagem do envelope falha** (documento criado, signatário falhou) — o plano não cobre isso explicitamente; decidir se o estado `erro` convive com um envelope órfão do lado da Clicksign que precisa ser cancelado manualmente.

## Fontes

### Primárias (confiança ALTA)
- `app/Http/Controllers/Api/HubspotWebhookController.php` (leitura completa, 1069 linhas) — precedente de HMAC, idempotência, guard anti-duplicidade
- `app/Http/Controllers/ComercialController.php` (leitura completa, 982 linhas)
- `app/Models/Configuracao.php`, `app/Http/Controllers/BonusAuditoriaController.php` — precedentes de kill switch e override auditado
- `app/Services/RelatorioMensalPdfService.php` + `resources/views/pdf/relatorio-bonificacao.blade.php` — precedente DomPDF pt-BR
- `plano-administrativo-clicksign.md` (raiz do repo) — plano canônico da milestone
- `.planning/PROJECT.md` — contexto de risco central e requisitos de rede de segurança

### Secundárias (confiança MÉDIA)
- `developers.clicksign.com/docs/primeiros-passos`, `/docs/seguranca-de-webhooks`, `/docs/limite-de-requisicoes`, `/reference/api-upload-documentos`, `/reference/api-criar-envelope`, `/docs/envelope` — documentação oficial via WebFetch, algumas páginas com conteúdo parcial
- `ajuda.clicksign.com` (central de ajuda) — prazo de assinatura, correção de e-mail, edição de signatário, lembretes automáticos
- `packagist.org/packages/clicksign/clicksign-php` e `mateusgalasso/clicksign` — status de pacotes descontinuados/inadequados

### Terciárias (confiança BAIXA, precisam validação)
- Práticas gerais de mercado (DocuSign "Certificate of Completion", Dropbox Sign) sobre auditoria de assinatura — generalização, não específica da Clicksign
- Retry policy e ordem de entrega de webhook da Clicksign — não encontrado em nenhuma fonte, tratado como pior caso

---
*Pesquisa concluída: 2026-08-07*
*Pronto para roadmap: sim, com gate empírico obrigatório do HMAC antes de codificar a fase do webhook*
