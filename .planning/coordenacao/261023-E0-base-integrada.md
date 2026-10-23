# E0 — base integrada · 23/10/2026

Registro curto, como o plano pede (§E0: *"não um novo documento de descoberta de dezenas de páginas"*).

## O que passou a funcionar

A base local passou a conter **todo** o trabalho do remoto: Vitrine, Prévia (`EtapaPrevia.jsx`,
`AnuncioNoMl.jsx`, `PreviaDoAnuncioService.php`), os consertos da fila e o `despacharCadeias`.
O agente parcial foi **preservado** e seus 4 ganchos seguem no lugar.

```
PARTIDA          b4ae4eb9
merge-base       e250f9fb
origin/main      ed8fdc15  (23/10 11:39)  ← o SHA que o plano cita
merge             42c1bd66
118 commits deles integrados · 101 nossos preservados · 0 faltando agora
```

⚠️ **A referência local de `origin/main` estava VELHA** (`bc8feb90`). O `fetch` corrigiu, e com isso o
número que eu havia reportado antes (*54 commits faltando*) estava errado: eram **118**. É a armadilha
que o próprio plano avisa.

## Como comprovei

| prova | resultado |
|---|---|
| marca de conflito em arquivo rastreado (`git grep`) | **0** |
| arquivos preparados no merge / inesperados | **327 / 0** |
| não-rastreados antes → depois (protótipo + planning) | **64 → 64** |
| `php -l` no arquivo de conflito delicado | **OK** |
| suíte do Agente | **42 passed (241 asserções)**, `exit 0` |
| `npm run build` | **exit 0**, 6020 módulos, `built in 1m 26s` |
| suíte JS | **6181 / 6175 pass / 6 fail** |

**Nenhum** push, deploy, migration, `stash`, `reset`, `checkout`, `clean` ou `--amend`.

## Os 5 conflitos

1. **`PreparoIaDoRascunhoService.php`** — mudança dos **dois** lados: a escolha do remoto
   (`EditorEmUso::editando`) com o gancho `agenteParado` no **mesmo ponto lógico**. ⚠️ **Não era
   rename:** a classe tem os dois métodos e são **sinais diferentes** (`emUso`↔`chave`,
   `editando`↔`chaveDaEdicao`). As outras 3 chamadas a `emUso()` seguem legítimas.
   Ganchos conferidos: imports `:19-20` · `aoPreparar` `:152` · `agenteParado` `:337` e `:385` ·
   `situacaoDoTexto` `:937` · `agenteParado` `:958`.
2. **`publicador-vocabulario-novo.test.js`** e 3. **`publicador-fila-fechamento-187.test.js`** —
   listas: **união**. `Agente.jsx` conferido em `CONTAINER_POR_PAGINA` (`:1326`) e `ABA_POR_PAGINA`
   (`:1347`) — e o remoto tem **zero** ocorrências de `Agente` ali, logo são nossas e sobreviveram.
