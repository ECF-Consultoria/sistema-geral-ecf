# Para quem for planejar a Fase 189 (o lightbox) — o que **não** está dedutível do código

**De:** sessão da v27.0 (Fases 186→188, "Aprovação humana antes de publicar")
**Em:** 19/10/2026
**Para:** a sessão que for planejar a **Fase 189 — Imagem ampliada / lightbox com zoom de 2,5×**

> **Leia isto ANTES de planejar.** Memória de sessão não atravessa máquinas; só o repositório
> atravessa. Tudo abaixo foi **medido** nesta árvore, e cada item já custou tempo de alguém.

---

## 0. O estado agora — e por que isso muda o seu plano

| fase | estado |
|---|---|
| **186** (o portão no servidor) | ✅ **em produção** (`a4c4187f`) |
| **187** (a fila "Aguardando aprovação") | ✅ **em produção** (`7791d7aa`, 18/10/2026) |
| **188** (o painel de 1140px) | 🔄 **em execução: 2 de 13 waves** |
| **189** (o lightbox) | ⬜ a planejar — **é você** |

⚠️⚠️ **A Fase 189 depende da 188, e a 188 NÃO ESTÁ PRONTA.** O lightbox abre **a partir do painel** e
**repete as ações de imagem dele**. ➡️ **Planeje contra o que a 188 VAI entregar, não contra o que
existe hoje no disco** — e **cite o plano da 188, não o código**, onde o alvo ainda não nasceu:
`.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/`
(13 `PLAN.md` + `188-REGUA.md` + `188-FID01.md` + `188-COBERTURA.md`).

⚠️ **O botão "⤢ Ampliar" é SEU, e a 188 NÃO o desenha de propósito** — há gate de ausência nela. Não
presuma que ele existe.

---

## 1. ⚠️ EXECUTAR em paralelo **não dá**. Planejar, dá.

Três travas **medidas**, e a primeira é absoluta:

1. ⚠️⚠️ **`tests/js/publicador-vocabulario-novo.test.js` compara POR IGUALDADE** a lista
   `ARQUIVOS_NOVOS` com o que existe no disco (37 pontos de verificação), e **pré-declarar é
   impossível**: a constante `FONTES` lê todos os caminhos e **LANÇA** com um inexistente.
   ➡️ **Toda fase que cria `.jsx` edita aquele arquivo.** Duas sessões criando componente ao mesmo
   tempo deixam uma vermelha. **Foi por isso que a 187 teve 11 planos em 11 waves e a 188 tem 13.**
2. **`npm run build` é artefato global** — dois builds simultâneos corrompem o manifest que os gates
   conferem, e **página fora do manifest SOME da tela**.
3. **Corrida de índice do git**: a árvore é compartilhada. Use **sempre** `git add -- <caminho>` e
   pathspec também no `git commit -- <caminho>`. **NUNCA** `git add -A`, `git stash`, `git reset`,
   `git checkout`, `git commit --amend`, `git clean`. ⚠️ **Há um `stash@{0}` de outra sessão na
   árvore — não rode `git stash` em forma nenhuma.**

---

## 2. ⚠️⚠️ OS NOVE CAMINHOS FANTASMA — com o lugar CERTO de cada

**Nove** caminhos citados em planos desta milestone **não existem**, e **três vieram de briefing do
orquestrador**. Caminho fantasma num plano custa uma tarefa inteira do executor.
➡️ **Rode `test -e` em CADA caminho que você escrever.** Os já medidos, com o real ao lado:

| citado errado | o REAL (medido em 19/10) |
|---|---|
| `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` | **`app/Services/Publicador/Fila/ResumoRapidoService.php`** |
| `.../Aprovacao/SimuladorVoceRecebe.php` | **`app/Support/Publicador/Validacao/SimuladorVoceRecebe.php`** |
| `.../Aprovacao/AnaliseAlavancasService.php` | **`app/Services/Publicador/Alavancas/AnaliseAlavancasService.php`** |
| `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` | **`tests/Feature/Publicador/CockpitDaContaTest.php`** |
| `resources/js/Components/Mlb/Publicador/useCriativosDoPublicador.js` | **`resources/js/Components/Publicador/useCriativosDoPublicador.js`** |

⚠️ **O mais perigoso é o primeiro:** o `ResumoRapidoService` **existe**, e confundi-lo com o
`FilaDeAprovacaoService` **apagaria a decisão 9 do usuário** — a que diz que há **duas** margens de
propósito no projeto (`ResumoRapidoService:384-391` é a da visão rápida, com o parâmetro da
Precificação; a do portão usa a **tarifa real do ML**). O docblock do `EditorRascunhoService:370-372`
diz textualmente **"diferente DE PROPÓSITO… não unificar"**.

---

