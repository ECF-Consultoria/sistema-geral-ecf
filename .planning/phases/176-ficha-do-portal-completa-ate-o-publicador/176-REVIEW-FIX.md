---
phase: 176-ficha-do-portal-completa-ate-o-publicador
fixed_at: 2026-10-08T23:30:00Z
review_path:
  - .planning/phases/176-ficha-do-portal-completa-ate-o-publicador/176-REVIEW.md
  - .planning/phases/176-ficha-do-portal-completa-ate-o-publicador/176-REVIEW-FRONT.md
iteration: 1
findings_in_scope: 23
fixed: 23
skipped: 0
status: all_fixed
---

# Fase 176: Relatório das correções do code review

**Corrigido em:** 2026-10-08
**Revisões de origem:** `176-REVIEW.md` (backend) e `176-REVIEW-FRONT.md` (frontend)
**Iteração:** 1

**Resumo:**
- No escopo: 23 achados. São todos os BLOCKER e WARNING das duas revisões (backend CR-01..03 e WR-01..10; front CR-01 e WR-01..06), mais três INFO baratos (backend IN-04; front IN-02 e IN-04).
- Corrigidos: 23, um commit por achado, cada um com o seu teste.
- Pulados: 0.
- Ficaram registrados e sem correção (INFO fora do pedido): backend IN-01, IN-02, IN-03, IN-05, IN-06 e IN-07; front IN-01, IN-03, IN-05, IN-06 e IN-07. O front IN-07 (toque no ícone) foi atendido de passagem no WR-04.

Os achados de lógica estão marcados como **"corrigido: requer conferência humana"**. Os testes provam a regra nova, mas a regra em si deve ser lida por uma pessoa antes do deploy.

**Prova final, um grupo por vez:**

| Grupo | Resultado |
|---|---|
| `tests/Feature/Publicador` | 701 OK |
| `tests/Unit/Publicador` | 309 OK |
| `tests/Unit/PortalEstrutura` | 218 OK |
| `tests/Unit/Portal` | 19 OK |
| `tests/Feature/PortalCliente` | 613 OK |
| `npm run test:js` | 1365 passam e 2 falham: as duas falhas antigas conhecidas ("Características secundárias nasce recolhido" e "FASES_TERMINAIS") |
| `npm run build` | OK |

Nas correções com teste novo de regra, o teste foi rodado também contra o código antigo e falhou, o que confirma que ele pega o defeito.

## Corrigidos — backend (`176-REVIEW.md`)

### CR-01: Excluir a oferta âncora congela o preço de uma cor em todas as variantes
**Arquivos:** `app/Services/Publicador/SoltarProdutoDaOfertaService.php`, `tests/Feature/Publicador/OfertaAncoraDoGrupoExcluidaTest.php`
**Commit:** `d238eb59` (corrigido: requer conferência humana)
**O que mudou:** o grupo é reancorado na próxima oferta Simples livre do mesmo produto do Portal e continua ligado ao Portal. Só a variante cuja cor foi excluída congela o próprio preço. Quando não há outra cor livre, cada variante congela o preço da SUA oferta (`precos_por_variante`, lido antes da exclusão). O `sku`/`nome` do grupo continuam os do produto do Portal.

### CR-02: Cor já publicada como anúncio avulso entra no grupo com o mesmo SKU
**Arquivos:** `PortalParaRascunhoService.php` (`semCoresPublicadas`), `PublicadorSincronizaPortalService.php`, testes em `PortalParaRascunhoTest` e `SincronizaPortalAgrupamentoTest`
**Commit:** `fd3cb1e2` (corrigido: requer conferência humana)
**O que mudou (D-06):** a cor fica fora do grupo quando a sua oferta já tem outro `pub_produto` publicado ou em publicação (intocável por fato). Vale o mesmo quando o SKU normalizado da cor é o de outro produto publicado. O resumo diz qual cor e qual produto. Se um Sincronizar antigo já pôs a cor no rascunho, ela não é removida (D-05) e o aviso pede que a equipe a desative. O aviso do Sincronizar agora nomeia a cor e o produto.
**Fica para depois:** o `mlbsDaRegua`, que olha só a oferta âncora, não foi estendido às outras cores. Isso pede decisão de produto.

