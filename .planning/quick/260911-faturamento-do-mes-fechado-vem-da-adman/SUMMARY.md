---
quick_id: 260911-eph
slug: faturamento-do-mes-fechado-vem-da-adman
date: 2026-09-11
type: quick
status: entregue-sem-producao
gate: 652 testes / 2966 asserções / 0 falhas (exit 0) — partiu de 632/2914
---

# O faturamento do mês fechado passa a poder vir da Adman — atrás de uma chave

## O que foi entregue

A consolidação do fechamento mensal pode ler o faturamento de ML do `/performance` da Adman
(o mesmo número da dashboard da Adman, já com os ajustes retroativos) em vez de
`SUM(adman_metrics.revenue)`, **só em mês fechado**, **só nas empresas que não são
`is_ml_driven` e têm `cust_id`**, e **só com a chave `fechamento_faturamento_da_api_ativo`
ligada** — que **nasce desligada**.

Nada mudou em produção ainda: sem ligar a chave e sem reconsolidar, todo número continua
exatamente como está hoje.

## Commits

| hash | assunto |
|---|---|
| `0d094ee6` | `feat(260911-eph): snapshot de fechamento registra a fonte do faturamento` (T3) |
| `109d4e9b` | `feat(260911-eph): rollup do fechamento ganha o faturamento da API da Adman (opt-in)` (T1) |
| `d77643a2` | `feat(260911-eph): consolidacao usa a fonte nova atras de chave, com gate de fallback` (T2 + T4) |

T2 e T4 saíram no mesmo commit por tocarem as mesmas linhas de `ConsolidarMesFechamento`
(a contagem de fontes do T4 depende da chave do T2) — separá-los exigiria staging
interativo, que não está disponível aqui.

## Gate

Filtro: `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911`

- **Antes:** 632 testes / 2914 asserções / 0 falhas (exit code 0, medido antes de editar).
- **Depois:** **652 testes / 2966 asserções / 0 falhas — exit code 0** (capturado com
  redirecionamento para arquivo e `echo $?`, antes de qualquer pipe).
- +20 testes, +52 asserções. **Nenhum teste existente foi editado.**

## Arquivos

**Criados**
- `database/migrations/2026_09_11_100000_add_faturamento_fonte_aos_fechamento_snapshots.php`
- `app/Services/Fechamento/FechamentoFonteFaturamento.php`
- `tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` (10 testes)
- `tests/Feature/Quick260911/ConsolidarComFaturamentoDaApiTest.php` (7 testes)
- `tests/Feature/Quick260911/OutrosChamadoresNaoChamamApiTest.php` (3 testes)

**Modificados**
- `app/Services/Fechamento/FechamentoRollupService.php` — `porEmpresa()` ganhou
  `$faturamentoDaApi` (último parâmetro, default `false`) e `faturamento_fonte` no retorno.
- `app/Console/Commands/ConsolidarMesFechamento.php` — lê a chave, passa o mesmo valor nas
  DUAS chamadas, grava a fonte, gate de fallback (Passo 6b) e resumo por fonte.
- `app/Models/FechamentoSnapshot.php` — constantes `FONTE_*` e `faturamento_fonte` no fillable.
- `app/Services/Fechamento/FechamentoSnapshotWriter.php` — **só comentário** (a coluna trafega
  no `fill()` existente; nenhuma linha de lógica mudou).

`FechamentoFaixaResolver.php` e `CobrancaCalculator.php` **não foram abertos para edição** —
a classificação não mudou. Nenhum `.jsx` tocado.

## A decisão que tomei sozinho (a mais importante deste quick)

**O plano dizia "T2 — quem liga a chave é SÓ a consolidação: `ConsolidarMesFechamento` passa
`true`". Implementei esse `true` condicionado a uma chave nova em `configuracoes`
(`fechamento_faturamento_da_api_ativo`, nasce desligada), e não incondicional.**

Três razões, em ordem de peso:

