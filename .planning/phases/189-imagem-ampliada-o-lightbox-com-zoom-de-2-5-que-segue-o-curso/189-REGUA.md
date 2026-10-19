# A régua da Fase 189 — comum aos 8 planos

> **Este arquivo é referenciado por `<context>` em TODOS os `189-NN-PLAN.md`.** Ele é o RECORTE da
> Fase 189 e **não substitui** três leituras que continuam obrigatórias, inteiras:
>
> 1. `.planning/coordenacao/261019-para-quem-planejar-a-fase-189-o-que-nao-esta-no-codigo.md` — o
>    documento de contexto desta fase (não há `CONTEXT.md`, decisão D-189-1). **Cada ⚠️ dele é
>    restrição travada.**
> 2. `.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-REGUA.md` —
>    §1 (o que 186/187 entregaram), §4 (portal e z-index), §5 (armadilhas de tela), §6 (gates), §7
>    (mutação), §10 (ambiente), §13 (a ordem mecânica), §14 (a URL da miniatura). **Valem aqui
>    palavra por palavra**, com `188-NN` lido como `189-NN`.
> 3. `.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md` — a régua completa dos gates.

---

## §0 — DECISÕES DO USUÁRIO DESTA FASE (19/10/2026) — TRAVADAS

| id | decisão | onde se aplica |
|---|---|---|
| **D-189-1** | Sem `CONTEXT.md`: o contexto é a nota de coordenação de 19/10 + ROADMAP + REQUIREMENTS-v27 + os planos da 188 | todos |
| **D-189-2** | Sem UI-SPEC: a tabela **`189-FID01.md`** é o contrato de UI, medida **por SCRIPT** no `design_handoff_publicador/Aprovacao.dc.html`, com colunas próprias para `cursor`, `white-space` e `text-overflow`; gate que lê o protótipo degrada para `skip` **EXPLÍCITO e NOMEADO** quando o arquivo não existe | 189-01, 189-03, 189-04, 189-05, 189-06, 189-07, 189-08 |
| **D-189-3** | Precedente B3 estendido: **"Comparar lado a lado na revisão detalhada" (AMPL-05 → Fase 190)** e **"✦ Gerar novamente com IA" (AMPL-07 → Fase 192)** **NÃO são desenhados** — nem desabilitados, nem com `onClick` vazio. Gate de ausência **com isca**. No fechamento o critério 4 e o trecho de AMPL-07 saem como **RESSALVA com dono** (190 / 192). A original de referência, o estado IMG-13, metadados, verificações e Aprovar/Reprovar foto **são entregues** | 189-05, 189-06, 189-08 |
| **D-189-3a** | **"Ampliar" DENTRO do lightbox TEM destino** — medido: `:763` é `{{ LB.zoomLabel }}` com `onClick={{ LB.onZoom }}`, e `zoomLabel` é `'Ampliar 2,5×'` / `'Reduzir'` (`:1326`). É o **liga/desliga do zoom**, não um botão de outra fase. ➡️ **É entregue** (189-05), e isso fica declarado | 189-05 |
| **D-189-3b** | O botão **"⤢ Ampliar" do PAINEL** (`:607`) é desta fase. A 188 tem gate de ausência dele (188-08, `T-188-75`); o **189-06** o INVERTE com prova, sem enfraquecer a ausência de "Gerar novamente com IA", e declara | 189-01 (inventário), 189-06 |

---

## §1 — A ORDEM DE CAMADAS: o lightbox é `z-[41]`

⚠️ **Medido no protótipo por script:** o lightbox é `z-index:90` (`:727`), entre o painel (`70`) e o
modal (`100`, `:775`). Ordem relativa do protótipo: `header 50 < backdrop 69 < painel 70 < lightbox 90
< modal 100 < toast 120`.

➡️ **O remapeamento desta fase, preservando a ORDEM RELATIVA:**

| peça | arquivo | z |
|---|---|---|
| header global | `header/PublicadorHeader.jsx` | `z-30` (**intocado**) |
| backdrop do painel | `aprovacao/PainelDeRevisao.jsx` (entregue pelo **188-07**) | `z-[39]` |
| painel | `aprovacao/PainelDeRevisao.jsx` (entregue pelo **188-07**) | `z-[40]` |
| **lightbox** | `aprovacao/LightboxDaImagem.jsx` (**criado pelo 189-03**) | **`z-[41]`** |
| casca de modal (inclui "Reprovar imagem") | `aprovacao/CascaDeModal.jsx` | `z-[42]` |
| toast | `aprovacao/ToastDaFila.jsx` | `z-[45]` |

