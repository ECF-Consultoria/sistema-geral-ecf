# Gates do redesign do Publicador — e o medidor que mente

**Leitura obrigatória antes de tocar nas Fases 180/181 (Publicações e Produtos) ou em qualquer
tela da milestone v26.0.** Escrito em 13/10/2026, no fim da Fase 179, depois de 11 planos,
**217 mutações** e **~100 divergências medidas** contra o protótipo.

Nada aqui é dedutível do código. Tudo custou rodada perdida.

---

## 1. O protótipo ganha da tabela. Sempre.

Cada plano de desenho traz uma tabela FID-01 derivada do `.dc.html`. **Placar das três fases:**

| Fase | Divergências entre a tabela do plano e o protótipo | Quem estava certo |
|---|---|---|
| 177 | 21 | protótipo, nas 21 |
| 178 | 3 | protótipo, nas 3 |
| 179 | ~78 (referências de linha deslocadas, valores errados, medidas omitidas) | protótipo, em todas |

**Em NENHUMA das ~102 a tabela venceu.** As referências de linha envelhecem e deslocam; confirme
cada medida pela **semântica**, nunca pelo número de linha.

⚠️ **REFINAMENTO medido em 13/10/2026 (plano 180-01): a tabela acertou 10 de 10** — 4 hex, 4 números
de linha e 2 tamanhos, zero divergências. **Foi a primeira vez na milestone.** A diferença: aquele
planejador **mediu com varredura por script**, em vez de ler e estimar.
➡️ **A regra não se revoga, se refina: tabela derivada de SCRIPT é confiável; tabela derivada de
LEITURA não é.** O executor segue obrigado a medir — mas, quando o plano diz como mediu, o custo da
conferência cai. **Mande o planejador declarar se a tabela saiu de script ou de leitura.**

➡️ **Escreva no plano: "quando a tabela discordar do protótipo, MEÇA no `.dc.html` e o protótipo
ganha; a divergência vai na SUMMARY com `arquivo:linha`."** Sem essa frase, o executor obedece a
tabela e o redesenho sai errado — foi a causa documentada do fracasso da primeira tentativa de
redesign, nas palavras do usuário: *"não saiu como eu esperava porque vc adaptava muito o novo
layout ao sistema atual"*.

---

## 2. ⚠️ `tailwind.config.js` varre `*.jsx` e NÃO `*.js`

`content:` aponta para `./resources/js/**/*.jsx`. **Classe do Tailwind escrita num arquivo `.js`
de `resources/js` não é varrida, não gera CSS e NÃO EXISTE PARA A TELA — com todos os testes de
render passando**, porque a classe está no HTML.

Isso quase aconteceu duas vezes: o plano da Fase 179 mandava pôr o mapa de cores no `jornada.js`.
Obedecer teria deixado **o anel da Jornada sem cor** e a hachura da barra invisível, sem nenhum
gate acusar.

➡️ **Todo mapa de classe mora no `.jsx`. Ponha gate que proíba classe no módulo `.js`.**
➡️ Mesma família: **classe montada por concatenação de string o scanner não vê.**

### ⚠️⚠️ 2b. `duration-[Nms]`, `delay-[Nms]` e `ease-[…]` NÃO COMPILAM NESTE PROJETO

Medido em 13/10/2026 (plano 180-09), conferindo o CSS construído: **não existe NENHUMA regra
`.duration-\[…\]`** no `public/build/assets/app-*.css` — zero ocorrências da string `duration-\[`.
A escala NOMEADA existe (`duration-75/150/200/300/500/700`); o valor arbitrário, não.

**A causa, reproduzida** com `postcss([tailwind({content:[{raw:'…duration-[180ms]…'}], plugins:[animate]})])`:
o plugin **`tailwindcss-animate`** (`tailwind.config.js`, `plugins: [forms, animate]`) registra
`duration`, `delay` e `ease` como utilitários de **animação**, e o Tailwind então recusa o valor
arbitrário com o aviso *"The class `duration-[180ms]` is ambiguous and matches multiple utilities"*.
Sem o plugin, a mesma classe compila.

⚠️ **É a mesma família do `*.js` que o `content` não varre: a classe fica no HTML, todo teste de
render passa, e a transição simplesmente não acontece na tela.** Havia **6 usos mortos** no
repositório antes desta fase (`LinhaDeProduto.jsx:40` e cinco em `Pages/Performance/Index.jsx`) —
registrados em `.planning/phases/180-publicacoes-lista/deferred-items.md`, não consertados.

➡️ **Duração fora da escala vai por `style={{ transitionDuration: '180ms' }}` ou por TOKEN
(`theme.extend.transitionDuration`) — nunca por `duration-[…]`.** E ponha gate que prove que a
classe morta não voltou (`assert.equal(regraDe('duration-[180ms]'), null)` com `duration-200` como
controle, senão o gate não distingue "o arbitrário não compila" de "a sonda está cega").

---

## 3. As seis armadilhas do CSS construído

Presença de classe **não é** fidelidade: classe presente com valor errado passa por qualquer gate
de presença. Ler o corpo da regra é obrigatório — e o medidor erra de seis maneiras:

1. **Token sólido NÃO compila para hex** — sai `rgb(38 38 38 / var(--tw-bg-opacity, 1))`, decimal.
   Esperar hex deu **25 falsas faltas**.
