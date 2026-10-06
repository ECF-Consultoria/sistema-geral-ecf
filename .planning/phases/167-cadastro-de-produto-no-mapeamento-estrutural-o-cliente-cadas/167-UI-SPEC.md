---
phase: 167
slug: cadastro-de-produto-no-mapeamento-estrutural
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-05
revised: 2026-10-06 (D-23; D-25..D-30)
---

# Fase 167 — Contrato de Design da UI

> Contrato visual e de interação do submódulo **Produtos** (Portal do Cliente → Mapeamento Estrutural).
> Gerado por gsd-ui-researcher, a ser verificado por gsd-ui-checker. Escrito em pt-BR.
> Fontes: 167-CONTEXT.md (D-01..D-22, travadas), 167-RESEARCH.md (PR167-01..14), telas existentes `EstruturaLista.jsx`,
> `comum.jsx`, `Janela.jsx`, `PreviaColagem.jsx`, `SpreadsheetGrid.jsx`, e as preferências do usuário nos Publicadores (02..04/10).

**Princípio do contrato:** esta tela é a forma RÁPIDA de cadastrar ~70 produtos. Velocidade de digitação vence enfeite.
A tabela fica calma: **sem contador de progresso, sem placar, sem checklist de completude, sem rótulo em CAIXA ALTA,
nada vermelho antes de tentar salvar.** Pendência por linha é texto discreto, não alarme.

## Revisão D-25..D-30 (06/10/2026) — referências visuais 1:1

Motivo: o usuário entregou as três referências (`167-REF-1-visual-grande.jpg`, `167-REF-2-ficha-do-produto.jpg`,
`167-REF-3-lista.jpg`, condensadas em `167-REFERENCIA-VISUAL.md`) e pediu o layout praticamente 1:1, com as cores do
sistema. Esta seção **vale sobre a Revisão D-23 e sobre as seções abaixo onde houver conflito.** Foi conferida por captura a
1586×992 contra as referências (167-21, 3 rodadas).

### Passa a valer

- **Topo amplo:** rótulo "Mapeamento Estrutural", título grande, descrição em uma linha, trilha em círculos numerados
  ligados por linha, "Como funciona" no canto.
- **Linha de ações:** Adicionar produto · Famílias e ambientes · Importar planilha · Baixar modelo · Sugerir categorias
  ("Sugerir categorias" sempre visível, desabilitado sem pendência), com a busca à direita.
- **Seletor [Visual grande][Lista]** guardado no navegador, com "Consultar fretes no Mercado Livre" à direita dele (só com
  conta conectada).
- **Dois desenhos de cartão:** foto = quadro com iniciais; "Família · Ambientes"; categoria "raiz › folha" (caminho inteiro
  na dica); pílula "Falta: …" neutra com ⓘ e o detalhe por variação; ⋮ com "Abrir a ficha" e "Ver {SKU} na Lista SKUs";
  por variação: Ref, valor, selo de logística e frete empilhado; medidas, peso cubado e custo na dica da variação.
- **Sem bolinha de cor** (D-31, 06/10): o palpite de cor pelo nome errava e poluía o cartão; a variação não tem cor.
- **Ficha em página** com URL própria (`/portal/estrutura/produtos/{id}` e `/novo`): breadcrumb, bloco de dados gerais,
  blocos de variação, volumes em cartões 2 por linha, faixa de calculados só leitura ("Os calculados aparecem ao salvar." /
  "Recalcula ao salvar."), "+ Nova variação" tracejado, rodapé Cancelar / Salvar produto (único amarelo), asterisco só em
  Ref e Nome, Valor texto livre, Eixo select nativo.
- **Saída da ficha:** confirmação "Há alterações não salvas neste produto. Sair sem salvar?"; volta à lista preservando
  busca, página, modo e rolagem (produto novo: rola até ele, quando está na página da lista) e o aviso "Produto salvo.".
- **Ref** sem `font-mono` nos cartões e na ficha, como nas referências.
- **Medidas de referência (1586 px):**

| Estado | Elemento | Medida |
|---|---|---|
| Lista | título / trilha / ações / seletor | y 78 / 185 / 254 / 327; ações h48, busca 333 px; seletor h42 |
| Visual grande | cartões | 3 colunas de ≈469; foto 106×108; linha de variação ≈67 |
| Lista | cartão | colunas ≈405 / 440 / 534 / 60; foto 78; ≈82 com 1 variação, ≈98 com 2 |
| Ficha | bloco principal | foto 266×198; ≈240 de altura |
| Ficha | variação | volumes 2 por linha; faixa de 5 calculados; Cancelar 151 / Salvar 284, h36 |

### Deixam de valer

- Painel lateral / folha de baixo da ficha (`Sheet`, `lado`) e a "Proteção do que foi digitado" por clique fora/Esc.
- A casca `max-w-6xl` para Produtos.
- O aviso dispensável da lista (virou dica do "Importar planilha").
- A linha "Falta: …" por variação e o "sem pílula, sem ícone" da Falta (agora pílula neutra com detalhe, no produto).
- Medidas, peso cubado e custo visíveis no cartão (agora na dica).
- O texto "Baixar modelo (.xlsx)" (agora "Baixar modelo", com a dica).
- O grid `xl:grid-cols-3` (agora 3 colunas a partir de 1440 px, mesma altura por linha).

### Diferenças aceitas em relação às referências

