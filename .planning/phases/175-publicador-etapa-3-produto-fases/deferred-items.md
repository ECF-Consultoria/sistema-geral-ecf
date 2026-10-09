# Itens adiados — Fase 175 (Publicador, Etapa 3)

Achados **fora do escopo** do plano em que foram descobertos. Nada aqui foi
corrigido: estão registrados para não se perderem.

---

## 1. `PublicacaoService::problemas()` pode derrubar o editor com 500 (descoberto no 175-09)

**Onde:** `app/Services/Publicador/PublicacaoService.php`, ~L182-187.

**O que é:** o `try/catch` em volta de `$this->schemas->obter($r->categoria_id)`
captura **só `RegraViolada`**. Mas a camada de token (`ClienteMlPublicador` →
`MlColetaService::66`) lança `RuntimeException('[MLB Coleta] Resposta de token sem
access_token')` quando o ML responde sem token — exatamente a armadilha que o
`PreviaDaFaseService::maxTitulo()` já documentou e tratou com `catch (\Throwable)`
(desvio 1 do `175-05-SUMMARY.md`).

**Consequência medida:** `EditorRascunhoService::estado()` chama
`$this->publicacoes->problemas($p)` para toda publicação que não esteja `RUNNING`.
Logo, abrir o editor de um rascunho que **já tem publicação** com o ML fora do ar
(ou com a categoria fora do cache e o token vencido) derruba a tela inteira em
500 — por uma lista de problemas que é informativa.

**Como apareceu:** o `kitPublicado()` do `EstoqueDoKitTest` precisou criar a
publicação com status `RUNNING` para o `estado()` não tentar ler o schema de um
cenário sem categoria. O comentário no teste registra isso.

**Por que não foi corrigido aqui:** é pré-existente, está em arquivo que o 175-09
não toca (`PublicacaoService`), e a troca do `catch` muda o comportamento de todo
o caminho de publicação — não é escopo de uma plan de estoque de kit.

**Correção provável:** `catch (\Throwable $e)` com `Log::warning` e `$schema = null`,
no mesmo molde do `PreviaDaFaseService::maxTitulo()`.

_(resolvido em `0607fe38`, pela quick `261009-ng9` — foi exatamente essa correção. A
hipótese do parágrafo "Por que não foi corrigido aqui" **estava errada num ponto** e vale
registrar: a troca do `catch` NÃO muda o comportamento de todo o caminho de publicação,
porque `problemas()` tem um único chamador — `EditorRascunhoService:571` — e `iniciar()` /
`executarFatia()` têm os `catch` deles. O cenário foi reproduzido em teste antes do fix,
com a pilha de produção literal: `MlColetaService:58` → `ClienteMlPublicador:99`.)_

---

## 2. A capa do kit não PEDE "N unidades lado a lado" — só informa a contagem (descoberto no 175-06)

**Onde:** `app/Services/Creative/CreativePromptBuilder.php` (`linhasMaster()` e o
bloco CLAIMS), em diálogo com `CreativeSlotCatalog` (as `cena_padrao` de
`lifestyle` e `hero`).

**O que é:** o 175-06 fez o N chegar ao prompt como FATO — o bloco "CONTAGENS
CONFIRMADAS NO CADASTRO (respeite exatamente): unidades idênticas do mesmo
produto: 4". Isso cumpre TRUTH-02/03 e é o que o plano pedia. Mas duas coisas
continuam como estavam, e as duas são do prompt builder, que o 175-06 tinha
instrução explícita de **não** tocar:

1. **Nenhuma linha do prompt PEDE a composição** "mostrar as N unidades lado a
   lado, nada além do produto". A `cena` dos slots `lifestyle`/`hero` é a do
   catálogo (ou a que o LLM do planejamento propôs), e nenhuma das duas sabe
   que este anúncio é um kit. O modelo tem o número, mas a instrução de
   composição depende de ele inferir sozinho a partir da contagem.
