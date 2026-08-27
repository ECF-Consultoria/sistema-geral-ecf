import { useMemo, useState, useEffect, useCallback } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, RefreshCw, Search, FileText, FilePlus2, ExternalLink,
    Wallet, Target, Building2, ChevronDown, ChevronRight, Link2, BookUser,
    Sparkles, MegaphoneOff, ShieldAlert, Pencil, Trash2, Check, X,
    Minus, Send, Users, MapPin, GitBranch, SlidersHorizontal, Undo2, Maximize2, Minimize2,
    Archive, Filter, Tv,
} from 'lucide-react';
import * as Popover from '@radix-ui/react-popover';
import { formatCurrency, cn } from '@/lib/utils';
import { useAutoFilter, VAZIO } from '@/hooks/useAutoFilter';
import ColumnFilter from '@/Components/ColumnFilter';
import BulkActionBar from '@/Components/BulkActionBar';
import { STATUS_META, STATUS_ORDEM } from './components/statusMeta';
// Célula de Cust ID compartilhada com o Onboarding (/mlb/implementacao).
import { CustIdCell } from './components/CustIdCell';
import StatusBadge from './components/StatusBadge';
import HeroKpi from './components/HeroKpi';
import FatVsMetaChart from './components/FatVsMetaChart';
import RankingProgresso from './components/RankingProgresso';
import StatusDonut from './components/StatusDonut';
import AdsCard from './components/AdsCard';
import M1Card from './components/M1Card';
import SparkSemanal from './components/SparkSemanal';
import { montarCorDoPolo } from './components/poloCores';
import { corEstagio } from './components/estagioBadge';
import { corAds } from './components/adsCor';
import OperacoesPanel from './components/OperacoesPanel';
import MetasPanel from './components/MetasPanel';
import EntrantesM0Panel from './components/EntrantesM0Panel';
import ModoTV from './components/ModoTV';
import ImplModal from '@/Pages/Mlb/components/ImplModal';

// ─── Domínio (strings EXATAS — chaves de comparação no banco) ─────────────────────
const ORDEM_FASE = ['Encaminhar Comercial', 'Aceite no Projeto', 'M0', 'M1', 'M2', 'M3', 'M4', 'Encerrado', 'Protocolo Churn', 'Churn'];
const FASES_TERMINAIS = ['Encerrado', 'Protocolo Churn', 'Churn'];

// Escopo operacional do painel: só quem está EM OPERAÇÃO conta nos filtros/donuts/grade.
// Fora ficam as fases que não são trabalho ativo — Churn, Encerrado, Aceite no Projeto,
// Fechamento, M0 e as empresas sem fase preenchida. Sem isso, um filtro de Logística por
// "Sem informações" devolvia empresa que saiu do projeto há meses. É um recorte de TELA
// (o toggle abaixo devolve a lista inteira); não altera nada em banco, meta ou faturamento.
const FASES_ESCOPO = ['M1', 'M2', 'M3', 'M4'];
const SEM_RESP = '__sem__';
const SEM_ESTAGIO = '__sem__';

// Bloco da ficha responsável por salvar cada campo (rota PATCH parcial).
const BLOCO_DE = {
    polo: 'identificacao', fase: 'identificacao', data_solicitacao: 'identificacao',
    status_entrada: 'identificacao', chance_entrada: 'identificacao',
    acesso_colaborador: 'acessos', gmail_colaborador: 'acessos', grupo_whatsapp: 'acessos', link_whatsapp: 'acessos', reuniao_onboarding: 'acessos',
    planilha_produtos: 'produtos', listagem: 'produtos', publicacao: 'produtos', decola: 'produtos', campanha_criada: 'produtos', central_promocao: 'produtos',
    contextos_logistica: 'logistica', me1: 'logistica', integradora: 'logistica', places: 'logistica', erp: 'logistica',
};
// Campos que dão pra salvar SEM ficha (via empresas.update). O resto exige ficha.
const SEM_FICHA_OK = ['fase', 'polo'];

const STATUS_ENVIO_LABELS = { falta_enviar: 'Pendente', enviado: 'Enviado', concluido: 'Concluído' };
const STATUS_ENVIO_BADGE = {
    falta_enviar: 'text-red-300 bg-red-500/10 border-red-500/20',
    enviado:      'text-amber-300 bg-amber-500/10 border-amber-500/20',
    concluido:    'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
};

const CARD = cn(
    'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 lg:p-6',
    'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent',
);

// ── Células "fantasma" (look de planilha) ─────────────────────────────────────────
// O valor aparece como TEXTO limpo; a moldura de campo (borda + leve fundo) só surge no
// hover/foco. Altura por PADDING (não h-8 fixo — com `select{appearance:none}` do app.css
// a altura fixa cortava o texto). `!bg-transparent` vence a regra global
// `select{background:#1a1c24}` (fora de @layer) p/ o select ficar translúcido parado.
const CELL = 'w-full rounded-md text-[12px] px-2 py-1.5 leading-tight border border-transparent !bg-transparent outline-none cursor-pointer transition-colors hover:border-white/15 hover:!bg-white/[0.06] focus:border-ecf-yellow/60 focus:!bg-white/[0.07] disabled:opacity-40';
const CELL_TXT = cn(CELL, 'cursor-text');

const fmtPct = (n) => `${Number(n ?? 0).toFixed(0)}%`;
const estagioKey = (e) => (e?.estagio && e.estagio !== '') ? e.estagio : SEM_ESTAGIO;

// Cor do texto por Fase M — hierarquia rápida na grade.
const COR_FASE = { 'Encaminhar Comercial': 'text-white/45', 'Aceite no Projeto': 'text-fuchsia-300', M0: 'text-violet-300', M1: 'text-sky-300', M2: 'text-amber-200', M3: 'text-amber-300', M4: 'text-emerald-300', Encerrado: 'text-white/40', 'Protocolo Churn': 'text-orange-300', Churn: 'text-red-300' };
const corFase = (f) => COR_FASE[f] ?? 'text-white/70';

// Cor do texto por valor de onboarding (verde=ok · âmbar=em progresso · vermelho=bloqueio).
// Espelha a classificação da ficha (corStatus) — só a cor do texto, p/ a grade escaneável.
const VAL_POS  = ['Com acesso', 'Já enviado', 'Já listado', 'Concluído', 'Concluido', 'Sim', 'Ativo', 'Checklist realizado', 'Feito', 'Alta'];
const VAL_PROG = ['Pronto para listar', 'Estágio 2', 'Em contratação', 'Realizando checklist', 'Solicitado', 'Precisa de ME1', 'Aguardando contato', 'Conversando com cliente', 'Pendente com integradora', 'Preenchendo tabela', 'Verificando', 'Agendada', 'em contato', 'Média', 'Reserva - entrada prox mês', 'Mensagem Enviada'];
const VAL_NEG  = ['Sem acesso', 'Banida', 'Protocolo Churn', 'Churn', 'Encerrado', 'Não', 'Não enviado', 'Suspensa', 'Falta informação', 'Falta emissor fiscal', 'Falta certificado A1', 'Falta endereço fiscal', 'Baixo', 'Abandonou o projeto', 'Não compareceu', 'Não responde', 'Não tem CNPJ', 'Não tem conta ML'];
function corValor(v) {
    if (!v) return 'text-white/25';
    if (VAL_POS.includes(v)) return 'text-emerald-300';
    if (VAL_PROG.includes(v)) return 'text-amber-300';
    if (VAL_NEG.includes(v)) return 'text-red-300';
    return 'text-white/80';
}

// Classificadores de "tom" p/ os indicadores acionáveis do OperacoesPanel (reusam as listas acima).
const toneValor = (v) => (VAL_POS.includes(v) ? 'green' : VAL_PROG.includes(v) ? 'amber' : VAL_NEG.includes(v) ? 'red' : 'neutral');
const TONE_FASE = { 'Encaminhar Comercial': 'neutral', 'Aceite no Projeto': 'violet', M0: 'violet', M1: 'sky', M2: 'amber', M3: 'amber', M4: 'green', Encerrado: 'neutral', 'Protocolo Churn': 'red', Churn: 'red' };
const toneFase = (f) => TONE_FASE[f] ?? 'neutral';

// Coluna do indicador → lente onde ela é editável (p/ navegar ao clicar). null = visível em todas.
const COL_LENTE = {
    situacao: null, responsavel: 'geral', fase: 'geral', estagio: 'geral', envio: 'geral',
    acesso_colaborador: 'acessos', planilha_produtos: 'produtos', listagem: 'produtos', publicacao: 'produtos',
    me1: 'logistica', integradora: 'logistica', places: 'logistica', erp: 'logistica', fin_status: 'financeiro',
};

// ─── AutoFiltro (estilo Excel) — helpers de domínio ─────────────────────────────────
// Coluna "Situação" (na Empresa): substitui os antigos botões/badges de filtro. Cada
// linha vira um conjunto de flags ativos; o funil filtra por qualquer um deles.
const SITUACAO_LABEL = {
    problema:       'Com problema',
    fora_meta:      'Desconsiderada da meta',
    fora_prazo:     'Fora do prazo',
    pendente_envio: 'Pendente de envio',
    sem_ficha:      'Sem ficha',
    ads_off:        'ADS desligado',
    ok:             'Sem pendências',
};
function situacaoDe(e) {
    const f = [];
    if (e.problema)                      f.push('problema');
    // Problema que tira da meta é um recorte próprio (curadoria da Distribuição de status).
    if (e.problema_desconsidera_meta)    f.push('fora_meta');
    if (e.fora_do_prazo)                 f.push('fora_prazo');
    if (e.status_envio === 'falta_enviar') f.push('pendente_envio');
    if (!e.impl_id)                      f.push('sem_ficha');
    if (e.ads_desligado)                 f.push('ads_off');
    return f.length ? f : ['ok'];
}

// ── Link do grupo de WhatsApp ───────────────────────────────────────────────────────
// O time cola o que tiver na mão: convite (chat.whatsapp.com/XXXX), wa.me/55… e às vezes
// sem o protocolo. Guardamos o texto como veio; só o href é normalizado na hora de abrir.
function hrefWhats(v) {
    const s = String(v ?? '').trim();
    if (!s) return null;
    return /^https?:\/\//i.test(s) ? s : `https://${s.replace(/^\/+/, '')}`;
}
// Rótulo curto p/ o funil de filtro: só o "miolo" do link (o convite inteiro estoura a lista).
function encurtaLink(v) {
    const s = String(v ?? '').trim().replace(/^https?:\/\//i, '').replace(/\/+$/, '');
    return s.length > 34 ? `${s.slice(0, 18)}…${s.slice(-12)}` : s;
}

// Data ISO (Y-m-d) → rótulo BR nas opções do filtro (sem fuso: string pura).
function fmtDataBR(v) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v));
    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(v);
}

// Campos de select editável cujo valor criado inline vira opção para as demais empresas
// (alimenta `valoresPresentes`). `fase` entra aqui só para reaproveitar valores legados no
// dropdown — criar fase nova é bloqueado no EditSelect (ver `criavel`).
const CAMPOS_CRIAVEIS = ['fase', 'polo', 'status_entrada', 'chance_entrada', 'reuniao_onboarding', 'acesso_colaborador', 'planilha_produtos', 'listagem', 'publicacao', 'decola', 'central_promocao', 'me1', 'integradora', 'places', 'erp'];

// ─── Edição em MASSA ────────────────────────────────────────────────────────────────
// Campos que exigem ficha (o backend IGNORA empresas sem ficha p/ estes; fase/polo não).
const CAMPOS_SO_FICHA = ['responsavel_id', 'status_envio', 'status_entrada', 'chance_entrada', 'reuniao_onboarding', 'acesso_colaborador', 'gmail_colaborador', 'grupo_whatsapp', 'planilha_produtos', 'listagem', 'publicacao', 'decola', 'campanha_criada', 'central_promocao', 'contextos_logistica', 'me1', 'integradora', 'places', 'erp', 'data_solicitacao'];

// Caixa de seleção temática (mesmo visual do AutoFiltro). state: 'on' | 'off' | 'ind'.
function CaixaSel({ state }) {
    return (
        <span className={cn('flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors',
            state === 'off' ? 'border-white/25 bg-transparent' : 'border-ecf-yellow bg-ecf-yellow')}>
            {state === 'on'  && <Check size={12} className="text-black" strokeWidth={3} />}
            {state === 'ind' && <Minus size={12} className="text-black" strokeWidth={3} />}
        </span>
    );
}

// Botão de ação rápida na barra de lote: abre popover (p/ cima) com opções; escolher chama onPick.
function AcaoLote({ icon: Icon, label, opcoes, onPick, busca = false }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ql = q.trim().toLowerCase();
    const lista = busca && ql ? opcoes.filter((o) => (o.label ?? '').toLowerCase().includes(ql)) : opcoes;
    return (
        <Popover.Root open={open} onOpenChange={(v) => { setOpen(v); if (v) setQ(''); }}>
            <Popover.Trigger asChild>
                <button type="button"
                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.1] bg-white/[0.04] px-2.5 py-1.5 text-[12px] font-semibold text-white/85 transition hover:border-white/25 hover:bg-white/[0.08]">
                    <Icon size={13} /> {label} <ChevronDown size={12} className="text-white/40" />
                </button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content side="top" align="start" sideOffset={8}
                    className="z-[60] w-56 rounded-xl border border-white/10 bg-ecf-card p-1.5 text-white shadow-2xl shadow-black/50 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95">
                    {busca && (
                        <div className="relative mb-1.5">
                            <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-white/30" />
                            <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Pesquisar…"
                                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] pl-7 pr-2 py-1.5 text-[12px] text-white outline-none focus:border-ecf-yellow/40" />
                        </div>
                    )}
                    <div className="max-h-64 overflow-y-auto">
                        {lista.length === 0 && <p className="px-2 py-3 text-center text-[11px] text-white/25">Nenhuma opção.</p>}
                        {lista.map((o) => (
                            <button key={String(o.value)} type="button" onClick={() => { setOpen(false); onPick(o.value); }}
                                className="flex w-full items-center rounded-lg px-2 py-1.5 text-left text-[12px] text-white/85 transition hover:bg-white/[0.06]">
                                {o.label}
                            </button>
                        ))}
                    </div>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