⚠️ **NUNCA copie o `90`** do protótipo. ⚠️ **Prove pela ORDEM, não por lista de números** (a 187-09
mediu que lista larga reprova código certo: `45` era o número do popover). ⚠️ **O gate tem de recusar
o número que o protótipo deu A ESTA peça** (`90`), no modelo de `tests/js/publicador-fila-zindex-187.test.js`
(mapa `DO_PROTOTIPO` por peça, com isca do mapa).
⚠️ `z-[41]` **já existe** no projeto em `resources/js/Components/PlanejadorFull/Gaveta.jsx:66` — outro
módulo, outra tela. **Não é colisão**; mas isca de ausência "z-[41] inexistente no projeto" seria
FALSA — não a escreva.

### ⚠️⚠️ O CONTEXTO DE EMPILHAMENTO — a armadilha que NÃO está na nota e foi medida no planejamento

`z-index` **não escapa** de um contexto de empilhamento. O `<aside>` do painel é `fixed z-[40]` →
**cria contexto**. ➡️ **Se o modal "Reprovar imagem" (188-12) for montado DENTRO do `<aside>`**, o
`z-[42]` dele vale **dentro do 40** — e o modal aberto a partir do lightbox (`z-[41]`, na raiz)
ficaria **ATRÁS do lightbox**: a pessoa clica "Reprovar" e nada aparece.
➡️ **O 189-01 MEDE onde o 188-12 montou o modal** (dentro ou fora do `<aside>`), e o **189-07** prova
por **árvore do HTML** (named export da raiz do painel, render real) que o fundo do modal **NÃO é
descendente** do `<aside>` `z-[40]`. Se for: o 189-07 **move a montagem** para a raiz do portal do
painel (irmã do `<aside>`, como o backdrop `z-[39]`), declarando — e **PARA** se algum gate da 188
medir a posição antiga.
⚠️ **O lightbox tem o SEU `createPortal`** para `document.body` (critério 1: "por portal"): ele não
depende de onde o painel o monta.

---

## §2 — O QUE A 188 ENTREGA E A 189 CONSOME: **CHAMAR / IMPORTAR, NUNCA REIMPLEMENTAR**

⚠️⚠️ **A 188 NÃO ESTÁ PRONTA (2 de 13 waves no planejamento desta fase).** Os alvos abaixo são
citados **pelo plano da 188 que os cria**. ➡️ **O 189-01 mede o que de fato existe e escreve
`189-CONTRATO-188.md`** — o inventário com `arquivo:linha` de cada peça. **Os planos 189-02..189-07 leem o
inventário, não esta tabela**, quando os dois divergirem.

