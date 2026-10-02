---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
reviewed: 2026-10-02
depth: standard
diff_base: 5f142cb8
escopo: duas revisões paralelas (backend 34 arquivos, frontend 41 arquivos) — escopo de 75 arquivos de produção acima do limite de 50 de uma passada
files_reviewed: 75
files_reviewed_list:
  - app/Console/Commands/PublicadorEmpresaTeste.php
  - app/Http/Controllers/MlbAnuncioController.php
  - app/Http/Controllers/MlbPublicadorController.php
  - app/Http/Controllers/MlbPublicadorEntradaController.php
  - app/Http/Controllers/PortalEstruturaController.php
  - app/Http/Middleware/RestringeDominioDoPortal.php
  - app/Jobs/GerarAnaliseAnuncioIaJob.php
  - app/Models/EstruturaPublicacao.php
  - app/Models/MlAnuncioIaAnalise.php
  - app/Models/MlbEmpresa.php
  - app/Models/PubProduto.php
  - app/Models/PubRascunho.php
  - app/Services/Portal/Estrutura/EstruturaOfertaService.php
  - app/Services/Publicador/CategoriaBuscaService.php
  - app/Services/Publicador/ClienteMlPublicador.php
  - app/Services/Publicador/ConferenciaService.php
  - app/Services/Publicador/ContaMlService.php
  - app/Services/Publicador/DadosEfetivosService.php
  - app/Services/Publicador/EditorRascunhoService.php
  - app/Services/Publicador/IaParaRascunhoService.php
  - app/Services/Publicador/ImagemAssetService.php
  - app/Services/Publicador/MigracaoAnunciarAntigo.php
  - app/Services/Publicador/ProgramasPublicadorService.php
  - app/Services/Publicador/PublicacaoService.php
  - app/Services/Publicador/PublicadorSincronizaPortalService.php
  - app/Services/Publicador/RascunhoRepository.php
  - app/Services/Publicador/SoltarProdutoDaOfertaService.php
  - app/Support/Portal/ModulosPortal.php
  - app/Support/Publicador/ContasLiberadas.php
  - config/publicador.php
  - database/migrations/2026_10_02_100000_create_pub_produtos_table.php
  - database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php
  - routes/mlb_anuncios.php
  - routes/web.php
  - resources/js/Components/Mlb/Publicador/AvisoContaTravada.jsx
  - resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx
  - resources/js/Components/Mlb/Publicador/IndicadoresDoPrograma.jsx
  - resources/js/Components/Mlb/Publicador/LinkReconexao.jsx
  - resources/js/Components/Mlb/Publicador/ModalNovoProduto.jsx
  - resources/js/Components/Mlb/Publicador/PainelComoFunciona.jsx
  - resources/js/Components/Mlb/Publicador/SeletorPrograma.jsx
  - resources/js/Components/Mlb/Publicador/SeloConta.jsx
  - resources/js/Components/Mlb/Publicador/SeloPortal.jsx
  - resources/js/Components/Mlb/Publicador/SeloStatusProduto.jsx
  - resources/js/Components/Mlb/Publicador/tempo.js
  - resources/js/Components/Portal/Estrutura/FotosDoPar.jsx
  - resources/js/Components/Publicador/CampoAtributo.jsx
  - resources/js/Components/Publicador/EditorDeEixos.jsx
  - resources/js/Components/Publicador/FotosPorGrupo.jsx
  - resources/js/Components/Publicador/GradeVariantes.jsx
  - resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx
  - resources/js/Components/Publicador/Mesa/BotaoAnunciarPorIa.jsx
  - resources/js/Components/Publicador/Mesa/CardDescricao.jsx
  - resources/js/Components/Publicador/Mesa/CardFichaTecnica.jsx
  - resources/js/Components/Publicador/Mesa/CardFotos.jsx
  - resources/js/Components/Publicador/Mesa/CardLogistica.jsx
  - resources/js/Components/Publicador/Mesa/CardProduto.jsx
  - resources/js/Components/Publicador/Mesa/CardTiposEPrecos.jsx
  - resources/js/Components/Publicador/Mesa/CardVariacoes.jsx
  - resources/js/Components/Publicador/Mesa/CartaoVariante.jsx
  - resources/js/Components/Publicador/Mesa/FaixaDeProdutos.jsx
  - resources/js/Components/Publicador/Mesa/LateralResumo.jsx
  - resources/js/Components/Publicador/Mesa/LateralValidacao.jsx
  - resources/js/Components/Publicador/Mesa/comum.jsx
  - resources/js/Components/Publicador/Problemas.jsx
  - resources/js/Components/Publicador/apoio.js
  - resources/js/Components/Publicador/derivados.js
  - resources/js/Components/Publicador/useIaDoPublicador.js
  - resources/js/Components/Publicador/usePublicador.js
  - resources/js/Pages/Mlb/AnunciosEmpresas.jsx
  - resources/js/Pages/Mlb/ModoAnuncioTabs.jsx
  - resources/js/Pages/Mlb/Publicador/Editor.jsx
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx
  - resources/js/Pages/Portal/EstruturaAnuncios.jsx
  - resources/js/lib/fotosDoPar.js
status: issues_found
findings:
  critical: 4
  warning: 23
  info: 16
  total: 43
---

# Code review — Fase 160 (Publicador no sistema interno)

Relatório único montado pelo orquestrador a partir de duas revisões independentes.
IDs com **B** vêm do backend (app, config, rotas, migrations); com **F**, do frontend (React).

Conferidos pelo orquestrador direto no código antes de consolidar: **CR-B01** (trava D21 só em
`PublicacaoService::iniciar`; `processar`/`enviar`/`reenviarDescricao` resolvem `$r->conta()` de novo
sem `ContasLiberadas::exigir`), **CR-B02** (`pubprod_empresa_fk`/`pubprod_company_fk` em
`cascadeOnDelete` levando `pub_rascunhos → pub_publicacoes → pub_publicacao_itens`) e **CR-F01**
(`atribuirFotos`/`removerFoto`/`reenviarFoto` com `tudo: true` sem `descarregar()` antes).

---

# Parte 1 — Backend

# Fase 160: Relatório de Code Review (backend)

**Revisado em:** 2026-10-02T19:08:33Z
**Profundidade:** standard (com leitura das chamadas que cruzam módulos onde a prioridade pedia: job de publicação, `MercadoLivreService::refreshToken`, FKs de `mlb_empresas`, `MlbController::destroyEmpresa`)
**Arquivos revisados:** 34 (worktree `C:/tmp/ecf-publicador-spec-261001`, `git diff 5f142cb8..HEAD`)
**Status:** issues_found

## Resumo

O núcleo da trava D21/D26 está no lugar nos pontos de ENTRADA: `PublicacaoService::iniciar()` exige a conta liberada, `ConferenciaService::conferir()` cai na conferência local, `consultarCondicionais()` e `ImagemAssetService::enviarAoMl()` não escrevem em conta não liberada. Autorização: todas as rotas novas estão no grupo `auth, verified, role:admin`; foto, item e atribuição de foto são achados pelo rascunho do produto autorizado (sem IDOR); nenhum token do ML sai em JSON, props ou log. A saída do Anunciar do Portal (D18) está limpa: rotas, allowlist, submódulo e referências de front/back removidos, sem rota órfã chamada por Ziggy. A migration com dado de produção é idempotente e o backfill zera `pub_rascunhos.oferta_id`, o que de fato tira o rascunho da cascata `pubr_oferta_fk` (D27).

Os dois problemas que custam caro:

1. **A trava D21 só vale no clique.** O job de publicação (todas as fatias depois da primeira, a reconciliação que reenvia e o "reenviar descrição") resolve a âncora do token DE NOVO a cada fatia e nunca chama `ContasLiberadas`. Uma troca de âncora entre fatias (token da `MlbEmpresa` criado ou revogado por outro processo, ou a conta tirada da lista) faz `POST /items` cair numa conta não liberada — inclusive de outro vendedor que não foi o conferido.
2. **A nova FK `pubprod_empresa_fk` (e `pubprod_company_fk`) é CASCADE.** Excluir uma `MlbEmpresa` — ação de gestor/líder em `DELETE /mlb/empresas/{empresa}`, sem `role:admin` — apaga produto → rascunho → publicações → itens (o `ml_item_id`, o payload e a resposta crua do ML). O motor antigo escolheu `nullOnDelete` exatamente para isso. Corrigir agora é trocar uma linha; depois do deploy é DDL em FK de tabela com dado (família do 1553).

As advertências tratam de divergência entre a conta que a tela mostra e a que publica, corridas da IA no rascunho (D14) e de `abrir()`, programa derivado de jeito diferente no SQL (case-insensitive no MariaDB) e no PHP, o caminho "sem token" que quebra a regra D26 de "só confere local", e um N+1 na lista de produtos.

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-B01: A trava D21 não é reaplicada no job nem no reenvio de descrição; a âncora do token não fica fixada entre o clique e o POST

