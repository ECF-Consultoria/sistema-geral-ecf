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

**Como a parte da conta rodou na produção sem deploy (01/10):** `pscp` do
`PublicadorSondar.php` + um `run.php` para `/tmp/<pasta>` da VPS; o `run.php` faz
o boot do app de `/var/www/ecf_admin`, dá `require` no comando e o registra com
`$kernel->registerCommand(new PublicadorSondar())`, depois
`$kernel->call('publicador:sondar', ['--empresa' => '459', '--categorias' => '…', '--saida' => __DIR__.'/saida'])`.
Rodar como **www-data** (`chown -R www-data` na pasta + `su -s /bin/sh www-data -c 'php run.php'`):
log ou cache criado como root deixaria o PHP-FPM sem escrita. Trazer `saida/conta`
de volta com `pscp -r` e apagar a pasta da VPS. Nada em `/var/www` é tocado.

**A conta da #459 é a MGSTOREL (1555596317), loja REAL**, não usuário de teste
do ML — a mesma conta da antiga "Dev 02 Teste" (#356), que em 10/07 era clássica
e hoje é **User Products**. Anúncio criado nela é visível a compradores: título
"Item de teste - Não ofertar", fechar logo depois, e confirmação do usuário antes
de cada `POST /items`.

**Conta de cliente: nunca publicar nem mexer em anúncio** (regra do usuário, 01/10). No
máximo leitura e `validate`, e só com autorização dele para aquilo. A lista de depósitos
(`/users/{id}/stores/search`) traz **endereço e coordenadas** da loja: o `sanitizar()` passou
a remover `location`/`address_line`/`latitude`/`longitude`, e as respostas de conta de
cliente são anonimizadas (id do vendedor, apelido) antes de irem para `tests/fixtures-ml/`.

**Medido em 01/10 nas contas de cliente:** 33 de 33 com token válido são User Products (o
perfil público `GET /users/{id}` NÃO traz `tags` — só o token da conta mostra), e 23 de 33
têm `warehouse_management`. Nessas, `available_quantity` é obrigatório no corpo (sem ele, 369)
mas **ignorado** (aviso 469): o estoque vem dos depósitos.

## 3a. O `/items/validate` devolve 400 mesmo quando só há avisos

Nesta conta sempre vêm dois avisos (`4053 lost_me1_by_user`, `350
mandatory_free_shipping`), e o status é **400**, não 204. "Passou" = nenhuma
causa com `type: error`. Quem testar `status === 204` vai achar que nada nunca
passa. O `lost_me1_by_user`, que em 10/07 bloqueava, hoje é aviso.

Outra armadilha da sondagem: numa conta UP, payload com `title` (ou sem
`family_name`) para no erro de corpo (369 / `body.invalid_fields`) ANTES de
qualquer outra validação — cenário montado sobre a base errada não testa nada.

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

## 5. Teste com `Http::fake` que muda no meio: mude o ESTADO, não o fake

`Http::fake()` acumula, e o primeiro stub que casa vence. Chamar `Http::fake()`
de novo no meio do teste para "o ML agora responde outra coisa" não tem efeito
— e o teste pode PASSAR sem testar nada (aconteceu em 01/10 com "ML fora do ar
usa o schema guardado": verde por engano). Padrão do Publicador: registrar o
fake uma vez, com closures que leem propriedades do teste
(`$this->fontesFora`, `$this->ajusteCategoria`), e mudar a propriedade
(`tests/Feature/Publicador/CamadaMlTest.php`). Conferir com uma mutação que o
teste quebra quando a regra some.
