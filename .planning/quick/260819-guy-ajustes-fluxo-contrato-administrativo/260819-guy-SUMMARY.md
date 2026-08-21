---
quick_id: 260819-guy
slug: ajustes-fluxo-contrato-administrativo
created: 2026-08-19
completed: 2026-08-19
status: complete
---

# Ajustes no fluxo de contrato do Administrativo — SUMMARY

**Origem:** teste ponta-a-ponta do fluxo completo (HubSpot → Administrativo → Clicksign), rodado
em 2026-08-19 com os setores responsáveis. Feedback do usuário (5 itens) + 4 defeitos de UX
diagnosticados na mesma sessão.

## ⚠️ Consequência de produto — LEIA ANTES DE DEPLOYAR

Os quatro campos novos entraram como **obrigatórios** (decisão explícita do usuário, via
AskUserQuestion em 2026-08-19). Isso significa que **nenhuma empresa gera contrato até o
Administrativo preencher razão social, endereço, data da 1ª parcela e dia do vencimento das
demais** — inclusive as que hoje já estavam prontas.

O efeito mais importante é no disparo **automático**: o gatilho via Comercial e via webhook do
HubSpot passa a ficar sempre pendente do Administrativo. Três suítes foram ajustadas para medir
a **fiação do gate** em vez da geração automática — `GatilhoContratoComercialTest`,
`GatilhoContratoHubspotTest` e `ReavaliacaoAutomaticaTest`.

**Isso é decisão de produto, não bug.** Quem for deployar precisa saber que, no dia seguinte, a
fila de contratos para gerar vai parecer travada até alguém preencher os campos novos das
empresas em andamento.

## O que foi feito

### Descoberta que encurtou o trabalho

O modelo `.docx` da Clicksign **já tinha as quatro variáveis** (`razao_social`, `endereco`,
`data_primeira_parcela`, `dia_vencimento`) em `ContratoVariaveisModeloService::mapa()`. Saíam
`"A DEFINIR"` porque o job nunca passava `$complementos` e `data_primeira_parcela` era
`PLACEHOLDER` fixo, com o comentário *"território da Fase 131 (ADM-01)"* — trabalho já previsto
e adiado. Nenhuma mudança no `.docx` foi necessária, nenhuma variável foi renomeada (T-126-38).

### Commits

| Tarefa | Commit | O que entrou |
|---|---|---|
| 1 | `0f963524` | Migration das 4 colunas, **nullable** — a obrigatoriedade é na validação, não no schema, para não quebrar linha existente |
| 2 | `61afd807` | Campos na tela + validação no servidor + persistência + props |
| 3 | `b967b79a` | Os 4 campos entram em `ContratoDadosMinimosService::faltantes()` (regras 4, 5, 7b, 7c) |
| 4 | `1b6a7bb2` | `App\Support\Cnpj` (módulo 11, puro) + `App\Rules\CnpjValido` |
| 5 | `b9d5b88c` | `$complementos` fluindo do job até o documento |
| 6 | `573f3234` | Filename da Clicksign vira slug ASCII de razão social + serviço |
| 7 | (este) | Os quatro defeitos de UX da tela de contrato |

### Decisões tomadas durante a execução

**`dia_vencimento` é dia do mês, não data.** "Data de vencimento das demais parcelas" não é uma
data única num contrato mensal recorrente — e é exatamente o que o placeholder `dia_vencimento`
do `.docx` já esperava. Registrado em comentário na migration.

**`data_primeira_parcela` e `dia_vencimento` entram no `servicos_snapshot` na hora de CRIAR o
snapshot**, não na leitura — disciplina D-04 preservada (`ContratoClicksignService`). `endereco`
é lido ao vivo da empresa. `ContratoVariaveisModeloService` continua **puro** (T-126-40).

**`razao_social` tem fallback para `company->name`** — empresa sem razão social preenchida ainda
gera documento com o nome que existe, em vez de "A DEFINIR".

