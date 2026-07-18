<?php

namespace App\Support;

/**
 * Catálogo canônico de permission keys do sistema.
 *
 * Cada key representa uma "tela/funcionalidade" que pode ser concedida a um setor.
 * A lista é INTENCIONALMENTE estática (não no banco) porque adicionar uma key nova
 * exige código novo (rota, controller, frontend) — banco seria fonte falsa de verdade.
 *
 * Grupos (prefixos):
 *   core.*       — Sistema ECF Consultoria principal (dashboard, empresas, NPS, PPA, etc)
 *   mlb.*        — Módulo de Publicações Mercado Livre
 *   shopee.*     — Módulo Shopee (aba Empresas para habilitar NPS)
 *   admin.*      — Módulo Administrativo (fechamento, relatórios, inventário)
 *   sistema.*    — Áreas internas (activity log, dev, config de setores)
 *   lideranca.*  — Habilidades de líder (atribuídas automaticamente a quem está em setor_lideres)
 */
class Permissions
{
    /** Acesso ao Dashboard principal ECF. */
    public const CORE_DASHBOARD            = 'core.dashboard';
    /** Visualizar/editar a carteira própria (empresas vinculadas). */
    public const CORE_CARTEIRA             = 'core.carteira';
    /** CRUD de empresas (companies). */
    public const CORE_EMPRESAS             = 'core.empresas';
    /** CRUD de usuários do sistema. */
    public const CORE_USUARIOS             = 'core.usuarios';
    /** Gerenciar reuniões. */
    public const CORE_REUNIOES             = 'core.reunioes';
    /** Acessar pesquisas NPS. */
    public const CORE_NPS                  = 'core.nps';
    /** Acessar e gerenciar metas (Goals + PortfolioGoals). */
    public const CORE_METAS                = 'core.metas';
    /** Acessar PPA (Plano de Ação). */
    public const CORE_PPA                  = 'core.ppa';
    /** Dashboard de Desempenho de consultores. */
    public const CORE_PERFORMANCE          = 'core.performance';
    /** Gerenciar grants do Mercado Livre. */
    public const CORE_GRANTS               = 'core.grants';
    /** Acessar módulo Sugadores (escopado pela carteira do user). */
    public const CORE_SUGADORES            = 'core.sugadores';
    /** Ver Sugadores de TODAS as empresas (não escopado por carteira). */
    public const CORE_SUGADORES_GLOBAL     = 'core.sugadores.global_view';

    public const MLB_DASHBOARD             = 'mlb.dashboard';
    public const MLB_PROJETOS              = 'mlb.projetos';
    public const MLB_TREINAMENTO           = 'mlb.treinamento';
    public const MLB_MEU_PAINEL            = 'mlb.meu_painel';
    public const MLB_PUBLICACOES           = 'mlb.publicacoes';
    public const MLB_VENDAS                = 'mlb.vendas';
    public const MLB_HISTORICO             = 'mlb.historico';
    public const MLB_REVISAO               = 'mlb.revisao';
    public const MLB_EMPRESAS              = 'mlb.empresas';
    public const MLB_IMPLEMENTACAO         = 'mlb.implementacao';
    public const MLB_METAS                 = 'mlb.metas';
    /** Inteligência de anúncios MLB — coleta e mineração de keywords de concorrentes. */
    public const MLB_COLETA                = 'mlb.coleta';
    /** Anunciar no Mercado Livre — criar e publicar anúncios na conta do cliente via API. */
    public const MLB_ANUNCIAR              = 'mlb.anunciar';
    /** Ver o Faturamento Polos (dados financeiros por polo). Escopado ao setor Polos — não vaza via mlb.projetos. */
    public const MLB_FATURAMENTO_POLOS     = 'mlb.faturamento_polos';

    /** Aba Empresas do marketplace Shopee — listagem enxuta + atribuição p/ NPS. */
    public const SHOPEE_EMPRESAS           = 'shopee.empresas';

    public const ADMIN_EMPRESAS            = 'admin.empresas';
    public const ADMIN_RELATORIO           = 'admin.relatorio';
    public const ADMIN_FINANCEIRO          = 'admin.financeiro';
    public const ADMIN_INVENTARIO          = 'admin.inventario';

    public const SISTEMA_ACTIVITY_LOG      = 'sistema.activity_log';
    public const SISTEMA_DESENVOLVIMENTO   = 'sistema.desenvolvimento';
    public const SISTEMA_SETORES           = 'sistema.setores';
    /** Catálogo de serviços e contratos por empresa (Módulo Serviços — Frente A). */
    public const SISTEMA_SERVICOS          = 'sistema.servicos';
    /** Painel de gestão OAuth Mercado Livre (admin only). */
    public const SISTEMA_ML_OAUTH          = 'sistema.ml_oauth';
    /** Painel de gestão OAuth Shopee (admin only). */
    public const SISTEMA_SHOPEE_OAUTH      = 'sistema.shopee_oauth';

