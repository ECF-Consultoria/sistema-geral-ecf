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
      "user": { "email": "adm@ecfconsultoria.com.br", "name": "Leticia Moura" },
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

E-mails e nomes já são dados de teste do sandbox (`@example.com`, "Fulano de Tal Silva", "Socio Um
ECF Teste Sandbox") — nenhum dado real de pessoa física passou por aqui. `key`s são UUIDs internos
da Clicksign, não segredos.

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

## Checklist de fechamento

- [x] Gate A1 fechado — `hmac_body_chave_secret` bateu em 5/5 eventos reais, as outras 3 falharam nos 5
- [x] Corpo bruto de pelo menos um evento real anonimizado e colado acima (evento id 2)
- [x] Gate #6 (`deadline`) registrado — explicitamente "NÃO MEDIDO" (achado colateral medido: `deadline_partial_signature_action: "closed"`)
- [x] Gate #7 (`refusal`) registrado — explicitamente "NÃO MEDIDO"
- [x] Pergunta conta-vs-envelope respondida — "não observado" nesta rodada
- [ ] Túnel e `php artisan serve` encerrados — **deliberadamente DEIXADOS DE PÉ** ao final desta
      sessão de continuação: o usuário sinalizou intenção de medir os gates #6/#7 em seguida, e
      derrubar o túnel agora custaria remontá-lo. Fica registrado aqui como pendência consciente,
      não como esquecimento — quem fechar a próxima sessão de medição (ou encerrar sem medir mais
      nada) deve lembrar de fechar o túnel e o `php artisan serve` (T-129-09).
- [x] Nenhum token/secret/header de autorização foi colado neste documento (T-129-10/T-129-11)