### CR-03: Sem schema, as variações nascem num eixo customizado permanente
**Arquivos:** `PortalParaRascunhoService.php`, `PortalParaRascunhoTest`
**Commit:** `8f16c786` (corrigido: requer conferência humana)
**O que mudou:** sem schema (categoria não confirmada, ou ML fora do ar), o Sincronizar não cria eixo. Variações, SKU, estoque e fotos ficam para o próximo Sincronizar, e o aviso diz isso. Com a categoria disponível, a execução seguinte varia pela cor da categoria. Rascunho que já nasceu com eixo próprio recebe um aviso pedindo a troca no editor; nada é trocado em silêncio.

### WR-01: Reexecutar o Sincronizar ressuscita a cor removida pela equipe
**Arquivos:** `PortalParaRascunhoService.php` (`coresRemovidas`, `lembrarCores`), `PortalParaRascunhoTest`
**Commit:** `b8687b71` (corrigido: requer conferência humana)
**O que mudou (D-05):** só entra a cor que nunca esteve no rascunho. Ficam fora, com aviso, as cores das variantes órfãs e as que um Sincronizar anterior já tinha trazido. A memória fica em `step_state.portal_cores`, gravada direto na linha travada, sem `tocar()`, então a revisão não muda. A cor continua fora mesmo depois de a equipe descartar a órfã.

### WR-02: Escritas do Portal sem nova checagem de "intocável" sob a trava
**Arquivos:** `PortalParaRascunhoService.php`, `PortalFotosTest`
**Commit:** `b38c5fac`
**O que mudou:** "fotos por variante" e cada atribuição de foto passam por `sobTrava`, com o intocável checado de novo. Se a publicação começar durante a cópia, o Portal para e não atribui nada. O `receber` (que faz I/O) continua fora da trava.

### WR-03: `values_multi` (D-13) é gravado e nunca lido de volta
**Arquivos:** `RascunhoRepository.php`, `PortalParaRascunhoTest`, `.planning/learnings/publicador-ml.md` §14
**Commit:** `d6d73f00`
**O que mudou:** o snapshot passa a expor `values_multi`. Quando a gravação (tela ou IA) não manda a chave, `gravarAtributos`/`mesclarAtributos` mantêm a lista enquanto a 1ª opção (`value_id`) for a mesma; se a opção mudar, a lista velha sai. O payload do ML não usa a coluna. A nota do learnings foi atualizada.

### WR-04: Unidade não aceita vira a unidade padrão com o mesmo número
**Arquivos:** `app/Support/Publicador/Portal/PortalValorDeAtributo.php`, `tests/Unit/Publicador/PortalValorDeAtributoTest.php`
**Commit:** `818b9362` (corrigido: requer conferência humana)
**O que mudou:** cm, mm e m, e também g e kg, são convertidos para uma unidade aceita da mesma grandeza (50 cm vira 500 mm). Qualquer outra unidade não preenche nada e gera aviso. A unidade padrão só entra quando o Portal não informou unidade. O teste antigo, que fixava o comportamento errado (4,5 km virando 4,5 cm), foi trocado.

### WR-05: `ConversorParaJpg` decodifica sem limite de pixels
**Arquivos:** `app/Support/Publicador/Imagem/ConversorParaJpg.php`, `PortalParaRascunhoService.php`, `regrasDoResumoDoSincronizar.js`, testes (unitário, `PortalFotosTest`, JS)
**Commit:** `e90ded2e`
**O que mudou:** as dimensões são lidas do cabeçalho (`getimagesizefromstring`) antes de decodificar. Acima de 40 MP, a foto é recusada com o motivo `dimensao_grande`, que aparece no resumo e tem texto na tela. Sem dimensões legíveis, o GD não decodifica. O teste usa um cabeçalho VP8X que anuncia 16383×16383 em poucos bytes.

