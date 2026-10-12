---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 03
subsystem: api
tags: [laravel, inertia, publicador, header, contrato-de-prop, acervo-ml, throttle]

requires:
  - phase: 164-publicador-interno
    provides: "ProgramasPublicadorService (resolver, empresaParaTela, produtosQuery, base/empresas) e as telas A/B"
  - phase: 173-visao-geral-do-publicador
    provides: "AcervoTriagemService (escopo, triagem, defasagem) e PainelVisaoGeralService::integracoes (fonte única do ERP declarado)"
  - phase: 175-fases-do-produto
    provides: "MlbPublicadorFaseController::mostrar e a conta do PRODUTO (WR-B01)"
provides:
  - "CabecalhoPublicadorService::para() — a prop `cabecalho` (identidade + integrações + contadores reais)"
  - "ProgramasPublicadorService::contasLeves() — as contas dos 3 programas no shape leve do dropdown"
  - "Prop `cabecalho` nas 9 páginas que o header global atinge"
  - "GET mlb/anuncios/publicador/empresas-header — {total, limite, empresas} para o seletor de empresa"
  - "tests/Feature/Publicador/CabecalhoDoPublicadorTest.php — o contrato, a disciplina null vs 0 e dois detectores de N+1"
affects: [177-04-publicadorheader, 177-05-abas-e-contadores, 177-06-dropdown-e-popover, 177-07, 177-08, 182-desempenho]

tech-stack:
  added: []
  patterns:
    - "Serviço de cabeçalho que RECEBE `$alvo` e `$empresa` já resolvidos pelo controller — nunca resolve a conta de novo"
    - "Contador de coisa não medida é `null`, nunca `0` (disciplina herdada de AcervoTriagemService::defasagem)"
    - "Endpoint de lista sem parâmetro de entrada: o escopo é do usuário autenticado, resolvido no servidor"

key-files:
  created:
    - app/Services/Publicador/CabecalhoPublicadorService.php
    - tests/Feature/Publicador/CabecalhoDoPublicadorTest.php
  modified:
    - app/Services/Publicador/ProgramasPublicadorService.php
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - app/Http/Controllers/MlbPublicadorFaseController.php
    - app/Http/Controllers/MlbPublicadorLoteController.php
    - app/Http/Controllers/MlbAlavancasController.php
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php

key-decisions:
  - "O ERP entra no contrato como `integracoes.erp.declarado` e NADA MAIS: não existe integração com ERP neste sistema, logo o header nunca pode dizer 'Conectado'. Teste nega a chave `conectado` explicitamente."
  - "O contador do dot vermelho se chama `publicacoes_pedem_acao` (severidade > 0), não 'com erro': `MlAcervoItem.status` só assume active|paused|under_review|closed. Divergência declarada de rótulo (FID-02) com restrição concreta."
  - "`publicacoes` e `publicacoes_pedem_acao` são `null` quando o acervo nunca foi coletado — e `0` só quando foi medido e é zero."
  - "`empresasDoHeader()` ficou SEM nenhum parâmetro (o plano previa `Request $request`): assim a mitigação de IDOR (T-177-08) é estrutural, não promessa de comentário."
  - "`contasLeves()` lê `base()`, nunca `empresas()`: o dropdown não paga o custo da tela A."
  - "O universo de `contasLeves()` é o MESMO de `buscaEmpresas()` (inclui conta que autorizou e ainda não tem token), para o rodapé 'N empresas sob gestão' nunca discordar do que a busca acha."

patterns-established:
  - "Prop ADITIVA: `cabecalho` entrou sem tirar `abas` de nenhuma página — a troca de consumo é das plans 177-07/08"
  - "Teste de contrato por tabela de rotas: `paginas()` lista as 9 páginas e o teste falha se alguma ficar sem caso"
  - "Teste de query log como detector de N+1, com o número medido registrado no docblock e teto folgado"

requirements-completed: [HEAD-10, HEAD-12, REG-01, REG-04, REG-05, REG-06, GATE-04, GATE-06]

duration: ~50min
completed: 2026-10-12
---

# Fase 177 Plano 03: o contrato do servidor para o header global

**A prop `cabecalho` nas 9 páginas do Publicador — identidade da conta, estado das integrações e os contadores REAIS das abas — mais o endpoint lazy do seletor de empresa, sem uma única consulta nova para o que já era calculado.**

