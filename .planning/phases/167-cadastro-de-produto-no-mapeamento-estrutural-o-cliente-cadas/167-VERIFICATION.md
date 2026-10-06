---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
verified: 2026-10-06T18:38:28Z
status: human_needed
score: 30/30 must-haves verificados (5 verdades do objetivo + 14 PR167 + 5 bloqueios da revisão + 6 regras do projeto)
overrides_applied: 1
overrides:
  - must_have: "PR167-07 Tela de cadastro rápido em tabela editável (uma linha por variação, Tab/Enter, colar do Excel crescendo linhas)"
    reason: "Substituído por D-23/D-24: no checkpoint do 167-17 o usuário reprovou a grade ('eu disse que não queria uma planilha dentro do sistema pra esse caso'). Ficou lista de cartões + ficha em página (D-27) gravando pelo mesmo POST linhas, e a planilha só como arquivo importado com prévia."
    accepted_by: "usuário — decisão D-23 registrada no 167-CONTEXT (checkpoint do 167-17)"
    accepted_at: "2026-10-06T00:00:00Z"
gaps: []
human_verification:
  - test: "Contar as linhas de estrutura_ofertas em produção (só leitura) antes do deploy e registrar o número aqui"
    expected: "Número registrado. O ADD COLUMN nullable sem default é instantâneo no MariaDB 10.4 (suposição A1 do RESEARCH)"
    why_human: "Exige acesso de leitura à VPS. Este worktree não tem .vps_cmd.sh e o verificador não acessa produção"
  - test: "No deploy, rodar as 2 migrations com --path, nesta ordem: 2026_10_06_100000_create_estrutura_produtos_tables e depois 2026_10_06_100100_add_variacao_id_to_estrutura_ofertas"
    expected: "As duas terminam DONE, sem 1059, 1553 ou 1830. Atenção: o deploy.sh (linha 70) roda 'php artisan migrate --force' sem --path. Se as duas já tiverem rodado com --path, ele não encontra nada desta fase pendente. As duas são idempotentes."
    why_human: "Deploy só com autorização explícita do usuário"
  - test: "Ler uma vez o frete real (GET /users/{seller}/shipping_options/free) numa conta conectada, só com o 'pode' do usuário (D-16)"
    expected: "list_cost coerente com a tabela da ECF. Conferir se o ML respeita as dimensions enviadas (ressalva A2: em algumas categorias ME2 ele usa as da categoria)"
    why_human: "Chamada real com conta de cliente. Os testes usam só Http::fake com token falso"
  - test: "Testar o mimes:xlsx em produção com .xlsx exportados do Google Sheets, do LibreOffice e do Excel Mac (BE-IN-01)"
    expected: "Os três passam na prévia e na importação. Nenhum recebe 'Envie a planilha no formato .xlsx' por causa do libmagic do servidor"
    why_human: "O MIME detectado depende do libmagic do servidor de produção"
  - test: "(adicional do verificador) Conferência visual curta da ficha do produto depois das correções da revisão"
    expected: "Mesmo desenho aprovado: banner 'Você tinha alterações não salvas…', campos travados durante o 'Salvando…', gatilhos de Ambientes e Categoria (FE-IN-09) e 'Sem família' (FE-IN-12) sem quebrar o layout da REF-2"
    why_human: "A aprovação do checkpoint (cd99282f, 14:08) é anterior aos 38 commits de correção (14:41–15:26), vários deles na ficha. O roteiro 26/26 do REVIEW-FIX testou comportamento, não aparência"
  - test: "(adicional do verificador) Voltar do navegador com alteração não salva fora do Chrome de computador: gesto de voltar no Android e no Safari do iOS"
    expected: "Pergunta 'Há alterações não salvas neste produto. Sair sem salvar?'. 'Ficar' mantém o que foi digitado; 'Sair' volta para a lista"
    why_human: "O roteiro 26/26 e a medição da ordem dos ouvintes (learnings §32) foram feitos só no Chrome 152 de computador. O node --test não exercita o navegador"
  - test: "(decisão do usuário) Aceitar os dois limites conhecidos: (1) em catálogo com mais de 100 produtos, o produto novo cai na última página, a volta vai para a página 1 e o destaque do D-32 não aparece; (2) se a resposta se perde (504) depois de gravar a 1ª variação de um produto novo, a nova tentativa responde 'código já existe'"
    expected: "Aceitar como estão ou abrir trabalho próprio. (1) Está documentado no desvio 1 do 167-21 e nas pendências do 167-17. (2) Está em 'Pendente sem solução' no REVIEW-FIX e pede idempotência pela chave no servidor"
    why_human: "São limites de produto, não regressões. Só o usuário decide se bastam"
---

