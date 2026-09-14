<?php

namespace App\Support\Onboarding;

use App\Models\OnboardingPasso;
use App\Models\Servico;

/**
 * DefinicaoOnboarding — a "receita" do onboarding de cada serviço, em código.
 *
 * Substitui as tabelas `onboarding_templates`/`template_passos` e a tela de
 * builder que existiam antes. O motivo da troca: existia UM template real
 * (Gestão), o processo muda talvez duas vezes por ano, e para isso havia tela
 * de admin, versionamento por linhas, guarda de ciclo e diálogo de migração —
 * maquinaria demais para o uso real.
 *
 * O QUE NÃO SE PERDEU NA TROCA: o onboarding em andamento continua NÃO mudando
 * debaixo do cliente. Antes isso vinha de as linhas de template nunca sofrerem
 * UPDATE; agora vem de `montarPassos()` COPIAR esta definição para colunas do
 * próprio `onboarding_passos`. Cada onboarding carrega a definição com que
 * nasceu — deployar uma mudança aqui não mexe em quem já está rodando.
 *
 * `VERSAO` sobe a cada mudança nesta definição. Ela é carimbada em
 * `onboardings.definicao_versao` no nascimento e serve para responder "sob qual
 * receita esta empresa entrou?" sem depender do histórico do git.
 */
