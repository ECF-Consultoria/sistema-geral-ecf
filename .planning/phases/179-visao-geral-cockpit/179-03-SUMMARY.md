---
phase: 179-visao-geral-cockpit
plan: 03
subsystem: api
tags: [laravel, publicador, visao-geral, cockpit, saude-da-conta, desempenho, atividade, mutacao]

requires:
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
    provides: "AcervoTriagemService::escopo/triagem/defasagem, a rota mlb.anuncios.meus.atualizar (que ENFILEIRA) e a lista fechada de filtros de meus()"
  - phase: 173-visao-geral-do-publicador
    provides: "PainelVisaoGeralService: integracoes(), indicadores(), criativosPacks(), ultimasPublicacoes() e atividadeDaEquipe() com as 3 fontes reais"
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "CabecalhoPublicadorService::contadores() (publicacoes/publicacoes_pedem_acao NULOS quando nunca coletado) e a asserção negativa do ERP só declarado"
  - phase: 179-visao-geral-cockpit
    plan: 02
    provides: "produto_id/produto_nome no item de ultimasPublicacoes() — a capa NÃO vem de lá de propósito"
provides:
  - "CockpitDaContaService::banner($empresa) — o banner orientado a dado, com UM caso alcançável"
  - "CockpitDaContaService::saude($empresa, $integracoes, $defasagem, $companyId) — geral + 3 linhas, ERP só declarado"
  - "CockpitDaContaService::resumoDePublicacoes($company, $contadores) — barra de 4 faixas + resíduo `outras`, somando o total do header"
  - "CockpitDaContaService::acervoDeImagens($alvo, $indicadores) — {imagens, packs}, sem link"
  - "CockpitDaContaService::desempenho($alvo, $indicadores) — 3 KPIs reais, 2 com delta e série de 30 dias, url null"
  - "CockpitDaContaService::recentes($ultimasPublicacoes, $produtos) — 5 itens enriquecidos com capa, ZERO consulta"
  - "CockpitDaContaService::atividade($alvo, $atividadeEquipe, $limite) — as 3 fontes reais + a 4ª (produto_recebido), agrupadas por dia"
  - "tests/Feature/Publicador/CockpitDaContaTest.php — 50 casos, 2394 asserções, provados por 15 mutações"
