---
phase: 169-etapa-de-imagens-no-editor-mais-texto-real-no-kit
plan: 03
subsystem: ui
tags: [creative-engine, product-truth, publicador, react]

requires:
  - phase: 169-02
    provides: "Endpoints GET/POST/DELETE mlb.anuncios.publicador.criativos.fatos(.salvar|.remover), resposta {confirmados, pode_ter_texto, faltam}"
provides:
  - "useCriativosDoPublicador(): estado fatos + carregarFatos()/salvarFato()/removerFato()"
  - "PainelCriativos.jsx: componente FatosDoProduto({ c }) — bloco visível de confirmação de ponto forte/medida"
affects: [169-04]

tech-stack:
  added: []
  patterns:
    - "Falha de rede silenciosa em leitura complementar (carregarFatos) vs. setErro() em escrita explícita do operador (salvarFato/removerFato) — mesma distinção que o resto do hook já fazia entre abrir()/planejar()"
    - "Bloco de UI gated por uma única condição combinada ((fase==='escolhendo'||fase==='kit')) em vez de duplicado dentro de cada ramo condicional — evita divergência futura entre os dois caminhos"

key-files:
  created: []
  modified:
    - resources/js/Components/Publicador/useCriativosDoPublicador.js
    - resources/js/Components/Publicador/Mesa/PainelCriativos.jsx
    - tests/js/publicador-painel-criativos-render.test.js

key-decisions:
  - "paraFatos() defensivo no HOOK (Array.isArray em confirmados/faltam, !! em podeTerTexto) — mesmo assim FatosDoProduto repete a mesma defesa, porque o teste de render real monta o componente com um mock de `c.fatos` que NÃO passa pelo hook (bypassa paraFatos); a defesa dupla é intencional, não redundância descartável"
  - "publicador-mesa.test.js não precisou de edição: os gates de fonte existentes (sem route( cru, sem bg-ecf-yellow sólido, tipografia 24/15/13/11px, peso 400/700) já leem o arquivo inteiro via lerSemComentarios() e já cobriam o código novo sem ajuste — confirmado rodando a suíte antes de tocar no arquivo"

requirements-completed: [TXT-01, TXT-04, REND-01, REND-02]

duration: ~30min
completed: 2026-10-07
---

# Phase 169 Plano 03: O operador confirma fatos dentro do painel que já existe hoje — Summary

**Dentro do painel "Gerar fotos com IA" que já existe na etapa Detalhes (sem tocar `Editor.jsx`/`EtapaDetalhes.jsx`), o operador agora confirma um ponto forte ou uma medida do produto, vê a lista do que já confirmou, e lê — em português, já dizendo o que fazer — por que nenhuma imagem ainda pode sair com texto quando é o caso; tudo provado por um teste que renderiza o componente de verdade (esbuild + `react-dom/server`), não só regex sobre a fonte.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 2/2 completas
- **Files modified:** 3 (nenhum arquivo novo)

## Accomplishments

- `useCriativosDoPublicador` ganhou `fatos` (estado) + `carregarFatos()`/`salvarFato()`/`removerFato()`, consumindo os três endpoints da 169-02 sem criar rota nova nenhuma (reaproveita a mesma `rota = criarRota('mlb.anuncios.publicador', 'produto')` já existente no topo do arquivo).
- `carregarFatos()` é fire-and-forget dentro de `abrir()` e falha em silêncio de propósito — o bloco de fatos é complementar, nunca pode travar o resto do painel (escolher fotos, planejar, gerar). `salvarFato`/`removerFato` usam `setErro(mensagemDe(e))` porque ali o erro É ação explícita do operador.
- `PainelCriativos.jsx` ganhou `FatosDoProduto({ c })`: lista os fatos confirmados com botão "remover", um formulário mínimo (tipo + texto + "Confirmar") reaproveitando `CAMPO`/`SELECT` de `comum.jsx`, e o aviso de "ainda não é possível colocar texto" com as mensagens já actionable que vêm de `CreativeSlotCatalog::faltamParaTexto()` (169-02) — a tela não inventou texto novo de orientação, só exibe o que o servidor já manda.
- Montado **uma única vez**, fora dos blocos condicionais de `'escolhendo'`/`'kit'` (uma condição combinada), nunca em `'carregando'`/`'parado'` — exatamente como o plano pediu, sem duplicar o JSX nos dois ramos.
- REND-01/02: `item.texto` (cada fato confirmado) e cada linha de `faltam` passam por `textoSeguro()` antes de virar filho do React — mesma defesa de `Estrategia`/`CartaoSlot`, pela mesma fronteira presenter-PHP → React que causou a tela preta de 261007. Três casos novos no teste de render real provam isso com `renderToStaticMarkup` de verdade (não regex sobre a fonte).
- `npm run build` confirmado: o texto novo ("Pontos fortes e medidas do produto") está no bundle de produção (`Editor-*.js`), não só no código-fonte.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Hook ganha fatos/carregarFatos/salvarFato/removerFato** — `006d5042` (feat)
2. **Task 2: Bloco de fatos no painel + teste de render real (REND-01/02)** — `1b715a20` (feat)

