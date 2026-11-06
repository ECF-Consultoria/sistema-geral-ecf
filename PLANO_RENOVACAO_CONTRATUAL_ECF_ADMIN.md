# ECF Admin — Plano de implementação: monitoramento e renovação contratual

> Documento de execução para Claude Code. Primeiro auditar o código existente; não implementar com base em nomes de tabelas/rotas presumidos.
>
> Revisão de 06/11/2026: decisão confirmada sobre negócios Ganho/Closed Won, critérios de reutilização de negócios abertos, rastreabilidade e prevenção de duplicidades. Conteúdo original recuperado pela prévia do documento na conversa “Acompanhamento de renovações”.

## 1. Objetivo

Automatizar a identificação de contratos de clientes próximos do vencimento e a abertura ou encaminhamento de oportunidades de renovação ao HubSpot, com visibilidade no módulo administrativo de contratos do ECF Admin. O setor comercial deve receber a oportunidade com antecedência de dois meses, sem verificação manual de planilhas ou contratos.

**Decisão confirmada:** se o negócio original estiver **Ganho/Closed Won**, preservá-lo integralmente e criar um **novo negócio de renovação** no pipeline específico, associado ao mesmo cliente/empresa e ao contrato/ciclo, com vínculo rastreável ao negócio original. Se já existir uma oportunidade para esse mesmo ciclo, conciliá-la em vez de criar outra. Negócios abertos somente podem ser reutilizados quando comprovadamente representarem aquela renovação, conforme os critérios da seção 4.3.

Essa decisão substitui qualquer orientação genérica de mover o negócio encontrado e deixa de ser uma pergunta pendente de negócio.

## 2. Contexto conhecido (validar no código)

- ECF Admin: aplicação Laravel 12 / PHP 8.2 / MySQL; ambiente local historicamente em `C:\xampp\htdocs\ecf_admin\ecf_admin`, produção `https://admin.ecfconsultoria.com.br`.
- Área administrativa: cadastro e gestão de contratos, valores, dados de clientes, início de vigência, geração de contrato no Clicksign e acompanhamento de assinaturas por integração API/webhooks.
- HubSpot: CRM usado pelo comercial para leads e negócios; existem integrações anteriores na operação.
- Contratos normalmente duram 12 meses, mas o prazo deve vir do registro efetivo do contrato, não ser fixado no código.
- Referência de repositório informada em conversas anteriores: `https://github.com/Erlon-ecf/metodologia.git`. Confirmar se contém o ECF Admin e se é a versão correta antes de modificar arquivos.
- Há um pipeline HubSpot específico para contratos próximos do vencimento, mas **o identificador/nome exato ainda não foi fornecido**. Tornar configurável e não presumir IDs.

As referências técnicas acima pertencem ao contexto original do plano; esta revisão documental não constitui auditoria do repositório nem validação da conta HubSpot.

## 3. Etapa obrigatória — descoberta e auditoria

Antes de escrever código:

1. Inspecionar a árvore do projeto, versão/framework, documentação, migrations, models, controllers, rotas, views/componentes, jobs, commands, scheduler, testes e convenções.
2. Mapear as tabelas e relacionamentos reais de empresas, clientes, contratos, versões/aditivos, datas de vigência, status de assinatura, responsáveis e vínculos externos.
3. Mapear geração de contratos, API/webhooks Clicksign, tratamento de assinatura e definição de quando um contrato passa a vigorar. Verificar se há múltiplos contratos ativos por empresa.
4. Encontrar a integração HubSpot existente, credenciais e configuração, mapeamentos de empresa/contato/negócio, retries, limites de API, logs e permissões. Reutilizar serviços existentes, sem expor credenciais nos relatórios.
5. Identificar a tela de contratos e seus filtros, consultas, paginação, permissões, componentes e indicadores atuais.
6. Verificar como scheduler e filas são executados **em produção** e como deploy/migrations são feitos.
7. Distinguir os vínculos de **negócio original** e **negócio de renovação**. Não reinterpretar um campo legado de negócio de aquisição como se fosse o negócio do novo ciclo.
8. Mapear os IDs de pipelines e etapas, incluindo a classificação efetiva de aberto, ganho e perdido. Não decidir apenas pelo nome exibido, pela tradução “Ganho” ou por um ID presumido.
9. Conferir o esquema de propriedades, associações e recursos de unicidade disponíveis na conta/API HubSpot. Definir uma correlação durável para cada contrato/ciclo antes de habilitar criação automática.
10. Produzir um resumo do estado atual com caminhos de arquivos, esquema real, riscos, lacunas e proposta de alteração mínima. **Não avançar com mudanças destrutivas ou duplicação de serviços.**

