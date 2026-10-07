# REQUIREMENTS — Milestone v25.0: Creative Engine V2

**Origem:** seis melhorias pedidas pelo usuário em 2026-10-07, depois de usar o Creative Engine em
produção (Fase 165, Creative Engine dentro do Publicador novo). **Não é correção de defeito** — o
que existe funciona e está em produção. É evolução para o resultado ficar profissional, como uma
empresa especialista em anúncios de marketplace produziria.

**Base sobre a qual esta milestone constrói:** a v24.0 (Creative Engine, Fases 160-163, requisitos em
`.planning/REQUIREMENTS-v24.md`) e a Fase 165 (Creative Engine dentro do editor do Publicador,
`.planning/phases/165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro/`). Nenhum
requisito aqui reabre o motor de geração (`GeminiImageProvider`), o validador Gemini-juiz (Fase 162)
ou o Product Truth (Fase 160) — todos são consumidos como já existem.

---

## Decisões travadas pelo usuário (NÃO reabrir)

- **D1 — Quarta etapa dedicada a Imagens no editor do Publicador**: Produto → Detalhes → **Imagens**
  → Condições de venda. Causa raiz medida: hoje a seção de Fotos é a PRIMEIRA seção da etapa
  Detalhes, ACIMA dos campos de detalhamento (`EtapaDetalhes.jsx` renderiza `FotosEVariacoes`
  primeiro, depois `FichaTecnica`, depois `Descricao`) — o operador gera antes de ter preenchido
  qualquer fato do produto.
  ⚠️ Isto reverte parcialmente uma decisão do PRÓPRIO usuário de 04/10/2026 (comentário de topo do
  `Editor.jsx`, commit `cab40d48`): *"no Mercado Livre são 3 fases; aqui parecem muitas"*. Ele foi
  avisado da tensão, recebeu a alternativa mais barata (só inverter a ordem das seções dentro de
  Detalhes) e **escolheu a quarta etapa de propósito** — ela também vai receber o acervo (D4), a
  identidade visual (D2) e a confirmação de custo. A reversão parcial é deliberada.
- **D2 — Identidade visual por CONTA DE MARKETPLACE** (a mesma âncora dual `Company`/`MlbEmpresa`
  já usada em todo o Publicador, nunca por empresa cliente isolada nem por produto). Cadastro único
  reaproveitado em toda geração daquela conta: cores, fontes, forma, filtros, estilo — como um
  prompt de marca. Entra no prompt de todos os slots.
- **D3 — Logo: arquivo real sobreposto pelo sistema, NUNCA desenhado pela IA, e OPCIONAL.** Palavras
  do usuário: *"o logo é opcional, a maioria das imagens que geramos não tem logo"*. Texto que o
  modelo precisa LER de uma foto sai corrompido (medido no spike V0.1 da v24.0); logo desenhado por
  IA sai com letra errada ou deformada, e logo de cliente deformado é pior que logo nenhum.
- **D4 — Guardar as imagens já é o comportamento atual; o trabalho é tornar navegável o que JÁ está
  guardado.** As imagens geradas já ficam, indefinidamente, em
  `storage/app/private/creative-geradas/{token}/{slot}.jpg`; nenhuma rotina as apaga (`LimparReferenciasCriativos`
  só apaga as fotos de REFERÊNCIA, em 48h — nunca as geradas). Tamanho médio ~1,9 MB/imagem, kit de 7
  ≈ 13 MB; regerar custa ~R$ 3,40/kit — guardar é ~100× mais barato que regenerar. Caso de uso real:
  *"fizemos o anúncio, passou dias e não performou bem, a ação seria excluir e fazer outro mudando
  algumas coisas, mas as fotos poderíamos usar as mesmas"*. O requisito é **acervo navegável e reuso
  por conta/empresa/produto**, não uma decisão de guardar (já tomada, pelo acúmulo de fato).
