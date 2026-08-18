---
criado: 2026-08-18
origem: Fase 132, gate #10 — observado, NÃO confirmado
severidade: media — pode ser comportamento esperado
area: contratos / operacional
---

# A liberação criou o registro, mas a empresa não apareceu no operacional

## O que foi observado

No gate #10 da Fase 132, `clicksign:reconciliar` liberou o contrato de teste corretamente:

```
ContratoLiberacao id=1  via=reconciliacao  company_id=424  servico_id=6
contrato 1: aguardando_assinaturas -> assinado
```

Mas `MlbEmpresa::where('company_id', 424)->exists()` continua **false**, e não há erro nos
logs.

## Por que provavelmente é esperado

A empresa 424 é fictícia (`TESTE CUTOVER 132 - NAO E CLIENTE`), sem loja no Mercado Livre e
sem `adman_account_id`/`ml_store_id`. É plausível que `EmpresaOperacionalRouter::rotear()`
não tenha o que rotear e saia sem criar ficha — comportamento correto, não falha.

## Por que ainda assim está registrado

**Não foi confirmado.** Ninguém leu o caminho de `liberarEmpresa()` até o fim para dizer se a
ausência de ficha é decisão deliberada ou no-op silencioso. Afirmar "está tudo certo" sem
essa leitura seria suposição.

## O que fazer

Conferir com a **primeira empresa real** que passar pelo fluxo completo: contrato assinado →
liberação → ficha operacional criada. Se a ficha não nascer para uma empresa com dados
completos, aí é bug e vira prioridade — é o passo que a Fase 133 vai depender.

Alternativa mais barata: ler `EmpresaOperacionalRouter::rotear()` e confirmar por código qual
é a condição de criação da ficha.
