# Fase 167: Cadastro de Produto no Mapeamento Estrutural — Pesquisa

**Pesquisado em:** 2026-10-05 (worktree `C:/tmp/ecf-publicador-spec-261001`, HEAD `5d98973d`)
**Domínio:** Portal do Cliente (Laravel 12 + Inertia + React) — submódulo novo `Produtos` do Mapeamento Estrutural (`estrutura_*`), com ALTER em `estrutura_ofertas` (tabela com dado em produção), grade editável, importação `.xlsx`, categoria/frete do Mercado Livre.
**Confiança geral:** ALTA para o que está no código e na planilha (lido nesta sessão); MÉDIA para o comportamento real do `shipping_options/free` com dimensões (a própria base documenta que o ML pode ignorar as dimensões em algumas categorias); MÉDIA para a tabela de frete reserva (cruzada com fontes públicas, vigência a reconfirmar).

<user_constraints>
## User Constraints (de 167-CONTEXT.md)

### Locked Decisions

**Quem cadastra e onde**
- **D-01:** Produtos é submódulo do Mapeamento Estrutural e é ancorado em **Company**, como todo o `estrutura_*` (empresa sempre do `PortalContexto`, nunca do request). Empresa polo sem Company (Polos, e hoje a Incubadora) fica de fora: o cliente da Incubadora entra "como cliente novo" e ganha Company + Portal no onboarding. Ter Company e acesso ao Portal para as empresas da Incubadora é pré-condição operacional, não código desta fase.
- **D-02:** Cliente (pelo Portal) **e** equipe (pela entrada de equipe `/companies/{company}/portal`, permissão `core.onboarding` + carteira em `PortalEquipeService::podeEntrar()`) cadastram e editam. O activity log grava a origem `cliente` | `interno`, como no resto do Mapeamento (o `causer_id` não distingue — learnings `portal-do-cliente.md` §12).

**Modelo do produto**
- **D-03:** O **produto** corresponde ao "Grupo (anúncio)" da planilha e guarda: nome, família, ambiente(s) e categoria do ML. Cada **variação** corresponde à "Ref" da planilha (ex.: `1014-1`, `1014-2`) e guarda: código (SKU), o valor da variação (eixo + valor, ex.: Cor = Natural), os **volumes** (lista de caixas, cada uma com C×L×A em cm e peso em kg), o peso total (soma dos volumes) e o custo.
- **D-04:** Medidas, peso e custo são **por variação** (na planilha há mesa cujas cores pesam diferente). Ao criar uma variação nova, os dados da primeira são copiados para a pessoa só ajustar o que muda.
- **D-05:** Família e ambiente são **listas da própria empresa**: criadas uma vez e depois só escolhidas (acaba o "Sala estar" × "Sala Estar" da planilha). Ambiente admite vários por produto ("Sala Jantar / Sala Estar"). Serve a qualquer segmento (móveis, peças, pet) porque as combinações só acontecem dentro do catálogo da mesma empresa.
- **D-06:** Categoria = **categoria real do ML** (grava o `category_id`), sugerida pelo nome do produto com o caminho inteiro da árvore, como o Publicador já faz. Motivo: na fase seguinte a tarifa de cada categoria vem da API (`listing_prices`) — na planilha só 1 das 24 categorias tinha tarifa confirmada.
- **D-07:** **Nome "família" — atenção.** Na planilha, Família = linha de design (Farmhouse, Palhinha Slim). No código existente, "família" (Precificação do Onboarding, `agruparFamilias` em `resources/js/lib/precificacaoProdutos.js`) quer dizer "o mesmo produto em várias cores" — que na planilha é Grupo + Variação. O campo novo segue a planilha. Também não colidir com `pub_produtos` (o "produto" do Publicador é, na prática, uma oferta a publicar).

