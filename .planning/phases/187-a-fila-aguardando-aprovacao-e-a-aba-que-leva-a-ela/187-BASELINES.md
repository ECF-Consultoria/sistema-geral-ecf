# Fase 187 — baselines medidas ANTES de editar, e a tabela FID-01 do E1

> ⚠️ **Tudo neste arquivo foi MEDIDO nesta máquina, nesta sessão, no commit de partida
> `75b9f82f`** — antes de qualquer edição de produção do plano 187-01. Medir junto com as
> próprias edições contamina o "antes" (já aconteceu nas Fases 177 e 186).
>
> ⚠️ **Contagem ACIMA da baseline é ESPERADA; ABAIXO é regressão.** A sessão paralela
> acrescenta testes e a régua sobe sozinha. NUNCA "ajustar" um número para baixo:
> registrar a diferença.

---

## 1. As linhas de forma fixa

```
COMMIT: 75b9f82f
BUILD: exit 0 em 162s
MANIFEST: 489 entradas / 200 paginas / isca ausente
JS: 4836 / 4835 pass / 1 fail / 0 skipped
PHP: 2346 / 20243 / 1
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e
PROTOTIPO design_handoff_publicador/Publicacoes v2.dc.html: 659 linhas sha256 56e6c194b896151c
PROTOTIPO new_layout_publicador/Publicacoes v2.dc.html: 656 linhas sha256 6dcbb2f9ff8782e0
```

### Como cada uma foi medida

| linha | comando |
|---|---|
| `COMMIT` | `git rev-parse --short HEAD` — **primeira ação do executor** |
| `BUILD` | `npm run build` cronometrado com `date +%s` |
| `MANIFEST` | `node <script>.cjs public/build/manifest.json`, lendo o JSON com `require` + `Object.prototype.hasOwnProperty.call` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` |
| `PROTOTIPO` | `wc -l` + `sha256sum`, com `test -e` antes |

⚠️ **JS e PHP foram medidos SÓ pelo harness.** `node --test | grep "^# tests"` NUNCA passa no
Node v24 e `artisan test | grep -E "Tests:|OK"` PASSA COM TESTE FALHANDO (learnings §4, casos 12
e 13). Nenhuma contagem deste arquivo veio de pipeline artesanal.

⚠️ **O `MANIFEST` foi lido pelo JSON, nunca por `grep`.** Medido nesta rodada: o hash do
`app.jsx` saiu **com HÍFEN** — `assets/app-Dv6-mbDf.js` — e um `grep -E "app-[A-Za-z0-9]*\.js"`
não casaria. A **isca** é a entrada inexistente `resources/js/Pages/Mlb/NaoExiste9z7.jsx`: ela
saiu **ausente**, então a sonda não está cega.

⚠️ E `resources/css/app.css` **não é chave do manifest** — o CSS entra por
`manifest['resources/js/app.jsx'].css = ["assets/app-BZcBiEAW.css"]`. Quem for conferir CSS
construído lê por aí, não por uma chave `resources/css/app.css` que não existe.

⚠️ **A suíte PHP foi rodada em BACKGROUND e nenhum arquivo de produção foi editado enquanto
ela rodava** (o PHPUnit carrega lazy; editar no meio contamina a medição — lição do 186-01).

---

## 2. As DUAS falhas nomeadas por SEMÂNTICA — nenhuma corrigida

### 2.1 JS — 1 falha, PRÉ-EXISTENTE, Fase 87

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
```

Localizada por semântica (`grep -rln` do texto do `test()`), não por número de linha:
**`tests/js/estrutura-grade-glide.test.js`**.

⚠️ **NÃO corrigida, de propósito.** É a falha única que `REF_JS_FALHAS=1` já aceita desde a
Fase 186, e consertá-la não é trabalho desta fase.

### 2.2 ⚠️⚠️ PHP — 1 falha que NÃO existia no fechamento da 186, e NÃO é desta fase

O fechamento da Fase 186 mediu, em 15/10/2026, **PHP 2346 / 20261 / 0**. Nesta sessão, no
commit `75b9f82f` e **antes de qualquer edição minha**, a medida é **2346 / 20243 / 1**:

```
FAILED  Tests\Feature\Publicador\Aprovacao\VinculoDuravelDaOriginalTest
        > terr 11 a retencao de 48h e o creative engine estao intocados por range
        tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php:410
        Failed asserting that two strings are identical.
        - ''
        + 'app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php'
```

**Causa medida, não presumida:**

```
$ git log --oneline 1c16d9e9..HEAD -- app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php
67d6a005 feat(196): fotos do Publicador gravam no disco imagens e leem do local como fallback
```

