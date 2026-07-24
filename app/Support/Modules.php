<?php

namespace App\Support;

/**
 * Catálogo canônico dos módulos do sistema (Module Registry — Fase 97).
 *
 * É a fonte de verdade VERSIONADA da estrutura dos módulos: cada `moduleKey`
 * declara nome (label pt-BR), grupo, prefixo de rota, permission key associada
 * e o estágio de maturidade DEFAULT. A lista é INTENCIONALMENTE estática (não
 * no banco) pelo mesmo motivo de `Permissions`: adicionar um módulo novo exige
 * código novo (rota, controller, item de menu) — banco seria fonte falsa de
 * verdade para a ESTRUTURA.
 *
 * O que é MUTÁVEL em runtime (estágio atual, bloqueio, prioridade, progresso,
 * dono, notas) vive na tabela `modules` (model `App\Models\Module`, Plano 03),
 * hidratada a partir deste catálogo pelo comando `modules:sync` (Plano 04) via
 * `updateOrCreate` por `key`. Este catálogo NUNCA é sobrescrito pelo banco —
 * o fluxo é sempre catálogo (código) → tabela (runtime).
 *
 * Elo com o frontend: `moduleKey` é o mesmo slug estável que passará a
 * decorar os itens do NAV_TREE (`resources/js/Layouts/AppLayout.jsx`) a
 * partir da Fase 98. O comando `modules:check` (Plano 04) é o guard
 * anti-drift entre este catálogo e o NAV_TREE — evita repetir o apodrecimento
 * já observado em `resources/js/lib/permissions.js` (espelho manual
 * desatualizado de `Permissions`).
 *
 * Grupos (mesmos prefixos de domínio usados em `Permissions`, mais `dev.*`
 * para os itens do grupo Dev e `amazon`/`administrativo` para módulos que
 * ainda não têm namespace próprio):
 *   core.*         — Sistema ECF Consultoria principal
 *   mlb.*          — Módulo de Publicações Mercado Livre
 *   shopee.*       — Módulo Shopee
 *   amazon         — Módulo Amazon (stub "Em breve")
 *   administrativo — Módulo Administrativo (fechamento financeiro — grupo inacabado)
 *   sistema.*      — Áreas internas (setores, catálogo de serviços)
 *   dev.*          — Grupo Dev (log, desenvolvimento, OAuth, board — DEC-2)
 *   notificacoes.* — Notificações
 *   comercial.*    — Módulo Comercial
 *   lideranca.*    — Habilidades de líder
 */
class Modules
{
    /**
     * Estágios canônicos de maturidade de um módulo, do mais cedo ao mais tardio.
     * Mesma lista usada pelo enum `modules.stage` (migration da Fase 97 Plano 01)
     * e referenciada pelo model `Module` (Plano 03) e pelo comando `modules:sync`
     * (Plano 04) para validação de input.
     *
     * @var array<int, string>
     */
    public const STAGES = [
        'ideia',
        'backlog',
        'em_desenvolvimento',
        'homologacao',
        'producao',
        'arquivado',
    ];

    /**
     * Estágio default seguro quando a moduleKey não está catalogada.
     * Garante zero regressão: qualquer item de menu SEM moduleKey se comporta
     * como hoje (visível). O gate real de rota (Fase 99, middleware
     * `EnsureModuleStage`) é aplicado explicitamente por grupo de rota — não
     * depende deste fallback para segurança.
     */
    private const DEFAULT_STAGE = 'producao';

    // ═══ Core (ECF Consultoria) ═══
    public const CORE_DASHBOARD_ECF          = 'core.dashboard_ecf';
    public const CORE_DASHBOARD              = 'core.dashboard';
    public const CORE_CARTEIRA               = 'core.carteira';
    public const CORE_EMPRESAS               = 'core.empresas';
    public const CORE_USUARIOS               = 'core.usuarios';
    public const CORE_REUNIOES               = 'core.reunioes';
    public const CORE_NPS                    = 'core.nps';
    public const CORE_NPS_CONFIGURACAO       = 'core.nps_configuracao';
    public const CORE_NPS_ENVIO_AUTOMATICO   = 'core.nps_envio_automatico';
    public const CORE_NPS_EMAILS_ENVIADOS    = 'core.nps_emails_enviados';
    public const CORE_METAS                  = 'core.metas';
    public const CORE_PPA                    = 'core.ppa';
    public const CORE_PERFORMANCE            = 'core.performance';
    public const CORE_GRANTS                 = 'core.grants';
    public const CORE_SUGADORES              = 'core.sugadores';
    public const CORE_MANUAL                 = 'core.manual';

