---
phase: 168
slug: geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
status: approved
reviewed_at: 2026-10-06
shadcn_initialized: false
preset: none
created: 2026-10-06
---

# Fase 168 — Contrato de Design da UI

> Contrato visual e de interação de duas telas: **Sugestões de ofertas** (Portal do Cliente → Mapeamento Estrutural) e
> **Tipos e pares** (admin da ECF, sistema interno). Gerado por gsd-ui-researcher, a ser verificado por gsd-ui-checker.
> Escrito em pt-BR.
> Fontes: 168-CONTEXT.md (D-01..D-20), 168-RESEARCH.md (PR168-01..15), 167-UI-SPEC.md (revisões D-23 e D-25..D-31, APROVADAS
> em 06/10), 167-REFERENCIA-VISUAL.md, learnings `portal-do-cliente.md` §31 e §32, e o código real da 167
> (`BarraAcoesProdutos.jsx`, `CartaoProdutoGrande.jsx`, `CartaoVariacao.jsx`, `PecasDoProduto.jsx`, `comum.jsx`, `Janela.jsx`,
> `Pages/Dev/Desenvolvimento.jsx`).

**Princípio do contrato:** a pessoa é leiga e vai decidir sobre ~170 ofertas. A tela tem de ser **decisão item a item,
em cartões, com botões claros**. **NADA de grade tipo planilha** (D-23 da 167, learnings §31): sem `SpreadsheetGrid`, sem
células editáveis em linha, sem colar do Excel. Nome e código editáveis são campos de formulário dentro do cartão.
A tela tem de parecer da mesma família da tela de Produtos: mesmos cartões, mesmas cores `ecf-*`, mesmo tom.
Calma visual: sem placar de progresso, sem alarme vermelho, sem rótulo em CAIXA ALTA.

---

## Nome, rota e entradas

| Item | Contrato |
|------|----------|
| Nome da tela | **Sugestões de ofertas** ("Planejamento" é o nome da agenda; não usar) |
| Rota | `GET /portal/estrutura/sugestoes` (Inertia, `PortalClienteLayout titulo="Sugestões de ofertas"`) |
| Página React | `resources/js/Pages/Portal/EstruturaSugestoes.jsx` (componentes em `Components/Portal/Estrutura/Sugestoes/`) |
| Menu lateral | **Nenhum submódulo novo (D-20).** A página usa a chave ativa `estrutura.produtos` |
| Entrada 1 | Tela **Produtos**: sexta ação da linha de ações, depois de "Sugerir categorias": `Sparkles` + **Sugestões de ofertas**. Estilo `SECUNDARIA` (o amarelo continua sendo "Adicionar produto"). Só aparece quando a empresa tem produtos |
| Entrada 2 | Tela **Lista SKUs**: link secundário no cabeçalho, mesmo texto e ícone, estilo `LINK_SECUNDARIO` já usado ali |
| Contador no botão | **Não.** Gerar sugestões só para pintar um número em toda abertura de Produtos/Lista custa caro e vira placar. O número aparece dentro da tela |
| Admin (ECF) | `GET /dev/estrutura-geracao`, página `resources/js/Pages/Dev/EstruturaGeracao.jsx`, `AppLayout`, grupo `role:admin` |
| Entrada do admin | Link no cartão de ferramentas da página `Dev/Desenvolvimento` ("Tipos e pares das sugestões de ofertas"). Sem item novo no menu lateral |

Topo da página (mesmo desenho da ficha da 167, **não** a trilha ampla de Produtos): rótulo "Mapeamento Estrutural", breadcrumb
`← Produtos › Sugestões de ofertas`, título 24px, uma linha de descrição:
"Combinamos os seus produtos em Combo, Kit e Combit. Você escolhe o que vira oferta. Nada é criado sozinho."
No canto, `Como funciona` (fantasma, o `ComoFunciona` existente) abre a mesma explicação do bloco "Os três tipos".

---

## Design System

| Propriedade | Valor |
|-------------|-------|
| Tool | none (`components.json` ausente; primitivos shadcn-style já existem em `Components/ui/*`) |
| Preset | não se aplica |
| Biblioteca de componentes | Radix UI via `Components/ui/*` (`dialog`, `checkbox` opcional) + `Janela` do módulo; `<input type="checkbox">` nativo com a classe de `FormAnuncio.jsx` é aceito |
| Biblioteca de ícones | `lucide-react` (`Sparkles`, `Check`, `X`, `Undo2`, `Pencil`, `Tag`, `ChevronDown`, `Truck`, `AlertTriangle`, `Loader2`, `Plus`, `Trash2`, `ArrowLeft`, `Link2` — este só no admin) |
| Fonte | a do `PortalClienteLayout`; títulos com `font-display`; números e códigos com `tabular-nums` / `font-mono` |
| Grade | **nenhuma.** Não usar `SpreadsheetGrid` |
| Peças reaproveitadas (não reinventar) | `PortalClienteLayout`, `Botao`, `Campo`, `CLASSE_INPUT`, `Seletor`, `AvisoFlash`, `Paginacao rotulo="sugestões"`, `Janela`, `ComoFunciona`, `PilulaLogistica`, `fmtReais`, `guardaDoVoltar.js` |
| Peças novas | `CartaoSugestao`, `CabecalhoFamilia`, `FiltrosSugestoes`, `BarraDeMarcadas`, `ExplicacaoDasOfertas`, `PainelSemTipo`, `JanelaTipo`, `ListaDescartadas`, e no admin `DevCard` extraído |
| `DevCard` | Hoje é função local de `Pages/Dev/Desenvolvimento.jsx`. Extrair **sem mudar o markup** para `resources/js/Components/Dev/DevCard.jsx` (export default) e importar nas duas páginas. Mudança aditiva, sem alteração visual em `Desenvolvimento.jsx` |

