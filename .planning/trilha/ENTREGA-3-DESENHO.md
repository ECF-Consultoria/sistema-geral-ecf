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
  id). Versão diferente → 409 "Este produto mudou depois que você abriu (outra pessoa salvou nele).
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

## 16. Mais decisões do usuário (03/11/2026)

- **A trilha não pode ter nada a ver com o Portal do cliente: ela o substitui.** Nenhum link, nota ou caminho da
  trilha leva ao Portal (a F5 foi antecipada para junto da F3). Por baixo, o produto segue nas mesmas tabelas que o
  Publicador lê (decisão de 02/11), o que não aparece na trilha.
- **O módulo da trilha vai para OUTRO sistema**, onde o cliente terá acesso e vai cadastrar e subir tudo pela
  trilha. Primeiro ele fica pronto aqui; depois é levado. Consequência para o código: a trilha tem de ser um
  subsistema independente — sem depender de telas, sessão ou contexto do Portal — e as peças do cadastro não podem
  assumir que só a equipe as usa (a porta do cliente vai ganhar o cadastro e o envio de fotos lá).
- **Fotos do cliente:** sobem pela trilha.
- **Ordem:** a planejada — F3 (fotos e frete) → F4 (cores e tamanhos) → F6 (versão) → F7 (planilha por categoria) →
  F8 (importar do ML) → 4ª entrega.
- **4ª entrega (escopo decidido):** a etapa 5 dentro da trilha — a prévia do anúncio (fotos + imagens geradas,
  títulos Clássico/Premium, preço, descrição, ficha), "Pedir ajuste ao robô" e "Aprovar e publicar" (fila do
  Publicador, só na #459); **aprovação do cliente ligável por loja** (publica pausado, o cliente aprova ou pede
  ajuste na trilha dele); **preço vindo da Precificação existente**, ajustável antes de publicar; e o envio de fotos
  pelo cliente na trilha.

## 17. F7 — Planilha por categoria (desenho fechado em 03/11/2026)

Pesquisa do que já existe: `ModeloCompleto` + `ImportadorModeloCompleto` (Portal), `PlanilhaDasFichasTecnicas`,
`ImportadorFichasTecnicas::ler`, `lib/modeloCompleto.js`. A "planilha direta" do Publicador NÃO está no HEAD
(`e87404dbb` só no branch `feat/publicador-planilha-261016`).

### 17.1 Respostas do usuário (03/11)
1. **Erro em alguns produtos:** grava os certos; o produto com erro fica **inteiro** de fora (nada dele grava, nem a
   ficha). A prévia mostra antes o que fica de fora e por quê.
2. **SKU que já existe:** atualiza só o preenchido (branco não apaga, nada é excluído, Ref nova no grupo = cor/tamanho
   novo). Recusa: produto travado (andou), em outra categoria, já no Publicador com categoria que mudaria (D12), ou
   que mudou depois da prévia (versão da F6).
3. **Fotos:** depois, pela gaveta de cada produto (fotos por cor). Sem fotos na planilha.
4. **Só o modelo vazio por categoria** (o que o protótipo promete). Sem "baixar meus produtos", sem aba "Sem
   categoria", sem apagar nem trocar SKU pela planilha (o SKU é a chave).

### 17.2 Decisões
| # | Decisão | Por quê |
|---|---|---|
| D20 | O arquivo sai do `ModeloCompleto` com um **perfil** aditivo (padrão = Portal, igual a hoje). Perfil da trilha: ficha por `definicao()` (SEM `definicaoComExplicacoes` — não enfileira IA ao baixar), 2º eixo, marca `trilha-planilha-v1`, instruções próprias (nenhuma cita Portal, Publicador, "Enviar fotos em lote" ou "Baixar modelo"), recusa categoria não folha OU não anunciável | uma só apresentação; D10 proíbe IA ao baixar |
| D21 | Variação em 4 colunas: "Tipo de variação", "Variação", "Tipo da 2ª variação", "2ª variação" → `eixo/valor/eixo_2/valor_2` (listas de `EstruturaProdutoVariacao::EIXOS`) | contrato da F4 |
| D22 | A importação NÃO passa por `ImportadorModeloCompleto`/`ImportadorProdutos` (o arquivo de passagem perde o 2º eixo e não conhece trava/anunciável/régua de cores). Serviço novo `App\Services\Trilha\PlanilhaDaTrilha` lê com `ImportadorFichasTecnicas::ler` e executa **cada produto como uma gaveta salva**: `CadastroNaTrilha::criar` (novo) → `gravar` (com `conferirLinhas`/`conferirCores`) → ficha por `FichaTecnicaDoProduto::gravarParcial` | reusa as regras já testadas da gaveta |
| D23 | Uma transação por produto. Campo da ficha inválido = **aviso** (só ele fica de fora, como no `gravarParcial`) | 1 erro de digitação em 60 colunas não barra o produto |
| D24 | **A prévia é o gravar desfeito:** as mesmas transações por produto, com rollback, sem `registrar` e sem `prepararSeCabe` | conferir o resultado, não simular (learnings §33) |
| D25 | Antes de qualquer transação: aquecer cache com `CategoriaSugestaoService::detalhes(ids das abas)` em lote e `definicao` por aba. Nenhuma chamada HTTP dentro da transação | `criar` prende `companies` com `lockForUpdate` |
| D26 | Efeitos só no gravar: um `trilha_cadastro_salvo` por produto (`passo: planilha`) e, no fim, UM `aoSalvar(soOsQueCabem(gravados))`. `criar`/`gravar` ganham parâmetro aditivo `efeitos` (padrão true) | não preparar N vezes |
| D27 | Sigilo da planilha mantido (`NAO_VAI`); nenhuma célula/instrução cita Portal, Publicador ou Mercado Livre | o arquivo vai para o cliente no outro sistema |

### 17.3 Rotas (grupo da equipe em `routes/trilha.php`; controller fino `Trilha\PlanilhaDaTrilhaController`)
Guarda: `CadastroNaTrilha::empresa()` (404 pelas chaves → 403 sem `podeEntrar`) ANTES da validação. Ator:
`AtorDoPortal::daEquipe`.

| Rota | Nome · throttle | Resposta |
|---|---|---|
| `GET /trilha/{company}/planilha/modelo?categorias[]=MLB…` (1 a 10) | `trilha.planilha.modelo` · 30/min | o `.xlsx` (download). 422 `errors.categorias` com o motivo da recusada |
| `POST /trilha/{company}/planilha/previa` (multipart `arquivo`) | `trilha.planilha.previa` · 10/min | 200 JSON da prévia; nada grava, nada enfileira |
| `POST /trilha/{company}/planilha` (multipart `arquivo` + `versoes[{produto_id}]=hash`) | `trilha.planilha.gravar` · 6/min | 200 JSON do resultado |

- Erro do ARQUIVO (não é .xlsx, > 2 MB, sem aba reconhecida da trilha, > 500 linhas de dados, marca de outro
  modelo): **422 `errors.arquivo`** com a frase. O 413 do servidor vira 422 `errors.arquivo` em
  `bootstrap/app.php` (ramo próprio para as rotas `trilha/{id}/planilha` e `trilha/{id}/planilha/previa`; o de
  fotos segue `errors.imagens`).
- `ApresentacaoDaTrilha::escrita` ganha `planilha: {modelo, previa, gravar}` (URLs), só na porta da equipe com escrita.

### 17.4 Contrato JSON (prévia e gravar têm a MESMA forma)
```json
{
  "gravado": false,
  "abas": [{"categoria_id": "MLB31447", "categoria_nome": "Camisetas", "linhas": 6}],
  "produtos": [{
    "chave": "CAM-01",
    "aba": "MLB31447",
    "linhas": [4, 5, 6],
    "produto_id": null,
    "nome": "Camiseta básica",
    "codigo": "CAM-01",
    "situacao": "novo | atualizado | sem_mudanca | recusado",
    "variacoes": {"novas": 6, "atualizadas": 0},
    "erros":  [{"linha": 5, "coluna": "Variação", "mensagem": "..."}],
    "avisos": [{"linha": 4, "coluna": "Material", "mensagem": "..."}],
    "faltas": ["fotos (0 de 3)"],
    "versao": null
  }],
  "totais": {"novos": 1, "atualizados": 0, "sem_mudanca": 0, "recusados": 0},
  "mensagem": "1 produto novo, nenhum recusado."
}
```
- `erros` não vazio ⇔ `situacao = recusado`. Erro do produto inteiro (travado, outra categoria, versão) vem com
  `linha` = 1ª linha do produto e `coluna` = null.
- `versao` = `VersaoDoCadastro::de` do existente (na prévia); a tela devolve em `versoes[produto_id]` no gravar.
  Diferente no gravar → aquele produto `recusado` com `MSG_VERSAO`; os outros gravam.
