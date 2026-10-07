---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
fixed_at: 2026-10-06
review: 167-REVIEW.md
scope: critical + warning + info (exceto BE-IN-01, FE-IN-06, FE-IN-14)
findings_in_scope: 40
fixed: 40
skipped: 3
iteration: 1
status: all_fixed
---

# Fase 167: Correções da revisão de código

Corrigidos os 5 bloqueios distintos, os 16 avisos e 19 dos 22 informativos. Cada achado tem
commit e teste próprios. A conferência no navegador (Chrome 152, banco SQLite isolado, dados
fictícios) achou um defeito nas próprias correções, corrigido no `98cfc3c7` (ver abaixo).

## Por achado

| Achado | Commit | Prova |
|---|---|---|
| BE-CR-01 / FE-CR-01 produto novo cai dentro de outro | `edba564c`, `e73b05a4` | `GravarLinhasTest::test_produto_novo_com_grupo_igual_ao_codigo_orfao_de_outro_produto_nao_mexe_nele`; `estrutura-produtos-gravacao.test.js`; navegador T6 |
| BE-CR-02 reimportação rebaixa categoria / apaga medidas | `aa1e6cd4` | `ModeloEImportacaoTest::test_reimportar_nao_rebaixa_a_categoria_confirmada_nem_apaga_medidas_com_sem_medidas` |
| FE-CR-02 voltar do navegador sem guarda | `a539edaa` + `98cfc3c7` | `estrutura-guarda-do-voltar.test.js`, `estrutura-produtos-navegacao.test.js`; navegador T1–T3 |
| FE-CR-03 campo esvaziado não grava | `ae77f8a8`, `bf0a5933` | `GravarLinhasTest::test_nulo_explicito_limpa_eixo_valor_familia_e_custo_e_texto_vazio_nao_mexe`; navegador T7, T10 |
| FE-CR-04 variação nova grava outro custo | `ae77f8a8` | `estrutura-produtos-linha.test.js`; navegador T8 |
| BE-WR-01 .xlsx sem limite antes da matriz | `18b3a592` | 3 testes em `ModeloEImportacaoTest` (dimensão enorme, linhas repetitivas, linhas formatadas) |
| BE-WR-02 fórmula em coluna de texto | `e958c78a` | `ModeloEImportacaoTest::test_formula_em_coluna_de_texto_usa_o_valor_salvo_ou_recusa_a_linha` |
| BE-WR-03 ofertas irmãs com nome velho | `574b6315` | `OfertaLigadaAoProdutoTest::test_renomear_numa_linha_que_cria_variacao_acompanha_as_ofertas_irmas` |
| BE-WR-04 importação esconde erros | `cd049bf7` | `ModeloEImportacaoTest`, `GravarLinhasTest::test_aplicar_conta_no_flash_as_linhas_que_nao_entraram` |
| BE-WR-05 HTTP e varredura dentro da transação | `2f7f3f47` | `CadastroDeProdutoTest::test_categorias_validadas_fora_da_transacao_e_espera_varrida_uma_vez_por_lote` |
| BE-WR-06 frete preso por minutos | `d2356f3e` | 2 testes em `FreteDoProdutoTest` (429 sem retry em série, queda de conexão) |
| BE-WR-07 N+1 | `cc076b5f` | `ListasDaEmpresaTest`, `CadastroDeProdutoTest`, `FreteDoProdutoTest` (contagem de queries) |
| BE-WR-08 migration de criação não idempotente | `08426a4d` | `SchemaDosProdutosTest`; MariaDB 10.4: banco descartável + `--path` no `ecf_admin` (13 ofertas antes e depois) |
| FE-WR-01 falha de rede descarta ids | `468ace87` | `estrutura-produtos-gravacao.test.js` |
| FE-WR-02 digitação durante o "Salvando…" | `19de1165` | `estrutura-produtos-ficha.test.js` |
| FE-WR-03 guarda desligada para sempre | `4f16fde8` | `estrutura-produtos-ficha.test.js` |
| FE-WR-04 excluir última gravada leva a nova | `9f834377` | `estrutura-produtos-ficha.test.js` |
| FE-WR-05 lista velha no histórico | `85ce6bf1` + `98cfc3c7` | `estrutura-produtos-navegacao.test.js`; navegador T4, T5, T9 |
| FE-WR-06 `mostrarCartao` desfaz a rolagem | `b5c50ac4` | `estrutura-produtos-navegacao.test.js` (6 posições) |
| FE-WR-07 "Consultar fretes" acima de 200 | `5531f8b1` | `estrutura-produtos-fretes.test.js` |
| FE-WR-08 picker de categoria preso | `acb3ef52` | `estrutura-produtos-ml.test.js` |
| BE-IN-02..08 | `dcbe7fe5`, `6a201872`, `d4e90967`, `308fc648`, `c0818f33`, `4cd1540b` (BE-IN-05 em `bf0a5933`) | testes unitários e de feature citados nos commits |
| FE-IN-01..05, 07..13 | `924b6ea1`, `b3555159`, `85056003`, `779ab749`, `a7d369eb`, `e64a7a98`, `020a7400`, `20383117`, `07979689`, `b111dd1e` (FE-IN-11 em `468ace87`; FE-IN-13 nos 4 arquivos de teste comportamentais novos) | `tests/js/estrutura-produtos-*.test.js` |

