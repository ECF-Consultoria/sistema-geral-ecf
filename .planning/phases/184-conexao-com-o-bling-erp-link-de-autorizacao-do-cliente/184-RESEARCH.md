# Fase 184 — Pesquisa

**Data:** 2026-10-13
**Procedência:** esta pesquisa **não foi refeita**. Ela é a destilação, recortada ao escopo da Fase 184,
de `.planning/seeds/261010-integracao-bling-erp.md` (10/10/2026 — duas pesquisas paralelas: API v3 do
Bling e encaixe no nosso código; fontes no fim daquele arquivo). O que foi **medido nesta sessão
(13/10/2026)** são os análogos do nosso próprio código, marcados abaixo com `file:line`.

**Confiança:** o que vem do seed sobre a API do Bling é **documentação lida**, não execução — o seed
marca explicitamente o que não está confirmado com **(medir)**, e nada disso virou fato. Os análogos
internos são **ALTA** (lidos no arquivo nesta sessão).

---

## 1. O achado que decide o desenho da fase

**O padrão que o usuário pediu já existe no repositório, pronto para copiar.** O OAuth da Shopee
implementa exatamente "o admin gera um link, o cliente abre e autoriza":

| Peça | Onde | O que faz |
|---|---|---|
| Gerar o link | `app/Http/Controllers/ShopeeOAuthController.php:92-106` | `URL::temporarySignedRoute('shopee.connect.landing', now()->addDays(7), ['company' => $company->id])`; grava `shopee_link_generated_at` e `shopee_link_url` em `companies`; devolve `url`/`generated_at`/`expires_at` em JSON para o painel atualizar o badge na hora |
| A landing pública | `app/Http/Controllers/ShopeeOAuthController.php:115-147` | Ramifica em `step = 1 \| 2 \| done` e renderiza a blade `oauth.shopee-connect`, passando `button_url` e `button_label` |
| A rota | `routes/web.php:547-548` | `Route::get('/shopee/conectar/{company}')->middleware('signed')` — pública, **sem** `auth` |
| O painel admin | `app/Http/Controllers/ShopeeOAuthController.php:29-44` | Lista empresas com o estado do link e o botão "Regerar" |

Prazo de 7 dias: é a mesma escolha do ML, onde está escrita como constante —
`MercadoLivreService::STATE_TTL = 604800` (`app/Services/MercadoLivreService.php:28`), comentada
*"7 dias para o cliente autorizar"*.

➡️ **Consequência para o plano:** a Fase 184 **não inventa** fluxo de link. Ela instancia o fluxo da
Shopee para o Bling. Divergência deliberada: o link da Shopee é por `{company}` na URL com assinatura;
aqui vale o mesmo, porque `bling_conexoes.company_id` é FK para `companies`.

⚠️ **Mas o `state` do Bling é diferente do da Shopee.** O Bling **ignora** `redirect_uri` e `scope`
enviados na URL (valem os do cadastro do app) e **exige** `state` não vazio. E há **um callback por
app**. Então: o `state` é o que amarra empresa + ambiente + destino de volta, e tem de ser aleatório,
de uso único e guardado em cache — o padrão do `MercadoLivreService`, não o da Shopee.

---

## 2. API v3 do Bling — só o que esta fase usa

### 2.1 OAuth (seed §6)

- **Authorize (navegador do cliente):**
  `GET https://www.bling.com.br/Api/v3/oauth/authorize?response_type=code&client_id=…&state=…`
  `redirect_uri` e `scope` na URL são **ignorados**. `state` **não pode ir vazio**.
- **Token:** `POST https://api.bling.com.br/Api/v3/oauth/token`
  - `Authorization: Basic base64(client_id:client_secret)` — as credenciais **só** no header.
  - Corpo `form-urlencoded`: `grant_type=authorization_code&code=…`.
  - Header `enable-jwt: 1` na troca e em **toda** renovação.
  - ⚠️ A doc escreve `/oauth/token` e `/oauth/revoke` **sem** `/Api/v3`; essas formas **dão 403**
    (testado pelo seed em 10/10).