## 3. ⚠️⚠️ O PROTÓTIPO — e onde os ponteiros mentem

**Arquivo:** `design_handoff_publicador/Aprovacao.dc.html` — **1432 linhas**,
`sha256 68624883ce35547e` (medido 4× entre 15 e 19/10: **não mudou**).

⚠️ **CITE SEMPRE O DIRETÓRIO:** existem **duas cópias** do pacote (`design_handoff_publicador/` e
`new_layout_publicador/`) **com conteúdo DIFERENTE**. E `design_handoff_publicador/` é **somente
leitura e NÃO versionado** (`??`) — gate que dependa de lê-lo **degrada para `skip` EXPLÍCITO e
NOMEADO**, senão não mede nada na máquina do outro dev.

⚠️⚠️ **OS PONTEIROS DE LINHA ERRAM MUITO.** Placar medido wave por wave na 187:
**9/16 · 3 · 7/12 · 8/9 · 13/20 · 11/17 · 19/25**. Na 188 a wave 1 re-mediu e achou **33
divergências — 20 por REFERÊNCIA, 11 por OMISSÃO, 2 por VALOR, e ZERO de medida.**
➡️ **A tabela erra por OMISSÃO e por REFERÊNCIA, quase nunca por valor. Confirme CADA ponteiro por
`sed`, e trate omissão como o erro mais provável.**

⚠️⚠️ **A REGIÃO DO VIEW-MODEL (`:1076`-`:1320`) ESTÁ PIOR, e por muito tempo ninguém a conferiu** — a
re-medição da wave 1 cobriu só HTML/CSS. A wave 2 da 188 achou lá **6 ponteiros deslocados, com
desvio de −1 a −10**: `:1309`→`:1301` · `:1304`→`:1295` (2×) · `:1307`→`:1297` · `:1264`→`:1263` (a
linha está **vazia**) · `:1317`→`:1318` · `:1171`→`:1172`.

⚠️ **Prova independente:** o código da Fase 186 **em produção** já cita `:1295`/`:1297`
(`AprovacaoDaOfertaService:402`, `PainelDeRevisaoService:57`/`:65`). **Corrigidos em
`188-FID01.md` §J.** ➡️ **Reconfira por `sed` qualquer `:1NNN`.**

⚠️ **Omissões load-bearing que a re-medição achou** (sem elas a tela quebra, não enfeita): o wrapper
`:604` (`right:10px;bottom:10px`) **estava ausente do documento** e era a causa-raiz de três
ponteiros deslocados; `:564` (`min-width:0`) e `:567`/`:680` (`flex-wrap:wrap`) omitidos — **sem eles
o título não trunca e os chips vazam**.

➡️ **Prescreva `cursor`, `white-space` e `text-overflow` em COLUNAS PRÓPRIAS** na sua tabela FID-01:
`cursor:pointer` foi omitido **onze** vezes numa fase, e `white-space:nowrap` **sete** na 187 — **três
delas sendo a PRIMEIRA propriedade da linha**.

---

## 4. ⚠️⚠️ O PROTÓTIPO GANHA — exceto 3 vezes, e NUNCA em dinheiro

**Placar da milestone: ~146 a 3 a favor dele.** **MEÇA no `.dc.html`; ele ganha em VALORES, RÓTULOS e
ORDEM.** Divergência vai na SUMMARY com `arquivo:linha`, confirmada pela **SEMÂNTICA**.
⚠️ **DECLARE COMO MEDIU: por SCRIPT é confiável, por LEITURA não é.**

⚠️ **As TRÊS derrotas dele, todas por conflito entre DUAS regras do projeto resolvido PELO USUÁRIO —
nunca por erro do protótipo. NÃO as "conserte de volta":**

1. **`'3 dias'` por extenso** contra o `' d'` dele — e `min`/`h` **continuam abreviados de propósito**
   (*"não uniformize"* está no docblock).
2. **O toast do lote mantém o vocabulário travado** (*"Aprovado — aguardando publicação"*) contra o
   *"N ofertas aprovadas"* de `:1167`.
3. **No painel, o cabeçalho "VERIFICAÇÃO AUTOMÁTICA · APOIO" uma vez + o ordinal por item** — o
   protótipo escreve minúsculo e sem cabeçalho em `:633` (o literal em maiúsculas só existe em
   `:369`, que é a revisão detalhada). ⚠️ **Razão do usuário: o cabeçalho é o que diz APOIO — sem ele
   a verificação automática pode ser lida como VEREDICTO.** ⚠️ **E o ordinal é FUNCIONAL: liga o item
   ao marcador x/y sobre a foto — isso é o seu lightbox.**