class DefinicaoOnboarding
{
    /**
     * Versão da definição. SUBIR sempre que qualquer passo desta classe mudar
     * (acréscimo, remoção, mudança de dono/dependência/SLA).
     *
     * v2 — entra o passo `ficha_conta_preenchida` (ordem 2): as 7 informações
     * de "Métricas e situação da conta" DECLARADAS pelo cliente, antes de
     * existir qualquer grant. Os demais passos desceram uma posição.
     *
     * v3 — entra `relatorio_inicial` (ordem 14, PDF §3), e `reuniao_realizada`
     * passa a depender dele: a reunião não acontece sem o documento que ela
     * existe para apresentar.
     *
     * v4 — entram `grupo_criado` e `mensagem_boas_vindas` (PDF §7), que o
     * documento pede como parte do "acompanhamento inicial da entrada da
     * empresa".
     *
     * v5 — o negócio INVERTEU a premissa da ficha. As 7 informações de
     * "Métricas e situação da conta" não são declaradas pelo cliente: são
     * puxadas depois que ele autoriza o grant. Saíram `ficha_cliente_recebida`
     * (upload de documento, que nunca foi pedido) e `ficha_conta_preenchida`
     * (formulário manual). `grant_sistema_ecf` virou a PRIMEIRA ação do cliente
     * e `acesso_colaborador_ml` passou a depender dele — antes era o contrário.
     *
     * v6 — os QUATRO itens de "Configuração de acessos" passam a ser do
     * cliente: `planilha_custos_adman` e `grant_consultoria_adman` saem de
     * `dono=sistema` e viram `dono=cliente`. O `auto_fonte` dos dois NÃO muda —
     * o sistema continua detectando sozinho quando acontecem. É D-19 em ação:
     * `dono` responde "de quem é a bola", `auto_fonte` responde "como o sistema
     * sabe". Antes disso o cliente não via nem era cobrado por dois dos quatro
     * acessos que só ele pode conceder.
     *
     * Entra também a `etapa` de cada passo — o bloco em que ele aparece, tanto
     * no painel interno quanto no portal do cliente.
     *
     * v7 — sai `grupo_criado` ("Grupo de WhatsApp criado"). O negócio disse
     * que esse passo não deve existir no onboarding. `mensagem_boas_vindas`
     * dependia dele e passou a não depender de nada.
     *
     * A `ordem` dos demais NÃO foi renumerada: ela só serve para ordenar, e
     * mexer em 13 números para fechar um buraco de um introduz risco sem
     * ganho nenhum — a lista continua saindo na mesma sequência.
     *
     * v9 — `grant_consultoria_adman` passa a depender de `grant_sistema_ecf`
     * em vez de `planilha_custos_adman`: é a ordem real do processo (autoriza
     * o sistema, depois concede à Consultoria).
     *
     * v8 — `metricas_da_conta` deixa de depender de `planilha_custos_adman`.
     * A ficha da conta é do Mercado Livre; a Adman só fornece faturamento, e
     * o resolver já conclui sem ela. A dependência travava a ficha inteira
     * esperando um cadastro na Adman que não tem relação com ela.
     *
     * v10 — saem CINCO passos `dono=interno` que o negócio disse não fazerem
     * parte do onboarding: `mensagem_boas_vindas`, `confirmacao_pagamento`,
     * `excluir_anuncios_inativos`, `grant_de_ads` e `relatorio_inicial`.
     * Nenhum era do cliente — o portal público não muda.
     *
     * `reuniao_realizada` era o único passo com dependência para dois deles
     * (`confirmacao_pagamento` e `relatorio_inicial`) e passou a depender só do
     * agendamento. Sem esse ajuste a reunião nasceria BLOQUEADA para sempre,
     * esperando passos que não existem mais.
     *
     * O que NÃO saiu com o passo: `RelatorioInicialService`, o resolver, a
     * tabela `onboarding_relatorios` e a tela `RelatorioInicial.jsx` seguem de
     * pé. Só a linha da régua foi removida — apagar a máquinaria junto tornaria
     * a volta atrás caríssima, e ninguém pediu isso.
     *
     * A `ordem` segue não renumerada, pelo mesmo motivo da v7.
     *
     * v11 — todo passo passa a declarar `natureza` (COMO o item se preenche):
     * `acao` | `reuniao` | `pergunta`. Os 9 passos existentes são todos
     * `acao` e NADA neles muda — título, dono, SLA e dependências seguem
     * iguais. A versão sobe porque a receita ganhou um campo estrutural, que
     * é copiado no nascimento: quem nasceu antes carrega `natureza` nula e o
     * front a lê como `acao`.
     *
     * O eixo existe porque os itens que o negócio pediu em 2026-08-19 —
     * conduzir na reunião × responder uma pergunta — são AMBOS `dono=interno`.
     * Sem eixo próprio a tela não distingue os dois.
     *
     * v13 — a REUNIÃO passa a abrir o processo, e entra o §15 do PDF.
     *
     * 1. `agendar_reuniao_onboarding` perde as duas dependências
     *    (`metricas_da_conta`, `anuncios_ativos_inativos`). O negócio inverteu
     *    a premissa: não esperamos o cliente conceder acesso para então marcar
     *    a call — nós definimos a data assim que a empresa chega e cobramos o
     *    cliente para ela. Enquanto o passo dependia da coleta, a data ficava
     *    bloqueada por algo que só o cliente destrava, que é exatamente o
     *    contrário de conduzir.
     *
     * 2. Entram `gravacao_informada` e `gravacao_acesso_explicado` (ordem 16 e
     *    17), os dois itens do §15/§19 que não tinham linha nenhuma na régua —
     *    uma seção inteira do documento sem representação no checklist.
     *    Deliberadamente NÃO dependem de `reuniao_realizada`: avisar que a call
     *    é gravada é coisa que se diz junto com o convite, não depois dela.
     *
     * 3. Cinco títulos ganharam os acentos que faltavam ("Participantes das
     *    reuniões cadastrados" e companhia). Um deles é `dono=cliente` e
     *    aparecia sem acento no portal do cliente.
     *
     * Quem já está rodando não recebe os dois passos novos sozinho — é o que
     * `onboarding:aplicar-passos-novos --apply` existe para fazer. O mesmo vale
     * para os títulos e para a dependência solta: são COPIADOS no nascimento,
     * então quem já existe segue com o valor antigo até
     * `onboarding:sincronizar-dependencias --apply` (dependência) rodar. Os
     * títulos antigos ficam como estão — reescrevê-los exigiria mexer no
     * congelamento, e ninguém pediu isso.
     */
    /**
     * ### v18 (14/09) — sai `analista_definido`
     *
     * O passo fechava sozinho quando o slot de analista do onboarding estava
     * preenchido. Desde a Fase 154 quem preenche esse slot é a DISTRIBUIÇÃO,
     * antes de o onboarding começar: o item nascia e fechava no mesmo instante,
     * sem nunca ter sido trabalho de ninguém. Um checklist que se fecha sozinho
     * na criação é ruído — ocupa linha no denominador do progresso e não diz
     * nada a quem lê.
     *
     * Nada dependia dele (`depende_de` vazio em toda a régua), então a remoção
     * não deixa passo esperando fantasma — a cascata que o
     * `onboarding:remover-passos-fora-da-regua` existe para resolver não se
     * aplica aqui.
     *
     * Quem JÁ está rodando mantém a linha até alguém rodar aquele comando com
     * `--apply`. Ele é destrutivo (leva `feito_por`/`feito_em` junto) e por
     * isso não roda sozinho: onboarding novo simplesmente nasce sem o passo.
     *
     * `AUTO_FONTE_ANALISTA_DEFINIDO` e o resolver dele continuam no código, de
     * propósito — as linhas antigas ainda os referenciam enquanto existirem.
     */
    /**
     * ### v19 (14/09) — os "explicados" deixam de esperar a reunião
     *
     * Os sete itens de publicidade e ADMAN dependiam de `reuniao_realizada`. A
     * precedência era de PROCESSO, não técnica: nada impede registrar que algo
     * foi explicado antes de alguém marcar que a reunião aconteceu — na prática
     * é o contrário, porque quem está explicando está NA reunião.
     *
     * Três coisas quebravam por causa dela:
     *
     * 1. No portal, `reuniao_realizada` é interno e nem aparece. O cliente lia
     *    "Liberamos assim que..." apontando para algo invisível, e o card caía
     *    na frase genérica porque o título de passo interno não vaza (§ da
     *    T-135-11-02).
     * 2. A equipe, operando o portal DURANTE a reunião, não conseguia registrar
     *    o que tinha acabado de explicar.
     * 3. Uma pendência segurava todas as outras. O pedido do negócio foi
     *    literal: "às vezes uma pendência precisa ser deixada e avançar as
     *    outras".
     *
     * Os dois irmãos que não estão no portal — `publicidade_operacao_explicada`
     * e `adman_funcionamento_explicado` — perderam a dependência junto. São da
     * mesma família e deixá-los presos criaria comportamento diferente entre
     * itens idênticos, sem razão que alguém consiga explicar depois.
     *
     * `reuniao_realizada` continua existindo e continua sendo cobrada — só não
     * é mais porteira.
     *
     * Quem JÁ está rodando segue com a dependência antiga até alguém rodar
     * `onboarding:sincronizar-dependencias --apply`: `depende_de` é COPIADO no
     * nascimento do passo.
     *
     * v20 — a régua encolhe de 18 para 9 passos. O negócio decidiu, em 14/09,
     * que o onboarding é conduzido pelo PORTAL do cliente, em reunião com a
     * tela compartilhada, e que "o que não está no portal pode descartar, já
     * que não vamos mais usar essas etapas".
     *
     * Saíram NOVE:
     *
     * - `planilha_custos_adman`, `grant_consultoria_adman` e `custos_app_ecf` —
     *   os três que o negócio já tinha tirado do portal por não saber explicar
     *   do que tratam. Item que ninguém sabe explicar não vira cobrança;
     * - `metricas_da_conta` — substituído pela Fotografia da Conta, que
     *   responde a mesma pergunta ("como está a conta?") com faturamento de 13
     *   semanas e o corte de quando a ECF entrou;
     * - `ponto_contato_definido` e `participantes_reuniao_cadastrados` — a
     *   resposta mora no cartão "Resumo do cliente" da ficha, preenchida por
     *   `BlocoContatos`. O item de checklist só cobrava um clique a mais de
     *   quem acabou de preencher o formulário;
     * - `publicidade_operacao_explicada`, `adman_funcionamento_explicado` e
     *   `adman_preenchimento_interno` — os irmãos internos dos que ficaram.
     *
     * FICOU `reuniao_realizada`, por escolha explícita do negócio: é o registro
     * de que a call aconteceu, e é o que o cartão "Agenda" mostra. É o único
     * passo da régua que não é operado no portal.
     *
     * O que se perde junto, e é consciente: com `metricas_da_conta` fora,
     * ninguém mais apura reputação, medalha de parceiro e Full da conta. O
     * faturamento continua, pela Fotografia. A MAQUINARIA fica de pé —
     * `MetricasContaResolver`, `OnboardingMapeamentoService` e as rotas de
     * sincronizar/confirmar seguem existindo, como em v10 se fez com o
     * relatório inicial: apagar junto tornaria a volta atrás caríssima.
     *
     * Quem JÁ está rodando continua com os 18 até alguém rodar
     * `onboarding:remover-passos-fora-da-regua --apply` — a definição é COPIADA
     * no nascimento, e é isso que impede o processo de mudar debaixo de quem
     * está no meio dele.
     */
    public const VERSAO = 20;

