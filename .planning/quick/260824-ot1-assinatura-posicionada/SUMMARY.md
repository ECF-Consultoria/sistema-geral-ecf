---
quick_id: 260824-ot1
slug: assinatura-posicionada
date: 2026-08-24
status: complete
---

# Assinatura posicionada embaixo do CNPJ de cada parte

## O pedido

Depois de a rubrica automática funcionar (quick `260824-mv3`), o usuário viu o contrato assinado e
pediu a última peça:

> "só faltou conseguir posicionar duas assinaturas uma do contratante e outra do contratado logo
> abaixo do CNPJ respectivo de cada um na última página"

## O mecanismo (documentação oficial)

[`docs-modelos`](https://developers.clicksign.com/docs/docs-modelos) descreve a tag de assinatura
manuscrita posicionada: `{{~position_sign_ID}}`, onde `~position_sign_` é prefixo fixo e o `ID` é
escolhido por quem monta o modelo.

A ligação signatário ↔ tag é feita pela API no campo **`rubric_field`** do requisito de rubrica —
o mesmo campo que vinha `NULL` no envelope real `2e245d87-88a6-473d-9e4e-8a1af9c74252`.

⚠️ É **assinatura manuscrita** (`kind: manuscript`): o signatário **desenha** a assinatura em vez
de só confirmar. O usuário foi avisado e aceitou (2026-08-24).

## O modelo

`modelo-contrato-gestao-v4-ASSINATURA-POSICIONADA.docx` (raiz do projeto), gerado e conferido:

```
{{razao_social}}
CNPJ: {{cnpj}}
{{~position_sign_contratante}}
ECF NEGOCIOS DIGITAIS LTDA
CNPJ: 63.381.851/0001-41 — Thiago Messina, Sócio, pela CONTRATADA
{{~position_sign_contratada}}
```

281 parágrafos, 2 tabelas, as 10 variáveis normais intactas.

## A trava que protege os outros 8 serviços

⚠️ **O ponto central deste quick.** Só o modelo de Gestão tem as tags. Os outros serviços usam o
modelo global, sem elas. Mandar `rubric_field` apontando para tag inexistente deve ser recusado
pela API — o que quebraria a geração de contrato de todo o resto.

Por isso o comportamento é **opt-in por serviço**: coluna `clicksign_assinatura_posicionada` em
`servicos`, boolean, **default `false`**, migration idempotente. **Nenhum serviço é ligado pela
migration** — ligar é passo de produção, conferido por reconsulta.

O teste `servico_sem_a_flag_se_comporta_identico_a_hoje_nenhum_rubric_field_enviado()` é o que
protege produção.

## Implementação

| requisito | atributos | quando |
|---|---|---|
| rubrica em todas as páginas | `action: rubricate`, `pages: all`, `kind: initials` | sempre |
| assinatura posicionada | `action: rubricate`, `rubric_field: <id>`, `kind: manuscript` | só com a flag |

São **dois requisitos** por signatário quando a flag está ligada — a referência diz que se informa
ou `pages` ou `rubric_field`, então o posicionado é adicional, nunca substitui.

Mapa `PAPEL_PARA_POSITION_SIGN_ID`: `contratante → 'contratante'`, `contratada → 'contratada'`.
`testemunha` fica de fora de propósito — a tag não existe no modelo.

⚠️ Docblock registra que esses ids **têm que existir como `{{~position_sign_<id>}}` no `.docx`**.
Renomear de um lado sem o outro é o mesmo modo de falha silencioso do T-126-38.

A decisão nasce no `GerarContratoAssinaturaJob` (que já resolve o `Servico` para o `templateId`) e
desce por parâmetro até `montarEnvelopeComum()`. **O client não consulta banco** — é cliente HTTP,
mesma disciplina do `$templateId`.

Falha no requisito posicionado cai no mesmo `catch`, dispara o rollback D-12 e propaga a exceção
original.

## Testes

- serviço **com** a flag: dois requisitos de rubrica por signatário mapeado
- serviço **sem** a flag: idêntico a hoje, nenhum `rubric_field` (a regressão que protege produção)
- `PAPEL_TESTEMUNHA` nunca ganha requisito posicionado
- falha no posicionado → rollback + exceção original
- regressão end-to-end pelo Job: 18 chamadas sem a flag, 21 com

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**525 testes, 1767 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `9013c9e9` | migration + `Servico` |
| `899a43eb` | `ClicksignClient` |
| `2b8e4bd8` | `GerarContratoAssinaturaJob` |

## Passos que ficam FORA do código

1. **Usuário:** subir `modelo-contrato-gestao-v4-ASSINATURA-POSICIONADA.docx` na Clicksign.
2. **Produção, pós-deploy:** ligar `clicksign_assinatura_posicionada` para o serviço Gestão e
   conferir por reconsulta.

Enquanto (2) não acontecer, nada muda — o default é `false`.
