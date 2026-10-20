---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 08
subsystem: Publicador — Aprovação (fechamento medido da fase do lightbox)
tags: [publicador, aprovacao, lightbox, fechamento, baselines, territorio, bijecao, forma-fixa, gates]
requires:
  - "189-01 — as baselines MEDIDAS, o `189-CONTRATO-188.md`, a `189-FID01.md` re-medida e o `18` liberado"
  - "189-02 — `LightboxDoPainelService` e a prop `lightbox` (a original de referência e o estado IMG-13)"
  - "189-03 — `LightboxDaImagem.jsx`: portal próprio, palco, marcadores, miniaturas, setas"
  - "189-04 — a ordem de camadas pelo COMPORTAMENTO, o GATE-09 na fonte e no CSS, e o portal pelas formas EXATAS"
  - "189-05 — `LateralDoLightbox.jsx`: metadados, original/IMG-13, verificações numeradas, zoom, travas"
  - "189-06 — o fio: `⤢ Ampliar` no painel, o contexto próprio do lightbox, teclado e foco"
  - "189-07 — as ações com o contexto, o modal acima do lightbox por ÁRVORE, o default D2"
  - "188-13 — o `188-FECHAMENTO.md`, que é o MODELO de forma e rigor deste documento"
provides:
  - "`189-FECHAMENTO.md` — os 8 critérios em FORMA FIXA (`CRIT-NN: OK|RESSALVA|REPROVA — … — gate … — dono: …`), com PLACAR-189"
  - "as 7 linhas de baseline RE-MEDIDAS, com a PROCEDÊNCIA (comando) de cada uma"
  - "os dois Δ de suíte ATRIBUÍDOS item por item, e as duas somas FECHAM EXATO"
  - "o território por ESCOPO DO ASSUNTO: 32 alvos, 2 passadas, 4 iscas, as TRÊS FACES do limite"
  - "a FID-01 consolidada (`FID-01-PLANO-NN`), a mutação consolidada (57/0) e as 8 bijeções"
  - "o roteiro da conferência visual da conta 459, com o caminho da fila VAZIA ANTES dos itens com oferta"
  - "`tests/Feature/Publicador/Aprovacao/FechamentoDaFase189Test.php` (9 métodos, 3 ids) e `tests/gates/contagens-189-08.txt`"
  - "o `deferred-items.md` com OITO decisões novas, todas COM DONO"
affects:
  - "o orquestrador: a §12 lista o que ele marca À MÃO no ROADMAP.md (bloco ADITIVO, 0 remoções)"
  - "a Fase 193: o `POST /items` do Publicador está em app/Services/Publicador/PublicacaoService.php:442"
  - "a Fase 194: o lightbox AMPLIA a janela do EditorEmUso enquanto alguém revisa"
  - "a Fase 192: o gate de ausência do 'Gerar novamente' tem de ser invertido COM PROVA, nos DOIS regimes"
tech-stack:
  added: []
  patterns:
    - "fechamento com FORMA FIXA lida por gate: critério que não passa recebe RESSALVA/REPROVA com razão e DONO, nunca é reescrito para caber"
    - "Δ de suíte atribuído por `git show --name-only` do commit que CRIOU cada arquivo — nunca por range, que engloba os commits da própria fase"
    - "a soma do Δ FECHAR EXATO é a prova de que a atribuição está COMPLETA"
    - "anti-fantasma (`test -e` em lista CONSTANTE) ANTES de qualquer diff, com os fantasmas coletados numa asserção ÚNICA"
    - "recorte de seção com `[^\\n]*` no cabeçalho e `[\\s\\S]*?` no corpo, NUNCA `.` com /s — e o recorte devolve o CORPO, não o cabeçalho"
key-files:
  created:
    - .planning/phases/189-.../189-FECHAMENTO.md
    - tests/Feature/Publicador/Aprovacao/FechamentoDaFase189Test.php
    - tests/gates/contagens-189-08.txt
  modified:
    - .planning/phases/189-.../deferred-items.md
