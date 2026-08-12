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

## GATE 1 — o prazo sobrevive à ativação pela interface? — **AGUARDANDO HUMANO**

O que já se sabe: o prazo de 10 dias e o lembrete de 7 foram **aceitos na criação** e estão gravados
no envelope. O que **ninguém mediu** é se ativar pela interface web — que é o gesto que o Comercial
fará por causa da D-02 — **preserva** esses valores ou os sobrescreve pelo padrão de 30/3.

Status: **NÃO MEDIDO**. Ver instruções ao usuário no fim deste arquivo.

**Consequência se não sobreviver:** o prazo customizado do DADOS-06 fica sem garantia depois da
ativação humana, e a Fase 130 (alerta de contrato preso) calcularia em cima de um prazo que não é o
real. Seria achado bloqueante para as Fases 129/131, não detalhe.

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