# Fase 167: Cadastro de Produto no Mapeamento Estrutural — Relatório de Verificação

**Objetivo da fase:** a aba Produtos da planilha `3Planejamento_Estrutural_ECF.xlsx` vira o submódulo **Produtos** do
Mapeamento Estrutural do Portal do Cliente. O cliente e a equipe cadastram cada produto com código, grupo/variação,
nome, família, ambiente(s), categoria do ML, volumes (C×L×A e peso), peso total e custo, pela tela ou importando a
planilha. Cada produto se liga às ofertas simples da Lista SKUs.

**Verificado em:** 2026-10-06T18:38:28Z · worktree `C:/tmp/ecf-publicador-spec-261001` · HEAD `fc8c6096` · diff
`8bbfc5e2..HEAD` (116 commits, 105 arquivos fora de `.planning`, árvore limpa)
**Status:** `human_needed`. Nenhuma lacuna no código; ficam as verificações de produção, de navegador e as decisões do usuário.
**Reverificação:** não. É a verificação inicial.

O ROADMAP da fase não traz uma lista "Success Criteria". As verdades abaixo saem do texto do objetivo e somam-se aos
requisitos PR167-01..14, às decisões D-01..D-32 e aos 5 bloqueios do `167-REVIEW.md`. Os SUMMARYs não contam como
evidência: cada item foi conferido no código e, quando possível, num teste rodado por mim.

---

## 1. Verdades do objetivo

| # | Verdade | Estado | Evidência |
|---|---|---|---|
| T1 | Existe o submódulo Produtos, 1º do menu do Mapeamento, acessível ao cliente e à equipe | ✓ VERIFICADO | `ModulosPortal.php:109` (`'produtos'` é o 1º de `SUBMODULOS['estrutura']`); rotas `routes/web.php:205-238` dentro do grupo `portal.auth`; `AcessoAosProdutosTest` (14 testes, verde): menu, cliente, equipe admin e analista da carteira |
| T2 | Cadastra-se pela tela o produto com código, grupo/variação, nome, família, ambientes, categoria ML, volumes, peso total e custo | ✓ VERIFICADO | Ficha `EstruturaProdutoFicha.jsx` + `useFichaProduto.js` → `POST linhas` → `ProdutoCadastroService::gravarLinhas` (`:96-204`). Peso total e nº de volumes derivados em `ProdutoLinhas.php` (`peso_total`, `n_volumes`), sem coluna própria (migration `100000`, docblock) |
| T3 | Cadastra-se importando a planilha: modelo, prévia, acrescentar e atualizar, nada apagado | ✓ VERIFICADO | `ModeloProdutosXlsx` (11 colunas), `LeitorPlanilhaProdutos`, `ImportadorProdutos::previa/aplicar` (`:39-113`), controller `previaImportacao`/`aplicarImportacao` (`:203-229`); `ModeloEImportacaoTest` (26 testes, verde) |
| T4 | Cada variação tem uma oferta simples ligada na Lista SKUs: criada, sincronizada e protegida | ✓ VERIFICADO | `ProdutoCadastroService::criarOferta` (`:223-237`), `sincronizarOferta` (`:240-255`), `garantirOfertas` (`:282-299`); unique `eo_variacao_uq`; proteção em `EstruturaOfertaService.php:162` (atualizar) e `:215` (excluir); `OfertaLigadaAoProdutoTest` e `OfertaLigadaNaListaSkusTest` verdes |
| T5 | A base para a geração automática existe: família e ambiente como dado estruturado da empresa e custo no produto alimentando a Precificação | ✓ VERIFICADO | Tabelas `estrutura_familias`/`estrutura_ambientes` + pivot N:N; `ListasDaEmpresaService`; `ProdutoCustos::daEmpresa` usado em `EstruturaPrecificacaoService.php:47`; `CustoDoProdutoNaPrecificacaoTest` (5 testes, verde, inclusive "Publicador herda o custo da variação") |

**Pontuação das verdades:** 5/5.

---

## 2. Requisitos PR167-01..14

