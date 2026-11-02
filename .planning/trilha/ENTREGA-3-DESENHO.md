# Trilha de produtos — 3ª entrega: o cadastro do produto na etapa 1 (DESENHO FINAL)

Escrito em 02/11/2026 pelo juiz, a partir dos quatro achados e dos três desenhos desta pasta
(`desenho-reuso.md`, `desenho-nativo.md`, `desenho-risco.md`) e de conferência direta no código. O código foi
lido SÓ no worktree `C:/tmp/ecf-trilha-261030`, HEAD `5e11e79e3` = `origin/main`, árvore limpa. Nada foi editado,
rodado nem migrado no repositório. Caminhos relativos à raiz do worktree; `:N` = linha nesse HEAD.

Requisitos (decisões do usuário de 02/11/2026):
- a trilha vira um MODO NOVO de usar o Publicador, sem Portal: o produto é cadastrado DENTRO da etapa 1;
- mesmas tabelas do Portal: só a tela é nova; o Portal, o Publicador, a IA do preparo e a publicação não mudam;
- só a equipe cadastra; o cliente segue só lendo;
- esta entrega é o cadastro completo; a prévia e o "publicar" ficam para a 4ª.

---

## 0. Em uma tela

1. **Uma gaveta de cadastro com chave própria, `e:{id}` e `e:novo`.** Ela segue o padrão do `m:` da 2ª entrega:
   - a linha da Lista abre o cadastro para quem escreve;
   - o painel de leitura `p:` continua igual para o cliente, para o "Ver como: Cliente" e para as outras etapas.
2. **Criar primeiro.** "+ Produto na lista" pede só Nome e SKU e cria o produto na hora. Depois:
   - criar cor ou tamanho, enviar foto e excluir são ações imediatas;
   - o resto (nome, categoria, família, título, custo, caixas, descrição, ficha) vai num botão "Salvar".

   Assim nunca há foto "pendente na aba" nem gaveta remontada perdendo o que se digitou.
3. **Servidor: os mesmos serviços do Portal, atrás de três controllers finos da porta da equipe.** A guarda é a da
   2ª entrega: 404 pelas duas chaves, 403 por `podeEntrar`, 404 pelo recurso ANTES da validação. As regras de
   validação do Portal são extraídas para uma classe usada pelas duas portas. **Nenhuma migration.**
4. **Tela 100% nova, no tema da trilha, sobre as libs puras do Portal** (já testadas). Nenhum componente nem hook
   do Portal é alterado.
5. **O produto feito na trilha vai ao Publicador quando sai da Lista** (pergunta 2, recomendado). O motivo foi
   conferido no código: o Sincronizar roda a cada save, mas só aplica categoria em rascunho SEM categoria e só
   copia foto para grupo VAZIO. Com o cadastro aos poucos, o rascunho ficaria preso na 1ª foto e na 1ª categoria.
6. **Correção da régua, sem pergunta:** as fotos passam a contar por COR quando há cor e tamanho. Sem isso, produto
   de moda nunca sai da Lista, e a tela já promete "3 fotos em cada cor".
7. **Achado novo deste juiz (pergunta 3):** a falta "frete ME1" sai só das medidas da caixa. Sofá e guarda-roupa
   passam de qualquer limite do ME2 (Coleta: 50 kg, soma de 300 cm) e ficariam PARA SEMPRE na Lista.
8. **Sete fatias**, cada uma testável e implantável sozinha, só para a 459 e só para o Dev (módulo oculto).
   Esforço total: ~9 a 13 dias com testes. Nenhum deploy sem autorização.

**Notas:** nativo **7,6** · risco **7,4** · reuso **5,3**. O desenho final é o esqueleto do nativo, com a guarda,
a trava, a versão e os testes do risco, e com algumas peças do reuso (§1).

---

## 1. As notas dos três desenhos

Escala de 0 a 10, quanto maior, melhor. Em "risco", 10 é o mais seguro; em "tamanho", 10 é o menor.

| Desenho | Aderência às decisões | Reuso real | Risco | Tamanho | Nota |
|---|---|---|---|---|---|
| **Nativo e enxuto** | 9 | 7 | 7 | 7 | **7,6** |
| **Risco primeiro** | 8 | 7 | 8 | 6 | **7,4** |
| **Reuso máximo** | 6 | 6 | 4 | 5 | **5,3** |

**Reuso (5,3).**
- *Aderência 6.* Cumpre equipe, tabelas e porta do cliente. Mas "só a tela é nova" vira "a tela do Portal muda por
  dentro": um piloto escondido de UMA loja põe em jogo o controller de 1.239 linhas e 16 arquivos de tela que
  todos os clientes usam. Também não trata o rascunho parcial nem o custo de editar o que já andou.
- *Reuso real 6.* No papel é o maior. Na prática:
  - continua com ~1.000 linhas novas de tela (dados, variações, medidas e uma "pele" de 200 linhas);
  - troca ~210 classes dentro do Portal;
  - acopla as duas telas para sempre: toda classe nova no Portal exige a mesma chave na pele da trilha.
- *Risco 4.* Os arquivos que ele mexe são os mais disputados:
  - `useFichaProduto.js` tem 10 commits desde 15/10, 4 deles do outro dev (MB.ECF-100376);
  - `PickerCategoria` e `PickerLista` só ele mexeu nesse período.

  O `EnderecoDaFoto` resolvido no contêiner é estado global escondido num serviço compartilhado.
- *Tamanho 5.* São 1,5 a 3 dias de refatoração (fatias 0a e 0b) antes de qualquer valor visível.
- *Enxertado no final:*
  - a chave `e:` que cai em `p:` para quem não escreve;
  - "Onde está" e "Ofertas" usados também no cadastro;
  - a tabela das alternativas descartadas (o motivo de não usar iframe, middleware que "abre" o `PortalContexto` ou
    ponte de CSS);
  - o aviso de edição fora da Lista;
  - o R20 (subpasta quebra o teste de cores).

**Nativo (7,6).**
- *Aderência 9.* É o protótipo com o sistema real por trás: criar abre a gaveta do produto, há "+ Produto na lista"
  e o aviso "Lista completa: o produto seguiu para o mapeamento". O Portal fica intocado. É o ÚNICO desenho que lê
  certo o Sincronizar (D4).
- *Reuso 7.* Usa os serviços e as libs puras e extrai as regras de validação para as duas portas. Duplica só a parte
  pequena do `useFichaTecnica`.
- *Risco 7.* Resolve o rascunho parcial e confere os ids do corpo. Perde pontos porque:
  - faz do `p:` de quem escreve um formulário em TODAS as etapas: mexe no painel de leitura e nos testes dele, e a
    caixa de sugestões some;
  - não trava o que já andou (editar refaz IA paga);
  - o produto criado por engano só sai na F6;
  - não tem versão.
- *Tamanho 7.*
- *Enxertado no final:*
  - "criar primeiro" e as ações estruturais imediatas;
  - `prepararSeCabe`, isto é, `agendarPreparo: false` com o gatilho só quando cabe;
  - a descrição pela linha (D7) e o JSON por lista de campos (D10);
  - `faltasDoProduto`, `conferirCores` no servidor e na tela, e `anunciavel` como chave aditiva;
  - `exigirObrigatorios: false`;
  - "Abrir o Portal" saindo só na última fatia do fluxo principal;
  - a tabela de textos.

**Risco (7,4).**
- *Aderência 8.* Tudo certo. Fecha um pouco mais que o pedido: sem "Preencher com IA", que o Portal tem, e com a trava
  do que andou, que ninguém pediu (aqui ela vira pergunta).
- *Reuso 7.* Igual ao nativo.
- *Risco 8.* Tem a melhor leitura de autorização: a variação de OUTRO produto da mesma loja pelo caminho do produto
  A, um IDOR dentro da loja que foi conferido no código. Traz ainda a versão sem schema, a trava do que andou, o chunk
  `lazy` pelo sigilo, a varredura recursiva e a matriz de acesso. Perde porque:
  - mantém o Sincronizar a cada save, e o rascunho nasce com a 1ª foto e a 1ª categoria (reconhece B11 e B12 e
    empurra para a 4ª);
  - mantém as fotos pendentes, com a `key` estável, uma complexidade que "criar primeiro" evita.
- *Tamanho 6.* Versão e trava já na fatia 1.
- *Enxertado no final:*
  - a guarda com a variação presa ao produto do caminho, e "uma requisição, um produto";
  - a trava (pergunta 1) e o aviso de onda começada;
  - a versão, como fatia própria;
  - `definicao()` sem as explicações por IA;
  - a pasta `Cadastro/` carregada por `lazy`, com o teste de fonte;
  - a matriz de testes, `CustoDoCadastroTest` e a operação: nenhum Job novo e a conferência por reconsulta ao banco.

---

## 2. O que foi conferido no código (as afirmações que decidem)

