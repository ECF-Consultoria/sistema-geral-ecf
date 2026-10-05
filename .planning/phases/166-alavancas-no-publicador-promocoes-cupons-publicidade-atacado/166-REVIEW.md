---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
reviewed: 2026-10-05
depth: standard
files_reviewed: 76
status: issues_found
fixed: 16
open: 13
findings:
  critical: 3
  warning: 15
  info: 11
  total: 29
---

# Fase 166 — Code Review

Revisão em duas partes paralelas (escopo de 121 arquivos passava do limite de 50 de um revisor só; testes ficaram
fora do escopo): **backend** (51 arquivos PHP: controllers, job, model, serviços e ações das Alavancas, diff do
`ClienteMlPublicador`, `config/publicador.php`, migration e rotas) e **frontend** (25 arquivos React da área
Alavancas + diff do `Produtos.jsx`). Base do diff: `2a279068`.

| Parte | Critical | Warning | Info |
|---|---|---|---|
| Backend | 1 (CR-BE-01) | 5 | 6 |
| Frontend | 2 (CR-FE-01, CR-FE-02) | 10 | 5 |

# Parte 1 — Backend


# Fase 166 — Revisão de código, parte 1/2 (backend)

**Escopo:** 51 arquivos PHP, profundidade standard, foco em dano à conta ML do cliente.

## Resumo

O núcleo de segurança está sólido. Conferi e não achei falha em:

- **Trava `AlavancasLiberadas`:** avaliada no `EscritorAlavancas::executar` (síncrono e job) e em `consultaPorPost`. Também em `confirmar` antes da assinatura. É reavaliada quando a lista muda entre prévia e confirmação, e fail-closed.
- **Conta e IDOR:** a conta vem sempre do `resolver`, nunca do corpo. Histórico e lote filtrados por `daEmpresa`.
- **Assinatura HMAC:** cobre ação, itens, chaveTela, usuário e expiração. Usa `hash_equals`. É de uso único por `Cache::add` com TTL maior que a validade.
- **`offer_id`:** sempre da leitura do servidor.
- **Publicidade:** só leitura; `RequisicaoMl` recusa não-GET em `/advertising`.
- **Histórico e logs:** `Authorization` fora do histórico e dos logs.
- **Ordem da escrita:** PENDENTE antes do HTTP, `enviado_em` antes do envio, 5xx e rede viram INCERTO sem repetir, 423 até 3 envios, reentrega do job vira INCERTO.
- **Job de lote:** em fatias com `release`, sem `dispatch` dentro do `handle`.
- **Migration:** nomes ≤ 64, sem enum, sem `->change()`, `nullOnDelete` só em coluna anulável, tipos de FK compatíveis (`id()` nas três tabelas).

Os problemas reais estão em três pontos: o atacado, que escreve às cegas quando a leitura das faixas é desconhecida; a prévia, que não assina o que o servidor resolveu; e a robustez do caminho de lote.

## Critical Issues

### CR-BE-01: Atacado grava às cegas e APAGA faixas existentes quando a leitura das faixas é desconhecida (A1 não conferida)

**File:** `app/Services/Publicador/Alavancas/Acoes/GravarFaixasAtacado.php:61-110` (`carregar`) e `app/Services/Publicador/Alavancas/AtacadoLeitura.php:68-83`

**Issue:** `AtacadoLeitura::faixas()` devolve `faixas => null` e `aviso` quando o `GET /items/{id}/prices` não traz a chave `price_per_quantity` e o anúncio tem a tag `standard_price_by_quantity`. O learnings §12 diz que A1 (faixas e `version` no mesmo GET) NÃO foi conferida; só há `Http::fake`. Esse é justamente o caminho que a produção pode seguir.

`carregar()` nunca olha `$leitura['faixas'] === null`. Ele faz `(array) ($leitura['faixas'] ?? [])`, então `$atuais = []`.

Cenário:
1. O anúncio tem 3 faixas em % B2B e a resposta não traz a chave.
2. A pessoa adiciona uma 4ª faixa. O formulário não tem as 3 existentes, ou todas as faixas com `id` caem em `ALAV-B2B-08`.
3. Se a tela mandar só a faixa nova (sem `id`), `resumo()` mostra "Faixas que saem" vazio, porque `$this->leitura['faixas']` é null.
4. O POST vai com `price_per_quantity: [nova]`. O ML trata faixa omitida como excluída (a docblock da própria ação diz isso) e apaga as 3 existentes.
5. `X-Version` não protege, porque foi relida logo antes e é a versão atual.

Dano: perda silenciosa de preços de atacado da conta do cliente. O histórico mostra `OK`.

