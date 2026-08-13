# Fase 129 — Gate A1 (plano 129-02)

**Preparado em:** 2026-08-13 (roteiro + verificação de ambiente).
**Medido em:** 2026-08-13, 10:27 BRT — túnel cloudflared, servidor local, webhooks reais do
sandbox Clicksign.
**Status geral:** ✅ **GATE A1 FECHADO.** A fórmula `hmac_body_chave_secret` bateu em **5 de 5**
eventos reais distintos (ids 2 a 6 em `contrato_assinatura_eventos`); as outras 3 candidatas
falharam nos 5. Gates #6 (`deadline`) e #7 (`refusal`) seguem **NÃO MEDIDOS** — nenhuma recusa
nem expiração foi exercitada nesta rodada.

> Molde: `.planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-GATE.md`.
> Regra de anonimização: nenhum token/secret/header de autorização entra neste documento
> (T-129-10/T-129-11). Antes de colar qualquer resposta de API, trocar e-mail, nome, IP e chaves
> por identificadores de teste — faixa RFC 5737 (`203.0.113.0/24`) para IP, UUIDs
> `00000000-0000-4000-8000-00000000000N` para identificadores (mesma disciplina do achado WR-07 da
> Fase 125, registrada no rodapé do `CLICKSIGN-SANDBOX-EMPIRICO.md`).

---

## Por que este gate é bloqueante

Implementar a fórmula errada do `Content-Hmac` faz **100% dos webhooks reais falharem em
silêncio** — e webhook recusado significa contrato assinado que nunca libera a empresa (D-08). Não
há plano B: se a varredura ampla das 4 candidatas não bater em nenhuma, a **fase PARA** e abre
investigação dedicada. Ninguém deve inventar o resultado desta medição.

---

## Ambiente confirmado (feito pelo executor antes de pedir qualquer coisa ao usuário)

| Item | Status |
|---|---|
| `php artisan migrate` | ✅ `contrato_assinatura_eventos` aplicada — `migrate:status` mostra `Ran` (batch 27) |
| `CLICKSIGN_WEBHOOK_SECRET` no `.env` local | ⚠️ **já existe um valor**, mas é resíduo de sessão anterior — **não confiar nele**. O cadastro do webhook no painel (passo 2 abaixo) gera um segredo NOVO, que precisa **substituir** o valor atual antes de qualquer teste real |
| `CLICKSIGN_ENV` / `CLICKSIGN_BASE_URL` | ✅ `sandbox` / `https://sandbox.clicksign.com/api/v3` — confirmado, nenhuma chamada de produção envolvida |
| `php artisan serve` | Testado manualmente nesta sessão — sobe limpo em `http://127.0.0.1:8000`. **Não fica de pé sozinho**: o usuário precisa rodar o comando no passo 0 abaixo, num terminal que ele mantém aberto durante toda a sessão de medição |
| ngrok / cloudflared | ❌ **Nenhum dos dois está instalado nesta máquina** (`where ngrok` / `where cloudflared` não encontraram nada). O usuário precisa instalar um antes do passo 1 |
| Rota-sonda `POST /api/webhooks/clicksign-sonda` | ✅ existe, sempre responde 200, grava tudo em `contrato_assinatura_eventos` |
| Comando `clicksign:verificar-assinatura` | ✅ existe, lê evento já gravado e diz qual candidata bate |

---

## Roteiro (linguagem simples, passo a passo, comandos exatos)

### Passo 0 — Subir o servidor local

Num terminal que você deixa aberto durante toda a sessão:

```
C:\xampp\php\php.exe artisan serve --port=8000
```

Ele vai mostrar `Server running on [http://127.0.0.1:8000]`. Deixe essa janela aberta — se você
fechar, o túnel fica apontando para um servidor que não existe mais.

### Passo 1 — Abrir a porta da máquina para a internet (o túnel)

Num SEGUNDO terminal (mantenha os dois abertos ao mesmo tempo):

