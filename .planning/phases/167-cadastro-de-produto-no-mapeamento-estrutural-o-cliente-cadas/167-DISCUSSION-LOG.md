# Phase 167: Cadastro de Produto no Mapeamento Estrutural - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-05
**Phase:** 167-cadastro-de-produto-no-mapeamento-estrutural
**Areas discussed:** Quem cadastra e onde, Produto ↔ oferta, Família/ambiente/categoria, Como os produtos entram, Custo e frete pelas dimensões (área acrescentada pelo usuário), Tela de cadastro

---

## Quem cadastra e onde

| Option | Description | Selected |
|--------|-------------|----------|
| Exigir Company (Recomendado) | Produtos no Mapeamento, que já é por Company; cliente da Incubadora entra como cliente novo e ganha Company + Portal | ✓ |
| Também sem Company | Produto aceita empresa polo sem Company; Lista SKUs, Precificação e Agenda teriam que mudar também | |

| Option | Description | Selected |
|--------|-------------|----------|
| Cliente e equipe (Recomendado) | Cliente pelo Portal, equipe pela entrada de equipe; histórico registra quem foi | ✓ |
| Só o cliente | Equipe só vê | |
| Cliente cadastra, equipe corrige | Trava depois de conferido | |

**User's choice:** Exigir Company; cliente e equipe editam.

---

## Produto ↔ oferta

| Option | Description | Selected |
|--------|-------------|----------|
| Sim, uma por variação (Recomendado) | Oferta simples nasce sozinha, como A002-V1/V2 na planilha | ✓ |
| Não, só catálogo | Todas as ofertas nascem na fase de geração | |
| A equipe liga à mão | Produto solto, ligação manual | |

| Option | Description | Selected |
|--------|-------------|----------|
| Ligar pelo SKU (Recomendado) | Casa produto novo com oferta simples existente pelo SKU | |
| Criar produto para cada uma | Backfill de produtos "vazios" (500 na #131) | |
| Deixar como estão | Ofertas antigas nunca são ligadas | ✓ |

| Option | Description | Selected |
|--------|-------------|----------|
| No produto (Recomendado) | Oferta simples usa o custo do produto; combos somam componentes | ✓ |
| Produto sugere, Precificação manda | Digitado na Precificação vence | |
| Continua na Precificação | Produto sem custo | |

**User's choice:** oferta simples automática por variação; ofertas existentes intocadas (escolha diferente da recomendada); custo no produto.

---

## Família, ambiente, categoria (e variação)

| Option | Description | Selected |
|--------|-------------|----------|
| Lista da empresa (Recomendado) | Cria uma vez, escolhe depois; ambiente múltiplo; qualquer segmento | ✓ |
| Ambiente fixo da ECF | Lista única de ambientes para todos | |
| Texto livre | Como a planilha | |

| Option | Description | Selected |
|--------|-------------|----------|
| Categoria real do ML (Recomendado) | Sugerida pelo nome, grava o código | ✓ |
| Texto livre | Como a planilha | |

| Option | Description | Selected |
|--------|-------------|----------|
| Por variação, copiando a 1ª (Recomendado) | Medidas, peso e custo por variação | ✓ |
| Do produto, igual para todas | Mais simples, erra quando a cor pesa diferente | |

**User's choice:** todas as recomendadas.

---

## Como os produtos entram

| Option | Description | Selected |
|--------|-------------|----------|
| Tela, um a um (Recomendado) | Ficha/tabela no sistema | ✓ |
| Planilha-modelo (Recomendado) | Baixa o modelo, preenche, importa com prévia | ✓ |
| Anúncios do ML | Produtos a partir dos anúncios existentes | |
| Planilha do Onboarding | Planilha do checklist `/implementacao` | |

| Option | Description | Selected |
|--------|-------------|----------|
| Acrescentar e atualizar (Recomendado) | Casa pelo código, nada é apagado | ✓ |
| Substituir tudo | Apaga o que não veio | |

**User's choice:** tela + planilha-modelo; acrescentar e atualizar.
**Notes:** "o cliente pode baixar modelo de planilha, fazer por ele e importar no portal também, mas acho muito mais eficiente podemos fazer os cadastros na tela, no sistema mesmo".

---

## Custo e frete pelas dimensões (área acrescentada pelo usuário)

**Pedido do usuário:** "ver onde pode se encaixar ou se já tem a questão de custo e envios de acordo com as
dimensões do produto, tipo de frete e já trazer quanto pagaria no frete, por exemplo se fosse ME2".
**Levantado no código:** frete ME2 e tarifa reais pela API já existem, mas só no Publicador (por anúncio); a
Precificação do Mapeamento tem frete digitado; a classificação Full/ME2/ME1 por medidas não existe (só na planilha).

| Option | Description | Selected |
|--------|-------------|----------|
| No produto, por variação (Recomendado) | Logística provável, peso cubado e frete ME2 no produto; kits na fase de geração | ✓ |
| Tudo na fase seguinte | Produto só guarda medidas | |

| Option | Description | Selected |
|--------|-------------|----------|
| API do ML, tabela como reserva (Recomendado) | Valor real da conta; sem conta, tabela da ECF como estimativa | ✓ |
| Só a tabela da ECF | Como a planilha | |
| Só a API do ML | Sem conta, sem frete | |

| Option | Description | Selected |
|--------|-------------|----------|
| Empilhar num pacote (Recomendado) | Maior C, maior L, alturas e pesos somados | ✓ |
| Mais de 1 volume = ME1 | Regra simples | |
| A pessoa informa o pacote | Sem cálculo | |

**User's choice:** todas as recomendadas.

---

## Tela de cadastro

| Option | Description | Selected |
|--------|-------------|----------|
| Tabela editável (Recomendado) | Jeito da aba Produtos, reaproveita o SpreadsheetGrid | ✓ |
| Lista + ficha do produto | Mais calma, mais lenta | |
| Tabela + ficha | Mais trabalho | |

**User's choice:** tabela editável. Em seguida: "Pode escrever o CONTEXT".

---

## Claude's Discretion

- Preço usado para cotar o frete (faixa) e os avisos de limite de frete grátis.
- Onde ficam as regras Full/ME2/cubagem e a tabela de frete reserva (configuração global).
- Posição do submódulo no menu (primeiro, antes da Lista SKUs).
- Efeito de alterar/excluir produto ou variação sobre a oferta simples ligada.
- Indicadores de "produto completo".
- Formato do modelo .xlsx e das colunas de volumes.
- Cache/lote das consultas de frete; nomes de tabelas e colunas.

## Deferred Ideas

- Geração automática de combo/kit/combit; logística de kits; grade de margem e tarifa por categoria; cronograma e
  checklist de alavancas; fotos/vídeo/ficha técnica/dados fiscais; produtos a partir dos anúncios do ML; Planilha
  de Produtos do Onboarding; empresa polo sem Company; frete ME1; renomear "Produtos" do Publicador.
