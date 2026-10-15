import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { fmtCobertura, fmtDia } from '@/lib/planejadorFull';

// ─── Status ────────────────────────────────────────────────────────────────
// Mesmas faixas do HTML original; cor = tom de alerta (vermelho → azul → cinza).
export const STATUS = {
    ruptura: { label: 'Ruptura', desc: 'Sem estoque apto no FULL agora', ponto: 'bg-red-500', chip: 'bg-red-500/15 text-red-300 border-red-500/30', barra: 'bg-red-500' },
    critico: { label: 'Crítico', desc: 'Acaba antes do próximo envio chegar', ponto: 'bg-orange-500', chip: 'bg-orange-500/15 text-orange-300 border-orange-500/30', barra: 'bg-orange-500' },
    atencao: { label: 'Atenção', desc: 'Chega abaixo do estoque de segurança', ponto: 'bg-amber-400', chip: 'bg-amber-400/15 text-amber-200 border-amber-400/30', barra: 'bg-amber-400' },
    ok: { label: 'OK', desc: 'Cobertura adequada', ponto: 'bg-emerald-500', chip: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30', barra: 'bg-emerald-500' },
    excesso: { label: 'Excesso', desc: 'Cobertura acima do limite', ponto: 'bg-sky-500', chip: 'bg-sky-500/15 text-sky-300 border-sky-500/30', barra: 'bg-sky-500' },
    semgiro: { label: 'Sem giro', desc: 'Sem venda nos últimos 30 dias', ponto: 'bg-white/30', chip: 'bg-white/[0.06] text-white/60 border-white/10', barra: 'bg-white/30' },
};
export const ORDEM_STATUS = ['ruptura', 'critico', 'atencao', 'ok', 'excesso', 'semgiro'];

export const STATUS_COMPRA = {
    ja: { label: 'Pedir já', ponto: 'bg-red-500', chip: STATUS.ruptura.chip, rank: 0 },
    semana: { label: 'Esta semana', ponto: 'bg-orange-500', chip: STATUS.critico.chip, rank: 1 },
    mes: { label: 'Próximas semanas', ponto: 'bg-amber-400', chip: STATUS.atencao.chip, rank: 2 },
    ok: { label: 'OK', ponto: 'bg-emerald-500', chip: STATUS.ok.chip, rank: 3 },
    semgiro: { label: 'Sem giro', ponto: 'bg-white/30', chip: STATUS.semgiro.chip, rank: 4 },
};

export function Chip({ meta, className }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-semibold', meta.chip, className)}>
            <span className={cn('h-1.5 w-1.5 rounded-full', meta.ponto)} />
            {meta.label}
        </span>
    );
}

export function Curva({ abc }) {
    return (
        <span
            title={`Curva ${abc}`}
            className={cn(
                'inline-grid h-5 w-5 place-items-center rounded text-[11px] font-extrabold',
                abc === 'A' && 'bg-ecf-yellow text-black',
                abc === 'B' && 'border border-white/40 text-white',
                abc === 'C' && 'border border-white/15 text-white/45',
            )}
        >
            {abc}
        </span>
    );
}

/** Barra de cobertura: preenchida até a cobertura; o traço marca quando o envio fica apto. */
export function BarraCobertura({ row, R }) {
    if (row.d <= 0) {
        return <span className="text-[12px] text-white/35">sem venda</span>;
    }
    const max = Math.max(R.daysA + R.C.intervaloEnvios + 10, 30);
    const largura = Math.min(100, ((row.cobertura || 0) / max) * 100);
    const marca = Math.min(100, (R.daysA / max) * 100);

    return (
        <div className="flex min-w-[120px] items-center gap-2" title={`Cobertura ${fmtCobertura(row.cobertura)} · envio fica apto em ${R.daysA} dias`}>
            <div className="relative h-2 flex-1 overflow-hidden rounded bg-white/[0.06]">
                <div className={cn('absolute inset-y-0 left-0 rounded-r', STATUS[row.status].barra)} style={{ width: `${largura}%` }} />
                <div className="absolute -inset-y-0.5 w-0.5 bg-white" style={{ left: `${marca}%` }} />
            </div>
            <span className="w-14 text-right text-[12px] tabular-nums text-white/80">{fmtCobertura(row.cobertura)}</span>
        </div>
    );
}

