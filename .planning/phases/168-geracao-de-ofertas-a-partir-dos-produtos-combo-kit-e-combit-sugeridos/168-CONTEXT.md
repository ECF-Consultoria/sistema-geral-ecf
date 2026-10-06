# Phase 168: Geração de ofertas a partir dos produtos (Combo, Kit e Combit sugeridos) - Context

**Gathered:** 2026-10-06
**Status:** Ready for planning (atualizado depois da pesquisa, D-12..D-20)

<domain>
## Phase Boundary

A aba **Planejamento** da planilha `3Planejamento_Estrutural_ECF.xlsx` ("identificação da oferta") vira sistema.
A partir dos produtos que o cliente cadastrou na Fase 167, o sistema **sugere** as ofertas que juntam produtos:
- **Combo:** o mesmo produto, mais unidades (2, 4 ou 6 cadeiras).
- **Kit:** produtos diferentes, uma unidade de cada.
- **Combit:** um kit com mais unidades de algum item (mesa com 4 cadeiras).

A pessoa revisa e aceita, e as aceitas viram ofertas da Lista SKUs. As **Simples** já nascem sozinhas, uma por
variação, desde a 167.

Esta fase entrega:
- o gerador de sugestões (Combo, Kit e Combit) com as regras abaixo;
- a lista de **pares de tipo que fazem sentido** e as **quantidades por tipo**, as duas mantidas pela ECF;
- a tela de revisão das sugestões no Mapeamento Estrutural: aceitar, descartar, ajustar nome e SKU antes de aceitar;
- em cada sugestão, a logística provável e o frete estimado do conjunto (volumes somados), com a mesma regra da 167;
- a oferta aceita criada pela mesma regra de composição da Lista SKUs, e por isso já aparecendo na Precificação.

**Fica FORA** (fases seguintes):
- grade de margem 30/20/10/0 e tarifa por categoria pela API;
- "qual promoção dá mais lucro", que é a tensão com o D-11 da 166 e foi adiada pelo usuário em 06/10;
- cronograma por capacidade do publicador;
- gatilhos depois da publicação;
- prioridade "o que já vendeu primeiro";
- IA escolhendo combinações;
- publicação automática.

A ideia que orienta: na reunião de 05/10 o Emerson mostrou que **70 produtos viram ~199 ofertas** (70 Simples,
46 Combo, 44 Kit, 39 Combit). Hoje a equipe monta cada uma à mão na Lista SKUs; a fase faz o sistema propor e
a pessoa só decidir.
</domain>

<decisions>
## Implementation Decisions

### O sistema sugere, a pessoa decide
- **D-01:** Nada vira oferta sozinho. O gerador produz SUGESTÕES. A pessoa aceita uma a uma ou marca várias e
  aceita juntas. Aceitar cria a oferta pelo mesmo caminho da Lista SKUs (`EstruturaOfertaService::criar`, com a
  regra de composição dela). Descartar tira a sugestão da lista e ela **não volta** na próxima geração.
- **D-02:** Mora no Mapeamento Estrutural do Portal, com a mesma porta de Produtos e Lista SKUs: o cliente, e a
  equipe pela entrada de equipe no portal (como o D-02 da 167). Há entrada pela tela de Produtos e pela Lista
  SKUs. O nome e a posição exata da tela são da UI-SPEC.
- **D-03:** Não duplicar. Se já existe oferta com a MESMA composição (mesmos componentes e mesmas quantidades),
  inclusive feita à mão na Lista SKUs, ela não é sugerida. Gerar de novo não duplica sugestão pendente.
- **D-04:** As ofertas que já existem não são tocadas, e as do cliente nunca são alteradas pelo gerador (como
  o D-09 da 167).

