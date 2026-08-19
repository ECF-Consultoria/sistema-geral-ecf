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
     */
    public const VERSAO = 10;

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

        'planilha_custos_adman' => 'Dentro da Adman, vincule a planilha de custos da sua conta. '
            . 'É ela que permite calcular sua margem real por anúncio. '
            . 'Assim que o vínculo existir, detectamos automaticamente — você não precisa avisar.',

        'grant_consultoria_adman' => 'Dentro da Adman, conceda acesso à ECF Consultoria. '
            . 'Sem esse acesso não conseguimos ler seus custos e seu faturamento pela Adman. '
            . 'Assim que você concluir, detectamos automaticamente — você não precisa avisar.',

        'custos_app_ecf' => 'Preencha os custos dos seus produtos no App ECF. '
            . 'São eles que transformam faturamento em margem — sem os custos, conseguimos mostrar quanto você '
            . 'vendeu, mas não quanto sobrou. Quando terminar, marque este item como feito.',
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
                'chave'      => 'acesso_colaborador_ml',
                'titulo'     => 'Acesso colaborador Mercado Livre',
                // Vem DEPOIS do grant: primeiro o cliente autoriza e o sistema
                // puxa o que consegue; só então se pede o acesso de colaborador.
                'dono'       => OnboardingPasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => ['grant_sistema_ecf'],
                'sla_dias'   => 3,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 5,
                'etapa'      => OnboardingPasso::ETAPA_ACESSOS,
                'chave'      => 'planilha_custos_adman',
                'titulo'     => 'Planilha de custos ADMAN',
                // v6 — passa a ser do CLIENTE: é ele quem concede/vincula a
                // conta na Adman. O `auto_fonte` não muda: o sistema segue
                // detectando sozinho quando o vínculo existe (D-19).
                'dono'       => OnboardingPasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
                'condicao'   => null,
            ],
            [
                'ordem'      => 6,
                'etapa'      => OnboardingPasso::ETAPA_ACESSOS,
                'chave'      => 'grant_consultoria_adman',
                'titulo'     => 'Grant com a Consultoria (Adman)',
                // v6 — só o cliente concede o grant à Consultoria dentro da
                // Adman. A sonda continua sendo quem confirma.
                //
                // v9 — depende do GRANT COM O SISTEMA, não mais da planilha de
                // custos. É a ordem que o negócio pratica: o cliente autoriza
                // o sistema primeiro e só então concede à Consultoria. Amarrar
                // na planilha punha um passo de cadastro no meio de dois
                // passos de acesso.
                'dono'       => OnboardingPasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => ['grant_sistema_ecf'],
                'sla_dias'   => 5,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_ADMAN_GRANT,
                'condicao'   => null,
            ],
            [
                'ordem'      => 8,
                'etapa'      => OnboardingPasso::ETAPA_MAPEAMENTO,
                'chave'      => 'metricas_da_conta',
                'titulo'     => 'Métricas da conta',
                'dono'       => OnboardingPasso::DONO_SISTEMA,
                'setor_id'   => null,
                // v8 — depende SÓ do grant do Mercado Livre. A Adman não tem
                // a ver com montar a ficha da conta: ela entra apenas no
                // faturamento, e `MetricasContaResolver` já conclui sem ela
                // (sem `cust_id`, `faturamento_3_meses` cai em `nao_obtidos`
                // e o resto é apurado normalmente). Amarrar a ficha ao
                // cadastro na Adman travava tudo por um dado acessório.
                'depende_de' => ['grant_sistema_ecf'],
                'sla_dias'   => 1,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_METRICAS,
                'condicao'   => null,
            ],
            [
                'ordem'      => 9,
                'etapa'      => OnboardingPasso::ETAPA_MAPEAMENTO,
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
                'ordem'      => 11,
                'etapa'      => OnboardingPasso::ETAPA_MAPEAMENTO,
                'chave'      => 'custos_app_ecf',
                'titulo'     => 'Custos no App ECF',
                'dono'       => OnboardingPasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 13,
                'etapa'      => OnboardingPasso::ETAPA_AGENDAMENTO,
                'chave'      => 'agendar_reuniao_onboarding',
                'titulo'     => 'Agendar reunião de onboarding',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['metricas_da_conta', 'anuncios_ativos_inativos'],
                'sla_dias'   => 3,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 15,
                'etapa'      => OnboardingPasso::ETAPA_AGENDAMENTO,
                'chave'      => 'reuniao_realizada',
                'titulo'     => 'Reunião de onboarding realizada',
                // v10 — dependia também de `confirmacao_pagamento` e
                // `relatorio_inicial`, que saíram da régua. Deixar as duas
                // chaves aqui deixaria a reunião BLOQUEADA para sempre: a
                // dependência aponta para passos que não nascem mais.
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['agendar_reuniao_onboarding'],
                'sla_dias'   => 10,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
        ];
    }
}