Casca da página do Portal (igual à de Produtos): `div.mx-auto.w-full.max-w-[1600px].px-4.pb-10.pt-6.sm:px-6.lg:pl-10.lg:pr-8`.
**Gutter de 16px a 390px** (`px-4`), nenhum filho com largura fixa maior que o contêiner; todo texto longo com `min-w-0` e
`truncate`/quebra. Com a barra fixa de marcadas, a página ganha `pb-28`.

---

## Escala de Espaçamento

Valores declarados (múltiplos de 4):

| Token | Valor | Uso |
|-------|-------|-----|
| xs | 4px | espaço entre ícone e texto, entre chips |
| sm | 8px | gap entre botões do cartão, entre linhas da composição |
| md | 16px | padding de cartão (`p-4`), gutter mobile, gap entre cartões (`gap-4`) |
| lg | 24px | respiro entre grupos de família, padding do estado vazio |
| xl | 32px | espaço acima do cabeçalho de cada família (desktop) |
| 2xl | 48px | respiro do estado vazio de página inteira |
| 3xl | 64px | não usado |

Exceções: alvo de toque **44px** (`h-11`) em campos, botões do cartão, abas, checkboxes (área clicável 44x44 com caixa visual de
20px) no celular; **48px** (`h-12`) só na linha de ações do topo, herdada de `BarraAcoesProdutos`; `px-5`/`h-12` e botões 15px
dessa linha são herança da 167 e ficam como estão. No admin (sistema interno, só computador), `Editar` em `h-9` (36px). As pílulas em `h-7` (28px) valem nas duas telas, porque não são alvo de toque principal. Nada de `p-3`/`gap-3` novos (12px) nesta fase.

---

## Tipografia

Exatamente **4 tamanhos e 2 pesos** para o que é novo. (`Botao`, `CabecalhoEstrutura` e a linha de ações da 167 herdam os
próprios pesos e tamanhos; é dívida aceita da 167, não reescrever.)

| Papel | Tamanho | Peso | Altura de linha |
|-------|---------|------|-----------------|
| Corpo (texto do cartão, composição, campos, botões) | 14px | 400 | 1.5 |
| Rótulo (acima do campo, nome do filtro, texto de botão) | 12px | 600 | 1.4 — caixa normal, nunca uppercase |
| Apoio (por quê, "estimado", Ref/SKU, avisos, contagens, dicas, pílulas) | 12px | 400 | 1.4 |
| Título (nome do cartão no cabeçalho de família e de bloco, título de janela e de estado vazio) | 17px | 600 | 1.2 |
| Display (h1 da página) | 24px | 600 | 1.2 |

Botões novos: 14px, peso 600. Código (SKU/Ref) em `font-mono` 12px 400. Números pt-BR (`R$ 78,65`, vírgula decimal), `tabular-nums`
nas quantidades ("4 ×").

---

## Cor

| Papel | Valor | Uso |
|-------|-------|-----|
| Dominante (60%) | `ecf-bg` `#050507` | fundo da página |
| Secundária (30%) | `ecf-card` `#0f1116` + `border-white/[0.08]` | cartões de sugestão, bloco explicativo, janelas, barra de marcadas |
| Acento (10%) | `ecf-yellow` `#ffe600` | LISTA FECHADA abaixo |
| Destrutivo | `red-300` texto / `red-500/10` fundo / `red-500/30` borda (`Botao variante="perigo"`) | admin: excluir tipo, excluir par. Mensagem depois de tentar: erro de aceite de um cartão e validação inline sob o campo, depois de tentar salvar |

**Acento reservado para (e nada mais):**
1. o botão **"Aceitar {N} marcadas"** da barra fixa (o único amarelo da vista de sugestões);
2. dentro de uma janela, o botão de confirmar daquela janela ("Salvar tipo", "Salvar par", "Descartar {N}");
3. no admin, o botão de confirmar da janela e o ícone do `DevCard` (já é assim no sistema);
4. foco de campos (`focus:border-ecf-yellow/40`), a página atual da `Paginacao` e o contorno de foco do teclado;
5. o CTA único do estado vazio "sem produtos" (**Ir para Produtos**): nessa vista não há barra de marcadas.

O botão **"Aceitar" de cada cartão é secundário** (`border border-white/[0.10] bg-white/[0.03] text-white/85`), senão haveria 20
amarelos por página.

Pílulas (12px/600 nas de fase; apoio nas demais; `rounded-lg h-7 px-2`):

