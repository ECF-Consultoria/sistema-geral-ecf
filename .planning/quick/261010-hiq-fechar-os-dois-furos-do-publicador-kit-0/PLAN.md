---
quick_id: 261010-hiq
slug: fechar-os-dois-furos-do-publicador-kit-0
type: quick
date: 2026-10-10
autonomous: true
wave: 1
depends_on: []
files_modified:
  - app/Services/Publicador/VinculoDeKitService.php
  - app/Models/PubProduto.php
  - app/Services/Publicador/CriarFaseService.php
  - app/Services/Publicador/FamiliaDeFasesService.php
  - resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx
  - resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx
  - tests/Feature/Publicador/CompostoDoPlanejamentoTest.php
  - tests/Feature/Publicador/VinculoDeKitTest.php
  - tests/Feature/Publicador/TelaDoProdutoTest.php
  - tests/Feature/Publicador/Concerns/CenarioPlanejamentoDaFase.php
  - tests/Unit/Publicador/CriarFaseServiceTest.php
  - tests/js/publicador-produtos-fases.test.js
  - tests/js/publicador-produtos-layout.test.js

must_haves:
  truths:
    - "Mandar `base_id` de um Combo/Kit/Combit do Planejamento no endpoint de vincular é recusado pelo SERVIÇO com a regra KIT-06 e a mensagem de `motivoKit06`, e nada é gravado"
    - "Um composto do Planejamento CONTINUA podendo ser o produto vinculado (o kit), só não pode ser a base"
    - "O primeiro kit de 5 unidades nasce com `fase = 5` (antes nascia 2), tanto em Criar Fase N quanto em Vincular"
    - "Família com Kit 2 e Kit 4 sugere quantidade 3 E Fase 3 (antes sugeria Fase 5), e `familia()` sai na ordem 1 → 2 → 3 → 4"
    - "`PubProduto::proximaFase()` não existe mais no código"
    - "O número que a tela mostra ANTES de confirmar é o mesmo que o servidor grava: o rótulo do vínculo deriva da QUANTIDADE, e acompanha o campo ao vivo"
    - "Quando a sugestão não traz o N (campo vazio), a tela não afirma número nenhum — nem 'Fase 1' nem o 2 fixo de antes"
    - "Nenhum arquivo de `routes/` ou `database/` foi tocado; nenhuma migration e nenhum backfill"
  artifacts:
    - path: "app/Models/PubProduto.php"
      provides: "`faseDaQuantidade(int): int` pura; `proximaFase()` removida; docblock da classe sem a afirmação cronológica"
      contains: "faseDaQuantidade"
    - path: "app/Services/Publicador/VinculoDeKitService.php"
      provides: "recusa KIT-06 do composto como base + linha nova na tabela de recusas do docblock; fase derivada da quantidade"
      contains: "KIT-06"
    - path: "resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx"
      provides: "`faseDaQuantidade()` (espelho do PHP) e `faseDoVinculo()` (o número que a tela pode afirmar, ou null); `proximaFaseDaFamilia()` removida"
      contains: "faseDaQuantidade"
    - path: "tests/Feature/Publicador/CompostoDoPlanejamentoTest.php"
      provides: "KIT-06 no caminho do vínculo (serviço e endpoint) + a prova de que o composto segue aceito COMO KIT"
  key_links:
    - from: "app/Services/Publicador/VinculoDeKitService.php"
      to: "app/Services/Publicador/PlanejamentoDaFaseService.php"
      via: "estáticas tipoComposto/motivoKit06 (mesma fonte do CriarFaseService, sem duplicar a regra)"
      pattern: "PlanejamentoDaFaseService::(tipoComposto|motivoKit06)"
    - from: "app/Services/Publicador/FamiliaDeFasesService.php"
      to: "app/Models/PubProduto.php"
      via: "`numero` do botão Criar Fase N derivado da quantidade sugerida"
      pattern: "faseDaQuantidade"
    - from: "resources/js/Pages/Mlb/Publicador/Produtos.jsx"
      to: "resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx"
      via: "import nomeado de `faseDoVinculo` nos três pontos que hoje chamam `proximaFaseDaFamilia`"
      pattern: "faseDoVinculo"
---

# Fechar os dois furos do que já existe no Publicador (Fases 172-175)

Dois furos no que já está em produção — e o segundo tem metade no servidor e metade
na tela.

**Furo 1 — a recusa KIT-06 falta no vínculo.** `CriarFaseService::recusarComposto()`
já recusa *criar fase* a partir de um composto do Planejamento (Combo/Kit/Combit
vindo do Portal). O endpoint de **vincular** ainda aceita um composto como BASE. A
sugestão automática não propõe isso, mas `base_id` é o **único id de entidade que
vem do corpo da requisição** nesta fase (está escrito no docblock do próprio
`VinculoDeKitService`): esconder não é impedir.

**Furo 2 — `proximaFase()` é cronológica e o resto do código não.**
`PubProduto::proximaFase(array $fases)` devolve `max($fases) + 1` — ordem de
criação. O resto do módulo já lê `fase` como o degrau derivado da QUANTIDADE, e
isso é medido no código, não deduzido:

- `ProgramasPublicadorService::bucketDaFase()` manda `fase >= 3` para `fase3_mais`
  e trata `fase 2` como kit.
- `PainelVisaoGeralService` tem o comentário literal: *"Rotulado kits, nunca
  Fase 2: `quantidade_kit >= 2` inclui o kit de 3, que é Fase 3"*.

A quantidade é editável no painel "Criar Fase N", então o bug é alcançável com um
clique: criar um **Kit 5** como primeiro kit da família dá `proximaFase([1]) = 2`.
O cartão mostra "Kit 5" (via `rotuloFase`) e a Visão geral conta o mesmo produto no
bucket **Fase 2**. E numa família com Kit 2 + Kit 4, `proximaQuantidade` sugere 3
enquanto `proximaFase` dá 4 — o Kit 3 nasceria "Fase 4" e, como `familia()` ordena
por `fase`, apareceria DEPOIS do Kit 4.

**Decisão do usuário (10/10), que autoriza o conserto:** *"É metodologia e também
ordem, temos que fazer seguindo a ordem de Fase 1, Fase 2 e assim por diante."*