**Fix:** em `carregar()`, logo após ler:
```php
if ($leitura['faixas'] === null) {
    throw new RegraViolada('ALAV-B2B-10', 'Não consegui ler as faixas atuais deste anúncio no Mercado Livre. Nada será enviado: gravar às cegas apagaria as faixas existentes.');
}
```
Além disso, faça a prova real de A1 na #459 antes de liberar o atacado em qualquer outra conta.

## Warnings

### WR-BE-01: A assinatura da prévia não cobre o que o servidor leu/resolveu (D-04 não se cumpre integralmente)

**File:** `app/Services/Publicador/Alavancas/PreviaAlavancasService.php:96-103` e `:134-150`; `AssinaturaDaPrevia.php:24`

**Issue:** a assinatura é feita sobre `canonico($acao, $normalizados)`, ou seja, só a ENTRADA do navegador. A docblock da classe e a de `AssinaturaDaPrevia` dizem "a prévia assina o que vai ao ML; confirmar recomputa e compara", mas o `confirmar` não recomputa nada além da forma da entrada. A prévia mostra "100 → 85 (15%)", "ML banca R$ x" e "recebe R$ y", que dependem do preço atual, do `offer_id`, da faixa e do status lidos no ML. Esses valores não entram no HMAC.

Se o preço do anúncio ou a entrada na promoção mudar nos 10 minutos de validade, a escrita sai com o mesmo `deal_price`, mas com um desconto, uma faixa ou um estado diferente do que foi mostrado e confirmado. As regras locais re-rodam no `executar`, mas só barram o inválido, não o diferente do que a pessoa viu.

**Fix:** na prévia, calcule por item um digest do que a escrita fará: `escrita()` (método, caminho, query, corpo) mais `preco_atual`. Inclua o digest no canônico assinado. No `confirmar`, reconstrua as ações, rode `validar()`/`escrita()` e compare o digest ANTES de queimar a assinatura. Divergiu: 409 "a situação mudou, refaça a conferência". Alternativa mínima: assinar `preco_atual`, `preco_promocao` e `offer_id` do resumo.

### WR-BE-02: Lote não é atômico: linhas PENDENTE órfãs e assinatura queimada se `abrirLinha`/`dispatch` falhar no meio

**File:** `app/Services/Publicador/Alavancas/PreviaAlavancasService.php:156-164`; `EscritorAlavancas.php:49-70`

**Issue:** `confirmar` queima a assinatura (`Cache::add`) e depois faz `foreach abrirLinha` (N inserts) e `ExecutarLoteAlavancaJob::dispatch`, sem transação e sem tratamento. Falhas plausíveis:

- **`item_id` maior que a coluna:** a regra `regex:/^MLB\d+$/` aceita `\d+` sem limite, mas a coluna é `string(20)` (máx. MLB + 17 dígitos). Um MLB digitado com 18+ dígitos dá erro 1406 no MariaDB estrito, no meio do laço.
- **Chaves extras:** `promotion_id` ou `promotion_type` com mais de 40 caracteres, vindos do corpo, chegam em ações que não declaram essa regra (ver WR-BE-03).
- **Fila fora do ar:** o `dispatch` falha (Redis).

Resultado: o controller devolve 502 "Nada foi confirmado", mas as linhas já criadas ficam PENDENTE para sempre, sem job. `lote()` nunca fica `terminado` e o histórico mostra pendências fantasma. A assinatura já está queimada, então a pessoa precisa refazer a prévia. No caminho `unico` o `abrirLinha` também vira 502 sem linha.

**Fix:**
- Envolva o laço de `abrirLinha` em `DB::transaction`.
- Faça o `dispatch` com `->afterCommit()` ou dentro de try/catch que, na falha, marque as linhas do lote como RECUSADA (`ALAV-LOTE-FILA`).
- Limite o tamanho no `validarForma`: `regex:/^MLB\d{1,17}$/D`.
- Use `Cache::forget` da assinatura se nada foi gravado.

### WR-BE-03: Chaves não declaradas em `regras()` atravessam a validação e vão para o histórico e para colunas `string(40)`

**File:** `app/Services/Publicador/Alavancas/PreviaAlavancasService.php:204-220` (`validarForma` guarda `$item` inteiro, não `$v->validated()`); `Acoes/AcaoAlavanca.php:56-64`

**Issue:** `validarForma` valida com `Validator::make`, mas empilha o `$item` ORIGINAL em `$validos`. O `dados` da ação, o `payload.dados` do histórico e o canônico assinado carregam qualquer chave extra enviada.

- `promotionType()`/`promotionId()` lêem direto de `dados`. Em ações sem essa regra (`atacado.gravar`, `exclusao.item`, `desconto.remover`, `convite.remover_todas`), um `promotion_id` ou `promotion_type` de 41+ caracteres estoura a coluna em `abrirLinha`, com os efeitos do WR-BE-02.
- Há inchaço do JSON do histórico, que é "resposta crua e payload", com lixo do cliente.

