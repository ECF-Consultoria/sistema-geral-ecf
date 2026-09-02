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

## Campos para a Task 2 / Task 3

`property_owner_nome_interno:` NÃO MEDIDO — HTTP 401 (credencial `HUBSPOT_ACCESS_TOKEN` ausente
no `.env` local deste worktree impediu a chamada à Properties API antes de a tabela ser
retornada; nenhuma linha da tabela foi lida)

`escopo_owners_read:` NÃO MEDIDO — mesma causa acima; o escopo do Private App não pôde ser
inferido a partir desta chamada (401 ocorre antes de qualquer verificação de escopo por
property específica)

`medido_por:` tentativa via `hubspot:inspect-properties` em 2026-09-02 — falhou por ausência de
credencial; aguardando decisão humana no checkpoint da Task 2
