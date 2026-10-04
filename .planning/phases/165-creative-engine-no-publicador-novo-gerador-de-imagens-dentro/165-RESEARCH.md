# Fase 165: Creative Engine no Publicador novo — Pesquisa

**Pesquisado em:** 2026-10-04
**Domínio:** integração do Creative Engine (v24.0, Fases 160–161 em produção) ao Publicador interno (`pub_rascunhos`, editor de 3 colunas) — Laravel 12 + Inertia + React
**Confiança:** ALTA no mapa de acoplamento e nos pontos de costura (tudo lido no código do worktree `bb1b61f8`, HEAD = origin/main); MÉDIA nas decisões de UI (sem UI-SPEC); as recomendações de desenho estão marcadas como tal.

Todos os caminhos abaixo são relativos a `C:/tmp/ecf-publicador-spec-261001/`. As citações `arquivo:linha` foram lidas nesta sessão `[VERIFIED: codebase]`. Nenhum pacote novo é necessário (ver "Auditoria de legitimidade de pacotes").

<user_constraints>
## Restrições do Usuário (do 165-CONTEXT.md — copiadas, travadas)

### Decisões travadas
- **D-01 — Integração de verdade, não ponte.** Nada de criar `ml_anuncio_rascunhos` por trás para o Publicador.
- **D-02 — Só ACRESCENTAR no Creative Engine (código do outro dev).** Coluna nova **anulável** `pub_rascunho_id` em `ml_anuncio_criativos` e `ml_anuncio_criativo_kits` (FK para `pub_rascunhos`, `nullOnDelete`, índice simples — nada de índice único). Migration aditiva, idempotente por `Schema::hasColumn`, conferida contra as armadilhas de MariaDB (§6 de `desempenho-bonificacao.md`; 1553/1830/1059). `rascunho_id` (antigo) já é anulável — criativo do Publicador tem `rascunho_id = NULL` e `pub_rascunho_id` preenchido. `CreativeContextBuilder::paraCriativo` ganha um **segundo caminho** com `pub_rascunho_id`; o caminho do `payload` antigo fica **byte a byte igual** (`Phase160`/`Phase161`/`Quick261003L8o` provam). Fora desta fase: `MlPublicacaoService`, `CreativeKitPublicacao`, o gate do PUB-03, o assistente antigo e a Fase 162.
- **D-03 — Endpoints próprios do Publicador.** Rotas novas em `mlb.anuncios.publicador.*` (`routes/mlb_anuncios.php`, grupo `role:admin`), por PRODUTO (`publicador/produtos/{produto}/criativos/...`), reaproveitando a lógica de planejar/gerar/regenerar/aprovar do kit (Fase 161 + quick 261003-l8o: variação obrigatória na regeneração, confirmação de custo, limitadores nomeados em pt-BR). Autorização igual à do editor (`MlbPublicadorController::produto()` → 404 fora do escopo) MAIS a do Creative Engine (`CreativeEngineAtivo` + `CreativePermissao::podeGerar`).
- **D-04 — Aprovada vira `pub_imagens`, nunca envio direto ao ML.** Entra pelo caminho de foto que já existe (`ImagemAssetService`: conferência de tamanho/formato, disco privado, envio ao ML só se a conta está liberada — D26) e é atribuída ao grupo de onde o kit foi pedido: galeria geral (`GENERAL`) quando pedido pelo item "Fotos"; grupo da variação quando pedido no bloco de fotos de uma variação. Entra no FIM do grupo, na ordem dos slots; nada que já estava lá é apagado. Limite de fotos: a validação do Publicador já bloqueia excesso (V-IMG-06/07) — não truncar em silêncio.
- **D-05 — Tela dentro do editor de 3 colunas.** O item "Fotos" e o bloco de fotos de cada variação ganham **"Gerar com IA"**. Reaproveitar `PainelCriativosIa` / `KitCriativosGrade` com props novas, **sem mudar o comportamento no assistente antigo**. Se o acoplamento ao `AnunciarML` impedir, extrair a parte comum para um componente compartilhado — decidir no planejamento, com o gate de não-regressão do assistente antigo. Seguir a identidade da mesa (gates de `tests/js/publicador-mesa.test.js`: tipografia 24/15/13/11, pesos 400/700, select nativo, sem `route(` nos cards, nenhum amarelo sólido em card; o único amarelo da tela é do Inspetor). Sem UI-SPEC (`--skip-ui`).
- **D-06 — Chave e permissão.** Mesma chave `creative_engine_ativo` (registro em `configuracoes`) e mesma permissão `podeGerar`. Sem a chave ou sem a permissão o botão não aparece. **Pendente de confirmação do outro dev.**
- **D-07 — Custo.** Gemini pago (~US$ 0,101 por imagem, ~US$ 0,71 por kit de 7). Manter a confirmação de custo antes de gerar (quick 261003-l8o) e os tetos/limitadores existentes. Nada de gerar sozinho.

### Critério de Claude (decidir no planejamento, com justificativa)
- **Fotos de referência:** preferência por reaproveitar as fotos que já estão no rascunho do Publicador (do grupo de onde o kit foi pedido) como referência, com upload novo opcional; respeitar a retenção efêmera (D-02 da v24: a foto ORIGINAL enviada só para gerar não vira acervo).
- **Contexto da variação:** quando pedido numa variação, incluir os valores dela (ex.: Cor = Azul) no contexto/Product Truth.
- Como o hook `usePublicador` acompanha o kit (polling próprio como `useIaDoPublicador`/palavras-IA) e como a mesa se atualiza quando a foto aprovada entra (reler pelo caminho de estrutura, `estruturar`).
- Divisão em planos/ondas (fatia fina ponta a ponta primeiro, como a v24 fez).

### Ideias adiadas (FORA DE ESCOPO)
- Fase 162 (validador Gemini-juiz e regeneração automática) e Fase 163 (custo por projeto/POC) — do outro dev.
- Gerar criativos para várias variações de uma vez (um kit por variação de cada vez nesta fase).
- Remover a ponte "Gerar criativos no assistente antigo" — só depois que a 165 estiver em uso.
</user_constraints>

## Restrições do projeto (do CLAUDE.md)

- Comentários, mensagens e artefatos em **pt-BR**; nomes de arquivo/classe/método/rota nunca traduzidos.
- Fase 165 é **GSD obrigatório** porque altera tabela existente COM dado em produção (`ml_anuncio_criativos` tem ao menos 1 linha; kits podem ter mais após 03/10) — exige baseline de testes e VERIFICATION. Baseline medido nesta pesquisa (abaixo).
- `git commit -- <caminhos>` — nunca `git add -A`/`git add .`; conferir `git show <sha>` antes do push (memória: `-- <caminhos>` não protege contra o que outra sessão deixou no índice).
- `npm run build` ao fim de qualquer alteração de frontend (em worktree novo sai 0 SEM buildar — conferir manifest).
- **Nenhum deploy sem autorização explícita.** Pós-deploy: `queue:restart` (não `supervisorctl restart`).
- Aviso de coordenação `AVISO-COORDENACAO-164` em `CLAUDE.md`: mexer em `app/Services/Creative/`, endpoints `criativo*` do `MlbAnuncioController`, `AnunciarML.jsx`, `PainelCriativosIa.jsx` ou `KitCriativosGrade.jsx` exige avisar o usuário/outro dev. **Esta pesquisa recomenda um desenho que toca UM arquivo de `app/Services/Creative/` (`CreativeContextBuilder` + o DTO `CreativeContext`) e NENHUM dos outros quatro** (ver §2 e §9).

## Requisitos propostos (IDs CE165-xx)

<phase_requirements>
| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| CE165-01 | Migration aditiva e idempotente: `pub_rascunho_id` (FK `nullOnDelete`, índice simples) + `pub_grupo` (string 600, anulável) em `ml_anuncio_criativos` e `ml_anuncio_criativo_kits`; `pub_imagem_id` (FK `nullOnDelete`) em `ml_anuncio_criativos`. Nada NOT NULL sem default, nada de enum, índices nomeados ≤ 64 chars. | §6 |
| CE165-02 | `CreativeContextBuilder::paraCriativo` ganha o segundo caminho (rascunho do Publicador) escolhido por `pub_rascunho_id` não nulo (no criativo ou no kit); caminho antigo byte a byte igual. | §2 |
| CE165-03 | O contexto do Publicador usa título efetivo, categoria, atributos com `value_name`, descrição, quantidade/combinações das variações ativas e a loja da conta do produto — sem exigir `company_id`. | §2, §7 |
| CE165-04 | Contexto da variação: pedido num grupo de variação injeta os valores do grupo (ex.: `COLOR` = Azul) como fato verificado, sem alterar `ProductTruthBuilder`. | §2 |
| CE165-05 | Referências: o operador escolhe fotos do próprio rascunho (`pub_imagens` com arquivo) e/ou sobe novas; ambas viram cópias EFÊMERAS em `creative-referencias/{token}/` (retenção da v24 preservada; a foto do rascunho continua sendo foto do rascunho). | §3 |
| CE165-06 | Endpoints `mlb.anuncios.publicador.criativos.*` por produto: referência, planejar, gerar, status, imagem, regenerar, aprovar slot e aprovar kit — todos com chave (404), escopo do produto (404), amarração token↔rascunho (IDOR) e `CreativePermissao` depois do escopo. | §1, §7 |
| CE165-07 | Idempotência: no máximo um kit ativo por (`pub_rascunho_id`, `pub_grupo`); lock por (rascunho, grupo); retomar o kit ativo ao reabrir a tela. | §1 |
| CE165-08 | Aprovar slot/kit grava a imagem em `pub_imagens` pelo `ImagemAssetService::receber` + atribuição no FIM do grupo pedido, sob `RascunhoRepository::travar`, com dedupe por sha256 e D26 (conta não liberada = foto `pending`); recusa em rascunho PUBLISHING/PUBLISHED; nunca trunca; nunca envio direto ao ML; nunca `CreativeKitPublicacao`/`payload.pictures`. | §4 |
| CE165-09 | Ciclo de vida: ao fechar o kit, apagar a referência do portador (`ReferenciaEfemeraService::apagar`); a varredura diária `creative:limpar-referencias` continua valendo sem mudança. | §3 |
| CE165-10 | Front: hook `useCriativosDoPublicador` (polling próprio, retomada após F5) + painel nativo da mesa; botão "Gerar com IA" no item "Fotos" e no bloco de fotos de cada variação; aprovação relê o estado do Publicador. Visível só com chave ligada + `podeGerar`. | §5 |
| CE165-11 | Custo e limites: confirmação de custo antes de gerar; limitadores nomeados `creative-kit-planejar`/`creative-kit-gerar`/`creative-regenerar`; tetos do kit; mensagens em pt-BR. | §7 |
| CE165-12 | Não regressão: assistente antigo e rotas `mlb.anuncios.criativo.*` inalterados; suítes `Phase160`/`Phase161`/`Quick261003L8o` e `Publicador` verdes; gates JS da mesa verdes. | §8 |
</phase_requirements>

## Resumo

