# 16 — Análise do Publicador atual do Portal (Anunciar) × especificação

> **Base de código analisada:** `origin/main` em `a2c69c39` (01/10/2026 13:44). O checkout principal (`main` local, em `C:\xampp\htdocs\ecf_admin`) está **1.385 commits atrás**, então todas as linhas citadas aqui são do `origin/main`. A leitura foi feita num worktree isolado e só de leitura: `C:/tmp/ecf-publicador-spec-261001`.
>
> **Prints do Seller Center:** ainda não recebidos. Esta análise usa a descrição deles em `00-analise-dos-materiais.md`.
>
> **Medições em produção:** não refeitas nesta sessão (a leitura do banco de produção foi barrada pelo classificador de permissões). Onde cito um número de produção, digo a data e a origem.

---

## Decisões do usuário (01/10/2026)

| Pergunta (§7.1) | Resposta | O que muda |
|---|---|---|
| 1. Lugar | **Fica no Anunciar do portal.** O `/incubadora/publicador` fica parado por enquanto. | O rascunho sempre nasce de uma oferta: `pub_rascunhos.oferta_id` é **obrigatório e único**, e a empresa vem pela oferta (sem `company_id`, como no ADR PORTAL-01) |
| 2. O par | **Sim**: um rascunho → Clássico e Premium, títulos diferentes, mesmo SKU | Decisão D1 (alvos) confirmada |
| 3. Conta de teste | Empresa **#459 "Dev 02 Testes API"** (link aberto de equipe no portal, `2869d201`) | A Fase 0b e o E2E rodam nela |
| 4. UP na régua | "As variações são separadas, mas do **mesmo anúncio** — separadas só para levar cor, imagens e outras informações" | Na aba Anúncios/régua, a oferta tem **um anúncio por tipo**. No UP, os N itens da família contam como esse único anúncio: registra-se o item da 1ª variante ativa, e os demais ficam ligados em `pub_publicacao_itens` (não viram linhas novas na régua). |

