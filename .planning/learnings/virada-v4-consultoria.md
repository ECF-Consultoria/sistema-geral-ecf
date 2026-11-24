# Virada do Sistema ECF V4 — Pipeline Consultoria (24/11/2026)

Leia antes de mexer no webhook do HubSpot, no gatilho de contrato ou em qualquer coisa que diferencie
cliente da Consultoria de cliente dos Polos.

## O que mudou

A partir de 24/11/2026 a entrada de cliente **novo** do Pipeline Consultoria (HubSpot `default`) roda pelo
Sistema ECF V4, mantido pelo Erlon (head de desenvolvimento). Para nada sair em dobro, o V4 faz:
- o contrato pelos modelos da Clicksign;
- a 1ª parcela na Conta Azul;
- o grupo de WhatsApp e as boas-vindas.

O **Polos continua 100% no Admin**: Pipeline - Polos (`894770343`), contratos, conexões ML e avisos da
Clicksign. Infoprodutos/MAG (`894770344`) e Renovações (`958410144`) também não mudaram.

| Chave (`.env`) | Efeito |
|---|---|
| `HUBSPOT_CONSULTORIA_ENTRADA_V4=true` | O webhook ignora deal do Pipeline Consultoria que ainda não virou empresa aqui (`hubspot_eventos.status=ignorado`, `erro_msg` cita o V4). |
| `HUBSPOT_CONSULTORIA_V4_EMPRESAS=495,496,497,518` | Empresas que já existiam aqui sem contrato e que o V4 assumiu. `GatilhoContratoAdministrativoService::avaliar()` devolve `consultoria_v4`, e a geração fica bloqueada em todos os caminhos: botão, observers e webhook. |
| `HUBSPOT_AVANCAR_DEAL_ATIVO=false` | O Admin não move mais card no Pipeline Consultoria (Contrato Enviado/Ganho). Essa chave já existia e só age nesse pipeline. |

Depois de mexer no `.env`: `config:cache` + `queue:restart`. Nunca `cache:clear`.

## Decisões não óbvias

- **A checagem vem DEPOIS da idempotência do deal.** Deal que já virou empresa no Admin antes da virada nunca
  chega nela, e "o que o Admin já mandou continua com o Admin até terminar" vale por construção.
- **O pipeline é lido do deal (`properties.pipeline`), não da etapa.** Etapa de Ganho existe em vários
  pipelines, e filtrar pela etapa pegaria o Polos.
- **A lista de empresas é explícita, não uma regra.** Em 24/11 havia 12 empresas da Consultoria no Admin sem
  contrato, mas só 4 estavam em Contrato Pendente no HubSpot: Full Móveis (496), Pacar Máquinas (497),
  JoyPlanet (495) e Arepresentacoes (518). Foram essas 4 que o Erlon pediu para o V4. As outras 8 (473, 475,
  477, 478, 479, 480, 483, 517) seguem com o Admin.
- **Sem `HUBSPOT_AVANCAR_DEAL_ATIVO`, contrato assinado não move mais o deal para Ganho.** Isso vale também
  para os contratos que o Admin mandou antes da virada. O Comercial move à mão, a pedido do Erlon.
- **O Admin nunca fez 1ª cobrança na Conta Azul** (não há integração) **nem criou grupo ou mandou boas-vindas
  sozinho**: o grupo é item de checklist e a mensagem só é montada para copiar. Não havia o que desligar ali.

## Pendente em 24/11

- Lojas da Consultoria que migraram para o V4 (chaves do Mercado Livre renovadas lá): tirar da leitura do Admin.
  Depende da lista do Erlon.
- `hubspot:reprocess-event` **não** passa por esta checagem, porque é replay manual e deliberado. Não usar em
  deal da Consultoria criado depois da virada.