    // ═══ Publicações (Mercado Livre) ═══
    public const MLB_PAINEL_EXECUTIVO        = 'mlb.painel_executivo';
    public const MLB_CONCENTRACAO_PREVISAO   = 'mlb.concentracao_previsao';
    public const MLB_ALERTAS_ESTRATEGICOS    = 'mlb.alertas_estrategicos';
    public const MLB_PAINEL_POLOS            = 'mlb.painel_polos';
    public const MLB_IMPLEMENTACAO           = 'mlb.implementacao';
    public const MLB_EMPRESAS_POLOS          = 'mlb.empresas_polos';
    public const MLB_FATURAMENTO_POLOS       = 'mlb.faturamento_polos';
    /** Anunciar no Mercado Livre — em Dev, acessível só por URL (routes/mlb_anuncios.php). */
    public const MLB_ANUNCIAR                = 'mlb.anunciar';
    public const MLB_COLETA                  = 'mlb.coleta';
    public const MLB_DASHBOARD               = 'mlb.dashboard';
    public const MLB_MEU_PAINEL              = 'mlb.meu_painel';
    public const MLB_TREINAMENTO             = 'mlb.treinamento';
    public const MLB_PUBLICACOES             = 'mlb.publicacoes';
    public const MLB_VENDAS                  = 'mlb.vendas';
    public const MLB_HISTORICO               = 'mlb.historico';
    public const MLB_REVISAO                 = 'mlb.revisao';
    public const MLB_EMPRESAS                = 'mlb.empresas';
    public const MLB_METAS                   = 'mlb.metas';
    public const MLB_PROJETOS                = 'mlb.projetos';

    // ═══ Shopee ═══
    public const SHOPEE_EMPRESAS             = 'shopee.empresas';
    /** Stub "Em breve" — sem API própria ainda (backlog). */
    public const SHOPEE_DASHBOARD            = 'shopee.dashboard';

    // ═══ Amazon ═══
    /** Stub "Em breve" — sem integração implementada (backlog). */
    public const AMAZON                      = 'amazon';

    // ═══ Administrativo ═══
    /** Grupo Administrativo inteiro (fechamento) — inacabado (backlog). */
    public const ADMINISTRATIVO              = 'administrativo';

    // ═══ Sistema ═══
    public const SISTEMA_SETORES             = 'sistema.setores';
    public const SISTEMA_SERVICOS            = 'sistema.servicos';

    // ═══ Dev (DEC-2 — namespace próprio p/ itens do grupo Dev) ═══
    public const DEV_LOG                     = 'dev.log';
    public const DEV_DESENVOLVIMENTO         = 'dev.desenvolvimento';
    public const DEV_ML_OAUTH                = 'dev.ml_oauth';
    public const DEV_SHOPEE_OAUTH            = 'dev.shopee_oauth';
    /** Abandonado — sem item de menu; existe só no catálogo (arquivado). */
    public const DEV_METAS                   = 'dev.metas';

    // ═══ Notificações ═══
    public const NOTIFICACOES_CRIAR          = 'notificacoes.criar';

    // ═══ Comercial ═══
    public const COMERCIAL_CADASTRAR_EMPRESA  = 'comercial.cadastrar_empresa';
    public const COMERCIAL_HUBSPOT_LINE_ITEMS = 'comercial.hubspot_line_items';

    // ═══ Liderança ═══
    public const LIDERANCA_DASHBOARD_SETOR   = 'lideranca.dashboard_setor';