- **D5 — Ambiente brasileiro subentendido** nos slots AMBIENTADOS (`lifestyle`, `lifestyle_uso`,
  `composicao`) e NUNCA no `hero` nem no `white_background` (regidos pela moderação do ML). Versão
  aprovada pelo usuário em teste de prompt: descrever o que torna o ambiente reconhecível em vez de
  citar o país — luz quente e abundante de clima tropical, pé-direito e esquadrias de apartamento
  brasileiro, acabamentos e plantas comuns aqui (costela-de-adão, jiboia), paleta de madeira clara
  com branco — mais proibição explícita de símbolo nacional, bandeira, verde-amarelo e futebol. Não
  existe hoje nenhuma palavra desse texto no `CreativePromptBuilder` — é escopo genuinamente novo.
- **D6 — Capa diferente entre Clássico e Premium.** Regra de publicação da empresa: publicando um
  Clássico (`gold_special`) e um Premium (`gold_pro`) do MESMO produto, a primeira foto (a capa) não
  pode ser a mesma nos dois. No Publicador, Clássico e Premium são `alvos` (um por `listing_type_id`)
  do MESMO rascunho, com a MESMA galeria de fotos aprovadas — a troca é de ORDEM de envio por tipo,
  não de geração de imagens diferentes.

---

## O achado que define o escopo do texto nas imagens

O usuário relatou: *"as imagens foram geradas mas nenhuma delas teve texto; pelo menos uma das
imagens tem que ter pequenos tópicos apresentando os pontos fortes do produto"* e *"se na criação do
anúncio colocarmos medidas, seria legal na geração da imagem ter uma imagem destacando as medidas"*.

**Isso NÃO é defeito, e a funcionalidade JÁ EXISTE — nunca foi acionada.** Medido no código
(`app/Services/Creative/CreativeSlotCatalog.php`) e em produção:

- `ACEITAM_TEXTO` = `benefits, specifications, dimensions, package_content, feature_highlight,
  how_to_use`. O slot `dimensions` é literalmente "Produto com guia de medidas sobreposta indicando
  largura, altura e profundidade" e existe o slot `benefits` — exatamente os dois que o usuário
  pediu.
- São `PRIORIDADE_COM_FATO` e só entram quando o Truth sustenta o requisito (`satisfaz()`):
  `dimensions` exige atributo cujo id casa `/_(WIDTH|HEIGHT|LENGTH|DEPTH)$|^(WIDTH|HEIGHT|LENGTH|DEPTH)$/`;
  `benefits` exige `count(fatosVerificados) >= 3`; `specifications` >= 2; `feature_highlight` >= 1.
- **Dump real de produção** (rascunho 8, "Mesa Centro Sala Mesinha Base Piramide", kit id 2):
  categoria `MLB31578`, **UM único atributo** — `MODEL` com lista de palavras-chave de SEO, sem ser
  fato de produto —, descrição VAZIA, zero medidas. Os 7 slots escolhidos foram todos SEM_FATO
  (`hero, white_background, angles, detail, composicao, lifestyle, lifestyle_uso`); nenhum aceita
  texto.

**A trava funcionou como projetada. A solução é garantir que os fatos existam na hora de gerar — é o
que D1 habilita —, NUNCA afrouxar a trava.** Os requisitos TXT cobrem: como a etapa de Imagens
consome os fatos já preenchidos em Detalhes; se o operador pode confirmar/complementar pontos fortes
e medidas à mão (entrada humana conferida conta como fato); e o que a tela diz quando não há fato
suficiente para nenhum slot de texto (hoje ela não diz nada e o operador não entende por que não veio
texto).

⚠️ **TRUTH-02/03 (v24.0), a regra que já custou caro — segue intacta nesta milestone:** contagem de
peça e medida só entram no prompt vindas do CADASTRO ou de leitura conferida, nunca de suposição. Num
teste afirmou-se "exatamente quatro pés" sem conferir; o produto tinha cinco. O modelo OBEDECEU com
confiança, a imagem saiu coerente consigo mesma e passou pela revisão humana sem levantar suspeita.
**Número errado no prompt é pior que número nenhum.** Nenhum requisito desta milestone abre brecha
para a IA inferir fato de texto livre — entrada humana vale como fato só quando explicitamente
marcada como conferida (TXT-02).