## Furo 2 tem um espelho na tela, e ele entra nesta quick

`resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx` exporta
`proximaFaseDaFamilia(produtos, baseId)` — `Math.max(1, ...fases, 1) + 1`, o mesmo
cronológico do PHP — e alimenta **três** pontos de
`resources/js/Pages/Mlb/Publicador/Produtos.jsx`: a faixa de sugestões (linha 764,
`faseDaPrimeira`), a prop `proximaFase` do `PainelDoProdutoLateral` (linha 1211) e a
prop `proximaFase` do próprio diálogo (linha 1237).

Se o servidor passa a derivar da quantidade e o espelho fica cronológico, o diálogo
diz "Vincular como Fase 2" e o banco grava Fase 5. Isso não fecha o furo: **move o
furo para um lugar pior**, porque a pessoa lê o número errado ANTES de confirmar.
Por isso esta quick mexe em `resources/` — a restrição "só backend" foi revista pelo
coordenador em 10/10.

O conserto da tela é mais simples do que o que existe: a fase do vínculo depende só
da QUANTIDADE que está sendo vinculada, não da família. A função deixa de precisar
da lista de produtos e do `baseId`.

**Medido no código, decide o formato do rótulo:** no diálogo o campo de unidades é
**editável ao vivo** (estado `quantidade`, `setQuantidade`, `autoFocus`, inicializado
por `quantidadeInicial(sugestao)`), e `quantidadeInicial` devolve string **vazia**
quando a sugestão não traz o N (casamento por SKU). Então:

1. o rótulo tem de seguir o campo ao vivo — número que não acompanha o que a pessoa
   digitou é a mesma mentira em versão lenta;
2. com o campo vazio/inválido **não existe número honesto**: hoje a tela mostra um
   `2` fixo e com a derivação crua mostraria "Fase 1", que é pior. O rótulo passa a
   sair **sem número** até a pessoa informar a quantidade (o botão já fica
   desabilitado nesse estado, com a explicação que já existe).

## Nada de migration e nada de backfill — já medido em produção

Consulta à VPS em 10/10 (`plink` + `artisan tinker`), **a NÃO refazer**:

- `pub_produtos` = **31**, dos quais **1 único kit**:
  `#32 sku=CAD+MESA-RD-CBT4-KIT2 fase=2 qtd=2 base=5`.
- `whereColumn('fase', '<>', 'quantidade_kit')` = **0**.

`fase = quantidade_kit` **já vale para 100% do dado existente**: nenhum rótulo muda,
nenhuma contagem muda. **Não planejar migration. Não planejar comando de backfill.
Não consultar a VPS de novo** (o executor é bloqueado em produção neste projeto).
Esses dois números entram na SUMMARY como a justificativa de não ter escrito nenhum
dos dois.

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@./CLAUDE.md

<interfaces>
Contratos que o executor já tem em mão — não precisa explorar o código para
descobri-los.

**PHP** — `app/Services/Publicador/PlanejamentoDaFaseService.php` (mesmo namespace do
`VinculoDeKitService`, então **não precisa de `use`**):

    public static function tipoComposto(PubProduto $p): ?string   // 'combo'|'kit'|'combit'|null
    public static function motivoKit06(string $tipo): string
    public static function quantidadeSugerida(array $doPlanejamento, array $daFamilia): int

`app/Services/Publicador/CriarFaseService.php:257` — a fonte que NÃO pode ser
duplicada (é a mesma regra, aplicada no outro caminho):

    public function recusarComposto(PubProduto $base): void
    {
        $tipo = PlanejamentoDaFaseService::tipoComposto($base);
        if ($tipo !== null) {
            throw new RegraViolada('KIT-06', PlanejamentoDaFaseService::motivoKit06($tipo));
        }
    }

`app/Models/PubProduto.php` — os vizinhos do método novo:

    public function familia(): Collection                            // ordena por `fase`, depois `id`
    public static function proximaFase(array $fases): int            // SAI nesta quick
    public static function proximaQuantidade(array $quantidades): int // o MOLDE do método novo (puro)

`app/Http/Controllers/MlbPublicadorFaseController.php:344` — `vincular()` resolve
`base_id` dentro de `produtosQuery()` (404 fora do escopo) e converte
`RegraViolada` em 422 com a chave `regra` no JSON.

Rota do vínculo (`routes/mlb_anuncios.php:297`, **não muda**):

    PUT /mlb/anuncios/publicador/empresas/{conta}/produtos/{produto}/vinculo

**JS** — o que existe hoje em `DialogoVincularKit.jsx` (exports e âncoras):

    export function erroLocalDaQuantidade(valor)      // fica como está
    export function erroDeRecusa(dados)               // fica como está
    export function proximaFaseDaFamilia(produtos, baseId)  // SAI nesta quick (linha ~104)
    export function quantidadeInicial(sugestao)       // '' quando sugestao.quantidade é null
    // helpers locais já disponíveis: numeroSeguro, objetoSeguro, textoSeguro

    // dentro do componente:
    const [quantidade, setQuantidade] = useState(() => quantidadeInicial(sugestao)); // campo AO VIVO
    const fase = numeroSeguro(proximaFase) ?? 2;                       // linha ~160 — o 2 fixo
    ... `agora é a Fase ${numeroSeguro(data.produto.fase) ?? fase} de ...`  // linha ~210
    ... Só registra que ele é a Fase {fase} deste produto base.         // linha ~304
    ... {recusando ? 'Não é kit, descartar' : `Vincular como Fase ${fase}`} // linha ~326

`PainelDoProdutoLateral.jsx` (a quantidade NÃO é editável aqui; o número vem da
página):

    const fase = typeof proximaFase === 'number' && Number.isFinite(proximaFase) ? proximaFase : 2; // linha 108
    ... . Confirme o vínculo para ele virar Fase {fase}.   // linha 182
    ... {`Vincular como Fase ${fase}`}                      // linha 186