| Elemento | Estilo |
|----------|--------|
| Fase Combo / Kit / Combit | neutra `bg-white/[0.06] text-white/70` (a diferença é o TEXTO, não a cor) |
| Logística ME2 · Full / ME2 / ME1 / Pendente | exatamente as de `PilulaLogistica` da 167 (emerald / sky / neutro / apagado) |
| Tipo do produto ("Cadeira") | `bg-white/[0.04] text-white/60`, com lápis pequeno; clicável (abre `JanelaTipo`) |
| "estimado" | texto apoio `text-white/45`, sem pílula |
| Aviso de risco (título > 60, SKU repetido, produto sem medida) | `text-amber-300` 12px com `AlertTriangle` 14px; só para risco explícito |
| Erro de aceite no cartão (depois de tentar) | `text-red-300` 12px, `role="alert"`: "Não aceitamos esta sugestão: {motivo}." |

Vermelho nunca aparece em campo, em sugestão pendente ou em contagem. Só aparece como mensagem DEPOIS de tentar:
- validação inline sob o campo, depois de tentar salvar (`JanelaTipo` e as janelas do admin);
- erro de aceite no cartão;
- o botão Excluir do admin.

---

## A tela Sugestões de ofertas — estrutura vertical

1. **Topo** (rótulo, breadcrumb, h1, descrição, `Como funciona`).
2. **`AvisoFlash`** (resultado do aceite, descarte, restauração; ver Copywriting).
3. **Bloco "Os três tipos"** (`ExplicacaoDasOfertas`): cartão `rounded-2xl border border-white/[0.08] bg-ecf-card p-4`, três linhas
   (pílula da fase + frase de 14px). Aberto na primeira visita, depois recolhido (estado no `localStorage`, chave própria);
   recolhido vira uma linha "Os três tipos de oferta" com `ChevronDown`. Textos fixos:
   - **Combo**: "o mesmo produto em mais unidades — Kit 4 cadeiras."
   - **Kit**: "produtos diferentes juntos — mesa + banco."
   - **Combit**: "um kit com mais unidades de um item — mesa + 4 cadeiras."
4. **Abas** (links Inertia, `nav aria-label="Seções"`, `aria-current="page"` na ativa; query `aba`): `Sugestões ({N})` ·
   `Sem tipo ({N})` · `Descartadas ({N})`. Contagens vêm do servidor sobre o CONJUNTO inteiro, não da página.
   Estilo: botões `h-11 rounded-[10px]`, ativa `bg-white/[0.08] text-white`, inativas `text-white/60`; no celular quebram em
   grade `grid-cols-3` sem rolagem horizontal (rótulo curto: "Sem tipo" e "Descartadas" cabem a 390px em 12px/600).
5. **Conteúdo da aba** (seções abaixo).
6. **`Paginacao rotulo="sugestões"`**, 20 por página, filtros e página no servidor (learnings §25/§27: nunca filtrar no navegador
   sobre a página).
7. **`BarraDeMarcadas`** fixa no rodapé enquanto houver marcadas.

Preservar ao voltar da ficha de produto ou do aceite: aba, filtros, busca, página, rolagem (`preserveScroll`, `preserveState`).

---

## Aba "Sugestões"

### Filtros (`FiltrosSugestoes`)

Uma linha com `flex-wrap gap-2` no desktop, empilhada no celular:

| Controle | Contrato |
|----------|----------|
| Fase | quatro botões segmentados: `Todas ({N})` · `Combo ({N})` · `Kit ({N})` · `Combit ({N})`; contagens do conjunto filtrado por família/tipo/busca; celular: grade `grid-cols-2`, cada botão `h-11` |
| Família | `Seletor` nativo, primeira opção "Todas as famílias", cada opção com contagem: "Farmhouse (32)"; celular largura total `h-11` |
| Tipo | `Seletor` nativo, "Todos os tipos" |
| Busca | input igual ao de Produtos (`rounded-[10px] border-white/[0.10] bg-white/[0.03]`, `Search` à esquerda, X para limpar), placeholder "Buscar produto, código ou nome…", debounce 350 ms, no servidor; desktop `lg:w-[336px] lg:ml-auto` |
| Limpar filtros | link apoio 12px, só quando há filtro ativo |
| Marcar | checkbox "Marcar as {k} desta página" (k = visíveis); e, quando há filtro e o conjunto filtrado tem N > 0, o botão de texto "Marcar todas as {min(N,100)} do filtro". O servidor manda `chaves_filtradas` (até 100 chaves); o JS não calcula nada. Passando de 100: "Marcamos as primeiras 100. Aceite e marque as próximas." |
| Frete | botão fantasma "Consultar fretes desta página no Mercado Livre" (`Truck`), só com conta ML conectada e ao menos uma sugestão ME2 na página; sob consulta vira "Consultando…" com `Loader2` e fica `disabled` |

### Agrupamento por família

A lista é agrupada por **família** (a pesquisa mediu que uma família concentra a maioria das sugestões). Dentro da família, ordem
fixa do servidor: fase Combo, Kit, Combit, depois chave (a página não "anda").