2. **O bloco MASTER e as CLAIMS FIXAS dizem** "nunca mude a quantidade ou o
   conteúdo da embalagem" / "Não mude a quantidade ou o conteúdo da embalagem".
   Lido ao pé da letra isso é sobre EMBALAGEM, não sobre quantas unidades
   aparecem na cena — mas, num prompt em que a foto de referência mostra UMA
   unidade e a contagem diz 4, é ambíguo o suficiente para o modelo preferir
   reproduzir a foto.

**Por que não foi corrigido aqui:** o plano (e o briefing da execução) são
explícitos — "você NÃO precisa tocar no `CreativePromptBuilder`" — e esse arquivo
monta o prompt de TODOS os criativos em produção, inclusive os 3 kits de 7 slots.
Mexer nele por causa da capa de kit é mudança de comportamento para todo mundo.

**Correção provável (uma das duas, nunca as duas de uma vez):**
- (a) um bloco KIT no `CreativePromptBuilder`, emitido **só** quando
  `ProductTruth::contagens` tem a entrada `unidades idênticas do mesmo produto`,
  com a composição pedida e sem tocar em MASTER/CLAIMS; ou
- (b) `cena` própria para os dois slots da capa, montada pelo
  `CapaDoKitService`/`CreativeSlotCatalog` — mais contido, porque não mexe em
  nenhum prompt existente.

**Como conferir que o problema é real antes de corrigir:** gerar uma capa na
conta #459 depois do 175-07 e olhar se a imagem saiu com N unidades ou com uma.
É barato (duas imagens, ~R$ 1,10) e é a única prova honesta — o resto é
suposição sobre o que o modelo faz.

---

## 3. A tela do Produto não recebe `criativos_ia`, então a caixa da capa NUNCA aparece (descoberto no 175-07)

**Onde:** `app/Http/Controllers/MlbPublicadorFaseController::mostrar()`, o
`Inertia::render('Mlb/Publicador/Produto', [...])`.

**O que é:** o painel "Criar Fase N" só renderiza a caixa "Gerar a capa do kit"
quando a prop `criativos_ia` é `true` — e isso é de propósito (§4: "só aparece se
`CreativePermissao` + chave do Creative Engine permitirem"; D23 não se aplica,
porque capacidade do servidor não vira caixa desabilitada). O lado React está
pronto e coberto por teste: `Produto.jsx` faz `<PainelDoProduto {...props} />`,
`PainelDoProduto` repassa `criativos_ia` ao painel, e o painel mostra/esconde a
caixa.

**Consequência medida:** `mostrar()` devolve `empresa`, `liberada`, `produto`,
`fase_destacada`, `fases`, `proxima_fase`, `ofertas`, `historico`, `criativos`,
`mapeamento` e `abas` — **e nada mais**. Em produção `criativos_ia` chega
`undefined`, o default do componente é `false`, e a caixa da capa não é
renderizada. Resultado prático: hoje o Confirmar manda `capa: false` sempre, e a
capa do kit entregue pelo 175-06 **não é acionável pela tela**.

**Por que não foi corrigido aqui:** `MlbPublicadorFaseController.php` é do
`175-08`, que rodou **em paralelo** a este plano e é dono do arquivo (e das
rotas). O briefing da execução é explícito: "NÃO tocar em NENHUM arquivo de
servidor — se algo parecer exigir mudança de servidor, pare e registre".

**Correção provável (uma linha, no 175-08 ou numa quick):** no array do
`Inertia::render` do `mostrar()`, acrescentar

```php
'criativos_ia' => $creativeAtivo->ativa() && $creativePermissao->podeGerar($request->user()),
```

com `App\Services\Creative\CreativeEngineAtivo` e
`App\Services\Creative\CreativePermissao` injetados no método — **o mesmo par
literal** de `MlbPublicadorEntradaController::produtos()` (conferido em
2026-10-09, linha 260). O `mostrar()` hoje não recebe `Request`, então a
assinatura muda junto.