| Req. | Descrição curta | Estado | Evidência (arquivo:linha) |
|---|---|---|---|
| PR167-01 | Submódulo 1º do menu; empresa do `PortalContexto`; allowlist; id de outra empresa = 404; `origem` no log | ✓ VERIFICADO | `ModulosPortal.php:109`; controller sempre `PortalContexto::empresa()` (`PortalEstruturaProdutosController.php:64, 92, 113, 146, 289, 337`); allowlist `RestringeDominioDoPortal.php:126-140` + `PERMITIDO_COM_ID` (`:179`, regex ancorada `\A…\z` só com dígitos); `ficha` faz `where('company_id')->findOrFail` (`:91-93`); `test_ids_de_outra_empresa_respondem_404`, `test_a_origem_no_log_e_cliente…`/`…interno…` verdes |
| PR167-02 | Produto → variações → volumes; código único por empresa; peso total derivado; variação nova copia a 1ª | ✓ VERIFICADO | Migration `100000` (`epv_company_cod_uq`, volumes por variação); comparação sem caixa e sem acento em `chaveCodigo` (`ProdutoCadastroService.php:87-90`) + recusa (`:482-485`); cópia da 1ª em `:572-600`; na ficha, `novaVariacao` copia a 1ª (`useFichaProduto.js:164-185`) |
| PR167-03 | Listas família e ambiente da empresa; ambiente N:N; normalização; recusa de `/ , \|`; "em uso" bloqueia exclusão | ✓ VERIFICADO | `ListasDaEmpresaService.php:50-59` (chave sem acento, sem caixa e com espaços colapsados), `:245-261` (recusa `/ , \|`), `:160-176` ("Em uso em N produtos"), `:274-279`; `ListasDaEmpresaTest` (9) verde |
| PR167-04 | Categoria real do ML (id + nome + caminho), sugerida pelo nome, só folha, nunca aceita sozinha | ✓ VERIFICADO | `resolverCategoria` (`ProdutoCadastroService.php:689-729`; folha em `:720-722`); app token (`CategoriaSugestaoService.php:22, 166`; `detalhes()` novo); sugestão em lote não grava (`controller :280-321`); aceitar só pela `JanelaSugestoesCategoria`; `CategoriaDoProdutoTest` (5) verde |
| PR167-05 | Uma oferta simples por variação (`variacao_id` unique); sku e nome acompanham; ofertas antigas intocadas; migration idempotente verificada no MariaDB 10.4 | ✓ VERIFICADO | Migration `100100` (cada DDL sob checagem de existência, sem try/catch, `nullOnDelete`); conferido por mim, só leitura, no MariaDB 10.4.32 local: `variacao_id bigint(20) unsigned DEFAULT NULL`, `UNIQUE KEY eo_variacao_uq`, `eo_variacao_fk … ON DELETE SET NULL`, `eo_company_fk` intacta, 13 ofertas e 0 ligadas; `test_ofertas_antigas_ficam_intactas_e_sku_repetido_gera_outra_oferta` verde |
| PR167-06 | Custo mora na variação; Precificação usa o custo do produto (origem `produto`, somente leitura); combos somam; oferta sem produto igual a hoje; Publicador herda | ✓ VERIFICADO | `EstruturaPrecificacaoService.php:45-50` (ligada ⇒ custo da variação, inclusive null), `:97-110` (recusa custo em oferta ligada e não sobrescreve a coluna antiga), `calcular` com origem `produto`; JSX `EstruturaPrecificacao.jsx` mostra "vem do produto" + link; consumidores do Publicador (`DadosEfetivosTest` 3, `SincronizaPortalTest` 8, `MigracaoAnunciarAntigoTest` 4, `CustoDoAnuncioTest` 7) verdes |
| PR167-07 | Tela em tabela editável (grade) | ✓ SUBSTITUÍDO por D-23/D-24 (override) | O usuário reprovou a grade no checkpoint. Nenhuma página de Produtos usa `SpreadsheetGrid` (o único consumidor segue sendo `Pages/Mlb/ImplementacaoPublica.jsx`). O que restou do requisito vale: gravação por linha no servidor (`POST linhas`), colunas calculadas só no servidor e erro por variação |
| PR167-08 | Modelo `.xlsx` + importação com prévia (novos, atualizados, sem mudança, erros); casa pelo código; nada apagado; aceita a aba original | ✓ VERIFICADO | `ImportadorProdutos.php:117-248` (classificação), `:186-195` (Ref repetida no arquivo), `:251-262` (linha de exemplo ignorada); leitor com `setReadDataOnly`, limites antes da matriz (`LeitorPlanilhaProdutos.php:87-148`); recusa `.xlsm` (`controller :444-452`); gabarito da aba real (70 → 55/8/6/1) registrado só em contagens no BASELINE |
| PR167-09 | Logística provável, cubado/faturado e pacote empilhado em classe PHP pura com regras em config | ✓ VERIFICADO | `LogisticaProduto.php:32-108` (pacote D-17, ME2/Full pelo peso REAL); `config/estrutura_produtos.php` (fator 6000, mínimo 5 kg, ME2 30/200/100, Full 20/80); `LogisticaProdutoTest` (12) verde com os casos do gabarito |
| PR167-10 | Frete ME2 pela API com token da empresa (cache + lote); tabela ECF rotulada "estimativa"; preço de cotação pela Precificação; limite de frete grátis fora do código | ✓ VERIFICADO (código) · leitura real pendente (humano) | `FreteMe2Service.php:59-77` (estimativa sem HTTP), `:84-…` (pool próprio, timeout 8 s, sem retry em série, cache 6 h, ≤ 12 por requisição), `:349-363` (preço pelo `PrecificacaoEstrutura::preco`), `gratis_obrigatorio` sai da resposta da API (`:192`); nenhum literal `79` nos serviços, no JS nem nas páginas (o `79` do config é limite de coluna da tabela, com comentário); `FreteDoProdutoTest` (16) verde |
| PR167-11 | Pendências por linha ("Falta") | ✓ VERIFICADO | `PendenciasDoProduto.php:39-77` (medidas, peso, custo, categoria, família, ambiente, frete ME1); aplicado em `ProdutoLinhas.php` (`pendencias`); `PendenciasDoProdutoTest` (6) verde |
| PR167-12 | Ciclo de vida: editar sincroniza a oferta; excluir reusa `EstruturaOfertaService::excluir` (componente bloqueia, anúncios vão para a espera, item do Publicador fica solto); a Lista SKUs não altera a oferta ligada | ✓ VERIFICADO | `ProdutoCadastroService::excluirVariacao` (`:309-345`), `excluir(..., viaProduto: true)`; `EstruturaOfertaService.php:162-169` (atualizar mantém sku/nome/fase) e `:215-219` (excluir só via Produtos); `PortalEstruturaController::dadosOferta` não aceita `variacao_id`; `CicloDeVidaDoProdutoTest` (7) e `OfertaExcluidaNoPortalTest` (9) verdes |
| PR167-13 | Kit, combo e combit sem logística nem frete nesta fase | ✓ VERIFICADO | `LogisticaProduto::daVolumes` só é chamado para variação (`ProdutoLinhas.php:103`, `controller :349`); `test_combo_e_kit_nao_entram_nas_linhas_do_produtos_nem_ganham_logistica_provavel_ou_frete` verde |
| PR167-14 | Gate de segurança do dado: baseline antes de mexer, `SHOW CREATE TABLE`/`SHOW INDEX` no MariaDB, `down()` testado, VERIFICATION | ✓ VERIFICADO (local) · contagem em produção pendente (humano) | `167-BASELINE-TESTES.md` registrado em `8bbfc5e2`, antes do 1º commit de código; rollback + re-up registrados no 167-01, no 167-17 e no BE-WR-08 (banco descartável); estado final conferido por mim, só leitura (FKs: as 9 das tabelas novas com CASCADE/SET NULL corretos, mais `eo_variacao_fk` SET NULL); `SchemaDosProdutosTest` (11) e `MigracoesDaFaseDetectamMariaDbTest` (4) verdes |

