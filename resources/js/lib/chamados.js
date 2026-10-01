// Chamados — rótulos e utilidades. Espelho manual das constantes de App\Models\Chamado.
// Classes de cor ficam em Components/Chamados/Partes.jsx: o Tailwind só varre .jsx.

export const STATUS_CHAMADO = {
    aberto:                 'Aberto',
    em_triagem:             'Em triagem',
    em_atendimento:         'Em atendimento',
    aguardando_solicitante: 'Aguardando solicitante',
    resolvido:              'Resolvido',
    cancelado:              'Cancelado',
};

// Para quem abriu, "Aguardando solicitante" é "Aguardando você".
export const STATUS_PARA_SOLICITANTE = { ...STATUS_CHAMADO, aguardando_solicitante: 'Aguardando você' };

export const STATUS_MANUAIS = ['aberto', 'em_triagem', 'em_atendimento', 'aguardando_solicitante'];

export const TIPOS = {
    problema:         'Problema / Erro',
    duvida:           'Dúvida / Suporte',
    melhoria:         'Melhoria',
    acesso:           'Acesso / Configuração',
    nova_solicitacao: 'Nova solicitação',
    outro:            'Outro',
};

export const IMPACTOS = {
    normal:         'Consigo continuar trabalhando normalmente',
    atrapalha:      'Está atrapalhando meu trabalho',
    impedido:       'Estou impedido de continuar',
    varias_pessoas: 'Está afetando várias pessoas',
};

// Versão curta para a lista da equipe.
export const IMPACTO_CURTO = {
    normal:         'Sem bloqueio',
    atrapalha:      'Atrapalha',
    impedido:       'Impedido',
    varias_pessoas: 'Várias pessoas',
};

export const encerrado = (status) => status === 'resolvido' || status === 'cancelado';

// ISO → "23/09 14:05" (ano só quando não é o corrente).
export const fmtDataHora = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    const agora = new Date();
    const data = d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', ...(d.getFullYear() !== agora.getFullYear() ? { year: 'numeric' } : {}) });
    return `${data} ${d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
};

// "há 5 min", "há 3 h", "há 2 dias".
export const haQuanto = (iso, agora = Date.now()) => {
    if (!iso) return '';
    const min = Math.max(0, Math.round((agora - new Date(iso).getTime()) / 60000));
    if (min < 1) return 'agora';
    if (min < 60) return `há ${min} min`;
    const h = Math.round(min / 60);
    if (h < 24) return `há ${h} h`;
    const d = Math.round(h / 24);
    return `há ${d} ${d === 1 ? 'dia' : 'dias'}`;
};

export const fmtTamanho = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`);

// Busca da caixa da equipe: código, título ou nome de quem abriu.
export const casaBusca = (c, termo) => {
    const t = termo.trim().toLowerCase();
    if (!t) return true;
    return [c.codigo, c.titulo, c.solicitante].some((v) => (v ?? '').toLowerCase().includes(t));
};

// Filtros rápidos da caixa da equipe.
export const FILTROS_RAPIDOS = {
    meus:       { rotulo: 'Meus tickets',          casa: (c, eu) => c.responsavel?.id === eu && !encerrado(c.status) },
    novos:      { rotulo: 'Novos',                 casa: (c) => c.status === 'aberto' },
    atendimento:{ rotulo: 'Em atendimento',        casa: (c) => c.status === 'em_atendimento' || c.status === 'em_triagem' },
    aguardando: { rotulo: 'Aguardando solicitante', casa: (c) => c.status === 'aguardando_solicitante' },
    resolvidos: { rotulo: 'Resolvidos',            casa: (c) => encerrado(c.status) },
    todos:      { rotulo: 'Todos',                 casa: () => true },
};