| peça | dono | o que a 189 faz com ela |
|---|---|---|
| `FotosDoPainelService::para()` — `imagens[]` com `id`, `imagem_id`, `posicao`, `posicao_rotulo`, `rotulo`, `titulo`, `de_ia`, `tipo_rotulo`, `principal`, `grupo`, `grupo_geral`, `estado`, `estado_rotulo`, `estado_cor`, `estado_dot`, `com_alerta`, `aprovada`, `aceita_acao`, `borda`, `anel`, `url_arquivo`, `marcadores[{ordinal,x,y,cor}]`, `verificacoes[{severidade,texto,glifo,cor,ordinal}]` | **188-03 — JÁ NO DISCO** (`app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php:548`-`:590`) | **LÊ** do payload `painel.fotos`. ⚠️ **Não altera.** |
| `FotosDoPainelService::borda(?string $estado, bool $selecionada, bool $comAlerta)` | 188-03, `:345` (público) | o 189-02 **CHAMA** (se precisar da borda sem seleção) |
| `PainelDeRevisao.jsx` — portal, `resolverAtalhoDoPainel`, `escreverNoPainel`, peças internas como named exports | **188-07** | o 189-06 **edita o mínimo** (e o 189-07 liga as ações): o degrau do lightbox no `Escape` (que o 188-07 deixou "previsto e declarado"), ←/→ indo para o lightbox, o estado `aberto/indice` do lightbox |
| `ImagemGrandeDoPainel.jsx` — wrapper `:604` e o "⤢ Ampliar" **ausente** | **188-08** | o 189-06 **desenha** o "⤢ Ampliar" (`:607`) |
| `BlocoDaImagemSelecionada.jsx` — `tipo · variação`, Reprovar (`onReprovar`), Aprovar foto, verificações com o ordinal | **188-08** (+ 188-12 religa Reprovar) | a 189 **IMPORTA** as peças/mapas que o inventário apontar, ou prova **igualdade por render cruzado** |
| `GradeDeMiniaturas.jsx` — a borda de 5 ramos com a seleção do CLIENTE | **188-08** | idem |
| `ModalDeReprovarImagem.jsx` + o handler que o abre | **188-12** | o lightbox abre o MESMO modal, **com o CONTEXTO DO LIGHTBOX** (oferta, versão e foto capturadas na abertura — PERGUNTA-189-B (b)); se o modal só lê a oferta do `painel`, o 189-07 acrescenta o mínimo (prop opcional de contexto, padrão = o do painel) |
| rotas `…/aprovacao/{oferta}/imagem/{imagem}/aprovar` e `…/reprovar` | **188-05** | **reusadas** pelo `escreverNoPainel` (188-07), com a oferta e a versão **do contexto do lightbox** (189-07, PERGUNTA-189-B (b)) |
| gate de ausência de "⤢ Ampliar" (`T-188-75`) | **188-08**, em `tests/js/publicador-painel-fotos-188.test.js` | o 189-06 **inverte com prova** |

### ⚠️ A REGRA ÚNICA DE REUSO DE TELA

**Peça que a 188 já desenha** (selo de estado e sua cor, número do marcador, estado de `url_arquivo`
nula, cor por severidade, rótulo da variação, cabeçalho APOIO, borda de miniatura com seleção) **é
IMPORTADA** do arquivo da 188 quando `189-CONTRATO-188.md` registrar um **named export** que a desenhe.
Quando **não** houver, o plano 189 desenha local **E PROVA IGUALDADE por render cruzado** (mesma
fixture → mesmo texto e mesmas classes de cor nos dois HTMLs). ⚠️ **Nunca uma segunda regra sem
teste de igualdade** — duas numerações da mesma verdade divergem, e aqui a divergência é *"a pessoa
reprova a foto errada"*.

---

## §3 — A FRONTEIRA DO SERVIDOR NESTA FASE

⚠️ **NÃO alterar NENHUM serviço das Fases 186/187/188 — CHAMÁ-los.** Precisar mudar: **PARE e devolva
a pergunta.**

➡️ A 189 tem **UM** dado novo de servidor: **a original de referência** (AMPL-05, IMG-13), que
`FotosDoPainelService` **não** entrega. Ele vem de um serviço **NOVO**,
`app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php` (criado pelo **189-02**), que
**CHAMA** `PubImagemReferencia::originaisDe()` (`app/Models/PubImagemReferencia.php:130`, Fase 186,
DADO-15 — **só leitura**) e entra no payload como a prop **`lightbox`**, recortada ao `?oferta=`, no
`MlbPublicadorAprovacaoController::index` — **sem tocar `PainelDeRevisaoService`**.
⚠️ **NADA nesta fase ESCREVE `pub_imagem_referencias`** — a escrita é das Fases 192/194, e há gate da
186 (`VinculoDuravelDaOriginalTest::test_dado_15_esta_fase_nao_escreve_o_vinculo_com_isca_de_dois_lados`)
varrendo `app/` inteiro. ⚠️ **Na estreia, TODA imagem de IA cai no estado declarado** (não há backfill).

⚠️ **O estado declarado vem do MODELO, uma fonte só:** `PubImagemReferencia::SEM_ORIGINAL`,
`SEM_ORIGINAL_ROTULO` (*"Original de referência não disponível"*), `SEM_ORIGINAL_APOIO` e
`SEM_ORIGINAL_ICONE` (`image-off` → `ImageOff` do `lucide-react`). **A tela não reescreve o texto.**