decisions:
  - "CRIT-02 e CRIT-07 saem RESSALVA (dono: usuário), e NÃO 'OK com ressalva declarada' como o planejador previa: os dois critérios afirmam RUNTIME e esta fase prova a FUNÇÃO — 'OK com ressalva' é exatamente o que a forma fixa existe para impedir"
  - "CRIT-04 carrega AS DUAS COISAS: a atribuição que o plano fixou (`dono: Fase 190`) E o cancelamento medido (decisão 20, 0607f5a9) ⇒ AMPL-05 sem dono. Escrever só uma das duas mentiria de um jeito ou de outro"
  - "`casca-189:1352` (mais largo que o invariante): NÃO consertar — o furo JÁ está fechado pelas quatro formas EXATAS do `camadas-189:726`, e editá-lo mudaria o `tests=27` de uma wave fechada sem ganhar invariante"
  - "a família 'gate mede WIP, não território' (24 pontos em 7 arquivos, não 3): declarar, NÃO consertar — são gates de fase EM PRODUÇÃO, fora do território; dono: usuário/orquestrador"
  - "`189-FID01.md` NÃO reescrito: CINCO gates o leem e um tem isca por BYTES da §G; a régua permite ESTENDER"
  - "`COMMIT` = `PARTIDA` (55d97241), declarado: as medições rodaram num estado COMMITADO e reproduzível, e o Δ deste plano foi medido SEPARADAMENTE em vez de embutido na linha `PHP:`"
requirements-completed: [FID-01, FID-02, GATE-09, TERR-06, AMPL-01, AMPL-02, AMPL-03, AMPL-04, AMPL-05, AMPL-06, AMPL-07, IMG-09, IMG-13]
metrics:
  duration: "~1h50 (20/10/2026)"
  completed: 2026-10-20
  tasks: 3
  commits: 2
  files_changed: 4
---

# Fase 189 Plano 08: o FECHAMENTO MEDIDO da fase do lightbox — Summary

**Os 8 critérios da Fase 189 em forma fixa com veredicto, razão e dono — `OK=5 RESSALVA=3
REPROVA=0` —, as 7 linhas de baseline RE-MEDIDAS com a procedência de cada uma e os dois Δ de suíte
atribuídos item por item (as duas somas FECHAM EXATO), o território provado por escopo do assunto em
duas passadas com as três faces do limite declaradas, e o roteiro da conferência visual da conta
459 com o caminho da fila vazia na frente — mais o gate que lê a FORMA do próprio documento e que
achou DOIS furos nele ao ser rodado.**

## Performance

- **Duração:** ~1h50
- **Início:** 2026-10-20T21:48:30Z (PARTIDA `55d97241`, capturada na primeira ação)
- **Tarefas:** 2 de 3 completas; a **3 é checkpoint humano BLOQUEANTE** e está aguardando o usuário
- **Arquivos:** 4 (3 criados, 1 modificado)

## Task Commits

1. **Tarefas 1 e 2** — `799f5d90` (`docs(189-08)`): o `189-FECHAMENTO.md`, o
   `FechamentoDaFase189Test.php` (9 métodos / 3 ids), o `contagens-189-08.txt` e o
   `deferred-items.md`.
   ⚠️ **Num commit só, e a razão está no próprio commit:** o `189-FECHAMENTO.md` é **UM arquivo** que
   as duas tarefas escrevem, e separá-las deixaria o gate **VERMELHO** no HEAD intermediário (ele
   **lê** o documento). Nada ficou fora de commit.
2. **Esta SUMMARY** — o commit de metadados.

---

## §1 — AS BASELINES, RE-MEDIDAS (nenhuma copiada)

```
PARTIDA:   55d972414f519d6a8006c767643c6a8f18b7ad84  (curto: 55d97241)  — primeira acao
COMMIT:    55d97241   <- build/manifest/suites/prototipo medidos NESTE HEAD (arvore limpa nos meus caminhos)
BUILD:     exit 0 em 44 s
MANIFEST:  498 entradas / 203 paginas (pelo JSON) / isca ausente / controle presente
JS:        6012 / 6011 pass / 1 fail / 0 skipped       (exit do harness = 0)
PHP:       2631 / 24041 / 0                            (RECORTE; exit do harness = 0)
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html - 1432 linhas - sha256 68624883ce35547e
```