Não é escalação de privilégio: `offer_id` do corpo continua ignorado e a conta não vem do corpo. É robustez e integridade do histórico.

**Fix:** use `$validos[] = $v->validated();`, mantendo só o declarado. Para as ações que usam `promotion_*` sem regra, declare a regra ou faça `promotionType()`/`promotionId()` retornarem null nelas.

### WR-BE-04: `normalizar()` converte strings numéricas em float em qualquer campo (código de cupom/nome mudam)

**File:** `app/Services/Publicador/Alavancas/PreviaAlavancasService.php:173-185`

**Issue:** `is_string($v) && is_numeric($v) && ! ctype_digit($v)` vira `round((float) $v, 2)` em QUALQUER campo. `partial_coupon_code` com `alpha_num` (validado ANTES da normalização) aceita `1E3`, `12E5` etc., que é numérico em PHP. Ele vira `1000.0` e é enviado ao ML como `"1000"`; `CriarCupom::escrita` faz `trim((string) ...)`. O resumo mostra o código mutado, mas ele não é o que a pessoa digitou. Também afeta `name` ("1.5", "2e3") e `faixas.*.id`. Casos de borda, mas a escrita num cupom com o código errado é irreversível para quem já divulgou o código.

**Fix:** normalize só campos numéricos conhecidos (lista por ação, ou `$classe::CAMPOS_DINHEIRO`), ou normalize só `is_float`/`is_numeric` quando a regra do campo for `numeric`. Nunca strings de `name`, `*_code`, `id`.

### WR-BE-05: Sem teto local do `deal_price` em SELLER_CAMPAIGN, DOD, LIGHTNING e no alterar (só DEAL e PRICE_DISCOUNT têm)

**File:** `app/Services/Publicador/Alavancas/Acoes/InscreverNoConvite.php:62-100`; `AlterarNoConvite.php:76-103`

**Issue:** só o DEAL (faixa min/max) e o desconto individual (`RegrasDeDesconto`) têm checagem local de valor. Para SELLER_CAMPAIGN, DOD e LIGHTNING (e para o `AlterarNoConvite` de SELLER_CAMPAIGN pendente), `deal_price > 0` é a única regra. Um erro de dígito (R$ 5,00 em vez de R$ 50,00) passa na prévia, e a confirmação mostra o % como informação. Só o ML barraria, e a doc que o próprio código cita não garante limite de desconto em todos esses tipos.

Dinheiro do cliente, escrita irreversível numa campanha em andamento.

**Fix:** para tipos `INSCREVE_COM_PRECO` sem faixa do ML, exija `deal_price < preço atual` e emita aviso forte (ou bloqueio, ex. desconto ≥ 80% = `ALAV-CONV-12`) usando `RegrasDeDesconto::percentual`. Se o `min_preco` do candidato existir, aplique-o a todos os tipos, não só ao DEAL.

## Info

### IN-BE-01: `regex:/^MLB\d+$/` e `[A-Za-z0-9-]{1,40}$` aceitam `\n` final

**File:** `Acoes/*.php` (`regras()`), `PromocoesLeitura.php:19/230`, `AtacadoLeitura.php:156`, `MlbAlavancasController.php:~140,205`

**Issue:** `$` do PCRE casa antes de um `\n` final: `"MLB123\n"` passa. O valor vai ao caminho via `rawurlencode` (`%0A`), então não há injeção, o ML devolve 404. Mas o valor sujo é gravado no histórico e no canônico.

**Fix:** use `$/D` (ou `\z`) nas regex de ids.

### IN-BE-02: Cache de leitura não é invalidado em INCERTO

**File:** `EscritorAlavancas.php:106-125`

**Issue:** `cache->invalidar` só roda em OK. Em INCERTO (5xx/timeout) a escrita pode ter saído, e a tela mostra por até 60–300 s (`conta`, `itens_promocao`, `produtos`) o estado antigo, justo quando a mensagem manda "confira o estado".

**Fix:** invalide também em INCERTO.

### IN-BE-03: Coluna `resumo` do histórico nunca é gravada

**File:** migration `2026_10_05_100000...` (coluna `resumo json`); `EscritorAlavancas::abrirLinha`

**Issue:** D-05 e a docblock da migration dizem que `resumo` guarda "o que a pessoa confirmou", mas nenhum caminho o preenche (`historicoMostrar` devolve sempre null). O histórico perde o "o que foi mostrado" (preço atual, % etc.), que seria a prova do que a pessoa viu (relacionado ao WR-BE-01).

**Fix:** passe o `resumo()` da prévia, ou recompute no `abrirLinha`, e grave. Ou remova a coluna antes de a migration ir a produção.

### IN-BE-04: `GravarFaixasAtacado` não confere que o anúncio é da conta; faixas lidas e `X-Version` vêm de dois GETs

