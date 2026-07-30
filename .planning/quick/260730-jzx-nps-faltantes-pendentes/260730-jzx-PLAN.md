---
quick_id: 260730-jzx
tipo: quick
titulo: "NPS — faltantes sem ruído, sem empresa inativa na operação, grupo em 1 linha e detalhe do link pendente"
data: 2026-07-30
autonomous: false
files_modified:
  - app/Http/Controllers/NpsController.php
  - resources/js/Pages/Nps/Index.jsx
  - tests/Feature/NpsFaltantesGrupoEResponsavelTest.php
depends_on: []
requirements: [AJU-01, AJU-02, AJU-03, AJU-04]

must_haves:
  truths:
    - "Na aba Faltantes não existe mais o selo nem o texto 'Sem contato cadastrado'"
    - "Empresa sem ninguém atribuído (sem estrategista) não aparece mais na aba Faltantes"
    - "Grupo cuidado pelas mesmas pessoas aparece como UMA linha com a contagem de empresas, e não como N linhas"
    - "Empresa do grupo cujos responsáveis divergem continua aparecendo sozinha na lista"
    - "Em Pendentes dá para abrir o detalhe do link: endereço, botão de copiar, quem gerou, quando foi gerado, até quando vale e se é link de uma empresa ou de um grupo"
  artifacts:
    - path: "app/Http/Controllers/NpsController.php"
      provides: "Montagem dos faltantes (filtro por elegibilidade + colapso de grupo) e payload do survey"
    - path: "resources/js/Pages/Nps/Index.jsx"
      provides: "FaltantesView sem o selo de contato + linha de grupo, e modal de detalhe do link pendente"
    - path: "tests/Feature/NpsFaltantesGrupoEResponsavelTest.php"
      provides: "Prova automatizada dos ajustes 1, 2, 3 e 4"
  key_links:
    - from: "NpsController::index()"
      to: "NpsElegibilidadeService::empresasElegiveis()"
      via: "filtro do que entra em \\$faltantes"
      pattern: "elegibilidadeService->empresasElegiveis"
    - from: "NpsController::index()"
      to: "NpsGrupoCoberturaService::calcular()"
      via: "colapso de grupo uniforme em 1 linha"
      pattern: "grupoCoberturaService->calcular"
---

<objective>
Quatro ajustes pedidos pelo usuário em 2026-07-30, depois de usar a tela de NPS em produção
(logo após o deploy da Fase 119.1):

1. Tirar o selo e a explicação "Sem contato cadastrado" da aba Faltantes — o NPS roda manual
   (gera o link no sistema, copia e manda pro cliente por fora), então não ter e-mail/Digisac
   cadastrado não impede nada e o aviso virou ruído.
2. Deixar ver o detalhe do link também nos PENDENTES (não respondidos): endereço + botão de
   copiar, quem gerou, quando gerou, até quando vale e se é link de uma empresa ou de um grupo.
3. Empresa que faz parte de um grupo cuidado pelas MESMAS pessoas não aparece mais uma a uma —
   entra UMA linha do grupo, com a contagem de empresas e o botão de gerar o link do grupo.
   Quem tem responsável diferente continua aparecendo sozinho (vai precisar do próprio link).
4. Empresa sem ninguém atribuído (ainda não ativa na operação) sai da lista de Faltantes.

Propósito: a aba Faltantes é a lista de trabalho do dia — hoje ela mostra linhas que não geram
trabalho nenhum (empresa fora da operação), repete a mesma decisão N vezes (grupo) e explica
uma coisa que não é problema (contato).

Saída: `NpsController::index()` e `Nps/Index.jsx` ajustados + arquivo de teste novo.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@CLAUDE.md

Comandos deste projeto (PowerShell, PHP fora do PATH):

