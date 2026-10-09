---
tipo: quick
slug: qo2-capa-do-combo-vira-a-capa-do-anuncio
data: 2026-10-09
files_modified:
  - app/Support/Publicador/RascunhoSnapshot.php
  - app/Support/Publicador/Payload/PayloadBuilderUserProducts.php
  - app/Services/Publicador/RascunhoRepository.php
  - app/Services/Publicador/EditorRascunhoService.php
  - app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php
  - app/Services/Publicador/CapaDoKitService.php
  - app/Jobs/PlanejarKitCriativosJob.php
  - tests/Unit/Publicador/Payload/MontadorDePlanoTest.php
  - tests/Feature/Phase165/AprovacaoParaPubImagensTest.php
  - tests/Feature/Publicador/VinculoDeKitTest.php
autonomous: false
---

<objective>
**A Fase 2 gerava a capa certa do combo, pagava por ela (~R$ 1,10) e não a usava.**

Achado numa auditoria pedida pelo usuário (briefing "Conclusão e melhoria do Publicador
Fase 2"), percorrendo o caminho do código — não era conhecido nem estava no
`deferred-items.md` da Fase 175. A cadeia:

| passo | onde | o que faz |
|---|---|---|
| 1 | `CriarFaseService` | clona as fotos do base **com as atribuições** ⇒ `posicao 0` do kit é a capa do base, com UMA unidade |
| 2 | `EditorRascunhoService::colocarFotoNoGrupo()` | aprovar a capa gerada grava `posicao = count($doGrupo)` — o FIM do grupo |
| 3 | `ResolvedorGruposImagem` (L156) | ordena as atribuições por `posicao` crescente |
| 4 | `PayloadBuilderUserProducts` | `pictures[0]` = primeiro da lista = a capa no Mercado Livre |

Resultado: o Clássico publicava com a foto do base (1 unidade); o Premium, pela rotação do
`OrdemCapaPorAlvo`, com a **segunda** foto do base — também 1 unidade. A imagem com as N
unidades ficava por último na galeria, nos dois anúncios.

O docblock do `CapaDoKitService` afirmava o contrário ("a imagem só vira **foto 1** do kit
depois de aprovada"). Nenhum teste pegava: o fixture do `CapaDoKitTest` tem **um alvo só**,
então a rotação Clássico/Premium do kit nunca foi exercitada.

## O conflito de regras, e a decisão do usuário

O §19/§21 do briefing pede capa com exatamente N unidades. O D6/CAPA-01 (regra da ECF já
implementada) pede que Clássico e Premium **não** repitam a primeira imagem. Para o combo as
duas colidem: pôr a capa do combo em 0 faz a rotação jogar o Premium para a posição 1 — uma
foto de 1 unidade.

Levado ao usuário com três saídas. **Decisão dele (2026-10-09):** *"use a mesma foto tanto
para clássico quanto para o premium, nesse caso pode quebrar aquela regra"*. O CAPA-01 passa
a valer só na Fase 1.
</objective>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: combo não rotaciona a capa</name>
  <files>RascunhoSnapshot, PayloadBuilderUserProducts, RascunhoRepository, MontadorDePlanoTest, VinculoDeKitTest</files>
  <action>
  Snapshot carrega `unidadesPorOferta` (FATO do cadastro, de `pub_produtos.quantidade_kit`,
  nunca lido do título). Payload usa `indiceParaCapa = 0` para todo alvo quando é combo —
  literalmente "não rotaciona", mantendo `OrdemCapaPorAlvo` puro e intocado.

  ⚠️ `comEfetivos()` reconstrói o snapshot com argumentos POSICIONAIS e é por ele que a
  PUBLICAÇÃO passa: campo novo esquecido ali volta ao default sem erro aparecer.
  ⚠️ `snapshot()` roda em quase toda leitura do módulo: produto por `loadMissing`, nunca
  `load`.
  </action>
  <verify>CAPA-01 da Fase 1 segue verde; combo abre igual nos dois alvos</verify>
</task>

<task type="auto" tdd="true">
  <name>Task 2: a capa aprovada do combo vira a foto 1</name>
  <files>EditorRascunhoService, PublicadorCriativoAprovacaoService, PlanejarKitCriativosJob, CapaDoKitService, AprovacaoParaPubImagensTest</files>
  <action>
  `colocarFotoNoGrupo(..., naFrente: true)` põe na posição 0 e empurra as outras **do mesmo
  grupo**; os outros grupos (cores da variação) ficam intactos. Opt-in: o default `false` é
  o que todo chamador existente usa.

  Gatilho estruturado: `PlanejarKitCriativosJob` grava `unidades_da_composicao` no
  `slot_plano`, e a aprovação lê. NUNCA a frase da `cena` — regra de negócio lida de texto
  de prompt é o que TRUTH-02/03 proíbe. Conferir antes que `CreativePromptBuilder::paraSlot()`
  lê chaves NOMEADAS e não itera o array (senão a chave vazaria para o prompt).

  Corrigir o docblock do `CapaDoKitService`, que afirma o que o código não fazia.
  </action>
  <verify>Fase 1 segue indo para o fim do grupo; fronteira de 1 unidade não vira capa</verify>
</task>

</tasks>

<notes>
Servidor puro. NADA em `resources/js/` — há redesign de UI do Publicador em paralelo
(`261009-t01`, `261009-t02`). Commitar só por caminho.

⚠️ Toca `app/Jobs/PlanejarKitCriativosJob.php`. O aviso de coordenação do `CLAUDE.md` lista
`app/Services/Creative/`, os endpoints `criativo*` do `MlbAnuncioController` e três `.jsx` —
este arquivo não está na lista, mas é vizinho do Creative Engine: avisar o ECF Dev.
NÃO fazer push nem deploy sem autorização explícita.
</notes>