2. **`stroke`/`fill` sólidos, ao contrário, SAEM EM HEX LITERAL.** (2 falsas faltas.)
3. ⚠️ **O modificador de opacidade muda de FORMA conforme a PROPRIEDADE.** Medido em 13/10/2026
   (plano 180-06), corrigindo o que esta linha dizia antes:
   - em `border-color`/`background-color` sai **hex de 8 dígitos**, e o byte do alfa **prova** a
     opacidade — é o único jeito de distinguir `0.13` de `0.12`; `0.10` arredonda para `0x1a`;
   - em **`--tw-ring-color`** sai `rgb(250 204 21 / .18)` — **decimal**, e com o zero da frente
     comido pelo cssnano.
   ➡️ **Leia a forma REAL no CSS construído antes de escrever a regex.** Prescrever "hex de 8
   dígitos" às cegas foi a 8ª mentira do medidor nesta milestone — e veio da prescrição, não do
   executor.
4. **O cssnano tira o zero da frente, inclusive depois do sinal de menos:** `-0.03em` → `-.03em`.
5. **O CSS escapa o seletor com barra invertida** (`.text-\[13\.5px\]`) — 54 falsas faltas.
6. **Na variante, o prefixo faz parte do nome da classe:** `.hover\:bg-pub-hover-0:hover`.
7. ⚠️ **Escapar SÓ a barra invertida quebra em classe com colchete e ponto.** Escapar apenas o
   caractere de barra funciona em `.hover:bg-…`, mas em `.text-[15.5px]` os colchetes e o ponto
   sobrevivem sem escape e a regex passa a ler **classe de caracteres** em vez de literais — a regra
   **presente e correta** sai como AUSENTE.
   ➡️ **Escape TODOS os metacaracteres de regex** (use `RegExp.escape` ou equivalente), não só a
   barra. (5ª mentira da sonda na milestone, no plano 180-01.)

⚠️ **LIMITAÇÃO ESTRUTURAL, medida em 13/10/2026 (plano 180-05): a sonda de CSS NÃO protege a
fidelidade de UM arquivo.** Duas razões: o CSS conferido é o do **último build**, e a mesma classe
pode ter **outro dono** no repositório — tirar `flex-none` de um componente não faz a regra
desaparecer do CSS se outro arquivo a usa. Naquele plano, duas mutações foram pegas pelo gate de
**HTML** (igualdade de conjunto de classes do elemento), **não** pelo de CSS.
➡️ **Os dois gates são necessários e não se substituem:** o de CSS prova que a classe EXISTE com o
valor certo; o de HTML prova que o ELEMENTO a tem. Gate de CSS sozinho deixa passar classe removida
do arquivo errado.

➡️ **Toda sonda carrega ISCA**, e a isca tem de ser **classe inexistente no projeto inteiro** —
`w-[13px]` parecia inventada e saiu presente.
➡️ **Regex em ARQUIVO escrito por ferramenta.** Nunca `node -e` (o escape do shell mastiga) e
**nunca heredoc — ele colapsa `\\` em `\` mesmo com `<<'EOF'`**.

---

## 4. A família das asserções que casam sempre — TREZE casos medidos

Este projeto já foi mordido treze vezes pela mesma classe de erro: asserção que passa
**independentemente do código**.

⚠️⚠️ **OS CASOS 12 E 13 SÃO OS MAIS CAROS DA LISTA, porque não estão no SEU gate: estão no jeito
PADRÃO de rodar teste neste projeto.** Medidos em 14/10/2026, na verificação dos 9 planos da Fase
186: **18 dos 26 gates automatizados da fase** estavam quebrados pelos dois, **em direções
opostas**. Os dois estão em planos e SUMMARYs antigos — trate como suspeito tudo que use as formas
abaixo.

### ⚠️ Caso 12 — `node --test | grep "^# tests"` **NUNCA PASSA** (depende da VERSÃO do Node)

No **Node v24.14.0** o reporter padrão é o `spec`, que emite **`ℹ tests 344`** (U+2139, bytes
`E2 84 B9`), **não `# tests 344`**. Logo `grep -E "^# (tests|pass|fail)"` **não casa nada**; e como
o `grep` é o último da pipeline, **ela sai 1 SEMPRE — inclusive com 344 de 344 verdes**.

```
node --test <arquivo> 2>&1 | cat -A | tail -8     # M-bM-^DM-9 tests 344  <= e o ℹ, nao o #
node --test <arquivo> 2>&1 | grep -cE "^# "       # 0
```

⚠️ **O dano NÃO é o vermelho — é o conserto.** O executor seguinte vê vermelho com os testes
verdes, conclui que o gate está errado, e **o conserto barato é tirar o `grep`** — perdendo a
comparação de contagem, que era o único motivo de o gate existir. É o padrão de
`feedback_gate_que_reprova_pelo_proprio_conserto`, e aqui ele vem de graça.

✅ **A forma que FUNCIONA** (medida nos 3 casos, 14/10/2026):
```
node --test --test-reporter=tap <arquivo> > OUT 2>&1
grep -qE '^# fail 0$' OUT && grep -qE "^# tests <N>$" OUT
```
| saída | veredicto |
|---|---|
| `# tests 344` + `# fail 0`, N=344 | **PASSOU** |
| `# tests 344` + `# fail 1` | **REPROVOU** |
| `# tests 3` + `# fail 0`, N=344 (arquivo não carregou) | **REPROVOU** |

⚠️ **Sem o `<N>` você troca um gate vazio por outro**: `# fail 0` sozinho é satisfeito por arquivo
que não carregou.

### ⚠️ Caso 13 — `artisan test | grep -E "Tests:|OK"` **PASSA COM TESTE FALHANDO**