## 4. Regras de negócio

### 4.1 Elegibilidade

- A fonte da verdade para a vigência é o ECF Admin.
- Contrato elegível: vigente/assinado conforme os estados reais do sistema, com data final confiável, sem cancelamento/rescisão e sem renovação já efetivada para o mesmo ciclo.
- Antecedência padrão proposta: **2 meses de calendário** antes da data final (usar operação de calendário adequada, sem pressupor 60 dias exatos). Configuração por ambiente ou administração, com valor padrão de 2 meses, sujeita à definição pendente da seção 9.
- Executar verificação diariamente, incluindo contratos que já entraram na janela antes da implantação ou que foram corrigidos retroativamente.
- Não inferir data final por `início + 12 meses` quando o contrato tiver data final expressa; para registros legados sem data final, sinalizar necessidade de revisão em vez de disparar automaticamente.
- Renovação deve referenciar contrato e ciclo anteriores; manter histórico, sem apagar o contrato anterior.
- Se o cliente já renovou antes do disparo, não criar oportunidade obsoleta.
- Contratos já vencidos devem aparecer na interface e seguir a política de backlog a definir, sem criação massiva automática na ativação.

### 4.2 Ciclo de renovação e vínculos

Criar entidade ou estrutura equivalente **somente se a modelagem atual não contemplar isso**, com identificador estável de contrato/ciclo, empresa, data final capturada, status de processamento, timestamps de detecção/sincronização, contagem de tentativas, último erro e histórico de eventos.

Separar conceitualmente os seguintes dados; os nomes são sugestões e devem ser adaptados ao esquema real:

| Dado | Finalidade |
| --- | --- |
| Identificador da integração/conta HubSpot | Distinguir contas e ambientes; evitar colisões de correlação. |
| ID da empresa/cliente ECF e ID da empresa HubSpot | Identificar inequivocamente o cliente e suas associações. |
| ID do contrato de origem e ID imutável do ciclo | Identificar a renovação específica, inclusive quando uma empresa tiver vários contratos. |
| Chave de correlação da renovação | Identificar a mesma operação no ECF Admin e no HubSpot. |
| ID do negócio original | Negócio que originou o contrato/ciclo que está sendo renovado; nunca substituir pelo ID da renovação. |
| ID do negócio de renovação | Oportunidade específica do ciclo em processamento. |
| Referência à cadeia anterior | Permitir navegar pelas renovações sucessivas e chegar à venda inicial. |
| Datas e status locais/remotos observados | Registrar o contexto da decisão e a última conciliação. |
| Decisão e evidências da correspondência | Registrar por que o negócio foi criado, reutilizado ou encaminhado à revisão. |

Garantir unicidade por integração, contrato e ciclo, com transação/bloqueio contra concorrência. Um negócio de renovação não pode ser atribuído a dois ciclos distintos pela automação. Se existir uma negociação que agrupe contratos, tratá-la como exceção de modelagem a revisar, sem associação automática.

A chave do ciclo deve ser estável. **Corrigir uma data final, nome ou valor não cria um novo ciclo.** Uma renovação efetiva cria um novo ciclo e mantém os vínculos anteriores. Datas são atributos do ciclo, não sua única identidade.

Estados conceituais comerciais: `identificado`, `em_negociacao`, `renovado`, `nao_renovado`, `dispensado`. Estados de integração: `pendente_sincronizacao`, `sincronizado`, `erro`, `revisao_manual`, `resultado_incerto`. Mapear para estados reais sem duplicar fontes de verdade; uma falha de integração não deve apagar o resultado comercial.

