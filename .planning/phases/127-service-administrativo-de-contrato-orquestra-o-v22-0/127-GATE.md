# Fase 127 — Gate (plano 127-07)

**Ambiente:** SANDBOX (`https://sandbox.clicksign.com/api/v3`) — confirmado antes de qualquer
requisição. Nenhuma chamada à conta de produção.
**Data:** 2026-08-12

> Identificadores e e-mails abaixo são de teste. Nenhum dado de cliente real foi enviado, e nenhum
> valor real foi colado aqui (regra de anonimização do `CLICKSIGN-SANDBOX-EMPIRICO.md`).

---

## Task 1 — Envelope completo com prazo customizado — **MEDIDO**

Rodado o fluxo REAL desta fase (`ContratoClicksignService::iniciarParaEmpresa()`, com o job
processado) contra o sandbox, com empresa de teste e `prazoDias: 10, lembreteDias: 7`.

⚠️ **O MariaDB local está fora do ar**, então o cenário foi montado com as factories sobre o SQLite
dos testes, sem `Http::fake()` — o banco é de teste, a API é real.

**Resultado de `iniciarParaEmpresa()`:**

```
ok: true | faltando: [] | criados: 1
contrato #1 | status=rascunho | envelope=d02d1a34-… | prazo=10 | lembrete=7
```

**Reconsulta na API (`GET /envelopes/{id}`) — o que a Clicksign de fato gravou:**

| Campo | Observado | Esperado | Veredito |
|---|---|---|---|
| `status` | `draft` | `draft` (D-02: não ativamos) | ✅ |
| `deadline_at` | `2026-08-22T10:55:31-03:00` | hoje + 10 dias = `2026-08-22` | ✅ |
| `remind_interval` | `7` | `7` | ✅ |
| documentos | 1 | 1 | ✅ |
| signatários | 4 | 4 (D-08) | ✅ |
| requisitos | 8 | 8 (2 por signatário) | ✅ |

**Envelope do gate:** `d02d1a34-c3d8-4e9f-b9ec-ed03d37761b7` (sandbox, em `draft`).
**Modelo usado:** `9e5d4517-…` — recadastrado no sandbox nesta sessão, já que o anterior foi
excluído de propósito ao medir a dívida D-16 na Fase 126.

**Confronto de variáveis do modelo** (`clicksign:sondar-modelo --criar-modelo=… --confirmar`):
7 `ok`, 3 `faltando no .docx` (`servico_contratado`, `vigencia_inicio`, `vigencia_fim`) — as três
são emitidas pelo código e não usadas pelo modelo, e a §10.5 do empírico já mediu que variável
sobrando é aceita sem erro. **Zero `sobrando no .docx`**, que seria o caso perigoso.

### ⚠️ Achado do gate — e ele virou correção de código

O fluxo **falhou na primeira execução**, e o motivo não era pegável por nenhum teste local:

```
[Clicksign] email - não pode ficar em branco
```

`config('services.clicksign.signatarios_ecf')` nasce com as **3 entradas presentes e VAZIAS** (as
chaves do `.env.example` vêm sem valor). A checagem de dados mínimos validava só a **empresa**, então
o fluxo passava pelo bloqueio, criava o envelope, criava o documento, e só então a API recusava o 1º
signatário — **3 chamadas queimadas** da janela medida de 20/min, mais o rollback, por um dado
sabível **sem nenhuma requisição**.

Isso contradiz o Goal literal da fase. Corrigido em `50415659`: `faltantesDaConfiguracaoEcf()`
bloqueia antes de qualquer I/O, em chave própria (`configuracao`), separada das pendências da
empresa — porque pendência de empresa o Comercial resolve na tela da Fase 131, e isto é `.env`, que
só um admin resolve. 4 testes novos, incluindo o que prova o Goal: empresa perfeita + config vazia
→ `Http::assertNothingSent()` e zero linhas gravadas.

**Não é caso de borda:** é o estado padrão de qualquer ambiente recém-configurado — produção
inclusive, antes do cutover da Fase 132.

---

## GATE 1 — o prazo sobrevive à ativação pela interface? — ✅ **MEDIDO: SIM**

