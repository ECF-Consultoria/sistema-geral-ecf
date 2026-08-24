---
quick_id: 260824-lfq
slug: papel-signatario-oficial
date: 2026-08-24
status: complete
---

# Os papéis de signatário passaram a usar os valores oficiais da Clicksign

## A segunda correção do mesmo mapa, no mesmo dia

O quick `260824-ish` já tinha invertido `PAPEL_PARA_CLICKSIGN_ROLE` porque o usuário viu o
prestador rotulado como "Contratante". Mas aquela correção **se restringiu aos três valores
medidos no sandbox** (`sign`, `party`, `contractor`) e pôs a ECF em `party` → **"Parte"**.

O usuário conferiu de novo:

> "o thiago messina na verdade é o **Contratada** o papel de signatário"

**A restrição aos três valores era autoimposta e nunca existiu na API.** O enum real tem dezenas
de valores; o certo simplesmente não tinha sido procurado.

## A fonte que faltava desde a Fase 126

A tabela oficial do enum `role` está publicada em
[`adicionar-requisito-de-qualificacao`](https://developers.clicksign.com/docs/adicionar-requisito-de-qualificacao):

| valor | rótulo na Clicksign |
|---|---|
| `contractee` | **Contratada** |
| `contractor` | **Contratante** |
| `witness` | **Testemunha** |
| `party` | Parte |
| `sign` | Assinar |

Isso encerra o `⚠️ NÃO MEDIDO` que arrastava desde a Fase 126 — não é mais inferência por sentido
nem observação de um contrato: é a tabela do fornecedor.

## O mapa final

| papel interno | antes deste quick | agora | rótulo |
|---|---|---|---|
| `PAPEL_CONTRATADA` (ECF) | `party` | **`contractee`** | Contratada |
| `PAPEL_CONTRATANTE` (cliente) | `contractor` | `contractor` | Contratante |
| `PAPEL_TESTEMUNHA` | `sign` | **`witness`** | Testemunha |

`PAPEL_TESTEMUNHA` não foi pedido pelo usuário: `sign` é rotulado **"Assinar"**, não "Testemunha".
O caminho está adormecido (a diretoria reduziu os signatários da ECF a uma pessoa só), mas é o
mesmo chute da mesma época e morderia quem reativasse testemunha depois. Com a tabela na mão,
corrigir agora custou uma linha.

## Confirmado, não assumido

- `ContratoSignatariosSyncService::localizarSignatario()` casa por `clicksign_signer_key` e, na
  ausência, por `email` — **nunca** por `role`/`sign_as`. Não depende do mapa.
- Os `'contractor'` em `Phase125`, `Phase129` e `ClicksignSandboxFixtures` são o campo `sign_as`
  de payload de **webhook simulado** (dado bruto de round-trip), não derivação do mapa. Nenhum
  precisou mudar.

## Testes

`tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` — data provider nos valores novos. A
guarda que lança `ClicksignException` para papel fora do mapa, antes de qualquer HTTP, segue
intacta e coberta.

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**510 testes, 1704 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `a903fefe` | corrige mapa de papel de signatário com a tabela oficial da API |

## Consequência operacional

Contrato **já gerado** na Clicksign carrega os papéis do momento da criação — não se corrige
sozinho. Para valer, apagar o rascunho e gerar de novo.

## A lição que vale além deste mapa

O código carregava `⚠️ NÃO MEDIDO` desde a Fase 126, com um checkpoint humano previsto que nunca
aconteceu — e a suposição foi para produção. Quando finalmente foi corrigida, a primeira tentativa
ainda ficou presa ao vocabulário que já estava no arquivo, em vez de procurar a fonte. **Duas
rodadas de contrato errado para o usuário.** Havia documentação pública o tempo todo.