- Testes: `$env:Path = "C:\xampp\php;$env:Path"; php artisan test --filter=<Filtro>`
- PROIBIDO `php artisan test` sem `--filter` (a suíte inteira derruba o processo por volta de 50–55%).
- PROIBIDO rodar teste em background.
- Build obrigatório depois de mexer em `.jsx`: `npm run build` (`public/build/` é gitignored).
- Árvore de trabalho é compartilhada com outras sessões: commitar SEMPRE com caminho explícito
  (`git commit -- <arquivos>`), nunca `git add -A`, nunca `git stash`.

Baseline de testes que NÃO pode regredir (medir antes de começar):

- `--filter=Phase119_1` → 108 passed
- `--filter=Phase116` → 79 passed
</context>

<decisoes_travadas>
Decisões já tomadas com o usuário — implementar como está, não reabrir:

- **DQ-01 — Contato não muda regra nenhuma.** Sai da tela SÓ a explicação. A decisão D5 da
  Fase 119.1 continua valendo: empresa sem contato cadastrado continua contando nota 1 e
  continua elegível. Não encostar em `NpsElegibilidadeService`, não encostar em `tem_canal`.
- **DQ-02 — "Sem responsável" usa a fonte de verdade que já existe.** O corte do ajuste 4 é
  `NpsElegibilidadeService::empresasElegiveis()` (que já exige estrategista atribuído via
  `estrategistaDaEmpresa()`). PROIBIDO escrever um `whereHas('users', ...)` novo no controller
  reproduzindo a condição — seria uma segunda régua para a mesma pergunta.
- **DQ-03 — Os contadores contam EMPRESAS, nunca linhas.** Colapsar um grupo de 5 empresas em
  1 linha NÃO pode fazer o chip "Faltantes" cair de 160 para 156. Cada linha carrega
  `empresas_count`, e `contadores['faltantes']`, `contadores['contam_nota_1']` e
  `contadores['todos']` somam esse campo. Assim o colapso encurta a LISTA sem mexer em número
  nenhum, e a coerência com os cards do `NpsSemLinkService` (T-119.1-15) fica intacta.
  O único número que muda no ajuste 4 é o esperado: empresa sem responsável sai da conta.
- **DQ-04 — Colapso só a partir de 2 empresas.** A linha de grupo só substitui as individuais
  quando 2 ou mais empresas faltantes daquele setor entram na cobertura do grupo. Com 1 só,
  mantém a linha individual (uma linha "Grupo X (1 empresa)" seria pior que a original).
- **DQ-05 — A regra de "mesma dupla" é do serviço, não do controller.** Usar
  `NpsGrupoCoberturaService::calcular()` como está (já cobre a régua da maior classe de
  responsáveis e o guard de duplicidade `ja_tem_link`). PROIBIDO comparar responsáveis no
  controller.
</decisoes_travadas>