    /**
     * As chaves que o PORTAL opera — a régua de quem aparece lá, no lugar do
     * antigo `dono = cliente`.
     *
     * ### Por que deixou de ser `dono`
     * O portal nasceu como "o que o cliente faz sozinho", e por isso filtrava
     * por `dono`. A decisão de 14/09 mudou o uso: o onboarding passa a ser
     * OPERADO no portal — analista e cliente juntos, na reunião, com a tela
     * compartilhada, do mesmo jeito que o portal de Polos sempre funcionou.
     * Com isso, "quem é o dono do passo" deixou de ser a mesma pergunta que
     * "este passo aparece no portal", e usar uma como proxy da outra passou a
     * dar resposta errada nos dois sentidos.
     *
     * `dono` continua existindo e continua significando o que sempre
     * significou — de quem é a responsabilidade. Só não decide mais a
     * visibilidade.
     *
     * ### Lista fechada, de propósito
     * Passo novo NÃO entra no portal sozinho: entra aqui, por decisão. O
     * inverso — herdar a visibilidade de um atributo — é como
     * `ponto_contato_definido` foi parar na frente do cliente sendo trabalho
     * interno.
     *
     * @var array<int, string>
     */
    public const CHAVES_NO_PORTAL = [
        // Acessos — o cliente age, e o sistema confirma sozinho.
        'grant_sistema_ecf',
        'acesso_colaborador_ml',
        // Retrato da conta — o cliente ACOMPANHA, não preenche. Era
        // `dono=sistema` e por isso nunca apareceu para ele.
        //
        // `metricas_da_conta` saiu da RÉGUA inteira na v20, substituído pela
        // Fotografia da Conta — um retrato de faturamento de 13 semanas, com o
        // corte de quando a ECF começou a operar. Dois blocos que diziam "como
        // está a conta" de formas diferentes viraram um.
        'anuncios_ativos_inativos',
        // Os "explicados": eram `dono=interno`. Entram porque são exatamente o
        // que se faz COM o cliente na chamada — explicar e alinhar. Todos têm
        // `auto_fonte=confirmacao_respondida`, então fecham por resposta
        // registrada (com observação), nunca por checkbox solto.
        'publicidade_processo_explicado',
        'publicidade_investimento_explicado',
        'publicidade_responsabilidades_alinhadas',
        'adman_uso_explicado',
        'adman_responsabilidades_alinhadas',
    ];

