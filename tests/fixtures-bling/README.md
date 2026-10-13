# Fixtures do Bling ERP

**Procedência:** todos os payloads deste diretório foram **copiados da documentação pública da
API v3 do Bling**, destilada em `.planning/phases/184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente/184-RESEARCH.md`
(seção 2) e em `.planning/seeds/261010-integracao-bling-erp.md` (seção 4 e 6). Nenhum deles veio de
uma conta de cliente real.

**Proibição:** nunca substituir um destes arquivos por um payload capturado de uma conta real de
cliente, nem em debug, nem em auditoria de bug. Payload de cliente é dado de terceiro — versionar
isso no repositório é vazamento de dado (T-184-09 do `184-02-PLAN.md`). Se for preciso reproduzir um
bug com dado real, anonimizar primeiro (como `tests/fixtures-ml/` já faz para o Mercado Livre) ou
descrever o caso em texto, nunca colar o JSON da conta.

**Grafia da pasta:** `tests/fixtures-bling/` — em letras minúsculas, sem hífen duplicado, sem
homônima com outra caixa. No Windows (`core.ignorecase=true`) `tests/Fixtures/` e `tests/fixtures/`
são a MESMA pasta física; o git grava a primeira grafia que o índice já conhece, e no Linux da VPS
o teste que procura a grafia certa não acha o arquivo (ver `.planning/learnings/publicador-ml.md`
§2). Depois de `git add`, confira com `git ls-files --stage | grep -i fixtures-bling` que todas as
linhas usam exatamente esta grafia.

## Arquivos

| Arquivo | Resposta do Bling | Uso |
|---|---|---|
| `produto-formato-s.json` | `GET /produtos/{id}` — produto simples | Tradutor, ramo `formato S` (plano 02) |
| `produto-formato-v.json` | `GET /produtos/{id}` — produto com variações | Tradutor, ramo `formato V` (plano 02) |
| `dados-basicos.json` | `GET /empresas/me/dados-basicos` | Conferência de CNPJ divergente (plano 07) |
| `estoques-saldos.json` | `GET /estoques/saldos` | Normalização de saldo (plano 05/09) |
| `depositos.json` | `GET /depositos` | Escolha de depósitos que contam (plano 05/09) |
| `oauth-token.json` | `POST /Api/v3/oauth/token` | Troca e renovação de token (plano 04) |
| `erro-429.json` | corpo de erro `TOO_MANY_REQUESTS` | Limitador / retry (plano 06) |
| `erro-401.json` | corpo de erro `UNAUTHORIZED_ERROR` | Renovação após 401 (plano 06) |

## `Tests\Support\BlingFixtures`

Ler com `BlingFixtures::ler('produto-formato-s')` (sem a extensão `.json`). O método lança
`RuntimeException` quando a fixture não existe ou o JSON é inválido — nunca devolve array vazio em
silêncio. Para o corpo bruto (`Http::fake`), usar `BlingFixtures::cru('produto-formato-s')`.
