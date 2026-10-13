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

---

## 3. As seis armadilhas do CSS construído

Presença de classe **não é** fidelidade: classe presente com valor errado passa por qualquer gate
de presença. Ler o corpo da regra é obrigatório — e o medidor erra de seis maneiras:

1. **Token sólido NÃO compila para hex** — sai `rgb(38 38 38 / var(--tw-bg-opacity, 1))`, decimal.
   Esperar hex deu **25 falsas faltas**.
2. **`stroke`/`fill` sólidos, ao contrário, SAEM EM HEX LITERAL.** (2 falsas faltas.)
3. **O modificador de opacidade compila para hex de 8 dígitos**, e o byte do alfa **prova** a
   opacidade — é o único jeito de distinguir `0.13` de `0.12`. E `0.10` arredonda para `0x1a`.
4. **O cssnano tira o zero da frente, inclusive depois do sinal de menos:** `-0.03em` → `-.03em`.
5. **O CSS escapa o seletor com barra invertida** (`.text-\[13\.5px\]`) — 54 falsas faltas.
6. **Na variante, o prefixo faz parte do nome da classe:** `.hover\:bg-pub-hover-0:hover`.
7. ⚠️ **Escapar SÓ a barra invertida quebra em classe com colchete e ponto.** Escapar apenas o
   caractere de barra funciona em `.hover:bg-…`, mas em `.text-[15.5px]` os colchetes e o ponto
   sobrevivem sem escape e a regex passa a ler **classe de caracteres** em vez de literais — a regra
   **presente e correta** sai como AUSENTE.
   ➡️ **Escape TODOS os metacaracteres de regex** (use `RegExp.escape` ou equivalente), não só a
   barra. (5ª mentira da sonda na milestone, no plano 180-01.)

➡️ **Toda sonda carrega ISCA**, e a isca tem de ser **classe inexistente no projeto inteiro** —
`w-[13px]` parecia inventada e saiu presente.
➡️ **Regex em ARQUIVO escrito por ferramenta.** Nunca `node -e` (o escape do shell mastiga) e
**nunca heredoc — ele colapsa `\\` em `\` mesmo com `<<'EOF'`**.

---

## 4. A família das asserções que casam sempre — nove casos medidos

Este projeto já foi mordido nove vezes pela mesma classe de erro: asserção que passa
**independentemente do código**.

1. `assert.match(tag, /disabled/)` casa com a classe `disabled:opacity-40` → use `/disabled=/`.
2. `doesNotMatch(html, /0%/)` casa com os dois últimos caracteres de `100%`.
3. `git diff --stat -- <dir>` **depois de commitar** compara a árvore e sai vazio **sempre** → use
   `<inicial>..HEAD`.
4. `html.includes('classe')` quando vários elementos compartilham a classe. **Substring casa com
   prefixo:** `font-pub` dentro de `font-pub-mono`, `text-[11px]` dentro de `text-[11.5px]`,
   `z-3` dentro de `z-30`, `bg-pub-raised` dentro de `bg-pub-raised-0`.
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
9. ⚠️ **Asserção de ausência rodada com dado VAZIO é vazia por construção.** Se você assertar que
   um bloco não aparece, rode **com as props preenchidas**.

➡️ **A pergunta que desmascara todos:** *"o que precisaria estar errado no código para este gate
falhar?"* Sem resposta concreta, o gate é decorativo. **Cada uma das 11 waves da Fase 179 pegou um
gate PRÓPRIO vazio por construção com essa pergunta.**

### E dois gates que são vazios AO CONTRÁRIO

- **"Asserções removidas = ZERO"** por `git diff | grep '^-' | grep assert` **acusa remoção onde não
  houve**: reapontar `assertSame(1,…)` para `assertSame(2,…)` produz uma linha `-assert` por
  construção. ➡️ Exija o par `-`/`+` **no mesmo teste** e `+assert > -assert`, enumerando cada um.
- **Gate de território por range da FASE** acusa o trabalho legítimo do plano dono. ➡️ Use **dois
  ranges**: o que ninguém pode tocar mede do início da fase; o que outro plano possui mede do fim
  do plano anterior.

---

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