### WR-06: Jobs de preenchimento duplicados concorrem e quebram nos uniques
**Arquivos:** `PreencherRascunhoDoPortalJob.php`, `ImagemAssetService.php`, `EditorRascunhoService.php`, `SincronizaPortalCompletoTest`
**Commit:** `32e6e060`
**O que mudou:** cada produto tem uma trava (`Cache::lock`, por id). O Job que não consegue a trava fecha o resumo do SEU pedido com um aviso e termina. A trava foi escolhida no lugar de `ShouldBeUnique` porque este descartaria o 2º Job em silêncio, e o resumo do 2º clique ficaria "preenchendo" para sempre. `rascunhoDoProduto` e `receber` tratam o 23000 relendo o que o outro processo gravou.

### WR-07: Duplicatas legadas invisíveis para a equipe
**Arquivos:** `PublicadorSincronizaPortalService.php`, `MlbPublicadorEntradaController.php`, testes; e, na tela, o commit do front CR-01
**Commit:** `35a26913`
**O que mudou:** cada produto ganha um aviso que nomeia as cores que também existem como produtos avulsos e o grupo. A resposta do clique passa a levar `duplicados`. Antes, os avisos do POST nunca apareciam na tela; o front CR-01 (`58637308`) passou a mostrá-los no painel do resumo.

### WR-08: A lista de empresas não reconhece a cobertura do grupo
**Arquivos:** `ProgramasPublicadorService.php` (`ofertasCobertas`), `SincronizaPortalAgrupamentoTest`
**Commit:** `fca56c4c`
**O que mudou:** uma regra só de cobertura (oferta própria ou grupo da mesma Company), agregada por empresa. A lista e `situacaoPortal` usam essa regra, e a contagem de consultas não cresce com o número de empresas.

### WR-09: Cor pulada pelo plano conta como coberta e some sem rastro
**Arquivos:** `app/Support/Publicador/Portal/CoresDoGrupo.php` (novo), `PublicadorSincronizaPortalService.php`, `PortalParaRascunhoService.php`, testes (unitário e de feature)
**Commit:** `72a5c5f5` (corrigido: requer conferência humana)
**O que mudou:** a mesma regra (`CoresDoGrupo::separar`) decide no Sincronizar e no preenchimento. A variação sem valor, de outro tipo de variação ou repetida vira um produto separado, com aviso que diz qual é e por quê, e deixa de sumir "coberta" pelo grupo.
**Fica para depois:** uma variação nova desse tipo, criada no Portal depois do último Sincronizar, continua contando como coberta até o próximo clique.

### WR-10: Texto livre do cliente vai cru ao prompt do MAG T8
**Arquivos:** `DescricaoIaService.php`, `DescricaoIaTest`
**Commit:** `ddef64ea`
**O que mudou:** a descrição do cliente chega à IA sem links, e-mails e telefones, entre os delimitadores `<<<DESCRICAO_DO_CLIENTE … DESCRICAO_DO_CLIENTE`. Um cabeçalho declara o bloco como DADO e não instrução. O corte em `LIMITE_SPECS` nunca cai no delimitador de fechamento, e o cliente não consegue abrir nem fechar o bloco. Nome e valores da ficha perdem link e e-mail; o telefone fica nesses campos, porque GTIN é número longo legítimo. A saída da IA passa pela mesma limpeza. Os textos dos prompts do MAG T8 não foram tocados.

### IN-04 (INFO): Mensagem crua da exceção na tela
**Arquivos:** `DescricaoIaService.php`, `DescricaoIaTest`
**Commit:** `963d2371`
**O que mudou:** o detalhe vai só para o `Log::error`. A tela recebe um texto nosso.

## Corrigidos — frontend (`176-REVIEW-FRONT.md`)

### CR-01: "Sincronizar do Portal" no estado vazio perde o acompanhamento
**Arquivos:** `acompanhamentoDoSincronizar.js` (novo, puro), `BotaoSincronizarPortal.jsx`, `ResumoDoSincronizar.jsx`, `Produtos.jsx`, `tests/js/publicador-sincronizar-resumo.test.js`
**Commit:** `58637308` (corrigido: requer conferência humana)
**O que mudou:** o botão só faz o POST, e a página, que não desmonta, acompanha o pedido. Há um acompanhamento por vez e o novo cancela o anterior. Os testes exercitam o controlador com um relógio falso: leitura imediata, intervalo, parada no pronto, troca de pedido e cancelamento durante a leitura.

