# Fase 160 — Baseline de testes (antes de mexer)

- Data/hora: 2026-10-02 (medida antes de qualquer mudança de código da fase)
- `git rev-parse HEAD`: `b3c5cc537225342ac9df77001b5d88678608b830`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\PubRascunho.php` (carrega ESTE worktree)
- Saídas brutas: `C:/tmp/ecf-160-baseline/g1.txt` … `g8.txt`

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo |
|---|---|---|---|---|---|---|---|---|
| 1 | Publicador (`tests/Unit/Publicador tests/Feature/Publicador`) | 230 | 952 | 0 | 0 | 0 | 0 | 16 s |
| 2 | `tests/Feature/PortalCliente` | 240 | 2123 | 0 | 0 | 0 | 0 | 70 s |
| 3 | `tests/Feature/Phase75` | 43 | 151 | 0 | 0 | 0 | 0 | 18 s |
| 4 | `tests/Feature/Phase76` | 23 | 103 | 0 | 0 | 0 | 0 | 6 s |
| 5 | `tests/Feature/Phase77` | 33 | 92 | 0 | 0 | 0 | 0 | 5 s |
| 6 | `tests/Feature/Phase134` | 24 | 107 | 0 | 0 | 0 | 0 | 5 s |
| 7 | Soltos (AnuncioIaAnalise, AnuncioIaRascunho, AnunciosPolosNaListagem, MlTokenAncoraPolos) | 52 | 189 | 0 | 0 | 0 | 0 | 10 s |
| 8 | `npm run test:js` (node --test) | 476 | n/d | 2 | 0 | 0 | 1 | 2 s |

Os grupos 3 a 6 saem "OK, but there were issues" apenas por PHPUnit Deprecations (4, 2, 36 e 27), não por falha.

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

Grupo 8 (`test:js`), 474 passam e 2 falham:

1. `Características secundárias nasce recolhido (é o grupo que mais infla)` — `AssertionError`: o fonte do componente de planilha não casa com `/RECOLHIDOS_INICIAIS = \[G_SECUND\]/`.
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha` — `deepStrictEqual`: o código tem `['Encerrado','Protocolo Churn','Desistência','Churn']` e o teste espera `['Encerrado','Protocolo Churn','Churn']`.

Nenhuma das duas tem relação com o Publicador (planilha / fases de Polos).

## Falha INTERMITENTE conhecida (depende da rede — não é regressão)

Grupo 3 (`tests/Feature/Phase75`): `PublicarEmpresaNaoAtribuidaTest::test_admin_nao_recebe_403_no_update`
falhou uma vez no gate da wave 3 (2026-10-02) com 500 — `RuntimeException: [MLB Coleta] Falha ao obter
app token: HTTP 400`. O teste não tem `Http::fake`: o `PUT /mlb/anuncios/rascunho/{id}` do assistente
antigo valida o título por `MlCatalogoMetaService::categoria('')`, que pede um app token REAL ao ML
(`MlColetaService::getAppToken`, client_credentials do `.env`). Na baseline a API respondeu 200; no
gate respondeu 400; rodado de novo, sozinho, passou. A fase não tocou nesse caminho (só apagou
`index`/`empresas` do `MlbAnuncioController`). Se reaparecer no gate final, rodar o teste isolado
antes de chamar de regressão.

## Regra de comparação

Gate da fase = cada grupo com contagem de testes maior ou igual à desta tabela e nenhuma falha nova; os testes do Portal Anunciar removidos em 160-15 saem da conta com nome listado. No grupo 8 as 2 falhas acima são o piso conhecido.