O Creative Engine está acoplado ao rascunho antigo em **três pontos reais**, não em dez: (1) `CreativeContextBuilder::paraCriativo` lê `$criativo->rascunho->payload` (`app/Services/Creative/CreativeContextBuilder.php:45-51`) — é o ÚNICO ponto de leitura do conteúdo do rascunho, por desenho (CTX-02); (2) a aprovação grava `payload.pictures` e sobe ao ML via `MlImagemService` (`MlbAnuncioController.php:1600-1651` e `2154-2188`, `CreativeKitPublicacao`); (3) as rotas/controller partem de `{rascunho}` e escopam por `$criativo->rascunho` (`MlbAnuncioController.php:1264`, `1372-1402`). Os jobs (`GerarCriativoIaJob`, `PlanejarKitCriativosJob`), o `ProductTruthBuilder`, o `CreativePromptBuilder`, o `CreativePlanner`, o `CreativeKitDespachante`, o `ReferenciaEfemeraService` e os dois models **não precisam mudar**: só carregam `rascunho_id` como dado opaco.

A costura mínima é: criar o criativo/kit do Publicador com `rascunho_id = NULL` e `pub_rascunho_id` preenchido, e fazer o `CreativeContextBuilder` ramificar por `pub_rascunho_id` ANTES da checagem de `rascunho === null`. Como o kit é lido pelos slots via `$criativo->kit` (e o portador ganha `kit_id` só no fim do planejamento), a resolução deve olhar `$criativo->pub_rascunho_id ?? $criativo->kit?->pub_rascunho_id` — assim `PlanejarKitCriativosJob` (que copia só `rascunho_id`, linha 144) e `GerarCriativoIaJob` ficam intactos. Os controllers do assistente antigo também ficam intactos: o Publicador ganha um controller próprio (`MlbPublicadorCriativoController`) com a sua orquestração, porque os métodos antigos escopam por `$criativo->rascunho` e, com `rascunho = NULL`, **o escopo vira no-op** (`checarEscopoDoRascunho` retorna cedo, `MlbAnuncioController.php:1385-1387`) e `planejarKitSobLock` casaria QUALQUER kit com `rascunho_id` nulo (`:1901`) — reaproveitar as rotas antigas com tokens do Publicador seria um bug de vazamento entre produtos.

"Aprovar" no Publicador é um novo significado do mesmo estado `aprovado`: a imagem é copiada para `pub_imagens` pelo `ImagemAssetService::receber` e atribuída ao fim do grupo, sob a trava de linha do rascunho. O envio ao ML acontece depois, na conferência/publicação, pelo `enviarPendentes` que já existe. O front reaproveita o CONTRATO JSON do kit (o mesmo do `criativoKitStatus`), mas o painel deve ser um componente **nativo da mesa** (`Mesa/PainelCriativos.jsx`, na lista `CARDS` dos gates) porque `PainelCriativosIa`/`KitCriativosGrade` têm ~10 `route(...)` fixos, `text-sm`/`font-medium`/`text-[12px]`/`text-[10px]`/`bg-emerald-500` e vivem fora dos gates de identidade — e porque tocá-los é exatamente a área onde a Fase 162 vai mexer.

**Recomendação principal:** novo controller + novas rotas + migration aditiva + ramo no `CreativeContextBuilder`; painel e hook novos do lado do Publicador; **nenhuma alteração** em `MlbAnuncioController`, `PainelCriativosIa`, `KitCriativosGrade`, `AnunciarML`, jobs, `CreativeKitPublicacao`, `ProductTruthBuilder` ou `ReferenciaEfemeraService`.

## Mapa de Responsabilidade Arquitetural

| Capacidade | Tier primário | Tier secundário | Justificativa |
|------------|---------------|-----------------|---------------|
| Montar contexto do produto para a IA | API / Backend (`CreativeContextBuilder`) | — | Fronteira única CTX-02; lê `pub_rascunhos` via `RascunhoRepository::snapshot` |
| Autorização por produto + chave + permissão | API / Backend (controller novo) | — | 404 fora do escopo (`ProgramasPublicadorService::empresaDoProduto`), depois `CreativePermissao` |
| Guarda de referência efêmera | Storage privado (disco `local`) | API | `creative-referencias/{token}/` via `ReferenciaEfemeraService`, varredura 48h |
| Planejar/gerar/regenerar | Fila `creative` (jobs existentes) | API (dispara 202) | Jobs reaproveitados sem mudança; custo pago só na fila |
| Aprovar → foto do rascunho | API / Backend (serviço novo + `ImagemAssetService`) | Banco (`pub_imagens`, `pub_imagem_atribuicoes`) | Trava de linha WR-B02, dedupe sha256, D26 |
| Estado e polling do kit | Browser (hook novo) | API (JSON do kit) | Mesmo padrão de `useIaDoPublicador`; sem Redux |
| Painel/grade de criativos | Browser (componente da mesa) | — | Apresentação pura; rotas só no hook (gate: sem `route(` nos cards) |
| Limites de taxa | API (RateLimiter nomeado) | — | Reusar `creative-kit-planejar`, `creative-kit-gerar`, `creative-regenerar` |

## Stack padrão

Sem dependências novas. Tudo já está no projeto `[VERIFIED: composer.json/package.json via CLAUDE.md]`.

### Núcleo
| Biblioteca | Versão | Propósito | Por que |
|------------|--------|-----------|---------|
| Laravel | 12.x | controller, rotas, RateLimiter, filas, Cache::lock | stack travada |
| Inertia + React 18 | ^2 / ^18.2 | página `Mlb/Publicador/Editor` já existe | sem mudança de stack |
| axios | ^1.11 | chamadas do hook (padrão de `useIaDoPublicador.js`) | já usado |
| lucide-react | ^1.11 | ícones (`Sparkles`, `Loader2`...) | já usado na mesa |

### Alternativas consideradas
| Em vez de | Poderia | Tradeoff |
|-----------|---------|----------|
| Controller novo | Reusar rotas `criativo.*` antigas | Escopo vira no-op com `rascunho = NULL`; `planejarKitSobLock` vaza kit entre produtos; rejeitado |
| Painel nativo da mesa | Parametrizar `PainelCriativosIa` com `rotas` | Toca arquivo da Fase 162 e fora dos gates de identidade; só vale se o usuário preferir menos código novo |
| Adaptador `UploadedFile` para copiar `pub_imagens` | Novo método em `ReferenciaEfemeraService` | Evita tocar serviço do outro dev (ver §3) |

**Instalação:** nenhuma.

## Auditoria de legitimidade de pacotes

Esta fase não instala pacotes externos. Nenhum nome de pacote foi recomendado; `slopcheck` não se aplica. **Pacotes removidos por [SLOP]: nenhum. Pacotes [SUS]: nenhum.**

## Padrões de Arquitetura

### Diagrama de fluxo (caminho feliz)

```
Operador (editor 3 colunas)
   │  clica "Gerar com IA" no item Fotos (GENERAL) ou no bloco da variação (grupo)
   ▼
useCriativosDoPublicador (Browser) ── axios ──▶ MlbPublicadorCriativoController (role:admin)
   │                                              │ 1) chave ligada? (404)   2) produto() autorizado? (404)
   │                                              │ 3) token/kit pertence ao rascunho do produto? (404)
   │                                              │ 4) CreativePermissao->exigir(...) (403)
   │                                              ▼
   │   POST referencias ──▶ cria MlAnuncioCriativo(pub_rascunho_id, pub_grupo, rascunho_id=NULL)
   │                         + copia pub_imagens/uploads ▶ creative-referencias/{token}/  (efêmero)
   │   POST kit (planejar) ▶ lock(rascunho,grupo) ▶ MlAnuncioCriativoKit(pub_rascunho_id, pub_grupo) ▶ PlanejarKitCriativosJob [fila creative]
   │                                              │        └─ CreativeContextBuilder::paraCriativo(portador) ── ramo Publicador ──▶ RascunhoRepository::snapshot + DadosEfetivosService
   │   (confirma custo na tela) POST kit/gerar ──▶ CreativeKitDespachante ▶ 7× GerarCriativoIaJob [fila creative] ▶ imagem em creative-geradas/{token}/
   │   GET kit (polling 5s) ◀── JSON do kit (mesmo contrato do criativoKitStatus, URLs de imagem/referência do Publicador)
   │   POST aprovar slot/kit ──▶ PublicadorCriativoAprovacaoService
   │                              ├─ ImagemAssetService::receber(bytes)  ▶ pub_imagens (disco privado, sha256, D26)
   │                              └─ DB::transaction{ travar; snapshot; gravarAtribuicoes(fim do grupo); tocar }
   ▼
pub.recarregar() relê o estado ▶ a foto aparece no bloco de fotos ▶ sobe ao ML só em conferir/publicar (enviarPendentes)
```

### Estrutura recomendada (arquivos novos em **negrito**)
```
app/Http/Controllers/MlbPublicadorCriativoController.php       # NOVO — orquestração HTTP do Publicador
app/Services/Publicador/Criativos/                              # NOVO
    PublicadorCriativoAprovacaoService.php                      #   aprovar → pub_imagens (+ trava, + dedupe)
    PublicadorCriativoReferenciaService.php                     #   pub_imagens/uploads → referência efêmera (adaptador UploadedFile)
    PublicadorCriativoKitPresenter.php                          #   JSON do kit com as rotas do Publicador
app/Services/Creative/CreativeContextBuilder.php                # ALTERADO — ramo Publicador (único do Creative Engine)
app/Services/Creative/Dto/CreativeContext.php                   # ALTERADO — 1 parâmetro opcional no FIM (ver §2)
app/Models/MlAnuncioCriativo.php, MlAnuncioCriativoKit.php      # ALTERADOS — $fillable + relações (aditivo)
database/migrations/2026_10_0X_..._add_pub_columns_to_ml_anuncio_criativos_and_kits.php  # NOVA
routes/mlb_anuncios.php                                         # ALTERADO — bloco novo dentro do grupo publicador/produtos/{produto}
resources/js/Components/Publicador/useCriativosDoPublicador.js  # NOVO — estado + polling + axios/rota
resources/js/Components/Publicador/Mesa/PainelCriativos.jsx     # NOVO — apresentação pura (entra em CARDS dos gates)
resources/js/Components/Publicador/FotosPorGrupo.jsx            # ALTERADO — prop opcional do botão "Gerar com IA" no BlocoDeFotos
resources/js/Components/Publicador/Mesa/CardFotos.jsx, CartaoVariante.jsx, resources/js/Pages/Mlb/Publicador/Editor.jsx  # ALTERADOS — composição
```

### Anti-padrões a evitar
- **Reusar `criativo.kit.planejar`/`criativo.kit.status`/`criativo.aprovar` com token do Publicador.** O escopo vira no-op e `planejarKitSobLock` (`MlbAnuncioController.php:1901`) compara `rascunho_id = NULL` (Eloquent vira `whereNull`) e devolveria o kit de outro produto.
- **Passar pelo `CreativeKitPublicacao`.** Ele reconstrói `payload.pictures` de `MlAnuncioRascunho` (`CreativeKitPublicacao.php:55-60,118-140`); no Publicador não há `payload`.
- **Chamar `colocarNoGrupo` sem trava.** Hoje `MlbPublicadorController::foto()` lê o snapshot e regrava a LISTA INTEIRA de atribuições (`gravarAtribuicoes` apaga tudo e recria, `RascunhoRepository.php:277-294`) fora de transação — duas aprovações simultâneas (ou aprovação + arrasto) perdem atribuições.
- **Subir ao ML dentro da aprovação.** D26: `receber()` já só envia se a conta está liberada; o resto fica `pending`.
- **Colocar `route(` em `Mesa/*.jsx`.** Gate dos CARDS (`tests/js/publicador-mesa.test.js:63-67`); rotas ficam no hook.