`CabecalhoFamilia` (acima de cada grupo, `mt-8` desktop / `mt-6` celular, `lg:` primeiro grupo sem margem):
- nome da família em 17px/600 e, ao lado, apoio "{k} nesta página · {N} no total";
- checkbox "Marcar as {k} desta família nesta página";
- sugestões sem família não existem para Kit/Combit; Combos de produto sem família ficam num grupo final **"Sem família"** com a
  frase de apoio "Sem família só entra em Combo. Escolha a família na ficha do produto.";
- família continuada em outra página repete o cabeçalho com "(continua)".

Os grupos **não recolhem**: a decisão é em bloco, mas a pessoa vê o que está aceitando.

### Cartão de sugestão (`CartaoSugestao`)

Grade `grid-cols-1 xl:grid-cols-2 gap-4` (1 coluna até 1279px, 2 colunas a partir daí). Cartão
`relative rounded-[14px] border border-white/[0.08] bg-ecf-card p-4` (`border-ecf-yellow/50` quando marcado; contorno, não fundo).
`<article aria-labelledby>` com `data-sugestao data-chave`.

Anatomia, de cima para baixo:

1. **Linha de topo:** checkbox (44x44 de área) · pílula da fase · pílula do tipo(s) · à direita, nada (as ações ficam embaixo).
2. **Composição** (a parte mais importante, 14px, `divide-y divide-white/[0.06]`): uma linha por componente:
   `{N} ×` (`tabular-nums`, peso 600) · nome do produto · valor da variação em apoio ("Natural") · Ref em `font-mono` 12px
   apoio. Quantidade 1 aparece como "1 ×". Quando as duas variações têm o mesmo valor, o valor aparece uma vez no fim da linha de
   título do cartão, não repetido. Nome do produto com `min-w-0 truncate` e dica (`title`) com o nome inteiro.
3. **O porquê** (apoio 12px, `text-white/60`, uma ou duas linhas, tradução de `motivo` do servidor, o JS só exibe):
   - Combo: "Mesmo produto em mais unidades. Tipo: Cadeira (2, 4, 6)."
   - Kit: "Mesma família: {família}. Ambiente em comum: {ambiente(s)}. Par: mesa + cadeira."
   - Combit: "Mesma família: {família}. Ambiente em comum: {ambiente(s)}. Par: mesa + cadeira; a cadeira se repete."
4. **Campos** (formulário, `grid grid-cols-1 sm:grid-cols-[1fr_220px] gap-2`; celular uma coluna): 
   - `Nome da oferta` (input, rótulo 12px/600 acima, `h-11 sm:h-9`, valor sugerido);
   - `Código (SKU)` (input `font-mono`, mesmo tamanho).
   Edição só em estado local da página (sem gravar até Aceitar). Campo editado ganha um ponto discreto "editado" (apoio) e
   `Desfazer edição` (link apoio) devolve o valor sugerido.
   Avisos (apoio 12px, abaixo do campo):
   - título acima de 60: "O título passa de 60 caracteres ({n}). O Mercado Livre pode cortar." (âmbar, não bloqueia);
   - SKU acima de 120: "O código passa de 120 caracteres. Encurte para poder aceitar." (âmbar, **desabilita Aceitar e a marcação em lote desta sugestão** com a razão em `title` e `aria-describedby`);
   - SKU igual ao de outra oferta: "Já existe uma oferta com este código na Lista SKUs. Você pode aceitar assim mesmo." (âmbar).
5. **Logística e frete** (linha de apoio, `flex-wrap gap-x-4 gap-y-1`): `PilulaLogistica` · "Frete {R$ 78,65} estimado" (valor 14px/600, "estimado" apoio) · para ME1: "Frete pela sua transportadora" (copy da 167);
   Pendente: "Faltam medidas em {nome do produto}. Complete na ficha." com link para `/portal/estrutura/produtos/{id}`. Após consultar: o valor
   troca e o apoio passa a "Mercado Livre"; falha: texto da 167, valores seguem "estimado". Conjunto ME1 não mostra frete. Nada
   aqui é gravado como preço.
6. **Ações** (`flex gap-2`, celular `grid grid-cols-2`, ambos `h-11`):
   - `Aceitar` (secundário, `Check`, `aria-label="Aceitar sugestão {nome}"`): aceita só esta; vira "Aceitando…" com `Loader2`, `disabled` durante o envio;
   - `Descartar` (fantasma, `X`, `text-white/60`, `aria-label="Descartar sugestão {nome}"`): sai da lista na hora (sem confirmação para um) e o `AvisoFlash` oferece `Desfazer`.
   Os dois botões ficam `disabled` enquanto houver aceite em lote em andamento.

Um cartão com erro mostra a mensagem do servidor no lugar do apoio de logística (`role="alert"`) e continua na lista para a pessoa
corrigir e tentar de novo. "Já existia" (corrida) tira o cartão e entra na contagem do aviso.

### Seleção múltipla e barra (`BarraDeMarcadas`)

