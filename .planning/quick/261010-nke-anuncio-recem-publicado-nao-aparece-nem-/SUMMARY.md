---
phase: quick-261010-nke
quick_id: 261010-nke
plan: 01
subsystem: publicador
tags: [mercado-livre, acervo, fila-high, publicacao, triagem, deadlock]

requires:
  - phase: 134 (acervo ML)
    provides: "`ml_acervo_itens`, `MlAcervoService::processarLote()`, `AcervoEscritaLock`, aba Publicações"
  - phase: 164/172 (Publicador)
    provides: "`PublicacaoService::concluir()`/`encerrar()`, `pub_publicacao_itens`"
provides:
  - "`MlAcervoService::coletarItens()` — caminho estreito de coleta por lista de MLB ids"
  - "`SincronizarAcervoDoPublicadoJob` — job interativo na fila `high`"
  - "Disparo do sync nos dois caminhos de publicação (avulso e fila em rodadas)"
  - "`acionaveis` (filtro padrão) cobrindo `under_review`"
affects: [acervo, publicador, aba-publicacoes, triagem-d09]

tech-stack:
  added: []
  patterns:
    - "Caminho estreito de coleta: reusa `processarLote()` em vez de criar escrita nova"
    - "Disparo de sync no ponto único de convergência dos dois caminhos de publicação"

key-files:
  created:
    - app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php
    - tests/Unit/Phase134/ColetaDoPublicadoTest.php
    - tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php
    - tests/Feature/Publicador/Lote/AcervoDoRecemPublicadoNaFilaTest.php
  modified:
    - app/Services/Mlb/Acervo/MlAcervoService.php
    - app/Services/Publicador/PublicacaoService.php
    - app/Services/Publicador/AcervoTriagemService.php
    - app/Http/Controllers/MlbAnuncioController.php
    - tests/Feature/Phase134/MeusAnunciosTest.php
    - tests/Unit/Publicador/AcervoTriagemServiceTest.php

key-decisions:
  - "Fonte única: a publicação DISPARA o sync; não escreve em `ml_acervo_itens` por um segundo caminho"
  - "Caminho estreito reusando `processarLote()` — nunca `SyncMlAcervoCompanyJob` (descarte silencioso do `ShouldBeUnique`)"
  - "Disparo em `concluir()` e `encerrar()`, não no `AgendadorDaFila` (duplicaria)"
  - "`under_review` ACRESCENTADO ao default; `paused` intacto; filtros estreitos intactos"

patterns-established:
  - "Escrita no acervo: toda nova necessidade de gravar linha passa por `processarLote()`, nunca por upsert próprio"
  - "Carimbo de `coleta_erro` em faixa é proibido fora da coleta completa (E6 do debug doc)"

requirements-completed: [NKE-01, NKE-02, NKE-03]

duration: ~2h45m
completed: 2026-10-10
---

# Quick 261010-nke: anúncio recém-publicado no acervo e no filtro padrão

**O MLB criado pela publicação passa a existir em `ml_acervo_itens` na hora (job estreito na fila
`high`, reusando o único caminho de escrita que já existia), e `under_review` entrou em
`acionaveis` — sem essas duas pontas juntas o anúncio continuaria invisível na aba Publicações.**

## Performance

- **Duração:** ~2h45m (17:20 → 18:05 BRT, com ~50 min só de suítes longas)
- **Tarefas:** 3 de 3, cada uma em ciclo RED → GREEN
- **Arquivos:** 4 criados, 6 modificados
- **Testes novos:** 15 (8 unit do caminho estreito, 6 feature do disparo, 1 unit do filtro)

## Gates — números ANTES e DEPOIS

