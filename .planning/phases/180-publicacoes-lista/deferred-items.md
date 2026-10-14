# Itens fora de escopo descobertos na Fase 180 (não consertados de propósito)

## `duration-[Nms]` NÃO COMPILA NESTE PROJETO — 7 usos mortos fora desta fase

**Descoberto em 13/10/2026, plano 180-09**, ao conferir o CSS construído.

**O fato, medido:** o CSS do build (`public/build/assets/app-*.css`) não tem **nenhuma** regra
`.duration-\[...\]` — zero ocorrências da string `duration-\[`. A escala NOMEADA existe
(`duration-75/150/200/300/500/700`), o valor arbitrário não.

**A causa, reproduzida:** o plugin `tailwindcss-animate` (`tailwind.config.js`, `plugins: [forms,
animate]`) registra `duration`, `delay` e `ease` como utilitários de **animação**, e aí o Tailwind
recusa o valor arbitrário com o aviso:

```
warn - The class `duration-[180ms]` is ambiguous and matches multiple utilities.
```

Reproduzido rodando `postcss([tailwind({content:[{raw:'…duration-[180ms]…'}], plugins:[animate]})])`:
**com** o plugin sai o aviso e a regra não nasce.

**Por que é grave:** a classe fica no HTML, todo teste de render passa, e a transição simplesmente
não acontece na tela. É a mesma família do `tailwind.config.js` que varre `*.jsx` e não `*.js`.

**Os usos mortos, FORA do escopo desta fase (não tocados):**

| arquivo | linha | classe morta |
|---|---|---|
| `resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx` | 40 | `duration-[2000ms]` |
| `resources/js/Pages/Performance/Index.jsx` | 1249 | `duration-[900ms]` |
| `resources/js/Pages/Performance/Index.jsx` | 1334 | `duration-[900ms]` |
| `resources/js/Pages/Performance/Index.jsx` | 1358 | `duration-[900ms]` |
| `resources/js/Pages/Performance/Index.jsx` | 1485 | `duration-[900ms]` |
| `resources/js/Pages/Performance/Index.jsx` | 1532 | `duration-[900ms]` |

⚠️ **Conferir também `delay-[...]` e `ease-[...]`**, que caem na mesma ambiguidade — não varri o
repositório inteiro por eles.

**Os dois caminhos de conserto** (nenhum é desta fase):

1. **Token:** `theme.extend.transitionDuration: { 'pub-chevron': '180ms', … }` em
   `tailwind.config.js` — território do plano **180-01**, e o gate
   `publicador-tokens-do-publicador.test.js` importa o config de verdade.
2. **`style`:** `style={{ transitionDuration: '180ms' }}` — é o que o plano 180-09 fez no chevron da
   linha de grupo, com gate que prova o `style` **e** que a classe morta não volta.
