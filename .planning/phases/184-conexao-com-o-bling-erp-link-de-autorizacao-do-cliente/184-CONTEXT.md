# Fase 184 — Contexto e decisões

**Data:** 2026-10-13
**Origem:** pedido do usuário (dev.01) em 13/10/2026: *"Vamos começar implementando primeiramente a
conexão de loja com o bling… O botão para se conectar ao ERP deve ficar na configuração da loja dentro
do publicador de anúncios"*, mais as quatro respostas à §9 do seed, dadas por `AskUserQuestion` na
mesma conversa.
**Pesquisa:** `.planning/seeds/261010-integracao-bling-erp.md` (10/10/2026), destilada em
`184-RESEARCH.md`. **Não refazer** — o seed mediu API v3, limites, schema e riscos.

---

## 1. O que esta fase entrega

A loja do cliente passa a estar **conectada ao Bling dele**, e o sistema passa a **ler** produtos e
estoque de lá para o cadastro de Produtos do Portal.

A conexão nasce em **Configurações da loja no Publicador**:
`/mlb/anuncios/publicador/empresas/{conta}/configuracoes`
(`MlbPublicadorEntradaController::configuracoes()`, `app/Http/Controllers/MlbPublicadorEntradaController.php:657`
→ `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx`). Hoje essa tela tem a linha
**"ERP · declarado no onboarding"** em modo **só-leitura**, montada por
`PainelVisaoGeralService::integracoes()` (`app/Services/Publicador/PainelVisaoGeralService.php:715-733`)
a partir de `ProgramasPublicadorService::erpDeclarado()`. É essa linha que ganha a ação.

---

## 2. Decisões do usuário — 13/10/2026

### D-01 — Quem autoriza: o CLIENTE, por link gerado pela equipe

O botão nas Configurações **gera um link único** que a equipe envia ao cliente (WhatsApp/e-mail); o
cliente abre o link, cai numa **landing guiada** e clica para autorizar no Bling dele.

**Isto SUBSTITUI** duas alternativas que foram apresentadas e recusadas:
- a recomendação do seed ("o cliente conecta pelo Portal") — o botão fica no Publicador, não no Portal;
- "a equipe clica e faz login no Bling do cliente" — nenhuma senha de ERP passa pela equipe.