- Se você tiver **ngrok** instalado:
  ```
  ngrok http 8000
  ```
- Se você tiver **cloudflared** instalado:
  ```
  cloudflared tunnel --url http://localhost:8000
  ```
- Se você não tiver nenhum dos dois: baixe o ngrok em `https://ngrok.com/download` (não precisa
  criar conta paga, o plano grátis serve) ou o cloudflared em
  `https://github.com/cloudflare/cloudflared/releases`.

Qualquer um dos dois vai imprimir um endereço público temporário, parecido com
`https://xxxx.ngrok-free.app` ou `https://xxxx.trycloudflare.com`. Esse endereço só existe
enquanto o programa do túnel estiver aberto — se fechar a janela, o endereço morre.

### Passo 2 — Avisar a Clicksign para onde mandar o aviso (cadastrar o webhook)

1. Entrar no painel do **sandbox** da Clicksign (confirme que é sandbox, não produção).
2. Ir em Configurações → Webhooks (ou equivalente) e cadastrar um novo webhook com a URL:
   ```
   <endereço do túnel do passo 1>/api/webhooks/clicksign-sonda
   ```
   Exemplo completo: `https://xxxx.ngrok-free.app/api/webhooks/clicksign-sonda`.
3. A Clicksign vai mostrar um **segredo** (secret) no momento do cadastro — é a única vez que ele
   aparece. Copiar esse valor e colar em `CLICKSIGN_WEBHOOK_SECRET` no `.env` local, **substituindo**
   o valor que já está lá.
4. Rodar, num terceiro terminal:
   ```
   C:\xampp\php\php.exe artisan config:clear
   ```

**Pergunta oportunista (pergunta em aberto nº 2 do `129-RESEARCH.md`, A4):** enquanto estiver
nessa tela, observar e anotar: **o cadastro do webhook é da CONTA inteira, ou de um envelope
específico?** Se o painel pedir para escolher um envelope/documento ao cadastrar, é por envelope;
se for uma tela de configuração geral da conta (sem pedir envelope), é por conta. Essa é a única
oportunidade barata de responder — importa para o desenho do cutover de produção (Fase 132).

### Passo 3 — Fazer algo acontecer num contrato de verdade (gate A1)

Usar um envelope descartável do sandbox (ou criar um novo pelo caminho já existente) e
**assinar** pela interface web da Clicksign. Isso faz a Clicksign bater na URL do túnel.

### Passo 4 — Ler o veredito

```
C:\xampp\php\php.exe artisan clicksign:verificar-assinatura --ultimo
```

A tabela impressa diz qual das 4 fórmulas bateu: `soma_body_secret`, `soma_secret_body`,
`hmac_body_chave_secret` ou `hmac_secret_chave_body`.

**Resultados possíveis:**
- **Exatamente uma candidata bate** → gate A1 FECHADO. Anotar a chave vencedora abaixo (seção
  "Resultado — Gate A1") e seguir para a Task 2 do plano 129-02.
- **Nenhuma bate** → **PARAR A FASE** (D-08). Anotar abaixo o header recebido (é um hash, não é
  segredo), o tamanho do corpo e as 4 saídas. Não inventar uma quinta fórmula.
- **O webhook nunca chega** → antes de concluir qualquer coisa, conferir: (a) o túnel do passo 1
  continua de pé; (b) a URL cadastrada no painel tem o caminho `/api/webhooks/clicksign-sonda`
  completo; (c) o arquivo `storage/logs/ecf-webhooks-<data-de-hoje>.log` tem alguma linha
  `[Clicksign Sonda HMAC]`.

### Passo 5 — Na MESMA rodada, medir os gates #6 e #7 (montar túnel de novo custa caro)

- **Recusa (`refusal`)** — num SEGUNDO envelope (não reusar o do passo 3), recusar a assinatura
  pela interface. Anotar: o `name` do evento que chegou (ver `storage/logs/ecf-webhooks-*.log` ou
  reconsultar o evento gravado) e se o `status` do envelope virou `closed` ou continuou `running`.
