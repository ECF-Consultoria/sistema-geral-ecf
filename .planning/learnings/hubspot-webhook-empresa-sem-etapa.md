# Webhook do HubSpot: empresa criada mas fora da Entrada (incidente de 30/10/2026)

Leia antes de responder "fechei o negócio no HubSpot e não foi para o sistema" ou de mexer em
`Api/HubspotWebhookController`, `HUBSPOT_WEBHOOK_USER_ID` ou na tela Comercial → Entrada.

## O que aconteceu

- Sintoma relatado: "ganho no HubSpot não está mais indo para o sistema". Coincidiu com o usuário
  perder permissões na conta dele do HubSpot, o que **não tinha relação nenhuma**: a integração usa o
  token do Private App (`HUBSPOT_ACCESS_TOKEN`), que é do app e não de uma pessoa.
- Causa real: `HUBSPOT_WEBHOOK_USER_ID` **vazio no `.env` da VPS** desde o deploy da Fase 151.
  Era o passo 1 do roteiro de VPS em
  `.planning/phases/151-rea-comercial-conectada-etapa-v23-0/151-CONTA-SISTEMA-HUBSPOT.md`, e ninguém
  rodou. A conta "Sistema HubSpot" nem existia em produção.
- Efeito: `nascerNaEtapa1()` não acha o ator, grava só um `Log::error` no canal `ecf-webhooks` e
  retorna. A Company nasce com `etapa = NULL`, e a Entrada (`ComercialEntradaController`,
  `whereIn('etapa', [etapas 1..4])`) não mostra empresa com etapa NULL. Ela também não aparecia
  na busca do `/companies` (o recorte de Performance a escondia). A empresa existia, mas nenhuma
  tela mostrava, e nenhum erro aparecia em lugar nenhum.
- As empresas de setembro (451, 452, 454) têm etapa por outro caminho. A partir de 16/10
  **todas** nasceram sem etapa, e daí veio o "não está indo **mais**".

## Correção aplicada (30/10/2026)

1. `hubspot:criar-usuario-sistema --apply` na VPS deu **users.id = 41**. `HUBSPOT_WEBHOOK_USER_ID=41`,
   seguido de `config:cache` e `queue:restart` (nunca `cache:clear`, que já derrubou produção).
2. Empresas 460, 464, 468, 469, 470, 471, 472, 473 e 474 levadas para `aguardando_administrativo`
   via `EtapaTransicaoService::transicionar()` com a conta 41 como ator. É o mesmo caminho do
   webhook, então ficou registrado em `company_etapa_transicoes`. Conferido por reconsulta ao banco.

## Como diagnosticar da próxima vez (5 minutos)

`hubspot_eventos` grava **toda** chamada do HubSpot e responde em qual elo quebrou:

| O que aparece em `hubspot_eventos` | Elo quebrado |
|---|---|
| Nenhum evento recente | HubSpot parou de mandar (assinatura do webhook / app) |
| `signature_valid = 0` | `HUBSPOT_CLIENT_SECRET` rotacionado |
| `status = erro` com 401/403 | Token do Private App revogado ou sem escopo |
| `status = ignorado` com `property_value` de ganho fora do CSV | Pipeline/etapa nova, atualizar `HUBSPOT_STAGE_FECHADO_GANHO_ID` |
| `status = processado` e `company_id_criada` preenchido | O HubSpot está OK; conferir a **etapa** da company |

E conferir `config('services.hubspot.webhook_user_id')` antes de suspeitar do HubSpot.

## Armadilhas

- **O `.env` recebeu `<id>` literal** quando o comando foi copiado de uma instrução com
  placeholder. O script de transição abortou corretamente pela guarda `User::find() === null`.
  Ao passar comandos para o usuário, mande o valor real, não um placeholder.
- **`hubspot:criar-usuario-sistema` grava um `.md`** em `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/`,
  caminho que não existe (a fase foi renumerada para 151) e que não existe na VPS. A conta é criada
  **antes** da escrita, então um erro de `file_put_contents` depois do id impresso não significa falha.
  Rodar de novo é idempotente e só mostra o id existente.
- **Empresa apagada É recriada no próximo Ganho, e isso não é bug.** Lendo só o controller, parece que
  o guarda de idempotência (`company_id_criada NOT NULL` do mesmo deal) bloquearia para sempre. Não
  bloqueia: `hubspot_eventos.company_id_criada` tem FK com `ON DELETE SET NULL` (conferido em
  produção em 30/10: `hubspot_eventos_company_id_criada_foreign`, InnoDB, zero eventos órfãos) e
  `Company` não usa SoftDeletes. Apagar a empresa zera o vínculo e libera o deal. Atenção: se um
  dia `Company` ganhar SoftDeletes, essa garantia **some** (o SET NULL não dispara em soft delete) e
  o guarda precisa passar a checar se a empresa existe.
- Mover o deal para Ganho de novo **não** reprocessa nada: a Mariano Design (deal `66036878083`)
  foi para Ganho 3 vezes e só a primeira criou a empresa (473), o que é correto.
- Gravação na VPS via `plink` é bloqueada pelo classificador (`Remote Shell Writes`). A leitura passa.
  Para gravar, entregar os comandos ao usuário.
