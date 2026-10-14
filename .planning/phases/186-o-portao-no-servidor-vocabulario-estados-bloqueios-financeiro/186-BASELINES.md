# Fase 186 — as quatro baselines, medidas ANTES de qualquer edição

Medidas pelo plano **186-01**, em **14/10/2026**, no commit de partida da fase, **antes de um byte
de código de produção ter sido tocado**. O plano 186-01 rodou **sozinho** (isolado dos planos 186-02
e 186-03, que são da mesma wave): medir junto com as edições dos outros contamina o "antes" — foi o
que aconteceu na Fase 177.

⚠️ **AS LINHAS DE FORMA FIXA ESTÃO ABAIXO, uma por linha, sem nada antes nem depois.** Elas existem
porque um `186-BASELINES.md` em prosa pode ser **inventado** e nenhum gate notaria (achado G3 da
verificação da fase). O gate da tarefa **confere o conteúdo**, não só a presença do arquivo — e
confere o `COMMIT:` contra o `git rev-parse --short HEAD` do momento: se divergir, a medição não foi
no commit declarado e o plano **reprova**.

```
COMMIT: d4aeaa30
BUILD: exit 0 em 58s
MANIFEST: 479 entradas / 196 paginas / isca ausente
JS: 4482 / 4481 pass / 1 fail / 0 skipped
PHP: 2103 / 16034 / 0
PROTOTIPO Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2
PROTOTIPO Publicacoes v2.dc.html: 659 linhas sha256 56e6c194b896151c360543adb0c46f07302e15099ce8508d4741ddb6893e841f
```

## Como cada linha foi medida (o comando, não a lembrança)

| linha | comando |
|---|---|
| `COMMIT` | `git rev-parse --short HEAD` |
| `BUILD` | `npm run build`, com `date +%s` antes e depois |
| `MANIFEST` | `node` sobre `public/build/manifest.json` **pelo JSON**, nunca por `grep` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` (= `node --test --test-reporter=tap "tests/js/**/*.test.js"`) |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` (= `artisan test tests/Unit/Publicador tests/Feature/Publicador`) |
| `PROTOTIPO` | `wc -l` + `sha256sum` |

⚠️ **O ANSI é removido ANTES de qualquer filtro** (`sed -E 's/\x1B\[[0-9;]*[A-Za-z]//g'`), dentro do
`verificar-testes.sh`. Não é cosmético: a linha real do Collision nesta máquina é
`<ESC>[90mTests:<ESC>[39m    <ESC>[32;1m1 passed<ESC>[39;22m<ESC>[90m (22 assertions)<ESC>[39m`, e o
`grep -E "Tests:[[:space:]]+1 passed \(22 assertions\)"` **não casa nela** com os escapes no meio. As
sequências de cor já comeram 12 linhas de veredicto neste projeto, com a campanha parecendo verde sem
provar nada. O `--autoteste` do harness tem uma **isca** para isso: o mesmo caso que passa limpo tem
de **REPROVAR** sem a limpeza.

⚠️ **A contagem é comparada, não só o "0 fail"**: arquivo que não carrega reporta zero falhas. É por
isso que `JS` e `PHP` trazem o total, e não só o veredicto.

## MANIFEST — a isca, e por que ela existe

O manifest é lido **pelo JSON** (`require` do `manifest.json` + `hasOwnProperty`), nunca por `grep`:
o hash do Vite sai com **hífen** (`VisaoGeral-B2rcMtJm.js`) e uma página que cai fora do manifest
**SOME da tela** sem erro nenhum.

As **8** páginas do Publicador conferidas, todas presentes:

```
resources/js/Pages/Mlb/Publicador/Alavancas.jsx        -> assets/Alavancas-BvemBHP5.js
resources/js/Pages/Mlb/Publicador/Configuracoes.jsx    -> assets/Configuracoes-azeOgkyE.js
resources/js/Pages/Mlb/Publicador/Editor.jsx           -> assets/Editor-B2WexF6o.js
resources/js/Pages/Mlb/Publicador/Produto.jsx          -> assets/Produto-FrSZ8Lxh.js
resources/js/Pages/Mlb/Publicador/Produtos.jsx         -> assets/Produtos-CQTykOxd.js
resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx -> assets/PublicacaoEmLote-NCjfq_IP.js
resources/js/Pages/Mlb/Publicador/Tarefas.jsx          -> assets/Tarefas-CXZJzpGj.js
resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx       -> assets/VisaoGeral-B2rcMtJm.js
```

