// Apoio da tela "Jardinagem" (23/10/2026): rótulos que espelham as constantes do
// PubJardinagem (o teste tests/js/publicador-jardinagem.test.js confere), os
// filtros da URL e os formatadores. Sem React aqui: dá para testar sozinho.

// Caminho relativo COM extensão: o node --test importa este arquivo direto, sem o alias "@" do Vite.
export { comoLista, comoObjeto, textoSeguro, numeroSeguro, fmtQuando } from './tarefasPosPublicacao.js';

export const ROTULO_STATUS = {
    pendente: 'Pendente',
    em_andamento: 'Em andamento',
    feita: 'Concluída',
    substituida: 'Substituída pelo mês seguinte',
};

export const ROTULO_ESTADO_ITEM = {
    pendente: 'pendente',
    feito: 'feito',
    nao_se_aplica: 'não se aplica',
};

export const DIAGNOSTICOS = ['sem_tracao', 'visita_sem_venda', 'conversao_baixa', 'conversao_boa'];

export const FILTROS_STATUS = [
    { chave: 'abertas', rotulo: 'Abertas' },
    { chave: 'feita', rotulo: 'Concluídas' },
    { chave: 'substituida', rotulo: 'Substituídas' },
    { chave: 'todas', rotulo: 'Todas' },
];

/** Só o que difere do padrão vai para a URL. */
export function queryDosFiltros({ status = 'abertas', diagnostico = null, conta = null, pagina = 1 } = {}) {
    const q = {};
    if (status && status !== 'abertas') q.status = status;
    if (typeof diagnostico === 'string' && DIAGNOSTICOS.includes(diagnostico)) q.diagnostico = diagnostico;
    if (typeof conta === 'string' && conta !== '') q.conta = conta;
    if (typeof pagina === 'number' && Number.isFinite(pagina) && pagina > 1) q.pagina = pagina;

    return q;
}

/** "2026-10-23" → "23/10"; qualquer outra coisa → "—". */
export function fmtDia(valor) {
    if (typeof valor !== 'string') return '—';
    const m = valor.match(/^(\d{4})-(\d{2})-(\d{2})/);

    return m ? `${m[3]}/${m[2]}` : '—';
}

/** "2026-10-23" + N dias → "dd/mm" (a data em que o mês do anúncio fecha). */
export function fmtDiaMais(valor, dias) {
    if (typeof valor !== 'string' || typeof dias !== 'number' || ! Number.isFinite(dias)) return '—';
    const m = valor.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (! m) return '—';
    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + dias));

    return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Inteiro no formato do Brasil (1.234); fora de número → "—". */
export function fmtInteiro(valor) {
    return typeof valor === 'number' && Number.isFinite(valor) ? Math.round(valor).toLocaleString('pt-BR') : '—';
}

/** Conversão com duas casas (2,50%); sem visita (nula) → "—". */
export function fmtConversao(valor) {
    return typeof valor === 'number' && Number.isFinite(valor)
        ? `${valor.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`
        : '—';
}
