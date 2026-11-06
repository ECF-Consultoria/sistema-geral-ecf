import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { RefreshCw, History, Ban, ExternalLink } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogFooter,
    DialogTitle,
    DialogDescription,
} from '@/Components/ui/dialog';
import { cn, formatDate } from '@/lib/utils';

// Situação comercial da renovação, em português claro.
const COMERCIAL_LABELS = {
    identificado:  { texto: 'Oportunidade a abrir', classe: 'border-sky-500/30 bg-sky-500/10 text-sky-300' },
    em_negociacao: { texto: 'Em negociação', classe: 'border-amber-500/30 bg-amber-500/10 text-amber-300' },
    renovado:      { texto: 'Renovado', classe: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' },
    nao_renovado:  { texto: 'Não renovado', classe: 'border-red-500/30 bg-red-500/10 text-red-300' },
    dispensado:    { texto: 'Dispensado', classe: 'border-white/15 bg-white/[0.05] text-white/60' },
};

// Situação do envio ao HubSpot. 'sincronizado' e 'pendente' não precisam de selo.
const INTEGRACAO_LABELS = {
    erro:              { texto: 'Erro ao enviar ao HubSpot', classe: 'border-red-500/30 bg-red-500/10 text-red-300' },
    revisao_manual:    { texto: 'Precisa de revisão', classe: 'border-amber-500/30 bg-amber-500/10 text-amber-300' },
    resultado_incerto: { texto: 'Aguardando confirmação do HubSpot', classe: 'border-amber-500/30 bg-amber-500/10 text-amber-300' },
    criando:           { texto: 'Criando no HubSpot', classe: 'border-sky-500/30 bg-sky-500/10 text-sky-300' },
};

const DECISOES_LABELS = {
    nao_elegivel:            'não faria nada: ainda não é hora de renovar',
    nada_a_fazer:            'não faria nada',
    conciliar_aberto:        'completaria o vínculo da oportunidade que já existe',
    registrar_ganho:         'registraria a renovação como fechada',
    registrar_perda:         'registraria a renovação como não fechada',
    vincular_por_correlacao: 'vincularia a oportunidade que já existe',
    criar:                   'abriria uma oportunidade de renovação',
    aguardar_conciliacao:    'esperaria a confirmação do HubSpot',
    revisao_manual:          'mandaria para revisão',
    erro_leitura:            'não conseguiu ler o HubSpot',
};

const EVENTO_LABELS = {
    detectado:              'Renovação identificada',
    simulacao:              'Simulação (nada foi enviado)',
    dispensado:             'Dispensada',
    liberacao_manual:       'Criação liberada manualmente',
    negocio_criado:         'Oportunidade criada no HubSpot',
    vinculado_por_correlacao: 'Oportunidade existente vinculada',
    associacao_completada:  'Vínculo com a empresa completado',
    erro_criacao:           'Erro ao criar a oportunidade',
    resultado_incerto:      'HubSpot não confirmou a criação',
    em_negociacao:          'Renovação em negociação',
    nao_renovado:           'Renovação não fechada',
    contrato_estendido:     'Contrato estendido (renovação fechada)',
    termino_corrigido:      'Data de término corrigida',
    ganho_repetido_ignorado: 'Fechamento repetido ignorado',
    ganho_sem_extensao:     'Fechamento sem ajuste do contrato (revisar)',
    etapa_fora_do_pipeline: 'Etapa fora do funil de renovação',
};

const ORIGEM_EVENTO = { manual: 'manual', agendador: 'automático', webhook: 'HubSpot' };

const btnBase = 'inline-flex items-center gap-1 text-[11px] font-medium px-2 h-6 rounded-md border transition-colors';
const btnNormal = 'border-white/15 text-white/70 hover:bg-white/[0.07] disabled:opacity-30 disabled:pointer-events-none';

function Selo({ info, title }) {
    if (!info) return null;
    return (
        <span
            title={title}
            className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', info.classe)}
        >
            {info.texto}
        </span>
    );
}

function LinkNegocio({ url, id, rotulo }) {
    if (!id) return null;
    if (!url) {
        return <span className="text-[11px] text-white/50">{rotulo}: {id}</span>;
    }
    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 text-[11px] text-sky-300 hover:underline"
        >
            {rotulo} <ExternalLink size={10} />
        </a>
    );
}

/**
 * Célula "Renovação" da lista de contratos (Fase 202, REN-06).
 * Mostra a situação, os dois negócios (original e de renovação, nunca um no
 * lugar do outro) e as ações. Tudo só enfileira: a tela não espera o HubSpot.
 */