**File:** `Acoes/GravarFaixasAtacado.php:61-110, 169-175`

**Issue:** todas as outras ações validam o item com `leituras()->produto()` (`seller_id` igual ao da conta). O atacado não: lê `/items/{id}/prices` (público) e só o ML recusaria o POST em item alheio. É defesa em profundidade.

Além disso, a decisão de manter ou recriar cada faixa usa a leitura de `validar()`, e a `X-Version` vem de um segundo GET no `preparo()`. Uma mudança entre os dois GETs (janela pequena) passa com a versão nova e a decisão velha, anulando o objetivo do `X-Version`.

**Fix:** chame `leituras()->produto($item)` em `carregar()`. Em `aplicarPreparo`, recompute `finais` a partir da MESMA resposta que traz a versão, ou compare as faixas lidas ali com `$this->leitura`.

### IN-BE-05: 403 do ML é sempre traduzido como "app sem permissão"; `ExcluirCupom`/`ExcluirCampanha` não conferem tipo/status

**File:** `MapeadorErroAlavanca.php:~74-81`; `Acoes/ExcluirCupom.php:33-41`; `Acoes/ExcluirCampanha.php:33-41`

**Issue:** qualquer 403 em `/seller-promotions` vira "O aplicativo ECF não tem a permissão de Promoções no DevCenter", mesmo quando o 403 é de elegibilidade, reputação ou item alheio, o que engana a pessoa.

`ExcluirCupom` e `ExcluirCampanha` só conferem que a leitura da promoção respondeu. Não conferem `type` igual ao pedido nem `status`. Uma campanha em andamento é excluída sem aviso destacado no resumo.

**Fix:** use o `cause`/`message` do ML quando houver, antes do texto fixo. Em `validar()`, confira `type`/`status` e avise "campanha ATIVA" no resumo.

### IN-BE-06: Pontos menores

- **Escrita lenta no pedido HTTP:** o laço de 423 mais os retries 429 do cliente (5 × até 16 s) podem passar de 1 min num único `confirmar` síncrono. Considere `set_time_limit` ou mandar sempre para o job.
- **Cabeçalho do histórico:** `RequisicaoMl::paraHistorico` filtra só `Authorization`; hoje os cabeçalhos são constantes, então não há segredo. Mantenha assim.
- **`config('mlb_acervo.lote_multiget', 20)`:** se alguém subir esse valor, o multiget do ML (máx. 20) quebra `porIds`. Fixe `min(20, ...)`.
- **Domínio da chave HMAC:** `AssinaturaDaPrevia::hmac` usa `app.key` sem prefixo de domínio. Prefixe `'alavancas-previa|'` para não colidir com outros usos do `app.key`.
- **Job:** o job não revalida que o usuário continua admin ao processar as fatias. A confirmação já era admin, então só importa se o cargo for revogado no meio.
- **`ml_api_base`:** o desvio de host depende de `APP_ENV=production`. Um ambiente de staging com tokens reais e a env setada mandaria o token a outro host.


_Reviewed: 2026-10-05_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

# Parte 2 — Frontend


# Fase 166 — Revisão do FRONTEND (parte 2/2)

**Profundidade:** standard. Contratos conferidos contra `MlbAlavancasController`, `PreviaAlavancasService`, `AnaliseAlavancasService`, `PromocoesLeitura`, `CuponsLeitura`, `PublicidadeLeitura`, `AtacadoLeitura`, `ProdutosDaContaService`, `PanoramaService`, `TiposDePromocao::capacidades` e as `resumo()` das ações.

## O que foi conferido e está certo (não precisa mexer)

- `TabelaAnalise` lê `recebe_normal.voce_recebe` e `recebe_promocao.voce_recebe`: correto, a análise manda o objeto do `SimuladorVoceRecebe` (com `frete_conhecido`). O descompasso do `dfef3257` era só da prévia (`recebe.normal` número), e o `LinhaRecebe` já está certo.
- Campos de `capacidades` (`inscrever/preco/pede_estoque/alterar/remover/motivo`), `produtos` (`id/titulo/preco/estoque/thumbnail/sku/elegivel/motivos`), `cupons`, `atacado.item` (`faixas[].id/percentual/quantidade_minima`), `recomendacoes[]` (`quantidade/percentual/incoerente`), `historico` e `lotes/{uuid}` batem com o servidor.
- Trava (D-03): todo botão que abre `ModalConfirmacao` tem `disabled={! liberada}`, e o modal só habilita Confirmar com `dados.assinatura` presente (conta não liberada recebe `assinatura:null`). O servidor ainda recusa antes da assinatura. Conta não liberada vê e analisa; a análise (POST `analise`) não depende da trava.
- Reenvio: `enviar` troca `fase` para `enviando` (botão desabilitado e modal não fecha); 409 `ALAV-ASSIN-USADA` nunca reenvia. Prévia com `vivo` (resposta velha descartada). `useLeitura` descarta resposta velha por contador. `useLote` limpa o intervalo no cleanup.
- Sem `dangerouslySetInnerHTML`; texto do ML entra como texto React; `payload/resposta` no histórico vão em `<pre>` via `JSON.stringify`.
- O que vai na escrita vem do servidor onde importa: `item_id`/`promotion_id` da lista lida, sem `offer_id` no cliente (o servidor o lê); `alvo.itens` é fotografia no clique, não estado vivo.

