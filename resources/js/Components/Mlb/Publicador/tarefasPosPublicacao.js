// ─── Tarefas pós-publicação (09/10/2026): rótulos e formatadores da fila ───
//
// ESM puro (sem JSX) para o `node --test` importar. As chaves espelham o PHP
// (`PubTarefa::STATUS`, `ESTADOS_DO_ITEM`, o selo do `MlbPublicadorTarefasController`):
// o teste de gate confere o espelho.

export const ROTULO_STATUS = {
    pendente: 'Pendente',
    em_andamento: 'Em andamento',
    feita: 'Concluída',
    cancelada: 'Cancelada',
};

export const ROTULO_SELO = {
    atrasado: 'Atrasado',
    hoje: 'Hoje',
    programado: 'Programado',
};

export const ROTULO_ESTADO_ITEM = {
    pendente: 'Pendente',
    feito: 'Feito',
    nao_se_aplica: 'Não se aplica',
};

// "Toda a equipe" e não "Todas": ao lado, o filtro de situação também tem "Todas".
export const ESCOPOS = [
    { chave: 'todas', rotulo: 'Toda a equipe' },
    { chave: 'minhas', rotulo: 'Minhas' },
];

export const FILTROS_STATUS = [
    { chave: 'abertas', rotulo: 'Abertas' },
    { chave: 'pendente', rotulo: 'Pendentes' },
    { chave: 'em_andamento', rotulo: 'Em andamento' },
    { chave: 'feita', rotulo: 'Concluídas' },
    { chave: 'todas', rotulo: 'Todas' },
];

/** O servidor pode mandar `null` (ou outra forma): lista de verdade ou lista vazia. */
export const comoLista = (valor) => (Array.isArray(valor) ? valor : []);

/** Objeto de verdade ou objeto vazio. */
export const comoObjeto = (valor) => ((valor !== null && typeof valor === 'object' && ! Array.isArray(valor)) ? valor : {});

/** Só string/número viram texto na tela; objeto no lugar de texto cai no reserva (nunca "[object Object]"). */
export const textoSeguro = (valor, reserva = '—') => ((typeof valor === 'string' || typeof valor === 'number') ? String(valor) : reserva);

export const numeroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) ? valor : null);

/** Prazo `aaaa-mm-dd` → `dd/mm` (data pura: sem conversão de fuso). */
export function fmtPrazo(valor) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(valor ?? '').slice(0, 10));

    return m ? `${m[3]}/${m[2]}` : '—';
}

/** Instante ISO → `dd/mm hh:mm` em São Paulo. */
export function fmtQuando(valor) {
    if (! valor) return '—';
    const d = new Date(String(valor));
    if (Number.isNaN(d.getTime())) return '—';

    return d.toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    }).replace(',', '');
}

// ─── Promoção automática de cada anúncio (10/10/2026) ───
// Espelha `PubPromocaoAutomatica::STATUS` (o teste de gate confere).
export const ROTULO_PROMOCAO = {
    agendada: 'Programada',
    enviando: 'Criando agora',
    ativa: 'Ativa',
    recusada: 'Não criada',
    encerrada: 'Encerrada',
    cancelada: 'Cancelada',
};

const reais = (n) => (typeof n === 'number' && Number.isFinite(n) ? `R$ ${n.toFixed(2).replace('.', ',')}` : '—');

/**
 * A linha de um anúncio no bloco "Promoção automática": `{ rotulo, texto, orientacao }`, tudo texto
 * pronto. Prop fora da forma vira texto de reserva, nunca "[object Object]".
 */
export function linhaDaPromocao(p) {
    const x = comoObjeto(p);
    const status = textoSeguro(x.status, '');
    const valores = `${reais(x.preco_publicado)} → ${reais(x.preco_promocao)} (−${typeof x.percentual === 'number' ? x.percentual.toFixed(2).replace('.', ',') : '—'}%)`;
    const motivo = textoSeguro(x.motivo, '');
    const ciclo = numeroSeguro(x.ciclo) ?? 1;
    const texto = {
        ativa: `até ${fmtPrazo(x.fim)}: ${valores}${ciclo > 1 ? ' · renovada' : ''}`,
        agendada: `${valores}${motivo ? ` · ${motivo}` : ''}`,
        enviando: `${valores} · enviando ao Mercado Livre`,
        recusada: motivo || 'O sistema não conseguiu criar.',
        encerrada: motivo || `terminou em ${fmtPrazo(x.fim)}`,
        cancelada: motivo || 'cancelada',
    }[status] ?? motivo;

    return {
        rotulo: ROTULO_PROMOCAO[status] ?? textoSeguro(x.status),
        texto,
        orientacao: status === 'recusada' ? textoSeguro(x.orientacao, '') : '',
    };
}

/** A query da fila sem o que é padrão (a URL fica limpa: `?escopo=minhas`, não `?escopo=minhas&status=abertas`). */
export function queryDosFiltros({ escopo = 'todas', status = 'abertas', conta = null, pagina = 1 } = {}) {
    const q = {};
    if (escopo && escopo !== 'todas') q.escopo = escopo;
    if (status && status !== 'abertas') q.status = status;
    if (conta) q.conta = conta;
    if (pagina && pagina > 1) q.pagina = pagina;

    return q;
}