/** Linha do tempo do próximo envio: preparo → agenda → trânsito → recebimento → cobertura. */
export function LinhaDoTempo({ R, compacta = false }) {
    const C = R.C;
    const antes = R.ship - R.base;
    const automatico = !C.proximoDespacho;
    const segmentos = [];

    if (automatico) {
        segmentos.push({ tipo: 'antes', d: C.preparo, t: 'Preparo', s: `${C.preparo} d` });
        segmentos.push({ tipo: 'antes', d: C.agenda, t: 'Espera da agenda', s: `${C.agenda} d` });
    } else {
        segmentos.push({ tipo: 'antes', d: Math.max(antes, 0), t: 'Até o despacho', s: `${antes} d` });
    }
    segmentos.push({ tipo: 'estrada', d: C.transito, t: 'Trânsito', s: `${C.transito} d` });
    segmentos.push({ tipo: 'estrada', d: C.recebimento, t: 'Recebimento', s: `${C.recebimento} d` });
    segmentos.push({ tipo: 'cobre', d: C.intervaloEnvios, t: 'Este envio cobre', s: `${C.intervaloEnvios} d até o próximo` });

    const colunas = segmentos.map((x) => `minmax(56px, ${Math.max(x.d, 0.6)}fr)`).join(' ');
    const nAntes = automatico ? 2 : 1;
    const aviso = !automatico && antes < C.preparo + C.agenda
        ? `A data de despacho deixa ${antes} dia(s), menos que preparo + agenda (${C.preparo + C.agenda} dias).`
        : null;

    return (
        <div className="mt-4">
            <div className="grid gap-x-[3px] gap-y-2" style={{ gridTemplateColumns: colunas }}>
                {segmentos.map((x, i) => (
                    <div
                        key={x.t}
                        title={`${x.t}: ${x.s}`}
                        style={{ gridColumn: i + 1, gridRow: 1 }}
                        className={cn(
                            'flex h-11 min-w-0 flex-col justify-center overflow-hidden rounded-md px-2.5',
                            x.tipo === 'antes' && 'bg-ecf-yellow/15 text-ecf-yellow',
                            x.tipo === 'estrada' && 'bg-white/[0.14] text-white',
                            x.tipo === 'cobre' && 'border border-dashed border-white/15 bg-[repeating-linear-gradient(135deg,transparent_0_9px,rgba(255,255,255,0.05)_9px_12px)] text-white/80',
                        )}
                    >
                        <b className="truncate text-[13px] font-semibold max-sm:hidden">{x.t}</b>
                        <span className="truncate text-[11px] opacity-80 max-sm:text-[12px] max-sm:font-semibold max-sm:opacity-100">{x.s}</span>
                    </div>
                ))}
                <Marco col={`1 / span ${nAntes}`} rotulo="Data base" valor={fmtDia(R.base, 'semana')} />
                <Marco col={`${nAntes + 1} / span 2`} rotulo="Despacho" valor={fmtDia(R.ship, 'semana')} chave />
                <div className="flex min-w-0 justify-between gap-2" style={{ gridColumn: segmentos.length, gridRow: 2 }}>
                    <Marco rotulo="Apto no FULL" valor={fmtDia(R.arrival, 'semana')} chave />
                    <div className="border-r-2 border-ecf-yellow pr-2 text-right text-[11px] leading-tight text-white/45 max-sm:hidden">
                        Próximo despacho
                        <strong className="block whitespace-nowrap text-[13px] tabular-nums text-white">{fmtDia(R.ship + C.intervaloEnvios, 'semana')}</strong>
                    </div>
                </div>
            </div>
            {!compacta && (
                <p className="mt-2.5 text-[12.5px] text-white/60">
                    Lead time do envio: <b className="text-white">{R.LTfull} dias</b> · o FULL precisa aguentar <b className="text-white">{R.daysA} dias</b> até o envio ficar apto.
                </p>
            )}
            {aviso && <p className="mt-1.5 text-[12.5px] font-semibold text-red-300">{aviso}</p>}
        </div>
    );
}

function Marco({ col, rotulo, valor, chave = false }) {
    return (
        <div
            className={cn('min-w-0 border-l-2 pl-2 text-[11px] leading-tight text-white/45', chave ? 'border-ecf-yellow' : 'border-white/15')}
            style={col ? { gridColumn: col, gridRow: 2 } : undefined}
        >
            {rotulo}
            <strong className="block whitespace-nowrap text-[13px] tabular-nums text-white">{valor}</strong>
        </div>
    );
}