**"já tentou antes" é derivado no backend.** A linha mais antiga por serviço é a primeira
tentativa; qualquer outra do mesmo serviço já é tentativa seguinte. Substituiu o `useState({})`
que zerava a cada reload.

**Fixtures ajustadas em ~10 arquivos de teste** das fases 127/128/131/132 que dependiam da
antiga definição de "empresa completa", e CNPJs de checksum inválido corrigidos onde a validação
nova passou a exercitá-los.

### Os quatro defeitos de UX (Tarefa 7)

1. **`erro_mensagem` agora é prop.** O texto exato da recusa da Clicksign (caso real:
   `"[Clicksign] name não está em um formato válido"`) chega na tela. Já passava por `podarPii()`
   antes de gravar; a tela exibe cru, sem reprocessar.
2. **`abort(422)` virou `back()->with('error', ...)`**, igual ao ramo de emissão congelada dez
   linhas acima. A checagem no servidor continua igual (o `disabled` do client não é controle,
   T-131-04-03) — só a apresentação mudou, de página branca do Symfony para faixa dentro da tela.
3. **A tela conta que falta enviar pelo painel da Clicksign**, com link (`painel_clicksign_url`,
   prop que já existia). O rótulo de `rascunho` passou de **"Não enviado"** para **"Falta
   enviar"** — descreve a ação pendente, não uma falha. O sistema para no rascunho de propósito
   (D-02 da Fase 127-05, `ativar: false`). O mapa de `contratoStatus.js` continua com
   **exatamente 7 chaves**.
4. **`App\Rules\NomeCompletoValido`** — nome do signatário precisa de pelo menos duas palavras.
   Antes, `"teste"` só falhava ~6 minutos depois, num 400 da Clicksign.

## Testes

- Suítes afetadas (Phase126/127/131/133 + Cnpj + NomeCompleto): **318 testes, 1077 asserções, verde**
- Suíte completa após a Tarefa 5: **533 testes, 1767 asserções, verde**
- `npm run build` limpo

As "PHPUnit Deprecations" reportadas são do próprio framework, pré-existentes e não relacionadas
a este trabalho.

## Fora de escopo — continua aberto

⛔ **`260819-clicksign-erro-salvar-posicionamentos`** — o erro *"Ocorreu um erro ao salvar os
posicionamentos!"* no painel da Clicksign. Acontece dentro da UI deles, num fluxo que não passa
pela nossa API. Precisa de teste controlado em sandbox (4 hipóteses e um roteiro de isolamento
estão no todo) antes de qualquer mudança de código. Se nenhuma variação nossa reproduzir a
diferença, é chamado com o suporte da Clicksign — temos `envelope_id` e horário.

## DEPLOYADO em 2026-08-19

Commit em producao: **`bdcb9ce4`** (autorizacao explicita do usuario nesta conversa).

Conferido por **reconsulta ao banco**, nunca pela tela:

- `HEAD` no VPS = `bdcb9ce4`
- As 4 colunas existem (`Schema::hasColumn` = 1 para as quatro)
- Contagens **identicas ao baseline** medido antes do deploy: `mlb_empresas` 496,
  `companies` 194, `contrato_assinaturas` 4 — o deploy nao criou nem apagou linha nenhuma
- `administrativo_bloqueio_ativo` continua **ligado** (Fase 133, ligado mais cedo no mesmo dia)
- As 2 migrations rodaram: `2026_08_19_100000` (575ms) e `2026_08_19_100001` (36ms)
- Smoke: `/login` 200, `/administrativo/contratos` 302, `/onboarding` 302
- **Zero** `production.ERROR` novo no log apos o deploy
- Nenhum `cache:clear` executado

**Foi junto o trabalho de outra sessao** (Onboarding v10, Fase 135 — commits `c88c8b27`,
`11ea482d`, `7f89a5c9`), porque a arvore e compartilhada e esse trabalho ja estava em
`origin/main`. Zero intersecao de arquivos com este quick task, conferida antes do rebase.

