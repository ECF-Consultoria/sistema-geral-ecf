// Espelho do App\Support\Permissions.php — mantido em sincronia manualmente.
// Usado pela tela admin de permissões (grid de checkboxes) e pra resolver labels
// quando o frontend precisa exibir keys arbitrárias (logs, breadcrumbs, etc).

export const PERMISSIONS = {
    // core
    CORE_DASHBOARD:            'core.dashboard',
    CORE_CARTEIRA:             'core.carteira',
    CORE_EMPRESAS:             'core.empresas',
    CORE_USUARIOS:             'core.usuarios',
    CORE_REUNIOES:             'core.reunioes',
    CORE_NPS:                  'core.nps',
    CORE_METAS:                'core.metas',
    CORE_PPA:                  'core.ppa',
    CORE_PERFORMANCE:          'core.performance',
    CORE_GRANTS:               'core.grants',
    CORE_SUGADORES:            'core.sugadores',
    CORE_SUGADORES_GLOBAL:     'core.sugadores.global_view',
    // mlb
    MLB_DASHBOARD:             'mlb.dashboard',
    MLB_PROJETOS:              'mlb.projetos',
    MLB_TREINAMENTO:           'mlb.treinamento',
    MLB_MEU_PAINEL:            'mlb.meu_painel',
    MLB_PUBLICACOES:           'mlb.publicacoes',
    MLB_VENDAS:                'mlb.vendas',
    MLB_HISTORICO:             'mlb.historico',
    MLB_REVISAO:               'mlb.revisao',
    MLB_EMPRESAS:              'mlb.empresas',
    MLB_IMPLEMENTACAO:         'mlb.implementacao',
    MLB_METAS:                 'mlb.metas',
    // admin
    ADMIN_EMPRESAS:            'admin.empresas',
    ADMIN_RELATORIO:           'admin.relatorio',
    ADMIN_FINANCEIRO:          'admin.financeiro',
    ADMIN_INVENTARIO:          'admin.inventario',
    // sistema
    SISTEMA_ACTIVITY_LOG:      'sistema.activity_log',
    SISTEMA_DESENVOLVIMENTO:   'sistema.desenvolvimento',
    SISTEMA_SETORES:           'sistema.setores',
    // lideranca (automático)
    LIDERANCA_DASHBOARD_SETOR: 'lideranca.dashboard_setor',
    LIDERANCA_DEFINIR_METAS:   'lideranca.definir_metas',
    LIDERANCA_VER_MEMBROS:     'lideranca.ver_membros',
};

/**
 * Hook simples: dado o array de permissões do user (via Inertia auth.permissions),
 * retorna helper booleano.
 *   const can = useCan(permissions);
 *   if (can(PERMISSIONS.MLB_VENDAS)) ...
 */
export function useCan(permissions = []) {
    return (key) => Array.isArray(permissions) && permissions.includes(key);
}