### 4.3 Identificação e decisão sobre negócios no HubSpot

#### 4.3.1 Ordem de busca e validação

1. Consultar o negócio de renovação já vinculado ao **mesmo contrato/ciclo**, quando existir, e validar sua identidade e associações atuais.
2. Consultar a chave de correlação remota do ciclo, para recuperar criações anteriores cujo ID não tenha sido salvo localmente.
3. Localizar o negócio original pelo vínculo persistido com o contrato. Esse vínculo prova a origem, mas **não autoriza reutilizar o negócio para renovação**.
4. Buscar outros candidatos associados à empresa/cliente por IDs externos persistidos e verificar contrato, ciclo, finalidade, pipeline, etapa e vínculos existentes.
5. Usar nome, email ou documento somente como apoio à investigação, **nunca como prova suficiente para mover, reutilizar ou associar um negócio**.

A associação à mesma empresa é necessária, mas não basta: o cliente pode ter outras compras, serviços, expansões e renovações em paralelo. Não escolher o negócio mais recente ou o primeiro resultado como substituto da correspondência por contrato/ciclo.

#### 4.3.2 Negócio original Ganho/Closed Won — decisão confirmada

- **Preservar o negócio original integralmente:** não reabrir, mover, alterar etapa, valor, data de fechamento, proprietário, associações, atividades ou histórico para executar a renovação.
- Criar um **novo negócio de renovação** no pipeline específico e na etapa inicial configurada, desde que não exista negócio para o mesmo ciclo e que não haja ambiguidade ou criação de resultado incerto.
- Associar o novo negócio à mesma empresa/cliente validado e aos contatos pertinentes já conhecidos. Não copiar indiscriminadamente todas as associações do original.
- Persistir no ECF Admin e no novo negócio os identificadores de contrato/ciclo, a chave de correlação e o ID do negócio original. Incluir links navegáveis para o contrato e o negócio original quando viáveis.
- Manter a rastreabilidade pelo registro local e pelas propriedades do novo negócio. Uma associação nativa entre negócios pode complementar o vínculo se suportada e validada, mas não é requisito para iniciar a solução nem justificativa para alterar o original.
- Não copiar atividades antigas nem lançar a venda concluída novamente como histórico da nova oportunidade. Valores propostos para renovação devem ter semântica própria e não alterar a receita histórica do negócio original.
- Se o negócio original não for localizado com segurança, não inventar o vínculo. Registrar essa ausência; a criação sem negócio original só é permitida quando cliente/empresa e contrato/ciclo estiverem identificados, não houver candidato ambíguo e a busca por renovação existente tiver sido concluída.

Exemplo: o negócio A, ganho, originou o contrato C. Ao entrar na janela de renovação do ciclo R1, criar B com referências a A, C e R1. A permanece intacto. Reexecutar o processamento de R1 retorna B. Em uma renovação futura, criar outro ciclo e outra oportunidade, mantendo a cadeia A → B → próxima renovação.

#### 4.3.3 Negócios abertos — critérios cumulativos de reutilização

Reutilizar automaticamente um negócio aberto somente quando **todos** os critérios abaixo forem satisfeitos:

1. O negócio pertence à conta HubSpot configurada, está acessível, não está arquivado e continua aberto na consulta imediatamente anterior à alteração.
2. A empresa/cliente corresponde por identificadores confiáveis; associações conflitantes ou com várias empresas sem titular inequívoco exigem revisão.
3. Há vínculo explícito ao **mesmo contrato e ao mesmo ciclo**, por correlação remota ou por vínculo local previamente validado. Registro legado sem esses identificadores exige revisão antes da reutilização.
4. A finalidade está comprovada como **renovação desse contrato/ciclo**. Não reutilizar aquisição, upsell, cross-sell, nova contratação, outro serviço ou renovação de outro ciclo.
5. Há apenas um candidato válido; não existem divergências entre o vínculo local e a chave remota, nem associação do negócio a outro ciclo.
6. O pipeline atual é o de renovação ou está numa lista explícita de pipelines/etapas de origem autorizados para essa movimentação. Por padrão, essa lista deve estar vazia; estar aberto não autoriza mover de qualquer pipeline.
7. Não há bloqueio manual, dispensa, cancelamento ou indicação de operação comercial que conflite com a automação.
8. Os dados necessários e as evidências da decisão estão registrados, e a transição preserva o proprietário, atividades, associações pertinentes e propriedades comerciais já preenchidas.