### WR-01: `useDescricaoIa` decide com um estado capturado antes do `await`
**Arquivos:** `descricaoIa.js` (`decidirLeitura`), `useDescricaoIa.js`, `tests/js/publicador-descricao-ia.test.js`
**Commit:** `f146a44e` (corrigido: requer conferência humana)
**O que mudou:** uma leitura por vez (`emVoo`). A decisão usa o estado relido depois da leitura e sabe se a página ainda está montada (`vivo`). Com a mesa travada, o texto só fica guardado como "Usar a descrição gerada". Os casos tempo esgotado, pedido novo, 2ª leitura, saída da página, mesa travada e texto digitado no meio têm teste.

### WR-02: `ja_pedido` descarta o pedido automático em andamento
**Arquivos:** `descricaoIa.js` (`pedidoParaAdotar`), `useDescricaoIa.js`, teste JS
**Commit:** `6885798f` (corrigido: requer conferência humana)
**O que mudou (D-11):** depois de um `ja_pedido`, a tela lê o estado e adota o pedido que estiver rodando ou pronto. O pedido adotado segue as mesmas regras de aplicação: só no campo vazio, nunca por cima do que a pessoa digitou.

### WR-03: Loops paralelos, painel que não fecha e spinner eterno
**Arquivos:** `Produtos.jsx`, `ResumoDoSincronizar.jsx`, teste JS
**Commit:** `770defc1`
**O que mudou:** fechar o painel cancela o acompanhamento. O botão fica desabilitado enquanto a página acompanha. No limite, o painel mostra "Ainda preenchendo…; recarregue a página em alguns minutos", sem spinner.

### WR-04: O balão invisível ocupa o layout e não atende WCAG 1.4.13
**Arquivos:** `resources/js/Components/Explicacao.jsx`, `tests/js/publicador-explicacao.test.js`
**Commit:** `16793cba`
**O que mudou:** fechado, o balão fica em `display:none` e para de causar rolagem horizontal. Aberto, respeita `max-w-[calc(100vw-2rem)]` e abre para o lado com espaço. Esc fecha. O vão entre o ícone e o balão virou padding, então o ponteiro alcança o balão sem ele sumir. O toque abre e fecha (front IN-07). O `aria-describedby` continua apontando para o balão.

### WR-05: `ResumoDoSincronizar.jsx` × `resumoDoSincronizar.js` só diferem na caixa
**Arquivos:** `resumoDoSincronizar.js` renomeado para `regrasDoResumoDoSincronizar.js`; imports em `ResumoDoSincronizar.jsx`, `Produtos.jsx` e no teste; `.planning/learnings/publicador-ml.md` §14
**Commit:** `e85bfff6`
**O que mudou:** os imports voltaram a ser sem extensão. Um teste recusa, na pasta, qualquer par de arquivos que só difira pela caixa. O build passa.

### WR-06: A descrição do produto fica fora do rascunho do navegador
**Arquivos:** `resources/js/lib/produtosNavegacao.js`, `useFichaProduto.js`, testes JS
**Commit:** `60416c43`
**O que mudou:** a descrição é gravada no rascunho junto com as variações, entra na comparação (mudar só a descrição já oferece o rascunho) e volta ao recuperar. Rascunho antigo, sem a chave, continua valendo como antes. Os valores da ficha técnica continuam fora do rascunho; a revisão marcou isso como opcional.

### IN-02 (INFO): "Não se aplica" sem o nome do campo
**Arquivos:** `CampoFichaTecnica.jsx`, `tests/js/estrutura-ficha-nao-se-aplica.test.js`
**Commit:** `7c657a5d`
**O que mudou:** um `sr-only` com o nome do campo dentro do rótulo da caixa. O teste de sigilo continua passando.

### IN-04 (INFO): Chaves repetidas e `aria-live` falante
**Arquivos:** `ResumoDoSincronizar.jsx`, teste JS
**Commit:** `3fef7fe7`
**O que mudou:** só a frase final (pronto ou expirou) é anunciada, numa região `sr-only` que já existe antes de mudar. As listas usam chave com o índice.

## Pulados

Nenhum.

---

_Corrigido em: 2026-10-08_
_Corretor: Claude (gsd-code-fixer)_
_Iteração: 1_