| # | Afirmação | Onde | Resultado |
|---|---|---|---|
| 1 | Os serviços do cadastro não leem `PortalContexto`, `session()`, `auth()` nem `request()` | grep em `app/Services/Portal/Estrutura` (o único acerto é `Geracao/CatalogoDaEcfService.php:199`, fora do cadastro) | ✔ servem fora do Portal com `Company` + `AtorDoPortal` |
| 2 | `PortalContexto::empresa()` e `ator()` lançam `LogicException` fora do `portal.auth` | `app/Support/Portal/PortalContexto.php:115-170` | ✔ controller próprio, não o do Portal |
| 3 | `gravarLinhas(Company, array, AtorDoPortal, string $modo, bool $agendarPreparo = true)`. Com `false`, os DOIS caminhos até o `aoSalvar` ficam fechados (o direto e o via oferta) | `ProdutoCadastroService.php:116-130`, `:219`, `:230-232`, `:343-350`; `EstruturaOfertaService.php:59-75` | ✔ é o caminho do Bling |
| 4 | Com `id` na linha, o produto vem da VARIAÇÃO e o `produto_id` da linha é ignorado | `ProdutoCadastroService.php:594-599` | ✔ id do produto B pelo caminho do A editaria o B: o controller confere |
| 5 | O Sincronizar aplica a categoria só em rascunho SEM categoria e copia foto só para grupo VAZIO | `PortalParaRascunhoService.php:513-528`, `:535-575` (`if ($r->categoria_id …) return false`), `:919-923` | ✔ |
| 6 | O `aoSalvar` agenda o Sincronizar (15 s, `Cache::add` de 5 min que o Job apaga ao rodar) e o preparo (20 s) a cada save, sem olhar se o produto está completo | `PreparoIaAgenda.php:93-137`; `SincronizarProdutoDoPortalJob.php:42-46`; `PreparoIaDoRascunhoService::sincronizarAgora` `:186-226` | ✔ |
| 7 | `FichaTecnicaDoProduto::gravar` é tudo ou nada e exige os obrigatórios | `:95-172` (o erro "Preencha" no bloco `$normal === null`) | ✔ um `bool $exigirObrigatorios = true` é aditivo |
| 8 | A descrição já vai pela linha, com o teto de 5.000 e a mensagem | `NormalizadorDeLinha.php:349-360` | ✔ uma rota a menos |
| 9 | A régua conta fotos por VARIAÇÃO; o Publicador empresta as da cor | `CompletudeDoProduto.php:117-122`; `PortalParaRascunhoService.php:1152-1185` (`temSegundoEixo`, `comFotosDaCor`) | ✔ moda presa na Lista |
| 10 | "frete ME1" sai só da logística dos volumes com a modalidade em cache. Limites: Correios 30 kg, 200, 100; Coleta 50 kg, 300, 200; cache de 168 h | `CompletudeDoProduto.php:163-185`; `PendenciasDoProduto.php:82-84`; `config/estrutura_produtos.php:30-33`, `:57` | ✔ sofá de 216×98×60 cm soma 374 cm: ME1 em qualquer modalidade |
| 11 | `anunciavel` (`listing_allowed`) existe na árvore e falta na busca | `CategoriaBuscaService.php:91-146`; `CategoriaSugestaoService.php:77-114` (só `folha`) | ✔ |
| 12 | `definicaoComExplicacoes` pode enfileirar IA; `definicao()`, não | `FichaTecnicaDaCategoria.php:187-238` → `ExplicacaoDeAtributos::paraAtributos` (`enfileirar`) | ✔ |
| 13 | Os termos do título gravam `ml_categoria_schemas` | `TituloDeVendaService.php:35` → `LimiteDoTitulo.php:36` → `CategorySchemaRepository::obter` | ✔ a ficha deixa de ficar "a conferir" |
| 14 | O 413→422 só casa o caminho do Portal | `bootstrap/app.php:77-84` | ✔ precisa da regex da trilha |
| 15 | `trilha.fotos` só lê, só serve miniatura (a original só sem GD) e exige as duas chaves | `FotosDaTrilhaController.php:32-58` | ✔ falta `?original=1` |
| 16 | `portal_abrir` vai para quem `podeEntrar`; `escrita` só com `pode_editar`; o cliente tem `links.produtos` e `url_ficha` para o Portal | `ApresentacaoDaTrilha.php:134-225` | ✔ |
| 17 | A gaveta tem 560 px e fecha no Esc sem olhar `defaultPrevented`; a `key` do painel é `${painel}:${aberto}` | `PainelLateral.jsx:467-476`, `:506`; `Empresa.jsx:288` | ✔ |
| 18 | A varredura de cores lê `Components/Trilha` sem recursão | `tests/js/trilha-empresa-render.test.js:556-571` | ✔ subpasta = EISDIR: o teste passa a ser recursivo |
| 19 | O bundle da trilha já traz "Abrir o Publicador" e "no Publicador as cores viram…"; "Mercado Livre" só aparece em comentário | `PainelDaEtapa.jsx:30`; `PainelDeMapear.jsx:302`; `lib/trilhaMapa.js:123` | ✔ o `lazy` de `Cadastro/` evita pôr "Mercado Livre" no JS que o cliente baixa |
| 20 | Moldes de teste e precedentes existem | `tests/Feature/Trilha/Concerns/MontaTrilha.php`, `tests/Unit/Publicador/Concerns/CarregaSchemas.php`, `tests/Feature/Publicador/Lote/CenarioDoPreparo.php`, `CustoDaTrilhaTest`, `FotosNaTrilhaTest`, `AcessoATrilhaTest`; `app/Http/Controllers/Concerns/OperaPlanejadorFull.php` | ✔ |

---

## 3. Escopo da 3ª entrega

**Entra:**
- **"+ Produto na lista"**, na Lista e em "Ver tudo".
- **O cadastro inteiro na gaveta:**
  - Produto: nome, categoria do ML (sugerida + 1 clique, Buscar, Navegar), família, ambientes, título de venda com
    os termos da categoria, descrição;
  - variações: cor, cor e tamanho, ou os outros eixos do sistema, cada uma com SKU e estoque opcional;
  - custo e embalagem: iguais em todas ou por cor;
  - fotos por cor;
  - medidas do produto montado;
  - ficha técnica do Mercado Livre, que pode ser gravada incompleta.
- **Excluir:** cor, tamanho e o produto inteiro (dois passos, como o Portal).
- **"Conferir frete pela conta"**, que só lê a conta da loja.
- **A régua de fotos por cor** e a régua do ME1, conforme a pergunta 3.
- **O caminho completo:** o produto completo sai da Lista sozinho → Mapeamento → Planejamento → preparação na
  data. As etapas 2 a 6 já existem.
- **Proteções:** guarda de "não salvo", Esc, trava do que andou (pergunta 1), versão contra aba velha (F6).
- **Os fins:** "Abrir o Portal da empresa" sai com `trabalho`, e o cliente perde os atalhos para o cadastro do
  Portal.

**Não entra** (§12 diz o que vai para a 4ª):
- prévia do anúncio, "publicar" e preço/precificação;
- planilha (Modelo Completo), fotos em lote e "Importar do Mercado Livre", salvo resposta diferente na pergunta 4;
- capa e ordem das fotos; copiar fotos de outra cor;
- renomear ou excluir família e ambiente (a criação é pelo nome, no salvar);
- "o que é isto?" com explicação gerada por IA;
- rascunho no navegador;
- "Preencher com exemplo" do protótipo, que grava dado fictício numa loja real;
- qualquer tela do Portal;
- qualquer mudança no Publicador;
- cadastro pela porta do cliente;
- migration.

---

## 4. As decisões deste desenho

Estas não viram pergunta: ou têm resposta técnica clara, ou seguem decisão anterior do usuário. Todas podem ser
vetadas.

| # | Decisão | Veio de | Por quê |
|---|---|---|---|
| D1 | Toda rota nova exige as DUAS chaves (`TrilhaLigada` + `TrilhaDeTrabalho`) | todos | sem a de trabalho, salvar prepararia com IA na hora (`PreparoIaAgenda.php:93-137`) |
| D2 | Painel próprio `e:{id}` / `e:novo`; `p:` segue de leitura; 560 px, uma coluna | risco + reuso | não mexe no painel de leitura nem nos testes dele; `e:` cai em `p:` sem escrita, como o `m:` |
| D3 | **Criar primeiro** (Nome + SKU). Estrutura imediata (produto, cor, tamanho, foto, excluir); conteúdo no "Salvar" | nativo | o protótipo faz assim ("Novo" cria e abre); elimina a foto pendente e o remonte de `novo → id` |
| D4 | Salvar único para o conteúdo, na ordem linhas → ficha | todos | o usuário pediu "o jeito do Portal"; o artefato é "só layout"; a cada campo, o histórico ganharia um registro por letra e a IA rodaria várias vezes com a onda começada |
| D5 | Tela nova sobre as libs puras; NENHUM componente nem hook do Portal alterado | nativo + risco | §1 (Reuso): tema escuro, `route('portal.auth…')` fixo, breakpoints de viewport e arquivos disputados com o outro dev |
| D6 | Regras de validação do Portal extraídas para uma classe das duas portas (`RegrasDoCadastro`) | nativo + risco | "duas cópias de regra já custaram caro"; a mudança no Portal é mecânica, num commit próprio, com as suítes dele como rede |
| D7 | A descrição grava pela linha | nativo | `NormalizadorDeLinha.php:349-360` já tem o teto e a mensagem |
| D8 | O JSON da gaveta sai por LISTA DE CAMPOS; as URLs de foto são montadas pela trilha | nativo | o retorno cru dos serviços traz `/portal/0/…`, foto quebrada no admin; campo novo nasce invisível |
| D9 | Ficha técnica gravada incompleta (`exigirObrigatorios: false`, só na trilha) | nativo + risco | cadastro aos poucos; a Lista já mostra "ficha: X"; o robô continua barrado pela ficha incompleta |
| D10 | Campos da ficha por `definicao()`, sem explicações geradas por IA | risco | "abrir a tela não é pedir" (learnings §27): nenhuma IA ao abrir |
| D11 | Categoria: sugerida + 1 clique; nada aceito sozinho (D-06); só **folha e anunciável** | todos + nativo/risco | o preditor erra com nome curto e já caiu em 22/10; `listing_allowed` evita o V-CAT-02 na 4ª |
| D12 | Categoria TRAVADA na trilha quando o produto já está no Publicador; troca no editor de lá | nativo | o Sincronizar não troca a categoria de rascunho que já tem uma; trocar o DRAFT seria mudar o Publicador |
| D13 | Fotos por cor com cor e tamanho (régua de fotos) | todos | a tela já promete "3 fotos em cada cor"; é a regra `comFotosDaCor` do Publicador |
| D14 | SKU digitado, com sugestão `{raiz}-{n}` | todos | o "jeito do Portal"; gerar exigiria regra nova contra SKUs de ML e Bling |
| D15 | Cor nova em produto já mapeado: aviso + [Mapear de novo]; a marca de mapeado não é tocada | todos | o combo é por cor e não nasce sozinho |
| D16 | Porta do cliente com `trabalho`: saem "Completar no cadastro" e "Abrir o cadastro de produtos"; o dono da etapa 1 vira "Equipe ECF" | todos | "só a equipe cadastra"; a 2ª entrega fez o mesmo com o "Decidir no Planejamento" |
| D17 | "Abrir o Portal da empresa" sai com `trabalho` só na F5 | nativo | até lá é a saída de emergência da 459 |
| D18 | `Components/Trilha/Cadastro/` carregada por `React.lazy` só com `podeEditar`; "Mercado Livre" escrito só ali | risco | o cliente não baixa o JS com os textos da equipe (o corte de dados continua no servidor) |
| D19 | Versão contra aba velha (409) numa fatia própria (F6), antes de liberar o módulo para além do Dev | risco (adiada) | na 459, com 1 ou 2 pessoas, o risco é baixo; a F1 sai mais leve, e o orquestrador já nasce com a `versao` opcional |

---

## 5. As telas

### 5.1 A Lista (porta da equipe, com `trabalho` e `pode_editar`)

- **Cabeçalho:** "Etapa 1 de 6 · Equipe ECF", o título e o `faz` novo, neutro porque o cliente também lê.
- **Botão principal "+ Produto na lista"** (`data-chave="e:novo"`). Até a F5, "Abrir o Portal da empresa" fica ao
  lado como secundário.
- **"Pronto quando":** o `ETAPAS[0].pronto` só muda se a pergunta 3 mudar o ME1. Ele está amarrado à régua pelo teste
  de render (learnings §18).
- **Quadros:**
  - "Pelo sistema", para a equipe, em `Cadastro/QuadrosDaLista.jsx` (lazy): "Um produto por vez. Ao digitar o nome,
    o sistema sugere a categoria do Mercado Livre e abre a ficha técnica dela.";
  - "Pela planilha" fica escondido com `trabalho`;
  - o cliente e o "Ver como: Cliente" leem só a nota neutra "A equipe ECF cadastra os produtos da sua loja aqui.".
- **Tabela:** a de hoje (`PainelDaLista.jsx:18-29`). A linha abre `e:{id}`, com a ação "Completar". Produto
  travado (§5.11) abre `p:{id}`, com a ação "Ver".
- **Lista vazia:** "Nenhum produto cadastrado ainda." e o botão.

### 5.2 Criar — a gaveta `e:novo`

```
Produto novo                                                 [Fechar]
─────────────────────────────────────────────────────────────────────
 Nome do item *   [Poltrona Ripada Bege                          ]
 SKU *            [POLT-RIP-BG                  ]
                  O código do produto na loja; único na empresa.
─────────────────────────────────────────────────────────────────────
 Depois de criar, a categoria é sugerida pelo nome.   [Criar produto]
```

- **O envio.** `POST {produtos}` com `{nome, codigo}`. O servidor monta UMA linha sem `grupo` e chama
  `gravarLinhas(…, MODO_GRADE, agendarPreparo: false)`. Nascem o produto, a 1ª variação (sem eixo) e a oferta
  simples (SKU = Ref).
- **Erro.** SKU repetido, conferido sem caixa nem acento, volta como 422 no próprio campo. O botão fica "ocupado"
  (duplo clique não cria dois).