| Gate | Suítes | ANTES | DEPOIS |
|---|---|---|---|
| 1 | `tests/Unit/Phase134` + `tests/Feature/Phase134` + `tests/Feature/Phase135` + `AcervoTriagemServiceTest` + `VisaoGeralTest` + `PainelVisaoGeralServiceTest` | **361 passando / 0 falhando** | **375 passando / 0 falhando** |
| 2 | `tests/Unit/Publicador` + `tests/Feature/Publicador` | **1706 passando / 0 falhando** | **1713 passando / 0 falhando** |
| 3 | `npm run test:js` | (não medido antes; o orquestrador previu 1 falha pré-existente) | **2125 pass / 1 fail** — a pré-existente `estrutura-grade-glide` ("Características secundárias nasce recolhido"). Não corrigida. |

**Os baselines do gate 1 e do gate 2 fui eu que medi — o orquestrador não os tinha.**

Duas observações honestas sobre os números:

1. **Gate 1 — baseline 361/0, medido ANTES de qualquer linha de código.** Depois: 375. O delta de
   +14 é exatamente o que escrevi nessas suítes (8 do `ColetaDoPublicadoTest` + 5 do
   `MeusAnunciosTest` + 1 do `AcervoTriagemServiceTest`). **Zero falhas pré-existentes ali** — então
   qualquer falha futura nessas suítes é regressão de verdade, não ruído herdado.
2. **Gate 2 — o baseline de 1706 foi medido em paralelo, e isso tem uma ressalva.** Rodei a suíte
   do Publicador em background enquanto lia o código, e ela terminou DEPOIS de eu já ter editado
   `MlAcervoService` e `PublicacaoService` — ou seja, os testes que correram na segunda metade da
   execução já usavam código novo. **O número é confiável no que importa (0 falhas) mas está
   tecnicamente contaminado como "antes".** Registro isso em vez de apresentá-lo como medição
   limpa. O número do orquestrador era 1702; o meu, 1706. O delta ANTES→DEPOIS fecha exatamente:
   1706 + 7 testes novos nessas duas pastas (5 do avulso + 1 do lote + 1 do
   `AcervoTriagemServiceTest`, que mora em `tests/Unit/Publicador`) = 1713.

Outros gates:

- `git diff --stat -- resources database` → **vazio**. Nada em `resources/`, nenhuma migration.
- `npm run build` → **NÃO executado**, como o plano exige.
- Nenhum pacote instalado.

## O que foi feito

### Tarefa 1 — caminho estreito de coleta + job na fila `high`

`MlAcervoService::coletarItens(Company $company, array $mlItemIds): array` — método público novo
que coleta a camada barata de uma LISTA de ids. Normaliza os ids, retorna zeros sem tocar em HTTP
nem banco com lista vazia, monta os três mapas que `processarLote()` exige e chama
`processarLote()` por chunk de `config('mlb_acervo.lote_multiget')`.

**Nenhuma escrita nova:** o `upsert()` continua sendo o de `processarLote()`, com o 3º argumento
literal `COLUNAS_CAMADA_BARATA` e dentro de `AcervoEscritaLock::naEmpresa()`. O lock e a proteção
da camada CARA vêm de graça justamente porque não há caminho próprio.

Duas diferenças deliberadas em relação a `coletarCamadaBarata()`:

- o mapa de buy box é restrito aos ids pedidos (`whereIn`), não um `pluck` da empresa inteira — para
  2 ids o original puxaria até 66 mil strings;
- o tratamento de erro **só loga e relança**. **Nenhum `update(['coleta_erro' => ...])`** — nem de
  faixa, nem dos ids pedidos.

`SincronizarAcervoDoPublicadoJob`: `ShouldQueue, ShouldBeUnique`, `tries = 3`, `timeout = 120`,
`backoff [30, 120]`, `uniqueId()` por `companyId + md5(ids)` e `uniqueFor() = 600`.
**`onQueue('high')` no construtor**, nunca redeclarando `$queue`. `handle()` pula com
`Log::warning` quando a Company não existe, não tem token, ou o token não está `active`.
`failed()` só loga.

### Tarefa 2 — disparo nos dois caminhos de publicação

