# Publicador ML (Anunciar do portal) — o que não se deduz do código

Leitura recomendada antes de mexer no Publicador novo do Anunciar
(`.planning/publicador-ml-spec/`). Começado em 01/10/2026, prazo 20/10/2026.
O plano e as decisões estão em `.planning/publicador-ml-spec/16-analise-do-portal.md`.

## 1. A especificação é a fonte; o código antigo é o que se corrige

A spec (`00`–`15`) foi escrita sem olhar o código, de propósito. Quando o
código do Anunciar antigo (`EstruturaPublicacaoService`, ADR PORTAL-03) ou do
`/mlb/anuncios` contradiz uma regra [ML] ou [ARQ], o código está errado — a
lista de violações está no `16` §3. Hipótese [HIP] nunca vira regra fixa:
vai para `config/publicador.php`.

## 2. `tests/Fixtures` × `tests/fixtures` — o git grava a grafia errada no Windows

O repositório tem as duas pastas (`tests/Fixtures/ClicksignSandboxFixtures.php`
e `tests/fixtures/phase134/`). No Windows (`core.ignorecase=true`) elas são a
MESMA pasta física, e um `git add tests/fixtures/x.json` grava
`tests/Fixtures/x.json` — a primeira grafia que o índice conhece. No Linux da
VPS o arquivo fica em outra pasta e o teste que lê `tests/fixtures/...` não o
acha. Por isso as fixtures do Publicador vivem em `tests/fixtures-ml/`, pasta
sem homônima. Arquivo novo em `tests/fixtures/`: conferir com
`git ls-files --stage | grep -i <nome>` a grafia gravada.

## 3. A sondagem (Fase 0) roda em duas máquinas

`php artisan publicador:sondar` só lê e só valida (recusa `POST /items`).
- `--publico`: categorias, atributos, `technical_specs`, `sale_terms`, tarifas —
  app token, roda no local.
- `--empresa=459`: dados da conta (tags, envios, `validate`) — o token é cifrado
  com a APP_KEY de produção, então só roda lá. A conta de teste definida pelo
  usuário é a empresa **#459 "Dev 02 Testes API"**.

As fixtures vão para o git: o comando tira dado pessoal (`sanitizar()`) e nunca
grava token (só vai no cabeçalho). Não afrouxe isso.

## 4. O que a API real mostrou e a documentação não dizia (01/10/2026)

Detalhes em `12-hipoteses-e-pendencias.md` §Resultado. Os que mais mudam código:
- `technical_specs/input` traz TODOS os atributos, agrupados; em `/attributes`
  todos são `attribute_group_id = OTHERS`. Tags: lista no `technical_specs`,
  objeto no `/attributes` — normalizar os dois.
- "Aceita valor livre?" = `ui_config.allow_custom_value` do componente.
- `settings.shipping_modes` não existe no MLB (vem `null`).
- `max_variations_allowed` da categoria (100) diverge do domínio (250) na camiseta.
- Garantia: `sale_terms` existe; `WARRANTY_TYPE` 2230280 vendedor / 2230279
  fábrica / 6150835 sem garantia.
- Autopeças: `GTIN` é `read_only` e `VEHICLE_TYPE` é `required` + `fixed` —
  `fixed`/`read_only` vencem `required`.
