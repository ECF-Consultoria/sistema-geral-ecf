import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2, Lock, Search } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { compararCodigo, PRIORIDADE_LABELS, STATUS_LABELS } from '@/lib/demandasDev';
import { Campo, inputClasse, STATUS_COR } from './Selos';

// Casca comum dos três formulários: diálogo escuro com rolagem própria.
function Janela({ open, onOpenChange, titulo, descricao, children, largura = 'max-w-xl' }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn('max-h-[92vh] gap-0 overflow-hidden border-white/[0.08] bg-ecf-card p-0 text-white', largura)}
                // Foco inicial no campo marcado com data-autofocus (o Radix focaria o 1º botão).
                onOpenAutoFocus={(e) => {
                    const alvo = e.currentTarget.querySelector('[data-autofocus]');
                    if (alvo) {
                        e.preventDefault();
                        alvo.focus();
                    }
                }}
            >
                <div className="border-b border-white/[0.06] px-6 pb-4 pt-5 pr-12">
                    <DialogTitle className="text-[16px] font-semibold text-white">{titulo}</DialogTitle>
                    {descricao && <DialogDescription className="mt-1 text-[12.5px] text-white/50">{descricao}</DialogDescription>}
                </div>
                {children}
            </DialogContent>
        </Dialog>
    );
}

function Rodape({ processing, onCancelar, rotulo }) {
    return (
        <div className="flex items-center justify-end gap-2 border-t border-white/[0.06] px-6 py-4">
            <button type="button" onClick={onCancelar} className="rounded-lg px-3.5 py-2 text-[13px] text-white/60 hover:bg-white/[0.04] hover:text-white">
                Cancelar
            </button>
            <button
                type="submit"
                disabled={processing}
                className="inline-flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2 disabled:opacity-60"
            >
                {processing && <Loader2 size={14} className="animate-spin" />}
                {rotulo}
            </button>
        </div>
    );
}

// ═══ Registrar atualização — uma linha nova no diário ═══════════════════════
export function AtualizacaoDialog({ demanda, hoje, onClose }) {
    const form = useForm({
        data:              hoje,
        // Começa no status atual e com a próxima ação vigente: o dev só muda o que andou.
        status:            demanda.status,
        feito:             '',
        proxima_acao:      demanda.proxima_acao ?? '',
        bloqueado:         demanda.bloqueado,
        motivo_bloqueio:   demanda.motivo_bloqueio ?? '',
        previsao_revisada: '',
    });
    const { data, setData, errors, processing } = form;

    const enviar = (e) => {
        e.preventDefault();
        form.post(route('dev.demandas.atualizacoes.store', demanda.id), {
            preserveScroll: true,
            preserveState:  true,
            onSuccess:      onClose,
        });
    };

    return (
        <Janela
            open
            onOpenChange={(v) => !v && onClose()}
            titulo={`Atualizar ${demanda.codigo}`}
            descricao={demanda.titulo}
        >
            <form onSubmit={enviar} className="flex min-h-0 flex-col">
                <div className="max-h-[68vh] space-y-4 overflow-y-auto px-6 py-5">
                    <Campo label="Status no fim do dia" erro={errors.status}>
                        <div className="flex flex-wrap gap-1.5">
                            {Object.entries(STATUS_LABELS).map(([valor, rotulo]) => (
                                <button
                                    key={valor}
                                    type="button"
                                    onClick={() => setData('status', valor)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12.5px] transition-colors',
                                        data.status === valor
                                            ? 'border-ecf-yellow/60 bg-ecf-yellow/10 text-white'
                                            : 'border-white/[0.08] text-white/60 hover:bg-white/[0.04] hover:text-white',
                                    )}
                                >
                                    <span className={cn('h-1.5 w-1.5 rounded-full', STATUS_COR[valor])} />
                                    {rotulo}
                                </button>
                            ))}
                        </div>
                    </Campo>

                    <Campo label="O que foi feito" erro={errors.feito}>
                        <textarea
                            rows={3}
                            value={data.feito}
                            onChange={(e) => setData('feito', e.target.value)}
                            placeholder="O que andou nesta demanda hoje"
                            className={inputClasse}
                            data-autofocus
                        />
                    </Campo>

                    <Campo label="Próxima ação" erro={errors.proxima_acao} dica="O que vem a seguir — aparece na fila até a próxima atualização.">
                        <input
                            value={data.proxima_acao}
                            onChange={(e) => setData('proxima_acao', e.target.value)}
                            className={inputClasse}
                        />
                    </Campo>

                    <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
                        <label className="flex cursor-pointer items-center gap-2.5">
                            <input
                                type="checkbox"
                                checked={data.bloqueado}
                                onChange={(e) => setData('bloqueado', e.target.checked)}
                                className="h-4 w-4 rounded border-white/20 bg-transparent text-orange-500 focus:ring-orange-500/40"
                            />
                            <span className="text-[13px] text-white/80">Está bloqueada / depende de alguém</span>
                        </label>
                        {data.bloqueado && (
                            <Campo label="Motivo do bloqueio / dependência" erro={errors.motivo_bloqueio} className="mt-3">
                                <input
                                    value={data.motivo_bloqueio}
                                    onChange={(e) => setData('motivo_bloqueio', e.target.value)}
                                    placeholder="Ex.: aguardando validação do Erlon"
                                    className={inputClasse}
                                    autoFocus
                                />
                            </Campo>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <Campo label="Data" erro={errors.data}>
                            <input type="date" value={data.data} onChange={(e) => setData('data', e.target.value)} className={inputClasse} />
                        </Campo>
                        <Campo label="Previsão revisada" erro={errors.previsao_revisada} dica="Opcional">
                            <input type="date" value={data.previsao_revisada} onChange={(e) => setData('previsao_revisada', e.target.value)} className={inputClasse} />
                        </Campo>
                    </div>

                    <p className="flex items-start gap-2 text-[11.5px] leading-relaxed text-white/40">
                        <Lock size={13} className="mt-0.5 shrink-0" />
                        Atualização registrada não se edita nem se apaga — o histórico é o valor. Errou? Registre outra corrigindo.
                    </p>
                </div>
                <Rodape processing={processing} onCancelar={onClose} rotulo="Registrar atualização" />
            </form>
        </Janela>
    );
}

