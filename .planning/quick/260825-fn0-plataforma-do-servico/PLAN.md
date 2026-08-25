---
quick_id: 260825-fn0
slug: plataforma-do-servico
date: 2026-08-25
status: in-progress
---

# A plataforma do contrato sai do serviço, em vez de estar fixa

## O problema

O modelo de Gestão cita **"Mercado Livre e Shopee"** em **11 parágrafos** — objeto do contrato,
escopo, tabela de faturamento, confidencialidade, limitação de responsabilidade.

Nem todo cliente contrata as duas. Relato do usuário (2026-08-25):

> "isso é delicado porque nem todos contratos cliente fecham o serviço de gerirmos as duas
> plataformas"

Um contrato assinado dizendo que a ECF gere uma plataforma que o cliente não contratou é
exposição jurídica, não detalhe cosmético.

## A regra, definida pelo usuário

A plataforma vem do **serviço** atribuído no item de linha do HubSpot:

| serviço | plataforma |
|---|---|
| Gestão de Ads | Mercado Livre |
| Gestão Shopee | Shopee |

⚠️ **Sinalizar ao usuário, fora deste plano:** o serviço no banco chama-se **"Gestão de ADS
Shopee"** (id 9) e o usuário disse ter criado **"Gestão Shopee"** no HubSpot. Se o nome não bater,
o item de linha não casa com o serviço existente. Isso é conferência dele, não código daqui.

## O modelo já está pronto

`modelo-contrato-gestao-v5-PLATAFORMA.docx` (raiz do projeto), gerado e conferido:

- as 11 ocorrências de "Mercado Livre e Shopee" viraram `{{plataformas}}`
- zero sobra de "Mercado Livre" ou "Shopee" no documento
- as tags de assinatura foram para **acima** do nome de cada empresa (o Administrativo disse que é
  onde a assinatura costuma ficar) — antes estavam abaixo do CNPJ
- 281 parágrafos, 2 tabelas, 13 variáveis

**Subir na Clicksign é ação do usuário.**

## Tarefa 1 — de onde vem a plataforma

Coluna nova em `servicos`: `plataforma`, string **nullable**.

- migration aditiva e idempotente (`hasColumn`)
- entra em `$fillable` e no `logOnly()` do `Servico` (mesma natureza de `clicksign_template_id` /
  `clicksign_assinatura_posicionada`)
- ⚠️ **NÃO** preencher nenhum serviço na migration. É passo de produção, pós-deploy, conferido por
  reconsulta.

## Tarefa 2 — a plataforma entra no snapshot

⚠️ D-04: os valores do contrato vêm do **`servicos_snapshot` congelado**, nunca da tabela ao vivo
— é o que garante que o contrato reflita o que foi contratado, mesmo que o cadastro mude depois.
Então a plataforma tem que entrar no snapshot no momento em que o contrato é criado
(`ContratoClicksignService::iniciarParaEmpresa()`), ao lado de `servico`, `valor_contratado` e
`parcelas`.

Contratos **já existentes** não têm essa chave no snapshot — a leitura precisa tolerar a ausência
(ver Tarefa 3), sem migração de dado.

## Tarefa 3 — a variável `{{plataformas}}`

`ContratoPdfService::montarDados()` expõe a plataforma; `ContratoVariaveisModeloService::mapa()`
ganha a chave `plataformas` lendo o que já veio pronto.

⚠️ `ContratoVariaveisModeloService` é **PURA** (T-126-40): não consulta banco, `Http`, `Log`,
`Cache` nem `Storage`. Quem resolve é o `montarDados()`.

**Ausência é visível, nunca silenciosa.** Use o padrão que já existe no arquivo —
`resolverOuPendente()`, que devolve o placeholder `A DEFINIR` e registra o campo em
`campos_pendentes`. Serviço sem plataforma configurada (ou contrato antigo, sem a chave no
snapshot) imprime `A DEFINIR` no contrato e aparece como pendência — **jamais** um espaço em
branco. Já perdemos uma rodada de teste com variável saindo vazia sem aviso (`plano_parcelas`,
quick `260821-m9h`); não repetir.

**Vários serviços num envelope** (D-19, `servico_contratado` concatena): `plataformas` deve
concatenar as plataformas **distintas**, no mesmo estilo de `concatenarServicos()` — vírgula e
" e " antes da última. Duas linhas do mesmo serviço (pagamento escalonado) têm a mesma plataforma
e devem aparecer **uma vez só**.

## Testes

- serviço com `plataforma = 'Mercado Livre'` → variável sai `Mercado Livre`
- serviço sem plataforma → `A DEFINIR` **e** o campo entra em `campos_pendentes`
- contrato antigo, cujo snapshot não tem a chave → mesmo comportamento, sem erro
- duas fases do mesmo serviço → plataforma aparece **uma vez**
- dois serviços de plataformas diferentes → concatenação com " e "
- `ContratoVariaveisModeloService` continua pura (sem DB/Http/Log/Cache/Storage)
- regressão zero nas outras variáveis

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Fora de escopo

- Subir o `.docx` v5 (ação do usuário)
- Preencher `plataforma` dos serviços (passo de produção, pós-deploy)
- Conferir o nome do produto no HubSpot vs. o nome do serviço no banco

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Sem mudança de JSX → não precisa `npm run build`. Comentários em pt-BR. Commits atômicos.
