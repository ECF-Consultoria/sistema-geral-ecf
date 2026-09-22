import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarPlus, Download, FileText, Loader2, Pencil, PlayCircle, Sparkles, Video, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtData } from '@/lib/demandasDev';

// Dia e hora por extenso curto: "qua, 24 set · 14:00–15:00".
const quando = (r) => {
    const [a, m, d] = r.data.split('-').map(Number);
    const dia = new Date(a, m - 1, d).toLocaleDateString('pt-BR', { weekday: 'short', day: 'numeric', month: 'short' }).replace(/\./g, '').replace(/ de /g, ' ');
    return r.hora ? `${dia}, ${r.hora}–${r.fim_hora}` : dia;
};

const LINKS = [
    { campo: 'link_gravacao',    rotulo: 'Gravação',            Icone: PlayCircle },
    { campo: 'link_transcricao', rotulo: 'Transcrição',         Icone: FileText },
    { campo: 'link_resumo',      rotulo: 'Anotações do Gemini', Icone: Sparkles },
];

export default function Reunioes({ reunioes, pode, hoje, onAbrir, onAgendar, onEditar }) {
    const { flash } = usePage().props;
    const [verCanceladas, setVerCanceladas] = useState(false);

    const proximas = reunioes.filter((r) => r.status === 'agendada').sort((a, b) => (a.inicio ?? a.data).localeCompare(b.inicio ?? b.data));
    const realizadas = reunioes.filter((r) => r.status === 'realizada');
    const canceladas = reunioes.filter((r) => r.status === 'cancelada');

    return (
        <div className="space-y-8">
            <div className="flex flex-wrap items-center gap-3">
                <p className="max-w-xl text-[13px] text-white/50">
                    Reunião dev é marcada pelo Google Agenda, com Meet. Gravação, transcrição e as anotações do Gemini voltam para cá depois.
                </p>
                {pode.gerenciar && (
                    <button
                        type="button"
                        onClick={() => onAgendar(null)}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2"
                    >
                        <CalendarPlus size={15} /> Agendar reunião
                    </button>
                )}
            </div>

            {flash?.aviso && (
                <p className="rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-4 py-2.5 text-[12.5px] text-amber-300">{flash.aviso}</p>
            )}

            <section aria-labelledby="reunioes-proximas">
                <h2 id="reunioes-proximas" className="font-display text-[17px] font-semibold text-white">Próximas</h2>
                {proximas.length === 0 ? (
                    <p className="mt-2 text-[13px] text-white/40">Nenhuma reunião marcada.</p>
                ) : (
                    <ul className="mt-3 space-y-2">
                        {proximas.map((r) => <Proxima key={r.id} r={r} pode={pode} hoje={hoje} onAbrir={onAbrir} onEditar={onEditar} />)}
                    </ul>
                )}
            </section>

            <section aria-labelledby="reunioes-realizadas">
                <h2 id="reunioes-realizadas" className="font-display text-[17px] font-semibold text-white">Realizadas</h2>
                {realizadas.length === 0 ? (
                    <p className="mt-2 text-[13px] text-white/40">Nenhuma ainda.</p>
                ) : (
                    <ul className="mt-3 space-y-2">
                        {realizadas.map((r) => <Realizada key={r.id} r={r} pode={pode} hoje={hoje} onAbrir={onAbrir} onEditar={onEditar} />)}
                    </ul>
                )}
            </section>

            {canceladas.length > 0 && (
                <section>
                    <button type="button" onClick={() => setVerCanceladas((v) => !v)} className="text-[12.5px] text-white/40 hover:text-white">
                        {verCanceladas ? 'Esconder' : 'Ver'} {canceladas.length} cancelada{canceladas.length === 1 ? '' : 's'}
                    </button>
                    {verCanceladas && (
                        <ul className="mt-2 space-y-1">
                            {canceladas.map((r) => (
                                <li key={r.id} className="text-[12.5px] text-white/40 line-through decoration-white/20">
                                    {quando(r)} — {r.titulo}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </div>
    );
}

// Cabeçalho comum: data em bloco à esquerda, assunto, módulo, quem participa, demandas.
function Corpo({ r, hoje, onAbrir, children }) {
    const pessoas = r.participantes_usuarios.length
        ? r.participantes_usuarios.map((u) => u.name.split(' ')[0]).join(', ')
        : r.participantes;
    const [, m, d] = r.data.split('-');

    return (
        <div className="grid grid-cols-[52px_minmax(0,1fr)] gap-x-4">
            <div className="rounded-lg bg-white/[0.03] py-1.5 text-center leading-none">
                <div className="font-display text-[20px] font-bold tabular-nums text-white">{d}</div>
                <div className="mt-1 text-[11px] text-white/50">{new Date(2000, Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '')}</div>
            </div>
            <div className="min-w-0">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <h3 className="text-[14.5px] font-semibold text-white">{r.titulo}</h3>
                    {r.modulo && <span className="rounded bg-white/[0.05] px-1.5 py-0.5 text-[11.5px] text-white/60">{r.modulo}</span>}
                </div>
                <p className="mt-0.5 text-[12.5px] text-white/50">
                    {quando(r)}
                    {pessoas && <> — {pessoas}</>}
                </p>
                {r.demandas.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {r.demandas.map((dm) => (
                            <button
                                key={dm.id}
                                type="button"
                                onClick={() => onAbrir(dm.id)}
                                title={dm.titulo}
                                className="rounded bg-white/[0.04] px-1.5 py-0.5 text-[11.5px] font-semibold tabular-nums text-white/60 hover:bg-ecf-yellow/10 hover:text-ecf-yellow"
                            >
                                {dm.codigo}
                            </button>
                        ))}
                    </div>
                )}
                {children}
            </div>
        </div>
    );
}

function Proxima({ r, pode, hoje, onAbrir, onEditar }) {
    const [cancelando, setCancelando] = useState(false);
    const cancelar = () => {
        if (!window.confirm(`Cancelar "${r.titulo}"? ${r.com_convite ? 'O Google avisa os participantes.' : ''}`)) return;
        setCancelando(true);
        router.post(route('dev.demandas.reunioes.cancelar', r.id), {}, { preserveScroll: true, preserveState: true, onFinish: () => setCancelando(false) });
    };

    return (
        <li className="rounded-xl border border-white/[0.08] bg-ecf-card px-4 py-3.5">
            <Corpo r={r} hoje={hoje} onAbrir={onAbrir}>
                {r.pauta && <p className="mt-2 line-clamp-2 whitespace-pre-line text-[12.5px] text-white/60">{r.pauta}</p>}
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {r.meet_link && (
                        <a href={r.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow/10 px-3 py-1.5 text-[12.5px] font-medium text-ecf-yellow hover:bg-ecf-yellow/20">
                            <Video size={14} /> Entrar no Meet
                        </a>
                    )}
                    {!r.com_convite && <span className="text-[12px] text-white/40">Sem convite no Google</span>}
                    {pode.gerenciar && (
                        <span className="ml-auto flex items-center gap-1">
                            <BotaoDiscreto onClick={() => onEditar(r)}><Pencil size={13} /> Editar</BotaoDiscreto>
                            <BotaoDiscreto onClick={cancelar} disabled={cancelando}>
                                {cancelando ? <Loader2 size={13} className="animate-spin" /> : <X size={13} />} Cancelar
                            </BotaoDiscreto>
                        </span>
                    )}
                </div>
            </Corpo>
        </li>
    );
}

function Realizada({ r, pode, hoje, onAbrir, onEditar }) {
    const [buscando, setBuscando] = useState(false);
    const [aberta, setAberta] = useState(false);
    const faltam = LINKS.some((l) => !r[l.campo]);
    const longa = (r.decisoes ?? '').length > 280;

    const buscar = () => {
        setBuscando(true);
        router.post(route('dev.demandas.reunioes.buscar_gravacao', r.id), {}, { preserveScroll: true, preserveState: true, onFinish: () => setBuscando(false) });
    };

    return (
        <li className="rounded-xl border border-white/[0.08] bg-ecf-card px-4 py-3.5">
            <Corpo r={r} hoje={hoje} onAbrir={onAbrir}>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {LINKS.map(({ campo, rotulo, Icone }) => r[campo] ? (
                        <a key={campo} href={r[campo]} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-lg bg-white/[0.04] px-2.5 py-1.5 text-[12.5px] text-white/80 hover:text-ecf-yellow">
                            <Icone size={14} /> {rotulo}
                        </a>
                    ) : (
                        <span key={campo} className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-white/10 px-2.5 py-1.5 text-[12.5px] text-white/30">
                            <Icone size={14} /> {rotulo}
                        </span>
                    ))}
                    {pode.gerenciar && (
                        <span className="ml-auto flex items-center gap-1">
                            {faltam && r.com_convite && (
                                <BotaoDiscreto onClick={buscar} disabled={buscando}>
                                    {buscando ? <Loader2 size={13} className="animate-spin" /> : <Download size={13} />} Buscar no Google
                                </BotaoDiscreto>
                            )}
                            <BotaoDiscreto onClick={() => onEditar(r)}><Pencil size={13} /> {faltam ? 'Colar links' : 'Editar'}</BotaoDiscreto>
                        </span>
                    )}
                </div>

                {r.decisoes && (
                    <div className="mt-3">
                        <p className={cn('whitespace-pre-line text-[13px] leading-relaxed text-white/70', longa && !aberta && 'line-clamp-3')}>{r.decisoes}</p>
                        {longa && (
                            <button type="button" onClick={() => setAberta((v) => !v)} className="mt-1 text-[12px] text-ecf-yellow/80 hover:text-ecf-yellow">
                                {aberta ? 'Mostrar menos' : 'Ler tudo'}
                            </button>
                        )}
                    </div>
                )}
                {r.com_convite && faltam && r.anexos_buscados_em && (
                    <p className="mt-2 text-[11.5px] text-white/30">Última busca no Google em {fmtData(r.anexos_buscados_em.slice(0, 10), hoje)}, {r.anexos_buscados_em.slice(11, 16)}.</p>
                )}
            </Corpo>
        </li>
    );
}

function BotaoDiscreto({ children, ...props }) {
    return (
        <button
            type="button"
            {...props}
            className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[12.5px] text-white/60 hover:bg-white/[0.04] hover:text-white disabled:opacity-50"
        >
            {children}
        </button>
    );
}
