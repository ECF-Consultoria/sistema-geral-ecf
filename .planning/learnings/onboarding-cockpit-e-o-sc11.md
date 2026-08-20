# Onboarding: o cockpit em /companies e a revogação parcial do SC-11

Escrito em 2026-08-20. Leitura obrigatória antes de mexer em progresso,
percentual ou "atividades feitas" de onboarding, e antes de mexer na aba
Onboarding de `/companies`.

## 1. O SC-11 foi revogado PELA METADE — e a metade que ficou é a que importa

O SC-11 era uma rejeição explícita do `feitos/total` do Polos. Estava escrito em
11 lugares do código, incluindo o docblock do `OnboardingSituacaoService`:

> "o que está travando, há quantos dias e de quem é a bola — **nunca
> `feitos/total`**"

Em 20/08 a Coordenação pediu volume de atividades no cockpit, e a decisão foi
**acrescentar** `progresso`, não trocar a leitura. O que continua valendo:

- **A resposta da tela continua sendo "o que trava / de quem é a bola".** É a
  coluna `Próxima ação`, e é a mais larga da tabela. O percentual é apoio.
- **Situação continua sendo vocabulário fechado**, nunca número. Um onboarding
  se lê como "Aguardando cliente", não como "45%".
- **Porcentagem não pode vazar pelo payload afora.** Existe UMA chave,
  `progresso`, com shape `{feitos,total,percentual}`. Uma `pct_conclusao` aqui e
  uma `percentual_etapa` ali é literalmente como nascem as duas verdades que a
  extração do service foi feita para impedir.

Os três invariantes acima estão travados por teste em
`tests/Feature/Phase135/OnboardingPainelPropsTest.php` — o guarda antigo
(`nenhuma_chave_de_porcentagem_...`) não foi apagado, foi **estreitado**. Se
você precisar de um quarto campo em `progresso`, vai bater em
`progresso_do_detalhe_tem_shape_fixo` de propósito.

## 2. `nao_aplicavel` sai dos DOIS lados da fração — e isso não é detalhe

`OnboardingSituacaoService::progresso()`:

```
total  = passos - nao_aplicaveis
feitos = concluidos
```

As duas alternativas foram consideradas e são **piores**:

- **Deixar no denominador**: trava o onboarding num teto abaixo de 100% para
  sempre. Quem terminou tudo que tinha para fazer nunca vê "acabou", e a barra
  passa a mentir justamente no fim, que é quando alguém olha.
- **Contar como feito**: infla o andamento de quem teve muita coisa dispensada.
  Dois onboardings com o mesmo trabalho real mostrariam números diferentes.

`total = 0` acontece de verdade (todos os passos dispensados). O percentual
devolve 0 em vez de estourar divisão por zero, e a tela mostra `—`, não `0%` —
"0%" ali seria mentira, não há atividade nenhuma a fazer.

## 3. Existem TRÊS listas de onboarding, e só uma é o caminho

Isto é a maior fonte de confusão do módulo hoje:

| Tela | Rota | Papel |
|---|---|---|
| **Cockpit** | `/companies?tab=onboarding` | **É a lista geral.** Uma linha por ONBOARDING |
| Painel antigo | `/onboarding` | Cards por empresa. Continua vivo, **não** é mais o caminho de ida |
| Detalhe | `/onboarding/{id}` | A tela de um onboarding |

`/onboarding` **não foi aposentada de propósito**: `OnboardingPainelPropsTest` e
`OnboardingPainelAcoesTest` fazem ~15 asserções em `->component('Onboarding/Painel')`.
Matar a rota quebraria a suíte inteira por uma mudança de navegação. O que mudou
foi só o "Voltar" do detalhe, que agora aponta para o cockpit — mandar a volta
para uma tela diferente da de ida é o tipo de coisa que ninguém reporta como bug
mas todo mundo sente.

**Se um dia for aposentar `/onboarding`**: os testes acima vão junto, e é
trabalho de verdade, não de dez minutos.

## 4. O cockpit lista uma linha por ONBOARDING, não por empresa