- **Prazo vencido (`deadline`)** — criar um envelope com o menor prazo que a Clicksign aceitar
  (ex.: 1 dia) e deixar vencer sem assinar. **Se não der para esperar dentro desta sessão, registrar
  como NÃO MEDIDO** e seguir — o plano 129-05 já cobre esse caso por fixture sintética. Não inventar
  o comportamento.

### Passo 6 — Coletar os corpos brutos

Para cada evento recebido (gate A1, `refusal`, `deadline` se medido), guardar o `payload` gravado
em `contrato_assinatura_eventos` — é a **primeira vez** que este projeto vê a forma real do corpo
que a Clicksign envia ao webhook (a doc oficial mostra duas formas incompatíveis entre si, ver
`129-RESEARCH.md` §"Nota de confiabilidade"). **Anonimizar e-mails, nomes, IPs e chaves antes de
colar em qualquer arquivo do repositório.**

Consulta pronta para reler um evento gravado sem imprimir nada sensível (rodar via
`php artisan tinker` ou script pontual):

```php
\App\Models\ContratoAssinaturaEvento::latest('id')->first()->only([
    'id', 'name', 'clicksign_envelope_id', 'status', 'created_at',
]);
```

Para o `payload` completo (JSON), usar o mesmo model e **revisar manualmente** antes de colar aqui
— não copiar e colar direto de um `dd()`/`dump()` sem checar e-mail/nome/IP.

### Passo 7 — Fechar o túnel

Encerrar o programa de túnel (Ctrl+C) e o `php artisan serve` ao terminar a sessão. O túnel é
temporário por desenho (T-129-09) — não deixar a máquina local acessível pela internet fora da
janela de medição.

---

## Resultado — Gate A1 (fórmula do `Content-Hmac`)

✅ **MEDIDO — fórmula única, confirmada em 5 de 5 eventos reais.**

`FORMULA_CONFIRMADA = hmac_body_chave_secret` — ou seja `hash_hmac('sha256', $rawBody, $secret)`,
digest em hex, header no formato `sha256=<hex>` (`ClicksignHmacVarredura::calcular()`).

| Candidata | Bate? (5/5 eventos) |
|---|---|
| `soma_body_secret` — `hash('sha256', body . secret)` | ❌ falhou nos 5 |
| `soma_secret_body` — `hash('sha256', secret . body)` | ❌ falhou nos 5 |
| `hmac_body_chave_secret` — `hash_hmac('sha256', body, secret)` | ✅ **bateu nos 5** |
| `hmac_secret_chave_body` — `hash_hmac('sha256', secret, body)` | ❌ falhou nos 5 |

**Fórmula vencedora:** `hmac_body_chave_secret`.

Isso resolve a A1 do `REQUIREMENTS-v22.md` a favor do `129-RESEARCH.md`/`PITFALLS.md`
(`hex(hmac_sha256(secret, body))`, equivalente a `hash_hmac('sha256', body, secret)`) — o
`STACK.md` estava **errado** (`hash('sha256', body . secret)`, que é a candidata
`soma_body_secret`, uma das que falhou).

**Eventos medidos** (todos gravados com `signature_valid = 0` porque a sonda mede sem validar,
por desenho — D-08):

| id | evento | quando (BRT) |
|---|---|---|
| 2 | `add_signer` | 2026-08-13 10:27:04 |
| 3 | `add_signer` | 2026-08-13 10:27:05 |
| 4 | `add_signer` | 2026-08-13 10:27:05 |
| 5 | `add_signer` | 2026-08-13 10:27:06 |
| 6 | `update_deadline` | 2026-08-13 10:27:06 |

(o id 7 foi um ping de conectividade manual, não veio da Clicksign — descartado da contagem.)

Todos os 5 vieram da **ativação** do envelope `aea3c36b-6f4f-40a3-a1dc-5be2422cb93f` — um rascunho
remanescente da Fase 128, ativado nesta sessão especificamente para gerar tráfego real de webhook.