`Produtos.jsx` — os três chamadores (764, 1211, 1237) e o shape da sugestão:
`sugestaoSegura(produto)` devolve `sugestao_kit` = `{base_id, base_sku, base_nome,
quantidade, origem, conflito_heuristica}` — a quantidade da sugestão está em
`sugestao.quantidade` e pode ser `null`.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Tarefa 1: KIT-06 também no vínculo — composto do Planejamento não é base de fase nenhuma</name>
  <files>tests/Feature/Publicador/CompostoDoPlanejamentoTest.php, app/Services/Publicador/VinculoDeKitService.php</files>

  <behavior>
RED primeiro, no arquivo que já é a casa da regra KIT-06
(`tests/Feature/Publicador/CompostoDoPlanejamentoTest.php`, seção
`═══ KIT-06: "Criar Fase N" recusado ═══`). O cenário e os helpers já existem lá:
`composto(string $tipo, string $sku, ?string $status)` cria um `pub_produto` ligado
a uma oferta do tipo pedido, `grupoComRascunho()` dá uma base de verdade (a oferta
dela é `simples`, logo `tipoComposto()` devolve `null`), e `$this->equipeP` é o ator
que os outros testes de endpoint deste arquivo usam.

Acrescentar `use App\Services\Publicador\VinculoDeKitService;` aos imports.

- **Teste 1 — o serviço recusa, para os três tipos.** Para cada `$tipo` em
  `['combo', 'kit', 'combit']`: um `composto($tipo, ...)` como BASE e um produto
  avulso do Publicador (criado com `PubProduto::create([...])`, `oferta_id` NULL,
  `origem = ORIGEM_PUBLICADOR`, as âncoras `$this->empresaP` / `$this->mlbP`) como
  produto a vincular. `app(VinculoDeKitService::class)->vincular($avulso, $composto, 2)`
  tem de lançar `RegraViolada` com `regra === 'KIT-06'` e mensagem **idêntica** a
  `PlanejamentoDaFaseService::motivoKit06($tipo)` (`assertSame`, não
  `assertStringContainsString` — a mensagem vem de lá, não é texto novo). Usar o
  `try { ...; $this->fail('devia recusar com KIT-06'); } catch (RegraViolada $e)`
  do teste vizinho da linha 134.
- **Teste 2 — nada foi gravado.** No mesmo cenário: depois da recusa,
  `$avulso->fresh()->produto_base_id` é NULL, `quantidade_kit` continua 1, e
  `PubProduto::whereNotNull('produto_base_id')->count()` é 0.
- **Teste 3 — o endpoint devolve 422 com a regra.** `PUT` em
  `/mlb/anuncios/publicador/empresas/empresa-{$this->mlbP->id}/produtos/{$avulso->id}/vinculo`
  com `['base_id' => $composto->id, 'quantidade' => 2]`, como `$this->equipeP`:
  `assertStatus(422)->assertJsonPath('regra', 'KIT-06')`, e nenhum
  `produto_base_id` gravado.
- **Teste 4 — a recusa é SÓ do lado da base (o furo que NÃO existe).** Com
  `$grupo = $this->grupoComRascunho()` e
  `$combo = $this->composto('combo', 'CAD-PT-CB2', PubRascunho::PUBLISHED)`,
  `vincular($combo, $grupo, 2)` **tem de passar**:
  `$combo->fresh()->produto_base_id === $grupo->id`, `quantidade_kit === 2`,
  `fase === 2` e `PlanejamentoDaFaseService::tipoComposto($combo->fresh()) === null`.
  É o que a regra KIT-06 afirma ("composto não é base de fase nenhuma") e o que o
  outro dev perguntou na coordenação — a recusa do outro lado **não existe e não
  pode ser inventada**.
  </behavior>

  <action>
GREEN: no `vincular()` de `app/Services/Publicador/VinculoDeKitService.php`,
acrescentar a recusa como a **PRIMEIRA** do método (antes do VINC-01), espelhando a
ordem que o `CriarFaseService` já usa (lá o `recusarComposto()` roda antes do
KIT-01). A ordem relativa de VINC-01..VINC-06 entre si **não muda**, e a recusa fica
antes de `$base->familia()` e antes de qualquer escrita.

Reusar a fonte do outro dev — `PlanejamentoDaFaseService::tipoComposto($base)` para
detectar e `::motivoKit06($tipo)` para a mensagem — e lançar com o código
**`KIT-06`**, de propósito o mesmo do `CriarFaseService`: é a MESMA regra aplicada no
outro caminho, não uma regra nova. **Não** duplicar a lista de tipos, **não** escrever
mensagem própria, **não** criar um código `VINC-10`. `PlanejamentoDaFaseService` está
no mesmo namespace (`App\Services\Publicador`): nenhum `use` novo é necessário —
conferir que nenhum import foi adicionado por reflexo.

Comentário acima da recusa (pt-BR) explicando por que ela existe: a sugestão
automática já não propõe composto como base, mas `base_id` vem do corpo da
requisição (D-13) — esconder não é impedir — e a recusa vale **só para a base**,
porque o composto pode perfeitamente ser o kit vinculado.

Docblock da classe: acrescentar a linha na tabela de recusas (que hoje documenta
VINC-01..VINC-09), no topo da tabela, para espelhar a ordem de execução:

    | KIT-06   | a base escolhida é um composto do Planejamento (regra do CriarFaseService) |

E no `@throws` de `vincular()`, trocar `VINC-01..VINC-06` por
`KIT-06, VINC-01..VINC-06`.

⚠️ Não tocar em `usarEstoqueCalculado()`, `desvincular()`, `recusar()` nem na lista
`$esperadas` da guarda `VINC-00`.
  </action>

  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Publicador/CompostoDoPlanejamentoTest.php tests/Feature/Publicador/VinculoDeKitTest.php</automated>
  </verify>

  <done>
Os 4 testes novos passam; `CompostoDoPlanejamentoTest` e `VinculoDeKitTest` inteiros
seguem verdes (os 9 VINC e a guarda VINC-00 intactos); `grep -c "KIT-06"
app/Services/Publicador/VinculoDeKitService.php` devolve 2 ou mais (a recusa e a
linha do docblock); nenhum `use` novo no arquivo do serviço.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 2: a fase passa a ser derivada da quantidade no servidor (e `proximaFase()` sai do código)</name>
  <files>app/Models/PubProduto.php, app/Services/Publicador/CriarFaseService.php, app/Services/Publicador/VinculoDeKitService.php, app/Services/Publicador/FamiliaDeFasesService.php, tests/Unit/Publicador/CriarFaseServiceTest.php, tests/Feature/Publicador/Concerns/CenarioPlanejamentoDaFase.php, tests/Feature/Publicador/VinculoDeKitTest.php, tests/Feature/Publicador/TelaDoProdutoTest.php</files>

  <behavior>