`PublicacaoService::sincronizarAcervoDoPublicado()`, chamado ao fim de **`concluir()`** e de
**`encerrar()`**, logo depois do `abrirTarefaPosPublicacao($p)` que já estava nos dois — mesmo
precedente, mesmo `try/catch (\Throwable)` fail-open. O `grep` confirma três ocorrências do nome
(definição + as duas chamadas).

A Company é resolvida por `PubProduto::whereKey($r->produto_id)->value('company_id')` —
deliberadamente **não** por `contaOuNula()`/`contaFixada()`, que devolvem a âncora que PUBLICA (e
preferem `MlbEmpresa`). O que o acervo precisa é a Company cuja tela o usuário abre.

Nada foi acrescentado no `AgendadorDaFila`: os dois caminhos passam pelo mesmo `executarFatia()`,
e um dispatch lá duplicaria.

### Tarefa 3 — `under_review` em `acionaveis`

Uma linha de código (`'acionaveis' => ['active', 'paused', 'under_review']`) e um comentário longo
em `AcervoTriagemService::escopo()` com o porquê e o impacto. No `MlbAnuncioController::meus()`, só
o comentário do `$statusFiltro` foi atualizado — a lista fechada
`['acionaveis','ativos','pausados','encerrados','todos']` não mudou.

## O que o `acionaveis += under_review` faz com os chips e com a ordenação (verificado, não suposto)

**Chips (D-09) — não mudam de significado.** Li `AnuncioSaudeService::triagem()`: `MOTIVO_PAUSADO`
só é carimbado quando `$status === 'paused'` (linha 124) e `MOTIVO_SEM_ESTOQUE` só no `elseif` de
`$status === 'active'` com estoque 0 (linha 126). Um `under_review` é estruturalmente incapaz de
entrar nesses dois chips. Confirmei por teste em dois níveis: chamei
`AnuncioSaudeService::triagem()` com um item real `under_review [waiting_for_patch]`, estoque 5, e
assertei que `motivos` não contém nenhum dos dois; e depois assertei na tela que os chips "Pausado"
e "Sem estoque" ficam em 0 com esse item presente. Um `under_review` só pode aparecer nos chips de
ficha/catálogo/foto, que é o que ele de fato tem.

**O chip "Pausado" continua contando o pausado.** Teste próprio: com um `paused` e um
`under_review` no acervo, o chip "Pausado" segue em 1. A emenda de 2026-08-10 ao D-03 não foi
tocada.

**Ordenação (D-12) — nenhum efeito.** Li o `meus()` do controller: a ordenação é
`orderByDesc('severidade')` → `nota_ecf IS NULL ASC` → `orderBy('nota_ecf')` →
`orderBy('ml_item_id')`. **Nenhuma das quatro cláusulas olha `status`** — a ordenação é agnóstica
dele. Consequência prática: um `under_review` saudável (severidade 0, nota alta) ordena no FIM da
lista, como qualquer item sem problema. Quem precisa achá-lo rápido usa a busca — que agora o
alcança, e era metade da queixa.

**O universo da triagem cresce.** `triagem()` roda sobre o mesmo `escopo()`, então o denominador
passa a incluir os `under_review`. É o efeito pretendido (o default é "o que precisa de você"), não
um efeito colateral — e ele não move nenhum chip crítico, pelo parágrafo acima.

## Por que o caminho estreito e não o `SyncMlAcervoCompanyJob`

Três motivos, o primeiro sendo o decisivo:

1. **`SyncMlAcervoCompanyJob` é `ShouldBeUnique` por `company->id` com `uniqueFor()` de 3600s e
   `timeout` de 1800s.** Se a varredura diária daquela empresa estiver em curso, o dispatch da
   publicação é **descartado em silêncio** pelo lock de unicidade do Laravel. Ou seja: a correção
   simplesmente não aconteceria — e deixaria de acontecer justamente nas contas grandes, que são as
   que levam mais tempo varrendo e portanto têm a maior janela de descarte. Um bug que volta
   sozinho em silêncio é pior que o bug original.
