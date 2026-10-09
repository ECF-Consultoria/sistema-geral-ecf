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