⚠️ **O fato que viabiliza o texto:** medido com 21 imagens em 3 modelos (spike V0.1 da v24.0) — texto
DADO no prompt sai exato; texto que o modelo precisa LER de uma foto corrompe. Tópicos/badge/headline
vindos do cadastro caem no caso que FUNCIONA.

---

## Restrições técnicas medidas (não reabrir)

- Modelo: `gemini-3.1-flash-image` (2K), ~R$ 3,40/kit de 7, ~112s/kit. O `lite` está descartado.
- Fila `creative` (numprocs=3), `onQueue()` NO CONSTRUTOR — nunca redeclarar `$queue`. O worker
  `ecf-worker-creative` está na VPS e NÃO está no git.
- `deploy.sh` só reinicia `ecf-worker:*`; depois dele é obrigatório `queue:restart` + conferir
  `supervisorctl status`.
- PHP-FPM de produção: `upload_max_filesize = 12M`, `post_max_size = 50M` — config fora do git.
- A imagem aprovada entra no rascunho por `ImagemAssetService::receber()`, que roda
  `ValidadorImagem::problemas()`, incluindo **V-IMG-03** (mínimo 500px no lado menor). A foto de
  REFERÊNCIA não passa por essa validação; a GERADA passa — importa para o logo sobreposto (LOGO-03)
  e para o reuso do acervo (ACERVO-02).
- Imagem ao ML só por `MlImagemService::enviar()`.
- D-13 da Fase 165: nenhum token de criativo pode chegar ao navegador; o kit é endereçado pelo `id`
  numérico escopado ao rascunho. **Vale para o acervo também** (ACERVO-03) — um acervo que exponha
  token quebra D-13.
- Migrations: índice nomeado à mão (MariaDB recusa nome > 64 chars), `->nullable()` em FK com
  `nullOnDelete` (erro 1830), sem enum (CHECK do SQLite nos testes), `up()` idempotente, coluna nova
  com default — HÁ DADO EM PRODUÇÃO.
- ⚠️ **Lição da tela preta (261005-si3, 2026-10-07):** o contrato entre
  `PublicadorCriativoKitPresenter::paraTela()` e o painel React não tinha teste de RENDER, só gates
  de regex sobre a fonte. Um campo que chegou como objeto (`estrategia`) virou "Objects are not valid
  as a React child" e derrubou a página em produção. Já existe
  `tests/js/publicador-painel-criativos-render.test.js`, que compila e renderiza de verdade com o
  JSON do presenter — formalizado nesta milestone como requisito explícito (REND), não lembrete
  solto.

---

## Coordenação com o outro dev (crítico)

O **Publicador é território do ECF Dev**, e D1 (quarta etapa) mexe no coração dele: `Editor.jsx`,
`apoio.js` (`ETAPAS`, `ETAPA_INICIAL`), `usePublicador` e a validação por etapa do "Continuar". A
negociação dessa etapa é **pré-requisito explícito** da Fase 169 (ver `Depends on`/gate na seção de
fases do `ROADMAP.md`) — registrada também em `.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`.

---

## Requisitos

### CAPA — Capa diferente entre Clássico e Premium (D6)

- **CAPA-01** — Ao publicar o mesmo produto como Clássico e como Premium, a imagem que ocupa a
  posição de capa (a primeira) é diferente entre os dois anúncios — nunca a mesma foto na posição 1
  dos dois.
- **CAPA-02** — A troca de ordem acontece no envio ao Mercado Livre por `listing_type_id`/alvo, sobre
  a MESMA lista de fotos já aprovadas do rascunho — sem duplicar o acervo de imagens do produto.
- **CAPA-03** — Produto publicado só como Clássico OU só como Premium mantém o comportamento atual (a
  primeira foto aprovada continua capa) — a troca de ordem só vale quando os DOIS tipos coexistem no
  mesmo produto.
- **CAPA-04** — A ordem de capa de cada tipo é determinística (não varia a cada publicação do mesmo
  produto) e auditável.

### AMB — Ambiente brasileiro subentendido nos slots ambientados (D5)

- **AMB-01** — Os slots `lifestyle`, `lifestyle_uso` e `composicao` recebem no prompt a descrição
  aprovada do ambiente brasileiro subentendido (luz tropical, pé-direito e esquadrias de apartamento
  brasileiro, acabamentos e plantas comuns, paleta de madeira clara com branco).