A aba nasceu com uma linha por empresa e sub-linhas expansíveis. Virou uma linha
por onboarding porque **tudo o que a Coordenação pergunta é por onboarding**:
produto, progresso, analista e próxima ação pertencem ao serviço contratado, não
à empresa. A versão por empresa era obrigada a escolher "o pior" onboarding e
escondia o resto atrás de um chevron — justamente o resto que também precisa de
alguém.

Consequência que já mordeu: a **contagem da aba** (`Onboarding (N)`) contava
EMPRESAS. Passou a contar onboardings não-concluídos, senão o rótulo diz 11 e a
tabela diz 24.

## 5. `/companies` não pagina — e o cockpit depende disso

`CompanyController::index()` usa `->get()`, sem `paginate()`. É o que permite os
KPIs do topo serem calculados sobre TODOS os onboardings enquanto a tabela
pagina só a apresentação (client-side).

**Se alguém paginar `/companies` no servidor, os cards do topo passam a contar
só a página atual e ninguém percebe** — os números continuam plausíveis. Nesse
dia, os contadores têm que virar agregação SQL no controller.

O mesmo `index()` já carrega `onboardings.passos` com projeção EXPLÍCITA de
colunas. Não tire: `valor` é JSON com métricas da conta e `ultimo_erro` guarda
texto de exceção. Arrastar os dois por empresa numa listagem sem paginação é
exatamente o que já derrubou `/dashboard` por OOM.

## 6. Os cards do topo são uma LENTE sobre as 8 situações, não estados novos

Os 5 cards (Total / Concluídos / Em andamento / Pendentes / Atrasados) agrupam
as oito situações oficiais. Cada bucket é definido pela lista de situações que
contém (`BUCKETS` em `AbaOnboarding.jsx`), e o select de Status continua dando o
filtro preciso. Não existe máquina de estados nova.

- **Atrasados** = `vencido` (o `dias_parado > sla_dias` do passo que trava).
- **Pendentes** = só `rascunho`. É o único estado em que o onboarding não
  começou: sem responsável, sem SLA correndo, sem portal visível ao cliente.
- **Em andamento** = os cinco do meio.

`GRAVIDADE` (no PHP) tem `rascunho` como **0**, mais grave que `vencido`, de
propósito — é o único estado em que o tempo passa sem que ninguém seja cobrado.
A ordenação padrão do cockpit é essa mesma régua; ela não foi inventada para a
tela.

`GRAVIDADE` está espelhada à mão no JSX. Não há tipo compartilhado entre PHP e
JS neste projeto — mexeu num, mexa no outro.

## 7. Armadilhas de ambiente que custaram tempo aqui

- **O heredoc do Bash estoura** em arquivo JSX grande (`ENAMETOOLONG` e depois
  erro de parse). Para arquivo acima de ~300 linhas, usar a ferramenta de
  escrita direta, não `cat > arquivo <<'EOF'`.
- **`php` não está no PATH do Bash tool** — usar `C:\xampp\php\php.exe`.
- **Build passando não prova nada sobre o front.** Não há ESLint: identificador
  indefinido compila e só quebra em runtime. Depois de remover imports de um
  componente, conferir na mão que nada mais os referencia.
- Este trabalho está no worktree `C:\xampp\htdocs\ecf_admin_onb`
  (`feat/onboarding-em-companies`), que é o que o servidor `:8123` serve — não
  na árvore principal, que está em `main`.

## 8. O cockpit HERDA os filtros de empresa de `/companies` — e isso esconde onboarding

Medido em 20/08 no banco local: existem **4** onboardings, e o cockpit mostra
**3**. Não é bug do cockpit — é consequência de ele morar dentro de
`/companies`, cuja query base já exclui empresa:

- com `MlbEmpresa` associada (`whereDoesntHave('mlbEmpresa')`, evita dupla
  contagem com `/mlb/empresas`);
- **sem contrato ATIVO de setor Performance** (`whereHas('contratosServico'...)`).

