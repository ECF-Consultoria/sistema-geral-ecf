---
quick_id: 260825-c3m
slug: rubric-field-sem-til
date: 2026-08-25
status: complete
---

# `rubric_field` tem que ser o nome como ficou no documento GERADO

## O incidente (produção, 2026-08-25 08:37)

Primeira geração com a assinatura posicionada ligada (quick `260824-ot1`). O contrato ficou em
**"Preparando"** e nunca saiu:

```
[Clicksign] rubric_field não encontrado no documento
```

O rollback D-12 funcionou como projetado — envelope cancelado, nada pela metade. Mas o contrato
não nasceu, e a tela não dizia o porquê.

## A causa, medida

O modelo carrega a tag com til:

```
{{~position_sign_contratante}}
```

Baixei o documento **gerado** pela Clicksign a partir desse modelo (via
`GET /envelopes/{id}/documents` → link do arquivo original) e inspecionei o `word/document.xml`:

```
[141] {{position_sign_contratante}}      <- SEM o til
```

**O `~` é consumido na geração.** Ele é o marcador que diz ao motor de modelos "não substitua,
emita literal" — a âncora sobrevive, o til não. E o `rubric_field` que mandávamos (`contratante`)
não correspondia a nada no documento final.

## A forma correta, medida contra a API

Sonda no envelope real `20b4c47e-faf8-461c-8c1d-b5a8f7812b69`:

| `rubric_field` enviado | resposta |
|---|---|
| `contratante` | 422 — não encontrado |
| **`position_sign_contratante`** | **201 — aceito** |
| `~position_sign_contratante` | 422 — não encontrado |
| `{{position_sign_contratante}}` | 422 — não encontrado |

O valor é o nome da tag **como ficou no documento gerado**: sem chaves, sem til.

## A correção

`PAPEL_PARA_POSITION_SIGN_ID` passou a guardar o `rubric_field` **completo**, não o id cru:

```php
PAPEL_CONTRATANTE => 'position_sign_contratante',
PAPEL_CONTRATADA  => 'position_sign_contratada',
```

O valor sai do mapa exatamente como vai para a API — sem transformação escondida em quem chama.

O docblock registra a cadeia inteira (modelo → geração come o til → API quer o nome do documento)
e a tabela das quatro sondas, com aviso explícito contra "simplificar" de volta ao id cru. **Foi
exatamente essa simplificação que causou o incidente**, e o modo de falha é silencioso: contrato
parado em "Preparando", sem erro na tela.

A regra que protege os outros 8 serviços não mudou: sem a flag do serviço, nenhum `rubric_field`
sai em requisição nenhuma.

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**525 testes, 1767 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `8a0b5c4c` | `rubric_field` da assinatura posicionada leva o prefixo `position_sign_` |

## O que este episódio ensina

A tag de posicionamento foi implementada a partir da documentação, que descreve
`{{~position_sign_ID}}` e diz que o `ID` é "o valor definido pelo usuário". Era razoável concluir
que `rubric_field` levava esse id. **A documentação não menciona que a geração por modelo consome
o til** — e sem baixar o documento gerado e olhar dentro, não havia como saber.

O que resolveu não foi ler melhor: foi **baixar o artefato real e inspecionar**, e depois sondar a
API com as quatro variantes.