⚠️⚠️ **E ele NUNCA ganha nos NÚMEROS de dinheiro:** as taxas de `fin()` (`:1064-1066`) são **ficção
declarada** (*"taxa de exemplo"*, `src:'est'`), e com as fontes reais o `o1` **inverte de aprovável
para bloqueado**. ⚠️ **A seção F do `README-v2.md` é PROSA e já contradisse o código:** chamou de
"margem baixa" um caso que dá **prejuízo**. **Só `info()` decide bloqueio.**

⚠️ **Se você achar um QUARTO conflito protótipo × regra do projeto: PARE e devolva a pergunta ao
usuário. Não decida** — as três acima foram todas decisão dele.

---

## 5. ⚠️⚠️ O PORTAL — a armadilha que mais importa para VOCÊ

O seu critério 1 pede o lightbox **por portal, acima do painel, sem alterar o header**. Duas coisas:

1. O `backdrop-filter` do `PublicadorHeader` **cria containing block para `position:fixed`** — sem
   portal, a peça fica presa a ele.
2. ⚠️⚠️ **O renderizador de SERVIDOR do React NÃO RENDERIZA PORTAL** — o seu teste de render veria
   **vazio e PASSARIA POR OMISSÃO.** ➡️ **A Fase 178 resolveu exportando as peças internas como
   NAMED EXPORTS e testando-as diretamente; a 187-09 e a 188-07 repetiram. Use essa solução e exija
   que o docblock declare POR QUE ela existe.**

⚠️ **A ordem de camadas é invariante MEDIDO de um bug que o usuário relatou em 12/10**
(`tests/js/publicador-header-zindex-ordem.test.js`): `filtros 10 < header 30 < menu lateral e painel 40`.
No disco hoje: header `z-30`, `BarraDeSelecao` `z-[35]`, backdrop do painel `z-[39]`, painel `z-[40]`,
`CascaDeModal` `z-[42]`, `ToastDaFila` `z-[45]`, popovers do header `z-[61]`, `ModalNovaPublicacao`
`z-[80]`.

➡️ **O seu lightbox fica ACIMA do painel. REMAPEIE preservando a ORDEM RELATIVA — NUNCA copie os
números do protótipo** (lá o header é 50 e as peças usam 44/45/60/100/120).

⚠️ **E gate com LISTA LARGA de números do protótipo REPROVA CÓDIGO CERTO:** a 187-09 mediu que `45` é
o número do **popover** e o toast legitimamente ficou em `z-[45]`. **Prove pela ORDEM, não pelos
números.**

⚠️ **Medido: os popovers NÃO precisam de portal** (são `absolute` dentro de pai `relative`). **Não
confunda os casos, não "conserte" os popovers.**

⚠️ **Medido na 187-09: peça `fixed` como "irmã do `<main>`" é IMPOSSÍVEL** sem tocar `AppLayout.jsx`
(**intocável**) — elas são irmãs do `PublicadorHeader`, e isso **preserva** o invariante. E o
`backdrop-blur-md` de `AppLayout:1068` está **FORA do `</main>`**: sonda que varre até o fim do
arquivo reprova código certo.

---

## 6. ⚠️⚠️ A TELA PRETA — voltou SEIS vezes neste projeto

Causa, medida: `String(valor)`, `hasOwnProperty` e **qualquer `obj[chave]`** **LANÇAM** com
`{toString:null}`; `textoSeguro(NaN)` devolve `'NaN'`; e **prop de NÓ chegando objeto comum faz o
React lançar e MATAR A PÁGINA INTEIRA**. Voltou **quatro** vezes na Fase 180 e **uma** na 187-06 —
essa última **no CLIQUE**, não no render.

➡️ **Render REAL** (esbuild + `react-dom/server`) com cada campo **objeto, nulo, ausente, NaN e
array**, a prop inteira nula, **e TAMBÉM no CLIQUE** (a 187-08 exportou o handler justamente porque
render estático não dispara `onClick`); `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`;
e **nunca** `String()`/`JSON.stringify()` na mensagem de asserção.

⚠️ **Há gate da 187-04 que PROÍBE `String(` na página — ele está CERTO.**

⚠️ **Outras armadilhas de tela, todas medidas:**

- **Rollup elimina variável de escopo do componente usada dentro de `.map()`** → **flag DENTRO do
  callback**. (Você mapeia miniaturas, marcadores e a lateral de 320px.)
- ⚠️ **`tailwind.config.js` varre `*.jsx` e NÃO `*.js`** — classe num `.js` **não gera CSS e não
  existe para a tela**, com render verde. **Toda PEÇA nova é `.jsx`.** ⚠️ **Mas o invariante certo é
  "nenhuma PEÇA nova sob `resources/` é `.js`"**: a 187-11 mediu que o *"nenhum arquivo novo é `.js`"*
  de um plano era **literalmente FALSO** (havia 9 `.js`, todos **testes**).