O onboarding invisível era o da empresa "A", cujo contrato de Performance não
está mais ativo. Ele continua `vencido`, continua existindo, e some da tela que
existe para cuidar dele.

O painel antigo `/onboarding` **não** tem esse recorte: ele consulta
`Onboarding` direto, então mostra os 4.

Consequências práticas:

- **Desativar o contrato faz o onboarding sumir do cockpit**, sem aviso e sem
  concluir nada. Se aparecer "sumiu um onboarding", confira o contrato ANTES de
  procurar bug na tela.
- Se a Coordenação quiser ver esses casos, a correção **não** é mexer na query
  de `/companies` (isso muda a aba Empresas junto). É buscar `Onboarding`
  direto num endpoint próprio para o cockpit.

Não foi alterado agora porque o recorte é o comportamento existente de
`/companies` e mudá-lo afeta outra aba — mas é uma decisão de produto pendente,
não um detalhe técnico.

## 9. O detalhe também virou cockpit (20/08) — e a ordem das etapas NÃO mudou

`Onboarding/Detalhe` ganhou cabeçalho, linha do tempo, destaque de próxima ação,
responsabilidades e atividade recente. Quatro decisões que vão morder quem
mexer depois:

**A ordem das etapas é decisão de negócio, não de layout.** `ETAPAS_FLUXO`
começa em `agendamento` desde 19/08 — nós marcamos a data e cobramos o cliente
para ela, então a reunião ABRE o processo. Foi junto com isso que
`agendar_reuniao_onboarding` perdeu a dependência do mapeamento
(`DefinicaoOnboarding` v13); sem aquilo a primeira etapa nasceria bloqueada.
Referência visual que sugira outra sequência não é motivo para mudar.

**"Reunião" não é dono, é natureza.** `dono` (cliente/interno/sistema) é
EXCLUSIVO e os três somam o total de pendências — é o que permite exibi-los
lado a lado. `reuniao` é `natureza` (COMO o item se preenche), eixo
independente: um passo "na reunião" já está contado em interno ou cliente.
Promovê-lo a quarto card irmão produz quatro números que não somam o total, e
quem conferir na mão conclui que a tela está errada. Ele aparece como
SUBCONJUNTO, com o rótulo dizendo isso. Travado por teste
(`donos_somam_o_total_de_pendencias_e_reuniao_e_subconjunto`).

**A linha do tempo tem TRÊS marcos porque o banco registra três.**
`created_at`, `iniciado_em`, `concluido_em`. Não existe "Em operação" como
estado de onboarding — o catálogo é rascunho/andamento/concluído, e concluir É
o sinal de que a empresa pode operar. Um quarto marco ficaria cinza para
sempre, inclusive nos onboardings que terminaram bem, e marco que nunca acende
ensina o time a ignorar a régua inteira.

**O feed de atividade não tem tabela própria.** Ele lê
`onboarding_passos.feito_em/feito_por/auto_em` — colunas que existiam desde
sempre e que nenhuma tela lia. Um log de auditoria próprio seria uma segunda
verdade sobre o mesmo fato e divergiria do checklist no primeiro passo
desmarcado. `auto_em` preenchido é o que distingue "o resolver fechou" de
"alguém conferiu", e essa diferença muda o quanto se confia na informação.

### Armadilha de ambiente: o scroll não é da janela

`AppLayout` põe o scroll em `MAIN.overflow-y-auto`, não no documento. Um probe
que mede `window.scrollY` para verificar navegação interna sempre vê `0` e
conclui, errado, que nada rolou. Medir `document.querySelector('main').scrollTop`.

### Armadilha de ambiente: o cwd do shell volta para a árvore principal

Comandos aparentemente idênticos rodaram ora em `C:\xampp\htdocs\ecf_admin`
(branch `main`), ora na worktree `ecf_admin_onb`. Na principal,
`FluxoOnboarding.jsx` não existe e `Detalhe.jsx` é a versão antiga — o que
parece "arquivo apagado por outra sessão" e não é. Confirmar com
`git branch --show-current` antes de concluir qualquer coisa sobre a árvore.