## Performance

- **Duração:** ~50 min
- **Concluído:** 2026-10-12T12:22Z
- **Tarefas:** 3 de 3
- **Arquivos:** 2 criados, 7 modificados

## O contrato da prop `cabecalho`

É isto que os planos 177-04/05/06 vão ler. As chaves são exatas.

```
cabecalho: {
  aba:        'visao-geral' | 'produtos' | 'publicacoes' | 'alavancas' | 'configuracoes',
  empresa:    <saída de empresaParaTela(), INTACTA>,   // nome, chave, identificador,
                                                       // programa, programa_rotulo, token,
                                                       // link_reconexao, portal, conta_nome, conta_ml_id
  company_id: ?int,
  contadores: {
    produtos:               int,    // sempre medível
    publicacoes:            ?int,   // null = acervo NUNCA coletado
    publicacoes_pedem_acao: ?int,   // null = acervo NUNCA coletado
    alavancas:              int,
  },
  integracoes: {
    mercado_livre: { estado: 'ativo'|'expirado'|'sem_token',
                     conta_nome: ?string, conta_ml_id: ?string, link_reconexao: ?string },
    erp:           { declarado: ?string },              // ⚠️ SÓ isso — ver abaixo
    portal:        { situacao, novas, sincronizado_em },// $empresa['portal'], intacto
    coleta:        { coletado_em: ?string, horas: ?int, defasado: bool, nunca_coletado: bool },
  },
}
```

A prop **pode ser `null`** nas três páginas do assistente antigo (`massa`, `historico`, `meus`): ali o
`resolver('company-N')` devolve `null` quando a Company não cai em programa nenhum. **O header tem de
sobreviver a `cabecalho = null`** — quem prova isso é o render real do plano 177-04.

Tudo o que a tela renderiza é **escalar** (string, int, bool ou null). Nenhum objeto aninhado novo
chega ao React como se fosse texto — foi exatamente disso que nasceu a tela preta de 07/10.

As 9 páginas e a aba de cada uma:

| Página | Rota | `aba` |
|---|---|---|
| Produtos (tela B) | `publicador/empresas/{conta}` | `produtos` |
| Visão geral | `…/visao-geral` | `visao-geral` |
| Configurações da conta | `…/configuracoes` | `configuracoes` |
| Tela do Produto | `…/produtos/{produto}` | `produtos` |
| Publicação em lote | `…/lote` | `produtos` |
| Alavancas | `…/alavancas` | `alavancas` |
| Em massa (assistente antigo) | `/mlb/anuncios/massa/{company}` | `produtos` |
| Histórico | `/mlb/anuncios/historico/{company}` | `publicacoes` |
| Meus Anúncios | `/mlb/anuncios/meus/{company}` | `publicacoes` |

## O que foi REUSADO em vez de reescrito

Esta é a parte que mais custou atenção: o plano exigia não escrever consulta nova para nada que já
era calculado.

| O que o contrato precisa | De onde veio (reuso) | O que NÃO foi feito |
|---|---|---|
| Identidade da conta (nome, chave, token, portal, conta_nome, conta_ml_id, link_reconexao) | `$empresa`, a saída de `ProgramasPublicadorService::empresaParaTela()` que o controller **já** calculou — passada como argumento | Não se resolve a conta de novo dentro do serviço. Isso daria 2 resoluções por request e, na tela do Produto/editor, mostraria a conta da EMPRESA onde a tela mostra a conta do PRODUTO (WR-B01) |
| Contador de produtos | `ProgramasPublicadorService::produtosQuery($e, $c)` — que já agrupa os dois `orWhere` dentro de `where(function…)` e já aplica `1 = 0` sem âncora | Não foi escrita a consulta à mão (o plano a trazia inline). Reusar o builder é o que garante o agrupamento do T-134-01 por construção |
| `publicacoes_pedem_acao` | `AcervoTriagemService::escopo($c, '', 'acionaveis')` + `severidade > 0` — o MESMO escopo que a tela de Publicações usa | Nenhuma definição nova de "acionável". O dot do header não pode discordar do topo daquela tela |
| `coleta` | `AcervoTriagemService::defasagem()`, e em `visaoGeral()`/`meus()` o valor **já calculado pela action** entra como 4º argumento | Não se repete a consulta de defasagem nessas duas páginas (eram as únicas que já a tinham em mãos) |
| `erp.declarado` | `ProgramasPublicadorService::erpDeclarado()` — a estática que a tela A e `PainelVisaoGeralService::integracoes()` já usam | Não foi criada uma segunda leitura do ERP. Enquanto existiam duas, a tela A e a Visão geral podiam mostrar ERPs diferentes para a MESMA conta |
| `alavancas` | `PubTarefa::abertasDaConta()` — já existe e já é resistente a tabela ausente | Nenhuma contagem nova de tarefas |
| `contasLeves()` | `base()` dos 3 programas, com o eager loading que ela já faz (`mlToken`, `company.mlToken`) | **Não usa `empresas()`**: ela varre produtos, rascunhos, publicações, ofertas do Portal e ERP de todas as contas — custo de tela, não de dropdown |

