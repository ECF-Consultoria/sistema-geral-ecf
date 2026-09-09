# Phase 139: Checklist administrativo + trava de finalização (v23.0) - Context

**Gathered:** 2026-09-09
**Status:** Ready for planning

<domain>
## Phase Boundary

Dentro do cadastro da empresa existe um checklist com os itens obrigatórios do Administrativo,
agrupado nos dois módulos da Área Comercial (**Contrato** e **Entrada**). O que o sistema já sabe
observar se marca sozinho; o que é ato humano é marcado à mão com autoria; e o botão
**FINALIZAR ENTRADA ADMINISTRATIVA** só habilita quando todos os itens obrigatórios estão
concluídos e o contrato está assinado, movendo a empresa para `Aguardando Distribuição` e para o
módulo do marketplace do contrato — **no mesmo cadastro**, sem criar registro novo.

**Fora do escopo:** o motor da mensagem de boas-vindas (Fase 140), a distribuição pela Coordenação
(Fase 141), o onboarding (142) e o histórico/SLA (143). Nada aqui reconstrói assinatura — o grupo
Contrato **lê** o envelope Clicksign entregue pela v22.0 (D5 da milestone).

</domain>

<decisions>
## Implementation Decisions

### Contagem e obrigatoriedade dos itens

- **D-01:** **A lista que vale é a do §5 — 9 itens**, não os 12 do §3. Medida a diferença exata: o
  §5 é o §3 menos `acompanhar a assinatura`, `gerar mensagem de boas-vindas` e
  `inserir todos os links necessários`. Os três excluídos são **passos intermediários** que
  desembocam em item já controlado (`contrato assinado`, `boas-vindas enviada`) — marcá-los como
  concluídos não teria significado próprio. O §5 é a lista dos **estados terminais**, e é a lista
  que o próprio PDF diz que trava o FINALIZAR (ADMIN-05).
  → Fecha a decisão que o `ROADMAP.md` marcou como **em aberto** no bloco da Fase 139.

- **D-02:** **Não existe estado "não aplicável".** Todo item exibido é obrigatório. O usuário
  rejeitou explicitamente o `nao_aplicavel` do motor de Onboarding.
  ⚠️ **Risco declarado e aceito:** empresa que legitimamente não precise de um item não terá saída
  senão marcar "concluído" mentindo — o que apaga o rastro. A D-07 remove o único caso concreto
  conhecido hoje (isenção de contrato) por **montagem condicional**, não por marcação. Se aparecer
  um segundo caso, a decisão precisa ser reaberta, não contornada.

- **D-03:** **Os 9 itens e como cada um fecha** (a coluna "fecha por" é decisão, não sugestão):

  **Grupo Contrato — 3 itens, só existe quando `Servico::exigeContrato()` é `true` (ver D-07):**

  | # | Item | Fecha por | Fonte |
  |---|---|---|---|
  | 1 | Contrato revisado | **manual**, com autoria | nenhuma — ver D-06 |
  | 2 | Contrato enviado | auto | `contrato_assinaturas.enviado_em !== null` |
  | 3 | Contrato assinado | auto | `contrato_assinaturas.assinado_em` / `status === assinado` |

  **Grupo Entrada — 6 itens, sempre:**

  | # | Item | Fecha por | Fonte |
  |---|---|---|---|
  | 4 | Grupo de WhatsApp criado | **manual**, com autoria | nenhuma |
  | 5 | E-mail colaborador criado | **manual**, com autoria | nenhuma |
  | 6 | Link Adman entregue | **manual**, com autoria | nenhuma — link fixo, ver D-04 |
  | 7 | Grant da consultoria (= OAuth do Mercado Livre) | auto | cliente **conectou** — ver D-05 |
  | 8 | Conexão com o sistema ECF gerada | auto | `onboarding_links` da empresa existe |
  | 9 | Boas-vindas enviada | **manual**, com autoria | nenhuma |

  Total: **9 itens** com contrato, **6** para serviço isento.

### Correção de duas premissas erradas do §3/§5 (medidas contra o código e confirmadas pelo usuário)