<linguagem_da_tela>
Regra sistêmica do projeto — nada de jargão sem explicação na interface.
PROIBIDO na tela: "imputação", "assignment", "replicação", "elegibilidade", "competência"
(usar "mês"), "cobertura", "escopo". Comentários de código em pt-BR.
</linguagem_da_tela>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Faltantes — sair o motivo de contato, entrar o requisito de responsável, e o survey dizer se é de grupo</name>

  <files>
    app/Http/Controllers/NpsController.php
    tests/Feature/NpsFaltantesGrupoEResponsavelTest.php
  </files>

  <read_first>
    - `app/Http/Controllers/NpsController.php` linhas 415–640 — bloco de `$contadores`, laço de
      `$faltantes` por setor, cálculo de `conta_nota_1` e a closure do `$surveys->through()`.
    - `app/Services/Nps/NpsElegibilidadeService.php` linhas 60–160 — `estrategistaDaEmpresa()`,
      `modelosAplicaveis()` e `empresasElegiveis()` (é memoizado por mês, chamar mais de uma vez
      no request não custa query nova).
    - `tests/Feature/NpsFaltantesPorModeloTest.php` — padrão de teste desta tela: traits
      `CriaCenarioResponsaveis` + `ContrataServicoNpsCoberto`, `actingAs` admin, asserção
      Inertia sobre a prop `faltantes`. Copiar esse padrão, inclusive o nome da rota usada.
    - `app/Models/NpsSurvey.php` linhas 40–110 — `group_survey_id` está em `$fillable` e tem a
      relação `groupSurvey()`.
  </read_first>

  <behavior>
    - Empresa ativa, com contrato ativo em serviço coberto por modelo automático, SEM ninguém
      atribuído como estrategista: NÃO aparece em `faltantes`.
    - A mesma empresa, depois de atribuir um estrategista no serviço: aparece em `faltantes`.
    - Nenhum item de `faltantes` traz a chave `motivo`.
    - Todo item de `faltantes` traz `tipo`, `empresas_count` e `conta_nota_1_count`.
    - Um survey pendente do mês traz no payload: `token`, `link`, `generated_by`, `created_at`,
      `expires_at` e `de_grupo` (booleano).
    - `de_grupo` é `true` quando o survey nasceu de um link de grupo (`group_survey_id`
      preenchido) e `false` quando é individual.
  </behavior>

  <action>
    Regrepar pelo NOME antes de editar (`$faltantes`, `conta_nota_1`, `elegiveisPorEmpresa`,
    `statusEfetivo`) — este arquivo foi tocado pelas Fases 116, 118 e por 3 planos da 119.1, os
    números de linha acima são referência, não endereço.

    1. **Tirar o motivo de contato (ajuste 1).** No laço que empurra itens em `$faltantes`,
       remover a chave `motivo` inteira, junto com o `empty($c->email_cliente) && empty($c->digisac_group_contact_id)`
       e com o comentário de D5/NPSMAN-12 que a explica. Tirar `email_cliente` e
       `digisac_group_contact_id` da lista de colunas do `->get([...])`, e acrescentar
       `company_group_id` (a Task 2 precisa dele). Deixar um comentário curto registrando que o
       motivo saiu porque o envio é manual e que a regra de nota 1 continua valendo (DQ-01).

    2. **Exigir responsável (ajuste 4).** Hoje `$elegiveisPorEmpresa` é montado DEPOIS do laço,
       só para calcular `conta_nota_1`. Subir esse trecho (`$this->elegibilidadeService->empresasElegiveis($mesInicio)`
       indexado por `company_id`) para ANTES do laço dos setores e usá-lo também como filtro do
       que entra em `$faltantes`: item cuja `company_id` não estiver no mapa não entra na lista.
       Comentar que `empresasElegiveis()` já exige estrategista atribuído, e que o efeito é
       alinhar a lista com o que o cálculo da nota já ignorava (DQ-02). Manter a expressão de
       `conta_nota_1` como está — ela continua correta.

    3. **Campos de agregação por linha (preparo do DQ-03).** Cada item de `$faltantes` passa a
       carregar `'tipo' => 'empresa'`, `'empresas_count' => 1` e
       `'conta_nota_1_count' => (int) $faltante['conta_nota_1']` (este último preenchido no
       mesmo laço que hoje grava `conta_nota_1`). Trocar os três contadores para somar os
       campos em vez de contar linhas: `$contadores['faltantes']` = soma de `empresas_count`,
       `$contadores['contam_nota_1']` = soma de `conta_nota_1_count`,
       `$contadores['todos']` = `$totalGeral` + soma de `empresas_count`. Comentar o porquê
       (DQ-03: a linha de grupo da Task 2 representa N empresas).

    4. **Link de grupo no payload do survey (ajuste 2, parte backend).** Na closure do
       `$surveys->through()`, acrescentar `'de_grupo' => $s->group_survey_id !== null`. É leitura
       de coluna já carregada — NÃO adicionar eager load nem relação nova (nada de N+1).

    5. **Teste.** Criar `tests/Feature/NpsFaltantesGrupoEResponsavelTest.php` (namespace
       `Tests\Feature`), com `RefreshDatabase` + as duas traits do arquivo modelo, cobrindo:
       `test_empresa_sem_estrategista_nao_aparece_em_faltantes`,
       `test_empresa_com_estrategista_aparece_em_faltantes`,
       `test_faltante_nao_expoe_chave_motivo`,
       `test_survey_pendente_expoe_dados_do_link_e_se_e_de_grupo` (um survey pendente com
       `group_survey_id` null e outro com `group_survey_id` preenchido).
  </action>

  <acceptance_criteria>
    - `grep -rn "sem_contato" app/Http/Controllers/NpsController.php` não retorna nada.
    - `grep -n "digisac_group_contact_id" app/Http/Controllers/NpsController.php` não retorna
      nada dentro do bloco de `$faltantes`.
    - `grep -n "de_grupo" app/Http/Controllers/NpsController.php` retorna a linha da closure de
      `$surveys->through()`.
    - `grep -n "empresasElegiveis" app/Http/Controllers/NpsController.php` mostra a chamada
      ANTES do `foreach ($setores as ...)`.
    - `$env:Path = "C:\xampp\php;$env:Path"; php artisan test --filter=NpsFaltantesGrupoEResponsavel`
      → todos os testes do arquivo passam (mínimo 4).
    - `php artisan test --filter=NpsFaltantesPorModelo` → continua verde.
  </acceptance_criteria>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Grupo cuidado pelas mesmas pessoas vira uma linha só nos faltantes</name>

  <files>
    app/Http/Controllers/NpsController.php
    tests/Feature/NpsFaltantesGrupoEResponsavelTest.php
  </files>

  <read_first>
    - `app/Services/Nps/NpsGrupoCoberturaService.php` inteiro — assinatura
      `calcular(CompanyGroup $grupo, NpsTemplate $template, Carbon $mes): array` devolvendo
      `referencia` / `incluidas[{company_id,name,servico_ids}]` / `excluidas[{company_id,name,motivo,...}]`.
      É ele quem já sabe a régua dos DOIS papéis por serviço coberto (D6) e a maior classe de
      responsáveis.
    - `app/Http/Controllers/NpsController.php` — construtor (linha ~58) para ver quais serviços
      já são injetados, e o bloco de `$faltantes` como ficou depois da Task 1.
    - `app/Models/CompanyGroup.php` — relação `companies()`.
    - `tests/Feature/Phase119_1/NpsGrupoCoberturaTest.php` — como montar grupo + empresas +
      responsáveis iguais/divergentes num teste.
  </read_first>

  <behavior>
    - Grupo com 3 empresas faltantes no mesmo setor, todas com o MESMO estrategista e o MESMO
      analista nos serviços cobertos: a prop `faltantes` traz 1 item com `tipo = 'grupo'`,
      `empresas_count = 3` e o nome do grupo em `name`; as 3 linhas individuais somem.
    - `contadores['faltantes']` continua 3 nesse cenário (conta empresa, não linha).
    - Grupo com 3 empresas iguais + 1 com analista diferente: a prop traz 2 itens — a linha de
      grupo com `empresas_count = 3` e a linha individual da divergente; `contadores['faltantes']` = 4.
    - Empresa de grupo cujo par já tem link no mês (só 1 empresa do grupo faltante): continua
      aparecendo como linha individual, sem linha de grupo (DQ-04).
    - Empresa sem `company_group_id` nunca vira linha de grupo.
  </behavior>

  <action>
    1. **Injetar o serviço.** Adicionar `NpsGrupoCoberturaService` ao construtor de
       `NpsController` (mesma forma dos serviços já injetados). Não instanciar com `new` no meio
       do método.

    2. **Colapsar depois do `conta_nota_1`, nunca antes.** O colapso roda como um passo próprio,
       APÓS o laço dos setores e APÓS o laço que grava `conta_nota_1`/`conta_nota_1_count`, e
       ANTES do `usort` e dos contadores. Assim a linha de grupo agrega valores já calculados.

    3. **Algoritmo (uma chamada por grupo+setor, nunca por empresa).** Agrupar as linhas atuais
       de `$faltantes` por `(setor, company_group_id)` ignorando `company_group_id` nulo. Para
       cada balde com 2 ou mais linhas (DQ-04):
       - carregar o `CompanyGroup` com `companies` (uma query só para todos os grupos
         envolvidos, via `whereIn` — PROIBIDO carregar dentro do laço por empresa);
       - chamar `calcular($grupo, $templateRepresentante, $mesInicio)` UMA vez, onde
         `$templateRepresentante` é o `NpsTemplate` do `template_id` que a linha já carrega
         (o representante do setor);
       - intersectar `incluidas` com as `company_id` das linhas faltantes daquele balde;
       - se a interseção tiver 2 ou mais empresas: remover essas linhas e inserir UMA linha de
         grupo; as linhas fora da interseção permanecem como estão;
       - se tiver menos de 2: não fazer nada com o balde.

    4. **Formato da linha de grupo** (chaves exatas, a Task 3 consome):
       `tipo = 'grupo'`, `group_id`, `name` (nome do grupo — a chave `name` é a mesma das linhas
       individuais para o `usort` existente continuar funcionando), `empresas_count`,
       `empresas_nomes` (array de nomes, para o resumo na tela), `modelo`, `template_id`,
       `setor`, `conta_nota_1` (true se ao menos uma das empresas conta) e `conta_nota_1_count`
       (quantas contam). Sem `company_id`.

    5. **Contadores.** Nada a mudar se a Task 1 já somou `empresas_count`/`conta_nota_1_count` —
       confirmar por teste que o colapso não mexeu em número nenhum.

    6. **Testes.** Acrescentar ao arquivo da Task 1:
       `test_grupo_com_mesmos_responsaveis_vira_uma_linha_unica`,
       `test_empresa_com_responsavel_diferente_continua_individual`,
       `test_grupo_com_uma_unica_empresa_faltante_nao_colapsa`,
       `test_colapso_de_grupo_nao_altera_os_contadores`.
  </action>

  <acceptance_criteria>
    - `grep -n "NpsGrupoCoberturaService" app/Http/Controllers/NpsController.php` retorna o
      `use` e a injeção no construtor.
    - `grep -c "responsavelDoServicoOuConsolidado\|company_users.role" app/Http/Controllers/NpsController.php`
      não cresce em relação ao valor medido antes da task (o controller não ganhou comparação
      de responsáveis própria — DQ-05).
    - O `calcular(` aparece exatamente uma vez em `NpsController.php`, e fora do `foreach` que
      itera empresas.
    - `$env:Path = "C:\xampp\php;$env:Path"; php artisan test --filter=NpsFaltantesGrupoEResponsavel`
      → todos passam (mínimo 8 testes somando as duas tasks).
    - `php artisan test --filter=Phase119_1` → 108 passed ou mais.
    - `php artisan test --filter=Phase116` → 79 passed ou mais.
  </acceptance_criteria>