O usuário abriu o rascunho na interface do sandbox, conferiu o prazo e clicou em **Enviar** em
12/08/2026. Reconsulta pela API imediatamente depois:

| Campo | Antes (criação, por código) | Depois (ativação, pela interface) | Sobreviveu? |
|---|---|---|---|
| `status` | `draft` | `running` | — (esperado) |
| `deadline_at` | `2026-08-22T10:55:31.000-03:00` | `2026-08-22T10:55:31.000-03:00` | ✅ **idêntico ao segundo** |
| `remind_interval` | `7` | `7` | ✅ |
| `auto_close` | `true` | `true` | ✅ |

**A D-03 está garantida ponta a ponta.** Este era o risco de a D-02 (parar no rascunho) e a D-03
(prazo customizado) se anularem — definir prazo na criação e deixar um humano ativar **não** perde o
valor. Não é preciso reaplicar prazo na ativação, e a Fase 130 pode confiar em `prazo_dias` do banco
como o prazo real.

### O que a tela de envio mostrou, e vale para as Fases 130/131

Print conferido antes do envio: os valores definidos por código chegam **pré-preenchidos** na tela
que o Comercial usa ("Data limite: Sáb, 22 de ago de 2026 às 10:55" / "Enviar lembretes: A cada 7
dias"). Ele não precisa saber que existe prazo customizado — já está lá.

Dois achados colaterais da mesma tela:

1. **"3 lembretes por destinatário"** — a Clicksign *deriva* a quantidade de lembretes do intervalo
   e do prazo. Não é um número controlável direto; só `remind_interval` é.
2. ⚠️ **"Suas configurações serão salvas automaticamente para o próximo uso"** — a tela memoriza a
   preferência de quem enviou. Se alguém alterar o prazo manualmente uma vez, isso pode virar o
   padrão da tela no próximo documento e **sobrescrever** o que o sistema mandou. Não foi medido, e
   é ponto de atenção para a Fase 131 (que expõe o botão de gerar).

### ⚠️ Achado NOVO e sério: rascunho expira em 7 dias

A tela de Rascunhos avisa: **"Os rascunhos ficam disponíveis por 7 dias."**

Isso **colide com a D-02**. Pela D-02 o sistema monta o contrato e para no rascunho, e quem envia é
o Comercial. Se ele demorar mais de 7 dias, **a Clicksign apaga o rascunho** — e o nosso banco
continuaria com `status = rascunho` e um `clicksign_envelope_id` apontando para um envelope que não
existe mais.

Não é caso de borda: contrato parado esperando revisão é exatamente o caso comum que a D-02 cria.

**Entrada obrigatória para a Fase 130** (rede de segurança / alerta de contrato preso):
- o alerta precisa disparar **antes** dos 7 dias, não depois;
- a reconciliação precisa distinguir "rascunho vivo" de "rascunho que a Clicksign já apagou" — o
  sintoma provável é `GET /envelopes/{id}` devolvendo 404, como já medido no descarte (§9.2 do
  empírico).

**NÃO MEDIDO:** se os 7 dias contam da criação ou da última atualização, e qual o comportamento
exato na expiração (some da lista? vira `canceled`?). Medir na Fase 130, que é quem depende disso.

---

## GATE 2 — consultar a conta de PRODUÇÃO — **NÃO AUTORIZADO ATÉ AGORA**

A conta de produção **nunca foi consultada**, nem uma vez, em nenhuma sessão desta milestone. Exige
autorização explícita do usuário. `clicksign:sondar-modelo --listar --producao --confirmar` existe
para isso e pede confirmação interativa antes de sair do sandbox.

Status: **NÃO MEDIDO**.

---

## GATE 3 — confronto de variáveis do modelo de PRODUÇÃO — **BLOQUEADO PELO GATE 2**

O modelo cadastrado na conta de produção pelo usuário (`dbf44e04-…`) é a versão **sem os nomes no
rodapé** — o `.docx` foi atualizado depois (Emerson Faccioli / Thiago Messina / Jéssica de Oliveira)
e precisa ser recadastrado lá antes de qualquer confronto valer.

Status: **NÃO MEDIDO**.
