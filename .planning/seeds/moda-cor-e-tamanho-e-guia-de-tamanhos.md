# Moda no Portal e no Publicador: cor E tamanho por variação, e a guia de tamanhos

Pedido do usuário em 28/10/2026, testando "Importar do Mercado Livre" com a **Meia Cano Longo** (#459): o anúncio é
**um pai só** com variações de **cor e tamanho** (Branco 34-39, Verde 34-39, Verde 39-43…), e ao entrar no Publicador
aparece "Esta categoria exige tabela de medidas, que o Publicador ainda não suporta. Publique pelo Mercado Livre por
enquanto." Decisão dele: importar como um pai só agora ("Branco · 34-39" em cada variação) e **planejar** o suporte
completo a moda — este documento.

> **Estado em 28/10/2026 (noite):** etapas 1 e 2 FEITAS — o usuário pediu dois eixos ao ver "Outro: Verde · 34-39".
> Escolhidas as duas colunas nulas (`eixo_2`/`valor_2`), não a tabela nova; a tela agrupa por cor ("cor com tamanhos
> dentro"). Detalhes e armadilhas em `.planning/learnings/portal-do-cliente.md` §55.1. **Falta a etapa 3** (guia de
> tamanhos): a meia ainda não publica pelo Publicador. Planilha/exportação ainda NÃO levam o 2º eixo.

## Por que hoje não dá (antes de 28/10 à noite)

| Onde | O que existe | O que falta |
|---|---|---|
| Portal — `estrutura_produto_variacoes` | UM `eixo` + UM `valor` por variação | a segunda característica (cor **e** tamanho) |
| Importação do ML (`TradutorAnuncioMlParaPortal`) | variando as duas, junta em "Cor · Tamanho" no eixo "Outro" | gravar cor e tamanho separados |
| Sincronizar (`PortalParaRascunhoService::planoDoEixo`) | cria UM eixo no rascunho a partir do eixo do Portal | criar dois eixos (COLOR + SIZE) |
| Rascunho do Publicador (`pub_eixos`, `GeradorCombinacoes`) | **já aceita vários eixos** e gera as combinações | — |
| Publicador — `ClassificadorAtributos::bloqueiosFase2` | bloqueio `tabela_de_medidas` em toda categoria com `SIZE_GRID_ID` / `grid_id` / `grid_row_id` | o passo da guia de tamanhos |
| Assistente antigo — `MlGradeService` | detecta a exigência, lista as guias da conta (`POST /catalog/charts/search`) e cria guia (`POST /catalog/charts`) | ser usado pelo Publicador novo |

**Guia de tamanhos ("tabela de medidas")** é uma tabela da conta do vendedor, por domínio (ex.: meias), que diz o que
cada tamanho significa. O anúncio leva `SIZE_GRID_ID` (a guia) e cada variação leva `SIZE_GRID_ROW_ID` (a linha
dela, "guia:linha"). O anúncio importado **já tem as duas** — dá para reaproveitar a guia do anúncio original.

## Proposta, em três etapas (cada uma entrega sozinha)

### 1. Portal com duas características por variação
- **Schema (decisão a escrever antes):** tabela NOVA `estrutura_produto_variacao_caracteristicas`
  (`variacao_id`, `eixo`, `valor`, `posicao`), sem alterar `estrutura_produto_variacoes` (tabela com dado em produção).
  O eixo/valor atual continua sendo a 1ª característica; a nova guarda a 2ª. Alternativa: duas colunas nulas no fim
  de `estrutura_produto_variacoes` (INSTANT no MySQL 8) — mais simples, mas é alterar tabela viva.
- **Ficha:** o cartão da variação ganha o 2º par "Tipo / Valor" (ex.: Cor = Verde, Tamanho = 39-43). Planilha e
  exportação levam as duas colunas. Sigilo: "cor" e "tamanho" são neutros.
- **Importação:** grava cor e tamanho separados; a meia volta a ser UM produto, com "Tamanho" preenchido em cada
  variação. Guarda `SIZE_GRID_ID` e o `SIZE_GRID_ROW_ID` de cada variação no retrato da origem.

### 2. Sincronizar cria os dois eixos
- `planoDoEixo` passa a montar até dois eixos (COLOR e SIZE, pelo `EIXO_PARA_ATRIBUTO`); as fotos continuam por cor
  (`defines_picture` do COLOR).
- `CoresDoGrupo::separar` passa a chavear pelo par (cor + tamanho), não só pelo valor.

### 3. Guia de tamanhos no Publicador (tira o bloqueio)
- Passo novo no editor, só em categoria que exige: escolher uma guia da conta (`MlGradeService::listarGrades`),
  criar uma (`criarGrade`) ou, no produto importado, **usar a do anúncio original**.
- Cada variante ganha a sua linha da guia (`SIZE_GRID_ROW_ID`), casada pelo tamanho.
- Payload: `SIZE_GRID_ID` no item e `SIZE_GRID_ROW_ID` por variante (no modelo User Products, por anúncio).
- Só então sai o bloqueio `tabela_de_medidas` do `ClassificadorAtributos`.

## Antes de codar — a sondar na #459 (loja de teste)
1. Como o ML quer moda no modelo **User Products**: cada cor um anúncio com os tamanhos como variação, ou cada
   cor×tamanho um anúncio? `POST /items/validate` com uma meia, sem publicar.
2. A guia da meia original é reaproveitável num anúncio novo da mesma conta (deve ser: a guia é da conta).
3. O que o ML devolve em `attribute_combinations` de um anúncio de moda real (nome do atributo de tamanho por
   domínio — `SIZE` é o comum, mas há domínios com outro id).

## Fora deste plano
- Medidas do produto e da caixa (já entram pela importação).
- Imagem por IA para moda (desligada para importados de propósito — `CriativosAutomaticosService::trazidoDoMl`).
