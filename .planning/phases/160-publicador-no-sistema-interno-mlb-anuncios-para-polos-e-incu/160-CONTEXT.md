# Fase 160 — Publicador no sistema interno (/mlb/anuncios), para Polos e Incubadora — CONTEXT

**Coletado em:** 2026-10-02, sessão direta com o usuário (sem discuss-phase formal — as decisões abaixo vieram de pergunta explícita, registradas em `.planning/publicador-ml-spec/17-publicador-interno.md`, D12–D19).
**Por que é fase GSD:** a migration altera `pub_rascunhos`, que já tem dado em produção — 2 rascunhos de teste da empresa #459 "Dev 02 Testes API" (CLAUDE.md, "GSD obrigatório"; escolha explícita do usuário, D19).

## O problema

O Publicador do Mercado Livre foi construído em 01–02/10 dentro do **Portal do Cliente** (Anunciar, piloto só #459, em produção em `13eedbb8` → `675e6c49`). Depois de usá-lo, o usuário decidiu que publicar não é coisa do cliente:

- vai ter API de gerar imagens (custo e controle da ECF);
- o módulo atende **dois programas**, Polos e Incubadora, e a equipe publica por eles.

Portanto o Publicador vira o assistente de `/mlb/anuncios` no sistema interno. O que o cliente preparou no Portal (Lista SKUs, títulos planejados na aba Anúncios, Precificação) entra **já preenchido**. Empresa sem Portal tem os produtos cadastrados no próprio Publicador.

## O que já funciona e NÃO deve mudar

- **O motor do Publicador** — `app/Support/Publicador/*` (núcleo puro: variações, schema, imagens, payload UP, L1/L2, mapeador de erros), `app/Services/Publicador/*` (repositório, conta, schema, imagens, conferência L3, publicação em fatias com SENT antes do POST e reconciliação por SKU), tabelas `pub_*`, Jobs `ConferirRascunhoJob`/`PublicarRascunhoJob` na fila `high`. 230 testes em `tests/Unit/Publicador` + `tests/Feature/Publicador`. A spec `.planning/publicador-ml-spec/` (00–16) continua valendo.
- **As outras abas de `/mlb/anuncios`** — Meus Anúncios (`MeusAnuncios.jsx`), Em massa (`AnunciarMassa.jsx` + `GradeAnuncioGlide.jsx`, `publicar-lote`), Histórico (`AnunciosHistorico.jsx`) — e o motor ANTIGO que elas usam (`app/Services/Mlb/Publicacao/*`, `MlAnuncioRascunho`, `PublicarAnuncioMlJob`), que também é usado pelo Portal antigo, pelo acervo e pela IA.
- **O token do ML com duas âncoras** — `ContaMercadoLivre` (Company e MlbEmpresa), `ml_tokens.mlb_empresa_id` desde 21/09, `MercadoLivreService::ensureValidToken(ContaMercadoLivre)`.
- **O Portal do Cliente** fora do Anunciar: Lista SKUs, Precificação, Anúncios, Planejamento, Mapeamento — e o menu em trilho com Dock (`b2f243c0`/`ef6bd3a2`).
- **Conta de cliente nunca recebe publicação de teste**: só a #459 (memória `feedback_conta_cliente_nunca_publicar`); confirmação do usuário antes de cada `POST /items` real.

## Decisões travadas

### D12 — O Publicador sai do Portal e vira o assistente de `/mlb/anuncios`
Rotas em `routes/mlb_anuncios.php` (grupo `auth, verified, role:admin`, prefixo `mlb/anuncios`). O motor é o mesmo; muda o ponto de entrada e quem usa.

### D13 — Ao entrar, escolhe-se Polos ou Incubadora
Polos e Incubadora são `MlbEmpresa` (`tipo = 'INCUBADORA'` ou `fase`/`projeto` via `MlbEmpresa::FASE_PARA_PROJETO`; ver `EmpresaOperacionalRouter.php:389-394`). O programa **não é gravado** em tabela nova: deriva da `MlbEmpresa`. A lista mostra as empresas do programa com conta do ML (token por `company_id` ou `mlb_empresa_id`).

### D14 — Troca SÓ o assistente individual
`AnunciarML.jsx` (wizard, ~2.900 linhas) e a rota `wizard` dão lugar ao Publicador. Meus Anúncios, Em massa e Histórico ficam como estão. O "Anunciar por IA" (`PainelAnunciarIa.jsx`, `GerarAnaliseAnuncioIaJob`, `RascunhoAnuncioIaService`) vira **botão dentro do Publicador** — o que a IA gera precisa cair no rascunho NOVO (`pub_*`), não em `MlAnuncioRascunho`.

### D15 — Empresa sem Portal: produtos cadastrados no Publicador
535 de 539 `MlbEmpresa` de Polos não têm `Company`; o Portal pendura tudo em `Company` (`estrutura_ofertas.company_id`). Tabela nova **`pub_produtos`** (desenho no doc 17 §3.1): `mlb_empresa_id` nullable, `company_id` nullable, `oferta_id` nullable **unique** (vínculo com o Portal), `sku`, `nome`, `origem` (`portal` · `publicador`). O schema vai POR ESCRITO no plano antes da migration; armadilhas de MariaDB do learnings §6 (nomes curtos, sem `nullOnDelete`, `--path` no MariaDB local; verificação real é `SHOW INDEX`/`SHOW CREATE TABLE`, não o SQLite dos testes).

### D16 — "Sincronizar do Portal" é LIGADO
Produto com `oferta_id` herda título planejado e preço da Precificação ao vivo (`DadosEfetivosService` + `RascunhoSnapshot::comEfetivos()` — já é assim); o que a equipe digita vence. O botão só cria produtos para ofertas novas (idempotente, nunca apaga). Publicar cadastra o MLB na aba Anúncios **só quando há `oferta_id`**.

### D17 — Acesso: só admins
Como hoje (`role:admin` no grupo). `Permissions::MLB_ANUNCIAR` e o módulo `homologacao` ficam como estão.

### D18 — O Anunciar sai do Portal para TODOS os clientes
Quando o interno estiver no ar: sai o piloto (`PortalPublicadorController`, rotas `portal/estrutura/ofertas/*/publicador*`, linhas da allowlist de `RestringeDominioDoPortal`) **e** o formulário antigo do par (`/estrutura/anunciar`, `EstruturaPublicacaoService` como tela). O submódulo "Anunciar" sai do menu do Mapeamento Estrutural (`ModulosPortal`). Lista SKUs, Precificação, Anúncios, Planejamento e Mapeamento ficam.

### D19 — Fase GSD completa
Baseline de testes antes de mexer, VERIFICATION no fim.

### Alteração em `pub_rascunhos` (decorre de D15)
`produto_id` FK `pub_produtos` **unique**; `oferta_id` passa a nullable; os 2 rascunhos existentes ganham o seu `pub_produto` (origem `portal`, `company_id` 459, `oferta_id` atual) **na mesma migration**, sem perda. `oferta_id` NOT NULL hoje com FK `pubr_oferta_fk` e unique `pubr_oferta_uq` (migration `2026_10_01_200000_create_publicador_tables.php`).

### Decisões da pesquisa (2026-10-02, perguntas Q1–Q4 do 160-RESEARCH §8)

Medido em produção (só leitura, 02/10): a #459 **não** tem `MlbEmpresa`; 603 `MlbEmpresa` ativas, só **3** da Incubadora (por `projeto`/`fase`; `tipo='INCUBADORA'` = 0), só **5** com `company_id` (ligadas ao Portal), **34** tokens ML ativos por `mlb_empresa_id`; **4** rascunhos antigos abertos em `ml_anuncio_rascunhos`.

### D20 — A Dev 02 entra como empresa da Incubadora
Criar em produção uma `MlbEmpresa` "Dev 02 Testes API" no programa Incubadora, ligada à `Company` 459 (o teste real passa pelo caminho de verdade, inclusive "Sincronizar do Portal" — a #459 tem 5 ofertas). É escrita em produção: tarefa com o usuário presente, pré-requisito do E2E, não da fase. Cuidado da memória: preencher `email_cliente`/`cnpj`/`nome_contato` da #459 pode disparar contrato real (`CompanyGatilhoContratoObserver`) — não tocar nesses campos.

### D21 — Trava por conta, liberada uma a uma
A regra "conta de cliente nunca recebe publicação de teste" continua com trava no servidor: lista de contas liberadas por `company_id` **e** `mlb_empresa_id` (generaliza `publicador.empresas_piloto`), começando só pela #459. O usuário libera os clientes depois do teste real. `PublicacaoTest` "fora do piloto não publica" é preservado/adaptado.

### D22 — O assistente antigo fica escondido nesta fase
`wizard`/`AnunciarML.jsx` saem da entrada principal (o Publicador ocupa o lugar e a aba "Individual"), mas continuam abrindo os rascunhos antigos (`MlAnuncioRascunho`) e o "Anunciar semelhante" do Histórico. Remoção em fase própria. As suítes do wizard continuam valendo.

### D23 — Três programas no seletor: Polos | Incubadora | Gestão
As contas da consultoria (`Company` com token, fora de Polos/Incubadora) são o terceiro programa: continuam com Meus Anúncios, Em massa e Histórico e também usam o Publicador. Para `MlbEmpresa` sem `Company`, as abas que dependem de `{company}` (Meus/Massa/Histórico) ficam escondidas ou desabilitadas nesta fase.

### D24 — Layout NOVO, do zero, para o sistema interno
O "Anunciar (Redesign Focado)" (seções 01–08 + trilho à direita) foi feito para o **Portal**. Em `/mlb/anuncios` o layout é outro, desenhado do zero para ferramenta interna (usuário, 02/10: "para o /mlb/anuncios tem que ser outro. gerar novo layout do zero"). Reaproveita-se a LÓGICA (estado do servidor, `CampoAtributo`, `EditorDeEixos`, `GradeVariantes`, `FotosPorGrupo`, `Problemas`, `apoio.js`), não a composição da tela. Valem as lições do piloto: nada de rodapé fixo alto com lista de pendências, nada de abas com contadores de aviso como estrutura principal, respiro, obrigatório à vista e opcional recolhido, estados calmos (sem vermelho enquanto se preenche). Identidade: ECF Admin Dark.

### D25 — Layout aprovado: as duas telas do Stitch (02/10)
Projeto "ECF Admin — Identidade" (`15646202289570387715`), design system ECF Admin Dark. Usuário: "por enquanto serve, principalmente o editor".
- **Editor** (tela `3c7f275a9e9046338d1f6d2854b19c64`, TRAVADO): barra do topo (empresa + conta ML, "Salvo há Xs", Anunciar por IA, Conferir no ML, Publicar), faixa horizontal com os produtos da empresa e o estado de cada um, coluna principal em cartões (produto/categoria; ficha técnica com obrigatórios em blocos e opcionais recolhidos; um cartão por variação com estoque/SKU/código/fotos e Clássico | Premium lado a lado; logística; descrição), coluna direita (validação do ML com prontidão e pendências calmas; resumo do lote com o botão de publicar).
- **Entrada** (tela `6179e11dac77472884f60d724cdec375`, direção): seletor de programa **com Gestão** (D23), busca, indicadores, tabela de empresas (conta ML, situação do Portal, produtos, publicados) e "Como funciona".
- Sai o que o Stitch inventou e não existe no motor: "SLA de integração", "Dica do mentor", "regra de duplicidade para Ads", descrição em Markdown (o ML só aceita texto puro, RN-72), "Publicar em lote" no topo da entrada.

### D26 — A trava por conta fecha também a conferência no ML (02/10, depois do planejamento)
Pergunta Q-UI-1 do planejador. O usuário escolheu "Travar também". Em conta **não liberada** (D21), "Conferir" roda **só a conferência local** (L1/L2 contra o schema da categoria) e não faz nenhuma chamada que escreva ou valide na conta do cliente: nem upload de fotos ao ML, nem `/items/validate`, nem as consultas da conferência que leem a conta (`available_listing_types`, busca de SKU em outros anúncios). Leitura pública de catálogo (schema e busca de categoria) continua, porque o editor precisa dela para abrir. A tela diz que a validação no ML depende da liberação da conta, em tom calmo (UI-SPEC §9). Razão: a memória do projeto diz que conta de cliente recebe no máximo leitura, e validate só com o "pode" do usuário.

### D27 — Oferta apagada no Portal: o produto fica, solto do Portal (02/10, depois do planejamento)
Pergunta Q5 da pesquisa e do planejador. O usuário escolheu "Produto fica, solto do Portal". Apagar a oferta na Lista SKUs **não apaga** o `pub_produto`, o rascunho nem o histórico de publicações (o payload enviado e a resposta bruta do ML ficam guardados, regra do usuário). O vínculo é desfeito: `pub_produtos.oferta_id` vira NULL (FK `nullOnDelete`, válido porque a coluna é nullable; o erro 1830 do learnings §6 só ocorre em coluna NOT NULL), e o produto passa a se comportar como cadastrado no Publicador (D15): título e preço passam a ser os digitados, e nada é cadastrado na régua (aba Anúncios). `pub_rascunhos.oferta_id` não pode continuar levando o rascunho junto em cascata. `test_excluir_a_oferta_leva_o_rascunho` inverte: apagar a oferta mantém produto, rascunho e publicações.

## A critério do planejamento (Claude's Discretion)

- Como generalizar `ClienteMlPublicador`, `ContaMlService`, `ImagemAssetService`, `ConferenciaService`, `PublicacaoService`, `EditorRascunhoService` de `Company`/`EstruturaOferta` para `PubProduto` + `ContaMercadoLivre` sem quebrar os 230 testes (adaptá-los é esperado; o comportamento coberto não muda).
- A organização das telas (entrada com Polos | Incubadora, produtos da empresa, editor) — desde que siga o layout do Stitch aprovado (UI-SPEC) e reaproveite os componentes do piloto (`resources/js/Components/Publicador/*`).
- Onde mora o controller interno (novo `MlbPublicadorController` ou métodos no `MlbAnuncioController`) — a regra do projeto pede controller enxuto.

## Referências canônicas

- `.planning/publicador-ml-spec/17-publicador-interno.md` — D12–D19 e o schema (fonte desta fase)
- `.planning/publicador-ml-spec/16-analise-do-portal.md` — motor, D1–D11, plano por fases do piloto
- `.planning/publicador-ml-spec/00`–`15` — a especificação do Publicador
- `.planning/learnings/publicador-ml.md` — Http::fake acumula; Job em fatias com `release()`; conferência visual isolada (SQLite); pegadinhas de teste
- `.planning/learnings/desempenho-bonificacao.md` §6 — armadilhas de MariaDB que o SQLite não pega
- `routes/mlb_anuncios.php`, `app/Http/Controllers/MlbAnuncioController.php`, `resources/js/Pages/Mlb/*` — o módulo atual
- `app/Services/MercadoLivreService.php` (`ContaMercadoLivre`, `ensureValidToken`), `app/Models/MlbEmpresa.php`, `app/Models/MlToken.php`

## Ideias específicas do usuário

- "ao entrar no publicador, vai ter essa opção de selecionar polos ou incubadora"
- "a empresa que tiver na incubadora e no portal do cliente, podemos puxar os dados dela de acordo com o que preencheu no portal"
- "vamos começar a publicar já vindo preenchido o que tem no portal do cliente, o que foi listado e preenchido lá"
- Layout: criar pelo Stitch, combinando com o sistema interno (projeto "ECF Admin — Identidade", design system "ECF Admin Dark") e com a lógica do Anunciar do portal.

## Fora desta fase

- API de gerar imagens (é o motivo da mudança, mas é fase própria).
- Permissão fina (`permission:mlb.anunciar`) e acesso de publicadores não-admin (D17: só admins).
- Mudanças em Meus Anúncios, Em massa e Histórico.
- O E2E na #459 (F1.12 do piloto) — pode rodar já no Publicador interno, com a confirmação do usuário antes de cada `POST /items`.