**Se já estiver no pipeline de renovação:** vincular/conciliar e preencher somente metadados de integração necessários, sem voltar à etapa inicial nem sobrescrever a evolução comercial.

**Se estiver em pipeline de origem autorizado:** mover somente a oportunidade comprovadamente dedicada à mesma renovação para o pipeline/etapa configurados, registrando origem, destino e motivo. Uma confirmação manual de correspondência deve ficar persistida com usuário, data e evidências; não libera movimentações futuras indiscriminadas.

**Se o negócio aberto representar a venda original:** preservá-lo. A existência do contrato ou a associação à empresa não transforma aquela venda em oportunidade de renovação. Aplicar a regra de criação de nova oportunidade após verificar duplicidades.

Reler status e vínculos antes de escrever e conferir o resultado depois. Se houver mudança concorrente ou inconsistência, interromper e conciliar/revisar; não forçar transições nem presumir que uma consulta anterior mantém o estado remoto bloqueado. Validar na auditoria os mecanismos de concorrência efetivamente suportados pela integração.

#### 4.3.4 Matriz de decisão

Aplicar primeiro a busca de uma renovação já existente para o ciclo; somente depois decidir sobre nova criação.

| Situação | Ação |
| --- | --- |
| Renovação do mesmo ciclo já vinculada e aberta | Conciliar e manter; não criar outra nem reiniciar a etapa comercial. |
| Renovação do mesmo ciclo já ganha ou perdida | Preservar resultado e vínculo; não reabrir nem criar outra automaticamente para esse ciclo. Conciliar eventual divergência com o contrato. |
| Original Ganho/Closed Won; nenhuma renovação do ciclo | Preservar original e criar nova oportunidade com vínculo rastreável. |
| Candidato aberto que satisfaz todos os critérios de reutilização | Reutilizar; mover apenas quando autorizado e necessário. |
| Negócio aberto comprovadamente de outra venda/serviço/ciclo | Preservar; desconsiderá-lo como candidato. Criar a renovação se não houver outra correspondente nem ambiguidade. |
| Original perdido/encerrado, mas contrato elegível comprovado | Preservar o original; criar nova renovação somente com vínculos confiáveis e sem oportunidade do ciclo. Divergência entre venda e contrato exige revisão. |
| Nenhum negócio apropriado, cliente e contrato/ciclo identificados | Criar nova renovação e associar corretamente; registrar ausência do original quando aplicável. |
| Mais de um candidato válido, identidade incerta ou vínculo conflitante | Registrar `revisao_manual`; não mover nem criar silenciosamente. |
| ID vinculado removido, arquivado, inacessível ou associado a outro contexto | Conciliar/revisar; não interpretar como autorização automática para substituir por outro negócio. |
| Timeout ou falha após possível criação remota | Registrar `resultado_incerto` e recuperar pela correlação; não repetir criação cegamente. |

Um negócio de renovação ganho não comprova, sozinho, que a nova vigência já foi formalizada. O ECF Admin e o fluxo contratual/Clicksign continuam responsáveis pela efetivação do contrato; divergências devem aparecer para acompanhamento, sem gerar outra oportunidade do mesmo ciclo.

### 4.4 Propriedades de negócio e dados

Campos recomendados, usando propriedades customizadas somente após conferir permissões e esquema HubSpot:

- Identificador estável do contrato, identificador imutável do ciclo e chave de correlação da renovação.
- ID da empresa/cliente ECF, associação à empresa HubSpot e contatos pertinentes.
- ID do negócio original e, quando necessário para a cadeia, referência ao ciclo/negócio anterior.
- Nome do cliente, data final, dias restantes, tipo de serviço e responsável.
- Valor proposto da renovação, quando autorizado e com significado definido; não presumir que o valor do contrato anterior seja a proposta atual.
- Link interno para o contrato/ciclo e referência navegável ao negócio original.
- Tipo `renovacao` e origem `ECF Admin`.