1. **A consolidação tem DOIS acionadores, não um.** Além do CLI, o botão "Refazer fechamento"
   da tela chama exatamente o mesmo comando (`FechamentoController` chama
   `Artisan::call('fechamento:consolidar-mes')`; o docblock da Fase 138 registra o usuário
   clicando três vezes em poucos segundos em 2026-09-03). Com a fonte nova valendo só para uma
   invocação específica do CLI — por exemplo uma opção `--faturamento-da-api` — um "Refazer"
   pela tela reescreveria agosto com o número ANTIGO e desfaria a correção **em silêncio**. A
   chave vale igual para os dois caminhos.
2. **Sem chave, a suíte passaria a fazer chamada HTTP de verdade.** 15 arquivos de teste
   consolidam competências fechadas com empresas que têm `adman_account_id` (Phase137,
   Phase138, Phase139, Phase141, Phase142 e `tests/Feature/AdminFechamentoControllerTest.php`,
   este último **fora** do filtro do gate). Com `true` incondicional, todos passariam a bater na
   rede — lentos, dependentes de máquina (o `.env` local tem `ADMAN_API_KEY` e não existe
   `.env.testing`) e caindo 100% em fallback, o que dispararia o gate do T4 e faria essas
   consolidações retornarem exit 1. As alternativas eram piores: adicionar `Http::fake()` nos 15
   arquivos mudaria os VALORES que eles conferem (a API sobrescreveria a soma diária), e fixar
   `ADMAN_API_KEY` vazia no `phpunit.xml` mexeria em configuração global compartilhada com a
   outra sessão.
3. **É o idioma do projeto para mudança de dinheiro.** `FechamentoRegraTabela::CHAVE` (Fase 141)
   e `EmpresaOperacionalRouter::CHAVE_BLOQUEIO` (Fase 124) nasceram desligadas pelo mesmo
   motivo, e permitem desligar sem deploy — indispensável numa fonte EXTERNA que pode passar a
   mentir.

Custo: **ligar a chave é um passo humano a mais em produção** (instruções no fim). Registro
isso como o principal ponto de atenção deste quick.

### Outras decisões menores

- **`porEmpresa()` com `faturamentoDaApi: true` e `$companies === null` lança
  `InvalidArgumentException`**, espelhando o guard que já existia para `somenteContratadas`:
  `cust_id` e token ML vêm da coleção; sem ela não há como aplicar o recorte.
- **Zero vindo da API é resposta, não ausência** — sobrescreve a soma diária como qualquer
  outro valor. **Mas** quando a API devolve exatamente 0 e a soma diária é positiva, sai um
  `Log::warning` nomeando a empresa ("conferir se a conta Adman foi abandonada"). Não alterei o
  número: mudar a política aqui seria inventar régua nova num valor que vira cobrança, e a
  medição da varredura (34 de 48 divergindo, +3,4%, nenhuma mudança de faixa) já cobre essa
  população. A visibilidade fica, a política não muda.
- **A API também preenche empresa sem NENHUMA linha diária** (sync que pulou o mês inteiro).
  Consequência a conhecer: uma empresa Adman-driven que hoje cairia em `sem_faturamento` pode
  passar a ter valor — é exatamente o primeiro motivo listado no docblock de
  `fetchGrossBilling()`, mas muda o estado da linha. Teste dedicado documenta o comportamento.
- **Gate do T4 com denominador restrito a quem TENTOU a API.** Empresa ML-driven, sem `cust_id`
  ou execução com a chave desligada não entram na conta e portanto nunca derrubam o fechamento.
  Teto em `FALLBACK_MAXIMO_API = 0.5`, comparação estrita (1 de 2 em fallback **não** derruba).
- **O mês corrente é barrado em DOIS lugares** (no rollup e no comando). Redundância deliberada:
  o rollup protege qualquer chamador futuro, o comando precisa do booleano para o resumo.
- **Migration só em `fechamento_snapshots`.** `fechamento_grupo_snapshots` não ganhou a coluna —
  a linha de grupo soma empresas que podem ter fontes diferentes, e o plano não pediu.

