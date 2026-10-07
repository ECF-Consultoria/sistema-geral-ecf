---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 21
subsystem: portal-cliente / mapeamento-estrutural / produtos
tags: [fidelidade-visual, gap-closure, puppeteer, ui-spec]
requires: ["167-20"]
provides:
  - "Produtos (Visual grande, Lista) e ficha conferidos por captura a 1586x992 contra REF-1/2/3"
  - "Revisão D-25..D-30 no 167-UI-SPEC.md"
affects: [167-17]
key-files:
  modified:
    - resources/js/Pages/Portal/EstruturaProdutoFicha.jsx
    - resources/js/Components/Portal/Estrutura/comum.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoProdutoGrande.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoProdutoLinha.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoVolume.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/FaixaCalculados.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx
    - .planning/phases/167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas/167-UI-SPEC.md
metrics:
  rodadas_de_captura: "r1 inicial + r2 e r3 de ajuste + final"
  completed: 2026-10-06
---

# Fase 167 Plano 21: Passe de fidelidade e gate final — Resumo

Passe de fidelidade com o sistema rodando sobre dados fictícios iguais aos das referências (empresa "Loja Referência", SQLite
isolado): duas rodadas de ajuste só de apresentação aproximaram topo, cartões e ficha das três referências a menos de 10% e
os fluxos lista → ficha → lista foram provados no navegador.

## Commits

- `1ca4d411` fix(167): Produtos e ficha mais fiéis às referências visuais — ajustes de geometria (9 arquivos JSX, só classes)
- `9050fa23` docs(167): revisão D-25..D-30 no contrato de tela

## Rodadas

| Rodada | O que mostrou / mudou |
|---|---|
| r1 (inicial) | Descrição do topo quebrava em 2 linhas (tudo ≈45 px abaixo da REF); pílula "Falta" espremia a categoria no cartão grande; variações da Lista ≈10 px mais altas; select do Eixo cortava o texto ("Cor"); "Adicionar volume" quebrava em 2 linhas; Ref em `font-mono`; blocos de variação ≈30% mais altos |
| r2 (ajuste 1) | Descrição em 1 linha (`max-w-[1100px]`), trilha `mt-6`, cartão grande com pílula que quebra para baixo, Lista com `py-1`, `py-0` nos campos, Ref sem mono, volume e variação compactos |
| r3 (ajuste 2) | Frete do calculado em uma linha, ajuste fino de margens do topo da ficha e do bloco "Variações"; topo bate: título y76, trilha y185, ações y255, seletor y327 (REF: 78/204→185 aqui, 254, 327) |
| final | recaptura do estado commitado: mesmos números, F1–F6 passando, sem erro de console/página |

## Tabela final das diferenças

| Estado | Elemento | Referência | Sistema | Motivo |
|---|---|---|---|---|
| todos | cores, menu lateral | cor da referência | tokens `ecf-*` | D-25 |
| ficha | sino/avatar/"Ver no ML"/⋮ no topo | presentes | ausentes | D-30 |
| Visual grande | seletor acima dos cartões | sem seletor | seletor (cartões ≈58 px abaixo) | D-26 |
| cartões/ficha | foto | foto + lápis | quadro com iniciais, sem lápis | D-29 |
| ficha | asterisco | em Família e Categoria também | só Ref e Nome | D-28 |
| ficha | Valor | com chevron | texto livre | D-20 |
| cartões | bolinha em eixo que não é Cor | colorida | neutra | D-30 |
| Visual grande | pílula "Falta: frete ME1 / medidas · categoria" | só em 2 dos 6 | quebra para a linha de baixo quando a categoria é longa; cartão ≈30–55 px mais alto (352/326 vs 296) | dado real: sem conta ML há pendência de frete ME1; seed realista |
| ficha | frete calculado | "R$ 78,20" | "R$ 68,65 estimativa" | dado do servidor (estimativa x faixa) |
| ficha | bloco de variação | ≈225 | ≈244 | subtítulo "Oferta … · Ver na Lista SKUs" que a REF não tem |
| ficha | dados gerais | 240 | 251 | idem (+5%) |
| ficha | título | ≈44 px | 42 px | dentro de ±10% |
| ficha | altura total (2 variações) | 992 | 1037 | soma das duas linhas acima |
| Lista | cartão com 2 variações | 98 | ≈100 | folga de 2 px |
| todos | "Como funciona" no canto | ausente | presente | decisão da fase |
| todos | valores, logísticas, fretes, ordem dos ambientes | do mockup | os que o servidor calcula | mockup tem combinações impossíveis |