**Corpo bruto anonimizado do evento id 2 (`add_signer`), trimado das seções repetidas/URLs de
download assinadas (que carregam token AWS temporário, não segredo da Clicksign, mas sem
utilidade documental):**

```json
{
  "event": {
    "name": "add_signer",
    "data": {
      "user": { "email": "usuario.api@example.com", "name": "Usuario API ECF (anonimizado)" },
      "account": { "key": "71db7df8-5355-47d1-b4c6-9f331320f0a4" },
      "signers": [
        {
          "sign_as": "party",
          "list_key": "43263525-ce66-4873-9338-f573515d2b75",
          "key": "3c8ab77d-b85b-434a-bf80-ce9e6e75408f",
          "email": "empresa.gate12806.r2@example.com",
          "name": "Fulano de Tal Silva",
          "auths": ["email"],
          "communicate_by": "email",
          "url": "https://sandbox.clicksign.com/notarial/widget/signatures/3c8ab77d-.../redirect"
        }
      ]
    },
    "occurred_at": "2026-08-13T10:27:02.931-03:00"
  },
  "document": {
    "key": "664544e7-fa4c-4b42-a60e-ba09b8dbb76e",
    "account_key": "71db7df8-5355-47d1-b4c6-9f331320f0a4",
    "path": "/Contrato — Gestão — Empresa Ficticia Gate 128-06 Rodada 2/contrato-6.docx",
    "filename": "contrato-6.docx",
    "status": "running",
    "auto_close": true,
    "deadline_at": "2026-09-12T10:27:01.000-03:00",
    "remind_interval": 3,
    "block_after_refusal": true,
    "template": { "key": "9e5d4517-...", "data": { "razao_social": "Empresa Ficticia Gate 128-06 Rodada 2", "...": "..." } },
    "signers": ["... 4 signatários, mesma forma resumida do array acima ..."],
    "events": ["... histórico retroativo do envelope inteiro, ver achado 4 abaixo ..."]
  }
}
```