- ⚠️ **`duration-[Nms]`, `delay-[…]` e `ease-[…]` NÃO COMPILAM** (`tailwindcss-animate` torna a classe
  ambígua) → `style={{transitionDuration}}`, com `duration-200` como controle.
- ⚠️ **Timer é FUNÇÃO PURA e o runtime NÃO é provado** (não há navegador) — **declare a limitação**.
  Isso vale para o **zoom que segue o cursor**: você prova a FUNÇÃO, não o movimento.
- **NADA de `localStorage`** nem *"Restaurar dados de exemplo"* — é artefato do protótipo.
- **`npm run build` obrigatório**; manifest **pelo JSON** (`node --input-type=commonjs`), **nunca por
  grep** — o hash sai **com hífen** (`assets/app--5yuaubs.js`) e grep não casa.

---

## 7. ⚠️ GATES — a régua, e as armadilhas que NÃO podem repetir

⚠️⚠️ **Use `tests/gates/verificar-testes.sh` em TODO gate, e o 1º argumento é `189-NN`, NÃO só o
número** — `03` sozinho resolve `contagens-186-03.txt`, **a fase ERRADA, em silêncio** (a
compatibilidade é DE PROPÓSITO: a 186 está em produção e seus 163 ids são chamados pelo número nu; os
casos 15/16 do `--autoteste` guardam isso). **`--autoteste` passa 20/0.**

⚠️ **Cada plano cria `tests/gates/contagens-189-NN.txt`; manifesto incompleto ABORTA.** ⚠️ **Escreva
o manifesto na MESMA tarefa que precisa dele, ANTES do verify** — e **registre `.jsx` em
`ARQUIVOS_NOVOS` na mesma tarefa que o cria**, senão o gate fica **vermelho no meio do plano** (e
árvore vermelha no HEAD pode **barrar deploy**).

⚠️ **Declare `vocabulario-tamanhos` em LINHA PRÓPRIA** no seu manifesto, forma **`>=`**, com o **N
MEDIDO na hora** (hoje **548**; a 187 cravou `>=443`). **Convenção medida: o id está declarado nos DEZ
manifestos da 187, um por wave.** ⚠️ **O proibido é só embuti-lo num id que roda OUTROS arquivos** —
foi isso que fez a 187-05 reprovar dois ids alheios e exigir consertar quatro manifestos.

⚠️ **NÃO chame `node --test`/`artisan test` direto nos gates:** o reporter do Node v24 emite **`ℹ`** e
não `#`, então `grep "^# tests"` **NUNCA casa**; e `grep -E "Tests:|OK"` **PASSA COM TESTE FALHANDO**
— o filtro do `error` é obrigatório.

⚠️ **Nenhum id com mais de TRÊS métodos** (alternância grande = filtro de classe, passa dos 120 s do
shell, o helper **RECUSA**). ⚠️ **Zero id órfão** (no manifesto fora de `<verify>` = gate sobre zero
casos). ⚠️ **Bijeção ids ↔ métodos por script, nos DOIS sentidos.**

⚠️⚠️ **HOMÔNIMO:** o `--filter` do PHPUnit é regex **NÃO ANCORADO**, e as Fases **186, 187 e 188**
numeram planos de 01 em diante — **todo `_0N` seu tem homônimo possível em fase EM PRODUÇÃO**. Método
**superstring** de um delas faz o id daquela fase **SOMAR** e reprovar. ➡️ **Confira por script nas
duas direções contra todo `test_*` de `tests/`; o conserto é sufixo no método NOVO (`…_189_NN`) —
NUNCA levantar a contagem do manifesto alheio.**

**As treze armadilhas** (`.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md` é a régua
completa — **LEIA-A**):

1. **Teste sem id no manifesto NUNCA RODA.**
2. **Palavra no DOCBLOCK que explica "eu não uso isso" faz o gate reprovar pelo próprio conserto** →
   **ler código SEM COMENTÁRIO**.
3. **Teste toda regex contra um caso que DEVE casar** (as waves acharam 2+1+2+4+3+9 furos nas
   **próprias** sondas).
4. **Gate sobre ZERO casos não prova nada.**
5. ⚠️ **Contagem que depende de quantos arquivos/tabelas/commits/linhas EXISTEM é frágil — NOVE
   ocorrências, todas consertadas na RAIZ** (infratores **coletados** numa asserção de contagem
   fixa); ⚠️ **e "piso" (`>= N`) NÃO prova PRESENÇA** — com 13 itens obrigatórios, perder um deixava
   12 e o piso passava. **Conserto: lista CONSTANTE. NUNCA elevar o piso.**
6. ⚠️ **Asserção VAZIA quando escrita que vira FALSO POSITIVO depois** — **de toda negativa pergunte:
   o desenho PROÍBE isso, ou eu só não vi ainda?**; use **ISCA INVERTIDA** quando a lista legítima
   não pode estar vazia.
