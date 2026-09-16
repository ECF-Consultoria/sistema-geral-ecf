import {
    addDays, addMinutes, differenceInCalendarDays, differenceInMinutes, endOfMonth, endOfWeek, format,
    isSameDay, startOfDay, startOfMonth, startOfWeek,
} from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { MapPin, Video, Link2, CalendarX2 } from 'lucide-react';

/**
 * O vocabulário da Agenda (16/09/2026), compartilhado pelo cartão da ficha do
 * onboarding e pela página completa.
 *
 * Os catálogos de tipo e de plataforma espelham `OnboardingEventoGoogle` —
 * não há enum compartilhado entre PHP e JS, então os dois são mantidos juntos.
 *
 * As horas são sempre as do navegador. O servidor manda ISO com fuso, e o
 * `new Date()` converte para a hora local — que é a de Brasília para toda a
 * equipe.
 */

// ─── Tipos e cores ─────────────────────────────────────────────────────────

/**
 * As classes são escritas por extenso de propósito: o Tailwind só gera o que
 * encontra literal no código, e `bg-${cor}-400` sumiria do CSS.
 */
export const CORES = {
    amarelo: {
        bloco: 'bg-ecf-yellow/[0.14] border-ecf-yellow/70 text-ecf-yellow',
        ponto: 'bg-ecf-yellow',
        barra: 'bg-ecf-yellow',
    },
    violeta: {
        bloco: 'bg-violet-500/[0.18] border-violet-400/70 text-violet-200',
        ponto: 'bg-violet-400',
        barra: 'bg-violet-400',
    },
    azul: {
        bloco: 'bg-sky-500/[0.16] border-sky-400/70 text-sky-200',
        ponto: 'bg-sky-400',
        barra: 'bg-sky-400',
    },
    verde: {
        bloco: 'bg-emerald-500/[0.15] border-emerald-400/70 text-emerald-200',
        ponto: 'bg-emerald-400',
        barra: 'bg-emerald-400',
    },
    rosa: {
        bloco: 'bg-rose-500/[0.15] border-rose-400/70 text-rose-200',
        ponto: 'bg-rose-400',
        barra: 'bg-rose-400',
    },
    neutro: {
        bloco: 'bg-white/[0.07] border-white/30 text-white/75',
        ponto: 'bg-white/45',
        barra: 'bg-white/45',
    },
};

export const TIPOS = {
    kickoff:      { rotulo: 'Reunião de onboarding',     cor: 'amarelo', comCliente: true },
    mapeamento:   { rotulo: 'Mapeamento da conta',       cor: 'violeta', comCliente: true },
    apresentacao: { rotulo: 'Apresentação ECF',          cor: 'azul',    comCliente: true },
    recorrente:   { rotulo: 'Reunião de acompanhamento', cor: 'verde',   comCliente: true },
    outro:        { rotulo: 'Outro evento',              cor: 'rosa',    comCliente: false },
};

/** Os que o "Agendar" cria — a rotina tem o próprio fluxo na ficha. */
export const TIPOS_CRIAVEIS = ['kickoff', 'mapeamento', 'apresentacao', 'outro'];

/** Compromisso do Google que o sistema não criou. */
export const ORIGEM_GOOGLE = { rotulo: 'Google Agenda', cor: 'neutro' };

export function corDoEvento(evento) {
    return CORES[TIPOS[evento?.tipo]?.cor ?? ORIGEM_GOOGLE.cor];
}

/** A chave de filtro de um evento: o tipo, ou "google" quando não é do sistema. */
export function categoriaDoEvento(evento) {
    return evento?.tipo && TIPOS[evento.tipo] ? evento.tipo : 'google';
}

// ─── Plataformas ───────────────────────────────────────────────────────────

export const PLATAFORMAS = {
    google_meet: { rotulo: 'Google Meet',     icone: Video },
    teams:       { rotulo: 'Microsoft Teams', icone: Video },
    zoom:        { rotulo: 'Zoom',            icone: Video },
    link:        { rotulo: 'Link externo',    icone: Link2 },
    presencial:  { rotulo: 'Presencial',      icone: MapPin },
};

/** As opções do formulário (as mesmas de `OnboardingEventoGoogle::PLATAFORMAS`). */
export const PLATAFORMAS_DO_FORMULARIO = [
    { valor: 'google_meet', rotulo: 'Google Meet', icone: Video },
    { valor: 'link',        rotulo: 'Teams, Zoom ou outro link', icone: Link2 },
    { valor: 'presencial',  rotulo: 'Presencial', icone: MapPin },
    { valor: 'nenhuma',     rotulo: 'Sem local', icone: CalendarX2 },
];

/** Do que o Google registrou para o que o formulário edita. */
export function plataformaParaFormulario(evento) {
    switch (evento?.plataforma) {
        case 'google_meet':
            return { plataforma: 'google_meet', link: '' };
        case 'teams':
        case 'zoom':
        case 'link':
            return { plataforma: 'link', link: evento.link ?? '' };
        case 'presencial':
            return { plataforma: 'presencial', link: evento.local ?? '' };
        default:
            return { plataforma: 'nenhuma', link: '' };
    }
}

