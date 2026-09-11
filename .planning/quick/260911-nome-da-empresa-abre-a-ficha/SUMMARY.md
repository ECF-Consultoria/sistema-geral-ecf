---
quick_id: 260911-exe
slug: nome-da-empresa-abre-a-ficha
date: 2026-09-11
type: quick
status: complete
commits:
  - 115a8145
files_changed:
  - resources/js/Pages/Admin/Financeiro.jsx
---

# No fechamento, clicar no nome da empresa abre a ficha dela

**Uma linha:** na tela de fechamento o nome da empresa virou link para `companies.show`,
tanto na linha da listagem quanto na "Composicao do grupo" — e para isso a linha deixou de
ser `<button>` e virou `<div role="button">` com foco, Enter/Espaco e `aria-expanded`
repostos a mao.

Arquivo unico: `resources/js/Pages/Admin/Financeiro.jsx`. **Nenhum arquivo de backend foi
aberto ou alterado** (nem `AdminController`, rollup, resolver, snapshot ou migration).

---

## O obstaculo, resolvido como o plano previa

A linha da listagem (`FechamentoRow`) era um `<button type="button" onClick={onToggle}>`
inteiro. Ancora dentro de botao e HTML invalido, entao nao dava para so embrulhar
`{empresa.name}` num `<Link>`.

O externo virou `<div>` e ganhou, explicitamente, tudo que o `<button>` dava de graca:

| O que o `<button>` dava | Como foi reposto |
|---|---|
| Focavel pelo teclado | `tabIndex={0}` |
| Anunciado como botao | `role="button"` |
| Enter / Espaco acionam | `onKeyDown` com `e.preventDefault()` + `onToggle()` |
| Estado aberto/fechado para leitor de tela | `aria-expanded={expandida}` (nao existia antes — ganho) |
| Cursor de mao | `cursor-pointer` (ver achado abaixo) |
| Foco visivel | `focus-visible:ring-2 ring-ecf-yellow/60 ring-inset` (nao existia antes — ganho) |

O `<Link>` do nome tem `onClick={e => e.stopPropagation()}` para o clique navegar **sem**
expandir a linha junto.

### Decisao tomada sozinha: a guarda no `onKeyDown`

O `stopPropagation` do `onClick` resolve o mouse, mas **nao resolvia o teclado**. Com foco no
`<Link>`, o Enter dispara um `keydown` que borbulha ate o `<div>` (o `onClick` sintetico do
link vem depois e ja chega barrado). Resultado seria: navega **e** expande a linha.

Guarda adicionada na primeira linha do handler:

```jsx
if (e.target !== e.currentTarget) return;
```

A linha so responde ao teclado quando o foco esta nela propria. Escolhi essa forma em vez de
`onKeyDown` com `stopPropagation` no `<Link>` porque ela protege qualquer elemento interativo
que venha a ser adicionado dentro da linha no futuro, nao so o link de agora.

### Decisao tomada sozinha: `text-left` e `w-full` ficaram

O plano pedia para conferir se continuavam fazendo sentido num `div`. Ficaram os dois. Sao
inertes num `div` (que ja e `block` e alinhado a esquerda), mas remove-los e mexer em
alinhamento de uma grade de 5 colunas sem necessidade nenhuma. Zero ganho, risco nao nulo.

---

## Achado: `cursor-pointer` era mesmo necessario

Tailwind **v4** tem no preflight a regra `button, [role="button"] { cursor: pointer }`. O
projeto esta no **v3.2** (`tailwindcss ^3.2.1`), que **nao tem** essa regra. Confirmado por
leitura do CSS compilado: o grep pelo seletor `[role="button"]` em
`public/build/assets/app-*.css` nao retorna nada.

Sem o `cursor-pointer` explicito, a linha passaria a mostrar cursor de texto onde antes
mostrava mao. Uma regressao silenciosa de sensacao de uso, que so apareceria no navegador.

---

## T2 — Composicao do grupo

Ali cada empresa ja era `<div>`, sem o problema do botao: bastou o `<Link>`.

A linha de indice 0 mostrava o nome e o sufixo "(este)" como um unico template string. Foi
quebrada para que **so o nome** entre no link — o sufixo "(este)" e a seta de hierarquia das
filhas ficam fora, porque sao ornamento e nao fazem parte do nome da empresa.

---

## Travas do plano, cumpridas

- **`companies.show` conferida antes de usar.** Nao bastou olhar a linha 864 de `routes/web.php`:
  rodei `artisan route:list --name=companies.show` e recebi `GET|HEAD companies/{company}` ->
  `CompanyController@show`, 1 rota. E confirmei que nao existe `config/ziggy.php` com `only`/
  `except` que pudesse podar a rota do bundle do Ziggy. Sem isso, o `route()` estouraria em
  runtime e derrubaria a pagina.
