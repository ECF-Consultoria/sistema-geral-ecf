---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
verified: 2026-10-05T12:00:00Z
status: human_needed
score: 20/20 requisitos e 13/13 decisões verificados no código (pendências só pós-deploy, na conta real #459)
overrides_applied: 0
re_verification:
  previous_status: none
gaps: []
warnings:
  - id: WR-BE-01
    sobre: "D-04 / AL166-08"
    texto: "A assinatura da prévia cobre a ENTRADA do navegador (ação, itens, conta, usuário, validade), não o estado que o servidor lê no ML. Se o preço atual ou a entrada na promoção mudar nos 10 min de validade, a escrita sai com o mesmo payload, mas sobre o estado novo, sem aviso. Aberto de propósito (decisão de produto: avisar x recusar), registrado em 166-REVIEW.md. Não bloqueia: a confirmação com resumo existe antes de CADA escrita e as regras locais re-rodam no executar."
    arquivo: "app/Services/Publicador/Alavancas/PreviaAlavancasService.php:96-103,134-150"
  - id: WR-BE-05
    texto: "Sem teto local de desconto em SELLER_CAMPAIGN/DOD/LIGHTNING (só DEAL e PRICE_DISCOUNT validam faixa); o ML recusa e a prévia mostra o % em destaque. Regra de negócio não inventada; aberto no review."
  - id: WR-FE-02
    texto: "Ad Groups: 50 mais clicados filtrados no cliente (só leitura)."
  - id: IN-BE-03
    texto: "Coluna `resumo` da tabela de histórico nunca é gravada (o que foi confirmado fica em `payload`). Sem efeito sobre D-05."
human_verification:
  - test: "Permissão 'Promoções' do app ECF no DevCenter (Questão 5)"
    expected: "Abrir Alavancas da #459 (company-459) e a aba Promoções ler convites sem erro de permissão"
    why_human: "Só se prova com o app e a conta reais, em produção. PENDÊNCIA PÓS-DEPLOY aceita pelo usuário (adiado), não bloqueio do código."
  - test: "A1 — faixas de PxQ e `version` no mesmo GET /items/{id}/prices?display_version=true"
    expected: "A tela mostra as faixas atuais; se não vierem, ALAV-B2B-09/10 e nada é gravado (CR-BE-01 já protege)"
    why_human: "Só há Http::fake/fixtures da documentação. Pendência pós-deploy aceita."
  - test: "A3 — 'quanto a loja recebe' em oferta cofinanciada x Seller Center"
    expected: "Número bate (hoje marcado como estimativa)"
    why_human: "Depende de oferta cofinanciada real. Pendência pós-deploy aceita."
  - test: "A9 e Questão 1 — critério de 'convite aberto' no panorama e forma real de `benefits` dos candidatos"
    expected: "Convites do panorama = convites da aba; linha 'o ML banca' aparece"
    why_human: "Forma real da resposta do ML. Pendência pós-deploy aceita."
  - test: "Roteiro E2E da #459 (.planning/todos/pending/261004-e2e-alavancas-459.md)"
    expected: "Criar e remover um PRICE_DISCOUNT; gravar e rereler 1 faixa de atacado; conta de cliente com botões desligados; histórico OK. Confirmação do usuário antes de CADA escrita."
    why_human: "Escrita na conta real; só depois do deploy autorizado pelo usuário e `queue:restart`."
---

# Fase 166: Alavancas no Publicador — Relatório de verificação

**Objetivo da fase:** no Publicador interno (`/mlb/anuncios`), depois de escolher a empresa, a equipe escolhe entre Publicar (Fase 164) e Alavancas (promoções, cupons, publicidade só leitura, atacado), com ver, analisar e, onde a API permite e a conta está liberada, criar/alterar.
**Verificado em:** 2026-10-05, worktree `C:/tmp/ecf-publicador-spec-261001`, branch `feat/publicador-ml-261001`.
**Status:** `human_needed` — todo o código verificado; sobram apenas as provas com a conta real (#459), já registradas e aceitas como pendência pós-deploy. Nenhum gap de código.

## Suítes (rodadas por mim, saída em arquivo, `exit` logo depois)

| Suíte | Resultado | Exit | Baseline |
|---|---|---|---|
| `tests/Unit/Publicador` | OK, 243 testes / 815 asserções | 0 | 156 -> 243 |
| `tests/Feature/Publicador` | OK, 560 testes / 3224 asserções (1 min 40 s) | 0 | 238 -> 560 |
| `npm run test:js` | 949 testes, 947 passam, 2 falham | 1 | 710 -> 949; as 2 falhas são exatamente as do baseline: `Características secundárias nasce recolhido` e `FASES_TERMINAIS` |

Nenhuma falha nova. (O SUMMARY dizia 550 no Feature; o review adicionou testes depois, e o número real hoje é 560.)

## Requisitos AL166-01..20

| ID | Onde está (conferido no código) | Status |
|---|---|---|
| 01 Barra Publicar \| Alavancas | `resources/js/Components/Mlb/Alavancas/AreaTabs.jsx`; `Pages/Mlb/Publicador/Produtos.jsx` (+5 linhas: import e `<AreaTabs area="publicar">`, `ModoAnuncioTabs` intacto); rota por `empresa-N`/`company-N` | SATISFEITO |
| 02 Rotas no `role:admin`, 404, redirect, sem token | `routes/mlb_anuncios.php:48-101` dentro do grupo `auth,verified,role:admin` (linha 27); `ContextoAlavancas::resolver/daTela`; `AcessoAlavancasTest` | SATISFEITO |
| 03 Panorama com falha isolada por fonte | `PanoramaService.php`; `MlbAlavancasController::panorama`; `PanoramaTest`; `Panorama.jsx` | SATISFEITO |
| 04 Trava própria fail-closed | `app/Support/Publicador/AlavancasLiberadas.php:17-43`; `config/publicador.php:72-80` (env `PUBLICADOR_ALAVANCAS_LIBERADAS_*`, default `459`, sem fallback); `AlavancasLiberadasTest` (os dois sentidos, Company≠MlbEmpresa, vazio, sem fallback) | SATISFEITO |
| 05 Único ponto de escrita | `EscritorAlavancas.php:62-139` (`exigir` antes de HTTP, `conferirVendedor`); `grep daConta(` em `app/` só mostra POST/PUT/DELETE em `EscritorAlavancas` e nos serviços da 164 (publicação); `UnicoCaminhoDeEscritaTest` (token_get_all) | SATISFEITO |
| 06 Histórico PENDENTE antes do HTTP + migration | `EscritorAlavancas::abrirLinha` (44-59) e `enviado_em` antes do envio (97-102); `Authorization` filtrado em `RequisicaoMl::paraHistorico`; migration `2026_10_05_100000_create_pub_alavanca_escritas_table.php` (desenho por escrito, índices nomeados, `nullOnDelete` só em anuláveis, sem enum/change); provada no MariaDB local (baseline); `MigracaoHistoricoTest`, `HistoricoEscritaTest` | SATISFEITO |
| 07 Tela de histórico por empresa | `MlbAlavancasController::historico` (239) e `historicoMostrar` (283) com `daEmpresa` das âncoras do resolver, filtros alavanca/resultado, paginado; `Historico.jsx`, ligado em `Alavancas.jsx:117-122`; `HistoricoTelaTest` | SATISFEITO |
| 08 Prévia + confirmação (assinatura, lote) | `PreviaAlavancasService::previa/confirmar`; `AssinaturaDaPrevia` (HMAC, `hash_equals`, uso único via `Cache::add`, 10 min); lote em `ExecutarLoteAlavancaJob` (fila `high`, `release`, sem `dispatch` recursivo), 1 linha por item; `PreviaEConfirmarTest`, `LoteJobTest`. Ressalva WR-BE-01 (acima) | SATISFEITO (com ressalva) |
| 09 Convites do ML (matriz por tipo) | `Acoes/InscreverNoConvite`, `AlterarNoConvite`, `RemoverDoConvite`, `RemoverDeTodas`; `TiposDePromocao`; `PromocoesLeitura` (`search_after`); `ConvitesMatrizTest`; UI `Promocoes/Convites.jsx`, `ItensDoConvite.jsx` | SATISFEITO |
| 10 Desconto individual PRICE_DISCOUNT | `Acoes/CriarDescontoIndividual`, `RemoverDescontoIndividual`; `RegrasDeDesconto`; `PriceDiscountRegrasTest`, `PriceDiscountTest`; `DescontoIndividual.jsx` | SATISFEITO |
| 11 Campanha do vendedor + VOLUME | `Acoes/CriarCampanha` (SELLER_CAMPAIGN e VOLUME BNGM/BNSP/SPONTH, `allow_combination`), `AlterarCampanha` (started/pending), `ExcluirCampanha`; produtos via `convite.*` com `remove_loyalty`; `CampanhaVendedorTest`; `CampanhasDoVendedor.jsx` | SATISFEITO |
| 12 Lista de exclusão (conta e produto) | `Acoes/GravarExclusaoDaConta`, `GravarExclusaoDoItem`; `ExclusaoTest`; `CampanhasAutomaticas.jsx` | SATISFEITO |
| 13 Cupons (criar/alterar/excluir/produtos) | `Acoes/CriarCupom`, `AlterarCupom` (started só 3 campos, budget só sobe), `ExcluirCupom`; pôr/tirar produto = `convite.inscrever/remover` com `SELLER_COUPON_CAMPAIGN` (`AbaCupons.jsx:12,108-109`); `CuponsLeitura` (`remaining_budget`); `CuponsTest` | SATISFEITO |
| 14 Publicidade só leitura | `PublicidadeLeitura.php` (só `daConta('GET'...)`, `api-version: 2`); `RequisicaoMl.php:26-29` recusa não-GET em `/advertising`; `AbaPublicidade.jsx` sem previa/confirmar/post; `SemEndpointLegadoTest` varre a fonte; `PublicidadeLeituraTest` | SATISFEITO |
| 15 Atacado % B2B | `AtacadoLeitura`, `Acoes/GravarFaixasAtacado` (X-Version relido, `ALAV-B2B-10` quando faixas ilegíveis = CR-BE-01 corrigido, commit `097a6883`), `RegrasDeFaixas`, recomendações por `consultaPorPost` (lista fechada); `grep standard/quantity` em `app/` e `resources/js` = vazio; `AtacadoTest`; `FaixasDoAnuncio.jsx` | SATISFEITO |
| 16 Análise por produto (recebe, ML banca, margem) | `AnaliseAlavancasService` (`recebe_normal`, `recebe_promocao`, tarifa `listing_prices`, frete, margem), `CustoDoAnuncioService` (`EstruturaPrecificacaoService::pagina` linha 71); `TabelaAnalise.jsx`; `AnaliseRecebeTest`, `CustoDoAnuncioTest`. A3 só na conta real | SATISFEITO |
| 17 Alertas sem ranking | `AlertasAlavancas.php` (prazo, recebido, estoque, reputação, ordem fixa, config desliga); `config/publicador.php:84-89`; `AlertasTest` | SATISFEITO |
| 18 Visual no padrão | `tests/js/publicador-alavancas*.test.js` (5 arquivos, gates de tipografia/amarelo/sem Select/sem contador) passam; `ModalConfirmacao` usa botão primário único; conferência visual APROVADA pelo usuário (166-16 Task 3) | SATISFEITO |
| 19 Lista de produtos da conta | `ProdutosDaContaService.php` (items/search + multiget de 20, busca, teto `offset_maximo_produtos`=1000 na linha 36-37, filtro de elegíveis); `ProdutosDaContaTest`; `SeletorDeProdutos.jsx` | SATISFEITO |
| 20 Robustez de erro | `MapeadorErroAlavanca.php` (cause[], cause_id, 409, 423, 429); `EscritorAlavancas::enviar` (423 até 3 envios, 5xx/rede = INCERTO sem repetir, 117); `MapeadorErroAlavancaTest`, `RobustezTest`, `ConfirmarRobustezTest` | SATISFEITO |

Resumo: 20 SATISFEITO, 0 PARCIAL, 0 FALTANDO.

## Decisões D-01..D-13

| D | Honrada? | Evidência |
|---|---|---|
| D-01 Barra Publicar \| Alavancas, duas âncoras | Sim | `AreaTabs.jsx` + `Produtos.jsx`; rota com `where conta (empresa\|company)-N`; Alavancas usa só o token (`ContextoAlavancas`) |
| D-02 Panorama + 4 abas + histórico dentro | Sim | `Alavancas.jsx` (Panorama, abas Promoções/Cupons/Publicidade/Atacado, link "Histórico de alterações" linha 117) |
| D-03 Trava própria fail-closed, separada, default só Company 459 | **Sim** | `AlavancasLiberadas` + config sem fallback; testes nos dois sentidos; servidor recusa em `EscritorAlavancas::executar` (79), em `consultaPorPost` (150), em `confirmar` antes da assinatura (`PreviaAlavancasService` ~124-133, devolve 403 + RECUSADA); botão desligado com o motivo (`ModalConfirmacao.jsx:198,233,264`); leitura segue livre |
| D-04 Confirmação com resumo antes de CADA escrita | **Sim (ressalva WR-BE-01)** | Toda ação da UI passa por `ModalConfirmacao` (prévia -> confirmar; os 8 pontos de `setAlvo` listados por grep); servidor exige assinatura válida de uso único (HMAC) e recusa sem ela; lote lista os produtos. Ressalva: a assinatura não cobre o estado lido no ML |
| D-05 Histórico de escritas, com tela | **Sim** | Tabela `pub_alavanca_escritas` (quem/quando/conta/alavanca/ação/payload/resposta crua/resultado, resposta guardada em erro); tela por empresa `Historico.jsx` + 2 endpoints com paginação, filtros e detalhe |
| D-06 Só admins | Sim | grupo `role:admin` (`routes/mlb_anuncios.php:27`) |
| D-07 As 4 escritas de promoção | Sim | `convite.*`, `desconto.*`, `campanha.*` (incl. VOLUME), `exclusao.*` em `RegistroDeAcoes.php` (15 ações) |
| D-08 Cupons | Sim | `cupom.criar/alterar/excluir` + produtos; saldo em `CuponsLeitura`/`Panorama` |
| D-09 Publicidade só leitura | **Sim** | `RequisicaoMl` lança exceção para não-GET em `/advertising`; `PublicidadeLeitura` só GET; nenhuma ação registrada toca publicidade; aba sem escrita; endpoints v2 e sem legados (teste de fonte) |
| D-10 Atacado ver/editar % B2B | Sim | `AtacadoLeitura` (tag `business`), `GravarFaixasAtacado`; sem `/prices/standard/quantity` |
| D-11 Números por produto | Sim | `AnaliseAlavancasService` + `CustoDoAnuncioService` (margem só com custo) |
| D-12 Alertas simples | Sim | `AlertasAlavancas` + config |
| D-13 Padrão visual do Publicador | Sim | gates JS passam; conferência visual aprovada pelo usuário |

## Must-haves dos 16 PLANs

- `gsd-sdk verify.artifacts`: todos passam, exceto 166-13 `ModalConfirmacao.jsx` "Missing pattern: escritas.confirmar" — falso negativo: a rota é montada por `rota('escritas.confirmar', ...)` em `useAlavancas.js:53` (`ModalConfirmacao` importa `confirmar` de lá).
- `verify.key-links`: as falhas reportadas são regex inválido da ferramenta (`->pagina\(`, `catch \(\\Throwable`) ou padrão com ponto não escapado; conferi cada uma à mão: `PanoramaService` (try/catch por fonte), `CustoDoAnuncioService:71` (`->pagina(`), `InscreverNoConvite:58,74` (`leituras()->produto/entrada`), `ItensDoConvite.jsx` e `AdicionarProdutos.jsx:67` (`convite.inscrever`), `FaixasDoAnuncio.jsx:66` (`atacado.recomendacoes`), rotas `publicador/empresas/{conta}/alavancas` em `routes/mlb_anuncios.php:50`. Todos ligados. 166-16: o link "antes -> depois" é a coluna "Depois (166-16)" do `166-BASELINE-TESTES.md`, preenchida.
- Truths conferidas por leitura de código (todas de 166-01, 166-03, 166-11, 166-16 e amostragem forte nos demais): 166-01 (trava separada, 459 por default, vazio = ninguém, tabela com SET NULL só em anuláveis, índices <= 64); 166-03 (caminho único por teste de fonte, RECUSADA sem HTTP, V-ACC-03, PENDENTE+`enviado_em` antes do HTTP, sem `Authorization`, 5xx/rede INCERTO, 423 até 3, assinatura de uso único); 166-11 (previa sem escrita, 403 para trava, 409 para assinatura usada, lote em linhas PENDENTE + job `high` com `release(15)`, `daLinha`); 166-16 (baseline lado a lado, migration provada com `--path`, conferência visual aprovada, prova #459 adiada com roteiro, learnings §12 existente). 166-REVIEW: 3 críticos e 13 warnings corrigidos com commit e teste (`097a6883`..`61957134`); abertos só WR-BE-01, WR-BE-05, WR-FE-02 e infos.

## Anti-padrões

Nenhum `TBD/FIXME/XXX` bloqueante encontrado nos arquivos da fase em amostragem; `git status` do worktree limpo. Observação (info): `resumo` do histórico nunca gravado (IN-BE-03).

## Pendências pós-deploy (não bloqueiam o código)

Ver `human_verification` no frontmatter: permissão "Promoções" do app, A1, A3, A9/Questão 1 e o roteiro E2E da #459, todos no todo `261004-e2e-alavancas-459.md`, adiados pelo usuário. Antes do deploy: conferir no `.env` de produção que `PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES=459` (ou ausente) e fazer `queue:restart` depois (lote na fila `high`). Deploy só com autorização explícita.

---
_Verificado: 2026-10-05_
_Verificador: Claude (gsd-verifier)_
