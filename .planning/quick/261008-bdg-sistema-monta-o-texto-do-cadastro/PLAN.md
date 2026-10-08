---
quick_id: 261008-bdg
slug: sistema-monta-o-texto-do-cadastro
type: quick
date: 2026-10-08
autonomous: true
---

# O sistema monta o texto do cadastro, sem depender do modelo propor

Relato do usuário hoje, depois do deploy da quick `261008-txt`: *"a segunda
imagem, que seria a de medidas e pontos fortes, saiu sem nenhum texto. Antes
da modificação estava saindo com texto."*

## O que os dados de produção mostram

Mesmo produto ("mesa escritório", conta 459), dois kits:

| | criativo 34 (07/10 18:58) | criativo 40 (08/10 10:11) |
|---|---|---|
| `badges` | `[]` | `[]` |
| `fatos_usados` | `[]` | `[]` |
| bloco TEXTO | contraditório (cabeçalho + lista vazia) | proíbe texto explicitamente |
| imagem | **saiu com texto** | saiu sem texto |

**Em nenhum dos dois o sistema validou texto.** Ontem o prompt se contradizia,
o modelo ignorou a proibição e escreveu por conta própria — logo **o texto que
o usuário aprovou era inventado pela IA**, não vinha do cadastro. É exatamente
a classe de falha de TRUTH-02/03 (o caso dos "quatro pés"): número convincente
que o cadastro não sustenta, numa imagem coerente consigo mesma, aprovada por
revisão humana.

A quick `261008-txt` não quebrou o texto — tornou visível que ele nunca
funcionou.

## A causa que falta corrigir

`261008-txt` só **rotula o que o modelo propõe** (`rotularSeConfirmado()`).
Quando o LLM do planejamento não propõe badge nenhuma, não há o que rotular —
e ele não está propondo. O produto tem `Width: 120 cm`, `Height: 75 cm`,
`Depth: 50 cm` bem cadastrados, e nada disso chega à imagem.

## Tarefa — preencher o texto a partir do Truth, deterministicamente

Quando o slot aceita texto e o modelo não propôs nada aproveitável, o SISTEMA
monta as badges direto do Product Truth, com o rótulo oficial e o valor exato
do cadastro ("Largura: 120 cm").

- `dimensions`: as medidas **do produto** (nunca de embalagem — a quick
  `261007-ifa` corrigiu isso e não pode ser reaberta).
- demais tipos com texto (`specifications`, `benefits`, `feature_highlight`,
  `package_content`, `how_to_use`): os fatos que sustentam aquele tipo.

⚠️ O número **continua vindo exclusivamente do cadastro**. O modelo não
escolhe, não completa, não arredonda. Se não houver fato, o ramo honesto da
`261008-txt` continua valendo (desenha o leiaute sem texto) — essa parte está
certa e não deve ser desfeita.

⚠️ Escolher QUANTAS badges e QUAIS, com critério registrado: uma imagem com
quinze linhas de texto é tão ruim quanto uma sem nenhuma. O cadastro deste
produto tem 25+ atributos.
