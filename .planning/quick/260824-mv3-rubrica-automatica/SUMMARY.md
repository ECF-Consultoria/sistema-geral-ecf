---
quick_id: 260824-mv3
slug: rubrica-automatica
date: 2026-08-24
status: complete
---

# O sistema configura a rubrica sozinho, em todo envelope

## O problema

O Administrativo marcava **"Posicionar campos de assinatura e rubrica"** no rascunho da Clicksign
e o posicionamento **não salvava**. Cada tentativa custava um rascunho descartado.

## Diagnóstico — confirmado por contraste

| caminho | posicionar pela UI |
|---|---|
| envelope criado **manualmente** na UI, PDF subido à mão | **funciona** |
| envelope criado **pela nossa API** | **não salva** |

Descartados por medição:

- **Não é conta/plano/permissão** — pela UI manual funciona.
- **Não é o modelo** — o usuário abriu `Modelos → contrato-gestao-novo → Editar modelo`; o editor
  é só de texto, não tem etapa de posicionamento.
- **Não é nada que a gente mande** — `criarEnvelope()` leva só `name`, `deadline_at` e
  `remind_interval`; o envelope nasce rascunho (`ativar: false`).

Nenhuma documentação da Clicksign declara essa limitação, mas o contraste é inequívoco. A
hipótese do usuário estava certa; a minha (posicionar no modelo) foi **refutada** por ele.

## A solução: pedir a rubrica pela API

[`criar-requisito-de-rubrica`](https://developers.clicksign.com/reference/criar-requisito-de-rubrica)
expõe `POST /envelopes/{id}/requirements` com `action: rubricate`, `pages`, `kind`.

O sistema passa a criar esse requisito **para cada signatário**, no momento em que monta o
envelope. Ninguém clica em nada — o que é melhor que o checkbox manual, porque não depende de
alguém lembrar.

- `pages: "all"` — todas as páginas
- `kind: "initials"` — iniciais do signatário

Ambos como constantes de classe (`RUBRICA_PAGES`, `RUBRICA_KIND`), não literais soltos.

⚠️ **Posicionar a ASSINATURA por coordenada não existe na API** — só a rubrica. Decisão do
usuário: a assinatura fica onde a Clicksign põe por padrão, como nos contratos que eles já
assinaram antes. Existe um `rubric_field` ("tag de posicionamento"), mas a sintaxe não é
publicada — não inventar.

## Implementação

- `ClicksignClient::criarRequisitoRubrica()` — irmão de `criarRequisitoQualificacao()` /
  `criarRequisitoAutenticacao()`, mesma delegação ao `criarRequisito()` privado.
- `montarEnvelopeComum()`: passo novo dentro do laço de signatários, depois do requisito de
  autenticação, com o mesmo padrão `$passoAtual` (a string vai na mensagem de erro que o operador
  lê).
- **Falha na rubrica é fatal**, como os demais passos: cai no `catch` existente, dispara o
  rollback D-12 e propaga a exceção **original**. Um contrato sem a rubrica que o jurídico espera
  é pior que um contrato não criado.

## Orçamento de chamadas

O docblock cita a janela medida de 20 chamadas/min por envelope. Com 4 signatários (o máximo
previsto) sobe de **15 para 19**; requisitos por envelope, de 8 para 12. Na configuração atual
(cliente + 1 signatário da ECF) são 10. Docblocks atualizados.

## Testes

- `criarRequisitoRubrica()` — payload (`action`, `pages`, `kind`, relacionamentos)
- `montarEnvelope()` — 1 requisito de rubrica **por signatário**
- **falha na rubrica** → `cancelarEnvelope()` chamado e a exceção 422 **original** propagada (não
  a do cancelamento)
- contagens de sequência ajustadas: 15→19, 14→18, 8→10

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**513 testes, 1726 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `ae44ea3b` | adiciona requisito de rubrica automática ao montar envelope |
| `64f0bb41` | cobre requisito de rubrica e ajusta contagem de chamadas |

## Consequência operacional

Contrato **já gerado** não ganha rubrica retroativa — o envelope carrega os requisitos do momento
da criação. Para valer, apagar o rascunho e gerar de novo.

**E parem de usar o checkbox "Posicionar campos de assinatura e rubrica" no rascunho:** ele não
salva em envelope criado pela API, e agora a rubrica já vem configurada.