// ═══ Cadastrar / editar demanda (admin) ═══════════════════════════════════════
export function DemandaDialog({ demanda = null, usuarios, areas, prefixos, hoje, onClose }) {
    const editando = !!demanda;
    const form = useForm({
        ...(editando ? {} : { prefixo: prefixos[0] ?? 'DEV' }),
        titulo:         demanda?.titulo ?? '',
        area:           demanda?.area ?? '',
        escopo:         demanda?.escopo ?? '',
        responsavel_id: demanda?.responsavel?.id ? String(demanda.responsavel.id) : '',
        prioridade:     String(demanda?.prioridade ?? 2),
        data_entrada:   demanda?.data_entrada ?? hoje,
        prazo:          demanda?.prazo ?? '',
        observacoes:    demanda?.observacoes ?? '',
    });
    const { data, setData, errors, processing } = form;

    form.transform((d) => ({
        ...d,
        responsavel_id: d.responsavel_id ? Number(d.responsavel_id) : null,
        prioridade:     Number(d.prioridade),
        prazo:          d.prazo || null,
    }));

    const enviar = (e) => {
        e.preventDefault();
        const opcoes = { preserveScroll: true, preserveState: true, onSuccess: onClose };
        if (editando) form.put(route('dev.demandas.update', demanda.id), opcoes);
        else form.post(route('dev.demandas.store'), opcoes);
    };

    return (
        <Janela
            open
            onOpenChange={(v) => !v && onClose()}
            titulo={editando ? `Editar ${demanda.codigo}` : 'Nova demanda'}
            descricao={editando ? 'Status e próxima ação não se editam aqui — vêm das atualizações.' : 'Uma demanda ocupa uma linha para sempre; o andamento vai nas atualizações.'}
            largura="max-w-2xl"
        >
            <form onSubmit={enviar} className="flex min-h-0 flex-col">
                <div className="max-h-[68vh] space-y-4 overflow-y-auto px-6 py-5">
                    <div className="grid grid-cols-[110px_1fr] gap-3">
                        {editando ? (
                            <Campo label="Código">
                                <div className="rounded-lg border border-white/[0.06] px-3 py-2 font-mono text-[13px] text-white/60">{demanda.codigo}</div>
                            </Campo>
                        ) : (
                            <Campo label="Prefixo" erro={errors.prefixo}>
                                <select value={data.prefixo} onChange={(e) => setData('prefixo', e.target.value)} className={inputClasse}>
                                    {prefixos.map((p) => <option key={p} value={p}>{p}</option>)}
                                </select>
                            </Campo>
                        )}
                        <Campo label="Demanda" erro={errors.titulo}>
                            <input value={data.titulo} onChange={(e) => setData('titulo', e.target.value)} className={inputClasse} data-autofocus />
                        </Campo>
                    </div>

                    <Campo label="Escopo / critério de conclusão" erro={errors.escopo} dica='Termine com "Concluída quando..." — é o que define o aceite.'>
                        <textarea rows={4} value={data.escopo} onChange={(e) => setData('escopo', e.target.value)} className={inputClasse} />
                    </Campo>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <Campo label="Responsável" erro={errors.responsavel_id}>
                            <select value={data.responsavel_id} onChange={(e) => setData('responsavel_id', e.target.value)} className={inputClasse}>
                                <option value="">Sem responsável</option>
                                {usuarios.map((u) => <option key={u.id} value={String(u.id)}>{u.name}</option>)}
                            </select>
                        </Campo>
                        <Campo label="Prioridade" erro={errors.prioridade}>
                            <select value={data.prioridade} onChange={(e) => setData('prioridade', e.target.value)} className={inputClasse}>
                                {Object.entries(PRIORIDADE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </Campo>
                        <Campo label="Área / projeto" erro={errors.area}>
                            <input list="demandas-dev-areas" value={data.area} onChange={(e) => setData('area', e.target.value)} className={inputClasse} />
                            <datalist id="demandas-dev-areas">
                                {areas.map((a) => <option key={a} value={a} />)}
                            </datalist>
                        </Campo>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <Campo label="Data de entrada" erro={errors.data_entrada}>
                            <input type="date" value={data.data_entrada} onChange={(e) => setData('data_entrada', e.target.value)} className={inputClasse} />
                        </Campo>
                        <Campo label="Prazo" erro={errors.prazo} dica="Vazio = sem prazo definido">
                            <input type="date" value={data.prazo} onChange={(e) => setData('prazo', e.target.value)} className={inputClasse} />
                        </Campo>
                    </div>

                    <Campo label="Observações / link" erro={errors.observacoes}>
                        <textarea rows={2} value={data.observacoes} onChange={(e) => setData('observacoes', e.target.value)} className={inputClasse} />
                    </Campo>
                </div>
                <Rodape processing={processing} onCancelar={onClose} rotulo={editando ? 'Salvar' : 'Cadastrar demanda'} />
            </form>
        </Janela>
    );
}

// ═══ Registrar / editar reunião (admin) ═══════════════════════════════════════
export function ReuniaoDialog({ reuniao = null, demandas, hoje, onClose }) {
    const editando = !!reuniao;
    const form = useForm({
        data:             reuniao?.data ?? hoje,
        titulo:           reuniao?.titulo ?? '',
        participantes:    reuniao?.participantes ?? '',
        duracao:          reuniao?.duracao ?? '',
        link_gravacao:    reuniao?.link_gravacao ?? '',
        link_transcricao: reuniao?.link_transcricao ?? '',
        decisoes:         reuniao?.decisoes ?? '',
        demandas:         reuniao?.demandas?.map((d) => d.id) ?? [],
    });
    const { data, setData, errors, processing } = form;
    const [busca, setBusca] = useState('');

    const opcoes = useMemo(() => {
        const t = busca.trim().toLowerCase();
        return [...demandas]
            .sort((a, b) => compararCodigo(a.codigo, b.codigo))
            .filter((d) => !t || d.codigo.toLowerCase().includes(t) || d.titulo.toLowerCase().includes(t));
    }, [demandas, busca]);

    const alternar = (id) =>
        setData('demandas', data.demandas.includes(id) ? data.demandas.filter((x) => x !== id) : [...data.demandas, id]);

    const enviar = (e) => {
        e.preventDefault();
        const o = { preserveScroll: true, preserveState: true, onSuccess: onClose };
        if (editando) form.put(route('dev.demandas.reunioes.update', reuniao.id), o);
        else form.post(route('dev.demandas.reunioes.store'), o);
    };

    return (
        <Janela
            open
            onOpenChange={(v) => !v && onClose()}
            titulo={editando ? 'Editar reunião' : 'Registrar reunião'}
            descricao="Registre no mesmo dia, com os links e as demandas que a reunião criou ou mudou."
            largura="max-w-2xl"
        >
            <form onSubmit={enviar} className="flex min-h-0 flex-col">
                <div className="max-h-[68vh] space-y-4 overflow-y-auto px-6 py-5">
                    <div className="grid grid-cols-[150px_1fr] gap-3">
                        <Campo label="Data" erro={errors.data}>
                            <input type="date" value={data.data} onChange={(e) => setData('data', e.target.value)} className={inputClasse} />
                        </Campo>
                        <Campo label="Reunião / pauta" erro={errors.titulo}>
                            <input value={data.titulo} onChange={(e) => setData('titulo', e.target.value)} className={inputClasse} data-autofocus />
                        </Campo>
                    </div>
                    <div className="grid grid-cols-[1fr_120px] gap-3">
                        <Campo label="Participantes" erro={errors.participantes}>
                            <input value={data.participantes} onChange={(e) => setData('participantes', e.target.value)} className={inputClasse} />
                        </Campo>
                        <Campo label="Duração" erro={errors.duracao}>
                            <input value={data.duracao} onChange={(e) => setData('duracao', e.target.value)} placeholder="1h10" className={inputClasse} />
                        </Campo>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Campo label="Link da gravação" erro={errors.link_gravacao}>
                            <input type="url" value={data.link_gravacao} onChange={(e) => setData('link_gravacao', e.target.value)} placeholder="https://" className={inputClasse} />
                        </Campo>
                        <Campo label="Link da transcrição" erro={errors.link_transcricao}>
                            <input type="url" value={data.link_transcricao} onChange={(e) => setData('link_transcricao', e.target.value)} placeholder="https://" className={inputClasse} />
                        </Campo>
                    </div>
                    <Campo label="Principais decisões / combinados" erro={errors.decisoes}>
                        <textarea rows={4} value={data.decisoes} onChange={(e) => setData('decisoes', e.target.value)} className={inputClasse} />
                    </Campo>

                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between">
                            <span className="text-[12px] font-medium text-white/60">Demandas relacionadas</span>
                            <span className="text-[11.5px] text-white/40">{data.demandas.length} selecionada{data.demandas.length === 1 ? '' : 's'}</span>
                        </div>
                        <div className="relative">
                            <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                            <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Filtrar por código ou título" className={cn(inputClasse, 'pl-8')} />
                        </div>
                        <div className="max-h-48 overflow-y-auto rounded-lg border border-white/[0.06] divide-y divide-white/[0.04]">
                            {opcoes.map((d) => (
                                <label key={d.id} className="flex cursor-pointer items-center gap-2.5 px-3 py-1.5 hover:bg-white/[0.03]">
                                    <input
                                        type="checkbox"
                                        checked={data.demandas.includes(d.id)}
                                        onChange={() => alternar(d.id)}
                                        className="h-3.5 w-3.5 rounded border-white/20 bg-transparent text-ecf-yellow focus:ring-ecf-yellow/40"
                                    />
                                    <span className="w-16 shrink-0 font-mono text-[11.5px] text-white/50">{d.codigo}</span>
                                    <span className="truncate text-[12.5px] text-white/80">{d.titulo}</span>
                                </label>
                            ))}
                        </div>
                        {errors.demandas && <span className="block text-[11.5px] text-red-400">{errors.demandas}</span>}
                    </div>
                </div>
                <Rodape processing={processing} onCancelar={onClose} rotulo={editando ? 'Salvar' : 'Registrar reunião'} />
            </form>
        </Janela>
    );
}