Nome sugerido: `Renovação | Nome do cliente | contrato <ID> | ciclo <ID> | término AAAA-MM-DD`. O nome serve para leitura humana, não para unicidade ou correspondência.

Preencher pipeline/etapa inicial apenas na criação ou movimentação autorizada. Na criação, atribuir proprietário conforme regra configurada; na reutilização, preservar o responsável existente salvo decisão operacional registrada. Não sobrescrever proposta, valor negociado, previsão de fechamento ou etapa comercial em cada sincronização.

Nunca enviar dados pessoais excessivos, segredos ou documento completo sem necessidade e base de acesso. Confirmar formato, tipos e permissões das propriedades HubSpot. Criar ou ajustar empresas/contatos ausentes somente conforme o fluxo existente validado; ausência de identidade confiável exige revisão.

### 4.5 Duplicidade e sincronização

1. **Unicidade local:** estabelecer chave única por integração, contrato e ciclo. Registrar a intenção e obter bloqueio por ciclo antes de enfileirar/processar; scheduler, ação manual e retries devem usar a mesma orquestração.
2. **Correlação remota durável:** incluir a mesma chave no negócio desde a criação. Antes de criar, consultar vínculo local, ID remoto conhecido e correlação. Se o recurso de propriedade única estiver disponível e validado na conta/API, utilizá-lo como proteção adicional e tratar conflitos recuperando o registro existente.
3. **Concorrência:** uma execução por ciclo, com transações curtas e bloqueio adequado ao tempo de processamento. Não manter transação de banco aberta durante chamadas demoradas. A liberação/expiração de bloqueios não pode autorizar nova criação se houver tentativa remota de resultado incerto.
4. **Sucesso parcial:** salvar o ID remoto assim que recebido. Se falhar a persistência local, a associação à empresa ou o preenchimento posterior, recuperar o negócio pela chave e completar somente a etapa faltante; nunca recriar todo o negócio para corrigir uma associação.
5. **Timeout ou resposta perdida:** marcar `resultado_incerto` e executar conciliação. Uma busca sem resultado imediatamente após timeout não prova ausência, pois a consulta pode ainda não refletir a criação. Sem evidência conclusiva ou garantia remota validada, encaminhar à revisão antes de liberar nova criação. Retry automático da chamada de criação deve respeitar essa regra.
6. **Múltiplos resultados para a chave:** registrar conflito e exigir revisão, sem escolher arbitrariamente, apagar, mesclar ou criar outro negócio.
7. **Ciclo já encerrado:** reexecução não cria nova oportunidade. Novo contrato/ciclo efetivo recebe nova chave; não reciclar a chave nem o negócio anterior.
8. **Falhas de API:** usar filas, tentativas limitadas e backoff, respeitando `429` e limites de uso. Distinguir falhas recuperáveis, permanentes e de resultado incerto. Erros permanentes não devem gerar loop infinito.
9. **Eventos:** autenticar e deduplicar webhooks/eventos, quando existentes; conciliar sem ciclos de atualização e sem regredir o estado por evento antigo.
10. **Mudanças contratuais:** corrigir datas/metadados no mesmo ciclo e auditar. Cancelamento, rescisão ou alteração após envio devem sinalizar a oportunidade conforme política, sem apagar o histórico comercial ou reabrir negócios encerrados.

Uma consulta prévia e um índice local, isoladamente, não garantem ausência de duplicidades no sistema remoto. A implementação deve combinar esses controles com a correlação e o tratamento de resultado incerto, sem presumir suporte a idempotência nativa não validado no HubSpot.

### 4.6 Preservação do histórico e auditoria

Registrar eventos de detecção, criação, reutilização, movimentação, conciliação, falha, revisão manual e encerramento. Cada evento deve conter contrato/ciclo, empresa, chave de correlação, IDs original/renovação, data/hora, origem da execução e decisão tomada.