- **AMB-02** — O prompt desses três slots proíbe explicitamente símbolo nacional, bandeira,
  verde-amarelo e futebol.
- **AMB-03** — Os slots `hero` e `white_background` NUNCA recebem esse texto de ambiente — ficam
  exatamente como hoje, por serem regidos pela moderação do Mercado Livre.
- **AMB-04** — A mudança é só de prompt: nenhum slot novo, nenhuma coluna de banco nova, nenhuma
  mudança em `CreativeSlotCatalog::elegiveis()`.

### EDIMG — Quarta etapa de Imagens no editor do Publicador (D1)

- **EDIMG-01** — O editor do Publicador passa a ter 4 etapas, nesta ordem: Produto → Detalhes →
  Imagens → Condições de venda; navegação, estado por URL/`sessionStorage` e "Continuar"/"Voltar"
  seguem o mesmo padrão já usado pelas 3 etapas atuais.
- **EDIMG-02** — A etapa Detalhes deixa de conter a seção de Fotos; "Fotos e variações"
  (`FotosEVariacoes`) passa a viver na etapa Imagens.
- **EDIMG-03** — A etapa Imagens reaproveita o painel nativo de geração por IA já existente (Fase
  165, `Mesa/PainelCriativos.jsx` + `useCriativosDoPublicador`) sem duplicar código nem criar um
  segundo caminho de geração.
- **EDIMG-04** — Pendências do servidor relacionadas a fotos (`alvo.grupo`/`alvo.imagem`, E6) passam
  a resolver-se na etapa Imagens — o "Continuar" de cada etapa aponta para o lugar certo depois da
  mudança.

### TXT — Texto nas imagens: pontos fortes e medidas, a partir dos fatos de Detalhes

- **TXT-01** — Na etapa Imagens, o operador consegue confirmar ou complementar, por produto, os
  pontos fortes (benefícios) e as medidas quando o cadastro automático (atributos do ML) não bastar
  para o Product Truth sustentar os slots `benefits`/`dimensions`.
- **TXT-02** — Essa confirmação/complemento feita pelo operador entra no Product Truth como fato
  **verificado por entrada humana conferida**, distinguível da origem "cadastro" — nunca promovido a
  fato a partir de texto livre sem essa marcação explícita (TRUTH-02/03 da v24.0 seguem intactas).
- **TXT-03** — Quando o Product Truth tiver fatos suficientes (via cadastro e/ou confirmação
  humana), o planejamento do kit de 7 (`CreativeSlotCatalog`/PLAN-03 da v24.0) prioriza os slots que
  aceitam texto elegíveis, em vez de preencher o kit só com slots `SEM_FATO`.
- **TXT-04** — Quando o Product Truth não tiver fatos suficientes para nenhum slot que aceita texto,
  a etapa Imagens diz isso explicitamente em pt-BR (hoje ela não diz nada) e indica o que falta
  preencher para habilitar texto numa imagem do kit.
- **TXT-05** — Nenhum requisito desta milestone afrouxa TRUTH-02/TRUTH-03 (v24.0): contagem e medida
  só entram no prompt vindas do cadastro ou de entrada humana explicitamente marcada como conferida.

### REND — Contrato de render real (lição da tela preta, 261005-si3)

- **REND-01** — Todo campo novo que um presenter desta milestone devolve e que uma tela nova ou
  alterada exibe (etapa Imagens, confirmação de fatos, cadastro de identidade/logo, acervo) entra num
  teste que compila e renderiza o componente de verdade com o JSON real do presenter — nunca só um
  gate de regex sobre a fonte.
- **REND-02** — Nenhum campo novo chega a um componente React como objeto quando o componente espera
  string/nó renderizável — confirmado pelo teste de render (REND-01), não por inspeção manual.

### IDENT — Identidade visual por conta de marketplace (D2)

- **IDENT-01** — Existe um cadastro único de identidade visual por conta de marketplace (a mesma
  âncora dual `Company`/`MlbEmpresa` já usada no Publicador): cores, fontes, forma, filtros e estilo,
  em parâmetros reaproveitáveis como prompt de marca.