**Arquivos:**
- `app/Services/Publicador/PublicacaoService.php:71` (único `ContasLiberadas::exigir`)
- `app/Services/Publicador/PublicacaoService.php:111-149` (`executarFatia`: cada fatia faz `PubRascunho::findOrFail`, ou seja, recarrega produto, empresa e token)
- `app/Services/Publicador/PublicacaoService.php:207-213` (`prepararItens`: compara só `modelo`, não o vendedor)
- `app/Services/Publicador/PublicacaoService.php:313`, `:345`, `:355` (`processar`/`enviar`: `$r->conta()` re-resolvido e `POST /items` sem trava)
- `app/Services/Publicador/PublicacaoService.php:192-201` e `:477` (`reenviarDescricao`: `POST /items/{id}/description` sem trava)
- `app/Models/PubProduto.php:163-173` (`ancoraComToken`: MlbEmpresa primeiro; token `revoked` faz cair para a Company)

**Problema:** `iniciar()` checa a conta liberada uma vez, no clique. Daí em diante o `PublicarRascunhoJob` roda em várias fatias (`release(15)`; item `UNKNOWN` espera ≥180 s e o job vive até 30 min). Cada fatia recarrega o rascunho, e `$r->conta()` devolve a âncora que tiver token NAQUELE momento. Nenhum ponto do job chama `ContasLiberadas`, e o vendedor da fatia não é comparado com o da conferência (`conta_snapshot.sellerId`) — só o `modelo`. O mesmo vale para `reenviarDescricao`, que é uma requisição avulsa.

**Cenários concretos:**
- Produto com `mlb_empresa_id = E` e `company_id = 459` (o desenho do D20: produtos sincronizados na `MlbEmpresa` "Dev 02" ligada à Company 459). No clique E não tem token → âncora = Company 459 (liberada) → `iniciar` passa. Durante a espera da reconciliação alguém conclui o OAuth da E pelo link do Onboarding → na fatia seguinte a âncora vira E (que NÃO está em `contas_liberadas.mlb_empresas`) → `reconciliar()` busca o SKU com o `sellerId` da 459 usando o token de E (resultado vazio/403) → passado o prazo o item volta a `PENDING` → `enviar()` faz `POST /items` na conta de E.
- Inverso, quando o usuário liberar uma `MlbEmpresa` de cliente (D21 "liberada uma a uma"): produto (E liberada, C não liberada). O sync diário renova o token de E, recebe `invalid_grant` e `MercadoLivreService::refreshToken()` (linha 256) grava `status = revoked` → na fatia seguinte a âncora cai para C → `POST /items` na conta C, não liberada, possivelmente de outro vendedor. O item anterior pode ter sido criado em E (estava `UNKNOWN`) e é reenviado em C, o que dá anúncio duplicado em duas contas.
- O usuário tira uma conta da lista (`.env` + `config:cache`) com uma publicação ainda em `RUNNING`/reconciliação: o job segue publicando.

**Correção:** fixar a âncora no clique e checar a trava em todo ponto que escreve:
```php
// iniciar(): gravar QUAL conta e QUAL vendedor foram autorizados
$ancora = $r->conta();
ContasLiberadas::exigir($ancora);
$v = /* última L3 */;
$sellerConferido = $v->respostas_ml['conta']['sellerId'] ?? null;
$p = $r->publicacoes()->create([... ,
    'conta_snapshot' => null,
    'ator' => [...self::atorParaGravar($ator), 'conta' => ['tipo' => $ancora::class, 'id' => $ancora->getKey(), 'seller' => $sellerConferido]],
]);

// helper usado por prepararItens/processar/depoisDeCriar/reenviarDescricao
private function contaDaPublicacao(PubPublicacao $p, PubRascunho $r): ContaMercadoLivre
{
    $atual = $r->conta();
    $fixada = (array) ($p->ator['conta'] ?? []);
    if ($atual::class !== ($fixada['tipo'] ?? null) || (int) $atual->getKey() !== (int) ($fixada['id'] ?? 0)) {
        throw new RegraViolada('V-ACC-02', 'A conta do Mercado Livre deste produto mudou desde o clique em Publicar. Confira de novo.');
    }
    ContasLiberadas::exigir($atual);   // cobre a conta tirada da lista
    return $atual;
}
// prepararItens(): além do modelo, comparar $conta->sellerId com $fixada['seller'] e encerrar se diferir.
```
(Se preferir não usar `ator`, uma coluna nova em `pub_publicacoes` também resolve, mas aí é migration em tabela com dado.) Teste: publicação `RUNNING` com item `UNKNOWN`; trocar o token entre duas chamadas de `executarFatia` e conferir que nenhum `POST /items` sai (`Http::assertNotSent`).

### CR-B02: `pubprod_empresa_fk`/`pubprod_company_fk` em CASCADE apagam o histórico de publicação ao excluir a empresa

**Arquivos:**
- `database/migrations/2026_10_02_100000_create_pub_produtos_table.php:45-46`
- a cadeia: `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:164` (`pubr_produto_fk` cascade) → `2026_10_01_200000_create_publicador_tables.php:221` (`pubpu_rascunho_fk` cascade) → `:239` (`pubpi_publicacao_fk` cascade)
- gatilhos: `app/Http/Controllers/MlbController.php:2709-2714` (`destroyEmpresa`: `$empresa->delete()` em `DELETE /mlb/empresas/{empresa}`, liberado a gestor/líder do setor de publicação — não exige admin) e `app/Http/Controllers/CompanyController.php:1231-1236` (hard delete de Company)

**Problema:** a fase criou uma tabela nova cujo dono é a `MlbEmpresa`/`Company` com `cascadeOnDelete`. Um `delete()` na empresa leva `pub_produtos` → `pub_rascunhos` → `pub_publicacoes` → `pub_publicacao_itens`, e com eles o `ml_item_id`, o payload enviado e a resposta crua do ML de anúncios que CONTINUAM no ar. Para produto de Polos sem Portal (D15: 535 de 539 `MlbEmpresa`), esses itens são o ÚNICO registro do que a ECF publicou, já que sem `oferta_id` nada entra na régua (D16). É o oposto da regra do usuário registrada no D27 ("o payload enviado e a resposta bruta do ML ficam guardados"), e o próprio projeto já tomou a decisão contrária no motor antigo: `2026_07_13_100001_alter_ml_anuncio_rascunhos_add_empresa_tier_sku.php:46-51` usa `nullOnDelete()` com o comentário "preserva o rascunho se a empresa for hard-deletada". Também apaga um produto que tem `company_id` vivo só porque a `MlbEmpresa` foi excluída. Depois disso, o "Sincronizar do Portal" recria um produto zerado para a mesma oferta.

**Cenário:** gestor de Polos exclui a empresa X (duplicada ou saiu do projeto) pelo painel → os 12 anúncios que a equipe publicou para X pelo Publicador perdem todo o rastro no ECF (o learnings §9 já registra o caso Company como "fora do D27", mas o caminho da `MlbEmpresa` não é de admin e não está registrado).

**Correção (antes do primeiro deploy da migration, enquanto ela ainda não rodou em produção):**
```php
// as duas colunas são anuláveis: SET NULL é válido (o 1830 é só para NOT NULL — learnings §6)
$t->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas', 'id', 'pubprod_empresa_fk')->nullOnDelete();
$t->foreignId('company_id')->nullable()->constrained('companies', 'id', 'pubprod_company_fk')->nullOnDelete();
```
O produto órfão (as duas âncoras nulas) já vira 404 em `ProgramasPublicadorService::empresaDoProduto()`, e o histórico fica. Alternativa: `restrictOnDelete()` e mensagem em `destroyEmpresa` ("a empresa tem anúncios publicados pelo Publicador; arquive em vez de excluir"). Se a migration já tiver rodado em algum ambiente com dado, vira fase própria com o cuidado do 1553.

## Warnings

### WR-B01: A tela mostra a conta e o "liberada" da EMPRESA; a publicação usa a conta do PRODUTO

**Arquivos:** `app/Http/Controllers/MlbPublicadorEntradaController.php:109`, `:195`; `app/Services/Publicador/ProgramasPublicadorService.php:391`, `:416-417` (`conta_nome`, `conta_ml_id`) contra `app/Http/Controllers/MlbPublicadorController.php:287` e `app/Models/PubProduto.php:175-185`

**Problema:** o cabeçalho do editor e da tela B (nome e id da conta do ML, `liberada`) vêm de `ancoraComToken($alvo['mlb_empresa'], $alvo['company'])`, a empresa resolvida pela tela. A conferência e o POST usam `ancoraComToken($produto->mlbEmpresa, $produto->company)`. Os dois divergem sempre que as âncoras do produto não forem as da empresa da tela. Exemplos: os 2 rascunhos backfillados de produção nascem com `mlb_empresa_id = null`, `company_id = 459`; depois do D20 a tela da `MlbEmpresa` "Dev 02" lista esses produtos (pelo OR em `company_id`), mas, se a "Dev 02" ganhar token, o cabeçalho mostra a conta dela enquanto esses produtos publicam pela 459. O mesmo acontece se `MlbEmpresa.company_id` for trocado depois de criados os produtos. O props `liberada` e o JSON `publicacao_liberada` podem discordar na mesma tela, e a pessoa confirma a publicação olhando o nome de uma conta que não é a que recebe o anúncio (o E2E exige confirmação do usuário antes de cada `POST /items` — ele vai confirmar olhando a conta errada).

