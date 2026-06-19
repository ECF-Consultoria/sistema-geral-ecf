---
phase: quick-260619-dce
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/js/Pages/Polos/components/RosePie.jsx
  - resources/js/Pages/Polos/Index.jsx
autonomous: true
requirements: [QUICK-260619-dce]

must_haves:
  truths:
    - "A seção 'Distribuição do faturamento' em /polos exibe uma pizza estilo rose/nightingale (raio proporcional ao valor) em vez da pizza 3D"
    - "As fatias estão ordenadas por valor (maior → menor), cada polo com sua cor da POLO_PALETTE (multicor, NÃO vermelho)"
    - "Cada fatia tem leader line com label do polo + valor/%, sobre fundo escuro arredondado, com glow/sombra suave"
    - "A pizza de distribuição de STATUS continua usando Pie3D (intacta) e os DonutCards permanecem inalterados"
    - "`npm run build` conclui sem erros"
  artifacts:
    - path: "resources/js/Pages/Polos/components/RosePie.jsx"
      provides: "Componente SVG nightingale rose (ângulo ∝ valor, raio ∝ valor, leader lines, glow), props no espírito de Pie3D"
      exports: ["default"]
      min_lines: 80
    - path: "resources/js/Pages/Polos/Index.jsx"
      provides: "Seção de distribuição de faturamento usando <RosePie>, demais seções intactas"
      contains: "RosePie"
  key_links:
    - from: "resources/js/Pages/Polos/Index.jsx"
      to: "resources/js/Pages/Polos/components/RosePie.jsx"
      via: "import RosePie + uso na seção de faturamento"
      pattern: "import RosePie"
---

<objective>
Substituir o gráfico de "Distribuição do faturamento" da página /polos (hoje uma pizza 3D
via `<Pie3D>`) por uma pizza estilo rose/nightingale desenhada em SVG próprio — inspirada
no demo ECharts "Customized Pie" (roseType:'radius'), mas SEM adicionar ECharts nem qualquer
nova dependência.

Características da nova pizza (copiadas do demo, exceto a cor):
- Ângulo de cada fatia proporcional ao valor (faturamento).
- Raio de cada fatia proporcional ao valor (efeito nightingale rose: fatia maior estica mais).
- Fatias ordenadas por valor (maior → menor) — `distrib.itens` já vem ordenado.
- Fundo escuro arredondado, glow/sombra suave (SVG filter / feGaussianBlur ou drop-shadow).
- Leader lines com label do polo + valor/percentual, em tom esmaecido (padrão do demo).
- Cores: multicor por polo usando a POLO_PALETTE atual (uma cor por polo) — NÃO o vermelho do demo.

Escopo cirúrgico: troca acontece SOMENTE na seção de distribuição do FATURAMENTO.
A pizza de distribuição de STATUS e a grade de DonutCards permanecem intactas, e o
componente `Pie3D` continua importado (ainda usado pela seção de status).

Purpose: Modernizar a visualização de faturamento por polo com um gráfico mais expressivo
(rose), mantendo a identidade dark/ECF e sem inflar o bundle com lib de gráfico.
Output: novo componente `RosePie.jsx` + edição pontual em `Index.jsx`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md

# Componente alvo da substituição e padrão SVG/CSS a seguir
@resources/js/Pages/Polos/Index.jsx
@resources/js/Pages/Polos/components/Pie3D.jsx
@resources/js/lib/utils.js

<interfaces>
<!-- Contratos que o executor já possui — sem necessidade de explorar o codebase. -->

POLO_PALETTE (Index.jsx, linha 10) — cor por polo, amarelo ECF primeiro:
  ['#ffe600', '#38bdf8', '#22c55e', '#a855f7', '#fb923c', '#f43f5e', '#2dd4bf', '#e879f9']

distrib.itens (Index.jsx, ~linha 103) — JÁ ordenado maior→menor por faturamento:
  Array<{ polo: string, cor: string, faturamento: number, share: number }>

Uso ATUAL a ser substituído (Index.jsx, ~linhas 247-252), dentro do card
"Distribuição do faturamento — {mesRefLabel}":
  <Pie3D
      slices={distrib.itens.map((i) => ({ color: i.cor, value: i.faturamento }))}
      size={240}
      depth={30}
      tilt={58}
  />