- Marcação por cartão, por grupo (da página) ou pelo "Marcar todas do filtro". A seleção **sobrevive a mudar de página e filtro** (estado
  do componente, guardado por `chave`), com teto de **100**. Tentar a 101ª: o checkbox não marca e aparece "Você pode aceitar até
  100 de uma vez. Aceite estas e marque as próximas." (`role="status"`).
- Barra fixa no rodapé (`fixed inset-x-0 bottom-0 z-30 border-t border-white/[0.10] bg-ecf-card/95 backdrop-blur`, `pb-[env(safe-area-inset-bottom)]`),
  só visível com 1 ou mais marcadas, dentro da mesma largura da casca. `role="region" aria-label="Ações para as sugestões marcadas"`.
  - Desktop: `{N} marcadas` (14px/600, `aria-live="polite"`) · `Limpar marcação` (link) · à direita `Descartar {N}` (secundário) · **`Aceitar {N} marcadas`** (amarelo).
  - Celular (390px): linha 1 `{N} marcadas` à esquerda, `Limpar` à direita; linha 2 dois botões lado a lado, `Aceitar {N}` (amarelo, `flex-1`) e `Descartar` (secundário, `flex-1`), ambos `h-11`.
- Aceitar em lote envia só `{chave, nome, sku}` (nunca a composição). Resultado único no `AvisoFlash`:
  "{N} ofertas criadas. Elas já estão na Lista SKUs." + link `Ver na Lista SKUs`; se houver: " {X} já existiam e saíram da lista." e
  " {Y} não puderam ser criadas; veja os cartões marcados."; as com erro permanecem marcadas.
- `Descartar {N}` com N maior que 1 abre `Janela` de confirmação (ver Copywriting). Com N igual a 1 age direto.

### Guarda do voltar (learnings §32)

Há "alteração não salva" quando existe nome ou SKU editado em alguma sugestão que ainda não foi aceita nem descartada. Nesse caso:
`guardaDoVoltar.js` protege o botão Voltar do navegador, o link `← Produtos`, as abas, os filtros que mudam a URL e a paginação, com
a confirmação **"Há nomes ou códigos editados que ainda não foram aceitos. Sair sem aceitar?"** (botões `Continuar editando` e
`Sair sem aceitar`). Só marcar cartões **não** conta como alteração (é barato refazer). Ao trocar filtro/página dentro da tela, as edições
são mantidas em memória e a guarda não dispara; ela só dispara ao SAIR da tela.

---

## Aba "Sem tipo" (`PainelSemTipo`)

Para Kit e Combit o produto precisa de um tipo (D-12). A escolha é feita **aqui**, não na ficha da 167 (a ficha não muda).

- Introdução (apoio 14px `text-white/70`, no topo): "Para sugerir Kit e Combit, precisamos saber o que cada produto é. Escolha o tipo dos produtos abaixo. Quem fica sem tipo continua podendo ter Combo."
- Lista de cartões, um por produto (`grid-cols-1 xl:grid-cols-2 gap-4`, mesmo cartão `rounded-[14px] border bg-ecf-card p-4`):
  - nome (17px/600, `truncate`), "Família · Ambiente(s)" (apoio; sem família: "Sem família. Kit e Combit precisam de família, escolha na ficha." com link), categoria do ML em apoio com `Tag`;
  - quando a inferência achou mais de um tipo: apoio "Pode ser Banco ou Banqueta." (e as duas opções vêm primeiro no select);
  - `Seletor` nativo "Tipo do produto" (rótulo 12px/600, `h-11`, primeira opção "Escolha o tipo…");
  - botão secundário **`Definir tipo`** (`h-11`), `disabled` sem escolha. Gravar é por botão, não ao mudar o select;
  - link apoio `Ajustar quantidades` abre `JanelaTipo` (mesma janela da pílula de tipo do cartão).
- Após definir: o cartão sai do painel, o `AvisoFlash` diz "Tipo definido: {Tipo}. As sugestões de Kit e Combit foram atualizadas." e as contagens das abas mudam.
- Paginação no servidor (`Paginacao rotulo="produtos"`), busca e filtro de família iguais aos da aba Sugestões.
- Estado vazio: "Todos os produtos têm tipo".

### `JanelaTipo` (`Janela`, `largura="max-w-md"`)

Título "Tipo de {produto}". Campos (uma coluna, `h-11`):
- `Tipo` (select nativo, inclui "Sem tipo" para limpar a escolha);
- `Quantidades de Combo` (texto, placeholder = padrão do tipo, ex.: "2, 4, 6"), dica "Deixe vazio para usar o padrão do tipo. Digite 0 para não gerar Combo."
- `Quantidades de Combit` (idem), dica "Quantas unidades deste item entram na mesa + cadeiras. Deixe vazio para o padrão. Digite 0 para não gerar."
- Validação inline (apoio, `text-red-300` só após tentar salvar): "Use números inteiros de 2 a 999, separados por vírgula."
Rodapé: `Cancelar` (secundário) · **`Salvar tipo`** (amarelo). Quem só quer o tipo e nenhuma quantidade deixa as quantidades vazias.

---

## Aba "Descartadas" (`ListaDescartadas`)