E-mails e nomes do bloco `signers` já são dados de teste do sandbox (`@example.com`, "Fulano de Tal
Silva", "Socio Um ECF Teste Sandbox"). `key`s são UUIDs internos da Clicksign, não segredos.

**[Rule 1 — correção nesta rodada, plano 129-07]** O bloco `data.user` do evento (o dono da conta
que fez a última alteração no envelope, não um signatário) trazia um e-mail e nome reais de
colaborador — a afirmação acima ("nenhum dado real de pessoa física passou por aqui"), escrita no
plano 129-02, estava **errada** para esse campo específico. Anonimizado nesta edição
(`usuario.api@example.com` / "Usuario API ECF (anonimizado)"), seguindo a mesma regra do rodapé do
`CLICKSIGN-SANDBOX-EMPIRICO.md` (achado WR-07). Nenhuma ação além da edição do documento — não é
segredo (o token de API nunca esteve neste bloco), só e-mail/nome de colaborador que não deveriam
ter sido colados sem anonimizar.

**Comparação com as duas formas que a doc oficial mostra** (`129-RESEARCH.md` §"Nota de
confiabilidade"): bateu com a **primeira forma**, `{"event":{"name","data","occurred_at"},
"document":{...}}` — a forma JSON:API (`{"data":{"attributes":...}}`) **nunca** apareceu em nenhum
dos 5 eventos reais. O código de produção (plano 129-03) deve extrair `name` de
`event.name`/`document.key`, não de `data.attributes`/`data.id`.

---

## Outros achados desta rodada

1. **Rascunho não dispara webhook nem é assinável.** Os 3 envelopes remanescentes da Fase 128
   estavam em `draft`; nenhum gerou webhook algum. Só depois de `ativarEnvelope()` (status virou
   `running`) os webhooks começaram a chegar — `draft` é inerte, tanto para assinatura quanto para
   notificação.

2. **A v3 NÃO expõe link de assinatura nos atributos do signatário.** `GET /envelopes/{id}/signers`
   devolve apenas: `name, birthday, email, phone_number, location_required_enabled,
   has_documentation, documentation, refusable, group, communicate_events, signature_host,
   created, modified`. Nenhum `request_signature_key` / `sign_url`. O link de assinatura só sai por
   e-mail — relevante para a Fase 131 (tela do Administrativo), que não pode prometer mostrar o
   link na tela.

3. **A ativação dispara uma rajada de eventos retroativos**, não só o "agora": 4 `add_signer` +
   1 `update_deadline` chegaram em 3 segundos, descrevendo tudo que já tinha acontecido durante o
   rascunho (cada signatário adicionado, o prazo definido). Ou seja, o webhook entrega **histórico**,
   não um fluxo estritamente incremental — reforça a decisão de sempre decidir por reconsulta ao
   estado agregado do envelope, nunca pela ordem/conteúdo do evento isolado (D-06/D-07, CLICK-05).

4. **`consultarEnvelope()` devolve o recurso DESEMBRULHADO** — chaves `id`, `type`, `links`,
   `attributes`, `relationships` direto no topo da resposta, **não** dentro de `data`. Qualquer
   código que fizer `['data']['attributes']` sobre o retorno deste método lê `null` em silêncio, sem
   erro. Já era um padrão observado em §9.5 do `CLICKSIGN-SANDBOX-EMPIRICO.md` (o client
   desembrulha o `data`) — esta rodada confirma que vale também para `consultarEnvelope()`.

---

## Resultado — Gate #6 (`deadline`, prazo vencido)

**NÃO MEDIDO nesta rodada.** Nenhuma recusa nem expiração foi exercitada — a janela da sessão foi
usada inteira para o gate A1 (bloqueante, sem plano B) e para confirmar o achado abaixo. O plano
129-05 cobre este caso por fixture sintética; ninguém deve inventar o comportamento do evento
`deadline` a partir de suposição.

**Achado relacionado, este SIM medido ao vivo:** `deadline_partial_signature_action: "closed"`
apareceu confirmado na resposta de `GET /envelopes/{id}` (`consultarEnvelope()`) do envelope
ativado nesta sessão. Era a hipótese mais importante da pesquisa sobre este gate — agora é fato
medido: **o envelope PODE fechar com assinatura parcial**, dependendo de como o `deadline_partial_
signature_action` foi configurado na criação. Isso reforça o gate de liberação do plano 129-04
(CLICK-05 — decidir por reconsulta ao estado agregado do envelope, nunca pelo payload isolado do
evento).

---

## Resultado — Gate #7 (`refusal`, recusa de assinatura)

**NÃO MEDIDO nesta rodada** — mesma razão do gate #6 (janela de sessão usada para o gate
bloqueante A1). O `name` do evento de recusa, o `status` do envelope após recusar
(`closed`/`running`) e a forma de `refusal.reasons`/`refusal.comment` continuam desconhecidos.
Registrar honestamente como pendência para a rodada de medição do gate final (129-07) — nenhuma
suposição foi usada para preencher esta lacuna.

---

## Resposta à pergunta oportunista — `/webhooks` é por conta ou por envelope? (A4)

**NÃO OBSERVADO nesta rodada.** O cadastro do webhook não foi refeito nesta sessão — o receiver já
estava apontado para o túnel de uma configuração anterior. Fica pendente para a próxima vez que o
painel de webhooks da Clicksign for aberto.

---

## O que continua permanentemente NÃO MEDIDO por esta fase

- **Gate #11 — política de retry e ordem de entrega.** Fora do alcance de uma sessão manual de
  medição (exigiria provocar falha proposital no receiver e esperar reentrega). Tratado como pior
  caso pelo `129-RESEARCH.md`: at-least-once, possivelmente fora de ordem. Nenhuma fase desta
  cadeia promete medir isso.

---

## Rodada ponta a ponta — pré-verificação do executor (plano 129-07)

**Medido em:** 2026-08-13, 12:06–12:08 BRT — mesmo túnel cloudflared e mesmo `artisan serve`
locais desta sessão contínua (nunca derrubados desde o gate A1).

**O que isto É:** o executor do plano 129-07, ANTES de envolver o usuário na rodada real de
assinatura (Task 1 do plano, `checkpoint:human-verify`), provou contra a rota de **produção**
`POST /api/webhooks/clicksign` — atravessando a internet pelo túnel, não em teste automatizado —
que a fiação de validação/gravação/dedup funciona com dados reais de rede. Nenhum `Http::fake()`
envolvido.

**O que isto NÃO É:** isto não substitui a Task 1. Nenhum envelope real da Clicksign, nenhuma
assinatura humana de verdade, nenhuma liberação de empresa de verdade e nenhum PDF assinado real
foram produzidos aqui — os dois eventos abaixo são requisições **sintéticas**, com corpo forjado
pelo executor (`verificacao_e2e_claude` / `e2e_assinatura_invalida_claude`, nomes de evento que a
Clicksign nunca emite), usadas só para provar o comportamento do receiver sob as três condições que
o critério de reprovação do plano lista. A rodada real com o sandbox — assinar de verdade, recusar
de verdade, ver o PDF baixado — continua pendente e é o objeto do checkpoint devolvido ao final
deste documento.

### B) Receiver de produção provado ponta a ponta pela internet

Três requisições HTTP reais, do exterior do processo Laravel, contra `POST
/api/webhooks/clicksign` pelo endereço do túnel — não chamada de teste, não `TestCase`:

| Cenário | Corpo (sintético, anonimizado — sem PII) | HTTP | Evidência no banco |
|---|---|---|---|
| Assinatura **VÁLIDA** (`Content-Hmac` calculado com `hmac_body_chave_secret`, a fórmula medida no gate A1) | `{"event":{"name":"verificacao_e2e_claude","occurred_at":"2026-08-13T12:06:59-03:00"}}` | **200** | evento **id 8** — `signature_valid=1`, `status='ignorado'`, `erro_msg='envelope nao pertence a nenhum contrato deste sistema'` (esperado: o `document.key` do corpo sintético não casa com nenhum `ContratoAssinatura` real — comportamento do passo 3 da matriz de status da D-10, "não é erro") |
| Assinatura **INVÁLIDA**, corpo novo | `{"event":{"name":"e2e_assinatura_invalida_claude"}}` | **401** | evento **id 10** — `signature_valid=0`, `status='erro'`, `erro_msg='assinatura invalida ou ausente'`, `payload.raw` preservado com o corpo bruto, `ip='127.0.0.1'` |
| Corpo **REPETIDO** (mesma assinatura inválida testada de novo) | reenvio de uma tentativa com assinatura inválida | **401** | **nenhuma linha nova persistida** — dedup por `payload_hash` recusando a segunda gravação (23000 silenciosamente capturado em `gravarEvento()`) |

**Log correspondente** (`storage/logs/ecf-webhooks-2026-08-13.log`, sem secret nem payload):
```
[2026-08-13 12:07:17] local.WARNING: [ProcessarEventoClicksignJob] Envelope sem contrato correspondente {"evento_id":8,"clicksign_envelope_id":null}
[2026-08-13 12:07:19] local.WARNING: [Clicksign Webhook] Requisicao recusada {"motivo":"assinatura invalida ou ausente","ip":"127.0.0.1","body_size":85}
[2026-08-13 12:07:37] local.WARNING: [Clicksign Webhook] Requisicao recusada {"motivo":"assinatura invalida ou ausente","ip":"127.0.0.1","body_size":51}
```

Isto prova ao vivo, contra a rota real de produção (não a sonda, que já não existe):
- **CLICK-03** — assinatura inválida recusa com 401, sem exceção não tratada;
- **CLICK-04** — o mesmo corpo (mesmo `payload_hash`) reenviado não grava uma segunda linha;
- **DADOS-03** — o evento é gravado bruto mesmo quando a assinatura é recusada (evento id 10 tem
  `payload.raw` preenchido);
- a matriz de status code da D-10 (`ClicksignWebhookController`, docblock) bate com o observado:
  200 para "envelope não casa com contrato nenhum", 401 para assinatura inválida.

⚠️ **`ip_address='127.0.0.1'` em todos os eventos desta sessão, incluindo os do gate A1 — não é
bug.** O cloudflared entrega a requisição ao `artisan serve` local via loopback; `$request->ip()`
sempre lê o IP de quem conecta na porta 8000, que é o processo do túnel na própria máquina, nunca o
IP público de origem. Não há vazamento de PII de rede aqui porque nunca houve IP público capturado
— mas também significa que **este ambiente nunca vai provar quais valores reais de
`X-Forwarded-For` a Clicksign manda**; isso só é observável atrás de um proxy reverso real (VPS,
Fase 132).

⚠️ **Lacuna no id sequencial (evento id 9 ausente).** `SHOW TABLE STATUS` confirma
`Auto_increment=11` para 10 tentativas de `INSERT`, mas só 8 linhas existem (ids 2–8, 10; o id 1
já estava consumido antes desta fase). Nenhuma entrada de erro correspondente apareceu no log entre
os ids 8 e 10 — consistente com o comportamento conhecido do InnoDB de não reciclar o valor de
`AUTO_INCREMENT` mesmo quando um `INSERT` não persiste (ex.: uma tentativa intermediária de reenvio
capturada pela unicidade de `payload_hash` antes de qualquer log ser emitido). Não afeta nenhum dos
três resultados provados acima — só registrado aqui por disciplina de não esconder o que não foi
plenamente explicado.

**Nenhuma chamada real à API da Clicksign aconteceu nesta pré-verificação** (o `document.key`
sintético nunca bateu com um `ContratoAssinatura`, então `ProcessarEventoClicksignJob` parou no
"envelope sem contrato correspondente" sem reconsultar nada). Por isso o orçamento de requisições
por evento processado (2 para o processamento + 1 para o download, contra a janela de 20/min
medida) **continua sem medição nesta rodada** — só a Task 1 real, com um envelope de verdade, mede
isso. Fica como item aberto explícito para a Fase 132.

### C) Achados de API — já registrados no gate A1, sem novidade nesta pré-verificação

Os quatro achados de API desta sessão (`deadline_partial_signature_action: "closed"`, rascunho
inerte, rajada de eventos retroativos na ativação, `consultarEnvelope()` desembrulhado) foram todos
medidos durante a rodada do gate A1 (seção "Outros achados desta rodada" acima) e já estão
espelhados em `CLICKSIGN-SANDBOX-EMPIRICO.md` §12. Nenhuma medição nova de API foi feita nesta
pré-verificação — ela testou só o receiver, contra corpo sintético, sem tocar a Clicksign.

### D) Eventos na tabela `contrato_assinatura_eventos` — estado consolidado

| id | origem | `name` | `signature_valid` | `status` | Nota |
|---|---|---|---|---|---|
| 2–6 | webhook real (gate A1) | `add_signer` ×4, `update_deadline` | 0 (sonda não valida por desenho) | `recebido` | ver seção do gate A1 acima |
| 7 | ping manual do orquestrador | — | 0 | `recebido` | descartado da contagem do gate A1 |
| 8 | pré-verificação 129-07 (sintético) | `verificacao_e2e_claude` | **1** | `ignorado` | prova o caminho "assinatura válida, envelope não casa com contrato" |
| 9 | — | — | — | — | ausente — ver nota da lacuna acima |
| 10 | pré-verificação 129-07 (sintético) | `e2e_assinatura_invalida_claude` | 0 | `erro` | prova recusa 401 + gravação bruta |

## O que continua NÃO MEDIDO após esta rodada (honesto, sem eufemismo)

Esta pré-verificação fechou a prova de que o **receiver** funciona ponta a ponta pela internet. O
que continua sem medição real é exatamente o que só um envelope de verdade, assinado por uma
pessoa, pode provar — nada disto foi inventado nem assumido:

1. **Gate #7 (`refusal`)** — nenhuma recusa real foi exercitada. O nome do evento (`refusal`) vem
   da documentação (confiança MÉDIA); ninguém viu esse evento disparar de um sandbox real.
2. **Gate #6 (`deadline`)** — nenhuma expiração real. O prazo mínimo do sandbox é medido em DIAS
   (`deadline_at`), não é mensurável dentro de uma sessão de algumas horas.
3. **Gate #11 (retry/ordem de entrega da própria Clicksign)** — sem documentação (3 páginas oficiais
   já checadas) e sem medição possível sem provocar falha proposital no receiver e esperar a
   Clicksign reenviar. Tratado como pior caso no código (at-least-once, sem garantia de ordem).
   **Permanentemente não medido** — nenhuma fase futura promete medir isto.
4. **Assinatura real completa → liberação → download do PDF.** O circuito de negócio inteiro (uma
   pessoa assinando de verdade no sandbox, o webhook real chegando, `ProcessarEventoClicksignJob`
   reconsultando, `EmpresaOperacionalRouter::liberarEmpresa()` rodando, `BaixarPdfContratoAssinadoJob`
   baixando o PDF de verdade) **não foi exercitado nesta sessão.** O que esta pré-verificação provou
   foi só a camada de recepção/validação/dedup do webhook — não o restante da cadeia.
5. **Pergunta A4 (`/webhooks` é registrado por conta ou por envelope?)** — não observado; o cadastro
   do webhook não foi refeito nesta sessão.
6. ⚠️ **O webhook cadastrado pelo usuário no painel da Clicksign aponta para
   `/api/webhooks/clicksign-sonda`, rota que foi REMOVIDA no plano 129-02.** Antes de qualquer
   medição real (item 4 acima), o usuário precisa reabrir o painel de Webhooks do sandbox e trocar
   a URL para `<endereço do túnel>/api/webhooks/clicksign` — **sem** `-sonda`. Sem esse ajuste, a
   Clicksign vai continuar batendo numa rota que devolve 404, e nenhum evento real chega.

---

## Checklist de fechamento

- [x] Gate A1 fechado — `hmac_body_chave_secret` bateu em 5/5 eventos reais, as outras 3 falharam nos 5
- [x] Corpo bruto de pelo menos um evento real anonimizado e colado acima (evento id 2)
- [x] Gate #6 (`deadline`) registrado — explicitamente "NÃO MEDIDO" (achado colateral medido: `deadline_partial_signature_action: "closed"`)
- [x] Gate #7 (`refusal`) registrado — explicitamente "NÃO MEDIDO"
- [x] Pergunta conta-vs-envelope respondida — "não observado" nesta rodada
- [x] Receiver de produção (`/api/webhooks/clicksign`) provado ponta a ponta pela internet, com
      assinatura válida (200), assinatura inválida (401) e reentrega (401, sem duplicar) — plano
      129-07, pré-verificação do executor
- [ ] **Rodada real de assinatura, recusa e prazo vencido contra o sandbox — PENDENTE.** Depende do
      usuário: (a) atualizar a URL do webhook no painel (item 6 acima), (b) assinar de verdade um
      contrato de teste, (c) recusar um segundo contrato de teste. Ver checkpoint devolvido pelo
      executor ao final da execução do plano 129-07.
- [ ] Túnel e `php artisan serve` encerrados — **continuam DE PÉ**, porque a rodada real do plano
      129-07 (item acima) ainda depende deles. Fechar só depois que o usuário confirmar a rodada
      real ou desistir dela nesta sessão (T-129-09).
- [x] Nenhum token/secret/header de autorização foi colado neste documento (T-129-10/T-129-11)
- [x] Nenhum e-mail, nome real ou IP público foi colado neste documento — os dois eventos novos
      (id 8, id 10) usam corpo sintético forjado pelo executor, sem dado de pessoa nenhuma