⚠️ **`PARTIDA` e `COMMIT` coincidem, e isso é DECLARADO:** as medições rodaram num estado
**commitado e reproduzível**, com `git diff HEAD` **vazio** nos meus caminhos. O Δ que **este** plano
acrescenta (os **9** métodos `_189_08`) foi medido **separadamente**, não embutido na linha `PHP:`.

### ⚠️⚠️ Os dois Δ FECHAM EXATO — e é isso que prova que a atribuição está COMPLETA

**JS `5871 → 6012` = `+141`:** `3` (tokens-189) + `27` (casca) + `16` (camadas) + `27` (lateral) +
`22` (painel) + `25` (ações) = **120**, mais `+14` do vocabulário (**681 → 695**, cresce por
construção) e `+7` do gate da **quick ALHEIA** `261020-tdz`. **120 + 14 + 7 = 141.** ✓

**PHP (recorte) `2583 → 2631` = `+48`:** atribuído por **`git show --name-only` do commit que CRIOU
cada arquivo** — ⚠️ **nunca por range** (o range englobaria os commits da própria fase, e foi nisso
que a 189-07 caiu). **36 ALHEIOS** (as 4 quicks do Agente Publicador: `1253cc86`, `e20a7653`,
`1a823d6f`, `c810a8ac`) + **12 desta fase** (`337f29c5` ×3, `95c4c12f` ×9). **36 + 12 = 48.** ✓

**E o recorte RE-MEDIDO depois do commit dos gates deste plano: `2640 / 24126 / 0`** (`exit=0`).
⚠️⚠️ **Fecha exato nas DUAS contagens:** `2631 + 9 = 2640` **e** `24041 + 85 = 24126`
(`32 + 32 + 21 = 85`, as asserções dos três ids). ➡️ Fechar **nas duas** é mais forte que fechar só
os testes: se algum método rodasse asserção a mais do que o manifesto declara, a segunda linha **não
bateria**. **Falhas em 0 nas duas medições**, ~40 min apartadas (a dupla de `CockpitDaContaTest`
**não oscilou**).

### ⚠️ A suíte PHP INTEIRA não é medível nesta máquina — **medido por mim**

```
C:/xampp/php/php.exe artisan test
→ Fatal error: Allowed memory size of 536870912 bytes exhausted ... FileFactory.php on line 87
→ EXIT = 255
```
⚠️ `-d memory_limit=-1` **não resolve** (o estouro é em processo **FILHO**). ➡️ Mede-se pelo
**RECORTE**, e o documento diz isso.

### ⚠️ A falha que a 189-01 devolveu foi **CONSERTADA NA RAIZ** — e é o **15º** caso

`2444feea fix(188-03): os cinco leitores do sinal ancorados por NOME, não por LINHA`.
⚠️⚠️ **E NENHUM número foi subido:** `tests=3` ficou **IGUAL**; só `assertions` subiu de **84** para
**91**, porque a lista por nome tem mais itens — **o gate ficou mais forte**. ➡️ É por isso que o
recorte PHP passou de **1 falha para 0**.

### As falhas alheias, nomeadas por SEMÂNTICA e **não corrigidas**

- **JS, a única:** `estrutura-grade-glide.test.js` (Fase 87), caso *"Características secundárias
  nasce recolhido"*. **Atribuída por execução ISOLADA:** `19 / 18 pass / 1 fail / 0 skipped`.
- **PHP (recorte): ZERO falhas.** A dupla de `CockpitDaContaTest` (Fase 179), que **oscila com a hora
  do dia**, está **verde** nesta medição.
- **Fora do recorte** (`CalcularFaixaTest`, `CompanyServiceTypeTest`, `Phase39`): **declaradas e NÃO
  medidas**, porque a suíte inteira não é medível.
- ⚠️ **Nenhuma linha deste documento veio de `/tmp/ultima-suite-*.log`** — caminho **FIXO e
  COMPARTILHADO**; usei arquivo próprio no scratchpad.

---

## §2 — O TERRITÓRIO, pelos dois caminhos

