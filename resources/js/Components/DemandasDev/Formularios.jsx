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

function Rodape({ processing, bloqueado = false, onCancelar, rotulo }) {
    return (
        <div className="flex items-center justify-end gap-2 border-t border-white/[0.06] px-6 py-4">
            <button type="button" onClick={onCancelar} className="rounded-lg px-3.5 py-2 text-[13px] text-white/60 hover:bg-white/[0.04] hover:text-white">
                Cancelar
            </button>
            <button
                type="submit"
                disabled={processing || bloqueado}
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

// ═══ Agendar / editar reunião dev (admin) ═════════════════════════════════════
//
// Agendar = evento no Google Agenda de quem agenda, com sala do Meet e convite
// para os participantes. Sem convite = registrar uma reunião que já aconteceu.
// Depois da reunião entram os links (gravação, transcrição, anotações do Gemini).

const DURACOES = [15, 30, 45, 60, 90, 120];

export function ReuniaoDialog({ reuniao = null, preset = null, demandas, usuarios, areas, google, hoje, onClose }) {
    const editando = !!reuniao;
    const form = useForm({
        convite:          editando ? reuniao.com_convite : true,
        titulo:           reuniao?.titulo ?? preset?.titulo ?? '',
        modulo:           reuniao?.modulo ?? preset?.modulo ?? '',
        pauta:            reuniao?.pauta ?? '',
        data:             reuniao?.data ?? hoje,
        hora:             reuniao?.hora ?? '',
        duracao:          String(reuniao?.duracao_min ?? 60),
        participantes:    reuniao?.participantes_usuarios?.map((u) => u.id) ?? preset?.participantes ?? [],
        demandas:         reuniao?.demandas?.map((d) => d.id) ?? preset?.demandas ?? [],
        decisoes:         reuniao?.decisoes ?? '',
        link_gravacao:    reuniao?.link_gravacao ?? '',
        link_transcricao: reuniao?.link_transcricao ?? '',
        link_resumo:      reuniao?.link_resumo ?? '',
    });
    const { data, setData, errors, processing } = form;

    form.transform((d) => ({ ...d, duracao: Number(d.duracao), hora: d.hora || null }));

    const comConvite = editando ? reuniao.com_convite && reuniao.status !== 'cancelada' : data.convite;
    const semGoogle = !editando && data.convite && !google.conectado;
    // Os links só fazem sentido depois da reunião (ou ao editar uma).
    const mostrarDepois = editando || !data.convite;

    // Escolher uma demanda traz o responsável dela como participante.
    const alternarDemanda = (id) => {
        if (data.demandas.includes(id)) {
            setData('demandas', data.demandas.filter((x) => x !== id));
            return;
        }
        const resp = demandas.find((d) => d.id === id)?.responsavel?.id;
        form.setData((atual) => ({
            ...atual,
            demandas: [...atual.demandas, id],
            participantes: resp && !atual.participantes.includes(resp) ? [...atual.participantes, resp] : atual.participantes,
        }));
    };
    const alternarPessoa = (id) =>
        setData('participantes', data.participantes.includes(id) ? data.participantes.filter((x) => x !== id) : [...data.participantes, id]);

    const enviar = (e) => {
        e.preventDefault();
        const o = { preserveScroll: true, preserveState: true, onSuccess: (p) => !p.props.flash?.error && onClose() };
        if (editando) form.put(route('dev.demandas.reunioes.update', reuniao.id), o);
        else form.post(route('dev.demandas.reunioes.store'), o);
    };

    const rotulo = editando ? 'Salvar' : data.convite ? 'Agendar e enviar convite' : 'Registrar reunião';

    return (
        <Janela
            open
            onOpenChange={(v) => !v && onClose()}
            titulo={editando ? 'Editar reunião' : 'Agendar reunião dev'}
            descricao={comConvite
                ? 'O convite sai da sua agenda do Google, com sala do Meet. Mudanças de data, horário e participantes avisam os convidados.'
                : 'Sem convite: só fica registrada aqui.'}
            largura="max-w-2xl"
        >
            <form onSubmit={enviar} className="flex min-h-0 flex-col">
                <div className="max-h-[70vh] space-y-5 overflow-y-auto px-6 py-5">
                    {!editando && (
                        <div className="flex rounded-lg border border-white/[0.08] p-0.5 text-[12.5px]" role="radiogroup">
                            {[[true, 'Agendar com convite do Google'], [false, 'Registrar reunião que já aconteceu']].map(([v, l]) => (
                                <button
                                    key={l}
                                    type="button"
                                    role="radio"
                                    aria-checked={data.convite === v}
                                    onClick={() => setData('convite', v)}
                                    className={cn('flex-1 rounded-md px-3 py-1.5 transition-colors', data.convite === v ? 'bg-white/[0.05] text-white' : 'text-white/50 hover:text-white')}
                                >
                                    {l}
                                </button>
                            ))}
                        </div>
                    )}

                    {semGoogle && (
                        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-4 py-3 text-[12.5px] text-amber-300">
                            <span className="flex-1">Sua conta Google não está conectada — sem ela o convite não sai.</span>
                            <a href={google.conectar_url} className="rounded-md bg-amber-400/15 px-3 py-1.5 font-medium text-amber-200 hover:bg-amber-400/25">Conectar Google</a>
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-[1fr_200px]">
                        <Campo label="Assunto" erro={errors.titulo}>
                            <input value={data.titulo} onChange={(e) => setData('titulo', e.target.value)} placeholder="Ex.: Alinhamento da Entrada" className={inputClasse} data-autofocus />
                        </Campo>
                        <Campo label="Módulo" erro={errors.modulo}>
                            <input list="demandas-dev-areas-reuniao" value={data.modulo} onChange={(e) => setData('modulo', e.target.value)} className={inputClasse} />
                            <datalist id="demandas-dev-areas-reuniao">
                                {areas.map((a) => <option key={a} value={a} />)}
                            </datalist>
                        </Campo>
                    </div>

                    <div className="grid grid-cols-[1fr_110px_120px] gap-3">
                        <Campo label="Dia" erro={errors.data}>
                            <input type="date" value={data.data} onChange={(e) => setData('data', e.target.value)} className={inputClasse} />
                        </Campo>
                        <Campo label="Horário" erro={errors.hora}>
                            <input type="time" value={data.hora} onChange={(e) => setData('hora', e.target.value)} className={inputClasse} />
                        </Campo>
                        <Campo label="Duração" erro={errors.duracao}>
                            <select value={data.duracao} onChange={(e) => setData('duracao', e.target.value)} className={inputClasse}>
                                {DURACOES.map((m) => <option key={m} value={String(m)}>{m < 60 ? `${m} min` : `${m / 60}h`}</option>)}
                            </select>
                        </Campo>
                    </div>

                    <Seletor
                        titulo="Demandas em pauta"
                        dica="Quem é responsável entra como participante."
                        opcoes={[...demandas].sort((a, b) => compararCodigo(a.codigo, b.codigo)).map((d) => ({ id: d.id, rotulo: d.titulo, prefixo: d.codigo, encerrada: d.encerrada }))}
                        selecionados={data.demandas}
                        onAlternar={alternarDemanda}
                        erro={errors.demandas}
                        busca="Filtrar por código ou título"
                    />

                    <Seletor
                        titulo="Participantes"
                        dica={comConvite ? 'Cada um recebe o convite no e-mail do cadastro.' : null}
                        opcoes={usuarios.map((u) => ({ id: u.id, rotulo: u.name }))}
                        selecionados={data.participantes}
                        onAlternar={alternarPessoa}
                        erro={errors.participantes}
                        busca="Buscar pessoa"
                    />

                    <Campo label="Pauta" erro={errors.pauta} dica={comConvite ? 'Vai na descrição do convite, junto com a lista das demandas.' : null}>
                        <textarea rows={3} value={data.pauta} onChange={(e) => setData('pauta', e.target.value)} className={inputClasse} />
                    </Campo>

                    {mostrarDepois && (
                        <fieldset className="space-y-3 border-t border-white/[0.06] pt-4">
                            <legend className="sr-only">Depois da reunião</legend>
                            <p className="text-[13px] font-medium text-white/80">Depois da reunião</p>
                            {comConvite && (
                                <p className="text-[12px] text-white/40">
                                    Quando a reunião é gravada no Meet, o sistema busca sozinho os links nos anexos do convite (a cada 30 min). Colar aqui também vale.
                                </p>
                            )}
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Campo label="Gravação" erro={errors.link_gravacao}>
                                    <input type="url" value={data.link_gravacao} onChange={(e) => setData('link_gravacao', e.target.value)} placeholder="https://" className={inputClasse} />
                                </Campo>
                                <Campo label="Transcrição" erro={errors.link_transcricao}>
                                    <input type="url" value={data.link_transcricao} onChange={(e) => setData('link_transcricao', e.target.value)} placeholder="https://" className={inputClasse} />
                                </Campo>
                                <Campo label="Anotações do Gemini" erro={errors.link_resumo}>
                                    <input type="url" value={data.link_resumo} onChange={(e) => setData('link_resumo', e.target.value)} placeholder="https://" className={inputClasse} />
                                </Campo>
                            </div>
                            <Campo label="Decisões e combinados" erro={errors.decisoes}>
                                <textarea rows={3} value={data.decisoes} onChange={(e) => setData('decisoes', e.target.value)} className={inputClasse} />
                            </Campo>
                        </fieldset>
                    )}
                </div>
                <Rodape processing={processing} bloqueado={semGoogle} onCancelar={onClose} rotulo={rotulo} />
            </form>
        </Janela>
    );
}

// Lista com busca e marcação múltipla; o que está marcado aparece em fichas acima.
function Seletor({ titulo, dica, opcoes, selecionados, onAlternar, erro, busca: placeholder }) {
    const [busca, setBusca] = useState('');
    const t = busca.trim().toLowerCase();
    const filtradas = opcoes.filter((o) => !t || o.rotulo.toLowerCase().includes(t) || (o.prefixo ?? '').toLowerCase().includes(t));
    const marcadas = opcoes.filter((o) => selecionados.includes(o.id));

    return (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-[12px] font-medium text-white/60">{titulo}</span>
                {dica && <span className="text-[11.5px] text-white/40">{dica}</span>}
            </div>
            {marcadas.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {marcadas.map((o) => (
                        <button
                            key={o.id}
                            type="button"
                            onClick={() => onAlternar(o.id)}
                            title="Tirar"
                            className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-ecf-yellow/10 px-2.5 py-1 text-[12px] text-white hover:bg-ecf-yellow/20"
                        >
                            {o.prefixo && <span className="font-semibold text-white/60">{o.prefixo}</span>}
                            <span className="truncate">{o.rotulo}</span>
                            <span aria-hidden className="text-white/40">×</span>
                        </button>
                    ))}
                </div>
            )}
            <div className="relative">
                <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder={placeholder} className={cn(inputClasse, 'pl-8')} />
            </div>
            <div className="max-h-36 overflow-y-auto rounded-lg border border-white/[0.06] divide-y divide-white/[0.04]">
                {filtradas.map((o) => (
                    <label key={o.id} className={cn('flex cursor-pointer items-center gap-2.5 px-3 py-1.5 hover:bg-white/[0.03]', o.encerrada && 'opacity-50')}>
                        <input
                            type="checkbox"
                            checked={selecionados.includes(o.id)}
                            onChange={() => onAlternar(o.id)}
                            className="h-3.5 w-3.5 rounded border-white/20 bg-transparent text-ecf-yellow focus:ring-ecf-yellow/40"
                        />
                        {o.prefixo && <span className="w-14 shrink-0 text-[11.5px] font-semibold text-white/50">{o.prefixo}</span>}
                        <span className="truncate text-[12.5px] text-white/80">{o.rotulo}</span>
                    </label>
                ))}
                {filtradas.length === 0 && <p className="px-3 py-2 text-[12px] text-white/40">Nada encontrado.</p>}
            </div>
            {erro && <span className="block text-[11.5px] text-red-400">{erro}</span>}
        </div>
    );
}