- Introdução (apoio): "Estas sugestões saíram da lista e não voltam sozinhas. Restaure as que quiser rever."
- Cartões **compactos** (sem campos editáveis, sem logística): pílula da fase, composição em uma linha ("1 × Mesa + 4 × Cadeira", nomes truncados), família (apoio), "Descartada em dd/mm", botão secundário `Restaurar` (`Undo2`, `h-11`).
- Marcação e barra: reuso da `BarraDeMarcadas` com o botão `Restaurar {N}` **secundário** (`border border-white/[0.10] bg-white/[0.03]`). A barra desta aba NÃO tem amarelo: restaurar não cria nada, só devolve à lista.
- Restaurar devolve a sugestão à aba Sugestões (se a composição já virou oferta nesse meio tempo, ela não volta: aviso "Já existe uma oferta com esta composição.").
- Estado vazio: "Nenhuma sugestão descartada".

---

## Estados da tela

| Estado | Contrato |
|--------|----------|
| Carregando (troca de filtro/página) | cartões atuais com `opacity-60`, `aria-busy="true"`; sem skeleton; spinner só nos botões |
| Empresa sem produtos | título "Cadastre seus produtos primeiro"; corpo "As sugestões nascem dos produtos que você cadastrou. Cadastre ao menos um produto com família, ambiente e medidas."; CTA (amarelo, é o único da vista) **Ir para Produtos** |
| Sem sugestões, mas com produtos | título "Ainda não há sugestões novas"; corpo "Para sugerir Kit e Combit, os produtos precisam de família, ambiente e tipo. Veja a aba Sem tipo ou cadastre mais produtos."; CTA secundário `Ver Sem tipo` (quando N maior que 0) |
| Filtro sem resultado | "Nada com esses filtros." + `Limpar filtros` |
| Tudo revisado | título "Você revisou todas as sugestões"; corpo "As ofertas aceitas já estão na Lista SKUs."; link `Ver na Lista SKUs` |
| Muitas sugestões (teto) | faixa apoio âmbar acima da lista: "Há muitas sugestões. Mostramos as primeiras {teto}. Escolha uma família para ver o restante." |
| Falha de rede ao aceitar/descartar | "Não foi possível salvar agora. Suas edições continuam na tela. Tente de novo." (`role="alert"`, nada se perde) |
| Aceite duplo clique | botão `disabled` durante o envio; a segunda resposta "já existia" é tratada como acima |

Os estados vazios usam o mesmo desenho do estado vazio de Produtos (cartão tracejado, título 17px/600, corpo 14px, CTA).

---

## Contrato de Copywriting

| Elemento | Texto |
|----------|-------|
| Nome da tela / botão de entrada | **Sugestões de ofertas** |
| Descrição do topo | Combinamos os seus produtos em Combo, Kit e Combit. Você escolhe o que vira oferta. Nada é criado sozinho. |
| CTA primário da tela (barra de marcadas) | **Aceitar {N} marcadas** (com N = 1: "Aceitar 1 marcada") |
| CTA por cartão | Aceitar · Descartar |
| CTA do estado vazio (sem produtos) | Ir para Produtos |
| Combo | o mesmo produto em mais unidades — Kit 4 cadeiras. |
| Kit | produtos diferentes juntos — mesa + banco. |
| Combit | um kit com mais unidades de um item — mesa + 4 cadeiras. |
| Estado vazio (sem produtos), título | Cadastre seus produtos primeiro |
| Estado vazio (sem produtos), corpo | As sugestões nascem dos produtos que você cadastrou. Cadastre ao menos um produto com família, ambiente e medidas. |
| Estado vazio (sem sugestões), título | Ainda não há sugestões novas |
| Estado vazio (sem sugestões), corpo | Para sugerir Kit e Combit, os produtos precisam de família, ambiente e tipo. Veja a aba Sem tipo ou cadastre mais produtos. |
| Aceite com sucesso | {N} ofertas criadas. Elas já estão na Lista SKUs. (N = 1: "1 oferta criada.") |
| Aceite com parte já existente | {X} já existiam e saíram da lista. |
| Aceite com erros | {Y} não puderam ser criadas. Veja os cartões que continuam marcados. |
| Erro de um cartão | Não aceitamos esta sugestão: {motivo}. |
| Descarte (1) | Sugestão descartada. [Desfazer] |
| Descarte em lote, confirmação | **Descartar {N} sugestões?** Elas saem da lista e não voltam sozinhas. Você pode restaurá-las na aba Descartadas. Botões: `Cancelar` · `Descartar {N}` |
| Restaurar | {N} sugestão(ões) restaurada(s). (singular: "1 sugestão restaurada.") |
| Frete | Frete {R$} estimado · Frete pela sua transportadora (ME1) · Faltam medidas em {produto}. Complete na ficha. |
| Frete, botão | Consultar fretes desta página no Mercado Livre |
| Frete, conta não conectada | Conecte sua conta do Mercado Livre para ver o frete real. (dica, igual à da 167) |
| Frete, falha | Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa. |
| Título longo | O título passa de 60 caracteres ({n}). O Mercado Livre pode cortar. |
| SKU longo | O código passa de 120 caracteres. Encurte para poder aceitar. |
| SKU repetido | Já existe uma oferta com este código na Lista SKUs. Você pode aceitar assim mesmo. |
| Limite do lote | Você pode aceitar até 100 de uma vez. Aceite estas e marque as próximas. |
| Sem tipo, introdução | Para sugerir Kit e Combit, precisamos saber o que cada produto é. Escolha o tipo dos produtos abaixo. Quem fica sem tipo continua podendo ter Combo. |
| Tipo definido | Tipo definido: {Tipo}. As sugestões de Kit e Combit foram atualizadas. |
| Quantidades inválidas | Use números inteiros de 2 a 999, separados por vírgula. |
| Guarda do voltar | Há nomes ou códigos editados que ainda não foram aceitos. Sair sem aceitar? (`Continuar editando` · `Sair sem aceitar`) |
| Falha de rede | Não foi possível salvar agora. Suas edições continuam na tela. Tente de novo. |
| Descartadas, introdução | Estas sugestões saíram da lista e não voltam sozinhas. Restaure as que quiser rever. |

