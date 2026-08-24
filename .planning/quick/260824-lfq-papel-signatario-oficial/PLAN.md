---
quick_id: 260824-lfq
slug: papel-signatario-oficial
date: 2026-08-24
status: in-progress
---

# Os papéis de signatário passam a usar os valores oficiais da Clicksign

## Contexto: a segunda correção do mesmo mapa, agora com fonte autoritativa

O quick `260824-ish` (hoje, mais cedo) já tinha invertido `PAPEL_PARA_CLICKSIGN_ROLE` porque o
usuário viu o prestador rotulado como "Contratante". A correção usou **só os três valores medidos
no sandbox** (`sign`, `party`, `contractor`) e pôs a ECF em `party` → **"Parte"**.

O usuário conferiu de novo e apontou o alvo certo:

> "o thiago messina na verdade é o **Contratada** o papel de signatário"

## A fonte que faltava

A documentação oficial da Clicksign
([`adicionar-requisito-de-qualificacao`](https://developers.clicksign.com/docs/adicionar-requisito-de-qualificacao))
traz a tabela completa do enum `role`. Os valores que interessam:

| valor | rótulo na Clicksign |
|---|---|
| `contractee` | **Contratada** |
| `contractor` | **Contratante** |
| `witness` | **Testemunha** |
| `party` | Parte |
| `sign` | Assinar |

Isso encerra em definitivo o `⚠️ NÃO MEDIDO` que arrastava desde a Fase 126: não é mais inferência
por sentido, é a tabela publicada pelo fornecedor.

## Tarefa 1 — o mapa correto

Em `app/Services/Clicksign/ClicksignClient.php`, `PAPEL_PARA_CLICKSIGN_ROLE`:

| papel interno | hoje | passa a ser | rótulo resultante |
|---|---|---|---|
| `PAPEL_CONTRATADA` (ECF) | `party` | **`contractee`** | Contratada |
| `PAPEL_CONTRATANTE` (cliente) | `contractor` | `contractor` (inalterado) | Contratante |
| `PAPEL_TESTEMUNHA` | `sign` | **`witness`** | Testemunha |

Sobre a testemunha: `sign` é rotulado **"Assinar"**, não "Testemunha". O caminho está adormecido
(a diretoria reduziu os signatários da ECF a uma pessoa só), mas é o mesmo chute da mesma época e
morde quem reativar testemunha depois. Corrigir agora, com a fonte na mão, é mais barato que
descobrir de novo por contrato errado.

## Tarefa 2 — reescrever o docblock com a fonte

O docblock foi reescrito no quick `260824-ish` dizendo "MEDIDO em produção" e explicando a
armadilha do inglês. **Atualizar** para refletir o que se sabe agora:

- a origem é a **tabela oficial** do enum (citar a URL), não mais inferência nem só observação
- registrar as duas correções em sequência e por que a primeira ainda estava incompleta: usar só
  os três valores do sandbox era uma restrição autoimposta que não existia na API — o enum tem
  dezenas de valores, e o certo (`contractee`) simplesmente não tinha sido procurado
- manter e reforçar o aviso sobre `contractor` ≠ "quem executa o trabalho"
- deixar claro que agora **existe** fonte para consultar antes de chutar

## Tarefa 3 — testes

`tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` — o data provider fixa o mapa e precisa
ir para os valores novos (`contratada -> contractee`, `contratante -> contractor`,
`testemunha -> witness`).

Confirmar que a guarda que lança `ClicksignException` para papel fora do mapa, **antes** de
qualquer HTTP, segue intacta e coberta.

⚠️ Verificado no quick `260824-ish` e continua valendo: `ContratoSignatariosSyncService` casa
signatário de volta por `clicksign_signer_key`/`email`, **nunca** por `sign_as`. Os `'contractor'`
em `Phase125`, `Phase129` e `ClicksignSandboxFixtures` são dado bruto de round-trip, não derivação
do papel. **Reconfirme** com uma busca, mas a expectativa é que não precisem mudar.

## Testes

- `PAPEL_CONTRATADA` → `contractee`; `PAPEL_CONTRATANTE` → `contractor`;
  `PAPEL_TESTEMUNHA` → `witness`
- papel fora do mapa continua lançando `ClicksignException` antes de qualquer requisição HTTP

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
