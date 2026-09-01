# Modo TV do Painel de Polos — o que a parede ensina e o código não conta

Leitura obrigatória antes de mexer em `resources/js/Pages/Polos/components/ModoTV.jsx`
ou nas props de parede do `FatVsMetaChart` / `StatusDonut`.

## 1. A TV roda a 960×600 CSS px. Nenhuma decisão de layout pode sair de breakpoint.

O aparelho aplica zoom próprio, então o viewport CSS que chega na parede é **960×600** —
não 1920×1080. `tailwind.config.js` não customiza `screens`, logo `lg:` (1024px) e `xl:`
(1280px) **nunca disparam lá**. Toda regra com esse prefixo neste arquivo vale só para o
desktop de quem desenvolve.

Já custou três rodadas de conserto com três disfarces diferentes: lista de polos que
mostrava 2 de 5, e o donut de status saindo com 110px de diâmetro porque a grade
`xl:grid-cols-[...]` empilhava e o excedente ia para o `overflow-hidden`.

**Régua:** colunas e fileiras vão em `style={{ gridTemplateColumns/Rows }}`, resolvidas
por contagem de itens. Nunca por largura de viewport.

Corolário útil: **`vw` é a unidade certa da parede**, e não `px`. O zoom muda o viewport
CSS mas não muda o tamanho FÍSICO da tela, então `2.9vw` ocupa o mesmo tanto de parede a
960 ou a 1920. Por isso a escala `FT` do arquivo é toda em `clamp(min, Nvw, max)`, e os
comentários citam o equivalente a 1920px ("56px · 5,1 m").

## 2. Medir a caixa serve para DIMENSIONAR canvas. Nunca para decidir o que aparece.

Duas encarnações já esconderam polo medindo a caixa com `ResizeObserver` e cortando a
lista (`useCabemNaGrade`, `useGradeAdaptativa`). O Modo TV entra em **fullscreen**: no
primeiro layout a altura chega pequena, a conta cai para 2 fileiras e **congela ali**.

O que é legítimo medir: altura de `<canvas>` (ECharts não desenha com altura em `%` que
chega 0). É o que `useAlturaMedida()` faz para o donut e para o gráfico de barras.

O que nunca se decide por medição: **quantos itens são renderizados**. A lista faz `map()`
sobre todos; quem se adapta é a escala tipográfica, escolhida por CONTAGEM
(`escalaPorContagem`) — determinística, igual em qualquer tela.

**Sinal de alerta:** se um layout esconde dado dependendo do tamanho da tela, o problema é
o mecanismo, não o parâmetro.

## 3. ECharts OMITE rótulo de categoria que se sobrepõe. Isso é corte de dado calado.

O default de `yAxis.axisLabel` é `interval: 'auto'`: quando os nomes não cabem, o ECharts
simplesmente **não desenha alguns**. Na parede o sintoma é perverso — a barra do polo
continua lá, do tamanho certo, só que **sem nome**. Ninguém percebe que faltou.

No modo parede o `FatVsMetaChart` força `interval: 0` (desenha todos) e quem cede é a
FONTE: o call-site passa um teto pela altura da fileira
(`alturaGrafico / nPolos * 0,42`, piso de 11px). Nome apertado é melhor que polo anônimo.

Mesma família do problema do item 2: a biblioteca "resolve" sozinha escondendo informação.

## 4. Na parede, número comprido não pede fonte menor — pede degrau de fonte por comprimento.

`R$ 4.807.978,51` tem 15 caracteres. Na fonte do herói (5vw) ele sai da caixa e o card
quebra em duas linhas — e número quebrado lê pior que número um degrau menor. O
`CardTV` escolhe a fonte pelo `String(valor).length` (`FONTE_NUMERO_CARD`), com todos os
degraus acima do piso de 28px-equivalentes da régua; o último degrau (48px · 4,4 m) segura
valores de 8 dígitos.

O mesmo vale para o rótulo: `truncate` + `leading-none` **come 1–4px do descender** (o "g"
de "Em progresso" perde o rabo). Onde há `truncate`, usar `leading-[1.2]`.

