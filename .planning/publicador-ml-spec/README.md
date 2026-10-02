# Pacote de especificação — Publicador de anúncios Mercado Livre (MLB)

> **Esta é a cópia oficial**, no repositório desde 01/10/2026, para atravessar máquinas. A análise do código atual e o plano estão no `16-analise-do-portal.md`; o resultado da sondagem da API, no `12-hipoteses-e-pendencias.md`.

Este pacote descreve **como o Publicador deve funcionar**, e não como o sistema atual funciona. Ele foi escrito para ser entregue ao Claude Code junto com os materiais originais (prints das 3 etapas do Anunciar no Seller Center).

> **Regra principal para quem for implementar:** não adapte esta especificação à estrutura atual do sistema antes de entendê-la. Primeiro entenda o modelo (categoria → schema dinâmico → eixos de variação → combinações → grupos de imagem → payload por estratégia). Depois decida o que do código atual pode ser reaproveitado.

---

## Ordem de leitura

| # | Arquivo | Conteúdo | Seção pedida |
|---|---|---|---|
| 00 | `00-analise-dos-materiais.md` | O que os prints mostram, conflitos com a documentação oficial, lacunas | Item 1 |
| 01 | `01-visao-geral.md` | Problema, objetivo, princípios, **os dois modelos de publicação do ML** | A |
| 02 | `02-fluxo-de-publicacao.md` | Etapas com objetivo, entradas, dependências, regras, validações e resultado | B, C |
| 03 | `03-categoria-e-atributos.md` | Como a categoria define o formulário; classificação de atributos | Item 4 |
| 04 | `04-modelo-de-dados.md` | Entidades, campos, relações, máquina de estados | D |
| 05 | `05-regras-de-variacao.md` | Eixos, combinações, chave canônica, preço/estoque/SKU, validações | F |
| 06 | `06-regras-de-imagem.md` | Galeria geral, grupos de imagem, `defines_picture`, montagem por modelo | G |
| 07 | `07-regras-de-negocio.md` | Lista numerada de regras (RN-xxx) | E |
| 08 | `08-validacao-pre-publicacao.md` | Matriz de validações em 3 camadas | H |
| 09 | `09-tratamento-de-erros.md` | Formato de erro do ML, classificação, retry, falha parcial | I |
| 10 | `10-payloads.md` | Exemplos de JSON para cada cenário | complementar |
| 11 | `11-casos-de-teste.md` | Cenários de teste com dados e resultado esperado | J |
| 12 | `12-hipoteses-e-pendencias.md` | **O que precisa ser validado na API real antes/durante a implementação** | Item 10 |
| 13 | `13-glossario.md` | Termos | complementar |
| 14 | `14-checklist-de-publicacao.md` | Checklist operacional | complementar |
| 15 | `15-fontes.md` | Fontes consultadas, com datas | complementar |

---

## Legenda de classificação (usada em todos os arquivos)

Toda regra relevante está marcada com uma destas etiquetas, como você pediu:

| Etiqueta | Significado |
|---|---|
| **[ML]** | Regra confirmada em documentação oficial do Mercado Livre. Vem acompanhada do código da fonte (`S1`…`S20`, ver `15-fontes.md`). Quando a página é de outro site do ML (Argentina, Global Selling), isso é dito. |
| **[MAT]** | Regra ou comportamento observado nos seus materiais (prints do Seller Center de 01/10/2026). |
| **[ARQ]** | Decisão de arquitetura proposta nesta especificação. Pode ser mudada, mas a mudança precisa ser consciente. |
| **[HIP]** | Hipótese não confirmada. **Não implementar como regra fixa**: implementar de forma configurável e validar contra a API real (ver `12-hipoteses-e-pendencias.md`). |

---

## Três avisos que mudam a implementação

1. **O ML tem dois modelos de publicação de variações, e a conta decide qual usar.**
   - *Legado:* um anúncio com array `variations[]` e o **mesmo preço** em todas as variações.
   - *User Products / "preço por variação":* cada variação é um **anúncio separado**, agrupado por `family_name`, com preço, estoque e fotos próprios.

   A conta indica o modelo pela tag `user_product_seller` em `GET /users/{id}` **[ML·S8]**. O modelo interno do Publicador tem que ser o mesmo para os dois, e só o montador de payload muda. Ver `01-visao-geral.md`.

2. **Parte da documentação oficial é antiga (2022–2024) e o ambiente de pesquisa não conseguiu chamar a API real.** Por isso o arquivo `12-hipoteses-e-pendencias.md` define uma **Fase 0 — Sondagem**: antes de codar as regras, rodar chamadas reais em 3 categorias e na conta do vendedor e salvar as respostas como fixtures de teste.

3. **Nada de formulário fixo.** O formulário é gerado a partir do *CategorySchema* (configurações, atributos com tags e `technical_specs` da categoria). Nenhum atributo de categoria deve existir como coluna no banco.

---

## Sugestão de fatiamento (prazo 20/10/2026)

| Fase | Escopo |
|---|---|
| **0 — Sondagem** (1–2 dias) | Fixtures reais; resolver as hipóteses H-01 a H-08. |
| **1 — Núcleo** | Conta e modelo; categoria e schema; atributos dinâmicos com N/A e condicionais; variações (0–N eixos) e combinações; imagens com grupos; condições de venda (Clássico/Premium, ME2, garantia); validação em 3 camadas; publicação nos dois modelos; tratamento de erros e falha parcial. |
| **2 — Extensões** | Catálogo (`catalog_product_id`); tabela de medidas (moda); preços de atacado; compatibilidades (autopeças); edição de anúncios já publicados. |

Na Fase 1, categorias que **exigem** tabela de medidas ou catálogo devem ser **detectadas e bloqueadas com mensagem clara**, em vez de gerar um erro genérico da API.