⚠️ **A URL da original reusa a MESMA rota** `mlb.anuncios.publicador.fotos.arquivo`
(`routes/mlb_anuncios.php:157`+`:178` → `MlbPublicadorController::arquivoFoto`) — e portanto **o
MESMO efeito colateral da PERGUNTA-188-C** (`EditorEmUso::marcar()` por 3 minutos), que o docblock do
serviço novo **declara nomeando os cinco leitores**, exatamente como `FotosDoPainelService:62`-`:86`.

⚠️ **APROVAR ENFILEIRA, NÃO PUBLICA** (a escrita no ML é da Fase 193). O lightbox **não cria rota
nenhuma** de escrita: Aprovar/Reprovar foto reusam as rotas do 188-05 pelo `escreverNoPainel`, com a
oferta e a versão do contexto do lightbox. A prova
de ausência de HTTP ao Mercado Livre (`Http::fake()` + `Http::preventStrayRequests()`) é feita sobre a
rota de leitura (`GET` com `?oferta=`) que passa a montar a prop `lightbox`.
⚠️ **Nada de "confirmar publicação", "desfazer", "segundo passo" ou "cancelar aprovação"** — gate de
ausência COM ISCA valendo das Fases 187 a 194.

---

## §4 — TELA: o que esta fase acrescenta à §5 do 188-REGUA

- ⚠️ **O zoom é FUNÇÃO PURA testada** (origem do `transform` a partir de coordenadas); **o movimento
  NÃO é provado** (não há navegador) — **declare no docblock e na SUMMARY**.
- ⚠️ **`duration-[…]`, `delay-[…]` e `ease-[…]` NÃO COMPILAM.** A transição do zoom (`:731`,
  `transition:transform .15s`) vai **inteira por `style`**: `transitionProperty: 'transform'`,
  `transitionDuration: '150ms'`, `transitionTimingFunction: 'ease'` (o `ease` é o padrão do CSS para
  `transition` sem curva — **não** o `cubic-bezier(0.4,0,0.2,1)` da classe `transition-transform`
  do Tailwind; usar a classe trocaria a curva). Gate GATE-09 com **`duration-200` como controle**.
- ⚠️ **O cursor é dinâmico** (`:730`, `cursor:{{ LB.cursor }}`, e `LB.cursor` é `'crosshair'` com 1× e
  `'zoom-out'` com 2,5×, `:1326`) → **duas classes LITERAIS** (`cursor-crosshair` / `cursor-zoom-out`)
  num mapa do `.jsx` — **nunca concatenadas** (o scanner do Tailwind não vê).
- ⚠️ **←/→ no lightbox DÃO A VOLTA** (`lbMove`, `:1321`: `(lb.i + d + n) % n`) — **diferente** do
  painel, que **clampa** (`:1018`). O protótipo ganha: **não unifique as duas regras**.
- ⚠️ **Trocar de foto zera o zoom** (`lbMove`, clique em miniatura e abrir, todos com `lbZoom: 1`),
  mas **NÃO** zera a origem (`lbX`/`lbY` persistem). Reproduzir os dois.
- ⚠️ **O lightbox fecha sozinho quando a galeria fica vazia** (`:1324`: `if (i < 0) return { open:
  false }`) — e reprovar tira a foto do conjunto final, então o índice é **clampado** no próximo
  payload.
- ⚠️ **Tamanho de fonte novo: `18`** (as setas ← →, `:741`/`:742`) — **o ÚNICO** ausente de
  `TAMANHOS_PERMITIDOS`. Medido por script em 19/10: `sed -n '726,772p' | grep -oE 'font-size:[0-9.]+px'
  | sort -u` → `9.5 11.5 12 12.5 13 18`. O **189-01** o libera **na régua da 187**
  (`LIBERADOS_DEPOIS_DA_187`, `tests/js/publicador-fila-tokens-187.test.js:450`), **numa linha
  própria com o plano e a medida** — é o mecanismo que o docblock daquela lista prescreve
  (*"QUEM AMPLIAR A RÉGUA ACRESCENTA UMA LINHA AQUI"*). ⚠️ **NÃO** mexer na linha do `21` (é da
  188-01) e **não** liberar nada "por precaução".
