// ═══════════════════════════════════════════════════════════════════════
// Tarefas do time dos Polos — "feito hoje × meta" (TKT-0017, DEV-44).
//
// Cada tarefa é um cartão que a gestão cria no Painel Polos: um INDICADOR de uma lista fixa,
// a meta que ela digita, responsável e prazo. O "feito" é quantas empresas das fases M1–M4
// estão HOJE com o indicador feito — cumulativo, como no pedido ("meta 120, já ativamos 80;
// bateu, sobe para 180"). Nada zera na virada do mês: quem muda a meta é a pessoa.
//
// A régua de cada indicador foi aprovada no ticket. Os campos são TEXTO LIVRE (vêm da planilha
// e de valores criados inline), então a comparação ignora acento, caixa e espaço — igualdade
// estrita zeraria um cartão calado na primeira célula digitada com caixa diferente.
//
// As chaves e os estágios espelham PoloTarefaMeta (PHP). Não há enum compartilhado: mudar um
// lado exige mudar o outro. JS puro (sem React) para ser testado com node:test.
// ═══════════════════════════════════════════════════════════════════════

/** Universo de toda tarefa: as empresas destas fases (resposta da Débora: "de M1 a M4"). */
export const FASES_TAREFAS = ['M1', 'M2', 'M3', 'M4'];

/** Ordem dos estágios de publicação (strings EXATAS de mlb_empresas.estagio). */
export const ESTAGIOS_ORDEM = ['Não Listado', 'Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido'];

/** Estágios que a tarefa de Publicação pode ter como alvo. */
export const ESTAGIOS_ALVO = ['Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido'];

/** Sem alvo escolhido, Publicação = saiu de "Não Listado". */
export const ESTAGIO_ALVO_PADRAO = 'Estágio 1';

// Cores dos estágios no mesmo matiz do badge do Painel (Pages/Polos/components/estagioBadge.js):
// a TV e a grade não podem pintar o mesmo estágio de cores diferentes.
export const COR_ESTAGIO = {
    'Não Listado': '#9ca3af',
    'Estágio 1':   '#60a5fa',
    'Estágio 2':   '#a78bfa',
    'Estágio 3':   '#fbbf24',
    'Concluido':   '#34d399',
};
export const COR_SEM_ESTAGIO = '#4b5563';

// Paleta da tela Tarefas da TV — a do desenho anexado ao ticket (vermelho, amarelo, verde,
// violeta, azul), pedida "igual à imagem". Só a TV usa: a grade do Painel segue com a do badge.
export const COR_ESTAGIO_TV = {
    'Não Listado': '#ef4444',
    'Estágio 1':   '#facc15',
    'Estágio 2':   '#22c55e',
    'Estágio 3':   '#a855f7',
    'Concluido':   '#3b82f6',
};