- **IDENT-02** — Toda geração de imagem daquela conta (qualquer produto, qualquer slot elegível) usa
  a identidade cadastrada no prompt, sem o operador repetir nada por produto.
- **IDENT-03** — Conta sem identidade cadastrada gera normalmente, do jeito que gera hoje — a
  identidade é aditiva, nunca obrigatória.
- **IDENT-04** — O cadastro de identidade é acessível a partir da etapa Imagens do Publicador, sob
  `role:admin`, igual ao resto do módulo.
- **IDENT-05** — Cadastrar ou alterar a identidade de uma conta não reprocessa imagens já geradas —
  só entra no prompt de gerações futuras daquela conta.

### LOGO — Logo opcional sobreposto por arquivo real (D3)

- **LOGO-01** — A conta pode, opcionalmente, cadastrar um arquivo de logo real; sem esse cadastro, a
  geração continua sem logo, como a maioria das imagens hoje.
- **LOGO-02** — O logo NUNCA é desenhado pela IA — é sobreposto programaticamente pelo sistema, após
  a geração da imagem pelo provedor.
- **LOGO-03** — A imagem final com logo sobreposto continua passando pela validação de dimensão
  (V-IMG-03, mínimo 500px no lado menor) antes de virar foto do rascunho.
- **LOGO-04** — A sobreposição não deforma o arquivo de logo original — sem redesenho, sem distorção
  de proporção.
- **LOGO-05** — A ordem entre a sobreposição do logo e a validação automática (Fase 162,
  Gemini-juiz) é decidida explicitamente no planejamento desta fase — nunca aprovar uma imagem com
  logo sem ela ter passado pela validação de fidelidade ao produto.

### ACERVO — Acervo navegável e reuso das imagens já geradas (D4)

- **ACERVO-01** — O operador navega as imagens já geradas, filtrando por conta, por empresa e por
  produto, sem precisar regerar.
- **ACERVO-02** — O operador reaproveita uma imagem já gerada (de um kit anterior do mesmo produto)
  num anúncio novo ou numa nova tentativa, sem pagar nova geração.
- **ACERVO-03** — A navegação e o reuso respeitam D-13 (nenhum token de criativo chega ao navegador)
  — endereçamento por id numérico escopado, igual ao resto do Publicador.
- **ACERVO-04** — Nenhuma rotina de limpeza apaga a imagem gerada — confirma e preserva o
  comportamento já existente (`LimparReferenciasCriativos` continua restrito às fotos de referência,
  48h); o acervo é sobre tornar navegável o que já não se apaga, não sobre decidir guardar.
- **ACERVO-05** — Reaproveitar uma imagem do acervo confere o mesmo escopo por empresa/conta que todo
  o resto do módulo — nunca cruza empresa ou conta.

---

## Fora do escopo desta milestone

1. Reabrir a decisão da quarta etapa de outra forma (ex.: só inverter a ordem das seções dentro de
   Detalhes) — D1 já fechou isso.
2. Integração programática com o Google Drive do cliente (D-02 da v24.0 segue valendo) — a foto de
   REFERÊNCIA continua upload efêmero; só a imagem GERADA entra no acervo (D4/ACERVO).
3. Afrouxar TRUTH-02/TRUTH-03 (v24.0) para permitir contagem ou medida por inferência — nenhuma
   frente desta milestone muda essa trava.
4. Reconstruir o validador Gemini-juiz (Fase 162) ou o motor de geração (`GeminiImageProvider`) —
   esta milestone consome o que já existe; LOGO-05 decide só a ORDEM entre logo e validação, não o
   funcionamento interno do validador.
5. Edição manual de imagem pelo operador (recorte, desenho, texto arrastável) — o texto nas imagens
   (TXT) é gerado pela IA a partir de fato confirmado, não editado à mão depois de gerado.
6. Comparação com outros provedores de imagem, variantes A/B, geração em lote, biblioteca de
   templates por categoria — seguem fora (já descartados na v24.0).
7. Refazer qualquer parte do Publicador fora do estritamente necessário para D1 (a mudança mexe na
   estrutura de etapas, não no resto do editor).