```
ALVOS = 32   FANTASMAS = 0   COMMITS_DO_ASSUNTO = 39 (distintos: 39)
PASSADA1_CAMINHOS = 40   PASSADA1_VIOLACOES = 0
PASSADA2_CAMINHOS = 40   PASSADA2_VIOLACOES = 0   CONJUNTOS_IGUAIS = SIM
ARQUIVOS_DE_PACOTE_TOCADOS_PELO_ESCOPO = 0
```

**As 4 iscas, exercidas:** (A) **6** alvos que a fase TOCOU → **1 acerto em cada**; (A2) `(188-07)` ×
`PainelDeRevisao.jsx` → **2 acertos**; (B) o escopo `189-NN` contra **6** assuntos alheios →
**0 em todos**; (C) caminho inventado → **ausente**.

⚠️ **Interseção VAZIA com `ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md`** (GATE-07) —
**nenhum** dos 8 planos os tocou, e `state.advance-plan` **não foi rodado**.

### ⚠️⚠️ AS TRÊS FACES DO LIMITE — todas se concretizaram, e eu medi cada uma

| face | medida |
|---|---|
| **1 — SOBRE-captura** (`git log --grep` lê a mensagem INTEIRA) | **39 pelo assunto × 43 pela mensagem** = **+4** sobre-capturados (189-03 ×1, 189-05 ×2, 189-06 ×1), todos commits de **outros planos** que citam o escopo no CORPO |
| **2 — SUB-captura** (toca arquivo da fase SEM o escopo no assunto) | **DOIS**: `62e5efae fix(gates):` edita `tests/js/publicador-lightbox-acoes-189.test.js` (gate do 189-07) e `85c3b46b fix(quick-261020-tdz):` edita `Aprovacao.jsx` ➡️ **a varredura por assunto NÃO vê nenhum dos dois** |
| **3 — o autor** | ⚠️ **CORRIGE o briefing**: há **DUAS** identidades nos últimos 120 commits (`MB.ECF-100376` ×118, **`ECF Dev` ×2**). A conclusão segue certa (os **39** do escopo são o mesmo nome), mas o único commit alheio que **de fato quebrou um gate** (`cd582f83`) **É** author-discriminável |

### ⚠️⚠️ SEIS caminhos FANTASMA pegos ANTES de qualquer diff

Eu construí a lista de alvos a partir dos **NOMES** citados na régua §8, no `CLAUDE.md` e no
briefing. **`test -e` reprovou 6 de 32 (19%)**: `ArquivosDeImagem.php` e `ResolvedorGruposImagem.php`
vivem sob **`Imagem/`**; `PublicacaoService.php` está em **`app/Services/Publicador/`** (não em
`Mlb/Publicacao/`); `PainelCriativosIa.jsx` e `KitCriativosGrade.jsx` em
**`resources/js/Pages/Mlb/components/`**; `ImagemAssetService.php` **sem** o `Imagem/`.

⚠️⚠️ **A LIÇÃO: *um NOME não é um CAMINHO*.** Veto escrito a partir do nome é **gate sobre ZERO
casos** e passa **VERDE para sempre**. ⚠️ **Total da milestone: 18 fantasmas, TRÊS de briefing.**
⚠️ **E o terceiro importa para a Fase 193:** o `POST /items` do Publicador está em
`app/Services/Publicador/PublicacaoService.php:442`.

---

## §3 — OS 8 CRITÉRIOS: `PLACAR-189: OK=5 RESSALVA=3 REPROVA=0`

**Contados por mim:**
`awk '/^### Phase 189:/,/^### Phase 190:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "` → **8**,
numerados 1..8 sem buraco. ⚠️ **Isca do `awk` exercida:** o mesmo comando sobre a **188** devolve
**20**, sobre a **187** **15**, sobre a **186** **17** — as quatro entradas são vizinhas, e um `awk`
errado traz a fase errada **em silêncio**.