// Controle de valor de UM campo dentro do drawer multi-campo.
function CampoLote({ campo, valor, onChange }) {
    const cls = 'w-full rounded-lg border border-white/[0.1] bg-ecf-bg px-2.5 py-1.5 text-[12px] text-white outline-none focus:border-ecf-yellow/40';
    if (campo.tipo === 'bool') {
        return (
            <div className="flex gap-1.5">
                {[['1', 'Sim'], ['0', 'Não']].map(([v, l]) => (
                    <button key={v} type="button" onClick={() => onChange(v)}
                        className={cn('rounded-lg border px-3 py-1 text-[12px] transition', String(valor) === v ? 'border-ecf-yellow/50 bg-ecf-yellow/10 text-ecf-yellow' : 'border-white/10 text-white/60 hover:text-white')}>{l}</button>
                ))}
            </div>
        );
    }
    if (campo.tipo === 'date') return <input type="date" value={valor ?? ''} onChange={(e) => onChange(e.target.value)} className={cls} />;
    if (campo.tipo === 'text') return <input type="text" value={valor ?? ''} onChange={(e) => onChange(e.target.value)} placeholder="valor…" className={cls} />;
    return (
        <select value={valor ?? ''} onChange={(e) => onChange(e.target.value)} className={cls}>
            <option value="" className="bg-ecf-card">— selecione —</option>
            {campo.opcoes.map((o) => <option key={String(o.value)} value={o.value} className="bg-ecf-card text-white">{o.label}</option>)}
        </select>
    );
}

// Drawer lateral "Editar vários…" — marca só os campos a alterar; os demais permanecem.
function DrawerLote({ aberto, onFechar, count, semFichaCount, campos, onAplicar, busy }) {
    const [ativos, setAtivos]     = useState(() => new Set());
    const [rascunho, setRascunho] = useState({});
    useEffect(() => { if (aberto) { setAtivos(new Set()); setRascunho({}); } }, [aberto]);
    if (!aberto) return null;

    const toggle = (campo) => setAtivos((s) => { const n = new Set(s); if (n.has(campo)) n.delete(campo); else n.add(campo); return n; });
    // Só entra em `changes` campo marcado COM valor definido — "marcado porém vazio" é no-op
    // (evita limpar dados em massa por engano); o backend converte '' → null só se enviado.
    const changes = {};
    ativos.forEach((c) => { const v = rascunho[c]; if (v !== undefined && v !== '') changes[c] = v; });
    const nMud = Object.keys(changes).length;
    const temSoFicha = Object.keys(changes).some((c) => CAMPOS_SO_FICHA.includes(c));

    return (
        <div className="fixed inset-0 z-[70] flex justify-end">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onFechar} />
            <div className="relative flex h-full w-full max-w-md flex-col border-l border-white/10 bg-ecf-card shadow-2xl animate-in slide-in-from-right duration-200">
                <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div>
                        <h3 className="font-semibold text-white">Editar em massa</h3>
                        <p className="mt-0.5 text-[12px] text-white/40">{count} empresa{count === 1 ? '' : 's'} selecionada{count === 1 ? '' : 's'}</p>
                    </div>
                    <button onClick={onFechar} className="text-white/40 transition hover:text-white"><X size={18} /></button>
                </div>
                <div className="flex-1 space-y-2 overflow-y-auto px-5 py-4">
                    <p className="mb-1 text-[11px] text-white/35">Marque só os campos que quer alterar. Os demais permanecem como estão.</p>
                    {campos.map((c) => {
                        const on = ativos.has(c.campo);
                        return (
                            <div key={c.campo} className={cn('rounded-xl border p-2.5 transition', on ? 'border-ecf-yellow/30 bg-ecf-yellow/[0.04]' : 'border-white/[0.06] bg-white/[0.02]')}>
                                <button type="button" onClick={() => toggle(c.campo)} className="flex w-full items-center gap-2 text-left">
                                    <CaixaSel state={on ? 'on' : 'off'} />
                                    <span className={cn('text-[13px] font-medium', on ? 'text-white' : 'text-white/60')}>{c.label}</span>
                                    {CAMPOS_SO_FICHA.includes(c.campo) && <span className="ml-auto text-[9px] text-amber-200/60">requer ficha</span>}
                                </button>
                                {on && <div className="mt-2 pl-6"><CampoLote campo={c} valor={rascunho[c.campo]} onChange={(v) => setRascunho((s) => ({ ...s, [c.campo]: v }))} /></div>}
                            </div>
                        );
                    })}
                </div>
                <div className="space-y-2 border-t border-white/10 px-5 py-4">
                    {temSoFicha && semFichaCount > 0 && (
                        <p className="flex items-center gap-1.5 text-[11px] text-amber-300/80"><AlertTriangle size={12} /> {semFichaCount} sem ficha ser{semFichaCount === 1 ? 'á' : 'ão'} ignorada{semFichaCount === 1 ? '' : 's'} nos campos que exigem ficha.</p>
                    )}
                    <div className="flex items-center gap-2">
                        <button type="button" disabled={nMud === 0 || busy} onClick={() => onAplicar(changes)}
                            className={cn('inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-[13px] font-semibold transition',
                                nMud === 0 || busy ? 'cursor-default bg-white/[0.05] text-white/30' : 'bg-ecf-yellow text-black hover:bg-ecf-yellow/90')}>
                            {busy ? <RefreshCw size={14} className="animate-spin" /> : <Check size={14} />}
                            Aplicar{nMud > 0 ? ` ${nMud} ` : ' '}a {count}
                        </button>
                        <button type="button" onClick={onFechar} className="rounded-lg border border-white/10 px-3 py-2 text-[13px] text-white/60 transition hover:text-white">Cancelar</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// Toast de resultado + Desfazer (undo), auto-oculta.
function ToastLote({ info, temUndo, onUndo, onFechar, busy }) {
    if (!info) return null;
    return (
        <div className="fixed inset-x-0 bottom-24 z-[65] flex justify-center px-4 animate-in fade-in slide-in-from-bottom-2 duration-200">
            <div className="flex items-center gap-3 rounded-xl border border-white/10 bg-ecf-card px-4 py-2.5 shadow-2xl shadow-black/60">
                {info.erro ? (
                    <>
                        <span className="inline-flex items-center gap-1.5 text-[12px] text-red-300"><AlertTriangle size={13} /> Falha ao aplicar.</span>
                        {temUndo && (
                            <button type="button" onClick={onUndo} disabled={busy}
                                className="inline-flex items-center gap-1 rounded-lg bg-white/[0.06] px-2 py-1 text-[12px] font-semibold text-ecf-yellow transition hover:bg-white/[0.1] disabled:opacity-50">
                                <Undo2 size={12} /> Tentar desfazer de novo
                            </button>
                        )}
                    </>
                ) : (
                    <>
                        <Check size={14} className="text-emerald-400" />
                        <span className="text-[12px] text-white">
                            <b>{info.aplicadas}</b> empresa{info.aplicadas === 1 ? '' : 's'} alterada{info.aplicadas === 1 ? '' : 's'}
                            {info.ignoradas?.length ? <span className="text-amber-300/80"> · {info.ignoradas.length} ignorada{info.ignoradas.length === 1 ? '' : 's'}</span> : null}
                        </span>
                        {temUndo && (
                            <button type="button" onClick={onUndo} disabled={busy}
                                className="inline-flex items-center gap-1 rounded-lg bg-white/[0.06] px-2 py-1 text-[12px] font-semibold text-ecf-yellow transition hover:bg-white/[0.1] disabled:opacity-50">
                                <Undo2 size={12} /> Desfazer
                            </button>
                        )}
                    </>
                )}
                <button type="button" onClick={onFechar} className="text-white/30 transition hover:text-white"><X size={13} /></button>
            </div>
        </div>
    );
}

// ─── Modal "Arquivados" ───────────────────────────────────────────────────────────
// Lista as empresas arquivadas (ausentes na planilha V2). Só leitura + "Desarquivar".
// Nada aqui conta em metas/faturamento/painel — é o "limbo" reversível.
function ArquivadasModal({ arquivadas = [], onDesarquivar, onClose }) {
    const [q, setQ] = useState('');
    const ql = q.trim().toLowerCase();
    const lista = ql
        ? arquivadas.filter((a) => `${a.nome} ${a.cust_id ?? ''} ${a.polo ?? ''}`.toLowerCase().includes(ql))
        : arquivadas;
    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-white/10 bg-ecf-card shadow-2xl">
                <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div>
                        <h3 className="flex items-center gap-2 font-semibold text-white"><Archive size={16} /> Empresas arquivadas</h3>
                        <p className="mt-0.5 text-[12px] text-white/40">{arquivadas.length} empresa{arquivadas.length === 1 ? '' : 's'} · fora do projeto Polos (não contam em metas, faturamento nem no painel).</p>
                    </div>
                    <button onClick={onClose} className="text-white/40 transition hover:text-white"><X size={18} /></button>
                </div>
                <div className="border-b border-white/[0.06] px-5 py-3">
                    <div className="relative">
                        <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar arquivada…"
                            className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] pl-8 pr-3 py-1.5 text-[12px] text-white/90 outline-none focus:border-ecf-yellow/40" />
                    </div>
                </div>
                <div className="flex-1 overflow-auto">
                    {lista.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-white/25">{arquivadas.length === 0 ? 'Nenhuma empresa arquivada.' : 'Nenhuma arquivada neste filtro.'}</p>
                    ) : (
                        <table className="w-full text-left text-[12px]">
                            <thead className="sticky top-0 bg-ecf-card">
                                <tr className="border-b border-white/[0.1] text-white/45 text-[11px] uppercase tracking-wider">
                                    <th className="px-4 py-2.5 font-semibold">Empresa</th>
                                    <th className="px-3 py-2.5 font-semibold">Fase</th>
                                    <th className="px-3 py-2.5 font-semibold">Polo</th>
                                    <th className="px-3 py-2.5 font-semibold">Arquivada em</th>
                                    <th className="px-3 py-2.5 font-semibold">Motivo</th>
                                    <th className="px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {lista.map((a) => (
                                    <tr key={a.id} className="border-b border-white/[0.05] hover:bg-white/[0.02]">
                                        <td className="px-4 py-2.5">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-white/90">{a.nome}</span>
                                                {a.cust_id && <span className="rounded border border-white/10 bg-white/[0.04] px-1 py-0.5 font-mono text-[10px] text-white/45">{a.cust_id}</span>}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5 text-white/60">{a.fase || '—'}</td>
                                        <td className="px-3 py-2.5 text-white/60">{a.polo || '—'}</td>
                                        <td className="px-3 py-2.5 tabular-nums text-white/50">{a.arquivado_em || '—'}</td>
                                        <td className="max-w-[220px] truncate px-3 py-2.5 text-white/45" title={a.arquivado_motivo ?? ''}>{a.arquivado_motivo || '—'}</td>
                                        <td className="px-4 py-2.5 text-right">
                                            <button type="button" onClick={() => onDesarquivar(a)}
                                                className="inline-flex items-center gap-1 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold text-emerald-300 transition hover:bg-emerald-500/20">
                                                <Undo2 size={12} /> Desarquivar
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    );
}

function Barra({ pct, cor }) {
    const p = Math.max(0, Math.min(100, Number(pct ?? 0)));
    return (
        <div className="h-1.5 w-full rounded-full bg-white/[0.06] overflow-hidden">
            <div className="h-full rounded-full transition-all" style={{ width: `${p}%`, background: cor || '#ffe600' }} />
        </div>
    );
}

// ─── Editores inline (planilha) ─────────────────────────────────────────────────────
// Cada editor salva sozinho. Campos do onboarding exigem ficha; sem ela mostram um
// hint "criar ficha" no lugar do controle (exceto fase/polo, que salvam via empresa).
function exigeFicha(campo, e) {
    return !e.impl_id && !SEM_FICHA_OK.includes(campo);
}

function SemFicha({ onCriar }) {
    return (
        <button onClick={onCriar} title="Criar ficha de onboarding para liberar a edição"
            className="inline-flex items-center gap-1 text-white/25 hover:text-ecf-yellow text-[11px] transition">
            <FilePlus2 size={11} /> criar ficha
        </button>
    );
}

const NOVO_VALOR = '__novo__';
// `criavel={false}` esconde o "＋ Criar novo valor…" — usado só na Fase, domínio fechado que
// alimenta FASE_PARA_PROJETO no backend (fase inventada tiraria a empresa do projeto POLOS).
function EditSelect({ e, campo, opcoes = [], presentes = [], onSave, onCriar, placeholder = '—', cor, criavel = true }) {
    if (exigeFicha(campo, e)) return <SemFicha onCriar={onCriar} />;
    const val = e[campo] ?? '';
    const corTxt = val ? (cor ? cor(val) : 'text-white/85') : 'text-white/25';
    // Opções = catálogo ∪ valores presentes nos dados ∪ valor atual (dedup, ordem preservada).
    const lista = [];
    const vistos = new Set();
    for (const o of [...opcoes, ...presentes, val]) {
        if (o && !vistos.has(o)) { vistos.add(o); lista.push(o); }
    }
    const aoMudar = (ev) => {
        const v = ev.target.value;
        if (v === NOVO_VALOR) {
            const novo = (window.prompt('Novo valor para este campo:') ?? '').trim();
            if (novo && novo !== val) onSave(e, campo, novo);
            return; // o <select> volta ao valor atual no re-render (preserveState)
        }
        onSave(e, campo, v === '' ? null : v);
    };
    return (
        <select
            value={val}
            onChange={aoMudar}
            style={{ backgroundColor: 'transparent' }}
            className={cn(CELL, corTxt, val && 'font-medium')}
        >
            <option value="" className="bg-ecf-card text-white/50">{placeholder}</option>
            {lista.map((o) => <option key={o} value={o} className="bg-ecf-card text-white">{o}</option>)}
            {criavel && <option value={NOVO_VALOR} className="bg-ecf-card text-ecf-yellow">＋ Criar novo valor…</option>}
        </select>
    );
}