Para reutilização ou movimentação, registrar evidências da correspondência e valores relevantes antes/depois, incluindo pipeline e etapa. Para decisões manuais, guardar usuário e justificativa. Evitar segredos e dados excessivos nos logs.

Permitir navegar do contrato ao negócio original, à oportunidade do ciclo e aos ciclos anteriores. O vínculo entre venda original e renovação deve continuar rastreável mesmo quando nomes, datas ou proprietários mudarem. Não excluir registros anteriores nem transferir suas atividades para “limpar” o histórico.

## 5. Interface — módulo administrativo de contratos

Reaproveitar layout, rotas, permissões, filtros e paginação existentes. Proposta:

- Filtros: **Vence em até 30 dias**, **Vence em até 2 meses**, **Vencido**, **Renovado**, **Sem data de término**, **Erro de sincronização**, **Revisão manual** e **Aguardando conciliação**, além dos filtros existentes.
- Colunas ou indicadores: término, dias restantes, situação da renovação, status HubSpot, última sincronização e acesso ao negócio de renovação.
- Exibir separadamente **Negócio original** e **Negócio de renovação**, com contrato/ciclo identificado; não substituir o link antigo pelo novo.
- Cards de resumo opcionais, se compatíveis com a tela: contratos próximos do vencimento, vencidos, oportunidades enviadas, pendências/erros.
- Ações autorizadas: **Ver oportunidade no HubSpot**, **Ver negócio original**, **Sincronizar novamente**, **Revisar correspondência**, **Dispensar com justificativa** e acesso ao histórico.
- **Sincronizar novamente** deve executar a mesma lógica idempotente; não pode funcionar como atalho para criar outra oportunidade.
- A revisão deve mostrar candidatos, contrato/ciclo e motivo do bloqueio. A seleção manual deve passar pelas validações de identidade, ciclo e preservação de negócios ganhos.
- Destacar visualmente urgência sem depender só de cor. Ordenar por menor prazo; manter filtros eficientes no banco.
- Respeitar perfis e permissões administrativas existentes. Não expor dados financeiros para perfis não autorizados.

## 6. Arquitetura sugerida (adaptar à arquitetura existente)

- Serviço de domínio para calcular elegibilidade e status de renovação.
- Serviço de orquestração de renovação que coordena banco, busca/correspondência HubSpot e resultado.
- Política explícita de decisão: conciliar renovação existente, criar nova, reutilizar negócio aberto elegível ou encaminhar à revisão; nunca mover o original ganho.
- Cliente/adapter HubSpot reutilizado, sem chamadas HTTP diretamente no controller.
- Job por contrato/ciclo, despachado por comando agendado diariamente, com proteção compartilhada entre execução automática e manual.
- Persistência de status e eventos para auditoria; índices por data final, status, correlação e IDs externos.
- Camada de consulta da tela separada do processamento remoto; interface nunca deve aguardar lote de sincronização.
- Configuração segura do pipeline ID, stage ID, origens permitidas para movimentação, antecedência, habilitação e modo simulação. Nunca versionar tokens.
- Rotina de conciliação para recuperar sucessos parciais e resultados incertos, sem repetir criações indiscriminadamente.

### Fluxo lógico

```text
Scheduler diário
  -> buscar contratos elegíveis (em lotes)
  -> identificar contrato/ciclo estável e registrar intenção de forma idempotente
  -> enfileirar sincronização e obter bloqueio do ciclo
  -> revalidar elegibilidade e pesquisar renovação já existente
     -> renovação do mesmo ciclo encontrada: conciliar, preservar etapa/resultado
     -> vínculo conflitante, múltiplos candidatos ou resultado incerto:
        conciliar/revisão manual; não criar nem mover
     -> nenhuma renovação do ciclo:
        -> identificar empresa, contrato/ciclo e negócio original
        -> original ganho: preservar; criar nova renovação com referência ao original
        -> candidato aberto inequivocamente da mesma renovação:
           reutilizar conforme critérios; mover somente se autorizado e necessário
        -> negócios comprovadamente não relacionados: preservar; criar renovação
        -> nenhum negócio apropriado e identidade confiável: criar renovação
        -> identidade/finalidade ambígua: revisão manual
  -> persistir IDs separados, associações, status e trilha de auditoria
  -> completar falhas parciais no mesmo negócio; conciliar antes de repetir criação
  -> disponibilizar status, links e filtros no módulo de contratos
```