**Correção:** no editor, montar `conta_nome`/`conta_ml_id`/`liberada` a partir de `$p->contaOuNula()`. Na tela B, sinalizar o produto cuja âncora difere da empresa (ou gravar `mlb_empresa_id` nos produtos com só `company_id` quando a empresa for resolvida como `MlbEmpresa`).

### WR-B02: IA → rascunho (D14) checa "intocável" e revisão uma vez só e regrava listas inteiras — perde edição concorrente

**Arquivos:** `app/Services/Publicador/IaParaRascunhoService.php:65-74`, `:104-125`, `:132-173`, `:198-260`

**Problema:**
1. `intocavel()` e `sobrescrever = revisao === revisao_base` são avaliados uma vez, sem trava. A aplicação inteira leva vários segundos (pode chamar `schemas->obter` no ML em `trocarCategoria`/`schemaDoRascunho`). Se a equipe clicar em Publicar nesse intervalo, a IA continua gravando num rascunho `PUBLISHING`. Se a pessoa editar nesse intervalo com `substituir=true`, a IA sobrescreve o que ela acabou de digitar, o que fere o "digitado vence" (UI-SPEC §8.2).
2. Mesmo com `sobrescrever = false`, as gravações são ler-tudo/gravar-tudo: `$atuais = snapshot()->atributos; $novos = $atuais; …; salvar(['atributos' => $novos])`, e o mesmo com `alvos` (títulos). Um atributo ou título que a pessoa grava entre o `snapshot()` e o `salvar()` é revertido para o valor antigo. Não é "a IA preenche só o vazio": a IA volta o campo que a pessoa acabou de preencher.

**Correção:** aplicar sob `Cache::lock("publicador:rascunho:{$r->id}")` (o mesmo lock que o editor/`iniciar` passarem a usar) ou, mais simples, rechecar dentro de cada `DB::transaction` com `lockForUpdate`:
```php
DB::transaction(function () use ($r, $destino, ...) {
    $r = PubRascunho::whereKey($r->id)->lockForUpdate()->first();
    if (self::intocavel($r) || $r->publicacoes()->where('status', PubPublicacao::RUNNING)->exists()) { return; }
    $sobrescrever = $substituir && $r->revisao === $revisaoEsperada;
    // gravar SÓ as chaves que mudaram (merge no repositório), não a lista inteira
});
```
e trocar `gravarAtributos($r, $novos)` por um merge que só toca as chaves vindas da IA.

### WR-B03: "Conferir" rebaixa um rascunho `PUBLISHING`/`PUBLISHED`/`PARTIALLY_PUBLISHED` para `VALIDATED` — e isso abre o rascunho publicado à IA

**Arquivos:** `app/Services/Publicador/ConferenciaService.php:158-161`; consequência em `app/Services/Publicador/IaParaRascunhoService.php:41,53-56` e `app/Http/Controllers/MlbAnuncioController.php:2296`; endpoint novo `app/Http/Controllers/MlbPublicadorController.php:226-234`

**Problema:** a conferência aprovada faz `$r->update(['status' => VALIDATED])` sem olhar o status atual. A linha é antiga, mas a fase expôs o endpoint `mlb.anuncios.publicador.conferir` sem guarda de status e passou a depender do status para a regra D14 ("publicado/publicando intocável"). Cenário: produto publicado → alguém clica "Conferir no ML" na barra do editor → status vira `VALIDATED` → a tela B passa a mostrar "conferido" no lugar de "publicado" (`prontidao()`), o indicador "prontos" da tela A conta o produto e `IaParaRascunhoService::intocavel()` devolve `false` → "Anunciar por IA" com substituir reescreve título, descrição e ficha de um rascunho cujos anúncios estão no ar. Durante uma publicação em andamento, o mesmo `VALIDATED` aparece no meio do `PUBLISHING`.

**Correção:**
```php
if ($status !== self::BLOQUEADO && $r->fresh()->revisao === $revisao
    && in_array($r->fresh()->status, [PubRascunho::DRAFT, PubRascunho::VALIDATED, PubRascunho::FAILED], true)) {
    $r->update(['status' => PubRascunho::VALIDATED]);
}
```
E basear o "intocável" da IA em fato, não em status: `RUNNING` existente ou algum `PubPublicacaoItem::CREATED` do rascunho.

### WR-B04: Produto sem token: a foto é gravada e a requisição devolve 422; a conferência local nem roda (contradiz D26)

**Arquivos:** `app/Services/Publicador/ImagemAssetService.php:48-55`, `:70-75`; `app/Services/Publicador/ConferenciaService.php:78-84`

**Problema:** D26 diz que a conta que não pode receber escrita só confere localmente e guarda a foto. Mas `enviarAoMl()` chama `$imagem->rascunho->conta()` ANTES do teste de liberação, e `conta()` lança `V-ACC-01` quando não há token ativo. A tela A lista de propósito empresas que "autorizaram" sem token (`ProgramasPublicadorService:66-70`). Nesse caso `receber()` já gravou o arquivo e a linha `pub_imagens` e então estoura: a resposta é 422 "reconecte a conta", a foto não entra no grupo (`colocarNoGrupo` não roda), mas existe; o segundo envio do MESMO arquivo cai na deduplicação por sha, devolve `nova=false` e passa calado. O mesmo `conta()` em `conferir()` grava ERRO (`V-ACC-01`) em vez da conferência L1/L2 local que a regra garante para conta não liberada.

**Correção:** tratar "sem token" como "não liberada" nos dois lugares:
```php
$conta = $imagem->rascunho->produto->contaOuNula();
if ($conta === null || ! ContasLiberadas::libera($conta)) {
    return $imagem; // fica PENDENTE, sobe depois
}
```
e em `conferir()`: `$ancora = $r->produto->contaOuNula(); if ($ancora === null || ! ContasLiberadas::libera($ancora)) return $this->conferirLocal(...)` (o `V-ACC-01` aparece na publicação, que já o exige em `iniciar`).

### WR-B05: Programa derivado de dois jeitos (SQL case-insensitive × PHP estrito): empresa listada na tela A dá 404 ao abrir

**Arquivos:** `app/Models/MlbEmpresa.php:128-155` (`scopePrograma`) contra `:157-178` (`programaPublicador`); usos em `app/Services/Publicador/ProgramasPublicadorService.php:51`, `:94-95` (SQL) e `:274`, `:288`, `:371` (PHP)

**Problema:** `projeto` é texto livre (`MlbController:2662`, `nullable|string|max:100`). No MariaDB, com collation `*_ci` e PAD SPACE, `where('projeto', 'POLOS')` casa "Polos", "polos" e "POLOS " (o mesmo vale para `whereIn('fase', …)`). No PHP, `programaPublicador()` compara com `===`. Uma empresa com `projeto = 'Polos'` aparece na aba Polos da tela A, mas `resolver('empresa-N')` → `programaPublicador()` devolve `null` → 404 na tela B, no editor e em toda a API. Ela também sai da aba Gestão (excluída por `$ligadas`, calculado no SQL). O SQLite dos testes compara com BINARY e não pega isso — é a armadilha de MariaDB do learnings §6.

**Correção:** normalizar no PHP do mesmo jeito que o banco compara (`mb_strtolower(trim(...))` em `projeto`/`fase`/`tipo` dentro de `programaPublicador()`), ou derivar o programa só no PHP para as duas telas. Medir em produção (só leitura) `SELECT projeto, COUNT(*) FROM mlb_empresas WHERE arquivado_em IS NULL GROUP BY BINARY projeto` antes de decidir.

### WR-B06: Migration com dado de produção detecta MariaDB por `=== 'mysql'`; com `DB_CONNECTION=mariadb` ela para no meio

**Arquivo:** `database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:216`, `:236`, `:196`

**Problema:** `hasIndex()`/`hasForeignKey()` (e o `dropForeign` do `down()`) só usam `information_schema` quando o driver é `mysql`. Laravel 11+ tem o driver `mariadb` próprio (`config/database.php:68`). Se o ambiente usar `DB_CONNECTION=mariadb`, o passo 5 roda `PRAGMA index_list(...)` no MariaDB → erro de sintaxe DEPOIS dos passos 1–4 e do `NOT NULL` (DDL não é transacional no MariaDB): fica coluna NOT NULL sem unique e sem FK, e a migration fica `Pending`. A reexecução quebra no mesmo ponto. As outras migrations do projeto usam `!== 'sqlite'` justamente para cobrir isso (`2026_04_28_000001…:19`).

**Correção:** `if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true))` nos três lugares (ou `DB::getDriverName() !== 'sqlite'`, como o resto do repositório).

### WR-B07: Corrida em `abrir()` → 500 por `pubr_produto_uq`

**Arquivos:** `app/Services/Publicador/EditorRascunhoService.php:63-76`; `app/Services/Publicador/MigracaoAnunciarAntigo.php:100`; chamadores `app/Http/Controllers/MlbPublicadorController.php:56-59` e `app/Http/Controllers/MlbAnuncioController.php:2295`

