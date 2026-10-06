# Fase 168: Geração de ofertas a partir dos produtos (Combo, Kit e Combit sugeridos) — Pesquisa

**Pesquisado em:** 2026-10-06 (worktree `C:/tmp/ecf-publicador-spec-261001`, branch `feat/publicador-ml-261001`; a Fase 167 está em produção desde 06/10)
**Domínio:** Portal do Cliente (Laravel 12 + Inertia + React) — gerador de sugestões sobre o catálogo da 167 (`estrutura_produtos` → variações → ofertas simples ligadas), criação de ofertas pelo `EstruturaOfertaService::criar()`, logística e frete do conjunto.
**Confiança geral:** ALTA para o que foi medido (planilha real, só contagens) e lido no código; MÉDIA para os padrões de SKU de Kit/Combit (a planilha não tem convenção própria) e para a lista-semente de pares de tipo (precisa de aprovação do usuário).

> **Regra de dados desta pesquisa.** A planilha `3Planejamento_Estrutural_ECF.xlsx` é catálogo e custo REAIS de um cliente. Tudo abaixo vem de um script em memória, fora do repositório (no scratchpad, já apagado), e este documento traz **só contagens e formas genéricas**: nenhum nome de produto, SKU ou custo. As palavras de tipo citadas (cadeira, mesa, banco, banqueta, cama) são as do próprio 168-CONTEXT. Os testes da fase devem usar fixture **sintética**; o arquivo real nunca entra no repositório.

<user_constraints>
## User Constraints (de 168-CONTEXT.md)

### Locked Decisions

**O sistema sugere, a pessoa decide**
- **D-01:** Nada vira oferta sozinho. O gerador produz SUGESTÕES. A pessoa aceita uma a uma ou marca várias e aceita juntas. Aceitar cria a oferta pelo mesmo caminho da Lista SKUs (`EstruturaOfertaService::criar`, com a regra de composição dela). Descartar tira a sugestão da lista e ela **não volta** na próxima geração.
- **D-02:** Mora no Mapeamento Estrutural do Portal, com a mesma porta de Produtos e Lista SKUs: o cliente, e a equipe pela entrada de equipe no portal (como o D-02 da 167). Há entrada pela tela de Produtos e pela Lista SKUs. O nome e a posição exata da tela são da UI-SPEC.
- **D-03:** Não duplicar. Se já existe oferta com a MESMA composição (mesmos componentes e mesmas quantidades), inclusive feita à mão na Lista SKUs, ela não é sugerida. Gerar de novo não duplica sugestão pendente.
- **D-04:** As ofertas que já existem não são tocadas, e as do cliente nunca são alteradas pelo gerador (como o D-09 da 167).

**Quais combinações (decisão do usuário em 06/10 — "faça o recomendado")**
- **D-05:** Regras duras, medidas na planilha: Kit e Combit nunca misturam família (0 das 83 composições da planilha misturam). Kit e Combit exigem pelo menos um ambiente em comum (82 de 83 dividem). Produto sem família não entra em Kit nem Combit; continua podendo ter Combo.
- **D-06:** Pares que fazem sentido = uma lista de pares de TIPO de produto (mesa + cadeira, mesa + banco, cama + criado-mudo...). Só pares da lista geram Kit e Combit. A equipe ainda revisa antes de aceitar (D-01). A lista é da ECF, global para todas as empresas, porque móveis repetem os mesmos tipos. Ela nasce com os pares que a planilha real usou e cresce quando a ECF acrescenta pares. Sem IA nesta fase. Motivo: família + ambiente permitem 105 pares na planilha, e o Emerson usou 43. Uma lista curta é previsível e explicável ("sugeri porque mesa + cadeira está na lista").
- **D-07:** Quantidades por TIPO, editáveis por produto. Cada tipo tem um padrão: cadeira 2/4/6, banqueta 2/3/4, mesa só 1 (mesa não tem Combo). O padrão vale para Combo (×2, ×4, ×6) e para o item repetido do Combit (mesa + 4 cadeiras, mesa + 6 cadeiras). O produto pode ter as próprias quantidades, que valem no lugar do padrão. Origem: a reunião citou atacado com "fit comercial" (2/4/6/8/10 cadeiras, nunca 5) e combos CB2…CB6 na planilha.

**Cada sugestão**
- **D-08:** Mostra, antes de aceitar: a composição (produto × quantidade); o nome e o SKU sugeridos, editáveis; a família e o ambiente que justificam; a logística provável (ME1/ME2/ME2·Full) e o frete estimado. A logística e o frete do conjunto saem dos volumes de todos os componentes × quantidade, pelo `LogisticaProduto` e pela tabela de frete da ECF da 167. Nada disso é gravado como preço (D-19 da 167).
- **D-09:** Os componentes são as ofertas Simples ligadas às variações (167). Uma sugestão só usa variação que já tem oferta simples. Excluir variação que é componente segue a regra que já existe: a exclusão é recusada com o nome do combo.

**Dados novos**
- **D-10:** O "tipo de produto" é dado novo. A pesquisa decide a fonte, com estas opções: derivar da categoria do ML (167) com um nome amigável; um campo "Tipo" com lista fechada da ECF, sugerido pela categoria ou pelo nome e editável. Exigência das duas: o cliente não pode ter trabalho extra para a maioria dos produtos, e produto sem tipo não entra em Kit nem Combit. Se a decisão acrescentar campo à ficha da 167, que o usuário aprovou, a mudança visual vai para conferência dele.
- **D-11:** Migration só ADITIVA: tabelas novas para pares, quantidades por tipo e sugestões descartadas; coluna nullable em tabela da 167 se precisar; idempotente (`hasTable`/`hasColumn`), no padrão das migrations da 167; `estrutura_ofertas` não é alterada.

### Claude's Discretion
- Estrutura interna do gerador, desde que seja testável sem banco (regra pura, como o `LogisticaProduto`).
- Como guardar as sugestões: calculadas na hora ou persistidas. Só o descarte precisa persistir (D-01).
- Paginação e agrupamento da tela (por produto ou por família), seguindo a UI-SPEC.
- Onde a ECF edita a lista de pares e as quantidades por tipo: tela simples para admin ou seed + config. A edição pelo cliente não entra nesta fase.

### Deferred Ideas (OUT OF SCOPE)
- IA para decidir combinações fora da lista de pares.
- Grade de margem 30/20/10/0, tarifa por categoria e "qual promoção dá mais lucro" (tensão com o D-11 da 166).
- Cronograma por capacidade do publicador, gatilhos pós-publicação e prioridade por vendas.
- O cliente editar a lista de pares e as quantidades por tipo.
</user_constraints>

<phase_requirements>
## Requisitos da Fase (definidos nesta pesquisa — o ROADMAP diz "a definir")

| ID | Descrição | Decisões | O que na pesquisa dá suporte |
|----|-----------|----------|------------------------------|
| PR168-01 | **Gerador puro** (`GeradorDeSugestoes`): recebe um retrato em arrays (produtos, variações, ofertas simples ligadas, tipos, pares, quantidades, composições existentes, descartadas) e devolve sugestões Combo/Kit/Combit, sem banco, sem `config()` e sem relógio, ordenadas de forma estável. Só usa variação que já tem oferta simples ligada | D-01, D-09, Discricionário | §Padrão 1, §Gabarito |
| PR168-02 | **Regras duras**: Kit/Combit só entre produtos da MESMA família (não nula) e com ≥ 1 ambiente em comum; produto sem família ou sem tipo não entra em Kit/Combit mas pode ter Combo; só pares da lista geram Kit/Combit; só 2 componentes na v1 | D-05, D-06, D-10 | §Q4 (trios), §Gabarito |
| PR168-03 | **Variações**: casar variação paralela (mesmo `eixo`+`valor` quando os dois lados têm; senão mesma `ordem`); produto com 1 variação casa com todas as do outro; **nunca** produto cartesiano | D-09 | §Q2 |
| PR168-04 | **Tipos, pares e quantidades da ECF** em tabelas globais (`estrutura_tipos_produto`, `estrutura_tipo_pares`) com semente idempotente: nome, plural, palavras-chave, quantidades de Combo e de Combit por tipo; par com direção (qual lado se repete no Combit) | D-06, D-07, D-11 | §Persistência, §Q1 |
| PR168-05 | **Tipo do produto**: lista fechada da ECF, **inferida** por palavra-chave sobre `categoria_ml_nome` (depois `nome`) e **sobrescrevível** por produto, junto com as quantidades do próprio produto, numa tabela 1:1 nova (`estrutura_produto_geracao`) sem ALTER na 167. Produto sem tipo aparece num painel "sem tipo" para a pessoa escolher (cliente ou equipe), sem tocar na ficha da 167 | D-07, D-10 | §D-10, §Q5 |
| PR168-06 | **Nada duplicado**: chave canônica da composição (`v{variacao}*{qtd}+...`, ordenada); não sugere composição que já existe como oferta (feita à mão ou aceita antes), nem a que está descartada; gerar de novo é idempotente | D-03, D-01, D-04 | §Nada duplicado |
| PR168-07 | **Descartar e restaurar**: descarte persiste em `estrutura_sugestoes_descartadas` (única por empresa+chave) e a sugestão não volta; há como ver as descartadas e restaurar uma | D-01, D-11 | §Persistência |
| PR168-08 | **Aceitar** (uma ou várias): reconstrói a sugestão no servidor a partir da chave (nunca confia na composição vinda do navegador), cria a oferta por `EstruturaOfertaService::criar(..., varrerEspera: false)` com nome e SKU editados, uma transação por sugestão, varredura da espera uma vez no fim, lote ≤ 100, erro numa não impede as outras; ator `cliente`/`interno` no activity log; não grava `logistica` nem preço na oferta | D-01, D-04, D-08 | §Aceitar |
| PR168-09 | **Nome e SKU sugeridos**: Combo `-CB{n}` (padrão que o `criarCombos` já usa) e nome no padrão da planilha ("Kit {n} {Plural} {resto}"); Kit `{A} + {B}`; Combit `{fixo} + {n} {Plural}`; SKU > 120 caracteres bloqueia o aceite até editar; título > 60 caracteres avisa | D-08 | §Q3 |
| PR168-10 | **Logística e frete do conjunto**: volumes de todos os componentes repetidos pela quantidade → `LogisticaProduto::daVolumes`; frete pela tabela da ECF via `FreteMe2Service::estimar` (cotação real só sob demanda, na página visível); componente sem medida → "Pendente"; nada gravado como preço | D-08 | §Logística do conjunto |
| PR168-11 | **Tela de revisão** (cartões/lista, **não** grade de planilha): painel sobre o conjunto inteiro, paginação e filtros no servidor (família, fase, tipo, busca), "marcar várias e aceitar", editar nome/SKU antes de aceitar, mostrar composição, família/ambiente que justificam, logística e frete; entradas em Produtos e na Lista SKUs | D-01, D-02, D-08 | §Volume, §Padrão 3 |
| PR168-12 | **Acesso**: rotas no grupo `portal.auth` (cliente e equipe), empresa só do `PortalContexto`, `whereNumber`, throttle com prefixo próprio por rota, uma linha por rota em `RestringeDominioDoPortal::PERMITIDO`, id de outra empresa = 404, escrita grava `origem` | D-02 | §Acesso |
| PR168-13 | **Migrations só aditivas e idempotentes** (`hasTable`/`hasIndex`/`hasForeignKey`, `emMysql()`, sem `enum`/`json`, índices curtos), nenhum ALTER em `estrutura_ofertas` nem em tabela da 167; semente por `insertOrIgnore`; verificada no MariaDB 10.4 com `--path`; entra em `MigracoesDaFaseDetectamMariaDbTest` | D-11 | §Migration |
| PR168-14 | **Manutenção pela ECF** (admin, `role:admin`, fora do Portal): tela simples para tipos (nome, plural, palavras, quantidades) e pares (direção); o cliente não edita | D-06, D-07 | §Persistência |
| PR168-15 | **Gabarito**: fixture sintética com a mesma forma da planilha medida (família-polo com muitos produtos, famílias de 1 produto, grupos de 2 variações, par com ambiente sem interseção) e teste dos números; mais um **roteiro local fora do repo** que roda o gerador na planilha real e imprime só contagens (meta: ≥ 106 acertos de 129, ver §Gabarito) | todas | §Gabarito, §Validation Architecture |
</phase_requirements>

