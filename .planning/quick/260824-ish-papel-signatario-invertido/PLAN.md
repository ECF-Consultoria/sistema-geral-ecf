---
quick_id: 260824-ish
slug: papel-signatario-invertido
date: 2026-08-24
status: completed
---

# Os papéis de signatário estavam invertidos na Clicksign

## O que o usuário viu (produção, 2026-08-24)

Num contrato de teste da Mons Bike:

> "o Thiago Messina que deveria ser parte está como contratante e o cliente Vitor Scarabeli
> está como parte, deve ser o contrário"

- **Thiago Messina** (ECF, quem presta o serviço) aparece como **Contratante** — errado
- **Vitor Scarabeli** (o cliente, quem contrata) aparece como **Parte** — errado

## Causa: uma suposição que o próprio código marcava como não verificada

`ClicksignClient::PAPEL_PARA_CLICKSIGN_ROLE` (~linha 64) traz no docblock:

> ⚠️ **NÃO MEDIDO** — confirmar no checkpoint humano do plano 126-06.
> `PAPEL_CONTRATADA` → `contractor` (quem presta o serviço — a ECF; "contractor" em inglês
> nomeia quem executa o trabalho sob contrato).

O raciocínio em inglês é defensável, mas a Clicksign **rotula `contractor` como "Contratante"**
na interface em português — o oposto do pretendido. O checkpoint nunca aconteceu; a suposição
foi para produção e só agora foi medida, pelo usuário, olhando o contrato gerado.

## A correção: troca entre dois valores JÁ MEDIDOS

O docblock registra que **só** `sign`, `party` e `contractor` foram medidos no sandbox. A
correção não precisa de um quarto valor (`contractee` e afins **não** foram medidos e mandar um
valor inválido só falha **depois** de o envelope já existir — o modo de falha caro desta
integração).

| papel interno | hoje | passa a ser | como a Clicksign exibe |
|---|---|---|---|
| `PAPEL_CONTRATADA` (ECF) | `contractor` | `party` | Parte |
| `PAPEL_CONTRATANTE` (cliente) | `party` | `contractor` | Contratante |
| `PAPEL_TESTEMUNHA` | `sign` | `sign` (inalterado) | — |

É exatamente o que o usuário descreveu como correto.

## Tarefa 1 — inverter o mapa e fechar o "não medido"

Em `app/Services/Clicksign/ClicksignClient.php`:

1. Trocar os dois valores no `PAPEL_PARA_CLICKSIGN_ROLE`.
2. **Reescrever o docblock.** O aviso `⚠️ NÃO MEDIDO — confirmar no checkpoint humano` deixa de
   valer: agora está medido. Registrar **como** foi medido (usuário conferiu o contrato gerado em
   produção, 2026-08-24, e reportou a inversão), e que a leitura em inglês de "contractor"
   induziu ao erro — a Clicksign rotula `contractor` como **Contratante**, não como quem executa
   o trabalho. Quem ler isso depois precisa entender por que o valor "óbvio" está do outro lado.
3. Manter intacta a guarda que lança `ClicksignException` para papel fora do mapa **antes** de
   qualquer requisição sair.

## Tarefa 2 — os testes que cristalizaram a inversão

Estes fixam o mapa antigo e **precisam** ser atualizados (é o comportamento que estava errado):

- `tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` (~linhas 86-87) — o data provider
  `contratante -> party` / `contratada -> contractor` inverte.

Estes usam `'contractor'` como dado de entrada de fixture/sync e **provavelmente não são sobre o
mapa** — leia antes de mexer, e **só ajuste se o teste realmente depender do papel interno**:

- `tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php` (~54, ~147)
- `tests/Feature/Phase129/ContratoSignatariosSyncTest.php` (~46)
- `tests/Fixtures/ClicksignSandboxFixtures.php` (~180)

⚠️ `ContratoSignatariosSyncService` lê `sign_as` **de volta** da Clicksign para casar signatários.
Se ele usar o mapa para reconhecer quem é quem, a inversão precisa ser coerente nos dois sentidos
— **verifique** e cubra com teste.

## Fora de escopo

Não mexer em `PAPEL_TESTEMUNHA`, na lista de signatários da ECF (`config/services.php`), no
modelo `.docx`, nem em contratos já gerados. Contrato já criado na Clicksign **não** se corrige
sozinho — é preciso apagar o rascunho e gerar de novo (o usuário já sabe disso).

## Testes

- `PAPEL_CONTRATADA` → `party`; `PAPEL_CONTRATANTE` → `contractor`; `PAPEL_TESTEMUNHA` → `sign`.
- Papel fora do mapa continua lançando `ClicksignException` **antes** de qualquer requisição HTTP.
- Se o sync de signatários depender do mapa: casar de volta continua funcionando com os valores
  novos.

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