**Problema:** `abrir()` faz "procura → se não achou, cria" sem trava. O primeiro acesso a um produto novo dispara o `GET …/produtos/{id}` do editor, e o botão "Anunciar por IA" chama `abrir()` de novo em `iaAnaliseStorePublicador`. Duas abas, ou editor e IA ao mesmo tempo, criam dois rascunhos: o segundo `PubRascunho::create` viola `pubr_produto_uq` → `QueryException` não tratada → HTTP 500 (o `responder()` só converte `RegraViolada`). O mesmo vale para o caminho da migração do Anunciar antigo. `PubProduto::daOferta` já trata essa corrida; o rascunho não.

**Correção:** envolver em `Cache::lock("publicador:abrir:{$produto->id}", 10)->block(5, …)` ou capturar o `23000` e reler:
```php
try { $r = $this->repo->criar(...); }
catch (QueryException $e) {
    if ((string) $e->getCode() !== '23000') throw $e;
    $r = PubRascunho::where('produto_id', $produto->id)->firstOrFail();
}
```

### WR-B08: `simular` fica fora do `responder()` — conta sem token vira HTTP 500

**Arquivos:** `app/Http/Controllers/MlbPublicadorController.php:259-264`; `app/Services/Publicador/EditorRascunhoService.php:262-263`

**Problema:** `simular()` chama `$r->conta()` sempre que `step_state.conta.sellerId` existe, ou seja, sempre que a conta já foi lida uma vez. Com o token revogado depois (o `invalid_grant` grava `revoked`), `conta()`/`tokenValido()` lançam `RegraViolada('V-ACC-01')`. Todas as outras ações passam pelo `responder()`, que converte em 422; `simular` não, e responde 500 no "Quanto eu recebo?".

**Correção:** `return $this->responder(fn () => [$r = $this->rascunho($produto), ['simulacao' => $this->editor->simular($r)]]);` ou, dentro de `simular()`, usar `contaOuNula()` e pular o frete quando for nula.

### WR-B09: N+1 na lista de produtos (tela B e faixa do editor)

**Arquivos:** `app/Services/Publicador/ProgramasPublicadorService.php:428`, `:482-483`; `app/Models/PubProduto.php:209-217`

**Problema:** `produtosQuery(...)->get()` não carrega `oferta`, e `skuExibido()`/`nomeExibido()` acessam `$this->oferta` em cada produto, o que faz uma consulta por produto com `oferta_id`. Roda na tela B E em toda abertura do editor (`MlbPublicadorEntradaController::editor:184`). Uma empresa com Portal sincronizado (centenas de ofertas) dispara centenas de SELECTs por carregamento.

**Correção:** `$produtos = $this->produtosQuery($e, $c)->with('oferta:id,sku,nome')->get();`

## Info

### IN-B01: O fallback `PUBLICADOR_EMPRESAS_PILOTO` muda de significado sem aviso

**Arquivo:** `config/publicador.php:97`

**Problema:** a variável antiga dizia "empresas no PILOTO da tela do Portal" (cliente usando a tela; vazio = todas). Agora ela vira "contas LIBERADAS para a equipe publicar". Se o `.env` de produção tiver ids de cliente nessa variável (pilotos de tela), elas passam a estar liberadas para publicação sem a liberação "uma a uma" do D21. Vazio, a semântica inverte para "ninguém", o que é seguro.
**Correção:** antes do deploy, conferir `grep PUBLICADOR_ /var/www/ecf_admin/.env` e gravar `PUBLICADOR_CONTAS_LIBERADAS_COMPANIES=459` explicitamente. Registrar no checklist de deploy.

### IN-B02: `oferta_id` velho em memória leva a TypeError/Error em corrida com a exclusão da oferta

**Arquivos:** `app/Services/Publicador/DadosEfetivosService.php:31-35`; `app/Services/Publicador/PublicacaoService.php:524-537`

**Problema:** os dois testam `$produto->oferta_id !== null` e depois usam `$produto->oferta` sem checar nulo. Se a oferta for apagada entre carregar o produto e acessar a relação (o Portal apaga enquanto o job de publicação está numa fatia), `daOferta(null)` dá `TypeError`. Em `cadastrarNaRegua`, `$oferta->anuncios()` fica FORA do `try` e dá `Error`, o que derruba o job depois de os itens estarem criados no ML.
**Correção:** `$oferta = $produto->oferta; if ($oferta === null) { return /* vazio */; }` nos dois lugares, e mover a checagem de idempotência para dentro do `try` em `cadastrarNaRegua`.

### IN-B03: Import morto e docblock órfão no `MlbAnuncioController`

**Arquivo:** `app/Http/Controllers/MlbAnuncioController.php:31`, `:2049-2064`

**Problema:** com `empresas()`/`empresasDeConsultoria()`/`empresasDePolos()` removidos, `use Illuminate\Support\Collection;` ficou usado só por um docblock que já era órfão ("Retorna as empresas que PODEM receber publicação…", sem método embaixo).
**Correção:** remover o import e os docblocks órfãos.

### IN-B04: `estado()` recalcula o conjunto inteiro da empresa duas vezes a cada salvamento automático

**Arquivo:** `app/Services/Publicador/EditorRascunhoService.php:345-348`, `:501-523`

**Problema:** `mlbDoTipo()` é chamado por alvo (2×) e cada chamada roda `mlbsDaRegua()` → `EstruturaConjunto::daEmpresa($oferta->company)`, que monta todas as ofertas da empresa. `estado()` é a resposta de TODO `PUT` do autosave. Não é bug de correção (performance fica fora do escopo v1), mas multiplica a carga do editor numa empresa grande do Portal.
**Correção:** calcular `mlbsDaRegua($r->produto)` uma vez em `estado()` e reutilizar.

### IN-B05: Duas `MlbEmpresa` ligadas à mesma Company enxergam e contam os produtos uma da outra

**Arquivo:** `app/Services/Publicador/ProgramasPublicadorService.php:186-187`, `:303-316`

**Problema:** o filtro "mlb_empresa_id = E OU company_id = C" coloca o produto de E1 também na linha e na tela B de E2 quando as duas apontam para C. Os indicadores contam em dobro. Ao abrir, o editor resolve E1 (`empresaDoProduto`) e o cabeçalho troca de empresa. Raro hoje (5 `MlbEmpresa` com `company_id`), mas `resolver()` já assume que pode haver mais de uma (`orderBy('id')->get()->first(...)`).
**Correção:** no OR por `company_id`, restringir a `mlb_empresa_id IS NULL OR mlb_empresa_id = E`.

---

### Conferido e sem achado

- **D18:** rotas `/estrutura/anunciar*`, `…/publicacao*`, `…/publicador*` removidas de `routes/web.php`; 22 linhas tiradas da allowlist de `RestringeDominioDoPortal`; submódulo saiu de `ModulosPortal`; nenhuma referência restante a `PortalPublicadorController`, `EstruturaPublicacaoService`, `portal.auth.estrutura.anunciar` ou `portal.auth.publicador.*` em `app/`, `routes/` e `resources/js`. Lista SKUs, Precificação, Anúncios, Planejamento e Mapeamento continuam com rotas e allowlist.
- **D27:** `SoltarProdutoDaOfertaService` é chamado dentro da transação de `EstruturaOfertaService::excluir` (o único `->delete()` de oferta no app), antes de os anúncios irem para a espera. Nada grava `pub_rascunhos.oferta_id` depois da migration, e o backfill zera a coluna, então `pubr_oferta_fk` (CASCADE) não alcança rascunho. `cadastrarNaRegua` não cadastra sem `oferta_id`.
- **Migration B:** idempotente passo a passo; unique antes da FK (a FK reaproveita o índice); `down()` derruba FK → unique → coluna, na ordem certa para o 1553; nomes curtos; sem try/catch em volta de DDL; tipos das PKs referenciadas (`id()` bigint) batem.
- **Autorização/IDOR:** todo endpoint resolve foto/item/atribuição pelo rascunho do produto autorizado (`imagens()->findOrFail`, `whereIn('publicacao_id', …)`, `gravarAtribuicoes` filtra por `$r->imagens()`); as âncoras de `criarProduto`/`sincronizar` vêm do `resolver`, não do corpo; `MlToken` esconde `access_token`/`refresh_token`; o log do `ClienteMlPublicador` não leva token.
- **Duplo clique em publicar:** `iniciar` usa `lockForUpdate` e recusa quando já há `RUNNING`; `ConferirRascunhoJob` é `ShouldBeUnique`; `PubProduto::daOferta` e o sincronizar tratam a corrida no `pubprod_oferta_uq`.

_Revisado em: 2026-10-02T19:08:33Z_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_

---

# Parte 2 — Frontend

# Fase 160: Revisão de código — frontend

**Revisado em:** 2026-10-02T19:08:05Z
**Profundidade:** standard (com leitura dos endpoints/serviços que o front consome, para confirmar contrato)
**Arquivos revisados:** 41
**Status:** issues_found

## Resumo

Foram revisados os 41 arquivos de frontend da Fase 160: o hook `usePublicador`, o hook `useIaDoPublicador`, os derivados, os cards da mesa, a lateral, a barra, a faixa e as telas A (empresas) e B (produtos). Para confirmar o contrato, também li o lado do servidor que o front consome: `MlbPublicadorController`, `EditorRascunhoService::salvar/estado/prontidao`, `RascunhoRepository::gravarAtributos/tocar`, `ConferenciaService`, `PublicacaoService::iniciar`, `IaParaRascunhoService`, `GerarAnaliseAnuncioIaJob` e `iaAnaliseStore/Status`.