Em `MlbPublicadorLoteController::index()` e `MlbAlavancasController::index()` o
`empresaParaTela($alvo)` estava inline dentro do `Inertia::render`; virou variável `$empresa` para o
header receber o **mesmo array**, em vez de montar um segundo. Mesmo valor, nenhuma mudança de
comportamento.

## Como o ERP aparece no contrato — e a prova de que nunca diz "Conectado"

**O ERP não existe como integração neste sistema.** Ele é apenas **declarado** pela empresa na ficha
de onboarding (ou preenchido pelo sync da planilha de Polos, na coluna `erp`). O docblock de
`PainelVisaoGeralService::integracoes()` já registrava isso, e a decisão 8 do handoff é literal:
*"Exibir 'Bling · declarado no onboarding', nunca 'conectado', até existir integração real"*.

Por isso `integracoes.erp` tem **uma única chave**: `declarado` — o nome ou `null`. Não existe
`conectado`, não existe `sincronizado_em`, não existe `atualizado_em`. A tela dirá **"Declarado: X"**
ou **"Não informado"**.

A prova está em `test_erp_aparece_so_como_declarado_e_nunca_como_conectado()`, e ela é **negativa de
propósito**:

```php
$this->assertSame(['declarado' => 'Bling'], $erp);
$this->assertArrayNotHasKey('conectado', $erp);
$this->assertArrayNotHasKey('sincronizado_em', $erp);
$this->assertArrayNotHasKey('atualizado_em', $erp);
```

Uma asserção positiva deixaria a porta aberta para uma fase futura ACRESCENTAR `conectado` e o teste
continuar verde. A negativa reprova. É ela que protege a honestidade da tela daqui para frente.

⚠️ O **segundo sinal de conexão** do header não é o ERP: é o **Portal do Cliente**, que é estado
medido de verdade (`$empresa['portal']`, o que o `SeloPortal` já entrega hoje). O protótipo do layout
pede dois dots na pill de conexão (ML + ERP); o dot do ERP só pode significar "declarado / não
informado", nunca "conectado / desconectado".

⚠️ **A meta do ML também não inventa prazo.** O protótipo escreve *"Conta ECFDEV02 · token renova em
4 h"*. O contrato expõe só `estado: ativo|expirado|sem_token` (o MESMO que o `SeloConta` mostra hoje)
— nada de `renova_em`. `test_integracao_do_ml_expoe_estado_e_nunca_prazo_de_renovacao()` trava a lista
exata de chaves e nega `renova_em`/`expires_at`.

## De onde vem o número de "pedem ação"

O protótipo chama o dot vermelho da aba Publicações de **"publicações com erro"**. Esse número **não
existe**: `MlAcervoItem.status` só assume `active`, `paused`, `under_review` e `closed` (ver o `match`
de `AcervoTriagemService::escopo()`). **Não há status de erro no acervo** — a coluna não existe.

O número real e acionável é **"publicação com 1 ou mais motivos de triagem"**, isto é `severidade > 0`
dentro do escopo `acionaveis` (`active` + `paused` + `under_review`):

```php
$this->triagem->escopo($c, '', 'acionaveis')->where('severidade', '>', 0)->count()
```

É **exatamente** o mesmo número que `AcervoTriagemService::triagem()['total']` devolve para o topo da
tela de Publicações hoje — mesma fonte de escopo, por construção. Por isso:

- a chave é **`publicacoes_pedem_acao`**;
- o rótulo que o header usa (plano 177-05) é **"N publicações pedem ação"**, **não** "com erro".

Esta é uma **divergência DECLARADA de rótulo** (FID-02): a restrição é concreta — a ausência da coluna
—, não preferência de estilo. `test_pedem_acao_conta_anuncio_com_motivo_de_triagem_nao_status_de_erro()`
monta 4 anúncios (saudável, pausado com motivo, com foto insuficiente, e um `closed` com motivo) e
cobra `publicacoes = 4` (acervo inteiro) com `publicacoes_pedem_acao = 2` — o `closed` fica fora
porque está fora do escopo `acionaveis`.

## `null` × `0`: a disciplina, com os dois casos

Acervo **nunca coletado** devolve `publicacoes = null` **e** `publicacoes_pedem_acao = null`. Nunca
`0` — um zero mentiria "esta conta não tem anúncio" quando a verdade é "ninguém mediu ainda". O header
não mostra pílula nenhuma nesse caso.

Dois testes opostos sustentam isso, e **só os dois juntos provam alguma coisa**:

- `test_acervo_nunca_coletado_devolve_null_e_nunca_zero()` — sem linha em `ml_acervo_itens`: os dois
  contadores são `null` e `coleta.nunca_coletado` é `true`.
- `test_acervo_coletado_sem_nenhum_motivo_devolve_zero_porque_foi_medido()` — 2 anúncios saudáveis:
  `publicacoes = 2` e `publicacoes_pedem_acao = 0`. **Aqui o `0` é a verdade.**

Sem Company (**D23** — lembrando que 535 de 539 `MlbEmpresa` não têm Company) o contrato não mente:
`company_id = null`, os dois contadores `null`, `coleta.nunca_coletado = true` e
`portal.situacao = 'sem_portal'`. Nada lança. O contador de **produtos** continua medindo (a âncora é
a `MlbEmpresa`, não a Company). `test_conta_sem_company_nao_mede_publicacoes_e_nao_lanca()` varre as 6
páginas alcançáveis nessa condição.

## O endpoint do seletor de empresa

```
GET /mlb/anuncios/publicador/empresas-header
→ { total: int, limite: 200, empresas: [{chave, nome, identificador, company_id, programa, token}] }
```

- **Sem nenhum parâmetro de entrada** (T-177-08): o escopo é do usuário autenticado, resolvido no
  servidor. Fica no grupo `['auth','verified','role:admin']`; não-admin recebe 403.
- `total` é a **contagem real** — é o "N empresas sob gestão" do rodapé do dropdown (HEAD-05).
- `limite = 200` (constante de classe). Acima disso o cliente completa pela rota de busca que **já
  existe** (`publicador.empresas-busca`); este endpoint **não** ganha parâmetro de busca, para não
  virar um segundo caminho para a mesma coisa.
- **Nunca** devolve `access_token`/`refresh_token` nem o objeto `MlToken` — só a string de estado
  `token`. O teste varre o corpo da resposta por `APP_USR` e `TG-`.
- `throttle:120,1,publicador.empresas-header`, nomeado, ao lado da rota de busca.

## Números de consulta medidos

Medidos em 12/10/2026, SQLite, e registrados nos docblocks dos testes para a próxima pessoa poder
comparar:

| Cenário | Consultas | Teto do teste |
|---|---|---|
| 1 visita à Visão geral (1 conta, 3 produtos, 3 anúncios) — a página mais pesada do módulo | **50** | `assertLessThan(80)` |
| `GET publicador/empresas-header` com **8 contas** | **16** | `assertLessThan(40)` |

O teto é folgado **de propósito**: o objetivo não é cravar um número bonito, é **pegar N+1**. O número
do seletor não cresce com a quantidade de contas — é isso que o teste guarda. Se `contasLeves()` ou
qualquer contador virar consulta por linha, os dois testes acusam.

## Task Commits

As três tarefas foram entregues em **um commit**, como o `<output>` do plano determina (serviço,
consumidores e teste de contrato são indivisíveis — o teste cobra as 9 props que o mesmo commit cria):

1. **Tasks 1, 2 e 3** — `399f8fe1` `feat(publicador): prop cabecalho nas 9 paginas e o endpoint do seletor de empresa (177-03)`

## Arquivos criados/modificados