- ⚠️ **Nenhum token novo.** Todo hex do lightbox já tem token (`189-FID01.md` §T). O fundo
  `rgba(5,5,5,0.94)` vai como `bg-[rgba(5,5,5,0.94)]` (o gate de vocabulário proíbe **hex cru**
  `#[0-9a-fA-F]{3,8}`, não `rgba(…)`); opacidade sobre token vai por modificador
  (`border-pub-danger/[0.45]`, `bg-pub-page/[0.85]`).
- ⚠️ **Toda peça nova é `.jsx`** e entra em `ARQUIVOS_NOVOS`
  (`tests/js/publicador-vocabulario-novo.test.js:45`) **na MESMA tarefa que a cria**.

---

## §5 — GATES: o que muda em relação à §6 do 188-REGUA

- O 1º argumento é **`189-NN`** — `sh tests/gates/verificar-testes.sh 189-03 <id>`. **Nunca** o
  número nu (`03` resolve `contagens-186-03.txt`, a fase errada, em silêncio).
- Todo método PHP novo leva **`_189_NN`** no nome; homônimo conferido **por script, nas duas
  direções, incluindo PREFIXO**, contra todo `test_*` de `tests/` — as Fases **186, 187 e 188** numeram
  de 01 em diante.
- `vocabulario-tamanhos` em **linha própria** em todo manifesto que o roda, forma **`>=N`** com N
  **medido na hora**.
- **Bijeção id ↔ `<verify>`** nos dois sentidos em cada plano; o **189-08** prova os oito pares por
  script, com asserção única coletando infratores.
- **Escopo do assunto** `(189-NN)` para território; **nunca** range cru; **nunca** conferir autor.
- ⚠️⚠️ **A PROVA DE INTOCADO — `PI(189-NN) sobre <lista>` — é a ÚNICA forma aceita nesta fase de
  dizer "este plano não tocou X".** ⚠️ **`git diff --numstat -- <alvos>` "sai vazio" é PROIBIDO como
  gate:** depois do commit ele compara a ÁRVORE com o índice e sai vazio SEMPRE — é gate vazio por
  construção (learnings §4 caso 3; achado do plan-checker de 19/10/2026 em cinco pontos destes planos).
  ➡️ **A receita, executada por script escrito por ferramenta no scratchpad (`pi-189-NN.sh`):**
  1. **`test -e` em CADA alvo da lista** (ou no diretório-pai, para alvo que é pasta) — alvo
     inexistente REPROVA (anti-fantasma);
  2. **os commits do escopo:** `git log --format=%h --grep='(189-NN)' --fixed-strings` — a lista tem de
     ser **NÃO-VAZIA** (o plano commitou; vazia = gate sobre zero casos → REPROVA);
  3. **passada 1:** para cada commit, `git show --stat --format= <h>` (ou `--name-only`), juntando os
     caminhos tocados; **interseção com a lista de alvos = vazia**;
  4. **passada 2, independente:** `git log --grep='(189-NN)' --fixed-strings --name-only --format=` de
     uma vez só — **o mesmo conjunto** de caminhos da passada 1 (se divergirem, o script está errado);
  5. **isca nas DUAS direções:** (a) o mesmo script com o escopo de uma fase que **SABIDAMENTE** tocou
     um dos alvos (ex.: `(188-07)` sobre `PainelDeRevisao.jsx`; `(188-08)` sobre
     `tests/gates/contagens-188-08.txt`) **ACUSA** — prova que a sonda enxerga; (b) o escopo
     `(189-NN)` **não** casa nenhum commit de assunto `(188-…)`/`(190-…)`/`(261019-…)` — prova que o
     recorte não vaza para outra sessão.
  ⚠️ A saída (commits do escopo, caminhos, interseção, as duas iscas) vai **colada na SUMMARY**.
  ⚠️ **Contagem de MANIFESTO alheio "inalterada"** é PI sobre o arquivo do manifesto **mais** o id
  alheio verde com o `tests=` que ele já declara — nunca `git diff` da árvore.

---

## §6 — AS PERGUNTAS DESTA FASE (forma fixa; **"a definir" NÃO é dono**)

> Lista viva. **O orquestrador registra a resposta aqui ANTES de a execução começar** (este arquivo é
> do planejamento; o executor só o LÊ). O plano que depende de uma pergunta **ABERTA** confere a linha
> por `grep` na primeira ação e **PARA** se ela não estiver `RESPONDIDA`.

