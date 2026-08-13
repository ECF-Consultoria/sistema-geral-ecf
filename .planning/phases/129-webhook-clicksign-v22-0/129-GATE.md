# Fase 129 — Gate A1 (plano 129-02)

**Preparado em:** 2026-08-13 (roteiro + verificação de ambiente).
**Medido em:** _(preencher quando o usuário concluir a sessão de medição)_
**Status geral:** ⏳ **AGUARDANDO MEDIÇÃO** — nada abaixo, além da seção "Ambiente confirmado", foi
observado contra um webhook real ainda. Este documento nasce como roteiro executável e é
completado por um agente de continuação depois que o usuário voltar com o resultado.

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

**PENDENTE DE MEDIÇÃO.**

| Candidata | Bate? |
|---|---|
| `soma_body_secret` | _(preencher)_ |
| `soma_secret_body` | _(preencher)_ |
| `hmac_body_chave_secret` | _(preencher)_ |
| `hmac_secret_chave_body` | _(preencher)_ |

**Fórmula vencedora:** _(preencher, ou "NENHUMA BATEU — fase parada")_

**Corpo bruto anonimizado de pelo menos um evento (gate A1):**

```
(preencher — payload JSON com e-mail/nome/IP/chaves trocados por identificadores de teste)
```

**Comparação com as duas formas que a doc oficial mostra** (`129-RESEARCH.md` §"Nota de
confiabilidade"): _(preencher — bateu com a forma `{"event":{"name","data","occurred_at"},
"document":{...}}`, com a forma JSON:API, ou com nenhuma das duas?)_

---

## Resultado — Gate #6 (`deadline`, prazo vencido)

**PENDENTE DE MEDIÇÃO** — ou **NÃO MEDIDO** se não der para esperar o prazo vencer dentro da
sessão (aceitável; o plano 129-05 cobre por fixture sintética).

_(preencher: `name` recebido, payload anonimizado, `status`/`deadline_partial_signature_action`
do envelope reconsultado)_

---

## Resultado — Gate #7 (`refusal`, recusa de assinatura)

**PENDENTE DE MEDIÇÃO.**

_(preencher: `name` recebido, payload anonimizado incluindo `refusal.reasons`/`refusal.comment`,
`status` do envelope após a recusa — `closed` ou `running`)_

---

## Resposta à pergunta oportunista — `/webhooks` é por conta ou por envelope? (A4)

**PENDENTE DE OBSERVAÇÃO** — anotar durante o passo 2. Ou "não observado" se a tela não deixar
claro.

---

## O que continua permanentemente NÃO MEDIDO por esta fase

- **Gate #11 — política de retry e ordem de entrega.** Fora do alcance de uma sessão manual de
  medição (exigiria provocar falha proposital no receiver e esperar reentrega). Tratado como pior
  caso pelo `129-RESEARCH.md`: at-least-once, possivelmente fora de ordem. Nenhuma fase desta
  cadeia promete medir isso.

---

## Checklist de fechamento (preencher depois da medição)

- [ ] Gate A1 fechado (exatamente uma candidata bateu) OU fase formalmente parada (nenhuma bateu)
- [ ] Corpo bruto de pelo menos um evento real anonimizado e colado acima
- [ ] Gate #6 (`deadline`) registrado — medido ou explicitamente "NÃO MEDIDO"
- [ ] Gate #7 (`refusal`) registrado — medido ou explicitamente "NÃO MEDIDO"
- [ ] Pergunta conta-vs-envelope respondida ou "não observado"
- [ ] Túnel e `php artisan serve` encerrados
- [ ] Nenhum token/secret/header de autorização foi colado neste documento (T-129-10/T-129-11)