## 1. Mapa de acoplamento (item 1 do pedido)

Legenda: **Nada** = reaproveitar sem tocar; **Ramo** = if no mesmo arquivo; **Adaptador** = código novo do lado do Publicador; **Novo** = não existe equivalente.

### `app/Services/Creative/*`
| Arquivo:linha | Lê/escreve `rascunho`/`payload`/`rascunho_id` | Necessidade para o Publicador |
|---|---|---|
| `CreativeContextBuilder.php:45-51` | `$criativo->rascunho`, `$rascunho->payload` | **Ramo** (único ponto real). Linhas 58-65 (atributos), 67-78 (variações), 80-100 (DTO), 89 (`$criativo->company?->nomeContaMl()`) |
| `Dto/CreativeContext.php:24,47` | `public int $rascunhoId` / chave `rascunho_id` em `paraAuditoria()` | **Ramo mínimo**: parâmetro opcional no FIM do construtor (`?int $pubRascunhoId = null`); `paraAuditoria()` só inclui `pub_rascunho_id` quando não nulo, para a saída antiga ficar idêntica. `rascunhoId` recebe `0` no ramo do Publicador (o campo é `int` não nulo). |
| `ProductTruthBuilder.php` | nenhuma leitura (recebe `CreativeContext`) | **Nada**. Usa `$contexto->atributos`, `marca`, `modelo`, `referenciasMeta` (linhas 91-107, 140-152) |
| `CreativePromptBuilder.php`, `CreativePlanner.php:115` | só `ProductTruth`/`CreativeContext` (`categoriaId`) | **Nada** |
| `CreativeKitDespachante.php:46,75` | `GerarCriativoIaJob::dispatch($slot->id)` por slot | **Nada** |
| `CreativeKitPublicacao.php:55-140` | `where('rascunho_id', $rascunho->id)`, `payload.pictures` | **Não usar** (fora de escopo, D-02). Substituto: `PublicadorCriativoAprovacaoService` (§4) |
| `CreativePermissao.php:91-104`, `CreativeEngineAtivo.php:27,41-52` | nenhuma | **Nada** — reutilizar como estão |
| `ReferenciaEfemeraService.php:38-108` | nenhuma (usa `$criativo->token`) | **Nada** (ver §3 para reaproveitar `pub_imagens`) |

### Jobs
| Arquivo:linha | Acoplamento | Necessidade |
|---|---|---|
| `GerarCriativoIaJob.php:112,131,169,214` | só `MlAnuncioCriativo::find`, `$criativo->kit`, `paraCriativo($criativo)`; gravação em `creative-geradas/{token}/{slot}.jpg` | **Nada**. O contexto sai do builder (ramo). |
| `PlanejarKitCriativosJob.php:111,140-151,156` | `paraCriativo($portador)` e cria slots copiando `rascunho_id`, `company_id`, `mlb_empresa_id`, `user_id` do portador (linha 144) | **Nada**, DESDE QUE o builder resolva `pub_rascunho_id` também pelo **kit** (os slots não ganham `pub_rascunho_id` porque o job não o copia). Ver §2. |

### Models
| Arquivo:linha | Necessidade |
|---|---|
| `MlAnuncioCriativo.php:40-52` (`$fillable`), `:79-82` (`rascunho()`) | **Aditivo**: incluir `pub_rascunho_id`, `pub_grupo`, `pub_imagem_id` no `$fillable`; relação `pubRascunho()`/`pubImagem()` |
| `MlAnuncioCriativoKit.php:77-85` (`$fillable`), `:105` | **Aditivo**: `pub_rascunho_id`, `pub_grupo` + `pubRascunho()` |

### Controller `MlbAnuncioController` (endpoints `criativo*`) — NENHUM é alterado
| Endpoint (linha) | Acoplamento ao rascunho antigo | Publicador |
|---|---|---|
| `criativoReferenciaStore` (1264-1324) | `{rascunho}` na rota; escopo por empresa/user do rascunho; cria criativo com `rascunho_id` (1296-1304) | **Novo** endpoint por produto (cria com `pub_rascunho_id`) |
| `criativoReferenciaVer` (1331-1363) | escopo por `$criativo->rascunho` | **Novo** (`.../criativos/{token}/referencia/{indice}`) com escopo por produto |
| `checarEscopoDoCriativo/Rascunho` (1372-1402) | `null` → retorna sem checar | Substituído por checagem "token pertence ao rascunho do produto autorizado" |
| `criativoGerar` (1418) | só estado do criativo | Fluxo de 1 imagem foi aposentado na UI (quick l8o); **não expor** no Publicador |
| `criativoStatus` (1478), `criativoImagem` (1519) | escopo por rascunho; URLs com nomes de rota antigos | **Novo** `imagem` por produto; status individual só se o kit sem slot for necessário (não é) |
| `criativoAprovar` (1553) | exige `$rascunho !== null` (1566), `$this->imagem->enviar($rascunho->company, ...)` (1600), `payload.pictures` (1642-1651) | **Novo** (`PublicadorCriativoAprovacaoService`) |
| `criativoRegenerar` (1728-1819) | estado + `$kit`; sem rascunho (usa só escopo) | **Novo** método com a MESMA orquestração (motivo ≤ 300, `podeRegenerarAsset`, transação, `GerarCriativoIaJob::dispatch`) — é cópia consciente de ~90 linhas (§9) |
| `criativoKitPlanejar` + `planejarKitSobLock` (1837-1933) | lock `criativo-kit-planejar:{rascunho_id}` (1850); kit existente por `rascunho_id` (1901) | **Novo**: lock e consulta por (`pub_rascunho_id`, `pub_grupo`) |
| `criativoKitGerar` (1947) | `checarEscopoDoRascunho($kit->rascunho)` | **Novo** com escopo por `pub_rascunho_id` |
| `criativoKitStatus` (2006-2076) | URLs via `route('mlb.anuncios.criativo.imagem'/'.referencia.ver')` (2035, 2068) | **Novo** presenter com as rotas do Publicador |
| `criativoKitAprovar` (2101-2243) | `$kit->rascunho`, `enviar($rascunho->company...)`, `aplicarPictures`, apaga referência do portador (2204-2210) | **Novo** |

### Front (assistente antigo — não alterar)
- `PainelCriativosIa.jsx`: props `{ empresa, rascunhoId, ativo, onImagemAprovada }` (linha 46); `route(...)` em 121, 156, 190, 228, 270, 304, 345, 374, 405; `if (!ativo) return null` (440); texto de custo e botões com `text-sm`/`font-medium`.
- `KitCriativosGrade.jsx`: 100% por props (`kit, referencias, onGerar, gerando, kitEmAndamento, confirmacaoRecusada, onCancelarConfirmacao, onRegenerar, onAprovar, processando, erros, motivos, onMotivoChange` — linhas 36-59), nenhuma chamada HTTP; importa `ETAPA_LABEL` de `PainelCriativosIa` (linha 3).
- `AnunciarML.jsx:1040` recebe `creativeAtivo` e monta `<PainelCriativosIa ... />` (linha 2439); `MlbAnuncioController.php:173` envia `'creativeAtivo' => $this->creativeAtivo->ativa()`.

## 2. A costura mínima no `CreativeContextBuilder` (item 2)

**Princípio:** o ramo novo é um `if` no início; o código antigo permanece lido na mesma ordem. Sketch (não é código de produção):

```php
public function paraCriativo(MlAnuncioCriativo $criativo): CreativeContext
{
    $portador = $criativo->portadorDeReferencia();

    if ($portador->referenciasVivas() === []) {          // linhas 39-43, INTACTAS (mesma mensagem)
        throw new \RuntimeException('Este criativo não tem foto de referência viva — suba uma foto do produto antes de gerar.');
    }

    // NOVO: o Publicador é identificado por pub_rascunho_id — no criativo (portador) ou no kit (slots).
    $pubId = $criativo->pub_rascunho_id ?? $criativo->kit?->pub_rascunho_id ?? $portador->pub_rascunho_id;
    if ($pubId !== null) {
        return $this->paraPublicador($criativo, $portador, (int) $pubId);
    }

    $rascunho = $criativo->rascunho;                      // linha 45 em diante, INTACTA
    ...
}
```

Por que `pub_rascunho_id` precisa estar no kit: o `PlanejarKitCriativosJob` chama `paraCriativo($portador)` **antes** de o portador receber `kit_id` (a transação que faz `$portador->update(['kit_id' => ...])` vem depois, linha 156) — então o portador carrega o seu próprio `pub_rascunho_id`/`pub_grupo`; já os 7 slots (criados na linha 140-151 sem copiar nenhuma coluna nova) resolvem pelo `kit`. Isso é o que dispensa tocar nos dois jobs. `pub_grupo` segue a mesma cadeia de resolução.

**Montagem do contexto a partir de `pub_rascunhos`** (todas as fontes já existem, `[VERIFIED: codebase]`):

| Campo do `CreativeContext` | Fonte no Publicador | Observação |
|---|---|---|
| `produto` (título) | `RascunhoRepository::snapshot($r)` → `->comEfetivos($e['titulos'], $e['precos'])` com `DadosEfetivosService::daProduto($r->produto)` (`DadosEfetivosService.php:27-34`); usar o título do primeiro alvo ATIVO com título não vazio; fallback `$r->produto->nomeExibido()` | É exatamente o par que `EditorRascunhoService::estado()` usa (`:379-382`). **Não** gravar nada de volta (regra do "preço congelado"). |
| `categoriaId` | `$r->categoria_id` (`snapshot->categoriaId` é `(string)`, pode ser `''` → `null`) | |
| `descricao` | `$snapshot->descricao` | Hoje o prompt nem usa descrição (só `ProductTruth`); mantém paridade |
| `atributos` | `$snapshot->atributos` (`attribute_id → [value_id, value_name, ..., origem, revisar]`, `RascunhoRepository.php:104`) filtrando `value_name` não vazio, **mesma regra TRUTH-01** do ramo antigo | Atributo "não se aplica" (`value_id='-1'`, `value_name` nulo) cai fora naturalmente. A tela grava `value_id`+`value_name` juntos (`CampoAtributo.jsx:63`), então a regra não esvazia a ficha. |
| `marca`/`modelo` | `atributos['BRAND']`/`['MODEL']` | igual ao antigo |
| `variacoes` | `quantidade = count($snapshot->variantesAtivas())`; `combinacoes` = lista de `valueName` por variante ativa (`Variante::valores` é `chaveEixo → ValorEixo`, `RascunhoSnapshot.php:83-86`) | mesma forma `{quantidade, combinacoes}` |
| `loja` | `$r->produto->contaOuNula()?->nomeContaMl()` (interface `ContaMercadoLivre`, `MlbEmpresa.php:228`, `Company.php:638`) | **Não** usar `$criativo->company` (nulo para produto de polo sem `Company`) |
| `imagensReferencia`/`referenciasMeta` | `ReferenciaEfemeraService::bytesDe($portador)` e `referenciasVivas()` — igual ao antigo | |
| `rascunhoId` | `0` | + `pubRascunhoId` no novo parâmetro opcional |