**Plan metadata:** não commitado (SUMMARY/STATE/ROADMAP ficam fora de git por instrução explícita deste plano).

_Nenhuma task teve RED→GREEN separado em commits distintos — os testes foram escritos e verificados junto com a implementação de cada task (mesmo molde das fases 160/161/165/169-01/169-02 para este módulo), com todos os testes passando antes de cada commit._

## Files Created/Modified

- `resources/js/Components/Publicador/useCriativosDoPublicador.js` — estado `fatos`; `paraFatos()` (conversão camelCase defensiva); `carregarFatos()`/`salvarFato()`/`removerFato()`; `abrir()` chama `carregarFatos()` fire-and-forget
- `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx` — componente `FatosDoProduto({ c })` novo; import de `CAMPO`/`SELECT`/`useId`; montado uma vez dentro do painel
- `tests/js/publicador-painel-criativos-render.test.js` — mock `c(kit, fatos)` ganhou `fatos`/`carregarFatos`/`salvarFato`/`removerFato`; 3 casos de render real novos (aviso com 2 mensagens, texto confirmado em formato inesperado, `faltam` em formato inesperado)

`tests/js/publicador-mesa.test.js` estava no `files_modified` do frontmatter do plano, mas **não precisou de nenhuma edição**: os gates de fonte existentes (`sem route( cru`, `sem bg-ecf-yellow sólido`, tipografia 24/15/13/11px, peso 400/700, "sem axios/primario/kit_token" no teste específico de `PainelCriativos.jsx`) já leem o arquivo inteiro via `lerSemComentarios()` — confirmado rodando a suíte inteira depois das mudanças, sem falha nova, sem precisar adicionar assert novo.

## Decisions Made

- **Defesa dupla (hook + componente) contra formato inesperado:** `paraFatos()` no hook já normaliza `confirmados`/`faltam` para array e `podeTerTexto` para booleano. `FatosDoProduto` repete exatamente a mesma normalização (`Array.isArray`) porque o teste de render real (e qualquer chamador futuro do componente) pode passar `c.fatos` sem ter passado pelo hook — a defesa no componente não é redundância descartável, é a garantia que o REND-01/02 exige.
- **Mensagem de "falta fato" é a mesma string do servidor, sem reescrita no front:** as duas mensagens fixas de `CreativeSlotCatalog::faltamParaTexto()` (169-02) já dizem "Confirme mais N ponto(s) forte(s)... (no cadastro do Mercado Livre ou aqui mesmo, como fato confirmado)..." — já actionable, já em pt-BR, já apontando os dois caminhos. O painel só adiciona a frase de contexto "Ainda não é possível colocar texto em nenhuma imagem:" antes da lista, sem duplicar nem reescrever o que o servidor já manda.
- **Botão "remover" nunca chama `c.removerFato` com id inválido:** `idFato = typeof item?.id === 'number' ? item.id : null`; o botão fica desabilitado quando `idFato === null` — nunca dispara `removerFato(null)` ou `removerFato(undefined)` se o presenter mandar um `id` em formato inesperado.

## Deviations from Plan

Nenhuma mudança de comportamento fora do que o plano descreveu. Um esclarecimento: `tests/js/publicador-mesa.test.js` estava listado em `files_modified`, mas os gates de fonte já existentes cobriram o código novo sem precisar de edição (documentado acima, não é desvio de código de produção).

## Issues Encountered

Nenhum. Rotas (`criativos.fatos`/`criativos.fatos.remover`) e constantes de tipo (`'beneficio'`/`'medida'`) foram confirmadas por leitura direta de `routes/mlb_anuncios.php` e `app/Models/PubProdutoFatoCriativo.php` antes de escrever o hook — bateram exatamente com o que o `169-02-SUMMARY.md` documentava.

## Verificação executada (resultado real)

```
node --test tests/js/publicador-editor.test.js tests/js/publicador-mesa.test.js
→ Tests: 158 passed, 0 failed (gate de fonte, Task 1 — hook não altera nenhum arquivo lido por este gate, roda como baseline)

node --test tests/js/publicador-painel-criativos-render.test.js tests/js/publicador-mesa.test.js
→ Tests: 119 passed, 0 failed (inclui os 3 casos novos de render real da Task 2)

npm run build
→ built in 42.69s, sem erro; grep confirma "Pontos fortes e medidas do produto" em
  public/build/assets/Editor-BuV9_8Yc.js (bundle de produção, não só a fonte)

npm run test:js (suíte JS completa)
→ Tests: 1132, pass: 1130, fail: 2
  As 2 falhas são as PRÉ-EXISTENTES já documentadas (estrutura-grade-glide.test.js,
  polosEntrantes.test.js) — confirmadas por leitura da saída, nenhuma menciona
  PainelCriativos/useCriativosDoPublicador/fatos. Não são regressão deste plano.
  (1130 = 1127 da baseline anterior + 3 casos novos deste plano.)
```

Nenhum teste PHP foi afetado — confirmado por `git diff --stat` entre o commit anterior (`82d4ea34`, fim da 169-02) e o HEAD deste plano, restrito a `app/`, `routes/`, `database/`: zero mudança. A suíte PHP de 816 testes do Publicador não precisou ser re-executada porque nenhum arquivo PHP foi tocado por este plano.

Confirmado por `git diff --stat` que `Editor.jsx`, `apoio.js`, `EtapaDetalhes.jsx` e `FotosPorGrupo.jsx` não foram tocados (zero linha alterada) — a restrição do prompt de execução foi respeitada.

## Mensagens finais escritas na tela (texto literal)

Título do bloco (sempre visível quando `c.fatos` não é `null`):
> **Pontos fortes e medidas do produto**
> O que você confirmar aqui pode aparecer escrito numa das imagens geradas.

Aviso de "falta fato" (só quando `pode_ter_texto` é falso — texto literal vem do servidor, 169-02):
> Ainda não é possível colocar texto em nenhuma imagem:
> Confirme mais N ponto(s) forte(s) do produto (no cadastro do Mercado Livre ou aqui mesmo, como fato confirmado) para habilitar texto no slot de benefícios.
> Confirme uma medida do produto (no cadastro do Mercado Livre ou aqui mesmo, como fato confirmado) para habilitar texto no slot de dimensões.

Cada fato confirmado (lista):
> **Ponto forte:** [texto confirmado] — [remover]
> **Medida:** [texto confirmado] — [remover]

Formulário:
> O que você quer confirmar?  [Ponto forte ▾ / Medida]
> [campo de texto, placeholder "ex.: motor silencioso", até 300 caracteres]
> [Confirmar]

Nenhum termo técnico ("fato", "slot", "badge", "Product Truth") aparece em texto visível ao operador — "fato"/"FatosDoProduto" existe só em nomes de variável/componente e em comentários pt-BR no código-fonte.

## Confirmação: nenhum token no front

Confirmado por leitura do código: `FatosDoProduto` só lê `item.id` (inteiro, usado na própria chamada de `removerFato`, nunca exibido na tela), `item.tipo`/`item.texto` e as strings de `faltam`. Nenhum id de kit/slot/portador, nenhum token de 32 caracteres entra no JSX. O hook só adiciona três chamadas novas às mesmas duas rotas nomeadas (`criativos.fatos`, `criativos.fatos.remover`) que já respondem sem token (D-13, confirmado na 169-02).

## Espelhamento visual (vizinhos usados como referência)

- `CartaoSlot` (mesmo arquivo) — molde do bloco de aviso (`border-amber-400/30 bg-amber-500/10`, tamanho `text-[11px]`) e do padrão label+controle+botão empilhado (como o bloco "O que não ficou bom?" de regenerar).
- `comum.jsx` — `CAMPO`/`SELECT` (44px, borda clara, foco amarelo) para o campo de texto e o select nativo; `LINK` para os botões "remover"/"Confirmar" (mesmo estilo discreto usado em "Fechar", "Agora não", "Pôr de novo no anúncio").
- Tipografia/peso: só `text-[13px]`/`text-[11px]` e `font-normal`/`font-bold`, confirmado pelo gate de fonte de `publicador-mesa.test.js` que já cobria o arquivo inteiro.

## User Setup Required

None — nenhuma configuração de serviço externo. Migration da 169-01 (`pub_produto_fatos_criativo`) continua pendente em produção, como já documentado nas duas ondas anteriores; não houve deploy nem comando de VPS neste plano.

## Next Phase Readiness

TXT-01/TXT-04/REND-01/REND-02 completos e visíveis ao operador, antes da quarta etapa existir. Quando a `169-04` (gate humano com o outro dev, fora deste plano) mover `FotosEVariacoes`/`BlocoDeFotos` para a nova etapa Imagens, o bloco `FatosDoProduto` vai junto sem reescrita — ele depende só de `c` (o hook), nunca de qual etapa o envolve.

---
*Phase: 169-etapa-de-imagens-no-editor-mais-texto-real-no-kit*
*Completed: 2026-10-07*

## Self-Check: PASSED

Confirmado por leitura em disco que os 3 arquivos modificados existem com o conteúdo esperado
(`useCriativosDoPublicador.js` com `fatos`/`carregarFatos`/`salvarFato`/`removerFato` no retorno do
hook; `PainelCriativos.jsx` com `FatosDoProduto` definido e montado; o teste de render real com os
3 casos novos) e por `git log --oneline -3` que os dois hashes (`006d5042`, `1b715a20`) estão na
história do branch `main`, imediatamente após `82d4ea34` (fim da 169-02). Nenhum arquivo deletado
por nenhum dos dois commits (`git diff --diff-filter=D` vazio em ambos).