    /**
     * Catálogo agrupado por módulo com label legível em pt-BR — espelha o
     * formato de `Permissions::catalog()`, acrescentando por item: `grupo`,
     * `route_prefix` (prefixo/nome de rota real, nullable), `permission_key`
     * (a permission correspondente de `Permissions`, nullable) e `stage`
     * (estágio default — validado contra `STAGES` em `stageMap()`).
     *
     * @return array<string, array<int, array{key: string, name: string, grupo: string, route_prefix: ?string, permission_key: ?string, stage: string}>>
     */
    public static function catalog(): array
    {
        return [
            'Core (ECF Consultoria)' => [
                ['key' => self::CORE_DASHBOARD_ECF,        'name' => 'ECF Dashboard',              'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'ecf.dashboard',              'permission_key' => null,                        'stage' => 'producao'],
                ['key' => self::CORE_DASHBOARD,            'name' => 'Dashboard Mercado Livre',     'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'mercadolivre.dashboard',     'permission_key' => Permissions::CORE_DASHBOARD, 'stage' => 'producao'],
                ['key' => self::CORE_CARTEIRA,             'name' => 'Carteira',                    'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'portfolio.own',              'permission_key' => Permissions::CORE_CARTEIRA,  'stage' => 'producao'],
                ['key' => self::CORE_EMPRESAS,             'name' => 'Empresas',                    'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'companies.index',            'permission_key' => Permissions::CORE_EMPRESAS,  'stage' => 'producao'],
                ['key' => self::CORE_USUARIOS,             'name' => 'Usuários',                    'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'users.index',                'permission_key' => Permissions::CORE_USUARIOS,  'stage' => 'producao'],
                ['key' => self::CORE_REUNIOES,             'name' => 'Reuniões',                    'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'meetings.index',             'permission_key' => Permissions::CORE_REUNIOES,  'stage' => 'producao'],
                ['key' => self::CORE_NPS,                  'name' => 'NPS · Pesquisas',             'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'nps.index',                  'permission_key' => Permissions::CORE_NPS,       'stage' => 'producao'],
                ['key' => self::CORE_NPS_CONFIGURACAO,     'name' => 'NPS · Configuração',          'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'nps.configuracao.index',     'permission_key' => null,                        'stage' => 'producao'],
                ['key' => self::CORE_NPS_ENVIO_AUTOMATICO, 'name' => 'NPS · Envio automático',      'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'nps.envio-automatico.index', 'permission_key' => null,                        'stage' => 'producao'],
                ['key' => self::CORE_NPS_EMAILS_ENVIADOS,  'name' => 'NPS · Emails enviados',       'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'nps.emails-enviados.index',  'permission_key' => null,                        'stage' => 'producao'],
                ['key' => self::CORE_METAS,                'name' => 'Metas',                       'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'goals.index',                'permission_key' => Permissions::CORE_METAS,     'stage' => 'producao'],
                ['key' => self::CORE_PPA,                  'name' => 'PPA',                         'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'ppa.index',                  'permission_key' => Permissions::CORE_PPA,       'stage' => 'producao'],
                ['key' => self::CORE_PERFORMANCE,          'name' => 'Desempenho',                  'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'performance.index',          'permission_key' => Permissions::CORE_PERFORMANCE, 'stage' => 'producao'],
                ['key' => self::CORE_GRANTS,               'name' => 'Grants',                      'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'grants.index',               'permission_key' => Permissions::CORE_GRANTS,    'stage' => 'producao'],
                ['key' => self::CORE_SUGADORES,            'name' => 'Sugadores',                   'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'sugadores.index',            'permission_key' => Permissions::CORE_SUGADORES, 'stage' => 'producao'],
                ['key' => self::CORE_MANUAL,               'name' => 'Manual do Sistema',            'grupo' => 'Core (ECF Consultoria)', 'route_prefix' => 'manual.index',               'permission_key' => null,                        'stage' => 'producao'],
            ],
            'Publicações (Mercado Livre)' => [
                ['key' => self::MLB_PAINEL_EXECUTIVO,      'name' => 'Painel Executivo',            'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'painel-executivo.index', 'permission_key' => null,                            'stage' => 'producao'],
                ['key' => self::MLB_CONCENTRACAO_PREVISAO, 'name' => 'Concentração e Previsão',     'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'concentracao.index',     'permission_key' => null,                            'stage' => 'producao'],
                ['key' => self::MLB_ALERTAS_ESTRATEGICOS,  'name' => 'Alertas Estratégicos',        'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'alertas.index',          'permission_key' => null,                            'stage' => 'producao'],
                ['key' => self::MLB_PAINEL_POLOS,          'name' => 'Painel Polos',                'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.polos-painel',       'permission_key' => Permissions::MLB_PROJETOS,       'stage' => 'producao'],
                ['key' => self::MLB_IMPLEMENTACAO,         'name' => 'Onboarding',                  'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.implementacao.index','permission_key' => Permissions::MLB_IMPLEMENTACAO,  'stage' => 'producao'],
                ['key' => self::MLB_EMPRESAS_POLOS,        'name' => 'Empresas Polos',              'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.polos-empresas',     'permission_key' => Permissions::MLB_PROJETOS,       'stage' => 'producao'],
                ['key' => self::MLB_FATURAMENTO_POLOS,     'name' => 'Faturamento Polos',           'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'polos.index',            'permission_key' => Permissions::MLB_FATURAMENTO_POLOS, 'stage' => 'producao'],
                ['key' => self::MLB_ANUNCIAR,              'name' => 'Anunciar Mercado Livre',      'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.anunciar',           'permission_key' => Permissions::MLB_ANUNCIAR,       'stage' => 'homologacao'],
                ['key' => self::MLB_COLETA,                'name' => 'Inteligência de Anúncios',    'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => null,                     'permission_key' => Permissions::MLB_COLETA,         'stage' => 'producao'],
                ['key' => self::MLB_DASHBOARD,              'name' => 'Pub · Dashboard',             'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.dashboard',          'permission_key' => Permissions::MLB_DASHBOARD,      'stage' => 'producao'],
                ['key' => self::MLB_MEU_PAINEL,            'name' => 'Pub · Meu Painel',            'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.meu-painel',         'permission_key' => Permissions::MLB_MEU_PAINEL,     'stage' => 'producao'],
                ['key' => self::MLB_TREINAMENTO,           'name' => 'Pub · Treinamentos',          'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.treinamentos',       'permission_key' => Permissions::MLB_TREINAMENTO,    'stage' => 'producao'],
                ['key' => self::MLB_PUBLICACOES,           'name' => 'Pub · Publicações',           'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.publicacoes',        'permission_key' => Permissions::MLB_PUBLICACOES,    'stage' => 'producao'],
                ['key' => self::MLB_VENDAS,                'name' => 'Pub · Vendas',                'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.vendas',             'permission_key' => Permissions::MLB_VENDAS,         'stage' => 'producao'],
                ['key' => self::MLB_HISTORICO,             'name' => 'Pub · Histórico',             'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.historico',          'permission_key' => Permissions::MLB_HISTORICO,      'stage' => 'producao'],
                ['key' => self::MLB_REVISAO,               'name' => 'Pub · Revisão',               'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.revisao',            'permission_key' => Permissions::MLB_REVISAO,        'stage' => 'producao'],
                ['key' => self::MLB_EMPRESAS,              'name' => 'Pub · Empresas',              'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.empresas',           'permission_key' => Permissions::MLB_EMPRESAS,       'stage' => 'producao'],
                ['key' => self::MLB_METAS,                 'name' => 'Pub · Metas',                 'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.metas.index',        'permission_key' => Permissions::MLB_METAS,          'stage' => 'producao'],
                ['key' => self::MLB_PROJETOS,              'name' => 'Projetos',                    'grupo' => 'Publicações (Mercado Livre)', 'route_prefix' => 'mlb.projetos',           'permission_key' => Permissions::MLB_PROJETOS,       'stage' => 'producao'],
            ],
            'Shopee' => [
                ['key' => self::SHOPEE_EMPRESAS,  'name' => 'Shopee · Empresas',  'grupo' => 'Shopee', 'route_prefix' => 'shopee.empresas.index', 'permission_key' => Permissions::SHOPEE_EMPRESAS, 'stage' => 'producao'],
                ['key' => self::SHOPEE_DASHBOARD, 'name' => 'Shopee · Dashboard', 'grupo' => 'Shopee', 'route_prefix' => 'shopee.dashboard',      'permission_key' => null,                         'stage' => 'backlog'],
            ],
            'Amazon' => [
                ['key' => self::AMAZON, 'name' => 'Amazon', 'grupo' => 'Amazon', 'route_prefix' => 'amazon.dashboard', 'permission_key' => null, 'stage' => 'backlog'],
            ],
            'Administrativo' => [
                ['key' => self::ADMINISTRATIVO, 'name' => 'Administrativo (Fechamento)', 'grupo' => 'Administrativo', 'route_prefix' => 'admin.', 'permission_key' => Permissions::ADMIN_FINANCEIRO, 'stage' => 'backlog'],
            ],
            'Sistema' => [
                ['key' => self::SISTEMA_SETORES,  'name' => 'Setores',  'grupo' => 'Sistema', 'route_prefix' => 'admin.setores.index', 'permission_key' => Permissions::SISTEMA_SETORES,  'stage' => 'producao'],
                ['key' => self::SISTEMA_SERVICOS, 'name' => 'Serviços', 'grupo' => 'Sistema', 'route_prefix' => 'servicos.index',      'permission_key' => Permissions::SISTEMA_SERVICOS, 'stage' => 'producao'],
            ],
            'Dev' => [
                ['key' => self::DEV_LOG,             'name' => 'Log',                      'grupo' => 'Dev', 'route_prefix' => 'activity-log.index', 'permission_key' => Permissions::SISTEMA_ACTIVITY_LOG,    'stage' => 'producao'],
                ['key' => self::DEV_DESENVOLVIMENTO, 'name' => 'Desenvolvimento',          'grupo' => 'Dev', 'route_prefix' => 'dev.desenvolvimento','permission_key' => Permissions::SISTEMA_DESENVOLVIMENTO, 'stage' => 'producao'],
                ['key' => self::DEV_ML_OAUTH,        'name' => 'ML OAuth',                 'grupo' => 'Dev', 'route_prefix' => 'ml.oauth.index',     'permission_key' => Permissions::SISTEMA_ML_OAUTH,        'stage' => 'producao'],
                ['key' => self::DEV_SHOPEE_OAUTH,    'name' => 'Shopee OAuth',             'grupo' => 'Dev', 'route_prefix' => 'shopee.oauth.index', 'permission_key' => Permissions::SISTEMA_SHOPEE_OAUTH,    'stage' => 'producao'],
                ['key' => self::DEV_METAS,           'name' => 'Metas de Desenvolvimento', 'grupo' => 'Dev', 'route_prefix' => null,                 'permission_key' => null,                                  'stage' => 'arquivado'],
            ],
            'Notificações' => [
                ['key' => self::NOTIFICACOES_CRIAR, 'name' => 'Enviar notificação', 'grupo' => 'Notificações', 'route_prefix' => 'notificacoes.nova', 'permission_key' => Permissions::NOTIFICACOES_CRIAR, 'stage' => 'producao'],
            ],
            'Comercial' => [
                ['key' => self::COMERCIAL_CADASTRAR_EMPRESA,  'name' => 'Comercial · Empresas', 'grupo' => 'Comercial', 'route_prefix' => 'comercial.empresas.listagem',      'permission_key' => Permissions::COMERCIAL_CADASTRAR_EMPRESA, 'stage' => 'producao'],
                ['key' => self::COMERCIAL_HUBSPOT_LINE_ITEMS, 'name' => 'HubSpot Line Items',   'grupo' => 'Comercial', 'route_prefix' => 'sistema.hubspot-line-items.index', 'permission_key' => null,                                      'stage' => 'producao'],
            ],
            'Liderança' => [
                ['key' => self::LIDERANCA_DASHBOARD_SETOR, 'name' => 'Meu Setor', 'grupo' => 'Liderança', 'route_prefix' => 'lideranca.index', 'permission_key' => Permissions::LIDERANCA_DASHBOARD_SETOR, 'stage' => 'producao'],
            ],
        ];
    }

    /**
     * Lista plana de todas as moduleKey válidas (pra validação de input).
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (self::catalog() as $itens) {
            foreach ($itens as $item) {
                $keys[] = $item['key'];
            }
        }
        return $keys;
    }

    /** True se a moduleKey existe no catálogo. */
    public static function isValid(string $key): bool
    {
        return \in_array($key, self::all(), true);
    }

    /**
     * Mapa moduleKey → stage default, construído a partir do catálogo.
     * Levanta exceção se algum item declarar um stage fora de `STAGES` —
     * evita que um typo silencioso vire um estágio fantasma.
     *
     * @return array<string, string>
     */
    private static function stageMap(): array
    {
        $map = [];
        foreach (self::catalog() as $itens) {
            foreach ($itens as $item) {
                if (! self::isStageValid($item['stage'])) {
                    throw new \RuntimeException(sprintf(
                        'App\\Support\\Modules: o módulo "%s" declara stage inválido "%s". Stages canônicos: %s.',
                        $item['key'],
                        $item['stage'],
                        implode(', ', self::STAGES)
                    ));
                }
                $map[$item['key']] = $item['stage'];
            }
        }
        return $map;
    }

    /**
     * Estágio default declarado no catálogo para a moduleKey.
     * Se a key não estiver catalogada, retorna o default seguro (`producao`).
     */
    public static function defaultStageFor(string $key): string
    {
        return self::stageMap()[$key] ?? self::DEFAULT_STAGE;
    }

    /** True se o stage pertence à lista canônica `STAGES`. */
    public static function isStageValid(string $stage): bool
    {
        return \in_array($stage, self::STAGES, true);
    }
}