- **Resposta:** `access_token` (exemplo: 21600 s = 6 h), `refresh_token` (30 dias), `scope` (ids
  numéricos).
- **`code`:** vale **1 minuto**, uso único. **Reusar um code ainda válido REVOGA o usuário.**
- **Renovação:** o refresh **gira** (fonte: comunidade — `bling-sdk`). Gravar sempre o novo e
  **travar por conta** (`Cache::lock`): duas renovações simultâneas derrubam a autorização. 30 dias
  sem renovar = o cliente reautoriza.
- **JWT:** 1.500 a 3.000 caracteres → coluna `TEXT`, nunca `varchar`. O token opaco está
  "descontinuado", data de corte "em definição". Relato de out/2026: o JWT funciona sem o header e o
  token opaco **com** o header dá 401. **(medir)** — nascer só com JWT.
- **Revogar:** `POST /Api/v3/oauth/revoke` com `token` e `token_type_hint`.
- **Identificar a empresa:** `GET /empresas/me/dados-basicos` → id da empresa (é o `companyId` dos
  webhooks **(medir)**) e o **CNPJ**, que é o que a conferência de divergência compara.
- **Falhas:** inadimplência ou permissão perdida → `UNAUTHORIZED_ERROR`. Empresa inativa não renova.
  Escopo insuficiente → **403 `insufficient_scope`**.

### 2.2 Limites (seed §6) — o risco operacional da fase

| Gatilho | Efeito |
|---|---|
| 3 req/s · 120.000 req/dia **por conta do cliente** | Cota **compartilhada com as outras integrações dele**; só o plano Elite segrega |
| 300 erros em 10 s | IP bloqueado 10 min |
| 600 req em 10 s | IP bloqueado 10 min |
| **20 chamadas a `/oauth/token` em 60 s** | **IP bloqueado 60 min** |
| Reincidência | Duração indeterminada |

⚠️ **Todos os clientes saem do IP da VPS** — um bloqueio derruba todos de uma vez. Não há headers de
limite na resposta: o ritmo é controle nosso. O 429 traz `TOO_MANY_REQUESTS` com `limit` e `period`.

### 2.3 Produtos e estoque — o que a fase lê

- `GET /produtos` — listagem **resumida**; paginação por `pagina`/`limite` (padrão 100, máximo não
  documentado); **não vem total**, itera até a página vazia ou menor. Filtro de período > 1 ano dá 400.
  `criterio=2` = ativos; `tipo` em PS/C/E. `filtroSaldoEstoque` **(medir)** — o schema diz padrão 1
  (positivo).
- `GET /produtos/{id}` — a ficha completa (a listagem não serve).
- `GET /estoques/saldos?idsProdutos[]=…` — `saldoFisicoTotal`, `saldoVirtualTotal`,
  `depositos[{id, saldoFisico, saldoVirtual}]`. **Quantos ids cabem por chamada: (medir)** (~50–100).
- `GET /depositos` — flags `padrao` e `desconsiderarSaldo`.
- **Não existe "alterado desde" no saldo:** não há histórico de movimentação nem filtro de data.
  Mudança de estoque só chega por webhook (Fase 2) ou **varredura**.
- **Custo de referência medido no seed** para 500 produtos / 800 SKUs: importação inicial ~510 req
  (~8,5 min a 1 req/s); estoque a cada 30 min = 8–16 chamadas por varredura.

### 2.4 Mapa de campos Bling → Portal

O mapa completo está no seed §4 e **não se repete aqui**. O que o plano precisa guardar:

- **O vínculo é pelo `id` do Bling, não pelo SKU** — o SKU não identifica (lição de `EstruturaOferta`).
- `codigo` → `estrutura_produto_variacoes.codigo` (a "Ref", única por empresa). Código vazio ou
  repetido no Bling = **linha recusada na prévia**.
- `formato S` → 1 produto + 1 variação; `formato V` → 1 produto + N variações (o pai **não tem
  estoque**); `formato E` = kit (entra na Fase 2, não aqui).