O Collision imprime a linha `Tests:` **em sucesso E em falha**, então o `grep` casa as duas. Medido:
```
echo "  Tests:    1 passed (22 assertions)"          | grep -E "Tests:|OK"  # EXIT 0
echo "  Tests:    2 failed, 1 passed (9 assertions)" | grep -E "Tests:|OK"  # EXIT 0  <= PASSA FALHANDO
```
**Só reprova quando NENHUM teste roda** (`No tests found.`). ➔ Ou seja: o gate prova apenas *"o
filtro casou alguma coisa"*, nunca *"passou"*. E como ele fica **verde**, ninguém desconfia — é a
metade silenciosa do par.

✅ **A forma que FUNCIONA** (medida nos 5 casos, 14/10/2026):
```
! grep -qE 'Tests:.*(failed|error)' OUT && grep -qE "Tests:[[:space:]]+<N> passed \(<A> assertions\)" OUT
```
| saída (N=1, A=22 esperados) | veredicto |
|---|---|
| `Tests: 1 passed (22 assertions)` | **PASSOU** |
| `Tests: 2 failed, 1 passed (9 assertions)` | **REPROVOU** |
| `Tests: 1 error, 1 passed (3 assertions)` | **REPROVOU** |
| `Tests: 1 passed (5 assertions)` | **REPROVOU** (asserções a menos) |
| `No tests found.` | **REPROVOU** |

⚠️ **O `error` é parte obrigatória do filtro:** teste que lança exceção imprime `1 error`, não
`1 failed`, e um gate que só procure `failed` **passa com o teste explodindo**.

### ⚠️ A lição que vale mais que as duas formas

**A contagem esperada tem de morar em arquivo versionado, não dentro do comando.** Na Fase 186 ela
foi para `tests/gates/contagens-186-NN.txt`, um manifesto por plano, por três razões medidas:
mudar um número fica **diff visível** em vez de `grep` apagado em silêncio; **manifesto incompleto
ABORTA** (sem id, sem `tests=`, ou `tests=0`), então não dá para "deixar o número para depois"; e o
mesmo arquivo guarda os **dois regimes** de contagem do §4-caso-11 (pristino e mutado), para a
campanha de mutação não precisar mexer no gate.

⚠️ **E todo harness de gate precisa de `--autoteste`**: um modo que alimenta saída sintética e
**exige que as formas REPROVEM**. Sem isso o próprio conserto vira o próximo caso desta lista.

1. `assert.match(tag, /disabled/)` casa com a classe `disabled:opacity-40` → use `/disabled=/`.
2. `doesNotMatch(html, /0%/)` casa com os dois últimos caracteres de `100%`.
3. `git diff --stat -- <dir>` **depois de commitar** compara a árvore e sai vazio **sempre** → use
   `<inicial>..HEAD`.
4. `html.includes('classe')` quando vários elementos compartilham a classe. **Substring casa com
   prefixo:** `font-pub` dentro de `font-pub-mono`, `text-[11px]` dentro de `text-[11.5px]`,
   `z-3` dentro de `z-30`, `bg-pub-raised` dentro de `bg-pub-raised-0`.
4b. ⚠️ **Terceira variante do substring, medida em 13/10/2026 (plano 180-07): a classe casa pelo
   FIM do nome de OUTRA classe.** `w-[` casa dentro de `shadow-[0_16px_40px_…]`. Já eram prefixo de
   classe e sufixo de atributo; agora também o fim de outro nome. **Trave por borda nos dois lados.**
4c. ⚠️ **O helper `seletorDe()` das fases 180-05/06 NÃO escapava PARÊNTESES:**
   `shadow-[0_16px_40px_rgba(0,0,0,0.6)]` saía AUSENTE com a regra **presente e correta**.
   ⚠️ **Qualquer valor arbitrário com `rgba(…)`, `calc(…)` ou `var(…)` cai no mesmo buraco.**
   É a mesma família do item 7 da §3 — escapar "a barra" não basta: escape **todos** os
   metacaracteres, parênteses incluídos.
5. **`/href="…"/` sem o `\s`** casa com **`data-href="…"`**. Substring casa com **sufixo de
   atributo**, não só com prefixo de classe.
6. ⚠️ **`new RegExp(`\b${x}\b`)` com TEMPLATE LITERAL** — ali `\b` é **backspace (U+0008)**, não
   borda de palavra. Quatro asserções negativas passavam por construção. Use `String.raw`.
7. ⚠️ **`html.indexOf(tagDeAbertura)` pega o IRMÃO ERRADO** quando dois elementos têm a mesma
   string de classes — **quatro gates do ERP estavam medindo a linha do Mercado Livre**.
   ➡️ Localize por **árvore com índices absolutos**, ou por texto único. Nunca pela lista de classes.
8. ⚠️ **Gate cujo CAMINHO NÃO EXISTE mede nada.** Dois casos nesta milestone:
   `app/Services/Mlb/MlPublicacaoService.php` (o real é `app/Services/Mlb/Publicacao/…`) e
   `identidade/tempo.js` + `identidade/dadosDeExemplo.js` (vivem um nível acima, em
   `Components/Mlb/Publicador/`). ➡️ **`test -e` em cada alvo ANTES de acreditar num diff vazio.**
   ⚠️ **Os caminhos CERTOS dos dois territórios proibidos mais citados, conferidos por `test -e` em
   13/10/2026:** `resources/js/Components/Publicador/Mesa` (**SEM** o `Mlb` no meio) e
   `resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx` (**dentro** de `header/`).
   Escrever `Components/Mlb/Publicador/Mesa` ou o header fora de `header/` dá diff vazio e "prova"
   território intocado — foram o 3º e o 4º caminho fantasma da milestone.
   ⚠️ **5º (13/10/2026):** `Pages/Mlb/Publicador/AnunciosHistorico.jsx` **não existe** — o real
   é **`resources/js/Pages/Mlb/AnunciosHistorico.jsx`**, FORA de `Publicador/`, ao lado de
   `anuncioHistoricoUtils.js`. Cinco caminhos fantasma em quatro fases: **`test -e` não é zelo,
   é obrigatório.**
   ⚠️ **6º (13/10/2026, plano 180-09):** `resources/js/Components/Mlb/SubAbasDePublicacoes.jsx`
   **não existe** — o real é **`resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx`**,
   DENTRO de `Publicador/`. Pego pelo `test -e` antes de virar diff vazio.
