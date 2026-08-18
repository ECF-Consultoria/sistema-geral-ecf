---
criado: 2026-08-18
origem: Fase 132 (cutover Clicksign), plano 132-03 Task 3 — medido em produção
severidade: ALTA — quebra a liberação automática ponta a ponta
area: contratos / clicksign / webhook
---

# O webhook resolve o contrato pelo ENVELOPE, mas o evento traz o DOCUMENTO

## Sintoma medido em produção

Contrato de teste do cutover (empresa 424, contrato 1, envelope `5d2458b6…`) foi ativado e
**três pessoas assinaram**. Os eventos chegaram, com HMAC válido — e **todos foram
descartados**:

```
sign  → status=ignorado → erro_msg: "envelope nao pertence a nenhum contrato deste sistema"
```

`ContratoLiberacao::count()` = 0. A empresa nunca seria liberada.

## Causa exata

O corpo do webhook da Clicksign é **baseado em documento** (`document.key`), não em envelope —
o que o §12.2 do empírico já registrava ("a forma real bate com a primeira forma da doc, não
a JSON:API"). O receiver grava esse `document.key` na coluna `clicksign_envelope_id` do
evento.

A resolução do contrato, porém, compara com a coluna do **envelope**:

- `app/Http/Controllers/Api/ClicksignWebhookController.php:114`
- `app/Jobs/ProcessarEventoClicksignJob.php:124`

```php
ContratoAssinatura::where('clicksign_envelope_id', $evento->clicksign_envelope_id)->first();
```

São namespaces diferentes. Nunca casa.

## Por que o conserto é pequeno

`ContratoAssinatura` **já tem** a coluna `clicksign_document_id`, e ela **já vem preenchida**
com exatamente o id que os eventos trazem. Medido no contrato 1:

```
contrato.clicksign_envelope_id = 5d2458b6-97a7-4485-b27d-98d6b0acd9da
contrato.clicksign_document_id = 2927d917-8e08-4691-ba7b-d53309f88267
evento.clicksign_envelope_id   = 2927d917-8e08-4691-ba7b-d53309f88267   <-- casa por document_id
```

O próprio job já usa `clicksign_document_id` mais adiante (linha 153, ao listar eventos do
documento) — a coluna é conhecida, só não é consultada na resolução.

**Correção:** nos dois pontos, tentar `clicksign_envelope_id` e, se não achar, cair para
`clicksign_document_id`. Manter os dois: o id que chega depende da forma do payload, e a
Fase 129 pode ter medido a outra forma.

## Cuidados ao corrigir

1. **Renomear a coluna do evento seria melhor, mas é migration** — `clicksign_envelope_id` na
   tabela de eventos guarda, na prática, a chave do documento. O nome mente. Avaliar
   `clicksign_ref_id` ou similar, com cuidado de não quebrar o dedup por `payload_hash`.
2. **Reprocessar os eventos já ignorados** depois do fix, para não perder as três assinaturas
   já colhidas: eles estão gravados com `status=ignorado` e o payload íntegro.
3. **Teste que prova:** evento com `document.key` de um contrato existente resolve e libera;
   evento de documento desconhecido continua sendo ignorado sem estourar.

## Efeito colateral descoberto junto

O webhook é **por conta** (§12.9). Depois de apontá-lo para o receiver, **toda a atividade
Clicksign da ECF** passa a chegar aqui — nesta medição vieram eventos de outros 3 documentos
que não são deste sistema. Eles são corretamente ignorados, mas convém confirmar que o
volume não vira ruído nem custo de fila.