Padrão de props do Pie3D (NÃO remover este componente — ainda usado pela seção de status):
  slices : Array<{ color: string, value: number, label?: string }>  (value em qualquer escala)
  size   : diâmetro em px

Helpers disponíveis (resources/js/lib/utils.js):
  formatCurrency(value) -> string BRL ('pt-BR')
  cn(...inputs)         -> classes Tailwind compostas (clsx + tailwind-merge)
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Tarefa 1: Criar componente RosePie.jsx (pizza nightingale rose em SVG puro)</name>
  <files>resources/js/Pages/Polos/components/RosePie.jsx</files>
  <action>
    Criar o componente `RosePie` em SVG puro, sem nova dependência, no mesmo espírito de `Pie3D.jsx`
    (import de `cn` e `formatCurrency` de `@/lib/utils`; docblock e comentários em pt-BR; tema dark ECF).

    Props (default export):
      - slices: Array&lt;{ color: string, value: number, label?: string }&gt; (value em qualquer escala)
      - size: diâmetro em px (default 240)
      - className: opcional

    Geometria nightingale rose (replicar o demo ECharts roseType:'radius', exceto a cor):
      - Filtrar fatias com value &gt; 0 e preservar a ordem recebida (Index já entrega maior→menor).
      - total = soma dos values; cada fatia recebe ângulo proporcional: anguloFracao = value/total * 360.
      - Raio por fatia proporcional ao valor: mapear value linearmente entre rMin e rMax
        (ex.: rMin ≈ 0.42*R, rMax ≈ R, onde R = size/2 menos margem para labels/leader lines).
        A MENOR fatia usa rMin, a MAIOR usa rMax; interpolar as demais por (value - min)/(max - min).
        Tratar o caso de todas iguais (denominador 0) usando rMax para todas.
      - Desenhar cada fatia como um setor (path SVG: M centro → L ponto inicial no raio da fatia →
        A arco até ponto final → Z). Acumular o ângulo inicial varrendo as fatias; começar do topo
        (-90°) para ficar visualmente como o demo. Converter graus→radianos para x/y.
      - Cantos arredondados/visual suave: aplicar stroke fino do fundo (rgba(5,5,7,…)) entre fatias
        como filete separador (equivalente ao gap do Pie3D), e/ou pequeno strokeLinejoin="round".

    Fundo escuro arredondado + glow:
      - Envolver o SVG num container com fundo escuro arredondado (ex.: rounded-2xl, bg ecf-card/escuro
        via style ou classes) — opcional porém presente, como o "fundo escuro" do demo.
      - Glow/sombra suave: definir um &lt;filter&gt; SVG com &lt;feGaussianBlur&gt; (ou feDropShadow) aplicado às
        fatias, OU usar CSS filter: drop-shadow(...) no grupo de fatias. Intensidade discreta (shadowBlur
        suave do demo), sem estourar o contraste.

    Leader lines + labels (estilo demo, esmaecido):
      - Para cada fatia, calcular o ângulo médio; traçar uma leader line: segmento curto do raio externo
        da fatia saindo radialmente, depois uma "perna" horizontal até a borda (esquerda/direita conforme
        o lado), terminando com o texto.
      - Texto: usar `label` (nome do polo) quando presente; abaixo/junto exibir valor via formatCurrency
        e o percentual (value/total*100, 1 casa). Cor do texto/linha esmaecida (ex.: rgba branco ~0.5–0.7),
        coerente com o tom "label apagada" do demo. Alinhar âncora do texto: start à direita, end à esquerda.
      - Garantir viewBox/size suficiente para as leader lines não cortarem (incluir padding lateral no
        cálculo de R e no viewBox). Usar &lt;text&gt; SVG com font-size pequeno e tabular para os números.

    NÃO inserir blocos de código fora deste componente. NÃO usar recharts nem ECharts. Cores vêm das
    `slices[].color` (POLO_PALETTE) — nunca hardcodar o vermelho do demo.
  </action>
  <verify>
    <automated>Test-Path resources/js/Pages/Polos/components/RosePie.jsx; Select-String -Path resources/js/Pages/Polos/components/RosePie.jsx -Pattern 'export default function RosePie' -Quiet</automated>
  </verify>
  <done>Arquivo RosePie.jsx existe, exporta default `RosePie`, renderiza setores SVG com raio proporcional ao valor (nightingale), fundo escuro/glow e leader lines com label+valor+%; usa slices[].color (multicor), sem nova dependência.</done>