7. **Silêncio que depende de UMA LETRA é sorte** (`aprovad` **não casa** `aprovação`).
8. ⚠️⚠️ **GATE MAIS LARGO QUE O INVARIANTE — DOZE ocorrências**: três reprovaram **trabalho correto
   de outra sessão**, uma **BARROU UM DEPLOY** (o MCP da Shopee usa `'andamento'` como status de
   promoção — **palavra comum do português**), e uma **empurrava para o conserto errado** (renomear
   uma variável para a régua passar). ➡️ **ANCORE NO COMPORTAMENTO (o operador de chamada), NÃO NA
   PALAVRA**, e **gate não cobra vocabulário do projeto inteiro: recorte à lista CONSTANTE dos
   arquivos da fase, com isca do recorte.**
9. ⚠️⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore** — a 187-11 mediu 111 commits no
   range, **43 da sessão paralela**, e o diff cru **acusava 12 dos 60 alvos vetados**. ➡️ **Use
   ESCOPO DO ASSUNTO, duas passadas independentes, isca nas duas direções.** ⚠️ **E NÃO confira
   autor: as três sessões usam o MESMO `user.name` (`MB.ECF-100376`)** — o discriminador é o **escopo
   do assunto**.
10. **"Lista de nomes" não é "prova de comportamento"** — entrada em lista de exceções tem de ser
    **PROVADA**: o item **tem** de casar a sonda larga (senão a exceção é linha morta) **e** não pode
    ter a forma proibida.
11. **Nome de guarda genérico COLIDE e o `--filter` SOMA** → sufixo próprio.
12. ⚠️⚠️ **Toda sonda precisa de ISCA — uma por FORMA que deve pegar, e uma que NÃO pode pegar.**
    ⚠️ **Pergunte de cada gate: "o que precisaria estar errado no mundo para ele falhar?" — "Nada" é
    ACHADO.**
13. ⚠️ **`/disabled=/` e NUNCA `/disabled/`**, que casa com `disabled:opacity-40`. Mesma família do
    `/0%/` que casava "100%", e do `includes('h-[64px]')` que casava `min-h-[64px]`.

⚠️ **As OITO normalizações do CSS construído** estão no learnings §3 — **leia antes de escrever
sonda**: token sólido compila `rgb(R G B / var(…))` **decimal**; `stroke`/`fill` sólidos saem em
**hex**; o modificador de opacidade é **hex de 8 dígitos** em `background`/`border` mas
**`rgb(… / .18)`** em `--tw-ring-color`; o cssnano **come o zero da frente**; o seletor vem
**escapado**; o prefixo de variante **faz parte** do nome; e **escapar só a barra invertida QUEBRA**
em classe com colchete, ponto ou parêntese — **o escape tem de ser GERAL** (a 8ª normalização existe
porque antes a sonda reportava **AUSENTE** classe que **EXISTIA**).

⚠️ **DOIS gates por elemento:** CSS (a classe existe com o valor certo) **e** HTML (o elemento a tem,
por **igualdade de conjunto**), com o elemento localizado por **árvore com índice ABSOLUTO** — **nunca
`indexOf` de classes**: quatro gates deste projeto mediam **o irmão errado**. ⚠️ **A sonda de CSS NÃO
protege a fidelidade de um arquivo** (o CSS é do último build e a classe pode ter outro dono).

### Mutação

⚠️ **Commite ANTES**, `cp` para o scratchpad com nome que **carregue o plano** (`mutar-189-NN.sh`),
ocorrência **travada em 1 ANTES** (recuse se ≠1), **linha conferida** depois, restauração **DA
CÓPIA** em `trap EXIT`, `sha256` contra `git show HEAD:` **com `tr -d '\r'` nos dois lados**.
⚠️⚠️ **`git checkout` NUNCA** — o orquestrador usou e **perdeu 90 linhas de gate**.

⚠️⚠️ **O NOME ÚNICO NÃO É ZELO: o scratchpad é COMPARTILHADO entre subagentes lançados em paralelo
pela mesma sessão.** Na 187 o script de uma wave foi **SOBRESCRITO** pelo da outra no meio do
trabalho, e a wave teve de **refazer a campanha inteira**. **Conclusão de mutação tirada com script
de nome genérico, em paralelo, NÃO VALE.** (learnings §4c.3)

⚠️ **Âncora MULTI-LINHA não é travável por `grep -c`** — ele conta **linhas**, não ocorrências (deu 12
e 724 numa âncora de uma ocorrência). ⚠️ **E a guarda "a âncora antiga sumiu" ABORTA numa réplica que
CONTÉM a âncora** → use **divergência de `sha256` contra a cópia**.