```
PERGUNTA-189-A: RESPONDIDA — dono: usuário (19/10/2026) — opção (a) SIM: o cabeçalho "VERIFICAÇÃO
  AUTOMÁTICA · APOIO" entra UMA vez na lateral do lightbox, com as classes do cabeçalho do painel
  (188-08; medida de origem `:369`), MAIS o ordinal por item (o número do marcador, elemento próprio).
  Razão do usuário: sem o cabeçalho a verificação automática pode ser lida como VEREDICTO. ⚠️ É MAIS
  UMA DERROTA DO PROTÓTIPO por decisão do usuário (a quarta da milestone): `:759`-`:761` não tem
  cabeçalho × `:369` tem o literal (E4). O ordinal já era exigido por AMPL-06/critério 2.
  APLICADA EM: 189-05 (tarefa 2), registrada no fechamento (189-08)
PERGUNTA-189-B: RESPONDIDA — dono: usuário (19/10/2026) — opção (b) FIEL AO PROTÓTIPO: com o lightbox
  aberto, ↑/↓ CONTINUAM navegando a fila por baixo (`:1017`), e o LIGHTBOX GUARDA O PRÓPRIO CONTEXTO
  capturado na ABERTURA — `oferta_id`, `versao`, `ao_vivo`, as `imagens` e as `originais`. ➡️ TODA ação
  do lightbox (Aprovar foto, Reprovar e o modal de reprovar) usa a oferta, a versão e a foto DO
  LIGHTBOX, NUNCA a oferta selecionada no painel por baixo. Regra de refresco (função pura
  `contextoDoLightbox`): enquanto o painel mostra a MESMA oferta do lightbox, o contexto se atualiza
  do payload novo (versão e imagens frescas, índice clampado); quando o painel mostra OUTRA oferta, o
  lightbox usa o contexto guardado. ⚠️ Prova: handler exportado + fixture que discrimina (oferta A
  versão 3 × oferta B versão 7: abrir na A, ↓ para a B, Aprovar foto → payload com o id e a versão
  da A); mutação que troca a fonte da versão para o painel TEM de reprovar.
  APLICADA EM: 189-06 (tarefa 2: o contexto e o teclado) e 189-07 (tarefa 1: as ações)
PERGUNTA-189-C: RESPONDIDA — dono: usuário (19/10/2026) — o padrão do protótipo: ao fechar, o painel
  volta à foto de onde o "⤢ Ampliar" foi clicado, com o foco nesse botão. (Vale quando a oferta por
  baixo NÃO mudou; o caso em que mudou é a PERGUNTA-189-D.) APLICADA EM: 189-06 (tarefa 2)
PERGUNTA-189-D: ABERTA, NÃO BLOQUEIA — dono: usuário — consequência da 189-B: o que acontece quando a
  oferta do PAINEL mudou (↑/↓) enquanto o lightbox estava aberto na oferta A?
  (D1) AO FECHAR: DEFAULT APLICADO = o painel fica na oferta para onde a pessoa navegou (B) — a
  navegação foi ação explícita dela —, na foto que o painel de B já tinha selecionada, e o foco vai
  para o "⤢ Ampliar" do painel de B (se não houver imagem, para nada — `ref` nula não lança). A
  foto "de onde se abriu" (189-C) não existe mais na tela: era da A. Alternativa: voltar o painel
  para a A (um `router.get` com `?oferta=A`).
  (D2) DEPOIS DE UMA AÇÃO BEM-SUCEDIDA no lightbox com a oferta do painel DIFERENTE: o recarregamento
  do Inertia traz o painel de B, e o contexto da A no lightbox fica VELHO (a foto aprovada ainda
  apareceria "A conferir" e com botões). DEFAULT APLICADO = o lightbox FECHA depois da ação (com o
  foco como em D1), porque não há como mostrar o estado novo da A sem mover o painel de volta.
  Alternativa: recarregar o painel na A (`?oferta=A`) e manter o lightbox aberto, atualizado.
  ⚠️ As duas escolhas por padrão são de produto e NÃO óbvias; cada alternativa é local (189-06/189-07).
  APLICADA EM: 189-06 (D1) e 189-07 (D2), com o default; registrada aberta no fechamento (189-08)
AVISO-189-1 (à sessão da 188, via orquestrador): `FotosDoPainelService::para($oferta)` é chamado SEM
  índice (`PainelDeRevisaoService:301`) → `selecionado = 0` → a `borda` da imagem 0 sai SEMPRE
  `selecionada`, e a `borda` de qualquer outra nunca sai `selecionada`. Como ←/→ trocam a foto no
  CLIENTE (188-07), a grade do 188-08 NÃO pode ler `borda` cru do servidor para a seleção — a imagem 0
  ficaria amarela sem estar selecionada. O lightbox (189-03) depende da mesma regra.
AVISO-189-2 (à sessão da 188, via orquestrador): o payload de `FotosDoPainelService` traz `grupo`
  (chave CANÔNICA, `ChaveCanonica::combinacao`, ex.: `cor=preto`) e `grupo_geral`, mas NENHUM rótulo
  humano de variação. O 188-08 precisa dele para "tipo · variação" (`:623`) e o lightbox para a linha
  "Variação" (`:749`). O rótulo humano só existe em `ResolvedorGruposImagem::rotuloDoGrupo` (PRIVADO,
  em `App\Support\Publicador`, território vetado). A 189 USA O QUE O 188-08 USAR (render cruzado).
AVISO-189-3 (à sessão da 188, via orquestrador): o 188-12 monta `ModalDeReprovarImagem`. Se ele ficar
  DENTRO do `<aside>` `z-[40]`, o `z-[42]` da casca vale DENTRO do contexto do painel e o modal aberto
  a partir do lightbox (`z-[41]`) fica ATRÁS dele (§1). Montar como irmão do `<aside>` na raiz do
  portal evita retrabalho no 189-07.
```