**Consequências obrigatórias:**
- existe um estado intermediário **"aguardando o cliente"** (link gerado, ainda não autorizado);
- existe **rota pública** sem autenticação, a landing;
- a landing precisa ser **amigável no sucesso E no erro** — isso não é capricho, é **exigência da
  revisão humana da homologação do Bling** (seed §6, "Revisão humana: … tela amigável no retorno do
  OAuth (sucesso e erro)").

**Análogo a copiar, medido em 13/10/2026 — o OAuth da Shopee faz exatamente isto:**
- `ShopeeOAuthController::generateLink()` (`app/Http/Controllers/ShopeeOAuthController.php:92-106`):
  `URL::temporarySignedRoute('shopee.connect.landing', now()->addDays(7), ['company' => $company->id])`,
  com `shopee_link_generated_at` / `shopee_link_url` gravados na `companies` para o painel mostrar o
  badge e o botão "Regerar";
- `ShopeeOAuthController::connectLanding()` (`:115-147`): rota **pública** com middleware `signed`,
  que ramifica em `step = 1 | 2 | done` e renderiza a blade `oauth.shopee-connect`;
- rota: `routes/web.php:547-548`, `Route::get('/shopee/conectar/{company}')->middleware('signed')`.

O OAuth do ML tem o mesmo prazo pelo outro mecanismo (`MercadoLivreService::STATE_TTL = 604800`,
`app/Services/MercadoLivreService.php:28`, comentado *"7 dias para o cliente autorizar"*) e a porta
pública por token em `routes/web.php:154-160`.

➡️ **Decisão de desenho:** copiar o caminho da **Shopee** (link assinado + landing em blade), não o do
ML (token do workspace). Motivo: o link da Shopee é exatamente "o admin gera e manda ao cliente", que é
o pedido literal, e já carrega a expiração de 7 dias, o "Regerar" e o badge.

### D-02 — O app no Bling ainda não existe: criar nesta fase, PÚBLICO

Criar em Central de Extensões > Área do Integrador > Criar aplicativo, com um usuário que tenha a
permissão "Cadastro de aplicativos".

- Tipo **público** (até **10 contas** sem homologação).
- **Privado foi recusado**: serve só à própria conta, e migrar para público depois é cadastro novo de
  app e nova autorização de todos.
- `BLING_CLIENT_ID`, `BLING_CLIENT_SECRET`, `BLING_REDIRECT_URI` **só no `.env`** (e documentados no
  `.env.example` sem valor).
- **App de desenvolvimento separado**, com o redirect local — há **um callback por app** (seed §6).
- A **homologação** (sem a qual o 11º cliente não entra) **fica FORA desta fase**: depende de logo,
  homepage pública, manual, vídeo e de uma revisão humana do Bling sem prazo publicado. Entra como
  todo/fase própria.

### D-03 — Escopos: TODOS de uma vez

Produtos, Estoques, Depósitos, Categorias de produtos, Campos customizados, Dados básicos da empresa
**+ Produtos-Lojas, Canais de venda e Anúncios**.

**Motivo:** mudar os escopos do app **revoga TODOS os clientes já conectados** (seed §6). A lista tem
de fechar antes do primeiro cliente.

⚠️ **TENSÃO DECLARADA E ACEITA PELO USUÁRIO.** Combinada com a D-01, é **o cliente** que vê a tela de
consentimento do Bling — e ela lista os escopos, incluindo **"Anúncios"** e **"Lojas"**. Isso **expõe a
plataforma de venda** ao cliente, contra a regra de sigilo que o resto do Portal segue (`assertSemOrigem`
nos testes; risco 10 do seed). O usuário recebeu o trade-off por escrito, nas duas opções, e escolheu
pedir tudo. **Não reabrir, não "proteger" por conta própria, e não omitir escopo "para não expor"** —
omitir agora é a revogação em massa depois.

### D-04 — O combo vai ao Bling como kit com composição

Decisão tomada nesta conversa, mas **executada na Fase 185** (`POST /produtos`, `formato E`, com
`estrutura.componentes`). Fica registrada aqui porque **condiciona o schema desta fase**: a Fase 185
precisa do `bling_produto_id` do produto-base para montar `componentes[].produto.id`, logo
`bling_vinculos` tem de nascer já com esse id — o que o seed §7 já previa.

---

## 3. Fronteira: o que esta fase NÃO faz

Tudo abaixo é da Fase 2/3 do seed ou da Fase 185. Fazer qualquer um aqui é **fora de escopo**:

| Fora | Onde fica |
|---|---|
| **Qualquer escrita no Bling** (produto, kit, estoque) | Fase 185 (kits/combos) e Fase 2 do seed |
| Webhooks (`product`, `stock`, `virtual_stock`), caixa de entrada, alerta de "sem eventos" | Fase 2 do seed |
| Fotos, GTIN, marca | Fase 2 do seed |
| Kits do Bling virando Combo/Kit/Combit do Planejamento | Fase 2 do seed |
| Releitura de saldo no clique de publicar | Fase 2 do seed |
| Tarefa "vincular no Bling" em `pub_tarefas` | Fase 2 do seed |
| Painel `/bling-oauth` no Admin | Fase 2 do seed |
| **Homologação do Bling** (logo, homepage, manual, vídeo, 5 chamadas de `/homologacao/produtos`) | Fase própria; até lá, teto de 10 contas |
| Atualizar estoque **no ML** | Seed `261010-estoque-do-cadastro-como-fonte-unica.md` |
| Mexer no estoque que o Publicador manda na **criação** do anúncio | Nada muda: `available_quantity` continua indo só na criação |

---

## 4. Restrições que valem aqui

### 4.1 Dono de cada dado (seed §3) — regra do Sincronizar, estendida

- O Bling **preenche o que está vazio** e **acompanha o que ele mesmo escreveu**; o que a equipe ou o
  cliente editou no Portal **nunca** é sobrescrito.
- A memória de "quem escreveu" fica em `bling_vinculos.escrito` (mesma técnica de
  `step_state.portal_escrito`), porque as colunas do Portal não têm `origem`.
- **Preço é da ECF** (Precificação). O preço do Bling **não entra**, nunca.
- **Estoque é do Bling** com a conexão ligada: fica só-leitura na ficha, e a regra mora **no serviço**
  (então a coluna Estoque da planilha também é ignorada na variação vinculada).
- **SKU fica travado** na variação vinculada — é por ele que o Bling casa com o anúncio.
- Produto apagado/inativado no Bling **não apaga nada** no Portal: o vínculo vira `removido_no_bling`.

### 4.2 Conta divergente não grava (lição do OAuth do ML nos Polos)

No callback, comparar o CNPJ de `GET /empresas/me/dados-basicos` com `companies.cnpj`. Divergindo, a
conexão fica `divergente`, **sem token ativo**.

### 4.3 Sem Company não há conexão

A conta do Publicador resolve para `empresa-{id}` (`MlbEmpresa`) ou `company-{id}` (`Company`), e
`bling_conexoes.company_id` é FK para `companies`. Conta sem `Company` **não pode conectar** — a tela
diz o motivo, como `PainelVisaoGeralService::ultimasPublicacoes()` já faz ("D23: sem Company não há o
que mostrar"). **Nunca inventar Company.**

### 4.4 Limites do Bling são o risco operacional (seed §6)

- 3 req/s e 120 mil req/dia **por conta do cliente**, cota **compartilhada com as outras integrações
  dele**: nossa carga em massa pode derrubar o hub do cliente.
- **20 chamadas a `/oauth/token` em 60 s bloqueiam o IP por 60 min** — e **todos** os clientes saem do
  IP da VPS. Copiar o padrão do `ml:refresh-tokens` (renovar todos de uma vez) estoura isso a partir de
  20 clientes. O keep-alive tem de ser **espalhado**.
- `code` do OAuth vale **1 minuto**, é de uso único, e **reusar um code ainda válido revoga o usuário**.
- O `refresh_token` **gira** a cada renovação: gravar sempre o novo e **travar a renovação por conta**
  (`Cache::lock`) — duas renovações simultâneas derrubam a autorização.
- **Não há sandbox.** Todo teste é em conta real.

### 4.5 Nada de disparo em massa na fila `high` (risco 5 do seed)

A gravação do que vem do Bling **não** chama `PreparoIaAgenda::aoSalvar`. Se chamasse, cada varredura
agendaria, por produto, um Sincronizar e a IA na fila `high` — a mesma fila que publica anúncio e manda
o código de acesso do Portal (learnings `publicador-ml.md` §22). Importar 500 produtos geraria centenas
de cadeias.

### 4.6 Sigilo nos textos novos

Nenhum texto novo do **Portal** cita a plataforma de venda (`assertSemOrigem`). Isso vale para a landing
pública e para qualquer aviso que o cliente leia. ⚠️ Não é contornável pela D-03: a tela de consentimento
é do **Bling** e não está sob nosso controle; os **nossos** textos continuam sob a regra.

### 4.7 Convenções do projeto que o schema tem de respeitar

Do `CLAUDE.md` e de `.planning/learnings/desempenho-bonificacao.md` §6, e das memórias de MariaDB:
- **sem `enum`** (CHECK do SQLite quebra os testes), **sem `json`**, sem `timestamp()` solto;
- nome de índice e de FK com **menos de 64 caracteres** (MariaDB recusa com 1059 e deixa a tabela
  criada SEM o índice, migration Pending);
- `foreignId()->constrained()->nullOnDelete()` exige `->nullable()` explícito (MariaDB 1830; SQLite
  não pega);
- migration **idempotente** com `hasTable`, e **provada no MariaDB local** — não só no SQLite dos testes;
- comentários em **pt-BR**.

---

## 5. Perguntas que esta fase NÃO pode responder sozinha

Vão para o checkpoint humano, não para o executor adivinhar:

1. **O `.env` de produção** com `BLING_CLIENT_ID`/`SECRET` só o usuário coloca. Sem isso o fluxo não
   roda em produção (o código tem de degradar com mensagem clara, não com 500).
2. **As 9 medições da §10 do seed** exigem a conta Bling de teste e não podem ser inferidas do código:
   se a API funciona em conta trial, o formato real de `variacao.nome`, físico × virtual, quantos
   `idsProdutos[]` cabem por chamada, se o `companyId` do webhook é o id de `dados-basicos`, se o
   refresh gira, se `fornecedor` grava custo, se `filtroSaldoEstoque` omitido traz só positivos, se as
   medidas são do produto embalado, e se `/produtos/lojas` reflete o vínculo com o ML.
   ➡️ O plano deve tratar cada uma como **suposição declarada com fallback**, nunca como fato.
3. **Primeira empresa a conectar de verdade.** O seed registra: se a conta de teste for ligada ao ML,
   só à **#459 (MGSTOREL, loja REAL)** — e aí o Bling passa a mandar estoque **e preço** aos anúncios
   vinculados.

---

## 6. Coordenação com a outra sessão

Outra sessão do Claude Code está na **Fase 180** (layout da tela de Publicações do Publicador), na
**mesma árvore de trabalho**. Conferido em 13/10/2026:

- A 180 mexe em `PublicacoesDaListaService`, `Publicacoes.jsx`, componentes em
  `Components/Mlb/Publicador/publicacoes/` e no acervo. **Nada disso é território desta fase.**
- Esta fase mexe em `Configuracoes.jsx` e em `PainelVisaoGeralService::integracoes()`, que a milestone
  v26.0 declarou **fora** do pacote de redesign (HEAD-15: essas telas ficam com "header novo e corpo
  antigo"). Logo **não há disputa de arquivo**.
- **Encosto real:** `routes/mlb_anuncios.php` e `routes/web.php` (só acréscimos) e a própria árvore —
  `deploy.sh` aborta com qualquer arquivo rastreado modificado, e o deploy publica o trabalho das duas
  sessões juntas.
- ➡️ **Commitar só os caminhos desta fase (`git commit -- <paths>`), nunca `git add -A`.** Em 13/10
  havia `app/Http/Controllers/MlbAnuncioController.php` modificado e não commitado, que é WIP da outra
  sessão: **não tocar, não stashar.**
- **Deploy e push para a `main` precisam de autorização explícita** para esta fase.