## Project Constraints (de CLAUDE.md)

- **Idioma:** artefatos GSD, comentários e commits em **pt-BR**; identificadores e termos técnicos consagrados como estão.
- **Trabalho direto vs GSD:** esta fase não toca `DesempenhoScoreService`, `*_snapshots` nem altera tabela com dado em produção (só cria tabelas), então **não** cai no GSD obrigatório do CLAUDE.md; as três disciplinas valem: perguntar antes (ver Questões Abertas), **decisão de schema por escrito** (§Migration) e **teste no mesmo commit**.
- **Stack travada:** Laravel 12 + Inertia + React 18, Tailwind `ecf-*`, `cn()`.
- **Commits:** `git commit -- <caminhos>`, nunca `git add -A`; conferir `git show <sha>` antes do push (o commit por caminho já arrastou arquivo de outra sessão).
- **`npm run build`** ao fim de qualquer alteração de frontend; em worktree, conferir que a página nova entrou em `public/build/manifest.json` (re-export puro some do manifest; `npm run build` sai 0 sem buildar em worktree novo).
- **Armadilhas de MariaDB** (`desempenho-bonificacao.md` §6): índice > 64 caracteres (1059), `nullOnDelete` só em coluna nullable (1830), não dropar índice usado por FK (1553), nada de `enum`.
- **Nenhum deploy sem autorização.** **Nenhuma escrita na conta ML de cliente** (só GET; conta de cliente nunca publica — só a #459). **Não acessar produção. Não rodar `migrate` sem `--path` no MariaDB local compartilhado.** Não usar `gsd-sdk query state.*`.
- **Sem planilha dentro do sistema no cadastro** (learnings `portal-do-cliente.md` §31, D-23 da 167): o usuário reprovou grade tipo planilha; a tela desta fase é de cartões/lista e botões, não `SpreadsheetGrid`.
- **Voltar do navegador:** se a tela tiver nome/SKU editado e não salvo, usar `resources/js/lib/guardaDoVoltar.js` (learnings §32).

## Resumo

A fase é **majoritariamente regra pura mais uma tela**. O gerador mora numa classe PHP sem banco (como `LogisticaProduto`), recebe um retrato do catálogo em arrays e devolve as sugestões; só o **descarte** persiste. As sugestões são calculadas na hora e paginadas no servidor; a logística e o frete saem só da página visível. As aceitas passam por `EstruturaOfertaService::criar()`, que já faz a regra de composição, a varredura da espera e o log. Não há nenhum ALTER: tudo é tabela nova (tipos, pares, ajustes 1:1 por produto, descartadas). **O medido na planilha real muda duas coisas do desenho** em relação ao CONTEXT: (1) a coluna "Tipo" da planilha é o tipo de **oferta** (Simples/Combo/…); não existe tipo de produto, ele precisa ser inferido; (2) o par de tipo precisa de **direção** no Combit (quem se repete), senão os Combits sobram 4 a 6 vezes.

**Medido (só contagens).** 70 variações em 56 produtos (grupos), 22 famílias; 83 composições de Kit/Combit, das quais 62 têm 2 itens e 21 têm 3 (teto de 3 da planilha). Os 44 pares de produto distintos usados viram **21 pares de tipo** (tipo = 1ª palavra do nome) ou **23** (tipo = categoria): a lista curta funciona. Kit/Combit casam variações **em paralelo** (a mesma posição), nunca em produto cartesiano. Aplicando D-05..D-07 com a lista de pares semeada e o Combit dirigido, o gerador propõe **171 sugestões** (52 Combo, 91 Kit, 28 Combit) e **reproduz 106 das 129** ofertas de Combo/Kit/Combit da planilha (82%); as 23 restantes são as 21 ofertas de 3 itens (fora da v1 de propósito: reproduzi-las exigiria propor 124 trios para acertar 12) e 2 que dependem da exceção de ambiente. Com as quantidades **literais do D-07** o acerto cai para 25/46 Combos e 10/25 Combits, porque a planilha também usa cadeira ×8, banco ×2/×4 e outros tipos ×2: isso é decisão do usuário (Questão Aberta 1), não do planejador.

**Recomendação principal:** gerador puro + tabelas globais `estrutura_tipos_produto` e `estrutura_tipo_pares` (semeadas por migration, mantidas por uma tela admin pequena) + tabela 1:1 `estrutura_produto_geracao` para o tipo e as quantidades do próprio produto + `estrutura_sugestoes_descartadas`; tipo inferido por palavra-chave sobre a categoria (79% dos produtos sem trabalho nenhum do cliente, com um vocabulário genérico de 36 palavras) e corrigível na própria tela de sugestões, **sem mexer na ficha da 167**.

## Respostas às 5 perguntas abertas do CONTEXT (medidas na planilha real)

### Pergunta 1 — Quantos tipos e quantos pares de tipo?

**Medido** [VERIFIED: leitura local da planilha, abas Planejamento e Produtos]:

| Item | Resultado |
|------|-----------|
| Variações / produtos (grupos) / famílias | 70 / 56 / 22 (14 famílias têm 1 produto só e não geram Kit; a maior tem 14 produtos; 8 famílias têm ≥ 2) |
| Coluna "Tipo" da planilha | É o tipo de **oferta** (Simples 70, Combo 46, Kit 44, Combit 39). **Não existe tipo de produto na planilha.** |
| Tipos distintos nos 56 produtos | **20** se tipo = 1ª palavra do nome; **24** se tipo = texto da categoria |
| Pares de produto distintos em Kit/Combit | **44** (43 passam em D-05; 1 não tem ambiente em comum) — 35 em ofertas de 2 itens, 9 só dentro de trios |
| → Pares de TIPO (todas as 83 composições) | **21** (por nome) / **23** (por categoria) |
| → Pares de TIPO só nas ofertas de 2 itens | 16 / 18 |
| Pares do mesmo tipo (ex.: duas camas) | 1 (por categoria) a 2 (por nome) |
| Pares de Combit com direção (fixo, repetido) | 7 |
| Pares de produto elegíveis só por D-05 (sem lista de tipos) | 105 (confere com o CONTEXT) |

**Conclusão:** D-06 está validado. 44 pares de produto se reduzem a ~21–23 pares de tipo, e a lista curta cobre tudo que a planilha usou. Dois achados que o D-06 não previa: (a) **o Combit precisa de direção** (7 pares dirigidos): sem ela, o mesmo par gera o item repetido nos dois lados e os Combits sobram 4 a 6 vezes (162 gerados contra 25 da planilha); (b) **91% das sobras de Kit (32 de 35 pares de produto a mais) concentram-se em 4 pares de tipo de uma única família de 14 produtos**, três deles envolvendo cama. A lista de pares sozinha não separa "cama de solteiro" de "cama de casal"; a revisão humana (D-01) é a rede, e a tela precisa **agrupar por família** para a pessoa decidir em bloco.

### Pergunta 2 — A planilha cruza variações em Kit e Combit?

**Não cruza: casa em paralelo.** [VERIFIED: leitura local]

- 83 composições: **nenhuma** junta duas variações do mesmo produto na mesma oferta; 82 têm todas as variações com o mesmo ordinal (a 83ª é produto de 1 variação com produto de 2).
- 44 pares de produtos: **30** são 1×1 (1 combinação usada de 1 possível), **13** são 2×2 com **2 das 4** combinações usadas (a posição 1 com a 1, a 2 com a 2; **0** de 13 usam 1 com 2) e **1** é 1×2 (usa as 2). Total: 58 combinações de variação usadas de 84 possíveis no produto cartesiano.
- A planilha só tem o **ordinal** (`1`, `2`, `3`, `única`), não "Cor = Natural" (167-RESEARCH). Logo o paralelismo é por posição. O modelo da 167 tem `eixo`+`valor` opcionais por variação: usar quando os dois lados têm.

**Regra do gerador (PR168-03):** casar por `(eixo, valor)` iguais quando ambos têm; senão por `ordem`; produto com 1 variação casa com todas as variações do outro; sobra sem par é ignorada. Nunca produto cartesiano. Com isso o gerador reproduz 36 dos 37 Kits de 2 itens e 24 dos 25 Combits de 2 itens (as exceções são o par sem ambiente em comum).

### Pergunta 3 — Padrões de nome e SKU

**Medido** [VERIFIED: leitura local]:

- **SKU da planilha** é `A{n}-V{k}` (código do anúncio + variação) em **todas** as fases (199/199), igual para Simples, Combo, Kit e Combit. Ela **não** usa sufixo `CB` na coluna de oferta; o "CB2…CB6" do CONTEXT é o padrão do **sistema** (`EstruturaOfertaService::criarCombos` gera `{sku}-CB{n}` e `"Combo {n} {Nome}"`). 155 códigos de anúncio para 199 ofertas (111 com 1 oferta, 44 com 2: o anúncio agrupa variações).
- **Título (coluna "Título sugerido")**:
  - Simples = nome do produto (70/70).
  - **Combo = "Kit {N} {Plural do tipo} {resto do nome}"** em 43 de 46; o resto do título é igual ao resto do nome em 42 de 46. **Nenhum** começa com "Combo {N}". Os combos do cliente usam a palavra "Kit" no título.
  - **Kit = "{nome A} + {nome B}"** (separador " + " em 44 de 44; nomes abreviados: 0 de 44 contêm o nome completo de todos os componentes; 75 de 95 partes são compatíveis).
  - **Combit = "{fixo} + {N} {Plural do tipo repetido}"** (ex.: "… + 4 Cadeiras"); o item repetido é o **último** componente em 31 de 39.
  - Quantidades do item repetido no Combit: 2 (26), 4 (7), 6 (8). Kit: 0 composições com quantidade ≠ 1.
  - Título > 60 caracteres em 4 de 44 Kits e 3 de 39 Combits (limite do ML = 60; `max_titulo` default 60 em `RascunhoAnuncioIaService`) [VERIFIED: codebase]. Combos: 0.
- **Combos por produto:** só 20 dos 70 SKUs têm combo (8 com 1 quantidade, 5 com 2, 7 com 4); quantidades 2 (20), 3 (3), 4 (9), 6 (7), **8 (7)**.

**Recomendação (PR168-09):**

| Fase | `nome` sugerido | SKU sugerido |
|------|-----------------|--------------|
| Combo | `Kit {n} {Plural} {resto do nome}` quando o produto tem tipo com plural e o nome começa pela palavra do tipo; senão `Combo {n} {nome}` (o que o `criarCombos` faz hoje) | `{sku da variação}-CB{n}` [VERIFIED: `criarCombos`] |
| Kit | `{nome A} + {nome B}` (tipo na ordem `ordem` do tipo; nome do produto **sem** o valor da variação; acrescenta ` — {valor}` só se as duas variações têm o mesmo valor) | `KT-{sku A}-{sku B}` [ASSUMED] |
| Combit | `{nome fixo} + {n} {Plural do repetido}` | `CT{n}-{sku fixo}-{sku repetido}` [ASSUMED] |

Todos editáveis antes de aceitar. SKU > 120 caracteres: o aceite é bloqueado até a pessoa editar (a regra de `campos()` do serviço). SKU igual ao de oferta existente **não** impede (a Lista SKUs já aceita SKU repetido e avisa, ADR PORTAL-01): o cartão mostra o aviso.

### Pergunta 4 — Gabarito: quantas das 46/44/39 as regras reproduzem?

[VERIFIED: simulação em memória sobre a planilha, só contagens]. Modelo simulado: família igual e não nula (normalizada sem caixa/acento) + ambiente em comum (ambiente da planilha quebrado por `/`, `,`, `;`, `|`) + par de tipo da lista + variações em paralelo; **componentes só de 2 itens** (21 das 83 composições têm 3 itens e ficam fora da v1).

Formato `[gerado, acerto, sobra, gabarito da planilha com 2 itens (Combo: 46)]`:

| Cenário | Combo | Kit | Combit | Total gerado / acertos |
|---------|-------|-----|--------|------------------------|
| **Tipo = categoria, lista-semente (23 pares), Combit dirigido, quantidades semeadas da planilha** (cenário recomendado) | 52 · 46 · 6 · 46 | 91 · 36 · 55 · 37 | 28 · 24 · 4 · 25 | **171 / 106** |
| Tipo = 1ª palavra do nome (21 pares), mesmo restante | 64 · 46 · 18 · 46 | 93 · 36 · 57 · 37 | 33 · 24 · 9 · 25 | 190 / 106 |
| Quantidades **literais do D-07** (cadeira 2/4/6, banqueta 2/3/4; demais tipos sem quantidade) | 27 · 25 · 2 · 46 | 91 · 36 · 55 · 37 | 15 · 10 · 5 · 25 | 133 / 71 |
| D-07 + cadeira 8 + banco 2/4 | 42 · 35 · 7 · 46 | 91 · 36 · 55 · 37 | 30 · 15 · 15 · 25 | 163 / 86 |
| Combit **sem direção** (repete os dois lados) | — | — | 140 a 162 · 24 · 116 a 138 | explosão |
| **Sem lista de pares** (só D-05 + tipo) | — | 136 · 36 · 100 | 168 a 198 · 24 | explosão |

Leitura:
- **106 de 129 (82%)** é o teto de um gerador de 2 itens com a lista-semente; as 23 que faltam são 21 de 3 itens + 2 por exceção de ambiente. Esse é o gabarito da fase (PR168-15).
- **Trios:** um gerador que também propusesse trios (3 pares na lista + ambiente comum aos 3) geraria **124 trios para acertar 12** (precisão ~10%). Fora da v1: a pessoa monta o trio à mão na Lista SKUs (o `criar()` aceita). Pergunta aberta 2.
- **Sobras de Kit (55)** não são erro: são pares válidos pela regra que o Emerson não usou, 32 de 35 em 4 pares de tipo de uma família. O D-01 (a pessoa decide) e o descarte permanente existem para isso. A tela deve agrupar por família.
- Para **cada 70 variações**, ~171 sugestões. Escala: o gerador só combina dentro da mesma família, então o custo é Σ(produtos por família)², e não N².

### Pergunta 5 — Fonte do tipo (D-10): categoria do ML

**Medido** [VERIFIED: leitura local + `EstruturaProduto::estadoCategoria()`]:

- **70 de 70** variações têm categoria **em texto**; **0 de 70** têm `category_id` (`MLB…`) e **0** têm caminho (`>`). Numa importação da 167 isso vira o estado `a_confirmar` (texto livre, "nunca vira id sozinho"). **Logo a inferência do tipo precisa funcionar sobre `categoria_ml_nome` em qualquer estado**, não só confirmada.
- 24 categorias distintas nos 56 produtos. Cada categoria tem **um só** tipo de nome (24 de 24 "puras"), mas um tipo se espalha em várias categorias: mesa em 4, cadeira em 2, banco em 2, rack em 2. A categoria separa bem cadeira, mesa, banqueta e banco: **cadeira 7/7, mesa 8/8, banqueta 1/1 e banco 2/3** (1 produto tem "banco" no nome e outra categoria); onde nome e categoria dão resultado **não há discordância (0)**.
- **Vocabulário genérico de 36 palavras** (montado do conhecimento de móveis, **não** treinado na planilha) sobre o texto da categoria: **44 de 56 produtos (79%)** recebem exatamente 1 tipo sem trabalho de ninguém; 1 recebe 2 e 11 nenhum. Usando o nome como segunda fonte, o vocabulário genérico não resgata nenhum dos 12 restantes. Dos 12 sem tipo, **7 participam de Kit/Combit na planilha** (7 de 38 produtos em composições). Com as palavras da própria planilha incorporadas à lista da ECF, a cobertura sobe (52 de 56 têm o radical do tipo no texto da categoria).

## Recomendação D-10 — fonte do "tipo de produto"

| Opção | Prós (medidos) | Contras (medidos) |
|-------|----------------|-------------------|
| **A. Tipo = categoria do ML** direto | Zero trabalho do cliente; a categoria é pura (24/24) e separa cadeira/mesa/banco/banqueta | **Em 70/70 a categoria é texto livre**, sem id; texto livre não é chave estável. Um tipo vira 2–4 categorias (mesa em 4), então a lista de pares passa a ter 23 em vez de 21 e cresce com **cada folha** do ML (centenas em Móveis), e o nome amigável ("Cadeiras" para o título "Kit 4 Cadeiras…") não existe |
| **B. Campo "Tipo" com lista fechada, sugerido por palavra-chave** (**recomendada**) | Vocabulário pequeno e da ECF (dezenas de tipos); pares e quantidades penduram no tipo; tem nome amigável e plural para os títulos; 79% automático com vocabulário genérico; funciona com categoria em texto ou confirmada, e cai no nome | Precisa de tabela e de um vocabulário mantido (admin); 21% sem tipo no primeiro dia (12 de 56) até a ECF ampliar as palavras ou a pessoa escolher |
| C. Tipo = 1ª palavra do nome | 56/56 recebem alguma coisa | Frágil (nome que começa por "Kit", "Conjunto", marca); 20 tipos soltos, sem plural nem quantidades; discordou da categoria em 4 de 56 por radical |

**Decisão recomendada: B, híbrido.** Tipo **efetivo** = `estrutura_produto_geracao.tipo_id` (escolha explícita da pessoa) senão **inferido** por palavras-chave do tipo sobre `categoria_ml_nome` e, na falta, sobre `nome`. Inferência ambígua (2 tipos) ou ausente = **sem tipo**: não entra em Kit/Combit, aparece no painel "Sem tipo" da tela de sugestões, e a pessoa escolhe um tipo da lista (cliente ou equipe). **Não acrescenta campo à ficha da 167**, então não há mudança visual na ficha aprovada para conferência; a escolha fica na tela de sugestões (e, se a UI-SPEC pedir, num seletor extra na ficha, aí sim indo para conferência do usuário). Quem **cria tipo novo** é só a ECF (global).

## Mapa de Responsabilidade por Camada

| Capacidade | Camada principal | Secundária | Justificativa |
|------------|------------------|------------|---------------|
| Regra de combinação (D-05..D-07), chave, nomes sugeridos | API / Backend (classe PHP pura) | — | Uma só implementação testável sem banco; a tela só exibe (PORTAL-02: duas cópias da conta já erraram preço) |
| Inferência do tipo, quantidades efetivas | Backend (classe pura) | Banco (tipos/palavras) | Mesma razão; o JS nunca reimplementa |
| Logística e frete do conjunto | Backend (`LogisticaProduto`, `FreteMe2Service::estimar`) | Cache | Já existem; só se concatenam volumes |
| Persistência (tipos, pares, ajustes, descartadas) | Banco | Backend (serviço em transação) | Só o descarte e as listas da ECF persistem; sugestão é calculada |
| Aceitar/descartar/restaurar | Backend (serviço) | — | `criar()` já faz composição, espera e log; o navegador manda só a chave |
| Revisão (cartões, filtros, marcar vários, editar nome/SKU) | Browser | Backend (paginação, painel) | Interação no navegador; contagens e página no servidor (learnings §25/§27) |
| Allowlist do domínio do cliente, throttle | Backend (middleware/rotas) | — | Rota nova nasce bloqueada no domínio do cliente se esquecida |
| Manutenção de tipos e pares | Backend + página admin | — | Lista global da ECF, `role:admin`, fora do Portal |

## Stack Padrão

### Núcleo (nenhuma dependência nova)

| Biblioteca / componente | Versão (lockfile/instalada) | Uso | Por que |
|-------------------------|-----------------------------|-----|---------|
| Laravel 12 / Eloquent / `DB::transaction` | `laravel/framework ^12.0` | serviço, models, transações | já é a stack |
| `EstruturaOfertaService::criar()` | local | cria a oferta aceita (composição, espera, log) | D-01: "mesmo caminho da Lista SKUs"; já tem `$varrerEspera=false` para lote (BE-WR-05) |
| `LogisticaProduto::daVolumes()` / `pacote()` | local | logística do conjunto | D-08 |
| `FreteMe2Service::estimar()` (e `cotar()` sob demanda) | local | frete estimado do conjunto pela tabela da ECF | D-08, mesma regra da 167 |
| `RegistroEstrutura::registrar()` | local | trilha com `origem` cliente/interno | padrão do Mapeamento |
| `EstruturaConjunto::daEmpresa()` | local | referência de leitura da empresa inteira + paginação só na entrega | mesmo padrão |
| React 18 + Inertia 2 + Radix + `cn()` | `package.json` | tela | stack travada |
| PHPUnit 11.5 (SQLite `:memory:`), `node:test` | `phpunit.xml`, `npm run test:js` | testes | já existem |

### Alternativas consideradas

| Em vez de | Poderia usar | Trade-off |
|-----------|--------------|-----------|
| Calcular as sugestões na hora | Persistir todas (tabela de sugestões) | Persistir exige invalidar a cada mudança de produto/variação/oferta/lista de pares (uma das maiores fontes de sugestão fantasma). Calcular custa poucos ms (combina só dentro da família). **Recomendado: calcular; persistir só o descarte** |
| Tipo = categoria | Campo "Tipo" da ECF | Ver §D-10 |
| `SpreadsheetGrid` na revisão | Cartões + lista | O usuário reprovou grade de planilha no cadastro (D-23 da 167); revisão é decisão item a item |

**Instalação:** nenhuma (`composer`/`npm` inalterados).

## Auditoria de Legitimidade de Pacotes

| Pacote | Registro | Idade | Downloads | Repo | slopcheck | Disposição |
|--------|----------|-------|-----------|------|-----------|------------|
| (nenhum pacote novo) | — | — | — | — | n/a | Aprovado — só código local e bibliotecas já em `composer.lock`/`package-lock.json` |

**Pacotes removidos por [SLOP]:** nenhum. **Pacotes [SUS]:** nenhum. slopcheck não foi executado porque a fase não instala pacote externo. Se o plano acrescentar dependência, gatear com `checkpoint:human-verify`.

## Padrões de Arquitetura

### Diagrama do fluxo

```
Cliente / Equipe ──► GET /portal/estrutura/sugestoes?familia=&fase=&tipo=&q=&pagina=   (Inertia, PortalClienteLayout)
        │                       │
        │                       ▼
        │         middleware portal.auth ─► PortalContexto::empresa()/ator()
        │                       │
        │                       ▼
        │         SugestoesService::listar(empresa, filtros)
        │            │ 1) RetratoDoCatalogo (3-4 consultas): produtos+familia+ambientes+variações+volumes,
        │            │    ofertas simples LIGADAS (variacao_id), ajustes 1:1, tipos, pares,
        │            │    composições existentes (componentes → variacao_id), descartadas
        │            ▼
        │         TipoDoProduto::efetivo()  ── override > palavra-chave(categoria_ml_nome > nome) > sem tipo
        │            ▼
        │         GeradorDeSugestoes::gerar(retrato)   [classe pura]
        │            │  Combo: variação × quantidades do tipo/produto
        │            │  Kit/Combit: pares de produto da MESMA família + ambiente em comum + par de tipo na lista
        │            │              + variações em paralelo; Combit só no lado dirigido
        │            │  remove: já existe (chave) · descartada (chave)
        │            ▼
        │         painel (contagens sobre o CONJUNTO) + filtro + página (20)  ◄── paginação no servidor
        │            ▼
        │         só a PÁGINA: volumes×qtd ─► LogisticaProduto::daVolumes ─► FreteMe2Service::estimar (cache many)
        │            ▼
        │         props: sugestões {chave, fase, itens[], nome, sku, familia, ambientes, logistica, frete, avisos[]}
        │
        ├─ POST /sugestoes/aceitar   {sugestoes:[{chave, nome, sku}]}  ≤100 ─► SugestoesService::aceitar
        │            │  gera de novo → só aceita chave presente · lock da empresa · re-checa duplicata
        │            │  por sugestão: DB::transaction → EstruturaOfertaService::criar(..., varrerEspera:false)
        │            │  fim: varrerEspera(skus) 1x · RegistroEstrutura 'sugestoes_aceitas'
        ├─ POST /sugestoes/descartar {chaves[]}  ─► estrutura_sugestoes_descartadas (unique empresa+chave)
        ├─ POST /sugestoes/restaurar {chaves[]}
        └─ PUT  /sugestoes/produtos/{produto}/geracao {tipo_id?, qtd_combo?, qtd_combit?}  ─► estrutura_produto_geracao

ECF (admin) ─► /dev/estrutura-geracao  (role:admin) ─► estrutura_tipos_produto · estrutura_tipo_pares
Aceita ─► estrutura_ofertas (fase combo/kit/combit + componentes) ─► Lista SKUs · Precificação (custo = soma dos componentes) · Publicador ("Sincronizar do Portal")
```

### Estrutura de arquivos recomendada

```
app/Services/Portal/Estrutura/Geracao/
   GeradorDeSugestoes.php        # PURO: retrato (arrays) → sugestões; sem Eloquent, sem config(), sem now()
   ChaveDeComposicao.php         # PURO: gerar/validar/ordenar "v12*1+v30*4"
   TipoDoProduto.php             # PURO: override > palavra-chave > null (normalização sem caixa/acento)
   Quantidades.php               # PURO: "2, 4, 6" ⇄ [2,4,6]; "0" = nenhuma; limites 2..999, ≤ 8 itens
   NomesSugeridos.php            # PURO: nome/SKU por fase (padrões da planilha), aviso >60 e >120
   VariacoesEmParalelo.php       # PURO: casa variações (eixo+valor, senão ordem, 1×N)
   ConjuntoLogistico.php         # volumes × quantidade → LogisticaProduto::daVolumes (fino, sem I/O)
   RetratoDoCatalogo.php         # I/O: monta o retrato da empresa (consultas escopadas por company_id)
   SugestoesService.php          # listar / aceitar / descartar / restaurar / definirGeracao (transações, log)
app/Models/EstruturaTipoProduto.php, EstruturaTipoPar.php, EstruturaProdutoGeracao.php, EstruturaSugestaoDescartada.php
app/Http/Controllers/PortalEstruturaSugestoesController.php   # NÃO engordar PortalEstruturaController (531 linhas) nem o dos Produtos
app/Http/Controllers/DevEstruturaGeracaoController.php        # admin: tipos e pares
config/estrutura_geracao.php     # catálogo-semente (tipos, palavras, pares, quantidades) + limites (página, lote, teto de sugestões)
database/migrations/2026_10_07_100000_create_estrutura_geracao_tables.php
database/migrations/2026_10_07_100100_semear_estrutura_tipos_e_pares.php
resources/js/Pages/Portal/EstruturaSugestoes.jsx
resources/js/Components/Portal/Estrutura/Sugestoes/   # CartaoSugestao, PainelSugestoes, JanelaTipo, ListaDescartadas
resources/js/Pages/Dev/EstruturaGeracao.jsx           # admin
resources/js/lib/sugestoesEstrutura.js                # só formatação/rótulos (NUNCA a regra de combinação)
```

### Padrão 1 — Gerador puro com retrato em arrays (PR168-01/02/03)

**O quê:** `GeradorDeSugestoes::gerar(array $retrato): array` com `$retrato = ['produtos' => [...], 'tipos' => [...], 'pares' => [...], 'existentes' => [chave => true], 'descartadas' => [chave => true]]`. Cada produto: `id, familia_id, ambiente_ids[], tipo_slug|null, qtd_combo?, qtd_combit?, variacoes[{id, ordem, eixo, valor, oferta_id, sku, volumes[]}], nome, categoria`. Devolve lista de `['chave','fase','itens'=>[['variacao_id','oferta_id','quantidade']], 'familia_id','ambiente_ids','pares_tipo','motivo']`, ordenada por `(familia, fase, chave)`.

**Passos:** (1) agrupar produtos por `familia_id` (família nula só Combo); (2) Combo: para cada variação com oferta simples ligada, `Quantidades` efetivas do produto (override) senão do tipo; uma sugestão por quantidade ≥ 2; (3) Kit/Combit: pares de produtos **dentro do bucket da família**, com `ambiente_ids` ∩ ≠ ∅, ambos com tipo, par de tipo na lista (não ordenado), `VariacoesEmParalelo`; Kit = ×1+×1; Combit = só se o par tem direção, `fixo×1 + repetido×q` para cada `q` de `qtd_combit` do **tipo/produto repetido**; (4) descartar quem já está em `existentes`/`descartadas`.

```php
// Esboço (comentários em pt-BR no código real). Fonte: medição desta pesquisa + EstruturaOfertaService::composicao.
public static function gerar(array $retrato): array
{
    $saida = [];
    foreach (self::porFamilia($retrato['produtos']) as $familiaId => $produtos) {
        foreach ($produtos as $p) {                                  // Combo (família pode ser nula)
            foreach (self::variacoesComOferta($p) as $v) {
                foreach (Quantidades::efetivas($p, 'combo', $retrato['tipos']) as $q) {
                    $saida[] = self::sugestao('combo', $familiaId, $p['ambiente_ids'], [[$v, $q]]);
                }
            }
        }
        if ($familiaId === null) { continue; }                       // D-05: sem família, sem Kit/Combit
        foreach (self::paresDoBucket($produtos) as [$a, $b]) {
            if (array_intersect($a['ambiente_ids'], $b['ambiente_ids']) === []) { continue; } // D-05
            $par = self::parDeTipo($retrato['pares'], $a['tipo_slug'], $b['tipo_slug']);      // D-06 (null = fora da lista)
            if ($par === null) { continue; }
            foreach (VariacoesEmParalelo::casar($a['variacoes'], $b['variacoes']) as [$va, $vb]) {
                $saida[] = self::sugestao('kit', $familiaId, /* ... */ [[$va, 1], [$vb, 1]]);
                foreach (self::repeticoes($par, $a, $b) as [$lado, $q]) {                      // Combit dirigido
                    $saida[] = self::sugestao('combit', $familiaId, /* ... */ self::itensCombit($va, $vb, $lado, $q));
                }
            }
        }
    }
    return self::semDuplicadas($saida, $retrato['existentes'], $retrato['descartadas']);
}
```

**Quando usar:** sempre; a lista, o aceite (que regera e confere a chave) e os testes usam a mesma função.

### Padrão 2 — Chave de composição única e estável (PR168-06/07)

`ChaveDeComposicao::de(array $itens)`: itens `[variacao_id => quantidade]` ordenados por `variacao_id`, formato `v12*1+v30*4`. **Por variação, não por oferta**: a oferta simples tem `variacao_id` único (167) e a variação é o que a pessoa enxerga; a chave sobrevive a oferta recriada. Composição existente vira chave pelos componentes → `oferta.variacao_id`; se algum componente não tem `variacao_id` (oferta antiga, sem produto) a composição existente **não é comparável** (nunca é igual a uma gerada, que só usa ofertas ligadas) e não entra no conjunto. Validar a chave recebida do navegador com regex `^v\d+\*\d+(\+v\d+\*\d+){0,2}$`.

### Padrão 3 — Tela: cartões e botões, painel sobre o conjunto, página no servidor (PR168-11)

Cartão por sugestão (composição "N× produto", nome e SKU editáveis, família e ambiente, selo ME1/ME2/ME2·Full, frete "estimado", avisos: SKU repetido, título > 60, componente sem medida/custo), agrupados por **família** com "marcar todas da família", barra fixa "Aceitar N" / "Descartar N". Contagens por fase e por família saem do conjunto inteiro; a lista é paginada no servidor (20/página). Aba/filtro "Descartadas" (restaurar) e painel "Sem tipo (N)" com seletor de tipo por produto. Seguir learnings §25 e §27: **nunca** filtrar no navegador sobre a página.

### Anti-padrões a evitar

- **Persistir todas as sugestões**: fica velha a cada edição de produto/variação/oferta/par.
- **Reimplementar a regra no JS** (chave, nome, logística): só formatação no JS.
- **Confiar na composição enviada pelo navegador**: aceitar só **chave + nome + SKU**; o servidor regera, confere que a chave existe e reconstrói os itens (sem isso, id de outra empresa viraria componente).
- **Chamar o ML por sugestão**: o frete da lista é a tabela da ECF (sem requisição); cotação real só por ação explícita e no teto de `max_por_requisicao`.
- **Produto cartesiano de variações** e **trios** na v1 (precisão de 10%).
- **Gravar `logistica` ou preço na oferta aceita**: a 167 não grava `logistica` na simples; o frete é exibição (D-19).
- **Grade/`SpreadsheetGrid` na revisão** (reprovada na 167).
- **`ConvertEmptyStringsToNull`**: quantidade "nenhuma" não pode ser `''` (vira `null` = herda); usar `'0'`.

## Não Reinvente

| Problema | Não construa | Use | Por quê |
|----------|--------------|-----|---------|
| Criar oferta com composição | `EstruturaOferta::create` direto | `EstruturaOfertaService::criar(..., varrerEspera: false)` | Regra de composição, `varrerEspera`, componentes e log num lugar só (D-01) |
| Combo em várias quantidades já existente | outro gerador de combo à parte | reutilizar as **regras** de `criarCombos` (SKU `-CB{n}`, pular quantidade que já existe por composição) | Mesma convenção; a comparação é por composição, não por SKU |
| Pacote empilhado e classe logística | outra conta | `LogisticaProduto::pacote/daVolumes` com volumes repetidos | Medido: 194 de 194 classes iguais às digitadas pela equipe |
| Frete ME2 | tabela nova | `FreteMe2Service::estimar` (+ `cotar` sob demanda) | Cache `Cache::many`, preço de referência, limite de frete grátis nunca fixo |
| Normalizar texto (caixa/acento) | `strtolower` solto | `Str::ascii` + `mb_strtolower` num único helper do domínio, o mesmo para palavras-chave, família e ambiente | A planilha tem 11 grafias de ambiente |
| Trilha de autoria | `Log::info` | `RegistroEstrutura::registrar()` com o ator | Grava `origem` (o `causer_id` não distingue) |
| Paginação/painel | filtro no navegador | Padrão `EstruturaConjunto` (tudo no servidor) | learnings §25/§27 |
| Detecção de driver nas migrations | `=== 'mysql'` | helper `emMysql()` (mysql **ou** mariadb) das migrations da 167 | O driver `mariadb` do Laravel 11+ cairia no ramo do SQLite |
| Guarda do voltar | ouvinte novo de `popstate` | `resources/js/lib/guardaDoVoltar.js` | learnings §32 |

**Insight central:** o trabalho de verdade está em **decidir o que NÃO sugerir** (família, ambiente, par de tipo, direção do Combit, duplicata, descartada). Cada filtro foi medido: sem a lista de pares o Kit vai de 91 para 136 sugestões; sem direção o Combit vai de 28 para 140–168.

## Persistência (decisão por escrito — D-11 / CLAUDE.md disciplina 2)

**Convenções herdadas da 167:** prefixo `estrutura_`; **sem `enum`** (varchar + constante no model); **sem `json`** (LONGTEXT no MariaDB 10.4); `timestamps()` nullable (nunca `timestamp()` solto, learnings §18); nomes de índice e FK **explícitos e curtos** (< 64); `company_id` em tudo que é por empresa.

| Tabela | Escopo | Colunas | Índices / FKs |
|--------|--------|---------|----------------|
| `estrutura_tipos_produto` | **global (ECF)** | `id`; `slug` varchar(40); `nome` varchar(60) ("Cadeira"); `plural` varchar(60) ("Cadeiras", para os títulos); `palavras` varchar(255) (radicais normalizados separados por vírgula, ex. "cadeira"); `qtd_combo` varchar(40) null; `qtd_combit` varchar(40) null; `ordem` smallint unsigned default 0; `timestamps` | unique `etp_slug_uq (slug)` |
| `estrutura_tipo_pares` | **global (ECF)** | `id`; `tipo_a_id`, `tipo_b_id` (par **não ordenado**: guardar com `tipo_a_id <= tipo_b_id`); `combit_repete` varchar(8) null (`'a'`, `'b'`, `'ambos'`; null = só Kit); `timestamps` | unique `etpar_uq (tipo_a_id, tipo_b_id)`; FK `etpar_a_fk`, `etpar_b_fk` → tipos **cascade**; index `etpar_b_idx (tipo_b_id)` |
| `estrutura_produto_geracao` | por empresa (1:1 com produto) | `produto_id` unsigned bigint **PK/unique**; `company_id` (denormalizada para escopo); `tipo_id` unsigned bigint **nullable**; `qtd_combo` varchar(40) null; `qtd_combit` varchar(40) null; `timestamps` | PK `epg_pk`; FK `epg_produto_fk` → `estrutura_produtos` **cascade**; FK `epg_company_fk` → companies **cascade**; FK `epg_tipo_fk` → tipos **nullOnDelete** (coluna nullable, sem erro 1830); index `epg_tipo_idx (tipo_id)` |
| `estrutura_sugestoes_descartadas` | por empresa | `id`; `company_id`; `chave` varchar(100); `fase` varchar(10); `timestamps` | FK `esd_company_fk` cascade; unique `esd_company_chave_uq (company_id, chave)` |

**Por que 1:1 em vez de coluna na 167:** nenhum ALTER em `estrutura_produtos` (tabela nova de 06/10, mas aprovada e em produção); os serviços e a grade da 167 não ficam sabendo do tipo; excluir o produto leva o ajuste junto (cascade). O D-11 permitiria coluna nullable, mas a 1:1 é estritamente mais segura e testável.

**Por que `qtd_*` como texto "2,4,6":** evita tabela filha e `json`; `Quantidades` valida (inteiros 2..999, únicos, até 8). `null` = herda; `'0'` = nenhuma (e **não** `''`, que o Laravel converte em `null`).

**Onde a ECF edita (PR168-14):** a decisão recomendada é **semente na migration + tela admin pequena** (`/dev/estrutura-geracao`, `role:admin`, dentro do grupo `role:admin` já existente em `routes/web.php`), com duas tabelas (tipos, pares). Motivo: "cresce quando a ECF acrescenta pares" (D-06) não pode depender de deploy. Se o escopo apertar, o corte aceitável é **semente + `php artisan` de manutenção**, mas isso deve ser decisão explícita do usuário (Questão Aberta 4).

**Semente:** a migration `..._semear_...` faz `insertOrIgnore` por `slug` e por par, lendo `config/estrutura_geracao.php`; `down()` **não apaga** (a ECF pode ter editado). Tipos: vocabulário **genérico** de móveis (cadeira, mesa, banqueta, banco, cama, criado-mudo, cômoda, buffet, aparador, rack, estante, cristaleira, escrivaninha, guarda-roupa, sofá, poltrona, painel, …), quantidades **literais do D-07** (cadeira combo/combit 2,4,6; banqueta 2,3,4; mesa `0`). Pares-semente: os que a planilha usou (21–23); **a lista concreta sai de um roteiro local fora do repo e passa por conferência do usuário antes de entrar em `config`** (Questão Aberta 3), porque revela a estrutura do catálogo do cliente.

**Sugestões descartadas:** só a chave. Se a variação for excluída, a chave fica órfã e inofensiva (nunca mais casa); limpeza opcional por comando. **Aceitas** não precisam de tabela: o `existentes` (composição já virou oferta) já as esconde; se a oferta aceita for excluída depois, a sugestão **volta** (comportamento correto: a pessoa excluiu a oferta, não descartou a ideia).

## Migration (aditiva e idempotente — PR168-13)

Duas migrations, **separadas** (se a de criação falhar no meio, não deixa tabela sem índice com a migration `Pending`):

1. `2026_10_07_100000_create_estrutura_geracao_tables.php` — as 4 tabelas, copiando o desenho de `2026_10_06_100000_create_estrutura_produtos_tables.php`: cada `Schema::create` sob `hasTable`; no fim, índices e FKs que faltarem repostos **pelo nome**, cada DDL em `Schema::table` separado, existência por `information_schema` (mysql **e** mariadb, `emMysql()`) e `PRAGMA` no SQLite; **proibido `try/catch` em volta de DDL**; `down()` em ordem inversa, cada drop sob checagem.
2. `2026_10_07_100100_semear_estrutura_tipos_e_pares.php` — só dados, `insertOrIgnore`; `down()` vazio.

**Nada** toca `estrutura_ofertas`, `estrutura_produtos` ou outra tabela da 167. Sem índice com FK a dropar (não há 1553). `nullOnDelete` só em `epg_tipo_fk` (nullable). Nomes: o maior (`esd_company_chave_uq`, 20) e os demais ≪ 64.

**Verificação obrigatória no MariaDB 10.4 local (compartilhado — usar `--path`):** `migrate --path=` das duas; `SHOW CREATE TABLE` das 4; `SHOW INDEX`; `migrate:rollback --path=` na ordem inversa e subir de novo (idempotência); contagem de `estrutura_ofertas` antes e depois (igual); rodar a semente **duas vezes** e conferir que a contagem não muda. Acrescentar as duas migrations a `MigracoesDaFaseDetectamMariaDbTest::MIGRACOES`. A tabela de criação deve ser registrada em `168-VERIFICATION.md` como na 167 (§"Prova no MariaDB").

## Logística do conjunto (PR168-10)

**Regra (medida):** conjunto = **todos os volumes de todos os componentes, repetidos pela quantidade** → `LogisticaProduto::daVolumes($volumes)` (empilha: maior C, maior L, soma das alturas, soma dos pesos). Código:

```php
// ConjuntoLogistico::volumes(array $itens): list<array{c,l,a,kg}>   (fino, sem I/O)
$volumes = [];
foreach ($itens as $item) {                       // $item = ['volumes' => [...volumes da variação...], 'quantidade' => int]
    for ($k = 0; $k < $item['quantidade']; $k++) { // cada UNIDADE leva os próprios volumes
        foreach ($item['volumes'] as $v) { $volumes[] = $v; }
    }
}
return LogisticaProduto::daVolumes($volumes);      // logistica, pacote, peso_cubado, peso_faturado
```

**Prova com a planilha (194 ofertas com medida; a planilha tem o pacote DIGITADO à mão pela equipe, e peso/custo por fórmula):**

| Comparação (empilhar × digitado) | Resultado |
|----------------------------------|-----------|
| Nº de volumes digitado = Σ quantidade × volumes do produto | **194 de 194** |
| **Classe logística** (ME1/ME2/ME2·Full) igual | **194 de 194** (Combo 42/42, Kit 44/44, Combit 39/39, Simples 69/69 entre as comparáveis) |
| C e L do pacote ≈ máximo dos volumes (±1 cm) | 161 de 194 |
| Altura: Σ alturas − digitada (cm) | mediana 0; p90 80; máx 1.085 (cadeiras empilhadas pesam mais na soma do que na caixa real) |
| **Frete pela tabela da ECF** (ofertas ME2) | **15 de 15 iguais** (preço de referência) |

**Conclusão:** a classe e o frete batem; a **altura pode ser superestimada** em conjuntos com muitas unidades (caixa real mais compacta que o empilhamento), o que só afeta o peso cubado (e só importa quando passa de 5 kg e do peso real). Por isso o rótulo é sempre **"estimado"** (como na 167) e a pessoa vê "Pendente" se qualquer componente não tem medida. A aceitação **não** grava `logistica` na oferta (a 167 não grava na simples; a coluna é a **intenção** do cliente, inclusive `kit_virtual`). Peso real do conjunto = Σ quantidade × peso total (mesma fórmula da coluna X da planilha).

Frete: `FreteMe2Service::estimar($empresa, [$chave => ['pacote','peso_faturado','logistica','custo']])` — **uma** chamada para a página inteira (itens ME2 apenas; ME1 volta vazio). `custo` do conjunto = Σ quantidade × custo da variação, ou `null` se algum for nulo (a Precificação anula a soma de propósito, PORTAL-02): sem custo, a estimativa usa o preço de referência e o cartão avisa. Cotação real (`cotar`) só por botão, só da página visível.

## Volume — como a tela aguenta centenas de sugestões (PR168-11)

- **Geração:** em memória, uma vez por requisição, **bucket por família** (Σ f², não N²). Para o catálogo medido (56 produtos), 171 sugestões; para 500 produtos em ~40 famílias a ordem de grandeza é de milhares. Guarda: `config('estrutura_geracao.teto_sugestoes')` (ex.: 5.000) e aviso "refine por família" se estourar (a família-polo de 14 produtos é o pior caso: sozinha concentra 32 das 35 sobras).
- **Consultas fixas (não por sugestão):** produtos+família+ambientes+variações+volumes (`with`), ofertas simples ligadas (`whereNotNull('variacao_id')`), composições existentes (join `estrutura_oferta_componentes` × `estrutura_ofertas` por `company_id`), ajustes 1:1, tipos/pares (globais, cache curto), descartadas. Nada de N+1.
- **Entrega:** painel (contagens por fase, família, "sem tipo", descartadas) sobre o **conjunto inteiro**; lista **paginada no servidor** (20 por página) e filtros por query (`familia`, `fase`, `tipo`, `q`, `aba=descartadas`); a logística e o frete só da página.
- **Aceitar em lote:** `≤ 100` por requisição; um `lockForUpdate` na linha da empresa (SQLite ignora) serializa dois aceites simultâneos; cada sugestão em sua transação; `varrerEspera` uma vez no fim com todos os SKUs. Marcar "todas da família" não passa de 100 por vez (a tela avisa e divide).
- **Ordem estável:** `(família, ordem da fase combo<kit<combit, chave)`, para a página não "andar" entre requisições.

## Nada duplicado (PR168-06)

1. **Composição existente (inclusive à mão):** conjunto `existentes` = chave de cada oferta `combo/kit/combit` da empresa cujos componentes têm todos `variacao_id`. Cobre o combo/kit digitado na Lista SKUs **usando as ofertas ligadas** (o caso normal depois da 167, quando as simples nascem do Produtos). Um combo à mão sobre **ofertas simples antigas, sem produto** não é comparável (são outros ids de oferta) e **não** bloqueia a sugestão: o cartão mostra o aviso "já existe oferta com este SKU" (comparação por `EstruturaOferta::normalizarSku`) para a pessoa decidir.
2. **Sugestão pendente:** não há tabela de pendentes; a regeração é determinística e a chave é a mesma, então gerar de novo **não duplica**.
3. **Descartada:** `esd_company_chave_uq` + o conjunto `descartadas` no gerador.
4. **Aceite concorrente / duplo clique:** o servidor regera, confere que a chave ainda está nas sugestões (se já virou oferta, ela saiu) dentro do `lockForUpdate`; a segunda requisição recebe "já existe" e **não** cria. Teste obrigatório (duas chamadas seguidas e uma com a oferta criada à mão entre a geração e o aceite).
5. **Variação excluída:** a regra da 167 recusa excluir variação que é componente (com o nome do combo); a sugestão correspondente simplesmente deixa de ser gerada. Oferta aceita é oferta comum da Lista SKUs (editável/excluível por lá).

## Aceitar (PR168-08)

```php
// SugestoesService::aceitar — esboço. Fonte: EstruturaOfertaService::criar (varrerEspera=false), padrão de gravarLinhas (erro por item não derruba o lote).
public function aceitar(Company $empresa, array $pedidos, AtorDoPortal $ator): array
{
    $resultado = ['criadas' => [], 'ja_existiam' => 0, 'erros' => []];

    DB::transaction(function () use (&$resultado, $empresa, $pedidos, $ator) {
        Company::whereKey($empresa->id)->lockForUpdate()->first();          // serializa aceites da empresa
        $vigentes = collect($this->gerar($empresa))->keyBy('chave');         // regera: só aceita chave que existe AGORA

        foreach (array_slice($pedidos, 0, 100) as $p) {
            $s = $vigentes[$p['chave']] ?? null;
            if ($s === null) { $resultado['ja_existiam']++; continue; }
            try {
                [$oferta] = $this->ofertas->criar($empresa, [
                    'sku' => $p['sku'] ?? $s['sku'], 'nome' => $p['nome'] ?? $s['nome'], 'fase' => $s['fase'],
                    'componentes' => array_map(fn ($i) => ['id' => $i['oferta_id'], 'quantidade' => $i['quantidade']], $s['itens']),
                ], $ator, varrerEspera: false);                             // regra de composição = a da Lista SKUs
                $resultado['criadas'][] = $oferta->sku;
            } catch (ValidationException $e) {
                $resultado['erros'][] = ['chave' => $p['chave'], 'mensagens' => $e->errors()];
            }
        }

        $this->ofertas->varrerEspera($empresa, $resultado['criadas']);      // 1x no fim (BE-WR-05)
        RegistroEstrutura::registrar($ator, $empresa, null, 'sugestoes_aceitas',
            count($resultado['criadas']).' sugestão(ões) aceita(s)', ['erros' => count($resultado['erros'])]);
    });

    return $resultado;
}
```

Observações: (a) `criar()` já cria a oferta com `componentes` pela regra e grava `oferta_criada`; (b) o ator vem **sempre** de `PortalContexto::ator()`; (c) nome/SKU vindos do navegador passam por `campos()` do serviço (SKU obrigatório, ≤ 120; nome ≤ 255); (d) como o gerador só usa **variações com oferta simples ligada**, `composicao()` valida "da mesma empresa e simples" de novo, sem confiança no navegador.

## Acesso (PR168-12)

- **Rotas** dentro do grupo `portal.auth` (ao lado das de Produtos, `routes/web.php:201-238`), cada uma com `throttle:N,1,<prefixo próprio>` (sem o 3º parâmetro todas dividem um contador e a colagem dava 429) e `whereNumber` no id. Sugestão: `GET /estrutura/sugestoes` (`60,1,estrutura.sugestoes`), `POST /estrutura/sugestoes/aceitar` (`30,1,estrutura.sugestoes.aceitar`), `POST .../descartar` (`60,1,...descartar`), `POST .../restaurar` (`60,1,...restaurar`), `PUT /estrutura/sugestoes/produtos/{produto}/geracao` (`60,1,...geracao`), `POST .../frete` opcional para cotação real (`10,1,...frete`).
- **Allowlist:** **uma linha por rota** em `RestringeDominioDoPortal::PERMITIDO`: `portal/estrutura/sugestoes`, `.../aceitar`, `.../descartar`, `.../restaurar`, `.../produtos/*/geracao`, `.../frete`. **Nunca** `portal/estrutura/sugestoes/*` genérico: o `*` do `Str::is` atravessa `/`. Rede de segurança já existente: `DominioLiberaTodoModuloTest` varre o router e falha se uma rota `portal.auth.*` não estiver liberada.
- **Menu:** recomendação — **não** criar submódulo novo. A página usa a chave ativa `estrutura.produtos` e é aberta por um botão em Produtos e em Lista SKUs (é o D-02: "entrada pela tela de Produtos e pela Lista SKUs"). Assim `PortalSemAnunciarTest` e `AcessoAoModuloEstruturaTest` (que asserem a lista literal de 6 submódulos) **não** mudam. O rótulo "Planejamento" já é do submódulo da agenda (`portal.auth.estrutura.agenda`): **não reutilizar o nome**. Nome e posição finais são da UI-SPEC.
- **Empresa e ator:** sempre de `PortalContexto::empresa()/ator()` (nunca do request); id de produto de outra empresa = 404 uniforme; `produto`/`tipo_id` revalidados contra a empresa/lista global; a equipe entra pelo mesmo grupo e grava `origem = interno`.
- **Admin ECF:** rotas `/dev/estrutura-geracao` no grupo `role:admin` de `routes/web.php` (não são Portal, não entram na allowlist do domínio do cliente).

## Runtime State Inventory

Não se aplica (fase aditiva, sem renomear/migrar dado). Verificado: nenhuma tabela existente é alterada; nenhuma oferta existente é tocada (D-04). *Stored data:* nenhum dado a migrar. *Live service config:* nada. *OS-registered state:* nada. *Secrets/env:* nenhum novo. *Build artifacts:* só o `npm run build` normal (conferir o manifest).

## Armadilhas Comuns

### Armadilha 1: SQLite dos testes não pega o MariaDB
**O que dá errado:** índice > 64 caracteres (1059), `nullOnDelete` em coluna NOT NULL (1830), drop de índice de FK (1553), `enum`, `json`.
**Como evitar:** nomes explícitos curtos; migração idempotente por checagem; verificar no MariaDB 10.4 local **com `--path`** (o banco é compartilhado; nunca `migrate` puro); teste de varredura `MigracoesDaFaseDetectamMariaDbTest`.
**Sinais:** migration `Pending` com tabela existente e sem índice.

### Armadilha 2: Combit sem direção explode (e mesa/cama repetidas)
**O que dá errado:** repetir o item nos dois lados do par gera 116–138 Combits a mais (planilha: 25). Quantidade por tipo "mesa só 1" contradiz o medido (8 Combits repetem mesa ×2; 2 Combos de mesa ×2).
**Como evitar:** `combit_repete` por par; `qtd_combo` e `qtd_combit` **separadas** por tipo (cadeira: Combo 2/4/6/8, Combit 2/4/6); tudo editável pela ECF e por produto.

### Armadilha 3: Variação em produto cartesiano
**O que dá errado:** 2×2 variações viram 4 combinações; a planilha usa 2 (paralelas). Gera ruído e SKUs sem sentido (cor errada com cor errada).
**Como evitar:** `VariacoesEmParalelo` por `(eixo, valor)` senão `ordem`; 1×N casa com todas.

### Armadilha 4: Confiar no que o navegador manda no aceite
**O que dá errado:** composição vinda do cliente com id de oferta de outra empresa.
**Como evitar:** só `chave` + `nome` + `sku`; regerar no servidor; regex da chave; `composicao()` do serviço revalida empresa e fase.

### Armadilha 5: `ConvertEmptyStringsToNull` e "nenhuma quantidade"
**O que dá errado:** `qtd_combo = ''` vira `null` e o produto passa a **herdar** o padrão do tipo, em vez de "sem combo".
**Como evitar:** `'0'` = nenhuma; validar na camada `Quantidades`.

### Armadilha 6: Tipo inferido errado vira Kit errado
**O que dá errado:** categoria "mesa de centro" casando "mesa" e formando par com cadeira.
**Como evitar:** o D-01 (revisão humana) e o agrupamento por família; ambiguidade (2 tipos) = **sem tipo**, nunca escolha silenciosa; a pessoa vê "Tipo: Mesa (sugerido)" e pode trocar.

### Armadilha 7: Planilha real no repositório ou na saída
**O que dá errado:** nome de produto, SKU ou custo do cliente em fixture, log, RESEARCH ou stdout.
**Como evitar:** fixture sintética gerada no teste; o roteiro de conferência local imprime **só contagens**; não commitar a planilha (já está no worktree principal fora do índice, conferir `git status`).

### Armadilha 8: Tela "não carrega" por geração a cada clique
**O que dá errado:** gerar tudo a cada requisição de filtro (centenas de ms × muitas ações).
**Como evitar:** geração em memória barata (bucket por família); `Cache` curto por empresa **só** se medir lentidão; nunca persistir sugestão como atalho.

### Armadilha 9: `npm run build` em worktree
`npm run build` sai 0 **sem buildar** em worktree novo, e página só re-exportada some do manifest (learnings da 167). Conferir `public/build/manifest.json` e usar `ASSET_URL` vazio.

## Código — exemplos verificados

### Chave canônica (PR168-06)
```php
// Source: desenho desta pesquisa; formato validado por regex.
final class ChaveDeComposicao
{
    public static function de(array $itens): string          // $itens: [variacao_id => quantidade]
    {
        ksort($itens);
        return implode('+', array_map(fn ($v, $q) => "v{$v}*{$q}", array_keys($itens), $itens));
    }

    public static function valida(string $chave): bool
    {
        return preg_match('/^v\d+\*\d+(\+v\d+\*\d+){0,2}$/', $chave) === 1;
    }
}
```

### Pacote do conjunto (PR168-10)
```php
// Source: app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php (pacote/avaliar/daVolumes) — validado 194/194 contra a planilha.
$log = LogisticaProduto::daVolumes($volumesDoConjunto);   // ['logistica','pacote','peso_cubado','peso_faturado',...]
$frete = $freteService->estimar($empresa, [$chave => [
    'pacote' => $log['pacote'], 'peso_faturado' => $log['peso_faturado'], 'logistica' => $log['logistica'], 'custo' => $custoDoConjunto,
]])[$chave];
```

### Nome do Combo no padrão da planilha (PR168-09)
```php
// Source: medição desta pesquisa (43 de 46); fallback = EstruturaOfertaService::criarCombos ("Combo {n} {nome}").
$resto = self::semPrimeiraPalavra($produto['nome'], $tipo['nome']);   // só se o nome começa pela palavra do tipo
$nome  = $tipo && $resto !== null ? "Kit {$n} {$tipo['plural']} {$resto}" : "Combo {$n} {$produto['nome']}";
```

## Estado da Arte

| Abordagem antiga | Atual | Quando mudou | Impacto |
|------------------|-------|--------------|---------|
| Equipe monta cada combo/kit/combit à mão na Lista SKUs (199 ofertas por 70 produtos) | O sistema **sugere** e a pessoa decide | Fase 168 | ~171 sugestões por 70 variações; só 25% (3 itens) segue manual |
| `criarCombos`: só combo por quantidade a partir de um produto | Gerador cobre Combo, Kit e Combit | Fase 168 | Reaproveita as regras de `criarCombos` (SKU `-CB{n}`, pular por composição) |
| Frete/logística só da oferta simples (167, D-18) | Conjunto = volumes × quantidade | Fase 168 | 194/194 classes e 15/15 fretes iguais ao digitado |

**Depreciado/fora:** "Combo n Nome" como único padrão de nome de combo (a planilha usa "Kit n Plural …"; o antigo fica como fallback).

## Log de Suposições

| # | Afirmação | Seção | Risco se errada |
|---|-----------|-------|-----------------|
| A1 | SKU de Kit `KT-{A}-{B}` e de Combit `CT{n}-{fixo}-{repetido}` (a planilha não tem convenção própria; só o `-CB{n}` do `criarCombos` existe) | Pergunta 3 | Baixo: editável antes de aceitar; o usuário pode preferir outro padrão |
| A2 | O vocabulário genérico de 36 palavras de tipo (cobertura de 79%) e as palavras-chave por tipo da semente | D-10, Persistência | Médio: se a ECF quiser outro vocabulário/granularidade (ex.: "mesa de centro" separada de "mesa de jantar"), muda a lista de pares |
| A3 | `max_titulo` de 60 vale para todas as categorias (o ML expõe `settings.max_title_length` por categoria; o código já usa 60 como default) | Pergunta 3, PR168-09 | Baixo: só é aviso, não bloqueio |
| A4 | Limite de 100 aceites por requisição e 20 sugestões por página | Volume | Baixo: valores de `config`, ajustáveis |
| A5 | A semente de pares tirada da planilha pode ser commitada em `config` (são tipos genéricos, sem produto/SKU/custo) | Persistência | Médio: o usuário pode considerar que revela a estrutura do catálogo do cliente (Questão Aberta 3) |
| A6 | A baseline de testes do fim da 167 (G1 224/1543 etc.) ainda vale na árvore de hoje | Validation | Baixo: a Onda 0 mede de novo antes de mexer |

## Questões Abertas

1. **As quantidades do D-07 batem com a planilha? (decisão do usuário)**
   - O que sabemos: com as quantidades **literais** (cadeira 2/4/6, banqueta 2/3/4, mesa só 1) o gerador acerta 25 de 46 Combos e 10 de 25 Combits. A planilha tem cadeira **×8** (7 combos), banco ×2/×4, e vários tipos com Combit ×2 (mesa 8, cama 2, outro tipo 6, banco 5). O que a reunião disse ("2/4/6/8/10, nunca 5") também inclui 8.
   - O que falta: se o 8 (e 10) entra no padrão da cadeira, se banco tem padrão, e se "mesa ×2 no Combit" é desejado.
   - Recomendação: **semear exatamente o D-07** (travado) e mostrar os números acima ao usuário; a ECF acrescenta pelo admin (`qtd_combo`/`qtd_combit` por tipo) sem deploy. Não alterar o D-07 por conta própria.
2. **Trios (Kit/Combit com 3 itens) ficam fora da v1?**
   - O que sabemos: 21 das 83 composições da planilha (25%) têm 3 itens; um gerador de trios propõe 124 para acertar 12.
   - Recomendação: v1 só com 2 itens; a pessoa monta o trio à mão na Lista SKUs. Se quiserem trios, é um passo posterior com tipo "complementar" por par, não um produto cartesiano.
3. **A lista-semente de pares de tipo pode ser commitada?** São 21–23 pares de tipos genéricos (sem produto, SKU ou custo), mas descrevem a estrutura de um catálogo real. Recomendação: gerar por roteiro local fora do repo, mostrar ao usuário a lista (só os pares) e commitar só depois do ok; os pares citados no CONTEXT (mesa+cadeira, mesa+banco, cama+criado-mudo) já são seguros.
4. **Tela admin da ECF na v1 ou semente + comando?** Recomendado: tela admin pequena (PR168-14). Se cortar, a lista só cresce por deploy.
5. **Seletor de tipo também na ficha da 167?** Recomendado **não** (a escolha vive na tela de sugestões, sem mudança visual na ficha aprovada). Se a UI-SPEC pedir, vai para conferência visual do usuário (D-10).
6. **Cotação real do frete (API do ML) por sugestão?** Recomendado: só a tabela da ECF "estimado" na lista (zero requisição) e um botão de cotar a página, ME2 apenas, no teto da 167. Em 06/10 a tabela da ECF ficou a < 1% do ML.

## Disponibilidade do Ambiente

| Dependência | Necessária para | Disponível | Versão | Fallback |
|-------------|-----------------|------------|--------|----------|
| PHP CLI (`C:/xampp/php/php.exe`) | testes, roteiro de conferência | ✓ | 8.2.x [CITED: 167-RESEARCH] (o `vendor/` deste worktree foi instalado com `--ignore-platform-reqs`; testes rodam normalmente) | — |
| PhpSpreadsheet do worktree | roteiro local de conferência (leitura da planilha real) | ✓ | 2.4.5 [CITED: composer.lock via 167-RESEARCH] | — |
| MariaDB local `ecf_admin` (compartilhado) | prova de migration | ✓ | 10.4.32 [CITED: 167-RESEARCH] | só `--path`; nunca `migrate` puro |
| Node + `node_modules` do worktree | `npm run build`, `npm run test:js` | ✓ | v26.9.0 [CITED: 167-RESEARCH] | — |
| Planilha real (fora do repo) | só o roteiro local de conferência | ✓ (`C:/xampp/htdocs/ecf_admin/3Planejamento_Estrutural_ECF.xlsx`) | — | fixture sintética nos testes |
| Conta ML conectada | cotação real opcional do frete | não usada nesta fase (só tabela da ECF) | — | `FreteMe2Service::estimar` |

**Sem bloqueio.** Nenhuma dependência faltando.

## Validation Architecture

> `workflow.nyquist_validation` = `true` em `.planning/config.json` (herdado da 167; a Onda 0 confirma).

### Test Framework
| Propriedade | Valor |
|-------------|-------|
| Framework PHP | PHPUnit 11.5 (`phpunit.xml`: SQLite `:memory:`, cache `array`, fila `sync`) |
| Framework JS | `node:test` (`npm run test:js`), gates estruturais com `lerSemComentarios` (`tests/js/_fonte.js`) |
| Config | `phpunit.xml` |
| Comando rápido PHP | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit tests/Unit/PortalEstrutura/Geracao` (≤ 30 s) |
| Suíte da fase | `tests/Unit/PortalEstrutura` + `tests/Feature/PortalCliente/Estrutura` (inclui `Sugestoes/`) + G2 + G3 + G5 + `npm run test:js` |
| Memória | **a suíte inteira estoura 512 MB — rodar por grupo**, saída para arquivo (sem pipe: `| tail` engole o exit code do phpunit) |

### Baseline proposta (`168-BASELINE-TESTES.md`, medir ANTES de mexer; números de referência = fim da 167)

| # | Grupo (comando) | Referência (167 final) |
|---|---|---|
| G1 | `tests/Feature/PortalCliente/Estrutura` | 224 testes / 1543 asserções |
| G2 | `DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas/CustoDoAnuncioTest` | 22 / 118 |
| G3 | `PortalCliente/DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` | 7 / 180 |
| G4 | `tests/Feature/PortalCliente` (inteiro) | 394 / 2877 (inclui G1 e G3) |
| G5 | `OfertaExcluidaNoPortalTest` + `ExclusaoDaEmpresaPreservaHistoricoTest` + `MigracoesDaFaseDetectamMariaDbTest` | 17 / 92 |
| G6 | `npm run test:js` | 1114 testes, 1112 passam; **as 2 falhas antigas são o piso**: "Características secundárias nasce recolhido…" e "FASES_TERMINAIS cobre as três fases de saída…" |
| G7 | `tests/Unit/PortalEstrutura` | 62 / 256 |
| G8 (novo) | `tests/Feature/PortalCliente/Estrutura/Sugestoes` + `tests/Unit/PortalEstrutura/Geracao` | a criar |

**Regra de comparação:** cada grupo com contagem ≥ à da baseline e nenhuma falha nova. Confirmar o autoloader antes (`ReflectionClass(...)->getFileName()` deve apontar para `C:\tmp\ecf-publicador-spec-261001\app\...`).

### Mapa Requisito → Teste

| Req | Comportamento | Tipo | Comando / arquivo | Existe? |
|-----|---------------|------|-------------------|---------|
| PR168-01 | gerador sem banco devolve Combo/Kit/Combit estáveis e ordenados; só usa variação com oferta ligada | unit puro | `tests/Unit/PortalEstrutura/Geracao/GeradorDeSugestoesTest.php` | ❌ Wave 0 |
| PR168-02 | família igual e não nula; ambiente em comum; sem família/tipo fora de Kit/Combit; Combo sem família ok; fora da lista não gera; só 2 itens | unit | `.../GeradorRegrasDurasTest.php` | ❌ Wave 0 |
| PR168-03 | variações em paralelo (valor, ordem, 1×N), nunca cartesiano | unit | `.../VariacoesEmParaleloTest.php` | ❌ Wave 0 |
| PR168-04 | semente idempotente (2× = mesma contagem); par não ordenado único; `combit_repete`; tipo com par apagado em cascata | feature | `Sugestoes/TiposEParesTest.php` | ❌ Wave 0 |
| PR168-05 | tipo efetivo: override > categoria > nome; ambíguo = sem tipo; categoria em qualquer estado; `'0'` = nenhuma; painel "sem tipo"; isolamento por empresa | unit + feature | `.../TipoDoProdutoTest.php`, `Sugestoes/GeracaoDoProdutoTest.php` | ❌ Wave 0 |
| PR168-06 | chave canônica; composição existente (à mão) não é sugerida; regerar não duplica; aceite duplo cria 1; oferta criada à mão entre gerar e aceitar | unit + feature | `.../ChaveDeComposicaoTest.php`, `Sugestoes/NadaDuplicadoTest.php` | ❌ Wave 0 |
| PR168-07 | descartar persiste e não volta; restaurar; unique empresa+chave; descarte de uma empresa não afeta a outra | feature | `Sugestoes/DescarteDeSugestaoTest.php` | ❌ Wave 0 |
| PR168-08 | aceitar cria oferta pela regra da Lista SKUs (componentes, fase); lote ≤ 100; erro num item não derruba os outros; varredura da espera 1×; `origem` cliente/interno; não grava `logistica`; oferta aparece na Lista SKUs e na Precificação (custo = soma dos componentes) | feature | `Sugestoes/AceitarSugestaoTest.php` | ❌ Wave 0 |
| PR168-09 | nome/SKU por fase (casos "Kit n Plural resto", fallback "Combo n", `-CB{n}`); SKU > 120 bloqueia; título > 60 avisa | unit | `.../NomesSugeridosTest.php` | ❌ Wave 0 |
| PR168-10 | volumes × quantidade; classe; componente sem medida = pendente; sem chamada HTTP na lista (`Http::assertNothingSent`); nada gravado em `estrutura_precificacoes` | unit + feature | `.../ConjuntoLogisticoTest.php`, `Sugestoes/LogisticaDoConjuntoTest.php` | ❌ Wave 0 |
| PR168-11 | painel sobre o conjunto inteiro; página no servidor; filtros; agrupamento por família; gates do JSX (sem `SpreadsheetGrid`, regra não duplicada no JS, `guardaDoVoltar` se houver edição não salva) | feature + js gate | `Sugestoes/ListaDeSugestoesTest.php`, `tests/js/estrutura-sugestoes.test.js` | ❌ Wave 0 |
| PR168-12 | rotas exigem `portal.auth`; cliente e equipe; 404 de outra empresa; throttle com prefixo próprio; allowlist | feature | `Sugestoes/AcessoAsSugestoesTest.php` + `DominioLiberaTodoModuloTest` (existente, deve passar) | ❌ Wave 0 (o 2º ✅) |
| PR168-13 | migrations up/down idempotentes; semente 2×; varredura anti-`=== 'mysql'` | feature + manual | `MigracoesDaFaseDetectamMariaDbTest` (ampliado) + roteiro MariaDB no `168-VERIFICATION.md` | ❌ Wave 0 / manual (SQLite não pega 1059/1830/1553) |
| PR168-14 | admin cria/edita tipo e par; não-admin = 403; tipo em uso por par não apaga sem aviso | feature | `Sugestoes/AdminTiposEParesTest.php` | ❌ Wave 0 |
| PR168-15 | gabarito sintético com a forma medida: 1 família-polo, famílias de 1 produto, grupos de 2 variações, par sem ambiente comum, produto sem tipo; números esperados fixados; **roteiro local** na planilha real imprime só contagens (meta ≥ 106 acertos de 129; 171 geradas no cenário recomendado) | unit + manual-only | `.../GabaritoDaGeracaoTest.php`; roteiro fora do repo | ❌ Wave 0 / manual |

### Frequência de amostragem
- **Por commit de tarefa:** o arquivo de teste da tarefa + `node --test` do arquivo JS tocado.
- **Por merge de onda:** `tests/Unit/PortalEstrutura` + `tests/Feature/PortalCliente/Estrutura/Sugestoes` + G2 + G3.
- **Gate da fase:** G1..G8 sem falha nova (G6 com o piso das 2 antigas) + prova no MariaDB (PR168-13) + roteiro de gabarito local (PR168-15) + `npm run build` com a página no manifest, antes de `/gsd:verify-work`.

### Lacunas da Onda 0
- [ ] `168-BASELINE-TESTES.md` com os números de hoje
- [ ] Fixture **sintética** do catálogo (gerada no teste; nunca o `.xlsx` real), com a forma medida
- [ ] `tests/Unit/PortalEstrutura/Geracao/*` (6 arquivos) e `tests/Feature/PortalCliente/Estrutura/Sugestoes/*` (8) e `tests/js/estrutura-sugestoes.test.js`
- [ ] Ampliar `MigracoesDaFaseDetectamMariaDbTest::MIGRACOES` com as 2 migrations
- [ ] Nenhum framework a instalar

## Domínio de Segurança

### Categorias ASVS aplicáveis

| Categoria | Aplica | Controle padrão |
|-----------|--------|-----------------|
| V2 Autenticação | sim (herdado) | middleware `portal.auth` (cliente por e-mail+código; equipe por ticket) — nada novo; admin da ECF por `auth` + `role:admin` |
| V3 Sessão | sim (herdado) | guard `portal`, `EnsurePortalAutenticado` |
| V4 Controle de acesso | **sim** | empresa **só** do `PortalContexto`; produto/ajuste por `where('company_id', $empresa->id)->findOrFail()` (404 uniforme); `tipo_id` só da lista global; o aceite regera e valida a chave no servidor |
| V5 Validação de entrada | **sim** | `$request->validate` + regex da chave; `nome` ≤ 255, `sku` ≤ 120; `qtd_*` por `Quantidades`; lote ≤ 100; `$fillable` explícito |
| V6 Criptografia | não | nenhum segredo novo; nenhuma chamada ao ML no caminho padrão |
| V13 API | **sim** | `throttle` por rota com prefixo próprio; o frete real só por ação explícita e com teto |

### Ameaças conhecidas para esta stack

| Padrão | STRIDE | Mitigação padrão |
|--------|--------|------------------|
| IDOR (chave/produto/variação de outra empresa) | Information disclosure / Tampering | chave regerada e conferida no servidor; escopo por `company_id`; 404 uniforme; teste com duas empresas |
| Composição forjada no aceite (id de oferta de outra empresa) | Tampering | o navegador manda só `chave`+`nome`+`sku`; `composicao()` revalida empresa e fase |
| Mass assignment | Tampering | `$fillable` explícito; `company_id` nunca do request |
| Duplo clique / corrida no aceite | Tampering | `lockForUpdate` na empresa + re-checagem da chave + unique no descarte |
| Abuso de CPU (gerar muitas vezes) | DoS | `throttle` no GET, teto de sugestões, bucket por família |
| Escrita na conta ML do cliente | Tampering | esta fase só lê; frete real (opcional) é GET e reaproveita a 167; teste de que nenhum POST/PUT sai |
| Vazamento do catálogo real em artefatos | Information disclosure | fixture sintética; roteiro local só imprime contagens; planilha fora do índice do git |
| Edição do catálogo global por não-admin | Elevation of privilege | `role:admin`; teste de 403 |
| Repúdio (quem aceitou/descartou) | Repudiation | `RegistroEstrutura` com `origem` em **toda** escrita (aceitar, descartar, restaurar, tipo do produto, admin) |

## Fontes

### Primárias (confiança ALTA — lidas/medidas nesta sessão)
- `168-CONTEXT.md`, `167-CONTEXT.md`, `167-RESEARCH.md`, `167-BASELINE-TESTES.md`, ROADMAP (seção da Fase 168).
- Código do worktree: `app/Services/Portal/Estrutura/EstruturaOfertaService.php` (`criar`, `criarCombos`, `composicao`, `varrerEspera`, `excluir`), `EstruturaConjunto.php`, `Produtos/LogisticaProduto.php`, `Produtos/FreteMe2Service.php`, `Produtos/ProdutoLinhas.php`, `Produtos/ProdutoCustos.php`; models `EstruturaOferta`, `EstruturaOfertaComponente`, `EstruturaProduto`, `EstruturaProdutoVariacao`; `app/Support/Portal/ModulosPortal.php`; `RestringeDominioDoPortal.php`; `routes/web.php` (grupo `portal.auth`); `config/estrutura_produtos.php`; migrations `2026_10_06_100000_*`, `2026_10_06_100100_*`; `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php`.
- Learnings: `portal-do-cliente.md` §25, §27, §28, §31 (D-23: sem planilha no cadastro), §32; `desempenho-bonificacao.md` §6.
- **Medição local** (script em memória, apagado; só contagens): `3Planejamento_Estrutural_ECF.xlsx`, abas Planejamento (199 ofertas) e Produtos (70 variações): tipos, pares, variações, padrões de nome/SKU, gabarito do gerador (simulação com 8 cenários), vocabulário genérico de tipo, logística do conjunto contra o pacote digitado (194 comparáveis), frete ME2 (15).

### Secundárias (confiança MÉDIA)
- 167-RESEARCH para as versões de PHP/MariaDB/PhpSpreadsheet/Node (não reconferidas hoje).

### Terciárias (confiança BAIXA — para validar)
- Nada além das suposições A1–A6.

## Metadados

**Confiança:**
- Stack padrão: ALTA — nenhuma dependência nova; tudo já existe.
- Arquitetura e schema: ALTA — precedentes da 167 e leitura do `criar()`.
- Gabarito (números): ALTA para a planilha medida; **o cenário recomendado é uma simulação sobre os mesmos dados de que a semente foi tirada**, então ele mede o teto (reprodutibilidade), não a precisão em catálogos novos. A precisão em outros clientes só se mede com uso real (descartes).
- Pitfalls: ALTA (repositório/MariaDB), MÉDIA (qualidade da inferência do tipo fora desta planilha).

**Data da pesquisa:** 2026-10-06
**Válida até:** 2026-11-05 (30 dias; o que muda mais rápido é a tabela de frete do ML e o catálogo do cliente)