    /**
     * ⚠️ A lista acima é hoje a régua INTEIRA menos um.
     *
     * Em 14/09 o negócio decidiu descartar tudo o que não é operado no portal
     * — v20. Dos dezoito passos sobraram nove: os oito acima e
     * `reuniao_realizada`, mantido por escolha explícita como registro de que
     * a call aconteceu.
     *
     * A distinção continua valendo e NÃO virou redundante: `apareceNoPortal()`
     * é o que faz a ficha interna marcar quais itens mudam de estado sem
     * ninguém tocar nela. E é lista fechada — passo novo não entra no portal
     * por herdar `dono=cliente`, que foi como `ponto_contato_definido` foi
     * parar na frente do cliente sendo trabalho interno.
     *
     * `analista_definido` saiu antes, na v18: é resolvido pela distribuição
     * (Fase 154), e pedi-lo de novo no onboarding era perguntar o que o
     * sistema já sabe.
     */
    public static function apareceNoPortal(string $chave): bool
    {
        return in_array($chave, self::CHAVES_NO_PORTAL, true);
    }

    /**
     * Devolve os passos do serviço, ou `null` quando o serviço não tem
     * onboarding definido — o chamador trata `null` como "não gera onboarding",
     * nunca como lista vazia.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function paraServico(Servico $servico): ?array
    {
        if (! self::eGestao($servico)) {
            return null;
        }

        return self::gestao();
    }

    /**
     * Instrução que o CLIENTE lê no portal, por `chave` de passo.
     *
     * Mora em código e NÃO é copiada para `onboarding_passos` — ao contrário de
     * `etapa`/`dono`/`sla_dias`, que são estrutura e por isso congelam no
     * nascimento. Instrução é TEXTO: corrigir uma frase confusa precisa
     * alcançar justamente quem já está travado por não tê-la entendido.
     * Congelá-la faria o cliente que mais precisa da correção nunca recebê-la.
     *
     * Chave sem instrução devolve `null` e o portal não renderiza a linha —
     * mesmo comportamento de antes de este mapa existir.
     */
    public static function instrucaoDe(string $chave): ?string
    {
        return self::INSTRUCOES[$chave] ?? null;
    }

