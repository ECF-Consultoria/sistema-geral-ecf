---
quick_id: 260824-ot1
slug: assinatura-posicionada
date: 2026-08-24
status: in-progress
---

# Assinatura posicionada embaixo do CNPJ de cada parte

## O pedido

Depois de a rubrica automática funcionar (quick `260824-mv3`), o usuário viu o contrato assinado
e pediu a última peça:

> "só faltou conseguir posicionar duas assinaturas uma do contratante e outra do contratado logo
> abaixo do CNPJ respectivo de cada um na última página"

## O mecanismo (documentação oficial da Clicksign)

[`docs-modelos`](https://developers.clicksign.com/docs/docs-modelos) descreve a tag de
**assinatura manuscrita posicionada**:

```
{{~position_sign_ID}}
```

- `~position_sign_` é prefixo fixo — o identificador técnico que diz ao sistema que aquilo é uma
  âncora de assinatura, não texto
- `ID` é escolhido por quem monta o modelo, para diferenciar signatários
  (exemplos da doc: `{{~position_sign_1}}`, `{{~position_sign_cliente}}`)

A ligação signatário ↔ tag é feita pela API, no campo **`rubric_field`** do requisito de rubrica
([`criar-requisito-de-rubrica`](https://developers.clicksign.com/reference/criar-requisito-de-rubrica)) —
o mesmo campo que hoje sai `NULL` (verificado no envelope real
`2e245d87-88a6-473d-9e4e-8a1af9c74252`).

⚠️ A doc é explícita: é **assinatura manuscrita** (`kind: manuscript`) — o signatário **desenha** a
assinatura ao assinar, em vez de só confirmar. **O usuário foi avisado e aceitou** (2026-08-24).

## O modelo já está pronto

`modelo-contrato-gestao-v4-ASSINATURA-POSICIONADA.docx` (raiz do projeto), gerado e conferido:

```
{{razao_social}}
CNPJ: {{cnpj}}
{{~position_sign_contratante}}      <- tag nova
ECF NEGOCIOS DIGITAIS LTDA
CNPJ: 63.381.851/0001-41 — Thiago Messina, Sócio, pela CONTRATADA
{{~position_sign_contratada}}       <- tag nova
```

281 parágrafos, 2 tabelas, as 10 variáveis normais intactas. **Subir o arquivo na Clicksign é
ação do usuário** — não faz parte deste plano.

## Tarefa 1 — a trava que impede quebrar os outros serviços

⚠️ **O ponto mais importante deste plano.** Só o modelo de **Gestão** tem as tags. Os outros 8
serviços usam o modelo global, **sem** tags. Mandar `rubric_field` apontando para uma tag que não
existe no documento deve ser recusado pela API — e isso quebraria a geração de contrato de todos
os outros serviços.

Então o comportamento novo é **opt-in por serviço**: coluna nova em `servicos`,
`clicksign_assinatura_posicionada`, booleana, **default `false`**.

- migration aditiva e idempotente (`hasColumn` antes de criar)
- entra no `$fillable` e no `logOnly()` do `getActivitylogOptions()` do model `Servico`, junto de
  `clicksign_template_id` (mesma natureza: configuração de contrato por serviço)
- ⚠️ **NÃO** ligar a flag para nenhum serviço na migration. Ligar é passo de produção, feito
  depois do deploy e conferido por reconsulta.

## Tarefa 2 — o requisito de assinatura posicionada

`ClicksignClient`: o requisito de rubrica ganha a variante posicionada.

A referência diz que se informa **ou `pages` ou `rubric_field`** — são alternativas dentro de um
requisito. Então a assinatura posicionada é um requisito **a mais** por signatário, não uma
alteração do que já existe:

| requisito | atributos | quando |
|---|---|---|
| rubrica em todas as páginas (já existe) | `action: rubricate`, `pages: all`, `kind: initials` | sempre |
| assinatura posicionada (novo) | `action: rubricate`, `rubric_field: <id>`, `kind: manuscript` | só quando o serviço opta |

O `<id>` sai do papel do signatário — mapa explícito, no mesmo espírito de
`PAPEL_PARA_CLICKSIGN_ROLE`:

- `PAPEL_CONTRATANTE` → `contratante`
- `PAPEL_CONTRATADA` → `contratada`
- `PAPEL_TESTEMUNHA` → **sem tag** (não existe no modelo; o signatário simplesmente não ganha o
  requisito posicionado)

⚠️ Documentar no docblock que **os IDs do mapa têm que existir como `{{~position_sign_<id>}}` no
`.docx`**. Renomear de um lado sem o outro é o mesmo modo de falha silencioso do T-126-38.

## Tarefa 3 — passar a decisão até o client

O client precisa saber se cria ou não o requisito posicionado. A informação nasce no
`GerarContratoAssinaturaJob` (que já resolve o `$templateId` a partir do `Servico`) e desce até
`montarEnvelopeComum()`.

Escolha a forma mais limpa dentro do que o arquivo já faz — mas **não** faça o client consultar o
banco: ele é um cliente HTTP, recebe o que precisa por parâmetro (mesma disciplina do
`$templateId`).

## Testes

- serviço **com** a flag: cada signatário com papel mapeado ganha **dois** requisitos de rubrica —
  o de `pages: all`/`initials` e o de `rubric_field`/`manuscript` com o id certo
- serviço **sem** a flag (default): comportamento **idêntico** ao de hoje, nenhum `rubric_field`
  enviado — é a regressão que protege os outros 8 serviços
- `PAPEL_TESTEMUNHA` não ganha requisito posicionado nem com a flag ligada
- falha no requisito posicionado → rollback D-12 e exceção **original** propagada
- orçamento de chamadas: atualizar os docblocks que citam o número

## Fora de escopo

- Subir o `.docx` na Clicksign (ação do usuário)
- Ligar a flag para Gestão (passo de produção, pós-deploy)
- Tornar os IDs configuráveis por serviço — mapa fixo basta; se um dia outro modelo usar outros
  ids, aí sim

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⚠️ Migration: SQLite dos testes é mais rígido que o MariaDB em `enum`; aqui é `boolean` simples,
  sem índice — mas mantenha idempotente.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Sem mudança de JSX → não precisa `npm run build`. Comentários em pt-BR. Commits atômicos.