- **Depois de criar:** "Criando…" até a recarga da trilha. Em seguida, troca para `e:{id}`. Remontar não perde nada,
  porque os dois campos já estão gravados. O foco vai para o "Usar esta" da categoria sugerida.
- **SKU que tinha anúncio na espera** (`absorvidos_da_espera > 0`): aviso "Este SKU já tinha anúncio: o produto
  nasceu ligado a ele e vai direto para a etapa 6." Com a trava (pergunta 1), a gaveta abre só para leitura.

### 5.3 A gaveta `e:{id}` — as caixas, na ordem do protótipo

Sobrelinha "Produto da lista"; título = o nome; abaixo, "SKU · família". O corpo rola e o rodapé é a barra de
salvar.

1. **Onde está** — o `OndeEsta` de `Pecas.jsx`, mais "Mapeado em dd/mm" quando houver.
2. **Lista de produtos** — com a pílula "faltam N" ou "completa", lida das `faltas` do SERVIDOR (nada recalculado no
   navegador). Os campos, nesta ordem:
   1. **Nome do item \*** (`data-foco-inicial`).
   2. **Categoria do Mercado Livre \*** — logo abaixo do nome, porque a ficha, o limite do título e as medidas do
      produto dependem dela (§5.4).
   3. **Família \*** — lista da empresa com busca. Nome novo aparece como "(nova)" e é criado no salvar
      (`resolverNomes`).
   4. **Ambientes \*** — chips com busca e o mesmo "(novo)".
   5. **Título de venda \*** — contador "N de {limite}" (60 sem categoria), os termos mais buscados como chips e
      "Começar pelo nome" (`lib/tituloDoProduto.js`). Dica da equipe: "É o título do Clássico; o robô escreve o do
      Premium" (`DadosEfetivosService::comTituloDoCadastro`).
   6. **"Este produto varia?"** — [Não] [Cor] [Cor e tamanho] e os outros eixos do sistema no seletor. Ver §5.6.
      - **Não varia:** SKU \*, Custo (R$) \*, Estoque (opcional), Embalagem \* (§5.8), Fotos \* (§5.7).
      - **Varia:** um cartão por cor (§5.6).
   7. **Medidas do produto montado** — os campos do grupo `medidas_do_produto` da ficha
      (`GRUPO_MEDIDAS_DO_PRODUTO`), separados por `lib/medidasDoProduto.js`. Gravam com a ficha. Sem categoria:
      "Escolha a categoria para ver as medidas do produto."
   8. **Descrição \*** — área de texto com o contador "N de 5.000".
3. **Ficha técnica do Mercado Livre** — §5.5.
4. **Ofertas com este produto** — só leitura, como no `p:` de hoje.

**Rodapé:**
- à esquerda, o estado: "Ao completar, segue sozinho para o mapeamento." / "Há mudanças não salvas." / o erro;
- à direita, **[Salvar]** (primário; desabilitado sem mudança; "ocupado" durante o envio);
- [Mapear agora] (`m:{id}`) quando o produto está fora da Lista;
- [Abrir no Publicador] para o admin, quando o pub existe.

### 5.4 Categoria: sugerida + 1 clique

1. **A busca.** Com o produto sem categoria, ao abrir ou ao mudar o nome (600 ms sem digitar, de 2 a 120 letras),
   sai `GET {categorias}?q={nome}`. É o preditor do ML com o APP token da ECF; não precisa de conta conectada.
   Só a resposta do último pedido vale.
2. **A sugestão.** A 1ª candidata `folha && anunciavel` aparece assim:
   `Categoria sugerida: Casa, Móveis e Decoração › Sala › Poltronas   [Usar esta] [Ver outras] [Navegar]`.
   - **Nada é aplicado sozinho** (D-06).
   - "Usar esta" põe a categoria no ESTADO da gaveta e carrega os campos (`GET {categorias}/{id}/campos`) e os
     termos (`GET {categorias}/{id}/titulo?nome=`). A categoria só grava no "Salvar", na linha (`categoria_ml_id`).
3. **"Ver outras"** lista as candidatas. A que não é folha diz "Escolha uma mais específica" e abre a navegação
   naquele nível.
4. **"Navegar"** é a árvore nível a nível (`useArvoreDeCategorias` + `lib/arvoreDeCategorias`), com o `buscarNivel`
   da trilha (`GET {categorias}/arvore?pai=`). As regras são as do Portal:
   - "Usar esta" só aparece na folha anunciável;
   - a lista só responde depois de 300 ms parada (proteção contra clique errado);
   - 422 ≠ fora do ar.
5. **Sem candidata, ou com o ML fora** (`indisponivel`): "Não achei pelo nome. [Buscar] [Navegar] [Tentar de
   novo]". O resto do cadastro continua salvando; o produto só fica com a falta "categoria".
6. **Trocar uma categoria já escolhida** pede confirmação DENTRO da gaveta: "Os campos da ficha que a categoria nova
   não tem saem quando você salvar."
7. **Produto já no Publicador** (`situacao.no_publicador`): a categoria fica só para leitura, com "Para trocar, use o
   editor do Publicador" e o link para o admin. O servidor também recusa (D12).

Todas as listas abrem DENTRO da gaveta, sem portal do Radix. No Esc, elas fecham a si mesmas com `preventDefault`.

### 5.5 Ficha técnica do Mercado Livre

- **Sem categoria:** "Digite o nome do item: o sistema sugere a categoria e abre os campos dela."
- **Com categoria:** a pílula com o caminho; as **obrigatórias (\*) primeiro**; as "Outras características (N)"
  recolhidas; o filtro Todas, Faltando, Preenchidas (`lib/fichaLayout.js`).
- **Texto da equipe:** "Os campos vêm da categoria do Mercado Livre. Os marcados com \* são os obrigatórios da
  categoria; a revisão do Publicador ainda confere outros antes de publicar." Não se escreve "obrigatórios para
  publicar": `new_required` e `conditional_required` só o Publicador confere.
- **Controles por tipo**, com os mesmos `data-*` do Portal (`data-campo-tecnico`, `data-acao="nao-se-aplica"`):
  - texto e número;
  - número com unidade (sufixo, ou seletor quando há várias);
  - Sim/Não (clicar de novo limpa);
  - lista e lista de várias opções (caixinhas);
  - "Outro (escrever)" onde `aceita_outro`;
  - "Não se aplica" onde `nao_se_aplica`;
  - Marca como texto livre com `<datalist>`.
- **Fora da tela:** o Modelo (a IA gera) e o campo que é eixo do produto.
- **Gravação:** `PUT {produtos}/{p}/ficha-tecnica` com a ficha INTEIRA do estado (`montarAtributos`), porque o
  `gravar` substitui.
  - Com `exigirObrigatorios: false`, o obrigatório vazio grava e vira a falta "ficha: X" na Lista.
  - Valor inválido continua 422 por campo (`errosDaResposta`).
- **"Preencher com IA"** só entra se a pergunta 4 disser sim. Seria um botão manual, só com o produto salvo com
  categoria, com a frase "usa IA" ao lado. Ele não grava: preenche só os vazios (`mesclarSugestoes`) e a pessoa
  salva.

### 5.6 Cores e tamanhos (variações) e o SKU de cada uma

- **"+ Cor" é imediato** (`POST {produtos}/{p}/linhas`). É um mini-formulário: `Nova cor: [Areia] SKU:
  [POLT-RIP-BG-2] (sugerido; pode trocar) [Adicionar cor]`.
  - Na 1ª vez, ele pergunta também a cor do que já existe: a variação atual ganha `eixo='cor'` e `valor` no MESMO
    pedido. Sem valor, ela seria cortada do grupo no Publicador (`CoresDoGrupo`).
  - A cor nova herda custo e caixas da 1ª variação quando a linha não os traz (`ProdutoCadastroService.php:784-819`).
    O estoque não é herdado.
  - Cada cor ganha a sua oferta simples.
- **Cor e tamanho:**
  - um cartão por cor, com o nome editável (muda todas as variações dela) e as fotos da cor;
  - dentro do cartão, uma linha por tamanho com valor \*, SKU \* e estoque;
  - "+ Tamanho" cria uma variação POR COR e mostra os SKUs sugeridos de cada par para confirmar;
  - "+ Cor" cria uma por tamanho existente;
  - tirar o 2º eixo com valores pede confirmação na gaveta.
- **Custo e caixa:** "iguais em todas as cores", o padrão (`grupoComum`, `assinaturaDoCusto`,
  `assinaturaDasCaixas`), ou "diferente por cor". O POST leva os de cada variação.
- **Remover cor ou tamanho** é imediato, com confirmação na gaveta (`DELETE {produtos}/{p}/variacoes/{v}`):
  - peça de combo ou kit → 422 com o texto do serviço;
  - os anúncios da cor voltam para a espera;
  - com dois eixos, a galeria passa para outro tamanho da cor;
  - a última variação não sai por aqui: é "Excluir produto" (§5.9).
- **Conferência das cores**, no servidor e na tela, ANTES de gravar (a régua do `CoresDoGrupo`):
  - com 2 ou mais variações, toda variação tem valor;
  - não há valor (nem par cor e tamanho) repetido;
  - todas usam o mesmo eixo.

  O que fugisse disso viraria, em silêncio, produto separado no Publicador.
- **Variação ligada ao Bling:** SKU e estoque só para leitura ("vem do Bling"), como a trava do serviço
  (`:649-678`).
- **Produto já mapeado que ganha cor:** "Cor nova salva. Este produto já foi mapeado: mapeie de novo para a cor
  ganhar os combos." [Mapear de novo].

### 5.7 Fotos

- **Uma galeria por cor**, na "dona": a 1ª variação da cor com foto, senão a 1ª da cor (`gruposPorCor`). Sem
  variação, a galeria é única.
- **Envio imediato** com seleção múltipla, numa remessa por cor sempre que couber
  (`POST {produtos}/{p}/variacoes/{dona}/fotos`).
  - Limites: 12 por variação, 10 MB, jpg/png/webp. O formato é conferido pelo CONTEÚDO, no servidor.
  - A tela confere antes com `quantasCabem`, `separarGrandes` e `prepararRemessa`.
- **Imagens:** as miniaturas vêm pela rota da equipe `trilha.fotos`; "Ver maior" usa `?original=1`.
- **Contador por cor:** "2 de 3" em âmbar, "3 fotos" em verde.
- **Excluir foto** é imediato, com confirmação. Capa e ordem não entram: a 1ª foto é a capa, como no Portal.
- **Produto já no Publicador:** "Foto nova aqui não entra no anúncio que já está sendo montado: troque no
  Publicador." O Sincronizar só copia para grupo vazio.

### 5.8 Embalagem, custo e frete

- **Embalagem \*:** as caixas da variação (C × L × A em cm, peso em kg), até 20, com "+ caixa". Os limites são os do
  normalizador: 0,01 a 999,99 cm e 0,001 a 9.999,999 kg.
- **Custo (R$) \*:** escrita brasileira ("1.890,00"); quem interpreta é o servidor.
- **Logística:** o retrato que volta do servidor diz ME2, Full ou ME1. Quando der ME1, o texto segue a pergunta 3:
  - "fica na Lista com a falta 'frete ME1'"; ou
  - "segue com aviso: vai por ME1 (transportadora)".
- **[Conferir frete pela conta]** (`POST {produtos}/{p}/frete` → `FreteMe2Service::cotar`):
  - é o único ponto com o token da LOJA, e só faz GET;
  - grava a modalidade real em cache (168 h) e tira o ME1 falso dos limites dos Correios;
  - sem conta conectada, devolve a estimativa.