Tom (igual ao da 167): "você", frases curtas, verbo no começo do botão, sem exclamação, sem emoji, sem "obrigatório!". As
siglas ME1/ME2 têm dica (`title`) com os textos da 167. Nunca escrever "algoritmo", "gerador" ou "IA" na tela.

---

## Celular (390px) — mesmo padrão da 167

- Gutter **16px** (`px-4`), sem rolagem horizontal em nenhuma aba (conferir a 390px com `scrollWidth <= clientWidth`).
- Tudo em **1 coluna**; cartões a `gap-4`; grupos com `mt-6`.
- Alvos de toque 44px: abas, filtros de fase (`grid-cols-2`), selects, checkboxes, `Aceitar`/`Descartar` do cartão (`grid-cols-2`), botões da barra.
- Campos Nome e SKU em coluna única, `h-11`, `text-[14px]` (sem zoom automático do iOS: 16px só se o teste de zoom reprovar, decisão do executor documentada).
- Composição: cada linha quebra em duas (nome em cima, valor e Ref embaixo em apoio). Nada de coluna fixa.
- Barra de marcadas em duas linhas (ver acima), `safe-area-inset-bottom`, página com `pb-28`.
- Janelas (`Janela`): `max-h-[90vh] overflow-y-auto`, largura limitada ao viewport menos 32px.
- Sem hover como única forma de acesso: toda dica (`title`) tem equivalente visível ou é redundante.

---

## Acessibilidade e movimento

- Cada cartão é `<article aria-labelledby>` apontando para o nome editável (rótulo visível "Nome da oferta"); checkbox com `aria-label="Marcar sugestão {nome}"`.
- Abas e filtros de fase como links/botões com `aria-current`; contagens fazem parte do texto acessível.
- Barra de marcadas: `role="region"`, contagem em `aria-live="polite"`. Resultado de aceite/descarte em `role="status"` (`AvisoFlash`); erro de cartão em `role="alert"`.
- Pílula sempre com TEXTO (fase, logística, tipo), nunca só cor.
- Foco visível: `focus-visible:ring-2 ring-ecf-yellow/40`. Janelas devolvem o foco ao botão que as abriu (Radix já faz).
- Texto de apoio mínimo `text-white/45` sobre `ecf-card` para informação secundária; informação necessária (por quê, avisos, composição) usa `text-white/60` ou mais.
- Sem animação nova; respeitar `prefers-reduced-motion`; `animate-spin` só no carregamento de botões.

---

## Tela de admin da ECF — Tipos e pares (`/dev/estrutura-geracao`)

Fora do Portal: `AppLayout`, tema escuro do sistema interno, `DevCard` (extraído, markup idêntico), só `role:admin`. Cliente nunca vê
(D-15; o cliente não edita tipos nem pares nesta fase). Não é planilha: **listas e janelas**.

Casca: `AppLayout`, `div.mx-auto.max-w-5xl.space-y-4.px-4.py-6 sm:px-6`. Título 24px/600 "Tipos e pares das sugestões de ofertas"; apoio:
"Valem para todas as empresas. A mudança aparece na próxima vez que alguém abrir Sugestões de ofertas. Ofertas já criadas não mudam."

### `DevCard` "Tipos de produto" (ícone `Tag`)

- Subtítulo: "Cada tipo tem palavras-chave para o sistema reconhecer o produto e as quantidades que entram em Combo e Combit."
- Botão `Novo tipo` (secundário, `Plus`) no topo do cartão.
- Lista (`divide-y divide-white/[0.06]`, uma linha-cartão por tipo, ordenada pelo campo `ordem`): 
  - nome (15px do `DevCard`; novo texto seguindo a tipografia desta fase: 14px/600) e plural em apoio;
  - palavras-chave em chips apoio (`rounded-full bg-white/[0.06] px-2 text-[12px]`), no máximo 6 visíveis e "+{n}";
  - "Combo: 2, 4, 6 · Combit: 2, 4, 6" (apoio; `nenhuma` quando `0`); 
  - ações `Editar` (secundário pequeno `h-9`) e `Excluir` (texto vermelho apoio).