**Contexto da variação (CE165-04) sem tocar no `ProductTruthBuilder`:** quando `pub_grupo !== GENERAL`, achar as variantes ativas cujo `ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $snapshot->fotosPorVariante)` é igual ao grupo (`ResolvedorGruposImagem.php:32-50`) e, para cada eixo que define a foto (`definesPicture`), acrescentar `atributos[<chave do eixo>] = <valueName>` ao mapa (ex.: `COLOR => 'Azul'`). O `ProductTruthBuilder` já transforma `atributos` em `fatosVerificados` com rótulo ("Cor") e **deixa de emitir** a claim "Não afirme nem altere a cor — não há cor confirmada" quando `COLOR` existe (`ProductTruthBuilder.php:146-148`). Eixo customizado (`~custom`, `ChaveCanonica::EIXO_CUSTOM`) não é id de atributo do ML: ignorar no mapa (ou usar `nome` do eixo — decisão do planejador; recomendo ignorar para não inventar id). Sem `fotosPorVariante` e sem eixo que defina foto, não há grupo de variação (tudo é `GENERAL`).

**`slot_plano` e `CreativeSlotCatalog::elegiveis`** decidem por `atributosIds` (`ProductTruthBuilder.php:~86`) — herdam o `COLOR` do foco automaticamente.

Teste de byte-a-byte do caminho antigo: `paraAuditoria()` do ramo antigo deve continuar com as MESMAS 10 chaves e ordem (`CreativeContext.php:46-57`); o parâmetro novo só aparece na auditoria quando preenchido.

## 3. Fotos de referência (item 3)

**Como o `ReferenciaEfemeraService` guarda:** `guardar($criativo, UploadedFile[])` grava cada arquivo em `creative-referencias/{token}/{indice}.{ext}` no disco `local`, devolve `[indice, path, mime, bytes, nome, hash(sha1)]` (`ReferenciaEfemeraService.php:38-63`); o controller grava isso em `criativo.referencias` (JSON). `bytesDe()` lê do disco (teto 14, `:72-85`). `apagar()` remove o DIRETÓRIO do token e marca `referencias_apagadas_em` (`:92-108`). Retenção: apagar ao aprovar o kit inteiro (`MlbAnuncioController.php:2204-2210`), e a varredura diária apaga o que sobrou após 48h (`LimparReferenciasCriativos.php:65-67`, config `services.creative.retencao_referencias_horas`). A varredura filtra só pelas colunas do próprio criativo (`created_at`, `referencias_apagadas_em`, `referencias`) — **funciona para criativos do Publicador sem mudança**.

**`pub_imagens` como referência — compatível com a D-02 da v24?** Sim, com a cópia efêmera:
1. A foto do rascunho (`pub_imagens.caminho`, `publicador/{rascunhoId}/{sha}.jpg`) é parte do rascunho do cliente, não "acervo do Creative Engine". Ela continua onde está, com a vida do rascunho.
2. A **cópia** em `creative-referencias/{token}/` é efêmera (apagada na aprovação do kit ou em 48h). É isso que a D-02 protege (a foto original enviada só para gerar não vira acervo). Nenhuma imagem é movida nem apagada de `publicador/`.
3. Sem mudar `ReferenciaEfemeraService`: o adaptador do Publicador cria `new \Illuminate\Http\UploadedFile($caminhoAbsoluto, $nome, $mime, null, true)` (modo teste, sem `is_uploaded_file`) para cada `pub_imagens` com `caminho` não nulo e chama `guardar()` junto com os uploads novos. `[ASSUMED]` — comportamento de `getRealPath()` nesse modo não foi executado aqui; o planejador deve cobrir com teste (A2 no log).
4. Imagem do Anunciar antigo sem arquivo (`caminho` nulo, `ImagemAssetService.php:68-73`) **não** serve como referência: filtrar `whereNotNull('caminho')`; se o grupo não tem foto com arquivo, a UI pede upload.
5. Validação: `imagens[]` ids precisam pertencer a `$r->imagens()` (IDOR); uploads com a mesma regra `image|max:10240` e teto somado `MAX_REFERENCIAS = 14` (`ReferenciaEfemeraService.php:29`).
6. Qual grupo sugerir como padrão: as fotos do grupo de onde o kit foi pedido (`fotosDoGrupo`), caindo para a galeria geral se o grupo estiver vazio — decisão de UI (Critério de Claude).

## 4. Aprovada → `pub_imagens` (item 4)

**Onde está a imagem gerada:** `creative-geradas/{token}/{slot}.jpg` no disco `local` (`GerarCriativoIaJob.php:214-215`), caminho em `ml_anuncio_criativos.imagem_path`, MIME em `imagem_mime` (default JPEG, `config/services.php:448`). Gemini sai em 2K 1:1 (`GeminiImageProvider.php:166-168`) — bem abaixo de 10 MB e acima de 500 px, então passa em `ValidadorImagem` (V-IMG-01/02/03; `ValidadorImagem.php:20-45`). `[ASSUMED]` que o tamanho real fica < 10 MB (medido apenas pela config); a mensagem de bloqueio já seria exibida se não ficasse.

**Como o Publicador ingere hoje** (`MlbPublicadorController::foto`, `:148-164`): `ImagemAssetService::receber($r, $bytes, $nome)` (`ImagemAssetService.php:35-57`) → valida L1, deduplica por sha256 no rascunho (`:43-46`, devolve a existente com `nova=false`), grava em `publicador/{rascunhoId}/{sha}.{ext}` (`:48-49`), cria `PubImagem` `pending` e chama `enviarAoMl` (`:56`), que **retorna sem subir** quando a conta não está liberada (D26, `:78-81`) ou o produto não tem token (WR-B04). Depois `colocarNoGrupo` (`:353-362`) lê o snapshot, ignora se já está no grupo e regrava a lista com a nova no fim (`posicao = count($doGrupo)`), e chama `tocar` (sobe `revisao`, invalida a validação).

**Desenho recomendado do `PublicadorCriativoAprovacaoService::aprovar(PubRascunho $r, MlAnuncioCriativo $slot, string $grupo, User $u)`:**
1. Recusar se `$slot->status !== pronto` (ou `aprovado` com foto ainda no rascunho → idempotente 200), se `imagem_path` ausente ou arquivo sumiu (422 em pt-BR, mesmos textos de `criativoAprovar`), e se o rascunho está em `PUBLISHING/PUBLISHED/PARTIALLY_PUBLISHED` (mesma lista `INTOCAVEIS` de `IaParaRascunhoService.php:50`).
2. **Fora da trava:** `ImagemAssetService::receber($r, $bytes, "criativo-{$slot->token}.jpg")` — pode fazer HTTP ao ML (conta liberada); learnings §9: "o que pode ir ao ML fica fora da trava". Se `imagem` nula (bloqueio L1): 422 com a mensagem do `Problema` (`EditorRascunhoService::problemaParaTela`).
3. **Dentro da trava:** `DB::transaction(fn() => { $repo->travar($r); $atuais = $repo->snapshot($r)->imagens; ...; $repo->gravarAtribuicoes($r, [...$atuais, ['imagem'=>$img->id,'grupo'=>$grupo,'posicao'=>count($doGrupo)]]); $repo->tocar($r); })`. Travar PRIMEIRO e ler o snapshot DEPOIS, na mesma transação (learnings §9, WR-B02). No SQLite dos testes o `FOR UPDATE` não existe — testar a ORDEM, como `IaParaRascunhoTest`.
4. Marcar o slot: `status = aprovado`, `aprovado_por`, `aprovado_em`, `pub_imagem_id`; **não** preencher `ml_picture_id`/`ml_picture_url` (a foto ainda pode nem ter subido). `$kit->recalcularStatus()` não rebaixa `aprovado` do kit, e `aprovadas()` conta slots `aprovado` (`MlAnuncioCriativoKit.php:147-150`) — o contador "mínimo" continua válido.
5. Aprovar o **kit**: percorrer slots `pronto` em ordem de `slot_indice`, aplicar o passo 2-4 a cada um (uma transação por slot, para o ML não ficar preso na trava); fechar o kit (`status = aprovado`) e apagar a referência do portador só se nada falhou e o mínimo foi atingido — mesma regra de `MlbAnuncioController.php:2190-2211`.
6. Capacidade: **não truncar**. Antes de aprovar o kit, o servidor devolve `capacidade = {atual, maximo}` (de `schema.limites.max_pictures_per_item[_var]` via `EditorRascunhoService::estado()`), e a UI avisa "vai passar de N fotos" — a validação V-IMG-06/07 bloqueia a publicação e o operador remove à mão (D-04).
7. Remoção posterior pelo operador: `ImagemAssetService::remover` apaga o arquivo e a linha; `pub_imagem_id` vira NULL (FK `nullOnDelete`). Slot `aprovado` sem foto → a UI mostra "removida do anúncio"; permitir "adicionar de novo" é decisão aberta (Q3).

**Extração recomendada (código NOSSO, não do outro dev):** mover a lógica de `colocarNoGrupo` (hoje `private` no controller, `:353-362`) para `EditorRascunhoService::colocarFotoNoGrupo(PubRascunho, PubImagem, string)` com `travar` dentro de `DB::transaction`, e fazer o controller delegar a ela. Isso corrige de passagem a corrida do upload manual. Comportamento observável do `foto()` não muda (`MlbPublicadorTest`/`ImagensTest` provam).

## 5. Frontend (item 5)

**Como o painel antigo é ligado:** `AnunciarML.jsx:2439` monta `<PainelCriativosIa empresa rascunhoId ativo={creativeAtivo} onImagemAprovada />`; o painel é autossuficiente (estado, 2 pollings de 5 s via `setInterval`, teto de espera 14 min/27 min — `PainelCriativosIa.jsx:9,14,232,274,311`). Contrato JSON do kit: `{kit_token, status, etapa, em_andamento, erro, estrategia, minimo_aprovadas, prontas, aprovadas, referencias[], slots[{indice,tipo,rotulo,objetivo,status,etapa,erro,token,imagem_url,modelo,latencia_ms,regeneracoes,regeneracoes_restantes,ml_picture_url}]}` (`MlbAnuncioController.php:2051-2075`).

**Por que NÃO reaproveitar `PainelCriativosIa`/`KitCriativosGrade` literalmente:**
- ~10 `route('mlb.anuncios.criativo.*')` fixos; parametrizar exigiria editar o arquivo (D-05 permite "props novas", mas é a área da Fase 162).
- Classes fora da identidade: `text-sm`, `font-medium`, `text-[12px]`, `text-[10px]`, `bg-emerald-500` etc. — os gates de tipografia/peso só cobrem os arquivos listados em `CARDS`/`COM_ROTA`/`NORMALIZADOS` (`publicador-mesa.test.js:14-48`), então o painel antigo "passaria" por não estar na lista, mas destoaria da mesa.
- Recomendação: **componente novo `Mesa/PainelCriativos.jsx`** (apresentação pura, adicionado a `CARDS`), consumindo o MESMO contrato JSON, e **hook novo** com as rotas. O mockup de comportamento (confirmação de custo antes de gerar, "Agora não", motivo opcional ≤ 300 por cartão, botões desabilitados enquanto o kit está em andamento, teto de espera) é copiado do painel antigo — as correções do quick l8o precisam estar presentes, não reinventadas.
- Alternativa se o usuário preferir reuso literal: extrair `KitCriativosGrade` para `Components/Creative/` mantendo um re-export no caminho antigo — mas isso edita arquivo do outro dev e a 162 vai querer mexer nele; só valeria depois de combinar a ordem.

