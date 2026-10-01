// ─── As duas portas do Portal do Cliente, num lugar só ──────────────────────
//
// O portal atende por dois caminhos: o antigo, em que a posse do token na URL
// identifica a empresa, e o novo, em que ela vem da sessão autenticada. As duas
// famílias de rota fazem a MESMA coisa e têm nomes diferentes.
//
// ### Por que isto existe em vez de um `if` em cada tela
// Já custou um bug: `Portal/Ppa.jsx` chamava sempre a rota do token, e no modo
// autenticado — sem token — o Ziggy lançava por parâmetro faltando. O erro
// subia como um `catch` genérico e o cliente lia só "Não foi possível salvar",
// sem nada no console que apontasse a causa.
//
// Com o mapa aqui, esquecer um caso vira erro de chave inexistente na hora, e
// não uma exceção silenciosa em produção.
//
// Some daqui a família `porToken` no dia em que o token for aposentado — e o
// mapa é justamente a lista do que precisa ser conferido nesse dia.

const ROTAS = {
    'onboarding.passo': {
        porToken: 'onboarding.publico.passo',
        autenticada: 'portal.auth.onboarding.passo',
    },
    'onboarding.passo.desmarcar': {
        porToken: 'onboarding.publico.passo.desmarcar',
        autenticada: 'portal.auth.onboarding.passo.desmarcar',
    },
    'onboarding.mapeamento.sincronizar': {
        porToken: 'onboarding.publico.mapeamento.sincronizar',
        autenticada: 'portal.auth.onboarding.mapeamento.sincronizar',
    },
    'onboarding.mapeamento.confirmar': {
        porToken: 'onboarding.publico.mapeamento.confirmar',
        autenticada: 'portal.auth.onboarding.mapeamento.confirmar',
    },
    'onboarding.pessoas': {
        porToken: 'onboarding.publico.pessoas',
        autenticada: 'portal.auth.onboarding.pessoas',
    },
    'onboarding.conectar-ml': {
        porToken: 'onboarding.publico.conectar-ml',
        autenticada: 'portal.auth.onboarding.conectar-ml',
    },
    'ppa.tarefa': {
        porToken: 'portal.ppa.tarefa',
        autenticada: 'portal.auth.ppa.tarefa',
    },
    // 14/09 — só existe autenticada, de propósito: os itens de confirmação são
    // `dono=interno` e quem os registra é a equipe da ECF. `porToken: null` faz
    // o helper abaixo falhar ALTO se alguém tentar chamá-la do modo anônimo,
    // em vez de o Ziggy estourar por nome de rota inexistente longe daqui.
    'onboarding.confirmacao': {
        porToken: null,
        autenticada: 'portal.auth.onboarding.confirmacao',
    },
    // Mesma natureza da confirmação: registro da equipe, sem porta anônima.
    'onboarding.relatorio': {
        porToken: null,
        autenticada: 'portal.auth.onboarding.relatorio',
    },
    'onboarding.investimento': {
        porToken: null,
        autenticada: 'portal.auth.onboarding.investimento',
    },
    'onboarding.fotografia': {
        porToken: null,
        autenticada: 'portal.auth.onboarding.fotografia',
    },
    // 23/09/2026 — o cliente marca a reunião de onboarding. Só autenticada: o
    // token foi aposentado, e estas rotas leem a agenda da equipe.
    'onboarding.horarios': {
        porToken: null,
        autenticada: 'portal.auth.onboarding.horarios',
    },
    'onboarding.agendar': {
        porToken: null,
        autenticada: 'portal.auth.onboarding.agendar',
    },
};

/**
 * A URL certa para o modo em que a tela está.
 *
 * @param {string} chave   uma das chaves de ROTAS
 * @param {?string} token  o token, ou null/undefined no modo autenticado
 * @param {Array|Object} params  parâmetros ALÉM do token (ex.: o id da tarefa)
 */
export function rotaDoPortal(chave, token, params = []) {
    const par = ROTAS[chave];

    if (! par) {
        // Falhar alto: uma chave errada aqui viraria `undefined` no `route()`,
        // e o erro apareceria longe da causa.
        throw new Error(`rotaDoPortal: chave desconhecida "${chave}"`);
    }

    const extras = Array.isArray(params) ? params : [params];

    if (token && ! par.porToken) {
        throw new Error(
            `rotaDoPortal: "${chave}" não existe no modo por token — é ação da equipe autenticada.`
        );
    }

    return token
        ? route(par.porToken, [token, ...extras])
        : route(par.autenticada, extras);
}