    /** Criar notificações manuais (admin sempre tem; líderes ganham via AUTO_LIDERANCA). */
    public const NOTIFICACOES_CRIAR        = 'notificacoes.criar';

    /** Cadastrar novas empresas pelo setor Comercial (acesso por setor, não automático para líderes). */
    public const COMERCIAL_CADASTRAR_EMPRESA = 'comercial.cadastrar_empresa';

    /** Acesso ao dashboard de líder do(s) setor(es) liderado(s). */
    public const LIDERANCA_DASHBOARD_SETOR = 'lideranca.dashboard_setor';
    /** Permissão de criar/editar/remover metas no(s) setor(es) liderado(s). */
    public const LIDERANCA_DEFINIR_METAS   = 'lideranca.definir_metas';
    /** Ver lista de membros + métricas individuais do(s) setor(es) liderado(s). */
    public const LIDERANCA_VER_MEMBROS     = 'lideranca.ver_membros';

    /**
     * Permissões concedidas automaticamente a qualquer user em setor_lideres.
     * NÃO precisam estar em setor_permissoes — o User::hasPermission resolve.
     */
    public const AUTO_LIDERANCA = [
        self::LIDERANCA_DASHBOARD_SETOR,
        self::LIDERANCA_DEFINIR_METAS,
        self::LIDERANCA_VER_MEMBROS,
        self::NOTIFICACOES_CRIAR, // notificações: líderes ganham automaticamente (PERM-03)
    ];

    /**
     * Quick 260623 — permissões automáticas ESPECÍFICAS pro líder do setor
     * Performance. Concedidas em adição ao AUTO_LIDERANCA padrão quando o
     * user é líder do setor cujo slug == 'performance'. Outros líderes (Polos,
     * Publicações) NÃO ganham esse pacote.
     *
     * Motivo: líder de Performance precisa ver/atuar nos dashboards ECF,
     * cadastro de empresas, metas e ranking de desempenho — porque a equipe
     * dele opera nessas áreas. Outros setores têm domínios diferentes.
     */
    public const AUTO_LIDERANCA_PERFORMANCE = [
        self::CORE_DASHBOARD,
        self::CORE_EMPRESAS,
        self::CORE_CARTEIRA,
        self::CORE_METAS,
        self::CORE_PERFORMANCE,
    ];