**Hook `useCriativosDoPublicador({ produtoId, onAprovou })`** (padrão de `useIaDoPublicador.js`: `axios`, `criarRota('mlb.anuncios.publicador','produto')` de `apoio.js:7`, `setInterval` com limpeza no unmount, `sessionStorage` por produto+grupo para retomar após F5):
- Estado: `alvo {grupo, titulo}`, `fase` (`parado | referencias | planejando | planejado | gerando | pronto | erro`), `kit`, `referenciasSugeridas`, `erros`, `processando`.
- Rotas (todas por `rota('criativos.<nome>', produtoId, {token|kit|indice})`): `criativos.atual` (GET, retoma kit ativo do grupo), `criativos.referencia` (POST), `criativos.kit.planejar`, `criativos.kit.gerar`, `criativos.kit.status`, `criativos.regenerar`, `criativos.aprovar` (slot), `criativos.kit.aprovar`, `criativos.imagem` e `criativos.referencia.ver` (GET binário).
- Ao aprovar: chamar `pub.recarregar()` (`usePublicador.js:591-595`: salva o pendente, espera, relê) — escolhido em vez de mesclar a resposta porque a aprovação é escrita de servidor fora da fila de `estruturar` (que não é exportada); o custo é uma leitura a mais. Alternativa: expor `estruturar`. Decisão do planejador.
- A mesa fica `disabled` enquanto `aguardando`/`relendo` (`usePublicador.js:615`); o painel só deve aparecer com `!m.disabled`.

**Onde plugar a UI (gates de texto da mesa continuam passando):**
- `FotosPorGrupo.jsx` `BlocoDeFotos` (`:70-109`): prop opcional `onGerarComIa` renderizando um botão de texto no cabeçalho do bloco (`font-bold`/`text-[11px]` já usados). `NORMALIZADOS` proíbe `text-xs/sm/...` e pesos fora de 400/700.
- `CardFotos.jsx:28`: `<BlocoDeFotos grupo={GERAL} ... onGerarComIa={...}>` — a regex `/<BlocoDeFotos grupo=\{GERAL\}/` (`publicador-mesa.test.js:~239`) continua casando.
- `CartaoVariante.jsx:69`: `<BlocoDeFotos grupo={grupo} ...>` idem (`:~257`).
- Quando `criativos.alvo?.grupo === grupo`, renderizar `<PainelCriativos c={criativos} />` logo abaixo do bloco. `Editor.jsx` instancia o hook ao lado de `useIaDoPublicador` (`:113-125`) e passa `criativos` por prop; flag de visibilidade vem do servidor (nova prop Inertia de `MlbPublicadorEntradaController::editor`, `:188-209`, = `ativa() && podeGerar($request->user())`, **sem** exigir `company_id` — a ponte atual exige `$companyId !== null`, `MlbPublicadorEntradaController.php:122`).
- Gates a respeitar: sem `route(` em `Mesa/*.jsx`; tipografia 24/15/13/11; pesos 400/700; `<select>` nativo (nada de Radix); nenhum `bg-ecf-yellow` sólido em card; o gradiente amarelo só em `botoes.jsx` (`publicador-mesa.test.js:70-76`) — o botão "Gerar agora" deve usar `BotaoAcao`/`SECUNDARIO` (não primário). Acrescentar `${BASE}/Mesa/PainelCriativos.jsx` à lista `CARDS` e os asserts de fonte do hook em `publicador-editor.test.js` (padrão das linhas 404-413 e 483-489).
- Componente não importado por nenhuma página NÃO é compilado pelo `npm run build` (learnings §9) — o `PainelCriativos` precisa ser importado em `CardFotos`/`CartaoVariante`/`Editor` antes de confiar no build.

## 6. Migration (item 6)

**Convenções lidas** (`database/migrations/2026_10_02_120000_*`, `2026_10_03_090000_*`, `..090100_*`, `..090200_*`, `..170000_*`):
- Aditiva em tabela existente: `if (Schema::hasColumn(tabela, coluna)) return;` no `up()` e `if (! hasColumn) return;` no `down()` (molde de `..090100`).
- FK anulável com `nullOnDelete()` e `->nullable()` EXPLÍCITO (erro 1830 do MariaDB).
- Índices NOMEADOS à mão e curtos (≤ 64 chars, erro 1059); `KitMigrationGuardaTest` (`tests/Unit/Phase161/KitMigrationGuardaTest.php`) é um guarda por grep de conteúdo — **criar um guarda equivalente** para a migration nova (mesmo padrão: sem `->enum(`, nomes de índice ≤ 64, `nullOnDelete` sempre com `nullable`).
- Sem DEFAULT em JSON/TEXT; `string(..)->nullable()` serve; `unsignedTinyInteger->default(0)` só para contadores.
- `down()`: dropar o índice ANTES de `dropConstrainedForeignId` (`..090100`, bloco do `down`); learnings §6 (1553: não dropar índice usado por FK).
- Tratar o driver `mariadb` como `mysql` se houver branch por driver (WR-B06 — `MigracoesDaFaseDetectamMariaDbTest.php`); esta migration não deve precisar de branch.

**Desenho de schema (por escrito, antes de existir — CLAUDE.md "decisão de schema"):**

| Tabela | Coluna | Tipo | Notas |
|---|---|---|---|
| `ml_anuncio_criativos` | `pub_rascunho_id` | `foreignId` anulável → `pub_rascunhos`, `nullOnDelete` | índice simples nomeado `ml_criativos_pubrasc_idx` |
| `ml_anuncio_criativos` | `pub_grupo` | `string(600)` anulável | mesmo tamanho de `pub_imagem_atribuicoes.grupo_chave` (`2026_10_01_200000_create_publicador_tables.php:196`); **sem índice** (600 chars estoura o limite de chave de 3072 bytes do utf8mb4 em InnoDB — não indexar) |
| `ml_anuncio_criativos` | `pub_imagem_id` | `foreignId` anulável → `pub_imagens`, `nullOnDelete` | índice `ml_criativos_pubimg_idx` |
| `ml_anuncio_criativo_kits` | `pub_rascunho_id` | idem, índice `ml_criativo_kits_pubrasc_idx` (nome ≤ 64) | usado na consulta "kit ativo do rascunho" |
| `ml_anuncio_criativo_kits` | `pub_grupo` | `string(600)` anulável | sem índice; a consulta filtra por `pub_rascunho_id` (indexado) e compara `pub_grupo` em PHP/`where` sobre poucas linhas |

Notas de risco:
- As duas tabelas têm dado em produção (≥ 1 criativo e kits de uso real desde 03/10): nenhuma coluna NOT NULL sem default; nenhuma reescrita de linha → `ALTER` rápido e seguro.
- A FK precisa que `pub_rascunhos.id` e `pub_imagens.id` sejam `bigint unsigned` (`foreignId`) — conferir em `2026_10_01_200000_create_publicador_tables.php` antes (o mesmo vale para o tipo de `produto_id` em `..100100`). `[ASSUMED]` — não abri a definição de `pub_imagens.id`; a migration deve falhar cedo no teste de migrate se divergir.
- Ordem: a migration depende de `pub_rascunhos` e `pub_imagens` já existirem (datas `2026_10_01_200000` < nova). Dar à migration timestamp posterior a `2026_10_03_170000`.
- `company_id` das tabelas de criativo é `cascadeOnDelete` (criativos) / `nullOnDelete` (kits): apagar `Company`/`MlbEmpresa` apaga criativos mas o arquivo em `creative-geradas/` fica órfão; igual ao antigo, fora de escopo (registrar no learnings se virar problema).
- Teste da migration usa `->change()`? Não — apenas `Schema::table add`. Pode rodar com `RefreshDatabase` no SQLite (learnings §9 só proíbe `->change()` no rollback de outra migration).

## 7. Autorização e chaves (item 7)

- **Ordem dos checks (copiar a do Creative Engine):** (1) `abort_unless(CreativeEngineAtivo::ativa(), 404)`; (2) produto autorizado: `PubProduto::findOrFail($id)` + `abort_if(ProgramasPublicadorService::empresaDoProduto($p) === null, 404)` (`MlbPublicadorController.php:340-346`) — então `PubRascunho::where('produto_id', ...)->firstOrFail()`; (3) o token/kit pertence a esse rascunho (`$criativo->pub_rascunho_id === $r->id`, senão 404 — IDOR); (4) `CreativePermissao::exigir($user, 'planejar'|'gerar'|'regenerar'|'aprovar')` DEPOIS do escopo para não distinguir 403 de 404 (comentário em `MlbAnuncioController.php:1738,1847`).
- **Company obrigatório?** O Creative Engine **não exige `company_id`**: `ml_anuncio_criativos.company_id` e `mlb_empresa_id` são anuláveis (`create_ml_anuncio_criativos_table.php`), `CreativeContext::loja` é `?string` e `paraCriativo` usa `company?->nomeContaMl()` (nulo-seguro). No Publicador copiar `company_id`/`mlb_empresa_id` do `PubProduto` (derivados do produto, nunca do corpo — mesma disciplina T-160-01) e obter a loja via `$produto->contaOuNula()`. Produto de polo SEM `Company` funciona; a ponte atual só o exclui por causa do wizard antigo (`MlbPublicadorEntradaController.php:122`).
- **`CreativePermissao`** (`CreativePermissao.php:91-104`): admin passa por `hasPermission(MLB_CRIATIVOS_IA)` (curto-circuito) e, se `creative_engine_usuarios` estiver preenchida em `configuracoes`, só os ids listados gastam cota. O botão da UI deve usar `podeGerar`; as ações `planejar/regenerar/aprovar` seguem a regra no servidor.
- **Rate limiters (quick 261003-l8o)** definidos em `AppServiceProvider.php:191,203,214`, por usuário: `creative-kit-planejar` 12/min, `creative-kit-gerar` 4/min, `creative-regenerar` 12/min, com resposta 429 em pt-BR (`respostaLimiteCriativo`). Reusar **pelo nome** nas rotas novas (`->middleware('throttle:creative-kit-planejar')`) — compartilha o balde com o fluxo antigo (conservador; custo é do mesmo bolso). Para as novas sem equivalente: upload de referência `throttle:20,1,publicador.criativos.referencia` (mesmo número do antigo, `routes/mlb_anuncios.php:204`), aprovar `throttle:30,1,publicador.criativos.aprovar`, polling `throttle:240,1,publicador.criativos.status` (5 s = 12/min por aba; 240 acomoda aberturas simultâneas, mesmo critério de `fotos.arquivo` = 240).
- **Binários (referência e imagem gerada):** `Cache-Control: private, no-store` e leitura do disco `local` por token (nunca URL pública), como em `MlbAnuncioController.php:1359-1362,1533-1536`; no Publicador, escopar pelo produto.
- **Os endpoints antigos continuam acessíveis a um admin com um token do Publicador** (a rota antiga não filtra). Por serem tokens de 32 caracteres aleatórios e o grupo ser `role:admin`, o risco é baixo; ainda assim **vale um teste de regressão** que prove que `criativoKitPlanejar` antigo com token do Publicador não devolve kit alheio — ou decidir com o outro dev colocar `abort_if($criativo->pub_rascunho_id !== null, 404)` nos métodos antigos (1 linha cada, ainda "só acrescenta" mas toca o controller da 162). Registrado como Q4.

## 8. Testes a proteger (item 8) — baseline medido