**Produto ↔ oferta**
- **D-08:** Cadastrar um produto **cria sozinho a oferta simples** (fase `simples`) na Lista SKUs, **uma por variação** — como a planilha faz (Cristaleira 1014 com 2 cores → ofertas A002-V1 e A002-V2) — ligada à variação. Combo, kit e combit não nascem nesta fase.
- **D-09:** As ofertas que já existem (criadas à mão ou importadas do ML, ex.: 500 na #131) **ficam como estão**: sem casamento por SKU, sem criar produto para elas. Produto novo com o SKU de uma oferta antiga gera outra oferta — SKU repetido, que a Lista SKUs já aceita e avisa (ADR PORTAL-01).
- **D-10:** O **custo mora na variação do produto**. A oferta simples ligada usa esse custo (a Precificação mostra o custo vindo do produto); combo/kit/combit continuam somando os componentes (ADR PORTAL-02, "custo pelos componentes"). Oferta sem produto segue com o custo digitado na Precificação, como hoje.
- **D-11:** Ligar oferta → variação altera `estrutura_ofertas`, tabela com dado em produção — por isso esta fase é GSD completa (CLAUDE.md): baseline de testes antes de mexer, desenho da migration por escrito antes de existir, armadilhas de MariaDB (learnings `desempenho-bonificacao.md` §6), VERIFICATION no fim.

**Como os produtos entram**
- **D-12:** A **tela é o caminho principal** ("acho muito mais eficiente fazer os cadastros na tela, no sistema mesmo" — o usuário). Formato: **tabela editável** no jeito da aba Produtos — uma linha por variação, Tab/Enter para andar, colar várias linhas copiadas do Excel, família/ambiente/categoria como escolha na célula, e a logística e o frete aparecendo na própria linha. Reaproveitar o `SpreadsheetGrid`. Tem de ser rápida para cadastrar ~70 produtos de uma vez.
- **D-13:** **Planilha-modelo**: o cliente baixa o modelo (colunas da aba Produtos), preenche fora e importa no Portal; o sistema mostra a prévia antes de gravar.
- **D-14:** Reimportar = **acrescentar e atualizar**, casando pelo código da variação; nada é apagado. A prévia separa novos / atualizados / sem mudança (mesmo padrão da colagem de anúncios do Mapeamento).

**Envio pelas dimensões**
- **D-15:** Assim que medidas e peso estão preenchidos, cada variação mostra **logística provável**, **peso cubado** e **frete ME2**. Regras da planilha (aba Parâmetros), aplicadas ao pacote:
  - peso cubado = C×L×A ÷ 6000; só vale acima de 5 kg cubados (peso faturado = maior entre real e cubado);
  - **ME2**: peso real ≤ 30 kg, soma dos lados ≤ 200 cm, maior lado ≤ 100 cm;
  - **Full elegível**: cabe no ME2 **e** peso real ≤ 20 kg **e** maior lado ≤ 80 cm;
  - fora do ME2 → **ME1** (frete pela tabela da transportadora do cliente; não calculado nesta fase);
  - sem medidas → "Pendente: completar cadastro".
- **D-16:** Frete ME2 vem da **API do ML** com a conta do cliente (`GET /users/{seller_id}/shipping_options/free`, o mesmo cálculo do Publicador, valor de `coverage.all_country.list_cost` — reflete a reputação dele). Sem conta do ML conectada, usa a **tabela da ECF** (aba "Frete ML Verde" da planilha) e mostra que é estimativa.
- **D-17:** Produto em **vários volumes** é **empilhado num pacote** para o cálculo: maior comprimento, maior largura, alturas somadas, pesos somados (Cristaleira 1015: 186×43×12 + 97×42×12 → 186×43×24, 39,9 kg → ME1).
- **D-18:** Logística e frete de **kits e combos** (pacote juntando produtos diferentes) ficam para a fase de geração das ofertas.

### Claude's Discretion
- **Preço usado para cotar o frete.** O frete do ML depende da faixa de preço, e o preço sai do custo + frete. Estimar o preço pelo custo da variação com os parâmetros da Precificação da empresa (`estrutura_precificacao_parametros`) e avisar quando ele cai perto/abaixo do limite de frete grátis obrigatório — esse limite NUNCA fica fixo no código (hoje R$ 79; regra do Publicador, learnings `publicador-ml.md`). A planilha supôs a faixa "a partir de R$ 200" e alertava abaixo disso.
- **Onde ficam as regras Full/ME2/cubagem e a tabela de frete reserva:** são regras do ML, iguais para todos — configuração global (não por empresa). Formato e atualização a critério do planejamento.
- **Posição do submódulo no menu:** proposta na conversa de 05/10 (sem objeção) como o primeiro, antes da Lista SKUs: Produtos → Lista SKUs → Precificação → Anúncios → Planejamento → Mapeamento (`ModulosPortal`).
- **Produto/variação alterado ou excluído:** o que acontece com a oferta simples ligada (sincronizar nome/SKU; bloquear exclusão quando a oferta tem anúncios ou entra em combo — `restrict` do PORTAL-01 — ou soltar o vínculo, como o D27 da Fase 164). Seguir os precedentes.
- **"Produto completo":** mostrar o que falta por linha (sem medidas, sem custo, sem categoria, ME1 sem frete), no espírito da coluna "Alertas" da planilha.
- **Formato do modelo para download** (.xlsx com PhpSpreadsheet, já no projeto) e como os volumes viram colunas.
- **Cache/lote das consultas de frete** (limite por minuto da conta — padrão `ClienteMlPublicador`).
- **Nomes de tabelas e colunas** (prefixo `estrutura_`, sem `enum`, índices com nome curto).

### Deferred Ideas (OUT OF SCOPE)
- **Geração automática das ofertas** combo/kit/combit a partir de família + ambiente + par de categorias que combina (aba Planejamento) — próxima fase.
- Logística e frete de **kits e combos** (pacote juntando produtos).
- **Precificação da planilha**: grade de margem 30/20/10/0, overprice, desconto da Central de Promoções, tarifa real por categoria pela API.
- **Cronograma** por capacidade por fase, checklist de 13 alavancas e status Atrasado/Hoje (aba Cronograma).
- **Fotos (mais de 7), vídeo, ficha técnica, descrição e dados fiscais** no produto — pedidos na reunião.
- Criar produtos a partir dos **anúncios que o cliente já tem no ML** ("relançar o que já vende").
- Trazer a **Planilha de Produtos do Onboarding** (`/implementacao/{token}`).
- Produtos para **empresa polo sem Company** (Polos).
- Frete **ME1** pela tabela da transportadora do cliente.
- Renomear a lista "Produtos" do Publicador (que são ofertas a publicar) para não confundir com o catálogo.
</user_constraints>

<phase_requirements>
## Requisitos da Fase (definidos nesta pesquisa — o ROADMAP diz "TBD")

| ID | Descrição | Decisões | O que na pesquisa dá suporte |
|----|-----------|----------|------------------------------|
| PR167-01 | Submódulo **Produtos** (1º do menu) acessível a cliente e equipe, empresa só do `PortalContexto`, rota na allowlist do domínio do cliente, id de outra empresa responde 404, cada escrita grava `origem` cliente/interno no activity log | D-01, D-02 | §Integração com o Portal (ModulosPortal, allowlist, `RegistroEstrutura`), §Segurança |
| PR167-02 | Modelo produto → variações → volumes (C×L×A cm + kg), código único por empresa, peso total derivado da soma; nova variação nasce copiando a primeira | D-03, D-04 | §Schema (6 tabelas), planilha (colunas/regras) |
| PR167-03 | Listas **família** e **ambiente** por empresa (criar uma vez, escolher depois; ambiente N:N; normalização sem caixa/acento/espaços; recusa de `/` `,` `\|` no nome; "em uso" bloqueia exclusão) | D-05, D-07 | §Schema, §Armadilhas (collation), planilha (grafias divergentes medidas) |
| PR167-04 | Categoria real do ML (`category_id` + nome + caminho gravados), sugerida pelo nome com caminho inteiro; só folha; nunca auto-aceita | D-06 | §Categoria (`CategoriaSugestaoService::sugerir/detalhe`) |
| PR167-05 | Cada variação nova cria **uma oferta simples** ligada (`estrutura_ofertas.variacao_id`, unique); sku/nome da oferta acompanham a variação; ofertas existentes intocadas (sem backfill); migration idempotente e verificada em MariaDB 10.4 | D-08, D-09, D-11 | §Schema, §Migration, §Oferta ligada |
| PR167-06 | Custo mora na variação: `EstruturaPrecificacaoService::pagina()` usa o custo da variação para oferta ligada (origem `produto`, campo somente leitura na Precificação), combos somam os componentes, oferta sem produto igual a hoje; Publicador (preço efetivo, custo do anúncio) herda sem mudar | D-10 | §Custo (lista completa de leitores) |
| PR167-07 | Tela de cadastro rápido em tabela editável (uma linha por variação, Tab/Enter, colar do Excel crescendo linhas, escolha família/ambiente múltiplo/categoria, colunas calculadas somente leitura), persistida por linha no servidor | D-12 | §Grade (SpreadsheetGrid: lacunas e extensões) |
| PR167-08 | Planilha-modelo `.xlsx` (colunas da aba Produtos) + importação com prévia (novos / atualizados / sem mudança / erros), "acrescentar e atualizar" casando pelo código da variação, nada apagado; aceita a aba Produtos original sem reformatar | D-13, D-14 | §Importação/Modelo |
| PR167-09 | Logística provável, peso cubado/faturado e pacote empilhado calculados por classe PHP pura com as regras globais em config (gabarito: 70 simples → 55 ME1, 8 ME2, 6 ME2·Full, 1 pendente) | D-15, D-17 | §Logística |
| PR167-10 | Frete ME2: API do ML com o token da empresa quando conectado (cache + lote, `getMany`), tabela ECF reservada e rotulada "estimativa"; preço de cotação derivado da Precificação; limite de frete grátis nunca hardcoded | D-16 + Discricionário | §Frete |
| PR167-11 | "Produto completo": pendências por linha (sem medidas, sem custo, sem categoria, sem família, ME1 sem frete) | Discricionário | §Pendências |
| PR167-12 | Ciclo de vida: editar sincroniza sku/nome da oferta; excluir variação/produto reusa `EstruturaOfertaService::excluir` (bloqueia se for componente; anúncios voltam à espera; D27 do Publicador); oferta ligada não pode ser excluída/ter fase, sku ou nome alterados pela Lista SKUs | Discricionário | §Ciclo de vida |
| PR167-13 | Kit/combo/combit não recebem logística nem frete nesta fase (só oferta simples ligada) | D-18 | §Logística |
| PR167-14 | Gate de segurança do dado: baseline de testes registrado antes de mexer, migration verificada com `SHOW CREATE TABLE`/`SHOW INDEX` no MariaDB 10.4 local, `down()` testado, VERIFICATION no fim | D-11 | §Validation Architecture |
</phase_requirements>

## Project Constraints (de CLAUDE.md)

- **Idioma:** tudo que o GSD escreve em **pt-BR** (planos, resumos, commits, comentários de código); termos técnicos consagrados e identificadores ficam como estão.
- **Stack travada:** Laravel 12 + Inertia + React 18, Tailwind com tokens `ecf-*`, `cn()` de `@/lib/utils`; nenhuma troca de stack.
- **GSD obrigatório aqui** porque a fase altera tabela existente com dado em produção (`estrutura_ofertas`): baseline de testes, desenho da migration por escrito antes de existir, VERIFICATION.
- **Armadilhas de MariaDB** (`desempenho-bonificacao.md` §6): nome de índice > 64 chars (1059), `nullOnDelete` só em coluna nullable (1830), não dropar índice usado por FK (1553), nada de `enum`.
- **Commits:** `git commit -- <caminhos>` — nunca `git add -A`/`git add .` (árvore compartilhada). Conferir `git show <sha>` antes do push (o commit por caminho já arrastou arquivo de outra sessão).
- **`npm run build` ao fim de qualquer alteração de frontend.** Em worktree, conferir o manifest (a página nova tem de aparecer em `public/build/manifest.json`; re-export puro some do manifest).
- **Nenhum deploy sem autorização explícita.** Nenhuma escrita na conta ML de cliente (só leitura; `shipping_options/free` é GET).
- **Descoberta cara e não dedutível → `.planning/learnings/`** (candidatos nesta fase: ver §Learnings a registrar).
- **Testes:** a suíte inteira estoura 512 MB — rodar por diretório. Cuidado com o autoloader compartilhado entre worktrees (confirmado nesta sessão: o `vendor/` deste worktree carrega `App\` da própria árvore — `ReflectionClass(...)->getFileName()` devolveu `C:\tmp\ecf-publicador-spec-261001\app\...`).

## Resumo

A fase é majoritariamente **aditiva**: seis tabelas novas (`estrutura_familias`, `estrutura_ambientes`, `estrutura_produtos`, `estrutura_produto_ambiente`, `estrutura_produto_variacoes`, `estrutura_produto_volumes`) e **um único ALTER** em tabela viva: `estrutura_ofertas.variacao_id` (bigint unsigned **nullable**, unique, FK `nullOnDelete`). A tabela hoje não tem nenhuma outra migration de alteração (só `create`), tem índice `eo_company_idx` e FK `eo_company_fk`, e no MariaDB local conferido tem 13 linhas (a de produção é maior: 500 só na #131). Não há backfill (D-09): nenhuma oferta existente ganha produto. O precedente exato de "coluna nullable + FK `nullOnDelete` + unique + checagem de existência por `information_schema`" já existe no repositório (`2026_10_02_100000_create_pub_produtos_table`, `2026_10_02_100100_add_produto_id_to_pub_rascunhos`, incluindo o helper `emMysql()` que trata o driver `mariadb` do Laravel 11+) e deve ser copiado, não reinventado.

O custo é a parte que parece perigosa e não é: **todo** leitor de `estrutura_precificacoes.custo` passa por `EstruturaPrecificacaoService::pagina()/calcular()` (Controller da Precificação, `DadosEfetivosService`, `CustoDoAnuncioService`, `MigracaoAnunciarAntigo`, `SoltarProdutoDaOfertaService`). Basta fazer `pagina()` montar o mapa de custos com o custo da variação para as ofertas ligadas (e `salvarOferta` recusar `custo` nelas). O `PublicadorSincronizaPortalService` lê só `id, sku, nome` da oferta — não vê custo.

Os pontos que mais exigem atenção do plano: (1) **o `SpreadsheetGrid` tem lacunas reais para D-12** (colar mais linhas do que existem é descartado em silêncio; `select` só aceita `string[]`; persistência é do array inteiro; não é virtualizado) — a pesquisa recomenda estendê-lo de forma aditiva ou, se o usuário preferir, usar a grade Glide que o repositório já tem; ver Pergunta Aberta 1; (2) **a coluna "Variação" da planilha real é um ordinal** (`1`, `2`, `3`, `única`), não "eixo + valor": o modelo guarda `ordem` + `eixo`/`valor` opcionais; (3) **55 de 70 produtos reais são ME1**, então o frete ME2 pela API só se aplica a ~20% das linhas — o lote de cotação é pequeno; (4) o frete do ML varia por faixa de preço e o preço sai do custo+frete: usar ponto fixo em no máximo 2 iterações, com preço de referência quando falta custo.

**Recomendação principal:** Produtos = 6 tabelas novas + 1 coluna nullable em `estrutura_ofertas` (duas migrations separadas, a do ALTER idempotente); regras logísticas em classe PHP pura + `config/estrutura_produtos.php`; o servidor é a única fonte dos campos calculados (a tela só exibe); custo da oferta ligada lido da variação dentro de `EstruturaPrecificacaoService::pagina()`; cadastro e importação passam pelo mesmo serviço de gravação, que cria/sincroniza as ofertas por `EstruturaOfertaService`.

## Mapa de Responsabilidade por Camada

| Capacidade | Camada principal | Secundária | Justificativa |
|------------|------------------|------------|---------------|
| Cadastro/edição de produto, variação, volumes | API / Backend (service) | Browser (grade) | Regra de negócio e validação no PHP; a grade só coleta |
| Listas família/ambiente por empresa | API / Backend | Banco | Normalização e "em uso" são regra; unique no banco é a rede |
| Cálculo de pacote, peso cubado, logística provável | API / Backend (classe pura) | — | Uma só implementação; a Calculadora já tinha duas cópias e publicou preço 43% errado (PORTAL-02) |
| Frete ME2 (API do ML) | API / Backend | Cache | Token do cliente nunca vai ao navegador; cache + lote no servidor |
| Frete reserva (tabela ECF) | API / Backend (config global) | — | Regra do ML igual para todos (decisão discricionária do CONTEXT) |
| Sugestão de categoria | API / Backend (app token) | Browser (picker) | Dado público; reaproveita cache `ml_meta_categoria_*` |
| Tabela editável (colar, escolher, navegar) | Browser | — | Interação; persistência por linha chamada ao servidor |
| Planilha-modelo e importação | API / Backend (PhpSpreadsheet) | Browser (upload/prévia) | Parse seguro de arquivo só no servidor |
| Custo da oferta simples ligada | API / Backend (`EstruturaPrecificacaoService`) | Banco | Um único ponto de leitura já existe |
| Menu/allowlist do domínio | API / Backend (`ModulosPortal`, middleware) | — | Rota nova nasce bloqueada no domínio do cliente se esquecida |

## Stack Padrão

### Núcleo (tudo já está no projeto — nenhuma dependência nova)

| Biblioteca | Versão (lockfile/instalada) | Uso | Por que |
|-----------|----------------------------|-----|---------|
| `phpoffice/phpspreadsheet` | 2.4.5 [VERIFIED: composer.lock] | Modelo `.xlsx` (escrita) e importação (leitura) | Já usado: `PolosController::streamXlsx` (escrita em streaming) e `ImportarDemandasDevPlanilha` (`IOFactory::createReaderForFile(...)->setReadDataOnly(true)`) |
| `@glideapps/glide-data-grid` + `-cells` | 6.0.3 [VERIFIED: node_modules] | Alternativa de grade (ver Pergunta Aberta 1); `-cells` exporta `MultiSelectCell`, `DropdownCell` | Já em uso em `Pages/Mlb/GradeAnuncioGlide.jsx` |
| `SpreadsheetGrid.jsx` (componente local) | 1.084 linhas [VERIFIED: leitura] | Grade pedida por D-12 | Já usado em `Pages/Mlb/ImplementacaoPublica.jsx` |
| `MercadoLivreService::getMany()` | método local | Lote paralelo (`Http::pool`, lotes de 10, refaz o que falhar com `get()`) para cotar frete | Já pensado para "uma chamada por item" |
| `ClienteMlPublicador::daConta/publico` | classe local | Chamada única com token da conta, 401→renova, 429→espera | Padrão do Publicador (cuidado: `sleep` bloqueante, ver Armadilhas) |
| `Incubadora\Publicador\CategoriaSugestaoService` | classe local | `sugerir($nome)` + `detalhe($id)` (id por nível do caminho, `folha`) | Melhor encaixe que `CategoriaBuscaService` (ver §Categoria) |
| Spatie `laravel-activitylog` via `RegistroEstrutura` | já no projeto | Trilha com `origem` | Mesmo canal `portal` do Mapeamento |

### Alternativas consideradas

| Em vez de | Poderia usar | Trade-off |
|-----------|--------------|-----------|
| `SpreadsheetGrid` estendido (D-12 literal) | Grade Glide (canvas) já no repo | Glide: virtualizada, colar cria linhas, `MultiSelectCell` pronto, precedente de editor customizado; mas canvas (sem DOM para teste de UI) e foge da letra do D-12 — **decisão do usuário** |
| Persistir frete cotado em colunas da variação | Cache (`Cache::put`, TTL 6 h) | Colunas = mais schema em tabela nova mas estável entre sessões; cache = zero schema, some após TTL e volta a "estimativa". **Recomendado: cache** |
| Parse de `.xlsx` no navegador (SheetJS) | PhpSpreadsheet no servidor | SheetJS = dependência nova e parse duplicado; servidor já tem a regra de negócio |

**Instalação:** nenhuma (`composer`/`npm` inalterados).
**Verificação de versão:** `composer.lock` → `phpoffice/phpspreadsheet 2.4.5`; `node_modules/@glideapps/*/package.json` → `6.0.3`; PHP 8.2.12, MariaDB 10.4.32 local, Node v26.9.0 (medidos).

## Auditoria de Legitimidade de Pacotes

| Pacote | Registro | Idade | Downloads | Repo | slopcheck | Disposição |
|--------|----------|-------|-----------|------|-----------|-----------|
| (nenhum pacote novo) | — | — | — | — | n/a | Aprovado — todas as bibliotecas citadas já estão em `composer.lock`/`package-lock.json` e em uso |

**Pacotes removidos por [SLOP]:** nenhum.
**Pacotes [SUS]:** nenhum.
*slopcheck não foi executado porque a fase não instala pacote externo. Se o plano decidir acrescentar qualquer dependência (ex.: biblioteca de validação de planilha), deve gate-ar com `checkpoint:human-verify` antes de instalar.*

## Padrões de Arquitetura

### Diagrama do fluxo

```
Cliente / Equipe ──► /portal/estrutura/produtos (Inertia page, PortalClienteLayout)
        │                    │ props: produtos paginados, listas, vocabulário, ml_conectado
        │                    ▼
        │            Grade editável (SpreadsheetGrid | Glide)
        │                    │  linhas alteradas (debounce) ──► POST /produtos/linhas ┐
        │  .xlsx ──► POST /produtos/importacao/previa ──► JSON prévia                  │
        │            (confirma) POST /produtos/importacao  ──────────────────────────┤
        ▼                                                                              ▼
 middleware portal.auth ─► PortalContexto::empresa()/ator() ─► ProdutoCadastroService::gravarLinhas()
                                                                │  (1 transação por lote)
                       ┌────────────────────────────────────────┼──────────────────────────────────┐
                       ▼                                        ▼                                  ▼
          NormalizadorDeLinha (NumeroBr,                ListasDaEmpresa                   EstruturaOfertaService
          VolumesTexto, codigo único)                   (família/ambiente por             criar()/sincronizar():
                       │                                nome normalizado)                 oferta simples ligada,
                       ▼                                                                  varrerEspera(sku)
          estrutura_produtos / _variacoes / _volumes / _produto_ambiente                    │
                       │                                                                       ▼
                       ▼                                                       estrutura_ofertas.variacao_id
          LogisticaProduto (pura) ──► pacote, cubado, faturado, ME2/Full/ME1/pendente
                       │
                       ▼
          FreteMe2Service ──► cache ──► MercadoLivreService::getMany(Company) ──► GET /users/{id}/shipping_options/free
                       │                         (sem token ativo) ──► TabelaFreteEcf (config global, "estimativa")
                       ▼
          resposta por linha: campos calculados + pendências ──► a grade só exibe

 Precificação: EstruturaPrecificacaoService::pagina() ── custo da variação p/ ofertas ligadas ──► DadosEfetivosService /
               CustoDoAnuncioService / MigracaoAnunciarAntigo (herdam sem mudança)
 Categoria:    célula "Categoria" ──► GET /produtos/categorias?q= ──► CategoriaSugestaoService (app token, cache)
```

### Estrutura de arquivos recomendada

```
app/Models/EstruturaFamilia.php, EstruturaAmbiente.php, EstruturaProduto.php,
           EstruturaProdutoVariacao.php, EstruturaProdutoVolume.php
app/Services/Portal/Estrutura/Produtos/
   ProdutoCadastroService.php     # gravarLinhas(), excluirVariacao(), excluirProduto() — ÚNICO caminho de escrita
   ListasDaEmpresaService.php     # família/ambiente: criar/renomear/excluir, normalização
   LogisticaProduto.php           # funções puras (pacote, cubado, ME2/Full/ME1)
   FreteMe2Service.php            # cotar() com cache + lote; TabelaFreteEcf
   TabelaFreteEcf.php             # lookup puro sobre config
   ImportadorProdutos.php         # plano() / aplicar() sobre linhas normalizadas (xlsx → linhas)
   ModeloProdutosXlsx.php         # gera o .xlsx de modelo
   VolumesTexto.php               # "186×43×12 · 27.8 | 97×42×12 · 12.1" ⇄ lista de volumes
   NumeroBr.php                   # "27,8" / "1.234,50" / "27.8" → float
   PendenciasDoProduto.php        # "produto completo" por linha
app/Http/Controllers/PortalEstruturaProdutosController.php   # NÃO engordar PortalEstruturaController (531 linhas)
config/estrutura_produtos.php     # limites ME2/Full, fator de cubagem, faixas de preço/peso do frete reserva
resources/js/Pages/Portal/EstruturaProdutos.jsx
resources/js/Components/Portal/Estrutura/Produtos/   # picker de categoria, gestão das listas, prévia da importação
resources/js/lib/produtosEstrutura.js                # só formatação/colunas (NÃO colidir com precificacaoProdutos.js, D-07)
database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php
database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php
```

### Padrão 1: um único serviço de escrita (grade, importação e API passam por ele)
**O quê:** `ProdutoCadastroService::gravarLinhas(Company, array $linhas, AtorDoPortal, string $modo)`; cada linha = uma variação (com os campos do produto repetidos, como na planilha). Faz: normalizar → validar por linha → upsert de produto (chave: `codigo` do grupo ou id) → upsert de variação (chave: id, ou código normalizado dentro da empresa) → substituir volumes → sincronizar a oferta (criar ou atualizar sku/nome) → um `RegistroEstrutura::registrar` **por lote** (evento `produtos_gravados`, com totais), como `anuncios_colados`.
**Quando:** sempre. A importação só difere na **prévia** (mesmo normalizador, sem gravar) — igual `ColagemAnunciosService::plano()` + `aplicar()`, onde a confirmação refaz o plano do zero (o navegador não manda "o que gravar").
**Erros:** linha inválida volta em `erros[]` com número e mensagem e **não** impede as demais (a colagem faz assim: "N linha(s) com erro não gravada(s)"); violação de unique na corrida → `QueryException` 23000 capturada e traduzida (precedente: `PublicadorSincronizaPortalService`).

### Padrão 2: servidor calcula, tela exibe
Cada resposta de gravação devolve, por linha, `{ id, produto_id, volumes_texto, n_volumes, peso_total, pacote, peso_cubado, peso_faturado, logistica, frete: {valor, origem, preco_usado, cotado_em}, pendencias[] }`. A grade tem colunas `compute: row => row.logistica_rotulo` (que só leem o campo devolvido). Isso segue a decisão do PORTAL-02 ("a conta roda no PHP") e evita a segunda cópia que já custou caro.

### Padrão 3: configuração global em arquivo próprio
`config/estrutura_produtos.php` (precedente: `config/publicador.php`, "toda hipótese vira opção aqui em vez de regra fixa"): `fator_cubagem` 6000, `peso_cubado_minimo` 5, `me2` {peso 30, soma 200, maior 100}, `full` {peso 20, maior 80}, `frete.preco_referencia`, `frete.vigente_desde`, `frete.reputacao` ('verde'), `frete.faixas_preco` (limites inferiores 0, 19, 49, 79, 100, 120, 150, 200) e `frete.tabela` (29 linhas de peso × 8 faixas). Os limites de faixa de preço são **dado da tabela do ML**, não a regra de frete grátis obrigatório — nenhum `79` aparece em lógica (ver Armadilha 9).

### Anti-padrões a evitar
- **Duplicar a conta no JS** (cubagem/ME2 em `produtosEstrutura.js`): foi o defeito do Anunciar/Calculadora; só formatação no JS.
- **Gravar o frete/preço na variação** como verdade: o preço "nunca é gravado" (PORTAL-02); o frete cotado é cache.
- **Deixar a Lista SKUs editar sku/nome/fase de oferta ligada**: cria duas verdades (ver §Ciclo de vida).
- **Aceitar a sugestão de categoria automaticamente** (categoria errada contamina a tarifa da fase seguinte).
- **Chamar o ML por tecla/por linha numa colagem de 70**: usar cache + lote + só linhas ME2-elegíveis.
- **Mexer em `PortalEstruturaController`** além do mínimo: já tem 531 linhas; controller novo.

## Não Reinvente

| Problema | Não construa | Use | Por quê |
|----------|--------------|-----|---------|
| Normalizar SKU para casar | outro `strtolower` | `EstruturaOferta::normalizarSku()` | Regra única (trim + minúsculas; vazio→null) |
| Registro de autoria | `Log::info` solto | `RegistroEstrutura::registrar()` | Grava `origem` cliente/interno; `causer_id` não distingue |
| Prévia/confirmação | token de sessão com arquivo | Padrão `plano()`/`aplicar()` stateless (reenvia o arquivo/texto) | Entre prévia e confirmar a base pode mudar |
| Criar/excluir oferta | `EstruturaOferta::create/delete` direto | `EstruturaOfertaService::criar/excluir` (+ método novo estreito para sincronizar sku/nome) | Já faz `varrerEspera`, componentes, D27 do Publicador, log |
| Preço/custo | outra fórmula | `PrecificacaoEstrutura::preco/custo/parametros` + `EstruturaPrecificacaoParametros::daEmpresa` | Mesma conta da Calculadora; funções puras |
| Lote paralelo no ML | `Http::pool` à mão | `MercadoLivreService::getMany(Company, ['chave' => [endpoint, query]])` | Reaproveita token/refresh e refaz o que falhou |
| Árvore de categoria | chamar `/categories/{id}` direto | `CategoriaSugestaoService` (cache `ml_meta_categoria_{id}` 7 dias) | Cache compartilhado com o wizard admin; falha não é cacheada |
| Gerar `.xlsx` | CSV "para abrir no Excel" | PhpSpreadsheet (padrão de `PolosController::streamXlsx`) | Acentos, cabeçalho estilizado, tipos de célula |
| Detecção de driver nas migrations | `=== 'mysql'` | helper `emMysql()` (mysql **ou** mariadb) | Laravel 11+ tem driver `mariadb`; a comparação estrita já mandou MariaDB para `PRAGMA` (WR-B06) |
| Parse de número BR | `floatval` | classe `NumeroBr` nova e testada (não existe parser PHP compartilhado — medido) | "27,8", "27.8", "1.234,50" |

## Schema (decisão escrita ANTES da migration — D-11 / CLAUDE.md disciplina 2)

Convenções herdadas: prefixo `estrutura_`; **sem `enum`** (varchar + constante no model); `timestamps()` nullable (nenhum `timestamp()` solto — MariaDB dá `ON UPDATE CURRENT_TIMESTAMP` à primeira coluna TIMESTAMP NOT NULL, `portal-do-cliente.md` §18); nomes de índice e FK **explícitos e curtos** (< 64); nenhum `json` (no MariaDB 10.4 é LONGTEXT) — guardar texto.

| Tabela | Colunas | Índices / FKs |
|--------|---------|----------------|
| `estrutura_familias` | `id`; `company_id` FK; `nome` varchar(80); `timestamps` | FK `efam_company_fk` → companies **cascade**; unique `efam_company_nome_uq (company_id, nome)` |
| `estrutura_ambientes` | idem | FK `eamb_company_fk` cascade; unique `eamb_company_nome_uq` |
| `estrutura_produtos` | `id`; `company_id` FK; `codigo` varchar(120) **nullable** (o "Grupo" da planilha); `nome` varchar(255); `familia_id` unsigned bigint **nullable**; `categoria_ml_id` varchar(20) nullable; `categoria_ml_nome` varchar(255) nullable; `categoria_ml_caminho` text nullable ("Casa > Móveis > Cristaleiras"); `timestamps` | FK `epr_company_fk` cascade; FK `epr_familia_fk` → estrutura_familias **nullOnDelete** (coluna nullable → sem erro 1830); unique `epr_company_cod_uq (company_id, codigo)` (NULL repete); index `epr_familia_idx` |
| `estrutura_produto_ambiente` | `produto_id`, `ambiente_id`; sem timestamps | PK composta; FK `epa_produto_fk` cascade; FK `epa_ambiente_fk` cascade; index `epa_ambiente_idx (ambiente_id)` |
| `estrutura_produto_variacoes` | `id`; `produto_id` FK; `company_id` FK (**denormalizada de propósito**, para o unique por empresa); `ordem` smallint unsigned default 1; `codigo` varchar(120); `eixo` varchar(30) nullable; `valor` varchar(80) nullable; `custo` decimal(12,2) nullable (mesmo tipo de `estrutura_precificacoes.custo`); `timestamps` | FK `epv_produto_fk` cascade; FK `epv_company_fk` cascade; unique `epv_company_cod_uq (company_id, codigo)`; index `epv_produto_idx (produto_id, ordem)` |
| `estrutura_produto_volumes` | `id`; `variacao_id` FK; `ordem` smallint unsigned; `comprimento` decimal(7,2); `largura` decimal(7,2); `altura` decimal(7,2); `peso` decimal(8,3) (kg) | FK `epvol_variacao_fk` cascade; unique `epvol_variacao_ordem_uq (variacao_id, ordem)` |

**Peso total e "Nº volumes" não são colunas** — derivam da soma/contagem dos volumes (na planilha real `Peso total` = soma dos pesos dos volumes, conferido em 1015 e 1045-3). Duas verdades divergem; uma só é derivada.

**ALTER (migration separada, só depois das tabelas):** `estrutura_ofertas.variacao_id` unsigned bigint **nullable**, unique `eo_variacao_uq`, FK `eo_variacao_fk` → `estrutura_produto_variacoes.id` **`nullOnDelete`**. Sem backfill.

### Por que `nullOnDelete` e não `restrict` na FK nova (decisão com motivo)
- A coluna é nullable → `SET NULL` é válido (o erro 1830 só ocorre em coluna NOT NULL, `desempenho-bonificacao.md` §6).
- `RESTRICT` no InnoDB é verificado **imediatamente** (não é diferido): na exclusão em cascata de uma `Company` (existe — `ExclusaoDaEmpresaPreservaHistoricoTest`) `companies → estrutura_produto_variacoes` e `companies → estrutura_ofertas` correm em cascata e a ordem entre elas não é garantida; um `RESTRICT` cruzado pode falhar com 1451. `SET NULL` não tem esse problema.
- A proteção real é de **serviço** (ver §Ciclo de vida) e do **unique** em `variacao_id` (no máximo uma oferta simples por variação — protege D-08 contra criação dupla por corrida/reimportação).
- Pelo mesmo motivo, as FKs da pivot são **cascade** nos dois lados e "ambiente/família em uso" é recusado no serviço (mensagem "usada por N produtos"), não por `RESTRICT`.

### Plano da migration do ALTER (copiar o esqueleto de `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php`)
1. `up()`: (a) `Schema::hasColumn` → adiciona coluna nullable; (b) unique sob `hasIndex('estrutura_ofertas','eo_variacao_uq')`; (c) FK sob `hasForeignKey(..., 'eo_variacao_fk')` — **cada DDL num `Schema::table` separado**, existência checada por `information_schema` (mysql **e** mariadb, `emMysql()`) e `PRAGMA` no SQLite; **proibido `try/catch` em volta de DDL** (o 1553 de `2026_07_09_140001` foi engolido assim por 2 meses).
2. `down()`: FK (`dropForeign('eo_variacao_fk')`; no SQLite pela forma de coluna) → unique → coluna, nessa ordem; cada um sob checagem de existência. O rollback descarta só o **vínculo** (a coluna), nunca a oferta — documentar no docblock; não precisa recusar.
3. Não dropar nem recriar `eo_company_idx`/`eo_company_fk`. Não há índice de `estrutura_ofertas` envolvido em FK que precise ser alterado → não toca o 1553.
4. Migration das tabelas novas **separada** (se falhar no meio não deixa "tabela criada sem índice + migration Pending").
5. Timestamps das migrations: **`2026_10_06_*`** (a última do repositório é `2026_10_05_100100`).
6. Verificação obrigatória (D-11/PR167-14), no MariaDB 10.4 local **compartilhado** (usar `--path`, para não arrastar migrations pendentes de outras sessões — 5 de `2026_10_0*` já estão registradas lá): `php artisan migrate --path=database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php` e depois a do ALTER; `SHOW CREATE TABLE estrutura_ofertas` (coluna, `eo_variacao_uq`, `eo_variacao_fk ... ON DELETE SET NULL`), `SHOW INDEX FROM estrutura_produto_variacoes`; `migrate:rollback --path` das duas (na ordem inversa) e subir de novo (idempotência); `SELECT COUNT(*) FROM estrutura_ofertas` antes e depois (igual). Em produção a tabela tem centenas/milhares de linhas: `ADD COLUMN` nullable sem default é barato no MariaDB 10.4 (instant add), mas medir a contagem real antes do deploy (`bash .vps_cmd.sh` lê produção, escrita barrada) — registrar o número no VERIFICATION.
7. Acrescentar as duas migrations à lista `MIGRACOES` de `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (a varredura barra `=== 'mysql'`) ou criar teste-irmão com o mesmo desenho.

## Integração com o Portal

- **`ModulosPortal::SUBMODULOS['estrutura']`**: inserir `'produtos' => ['rotulo' => 'Produtos', 'rota_auth' => 'portal.auth.estrutura.produtos']` **como primeiro** (PHP preserva a ordem de inserção). A chave ativa é `estrutura.produtos` (`contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.produtos', ...)`); passar só `estrutura` acende o módulo e nenhum submódulo (`portal-do-cliente.md` §28).
- **Testes que vão quebrar de propósito e precisam ser atualizados** (lista de submódulos é asserida literalmente): `tests/Feature/PortalCliente/PortalSemAnunciarTest.php:110-124` (`test_o_mapeamento_estrutural_tem_cinco_submodulos`) e `tests/Feature/PortalCliente/Estrutura/AcessoAoModuloEstruturaTest.php:60`. Não é regressão: é o contrato mudando.
- **`/portal/estrutura` (`entrada()`)** hoje redireciona para a Lista SKUs (teste na linha 48 do mesmo arquivo). Manter ou apontar para Produtos? Ver Pergunta Aberta 6.
- **Allowlist `RestringeDominioDoPortal::PERMITIDO`**: uma linha por rota nova (ex.: `portal/estrutura/produtos`, `.../produtos/modelo`, `.../produtos/linhas`, `.../produtos/variacoes/*`, `.../produtos/importacao`, `.../produtos/importacao/previa`, `.../produtos/familias`, `.../produtos/familias/*`, `.../produtos/ambientes`, `.../produtos/ambientes/*`, `.../produtos/categorias`, `.../produtos/fretes`). Atenção: `Str::is('a/*')` casa também `a/5/b` (o `*` atravessa `/`), então os `*` existentes são mais largos do que parecem — não criar `portal/estrutura/produtos/*` genérico "para facilitar". **Rede de segurança já existe:** `DominioLiberaTodoModuloTest` varre o router e falha se uma rota `portal.auth.*` não estiver liberada (rodado nesta sessão: 7 testes verdes).
- **Rotas** (em `routes/web.php`, dentro do grupo `portal.auth`, **cada uma com `throttle:N,1,<prefixo próprio>`** — o terceiro parâmetro é o prefixo da chave; sem ele todas dividem um contador e a colagem dava 429 por causa das escritas anteriores, `web.php:177-185`) e `whereNumber` nos ids. Sugestão de limites: página GET livre; `linhas` 120/min (autosave por linha); `importacao/previa` 10/min; `importacao` 6/min; `categorias` 60/min; `fretes` 20/min; listas 60/min.
- **Controller novo** `PortalEstruturaProdutosController`: toda resolução de id dentro da empresa do `PortalContexto::empresa()` (`...::where('company_id', $empresa->id)->findOrFail($id)`); id de outra empresa = 404 (mesma mensagem do inexistente). **A empresa nunca vem do request.**
- **Equipe:** a entrada de equipe (`PortalEquipeService::podeEntrar()`: admin, ou `core.onboarding` + carteira) cai no mesmo grupo `portal.auth`; `PortalContexto::ator()` devolve `AtorDoPortal::daEquipe`, e `RegistroEstrutura` grava `origem = interno`. Nada específico a fazer além de **sempre** passar `PortalContexto::ator()` ao serviço (um serviço que esquecesse o ator gravaria sem origem). A reunião de 05/10 diz que a Vitória (operadora da equipe) vai ser quem mais cadastra — o caminho de equipe é o caminho quente, não a exceção.
- **Flash:** usar a chave `success` (já compartilhada). Chave nova exige linha em `HandleInertiaRequests` (`portal-do-cliente.md` §19).

## Custo: como vai para o produto sem quebrar (D-10)

**Todos os leitores de `estrutura_precificacoes.custo` (medido por grep em `app/`, `resources/js`, `routes`):**

| Leitor | Como lê | Muda? |
|--------|---------|-------|
| `EstruturaPrecificacaoService::pagina()` (linhas 32-38) | monta `$custos = linhas->map(custo)` indexado por `oferta_id` | **SIM — único ponto a alterar** |
| `EstruturaPrecificacaoService::calcular()` (linha 114) | `PrecificacaoEstrutura::custo($linha?->custo, $o['componentes'], $custos)` | **SIM** (passa o custo da variação como "digitado" quando ligada; origem `produto`) |
| `EstruturaPrecificacaoService::salvarOferta()` | grava `custo` | **SIM** — recusar `custo` em oferta ligada (422 "o custo vem do Produtos") |
| `PortalEstruturaController::precificacaoIndex/salvarPrecificacao` | chama o service | só repassa; `only([...'custo'...])` continua, o service decide |
| `DadosEfetivosService::daOferta` | `pagina($empresa,[$id])['por_oferta'][$id]` | não — herda |
| `Alavancas\CustoDoAnuncioService::custos` | `pagina(...)` e lê `$calculo['custo']['valor']` | não — herda (D-11 do Publicador: "sem custo, nada de pedir") |
| `MigracaoAnunciarAntigo` (linha 157) | `pagina(...)` | não — herda |
| `SoltarProdutoDaOfertaService` | via `DadosEfetivosService::daOferta` | não — herda |
| `PublicadorSincronizaPortalService` | lê `id, sku, nome` de `estrutura_ofertas`; **não vê custo** | não |
| JSX `EstruturaPrecificacao.jsx` (linhas 199-250) | só trata origem `digitado` como editável | **SIM** — origem `produto`: campo somente leitura + texto "vem do Produtos" e link para a página |

**Regra para `pagina()`:** para oferta com `variacao_id`, o custo "digitado" **é** `variacoes.custo` (inclusive `null` = sem custo) e **ignora** qualquer `estrutura_precificacoes.custo` antigo (evita um custo digitado velho vencer o do produto). Como `$custos` alimenta também o cálculo de combo/kit/combit (`PrecificacaoEstrutura::custo` usa `$custosDigitados[$componente['id']]`), combos de simples ligadas passam a somar o custo do produto **sem nenhuma alteração em `PrecificacaoEstrutura`** (função pura, só ganha o rótulo de origem no serviço). Implementação sugerida: `ProdutoCustos::daEmpresa(Company): array<oferta_id, ?float>` (um join `estrutura_ofertas × estrutura_produto_variacoes` filtrado por `company_id`), chamado uma vez em `pagina()`.

**Testes que cobrem a herança** (devem ganhar um caso de oferta ligada): `PrecificacaoEstruturaTest` (gabarito cadeira+mesa), `DadosEfetivosTest`, `Alavancas/CustoDoAnuncioTest`, `MigracaoAnunciarAntigoTest`, `SincronizaPortalTest`.

## Oferta ligada e ciclo de vida (D-08, D-09 e discricionário)

- **Criar:** `EstruturaOfertaService::criar` hoje só aceita `sku, fase, nome, logistica, observacoes, componentes`. Acrescentar suporte a `variacao_id` (campo `fillable` + parâmetro opcional) **ou** um método irmão `criarParaVariacao`. Reaproveitar `criar` mantém a regra de composição, `varrerEspera` (anúncios colados esperando aquele SKU são absorvidos) e o log `oferta_criada`. Consequência a documentar: produto novo cujo código coincide com o SKU de oferta antiga gera **SKU repetido** (D-09) e a `varrerEspera` passa as linhas da espera desse SKU para o motivo `sku_repetido`.
- **Nome da oferta:** sugestão `produto.nome` (+ `" — {valor}"` quando a variação tem valor); SKU = `variacao.codigo`.
- **Editar variação/produto:** método estreito `sincronizarDaVariacao(EstruturaOferta, sku, nome)` em `EstruturaOfertaService` (não usar `atualizar()`, que reescreve fase e componentes). Ele roda a mesma `varrerEspera([skuAntigo, skuNovo])` e loga. Mudança de código da variação muda o SKU da oferta; **anúncios já ligados continuam ligados** (vínculo é por `oferta_id`, não por SKU).
- **Oferta ligada é protegida na Lista SKUs:** `EstruturaOfertaService::atualizar` ignora/recusa `sku`, `nome` e `fase` quando `variacao_id !== null` (logística e observações continuam editáveis); `excluir` recusa com "Esta oferta vem do Produtos; exclua a variação lá". Invariante: **toda variação tem exatamente uma oferta simples** (unique + serviço). A Lista SKUs mostra um selo "do Produtos" (acrescentar `variacao_id` ao `get([...])` de `EstruturaConjunto::daEmpresa` e ao array de `EstruturaVisaoService::oferta`).
- **Excluir variação/produto:** chamar `EstruturaOfertaService::excluir($oferta, $ator, viaProduto: true)` **dentro** da transação e só depois apagar a variação. Assim valem de graça: bloqueio quando a oferta é componente de combo/kit/combit (mensagem lista as ofertas que a usam — o `restrict` do PORTAL-01 em nível de serviço), anúncios voltam à **espera** (não somem), e `SoltarProdutoDaOfertaService::antesDeExcluir` congela título/preço no `pub_produto` (D27 da 164). **Recomendação ao CONTEXT ("bloquear se tem anúncios OU soltar"):** seguir o precedente (não bloquear por ter anúncios; a confirmação da UI diz quantos anúncios voltam à espera). Precedente verificado em `EstruturaOfertaService::excluir`.
- **Excluir lista (família/ambiente):** recusa se em uso ("usada por N produtos"); renomear é permitido (os produtos acompanham porque apontam por id).
- **Produto sem nenhuma variação** não deve existir: excluir a última variação exclui o produto (ou o serviço recusa produto sem variação).

## Grade editável (D-12): `SpreadsheetGrid` — o que serve, o que falta

Verificado em `resources/js/Components/SpreadsheetGrid.jsx` e no uso em `ImplementacaoPublica.jsx`.

| Necessidade (D-12) | `SpreadsheetGrid` hoje | Lacuna |
|--------------------|------------------------|--------|
| Tab/Enter/setas, Ctrl+Z/Y, fill handle, filtro, ordenação, agrupar | Sim (`handleKeyDown`, `applyMulti`, histórico de 100 snapshots JSON) | — |
| Colar várias linhas do Excel | `Ctrl+V` lê `navigator.clipboard.readText()` e aplica **só onde `tr < R`** (`R` = linhas exibidas, incl. preenchimento de `minRows`) | **Colar mais linhas que as existentes descarta o excedente em silêncio.** `readText()` também exige permissão do navegador (Firefox só em contexto de colar). Precisa: crescer linhas e usar o evento DOM `paste` (`clipboardData.getData('text')`) |
| Célula de escolha | `type: 'select'` com `options: string[]` | Serve a **família**. Não serve categoria (busca remota, id + caminho) |
| Escolha múltipla (ambiente) | `type: 'tags'` (valor = string separada por vírgula, `options: string[]`) | Serve, **desde que nome de ambiente não tenha vírgula** (recusar `, / |` no cadastro da lista). Comita ao sair da célula |
| Colunas calculadas somente leitura | `compute(row)` ou `type: 'readonly'` — texto simples | Serve para logística/cubado/frete **se vierem do servidor** em campos da linha; sem badge colorido (só classe via `conditionalFormat`) |
| Persistência | `onChange(newRows)` com o **array inteiro**; na Onboarding o autosave grava o blob todo (debounce 800 ms) | Precisa de **diff por linha no nível da página** (id estável por linha; comparar anterior × novo; enviar só as alteradas ao `POST /linhas`; mesclar a resposta). Não exige mudar a grade |
| Performance 70–500 linhas | Não virtualizada: DOM com uma `div` por célula; `JSON.stringify` do array a cada edição para histórico | ≤ ~200 linhas por página é tranquilo; 500 × ~16 colunas é o limite do razoável. Paginar no servidor (convenção do módulo, `portal-do-cliente.md` §27) |
| Autocomplete de categoria | não existe | `type: 'picker'` novo (editor popup, como o `TextareaPopup` já faz para texto longo) |

**Recomendação (honra o D-12):** estender o `SpreadsheetGrid` de forma **aditiva e com props opcionais** (default = comportamento atual, para não regredir a Planilha de Produtos do Onboarding, página pública com cliente): (1) `growOnPaste` + captura do evento `paste`; (2) coluna `type: 'picker'` com `renderEditor`/`onPick`; (3) `rowKey` + callback `onRowsCommit(prev, next)` opcional. Todo o resto (colunas, selects, tags, computed) usa o que existe. Cobrir com gate estrutural em `tests/js/` (padrão `lerSemComentarios`).
**Alternativa documentada (Pergunta Aberta 1):** a grade **Glide** (`GradeAnuncioGlide.jsx`) já resolve colar-cresce-linhas (`onCellsEdited` cria as linhas que faltam), virtualização, fill handle, `rangeSelect`, `DropdownCell` e há `MultiSelectCell` em `glide-data-grid-cells@6.0.3` (verificado em `dist/dts/index.d.ts`); picker de categoria exigiria `provideEditor` customizado (há precedente: `origemCellRenderer`, linha ~148). Ela foge da letra do D-12 ("reaproveitar o SpreadsheetGrid") — só adotar com o ok do usuário.

**UX que a pesquisa recomenda independentemente da grade:** uma linha por variação, campos de produto (grupo, nome, família, ambiente, categoria) repetidos na linha como na planilha; alterar campo de **produto** numa linha propaga para todas as variações do mesmo grupo (o servidor aplica e devolve o grupo); "+ variação" cria linha pré-preenchida copiando a primeira (D-04) e sugere o próximo código (`{grupo}-{n+1}`); linha só é enviada quando tem **código e nome** (antes disso vive só no navegador); erro por linha aparece na linha, não bloqueia as outras.

## Categoria do ML por nome (D-06)

| Serviço | Entrega | Token | Cache | Encaixe |
|---------|---------|-------|-------|---------|
| `Incubadora\Publicador\CategoriaSugestaoService` | `sugerir($nome)` → até 8 `{id, nome, dominio, caminho:[{id,nome}]}`; `detalhe($id)` → `{id, nome, caminho, folha}` | app token (`MlColetaService::getAppToken`) | preditor 1 h; `GET /categories/{id}` 7 dias, chave `ml_meta_categoria_{id}` (compartilhada); lê categorias em `Http::pool`; falha não cacheada | **Recomendado**: tem o id por nível e `folha` — necessários para gravar `category_id` e **validar que é folha** |
| `Publicador\CategoriaBuscaService` | `categorias($q)` com caminho só como nomes | app token | idem | caminho sem ids/sem `folha`; foi extraído do Anunciar do Portal |
| `Mlb\Publicacao\MlCatalogoMetaService` | `preverCategoria`, `categoria`, `atributos` | app token | TTL 1 h/7 dias | camada de baixo, os dois acima já a usam |

- Dado **público** → app token, **nunca** o token do cliente; funciona para empresa sem OAuth (a maioria dos clientes novos da Incubadora). Nenhuma allowlist de domínio do ML é necessária no código (é saída HTTP do servidor); a **rota do portal** entra na allowlist do middleware.
- `GET /portal/estrutura/produtos/categorias?q=` (throttle 60/min) para o picker; `POST .../categorias/sugerir` em **lotes de ≤ 10 linhas** para "sugerir para os pendentes" (cada sugestão ≈ 1 chamada de preditor + multiget de categorias em paralelo; 70 linhas em uma requisição passariam de 20 s). O cliente chama em laço com barra de progresso; **nunca grava sozinho** — mostra a 1ª sugestão com "aceitar" por linha / "aceitar marcadas".
- Gravar `categoria_ml_id` + `categoria_ml_nome` + `categoria_ml_caminho` (texto "A > B > C") no produto: a grade não chama o ML a cada render. Ao salvar um id vindo de colagem/importação, validar `^MLB\d+$` e (se o ML responder) `detalhe()->folha === true`; ML fora do ar → aceitar com aviso "não validada" (não bloquear o cadastro).
- Na **importação da planilha real** a coluna "Categoria ML" são **nomes** de 24 categorias plurais ("Cristaleiras", "Mesas de Jantar"), não ids. Não resolver tudo na prévia (70 chamadas): importar com categoria **pendente**, exibindo o texto original como dica, e usar o laço de sugestão.

## Logística e frete (D-15, D-16, D-17)

### Regras — verificadas na planilha (aba Planejamento, fórmulas)
- Pacote (D-17): `C = maior comprimento`, `L = maior largura`, `A = soma das alturas`, `peso real = soma dos pesos` (conferido em 1015: 186×43×12 + 97×42×12 → 186×43×24; 27,8 + 12,1 = 39,9). **Não reordenar** as medidas dos volumes (a ordem C×L×A é a digitada); `maior lado` e `soma dos lados` independem da ordem.
- `V = MAX(C,L,A)`, `W = C+L+A`, `cubado = C·L·A / 6000`, `faturado = cubado > 5 ? MAX(real, cubado) : real`.
- **Fórmula exata da coluna AA** (a pedido do CONTEXT): `PENDENTE` se não há medida; senão `ME2` se `X ≤ ME2.peso AND W ≤ ME2.soma AND V ≤ ME2.maior` onde **`X` é o peso REAL** (coluna X = soma dos pesos da aba Produtos, não o faturado); dentro do ME2, `Full elegível` se `X ≤ 20 AND V ≤ 80`; fora do ME2 → `ME1`.
- Valores dos parâmetros (aba Parâmetros): fator 6000; cubado mínimo 5; Full ≤ 20 kg / ≤ 80 cm; ME2 ≤ 30 kg / ≤ 200 cm somados / ≤ 100 cm o maior.
- Frete reserva (aba "Frete ML Verde", `AC`): `INDEX(coluna J, MATCH(Z − 0,0001, faixas_de_peso, 1))` — faixa de peso = **maior limite inferior ≤ (peso faturado − 0,0001)** (peso exatamente 0,3 cai em "até 0,3"; exatamente 5 kg em "de 4 a 5"); a planilha usa sempre a coluna **"a partir de R$ 200"**; ME1/pendente ficam sem frete; o alerta da coluna `AZ` ("Preço CP abaixo de R$ 200 na menor MC: frete pode cair de faixa").

### Gabarito (medido na planilha real, só agregados e medidas anônimas — **nenhum custo**)
70 ofertas simples (uma por variação) → **55 ME1, 8 ME2, 6 ME2·Full elegível, 1 Pendente**. Casos para teste unitário (medidas em cm C×L×A, peso real kg): `93×55×6, 9,5` → ME2 (cubado 5,12 > 5; faturado = real 9,5); `91×59×20, 13,5` → ME2 e faturado = cubado 17,90 (cubado > real); `89×57×52, 25` → ME2 (soma 198 ≤ 200) com faturado 43,97; `65,5×47×10,5, 9` → ME2·Full; `74,5×28×6, 2,7` → ME2·Full; `131×48×17, 33` → ME1 (33 > 30); `187×44×12, 28,5` → ME1 (soma 243 > 200); `186×43×24, 39,9` (empilhado) → ME1; sem volume → Pendente. **Consequência de produto:** só ~20% das linhas reais são ME2, logo a cotação pela API é pequena.
Verificação manual local (não commitar): rodar o importador contra o `.xlsx` real e conferir a contagem acima.

### Frete ME2 pela API (D-16)
- **Chamada** (a mesma do Publicador, `EditorRascunhoService::simular()` linhas 277-281): `GET /users/{seller_id}/shipping_options/free` com `item_price`, `listing_type_id`, `mode=me2`, `condition=new`, `logistic_type=drop_off`, `dimensions`, `verbose=true`; valor em `coverage.all_country.list_cost`. **`dimensions` = `"{altura}x{largura}x{comprimento},{peso_em_GRAMAS}"`**, centímetros inteiros (`dimensoes()` arredonda a inteiro; `MlFreteService::cotar` documenta o mesmo formato). A ordem é **altura, largura, comprimento** (não C×L×A). O frete do ME2 não muda entre Clássico e Premium (PORTAL-02) → cotar com `gold_special`.
- **Token da empresa a partir de uma `Company`:** `Company implements ContaMercadoLivre`; conectada = `$empresa->mlToken?->status === 'active'` (`AnunciosMercadoLivreService::conectado`); seller id = `$empresa->mlToken->ml_user_id` (padrão do `MlFreteService`). **Lote:** `MercadoLivreService::getMany($empresa, ['{variacaoId}' => ["/users/{$sellerId}/shipping_options/free", $query]])` — `Http::pool` em lotes de 10, o que falhar é refeito por `get()` (renova token e honra `Retry-After`); levanta `RuntimeException` sem token → capturar e cair na tabela. `ClienteMlPublicador::daConta` é melhor para **uma** chamada com classificação de erro, mas faz `sleep()` (até 16 s por tentativa, 5 tentativas em 429) dentro da requisição web — não usar em laço.
- **Cache e lote (para 70 linhas não virarem 70 chamadas síncronas por tecla):** (1) a linha **sempre** sai com a estimativa da tabela ECF (zero chamadas, instantânea) e rótulo "estimativa"; (2) cotação real só para variação **ME2-elegível** (≈ 14 de 70), em **ação explícita** ("Cotar fretes no ML") e uma vez após importar/salvar; (3) `Cache::put("estrutura:frete:v1:{company_id}:{dimensions}:{round(preco)}", valor, 6h)`; (4) máx. ~12 cotações novas por requisição (2 pools de 10 no máximo), o resto devolve `pendente_cotacao` e a página repete o POST até zerar (mesmo padrão cliente-conduzido do laço de categorias — sem job/fila). Em produção a fila é **Redis** e a `default` vive cheia; um job disparado por clique deveria ir à `high`, mas este desenho nem precisa de job.
- **Sem conta ML ativa** (maioria dos clientes novos): só tabela ECF com selo "estimativa — tabela vigente desde 02/03/2026, reputação verde".
- **Preço usado para cotar (discricionário):** o frete depende da faixa de preço e o preço depende do frete. Ponto fixo limitado: `p0 = PrecificacaoEstrutura::preco(custo, frete=null, ...)['minimo']` (o preço pelo qual se **vende**, sem acréscimo — `PORTAL-02` "Promoção é por ele que se vende"); cota `f1` em `p0`; `p1 = preco(custo, f1, ...)`; se `p1` mudar de faixa/valor relevante, cota de novo em `p1`; **no máximo 2 re-cotações**; se ainda instável marca `frete_instavel`. Parâmetros = `EstruturaPrecificacaoParametros::daEmpresa` (Clássico). **Sem custo** não há preço: usar o preço de referência de `config('estrutura_produtos.frete.preco_referencia')` (200, como a planilha) e rotular "faixa de referência — informe o custo". Devolver `preco_usado` e a origem (`api`|`tabela_ecf`|`referencia`).
- **Aviso de frete grátis obrigatório (limite nunca no código):** com API, ler `coverage.all_country.discount.type === 'mandatory'` **e** ausência de `free_shipping_by_meli` na resposta cotada no preço estimado (`publicador-ml.md` §10: quem decide é `free_shipping_by_meli`, não o `discount.type`, que vem `mandatory` em todas as faixas); `free_shipping_by_meli = true` indica que o ML ainda banca naquele preço (preço abaixo da faixa). Sem API **não se deduz** frete grátis obrigatório da tabela — só se mostra "estimativa". Não existe literal `79` em lógica (as faixas 0/19/49/79/… são limites de coluna da tabela do ML, em config, com comentário dizendo que não são a regra de frete grátis).
- **Ressalva de confiança:** `MlFreteService` documenta (citando STACK.md linha 329) que **na maioria das categorias ME2 o ML pode ignorar as `dimensions` enviadas e usar as da categoria** — a cotação é indicativa. Tratar o valor como "frete estimado pelo ML" e confirmar com uma chamada real **somente-leitura** numa conta conectada antes de prometer precisão (item de VERIFICATION manual; regra do usuário: conta de cliente só leitura).

### Tabela reserva (config global)
29 faixas de peso (limites inferiores 0; 0,3; 0,5; 1; 1,5; 2; 3; 4; 5; 6; 7; 8; 9; 11; 13; 15; 17; 20; 25; 30; 40; 50; 60; 70; 80; 90; 100; 125; 150) × 8 faixas de preço (0; 19; 49; 79; 100; 120; 150; 200). Os valores saem da aba "Frete ML Verde" (fonte declarada: Central de Vendedores do ML, "custos de envio reputação verde/sem reputação", vigente desde 02/03/2026) — **copiar para o config na implementação** (é tabela pública do ML, não dado do cliente; não é o catálogo/custos). Cruzamento: fontes públicas confirmam "29 faixas de peso × 8 de preço × 3 reputações" e o valor de R$ 12,35 para 79–99,99 até 0,3 kg na reputação verde, idêntico ao da planilha [CITED: blog.bling.com.br/como-funciona-o-frete-do-mercado-livre, vendedorlucrativo.com.br/calculadora-frete-mercado-livre]. **A tabela é só da reputação verde**: reputação amarela/laranja paga mais — por isso a API (D-16) é a fonte preferida e a tabela é sempre "estimativa". Guardar `vigente_desde` no config e mostrar na UI.

## Pendências por linha — "produto completo"
`PendenciasDoProduto` (puro) devolve códigos: `sem_medidas` (logística "Pendente: completar cadastro"), `sem_peso`, `sem_custo`, `sem_categoria`, `sem_familia`, `sem_ambiente`, `me1_sem_frete` ("Frete ME1: preencher com a tabela do cliente" — fora do escopo calcular), `frete_estimado` (informativo), `preco_na_faixa_de_frete_gratis`. A coluna "Alertas" da grade lista os rótulos; contador de resumo no topo da página (padrão do resumo da Precificação).

## Modelo e importação de planilha (D-13, D-14)

**Estrutura real da aba Produtos** (71 linhas = cabeçalho + 70; sem fórmulas; sem validação de dados): `Ref · Grupo (anúncio) · Variação · Produto · Família · Ambiente · Categoria ML · Nº volumes · Volumes (C×L×A cm · kg) · Peso total (kg) · Custo (R$)`. Medido: 56 grupos / 70 variações; "Variação" ∈ {`1`: 48, `2`: 14, `3`: 1, `única`: 7} — **ordinal, não eixo+valor**; nas 7 `única`, `Ref` = `Grupo` (ex.: nome em maiúsculas), sem sufixo `-n`; 22 famílias; 11 grafias de ambiente (com duplicatas por caixa: `Sala estar`/`Sala Estar`, `Hall / Sala estar`/`Hall / Sala Estar`, `Quarto/Sala estar`) e separadores `" / "` ou `"/"`; 24 categorias (nomes); `Nº volumes`: 54×1, 13×2, 2×3 e **1 produto `SEM MEDIDAS` com 0**; volumes em texto `186×43×12 · 27.8 | 97×42×12 · 12.1` (caracteres `×` U+00D7 e `·` U+00B7; decimal com **ponto**); nome ≤ 66 chars.

- **Modelo para baixar:** `.xlsx` via PhpSpreadsheet com **as mesmas 11 colunas e nomes** da aba (o cliente que já tem a planilha do Emerson importa sem reformatar), aba "Produtos", uma linha de exemplo com **valores fictícios**, e uma segunda aba "Instruções" (como preencher volumes; "Variação" aceita `1`, `2`, `única`, `Cor: Natural`). Download por rota GET comum (link `<a href>`, não XHR) com cabeçalhos de download; precedente de escrita: `PolosController::streamXlsx` (`setCellValueExplicit` com tipo explícito — **texto sempre explícito** nas células do modelo, e se algum dia o modelo for pré-preenchido com dado do cliente, escapar valores que comecem com `= + - @` para evitar injeção de fórmula).
- **Leitura:** `IOFactory::createReaderForFile($caminho)->setReadDataOnly(true)`; ler a aba `Produtos` (senão a primeira); mapear colunas por **nome normalizado** (sem caixa/acento) e aceitar sinônimos (`Código`, `SKU`, `Ref`); **nunca `getCalculatedValue()`** (valores, não fórmulas). Limites: só `.xlsx`, ≤ 2 MB, ≤ 1.000 linhas, validar extensão **e** MIME/estrutura (zip), e rejeitar `.xlsm`. Usar o upload do Laravel (`$request->file()`), nunca o caminho do cliente.
- **Interpretação das células:** `Variação`: número ou `única` → `ordem` (sem eixo/valor); `Eixo: valor` → eixo+valor; texto simples → valor. `Ambiente`: dividir por `/` e `,`, aparar, casar com a lista **por chave normalizada** (minúsculas + sem acento + espaços colapsados) — nome novo vai para "criar na lista" na prévia (e a 1ª grafia vence). `Família`: idem. `Volumes`: `VolumesTexto::interpretar` (aceita `×`, `x`, `X`, `*`; `·`, `-`, `kg`; vírgula ou ponto decimal; separador de volumes `|`, `;` ou quebra de linha). `Nº volumes` e `Peso total` são **informativos**: divergência da soma → aviso (não erro). `Custo`: `NumeroBr`. `SEM MEDIDAS`/vazio → variação sem volumes (permitido; logística pendente). Linhas do mesmo `Grupo` com nome/família/ambiente/categoria diferentes → usa a 1ª e avisa.
- **Prévia e confirmação (padrão `ColagemAnunciosService`):** `ImportadorProdutos::plano(Company, $arquivo, $modo)` devolve `{erro_geral, colunas, totais, grupos:{novos, atualizados, sem_mudanca, erros}, criar_listas:{familias[], ambientes[]}}` com detalhe limitado (200 itens por grupo, totais inteiros). A confirmação (`aplicar`) **reenvia o arquivo** e refaz o plano antes de gravar (sem estado de servidor, como a colagem refaz do texto). "Atualizar" = casar por **código da variação normalizado dentro da empresa**; nada é apagado (não há modo "substituir"); comparar campo a campo para classificar "sem mudança". Escolha de modo (`acrescentar`/`acrescentar e atualizar`) é a única na tela.
- **Volumes nas colunas?** Recomendação: **uma célula de texto** (como a planilha), não N colunas de C/L/A/kg — mantém colar-do-Excel idêntico, o modelo igual à aba real e a grade com uma coluna de volumes; a decomposição fica no banco.

## Testes e ambiente de execução

- **Baseline (D-11) medido nesta sessão** (worktree, HEAD `5d98973d`, PHP 8.2.12, SQLite em memória): `tests/Feature/PortalCliente/Estrutura` → **83 testes, 724 asserções, OK, 24,6 s, 98 MB**; consumidores do Publicador (`DadosEfetivosTest`, `SincronizaPortalTest`, `MigracaoAnunciarAntigoTest`, `Alavancas/CustoDoAnuncioTest`) → **22 testes, 118 asserções, OK**; `DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` → **7 testes, 155 asserções, OK**. Registrar estes números em `167-BASELINE-TESTES.md` **antes** do primeiro commit que toque `estrutura_ofertas` (o CLAUDE.md diz que esse arquivo existe em 2 fases — é a hora de ser a terceira).
- **Comando:** `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit <caminho>` por **diretório** (a suíte inteira estoura 512 MB). **Não pipar para `tail`** (engole o exit code do phpunit — já aconteceu); usar `; echo "exit=$?"` ou `--log-junit`.
- **Autoloader:** o `vendor/` deste worktree carrega `App\` da própria árvore (confirmado com `ReflectionClass`); ainda assim, antes de acreditar em um teste verde, rodar a checagem do learnings (`grep -m3 "=> __DIR__" vendor/composer/autoload_static.php` mostra só pacotes aqui; o teste definitivo é o `ReflectionClass(...)->getFileName()`). Não rodar `composer dump-autoload`/`install` neste worktree sem verificar quem mais usa o `vendor`.
- **Fixtures do módulo:** `tests/Concerns/EntraNoPortal` (`clienteDoPortal`, `entrarNoPortal`; grava a sessão do guard `portal`, **não** `actingAs`), `tests/Concerns/GabaritoDaPlanilhaEstrutural` (`empresaDoGabarito`, `atorCliente`, `listaDoGabarito`). Teste de equipe: ver `EquipeNoPortalTest`.
- **JS:** `npm run test:js` = `node --test "tests/js/**/*.test.js"`; os testes do projeto são **gates estruturais lendo a fonte sem comentários** (`tests/js/_fonte.js::lerSemComentarios`), não testes de DOM. Seguir o padrão (identificador solto, import, fiação) para `EstruturaProdutos.jsx` e para as extensões do `SpreadsheetGrid`.
- **Pegadinhas de teste:** `Http::fake()` acumula e **o 1º stub vence** (montar o fake do `shipping_options/free` de uma vez só, por URL); `CACHE_STORE=array` nos testes (o cache de frete/categoria não vaza entre testes; limpar com `Cache::flush()` se um teste reutilizar chave); SQLite não reproduz collation/`ON UPDATE`/1059/1830/1553 — a prova dessas é a execução real no MariaDB 10.4 local (PR167-14).
- **Verificação visual local:** `.planning/learnings/verificacao-visual-local.md`; em worktree novo `npm run build` pode sair 0 sem buildar e `ASSET_URL` deve ficar vazio (tela branca) — conferir `public/build/manifest.json`.

## Armadilhas Comuns

1. **Colar 70 linhas na grade e só 10 entrarem** (`SpreadsheetGrid` descarta o excedente sem aviso). *Evitar:* `growOnPaste`; teste que cola N > linhas.
2. **Collation vs normalização:** MariaDB `utf8mb4_unicode_ci` é case-**e acento**-insensível e ignora espaço final (PAD SPACE); SQLite dos testes é binário; `normalizarSku` só faz trim+minúsculas. "Cômoda"/"Comoda" são iguais para o banco e diferentes para o PHP → 500 por unique. *Evitar:* normalizar nome de lista com `Str::ascii`+minúsculas+espaços colapsados **no PHP**, e capturar `QueryException` 23000 → mensagem de validação; teste com par acentuado.
3. **`restrict`/cascade cruzados na exclusão de `Company`** (1451). *Evitar:* `nullOnDelete` na FK nova e cascade na pivot; proteção por serviço.
4. **Migration em tabela viva falhando no meio** (1059/1830/1553, `Pending` com tabela criada). *Evitar:* tabelas novas e ALTER em migrations separadas; DDL em `Schema::table` separados e idempotentes; `emMysql()`; sem `try/catch` ao redor de DDL.
5. **Duas verdades do custo:** `estrutura_precificacoes.custo` velho vencendo o da variação. *Evitar:* regra "ligada ⇒ custo da variação, ignora o digitado"; `salvarOferta` recusa.
6. **Duas verdades de sku/nome:** edição na Lista SKUs divergindo do produto. *Evitar:* oferta ligada protegida (serviço + UI).
7. **"Família" com dois significados** (D-07). *Evitar:* identificadores `familia_id`/`EstruturaFamilia` só para linha de design; **não importar nem estender** `agruparFamilias` de `precificacaoProdutos.js`; copy da UI "Família (linha de design)" e dica.
8. **`*` da allowlist atravessa `/`.** *Evitar:* linhas específicas por rota; confiar em `DominioLiberaTodoModuloTest`.
9. **Limite de frete grátis em código** (R$ 79 já foi regra errada: `publicador-ml.md` §10). *Evitar:* decidir por `free_shipping_by_meli` da resposta; faixas só como dado de coluna da tabela; gate de teste que procura o literal `79` fora do config (padrão `lerSemComentarios`/grep na fonte PHP).
10. **Cotar frete na digitação** (70 chamadas por colagem, `sleep()` de `ClienteMlPublicador` travando o PHP-FPM). *Evitar:* estimativa local imediata + lote explícito via `getMany` + cache.
11. **Preço↔frete circular:** loop sem fim. *Evitar:* no máximo 2 re-cotações e `frete_instavel`.
12. **Parse numérico BR** (`27,8` vs `27.8` vs `1.234,5`): `floatval('27,8')` = 27. *Evitar:* `NumeroBr` testado; na dúvida entre milhar e decimal numa coluna de peso/medida tratar `.` como decimal; em custo, `^\d{1,3}(\.\d{3})+$` é milhar.
13. **Importação confiando no navegador:** a confirmação deve refazer o plano. *Evitar:* reenviar o arquivo; nada de "linhas para gravar" vindo do JSON da prévia.
14. **Upload de planilha:** zip bomb/arquivo enorme, fórmula, macro. *Evitar:* limites de tamanho/linhas, `.xlsx` apenas, `setReadDataOnly`, sem `getCalculatedValue`.
15. **Throttle sem prefixo próprio** faz as rotas dividirem contador e dar 429 espúrio (`web.php:177-185`). *Evitar:* terceiro parâmetro em toda rota.
16. **Variação sem oferta** (inconsistência D-08) depois de falha parcial. *Evitar:* tudo do lote numa transação; reconciliador idempotente (`ProdutoCadastroService::garantirOfertas`) que cria a oferta que faltar, chamado em toda gravação.
17. **Resposta de gravação enorme:** devolver só as linhas tocadas (e o grupo, quando campo de produto propagou), não a página inteira.
18. **Página React que some do manifest do Vite** quando é re-export puro — `EstruturaProdutos.jsx` precisa ser componente real.
19. **Sobrescrita por outra sessão:** árvore compartilhada — `git commit -- <caminhos>` e conferir `git show <sha>` antes do push; `routes/web.php` e `RestringeDominioDoPortal.php` são arquivos quentes (conflito provável com a Fase 165).

## Exemplos de Código

### ALTER idempotente (esqueleto — copiar helpers de `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php`)
```php
// Fonte: padrão do repositório (Fase 164-01). Cada DDL em Schema::table separado.
public function up(): void
{
    if (! Schema::hasColumn('estrutura_ofertas', 'variacao_id')) {
        Schema::table('estrutura_ofertas', fn (Blueprint $t) => $t->unsignedBigInteger('variacao_id')->nullable()->after('company_id'));
    }
    if (! $this->hasIndex('estrutura_ofertas', 'eo_variacao_uq')) {
        Schema::table('estrutura_ofertas', fn (Blueprint $t) => $t->unique('variacao_id', 'eo_variacao_uq'));
    }
    if (! $this->hasForeignKey('estrutura_ofertas', 'eo_variacao_fk')) {
        Schema::table('estrutura_ofertas', fn (Blueprint $t) =>
            $t->foreign('variacao_id', 'eo_variacao_fk')->references('id')->on('estrutura_produto_variacoes')->nullOnDelete());
    }
}
```

### Logística — função pura (regras vêm do config, nunca literais)
```php
// Fonte: fórmulas das colunas V:AA da aba Planejamento (verificadas) — usa o peso REAL nos limites.
final class LogisticaProduto
{
    /** @param list<array{c: float, l: float, a: float, kg: float}> $volumes */
    public static function pacote(array $volumes): ?array
    {
        if ($volumes === []) { return null; }
        return [
            'c' => max(array_column($volumes, 'c')), 'l' => max(array_column($volumes, 'l')),
            'a' => array_sum(array_column($volumes, 'a')), 'peso_real' => array_sum(array_column($volumes, 'kg')),
        ];
    }

    public static function avaliar(?array $p, array $r): array   // $r = config('estrutura_produtos')
    {
        if ($p === null || min($p['c'], $p['l'], $p['a'], $p['peso_real']) <= 0) {
            return ['logistica' => 'pendente'];
        }
        $cubado   = $p['c'] * $p['l'] * $p['a'] / $r['fator_cubagem'];
        $faturado = $cubado > $r['peso_cubado_minimo'] ? max($p['peso_real'], $cubado) : $p['peso_real'];
        $maior = max($p['c'], $p['l'], $p['a']); $soma = $p['c'] + $p['l'] + $p['a'];
        $me2  = $p['peso_real'] <= $r['me2']['peso'] && $soma <= $r['me2']['soma'] && $maior <= $r['me2']['maior'];
        $full = $me2 && $p['peso_real'] <= $r['full']['peso'] && $maior <= $r['full']['maior'];

        return ['logistica' => $me2 ? ($full ? 'me2_full' : 'me2') : 'me1', 'peso_cubado' => round($cubado, 2),
                'peso_faturado' => round($faturado, 2), 'maior_lado' => $maior, 'soma_lados' => $soma];
    }
}
```

### Cotação em lote pela conta do cliente
```php
// Fonte: MercadoLivreService::getMany + EditorRascunhoService::simular. dimensions = "AxLxC,peso_g" (altura, largura, comprimento).
$pedidos = [];
foreach ($pendentes as $v) {   // só ME2-elegíveis sem cache
    $pedidos[$v['id']] = ["/users/{$sellerId}/shipping_options/free", [
        'item_price' => $v['preco'], 'listing_type_id' => 'gold_special', 'mode' => 'me2', 'condition' => 'new',
        'logistic_type' => 'drop_off', 'verbose' => 'true',
        'dimensions' => sprintf('%dx%dx%d,%d', round($v['a']), round($v['l']), round($v['c']), round($v['peso_real'] * 1000)),
    ]];
}
$respostas = $this->ml->getMany($empresa, array_slice($pedidos, 0, 12, true));   // RuntimeException sem token → tabela ECF
$custo = data_get($respostas[$id] ?? null, 'coverage.all_country.list_cost');       // Throwable no mapa = falhou só esta
```

### Custo da oferta ligada em `pagina()`
```php
// Fonte: EstruturaPrecificacaoService::pagina() (hoje: $custos = $linhas->map(custo)).
$custos = $linhas->map(fn ($l) => $l->custo)->all();
foreach (ProdutoCustos::daEmpresa($empresa) as $ofertaId => $custoDaVariacao) {
    $custos[$ofertaId] = $custoDaVariacao;           // inclusive null: ligada ⇒ o custo é o do produto
}
// calcular(): se ligada, PrecificacaoEstrutura::custo($custoDaVariacao, ...) e origem = 'produto'.
```

## Estado da Arte

| Antes | Agora | Desde | Impacto |
|-------|-------|-------|---------|
| Cadastrar anúncio por anúncio | Cadastrar o produto uma vez; oferta simples nasce ligada | Reunião 05/10/2026 | Base da geração automática (fase seguinte) |
| Frete ML só por tabela de planilha | `shipping_options/free` com token do vendedor (reflete reputação) | Publicador (Fase 164/166) | Tabela vira reserva/estimativa |
| Tarifa por categoria "a confirmar" na planilha | `category_id` real → `listing_prices` (fase seguinte) | D-06 | Por isso a categoria é id real |
| Detecção `=== 'mysql'` | helper `emMysql()` (mysql ou mariadb) | WR-B06 | Obrigatório em migration com checagem de índice/FK |

**Obsoleto/evitar:** `MlFreteService` (wizard antigo; sem cache/sem classificação de erro); `CategoriaBuscaService` para este caso (caminho sem ids); `navigator.clipboard.readText()` como único caminho de colar.

## Aprendizados a registrar em `.planning/learnings/` (durante a execução)
- Medição da planilha de Produtos real (distribuição ME1/ME2/Full, "Variação" é ordinal, ambientes com grafias duplicadas, 1 produto sem medida) — contexto de produto que não se deduz do código (sem custos).
- `RESTRICT` vs `nullOnDelete` na FK de vínculo com exclusão em cascata de `Company` e por que a proteção é de serviço.
- `SpreadsheetGrid`: lacunas de colagem e como foram estendidas (ou por que se migrou para Glide).
- Fórmula exata do ME2/Full (usa peso real) e o `MATCH(peso − 0,0001)` da tabela de frete.

## Log de Suposições

| # | Afirmação | Seção | Risco se errada |
|---|-----------|-------|-----------------|
| A1 | `ADD COLUMN` nullable em `estrutura_ofertas` é rápido no MariaDB 10.4 de produção (instant add) — medir a contagem real (`.vps_cmd.sh`) antes do deploy | Schema | Lock de tabela mais longo que o esperado durante o deploy |
| A2 | O ML pode ignorar `dimensions` em `shipping_options/free` para a maioria das categorias ME2 (citação de `MlFreteService`/STACK.md, não reverificada) | Frete | Frete "ME2" exibido diferente do real; sempre rotular "estimado" |
| A3 | Quotar o frete pelo preço **de venda** (`minimo`, sem acréscimo) é o correto (PORTAL-02 diz que a venda é por ele); a alternativa é o preço de lista (`anunciado`) | Frete | Frete cotado em faixa errada; revisar com o usuário |
| A4 | Tabela de frete reserva (reputação verde, vigente desde 02/03/2026) ainda vale em outubro/2026 — corroborada por fontes públicas (valor de uma célula conferido), não pela página oficial do ML | Frete | Estimativa desatualizada; por isso `vigente_desde` visível e a API preferida |
| A5 | `Str::ascii` + minúsculas reproduz de forma suficiente a igualdade `utf8mb4_unicode_ci` para nomes de família/ambiente em PT-BR | Armadilha 2 | Falso "duplicado" ou 500 por unique em caso raro (ç/ñ/ligaduras); capturar 23000 cobre |
| A6 | A conta ML de cliente novo da Incubadora, em geral, **não** está conectada no momento do cadastro (por isso tabela como caminho comum) | Frete | Se a maioria estiver conectada, a cotação em lote passa a ser o caminho quente |
| A7 | Servir a página de Produtos paginada (≈ 100 produtos por página) é aceitável para o fluxo "70 de uma vez" | Grade | Se o usuário quiser tudo numa tela, a grade precisa de virtualização (Glide) |

## Perguntas Abertas

1. **Grade: `SpreadsheetGrid` estendido ou Glide?** (D-12 diz "reaproveitar o `SpreadsheetGrid`".) O que sabemos: lacunas verificadas (colar descarta excedente; select só `string[]`; sem picker; sem virtualização; persistência por array inteiro). O que falta: o ok do usuário para o caminho Glide, que já resolve colar/virtualizar/múltipla escolha. **Recomendação:** seguir D-12 com 3 extensões aditivas e props opcionais (§Grade); registrar a decisão no plano 01; só trocar para Glide se o usuário aprovar.
2. **Frete calculado alimenta o `frete_classico/premium` da Precificação da oferta ligada?** Não está no escopo do CONTEXT (D-10 só move o custo; "grade de margem/tarifa" é fase seguinte). **Recomendação:** **não** nesta fase (exibição apenas), para não alterar o preço efetivo que o Publicador herda; ao final do VERIFICATION, propor "usar este frete" como ação explícita futura.
3. **Gravar `logistica` (intenção do cliente) da oferta com a logística provável?** `estrutura_ofertas.logistica` é o dropdown do cliente (Full/Flex/Kit virtual…). **Recomendação:** não gravar; a provável é derivada e exibida.
4. **Coluna "Variação" do modelo:** aceitar ordinal (`1`/`2`/`única`) **e** `Eixo: valor`? **Recomendação:** sim (importa a planilha real sem reformatar e permite o eixo/valor de D-03). Conferir com o usuário se o eixo deve ser vocabulário fechado (Cor/Tamanho/Voltagem/Material/Outro, espelhando `VARIACAO_TIPOS` do Onboarding) ou texto livre.
5. **Preço de referência sem custo** (200, como a planilha) é aceitável? **Recomendação:** sim, rotulado.
6. **`/portal/estrutura` (entrada) passa a abrir Produtos?** Hoje abre a Lista SKUs e há teste dessa redirecionamento. Quem começa do zero entra por Produtos agora. **Recomendação:** apontar para Produtos **se** o usuário confirmar; senão manter (e o teste atual segue intacto).
7. **Paginação da tela de Produtos:** por produtos (≈ 100) com busca no servidor? **Recomendação:** sim (convenção do módulo); a importação não depende da página.
8. **Nome da oferta criada:** `produto.nome` ou `produto.nome — valor`? **Recomendação:** com o valor quando existir (diferencia as ofertas V1/V2 na Lista SKUs e no "Sincronizar do Portal" do Publicador).
9. **Excluir variação com anúncios:** seguir o precedente da Lista SKUs (anúncios voltam à espera, com confirmação mostrando a quantidade) em vez de bloquear. **Recomendação:** seguir o precedente; bloquear só se for componente.

## Disponibilidade de Ambiente

| Dependência | Necessária para | Disponível | Versão | Fallback |
|-------------|-----------------|-----------|--------|----------|
| PHP CLI (`C:\xampp\php\php.exe`) | testes, migrations | ✓ | 8.2.12 | — |
| MariaDB local | verificar migration (PR167-14) | ✓ | 10.4.32 (DB `ecf_admin`, compartilhado com outras sessões; `estrutura_ofertas` tem 13 linhas lá) | usar `--path`; **não** rodar `migrate` sem `--path` |
| Extensões PHP p/ PhpSpreadsheet (`zip`, `gd`, `xml*`, `mbstring`) | modelo/importação | ✓ (`intl` ausente — não é exigida) | — | — |
| Node/npm | `npm run build`, `test:js` | ✓ | Node v26.9.0, npm 11.19.1 | — |
| `node_modules` do worktree (Glide, Vite) | build | ✓ | glide 6.0.3 | — |
| Conta ML conectada (leitura) | verificação manual do frete por API | ? (não verificado nesta sessão) | — | testar com `Http::fake()`; real só leitura e com "pode" do usuário |
| Planilha real do cliente (fora do repo) | verificação manual local | ✓ (`C:/xampp/htdocs/ecf_admin/3Planejamento_Estrutural_ECF.xlsx`) | — | **nunca** copiar para o repo nem citar custos |
| `.vps_cmd.sh` (leitura de produção) | contar linhas de `estrutura_ofertas` antes do deploy | ? | — | pedir ao usuário |

**Sem bloqueio.** Nenhuma dependência faltando.

## Validation Architecture

> `workflow.nyquist_validation` = `true` em `.planning/config.json`.

### Test Framework
| Propriedade | Valor |
|-------------|-------|
| Framework PHP | PHPUnit 11.5.55 (`phpunit.xml`: SQLite `:memory:`, cache `array`, fila `sync`) |
| Framework JS | `node:test` (`npm run test:js`), gates estruturais com `lerSemComentarios` |
| Config | `phpunit.xml`; `tests/js/_fonte.js` |
| Comando rápido | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit tests/Feature/PortalCliente/Estrutura/Produtos` (≤ 30 s alvo) |
| Suíte da fase | `...phpunit tests/Feature/PortalCliente/Estrutura` + `tests/Unit/PortalEstrutura` + consumidores do Publicador (4 arquivos) + `DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` + `node --test tests/js/estrutura-produtos.test.js` |

### Mapa Requisito → Teste
| Req | Comportamento | Tipo | Comando / arquivo | Existe? |
|-----|---------------|------|-------------------|---------|
| PR167-01 | rota exige `portal.auth`; cliente e equipe entram; id de outra empresa = 404; `origem` cliente/interno no log; allowlist | feature | `Produtos/AcessoAosProdutosTest.php` + `DominioLiberaTodoModuloTest` (existente, deve passar) | ❌ Wave 0 (o 2º ✅) |
| PR167-02 | produto→variações→volumes; código único por empresa (case/acento/espaço); peso total derivado; nova variação copia a 1ª | feature | `Produtos/CadastroDeProdutoTest.php` | ❌ Wave 0 |
| PR167-03 | listas por empresa, ambiente múltiplo, normalização, recusa de `/ , \|`, "em uso" bloqueia, isolamento entre empresas | feature | `Produtos/ListasDaEmpresaTest.php` | ❌ Wave 0 |
| PR167-04 | sugestão com caminho e ids; grava `category_id`/nome/caminho; só folha; ML fora do ar não bloqueia; app token (nunca o do cliente) | feature (`Http::fake`) | `Produtos/CategoriaDoProdutoTest.php` | ❌ Wave 0 |
| PR167-05 | uma oferta simples por variação, `variacao_id` unique, sku/nome sincronizados, ofertas antigas intactas (contagem antes=depois), SKU repetido avisa, `varrerEspera` absorve; migration up/down idempotente | feature | `Produtos/OfertaLigadaAoProdutoTest.php` + `Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (ampliado) | ❌ Wave 0 |
| PR167-06 | oferta ligada: custo = o da variação (origem `produto`), `salvarOferta` recusa custo, combo soma custos do produto, oferta sem produto igual a hoje; Publicador herda | feature | `Produtos/CustoDoProdutoNaPrecificacaoTest.php` + `PrecificacaoEstruturaTest`/`DadosEfetivosTest`/`CustoDoAnuncioTest`/`MigracaoAnunciarAntigoTest`/`SincronizaPortalTest` (existentes) | ❌ Wave 0 / ✅ |
| PR167-07 | grade: usa `SpreadsheetGrid` estendido; `growOnPaste`; `picker`; diff por linha; colunas calculadas só leem campos do servidor; sem lógica de logística no JS | js gate + feature (endpoint `linhas`) | `tests/js/estrutura-produtos.test.js`; `Produtos/GravarLinhasTest.php` | ❌ Wave 0 |
| PR167-08 | modelo baixa com as 11 colunas; prévia classifica novos/atualizados/sem mudança/erros; confirmar refaz o plano; nada apagado; aceita a aba real (fixture **sintética** equivalente); limites de upload; sem `getCalculatedValue` | feature | `Produtos/ModeloEImportacaoTest.php` | ❌ Wave 0 |
| PR167-09 | pacote empilhado, cubado, faturado, ME2/Full/ME1/pendente (gabarito) | unit puro | `tests/Unit/PortalEstrutura/LogisticaProdutoTest.php` | ❌ Wave 0 |
| PR167-10 | tabela ECF (borda do `−0,0001`, faixa de preço); API via `Http::fake` (`list_cost`, dimensions `AxLxC,g`); sem token → estimativa rotulada; cache evita 2ª chamada; ≤ 12 por requisição; ponto fixo ≤ 2 re-cotações; sem literal de frete grátis no código | unit + feature | `tests/Unit/PortalEstrutura/TabelaFreteEcfTest.php`; `Produtos/FreteDoProdutoTest.php` | ❌ Wave 0 |
| PR167-11 | pendências por linha | unit | `tests/Unit/PortalEstrutura/PendenciasDoProdutoTest.php` | ❌ Wave 0 |
| PR167-12 | excluir variação: bloqueia se componente; anúncios voltam à espera; `pub_produto` solto; oferta ligada não excluível/editável (sku/nome/fase) pela Lista SKUs | feature | `Produtos/CicloDeVidaDoProdutoTest.php` (+ `OfertaExcluidaNoPortalTest` existente) | ❌ Wave 0 / ✅ |
| PR167-13 | combo/kit/combit sem logística/frete | feature | caso em `CadastroDeProdutoTest` | ❌ Wave 0 |
| PR167-14 | baseline registrado; `SHOW CREATE TABLE`/`SHOW INDEX` no MariaDB; rollback + re-up; contagem de ofertas igual | manual-only (MariaDB real) | roteiro no `167-VERIFICATION.md` | manual — o SQLite não pega 1059/1830/1553/collation |

### Frequência de amostragem
- **Por commit de tarefa:** o arquivo de teste da tarefa (`phpunit <arquivo>`) + `node --test` do arquivo JS tocado.
- **Por merge de onda:** diretório `Produtos` + `tests/Feature/PortalCliente/Estrutura` + os 4 consumidores do Publicador + dois testes de domínio do portal.
- **Gate da fase:** todos acima verdes **mais** a verificação manual no MariaDB (PR167-14) e `npm run build` com a página no manifest, antes de `/gsd:verify-work`.

### Lacunas da Onda 0
- [ ] `tests/Feature/PortalCliente/Estrutura/Produtos/*` (10 arquivos acima) e `tests/Unit/PortalEstrutura/*` (4)
- [ ] `tests/js/estrutura-produtos.test.js` (gates: `SpreadsheetGrid` importado, `growOnPaste`, sem `cubagem`/`ME2` no JS, rota `modelo` como `<a href>`)
- [ ] Atualizar `PortalSemAnunciarTest:110-124` e `AcessoAoModuloEstruturaTest:60` (6 submódulos, `produtos` primeiro)
- [ ] Ampliar `MigracoesDaFaseDetectamMariaDbTest::MIGRACOES`
- [ ] Fixture **sintética** do `.xlsx` gerada no próprio teste via PhpSpreadsheet (nunca o arquivo real)
- [ ] `167-BASELINE-TESTES.md` com os números desta pesquisa
- [ ] Nenhum framework a instalar

## Domínio de Segurança

### Categorias ASVS aplicáveis
| Categoria | Aplica | Controle padrão |
|-----------|--------|-----------------|
| V2 Autenticação | sim (herdado) | middleware `portal.auth` (cliente por e-mail+código; equipe por ticket) — nada novo |
| V3 Sessão | sim (herdado) | guard `portal`, `EnsurePortalAutenticado` relê usuário e vínculo a cada request |
| V4 Controle de acesso | **sim** | empresa **só** do `PortalContexto`; toda busca por id com `where('company_id', $empresa->id)->findOrFail()` (404 igual para inexistente/outra empresa); FKs de lista (`familia_id`, `ambiente_ids`) revalidadas contra a empresa (id de outra empresa = 422 genérico, como `EstruturaOfertaService::composicao`) |
| V5 Validação de entrada | **sim** | `$request->validate` + normalizadores no serviço (tamanhos, `Rule::in`, números ≥ 0 e tetos, `category_id` `^MLB\d+$`); nada de `$request->all()` direto no `create` (`$fillable` explícito) |
| V6 Criptografia | não | token ML já é `encrypted` no model; nenhum segredo novo; nunca logar token (padrão `ClienteMlPublicador`) |
| V12 Arquivos | **sim** | upload `.xlsx`: extensão+estrutura zip, ≤ 2 MB, ≤ 1.000 linhas, `setReadDataOnly`, sem fórmula calculada, arquivo temporário descartado; sem execução de macro |
| V13 API | **sim** | `throttle` por rota com prefixo próprio; endpoints que chamam o ML (`categorias`, `fretes`) com limite baixo + cache para o cliente não usar o servidor como proxy do ML |

### Ameaças conhecidas para esta stack
| Padrão | STRIDE | Mitigação padrão |
|--------|--------|------------------|
| IDOR (id de variação/produto/lista de outra empresa) | Information disclosure / Tampering | escopo por `company_id` em toda consulta; 404 uniforme; testes de isolamento entre duas empresas |
| Mass assignment | Tampering | `$fillable` explícito; `company_id` nunca do request |
| Injeção de fórmula/CSV no `.xlsx` | Tampering | células de modelo com tipo explícito texto; ao exportar dado do cliente, prefixar `'` em valor iniciado por `= + - @` |
| Planilha maliciosa (zip bomb, XXE, arquivo gigante) | DoS | limites, PhpSpreadsheet 2.4.5 com leitor padrão (entidades externas desabilitadas), timeout de requisição |
| Abuso do proxy ao ML (spam de cotação/sugestão) | DoS / custo | throttle, cache, teto por requisição |
| Vazamento do token do cliente | Information disclosure | frete só no servidor; resposta ao navegador sem token; `MlToken::$hidden` |
| XSS por nome de produto/família | Tampering | React escapa por padrão; nenhum `dangerouslySetInnerHTML`; validar tamanho |
| Escrita na conta ML do cliente | Tampering | só `GET`; teste que o serviço de frete não faz POST/PUT; regra "conta de cliente só leitura" |
| Repúdio (quem alterou) | Repudiation | `RegistroEstrutura` com `origem` em **toda** escrita (incluindo listas e exclusões) |

## Fontes

### Primárias (confiança ALTA — lidas nesta sessão)
- Código do worktree `C:/tmp/ecf-publicador-spec-261001`: migrations `2026_09_23_160000`, `2026_09_29_120000`, `2026_10_02_100000/100100`; models `Estrutura*`; `app/Services/Portal/Estrutura/*` (OfertaService, PrecificacaoEstrutura, PrecificacaoService, ColagemAnunciosService, Conjunto, VisaoService, RegistroEstrutura); `PortalEstruturaController`; `routes/web.php` (grupo `portal.auth`); `RestringeDominioDoPortal`; `ModulosPortal`; `PortalContexto`; `PortalEquipeService`; `AtorDoPortal`; `PublicadorSincronizaPortalService`; `DadosEfetivosService`; `SoltarProdutoDaOfertaService`; `EditorRascunhoService` (simular/freteGratis/dimensoes); `ClienteMlPublicador`; `MercadoLivreService::getMany/get`; `MlFreteService`; `CategoriaSugestaoService`, `CategoriaBuscaService`, `MlCatalogoMetaService`; `SpreadsheetGrid.jsx`, `GradeAnuncioGlide.jsx`, `ImplementacaoPublica.jsx`; `EstruturaPrecificacao.jsx`; `PolosController::streamXlsx`; `config/publicador.php`.
- ADRs `PORTAL-01`, `PORTAL-02`; `164-CONTEXT.md` (D27); learnings `portal-do-cliente.md` (§12, §18, §19, §27–30), `desempenho-bonificacao.md` §6, `publicador-ml.md` §10, `autoloader-compartilhado-entre-worktrees.md`.
- Planilha `3Planejamento_Estrutural_ECF.xlsx` (leitura local; abas Produtos, Parâmetros, Planejamento — fórmulas V:AA e AC —, Frete ML Verde) e transcrição `REUNIAO_INCUBADORA.docx` (contexto: a operadora Vitória fará o cadastro).
- Execuções reais desta sessão: PHPUnit (baseline 83/22/7 verdes), `SHOW CREATE TABLE estrutura_ofertas` no MariaDB 10.4.32 local, `ReflectionClass` do autoloader.

### Secundárias (confiança MÉDIA)
- [Como funciona o frete do Mercado Livre? (Bling)](https://blog.bling.com.br/como-funciona-o-frete-do-mercado-livre/) e [Custo dos Envios Mercado Livre 2026 (Vendedor Lucrativo)](https://vendedorlucrativo.com.br/calculadora-frete-mercado-livre) — "29 faixas de peso × 8 de preço × 3 reputações", valor da célula 79–99,99/≤0,3 kg verde conferido com a planilha; vigência de 02/03/2026.

### Terciárias (confiança BAIXA — marcadas para validação)
- Comportamento real de `dimensions` em `shipping_options/free` por categoria (A2) — precisa de uma chamada real de leitura numa conta conectada.

## Metadados

**Confiança:**
- Stack padrão: ALTA — nada novo; versões lidas do lockfile/instalação.
- Arquitetura e schema: ALTA — baseada em precedentes do próprio repositório (164-01) e em leitura completa dos leitores de custo.
- Grade: MÉDIA-ALTA — lacunas verificadas no código; a escolha final depende da decisão do usuário (Pergunta 1).
- Frete por API: MÉDIA — endpoint e parâmetros iguais aos do Publicador em produção; precisão por dimensões incerta (A2).
- Armadilhas: ALTA para as do repositório/MariaDB; MÉDIA para a igualdade de collation (A5).

**Data da pesquisa:** 2026-10-05
**Válida até:** 2026-11-04 (30 dias; a tabela de frete e o comportamento do ML mudam mais rápido que o código — rever `vigente_desde` antes de implementar)