⚠️ **DIVERGÊNCIA MEDIDA ao texto do plano:** ele diz "as **7** páginas do Publicador". São **8** no
disco (`ls resources/js/Pages/Mlb/Publicador/*.jsx` → 8). A 8ª é `Editor.jsx`, que é **território do
outro dev** — ela foi **conferida no manifest** (leitura), e **não** tocada.

**ISCA:** `resources/js/Pages/Mlb/NaoExiste9z7.jsx` → **ausente**. Se ela aparecesse como presente, a
sonda estaria cega e as 8 linhas acima não provariam nada.

## JS — a falha é UMA, e é pré-existente

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
```

Localizada por **SEMÂNTICA** (`grep -rln` pelo nome do teste), não por número de linha:
**`tests/js/estrutura-grade-glide.test.js`**.

⚠️ **NÃO CORRIGIR.** É dívida anterior a esta fase e não pertence a nenhum plano dela.

⚠️ **Contagem ACIMA da referência é ESPERADA** — a sessão paralela (planos 186-02/03 e as fases
184/185 do outro dev) acrescenta testes. Contagem **ABAIXO** é regressão. Em nenhum dos dois casos a
baseline é "ajustada": a diferença é **registrada e explicada**.

## PHP — 2103 / 16034 / 0

`tests/Unit/Publicador` + `tests/Feature/Publicador`, **0 failed e 0 error**. Bateu com a referência
de 14/10 exatamente.

⚠️ Os testes usam **SQLite em memória** (`phpunit.xml`), então **MariaDB não é necessário** — e ele
está **desligado** nesta máquina agora. Nenhuma migration foi rodada por este plano (migration é do
plano **186-03**).

## PROTÓTIPOS — e o 7º caminho fantasma, pego antes de virar medida

`test -e` em **cada** caminho citado, antes de medir qualquer coisa nele:

| caminho | existe? |
|---|---|
| `design_handoff_publicador/Aprovacao.dc.html` | **sim** — 1432 linhas |
| `design_handoff_publicador/Publicacoes v2.dc.html` | **sim** — 659 linhas |
| `new_layout_publicador/Visao Geral.dc.html` | **sim** — 620 linhas, sha256 `bd3fee754bfdaf13eda4d70adf90745a834b1a328ddc0420890d3c0cb640f141` |
| `design_handoff_publicador/Visao Geral.dc.html` | ⚠️ **NÃO EXISTE** — seria o **7º caminho fantasma** do projeto |

⚠️ **Os protótipos vivem em DOIS diretórios e eles NÃO têm os mesmos arquivos.** `Aprovacao.dc.html`
e `Publicacoes v2.dc.html` estão em `design_handoff_publicador/`; **`Visao Geral.dc.html` está em
`new_layout_publicador/`** e não existe no outro. Caminho ausente é **ERRO DO GATE**, nunca
"conferido" nem "território intocado".

⚠️ **NENHUM dos dois diretórios é versionado** (`git ls-files` devolve vazio nos dois). Na máquina de
outro dev eles **não existem**. Portanto:
- as linhas `PROTOTIPO` acima valem para **esta** máquina, **nesta** data;
- qualquer gate que dependa de ler `.dc.html` tem de **degradar para `skip` EXPLÍCITO** quando o
  arquivo falta — **nunca** para "conferido", e nunca para uma linha inventada;
- quando ausente, a linha correta é `PROTOTIPO <arquivo>: AUSENTE (skip declarado)`.

## ⚠️ Nenhum executor viu tela renderizada

**Nenhum executor desta fase viu tela renderizada.** Não há navegador neste ambiente, e a conferência
visual é do **usuário**, em **produção**, depois de um deploy que **só ele autoriza**.

Os fatos do ambiente que sustentam a frase, cada um verificável:

1. **Não há banco de dados local de pé.** O MariaDB desta máquina está **desligado** nesta sessão
   (`tasklist | grep mysqld` vazio). Por consequência não há empresa com `ml_token` e nem como
   popular `ml_acervo_itens`: sem banco não existe nem a tabela. A suíte de PHP roda em **SQLite em
   memória**, com fixtures criadas e descartadas por teste — nada do que ela cria chega a uma tela.
2. **Leitura de produção está BLOQUEADA** nesta sessão (classificador barra `plink`/`pscp`/`tinker`
   contra o VPS em subagente). Não houve, e não podia haver, conferência contra dado real.
3. **O que os gates de JS medem é HTML de `renderToStaticMarkup`**, string em memória — não é
   navegador, não tem CSS aplicado, não tem layout. Ele prova estrutura e literal, **não aparência**.

➡️ Toda afirmação desta fase sobre "a tela mostra X" significa **"o HTML gerado contém X"**. A
conferência visual de verdade acontece em produção, na conta 459, com o usuário olhando.