## 7. Critérios de aceite e testes

1. Contrato vigente com fim em dois meses entra na lista e gera **uma** oportunidade de renovação para o ciclo.
2. Contrato fora da janela não dispara; contrato já vencido é exibido e tratado segundo política de backlog. Testar fronteiras de meses e datas finais de mês conforme a regra de calendário definida.
3. Contrato cancelado, não assinado ou já renovado não gera oportunidade indevida; revalidar elegibilidade antes da escrita remota.
4. **Original Ganho/Closed Won:** criar nova oportunidade no pipeline/etapa configurados, associada à mesma empresa e ao contrato/ciclo, com ID do original rastreável. Verificar que a integração não envia alterações ao original e que seus dados e histórico permanecem preservados.
5. Reexecutar o cenário anterior retorna a mesma renovação; não cria uma segunda oportunidade por o original continuar ganho.
6. Negócio aberto com prova inequívoca de mesma empresa, contrato, ciclo e finalidade pode ser reutilizado. Fora do pipeline de renovação, mover apenas se a origem estiver explicitamente autorizada.
7. Negócio já no pipeline de renovação mantém a etapa, o proprietário e os dados comerciais existentes; sincronização não o devolve à etapa inicial.
8. Negócio aberto de aquisição, upsell, outro serviço ou outro ciclo permanece intacto, mesmo quando é o único negócio da empresa. Criar nova renovação somente após excluir duplicidade e ambiguidade.
9. Mesmo nome, email, documento ou associação à empresa, sem prova de contrato/ciclo/finalidade, não autoriza reutilização. Ambiguidade gera revisão manual, sem criação silenciosa.
10. Sem negócio apropriado, mas com empresa e contrato/ciclo confiáveis, criar novo e associar corretamente. Se o original for desconhecido, registrar a ausência sem inventar vínculo.
11. Múltiplos candidatos válidos, conflito de IDs ou correlação duplicada geram revisão, sem movimentar ou escolher arbitrariamente.
12. Renovação já ganha ou perdida para o mesmo ciclo não é reaberta nem duplicada. Divergência entre ganho comercial e efetivação contratual fica visível para conciliação.
13. Execuções concorrentes, scheduler e reprocessamento manual usam a mesma chave e bloqueio. Verificar que um negócio não é atribuído a dois ciclos.
14. Timeout após criação, falha de persistência local e atraso de visibilidade na busca remota não disparam recriação cega. Recuperar pela correlação ou manter em conciliação/revisão.
15. Falha na associação após criação reutiliza o ID/correlação recuperado e completa apenas a associação faltante.
16. Negócio vinculado excluído, arquivado ou inacessível não gera substituição automática. Falhas e tentativas limitadas aparecem no painel e podem ser reprocessadas com segurança.
17. Renovação subsequente gera novo ciclo e novo negócio sem sobrescrever a cadeia anterior; dois contratos ativos da mesma empresa mantêm ciclos e oportunidades independentes.
18. Correção de data final mantém a chave e a oportunidade do ciclo. Cancelamento após sincronização preserva o histórico e segue a política definida.
19. Mudança concorrente de etapa, empresa ou vínculo interrompe a movimentação quando detectada; conciliação não sobrescreve decisões comerciais nem tenta desfazer mudanças externas à força.
20. Filtros paginados, autorização, links original/renovação, histórico e desempenho funcionam com volume realista.
21. Cobrir cálculo de datas e política de decisão com testes unitários; integração com API HubSpot mockada; concorrência, timeout e sucessos parciais em testes de integração; ponta a ponta em ambiente controlado.

## 8. Plano de execução por fases

**Fase 0 — Auditoria e decisões pendentes.** Mapear código real e contratos; identificar pipeline/stage e IDs; separar negócio original e renovação; validar propriedades e mecanismo de correlação; definir tratamento de ambiguidades, vencidos e contratos com múltiplas vigências. A política de preservar Ganho/Closed Won e criar nova renovação já está decidida. Entregar inventário e proposta de migrations antes de codificar.

