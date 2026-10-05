---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 05
subsystem: publicador
tags: [alavancas, publicidade, cupons, panorama, sondagem, guarda-de-fonte]
requires: [166-02, 166-04]
provides:
  - PublicidadeLeitura (anunciante, janela, campanhas, adGroups, bonificacoes, resumo)
  - CuponsLeitura (cupons)
  - PanoramaService (montar)
  - publicador:sondar-alavancas
  - SemEndpointLegadoTest ampliado (PHP e JSX, /advertising barrado nas ações e no escritor)
affects: [166-06, 166-07, 166-08, 166-09]
key-files:
  created:
    - app/Services/Publicador/Alavancas/PublicidadeLeitura.php
    - app/Services/Publicador/Alavancas/CuponsLeitura.php
    - app/Services/Publicador/Alavancas/PanoramaService.php
    - app/Console/Commands/PublicadorSondarAlavancas.php
    - tests/Feature/Publicador/Alavancas/PublicidadeLeituraTest.php
    - tests/Feature/Publicador/Alavancas/CuponsLeituraTest.php
    - tests/Feature/Publicador/Alavancas/PanoramaTest.php
    - tests/Feature/Publicador/Alavancas/SondarAlavancasCommandTest.php
    - tests/fixtures-ml/alavancas/doc/publicidade/ (advertisers, campaigns_search, ad_groups_search, bonifications)
    - tests/fixtures-ml/alavancas/doc/cupons/promotion_coupon.json
  modified:
    - tests/Unit/Publicador/Alavancas/SemEndpointLegadoTest.php
    - app/Services/Publicador/Alavancas/PromocoesLeitura.php
metrics:
  completed: 2026-10-04
  tasks: 3
---

# Fase 166 Plano 05: Publicidade, cupons, panorama e sondagem Summary

Publicidade só leitura pelos caminhos vigentes da documentação (campanhas e Ad Groups com `api-version: 2`, anunciante com `Api-Version: 1`, bonificações), cupons do vendedor com saldo, panorama da conta com cada fonte isolada e o comando `publicador:sondar-alavancas` (só GET, só conta liberada), tudo protegido por uma guarda de fonte contra endpoints desligados.

## Commits

| Task | Descrição |
|---|---|
| 1 | `feat(166-05): publicidade só leitura pelos endpoints atuais` |
| 2 | `feat(166-05): leitura de cupons e panorama das Alavancas` |
| 3 | `feat(166-05): sondagem só leitura das Alavancas para a conta de teste` |

(Hashes: `git log --oneline -4`.)

## Releitura da doc (04/10/2026, `curl` só em developers.mercadolivre.com.br)

- `bonificacoes-para-product-ads`: o GET `/advertising/advertisers/bonifications` pede SÓ `Authorization` — nenhum parâmetro, nenhum cabeçalho de versão. A lista vem na chave `bonification` (singular). Campos: `status` (ACTIVE), `creation_date`, `end_date`, `campaign_name`, `currency_id`, `level` (Campaign/Account), `amount`, `balance`, `days_remaining`, `campaign_id` (0 no nível conta), `campaign_status`, `benefit_name` (certification, seller-startup-program, smart_benefit, manual). O saldo total soma só as `ACTIVE`.
- `product-ads-para-catalogo-e-user-products-leitura`: anunciante `?product_id=PADS`; campanhas e Ad Groups com `api-version: 2`; `filters[item_ids]` (plural) e o singular não é mais aceito; `metrics_summary` exige `metrics` e datas. A campanha da doc não traz `daily_budget` (só `budget`): `orcamento_diario` sai nulo quando ausente.
- `cupons-do-vendedor`: o detalhe traz `remaining_budget`, `used_coupons`, `budget`, `sub_type`, `fixed_amount`/`fixed_percentage`, `min_purchase_amount`, `max_purchase_amount`, `coupon_code`.
- Origem das fixtures: as 5 novas estão como `"origem": "doc-oficial"` (com a URL), com ids sintéticos e métricas preenchidas para os testes.

## Decisões