⚠️⚠️ **SOBREVIVENTE É INVESTIGADA, nunca presumida equivalente.** Na milestone: **~150 mutações, 7
eventos de sobrevivência, 6 lacunas REAIS**. Duas das últimas acharam defeito que nenhum teste pegava:
um analisador que **nomeava a chave errada** (veredicto certo, **mensagem mentindo** — e mensagem que
nomeia a coisa errada faz o próximo consertar a coisa errada); e uma decisão do usuário protegida
**só pela ausência acidental de dois arms de um `match`**.

⚠️ **Mutação que NÃO reprova quando deveria TAMBÉM é informação.** ⚠️ **FIXTURE QUE DISCRIMINA:** na
187-06 as duas sobreviventes eram falha da **fixture**, não da asserção — com uma oferta só, dois
recortes diferentes davam o mesmo número.

⚠️⚠️ **E `cp` ATUALIZA O `mtime`:** com a fonte mais nova que o CSS construído, **a camada de CSS dos
gates PULA** e a suíte JS aparece com `skipped` e contagem "errada" que **NÃO é regressão**.
➡️ **`npm run build` DEPOIS da campanha de mutação, ANTES de medir a suíte JS.**

---

## 8. ⚠️ AS BASELINES, e as duas falhas que **não são de ninguém**

**Medidas em 19/10/2026 (fim da wave 188-02):**

```
BUILD: exit 0 · 77 s     MANIFEST: 495 entradas / 202 paginas (pelo JSON)
JS: 5498 / 5497 pass / 1 fail / 0 skipped        PHP: 2510 / 22264 / 0 falhas
```

⚠️ **MEÇA DE NOVO antes de editar e REGISTRE — não ajuste.** A 188 segue acrescentando testes.

⚠️ **A única falha JS é a PRÉ-EXISTENTE `tests/js/estrutura-grade-glide.test.js`** (Fase 87,
*"Características secundárias nasce recolhido"*) — **NÃO corrija**.

⚠️⚠️ **PHP: `0` é o alvo. MAS dois casos de `tests/Feature/Publicador/CockpitDaContaTest.php`
(Fase 179, EM PRODUÇÃO) oscilam COM A HORA DO DIA** — `:966` (esperava `12`, obteve `0`) e `:1318`
(esperava `'hoje'`, obteve `'ontem'`). Vermelhos 16/10, **verdes** 17/10, vermelhos 18/10, **verdes**
19/10, **sem nenhuma mudança de código**.

⚠️ **A prova é definitiva:** `git log --name-only <antes>..<depois> -- tests/` sai **VAZIO** — nenhum
commit tocou `tests/`, logo a oscilação **não pode ser código**. O arquivo usa o relógio **54 vezes**,
e o último commit dele é literalmente *"ancora o relogio no caso de agrupamento da Atividade"*:
alguém já consertou **um** caso dessa família e deixou estes dois.

➡️ ⚠️ **NÃO conserte, NÃO conte como regressão, e NÃO crie `REF_PHP_FALHAS`.** ⚠️ **Mas qualquer
OUTRO teste falhando, ou mais de 2, é DA SUA FASE — investigue.**

⚠️ **E as ASSERÇÕES do PHP não são invariante:** mediu-se −5 com testes idênticos e zero commits em
`tests/`. ➡️ **Compare TESTES e FALHAS; não persiga contagem de asserção.**

---

## 9. ⚠️ TERRITÓRIO — o que é de outra sessão e de outro dev

⚠️ **NÃO ALTERE nenhum serviço das Fases 186/187/188 — CHAME-os.** Precisar mudar: **PARE e devolva a
pergunta.**

⚠️ **A ÚNICA exceção aberta** é a lista de **EXPOSITORES** da camada B do gate de andamento
(`AndamentoDaOfertaService` é a **ÚNICA PORTA** de escrita). Entra-se nela **com PROVA nas duas
pontas**, **remedindo `contagens-186-09.txt`** (hoje: `andamento-um-lugar-so` = **20**,
`transversais` = **258**). ⚠️ **NÃO renomeie chave para fugir do gate, NÃO enfraqueça camada.**

⚠️ **DECISÃO ADIADA, não sua:** aquele gate tem uma asserção que **cresce a cada expositor**
(`assertFileExists` por item) — é a família da armadilha 5, **em produção**. **O plano 188-13 decide a
forma. Não conserte.**