| # | veredicto | o essencial |
|---|---|---|
| 01 | **OK** | tela cheia por portal, grid `minmax(0,1fr) 320px`, `z-[41]` pela ORDEM (o `90` do protótipo **RECUSADO**), header intocado |
| 02 | **RESSALVA** — dono: **usuário** | 1:1 até 78vh, marcadores coincidindo por prova cruzada; ⚠️ **o MOVIMENTO do zoom NÃO é provado** (a função pura é) |
| 03 | **OK** | zero `duration-[…]`/`delay-[…]`/`ease-[…]`; transição inteira por `style`, com `duration-200` de CONTROLE no CSS construído |
| 04 | **RESSALVA** — dono: **Fase 190 ⛔ CANCELADA ⇒ sem dono** | a lateral entrega metadados, original, verificações e botões; **"Comparar lado a lado" não é desenhado**; e a linha **"Variação"** também não (lacuna de DADO decidida, **com o custo funcional escrito**) |
| 05 | **OK** | estado IMG-13 lido das constantes do **MODELO**, nunca quadro vazio, nunca `<img src="">` |
| 06 | **OK** | só `live` edita; `regenerating` não aceita — nos **três** casos nomeados, com o `ao_vivo` do CONTEXTO e a trava REAL no servidor (422) |
| 07 | **RESSALVA** — dono: **usuário** | `←/→` dão a volta (o painel clampa — **não** unificados), `Esc` um degrau por vez, foco no `⤢ Ampliar`; ⚠️ **o RUNTIME do teclado e a devolução do foco NÃO são provados** |
| 08 | **OK** | FID-01 declarando como mediu: **6 de 8 por SCRIPT, 2 SEM-PROTOTIPO, ZERO por LEITURA** |

### ⚠️⚠️ Onde eu DIVIRJO do planejador, e por quê

O `189-COBERTURA.md` previa **02** e **07** como *"OK com a ressalva declarada"*. **Eu os escrevo
RESSALVA.** Razão: os dois critérios afirmam **comportamento de RUNTIME** e esta fase prova a
**FUNÇÃO PURA** — não há navegador. ➡️ ***"OK com ressalva" é exatamente a forma que a forma fixa
existe para impedir***: ou o veredicto carrega a ressalva, ou ela desaparece na leitura.

### ⚠️⚠️ O CRIT-04 e o dono que deixou de existir

O `must_haves` e o critério de aceitação do plano (**19/10**) mandam `dono: Fase 190`. Em **20/10** o
usuário **cancelou as Fases 190 e 191** (decisão 20, `0607f5a9`). ➡️ **A linha carrega AS DUAS
COISAS**: a atribuição original **e** o cancelamento medido, terminando em **"sem dono (190/191 fora
de escopo em 20/10/2026)"**. Escrever só a primeira faria o leitor **esperar entrega de uma fase que
não existe**; só a segunda **apagaria** o que o plano fixou. ⚠️ **O que NÃO foi feito é o CRIT-04
sair `OK`** — e o gate o **proíbe**, nas duas direções.

---

## §4 — AS MINHAS DECISÕES (as duas que o briefing me mandou tomar)

### `publicador-lightbox-casca-189.test.js:1352` → **NÃO consertar**

**Medido:** o furo que ela deixava **já está fechado** em
`publicador-lightbox-camadas-189.test.js:726`-`:733` (189-04, `86b887bf`), pelas **quatro formas
EXATAS** (`import { createPortal } from 'react-dom';`, `return createPortal(`, `document.body,`, a
guarda de ambiente), **cada uma casando 1 vez**, com a isca construída **do mutante M1 de verdade** e
uma asserção que **registra** que a sonda larga continuaria verde.
➡️ O `:1352` é **REDUNDÂNCIA LARGA, não FURO**. Editá-lo mudaria o `tests=27` de uma wave **fechada**
sem ganhar invariante; removê-lo é o "conserto barato". **Dono: nenhum pendente.** Fica **NOMEADO**
como a 15ª ocorrência da família.

### A família *"gate mede WIP, não território"* → **declarar, NÃO consertar**