**Como conferir que ficou certo:** abrir um produto publicado da conta #459,
clicar "Criar Fase 2" e ver a caixa "Gerar a capa do kit" **marcada**. Com um
usuário sem a permissão, a caixa não deve aparecer (nem desabilitada).

_(resolvido em `8eb134b9`, pela plan 175-11.)_

---

## 4. A ação "Usar estoque calculado" da §6 não existe — e exige servidor (descoberto no 175-10)

**Onde:** o cartão da fase em
`resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx` (~L254) e,
do lado servidor, `routes/mlb_anuncios.php` + o
`RecalculoEstoqueDoKitService` (175-09).

**O que é:** a §6 da ETAPA-3 pede três coisas no cartão do combo vinculado —
"estoque próprio", o valor calculado ao lado **e a ação explícita 'Usar estoque
calculado'**. As duas primeiras existem desde o 175-04/06: o cartão mostra
`estoque próprio · calculado do base: N`. **A terceira não existe em nenhum
lugar:** o texto só aparece como comentário no docblock do
`VinculoDeKitService` ("a pessoa decide, não o sistema").

**Consequência medida:** combo vinculado nasce com `estoque_calculado = false`
(§6, de propósito), e por isso o `RecalculoEstoqueDoKitService` **nunca** o
recalcula quando o estoque do base muda. Hoje não há como a pessoa mudar de
ideia: o valor calculado é mostrado, mas não dá para adotá-lo. O combo fica para
sempre no estoque próprio, a não ser que alguém edite o rascunho à mão.

**Por que não foi corrigido no 175-10:** este plano é **frontend puro**, com
instrução explícita de não tocar em `app/` nem em `routes/`; não existe endpoint
que grave `estoque_calculado = true` (as rotas de vínculo gravam o parentesco e
nada mais). E o botão viveria em `PainelDoProduto.jsx`, que o briefing manda não
tocar (dono: 175-07).

**Correção provável (3 peças, nessa ordem):**
1. rota + ação no `MlbPublicadorFaseController` (ex.:
   `PUT publicador/empresas/{conta}/produtos/{produto}/estoque-calculado`), no
   mesmo grupo `role:admin` e com throttle nomeado, usando o
   `produtoDaConta()` que o 175-08 já extraiu (escopo por conta, 404 fora dele);
2. gravar `estoque_calculado = true` e chamar o `RecalculoEstoqueDoKitService`
   para o kit em questão, devolvendo o produto para a tela atualizar a linha;
3. botão "Usar estoque calculado" no cartão da fase, só quando
   `estoque_proprio === true` **e** `estoque_calculado_valor !== null` — com a
   confirmação de que isso **muda o rascunho** do kit (diferente de vincular,
   que não muda nada).

**Como conferir que ficou certo:** na conta #459, vincular um combo, mudar o
estoque do base e ver o cartão oferecer o número calculado; clicar e conferir no
editor do kit que o estoque do rascunho passou a ser o calculado — e que o
anúncio no ML **não** foi atualizado (§7: isso não é desta etapa).

---

## Item 5 — Flakiness nas suítes combinadas do Creative Engine (`Phase165`)

**Registrado em:** 2026-10-09, durante o plano **175-11** (fechamento dos furos da capa).
**Fora do escopo:** fixtures de `tests/Feature/Phase165/**`, de outra fase. Não é regressão do 175-11.

**O sintoma.** Rodando o filtro combinado do plano
(`--filter="Phase160|Phase161|Phase162|Phase165|Phase168|Phase169|Phase170|Phase171"`),
2 de 7 rodadas falharam — 1 falha numa, 2 noutra — sempre com a mesma mensagem:

```
SQLSTATE[23000]: Integrity constraint violation: 19
UNIQUE constraint failed: pub_imagens.rascunho_id, pub_imagens.sha256
```

As outras 5 rodadas saíram limpas: **419 passed + 1 incomplete, 0 failed**.

**O que já foi medido (não repetir):**
- **`Phase165` isolado é estável:** 3 rodadas, `1 incomplete, 151 passed` nas três. A falha
  **só** aparece no filtro combinado → é vazamento de estado/ordem **entre suítes**, não um
  teste quebrado.