| 12. Conta clássica para teste | Autorizado usar conta de cliente **só para conferência** (`validate`, sem publicar nem mexer em anúncio). Publicação real só na Dev 02 (#459). | Nenhuma das 33 contas de cliente está no modelo antigo |
| 13. Builder legado | **Sim, sai da Fase 1** | Decisão **D10**: só o builder UP na Fase 1; conta sem `user_product_seller` é detectada e bloqueada com mensagem clara. Muda o [ARQ] do `01` §4.3 ("os dois builders na Fase 1"). Justificativa: 0 de 33 contas de cliente usam o legado (medido em 01/10). O núcleo já feito (união de fotos do legado, chave, regeneração) fica; só não há builder nem publicação legada. |
| 14. Conferir conta multidepósito | **Sim** (uma conta, só `validate`) | Feito: o `available_quantity` é **obrigatório** (sem ele, 369) mas **ignorado** (aviso 469). O anúncio é aceito; o estoque vem dos depósitos da conta. Ver a pergunta 15. |

As perguntas 5–11 seguem com o padrão proposto (§7.1) até você dizer o contrário.

**Pergunta 15 (aberta):** nas contas multidepósito (23 de 33), o ML aceita o anúncio, mas ignora o estoque informado. Proposta para a Fase 1: **publicar normalmente** (mandando o estoque, porque o campo é obrigatório) e **avisar na tela**: "Nesta conta o estoque é controlado por depósito: depois de publicar, ajuste o estoque no Mercado Livre ou no seu sistema de estoque". Estoque por depósito pelo Publicador fica para a Fase 2. A alternativa da spec (bloquear essas contas) deixaria ~70% dos clientes de fora.

## Andamento

Branch `feat/publicador-ml-261001`. Commits só locais até a primeira entrega com tela.

| Entrega | Situação | Commit |
|---|---|---|
| F0 — sondagem (0a pública + 0b na conta #459) | Feita em 01/10 | `6184715a`, `7a7d5a9f` |
| F1.1 — chave canônica, combinações, regeneração | Feita em 01/10 (TC-03…13, 40) | `a48ee990` |
| F1.2 — CategorySchema, classificador, valor de atributo, troca de categoria | Feita em 01/10 (TC-30…41, 70…74) | `5036a94e` |
| F1.3 — grupos de imagem | Feita em 01/10 (TC-50…55, 60) | `a070f02e` |
| F1.4 — builder UP + PayloadPlan com alvos (legado fora: D10) | Feita em 01/10 (TC-01…05, 14, 31, 39, 41, 43, 90) | ver `git log` |
| F1.5 — validação L1/L2 + simulador | Próxima | — |

## Resultado da Fase 0 (01/10/2026) — o que muda no plano

Detalhes e evidências no `12-hipoteses-e-pendencias.md` §Resultado.

| Achado | Muda no plano |
|---|---|
| A conta #459 (MGSTOREL) é **User Products** | O builder UP é o **caminho principal** e o primeiro a ficar pronto (F1.4). O legado continua, para contas clássicas. |
| No UP, `title` é **inválido** e `family_name` é obrigatório | O builder UP nunca manda `title` — regra da API, não flag |
| `validate` devolve **400 mesmo só com avisos** | V-REM-01 = "nenhuma causa `type=error`" (decisão D6 mantida; o 204 do `08` não é o critério) |
| `lost_me1_by_user` agora vem como `warning` | Decisão **D7 dispensada**: decide-se pelo `type` que o ML manda |
| Embalagem sem unidade é **descartada em silêncio** (aviso 306) | L1 bloqueia embalagem sem unidade |
| `family_name` limitado pelo `max_title_length` (60, ou 200 na pastilha); o teto de 120 não apareceu | Limite lido da categoria; 120 só como configuração |
| `technical_specs/input` agrupa **todos** os atributos; `allow_custom_value` diz se aceita texto livre | O formulário dinâmico (F1.2/F1.11) usa os grupos e esse sinal |
| **A conta de teste é uma loja real** (não usuário de teste do ML) | O E2E (F1.12) cria anúncio visível de verdade: título "Item de teste - Não ofertar", fechar logo depois, e sua confirmação antes de cada `POST /items` |
| **Não há conta clássica** para testar o legado | O H-26 e o E2E legado (TC-93) ficam sem conta (pergunta 12 abaixo) |
| **Nenhum cliente no modelo antigo:** as 33 contas de cliente com token válido são User Products (leitura autorizada, só `/users/me`) | Recomendação: o builder legado sai do caminho crítico (pergunta 13) |
| **23 das 33 contas têm `warehouse_management`** (estoque em vários depósitos) | Bloquear essas contas na Fase 1 (RN-04/V-VAR-20) barraria ~70% dos clientes. Precisa de um `validate` numa conta com a tag antes de decidir (pergunta 14). |

---

## 0. Resumo

1. **O que existe hoje.** O "Anunciar" do Portal do Cliente é o 6º submódulo do Mapeamento Estrutural (ADR PORTAL-03, no ar desde 29/09). Ele publica um **par** de anúncios (Clássico e Premium) de **um produto sem variações**. O formulário é fixo, com 6 seções, e mostra **só** os atributos `required` da categoria. Não há eixos, combinações, grupos de imagem, atributos condicionais, `technical_specs`, tela de revisão nem registro do payload enviado. Segundo a medição registrada em 01/10/2026, **ele nunca publicou contra o ML real**.
2. **O que presta e fica.** OAuth e renovação do token com lock (RN-01 já é atendida). Upload das fotos para o ML no momento em que são adicionadas. A trava atômica contra publicar duas vezes. A gravação do MLB no instante em que o ML devolve o id. A regra "publica só o tipo que falta na régua". O hash de conferência. A lista de ofertas, a integração com a aba Anúncios, o arraste de fotos com `@dnd-kit` e o layout do portal.
3. **O que se reconstrói.**
   - O modelo de dados: hoje é um JSON `dados` com campos fixos; passa a ser as entidades do `04`.
   - O montador de payload: hoje é ad hoc; passam a ser dois builders puros que geram um `PayloadPlan`.
   - O formulário: hoje é fixo; passa a ser gerado pelo `CategorySchema`.
   - A validação: hoje são 8 pendências locais e um `/items/validate`; passam a ser as camadas L1, L2 e L3.
   - Os erros: hoje são traduzidos por trecho de texto; passam a ser classificados e mapeados por `references`.
   - A publicação: hoje é síncrona e não tem estado `UNKNOWN`; passa a ser um orquestrador com reconciliação.
4. **Violações mais graves** (detalhes na §3):
   - O motor compartilhado manda `variations[]` para conta User Products (RN-03).
   - O modelo da conta fica 24h em cache, e uma falha momentânea grava "clássico" no cache (RN-02).
   - O limite de 12 fotos é fixo no código e o excedente é cortado em silêncio (RN-63).
   - Atributos booleanos e numéricos com unidade são enviados como texto livre (RN-16, RN-17).
   - Atributos `required` + `allow_variations` (ex.: Cor) nunca aparecem na tela (V-VAR-18).
   - Um timeout no `POST /items` vira "erro", e o "tentar de novo" pode duplicar o anúncio (RN-93).
5. **Uma decisão que muda o desenho:** o **par Clássico + Premium não existe na especificação**, que tem um único `listing_type_id` por rascunho. Proponho "alvos" dentro do mesmo rascunho (§5.1, mudança [ARQ] justificada). Esta é a pergunta nº 2 da §7.
6. **A Fase 0 depende de você.** Os tokens dos vendedores só se descriptografam na VPS. A parte pública (categorias e atributos) roda no meu ambiente com o token do aplicativo. A parte do vendedor (tags da conta, `available_listing_types`, `/items/validate` com o token dele) precisa rodar na VPS, com a sua autorização.
7. **Prazo:** cerca de 16 dias de trabalho mais 3 de folga, para 19 dias corridos (02→20/10). **Cabe, mas sem margem.** A §8 traz os cortes, em ordem, se atrasar.

---

## 1. O Publicador atual (Etapa 2)

### 1.1 Onde mora

| Camada | Arquivo | Papel |
|---|---|---|
| Rotas | `routes/web.php:214-228` | 8 rotas `portal.auth.estrutura.anunciar.*` / `publicacao.*`, com `throttle`. Cada rota do portal precisa de uma linha na allowlist de `RestringeDominioDoPortal`; sem ela, a rota dá 404 no domínio do cliente. |
| Controller | `app/Http/Controllers/PortalEstruturaController.php:191-268` (+ `dadosPublicacao` 604-635) | Página Inertia da lista e JSON do formulário, da foto, da conferência e da publicação |
| Service do portal | `app/Services/Portal/Estrutura/EstruturaPublicacaoService.php` (950 linhas) | Toda a regra: dados efetivos, pendências, `/items/validate`, publicação, trava |
| Model | `app/Models/EstruturaPublicacao.php` | Status, `LISTING_TYPES`, `hashDe()` |
| Migration | `database/migrations/2026_09_29_180000_create_estrutura_publicacoes_table.php` | Tabela `estrutura_publicacoes` |
| Motor compartilhado com o admin | `app/Services/Mlb/Publicacao/` | `MlPublicacaoService::builderPara()`, os builders `Classic`/`UserProduct`, `MlImagemService`, `MlCatalogoMetaService`, `MlItemPayloadValidator` |
| HTTP e token | `app/Services/MercadoLivreService.php`, `app/Models/MlToken.php` | `post()`, `ensureValidToken()`, `refreshToken()` |
| Tela | `resources/js/Pages/Portal/EstruturaAnunciar.jsx`, `Components/Portal/Estrutura/FormPublicacao.jsx`, `FotosDoPar.jsx`, `lib/fotosDoPar.js` | Lista à esquerda e formulário à direita |
| Decisões | `.planning/adrs/PORTAL-03-anunciar-do-mapeamento.md`, `.planning/learnings/portal-do-cliente.md` §27-30 | Por que é assim |

**Stack:** Laravel 12 + Inertia + React 18 + Tailwind (`ecf-*`). A fila de produção é **Redis** (`queue:work redis --queue=high,default`). Job disparado por clique vai para `high` (learnings §27). Não há Job no Anunciar: tudo roda dentro do request.

### 1.2 O que o usuário vê, em ordem

1. **Lista à esquerda:** "A anunciar" ou "Publicados", com busca por SKU ou nome e 25 itens por página, tudo no servidor. Cada card mostra a primeira pendência ("falta foto", "pronto para conferir"…). A primeira oferta abre sozinha.
2. **Formulário da oferta, numa página só:**
   1. **Categoria.** Vem sugerida pelo título (`domain_discovery`), com o caminho inteiro da árvore. Pode ser trocada por busca. Exige categoria folha.
   2. **O par.** Título e preço do Clássico e do Premium. O título vem da aba Anúncios e o preço vem da Precificação. Títulos iguais bloqueiam.
   3. **Fotos.** Cada arquivo sobe para o ML na hora. Até 12. Arraste para ordenar; a primeira é a capa.
   4. **Ficha técnica.** Só os atributos `required` da categoria, e nenhum de variação.
   5. **Estoque, condição** (Novo/Usado), **envio** (Mercado Envios ou "a combinar"), **garantia** (lista fixa), **frete grátis** e **embalagem** (peso e as três dimensões).
   6. **Descrição.**
3. **Rodapé:** "Conferir no Mercado Livre" (pendências locais e depois `/items/validate`) e "Publicar" (só depois de conferir, e se nada mudou desde então).
4. **Sem conta do ML conectada:** a tela leva para o Onboarding.

Cliente e equipe usam a mesma tela (guard `portal`, `AtorDoPortal`). O activity log grava a origem de cada ação.

### 1.3 Como cada tema é tratado hoje

| Tema | Hoje |
|---|---|
| Categoria | `domain_discovery` (token do aplicativo, cache de 1h) mais `GET /categories/{id}` em paralelo (cache de 7 dias, chave `ml_meta_categoria_{id}`). Confere se é folha. Não guarda `domain_id`. |
| Atributos | Filtra `required` ∧ ¬`allow_variations` ∧ id sem "GRID" (`EstruturaPublicacaoService.php:323-325`). Só o tipo `list` vira select; os demais viram texto livre. Não há N/A, condicionais, grupos nem unidade. |
| Condição | `new` ou `used` (`:73`). Não existe recondicionado. |
| Variações / combinações | **Não existem.** O produto é um item por tipo. |
| SKU / GTIN / estoque / preço | SKU é o da oferta, enviado como `SELLER_SKU` nos dois anúncios (é o que liga o par no ML, learnings §27). Não há campo de GTIN. O estoque é um só, ≥ 1. O preço é por tipo: o digitado ou o da Precificação. |
| Imagens | `POST /pictures/items/upload` no momento em que a foto é adicionada. O rascunho guarda `{id, url}`. Nada fica em disco local. |
| Título | Um por tipo. Limite de `settings.max_title_length` (60 se não vier). |
| Descrição | Texto. Enviada depois de criar o item. Se falhar, vira aviso. |
| Condições de venda | `gold_special`/`gold_pro` fixos. `me2` ou `not_specified`. `free_shipping` à escolha. Embalagem como `SELLER_PACKAGE_*`. Garantia por `value_name`. |

### 1.4 Payload, endpoints e erros

- **Montagem:** `montarItem()` (`EstruturaPublicacaoService.php:826-864`) monta um array no formato do item e entrega ao `builderPara($empresa)->montar()`. O builder só copia as chaves conhecidas e decide entre `title` (clássico) e `family_name` (UP).
- **Endpoints chamados:** `GET /sites/MLB/domain_discovery/search`, `GET /categories/{id}`, `GET /categories/{id}/attributes`, `GET /users/{id}` (detecção do modelo), `POST /pictures/items/upload`, `POST /items/validate`, `POST /items`, `POST /items/{id}/description` e `POST /oauth/token` (renovação).
- **Nunca chamados**, conferido por busca em `app/`: `technical_specs/input`, `sale_terms`, `attributes/conditional`, `available_listing_types`, `shipping_preferences`, `listing_prices` e `GET /items/{id}` depois de publicar.
- **Erros:** o `/items/validate` é chamado direto por `Http`, sem renovação em 401 nem nova tentativa em 429 (`:454`). O `POST /items` passa por `MercadoLivreService::post()`, que renova em 401 e espera em 429, mas transforma qualquer outra resposta em `RuntimeException` com o corpo dentro da mensagem. O portal extrai o JSON da mensagem (`traduzirFalha`, `:873-890`) e o `MlItemPayloadValidator` traduz por **trecho de texto** (`TRADUCOES`, 68 entradas). O `cause_id` e a mensagem original se perdem.

### 1.5 Token OAuth

- `ml_tokens`: `access_token` e `refresh_token` com cast `encrypted`, `expires_at` gravado com 60 s de folga e `status` (`active`/`revoked`). Cada empresa tem uma conta; a âncora é `company_id` ou `mlb_empresa_id`.
- **Renovação** (`MercadoLivreService.php:216-298`): `Cache::lock("ml-refresh-{conta}", 15)` serializa por conta. Depois do lock, relê o token do banco, para reaproveitar uma renovação concorrente. O novo par de tokens é salvo num único `UPDATE` (`:274-280`). Só `invalid_grant` marca `revoked`; erro transitório mantém o token ativo. **RN-01 atendida.**
- Renova quando falta menos de **5 min** (`:320`); a especificação pede 10 min.
- Nenhum log grava token. Os logs gravam o corpo das respostas de erro do ML, que não contém token.

### 1.6 Modelo de dados atual

`estrutura_publicacoes` tem uma linha por oferta (`unique(oferta_id)`): `status` (6 valores), `dados` JSON (categoria, `atributos{ID:{value_id,value_name}}`, `fotos[{id,url}]`, estoque, condição, envio, embalagem, garantia, descrição, `tipos{classico,premium:{titulo,preco}}`), `erros` JSON (traduzidos), `validado_hash`, `ml_item_classico`, `ml_item_premium`, `publicando_em` e `publicado_em`. A empresa vem pela oferta.

**Achado colateral (leitura de código, não reproduzido):** o ADR diz que "o rascunho só guarda o que a pessoa digitou; a Precificação continua mandando no preço". Mas `abrir()` devolve os dados **já preenchidos** (`:194`) e o autosave do front manda esse objeto inteiro de volta (`FormPublicacao.jsx:142`). O primeiro autosave, que dispara sozinho quando a categoria foi sugerida (`:120-121`), **congela** o título e o preço no rascunho. A partir daí, mudar a Precificação não muda mais o preço. Isso pesa na migração (§4.4).

### 1.7 Testes existentes

| Suíte | Cobertura |
|---|---|
| `tests/Feature/PortalCliente/Estrutura/AnunciarEstruturaTest.php` | 14 testes com `Http::fake`: a página, abrir com dados preenchidos, foto, ordem das fotos, sugestões com caminho, conferência local e do ML, publicar o par, publicar duas vezes, parcial com retry, mudança depois da conferência, Clássico importado, oferta completa, 404 de outra empresa, sem conta |
| `tests/js/fotos-do-par.test.js` | 5 testes da lógica pura de ordenação das fotos |
| Admin `/mlb/anuncios` | Phase75/76/82/86 e, segundo a memória de 14/07, 114 testes nas Phases 75-81 (`MlVariacaoService`, builders, frete, duplicar tipo, lote) |
| `tests/Feature/IncubadoraPublicador/CategoriaTermosTest.php` | Passo 1 da Incubadora |
| Fixtures do ML | Só `tests/fixtures/phase134/` (anúncios reais, incluindo um com variações). **Não há** fixtures de categoria nem de atributos. |

Não rodei as suítes nesta sessão: o worktree não tem `vendor`, e o autoloader compartilhado aponta para outra árvore (memória de gotcha).

### 1.8 Vizinhos que pesam na decisão

- **`/mlb/anuncios`** (wizard admin, só para `role:admin`): é **onde as variações existem hoje**, e é o retrato exato dos problemas que a especificação descreve.
  - Variações são linhas digitadas uma a uma (`novaVariacao`, `AnunciarML.jsx:361`), não um produto cartesiano.
  - Cada linha tem fotos próprias (`handleUploadFotos`, `:467`), sem grupo por cor.
  - O payload é montado **no navegador** (`montarPayload`, `:1634-1756`).
  - O wizard gera EAN aleatório com prefixo 789 (`gerarEan13`, `:331`; a IA faz o mesmo em `RascunhoAnuncioIaService.php:790`).
  - Ele usa o mesmo motor que o portal.
- **`/incubadora/publicador`** (criado hoje, `933d3abc`, oculto, só para Dev): passo 1, que vai do nome à categoria e aos termos mais buscados. O `CategoriaSugestaoService` dele é melhor que o do portal, porque **não grava falha no cache** (learnings `publicador-incubadora.md` §3).

---

## 2. Mapa atual × especificado

Classificação: **Manter** (fica como está), **Adaptar** (a base serve, muda o comportamento) ou **Reconstruir** (estruturalmente errado ou inexistente).

### 2.1 Fluxo

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Wizard E0–E14 com grafo de dependências e `step_state` (`02`) | Página única com 6 seções; qualquer edição só zera a conferência | **Reconstruir** | Sem etapas não há invalidação em cascata (`02` §3) nem lista de pendências por etapa |
| Apresentação nas 3 etapas do Seller Center [MAT] | — | **Reconstruir** (layout) | 4 telas: Produto (E1-E2), Dados do produto (E3-E9), Condições de venda (E10), Revisão e publicação (E11-E14) |
| Lista de ofertas como entrada | "A anunciar"/"Publicados", paginada no servidor, com prontidão | **Manter** | Funciona e conversa com a régua. A prontidão passa a vir do resumo da L2. |
| Rascunho salvo sozinho [MAT] | Autosave a cada 900 ms | **Manter** | — |
| Revisão gerada a partir dos payloads (E12, RN-91) | Não existe | **Reconstruir** | Hoje o usuário publica a partir do formulário, sem ver o que vai ser enviado |
| Pós-publicação (E14) | Não existe | **Adaptar** | `GET /items/{id}` depois de criar; as notificações ficam para depois (§6.3) |
| Estados do rascunho | `rascunho`/`validado`/`publicando`/`publicado`/`parcial`/`erro` | **Manter** o conceito | Equivalem a DRAFT…FAILED; muda só o armazenamento |
| Quem publica (cliente e equipe) | `AtorDoPortal` + activity log | **Manter** | Decisão do ADR PORTAL-03 |

### 2.2 Conta e token

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| E0 `AccountContext` (tags, modelo, modos de envio, `checked_at`) | Só o modelo, em cache de 24h com valor reserva | **Reconstruir** | Viola RN-02 (§3, V2) |
| Token cifrado; renovação com lock; novo refresh salvo de forma atômica | Feito | **Manter** | RN-01 atendida |
| Renovar faltando < 10 min | 5 min | **Adaptar** | Parâmetro de configuração |
| `invalid_grant` → reconectar | `revoked` e mensagem "conecte pelo Onboarding" | **Manter** | — |
| `warehouse_management` bloqueia (RN-04) | Não existe | **Adaptar** | Ler a tag no E0 |

### 2.3 Categoria e schema

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| `CategorySchema` com 4 fontes (`03` §2) | 2 fontes, guardadas no `Cache` | **Reconstruir** | Faltam `technical_specs/input` e `sale_terms` |
| `schema_hash`, TTL de 24h, revalidar antes de publicar (RN-22) | TTL de 7 dias, sem hash, falha guardada no cache | **Reconstruir** | §3, V13 |
| Sugestão com caminho antes de escolher | Feito, em paralelo | **Manter** | Trocar pelo `CategoriaSugestaoService` da Incubadora, que não guarda falha |
| Folha (V-CAT-01) | Feito | **Manter** | — |
| `listing_allowed`, `item_conditions`, `currencies` | Não confere | **Adaptar** | Regras L2 |
| Detectar o que é Fase 2 e bloquear com mensagem | Descarta em silêncio os atributos `*GRID*` | **Reconstruir** | §3, V9 |
| Guardar `domain_id` | Não guarda | **Adaptar** | Preciso dele para família UP e catálogo |
| Troca de categoria: valores `migrated` e lista do que foi descartado (RN-21) | Descarta em silêncio | **Reconstruir** | §3, V14 |

### 2.4 Atributos

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Classificação de papel e obrigatoriedade efetiva (`03` §4) | Filtro `required` ∧ ¬`allow_variations` | **Reconstruir** | Ignora `new_required`, `conditional_required`, `catalog_required`, `hidden` e os papéis |
| Grupos do `technical_specs` (principais, regulatória, secundárias) [MAT] | Lista plana | **Reconstruir** | — |
| Componente escolhido pelo `value_type` | Só `list` vira select | **Reconstruir** | §3, V5/V6 |
| `number_unit` com `allowed_units` | Texto livre | **Reconstruir** | §3, V6 |
| "Não se aplica" (`-1`) em opcional | Não existe | **Adaptar** | Novo no componente |
| Condicionais (`/attributes/conditional`) | Não existe | **Adaptar** | Novo serviço L3 |
| `read_only`/`inferred`/`fixed` nunca enviados (RN-14) | Sem guarda (o risco hoje é baixo, porque a tela só mostra os obrigatórios) | **Adaptar** | Guarda no builder |
| Sugestão por inferência (`domain_discovery.attributes`) com revisão | Não existe no portal | **Adaptar** | `origem=inferred`, `revisar=true` |
| GTIN / `EMPTY_GTIN_REASON` por variante | Não existe no portal | **Adaptar** | Dado da variante |

### 2.5 Condição, título, descrição e condições de venda

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Condição `new`/`used`/recondicionado (`ITEM_CONDITION`) | `new`/`used` | **Adaptar** | Recondicionado fica atrás da configuração do H-04 |
| Título ≤ `max_title_length`; UP ≤ 120; termos proibidos como aviso | Só o limite da categoria | **Adaptar** | — |
| Título por tipo, vindo da aba Anúncios, e títulos diferentes no par | Feito | **Manter** | Vira o "alvo" (§5.1) |
| Descrição em texto puro, enviada depois de criar; sem HTML; ≤ `max_description_length` | Enviada como aviso se falhar, sem limpar HTML nem conferir limite | **Adaptar** | Ganha `descricao_status` e ação "reenviar descrição" |
| Tipo de anúncio disponível para a conta (`available_listing_types`, V-SAL-01) | Fixo | **Adaptar** | L3 |
| Preço ≥ `minimum_price`, com 2 casas | Só > 0 | **Adaptar** | L2 |
| Preço vindo da Precificação (dados efetivos) | Feito, mas congela no autosave (§1.6) | **Adaptar** | `null` = herda; corrigir o congelamento |
| Envio conforme `shipping_preferences` (RN-82) | `me2`/`not_specified` sem consultar | **Adaptar** | E0 |
| Garantia por `sale_terms` com valores da API | Lista fixa com `value_name` | **Adaptar** | Configuração (H-09) |
| Embalagem `SELLER_PACKAGE_*` (H-05) | Feito | **Manter** | Formato confirmado na Fase 0 |
| "Você recebe" (`listing_prices` + `shipping_options/free`) | Não existe no portal (`MlFreteService` só no admin) | **Adaptar** | Primeiro corte se faltar prazo (§8) |

### 2.6 Variações

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Eixos `allow_variations`, valores e produto cartesiano | Portal: não existe. Admin: linhas digitadas | **Reconstruir** | É o núcleo da especificação |
| Chave canônica normalizada e unicidade (`05` §3) | Admin: chave `id:valor` sem normalização | **Reconstruir** | — |
| Variante `__single__` sempre presente | Implícito: 1 item por tipo | **Reconstruir** (modelo) | Elimina o "se tem variação" |
| Preço, estoque, SKU e GTIN por variante | Estoque único; SKU = SKU da oferta | **Reconstruir** | — |
| Desativar em vez de apagar; variantes órfãs | Não existe | **Reconstruir** | — |
| `max_variations_allowed` (legado) | Não existe | **Reconstruir** | — |

### 2.7 Imagens

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Upload prévio; o payload usa ids | Upload no momento da adição | **Manter** o mecanismo | Já é a "otimização opcional" do `06` §7 |
| `image_asset` local (sha256, dimensões) com deduplicação | Só `{id,url}` do ML | **Adaptar** | Sem o arquivo não há como validar localmente nem reenviar em `picture_not_found` (H-22) |
| Validação local de formato, tamanho e lado ≥ 500 px | `image` + 10 MB | **Adaptar** | §3, V4 |
| Grupos por `defines_picture` + galeria geral | Lista única | **Reconstruir** | — |
| Limites lidos da categoria | 12 fixo, com corte silencioso | **Reconstruir** | §3, V3 |
| Ordenação por arraste e selo de capa | `@dnd-kit` com lógica pura testada | **Manter** | Reaproveitar em cada coluna de grupo |
| Fila de upload com concorrência 2-3 e espera progressiva | Envio sequencial pelo navegador | **Adaptar** | — |

### 2.8 Validação

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| L1 (campo) | Parcial, pelo FormRequest | **Adaptar** | — |
| L2 (rascunho + schema), matriz do `08` | `pendencias()` com 8 regras | **Reconstruir** | — |
| L3: condicionais + `validate` de **cada** payload | Só `validate` por tipo | **Adaptar** | — |
| Validade presa à revisão | `validado_hash` dos dados efetivos | **Manter** o conceito | Evolui para o hash do `PayloadPlan` (§5.1) |
| WARNING exige "Estou ciente" | Warning não bloqueia, mas não pede ciência | **Adaptar** | — |
| Problemas por etapa e alvo, com "ir para o campo" | Lista plana | **Reconstruir** | — |

### 2.9 Publicação

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Builder puro por modelo + `PayloadPlan` | `montarItem()` + builder que só copia chaves | **Reconstruir** | §3, V1/V12 |
| Orquestrador: token → tags → schema → upload → POST → descrição → status | `publicar()` síncrono dentro do request | **Reconstruir** (Job) | O UP com N variantes × 2 tipos não cabe num request |
| Trava contra duplicidade | `UPDATE` condicional + trava órfã de 15 min | **Manter** | Funciona em SQLite e MariaDB |
| `ml_item_id` gravado na hora | Feito | **Manter** | — |
| Estados `SENT`/`UNKNOWN` + reconciliação por SKU | Não existem | **Reconstruir** | §3, V10 |
| Falha parcial; retentar só o que falhou | Feito por tipo (`parcial`) | **Manter** o conceito | Generalizar para N itens |
| Publicar só o tipo que falta na régua | `tiposPendentes()` | **Manter** | — |
| Registrar na aba Anúncios | `EstruturaAnuncioService::cadastrar()` | **Manter** | Com N itens no UP, ver a pergunta 4 |

### 2.10 Erros

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Classes VALIDATION/WARNING/AUTH/PERMISSION/RATE/SERVER/NETWORK/UNKNOWN (`09` §2) | Só 401 → renovar e 429 → esperar, no `post()` | **Reconstruir** | — |
| `references` → campo; índice → `variant_id` | `references[0]` vira um texto | **Reconstruir** | — |
| Dicionário `code`/`cause_id` → mensagem em português, configurável | `TRADUCOES` por trecho de texto | **Adaptar** | Migrar as 68 entradas para o dicionário |
| Payload e resposta bruta guardados | Não guarda | **Reconstruir** | §3, V11 |
| Espera progressiva em 429 | `comRetry429` (honra `Retry-After`) | **Manter** | — |

### 2.11 Modelo de dados

| Especificado | Hoje | Classif. | Justificativa |
|---|---|---|---|
| Entidades do `04` | `estrutura_publicacoes.dados` em JSON | **Reconstruir** | Sem elas não há unicidade de combinação, de imagem nem de SKU. Ver §4. |

---

## 3. Violações encontradas

As linhas citadas são do `origin/main` `a2c69c39`.

### 3.1 Regras [ML]

| # | Regra | Onde | O que acontece | Correção |
|---|---|---|---|---|
| V1 | **RN-03** [ML·S8]: conta UP não pode enviar `variations[]` | `app/Services/Mlb/Publicacao/Builders/ItemBuilderBase.php:27-31` copia `variations` para os dois modelos; `UserProductItemBuilder.php:16-27` usa esse método | O admin (`AnunciarML.jsx:1742`) numa conta UP (ex.: ByMobille-Teste) manda `variations` → 400. O portal não é afetado hoje (nunca manda variações), mas o motor é o mesmo. | O builder UP novo tem uma guarda que lança exceção **antes** da chamada HTTP (TC-90). No motor antigo, guarda de 3 linhas, como conserto separado (pergunta 10). |
| V2 | **RN-02** [ML·S8][ARQ] / V-ACC-02: o modelo é consultado no início **e** imediatamente antes de publicar | `MlPublicacaoService.php:39-53`: `Cache::remember` por 24h; no `catch`, devolve `'classic'` **dentro** do `remember`, e o valor reserva fica 24h no cache. Usado em `EstruturaPublicacaoService.php:449` e `:533`. | Uma falha momentânea em `GET /users/{id}` faz uma conta UP receber payload clássico por 24h. Uma mudança de tag não é vista antes de publicar. A publicação não registra o modelo usado. | `ContaMlService` lê as tags no início do rascunho e logo antes de publicar, nunca guarda falha e registra o modelo na publicação. Se o modelo mudou, volta à revisão (TC-89). |
| V3 | **RN-63** [ML·S2][ARQ] / V-IMG-06/07: limites lidos da categoria; o excedente bloqueia em vez de ser cortado em silêncio | `EstruturaPublicacaoService.php:69` (`MAX_FOTOS = 12`), `:385-387` (barra em 12, seja qual for a categoria), `:704` (`array_slice` corta em silêncio); `PortalEstruturaController.php:614` | Categoria com limite diferente de 12 é ignorada | Ler `max_pictures_per_item(_var)`; o excedente vira BLOCKER listando as fotos que sobram |
| V4 | **RN-64** [ML·S9] / V-IMG-01/03: JPG/PNG, ≤ 10 MB, lados ≥ 500 px | `PortalEstruturaController.php:231` (regra `image` aceita webp, gif e bmp; não confere dimensões); `FormPublicacao.jsx:399` (`accept` inclui `image/webp`) | WEBP e fotos pequenas vão ao ML e voltam com uma mensagem genérica (`:400`) | `mimes:jpg,jpeg,png` + `dimensions:min_width=500,min_height=500`; avisos para menos de 1200 px e para CMYK |
| V5 | **RN-16** [ML·S3] / V-ATT-03: `list`/`boolean` só aceitam `value_id` de `values[]` | `FormPublicacao.jsx:419-428` (só `list` vira select; `boolean` vira texto); o servidor aceita qualquer valor (`EstruturaPublicacaoService.php:663-676`) | Um booleano obrigatório sai como `value_name: "Sim"` digitado | Componente escolhido pelo `value_type`; L1 no servidor confere o `value_id` |
| V6 | **RN-17** [ML·S3] / V-ATT-04: `number_unit` = `"<n> <unidade>"`, com unidade em `allowed_units` | `EstruturaPublicacaoService.php:831-833` envia o texto como foi digitado; `FormPublicacao.jsx:425-427` só tem placeholder | Digitar "88" gera `item.attributes.normalizable.invalid`. O admin já levou um conserto para exatamente esse erro em 13/07; o portal não tem. | `value_number` + `value_unit` com seletor; o builder formata; usa `default_unit` se vazio (TC-31) |
| V7 | **V-VAR-18** / RN-13 [ML·S3]: atributo `required` + `allow_variations` precisa virar eixo ou ter valor | `EstruturaPublicacaoService.php:323-325` tira esses atributos do formulário | Cor obrigatória nunca é pedida; só aparece como erro genérico no `validate` | `ClassificadorAtributos` (`03` §4) |
| V8 | **RN-10** [ML·S2] / V-CAT-02: `listing_allowed = true` | Não é conferido (só a folha, `:749-751`) | Erro 126 só aparece na L3 | Regra L2 |
| V9 | **RN-30** [ML·S2]: condição em `settings.item_conditions` | `:73`, `:706` | Não é conferida | Regra L2 |

### 3.2 Regras [ARQ]

| # | Regra | Onde | Correção |
|---|---|---|---|
| V10 | **RN-93** / `09` §5: nunca reenviar um item `UNKNOWN` sem reconciliar | `EstruturaPublicacaoService.php:542-556`: qualquer exceção do `POST /items`, inclusive timeout e 5xx **depois** de o ML ter criado o item, vira `erros[tipo]` e status `erro` (`:582-586`); o "tentar de novo" (`:517`) faz um novo POST | Item `PENDING → SENT → CREATED/FAILED/UNKNOWN`; `UNKNOWN` só se resolve pela reconciliação por SKU + `listing_type_id` + título |
| V11 | **`09` §7** e a sua instrução: guardar o payload enviado e a resposta bruta | `:543` monta e envia sem guardar; só o `id` fica; `erros` guarda só a tradução (`:471-475`, `:553`) | `pub_publicacao_itens.payload/resposta/http_status`; L3 também guarda as respostas |
| V12 | **RN-91** / E12: revisão e publicação saem do mesmo montador | Não há revisão; o usuário vê o formulário, não o que será enviado | Tela de Revisão gerada a partir do `PayloadPlan` |
| V13 | **RN-22**: schema com cache de 24h e hash | `MlCatalogoMetaService.php:70-77` e `:87-94` (7 dias, sem hash, e uma falha `[]` fica no cache por 7 dias) | Tabela `ml_categoria_schemas` com hash, TTL de 24h e nunca guardar falha |
| V14 | **RN-21**: a troca de categoria lista o que foi descartado | `FormPublicacao.jsx:180-186` mantém só os atributos que estão na nova lista de obrigatórios e descarta o resto em silêncio | Função pura `migrarParaCategoria()` devolve o que foi mantido (`migrated`, a revisar) e o que foi descartado (TC-71) |
| V15 | **V-CAT-04**: detectar o que é Fase 2 e bloquear com mensagem | `EstruturaPublicacaoService.php:325` remove os atributos `*GRID*` | Flags `needs_size_grid`/`catalog_required` no schema classificado; BLOCKER com a mensagem da Fase 2 |
| V16 | **`08` §3**: problemas do ML mostram a mensagem traduzida e, recolhidos, `code`, `cause_id` e o texto original | `MlItemPayloadValidator.php:122-133` devolve só a tradução | `ml_cause` bruto em cada problema |
| V17 | **`09` §6**: renovar o token faltando < 10 min | `MercadoLivreService.php:320` (5 min) | Parâmetro de configuração |
| V18 | **`09` §4**: falha na descrição → `description_status = FAILED` e ação de reenvio | `:559-566` vira um aviso de texto, sem reenvio | Coluna de estado e ação "reenviar descrição" (TC-91) |

### 3.3 Hipóteses implementadas como regra fixa

A especificação manda tornar essas configuráveis:

| HIP | Onde | Correção |
|---|---|---|
| H-02 (UP sem `title`) | `UserProductItemBuilder.php:16-27`, fixo no código | Configuração `up_send_title`, padrão `false` |
| H-09 (garantia) | `EstruturaPublicacaoService.php:71`, `:859-861` (lista fixa e `value_name` fixo) | Configuração + `sale_terms` da categoria |
| H-10 (limite do frete grátis) | `MlItemPayloadValidator.php:23` diz "acima de ~R$79" na mensagem | Mostrar o que a API indicar (RN-83) |
| H-26 (`available_quantity` no item legado com variações) | `MlVariacaoService.php:100-105` afirma, sem fonte, que o ML recusa valor > 0 no item | Isso **contradiz** a decisão provisória ("enviar a soma"). Resolver na Fase 0 antes de codar. |

**Risco à parte:** o admin e a IA geram EAN aleatório com prefixo 789 (`AnunciarML.jsx:331`, `RascunhoAnuncioIaService.php:790`). O dígito verificador fecha, mas o código pode pertencer ao produto de outra empresa. O Publicador novo **não gera GTIN**: usa `EMPTY_GTIN_REASON` quando o produto não tem um (RN-49). Ver a pergunta 8.

---

## 4. Modelo de dados

### 4.1 Princípios

- **Só tabelas novas.** `estrutura_publicacoes`, `ml_anuncio_rascunhos` e `ml_tokens` não são alteradas. Com isso, não cai na regra do CLAUDE.md que exige fase GSD para migration que altere tabela com dado em produção, e voltar atrás é reverter código.
- **Nomes em português**, com prefixo `pub_`, que é a convenção do projeto. O mapeamento para as entidades do `04` está abaixo.
- **Armadilhas de MariaDB** (ADR PORTAL-03 e learnings do portal §18):
  - FK e unique com nome curto explícito.
  - Nenhum `nullOnDelete`.
  - Nenhum `timestamp()` fora do `timestamps()`; datas avulsas usam `dateTime`.
  - Migration rodada no MariaDB local com `--path`.
  - Chaves longas viram `char(64)` com sha256 para caber no índice.
  - `NULL` não é repetido em `unique`: por isso a galeria geral usa `grupo_chave = 'GENERAL'`, não `NULL`.

### 4.2 Entidades

| `04` | Proposta | Observação |
|---|---|---|
| `ml_account` | `ml_tokens` (existente, sem alteração) + snapshot do contexto | §5.1, decisão D3 |
| `category_schema` | `ml_categoria_schemas` | `category_id` PK; `domain_id`; JSON brutos de `path_from_root`, `settings`, `attributes`, `technical_specs_input` e `sale_terms`; `schema_hash char(64)`; `fetched_at dateTime` |
| `listing_draft` | `pub_rascunhos` | `oferta_id` FK **obrigatória e única** (decisão de 01/10; a empresa vem pela oferta); `status`; `revisao`; `step_state` json; `identificacao` json; `categoria_id`; `dominio_id`; `schema_hash`; `condicao`; `descricao`; `envio` json; `garantia` json; `fotos_por_variante`; `incluir_geral_nas_variantes` (padrão true); `modelo_publicacao` (snapshot); `conta_checada_em`; `ator` json |
| *(novo)* alvo | `pub_rascunho_alvos` | `listing_type_id`; `titulo` (null = herda o título planejado da aba Anúncios); `ativo`; `posicao`; `unique(rascunho_id, listing_type_id)` |
| `draft_attribute_value` | `pub_rascunho_atributos` | `attribute_id`, `value_id`, `value_name`, `value_number`, `value_unit`, `values_multi`, `origem` (user/inferred/catalog/migrated), `revisar`; `unique(rascunho_id, attribute_id)` |
| `variation_axis` | `pub_eixos` | `attribute_id` null para eixo customizado; `nome_custom`; `posicao`; `defines_picture`. "No máximo 1 customizado" fica na validação V-VAR-02, porque o `unique` deixa passar vários `NULL`. |
| `axis_value` | `pub_eixo_valores` | `value_id`, `value_name`, `chave_normalizada`, `posicao`, `removido` (**nunca apagar** enquanto houver variante órfã); `unique(eixo_id, chave_normalizada)` |
| `variant` | `pub_variantes` | `combinacao_chave` (texto), `combinacao_hash char(64)`, `ativa`, `orfa`, `estoque`, `posicao`; `unique(rascunho_id, combinacao_hash)`. **Os ids do ML não ficam aqui** (decisão D5). |
| `variant_axis_value` | `pub_variante_eixo_valores` | PK `(variante_id, eixo_id)` |
| `variant_attribute_value` | `pub_variante_atributos` | `SELLER_SKU`, `GTIN`, `EMPTY_GTIN_REASON`…; `unique(variante_id, attribute_id)`. A unicidade do SKU no rascunho é garantida pela validação (V-VAR-13). |
| *(novo)* preço por alvo | `pub_variante_precos` | `variante_id`, `alvo_id`, `preco decimal(12,2)` (null = herda a Precificação); `unique(variante_id, alvo_id)` |
| `image_asset` | `pub_imagens` | `caminho` (null = foto migrada sem arquivo), `sha256`, `mime`, `bytes`, `largura`, `altura`, `ml_picture_id`, `ml_url`, `upload_status`, `upload_erro`; `unique(rascunho_id, sha256)` |
| `image_assignment` | `pub_imagem_atribuicoes` | `escopo` (GENERAL/GROUP), `grupo_chave`, `posicao`; `unique(imagem_id, grupo_chave)` |
| `validation_run` + `validation_issue` | `pub_validacoes` | `revisao`, `camada`, `plano_hash`, `resultado`, `issues` json (no formato do `04` §2.12), `respostas_ml` json (bruto). Decisão D4. |
| `publication` | `pub_publicacoes` | `revisao`, `modelo_publicacao`, `conta_snapshot` json, `plano_hash`, `status`, `chave_idempotencia`, `iniciada_em`, `concluida_em`, `ator` |
| `publication_item` | `pub_publicacao_itens` | `alvo_id`, `indice`, `variante_ids` json, `mapa_variacoes` json (legado: índice do payload → `variante_id` → `ml_variation_id`), `payload` json, `payload_hash`, `status`, `tentativas`, `http_status`, `resposta` json (bruta), `ml_item_id`, `ml_user_product_id`, `avisos`, `descricao_status`, `enviado_em`, `criado_em` |

São 15 tabelas novas.

### 4.3 O que acontece com o que já existe

| Dado | Destino |
|---|---|
| **`estrutura_publicacoes` sem MLB** (`rascunho`/`validado`/`erro`) | O comando `publicador:migrar-anunciar` (simulação por padrão, `--apply` para gravar; idempotente) cria um `pub_rascunho` com a mesma oferta e empresa, status DRAFT e **validação descartada**.<br>• Atributos → `origem=migrated`, `revisar=true`.<br>• Estoque → variante `__single__`.<br>• SKU da oferta → `SELLER_SKU`.<br>• Embalagem → `SELLER_PACKAGE_*`.<br>• Fotos `{id,url}` → `pub_imagens` com `ml_picture_id`, `upload_status=uploaded` e `caminho`/`sha256` nulos ("foto legada": não é revalidada localmente e, se o ML devolver `picture_not_found` (H-22), pede reenvio).<br>• Título e preço por tipo → alvo e preço da variante **só se forem diferentes** do valor herdado hoje (Anúncios/Precificação), por causa do congelamento descrito na §1.6. Quando forem iguais, ficam `null` e voltam a herdar. |
| **`estrutura_publicacoes` com MLB** (`publicado`/`parcial`) | `pub_rascunho` em PUBLISHED ou PARTIALLY_PUBLISHED + `pub_publicacoes` com `modelo_publicacao='desconhecido'` + `pub_publicacao_itens` com `ml_item_id` e status CREATED, `payload=null` (nunca foi guardado). O alvo que falta continua publicável. |
| `estrutura_publicacoes` (a tabela) | Fica, **só para leitura**. As rotas `validar`/`publicar` antigas saem na virada. |
| `estrutura_anuncios` (régua, aba Anúncios) | Não muda. O Publicador novo registra cada MLB criado por `EstruturaAnuncioService::cadastrar()`, como hoje. Com N itens no UP, ver a pergunta 4. |
| `ml_anuncio_rascunhos` (admin) | Não muda e não é migrada: é outro módulo, com `user_id` de `users`. |

**Volume:** a memória registra em 01/10/2026 que o Anunciar do portal "nunca publicou contra o ML real", então não deve haver linhas com MLB. A contagem por status não foi refeita (leitura de produção bloqueada). Ver a pergunta 8.

---

## 5. Arquitetura proposta no portal

### 5.1 Decisões [ARQ] que este plano muda

| # | A especificação diz | Proposta | Justificativa |
|---|---|---|---|
| D1 | Um `listing_type_id` por rascunho | **Alvos:** um rascunho tem 1 ou 2 alvos (Clássico e/ou Premium), cada um com título e preço próprios. `PayloadPlan` = alvos × (1 item no legado, ou N itens no UP). | Regra de negócio da ECF: "mesmo SKU, títulos diferentes" (ADR PORTAL-03, learnings §27). Só o que falta na régua vira alvo. |
| D2 | `combination_key` única | Mantida. O SKU de cada variante é **o mesmo nos dois alvos**. | É o que liga o par no ML (medido em 25/09). Por isso a reconciliação filtra também por `listing_type_id`, e o V-REM-02 (SKU já usado em outro anúncio) ignora os anúncios da mesma oferta. |
| D3 | Entidade `ml_account` com tags e modelo | Não criar tabela. `ContaMlService` lê ao vivo, nunca guarda falha, e o snapshot fica no rascunho e na publicação. | Alterar `ml_tokens` exige fase GSD e o contexto da conta muda. O `04` já pede snapshot na publicação. |
| D4 | `validation_issue` como entidade | Problemas em JSON dentro de `pub_validacoes`, no mesmo formato | Nunca são consultados entre rascunhos; valem para uma revisão e são exibidos em bloco |
| D5 | `ml_item_id`/`ml_variation_id` na `variant` | Ficam em `pub_publicacao_itens` (+ `mapa_variacoes`) | Com alvos, uma variante tem até 2 ids no ML. "Variante já publicada" vira uma consulta. |
| D6 | Validade por `draft_revision` | `revisao` (edições) **+ `plano_hash`** (sha256 do `PayloadPlan` validado na L3). Publicar recalcula o plano e exige o mesmo hash. | O título e o preço efetivos vêm de fora do rascunho (aba Anúncios, Precificação). O hash do que será enviado é a garantia de que "o que se vê é o que se envia" (RN-91). |
| D7 | V-REM-01: `validate` = 204 é BLOCKER | Lista **configurável** de `code` reconhecidos como falso positivo (hoje só `shipping.lost_me1_by_user`), que viram WARNING com "Estou ciente" | Observado em 10/07/2026: `validate` deu `lost_me1_by_user` na Dev 02 Teste e o `POST /items` real criou o MLB7144195140. Começa vazia ou com esse único código; quem decide é você (pergunta 9). |
| D8 | Fixtures em `fixtures/ml/` | `tests/fixtures-ml/` | O repositório tem `tests/Fixtures` **e** `tests/fixtures`. No Windows são a mesma pasta, e o git grava a grafia maiúscula; no Linux da VPS o arquivo some. Pasta sem homônima. |
| D9 | — | Validar (L3) e publicar viram **Job** na fila `high`, em fatias de até 45 s que se redespacham, com a tela consultando o andamento. O ADR PORTAL-03 dizia "síncrono". | N payloads com concorrência 2 não cabem no request. Um Job que passa do `retry_after` (90 s) é **reentregue**, e a reentrega duplicaria POST. É a mesma armadilha do "Puxar do ML" (learnings §27). Com o item gravado como `SENT` **antes** do POST, uma reentrega vai para a reconciliação, nunca para um novo POST. |

### 5.2 Camadas

```
Tela (React)            ── só desenha, L1 espelhada (feedback instantâneo) ──┐
  Pages/Portal/EstruturaAnunciar.jsx (lista)                                 │
  Components/Publicador/* (wizard)                                           │
                                                                             ▼
HTTP (PortalPublicadorController, JSON)  ── autosave, upload, L2 síncrona, dispara Jobs, estado
                                                                             │
Serviços com I/O  app/Services/Publicador/                                   │
  ContaMlService · CategorySchemaRepository · CondicionaisService            │
  ImagemAssetService · ClienteMlPublicador · ReconciliadorSku                │
  RascunhoRepository (banco ⇄ snapshot imutável)                             │
  Jobs: ValidarRascunhoJob (L3) · PublicarRascunhoJob (orquestrador)         │
                                                                             ▼
Núcleo PURO  app/Support/Publicador/  — sem rede, sem Eloquent, sem relógio
  Schema/      CategorySchema (objeto de valor)  ·  ClassificadorAtributos
  Variacao/    ChaveCanonica  ·  GeradorCombinacoes  ·  RegeneradorVariantes
  Imagem/      ResolvedorGruposImagem
  Payload/     PayloadBuilderLegado  ·  PayloadBuilderUserProducts  ·  PayloadPlan
  Validacao/   ValidadorL1  ·  ValidadorL2  ·  CatalogoRegras (rule_id → severidade, etapa)
  Erros/       ClassificadorErroMl  ·  MapeadorReferencias  ·  DicionarioErros
  Tarifa/      SimuladorVoceRecebe
  Categoria/   MigradorDeCategoria (troca de categoria, RN-21)
config/publicador.php  — todas as [HIP] como flags (up_send_title, max_eixos=3, garantias,
                         recondicionado, falsos_positivos_validate, termos_proibidos,
                         schema_ttl_horas=24, renovar_token_min=10, concorrencia=2…)
```

**Contrato do núcleo puro.** Cada função recebe arrays e objetos de valor e devolve arrays:

- `classificar(CategorySchema, RascunhoSnapshot) → {atributos[{id, papel, obrigatoriedade, componente}], limites, flags}`
- `gerar(eixos) → variantes[]` em ordem estável
- `regenerar(antigas, novosEixos, opcaoCopiar) → {variantes, orfas}`
- `resolver(variantes, eixos, atribuicoes, rascunho) → fotos por variante + união do legado + problemas`
- `montar(snapshot, schema, modelo, alvos) → PayloadPlan`, determinístico, com teste por snapshot JSON
- `validarL1/L2(...) → issues[]`

Tudo coberto por `tests/Unit/Publicador/`, alimentado pelas fixtures da Fase 0.

**`ClienteMlPublicador`** é um serviço novo. Reaproveita `ensureValidToken()` e `refreshToken()` (lock e gravação atômica) do `MercadoLivreService`, mas **devolve** um objeto de resposta classificada em vez de lançar exceção, e nunca registra cabeçalhos. O `write()` atual não muda (outros módulos dependem dele).

**Orquestrador (E13), por item:**

1. Grava `SENT` + `payload` + `payload_hash`.
2. Faz o POST.
3. Grava `CREATED` + `ml_item_id` + resposta bruta **antes de qualquer outra coisa**.
4. Envia a descrição e grava `descricao_status`.
5. Faz `GET /items/{id}` e grava o status do ML.
6. `cadastrar()` na aba Anúncios.

Timeout ou 5xx → `UNKNOWN` → reconciliação por `/users/{id}/items/search?seller_sku=`. Esse endpoint já é usado em produção pelo "Importar" do Mapeamento (128 ms na #131, learnings §27), o que confirma na prática a maior parte do H-19. A trava atômica e a trava órfã do serviço atual continuam como a porta de entrada.

**Tela:** 4 telas no ritmo do Seller Center [MAT], com selo de estado por etapa E2…E12. Componentes novos: `CampoAtributo` (por `value_type`, com N/A, unidade e multivalor), `GrupoAtributos`, `EditorEixos`, `GradeVariantes` (ativar, preço por alvo, estoque, SKU, GTIN ou motivo), `GruposImagem` (uma coluna por grupo + Galeria geral, reaproveitando `lib/fotosDoPar.js` e `@dnd-kit`), `CondicoesVenda`, `Revisao` (gerada dos payloads) e `PainelValidacao` (por etapa e alvo, com "ir para o campo"). As regras L1 espelhadas ficam em `lib/publicador/` com teste em node, seguindo o padrão de `tests/js/`.

**Convivência:** o Anunciar atual continua no ar para os clientes até a virada. O novo nasce numa rota paralela visível só para Dev, como o `/incubadora/publicador`. A virada (troca da rota, migração dos rascunhos) é um deploy separado, com a sua autorização.

---

## 6. Plano em fases

### 6.1 Fase 0 — Sondagem

Comando Artisan `publicador:sondar` que salva **todas** as respostas em `tests/fixtures-ml/sondagem/`. O resultado por hipótese vai para o `12-hipoteses-e-pendencias.md`. Ele **não chama `POST /items`**, nunca imprime nem grava token e só lê ou valida.

| Parte | Onde roda | Token | Chamadas |
|---|---|---|---|
| **0a** — dados públicos | Local | Do aplicativo (já funcionou daqui no spike de 10/07) | `domain_discovery` ("cadeira escritorio executiva", "furadeira de impacto", "camiseta basica algodao", "pastilha de freio"); para cada categoria: `/categories/{id}`, `/attributes`, `/technical_specs/input`, `/sale_terms`; `listing_prices` |
| **0b** — dados do vendedor | **VPS** (os tokens só se descriptografam lá) | Do vendedor (conta de teste) | `GET /users/me` (**H-01**); `shipping_preferences`; `available_listing_types?category_id=`; `shipping_options/free` em várias faixas de preço (H-10); `attributes/conditional` com payloads de exemplo (H-08); `POST /items/validate` em variações deliberadas (com e sem `title`/`family_name`, com `variations`, sem fotos, N/A em obrigatório, embalagem `"12 cm"` × `"12"`, `available_quantity` soma × 0 no item, recondicionado, 3+ eixos); `items/search?seller_sku=` |

**Primeira pergunta a responder: a conta tem a tag `user_product_seller`?** Indícios anteriores, de 10/07/2026 e a reconfirmar:

- ByMobille-Teste (#298, ml_user_id 436501796): era UP; o `validate` exigiu `family_name`.
- Dev 02 Teste (#356): era clássica.
- Os anúncios reais da #131 têm `user_product_id`.

Os dois builders são necessários. O caminho principal sai do 0b.

**Hipóteses que a Fase 0 resolve:** H-01, 02, 03, 04, 05, 06, 07, 08, 09, 10, 15, 16, 17, 19, 21, 24, 26, 27 e 28. **Ficam para o E2E:** H-18 (família), H-22 (validade do id da foto), H-23 (descrição no UP) e H-25 (idempotência). **Fica para comparação manual com o simulador do ML:** H-14. O `12-hipoteses-e-pendencias.md` é atualizado com cada resultado.

### 6.2 Fase 1 — Núcleo

Cada entrega é pequena, tem os testes junto no mesmo commit e é verificável sozinha. "U" = teste unitário puro; "I" = integração com `Http::fake` usando as fixtures da Fase 0.

| # | Entrega | Casos do `11` | Verificação | Depende de |
|---|---|---|---|---|
| F1.1 | Chave canônica, gerador de combinações, regeneração (adicionar ou remover eixo e valor, órfãs, copiar dados) | TC-03, 05, 06, 07, 08, 09, 10, 11, 12, 13 | U | — |
| F1.2 | `CategorySchema` + `ClassificadorAtributos` + `MigradorDeCategoria` + flags da Fase 2 | TC-30, 31, 34, 35, 36, 37, 39, 40, 41, 70, 71, 72, 73, 74 | U sobre as fixtures das 4 categorias; "nenhum `if categoria ==`" conferido por busca no código | 0a |
| F1.3 | Resolvedor de grupos de imagem (grupo, lista final por variante, união do legado, limites) | TC-50, 51, 52, 53, 54, 55, 60 | U | F1.1, F1.2 |
| F1.4 | Builders legado e UP + `PayloadPlan` (alvos, guarda RN-03, N/A, unidade, `ITEM_CONDITION`, `read_only` fora) | TC-01, 02, 03, 04, 05, 14, 15, 31, 39, 41, 43, 90 | U + snapshots JSON (legado e UP lado a lado) | F1.1–F1.3, 0b para H-02/H-26 |
| F1.5 | Validador L1/L2 (matriz `08` + `05` §10 + `06` §8) + simulador "Você recebe" | TC-14, 20–25, 38, 42, 43, 56, 57, 100, 101, 102, 104, 105 | U | F1.2–F1.4 |
| F1.6 | 15 migrations + models + `RascunhoRepository` (banco ⇄ snapshot) + comando de migração do Anunciar antigo | TC-05, 10, 58 (persistência); migração em simulação contra uma cópia local | SQLite nos testes + migration no MariaDB local com `--path` | F1.1–F1.5 |
| F1.7 | `ContaMlService` + `CategorySchemaRepository` (TTL de 24h, hash, nunca guarda falha) + `ClienteMlPublicador` | TC-75, 87, 88, 89 | I | F1.6, 0b |
| F1.8 | Imagens: upload local (sha256, dimensões, deduplicação), validação L1, fila de envio ao ML com concorrência 2 e espera progressiva | TC-56, 57, 58, 59 | I | F1.6, F1.7 |
| F1.9 | L3: condicionais (com espera de digitação), `validate` de cada payload em Job, `plano_hash`, falsos positivos configuráveis | TC-32, 33, 92, 103 | I | F1.4, F1.7, F1.8 |
| F1.10 | Orquestrador (`PublicarRascunhoJob` em fatias), estados por item, reconciliação, retentar falhas, descrição, `GET /items/{id}`, cadastro na régua, registro bruto | TC-80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 91 | I (com reentrega simulada do Job) | F1.9 |
| F1.11 | Tela: 4 telas, formulário dinâmico, eixos e grade, grupos de imagem, condições de venda, revisão, painel de validação, lista de ofertas lendo a L2 | TC-70 (visual) | Teste visual com Puppeteer (padrão do projeto) com as 3 categorias; `npm run build` | F1.2–F1.10 (por API) |
| F1.12 | **E2E** com a conta de teste, título "Item de teste - Não ofertar", fechado ao final, **só com a sua confirmação antes de cada `POST /items`** | TC-93, 94 | E | Tudo |

### 6.3 Fase 2 — detectado e bloqueado com mensagem

| Item | Como detectar | Mensagem |
|---|---|---|
| Tabela de medidas (moda) | Atributo com tag `grid_template_required` ou `value_type` `grid_id`/`grid_row_id` (`SIZE_GRID_ID`; o `MlGradeService` já sabe detectar) | "Esta categoria exige tabela de medidas, que o Publicador ainda não suporta. Publique pelo Mercado Livre por enquanto." |
| Catálogo obrigatório | **[HIP]** O campo exato sai do 0a (`settings.catalog_domain` e tags do domínio) | "Esta categoria exige publicação via catálogo, que ainda não é suportada." |
| Compatibilidades (autopeças) | **[HIP·H-13]** Sinal a definir no 0a. Existe `MlCompatibilidadeService` no admin para a Fase 2. | "Esta categoria pede veículos compatíveis — ainda não suportado." |
| Estoque por depósito | Tag `warehouse_management` da conta (V-VAR-20) | "Sua conta usa estoque por depósito, que ainda não é suportado." |
| Edição depois de publicar | Rascunho PUBLISHED fica somente leitura | "Para alterar, edite no Mercado Livre." |
| Atacado, notificações `items`/`user-products-families` | Não oferecidos | — |
| Recondicionado | Se o H-04 não se resolver na Fase 0 | Opção desabilitada, com explicação |

---

## 7. Riscos e perguntas para você

### 7.1 Perguntas (por ordem de impacto no desenho)

1. **Lugar e entrada.** O novo Publicador substitui o formulário do Anunciar do portal e mantém a lista de ofertas à esquerda? E o `/incubadora/publicador` criado hoje (`933d3abc`): vira a entrada "do zero" (sem oferta) do mesmo Publicador, ou fica parado? Isso decide se `pub_rascunhos.oferta_id` é obrigatório. Os clientes da Incubadora não têm ofertas no Mapeamento.
2. **O par.** Mantenho "um rascunho → Clássico e Premium, títulos diferentes, mesmo SKU" (decisão D1)? Ou o Publicador novo publica um tipo por vez?
3. **Fase 0b.** Você autoriza rodar a sondagem **na VPS** com o token de quais contas? Sugiro ByMobille-Teste (#298) e Dev 02 Teste (#356). E qual conta gerou os prints do Seller Center?
4. **UP na régua.** No UP, N variantes viram N MLBs por tipo. Registro todos na oferta, na aba Anúncios?
5. **Preço por variante.** No UP, cada variante herda o preço da Precificação (por oferta e tipo) e só muda se a pessoa digitar?
6. **SKU por variante.** Sugestão editável `<SKU da oferta>-<sigla do valor>`. E uma oferta com SKU de mentira ("Não tenho"): bloqueio, já que o SKU é a chave da reconciliação?
7. **Job.** A publicação vira Job na fila `high`, com a tela acompanhando o andamento, em vez de ser síncrona como diz o ADR PORTAL-03 (decisão D9)?
8. **Dados existentes.** Migro os rascunhos não publicados como descrito na §4.3 e deixo `estrutura_publicacoes` só para leitura? Você autoriza (ou roda) a contagem por status em produção, que o classificador barrou?
9. **Falso positivo do `validate`.** Para `lost_me1_by_user`, aceito "aviso com Estou ciente" em vez de bloquear (decisão D7)?
10. **Admin `/mlb/anuncios`.** Fica fora até 20/10? Quer o conserto isolado da guarda RN-03 (V1) lá, e a remoção do EAN aleatório?
11. **Prints do Seller Center.** Ainda não chegaram. Eles mudam a apresentação da F1.11, não o núcleo.
13. **Builder legado (01/10).** Nenhuma das 33 contas de cliente usa o modelo antigo. Proposta: o builder legado deixa a Fase 1; conta no modelo antigo é detectada e bloqueada com mensagem clara. Isso muda a decisão [ARQ] do `01` §4.3 ("os dois builders na Fase 1"), e a justificativa é a medição.
14. **Contas com `warehouse_management` (01/10).** 23 das 33. A spec manda bloqueá-las na Fase 1. Proposta: rodar o `validate` (sem publicar) numa delas para ver o que o ML exige no estoque, e só então decidir se bloqueia ou se o Publicador as atende.
12. **Conta clássica (pergunta nova, 01/10).** **Respondida:** autorizado usar conta de cliente só para conferência — mas não existe nenhuma no modelo antigo. A #459 é UP. Para fechar o legado (H-26 e TC-93) é preciso uma conta **sem** `user_product_seller`. Existe alguma conectada que possa ser usada só para `validate`? Sem ela, o builder legado sai testado só contra a documentação e as fixtures.

### 7.2 Riscos

| Risco | Efeito | Mitigação |
|---|---|---|
| A tela (F1.11) é o maior bloco e está no fim | Estoura o prazo | API completa e testada antes (F1.1–F1.10); cortes na §8 |
| A Fase 0b depende de autorização e de dia útil | O H-01 decide o caminho principal | Pedir no dia 02/10; F1.1 e F1.2 andam com o 0a enquanto isso |
| A Incubadora tem 0 Company e 0 token em produção (01/10) | O Publicador fica pronto e ninguém publica | Cadastro e OAuth dos clientes em dia útil, em paralelo |
| `validate` com falsos positivos | Publicação legítima bloqueada | Decisão D7, configurável |
| Família UP quebrada (H-18) | Variantes aparecem separadas no ML | V-VAR-19: atributos PRODUCT idênticos por construção; conferir `family_id` no E2E |
| Duplicidade no `POST /items` (H-25) | Anúncio em dobro na conta do cliente | `SENT` antes do POST + reconciliação + trava; nunca mais de um reenvio sem reconciliar |
| Reentrega de Job (`retry_after`) | POST duplicado | Fatias de 45 s + `SENT` antes do POST (decisão D9) |
| Mexer no motor antigo afeta o `/mlb/anuncios` | Regressão no admin | Motor novo separado; o antigo fica intacto |
| SQLite dos testes não pega MariaDB | Migration quebra no deploy | Rodar no MariaDB local com `--path` antes do commit |
| Dois devs, checkout principal 1.385 commits atrás | Trabalho sobre código velho, ou sobrescrever o outro | Branch própria num worktree novo a partir do `origin/main`; `git commit -- <caminhos>` |

---

## 8. Estimativa (prazo 20/10/2026)

Em dias de trabalho. O calendário usa fins de semana e o feriado de 12/10. Dependências de terceiros (você, contas de teste, ML) só em dia útil.

| Entrega | Dias | Janela |
|---|---|---|
| F0 (0a + 0b) | 1 | 02/10 (sex) |
| F1.1 combinações | 0,5 | 02–03/10 |
| F1.2 schema e classificador | 1 | 03/10 |
| F1.3 grupos de imagem | 0,5 | 04/10 |
| F1.4 builders e plano | 1 | 04–05/10 |
| F1.5 L1/L2 e simulador | 1 | 05–06/10 |
| F1.6 dados e migração | 1 | 06–07/10 |
| F1.7 conta, schema e cliente HTTP | 0,5 | 07/10 |
| F1.8 imagens | 1 | 08/10 |
| F1.9 L3 | 1 | 09/10 |
| F1.10 orquestrador | 1,5 | 10–11/10 |
| F1.11 tela | 4,5 | 12–16/10 |
| F1.12 E2E (com sua confirmação) | 1,5 | 16–17/10 (sexta é dia útil) |
| Folga, revisão e deploy (com autorização) | 3 | 18–20/10 |
| **Total** | **19** | |

**Confiança: média.** O núcleo puro e as regras são previsíveis. A tela e os imprevistos da API real não são.

**Cortes, nesta ordem, se atrasar:**

1. Simulador "Você recebe" (H-14): mostra só a tarifa.
2. Eixo customizado (TC-04/11/12): fica bloqueado com mensagem.
3. "Copiar dados ao adicionar eixo" (TC-09): as variantes novas começam vazias.
4. Seção "Avançado" com os atributos `hidden`.
5. Avisos de plausibilidade (V-ATT-11).
6. Recondicionado.

Nenhum corte toca nas regras [ML] nem na reconciliação.