2. Ele varre a conta INTEIRA por `scroll_id` (~3.340 chamadas na maior conta, ~30 min) para mostrar
   um anúncio só.
3. Ele mora na `default`, que fica atrás do Adman e do Acervo.

E a camada CARA (`SyncMlAcervoDetalheJob`) também não serve: `MlAcervoDetalheService` faz
`MlAcervoItem::where(company_id)->where(ml_item_id)->update([...])` — **enriquece linha existente e
nunca cria**. Para um MLB que não está no acervo, esse job não faz nada.

O caminho estreito não é "mais código de escrita": é o MESMO `processarLote()`, recebendo uma lista
de ids em vez da varredura.

## Deadlock do acervo — o que foi respeitado

Li `.planning/debug/resolved/acervo-deadlock-upsert.md` antes de desenhar qualquer coisa. O E6 é o
que molda este trabalho: o `catch` de nível de empresa de `coletarCamadaBarata()` carimba
`coleta_erro` em até 66.747 linhas, o que (a) mentiu na tela marcando 136.432 itens como falhos por
algumas dezenas de eventos e (b) realimentou o próprio deadlock.

Consequências concretas aqui:

- `coletarItens()` **não carimba `coleta_erro`** em caso nenhum — só `Log::error` + relançar.
- `SincronizarAcervoDoPublicadoJob::failed()` **só loga**.
- Nenhum `upsert`/`update` novo em `ml_acervo_itens`: toda escrita segue em `processarLote()`,
  dentro de `AcervoEscritaLock::naEmpresa()`, com um único statement por transação (a série diária
  continua fora do lock, como o E9 exige).
- `tests/Unit/Phase134/SerializacaoEscritaAcervoTest.php` (que existe para provar que toda escrita
  passa pelo lock) segue verde, sem afrouxamento.

Um teste próprio trava isso: com o `/items` respondendo 500, a exceção propaga e uma linha
pré-existente **de fora da lista pedida** continua com `coleta_erro = null`.

## Verificação negativa (os testes têm poder de detecção real)

Dois testes poderiam passar por acidente. Conferi os dois:

- **Fail-open do disparo** (`test_falha_no_disparo_nao_desfaz_a_publicacao`): instrumentei
  temporariamente o `catch (\Throwable)` de `sincronizarAcervoDoPublicado()` para
  `catch (\LogicException)` e rodei o teste — ele **ERROU** (a exceção propaga por fora de
  `executarFatia()`, porque `concluir()` é chamado fora do `try` dele). Restaurei o `\Throwable` e
  conferi por `grep` que não sobrou nenhuma ocorrência de `LogicException` no arquivo.
- **Disparo nos dois caminhos**: os dois testes de dispatch (avulso e fila em rodadas) falharam no
  RED com "size 0 matches expected size 1" antes de a chamada existir. Se alguém remover a chamada
  de `concluir()`/`encerrar()`, eles voltam a falhar.

Os três testes negativos da Tarefa 2 (nada criado → não despacha; `MlbEmpresa` sem Company → não
despacha; fail-open) passavam já no RED, por serem guardas de regressão — e isso está dito aqui em
vez de maquiado.

## O que NÃO foi feito, e o que precisa de decisão do usuário

Nada disso foi implementado por conta própria. São perguntas abertas:

1. **Opção "Em revisão" no select de status.** `MeusAnuncios.jsx` hoje **não renderiza o status por
   linha** (as ocorrências de `status` no arquivo são todas do filtro), então `under_review` entrar
   em `acionaveis` não produz badge nem jargão novo na tela. Adicionar a opção exigiria mexer em
   `resources/`, rodar `npm run build` e gerar churn de hash em `public/build/` numa árvore
   compartilhada com outro dev. **Pergunta:** quer poder isolar "Em revisão" num filtro próprio? Se
   sim, vira tarefa própria junto da próxima mudança que já tocar essa tela — e o comentário
   "ativos + pausados" da linha ~36 do `MeusAnuncios.jsx` fica desatualizado até lá.
