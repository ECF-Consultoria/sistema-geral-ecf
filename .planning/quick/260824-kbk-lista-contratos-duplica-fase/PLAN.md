---
quick_id: 260824-kbk
slug: lista-contratos-duplica-fase
date: 2026-08-24
status: done
---

# A lista de contratos duplica a empresa quando o pagamento é escalonado

## O que o usuário viu (produção, 2026-08-24)

> "Inclusive vi que ela está aparecendo duas vezes no sistema"

Medido: existe **um único** registro de empresa (`company_id=435`, Mons Bike). O que duplica é a
**linha na lista** de `/administrativo/contratos`.

## Causa: uma invariante que a consolidação de fases quebrou

`ContratoAdminController::index()` (~linha 91) tem o comentário:

> `// (4) Linhas — uma por par (empresa, serviço que exige contrato).`

Mas o laço percorre `$company->contratosServico`, que desde o quick `260824-bte` tem **uma linha
por FASE**, não por serviço. Com pagamento escalonado (3× R$ 5.500 + 9× R$ 6.000) são dois
`ContratoServico` do mesmo `servico_id`.

Pior: os dois resolvem a **mesma** chave `company_id:servico_id` em `$contratosPorPar`, então as
duas linhas apontam para o **mesmo `ContratoAssinatura`** — o mesmo contrato listado duas vezes,
com o mesmo `contrato_id`.

A invariante do comentário continua correta como intenção; o código é que deixou de cumpri-la.

## Tarefa — uma linha por par (empresa, serviço)

Em `ContratoAdminController::index()`, agrupar `$company->contratosServico` por `servico_id` e
emitir **uma** linha por grupo, tanto no ramo com contrato quanto no ramo `SEM_CONTRATO`.

Cuidados nos campos derivados do `ContratoServico`:

- **`data_vencimento`** (coluna "Término" e a ordenação `vencimento`): hoje sai do
  `ContratoServico` da vez. Com fases, use o **maior valor não-nulo** do grupo — o serviço
  termina quando a última fase termina. Todas nulas → `null` (prazo indeterminado, caso legítimo
  que a tela já trata como "Sem prazo").
- O filtro `exigeContrato()` continua valendo: grupo cujo serviço é isento não entra.
- Não mudar a forma da linha (as chaves do array) — a tela consome esse formato e o resumo de 7
  contagens (D-04) depende dele.

⚠️ **Não** mexer no `show()`, na seção "Datas por serviço" (lá as fases DEVEM aparecer separadas,
uma por fase — é onde a pessoa preenche as datas de cada uma) nem na consolidação em si.

## Testes

- Empresa com **duas fases do mesmo serviço** → **uma** linha na lista, não duas.
- O resumo de contagens por situação **não** conta a empresa duas vezes.
- `data_vencimento` da linha = o maior não-nulo do grupo; todas nulas → `null`.
- Regressão zero: empresa com **serviços diferentes** continua com uma linha por serviço.
- Regressão zero: empresa **sem contrato** (ramo `SEM_CONTRATO`) também deduplica.
- Ordenações `recente` e `vencimento` seguem funcionando.

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo, commitou há pouco em `PerformanceController` e
  `DesempenhoScoreService`): nunca `git add -A` / `git add .` / `git commit -a` / `git stash`.
  Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Provavelmente sem mudança de JSX → só rode `npm run build` se mexer em `.jsx`.
- Comentários em pt-BR. Commits atômicos.
