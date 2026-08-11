# Fase 127 — Log da discussão (2026-08-11)

Registro humano da conversa. **Não é consumido pelos agentes** — o que vale para eles é o
`127-CONTEXT.md`.

## Áreas apresentadas

Quatro áreas cinzentas identificadas a partir do Goal do ROADMAP, do bloco "ENTRADA OBRIGATÓRIA DA
FASE 126" e das medições da fase anterior. **O usuário selecionou as quatro.**

## Área 1 — Empresa com 2+ serviços

**Pergunta:** com um modelo por serviço, ela recebe N contratos = 15×N chamadas contra o limite
medido de 20/min. Como o sistema se comporta?

| Opção | Escolhida |
|---|---|
| Fila com espaçamento automático | ✅ |
| Um de cada vez, manual | — |
| Todos de uma vez, síncrono | — |

Virou **D-01**. Motivo do descarte do manual registrado no CONTEXT: nada garante que o Comercial
lembre de gerar o segundo contrato.

## Área 2 — Conferir antes de enviar

**Pergunta:** não há pré-visualização sem ativar, e ativar dispara e-mail ao cliente. Onde o sistema
para?

| Opção | Escolhida |
|---|---|
| Para no rascunho; Comercial envia pela Clicksign | ✅ |
| Envia direto | — |
| Depende do serviço | — |

Virou **D-02**. Consequência levantada durante a escrita do contexto e registrada: `enviado_em` não
pode ser gravado por quem monta o envelope, porque a ativação passa a acontecer fora do sistema.

## Área 3 — Falha no meio da montagem

**Pergunta:** são 15 chamadas por envelope; se a 8ª falhar, o que fazer com o envelope pela metade?

| Opção | Escolhida |
|---|---|
| Cancelar tudo e recomeçar | ✅ |
| Guardar como erro para retomar | — |

Virou **D-04**. Confirma o comportamento que a Fase 126 já tinha implementado (D-12) e medido.

## Área 4 — Prazo de assinatura

**Pergunta:** o padrão medido é 30 dias com lembrete a cada 3; o critério 3 do ROADMAP exige poder
fugir do padrão.

| Opção | Escolhida |
|---|---|
| Padrão em config + Comercial encurta por contrato | ✅ |
| Só o padrão, igual para todos | — |
| Prazo por serviço | — |

Virou **D-03**.

## Medição feita durante a discussão

As escolhas 2 e 4 se cruzavam num ponto que poderia invalidar uma delas: se o prazo só fosse aceito
na **ativação**, e a ativação passasse a ser feita pelo Comercial na interface (D-02), o prazo
escolhido se perderia.

Em vez de presumir, foi medido contra o sandbox:

```
POST /envelopes  { deadline_at: 2026-08-21..., remind_interval: 7 }
=> 201, devolvidos exatamente os valores pedidos
```

**As duas decisões convivem.** O que ficou NÃO MEDIDO — se o prazo definido na criação sobrevive à
ativação feita por fora — virou item 1 dos `<gates_desta_fase>`; a sonda deu 422 porque o envelope
de teste estava vazio, limitação do teste e não da API.

## Decisões deixadas à discrição do Claude

- Nome e assinatura do service (o ROADMAP sugere `ContratoClicksignService::iniciarParaEmpresa()`).
- Como a fila é implementada (job único iterando × um job por contrato), desde que respeite o
  espaçamento da D-01.
- Se a checagem de bloqueio reusa `PendenciasComerciaisService::calcular()` ou define regra própria
  — o CONTEXT manda **verificar** se o que ele calcula bate com os 3 bloqueantes antes de duplicar.

## Nada foi redirecionado como scope creep

Todas as quatro áreas estavam dentro do Goal da fase.