⚠️ **NÃO tocar** (território do outro dev / da sessão da v28): `app/Services/Creative/`,
`MlbPublicadorCriativoController`, `MlAnuncioCriativoKit`, `PublicadorCriativoKitPresenter`, os
endpoints `criativo*`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`,
`PublicadorCriativo*Service`, `LimparReferenciasCriativos`, **a retenção de 48h**, o editor
(`Pages/Mlb/Publicador/Editor.jsx` e `resources/js/Components/Publicador/Mesa/` — ⚠️ **SEM** o `Mlb`
no caminho), `resources/js/Components/Publicador/useCriativosDoPublicador.js`,
**`App\Support\Publicador`** (inclui `Imagem\ArquivosDeImagem` e `EditorEmUso`),
`app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`, `app/Models/Bling*`,
`app/Services/Bling/`, `app/Services/Portal/`, **`app/Mcp/`**, e **`Acervo*`/`ImagemAssetService`**.

⚠️ **O acervo virou Cloudflare R2 PRIVADO em produção em 16/10** — o bucket **não serve URL pública**
(o ML recebe **bytes**). Leia `.planning/learnings/acervo-imagens-r2.md` **antes** de pensar em imagem.

⚠️ **REUSAR NÃO É EDITAR.** A 188 precisou de URL de imagem e **reusou a rota existente**
`routes/mlb_anuncios.php:178` → `MlbPublicadorController::arquivoFoto`, sem editar nada vetado.

⚠️⚠️ **E ela tem EFEITO COLATERAL que o usuário ACEITOU em 19/10:** `arquivoFoto` passa por
**`EditorEmUso::marcar()`** (`MlbPublicadorController:405`), que marca o produto como *"com o editor
aberto"* por **3 minutos** (`config/publicador.php:148`) — e isso é **lido** por
`AgendadorDaFila:337`, `PreparoIaDoRascunhoService:131`/`:180`/`:366`, `GarantiaPadrao:192` e
`ExcluirProdutoService:195`. ➡️ **Carregar miniatura SUSPENDE o agendamento e o preparo por IA
daquele produto enquanto alguém revisa.** Razão aceita: *o próprio agendador diz "gente com o editor
aberto: não publica por baixo dela"*, e revisar é trabalhar no produto. **Se o seu lightbox usar a
mesma rota, o efeito é o mesmo — e tem de ficar DECLARADO no docblock, nomeando os cinco leitores.**

⚠️ **`D-11` e `D-22` de `tests/js/estrutura-meus-anuncios.test.js` são INTOCÁVEIS** (identifique por
**SEMÂNTICA**, não por número de linha): a tela de Publicações **nunca escreve no ML**, e a Nota ECF é
**"de 86"**.

⚠️ **A fronteira até a Fase 192: aprovar ENFILEIRA, NÃO publica.** A escrita no ML é da **Fase 193**.
**Declare, e prove por ausência de requisição HTTP com isca** (`Http::fake` +
`Http::preventStrayRequests()`). ⚠️ **E nada de "confirmar publicação", "desfazer", "segundo passo" ou
"cancelar aprovação": APROVAR JÁ PUBLICA foi decisão do usuário em 15/10, e há gate de ausência COM
ISCA valendo das Fases 187 a 194.**

⚠️ **NÃO tocar** em `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md` nem `STATE.md` durante o
planejamento. ⚠️ `tests/Feature/CompanyPortfolioAccessTest.php` aparece `??` e **não é de ninguém
desta linha** — intocado.

---

## 10. Regras do produto que valem para o lightbox

⚠️ **Vocabulário travado: "Resultado estimado por venda" / "Margem de contribuição estimada" —
NUNCA "lucro líquido"** (⚠️ `lucro_liquido` **é** campo legítimo do Portal: **recorte a sonda à lista
CONSTANTE dos seus arquivos novos**).

⚠️ **COR NUNCA É O ÚNICO SINAL** — todo status tem **ícone e texto**. ⚠️ **Jargão não vai para a
tela** (regra permanente desde 07/07/2026).

⚠️ **UM VOCABULÁRIO SÓ, chaves pt-BR nos DOIS lados** (PHP e JSX). As inglesas existem **apenas** em
`DO_PROTOTIPO`, para conferência, com bijeção provada. ⚠️ **NUNCA escreva um `||` aceitando as duas
formas** — foi achado como defeito grave na verificação da 186. ⚠️ **Espelhe rótulos LENDO o módulo
JSX por teste que LÊ o arquivo — não copie à mão.**

⚠️ **`andamentoDe()` NUNCA devolve `null`**, e o selo exige **TEXTO NÃO-VAZIO** dentro do elemento:
guarda que devolve `null` produz elemento **vazio**, que **passa em render e desaparece na tela**.

⚠️ **"Nada nasce decorativo":** o usuário exerceu essa régua **quatro** vezes. Botão sem destino não
nasce — e `onClick` vazio ou `disabled` decorativo é a mesma dívida com outra roupa. Na 188 ele
decidiu **não desenhar** "Abrir revisão detalhada ↗" porque a tela é da Fase 190/191, e o critério
ficou com **RESSALVA declarada**. ⚠️ **"Desenhar desabilitado com explicação" foi OFERECIDO e
RECUSADO.**

⚠️ **FID-03:** `text-[13.5px]`, nunca `text-sm`. ⚠️ **FID-04, vetados:** `ecf-bg`, `ecf-card`,
`ecf-yellow`, `font-display`, `border-white/[0.08]`. ⚠️ **viewBox** do protótipo `0 0 16 16` × Lucide
`0 0 24 24` → **`strokeWidth × 1,5`**. ⚠️ **`21` é o único tamanho de fonte liberado na 188** (via
lista **CONSTANTE** `LIBERADOS_DEPOIS_DA_187`, não por contagem) — **meça os seus e não acrescente à
lista dela.**

⚠️ **FID-02, justificativas NÃO aceitas:** *"ficou mais parecido com o resto do sistema"*, *"mantive
o token `ecf-*`"*, *"o componente que já existe faz parecido"*, *"o Tailwind não tem esse passo"*.

---

## 11. Ambiente, e como dimensionar os planos

⚠️ `php` **NÃO está no PATH**: use **`C:/xampp/php/php.exe`**. ⚠️ `artisan tinker` precisa de
**`< /dev/null`**. ⚠️ **O `mysqld` local pode não estar em execução** — confira
`tasklist | grep mysqld` antes de qualquer coisa que dependa do banco local (ele já esteve
**corrompido** em 2026-06-25).

⚠️ **NÃO criar worktree** — o junction do Windows já **destruiu o `vendor/` duas vezes**.
⚠️ **NÃO rodar `vendor/bin/pint`.** ⚠️ **NÃO usar Write em arquivo existente** (use Edit).

⚠️ **NÃO rodar `gsd-sdk query state.advance-plan`** — corrompeu o `STATE.md` deste projeto **seis
vezes**. ⚠️ **Medido: `roadmap update-plan-progress` reporta sucesso e NÃO ESCREVE NADA**;
`requirements.mark-complete` devolve **`not_found`** para IDs de `REQUIREMENTS-vNN.md`; e
**`REQUIREMENTS-v27.md` tem ZERO checkbox** (os `✅` dele marcam **decisão respondida**, não requisito
entregue). ➡️ **Quem rastreia entrega é o Coverage Map do `ROADMAP.md`, marcado À MÃO no fechamento,
em bloco ADITIVO com 0 remoções, conferido por `numstat`.**

⚠️ **`phase.add` do GSD numera pelo DIRETÓRIO, não pelo ROADMAP** — já colidiu **duas** vezes neste
projeto. **Confira `grep "^### Phase" .planning/ROADMAP.md | tail` antes de aceitar número.**

⚠️ **Mensagem de commit acentuada ou com aspas: use `-F <arquivo>`, nunca `-m`** — uma wave da 187
usou `-m` e **perdeu o `Co-Authored-By`**, e `--amend` é proibido. **O `-m` vem ANTES do `--`.**

⚠️⚠️ **DIMENSIONE OS PLANOS PARA QUEDA DE AGENTE: TRÊS caíram na Fase 187** (travamento de 600 s,
erro de rede `ENOTFOUND`, e turno esgotado), e **um deixou 2/3 de um plano SEM COMMIT**.
➡️ **Planos MENORES e MAIS NUMEROSOS, 2–3 tarefas cada, COMMIT ATÔMICO POR TAREFA, e em cada plano a
instrução explícita: "se o turno pesar, PARE, commite o provado e diga em que tarefa parou".**

⚠️⚠️ **COMO O USUÁRIO CONFERE — preveja isso no plano:** o banco local **não tem empresa com
`ml_token`** (a rota dá **404**), `pub_aprovacao_ofertas` está **vazia**, e **a fila só passa a ter
oferta na Fase 194**. ➡️ ⚠️ **NENHUM executor pode afirmar ter visto tela renderizada** — todo render
é `esbuild` + `react-dom/server`, e *"a tela mostra X"* significa **"o HTML gerado contém X"**.
**A validação visual é do usuário, em produção, na conta 459** — escreva o roteiro, **com um caminho
alternativo para quando a fila está vazia**.

⚠️ **A conferência visual da Fase 187 pelo usuário segue ABERTA.** **Não a presuma feita.**

---

## 12. Se você precisar falar com esta sessão

Escreva um arquivo nesta pasta (`.planning/coordenacao/`), com data no nome. É assim que as sessões
deste projeto conversam — **são 10 notas aqui**, e as desta semana resolveram um deploy bloqueado e
dois gates que acusavam trabalho correto de outra máquina.

⚠️ **E se você descobrir algo desta natureza — que custou caro e NÃO é dedutível do código —, escreva
em `.planning/learnings/`, não deixe só na memória da sua sessão.** Memória de sessão não atravessa
máquinas; só o repositório atravessa.
