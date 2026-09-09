# Painel Polos — por que a grade travava (e o que não adianta otimizar)

Descoberto em 09/09/2026, a partir do relato "tudo trava: filtrar, pesquisar, e até o F12
fica travado".

**Leia antes de mexer em `resources/js/Pages/Polos/Painel.jsx`**, em `useAutoFilter`, ou em
qualquer grade editável inline do projeto (o Onboarding tem a mesma forma).

## 1. O gargalo era o DOM, não a query nem o filtro

O reflexo é culpar o backend ou o motor de filtro. Aqui não era nenhum dos dois: o payload
sai pronto do `PolosController@painel` numa consulta só, e `useAutoFilter` varre ~180 linhas
em memória — trabalho irrelevante para um navegador.

O custo estava em **quantos nós a página mantinha vivos ao mesmo tempo**. Medido no Chrome,
lente Geral, admin, escopo padrão M1–M4 (177 empresas):

| | antes | depois |
|---|---:|---:|
| Nós de DOM | 56.080 | 31.677 |
| `<option>` | 28.953 | 4.550 |
| HTML da página | 6,30 MB | 4,37 MB |

Com "Todas as fases" (284 empresas) o depois fica em 50.391 nós / 6.995 `<option>`.

A latência de digitação na busca caiu junto — de 744/842/689/627 ms nas quatro primeiras
teclas para 332/560/441/437 ms. É medida ruidosa (headless, máquina compartilhada); o número
confiável é a contagem de nós.

**28 mil dos 55 mil nós eram `<option>`.** A lente Geral tem ~16 `<select>` por linha
(fase, polo, responsável, status de entrada, chance, acesso, reunião, planilha, listagem,
publicação, decola, central de promoção, ME1, integradora, places, ERP) e cada um
renderizava o catálogo inteiro — ~130 opções por linha, multiplicadas por 177 linhas.

É por isso que **o F12 travava junto**: o DevTools precisa serializar a árvore inteira para
montar o painel Elements. Quando o "Inspecionar" trava, o sintoma está apontando para
tamanho de DOM — não para JavaScript lento.

## 2. `<option>` sob demanda — e por que `flushSync` não é opcional

O conserto é o `useOpcoesSobDemanda()`: o `<select>` nasce só com a opção do valor atual e
recebe o catálogo no primeiro contato (`onMouseDown` / `onFocus` / `onKeyDown` /
`onTouchStart`).

A armadilha: **sem `flushSync` isso quebra de um jeito difícil de ver**. O React aplicaria a
lista num microtask, e o navegador já teria aberto o dropdown nativo com a lista curta — o
primeiro clique em cada select mostraria uma opção só, e o segundo funcionaria. `flushSync`
força o DOM a estar pronto antes do navegador executar a ação padrão do mousedown.

Verificado no navegador: `select.options.length` vai de 2 para 12 no próprio `mousedown`, e
de 1 para 24 no select de responsável.

## 3. Props que furam o `memo()` — o erro que se repete

`LinhaPainel` não tinha `memo()`, então **qualquer** estado do painel redesenhava as 177
linhas × ~32 colunas: uma tecla na busca, uma caixa de seleção, uma célula salva.

Só que pôr `memo()` não basta — quatro props furavam a comparação e precisaram mudar juntas:

- `on={handlers}` — objeto literal, nascia novo a cada render. Agora é criado uma vez e cada
  método despacha para a versão atual por ref (`useLatest`).
- `onToggleSel={toggleLinha}` — `useCallback` com deps `[ancora, idsVisiveis]`. **Como todo
  clique muda `ancora`, a identidade mudava a cada clique** — exatamente na interação que se
  queria baratear. Agora lê as duas por ref, com deps estáveis.
- `idx={idx}` — mudava para quase toda linha a cada filtro. Removida: `toggleLinha` acha o
  índice pelo id na lista visível.
- `editNota={editNota}` — o objeto inteiro ia para todas as linhas, então editar a nota de
  UMA empresa redesenhava todas. Agora vai só `notaEdit={editNota[e.id]}`.
- `adsLimites` — `cockpit?.adsLimites ?? { … }`: o fallback criava objeto novo a cada render
  enquanto o cockpit (assíncrono) não tivesse chegado.

Moral: `memo()` numa linha de grade só paga se **todas** as props forem estáveis. Uma sozinha
que não seja anula o resto, sem erro nenhum aparecer.

## 4. `useDeferredValue` na busca

A busca alimenta `useAutoFilter` por `buscaDiferida`, não por `busca`. O campo continua
recebendo o texto em prioridade alta e a varredura da grade roda em prioridade baixa,
interrompível pela tecla seguinte. Sem isso cada caractere segurava a thread principal até a
grade inteira terminar de redesenhar.

## 5. O que NÃO foi feito, e por quê

**Virtualização de linhas** (renderizar só a janela visível) derrubaria o DOM para ~6 mil
nós, bem abaixo dos 31 mil de hoje. Ficou de fora de propósito: a tabela tem duas colunas
congeladas à esquerda, `thead` sticky, linha de detalhe expansível de altura variável e
rolagem horizontal — e o time usa o Ctrl+F do navegador para achar empresa, que a
virtualização quebra. Se um dia 31 mil nós voltarem a incomodar, esse é o próximo passo, mas
com a troca consciente.

**Paginar no servidor** também ficou de fora: os funis de coluna, os donuts do Centro de
Operações e a exportação dependem de ter a lista inteira no cliente.

## 6. Como medir de novo

Não confie em "parece mais rápido". O que decide é contar nós:

```js
document.getElementsByTagName('*').length
document.getElementsByTagName('option').length
```

Há um par de scripts Puppeteer (medição + teste funcional de seleção, Shift-range, selects e
funis) usados nesta rodada; eles precisam de servidor local e login, por isso não entraram no
repositório.