### Quais combinações (decisão do usuário em 06/10 — "faça o recomendado")
- **D-05:** **Regras duras, medidas na planilha:**
  - Kit e Combit **nunca misturam família**: 0 das 83 composições da planilha misturam.
  - Kit e Combit **exigem pelo menos um ambiente em comum**: 82 de 83 dividem.
  - Produto sem família não entra em Kit nem Combit; continua podendo ter Combo.
- **D-06:** **Pares que fazem sentido = uma lista de pares de TIPO de produto** (mesa + cadeira, mesa + banco,
  cama + criado-mudo...).
  - Só pares da lista geram Kit e Combit. A equipe ainda revisa antes de aceitar (D-01).
  - A lista é **da ECF, global para todas as empresas**, porque móveis repetem os mesmos tipos.
  - Ela nasce com os pares que a planilha real usou e cresce quando a ECF acrescenta pares.
  - **Sem IA nesta fase.**
  - Motivo: família + ambiente permitem 105 pares na planilha, e o Emerson usou 43. Uma lista curta é
    previsível e explicável ("sugeri porque mesa + cadeira está na lista").
- **D-07:** **Quantidades por TIPO, editáveis por produto:**
  - Cada tipo tem um padrão: cadeira 2/4/6, banqueta 2/3/4, mesa só 1 (mesa não tem Combo).
  - O padrão vale para Combo (×2, ×4, ×6) e para o item repetido do Combit (mesa + 4 cadeiras, mesa + 6
    cadeiras).
  - O produto pode ter as próprias quantidades, que valem no lugar do padrão.
  - Origem: a reunião citou atacado com "fit comercial" (2/4/6/8/10 cadeiras, nunca 5) e combos CB2…CB6 na
    planilha.

### Cada sugestão
- **D-08:** Mostra, antes de aceitar:
  - a composição (produto × quantidade);
  - o nome e o SKU sugeridos, editáveis;
  - a família e o ambiente que justificam;
  - a logística provável (ME1/ME2/ME2·Full) e o frete estimado.

  A logística e o frete do conjunto saem dos volumes de todos os componentes × quantidade, pelo
  `LogisticaProduto` e pela tabela de frete da ECF da 167. Nada disso é gravado como preço (D-19 da 167).
- **D-09:** Os componentes são as **ofertas Simples ligadas às variações** (167). Uma sugestão só usa variação
  que já tem oferta simples. Excluir variação que é componente segue a regra que já existe: a exclusão é
  recusada com o nome do combo.

### Dados novos
- **D-10:** O "tipo de produto" é dado novo. A pesquisa decide a fonte, com estas opções:
  - derivar da categoria do ML (167) com um nome amigável;
  - um campo "Tipo" com lista fechada da ECF, sugerido pela categoria ou pelo nome e editável.

  Exigência das duas: o cliente não pode ter trabalho extra para a maioria dos produtos, e produto sem tipo não
  entra em Kit nem Combit. Se a decisão acrescentar campo à ficha da 167, que o usuário aprovou, a mudança
  visual vai para conferência dele.
- **D-11:** Migration só ADITIVA:
  - tabelas novas para pares, quantidades por tipo e sugestões descartadas;
  - coluna nullable em tabela da 167 se precisar;
  - idempotente (`hasTable`/`hasColumn`), no padrão das migrations da 167;
  - `estrutura_ofertas` não é alterada.

### Perguntas abertas para a pesquisa (medir na planilha real, só contagens, sem nome nem custo)
1. Quantos tipos distintos os 70 produtos têm, e a quantos **pares de tipo** os 43 pares usados se reduzem.
   Isso valida o D-06: se forem poucos pares de tipo, a lista curta funciona.
2. Variações em Kit e Combit: a planilha junta a mesma cor ou valor (mesa natural + cadeira natural) ou
   combina livre? Isso define se o gerador cruza variações ou só produtos.
3. Os padrões de **nome e SKU** que a planilha usa para Combo, Kit e Combit (ex.: sufixo CB4). Vira o nome e o SKU
   sugeridos.