O gate é um **gate de território por RANGE** da Fase 186 (`PARTIDA = '1c16d9e9'`, declarada no
próprio arquivo de teste). A **sessão paralela** commitou a Fase 196 **dentro desse range** e
tocou um arquivo que, para a Fase 186, é território do outro dev. É **exatamente** o caso já
escrito no learnings §4 — *"o gate por range da FASE acusa o ORQUESTRADOR e as SESSÕES
PARALELAS"*: o recorte certo é por **ESCOPO DO ASSUNTO** (`feat(186-…)` × `feat(196)`), não por
range puro.

⚠️⚠️ **NÃO corrigida, e de propósito, por três razões:**

1. O arquivo acusado — `PublicadorCriativoReferenciaService.php` — está **explicitamente
   proibido** a esta fase (Creative Engine / retenção de 48 h, território do outro dev).
2. O teste é prova de uma fase **em produção** (a 186 foi deployada em `a4c4187f`). Reapontar
   um gate da 186 para "fazer passar" é a definição de *teste que para de provar algo*.
3. A queda de asserções (20261 → **20243**, 18 a menos) é consequência aritmética da falha: o
   método aborta na primeira asserção falha e perde as asserções seguintes do laço.

⚠️ **Consequência operacional para os dez planos seguintes:** `--suite php` sai com **exit 1**
neste commit, por essa falha herdada. **A régua de falhas do PHP NÃO foi afrouxada** — o
harness continua reprovando com `_f > 0`, porque criar um `REF_PHP_FALHAS` aceito seria abrir a
porta dos fundos que este plano existe para fechar. **A diferença é registrada aqui, não
silenciada.** Quem for comparar: `2346 / 20243 / 1` é o *antes* desta fase; subir asserções e
manter **1** falha é progresso; **2** falhas é regressão.

---

## 3. O estado do harness ANTES de eu mexer nele

```
$ sh tests/gates/verificar-testes.sh --autoteste | tail -2
AUTOTESTE: 14 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

E `REF_*` como estavam (medidos em `d4aeaa30`, 14/10/2026):
`REF_JS_TESTS=4482` · `REF_JS_FALHAS=1` · `REF_PHP_TESTS=2103`.

---

## 4. Os três protótipos — e qual é a autoridade

| arquivo | linhas | sha256 (16) | papel |
|---|---|---|---|
| `design_handoff_publicador/Aprovacao.dc.html` | 1432 | `68624883ce35547e` | ⚠️ **AUTORIDADE** da fila "Aguardando aprovação" (E1-E5) |
| `design_handoff_publicador/Publicacoes v2.dc.html` | 659 | `56e6c194b896151c` | ⚠️ **AUTORIDADE** da aba de Publicações (seção A) |
| `new_layout_publicador/Publicacoes v2.dc.html` | 656 | `6dcbb2f9ff8782e0` | versão ANTIGA — **a que está no ar** (Fase 180), e a **ERRADA** para esta fase |

Os três hashes estão aqui para que **nenhuma medida de fidelidade desta fase seja feita contra
um protótipo que se moveu no meio**. Os dois primeiros batem, byte a byte, com os hashes que o
fechamento da Fase 186 registrou em 15/10/2026.

### ⚠️ ACHADO NOVO — as duas cópias NÃO diferem "exatamente na seção A1"

O plano afirma que as duas `Publicacoes v2.dc.html` *"diferem exatamente na seção A1"*. Medido
por `diff`: são **4 hunks**, e dois deles são da **seção A2** do README (*"Renomear o status do
Mercado Livre"*), não da A1.

| hunk | linhas | seção | o que muda |
|---|---|---|---|
| 1 | `:31,32` × `:31,32` | A1 | `border-bottom:1px solid #1F1F1F` entra no `<header>`; `gap:6px` para **`gap:14px`** |
| 2 | `:34` × `:34,37` | A1 | o **subtítulo** (`font-size:13.5px`, *"Ofertas ativas e históricas…"*) é **substituído pelas DUAS ABAS** (`gap:20px`, `margin-bottom:-1px`, `border-bottom:2px solid #FACC15` na ativa) |
| 3 | `:458` × `:461` | **A2** | `ST.pending[0]`: `'Em revisão'` para **`'Em análise pelo Mercado Livre'`** |
| 4 | `:531` × `:534` | **A2** | `tabsDef`: `['pending', 'Em revisão']` para **`['pending', 'Em análise pelo ML']`** |

➡️ Quem implementar a aba (seção A) precisa dos **quatro**, não dos dois primeiros.

⚠️ E o docblock de `publicacoes/CabecalhoDePublicacoes.jsx` cita `new_layout_publicador/…` — é a
referência **certa** para o que está no ar e a **ERRADA** para esta fase.