RED antes de GREEN. Os números abaixo são o antes/depois de cada asserção — anotar
cada um deles para a SUMMARY da Tarefa 4.

**`tests/Unit/Publicador/CriarFaseServiceTest.php`** (a bateria de helpers PUROS,
sem banco, é onde isso se prova):

- Substituir `test_proxima_fase_e_a_maior_mais_um` (linha ~45, que chama o método
  removido e portanto nem compila) por
  `test_fase_da_quantidade_e_a_propria_quantidade`: `faseDaQuantidade(1) === 1`,
  `(2) === 2`, `(5) === 5`, `(0) === 1`, `(-3) === 1` — com a mensagem de cada
  asserção dizendo por quê ("fase nenhuma é menor que a do base").
- `test_kit_nasce_com_as_ancoras_do_base_e_a_proxima_fase` (linha ~190) cria um
  **Kit 3** e hoje afirma `fase === 2` *"família só com a Fase 1: o kit é a Fase 2"*;
  passa a afirmar **`fase === 3`** com a mensagem nova ("Kit 3 é a Fase 3: a fase vem
  da quantidade, não da ordem de criação"). A última linha do mesmo teste cria um
  **Kit 4** e afirma `fase === 3`; passa a **4**. Renomear o teste para
  `test_kit_nasce_com_as_ancoras_do_base_e_a_fase_da_quantidade`.
  ⚠️ **Este teste é o bug capturado num teste existente** — ele afirmava, verde em
  produção, que um Kit 3 é a Fase 2. Na SUMMARY isso tem de aparecer com essas
  palavras, não diluído numa lista de "asserções ajustadas" (ver Tarefa 4).
- Docblock da classe (linha ~28) cita `proximaFase` entre os helpers puros: trocar
  por `faseDaQuantidade`.
- **O caso que prova o bug:** teste novo — primeiro kit da família com quantidade
  **5** (`servico()->criar($base, $this->dados(5, sku: 'CAD-01-KIT5'))`) nasce com
  `fase === 5`. Hoje nasceria 2, e o cartão diria "Kit 5" enquanto a Visão geral o
  contaria no bucket Fase 2.
- **O caso do buraco:** teste novo — criar Kit 2 e Kit 4 na mesma família; então
  `PubProduto::proximaQuantidade($familia->pluck('quantidade_kit')->all()) === 3`
  (a regra que já existia) **e** o Kit 3 criado em seguida nasce com `fase === 3`
  (hoje nasceria 4); e `$base->fresh()->familia()->pluck('fase')->all() === [1, 2, 3, 4]`
  junto com `pluck('quantidade_kit')->all() === [1, 2, 3, 4]` — porque `familia()`
  ordena por `fase` e com o cronológico o Kit 3 sairia depois do Kit 4.

**`tests/Feature/Publicador/VinculoDeKitTest.php`** (o caminho do vínculo):

- Linha ~253: a asserção `assertSame(2, (int) $combo->fase, 'fase = max(fase da família) + 1')`
  mantém o **valor 2** (o vínculo é de 2 unidades) e muda só a mensagem, que passou a
  mentir: `'fase = quantidade: Kit 2 é a Fase 2'`.
- Teste novo, curto: vincular com `['base_id' => $base->id, 'quantidade' => 5]` numa
  família sem kit nenhum devolve `produto.fase === 5` e `produto.rotulo_fase === 'Kit 5'`
  (hoje devolveria fase 2 com rótulo "Kit 5" — os dois números do mesmo produto), e o
  banco confirma `fase === 5`. Reusar os helpers `conta()`, `produto()`,
  `rascunhoCompleto()` e `url()` do próprio arquivo.
- **Não afrouxar nada**: a guarda `VINC-00` (as 4 colunas e nada mais) e o critério
  da §9 (vincular não toca rascunho, estoque nem MLBs) continuam exatamente como
  estão, com as mesmas asserções byte a byte.

**`tests/Feature/Publicador/TelaDoProdutoTest.php`** (o `numero` do botão):

- Os testes das linhas ~205 e ~264 já afirmam `numero === quantidade_sugerida`
  (2 e 3): continuam verdes sem mudança — conferir, não editar.
- Teste novo `test_familia_com_kit_2_e_kit_4_sugere_fase_3_e_nao_5`: com o helper
  `kit($base, 2, 2)` e `kit($base, 4, 4)` e o base publicado, a tela tem
  `proxima_fase.quantidade_sugerida === 3` **e** `proxima_fase.numero === 3`
  (hoje o `numero` seria 5). As outras chaves de `proxima_fase` (`habilitado`,
  `motivo`, `quantidades_do_planejamento`) continuam com os mesmos valores.

**`tests/Feature/Publicador/Concerns/CenarioPlanejamentoDaFase.php:157`** chama o
método removido dentro de `kitAntigo()` — trocar por
`PubProduto::faseDaQuantidade($n)` (mesmo valor para todo kit que o cenário cria,
porque lá a quantidade já é o degrau).

⚠️ Qualquer outro teste que fique vermelho: **atualizar asserção que mudou de
propósito é permitido e esperado; afrouxar ou apagar teste que ainda vale, não.**
Registrar antes/depois e o porquê de cada asserção alterada.
  </behavior>

  <action>
GREEN, nesta ordem:

**1. `app/Models/PubProduto.php`** — método novo no lugar do antigo, puro, no estilo
do vizinho `proximaQuantidade`:

`public static function faseDaQuantidade(int $quantidadeKit): int` devolvendo
`max(1, $quantidadeKit)`. Docblock em pt-BR dizendo que Kit N é a Fase N, que a base
(1 unidade) é a Fase 1, que quantidade inválida cai em 1, e **por que deixou de ser
cronológico**, citando as duas provas medidas: `ProgramasPublicadorService::bucketDaFase()`
(manda `fase >= 3` para `fase3_mais` e trata `fase 2` como kit) e o comentário
literal do `PainelVisaoGeralService` (*"Rotulado kits, nunca Fase 2:
`quantidade_kit >= 2` inclui o kit de 3, que é Fase 3"*), mais a decisão do usuário
de 10/10 ("é metodologia e também ordem, Fase 1, Fase 2 e assim por diante").

**Remover `proximaFase()`** — remover, não deprecar: deixar as duas conviverem
convida a usar a errada de novo, e os testes pegam quem for esquecido.

No docblock da **classe** (seção "Fases e kits"), a linha que afirma
*"`fase` do kit novo = `max(fase da família) + 1`"* passa a dizer que a fase é
DERIVADA da quantidade (`faseDaQuantidade`) — comentário que mente é pior que
comentário nenhum. A observação de que a coluna existe para fases futuras que não
sejam kit continua válida; manter.

**2. `app/Services/Publicador/CriarFaseService.php`** — a chamada da linha 300.
`criarProdutoDoKit()` já recebe `int $quantidade`: derivar dele
(`'fase' => PubProduto::faseDaQuantidade($quantidade)`) e **remover o 4º parâmetro
`array $fasesDaFamilia`** da assinatura (linha 285) junto com a linha
`/** @param list<int> $fasesDaFamilia */` (linha 284) e com o argumento
`$familia->pluck('fase')->all()` da chamada da linha 207.

⚠️ **A variável `$familia` FICA.** A dúvida está resolvida por leitura do código: ela
é usada na linha 197, em `$this->recusarQuantidadeRepetida($familia, $quantidade)`
(a releitura sob a trava). **Só o parâmetro `$fasesDaFamilia` sai** — não remover a
variável, não mexer na releitura.

**3. `app/Services/Publicador/VinculoDeKitService.php`** — linha 97:
`$fase = PubProduto::faseDaQuantidade($quantidade);` (`$quantidade` já foi validado
`>= 2` pelo VINC-03 logo acima). O comentário da linha 93 ("A família do BASE decide
a fase (max + 1) e recusa a quantidade repetida") passa a dizer que a família do BASE
recusa a quantidade repetida e que a fase vem da quantidade.

**4. `app/Services/Publicador/FamiliaDeFasesService.php`** — o `'numero'` do botão
"Criar Fase N" (linha 504) no método privado `proximaFase()`. Extrair a
`quantidade_sugerida`, que é calculada duas linhas abaixo, para uma variável local
ANTES do `return` e usar `'numero' => PubProduto::faseDaQuantidade($quantidadeSugerida)`
com `'quantidade_sugerida' => $quantidadeSugerida`.

⚠️ **Reordenar só o necessário.** Todo o resto do método é do outro dev —
`$composto`, `$habilitado`, `$doPlanejamento`, `quantidades_do_planejamento` e o
`match` do motivo — e **não pode mudar de valor nem de ordem de avaliação**. O nome
do método privado `proximaFase()` fica como está (o docblock de
`MlbPublicadorFaseController:65` cita `FamiliaDeFasesService::proximaFase()` e
continua correto). Atualizar o docblock do método para dizer que o `numero` é o
degrau da quantidade sugerida.

**5. Conferência final da remoção:** `grep -rn "proximaFase" app/ tests/` não pode
mais achar `PubProduto::proximaFase` em lugar nenhum. As ocorrências que SOBRAM e
estão corretas: o método privado `FamiliaDeFasesService::proximaFase()` e a citação
dele no docblock do `MlbPublicadorFaseController`. O espelho JS é a **Tarefa 3** —
não antecipar nada de `resources/` aqui.

⚠️ Nesta tarefa, **nenhum arquivo de `resources/`, `routes/` ou `database/`**. Sem
migration e sem comando de backfill: `fase = quantidade_kit` já vale para 100% das
31 linhas de produção.
  </action>

  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador</automated>
  </verify>

  <done>
`PubProduto::faseDaQuantidade` existe e `PubProduto::proximaFase` não existe mais;
os três chamadores derivam da quantidade; `$familia` segue em uso na linha 197;
os testes novos (Kit 5 nasce Fase 5; Kit 2 + Kit 4 → sugestão 3, fase 3 e família na
ordem 1 → 2 → 3 → 4; vínculo de 5 devolve fase 5; `numero` 3 em vez de 5 na tela)
passam; a suíte `tests/{Unit,Feature}/Publicador` volta a 0 falha;
`git diff --stat -- resources routes database` ainda sai vazio **nesta tarefa**.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 3: o espelho na tela — o número que a pessoa lê antes de confirmar é o que o servidor grava</name>
  <files>tests/js/publicador-produtos-fases.test.js, resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx, resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx, resources/js/Pages/Mlb/Publicador/Produtos.jsx, tests/js/publicador-produtos-layout.test.js</files>

  <behavior>
Depende da Tarefa 2: o nome do helper JS é **o mesmo do método PHP**, de propósito.

Duas funções puras exportadas de `DialogoVincularKit.jsx`, porque são duas perguntas
diferentes:

- `faseDaQuantidade(quantidade)` → **número**, espelho fiel de
  `PubProduto::faseDaQuantidade()`: `1 → 1`, `2 → 2`, `5 → 5`, `'5' → 5`, `0 → 1`,
  `-3 → 1`, e todo valor adverso (`null`, `undefined`, `''`, `'abc'`, `{}`, `[]`,
  `'2,5'`) → **1**. Defensiva como a função que ela substitui — a antiga já tratava
  `fase: 'um'`, `[]`, `'nao-e-array'` e `baseId` nulo, e o padrão do módulo é
  `numeroSeguro`.
- `faseDoVinculo(quantidade)` → **`?number`**: o número que a TELA pode afirmar.
  `n >= 2 ? faseDaQuantidade(n) : null`. Logo: `2 → 2`, `5 → 5`, `' 12 ' → 12`,
  e `'' → null`, `'1' → null`, `0 → null`, `null → null`, `{} → null`, `'abc' → null`.
  Existe porque com o campo vazio **não há número honesto**: a derivação crua diria
  "Fase 1" (pior que o `2` fixo de hoje), e a regra do projeto é não mostrar número
  que a tela não pode garantir.

**`tests/js/publicador-produtos-fases.test.js`:**

- Linha ~675, a desestruturação do módulo: trocar `proximaFaseDaFamilia` por
  `faseDaQuantidade, faseDoVinculo`.
- Linha ~711, o teste
  `'proximaFaseDaFamilia: espelha PubProduto::proximaFase (max + 1, mínimo 2)'`
  **mudou de propósito**: renomear para
  `'faseDaQuantidade / faseDoVinculo: espelham PubProduto::faseDaQuantidade (Kit N é a Fase N)'`
  e reescrever as asserções com as duas listas acima. Registrar antes/depois na
  SUMMARY.
  ⚠️ Os helpers `produtoBase()` e `kitDe()` que o teste antigo usava são usados por
  **muitos** outros testes do arquivo (linhas 156, 238-242, 259, 283-284, 317-318,
  348-351, 407...): **não remover nenhum dos dois**.
- A fábrica `props()` do render do diálogo (linha ~755) tem `proximaFase: 2`: sai,
  porque a prop deixa de existir.
- Teste `'o botão diz a fase que vai nascer'` (linha ~785): passa a provar que o
  rótulo segue a QUANTIDADE — `render()` (sugestão com `quantidade: 2`) →
  `/Vincular como Fase 2/`; `render({ sugestao: sugestaoBase({ quantidade: 5 }) })` →
  `/Vincular como Fase 5/`; e `render({ sugestao: sugestaoBase({ quantidade: null }) })`
  → `/Vincular como kit/` **sem** `/Fase 1/`.
- ⚠️ Teste `'quantidade ausente na sugestão abre o campo VAZIO e trava o botão COM
  explicação (D23)'` (linha ~795): ele localiza o botão por
  `html.indexOf('Vincular como Fase')` — com o campo vazio o botão passa a dizer
  "Vincular como kit", então a agulha tem de virar `'Vincular como'`. A asserção que
  importa (`disabled=`) **continua valendo e não pode ser afrouxada**.
- Teste `'modo "recusar"...'` (linha ~815): `doesNotMatch(/Vincular como Fase/)`
  continua verdadeiro; apertar para `/Vincular como/`, que é o que o modo recusar
  realmente não mostra.
- Testes adversos (linhas ~822 e ~830): remover os overrides `proximaFase: null` e
  `proximaFase: 'duas'` (a prop não existe mais). O `quantidade: {}` do segundo caso
  continua e agora é exatamente a prova do caminho `faseDoVinculo → null`.
- Gate de fonte `'Tela B — monta o diálogo de vínculo...'` (linha ~870):
  `assert.match(fonte, /proximaFaseDaFamilia/)` → `/faseDoVinculo/`, e o comentário
  acima dele ("A fase que vai nascer é calculada com a família que a própria lista já
  tem") passa a dizer que ela vem da quantidade da sugestão. O regex do import
  (`/import DialogoVincularKit(, \{[^}]*\})? from .../`) continua casando.

**`tests/js/publicador-produtos-layout.test.js`** (`PainelDoProdutoLateral`):

- Os casos que passam `proximaFase: 2` e `proximaFase: 3` (linhas ~910, ~994, ~1004)
  continuam verdes sem edição — a prop segue sendo um número, só mudou quem o
  calcula. Conferir, não editar.
- Os adversos `{ proximaFase: 'duas' }` e `{ proximaFase: null }` (linhas ~1057-1058)
  usam a fábrica com `sugestao: null`, então nem chegam ao rótulo: continuam verdes e
  **não provam nada do caminho novo**. Acrescentar UM caso que prova:
  `render({ produto: ..., sugestao: { base_id: 1, base_sku: 'CAD-01' }, proximaFase: null })`
  → `/Vincular como kit/`, `doesNotMatch(/Vincular como Fase/)` e
  `doesNotMatch(/Fase 1/)`.
  </behavior>

  <action>
GREEN:

**1. `resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx`**

- **Remover** `proximaFaseDaFamilia(produtos, baseId)` (linhas ~94-117, docblock
  incluído) e criar no lugar as duas puras descritas no `<behavior>`, usando
  `numeroSeguro` para o valor adverso. O docblock de `faseDaQuantidade` diz que ela
  **espelha `PubProduto::faseDaQuantidade()`** (mesmo nome de propósito), que é só
  RÓTULO — quem grava a fase é o servidor — e que deixou de depender da família
  porque a fase do vínculo depende só da quantidade vinculada. O de `faseDoVinculo`
  diz por que devolve `null`: sem quantidade válida a tela não afirma número.
- No componente: **remover a prop `proximaFase`** (assinatura e JSDoc do contrato) e
  trocar a linha ~160 `const fase = numeroSeguro(proximaFase) ?? 2;` por
  `const fase = faseDoVinculo(quantidade);` — o estado do campo, **ao vivo**, para o
  rótulo acompanhar o que a pessoa digitar.
- Linha ~304: `Só registra que ele é a Fase {fase} deste produto base.` → com
  `fase === null`, a frase sai sem número ("Só registra que ele é uma fase deste
  produto base."). Manter o resto do parágrafo igual.
- Linha ~326: o rótulo do botão vira
  `fase !== null ? \`Vincular como Fase ${fase}\` : 'Vincular como kit'` (o ramo
  `recusando` não muda). O botão já está desabilitado quando a quantidade é inválida —
  **não tocar** em `podeEnviar` nem em `erroLocalDaQuantidade`.
- Linha ~210 (texto de sucesso): a fase do servidor continua tendo prioridade; o
  fallback deixa de ser a prop e passa a ser `faseDaQuantidade(quantidade)` — depois
  de um envio bem-sucedido a quantidade é necessariamente válida, então o fallback é
  sempre um número.
- **Não** mexer em `erroLocalDaQuantidade`, `erroDeRecusa`, `quantidadeInicial`, nos
  `useEffect`, nas rotas `vinculo.salvar`/`vinculo.recusar` nem no corpo do PUT
  (`base_id` + `quantidade`) — os gates de fonte do módulo cobrem todos eles, e o
  vocabulário tipográfico (`text-[24|15|13|11px]`, sem `font-medium`/`text-sm`) é
  gate também: **nenhuma classe Tailwind nova fora desse vocabulário**.

**2. `resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx`**

A prop `proximaFase` **continua existindo** (aqui a quantidade não é editável, quem
calcula é a página), mas muda de sentido: passa a ser a fase derivada **ou `null`**.

- Linha 108: tirar o fallback `: 2` — `const fase = typeof proximaFase === 'number'
  && Number.isFinite(proximaFase) ? proximaFase : null;`
- Linha 182: com `fase === null`, a frase sai sem número ("Confirme o vínculo para
  ele virar uma fase deste produto base.").
- Linha 186: com `fase === null`, o botão diz `Vincular como kit`.
- Atualizar o JSDoc da prop (hoje: *"o que `proximaFaseDaFamilia()` calculou"*) para
  "a fase derivada da quantidade da sugestão (`faseDoVinculo`), ou `null` quando a
  sugestão não traz o N".
- Não mexer em mais nada do painel (os gates do layout cobrem 440px, `role="dialog"`,
  o bloco de anúncios sem preço e a ausência de `pendencias`).

**3. `resources/js/Pages/Mlb/Publicador/Produtos.jsx`** — os três chamadores:

- Import: `import DialogoVincularKit, { faseDoVinculo } from '@/Components/Mlb/Publicador/DialogoVincularKit';`
  (sai `proximaFaseDaFamilia`).
- Linha ~764: `const faseDaPrimeira = primeiraSugestao === null ? null : faseDoVinculo(primeiraSugestao.quantidade);`
  e, no `textoDaFaixa`, o ramo de UM produto passa a ter duas formas: com número
  (`... Confirme o vínculo para ele virar Fase ${faseDaPrimeira}.`) e, quando
  `faseDaPrimeira === null`, sem número (`... Confirme o vínculo para ele virar uma
  fase desse produto base.`). O ramo de vários produtos **não muda**.
- Linha ~1211: `proximaFase={faseDoVinculo(sugestaoSegura(produtoDoPainel)?.quantidade ?? null)}`.
- Linha ~1237: **remover** a prop `proximaFase` do `<DialogoVincularKit>` — o diálogo
  agora deriva do campo ao vivo.
- `lista` continua em uso em outros pontos da página (`vazio`, `comSugestao`,
  paginação): **não remover a variável**, só os argumentos que eram dela.
- ⚠️ **Armadilha do Rollup deste projeto:** flag/variável de escopo do componente
  usada DENTRO de um `.map()` é eliminada no bundle de produção e dá `ReferenceError`
  em produção (não no teste). Hoje nenhum dos três chamadores está dentro de um
  `.map()` — **manter assim**. Se precisar da fase por linha da lista, computar
  `faseDoVinculo(...)` **dentro do callback**, nunca numa const de fora.
  </action>

  <verify>
    <automated>npm run test:js && npm run build</automated>
  </verify>

  <done>
`proximaFaseDaFamilia` não existe mais em `resources/` nem em `tests/js/`;
`faseDaQuantidade`/`faseDoVinculo` exportadas, puras e defensivas; o rótulo do
diálogo acompanha o campo ao vivo e sai **sem número** quando a quantidade não é
válida; os três chamadores de `Produtos.jsx` passam a quantidade; `npm run test:js`
com **exatamente uma** falha (a pré-existente `estrutura-grade-glide`);
`npm run build` concluído.
  </done>
</task>

<task type="auto">
  <name>Tarefa 4: gates, SUMMARY e commit</name>
  <files>.planning/quick/261010-hiq-fechar-os-dois-furos-do-publicador-kit-0/SUMMARY.md</files>

  <action>
**Gates, nesta ordem:**

1. **PHP** — `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador`.
   Baseline: **1684 passando / 0 falhando**. Tem de voltar a **0 falha**, com os
   testes novos somando ao total. Se sobrar falha, consertar o código ou a asserção
   que mudou de propósito — nunca afrouxar nem apagar teste que ainda vale.
2. **JS** — `npm run test:js`. Baseline **2122 testes / 2121 pass / 1 fail**, e a
   falha é a `estrutura-grade-glide`, **PRÉ-EXISTENTE**. O total muda (testes
   reescritos e acrescentados); o invariante é **exatamente UMA falha, e a mesma**.
   **Não corrigir** a falha pré-existente; não deixar virar 2.
3. **`npm run build` — OBRIGATÓRIO** nesta quick (algo em `resources/` mudou).
   ⚠️ Página React que sai do manifest do Vite **SOME da aplicação**. Conferir pelo
   **JSON** do manifest, nunca por `grep` (o hash do arquivo pode conter hífen):
   ler `public/build/manifest.json` com `node -e` e confirmar que a chave
   `resources/js/Pages/Mlb/Publicador/Produtos.jsx` existe e tem `file`.
4. **`git diff --stat -- routes database`** → **VAZIO**. (`resources` agora muda de
   propósito: o gate de vazio NÃO se aplica mais a ele.) Se aparecer algo em
   `routes/` ou `database/`, reverter aquele arquivo pontualmente pelo Edit — nunca
   `git checkout`/`git reset`.
5. **Não** rodar `vendor/bin/pint` (reprova o módulo no baseline).

**SUMMARY** em `.planning/quick/261010-hiq-fechar-os-dois-furos-do-publicador-kit-0/SUMMARY.md`,
em pt-BR, registrando obrigatoriamente:

- Furo 1: onde a recusa KIT-06 entrou, por que reusa `tipoComposto`/`motivoKit06` em
  vez de duplicar a regra, e por que ela vale **só para a base** (com o teste que
  prova que o composto segue aceito COMO KIT — a pergunta do outro dev na
  coordenação, respondida por teste).
- Furo 2 no servidor: a derivação nova, os três chamadores trocados, a remoção de
  `proximaFase()` (por que remover em vez de deprecar) e o fato de que **só o
  parâmetro `$fasesDaFamilia` saiu** do `CriarFaseService` — a variável `$familia`
  ficou, porque é usada na releitura sob a trava (linha 197).
- **Com estas palavras, em seção própria e não escondido numa lista de ajustes:**
  *o teste `tests/Unit/Publicador/CriarFaseServiceTest.php:190` era o bug capturado
  num teste existente* — ele afirmava, verde em produção, que um **Kit 3 é a Fase
  2**. É a prova de que o conserto era necessário, e some se ficar diluído em
  "asserção ajustada".
- Furo 2 na tela: por que a restrição "só backend" foi revista (sem o espelho, o
  diálogo diria "Fase 2" e o banco gravaria "Fase 5" — o furo mudaria para um lugar
  pior, porque a pessoa lê o número errado ANTES de confirmar); por que
  `faseDoVinculo` devolve `null` em vez de "Fase 1"; e **o que foi achado sobre o
  campo ao vivo**: o campo de unidades do diálogo é editável (`useState` +
  `quantidadeInicial`, que abre VAZIO quando a sugestão não traz o N), então o rótulo
  passou a seguir o estado, não uma prop — e a prop `proximaFase` do diálogo foi
  removida por ter virado fonte de mentira.
- **Os números de produção medidos em 10/10 como a justificativa de não ter escrito
  migration nem backfill:** `pub_produtos` = 31, 1 único kit
  (`#32 sku=CAD+MESA-RD-CBT4-KIT2 fase=2 qtd=2 base=5`), e
  `whereColumn('fase','<>','quantidade_kit')` = **0** — `fase = quantidade_kit` já
  valia para 100% do dado existente, então nenhum rótulo e nenhuma contagem mudaram.
- **Cada asserção alterada, com antes → depois e o porquê**: as duas de
  `CriarFaseServiceTest:190` (2 → 3 e 3 → 4), a mensagem da linha ~253 do
  `VinculoDeKitTest`, o teste reescrito da linha ~711 de
  `publicador-produtos-fases.test.js` (nome e regra), a agulha do teste D23
  (`'Vincular como Fase'` → `'Vincular como'`) e o gate de fonte
  (`proximaFaseDaFamilia` → `faseDoVinculo`).
- Os resultados dos gates: contagem PHP final, contagem JS final com a única falha
  nomeada, `npm run build` concluído com a chave de `Produtos.jsx` presente no
  manifest, e `git diff --stat -- routes database` vazio.

**Commit** — a árvore é COMPARTILHADA com outro dev e com outras sessões:

- `git add -- <cada caminho desta quick>`, nunca `git add -A`.
- Mensagem multilinha acentuada por arquivo no scratchpad da sessão:
  `git commit -F <arquivo> -- <os mesmos caminhos>` (o `-F`/`-m` vem **antes** do `--`).
- Mensagem em pt-BR, terminando com a linha
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- **PROIBIDO**: `git add -A`, `git stash`, `git reset`, `git checkout`,
  `git commit --amend`, push, deploy, qualquer comando no VPS.
- `tests/Feature/CompanyPortfolioAccessTest.php` aparece untracked e **não é desta
  tarefa** — deixar intocado e fora do `add`.
- **Não** tocar em `ROADMAP.md` nem rodar `gsd-sdk query state.advance-plan`.
  </action>

  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador && npm run test:js && git diff --stat -- routes database</automated>
  </verify>

  <done>
Suíte `Publicador` em 0 falha; JS com exatamente a falha pré-existente;
`npm run build` feito e `Produtos.jsx` presente no `manifest.json`;
`git diff --stat -- routes database` vazio; SUMMARY.md escrita com os números de
produção, a seção própria do teste que capturava o bug, o achado do campo ao vivo e o
antes/depois de todas as asserções; commit feito por pathspec com a linha de
co-autoria.
  </done>
</task>

</tasks>

<verification>
- `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` → 0 falha (baseline 1684 passando + os novos).
- `npm run test:js` → exatamente 1 falha, a pré-existente `estrutura-grade-glide`.
- `npm run build` → concluído; `node -e` sobre `public/build/manifest.json` confirma a
  chave `resources/js/Pages/Mlb/Publicador/Produtos.jsx` (conferir pelo JSON, nunca
  por grep — o hash pode conter hífen).
- `git diff --stat -- routes database` → saída vazia.
- `grep -rn "PubProduto::proximaFase" app/ tests/` → nenhuma ocorrência.
- `grep -rn "proximaFaseDaFamilia" resources/ tests/js/` → nenhuma ocorrência.
- `grep -rn "faseDaQuantidade" app/` → `PubProduto`, `CriarFaseService`,
  `VinculoDeKitService` e `FamiliaDeFasesService`.
- `grep -n "KIT-06" app/Services/Publicador/VinculoDeKitService.php` → a recusa e a
  linha do docblock.
- `git status --porcelain database/migrations` → nada novo (sem migration).
</verification>

<success_criteria>
1. Composto do Planejamento como `base_id` do vínculo é recusado pelo serviço com
   KIT-06 e a mensagem de `motivoKit06`, e nada é gravado — provado por teste, não só
   escondido pela sugestão.
2. Composto segue aceito como o produto vinculado (a recusa do outro lado não foi
   inventada), provado por teste.
3. `fase` é derivada da quantidade em todos os caminhos do servidor: Criar Fase N,
   Vincular e o `numero` do botão. Kit 5 nasce Fase 5; Kit 2 + Kit 4 → sugestão 3,
   fase 3, família na ordem 1 → 2 → 3 → 4.
4. `PubProduto::proximaFase()` não existe mais e nenhum chamador ficou esquecido;
   no `CriarFaseService` saiu o parâmetro e **ficou** a variável `$familia`.
5. A tela afirma o MESMO número que o servidor grava: rótulo derivado da quantidade,
   acompanhando o campo ao vivo, e **sem número** quando a quantidade não é válida —
   nem "Fase 1", nem o 2 fixo de antes. `proximaFaseDaFamilia` extinta.
6. Zero mudança em `routes/` e `database/`; nenhuma migration e nenhum backfill, com
   os números de produção na SUMMARY como justificativa.
7. Suíte do Publicador em 0 falha; VINC-00 e o critério da §9 intactos; JS com só a
   falha pré-existente; `npm run build` feito e a página no manifest.
8. Commit por pathspec, em pt-BR, com a linha de co-autoria. Sem push, sem deploy.
</success_criteria>

<output>
Criar `.planning/quick/261010-hiq-fechar-os-dois-furos-do-publicador-kit-0/SUMMARY.md`
ao terminar.
</output>