/** Sem acento, sem caixa e sem espaço nas pontas. */
export function normaliza(valor) {
    return String(valor ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .trim()
        .toLowerCase();
}

const igual = (valor, alvo) => normaliza(valor) === normaliza(alvo);

/** 'Concluido' é a chave do banco (sem acento de propósito); na tela, com acento. */
export const rotuloEstagio = (estagio) => (normaliza(estagio) === 'concluido' ? 'Concluído' : estagio);

/** Posição do estágio na ordem (0 = Não Listado … 4 = Concluído); -1 se vazio ou fora da ordem. */
export function nivelEstagio(estagio) {
    const n = normaliza(estagio);
    if (n === '') return -1;
    return ESTAGIOS_ORDEM.findIndex((s) => normaliza(s) === n);
}

/**
 * Indicadores e a régua de "feito" de cada um. `regra` é o texto que o formulário mostra para
 * quem cria a tarefa saber o que está sendo contado.
 */
export const INDICADORES_TAREFA = {
    places: {
        rotulo: 'Places',
        regra: 'Places "Ativo"',
        feito: (e) => igual(e.places, 'Ativo'),
    },
    me1: {
        rotulo: 'ME1',
        regra: 'ME1 "Ativo"',
        feito: (e) => igual(e.me1, 'Ativo'),
    },
    decola: {
        rotulo: 'Decola',
        regra: 'Decola "Sim" ou confirmado pelo Mercado Livre',
        // O selo automático (o ML confirma o Decola ativo) vale tanto quanto o "Sim" marcado à mão.
        feito: (e) => igual(e.decola, 'Sim') || e.decola_ml_ativo === true,
    },
    central_promocao: {
        rotulo: 'Central de Promoções',
        regra: 'Central de Promoções "Sim"',
        feito: (e) => igual(e.central_promocao, 'Sim'),
    },
    colaborador: {
        rotulo: 'Acesso colaborador',
        regra: 'Acesso colaborador "Com acesso"',
        feito: (e) => igual(e.acesso_colaborador, 'Com acesso'),
    },
    campanha: {
        rotulo: 'Campanha',
        regra: 'campanha ativa (leitura da Adman; sem leitura, a marcação manual)',
        feito: (e) => e.ads_campanha === 'ativa',
    },
    adman: {
        rotulo: 'Adman',
        regra: 'conta vinculada na Adman',
        feito: (e) => e.adman_vinculo === 'Vinculado',
    },
    ppa: {
        rotulo: 'PPA',
        regra: 'plano de ação criado',
        feito: (e) => e.tem_ppa === true,
    },
    publicacao: {
        rotulo: 'Publicação',
        regra: 'o estágio escolhido ou adiante',
        // Evolução de estágios no modelo feito × meta: conta quem JÁ chegou ao alvo.
        feito: (e, tarefa) => {
            const alvo = nivelEstagio(tarefa?.estagio_alvo || ESTAGIO_ALVO_PADRAO);
            const atual = nivelEstagio(e.estagio);
            return alvo > 0 && atual >= alvo;
        },
    },
    manual: {
        rotulo: 'Contagem manual',
        regra: 'o número que você atualiza (lista de empresas escolhida à mão)',
        // Sem coluna na ficha que diga quem está feito: o número vem de `feito_manual`.
        manual: true,
        feito: () => false,
    },
};

/** Ordem do select do formulário (a mesma de PoloTarefaMeta::INDICADORES). */
export const ORDEM_INDICADORES = Object.keys(INDICADORES_TAREFA);

/** Empresas que entram na conta de toda tarefa. */
export const universoTarefas = (empresas = []) => empresas.filter((e) => FASES_TAREFAS.includes(e?.fase));

/** Quantas empresas do universo estão com o indicador da tarefa feito. */
export function feitoDaTarefa(tarefa, universo = []) {
    const ind = INDICADORES_TAREFA[tarefa?.indicador];
    if (!ind) return 0;
    if (ind.manual) return Math.max(0, parseInt(tarefa?.feito_manual, 10) || 0);
    return universo.reduce((n, e) => n + (ind.feito(e, tarefa) ? 1 : 0), 0);
}

/** Data local de hoje como 'YYYY-MM-DD' (nunca toISOString: em Brasília vira o dia seguinte à noite). */
export function hojeISO(hoje = new Date()) {
    const p = (n) => String(n).padStart(2, '0');
    return `${hoje.getFullYear()}-${p(hoje.getMonth() + 1)}-${p(hoje.getDate())}`;
}

const DIAS_SEMANA = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

/**
 * "10/10 (sex)" ou "contínuo". Lê os dígitos da string: `new Date('YYYY-MM-DD')` é meia-noite
 * UTC e em Brasília cairia no dia anterior.
 */
export function rotuloPrazo(prazo) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(prazo ?? ''));
    if (!m) return 'contínuo';
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return `${m[3]}/${m[2]} (${DIAS_SEMANA[d.getDay()]})`;
}

/**
 * Progresso de uma tarefa: feito, meta, % (null sem meta; teto de 100), % da barra, se bateu e
 * se está atrasada (prazo vencido sem bater a meta; tarefa contínua nunca atrasa).
 *
 * O % para em 100 a pedido do desenvolvedor (14/10): meta estourada aparecia como "549%" no anel.
 * Quanto passou da meta continua visível no "feito / meta" do cartão.
 */
export function progressoDaTarefa(tarefa, universo = [], hoje = new Date()) {
    const feito = feitoDaTarefa(tarefa, universo);
    const meta = Math.max(0, Number(tarefa?.meta) || 0);
    const pct = meta > 0 ? Math.min(100, Math.round((feito / meta) * 100)) : null;
    const batida = meta > 0 && feito >= meta;
    const prazo = tarefa?.prazo ? String(tarefa.prazo).slice(0, 10) : null;
    const atrasada = !!prazo && !batida && prazo < hojeISO(hoje);
    return {
        feito,
        meta,
        pct,
        pctBarra: pct === null ? 0 : Math.min(100, pct),
        batida,
        atrasada,
        prazoLabel: rotuloPrazo(prazo),
    };
}