Executado nesta pesquisa, no worktree, em SQLite (sem modificar fonte):

| Suíte | Comando | Resultado (04/10/2026) |
|---|---|---|
| Creative Engine (antigo) | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase160 tests/Unit/Phase161 tests/Unit/Quick261003L8o tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o` | **OK — 164 testes, 738 asserções** (1 deprecation de PHPUnit, não da suíte) |
| Publicador | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Publicador tests/Unit/Publicador` | **OK — 394 testes, 1995 asserções** |
| JS (mesa + editor + assistente antigo) | `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js tests/js/estrutura-anunciar-ml.test.js` | **OK — 169 testes, 0 falhas** |

Saídas completas em `C:\Users\User\AppData\Local\Temp\claude\c--xampp-htdocs-ecf-admin\ba26febf-5908-43f6-b70a-0294e543ecfc\scratchpad\base-creative.txt` e `base-pub.txt`.

Testes que PROVAM o caminho antigo intacto e que cada plano da 165 deve rodar: `tests/Feature/Phase160/{CriativoReferenciaTest,CriativoGeracaoTest,CriativoGeracaoEndpointsTest,CriativoAprovacaoTest,CriativoRetencaoTest}`, `tests/Feature/Phase161/{CriativoKitPlanejamentoTest,CriativoKitGeracaoTest,CriativoKitAprovacaoTest,CriativoRegeneracaoTest,CriativoPermissaoTest,CriativoKitPublicacaoGateTest}`, `tests/Feature/Quick261003L8o/*`, `tests/Unit/Phase160/{ProductTruthBuilderTest,CreativeSegredoLogTest}`, `tests/Unit/Phase161/{CreativePlannerTest,CreativePromptBuilderSlotTest,KitMigrationGuardaTest}`, `tests/Unit/Quick261003L8o/CreativePromptBuilderVariacaoTest`. (Não existe diretório `tests/Unit/Creative*` — a suíte unitária do Creative Engine mora em `Phase160`/`Phase161`/`Quick261003L8o`.) Os testes que constroem `CreativeContext` direto (`ProductTruthBuilderTest.php:34`, `CreativePlannerTest.php:28`) provam que o parâmetro novo precisa ser OPCIONAL e AO FINAL.

Padrões a reaproveitar nos testes novos: `Configuracao::set('creative_engine_ativo','1')`, `Http::preventStrayRequests()`, dublê de `ImageGenerationProvider` (`CriativoKitPlanejamentoTest.php:42-65`), `QUEUE_CONNECTION=sync` no phpunit (rodar a rota executa o job); `CenarioCadeira` (`tests/Feature/Publicador/Concerns/CenarioCadeira.php`) e `ImagensTest` (cria rascunho/produto/token, `Storage::fake('local')`, config `publicador.contas_liberadas`) para o lado Publicador. Armadilhas: suíte inteira estoura 512 MB — rodar por pasta e redirecionar para arquivo (`| tail` engole exit code); `Http::fake` acumula (primeiro stub vence); `UploadedFile::fake()->image()` precisa de variável.

## 9. Riscos e questões abertas — coordenação com a Fase 162 (item 9)

**O que a Fase 162 (validador Gemini-juiz + regeneração automática) provavelmente toca** `[ASSUMED]` — inferência a partir de REQUIREMENTS-v24 (VAL) e do desenho atual, não confirmada com o outro dev:
- `GerarCriativoIaJob.php` — chamar o juiz depois de salvar a imagem e/ou regenerar automaticamente (linha ~217-234).
- `MlAnuncioCriativo.php` / `MlAnuncioCriativoKit.php` e uma migration nova — colunas de veredito/validação (`$fillable`, `$casts`).
- `ProductTruthBuilder`/`CreativeContextBuilder` — o juiz precisa de Truth + fotos originais; pode ler o contexto pelo builder.
- `MlbAnuncioController::criativoKitStatus` (payload com veredito por slot) e `KitCriativosGrade.jsx` (exibir veredito), `PainelCriativosIa.jsx`.
- `MlAnuncioCriativoKit::recalcularStatus()` / `CreativeKitDespachante` — se a regeneração automática mudar o ciclo de estados.
- `ReferenciaEfemeraService::apagar` — se o juiz precisar da referência depois da aprovação, a regra "apagar na aprovação do kit" muda.

**Arquivos que a 165 toca e podem colidir:** `CreativeContextBuilder.php` (ramo no início do método; conflito de merge baixo se a 162 só ler o contexto), `CreativeContext.php` (parâmetro no fim), os dois models (`$fillable` e relações — conflito textual provável, trivial), `routes/mlb_anuncios.php` (bloco novo dentro do grupo do Publicador, longe do bloco `criativo.*`). **Não toca** `GerarCriativoIaJob`, `PlanejarKitCriativosJob`, `MlbAnuncioController`, `PainelCriativosIa`, `KitCriativosGrade`, `AnunciarML`, `CreativeKitPublicacao`, `ProductTruthBuilder`, `ReferenciaEfemeraService`, `CreativeKitDespachante`.

**Consequência a levar ao outro dev:** o veredito da 162 aparece no `criativoKitStatus` antigo; o `PublicadorCriativoKitPresenter` é uma cópia do array de slots. Se a 162 acrescentar campos ao status, o presenter do Publicador precisa acompanhar — por isso vale combinar que a 162 extraia o "montar slots do kit" para uma classe de `app/Services/Creative/` que os dois controllers usem (a 165 migraria o presenter depois). Se a ordem for 162 → 165, a 165 já nasce usando essa classe; se for 165 → 162, a 162 herda dois pontos de payload.

**Questões abertas (RESOLVED — todas fechadas no 165-CONTEXT.md em 04/10; ponteiros abaixo):**
1. **(RESOLVED → checkpoint de coordenação, 165-01 Task 1) Q1 — Ordem 162 × 165 e uso do ramo no builder** (bloqueia execução, não planejamento). *Sabemos:* a 165 só precisa do ramo no builder e da chave/permissão. *Falta:* resposta do outro dev. *Recomendação:* planejar com a lista de arquivos por plano (feito) e deixar o plano de migration + builder como o ÚNICO que toca `app/Services/Creative` + models, para a conversa ser sobre um arquivo.
2. **(RESOLVED → D-11) Q2 — Semântica de "aprovado" para criativo do Publicador.** `aprovado` hoje significa "subiu ao ML". Recomendação: manter o literal `aprovado` com `pub_imagem_id` preenchido e `ml_picture_*` nulos, e documentar no docblock; a 162 não deve inferir "no ML" a partir de `aprovado`.
3. **(RESOLVED → D-12) Q3 — Slot aprovado cuja foto foi removida do rascunho.** Permitir "adicionar de novo" (reabre a aprovação quando `pub_imagem_id` for NULL) ou exigir regenerar? Recomendação: permitir re-adicionar (a imagem existe em disco; evita pagar de novo).
4. **(RESOLVED → D-13; o lado da 165 endereça o kit por id numérico sem expor token — planos 165-04/05 — e a guarda de 1 linha vai como pergunta ao outro dev no checkpoint do 165-01) Q4 — Guarda nos endpoints antigos.** Aceitar o risco baixo (token aleatório + `role:admin`) com teste de regressão, ou pedir ao outro dev o `abort_if` de 1 linha nos métodos antigos? Recomendação: teste primeiro; o `abort_if` só se o outro dev concordar.
5. **(RESOLVED → D-08) Q5 — Painel novo × reuso literal.** Recomendado o nativo da mesa; depende do usuário aceitar ~250 linhas de JSX novo a troco de zero acoplamento com a 162 e gates de identidade verdes.
6. **(RESOLVED → D-14) Q6 — Qual grupo/limite quando o kit é pedido numa variação de eixo único sem `defines_picture`.** Sem eixo que defina foto, a tela liga `fotos_por_variante` e cada combinação vira grupo (`ResolvedorGruposImagem.php:47`). O grupo é a CHAVE da variante — o contexto da variação então usa todos os valores dela.
7. **(RESOLVED → D-15) Q7 — Kit anterior ainda `pronto` com slots não aprovados** quando o operador abre o painel de novo: o endpoint `criativos.atual` deve devolvê-lo (retomada) em vez de criar outro (que custaria de novo). Confirmar com o usuário se "descartar kit e começar de novo" precisa existir nesta fase (hoje o antigo também não tem).
8. **(RESOLVED → D-16) Q8 — `Company`/`MlbEmpresa` apagadas** apagam criativos por cascade enquanto o `pub_produto` é preservado (SET NULL, learnings §9). Aceitável; anotar.

## Não reinventar (Don't Hand-Roll)

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Guardar/validar foto no rascunho | cópia manual em `pub_imagens` | `ImagemAssetService::receber` | L1 (JPG/PNG, ≤ 10 MB, ≥ 500 px), sha256, disco privado, D26 |
| Atribuir foto a grupo | `INSERT` em `pub_imagem_atribuicoes` | `RascunhoRepository::gravarAtribuicoes` + `tocar` | a lista é substituída por inteiro; `tocar` invalida a validação |
| Resolver grupo de variação | parse de string do grupo | `ResolvedorGruposImagem::chaveDoGrupo/resolver` | RN-61/66/63 |
| Título/preço efetivos | ler `alvos.titulo` direto | `RascunhoSnapshot::comEfetivos` + `DadosEfetivosService::daProduto` | título herdado da aba Anúncios |
| Trava de escrita no rascunho | lock próprio | `RascunhoRepository::travar` dentro de `DB::transaction` | WR-B02 |
| Escopo por produto | verificação nova | `ProgramasPublicadorService::empresaDoProduto` | 404 consistente com o editor |
| Chave liga/desliga e permissão | flag nova | `CreativeEngineAtivo` + `CreativePermissao` | OPS-03/OPS-04 |
| Limitar taxa | `throttle:N,1` ad hoc para gerar | limitadores nomeados `creative-*` | mensagens em pt-BR e números calibrados |
| Planejar/gerar/regenerar | lógica nova | `PlanejarKitCriativosJob`, `CreativeKitDespachante`, `GerarCriativoIaJob`, `MlAnuncioCriativoKit::podeRegenerarAsset/tetoDeImagensAtingido` | tetos de custo já testados |
| Apagar referência | `Storage::delete` solto | `ReferenciaEfemeraService::apagar` | apaga o diretório do token e marca `referencias_apagadas_em` |
| Taxa de erros do ML na foto | tratar resposta do ML | `ImagemAssetService::enviarAoMl`/`enviarPendentes` | já mapeia 3703, rate-limit |

**Insight-chave:** o Creative Engine já foi desenhado com a fronteira certa (CTX-02) — o conteúdo do rascunho só entra por UM método. O trabalho da 165 é dar a esse método uma segunda fonte e substituir o "lado de saída" (aprovação), não reescrever o motor.

## Armadilhas comuns

### Armadilha 1: escopo "vazio" com `rascunho = NULL`
**O que dá errado:** usar as rotas antigas (ou copiar `checarEscopoDoRascunho`) com kit do Publicador — a função retorna sem checar quando o rascunho é nulo (`MlbAnuncioController.php:1385-1387`), então qualquer admin vê/aprova qualquer kit.
**Como evitar:** toda rota nova resolve `produto → rascunho` e compara `kit/criativo.pub_rascunho_id === rascunho.id`; 404 se diferente. Teste IDOR dedicado.
**Sinal de alerta:** controller novo sem `abort_unless($x->pub_rascunho_id === $r->id, 404)`.