9. ⚠️⚠️ **CÓDIGO ANSI COME O VEREDICTO DO TESTE — e o log diz que deu tudo certo.**
   Medido em 13/10/2026 (plano 180-03): a campanha de mutação filtrava o resultado do `artisan test`
   por `grep` numa linha fixa, e as sequências de cor comeram **todas as 12 linhas de resultado**.
   O log saiu com "RESTAURADA sha256 OK" nas 12 e **zero evidência de reprovação** — a campanha
   inteira parecia verde sem ter provado nada.
   ➡️ **Em qualquer gate que LEIA saída de `artisan test` ou `npm`, tire o ANSI antes de filtrar**
   (um `sed` removendo `ESC[` seguido de dígitos/`;` e a letra final). ⚠️ **É a armadilha mais
   insidiosa da lista, porque o modo de falhar é silêncio com aparência de sucesso** — todas as
   outras fazem um gate passar; esta faz a **prova inteira** passar.
10. ⚠️ **Asserção de ausência rodada com dado VAZIO é vazia por construção.** Se você assertar que
   um bloco não aparece, rode **com as props preenchidas**.

11. ⚠️ **O SINTOMA PODE SER A CONTAGEM DE TESTES CAIR, não teste falhar.** Medido em 13/10/2026
   (plano 180-08): um `sed` de renome em massa pegou a **declaração** da função junto com os call
   sites, o arquivo virou `SyntaxError`, e o veredicto foi **1 teste em vez de 107** — nenhuma
   falha, nenhum erro gritado. Mesma família do ANSI (item 9) e do `after()` do `node --test`:
   **o modo de falhar é silêncio com aparência de sucesso.**
   ➡️ **Compare sempre a CONTAGEM esperada, não só "0 fail".** Um arquivo que não carrega
   reporta zero falhas.
   ⚠️⚠️ **MAS a contagem esperada pode ter DOIS REGIMES, e isso quebra a regra acima.**
   Medido em 14/10/2026 (plano 180-10B): a sonda de CSS entra em `skip` por `mtime`, e **mutar o
   arquivo o deixa mais novo** — então o mesmo gate reporta **119 testes com o arquivo pristino e
   114 com ele mutado** (noutro, 130 × 118). Travar a campanha na contagem "normal" fez a 1ª
   execução abortar com *"BASE com contagem inesperada"*; e **sem** a trava, a campanha **inteira**
   marcaria "SOBREVIVEU" por um motivo que **não é o código**.
   ➡️ **Meça as DUAS contagens antes da campanha** (pristino e mutado) e aceite as duas, ou
   remova a dependência de `mtime` do gate. É a armadilha que se esconde **dentro** da defesa
   contra as outras três silenciosas (ANSI, `after()` do `node --test`, `SyntaxError`).

➡️ **A pergunta que desmascara todos:** *"o que precisaria estar errado no código para este gate
falhar?"* Sem resposta concreta, o gate é decorativo. **Cada uma das 11 waves da Fase 179 pegou um
gate PRÓPRIO vazio por construção com essa pergunta.**

### E dois gates que são vazios AO CONTRÁRIO

- **"Asserções removidas = ZERO"** por `git diff | grep '^-' | grep assert` **acusa remoção onde não
  houve**: reapontar `assertSame(1,…)` para `assertSame(2,…)` produz uma linha `-assert` por
  construção. ➡️ Exija o par `-`/`+` **no mesmo teste** e `+assert > -assert`, enumerando cada um.
- ⚠️ **O gate por range da FASE acusa o ORQUESTRADOR e as SESSÕES PARALELAS.** Em seis planos
  seguidos da Fase 180 ele apontou violações que não eram do executor: `ROADMAP.md` (fui eu,
  editando o Coverage Map e registrando a Fase 183 **durante** a fase) e, depois, **PHP e duas
  migrations** da sessão paralela que fazia a Fase 184 **na mesma árvore**, com commits
  **intercalados** entre os do plano em curso — e um arquivo dela deixado **staged no índice
  compartilhado**.
  ➡️ `ROADMAP.md`, `REQUIREMENTS-v26.md` e `STATE.md` **não** pertencem a nenhum plano.
  ➡️ Quando houver sessão paralela, **a prova de "não toquei X" é COMMIT POR COMMIT**, não por
  range — e **não rode a suíte da outra área**, que mediria o trabalho alheio.

  ⚠️⚠️ **E NÃO CONFIRA O AUTOR: nesta árvore ele não discrimina nada.** Medido em 13/10/2026
  (plano 180-04B): as duas sessões rodam com o **mesmo** `user.name` (`MB.ECF-100376`), então
  `git log --format=%an` devolve um valor só para tudo. Os "conferi o autor" dos planos anteriores
  estavam, na prática, conferindo o **assunto**. ➡️ **O discriminador real é o ESCOPO do assunto**
  (`feat(180-…)` × `feat(184-…)`). Um gate comparando `%an` com um nome esperado é **vazio por
  construção** aqui — e a orientação "confira o autor" foi erro meu, repetido em vários briefings.