## O que encontrei e decidi NÃO fazer

- **Não coloquei `faturamento_fonte` na tela, no PDF nem no `fechamento:verificar-consolidacao`.**
  O plano é explícito em não mexer na tela; a fonte é auditável por reconsulta ao banco e pelo
  resumo impresso pelo comando.
- **Não mexi no `EnviarRelatorioFechamentoJob` nem em `CompararMensalidadeFechamento`** além de
  travá-los com teste. O comparador de mensalidade, quando rodar contra uma competência
  consolidada com a fonte nova, vai comparar o cálculo ao vivo (soma diária) contra um snapshot
  vindo da API — e mostrará diferenças de ~3,4% que **não são divergência de regra**. Não
  corrigi porque ligá-lo à API multiplicaria chamadas numa ferramenta de leitura pura; fica
  registrado como coisa a saber ao interpretar o comparador depois da virada.
- **Não reconsolidei agosto** — executor não alcança produção nem a API da Adman (plink/pscp
  bloqueados em subagente). Sem deploy, sem `.env`, sem tocar em
  `fechamento_tabela_por_empresa_ativa`.
- **Não toquei nos arquivos que não são meus** na árvore compartilhada
  (`tests/Feature/CompanyPortfolioAccessTest.php`, `public/images/*`, os .docx/.pdf da raiz,
  `design_handoff_fechamento/`, `scratchpad/`). `git status --porcelain app/ tests/ database/`
  conferido antes de cada um dos três commits; nada além dos meus caminhos foi indexado.
- **Não usei `gsd-sdk query state.advance-plan`.**
- **Não rodei `migrate` local** — banco local ~31 migrations atrás; a migration foi exercitada
  pelo SQLite da suíte (os testes novos leem e escrevem a coluna).

## Como ligar isto em produção (passo a passo, para o orquestrador)

A ordem importa: **conferir o delta antes de comunicar qualquer cobrança**.

1. Deploy normal (migration nova:
   `2026_09_11_100000_add_faturamento_fonte_aos_fechamento_snapshots`). A coluna nasce NULL nas
   linhas já congeladas — é "não sei", e é o certo.
2. **Ligar a chave:** `Configuracao::set('fechamento_faturamento_da_api_ativo', '1')`.
   Enquanto ela estiver desligada, nada muda em lugar nenhum.
3. **Reconsolidar agosto** com `fechamento:consolidar-mes --mes=2026-08` e um `--motivo=`
   descritivo — `--motivo=` é obrigatório em competência fechada (D-12) e essa trava não foi
   afrouxada.
4. **Conferir por RECONSULTA ao banco, nunca pelo stdout:**
   - contagem por fonte em `fechamento_snapshots` da competência 2026-08-01 — esperado ~48 em
     `api`, o resto em `soma_diaria`, e o mínimo possível em `soma_diaria_fallback`;
   - comparar `faixa_ordem` antes x depois: a varredura mediu **zero** mudança de faixa em
     agosto. Se alguém mudou, **pare e investigue**;
   - procurar por `[Fechamento]` em `storage/logs/laravel.log` — os warnings de fallback e de
     "API devolveu ZERO" nomeiam empresa e competência.
5. Se a Adman estiver ruim na hora, o comando **recusa sozinho** (mais da metade em fallback =
   exit 1, nada gravado). Nesse caso é só repetir mais tarde; nenhum snapshot foi tocado.
6. Para reverter a fonte sem deploy: gravar `'0'` na mesma chave e reconsolidar com `--motivo=`.
   Desligar a chave **não** reescreve competência já congelada.

## Autocheck

- Arquivos criados conferidos no disco (5/5 encontrados).
- Commits `0d094ee6`, `109d4e9b`, `d77643a2` encontrados em `git log`.
- Gate reexecutado por inteiro depois do último commit: 652 testes / 2966 asserções / 0 falhas,
  exit code 0.

**Self-Check: PASSED**