</task>

<task type="auto">
  <name>Task 3: Tela — faltantes sem o aviso de contato, linha de grupo, e detalhe do link pendente</name>

  <files>
    resources/js/Pages/Nps/Index.jsx
  </files>

  <read_first>
    - `resources/js/Pages/Nps/Index.jsx` linhas 1179–1296 — `FaltantesView`, incluindo o
      `.map()` com as flags `ehSemContato`/`ehSemLink`/`jaContaUm` calculadas DENTRO do callback.
    - Linhas 1100–1155 — coluna de ações da tabela: hoje o pendente só tem o botão de copiar
      (`Link2`) e o respondido tem o `Eye`.
    - Linhas 2068–2103 — o bloco copiável do modal de link da Fase 119.1 (caixa
      `bg-muted` + `text-sm break-all` + `Button size="icon" variant="ghost"` alternando
      `Copy`/`CheckCircle`). É o padrão a reusar, para a tela não ficar com dois estilos de link.
    - Linhas 1440–1650 — estado do `NpsIndex`: `modoGeracao`, `grupoId`, `setData`, `copyLink`,
      `copied`, e o `onGerarLink={() => setOpen(true)}` da linha ~1918.
  </read_first>

  <action>
    Armadilha do projeto (já mordeu aqui): variável de escopo do componente usada dentro de
    `.map()` some no bundle do Rollup e vira `ReferenceError` só em produção. TODA flag derivada
    do item continua sendo calculada DENTRO do callback do `.map()`.

    1. **Tirar o aviso de contato (ajuste 1).** Em `FaltantesView`, remover a flag
       `ehSemContato`, o `<span>` "Sem contato cadastrado" e o parágrafo que explica e-mail/
       WhatsApp. O ramo `ehSemLink` (que já diz "Sem link neste mês — já está contando nota 1."
       / "...ainda dá tempo de enviar.") passa a ser o único texto da linha; como `motivo` não
       existe mais no payload, trocar a condição por `c.conta_nota_1 === true` calculada dentro
       do callback.

    2. **Linha de grupo (ajuste 3).** Dentro do `.map()`, calcular `const ehGrupo = c.tipo === 'grupo';`
       e renderizar:
       - `key`: `ehGrupo ? \`g-${c.group_id}-${c.template_id}\` : \`${c.company_id}-${c.template_id}\``;
       - título: nome do grupo seguido da contagem em texto simples — ex.: `Grupo Camillo Parts`
         + selo `5 empresas` (nada de jargão; o selo do modelo continua igual);
       - subtítulo: os nomes das empresas (`c.empresas_nomes`) em linha única com reticências, e
         `title` com a lista completa;
       - texto de estado: "Um link só vale para as N empresas — todas são cuidadas pelas mesmas
         pessoas." e, quando `c.conta_nota_1_count > 0`, acrescentar
         "N já estão contando nota 1 neste mês.";
       - botão: rótulo "Gerar link do grupo".
       Linha individual segue exatamente como está hoje (menos o item 1).

    3. **Botão do grupo abre o modal já no modo grupo.** Trocar `onGerarLink` de callback sem
       argumento para `onGerarLink(preselecao)`, onde `preselecao` é `null` (comportamento atual)
       ou `{ grupoId, templateId }`. Em `NpsIndex`, na passagem da prop (linha ~1918), quando
       vier preseleção: `setModoGeracao('grupo')`, `setGrupoId(String(grupoId))`,
       `setData('template_id', templateId)` e `setOpen(true)`. Sem preseleção, manter
       `setModoGeracao('empresa')` + `setOpen(true)`. O efeito que já busca a prévia do grupo
       (dependências `[modoGeracao, grupoId, data.template_id]`) cuida do resto sozinho.

    4. **Detalhe do link pendente (ajuste 2).** Acrescentar, na coluna de ações das linhas com
       `s.status === 'pending'`, um segundo botão (ícone `Eye`, `title="Ver detalhes do link"`)
       ao lado do de copiar, que abre um `Dialog` novo controlado por
       `const [linkPendente, setLinkPendente] = useState(null)`. Conteúdo do modal, no estilo
       dark/`ecf-*` já usado:
       - título: nome da empresa + "Link da pesquisa";
       - a caixa copiável REUSANDO o padrão do modal de link (mesma classe `bg-muted`,
         `text-sm break-all`, botão `size="icon" variant="ghost"` alternando `Copy` →
         `CheckCircle` por 2s);
       - linhas de informação em pt-BR simples: "Gerado por" (`s.generated_by` ou "Envio
         automático" quando `s.auto_generated`), "Gerado em" (`s.created_at`), "Vale até"
         (`s.expires_at` ou "Sem prazo"), "Tipo de link" (`s.de_grupo ? 'Link de grupo (vale
         para todas as empresas do grupo)' : 'Link só desta empresa'`);
       - rodapé com botão "Fechar".
       O botão de copiar existente na linha continua como está (atalho rápido).

    5. `npm run build` no fim.
  </action>

  <acceptance_criteria>
    - `grep -c "Sem contato cadastrado" resources/js/Pages/Nps/Index.jsx` → `0`.
    - `grep -c "ehSemContato" resources/js/Pages/Nps/Index.jsx` → `0`.
    - `grep -n "tipo === 'grupo'" resources/js/Pages/Nps/Index.jsx` retorna a linha DENTRO do
      callback do `.map()` de `FaltantesView` (nenhuma flag derivada de item no escopo do
      componente).
    - `grep -n "linkPendente\|de_grupo" resources/js/Pages/Nps/Index.jsx` retorna o estado do
      modal e o uso do campo.
    - `npm run build` termina com exit code 0.
    - `grep -rEc "imputa|assignment|replica|elegibilidade|competência|cobertura|escopo" resources/js/Pages/Nps/Index.jsx`
      não cresce em relação ao valor medido antes da task (nenhum jargão novo na tela).
  </acceptance_criteria>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>
    Aba Faltantes sem o aviso de contato, com grupo cuidado pelas mesmas pessoas em uma linha só
    e sem empresa que ainda não tem ninguém atribuído; e detalhe do link nos pendentes.
  </what-built>
  <how-to-verify>
    1. Abrir a tela de NPS e clicar no chip "Faltantes".
    2. Conferir que nenhuma linha traz o selo "Sem contato cadastrado" nem o texto sobre
       e-mail/WhatsApp.
    3. Procurar um grupo conhecido (ex.: Camillo Parts): deve aparecer UMA linha com o nome do
       grupo e a contagem de empresas, não N linhas repetidas. Clicar em "Gerar link do grupo" e
       confirmar que o modal abre já em "Um grupo de empresas" com o grupo e o modelo escolhidos.
    4. Conferir que empresa recém-cadastrada, sem estrategista/analista atribuído, não aparece
       mais na lista.
    5. Conferir que o número do chip "Faltantes" caiu SÓ pelas empresas sem responsável — o
       colapso do grupo não pode ter derrubado o número.
    6. Voltar para a lista de pesquisas, achar uma pendente e clicar no ícone de olho: o modal
       precisa mostrar o link, o botão de copiar funcionando, quem gerou, quando gerou, até
       quando vale e se é link de uma empresa ou de um grupo.
  </how-to-verify>
  <resume-signal>Responder "aprovado" ou descrever o que ficou errado</resume-signal>
</task>

</tasks>

<verification>
- `$env:Path = "C:\xampp\php;$env:Path"; php artisan test --filter=NpsFaltantesGrupoEResponsavel` → verde
- `php artisan test --filter=Phase119_1` → ≥ 108 passed
- `php artisan test --filter=Phase116` → ≥ 79 passed
- `php artisan test --filter=NpsFaltantesPorModelo` → verde
- `php artisan test --filter=NpsFiltroPorPessoa` → verde (o escopo de carteira dos faltantes não pode ter mudado)
- `npm run build` → exit code 0
</verification>

<success_criteria>
- Nenhuma menção a contato cadastrado sobrou na aba Faltantes (backend nem frontend), e a regra
  de nota 1 continua idêntica (DQ-01).
- Empresa sem estrategista atribuído não aparece mais em Faltantes, pelo mesmo critério que o
  cálculo da nota já usava (DQ-02).
- Grupo uniforme aparece como uma linha com a contagem de empresas; divergente continua
  individual; os contadores continuam contando empresas (DQ-03/DQ-04).
- Pendente tem detalhe do link com endereço, cópia, quem gerou, quando gerou, validade e se é
  individual ou de grupo.
- Regra de negócio nenhuma foi duplicada no controller (DQ-05).
</success_criteria>

<output>
Ao terminar, criar `.planning/quick/260730-jzx-nps-faltantes-pendentes/260730-jzx-SUMMARY.md`.

Commit com caminhos explícitos (árvore compartilhada):
`git commit -- app/Http/Controllers/NpsController.php resources/js/Pages/Nps/Index.jsx tests/Feature/NpsFaltantesGrupoEResponsavelTest.php`
</output>
</content>
</invoke>
