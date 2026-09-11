---
quick_id: 260911-exe
slug: nome-da-empresa-abre-a-ficha
date: 2026-09-11
type: quick
status: pending
---

# No fechamento, clicar no nome da empresa abre a ficha dela

## O pedido do usuário (2026-09-11)

> "Na tela de fechamento, na listagem de empresas, seria interessante que ao clicar sobre o nome da
> empresa abrisse a página de visualização detalhada da empresa."

Destino: `route('companies.show', id)` — `routes/web.php` linha 864,
`CompanyController::show()` → `resources/js/Pages/Companies/Show.jsx` (a ficha reescrita na Fase 108).

---

## ⚠️ O obstáculo real: a linha inteira já é um `<button>`

Em `resources/js/Pages/Admin/Financeiro.jsx` (~linha 790) a linha da listagem é:

```jsx
<button type="button" onClick={onToggle} className={cn('w-full text-left …')}>
    …
    <p className="text-white text-[17px] font-semibold …">{empresa.name}</p>
```

**Âncora dentro de botão é HTML inválido** — não dá para só embrulhar `{empresa.name}` num `<Link>`
e seguir em frente. O navegador reaninha a árvore e o resultado é imprevisível.

O caminho: o elemento externo deixa de ser `<button>` e vira `<div>` que mantém o clique de
expandir, e o nome vira um `<Link>` de verdade que interrompe a propagação.

⚠️ **A acessibilidade não pode ser perdida na troca.** O `<button>` de hoje é focável e responde a
Enter/Espaço de graça. Ao virar `<div>`, isso tem de ser reposto **à mão**:

```jsx
role="button"
tabIndex={0}
aria-expanded={aberto}
onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); } }}
```

E o `<Link>` do nome precisa de `onClick={e => e.stopPropagation()}` — senão clicar no nome navega
**e** expande a linha ao mesmo tempo.

⚠️ Conferir se o `cn('w-full text-left …')` continua fazendo sentido num `div` — `text-left` existe
porque `button` centraliza por padrão; `div` não. Não saia removendo classe sem olhar o resultado.

---

## O que fazer

### T1 — o nome vira link na linha da listagem

`Financeiro.jsx` ~linha 802. `Link` **já está importado** (linha 2, de `@inertiajs/react`) — não
reimportar.

Deixar visível que é clicável, no vocabulário da tela (tokens `ecf-*`, tema escuro): `hover:underline`
e/ou `hover:text-ecf-yellow`. Nada de cor nova fora da paleta.

### T2 — as empresas do grupo também

Na "Composição do grupo" (~linha 1109) cada empresa é renderizada em `<div>`, **sem** o problema do
botão — ali é só embrulhar o nome no `<Link>`. São empresas de verdade da listagem; deixá-las de
fora criaria a inconsistência de nome clicável num lugar e não no outro.

⚠️ A linha de índice 0 mostra `${e.name} (este)`. **Só o nome vira link**, não o "(este)".

---

## Travas

⚠️ **`route()` do Ziggy estoura em runtime se a rota não existir e derruba a página inteira** — já
aconteceu neste projeto. `companies.show` existe hoje (linha 864 de `routes/web.php`); confirme
antes de usar.

⚠️ **Não usar `target="_blank"`.** `Link` do Inertia renderiza `<a href>` de verdade, então
ctrl+clique / clique do meio já abrem em aba nova por conta do navegador, e o clique simples mantém
o botão Voltar funcionando. Forçar aba nova tira essa escolha de quem usa.

⚠️ **Escala do Tailwind:** `px-4.5`, `gap-4.5`, `py-5.5` **não existem** — o build passa e nenhum
CSS é gerado. Ao conferir o CSS compilado, use **arquivo `.js` rodado com `node caminho.js`**, nunca
`node -e` inline: já houve falso negativo por escape de barras nesta linha de trabalho.

⚠️ **Nada de backend.** Esta tarefa não encosta em `AdminController`, rollup, resolver, snapshot ou
qualquer coisa de cobrança. Só `Financeiro.jsx`.

⚠️ **Árvore compartilhada, outra sessão ativa:** nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. Antes de commitar, `git status --porcelain resources/` (sem `--untracked-files=no`) e
conferir os `??`. `public/images/*`, `design_handoff_fechamento/` e `scratchpad/` **não são seus**.

⚠️ **Não use `gsd-sdk query state.advance-plan`** — a última execução avançou o contador de outra
fase.

⛔ Sem deploy, sem `.env`.

`npm run build` ao final (mexe em `.jsx`). Comentários e commits em **pt-BR**.

**Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911"`
— a suíte não pode regredir. (O número de partida sai do quick `260911-eph`, que está em execução
neste momento; leia o valor no `SUMMARY.md` dele em vez de presumir.)
