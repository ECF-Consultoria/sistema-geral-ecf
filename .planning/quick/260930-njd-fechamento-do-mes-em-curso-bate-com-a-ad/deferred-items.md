# Itens fora do escopo — quick 260930-njd

## Falha PRÉ-EXISTENTE (não é regressão deste quick)

`tests/Feature/Quick260911/ValorFixoNaColunaFaturamentoTest::test_coluna_de_estado_ok_continua_imprimindo_o_valor_apurado`

Falha desde o quick **260922-j4l** (commit `e5568bc9`, "faturamento separado por
plataforma no fechamento"), que trocou o corpo de `ColunaFaturamento()` em
`resources/js/Pages/Admin/Financeiro.jsx`:

- antes: `<span className="font-mono tabular-nums text-[16px] text-white/75">{fmtBRL(empresa.faturamento)}</span>`
- agora: `<ValorPorPlataforma linha={empresa} alinhamento="esquerda" ... />`

O teste é de conteúdo de arquivo e casa a regex do trecho ANTIGO, que não existe
mais. Confirmado pré-existente por `git show HEAD:resources/js/Pages/Admin/Financeiro.jsx`
— o diff deste quick nesse arquivo é de +40 linhas, todas adições (o componente
`ProcedenciaFaturamentoNota` e a chamada dele), sem tocar `ColunaFaturamento`.

Passou desapercebido porque `Quick260911` não está em nenhum dos dois gates
(`Phase137|Phase139|...|Quick260930` e `Phase74|Phase110`).

**Correção pendente:** atualizar a regex do teste para o componente novo
(`ValorPorPlataforma`), preservando a intenção original — que o ramo genérico
(estado `ok`) continue imprimindo o valor apurado em vez de cair num dos ramos
nomeados. Fora do escopo deste quick (arquivo e módulo de outro trabalho).

## Outras 14 falhas PRÉ-EXISTENTES (fora dos dois gates)

Apareceram ao rodar um filtro largo (`Adman|adman|Sync|Metric`) para conferir que
a correção do `updateOrCreate` em `AdmanService::syncCompany()` não regredia nada.
**MEDIDAS nos dois estados**, não presumidas: com os arquivos deste quick
restaurados para HEAD e com eles aplicados, o resultado é IDÊNTICO —
`14 failed, 13 passed (155 assertions)` nas duas rodadas.

| suíte | falhas | sintoma |
|---|---|---|
| `Tests\Unit\Phase39\MercadoLivreSugadoresProviderTest` | 2 | `fetchAdgroupsMetrics`/`fetchAdgroupMlbs` devolvem payload vazio |
| `Tests\Feature\DevControllerTest` | 5 | 404 na rota `/dev/adman/{id}/sync` e props da `/dev` mudadas |
| `Tests\Feature\Phase119\CompanyScoreServiceFonteTest` | 3 | resolução de fonte Performance/Shopee |
| `Tests\Feature\Phase42\AnalyzeCompanyMlWindowQuarantineTest` | 2 | contrato de `fetchAdgroupsMetrics` |
| `Tests\Feature\Phase75\Phase75NpsShopeeTest` | 2 | `nps_surveys` não é criado |

Nenhuma toca `syncCompany()`, `SyncAdmanCompanyJob`, o rollup do fechamento, o
`AdminController` ou a `Financeiro.jsx`. Todas estão FORA dos dois gates deste
quick, que ficaram verdes (730 e 39 testes, exit 0).

## `syncCompanyMarginOnly()` tem o mesmo defeito de `updateOrCreate`

`AdmanService::syncCompanyMarginOnly()` (linha ~200) faz
`updateOrCreate(['company_id' => ..., 'reference_date' => $date], ...)` com `$date`
como string `'Y-m-d'` — exatamente o padrão corrigido em `syncCompany()` neste
quick. O valor gravado é datetime (`'2026-09-28 00:00:00'`, cast `date` do model),
então o WHERE com a string curta não casa: no MariaDB funciona por coerção, no
SQLite estoura no unique de `(company_id, reference_date)`.

Não foi corrigido aqui porque nada deste quick passa por esse método (ele roda no
`adman:sync-margem` das 11:20) e a correção deveria vir com um teste próprio do
caminho de margem. **A correção é a mesma:** trocar `$date` por
`Carbon::parse($date)->startOfDay()`.