Nenhuma diferença de estrutura (ordem, posição, proporção, hierarquia) ficou sem motivo.

## Fluxos (captura final)

- F1 modo: escolher Lista, recarregar -> continua Lista. OK
- F2 rolagem: scrollY 1500 -> ficha -> "Produtos" -> scrollY 1500, mesma URL. OK
- F3 busca/página: `?q=Puff` -> ficha -> Cancelar -> `q=Puff` mantido; `?pagina=2` -> ficha -> voltar -> `pagina=2`. OK
- F4 salvar: custo trocado, "Salvar produto" -> volta com "Produto salvo.". OK
- F5 guarda: com alteração, "Produtos" abre `confirm` "Há alterações não salvas neste produto. Sair sem salvar?"; recusar fica na ficha, aceitar volta. OK
- F6 produto novo: "Adicionar produto" -> `/portal/estrutura/produtos/novo` -> salvar -> aviso e cartão novo visível (testado na Loja Conferência, 7 produtos).
- Sem rolagem horizontal a 1586, 1280 e 390 px (grande, lista, ficha); 0 erros de console/página.

## Deviations from Plan

**1. [Limite observado, sem alteração] Produto novo em empresa com mais de 100 produtos** — a lista é ordenada por id, então o
produto novo cai na última página; a volta vai para a página 1 e não há cartão para rolar. Em lista curta (uma página) funciona.
Não foi mexido (seria regra/navegação, fora do escopo de fidelidade). Fica como observação para o usuário.

**2. [Rule 3 - ambiente]** `novo-ticket.php` do scratchpad passou a aceitar o id da empresa (acentos no argumento do `execSync`
no Windows). `capturar-referencias.mjs` carrega `puppeteer-core` via `createRequire` do worktree. Nada disso entra no repositório.

## Gate final

- `npm run test:js`: 1048 testes, 1046 ok, 2 falhas = as do baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`).
- `tests/Feature/PortalCliente`: OK (372 tests, 2716 assertions).
- `npm run build` real; as duas páginas (`EstruturaProdutos.jsx`, `EstruturaProdutoFicha.jsx`) no manifest.
- Diff de guarda (`app routes database config tests/Feature`, hook, navegação) desde o 167-20: vazio. Diff `database`/grade compartilhada desde o 167-18: vazio. Nenhum `.xlsx/.sqlite/.png/.jpg` novo além das 3 referências do usuário.
- Servidor `php -S 127.0.0.1:8167` continua no ar; "Loja Referência" (id 3) e "Loja Conferência" (id 1) prontas.

## Capturas (scratchpad `.../produtos-visual/`)

Finais: `final-ref1-visual-grande.png`, `final-ref2-ficha.png`, `final-ref3-lista.png`; extras: `final-ref1-com-faixa.png`,
`final-ficha-inteira.png`, `final-lista-1280.png`, `final-lista-390.png`, `final-ficha-1280.png`, `final-ficha-390.png`;
medidas `medidas-final-*.json`; rodadas anteriores `r1-*`, `r2-*`, `r3-*`.
A faixa amarela de sessão de equipe é escondida (display:none) nas capturas de comparação; `*-com-faixa.png` mostra com ela.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Commits `1ca4d411` e `9050fa23` existem; UI-SPEC contém "Revisão D-25..D-30"; capturas finais existem.