- **Gate de território por range da FASE** acusa o trabalho legítimo do plano dono. ➡️ Use **dois
  ranges**: o que ninguém pode tocar mede do início da fase; o que outro plano possui mede do fim
  do plano anterior.

---

## 4b. Os cinco achados da Fase 186 (14/10/2026) — e um deles NÃO é sobre gate

A Fase 186 (o portão de aprovação, 9 planos, 12 tabelas) rendeu cinco coisas que **nenhuma
leitura de código entregaria**. As quatro primeiras são família dos casos 1-13; a quinta é um
**bug de runtime** que morde em silêncio.

### ⚠️ 4b.1 — A asserção que é VAZIA quando escrita e vira FALSO POSITIVO depois

O pior modo de falhar da lista, porque são **os dois** no mesmo texto, **sem ninguém mexer nela**.

O plano 186-04 escreveu: *"nenhuma tabela do portão tem `oferta_id`"*. Quando foi escrita **não
havia tabela-filha nenhuma** → a lista saía vazia sempre, e a asserção **passava sem medir nada**.
No instante em que o 186-05 criou as filhas, ela **reprovou trabalho CORRETO**: medido no schema
vivo, **cinco** tabelas têm `oferta_id` **legitimamente**, porque os requisitos de histórico e de
ajuste **por oferta** as EXIGEM.

O invariante de verdade era o **PAR** `(oferta_id, imagem_id)` — é ele que faria a aprovação da
FOTO ser por oferta.

➡️ **Pergunte de toda asserção negativa: "o desenho PROÍBE isso, ou eu só não vi acontecer
ainda?"** Negativa escrita antes de o caso existir é suspeita por construção.
➡️ **E a correção certa é reapontar com ISCA INVERTIDA:** aqui, a lista de filhas por oferta tem
de estar **NÃO-vazia** — se vier vazia não é "tudo certo", é a varredura cega ou alguém apagou o
histórico.

### ⚠️ 4b.2 — Contagem que depende de QUANTOS ARQUIVOS (ou tabelas) EXISTEM

**Dois gates da mesma fase caíram nisso**, e os dois reprovaram com **zero teste falhando**:
- uma asserção **por arquivo** de `glob('database/migrations/*pub_aprovacao*.php')` → 5→6 quando a
  3ª migration nasceu, e ia virar 7 e 8 nas waves seguintes;
- uma asserção **por tabela** com `oferta_id` → subia a cada tabela-filha nova.

⚠️ O dano é cumulativo e **social**: elevar a régua três ou quatro vezes **treina o executor
seguinte a ajustar réguas quando elas incomodam**, e um dia ele ajusta uma que importava.
➡️ **Colete os infratores e faça UMA asserção de contagem fixa.** Não se perde nada e se ganha: a
mensagem **nomeia todos** em vez de parar no primeiro.
➡️ E quando a régua **tem** de subir (contagem de arquivo varrido, por exemplo), **meça as duas
pontas, suba MEDINDO, e remova o valor antigo** — mantê-lo aceito faz o gate passar se alguém
**apagar** o que o fez subir.

### ⚠️ 4b.3 — Sonda cujo silêncio depende de UMA LETRA é sorte, não ancoragem

Eu prescrevi uma sonda de ausência pelo par `(aguardand|pendent|…)` × `(autoriza|gestor|aprovad)`.
Medido pelo 186-05: o par casa **10 ocorrências LEGÍTIMAS** na área — *"Aguardando aprovação"* é
vocabulário correto do domínio, e `AJUSTES_SOLICITADOS => APROVADO` é transição válida. A sonda
**só ficava calada por acidente de grafia**: `aprovad` **não casa** a palavra `aprovação`.
➡️ **O discriminador tem de ser o SUJEITO, não as palavras soltas** (a *exceção* esperando, não
qualquer coisa aguardando aprovação). Em pt-BR, **conte com a cedilha e os acentos**, e **confira a
sonda contra o que ELA DEVE casar**, nunca só contra o que deve ignorar.

### ⚠️ 4b.4 — `migrate:rollback --step=1` destrói a migration da sessão PARALELA

O banco local é **compartilhado**, e `--step` rola de volta **o último BATCH global**, decidido no
instante em que roda. Dois planos da mesma wave provaram `migrate → rollback → migrate` ao mesmo
tempo e **cada um derrubou a migration do outro** (uma delas duas vezes, com o batch saltando de 44
para 46).
➡️ A forma segura é **`migrate:rollback --batch=<N> --path=<o seu arquivo>`**. ⚠️ **`--path`
sozinho NÃO basta** — ele filtra o último batch pelo caminho, imprime *"Migration not found"* para
o alheio e **não rola a sua de volta**.
⚠️ E **reaplicar a migration alheia é corretamente BLOQUEADO** pelo classificador
(`[Modify Shared Resources]`): quem derrubou **avisa**, não conserta. Confira a tabela `migrations`
por **reconsulta entre os passos**, nunca por stdout.

### ⚠️⚠️ 4b.5 — Relação Eloquent com nome de propriedade do `Model`: erro CALADO, e o teste de fora PASSA

**Não é sobre gate — é um bug de runtime, e foi MEDIDO duas vezes de forma independente.**
Uma relação chamada `original()` colide com `protected $original` do `Model`
(`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:63`):

```
de DENTRO da classe   $this->original()  => BelongsTo   (certo)
de DENTRO da classe   $this->original    => array       <- os ATRIBUTOS CRUS, calado
de FORA               $obj->original     => NULL / a relação
```

