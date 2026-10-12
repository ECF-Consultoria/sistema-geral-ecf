# Fase 177 — achados fora do escopo (não corrigidos de propósito)

## 1. `iniciais()` morto em `AnunciarMassa.jsx` (plano 177-08)

- **Arquivo:** `resources/js/Pages/Mlb/AnunciarMassa.jsx`, linha ~39
- **O que é:** `const iniciais = (nome) => …` no escopo do módulo, com o
  comentário "Iniciais da empresa para o 'dot' do chip".
- **Estado:** nunca chamado. Conferido em `git show HEAD:…` **antes** da troca
  do topo — já era código morto, não é resíduo do plano 177-08. (As ocorrências
  de `iniciais` nas linhas 88-110 são uma variável LOCAL homônima, dentro de
  outra função.)
- **Por que não foi removido:** fora do escopo do plano (que proíbe mexer em
  qualquer linha do corpo das 3 páginas — HEAD-15) e não causado por ele.
- **Quando resolver:** na varredura que apagar `BarraDaConta.jsx` e
  `AbasDaConta.jsx`, depois que nenhuma página os montar.

## 2. Furo PRÉ-EXISTENTE já corrigido: `grupos.links` sem `\b`

- **Arquivo:** `tests/js/estrutura-anuncios-historico.test.js` (gate de 173-05)
- **O que era:** `assert.match(fonte, /grupos\.links/)` passava com
  `grupos.linksQualquerCoisa` — asserção praticamente vazia.
- **Medido em:** prova por mutação do plano 177-08.
- **Corrigido** no mesmo plano (`/grupos\.links\b/`), porque o arquivo já estava
  aberto e a correção é de um caractere. Registrado aqui porque **não** é uma
  regressão do 177-08.