## Critical Issues

### CR-FE-01: Resultado da escrita (inclusive ERRO e INCERTO) some na hora em cupom e atacado

**Arquivos:** `Cupons/FormCupom.jsx:93-96` + `AbaCupons.jsx:26-29`; `Atacado/FaixasDoAnuncio.jsx:98-101`
**Cenário:** o `ModalConfirmacao` chama `onConcluido` assim que a escrita volta, também quando `escrita.resultado` é `ERRO` ou `INCERTO` (`ModalConfirmacao.jsx` `enviar`, logo após `setFase('resultado')`). Nesses três pontos o `onConcluido` desmonta o próprio modal:
- `FormCupom.aoConcluir` faz `setAlvo(null)` e depois `AbaCupons.aoConcluir` faz `setForm(null)` (o modal vive dentro do `FormCupom`);
- `FaixasDoAnuncio.aoConcluir` faz `setAlvo(null)`.

O operador cria cupom ou grava faixas, o Mercado Livre recusa (ou não confirma), a janela some, o formulário fecha e a lista recarrega: ele nunca lê "Recusado pelo Mercado Livre: ..." nem "Sem confirmação: confira o estado antes de tentar de novo". Parece que deu certo. No INCERTO é pior: a orientação de conferir antes de repetir não é mostrada. (Nas outras telas, `ItensDoConvite`, `CampanhasDoVendedor`, `DescontoIndividual`, `AdicionarProdutos`, `CampanhasAutomaticas` e `AbaCupons` no excluir, o modal é do pai e não é desmontado; só nesses três fluxos.)
**Fix:** `onConcluido` só recarrega dados; quem fecha é o "Fechar" do usuário. Em `FormCupom`: `function aoConcluir(r) { onConcluido?.(r); }` e, no `AbaCupons`, não chamar `setForm(null)` no `aoConcluir` (mover o fechamento do formulário para o `onFechar` do modal, só quando `resultado.resultado === 'OK'`). Em `FaixasDoAnuncio`: `function aoConcluir() { recarregar(); }`.

### CR-FE-02: Datas "Y-m-d" aparecem com um dia a menos na prévia (fmtData em UTC)

**Arquivos:** `formato.js:19-27` (`fmtData`), usado em `ModalConfirmacao.jsx:68`
**Cenário:** `CriarCampanha`, `CriarCupom` e `CriarDescontoIndividual` devolvem `prazo.inicio/fim` como `Y-m-d` puro (o que o formulário enviou). `new Date('2026-10-05')` é meia-noite UTC e `toLocaleString` com `timeZone: 'America/Sao_Paulo'` mostra `04/10/2026`. Quem cria um desconto de 05/10 a 18/10 lê "Prazo: 04/10/2026 até 17/10/2026" na janela que existe justamente para conferir antes de confirmar. O dado enviado está certo; a conferência mente.
**Fix:** em `fmtData`, tratar data pura sem fuso: `if (/^\d{4}-\d{2}-\d{2}$/.test(valor)) { const [a, m, d] = valor.split('-'); return `${d}/${m}/${a}`; }` (se `hora`, acrescentar `00:00` ou ocultar). Para strings ISO sem fuso (`2026-10-05T23:59:59`, que a própria `DatasDoMl` diz existir), interpretar como horário de São Paulo, não do navegador (acrescentar `-03:00` quando não houver `Z`/offset).

## Warnings

### WR-FE-01: "Convites do Mercado Livre" lista tudo que `/promocoes` devolve (campanhas do vendedor, cupons, encerradas)

**Arquivo:** `Promocoes/Convites.jsx:22-24,38`
**Cenário:** `GET promocoes` devolve todas as promoções da conta; o `Panorama` filtra por `CONVITES_DO_ML` e descarta vencidas/encerradas (`PanoramaService::convitesAbertos`), mas `Convites.jsx` mostra `dados.itens` inteiro. O cartão "Convites abertos: 3" e a lista "Convites do Mercado Livre" divergem, aparecem `SELLER_CAMPAIGN` e `SELLER_COUPON_CAMPAIGN` (que já têm aba/seção própria) e promoções `finished`, que dá para abrir e tentar inscrever.
**Fix:** filtrar na tela (ou o servidor aceitar `?so_convites=1`): excluir `SELLER_CAMPAIGN`, `SELLER_COUPON_CAMPAIGN`, `PRICE_DISCOUNT` e `status` encerrado/`dias_para_vencer < 0`, espelhando `convitesAbertos`.

