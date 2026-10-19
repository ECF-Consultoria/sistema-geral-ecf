---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 03
subsystem: publicador-aprovacao-painel
tags: [painel-de-revisao, coluna-de-fotos, marcadores-xy, galeria, aprovar-sem-alerta, verificacao-automatica, servidor-antes-do-render]
requires:
  - BloqueiosDaOfertaService::contagemDeFotos (Fase 186)
  - GaleriaDaOfertaService::doRascunho + ::tituloDoQuadrado (Fase 187)
  - RevisaoDeImagemService::aprovarSemAlerta (Fase 186)
  - PubAprovacaoImagem / PubAprovacaoImagemAlerta / PubAprovacaoConjunto (Fase 186)
  - PainelDeRevisaoService (Fase 188, plano 02)
  - rota mlb.anuncios.publicador.fotos.arquivo (routes/mlb_anuncios.php:178)
provides:
  - FotosDoPainelService::para — a coluna de fotos do painel E2, resolvida no servidor
  - FotosDoPainelService::selo / ::borda / ::marcadores / ::verificacoes / ::alertasOrdenados / ::porId / ::noQuadro — as regras puras e PÚBLICAS
  - a chave `fotos` do view-model do PainelDeRevisaoService
affects:
  - 188-05 (a ação "Aprovar N sem alerta" — tem de percorrer TODOS os conjuntos)
  - 188-08 (a tela da coluna de fotos: o cabeçalho e o mapa chave→token são dela)
  - 189 (lightbox) e 192 ("Gerar novamente com IA") — NÃO antecipados
tech-stack:
  added: []
  patterns:
    - cor como CHAVE SEMÂNTICA no PHP (TOK-15), nunca hex
    - regra pura e PÚBLICA para estado que a galeria final nunca emite (o padrão de GaleriaDaOfertaService::tituloDoQuadrado)
    - ordem determinística normalizada no serviço quando a relação do modelo vem ordenada pelo ÍNDICE
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php
    - tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php
    - tests/gates/contagens-188-03.txt
  modified:
    - app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
    - tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php
    - tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php
    - .planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-FID01.md
decisions:
  - "PERGUNTA-188-C (usuário, 19/10/2026): o reuso da rota da foto é ACEITO COM o efeito colateral do EditorEmUso — carregar miniatura suspende o agendamento e o preparo por IA do produto por 3 minutos. Declarado no docblock com os CINCO leitores nomeados."
  - "PERGUNTA-188-A (usuário, 18/10/2026, opção c): híbrido — cabeçalho uma vez (tela) + ordinal por item (dado). TERCEIRA derrota do protótipo na milestone."
  - "`FotosDoPainelService` é 4º parâmetro OBRIGATÓRIO do PainelDeRevisaoService: com default o container devolveria o default (Container.php:1327-:1331) e a injeção seria uma ilusão."
  - "A ordem dos alertas é normalizada por `id` no serviço (`porId()`), porque a relação do modelo vinha ordenada pelo ÍNDICE composto `pubapal_sev_ix`."
metrics:
  duration: "~3h"
  completed: 2026-10-19
---

# Fase 188 Plano 03: o SERVIDOR da coluna de fotos Summary

A coluna de fotos do painel E2 passou a existir no servidor: progresso lido de **uma** contagem,
galeria ordenada com **a URL que já existia**, marcadores x/y em **percentual**, borda de **cinco**
ramos, "Aprovar N sem alerta" **igual ao que a ação aprova**, e as verificações automáticas como
**dado** com o ordinal do marcador.

---

## PARTIDA e commits

**PARTIDA: `f931e5d9`** (capturada na primeira ação, como pedido — e usada **só como referência de
leitura**, nunca como extremo de range de território).

| commit | o que entregou |
|---|---|
| `9ffa6164` | **tarefa 1** — `FotosDoPainelService`, progresso/galeria/URL/marcadores, ligado ao `PainelDeRevisaoService` |
| `116217c2` | **tarefa 2** — "Aprovar N sem alerta", a igualdade com a ação, o custo, e o achado do container |
| `4a740c28` | **tarefa 3** — as verificações com o ordinal, território e bijeção, e o achado da ordem dos alertas |
| `2e52cb4b` | a **sobrevivente da mutação** investigada e a premissa travada |

---

## As 4 baselines RE-MEDIDAS

⚠️ **A régua do PHP é `0`.** As duas falhas da **Fase 179**
(`tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318` — fronteira de dia, `now()` 52
vezes, **fora** de `Aprovacao/`) estavam **VERDES nas duas medições**, antes e depois. ⚠️ Não foram
consertadas, não foram contadas como regressão e **não existe `REF_PHP_FALHAS`**.

| baseline | na PARTIDA (`f931e5d9`) | no FIM | wave 2 declarou | comando |
|---|---|---|---|---|
| **PHP** | **2510 / 22264 / 0** | **2522 / 22568 / 0** | 2510 / 22264 / 0 | `sh tests/gates/verificar-testes.sh --suite php` |
| **JS** | — (não re-medida no início; a wave 2 mediu) | **5498 / 5497 pass / 1 fail / 0 skipped** | 5498 / 5497 / 1 / 0 | `sh tests/gates/verificar-testes.sh --suite js` |
| **BUILD** | — | **exit 0 · 62 s** | exit 0 · 77 s | `npm run build` |
| **MANIFEST** | — | **495 entradas / 202 páginas · isca ausente** | 495 / 202 | lido **pelo JSON** (o hash sai `app--5yuaubs.js`, **com hífen** — `grep` não casaria) |