O ponto mais frágil é o modelo de salvamento do editor. São duas falhas BLOCKER de perda silenciosa de dado:

1. Toda ação "de estrutura" substitui a cópia local inteira, e três delas (atribuir, remover e reenviar foto) nem descarregam o que estava pendente antes. O resultado é que o texto digitado some da tela e, em seguida, o próprio autosave grava a versão antiga e mostra "Salvo".
2. O autosave manda o documento inteiro (`gravarAtributos` apaga o que não veio). Por isso ele apaga o que a IA gravou no servidor enquanto o editor continuava editável. O `recarregar` piora a situação: dispara um PUT sem esperar, que corre contra o GET.

Os demais achados são WARNING:

- Corridas de resposta e de servidor no salvamento.
- Autosave que falha sem nova tentativa e sem aviso ao sair da página.
- Um simples foco seguido de saída do campo invalida a conferência.
- A faixa da IA lê o contrato errado e sempre diz "0 seções".
- A confirmação da IA ignora os dados do Portal.
- Textos que atribuem ao Mercado Livre bloqueios que são locais ("apontou 0 pendência(s)").
- Bloqueio local de conta escondido.
- Pendência de preço que leva ao card errado.
- Mensagens de foto rejeitada que se apagam umas às outras.
- Select de envio fora das opções.
- Campo com sugestões que come o espaço digitado.
- Troca de programa que volta para o programa anterior.
- Campos sem rótulo acessível.

### Conferido e sem achado (para não repetir trabalho)

- `substituir: true` só é enviado pelo botão "Substituir com a IA" do Dialog (`BotaoAnunciarPorIa.jsx:68`). O "Tentar de novo" manda `false` (ver WR-F06 para o efeito colateral disso).
- O polling da IA para ao concluir, ao dar erro, ao receber 404, no limite de tempo e ao desmontar (há guarda `vivo`). A chave do `sessionStorage` (`publicador.ia.{produtoId}`) é a mesma na leitura e na escrita.
- `podePublicar` nunca libera com conferência local (D26) nem com conta não liberada (D21). A trava real está no servidor (`ContasLiberadas::exigir`, RN-90).
- Duplo clique em "Publicar" não publica duas vezes: o botão desabilita via `salvando` e o servidor bloqueia com `lockForUpdate` + RN-93.
- "Publicar" sem diálogo extra é decisão registrada (UI-SPEC §"Confirmação de publicação"). O "ciente" com AVISOS é exigido no front e no servidor.
- Não há `dangerouslySetInnerHTML`. Todas as rotas são montadas por `route()`. A cor da variante passa por regex antes de virar `style`. Nenhum token aparece na tela (o link de reconexão só é copiado).
- A troca de produto na faixa usa `router.get` sem `preserveState`, então o Inertia 2.3.21 **remonta** o `Editor`. Isso impede, hoje, a contaminação entre produtos (ver IN-F07).

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-F01: Ação de estrutura troca a cópia local inteira e o autosave grava a versão antiga por cima do que foi digitado

**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js:67` (`aplicarTudo`)
- `usePublicador.js:79` (`if (tudo) aplicarTudo(data)`)
- `usePublicador.js:92-98` (`salvarRasc` lê `rascRef.current` na hora de disparar)
- `usePublicador.js:209-214` (`atribuirFotos`, `removerFoto` e `reenviarFoto` **sem** `descarregar()`)
- `usePublicador.js:196-208` (`enviarFotos`: um `aplicarTudo` por arquivo)
- `usePublicador.js:246` (`disabled` não inclui ação em andamento)

**Problema:** toda resposta com `{ tudo: true }` executa `setRasc(doEstado(data)); setVars(variantesDoEstado(data))` sem conferir se houve digitação depois do envio. O temporizador de 900 ms continua armado, com `sujo.current.rasc === true`. Quando ele dispara, `salvarRasc` envia `rascRef.current`, que a essa altura já é a cópia do servidor, e ainda chama `setSalvoEm(new Date())`.

**Cenário 1 (não precisa de latência):**
1. O usuário digita o título do Clássico.
2. Em menos de 900 ms, clica ◀ numa foto, ou em "tornar capa", ou arrasta uma miniatura. Também vale clicar na lixeira ou em "enviar de novo".
3. `atribuirFotos` não chama `descarregar()`. O PUT `fotos.atribuir` volta e `aplicarTudo` recoloca o título antigo.
4. Em seguida, o autosave grava esse título antigo.
5. A barra mostra "✓ Salvo há 0s". O título digitado sumiu da tela e do banco.

O mesmo acontece com estoque, SKU e preço, porque `setVars` também é sobrescrito. O `CampoPreco` volta o texto via `useEffect([valor])`.

**Cenário 2:**
1. O usuário escolhe 6 fotos e começa a escrever a descrição enquanto elas sobem.
2. Cada resposta de foto chama `aplicarTudo` e a descrição volta ao que era.

**Cenário 3:** o mesmo vale para o que se digita enquanto `escolherCategoria` ou `salvarEixos` estão em voo. Os campos não ficam desabilitados, porque `disabled` só considera publicação e `aguardando`.

**Agravante:** `descarregar()` só olha `sujo`. Se o salvamento disparado pelo temporizador já está em voo (`sujo` já virou `false`), `descarregar` retorna na hora e a ação de estrutura corre em paralelo com ele. A resposta da estrutura (n maior) pode refletir o banco **antes** do salvamento e vence pela regra de ordem. Assim a tela volta ao estado antigo, e o próximo autosave do documento inteiro regrava esse estado antigo.

**Correção:**
```js
// 1) Toda ação de estrutura descarrega antes, e descarregar espera também o que já está em voo.
const emVoo = useRef(Promise.resolve());
const enfileirar = (fn) => (emVoo.current = emVoo.current.then(fn, fn));
const descarregar = () => enfileirar(async () => { await salvarRasc(); await salvarVars(); });

const atribuirFotos = async (atribuicoes) => { await descarregar(); /* ...como hoje */ };
const removerFoto   = async (id) => { await descarregar(); return chamar(/* ... */, { tudo: true }); };
const reenviarFoto  = async (id) => { await descarregar(); return chamar(/* ... */, { tudo: true }); };

// 2) Nunca pisar em digitação feita depois do envio.
const versao = useRef(0);               // ++ em mudarRasc/mudarVar
const chamar = async (promessa, { tudo = false } = {}) => {
    const n = ++ordem.current.enviada;
    const v0 = versao.current;
    // ...
    if (tudo && versao.current === v0) aplicarTudo(data); else setEstado(data);
};

// 3) Travar a digitação enquanto uma ação de estrutura está em voo.
const [estruturando, setEstruturando] = useState(0);
const disabled = publicando || publicado || !! aguardando || estruturando > 0;
```

### CR-F02: O autosave do documento inteiro apaga o que a IA gravou, e `recarregar` dispara um PUT sem esperar que corre com o GET

**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js:96`, `103` (PUT com `rascRef.current` e `varsRef.current` **inteiros**)
- `usePublicador.js:140-148` (cleanup com PUT `.catch(() => {})` sem esperar)
- `usePublicador.js:240` (`recarregar`)
- `usePublicador.js:246` (`disabled` ignora a IA em andamento)
- `resources/js/Pages/Mlb/Publicador/Editor.jsx:65-69` (`onConcluiu: () => pub.recarregar()`)

**Problema:** a IA grava o rascunho **no servidor**, dentro do `GerarAnaliseAnuncioIaJob` (etapa `rascunho` → `IaParaRascunhoService::aplicar`). Durante os minutos da geração, o editor continua editável. O front só fica sabendo do resultado no próximo polling (até 2,5 s depois). Do outro lado, o `PUT salvar` não é um "patch":

- `RascunhoRepository::gravarAtributos` faz `whereNotIn(...)->delete()` dos atributos que não vieram.
- `titulo: ''` vira `null`.
- `descricao` é sobrescrita.

**Cenário A:**
1. Com a IA rodando, a equipe ajusta a garantia no card de logística.
2. O temporizador dispara no intervalo entre o job aplicar e o polling perceber o "concluido".
3. O PUT leva `atributos` sem nada da IA, títulos vazios e descrição vazia.
4. As características, os títulos e a descrição que a IA acabou de gravar são apagados.
5. Depois disso, a faixa azul diz "A IA preencheu … seções".

**Cenário B (conclusão da IA, reenviar descrição, "Tentar de novo"):**
1. `recarregar` muda `recarga`.
2. O cleanup do efeito manda, sem esperar, o PUT da cópia local anterior à IA, se houver algo sujo.
3. Logo em seguida sai o `GET abrir`. Os dois correm em paralelo no PHP-FPM.
4. Se o GET terminar antes do commit do PUT, a tela mostra o conteúdo da IA e zera `sujo`, mas o banco fica com a versão anterior.
5. "Conferir" (`descarregar` não faz nada) confere o banco, não a tela. O próximo autosave do documento inteiro volta a misturar as versões.