**Pontuação dos requisitos:** 14/14 (13 verificados + 1 substituído por decisão do usuário).

---

## 3. Decisões D-01..D-32

| D | Decisão | Estado | Evidência |
|---|---|---|---|
| D-01 | Ancorado em Company; empresa só do `PortalContexto` | ✓ | `company_id` ignorado na linha (`ProdutoCadastroService.php:543-544, 591`); `test_company_id_do_corpo_e_ignorado…` |
| D-02 | Cliente e equipe gravam; log com `origem` | ✓ | `RegistroEstrutura::registrar($ator, …)` em todo caminho de escrita; testes de origem cliente/interno |
| D-03 | Produto = Grupo; variação = Ref com eixo/valor, volumes, custo | ✓ | Migration `100000`; models `EstruturaProduto`, `EstruturaProdutoVariacao`, `EstruturaProdutoVolume` |
| D-04 | Medidas, peso e custo por variação; nova copia a 1ª | ✓ | `useFichaProduto.js:164-185`; servidor `:572-600` (importação) |
| D-05 | Família e ambiente como listas da empresa; ambiente múltiplo | ✓ | `ListasDaEmpresaService`; pivot `estrutura_produto_ambiente` |
| D-06 | Categoria real do ML, só folha | ✓ | ver PR167-04 |
| D-07 | "Família" = linha de design; sem colisão com `pub_produtos` | ✓ | tabelas `estrutura_*`; dica "Família é a linha de design (ex.: Farmhouse)" na ficha |
| D-08 | Produto cria a oferta simples, uma por variação | ✓ | ver T4 |
| D-09 | Ofertas antigas intocadas; SKU igual gera outra oferta | ✓ | sem backfill (migration `100100`); teste de SKU repetido |
| D-10 | Custo na variação; combos somam | ✓ | ver PR167-06 |
| D-11 | ALTER em tabela com dado ⇒ GSD completo | ✓ | baseline, schema por escrito no docblock das migrations, MariaDB, esta VERIFICATION |
| D-12 | Tela como caminho principal, em tabela editável | ◐ formato SUBSTITUÍDO por D-23 | A tela continua sendo o caminho principal (ficha); a grade saiu |
| D-13 | Planilha-modelo + prévia | ✓ | ver PR167-08 |
| D-14 | Reimportar = acrescentar e atualizar, nada apagado | ✓ | BE-CR-02 corrigido (ver §4) |
| D-15 | Logística, cubado e frete por variação | ✓ | ver PR167-09 |
| D-16 | Frete ME2 pela API do cliente; sem conta, tabela ECF como estimativa | ✓ código / ? leitura real | nota "O frete é uma estimativa pela tabela da ECF…" (`EstruturaProdutos.jsx:317-321`); leitura real é item humano |
| D-17 | Vários volumes empilhados num pacote | ✓ | `LogisticaProduto::pacote` (`:32-45`) |
| D-18 | Logística de kits e combos fica para depois | ✓ | ver PR167-13 |
| D-19 | Frete só exibido, nunca gravado como preço | ✓ | `FreteMe2Service` só GET + `Cache::put`; nenhum `EstruturaPrecificacao`/`frete_*` em `Services/Portal/Estrutura/Produtos` (grep); `test_cotar_so_le_nao_grava_e_nao_vaza_o_token` e `test_fretes_com_conta_…_nao_gravam_precificacao` |
| D-20 | Eixo em lista fechada; valor livre; ordinal aceito | ✓ | `EstruturaProdutoVariacao::EIXOS` = `VARIACAO_TIPOS` do Onboarding; `NormalizadorDeLinha.php:107-148` |
| D-21 | Entrada: Produtos se há produto ou não há oferta; senão Lista SKUs | ✓ | `PortalEstruturaController.php:72-75`; `test_entrada_*` (3) |
| D-22 | Excluir variação com anúncios: confirmação com contagem; só componente bloqueia | ✓ | `JanelaExcluirVariacao.jsx:29-64`; `excluirVariacao` |
| D-23 | Sem planilha na tela: lista + ficha | ✓ | nenhuma página de Produtos importa `SpreadsheetGrid`; `ListaProdutos` + cartões |
| D-24 | Importação por arquivo continua | ✓ | `JanelaImportacao.jsx` (prévia → confirmar) |
| D-25 | Layout 1:1 das REF-1/2/3 com cores do sistema | ✓ aprovado pelo usuário em 06/10 (167-17 Task 3) · ? reconferência curta depois das correções | ver item humano 5 |
| D-26 | Seletor Visual grande / Lista guardado no navegador | ✓ | `SeletorVisualizacao.jsx`; `lerModo`/`gravarModo` em `localStorage` (`produtosNavegacao.js:290-305`) |
| D-27 | Ficha em página inteira com URL própria; voltar preserva busca, página, modo e rolagem | ✓ | rotas `/novo` (`web.php:207`) e `/{produto}` com `whereNumber` (`:233`); breadcrumb (`EstruturaProdutoFicha.jsx:227-232`); nenhum `Sheet` em Produtos; `guardarRetorno`/`rolarParaVolta` |
| D-28 | Campos reais; só Ref e Nome obrigatórios; calculados só leitura | ✓ | `useFichaProduto.js:204-209`; `FaixaCalculados.jsx` só exibe o que vem do servidor |
| D-29 | Quadro da foto com iniciais, sem upload | ✓ | `iniciais()` (`produtosEstrutura.js:119-121`); nenhum `type="file"` na ficha |
| D-30 | Sem "Ver no ML", sino ou avatar; ⋮ só com ações reais | ✓ (a parte da bolinha foi revogada pelo D-31) | `MenuDoProduto` só com "Abrir a ficha" e "Ver {SKU} na Lista SKUs" (`PecasDoProduto.jsx:165-181`) |
| D-31 | Sem bolinha de cor na variação | ✓ | nenhum mapa de cor em Produtos (grep); `PecasDoProduto.jsx:115` |
| D-32 | A lista destaca o produto de onde a pessoa voltou (até 2 min, consumido ao montar) | ✓ · limite com mais de 100 produtos (decisão) | `marcarUltimoProduto`/`pegarUltimoProduto` com `ULTIMO_VALE_MS = 2 min` (`produtosNavegacao.js:172-196`); etiqueta "Último aberto" (`PecasDoProduto.jsx:36-37`); forte por 2,5 s (`EstruturaProdutos.jsx:141-142`) |

