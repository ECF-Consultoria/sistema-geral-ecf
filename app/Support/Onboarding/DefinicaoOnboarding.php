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
     */
    public const VERSAO = 5;

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
                'ordem'      => 1,
                'chave'      => 'grupo_criado',
                'titulo'     => 'Grupo de WhatsApp criado',
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 2,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 2,
                'chave'      => 'mensagem_boas_vindas',
                'titulo'     => 'Mensagem de boas-vindas enviada',
                // Depende do grupo existir — é nele que a mensagem é enviada.
                // NÃO travamos nada do cliente nisto: um checkbox interno
                // esquecido não pode parar quem já recebeu o link.
                'dono'       => OnboardingPasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['grupo_criado'],
                'sla_dias'   => 2,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 3,
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
                'chave'      => 'planilha_custos_adman',
                'titulo'     => 'Planilha de custos ADMAN',
                'dono'       => OnboardingPasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
                'condicao'   => null,
            ],
            [
                'ordem'      => 6,
                'chave'      => 'grant_consultoria_adman',
                'titulo'     => 'Grant com a Consultoria (Adman)',
                'dono'       => OnboardingPasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => ['planilha_custos_adman'],
                'sla_dias'   => 5,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_ADMAN_GRANT,
                'condicao'   => null,
            ],
            [
                'ordem'      => 7,
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
                'chave'      => 'metricas_da_conta',
                'titulo'     => 'Métricas da conta',
                'dono'       => OnboardingPasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => ['planilha_custos_adman', 'grant_sistema_ecf'],
                'sla_dias'   => 1,
                'auto_fonte' => OnboardingPasso::AUTO_FONTE_METRICAS,
                'condicao'   => null,
            ],
            [
                'ordem'      => 9,
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