- `variacao.nome` vem como `"Cor:Azul;Tamanho:M"`. **Um atributo** passa por
  `NormalizadorDeLinha::eixo()`; **dois ou mais não cabem** (o Portal tem 1 eixo por variação) e entram
  como "outro" com valor `"Azul · M"` + aviso.
- `dimensoes` com `unidadeMedida` 0 = m, 1 = cm, 2 = mm; **profundidade = comprimento**. `volumes > 1`
  → a medida **não entra** + aviso. Se as medidas são do produto **embalado**: **(medir)**.
- `preco` do Bling **não entra** — o preço é da Precificação.
- `categoria.id` é a árvore **do cliente** e **não casa** com a categoria do ML. Texto nunca vira id.

---

## 3. Onde isso encosta no nosso código (medido em 13/10/2026)

### 3.1 A tela que recebe o botão

- `MlbPublicadorEntradaController::configuracoes()` — `app/Http/Controllers/MlbPublicadorEntradaController.php:657-679`.
  Renderiza `Mlb/Publicador/Configuracoes` com `empresa`, `identidade`, `conexoes`, `programa`,
  `responsavel`, `cabecalho`.
- `PainelVisaoGeralService::integracoes()` — `app/Services/Publicador/PainelVisaoGeralService.php:715-733`.
  Devolve `mercado_livre`, `publicacao_liberada`, `alavancas_liberada`, `portal` e
  `erp => ['valor', 'rotulo']`. ⚠️ O docblock avisa que `ProgramasPublicadorService::erpDeclarado()` é
  **fonte única** do ERP declarado e que já houve duas implementações divergentes — **não duplicar**.
- `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx:82` — a linha
  `<LinhaSoLeitura rotulo="ERP">` com `erpValor ? '… · declarado no onboarding' : 'Não informado'`.
  O comentário de cabeçalho (`:24-25`) diz: *"ERP é apenas DECLARADO no onboarding (decisão 8 do
  handoff) — nunca mostrar como 'conectado'"*. ➡️ **Esta fase revoga esse comentário para o Bling** (e
  só para ele): declarado continua declarado; conectado é estado novo. O comentário tem de ser
  atualizado, não contornado.
- `LinhaSoLeitura` (`:39-46`) é, por contrato do arquivo, **só-leitura** — *"nunca um botão de editar
  aqui dentro"*. ➡️ O botão do Bling **não** entra dentro de `LinhaSoLeitura`: é um bloco próprio.

### 3.2 O padrão de token guardado

`app/Models/MlToken.php:25-32` — `'access_token' => 'encrypted'`, `'refresh_token' => 'encrypted'`,
`protected $hidden = ['access_token', 'refresh_token']`. É o padrão que `bling_conexoes` copia.

### 3.3 O caminho único de escrita de produto

- `ProdutoCadastroService::gravarLinhas(Company $empresa, array $linhas, AtorDoPortal $ator, string $modo)`
  — `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:106`, com
  `MODO_IMPORTACAO` (`:82`) e limite `MAX_IMPORTACAO` (`:108`).
- `ImportadorProdutos::previa()` / `::aplicar()` —
  `app/Services/Portal/Estrutura/Produtos/ImportadorProdutos.php:68` e `:113`; o `aplicar()` chama
  `gravarLinhas(..., MODO_IMPORTACAO)` na linha `:133`. É o molde de "prévia antes de gravar".
- `NormalizadorDeLinha` (mesma pasta) é o contrato de linha que o tradutor do Bling tem de produzir.

### 3.4 O problema do ator na varredura — **resolver no plano, não no código às cegas**

- `AtorDoPortal` (`app/Support/Portal/AtorDoPortal.php:23-39`) tem **construtor privado** e só dois
  nascimentos: `::cliente(PortalUsuario)` e `::daEquipe(User)`. O campo `equipe` é `readonly bool`.
- `RegistroEstrutura::registrar(AtorDoPortal $ator, …)`
  (`app/Services/Portal/Estrutura/RegistroEstrutura.php:19-26`) grava
  `'origem' => $ator->equipe ? 'interno' : 'cliente'` e usa `causedBy()`.