- `PromocoesLeitura::promocao()` ganhou o parâmetro opcional `$atualizar` (retrocompatível) para o panorama refazer também o detalhe do cupom. Os 15 testes de `PromocoesLeituraTest` seguem verdes.
- "Cupom ativo" no panorama = `status === 'started'`. "Convite aberto" = tipo em `CONVITES_DO_ML` com prazo de hoje em diante (ou, sem prazo, status fora de finished/closed/cancelled/deleted). Os dois critérios são interpretação minha do plano; mudar é uma linha em `PanoramaService`.
- 403 e 404 "No permissions found" em qualquer leitura de publicidade viram `indisponivel` (texto explicativo) em vez de exceção; qualquer outro erro lança e o panorama o isola.
- O `resumo` de publicidade olha os últimos 30 dias (hoje incluído) e guarda só sucesso em `cache.panorama`.
- Painel de conta: `atacado.business` sai da mesma leitura de `/users/me`; se ela falha, `atacado` vira erro junto.
- `SemEndpointLegadoTest`: a varredura de PHP usa `token_get_all` descartando comentários e docblocks; a de JSX tira `/* */` e `//` (sem poupar `://`). Padrões: `/prices/standard/quantity`, `standard/quantity` (cobertura do 166-09 mantida, também num teste próprio), `product_ads/items`, `product_ads/ads/search`, `/marketplace/advertising`, `/advertising/advertisers/<id>/product_ads`. Segunda varredura barra `/advertising` em `Acoes/**` e `EscritorAlavancas.php`. Casos de controle: strings em PHP, JSX, arquivo temporário e comentário ignorado. Arquivos das próximas ondas (controllers `MlbAlavancas*`, job de lote, JSX) entram sozinhos.

## Roteiro da sondagem na VPS (learnings §3, SEM deploy)

O comando NÃO bloqueia a fase; o usuário roda quando quiser. Ele recusa conta fora de `publicador.alavancas.contas_liberadas.companies` (padrão 459) e qualquer verbo diferente de GET.

1. Copiar `app/Console/Commands/PublicadorSondarAlavancas.php` para uma pasta de `/tmp` na VPS (o arquivo é autocontido; usa `PublicadorSondar::sanitizar` e `CAMPOS_USUARIO`, que já estão em produção desde a Fase 0).
2. Rodar pelo runner do learnings §3 (como `www-data`, carregando o app de produção e registrando o comando), por exemplo `php artisan publicador:sondar-alavancas --empresa=459 --itens=MLB...,MLB... --saida=/tmp/sondagem-alavancas` (`--itens` é opcional, até 5; sem ele o comando pega os 5 primeiros anúncios ativos).
3. Trazer a pasta `/tmp/sondagem-alavancas` para `tests/fixtures-ml/alavancas/sondagem/`. Os arquivos já saem sem token, sem cabeçalho e sem dado pessoal (`sanitizar` + `CAMPOS_USUARIO`).
4. O comando imprime qual arquivo responde a cada suposição: A1 `itens/<MLB>_precos.json`; A3 `promocoes/<id>_itens.json`; A6 `publicidade/*.json`; A9 `promocoes/convites.json`; Q1 `promocoes/<id>_itens_candidatos.json`.

Não foi executado contra conta real aqui (só `Http::fake`).

## Testes

- Novos: PublicidadeLeitura 12 (com data provider), SemEndpointLegado 7, CuponsLeitura 4, Panorama 7, SondarAlavancasCommand 6.
- `tests/Unit/Publicador`: 229 testes, 708 asserções, exit 0 (antes: 211).
- `tests/Feature/Publicador`: 361 testes, 2172 asserções, exit 0 (antes: 307). Sem falha nova.

## Deviations from Plan

None - plano executado como escrito. Observações (não são desvios de regra):
- A mensagem dos commits segue `feat(166-05): ...` (pedido do orquestrador) em vez de `feat(166): ...` do texto do plano.
- Um teste do comando precisou usar um único `Http::fake` (o fake acumula e o 1º stub vence); os cabeçalhos são registrados na closure do `setUp`.
- Primeiro heredoc de criação de arquivo no Bash falhou por sintaxe do shell; os arquivos foram refeitos com Write, sem efeito no resultado.

## Known Stubs

Nenhum.

## Notas

Nenhuma chamada de rede à API do ML (a releitura da doc foi só nas páginas de developers.mercadolivre.com.br); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED
