# Cobertura da Fase 189 — auditoria multi-fonte (GOAL · REQ · CONTEXTO · DECISÕES)

**Como os critérios foram contados (comando):**
`awk '/^### Phase 189:/,/^### Phase 190:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "` → **8**,
numerados 1..8 sem buraco (medido pelo planejador em 19/10/2026). ⚠️ 20 é a contagem da 188.

Não há `RESEARCH.md` nem `CONTEXT.md` nesta fase (D-189-1): a fonte de contexto é a nota
`.planning/coordenacao/261019-para-quem-planejar-a-fase-189-o-que-nao-esta-no-codigo.md`.

**Os 8 planos e as ondas:**

| wave | plano | o que entrega | cria `.jsx`? |
|---|---|---|---|
| 1 | 189-01 | gate de entrada (188 fechada), baselines, inventário da 188, FID-01 re-medida, `18` liberado | não |
| 2 | 189-02 | servidor: `LightboxDoPainelService` + prop `lightbox` | não (só PHP; **não builda**) |
| 2 | 189-03 | `LightboxDaImagem.jsx`: funções puras, portal, palco, marcadores, miniaturas, setas (HTML e render adverso) | **sim** |
| 3 | 189-04 | a prova FORA do render: ordem de camadas, GATE-09 na fonte e no CSS, CSS das classes novas, build e a mutação do lightbox (dividido do 189-03 a pedido do plan-checker) | não |
| 4 | 189-05 | `LateralDoLightbox.jsx`: metadados, original/IMG-13, verificações numeradas, zoom, travas | **sim** |
| 5 | 189-06 | o fio (e o CONTEXTO PRÓPRIO do lightbox, 189-B (b)): "⤢ Ampliar" no painel, inversão do gate do 188-08, estado e teclado, foco | não (edita da 188) |
| 6 | 189-07 | as ações com o CONTEXTO do lightbox (oferta/versão/foto; fixture A × B), o modal acima do lightbox (árvore), o default D2, build | não |
| 7 | 189-08 | fechamento medido + checkpoint da conta 459 | não |

⚠️ **02 ∥ 03 é seguro:** sem arquivo em comum, o 02 não cria `.jsx` nem roda `npm run build`, e a
suíte PHP roda em SQLite `:memory:` (`phpunit.xml:27`-`:28`). Todos os outros são sequenciais (`.jsx`
novos → `ARQUIVOS_NOVOS` por igualdade; `npm run build` global no 04, 05, 07; edições em `PainelDeRevisao.jsx`
em 06 e 07). O 04 depende só do 03, mas o 05 o espera para não haver dois builds simultâneos.

---

## §1 — GOAL (ROADMAP, Phase 189) × planos

| trecho do objetivo | plano(s) |
|---|---|
| tela cheia | 189-03 |
| imagem 1:1 de até 78vh | 189-03 |
| zoom de 2,5× seguindo o cursor | 189-03 (função pura + camada) · 189-05 (o botão liga/desliga) |
| marcadores numerados batendo com as verificações | 189-03 (marcadores) · 189-05 (ordinal na lateral + prova cruzada palco × lateral) |
| miniaturas | 189-03 |
| lateral de 320px com metadados | 189-05 |
| original de referência | 189-02 (dado) · 189-05 (tela) |
| verificações | 189-05 |
| ações de aprovar/reprovar/regenerar | 189-05 (botões e travas) · 189-07 (fio pelo `escreverNoPainel` com o contexto do lightbox) — **regenerar: RESSALVA → Fase 192 (D-189-3)** |

## §2 — Os 8 critérios de sucesso × planos

| # | critério (resumo) | plano(s) | veredicto previsto |
|---|---|---|---|
| 1 | `rgba(5,5,5,0.94)`, grid `minmax(0,1fr) 320px`, acima do painel, por portal, header intocado | 189-03 (casca), 189-04 (a ordem pelo comportamento), 189-06 (montagem), 189-07 (o modal acima, por árvore) | OK se os gates passarem |
| 2 | 1:1 até 78vh, zoom 2,5× pelo cursor, marcadores coincidem com a lateral | 189-03, 189-05 | OK com a ressalva **declarada** de que o MOVIMENTO não é provado (só a função) |
| 3 | sem `duration-[…]`/`delay-[…]`/`ease-[…]`, `duration-200` como controle | 189-03 (`style`), 189-04 (GATE-09 na fonte e no CSS, controle `duration-200`) | OK |
| 4 | lateral: metadados, original + "Comparar lado a lado…", verificações, botões | 189-02, 189-05, 189-07 | **RESSALVA — dono Fase 190** ("Comparar lado a lado" não desenhado, D-189-3) |
| 5 | sem original → estado declarado IMG-13 | 189-02, 189-05 | OK |
| 6 | só `live` edita; `regenerating` não aceita ação | 189-05 (`podeAgirNaFoto`), 189-06/189-07 (`ao_vivo` do CONTEXTO do lightbox; o servidor do 188-05 recusa com 422) | OK |
| 7 | ←/→ trocam a foto; Esc fecha devolvendo o foco ao painel na mesma imagem | 189-03 (funções), 189-06 (fio, foco), 189-07 (Esc com modal) | OK pela função; runtime declarado não provado; foco pela 189-C (respondida: o padrão); oferta mudada por baixo → default da 189-D1 (ABERTA, não bloqueia) |
| 8 | a tabela FID-01 do lightbox, declarando como mediu | `189-FID01.md` (planejador, SCRIPT) · 189-01 (re-medição) · 189-08 (consolidação) | OK |

## §3 — REQ-IDs × planos (todo ID em ao menos um `requirements:`)

| REQ | plano(s) |
|---|---|
| AMPL-01 | 189-03, 189-04, 189-08 |
| AMPL-02 | 189-03, 189-06, 189-08 |
| AMPL-03 | 189-03, 189-04, 189-08 |
| AMPL-04 | 189-05, 189-08 |
| AMPL-05 | 189-02, 189-05, 189-08 — **RESSALVA**: o botão "Comparar lado a lado" é da Fase 190 |
| AMPL-06 | 189-03, 189-05, 189-07, 189-08 |
| AMPL-07 | 189-05, 189-06, 189-07, 189-08 — **RESSALVA**: "✦ Gerar novamente com IA" é da Fase 192; "Ampliar" (o do painel e o liga/desliga da lateral) e Reprovar/Aprovar foto **entregues** |
| GATE-09 | 189-03, 189-04, 189-08 |
| IMG-09 | 189-05, 189-07, 189-08 |
| IMG-13 | 189-02, 189-05, 189-08 |
| TERR-06 | 189-03, 189-04, 189-06, 189-07, 189-08 |
| FID-01 | 189-01, 189-08 |
| FID-02 | 189-01, 189-08 |
| REVS-01 | 189-03 (o portal), 189-04 (a ordem de camadas que o lightbox respeita) |
| REVS-08 | 189-06 (o "⤢ Ampliar" que a 188 deixou de fora de propósito) |

## §4 — DECISÕES (D-189-*) × planos

| decisão | plano(s) | como |
|---|---|---|
| D-189-1 | todos | `<context>` cita a nota de coordenação e a régua |
| D-189-2 | 189-01, 189-08 | `189-FID01.md` por SCRIPT; gate que lê o protótipo com `skip` NOMEADO |
| D-189-3 | 189-05, 189-06, 189-08 | gates de ausência com isca; RESSALVAS com dono no fechamento |
| D-189-3a | 189-05 | o botão "Ampliar 2,5×"/"Reduzir" da lateral É entregue |
| D-189-3b | 189-01 (inventário do gate), 189-06 (inversão) | o gate de ausência do 188-08 é reapontado com veto e declarado |
| PERGUNTA-189-A (a) | 189-05 (tarefa 2), 189-08 | cabeçalho APOIO uma vez na lateral + ordinal; registrado como 4ª derrota do protótipo |
| PERGUNTA-189-B (b) | 189-06 (contexto e teclado), 189-07 (ações), 189-08 | ↑/↓ navegam por baixo; o lightbox guarda oferta/versão/imagens na abertura; toda ação usa esse contexto; fixture A v3 × B v7; mutação da fonte da versão |
| PERGUNTA-189-C | 189-06 | ao fechar, o painel volta à foto de onde se abriu, foco no "⤢ Ampliar" |
| PERGUNTA-189-D (ABERTA) | 189-06 (D1), 189-07 (D2), 189-08 | defaults: painel fica na oferta nova ao fechar; lightbox fecha após ação com oferta diferente — declarados, com alternativa local |
| `18` aprovado | 189-01 | linha própria da 189 em `LIBERADOS_DEPOIS_DA_187`, sem tocar no `21` |

## §5 — A NOTA DE COORDENAÇÃO (§0-§11) × planos

| item da nota | plano(s) |
|---|---|
| §0 a 188 não está pronta → gate de entrada | 189-01 (tarefa 1) |
| §1 execução sequencial onde há `.jsx` | ondas acima; 02 ∥ 03 justificado |
| §2 caminhos fantasma | `test -e` em cada plano; inventário do 189-01 |
| §3 ponteiros que mentem / colunas `cursor`/`white-space`/`text-overflow` | `189-FID01.md` + re-medição no 189-01 |
| §4 o protótipo ganha, exceto 3 vezes; quarto conflito → pergunta | PERGUNTA-189-A/B/C RESPONDIDAS em 19/10 (A: cabeçalho APOIO entra — 4ª derrota do protótipo; B: fiel ao protótipo, com contexto próprio; C: o padrão); PERGUNTA-189-D ABERTA, não bloqueia (`189-REGUA.md` §6) |
| §5 o portal, SSR sem portal, ordem de camadas | 189-03, 189-04, 189-06, 189-07 |
| §6 a tela preta | 189-03, 189-05, 189-06, 189-07 (render adverso + clique/tecla/movimento) |
| §7 gates e mutação | todos (a PI da régua §5 substitui todo `git diff --numstat` "vazio"); consolidação no 189-08 |
| §8 baselines | 189-01 (medir), 189-08 (re-medir) |
| §9 território, `EditorEmUso`, aprovar enfileira | 189-02 (docblock + `Http::fake`), régua §8, 189-07 (nenhuma escrita nova) |
| §10 vocabulário, cor nunca é o único sinal, FID-03/04, strokeWidth | 189-03, 189-05 (nenhum ícone do protótipo em SVG nesta região: o único ícone é o `ImageOff` do IMG-13, sem medida — declarado) |
| §11 roteiro da conta 459 com caminho para fila vazia | 189-08 |

## §6 — Itens SEM plano, de propósito (exclusões, não lacunas)

| item | razão | dono |
|---|---|---|
| "Comparar lado a lado na revisão detalhada" | D-189-3 | Fase 190 |
| "✦ Gerar novamente com IA" (lightbox `:762` e painel `:606`) | D-189-3 | Fase 192 |
| modal "Substituir por foto original" (MOD-06) | Coverage Map: `MOD-06 (1) \| Phase 190` (`ROADMAP.md:5025`) | Fase 190 |
| escrita de `pub_imagem_referencias` | DADO-15: escrita nas Fases 192/194 | Fases 192/194 |
| escrita no Mercado Livre | aprovar enfileira | Fase 193 |