### 5.9 Salvar, completo e o que acontece depois

**A ordem é contrato** (o eixo é decidido pelas variações JÁ gravadas; a ficha exige a categoria gravada):

1. `POST {produtos}/{p}/linhas` com o que mudou nas caixas Produto, Variações, Embalagem e Custo, mais a
   descrição.
   - As linhas saem pelo `linhaParaServidor`: só o que mudou, e `null` para limpar.
   - Até 200 por pedido (`gravarVariacoes`, com o `enviar` injetado).
   - Com `erros[]` (HTTP 200, um por linha), o salvar PARA e marca os campos pela `chave`.
2. `PUT {produtos}/{p}/ficha-tecnica`, se a ficha ou as medidas do produto mudaram, ou se a categoria mudou. Um
   422 marca os campos. O passo 1 já ficou gravado ("Os dados do produto foram salvos; confira os campos marcados
   na ficha.").
3. **Recarga:** a trilha (`onRecarregar`: faltas e etapa) e o `GET {produtos}/{p}/cadastro`, que vira o novo
   retrato e zera o "mexido".

**Depois de salvar, o resultado toma o lugar do formulário**, no padrão do Mapear (`PainelDeMapear.jsx:172-198`):
- **completo:** "Lista completa: o produto seguiu para o mapeamento." [Mapear agora] [+ Outro produto]
  [Continuar editando];
- **incompleto:** "Salvo. Ainda faltam: …" [Continuar editando] [+ Outro produto].

**"Completo"** é a régua de hoje (`CompletudeDoProduto`), com a correção das fotos por cor (D13) e o ME1 conforme a
pergunta 3:
- **conta:** SKU e nome; família; 1 ambiente ou mais; categoria; título; descrição; custo > 0 e caixa com medidas e
  peso em cada variação; 3 fotos em cada cor; os obrigatórios da ficha (sem Modelo, sem os eixos, N/A não vale em
  obrigatório);
- **não conta:** estoque, preço, opcionais e medidas do produto montado, salvo se obrigatórias.

**Excluir produto** (no rodapé, só com o produto destravado) usa dois passos, como o Portal
(`ExclusaoDeProdutos::previa` e `excluir`):
- a prévia diz quais combos, kits e combits saem junto;
- os combos saem da onda na mesma transação (`TrilhaComboExcluidoObserver`, `5e11e79e3`);
- a cascata leva `trilha_onda_itens` e `trilha_mapeamentos`.

### 5.10 Avisos que a gaveta mostra

| Quando | Texto (só a equipe lê) | Fonte |
|---|---|---|
| A onda do item já começou e o robô ainda não mexeu | "A Onda {nome} já começou: ao salvar, o robô prepara este produto com o que estiver aqui (usa IA)." | `edicao.onda_comecou` |
| O produto já está no Publicador | "Texto, ficha, custo e embalagem que você mudar seguem para lá; categoria e fotos, não." | `situacao.no_publicador` |
| O SKU ligou o produto a um anúncio da espera | §5.2 | `absorvidos_da_espera` |
| Cor nova em produto mapeado | §5.6 | `situacao.mapeado_em` |
| O Publicador separaria uma variação | "sem nome", "repetida", "de outro tipo" | `conferirCores` |

### 5.11 Modo leitura: o produto que já andou (pergunta 1)

Com a recomendação da pergunta 1, o produto fica TRAVADO quando QUALQUER item da trilha que o contém já andou. Isso
vale para a Simples, o Combo N, e o kit e o combit que o levam. A régua é a única `SituacaoDasOfertas::andou`
(`:339-351`):
- tem anúncio;
- o rascunho saiu do DRAFT;
- o robô registrou etapa ou memória de imagens;
- ou o item é o Kit N.

**Na tela:**
- a gaveta abre só para leitura, com o motivo no topo: "A oferta '{nome}' já saiu do Planejamento (o robô já
  preparou, está na revisão ou no ar). Mudar o cadastro agora faria o robô refazer texto e imagens e não muda o
  anúncio no ar.";
- a linha da Lista diz "Ver"; o `p:` não mostra "Editar cadastro";
- o admin vê [Abrir no Publicador].

**No servidor:** toda escrita no produto travado responde **409**, inclusive a das fotos. O que ainda não andou fica
editável: Lista, Mapeamento, Planejamento, e onda começada sem o robô ter mexido (com o aviso do §5.10).

### 5.12 Não perder o que foi digitado

- **"Mexido"** = alguma caixa de conteúdo difere do retrato do servidor (função pura).
- **Guarda de saída**, registrada pelo painel num `GavetaContexto`. Cobre:
  - Esc, clique no fundo, "Fechar";
  - abrir outro item por dentro (`onAbrir`);
  - trocar o "Ver como" para Cliente;
  - as visitas do Inertia (`router.on('before')`);
  - F5 e fechar a aba (`beforeunload`, só com algo mexido).
- **A confirmação é no rodapé da gaveta** (a trilha não usa `window.confirm`): "Há alterações não salvas. [Salvar e
  fechar] [Descartar] [Continuar editando]". O foco vai para "Continuar editando".
- **Esc:** a gaveta passa a respeitar `evento.defaultPrevented` (uma linha em `PainelLateral.jsx:467-476`).
- **Durante o "Salvar":** `fieldset disabled` e botão "ocupado".
- **Ação imediata com conteúdo mexido** (+ cor, foto): ela grava SÓ a estrutura. O reducer puro junta a resposta
  ao estado sem apagar o que ainda não foi salvo.
- **Produto apagado por outra pessoa:** a gaveta fecha na recarga, como hoje (`Empresa.jsx:278-280`).

### 5.13 Porta do cliente e "Ver como: Cliente"

- **Nada de escrita:**
  - `pode_editar` é falso e `escrita` é nula;
  - nenhuma rota nova no `portal.auth`;
  - os dados de edição vêm só pelo JSON da equipe e nunca entram nas props da página.
- **As chaves `e:`:** `e:{id}` cai no painel de leitura `p:{id}`; `e:novo` não abre nada (`painelDaChave`).
- **O JS da pasta `Cadastro/`** só é baixado com `podeEditar`.
- **`cadastro_travado`** sai só em `paraEquipe`. A varredura `AcessoATrilhaTest::assertNaoChegaNadaDaEquipe` segue
  valendo.
- **Com `trabalho` (F5):** `links.produtos` e `url_ficha` ficam nulos no cliente (D16).

### 5.14 Textos que mudam (só com `trabalho`; sem ela, tudo como na 1ª entrega)

| Onde | Hoje | Com `trabalho` |
|---|---|---|
| `ETAPAS[0].dono` (via `etapaDaTrilha`) | Cliente | Equipe ECF |
| `TEXTOS_DA_TRILHA_DE_TRABALHO.lista.faz` (neutro: o cliente lê) | "O cliente cadastra cada produto pela tela ou pela planilha…" | "A equipe cadastra cada produto aqui: nome, categoria, ficha técnica, cores, fotos, medidas e custo. Completo, ele segue sozinho para o mapeamento." |
| `ETAPAS[0].pronto` | — | igual (só ajusta o ME1 se a pergunta 3 mudar a régua; continua citando "frete ME1") |
| `comQuemEsta` (`lib/trilha.js:220`) | Cliente · completar a lista | Equipe ECF · cadastrar a lista (cliente: "cadastrando seus produtos") |
| `acaoDoItem('lista', 'cliente')` | Completar | Acompanhar |
| Rodapé do `p:` em `mapa` (`PainelLateral.jsx:239-248`) | equipe: "Abrir o Portal da empresa" | sai na F5 (o "Mapear" já está lá) |

O `VOCABULARIO_PROIBIDO` (`trilha-trabalho-render.test.js:151`) vale para todos os textos novos ("prazo",
"planejad[oa]", `-KIT`, `-CBT`).

---

## 6. As rotas e os contratos

### 6.1 A guarda (a ordem importa)

Todas as rotas ficam no grupo da equipe de `routes/trilha.php`: `auth`, `verified`, `modulo:trilha.produtos`, CSRF
do grupo `web`. O `where` passa a cobrir `variacao` e `imagem` (`[0-9]{1,18}`, 19 dígitos dá 404), e `categoria`
casa com `MLB[0-9]{1,17}`.

```php
abort_unless(TrilhaLigada::para($company) && TrilhaDeTrabalho::ligada($company), 404);   // 1. as duas chaves, antes de ler a empresa
$empresa = Company::query()->findOrFail($company);                                      // 2. a empresa
abort_unless($this->equipe->podeEntrar($request->user(), $empresa), 403,                 // 3. quem escreve no Portal dela
    'Você não tem acesso ao portal desta empresa.');
// 4. o recurso do ENDEREÇO, preso à empresa → 404 ANTES de validar o corpo ("não é seu" nunca vira "campo faltando"):
//    o produto (where company_id); a variação, que também é DO PRODUTO do caminho; a imagem, que também é DA VARIAÇÃO.
// 5. validação de forma → 422
// 6. escritas no produto: trava (pergunta 1) → 409; versão (F6) → 409
// 7. ator = AtorDoPortal::daEquipe($request->user())   (origem 'interno', causer = User)
```

### 6.2 As rotas, todas sob `/trilha/{company}` (JSON)

Os endereços chegam à tela em `escrita` (só com `pode_editar`):
- `produtos` já existe;
- `categorias` é nova;
- o resto é montado por `lib/trilhaEscrita.js` a partir delas, como o `urlDoMapeamento`.

