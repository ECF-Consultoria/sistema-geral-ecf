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

## GATE 2 — a conta de PRODUÇÃO enxerga o modelo? — ✅ **MEDIDO: SIM**

Autorizado pelo usuário em 12/08/2026. **Primeira vez que a conta de produção foi consultada em toda
a milestone.** Somente leitura: `GET /templates`, nenhum envelope criado, nada enviado a ninguém.

⚠️ **Como o token de produção foi manuseado:** chave dedicada `CLICKSIGN_PROD_TOKEN` no `.env`
(gitignored), lida por script pontual que monta a URL de produção explicitamente.
`CLICKSIGN_ENV`/`CLICKSIGN_BASE_URL` do projeto **continuaram apontando para o sandbox** — nenhum
teste, comando ou job passou a tocar a conta real. O token nunca foi impresso nem colado no chat.

### O susto que não era

A primeira chamada devolveu **403**, e por um momento pareceu ser o gate de PLANO que a pesquisa
previu ("A conta não possui acesso a essa funcionalidade" → exigiria trocar de plano, decisão
comercial). **Não era.** O detalhe era o outro 403, o da §1 do empírico:

```
403 => E-mail do usuário da API não configurado.
       Verifique as informações na aba API em configurações
```

Três conclusões de uma vez: o plano de produção **tem** acesso à API, a aba API **existe** na conta,
e o token é **válido** (passou da autenticação, parou na camada seguinte). Faltava uma configuração
de uma linha, a mesma já feita no sandbox.

⚠️ **Lição: a Clicksign usa 403 para dois casos muito diferentes** — um é configuração trivial, o
outro é decisão comercial. Distinguir pelo `detail`, nunca pelo código. O script do gate foi
corrigido para não confundir os dois.

Depois de o usuário preencher o e-mail do usuário da API em produção:

```
GET /templates => 200
  modelos na conta: 1
    - modelo-contrato-gestao-ads-mercado-livre   <== o nosso
  modelo 68c524fd-… presente? SIM
```

---

## GATE 3 — confronto de variáveis do modelo de PRODUÇÃO — ✅ **MEDIDO: BATE**

O usuário recadastrou o modelo em produção com o `.docx` atualizado (rodapé com os nomes dos 4
signatários da D-20). Chave nova: `68c524fd-f8de-45ff-a619-23cc0862e434` — a antiga (`dbf44e04-…`,
sem os nomes) foi substituída.

Confronto entre o que o `.docx` espera e o que `ContratoVariaveisModeloService::nomes()` emite —
extração **local**, zero requisições:

| Categoria | Qtd | Quais |
|---|---|---|
| ✅ OK | 7 | `cnpj`, `data_assinatura`, `data_primeira_parcela`, `dia_vencimento`, `endereco`, `razao_social`, `valor_mensal` |
| ⚠️ **SOBRANDO no `.docx`** | **0** | — |
| Não usadas pelo modelo | 3 | `servico_contratado`, `vigencia_fim`, `vigencia_inicio` |

**Zero `sobrando` é o que importa.** Essa é a única categoria perigosa: variável que o modelo pede e
o código não emite vira **campo em branco no contrato, sem erro nenhum** (§10.5 do empírico). As 3
"não usadas" são o caso inverso e inofensivo — medido que variável sobrando no payload é aceita.

As 3 não usadas são consequência esperada da **D-21**: com um modelo por serviço,
`servico_contratado` deixou de existir no documento; `vigencia_inicio`/`vigencia_fim` não entraram
porque a cláusula 11ª usa "12 meses a partir da assinatura" e colocar datas ali mudaria o sentido
jurídico.

---

## Placar final dos gates

| Gate | Status | Resultado |
|---|---|---|
| Task 1 — envelope real no sandbox | ✅ MEDIDO | prazo/lembrete/draft/4 signatários/8 requisitos, todos conforme |
| GATE 1 — prazo sobrevive à ativação humana | ✅ MEDIDO | **SIM**, idêntico ao segundo |
| GATE 2 — produção enxerga o modelo | ✅ MEDIDO | **SIM**, `200` após configurar o e-mail da API |
| GATE 3 — variáveis do modelo de produção | ✅ MEDIDO | **7 ok, 0 sobrando** |

**Nenhum gate ficou como NÃO MEDIDO.**

## O que o gate produziu além de vereditos

1. **Correção de código** (`50415659`) — a checagem de bloqueio não olhava a configuração da própria
   ECF; signatários vazios queimavam 3 chamadas antes de falhar. 5º bug da milestone achado por
   medição real, nenhum deles pegável por `Http::fake()`.
2. **Achado que muda a Fase 130** — rascunho expira em **7 dias**, e a D-02 faz o sistema parar
   exatamente aí. Ver §11.2 do empírico.
3. **Risco de milestone descartado** — o plano de produção tem acesso à API. Era o maior risco em
   aberto e ninguém tinha verificado.

## O que segue NÃO MEDIDO (declarado, não escondido)

- Se os 7 dias do rascunho contam da criação ou da última atualização, e o que acontece na
  expiração. → **Fase 130**.
- Se a tela de envio, que memoriza preferência de prazo do operador, pode sobrescrever o prazo que o
  sistema mandou num documento seguinte. → **Fase 131**.
- `DELETE` de envelope já ativado (`running`) — o rollback desta fase só roda antes da ativação.

## Higiene de credencial

`CLICKSIGN_PROD_TOKEN` foi adicionado ao `.env` **só para este gate**. Ele não é lido por nenhum
código de produção — apenas pelo script pontual do gate. **Remover do `.env` quando não for mais
necessário**; o cutover de verdade é a **Fase 132**, e lá a decisão é trocar `CLICKSIGN_ENV`/
`CLICKSIGN_BASE_URL`/`CLICKSIGN_ACCESS_TOKEN` no servidor, não manter uma chave paralela.