- `faltas` = as faltas da Lista do produto como ficaria (calculadas dentro da transação, antes do rollback na prévia).
- No gravar: `gravado: true`; a tela recarrega a Lista.

### 17.5 O arquivo
- Igual ao Modelo Completo (faixa linha 1, cabeçalho 2, ajuda 3, dados da 4; volumes recolhidos; seta de termos no
  Título; listas da ficha na aba oculta), mais as 4 colunas de variação e a marca `trilha-planilha-v1` na aba oculta
  "Categorias".
- "Grupo" junta as linhas do mesmo produto (cada cor ou cor+tamanho é uma linha). A ficha vale da 1ª linha do
  produto; o campo que é eixo do produto é ignorado para ele (como `doProduto`).
- Gerar recusa a categoria cuja aba passaria de 200 colunas (o leitor corta em silêncio).

### 17.6 Produto existente
Casa pela Ref (sem caixa, acento nem espaço — `chaveCodigo`), só na empresa. Refs de um grupo em produtos diferentes
→ grupo recusado. Régua de trava (`travasDoCadastro(itens())`) calculada UMA vez por arquivo. SKU/estoque travados
pelo Bling: ignorados com aviso (o serviço já faz).

### 17.7 Limites
2 MB, `.xlsx` (mimes + extensão); 10 categorias; **500 linhas de dados** (linha com dado além do corte do leitor =
recusa do arquivo, nunca corte em silêncio); 200 variações por produto (`MAX_GRADE`); 200 colunas. Síncrono — medir
o tempo de 500 linhas no teste de custo.

### 17.8 Chamadas ao ML e custo
Baixar: por categoria, `detalhes` (cache 7 d), `definicao` (cache 7 d) e termos do título (prazo total 20 s, para na
1ª falha). **Zero IA.** Prévia e gravar: só os caches (D25); nenhuma IA; o robô só roda se o produto completar
(sem fotos não completa) ou se já estiver no Publicador.

### 17.9 Tela
- `Cadastro/QuadrosDaLista.jsx` ganha o quadro "Pela planilha": "1. Escolha as categorias dos produtos. 2. Baixe a
  planilha já com os campos da ficha técnica de cada uma. 3. Preencha e suba de volta." + botão "Usar planilha".
- Gaveta `e:planilha` (só com escrita, como `e:novo`) em `Cadastro/PlanilhaPorCategoria.jsx`: escolher categorias
  (busca e árvore da F2, até 10, `lib/modeloCompleto.js`) → "Baixar planilha" → "Enviar planilha" → prévia por
  produto (novo/atualizado/sem mudança/recusado, erros por linha e coluna, avisos, faltas) → "Gravar N produtos
  (M ficam de fora)" → resultado → recarrega a Lista. Lógica pura em `lib/trilhaPlanilha.js`.
- O `lazy` novo vai DENTRO de `Pages/Trilha/CadastroSobDemanda.jsx`; conferir que `Empresa.jsx` e
  `CadastroSobDemanda.jsx` seguem no `manifest.json` (learnings §30).

### 17.10 Testes (no mesmo commit)
- `tests/Feature/Trilha/PlanilhaNaTrilhaTest.php`: baixar (aba por categoria, 4 colunas de variação,
  `Queue::assertNotPushed(GerarExplicacoesDeAtributosJob)`, nenhuma célula/instrução com "Mercado", "Portal" ou
  "/portal", não anunciável/não folha → 422, marca própria); prévia (contagens de `estrutura_*` e `activity_log`
  iguais antes e depois, `Queue::assertNothingPushed`, SKU repetido, cores inválidas, travado, outra categoria,
  versão velha); gravar (2 cores × 3 tamanhos com `eixo_2`, branco não apaga, obrigatório vazio grava, campo
  inválido = aviso, produto ruim não derruba os outros, novo sem foto NÃO vai ao Publicador, o que já está lá vai
  uma vez, evento `passo: planilha`); limites (413 → 422 `errors.arquivo`, não-xlsx, > 2 MB, > 500 linhas).
- `CadastroNaTrilhaAcessoTest`: as 3 rotas no `dataProvider`.
- Custo: `Http::preventStrayRequests` com cache quente; consultas por produto constantes.
- JS: `tests/js/trilha-planilha.test.js` + teste de fonte do `lazy`.
- Rede do Portal, arquivo por arquivo: `ModeloCompletoTest`, `ModeloEImportacaoTest`, `FichaTecnicaPelaPlanilhaTest`,
  `PlanilhaDeProdutosV2Test`, `TituloDeVendaTest`.

### 17.11 Achados do Portal (fora do escopo — só registrar no learnings)
- O Modelo Completo do Portal corta em silêncio acima da linha 1.001 (`ImportadorFichasTecnicas::ler` :434).
- O "Baixar modelo" do Portal enfileira `GerarExplicacoesDeAtributosJob` (`definicaoComExplicacoes`).

## 18. F8 — Importar do Mercado Livre (desenho fechado em 03/11/2026)

Pesquisa: o importador de 28/10 (`ImportadorDeAnuncioMl`, `TradutorAnuncioMlParaPortal`, `ProdutosDoMercadoLivreService`,
`ImportarAnuncioMlParaProdutoJob`, `JardinagemDaFicha`, `EstruturaProdutoOrigemMl`) funciona, mas só serve ao Portal
(`PortalContexto`, `MODO_IMPORTACAO` + `grupo` sem a régua das cores/anunciável/trava, textos "Portal"). Não confundir
com `AnunciosMercadoLivreService` + `ImportarAnunciosMlEstruturaJob` (cria oferta por SKU para o Mapeamento).

### 18.1 Respostas do usuário (03/11)
1. **Título de venda:** é NOSSO, não o do anúncio, e quem escreve é o robô — "pega os termos mais usados, o
   contexto do produto de acordo com a ficha, e as palavras que fazem sentido, e cria o título, assim como é no
   Publicador". A equipe pode ajustar depois na gaveta.
2. **IA da ficha:** roda na hora da importação (uma chamada paga por anúncio, fora da onda, grava sem revisão).
3. **Produto que já existe** (já importado, publicado, ligado, ou Ref igual): não mexe — "Já está na Lista ·
   Abrir", ou recusa dizendo de qual produto é o SKU.
4. **Fotos:** copiar do anúncio (tamanho cheio, até 12 por cor).