| # | Método e caminho | Nome · throttle (prefixo próprio) | Validação além da guarda | Serviço do Portal | Efeitos | Fatia |
|---|---|---|---|---|---|---|
| 1 | `GET produtos/{produto}/cadastro` | `trilha.cadastro.abrir` · 120 | — | `ProdutoLinhas::paraProdutos`, `VariacaoImagensService::listarDasVariacoes`, `FichaTecnicaDoProduto::salvos`, `ListasDaEmpresaService::lista` ×2, `TrilhaDaEmpresa::faltasDoProduto` | nenhum; não chama o ML | F1 |
| 2 | `POST produtos` | `trilha.cadastro.criar` · 30 | `nome` 1..255, `codigo` 1..120; o resto é ignorado | `gravarLinhas([1 linha], ator, MODO_GRADE, agendarPreparo: false)` | produto + variação + oferta simples; varre a espera; `garantirOfertas`; registro | F1 |
| 3 | `POST produtos/{produto}/linhas` | `trilha.cadastro.linhas` · 120 | `linhas` 1..200 lidas do **JSON cru**; uma requisição, um produto; lista permitida de chaves; categoria nova folha **e anunciável** e não travada; `conferirCores` | `gravarLinhas(…, MODO_GRADE, agendarPreparo: false)` | linhas e listas pelo nome; oferta da cor nova; `prepararSeCabe` | F1 (uma variação); F2 (categoria); F4 (várias) |
| 4 | `PUT produtos/{produto}/ficha-tecnica` | `trilha.cadastro.ficha` · 60 | regras extraídas da ficha (`atributos` ≤300; valor escalar ou lista ≤60; `nao_se_aplica`, `proprio`, `proprios`) | `FichaTecnicaDoProduto::gravar(…, exigirObrigatorios: false)` | substitui a ficha (Modelo preservado); `prepararSeCabe` | F2 |
| 5 | `GET categorias?q=` | `trilha.categorias` · 60 | `q` 2..120 | `CategoriaSugestaoService::sugerir` + `detalhe` (com `anunciavel`) | caches do preditor (1 h) e da categoria (7 dias); falha → `indisponivel`, nunca erro | F2 |
| 6 | `GET categorias/arvore?pai=` | `trilha.categorias.arvore` · 180 | `pai` vazio ou `MLB\d{1,17}` | `CategoriaBuscaService::nivel` | cache | F2 |
| 7 | `GET categorias/{categoria}/campos` | `trilha.categorias.campos` · 60 | — | `FichaTecnicaDaCategoria::definicao` (D10) | cache `ml_meta_atributos_*` de 7 dias; filtro de sigilo mantido | F2 |
| 8 | `GET categorias/{categoria}/titulo?nome=` | `trilha.categorias.titulo` · 60 | `nome` ≤255 | `TituloDeVendaService::termos` | grava `ml_categoria_schemas`; degrada para `indisponivel`; a tela funciona sem ele (limite 60) | F2 |
| 9 | `POST produtos/{produto}/ficha-tecnica/ia` | `trilha.cadastro.ia` · 10 | as checagens do Portal (`:751-765`): 422 sem categoria salva ou com categoria diferente da tela | `JardinagemDaFicha::sugerir` | IA paga por clique; não grava | F2b, só com o "sim" da pergunta 4 |
| 10 | `POST produtos/{produto}/variacoes/{variacao}/fotos` | `trilha.cadastro.fotos` · 30 | regras extraídas: `recusarEnvioQueNaoChegou` + `imagens` 1..12, `mimes:jpg,jpeg,png,webp`, 10 MB, mensagens com `:position` | `VariacaoImagensService::enviar` | disco privado, tudo ou nada, trava a variação; `prepararSeCabe` | F3 |
| 11 | `DELETE produtos/{produto}/variacoes/{variacao}/fotos/{imagem}` | `trilha.cadastro.fotos.excluir` · 60 | — | `VariacaoImagensService::excluir` | renumera; o arquivo sai depois do commit; `prepararSeCabe` | F3 |
| 12 | `GET fotos/{variacao}/{imagem}?original=1` (rota que já existe, `trilha.fotos`) | 600 | `original` boolean | — | a original (`private, max-age=3600`, `nosniff`); sem o parâmetro, a miniatura, como hoje | F3 |
| 13 | `POST produtos/{produto}/frete` | `trilha.cadastro.frete` · 20 | — | `FreteMe2Service::cotar` (as variações do produto) | GET na conta da LOJA; grava a modalidade em cache | F3 |
| 14 | `DELETE produtos/{produto}/variacoes/{variacao}` | `trilha.cadastro.variacao` · 30 | — | `ProdutoCadastroService::excluirVariacao` | peça de combo ou kit → 422; anúncios para a espera; a galeria passa de tamanho; não agenda o preparo (como o Portal) | F4 |
| 15 | `POST produtos/{produto}/exclusao/previa` + `DELETE produtos/{produto}` | `trilha.cadastro.exclusao` · 10 | `montadas` confirmadas (≤2000) | `ExclusaoDeProdutos::previa` / `excluir` | em dois passos; cascata `trilha_*`; o observer tira os combos da onda | F4 |

As fotos ficam no `FotosDaTrilhaController`, que já existe (rotas 10 a 12). As categorias ficam em
`CategoriasDaTrilhaController` (rotas 5 a 8). O resto fica em `CadastroDaTrilhaController`.

### 6.3 Os contratos que a trilha acrescenta

- **JSON cru nas linhas.** É `RegrasDoCadastro::linhasComoVieram`, extraído de
  `PortalEstruturaProdutosController.php:271-281`. Sem ele, o `ConvertEmptyStringsToNull` troca `''` (não mexi) por
  `null` (limpar) e APAGA custo, eixo, família ou título.
- **Uma requisição, um produto.** Na rota 3, toda linha:
  - recebe `produto_id` = o do caminho;
  - quando tem `id`, esse `id` precisa ser variação DESTE produto. Senão: 404 uniforme, antes do serviço (afirmação
    4 do §2).

  As chaves fora da lista permitida são descartadas: `grupo`, `company_id`, `categoria_texto`, `variacao` e
  `volumes_texto`. A lista permitida é `id, chave, codigo, nome, titulo, eixo, valor, eixo_2, valor_2, ordem,
  familia, ambientes, categoria_ml_id, volumes, custo, estoque, descricao`.
- **Lista de campos** (D8). O JSON da rota 1 é este:

```json
{
  "produto":   {"id": 12, "nome": "…", "titulo": "…", "familia": "Poltronas", "ambientes": ["Sala"],
                "categoria": {"id": "MLB…", "nome": "…", "caminho_texto": "… › …", "estado": "confirmada"},
                "descricao": "…", "eixo": "cor", "eixo_2": null, "origem_ml": false},
  "variacoes": [{"id": 31, "codigo": "POLT-RIP-BG", "valor": "Bege", "valor_2": null, "ordem": 1,
                 "custo": "890.00", "estoque": 4, "volumes": [{"c": 80, "l": 80, "a": 90, "kg": 18.5}],
                 "bling": false, "dona_da_cor": true, "logistica": "me2",
                 "fotos": [{"id": 501, "url": "/trilha/459/fotos/31/501", "url_original": "/trilha/459/fotos/31/501?original=1", "nome_original": "p1.jpg"}]}],
  "ficha":     {"salvos": [{"id": "BRAND", "nome": "Marca", "valor": "…", "valor_id": null, "unidade": null}]},
  "listas":    {"familias": [{"id": 1, "nome": "Poltronas"}], "ambientes": [{"id": 3, "nome": "Sala"}]},
  "eixos":     {"cor": "Cor", "tamanho": "Tamanho", "voltagem": "Voltagem", "material": "Material", "sabor": "Sabor", "outro": "Outro"},
  "limites":   {"fotos_por_variacao": 12, "foto_mb": 10, "descricao": 5000, "linhas": 200},
  "situacao":  {"etapa": "lista", "faltas": ["fotos (0 de 3)"], "avisos": [], "no_publicador": false,
                "categoria_travada": false, "mapeado_em": null},
  "edicao":    {"livre": true, "motivo": null, "onda_comecou": null},
  "versao":    null
}
```

  - O `capa`, o frete estimado e o `oferta` com conjuntos do `ProdutoLinhas` NÃO saem.
  - As URLs de foto são `route('trilha.fotos', …, false)`, montadas pelo id. O `VariacaoImagensService` não muda.
  - Nenhuma resposta das rotas da trilha contém `/portal/`.
- **O gatilho do Publicador: `prepararSeCabe`** (pergunta 2, recomendado). Depois de CADA escrita:
  - o `gravarLinhas` é chamado com `agendarPreparo: false`;
  - o serviço da trilha chama `aoSalvar` só se `faltasDoProduto === []` ou se o produto já está no Publicador.
    "Já está no Publicador" = existe `pub_produtos` pelo `estrutura_produto_id`, ou pela oferta simples de uma cor
    (o legado por cor).

  O 1º Sincronizar leva categoria, ficha e TODAS as fotos de uma vez. Com a chave de trabalho, a IA fica
  `aguardando_onda` (`PortaoDaOnda`). Exclusão não agenda, como no Portal.
- **Trava** (pergunta 1). Responde 409 com `{travado: true, motivo}`. A conta usa `TrilhaDaEmpresa::itens()`
  filtrado pelos itens que contêm o produto, com número fixo de consultas.
- **Categoria.**
  - Se a linha trouxer `categoria_ml_id` diferente da gravada, o controller confere
    `CategoriaSugestaoService::detalhe($id)['anunciavel']` (mesmo cache). Se ela não for anunciável: 422 "O Mercado
    Livre não aceita produtos novos nesta categoria; escolha outra.". Com o ML fora, segue como o Portal ("não
    validada", com aviso).
  - Com `no_publicador`: 422 "A categoria já está no Publicador; troque no editor de lá."
- **Versão** (F6). `App\Services\Trilha\VersaoDoCadastro::de(Company, EstruturaProduto)` = `sha1` do JSON canônico
  (4 consultas) de:
  - os campos do produto;
  - os ids dos ambientes, ordenados;
  - as variações (`id, codigo, ordem, eixo, valor, eixo_2, valor_2, custo`);
  - os volumes;
  - a ficha.

  Ficam FORA o estoque (o Bling grava por fora e daria 409 falso) e as fotos (enviar só acrescenta; excluir age por
  id). Versão diferente → 409 "Este produto mudou depois que você abriu (outra pessoa salvou aqui ou no Portal).
  Recarregue para ver o que mudou.", com a versão atual. A versão pega a aba velha, não a corrida de milissegundos:
  envolver o `gravarLinhas` numa trava seguraria o HTTP da categoria dentro dela.
- **Rastro.** Os serviços gravam os eventos de sempre (`activity_log`, canal `portal`, origem `interno`). O
  controller acrescenta UM evento por escrita, `trilha_cadastro_salvo`, com `via: trilha` e o `passo` (`criar`,
  `linhas`, `ficha`, `fotos`, `foto_excluida`, `variacao_excluida`, `produto_excluido`), pelo
  `RegistroEstrutura::registrar`.
- **413 com mensagem.** A regex de `bootstrap/app.php:77-84` passa a aceitar também
  `#^trilha/[0-9]+/produtos/[0-9]+/variacoes/[0-9]+/fotos$#`.
- **Erros que a tela lê** (`lib/trilhaEscrita.js` → `mensagemDoErro`): 404 e 403 (recarregar), 422 (por campo), 409
  (novo: trava ou versão) e 429.

---

## 7. Os serviços

**Reaproveitados como estão** (recebem `Company` + `AtorDoPortal`):
- `ProdutoCadastroService` (`gravarLinhas`, `excluirVariacao`);
- `FichaTecnicaDoProduto` (`salvos`, `gravar`);
- `FichaTecnicaDaCategoria` (`definicao`, `doProduto`, `camposPorId`);
- `VariacaoImagensService` (`enviar`, `excluir`, `listarDasVariacoes`, `miniatura`, `existe`);
- `ListasDaEmpresaService::lista`;
- `CategoriaBuscaService::nivel`;
- `CategoriaSugestaoService::sugerir`/`detalhe`;
- `TituloDeVendaService::termos`;
- `FreteMe2Service::cotar`;
- `ExclusaoDeProdutos`;
- `ProdutoLinhas::paraProdutos`;
- `PreparoIaAgenda::aoSalvar`;
- `JardinagemDaFicha::sugerir` (se a pergunta 4 deixar);
- da trilha: `CompletudeDoProduto`, `TrilhaDaEmpresa`, `SituacaoDasOfertas`.

**Alterados (aditivos, padrão igual ao de hoje):**