export function Painel({ titulo, sub, acoes, children, className, semPadding = false }) {
    return (
        <section className={cn('min-w-0 rounded-xl border border-white/[0.08] bg-ecf-card', className)}>
            {(titulo || acoes) && (
                <div className={cn('flex flex-wrap items-start justify-between gap-3', semPadding ? 'px-5 pt-4 pb-3' : 'px-5 pt-4')}>
                    <div className="min-w-0">
                        {titulo && <h2 className="text-[15px] font-semibold text-white">{titulo}</h2>}
                        {sub && <p className="mt-0.5 text-[12.5px] text-white/45">{sub}</p>}
                    </div>
                    {acoes}
                </div>
            )}
            <div className={semPadding ? '' : 'px-5 pb-5'}>{children}</div>
        </section>
    );
}

export function Vazio({ titulo, children }) {
    return (
        <div className="px-5 py-10 text-center text-sm text-white/50">
            <b className="mb-1 block text-[15px] text-white">{titulo}</b>
            {children}
        </div>
    );
}

export const classeBotao = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-white/[0.12] bg-white/[0.03] px-3 py-1.5 text-[13px] font-semibold text-white transition hover:border-white/30 disabled:cursor-default disabled:opacity-50';
export const classeBotaoPrimario = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-ecf-yellow px-3 py-1.5 text-[13px] font-semibold text-black transition hover:brightness-105 disabled:cursor-default disabled:opacity-50';
export const classeCampo = 'w-full min-w-0 rounded-md border border-white/[0.12] bg-black/30 px-2 py-1.5 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none';
export const classeTabela = 'w-full border-separate border-spacing-0 text-[13px]';
export const classeTh = 'sticky top-0 z-[1] whitespace-nowrap border-b border-white/[0.08] bg-[#14171e] px-2.5 py-2 text-left text-[10.5px] font-semibold uppercase tracking-wider text-white/45';
export const classeTd = 'border-b border-white/[0.05] px-2.5 py-2 align-middle';

/**
 * Campo numérico que só grava ao sair (blur) ou com Enter — como o "change" do HTML.
 * `aoGravar(valor | null)`; vazio = null. Destacado quando tem valor fixado.
 */
export function CampoNumero({ valor, aoGravar, placeholder, min = 0, step = 1, className, destaque = false, ...resto }) {
    const [texto, setTexto] = useState(valor ?? '');
    useEffect(() => { setTexto(valor ?? ''); }, [valor]);

    // Lê o valor do próprio campo (não do estado): blur logo depois da última tecla ainda
    // não teria o re-render com o texto novo.
    const gravar = (atual) => {
        const t = String(atual).trim().replace(',', '.');
        const novo = t === '' ? null : Number(t);
        if (novo !== null && !Number.isFinite(novo)) {
            setTexto(valor ?? '');
            return;
        }
        if ((novo ?? null) === (valor === '' || valor == null ? null : Number(valor))) return;
        // aoGravar devolve false quando recusa o valor (ex.: abaixo do mínimo): volta ao anterior.
        if (aoGravar(novo) === false) setTexto(valor ?? '');
    };

    return (
        <input
            type="number"
            inputMode="decimal"
            min={min}
            step={step}
            value={texto}
            placeholder={placeholder}
            onChange={(e) => setTexto(e.target.value)}
            onBlur={(e) => gravar(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
            className={cn(classeCampo, 'text-right tabular-nums', destaque && 'border-ecf-yellow/60 bg-ecf-yellow/10 font-bold', className)}
            {...resto}
        />
    );
}

/** Campo de texto que grava ao sair. */
export function CampoTexto({ valor, aoGravar, className, ...resto }) {
    const [texto, setTexto] = useState(valor ?? '');
    useEffect(() => { setTexto(valor ?? ''); }, [valor]);

    return (
        <input
            type="text"
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            onBlur={(e) => { if (e.target.value !== (valor ?? '')) aoGravar(e.target.value); }}
            onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
            className={cn(classeCampo, className)}
            {...resto}
        />
    );
}

/** Aviso flutuante no rodapé (o equivalente ao toast do HTML). */
export function Aviso({ mensagem, tipo = 'info', aoFechar }) {
    useEffect(() => {
        if (!mensagem) return undefined;
        const t = setTimeout(aoFechar, 5000);
        return () => clearTimeout(t);
    }, [mensagem, aoFechar]);

    if (!mensagem) return null;

    return (
        <div
            role="status"
            className={cn(
                'fixed bottom-5 left-1/2 z-[60] max-w-[calc(100%-32px)] -translate-x-1/2 rounded-lg px-4 py-2.5 text-[13px] font-medium shadow-xl',
                tipo === 'erro' ? 'bg-red-600 text-white' : 'bg-white text-black',
            )}
        >
            {mensagem}
        </div>
    );
}