### Armadilha 2: `where('rascunho_id', null)` virar `whereNull`
**O que dá errado:** copiar `planejarKitSobLock` com a chave `rascunho_id` — para Publicador casa kits de OUTROS produtos (todos têm `rascunho_id` nulo).
**Como evitar:** consultar por `pub_rascunho_id` **e** `pub_grupo`; lock `Cache::lock("criativo-kit-planejar-pub:{$r->id}:".md5($grupo), 5)`.

### Armadilha 3: portador sem `kit_id` no momento do planejamento
**O que dá errado:** `paraCriativo($portador)` roda antes de `$portador->update(['kit_id' => ...])` (`PlanejarKitCriativosJob.php:111` vs `:156`); se o builder só olhar `$criativo->kit?->pub_rascunho_id`, o planejamento cai no caminho antigo e falha com "O rascunho deste criativo não existe mais".
**Como evitar:** o portador carrega o PRÓPRIO `pub_rascunho_id`/`pub_grupo` na criação (cadeia `criativo → kit → portador`). Teste: planejar kit do Publicador por `QUEUE_CONNECTION=sync` e verificar `kit.status = planejado`.

### Armadilha 4: corrida na atribuição de fotos
**O que dá errado:** duas aprovações (ou aprovação + arrasto da mesa) leem `snapshot->imagens` e regravam a lista inteira — a última vence e atribuições somem.
**Como evitar:** `travar` + snapshot na MESMA transação; HTTP ao ML (`receber`) fora da trava.

### Armadilha 5: truncar em silêncio
**O que dá errado:** aprovar 7 imagens num grupo de 8 de 10 corta 5.
**Como evitar:** aprovar tudo e deixar V-IMG-06/07 bloquear; a UI avisa a capacidade antes (D-04).

### Armadilha 6: `pub_grupo` indexado
**O que dá errado:** `string(600)` em índice estoura a chave do InnoDB (utf8mb4) no MariaDB; no SQLite passa. **Como evitar:** sem índice em `pub_grupo`.

### Armadilha 7: componente novo "órfão" no build
**O que dá errado:** `PainelCriativos.jsx` que nenhuma página importa não é compilado; import quebrado só aparece depois (learnings §9). **Como evitar:** importar nos cards/Editor antes de fechar o plano; conferir o manifest; em worktree novo `npm run build` pode sair 0 sem buildar.

### Armadilha 8: `Http::fake` acumula / job síncrono no teste
**O que dá errado:** stub que muda no meio. **Como evitar:** mudar o ESTADO, não o fake (learnings §5).

### Armadilha 9: ponte antiga visível junto
**O que dá errado:** `criativos_ia.url` (ponte da Fase 164, `MlbPublicadorEntradaController.php:118-123`) continua aparecendo. **Como evitar:** manter (D: só remover depois da 165 em uso); o botão novo e a ponte coexistem.

## Exemplos de código (formas, não implementação final)

### Rotas novas (dentro do grupo `publicador/produtos/{produto}`, `routes/mlb_anuncios.php:50-90`)
```php
// Fase 165 — Creative Engine por produto do Publicador (D-03). Nomes: mlb.anuncios.publicador.criativos.*
Route::prefix('criativos')->name('criativos.')->group(function () {
    Route::get('/', [MlbPublicadorCriativoController::class, 'atual'])->middleware('throttle:240,1,publicador.criativos.status')->name('atual');
    Route::post('/referencias', [MlbPublicadorCriativoController::class, 'referencias'])->middleware('throttle:20,1,publicador.criativos.referencia')->name('referencia');
    Route::get('/{token}/referencia/{indice}', [...,'referenciaVer'])->where('token','[A-Za-z0-9]{32}')->whereNumber('indice')->name('referencia.ver');
    Route::get('/{token}/imagem', [...,'imagem'])->where('token','[A-Za-z0-9]{32}')->name('imagem');
    Route::post('/{token}/kit', [...,'planejar'])->where('token','[A-Za-z0-9]{32}')->middleware('throttle:creative-kit-planejar')->name('kit.planejar');
    Route::post('/kit/{kit}/gerar', [...,'gerar'])->where('kit','[A-Za-z0-9]{32}')->middleware('throttle:creative-kit-gerar')->name('kit.gerar');
    Route::get('/kit/{kit}', [...,'status'])->where('kit','[A-Za-z0-9]{32}')->middleware('throttle:240,1,publicador.criativos.status')->name('kit.status');
    Route::post('/{token}/regenerar', [...,'regenerar'])->where('token','[A-Za-z0-9]{32}')->middleware('throttle:creative-regenerar')->name('regenerar');
    Route::post('/{token}/aprovar', [...,'aprovar'])->where('token','[A-Za-z0-9]{32}')->middleware('throttle:30,1,publicador.criativos.aprovar')->name('aprovar');
    Route::post('/kit/{kit}/aprovar', [...,'aprovarKit'])->where('kit','[A-Za-z0-9]{32}')->middleware('throttle:30,1,publicador.criativos.aprovar')->name('kit.aprovar');
});
```
Cuidado de ordem: rotas literais `kit/{kit}` antes de `{token}` genérico (o `where` de 32 chars alfanuméricos já impede "kit" de casar como token, mas manter literais primeiro por clareza).

### Ramo do builder — resolução do grupo e do foco
```php
// Source: composição de ResolvedorGruposImagem::chaveDoGrupo (ResolvedorGruposImagem.php:32-50) + RascunhoSnapshot
$eixos = Eixo::ordenar($snapshot->eixos);
foreach ($snapshot->variantesAtivas() as $v) {
    if (ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $snapshot->fotosPorVariante) !== $grupo) continue;
    foreach ($eixos as $e) {
        if (($e->definesPicture || $snapshot->fotosPorVariante) && isset($v->valores[$e->chave]) && $e->chave !== ChaveCanonica::EIXO_CUSTOM) {
            $atributos[$e->chave] = $v->valores[$e->chave]->valueName;   // COLOR => 'Azul'
        }
    }
}
```

### Gravar no fim do grupo, sob a trava (aprovação)
```php
// Source: padrão de EditorRascunhoService::salvar (travar primeiro) + MlbPublicadorController::colocarNoGrupo
$res = $this->imagens->receber($r, $bytes, "criativo-{$slot->token}.jpg");   // fora da trava (pode falar com o ML)
DB::transaction(function () use ($r, $res, $grupo) {
    $this->repo->travar($r);
    $atuais  = $this->repo->snapshot($r)->imagens;
    $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
    if (! in_array((string) $res['imagem']->id, array_column($doGrupo, 'imagem'), true)) {
        $this->repo->gravarAtribuicoes($r, [...$atuais, ['imagem' => $res['imagem']->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)]]);
    }
    $this->repo->tocar($r);
});
```

### Hook (padrão `useIaDoPublicador`)
```js
// Source: resources/js/Components/Publicador/useIaDoPublicador.js (polling com setInterval + limpeza + sessionStorage)
const rota = criarRota('mlb.anuncios.publicador', 'produto');
const { data } = await axios.post(rota('criativos.kit.planejar', produtoId, { token }));
```

## Estado da arte

| Antigo | Atual | Quando | Impacto |
|--------|-------|--------|---------|
| 1 imagem por rascunho (hero) | Kit de 7 com planejamento e confirmação de custo | Fase 161 / quick l8o (02-03/10) | O fluxo de 1 imagem foi removido da UI; a 165 só expõe o kit |
| `payload.pictures` do assistente antigo | `pub_imagens` + `pub_imagem_atribuicoes` | Fase 164 | A aprovação do Publicador não toca `payload` |
| Painel único `route()` hardcoded | Hooks + mesa pura (gates `publicador-mesa.test.js`) | Conceito E (03/10) | Rotas só em hooks |

**Obsoleto/aposentado:** o botão "Gerar imagem com IA" de 1 slot (quick l8o correção 1); `CreativeKitPublicacao` continua valendo SÓ para o assistente antigo.

## Log de Suposições

| # | Afirmação | Seção | Risco se errada |
|---|-----------|-------|-----------------|
| A1 | A Fase 162 tocará `GerarCriativoIaJob`, models, `criativoKitStatus`, `KitCriativosGrade` e talvez `ReferenciaEfemeraService::apagar` | §9 | A lista de colisão muda; as recomendações de desenho não |
| A2 | `new UploadedFile($pathReal, $nome, $mime, null, true)` + `ReferenciaEfemeraService::guardar()` funciona para copiar `pub_imagens` (leitura via `getRealPath`/`sha1_file`) | §3 | Se não funcionar, adicionar método `guardarBytes()` ao serviço (toca arquivo do outro dev) ou gravar o meta no adaptador |
| A3 | Imagem Gemini 2K JPEG fica < 10 MB e passa em V-IMG-01..03 | §4 | Aprovação devolveria 422 com a mensagem do validador; sem perda de dado |
| A4 | `pub_rascunhos.id` e `pub_imagens.id` são `bigint unsigned` (compatíveis com `foreignId`) | §6 | Migration falha cedo no teste; corrigir o tipo |
| A5 | Admin passa sempre em `CreativePermissao` hoje (curto-circuito de `hasPermission`) e a lista `creative_engine_usuarios` está vazia em produção | §7 | Se a lista estiver preenchida, só os ids dela veem o botão — comportamento correto, só a expectativa do usuário muda |
| A6 | Em produção a chave `creative_engine_ativo` segue LIGADA (CONTEXT: "estava LIGADO em 03/10") | §7 | Sem a chave, a UI nova não aparece; nada quebra |

## Questões abertas (RESOLVED)

Todas as questões do §9 (Q1–Q8) foram fechadas no `165-CONTEXT.md` em 04/10: Q1 → checkpoint de coordenação do plano 165-01 (Task 1); Q2 → D-11; Q3 → D-12; Q4 → D-13 (kit por id numérico, sem token no navegador, e a guarda das rotas antigas como pergunta ao outro dev); Q5 → D-08; Q6 → D-14; Q7 → D-15; Q8 → D-16.

## Disponibilidade de ambiente

| Dependência | Necessária para | Disponível | Versão | Alternativa |
|-------------|-----------------|-----------|--------|-------------|
| PHP | testes | ✓ | `C:\xampp\php\php.exe` | — |
| PHPUnit | testes | ✓ | `vendor/bin/phpunit` (baseline rodou) | — |
| Node | gates JS | ✓ | `node --test` rodou 169 testes | — |
| SQLite | testes/conferência visual isolada | ✓ | via `phpunit.xml` | — |
| Gemini (API paga) | E2E real | ✗ (não executado) | — | `ImageGenerationProvider` dublê nos testes; E2E real só na conta #459 com confirmação do usuário |
| MariaDB local | validação das 3 armadilhas | compartilhado — NÃO semear | — | Guarda de conteúdo por grep (padrão `KitMigrationGuardaTest`) |

**Sem bloqueios de ambiente.** Nota de ambiente: o Bash corrompe barras invertidas em `sed` (learnings §9) — editar PHP/JS só com Edit/Write e checar com `php -l`.

## Arquitetura de Validação