---

## 4. Os 5 bloqueios da revisão: conferidos no código, não no REVIEW-FIX

| # | Bloqueio | Estado | O que está no código | Teste rodado por mim |
|---|---|---|---|---|
| 1 | **BE-CR-01 / FE-CR-01**: "Novo produto" caía dentro de um produto existente pelo `grupo` | ✓ CORRIGIDO nas duas pontas | **Servidor:** `ProdutoCadastroService.php:465-479`. Em MODO_GRADE, um grupo que casa com produto que **já existia antes do lote** (`$estado['existiam']`) vira erro da linha, "Já existe um produto com o código …". Além disso, `:537-541` grava código nulo no produto novo quando o código colide. **Ficha:** `produtosGravacao.js:42-46` (`semGrupo`) e `:70-75`. A 1ª variação vai sozinha e sem grupo; as demais seguem com o `produto_id` devolvido para ela. Se a 1ª falha, a sequência para | `GravarLinhasTest::test_produto_novo_com_grupo_igual_ao_codigo_orfao_de_outro_produto_nao_mexe_nele` ✓; `tests/js/estrutura-produtos-gravacao.test.js` (roda `gravarVariacoes` de verdade contra um servidor falso) ✓ |
| 2 | **BE-CR-02**: reimportar rebaixava a categoria confirmada e "SEM MEDIDAS" apagava volumes | ✓ CORRIGIDO | Categoria: `ProdutoCadastroService.php:694-696` (em MODO_IMPORTACAO, texto não substitui `categoria_ml_id`). Volumes: `NormalizadorDeLinha.php:268` + `VolumesTexto::semMedidas` (`:71-77`), onde "SEM MEDIDAS" conta como célula em branco. A prévia não acusa mudança nesses casos (`ImportadorProdutos.php:349-356`) | `ModeloEImportacaoTest::test_reimportar_nao_rebaixa_a_categoria_confirmada_nem_apaga_medidas_com_sem_medidas` ✓ |
| 3 | **FE-CR-02**: o voltar do navegador descartava a ficha sem perguntar | ✓ CORRIGIDO no código · ? fora do Chrome de computador | O ouvinte único é registrado no import do `app.jsx` (`:5`, antes do `createInertiaApp`): `guardaDoVoltar.js:30-32`. A ficha liga a guarda (`EstruturaProdutoFicha.jsx:83-107`), que pergunta, faz `stopImmediatePropagation` e devolve o histórico. Rascunho em `sessionStorage` com "Recuperar/Descartar" (`useFichaProduto.js:64-111`) | `tests/js/estrutura-guarda-do-voltar.test.js` ✓ (inclui "app.jsx importa a guarda antes do Inertia"). Navegador real: ver item humano 6 |
| 4 | **FE-CR-03**: esvaziar Valor, Eixo ou Custo não gravava | ✓ CORRIGIDO nas duas pontas | **Ficha:** `produtosEstrutura.js:196` (`limpou`) e `:211-240`. Campo esvaziado que tinha valor no servidor vai como `null`. **Servidor:** `NormalizadorDeLinha.php:152-155, 174-177, 188-191, 242-243` (`null` explícito = limpar). O controller lê o JSON cru para escapar do `ConvertEmptyStringsToNull` (`PortalEstruturaProdutosController.php:131-141`) | `GravarLinhasTest::test_nulo_explicito_limpa_eixo_valor_familia_e_custo_e_texto_vazio_nao_mexe` ✓; `estrutura-produtos-linha.test.js` (FE-CR-03 ×3) ✓ |
| 5 | **FE-CR-04**: a variação nova mostrava um custo e um eixo e gravava outros | ✓ CORRIGIDO | `produtosEstrutura.js:199` (`novaDeProdutoGravado`), `:211`, `:236`, `:238`. Eixo, volumes e custo vão sempre explícitos, com o que a tela mostra; o servidor deixa de copiar da 1ª do banco | `estrutura-produtos-linha.test.js` (FE-CR-04 ×3) ✓ |

