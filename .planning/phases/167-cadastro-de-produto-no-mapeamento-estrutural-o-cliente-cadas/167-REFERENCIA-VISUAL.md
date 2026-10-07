# Fase 167 — Referência visual da tela de Produtos (pedido do usuário, 06/10/2026)

Três mockups gerados pelo usuário (ChatGPT) a partir do contexto da fase, entregues depois do 167-18. Decisões
correspondentes: D-25..D-30 no `167-CONTEXT.md`.

| Arquivo | Estado da tela |
|---|---|
| `167-REF-1-visual-grande.jpg` | Lista de produtos em **cartões grandes** (3 colunas a 1586 px) |
| `167-REF-2-ficha-do-produto.jpg` | **Ficha do produto em página inteira** (edição) |
| `167-REF-3-lista.jpg` | Os mesmos produtos em **cartões horizontais** (modo Lista) |

## Regra de ouro

**COPIE O LAYOUT. PRESERVE A IDENTIDADE DO SISTEMA. PRESERVE A REGRA DE NEGÓCIO REAL. NÃO LIMITE OS CAMPOS AO
MOCKUP.** As imagens são a fonte da verdade para geometria e UX: proporções, larguras, alturas, margens, paddings,
gaps, grid, alinhamentos, hierarquia, posição dos controles, densidade, agrupamento, posição da busca, das ações e
do seletor de visualização, estrutura da ficha, das variações, dos volumes e do rodapé de ações. Ao comparar lado a
lado, a estrutura tem de ser praticamente igual — não "inspirada".

As imagens **não** definem: cores, quantidade de campos, regra de negócio, modelo de dados, obrigatoriedade, lógica
de cadastro. Cores = tokens do sistema (`ecf-*`). Campos e cálculos = os que existem no sistema.

## 1. Visual grande (REF-1)

- Topo: rótulo "MAPEAMENTO ESTRUTURAL", título "Produtos", descrição, trilha de etapas (1 Produtos … 6 Mapeamento).
- Linha de ações: **Adicionar produto** (primário) · Famílias e ambientes · Importar planilha · Baixar modelo ·
  Sugerir categorias — e a **busca à direita** na mesma linha.
- Seletor **[Visual grande] [Lista]** abaixo das ações.
- Cartões: foto à esquerda no topo, nome, "Família · Ambientes", categoria com ícone de etiqueta e caminho
  "A › B"; pílula "Falta: …" com ícone de info no canto quando houver pendência; menu ⋮; depois, uma linha por
  variação: bolinha de cor, Ref, valor, selo de logística, frete ("R$ 78,65 / estimativa") alinhado à direita.
- Resumo útil com os dados reais (não limitar ao exemplo).

## 2. Lista (REF-3)

- Mesmo topo, mesmas ações, seletor com "Lista" ativo. Sem navegar: só muda a representação.
- Cada produto é um **cartão horizontal grande** (não tabela): foto à esquerda; nome + "Família · Ambientes";
  categoria (e "Falta: …"); à direita, as variações empilhadas (bolinha, Ref, valor, selo, frete); ⋮ no fim.
- Mesmos produtos do Visual grande. Preferência de visualização persistida.

## 3. Ficha do produto (REF-2)

- **Página inteira dentro do shell do Portal**, estilo ERP (Bling): nada de drawer, modal ou lista ao lado.
- Topo: "← Produtos › Nome do produto" (breadcrumb/voltar), título = nome, frase de contexto.
- Bloco principal: **foto à esquerda** (não domina) e os campos principais ocupando o resto da largura:
  Nome · Família (seleção) na mesma linha; Ambientes (chips com ×, várias); Categoria do Mercado Livre (busca com
  caminho "A › B › C", limpar).
- Seção **Variações**: um bloco grande por variação — miniatura, Ref + selo de logística no topo; Ref · Eixo · Valor ·
  Custo em linha; "Excluir variação" à direita; **Volumes** em cartões (2 por linha) "Volume N" com Comprimento ·
  Largura · Altura · Peso e lixeira; "+ Adicionar volume"; faixa de **calculados como leitura**: Nº de volumes ·
  Peso total · Logística provável · Peso cubado · Frete ME2 (estimativa).
- "+ Nova variação" em faixa tracejada de largura total; rodapé com **Cancelar** e **Salvar produto**.
- Suportar 1..N variações e 1..N volumes; campos adicionais reais entram na mesma estrutura.

## Fluxo

Produtos → Visual grande/Lista → clicar no produto → ficha em página → Salvar → voltar para Produtos, preservando
busca, filtros, scroll e o modo Visual grande/Lista.

## Responsividade

Prioridade: desktop com fidelidade máxima às referências. Depois adaptar sem destruir a hierarquia.

## Não fazer

Sidebar de edição; drawer; produtos visíveis ao lado durante a edição; cadastro em tabela/planilha; copiar cores
das referências; dados fictícios no lugar das regras; hardcode dos produtos das imagens; limitar variações, volumes
ou campos ao mockup; apagar validações; quebrar APIs; mexer no banco sem necessidade; duplicar lógica; mudar a
identidade visual.

## Qualidade

Reutilizar componentes; refatorar quando impedirem a fidelidade; evitar componentes gigantes — separar, no padrão do
projeto, algo como: página/toolbar/seletor de visualização, grade de cartões, lista, cartão, item de lista, editor,
dados gerais, variações, cartão de variação, volumes, cartão de volume, métricas calculadas, ações do editor.

## Critério de aceite

Abrir as 3 imagens lado a lado com o sistema e reconhecer imediatamente: REF-1 → Visual grande; REF-2 → ficha em
tela inteira; REF-3 → Lista. A única diferença visual relevante é a identidade/cor do sistema; campos, regras,
valores e comportamentos vêm do sistema real.