**Correção:**
```js
// Editor.jsx — a mesa fica só leitura enquanto a IA trabalha
const m = { ...pub.m, disabled: pub.m.disabled || ia.estado === 'andamento' };

// usePublicador.js — recarregar descarrega ANTES de ler; o cleanup não manda PUT para o mesmo produto
const recarregar = useCallback(async () => { await descarregarRef.current(); setRecarga((n) => n + 1); }, []);
```
O ideal é complementar no servidor: o `PUT salvar` e o `PUT variantes` passam a levar `revisao_base`, e o servidor responde 409 quando a revisão subiu por outra via (IA). Ou então o front manda só os campos alterados (atributos sujos), em vez do documento inteiro.

## Warnings

### WR-F01: Salvamentos concorrentes sem fila, com a ordem decidida por número de envio e não pela revisão do servidor

**Arquivos:** `resources/js/Components/Publicador/usePublicador.js:70-90`, `92-105`, `152-175`

**Problema:** os temporizadores de `rasc` e de `vars` são independentes, e um novo PUT pode sair enquanto o anterior ainda está em voo. Daí saem dois efeitos:

- **(a) Estado da tela desatualizado.** Exemplo: PUT rasc (n=1) e PUT vars (n=2) em paralelo. A resposta de n=2 é calculada antes do commit de n=1 e chega primeiro. A de n=1, que é a mais nova, é descartada por `n >= aplicada`. Com isso, `estado.problemas` fica velho: mostra "falta título" com o título já salvo, `bloqueiosLocais` continua > 0 e "Conferir" fica desabilitado até a próxima edição.
- **(b) Ordem no servidor.** Sob latência, o PUT1 (conteúdo antigo) pode chegar ao commit depois do PUT2 (conteúdo novo). O banco fica com o antigo, e a tela, que descartou a resposta de n=1, mostra o novo.

O polling (`setEstado(data)` em `:163`) também ignora a regra de ordem.

**Correção:** serializar toda escrita numa fila de promessas (a mesma de CR-F01) e aplicar a resposta pela revisão real:
```js
if ((data.rascunho?.revisao ?? 0) >= (estadoRef.current?.rascunho?.revisao ?? -1)) setEstado(data);
```

### WR-F02: Autosave que falha não tenta de novo, o indicador continua "Salvo" e não há aviso ao sair da página

**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js:97`, `104` (`sujo = true` sem reagendar)
- `resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx:52-72`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx:116`, `151`

**Problema:** um 422, 429 (throttle), 419 ou queda de rede no PUT só faz `sujo = true`. Nada reagenda o salvamento. A faixa vermelha pode ser fechada (`onFechar`), e depois disso a barra volta a mostrar "✓ Salvo há 40s", que é o último sucesso. Não há `beforeunload`, então um F5 ou o fechamento da aba durante a espera de 900 ms ou após a falha perde a edição. Ao sair por um `Link` (`onVoltar={() => pub.descarregar()}`, sem esperar), uma falha do PUT acontece com o componente já desmontado e ninguém fica sabendo.

**Correção:**
- Na falha, reagendar com espera crescente: `relogio.current.rasc = setTimeout(salvarRasc, 3000)`.
- Guardar um estado `naoSalvo` e mostrar "Não salvo — tentando de novo" no `Salvamento` (sem ✓).
- Registrar `beforeunload` enquanto houver `sujo` ou `salvando > 0`.
- Para a navegação interna, usar `router.on('before', …)` esperando `descarregar()`.

### WR-F03: Só passar pelo campo (foco e saída) grava e invalida a conferência

**Arquivos:**
- `resources/js/Components/Publicador/Mesa/CartaoVariante.jsx:23` (`onBlur={() => onMudar(paraNumero(texto))}`)
- `resources/js/Components/Publicador/CampoAtributo.jsx:70-74` (`number_unit`: `onBlur={() => gravar(numero, unidade)}`)
- `resources/js/Components/Publicador/usePublicador.js:108-119`

**Problema:** o `onBlur` grava sempre, mesmo sem mudança. `mudarRasc` e `mudarVar` marcam `sujo` incondicionalmente, e o servidor faz `tocar()` → `increment('revisao')` em todo salvamento.

**Cenário:**
1. A conferência no ML voltou OK.
2. O usuário clica no preço ou no peso só para conferir o valor e sai do campo.
3. O PUT sobe a revisão e a conferência passa a `vale=false` ("Editado depois da última conferência").
4. "Publicar" desabilita.
5. Em conta liberada, conferir de novo repete os `/items/validate` no ML.

**Correção:** comparar antes de gravar:
```js
onBlur={() => { const n = paraNumero(texto); if (n !== (valor ?? null)) onMudar(n); }}
```
Fazer o mesmo no `number_unit` (comparar o novo `value_name` com `v.value_name`). Opcionalmente, ignorar em `mudarRasc`/`mudarVar` um patch igual ao atual.

### WR-F04: A faixa de conclusão da IA lê o contrato errado (sempre "0 seções") e esconde o aviso e o erro do servidor

**Arquivos:**
- `resources/js/Pages/Mlb/Publicador/Editor.jsx:137-138`, `141-148`
- `resources/js/Components/Publicador/derivados.js:126-127`

**Problema:** `IaParaRascunhoService::resumo()` devolve `secoes` como **inteiro** (`'secoes' => $secoes`, linha 525). O Editor usa `ia.resumo?.secoes?.length ?? 0`, que vale `undefined` para um número, e o texto fica sempre "A IA preencheu 0 seções".

O teste `tests/js/publicador-editor.test.js:154-156` usa `secoes: ['a']` e por isso valida o formato errado.

Além disso, ficam de fora:
- O `resumo.aviso`, com mensagens como "O anúncio já estava publicado; a IA não mudou nada", "A categoria sugerida… não pôde ser aplicada" e "O rascunho já tem variações; a IA não mudou…".
- O `resumo.sobrescreveu`: se a equipe pediu "Substituir" mas editou durante a geração, a IA só preencheu o vazio e ninguém é avisado.

A faixa de erro ignora `ia.erro` (ex.: 422 "Este anúncio já foi publicado…", "A análise demorou demais") e afirma sempre "Nada foi alterado". Isso é falso quando o job morre no meio de `aplicar()`, que grava em vários passos sem transação única.

**Correção:**
```jsx
<p>A IA preencheu {ia.resumo?.secoes ?? 0} seções. Revise antes de conferir.</p>
{ia.resumo?.aviso && <p className="mt-1">{ia.resumo.aviso}</p>}
{pediuSubstituir && ia.resumo?.sobrescreveu === false && <p>Como houve edição durante a geração, a IA só preencheu o que estava vazio.</p>}
// faixa de erro: {ia.erro ?? 'A IA não conseguiu preparar este anúncio.'} — sem "Nada foi alterado" fixo
```
O teste também precisa usar `secoes: 3`.

### WR-F05: A confirmação "Substituir?" ignora o que veio do Portal, e o texto não cita variações, estoque e garantia

**Arquivos:**
- `resources/js/Components/Publicador/derivados.js:103-109` (`rascunhoPreenchido`)
- `resources/js/Components/Publicador/Mesa/BotaoAnunciarPorIa.jsx:30-33`, `54-56`

**Problema 1:** `rascunhoPreenchido` só olha `alvos[].titulo` digitado, atributos e descrição. Num produto sincronizado do Portal (D16), os títulos planejados e os preços chegam como `titulo_efetivo` e `precos_efetivos`, então a função diz "vazio" e a IA dispara direto (`substituir:false`), sem Dialog. Como o `IaParaRascunhoService` grava `titulo` quando o título digitado está vazio (linha 143), e o digitado vence o efetivo, o título que o cliente planejou no Portal é trocado pelo da IA sem pergunta. O preço da planilha (`varianteUnica`) faz o mesmo com o preço da Precificação. Isso contraria "começar a publicar já vindo preenchido o que tem no portal".

**Problema 2:** com `substituir:true`, `aplicarVariacoes` regrava eixos e dados das variantes (estoque, SKU, preço) e também a garantia e as medidas. O Dialog, porém, só fala em "categoria, características, títulos e descrição".

**Correção:**
- Considerar preenchido quando `estado.alvos.some(a => a.titulo_efetivo)`, quando há `precos_efetivos`, quando `estado.eixos?.length` ou quando há fotos.
- Completar o texto do Dialog com "variações, estoque, SKU, preços, garantia e medidas".
- No servidor, tratar o título efetivo do Portal como "preenchido".

### WR-F06: "Anunciar por IA" permite disparo duplo, e "Tentar de novo" pula o descarregar e perde o "substituir"

**Arquivos:**
- `resources/js/Components/Publicador/Mesa/BotaoAnunciarPorIa.jsx:21-28`
- `resources/js/Components/Publicador/useIaDoPublicador.js:47-53`, `112`

**Problema 1:** `disparar` faz `await pub.descarregar()` antes de `ia.disparar`. Durante esse PUT (que pode passar de 1 s), `ia.estado` continua `'parado'` e o botão fica habilitado. Um duplo clique cria duas análises: dois jobs gravando no mesmo rascunho, e o `sessionStorage` guarda só o segundo id, então a conclusão do primeiro nunca é acompanhada.