---

## 5. Regras do projeto

| Regra | Estado | Evidência |
|---|---|---|
| Isolamento por empresa em todo acesso por id | ✓ | Rotas com id: `ficha` (`:91-93`), `excluirVariacao` (`ProdutoCadastroService.php:311`), listas `renomear/excluir` (`ListasDaEmpresaService.php:133, 162`). Ids no corpo da requisição: `gravarLinhas` (estado só da empresa, `carregar` `:354-383`), `sugerirCategorias` (`:288-292`), `cotarFretes` (`:338-342`). A busca da página tem o `orWhere` dentro do `where` da empresa (`ProdutoLinhas.php:36-44`). `ProdutoCustos` filtra `o.company_id` |
| Allowlist do Portal (`RestringeDominioDoPortal`) | ✓ | Uma linha por rota (`:126-140`) e id numérico ancorado (`:179-200`); `DominioLiberaTodoModuloTest` ✓ (varre o router) e `test_a_allowlist_tem_uma_linha_por_rota_e_nenhum_curinga_generico` ✓. Info: `variacoes/*`, `familias/*` e `ambientes/*` usam `*`, que no `Str::is` atravessa `/`. Só existem rotas com `whereNumber` sob esses prefixos, então o resto dá 404 no router |
| Migrations seguras no MariaDB: idempotentes, sem `migrate` puro | ✓ (código e local) · deploy é item humano | As duas reaplicam por nome, sem try/catch e com nomes < 64; não usam enum, json nem `timestamp()` solto. A de criação ficou idempotente no BE-WR-08 (`08426a4d`). Localmente rodou só com `--path`, segundo o BASELINE. Atenção: o `deploy.sh:70` roda `migrate --force` puro |
| Frete nunca gravado como preço (D-19) | ✓ | ver D-19 |
| Nenhuma chamada ao ML com conta de cliente nos testes | ✓ | Os testes que criam `MlToken` (`FreteDoProdutoTest`, `GravarLinhasTest`, `CategoriaDoProdutoTest`) usam token falso (`fake-access-token`) e `Http::fake` antes de qualquer cotação. Os testes sem conta conectada confirmam `Http::assertNothingSent` |
| Nenhum dado da planilha real no repositório | ✓ | `git diff --name-status 8bbfc5e2..HEAD`: nenhum `.xlsx`/`.xls`/`.csv`/`.ods`. Custos nos testes são redondos e fictícios (10, 50, 80, 100, "100,50"). Os nomes que aparecem ("Cristaleira 1014", "Farmhouse", "Palhinha Slim") são os exemplos da reunião e já estavam no `167-CONTEXT.md` (commit `e8eab8aa`, anterior ao início da fase). Guarda `test_o_teste_nao_cita_a_planilha_real_do_cliente`. O gabarito da aba real foi registrado só em contagens |