4. **`.planning/STATE.md`** — união (aditivo dos dois lados).
5. ⚠️ **`Aprovacao.jsx` — REDUÇÃO DE ESCOPO DECLARADA.** 10 regiões, e não era aditivo: o commit
   `0db8c025` deles se chama *"fila de aprovação — **sem tela preta**, busca, filtros e modais"*.
   **Eles consertaram a tela preta independentemente** e a versão deles **não tem o lightbox**.
   ➡️ Tomei a versão do remoto, com base na **§2.2** (*"não concluir a antiga jornada como
   pré-requisito"*) e na **§10** (*"atualizar testes acoplados à tela tombada… em vez de perpetuar UI
   obsoleta"*). ⚠️ **Verificado antes de tomar:** a versão deles **não tem o defeito de TDZ** — os
   cinco símbolos são declarados antes de lidos. **O que sai é a INTEGRAÇÃO do lightbox; os
   componentes ficam no disco** (`LightboxDaImagem.jsx`, `LateralDoLightbox.jsx`).

## As 6 falhas JS, atribuídas (o plano manda registrar antes de culpar o trabalho novo)

| # | teste | causa |
|---|---|---|
| 195 | *"Características secundárias nasce recolhido"* | **pré-existente**, Fase 87. Não corrigir. |
| 1682 | *"nenhum FILTRO mora em `useState`… 187-06"* | ⚠️ **corrigido em 23/10 — ver §"Atribuição corrigida"**: **não** era o remoto; era `sem-useState=[doLightbox]`, entrada **morta** na lista. Mesma causa dos quatro abaixo. |
| 1840 · 1841 · 1842 | gates **189-07** do fio do lightbox na página | ⚠️ **consequência declarada** do item 5. |
| 1934 | gate **189-06** do repasse de `lightbox` | ⚠️ **idem**. |

➡️ **Nenhuma é defeito de funcionalidade:** 1 é pré-existente e **as outras 5** são consequência
declarada do tombamento — asseveram uma integração que **saímos de propósito**.

### ⚠️ Atribuição corrigida — a falha 1682 **não** era do remoto

Eu havia escrito que o `CampoDeBuscaDaFila.jsx` deles, com rascunho local na busca, havia encontrado
o gate `187-06`. **Medido agora, e está errado nos dois sentidos:**

- o arquivo deles **tem** 2 `useState` — mas **este gate nunca o lê** (ele lê `FiltrosDaFila`,
  `PopoverDeFiltro` e a página; `grep -n CampoDeBusca` no arquivo de teste → **zero linhas**);
- a mensagem real era `sem-useState=[doLightbox]`: **entrada morta na lista**, porque a integração
  do lightbox saiu no merge. ➡️ **Mesma causa das outras quatro.**

⚠️ **E o gate estava FUNCIONANDO como foi desenhado:** o comentário da própria lista diz que
*"entrada que não existe mais na página também reprova — entrada morta é linha morta"*, e que isso
é justamente o que a contagem antiga **não** cobria. Logo o conserto foi tirar a **entrada**, nunca
tocar a asserção.

## O que falta / próximo passo

1. ✅ **FEITO em 23/10 — os 5 gates da tela tombada, reapontados com cobertura equivalente (§10).**
   **Suíte JS: 6181 / 6180 pass / 1 fail**, e a única falha é a **pré-existente da Fase 87**.
   Os três ids passam pelo harness: `acoes-do-lightbox` 25/25, `lightbox-no-painel` 22/22,
   `filtros-render` 29/29. ⚠️ **Nenhuma contagem de manifesto mudou** — o invariante foi
   **invertido**, não removido, e é por isso que o número ficou igual:

   | gate | antes provava | agora prova |
   |---|---|---|
   | `189-07` (3 casos) | a página liga o fio do lightbox | a página **não** liga (com isca sintética) · os **componentes ficaram no disco** · a proteção **viva** |
   | `189-06` (1 caso) | 4 asserções de integração + as vivas | o fio **ausente** por conjunto nomeado + **as vivas intactas** |
   | `187-06` (1 caso) | — | a entrada **morta** `doLightbox` saiu da lista, com o porquê |

   ⚠️ **O que sobreviveu de propósito:** a página segue sem `String(` e sem `localStorage`.
   **Medido:** o arquivo **cru** tem **7** `String(` e **5** `localStorage` — **todas em comentário**,
   explicando por que não se usa. Os dois gates leem pelo `lerSemComentarios`, e **é só por isso**
   que não reprovam pelo próprio conserto.
2. **E1** — shell do agente, configuração versionada, pausa/suspensão/retomada, rotina/fila e
   atividade com dados reais, reaproveitando a página e o controller locais.

## Pendências herdadas que o E0 não resolve

- ⚠️ **SHA realmente implantado na VPS: NÃO verificado.** O plano exige isso antes de qualquer deploy,
  e nada foi deployado.
- A **ponte** do aceite da Prévia até o `mover(APROVADO)` segue por nomear (tombamento §3).
- ⚠️ **Não ligar o gatilho da 193** para o fluxo novo — a publicação sai do comando da Prévia.