### WR-FE-02: Grupos de anúncios: filtra 50 no cliente e diz "Nenhum grupo" quando o ML só não veio nos 50

**Arquivo:** `AbaPublicidade.jsx:22-26`
**Cenário:** `publicidade/ad-groups` é chamado sem `itens` e o servidor pede ao ML `limit=50 ... sort_by=clicks` (conta inteira). A tela filtra `campanha_id` no cliente. Campanha cujos grupos ficaram fora dos 50 mais clicados (e "Fora de campanha", com cliques baixos) mostra "Nenhum grupo de anúncios nesta janela", afirmação falsa.
**Fix:** mostrar aviso de truncamento quando `todos.length >= 50` ("Mostrando só os 50 grupos com mais cliques"), ou o servidor aceitar `campaign_id` na consulta. No mínimo trocar o texto vazio por "Nenhum grupo entre os 50 mais clicados".

### WR-FE-03: Troca de período mostra números do período antigo sem sinalizar

**Arquivo:** `AbaPublicidade.jsx:66-88`
**Cenário:** o `useLeitura` mantém `dados` durante a nova leitura e a tela só mostra "Carregando…" quando `! dados`. Trocar "Últimos 30" para "Últimos 7 dias" mantém investimento, vendas e ACOS de 30 dias sob o rótulo de 7 até a resposta chegar (e se falhar, `dados` vira null e aparece só o erro). Quem decide lê o número errado com o rótulo certo. O mesmo vale para `AdGroups`.
**Fix:** mostrar o bloco com opacidade/“Atualizando…” enquanto `carregando`, ou limpar `dados` ao trocar `dias`; mostrar `dados.de`/`dados.ate` junto dos números.

### WR-FE-04: Bonificações: lista inclui as inativas, total soma só as ativas

**Arquivo:** `AbaPublicidade.jsx:131-146` (servidor: `PublicidadeLeitura::bonificacoes`)
**Cenário:** `saldo_total` soma só `status === 'ACTIVE'`, mas `itens` traz todas, e a tela lista todas com "saldo R$ X" sem mostrar o status; "Nenhuma bonificação ativa" nunca aparece se houver uma vencida. A soma das linhas não bate com "Saldo total".
**Fix:** filtrar `b.status === 'ACTIVE'` na lista (ou exibir o status por linha).

### WR-FE-05: Fechar o modal durante um lote deixa a tela velha (e `esgotou` nunca atualiza)

**Arquivo:** `ModalConfirmacao.jsx:98-102,166` (`concluir` só roda quando `lote.dados.terminado`)
**Cenário:** inscrever 30 produtos gera lote. O usuário fecha a janela (Esc, Cancelar) antes de terminar: o polling para (cleanup do `useLote`), `onConcluido` não é chamado, a lista de candidatos e as seleções ficam como antes, mostrando como candidatos produtos que já foram inscritos. Mesmo se esperar mais de 4 minutos (`esgotou`), `concluir` nunca é chamado. Fechar o modal em `fase === 'lote'` também permite começar nova escrita sobre os mesmos itens.
**Fix:** chamar `concluir(lote.dados ?? null)` também no fechamento durante `lote` e quando `lote.esgotou`; (opcional) bloquear o fechamento por Esc/clique fora enquanto `fase === 'lote' && ! terminado`, deixando só o botão explícito.

### WR-FE-06: Inclusão de produtos não recarrega a lista de itens aberta ao lado

**Arquivos:** `CampanhasDoVendedor.jsx:258-268`, `AbaCupons.jsx:104-114`, `AdicionarProdutos.jsx:35-39`
**Cenário:** `AdicionarProdutos` não recebe `onConcluido` do pai. Depois de incluir, o `ItensDoConvite` da mesma campanha/cupom continua com os produtos recém-incluídos como candidatos (checkbox marcável); marcar de novo e inscrever gera erro do ML ou escrita redundante. O cache do servidor já foi invalidado, falta só o refetch.
**Fix:** subir um contador `versao` no pai (`onConcluido={() => setVersao((n) => n + 1)}`) e usar `key={`${c.id}-${versao}`}` no `ItensDoConvite`, como `DescontoIndividual` já faz com `versao`.

### WR-FE-07: `dia()` corta o ISO em UTC; campanha/cupom com data `Z` abre com o dia errado