- `app/Services/Publicador/CabecalhoPublicadorService.php` **(novo)** — monta a prop `cabecalho`; expõe `para()` e `contadores()`
- `tests/Feature/Publicador/CabecalhoDoPublicadorTest.php` **(novo)** — 16 casos: as 9 rotas, `abas` não regrediu, `null` × `0`, D23, ERP só declarado, estado do ML sem prazo, escopo do contador, o endpoint (total/segredo/403) e dois detectores de N+1
- `app/Services/Publicador/ProgramasPublicadorService.php` — **só adição**: `contasLeves()` (nenhuma linha removida no diff)
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — `empresasDoHeader()`, const `HEADER_LIMITE`, prop em `produtos()`/`visaoGeral()`/`configuracoes()`
- `app/Http/Controllers/MlbPublicadorFaseController.php` — prop em `mostrar()` (identidade do PRODUTO, WR-B01)
- `app/Http/Controllers/MlbPublicadorLoteController.php` — prop em `index()`; `$empresa` extraída para variável
- `app/Http/Controllers/MlbAlavancasController.php` — prop em `index()`; `$empresa` extraída para variável
- `app/Http/Controllers/MlbAnuncioController.php` — prop em `massa()`/`historico()`/`meus()` (nullable; `meus()` passa o `$defasagem` já calculado)
- `routes/mlb_anuncios.php` — **só a linha nova** `publicador.empresas-header`

## Verificação