**Fase 1 — Domínio e banco.** Implementar cálculo de vencimento, consultas, entidade de ciclo/estado se necessária, vínculos separados, chave imutável, unicidade e auditoria. Testar matriz de decisão e concorrência. Fazer migration aditiva e reversível. Não atribuir ciclos ou finalidade a negócios legados por suposição.

**Fase 2 — Interface.** Criar filtros, status, indicadores, links separados e ações, sem acionar HubSpot ainda. Validar com o administrativo.

**Fase 3 — HubSpot.** Reutilizar integração; mapear propriedades; implementar busca de renovação existente, preservação do original ganho, criação vinculada, critérios de reutilização e movimentação limitada. Implementar conciliação, proteção contra duplicidades, logs e testes com mocks, incluindo falhas parciais.

**Fase 4 — Scheduler e filas.** Processamento incremental em lotes, bloqueio por ciclo, tentativas limitadas e backlog em modo simulação. Conferir cron/queue worker no servidor. Garantir que o botão de reprocessamento usa os mesmos controles.

**Fase 5 — Homologação.** Rodar simulação em amostra de contratos reais e apresentar separadamente negócios originais preservados, novas renovações, negócios abertos reutilizáveis e casos de revisão. Validar com comercial/administrativo. Executar testes de autorização, concorrência, timeout e preservação do histórico antes de habilitar alterações reais.

**Fase 6 — Implantação progressiva.** Feature flag; ativar primeiro para pequena amostra, monitorar erros, resultados incertos e duplicidades, expandir após validação. Manter rollback que desative automação sem apagar histórico ou excluir automaticamente negócios já criados.

## 9. Perguntas de negócio pendentes (não bloquear auditoria)

- Qual o **pipeline ID** e **stage ID** de destino no HubSpot? O nome visível é insuficiente para configuração segura.
- O prazo é **2 meses de calendário** ou exatamente **60 dias**? Proposta: dois meses de calendário.
- A vigência conta da data de início cadastrada, da assinatura no Clicksign ou de outra data contratual? Priorizar a data de término expressa no contrato.
- O que fazer com contratos já vencidos quando a função for ativada? Proposta: sinalizar/backfill controlado, sem criação massiva automática.
- Quem deve ser proprietário da nova oportunidade no HubSpot e qual etapa inicial deve recebê-la?
- Quais pipelines/etapas de origem podem conter oportunidades de renovação abertas passíveis de movimentação? Até configurar a lista, não mover automaticamente de outros pipelines; manter a criação de nova renovação quando não existir candidata e não houver ambiguidade.
- Qual política aplicar à oportunidade ainda aberta após cancelamento/rescisão, e quem resolve divergências ou correspondências manuais?

**Decisão já encerrada:** negócio original Ganho/Closed Won permanece preservado; a renovação usa um novo negócio vinculado ao cliente/empresa e ao contrato/ciclo, com referência rastreável ao original. Não reabrir essa escolha como requisito pendente.

## 10. Instrução final ao Claude Code

**Não comece implementando cegamente.** Primeiro leia o repositório real, apresente o mapa do módulo administrativo de contratos, integrações Clicksign/HubSpot, esquema e plano de arquivos afetados. Identifique divergências entre este documento e o código. Reutilize os componentes existentes, preserve compatibilidade com o restante do ECF Admin, evite alterações não relacionadas e não exponha segredos.

Implemente a decisão confirmada de preservar o negócio original ganho e criar uma oportunidade de renovação própria por contrato/ciclo, conciliando uma já existente antes de qualquer criação. Reutilize negócios abertos somente com todos os critérios de correspondência e movimentação segura. Preserve histórico, mantenha os IDs separados e trate falhas de resultado incerto sem recriação cega.

Após a auditoria, proponha a sequência de implementação mais curta e segura, com testes e validações de cada fase. Se faltar uma decisão de negócio crítica ainda listada na seção 9, pergunte objetivamente antes da ação remota que dependa dela; a decisão sobre Ganho/Closed Won não depende de nova confirmação.