**Arquivos:** `Cupons/FormCupom.jsx:18,41-42`, `Promocoes/CampanhasDoVendedor.jsx:22,56-57`
**Cenário:** `DatasDoMl` documenta que o ML devolve datas ora com `Z`, ora `-03:00`, ora sem fuso. `String(iso).slice(0,10)` de `2026-10-18T02:59:59Z` dá `2026-10-18`, mas o dia em São Paulo é 17. O formulário de alterar abre com "Fim 18/10" (um dia a mais). Se a pessoa não mexe na data, não é enviada (compara com o mesmo `dia()`), mas se ela ajusta com base no valor exibido, o prazo sai um dia além do desejado.
**Fix:** converter para o dia de São Paulo: `new Date(iso).toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' })` (com tratamento do ISO sem fuso como SP). Colocar `diaSP()` em `formato.js` e usar nos dois lugares.

### WR-FE-08: Validação do período do cupom é "diferença de datas", o servidor conta inclusive (erro de 1 em cada ponta)

**Arquivo:** `Cupons/FormCupom.jsx:48,88` (servidor: `CriarCupom::validar`, `DatasDoMl::diasInclusivos`)
**Cenário:** o servidor exige 1 a 31 dias inclusivos. A tela usa `fim - inicio` em dias e exige 1..31: (a) cupom de 1 dia (início = fim) fica com "Revisar" desabilitado, mas o servidor aceitaria; (b) `fim = inicio + 31` passa na tela (32 dias inclusivos) e só é recusado na prévia com `ALAV-CUP-02`.
**Fix:** `const dias = ... + 1;` mantendo `dias >= 1 && dias <= 31`.

### WR-FE-09: Parser de dinheiro trata "1.500" como 1,5

**Arquivos:** `ItensDoConvite.jsx:20-27`, `AdicionarProdutos.jsx:10-17`, `DescontoIndividual.jsx:11-18`, `FormCupom.jsx:7-14`, `CampanhasDoVendedor.jsx` (`decimal`)
**Cenário:** sem vírgula, o ponto é decimal. Em pt-BR quem digita "1.299" quer R$ 1.299,00; a tela envia 1,299. Para preço de promoção (DEAL, SELLER_CAMPAIGN), orçamento e compra mínima de cupom, o valor vira 1000x menor, e fora do desconto individual (que o servidor limita a 5–80%) nada o barra além de o operador reparar na prévia. Em orçamento de cupom, "1.000" vira R$ 1,00 e o cupom nasce com orçamento mínimo.
**Fix:** tratar ponto seguido de exatamente 3 dígitos e sem vírgula como milhar (`/^\d{1,3}(\.\d{3})+$/`), ou recusar a entrada ambígua (`null`) e mostrar o valor interpretado ao lado do campo ("= R$ 1.299,00"). Uma função única em `formato.js` em vez de cinco cópias.

### WR-FE-10: `useLote` dispara leituras sobrepostas e uma resposta velha pode desfazer `terminado`

**Arquivo:** `useAlavancas.js:63-87`
**Cenário:** `setInterval(ler, 2500)` não espera a leitura anterior. Com o servidor lento (fila `high`, 502), duas leituras ficam em voo; a mais antiga pode chegar depois da que trouxe `terminado: true` e sobrescrever o estado com `terminado: false`: o modal volta a "Cancelar", mostra contagem antiga e a leitura segue sem `clearInterval` correto (o `clearInterval` já tinha sido chamado, mas o estado fica errado). Além disso, em erro persistente (404/500) o polling continua 4 minutos.
**Fix:** encadear com `setTimeout` após o `finally` (sem sobreposição), ou ignorar resposta com número de sequência menor que a última aplicada; parar o polling em 404/403.

## Info

### IN-FE-01: `key` nula/duplicada no resumo do modal
**Arquivo:** `ModalConfirmacao.jsx:75,161`. `resumo.itens` de campanha/cupom/exclusão/atacado têm `item_id: null` (`key={item.item_id}`), e `RemoverDeTodas` repete `rotulo` por tipo (duas SELLER_CAMPAIGN dão `key={l.rotulo}` duplicada). Hoje são itens únicos e o efeito é só aviso do React, mas a prévia de tirar de todas as promoções com duas do mesmo tipo pode omitir/duplicar uma linha na renderização. Usar `key={`${i.item_id ?? 'x'}-${idx}`}` e `${l.rotulo}-${idx}`.

### IN-FE-02: `title` explicando a trava nunca aparece
`BotaoAcao` usa `disabled:pointer-events-none`, então o `title={motivo}` dos botões desabilitados não mostra tooltip. O aviso de "não liberada" está na faixa da página, então não há perda de informação crítica; considerar envolver o botão num `<span title>`.

### IN-FE-03: Análise/prévia sem número ficam mudas sobre o porquê
`TabelaAnalise.jsx:74-76` mostra "—" quando `recebe_normal` é null (tarifa não lida) e não exibe `i.avisos`; `ModalConfirmacao.LinhaRecebe` não mostra nada quando `normal` é null e ignora `recebe.erro`/`calculado:false`. Exibir `i.avisos` e uma linha "Não foi possível calcular quanto a loja recebe".