| Arquivo | Mudança | O que protege o resto |
|---|---|---|
| `FichaTecnicaDoProduto::gravar` | `bool $exigirObrigatorios = true`; com `false`, o obrigatório vazio não vira erro | o Portal não passa o parâmetro; `FichaTecnicaDoProdutoTest` ganha o caso do padrão |
| `CategoriaSugestaoService::detalhe/detalhes` | chave `anunciavel` (folha e `settings.listing_allowed !== false`) | chave nova; o Portal a ignora |
| `CompletudeDoProduto::avaliar` | fotos por cor com `eixo_2` (`comFotosDaCor`, com comentário "mudou lá, muda aqui", learnings §9); ME1 conforme a pergunta 3 (`avisos`) | só a trilha usa |
| `TrilhaDaEmpresa` | `faltasDoProduto(Company, int)` (a régua de `faltasDosProdutos`, de um produto só, com consultas fixas); `cadastro_travado` por produto em `montar` | — |
| `ApresentacaoDaTrilha` | `escrita.categorias`; `cadastro_travado` SÓ em `paraEquipe`; `avisos` no `PRODUTO` (neutro); `portal_abrir` nulo com `trabalho` (F5); cliente: `links.produtos`/`url_ficha` nulos com `trabalho` (F5) | sem `trabalho`, a 1ª entrega fica igual |
| `FotosDaTrilhaController` | `enviar`, `excluir`, `?original=1` em `ver` | rota da trilha |
| `PortalEstruturaProdutosController` | passa a chamar `RegrasDoCadastro`; comportamento igual | commit próprio; as suítes do Portal arquivo por arquivo |
| `bootstrap/app.php` | a regex do 413 da trilha | a do Portal fica |
| `routes/trilha.php` | as rotas 1 a 15; `where` de `variacao`, `imagem`, `categoria` | — |

**Novos:**
- `app/Http/Controllers/Trilha/CadastroDaTrilhaController.php`: abrir, criar, linhas, ficha, ficha por IA,
  excluirVariacao, exclusão e frete.
- `app/Http/Controllers/Trilha/CategoriasDaTrilhaController.php`: buscar, árvore, campos e título.
- `app/Services/Trilha/CadastroNaTrilha.php`, com a regra testável:
  - `alvo()` (a guarda);
  - `retrato()` (a lista de campos);
  - `conferirLinhas()` (uma requisição, um produto; a lista permitida);
  - `conferirCores()`;
  - `travado()`;
  - `prepararSeCabe()`, `jaNoPublicador()`;
  - `registrar()`.
- `app/Support/Portal/Estrutura/RegrasDoCadastro.php`, as regras extraídas do Portal:
  - `linhasComoVieram`;
  - as regras da ficha (`:708-732`, `MAX_OPCOES_MULTIVALOR`);
  - as das imagens (`:822-833`);
  - `recusarEnvioQueNaoChegou`/`bytesDoIni` (`:1110-1152`);
  - as mensagens.
- `app/Services/Trilha/VersaoDoCadastro.php` (F6).

---

## 8. Os componentes React

