# Fase 160: Publicador no sistema interno (/mlb/anuncios) para Polos e Incubadora — Pesquisa

**Pesquisado em:** 2026-10-02
**Domínio:** Laravel 12 + Inertia/React — generalizar o motor do Publicador ML (âncora `EstruturaOferta`/`Company` → `PubProduto`/`ContaMercadoLivre`), migration em tabela com dado em produção (MariaDB), nova entrada em `/mlb/anuncios`, saída do Anunciar do Portal.
**Confiança geral:** ALTA para o mapa de código e para o plano de migration (tudo lido no worktree); MÉDIA para o que depende de dado de produção (contagens, estado de `#459`), que NÃO foi consultado (proibido nesta pesquisa).

> Convenção de proveniência: `[VERIFIED: codebase]` = lido/rodado no worktree `C:/tmp/ecf-publicador-spec-261001`; `[CITED: …]` = documento do repositório/memória do projeto; `[ASSUMED]` = não verificado nesta sessão (vai para o Assumptions Log).

<user_constraints>
## User Constraints (do CONTEXT.md — copiado)

### Decisões travadas
- **D12** — O Publicador sai do Portal e vira o assistente de `/mlb/anuncios`. Rotas em `routes/mlb_anuncios.php` (grupo `auth, verified, role:admin`, prefixo `mlb/anuncios`). O motor é o mesmo; muda o ponto de entrada e quem usa.
- **D13** — Ao entrar, escolhe-se Polos ou Incubadora. Polos e Incubadora são `MlbEmpresa` (`tipo = 'INCUBADORA'` ou `fase`/`projeto` via `MlbEmpresa::FASE_PARA_PROJETO`; ver `EmpresaOperacionalRouter.php:389-394`). O programa **não é gravado** em tabela nova: deriva da `MlbEmpresa`. A lista mostra as empresas do programa com conta do ML (token por `company_id` ou `mlb_empresa_id`).
- **D14** — Troca SÓ o assistente individual. `AnunciarML.jsx` (wizard, ~2.900 linhas) e a rota `wizard` dão lugar ao Publicador. Meus Anúncios, Em massa e Histórico ficam como estão. O "Anunciar por IA" (`PainelAnunciarIa.jsx`, `GerarAnaliseAnuncioIaJob`, `RascunhoAnuncioIaService`) vira **botão dentro do Publicador** — o que a IA gera precisa cair no rascunho NOVO (`pub_*`), não em `MlAnuncioRascunho`.
- **D15** — Empresa sem Portal: produtos cadastrados no Publicador. 535 de 539 `MlbEmpresa` de Polos não têm `Company`. Tabela nova **`pub_produtos`** (desenho no doc 17 §3.1): `mlb_empresa_id` nullable, `company_id` nullable, `oferta_id` nullable **unique**, `sku`, `nome`, `origem` (`portal` · `publicador`). O schema vai POR ESCRITO no plano antes da migration; armadilhas de MariaDB do learnings §6 (nomes curtos, sem `nullOnDelete`, `--path` no MariaDB local; verificação real é `SHOW INDEX`/`SHOW CREATE TABLE`, não o SQLite dos testes).
- **D16** — "Sincronizar do Portal" é LIGADO. Produto com `oferta_id` herda título planejado e preço da Precificação ao vivo (`DadosEfetivosService` + `RascunhoSnapshot::comEfetivos()`); o que a equipe digita vence. O botão só cria produtos para ofertas novas (idempotente, nunca apaga). Publicar cadastra o MLB na aba Anúncios **só quando há `oferta_id`**.
- **D17** — Acesso: só admins (`role:admin` no grupo). `Permissions::MLB_ANUNCIAR` e o módulo `homologacao` ficam como estão.
- **D18** — O Anunciar sai do Portal para TODOS os clientes quando o interno estiver no ar: sai o piloto (`PortalPublicadorController`, rotas `portal/estrutura/ofertas/*/publicador*`, linhas da allowlist de `RestringeDominioDoPortal`) **e** o formulário antigo do par (`/estrutura/anunciar`, `EstruturaPublicacaoService` como tela). O submódulo "Anunciar" sai do menu do Mapeamento Estrutural (`ModulosPortal`). Lista SKUs, Precificação, Anúncios, Planejamento e Mapeamento ficam.
- **D19** — Fase GSD completa. Baseline de testes antes de mexer, VERIFICATION no fim.
- **Alteração em `pub_rascunhos`** — `produto_id` FK `pub_produtos` **unique**; `oferta_id` passa a nullable; os 2 rascunhos existentes ganham o seu `pub_produto` (origem `portal`, `company_id` 459, `oferta_id` atual) **na mesma migration**, sem perda.

### A critério do planejamento (Claude's Discretion)
- Como generalizar `ClienteMlPublicador`, `ContaMlService`, `ImagemAssetService`, `ConferenciaService`, `PublicacaoService`, `EditorRascunhoService` de `Company`/`EstruturaOferta` para `PubProduto` + `ContaMercadoLivre` sem quebrar os 230 testes (adaptá-los é esperado; o comportamento coberto não muda).
- A organização das telas (entrada com Polos | Incubadora, produtos da empresa, editor) — desde que siga o layout do Stitch aprovado (UI-SPEC) e reaproveite os componentes do piloto (`resources/js/Components/Publicador/*`).
- Onde mora o controller interno (novo `MlbPublicadorController` ou métodos no `MlbAnuncioController`) — a regra do projeto pede controller enxuto.
- O que acontece com a rota `wizard` antiga e com os rascunhos `MlAnuncioRascunho` abertos (manter leitura? migrar? avisar?) — decidir com dados (contagem em produção só por leitura) e, havendo dúvida, perguntar.

### Ideias adiadas (FORA DESTA FASE)
- API de gerar imagens (é o motivo da mudança, mas é fase própria).
- Permissão fina (`permission:mlb.anunciar`) e acesso de publicadores não-admin (D17: só admins).
- Mudanças em Meus Anúncios, Em massa e Histórico.
- O E2E na #459 (F1.12 do piloto) — pode rodar já no Publicador interno, com a confirmação do usuário antes de cada `POST /items`.
</user_constraints>

## Project Constraints (from CLAUDE.md)