- ➡️ **Uma varredura agendada não é cliente nem equipe.** O seed §7 já apontou: precisa de registro com
  `origem = 'bling'` e **sem causer**. Isso exige um terceiro nascimento (ex.: `AtorDoPortal::sistema()`)
  ou um caminho de registro que aceite causer nulo. **Decisão de desenho que o plano tem de tomar
  explicitamente** — não deixar o executor escolher, e não reaproveitar `::daEquipe()` com um usuário
  qualquer (isso falsifica o histórico, que é exatamente o que o docblock do `AtorDoPortal` diz existir
  para evitar).

### 3.5 Fila: nunca a `default`, nunca `high` em massa

- Risco 5 do seed: a gravação do Bling **não** chama `PreparoIaAgenda::aoSalvar` — senão cada produto
  agenda Sincronizar + IA na fila `high`, a mesma que publica anúncio e manda o código do Portal
  (learnings `publicador-ml.md` §22).
- Memória do projeto: **a fila de produção é Redis e a `default` vive congestionada** (179 jobs de
  acervo seguraram um webhook por horas em 16/09). O comando de varredura roda em **processo próprio**,
  com `withoutOverlapping`, `onOneServer` e `runInBackground` — precedente `polos:warm`.

---

## 4. Riscos vivos nesta fase

| # | Risco | Mitigação que o plano deve conter |
|---|---|---|
| 1 | **Bloqueio do IP da VPS derruba TODOS os clientes** | Limitador por conta **e** global por IP, no Redis; keep-alive espalhado (≤ ~10 renovações/min); nunca renovar em rajada |
| 2 | **Reusar um `code` revoga o usuário** | `state` de uso único em cache; nunca reprocessar callback; idempotência por `state` consumido |
| 3 | **Duas renovações simultâneas derrubam a autorização** | `Cache::lock` por conta na renovação; gravar o refresh novo **sempre** |
| 4 | **Mudar escopo revoga todos** | A lista fecha **antes** do primeiro cliente (D-03 do CONTEXT) |
| 5 | **Sem sandbox:** todo teste é em conta real | `Http::fake` no mesmo commit; a prova real é checkpoint humano na conta de teste |
| 6 | **A cota é do cliente** | Ritmo ≤ 1 req/s em carga de massa; a varredura não pode competir com o hub dele |
| 7 | **Conta divergente** (CNPJ ≠ `companies.cnpj`) | Status `divergente`, **sem token ativo** — lição do OAuth do ML nos Polos |
| 8 | **Link assinado vazado** = alguém conecta um Bling qualquer naquela empresa | Validade de 7 dias + conferência de CNPJ no callback + "Regerar" invalidando o anterior |
| 9 | **Teto de 10 contas** sem homologação | A fase **declara** o teto na tela e o conta; a homologação é fase própria |
| 10 | **Sigilo** | `assertSemOrigem` nos textos novos; a tela de consentimento do Bling é fora do nosso controle (tensão aceita, D-03) |

---

## 5. Suposições declaradas — o que NÃO está medido

Nenhuma destas pode entrar no código como fato. Cada uma precisa de **fallback** e de um item no
checkpoint humano (origem: seed §10, exige a conta Bling de teste):

1. A API funciona em **conta trial**, e um app em desenvolvimento autoriza **outra** conta.
2. O formato real de `variacao.nome`, e qual saldo vem ao consultar o **pai**.
3. Físico × virtual; quantos `idsProdutos[]` cabem por chamada; o máximo de `limite`.
4. O `companyId` do webhook é o id de `dados-basicos` (importa para a Fase 2).
5. O refresh **gira** a cada renovação; o `enable-jwt` é necessário nas chamadas comuns.
6. `filtroSaldoEstoque` omitido traz **só** os positivos.
7. As `dimensoes` do Bling são do produto **embalado**.
8. `/produtos/lojas` reflete o vínculo com o ML em conta que usa a "Gestão de Anúncios".