// ─── Datas ─────────────────────────────────────────────────────────────────

const dois = (n) => String(n).padStart(2, '0');

export const ymd = (d) => `${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())}`;

/** 'YYYY-MM-DD' vira meia-noite LOCAL — `new Date('2026-09-16')` seria UTC. */
export function deYmd(texto) {
    if (! texto || ! /^\d{4}-\d{2}-\d{2}$/.test(texto)) return null;
    const [a, m, d] = texto.split('-').map(Number);

    return new Date(a, m - 1, d);
}

/** O formato do `datetime-local` e da API ('YYYY-MM-DDTHH:mm'), em hora local. */
export function paraInputLocal(data) {
    if (! data) return '';
    const d = data instanceof Date ? data : new Date(data);
    if (Number.isNaN(d.getTime())) return '';

    return `${ymd(d)}T${dois(d.getHours())}:${dois(d.getMinutes())}`;
}

/** "quarta-feira, 16 de setembro" → "Quarta-feira, 16 de setembro" (o `capitalize` do CSS faria "De Setembro"). */
export const primeiraMaiuscula = (texto) => (texto ? texto.charAt(0).toUpperCase() + texto.slice(1) : texto);

export const ordenarPorInicio = (eventos) => [...eventos].sort((a, b) =>
    new Date(a.inicio) - new Date(b.inicio) || new Date(a.fim) - new Date(b.fim));

/**
 * O título sem o "ECF · Empresa — " que o sistema põe no convite. Na ficha do
 * onboarding todo evento é da mesma empresa, e o prefixo só corta o que importa.
 */
export function tituloSemEmpresa(evento) {
    const empresa = evento?.vinculo?.empresa;
    const prefixo = empresa ? `ECF · ${empresa} — ` : null;

    return prefixo && evento.titulo?.startsWith(prefixo) ? evento.titulo.slice(prefixo.length) : evento?.titulo;
}

export const horaCurta = (d) => `${dois(d.getHours())}:${dois(d.getMinutes())}`;

export function faixaDeHorario(evento) {
    if (evento.dia_inteiro) return 'Dia inteiro';

    return `${horaCurta(new Date(evento.inicio))} – ${horaCurta(new Date(evento.fim))}`;
}

export function duracaoEmMinutos(evento) {
    return Math.max(5, differenceInMinutes(new Date(evento.fim), new Date(evento.inicio)));
}

/** "Hoje", "Amanhã" ou "qua., 17 set." */
export function rotuloDoDia(data, agora = new Date()) {
    const dias = differenceInCalendarDays(data, agora);
    if (dias === 0) return 'Hoje';
    if (dias === 1) return 'Amanhã';
    if (dias === -1) return 'Ontem';

    return primeiraMaiuscula(format(data, "EEE, d 'de' MMM", { locale: ptBR }));
}

/** "Agora", "Em 25 min", "Em 2h", "Amanhã", "Em 3 dias" — a distância até o evento. */
export function distancia(evento, agora = new Date()) {
    const inicio = new Date(evento.inicio);
    const fim = new Date(evento.fim);

    if (inicio <= agora && fim > agora) return 'Agora';
    if (fim <= agora) return 'Encerrado';

    const minutos = differenceInMinutes(inicio, agora);
    const dias = differenceInCalendarDays(inicio, agora);

    if (dias === 0) {
        if (minutos < 60) return `Em ${Math.max(1, minutos)} min`;

        return `Em ${Math.round(minutos / 60)}h`;
    }
    if (dias === 1) return 'Amanhã';
    if (dias < 14) return `Em ${dias} dias`;

    return `Em ${Math.round(dias / 7)} sem.`;
}

export const inicioDaSemana = (d) => startOfWeek(d, { weekStartsOn: 1 });

/** O intervalo de dias que cada visão pede ao servidor. */
export function intervaloDaVisao(visao, ancora) {
    switch (visao) {
        case 'dia':
            return [startOfDay(ancora), startOfDay(ancora)];
        case 'mes':
            return [
                startOfWeek(startOfMonth(ancora), { weekStartsOn: 0 }),
                endOfWeek(endOfMonth(ancora), { weekStartsOn: 0 }),
            ];
        case 'lista':
            return [startOfDay(ancora), addDays(startOfDay(ancora), 30)];
        default:
            return [inicioDaSemana(ancora), addDays(inicioDaSemana(ancora), 6)];
    }
}

export function rotuloDoPeriodo(visao, ancora) {
    const [de, ate] = intervaloDaVisao(visao, ancora);

    if (visao === 'dia') {
        return primeiraMaiuscula(format(ancora, "EEEE, d 'de' MMMM 'de' yyyy", { locale: ptBR }));
    }
    if (visao === 'mes') {
        return primeiraMaiuscula(format(ancora, "MMMM 'de' yyyy", { locale: ptBR }));
    }
    if (de.getMonth() === ate.getMonth()) {
        return `${format(de, 'd')} – ${format(ate, "d 'de' MMMM 'de' yyyy", { locale: ptBR })}`;
    }
    if (de.getFullYear() === ate.getFullYear()) {
        return `${format(de, "d 'de' MMM", { locale: ptBR })} – ${format(ate, "d 'de' MMM 'de' yyyy", { locale: ptBR })}`;
    }

    return `${format(de, "d 'de' MMM 'de' yyyy", { locale: ptBR })} – ${format(ate, "d 'de' MMM 'de' yyyy", { locale: ptBR })}`;
}