function EditToggle({ e, campo, onSave, onCriar }) {
    if (exigeFicha(campo, e)) return <SemFicha onCriar={onCriar} />;
    const v = e[campo] === true;
    return (
        <button
            type="button"
            onClick={() => onSave(e, campo, !v)}
            className={cn('inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition-all',
                v ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
                  : 'border-transparent bg-transparent text-white/35 hover:border-white/15 hover:text-white/70')}
        >
            {v ? <Check size={11} /> : null}{v ? 'Sim' : 'Não'}
        </button>
    );
}

function EditText({ e, campo, onSave, onCriar, placeholder = '—', wide }) {
    // Hooks SEMPRE no topo (Rules of Hooks): impl_id muda em runtime ao "criar ficha"
    // (reload com preserveState mantém a mesma instância) — o early-return abaixo dos
    // hooks trocaria a contagem de hooks entre renders e quebraria a árvore React.
    const [v, setV] = useState(e[campo] ?? '');
    // Sincroniza se o valor externo mudar (reload).
    useEffect(() => { setV(e[campo] ?? ''); }, [e[campo]]);
    if (exigeFicha(campo, e)) return <SemFicha onCriar={onCriar} />;
    const salvar = () => { if ((v ?? '') !== (e[campo] ?? '')) onSave(e, campo, v === '' ? null : v); };
    return (
        <input
            type="text"
            value={v}
            placeholder={placeholder}
            onChange={(ev) => setV(ev.target.value)}
            onBlur={salvar}
            onKeyDown={(ev) => { if (ev.key === 'Enter') ev.currentTarget.blur(); }}
            className={cn(CELL_TXT, v ? 'text-white/85' : 'text-white/40', wide && 'min-w-[200px]')}
        />
    );
}

// Link do grupo de WhatsApp: mesma célula-fantasma do EditText + atalho p/ abrir o grupo.
// O input guarda a URL inteira (editável); o ícone só aparece quando há link salvo.
function EditLink({ e, campo, onSave, onCriar, placeholder = 'link do grupo…' }) {
    // Hooks SEMPRE no topo — ver nota em EditText (impl_id muda em runtime ao "criar ficha").
    const [v, setV] = useState(e[campo] ?? '');
    useEffect(() => { setV(e[campo] ?? ''); }, [e[campo]]);
    if (exigeFicha(campo, e)) return <SemFicha onCriar={onCriar} />;
    const salvar = () => {
        const limpo = (v ?? '').trim();
        if (limpo !== (e[campo] ?? '')) onSave(e, campo, limpo === '' ? null : limpo);
    };
    const href = hrefWhats(e[campo]);
    return (
        <div className="flex items-center gap-1 min-w-[210px]">
            <input
                type="text"
                value={v}
                placeholder={placeholder}
                onChange={(ev) => setV(ev.target.value)}
                onBlur={salvar}
                onKeyDown={(ev) => { if (ev.key === 'Enter') ev.currentTarget.blur(); }}
                className={cn(CELL_TXT, 'flex-1 min-w-0', v ? 'text-emerald-300/90' : 'text-white/40')}
            />
            {href && (
                <a href={href} target="_blank" rel="noopener noreferrer" title="Abrir o grupo no WhatsApp"
                    className="shrink-0 rounded-md p-1 text-emerald-300/70 transition hover:bg-emerald-500/10 hover:text-emerald-300">
                    <ExternalLink size={12} />
                </a>
            )}
        </div>
    );
}

function EditDate({ e, campo, onSave, onCriar }) {
    if (exigeFicha(campo, e)) return <SemFicha onCriar={onCriar} />;
    return (
        <input
            type="date"
            value={e[campo] ?? ''}
            onChange={(ev) => onSave(e, campo, ev.target.value === '' ? null : ev.target.value)}
            className={cn(CELL, e[campo] ? 'text-white/85' : 'text-white/40')}
        />
    );
}

/**
 * PolosPainel — Aba unificada (rota mlb.polos-painel). Modelo "planilha":
 * tabela plana com lentes (Geral/Acessos/Produtos/Logística/Financeiro), TODOS os
 * campos do onboarding editáveis INLINE — sem abrir a ficha. Financeiro (admin) vem
 * de endpoint async separado (não vaza p/ não-admin; edição inline fica instantânea).
 */