### IN-FE-04: "Carregar mais" desaparece após erro
`ItensDoConvite.jsx:243`: se a leitura com cursor falhar, `dados` vira null, `proximo` some e não há como retentar (só trocando o filtro). Guardar o último `proximo` em estado e oferecer "Tentar de novo".

### IN-FE-05: Atacado: detalhes menores
`FaixasDoAnuncio.jsx`: "Recarregar faixas" descarta as edições sem confirmar; `id: ''` (quando o ML não manda id, o servidor converte para string vazia) passa o teste `id !== null` e seria enviado como id vazio; voltar uma faixa editada ao valor original não restaura o `id` (a faixa será recriada). Nenhum causa dano com a validação do servidor, mas vale normalizar `id || null` em `linhaDe`.


_Reviewed: 2026-10-05_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

---

# Correções aplicadas (2026-10-05)

Os 3 críticos e os warnings que mexem em dinheiro, em dado enviado ao ML ou no que a pessoa lê antes/depois de
confirmar foram corrigidos antes da verificação, um commit por achado, cada um com teste.

| Achado | Commit | Como ficou |
|---|---|---|
| CR-BE-01 | `097a6883` | `ALAV-B2B-10` quando a leitura não traz as faixas (em `carregar()` e no GET relido do preparo); ids relidos diferentes → `ALAV-B2B-08`. Sem POST. Anúncio sem atacado (sem a tag do ML) aceita a chave ausente como lista vazia — senão a 1ª gravação nunca passaria. |
| WR-BE-02 + IN-BE-01 | `a5105e58` | linhas do lote abertas em transação; falha do dispatch vira ERRO `ALAV-LOTE-FILA` e devolve a assinatura; ids `MLB\d{1,17}` e `promotion_id {1,40}` com `/D` |
| WR-BE-03 | `ff533d4a` | `validarForma` guarda só `$v->validated()` |
| WR-BE-04 | `db0f5587` | `normalizar()` só converte campos numéricos conhecidos (código de cupom `1E3` fica intacto) |
| CR-FE-01 | `609bbe33` | `onConcluido` só relê; janela e formulário fecham no `onFechar`, depois de a pessoa ler o resultado |
| CR-FE-02 | `449b1aa4` | `fmtData` formata `YYYY-MM-DD` sem conversão de fuso |
| WR-FE-09 | `c42b42c0` | `lerNumero` único em `formato.js` para entrada pt-BR ("1.500" = 1500; "1.500,50" = 1500,5) |
| WR-FE-07 | `25e412b1` | `diaSP` converte ISO com fuso para o dia de São Paulo |
| WR-FE-08 | `ce7222be` | período do cupom em dias inclusivos (1 a 31), igual ao servidor |
| WR-FE-05 | `339152b8` | fechar a janela ou esgotar o lote relê a lista (uma vez) |
| WR-FE-10 | `bdd06303` | `useLote` com `setTimeout` encadeado, sem leitura em voo duplicada nem resposta velha |
| WR-FE-06 | `f1996406` | incluir produtos relê os itens abertos ao lado |
| WR-FE-01 | `2f2928d8` | Convites filtra pelo mesmo critério do panorama (`CONVITES_DO_ML`, não encerrados), com teste-espelho contra o PHP |
| WR-FE-03 | `8cbcdff8` | publicidade esconde os números do período antigo enquanto carrega |
| WR-FE-04 | `61957134` | rótulo "Saldo total das bonificações ativas" e marca nas inativas |

Gate depois das correções: Unit/Publicador 243 ✓, Feature/Publicador 560 ✓, PortalCliente 231 ✓, JS 949 (só as 2
falhas do baseline), build ok.

**Ficam abertos (registrados, não corrigidos):**
- **WR-BE-01** — a assinatura cobre a entrada do navegador, não o estado resolvido no servidor; o confirmar relê o ML e
  escreve com o estado ATUAL (o que é o comportamento desejado para `offer_id`/status), mas não avisa se o preço atual
  mudou nos 10 minutos da prévia. Decisão de produto (avisar × recusar), fora da fase.
- **WR-BE-05** — teto local de preço só em DEAL e desconto individual; nos demais tipos o ML é quem recusa e a prévia
  mostra o % em destaque. Criar regra de "desconto alto demais" é regra de negócio — não inventada aqui.
- **WR-FE-02** — grupos de anúncios limitados aos 50 mais clicados e filtrados no cliente: precisa de filtro por campanha
  no servidor. Só leitura; vai junto com a evolução da Publicidade (escrita depende da permissão "Advertising").
- Infos IN-BE-02..06 e IN-FE-01..05.