    /**
     * Só os passos `dono=cliente` precisam de instrução — os demais o cliente
     * nem vê. Texto em 2ª pessoa, direto, sem jargão interno ("grant", "OAuth",
     * "cust_id" não significam nada para quem está do outro lado).
     */
    private const INSTRUCOES = [
        'grant_sistema_ecf' => 'Clique em "Autorizar acesso" e entre com a conta do Mercado Livre da sua empresa. '
            . 'Você será levado para uma página do próprio Mercado Livre — nós não vemos sua senha. '
            . 'É esta autorização que permite buscarmos seus dados automaticamente; sem ela, as próximas etapas ficam paradas.',

        'acesso_colaborador_ml' => 'No Mercado Livre, acesse "Meu perfil" → "Usuários e permissões" → "Convidar usuário" '
            . 'e envie o convite para o e-mail que combinamos com você. Isso dá à nossa equipe acesso operacional '
            . 'à conta, sem compartilhar sua senha. Quando terminar, marque este item como feito.',





    ];

    /**
     * URL do vídeo-tutorial que o cliente assiste no card do passo, por `chave`.
     *
     * Mesma natureza de `INSTRUCOES` — CONTEÚDO, não estrutura: mora em código,
     * não é copiada para `onboarding_passos` e por isso NÃO faz `VERSAO` subir.
     * Trocar o link de um vídeo precisa alcançar quem já está no meio do
     * onboarding, exatamente como a correção de uma frase confusa.
     *
     * Chave sem vídeo devolve `null` e o portal não renderiza o botão — mesmo
     * contrato do `TutorialBtn` do portal de Polos (`if (!url) return null`).
     * O mapa nasce vazio de propósito: nenhuma URL foi inventada aqui. Basta
     * colar o link do YouTube na chave correspondente para o botão aparecer.
     */
    public static function tutorialDe(string $chave): ?string
    {
        return self::TUTORIAIS[$chave] ?? null;
    }

    /**
     * @var array<string, string>
     */
    private const TUTORIAIS = [
        // 'grant_sistema_ecf'       => 'https://www.youtube.com/watch?v=...',
        // 'acesso_colaborador_ml'   => 'https://www.youtube.com/watch?v=...',
        // 'planilha_custos_adman'   => 'https://www.youtube.com/watch?v=...',
        // 'grant_consultoria_adman' => 'https://www.youtube.com/watch?v=...',
        // 'custos_app_ecf'          => 'https://www.youtube.com/watch?v=...',
    ];

    /**
     * Passo a passo em TEXTO por `chave` — o que o portal de Polos provou ser o
     * recurso mais usado do checklist: o cliente que não assiste vídeo ainda
     * consegue seguir a numeração.
     *
     * Shape (igual ao `PassoAPassoModal` de Polos, para o componente ser o
     * mesmo desenho): `titulo`, `saudacao`, `passos` (lista numerada) e
     * `atencao` (caixa âmbar, opcional — só onde existe uma pegadinha real).
     *
     * Complementa `instrucaoDe()`, não a substitui: a instrução é o parágrafo
     * curto sempre visível no card; isto é o detalhe que abre em modal quando
     * o cliente empaca. Chave sem entrada devolve `null` e o botão não aparece.
     *
     * @return array{titulo: string, saudacao: string, passos: array<int, string>, atencao: ?string}|null
     */
    public static function passoAPassoDe(string $chave): ?array
    {
        return self::PASSO_A_PASSO[$chave] ?? null;
    }