### Framework de teste
| Propriedade | Valor |
|-------------|-------|
| Framework | PHPUnit 11.x (PHP) + `node --test` (gates JS de fonte) |
| Config | `phpunit.xml` (SQLite, `QUEUE_CONNECTION=sync`); JS: `tests/js/*.test.js` |
| Comando rápido | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase165 tests/Unit/Phase165` + `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js` |
| Comando completo | os 3 comandos da tabela do §8 (rodar por pasta, saída para arquivo) |

### Mapa Requisito → Teste
| Req | Comportamento | Tipo | Comando automatizado | Existe? |
|-----|---------------|------|----------------------|---------|
| CE165-01 | Migration idempotente, sem enum, índices ≤ 64, `nullOnDelete` com `nullable`; roda 2× sem erro; linha antiga intacta | unit (guarda por grep) + feature (migrate) | `phpunit tests/Unit/Phase165/PubColunasMigrationGuardaTest.php` | ❌ Wave 0 |
| CE165-02 | `paraCriativo` com `pub_rascunho_id` devolve contexto do Publicador; sem ele, saída IDÊNTICA à de hoje (comparar `paraAuditoria()` e a exceção "rascunho não existe mais") | unit/feature | `phpunit tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` + `tests/Feature/Phase160/CriativoGeracaoTest.php` | ❌ Wave 0 / ✅ existente |
| CE165-03 | título efetivo, atributos só com `value_name`, variações ativas, loja de `MlbEmpresa` sem `Company` | feature | idem (cenário `CenarioCadeira` com `MlbEmpresa`) | ❌ Wave 0 |
| CE165-04 | grupo `COLOR=id:…` injeta `COLOR` nos fatos e some a claim "não há cor"; `GENERAL` não injeta | unit | `phpunit tests/Unit/Phase165/ContextoDaVariacaoTest.php` | ❌ Wave 0 |
| CE165-05 | copia `pub_imagens` com arquivo para `creative-referencias/{token}/`; ignora `caminho` nulo; recusa imagem de outro rascunho; teto 14 | feature | `phpunit tests/Feature/Phase165/ReferenciaDoPublicadorTest.php` | ❌ Wave 0 |
| CE165-06 | chave desligada → 404; produto fora do escopo → 404; token de outro rascunho → 404; sem permissão → 403 (depois do escopo); fluxo planejar → gerar → status com `QUEUE_CONNECTION=sync` e provider dublê | feature | `phpunit tests/Feature/Phase165/CriativosEndpointsTest.php` | ❌ Wave 0 |
| CE165-07 | duplo clique de planejar devolve o mesmo kit; kits de grupos diferentes coexistem; kit de outro produto nunca é devolvido; `atual` retoma | feature | `phpunit tests/Feature/Phase165/KitIdempotenciaTest.php` | ❌ Wave 0 |
| CE165-08 | aprovar cria `PubImagem` + atribuição no FIM do grupo; dedupe por sha; conta não liberada = `pending` e sem HTTP; rascunho PUBLISHING → 422; não grava `payload` nem chama `MlImagemService`; ordem `travar` antes do snapshot; não trunca | feature | `phpunit tests/Feature/Phase165/AprovacaoParaPubImagensTest.php` | ❌ Wave 0 |
| CE165-09 | aprovar o kit inteiro apaga o diretório de referência e marca `referencias_apagadas_em`; `creative:limpar-referencias` recolhe criativo do Publicador de 49h | feature | `phpunit tests/Feature/Phase165/RetencaoDoPublicadorTest.php` | ❌ Wave 0 |
| CE165-10 | gates de fonte: `PainelCriativos.jsx` em `CARDS` (sem `route(`, 24/15/13/11, 400/700, sem amarelo sólido); hook usa `criarRota('mlb.anuncios.publicador','produto')` e `setInterval` com limpeza; `CardFotos`/`CartaoVariante` passam `onGerarComIa`; `BlocoDeFotos` com a prop | js | `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js` | ✅ (ampliar) |
| CE165-11 | rotas novas usam os limitadores nomeados; 429 em pt-BR; confirmação de custo exibida antes de `kit/gerar` | feature + js | `phpunit tests/Feature/Phase165/LimitesDoPublicadorTest.php` (padrão `tests/Feature/Quick261003L8o/LimiteDeCustoEmPtBrTest.php`) | ❌ Wave 0 |
| CE165-12 | suítes antigas verdes sem alteração + `tests/js/estrutura-anunciar-ml.test.js` | regressão | comandos do §8 | ✅ |

### Taxa de amostragem
- **Por commit de tarefa:** suíte nova da fase + a suíte do arquivo tocado + `node --test` dos dois arquivos JS da mesa.
- **Por merge de onda:** Creative Engine antigo (164 testes) + Publicador (394) + JS (169) — todos devem continuar com os mesmos números ou maiores.
- **Gate da fase:** tudo verde + conferência visual (abaixo) antes do `/gsd:verify-work`.

### Conferência visual (learnings §7)
Receita para ver a mesa sem tocar no MariaDB compartilhado: `DB_CONNECTION=sqlite DB_DATABASE=<arquivo> SESSION_DRIVER=file ... php artisan migrate --force` (migrations rodam em SQLite); semear com PHP avulso que dá `bootstrap()` e se recusa a rodar se `database.default` não for `sqlite`; servir com `php -S` **de dentro de `public/`** usando o `server.php` do framework; `node_modules` por junção de outro worktree com `package-lock.json` idêntico (conferir com `diff`); `Configuracao::set('creative_engine_ativo','1')` e provider dublê para ver kit planejado/gerado sem custo. O login local é por sessão de admin (memória: `admin@ecfconsultoria.com.br`). Itens a olhar: botão "Gerar com IA" no item Fotos e na variação; painel abre sob o bloco certo; confirmação de custo em dólar; estados `planejando/planejado/gerando/pronto/erro`; foto aprovada aparece no bloco com a contagem `n/max`; aviso de capacidade; Inspetor continua com o único amarelo. E2E com custo real só na conta #459 e com confirmação do usuário.

### Lacunas da Wave 0
- [ ] `tests/Feature/Phase165/*` e `tests/Unit/Phase165/*` (lista acima) — a pasta ainda não existe.
- [ ] Guarda de migration por conteúdo para a migration nova (molde `KitMigrationGuardaTest`).
- [ ] Ampliar `tests/js/publicador-mesa.test.js` (`CARDS` + asserts de `CardFotos`/`CartaoVariante`/`BlocoDeFotos`) e `tests/js/publicador-editor.test.js` (hook).
- [ ] Teste de regressão do risco do §7 (rota antiga com token do Publicador não devolve kit alheio).

## Domínio de Segurança

### Categorias ASVS aplicáveis
| Categoria | Aplica | Controle padrão |
|-----------|--------|-----------------|
| V2 Autenticação | não (sessão do admin existente) | — |
| V3 Sessão | não | — |
| V4 Controle de acesso | **sim** | grupo `role:admin` + `empresaDoProduto` (404) + amarração token↔rascunho + `CreativePermissao` depois do escopo |
| V5 Validação de entrada | **sim** | `Request::validate` (grupo ≤ 600 e conferido contra `ResolvedorGruposImagem::resolver(...)->grupos` ou `GENERAL`; `motivo` ≤ 300; `imagens[]` inteiros do próprio rascunho; `referencias.*` `image|max:10240`) |
| V6 Criptografia | não | — |
| V8 Proteção de dados | **sim** | disco `local` privado; `Cache-Control: private, no-store`; log sem prompt/base64/bytes (GEN-05) |
| V12 Arquivos | **sim** | validação de imagem (`ValidadorImagem`), nome do cliente nunca no caminho (T-160-03) |
| V13/API (abuso/custo) | **sim** | limitadores nomeados, tetos do kit, confirmação de custo |

### Padrões de ameaça conhecidos
| Padrão | STRIDE | Mitigação padrão |
|--------|--------|------------------|
| IDOR: token de kit/criativo de outro produto | Elevação / Divulgação | comparar `pub_rascunho_id` do registro com o do produto autorizado; 404 |
| Gasto de cota por clique repetido ou script | DoS financeiro | `throttle` nomeado + `ShouldBeUnique` dos jobs + tetos `max_imagens`/regenerações |
| Grupo forjado (`grupo` arbitrário gravado em atribuição) | Adulteração | validar contra a lista de grupos do servidor |
| `imagens[]` de outro rascunho como referência | Divulgação | filtrar por `$r->imagens()` |
| Vazamento de foto de cliente | Divulgação | referência efêmera; leitura por token com escopo; sem URL pública |
| Texto livre do operador no prompt (`motivo`) | Adulteração | já tratado: ≤ 300, sanitizado, nunca vira fato (TRUTH-02/03) — manter ao copiar o endpoint de regenerar |
| Escrita concorrente no rascunho | Adulteração | `travar` em transação |

## Fontes

### Primárias (confiança ALTA — código do worktree, lido nesta sessão)
- `.planning/phases/165-.../165-CONTEXT.md`, `.planning/REQUIREMENTS-v24.md`, `.planning/ROADMAP.md` (seção Phase 165), `.planning/STATE.md`, `CLAUDE.md`
- `.planning/learnings/publicador-ml.md` §7, §9, §10; `.planning/learnings/desempenho-bonificacao.md` §6
- `app/Services/Creative/*`, `app/Jobs/GerarCriativoIaJob.php`, `app/Jobs/PlanejarKitCriativosJob.php`, `app/Models/MlAnuncioCriativo.php`, `app/Models/MlAnuncioCriativoKit.php`
- `app/Http/Controllers/MlbAnuncioController.php:1253-2243`, `routes/mlb_anuncios.php:28-90,198-278`, `app/Providers/AppServiceProvider.php:189-221`, `app/Console/Commands/LimparReferenciasCriativos.php`
- `app/Http/Controllers/MlbPublicadorController.php`, `MlbPublicadorEntradaController.php`, `app/Services/Publicador/{ImagemAssetService,RascunhoRepository,EditorRascunhoService,DadosEfetivosService,ProgramasPublicadorService}.php`, `app/Support/Publicador/{RascunhoSnapshot,Imagem/ResolvedorGruposImagem,Validacao/ValidadorImagem}.php`, `app/Models/{PubProduto,PubRascunho,PubImagem}.php`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx`, `resources/js/Components/Publicador/{usePublicador.js,useIaDoPublicador.js,apoio.js,FotosPorGrupo.jsx,Mesa/CardFotos.jsx,Mesa/CartaoVariante.jsx}`, `resources/js/Pages/Mlb/components/{PainelCriativosIa,KitCriativosGrade}.jsx`
- `tests/js/publicador-mesa.test.js`, `tests/js/publicador-editor.test.js`, `tests/Unit/Phase161/KitMigrationGuardaTest.php`, migrations de `ml_anuncio_criativos`/kits
- Execução real dos três baselines (§8)

### Secundárias / terciárias
- Nenhuma fonte externa foi necessária (domínio inteiramente interno ao repositório). Não foi usada web nem Context7.

## Metadados

**Confiança:**
- Pilha padrão: ALTA — sem pacotes novos.
- Arquitetura/costura: ALTA — todos os pontos lidos com linha; a única dependência não verificada é o comportamento do adaptador `UploadedFile` (A2).
- Armadilhas: ALTA — as de MariaDB vêm dos learnings; as de escopo/corrida vêm do código lido.
- Coordenação com a 162: MÉDIA/BAIXA — é inferência (A1), precisa da resposta do outro dev.

**Data da pesquisa:** 2026-10-04
**Válido até:** 2026-10-11 (a base de código da v24.0 está em movimento; reabrir se a Fase 162 mergear antes)