- **D-04:** **"Gerar link de conexão com a ADMA" está errado em dois níveis.** Primeiro, o nome é
  **Adman**, não ADMA (correção do usuário, 2026-09-09). Segundo, e mais importante: **não é um
  link gerado nem por empresa** — é um **link fixo de cadastro, idêntico para todas**:
  `https://app.ad-man.io/register?ref=588D0DD78C4F`.
  Verificado: essa URL **não existe em lugar nenhum do repositório** hoje, e o `AdmanService` é
  **somente leitura** (puxa métricas; não há ação "conectar ao Adman" per-company).
  **Onde mora:** `config/services.php`, como
  `env('ADMAN_REGISTER_URL', 'https://app.ad-man.io/register?ref=588D0DD78C4F')`. Trocar o código
  `ref` em produção passa a ser mudança de `.env` na VPS — **sem deploy e sem mudança de código**,
  o mesmo padrão que a Fase 138 usou para as properties do HubSpot.
  **Consequência para o item 6:** não há estado observável, logo o item é **manual**.

- **D-05:** **"Gerar Grant da consultoria" é o OAuth do Mercado Livre**, não a planilha de grants
  por SFTP (confirmação literal do usuário: *"seria o OAuth do mercado livre para o cliente
  conectar, nao tem nada com SFTP"*).
  Isso **derruba** a leitura de que o item usaria `company_grants` / `grants:sync-sftp` — esse
  comando é **global** (baixa um XLSX inteiro e faz upsert para todas as empresas, com lockfile em
  `storage/app/grants_sync_status.json`), e nunca poderia ser acionado como "gerar para esta
  empresa".
  **O que o item usa:** `POST /companies/{company}/ml/initiate` → `MercadoLivreService::buildAuthUrl()`
  (`companies.ml_link_url` + `ml_link_generated_at`, TTL de 7 dias).
  **Quando fecha:** somente quando o **cliente realmente conectou** — o callback OAuth
  bem-sucedido — e **não** quando o link foi gerado. Link gerado ≠ link usado: ele expira em 7 dias
  e o checklist estaria mentindo o tempo todo.
  **Detecção:** `MercadoLivreOAuthController` **zera `ml_link_url`** no callback bem-sucedido, então
  "gerado mas não conectado" e "conectado" são distinguíveis. O planejador deve confirmar qual
  sinal é o mais estável (o zeramento é efeito colateral, não um carimbo de sucesso — se houver
  um campo de token/conexão mais explícito, prefira-o).
  ⚠️ **Armadilha conhecida e já registrada no projeto:** clique interno por usuário logado na
  própria conta ML gera **autorização FALSA** (`project_polos_oauth_link_boas_vindas_260827`). Um
  botão "gerar link" dentro do checklist multiplica a chance de alguém clicar para testar — o
  desenho precisa levar isso em conta (copiar link ≠ abrir link).

- **D-06:** **"Contrato revisado" é marcação MANUAL, com autoria.** Revisar um contrato é ato
  humano e não deixa rastro digital; os 7 estados do envelope (`rascunho`, `aguardando_assinaturas`,
  `assinado`, `recusado`, `expirado`, `cancelado`, `erro`) não contêm "revisado", e não existe
  nenhum accessor ou scope derivado hoje em `ContratoAssinatura`.
  As alternativas foram apresentadas e recusadas: mapear para
  `ContratoDadosMinimosService::estaPronta()` mediria **completude de cadastro**, não revisão;
  `status !== rascunho` seria na prática a mesma condição de "enviado", tornando um dos dois itens
  decorativo.
  ⚠️ **Isto é exceção explícita ao ADMIN-02**, que diz que o grupo Contrato reflete o envelope
  "sem marcação manual paralela". A exceção **deve ser registrada em
  `.planning/REQUIREMENTS-v23.md`** junto ao ADMIN-02 — não deixada implícita no código.

### Isenção de contrato

- **D-07:** **O grupo Contrato não existe para serviço com `exige_contrato = 0`.** O checklist é
  montado conforme o serviço: empresa isenta nasce com **6 itens**, empresa com contrato nasce com
  **9**, e o denominador do progresso acompanha. Não é `nao_aplicavel` marcado à mão (proibido pela
  D-02) — é o item **nem existir** para aquela empresa.
  **Medido em 2026-09-09, não assumido:** `Polos` (`servicos.id=2`) é o **único** serviço com
  `exige_contrato = 0`, e tem **5 vínculos `contratos_servico` ativos** hoje. Sem esta decisão,
  essas 5 empresas — e toda Polos futura — teriam 3 itens pendentes para sempre e **nunca
  poderiam finalizar**.
  Reusa `Servico::exigeContrato()`, a mesma isenção (D9) que `ContratoAdminController::index()`,
  `ComercialController` e `ComercialEntradaController` já respeitam — nada novo a inventar.

### Tela e permissão

- **D-08:** **Uma ficha única por empresa, com as duas seções na mesma tela**, aberta pelas duas
  listagens. Reusa `admin.contratos.show` → `resources/js/Pages/Admin/ContratoDetalhe.jsx`
  (1127 linhas), que já carrega `contratos_servico`, `ContratoAssinatura`, signatários,
  `podeGerarContrato` e `motivoBloqueio` — metade do que o checklist precisa já está no payload.
  A listagem **Entrada** ganha uma ação "Abrir" apontando para a **mesma** ficha (hoje
  `Comercial/Entrada.jsx` é listagem pura, 305 linhas, sem rota de detalhe).
  Motivo de fundo: o FINALIZAR precisa ficar onde dá para ver tudo que ele exige — separar as
  seções em duas telas obrigaria quem vai finalizar a conferir a outra para saber se o contrato
  assinou.

- **D-09:** **As permissões de módulo da Fase 138 bastam** — `comercial.entrada` para os itens de
  Entrada e para o FINALIZAR, `admin.contratos` para os itens de Contrato. **Nenhuma chave nova.**
  As duas já são liberáveis por setor sem deploy (D-15 da Fase 138).

### Claude's Discretion

Decisões tomadas por delegação implícita, ancoradas em medição do código. O usuário viu o resumo
das três primeiras e não corrigiu; as demais são consequência direta das decisões acima.

- **D-10:** **Copiar o *shape* do motor de Onboarding, não hospedar nele.** A tabela nova é
  ancorada em **`company_id`**, não em `contrato_servico_id`.
  Motivo medido: `Onboarding` exige um `ContratoServico` (`criarParaContrato()`), a tabela tem
  `unique(contrato_servico_id)`, e `DefinicaoOnboarding::paraServico()` só devolve passos quando
  `eGestao($servico)` — hospedar o checklist administrativo ali exigiria inventar um serviço
  fantasma e furar a unique.
  O que **copiar** de `OnboardingEngineService` / `onboarding_passos`: `chave` + `natureza` +
  `dono` + `auto_fonte`; resolver com resultado de **3 estados**
  (`concluido | nao_coletado | indeterminado`, `OnboardingResolverResultado`), nunca booleano;
  a recusa de `concluirManualmente()` em passo que tem `auto_fonte` (`DomainException` salvo
  `$forcar`); e `reabrirPasso()` com activity log.
  ⚠️ Note que `nao_aplicavel` do Onboarding **não** é copiado — a D-02 o proíbe.

- **D-11:** **Autoria: copiar `onboarding_passos.feito_por` / `feito_em` / `auto_em` verbatim.**
  É o padrão dominante do projeto (`mlb_revisoes`, `mlb_publicacoes`, `sugadores`,
  `companies.empresa_nova_visto_por`). Três disciplinas que vêm junto e não são opcionais:
  (1) `belongsTo(User::class, 'feito_por')->withTrashed()` — usuário desligado não pode apagar a
  autoria; (2) desmarcar limpa **os dois** campos juntos (`RevisaoService:304-305`,
  `OnboardingEngineService::reabrirPasso`); (3) `spatie/activitylog` é trilha **secundária** —
  a leitura da tela vem sempre das colunas, nunca do log.

- **D-12:** **Destino do FINALIZAR: `Company::primaryMarketplace()`**, que já lê `is_primary` na
  pivot `company_marketplaces` e cai para a coluna flat `companies.marketplace` quando a pivot está
  vazia — e essa coluna é `NOT NULL` com `default('meli')`, então "empresa sem marketplace" na
  prática não existe.
  ⚠️ **`is_primary` é guard de Model, não constraint de banco** — nada impede duas linhas
  `is_primary = true`. O planejador deve decidir o comportamento nesse caso em vez de assumir que
  não acontece.

- **D-13:** **Os itens sem estado observável são manuais**, não inventados: `Grupo de WhatsApp
  criado`, `E-mail colaborador criado`, `Link Adman entregue` e `Boas-vindas enviada`. Quatro dos
  nove. Fabricar um sinal automático para qualquer um deles seria marcar "concluído" sem evidência
  — o modo de falha que a D-05 e a D-06 existem para evitar.

- **D-14:** **Item 8 (conexão com o sistema ECF) fecha por existência, não por clique.**
  `OnboardingLinkService::paraEmpresa(Company $company)` é `firstOrCreate` por `company_id`
  (unique), sem rede e sem depender de o onboarding existir — é idempotente, então "o link existe"
  é sinal suficiente e o botão pode ser acionado quantas vezes for.


### Decisões abertas pela pesquisa e fechadas pelo usuário (2026-09-09)

As quatro decisões abaixo nasceram de conflitos que a `139-RESEARCH.md` mediu contra o código e
**não** resolveu sozinha. Foram apresentadas ao usuário com trade-off explícito e fechadas por ele.

- **D-15:** **O progresso do checklist dirige as etapas 2, 3 e 4 — não o clique final.**
  Medido: `EtapaTransicaoService::TRANSICOES_PERMITIDAS` só permite chegar em
  `aguardando_distribuicao` (5) vindo de `administrativo_concluido` (4), mas **nenhum chamador de
  produção escreve 2, 3 ou 4 hoje** — só a etapa 1 (`HubspotWebhookController:500`,
  `ComercialController:683`). Sem esta decisão o FINALIZAR nasce morto.
  **A régua:** 1º item concluído → `administrativo_andamento` (2); envelope Clicksign enviado →
  `aguardando_assinatura` (3); todos os itens obrigatórios concluídos **e** contrato assinado →
  `administrativo_concluido` (4); clique no FINALIZAR → `aguardando_distribuicao` (5).
  ⚠️ **O salto 2→4 já é previsto** na tabela da Fase 137 para empresa isenta de contrato (D-07):
  sem envelope não existe etapa 3.
  Toda transição passa por `EtapaTransicaoService::transicionar()` — **nunca** `update()` direto.
  Motivo: é a única opção que preserva o propósito declarado da máquina de estados
  (`EtapaTransicaoService.php:28-32`) e dá à Fase 143 (HIST-03) o **instante real** de cada etapa.
  Custo aceito: amplia o escopo além da letra de ADMIN-01..06.

- **D-16:** **O item 3 "Contrato assinado" fecha por assinatura OU por liberação registrada.**
  Fonte: `contrato_assinaturas.assinado_em` / `status === assinado` **OU**
  `ContratoLiberacao` existente para aquele serviço.
  Medido: existem **3 vias** de liberação (`ContratoLiberacao::VIA_TODAS` — `webhook`, `manual`,
  `reconciliacao`). Nas vias `webhook` e `reconciliacao` o `status`/`assinado_em` é gravado no mesmo
  `save()`, então ler as colunas equivale a ler a liberação. Mas a via **`manual`**
  (`ContratoAdminController::liberarManual()` → `EmpresaOperacionalRouter::liberarEmpresa()`)
  **nunca toca** essas colunas — e existe exatamente para "Clicksign fora do ar, cliente assinou
  fora do sistema". Sem esta decisão, empresa liberada por essa via ficaria **permanentemente
  impedida** de finalizar a entrada administrativa, sem saída pela tela.
  Continua sendo **leitura pura** — não fere a D5 da milestone.
  ⚠️ **Isto altera a coluna "Fecha por" do item 3 na tabela da D-03.** A D-03 continua valendo em
  tudo o mais; só o item 3 ganha a segunda fonte.

- **D-17:** **Mesma rota para a ficha, com permissão em OR — nenhuma rota nova, nenhuma chave nova.**
  Medido: `admin.contratos.show` está sob `permission:admin.contratos` (`routes/web.php:1443`), então
  quem tem só `comercial.entrada` tomaria **403** ao clicar "Abrir" na listagem Entrada — o que
  quebraria a D-08. O middleware `EnsurePermission` **já aceita várias chaves em OR nativamente**
  (`app/Http/Middleware/EnsurePermission.php:24-38`, docblock literal:
  *"Permite acesso à rota se o user tem QUALQUER uma das permission keys"*).
  **A mudança:** `permission:admin.contratos,comercial.entrada` na rota existente.
  A **seção Contrato dentro da ficha** é renderizada apenas para quem tem `admin.contratos` —
  a permissão de rota abre a ficha; a permissão de módulo decide o que aparece nela.
  A D-09 fica preservada na letra: nenhuma chave de permissão nova é criada.

- **D-18:** **Com 2+ envelopes ativos, manda o mais atrasado.**
  Medido: `ContratoClicksignService::iniciarParaEmpresa()` itera por grupo de serviço (linhas
  138-223) e pode criar **um `ContratoAssinatura` por grupo** — envelopes simultâneos em estados
  diferentes. A D-03 descreve o grupo Contrato como 3 itens únicos por empresa e não previa isso.
  **A régua:** os itens 2 e 3 só fecham quando **TODOS** os envelopes ativos da empresa atingiram
  aquele estado. Um assinado + um pendente = item 3 **pendente**.
  Precedente do próprio projeto, não invenção: o `EtapaTransicaoService` já declara por escrito a
  mesma regra para o onboarding da Fase 142 — *"Manda o onboarding mais atrasado — a empresa só
  chega em `onboarding_concluido` quando TODOS os onboardings considerados concluírem"*
  (`EtapaTransicaoService.php:36-39`).

### Registro obrigatório em REQUIREMENTS-v23.md

- **D-19:** Duas exceções ao **ADMIN-02** ("o grupo Contrato reflete o envelope, sem marcação manual
  paralela") precisam ficar **escritas** em `.planning/REQUIREMENTS-v23.md`, junto ao próprio
  ADMIN-02 — não implícitas no código:
  (1) **D-06** — "Contrato revisado" é marcação **manual** com autoria, porque revisar é ato humano
  e nenhum dos 7 estados do envelope o representa;
  (2) **D-16** — "Contrato assinado" também fecha por `ContratoLiberacao`, porque a via de liberação
  manual não escreve `contrato_assinaturas`.
  ⚠️ Editar `REQUIREMENTS-v23.md` **à mão**: os verbos `gsd-sdk query requirements.*` escrevem no
  `REQUIREMENTS.md` sem sufixo, que é da v17 e está stale.
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requisitos e roadmap

- `.planning/REQUIREMENTS-v23.md` — **a fonte dos requisitos ADMIN-01..06.**
  ⚠️ `.planning/REQUIREMENTS.md` (sem sufixo) é da **v17** e está stale — os verbos
  `gsd-sdk query requirements.*` escrevem no arquivo errado. Editar o v23 **à mão**.
- `.planning/ROADMAP.md`, bloco `### Phase 139` — Goal, 5 Success Criteria, o lembrete D5 e o aviso
  de contagem em aberto (fechado pela D-01 acima).

### O enunciado original e a reorganização

- `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` §3 (as 12 atividades), §5 (os 9
  controles + a regra do FINALIZAR), §6 (a mudança para o marketplace).
  ⚠️ **Duas premissas do §3 estão erradas** — ver D-04 e D-05. Não implementar o §3 na letra.
- `.planning/seeds/138-140-admin-no-comercial-dois-modulos-260902.md` — a fusão Estrutura+Comunicação
  em **Entrada**, e o mapa arquivo-a-arquivo do que a reorganização encosta.

### Decisões travadas nas fases anteriores

- `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-CONTEXT.md` — D-02/D-03 (os dois
  módulos), D-05 (separação por processo pendente, não por etapa), D-11 (duas pendências em colunas
  separadas, nunca somadas), D-13 (as duas portas nascem na etapa 1).
- `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-VERIFICATION.md` — o que a Fase 138
  de fato entregou, verificado contra o código.
- `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-CONTA-SISTEMA-HUBSPOT.md` — as 3
  pendências de VPS herdadas da 138 (conta de sistema, property de owner, escopo
  `crm.objects.owners.read`). **Não são trabalho desta fase**, mas quem fizer o deploy precisa
  delas.

### Aprendizados obrigatórios do projeto

- `.planning/learnings/painel-polos-status-e-meta.md` §2 — as ~10 falhas pré-existentes de Polos
  que **não** são regressão, e a página React de re-export puro que some do manifest do Vite.
- `.planning/learnings/desempenho-bonificacao.md` §6 — as armadilhas de MariaDB que o SQLite dos
  testes não pega. §10 — `git commit -- <caminhos>`, nunca `git add -A`.
- `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-VALIDATION.md` — **os comandos de
  teste que de fato terminam neste ambiente**. `php artisan test` e `--testsuite=Feature` travam
  numa cascata de timeout de rede (~300s) e nunca imprimem resumo.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`app/Services/Onboarding/OnboardingEngineService.php` + `onboarding_passos`** — o molde do
  checklist (resolver de 3 estados, autoria, progresso com denominador ajustável). **Molde, não
  hospedagem** — ver D-10.
- **`app/Services/Onboarding/Resolvers/`** (13 resolvers, `OnboardingResolverFactory`) — o padrão
  de resolver auto-marcador. `AdmanGrantResolver` é o analog mais próximo de "auto-marcar por
  estado externo".
- **`resources/js/Pages/Admin/ContratoDetalhe.jsx`** (`admin.contratos.show`, 1127 linhas) — a
  ficha por empresa que hospeda o checklist (D-08). Já traz assinatura + signatários no payload.
- **`app/Services/Onboarding/OnboardingLinkService::paraEmpresa()`** — item 8, idempotente (D-14).
- **`MercadoLivreService::buildAuthUrl()` + `POST /companies/{company}/ml/initiate`** — item 7 (D-05).
- **`Servico::exigeContrato()`** — a isenção que monta o grupo Contrato condicionalmente (D-07).
- **`Company::primaryMarketplace()`** — o destino do FINALIZAR (D-12).
- **`app/Services/FluxoEntrada/EtapaTransicaoService`** (Fase 137) — **a única porta** para mudar
  `companies.etapa`. O FINALIZAR transiciona por ele, nunca por `update()` direto. A Fase 138
  provou que os dois chamadores de produção passam por ele; esta fase acrescenta o terceiro.

### Established Patterns

- **Autoria `<verbo>_por` / `<verbo>_em`** — ver D-11.
- **Estados do envelope Clicksign** vivem em `contrato_assinaturas` e são transicionados **no job**
  (`ProcessarEventoClicksignJob`), não no controller. Ler estado, nunca escrever (D5 da milestone).
- **Permissões liberáveis por setor sem deploy** — `app/Support/Permissions.php` + `setor_permissoes`.
- **Comentários em pt-BR**; nomes de classes, métodos, rotas e colunas em inglês.

### Integration Points

- `ComercialEntradaController` (Fase 138) — ganha a ação "Abrir" para a ficha (D-08).
- `ContratoAdminController::show()` — passa a montar e servir o checklist.
- `EtapaTransicaoService` — o FINALIZAR é o novo chamador (etapa 4 → 5).
- `config/services.php` — ganha `ADMAN_REGISTER_URL` (D-04). **`.env.example` também.**

</code_context>

<specifics>
## Specific Ideas

- O link do Adman, na letra, dado pelo usuário: `https://app.ad-man.io/register?ref=588D0DD78C4F`.
  É de cadastro/indicação e **igual para todas as empresas**.
- A correção de nomenclatura veio do usuário e vale para toda a fase: **Adman**, nunca "ADMA".

</specifics>

<deferred>
## Deferred Ideas

- **Reversibilidade do FINALIZAR** — o que acontece se alguém finalizar por engano, e se um item
  concluído pode ser desmarcado depois. Levantado como candidato e **não discutido** nesta sessão.
  O `reabrirPasso()` do Onboarding é o precedente óbvio se isso voltar à mesa.
- **Cliente desconecta o OAuth depois de finalizado** — o item 7 voltaria a ficar aberto numa
  empresa que já saiu da etapa administrativa. Não discutido.
- **Integração per-company de Grant com o Mercado Livre** — foi cogitada enquanto se acreditava que
  o item era sobre a planilha SFTP. A D-05 tornou a questão sem objeto: o item é OAuth, e OAuth já
  existe per-company. Registrado para que ninguém reabra por engano.

</deferred>

---

*Phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Context gathered: 2026-09-09*
