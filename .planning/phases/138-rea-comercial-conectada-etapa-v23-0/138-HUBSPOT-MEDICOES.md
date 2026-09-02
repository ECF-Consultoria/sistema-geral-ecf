# 138-02 — Medições contra a conta HubSpot real da ECF

**Data:** 2026-09-02
**Medido por (tentativa):** `hubspot:inspect-properties`

## Comando executado

```
C:\xampp\php\php.exe artisan hubspot:inspect-properties --objects=deals
```

**Exit code:** 0 (o comando sempre retorna `self::SUCCESS`, mesmo em falha parcial — ver
docblock de `app/Console/Commands/HubspotInspectProperties.php`, T-111-02: é ferramenta de
diagnóstico, não deve travar scripts. O exit code 0 aqui **não** significa que a medição teve
sucesso — ver saída abaixo).

## Saída literal (stdout capturado)

```
== deals ==
Falha ao consultar propriedades de deals: HTTP 401
```

## Causa raiz confirmada

`.env` deste worktree (`ecf_fluxo_entrada`) **não contém a chave** `HUBSPOT_ACCESS_TOKEN`
(confirmado por `grep -n "HUBSPOT_ACCESS_TOKEN" .env` → sem match, exit 1). `config/services.php`
lê `env('HUBSPOT_ACCESS_TOKEN')` sem default, então `config('services.hubspot.access_token')`
resolve para `null`. A chamada `Http::withToken(null)->get(...)` foi rejeitada pela API do
HubSpot com HTTP 401 antes mesmo de a tabela de properties ser retornada.

Nenhum token foi logado. O header de autorização não aparece nesta saída — o comando, por
desenho (T-111-01), nunca imprime esse header, só o status HTTP.

## Campos — resolvidos pelo checkpoint humano (Task 2 e Task 3, 2026-09-02)

`property_owner_nome_interno:` `hubspot_owner_id`

`escopo_owners_read:` `NÃO CONFIRMADO`

`medido_por:` assumido com autorização explícita do usuário em 2026-09-02 (credencial ausente
no `.env` local; medição real adiada para a VPS)

## Task 2 — resolução do checkpoint (nome interno da property de owner)

O usuário, diante da falha de credencial da Task 1, respondeu literalmente:

> "nesse caso preparar para quando for subir para o sistema e usar a key do hubspot na vps"

Isso corresponde à opção **(b)** oferecida no `how-to-verify` do plano (`138-02-PLAN.md`):
autorização explícita para usar `hubspot_owner_id` como default assumido, com a ciência
declarada de que, se a property real da conta tiver outro nome interno, o payload da API
devolve o campo ausente como `null` e a coluna `companies.hubspot_owner_nome` (a ser escrita no
plano 138-03) nasce sempre vazia — sem erro visível em lugar nenhum. Esse é exatamente o modo de
falha silenciosa do precedente `quick 260805-eqk` documentado no comentário de topo do bloco
`hubspot.props` em `config/services.php`.

O usuário impôs uma condição adicional, não coberta pelas opções literais do plano: a medição
real **não fica dispensada**, fica **preparada para rodar contra a VPS** no momento do deploy
desta fase, quando o `HUBSPOT_ACCESS_TOKEN` de produção existir. Ver seção
"Pendências obrigatórias para a VPS" abaixo — este item é carregado como pendência obrigatória
do plano **138-09** (checkpoint final da fase).

**Nenhum arquivo de código foi tocado por esta resolução** — `hubspot_owner_id` é o nome que o
plano 138-03 vai fixar como default de `env('HUBSPOT_PROP_DEAL_OWNER_ID', 'hubspot_owner_id')`
em `config/services.php`; o override por `.env` (sem mudança de código) é o mecanismo de
correção previsto se a VPS medir outro valor.

## Task 3 — resolução do checkpoint (escopo OAuth `crm.objects.owners.read`)

O usuário respondeu: **"Sem acesso agora"** — não tem acesso ao painel HubSpot
(Settings → Integrations → Private Apps) nesta sessão para conferir a aba de Scopes.

Consequência declarada, conforme o `what-built` do plano: se o escopo `crm.objects.owners.read`
não estiver concedido ao Private App, `GET /crm/v3/owners/{id}` devolve HTTP 403; o
`HubspotApiClient` (resiliente por desenho, T-111-01/T-111-03) devolve `null` em vez de lançar
exceção; e `companies.hubspot_owner_nome` fica **sempre vazio, sem erro visível em lugar
nenhum**, até alguém notar que o campo "responsável comercial" das listagens Contrato e Entrada
nunca preenche.

Este item também é carregado como pendência obrigatória do plano **138-09**, para ser conferido
depois do primeiro deploy — junto com a medição da property acima, ambas contra a conta HubSpot
real via VPS.

## Pendências obrigatórias para a VPS (item do plano 138-09)

Estas duas verificações **não puderam ser feitas contra a conta HubSpot real** nesta sessão
(credencial ausente no `.env` local + sem acesso ao painel HubSpot) e foram assumidas/deixadas
em aberto com autorização explícita do usuário. O plano 138-09 (checkpoint final da fase) DEVE
tratá-las como pendência obrigatória antes de considerar a fase encerrada:

1. **Medir o nome interno real da property de owner na VPS.**
   - Comando literal a rodar na VPS, com o `HUBSPOT_ACCESS_TOKEN` de produção já configurado:
     ```
     php artisan hubspot:inspect-properties --objects=deals
     ```
   - O que conferir na saída: localizar, na tabela impressa, a linha cujo `name` (nome
     INTERNO da property, não o `label`) corresponde à property de owner do deal.
   - Se o `name` devolvido for `hubspot_owner_id`: nada a fazer, o default do config já está
     correto.
   - Se o `name` devolvido for **diferente** de `hubspot_owner_id`: setar
     `HUBSPOT_PROP_DEAL_OWNER_ID=<nome_real>` no `.env` da VPS. O config do plano 138-03 lê o
     valor via `env('HUBSPOT_PROP_DEAL_OWNER_ID', 'hubspot_owner_id')` — o override por `.env`
     é suficiente, **não exige mudança de código nem redeploy**, só `php artisan config:clear`
     (ou reiniciar o queue worker/supervisor se o config estiver cacheado).

2. **Confirmar o escopo `crm.objects.owners.read` no Private App de produção.**
   - Onde conferir: painel HubSpot → Settings → Integrations → Private Apps → o app que gera o
     `HUBSPOT_ACCESS_TOKEN` usado em produção → aba Scopes.
   - Se **não** estiver marcado: marcar, salvar, e verificar se o HubSpot rotacionou o token.
     Se rotacionou, atualizar `HUBSPOT_ACCESS_TOKEN` no `.env` da VPS antes de considerar o
     escopo resolvido (rotação de token sem atualizar o `.env` reintroduz o mesmo 403).
   - Se o escopo não puder ser concedido: `GET /crm/v3/owners/{id}` continuará devolvendo 403 e
     `companies.hubspot_owner_nome` continuará sempre vazio — sem erro visível — até isso ser
     corrigido.