**Massa mínima de teste** (seed §10): 1 produto simples, 1 com variação de cor, 1 com cor × tamanho,
1 kit virtual, 2 depósitos, 1 campo customizado.

**Se a conta de teste for ligada ao ML, só à #459 (MGSTOREL, loja REAL)** — e aí o Bling passa a mandar
estoque **e preço** aos anúncios vinculados.

---

## Validation Architecture

Como esta fase se prova. **O princípio:** nada que dependa da API real do Bling pode ser gate
automático (não há sandbox); logo os gates automáticos cobrem **o nosso lado**, e a API real vira
**checkpoint humano declarado**.

### Camada 1 — Unidade, sem rede (gate automático)

- **Tradutor Bling → linhas do Portal**: função pura, testada com payloads de `formato S` e `formato V`
  copiados da doc. Casos obrigatórios: `codigo` vazio → linha recusada; `codigo` repetido → recusada;
  `variacao.nome` com **dois** atributos → eixo "outro" + aviso; `unidadeMedida` 0/1/2 → cm;
  `volumes > 1` → medida **não** entra; `preco` do Bling **ausente** do resultado (asserção negativa).
- **Montagem da URL de authorize**: `state` presente e não vazio; `redirect_uri`/`scope` **não**
  enviados (asserção negativa — eles são ignorados pelo Bling e mandá-los esconde erro de cadastro).
- **Normalização de saldo**: negativo → 0; soma por depósitos escolhidos; `NULL` ≠ `0`.

### Camada 2 — HTTP fingido (gate automático, `Http::fake`)

- Troca de `code` por token: `Authorization: Basic` presente, credenciais **ausentes** do corpo,
  `enable-jwt: 1` presente, URL **com** `/Api/v3`.
- **401 → renova uma vez, e só uma.** **429 → espera, não insiste.**
- Renovação concorrente: duas chamadas simultâneas → **uma** requisição ao Bling (prova do
  `Cache::lock`).
- `code` já consumido → **não** chama o Bling de novo (prova da idempotência por `state`).
- CNPJ divergente em `dados-basicos` → conexão `divergente` e **nenhum token gravado** (asserção
  negativa na tabela).

### Camada 3 — Feature (gate automático)

- O link: só quem tem o papel gera; o link é `temporarySignedRoute`; **assinatura inválida → 403**;
  "Regerar" invalida o anterior.
- A landing: rota **pública** (sem `auth`), `signed` aplicado, e os três estados (a conectar,
  aguardando, conectado) renderizam **sem** 500 quando o `.env` está vazio.
- Sem `Company` → a tela diz o motivo e **não** oferece o botão.
- Sigilo: `assertSemOrigem` em todo texto novo que o cliente lê.

### Camada 4 — Banco (gate automático)

- Migration **idempotente** (`hasTable`) e **provada no MariaDB local**, não só no SQLite: sem `enum`,
  sem `json`, nomes de índice/FK < 64 caracteres, `nullable()` explícito antes de `nullOnDelete()`.
- `access_token`/`refresh_token` em `TEXT` com cast `encrypted` e `$hidden`: um teste lê a linha crua e
  afirma que **não** contém o token em claro.

### Camada 5 — Checkpoint humano (NÃO automatizável, declarado)

Itens que exigem a conta Bling de teste e o `.env` preenchido pelo usuário:

1. Criar o app no Bling, com os escopos da D-03 fechados, e registrar o Client ID/Secret.
2. Autorizar de verdade pela landing e conferir que o token chegou, que o CNPJ casou e que a tela passou
   a "conectado".
3. Rodar as 8 medições da §5 e **registrar o resultado** — cada uma que divergir da suposição vira
   correção, não surpresa em produção.
4. Conferir o teto de 10 contas e abrir o item de homologação.

### O que explicitamente NÃO tem gate

- **A homologação do Bling** (revisão humana, sem prazo) — fora da fase.
- **O comportamento real da API em conta trial** — é a suposição 1, e nenhum teste nosso a prova.
- **A tela de consentimento do Bling** — fora do nosso controle (tensão da D-03).