De **dentro** da classe o PHP enxerga a propriedade protegida e **não chama `__get()`**. O serviço
devolvia linhas da tabela errada, e ⚠️ **um teste escrito DE FORA passava** enquanto o código de
dentro estava errado — porque de fora o acessor funciona.
➡️ **Ao criar relação ou acessor, confira o nome contra as ~60 propriedades do `Model`**
(`$original`, `$attributes`, `$changes`, `$relations`, `$casts`, `$table`, `$connection`…). Vale um
gate que varra as relações dos models novos contra essa lista, **com isca exigindo que `original`
esteja nela**.

### ⚠️ Dois extras medidos, para quem mexer em schema

- **`decimal(p,s)` NÃO é legível no SQLite:** `Schema::getColumns` devolve `numeric` **sem
  precisão**. Gate que confira precisão pelo **schema vivo** não tem o que medir na suíte — falha
  sempre, ou vira *"se der para ler, confere"*, que **pula em silêncio**. Leia o **TEXTO da
  migration** e **declare a limitação como asserção medida**.
- **O Eloquent NÃO lê o default do banco:** criar a linha sem o campo grava o default **no banco**
  e deixa **null em memória** — e a primeira transição foi recusada como ilegal por isso. Use
  `$attributes` no model **e** gate comparando com o default lido do **schema vivo**, nunca com
  outra constante do PHP (seriam duas cópias da mesma verdade).
- **Nome de método de guarda genérico COLIDE** entre planos: `--filter` casa os homónimos de vários
  arquivos e **soma as contagens**. Dê sufixo próprio aos seus.

## 4c. Duas lições do ORQUESTRADOR, 14/10/2026 — as duas são erro meu

Não vieram de executor. Vieram de eu fazer e quebrar.

### ⚠️⚠️ 4c.1 — Na campanha de mutação, restaure de uma CÓPIA. NUNCA de `git checkout`

Eu mutei um arquivo para provar um gate e desfiz com `git checkout -- <arquivo>`. O arquivo também
tinha **trabalho meu ainda não commitado** — e o `checkout` restaura o estado **COMMITADO**.
**Perdi a camada de gate inteira**, cerca de 90 linhas, e só percebi porque fui reconferir a
contagem de asserções: tinha voltado de 33 para 15.

⚠️ **`git checkout` está na lista de proibidos deste projeto EXATAMENTE por isso**, e eu o usei
mesmo assim, porque com pathspec ele *parece* a ferramenta de restaurar mutação.

➡️ **A receita, nesta ordem:**
1. **COMMITE o trabalho ANTES de mutar.** Mutação em cima de trabalho não commitado é risco sem
   necessidade.
2. **`cp <arquivo> <scratchpad>/copia-*.ext`** antes de aplicar.
3. Restaure com **`cp` da cópia de volta**, em `finally`.
4. **Confira o sha256 contra `git show HEAD:<arquivo>`**, não contra a memória.
5. Apague as cópias no fim.

⚠️ E note a ordem de quem acerta: um executor desta fase teve o script de mutação **crashar no
meio** e deixar o arquivo mutado no disco — por isso o `finally` é obrigatório, não recomendado.

### ⚠️ 4c.2 — A isca pegou o furo do MEU próprio detector, e por isso ela existe

Escrevi um detector de escrita com `\[[^\]]{0,400}?'campo'\s*=>` para pegar
`update(['campo' => ...])`. As iscas que eu mesmo escrevi reprovaram na hora: **`updateOrCreate`
põe o campo no SEGUNDO array** (`updateOrCreate([chaves], [valores])`), e `[^\]]` **não atravessa**
o `]` que fecha o primeiro.

➡️ O limite certo era o **`;`** (fim do statement), não o `]` — e ficar dentro do statement é
**também** o que evita o falso positivo da chave de saída num `return`.
➡️ **Isca não é cerimônia: é o único jeito de saber se a sonda enxerga o que ela diz enxergar.**
Escreva uma isca **por FORMA** que a sonda deve pegar, e **uma que ela NÃO pode pegar**. Sonda sem
isca para o caso positivo é sonda que você **espera** que funcione.

### ⚠️ O padrão por trás dos dois: "lista de nomes" não é "prova de comportamento"

Um gate guardava o invariante *"só um lugar escreve o estado"* com a **lista fechada dos arquivos
que mencionam o campo**. Parecia forte, e tinha isca. Mas **entrar na lista não era conferido por
nada**: quem acrescentasse um arquivo ali — por engano, ou para "fazer passar" — **passava a poder
escrever sem gate nenhum notar**. A lista protegia a porta **por NOME**, não **por COMPORTAMENTO**.

➡️ Quando o gate tem uma **lista de exceções**, pergunte: **o que impede alguém de entrar nela?**
Se a resposta for "nada", a lista é a própria porta dos fundos. O conserto não é fechar a lista —
é **pôr ao lado dela um gate que meça o COMPORTAMENTO** de quem está dentro.

### ⚠️⚠️ 4c.3 — O SCRATCHPAD É COMPARTILHADO entre subagentes lançados EM PARALELO (18/10/2026)

**Terceira lição do orquestrador, e ela também é erro meu.**

Lancei as waves **187-09** e **187-10** **em paralelo**, pela mesma sessão. **As duas receberam o
MESMO diretório de scratchpad** — e as duas escreveram o script de mutação com nome **genérico**
(`mutar.sh`, `de.txt`, `para.txt`, `out.txt`). ➡️ **O `mutar.sh` da wave 10 foi SOBRESCRITO pelo da
wave 09 no meio do trabalho.**

| quem | o que fez |
|---|---|
| **wave 10** | **detectou** por `sha256` que o próprio script auxiliar havia mudado debaixo dela |
| **wave 09** | **refez a campanha de mutação INTEIRA**, com `…-187-09-waveNOVE` em **todos** os nomes, e rodou com a wave 10 **já fechada** — sem colisão possível |
| **as duas** | conferiram, por `sha256` contra `git show HEAD:`, que **nenhum arquivo de produção se perdeu** |

