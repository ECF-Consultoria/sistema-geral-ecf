import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { FileText, Image as ImageIcon, Loader2, Lock, Paperclip, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtDataHora, fmtTamanho, STATUS_CHAMADO, STATUS_PARA_SOLICITANTE } from '@/lib/chamados';

// Mapas de classe AQUI (não em lib/*.js): o Tailwind só varre .jsx.
const STATUS_CLASSE = {
    aberto:                 'bg-sky-500/10 text-sky-400',
    em_triagem:             'bg-violet-500/10 text-violet-400',
    em_atendimento:         'bg-amber-500/10 text-amber-400',
    aguardando_solicitante: 'bg-orange-500/10 text-orange-400',
    resolvido:              'bg-emerald-500/10 text-emerald-400',
    cancelado:              'bg-white/[0.04] text-white/50',
};

export function StatusChamado({ status, paraSolicitante = false, className }) {
    const rotulos = paraSolicitante ? STATUS_PARA_SOLICITANTE : STATUS_CHAMADO;
    return (
        <span className={cn('inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-medium', STATUS_CLASSE[status], className)}>
            {rotulos[status] ?? status}
        </span>
    );
}

export function ListaAnexos({ anexos, className }) {
    if (!anexos?.length) return null;
    return (
        <ul className={cn('flex flex-wrap gap-2', className)}>
            {anexos.map((a) => (
                <li key={a.id}>
                    <a
                        href={a.url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex max-w-[260px] items-center gap-2 rounded-lg border border-white/[0.08] bg-white/[0.02] px-2.5 py-1.5 text-[12.5px] text-white/80 hover:border-white/10 hover:text-ecf-yellow"
                    >
                        {a.imagem ? <ImageIcon size={14} className="shrink-0 text-white/40" /> : <FileText size={14} className="shrink-0 text-white/40" />}
                        <span className="truncate">{a.nome}</span>
                        <span className="shrink-0 text-[11px] text-white/30">{fmtTamanho(a.tamanho)}</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

const ACEITOS = '.jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.log,.xlsx,.docx';
const MAX_ARQUIVOS = 5;

/** Escolha de arquivos: lista o que vai junto e deixa tirar antes de enviar. */
export function CampoArquivos({ arquivos, onChange, erro }) {
    const input = useRef(null);
    const acrescentar = (lista) => onChange([...arquivos, ...Array.from(lista)].slice(0, MAX_ARQUIVOS));

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    disabled={arquivos.length >= MAX_ARQUIVOS}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-white/10 px-3 py-1.5 text-[12.5px] text-white/60 hover:border-white/20 hover:text-white disabled:opacity-40"
                >
                    <Paperclip size={14} /> Anexar arquivo
                </button>
                <span className="text-[11.5px] text-white/40">Print, PDF, planilha — até {MAX_ARQUIVOS} arquivos de 10 MB.</span>
                <input
                    ref={input}
                    type="file"
                    multiple
                    accept={ACEITOS}
                    className="hidden"
                    onChange={(e) => { acrescentar(e.target.files); e.target.value = ''; }}
                />
            </div>
            {arquivos.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {arquivos.map((f, i) => (
                        <li key={`${f.name}-${i}`} className="inline-flex max-w-[260px] items-center gap-2 rounded-lg bg-white/[0.04] px-2.5 py-1 text-[12.5px] text-white/80">
                            <span className="truncate">{f.name}</span>
                            <span className="shrink-0 text-[11px] text-white/30">{fmtTamanho(f.size)}</span>
                            <button type="button" onClick={() => onChange(arquivos.filter((_, j) => j !== i))} aria-label={`Tirar ${f.name}`} className="text-white/40 hover:text-white">
                                <X size={13} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {erro && <p className="text-[11.5px] text-red-400">{erro}</p>}
        </div>
    );
}

// Frase de cada evento do histórico. Motivo de transferência só chega para a equipe.
const frase = (e, rotulos) => ({
    criado:                `${e.ator} abriu o ticket`,
    atribuido:             e.ator === e.para ? `${e.ator} assumiu o ticket` : `Ticket direcionado para ${e.para}`,
    transferido:           `${e.ator} transferiu de ${e.de} para ${e.para}`,
    status:                `${e.ator} mudou o status para ${rotulos[e.para] ?? e.para}`,
    convertido_em_demanda: `${e.ator} criou a demanda ${e.para}`,
    resolvido:             `${e.ator} resolveu o ticket`,
    reaberto:              `${e.ator} reabriu o ticket`,
    cancelado:             `${e.ator} cancelou o ticket`,
}[e.tipo] ?? e.tipo);

/**
 * Conversa + histórico em ordem. A tela só desenha: o que o solicitante não pode
 * ver (nota interna, demanda, motivo) nem chega no payload dele.
 */
export function LinhaDoTempo({ itens, paraSolicitante = false }) {
    const rotulos = paraSolicitante ? STATUS_PARA_SOLICITANTE : STATUS_CHAMADO;

    return (
        <ol className="space-y-3">
            {itens.map((i) => i.item === 'evento' ? (
                <li key={`e${i.id}`} className="flex items-baseline gap-2 pl-1 text-[12px] text-white/40">
                    <span className="h-1 w-1 shrink-0 translate-y-[-2px] rounded-full bg-white/25" />
                    <span>
                        {frase(i, rotulos)}
                        {i.motivo && <span className="text-white/50"> — “{i.motivo}”</span>}
                        <span className="ml-1.5 text-white/30">{fmtDataHora(i.em)}</span>
                    </span>
                </li>
            ) : (
                <li
                    key={`m${i.id}`}
                    className={cn(
                        'rounded-xl border px-4 py-3',
                        i.interna
                            ? 'border-amber-500/25 bg-amber-500/[0.05]'
                            : i.da_equipe ? 'border-white/[0.08] bg-white/[0.03]' : 'border-white/[0.06] bg-transparent',
                    )}
                >
                    <div className="flex flex-wrap items-baseline gap-x-2 text-[12.5px]">
                        <span className="font-semibold text-white">{i.autor}</span>
                        {i.interna && <span className="inline-flex items-center gap-1 text-[11.5px] text-amber-400"><Lock size={11} /> Nota interna</span>}
                        <span className="text-white/40">{fmtDataHora(i.em)}</span>
                    </div>
                    <p className="mt-1 whitespace-pre-line break-words text-[13.5px] leading-relaxed text-white/80">{i.texto}</p>
                    <ListaAnexos anexos={i.anexos} className="mt-2" />
                </li>
            ))}
        </ol>
    );
}

/**
 * Caixa de resposta. Para a equipe, alterna entre responder a quem abriu e
 * nota interna — o servidor confere de novo quem pode escrever nota interna.
 */
export function CaixaDeResposta({ chamadoId, podeInterna = false, placeholder = 'Escreva sua mensagem…' }) {
    const form = useForm({ texto: '', interna: false, anexos: [] });
    const { data, setData, errors, processing } = form;

    const enviar = (e) => {
        e.preventDefault();
        form.post(route('chamados.mensagens.store', chamadoId), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={enviar} className={cn('space-y-2.5 rounded-xl border p-3', data.interna ? 'border-amber-500/30 bg-amber-500/[0.03]' : 'border-white/[0.08]')}>
            {podeInterna && (
                <div className="flex gap-1 text-[12.5px]" role="radiogroup" aria-label="Tipo de mensagem">
                    {[[false, 'Responder ao solicitante'], [true, 'Nota interna']].map(([v, l]) => (
                        <button
                            key={l}
                            type="button"
                            role="radio"
                            aria-checked={data.interna === v}
                            onClick={() => setData('interna', v)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 transition-colors',
                                data.interna === v
                                    ? (v ? 'bg-amber-500/15 text-amber-300' : 'bg-white/[0.06] text-white')
                                    : 'text-white/50 hover:text-white',
                            )}
                        >
                            {v && <Lock size={12} />} {l}
                        </button>
                    ))}
                </div>
            )}
            <textarea
                rows={3}
                value={data.texto}
                onChange={(e) => setData('texto', e.target.value)}
                placeholder={data.interna ? 'Só a equipe dev vê esta nota.' : placeholder}
                className="w-full resize-y rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13.5px] text-white placeholder:text-white/30 focus:border-ecf-yellow/50 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/30"
            />
            {errors.texto && <p className="text-[11.5px] text-red-400">{errors.texto}</p>}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <CampoArquivos arquivos={data.anexos} onChange={(v) => setData('anexos', v)} erro={errors.anexos || Object.entries(errors).find(([k]) => k.startsWith('anexos.'))?.[1]} />
                <button
                    type="submit"
                    disabled={processing || !data.texto.trim()}
                    className="ml-auto inline-flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2 disabled:opacity-50"
                >
                    {processing && <Loader2 size={14} className="animate-spin" />}
                    {data.interna ? 'Salvar nota interna' : 'Enviar'}
                </button>
            </div>
        </form>
    );
}