**Problema 2:** depois de um F5 com a análise retomada do `sessionStorage`, `resposta` fica `null` até o primeiro polling (2,5 s). Nesse intervalo o botão aparece habilitado e sem "IA preparando…", o que convida a um segundo disparo.

**Problema 3:** `tentarDeNovo: () => disparar(false)` não chama `pub.descarregar()` (a IA lê o rascunho no servidor sem a edição pendente). Se o pedido original era "Substituir", ele volta silenciosamente para "só o vazio".

**Correção:**
- Estado local `disparando` ligado antes do `await` e incluído em `desabilitado`.
- Ao retomar um id guardado, fazer `setResposta({ status: 'pendente' })` e consultar na hora.
- Fazer o "Tentar de novo" passar pelo mesmo `disparar` do botão, lembrando o último `substituir`.

### WR-F07: Conferência no ML bloqueada por regra local aparece como "O Mercado Livre apontou 0 pendência(s)" e nada é listado

**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js:264-274`
- `resources/js/Components/Publicador/Mesa/LateralValidacao.jsx:34-36`, `86-93`, `144`
- `resources/js/Components/Publicador/derivados.js:61`

**Problema:** em conta liberada, `ConferenciaService::conferir` pode gravar BLOQUEADO com os problemas da L2 recalculados com a conta lida na hora e com os condicionais (passo 4, `$prep['l2']->problemas`, que usa `Problema::bloqueio(..., camada = 'L2')` por padrão). A validação fica com `camada 'L3'`, então `conf.local === false`.

No front:
- `doMl` filtra só `p.camada === 'L3'`. Por isso `nPendencias = 0` e o texto vira "O Mercado Livre apontou 0 pendência(s)".
- `bloqueiosMl` (também só L3) não lista nada.
- Esses bloqueios não entram em `todos`, então a lateral pode mostrar "Tudo pronto. Pode conferir" ao lado de um BLOQUEADO, com "Publicar" desabilitado e sem motivo à vista.

O texto também atribui ao ML um bloqueio que é local. Da mesma forma, avisos locais da conferência caem em `avisosMl`, e o checkbox diz "Li os avisos do **Mercado Livre**".

**Correção:**
- Quando `!conf.local`, considerar todos os `conf.issues` BLOCKER em `doMl`, `bloqueiosMl` e `nPendencias`.
- Quando nenhum deles for L3, usar o texto "A conferência apontou N pendência(s)".
- Trocar o rótulo do ciente para "Li os avisos da conferência".

### WR-F08: Bloqueio local sem seção (ex.: D10, E0) desabilita "Conferir" sem mostrar o motivo

**Arquivos:**
- `resources/js/Components/Publicador/apoio.js:64-69`
- `resources/js/Components/Publicador/usePublicador.js:271`, `275`
- `resources/js/Components/Publicador/Mesa/LateralValidacao.jsx:37-39`, `86-93`, `41-44`

**Problema:** `ValidadorRascunho` emite `Problema::bloqueio('D10', 'Esta conta do Mercado Livre usa o modelo antigo de variações…', ['etapa' => 'E0'])`. `secaoDoProblema` devolve `null` para E0, E11 e E13, e o problema não conta em seção nenhuma: as 8 verificações ficam verdes e a nota diz "Tudo pronto. Pode conferir no Mercado Livre."

Mas `bloqueiosLocais` conta todos os BLOCKER, então "Conferir" fica desabilitado. As pendências locais só são listadas no estado `local_bloqueado`, e a mensagem D10, que explica o que fazer, nunca aparece. Em `Problemas`, o "ir para" de E0, E11 e E13 chama `irPorEtapa`, que não faz nada.

Isto **não** é a pendência de layout já registrada ("Tudo pronto" em conta não liberada): aqui o problema é um bloqueio escondido em qualquer conta.

**Correção:**
- Listar sempre, na lateral, os BLOCKER locais com `secaoDoProblema(p) === null` (bloco "Conta"), e tirar o "Tudo pronto" quando eles existirem.
- Esconder o "ir para" quando não houver seção.

### WR-F09: Pendência de preço leva ao card errado (Clássico e Premium ou Logística), onde não há campo de preço

**Arquivos:**
- `resources/js/Components/Publicador/apoio.js:66`
- `resources/js/Components/Publicador/Mesa/LateralValidacao.jsx:14`, `41-44`
- `resources/js/Components/Publicador/Mesa/CardTiposEPrecos.jsx`

**Problema:** o problema de preço é `['etapa' => 'E10', 'variante' => …, 'campo' => 'preco']` (`ValidadorRascunho.php:430`). `secaoDoProblema` o manda para `tipos`, então "Ir para Clássico e Premium" rola até um card que só tem título e simulação: os preços moram no `CartaoVariante`, dentro do card Variações. Já os problemas do ML passam por `Problemas → irPorEtapa('E10')`, que usa só a etapa e cai em `envio` (card Logística).

Ou seja, a mesma pendência leva a dois cards diferentes, e nenhum dos dois é o certo. O chip do card Clássico e Premium mostra "Falta 1" com os títulos completos, e a mensagem do preço não aparece junto do `CampoPreco`.

**Correção:**
- Mapear `E10/preco` com `alvo.variante` para `variantes` (`card-variacoes`).
- Fazer o `onIr` de `Problemas` receber o problema e usar `secaoDoProblema(p)`.
- Mostrar sob o `CampoPreco` os problemas com `alvo.variante === v.chave && alvo.alvo === tipo`.

### WR-F10: No envio de várias fotos, a recusa de uma é apagada pela seguinte, e a reordenação otimista não volta atrás em caso de falha

**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js:73` (`setErro(null)` em todo `chamar`)
- `usePublicador.js:198-207`, `209-212`

**Problema:** quando a foto é recusada (BLOCKER), o servidor **não** guarda a imagem (`$res['imagem']` nulo) e o único aviso é `setErro(`${arquivo.name}: …`)`. Na volta seguinte do laço, `chamar` faz `setErro(null)`. Com 5 arquivos e o 2º pequeno demais, nenhuma mensagem sobra e a foto some sem explicação. O mesmo `setErro(null)` em autosaves de fundo apaga erros de ações anteriores.

Em `atribuirFotos`, o `setEstado((e) => ({ ...e, atribuicoes }))` otimista não é desfeito quando o PUT falha (ex.: 429 com ◀ ▶ rápidos, limite de 180/min). A tela fica com uma ordem e uma capa que não são as que vão ser publicadas.

**Correção:**
- Acumular as recusas e mostrar todas ao fim: `setErro(recusas.join(' · '))`.
- Não limpar `erro` em salvamento de fundo.
- Em `atribuirFotos`, guardar as atribuições anteriores e restaurá-las se `chamar` devolver `null`.

### WR-F11: "Forma de envio" com valor fora das opções mostra um modo e grava outro

**Arquivos:**
- `resources/js/Components/Publicador/Mesa/CardLogistica.jsx:43`, `105-106`
- `resources/js/Components/Publicador/usePublicador.js:30` (padrão `modo: 'me2'`)

**Problema:** `opcoesEnvio` filtra pelos `modos_envio` da conta, mas o valor vem de `rasc.envio?.modo ?? 'me2'`. Em conta sem `me2`, o `<select>` nativo exibe a primeira opção ("Envio próprio") enquanto o rascunho continua `me2`. O Resumo diz "Mercado Envios". Escolher a opção que já aparece selecionada não dispara `onChange`, então não dá para corrigir sem passar por outra opção antes.

**Correção:** passar `vazio="Escolha…"` ao `Seletor` quando o modo atual não estiver em `opcoesEnvio`, e não assumir `me2` em `doEstado` quando a conta não o oferece.

### WR-F12: Campo de atributo com sugestões come o espaço digitado depois de um valor da lista

**Arquivo:** `resources/js/Components/Publicador/CampoAtributo.jsx:88-92` (lógica herdada do piloto, agora usada em todos os cards da mesa)

**Problema:** `igual` compara `texto.trim()` com os valores da lista e, se casar, grava `value_name: igual.name`. Com "Preto" na lista, quem digita "Preto " vê o espaço sumir (o campo é controlado por `value_name`). Não dá para digitar "Preto Fosco" letra a letra.

**Correção:** casar sem `trim` (`x.name.toLowerCase() === texto.toLowerCase()`) e manter `value_name: texto`. A normalização para o `value_id` pode ser feita no blur.

### WR-F13: Trocar de programa com uma busca ativa volta para o programa anterior

**Arquivo:** `resources/js/Pages/Mlb/AnunciosEmpresas.jsx:84-102`, `106`

**Problema:**
1. Com `?busca=abc` aplicada, o usuário clica "Incubadora". `trocarPrograma` faz `setBusca('')` + `router.get({ programa: 'incubadora' })`.
2. Na renderização seguinte, `busca ('') !== filtros.busca ('abc')`, e o efeito agenda `visitar` em 350 ms usando o `programa` **da renderização velha** (`'polos'`).
3. Quando os props novos chegam, `busca` não muda, então o efeito não roda de novo e o timer não é cancelado.
4. O timer dispara e navega de volta para Polos. Se a visita da Incubadora ainda estiver em voo, ela é cancelada.

`limparBusca` também gera visita dupla.