➡️ **A LIÇÃO EXATA:** **conclusão de mutação tirada com script auxiliar de NOME GENÉRICO, em
execução PARALELA, NÃO VALE — o nome do script precisa CARREGAR O PLANO.**

⚠️ **E note o que torna isto perigoso em silêncio:** o dano não é o script sumir — é o script
**continuar lá, com outro conteúdo**. A campanha segue rodando, imprime "MORREU"/"SOBREVIVEU" e o
veredicto parece normal. **Mutação é a última linha de defesa contra gate vazio; se a ferramenta dela
for trocada no meio, perde-se a única prova de que os gates medem algo.**

➡️ **A receita, acrescentada à de §4c.1:**
- **todo** arquivo auxiliar no scratchpad leva o identificador do plano no nome
  (`mutar-187-11.sh`, `sha-187-11.sh`, `out-187-11-<rotulo>.txt`, `copia-187-11-<caminho>`);
- em execução paralela, **conferir o `sha256` do próprio script auxiliar** antes de confiar no
  veredicto;
- e **nada** no scratchpad é tratado como privado da sessão: ele é **compartilhado**, como o
  `refs/stash` do git é compartilhado entre worktrees.

### ⚠️ 4c.4 — "Gate mais LARGO" e "gate mais ESTREITO" dão o MESMO prejuízo (18/10/2026)

A lista das armadilhas deste arquivo enfatiza o gate **largo** (o que passa calado). O fechamento da
Fase 187 mediu, no **próprio** gate recém-escrito, os **dois** lados no mesmo dia:

| direção | caso medido | efeito |
|---|---|---|
| **LARGO** | contar "itens numerados" no documento **inteiro** em vez de na seção do roteiro: media **23** em vez de 8 | reprova **trabalho certo**, e o conserto barato é **afrouxar** |
| **ESTREITO** | procurar `não há desfazer` com **espaço literal**, num markdown de 100 colunas onde a frase **quebra** no meio | reprova **trabalho certo** pelo mesmo motivo oposto |
| **VAZIO por construção** | recortar a seção com `.` sob a flag **`/s`**: o `.*$` do cabeçalho engole o **documento inteiro** e o corpo sai **VAZIO** | o gate mede **nada** e reprova com lista vazia |

➡️ **O gate que reprova trabalho certo é tão caro quanto o que passa calado**, porque é ele que
**ensina o executor seguinte a mexer na régua**. ⚠️ E o terceiro caso é a lembrança de que, em PCRE,
**`/s` muda o significado de `.` e portanto o de `$`** — num recorte de seção, use `[^\n]*` para a
linha do cabeçalho e `[\s\S]*?` para o corpo, **nunca `.` com `/s`**.

## 5. Asserção negativa exige medir se o literal é EXCLUSIVO

Ao proibir um texto na tela, confira que ele não é legítimo em outro lugar. Medido na Fase 179,
ao remover dois blocos: **"Fase 1 publicada"** é rótulo de um estágio da Jornada e
**"Sem oferta"** é **substring de "Sem ofertas no Portal"**, que o header renderiza **na mesma
página**. Negativa cega ali quebraria a tela certa.

➡️ Recorte a negativa ao **corpo** por índices absolutos, ou prove fonte única no fixture.

---

## 6. Mutação: como ela mente

A prova por mutação é o que pegou os furos reais — mas ela erra de três formas:

1. **Alvo com mais de uma ocorrência**, a segunda em **comentário**: `sed`/`String.replace` troca
   só a primeira e registra um furo inexistente. ➡️ **Trave a contagem antes de aplicar (recuse se
   ≠ 1) e confira QUAL linha mudou.** Na Fase 179, **8 mutações foram recusadas ou refeitas** por isso.
2. **Equivalência por construção:** `tomorrow()` → `now()->addDay()` não muda nada porque o
   `setTime()` normaliza. Não conta como reprovação.
3. ⚠️ **Mutação que SOBREVIVE não é "equivalente" até você provar que é.** Três vezes nesta fase a
   sobrevivente era **lacuna de cobertura real** — uma delas mudaria em silêncio o
   "N Fase 1 · N kits" da tela de **Produtos**, outra tela.

➡️ **Restaure por cópia pristina e confira por `sha256`** no fim de cada rodada.

---

## 7. Armadilhas de render deste projeto

- ⚠️ **A tela preta de 07/10/2026:** um campo do presenter que chegou como **objeto** e foi
  renderizado como texto derrubou a **página inteira**. ➡️ **Render REAL** (esbuild +
  `react-dom/server`) com cada campo **objeto, nulo e ausente**; coleção nula/string/número; chave
  desconhecida; a prop inteira nula; e `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`.
  Na Fase 178, **grupo nulo derrubava o painel inteiro** e **só o render adverso pegou**.
- ⚠️ **O renderizador de servidor do React NÃO suporta portal** — o corpo de um modal portado não
  aparece no HTML. ➡️ Exporte as peças internas como named exports para poder conferi-las.
- ⚠️ **Conteúdo portado não herda a fonte** — os dois `Popover.Content` e o modal tiveram de
  declarar `font-pub` por conta própria. Sem isso renderizam em Inter.
- ⚠️ **Rollup elimina variável de escopo do componente usada dentro de `.map()`** → `ReferenceError`
  só no bundle de produção. **Toda flag nasce DENTRO do callback.**