| Diferença | Motivo |
|---|---|
| Cores e identidade (tokens `ecf-*`), menu lateral do Portal | D-25 |
| Sem sino, avatar, "Ver no Mercado Livre" nem ⋮ no topo da ficha | D-30 |
| Seletor também no Visual grande: cartões ≈58 px abaixo da REF-1 | D-26 |
| Foto = quadro com iniciais, sem lápis | D-29 |
| Asterisco só em Ref e Nome | D-28 |
| Valor sem chevron (texto livre) | D-20 |
| Sem a bolinha de cor das referências | D-31 (pedido do usuário depois da conferência) |
| Pílula "Falta" quebra para a linha de baixo no Visual grande quando a categoria é longa (cartão fica ≈30–55 px mais alto que na REF-1) | dado real: sem conta do Mercado Livre há "Falta: frete ME1"; só a REF-1 mostra 2 pendências em 6 |
| Calculados com "estimativa" ao lado do valor; "cobrado" no peso cubado | dado calculado pelo servidor (estimativa x faixa de referência) |
| Bloco de variação ≈244 (REF-2: 225) e dados gerais ≈251 (REF-2: 240) | dois cartões de volume + subtítulo "Oferta … · Ver na Lista SKUs" que a referência não tem |
| Título da ficha 42 px (REF-2 ≈44) e página com 1037 px de altura para 2 variações | proporção medida dentro de ±10% |
| Valores, logísticas e fretes | os do servidor para os dados; o mockup tem combinações impossíveis |

## Revisão D-23 (06/10/2026) — lista + ficha, sem planilha na tela

