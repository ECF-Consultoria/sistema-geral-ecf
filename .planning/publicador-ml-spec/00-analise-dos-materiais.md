# 00 — Análise dos materiais enviados

## 1. Inventário

| ID | Material | O que explica | Valor para o desenvolvimento |
|---|---|---|---|
| M1 | Prompt/briefing (texto) | Problemas atuais, escopo, formato da entrega | Requisitos. Define a separação das 4 etiquetas e a lista de casos de teste obrigatórios. |
| M2 | Print "Etapa 1 de 3" — busca no catálogo (palavras-chave / foto / código) | A publicação começa tentando casar o produto com o **catálogo** do ML. O dropdown sugere produtos de catálogo. | Médio. Mostra que catálogo vem antes de categoria no fluxo oficial. |
| M3 | Print "Etapa 1 de 3" — Confirme a categoria (Cadeiras para Escritório) | Categoria confirmada com caminho completo (*Indústria e Comércio > Equipamento para Escritórios > Mesas e Cadeiras > Cadeiras para Escritório*). Aviso regulatório específico da categoria (NR do MTE). Texto: "você precisará preencher todas as características por conta própria". | **Alto.** Mostra que (a) a categoria é confirmada pelo usuário, não só prevista; (b) categorias carregam avisos/restrições; (c) sem produto de catálogo, todos os atributos ficam com o vendedor. |
| M4 | Print do bloco Título | Contador 18/60, "Sugerir um título", proibição de dados de contato e condições de venda no título | Alto. Confirma o limite de 60 caracteres nessa categoria e as regras de conteúdo. |
| M5 | PDF "Etapa 2" (5 páginas) | Estrutura do formulário de dados do produto (detalhada abaixo) | **Referência principal de UX.** |
| M6 | PDF "Etapa 3" (2 páginas) | Condições de venda: preço, atacado, tipo de anúncio, envio, retirada, garantia, resumo de tarifas | Alto. Define a etapa comercial e o preview de "Você recebe". |

### Detalhe do M5 (Etapa 2), na ordem da tela

1. **Características principais**: Marca (obrigatório, com dica "Informe a marca verdadeira ou 'Genérica'") e Modelo (obrigatório). Há "Sugerir características" e "Desfazer sugestões".
2. **Condição**: Novo / Usado / Recondicionado.
3. **Variações e fotos**: mesmo sem variações, aparece **um card de variação** "Azul / Couro" com foto, Estoque (1), Código universal (7896553367645) e SKU (CAD). Depois vem o botão "+ Adicionar variações".
4. **Título**, abaixo das variações.
5. **Informação regulatória**: "Número de registro/certificação INMETRO", preenchido com "Não se aplica".
6. **Características secundárias**:
   - Numéricos com unidade obrigatórios: altura do encosto, profundidade do assento, largura e altura máxima (cm).
   - Booleanos obrigatórios: requer montagem, é gamer, é ergonômica, é giratória, inclui manual.
   - Opcionais, cada um com checkbox "Não se aplica": tipo de cadeira; materiais da estrutura, do enchimento e do aro; peso máximo (kg); vários booleanos "Com …".
   - Formato de venda: Unidade.
7. **Descrição** (opcional).

### Detalhe do M6 (Etapa 3)

| Campo | Valor no print |
|---|---|
| Preço | R$ 150 (um único campo) |
| Preços de atacado | "Exclusivo negócios" |
| Tipo de anúncio | Clássico, tarifa R$ 16,50 |
| Forma de entrega | Envios no Mercado Livre; "Você oferece frete grátis"; custo R$ 124,70 com 50% de desconto por reputação = R$ 62,35 |
| Retirar pessoalmente | Ofereço / Não ofereço |
| Garantia | Do vendedor (30 dias) / de fábrica / sem garantia |
| Resumo | "Você recebe R$ 71,15 (47,43%)" |

---

## 2. Regras importantes extraídas dos materiais

| # | Regra observada | Etiqueta | Status após pesquisa |
|---|---|---|---|
| 1 | O fluxo oficial tem 3 etapas: **identificação (catálogo/categoria) → dados do produto → condições de venda**. | [MAT] | Coerente com a API: categoria e atributos primeiro; preço, tipo e envio são campos do item. |
| 2 | Marca é obrigatória; sem marca, usar "Genérica". | [MAT] | **Confirmado** [ML·S5/S10]. |
| 3 | O formulário de atributos se divide em **grupos** (principais, regulatória, secundárias). | [MAT] | **Confirmado**: espelha `technical_specs/input` (grupos → componentes → atributos) [ML·S3]. |
| 4 | Atributos não obrigatórios têm "Não se aplica". | [MAT] | **Confirmado**: `value_id: "-1"`, `value_name: null` [ML·S3]. Eixos de variação não aceitam N/A. |
| 5 | Atributos numéricos têm seletor de unidade (cm, kg). | [MAT] | **Confirmado**: `value_type: number_unit`, unidades em `allowed_units` [ML·S3]. |
| 6 | Estoque, código universal (GTIN) e SKU ficam **no card da variação**, não no produto. | [MAT] | **Confirmado**: SELLER_SKU e GTIN são `variation_attribute` [ML·S7]. |
| 7 | Mesmo sem variações, o produto aparece como **uma variação**. | [MAT] | Coerente com o modelo **User Products** (cada variação é uma unidade própria) [ML·S8]. Indica que esta conta provavelmente já está nesse modelo (**H-01**). |
| 8 | Título com máximo de 60 caracteres, sem contato nem condições de venda. | [MAT] | **Confirmado**: `max_title_length` vem da categoria; o conteúdo proibido também está documentado [ML·S2/S10]. |
| 9 | Condição tem 3 opções na UI. | [MAT] | **Diferença**: na API, `condition` aceita `new`/`used`/`not_specified`; "Recondicionado" é o atributo `ITEM_CONDITION` [ML·S10]. |
| 10 | Garantia tem 3 opções; a do vendedor tem prazo e unidade. | [MAT] | **Confirmado**: `sale_terms` WARRANTY_TYPE + WARRANTY_TIME [ML·S10]. O id de "Sem garantia" é **H-09**. |
| 11 | Tarifa e custo de envio dependem de preço, tipo, categoria e reputação. | [MAT] | **Confirmado**: `listing_prices` [ML·S14] e `shipping_options/free` [ML·S15]. Nunca fixar percentuais no código. |
| 12 | A R$ 150, o frete grátis aparece como obrigatório. | [MAT] | Coerente com o frete grátis obrigatório acima de um limite (tag `mandatory_free_shipping`) [ML·S15]. O valor do limite (R$ 79) vem de fonte não oficial (**H-10**). |
| 13 | A categoria exibe aviso legal (NR do MTE). | [MAT] | **Não encontrado na API** (**H-11**). Tratar como conteúdo informativo opcional. |
| 14 | "Rascunho salvo" aparece em todas as etapas. | [MAT] | Decisão [ARQ]: o Publicador persiste rascunhos por etapa (ver `04-modelo-de-dados.md`). |