    /**
     * A caixa `atencao` de `grant_sistema_ecf` e `grant_consultoria_adman` é o
     * mesmo alerta institucional que o portal de Polos carrega há meses
     * (`ADMAN_PASSO_A_PASSO.atencao`): vincular a conta ERRADA do Mercado Livre
     * é o erro que mais volta como retrabalho, porque só aparece dias depois,
     * quando os dados que chegam não são os da loja do projeto.
     *
     * @var array<string, array{titulo: string, saudacao: string, passos: array<int, string>, atencao: ?string}>
     */
    private const PASSO_A_PASSO = [
        'grant_sistema_ecf' => [
            'titulo'   => 'Como autorizar o acesso ao Mercado Livre',
            'saudacao' => 'A autorização é feita na página do próprio Mercado Livre e leva menos de um minuto:',
            'passos'   => [
                'Abra o Mercado Livre neste mesmo navegador e confirme que está logado na conta da empresa que participa do projeto.',
                'Volte para esta página e clique em "Autorizar acesso".',
                'Você será levado para uma tela do Mercado Livre pedindo confirmação — revise se o nome da conta que aparece é o da sua loja.',
                'Confirme a autorização. Você volta automaticamente para este portal.',
                'O item fica marcado como concluído sozinho, sem você precisar avisar.',
            ],
            'atencao'  => 'O vínculo precisa ser feito com a conta do Mercado Livre participante do projeto, e não com uma conta pessoal '
                . 'ou outra conta que não será utilizada. Por isso, antes de clicar em "Autorizar acesso", abra o Mercado Livre no mesmo '
                . 'navegador e confirme se está logado na conta correta.',
        ],

        'acesso_colaborador_ml' => [
            'titulo'   => 'Como convidar nossa equipe na sua conta',
            'saudacao' => 'O convite dá acesso operacional à nossa equipe sem que você compartilhe sua senha:',
            'passos'   => [
                'No Mercado Livre, clique no seu nome (canto superior direito) e acesse "Meu perfil".',
                'Abra a seção "Usuários e permissões".',
                'Clique em "Convidar usuário".',
                'Informe o e-mail que combinamos com você e envie o convite.',
                'Volte a este portal e marque o item como feito.',
            ],
            'atencao'  => null,
        ],

        'planilha_custos_adman' => [
            'titulo'   => 'Como vincular a planilha de custos na Adman',
            'saudacao' => 'É a planilha de custos que permite calcular sua margem real por anúncio:',
            'passos'   => [
                'Acesse sua conta na Adman.',
                'Abra a área de custos dos produtos.',
                'Vincule a planilha de custos da sua operação.',
                'Confirme que os produtos aparecem com os custos preenchidos.',
                'Não precisa avisar: assim que o vínculo existir, detectamos automaticamente e o item fecha sozinho.',
            ],
            'atencao'  => null,
        ],

        'grant_consultoria_adman' => [
            'titulo'   => 'Passo a passo para o acesso na Adman',
            'saudacao' => 'Olá! Para liberar o acesso da ECF na Adman, o processo é bem simples:',
            'passos'   => [
                'Acesse o link de criação de conta da Adman.',
                'Clique em "Criar uma conta".',
                'Preencha os dados solicitados no cadastro.',
                'Antes de fazer o vínculo com o Mercado Livre, confirme que você está logado no mesmo navegador com a conta principal do Mercado Livre que participará do projeto.',
                'Faça o vínculo da Adman com essa conta do Mercado Livre.',
                'Na Adman, conceda acesso à ECF Consultoria.',
            ],
            'atencao'  => 'O vínculo precisa ser feito com a conta do Mercado Livre participante do projeto, e não com uma conta pessoal '
                . 'ou outra conta que não será utilizada no projeto. Por isso, antes de acessar o link da Adman, abra o Mercado Livre no '
                . 'mesmo navegador e confirme se está logado na conta correta.',
        ],

        'custos_app_ecf' => [
            'titulo'   => 'Como preencher os custos no App ECF',
            'saudacao' => 'São os custos que transformam faturamento em margem — sem eles conseguimos mostrar quanto você vendeu, mas não quanto sobrou:',
            'passos'   => [
                'Acesse o App ECF com o login que enviamos para você.',
                'Abra a lista de produtos da sua conta.',
                'Preencha o custo de cada produto — comece pelos que mais vendem.',
                'Salve e confira se nenhum produto ficou sem custo.',
                'Volte a este portal e marque o item como feito.',
            ],
            'atencao'  => null,
        ],

        'ponto_contato_definido' => [
            'titulo'   => 'Quem devemos acionar no dia a dia',
            'saudacao' => 'É a pessoa que vamos procurar quando precisarmos de uma decisão, de um dado ou de uma aprovação:',
            'passos'   => [
                'Clique em "Indicar ponto de contato".',
                'Informe o nome e o melhor e-mail ou telefone dessa pessoa.',
                'Se ela tiver um cargo que ajude a entender o papel dela, escreva também.',
                'Salve. Pode trocar de pessoa depois, quando quiser.',
            ],
            'atencao'  => null,
        ],

        'participantes_reuniao_cadastrados' => [
            'titulo'   => 'Quem vai participar das reuniões',
            'saudacao' => 'É para estes e-mails que enviamos o convite e a recorrência no calendário:',
            'passos'   => [
                'Clique em "Adicionar participante".',
                'Informe o nome e o Gmail da pessoa.',
                'Repita para cada pessoa que deve participar — pode incluir quantas quiser.',
                'Confira se todos têm Gmail: sem ele, a pessoa não recebe o convite.',
            ],
            'atencao'  => 'Use o Gmail de cada participante. O convite e o calendário são do Google, '
                . 'e um e-mail de outro provedor pode não receber os encontros recorrentes.',
        ],
    ];