| Gate | Resultado |
|---|---|
| `phpunit tests/Feature/Publicador/CabecalhoDoPublicadorTest.php` | **16 / 16 OK**, 210 asserções |
| `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1777 / 0 falhas** (baseline GATE-04 era 1761/0 — exatamente os 16 casos novos, nenhuma regressão) |
| `phpunit tests/Feature/Phase134 tests/Feature/Phase135` | **280 / 0 falhas** |
| `phpunit tests/Feature/Phase82 tests/Feature/Phase86` | **24 / 0 falhas** (as suítes de `massa`/`historico`, que ganharam prop) |
| `git diff --stat b36037f9..HEAD -- app/Support/Publicador app/Services/Mlb/Publicacao/MlPublicacaoService.php` | **vazio** (REG-04) |
| `git diff --stat b36037f9..HEAD -- database` | **vazio** — nenhuma migration |
| `git diff b36037f9..HEAD -- routes/` | **só adições**, nenhuma linha removida (REG-05) |
| `git diff b36037f9..HEAD -- app/Services/Publicador/ProgramasPublicadorService.php \| grep '^-'` | **nenhuma linha** — método novo e nada mais |
| `git diff --diff-filter=D HEAD~1 HEAD` | **vazio** — o commit não apaga arquivo nenhum |
| Editor e `Components/Publicador/Mesa/` | **intocados** |

**Sem migration, nada a validar no VPS.** Este plano é 100% leitura: nenhuma coluna nova, nenhuma
chamada HTTP ao ML, nenhuma rota de escrita. As armadilhas de `enum`/índice/FK do MariaDB não se
aplicam aqui.

## Decisões tomadas

1. **`empresasDoHeader()` sem nenhum parâmetro.** O plano listava a assinatura
   `empresasDoHeader(Request $request)`, mas o próprio registro de ameaças do plano exige "nenhum
   parâmetro de entrada" (T-177-08). Com `Request` na assinatura, a mitigação seria um comentário;
   sem ela, é estrutural — não há de onde ler `company`/`conta`. Um parâmetro não lido também
   convidaria a próxima pessoa a começar a lê-lo.
2. **Reusar `produtosQuery()` em vez de escrever o `where(function…)` à mão.** O plano trazia a
   consulta inline. O builder existente faz exatamente aquilo e já carrega o `1 = 0` para conta sem
   âncora — reusá-lo garante o agrupamento do T-134-01 por construção, não por disciplina.
3. **`$defasagem` passado também em `visaoGeral()`**, não só em `meus()` como o plano pedia: aquela
   action também já tinha o valor em mãos, e repetir a consulta seria pagar duas vezes pelo mesmo
   número.
4. **`viewData('page')['props']` no teste, em vez de `assertInertia`.** É o padrão de toda a suíte
   `tests/Feature/Publicador` (os arquivos vizinhos não usam `assertInertia` em lugar nenhum); prova a
   mesma coisa e deixa a leitura uniforme — e é mais direto para asserções de `null` × `0`.
5. **A `aba` não é validada contra `self::ABAS`.** Ela é sempre um literal escrito no controller,
   nunca entra pelo request. A constante existe como documentação do vocabulário para o 177-05.

## Desvios do plano

### Ajustes automáticos (nenhum fora de escopo)

**1. [Regra 3 — desbloqueio] `empresaParaTela()` inline virou variável em duas actions**
- **Durante:** Task 2
- **Situação:** Em `MlbPublicadorLoteController::index()` e `MlbAlavancasController::index()` a
  chamada estava dentro do `Inertia::render`. Para o header receber o MESMO array (e não uma segunda
  resolução da conta no mesmo request), ela precisava de nome.
- **O que foi feito:** extraída para `$empresa` e usada nas duas props. Mesmo valor, mesma ordem de
  chaves, nenhuma mudança de comportamento.
- **Commit:** `399f8fe1`

**2. [Regra 2 — correção de contrato] `empresasDoHeader()` sem `Request`**
- **Durante:** Task 2
- **Situação:** a assinatura do plano permitiria IDOR por parâmetro no futuro (ver Decisão 1).
- **O que foi feito:** método sem parâmetros, com o motivo escrito no docblock.
- **Commit:** `399f8fe1`

---

**Total de desvios:** 2, ambos documentados acima. **Impacto:** nenhum aumento de escopo; os dois
existem para cumprir o que o próprio plano exige (uma resolução de conta por request; nenhum parâmetro
de entrada no endpoint).

## Problemas encontrados

- **Heredoc do bash falhou ao criar o arquivo PHP novo** (`unexpected EOF while looking for matching
  quote`), sem criar nada. Resolvido escrevendo o arquivo pela ferramenta de escrita. Vale para a
  próxima sessão nesta máquina: para arquivo PHP novo, não insistir no `cat <<'EOF'`.
- **Primeira execução do teste reprovou em 1 caso** (`contadores.produtos` = 2 em vez de 1 no caso
  D23): o helper `paginas()` já cadastra um produto (é o alvo da tela do Produto) e o teste cadastrava
  outro. Corrigido no próprio teste — não era defeito do contrato.

## Estado não alterado de propósito

`STATE.md`, `ROADMAP.md` e `REQUIREMENTS-v26.md` **não foram tocados**, e `state.advance-plan` **não
foi executado**, conforme a instrução do orquestrador: o plano 177-01 roda em paralelo nesta mesma
árvore e escrever nesses arquivos daria corrida de git-index. Quem avança o estado da fase é o
orquestrador.

## Prontidão para os próximos planos

**Pronto para 177-04/05/06 consumirem sem adivinhar:**
- O contrato completo está acima, com nomes de chave exatos.
- O header tem de sobreviver a `cabecalho = null` (3 páginas) e a `publicacoes = null` (acervo nunca
  coletado e D23) — nos dois casos **não desenhar a pílula**, nunca desenhar `0`.
- O dropdown tem `total`, `limite` e as contas em `publicador.empresas-header`; a busca incremental
  continua em `publicador.empresas-busca`.
- **O rótulo do dot é "N publicações pedem ação"**, nunca "com erro". **O ERP diz "Declarado: X" ou
  "Não informado"**, nunca "Conectado".
- **HEAD-12 é do consumidor:** este plano entrega os campos; passar `nome`, `identificador`,
  `conta_nome` e `erp.declarado` por `textoSeguro()` no JSX é trabalho das plans do header.

**Nenhuma tela foi renderizada nem conferida visualmente neste plano** — ele não toca em `.jsx`
nenhum. Toda a prova aqui é de contrato de servidor, por teste automatizado.

---
*Fase: 177-tokens-fonte-geist-e-o-publicadorheader-global · Plano 03*
*Concluído: 2026-10-12*

## Self-Check: PASSED

- `app/Services/Publicador/CabecalhoPublicadorService.php` — existe
- `tests/Feature/Publicador/CabecalhoDoPublicadorTest.php` — existe
- `.planning/phases/177-.../177-03-SUMMARY.md` — existe
- commit `399f8fe1` — existe no histórico
- rota `mlb.anuncios.publicador.empresas-header` — registrada (`artisan route:list`)