⚠️⚠️ **O registro dizia TRÊS. Medi 24 pontos em 7 arquivos** (`numstatDe(rel)` sem o 2º argumento →
`HEAD` → compara a **ÁRVORE**), mais os dois `git status --porcelain` do `casca-188`.
⚠️ **E a isca de não-cegueira de cada ponto é ELA MESMA mais larga que o invariante:** ela prova que
**o pathspec resolve**, não que **um commit seria pego**.
⚠️⚠️ **Mas o conserto de RAIZ já está no código:** o helper **aceita a base**, e o `casca-188:549`
**já usa a forma certa** (`HEAD~1 HEAD`, um **RANGE**).
➡️ **Não consertei:** são gates de fase **EM PRODUÇÃO** (188), fora do `files_modified` deste plano e
fora do que a régua §8 autoriza. **Dono: usuário / orquestrador.**
⚠️ **Estado hoje, medido:** `git status --porcelain -- database/migrations` sai **VAZIO** (a outra
sessão commitou a migration em `1253cc86`) ➡️ o gate está **VERDE** — e isso **corrige o briefing**,
que a listava como ainda untracked.

---

## §5 — O GATE DESTE PLANO ACHOU DOIS FUROS NO PRÓPRIO DOCUMENTO

⚠️ *"O que precisaria estar errado no mundo para este gate falhar?"* — rodei o gate e ele **reprovou
duas vezes**, as duas por FORMA:

1. **o recorte de seção devolve o CORPO, não o cabeçalho** ➡️ *"conta 459"* estava **só no título**
   da §11;
2. depois de pôr no corpo, **`a conta **459**` CONTINUOU não casando** `conta\s+459` — ⚠️ **os
   asteriscos do markdown ficam ENTRE as duas palavras**, e `\s+` não os atravessa.

➡️ **É face NOVA** da família *"a frase quebra no markdown e o gate não a acha"* (a face conhecida era
a quebra de linha em 100 colunas). Resolvido escrevendo o número **sem negrito**, com a razão **no
próprio documento**.

⚠️ **E eu achei UMA ARMADILHA no critério de aceitação do meu plano:** `grep -c "a definir"` na seção
de perguntas = **0** é **falso por construção** — escrever **a própria regra** ali faria o `grep`
devolver **≥1 mesmo com todas as perguntas tendo dono**. ➡️ Rodei o **equivalente honesto, por
linha** (cada `PERGUNTA-*` casa `dono:\s*\S` e **não** casa `dono:\s*a\s+definir`): **7 de 7 com
dono, 0 indefinidos** — e registrei.

---

## §6 — OS 8 MANIFESTOS, AS TRÊS BIJEÇÕES E O HARNESS

```
21 ids em 8 manifestos   PARES_MEDIDOS=8   ORFAOS=0   NAO_DECLARADOS=0
ARQUIVOS=1232  METODOS=7980  HOMONIMOS=0  PREFIXOS=0
DECLARADOS_EXPANDIDOS=21  DECLARADOS_SEM_METODO=0  METODOS_DA_FASE_189=21  SEM_ID=0
vocabulario-tamanhos: declaram 5, rodam os MESMOS 5, id composto 0, forma >= sempre, REAL = 695
--autoteste: 20 casos, 0 divergencia (exit=0)
```

⚠️ **25 ids re-rodados, TODOS `exit=0`** (os 14 da fase + as 8 não-regressões + o da quick + os 3
deste plano), com o **`exit` medido na linha SEGUINTE ao comando, sem pipe** — `cmd | tail` devolve o
exit do `tail`, **sempre 0**.
⚠️ **E a isca do 1º argumento, exercida:** `verificar-testes.sh 08 fechamento-territorio` →
**`exit=2`**, *"ABORTOU: … não está em `contagens-186-08.txt`"*.
⚠️ **Nenhuma contagem ALHEIA foi subida:** `casca-do-painel` **45**, `coluna-de-fotos` **32**,
`tokens-da-fila` **17** — exatamente o que os manifestos daquelas fases declaram.

⚠️ **Este plano NÃO tem mutação, e está DECLARADO no manifesto** (ele não escreve código de produção:
ele **mede**). A prova de que o gate não é cego são as **iscas internas** de cada método — e **não há
2º regime** (`tests=N,M`) em nenhuma linha, porque inventar um 2º número seria afirmar medição que
não houve.

---

## §7 — AS PERGUNTAS, TODAS COM DONO