⚠️⚠️ **O DELTA DO PHP FECHA EXATAMENTE, e isso é a prova de que nada alheio entrou nem saiu:**
`2522 − 2510 = **+12**` testes (os **12** métodos deste plano) e `22568 − 22264 = **+304**`
asserções (as **304** medidas pelos quatro ids: `84 + 59 + 48 + 113 = 304`). **Falhas: 0 → 0.**
⚠️ A wave 1 havia medido que **as asserções não são invariante** (−5 com testes idênticos); aqui o
par bateu nos dois eixos, então a comparação é de **testes E falhas** — e as duas também fecham.

⚠️ A única falha JS é a **pré-existente** `estrutura-grade-glide.test.js` (Fase 87, *"Características
secundárias nasce recolhido"*), nomeada **por semântica** pelo próprio harness — não corrigida.
⚠️ **`0 skipped`:** o `npm run build` foi rodado **DEPOIS** da campanha de mutação e **ANTES** de
medir a suíte JS, exatamente para não cair na armadilha do `mtime` que a wave 1 mediu.

**`--autoteste`, colado:**

```
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

(casos 1–8 JS · 9–14 PHP com o shape ANSI real do Collision · 15–16 a resolução `187-01` × `01` nu ·
17–20 manifesto incompleto ABORTANDO.)

---

## A URL DA IMAGEM — o BLOCKER resolvido sem território novo

**Mapeamento medido, conferido model por model:**

```php
route('mlb.anuncios.publicador.fotos.arquivo', [
    'produto' => $oferta->produto_id,   // PubAprovacaoOferta:280 — belongsTo(PubProduto)
    'imagem'  => $revisao->imagem_id,   // PubAprovacaoImagem:67  — belongsTo(PubImagem)
])
```

| de | por | para | medido em |
|---|---|---|---|
| prefixo da rota | `publicador/produtos/{produto}` | — | `routes/mlb_anuncios.php:158` ⚠️ (a `188-REGUA.md` §14 diz **`:157`**, que é o `->middleware` da rota ANTERIOR) |
| a rota | `/fotos/{imagem}/arquivo` + `throttle:240,1` | `MlbPublicadorController::arquivoFoto` | `routes/mlb_anuncios.php:178` ✓ |
| o handler | `$img->caminho === null ? null : ArquivosDeImagem::resposta(...)` | `abort(404)` no nulo | `MlbPublicadorController:212`, `:215`-`:217` ✓ |

### `url_arquivo = null`, testado nos DOIS casos

- **com arquivo** → a URL da rota, comparada por `assertSame` contra o `route()` esperado **e** com o
  trecho `/fotos/{imagem}/arquivo` conferido;
- **`caminho === null`** (`PubImagem:10`: *"veio do Anunciar"*) → **`null`**, com **prova explícita de
  que não é string vazia** (`<img src="">` faz o navegador **recarregar a própria página**) e com o
  `estado_rotulo` ainda preenchido — a tela mostra **o estado declarado**, na disciplina do IMG-13.

⚠️ **REUSAR NÃO É EDITAR, provado por FORMA:** `numstat` **ZERO** em `app/Support/Publicador`
(incl. `Imagem/ArquivosDeImagem`), `ImagemAssetService.php`, `Acervo*`, `routes/mlb_anuncios.php` e
`MlbPublicadorController.php`; e gate de ausência no código **sem comentário** para
`ArquivosDeImagem`, `Acervo`, `ImagemAssetService` e `App\Support\Publicador`. Nenhum deles em
`files_modified`. **A rota serve BYTES** — a v28 virou o acervo para o **R2 privado** e o bucket não
serve URL pública.

---

## ⚠️⚠️ PERGUNTA-188-C — o efeito do `EditorEmUso`, DECLARADO (não escondido)

**O caminho, medido:** `arquivoFoto` → `rascunho($produto)` → `produto($id)` →
**`EditorEmUso::marcar()`** (`MlbPublicadorController:405`) → o produto fica *"com o editor aberto"*
por **3 minutos** (`publicador.preparo_ia.editor_em_uso_min`, **`config/publicador.php:148`**,
valor `3` conferido também em runtime pelo gate).

➡️ **Carregar a miniatura no painel SUSPENDE o agendamento e o preparo por IA daquele produto
enquanto alguém revisa.** Os leitores do sinal, **nomeados um por um** no docblock do serviço:

| # | leitor | efeito |
|---|---|---|
| 1 | **`AgendadorDaFila:337`** | `['tipo' => 'esperar']` — o produto não é agendado nesta passada |
| 2 | **`PreparoIaDoRascunhoService:131`** | o preparo por IA do lote pula o produto |
| 3 | **`PreparoIaDoRascunhoService:180`** | idem, no segundo laço |
| 4 | **`PreparoIaDoRascunhoService:366`** | idem, na escrita do rascunho |
| 5 | **`GarantiaPadrao:192`** | a garantia padrão não é escrita por baixo de quem edita |
| 6 | **`ExcluirProdutoService:195`** | a exclusão do produto espera |

**A razão do usuário (19/10/2026):** *é o que o portão quer* — `AgendadorDaFila:336` diz
textualmente *"Gente com o editor aberto: não publica por baixo dela"*, e **revisar é trabalhar no
produto**. ⚠️ **"Servir a foto sem marcar" foi OFERECIDO e RECUSADO** (exigiria rota nova sobre
`App\Support\Publicador`, território da outra sessão).

**E isso tem GATE**, não só prosa: o caso lê o arquivo **CRU** (o único gate desta fase que lê
comentário de propósito, **com a inversão declarada no arquivo de teste**) e exige, por **lista
CONSTANTE de 13 termos** e **UMA** asserção coletando os ausentes, que o docblock nomeie os 3
minutos, o `config/publicador.php:148`, o `MlbPublicadorController:405`, `EditorEmUso::marcar`, os
cinco leitores, a data e o que foi recusado. **Isca nas duas direções:** o docblock completo PASSA;
tirar `AgendadorDaFila:337` REPROVA (provado em memória, sem tocar o repo). E **anti-fantasma**: as
cinco linhas citadas são lidas do disco e têm de conter `EditorEmUso::` — **as cinco contêm**.

> **Por que os cinco nomes, e não "vários consumidores":** a próxima pessoa que vir o agendamento
> parado tem de achar a causa **pelo `grep`, não por bisseção**.

---

## Os marcadores x/y em PERCENTUAL, e o teto do `decimal(5,2)`

- `marcadores` existem **só** em `pending` e `rejected` (`:1109`), numerados **1..N na ordem dos
  alertas**, com `x`/`y` em **percentual** (`float`, não string) e cor por severidade
  (`high → vermelho`, `med → âmbar`);
- alerta **`ok` não** gera marcador (não tem ponto) · alerta com **x/y nulos não** gera marcador **e
  não derruba a lista** · foto **aprovada com alerta** não tem marcador **e não** tem badge;
- ⚠️ **saneamento na leitura:** `noQuadro()` recusa fora de **0–100** — `0.0` e `100.0` passam,
  `100.01`, `-0.01`, **`999.99`** (o teto da coluna), `null` e texto não. **O MariaDB recusa acima
  de 999,99 com erro 1264; o SQLite dos testes grava TRUNCADO em silêncio**, então a suíte não
  pegaria o valor absurdo — ele simplesmente não vira **marcador fantasma** desenhado fora da foto.
  Este serviço **não grava nada**.

---

## A grade, a borda de CINCO ramos e o "sem alerta"

**As três regras ficaram TRÊS** (provadas separadamente **e** com conflito):

| regra | quantos estados | ponteiro |
|---|---|---|
| marcadores | **2** (`pending`, `rejected`) | `:1109` |
| badge `!` | **1** (`pending` com alerta) | `:1105` |
| borda | **5** ramos: selecionada → alerta → aprovada → pendente → **neutra** | `:1113` |

⚠️ **A precedência só se prova com CONFLITO**, e há três casos: `selecionada + alerta` → selecionada ·
`selecionada + aprovada` → selecionada · `alerta + aprovada` → alerta. **No payload real também**: a
selecionada da fixture é a pendente-COM-alerta, com isca confirmando que ela tem alerta.

**AS DUAS OMISSÕES DO REVS-09 (`.planning/REQUIREMENTS-v27.md:596`), declaradas:** o requisito lista
**quatro** bordas e **não** lista (a) o **5º ramo** `#3F3F46` nem (b) o **anel**
`'0 0 0 2px #FACC15'`, que é box-shadow **ADICIONAL** à borda (`:1113`). **Os dois foram
reproduzidos** — `borda` tem cinco valores semânticos e `anel` é booleano próprio, com asserção de
que **só uma** miniatura o tem.

**"Aprovar N sem alerta"** (IMG-05 / critério 5): `sem_alerta.quantidade` = `pendentes - com_alerta`,
**da MESMA leitura** — e a igualdade com a ação é **medida** contra o retorno real de
`RevisaoDeImagemService::aprovarSemAlerta()`, **somado sobre todos os conjuntos**. `rotulo` =
`'Aprovar 3 sem alerta'`. **`null`** com N = 0 **ou** fora do `live` (`:1305`), este último com os
5 andamentos não-vivos num laço e **isca positiva** com os 3 do `live` lidos de
`PubAprovacaoOferta::LIVE`. **Decisão 8 do usuário:** `com_alerta_restantes` viaja junto e
**a soma fecha** (`sem_alerta + com_alerta_restantes = pendentes`).

### ⚠️ AVISO MEDIDO AO PLANO 188-05 — leia antes de ligar o botão

`aprovarSemAlerta()` recebe **UM conjunto por chamada**, e esta contagem cobre **TODOS os grupos**.
Medido na fixture de dois grupos: `GENERAL → 2`, `COR:Azul → 1`, total **3**. ➡️ **A ação tem de
percorrer `PubAprovacaoConjunto::doRascunho()`**; chamá-la para um conjunto só aprovaria **menos** do
que o botão promete — e há asserção provando que `1 < 3` nesta fixture, para que a cobertura não seja
"observável por acidente".

**A fronteira das duas definições, declarada:** a contagem sai da **galeria final**
(`noConjuntoFinal()`: estado vivo **E** `posicao` não nula **E** `fora` falso) e `aprovarSemAlerta()`
filtra só por `estado = pending`. **Hoje elas coincidem por construção dos escritores**: os três
lugares que gravam `fora = true` gravam, no MESMO `update()`, um estado que **não** é `pending`
(`RevisaoDeImagemService:217`, `:303`, `:512`).

---

## O bloqueio cobre TODOS os grupos — provado

- `contagemDeFotos()` é **CHAMADO**, nunca recontado: espião (**subclasse no teste**, a 186 intocada
  no disco) conta **1** chamada, com isca provando que ele conta;
- os **quatro** números do payload são comparados por `assertSame` contra a leitura direta de
  `BloqueiosDaOfertaService::contagemDeFotos()`;
- fixture de **QUATRO** grupos (`GENERAL` + 3 cores): `progresso` = `0 de 4 aprovadas`,
  `sem_alerta.quantidade` = **4**, `imagens` com **4** entradas e o `GENERAL` **primeiro**. Um
  serviço que lesse só o grupo da imagem aberta daria **1** e reprovaria;
- galeria **vazia** → `imagens: []`, `selecionada: null`, `progresso: '0 de 0 aprovadas'`, e o gate
  confere que o bloqueio **`sem_fotos` continua de pé** (IMG-12 é bloqueio, não tela muda).

## A galeria NÃO cria segunda contagem

`progresso`, `total`, `aprovadas`, `pendentes`, `com_alerta`, `com_alerta_restantes` e
`sem_alerta.quantidade` saem **todos** de `contagemDeFotos()`. A lista ordenada vem de
`GaleriaDaOfertaService::doRascunho()` (que **recebe o id do RASCUNHO**, de propósito) e o `titulo`
é obtido **chamando** `tituloDoQuadrado()` — comparado por `assertSame` contra a chamada direta, em
vez de remontado. O gate de território exige a presença de `contagemDeFotos`, `doRascunho`,
`ehPrincipal`, `ehAlerta`, `temPonto`, `ehLive` e do `route(...)`, **e** a ausência dos literais
`'Capa'`, `'a conferir'`, `'gold_pro'`, `'pending'`, `'approved'`, `'high'` (todos **LIDOS** de
constante).

## A PERGUNTA-188-A aplicada — o cabeçalho fora, o ordinal dentro

| entrega (DADO) | **não** entrega (é da tela, plano 188-08) |
|---|---|
| `severidade` (`ok`/`med`/`high`) | o cabeçalho `VERIFICAÇÃO AUTOMÁTICA · APOIO` |
| `texto` (pt-BR pronto, de `pub_aprovacao_imagem_alertas.texto`) | a frase montada com prefixo |
| `glifo` (`✓`/`!`) e `cor` (três chaves semânticas DISTINTAS) | a classe de cor |
| **`ordinal`** (`?int`, o número do marcador) | a posição do `span` |

- ordinais medidos: **`[1, null, 2, 3]`** com o `ok` **NO MEIO** da fixture (se ele fosse o último,
  uma numeração sobre a lista inteira passaria);
- ⚠️ **`null` no `ok`, nunca `0` nem `''`** — `0` é um número de marcador **plausível** para quem lê.
  **DIVERGÊNCIA DECLARADA do `:1108`**, que usa `''`;
- ⚠️ **um cálculo só:** o ordinal é a **posição** em `alertasOrdenados()`, achada por identidade
  (`array_search` estrito), e é a **mesma lista** que `marcadores()` numera. Provado também pelo
  par: tirando um alerta, os dois andam junto;
- **o prefixo colado NÃO foi reproduzido:** gate de ausência de `VERIFICAÇÃO AUTOMÁTICA`,
  `Verificação automática` e `APOIO` na fonte **sem comentário**, com **isca do leitor** (o arquivo
  **CRU** contém os três, de propósito).

### ⚠️ A TERCEIRA derrota do protótipo, com `arquivo:linha` dos DOIS lados

| lado | linha | o que tem lá |
|---|---|---|
| o literal do cabeçalho | **`:369`** | `VERIFICAÇÃO AUTOMÁTICA · APOIO` — **mas é a revisão detalhada (E4)**, Fase 190/191 |
| o que o **painel** escreve | **`:633`** | `<span>Verificação automática{{ a.n }} · </span>{{ a.text }}` — colado, **sem** cabeçalho e **sem espaço** |

As **três** derrotas da milestone foram conflito entre **duas regras do projeto** resolvido **pelo
usuário**, nunca erro do protótipo (as outras duas: o `'3 dias'` por extenso contra o `' d'` de
`:1054`, e o toast do lote mantendo o vocabulário travado contra o *"N ofertas aprovadas"* de
`:1167`). ⚠️ Os dois lados são lidos **do arquivo** pelo gate, que **degrada para razão NOMEADA** se
o `design_handoff_publicador/` (**não versionado**) não estiver no disco — nunca para "conferido".

---

## Os ids, a bijeção e o homônimo

| id | métodos | medido |
|---|---|---|
| `galeria-e-marcadores` | 3 | `tests=3 assertions=84` |
| `borda-e-estados` | 3 | `tests=3 assertions=59` |
| `sem-alerta-e-contagem` | 3 | `tests=3 assertions=48` |
| `verificacoes-e-ordinal` | 3 | `tests=3 assertions=113` |

Soma: **12 testes / 304 asserções** — conferido também numa invocação única (`--filter=_188_03` →
`12 passed (304 assertions)`), que é o **mesmo número** da soma dos quatro ids.

**Zero órfão, nos três sentidos:** os 4 ids do manifesto são exatamente os 4 usados nos `<verify>`
(`188-03-PLAN.md:240`, `:299`, `:365`); os **12** métodos casam id; nenhum id passa de **3** métodos.
Provado por script no próprio teste (`test_bijecao_do_manifesto_das_fotos_188_03`), com **lista
CONSTANTE** (piso não prova presença), **UMA** asserção coletando por sentido, e isca do nome que
ninguém escreveu.

**Homônimo, por script, nas DUAS direções, incluindo prefixo** (`homonimo-188-03.sh`):

```
ARQUIVOS=905  METODOS=7745  MEUS=12  HOMONIMOS=0
ISCA_DIR1 (test_territorio_e_ausencias_do_plano) acusa=7   (0 = sonda CEGA)
ISCA_CONTROLE (test_metodo_que_ninguem_escreveu_188_03) acusa=0
```

⚠️ A direção 1 **acusa 7 métodos que existem** (`_187_06` … `_187_10`, `_188_02` e o meu) — é a prova
de que o sufixo `_188_03` **não é zelo**. O gate também trava o par: o filtro **não** casa
`_188_02` nem `_187_08`, e os dois métodos **existem no disco**.

⚠️ **E o 1º argumento é `188-03`:** há asserção de que `tests/gates/contagens-186-03.txt` **existe**,
para que a isca do número nu não perca o sujeito.

---

## Território, por ESCOPO DO ASSUNTO (duas passadas)

**Passada A** — commits com `(188-03)` no assunto (`f931e5d9..HEAD`): `9ffa6164`, `116217c2`,
`4a740c28`, `2e52cb4b`. Arquivos tocados por eles: **exatamente os 6** deste plano (os 3 criados + os
3 modificados). ⚠️ **Não se confere o AUTOR:** as três máquinas desta árvore commitam com o mesmo
`user.name`.

⚠️⚠️ **E A RÉGUA §6-9 SE PROVOU AO VIVO NESTA WAVE:** o range `f931e5d9..HEAD` tem **5** commits, e
**um deles não pertence a este plano** — `e57e958f docs(coordenacao): nota para quem planejar a Fase
189 — o que nao esta no codigo` (um bilhete de coordenação para quem planejar a 189, que **aponta os
13 PLAN.md da 188** e avisa que **o "⤢ Ampliar" é da 189 e a 188 não o desenha de propósito**). Ele
tocou **só** `.planning/coordenacao/261019-…md` e **nada meu**, e vem com o **mesmo `user.name`
(`MB.ECF-100376`)** de todo commit desta árvore. ➡️ Prova concreta, nesta wave, de que **território
por range cru não discrimina** e de que **`%an` é vazio por construção** aqui: o **escopo do
assunto** o excluiu corretamente, sem olhar autor nem data.

**Passada B** — `numstat` por caminho, com **`test -e` antes** (anti-fantasma): **ZERO linhas** em
`RevisaoDeImagemService.php`, `GaleriaDaOfertaService.php`, `BloqueiosDaOfertaService.php`,
`PubAprovacaoImagem.php`, `PubAprovacaoImagemAlerta.php`, `PubAprovacaoConjunto.php`,
`routes/mlb_anuncios.php`, `MlbPublicadorController.php`, `app/Support/Publicador`,
`app/Services/Creative`, `app/Mcp`, `ImagemAssetService.php`,
`resources/js/Components/Mlb/Publicador/aprovacao`, `AppLayout.jsx`,
`estrutura-meus-anuncios.test.js`, `publicador-fila-tokens-187.test.js`, `.planning/ROADMAP.md`,
`.planning/STATE.md` e `.planning/REQUIREMENTS-v27.md`. **Nenhum fantasma.**

⚠️ **Nenhuma migration** (esta fase não tem) · **nada instalado** (T-188-SC, provado por forma) ·
**nenhuma requisição HTTP** saiu da coluna de fotos — `Http::preventStrayRequests()` + gravador, com
**isca nas duas direções** (depois da asserção de vazio, um `Http::get` é registrado). **O painel
APROVA, não publica**: a escrita no ML é da Fase 193.
⚠️ **Os três `stash` desta árvore foram apenas LISTADOS** (`stash@{0}: On main: wip` e dois de
`settings-lider2`) — **`git stash` não foi rodado em forma nenhuma**, nem `git checkout`,
`git reset`, `git clean`, `git add -A`, `--amend`, `pint`, deploy, push ou VPS.

---

## A campanha de mutação (`mutar-188-03.sh`)

⚠️ Nome **com o plano**: o scratchpad é **compartilhado** entre subagentes paralelos, e na 187 o
`mutar.sh` de uma wave foi **sobrescrito** pela outra. `sha256` do próprio script:
`7f774d8375dd5772901dfc003d7c9971c0eaf149375ccd401986c5b24f6e8253`.
Ocorrência **travada em 1 ANTES** (⚠️ **toda âncora é de UMA LINHA**, porque `grep -c` conta linhas e
não ocorrências), linha conferida **depois**, restauração **DA CÓPIA** em `trap EXIT`, e `sha256` do
disco **idêntico** ao de `git show HEAD:` antes **e** depois
(`e755e4f67a8778677ade3aefd70eba32cd1da9363357ce008ab4d356a0b460f0`). **`git checkout` NUNCA.**

**9 mutações · 8 MORRERAM · 1 sobreviveu:**

| # | mutação | veredicto |
|---|---|---|
| i | marcador também em `approved` | **MORREU** (1 failed, 2 passed) |
| ii-a | badge `!` também em `approved` | **MORREU** (2 failed, 1 passed) |
| ii-b | badge `!` também em `rejected` | **SOBREVIVEU** → investigada abaixo |
| iii | `aprovada` passa a vencer `alerta` na borda | **MORREU** |
| iv | `sem_alerta` conta pendente **COM** alerta | **MORREU** (2 failed) |
| v | `estado_rotulo` vazio na chave desconhecida | **MORREU** |
| vi | `url_arquivo` deixa de ser `null` com `caminho` nulo | **MORREU** |
| vii | `ordinal` do `ok` virando `0` | **MORREU** |
| viii | `porId()` deixa de ordenar (volta à ordem do ÍNDICE) | **MORREU** |

### ⚠️ A SOBREVIVENTE (ii-b), INVESTIGADA

**Não é lacuna de asserção: é INOBSERVABILIDADE por construção.**
`PubAprovacaoImagem::noConjuntoFinal()` admite só `pending` ∪ `approved`, então **`rejected` nunca
chega à galeria final** e o ramo `rejected` do badge não tem como ser exercitado por `para()`. A
regra mora **um nível abaixo** (`PubAprovacaoConjunto::comAlerta()` + `GaleriaDaOfertaService`,
Fases 186/187, as duas **em produção** e com gates próprios); este serviço só a **repassa**.

**O que é meu para provar ficou provado, em duas pontas** (commit `2e52cb4b`):
1. o badge é **LIDO** de `$daGaleria['com_alerta']`, **nunca recomputado** aqui — recomputar seria a
   **segunda definição** de "com alerta";
2. ⚠️ **a premissa ficou TRAVADA:** `NO_CONJUNTO_FINAL` é comparada por igualdade com
   `[pending, approved]`. **No dia em que ela ganhar `rejected`, este caso REPROVA** — gate que
   dispara **na hora** em que a premissa muda, em vez de uma nota que ninguém lê.

---

## ⚠️ Os ponteiros que eu tive de RECONFIRMAR (e os números certos)

A lição da **§J** se confirmou **na primeira tentativa de usá-la**: os três ponteiros que a prosa do
plano cita para a coluna de fotos estavam **todos deslocados**. Registrados na **§K nova** do
`188-FID01.md` (o arquivo foi **estendido**, com `git diff HEAD` conferido antes: sem WIP alheio).

| a prosa dizia | real (medido por `sed`) | o que está DE FATO na linha citada | desvio |
|---|---|---|---|
| `pins` em **`:1112`** (plano `:146`; §C) | **`:1109`** | `:1112` é `variation: this.PROD[...]` | **−3** |
| o texto do progresso em **`:1305`** (§C, C1) | **`:1303`** | `:1305` é `hasSafe`/`safeLabel` | **−2** |
| `hasSafe` em **`:1311`** (plano `:289`) | **`:1305`** | `:1311` é o `selActions` | **−6** |
| `safeCount` | **`:1304`** | mora com `shared`/`sharedNote` | (não citado antes) |
| prefixo da rota em **`routes/mlb_anuncios.php:157`** (`188-REGUA.md` §14) | **`:158`** | `:157` é o `->middleware` da rota ANTERIOR | **+1** |

**CONFIRMADOS** (medidos, não presumidos): `:1104` · `:1105` · `:1113` · `:1117` · `:1119` · `:1167`
· `:369` · `:633` · `:1293` · `:1059`; e fora do protótipo `MlbPublicadorController:212`/`:215`-`:217`/`:405`
· `config/publicador.php:148` · `AgendadorDaFila:336`/`:337` ·
`PreparoIaDoRascunhoService:131`/`:180`/`:366` · `GarantiaPadrao:192` · `ExcluirProdutoService:195` ·
`routes/mlb_anuncios.php:178` · `REQUIREMENTS-v27.md:596`.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A ordem dos alertas vinha do ÍNDICE, não da criação — e isso quebrava a numeração**

- **Found during:** tarefa 3, ao medir os ordinais (`[1,2,3,null]` em vez de `[1,null,2,3]`)
- **Issue:** `PubAprovacaoImagem::alertas()` não tem `orderBy`, e a tabela tem o índice composto
  `pubapal_sev_ix` em `(aprovacao_imagem_id, severidade)`
  (`database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php:240`). O
  planejador varre por esse índice e devolve os alertas **ordenados por severidade** (`high < med <
  ok`, alfabético). Como o ordinal **é a posição nessa lista**, o **mesmo defeito ganharia números
  diferentes em ambientes diferentes** — e o MariaDB de produção não é obrigado a escolher o mesmo
  índice que o SQLite dos testes. ⚠️ **Nenhuma suíte pegaria:** texto e marcador leem a MESMA lista,
  então continuariam **concordando entre si** enquanto apontam para outro ponto da foto.
- **Fix:** `FotosDoPainelService::porId()` normaliza por `id` **no único lugar que numera** (o modelo
  é da Fase 186 e intocável). Mutação (viii) prova que o gate morde.
- **Commit:** `4a740c28`

**2. [Rule 3 - Blocking] `fotos` nasceu `null` com asserção que reprovava pelo próprio conserto**

- **Found during:** tarefa 1
- **Issue:** `PainelDeRevisaoServiceTest:1172` (wave 2) fazia `assertNull($vm['fotos'])` com a
  mensagem *"é do plano 188-03 e nasce null"* — uma **medição DATADA**, não invariante: preencher a
  chave (o trabalho deste plano) **reprovava** o gate.
- **Fix:** virou `assertIsArray`. ⚠️ **A contagem de asserções NÃO mudou** (`navegacao-e-payload`
  segue `2 passed / 50 assertions`), então `contagens-188-02.txt` **não foi retocado**. `preco`
  continua `null` (plano 188-04).
- **Commit:** `9ffa6164`

**3. [Rule 3 - Blocking] A injeção com default seria uma ILUSÃO — achado no framework**

- **Found during:** tarefa 2, pelo gate escrito para provar a construção
- **Issue:** a primeira redação deu a `PainelDeRevisaoService::$fotos` o default `null`, para não
  mexer nos testes da wave 2. ⚠️ **`Container::resolveClass()`
  (`vendor/laravel/framework/src/Illuminate/Container/Container.php:1327`-`:1331`) devolve o
  DEFAULT** quando ele existe e a classe não está explicitamente ligada (*"to avoid overriding any
  of the developer specified defaults"*). ➡️ A dependência **nunca** seria injetada e a coluna de
  fotos sairia de um `app()` **por chamada**: service locator disfarçado de injeção.
- **Fix:** 4º argumento **obrigatório**, e as **duas** construções manuais dos testes da wave 2
  passaram a receber o quarto argumento — `PainelDeRevisaoServiceTest:1267` e
  **`PainelDaOfertaRotaTest:198`** (a segunda só apareceu ao rodar o gate `custo-do-painel`, que a
  usa pelo helper `espiao()`). O achado ficou **travado por um par de classes anônimas em memória**
  (COM default não é injetada; a MESMA SEM default é).
- ⚠️ **Os NOVE ids de `contagens-188-02.txt` foram re-rodados e estão VERDES com as contagens
  INALTERADAS** (`custo-do-painel` 2/24 · `painel-na-rota` 2/41 · `navegacao-e-payload` 2/50 ·
  `territorio-painel` 1/42 · `bijecao-manifesto-02` 1/22 · os outros quatro idem).
- **Commit:** `116217c2`

**4. [Rule 3 - Blocking] O resíduo de verificação da tarefa 2 (o manifesto)**

- **Issue:** o briefing apontava que a tarefa 2 (`:299`) usaria um id sem o manifesto; medido, o
  `<files>` da tarefa 2 (`:266`) **já listava** `tests/gates/contagens-188-03.txt` — mas a prosa da
  tarefa 3 (`:357`) diz que o arquivo é *"FECHADO aqui"*, leitura pela qual o `<verify>` da tarefa 2
  abortaria.
- **Fix:** cada tarefa escreveu o **seu** id **na mesma tarefa que o usa, antes do verify** (§13). A
  razão está registrada **no cabeçalho do manifesto**, para o próximo executor.

**5. [Rule 2 - Correctness] A isca que reprovaria o comportamento certo**

- **Issue:** a primeira isca do "cálculo único" comparava marcadores e ordinais sobre uma lista
  **com alerta sem ponto**, onde a igualdade é **falsa por desenho** — ela reprovaria o
  comportamento correto. É a família **"gate mais largo que o invariante"**, acontecendo **dentro do
  gate** escrito para medir o invariante.
- **Fix:** a isca passou a ser formulada **só com alertas que têm ponto**, e a assimetria ficou
  medida com `array_diff` **nas duas direções** (os marcadores são **subconjunto** dos ordinais do
  texto, e o que falta é exatamente o `3` do alerta sem ponto).
- **Commit:** `4a740c28`

### Divergências de DESENHO, declaradas (não são bugs)

1. **Os estados que a galeria final nunca emite.** `rejected`, `regenerating`, `replaced` e
   `removed` **não chegam** por `doRascunho()`. As regras deles são medidas por **métodos públicos e
   puros** (`selo()`, `borda()`, `marcadores()`) — o mesmo padrão, e pela mesma razão, de
   `GaleriaDaOfertaService::tituloDoQuadrado()`. Sem isso, o ramo `rejected` dos marcadores seria
   fidelidade **afirmada e não medida**. ⚠️ **Isso difere da leitura literal do
   `<behavior>`**, que supõe os cinco estados chegando pelo payload.
2. **O clamp nas DUAS pontas.** `:1293` faz `imgs[Math.min(selIdx, len-1)] || null` — clampa só o
   teto, então índice **negativo** devolve `null`. Aqui `null` é **reservado** para *"galeria
   vazia"*, um estado que a tela DECLARA; usá-lo para um índice inválido faria a coluna **dizer o
   vazio com fotos na grade**.
3. **`posicao` global × `rotulo` por grupo, e N principais.** `reposicionar()` renumera `posicao`
   **por conjunto** (`RevisaoDeImagemService:609`-`:611`), e a capa mora em
   `pub_aprovacao_conjuntos.principal_imagem_id` — **por conjunto**. Com 4 grupos há **4**
   principais e o selo `#4` pode acompanhar o rótulo `Capa`; o protótipo tem `D.main[set]`, **uma
   só**. Medido e travado por asserção.
4. **`ordinal` do `ok` é `null`, não `''`** (`:1108` usa `''`).
5. **Alerta sem ponto mantém o ordinal** e não tem marcador (o protótipo não filtra x/y nulos e
   desenharia `left: "undefined%"`). **Renumerar faria o texto dizer "3" com o marcador escrito
   "4"** — e é por isso que não se renumera.
6. **`grupo`/`grupo_geral` viajam como DADO CRU.** Não existe coluna com a **descrição humana** da
   variação (medido no schema); inventá-la seria a tela afirmando o que não sabe — a mesma decisão
   que `GaleriaDaOfertaService::ROTULO_CAPA` já declarou. O rótulo humano é da tela (188-08).
7. **Custo: consultas FIXAS, porém repetidas.** `contagemDeFotos()` e `doRascunho()` leem o mesmo
   conjunto de imagens (3 + 3 consultas) e este serviço faz a sua própria leitura das revisões — o
   plano **exige** chamar as duas para não criar segunda verdade. O total é **fixo** e **não cresce
   com imagem nem com grupo** (medido: idêntico com 1 imagem/1 grupo e com 12 imagens/4 grupos).

---

## Known Stubs

Nenhum. Varredura por `\bTODO\b|\bFIXME\b|placeholder|coming soon` nos dois arquivos deste plano:
**vazia**, com isca da sonda nas duas direções (`// TODO: x` → 1; `// TODOS os grupos` → 0 — a
primeira redação da sonda casava `TODOS` com `TODO`, a família *"gate mais largo que o invariante"*
acontecendo no meu próprio scan).

Nenhum valor vazio hardcoded chega à tela: `url_arquivo` nula é **estado declarado** (nunca `''`),
`estado_rotulo` **nunca** é vazio, `verificacoes`/`marcadores`/`imagens` vazias saem como **lista**,
e `selecionada`/`sem_alerta`/`nota_de_conjunto` nulas são **casos declarados no contrato**.

## Threat Flags

Nenhuma superfície nova. Este plano **não** abre rota, **não** grava nada, **não** instala
dependência e **não** cria migration. O único consumo de superfície existente é a rota
`mlb.anuncios.publicador.fotos.arquivo` (já no ar, com `throttle:240,1` e recorte por
`programas->empresaDoProduto()`), cujo **efeito colateral está declarado** acima (PERGUNTA-188-C).
O recorte por conta já foi feito no plano 188-02 (T-188-22): a galeria é lida **pelo rascunho da
oferta**.

## O que NÃO foi feito (fronteira)

- **A tela da coluna de fotos** é do plano **188-08** (cabeçalho `VERIFICAÇÃO AUTOMÁTICA · APOIO`,
  grade de 6 colunas, mapa chave semântica → token hex, posição do `span` do marcador);
- **o botão** "Aprovar N sem alerta" e o toast são dos planos **188-05/12** — aqui está **a
  contagem**;
- **"⤢ Ampliar"** (Fase **189**) e **"✦ Gerar novamente com IA"** (Fase **192**) **não foram
  desenhados**, com **gate de ausência** (`Ampliar`, `Gerar novamente`, `⤢`, `✦`);
- ⚠️ **nenhuma tela foi vista renderizada**: o banco local não tem empresa com `ml_token` (a rota dá
  **404** aqui), `pub_aprovacao_ofertas` está **vazia** e a fila nasce vazia até a Fase 194. ⚠️ **A
  conferência visual da Fase 187 pelo usuário segue ABERTA** — nada daqui a substitui.

---

## Self-Check: PASSED

**Arquivos declarados, conferidos no disco (`test -f`):**

```
FOUND: app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php          (776 linhas, min_lines 160)
FOUND: tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php            (1897 linhas, 12 métodos)
FOUND: tests/gates/contagens-188-03.txt                                           (4 ids, nenhum com tests=0)
FOUND: app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
FOUND: tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php
FOUND: tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php
FOUND: .planning/phases/188-.../188-FID01.md                                      (§K nova)
FOUND: .planning/phases/188-.../188-03-SUMMARY.md
```

**Commits declarados, conferidos (`git log --oneline --all | grep`):**

```
FOUND: 9ffa6164   FOUND: 116217c2   FOUND: 4a740c28   FOUND: 2e52cb4b
```

**`must_haves` do frontmatter do plano:**

| exigência | medido |
|---|---|
| `FotosDoPainelService.php` com `min_lines: 160` | **776** linhas |
| `exports: ["para"]` | `public function para(` presente |
| `key_link` → `PubAprovacaoImagemAlerta` via `alertas` | **11** referências ao modelo, **25** a `alertas` |
| `key_link` → `FotosDoPainelService::para` pelo padrão `FotosDoPainelService` | **3** no `PainelDeRevisaoService` |

**Gates, última varredura — todos VERDES:**

```
188-03  galeria-e-marcadores · borda-e-estados · sem-alerta-e-contagem · verificacoes-e-ordinal
188-02  os NOVE ids, com as contagens INALTERADAS
188-01  baselines-medidas · fid01-remedida
```