    /**
     * Resolve o serviço-alvo por consulta, NUNCA por id fixo — o catálogo de
     * serviços tem ids diferentes entre localhost e produção.
     */
    private static function eGestao(Servico $servico): bool
    {
        return $servico->setor === Servico::SETOR_PERFORMANCE
            && str_contains(mb_strtolower($servico->nome), 'gestão');
    }

    /**
     * Os 9 passos do onboarding de Gestão (Performance), 5 automáticos.
     *
     * A contagem mudou com a régua e o docblock ficou para trás: até a v9 eram
     * 15 passos, e a v10 removeu os cinco `dono=interno` que o negócio disse
     * não fazerem parte do onboarding. Quem planeja pela contagem antiga
     * superestima o que existe — conferido item a item em 2026-08-19.
     *
     * `dono` e `auto_fonte` são eixos INDEPENDENTES:
     *  - `dono` responde "de quem é a bola?" — quem precisa AGIR.
     *  - `auto_fonte` responde "como o sistema sabe que aconteceu?".
     * `grant_sistema_ecf` prova a independência: a bola é do cliente (só ele
     * autoriza o OAuth), mas ninguém digita "feito" — `ml_tokens.status=active`
     * fecha o passo sozinho.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function gestao(): array
    {
        // v10: a consulta ao setor financeiro saiu junto com
        // `confirmacao_pagamento` — era o único passo com `setor_id`.
        return [
            [
                'ordem'      => 3,
                'etapa'      => OnboardingPasso::ETAPA_ACESSOS,
                'natureza'   => OnboardingPasso::NATUREZA_ACAO,
                'chave'      => 'grant_sistema_ecf',
                'titulo'     => 'Grant com o Sistema ECF (OAuth)',
                // PRIMEIRA ação do cliente, e a que destrava o resto: é por ela
                // que o sistema passa a conseguir puxar os dados da conta
                // sozinho. Antes dela não há o que buscar — nem faturamento,
                // nem Full, nem reputação, nem acervo.
                //
                // A bola é do cliente (só ele autoriza), mas ninguém digita
                // "feito": `ml_tokens.status = active` fecha o passo.
                'dono'       => OnboardingPasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_ML_TOKEN,
                'condicao'   => null,
            ],
            [
                'ordem'      => 4,
                'etapa'      => OnboardingPasso::ETAPA_ACESSOS,
                'natureza'   => OnboardingPasso::NATUREZA_ACAO,
                'chave'      => 'acesso_colaborador_ml',
                'titulo'     => 'Acesso colaborador Mercado Livre',
                // Vem DEPOIS do grant: primeiro o cliente autoriza e o sistema
                // puxa o que consegue; só então se pede o acesso de colaborador.
                'dono'       => OnboardingPasso::DONO_CLIENTE,
                'setor_id'   => null,
                // v17 — SEM dependencia do OAuth. Convidar colaborador no Mercado
                // Livre e conceder acesso dentro da Adman sao acoes de plataformas
                // diferentes: nenhuma delas precisa do nosso grant para acontecer.
                // A dependencia so produzia cadeado e a frase "Liberamos assim que
                // ... estiver concluido" num item que o cliente ja podia fazer,
                // empurrando para depois um trabalho que cabia em paralelo.
                'depende_de' => [],
                'sla_dias'   => 3,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 9,
                'etapa'      => OnboardingPasso::ETAPA_MAPEAMENTO,
                'natureza'   => OnboardingPasso::NATUREZA_ACAO,
                'chave'      => 'anuncios_ativos_inativos',
                'titulo'     => 'Anúncios ativos / inativos',
                'dono'       => OnboardingPasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => ['grant_sistema_ecf'],
                'sla_dias'   => 1,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_ACERVO,
                'condicao'   => null,
            ],
            [
                'ordem'      => 15,
                'etapa'      => OnboardingPasso::ETAPA_AGENDAMENTO,
                'natureza'   => OnboardingPasso::NATUREZA_ACAO,
                'chave'      => 'reuniao_realizada',
                'titulo'     => 'Reunião de onboarding realizada',
                // v10 — dependia também de `confirmacao_pagamento` e
                // `relatorio_inicial`, que saíram da régua. Deixar as duas
                // chaves aqui deixaria a reunião BLOQUEADA para sempre: a
                // dependência aponta para passos que não nascem mais.
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => [],
                // v14 — `agendar_reuniao_onboarding` saiu da régua: o bloco
                // "Reunião de onboarding" da tela já grava data e hora, e um
                // item de checklist pedindo para agendar o que o formulário ao
                // lado agenda era pedir a mesma coisa duas vezes. Sem esta
                // limpeza a reunião ficaria BLOQUEADA para sempre, esperando um
                // passo que não nasce mais — a mesma armadilha que a v10 já
                // tinha criado com `confirmacao_pagamento`.
                'sla_dias'   => 10,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 30,
                'etapa'      => OnboardingPasso::ETAPA_PUBLICIDADE,
                'natureza'   => OnboardingPasso::NATUREZA_REUNIAO,
                'chave'      => 'publicidade_processo_explicado',
                'titulo'     => 'Processo de publicidade explicado',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => [],
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
                'condicao'   => null,
            ],
            [
                'ordem'      => 31,
                'etapa'      => OnboardingPasso::ETAPA_PUBLICIDADE,
                'natureza'   => OnboardingPasso::NATUREZA_REUNIAO,
                'chave'      => 'publicidade_investimento_explicado',
                'titulo'     => 'Uso do investimento em publicidade explicado',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => [],
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
                'condicao'   => null,
            ],
            [
                'ordem'      => 33,
                'etapa'      => OnboardingPasso::ETAPA_PUBLICIDADE,
                'natureza'   => OnboardingPasso::NATUREZA_REUNIAO,
                'chave'      => 'publicidade_responsabilidades_alinhadas',
                'titulo'     => 'Responsabilidades de publicidade alinhadas',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => [],
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
                'condicao'   => null,
            ],
            [
                'ordem'      => 34,
                'etapa'      => OnboardingPasso::ETAPA_ADMAN,
                'natureza'   => OnboardingPasso::NATUREZA_REUNIAO,
                'chave'      => 'adman_uso_explicado',
                'titulo'     => 'Uso da ADMAN explicado ao cliente',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => [],
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
                'condicao'   => null,
            ],
            [
                'ordem'      => 36,
                'etapa'      => OnboardingPasso::ETAPA_ADMAN,
                'natureza'   => OnboardingPasso::NATUREZA_REUNIAO,
                'chave'      => 'adman_responsabilidades_alinhadas',
                'titulo'     => 'Responsabilidades sobre a ADMAN alinhadas',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => [],
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
                'condicao'   => null,
            ],
        ];
    }
}