### 18.2 Decisões
| # | Decisão | Por quê |
|---|---|---|
| D28 | Serviço novo `App\Services\Trilha\ImportacaoDoMercadoLivre`. Cada anúncio executa como uma gaveta salva (padrão da F7): `criar(efeitos:false)` → `conferirLinhas` → `conferirCategoria` → `gravar(efeitos:false)` → `FichaTecnicaDoProduto::gravarParcial`, **numa transação**. Não usa `ImportadorDeAnuncioMl::importar` | o `MODO_IMPORTACAO` + `grupo` pula a régua das cores (F4), o anunciável e a trava |
| D29 | Reusa sem mudar: `TradutorAnuncioMlParaPortal::traduzir`, `ProdutosDoMercadoLivreService::candidatos` (o controller da trilha remapeia `no_portal` → `na_lista`), `AnunciosNoPortal::daEmpresa`. Extrai sem mudar comportamento (suítes do Portal = rede): baixar fotos → `FotosDoAnuncioMl` (só baixa); entradas da ficha → `TradutorAnuncioMlParaPortal::entradasDaFicha` (pura) | duas cópias de regra já custaram caro (D6) |
| D30 | Ordem: GET do anúncio, da descrição e das fotos (temporários) + aquecer `detalhe`/`definicao` da categoria ANTES; a transação só toca o banco (`criar` prende `companies`); fotos entram por cor (`VariacaoImagensService::enviar`, 1ª variação de cada cor) DEPOIS do commit; foto que falha = aviso | nenhum HTTP dentro da trava (learnings §30) |
| D31 | **Ficha pela IA na hora** (resposta 2): depois das fotos, o mesmo Job chama `JardinagemDaFicha::completar` (atributos do anúncio que ficaram de fora + `AnaliseAnuncioService::ficha`; só preenche vazio). Grava `origem.jardinagem` como o Portal grava | resposta 2; `jardinagem` nula deixaria o estado do Portal "em andamento" para sempre |
| D32 | **Título pelo robô** (resposta 1): depois da ficha, o mesmo Job monta o contexto do PRODUTO (nome, caminho da categoria, termos de `PalavrasChaveService::termosDaCategoria`, fatos da ficha gravada, marca, limite `LimiteDoTitulo`) e chama a MESMA IA do Publicador (`AnaliseAnuncioService::tituloPorTermos`/`titulosPorTermos` — o prompt do `gerarTitulos`), finaliza com as regras do Publicador (`finalizarTitulo`/`RegrasDoTitulo`/`TituloDoProduto::capitalizar`) e grava em `estrutura_produtos.titulo` (o "título de venda" da gaveta). `evitar` = o título do anúncio original (nunca devolver o mesmo). Falhou (IA fora, termos fora) → título fica vazio, aviso "Escreva o título na gaveta", e o produto fica na Lista por essa falta. Se precisar de método novo para um produto (o `contextoDoTitulo` de hoje recebe `PubRascunho`), é ADITIVO no `PalavrasChaveService` — o caminho do Publicador não muda | resposta 1: "assim como é no Publicador" |
| D33 | Categoria do anúncio: entra se folha e anunciável. Se não, o produto entra **sem categoria**, com aviso, sem ficha e sem título pelo robô (a gaveta pede a categoria). Definição da categoria fora do ar → o anúncio sai `erro` ("tente de novo") e nada grava | aqui a categoria não é escolha da equipe |
| D34 | Produto existente nunca é atualizado (resposta 3) | quem atualiza em massa é a F7 |
| D35 | Efeitos: grava a origem (`EstruturaProdutoOrigemMl`: selo, deduplicação, fatos para a IA), `registrar(passo: 'mercado_livre', {mlb})` e, no fim do Job, `prepararSeCabe` (na prática nada vai ao Publicador: custo, família e ambiente nunca vêm do ML) | — |
| D36 | Job `App\Jobs\Trilha\ImportarAnuncioNaTrilhaJob`: **fila da IA (`PreparoIaAgenda::fila()`)**, não a `high` (em prod a `high` tem 1 worker que também publica e manda código de acesso; 20 importações com IA a seguravam). `tries 1`, `timeout 600`. Ator refeito com `AtorDoPortal::daEquipe(User)`; sem a pessoa, o Job não roda. Estado em cache `trilha:ml:{lote,item}:…` por 6 h. Trava por anúncio com a MESMA chave do Portal (`estrutura:produtos-ml:importando:{empresa}:{mlb}`) | Portal e trilha não importam o mesmo MLB ao mesmo tempo |
| D37 | **Espera:** se algum SKU do anúncio tem linha em `estrutura_anuncio_espera` (SKU normalizado), o anúncio sai `recusado` com o motivo e nada grava | senão o produto nasce ligado ao anúncio original, "no ar" e travado (409) — `varrerEspera` no `ProdutoCadastroService` |
| D38 | Sem prévia separada: a lista já diz o que vai acontecer (`na_lista` como importado/publicado/ligado/**sku** — cruza o `skus` do acervo com as Refs da empresa, sem chamar o ML). Desfazer = "Excluir produto" da F4 | nada é sobrescrito |
| D39 | Sem diagnóstico ao vivo nesta fatia | listar não chama o ML |
| D40 | Textos: "Mercado Livre" só na porta da equipe, dentro do `lazy`. Nenhum "Portal" nem `/portal`. Moda que exige tabela de medidas leva o aviso "esta categoria ainda não publica pelo sistema" | regra de 03/11 |
| D41 | A gaveta do produto importado mostra "Título do anúncio original: …" (de `origem.retrato`), só como referência, na porta da equipe | a equipe compara o título do robô com o de antes |

### 18.3 Rotas (grupo da equipe em `routes/trilha.php`; controller fino `Trilha\MercadoLivreDaTrilhaController`)
Guarda `CadastroNaTrilha::empresa()` (404 chaves → 403) antes da validação.

| Rota | Nome · throttle |
|---|---|
| `GET /trilha/{company}/mercado-livre/anuncios?q=&ordem=&situacao=&pagina=&mostrar_na_lista=` | `trilha.ml.anuncios` · 120/min |
| `POST /trilha/{company}/mercado-livre/importar` (`mlbs[]`, 1 a 20) | `trilha.ml.importar` · 10/min |
| `GET /trilha/{company}/mercado-livre/estado` | `trilha.ml.estado` · 240/min |
| `POST /trilha/{company}/mercado-livre/limpar` | `trilha.ml.limpar` · 30/min |

422 `errors.mlbs`: nenhum, mais de 20, fora do acervo da empresa, conta desconectada (sem link).
`ApresentacaoDaTrilha::escrita` ganha `mercado_livre: {anuncios, importar, estado, limpar}`.

### 18.4 Contrato JSON
Lista:
```json
{"conectado": true, "atualizado_em": "2026-11-03T11:35:00-03:00", "total": 41, "pagina": 1, "paginas": 2, "limite": 20,
 "itens": [{"mlb": "MLB1", "titulo": "…", "thumbnail": "https://…", "permalink": "https://…", "preco": 199.9, "tipo": "Clássico",
   "ativo": true, "status": "Ativo", "catalogo": false, "estoque": 12, "vendas_total": 30, "visitas_30d": 410,
   "fotos": 8, "saude_nivel": "Média", "feito_pela_ecf": false,
   "na_lista": null, "importacao": null}]}
```
`na_lista`: null | `{produto_id, nome, como: importado|publicado|ligado|sku}`. `importacao`: null | `{situacao}`.

Estado (resposta do `importar`, do `estado` e do `limpar`):
```json
{"em_andamento": true, "totais": {"novos": 1, "na_lista": 0, "recusados": 0, "erros": 0},
 "itens": [{"mlb": "MLB1", "titulo_do_anuncio": "…", "situacao": "fila|importando|novo|na_lista|recusado|erro",
   "passo": "Lendo o anúncio|Gravando|Trazendo as fotos|Preenchendo a ficha|Escrevendo o título|null",
   "produto_id": 91, "nome": "…", "codigo": "POLT-01", "variacoes": 6,
   "fotos": {"trazidas": 9, "falharam": 1}, "ficha": {"gravados": 14, "recusados": 2, "pela_ia": 5},
   "titulo": "Poltrona Decorativa Estofada Linho Pés Palito", "avisos": ["…"],
   "faltas": ["custo", "família", "ambiente"], "mensagem": null}]}
```
- `titulo_do_anuncio` = título do anúncio do ML (sempre); `titulo` = o título de venda escrito pelo robô (null até
  ser escrito). Na LISTA, `titulo` é o do anúncio. `limite` da lista = teto por clique (20); página de 30.
- `GET estado` ao abrir devolve o último lote do cache (6 h), mesmo terminado, ou `{em_andamento: false, itens: []}`.
- `recusado` e `erro`: nada foi gravado. `novo` com foto/IA que falhou: o produto fica, com aviso.
- `situacao` só vira `novo` no FIM do Job (depois do título); enquanto isso é `importando` com o `passo`.
- `faltas` de `TrilhaDaEmpresa::faltasDoProduto`, calculadas no fim.

### 18.5 Tela
- `Cadastro/QuadrosDaLista.jsx` ganha o quadro "Do Mercado Livre": "Traga os anúncios que a loja já tem. Cada um vira
  um produto na Lista, com variações, fotos, caixa, ficha e título. O custo, a família e o ambiente ficam para a
  equipe." Botão "Escolher anúncios".
- Gaveta `e:ml` em `Cadastro/ImportarDoMercadoLivre.jsx`, `lazy` dentro de `CadastroSobDemanda.jsx` (conferir o
  manifest — learnings §30): busca, situação, ordem, "Mostrar os que já estão na Lista"; marca até 20; "Importar N".
  Pergunta o estado a cada 2 s; cada linha mostra o passo, depois "novo" com o título escrito, as faltas e [Abrir]
  (`e:{id}`), ou o motivo. Ao terminar, recarrega a Lista. Lógica pura em `lib/trilhaImportarMl.js` (reusa rótulos
  de `lib/produtosDoMl.js`, menos o `textoNoPortal`).
- Gaveta do produto (`e:{id}`): "Título do anúncio original: …" quando o produto veio do ML (D41).

### 18.6 Limites
20 anúncios por clique; 30 por página; 12 fotos por variação, 10 MB por foto; 200 variações (`MAX_GRADE`) — acima,
`recusado`; Job até 600 s.

### 18.7 Chamadas ao ML e custo
Listar: zero (acervo). Por anúncio: 1 GET do item (`include_attributes=all`) + 1 da descrição; fotos da CDN sem
token (até 12 por cor, 20 s, 2 tentativas); `detalhe`/`definicao` da categoria do cache de 7 d; termos do título
(`/trends`, cache do serviço). **IA: 2 chamadas pagas por anúncio** (ficha ~6000 tokens + título ~2500). Zero
escrita no ML.

### 18.8 Testes (no mesmo commit)
- `tests/Feature/Trilha/ImportarDoMlNaTrilhaTest.php` (`Http::fake` nos moldes do `TrazerDoMercadoLivreTest`, IA
  falsa): anúncio simples vira produto com título do robô e faltas = [custo, família, ambiente]; cor×tamanho grava
  `eixo_2`, fotos só na 1ª variação de cada cor; sem SKU → `MLB-n`; o título nunca é o do anúncio; IA do título
  fora → título vazio + aviso; IA da ficha preenche só vazio; Job na fila da IA (não `high`); evento `passo:
  mercado_livre`; origem com `jardinagem` não nula; reimportar → `na_lista`; Ref existente → recusado com contagens
  das `estrutura_*` iguais; categoria não anunciável → sem categoria, sem título, com aviso; definição fora → erro,
  nada gravado; foto que falha → aviso; outra conta (`seller_id`) → erro; fora do acervo → 422; espera → recusado;
  **o `Http::fake` falha se `DB::transactionLevel() > 0`** (prova da D30); nenhum "Portal"/"/portal" no JSON.
- `CadastroNaTrilhaAcessoTest`: as 4 rotas no `dataProvider`.
- Custo: consultas constantes por anúncio.
- JS: `tests/js/trilha-importar-ml.test.js` + teste de fonte do `lazy`.
- Rede do Portal (arquivo por arquivo): `TrazerDoMercadoLivreTest`, `ImportarDoMlPublicadoPeloPortalTest`,
  `TradutorAnuncioMlParaPortalTest`, `FichaTecnicaPelaIaTest`, e os testes do `PalavrasChaveService`/título do
  Publicador se ele mudar.

### 18.9 Riscos
- R1 espera (D37). R2 fila da IA mais cheia (20 anúncios × 2 chamadas) — medir na 459. R3 kit/combo vira produto
  simples. R4 moda importa mas não publica (aviso). R5 quando publicado, o anúncio original e o novo ficam os dois no
  ar — só a 459. R6 acervo é diário (11:35). R7 duas portas (Portal da 459 também importa): trava e origem
  compartilhadas evitam produto em dobro.

## 19. 4ª entrega — a revisão e a publicação dentro da trilha (desenho fechado em 03/11/2026)

Pesquisa (03/11, conferir as linhas antes do código): a prévia do anúncio é `App\Services\Publicador\PreviaDoAnuncioService::montar(PubRascunho)` (não grava, não chama IA; com cache frio faz GET público do schema e `shipping_preferences`). `PreviaDaFaseService` é do kit — não serve. A rota da prévia do Publicador é `role:admin` e marca `EditorEmUso` (adia robô e fila). "Pedir ajuste ao robô" e "publicar pausado" NÃO existem. Não existe editar título/descrição de anúncio publicado (só fotos). O preço da Precificação é derivado na hora (`EstruturaPrecificacaoService::pagina`), o Publicador aplica onde `pub_variante_precos` é nulo. O bloqueio "Premium igual ao Clássico" existe só no front do Portal (`precificacaoSimulador.js::premiumIgualAoClassico`). **`ContasLiberadas` NÃO protege a #459** (`publicador.contas_liberadas.todas` ligado em prod desde 26/10).

### 19.1 Respostas do usuário (03/11)
1. **Aprovação do cliente: ANTES de publicar** (substitui o "publica pausado" de §16 — pausar/reativar/editar publicado não existe). [Mandar ao cliente] → ele aprova a PRÉVIA e só então o anúncio entra na fila de publicação da #459; pedido de ajuste volta para a equipe.
2. **Pedir ajuste ao robô:** escolher o que refazer (título — com o Modelo — e/ou descrição), sem texto livre, sem imagens.
3. **Preço:** a equipe ajusta na prévia, grava SÓ no rascunho do anúncio, com [Voltar ao da Precificação]; o cliente só vê. Premium igual ao Clássico bloqueado no servidor.
4. **Fotos do cliente:** só enquanto o produto está na Lista; depois, só leitura ("fale com a equipe").

Padrões assumidos (sem pergunta; o usuário pode mudar): a aprovação por loja liga pelo `.env` (D53); o cliente só vê a prévia quando o item está com ele; no modo equipe do Portal a ECF pode aprovar pelo cliente (grava `decidido_por_equipe`); texto do anúncio recém-publicado em revisão no ML: "Em análise", sem alerta nas primeiras 24 h (D59).

### 19.2 Fatias, na ordem
| Fatia | Entrega |
|---|---|
| **4a** Prévia só leitura (equipe) | painel `v:{item}` com fotos (as geradas marcadas), títulos por tipo, preço, descrição e ficha |
| **4c** Preço ajustável | preço da Precificação × ajustado, gravado no rascunho; Premium = Clássico bloqueado no servidor |
| **4d** Aprovar e publicar na #459 | conferir no ML e entrar na fila de publicação; acompanhamento; "Em análise" |
| **4b** Pedir ajuste ao robô | refazer título e/ou descrição pelo próprio preparo |
| **4f** Fotos enviadas pelo cliente | 1ª escrita na porta do cliente (produto na Lista) |
| **4e** Aprovação do cliente por loja | mandar ao cliente; ele aprova (entra na fila) ou pede ajuste (texto para a equipe) |
Cada fatia sobe sozinha, só na 459, só para o Dev.

### 19.3 Decisões
| # | Decisão |
|---|---|
| D42 | Serviço novo `App\Services\Trilha\PreviaNaTrilha`: acha o item pela régua única (`TrilhaDaEmpresa::itens()` → `pub_produto_id`), chama `PreviaDoAnuncioService::montar` **sem mudá-lo** e trabalha a saída: troca as URLs das fotos pela rota da trilha; marca `gerada` por foto (cópia da régua de `CriativosAutomaticosService::grupos`: `acervo_imagens.origem='ia'` ou `ml_anuncio_criativos.pub_imagem_id` — registrar no learnings "mudou lá, muda aqui"; NÃO usar o `de_ia` da Aprovação); reescreve `motivo` em texto neutro (sem "etapa Produto/Condições/Detalhes"). Nunca passa pela rota do Publicador (não marca `EditorEmUso`). |
| D43 | Painel `v:{id do item}` (`v:s-12`, `v:c-12-2`, `v:o-55`), aberto pelo "Revisar" da linha em revisão; o `o:` ganha [Ver prévia]. O cartão do anúncio é componente próprio, sem "Mercado Livre", com lib pura `lib/trilhaPrevia.js` (NÃO reusar `AnuncioNoMl.jsx` — classes `pub-*` — nem `Components/Publicador/previa.js` — cita o ML). As peças da equipe ficam em `Components/Trilha/Revisao/`, por `lazy` dentro de `CadastroSobDemanda.jsx` (conferir o manifest — learnings §30). |
| D44 | `v:` só para item na etapa `revisao` (e depois: publicado, para conferência). Antes: "o robô ainda prepara". |
| D45 | (4c) Preço grava no rascunho por `EditorRascunhoService::salvarVariantes`, por tipo, igual em todas as cores ou por cor. Nulo = voltar ao da Precificação. Nunca escreve na Precificação. |
| D46 | (4c) Premium = Clássico é regra do SERVIDOR da trilha (diferença < 0,005 sobre o preço efetivo de cada cor), no gravar do preço e no publicar. |
| D47 | Toda escrita da 4ª entrega confere a `revisao` do rascunho: 409 SEM `travado` (padrão F6). 409 também com o item na fila (`NaFilaDePublicacao`), publicando ou publicado. |
| D48 | (4d) **Guarda da #459 NO CÓDIGO:** `PublicacaoNaTrilha::LOJA = 459` (constante, não env), conferida na Company do endereço, em `pub_produtos.company_id` e na âncora da conta (Company 459, ou MlbEmpresa com `company_id` 459) antes de conferir e antes de agendar. Teste com a 460 pronta → 403 e nada na fila. |
| D49 | (4d) Publicar = Job (fila `high`) que roda `ConferenciaService::conferir` e, OK (ou avisos com `ciente`), `FilaPublicacaoService::agendar(resolver('company-459'), [pub], User)`. Não usa o portão de aprovação nem o `iniciar` direto. |
| D50 | (4d) Avisos da conferência: a tela lista e pede [Publicar assim mesmo] (`ciente`). |
| D51 | (4b) `AjusteDoRoboNaTrilha` + Job na fila da IA: gera com `PalavrasChaveService::gerarTitulos` (`evitar` = títulos atuais) e `DescricaoIaService::gerar`, grava por `PreparoIaDoRascunhoService::executarEtapa(..., $valorPronto)` (só campo da IA; adia com editor em uso ou na fila). Respeita a cota diária. Teto 3 ajustes por item por dia. |
| D52 | (4e) O pedido de ajuste do CLIENTE é texto para a equipe; nunca vai ao prompt. |
| D53 | (4e) Chave `trilha.aprovacao_cliente.empresas` (env `TRILHA_APROVACAO_CLIENTE_EMPRESAS`, padrão vazio), cruzada com `TrilhaDeTrabalho`. Com ela, o primário vira [Mandar ao cliente]. |
| D54 | (4e) Estado da aprovação na tabela NOVA `trilha_aprovacoes` (19.6); histórico no `activity_log`. |
| D55 | (4e) O cliente aprova a `revisao_enviada`; se a equipe mexeu depois, 409 e volta para ela. |
| D56 | (4e) Aprovado pelo cliente → agenda com o `User` que mandou (`enviado_por`); inativo → "Aprovado pelo cliente · falta publicar" para a equipe. |
| D57 | Porta do cliente: controllers finos em `Trilha\Cliente\*` (leem `PortalContexto`) que chamam os MESMOS serviços com `Company` + `AtorDoPortal::cliente`. Só essa camada troca quando a trilha for para o outro sistema. |
| D58 | (4f) Fotos do cliente: `CadastroNaTrilha::enviarFotos/excluirFoto` com ator cliente, atrás de guarda nova `empresaDoCliente(Company)`; só produto na Lista; depois 409. |
| D59 | Textos do cliente nunca dizem "Mercado Livre", "Publicador", "Portal" nem "agente". Recém-publicado em revisão no ML: "Em análise", sem alerta nas primeiras 24 h (mexe em `noAr`/contadores — as telas mudam juntas). |
| D60 | (4b) Enquanto o ajuste roda: "Robô refazendo · título, descrição" na revisão (marca em cache por pub), sem voltar à etapa 4. |

### 19.4 Rotas
`item` casa `s-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}`. Guarda: 404 chaves → 403 `podeEntrar` → 404 item (antes da validação) → 409 → 422.

Equipe (`routes/trilha.php`):
| Rota | Nome · throttle | Fatia |
|---|---|---|
| `GET /trilha/{company}/ofertas/{item}/previa` | `trilha.previa` · 120/min | 4a |
| `GET /trilha/{company}/ofertas/{item}/fotos/{imagem}` (só `pub_imagens` daquele rascunho; `ArquivosDeImagem::resposta`; cache `private`) | `trilha.previa.foto` · 600/min | 4a |
| `PUT /trilha/{company}/ofertas/{item}/preco` | `trilha.preco` · 30/min | 4c |
| `POST /trilha/{company}/ofertas/{item}/publicar` | `trilha.publicar` · 10/min | 4d |
| `GET /trilha/{company}/ofertas/{item}/publicacao` | `trilha.publicacao` · 240/min | 4d |
| `POST /trilha/{company}/ofertas/{item}/ajuste` | `trilha.ajuste` · 10/min | 4b |
| `POST /trilha/{company}/ofertas/{item}/cliente` | `trilha.cliente` · 10/min | 4e |

Cliente (grupo `portal.auth`, prefixo `portal/{portalEmpresa}`; cada rota com linha em `RestringeDominioDoPortal::PERMITIDO_COM_ID`): 4f — fotos (GET/POST/DELETE); 4e — prévia, foto, aprovar, ajuste.
`ApresentacaoDaTrilha::escrita` ganha `revisao: {previa, ...}` (URLs relativas; cada fatia acrescenta a sua). Na 4a: `revisao: {previa}` — a URL da prévia com `{item}` como marcador (ex.: `/trilha/459/ofertas/__ITEM__/previa`) para a tela trocar.

### 19.5 Contrato JSON da prévia (4a; as outras fatias acrescentam)
```json
{"item": {"id": "s-12", "nome": "Rack 1,80 m", "tipo": "Simples", "etapa": "revisao", "status": "Pré-pronto · esperando a revisão"},
 "pronta": true, "motivo": null, "revisao": 37,
 "categoria": {"caminho": ["Casa, Móveis e Decoração", "Sala", "Racks"]},
 "tipos": [{"chave": "classico", "nome": "Clássico"}, {"chave": "premium", "nome": "Premium"}],
 "variantes": [{"chave": "v1", "rotulo": "Preto"}],
 "anuncios": [{"tipo": "classico", "variante": "v1", "titulo": "…", "preco": 899.9, "promocao": null, "parcelas": null,
   "fotos": [{"id": "881", "url": "/trilha/459/ofertas/s-12/fotos/881", "gerada": false}],
   "caracteristicas": [{"grupo": "Principais", "itens": [{"nome": "Material", "valor": "MDF"}]}],
   "envio": {"modo": "…", "aviso": null}}],
 "descricao": "…",
 "preco": {"classico": {"valor": 899.9, "da_precificacao": 879.9, "ajustado": true},
           "premium": {"valor": 989.9, "da_precificacao": 989.9, "ajustado": false}},
 "preparo": {"titulo": "ok", "descricao": "ok", "imagens": "ok"},
 "publicacao": null, "aprovacao": null, "bloqueios": []}
```
- Sem rascunho ainda / não pronta: `pronta: false`, `motivo` neutro ("O robô ainda está preparando este anúncio."), `anuncios: []`.
- Na porta do cliente (4e) não saem `preparo`, `bloqueios` nem `preco.*.da_precificacao`.
- `publicacao` (4d) e `aprovacao` (4e) ficam `null` na 4a.

### 19.6 Schema (só a 4e) — tabela NOVA, nenhuma ALTER
```
trilha_aprovacoes
  id
  company_id            ubigint  FK companies cascadeOnDelete      (tra_company_fk / tra_company_ix)
  pub_produto_id        ubigint  FK pub_produtos cascadeOnDelete   unique tra_pub_uq (1 linha viva por anúncio)
  item_chave            string(40)                                  ('s-12' | 'c-12-2' | 'o-55'; diagnóstico)
  estado                string(20)                                  com_cliente | ajuste | aprovado (string, não ENUM)
  rodada                usmallint default 1
  revisao_enviada       uint                                        (pub_rascunhos.revisao no envio)
  enviado_em            datetime
  enviado_por           ubigint NULL FK users nullOnDelete          (tra_enviado_fk / tra_enviado_ix)
  decidido_em           datetime NULL
  decidido_por_cliente  ubigint NULL FK portal_usuarios nullOnDelete (tra_cliente_fk / tra_cliente_ix)
  decidido_por_equipe   ubigint NULL FK users nullOnDelete          (tra_equipe_fk / tra_equipe_ix)
  pedido                text NULL                                   (texto do "pedir ajuste" do cliente, ≤ 1000)
  timestamps
```
MariaDB (learnings de desempenho §6): nomes de índice curtos; toda coluna `nullOnDelete` é nullable; o `down` dropa as FKs antes da tabela.

### 19.7 Tela (4a)
- O "Revisar" da linha em revisão abre `v:{item}`; o painel da oferta (`o:`) ganha [Ver prévia].
- Corpo: "Onde está"; **Anúncio** — galeria (as geradas com selo "gerada pelo robô", cor própria), abas Clássico/Premium e cor, título, preço (com parcelas no Premium), descrição, ficha agrupada; **Preço** (só leitura na 4a: valor, e "ajustado" quando difere da Precificação); "Situação".
- Rodapé: "Abrir no Publicador" continua só para admin (o link que já existe).
- Texto neutro: nada de "Mercado Livre" no pacote que o cliente baixa (o cartão fica dentro do `lazy` da equipe).

### 19.8 Custo
4a: zero IA; ML só com cache frio (schema público e `shipping_preferences`).

### 19.9 Testes (4a)
`tests/Feature/Trilha/PreviaNaTrilhaTest.php`: itens s-, c- e o-; outro item ou outra loja → 404; sem rascunho → `motivo` neutro; URL da foto da trilha e `gerada` certo (origem `ia` e criativo); nenhum `/portal`, `mlb/anuncios`, "Mercado Livre" nem "etapa Produto/Condições/Detalhes" no JSON; `EditorEmUso` NÃO marcado; `Http::preventStrayRequests` com cache quente e consultas constantes; a rota da foto recusa `pub_imagens` de outro rascunho e de outra empresa; a matriz de acesso no dataProvider do `CadastroNaTrilhaAcessoTest`. JS: `tests/js/trilha-previa.test.js` + teste de fonte do `lazy`.

### 19.10 Riscos
R1 publicar fora da #459 (D48 + teste da 460). R2 o editor/lote do Publicador ainda publica o mesmo produto por fora (aceito na 459). R3 todo anúncio novo nasce `under_review` (D59). R4 ajustar preço invalida a conferência (o publicar sempre reconfere). R5 escrever no rascunho com o item na fila o tira como `precisa_revisar` (D47). R6 promoção automática agendada na publicação (a prévia mostra `promocao`). R7 régua do `gerada` é cópia. R8 D-05: foto/categoria trocada depois do 1º Sincronizar não chega ao rascunho. R9 1ª escrita na porta do cliente (allowlist, CSRF, 413, varredura de props). R10 agente da Aprovação, se ligado na 459, cria `PubAprovacaoOferta` em paralelo. R11 fila da IA mais cheia com ajustes.

Achados fora do escopo: o "Salvar preço" do Painel de Aprovação não grava o preço (`AprovacaoDaOfertaService::registrarPrecoSalvo`); o aviso `preco_alterado` não tem escritor não nulo em `app/`.

### 19.11 4c — preço ajustável (contrato)
`PUT /trilha/{company}/ofertas/{item}/preco` (`trilha.preco`, 30/min). URL em `escrita.revisao.preco` (com `__ITEM__`).

Pedido:
```json
{"revisao": 37, "precos": {"classico": 899.9, "premium": null}, "cor": null}
```
- `precos.{classico|premium}`: número > 0 com até 2 casas, ou `null` = **voltar ao da Precificação** naquele tipo.
  Tipo ausente no pedido = não mexe. Tipo desligado no anúncio = 422.
- `cor`: `null` = todas as cores; ou a chave da variante (a mesma de `variantes[].chave`) = só aquela cor.
- Grava por `EditorRascunhoService::salvarVariantes` (D45) em `pub_variante_precos`; nunca escreve na Precificação.

Respostas:
- 200 → a prévia nova (forma §19.5), com `preco` ampliado:
  `preco.{classico|premium} = {valor, da_precificacao, minimo, ajustado}` (da 1ª cor ativa) e
  `preco.por_cor: bool` (alguma cor com preço diferente das outras no mesmo tipo), `preco.premium_igual: bool`.
  O preço de cada cor segue em `anuncios[].preco`.
- 422 `errors["precos.classico"]` / `errors["precos.premium"]`: valor inválido; **"O Premium não pode ter o mesmo
  preço do Clássico."** (D46: diferença < 0,005 sobre o preço EFETIVO de cada cor, depois de aplicar o pedido);
  tipo desligado.
- 409 SEM `travado` (padrão F6/D47): `revisao` diferente da atual → `{revisao_atual, message: "Este anúncio mudou
  depois que você abriu. Recarregue para ver o que mudou."}`; item na fila, publicando ou publicado →
  `{message}` neutro. Item fora da revisão → 409 `{message}`.
- Abaixo do `minimo` da Precificação: **aviso** na resposta (`avisos: ["Abaixo do mínimo da Precificação (R$ …)."]`),
  não bloqueia — a equipe decide.
- Efeitos: sobe a `revisao` do rascunho (a conferência anterior perde a validade — R4); registra no `activity_log`
  (`trilha_preco_ajustado`, com tipo, cor, de → para, ator da equipe). Zero IA, zero ML.

Tela (equipe): a caixa **Preço** da prévia vira editável — por tipo, um campo com o valor atual e, embaixo, "da
Precificação: R$ X · mínimo R$ Y"; "Todas as cores" × uma cor (seletor com as `variantes`); [Salvar preço];
[Voltar ao da Precificação] por tipo (manda `null`); aviso na hora quando Premium = Clássico (espelho da regra; o
servidor decide); 409 de revisão → "Este anúncio mudou depois que você abriu" + [Recarregar]; 409 de fila/publicado
→ a caixa fica só leitura com a mensagem. O cliente nunca edita (a porta do cliente nem recebe a URL).

Testes 4c: `tests/Feature/Trilha/PrecoNaTrilhaTest.php` — grava em `pub_variante_precos` (todas as cores e uma cor);
`null` volta a herdar; Premium igual → 422 (inclusive quando a igualdade nasce de combinar o pedido com o preço já
gravado do outro tipo); tipo desligado → 422; revisão velha → 409 sem `travado`; na fila/publicando/publicado → 409;
fora da revisão → 409; contagem de `estrutura_precificacoes` (e tabelas da Precificação) igual antes e depois;
`activity_log` com o evento; abaixo do mínimo = aviso; nenhum HTTP e nenhum Job; matriz de acesso no dataProvider.
JS: `tests/js/trilha-preco.test.js` (lógica pura + chamada + render da caixa editável e dos 409/422).

### 19.12 Respostas da 2ª rodada (04/11)
- **4d — quem publica:** quem já edita a trilha da loja (a mesma escrita da porta da equipe; hoje só o Dev na 459).
  A guarda da #459 no código (D48) vale por cima.
- **4d — avisos da conferência:** mostra e pede confirmação ([Publicar assim mesmo] = `ciente`); erro sempre bloqueia.
- **4b — limite:** 3 pedidos de ajuste por anúncio por dia (a cota diária da loja vale por cima).
- **4f — fotos do cliente:** enviar E excluir, enquanto o produto está na Lista.
- Ritmo: o usuário manteve o rigor (testes completos e provas de mutação em todas as fatias); a bateria agora roda
  em paralelo (`scratchpad/bateria.sh`, 4 processos, `TEST_TOKEN` por arquivo).

### 19.13 4d — aprovar e publicar na #459 (contrato)
Rotas (equipe; URLs em `escrita.revisao.publicar` e `escrita.revisao.publicacao`, com `__ITEM__`, só para quem escreve):
- `POST /trilha/{company}/ofertas/{item}/publicar` (`trilha.publicar`, 10/min; contador `trilha.revisao.publicar`)
  — corpo `{revisao, ciente}` → **202** `{publicacao: {situacao: "conferindo", mensagem, avisos: [], mlbs: []}}`.
- `GET /trilha/{company}/ofertas/{item}/publicacao` (`trilha.publicacao`, 240/min; contador `trilha.revisao.publicacao`)
  → 200 `{publicacao: {...}}` (ou `{publicacao: null}` se nunca pediu).

Guarda, nesta ordem: 404 chaves → 403 `podeEntrar` → 404 item → **403 fora da #459** (D48: `PublicacaoNaTrilha::LOJA =
459` constante; confere a Company do endereço, `pub_produtos.company_id` e a âncora da conta — Company 459 ou
MlbEmpresa com `company_id` 459 — com a mensagem neutra "Publicar pela trilha ainda não está liberado para esta
loja.") → 409 (revisão velha SEM `travado`, com `revisao_atual`; item fora da revisão; na fila/publicando/publicado;
prévia não pronta) → 422 (Premium = Clássico, D46 — no publicar confere TODAS as cores ativas, não só as tocadas como
na 4c).

Job `App\Jobs\Trilha\PublicarPelaTrilhaJob` (fila `high`, `tries 1`): refaz a guarda D48 (o Job não confia no
controller) → `ConferenciaService::conferir($rascunho)` → erros = `recusado` (mensagem + `avisos` com os erros em
texto neutro) → avisos sem `ciente` = `avisos` (lista) → senão `FilaPublicacaoService::agendar(
ProgramasPublicadorService::resolver('company-459'), [$pubId], $user, ['ciente' => $ciente])` → `na_fila`. NUNCA usa o
portão de aprovação nem `PublicacaoService::iniciar` direto (D49). O estado do pedido fica em cache
`trilha:publicar:{pub}` por 6 h; depois de `na_fila`, a situação sai da fila/publicação de verdade
(`PubFilaPublicacaoItem` e `pub_publicacoes`): `na_fila` → `publicando` → `publicado` (com `mlbs`) | `erro`.

`publicacao.situacao` ∈ `conferindo | avisos | na_fila | publicando | publicado | recusado | erro`. A prévia (§19.5)
passa a trazer `publicacao` preenchida pelo mesmo cálculo.

D59 (nesta fatia): anúncio recém-publicado ainda `under_review` no ML aparece como **"Em análise"**, sem alerta, nas
primeiras 24 h (em `SituacaoDasOfertas::noAr` e onde os contadores "no ar" leem — as telas mudam juntas); depois
de 24 h volta à regra de hoje.

Tela (equipe, na gaveta `v:`): rodapé primário **[Aprovar e publicar]** quando a prévia está pronta, na revisão, sem
`bloqueios` e com `escrita.revisao.publicar`; confirmação no rodapé ("Publicar este anúncio na loja agora?");
depois, pergunta o `GET publicacao` a cada 3 s enquanto `conferindo | na_fila | publicando`; `avisos` → lista +
[Publicar assim mesmo] (POST de novo com `ciente: true` e a `revisao` atual); `recusado`/`erro` → mensagem; `publicado`
→ "Publicado · em análise" e recarrega a Lista. O link "Abrir no Publicador" (admin) continua.

Custo: zero IA. ML: conferência (sobe fotos + `POST /items/validate`) e publicação (`POST /items` por tipo × cor +
descrição + `GET`) — **só na 459**.

Testes 4d: `tests/Feature/Trilha/PublicarNaTrilhaTest.php` — **empresa 460 com tudo pronto → 403 e
`Queue::assertNothingPushed`**; pub da 459 com âncora de outra empresa → recusa (também dentro do Job); `Http::fake`
no validate → item na fila (`PubFilaPublicacaoItem`); avisos sem `ciente` → `avisos`; com `ciente` → na fila;
erro de conferência → `recusado`, nada na fila; revisão velha → 409 sem `travado`; fora da revisão / na fila /
publicado → 409; Premium igual → 422; **nenhum `POST /items` disparado pela trilha** (só a fila publica); a situação
segue a fila (`na_fila` → `publicado` com `mlbs`); "Em análise" nas primeiras 24 h e a regra antiga depois; matriz de
acesso. Rede: suítes da fila e da publicação do Publicador (`PUBLICADOR_PREPARO_IA_ATIVO=true`, `--colors=never`).
JS: `tests/js/trilha-publicar.test.js`.

### 19.14 4b — pedir ajuste ao robô (contrato)
Rotas (equipe; URL em `escrita.revisao.ajuste`, com `__ITEM__`, só para quem escreve):
- `POST /trilha/{company}/ofertas/{item}/ajuste` (`trilha.ajuste`, 10/min; contador `trilha.revisao.ajuste`) — corpo
  `{revisao, refazer: ["titulo", "descricao"]}` (1 ou 2 partes; SEM texto livre — qualquer outra chave é ignorada)
  → **202** `{ajuste: {...}}`.
- `GET /trilha/{company}/ofertas/{item}/ajuste` (`trilha.ajuste.estado`, 240/min; contador `trilha.revisao.ajuste.estado`)
  → 200 `{ajuste: {...}}` ou `{ajuste: null}`.

`ajuste = {situacao: fila|fazendo|feito|erro, partes: {titulo?: {situacao: refeito|mantido|erro|fila|fazendo,
mensagem}, descricao?: {...}}, restantes_hoje: n, mensagem}`. `mantido` = a equipe editou aquela parte à mão (o robô
só reescreve campo que ainda é dele — `step_state.ia_escrito`), com mensagem "A equipe editou o título; o robô não
mexe nele." A prévia (§19.5) passa a trazer `ajuste` (mesma forma e mesmo cálculo do GET) e `ajustes_restantes_hoje`.

Guarda: 404 chaves → 403 → 404 item → 409 (revisão velha SEM `travado`, com `revisao_atual`; fora da revisão; na
fila/publicando/publicado; prévia não pronta; ajuste já em andamento) → 422 (`refazer` vazio ou com parte
desconhecida em `errors.refazer`; **limite de 3 ajustes por anúncio por dia** → `errors.refazer` "Este anúncio já teve
3 ajustes hoje. Amanhã libera de novo.").

Job `App\Jobs\Trilha\AjustarPeloRoboNaTrilhaJob` na fila da IA (`PreparoIaAgenda::fila()`): para cada parte, gera
com os geradores do preparo — título: `PalavrasChaveService::gerarTitulos($r, $prazo, $evitar)` com `evitar` = os
títulos atuais dos dois tipos; descrição: `DescricaoIaService::gerar` — e grava por
`PreparoIaDoRascunhoService::executarEtapa(rascunhoId, etapa, hash, $valorPronto)` (as travas do robô: só campo da
IA; adia com editor em uso ou com o item na fila). Respeita a cota diária da loja (falta cota → `erro` com mensagem
neutra, sem gastar). Confira as assinaturas reais antes de usar. Nenhum prompt do Publicador muda.

D60: enquanto o ajuste está em `fila`/`fazendo`, o item fica na revisão com o status **"Robô refazendo · título,
descrição"** (marca em cache por pub, 6 h; some em `feito`/`erro`), sem voltar à etapa 4.

Tela (gaveta `v:`): botão secundário **[Pedir ajuste ao robô]** quando há `escrita.revisao.ajuste`, a prévia está
pronta, na revisão, sem publicação nem ajuste em andamento e `ajustes_restantes_hoje > 0`; abre no rodapé a escolha
"O que refazer?" com [Título] [Descrição] (pelo menos um) + [Refazer] [Cancelar], e "Restam N ajustes hoje";
depois pergunta o `GET ajuste` a cada 3 s enquanto `fila`/`fazendo`; ao terminar recarrega a prévia (o título e a
descrição novos aparecem) e mostra o resultado por parte ("Título refeito.", "Descrição mantida: a equipe editou.");
409/422 → mensagem do servidor. Enquanto o ajuste anda, [Aprovar e publicar] e a caixa de preço ficam desligados.

Custo: título = 1 chamada de IA (+1 do Modelo, se o gerador usar); descrição = 1. Zero escrita no ML.

Testes 4b: `tests/Feature/Trilha/AjusteDoRoboNaTrilhaTest.php` (IA falsa) — título novo diferente dos atuais
(`evitar`); parte editada pela equipe → `mantido`, nada sobrescrito; editor em uso → adiado (sem perder o pedido);
na fila/publicando/publicado → 409; fora da revisão → 409; revisão velha → 409 sem `travado`; 4º pedido no dia → 422;
cota da loja esgotada → `erro` sem chamar a IA; Job na fila da IA (não `high`); texto livre no corpo é ignorado (não
chega a prompt nenhum); D60 na linha da Lista; a `ajuste` da prévia = a do GET; matriz de acesso. Rede: suítes do
preparo IA do Publicador (`PreparoIaDoRascunhoService`, `PalavrasChaveService`, `DescricaoIaService`). JS:
`tests/js/trilha-ajuste.test.js`.

### 19.15 4f — fotos enviadas pelo cliente (contrato)
É a 1ª ESCRITA na porta do cliente (R9): guarda própria, allowlist do domínio do cliente, CSRF, 413, varredura de
props. D57 (controllers finos em `App\Http\Controllers\Trilha\Cliente\*` que leem `PortalContexto` e chamam os MESMOS
serviços com `Company` + `AtorDoPortal::cliente(PortalUsuario)`) e D58 (`CadastroNaTrilha::enviarFotos/excluirFoto` +
guarda nova `empresaDoCliente(Company)` — as duas chaves `TrilhaLigada` e `TrilhaDeTrabalho`; o vínculo do usuário com
a empresa já vem conferido pelo `portal.auth`). Resposta do usuário (§19.12): enviar E excluir, só enquanto o
produto está na Lista.

Rotas (grupo `portal.auth`, prefixo `portal/{portalEmpresa}`, nomes `portal.trilha.fotos.*`; cada uma com linha em
`RestringeDominioDoPortal::PERMITIDO_COM_ID`):
- `GET  portal/{pe}/trilha/produtos/{produto}/fotos` → 200 o quadro de fotos do produto (abaixo).
- `GET  portal/{pe}/trilha/fotos/{variacao}/{imagem}` → a imagem (só da empresa; `Cache-Control: private`).
- `POST portal/{pe}/trilha/produtos/{produto}/variacoes/{variacao}/fotos` (multipart `imagens[]`) → 200 o quadro novo + `avisos`.
- `DELETE portal/{pe}/trilha/produtos/{produto}/variacoes/{variacao}/fotos/{imagem}` → 200 o quadro novo.
Throttles com contador próprio (`trilha.cliente.fotos*`). A URL base chega na página do cliente por
`cliente_escrita.fotos` = `/portal/{pe}/trilha/produtos/__PRODUTO__/fotos` — SÓ quando as duas chaves estão ligadas e
quem vê é o cliente de verdade (no "Ver como: Cliente" da equipe NÃO vem: a equipe tem a gaveta dela).

Quadro de fotos:
```json
{"produto": {"id": 91, "nome": "Cadeira Jatobá", "editavel": true, "motivo": null},
 "cores": [{"variacao_id": 301, "rotulo": "Natural", "minimo": 3, "maximo": 12,
            "fotos": [{"id": 5501, "url": "/portal/88/trilha/fotos/301/5501"}], "faltam": 1}],
 "limites": {"tamanho_mb": 10, "tipos": ["jpg", "png", "webp"], "por_envio": 12}}
```
- `cores` segue a MESMA régua das fotos por cor da equipe (`CompletudeDoProduto::fotosDeCadaVariacao`: com cor e
  tamanho, a foto é da cor — vai na 1ª variação da cor). `faltam` = quanto falta para o mínimo daquela cor.
- `editavel: false` + `motivo` ("Este produto já seguiu para a próxima etapa. Para trocar as fotos, fale com a
  equipe.") quando o produto andou (`exigirLivre`/trava) ou não está mais na Lista; POST/DELETE nesse caso → 409
  `{message}`.

Guarda, nesta ordem: `portal.auth` → 404 sem as duas chaves (`empresaDoCliente`) → 404 produto/variação/imagem de
outra empresa ou inexistente (antes da validação) → 409 produto que andou/fora da Lista → 422 (regras de imagem de
`RegrasDoCadastro`; 413 do servidor → 422 `errors.imagens` — estender a regex de `bootstrap/app.php` para as rotas do
cliente). Evento `trilha_cadastro_salvo` (`passo: fotos`) com o ator CLIENTE. Depois de enviar, `prepararSeCabe` como na
equipe (o produto completo segue sozinho; o robô espera a onda).

Tela (cliente): no painel do produto da Lista (`p:`), a caixa **Fotos** por cor: miniaturas (`loading="lazy"`),
"Faltam N fotos nesta cor" / "Fotos completas", [Enviar fotos] (várias de uma vez; confere tipo e tamanho antes de
enviar), [Excluir] por foto com confirmação no rodapé, e o andamento do envio. `editavel: false` → só leitura com o
`motivo`. Textos neutros: nada de "Mercado Livre", "Publicador", "Portal" nem "agente". O pacote do cliente não pode
levar nada da equipe (`Cadastro/`, `Revisao/`).

Testes 4f: `tests/Feature/Trilha/FotosDoClienteNaTrilhaTest.php` — o cliente da 459 envia e exclui no próprio produto
(arquivo gravado, evento com ator cliente); produto/variação/imagem de outra empresa → 404; sem as chaves → 404;
produto que andou ou fora da Lista → 409 (nada grava); 413/`UPLOAD_ERR_INI_SIZE` → 422 `errors.imagens`; tipo e
tamanho → 422; o domínio do cliente deixa a rota passar (allowlist) e o CSRF vale; `cliente_escrita` só para o
cliente de verdade (não no "Ver como: Cliente"); a varredura do `AcessoATrilhaTest` (props compartilhadas do cliente
sem dado da equipe); a rota de foto da equipe não muda. JS: `tests/js/trilha-fotos-cliente.test.js` + teste de que
o pacote do cliente não importa `Cadastro/` nem `Revisao/`.

### 19.16 4e — aprovação do cliente por loja (contrato)
Respostas (§19.1): o cliente aprova a PRÉVIA **antes** de publicar; o pedido de ajuste dele é texto para a equipe
(D52, nunca vai a prompt). Padrões (§19.1): a chave liga pelo `.env` (D53); o cliente só vê a prévia quando o item
está com ele (D44); no modo equipe do Portal a ECF pode aprovar pelo cliente (`decidido_por_equipe`).
**Decisão desta fatia (sem pergunta):** com a chave ligada na loja, a equipe NÃO publica direto — o `POST publicar`
da 4d responde 409 "Esta loja aprova com o cliente: mande a prévia para ele." — senão a aprovação não vale nada. A
exceção é o item já `aprovado` pelo cliente cujo envio não publicou (D56): aí [Aprovar e publicar] volta para a equipe.

Chave: `config('trilha.aprovacao_cliente.empresas')` (env `TRILHA_APROVACAO_CLIENTE_EMPRESAS`, lista de ids, padrão
vazio), cruzada com `TrilhaDeTrabalho`. Desligada: tudo como na 4d.

Schema: a tabela NOVA `trilha_aprovacoes` da §19.6 (nenhuma ALTER). Migration com as armadilhas do MariaDB (nomes de
índice curtos; `nullOnDelete` ⇒ nullable; o `down` dropa as FKs antes da tabela). Estados: `com_cliente | ajuste |
aprovado` (string). Histórico no `activity_log` (`trilha_aprovacao_enviada`, `_aprovada`, `_ajuste_pedido`, com o ator).

Equipe (URL em `escrita.revisao.cliente`, com `__ITEM__`, só com a chave ligada):
- `POST /trilha/{company}/ofertas/{item}/cliente` (`trilha.cliente`, 10/min; contador `trilha.revisao.cliente`) — corpo
  `{revisao}` → 200 a prévia nova com `aprovacao`. Cria/atualiza a linha: `estado=com_cliente`, `rodada+1`,
  `revisao_enviada` = revisão atual, `enviado_em`, `enviado_por`. Guarda: 404 chaves → 403 → 404 item → 409 (chave
  desligada; revisão velha SEM `travado`; fora da revisão; na fila/publicando/publicado; ajuste do robô andando; já
  `com_cliente`) → 422 Premium = Clássico em TODAS as cores (D46).
- Enquanto `com_cliente`, as escritas da equipe (preço 4c, ajuste 4b) continuam possíveis, mas qualquer uma que suba a
  revisão faz o envio valer menos: o cliente que aprovar recebe 409 (D55) e a linha volta para a equipe
  (`estado=ajuste`, `pedido` null, motivo "A equipe mudou o anúncio depois de enviar.").

Cliente (grupo `portal.auth`, prefixo `portal/{pe}`, nomes `portal.trilha.aprovacao.*`; allowlist do domínio do
cliente; controllers finos em `Trilha\Cliente\*` — D57):
- `GET  portal/{pe}/trilha/ofertas/{item}/previa` → a prévia na forma da §19.5 SEM `preparo`, `bloqueios`, `ajuste`,
  `ajustes_restantes_hoje`, `preco.*.da_precificacao` nem `preco.*.minimo`; `publicacao` só com `situacao`/`mensagem`
  neutras; e `aprovacao: {estado, rodada, enviado_em, pedido}`. Só quando o item está `com_cliente` (ou `ajuste`/
  `aprovado` desta rodada, para ele acompanhar); senão 404.
- `GET  portal/{pe}/trilha/ofertas/{item}/fotos/{imagem}` → a foto (só daquele rascunho/empresa).
- `POST portal/{pe}/trilha/ofertas/{item}/aprovar` `{revisao}` → 200 `{aprovacao, publicacao}`: `estado=aprovado`,
  `decidido_em`, `decidido_por_cliente` (ou `decidido_por_equipe` no modo equipe) e dispara o MESMO caminho da 4d
  (`PublicacaoNaTrilha` → `PublicarPelaTrilhaJob`) com o `User` `enviado_por` (D56; a guarda da #459 vale igual). Se
  `enviado_por` estiver inativo: `aprovado` sem publicar, e a linha da equipe diz "Aprovado pelo cliente · falta
  publicar". 409: revisão diferente da `revisao_enviada` (D55 — e a linha volta para a equipe); não está `com_cliente`.
- `POST portal/{pe}/trilha/ofertas/{item}/ajuste` `{revisao, texto}` (texto obrigatório, 1–1000) → 200 `{aprovacao}`:
  `estado=ajuste`, `pedido`=texto, `decidido_em`. 422 `errors.texto`. O texto NUNCA vai a prompt.
- A página do cliente recebe `cliente_escrita.aprovacao = {previa, aprovar, ajuste}` (com `__ITEM__`) só para o
  cliente de verdade com as chaves ligadas.

Lista (status e ação; D59 — nada de "Mercado Livre", "Publicador", "Portal", "agente" para o cliente):
- `com_cliente`: status "Com o cliente para aprovar"; ação da equipe "Com o cliente"; ação do cliente **"Aprovar"**
  (abre `v:` na versão dele).
- `ajuste`: status "O cliente pediu ajuste" (`alerta: true`); a equipe abre a `v:` e vê a caixa "O cliente pediu
  ajuste" com o texto citado, mais as ferramentas de sempre e [Mandar ao cliente de novo].
- `aprovado`: segue a publicação ("Aprovado pelo cliente · na fila" etc.; "· falta publicar" no D56).
- `lib/trilha.js` `ETAPAS`: o dono da etapa 5 deixa de ser "Publicador" (pendência da 4a) — "Equipe ECF".

Tela da equipe (gaveta `v:`): com a chave ligada, o primário vira **[Mandar ao cliente]** (confirmação no rodapé);
`com_cliente` → caixa "Com o cliente para aprovar desde …" e primário desligado; `ajuste` → caixa "O cliente pediu
ajuste" com o texto e [Mandar ao cliente de novo]; `aprovado` + falta publicar → [Aprovar e publicar] volta.
Tela do cliente (gaveta `v:` da porta dele, pasta própria `Components/Trilha/Cliente/`, lazy com 2º caminho): o cartão
do anúncio (reusar o cartão da 4a SÓ se ele não puxar nada da equipe — senão, versão enxuta própria), "Preço" só
leitura, e o rodapé [Pedir ajuste] (abre caixa de texto, obrigatório, até 1000) e **[Aprovar anúncio]** (confirmação
"O anúncio vai ao ar depois da sua aprovação."); depois de aprovar: "Aprovado. A equipe publica em seguida." /
acompanha a publicação com texto neutro.

Testes 4e: `tests/Feature/Trilha/AprovacaoDoClienteNaTrilhaTest.php` — chave desligada → 4d intacta; chave ligada →
[Mandar ao cliente] cria a linha `com_cliente` e o `POST publicar` da equipe dá 409; cliente aprova → o Job de
publicar entra com o `User` `enviado_por` e a guarda da #459 vale (a 460 com a chave ligada continua recusada);
revisão mudada depois do envio → 409 para o cliente e a linha volta à equipe; ajuste exige texto (422) e não chega a
prompt nenhum; outra empresa → 404; o cliente só vê a prévia do item com ele, sem `minimo`/`da_precificacao`/
`preparo`/`bloqueios`; modo equipe grava `decidido_por_equipe`; `enviado_por` inativo → "falta publicar"; migration
sobe e desce; matriz de acesso das rotas da equipe e do cliente (allowlist, CSRF, varredura de props). JS:
`tests/js/trilha-aprovacao.test.js` (equipe e cliente) + o pacote do cliente sem `Cadastro/`/`Revisao/` da equipe.