/** Os eventos que tocam um dia (um evento de 23h às 1h aparece nos dois). */
export function eventosDoDia(eventos, dia) {
    const comeco = startOfDay(dia);
    const fim = addDays(comeco, 1);

    return ordenarPorInicio(eventos.filter((e) => new Date(e.inicio) < fim && new Date(e.fim) > comeco));
}

/**
 * Dias com evento, para os pontos do mini calendário: `ymd` → cores (no
 * máximo três, sem repetir).
 */
export function marcasPorDia(eventos) {
    const mapa = new Map();

    eventos.forEach((evento) => {
        let dia = startOfDay(new Date(evento.inicio));
        const fim = new Date(evento.fim);
        // Evento de dia inteiro termina à meia-noite do dia seguinte (exclusivo).
        for (let i = 0; i < 31 && dia < fim; i++, dia = addDays(dia, 1)) {
            const chave = ymd(dia);
            const cores = mapa.get(chave) ?? [];
            const ponto = corDoEvento(evento).ponto;
            if (! cores.includes(ponto) && cores.length < 3) cores.push(ponto);
            mapa.set(chave, cores);
            if (! evento.dia_inteiro) break;
        }
    });

    return mapa;
}

/**
 * Eventos que se sobrepõem dividem a largura da coluna, como no Google
 * Agenda: cada grupo de eventos encadeados ganha tantas faixas quantas
 * precisar, e cada evento ocupa a primeira faixa livre.
 *
 * @returns {Array<{evento, faixa: number, faixas: number}>}
 */
export function distribuirEmFaixas(eventos) {
    const ordenados = [...eventos].sort((a, b) =>
        new Date(a.inicio) - new Date(b.inicio) || new Date(b.fim) - new Date(a.fim));

    const resultado = [];
    let grupo = [];
    let fimDoGrupo = null;

    const fecharGrupo = () => {
        const faixasFim = [];
        const doGrupo = grupo.map((evento) => {
            const inicio = new Date(evento.inicio);
            let faixa = faixasFim.findIndex((fim) => fim <= inicio);
            if (faixa === -1) {
                faixa = faixasFim.length;
                faixasFim.push(null);
            }
            faixasFim[faixa] = new Date(evento.fim);

            return { evento, faixa };
        });
        doGrupo.forEach((item) => resultado.push({ ...item, faixas: faixasFim.length }));
        grupo = [];
        fimDoGrupo = null;
    };

    ordenados.forEach((evento) => {
        const inicio = new Date(evento.inicio);
        if (fimDoGrupo && inicio >= fimDoGrupo) fecharGrupo();
        grupo.push(evento);
        const fim = new Date(evento.fim);
        fimDoGrupo = fimDoGrupo && fimDoGrupo > fim ? fimDoGrupo : fim;
    });
    if (grupo.length) fecharGrupo();

    return resultado;
}

/** Compromissos que ocupam o horário escolhido (os "livres" e recusados não contam). */
export function conflitos(ocupados, inicio, duracaoMin, ignorarId = null) {
    const fim = addMinutes(inicio, duracaoMin);

    return ocupados.filter((e) => ! e.dia_inteiro
        && ! e.livre
        && e.sua_resposta !== 'declined'
        && (! ignorarId || (e.google_event_id !== ignorarId && e.id !== ignorarId))
        && new Date(e.inicio) < fim
        && new Date(e.fim) > inicio);
}

export { isSameDay };

// ─── Rede ──────────────────────────────────────────────────────────────────

/**
 * Escrita em JSON pelo axios do projeto — ele já leva o CSRF, renovado a cada
 * resposta do Inertia (app.jsx). Nunca lança: devolve `{ ok, mensagem, erros }`.
 */
export async function enviarJson(metodo, url, corpo = undefined) {
    try {
        const resposta = await window.axios({ method: metodo, url, data: corpo, headers: { Accept: 'application/json' } });

        return { ok: true, ...(resposta.data ?? {}) };
    } catch (erro) {
        const dados = erro?.response?.data ?? {};
        const erros = dados.errors ?? null;
        const primeiro = erros ? Object.values(erros).flat()[0] : null;
        const status = erro?.response?.status;

        let mensagem = dados.mensagem ?? primeiro ?? dados.message;
        if (! mensagem) {
            mensagem = status === 419
                ? 'A sessão expirou. Recarregue a página e tente de novo.'
                : 'Não deu para falar com o servidor agora. Tente de novo.';
        }
        if (status === 403 && ! dados.mensagem) mensagem = 'Você não tem acesso a este onboarding.';

        return { ok: false, mensagem, erros };
    }
}