## 5. Sandbox de medição com puppeteer — a única coisa que pega esses defeitos.

`.tvsandbox/` (fora do git, é ferramenta) renderiza o `ModoTV` isolado com dados fictícios
e mede no browser real:

```
npx vite build --config .tvsandbox/vite.config.js
node .tvsandbox/medir-fat.cjs        # tela de faturamento, 5 viewports, com screenshot
node .tvsandbox/teste-cobertura.cjs  # clica o toggle e confere o 2º modo do gráfico
```

Três pegadinhas do próprio sandbox, cada uma já custou uma rodada:

- **Módulo ES em `file://` exige `--allow-file-access-from-files`.** Sem a flag a página
  mede "vazia" sem erro nenhum e você conclui que o layout quebrou.
- **Dado fictício generoso esconde o caso real.** Testar com 18 polos não achou nada; a
  produção tem 5, e é com 5 que o vão fica gigante. O cenário `real` do `main.jsx` copia os
  números do print do usuário — mantenha assim.
- **Contar item no DOM não prova que aparece.** `querySelectorAll().length` dizia 5/5
  enquanto a parede mostrava 2. O que vale é comparar o rect do filho com o da caixa, e o
  `scrollHeight > clientHeight` de quem tem `overflow:hidden` (é o campo `clipados` do
  relatório — foi ele que achou os 6px de corte em "EMPRESAS ATIVAS" a 960px).

Sempre medir **960×600 e 1024×600** junto com 1920: os defeitos aparecem só lá embaixo.

## 6. Antes de culpar cache da TV, confira qual bundle os clientes baixaram.

```
grep -ho 'ModoTV-[A-Za-z0-9_-]*\.js' /var/log/nginx/ecf-admin-access.log | sort | uniq -c
```

O Vite separa `ModoTV` em chunk próprio (puxa echarts via `StatusDonut` e `FatVsMetaChart`):
**grep no bundle do Painel dá zero e isso NÃO significa que faltou deploy** — conferir
`imports` no manifest. Foi assim que se descobriu que outra sessão tinha deployado por cima.

## 7. Feedback desta tela: peça a FOTO antes de caçar no git.

"Não mostra a porcentagem, mostra apenas os nomes" queria dizer que **sumiu a BARRA**, não
o texto — a string `${p.pc}%` estava intacta havia semanas. Três hipóteses erradas antes da
foto. Nesta tela, "sumiu o número X" quase nunca significa que X saiu do DOM.

## 8. Dev local não roda o `composer install` de `origin/main` sem flag.

O lockfile de `origin/main` puxa `maennchen/zipstream-php 3.2.2`, que exige **PHP ≥ 8.3**;
o XAMPP da máquina de dev está em 8.2.12 (a VPS já está em 8.3+):

```
php composer.phar install --ignore-platform-req=php-64bit
```

Para o `npm`, o `main` local costuma estar centenas de commits atrás e o `package.json` de
lá não tem `@dnd-kit/*` — apontar o `node_modules` do repo principal para um worktree de
`origin/main` quebra o `vite build` em `Pages/Ppa/Kanban.jsx`. Em worktree de deploy, rodar
`npm ci` no próprio worktree (ou fazer junction para um worktree cujo `package-lock.json`
seja idêntico ao de `origin/main` — conferir com `git diff <ref> origin/main -- package-lock.json`).

## 9. Trabalho relacionado que NÃO está em `main`

A branch `fix/polos-modo-tv-barra-polo-260827` (worktree `c:/xampp/htdocs/ecf_admin_tv`)
tem uma análise da barra da `LinhaPolo` sendo comida por `auto-rows-fr` + `overflow-hidden`
— `minmax(0,1fr)` deixa a fileira ficar menor que o conteúdo, e `overflow-hidden` no filho
**zera a contribuição de `min-content`**, então `minmax(min-content,1fr)` só funciona se o
`overflow-hidden` sair da raiz da linha. Aquela branch ficou 26 commits atrás e a tela foi
reescrita em `main` por outra sessão; o diagnóstico segue válido, o patch não.