export default function RenovacaoCelula({ linha }) {
    const r = linha.renovacao;
    const [modal, setModal] = useState(null); // 'liberar' | 'dispensar' | 'historico'
    const [justificativa, setJustificativa] = useState('');
    const [confirmou, setConfirmou] = useState(false);
    const [historico, setHistorico] = useState(null);
    const [erroHistorico, setErroHistorico] = useState(null);
    const [enviando, setEnviando] = useState(false);

    if (!r) return <span className="text-white/30">—</span>;

    const fechar = () => {
        setModal(null);
        setJustificativa('');
        setConfirmou(false);
    };

    const urlSincronizar = route('admin.contratos.renovacao.sincronizar', [linha.company_id, linha.servico_id]);
    const opcoes = {
        preserveScroll: true,
        onStart: () => setEnviando(true),
        onFinish: () => setEnviando(false),
        onSuccess: fechar,
    };

    const sincronizar = () => {
        if (r.status_integracao === 'revisao_manual') {
            setModal('liberar');
            return;
        }
        router.post(urlSincronizar, {}, opcoes);
    };

    const liberar = () => {
        router.post(urlSincronizar, { liberar_criacao: true, justificativa }, opcoes);
    };

    const dispensar = () => {
        router.post(route('admin.contratos.renovacao.dispensar', r.ciclo_id), { justificativa }, opcoes);
    };

    const abrirHistorico = () => {
        setModal('historico');
        setHistorico(null);
        setErroHistorico(null);
        axios
            .get(route('admin.contratos.renovacao.historico', [linha.company_id, linha.servico_id]))
            .then((res) => setHistorico(res.data))
            .catch(() => setErroHistorico('Não foi possível carregar o histórico agora.'));
    };

    const comercial = r.status_comercial ? COMERCIAL_LABELS[r.status_comercial] : null;
    const integracao = r.status_integracao ? INTEGRACAO_LABELS[r.status_integracao] : null;
    const justificativaOk = justificativa.trim().length >= 10;

    return (
        <div className="flex flex-col gap-1 min-w-[190px]" onClick={(e) => e.stopPropagation()}>
            <div className="flex flex-wrap items-center gap-1">
                <Selo info={comercial} />
                <Selo info={integracao} title={r.ultimo_erro ?? undefined} />
                {!comercial && !integracao && <span className="text-[11px] text-white/30">Sem renovação aberta</span>}
            </div>

            {r.ultima_decisao_simulada && r.ultima_decisao && (
                <span className="text-[10px] text-white/40">
                    Simulação: {DECISOES_LABELS[r.ultima_decisao] ?? r.ultima_decisao}
                </span>
            )}

            <div className="flex flex-col gap-0.5">
                <LinkNegocio url={r.negocio_original_url} id={r.negocio_original_id} rotulo="Negócio original" />
                <LinkNegocio url={r.negocio_renovacao_url} id={r.negocio_renovacao_id} rotulo="Negócio de renovação" />
            </div>

            <div className="flex flex-wrap items-center gap-1">
                <button
                    type="button"
                    className={cn(btnBase, btnNormal)}
                    disabled={!r.pode_sincronizar || enviando}
                    onClick={sincronizar}
                    title="Confere agora se a oportunidade de renovação existe no HubSpot e, se precisar, pede para criá-la. Pode clicar mais de uma vez: não duplica."
                >
                    <RefreshCw size={11} /> Sincronizar novamente
                </button>
                {r.pode_dispensar && (
                    <button
                        type="button"
                        className={cn(btnBase, btnNormal)}
                        onClick={() => setModal('dispensar')}
                        title="Encerra esta renovação sem abrir oportunidade. Fica registrado quem dispensou e por quê."
                    >
                        <Ban size={11} /> Dispensar
                    </button>
                )}
                {r.ciclo_id && (
                    <button type="button" className={cn(btnBase, btnNormal)} onClick={abrirHistorico}>
                        <History size={11} /> Histórico
                    </button>
                )}
            </div>

            {/* Modal: liberar criação (ciclo em revisão) */}
            <Dialog open={modal === 'liberar'} onOpenChange={(o) => !o && fechar()}>
                <DialogContent className="bg-ecf-card border-white/10 text-white">
                    <DialogHeader>
                        <DialogTitle>Liberar a criação da oportunidade</DialogTitle>
                        <DialogDescription className="text-white/60">
                            Este ciclo está parado para revisão. Liberar zera o contador de tentativas e fica registrado
                            com o seu nome. Se o sistema já tentou criar e não teve resposta, ele ainda espera alguns
                            minutos antes de criar, para não duplicar.
                        </DialogDescription>
                    </DialogHeader>
                    <textarea
                        value={justificativa}
                        onChange={(e) => setJustificativa(e.target.value)}
                        rows={3}
                        placeholder="Por que está liberando? (mínimo de 10 caracteres)"
                        className="w-full rounded-lg border border-white/10 bg-white/[0.03] p-2 text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                    />
                    <label className="flex items-start gap-2 text-[13px] text-white/80">
                        <input type="checkbox" checked={confirmou} onChange={(e) => setConfirmou(e.target.checked)} className="mt-1" />
                        Confirmo que conferi no HubSpot e não existe oportunidade deste ciclo
                    </label>
                    <DialogFooter>
                        <button type="button" className={cn(btnBase, btnNormal, 'h-8 px-3 text-[12px]')} onClick={fechar}>Cancelar</button>
                        <button
                            type="button"
                            disabled={!justificativaOk || !confirmou || enviando}
                            onClick={liberar}
                            className="inline-flex items-center h-8 px-3 rounded-md bg-ecf-yellow text-black text-[12px] font-semibold disabled:opacity-40"
                        >
                            Liberar e sincronizar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Modal: dispensar */}
            <Dialog open={modal === 'dispensar'} onOpenChange={(o) => !o && fechar()}>
                <DialogContent className="bg-ecf-card border-white/10 text-white">
                    <DialogHeader>
                        <DialogTitle>Dispensar esta renovação</DialogTitle>
                        <DialogDescription className="text-white/60">
                            Nenhuma oportunidade será aberta para este ciclo. Nada é alterado no HubSpot; o sistema
                            guarda quem dispensou, quando e o motivo.
                        </DialogDescription>
                    </DialogHeader>
                    <textarea
                        value={justificativa}
                        onChange={(e) => setJustificativa(e.target.value)}
                        rows={3}
                        placeholder="Motivo da dispensa (mínimo de 10 caracteres)"
                        className="w-full rounded-lg border border-white/10 bg-white/[0.03] p-2 text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                    />
                    <DialogFooter>
                        <button type="button" className={cn(btnBase, btnNormal, 'h-8 px-3 text-[12px]')} onClick={fechar}>Cancelar</button>
                        <button
                            type="button"
                            disabled={!justificativaOk || enviando}
                            onClick={dispensar}
                            className="inline-flex items-center h-8 px-3 rounded-md bg-ecf-yellow text-black text-[12px] font-semibold disabled:opacity-40"
                        >
                            Dispensar renovação
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Modal: histórico */}
            <Dialog open={modal === 'historico'} onOpenChange={(o) => !o && fechar()}>
                <DialogContent className="bg-ecf-card border-white/10 text-white max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Histórico da renovação</DialogTitle>
                        <DialogDescription className="text-white/60">
                            Cada ciclo é um período de contrato. Os eventos mostram o que o sistema e as pessoas fizeram.
                        </DialogDescription>
                    </DialogHeader>
                    {erroHistorico && <p className="text-[13px] text-red-300">{erroHistorico}</p>}
                    {!historico && !erroHistorico && <p className="text-[13px] text-white/50">Carregando…</p>}
                    {historico && (
                        <div className="space-y-4 text-[12px]">
                            <div>
                                <p className="font-semibold text-white/80 mb-1">Ciclos</p>
                                {historico.ciclos.length === 0 && <p className="text-white/40">Nenhum ciclo ainda.</p>}
                                {historico.ciclos.map((c) => (
                                    <div key={c.numero} className="rounded-lg border border-white/10 p-2 mb-1 text-white/70">
                                        <p>
                                            Ciclo {c.numero} — término {c.termino_capturado ? formatDate(c.termino_capturado) : 'sem data'}
                                            {c.termino_novo ? `, novo término ${formatDate(c.termino_novo)}` : ''}
                                        </p>
                                        <p className="text-white/50">
                                            {COMERCIAL_LABELS[c.status_comercial]?.texto ?? c.status_comercial}
                                            {c.negocio_original_id ? ` · negócio original ${c.negocio_original_id}` : ''}
                                            {c.negocio_renovacao_id ? ` · negócio de renovação ${c.negocio_renovacao_id}` : ''}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <div>
                                <p className="font-semibold text-white/80 mb-1">Eventos</p>
                                {historico.eventos.length === 0 && <p className="text-white/40">Nenhum evento registrado.</p>}
                                {historico.eventos.map((e, i) => (
                                    <div key={i} className="border-b border-white/[0.06] py-1.5 text-white/70">
                                        <p>
                                            <span className="text-white/40">{e.created_at ? new Date(e.created_at).toLocaleString('pt-BR') : ''}</span>
                                            {' — '}{EVENTO_LABELS[e.tipo] ?? e.tipo}
                                            {e.simulacao ? ' (simulação)' : ''}
                                        </p>
                                        <p className="text-white/40">
                                            {ORIGEM_EVENTO[e.origem] ?? e.origem}{e.usuario ? ` · ${e.usuario}` : ''}
                                            {e.dados?.motivo ? ` · ${e.dados.motivo}` : ''}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