### Regressao encontrada e corrigida antes de subir

O teste de PII `Phase130\LiberacaoManualEstadoRealTest::test_nenhum_prop_de_contrato_expoe_dado_de_signatario`
fixa a lista EXATA de props que o detalhe expoe, e pegou as duas props novas da Tarefa 7.
Estava certo em exigir decisao. Liberadas conscientemente em `bdcb9ce4`, com o motivo no
proprio teste: `erro_mensagem` so e seguro porque `podarPii()` roda ANTES de gravar (a protecao
mora na gravacao, nao na whitelist), e `ja_tentou_antes` e booleano derivado.

### Falhas PRE-EXISTENTES, confirmadas como nao sendo deste trabalho

Isoladas empiricamente: com as duas migrations deste quick **removidas do caminho**, as mesmas
falhas acontecem.

- `Phase14MigrationTest` (2 erros) e `AdminFechamentoControllerTest::test_update_persiste_datas_contrato`
  — a migration legada `2026_05_27_100002` le `companies.contract_start`, coluna que a
  `2026_05_27_100003` ja dropou; no SQLite, coluna inexistente entre aspas duplas vira **string
  literal**, entao o `Carbon::parse()` recebe `'contract_start'`. Problema de desenho do teste.
- `Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` — polo renomeado
  para "Serra Gaucha" em `ONB_POLO_OPCOES` mas `GRANTS_POR_POLO_PADRAO` ficou com a chave
  "Bento Goncalves". **Bug real, ja em producao**, registrado em
  `.planning/todos/pending/260819-grant-serra-gaucha-chave-antiga.md`.
- A **suite completa nao roda localmente**: trava em `MercadoLivreAdsService.php:215` e estoura
  o limite de 300s do PHP. Tambem pre-existente. A cobertura usada como gate foi o dominio de
  contrato inteiro: **597 testes, 1989 assercoes, verde**.

## Não deployado

Nada foi para produção. As migrations rodaram só no banco local. O deploy publica o trabalho de
todas as sessões que compartilham a árvore e precisa de autorização explícita.

## HOTFIX 2026-08-20 — classe nao commitada quebrou producao com 500

`App\Support\NomeCompleto` (Tarefa 7 item 4) **existia no disco local mas nunca foi commitada**.
Efeito: a suite passava aqui (arquivo presente) e producao quebrava
(`Class "App\Support\NomeCompleto" not found`).

Quem chama e `ContratoDadosMinimosService::faltantes()`, na regra 3: `blank()` primeiro,
`NomeCompleto::valido()` depois. Ou seja **`/administrativo/contratos/{company}` dava 500 em toda
empresa com `nome_contato` PREENCHIDO** — justamente quem ja tem cadastro completo e contrato
assinado. Empresa com o campo vazio escapava pelo curto-circuito do `blank()`, o que fez o bug
parecer "so acontece em contrato assinado" quando o usuario reportou.

3 ocorrencias em producao, todas entre o deploy de `bdcb9ce4` (19/08) e o hotfix.

**Causa:** commit por caminho explicito (`git commit -- <paths>`, disciplina obrigatoria na arvore
compartilhada) sem conferir arquivo NOVO nao rastreado no lote — e
`git status --porcelain --untracked-files=no`, usado o tempo todo para ignorar o WIP das outras
sessoes, **esconde exatamente esse caso**. Passou por todos os gates verdes e por um deploy inteiro.

**Fix:** `db22dbf2` — a classe e o teste de unidade dela (4 casos, que tambem estava fora).
Deployado e conferido por reconsulta: arquivo presente no VPS e `faltantes()` executado com sucesso
numa empresa real com `nome_contato` preenchido (company 396), o caminho exato que quebrava.

**Licao registrada:** antes de commitar/deployar, rodar
`git status --porcelain app/ resources/ tests/ database/` **sem** `--untracked-files=no` e conferir
os `??`.