---

## §7 — COMO O USUÁRIO CONFERE

⚠️ **NENHUM executor pode afirmar ter visto tela.** Todo render é esbuild + `react-dom/server`; *"a tela
mostra X"* = *"o HTML gerado contém X"*. O banco local não tem empresa com `ml_token` (a rota dá
**404**), `pub_aprovacao_ofertas` está **vazia** e **a fila só ganha oferta na Fase 194**.
➡️ O **189-08** escreve o roteiro da conta **459**, **com caminho alternativo para a fila vazia**, e
registra `PERGUNTA-VISUAL-189: ABERTA — dono: usuário`. ⚠️ As conferências visuais das Fases **187 e
188** seguem abertas e **separadas** — nenhuma é presumida feita.

---

## §8 — TERRITÓRIO DESTA FASE

**PODE editar (o mínimo, declarando):** `PainelDeRevisao.jsx`, `ImagemGrandeDoPainel.jsx` e `ColunaDeFotos.jsx` (188) e `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` (só para repassar a prop `lightbox`) — e
**só** nos pontos do §2; `ModalDeReprovarImagem.jsx` (188-12) — **só** se ele não aceitar a oferta/versão por prop (189-07, PERGUNTA-189-B); `tests/js/publicador-painel-fotos-188.test.js` — **só** para inverter a
ausência do "⤢ Ampliar", com prova; `MlbPublicadorAprovacaoController::index` — **só** para a prop
`lightbox` e a injeção do serviço novo; `tests/js/publicador-vocabulario-novo.test.js` (`ARQUIVOS_NOVOS`
e `18`); `tests/js/publicador-fila-tokens-187.test.js` (**uma linha** em `LIBERADOS_DEPOIS_DA_187`).