</task>

<task type="auto">
  <name>Tarefa 2: Trocar Pie3D por RosePie na seção de faturamento do Index.jsx</name>
  <files>resources/js/Pages/Polos/Index.jsx</files>
  <action>
    Editar APENAS a seção "Distribuição do faturamento" (card ~linhas 238-277):

    1. Adicionar o import do novo componente junto aos imports de componentes (após a linha
       `import Pie3D from './components/Pie3D';`):
         import RosePie from './components/RosePie';
       MANTER o import de `Pie3D` (ainda usado pela seção de status).

    2. Substituir o bloco `<Pie3D ... />` da seção de FATURAMENTO (~linhas 247-252) por `<RosePie>`,
       passando agora também o label do polo nas slices para a leader line:
         <RosePie
             slices={distrib.itens.map((i) => ({ color: i.cor, value: i.faturamento, label: i.polo }))}
             size={240}
         />
       (ajustar props extras conforme a assinatura final do RosePie da Tarefa 1; manter size={240}).

    NÃO alterar:
      - a pizza de distribuição de STATUS (~linhas 282-303), que continua usando `<Pie3D>`;
      - a legenda/ranking ao lado da pizza de faturamento, os Kpi e os DonutCards;
      - qualquer lógica de `distrib`, filtros, sync ou drawer.

    Comentário pontual em pt-BR pode ser ajustado (ex.: trocar "Pizza 3D de distribuição" por
    "Pizza rose de distribuição" no comentário da seção de faturamento), opcional.
  </action>
  <verify>
    <automated>Select-String -Path resources/js/Pages/Polos/Index.jsx -Pattern "import RosePie from './components/RosePie'" -Quiet; Select-String -Path resources/js/Pages/Polos/Index.jsx -Pattern '<RosePie' -Quiet; (Select-String -Path resources/js/Pages/Polos/Index.jsx -Pattern '<Pie3D' -AllMatches | Measure-Object).Count -ge 1</automated>
  </verify>
  <done>Index.jsx importa RosePie e o usa na seção de faturamento com slices contendo label do polo; a seção de status ainda usa Pie3D; import de Pie3D preservado; demais seções inalteradas.</done>
</task>

<task type="auto">
  <name>Tarefa 3: Build de validação (npm run build)</name>
  <files>(nenhum arquivo modificado — apenas validação)</files>
  <action>
    Rodar o build de produção do Vite para garantir que o novo componente e a edição do Index
    compilam sem erros (convenção obrigatória do projeto: `npm run build` ao final de toda edição de código).
    Se houver erro de compilação/JSX, corrigir em RosePie.jsx ou Index.jsx e rebuildar até passar.
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>`npm run build` conclui com sucesso (sem erros de compilação), gerando os assets atualizados em public/build.</done>
</task>

</tasks>

<verification>
- RosePie.jsx existe, é SVG puro (sem recharts/ECharts), com raio ∝ valor, glow, fundo escuro e leader lines multicor.
- Index.jsx usa <RosePie> na seção de faturamento (com label do polo nas slices) e mantém <Pie3D> na seção de status.
- Nenhuma nova dependência em package.json; nenhuma mudança de backend/controller.
- `npm run build` passa.
</verification>

<success_criteria>
- A seção "Distribuição do faturamento" de /polos renderiza a pizza rose/nightingale multicor com leader lines.
- Pizza de status e DonutCards permanecem intactos; Pie3D continua disponível.
- Build de produção conclui sem erros.
</success_criteria>

<output>
Criar `.planning/quick/260619-dce-grafico-rose-faturamento-polos/260619-dce-SUMMARY.md` ao concluir.
</output>
