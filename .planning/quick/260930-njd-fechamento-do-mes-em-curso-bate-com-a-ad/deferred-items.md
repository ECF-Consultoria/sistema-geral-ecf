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
