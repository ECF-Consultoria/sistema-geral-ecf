---
quick_id: 260825-c3m
slug: rubric-field-sem-til
date: 2026-08-25
status: in-progress
---

# `rubric_field` tem que ser o nome como ficou no documento GERADO

## O incidente (produção, 2026-08-25 08:37)

Primeira geração com a assinatura posicionada ligada (quick `260824-ot1`). O contrato ficou em
**"Preparando"** e nunca saiu. Log:

```
[Clicksign] rubric_field não encontrado no documento
```

O rollback D-12 funcionou como projetado — envelope cancelado, nada pela metade — mas o contrato
não foi criado.

## A causa, medida

O modelo carrega a tag com til:

```
{{~position_sign_contratante}}
```

Baixei o documento **gerado** pela Clicksign a partir desse modelo e inspecionei:

```
[141] {{position_sign_contratante}}      <- SEM o til
```

**O `~` é consumido na geração.** Ele é o marcador que diz ao motor de modelos "não substitua,
emita literal" — a âncora sobrevive, mas o til não. E o `rubric_field` que mandávamos
(`contratante`) não corresponde a nada no documento final.

## A forma correta, medida contra a API

Sonda no envelope real `20b4c47e-faf8-461c-8c1d-b5a8f7812b69`:

| `rubric_field` enviado | resposta |
|---|---|
| `contratante` | 422 — não encontrado |
| **`position_sign_contratante`** | **201 — aceito** |
| `~position_sign_contratante` | 422 — não encontrado |
| `{{position_sign_contratante}}` | 422 — não encontrado |

**O valor é o nome da tag como ficou no documento gerado: sem chaves e sem til.**

## Tarefa — o prefixo entra no valor enviado

Em `ClicksignClient`, o `rubric_field` passa a levar `position_sign_` + o id do mapa
`PAPEL_PARA_POSITION_SIGN_ID`.

Escolha entre prefixar na montagem do payload ou guardar o nome completo no mapa — o que ficar
mais honesto de ler. Mas **o docblock precisa registrar a cadeia inteira**, porque nada disso é
dedutível do código:

1. o `.docx` carrega `{{~position_sign_<id>}}`
2. a geração pelo modelo **come o til**, deixando `{{position_sign_<id>}}` no documento
3. a API quer o nome **como ficou no documento**: `position_sign_<id>`, sem chaves, sem til
4. os quatro valores testados e o que cada um respondeu (a tabela acima)

Sem esse registro, a próxima pessoa que mexer aqui repete a sonda inteira — ou "simplifica" o
valor de volta para o id e quebra em silêncio, com o contrato parando em "Preparando".

⚠️ Mantenha a regra que já existe: se o serviço não optou pela assinatura posicionada, **nenhum**
`rubric_field` é enviado. É o que protege os outros 8 serviços.

## Testes

- serviço com a flag → `rubric_field` sai como `position_sign_contratante` /
  `position_sign_contratada` (o valor literal, não o id cru)
- serviço sem a flag → nada muda (regressão que protege produção)
- `PAPEL_TESTEMUNHA` continua sem requisito posicionado

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Sem mudança de JSX → não precisa `npm run build`. Comentários em pt-BR. Commits atômicos.