2. **Busca por SKU.** `ml_acervo_itens` **não tem** coluna de SKU, e o multiget não pede
   `seller_custom_field`. A busca cobre `title` e `ml_item_id`. Buscar por SKU hoje é impossível sem
   coluna nova + atributo novo no multiget + migration. **Pergunta:** faz falta?
3. **Status `inactive` fora de todos os filtros estreitos.** Fechar um item moderado o deixa
   `inactive`, e `encerrados` cobre só `['closed']` — então um `inactive` só aparece em "Todos". É
   buraco **pré-existente** e não afeta o anúncio recém-publicado (que nasce `active` ou
   `under_review`). Não mexi: mudar `encerrados` alteraria o significado do rótulo. **Pergunta:**
   quer que `inactive` entre em algum filtro?
4. **Selo de origem do item publicado pelo Publicador.** `mapaRascunhos()` lê `MlAnuncioRascunho`
   (o assistente ANTIGO), não `PubRascunho`, e `cadastrarNaRegua()` grava em `EstruturaAnuncio`,
   não em `Publicacao`. Logo o item publicado pelo Publicador entra no acervo com `origem = legado`.
   **Isso já é assim hoje pela varredura diária — não é regressão desta tarefa** — mas passa a
   ficar visível mais cedo. **Pergunta:** quer selo próprio para o item do Publicador?
5. **Segunda passada de sync alguns minutos depois.** O item pode ir a `under_review` minutos após
   o POST, então a linha gravada pelo sync imediato pode nascer `active` e ficar desatualizada até
   a varredura diária. Não adicionei uma segunda passada: com `under_review` dentro de
   `acionaveis`, **os dois valores aparecem no filtro padrão**, então a defasagem de status não
   esconde o anúncio. Trade-off deliberado.
6. **Precificação.** Não tocada — é outra tarefa, com decisão do usuário pendente.

## Conferência visual é do usuário

⚠️ **Não há navegador nesta sessão e eu não vi tela renderizada nenhuma.** Tudo acima é código
lido, teste escrito e número medido. A confirmação de que o anúncio aparece na aba Publicações e de
que a busca o acha depois de publicar **é do usuário**, em produção, depois do deploy.

Também não consultei produção: subagente é bloqueado nisso neste projeto. Os dois MLB citados
(`MLB5366398961`, `MLB5366495199`) vêm da prova que o orquestrador fez, não de consulta minha.

## Desvios do plano

Dois, os dois menores e declarados:

1. **Dois testes a mais que o plano pediu na Tarefa 1** (`handle` de empresa inexistente, além do
   `handle` sem token ativo): o `find()` pode devolver `null` e esse ramo precisava de guarda
   própria. Foram 8 testes em vez dos 7 do plano.
2. **Um commit único no fim**, como `<ambiente_e_commit>` do plano manda, em vez de um commit por
   tarefa. Numa árvore compartilhada com outro dev, menos operações de git é menos corrida de
   índice — e o plano foi explícito. O ciclo RED→GREEN de cada tarefa foi respeitado (cada RED
   rodado e conferido falhando antes do código), só não virou commit separado.

Nada de Rule 4 apareceu: nenhuma decisão arquitetural, nenhuma migration, nenhum pacote.

## Pendente de produção (não feito por esta sessão)

- Deploy. Nenhum `push`, nenhum comando no VPS, nenhuma consulta a produção.
- Depois do deploy, vale conferir no log: `[MLB Anuncios] acervo do recém-publicado — empresa {id}`
  aparece a cada publicação, e `[MLB Anuncios] sync do recém-publicado pulado` aparece só nas contas
  ancoradas em `MlbEmpresa` sem Company.