- ⚠️ **`node --test`: monte TODOS os bundles ANTES do primeiro `test()`.** O `after()` dispara
  quando os testes registrados terminam e já matou um arquivo inteiro **sem nenhum teste falhar**
  (sintoma: 37 testes em vez de 94).
- ⚠️ **Harness de esbuild que bundla o `PublicadorHeader` precisa de `'@radix-ui/react-dialog'` no
  `external`** — a página arrasta o Dialog → `react-remove-scroll` (CJS) → `Dynamic require of "react"`.
  Harness que só lê a fonte não precisa.
- ⚠️ **`textoSeguro(NaN)` devolve a string `'NaN'`** (`typeof NaN` é `'number'`), e **`textoUtil(7)`
  devolve `'7'`** — que montava rota inválida três vezes nesta fase. **Campo que vai para URL ou
  para frase exige STRING de forma fechada.**
- ⚠️ **`JSON.stringify` mente na mensagem de asserção:** `NaN` e `Infinity` viram `'null'`.
  ⚠️ **E `String(valor)` LANÇA** quando o valor é `{toString: null}` — um dos casos do próprio
  render adverso. No plano 180-05 isso matou **5 blocos** de teste **dentro da mensagem de
  asserção**, não no código testado.
  ⚠️⚠️ **E `Object.prototype.hasOwnProperty.call(obj, chave)` LANÇA**
  `TypeError: Cannot convert object to primitive value` quando a chave é `{toString: null}` — a busca
  de chave **coage para string**, igual ao `String()`. Medido em 13/10/2026 (plano 180-07):
  derrubava a **página inteira** em dois componentes, e **só o render adverso pegou**. ➡️ Guarda de
  tipo **antes** do `hasOwnProperty`, sempre que a chave vier do servidor.
  ➡️ Monte a mensagem com algo que não possa lançar (tipo +
  descrição fixa), nunca com `String()` nem `JSON.stringify()` do valor adverso.
- ⚠️ **`lerSemComentarios()` mastiga o glob do `tailwind.config.js`** (`/*` seguido de `*/`). Leia cru.
- ⚠️ **Data por REGEX, nunca `new Date()`** — `Invalid Date` e o deslocamento de fuso de data-only em BRT.
- ⚠️ **Teste dependente de `now()`** reprova por horário: um caso com `subHours(9)` falhava entre
  00:00 e 09:00 e passava depois. **Ancore com `Carbon::setTestNow()`.**

---

## 8. Marcação de fase: as ferramentas falham em SILÊNCIO

- **`roadmap update-plan-progress` reporta sucesso e NÃO escreve nada** nesta milestone.
- **`requirements.mark-complete` devolve `not_found`** para IDs de `REQUIREMENTS-vNN.md`.
- **`REQUIREMENTS-v26.md` não tem checkbox nenhum** — os requisitos são bullets; não há o que marcar.
- O que existe é o **Coverage Map** no `ROADMAP.md` e a linha `**Plans**:` de cada fase.
  ➡️ **Marque as duas à mão ao fechar a fase.**
- ⚠️ **`gsd-sdk query state.advance-plan` já corrompeu o `STATE.md` deste projeto seis vezes.** Não
  rode. Escreva **bloco aditivo à mão** e confira por `git diff --numstat` que removeu 0 linhas.

---

## 9. Números de referência (13/10/2026, fim da Fase 179)

- `tests/{Unit,Feature}/Publicador`: **2007 testes / 14.953 asserções / 0 falhas**, ~7min30 cheia.
  **54 `PHPUnit Deprecations` são pré-existentes**, todas por `@group phaseNNN` em doc-comment —
  convenção de 75 arquivos deste projeto. Fora de escopo.
- `npm run test:js`: **3737 / 3736 pass / 1 fail**. ⚠️ **A falha é a pré-existente
  `tests/js/estrutura-grade-glide.test.js:122`** ("Características secundárias nasce recolhido") —
  **não corrigir**, é conhecida e intencional.
- `npm run build`: 30 s a 2 min. ⚠️ **Manifest sempre pelo JSON** (`node --input-type=commonjs`,
  porque o `package.json` é `"type":"module"`), **nunca por grep** — o hash sai com hífen.
  **Página React fora do manifest SOME da aplicação.**
- ⚠️ `artisan test --filter=` numa classe inteira **passa dos 120 s do shell** — use filtro por método.
  `artisan tinker` precisa de `< /dev/null`.

---

## 10. Dívidas conhecidas, deixadas de propósito

- **N+1 em `PainelVisaoGeralService::resolverAtor()`** (um `User::find()` por publicação). A página
  **não** cresce com produto (65 consultas com 12 e com 24) — cresce com **publicação** (91 com 12).
  ⚠️ O próprio arquivo tem o padrão certo em `:976` (`whereIn`) e o docblock de `:860` já proíbe
  `User::find()` por linha. **Consertar ali resolve sem tocar prop nem asserção.**
- **`produtosPorFase` e `quemPublicou` continuam chegando como prop sem render**, por decisão de
  arquitetura: parar de enviá-las exigiria cirurgia em 14 asserções, uma delas um teste cujo
  propósito é provar que o contrato não perde prop. **Prop órfã ali é proposital.**
- **Os dois blocos saíram da tela por decisão informada do usuário em 12/10/2026**, exceção
  explícita à regra "melhorar sem regredir". ⚠️ **Não recriar sem decisão NOVA dele.**
- **Falta `cursor-pointer`** em `SeletorDeEmpresaDoHeader.jsx:171` — o protótipo o declara (`:53`).
- **`MapaDeCobertura`** entra com as 4 células seguidas; o protótipo separa `F1 [C][P]` de
  `F2 [C][P]` com rótulo próprio. Conserto exige fase que possa tocar `identidade/`.