- **O banco de teste é `:memory:`** (`phpunit.xml` L27-28), então **não** é interferência da
  sessão paralela nem do MariaDB local. A colisão é intra-processo.
- Os dois fixtures candidatos usam contadores de instância para garantir sha único —
  `CenarioCriativoDoPublicador::fotoComArquivo()` (lado `1200 + ++$fotoComArquivoSeq`) e
  `AprovacaoParaPubImagensTest::fotoExistenteNoGrupo()` (lado `900 + ++$fotoSeq`). Contador de
  **instância** zera por teste, então a colisão provavelmente vem de estado que **não** zera:
  `Storage::disk('local')` **real** (os fixtures gravam em `storage/app/publicador/{id}/{sha}.jpg`
  sem `Storage::fake` em alguns caminhos), um dedupe por sha256 que encontra arquivo de rodada
  anterior, ou cache de snapshot do `RascunhoRepository`.

**Por onde começar:** rodar o filtro combinado com `--order-by=defect` / `--stop-on-failure` para
pinar o nome do teste (nas 7 rodadas aqui ele não reapareceu depois que passei a rodar o filtro
isolado), e conferir se `Phase165` pede `Storage::fake('local')` em todos os `setUp()` que gravam
foto com arquivo.

**Por que não consertei agora:** o 175-11 toca 3 arquivos
(`MlbPublicadorFaseController`, `PlanejarKitCriativosJob`, `CapaDoKitTest`) e nenhum deles
escreve em `pub_imagens`; além disso o ramo novo do job só é alcançado por quem passa
`tiposFixos`, e o único chamador é o `CapaDoKitService` da Fase 175. Mexer em fixture de outra
fase para calar uma falha intermitente seria invadir escopo alheio.

_(resolvido em `640bc60f`, pela quick `261009-nxp`.)_

**A causa era mais simples do que a investigação acima supunha, e as duas hipóteses daqui
estavam erradas.** Não era vazamento de estado entre suítes, nem `Storage::disk('local')` real,
nem cache de snapshot do `RascunhoRepository` — nada disso participa. Era
`ColocarFotoNoGrupoSobTravaTest::fotoSemGrupo()`, que gerava o lado da imagem com
`jpeg(1200 + random_int(1, 200))` para ter sha único: **sorteio pode repetir**, e
`test_foto_entra_no_fim_do_grupo_sem_mexer_nas_que_ja_estavam_la` tira TRÊS fotos no MESMO
rascunho ⇒ `1 − (199/200)(198/200) ≈ 1,5%` de falha por rodada. Daí o padrão que mais confundiu:
`Phase165` isolado parecia estável (3 rodadas limpas é o esperado a 1,5%), e o filtro combinado
parecia culpado só porque tem mais rodadas acumuladas. **O `:memory:` e o `RefreshDatabase`
estavam certos o tempo todo; a colisão era intra-teste.**

Mecanismo **provado, não deduzido**: estreitando a faixa para `random_int(1, 1)`, só aquele teste
falhou — com o erro literal — e os três testes de UM sorteio passaram. Se fosse choque com o
cenário ou vazamento, eles teriam caído também. Fix: contador de instância em faixa própria
(`1400 + k`), e o docblock passou a registrar a faixa de cada fixture que minta bytes.

⚠️ **Fica de pé um risco parente, deliberadamente não tocado:** `fotoComArquivo()` do
`CenarioCriativoDoPublicador` e o provider falso do mesmo cenário dividem a faixa `1200 + k` — é
por isso que `RegenerarEAprovarKitTest` tem um desvio documentado. Essa sobreposição é
**determinística**, então ou colide sempre ou nunca, e hoje não colide (as suítes passam em toda
rodada). Separá-la exigiria mexer no cenário de que todo o `Phase165` depende, para resolver um
problema que não se manifesta — e um dos testes se apoia na coincidência de propósito.