**Novos, em `resources/js/Components/Trilha/Cadastro/`** (lazy, só com `podeEditar`; o único lugar onde "Mercado
Livre" pode estar escrito):

| Arquivo | Papel |
|---|---|
| `PainelDoCadastro.jsx` | a gaveta `e:{id}`: carrega o GET (com "Tentar de novo"), monta as caixas, faz o Salvar na ordem, a guarda, o resultado, o modo leitura, os avisos e o 409 |
| `NovoProduto.jsx` | `e:novo`: Nome e SKU, "Criar produto", espera a recarga e troca para `e:{id}` |
| `CaixaDoProduto.jsx` | nome, família, ambientes, título com termos, descrição |
| `EscolhaDaCategoria.jsx` | sugerida + 1 clique, Ver outras, Navegar, confirmação de troca, categoria travada |
| `CaixaDasVariacoes.jsx` | "varia?", cartões por cor, linhas por tamanho, "+ Cor", "+ Tamanho", SKU sugerido, custo e caixa iguais ou por cor, remover, avisos (Bling, mapeado, `conferirCores`) |
| `FotosDaCor.jsx` | a galeria da cor: envio, miniaturas, ver maior, excluir, contador, aviso "já no Publicador" |
| `CaixaDaEmbalagem.jsx` | caixas, custo, logística, ME1, [Conferir frete pela conta], medidas do produto montado |
| `CaixaDaFicha.jsx` + `CampoDaFicha.jsx` | a ficha por tipo, nos tokens da trilha, com os `data-*` do Portal |
| `QuadrosDaLista.jsx` | o quadro "Pelo sistema" da equipe |
| `PecasDoCadastro.jsx` | `AreaDeTexto`, `CampoComUnidade`, `Chips`, `ListaComBusca` (sem portal; Esc com `preventDefault`), `EscolhaSegmentada`, `EnvioDeArquivos`, `ConfirmacaoNaGaveta` |
| `useCadastroDoProduto.js`, `useCategoriaDaTrilha.js`, `useFichaDaTrilha.js` | estado, retrato, "mexido", `versao`; sugestão e busca; campos por categoria (espera de 350 ms, memória por categoria, tira os eixos e as medidas). Sem `className`: são `.js` |

**Lógica pura nova, `resources/js/lib/trilhaCadastro.js`** (sem React, axios nem classe):
- `gravarCadastro({estado, enviarLinhas, gravarFicha})`: a ordem, a parada no 1º erro, a mensagem por passo e a
  `versao` passada adiante;
- `aplicarResposta(estado, retrato, resposta)`: o reducer das ações imediatas, que não apaga o não salvo;
- `mexido`, `linhasParaSalvar` (sobre `linhaParaServidor`), `errosPorCampo`;
- `sugestaoDaCategoria` (1ª folha anunciável), `fotosPorCor` (sobre `gruposPorCor`), `conferirCores`,
  `refsSugeridas`;
- `deveFecharNoEsc`, `CHAVE_NOVO_PRODUTO`.

**Reaproveitados tal qual** (puros e testados):
- `lib/produtosGravacao.js` (`gravarVariacoes` com `enviar` injetado, `mensagemDeFalha`);
- `lib/produtosEstrutura.js` (`linhaParaServidor`, `linhaDoServidor`, `gruposPorCor`, `refSugerida`,
  `segundoEixoDe`), MENOS `ESTILO_LOGISTICA`/`renderFrete`/`renderPesoCubado`, que são escuros;
- `lib/fichaTecnica.js`, `lib/fichaLayout.js`, `lib/medidasDoProduto.js`, `lib/tituloDoProduto.js`;
- `lib/imagensVariacao.js` (`quantasCabem`, `separarGrandes`, `prepararRemessa`, `avisosDoErro`; o "pendente" não é
  usado);
- `lib/arvoreDeCategorias.js` + `Components/Categorias/useArvoreDeCategorias.js`;
- da trilha: `Pecas.jsx` (`CAMPO`, `ROTULO_DO_CAMPO`, `Seletor`, `CaixaDeMarcar`, `Botao`, `Caixa`, `Pilula`,
  `OndeEsta`), `Aviso.jsx` e o padrão do `PainelDeMapear`.

**Alterados:**

| Arquivo | Mudança |
|---|---|
| `lib/trilha.js` | `e:` em `acharAberto`; `TEXTOS_DA_TRILHA_DE_TRABALHO.lista` (dono e faz); `comQuemEsta` e `acaoDoItem` com `trabalho` |
| `Components/Trilha/estadoDaTela.js` | `painelDaChave`: `e:{id}` → `cadastro` só com `podeEditar`, senão `p:`; `e:novo` só com `podeEditar`; `lerEstadoDaUrl` e `buscaComEstado` com `e%3A…` (learnings §15) |
| `Pages/Trilha/Empresa.jsx` | painel `cadastro` por `lazy(() => import(…))` + `Suspense`; `fechar`, `abrirItem` e "Ver como" perguntam à guarda |
| `Components/Trilha/PainelLateral.jsx` | Esc com `defaultPrevented`; `GavetaContexto`; "Editar cadastro" no `p:` (destravado); `alvoDaVolta` (`e:{id}` → linha `p:{id}`; `e:novo` → o botão); F5: sai "Abrir o Portal" do rodapé em `mapa` |
| `Components/Trilha/PainelDaLista.jsx` | "+ Produto na lista"; a linha abre `e:` ou `p:`; quadros por visão (o da equipe por `lazy`); vazio com o botão |
| `Components/Trilha/PainelDaEtapa.jsx` | `PainelGeral` com "+ Produto na lista" para quem escreve |
| `Components/Trilha/ComQuemEsta.jsx` | passa `trabalho` |
| `lib/trilhaEscrita.js` | as chamadas novas (`abrirCadastro`, `criarProduto`, `gravarLinhas`, `gravarFicha`, `enviarFotos` multipart, `excluirFoto`, `excluirVariacao`, `previaDaExclusao`, `excluirProduto`, `conferirFrete`, `buscarCategorias`, `nivelDaArvore`, `camposDaCategoria`, `termosDoTitulo`, `fichaPelaIa`) e o 409 em `mensagemDoErro` |

**NÃO reaproveitados, de propósito:**
- os 40 componentes de `Components/Portal/Estrutura/Produtos/`, por quatro motivos:
  - tema escuro, que o learnings §6 proíbe;
  - breakpoints de viewport, que valem o layout de computador numa gaveta de 520 px;
  - `route('portal.auth…')` em 39 lugares;
  - Radix Popover, com o Esc que vaza;
- os hooks do Portal (`useFichaProduto`, `useFichaTecnica`, `useTermosDoTitulo`, `useDescricaoProduto`). Mexer neles
  é mexer na tela do cliente em produção, e esses arquivos são os mais disputados com o outro dev.

---

## 9. Os testes (no mesmo commit de cada fatia)

**O `setUp` comum**, no molde `FotosNaTrilhaTest` + `MontaTrilha`:
- `Storage::fake(VariacaoImagensService::DISCO)`, `Queue::fake()`, `Http::preventStrayRequests()`, `Cache::flush()`;
- `config` com `trilha.empresas`, `trilha.trabalho.empresas` e `publicador.preparo_ia.ativo = true` (o `.env` do
  worktree desliga, learnings §26);
- `Cache::put('ml_app_token_coleta', …)`;
- as respostas reais do trait `CarregaSchemas` (MLB193945 Cadeiras, MLB31447 Camiseta) com `Http::fake` por URL;
- o dublê de `EcfDriveService::listSignals` (learnings §12).

| Arquivo | Fatia | O que afirma |
|---|---|---|
| `tests/Feature/Trilha/TrilhaDaEmpresaTest.php` (ampliado) | F0 | cor × tamanho com 3 fotos só na dona → sem a falta "fotos"; tamanho com 1 foto própria → "fotos (1 de 3)"; um eixo só continua por variação; (pergunta 3) ME1 vira `avisos` e não `faltas` |
| `tests/Feature/Trilha/CadastroNaTrilhaAcessoTest.php` (novo; cresce a cada fatia) | F1+ | `dataProvider` com TODAS as rotas: fora de `trilha.empresas` → 404; fora de `trabalho` → 404; sem login → redireciona; sem `podeEntrar` → 403; produto, variação ou imagem de outra loja → 404 COM corpo inválido (prova a ordem); variação de OUTRO produto da mesma loja → 404; 19 dígitos → 404; módulo oculto para quem não é Dev → 404 |
| `tests/Feature/Trilha/CadastroNaTrilhaTest.php` (novo) | F1 | criar → produto, variação e 1 oferta simples (SKU = Ref); SKU repetido sem caixa nem acento ("póltrona-1" × "POLTRONA-1") → 422 `errors.codigo`; duplo POST não cria dois; `''` não apaga e `null` limpa (custo, família, título); `id` de outro produto no corpo → 404 e o produto B fica igual; `grupo`/`categoria_texto` descartados; descrição acima de 5.000 → erro da linha; família e ambiente novos pelo nome; `prepararSeCabe`: incompleto NÃO enfileira `SincronizarProdutoDoPortalJob`/`PrepararProdutoNoPublicadorJob`, completo enfileira uma vez, com `pub_produtos` enfileira mesmo incompleto; evento `trilha_cadastro_salvo` (`via`, `passo`, origem `interno`, causer); espera absorvida → aviso; (pergunta 1) rascunho VALIDATED, com anúncio, com etapa do robô e Kit N → 409 em cada escrita; onda começada sem o robô → editável e `edicao.onda_comecou`; nenhuma resposta contém `/portal/` |
| `tests/Feature/Trilha/CustoDoCadastroTest.php` (novo; molde `CustoDaTrilhaTest` + `CenarioDoPreparo`) | F1/F3 | completar pela trilha com a Simples em onda futura → o Job, rodado à mão → `aguardando_onda`, e a IA dublê nunca é chamada; onda começada → a IA roda uma vez por Salvar; produto travado → 409 e nenhum Job |
| `tests/Feature/Trilha/CategoriasNaTrilhaTest.php` (novo) | F2 | busca com `anunciavel`; `indisponivel` com o ML em 500 e com `[]`, sem erro HTTP; árvore; campos sem `GerarExplicacoesDeAtributosJob` na fila; nenhum rótulo com "Mercado"/"ml" (sigilo da definição); termos gravam `ml_categoria_schemas` |
| `tests/Feature/Trilha/FichaNaTrilhaTest.php` (novo) | F2 | obrigatório vazio grava (200) e a Lista diz "ficha: x"; valor inválido → 422 e nada gravado; id de eixo e desconhecido ignorados; Modelo preservado; sem categoria → 422 sem chamar o ML; catálogo fora → 422 sem apagar; categoria não folha → erro da linha; não anunciável → 422; troca com `no_publicador` → 422; depois do GET dos campos, a Lista não diz "a conferir"; (F2b) IA: 422 sem categoria salva e sugestão sem gravar |
| `tests/Feature/Trilha/FotosNaTrilhaTest.php` (ampliado) | F3 | envio de 3 → URLs `/trilha/{c}/fotos/…`; 13ª → 422, tudo ou nada; texto com extensão `.jpg` → 422; `PostTooLargeException` no caminho da trilha → 422 `errors.imagens`; excluir renumera e o arquivo sai depois do commit; imagem de outra variação ou loja → 404; `?original=1` entrega a original; a foto que completa o produto enfileira uma vez; trava → 409; frete: sem conta → estimativa; com `Http::fake` da preferência → modalidade em cache e o ME1 falso some |
| `tests/Feature/Trilha/CoresNaTrilhaTest.php` (novo) | F4 | a 1ª cor nomeia a variação que existe no mesmo pedido; a cor nova herda custo e caixas e ganha oferta; `conferirCores` (valor vazio com 2+, repetido, outro eixo → 422); excluir cor peça de combo → 422 e nada muda; excluir a dona passa a galeria; última variação pela rota da cor → recusada; excluir produto em dois passos (montadas confirmadas; o combo sai da onda; cascata) |
| `tests/Feature/Trilha/AcessoATrilhaTest.php` (ajustado) | F1, F5 | `escrita.categorias` só para quem escreve; `cadastro_travado` nunca chega ao cliente; F5: `portal_abrir` nulo com `trabalho` e igual sem ele (`:118`); cliente com `links.produtos`/`url_ficha` nulos com `trabalho`; a varredura `assertNaoChegaNadaDaEquipe` sem mudança |
| `tests/Feature/Trilha/VersaoDoCadastroTest.php` (novo) | F6 | versão velha → 409 e nada muda; em dia → grava e devolve a nova; estoque do Bling não muda a versão; foto não muda a versão; categoria trocada pelo Portal → 409 |
| `tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php` (ampliado) | F2 | o Portal, sem o parâmetro, continua exigindo os obrigatórios |

**JS** (`node --test`; esbuild + `renderToStaticMarkup`, como os `trilha-*`; os componentes `lazy` são testados
importados direto):

| Arquivo | O que afirma |
|---|---|
| `tests/js/trilha-cadastro.test.js` (novo) | `gravarCadastro` (ordem, parada, mensagem por passo, `versao`); `aplicarResposta` (+ cor com custo não salvo: nada se perde); `mexido`; `linhasParaSalvar` (só o que mudou; `null` limpa; produto na 1ª linha; cor nova com custo e caixas explícitos); `errosPorCampo`; `sugestaoDaCategoria`; `fotosPorCor`; `conferirCores`; `refsSugeridas`; `deveFecharNoEsc`; `painelDaChave`, `lerEstadoDaUrl` e `alvoDaVolta` com `e:12` e `e:novo` (quem escreve, cliente, "Ver como") |
| `tests/js/trilha-cadastro-render.test.js` (novo) | a gaveta na ordem das caixas; "Categoria sugerida" sem aplicar; `*` nos obrigatórios; pílula "faltam N"/"completa"; resultado "Lista completa…" + "Mapear agora"; modo leitura com o motivo; 409; avisos (onda começada, Publicador, mapeado) |
| `tests/js/trilha-escrita.test.js` (ampliado) | as URLs novas sob `escrita.produtos` e `escrita.categorias`; sem endereço → "recarregue a página"; 409 em `mensagemDoErro` |
| `tests/js/trilha-trabalho-render.test.js` + `_trilha-exemplo.js` (ajustados) | "+ Produto na lista" só para quem escreve; textos da etapa 1 com `trabalho`; F5: as linhas `:182-185` invertidas (sem link do Portal); `VOCABULARIO_PROIBIDO`; "Ver como: Cliente" sem "Mercado Livre" e sem campo editável |
| `tests/js/trilha-empresa-render.test.js` (ajustado) | varredura de cores RECURSIVA (`:556-571`), cobrindo `Cadastro/`; teste de fonte: `Empresa.jsx` importa `Cadastro/` só por `lazy(() => import(`; nenhum literal "Mercado Livre" fora de `Cadastro/` (sem comentários); sem `trabalho`, tudo igual; o "Pronto quando" continua conferido contra a régua PHP |
| `estrutura-*`, `categorias-navegar`, `titulo-do-produto`, `estrutura-ficha-sigilo` | ficam verdes SEM mudança: nada novo na pasta do Portal nem nas libs que o teste de sigilo varre |

**Rede de regressão, a cada fatia:**
- `tests/Feature/Trilha/*` inteira. As 10 falhas antigas de Polos e o `FotosDoPainelServiceTest` não são regressão
  (learnings §26).
- Do Portal, os que tocam o que foi extraído ou aditivado, ARQUIVO POR ARQUIVO (a pasta inteira estoura 512 MB):
  - `FichaTecnicaDoProdutoTest`;
  - os testes de linhas, imagens e descrição de `tests/Feature/PortalCliente/Estrutura/Produtos/`;
  - `SalvarPreparaNoPublicadorTest`;
  - `TrazerDoMercadoLivreTest`.
- Antes: `npm run build` (sem `public/build`, ~31 testes do Portal caem, learnings §12).
- Comandos:
  ```
  npm run build
  PUBLICADOR_PREPARO_IA_ATIVO=true php vendor/bin/phpunit --colors=never tests/Feature/Trilha/CadastroNaTrilhaTest.php
  npm run test:js
  ```

**Na tela de verdade:** pelo CDP (perfil `ECF-QA`, porta 9223), na base LOCAL. O roteiro de cada fatia:
1. criar → categoria sugerida → ficha → cor → fotos → completo;
2. conferir o aviso e o Mapeamento;
3. Esc com a lista de categorias aberta (fecha só a lista);
4. fechar com algo mexido;
5. F5;
6. "Ver como: Cliente";
7. claro e escuro;
8. 390 px;
9. o foco volta ao fechar.

Na 459, só depois de deploy autorizado, a conferência é por RECONSULTA AO BANCO: o rascunho nasce com todas as fotos
e a categoria certa, e sem `ia_preparo.etapas` antes da onda (learnings §28).

---

## 10. As fatias, na ordem

**Valem para todas:**
- teste no mesmo commit;
- `git commit -- <caminhos>` (nunca `git add -A`) e `git show <sha>` antes do push;
- `npm run build` em toda fatia com tela;
- **nenhum deploy sem autorização**;
- só aparece para empresa nas duas chaves (hoje a 459) e para o Dev (módulo oculto).

Nenhuma fatia cria nem muda Job, migration, `.env` ou `modules:sync`. Por isso o `queue:restart` não é preciso:
o `prepararSeCabe` só enfileira Jobs que já existem, com a assinatura de hoje (o cuidado do learnings §28 não se
aplica). O desfazer de cada fatia é reverter o commit. O que ela criar na 459 são produtos comuns do Portal, com
códigos `TRILHA-*`.

| Fatia | Escopo | Pronto quando | Esforço |
|---|---|---|---|
| **F0 — Fotos por cor** (só servidor) | `CompletudeDoProduto` com o empréstimo da cor quando há `eixo_2`; teste; learnings | um produto cor × tamanho com 3 fotos na dona sai da Lista; o de um eixo fica igual | ½ dia |
| **F1 — Esqueleto: criar e editar o básico** | **servidor:** `RegrasDoCadastro` (só o `linhasComoVieram`, num commit próprio, com o Portal passando a usá-la); `CadastroDaTrilhaController` + `CadastroNaTrilha` (guarda, lista de campos, uma requisição/um produto, `prepararSeCabe`, trava conforme a pergunta 1, evento); rotas 1 a 3 (uma variação); `faltasDoProduto`; `cadastro_travado`; `escrita` do cadastro. **tela:** chave `e:`; "+ Produto na lista" (Lista e Ver tudo); `NovoProduto`; `PainelDoCadastro` com Onde está, Nome, Família, Ambientes, Título (sem termos), Descrição, SKU, Custo, Estoque, Embalagem e Ofertas; Salvar e resultado; guarda; Esc; modo leitura; aviso de onda começada; `lazy` de `Cadastro/`; varredura recursiva; textos da etapa 1 com `trabalho` | um produto criado pela trilha aparece na Lista com as faltas "categoria" e "fotos (0 de 3)"; aparece na tela de Produtos do Portal com a oferta simples; o Publicador NÃO o recebe enquanto incompleto (pergunta 2); o produto que andou abre só para leitura | 2–3 dias |
| **F2 — Categoria e ficha técnica** | **servidor:** `CategoriasDaTrilhaController` (rotas 5 a 8); rota 4; `anunciavel` no `CategoriaSugestaoService`; `exigirObrigatorios`; regras da ficha extraídas; categoria anunciável e travada na rota 3; `escrita.categorias`. **tela:** `EscolhaDaCategoria`, título com termos e limite, `CaixaDaFicha`/`CampoDaFicha`, medidas do produto montado, confirmação de troca | o nome sugere sem aplicar; "Usar esta" abre os campos; a ficha parcial grava e a Lista diz "ficha: x"; a categoria não anunciável é recusada; nenhuma explicação vai para a fila; a ficha nunca fica "a conferir" | 2–3 dias |
| **F2b — "Preencher com IA"** (só com o "sim" da pergunta 4) | rota 9 + o botão, com "usa IA" ao lado | preenche só os vazios; nada grava sem o Salvar | ½ dia |
| **F3 — Fotos e frete: o 1º produto de ponta a ponta** | **servidor:** rotas 10 a 13; regras de imagem extraídas + `recusarEnvioQueNaoChegou`; regex do 413; ME1 conforme a pergunta 3. **tela:** `FotosDaCor`; "Ver maior"; [Conferir frete pela conta]; avisos | um produto sem variação, completo pela trilha, vai para o Mapeamento sem abrir o Portal; o rascunho nasce com as 3 fotos e a categoria certa; "Mapear agora" abre `m:{id}` | 1–2 dias |
| **F4 — Cores, tamanhos e exclusões** | **servidor:** rota 3 com várias variações e lotes; `conferirCores`; rotas 14 e 15. **tela:** `CaixaDasVariacoes` (1 e 2 eixos), SKUs sugeridos, "iguais em todas", remover, excluir produto, avisos (mapeado, Bling) | uma poltrona de 2 cores e uma camiseta de teste (2 cores × 3 tamanhos, MLB31447) saem completas; no Publicador, um grupo com as cores e as fotos de cada uma; excluir a peça de kit é recusado | 2–3 dias |
| **F5 — Fechamento da etapa 1** | `portal_abrir` nulo com `trabalho` e os 5 lugares do front; o cliente sem os atalhos do Portal; o rodapé do `p:` em `mapa`; textos finais; § novo no learnings | a etapa 1 não tem mais nenhum caminho que leve ao Portal; o cliente da 459 lê a trilha neutra | ½–1 dia |
| **F6 — Rede contra a aba velha** (antes de liberar o módulo para além do Dev) | `VersaoDoCadastro`; 409 nas rotas 3, 4, 14 e 15; tela do conflito ("Recarregar") | duas abas: a 2ª a salvar recebe 409 e nada se perde | 1 dia |

**Total:** ~9 a 13 dias com testes. O caminho crítico é a tela: as caixas da F1, a ficha da F2 e as variações da
F4.

---

## 11. Riscos que ficam (e por que é aceitável agora)

| Risco | Fica como | Antes de quê |
|---|---|---|
| O cliente, no Portal, grava por cima do que a equipe fez: a ficha do Portal substitui tudo e o Portal não confere a versão | aceito na 459 (o cliente não edita); a F6 protege só a trilha | antes de ligar a trilha de trabalho para cliente real |
| O Portal ainda agenda o Publicador a cada save: um produto da trilha editado pelo cliente vai ao Publicador incompleto | aceito (mesmo motivo) | idem |
| Foto ou categoria trocada DEPOIS que o produto foi ao Publicador não chega ao rascunho (D-05) | aviso na gaveta e categoria travada | 4ª entrega |
| "Ficha completa" na trilha ≠ "Pronto" no Publicador (`new_required`/`conditional_required`) | o texto da equipe não promete "para publicar" | 4ª entrega |
| A pendência na Lista é AVISO para a onda ("Na fila · com pendência na Lista"): planejar um item com o produto ainda na Lista leva-o ao Publicador na data, incompleto | comportamento da 2ª entrega, decisão de quem planeja | — |
| O filtro de sigilo da definição tira coisas legítimas também da equipe (unidade `ml`, rótulo com "Mercado") | mantido: uma definição só para gravação, Lista e robô | — |
| A modalidade de envio expira em 168 h e a régua do ME1 volta aos limites dos Correios | "Conferir frete pela conta" renova; a pergunta 3 decide se o ME1 segura | — |
| A trava lê os itens da empresa inteira a cada escrita (número fixo de consultas) | aceitável na 459 | medir se a trilha for ligada para loja grande |

---

## 12. O que fica para a 4ª entrega (e o caminho aberto)

**Fica para a 4ª:**
- a prévia do anúncio e o "publicar" na trilha;
- o preço e a precificação (com o bloqueio "Premium igual ao Clássico" do Portal);
- alinhar o rascunho DRAFT quando categoria ou fotos mudarem depois do 1º Sincronizar
  (`EditorRascunhoService::trocarCategoria` e recópia das fotos), o que é mudança no Publicador;
- os obrigatórios `new_required` e `conditional_required`;
- os textos do cliente sobre o agente e "em revisão no ML" (learnings §13).

**Entregas próprias, se a pergunta 4 mantiver fora:** planilha (Modelo Completo, com o "aplicar" em JSON e sem o 2º
eixo), fotos em lote (decidir antes o caso de dois eixos) e Importar do ML (decidir antes o título vazio e a IA da
ficha na hora).

**O caminho aberto:**
- a chave de painel na URL já comporta mais um painel (`v:{id}` para a prévia, como `e:` e `m:`);
- o editor do Publicador já abre na prévia (`ApresentacaoDaTrilha::editor`, `?etapa=previa`);
- `trilha.fotos?original=1` já serve a foto grande;
- a categoria nasce anunciável (sem o V-CAT-02 na publicação);
- com a resposta recomendada na pergunta 2, o rascunho nasce inteiro;
- o estoque é capturado;
- trava e versão já protegem a gaveta;
- publicação, quando vier: só na #459, nunca em conta de cliente.

---

## 13. Schema, operação e learnings

**Schema: nenhuma migration.**
- Tudo grava nas tabelas `estrutura_*` que existem desde 06–07/10 (o 2º eixo desde 28/10).
- "Veio da trilha" vai para o `activity_log` (`trilha_cadastro_salvo`, `via: trilha`). Uma coluna em
  `estrutura_produtos` seria ALTER em tabela com dado de cliente em produção, o que exige fase GSD pelo CLAUDE.md.
- A versão é hash calculado na hora: o `updated_at` do MariaDB tem resolução de segundo, e
  `EstruturaProdutoVolume` não tem timestamps.
- A trava é derivada do "andou".
- Apagar produto leva `trilha_onda_itens` e `trilha_mapeamentos` por cascata.

Se uma fatia futura precisar de schema, valem as três armadilhas do MariaDB que o SQLite não pega (learnings
`desempenho-bonificacao.md` §6):
- nome de índice com mais de 64 caracteres;
- `nullOnDelete` exige coluna `nullable`;
- dropar índice que uma FK usa.

Vale também o unique sem caixa e sem acento do `utf8mb4_unicode_ci`: os testes usam "Sofá" × "SOFA".

**Operação:**
- comentários em pt-BR;
- deploy só com autorização;
- depois do deploy, conferir por reconsulta ao banco, nunca pelo stdout;
- produção grava log só de `error`: o `[Trilha]` info não aparece (learnings §28).

**Learnings** (§ novo em `.planning/learnings/trilha-de-produtos.md`, na F5, e um item por fatia que descobrir algo):
- "vai ao Publicador ao completar" e o porquê: foto só no grupo vazio, categoria só no rascunho vazio, Sincronizar a
  cada save;
- "criar primeiro" e as ações imediatas;
- a trava (o "andou") e a versão (sem estoque e sem fotos, e por quê);
- `gravar` sem obrigatórios só pela trilha;
- a régua de fotos por cor (cópia de `comFotosDaCor`: mudou lá, muda aqui);
- o ME1 conforme a pergunta 3;
- "a trilha passou a chamar o ML (app token) no cadastro; a régua não" (o docblock de `CompletudeDoProduto` muda
  junto);
- abrir campos e termos guarda a definição;
- a regex do 413;
- a pasta `Cadastro/` lazy e o teste de fonte;
- a varredura recursiva;
- `RegrasDoCadastro` compartilhada com o Portal.

---

## 14. Perguntas ao usuário (só as que mudam o desenho)

**1. Produto que o robô já preparou, que está na revisão ou no ar (inclusive o que a 459 já tinha anunciado): a
equipe pode editar o cadastro pela trilha?**
- **Recomendado: só leitura nesta entrega.** A gaveta mostra o motivo e, para o admin, "Abrir no Publicador". O
  servidor recusa a escrita (409). Continua editável tudo o que ainda não andou: Lista, Mapeamento, Planejamento, e
  onda começada sem o robô ter mexido (com aviso).
- **Por quê:** salvar um produto que já andou muda os "fatos" dele. O robô refaz texto e imagens (IA paga), e o
  anúncio no ar não muda. Trocar o SKU ou excluir uma cor ainda desliga o anúncio (ele volta para a espera).
- **Se for "pode editar":** sai a trava (409 e modo leitura) e fica só o aviso de custo, como no Portal de hoje.

**2. O produto que a equipe está cadastrando na trilha só vai para o Publicador quando fica completo (sai da Lista)?**
- **Recomendado: sim.** Enquanto estiver na Lista, o Publicador não sabe dele. Ao completar, o 1º Sincronizar leva
  categoria, ficha e todas as fotos de uma vez. Daí em diante, cada gravação vai, como no Portal.
- **Por quê:** conferido no código, o Sincronizar roda a cada save, mas só aplica a categoria em rascunho que ainda
  não tem nenhuma e só copia foto para grupo vazio. O cadastro aos poucos, que a trilha incentiva, deixaria o rascunho
  preso na 1ª categoria escolhida e na 1ª foto enviada, sem conserto automático.
- **Se for "a cada gravação, como o Portal":**
  - a categoria não pode travar (a gaveta só avisa);
  - as fotos precisam subir todas de uma vez por cor;
  - a 4ª entrega terá de mudar o Publicador para recopiar foto e categoria no rascunho.

**3. Produto que só vai por transportadora (passa do limite do Mercado Envios: sofá, guarda-roupa) fica preso na
Lista?**
- **Recomendado: não. Na trilha, "frete ME1" vira AVISO, e o produto segue para o Mapeamento** com o selo "vai por
  ME1 (transportadora)". O botão "Conferir frete pela conta" entra de qualquer jeito (lê a conta da loja, só leitura)
  para tirar o ME1 falso.
- **Por quê:** hoje a falta "frete ME1" sai só das medidas da caixa. Um sofá de 216 × 98 × 60 cm soma 374 cm, e o
  maior limite do ME2 é 300 cm (Coleta, 50 kg). Ele ficaria na Lista para sempre, e nada que a equipe preencha resolve.
  O sistema já tem destino para o que passa do ME2 (ME1 da conta ou "Combine a entrega", desde 21/10).
- **Se for "fica preso":** a régua e o "Pronto quando" não mudam. Móvel grande não passa da etapa 1 da trilha.

**4. Além do cadastro de um produto por vez, o que entra já?**
- **Recomendado:**
  - "Preencher com IA" da ficha técnica: **sim**, como botão manual, igual ao do Portal. A ficha é preenchida pela IA
    só quando alguém clica, a cada clique, por uns 3 s de IA. Nada grava sem o "Salvar". O botão manual já é a regra
    aceita na 2ª entrega.
  - Planilha ("Pela planilha" do protótipo), fotos em lote e "Importar do Mercado Livre": **numa entrega própria,
    depois desta**.
- **Por quê:** a planilha não leva cor e tamanho juntos. O "Importar do ML" deixa o título vazio de propósito, o que
  segura o produto na Lista, e roda a IA da ficha na hora, fora da onda. Cada um pede tela e decisão próprias, e o
  cadastro unitário precisa estar validado antes.
- **Se for "a IA fica fora":** some a fatia F2b, e mais nada muda.

---

## 15. Respostas do usuário (02/11/2026, à tarde)

1. Produto que o robô já preparou ou que está no ar: **só leitura** na trilha (409 no servidor, "Abrir no
   Publicador" para o admin).
2. Vai ao Publicador **só quando completo** (sai da Lista) — decisão do desenho, sem objeção do usuário.
3. "Frete ME1" **vira aviso** na trilha e não segura o produto na Lista.
4. Entram NESTA entrega, além do cadastro um a um: **"Preencher com IA"** da ficha (botão manual), **planilha por
   categoria** e **importar do Mercado Livre**. Os dois últimos viram as fatias F7 e F8, depois da F6, cada uma com o
   desenho fechado antes do código (a planilha de hoje não leva cor e tamanho juntos; o import deixa o título vazio e
   roda a IA da ficha na hora).
5. **Cada fatia pode subir** quando testada, só na 459 e só para o Dev.