---

## 3. Informações repetidas

- O "Resumo estimado das tarifas" aparece duas vezes no PDF da Etapa 3 (painel fixo). É o mesmo dado; basta uma fonte de cálculo.
- "Sugerir características" e "Desfazer sugestões" aparecem nos dois grupos de características. É o mesmo mecanismo (preenchimento por inferência), que na API corresponde aos `attributes` devolvidos pelo `domain_discovery` [ML·S1].

## 4. Conflitos e inconsistências

| Conflito | Onde | Resolução / referência |
|---|---|---|
| **Dados de exemplo implausíveis**: altura do encosto 23 cm, altura máxima da cadeira 22 cm, profundidade do assento 23 cm. A altura máxima ficou **menor** que o encosto. | M5 | O ML aceita (valida só tipo e unidade). [ARQ]: o Publicador pode ter **avisos de plausibilidade opcionais** por categoria, nunca bloqueantes. São dados de teste e não devem virar fixtures "corretas". |
| UI "Condição: Recondicionado" × API (`condition` + `ITEM_CONDITION`) | M5 × S10 | **A API é a referência.** A UI pode manter 3 opções; o montador de payload traduz. |
| Preço único na Etapa 3 × "preço por variação" do modelo User Products | M6 × S8 | Nos dois modelos o preço é armazenado **por variação** [ARQ]. No legado, a validação exige que todos sejam iguais [ML·S7]. No UP, a UI pode oferecer "aplicar a todos". O print só tem uma variação, então não dá para concluir como o Seller Center trata vários preços. |
| Título digitado pelo vendedor × modelo UP, em que o ML **gera** o título a partir do `family_name` | M4 × S8 | Fontes oficiais divergentes (ver **H-02**). [ARQ]: no modelo UP, o campo "Título" da UI alimenta o `family_name`. |
| Ordem da UI (Variações antes do Título) × ordem lógica de dependências | M5 | A UI do ML é uma página longa com blocos independentes. Nosso fluxo é um **grafo de dependências** (ver `02-fluxo-de-publicacao.md`). O título pode ser editado em qualquer momento depois da categoria. |
| Limites de fotos: exemplos oficiais dizem "12 por item / 10 por variação" e também "30 / 6" | S10 × S2 | **Ler sempre de `GET /categories/{id}`**: `max_pictures_per_item` e `max_pictures_per_item_var` [ML·S2]. Nunca fixar no código. |

## 5. Informações que faltam nos materiais

| Falta | Por que importa | Como suprir |
|---|---|---|
| Print do modal "Adicionar variações" com 2+ eixos | É o problema central do sistema atual | Capturar no Seller Center: adicionar Cor (3 valores) e um segundo eixo, mostrando grade e fotos por cor. |
| Exemplo com **fotos diferentes por cor** | Valida a regra `defines_picture` | Mesmo print acima. |
| Respostas reais da API (JSON) | O schema dinâmico depende delas | Fase 0 de `12-hipoteses-e-pendencias.md`. |
| Dimensões e peso da embalagem | Podem ser obrigatórios para ME2 (**H-05**) | Não aparecem nos prints; validar via `/items/validate`. |
| Exemplos de erro reais | Calibrar o mapeamento de erros | Capturar respostas 400 durante a Fase 0. |
| Descrição do sistema atual | Saber o que reaproveitar | Fora do escopo desta especificação, de propósito (regra principal). |
| Categoria com tabela de medidas (moda) e com compatibilidade (autopeças) | São estruturas de categoria muito diferentes | Incluir 1 categoria de cada na Fase 0. |

## 6. Hierarquia de referência

1. **API real do ML (respostas da Fase 0)**: verdade operacional.
2. **Documentação oficial do ML** [ML]: a mais recente quando houver conflito entre páginas.
3. **Prints do Seller Center** [MAT]: referência de **UX e ordem de apresentação**, não de regra técnica. O Seller Center é uma interface própria do ML e pode ter regras que a API não expõe, e vice-versa.
4. **Fontes não oficiais** (integradores, imprensa): só como indício, sempre marcadas [HIP].