affects: [179-05-controller-visaogeral, 179-07-saude-e-publicacoes-no-cliente, 179-08-recentes-e-atividade, 179-09-composicao-da-pagina, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "Janela dupla de 30 dias por UMA consulta agrupada por DATE(): o calendário é preenchido em PHP, e é isso que faz dia sem linha valer 0 em vez de ausência"
    - "Zero MEDIDO × não medido distinguidos pela ORIGEM do dado: fonte escrita por nós (janela fechada) vale 0; fonte coletada de terceiro vale null"
    - "Resíduo explícito (`outras`) em toda decomposição que tem de fechar com um total de outra fonte — sem ele a divergência fica silenciosa"
    - "Chave de contrato que só existe quando foi medida (`pedem_acao`), em vez de chave presente com zero"
    - "Filtro de escopo de conta em UM lugar do serviço, devolvido como Closure para `where()` — a desagrupação vira uma mutação que reprova por vazamento"

key-files:
  created:
    - app/Services/Publicador/CockpitDaContaService.php
    - tests/Feature/Publicador/CockpitDaContaTest.php
  modified: []

key-decisions:
  - "`pedem_acao` SAI do retorno quando o contador é nulo: o `<action>` do plano dizia as duas coisas e venceu a do `<behavior>`, que é a que o contrato do 179-07 já escreve (`pedem_acao?`)"
  - "`outras` também é null no ramo indisponível — mesma disciplina do VG-18 que já governa `total` e `ativas_pct`"
  - "`detalhe` do `produto_recebido` é o SKU, não a frase de origem: a frase já existe literal em DOIS lugares privados e não acrescenta nada além do que `quem` já diz"
  - "`quem` das publicações recentes continua `{tipo, nome}` — achatá-lo contrariaria o 'vem como veio' e quebraria a tela que já lê `quem.nome ?? quem.tipo`"
  - "`atividade()` NÃO ordena os grupos: a ordem vem da `usort` dos eventos, senão o gate do 'evento sem data no fim' ficaria vazio por construção"
  - "O teto de 5 recentes é repetido NESTE serviço, não só na fonte: a fonte subir o limite não pode virar uma lista de 30 linhas em silêncio"
  - "O `geral` fica em `atencao` para conta sem Company (nunca_coletado), sem exceção escondida para D23 — é o que o contrato do header já faz"

metrics:
  duration: "~4h"
  completed: 2026-10-12
  tasks: 3
  tests_added: 50
  assertions_added: 2394
  mutations: 15
  mutations_caught: 15
  queries_in_service: 5
  migrations: 0
---

# Fase 179 Plano 03: o cockpit da conta (servidor) Summary

O contrato de servidor dos cinco blocos que sobram do cockpit — banner, Saúde da conta, Publicações
no Mercado Livre com o acervo de imagens, a faixa de Desempenho e o par Recentes + Atividade — em
**5 consultas no total**, com `null` e `0` significando coisas diferentes em todo indicador e com
**seis** ausências de fonte declaradas em vez de preenchidas com enfeite.

## O shape EXATO dos sete retornos (é isto que o 179-05 liga e os 179-07/179-08 renderizam)

```php
// banner($empresa) — ?array
null                                   // token === 'ativo'
['chave' => 'ml', 'link_reconexao' => ?string]

// saude($empresa, $integracoes, $defasagem, ?int $companyId)
[
  'geral'  => 'tudo_conectado'|'atencao'|'requer_acao',
  'linhas' => [                        // EXATAMENTE 3, nesta ordem
    ['chave' => 'mercado_livre', 'estado' => 'ativo'|'expirado'|'sem_token',
     'conta_nome' => ?string, 'conta_ml_id' => ?string, 'problema' => bool, 'link_reconexao' => ?string],
    ['chave' => 'erp', 'declarado' => ?string, 'problema' => false],   // só estas 3 chaves
    ['chave' => 'sincronizacao', 'coletado_em' => ?string /* ISO */, 'horas' => ?int,
     'defasado' => bool, 'nunca_coletado' => bool, 'atualizar_url' => ?string],
  ],
]

// resumoDePublicacoes(?Company $company, $contadores)
[
  'disponivel' => bool,
  'total'      => ?int,                // o contador do HEADER, fonte única
  'faixas'     => [                    // EXATAMENTE 4, nesta ordem (ou [] quando indisponível)
    ['chave' => 'ativas',     'total' => int, 'url' => string],   // status=ativos
    ['chave' => 'pausadas',   'total' => int, 'url' => string],   // status=pausados
    ['chave' => 'em_revisao', 'total' => int, 'url' => string],   // status=em_revisao
    ['chave' => 'encerradas', 'total' => int, 'url' => string],   // status=encerrados
  ],
  'outras'     => ?int,                // resíduo: total − soma das 4 (0 quando fecha)
  'ativas_pct' => ?int,                // null quando total é null OU 0
  'pedem_acao' => ['total' => int, 'url' => string],  // ⚠️ A CHAVE SÓ EXISTE QUANDO FOI MEDIDA
]

// acervoDeImagens($alvo, $indicadores)
['imagens' => ?int, 'packs' => ?int]   // sem `url`: não existe tela de acervo por conta

// desempenho($alvo, $indicadores)
[
  'kpis' => [                          // EXATAMENTE 3, nesta ordem
    ['chave' => 'publicacoes_criadas',   'valor' => int,  'de' => null, 'delta' => int,  'serie' => [30 ints]],
    ['chave' => 'criativos_ia',          'valor' => int,  'de' => null, 'delta' => int,  'serie' => [30 ints]],
    ['chave' => 'publicacoes_com_venda', 'valor' => ?int, 'de' => ?int, 'delta' => null, 'serie' => null],
  ],
  'dias' => 30,
  'url'  => null,                      // a tela Desempenho é a Fase 182
]

// recentes($ultimasPublicacoes, $produtos)
[
  'disponivel' => bool,
  'itens' => [                         // até 5, da mais nova para a mais velha
    ['produto_id' => ?int, 'produto_nome' => ?string, 'titulo' => ?string, 'capa' => ?string,
     'fase' => ?int, 'rotulo_fase' => ?string, 'tipo' => ?string, 'ml_item_id' => ?string,
     'situacao' => ?string, 'vendas' => ?int, 'quando' => ?string /* ISO */,
     'quem' => ['tipo' => string, 'nome' => ?string], 'nova' => bool],
  ],
]

// atividade($alvo, $atividadeEquipe, int $limite = 12)
[
  'disponivel' => bool,
  'grupos' => [                        // ordem decrescente de data; `sem_data` por último
    ['chave' => 'hoje'|'ontem'|'YYYY-MM-DD'|'sem_data', 'data_iso' => ?string,
     'eventos' => [
       ['tipo' => 'publicou'|'criativos'|'conferiu'|'produto_recebido',
        'quem' => string, 'quando_iso' => ?string, 'titulo' => ?string, 'detalhe' => ?string],
     ]],
  ],
]
```

**A ordem das chaves é travada por `assertSame(array_keys(...))`** nas linhas da Saúde, nas faixas,
nos KPIs, no item recente e no evento. Campo a mais aqui é convite a inventar tela na wave 3.

## ⚠️ Para o 179-07 e o 179-08 (quem desenha) e para o 179-05 (quem liga)

**TODO rótulo, cor e formato de data é de vocês.** Do servidor saem só `chave`, números, booleanos e
URLs. As `chave` que vocês têm de mapear:

| Onde | `chave` que chegam |
|---|---|
| `banner.chave` | `ml` (hoje é o único alcançável) |
| `saude.geral` | `tudo_conectado` · `atencao` · `requer_acao` |
| `saude.linhas[].chave` | `mercado_livre` · `erp` · `sincronizacao` |
| `saude.linhas[0].estado` | `ativo` · `expirado` · `sem_token` |
| `publicacoesMl.faixas[].chave` | `ativas` · `pausadas` · `em_revisao` · `encerradas` (+ `outras`, que é número, não faixa) |
| `desempenho.kpis[].chave` | `publicacoes_criadas` · `criativos_ia` · `publicacoes_com_venda` |
| `atividade.grupos[].chave` | `hoje` · `ontem` · `YYYY-MM-DD` · `sem_data` |
| `atividade…eventos[].tipo` | `publicou` · `criativos` · `conferiu` · `produto_recebido` |

⚠️ **Cinco armadilhas medidas, para não repetir no cliente:**

1. **`pedem_acao` pode NÃO EXISTIR** no objeto (não é `null`: a chave não vem). Leiam com
   `publicacoesMl.pedem_acao?.total` e não desenhem a legenda quando falta.
2. **`outras` não é uma faixa** — é o resíduo que faz a soma fechar com o total do header. Se vier
   `> 0`, há `status` fora dos quatro conhecidos no acervo daquela conta; desenhá-lo ou não é decisão
   de vocês, mas **não o jogue dentro de "Encerradas"**.
3. **`ativas_pct` null tem DOIS significados** (`total` null = não medimos; `total` 0 = medimos e não
   há anúncio), e os dois pedem texto diferente. O par para distinguir é `disponivel` + `total`.
4. **`serie` tem 30 posições e a última é HOJE**; dia vazio é `0` **medido**. `serie` null só existe
   no KPI do acervo, e aí **nenhum svg** deve nascer — isso é correto, não defeito.
5. **`quem` das recentes é objeto** (`{tipo, nome}`), o único do contrato: `quem.nome ?? quem.tipo`.
   Produto do Portal vem como `tipo: 'cliente'` e `nome: null` de propósito (T-173-09).

## As divergências DECLARADAS, cada uma com a restrição concreta

| # | O protótipo / o plano diz | O que foi entregue | Restrição concreta (medida) |
|---|---|---|---|
| D-1 | linha âmbar "ERP Bling desconectado", banner e CTA "Reconectar ERP" (`:368`, `:519`) | a linha do ERP só com `declarado`, `problema` sempre falso, **nenhum banner de ERP** | **O ERP não é integração neste sistema** — é declarado na ficha de onboarding (ou pelo sync da planilha de Polos). A Fase 177-03 travou isso com asserção negativa (`assertArrayNotHasKey('conectado', $erp)`). "Reconectar ERP" seria botão sem destino (EXMP-03). **O cenário "ERP desconectado" de VG-19 não existe: a fase entrega 5 de 6 cenários.** |
| D-2 | o ERP é o caso **âmbar** da tela (`:519`) | o âmbar é a **defasagem da coleta** | Mesma restrição de D-1: o ERP não é medido, então não pode mudar um indicador. Defasagem é estado medido (`AcervoTriagemService::defasagem()`). |
| D-3 | barra `Ativas / Pausadas / **Com erro**` (`:532`) | 4 faixas (`ativas/pausadas/em_revisao/encerradas`) + a legenda `pedem_acao` | **`MlAcervoItem.status` só assume `active|paused|under_review|closed`** — não existe status de erro (medido na 177-03). O terceiro sinal real é `severidade > 0`, o MESMO número do dot da aba Publicações. |
| D-4 | KPIs "Vendas", "Visitas" e "Conversão" (`:536`) | **não nascem** | DESEMP-05: `sold_quantity` é o acumulado da **vida inteira** do anúncio; `ml_acervo_metricas_diarias` é esparsa (1,3 dias/anúncio); `visitas` é NULL em **100% de 1.374.527 linhas**; **não existe tabela de pedidos**. |
| D-5 | a faixa de Desempenho é um `<a>` para a tela Desempenho (VG-15, `:283`) | `url: null`, e o cliente desenha `<section>` | **A tela Desempenho não existe** e a aba dela não aparece — é a Fase 182 (decisão do usuário de 11/10/2026). ✅ A forma "3 KPIs reais, sem link" foi **confirmada pelo usuário em 12/10/2026**. Quando a 182 existir, é **uma linha**. |
| D-6 | "Acervo de imagens … **Abrir →**" (`:255`) | os dois números, **sem link** | Não existe tela de acervo **por conta**: o acervo navegável é `publicador/produtos/{produto}/acervo` e vive **dentro do editor** (território de outro dev nesta fase). |
| D-7 | chip **Sistema** na Atividade (`:366`) | 4 chips (Tudo/Publicações/Criativos/Produtos) | Não existe fonte de **evento** de sincronização: o que existe é um **carimbo** (`defasagem.coletado_em`), que já é a linha de Sincronização da Saúde. Carimbo não é linha do tempo. |
| D-8 | badge **NOVA** nas "duas mais recentes" (`recBase[…][10]`, `:563`) | `nova` = publicada nas **últimas 24 h** | Posição na lista não é informação sobre o mundo: no protótipo o `isNew` é mock. 24 h é o critério real mais próximo e é mensurável. |
| D-9 | o `<action>` prescreveu `pedem_acao: null` no ramo indisponível **e** "a entrada sai do retorno quando o total é nulo" | a chave **existe quando, e só quando, há número medido** | As duas frases se contradizem. Venceu a do `<behavior>` ("a legenda do 'Pedem ação' **sai**"), que é também a do contrato do 179-07 (`pedem_acao?`). Duas formas de dizer a mesma coisa seriam duas verdades. |
| D-10 | o `<action>` prescreveu selecionar `id, nome, origem, created_at` na 4ª fonte | `sku` acrescentado à MESMA consulta, e `detalhe` = SKU | A frase de origem ("Recebido do Portal do Cliente"…) já existe **literal em dois lugares privados** (`NovaPublicacaoService` e `JornadaDosProdutosService`) e **não acrescentaria informação**: a origem já está em `quem`. O SKU é o identificador que o usuário tem na mão — e é o que o protótipo usa como meta de produto. |

## `null` × `0` — o mapa completo deste plano

| Indicador | "nunca medimos" | "medimos e é zero" | Por que são diferentes |
|---|---|---|---|
| `publicacoesMl.total` | `null` + `disponivel: false` | `0` + `disponivel: true` | acervo nunca coletado × conta sem anúncio |
| `publicacoesMl.ativas_pct` | `null` (total null) | `null` (total 0) | não há o que dividir nos dois casos — o par `disponivel`+`total` distingue |
| `publicacoesMl.outras` | `null` | `0` | resíduo não medido × resíduo medido e fechado |
| `publicacoesMl.pedem_acao` | **chave ausente** | `{total: 0, …}` | ver D-9 |
| `acervoImagens.packs` | `null` (indicador ausente) | `0` | indicador não calculado × conta sem pack |
| `desempenho…com_venda.valor/de` | `null` | `0` | acervo nunca coletado × acervo sem venda |
| `desempenho…serie[i]` | **não existe** | `0` | ⚠️ aqui dia vazio é **sempre** zero medido: a janela é fechada e a fonte é escrita por nós |
| `saude…sincronizacao.coletado_em/horas` | `null` + `nunca_coletado: true` | — | "há 0 min" seria mentira |

⚠️ **A distinção é de ORIGEM do dado, não de gosto.** O acervo é **coletado de terceiro**: lá "dia
sem linha" pode ser falha de coleta — é a armadilha *"dia sem linha ≠ venda zero"* dos learnings da
Shopee deste projeto. `pub_publicacoes` e `ml_anuncio_criativo_kits` são escritas por **nós**: se não
há linha no dia 7, nada aconteceu no dia 7. Os dois convivem nesta mesma tela, de propósito.

## O custo medido

| O que | Medido |
|---|---|
| Consultas dos **sete** métodos juntos, com 12 produtos e 20 anúncios | **5** |
| As mesmas, com **24** produtos | **5** (`assertSame`) — é a estabilidade que pega N+1, não o teto |
| Teto cobrado | `assertLessThan(10, …)` |
| Isca do mesmo teste | `produtosParaTela()` medido antes, `assertGreaterThan(0, …)` — sem ela o teto passaria por construção |
| Quem consulta | barra de status (1) · acervo de imagens (1) · janela de publicações (1) · janela de kits (1) · produtos recebidos (1) |
| Quem **não** consulta | `banner()`, `saude()`, `recentes()` — tudo chega por argumento |
| Chamadas ao Mercado Livre | **0** (`Http::fake()` sem handler em toda a classe de teste) |

## A prova por mutação — 15 aplicadas, 15 reprovaram

Processo por mutação: **contagem de ocorrências do alvo travada ANTES** (recusa se ≠ 1), aplicação,
**conferência de QUAL linha mudou** (`git diff --unified=0`, com checagem automática de que a
mudança não é só comentário — na Fase 178 três mutações foram recusadas por casarem em comentário),
execução da suíte do plano, restauração de cópia pristina e **conferência por `sha256`**
(`2e158c6c316bdc8c…8be77b` idêntico ao fim das 15 rodadas).

| # | Mutação | Linha | Reprovou em |
|---|---|---|---|
| M01 | `total` null vira `0` quando o contador é nulo | 251 | `contador_nulo_deixa_o_resumo_indisponivel_sem_nenhum_zero` + 1 |
| M02 | tira o resíduo `outras` (fixa em 0) | 286 | `a_soma_da_barra_fecha_em_tres_distribuicoes_inclusive_com_status_desconhecido` |
| M03 | escopo da barra de `'todos'` para `'acionaveis'` | 263 | `as_quatro_faixas_somam_o_total_do_header` + 1 |
| M04 | ERP ganha `problema: true` | 179 | `erp_nao_informado_nao_tem_problema_e_nunca_vira_banner` + 1 |
| M05 | ERP puxa o `geral` para `atencao` | 197 | `erp_nao_informado_nao_tem_problema_e_nunca_vira_banner` |
| M06 | `ativas_pct` sem o guard de `total === 0` | 287 | `acervo_coletado_e_vazio_tem_total_zero_medido_e_pct_nulo` |
| M07 | `delta` deixa de subtrair o anterior | 801 | `publicacoes_criadas_mede_as_duas_janelas…` + 2 |
| M08 | série encurta de 30 para 12 | 774 | `publicacoes_criadas…` + 3 (inclusive as varreduras) |
| M09 | dia sem linha vira `null` em vez de `0` | 775 | `publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias` |
| M10 | acrescenta um KPI de vendas | 399 | `a_faixa_nao_tem_kpi_de_venda_de_visita_nem_de_conversao` + 3 |
| M11 | faixa de Desempenho ganha `url` | 400 | `a_faixa_de_desempenho_nao_tem_destino_porque_a_tela_nao_existe` |
| M12 | teto de recentes sobe de 5 para 10 | 70 | `o_teto_de_cinco_vale_mesmo_quando_a_fonte_manda_mais` |
| M13 | evento sem data vai para o TOPO | 519 | `evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo` |
| M14 | tira a 4ª fonte da atividade | 504 | `as_tres_fontes_reais_chegam_intactas_e_a_quarta_e_somada` + 3 |
| M15 | **desagrupa o `orWhere` do escopo** | 566 | `a_quarta_fonte_nao_vaza_produto_de_outra_conta_nem_produto_antigo` — ⚠️ reprovou por **vazamento de escopo**, com a segunda conta cheia no banco |

## Deviations from Plan

### [Rule 1 - Bug no desenho do gate] O range `898d68c2..HEAD` NÃO mede este plano

- **Encontrado durante:** a execução dos gates 5 e 8 da `<verification>`.
- **Problema:** `898d68c2` é o commit de partida da **FASE**, não deste plano. O gate 5
  (`git diff --stat 898d68c2..HEAD -- PainelVisaoGeralService.php` deve ser **vazio**) acusa
  **86 inserções / 15 remoções** — que são do **plano 179-02**, dono legítimo daquele arquivo. O
  mesmo vale para `JornadaDosProdutosService`/`AcoesDaContaService`/`AdiamentoDeAcoes` (waves 01 e
  02). Rodado como escrito, o gate acusaria **este** plano por trabalho de outro.
- **Correção:** os gates de "não toquei" foram medidos no range **deste plano**, `8b88f530..HEAD`
  (`8b88f530` = o commit de SUMMARY do 179-02B, o último antes do meu primeiro). Nele todos os
  caminhos proibidos dão **vazio** e a **isca** (`-- app tests`) devolve exatamente os 2 arquivos
  novos (2428 inserções, 0 remoções) — o vazio não é por construção.
- **Para o 179-10:** o gate de território desta fase precisa ser **por plano**, não por fase. Em
  range de fase ele é **falso-positivo por construção** a partir da wave 02.

### [Rule 1 - Correção de fixture, não de contrato] `pub_rascunhos.produto_id` é UNIQUE

- **Encontrado durante:** o RED da Task 2, em `UniqueConstraintViolationException`.
- **Problema:** o helper criava um rascunho por publicação. A tabela tem `unique('produto_id')`: duas
  publicações do mesmo produto são **revisões do mesmo rascunho**, como no editor.
- **Correção:** `firstOrCreate` por produto e `revisao` incremental. Nenhuma mudança de contrato.

### [Rule 2 - Correção] A varredura de escalares esperava UM evento sem título; são DOIS

O produto sem nome (4ª fonte) **e** o kit de criativo sem `pub_rascunho_id`, que não tem produto para
nomear (é `leftJoin` em `atividadeDaEquipe()`). Os dois são honestos, os dois são `null` e nenhum é
`''`. O teste passou a cobrar os dois **e os tipos deles**, para que um terceiro caso de título
ausente não entre em silêncio.

### [Rule 2 - Correção] O gate D23 esperava `tudo_conectado`; é `atencao`

Sem `Company` não há acervo para coletar, então `nunca_coletado` é verdadeiro e o `geral` fica em
`atencao` — a **mesma regra** do caso medido, sem exceção escondida para D23. É exatamente o que o
contrato do header já faz para as contas sem Company (ali a tela aplica "disponível só para empresas
cadastradas"). O teste passou a cobrar o `atencao` **com o motivo** (`nunca_coletado: true` +
`problema: false` na linha do ML), para não confundir o âmbar da coleta com problema de integração.

### [Desvio de processo] Seis commits em pares RED/GREEN, um par por task

O arquivo de serviço é o mesmo das três tasks e o `<verify>` de cada uma é a suíte completa. Entregue
na ordem de gate do TDD, com o RED conferido antes de cada implementação:

| Commit | O que | Gate |
|---|---|---|
| `80e435bd` | teste: banner, Saúde e barra | **RED** — 21 falhas por "Class … does not exist" |
| `a8856ab7` | serviço: `banner`/`saude`/`resumoDePublicacoes`/`acervoDeImagens` | **GREEN** — 21/21, 111 asserções |
| `54a0975f` | teste: faixa de Desempenho | **RED** — 9 falhas por método inexistente |
| `0e33b134` | serviço: `desempenho` | **GREEN** — 30/30, 195 asserções |
| `23f3197a` | teste: recentes, atividade e os gates do contrato | **RED** — 20 falhas por método inexistente |
| `43fa4d4a` | serviço: `recentes`/`atividade` + as 3 correções de teste | **GREEN** — 50/50, 2394 asserções |

### Nenhuma outra: nada fora do escopo foi tocado

No range deste plano (`8b88f530..HEAD`): `PainelVisaoGeralService.php` (dono: 179-02),
`JornadaDosProdutosService`/`AcoesDaContaService`/`AdiamentoDeAcoes` (waves 01/02),
`app/Support/Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `resources/js`,
`routes`, `database`, `composer.*`, `package*.json`, `ROADMAP.md` e `REQUIREMENTS-v26.md`:
**diff vazio**, com os 19 caminhos conferidos por `test -e` antes de acreditar no vazio.

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `artisan test --filter=CockpitDaContaTest` | **50 passed, 2394 assertions** |
| 2 | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1981 testes / 14702 asserções / 0 falhas** (baseline medida ANTES de editar: **1931 / 12308 / 0**, em 5m24s) |
| 3 | `artisan test --filter=CabecalhoDoPublicador` | **16 passed, 210 assertions** (o contador que esta tela reusa) |
| 4 | `artisan test --filter=Phase134` | **111 passed, 513 assertions** (acervo + a rota "Atualizar agora") |
| 5 | diff vazio em `PainelVisaoGeralService.php` | ✅ no range deste plano — ver o deviation do range |
| 6 | diff vazio em `database` / `database/migrations` | ✅ (nenhuma migration) |
| 7 | diff vazio em `resources/js` + `routes` | ✅ (100% servidor — o JS não foi tocado, provado por diff, não por afirmação) |
| 8 | diff vazio nos 5 caminhos de território proibido | ✅ |
| 9 | `git diff --diff-filter=D --name-only` | ✅ vazio (nenhuma deleção) |
| 10 | `git status --short \| grep tests` | só `?? tests/Feature/CompanyPortfolioAccessTest.php`, que **não é deste plano** (já estava na árvore) |
| 11 | os 19 caminhos de gate existem no disco (`test -e`) + isca do range | ✅ |
| 12 | T-179-SC: `composer.json/lock`, `package.json/lock` | ✅ vazio (nenhum pacote instalado) |
| 13 | asserções removidas em `tests/` | **ZERO**, com isca: **233 linhas com `assert` acrescentadas** no mesmo range |

**Deprecations: 52 → 53.** A nova é do `@group phase179` no doc-comment desta classe de teste — a
convenção que **75 arquivos** deste projeto já usam, e exatamente a origem das 52 anteriores.

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-10 Information Disclosure (vazamento de escopo) | mitigado | a barra usa `AcervoTriagemService::escopo()` (que carrega o `company_id`); as três consultas novas agrupam os `orWhere` dentro de `where(function…)` com `1 = 0` sem âncora. **M15 reprova a desagrupação** com uma segunda conta cheia no banco, e há teste de não-vazamento para o acervo de imagens, para as duas janelas e para a 4ª fonte |
| T-179-11 Repudiation (números que discordam) | mitigado | `total` é o do header e a soma das 4 faixas **+ `outras`** é cobrada por igualdade em 3 distribuições; `pedem_acao` conferido contra `AcervoTriagemService::triagem()['total']` |
| T-179-12 Tampering (zero inventado) | mitigado | os dois casos opostos testados juntos + varredura do JSON inteiro por qualquer `0` no ramo não medido (ramo sem URL, então nenhum dígito legítimo mascara o gate) |
| T-179-13 Spoofing (nome de cliente exposto) | mitigado | `quem` da 4ª fonte reusa `QUEM_CLIENTE`/`QUEM_EQUIPE_SEM_NOME`/`QUEM_ORIGEM_ANTIGA`; teste cobra que **todo** `quem` é uma das três constantes |
| T-179-14 Spoofing (destino inventado) | mitigado | as 5 URLs saem de `route()` com valores da lista FECHADA de `meus()`, conferidos contra ela no teste; a faixa de Desempenho sai com `url: null` |
| T-179-15 Denial of Service (coleta no request) | mitigado | `atualizar_url` aponta para `mlb.anuncios.meus.atualizar`, que **enfileira**; zero chamada HTTP ao ML (`Http::fake()` sem handler) |
| T-179-SC | mitigado | nenhum pacote instalado, diff vazio nos 4 manifests |
| **extra** (não estava no registro) | mitigado | `test_o_payload_nao_vaza_segredo_da_conta`: nem `access_token`, nem `refresh_token`, nem `APP_USR`/`TG-` |

## Known Stubs

Nenhum. Nenhum campo do payload é constante, placeholder ou valor de exemplo: todos saem de consulta,
de argumento já medido ou de `route()`. Os únicos valores fixos são `dias: 30` (o tamanho declarado
da janela) e `url: null` (ausência deliberada de destino, com a restrição escrita no docblock).

## Threat Flags

Nenhuma. Nenhum endpoint, rota, caminho de autenticação ou alteração de schema nasce aqui: o plano é
100% leitura, e as cinco consultas são escopadas pela mesma fronteira (`company_id` / dupla-âncora)
que a tela de Publicações e a de Produtos já usam.

## Perguntas em aberto para o usuário

1. **O número de produção não foi medido.** A leitura de produção está bloqueada nesta sessão
   (plink/tinker/MCP, motivo "Production Reads"), então todo cenário é sintético. Em particular, não
   sabemos ainda quantas contas reais têm `outras > 0` (status de acervo fora dos quatro conhecidos)
   nem como a faixa de Desempenho fica numa conta movimentada.
2. **`criativos_ia` conta TODO kit criado na janela**, inclusive kit que ainda não gerou imagem
   (`planejando`), para ficar consistente com `criativos_packs`, que é a contagem de sempre. Se você
   preferir "só kit que gerou imagem", é um `where('imagens_geradas', '>', 0)` — mas então os dois
   números do mesmo card passam a medir universos diferentes.
3. **O cenário "ERP desconectado" de VG-19 não existe** (D-1): a fase entrega **5 de 6** cenários de
   vazio/erro. Isso já estava aceito no plano; fica registrado para o fechamento da fase.
4. ⚠️ **Nada foi visto renderizado** — não há navegador neste ambiente e nenhuma tela foi aberta.
   Toda a conferência é de teste automatizado; a validação visual é sua, em produção, na conta 459,
   depois das waves 2 e 3.

## Self-Check: PASSED

- `app/Services/Publicador/CockpitDaContaService.php` — FOUND
- `tests/Feature/Publicador/CockpitDaContaTest.php` — FOUND
- `.planning/phases/179-visao-geral-cockpit/179-03-SUMMARY.md` — FOUND
- commits `80e435bd`, `a8856ab7`, `54a0975f`, `0e33b134`, `23f3197a`, `43fa4d4a` — FOUND em `git log`
- os dois arquivos **idênticos ao commit** depois das 15 mutações (`git diff --stat` vazio sobre
  eles, com o `sha256` do serviço conferido a cada rodada da campanha)
- `git status --short` sem fixture órfã deste plano