4. Com as regras D-05 a D-07 aplicadas aos 70 produtos reais, quantas sugestões saem **por tipo de oferta**
   contra as 46/44/39 da planilha, e quantas das ofertas reais o gerador acertaria. É o gabarito da fase.
5. A fonte do tipo (D-10): quantos dos 70 produtos têm categoria do ML confirmada ou em texto, e se a categoria
   separa bem cadeira, mesa, banqueta e banco.

### Decisões depois da pesquisa (06/10, aplicando o recomendado — confirmar no resumo antes de executar)
- **D-12: Tipo de produto (fecha o D-10).**
  - É uma lista FECHADA de tipos da ECF, inferida por palavra-chave: primeiro na categoria do ML (mesmo em
    estado "a confirmar"), depois no nome.
  - O override fica por produto, numa tabela à parte.
  - Tipo ambíguo ou ausente = "sem tipo": o produto fica fora de Kit e Combit e aparece num painel "Sem tipo"
    para a pessoa escolher.
  - **A ficha da 167 não muda.**
  - Medido: 79% dos produtos reais tipificados sem trabalho do cliente, e a categoria separa bem cadeira, mesa,
    banqueta e banco.
- **D-13: Quantidades (fecha o D-07).**
  - A semente é exatamente o D-07: cadeira 2/4/6, banqueta 2/3/4, mesa só 1.
  - A ECF amplia pela tela de admin (D-15), por exemplo cadeira ×8 ou banco 2/4, que a planilha usa.
  - Combo e Combit têm quantidades separadas por tipo.
- **D-14: Combit tem DIREÇÃO.** O par de tipos diz qual item se repete: em "mesa + cadeira", a cadeira se
  repete. Sem isso, o Combit gera 140 a 162 sugestões contra 25 da planilha.
- **D-15: Admin da ECF na v1.** Uma tela pequena para a ECF manter os tipos (palavras-chave, quantidades) e
  os pares (com a direção do Combit) sem deploy.
- **D-16: Só 2 itens por Kit e Combit na v1. Trios ficam FORA:** gerar trios proporia 124 sugestões para
  acertar 12. Trio continua sendo montado à mão na Lista SKUs.
- **D-17: Variações casam em paralelo, nunca em produto cartesiano.**
  - Casam por eixo + valor quando os dois lados têm; senão, pela ordem.
  - Produto com uma variação casa com todas as do outro.
  - Medido: a planilha nunca cruza "1 com 2".
- **D-18: Frete na lista.** Mostra só a estimativa pela tabela da ECF, marcada "estimado", mais um botão para
  cotar no ML a página visível (como na 167). Nada gravado como preço.
- **D-19: Nome e SKU sugeridos, editáveis antes de aceitar.**
  - Combo: título "Kit {N} {plural do tipo} {resto do nome}" e SKU `{simples}-CB{N}`, que já é o padrão do
    sistema.
  - Kit: "{A} + {B}" e `KT-{A}-{B}`.
  - Combit: "{fixo} + {N} {plural}" e `CT{N}-{fixo}-{repetido}`.
  - Os SKUs de Kit e Combit são suposição da pesquisa e podem ser editados.
  - Avisos: SKU acima de 120 caracteres e título acima de 60.
- **D-20: Nenhum submódulo novo no menu.** A revisão entra por Produtos e pela Lista SKUs. "Planejamento" já é
  o nome da agenda. A semente de pares (21–23 pares de tipos GENÉRICOS, sem produto, SKU nem custo) vai para o
  resumo do usuário antes da execução.

### Claude's Discretion
- Estrutura interna do gerador, desde que seja testável sem banco (regra pura, como o `LogisticaProduto`).
- Como guardar as sugestões: calculadas na hora ou persistidas. Só o descarte precisa persistir (D-01).
- Paginação e agrupamento da tela (por produto ou por família), seguindo a UI-SPEC.
- Onde a ECF edita a lista de pares e as quantidades por tipo: tela simples para admin ou seed + config. A
  edição pelo cliente não entra nesta fase.