/**
 * Resumo por responsável: quantas tarefas e a MÉDIA do % de cada uma (teto de 100 por tarefa,
 * para uma meta estourada não esconder outra parada). Tarefa sem meta não entra na média.
 * Ordem: mais tarefas primeiro, depois nome.
 */
export function resumoPorResponsavel(tarefas = [], universo = [], hoje = new Date()) {
    const grupos = new Map();
    tarefas.forEach((t) => {
        const chave = t.responsavel_id ?? 'sem';
        if (!grupos.has(chave)) {
            grupos.set(chave, { id: t.responsavel_id ?? null, nome: t.responsavel_nome || 'Sem responsável', tarefas: 0, pcts: [] });
        }
        const g = grupos.get(chave);
        g.tarefas += 1;
        const { pct } = progressoDaTarefa(t, universo, hoje);
        if (pct !== null) g.pcts.push(Math.min(100, pct));
    });
    return [...grupos.values()]
        .map(({ pcts, ...g }) => ({ ...g, pct: pcts.length > 0 ? Math.round(pcts.reduce((s, p) => s + p, 0) / pcts.length) : null }))
        .sort((a, b) => (b.tarefas - a.tarefas) || a.nome.localeCompare(b.nome, 'pt-BR'));
}

/**
 * Distribuição do universo pelos estágios (Não Listado → Concluído), com "Sem estágio" no fim
 * quando houver empresa vazia ou com valor fora da ordem — sem ela a soma não fecharia com o total.
 */
export function distribuicaoEstagios(universo = []) {
    const total = universo.length;
    const contagem = ESTAGIOS_ORDEM.map(() => 0);
    let semEstagio = 0;
    universo.forEach((e) => {
        const i = nivelEstagio(e.estagio);
        if (i >= 0) contagem[i] += 1; else semEstagio += 1;
    });
    const fatia = (chave, rotulo, n, cor) => ({ chave, rotulo, n, cor, pct: total > 0 ? Math.round((n / total) * 100) : 0 });
    const linhas = ESTAGIOS_ORDEM.map((s, i) => fatia(s, rotuloEstagio(s), contagem[i], COR_ESTAGIO[s]));
    if (semEstagio > 0) linhas.push(fatia('sem', 'Sem estágio', semEstagio, COR_SEM_ESTAGIO));
    return { total, linhas };
}

// Paleta dos responsáveis — a do desenho do ticket (amarelo, violeta, azul…) e depois o resto.
const PALETA_RESPONSAVEIS = ['#ffe600', '#a855f7', '#38bdf8', '#22c55e', '#f97316', '#e879f9', '#14b8a6', '#f43f5e'];

/** Cor estável por responsável: pela ordem do id entre os responsáveis das tarefas. */
export function coresDosResponsaveis(tarefas = []) {
    const ids = [...new Set(tarefas.map((t) => t.responsavel_id).filter((id) => id !== null && id !== undefined))]
        .sort((a, b) => a - b);
    const mapa = {};
    ids.forEach((id, i) => { mapa[id] = PALETA_RESPONSAVEIS[i % PALETA_RESPONSAVEIS.length]; });
    return mapa;
}
export const COR_SEM_RESPONSAVEL = '#9ca3af';

/**
 * Larguras dos cartões na tela Tarefas da TV, numa grade de 12 colunas — o arranjo do desenho
 * do ticket: dois cartões por fileira e, com quantidade ímpar, os três últimos dividindo a
 * última fileira em larguras decrescentes (5 · 4 · 3, como Campanha · Decola · Publicação).
 * Com 10 ou mais, três por fileira; a sobra fecha em fileiras de dois.
 */
export function spansDosCartoes(total) {
    const n = Math.max(0, Math.floor(Number(total) || 0));
    if (n === 0) return [];
    if (n === 1) return [12];
    if (n <= 9) return n % 2 === 0 ? Array(n).fill(6) : [...Array(n - 3).fill(6), 5, 4, 3];
    const resto = n % 3;
    if (resto === 1) return [...Array(n - 4).fill(4), 6, 6, 6, 6];
    if (resto === 2) return [...Array(n - 2).fill(4), 6, 6];
    return Array(n).fill(4);
}

/** Quantas fileiras as larguras ocupam (cada fileira soma 12). */
export function fileirasDosSpans(spans = []) {
    let soma = 0;
    let fileiras = 0;
    spans.forEach((s) => {
        if (soma === 0) fileiras += 1;
        soma += s;
        if (soma >= 12) soma = 0;
    });
    return fileiras;
}