| pergunta | estado |
|---|---|
| `189-A`, `189-B`, `189-C` | **RESPONDIDAS** (usuário, 19/10), com a resposta e onde foi aplicada |
| **`189-D`** | ⚠️ **ABERTA — dono: usuário.** Os defaults **D1** e **D2** foram **aplicados e declarados**; a asserção que **distingue** default de alternativa (`recarregarNaOferta = null SEMPRE`) está **travada no gate** — trocar para a alternativa **REPROVA**, de propósito |
| **`VISUAL-189`** | ⚠️ **ABERTA — dono: usuário** (o roteiro na §11) |
| **`VISUAL-188`**, **`VISUAL-187`** | ⚠️ **ABERTAS e SEPARADAS** — esta fase **não** as substitui |

**Os três AVISOS** que a 189 mandou à sessão da 188 (`AVISO-189-1/2/3`) estão na §8 do fechamento com
**o que a 188 fez com cada um** — os três **ATENDIDOS**, o segundo como **lacuna decidida**.

---

## §8 — OS ITENS SEM DONO E COM DONO

| item | dono |
|---|---|
| **AMPL-05** — "Comparar lado a lado na revisão detalhada" | **sem dono (190/191 fora de escopo em 20/10/2026)** |
| `refLabel` — o placeholder da caixa da original (conserto de **1 linha** no `LightboxDoPainelService`) | **sem dono (190/191 fora de escopo em 20/10/2026)** |
| a linha **"Variação"** (com o custo funcional: num produto de 3 cores as fotos saem juntas **sem dizer qual é de qual cor**) | **sem dono (190/191 fora de escopo em 20/10/2026)** |
| **AMPL-07** — "✦ Gerar novamente com IA" | **Fase 192** (a ÚLTIMA da milestone) |
| a família **WIP** (24 pontos) e os ponteiros do **`189-FID01.md` §G** | **usuário / orquestrador** |
| `casca-189:1352` | **nenhum pendente** (o invariante tem dono e está provado) |

---

## §9 — O QUE EU **NÃO** POSSO AFIRMAR

- ⚠️⚠️ **Eu não vi tela.** Não abri navegador, não visitei rota, não vi o lightbox. **Nada** neste
  documento é relato de observação.
- ⚠️ **Não posso afirmar que o zoom segue o cursor**, nem que `Esc` devolve o foco **em runtime** —
  por isso os critérios **02** e **07** saem **RESSALVA**.
- ⚠️ **Não posso afirmar que a suíte PHP inteira está verde** — ela **não é medível** aqui.
- ⚠️ **Não posso afirmar que as falhas fora do recorte são as que o briefing lista** — eu **não as
  medi**, e digo isso.
- ⚠️ **Não posso afirmar que o território está provado para commits SEM o escopo no assunto** — a
  **FACE 2** mostra dois que a varredura não vê.
- ⚠️ **Não deployei, não dei push, não toquei no VPS, não chamei a API do Mercado Livre, e esta fase
  não tem migration.**

---

## §10 — ONDE ESTE PLANO PAROU

| tarefa | estado |
|---|---|
| **1** — re-medir baselines e território | **COMPLETA**, commitada em `799f5d90` |
| **2** — os 8 critérios, FID-01, mutação, manifestos e bijeções | **COMPLETA**, commitada em `799f5d90` |
| **3** — o roteiro da conferência visual (checkpoint humano **BLOQUEANTE**) | **A §11 está escrita.** ⚠️ **PARADO AQUI, aguardando o usuário** |

## Self-Check: PASSED

| claim | conferência |
|---|---|
| `189-FECHAMENTO.md` existe | `test -e` → **FOUND** |
| `FechamentoDaFase189Test.php` existe | `test -e` → **FOUND** |
| `contagens-189-08.txt` existe | `test -e` → **FOUND** |
| commit `799f5d90` existe | `git log --oneline --all \| grep` → **FOUND** |
| os 3 ids saem `exit=0` | **3 passed (32)** · **3 passed (32)** · **3 passed (21)** |
| `--autoteste` | **20 casos, 0 divergência**, `exit=0` |
| nenhuma deleção no commit | `git diff --diff-filter=D HEAD~1 HEAD` → **vazio** |