---

## 6. Artefatos e ligações

| Artefato | Existe | Substantivo | Ligado | Dados fluem |
|---|---|---|---|---|
| `PortalEstruturaProdutosController.php` (465 l.) | ✓ | ✓ | rotas `web.php:205-238` | ✓ |
| `ProdutoCadastroService.php` (809 l.) | ✓ | ✓ | controller `gravarLinhas`, `ImportadorProdutos::aplicar` | ✓ grava e devolve as linhas recalculadas |
| `ImportadorProdutos` + `LeitorPlanilhaProdutos` + `ModeloProdutosXlsx` | ✓ | ✓ | controller `modelo/previa/aplicar` → `JanelaImportacao.jsx` | ✓ |
| `LogisticaProduto`, `TabelaFreteEcf`, `FreteMe2Service`, `PendenciasDoProduto` | ✓ | ✓ | `ProdutoLinhas` → props `produtos`/`linhas` | ✓ tudo calculado no servidor; o JS só exibe |
| `ProdutoCustos` | ✓ | ✓ | `EstruturaPrecificacaoService::pagina` | ✓ join real `ofertas × variacoes` |
| `EstruturaProdutos.jsx` / `EstruturaProdutoFicha.jsx` | ✓ | ✓ | Inertia `Portal/EstruturaProdutos` e `Portal/EstruturaProdutoFicha`; presentes no `public/build/manifest.json` local | ✓ props do controller e `POST linhas` |
| Lista SKUs: selo "do Produtos" e campos protegidos | ✓ | ✓ | `variacao_id` em `EstruturaConjunto` → `EstruturaVisaoService::oferta` → `EstruturaLista.jsx` / `FormOferta.jsx` | ✓ |
| Migrations `2026_10_06_100000` / `100100` | ✓ | ✓ | aplicadas no MariaDB local (batches 127/128) | n/a |

---

## 7. Testes rodados nesta verificação (saída em arquivo, exit conferido, sem `| tail`)

- **PHP, arquivo por arquivo** (sem rodar os grupos inteiros de `tests/Feature/PortalCliente`): **31 arquivos, 294 testes, 2.066 asserções, todos com exit 0.**
  - Todos os 14 arquivos de `tests/Feature/PortalCliente/Estrutura/Produtos` (163 testes).
  - Os 6 unitários de `tests/Unit/PortalEstrutura` da fase.
  - Os consumidores do Publicador: `DadosEfetivosTest`, `SincronizaPortalTest`, `MigracaoAnunciarAntigoTest`, `CustoDoAnuncioTest`, `OfertaExcluidaNoPortalTest`, `ExclusaoDaEmpresaPreservaHistoricoTest`, `MigracoesDaFaseDetectamMariaDbTest`.
  - Domínio, menu e preço: `DominioLiberaTodoModuloTest`, `PortalSemAnunciarTest`, `AcessoAoModuloEstruturaTest`, `PrecificacaoEstruturaTest`.
  - Autoloader conferido: `ReflectionClass(ProdutoCadastroService)` aponta para este worktree.