**NÃO toca:** todo serviço de `app/Services/Publicador/Aprovacao/` das 186/187/188 (incluindo
`FotosDoPainelService` e `PainelDeRevisaoService`), `PubImagemReferencia`, `header/PublicadorHeader.jsx`,
`tests/js/publicador-header-zindex-ordem.test.js`, `AppLayout.jsx`, `CascaDeModal.jsx`,
`ToastDaFila.jsx`, `BarraDeSelecao.jsx`, `tests/js/estrutura-meus-anuncios.test.js` (D-11/D-22),
`app/Services/Creative/`, `App\Support\Publicador` (inclui `ArquivosDeImagem`, `EditorEmUso`,
`ResolvedorGruposImagem`), `Acervo*`/`ImagemAssetService`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`,
`resources/js/Components/Publicador/Mesa/`, `resources/js/Pages/Mlb/Publicador/Editor.jsx`,
`resources/js/Components/Publicador/useCriativosDoPublicador.js`, `app/Mcp/`, `Bling*`,
`app/Services/Portal/`, e **`.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v27.md`,
`.planning/STATE.md`** (nenhum plano os toca; o Coverage Map é marcado à mão pelo orquestrador no
fechamento, em bloco ADITIVO conferido por `numstat`).

⚠️ **`design_handoff_publicador/` é somente leitura e NÃO versionado.** Cite sempre o diretório: há
outra cópia (`new_layout_publicador/`) com conteúdo diferente.

---

## §9 — `test -e` DO PLANEJAMENTO: os caminhos citados que AINDA NÃO EXISTEM (19/10/2026)

Medido por script sobre os 10 documentos desta pasta (todo token `app/…`, `resources/…`, `tests/…`,
`routes/…`, `config/…`, `database/…`, `.planning/…`, `design_handoff_publicador/…`, com `test -e`).
**Todo caminho citado que não existe no disco está numa das três listas abaixo — nenhum é fantasma.**

**(a) Entregues pela Fase 188 (citados pelo plano dono; o 189-01 os confere no gate de entrada e no inventário):**
`resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx` e `TopoDoPainel.jsx` (188-07) ·
`ImagemGrandeDoPainel.jsx`, `ColunaDeFotos.jsx`, `GradeDeMiniaturas.jsx`, `BlocoDaImagemSelecionada.jsx`
(188-08) · `ModalDeReprovarImagem.jsx` (188-12) · `tests/js/publicador-painel-casca-188.test.js` (188-07) ·
`tests/js/publicador-painel-fotos-188.test.js` (188-08) · `tests/js/publicador-painel-modais-b-188.test.js`
(188-12) · `tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php` (188-05) ·
`tests/gates/contagens-188-07.txt`, `-08.txt`, `-12.txt` · `188-08-SUMMARY.md`, `188-12-SUMMARY.md`,
`188-13-SUMMARY.md` e `188-FECHAMENTO.md` (188-13).

**(b) Criados pelos planos desta fase:** `189-BASELINES.md`, `189-CONTRATO-188.md`,
`tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines189Test.php`,
`tests/js/publicador-lightbox-tokens-189.test.js`, `tests/gates/contagens-189-01.txt` (189-01) ·
`app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php`,
`tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php`, `contagens-189-02.txt` (189-02) ·
`resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx`,
`tests/js/publicador-lightbox-casca-189.test.js`, `contagens-189-03.txt` (189-03) ·
`tests/js/publicador-lightbox-camadas-189.test.js`, `contagens-189-04.txt` (189-04) ·
`resources/js/Components/Mlb/Publicador/aprovacao/LateralDoLightbox.jsx`,
`tests/js/publicador-lightbox-lateral-189.test.js`, `contagens-189-05.txt` (189-05) ·
`tests/js/publicador-lightbox-painel-189.test.js`, `contagens-189-06.txt` (189-06) ·
`tests/js/publicador-lightbox-acoes-189.test.js`, `contagens-189-07.txt` (189-07) ·
`189-FECHAMENTO.md`, `deferred-items.md`, `tests/Feature/Publicador/Aprovacao/FechamentoDaFase189Test.php`,
`contagens-189-08.txt` (189-08) · as `189-NN-SUMMARY.md` de cada plano.

**(c) ISCA de propósito (tem de continuar AUSENTE):** `resources/js/Pages/Mlb/NaoExiste9z7.jsx`.

⚠️ **Os caminhos REAIS conferidos que costumam ser citados errado** (nota §2): `tests/Feature/Publicador/CockpitDaContaTest.php`
(FORA de `Aprovacao/`), `app/Services/Publicador/Fila/ResumoRapidoService.php`,
`resources/js/Components/Publicador/useCriativosDoPublicador.js` (SEM `Mlb`),
`resources/js/Components/Publicador/Mesa/` (SEM `Mlb`), `resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx`
(DENTRO de `header/`), `app/Services/Mlb/Publicacao/MlPublicacaoService.php`.
