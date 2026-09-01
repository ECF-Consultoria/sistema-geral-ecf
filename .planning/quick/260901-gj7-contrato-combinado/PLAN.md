---
quick_id: 260901-gj7
slug: contrato-combinado
date: 2026-09-01
status: in-progress
---

# Venda combinada Mercado Livre + Shopee gera UM contrato, não dois

## O pedido

> "caso o negócio for combinado mercado livre e shopee o mesmo contrato deve constar as duas
> plataformas, o modelo seria o modelo-contrato-gestao-ads-mercado-livre"

## O que acontece hoje

`ContratoClicksignService::iniciarParaEmpresa()` faz `groupBy('servico_id')` e cria **um contrato
por serviço**. Uma empresa que fecha Gestão (id 6) + Gestão de ADS Shopee (id 9) receberia **dois
contratos**, cada um nomeando só a sua plataforma.

Nunca aconteceu: **zero empresas** têm contrato de mais de um serviço (medido em 2026-09-01).

## O que já funciona sozinho

`{{plataformas}}` (quick `260825-fn0`) concatena as plataformas **distintas do snapshot**. Basta
os dois serviços caírem no MESMO contrato para a variável imprimir "Mercado Livre e Shopee" —
sem tocar nela.

E o modelo de Gestão **não usa** `{{valor_mensal}}` (confrontado em 2026-08-26), então a soma dos
dois serviços não aparece impressa. Não há decisão de dinheiro neste plano.

## Tarefa 1 — dizer QUAIS serviços andam juntos

Coluna nova em `servicos`: `contrato_junto_com_servico_id`, `nullable`, FK para `servicos`.

Semântica: *"quando este serviço aparecer junto com o serviço X na mesma empresa, os dois
compartilham UM contrato, que pertence a X"*.

- migration aditiva e idempotente
- ⚠️ **FK `nullOnDelete` exige coluna `nullable`** — sem isso o MariaDB recusa com erro 1830 e o
  SQLite dos testes não pega (armadilha já registrada no projeto)
- entra em `$fillable` e no `logOnly()` do `Servico`
- ⚠️ **NÃO** preencher nada na migration. É passo de produção: Shopee (9) → Gestão (6).

O serviço "dono" é quem define o **modelo** da Clicksign e o `servico_id` gravado no
`ContratoAssinatura`.

## Tarefa 2 — o agrupamento

Em `iniciarParaEmpresa()`, a chave do `groupBy` deixa de ser `servico_id` cru e passa a ser o
**serviço dono**: se o serviço tem `contrato_junto_com_servico_id` E o dono também está entre os
serviços ativos da empresa, o item cai no grupo do dono. Caso contrário, grupo próprio.

⚠️ **Shopee sozinho continua tendo contrato próprio**, com o modelo de Shopee — é o caso que
acabou de ser configurado e não pode regredir. O redirecionamento só vale quando o dono está
presente.

⚠️ O snapshot do contrato combinado carrega as fases dos **dois** serviços. A ordenação de fases
do pagamento escalonado (quick `260824-bte`) é **por serviço** — não misturar fases de serviços
diferentes numa ordenação só.

⚠️ Se **qualquer** serviço do grupo tiver ordem de fases ambígua, o grupo inteiro é barrado com
`servicos_duplicados` — gerar metade do contrato é pior que não gerar.

## Tarefa 3 — a lista para de mostrar um contrato fantasma

`ContratoAdminController::index()` também faz `groupBy('servico_id')` (~linha 108). Sem ajuste, o
Shopee apareceria como uma linha **"aguardando administrativo" para sempre** — um contrato que
nunca vai existir, porque está coberto pelo combinado.

Aplicar a mesma regra de dono: os dois serviços viram **uma linha**, a do contrato combinado.

⚠️ Não mudar as chaves do array de linha — a tela e o resumo de 7 contagens (D-04) dependem do
formato.

## Fora de escopo

- Preencher a coluna em produção (passo pós-deploy)
- Qualquer mudança em `{{plataformas}}`, `{{valor_mensal}}` ou nos modelos `.docx`
- Combinações além da que for configurada — o mecanismo é genérico, a configuração é explícita

## Testes

- empresa com Gestão + Shopee → **UM** contrato, `servico_id` = Gestão, snapshot com os serviços
  dos dois, e `{{plataformas}}` = "Mercado Livre e Shopee"
- o contrato combinado usa o **modelo de Gestão** (o do dono)
- **Shopee sozinho → contrato próprio, modelo de Shopee** (a regressão que protege o que acabou de
  entrar em produção)
- **Gestão sozinho → igual a hoje**
- Gestão + Shopee + Mentoria → 2 contratos (o combinado e o de Mentoria)
- fase ambígua em QUALQUER serviço do grupo → grupo inteiro barrado, nada criado
- pagamento escalonado dentro do combinado: fases ordenadas **por serviço**, não misturadas
- a **lista** mostra uma linha só para o par combinado, e o resumo de contagens não conta duas vezes
- serviço isento (Polos) continua fora, mesmo dentro de um grupo

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Comentários em pt-BR. Commits atômicos.