- **JS:** `npm run test:js` → 1.114 testes, 1.112 passam. As 2 falhas são exatamente o piso conhecido ("Características secundárias nasce recolhido" e "FASES_TERMINAIS").
- **MariaDB 10.4.32 local, só leitura:** `SHOW CREATE TABLE estrutura_ofertas`, as FKs em `information_schema.REFERENTIAL_CONSTRAINTS`, a contagem 13/0 e a tabela `migrations` (batches 127/128).

---

## 8. Anti-padrões e achados

| Arquivo | Linha | Achado | Gravidade | Impacto |
|---|---|---|---|---|
| arquivos da fase (`app`, `resources`, `routes`, `database`, `config`, `tests`) | — | Nenhum `TBD`, `FIXME`, `XXX`, `TODO`, `HACK` ou `console.log` adicionado | — | — |
| `resources/js/lib/produtosGravacao.js` / servidor | — | Residual do FE-WR-01: se a resposta se perde (504) depois de gravar a 1ª variação de um produto novo num lote único, a nova tentativa diz "código já existe". Precisa de idempotência pela `chave` | ⚠️ Aviso | Nada se perde, mas a mensagem confunde. Decisão do usuário (item 7) |
| `EstruturaProdutos.jsx` / `ProdutoLinhas` (ordem por id, 100 por página) | — | Em catálogo com mais de 100 produtos, o produto novo cai na última página: a volta vai à página 1 e o destaque D-32 não aparece (desvio 1 do 167-21) | ⚠️ Aviso | Decisão do usuário (item 7) |
| `RestringeDominioDoPortal.php` | 131, 135, 137 | `*` sob `variacoes/`, `familias/` e `ambientes/` atravessa `/` | ℹ️ Info | Sem rota correspondente, o router dá 404 |
| `SpreadsheetGrid.jsx`, `gradeTeclado.js`, `sheet.jsx` (`side`) | — | Extensões sem consumidor depois do D-23 (FE-IN-06, fora do escopo de propósito) | ℹ️ Info | Código morto no componente compartilhado |
| `public/build/manifest.json` (local) | — | O build local (15:24) é anterior ao último commit de frontend `98cfc3c7` (15:26) | ℹ️ Info | Não afeta o deploy (a VPS builda). Para conferir no navegador local, rodar `npm run build` de novo |
| importação | — | Até 1.000 linhas, síncrona (decisão registrada no REVIEW-FIX) | ℹ️ Info | — |

---

## 9. Verificação humana necessária

Os 4 primeiros itens vieram do pedido. O 5, o 6 e o 7 são adicionais do verificador.

1. **Contar `estrutura_ofertas` em produção** (só leitura) antes do deploy e registrar o número aqui.
2. **Migrations no deploy com `--path`**, na ordem `100000` → `100100`. O `deploy.sh:70` roda `migrate --force` puro. Se as duas já rodaram, ele não acha nada desta fase pendente.
3. **Frete real** numa conta conectada, uma leitura, só com o "pode" do usuário (D-16). Conferir se o ML respeita as `dimensions` (ressalva A2).
4. **`mimes:xlsx` em produção** com exportações do Google Sheets, do LibreOffice e do Excel Mac (BE-IN-01).
5. **Reconferência visual curta da ficha** depois das correções da revisão. A aprovação visual veio antes dos 38 commits de correção.
6. **Voltar do navegador com alteração não salva** no gesto do Android e no Safari do iOS. O roteiro 26/26 foi só no Chrome 152 de computador.
7. **Decisão:** aceitar os dois limites conhecidos (mais de 100 produtos × destaque do D-32; 504 depois da 1ª variação → "código já existe") ou abrir trabalho próprio.

---

## 10. Conclusão

A fase entregou o objetivo no código. O submódulo Produtos existe e é o 1º do Mapeamento. O cadastro funciona pela
ficha em página inteira e pela importação com prévia. Cada variação tem a sua oferta simples ligada e protegida na Lista
SKUs. O custo do produto chega à Precificação e ao Publicador sem mudar o preço das ofertas antigas. Logística e frete
são calculados só no servidor e nunca gravados como preço.

Os 5 bloqueios da revisão estão corrigidos no código, cada um com teste que passou nesta verificação. O PR167-07 (grade)
saiu por decisão do usuário (D-23) e conta como substituído.

Não há lacuna de código. O status é `human_needed` por causa das verificações de produção (contagem, migrations com
`--path`, frete real e `mimes:xlsx`), das duas conferências de navegador e das duas decisões de limite listadas acima.

---

_Verificado: 2026-10-06T18:38:28Z_
_Verificador: Claude (gsd-verifier)_
