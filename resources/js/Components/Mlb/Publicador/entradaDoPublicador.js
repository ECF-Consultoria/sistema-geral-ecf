/**
 * Regras puras da entrada do Publicador (`Pages/Mlb/AnunciosEmpresas.jsx`):
 * busca, situação de cada empresa e os helpers de campo seguro.
 *
 * 26/10/2026 — a tela virou "busca primeiro": a lista inteira (Polos, Incubadora
 * e Gestão) chega numa visita só e a busca filtra AQUI, no navegador. Antes a
 * busca ia ao servidor a cada pausa de 350 ms, trocava o bloco pelo esqueleto
 * (o campo sumia e o resto da digitação se perdia) e só procurava no programa
 * da aba escolhida.
 *
 * ⚠️ A tela preta de 07/10 nasceu de um campo que chegou como OBJETO e foi
 * renderizado cru. Todo campo da linha passa por `textoSeguro`/`numeroSeguro`.
 */

/** Só string/number do servidor; qualquer outra forma (objeto, array…) cai no fallback. */
export function textoSeguro(valor, fallback = '—') {
    return (typeof valor === 'string' || typeof valor === 'number') ? String(valor) : fallback;
}

/** Número de verdade ou `null` — `NaN`, string e objeto não viram contagem. */
export function numeroSeguro(valor) {
    return (typeof valor === 'number' && Number.isFinite(valor)) ? valor : null;
}

/** Até duas letras do nome para o quadradinho da linha e dos Recentes. */
export function iniciaisDe(nome) {
    const limpo = (typeof nome === 'string' ? nome : '').trim();
    if (limpo === '') return '—';
    const partes = limpo.split(/\s+/).filter(Boolean);
    const letras = partes.length >= 2 ? partes[0].charAt(0) + partes[1].charAt(0) : limpo.slice(0, 2);

    return letras.toUpperCase();
}

export const ROTULO_PROGRAMA = { polos: 'Polos', incubadora: 'Incubadora', gestao: 'Gestão' };

/** Ordem fixa das etiquetas de programa. */
export const ORDEM_PROGRAMAS = ['polos', 'incubadora', 'gestao'];

/** Minúsculas e sem acento: "Móveis" casa com "moveis". */
export function normalizar(texto) {
    return textoSeguro(texto, '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const soDigitos = (texto) => textoSeguro(texto, '').replace(/\D/g, '');

/**
 * A empresa casa com a busca quando CADA palavra digitada aparece no nome ou no
 * identificador (CNPJ/CUST). "absolut pesca" acha "ABSOLUTCARGOEPESCA Is rede".
 *
 * ⚠️ CNPJ está gravado em dois formatos no banco (com pontuação e só dígitos —
 * 26/10/2026: 35 × 24 só em Gestão). Por isso uma palavra com 4 dígitos ou mais
 * também é comparada só pelos dígitos: colar "25166424000151" acha
 * "25.166.424/0001-51" e vice-versa. Com menos de 4 dígitos, não ("Loja 2"
 * não pode casar com todo CNPJ que tenha um 2).
 */
export function empresaCasaBusca(empresa, consulta) {
    const palavras = normalizar(consulta).split(/\s+/).filter(Boolean);
    if (palavras.length === 0) return true;

    const alvo = normalizar(empresa?.nome) + ' ' + normalizar(empresa?.identificador);
    const digitosDoId = soDigitos(empresa?.identificador);

    return palavras.every((p) => {
        if (alvo.includes(p)) return true;
        const digitos = soDigitos(p);

        return digitos.length >= 4 && digitosDoId.includes(digitos);
    });
}

/** Filtra pelo programa escolhido e pela busca. `programa = 'todos'` não filtra programa. */
export function filtrarEmpresas(empresas, { programa = 'todos', busca = '' } = {}) {
    const lista = Array.isArray(empresas) ? empresas : [];

    return lista.filter((e) => (programa === 'todos' || e?.programa === programa) && empresaCasaBusca(e, busca));
}

/** "Polos e Gestão" / "Polos, Incubadora e Gestão" — só programas com empresa. */
export function textoDosProgramas(programas) {
    const nomes = ORDEM_PROGRAMAS
        .filter((p) => (numeroSeguro(programas?.[p]) ?? 0) > 0)
        .map((p) => ROTULO_PROGRAMA[p]);
    if (nomes.length <= 1) return nomes[0] ?? '';

    return nomes.slice(0, -1).join(', ') + ' e ' + nomes[nomes.length - 1];
}

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/**
 * O que a linha diz e qual ÚNICA ação ela oferece, por ordem de prioridade:
 * 1. conta do ML desconectada → "Copiar link de reconexão" (sem token não se publica nada);
 * 2. rascunhos conferidos → "Publicar";
 * 3. Portal com ofertas que ainda não viraram produto → "Sincronizar";
 * 4. o resto → só "Conectada" e a seta.
 *
 * @returns {{ conectada: boolean, texto: string|null, acao: 'reconectar'|'publicar'|'sincronizar'|null }}
 */
export function situacaoDaEmpresa(empresa) {
    const conectada = empresa?.token === 'ativo';
    const produtos = numeroSeguro(empresa?.produtos) ?? 0;
    const prontos = numeroSeguro(empresa?.prontos) ?? 0;
    const portal = (empresa?.portal !== null && typeof empresa?.portal === 'object') ? empresa.portal : {};
    const situacaoPortal = textoSeguro(portal.situacao, 'sem_portal');
    const novas = numeroSeguro(portal.novas) ?? 0;
    const podeSincronizar = Boolean(numeroSeguro(empresa?.company_id)) && situacaoPortal !== 'sem_portal';

    if (! conectada) {
        return { conectada, texto: 'Conta do Mercado Livre desconectada', acao: 'reconectar' };
    }
    if (prontos > 0) {
        return { conectada, texto: `${plural(produtos, 'produto', 'produtos')} · ${prontos} ${prontos === 1 ? 'pronto' : 'prontos'}`, acao: 'publicar' };
    }
    if (podeSincronizar && situacaoPortal === 'nunca') {
        return { conectada, texto: 'Portal nunca sincronizado', acao: 'sincronizar' };
    }
    if (podeSincronizar && situacaoPortal === 'novas' && novas > 0) {
        return { conectada, texto: `${plural(novas, 'oferta nova', 'ofertas novas')} no Portal`, acao: 'sincronizar' };
    }
    // Conta com token mas fora da lista de liberadas (só existe com `publicador.contas_liberadas.todas` desligado).
    if (empresa?.liberada === false) {
        return { conectada, texto: 'Publicação ainda não liberada', acao: null };
    }

    return { conectada, texto: produtos > 0 ? plural(produtos, 'produto', 'produtos') : null, acao: null };
}