- `Janela` "Novo tipo" / "Editar tipo" (`max-w-md`), campos `h-11`: `Nome` (ex.: "Cadeira"), `Plural` (ex.: "Cadeiras", usado nos títulos), `Palavras-chave` (dica: "Separe por vírgula. O sistema procura estas palavras na categoria e no nome do produto. Ex.: cadeira, poltrona."), `Quantidades de Combo` e `Quantidades de Combit` (dica: "Ex.: 2, 4, 6. Digite 0 para este tipo não ter Combo/Combit. Vazio = nenhuma."). O identificador (slug) não é editável e aparece em apoio.
  Rodapé `Cancelar` · **`Salvar tipo`** (amarelo).
- Excluir (única ação destrutiva, `Botao variante="perigo"` na janela): **Excluir o tipo {Nome}?** "Os pares que usam este tipo também serão removidos, e os produtos deste tipo voltam para Sem tipo. Ofertas já criadas não mudam." Botões `Cancelar` · `Excluir tipo`.

### `DevCard` "Pares de tipos" (ícone `Link2`)

- Subtítulo: "Só os pares desta lista geram Kit e Combit. Para o Combit, diga qual item se repete."
- Botão `Novo par` (secundário, `Plus`).
- Lista, uma linha por par: "Mesa + Cadeira" (14px/600) e apoio "Kit · Combit: a Cadeira se repete" / "Só Kit" / "Combit: os dois se repetem"; ações `Editar`, `Excluir`.
- `Janela` "Novo par" / "Editar par": `Primeiro tipo` e `Segundo tipo` (selects nativos; o mesmo tipo nos dois lados é permitido, ex.: cama + cama), e grupo de rádios "No Combit": `Não gerar Combit (só Kit)` · `Repetir {Primeiro tipo}` · `Repetir {Segundo tipo}` · `Repetir os dois`. Os rótulos de `Repetir…` usam os nomes escolhidos (atualizam ao vivo). Rodapé `Cancelar` · **`Salvar par`** (amarelo).
- Par repetido: "Este par já existe. Edite o que está na lista."
- Excluir: **Excluir o par {A} + {B}?** "Kits e Combits novos deixam de ser sugeridos para este par. Ofertas já criadas não mudam." Botões `Cancelar` · `Excluir par`.

Estado vazio das duas listas: "Nenhum tipo cadastrado" / "Nenhum par cadastrado" + o botão `Novo tipo` / `Novo par`.
Mensagens de sucesso no `flash` do sistema ("Tipo salvo.", "Par salvo.", "Tipo excluído.", "Par excluído.") e erro de validação inline `text-red-300` 12px
sob o campo. Um amarelo por vista: nenhum amarelo na página, só nas janelas (os botões de abrir são secundários).
Celular: uma coluna, linhas empilhadas, botões `h-11`, sem rolagem horizontal; as janelas seguem o padrão acima.

---

## Registry Safety

| Registry | Blocos usados | Safety Gate |
|----------|---------------|-------------|
| shadcn official | nenhum novo (só `Components/ui/dialog` já existente) | não exigido |
| Terceiros | nenhum | não se aplica; sem `components.json` |

Nenhuma dependência nova de npm.

---

## Pré-preenchido de

| Origem | Decisões usadas |
|--------|-----------------|
| 168-CONTEXT.md | D-01, D-02, D-03, D-05..D-08, D-12..D-20 (mostrar composição, porquê, logística, frete estimado, nome e SKU editáveis, painel Sem tipo, admin) |
| 168-RESEARCH.md | PR168-07, 08, 09, 10, 11, 12, 14 (paginação 20, lote até 100, avisos 60/120, chaves, tela admin) |
| 167-UI-SPEC / código | cores `ecf-*`, cartões `rounded-[14px]`, `PilulaLogistica`, `Janela`, `Botao`, tom do texto, gutter 16px, guarda do voltar |
| Pedido do usuário (06/10) | sem planilha na tela, nome "Sugestões de ofertas", textos de Combo/Kit/Combit |

## Decisões que o planejador e o usuário devem confirmar

1. **Nome:** "Sugestões de ofertas" (alternativas: "Ofertas sugeridas"). Escolhido por verbo-no-começo e por não colidir com "Planejamento".
2. **Sem contador no botão de entrada** (custo de gerar em toda abertura de Produtos/Lista). Se o usuário quiser o número, o servidor precisa de contagem em cache curto.
3. **"Marcar todas do filtro"** exige que o servidor envie `chaves_filtradas` (até 100 chaves). Se o planejador cortar isso, restam só o checkbox da página e o do grupo.
4. **Link do admin** dentro de `Dev/Desenvolvimento` (sem item de menu) e a extração do `DevCard` para `Components/Dev/DevCard.jsx`.
5. **Tipo do produto fora da ficha da 167** (D-12). Nenhuma mudança visual na ficha aprovada; o tipo é escolhido no painel "Sem tipo" e na pílula de tipo do cartão.
6. Fora do contrato por decisão do CONTEXT: trios, grade de margem, IA, edição de pares pelo cliente.

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