**Fora do escopo:**
- **BE-IN-01:** o `mimes:xlsx` só pode ser testado em produção, com arquivos exportados do Google Sheets, do LibreOffice e do Excel Mac.
- **FE-IN-06:** código sem uso no `SpreadsheetGrid`, que é compartilhado com o Onboarding.
- **FE-IN-14:** "100 por página" duplicado no front.

## O que a conferência no navegador achou nas correções (`98cfc3c7`)

1. **FE-CR-02 pela metade.** A ficha registrava o ouvinte de popstate em captura no `window`, contando que ele rodaria antes do do Inertia. No Chrome 152, porém, os ouvintes do próprio `window` rodam na ordem em que foram registrados, e a captura não passa à frente. Isso foi medido com um evento real e com um sintético. Como o do Inertia nasce antes do da ficha, escolher "ficar" recarregava a ficha e sobrava só o rascunho. **Correção:** o ouvinte agora é registrado no `app.jsx` antes do `createInertiaApp` (`resources/js/lib/guardaDoVoltar.js`), e a ficha só liga e desliga a guarda dela.
2. **FE-WR-05 com a ficha recarregada.** Depois de um F5, ou com a ficha aberta pela URL, a lista que está atrás pertence a OUTRO documento. O `history.back()` trazia a página velha do cache do navegador, sem recarga, sem aviso e sem destaque. **Correção:** a volta pelo histórico agora exige `sameDocument`, e a lista restaurada do cache (`pageshow` com `persisted`) recarrega os produtos.

Roteiro final no navegador: **26/26**, sem erro no console.

| Grupo | Casos |
|---|---|
| Voltar do navegador | perguntar, ficar e sair (T1, T2) |
| Rascunho | rascunho depois do F5 (T3) |
| Histórico | salvar com o histórico limpo (T4) e Cancelar sem empilhar (T5) |
| Gravação | produto novo com Ref igual ao código solto de outro (T6) |
| Limpar campos | esvaziar custo (T7), valor e eixo (T10) |
| Variação nova | grava o custo que mostra (T8) |
| Ficha recarregada | salvar depois do F5 (T9) |

## Decisões tomadas nas correções (para o usuário saber)

- **`''` e `null` no POST de linhas.**
  - O controller lê o JSON cru das linhas, porque o `ConvertEmptyStringsToNull` transformaria todo `''` em "limpar".
  - `''` significa "não mexi", e `null` significa "limpar".
  - `custo: ''` deixou de apagar o custo pelo HTTP. A ficha nunca enviava isso.
- **Categoria na reimportação.** A proteção vale só na importação. Na ficha, um texto digitado de propósito ainda troca a categoria.
- **"SEM MEDIDAS".** Vale como célula em branco em todos os modos. Na importação, uma variação nova de produto existente marcada "SEM MEDIDAS" copia os volumes da 1ª variação (D-04).
- **Fórmula nas colunas de número.** Custo, peso e nº de volumes com fórmula continuam dando erro "Use só números". Só as colunas de texto usam o valor em cache.
- **Aviso da importação.** "N linhas não entraram: …" vai no flash `success`, porque é o único que o `AvisoFlash` exibe. Na Ref repetida dentro do arquivo, vale a 1ª linha.
- **Importação na fila.** A importação de até 1.000 linhas continua síncrona; não virou job na fila.
- **Frete com 429.** O 429 não é mais repetido: a estimativa fica marcada `falhou` e a pessoa consulta de novo.
- **Limites do leitor .xlsx.**
  - Recusa acima de 1.100 linhas com valor; o limite de 1.000 é conferido nas colunas importadas.
  - Ignora valores além da coluna 60.
  - Não recusa pelo `totalRows`, porque a planilha real exportada do Google Sheets declara 1.000 linhas por aba.
- **Pendente sem solução.** No FE-WR-01, um produto novo com um lote só cuja resposta se perde depois de gravar (504) recebe "código já existe" na nova tentativa. Resolver isso exige idempotência pela `chave` no servidor.