    /**
     * Catálogo agrupado por módulo com label legível em pt-BR.
     * Usado pela tela admin de permissões e pra normalizar a lista no frontend.
     *
     * @return array<string, array<string, array{key: string, label: string, description: string}>>
     */
    public static function catalog(): array
    {
        return [
            'Core (ECF Consultoria)' => [
                ['key' => self::CORE_DASHBOARD,        'label' => 'Dashboard',                'description' => 'Visão geral do sistema'],
                ['key' => self::CORE_CARTEIRA,         'label' => 'Carteira',                 'description' => 'Empresas vinculadas ao próprio usuário'],
                ['key' => self::CORE_EMPRESAS,         'label' => 'Empresas',                 'description' => 'CRUD de empresas'],
                ['key' => self::CORE_USUARIOS,         'label' => 'Usuários',                 'description' => 'CRUD de usuários'],
                ['key' => self::CORE_REUNIOES,         'label' => 'Reuniões',                 'description' => 'Gestão de reuniões'],
                ['key' => self::CORE_NPS,              'label' => 'NPS',                      'description' => 'Pesquisas NPS'],
                ['key' => self::CORE_METAS,            'label' => 'Metas',                    'description' => 'Metas de empresa e carteira'],
                ['key' => self::CORE_PPA,              'label' => 'PPA',                      'description' => 'Plano de Ação'],
                ['key' => self::CORE_PERFORMANCE,      'label' => 'Desempenho',               'description' => 'Ranking e estatísticas globais'],
                ['key' => self::CORE_GRANTS,           'label' => 'Grants',                   'description' => 'Grants do Mercado Livre'],
                ['key' => self::CORE_SUGADORES,        'label' => 'Sugadores',                'description' => 'Detecção de adgroups/campanhas drenando investimento'],
                ['key' => self::CORE_SUGADORES_GLOBAL, 'label' => 'Sugadores · Visão global', 'description' => 'Vê Sugadores de todas as empresas (não só carteira)'],
            ],
            'Publicações (MLB)' => [
                ['key' => self::MLB_DASHBOARD,     'label' => 'Pub · Dashboard',      'description' => 'Painel geral de Publicações'],
                ['key' => self::MLB_PROJETOS,      'label' => 'Pub · Projetos',       'description' => 'Projetos de publicação'],
                ['key' => self::MLB_TREINAMENTO,   'label' => 'Pub · Treinamentos',   'description' => 'Treinamentos da equipe'],
                ['key' => self::MLB_MEU_PAINEL,    'label' => 'Pub · Meu Painel',     'description' => 'Painel individual do publicador'],
                ['key' => self::MLB_PUBLICACOES,   'label' => 'Pub · Publicações',    'description' => 'Criar/editar publicações'],
                ['key' => self::MLB_VENDAS,        'label' => 'Pub · Vendas',         'description' => 'Vendas das publicações'],
                ['key' => self::MLB_HISTORICO,     'label' => 'Pub · Histórico',      'description' => 'Histórico de publicações'],
                ['key' => self::MLB_REVISAO,       'label' => 'Pub · Revisão',        'description' => 'Revisar publicações da equipe'],
                ['key' => self::MLB_EMPRESAS,      'label' => 'Pub · Empresas',       'description' => 'Empresas com módulo Publicação ativo'],
                ['key' => self::MLB_IMPLEMENTACAO, 'label' => 'Pub · Onboarding',     'description' => 'Onboarding de novas empresas'],
                ['key' => self::MLB_METAS,         'label' => 'Pub · Metas',          'description' => 'Configuração de metas de publicação'],
                ['key' => self::MLB_COLETA,        'label' => 'Pub · Int. Anúncios',  'description' => 'Coleta e mineração de keywords de concorrentes MLB'],
                ['key' => self::MLB_ANUNCIAR,      'label' => 'Pub · Anunciar ML',    'description' => 'Criar e publicar anúncios na conta do cliente via API'],
                ['key' => self::MLB_FATURAMENTO_POLOS, 'label' => 'Pub · Faturamento Polos', 'description' => 'Ver a página Faturamento Polos (financeiro por polo) — libera o setor Polos sem depender de admin'],
            ],
            'Shopee' => [
                ['key' => self::SHOPEE_EMPRESAS, 'label' => 'Shopee · Empresas', 'description' => 'Empresas atendidas na Shopee (habilita NPS)'],
            ],
            'Administrativo' => [
                ['key' => self::ADMIN_EMPRESAS,    'label' => 'Adm · Empresas',    'description' => 'Cadastro administrativo de empresas'],
                ['key' => self::ADMIN_RELATORIO,   'label' => 'Adm · Relatório',   'description' => 'Relatórios administrativos'],
                ['key' => self::ADMIN_FINANCEIRO,  'label' => 'Adm · Fechamento',  'description' => 'Fechamento financeiro mensal'],
                ['key' => self::ADMIN_INVENTARIO,  'label' => 'Adm · Inventário',  'description' => 'Inventário de ativos'],
            ],
            'Sistema' => [
                ['key' => self::SISTEMA_ACTIVITY_LOG,    'label' => 'Activity Log',      'description' => 'Log de ações dos usuários'],
                ['key' => self::SISTEMA_DESENVOLVIMENTO, 'label' => 'Desenvolvimento',   'description' => 'Área interna de devs'],
                ['key' => self::SISTEMA_SETORES,         'label' => 'Setores',           'description' => 'Configuração de setores, cargos e permissões'],
                ['key' => self::SISTEMA_SERVICOS,        'label' => 'Serviços',          'description' => 'Catálogo de serviços e contratos por empresa'],
                ['key' => self::SISTEMA_ML_OAUTH,        'label' => 'ML OAuth',          'description' => 'Painel de gestão OAuth Mercado Livre'],
                ['key' => self::SISTEMA_SHOPEE_OAUTH,    'label' => 'Shopee OAuth',      'description' => 'Painel de gestão OAuth Shopee'],
            ],
            'Notificações' => [
                ['key' => self::NOTIFICACOES_CRIAR, 'label' => 'Criar notificações', 'description' => 'Envia notificações manuais para usuários, setores, líderes ou todos'],
            ],
            'Comercial' => [
                ['key' => self::COMERCIAL_CADASTRAR_EMPRESA, 'label' => 'Cadastro de Empresas', 'description' => 'Cadastrar novas empresas pelo setor Comercial'],
            ],
            'Liderança (automático para líderes)' => [
                ['key' => self::LIDERANCA_DASHBOARD_SETOR, 'label' => 'Dashboard do setor', 'description' => 'Visualiza o(s) setor(es) que lidera'],
                ['key' => self::LIDERANCA_DEFINIR_METAS,   'label' => 'Definir metas',      'description' => 'Cria/edita metas do setor liderado'],
                ['key' => self::LIDERANCA_VER_MEMBROS,     'label' => 'Ver membros',        'description' => 'Lista membros + métricas individuais'],
            ],
        ];
    }

    /**
     * Lista plana de todas as keys válidas (pra validação de input).
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            foreach ($group as $perm) {
                $keys[] = $perm['key'];
            }
        }
        return $keys;
    }

    /** True se a key existe no catálogo. */
    public static function isValid(string $key): bool
    {
        return \in_array($key, self::all(), true);
    }
}