- **`empresa.id` e id de empresa de verdade** — confirmado sem abrir backend, pelo uso que ja
  existia no proprio arquivo: `route('admin.financeiro.relatorio', { company: empresa.id })`
  (linha ~1171) e a URL `/empresas/{empresa.id}/contratos-servico` (linha ~1812).
- **Sem `target="_blank"`.** `Link` do Inertia renderiza `<a href>` real; ctrl+clique e clique do
  meio continuam abrindo em aba nova por conta do navegador, e o clique simples preserva o Voltar.
- **Escala do Tailwind conferida no CSS compilado**, nao presumida. E a armadilha do plano me
  pegou: o script `.js` com `String.includes` deu **falso negativo** em todos os seletores com
  barra invertida (as variantes `hover:` e `focus-visible:`) — e o mesmo problema de escape que o
  plano descreve, e ele **nao** e exclusivo do `node -e`. Reconferi com `grep -F` sobre o seletor
  completo e as 9 classes usadas existem todas no CSS: `hover:text-ecf-yellow`,
  `focus-visible:ring-2`, `focus-visible:ring-inset`, `focus-visible:ring-ecf-yellow/60`,
  `focus:outline-none`, `hover:underline`, `underline-offset-2`, `cursor-pointer`, `rounded-sm`.
  Nada fora da paleta `ecf-*`.
- **`npm run build` verde** (42,30s). `companies.show` aparece 2x no `Financeiro-DLiCCh8j.js`
  (uma por tarefa). `public/build` e ignorado pelo git (`.gitignore:41`), nao entrou no commit.
- **Arvore compartilhada respeitada.** Commit por caminho
  (`git commit -- resources/js/Pages/Admin/Financeiro.jsx`), nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. O `git status --porcelain` mostrava `128-REVIEW.md` e
  `131-SECURITY.md` como nao rastreados, de outra sessao — deixados intactos.
  `public/images/*`, `design_handoff_fechamento/` e `scratchpad/` nao foram tocados.
- **`gsd-sdk query state.advance-plan` nao foi executado.**
- Sem deploy, sem `.env`.

---

## O que encontrei e decidi NAO fazer

1. **Nao mexi no `<p>` com `truncate` que envolve o nome.** O `truncate` continua no `<p>` e o
   `<Link>` e inline dentro dele — o corte com reticencias segue funcionando. Mover o `truncate`
   para o link mudaria o comportamento de largura da celula sem pedido.
2. **Nao transformei a linha inteira em link.** Seria o desenho mais simples, mas mataria o
   expandir/recolher, que e a funcao principal da linha.
3. **Nao repliquei o link nos outros lugares onde o nome da empresa aparece** (relatorio PDF,
   modal de contrato, banner de grupo divergente). O pedido e o plano sao sobre a listagem e a
   Composicao do grupo. Ficou de fora de proposito, nao por esquecimento.
4. **Nao escrevi teste automatizado.** O gate e de PHPUnit e esta mudanca e 100% de renderizacao
   JSX; o projeto nao tem infraestrutura de teste de componente React (o `tests/js/` do quick
   260910-j1u usa `node --test` e so cobre funcoes puras, nao JSX). Criar essa infra e decisao
   maior que uma quick task de UI e nao foi pedida.
5. **Nao revisitei o aninhamento interativo em si.** `<div role="button">` contendo `<a>` continua
   sendo aninhamento de elementos interativos — tecnicamente nao ideal para leitor de tela, mas e
   o desenho que o plano prescreve e o unico que entrega as duas acoes (expandir + navegar) na
   mesma linha sem HTML invalido. A alternativa real seria tirar o clique da linha inteira e por
   um botao-chevron dedicado, que e redesenho, nao esta tarefa.

---

## Gate

Comando exato do pedido, exit code capturado **antes** de qualquer pipe (saida redirecionada para
arquivo, `echo "EXIT_CODE=$?"` na linha seguinte):

```
C:\xampp\php\php.exe artisan test --filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911"
```

```
EXIT_CODE=0
Tests:    652 passed (2966 assertions)
Duration: 303.64s
```

Partida: **652 testes / 2966 assercoes / 0 falhas**. Chegada: **652 / 2966 / 0**.
Mesmo numero — nenhum teste novo (a mudanca e so de JSX) e **zero regressao**.

---

## Commits

| Hash | Assunto |
|---|---|
| `115a8145` | `feat(260911-exe): nome da empresa no fechamento abre a ficha dela` |

O commit nao apagou nenhum arquivo rastreado (`git diff --diff-filter=D HEAD~1 HEAD` vazio).

## Self-Check: PASSED

- `resources/js/Pages/Admin/Financeiro.jsx` — existe, modificado (+45 -6)
- `115a8145` — existe em `git log`
- `.planning/quick/260911-nome-da-empresa-abre-a-ficha/SUMMARY.md` — este arquivo