- Stack Laravel 12 + Inertia + React, sem mudança de stack. Tailwind com tokens `ecf-*`, dark theme, `cn()`.
- Comentários e artefatos GSD em **pt-BR**; identificadores em inglês/como estão.
- `git commit -- <caminhos>`, nunca `git add -A`/`.` (árvore compartilhada). A memória do projeto avisa que `-- <caminhos>` NÃO protege de arrastar o que outra sessão deixou no índice: conferir `git show <sha>` antes do push. [CITED: memória `feedback_commit_por_caminho_arrasta_outra_sessao`]
- `npm run build` ao fim de qualquer alteração de frontend. Em worktree novo o build "sai 0 SEM buildar" — conferir o manifest. [CITED: memória `project_onboarding_semana_agenda_260916`]
- **Nenhum deploy sem autorização explícita.** Conta de cliente: nunca publicar (só a Dev 02 / #459, com confirmação a cada `POST /items`). [CITED: memória `feedback_conta_cliente_nunca_publicar`]
- GSD obrigatório aqui só pela migration em tabela com dado em produção (D19). Decisão de schema POR ESCRITO antes da migration; teste no mesmo commit.
- Leitura obrigatória feita: `.planning/learnings/publicador-ml.md` e `desempenho-bonificacao.md` §6.
- Descoberta cara e não dedutível vai para `.planning/learnings/` (sugestão no fim: acrescentar seção ao `publicador-ml.md`).
- Sem skills de projeto (`.claude/skills` não consultado além do CLAUDE.md, que declara "No project skills found").

## Summary

O motor do Publicador (núcleo puro `app/Support/Publicador/*`, serviços, jobs, tabelas `pub_*`) está bem isolado: **só 8 pontos de código** assumem `EstruturaOferta`/`Company` (lista exata na §1), e quase todos são a mesma expressão `$r->oferta->company` repetida. A generalização é mecânica: `PubRascunho` ganha `produto()` e `conta(): ContaMercadoLivre`; `ClienteMlPublicador`/`ContaMlService` passam a tipar `ContaMercadoLivre` (a interface que `Company` e `MlbEmpresa` já implementam — o `MercadoLivreService::ensureValidToken` já aceita as duas âncoras); `DadosEfetivosService`, `EditorRascunhoService::abrir`, `RascunhoRepository::criar` e `PublicacaoService::cadastrarNaRegua` passam a receber `PubProduto` e a tratar `oferta_id` nulo (sem efetivos, sem régua). O núcleo puro (`RascunhoSnapshot::comEfetivos` com arrays nulos) já funciona sem Portal. Baseline medido hoje: `tests/Unit/Publicador` + `tests/Feature/Publicador` = **230 testes / 952 asserções, verde, 16 s** [VERIFIED: phpunit rodado no worktree, SQLite em memória].

A migration é de baixo risco **se** seguir o molde do repo: não dropar nem recriar `pubr_oferta_uq`/`pubr_oferta_fk` (o `oferta_id` vira apenas nullable por `->change()`, que tem precedente em `2026_09_21_120000_alter_ml_tokens_add_mlb_empresa_anchor.php`); criar `pub_produtos` em um arquivo e fazer `ADD produto_id` + backfill + unique/FK em outro (assim uma falha no meio não deixa a tabela "criada sem índice" com a migration `Pending`, o incidente do learnings §6); índices/FK com nomes `pubprod_*`/`pubr_produto_*` (< 64 caracteres); sem `nullOnDelete` em coluna não-nullable; idempotência por `hasColumn`/`hasIndex` (molde `2026_09_30_100000_amplia_unique_user_setores_por_cargo.php`), nunca `try/catch` em volta de DDL.

O que mais pode dar errado **não é técnico, é de produto**, e o planejador não deve decidir sozinho (§8): (1) a conta `#459`, única onde se pode testar, é uma `Company` que provavelmente **não aparece** em Polos/Incubadora — sem `MlbEmpresa` no programa o E2E é inalcançável pela nova entrada; (2) abrir o módulo para ~535 contas reais de cliente remove a trava `publicador.empresas_piloto` (hoje o único freio da regra "conta de cliente nunca publica"); (3) a rota `wizard` e `AnunciarML.jsx` são referenciadas por Meus Anúncios, Histórico, Rascunhos e por ~6 arquivos de teste — "dar lugar" literal quebra "Anunciar semelhante" e a abertura de rascunhos antigos; (4) as abas Meus/Massa/Histórico só aceitam `Company` (binding `{company}`), então para `MlbEmpresa` sem `Company` as abas irmãs não existem; (5) a busca de categoria do editor mora em `EstruturaPublicacaoService`, que D18 manda aposentar.

**Recomendação principal:** dividir em ondas: (A) baseline + decisão de schema por escrito + migrations (`pub_produtos`; `pub_rascunhos.produto_id` + backfill); (B) generalização do motor (`PubProduto`/`ContaMercadoLivre`) mantendo os 230 testes verdes com adaptação só de fixtures; (C) backend interno (`MlbPublicadorController` + rotas `mlb.anuncios.publicador.*` + listagem Polos/Incubadora + sincronizar do Portal); (D) front (entrada, produtos, editor parametrizado por rota, botão IA); (E) só no fim, a saída do Portal (D18), com o interno já no ar e verificado.

## Architectural Responsibility Map

| Capacidade | Tier primário | Tier secundário | Racional |
|------------|---------------|-----------------|----------|
| Listar empresas de Polos/Incubadora com conta ML | API / Backend (Inertia controller + `MlbEmpresa` scopes) | Database | Derivado de `mlb_empresas` + `ml_tokens`; nada no browser |
| Sincronizar do Portal (criar `pub_produtos`) | API / Backend (serviço idempotente) | Database (unique `oferta_id`) | Regra de negócio + garantia de unicidade no banco |
| Cadastrar produto à mão (D15) | API / Backend | Browser (formulário) | Validação de SKU/nome no servidor |
| Editor do rascunho (seções 01–08) | Browser / Client (React `EditorPublicador`) | API (JSON por produto) | Já é assim no piloto: toda resposta devolve o estado inteiro |
| Conferência L3 e publicação | API / Backend (Jobs na fila `high`) | Browser (polling do estado) | Chamadas ML longas; D9 do piloto |
| Token/conta ML do produto | API / Backend (`PubProduto::conta()` → `ContaMercadoLivre`) | Database (`ml_tokens`) | Duas âncoras (`company_id`/`mlb_empresa_id`) |
| Efetivos do Portal (título/preço) | API / Backend (`DadosEfetivosService`) | — | Lido ao vivo na conferência/publicação; nunca gravado no rascunho |
| Cadastro do MLB na aba Anúncios | API / Backend (`PublicacaoService::cadastrarNaRegua`) | Database | Só com `oferta_id` (D16) |
| Acesso admin-only | API / Backend (`role:admin` no grupo de rotas) | — | Já configurado em `routes/mlb_anuncios.php` |
| Bloqueio do Anunciar no Portal (D18) | API / Backend (remoção de rotas + allowlist) | Browser (menu `ModulosPortal`) | 404 vem da rota ausente; allowlist é a 2ª barreira |

## Standard Stack

Nenhuma dependência nova. Tudo já está no projeto. [VERIFIED: codebase — `composer.json`/`package.json` não precisam mudar]

### Core (já existente, reutilizar)
| Biblioteca/Peça | Versão | Propósito | Por que |
|-----------------|--------|-----------|---------|
| Laravel | ^12.0 | Eloquent, migrations, filas | Stack travada |
| Inertia (`inertia-laravel ^2`, `@inertiajs/react ^2`) | 2.x | Páginas internas | Padrão do módulo `Mlb/*` |
| PHPUnit | 11.5.55 (rodado) | Testes | `phpunit.xml`: SQLite `:memory:` |
| `App\Contracts\ContaMercadoLivre` | — | Abstração `Company`/`MlbEmpresa` | Já implementada nos dois modelos |
| `@dnd-kit/*` | já no `package.json` | Reordenar fotos (`FotosDoPar`) | Componente reaproveitado |
| `node --test` | `npm run test:js` | Testes JS "de fonte" (`tests/js/*.test.js`) | Padrão do repo para garantias de UI |

### Pacotes: auditoria de legitimidade
Esta fase **não instala pacotes externos**. Nenhum `[SLOP]`/`[SUS]`; `slopcheck` não é necessário.

| Pacote | Registro | Disposição |
|--------|----------|------------|
| — (nenhum novo) | — | Não aplicável |

**Pacotes removidos por `[SLOP]`:** nenhum. **Sinalizados `[SUS]`:** nenhum.

## Architecture Patterns

### Diagrama do fluxo (alvo da fase)

```
Admin (role:admin)
   │  GET /mlb/anuncios
   ▼
[Entrada: Polos | Incubadora] ──► MlbPublicadorController@index
   │                                  │ MlbEmpresa::programa('polos'|'incubadora')->ativas()
   │                                  │   + token (mlToken OU company.mlToken) + situação do Portal
   ▼                                  ▼
[Empresas do programa c/ conta ML]   (props Inertia)
   │ escolhe empresa
   ▼
GET /mlb/anuncios/publicador/empresas/{mlbEmpresa}   ── Produtos da empresa
   │   ├─ "Sincronizar do Portal" ► PublicadorSincronizaPortalService (firstOrCreate por oferta_id; nunca apaga)
   │   └─ "+ Produto" ────────────► cria pub_produtos (origem=publicador, oferta_id NULL)
   ▼ abre produto
GET /mlb/anuncios/publicador/produtos/{produto}  (JSON, estado inteiro)  ◄── EditorPublicador (React)
   │ PUT salvar|categoria|eixos|variantes|fotos…
   ▼
EditorRascunhoService(PubProduto) ──► RascunhoRepository ──► pub_rascunhos(produto_id) + filhas
   │                       │
   │                       └─ DadosEfetivosService::daProduto(): oferta? Portal ao vivo : [] (sem efetivos)
   ▼ "Conferir" (202)                      ▼ "Publicar" (202)
ConferirRascunhoJob (high)             PublicarRascunhoJob (high, fatias de 45 s)
   │ ConferenciaService                    │ PublicacaoService
   └──── ClienteMlPublicador::daConta(ContaMercadoLivre = produto->conta()) ──► API ML
                                           └─ cadastrarNaRegua() só se produto->oferta_id
"Anunciar por IA": POST ia/analise (job) ─► resultado ─► aplicar em pub_* via EditorRascunhoService
```

### Estrutura recomendada (arquivos novos/alterados)
```
database/migrations/
├── 2026_10_02_100000_create_pub_produtos_table.php         # só a tabela nova
└── 2026_10_02_100100_add_produto_id_to_pub_rascunhos.php   # ALTER + backfill + unique/FK
app/Models/PubProduto.php                                    # relações + conta()
app/Services/Publicador/
├── DadosEfetivosService.php        # daOferta() fica; ganha daProduto()
├── PublicadorSincronizaPortalService.php   # NOVO: sincronizar + situação do Portal
├── ProgramasPublicadorService.php  # NOVO: lista Polos/Incubadora + conta/token (ou scope no MlbEmpresa)
├── CategoriaBuscaService.php       # NOVO: extraído de EstruturaPublicacaoService::categorias()
└── IaParaRascunhoService.php       # NOVO: resultado da IA → pub_* (via EditorRascunhoService)
app/Http/Controllers/MlbPublicadorController.php            # NOVO, enxuto (copia a forma do PortalPublicadorController)
routes/mlb_anuncios.php                                      # + grupo publicador.*
resources/js/Pages/Mlb/Publicador/{Entrada,Produtos,Editor}.jsx   # NOVOS
resources/js/Components/Publicador/apoio.js                  # rota() parametrizável
```

### Padrão 1: Âncora do produto → conta do ML
**O quê:** `PubProduto::conta(): ContaMercadoLivre` escolhe a âncora que **tem token ativo**: `mlbEmpresa` se `mlbEmpresa->mlToken` existir, senão `company` se `company->mlToken` existir; senão lança `RegraViolada('V-ACC-01', …)` (a mensagem já existe em `ClienteMlPublicador::desconectada()`).
**Por quê:** `MlbEmpresa::mlToken()` é `hasOne(MlToken::class, 'mlb_empresa_id')` (`MlbEmpresa.php:125`) e `Company::mlToken()` é por `company_id` (`Company.php:622`). Uma `MlbEmpresa` com `company_id` (4 das 539) pode ter o token em qualquer das duas — o OAuth do Portal grava em `company_id`. `ensureValidToken` usa `$conta->mlToken ?? MlToken::where($conta->colunaAncoraMl(), $conta->getKey())->first()` (`MercadoLivreService.php:308-314`), então passar o modelo errado = "sem token". [VERIFIED: codebase]
**Atenção:** nunca usar `$model->id` cru em chave de cache/lock; usar `chaveContaMl()` (`company-5` ≠ `empresa-5`). [VERIFIED: `ContaMercadoLivre.php` docblock]

### Padrão 2: Efetivos opcionais
`DadosEfetivosService::daProduto(PubProduto $p)`: se `$p->oferta_id === null` devolve `['titulos' => [gold_special=>null, gold_pro=>null], 'precos' => [mesmo], 'mlbs' => []]`; senão delega ao `daOferta($p->oferta)` atual (linhas 27-48). `RascunhoSnapshot::comEfetivos` já trata nulos (`RascunhoSnapshot.php:58-80`: título vazio → efetivo; preço só é preenchido `isset($precos[$lt])`). [VERIFIED: codebase]

### Padrão 3: Controller interno igual ao do piloto
Copiar a forma de `PortalPublicadorController` (`responder()`, `RegraViolada → 422`, estado inteiro em toda resposta) trocando a resolução de `oferta(int)`/`rascunho(int)` (linhas ~244-256) por `PubProduto::findOrFail($id)` + checagem de "o produto pertence a uma empresa de programa". `PortalContexto::ator()` (usado em `publicar`) vira `AtorDoPortal::daEquipe(auth()->user())` (`app/Support/Portal/AtorDoPortal.php`: `daEquipe(User)` existe e `PublicacaoService::atorGravado` já sabe reler `equipe=true` via `User::find`). [VERIFIED: codebase]

### Anti-padrões
- **Gravar o programa (Polos/Incubadora) em `pub_produtos`** — D13 proíbe; deriva da `MlbEmpresa` para não divergir.
- **Dropar/recriar `pubr_oferta_uq`/`pubr_oferta_fk`** para "limpar" — é a rota do erro 1553; não é necessário (§2).
- **Copiar `sku`/`nome` da oferta para o produto e deixar divergir** — para produto com `oferta_id`, exibir `oferta->sku/nome` ao vivo (o campo do produto é só reserva).
- **Chamar `Http::fake()` de novo no meio do teste** (learnings §5): registrar uma vez com closures.

## Don't Hand-Roll

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Token ML / refresh / lock | Novo gerenciador | `MercadoLivreService::ensureValidToken(ContaMercadoLivre)` via `ClienteMlPublicador` | refresh token é de uso único; lock por `chaveContaMl()` |
| Conferência e publicação em fila | Novo Job | `ConferirRascunhoJob`/`PublicarRascunhoJob` (nada muda) | SENT antes do POST, `release()` em fatias (learnings §6 do `publicador-ml`) |
| Ator do histórico | Novo DTO | `AtorDoPortal::daEquipe(User)` | `EstruturaAnuncioService::cadastrar` e `PubPublicacao.ator` já o esperam |
| Estado do editor | Estado paralelo no React | Resposta JSON `editor->estado($r)` (inteira) | contrato do piloto |
| Prontidão do produto na lista | Recalcular | `EditorRascunhoService::prontidao($r, $ultimaValidacao)` (estático; já usado em `PortalEstruturaController::comProntidaoDoPublicador`, l.218-228) | mesma semântica de selo |
| Busca de categoria | Novo cliente ML | `MlCatalogoMetaService` + o código de `EstruturaPublicacaoService::categorias()`/`categoriasEmLote()` (l.223-315) **extraído** | D18 aposenta o service; sem extrair, o editor perde a busca (§5) |
| IA | Novo pipeline de geração | `GerarAnaliseAnuncioIaJob` + `MlAnuncioIaAnalise` (já tem `mlb_empresa_id`) | só falta o "aplicar" no `pub_*` (§4) |
| Reordenar fotos | Novo drag | `FotosDoPar` + `lib/fotosDoPar.js` (ficam; `FotosPorGrupo` os usa) | testado por `tests/js/fotos-do-par.test.js` |

## 1. Mapa de acoplamento a `Company`/`EstruturaOferta` (com arquivo:linha)

[VERIFIED: codebase — `grep` + leitura]

| Arquivo:linha | Hoje | Generalização |
|---|---|---|
| `app/Models/PubRascunho.php:43-46` | `oferta(): BelongsTo EstruturaOferta` por `oferta_id` | + `produto(): BelongsTo PubProduto` por `produto_id`; + `conta(): ContaMercadoLivre` = `$this->produto->conta()`. Manter `oferta()` só para os 2 legados/leitura |
| `app/Services/Publicador/ClienteMlPublicador.php:46,57,72,112` | `daConta(Company …)`, `enviarFoto(Company …)`, `executar(Company …)`, `tokenValido(Company …)` | Trocar o tipo por `ContaMercadoLivre` (4 assinaturas; corpo igual: `$this->ml->ensureValidToken($empresa)`). `Company` continua satisfazendo → testes que passam `Company` não mudam |
| `app/Services/Publicador/ContaMlService.php:23` | `contexto(Company $empresa)` | `contexto(ContaMercadoLivre $conta)`; corpo só repassa a `ClienteMlPublicador` |
| `app/Services/Publicador/DadosEfetivosService.php:27-31` | `daOferta(EstruturaOferta)`: `$oferta->company`, `EstruturaConjunto::daEmpresa($empresa)->oferta($id)`, `precificacao->pagina($empresa,[$id])` | Manter `daOferta`; **novo** `daProduto(PubProduto)` (nulo ⇒ efetivos vazios). Todos os chamadores abaixo passam a chamar `daProduto($r->produto)` |
| `app/Services/Publicador/ConferenciaService.php:71` | `$empresa = $r->oferta->company` | `$r->conta()` |
| `ConferenciaService.php:212` | `efetivos->daOferta($r->oferta)` | `daProduto($r->produto)` |
| `ConferenciaService.php:248, 322` | `cliente->daConta($r->oferta->company, …)` | `daConta($r->conta(), …)` |
| `ConferenciaService.php:306-308` | `skusEmOutrosAnuncios(... $mlbsDaOferta ...)` já recebe `mlbs` por parâmetro | sem mudança (vazio sem Portal ⇒ V-REM-02 avisa em SKU repetido, o que é correto) |
| `app/Services/Publicador/EditorRascunhoService.php:63-76` | `abrir(EstruturaOferta)`: `PubRascunho::where('oferta_id')`, migra de `EstruturaPublicacao`, `mlbsDaRegua($oferta)`, `repo->criar($oferta,…)`, SKU da variante única `$oferta->sku` | `abrir(PubProduto)`: `PubRascunho::where('produto_id')`; migração de `EstruturaPublicacao` **só se** `oferta_id`; alvos `new Alvo($lt, null, ativo: $mlbs[$tipo]===null)` — sem oferta `mlbs` = nulos ⇒ ambos ativos; SKU = `$produto->sku` |
| `EditorRascunhoService.php:243, 294-295` | `efetivos->daOferta($r->oferta)` | `daProduto` |
| `EditorRascunhoService.php:263` | `cliente->daConta($r->oferta->company, …)` (simular frete) | `$r->conta()` |
| `EditorRascunhoService.php:294, 331` | `$r->fresh(['oferta'])`; `'oferta' => ['id','sku','nome']` no estado | `fresh(['produto.oferta'])`; chave do estado vira `'produto' => ['id','sku','nome','oferta_id','origem']` (o front lê `estado.oferta.sku/nome` em `EditorPublicador.jsx:131,460,461`) |
| `EditorRascunhoService.php:465` | `contas->contexto($r->oferta->company)` | `$r->conta()` — **atenção:** a captura de `RegraViolada` (l.466-471) já grava `conta.erro` quando falta token, bom para `MlbEmpresa` sem token |
| `EditorRascunhoService.php:477-479, 494` | `mlbsDaRegua(EstruturaOferta)` usa `$oferta->company` | `mlbsDaRegua(PubProduto)`: sem `oferta_id` ⇒ `['classico'=>null,'premium'=>null]`; senão como hoje |
| `app/Services/Publicador/ImagemAssetService.php:70` | `enviarFoto($imagem->rascunho->oferta->company, …)` | `$imagem->rascunho->conta()` |
| `app/Services/Publicador/RascunhoRepository.php:39-42` | `criar(EstruturaOferta $oferta, $alvos, $ator)` grava `oferta_id` | `criar(PubProduto $produto, …)`: grava `produto_id` **e** `oferta_id = $produto->oferta_id` (recomendado manter denormalizado — §2, decisão D-aberta) |
| `app/Services/Publicador/PublicacaoService.php:66-70` | `iniciar()`: trava `publicador.empresas_piloto` por `$r->oferta->company_id` | Reescrever a trava para o produto (`company_id`/`mlb_empresa_id`) — **decisão do usuário, §8 Q2** |
| `PublicacaoService.php:197, 207, 313, 455` | `$r->oferta->company` | `$r->conta()` |
| `PublicacaoService.php:339, 395, 472` | `enviar(…, Company $empresa)`, `reconciliar(…, Company …)`, `descricao(…, Company …)` | tipo `ContaMercadoLivre` |
| `PublicacaoService.php:520-545` (`cadastrarNaRegua`) | `$oferta = $r->oferta; $oferta->anuncios()…; $this->anuncios->cadastrar($oferta, …)` | `if ($r->produto->oferta_id === null) return;` no topo (D16) e `$oferta = $r->produto->oferta` |
| `app/Services/Publicador/MigracaoAnunciarAntigo.php:60-62, 74, 94-99, 155-156` | usa `$antiga->oferta`, `PubRascunho::where('oferta_id')`, `repo->criar($oferta)`, `precificacao->pagina($oferta->company…)` | Adaptar para criar/obter o `PubProduto` da oferta (`firstOrCreate` por `oferta_id`) antes de `repo->criar`. O comando `app/Console/Commands/PublicadorMigrarAnunciar.php` acompanha. Ver §8 Q9 (ainda faz sentido migrar o Anunciar antigo se ele sai do Portal?) |
| `app/Jobs/Publicador/{ConferirRascunhoJob,PublicarRascunhoJob}.php` | **nenhuma** referência a oferta/company [VERIFIED: grep] | sem mudança |
| `app/Support/Publicador/**` (núcleo puro) | **nenhuma** [VERIFIED: grep] | sem mudança |
| `app/Http/Controllers/PortalPublicadorController.php` | `oferta(int)`: `PortalContexto::empresa()` + `EstruturaOferta::where('company_id')`; `rascunho(int)`: `PubRascunho::where('oferta_id')`; `publicar`: `PortalContexto::ator()` | Não é adaptado — é **substituído** por `MlbPublicadorController` e removido na onda E (D18) |
| `app/Http/Controllers/PortalEstruturaController.php:201-203, 218-228` | `noPiloto`, `comProntidaoDoPublicador` (lê `PubRascunho` por `oferta_id`) | removidos com o Anunciar do Portal (E) |

### Testes que precisam de adaptação (230 baseline)

[VERIFIED: grep em `tests/Unit/Publicador` + `tests/Feature/Publicador`]

| Arquivo | Testes | Por quê / ação |
|---|---|---|
| `tests/Unit/Publicador/**` (Erros, Imagem, Payload, Schema, Validacao, Variacao, `PublicadorSondarTest`) | ~178 | **Nenhuma referência** a oferta/Company (núcleo puro). Não tocar |
| `Feature/Publicador/Concerns/CenarioCadeira.php:48,76,89-90` | base de `ConferenciaTest` (13) + `PublicacaoTest` (18) | **Único ponto de adaptação** dos dois: criar `PubProduto` (origem `portal`, `company_id`, `oferta_id`) e chamar `repo->criar($produto, …)`. `PublicacaoTest` usa `$this->r->oferta_id` (l.130,158,259,323,334) ⇒ continua válido se `criar` grava `oferta_id`; `config(['publicador.empresas_piloto'…])` (l.64,188-191) acompanha a decisão Q2 |
| `RascunhoRepositoryTest.php:40-43` (+ `test_excluir_a_oferta_leva_o_rascunho`, l.162-166) | 9 | `criar($produto, …)`. O teste de exclusão **fixa o comportamento do FK**: decidir §8 Q5 e adaptar (cascade pela oferta → produto → rascunho) |
| `ImagensTest.php:42-46` | 8 | `criar($produto, …)` |
| `CamadaMlTest.php` (3 refs a `Company`) | 15 | Só passa `Company`; deve continuar verde após trocar o tipo por `ContaMercadoLivre`. **Adicionar** caso com `MlbEmpresa` (token em `mlb_empresa_id`) |
| `DadosEfetivosTest.php` (1 teste, usa rotas do Portal para montar dados) | 1 | Continua; chamar `daProduto`; **adicionar** caso produto sem oferta |
| `MigracaoAnunciarAntigoTest.php` | 4 | Adaptar a `PubProduto` ou aposentar com a decisão Q9 |
| `PortalPublicadorTest.php` | 10 | Testa o HTTP **do Portal** (piloto, abrir, partes, selos). **Converter** para `MlbPublicadorTest` (admin + rotas por produto) na onda C e **apagar** o arquivo original na onda E |
| `tests/Feature/PortalCliente/Estrutura/AnunciarEstruturaTest.php` (14 testes, 611 linhas) | 14 | Testa o Anunciar antigo (D18). Maioria some com a rota; `test_sugestoes_de_categoria_trazem_o_caminho_completo…` (l.291) migra para o teste de `CategoriaBuscaService`; `test_oferta_de_outra_empresa_responde_404` e `test_sem_conta_do_ml_nada_vai_para_o_ml` viram "rota inexistente ⇒ 404" |
| `tests/Feature/PortalCliente/Estrutura/AcessoAoModuloEstruturaTest.php:60-69` | 1+ | Hoje espera `['lista','precificacao','anuncios','planejamento','mapeamento','anunciar']` e o loop `'anunciar' => 'Portal/EstruturaAnunciar'`; ajustar para 5 submódulos e remover `anunciar` |
| `tests/Feature/AnunciosPolosNaListagemTest.php`, `MlTokenAncoraPolosTest.php`, `Phase75/*`, `Phase76/*`, `Phase134/RascunhosMeusAnunciosTest.php`, `AnuncioIaAnaliseTest.php` | vários | Dependem do `index`/`wizard` atuais (§4). Só quebram se `index`/`wizard` mudarem de comportamento — ver §8 Q3/Q4 |
| `tests/js/estrutura-meus-anuncios.test.js:95-102` | 2 | Garante que `ModoAnuncioTabs` tem `mlb.anuncios.wizard` e que `meus` vem antes. Mudar a aba "Individual" exige atualizar |

## 2. Schema atual e plano SEGURO de migration (MariaDB)

### 2.1 Estado atual [VERIFIED: `2026_10_01_200000_create_publicador_tables.php:36-59`]
```php
Schema::create('pub_rascunhos', function (Blueprint $t) {
    $t->id();
    $t->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'pubr_oferta_fk')->cascadeOnDelete();
    // … status, revisao, step_state, identificacao, categoria_id, … ator, timestamps
    $t->unique('oferta_id', 'pubr_oferta_uq');
});
```
Filhas (`pub_rascunho_alvos`, `_atributos`, `pub_eixos`, `pub_variantes`, `pub_imagens`, `pub_validacoes`, `pub_publicacoes`…) apontam para `pub_rascunhos.id` com FK `*_fk` e `cascadeOnDelete`. **Nenhuma** outra tabela referencia `oferta_id`/`company_id` do Publicador. [VERIFIED: grep na migration]

### 2.2 Decisão de schema (a ir POR ESCRITO no PLAN antes de existir — CLAUDE.md, disciplina 2)

**Migration A — `2026_10_02_100000_create_pub_produtos_table.php`** (só tabela nova; molde `2026_10_01_200000`)

| coluna | tipo | nota |
|---|---|---|
| `id` | bigint unsigned PK | `$t->id()` |
| `mlb_empresa_id` | `foreignId` nullable | FK `pubprod_empresa_fk` → `mlb_empresas.id`, `cascadeOnDelete` |
| `company_id` | `foreignId` nullable | FK `pubprod_company_fk` → `companies.id`, `cascadeOnDelete` |
| `oferta_id` | `foreignId` nullable | FK `pubprod_oferta_fk` → `estrutura_ofertas.id`; **unique `pubprod_oferta_uq`**; ação de exclusão: ver Q5 |
| `sku` | string(120) | |
| `nome` | string(255) | |
| `origem` | string(12) | default `'publicador'`; valores `portal`·`publicador` |
| timestamps | | |
| índice | `(mlb_empresa_id, sku)` nome `pubprod_empresa_sku_ix` | acelera lista e dedupe (não-unique: SKU "Não tenho" repete no Portal) |
| índice | `company_id` nome `pubprod_company_ix` | listagem por `company_id` (backfill/portal) |

- `nullOnDelete` **não** usar (learnings §6 erro 1830 se a coluna não for nullable; aqui as três são nullable, mas preferir `cascadeOnDelete`/`restrict` explícito e decidido — Q5).
- Tipos das FK **idênticos** aos PK referenciados (`unsignedBigInteger`): confirmar `SHOW CREATE TABLE mlb_empresas/companies/estrutura_ofertas` (erro 150/3780 se divergir). `foreignId()` gera `unsignedBigInteger`. [ASSUMED: PKs dessas tabelas são `id()` — o `ml_tokens` já referencia `mlb_empresas` com a mesma forma, `2026_09_21_120000`]
- A regra "pelo menos uma âncora" fica no serviço (doc 17 §3.1) — sem CHECK.
- `unique(oferta_id)` com NULL repetido é permitido (MariaDB e SQLite) — é o que permite vários produtos "do Publicador". [CITED: doc 17 §3.1 e docblock da migration `2026_09_30_100000`]
- Nomes: o maior (`pubprod_empresa_sku_ix`, 22) está bem abaixo de 64 (erro 1059).

**Migration B — `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php`** (ALTER + backfill; separada de A de propósito: se falhar a meio, A já está aplicada e B é retomável, sem "tabela criada sem índice + migration Pending" — learnings §6)

Ordem do `up()` (cada passo idempotente por `hasColumn`/`hasIndex` — molde `2026_09_30_100000_amplia_unique_user_setores_por_cargo.php`, que também traz o helper `hasIndex()` cross-driver para copiar):
1. `if (! Schema::hasColumn('pub_rascunhos','produto_id'))` → `$t->unsignedBigInteger('produto_id')->nullable()->after('id')`.
2. `oferta_id` nullable: `$t->unsignedBigInteger('oferta_id')->nullable()->change();` — **precedente no repo** com a mesma situação (coluna com FK): `2026_09_21_120000_alter_ml_tokens_add_mlb_empresa_anchor.php` faz `company_id … ->nullable()->change()`. **Não** mexer em `pubr_oferta_uq`/`pubr_oferta_fk` ⇒ nenhum risco de 1553. (O `change()` em Laravel 12 precisa repetir tipo/unsigned — foi o que o precedente fez.)
3. **Backfill** (DB::table, sem Eloquent): para cada `pub_rascunhos` com `produto_id IS NULL`: buscar a oferta; `firstOrCreate` em `pub_produtos` por `oferta_id` com `['company_id' => oferta.company_id, 'sku' => oferta.sku, 'nome' => oferta.nome ?? oferta.sku, 'origem' => 'portal', timestamps]`; gravar `produto_id`. Idempotente (`whereNull('produto_id')` + `where('oferta_id')` no produto). 2 linhas em produção (ambas #459) [CITED: doc 17 §3.2].
4. Conferir `whereNull('produto_id')->count() === 0` — senão `throw new \RuntimeException` (não engolir).
5. `produto_id` NOT NULL via `change()` (decisão recomendada: rascunho sempre tem produto) e **só então** `unique('produto_id','pubr_produto_uq')` + `foreign('produto_id','pubr_produto_fk')->references('id')->on('pub_produtos')->cascadeOnDelete()`, cada um sob `hasIndex`/checagem de FK.
6. `down()`: recusar (`RuntimeException`) se existir rascunho cujo produto tenha `oferta_id` NULL (não dá para restaurar `oferta_id` NOT NULL); senão dropar FK `pubr_produto_fk` **antes** do unique `pubr_produto_uq` e só então a coluna; não restaurar NOT NULL de `oferta_id` sem checar nulos. Não apagar `pub_produtos`.

**O que NÃO fazer:** dropar/recriar `pubr_oferta_uq`; `try/catch` em volta de DDL; `->nullOnDelete()`; nome auto-gerado (o auto-nome de `pub_rascunhos_produto_id_foreign` tem 33 caracteres — ok, mas o repo padronizou nomes explícitos `pub*_fk` e tabelas `pub_*` em unique/FK); rodar sem `--path`.

**`oferta_id` em `pub_rascunhos` após a migration (decisão D-aberta, recomendo MANTER preenchido):** `RascunhoRepository::criar` grava `produto_id` + `oferta_id = produto.oferta_id`. Vantagens: o unique `pubr_oferta_uq` e a cascata por oferta continuam valendo sem DDL, e `PublicacaoTest` (`$this->r->oferta_id`) segue intacto. Custo: duas fontes; mitigação = teste que garante `oferta_id === produto.oferta_id`.

### 2.3 Como verificar (o SQLite não pega)
```bash
# MariaDB LOCAL (ecf_admin) — compartilhado entre sessões: nada de seed; só a migration, com --path:
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_02_100000_create_pub_produtos_table.php
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php
# depois, no cliente MySQL:
SHOW CREATE TABLE pub_produtos;   SHOW CREATE TABLE pub_rascunhos;   SHOW INDEX FROM pub_rascunhos;
```
Conferir: `pubr_oferta_uq` e `pubr_oferta_fk` ainda existem; `pubr_produto_uq` existe; `oferta_id` aceita NULL; nenhum nome > 64; `migrations` registra as duas como `Ran` (não `Pending`). Para provar o backfill sem sujar o banco compartilhado: criar 2 linhas legadas em SQLite via teste (migrar até a A, inserir `pub_rascunhos` com `produto_id` ausente, rodar B) — ver §7. [ASSUMED: o MariaDB local está de pé e já tem `2026_10_01_200000` aplicada; não foi sondado]

## 3. Polos e Incubadora: listar empresas, token e vínculo com o Portal

### 3.1 Definição de programa [VERIFIED: codebase]
- `MlbEmpresa` (`app/Models/MlbEmpresa.php`): colunas `tipo` (default `'POLO'`, `string(20)`), `projeto` (canônico, nullable), `fase`, `company_id` (nullable), `arquivado_em`.
- `projeto()` (l.~108): `projeto ?: FASE_PARA_PROJETO[fase]`. `FASE_PARA_PROJETO` (l.~92-105): `Encaminhar Comercial|Aceite no Projeto|M0..M4 ⇒ POLOS`, `ASSESSORIA ⇒ Assessoria`, `Incubadora ⇒ Incubadora`, `Implantação ⇒ Implantação`.
- **Polos** (padrão do próprio painel): `PolosController.php:365` filtra `(projeto ?: FASE_PARA_PROJETO[fase]) === 'POLOS'` depois de `MlbEmpresa::ativas()`.
- **Incubadora — três marcadores coexistem**, e é a pegadinha: `EmpresaOperacionalRouter::criarFicha` cria `tipo => 'INCUBADORA'` **sem `projeto` e sem `fase`** (l.389-394), então `projeto()` devolve `null` para essas; enquanto `MlbController.php:395-403` e o dashboard contam Incubadora por `projeto = 'Incubadora'`/`fase = 'Incubadora'`. Para não perder nenhuma: `tipo = 'INCUBADORA' OR projeto = 'Incubadora' OR (projeto nulo/vazio E fase = 'Incubadora')`.
- Recomendação: um `scopePrograma($q, 'polos'|'incubadora')` em `MlbEmpresa` (ou serviço `ProgramasPublicadorService`), **sempre** após `ativas()` (empresa arquivada saiu do projeto — learnings de Polos §3; `empresasDePolos()` já faz isso, l.2404-2410). Cuidado: o filtro de Polos por `projeto` precisa da mesma tolerância a nulo (`whereNull('projeto')->whereIn('fase', array_keys(FASE_PARA_PROJETO) filtrado p/ POLOS)`), senão uma empresa só com `fase` some em silêncio (exatamente o comentário em `MlbEmpresa.php` sobre `Encaminhar Comercial`).
- `.planning/learnings/painel-polos-status-e-meta.md` diz que "empresa polo" é `MlbEmpresa`, não `Company` [CITED: CLAUDE.md]; não criar `Company` para guardar token.
- Memória do projeto: "Incubadora 0 clientes/tokens em prod" [CITED: `project_publicador_incubadora_261001`]. A aba Incubadora pode nascer vazia — a tela precisa de estado vazio decente.

### 3.2 Token (conta do ML)
- `ml_tokens` tem **duas âncoras**: `company_id` (nullable desde 21/09) e `mlb_empresa_id` (nullable, **unique**, FK cascade) [VERIFIED: `2026_09_21_120000…`].
- Para listar sem N+1: `MlbEmpresa::query()->ativas()->programa($p)->with(['mlToken','company.mlToken'])`; "tem conta" = `mlToken` **ou** `company?->mlToken` com `status !== 'revoked'`. Status/expiração: `MlToken::isExpired()`, `status` (`active`/`revoked`).
- **Não** usar como evidência o carimbo `dados->ml_oauth` (como `empresasDePolos()` ainda faz, l.2409): desde 21/09 o token é gravado; o carimbo mede "autorizou", não "tem token". D13 pede token. [VERIFIED: codebase]

### 3.3 Vínculo com o Portal
`MlbEmpresa.company_id` → `Company` → `EstruturaOferta.company_id` (`EstruturaConjunto::daEmpresa($company)` lê as ofertas e `Precificacao` por `Company`). Sem `company_id` ⇒ "sem Portal" (D15). Situação para a lista (D13/critério 1):
- `sem_portal`: `company_id` nulo **ou** a `Company` não tem nenhuma `EstruturaOferta`;
- `nunca`: tem ofertas e **nenhum** `pub_produtos.oferta_id` dessas ofertas;
- `sincronizado`: todas as ofertas têm produto; (estado intermediário "pendente N" quando faltam algumas — ver Q7: o doc 17 lista só 3 estados).
Contagem eficiente: dois `COUNT` agrupados por `company_id` (`estrutura_ofertas`) e por `pub_produtos` (`whereNotNull('oferta_id')`), não por empresa.

### 3.4 `MlbAnuncioController::empresas()` (l.2332-2338) é reaproveitável?
Resposta: **só em parte; não reaproveitar tal qual.** [VERIFIED: codebase]
- `empresasDeConsultoria()` (l.2341) lista TODA `Company` com token e conta `MlAnuncioRascunho` por empresa dentro de um `map` (N+1: 2 COUNT por empresa + 1 `MlbEmpresa` por empresa); `empresasDePolos()` (l.2404) usa o carimbo `ml_oauth` e `pode_publicar => false`. As duas alimentam `Mlb/AnunciosEmpresas.jsx` (cards).
- O que reaproveitar: o **padrão** de `empresasDePolos()` (agregado por `groupBy`, `ativas()`, `link_reconexao` via `implementacao->token` → `route('implementacao.conectar-ml')`) e o componente de card/`TokenBadge` (`AnunciosEmpresas.jsx`: `TokenBadge`, `fmtData`).
- Efeito colateral importante: o `index` atual mostra empresas **de consultoria** (Gestão/Assessoria `Company`) que não são Polos/Incubadora; D13 as exclui da nova entrada. Ver Q4.

## 4. O módulo `/mlb/anuncios` atual: o que trocar e o que manter

[VERIFIED: codebase — `routes/mlb_anuncios.php`, `MlbAnuncioController` (2.467 linhas), `resources/js/Pages/Mlb/*`]

- **Rotas** (grupo `auth, verified, role:admin`, prefixo `mlb/anuncios`, nome `mlb.anuncios.`): `index` (l.28) → `Mlb/AnunciosEmpresas`; `meus/{company}`, `meus/{company}/atualizar`, `meus/{company}/{mlItemId}/detalhe`; **`wizard/{company}`** (l.45, `wizard()` controller l.84 → `Mlb/AnunciarML`); `massa/{company}`, `massa/meta/…`, `massa/{company}/produtos`; `historico/{company}`; rascunhos legados (`rascunho*`, `validar`, `publicar`, `publicar-duplo`, `duplicar-*`, `empresa/{company}/duplicar-lote`, `publicar-lote`); `meta/*` (prever categoria, atributos, tipos, compat); IA (`ia/analise` POST l.~`iaAnaliseStore`, `ia/analise/{analise}` GET).
- **Binding `{company}` = `Company` implícito.** Não há `Route::bind` em lugar nenhum (grep `app bootstrap routes`) ⇒ `empresa-<id>` (chave de `MlbEmpresa`) **não resolve** nas rotas `meus/massa/historico/wizard`. É por isso que `empresasDePolos()` marca `pode_publicar => false` e o `CardEmpresa` (`AnunciosEmpresas.jsx`) deixa de ser clicável. [VERIFIED]
- **A aba "Individual"**: `ModoAnuncioTabs.jsx` (`MODOS`, l.~19-24): `{chave:'individual', rota:'mlb.anuncios.wizard'}`; `trocarModo` faz `router.get(route(item.rota,{company: empresaId}))`. Usada por `AnunciarML`, `AnunciarMassa`, `AnunciosHistorico`, `MeusAnuncios`.
- **Quem aponta para `mlb.anuncios.wizard`** além da aba: `AnunciosEmpresas.jsx:128` (clique no card), `AnunciosHistorico.jsx:215` ("Anunciar semelhante"), `ModalDetalheAnuncio.jsx:284` e `MeusAnuncios.jsx:533` (abrir rascunho), `RascunhosPainel.jsx:113` (lista de rascunhos). E em testes: `AnuncioIaAnaliseTest`, `Phase75/*`, `Phase76/RascunhoPorProdutoTest` (+ POST `wizard/{company}/rascunho-por-produto`), `Phase134/RascunhosMeusAnunciosTest`, `tests/js/estrutura-meus-anuncios.test.js`.
- **"Anunciar por IA" hoje:** `PainelAnunciarIa.jsx` (469 linhas, dentro do `AnunciarML.jsx:2119`) → `POST mlb.anuncios.ia.analise.store` com `company_id` obrigatório e `exists:companies,id` (controller l.2219-2231; `abort_unless($company->mlToken !== null, 422)`) → `GerarAnaliseAnuncioIaJob` (fila `high`, etapas analise→titulos→descricao→ficha→**rascunho**) → etapa 5 chama `RascunhoAnuncioIaService::criarRascunho()` (l.527) que faz `MlAnuncioRascunho::create([... 'payload' => montarPayload(...)])` no **formato do wizard antigo** (payload ML cru + `meta_campos`). O front lê `rascunho.payload` via `preenchimentoIa()` (controller l.2191-2208, escopado por `company_id`) e `onAbrirRascunho` abre no wizard.
  - `MlAnuncioIaAnalise` **já tem `mlb_empresa_id`** (migration `2026_09_21_140000`, `company_id` nullable) e relação `mlbEmpresa()` — a análise em si (análise estratégica, títulos, descrição, ficha) não depende de `Company`; só `criarRascunho()` (devolve `null` se `company_id === null`) e a validação do endpoint.
  - **O que a IA produz em `$analise->resultado`:** `titulos[]`, `descricao`, `analise`, `ficha` = `{titulo, category_id, atributos:[{id,value_id|value_name…}], pacote:{peso_g,altura_cm,comprimento_cm,largura_cm,origem}, variacoes:[{attribute_combinations,attributes,…}], garantia, aviso}`, e `cliente` (preço/estoque/medidas da planilha).
  - **Para preencher o rascunho `pub_*`:** novo `IaParaRascunhoService::aplicar(MlAnuncioIaAnalise, PubRascunho)` que usa o `EditorRascunhoService` (não SQL direto): `trocarCategoria($r, ficha.category_id)` → `salvar($r, ['atributos' => …, 'alvos' => [titulo por listing_type], 'descricao' => …, 'garantia' => …])` (mapear `pacote` para `SELLER_PACKAGE_*` e `ficha.atributos` para o formato `{value_id,value_name,value_number…}` de `pub_rascunho_atributos`) → variações (`salvarEixos` + `salvarVariantes`). **Não** reutilizar `montarPayload()`/`criarRascunho()` (formato diferente). `iaAnaliseStore` ganha variante por produto (`produto_id` em vez de `company_id`), gravando `mlb_empresa_id`/`company_id` da análise a partir de `produto->conta()`. O `GerarAnaliseAnuncioIaJob` precisa de um ramo "aplicar no Publicador" na etapa 5 (hoje: `criarRascunho`), selecionado por um campo da análise (ex.: `resultado.destino = 'publicador'` ou coluna `produto_id`) — **o job é compartilhado com o wizard antigo, que continua vivo (Q3)**.
  - Risco: o formato `ficha.variacoes` é de variação ML ("attribute_combinations"), enquanto o Publicador modela eixos/valores/variantes com `combination_key`. Conversão não é trivial — **estimar como a tarefa mais incerta da fase** e considerar entregar a IA em duas fatias: (1) título/descrição/categoria/atributos/pacote/garantia; (2) variações. [ASSUMED: nenhuma conversão existente — não encontrei uma]
- **O que manter (D14):** `meus`, `massa`, `historico`, o motor antigo (`app/Services/Mlb/Publicacao/*`, `MlAnuncioRascunho`, `PublicarAnuncioMlJob`), rotas `rascunho*`/`publicar*`/`meta.*`/`ia.*`.
- **O que trocar:** a aba "Individual" (e o clique no card/entrada) passa a abrir o Publicador. A recomendação para `wizard`/`AnunciarML.jsx` está em Q3 (manter vivos nesta fase, só sem entrada principal; remoção em fase própria) — a leitura literal de D14 ("dão lugar") quebraria "Anunciar semelhante", a abertura de rascunhos e ~6 suítes sem ganho.

## 5. Sair do Portal (D18)

[VERIFIED: codebase]

**Rotas** (`routes/web.php`, grupo autenticado do portal):
- Anunciar antigo: `GET /estrutura/anunciar` (`portal.auth.estrutura.anunciar`, l.215), `GET /estrutura/anunciar/categorias` (l.216-217), `GET /estrutura/anunciar/categorias/{categoria}` (l.218-219), `GET|PUT /estrutura/ofertas/{oferta}/publicacao` (l.220-223), `POST …/publicacao/fotos|validar|publicar` (l.224-229).
- Piloto: `prefix('/estrutura/ofertas/{oferta}/publicador')` (l.234-262; 13 rotas `portal.auth.publicador.*`).
- `use App\Http\Controllers\PortalPublicadorController;` em `web.php:60`.

**Allowlist** (`app/Http/Middleware/RestringeDominioDoPortal.php:148-168`): 3 linhas `portal/estrutura/anunciar*`, 4 linhas `…/publicacao*`, 12 linhas `…/publicador*`. Remover **junto com** as rotas (uma sem a outra: rota órfã na allowlist = inofensiva; rota sem allowlist = 404 no domínio do portal). Os testes `DominioDoPortalTest`, `DominioLiberaTodoModuloTest`, `DominioDoLinkTest`, `LinkAbertoDeEquipeTest` referenciam o middleware — conferir se algum enumera essas linhas (grep sugere que não; rodar a suíte `tests/Feature/PortalCliente` na onda E).

**Controller/serviço:** `PortalPublicadorController` (292 linhas) some inteiro. `PortalEstruturaController`: remover `anunciarIndex` (l.194), `comProntidaoDoPublicador` (l.218), `abrirPublicacao`/`salvarPublicacao`/`fotoPublicacao`/`validarPublicacao`/`publicarPublicacao` (l.233-273), `categoriasAnunciar`/`categoriaAnunciar` (l.276-291) e a injeção de `EstruturaPublicacaoService`/`dadosPublicacao`. `EstruturaPublicacaoService` (950 linhas): **antes de apagar, extrair** `categorias()`+`categoriasEmLote()` (l.223-315) para `CategoriaBuscaService` (o editor interno precisa — hoje `EditorPublicador.jsx:141` chama `route('portal.auth.estrutura.anunciar.categorias')`). `montarItem` (l.826) e o resto saem. **Ficam:** o modelo `EstruturaPublicacao` e a tabela `estrutura_publicacoes` (a constante `LISTING_TYPES` é usada por `DadosEfetivosService`, `EditorRascunhoService`, `PublicacaoService` e `MigracaoAnunciarAntigo`), e `EstruturaOferta::publicacao()`.

**Menu e etapas:** `app/Support/Portal/ModulosPortal.php:113` — remover a linha `'anunciar' => [...]` de `SUBMODULOS`. O cabeçalho em trilho (`CabecalhoEstrutura`, `comum.jsx:335`) é **dirigido por dados** (`modulos` do `usePage().props` → `subs`); remover a entrada remove a etapa 6 sem tocar no componente. Ajustar o texto de `EstruturaAnuncios.jsx:20` ("é do submódulo Anunciar, que vem depois") e `EstruturaPrecificacao.jsx` só se citar o submódulo (o rótulo "Anunciar" em `EstruturaPrecificacao.jsx:150,172` é o **preço de anunciar**, não o submódulo — NÃO mexer).

**Front:** apagar `Pages/Portal/EstruturaAnunciar.jsx` (255 linhas) e `Components/Portal/Estrutura/FormPublicacao.jsx` (528 linhas). **Ficam** `Components/Portal/Estrutura/FotosDoPar.jsx` e `lib/fotosDoPar.js` (usados por `FotosPorGrupo.jsx`; `tests/js/fotos-do-par.test.js`). Rodar `npm run build` e conferir que o manifest não tem referência morta (memória: "página React que some do manifest do Vite" — checar `public/build/manifest.json`).

**Ordem:** só após o interno estar no ar e verificado (onda E). Enquanto isso o Portal continua publicando para a #459 (piloto). Sugestão: na onda E, em vez de apagar de uma vez, **remover primeiro as rotas+allowlist+menu** (reversível por `git revert`) e deixar a remoção de código morto (controller/serviço/JSX) para um commit seguinte.

## 6. Reuso dos componentes React do piloto no AppLayout interno

[VERIFIED: codebase — `resources/js/Components/Publicador/*` (1.360 linhas)]

- **Específico do Portal (a parametrizar):**
  1. `apoio.js:6` — `rota = (nome, ofertaId, extra) => route('portal.auth.publicador.'+nome, { oferta: ofertaId, … })` → trocar por uma fábrica injetável: `EditorPublicador` recebe `rotas` (prop) ou `criarRota(prefixo, paramChave)`; internamente: `route('mlb.anuncios.publicador.'+nome, { produto: id, … })`. As 13 chamadas `rota(...)` em `EditorPublicador.jsx` (l.278,285,307,330,393,399,409,417,421,428,435,538-540,755) passam por esse único ponto — mudança de 1 linha + prop.
  2. `EditorPublicador.jsx:141` — `route('portal.auth.estrutura.anunciar.categorias')` → prop `rotaCategorias` (nova rota interna `mlb.anuncios.publicador.categorias`, que usa `CategoriaBuscaService`). Alternativa pronta: `route('mlb.anuncios.meta.prever')` já existe mas devolve `{category_id,category_name,path?}` (formato diferente: o editor espera `{id,nome,caminho[]}`) — preferir a nova rota para manter o contrato.
  3. Props/nomes: `ofertaId`, `seletorOferta`, `estado.oferta.{id,sku,nome}` (l.131,224,460-461) → renomear para `produtoId`, `seletor`, `estado.produto` (acompanhando a chave do estado do §1); textos "Abrindo a oferta…"/"Não foi possível abrir a oferta." (l.348,351).
- **Importam de `Components/Portal/Estrutura/comum`** (puros: `Botao`, `Campo`, `CLASSE_INPUT`, `Seletor`, `LinkMl`, `fmtReais`): não usam rotas nem contexto do portal; `usePage().props.modulos` só aparece em `CabecalhoEstrutura` (que o Publicador não usa). Funcionam dentro do `AppLayout`. Opcional: mover para `Components/ui` depois — não nesta fase.
- **Estilo:** o editor já usa tokens `ecf-*` e `white/[0.0x]` (mesmo dark theme do interno). `AppLayout` ≈ `PortalClienteLayout` no tema; revisar largura (`max-w-[1180px]`), sidebar e "Dock" só na conferência visual (critério 8).
- **Conferência visual sem tocar no MariaDB compartilhado:** receita do learnings §7 (SQLite arquivo + seed por script que recusa rodar fora de SQLite + `php -S` de dentro de `public/`; `node_modules` por junção). Para o AppLayout interno a sessão é de `User` admin (guard `web`), mais simples que a do portal.
- **Testes JS de fonte** (padrão `tests/js/*.test.js` + `_fonte.js`): criar um para garantir que `apoio.js` não contém mais `portal.auth` e que as rotas internas existem em `routes/mlb_anuncios.php`.

## 7. Validação (arquitetura de testes, estilo Nyquist)

### Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.5.55 (PHP 8.2.12) + `node --test` para `tests/js` |
| Config | `phpunit.xml` (SQLite `:memory:`) |
| Comando rápido (baseline do motor) | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` — **230 testes / 952 asserções, OK, 16 s** [VERIFIED: rodado] |
| Suíte completa | **não rodar de uma vez** (estoura 512 MB — memória `project_metas_dev_v3_fase158`); rodar por pastas: `PortalCliente`, `Phase75`, `Phase76`, `Phase77`, `Phase134`, `Feature/Anuncio*`, `MlToken*` |
| JS | `npm run test:js` |
| Cuidado | `\| tail` engole o exit code do phpunit (usar `${PIPESTATUS[0]}`); o autoloader do composer pode apontar para outra worktree (memória `project_composer_autoloader_worktree`) — aqui `vendor/` é do worktree e o rodado passou |

### Mapa critério → teste
| Critério (ROADMAP) | Comportamento | Tipo | Comando / arquivo | Existe? |
|---|---|---|---|---|
| 1 | Entrada lista só empresas de Polos/Incubadora com conta ML; Incubadora por `tipo`/`projeto`/`fase`; arquivada fora; situação do Portal; não-admin 403 | Feature | `tests/Feature/Publicador/MlbPublicadorEntradaTest.php` | ❌ Wave 0 |
| 2 | "Sincronizar do Portal": cria só ofertas sem produto; 2ª chamada não duplica (unique `oferta_id`); nunca apaga; sem `company_id` ⇒ recusa | Feature + Unit do serviço | `…/SincronizaPortalTest.php` | ❌ |
| 3 | Produto com `oferta_id` herda título/preço ao vivo (muda Precificação ⇒ muda efetivo); digitado vence; publicar cadastra o MLB na aba Anúncios; sem `oferta_id` NÃO cadastra | Feature | adaptar `DadosEfetivosTest` + `PublicacaoTest` (`cadastrarNaRegua`) | parcial |
| 4 | **`MlbEmpresa` sem `Company` publica** com token em `mlb_empresa_id` (`Http::fake`): abrir → conferir → publicar; `ensureValidToken` usa a âncora certa; sem token ⇒ `V-ACC-01` | Feature | `…/PublicaMlbEmpresaSemCompanyTest.php` (cenário nos moldes de `CenarioCadeira`) | ❌ |
| 5 | Migration: roda no SQLite; **backfill** de rascunhos legados (migrar até A, inserir 2 `pub_rascunhos` sem `produto_id`, rodar B ⇒ 2 produtos origem `portal`, `produto_id` preenchido, abrem pelo produto); idempotente (2ª execução); `down` recusa com produto sem oferta | Feature (migration) | `tests/Feature/Publicador/MigracaoProdutoRascunhoTest.php` | ❌ |
| 5 (MariaDB) | `SHOW CREATE TABLE`/`SHOW INDEX` conforme §2.3 | **manual** (justificado: SQLite não reproduz 1553/1059/1830) | roteiro no VERIFICATION | — |
| 6 | Meus/Massa/Histórico seguem funcionando; aba Individual leva ao Publicador; botão IA grava em `pub_*` e **não** cria `MlAnuncioRascunho` | Feature + JS | `AnunciosPolosNaListagemTest`, `Phase134/RascunhosMeusAnunciosTest`, `AnuncioIaAnaliseTest` adaptados + `tests/js/estrutura-meus-anuncios.test.js`; novo `IaParaRascunhoTest` (com `Http::fake`/modelo falso, **sem** chamar IA real) | parcial |
| 7 | Rotas do Portal respondem 404 e saem da allowlist; submódulos = 5; Lista SKUs/Precificação/Anúncios/Planejamento/Mapeamento intactos | Feature | `AcessoAoModuloEstruturaTest` (ajustar), novo `PortalSemAnunciarTest` (`get` das 3 famílias de rota ⇒ 404), suíte `tests/Feature/PortalCliente` inteira | parcial |
| 8 | Layout conferido no navegador, sem erro de console | **manual** (justificado: renderização) | receita learnings §7 + Puppeteer; lembrar que headless força `prefers-reduced-motion` (memória) | — |
| Segurança | Produto de outra empresa/arquivada ⇒ 404; `{produto}` inexistente ⇒ 404; não-admin ⇒ 403; trava de conta liberada (Q2) ⇒ `RegraViolada` | Feature | `MlbPublicadorAcessoTest` | ❌ |

### Amostragem
- **Por commit de tarefa:** `phpunit tests/Unit/Publicador tests/Feature/Publicador` (16 s) + o teste novo da tarefa.
- **Por onda:** + `phpunit tests/Feature/PortalCliente` (onda E), `tests/Feature/Phase75 Phase76 Phase77 Phase134`, `AnuncioIaAnaliseTest`, `AnunciosPolosNaListagemTest`, `MlTokenAncoraPolosTest`, `npm run test:js`.
- **Gate de fase:** baseline (230/952) igual ou maior, zero regressão nas pastas acima, `npm run build` com manifest conferido, roteiro manual MariaDB + conferência visual.
- **Regra de teste de mutação (learnings §5/§8):** os testes com `Http::fake` novos registram o fake UMA vez; conferir com uma mutação que o teste quebra (ex.: apontar a âncora errada de token ⇒ falha).

### Lacunas Wave 0
- [ ] `tests/Feature/Publicador/Concerns/CenarioCadeira.php` — migrar para `PubProduto` (base de 31 testes)
- [ ] helper/factory para `PubProduto` e para `MlbEmpresa` com `MlToken` (`mlb_empresa_id`) — hoje não há factory de `PubProduto`
- [ ] testes novos listados acima (entrada, sincronizar, MlbEmpresa sem Company, migration, acesso, portal sem Anunciar, IA→pub_*)
- [ ] baseline registrada: copiar a contagem 230/952 para `160-BASELINE-TESTES.md` **e** medir as pastas de §7 antes de mexer (CLAUDE.md exige baseline nesta classe de fase)

## 8. Riscos e perguntas que o planejador NÃO deve decidir sozinho

1. **Q1 — A `#459` aparece onde?** O E2E (e qualquer teste real) só pode ser na #459, que é uma `Company` criada para o piloto. A nova entrada lista `MlbEmpresa` de Polos/Incubadora; se a #459 não tem `MlbEmpresa` nesses programas (não verificado — proibido consultar produção), o módulo novo **não a alcança**. Opções: (a) criar/vincular uma `MlbEmpresa` "Dev 02 Testes API" no programa certo (em produção, por ação do usuário — a memória diz que o onboarding #38 está em andamento); (b) rota/atalho admin para abrir produto de qualquer `Company` com token. **Perguntar ao usuário** e, se (a), registrar como pré-requisito do E2E, não da fase. Lembrar: a backfill põe os 2 rascunhos em `pub_produtos.company_id = 459` — a lista por `MlbEmpresa` só os mostra se a consulta também casar `pub_produtos.company_id = mlbEmpresa.company_id` (recomendado: `WHERE mlb_empresa_id = X OR (company_id = X.company_id AND company_id IS NOT NULL)`).
2. **Q2 — Trava de "conta de cliente nunca publica".** Hoje `publicador.empresas_piloto` (`config/publicador.php:57`, env `PUBLICADOR_EMPRESAS_PILOTO`, default `459`) é a única barreira, aplicada em `PublicacaoService::iniciar` (l.66-70) por `company_id`. Abrir para Polos/Incubadora (~535 contas reais) com `role:admin` **remove** o freio. Perguntar: manter uma lista de contas liberadas (por `company_id` **e** `mlb_empresa_id`) até o usuário liberar cada uma, ou liberar geral? Recomendação: manter a trava, generalizada para as duas âncoras, default só #459, e tratá-la como decisão explícita de produto. `PublicacaoTest.php:188-191` (`fora do piloto não publica`) deve ser preservado/adaptado, não apagado.
3. **Q3 — Destino de `wizard`/`AnunciarML.jsx`.** D14 diz "dão lugar", mas o `wizard` é alvo de links em Histórico ("Anunciar semelhante"), Meus Anúncios e Rascunhos, e do POST `wizard/{company}/rascunho-por-produto`, e de 5+ suítes. Recomendação: nesta fase **só trocar a aba "Individual" e o clique de entrada**; manter `wizard` vivo (inclusive para abrir `MlAnuncioRascunho` abertos) e remover em fase posterior com contagem de produção (leitura). Confirmar com o usuário e, se necessário, contar `ml_anuncio_rascunhos` abertos **só por leitura**.
4. **Q4 — Contas `Company` sem Polos/Incubadora e abas irmãs.** (a) A nova entrada (D13) exclui `Company` de consultoria (Gestão/Assessoria) que hoje aparecem no `index`; elas perdem o acesso a Meus/Massa/Histórico? (b) Para `MlbEmpresa` **sem** `Company` as abas Meus/Massa/Histórico não resolvem (`{company}`); `ModoAnuncioTabs` precisa esconder/desabilitar essas abas nesse caso. Perguntar se o `index` antigo (cards) continua acessível (ex.: aba "Outras contas" ou rota separada).
5. **Q5 — Exclusão de oferta.** `pub_rascunhos.oferta_id` tem `cascadeOnDelete` hoje (`test_excluir_a_oferta_leva_o_rascunho`). Em `pub_produtos.oferta_id`: cascade (preserva o comportamento, mas apagar uma oferta apaga o produto e **o histórico de publicação**), `restrict`, ou `nullOnDelete` (coluna nullable ⇒ válido em MariaDB; o produto vira "cadastrado no Publicador" e o rascunho sobrevive)? Recomendação: `cascade` na A para manter o teste/semântica, mas é decisão de produto.
6. **Q6 — Duplicidade de SKU / "Não tenho".** O Portal já tem SKUs repetidos por design (identidade é `oferta_id`). Em produtos "do Publicador" o SKU pode repetir? Recomendo **não** impor unique de SKU (só aviso V-REM-02 já existente), mas confirmar.
7. **Q7 — Estados de sincronização.** O doc 17 lista "sincronizado / nunca / sem Portal"; falta o intermediário "ofertas novas desde a última sincronização". Definir rótulo e se o botão aparece nos dois estados. Também: `nome`/`sku` do produto de origem `portal` seguem a oferta ao vivo? (recomendado) ou são copiados uma vez?
8. **Q8 — Uma `Company` com duas `MlbEmpresa`** (Polos + Incubadora; `aplicarRoteamento` cria uma ficha por tipo quando sem guarda). A oferta só pode estar em **um** `pub_produto` (unique). A qual programa pertence? Recomendação: o produto de origem `portal` nasce na `MlbEmpresa` de onde o usuário clicou "Sincronizar"; outro programa vê "já sincronizado em X". Confirmar.
9. **Q9 — `MigracaoAnunciarAntigo`/`PublicadorMigrarAnunciar` e `estrutura_publicacoes`.** Com D18 o Anunciar antigo sai; ainda há rascunhos em `estrutura_publicacoes` em produção? (não consultado). Manter o código de migração (adaptado a `PubProduto`) ou aposentá-lo. Tabela e modelo ficam de qualquer forma.
10. **Q10 — UI-SPEC/Stitch.** O CONTEXT cita "layout do Stitch aprovado (UI-SPEC)", mas **não existe** UI-SPEC na pasta da fase e `workflow.ui_phase = true`. Memória: Stitch só sob demanda (o usuário pediu aqui explicitamente: projeto "ECF Admin — Identidade", design system "ECF Admin Dark"; atenção: "timeout ≠ falha — a tela chega depois, não reenviar"). Rodar `/gsd-ui-phase 160` antes do plano das telas ou planejar as telas atrás de um checkpoint de design.
11. **Q11 — Produtos do Onboarding de Polos.** `MlbImplementacao.dados.itens.planilha_produtos` já guarda os produtos do cliente de Polos (usado por `montarProdutosDoCliente`, controller l.1889, e pelo wizard antigo `rascunho-por-produto`). D15 manda cadastrar à mão; importar da ficha seria um atalho natural, mas **não está** em D12–D19. Perguntar se entra (provavelmente não; registrar como ideia adiada).
12. **Q12 — Qual `mlb_empresa_id`/`company_id` o produto cadastrado à mão grava** quando a `MlbEmpresa` tem `Company` **e** token só em `company_id`? Recomendo gravar ambos e deixar `conta()` escolher a âncora com token (Padrão 1).
- **Risco R1 (alto):** IA→`pub_*` (§4): conversão de `ficha.variacoes`; planejar em duas fatias.
- **Risco R2 (médio):** `ModoAnuncioTabs` compartilhado — mudar a aba "Individual" afeta 4 telas e 1 teste JS de fonte.
- **Risco R3 (médio):** worktree: `npm run build` pode sair 0 sem buildar; `ASSET_URL` vazio (memórias); `MlbEmpresa.company_id` pode estar nulo para #459.
- **Risco R4 (baixo):** `->change()` em SQLite reconstrói a tabela — em testes as tabelas estão vazias; sem risco de dado. [ASSUMED: comportamento do Laravel 12 em SQLite com FKs filhas — o precedente `ml_tokens` roda nos testes]
- **Risco R5 (baixo):** módulo oculto `/incubadora/publicador` (`IncubadoraPublicadorController`, `routes/incubadora_publicador.php`, `Modules::INCUBADORA_PUBLICADOR`) é **outra coisa** (sugestão de categoria/termos mais buscados, Dev-only). Não confundir nem reaproveitar rotas/nomes; `mlb.anuncios.publicador.*` não colide com `incubadora.publicador.*`. [VERIFIED: codebase]

## Armadilhas comuns

### Pitfall 1: Migration única "tudo em um" deixa estado quebrado
**O que dá errado:** `Schema::create` morre no índice e a migration fica `Pending` com a tabela existente (learnings §6). **Como evitar:** A (create) separada de B (alter+backfill); guards por `hasIndex`; sem try/catch. **Sinal:** `migrate:status` mostra `Pending` e `SHOW INDEX` não mostra `pubprod_oferta_uq`.

### Pitfall 2: Dropar o unique que sustenta a FK (erro 1553)
**Como evitar:** não tocar em `pubr_oferta_uq`/`pubr_oferta_fk`. Se algum dia precisar: criar o novo ANTES de dropar o antigo.

### Pitfall 3: Token lido pela âncora errada
`ensureValidToken(Company)` de uma empresa cujo token está em `mlb_empresa_id` ⇒ "sem token". `PubProduto::conta()` escolhe a âncora com token; teste com `MlbEmpresa` sem `Company` e com `MlbEmpresa` + `Company` (token em cada uma).

### Pitfall 4: `$r->oferta` esquecido em um serviço
São 8 serviços/arquivos (§1). Fazer `grep -rn "oferta->company\|->oferta\b" app/Services/Publicador app/Jobs` retornar **vazio** como critério de aceite da onda B (exceto `cadastrarNaRegua`/`DadosEfetivos` sob `if oferta_id`).

### Pitfall 5: Buscar categoria some com o `EstruturaPublicacaoService`
Extrair `CategoriaBuscaService` **antes** de apagar o serviço; o teste de sugestões (`AnunciarEstruturaTest.php:291`) migra junto.

### Pitfall 6: Meus/Massa/Histórico com `MlbEmpresa`
`route('mlb.anuncios.meus', { company: 'empresa-5' })` ⇒ 404. Tratar na aba (Q4).

### Pitfall 7: `Http::fake` que muda no meio / `assertExists` com 2º argumento
Learnings `publicador-ml` §5 e §8. Valem para todos os testes novos.

### Pitfall 8: Prod ≠ SQLite
Tudo que é DDL precisa da verificação manual de §2.3; CLAUDE.md: "conferir consolidação por reconsulta ao banco". E: **não** semear o MariaDB local compartilhado.

## Exemplos de código

### `PubProduto` (esqueleto)
```php
// app/Models/PubProduto.php — comentários em pt-BR (convenção do projeto)
class PubProduto extends Model
{
    protected $table = 'pub_produtos';
    protected $guarded = ['id'];

    public const ORIGEM_PORTAL = 'portal';
    public const ORIGEM_PUBLICADOR = 'publicador';

    public function mlbEmpresa(): BelongsTo { return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function oferta(): BelongsTo { return $this->belongsTo(EstruturaOferta::class, 'oferta_id'); }
    public function rascunho(): HasOne { return $this->hasOne(PubRascunho::class, 'produto_id'); }

    /** A conta do ML que publica este produto: a âncora que TEM token. */
    public function conta(): ContaMercadoLivre
    {
        foreach ([$this->mlbEmpresa, $this->company] as $ancora) {
            if ($ancora?->mlToken && $ancora->mlToken->status !== 'revoked') {
                return $ancora;
            }
        }
        throw new RegraViolada('V-ACC-01', 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.');
    }
}
```
(Fonte: padrão derivado de `MlbEmpresa.php:125-145`, `Company.php:622-646`, `MercadoLivreService.php:308-314`.)

### Sincronizar do Portal (idempotente)
```php
// Uma linha por oferta sem produto; nunca apaga. A unique pubprod_oferta_uq fecha a corrida.
foreach ($company->estruturaOfertas /* ou EstruturaOferta::where('company_id', …) */ as $o) {
    PubProduto::firstOrCreate(
        ['oferta_id' => $o->id],
        ['company_id' => $company->id, 'mlb_empresa_id' => $empresa->id,
         'sku' => $o->sku, 'nome' => $o->nome ?? $o->sku, 'origem' => PubProduto::ORIGEM_PORTAL],
    );
}
// Em corrida: capturar QueryException 23000 e seguir (já existe).
```

### Rota parametrizável no front
```js
// apoio.js — antes: route(`portal.auth.publicador.${nome}`, { oferta: id })
export const criarRota = (prefixo, chave) => (nome, id, extra = {}) => route(`${prefixo}.${nome}`, { [chave]: id, ...extra });
// uso interno: criarRota('mlb.anuncios.publicador', 'produto')
```

## State of the Art

| Antes | Agora | Quando | Impacto |
|---|---|---|---|
| Rascunho por oferta do Portal (`oferta_id` NOT NULL) | Rascunho por `pub_produto` (âncora de produto própria) | 02/10 (D15) | Empresa sem `Company` publica |
| Anunciar do cliente no Portal | Anunciar interno, só equipe | 02/10 (D12/D18) | Publicar deixa de ser do cliente; remover piloto |
| `MercadoLivreService` só com `Company` | `ContaMercadoLivre` (Company + MlbEmpresa) | 21/09 | Já pronto para o motor |
| IA gravava `MlAnuncioRascunho` | IA precisa gravar `pub_*` | esta fase | Nova etapa 5 do job |

**Obsoleto após a fase:** `PortalPublicadorController`, `EstruturaPublicacaoService` (como tela), `FormPublicacao.jsx`, `EstruturaAnunciar.jsx`, `publicador.empresas_piloto` como conceito de "piloto do Portal" (vira lista de contas liberadas — Q2).

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-----------------|
| A1 | PKs de `mlb_empresas`, `companies`, `estrutura_ofertas` são `unsignedBigInteger` (`id()`), compatíveis com `foreignId()` | §2.2 | Erro 150/3780 na migration A; verificar com `SHOW CREATE TABLE` antes |
| A2 | MariaDB local (`.env`: mysql/`ecf_admin`) está de pé e com `2026_10_01_200000` aplicada | §2.3 | Roteiro manual muda (aplicar a anterior primeiro) |
| A3 | `->change()` para nullable em coluna com FK funciona no MariaDB de produção (há precedente `ml_tokens` já aplicado em prod) | §2.2 | Trocar por `ALTER TABLE … MODIFY` cru no branch mysql |
| A4 | `->change()` em SQLite (testes) reconstrói a tabela sem perder unique/FK de `pub_rascunhos` | R4 | Testes de migration falham; usar branch SQLite |
| A5 | #459 não tem `MlbEmpresa` em Polos/Incubadora | Q1 | Se tiver, Q1 some; se não, E2E precisa de pré-requisito |
| A6 | Não existe conversão `ficha.variacoes` (IA) → eixos/variantes do Publicador | §4 | Se existir, a tarefa da IA diminui |
| A7 | Polos em produção usam `projeto='POLOS'` ou `fase` M0–M4; Incubadora usa `tipo='INCUBADORA'` (Router) e/ou `projeto/fase='Incubadora'` | §3.1 | Empresa some da lista; medir com `COUNT` por critério (somente leitura) antes de fixar o scope |
| A8 | Não há rascunhos úteis em `estrutura_publicacoes` em produção | Q9 | Se houver, manter a migração adaptada |
| A9 | Nenhum teste enumera as linhas da allowlist do portal | §5 | Ajustar o teste na onda E |

## Perguntas em aberto
Ver §8 (Q1–Q12). As de maior impacto no desenho: **Q1** (acesso à #459), **Q2** (trava de contas liberadas), **Q3** (destino do `wizard`), **Q4** (abas irmãs para `MlbEmpresa` sem `Company`), **Q10** (UI-SPEC/Stitch).

## Disponibilidade do ambiente

| Dependência | Necessária para | Disponível | Versão | Fallback |
|---|---|---|---|---|
| PHP | testes/migrations | ✓ | 8.2.12 (`C:\xampp\php\php.exe`) | — |
| PHPUnit | testes | ✓ | 11.5.55 | — |
| Node | build/testes JS | ✓ | v26.9.0 (CLAUDE.md cita v24; funciona) | — |
| `vendor/`, `node_modules/` no worktree | testes/build | ✓ | presentes | junção de outro worktree (learnings §7) |
| MariaDB local (`.env` → mysql `ecf_admin`) | verificação real da migration | não sondado | — | SQLite para testes + roteiro manual `SHOW CREATE TABLE`; NÃO semear (compartilhado) |
| ML API / VPS / produção | — | **proibido** nesta pesquisa | — | `Http::fake`; leituras de produção só pelo usuário |
| Stitch MCP | layout (Q10) | não verificado | — | `/gsd-ui-phase`; tela só sob demanda do usuário |

**Sem fallback e bloqueando:** nenhum para as ondas A–D. Verificação MariaDB real depende de Q-A2.

## Domínio de segurança

| Categoria ASVS | Aplica | Controle |
|---|---|---|
| V2 Autenticação | não muda | sessão do `auth, verified` |
| V3 Sessão | não muda | — |
| V4 Controle de acesso | **sim** | `role:admin` no grupo (D17); `{produto}` resolvido por `findOrFail` + checagem de que o produto pertence a empresa de programa; trava de contas liberadas (Q2) — IDOR entre empresas é de baixo risco (só admin), mas a trava protege **contas reais de cliente** |
| V5 Validação de entrada | **sim** | `$request->validate()` no controller (copiar as regras do `PortalPublicadorController`); `categoria_id` por regex `^MLB[0-9]+$`; upload de foto `max:10240`; SKU/nome no cadastro manual |
| V6 Criptografia | não muda | tokens cifrados pela `APP_KEY`; **nunca** logar `access_token` (o `ClienteMlPublicador::registrar` já omite) |

| Padrão de ameaça | STRIDE | Mitigação |
|---|---|---|
| Publicar em conta de cliente real por engano | Tampering | trava de contas liberadas + confirmação humana (Q2), `PUBLICADOR_EMPRESAS_PILOTO` |
| Rota do Portal esquecida após D18 | Elevation | remover rota **e** allowlist; teste 404 |
| Enumeração de `produto` por id sequencial | Info disclosure | só admin; 404 quando o produto não pertence a empresa de programa |
| Duplo clique em Publicar / reentrega do Job | Tampering | já coberto: lock por publicação, SENT antes do POST (não alterar) |
| Vazamento de token em log/resposta | Info disclosure | `access_token` é `hidden`; respostas do editor não incluem token (conferir `estado()`) |

## Fontes

### Primárias (ALTA)
- Código do worktree `C:/tmp/ecf-publicador-spec-261001` (todas as referências arquivo:linha acima).
- `.planning/phases/160-…/160-CONTEXT.md`, `.planning/publicador-ml-spec/17-publicador-interno.md`, `…/16-analise-do-portal.md`.
- `.planning/learnings/publicador-ml.md`, `.planning/learnings/desempenho-bonificacao.md` §6.
- Molde de migration: `database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php`; precedente de `->change()` com FK: `2026_09_21_120000_alter_ml_tokens_add_mlb_empresa_anchor.php`.
- PHPUnit rodado: `tests/Unit/Publicador` + `tests/Feature/Publicador` → 230 testes, 952 asserções, OK.

### Secundárias (MÉDIA)
- Memória do projeto (`MEMORY.md` e arquivos citados): regras de #459, Incubadora sem tokens, deploy, autoloader, `| tail`.

### Terciárias (BAIXA)
- Nenhuma. Não houve pesquisa web (a fase é 100 % interna ao repositório); documentação de framework não foi necessária além do comportamento já usado no repo.

## Metadados

**Confiança:**
- Stack: ALTA — nenhuma dependência nova.
- Mapa de acoplamento/arquitetura: ALTA — lido e conferido por grep; 230 testes verdes como baseline.
- Migration: ALTA no desenho (molde do repo); MÉDIA na execução MariaDB (A1–A4 não verificados em banco real).
- IA→`pub_*`: MÉDIA/BAIXA no esforço (A6).
- Dados de produção (contagens, #459): não consultados.

**Data da pesquisa:** 2026-10-02
**Válido até:** 2026-10-16 (código muda rápido: outras sessões editam `MlbController`, `HandleInertiaRequests` etc. na mesma árvore; reconferir `routes/mlb_anuncios.php` e `web.php` antes de planejar a onda E).

## RESEARCH COMPLETE
