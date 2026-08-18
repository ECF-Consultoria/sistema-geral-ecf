<?php

namespace App\Support\Onboarding;

use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\Setor;

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
     */
    public const VERSAO = 9;

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
     * Resolve o serviço-alvo por consulta, NUNCA por id fixo — o catálogo de
     * serviços tem ids diferentes entre localhost e produção.
     */
    private static function eGestao(Servico $servico): bool
    {
        return $servico->setor === Servico::SETOR_PERFORMANCE
            && str_contains(mb_strtolower($servico->nome), 'gestão');
    }

    /**
     * Os 15 passos do onboarding de Gestão (Performance), 6 automáticos.
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
        $setorFinanceiroId = Setor::where('slug', 'financeiro')->value('id');

        return [
            [
                'ordem'      => 2,
                'etapa'      => OnboardingPasso::ETAPA_ADMINISTRATIVO,
                'chave'      => 'mensagem_boas_vindas',
                'titulo'     => 'Mensagem de boas-vindas enviada',
                // v7 — dependia de `grupo_criado`, que saiu. Sem dependência,
                // nasce aberto junto com os demais.
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 2,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
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
                'ordem'      => 7,
                'etapa'      => OnboardingPasso::ETAPA_ADMINISTRATIVO,
                'chave'      => 'confirmacao_pagamento',
                'titulo'     => 'Confirmação de pagamento',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => $setorFinanceiroId,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => null,
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
                'ordem'      => 10,
                'etapa'      => OnboardingPasso::ETAPA_MAPEAMENTO,
                'chave'      => 'excluir_anuncios_inativos',
                'titulo'     => 'Excluir anúncios inativos',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['anuncios_ativos_inativos'],
                'sla_dias'   => 5,
                'auto_fonte' => null,
                // Só nasce se o passo 8 apurar inativos > 0.
                'condicao'   => ['tipo' => OnboardingPasso::CONDICAO_ANUNCIOS_INATIVOS],
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
                'ordem'      => 12,
                'etapa'      => OnboardingPasso::ETAPA_MAPEAMENTO,
                'chave'      => 'grant_de_ads',
                'titulo'     => 'Grant de Ads',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['grant_sistema_ecf'],
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
                'ordem'      => 14,
                'etapa'      => OnboardingPasso::ETAPA_AGENDAMENTO,
                'chave'      => 'relatorio_inicial',
                'titulo'     => 'Relatório inicial da empresa',
                // O PDF §3 pede o relatório ANTES da reunião — é o que se
                // apresenta nela. Tem auto_fonte para não existir um "marcar
                // como feito" que fecharia a etapa sem relatório nenhum.
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['metricas_da_conta', 'anuncios_ativos_inativos'],
                'sla_dias'   => 3,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_RELATORIO_INICIAL,
                'condicao'   => null,
            ],
            [
                'ordem'      => 15,
                'etapa'      => OnboardingPasso::ETAPA_AGENDAMENTO,
                'chave'      => 'reuniao_realizada',
                'titulo'     => 'Reunião de onboarding realizada',
                // Depende do agendamento, do pagamento E do relatório —
                // pagamento trava a CONCLUSÃO (nunca o mapeamento), e a reunião
                // não acontece sem o documento que ela existe para apresentar.
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['agendar_reuniao_onboarding', 'confirmacao_pagamento', 'relatorio_inicial'],
                'sla_dias'   => 10,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
        ];
    }
}