**Correção:**
```js
const pular = useRef(false);
const trocarPrograma = (p) => { pular.current = true; clearTimeout(espera.current); setBusca(''); router.get(/* ... */); };
useEffect(() => { if (primeira.current || pular.current) { primeira.current = false; pular.current = false; return; } /* ... */ }, [busca]);
```
O mesmo vale para `limparBusca`.

### WR-F14: Campos da mesa sem nome acessível

**Arquivos:**
- `resources/js/Components/Publicador/Mesa/comum.jsx:56-67` (`Tile` usa `<div>`/`<span>`, não `<label>`)
- `resources/js/Components/Publicador/Mesa/CardFichaTecnica.jsx:23-25`, `CardLogistica.jsx:23-25`, `72` (tipo de garantia)
- `resources/js/Components/Publicador/Mesa/CartaoVariante.jsx:83-96` (estoque, SKU, GTIN), `113-116` (preço)
- `resources/js/Components/Publicador/Mesa/CardTiposEPrecos.jsx:44-49` (título)
- `resources/js/Components/Publicador/Mesa/CardDescricao.jsx:20` (descrição)

**Problema:** os rótulos visuais são `<span>` soltos, sem `htmlFor`, `aria-label` ou `aria-labelledby`. O leitor de tela anuncia "editar texto" ou "caixa de seleção" sem nome em todos os campos obrigatórios da ficha, das variações, do título e da descrição.

**Correção:**
- Passar um `id` do `Tile` ao `CampoAtributo` e renderizar o rótulo como `<label htmlFor>`, ou usar `aria-label={a.nome}`.
- Nas variantes: `aria-label={`Estoque de ${v.rotulo}`}` (e equivalentes).
- No título: `aria-label={`Título ${NOME_TIPO[lt]}`}`.
- Na descrição: `aria-labelledby` apontando para o título do card.

## Info

### IN-F01: "Você já pode publicar" ignora a liberação, e a explicação do botão travado nunca aparece
**Arquivos:** `resources/js/Components/Publicador/derivados.js:59`; `resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx:16`, `38-41`

**Problema:**
- O texto de `ok` não considera `liberada`. Uma conta desliberada depois de uma conferência L3 OK mostra "Você já pode publicar" com o botão travado.
- `BASE_BOTAO` tem `disabled:pointer-events-none`, então o `title` (TITLE_CONTA_TRAVADA / TITLE_CONFERIR_LOCAL) não aparece no hover. O `aria-describedby` está num botão desabilitado e fora da ordem de foco.
- Antes do primeiro estado, `nota-conta-travada` não existe na página.

**Correção:** condicionar o texto a `liberada` e colocar a explicação fora do botão (ou num `span` com tooltip ao redor dele).

### IN-F02: Falha no recarregar depois da abertura é invisível, e o recarregar zera o acompanhamento da conferência
**Arquivos:** `resources/js/Pages/Mlb/Publicador/Editor.jsx:160`; `resources/js/Components/Publicador/usePublicador.js:129`; `resources/js/Components/Publicador/Mesa/BotaoAnunciarPorIa.jsx:21`

**Problema:**
- `erroCarga` só aparece com `! estado`. Se o GET do `recarregar` falha (ex.: depois da IA), a tela segue com o estado velho sem nenhum aviso.
- O efeito de abertura faz `setAguardando(null)`. Como o botão da IA não fica desabilitado durante a conferência, uma IA que conclui no meio de "Conferindo…" para o polling da conferência e reabilita a edição com o job ainda rodando.

### IN-F03: Mensagens técnicas ou em inglês chegam ao usuário
**Arquivos:** `resources/js/Components/Publicador/Mesa/CardProduto.jsx:36`; `resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx:23`; `resources/js/Components/Publicador/apoio.js:9-15`

**Problema:**
- `BuscaCategoria` mostra `e.message` ("Request failed with status code 422"). Isso ocorre, por exemplo, quando o nome do produto tem mais de 200 caracteres na busca automática.
- O sincronizar mostra "Server Error" em caso de 500.
- `mensagemDe` repassa "CSRF token mismatch." e "Too Many Attempts.".

**Correção:** usar `mensagemDe(e)` e traduzir 419 e 429.

### IN-F04: Handlers `onError` do Inertia que nunca disparam
**Arquivos:** `resources/js/Pages/Mlb/Publicador/Produtos.jsx:119-123`; `resources/js/Pages/Mlb/AnunciosEmpresas.jsx:79`, `100`; `resources/js/Components/Mlb/Publicador/ModalNovoProduto.jsx:42`

**Problema:**
- O `onError` só roda com erro de validação. 404, 500 e rede vão para o modal de erro do Inertia, então `erroAbrir` e `erroCarga` são estados mortos.
- No modal, se `router.get(data.url)` falhar, `enviando` fica `true` para sempre e `fechar()` não fecha.

### IN-F05: Componente `Falha` declarado dentro do render; ações de foto e de descrição sem trava de clique
**Arquivos:** `resources/js/Components/Publicador/FotosPorGrupo.jsx:58-63`, `38-47`; `resources/js/Components/Publicador/Mesa/LateralResumo.jsx:61-68`

**Problema:**
- `Falha` muda de identidade a cada render: remonta, e o foco do "enviar de novo" se perde.
- A lixeira apaga a foto de vez, sem confirmação e sem trava. Um duplo clique gera um segundo DELETE → 404 → faixa vermelha.
- "reenviar descrição" também não tem trava de clique.

### IN-F06: O mesmo aviso aparece em dois lugares
**Arquivos:** `resources/js/Components/Publicador/Mesa/CardProduto.jsx:139-143`; `resources/js/Pages/Mlb/Publicador/Editor.jsx:150`

**Problema:** `m.aviso` aparece também em âmbar dentro do card de categoria. Avisos que não são de categoria ("Ainda processando no servidor…", "Algumas variações juntaram dados…") viram alerta no card errado.

### IN-F07: A troca de `produtoId` no hook não tem guarda; hoje funciona só porque a página remonta
**Arquivo:** `resources/js/Components/Publicador/usePublicador.js:125-175`

**Problema:**
- `chamar`, o GET do polling (que também chama `onPublicou`) e os PUTs do cleanup não conferem `vivo` nem o produto.
- `ciente`, `erro`, `aviso` e `salvoEm` não são zerados.

Hoje isso não se manifesta porque o `router.get` da faixa remonta o `Editor`. Se alguém adicionar `preserveState` (ou reutilizar o hook), o estado do produto A aparece no B e o "ciente" de um produto vale no outro.

**Correção:** ou documentar que o hook exige remontagem (`key={produtoId}`), ou guardar o produto em cada resposta.

### IN-F08: Acessibilidade menor
**Arquivos:** `resources/js/Components/Publicador/Mesa/LateralValidacao.jsx:78`; `resources/js/Pages/Mlb/Publicador/Produtos.jsx:293-299`; `resources/js/Pages/Mlb/AnunciosEmpresas.jsx:247-255`; `resources/js/Components/Portal/Estrutura/FotosDoPar.jsx:49`; `resources/js/Pages/Mlb/Publicador/Editor.jsx:206`

**Problema:**
- `<span aria-label="Falta">` (papel genérico) não é anunciado.
- `<tr tabIndex={0}>` sem nome cria uma parada de foco redundante.
- Os botões da miniatura têm 18 px, abaixo do mínimo de 24 px.
- Abaixo de 1360 px, o checkbox "Li os avisos…" fica dentro da lateral recolhida, enquanto o "Publicar" da barra aparece desabilitado sem motivo visível.

### IN-F09: As opções de foto parecem não responder ao clique
**Arquivos:** `resources/js/Components/Publicador/Mesa/CardFotos.jsx:30`, `33`

**Problema:** `checked` lê `estado.rascunho.*` (servidor), mas a mudança vai por `mudarRasc`, com espera de 900 ms. A caixa volta a desmarcada até a resposta chegar.

**Correção:** ler de `m.rasc.incluir_geral ?? estado.rascunho.incluir_geral`. Como "Fotos diferentes para cada combinação" muda a estrutura dos grupos, também vale tratá-la como ação de estrutura.

### IN-F10: Falha ao copiar o link de reconexão é silenciosa
**Arquivo:** `resources/js/Components/Mlb/Publicador/LinkReconexao.jsx:19-28`

**Problema:** sem `navigator.clipboard` (contexto não seguro ou permissão negada), o `catch` só faz `setCopiado(false)`. Não há alternativa, como mostrar o link para seleção manual.

### IN-F11: "Quanto eu recebo?" fica desatualizado e, em conta não liberada, lê a conta do cliente
**Arquivo:** `resources/js/Components/Publicador/Mesa/CardTiposEPrecos.jsx:88-97`

**Problema:**
- A simulação continua na tela depois que o preço muda.
- O botão fica ativo em conta não liberada, e `EditorRascunhoService::simular` chama `/users/{sellerId}/shipping_options/free` com o token do cliente (é leitura).

**Para decidir:** confirmar com o usuário se isso está dentro do D26 ("conta de cliente: no máximo leitura"). Se não estiver, desabilitar o botão com `! pub.liberada` ou pular o frete no servidor.

---

_Revisado em: 2026-10-02T19:08:05Z_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_
