---
quick_id: 260915-fc7
status: complete
date: 2026-09-15
commits: [3903fd90]
---

# Quick 260915-fc7 — Vigência do contrato em meses como variável

**O contrato passa a emitir `{{vigencia_meses}}` ("6 (seis)") a partir das parcelas do
snapshot congelado — mas o valor só aparece no documento depois que o `.docx` de Gestão
na Clicksign trocar o "12 (doze)" fixo da Cláusula 11ª pela variável.**

## O que mudou

- `ContratoPdfService::montarVigencia()` devolve `meses`; regra em `vigenciaEmMeses()`:
  fases do mesmo serviço somam (3 + 9 = 12), entre serviços vale o maior total, qualquer
  fase sem `parcelas` (ou snapshot anterior ao quick 260824-bte) cai em
  `VIGENCIA_MESES_PADRAO = 12` — o mesmo texto que o modelo tinha fixo.
- `ContratoVariaveisModeloService::mapa()` emite `vigencia_meses`.
- 6 testes novos em `ContratoVariaveisModeloTest`.

## Verificação

`Phase126 + Phase127 + Phase131`: **395 testes, 1394 asserções, OK.**

## Pendências (fora do código)

1. Editar o modelo de Gestão na Clicksign: "12 (doze) meses" → `{{vigencia_meses}} meses`.
   Enquanto isso não for feito, a variável é emitida e ignorada (inofensivo, mesma situação
   documentada para `vigencia_inicio`/`vigencia_fim` em `126-VARIAVEIS-DO-MODELO.md`).
2. Deploy — não executado.
3. Quater: contrato já gerado precisa ser cancelado e reenviado depois de 1 e 2. O snapshot
   é congelado na criação; se ele foi criado antes do quick 260824-bte não terá `parcelas` e
   sairá com 12 — conferir no banco antes de reenviar.
4. Renovação automática "por períodos iguais" com 6 meses renova por 6 — validar com o
   jurídico.