export default function PolosPainel({
    isAdmin  = false,
    empresas = [],
    arquivadas = [],
    usuarios = [],
    opcoes   = {},
    metasEntrada = [],
    checklist = [],
    erp_opcoes = [],
    integrador_opcoes = [],
    global_padroes = {},
}) {
    const { asset_url, csrf_token } = usePage().props;
    const appUrl = asset_url ?? '';

    // ── Lentes ──
    const LENTES = useMemo(() => {
        const base = [
            { key: 'geral',     label: 'Geral' },
            { key: 'metas',     label: 'Metas' },
            { key: 'acessos',   label: 'Acessos & Setup' },
            { key: 'produtos',  label: 'Produtos & Publicação' },
            { key: 'logistica', label: 'Logística' },
        ];
        return isAdmin ? [...base, { key: 'financeiro', label: 'Performance' }] : base;
    }, [isAdmin]);
    const [lente, setLente] = useState('geral');
    // Escopo M1–M4 ligado por padrão (persistido enquanto a aba viver). Ver FASES_ESCOPO.
    const [soEscopo, setSoEscopo] = useState(() => {
        try { return window.sessionStorage.getItem('polos-painel-escopo') !== 'todas'; } catch (_) { return true; }
    });
    useEffect(() => {
        try { window.sessionStorage.setItem('polos-painel-escopo', soEscopo ? 'escopo' : 'todas'); } catch (_) { /* quota/priv */ }
    }, [soEscopo]);
    // Sub-visão da lente Metas: "Entrantes (M0)" (espelha o PDF) | "Visão geral" (MetasPanel).
    const [metaView, setMetaView] = useState('entrantes');

    // Metas de entrantes por região × mês (aba Metas) — seed das props; edição otimista.
    const [metas, setMetas] = useState(metasEntrada);
    // Re-seed quando a prop volta do servidor (o Modo TV recarrega sozinho a cada N min).
    useEffect(() => { setMetas(metasEntrada); }, [metasEntrada]);

    // ── Filtros: só a busca global fica no topo; o resto vive nos cabeçalhos (AutoFiltro). ──
    // Busca persistida em sessionStorage (Req 13: sobrevive a reload enquanto no módulo).
    const [busca, setBusca] = useState(() => {
        try { return window.sessionStorage.getItem('polos-painel-busca') ?? ''; } catch (_) { return ''; }
    });
    useEffect(() => {
        try { window.sessionStorage.setItem('polos-painel-busca', busca); } catch (_) { /* quota/priv */ }
    }, [busca]);

    // ── Seleção + edição em massa ──
    const [selecionadas, setSelecionadas] = useState(() => new Set());
    const [ancora, setAncora]             = useState(null);
    const [loteBusy, setLoteBusy]         = useState(false);
    const [undoData, setUndoData]         = useState(null);   // { items: [...] } p/ desfazer
    const [undoInfo, setUndoInfo]         = useState(null);   // { aplicadas, ignoradas } | { erro }
    const [drawerLote, setDrawerLote]     = useState(false);

    const [expandida, setExpandida]   = useState(null);
    const [verModal, setVerModal]     = useState(null);   // empresa aberta no modal "Ver"
    const [telaCheia, setTelaCheia]   = useState(false);  // modo planilha em tela cheia
    const [modoTv, setModoTv]         = useState(false);  // painel de parede (TV da empresa)
    const [mostrarArquivadas, setMostrarArquivadas] = useState(false); // modal "Arquivados"
    const [editNota, setEditNota]     = useState({});
    const [semanal, setSemanal]       = useState({});

    // ── Financeiro (admin) — carregado async, separado do payload operacional ──
    const [mes, setMes]           = useState(null);   // null = mês default do backend
    const [fin, setFin]           = useState(null);   // { custNorm: {...} }
    const [finErro, setFinErro]   = useState(false);  // falha no fetch financeiro (≠ "sem meta")
    const [cockpit, setCockpit]   = useState(null);
    const [finLoading, setFinLoading] = useState(false);
    const [cockpitAberto, setCockpitAberto] = useState(false); // recolhido por padrão
    const [sincronizando, setSincronizando] = useState(false);
    const [syncMsg, setSyncMsg] = useState(null);
    // Meta ÚNICA de faturamento (R$) — override local após editar (null = usa a do backend).
    const [metaFatOverride, setMetaFatOverride] = useState(null);
    const [editandoMeta, setEditandoMeta] = useState(false);
    const [metaInput, setMetaInput] = useState('');
    // Contador de recarga do financeiro — o Modo TV o incrementa p/ refazer o fetch sem F5.
    const [finTick, setFinTick] = useState(0);

    useEffect(() => {
        if (!isAdmin) return;
        let vivo = true;
        setFinLoading(true);
        setFinErro(false);
        const url = route('mlb.polos-painel.financeiro', mes ? { mes } : {});
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => { if (!vivo) return; setCockpit(d.cockpit ?? null); setFin(d.financeiro ?? {}); })
            .catch(() => { if (vivo) { setFin({}); setFinErro(true); } })
            .finally(() => { if (vivo) setFinLoading(false); });
        return () => { vivo = false; };
    }, [isAdmin, mes, finTick]);

    const mesEfetivo = mes ?? cockpit?.mesSelecionado ?? null;
    const parcial    = cockpit?.parcial ?? false;
    const fechado    = !parcial;
    const polosCk    = cockpit?.polos ?? [];
    const adsLimites = cockpit?.adsLimites ?? { teto: 3000, alerta1: 1000, alerta2: 2000 }; // barra de ADS (lente Performance)
    const corDoPolo  = useMemo(() => montarCorDoPolo(polosCk), [polosCk]);
    const finDe      = (e) => (fin && e.cust_norm) ? (fin[e.cust_norm] ?? null) : null;

    // ── AutoFiltro (motor genérico) — colunas de todas as lentes; filtros cruzados + sort. ──
    const usuariosById = useMemo(() => {
        const m = {}; usuarios.forEach((u) => { m[u.id] = u.name; }); return m;
    }, [usuarios]);
    const respNome = useCallback((e) => {
        if (e.impl_id) return e.responsavel_id ? (usuariosById[e.responsavel_id] ?? e.responsavel_nome ?? null) : null;
        return e.empresa_responsavel_nome ?? null;
    }, [usuariosById]);

    // Valores já presentes nos dados p/ os selects editáveis (inclui os criados inline).
    const valoresPresentes = useMemo(() => {
        const sets = {}; CAMPOS_CRIAVEIS.forEach((c) => { sets[c] = new Set(); });
        empresas.forEach((e) => CAMPOS_CRIAVEIS.forEach((c) => { if (e[c]) sets[c].add(e[c]); }));
        const out = {}; CAMPOS_CRIAVEIS.forEach((c) => { out[c] = [...sets[c]]; }); return out;
    }, [empresas]);

    // Defs de coluna filtrável/ordenável (accessors fecham sobre `fin`/`usuariosById`).
    const COLUNAS = useMemo(() => {
        const cols = {
            situacao:            { key: 'situacao', label: 'Situação', sortable: false, accessor: situacaoDe, format: (v) => SITUACAO_LABEL[v] ?? v },
            fase:                { key: 'fase', label: 'Fase', accessor: (e) => e.fase },
            estagio:             { key: 'estagio', label: 'Estágio', accessor: (e) => e.estagio, format: (v) => (v === VAZIO ? '(Sem estágio)' : v) },
            polo:                { key: 'polo', label: 'Polo', accessor: (e) => e.polo },
            responsavel:         { key: 'responsavel', label: 'Responsável', accessor: respNome, format: (v) => (v === VAZIO ? '(Sem responsável)' : v) },
            onboarding:          { key: 'onboarding', label: 'Onboarding', type: 'number', filter: false, accessor: (e) => e.onboarding_progresso?.pct ?? null },
            envio:               { key: 'envio', label: 'Envio', accessor: (e) => e.status_envio, format: (v) => (v === VAZIO ? '(Sem envio)' : (STATUS_ENVIO_LABELS[v] ?? v)) },
            status_entrada:      { key: 'status_entrada', label: 'Status entrada', accessor: (e) => e.status_entrada },
            chance_entrada:      { key: 'chance_entrada', label: 'Chance entrada', accessor: (e) => e.chance_entrada },
            acesso_colaborador:  { key: 'acesso_colaborador', label: 'Acesso colaborador', accessor: (e) => e.acesso_colaborador },
            gmail_colaborador:   { key: 'gmail_colaborador', label: 'Gmail colaborador', accessor: (e) => e.gmail_colaborador },
            grupo_whatsapp:      { key: 'grupo_whatsapp', label: 'Grupo WhatsApp', accessor: (e) => (e.grupo_whatsapp === true ? 'Sim' : e.grupo_whatsapp === false ? 'Não' : null) },
            // Filtrável de propósito: o uso real é isolar "(Sem link)" e cobrar quem falta.
            // O funil mostra o link encurtado (a URL inteira estouraria o dropdown).
            link_whatsapp:       { key: 'link_whatsapp', label: 'Link do Whats', accessor: (e) => e.link_whatsapp, format: (v) => (v === VAZIO ? '(Sem link)' : encurtaLink(v)) },
            reuniao_onboarding:  { key: 'reuniao_onboarding', label: 'Reunião onboarding', accessor: (e) => e.reuniao_onboarding },
            data_solicitacao:    { key: 'data_solicitacao', label: 'Data solicitação', type: 'date', accessor: (e) => e.data_solicitacao, format: (v) => (v === VAZIO ? '(Vazios)' : fmtDataBR(v)) },
            data_cadastro:       { key: 'data_cadastro', label: 'Cadastro', type: 'date', sortable: true, accessor: (e) => e.data_cadastro, format: (v) => (v === VAZIO ? '(Sem data)' : fmtDataBR(v)) },
            planilha_produtos:   { key: 'planilha_produtos', label: 'Planilha produtos', accessor: (e) => e.planilha_produtos },
            listagem:            { key: 'listagem', label: 'Listagem', accessor: (e) => e.listagem },
            publicacao:          { key: 'publicacao', label: 'Publicação', accessor: (e) => e.publicacao },
            decola:              { key: 'decola', label: 'Decola', accessor: (e) => e.decola },
            campanha_criada:     { key: 'campanha_criada', label: 'Campanha', accessor: (e) => (e.campanha_criada === true ? 'Sim' : e.campanha_criada === false ? 'Não' : null) },
            central_promocao:    { key: 'central_promocao', label: 'Central de Promoção', accessor: (e) => e.central_promocao },
            contextos_logistica: { key: 'contextos_logistica', label: 'Contextos logística', accessor: (e) => e.contextos_logistica },
            me1:                 { key: 'me1', label: 'ME1', accessor: (e) => e.me1 },
            integradora:         { key: 'integradora', label: 'Integradora', accessor: (e) => e.integradora },
            places:              { key: 'places', label: 'Places', accessor: (e) => e.places },
            erp:                 { key: 'erp', label: 'ERP', accessor: (e) => e.erp },
        };
        if (isAdmin) {
            const fget = (e, k) => (fin && e.cust_norm) ? (fin[e.cust_norm]?.[k] ?? null) : null;
            cols.fin_faturamento = { key: 'fin_faturamento', label: 'Faturamento', type: 'number', filter: false, accessor: (e) => fget(e, 'faturamento') };
            cols.fin_meta        = { key: 'fin_meta', label: 'Meta', type: 'number', filter: false, accessor: (e) => fget(e, 'meta') };
            cols.fin_pct         = { key: 'fin_pct', label: '%', type: 'number', filter: false, accessor: (e) => fget(e, 'pct') };
            cols.fin_ads         = { key: 'fin_ads', label: 'ADS', type: 'number', filter: false, accessor: (e) => fget(e, 'ads') };
            cols.fin_status      = { key: 'fin_status', label: 'Status', accessor: (e) => fget(e, 'status'), format: (v) => (v === VAZIO ? '(Sem dado)' : (STATUS_META[v]?.label ?? v)) };
        }
        return cols;
    }, [isAdmin, fin, respNome]);

    // Colunas visíveis da lente ativa (na Geral = planilha completa; fin_* só p/ admin).
    const colsVisiveis = useMemo(() => colsDaLente(lente, isAdmin), [lente, isAdmin]);
    // Busca global casa por NOME + cust_id (bruto e normalizado) + polo — assim digitar o
    // cust_id da loja no campo já traz a empresa (espelha o filtro de "Arquivados").
    const matchBusca = useCallback(
        (e, q) => `${e.nome ?? ''} ${e.cust_id ?? ''} ${e.cust_norm ?? ''} ${e.polo ?? ''}`.toLowerCase().includes(q),
        [],
    );
    // Base do AutoFiltro: SÓ as fases em operação (FASES_ESCOPO), a menos que o toggle esteja
    // desligado. Tudo que lê `af` — donuts do Centro de Operações, funis dos cabeçalhos, contagens
    // e a grade — passa a enxergar o mesmo recorte. A aba Metas continua lendo `empresas` inteiro
    // de propósito: Entrantes (M0) e as metas por região dependem das fases de fora.
    const empresasEscopo = useMemo(
        () => (soEscopo ? empresas.filter((e) => FASES_ESCOPO.includes(e.fase)) : empresas),
        [empresas, soEscopo],
    );
    const nForaDoEscopo = empresas.length - empresasEscopo.length;

    const af = useAutoFilter(empresasEscopo, COLUNAS, { search: busca, matchSearch: matchBusca, storageKey: 'polos-painel-af', visibleKeys: colsVisiveis });
    const filtradas = af.filtered;

    // Indicador acionável → filtra + navega p/ a lente da coluna (ou limpa, se já isolado).
    const irPara = useCallback((col, value) => {
        if (af.isOnly(col, value)) { af.clearColumn(col); return; }
        const alvoLente = COL_LENTE[col];
        if (alvoLente) setLente(alvoLente);
        af.setOnly(col, value);
    }, [af]);

    // ── Seleção em massa ──
    // A seleção guarda ids (persiste entre lentes/filtros), mas o ALVO efetivo de qualquer
    // ação é sempre a interseção com o que está VISÍVEL — nunca edita linha oculta pelo filtro.
    const idsVisiveis = useMemo(() => filtradas.map((e) => e.id), [filtradas]);
    const idsAlvo     = useMemo(() => idsVisiveis.filter((id) => selecionadas.has(id)), [idsVisiveis, selecionadas]);
    const nAlvo       = idsAlvo.length;
    const headerSel   = idsVisiveis.length > 0 && nAlvo === idsVisiveis.length ? 'on' : nAlvo > 0 ? 'ind' : 'off';
    const semFichaSel = useMemo(() => filtradas.filter((e) => selecionadas.has(e.id) && !e.impl_id).length, [filtradas, selecionadas]);

    // Poda ids "fantasma" que saíram do payload `empresas` (ex.: mudaram de projeto no lote).
    useEffect(() => {
        setSelecionadas((prev) => {
            if (prev.size === 0) return prev;
            const vivos = new Set(empresas.map((e) => e.id));
            const next = new Set([...prev].filter((id) => vivos.has(id)));
            return next.size === prev.size ? prev : next;
        });
    }, [empresas]);
    // Âncora que saiu da vista deixa de valer p/ o shift-range.
    useEffect(() => { if (ancora != null && !idsVisiveis.includes(ancora)) setAncora(null); }, [idsVisiveis, ancora]);

    const toggleLinha = useCallback((id, idx, shift) => {
        setSelecionadas((prev) => {
            const n = new Set(prev);
            const a = ancora != null ? idsVisiveis.indexOf(ancora) : -1;
            if (shift && a !== -1) {
                const marcar = !n.has(id); // segue a ação no item clicado
                const [lo, hi] = a < idx ? [a, idx] : [idx, a];
                for (let i = lo; i <= hi; i++) { if (marcar) n.add(idsVisiveis[i]); else n.delete(idsVisiveis[i]); }
            } else if (shift) { n.add(id); } // âncora perdida: seleção pura (nunca desmarca por engano)
            else if (n.has(id)) { n.delete(id); } else { n.add(id); }
            return n;
        });
        setAncora(id);
    }, [ancora, idsVisiveis]);

    const toggleTodasVisiveis = () => setSelecionadas((prev) => {
        const n = new Set(prev);
        if (headerSel === 'on') idsVisiveis.forEach((id) => n.delete(id));
        else idsVisiveis.forEach((id) => n.add(id));
        return n;
    });
    const limparSelecao = () => { setSelecionadas(new Set()); setAncora(null); };

    // ── Aplicar / desfazer (endpoint de lote transacional; recarrega só `empresas`) ──
    const postLote = useCallback((payload, aoConcluir) => {
        setLoteBusy(true);
        window.axios.post(route('mlb.polos-painel.bulk'), payload, { headers: { 'X-CSRF-TOKEN': csrf_token } })
            .then(({ data }) => { aoConcluir?.(data); router.reload({ only: ['empresas'], preserveScroll: true, preserveState: true }); })
            .catch(() => setUndoInfo({ erro: true }))
            .finally(() => setLoteBusy(false));
    }, [csrf_token]);

    const aplicarLote = useCallback((changes, opts = {}) => {
        if (!nAlvo || loteBusy) return;
        setUndoData(null); // limpa snapshot velho: se ESTE aplicar falhar, não oferece "desfazer" enganoso
        postLote({ ids: idsAlvo, changes }, (data) => {
            setUndoData(data?.undo?.items?.length ? data.undo : null);
            setUndoInfo({ aplicadas: data?.aplicadas ?? 0, ignoradas: data?.ignoradas ?? [] });
            if (opts.fecharDrawer) setDrawerLote(false);
        });
    }, [idsAlvo, nAlvo, loteBusy, postLote]);

    const desfazerLote = useCallback(() => {
        if (!undoData?.items?.length || loteBusy) return;
        postLote({ items: undoData.items }, () => { setUndoData(null); setUndoInfo(null); });
    }, [undoData, loteBusy, postLote]);

    // Auto-oculta o toast só no SUCESSO; no erro fica até o usuário fechar (permite re-tentar o desfazer).
    useEffect(() => {
        if (!undoInfo || undoInfo.erro) return;
        const t = setTimeout(() => { setUndoInfo(null); setUndoData(null); }, 8000);
        return () => clearTimeout(t);
    }, [undoInfo]);

    // ── Opções das ações rápidas + config do drawer multi-campo ──
    const opcFase  = useMemo(() => [...ORDEM_FASE, ...valoresPresentes.fase.filter((f) => !ORDEM_FASE.includes(f))].map((f) => ({ value: f, label: f })), [valoresPresentes.fase]);
    const opcPolo  = useMemo(() => [...new Set([...(opcoes.polo ?? []), ...valoresPresentes.polo])].map((p) => ({ value: p, label: p })), [opcoes.polo, valoresPresentes.polo]);
    const opcResp  = useMemo(() => [{ value: '', label: 'Sem responsável' }, ...usuarios.map((u) => ({ value: String(u.id), label: u.name }))], [usuarios]);
    const opcEnvio = [{ value: 'enviado', label: 'Marcar enviado' }, { value: 'falta_enviar', label: 'Marcar pendente' }];
    const enumOpc  = (campo) => (opcoes[campo] ?? []).map((o) => ({ value: o, label: o }));

    const camposLote = useMemo(() => [
        { campo: 'fase',               label: 'Fase',               tipo: 'select', opcoes: opcFase },
        { campo: 'polo',               label: 'Polo',               tipo: 'select', opcoes: opcPolo },
        { campo: 'responsavel_id',     label: 'Responsável',        tipo: 'select', opcoes: opcResp },
        { campo: 'status_envio',       label: 'Envio do link',      tipo: 'select', opcoes: opcEnvio },
        { campo: 'status_entrada',     label: 'Status entrada',     tipo: 'select', opcoes: enumOpc('status_entrada') },
        { campo: 'chance_entrada',     label: 'Chance entrada',     tipo: 'select', opcoes: enumOpc('chance_entrada') },
        { campo: 'reuniao_onboarding', label: 'Reunião onboarding', tipo: 'select', opcoes: enumOpc('reuniao_onboarding') },
        { campo: 'acesso_colaborador', label: 'Acesso colaborador', tipo: 'select', opcoes: enumOpc('acesso_colaborador') },
        { campo: 'planilha_produtos',  label: 'Planilha produtos',  tipo: 'select', opcoes: enumOpc('planilha_produtos') },
        { campo: 'listagem',           label: 'Listagem',           tipo: 'select', opcoes: enumOpc('listagem') },
        { campo: 'publicacao',         label: 'Publicação',         tipo: 'select', opcoes: enumOpc('publicacao') },
        { campo: 'decola',             label: 'Decola',             tipo: 'select', opcoes: enumOpc('decola') },
        { campo: 'central_promocao',   label: 'Central de Promoção', tipo: 'select', opcoes: enumOpc('central_promocao') },
        { campo: 'me1',                label: 'ME1',                tipo: 'select', opcoes: enumOpc('me1') },
        { campo: 'integradora',        label: 'Integradora',        tipo: 'select', opcoes: enumOpc('integradora') },
        { campo: 'places',             label: 'Places',             tipo: 'select', opcoes: enumOpc('places') },
        { campo: 'erp',                label: 'ERP',                tipo: 'select', opcoes: enumOpc('erp') },
        { campo: 'grupo_whatsapp',     label: 'Grupo WhatsApp',     tipo: 'bool' },
        { campo: 'campanha_criada',    label: 'Campanha',           tipo: 'bool' },
        { campo: 'data_solicitacao',   label: 'Data solicitação',   tipo: 'date' },
    ], [opcFase, opcPolo, opcResp, opcoes]);

    const aplicarFase = (v) => {
        if (FASES_TERMINAIS.includes(v) && !window.confirm(`Mover ${nAlvo} empresa(s) para "${v}"? Elas saem dos polos ativos.`)) return;
        aplicarLote({ fase: v });
    };

    // ── KPIs macro (do cockpit async) ──
    const totalFat    = polosCk.reduce((a, p) => a + (p.faturamento ?? 0), 0);
    const totalAtivos = polosCk.reduce((a, p) => a + (p.ativos ?? 0), 0);
    // Meta ÚNICA de faturamento (alvo global editável), NÃO a soma das metas por empresa.
    const metaFat     = metaFatOverride ?? cockpit?.metaFaturamento ?? 3200000;
    const pctGeral    = metaFat > 0 ? totalFat / metaFat * 100 : 0;
    const alertas = useMemo(() => {
        let ads = 0, prob = 0, n = 0;
        polosCk.forEach((p) => (p.empresas ?? []).forEach((e) => {
            const a = e.ads_desligado === true, pr = e.problema === true;
            if (a) ads++; if (pr) prob++; if (a || pr) n++;
        }));
        return { ads, prob, n };
    }, [polosCk]);

    // ── Ações (reusam rotas existentes; preserveState mantém lente/filtros/financeiro) ──
    const reloadOpts = { preserveScroll: true, preserveState: true };
    const salvarCampo = (e, campo, valor) => {
        if (!e.impl_id) {
            if (SEM_FICHA_OK.includes(campo)) {
                if (campo === 'fase' && FASES_TERMINAIS.includes(valor) &&
                    !window.confirm(`Mover "${e.nome}" para ${valor}? Sai dos polos ativos.`)) return;
                router.put(route('mlb.empresas.update', e.id), { ...(e.payload_empresa ?? {}), [campo]: valor }, reloadOpts);
            }
            return; // demais campos exigem ficha
        }
        if (campo === 'fase' && FASES_TERMINAIS.includes(valor) &&
            !window.confirm(`Mover "${e.nome}" para ${valor}? Sai dos polos ativos.`)) return;
        router.patch(route('mlb.implementacao.bloco.' + BLOCO_DE[campo], e.impl_id), { [campo]: valor }, reloadOpts);
    };
    const trocarResponsavel = (e, v) =>
        router.patch(route('mlb.implementacao.responsavel', e.impl_id), { responsavel_id: v === SEM_RESP ? null : Number(v) }, reloadOpts);
    // `desconsidera` decide se o problema tira a empresa da meta (status Problema na
    // Distribuição) ou se ela segue contando em No alvo / Em progresso / Não.
    const toggleProblema = (e, desconsidera = false) =>
        router.patch(route('mlb.empresas.problema', e.id), { acao: 'toggle', desconsidera_meta: desconsidera }, reloadOpts);
    const salvarNota = (e) =>
        router.patch(route('mlb.empresas.problema', e.id),
            { acao: 'editar', problema_nota: editNota[e.id] ?? '', desconsidera_meta: e.problema_desconsidera_meta === true },
            { ...reloadOpts, onSuccess: () => setEditNota((s) => { const n = { ...s }; delete n[e.id]; return n; }) });
    // Só troca o flag de meta, preservando o problema e a nota.
    const alternarMeta = (e, desconsidera) =>
        router.patch(route('mlb.empresas.problema', e.id), { acao: 'meta', desconsidera_meta: desconsidera }, reloadOpts);
    const removerProblema = (e) => { if (window.confirm('Remover o problema desta conta?')) router.patch(route('mlb.empresas.problema', e.id), { acao: 'remover' }, reloadOpts); };
    const marcarEnviado = (e) => router.post(route('mlb.implementacao.marcar-enviado', e.impl_id), {}, reloadOpts);
    const desfazerEnvio = (e) => router.post(route('mlb.implementacao.desfazer-envio', e.impl_id), {}, reloadOpts);
    const criarOnboarding = (e) => { if (window.confirm(`Criar ficha de onboarding para "${e.nome}"?`)) router.post(route('mlb.implementacao.gerar', e.id), {}, reloadOpts); };
    // Arquivar/desarquivar (aba Arquivados): reversível; recarrega empresas + arquivadas.
    const arquivar = (e) => {
        if (!window.confirm(`Arquivar "${e.nome}"?\n\nEla sai do Painel e não conta mais em metas, faturamento nem cockpit. É reversível pela aba "Arquivados".`)) return;
        router.post(route('mlb.polos-painel.arquivar', e.id), {}, reloadOpts);
    };
    const desarquivar = (e) => router.post(route('mlb.polos-painel.desarquivar', e.id), {}, reloadOpts);
    // Salva SÓ o cust_id (endpoint dedicado que não zera os demais campos da empresa).
    const salvarCustId = (e, valor) =>
        router.patch(route('mlb.empresas.cust-id', e.id), { cust_id: String(valor ?? '').trim() }, reloadOpts);
    const sincronizar = () =>
        router.post(route('polos.sync'), mesEfetivo ? { mes: mesEfetivo } : {}, {
            preserveScroll: true, preserveState: true,
            onStart: () => setSincronizando(true),
            onSuccess: () => setSyncMsg('Sincronização iniciada — atualiza em alguns minutos.'),
            onFinish: () => setSincronizando(false),
        });

    const toggleExpandir = (e) => {
        const abrindo = expandida !== e.id;
        setExpandida(abrindo ? e.id : null);
        if (abrindo && isAdmin && e.cust_id && !semanal[e.cust_id]) {
            setSemanal((s) => ({ ...s, [e.cust_id]: { loading: true } }));
            fetch(route('polos.empresa.semanal', { cust: e.cust_id, mes: mesEfetivo }))
                .then((r) => r.json())
                .then((d) => setSemanal((s) => ({ ...s, [e.cust_id]: { ...d, loading: false } })))
                .catch(() => setSemanal((s) => ({ ...s, [e.cust_id]: { erro: true, loading: false } })));
        }
    };

    // Grava a meta de entrantes de uma região/mês (aba Metas): otimista + POST assíncrono.
    const salvarMetaEntrada = useCallback((polo, mes, meta) => {
        const n = Math.max(0, parseInt(meta, 10) || 0);
        setMetas((prev) => [...prev.filter((m) => !(m.polo === polo && m.mes === mes)), { polo, mes, meta: n }]);
        window.axios.post(route('mlb.polos-painel.meta-entrada'), { polo, mes, meta: n }, { headers: { 'X-CSRF-TOKEN': csrf_token } }).catch(() => {});
    }, [csrf_token]);

    // Abre a edição da meta de faturamento com o valor atual (em R$ inteiros) no input.
    const abrirEdicaoMeta = useCallback(() => {
        setMetaInput(String(Math.round(metaFat)));
        setEditandoMeta(true);
    }, [metaFat]);

    // Grava a meta ÚNICA de faturamento (aceita "3.200.000" / "3200000"): otimista + POST.
    const salvarMetaFat = useCallback(() => {
        const n = Math.max(0, Math.round(Number(String(metaInput).replace(/[^\d]/g, '')) || 0));
        setMetaFatOverride(n);
        setEditandoMeta(false);
        window.axios.post(route('mlb.polos-painel.meta-faturamento'), { meta: n }, { headers: { 'X-CSRF-TOKEN': csrf_token } }).catch(() => {});
    }, [metaInput, csrf_token]);

    const handlers = { salvarCampo, trocarResponsavel, toggleProblema, alternarMeta, salvarNota, removerProblema, marcarEnviado, desfazerEnvio, criarOnboarding, arquivar, toggleExpandir, verEmpresa: setVerModal, salvarCustId };

    // ── Modo TELA CHEIA (planilha): overlay que estoura sidebar/max-width + Fullscreen API. ──
    const toggleTelaCheia = useCallback(() => {
        setTelaCheia((v) => {
            const next = !v;
            try {
                if (next && !document.fullscreenElement) document.documentElement.requestFullscreen?.();
                else if (!next && document.fullscreenElement) document.exitFullscreen?.();
            } catch (_) { /* browser pode bloquear a Fullscreen API — o overlay CSS já resolve */ }
            return next;
        });
    }, []);
    // Sai do modo se o usuário sair do fullscreen do browser (Esc/F11).
    useEffect(() => {
        const onFs = () => { if (!document.fullscreenElement) { setTelaCheia(false); setModoTv(false); } };
        document.addEventListener('fullscreenchange', onFs);
        return () => document.removeEventListener('fullscreenchange', onFs);
    }, []);

    // ── MODO TV: painel de parede. Mesma Fullscreen API da tela cheia, mas o conteúdo é
    // outro (cenas que se revezam), então os dois modos são exclusivos. ──
    const toggleModoTv = useCallback(() => {
        setModoTv((v) => {
            const next = !v;
            if (next) setTelaCheia(false);
            try {
                if (next && !document.fullscreenElement) document.documentElement.requestFullscreen?.();
                else if (!next && document.fullscreenElement) document.exitFullscreen?.();
            } catch (_) { /* browser pode bloquear a Fullscreen API — o overlay CSS já resolve */ }
            return next;
        });
    }, []);

    // Recarga automática dos dados enquanto a TV está ligada: `only` mantém o payload
    // enxuto (empresas + metas), e o finTick refaz o fetch do cockpit financeiro.
    const atualizarDadosTv = useCallback(() => {
        router.reload({ only: ['empresas', 'metasEntrada', 'arquivadas'], preserveScroll: true, preserveState: true });
        if (isAdmin) setFinTick((t) => t + 1);
    }, [isAdmin]);
    // Esc sai da tela cheia (exceto se um modal estiver aberto — aí o Esc é dele).
    useEffect(() => {
        if (!telaCheia) return;
        const onKey = (e) => { if (e.key === 'Escape' && !verModal && !drawerLote) setTelaCheia(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [telaCheia, verModal, drawerLote]);

    return (
        <AppLayout title="Painel Polos">
            <Head title="Painel Polos" />

            <div className={cn('space-y-5', telaCheia ? 'fixed inset-0 z-30 flex flex-col bg-ecf-bg p-4 lg:p-6' : 'max-w-[1600px] mx-auto')}>

                {/* ── Cabeçalho ── */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Painel Polos</h1>
                        <p className="text-white/40 text-sm mt-0.5">
                            Filtre, edite inline e siga — sem abrir ficha. {empresasEscopo.length} empresas Polos
                            {soEscopo ? ' em operação (M1–M4)' : ' (todas as fases)'}.
                        </p>
                    </div>
                    {isAdmin && (
                        <div className="flex flex-col items-end gap-1.5">
                            <div className="flex items-center gap-2">
                                <button type="button" onClick={sincronizar} disabled={sincronizando}
                                    title="Aquece faturamento/ADS do mês na Adman (background)"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-3 py-1.5 text-sm font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/20 disabled:opacity-50">
                                    <RefreshCw size={14} className={cn(sincronizando && 'animate-spin')} />
                                    {sincronizando ? 'Iniciando…' : 'Sincronizar'}
                                </button>
                                {(cockpit?.meses ?? []).length > 0 && (
                                    <>
                                        <label className="text-white/40 text-xs uppercase tracking-wider ml-1">Mês</label>
                                        <select value={mesEfetivo ?? ''} onChange={(ev) => setMes(ev.target.value)}
                                            className="rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40">
                                            {(cockpit?.meses ?? []).map((m) => (
                                                <option key={m.value} value={m.value} className="bg-ecf-card text-white">{m.label}{m.parcial ? ' (parcial)' : ''}</option>
                                            ))}
                                        </select>
                                    </>
                                )}
                            </div>
                            {syncMsg && <span className="text-[11px] text-ecf-yellow/80">{syncMsg}</span>}
                        </div>
                    )}
                </div>

                {/* ── Cockpit financeiro (admin) — recolhido por padrão; oculto na tela cheia ── */}
                {isAdmin && !telaCheia && (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.015]">
                        <button type="button" onClick={() => setCockpitAberto((v) => !v)}
                            className="flex w-full items-center gap-2 px-4 py-3 text-white/60 hover:text-white text-sm font-semibold transition">
                            {cockpitAberto ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                            Faturamento Polos
                            {finLoading && <RefreshCw size={13} className="animate-spin text-white/30" />}
                            {!cockpitAberto && cockpit && (
                                <span className="ml-2 text-[12px] font-normal text-white/40">
                                    {formatCurrency(totalFat)} / {formatCurrency(metaFat)} · {pctGeral.toFixed(0)}% · {totalAtivos} ativos
                                    {!fechado && alertas.n > 0 ? ` · ${alertas.n} alertas` : ''}
                                </span>
                            )}
                        </button>
                        {cockpitAberto && (
                            <div className="px-4 pb-4 space-y-4">
                                {cockpit?.erro ? (
                                    <div className="flex items-start gap-3 rounded-xl p-3 border border-red-500/20 bg-red-500/[0.06]">
                                        <AlertTriangle size={16} className="text-red-400 mt-0.5" />
                                        <p className="text-red-300 text-sm">{cockpit.erro}</p>
                                    </div>
                                ) : !cockpit ? (
                                    <p className="text-white/30 text-sm py-4 inline-flex items-center gap-2"><RefreshCw size={13} className="animate-spin" /> Carregando financeiro…</p>
                                ) : (
                                    <>
                                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                                            <HeroKpi titulo="Faturamento total" valor={formatCurrency(totalFat)} icone={Wallet} glow="yellow"
                                                sublabel={cockpit.mesRefLabel ? `${cockpit.mesRefLabel} · ${parcial ? 'parcial' : 'fechado'}` : null} />
                                            <HeroKpi titulo="% Geral da meta" valor={`${pctGeral.toFixed(0)}%`} icone={Target} glow="yellow"
                                                sublabel={`${formatCurrency(totalFat)} / ${formatCurrency(metaFat)}`}
                                                extra={editandoMeta ? (
                                                    <div className="flex items-center gap-1.5">
                                                        <div className="relative flex-1">
                                                            <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[11px] text-white/40">R$</span>
                                                            <input
                                                                type="text" inputMode="numeric" autoFocus value={metaInput}
                                                                onChange={(ev) => setMetaInput(ev.target.value)}
                                                                onKeyDown={(ev) => { if (ev.key === 'Enter') salvarMetaFat(); if (ev.key === 'Escape') setEditandoMeta(false); }}
                                                                className="w-full rounded-lg border border-ecf-yellow/40 bg-ecf-bg pl-7 pr-2 py-1 text-[12px] text-white/90 tabular-nums outline-none focus:border-ecf-yellow"
                                                                placeholder="3200000" />
                                                        </div>
                                                        <button type="button" onClick={salvarMetaFat} title="Salvar meta"
                                                            className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-1.5 text-emerald-300 transition hover:bg-emerald-500/20"><Check size={13} /></button>
                                                        <button type="button" onClick={() => setEditandoMeta(false)} title="Cancelar"
                                                            className="rounded-lg border border-white/10 bg-white/[0.04] p-1.5 text-white/50 transition hover:text-white/80"><X size={13} /></button>
                                                    </div>
                                                ) : (
                                                    <button type="button" onClick={abrirEdicaoMeta}
                                                        className="inline-flex items-center gap-1 text-[11px] text-white/40 transition hover:text-ecf-yellow">
                                                        <Pencil size={11} /> Editar meta
                                                    </button>
                                                )} />
                                            <HeroKpi titulo="Empresas ativas" valor={totalAtivos} icone={Building2}
                                                sublabel={`${polosCk.length} ${polosCk.length === 1 ? 'polo' : 'polos'}`} />
                                            <HeroKpi titulo="Alertas" valor={fechado ? '—' : alertas.n} icone={AlertTriangle}
                                                glow={!fechado && alertas.n > 0 ? 'rose' : 'none'} alerta={!fechado && alertas.n > 0}
                                                sublabel={fechado ? 'sem ADS no mês fechado' : `${alertas.ads} ads off · ${alertas.prob} problema`} />
                                        </div>
                                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                            <div className={cn(CARD, 'lg:col-span-2')}>
                                                <h3 className="text-white/70 text-sm font-semibold mb-3">Faturamento vs Meta</h3>
                                                <FatVsMetaChart polos={polosCk} corDoPolo={corDoPolo} fonteFaturamento={cockpit.fonteFaturamento} parcial={parcial} />
                                            </div>
                                            <div className={CARD}>
                                                <h3 className="text-white/70 text-sm font-semibold mb-3">Distribuição de status</h3>
                                                <StatusDonut statusDist={cockpit.statusDist} height={240} />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                            <div className={CARD}>
                                                <div className="mb-3">
                                                    <h3 className="text-white/70 text-sm font-semibold">Ranking de % da meta</h3>
                                                    {/* A barra é a Distribuição de status recortada por região — somando os polos dá o donut acima. */}
                                                    <p className="text-white/35 text-[11px]">Distribuição de status por região · ordenado por % no alvo</p>
                                                </div>
                                                <RankingProgresso polos={polosCk} corDoPolo={corDoPolo} onPolo={(p) => (p?.polo ? af.setOnly('polo', p.polo) : af.clearColumn('polo'))} fechado={fechado} />
                                            </div>
                                            <div className={CARD}>
                                                <h3 className="text-white/70 text-sm font-semibold mb-3">Saldo de ADS</h3>
                                                <AdsCard polos={polosCk} teto={cockpit.adsLimites?.teto ?? 3000} fechado={fechado} onPolo={(p) => (p?.polo ? af.setOnly('polo', p.polo) : af.clearColumn('polo'))} />
                                            </div>
                                            <div className={CARD}>
                                                <h3 className="text-white/70 text-sm font-semibold mb-3">Coorte M1</h3>
                                                <M1Card m1={cockpit.m1} fechado={fechado} />
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {/* ── Centro de Operações (indicadores acionáveis) — oculto na aba Metas e na tela cheia ── */}
                {lente !== 'metas' && !telaCheia && <OperacoesPanel af={af} irPara={irPara} toneValor={toneValor} toneFase={toneFase} lente={lente} />}

                {/* ── Filtros ativos (breadcrumb clicável p/ remover) — oculto na aba Metas ── */}
                {lente !== 'metas' && af.activeCount > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-white/35 text-[11px] font-semibold uppercase tracking-wider">Filtros ativos:</span>
                        {af.activeSummary().map((f) => (
                            <button key={f.key} type="button" onClick={() => af.clearColumn(f.key)}
                                title="Remover este filtro"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-2 py-1 text-[11px] text-ecf-yellow transition hover:bg-ecf-yellow/15">
                                <span className="font-semibold">{f.label}:</span>
                                <span className="max-w-[220px] truncate text-white/80">
                                    {f.nMostrados === 0 ? '(nenhum)' : f.nMostrados <= 3 ? f.valores.join(', ') : `${f.nMostrados} de ${f.nPresentes}`}
                                </span>
                                <X size={11} />
                            </button>
                        ))}
                    </div>
                )}

                {/* ── Barra global (o resto da filtragem vive nos cabeçalhos) — oculta na aba Metas ── */}
                {lente !== 'metas' && (
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white/40 text-[12px] shrink-0">
                        Filtre pelos <span className="text-white/70 font-medium">funis</span> nos cabeçalhos das colunas.
                    </span>
                    {(af.activeCount > 0 || af.sort) && (
                        <button type="button" onClick={af.clearAll}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-2.5 py-1.5 text-[12px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/15">
                            <X size={12} /> Limpar todos os filtros{af.activeCount > 0 ? ` (${af.activeCount})` : ''}
                        </button>
                    )}
                    <div className="relative ml-auto">
                        <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                        <input type="text" value={busca} onChange={(ev) => setBusca(ev.target.value)} placeholder="Buscar empresa ou cust_id…"
                            className="w-52 rounded-lg border border-white/[0.08] bg-white/[0.03] pl-8 pr-3 py-1.5 text-[12px] text-white/90 outline-none focus:border-ecf-yellow/40" />
                    </div>
                    <button type="button" onClick={() => setSoEscopo((v) => !v)}
                        title={soEscopo
                            ? `Mostrando só M1–M4. ${nForaDoEscopo} empresa(s) fora do escopo (Churn, Protocolo Churn, Encerrado, Aceite no Projeto, M0, Fechamento, sem fase) estão ocultas — clique para incluir.`
                            : 'Mostrando todas as fases, inclusive Churn/Encerrado — clique para voltar ao escopo M1–M4.'}
                        className={cn('inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12px] font-semibold transition shrink-0',
                            soEscopo ? 'border-ecf-yellow/30 bg-ecf-yellow/[0.08] text-ecf-yellow hover:bg-ecf-yellow/15'
                                     : 'border-white/[0.1] bg-white/[0.03] text-white/50 hover:text-white/80')}>
                        <Filter size={12} /> {soEscopo ? 'Só M1–M4' : 'Todas as fases'}
                        {soEscopo && nForaDoEscopo > 0 && <span className="text-white/40 tabular-nums">(+{nForaDoEscopo})</span>}
                    </button>
                    <span className="text-white/30 text-[12px] tabular-nums shrink-0">{filtradas.length}/{empresasEscopo.length}</span>
                </div>
                )}

                {/* ── Lentes + Tela cheia ── */}
                <div className="flex items-center justify-between gap-2 flex-wrap">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        {LENTES.map((l) => (
                            <button key={l.key} type="button" onClick={() => setLente(l.key)}
                                className={cn('rounded-lg px-3.5 py-1.5 text-[13px] font-semibold transition-all border',
                                    lente === l.key ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                                    : 'border-transparent text-white/45 hover:text-white/80 hover:bg-white/[0.04]')}>
                                {l.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <button type="button" onClick={() => setMostrarArquivadas(true)}
                            title="Empresas arquivadas (fora do projeto Polos — não contam em nada)"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.1] bg-white/[0.04] px-3 py-1.5 text-[12px] font-semibold text-white/80 transition hover:border-white/25 hover:bg-white/[0.08]">
                            <Archive size={13} /> Arquivados
                            {arquivadas.length > 0 && (
                                <span className="ml-0.5 rounded-full bg-white/[0.12] px-1.5 py-0.5 text-[10px] tabular-nums text-white/70">{arquivadas.length}</span>
                            )}
                        </button>
                        <button type="button" onClick={toggleModoTv}
                            title={lente === 'metas'
                                ? 'Painel de parede da TV — abre a tela de METAS (entrantes × meta do mês). Setas ← → alternam para Faturamento.'
                                : 'Painel de parede da TV — abre a tela de FATURAMENTO POLOS. Setas ← → alternam para Metas.'}
                            className={cn('inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-[12px] font-semibold transition',
                                modoTv ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                       : 'border-white/[0.1] bg-white/[0.04] text-white/80 hover:border-white/25 hover:bg-white/[0.08]')}>
                            <Tv size={13} /> Modo TV
                        </button>
                        <button type="button" onClick={toggleTelaCheia}
                            title={telaCheia ? 'Sair da tela cheia (Esc)' : 'Abrir a planilha em tela cheia'}
                            className={cn('inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-[12px] font-semibold transition',
                                telaCheia ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                          : 'border-white/[0.1] bg-white/[0.04] text-white/80 hover:border-white/25 hover:bg-white/[0.08]')}>
                            {telaCheia ? <Minimize2 size={13} /> : <Maximize2 size={13} />}
                            {telaCheia ? 'Sair da tela cheia' : 'Tela cheia'}
                        </button>
                    </div>
                </div>

                {/* ── Aba Metas (Entrantes M0 | Visão geral) OU Grade (planilha das lentes) ── */}
                {lente === 'metas' ? (
                    <div className="space-y-4">
                        {/* Sub-toggle da lente Metas */}
                        <div className="inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5">
                            {[['entrantes', 'Entrantes (M0)'], ['torre', 'Visão geral']].map(([k, lbl]) => (
                                <button key={k} type="button" onClick={() => setMetaView(k)}
                                    className={cn('rounded-md px-3 py-1.5 text-[12px] font-semibold transition',
                                        metaView === k ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'text-white/50 hover:text-white/80')}>
                                    {lbl}
                                </button>
                            ))}
                        </div>

                        {metaView === 'entrantes' ? (
                            <EntrantesM0Panel empresas={empresas} regioes={opcoes.polo ?? []} metasEntrada={metas} />
                        ) : (
                            <MetasPanel
                                empresas={empresas}
                                regioes={opcoes.polo ?? []}
                                metasEntrada={metas}
                                onSalvarMeta={salvarMetaEntrada}
                                fin={fin}
                                finLoaded={fin !== null}
                                finErro={finErro}
                                isAdmin={isAdmin}
                            />
                        )}
                    </div>
                ) : (
                /* Altura limitada + overflow-auto: a barra de rolagem HORIZONTAL passa a ficar no
                    rodapé de uma caixa do tamanho da tela (não no fim de TODAS as linhas) — dá pra ir
                    pro lado sem descer tudo. thead sticky (nomes acompanham a rolagem vertical) e as 2
                    colunas fixas (Seleção + Empresa) congeladas à esquerda (empresa visível ao rolar). */
                <div className={cn('rounded-2xl border border-white/[0.08] bg-white/[0.02] overflow-auto', telaCheia ? 'min-h-0 flex-1' : 'max-h-[80vh]')}>
                    <table className="w-full text-left border-collapse">
                        <thead className="sticky top-0 z-20 bg-ecf-card shadow-[inset_0_-1px_0_0_rgba(255,255,255,0.12)]">
                            <tr className="border-b border-white/[0.12] bg-white/[0.02]">
                                <th className="sticky left-0 z-30 bg-ecf-card w-10 px-3 py-3">
                                    <button type="button" onClick={toggleTodasVisiveis} title="Selecionar todas as filtradas" className="align-middle">
                                        <CaixaSel state={headerSel} />
                                    </button>
                                </th>
                                <th className="sticky left-10 z-30 bg-ecf-card px-3 py-3 text-white/45 text-[11px] font-semibold uppercase tracking-wider min-w-[220px]">
                                    <div className="inline-flex items-center gap-1">
                                        <span>Empresa</span>
                                        <ColumnFilter
                                            label="Situação" showLabel={false} sortable={false}
                                            getOptions={() => af.optionsFor('situacao')}
                                            active={af.isColumnActive('situacao')}
                                            onToggle={(v) => af.toggleValue('situacao', v)}
                                            onSelectAll={(vals, chk) => af.selectAll('situacao', vals, chk)}
                                            onClear={() => af.clearColumn('situacao')}
                                            onSort={() => {}}
                                        />
                                    </div>
                                </th>
                                <CabecalhoLente keys={colsVisiveis} af={af} colunas={COLUNAS} />
                            </tr>
                        </thead>
                        <tbody>
                            {filtradas.length === 0 && (
                                <tr><td colSpan={13} className="px-4 py-12 text-center text-sm text-white/20">
                                    {empresasEscopo.length === 0 ? (empresas.length === 0 ? 'Nenhuma empresa Polos cadastrada.' : 'Nenhuma empresa em M1–M4. Use "Todas as fases" para ver as demais.') : (
                                        <div className="inline-flex flex-col items-center gap-2">
                                            <span>Nenhuma empresa neste filtro.</span>
                                            {af.activeCount > 0 && (
                                                <button type="button" onClick={af.clearAll}
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-3 py-1.5 text-[12px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/15">
                                                    <X size={13} /> Limpar filtros
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </td></tr>
                            )}
                            {filtradas.map((e, idx) => (
                                <LinhaPainel
                                    key={e.id}
                                    e={e}
                                    idx={idx}
                                    selecionada={selecionadas.has(e.id)}
                                    onToggleSel={toggleLinha}
                                    lente={lente}
                                    isAdmin={isAdmin}
                                    opcoes={opcoes}
                                    valoresPresentes={valoresPresentes}
                                    usuarios={usuarios}
                                    appUrl={appUrl}
                                    fin={finDe(e)}
                                    finLoaded={fin !== null}
                                    fechado={fechado}
                                    adsLimites={adsLimites}
                                    semanal={e.cust_id ? semanal[e.cust_id] : null}
                                    aberta={expandida === e.id}
                                    editNota={editNota}
                                    setEditNota={setEditNota}
                                    on={handlers}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
                )}
            </div>

            {/* ── Edição em massa (aparece só quando há seleção) ── */}
            <BulkActionBar count={nAlvo} onClear={limparSelecao} busy={loteBusy}>
                <AcaoLote icon={GitBranch}  label="Fase"        opcoes={opcFase}  onPick={aplicarFase} />
                <AcaoLote icon={MapPin}     label="Polo"        opcoes={opcPolo}  onPick={(v) => aplicarLote({ polo: v })} busca />
                <AcaoLote icon={Users}      label="Responsável" opcoes={opcResp}  onPick={(v) => aplicarLote({ responsavel_id: v })} busca />
                <AcaoLote icon={Send}       label="Envio"       opcoes={opcEnvio} onPick={(v) => aplicarLote({ status_envio: v })} />
                <button type="button" onClick={() => setDrawerLote(true)}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-2.5 py-1.5 text-[12px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/15">
                    <SlidersHorizontal size={13} /> Editar vários…
                </button>
                {loteBusy && <RefreshCw size={14} className="animate-spin text-white/50" />}
            </BulkActionBar>

            <DrawerLote
                aberto={drawerLote} onFechar={() => setDrawerLote(false)} count={nAlvo}
                semFichaCount={semFichaSel} campos={camposLote} busy={loteBusy}
                onAplicar={(changes) => aplicarLote(changes, { fecharDrawer: true })} />

            <ToastLote
                info={undoInfo} temUndo={!!undoData?.items?.length} onUndo={desfazerLote}
                onFechar={() => { setUndoInfo(null); setUndoData(null); }} busy={loteBusy} />

            {/* Modal "Ver" — mesma visão rápida do Onboarding (Link & Status · Configurar · Dados) */}
            {verModal && (
                <ImplModal
                    empresa={verModal}
                    checklist={checklist}
                    erp_opcoes={erp_opcoes}
                    integrador_opcoes={integrador_opcoes}
                    global_padroes={global_padroes}
                    onClose={() => setVerModal(null)}
                />
            )}

            {/* Modal "Arquivados" — empresas fora do projeto Polos (não contam em nada) */}
            {mostrarArquivadas && (
                <ArquivadasModal
                    arquivadas={arquivadas}
                    onDesarquivar={desarquivar}
                    onClose={() => setMostrarArquivadas(false)}
                />
            )}

            {/* Modo TV — overlay de parede. Fica por cima de tudo (inclusive da sidebar). */}
            {modoTv && (
                <ModoTV
                    empresas={empresas}
                    metasEntrada={metas}
                    regioes={opcoes.polo ?? []}
                    cockpit={cockpit}
                    isAdmin={isAdmin}
                    lenteInicial={lente}
                    onSair={toggleModoTv}
                    onAtualizar={atualizarDadosTv}
                />
            )}
        </AppLayout>
    );
}

// ─── Cabeçalho de colunas por lente (com AutoFiltro nos funis) ──────────────────────
const TH = 'px-2.5 py-3 text-white/45 text-[11px] font-semibold uppercase tracking-wider whitespace-nowrap';

// Ordem das colunas de cada lente (batendo com as células do corpo em LinhaPainel).
const COLS_POR_LENTE = {
    // Geral = "planilha completa": união ordenada de todas as áreas (identidade → Acessos →
    // Produtos → Logística → Financeiro admin → Ações). As fin_* só entram p/ admin (colsDaLente).
    geral: [
        'data_cadastro',
        'fase', 'estagio', 'polo', 'responsavel', 'onboarding', 'envio', 'status_entrada', 'chance_entrada',
        'acesso_colaborador', 'gmail_colaborador', 'grupo_whatsapp', 'link_whatsapp', 'reuniao_onboarding', 'data_solicitacao',
        'planilha_produtos', 'listagem', 'publicacao', 'decola', 'campanha_criada', 'central_promocao',
        'contextos_logistica', 'me1', 'integradora', 'places', 'erp',
        'fin_faturamento', 'fin_meta', 'fin_pct', 'fin_ads', 'fin_status',
        '__acoes__',
    ],
    acessos:    ['acesso_colaborador', 'gmail_colaborador', 'grupo_whatsapp', 'link_whatsapp', 'reuniao_onboarding', 'data_solicitacao'],
    produtos:   ['planilha_produtos', 'listagem', 'publicacao', 'decola', 'campanha_criada', 'central_promocao'],
    logistica:  ['contextos_logistica', 'me1', 'integradora', 'places', 'erp'],
    financeiro: ['fin_faturamento', 'fin_meta', 'fin_pct', 'fin_ads', 'fin_status'],
};

// Colunas financeiras alinhadas à direita (números) — vale na lente Financeiro E na Geral.
const FIN_ALIGN_RIGHT = ['fin_faturamento', 'fin_meta', 'fin_pct'];

// Colunas visíveis da lente. Na Geral (planilha completa) as fin_* só aparecem p/ admin
// (COLUNAS nem define fin_* p/ não-admin; sem o filtro o cabeçalho ficaria com <th> nulos).
function colsDaLente(lente, isAdmin) {
    const keys = COLS_POR_LENTE[lente] ?? [];
    if (lente === 'geral' && !isAdmin) return keys.filter((k) => !k.startsWith('fin_'));
    return keys;
}

// <th> com funil de filtro/ordenação. `alignRight` p/ colunas numéricas do financeiro.
function ThFiltro({ col, af, alignRight = false }) {
    if (!col) return null;
    const filterable = col.filter !== false;
    const sortable = col.sortable !== false;
    return (
        <th className={cn(TH, alignRight && 'text-right')}>
            <ColumnFilter
                label={col.label} type={col.type ?? 'text'} filterable={filterable} sortable={sortable}
                getOptions={() => af.optionsFor(col.key)}
                active={af.isColumnActive(col.key)}
                sortDir={af.sort?.key === col.key ? af.sort.dir : null}
                onToggle={(v) => af.toggleValue(col.key, v)}
                onSelectAll={(vals, chk) => af.selectAll(col.key, vals, chk)}
                onClear={() => af.clearColumn(col.key)}
                onSort={(dir) => af.setSort(col.key, dir)}
                alignRight={alignRight}
            />
        </th>
    );
}

function CabecalhoLente({ keys = [], af, colunas }) {
    return (
        <>
            {keys.map((k) => {
                if (k === '__acoes__') return <th key={k} className={TH}>Ações</th>;
                // Números do financeiro alinhados à direita (por chave — funciona na Geral também).
                return <ThFiltro key={k} col={colunas[k]} af={af} alignRight={FIN_ALIGN_RIGHT.includes(k)} />;
            })}
        </>
    );
}

// ─── Linha ──────────────────────────────────────────────────────────────────────────
function LinhaPainel({ e, idx, selecionada, onToggleSel, lente, isAdmin, opcoes, valoresPresentes, usuarios, appUrl, fin, finLoaded, fechado, adsLimites = { teto: 3000, alerta1: 1000, alerta2: 2000 }, semanal, aberta, editNota, setEditNota, on }) {
    const precisaAcao = e.problema || e.fora_do_prazo || e.status_envio === 'falta_enviar';
    const onb = e.onboarding_progresso;
    const td = 'px-2.5 py-3 align-middle';

    // ── Fragmentos de células por área ──────────────────────────────────────────────
    // Reutilizados na lente própria E concatenados na Geral (planilha completa). A ordem
    // AQUI tem de bater com COLS_POR_LENTE (cabeçalho ↔ corpo) — ver colsDaLente/CabecalhoLente.
    const celIdentidade = (<>
        <td className={td}><div className="min-w-[88px]"><EditSelect e={e} campo="fase" opcoes={opcoes.fase} presentes={valoresPresentes.fase} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corFase} criavel={false} /></div></td>
        <td className={td}>{e.estagio ? <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full', corEstagio(e.estagio))}>{e.estagio}</span> : <span className="text-white/20 text-[12px]">—</span>}</td>
        <td className={td}><div className="min-w-[120px]"><EditSelect e={e} campo="polo" opcoes={opcoes.polo} presentes={valoresPresentes.polo} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} /></div></td>
        <td className={td}>
            {e.impl_id ? (
                <select value={e.responsavel_id ? String(e.responsavel_id) : '__sem__'} onChange={(ev) => on.trocarResponsavel(e, ev.target.value)} style={{ backgroundColor: 'transparent' }} className={cn('w-40', CELL, e.responsavel_id ? 'text-white/85 font-medium' : 'text-white/30')}>
                    <option value="__sem__" className="bg-ecf-card">Sem responsável</option>
                    {usuarios.map((u) => <option key={u.id} value={String(u.id)} className="bg-ecf-card text-white">{u.name}</option>)}
                </select>
            ) : <span className="text-white/40 text-[12px]">{e.empresa_responsavel_nome ?? '—'}</span>}
        </td>
        <td className={td}>
            {e.impl_id && onb ? (
                <div className="flex items-center gap-2 min-w-[90px]"><div className="flex-1"><Barra pct={onb.pct} cor={onb.pct === 100 ? '#22c55e' : '#6366f1'} /></div><span className="text-white/40 text-[10px] tabular-nums">{onb.feitos}/{onb.total}</span></div>
            ) : <span className="text-white/20 text-[12px]">—</span>}
        </td>
        <td className={td}>
            {e.status_envio ? (
                <div className="flex flex-col gap-0.5">
                    <span className={cn('text-[10px] font-semibold px-1.5 py-0.5 rounded-full border w-fit', STATUS_ENVIO_BADGE[e.status_envio])}>{STATUS_ENVIO_LABELS[e.status_envio]}</span>
                    {!e.link_enviado_em && e.status_envio !== 'concluido' && <button onClick={() => on.marcarEnviado(e)} className="text-emerald-300/70 hover:text-emerald-300 text-[10px] text-left transition">marcar enviado</button>}
                    {e.link_enviado_em && <button onClick={() => on.desfazerEnvio(e)} className="text-white/30 hover:text-white/60 text-[10px] text-left transition">desfazer</button>}
                </div>
            ) : <span className="text-white/20 text-[12px]">—</span>}
        </td>
        <td className={td}><div className="min-w-[140px]"><EditSelect e={e} campo="status_entrada" opcoes={opcoes.status_entrada} presentes={valoresPresentes.status_entrada} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[110px]"><EditSelect e={e} campo="chance_entrada" opcoes={opcoes.chance_entrada} presentes={valoresPresentes.chance_entrada} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
    </>);

    const celAcessos = (<>
        <td className={td}><div className="min-w-[140px]"><EditSelect e={e} campo="acesso_colaborador" opcoes={opcoes.acesso_colaborador} presentes={valoresPresentes.acesso_colaborador} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[200px]"><EditText e={e} campo="gmail_colaborador" onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} placeholder="gmail…" wide /></div></td>
        <td className={td}><EditToggle e={e} campo="grupo_whatsapp" onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} /></td>
        <td className={td}><EditLink e={e} campo="link_whatsapp" onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} /></td>
        <td className={td}><div className="min-w-[130px]"><EditSelect e={e} campo="reuniao_onboarding" opcoes={opcoes.reuniao_onboarding} presentes={valoresPresentes.reuniao_onboarding} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[140px]"><EditDate e={e} campo="data_solicitacao" onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} /></div></td>
    </>);

    // Cadastro no sistema (created_at) — read-only, automático; existe mesmo sem ficha.
    // Selo "novo": some depois que alguém editar a empresa ou a ficha (flag `e.novo` do backend).
    const celCadastro = (
        <td className={td}>
            <div className="flex items-center gap-1.5 whitespace-nowrap min-w-[112px]">
                <span className="text-white/60 text-[12px] tabular-nums">{e.data_cadastro ? fmtDataBR(e.data_cadastro) : '—'}</span>
                {e.novo && (
                    <span className="rounded-full border border-emerald-400/30 bg-emerald-500/[0.12] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-emerald-300">novo</span>
                )}
            </div>
        </td>
    );

    const celProdutos = (<>
        <td className={td}><div className="min-w-[130px]"><EditSelect e={e} campo="planilha_produtos" opcoes={opcoes.planilha_produtos} presentes={valoresPresentes.planilha_produtos} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[150px]"><EditSelect e={e} campo="listagem" opcoes={opcoes.listagem} presentes={valoresPresentes.listagem} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[130px]"><EditSelect e={e} campo="publicacao" opcoes={opcoes.publicacao} presentes={valoresPresentes.publicacao} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[140px]"><EditSelect e={e} campo="decola" opcoes={opcoes.decola} presentes={valoresPresentes.decola} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><EditToggle e={e} campo="campanha_criada" onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} /></td>
        <td className={td}><div className="min-w-[150px]"><EditSelect e={e} campo="central_promocao" opcoes={opcoes.central_promocao} presentes={valoresPresentes.central_promocao} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
    </>);

    const celLogistica = (<>
        <td className={td}><div className="min-w-[240px]"><EditText e={e} campo="contextos_logistica" onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} placeholder="anotação…" wide /></div></td>
        <td className={td}><div className="min-w-[160px]"><EditSelect e={e} campo="me1" opcoes={opcoes.me1} presentes={valoresPresentes.me1} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[130px]"><EditSelect e={e} campo="integradora" opcoes={opcoes.integradora} presentes={valoresPresentes.integradora} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[150px]"><EditSelect e={e} campo="places" opcoes={opcoes.places} presentes={valoresPresentes.places} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
        <td className={td}><div className="min-w-[130px]"><EditSelect e={e} campo="erp" opcoes={opcoes.erp} presentes={valoresPresentes.erp} onSave={on.salvarCampo} onCriar={() => on.criarOnboarding(e)} cor={corValor} /></div></td>
    </>);

    const celAcoes = (
        <td className={td}>
            <div className="flex items-center gap-1.5 whitespace-nowrap">
                <button onClick={() => on.toggleProblema(e)} title={e.problema ? 'Alternar problema' : 'Marcar problema'}
                    className={cn('p-1.5 rounded-lg transition', e.problema ? 'text-red-300 bg-red-500/10 hover:bg-red-500/20' : 'text-white/40 hover:text-red-300 hover:bg-white/[0.06]')}><ShieldAlert size={13} /></button>
                {e.impl_id ? (
                    <Link href={route('mlb.implementacao.ficha', e.impl_id)} className="inline-flex items-center gap-1 px-2 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white text-[11px] transition" title="Abrir ficha completa (página inteira)"><FileText size={12} /></Link>
                ) : (
                    <button onClick={() => on.criarOnboarding(e)} className="inline-flex items-center gap-1 px-2 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white text-[11px] transition" title="Criar ficha de onboarding"><FilePlus2 size={12} /></button>
                )}
                <button onClick={() => on.arquivar(e)} title="Arquivar (sai do painel; não conta em nada — reversível)"
                    className="p-1.5 rounded-lg text-white/40 transition hover:text-amber-300 hover:bg-white/[0.06]"><Archive size={13} /></button>
            </div>
        </td>
    );

    return (
        <>
            <tr className={cn('border-b border-white/[0.05] transition-colors hover:bg-white/[0.025]', aberta && 'bg-white/[0.04]', selecionada && 'bg-ecf-yellow/[0.05]')}>
                {/* Seleção (congelada à esquerda) */}
                <td className="sticky left-0 z-10 bg-ecf-card px-3 py-3 align-middle">
                    <button type="button" onClick={(ev) => onToggleSel(e.id, idx, ev.shiftKey)} title="Selecionar (Shift = intervalo)" className="align-middle">
                        <CaixaSel state={selecionada ? 'on' : 'off'} />
                    </button>
                </td>
                {/* Empresa (congelada à esquerda) */}
                <td className={cn('sticky left-10 z-10 bg-ecf-card px-3 py-3 align-middle', precisaAcao && 'border-l-2 border-l-amber-500/60')}>
                    <div className="flex items-start gap-2.5">
                        <button onClick={() => on.toggleExpandir(e)} className="mt-0.5 text-white/25 hover:text-white transition shrink-0" title="Detalhes">
                            {aberta ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
                        </button>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 flex-wrap">
                                {e.impl_id ? (
                                    <button type="button" onClick={() => on.verEmpresa(e)} className="text-left text-white text-[13.5px] font-semibold hover:text-ecf-yellow transition truncate max-w-[220px]" title="Ver (visão rápida do onboarding)">{e.nome}</button>
                                ) : (
                                    <span className="text-white text-[13.5px] font-semibold truncate max-w-[220px]">{e.nome}</span>
                                )}
                                <CustIdCell e={e} onSalvar={on.salvarCustId} />
                                {/* Roxo (cor do status Problema no donut) = problema que tira da meta. */}
                                {e.problema && (
                                    <span
                                        className={cn('inline-flex items-center gap-0.5 text-[9px] font-semibold px-1.5 py-0.5 rounded-full border',
                                            e.problema_desconsidera_meta
                                                ? 'text-purple-200 bg-purple-500/10 border-purple-500/25'
                                                : 'text-red-300 bg-red-500/10 border-red-500/20')}
                                        title={`${e.problema_nota ?? 'Problema'}${e.problema_desconsidera_meta ? ' — desconsiderada da meta' : ' — continua contando pra meta'}`}>
                                        <ShieldAlert size={9} /> problema{e.problema_desconsidera_meta ? ' · fora da meta' : ''}
                                    </span>
                                )}
                                {e.fora_do_prazo && <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded-full text-red-300 bg-red-500/10 border border-red-500/20">fora do prazo</span>}
                                {e.ads_desligado && <span className="inline-flex items-center gap-0.5 text-[9px] px-1.5 py-0.5 rounded-full text-white/50 bg-white/[0.05] border border-white/10" title="ADS desligado"><MegaphoneOff size={9} /> ads off</span>}
                                {!e.impl_id && <span className="text-[9px] px-1.5 py-0.5 rounded-full text-amber-200/70 bg-amber-500/[0.08] border border-amber-500/20" title="Sem ficha de onboarding">sem ficha</span>}
                            </div>
                            {/* Contexto fase·polo só fora da lente Geral (lá viram colunas) */}
                            {lente !== 'geral' && (
                                <div className="text-[11px] mt-1"><span className={cn('font-semibold', corFase(e.fase))}>{e.fase || '—'}</span>{e.polo ? <span className="text-white/35"> · {e.polo}</span> : null}</div>
                            )}
                        </div>
                    </div>
                </td>

                {/* Colunas da lente — Geral concatena TODAS as áreas (planilha completa). */}
                {lente === 'geral' && (<>
                    {celCadastro}
                    {celIdentidade}
                    {celAcessos}
                    {celProdutos}
                    {celLogistica}
                    {isAdmin && <CelulasFinanceiro fin={fin} finLoaded={finLoaded} td={td} adsLimites={adsLimites} fechado={fechado} />}
                    {celAcoes}
                </>)}
                {lente === 'acessos'   && celAcessos}
                {lente === 'produtos'  && celProdutos}
                {lente === 'logistica' && celLogistica}
                {lente === 'financeiro' && isAdmin && (
                    <CelulasFinanceiro fin={fin} finLoaded={finLoaded} td={td} adsLimites={adsLimites} fechado={fechado} />
                )}
            </tr>

            {/* Drawer (detalhe pesado sob demanda) */}
            {aberta && (
                <tr className="bg-white/[0.02]">
                    {/* colSpan alto: o navegador limita ao total real de colunas (Geral, com
                        as 2 congeladas, chega a ~33 desde a coluna "Link do Whats"). */}
                    <td colSpan={40} className="px-5 py-4">
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            {/* Problema */}
                            <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                <h4 className="text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-2 flex items-center gap-1.5"><ShieldAlert size={12} /> Problema</h4>
                                {e.problema ? (
                                    <div className="space-y-2">
                                        <textarea value={editNota[e.id] ?? e.problema_nota ?? ''} onChange={(ev) => setEditNota((s) => ({ ...s, [e.id]: ev.target.value }))} rows={2} placeholder="Descreva o problema…"
                                            className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] p-2 outline-none focus:border-ecf-yellow/40" />
                                        {/* Decide se ESTE problema tira a empresa da meta. Desmarcado (padrão)
                                            ela continua contando em No alvo / Em progresso / Não. */}
                                        <label className="flex items-start gap-2 cursor-pointer select-none rounded-lg bg-white/[0.02] border border-white/[0.06] p-2">
                                            <input type="checkbox" checked={e.problema_desconsidera_meta === true}
                                                onChange={(ev) => on.alternarMeta(e, ev.target.checked)}
                                                className="mt-0.5 h-3.5 w-3.5 rounded border-white/20 bg-transparent accent-purple-500" />
                                            <span className="text-[11px] leading-snug">
                                                <span className="text-white/70 font-semibold">Desconsiderar da meta</span>
                                                <span className="block text-white/35">
                                                    {e.problema_desconsidera_meta
                                                        ? 'Fica no status Problema e sai da meta do polo.'
                                                        : 'Segue contando pra meta (No alvo / Em progresso / Não).'}
                                                </span>
                                            </span>
                                        </label>
                                        <div className="flex items-center gap-2">
                                            <button onClick={() => on.salvarNota(e)} className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-ecf-yellow/10 text-ecf-yellow text-[11px] font-semibold hover:bg-ecf-yellow/20 transition"><Pencil size={11} /> Salvar nota</button>
                                            <button onClick={() => on.removerProblema(e)} className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-white/[0.04] text-white/50 text-[11px] hover:text-red-300 transition"><Trash2 size={11} /> Remover</button>
                                        </div>
                                    </div>
                                ) : (
                                    // Duas portas de entrada: o problema comum (continua na meta) e o
                                    // que desconsidera. A escolha é feita no ato de marcar.
                                    <div className="flex flex-col gap-1.5 items-start">
                                        <button onClick={() => on.toggleProblema(e, false)} className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/[0.04] text-white/60 text-[12px] hover:text-red-300 hover:bg-red-500/10 transition"><ShieldAlert size={12} /> Marcar problema <span className="text-white/30">· conta pra meta</span></button>
                                        <button onClick={() => on.toggleProblema(e, true)} className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-purple-500/[0.08] text-purple-200/80 text-[12px] hover:bg-purple-500/[0.16] transition"><ShieldAlert size={12} /> Marcar e tirar da meta</button>
                                    </div>
                                )}
                                {e.contexto && <p className="text-white/35 text-[11px] mt-2 italic">Contexto: {e.contexto}</p>}
                            </div>
                            {/* Links */}
                            <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                <h4 className="text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-2 flex items-center gap-1.5"><Link2 size={12} /> Links</h4>
                                {e.token ? (
                                    <div className="flex flex-col gap-1.5">
                                        <a href={`${appUrl}/implementacao/${e.token}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-[12px] text-sky-300 hover:text-sky-200 transition"><ExternalLink size={12} /> Workspace do cliente</a>
                                        <a href={`${appUrl}/implementacao/${e.token}/publicador`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-[12px] text-sky-300 hover:text-sky-200 transition"><BookUser size={12} /> Visão do publicador</a>
                                        {e.link_enviado_em && <span className="text-white/30 text-[10px] mt-1">Enviado por {e.link_enviado_por ?? '—'} em {e.link_enviado_em}</span>}
                                    </div>
                                ) : <p className="text-white/30 text-[12px]">Sem ficha — crie o onboarding para gerar os links.</p>}
                            </div>
                            {/* Semanal (admin) */}
                            {isAdmin && (
                                <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                    <h4 className="text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-2 flex items-center gap-1.5"><Wallet size={12} /> Semanal do mês</h4>
                                    {!e.cust_id ? <p className="text-white/30 text-[12px]">Sem cust_id.</p>
                                        : semanal?.loading ? <p className="text-white/30 text-[12px] inline-flex items-center gap-1.5"><RefreshCw size={12} className="animate-spin" /> Carregando…</p>
                                        : semanal?.erro ? <p className="text-red-300/70 text-[12px]">Falha ao buscar.</p>
                                        : semanal ? <SparkSemanal semanas={semanal.semanas ?? []} total={semanal.total ?? 0} totalAds={semanal.totalAds ?? 0} fechado={fechado} />
                                        : <p className="text-white/30 text-[12px]">Abrindo…</p>}
                                </div>
                            )}
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

// ─── Células da lente Financeiro/Performance (admin, read-only) ─────────────────────
function CelulasFinanceiro({ fin, finLoaded, td, adsLimites = { teto: 3000, alerta1: 1000, alerta2: 2000 }, fechado = false }) {
    if (!finLoaded) return <td className={td} colSpan={5}><span className="text-white/25 text-[12px] inline-flex items-center gap-1.5"><RefreshCw size={11} className="animate-spin" /> carregando…</span></td>;
    if (!fin) return <td className={td} colSpan={5}><span className="text-white/20 text-[12px]">— sem dado financeiro (não ativo / sem sync)</span></td>;
    if (fin.tipo === 'm1') {
        return (<>
            <td className={cn(td, 'text-right')}><span className="text-white/80 text-[12px] tabular-nums">{formatCurrency(fin.faturamento ?? 0)}</span></td>
            <td className={cn(td, 'text-right text-white/20 text-[12px]')}>—</td>
            <td className={cn(td, 'text-right text-white/20 text-[12px]')}>—</td>
            <td className={cn(td, 'text-white/20 text-[12px]')}>—</td>
            <td className={td}>{fin.faturando ? <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-300"><Sparkles size={10} /> Pronto p/ M2</span> : <span className="text-white/30 text-[11px]">M1 — não fatura</span>}</td>
        </>);
    }
    const cor = fin.pct >= 100 ? '#22c55e' : fin.pct > 0 ? '#ffe600' : '#ef4444';
    // ADS: MESMA coluna de /polos/empresas — barra do gasto vs teto/empresa, cor por limiar (corAds),
    // com o teto visível ("R$ X / R$ 3.000,00"). Mês fechado não tem fonte de ADS → "—".
    const ads    = fin.ads ?? 0;
    const adsPct = Math.min(ads / (adsLimites.teto || 3000) * 100, 100);
    return (<>
        <td className={cn(td, 'text-right')}><span className="text-white/90 text-[12px] font-semibold tabular-nums">{formatCurrency(fin.faturamento ?? 0)}</span></td>
        <td className={cn(td, 'text-right text-white/40 text-[12px] tabular-nums')}>{formatCurrency(fin.meta ?? 0)}</td>
        <td className={cn(td, 'text-right')}><span className="text-[12px] font-semibold tabular-nums" style={{ color: cor }}>{fmtPct(fin.pct)}</span></td>
        <td className={td}>
            {fechado ? (
                <span className="text-white/20 text-[12px]" title="ADS só é apurado no mês corrente">—</span>
            ) : (
                <div className="flex items-center gap-2">
                    <div className="w-24 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                        <div className="h-full rounded-full" style={{ width: `${adsPct}%`, background: corAds(ads, adsLimites) }} />
                    </div>
                    <span className="text-white/40 text-xs tabular-nums whitespace-nowrap">
                        {formatCurrency(ads)} <span className="text-white/20">/ {formatCurrency(adsLimites.teto)}</span>
                    </span>
                </div>
            )}
        </td>
        <td className={td}><StatusBadge status={fin.status} /></td>
    </>);
}