</decisions>

<canonical_refs>
## Canonical References

### Mapeamento Estrutural (onde a fase mora)
- `app/Services/Portal/Estrutura/EstruturaOfertaService.php`: regra de composição (tabela simples/combo/kit/combit),
  `criar()` e a varredura da espera.
- `app/Models/EstruturaOferta.php`, `app/Models/EstruturaOfertaComponente.php`: fases e componentes.
- `app/Services/Portal/Estrutura/EstruturaConjunto.php`: agrupamento produto → combos e kits ("Também em").
- `resources/js/Pages/Portal/EstruturaLista.jsx`: a Lista SKUs (onde as aceitas aparecem).
- `.planning/adrs/PORTAL-01-mapeamento-estrutural-schema.md`: schema e ADR do Kit virtual.

### Fase 167 (base desta)
- `.planning/phases/167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas/167-CONTEXT.md` (D-01..D-32).
- `app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php`, `TabelaFreteEcf.php`, `FreteMe2Service.php`:
  logística, cubagem e frete. Prova real do frete em 06/10: tabela ECF a menos de 1% do ML.
- `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php`: produto, variação e oferta simples ligada.
- `.planning/learnings/portal-do-cliente.md` §31 e §32.

### Fonte da fase (FORA do repositório — NÃO commitar, NÃO imprimir nome de produto nem custo)
- `C:/xampp/htdocs/ecf_admin/3Planejamento_Estrutural_ECF.xlsx`, aba **Planejamento** (199 ofertas) e aba Produtos.
- `C:/xampp/htdocs/ecf_admin/REUNIAO_INCUBADORA.docx`: transcrição da reunião de 05/10 (F1–F4, fit comercial).
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- A regra de composição e a criação de oferta com componentes já existem (Lista SKUs). O gerador só monta as
  entradas certas para `criar()`.
- `LogisticaProduto::daVolumes()` já aceita vários volumes: um conjunto é a soma dos volumes dos componentes ×
  quantidade.
- As listas da empresa (famílias e ambientes da 167) já existem como dado estruturado: o filtro D-05 é consulta
  direta.

### Established Patterns
- Regra pura testável sem banco (`LogisticaProduto`, `NormalizadorDeLinha`) e serviço que grava em transação.
- Allowlist de rota nova em `RestringeDominioDoPortal` (`PERMITIDO` / `PERMITIDO_COM_ID`).
- Guarda do voltar do navegador por `resources/js/lib/guardaDoVoltar.js` (learnings §32), se a tela tiver
  edição não salva.

### Integration Points
- A Lista SKUs mostra as aceitas, e a Precificação as precifica pelo custo dos componentes, como já faz.
- O Publicador lê as ofertas do Mapeamento pelo "Sincronizar do Portal" (Fase 164).
</code_context>

<specifics>
## Specific Ideas

- Fala da reunião: "70 ofertas → ~200 oportunidades"; F1 individual, F2 quantidades, F3 pares e combos, F4
  conjuntos (mesa + 4/6 cadeiras).
- No sistema, essas fases são: F1 = Simples (já automática), F2 = Combo, F3 = Kit, F4 = Combit.
- Exemplo dado ao usuário: na família Farmhouse, sala de jantar, "mesa + cadeira" e "mesa + banco" fazem
  sentido; "buffet + aparador" e "banco + buffet" não.
</specifics>

<deferred>
## Deferred Ideas

- IA para decidir combinações fora da lista de pares.
- Grade de margem 30/20/10/0, tarifa por categoria e "qual promoção dá mais lucro" (tensão com o D-11 da 166).
- Cronograma por capacidade do publicador, gatilhos pós-publicação e prioridade por vendas.
- O cliente editar a lista de pares e as quantidades por tipo.
</deferred>