Motivo: o checkpoint visual do 167-17 foi REPROVADO. A tela nasceu como grade de células (o D-12 lido como "tabela
editável") e o usuário disse: "eu disse que não queria uma planilha dentro do sistema pra esse caso". "Na tela, no sistema
mesmo" quer dizer FORMULÁRIO. As decisões D-23 e D-24 do 167-CONTEXT.md substituem o formato do D-12, e esta seção VALE
SOBRE as seções abaixo onde houver conflito.

**Passa a valer**

- Cabeçalho: `Famílias e ambientes` · `Importar planilha` · `Baixar modelo (.xlsx)` · **`Adicionar produto`** (abre a ficha em
  branco) · `Como funciona`. A barra tem só a busca e os botões contextuais (Sugerir categorias, Consultar fretes), sem
  "Salvando… / Salvo".
- Lista de cartões no lugar da tabela, em qualquer largura: `grid-cols-1 md:grid-cols-2 xl:grid-cols-3`; cartão
  `rounded-2xl border border-white/[0.08] bg-ecf-card p-4`. O cartão mostra nome, "Família · Ambiente(s)" e categoria (com
  "a confirmar" / "não validada"); cada variação mostra Ref, Valor, pílula de logística, frete, medidas, peso total (só com
  2+ volumes), peso cubado, custo e, se houver, "Falta: …" em texto apagado, sem cor, sem ícone e sem contador.
- Ficha do produto: o formulário da seção "Mobile e telas estreitas" vale para todos os tamanhos. `Sheet` com `side='right'`
  a partir de 768 px e `side='bottom'` abaixo; campos em 4 colunas a partir de 640 px; família, ambiente e categoria são
  escolhidos numa folha sobreposta (mesmos pickers, outro contêiner); "Salvar produto" é o único amarelo da ficha e a única
  forma de gravar (um POST `linhas` com todas as variações).
- Proteção do que foi digitado: com alteração não salva, clique fora e Esc não fecham a ficha e ela diz "Há alterações não
  salvas. Use Salvar produto ou feche pelo X para descartar." O X fecha (ação explícita).
- Erro de variação: "Não salvamos esta variação: {motivo}."
- Estado vazio sem tabela por baixo, com o corpo "Aqui ficam os produtos que você vende, com medidas, peso e custo. Cada
  variação vira uma oferta na Lista SKUs. Cadastre um produto por vez aqui ou importe a planilha-modelo preenchida."
- Aviso dispensável da lista: "Para cadastrar muitos produtos de uma vez, preencha o modelo e use Importar planilha."
- Acento: "Adicionar produto" / "Cadastrar o primeiro produto" (página), "Salvar produto" (ficha), "Confirmar importação"
  (janela).
- "Sem sugestão — escolha no produto" na janela de sugestões em lote.

**Deixam de valer**

- A linha "Grade" do Design System e a frase da casca sobre a tabela que rola; a altura de linha/cabeçalho de 40 px.
- "A tabela — colunas e ordem" (o que as colunas 9 a 14 mostravam passou para a variação no cartão), "Aparência de campo",
  "Agrupamento visual" e "Ações da linha".
- "Teclado, colar e fluxo de entrada rápida" inteira, inclusive "Volumes — edição dentro da grade". "Nova variação (D-04)"
  continua, dentro da ficha.
- A coluna "Falta" e o "menu de colunas" (viram a linha "Falta:" do cartão).
- Em Mobile: os itens de 1024 px e de 768 a 1023 px, "Colar do Excel e navegação por Tab são só desktop" e o aviso antigo.
- Em Acessibilidade: `role="grid"` / `columnheader`.
- "Extensões exigidas do SpreadsheetGrid": continuam no componente compartilhado, sem uso nesta tela.
- No Copywriting: o corpo antigo do estado vazio, "Salvando / salvo" da barra e o "Erro de linha" (vira "variação").


---

## Design System

| Propriedade | Valor |
|-------------|-------|
| Tool | none (`components.json` ausente; primitivos shadcn-style já existem em `resources/js/Components/ui/*`) |
| Preset | não se aplica |
| Biblioteca de componentes | Radix UI (via `Components/ui/*`: `dialog`, `sheet`, `dropdown-menu`, `tabs`, `checkbox`, `input`, `label`, `progress`) + `Janela` do módulo |
| Biblioteca de ícones | `lucide-react` (já usada: `Plus`, `Pencil`, `Trash2`, `Search`, `X`, `DownloadCloud`, `AlertTriangle`, `ChevronRight`) |
| Fonte | a do `PortalClienteLayout`; títulos com `font-display`; números da tabela com `tabular-nums` |
| Grade | `SpreadsheetGrid` (D-12) estendido de forma ADITIVA com props opcionais (ver "Grade — extensões exigidas") |
| Peças reaproveitadas, sem reinventar | `PortalClienteLayout`, `CabecalhoEstrutura`, `Botao`, `Campo`, `AvisoFlash`, `Paginacao`, `FotoProduto` (não usado aqui), `Janela`, `ComoFunciona`, padrão visual de `PreviaColagem` |

Casca da página (idêntica às outras `Estrutura*.jsx`): `PortalClienteLayout titulo="Produtos"` → `div.mx-auto.max-w-6xl.space-y-4.px-4.py-6`
→ `CabecalhoEstrutura etapa="produtos"`. A tabela é a única parte que pode passar de `max-w-6xl`: ela rola na horizontal dentro do próprio
cartão (`overflow-x-auto`), com as 2 primeiras colunas fixas (sticky).

---

## Escala de Espaçamento

Valores declarados (múltiplos de 4):

| Token | Valor | Uso |
|-------|-------|-----|
| xs | 4px | espaço entre ícone e texto, entre chips |
| sm | 8px | padding horizontal da célula da tabela (`px-2`), gap entre botões |
| md | 16px | padding de cartão/janela, gap entre blocos (`space-y-4`) |
| lg | 24px | respiro do estado vazio, padding de página no desktop |
| xl | 32px | — (não usado nesta tela) |
| 2xl | 48px | — |
| 3xl | 64px | — |

Alturas: linha da tabela **40px** (`h-10`); cabeçalho da tabela 40px; campo de formulário (Sheet mobile, janelas) **44px** (`h-11`).
Exceções: alvos de toque de 44px no mobile (campos, botões de ação da linha); larguras de coluna em px (Colunas) — todas múltiplos de 4; linha e cabeçalho da tabela em 40px (`h-10`, densidade para ~70 linhas sem rolagem excessiva, igual à grade do Onboarding); coluna de ações em 56px (dois ícones de 24px + 8px de respiro); `p-3` (12px) só na caixa da prévia de importação, herdado do `PreviaColagem` existente para não divergir do componente reaproveitado.

---

## Tipografia

> Dívida aceita (FLAG do checker, 05/10): `Botao` e `CabecalhoEstrutura` existentes herdam `font-medium`/`font-bold`; ficam como estão nesta fase — os 2 pesos abaixo valem para o que é novo.

Exatamente 4 tamanhos e 2 pesos nesta tela.

| Papel | Tamanho | Peso | Altura de linha |
|-------|---------|------|-----------------|
| Corpo / texto de célula | 13px | 400 | 1.5 |
| Rótulo (label acima do campo, cabeçalho de coluna) | 12px | 600 | 1.4 — **caixa normal, nunca uppercase** |
| Apoio (dicas, caminho da categoria, "estimativa", pendência, pílulas) | 12px | 400 | 1.4 |
| Título de bloco (janelas, estado vazio) | 15px | 600 | 1.2 |
| Display (h1 do `CabecalhoEstrutura`) | 24px | 600 | 1.2 |

Notas: o `CabecalhoEstrutura` e o `Botao` existentes seguem como estão (herdados; `font-bold`/`font-medium` deles não são reescritos).
Código/SKU e medidas em `font-mono` 13px 400. Números com vírgula decimal pt-BR (`27,8 kg`, `R$ 1.234,50`); o campo aceita vírgula OU ponto.

---

## Cor

| Papel | Valor | Uso |
|-------|-------|-----|
| Dominante (60%) | `ecf-bg` `#050507` | fundo da página e corpo da tabela |
| Secundária (30%) | `ecf-card` `#0f1116` (+ `border-white/[0.08]`) | cartão da tabela, janelas, cabeçalho da tabela (`ecf-card-2` `#14161d`), popovers |
| Acento (10%) | `ecf-yellow` `#ffe600` | LISTA FECHADA abaixo |
| Destrutivo | `red-300` texto / `red-500/10` fundo / `red-500/30` borda (como `Botao variante="perigo"`) | só confirmação de exclusão e erro de linha DEPOIS de uma tentativa de salvar |

Acento reservado para (e nada mais):
1. o botão primário da página, **"Adicionar produto"** (um único amarelo por vista; no estado vazio, "Cadastrar o primeiro produto"); dentro de uma janela, o botão de confirmar daquela janela ("Confirmar importação"); no celular, o botão fixo "Salvar produto" do Sheet;
2. o contorno da célula ativa da tabela (`outline ecf-yellow/60`) e o foco de campos (`focus:border-ecf-yellow/40`, como a busca da Lista);
3. o item ativo da trilha (`CabecalhoEstrutura` já faz) e a página atual da `Paginacao`.

Cores semânticas das pílulas da tabela (apoio, 12px, `rounded-full px-2 py-1`; sem borda grossa, sem ícone de alarme):

| Estado | Estilo |
|--------|--------|
| ME2 · Full | `bg-emerald-500/10 text-emerald-300` |
| ME2 | `bg-sky-500/10 text-sky-300` |
| ME1 | `bg-white/[0.06] text-white/60` (NEUTRO — ME1 não é problema, é só outro caminho) |
| Pendente | `bg-white/[0.04] text-white/45` |
| "do Produtos" (Lista SKUs/Precificação) | `bg-white/[0.06] text-white/60` |
| Estimativa / pendência / "a confirmar" | texto `text-white/45`; **âmbar (`text-amber-300`) só** para aviso explícito de risco (frete grátis obrigatório, valor não validado) |

Vermelho NUNCA aparece em linha vazia, campo faltando ou "pendência". Linha em edição incompleta = sem cor nenhuma.

---

## A página Produtos — estrutura

**Entrada do Mapeamento (D-21):** `/portal/estrutura` abre **Produtos** para empresa sem ofertas antigas ou que já tem produtos; empresa que já trabalha pela Lista SKUs (ofertas sem produto) continua entrando na **Lista SKUs**. No menu, Produtos é sempre o primeiro submódulo.

Ordem vertical:

1. **Cabeçalho** (`CabecalhoEstrutura etapa="produtos"`): descrição "Cadastre cada produto uma vez, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs." Ações, nesta ordem:
   `Planilha ▾` (secundário, `dropdown-menu`: "Baixar modelo (.xlsx)" como `<a href download>`, "Importar planilha…") · `Famílias e ambientes` (secundário) · **`Adicionar produto`** (primário amarelo; adiciona linha em branco ao fim da tabela e põe o foco na célula Ref) · `Como funciona` (fantasma, já do cabeçalho).
2. **Barra da tabela** (uma linha, `flex-wrap gap-2`): busca "Buscar código ou nome…" (mesmo input da Lista: `rounded-xl border-white/[0.10] bg-white/[0.04]`, debounce 350 ms, servidor) · à direita, apoio 12px: estado de gravação ("Salvo" com check discreto / "Salvando…") · botões fantasma contextuais, só quando fazem sentido: `Sugerir categorias` (existe linha sem categoria) e `Consultar fretes no Mercado Livre` (conta ML conectada e existe linha ME2).
3. **Aviso de colagem/lote** (opcional, 1 linha dispensável com X; apoio 12px, `border-white/[0.08] bg-white/[0.03]`): ex. "Coladas 40 linhas. Criamos as famílias Farmhouse e Palhinha Slim." (ver "Colar").
4. **A tabela** (cartão `rounded-2xl border border-white/[0.08] bg-ecf-card`, `data-tabela-produtos`).
5. **Nota de rodapé** do frete (apoio 12px) — ver "Frete".
6. **`Paginacao rotulo="produtos"`** (≈100 produtos por página, convenção do módulo; busca no servidor).

Não existe: contador "X de Y completos", barra de progresso do cadastro, resumo de pendências no topo (a RESEARCH sugeriu um contador de resumo — **rejeitado** por preferência do usuário; a pendência vive só na linha).

---

## A tabela — colunas e ordem

Uma linha por **variação**; produtos agrupados (variações seguidas). A coluna "Grupo" da planilha NÃO é coluna da tela: o agrupamento é visual e o código do grupo é derivado (continua na importação/modelo).

| # | Coluna (rótulo) | Tipo na grade | Largura | Obs. |
|---|-----------------|---------------|---------|------|
| 1 | Ref | `text` (mono), **fixa à esquerda** | 120 | código da variação (SKU da oferta); obrigatório para gravar |
| 2 | Produto | `text`, **fixa à esquerda** | 240 | nome do produto; obrigatório para gravar |
| 3 | Eixo | `select` (lista fechada D-20: Cor, Tamanho, Voltagem, Material, Sabor, Outro) | 96 | vazio em produto de variação única |
| 4 | Valor | `text` | 120 | "Natural", "Preto"; livre |
| 5 | Família | `picker` (lista da empresa, escolha única) | 152 | rótulo da coluna com dica: "Família (linha de design)" |
| 6 | Ambiente | `picker` (lista da empresa, múltipla) | 168 | chips; "Sala Jantar +1" quando não cabe |
| 7 | Categoria ML | `picker` (busca remota) | 220 | folha do ML; caminho completo no tooltip e no popover |
| 8 | Volumes | `picker` (editor de volumes) | 200 | resumo: `186×43×12 · 27,8 kg` e, se mais de um, `+1 volume` |
| 9 | Peso total | `readonly` | 88 | soma dos volumes (do servidor); `39,9 kg` |
| 10 | Custo | `currency` | 104 | `R$`; aceita vírgula/ponto |
| 11 | Peso cubado | `readonly` | 96 | `17,90 kg`; sufixo apoio "cobrado" quando é o peso faturado (cubado > 5 kg e > real) |
| 12 | Logística | `readonly` (pílula) | 104 | ME2 · Full / ME2 / ME1 / Pendente |
| 13 | Frete ME2 | `readonly` | 120 | valor + "estimativa" ou "ML"; estados em "Frete" |
| 14 | Falta | `readonly` (texto de apoio) | 160 | "custo · categoria"; vazio quando completo |

- Colunas 11–14 são **calculadas no servidor e só exibidas** (PORTAL-02: a conta roda no PHP). O JS não tem lógica de cubagem/ME2/Full.
  Visualmente separadas das editáveis por `border-l border-white/[0.08]` e texto `text-white/60` (não parecem campo).
- Nº de volumes NÃO é coluna (derivado; aparece no resumo da célula Volumes).
- Cabeçalho de coluna: 12px/600, caixa normal, `bg-ecf-card-2`, fixo ao rolar a página (sticky top).

### Aparência de "campo" (reação direta à queixa "nem parecem que são para preencher")

Célula editável vazia mostra o **placeholder** em `text-white/25` ("código", "nome do produto", "escolher" em Família/Ambiente/Categoria, "adicionar medidas" em Volumes, "0,00") e, em hover, `bg-white/[0.04]`
com `cursor-text`. Célula editável tem sempre fundo `bg-black/40` e divisor `border-white/[0.06]` — contraste claro com as calculadas
(`bg-transparent`, texto mais apagado). Célula ativa: `outline outline-1 outline-ecf-yellow/60`; em edição: `border-white/20 bg-black/40`.

### Agrupamento visual

- Primeira linha do produto: normal. Linhas seguintes (variações 2, 3…): células de **produto** (Produto, Família, Ambiente, Categoria) em `text-white/40`,
  e a célula Ref com `border-l-2 border-white/15` + recuo `pl-2` (conector discreto). Tooltip nas células de produto das linhas seguintes: "Vale para todas as variações".
- Editar qualquer célula de **produto** em qualquer linha do grupo propaga para todo o grupo (servidor aplica e devolve o grupo; a tela repinta as linhas irmãs).
- Entre grupos: `border-t border-white/[0.08]`. Sem faixas zebradas, sem cabeçalho de grupo.

### Ações da linha (coluna final fixa à direita, 56px, só ícones)

Aparecem em hover/foco da linha; **sempre visíveis em toque**. `+ variação` (ícone `Plus`, `aria-label="Nova variação de {Ref}"`), `Excluir` (ícone `Trash2`, hover `text-red-300`).
`+ variação` só na última linha do grupo.

---

## Teclado, colar e fluxo de entrada rápida (D-12)

| Ação | Comportamento |
|------|---------------|
| Tab / Shift+Tab | anda entre células **editáveis** da linha, pulando as calculadas; no fim da linha vai à 1ª editável da linha de baixo; na última linha, cria linha nova em branco |
| Enter | confirma a célula e desce na mesma coluna; na última linha preenchida, cria nova linha |
| F2 / digitar | entra em edição (digitar substitui, F2 edita); Esc cancela; Delete limpa a célula (não a linha) |
| Setas | movem a seleção (já existe); Ctrl+Z / Ctrl+Y desfazem/refazem (já existe) |
| Enter/Espaço em `picker` | abre o popover da célula; foco no campo de busca; Esc fecha devolvendo o foco à célula |
| Ctrl+V | cola bloco do Excel a partir da célula ativa, **crescendo linhas** (evento DOM `paste`, `clipboardData`; não `readText()`) |

**Gravação:** automática por linha, ao sair da linha (ou 800 ms parado), só quando a linha tem **Ref e Produto**; antes disso ela vive só no navegador
(sem erro, sem aviso). O estado "Salvando…/Salvo" mora na barra. Falha de uma linha não bloqueia as outras.

**Nova variação (D-04):** `+ variação` insere linha logo abaixo do grupo, copiando da 1ª variação: Eixo, Volumes, Custo (e os campos de produto, que são do grupo).
Ref sugerida `{código base}-{n+1}` (editável). **Valor fica vazio e recebe o foco** — é só o que muda. Peso total/cubado/logística recalculam ao gravar.

**Novo produto:** `Adicionar produto` ou Enter na última linha → linha em branco; foco em Ref. Eixo/Valor em branco = variação única.

**Colar:**
- Se a 1ª linha colada tiver os cabeçalhos do modelo (Ref, Produto, Variação, Família, Ambiente, Categoria ML, Volumes, Custo…), mapeia por NOME de coluna, em qualquer ordem;
  senão, mapeia por posição a partir da célula ativa.
- Coluna "Variação" colada aceita `1`, `2`, `única` (vira ordem) ou `Cor: Natural` (vira Eixo + Valor).
- Família/ambiente colados que casam com a lista (sem caixa/acento/espaço) viram escolha; os que não casam **entram como novos na lista ao gravar** e o aviso da barra diz quais.
  Categoria colada como nome de categoria fica como texto com a marca "a confirmar" (nunca vira id sozinha).
- Volumes colados no formato `186×43×12 · 27.8 | 97×42×12 · 12.1` são interpretados; texto ilegível → célula mantém o texto, sem gravar volume, e a linha ganha a pendência "medidas".
- Colou mais de ~200 linhas: aviso "Cole até 200 linhas por vez. Para mais, use Importar planilha."

**Volumes — edição dentro da grade (sem sair dela):** `picker` abre um popover ancorado à célula (largura 320px, `bg-ecf-card border-white/[0.08] rounded-xl p-4`):
- uma linha por volume com 4 campos curtos **Comp. × Larg. × Alt. (cm)** e **Peso (kg)** — rótulo 12px acima do 1º volume, h-10;
- Tab anda campo a campo; Enter no último campo do volume cria o próximo volume; `Remover` (ícone `X`) por volume;
- linha de apoio embaixo: "Pacote para o frete: 186×43×24 cm · 39,9 kg" (empilhado, D-17, vindo do servidor após gravar; antes disso omitido);
- fechar (Esc, clique fora, Tab para fora) **grava** (mesma regra da `TextareaPopup` existente). Botão "Fechar" fantasma; sem "Salvar" separado.
- A célula também aceita digitar/colar o texto cru no formato da planilha (atalho de quem já tem a planilha).

---

## Família e ambiente — criar uma vez, depois escolher

- `picker` abre popover (`w-64`) com campo de busca no topo (h-10) e lista abaixo. Família: escolha única (clicar escolhe e fecha). Ambiente: lista com `checkbox`, vários, fecha com Esc/clique fora (grava ao fechar).
- Sem correspondência exata (comparação sem caixa/acento/espaço) o último item é **`Criar “{texto}”`** (Enter). Criou → já fica escolhido; a lista da empresa ganha o nome (sem tela de cadastro separada).
- Nome inválido (`/`, `,`, `|`): sob o campo, 12px `text-amber-300`: "Não use / , | no nome. Escolha um nome simples." (não é erro vermelho — é orientação ao digitar).
- Quase igual ao existente ("Sala estar" × "Sala Estar"): ao tentar criar, o item vira "Usar “Sala Estar”" (escolhe o existente) em vez de duplicar.
- Dica fixa do popover de Família (apoio 12px): "Família é a linha de design (ex.: Farmhouse), não a cor do produto."

**Gerenciar as listas:** botão `Famílias e ambientes` abre `Janela` (`max-w-lg`) com 2 abas (`ui/tabs`): "Famílias" e "Ambientes". Cada item: nome, "usada em N produtos" (apoio), `Pencil` (renomear inline; os produtos acompanham)
e `Trash2`. Item **em uso**: lixeira desabilitada, tooltip e texto "Em uso em N produtos. Troque nos produtos para poder excluir." (não há confirmação porque não há como excluir). Item sem uso: exclui após clique em "Excluir" → confirmação inline "Excluir “X”? [Excluir família] / [Excluir ambiente] [Manter]". Campo "Nova família/Novo ambiente" no topo (h-11, Enter adiciona). Sem contagem total, sem barra.

---

## Categoria ML

- Célula vazia: placeholder `escolher` (apoio). Preenchida: **nome da folha** (13px) e, em hover/foco, tooltip com o caminho inteiro "Casa, Móveis e Decoração > Móveis > Cristaleiras".
- Popover `picker` (`w-[420px]`, `max-w-[92vw]`): campo de busca **já preenchido com o nome do produto** e busca ao abrir (debounce 350 ms). Até 8 sugestões; cada uma: nome da folha 13px/600 + caminho completo 12px `text-white/45` em até 2 linhas.
  Categoria que não é folha aparece apagada (`text-white/35`) com "Escolha uma mais específica" e **não é selecionável**.
- A 1ª sugestão vem em destaque de teclado (seta/Enter), **mas nada é aceito sem Enter/clique** (D-06).
- Estados: buscando → "Buscando categorias…" (spinner discreto); sem resultado → "Nada encontrado. Tente outra palavra, como o tipo do produto."; ML fora do ar → "Não deu para buscar agora. Você pode tentar de novo ou deixar para depois." com `Tentar de novo`. Cadastro nunca bloqueia por causa da categoria.
- Valor colado/importado como id sem validação do ML: aceito, com apoio 12px amber "não validada" no tooltip da célula.

**Sugerir para os pendentes (lote):** botão fantasma `Sugerir categorias` (aparece com ≥1 linha sem categoria). Roda em lotes de 10 no servidor, conduzido pelo navegador, mostrando só
"Buscando sugestões…" com spinner (**sem "12 de 70"**). Ao terminar abre `Janela` (`max-w-3xl`) "Revisar categorias sugeridas": uma linha por produto — nome do produto, 1ª sugestão (folha + caminho) e uma caixa de seleção;
caixas **desmarcadas por padrão** (nada é aceito sozinho), com links "Marcar todas" e "Desmarcar". Botão `Aceitar marcadas` (secundário) e `Fechar`. Produto sem sugestão: "Sem sugestão — escolha na tabela".

---

## Frete, logística e peso cubado

Os campos 11–14 chegam do servidor. O frete **sempre** nasce como estimativa da tabela da ECF, instantâneo, sem chamar o ML.

| Estado da linha | Célula "Logística" | Célula "Frete ME2" |
|-----------------|--------------------|--------------------|
| Sem medidas/peso | pílula **Pendente** (tooltip: "Pendente: completar cadastro") | `—` |
| ME2 (ou ME2·Full), sem conta ML conectada | pílula | `R$ 23,40` + apoio "estimativa" |
| ME2, conta conectada, ainda não consultado | pílula | `R$ 23,40` + apoio "estimativa" |
| Consultando o ML | pílula | spinner 12px + "consultando" (apoio) |
| ME2, valor real do ML | pílula | `R$ 25,90` + apoio "ML" |
| Consulta falhou | pílula | valor da estimativa + apoio "não consultado"; tooltip "Não deu para consultar o Mercado Livre agora. Tente de novo." |
| ME1 | pílula **ME1** | `—` + apoio "sem frete aqui" (tooltip: "Fora do tamanho do envio ME2. O frete usa a tabela da sua transportadora; ainda não calculamos aqui.") |
| Preço de referência (sem custo) | pílula | valor + apoio "faixa de referência" (tooltip: "Informe o custo para o frete usar o preço certo.") |
| Preço perto do limite de frete grátis obrigatório (resposta da API) | pílula | valor + ícone `AlertTriangle` 12px amber + tooltip "Neste preço o frete pode mudar de faixa." (o limite nunca está no texto da tela; vem da API) |

- Ação explícita `Consultar fretes no Mercado Livre` (barra): visível só com conta ML conectada e ao menos uma linha ME2; consulta no máximo 12 por requisição, repetindo até zerar; enquanto roda o botão vira "Consultando…" desabilitado. Ao fim: aviso pontual "Fretes atualizados." (flash `success`). Falha geral: "Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa."
- Sem conta ML conectada: botão ausente; **nota de rodapé** da tabela (apoio 12px): "O frete é uma estimativa pela tabela da ECF (vigente desde 02/03/2026, reputação verde). Conectando sua conta do Mercado Livre, mostramos o valor real."
- Peso cubado: `C×L×A ÷ 6000` e a regra do 5 kg ficam no servidor. Tooltip da célula: "Peso cobrado: 17,90 kg". Sufixo "cobrado" só quando o cubado é o faturado.
- Kit/combo/combit: nada de logística nem frete (D-18) — esta tela só tem ofertas simples.
- O frete exibido **não preenche** a Precificação (D-19).

---

## Pendências por linha (PR167-11) — discretas

Coluna "Falta" (apoio 12px, `text-white/40`, sem pílula, sem ícone, sem cor): lista curta em minúsculas separada por " · ":
`medidas`, `peso`, `custo`, `categoria`, `família`, `ambiente`. ME1 acrescenta "frete ME1". `estimativa` NÃO é pendência (já aparece no frete).
Linha completa = célula vazia (não existe "Completo ✓"). Linha ainda não gravada (sem Ref/Produto) = vazia também.
Ordem de relevância fixa: medidas, peso, custo, categoria, família, ambiente. Se não couber, corta com `…` e o tooltip mostra a lista inteira.
A coluna pode ser ocultada pelo menu de colunas da grade (já existe no `SpreadsheetGrid`); o padrão é visível.

---

## Planilha-modelo e importação (D-13/D-14)

`Planilha ▾` → **Baixar modelo (.xlsx)** (link comum `<a href>`, 11 colunas e nomes da aba Produtos; 1 linha de exemplo FICTÍCIA; aba "Instruções") e **Importar planilha…**.

`Janela` "Importar planilha" (`max-w-3xl`):
1. **Escolher arquivo:** área tracejada (`border-dashed border-white/[0.14] rounded-xl p-6 text-center`) "Arraste o arquivo ou clique para escolher" + apoio "Aceita .xlsx, até 2 MB e 1.000 linhas. Tem a planilha de Produtos do Planejamento? Pode importar como está." + link `Baixar o modelo`.
2. **Prévia** (mesmo desenho do `PreviaColagem`: caixa `rounded-xl border-white/[0.08] bg-white/[0.02] p-3`, 12px, título "Prévia — nada foi gravado ainda" em caixa normal):
   grupos com cor de texto (não de alarme): **novos** (emerald), **atualizados** (sky), **sem mudança** (white/45), **com erro — não serão gravados** (red-300, abertos por padrão, `linha N: motivo`).
   Bloco "Serão criadas nas listas": Famílias: … · Ambientes: … (apoio). Avisos amber de divergência (Nº volumes ≠ soma, grupo com nome diferente "usamos o da primeira linha").
   Cada grupo recolhível, mostrando até 200 itens; totais inteiros ao lado do nome do grupo (contagem aqui é informação da prévia, necessária).
3. Rodapé: apoio "Reimportar atualiza pelo código da variação. Nada é apagado." · `Voltar` (fantasma) · **`Confirmar importação`** (primário amarelo, desabilitado se não há novos nem atualizados).
   Durante a gravação: botão "Importando…" desabilitado. Terminou: janela fecha e flash `success` "Importação concluída: {n} novos, {m} atualizados." (a confirmação refaz o plano no servidor).
Erro geral (arquivo inválido, grande, sem colunas): `text-red-300` 13px dentro da prévia (veio de uma tentativa do usuário), com como resolver.
Categoria em nome (não id) vem como "a confirmar" → após importar, a barra oferece `Sugerir categorias`.

---

## Excluir variação (D-22) e vínculos de oferta

**Confirmação** em `Janela` (`max-w-md`), título "Excluir a variação {Ref}?" — botão `Excluir variação` com `variante="perigo"` e `Manter variação` fantasma. Texto conforme o caso:
- Sem anúncios: "A oferta {SKU} também será excluída da Lista SKUs."
- Com anúncios (mostra a contagem): "Esta variação tem {n} anúncio(s) cadastrado(s). Eles voltam para a área de espera e o item do Publicador fica solto. A oferta {SKU} também será excluída."
- Última variação do produto: acrescenta "É a última variação, então o produto {nome} também será excluído."
- Bloqueada (oferta é componente): sem botão de excluir; "Não dá para excluir {Ref}: a oferta {SKU} entra em {SKU-kit}, {SKU-combo}. Tire-a dessas ofertas antes." + botão `Entendi`.
Excluir produto inteiro não existe como ação separada (exclui-se a última variação). Linhas ainda não gravadas somem sem confirmação.

**Lista SKUs (oferta ligada):** pílula neutra "do Produtos" ao lado do SKU; no formulário de edição, SKU, Nome e Fase aparecem como texto somente leitura (não como campo) com apoio "Vem do Produtos." e link `Editar no Produtos`;
Logística e observações seguem editáveis; o botão excluir da oferta é trocado pelo link `Excluir pelo Produtos` (texto: "Esta oferta vem do Produtos. Exclua a variação lá.").
**Precificação (oferta ligada):** o campo de custo vira valor somente leitura (`R$ 120,00`) com apoio 12px "vem do produto" + link `Alterar no Produtos`. Combo/kit/combit seguem somando componentes (já mostram "pelos componentes").

---

## Estado vazio (empresa sem produtos)

Cartão `rounded-2xl border border-dashed border-white/[0.12] p-6 text-center` acima de uma tabela já visível com 1 linha em branco (o cliente pode começar a digitar sem clicar em nada):
- Título (15px/600): **Cadastre seus produtos uma vez**
- Corpo (13px, `text-white/50`, `max-w-lg`): "Aqui ficam os produtos que você vende, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs. Digite na tabela abaixo ou cole as linhas do Excel."
- Botões: **`Cadastrar o primeiro produto`** (primário; foca a célula Ref da linha em branco) · `Baixar planilha-modelo` (secundário, link) · `Importar planilha` (secundário).
Enquanto o cartão está visível, o `Adicionar produto` do cabeçalho fica **secundário** — o único amarelo da vista é `Cadastrar o primeiro produto`. O cartão some assim que existir o primeiro produto gravado (e o `Adicionar produto` volta a ser o primário). Busca sem resultado: "Nenhum produto com essa busca." (centralizado, como na Lista).

Texto da aula (`ComoFunciona`, 3 itens curtos): "1. Cadastre o produto e as variações (cor, tamanho…). 2. Informe medidas, peso e custo — o sistema mostra o tipo de envio e o frete. 3. Cada variação já vira uma oferta na Lista SKUs."

---

## Contrato de Copywriting

| Elemento | Texto |
|----------|-------|
| CTA primário da página | **Adicionar produto** |
| CTA do estado vazio | Cadastrar o primeiro produto |
| CTA da importação | Confirmar importação |
| Título do estado vazio | Cadastre seus produtos uma vez |
| Corpo do estado vazio | Aqui ficam os produtos que você vende, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs. Digite na tabela abaixo ou cole as linhas do Excel. |
| Placeholders (célula vazia) | Ref: `código` · Produto: `nome do produto` · Valor: `ex.: Natural` · Família/Ambiente/Categoria: `escolher` · Volumes: `adicionar medidas` · Custo: `0,00` |
| Salvando / salvo | Salvando… / Salvo |
| Erro de linha ao salvar (única linha vermelha da tela; `text-red-300` 12px sob a linha, só depois de tentar salvar) | "Não salvamos esta linha: {motivo}. Corrija e tente de novo." |
| Código repetido | "O código {Ref} já existe em outro produto. Use outro código." |
| Número inválido | "Use só números. Exemplo: 27,8" |
| Falha de rede ao gravar | "Não foi possível salvar agora. Suas alterações ficam na tela; vamos tentar de novo." |
| Frete — rodapé sem conta ML | O frete é uma estimativa pela tabela da ECF (vigente desde 02/03/2026, reputação verde). Conectando sua conta do Mercado Livre, mostramos o valor real. |
| Frete — falha da consulta | Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa. |
| Conta ML não conectada (tooltip do botão ausente / dica) | Conecte sua conta do Mercado Livre para ver o frete real. |
| ME1 | Fora do tamanho do envio ME2. O frete usa a tabela da sua transportadora; ainda não calculamos aqui. |
| Pendente | Pendente: completar cadastro |
| Categoria sem resultado | Nada encontrado. Tente outra palavra, como o tipo do produto. |
| Categoria — ML fora do ar | Não deu para buscar agora. Você pode tentar de novo ou deixar para depois. |
| Lista em uso | Em uso em {N} produtos. Troque nos produtos para poder excluir. |
| Nome de lista inválido | Não use / , | no nome. Escolha um nome simples. |
| Importação — arquivo inválido | Não conseguimos ler este arquivo. Use o modelo (.xlsx) e confira se a aba se chama Produtos. |
| Importação — rodapé | Reimportar atualiza pelo código da variação. Nada é apagado. |
| Excluir variação | ver seção "Excluir variação (D-22)" |
| Oferta ligada na Lista SKUs | Esta oferta vem do Produtos. Exclua a variação lá. |
| Custo na Precificação | vem do produto |

Tom: "você", frases curtas, verbo no começo do botão, sem sigla sem explicar na 1ª vez (ME2/ME1 têm tooltip), sem "obrigatório!", sem exclamação, sem emoji.

---

## Mobile e telas estreitas (Portal usado por clientes)

- **≥ 1024px:** tabela completa como descrito.
- **768–1023px:** mesma tabela com rolagem horizontal; Ref e Produto continuam fixas; ações da linha sempre visíveis.
- **< 768px:** a tabela é substituída por **lista de cartões por produto** (a grade em célula não serve com dedo):
  cartão `rounded-2xl border border-white/[0.08] bg-ecf-card p-4`: nome do produto (15px/600), linha de apoio "Família · Ambiente(s)", categoria (apoio), e abaixo as variações
  (`Ref · Valor · logística pílula · frete`). Toque no cartão/variação abre `Sheet` de baixo (`ui/sheet`, `max-h-[90vh] overflow-y-auto rounded-t-2xl`) com **formulário no estilo aprovado**:
  rótulo 12px/600 acima do campo, campo `h-11 border-white/20 bg-black/40`, uma coluna, volumes como cartões empilhados (C, L, A, kg em grade 2×2), botão `Adicionar volume`, botão `Nova variação` (copia a 1ª),
  e **um** botão amarelo de rodapé fixo `Salvar produto` (o único fluxo do mobile em que se salva por botão). Excluir fica no fim do Sheet, texto vermelho pequeno `Excluir variação`.
  Cabeçalho da página: `Adicionar produto` abre o Sheet em branco; `Planilha ▾` continua (baixar/importar funcionam no celular).
  Aviso de uma linha no topo, dispensável: "Para cadastrar muitos produtos de uma vez, use o computador ou a planilha."
- Colar do Excel e navegação por Tab são só desktop. Alvos de toque ≥ 44px. Popovers viram `Sheet` de baixo em < 768px (família, ambiente, categoria, volumes).

---

## Acessibilidade e movimento

- Grade com `role="grid"`, `aria-label="Produtos"`, cabeçalhos `columnheader`; célula calculada `aria-readonly="true"`; pílula sempre com TEXTO (não só cor).
- Popovers: foco vai ao campo de busca ao abrir, Esc fecha e devolve o foco à célula, setas navegam a lista, Enter escolhe.
- Contraste: texto de apoio mínimo `text-white/40` sobre `ecf-card` (aceitável só para informação secundária; informação necessária usa ≥ `text-white/60`).
- Sem animação nova (respeita `prefers-reduced-motion`); spinner simples `animate-spin` só em carregamento.
- Mensagens de gravação/consulta em `role="status"` (como `AvisoFlash`); erro de linha com `role="alert"`.

---

## Extensões exigidas do SpreadsheetGrid (aditivas, props opcionais; default = comportamento atual para não regredir a Planilha de Produtos do Onboarding)

1. `growOnPaste` + captura do evento DOM `paste` (cresce linhas; mapeia por cabeçalho quando presente).
2. Coluna `type: 'picker'` (popover/Sheet com `renderEditor`, resumo na célula, grava ao fechar) — usada por Família, Ambiente, Categoria ML e Volumes.
3. `rowKey` + `onRowsCommit(prev, next)` (diff por linha → só as linhas alteradas vão ao servidor).
4. Tab que pula colunas `readonly`, avança à próxima linha e cria linha no fim (`tabWrap`), e colunas/linhas fixas (`stickyCols`) — pedidos por esta tela.

---

## Segurança do Registro (Registry Safety)

| Registry | Blocos usados | Safety Gate |
|----------|---------------|-------------|
| shadcn official | nenhum bloco novo (usa os primitivos já copiados em `Components/ui/*`) | não se aplica (shadcn não inicializado; sem registry) |
| Terceiros | nenhum | não se aplica |

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: PASS
- [x] Dimension 2 Visuals: PASS
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: PASS
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** aprovado em 2026-10-05 (gsd-ui-checker, revisão 1; recomendações não bloqueantes aplicadas)

---

## Perguntas ao usuário — respondidas em 05/10/2026

1. **Grade:** `SpreadsheetGrid` estendido (já decidido no D-12; Glide descartado).
2. **Colar com família/ambiente que ainda não existem:** **criar ao gravar e avisar** numa linha discreta ("Criadas: …").
3. **Celular:** **cartões + Sheet de formulário com edição** (o cliente cadastra pelo telefone).
4. **"Sugerir categorias" em lote:** **caixas desmarcadas por padrão**, com "Marcar todas" (D-06: nada é aceito sozinho).
5. **Coluna "Falta":** **visível e discreta** — texto apagado, sem cor, sem ícone, sem contador no topo.
