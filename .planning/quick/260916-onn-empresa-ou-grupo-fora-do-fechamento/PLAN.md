---
quick_id: 260916-onn
slug: empresa-ou-grupo-fora-do-fechamento
date: 2026-09-16
type: quick
status: pending
---

# Empresa ou grupo marcado como "fora do fechamento"

## Por que

O fechamento existe para descobrir **em que faixa da tabela progressiva** cada cliente caiu no mês.
Quem **não tem contrato progressivo** não precisa participar. Decisão do usuário em 2026-09-16:

> "Tirar só os não progressivos" — continua todo mundo que tem tabela; saem apenas as empresas que o
> usuário apontar como não progressivas, **caso a caso**.

Casos reais que motivaram (já corrigidos no cadastro, falta só a marcação):

| cliente | situação | o que o usuário pediu |
|---|---|---|
| **Rações Soldera** (empresa #253) | contrato de Brigada, sem tabela progressiva; paga R$ 2.500 só no 4º, 5º e 6º mês | não participa do fechamento |
| **grupo Wenus** (#8, 2 empresas) | valor fixo de R$ 4.000/mês — já gravado como tabela de faixa única | "mantém gravado que paga 4 mil, **mas não participa do fechamento**" |

⚠️ **Hoje não existe marcação nenhuma para isso** (conferido: nenhuma coluna em `companies` nem em
`company_groups`, nenhum código). Trocar o serviço do contrato não resolve: o próprio sistema trata
Brigada (serviço #10) como progressiva, com tabela presumida de 7 faixas.

⛔ **Calendário de pagamento está fora de escopo** (decisão do usuário: "ainda não iremos focar na parte
de pagamentos").

---

## T1 — A marcação

Migration nova, em `companies` **e** em `company_groups`:

- `fora_do_fechamento` boolean, default `false`, not null
- `fora_do_fechamento_motivo` string/text nullable
- `fora_do_fechamento_por` foreignId nullable → `users`, **`->nullable()` antes de `->nullOnDelete()`**
  (MariaDB recusa SET NULL em coluna NOT NULL — erro 1830, já derrubou deploy)
- `fora_do_fechamento_em` timestamp nullable

⚠️ Default `false` = **regressão zero**: ninguém sai de nada até alguém marcar.
⚠️ Nome de índice, se criar algum, abaixo de 64 caracteres (MariaDB 1059).

Nos models: `$fillable`/casts; no `Company::getActivitylogOptions()` acrescentar
`fora_do_fechamento` ao `logOnly` para a trilha registrar. `CompanyGroup`: registrar a trilha no
ponto de gravação (activity log manual se o model não usar `LogsActivity`).

⚠️ `fora_do_fechamento` **não pode** entrar em `CompanyGatilhoContratoObserver::CAMPOS_GATILHO` —
marcar não tem nada a ver com gerar contrato.

---

## T2 — A regra, num lugar só

`app/Services/Fechamento/FechamentoEmpresasDoMes.php` já é o ponto único de "quem entra no mês"
(quick 260915-jpr), usado por `ConsolidarMesFechamento`, `AdminController::fechamento()`,
`AdminController::gerarRelatorioGeral()`, `EnviarRelatorioFechamentoJob` e
`CompararMensalidadeFechamento`. **A marcação entra aqui**, e só aqui:

- empresa com `fora_do_fechamento = true` → **sai**
- empresa cujo grupo **ou o grupo de cobrança acima dele** (`raiz()`, Fase 143) está marcado → **sai**
  (o grupo inteiro deixa de participar; é o caso Wenus)
- estado novo distinto de "fora pela data", para o resumo poder separar os dois motivos
- a regra da data de início continua **idêntica**

⚠️ Carregar o dado sem query por empresa no laço de ~200 (eager loading de `grupo` e do pai).

**Grupo:** se todas as empresas de um grupo saem, a linha do grupo some (o `FechamentoSnapshotWriter`
já poda — **não tocar** nele); confirmar com teste.

**Resumo do `fechamento:consolidar-mes`:** uma linha a mais, no mesmo molde da linha da data de início:
*"Fora do fechamento por decisão: N empresa(s) — nomes (motivo)"*. **Nada some em silêncio.**

**Mês já fechado na tela:** mantém o que está gravado até ser refeito — mesma decisão do 260915-jpr.

---

## T3 — Marcar pela tela

Duas portas, mesma permissão do módulo administrativo de contratos:

- **empresa:** na página administrativa da empresa (`/administrativo/contratos/empresa/{id}`,
  `ContratoDetalhe.jsx` — confirme o arquivo)
- **grupo:** na tela de grupos (`/administrativo/contratos/grupos`, `GruposCobranca.jsx`) e/ou na
  página da tabela do grupo (`TabelaGrupo.jsx`) — escolha **uma** e documente

Cada porta:
- botão/alternância "Não participa do fechamento" com **motivo obrigatório** ao marcar (mín. 10
  caracteres) — desmarcar não exige motivo, mas registra na trilha
- grava `_por` (usuário da sessão, **nunca** id vindo do corpo da requisição) e `_em`
- mostra quando está marcado: quem, quando e motivo
- rota POST/DELETE (ou PATCH) com `abort_unless` de permissão igual às vizinhas, 422 em pt-BR

**Na tela do fechamento** (`resources/js/Pages/Admin/Financeiro.jsx`): uma lista discreta
*"Não participam do fechamento"* com nome, motivo e link para a página onde se desmarca — mesmo molde
do aviso `SemDataInicioAviso` do 260915-jpr. **Prop de página, nunca chave nova nos cinco literais de
linha** de `AdminController::fechamento()` (chave esquecida em um literal cria propriedade fantasma).

⚠️ **Copy sem jargão** (há teste travando termos): nada de "flag", "snapshot", "competência",
"rollup", "raiz", "fora_do_fechamento". Fale "não participa do fechamento".
⚠️ Escala do Tailwind: `px-4.5`, `gap-4.5`, `py-5.5` não existem; conferir CSS com `grep -F`.
⚠️ A tabela de R$ 4.000 do grupo Wenus **continua gravada e visível** — marcar não apaga tabela.

---

## Testes (`tests/Feature/Quick260916/ForaDoFechamento*`)

| caso | espera |
|---|---|
| empresa marcada | sai do fechamento (comando e tela ao vivo) |
| empresa não marcada | entra como hoje (regressão zero) |
| grupo marcado | todas as empresas dele saem; a linha do grupo some ao refazer |
| grupo filho de grupo de cobrança marcado | sai também |
| empresa marcada **e** com início depois do mês | aparece uma vez só, com um motivo |
| resumo do comando | lista quem saiu por decisão, separado de quem saiu pela data |
| marcar sem motivo | 422 |
| marcar como não-admin | 403 |
| marcar grava por/em pela sessão | `_por` = usuário logado, ignorando id no corpo |
| desmarcar | volta a entrar; trilha registrada |
| marcar **não** gera contrato | nenhum `ContratoAssinatura` criado |
| tabela do grupo continua gravada depois de marcar | sim |
| tela do fechamento | lista "Não participam do fechamento" com link |
| copy | sem termos proibidos |
| migration em SQLite | roda (o CHECK/enum do SQLite já pegou fase anterior) |

---

## Travas

⛔ **Não tocar** em `FechamentoFaixaResolver::classificar()`, `FechamentoSnapshotWriter`,
`FechamentoRollupService`. ⛔ **O NPS e o desempenho não sentem nada**: a marcação é só do
fechamento — nada de filtrar carteira, ranking, bônus ou NPS por ela.

⛔ **Sem deploy, sem `.env`, sem VPS, sem plink/pscp, sem alterar dado de produção.** Quem marca a
Soldera e a Wenus em produção é o orquestrador, depois do deploy.

⚠️ Árvore compartilhada com outra sessão. Nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain app/ tests/ resources/ routes/ database/` antes de cada commit.
**Arquivo novo precisa de `git add -- <caminho>` explícito antes do `git commit -- <caminhos>`.**
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` ao final. Comentários, copy e commits em **pt-BR**.

**Gates (nenhum pode regredir, exit capturado antes de qualquer pipe):**
`--filter="Phase137|Phase140|Phase142|Phase143|Quick260915|Quick260916"` e
`--filter="Phase74|Phase110"` — rode ANTES de editar (referência) e ao final.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final para o orquestrador gravar.
