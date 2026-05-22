import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Calendar, Mail, Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';

const HORAS = Array.from({ length: 24 }, (_, h) => {
    const hh = String(h).padStart(2, '0');
    return { value: `${hh}:00`, label: `${hh}:00` };
});

export default function ConfiguracoesFinanceiro({
    destinatarios,
    ultimo_envio,
    envio_auto_ativo,
    envio_auto_dia,
    envio_auto_hora,
}) {
    const { data, setData, post, processing } = useForm({
        destinatarios:    destinatarios    || [],
        envio_auto_ativo: envio_auto_ativo ?? false,
        envio_auto_dia:   envio_auto_dia   ?? 5,
        envio_auto_hora:  envio_auto_hora  ?? '09:00',
    });

    const [novoEmail, setNovoEmail] = useState('');
    const [erroEmail, setErroEmail] = useState('');

    function adicionarEmail() {
        const email = novoEmail.trim();
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
            setErroEmail('E-mail inválido.');
            return;
        }
        if (data.destinatarios.includes(email)) {
            setErroEmail('E-mail já cadastrado.');
            return;
        }
        setErroEmail('');
        setData('destinatarios', [...data.destinatarios, email]);
        setNovoEmail('');
    }

    function handleKeyDown(e) {
        if (e.key === 'Enter') { e.preventDefault(); adicionarEmail(); }
    }

    function removerEmail(idx) {
        setData('destinatarios', data.destinatarios.filter((_, i) => i !== idx));
    }

    function salvar() {
        post(route('admin.configuracoes.financeiro.salvar'), { preserveScroll: true });
    }

    function formatarUltimoEnvio(iso) {
        if (!iso) return 'Nunca';
        try {
            return new Date(iso).toLocaleString('pt-BR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit',
            });
        } catch {
            return iso;
        }
    }

    // Exibe "dia X às HH:MM" do próximo envio configurado
    function proximoEnvio() {
        if (!data.envio_auto_ativo) return 'Desativado';
        return `Todo dia ${data.envio_auto_dia} às ${data.envio_auto_hora}`;
    }

    const inputCls = 'h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 transition-colors';

    return (
        <AppLayout title="Configurações do Financeiro">
            <main className="p-6">
                <div className="max-w-xl space-y-6">

                    <Link
                        href={route('admin.financeiro')}
                        className="inline-flex items-center gap-1.5 text-[13px] text-white/40 hover:text-white/70 transition-colors"
                    >
                        <ArrowLeft size={14} />
                        Voltar para Fechamento
                    </Link>

                    {/* ── Destinatários ── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-6 space-y-5">
                        <div className="flex items-center gap-3">
                            <div className="flex items-center justify-center w-9 h-9 rounded-lg bg-ecf-yellow/10 shrink-0">
                                <Mail size={16} className="text-ecf-yellow" />
                            </div>
                            <div>
                                <h2 className="text-[15px] font-semibold text-white">Destinatários</h2>
                                <p className="text-[13px] text-white/40 mt-0.5">
                                    Emails que receberão o Relatório Geral de Fechamento.
                                </p>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <div className="flex gap-2">
                                <input
                                    type="email"
                                    value={novoEmail}
                                    onChange={e => { setNovoEmail(e.target.value); setErroEmail(''); }}
                                    onKeyDown={handleKeyDown}
                                    placeholder="novo@ecfconsultoria.com.br"
                                    className={cn(
                                        'flex-1 h-9 px-3 rounded-lg border bg-white/[0.03] text-[13px] text-white/80 placeholder:text-white/20 focus:outline-none transition-colors',
                                        erroEmail
                                            ? 'border-red-500/40 focus:border-red-500/60'
                                            : 'border-white/[0.08] focus:border-ecf-yellow/40',
                                    )}
                                />
                                <button
                                    type="button"
                                    onClick={adicionarEmail}
                                    className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/10 text-ecf-yellow text-[13px] font-semibold hover:bg-ecf-yellow/15 transition-colors shrink-0"
                                >
                                    <Plus size={14} />
                                    Adicionar
                                </button>
                            </div>
                            {erroEmail && <p className="text-[12px] text-red-400">{erroEmail}</p>}
                        </div>

                        <div className="space-y-1.5">
                            {data.destinatarios.length === 0 ? (
                                <p className="text-[13px] text-white/25 py-3 text-center">
                                    Nenhum destinatário cadastrado.
                                </p>
                            ) : (
                                data.destinatarios.map((email, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between px-3 py-2 rounded-lg border border-white/[0.06] bg-white/[0.02]"
                                    >
                                        <span className="text-[13px] text-white/70 truncate">{email}</span>
                                        <button
                                            type="button"
                                            onClick={() => removerEmail(idx)}
                                            className="ml-3 shrink-0 text-white/25 hover:text-red-400 transition-colors"
                                        >
                                            <X size={14} />
                                        </button>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    {/* ── Agendamento automático ── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-6 space-y-5">
                        <div className="flex items-center gap-3">
                            <div className="flex items-center justify-center w-9 h-9 rounded-lg bg-white/[0.04] shrink-0">
                                <Calendar size={16} className="text-white/50" />
                            </div>
                            <div className="flex-1">
                                <h2 className="text-[15px] font-semibold text-white">Envio automático</h2>
                                <p className="text-[13px] text-white/40 mt-0.5">
                                    Dispara o relatório automaticamente todo mês na data configurada.
                                </p>
                            </div>
                            {/* Toggle ativar/desativar */}
                            <button
                                type="button"
                                onClick={() => setData('envio_auto_ativo', !data.envio_auto_ativo)}
                                className={cn(
                                    'inline-flex items-center gap-2 h-8 px-3 rounded-lg border text-[12px] font-semibold shrink-0 transition-colors',
                                    data.envio_auto_ativo
                                        ? 'bg-ecf-yellow/10 border-ecf-yellow/30 text-ecf-yellow'
                                        : 'bg-white/[0.04] border-white/[0.08] text-white/40 hover:text-white/60',
                                )}
                            >
                                <span className={cn(
                                    'w-7 h-4 rounded-full relative transition-colors shrink-0',
                                    data.envio_auto_ativo ? 'bg-ecf-yellow' : 'bg-white/20',
                                )}>
                                    <span className={cn(
                                        'absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform duration-200',
                                        data.envio_auto_ativo ? 'translate-x-3.5' : 'translate-x-0.5',
                                    )} />
                                </span>
                                {data.envio_auto_ativo ? 'Ativado' : 'Ativar'}
                            </button>
                        </div>

                        {/* Campos de dia e hora — só visíveis quando ativo */}
                        <div className={cn(
                            'grid grid-cols-2 gap-3 transition-opacity',
                            data.envio_auto_ativo ? 'opacity-100' : 'opacity-30 pointer-events-none',
                        )}>
                            <div className="space-y-1.5">
                                <label className="text-[12px] text-white/40 font-medium">Dia do mês</label>
                                <input
                                    type="number"
                                    min={1}
                                    max={28}
                                    value={data.envio_auto_dia}
                                    onChange={e => setData('envio_auto_dia', Math.min(28, Math.max(1, parseInt(e.target.value) || 1)))}
                                    className={cn(inputCls, 'w-full')}
                                />
                                <p className="text-[11px] text-white/25">Entre 1 e 28</p>
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-[12px] text-white/40 font-medium">Horário</label>
                                <select
                                    value={data.envio_auto_hora}
                                    onChange={e => setData('envio_auto_hora', e.target.value)}
                                    className={cn(inputCls, 'w-full pr-7 appearance-none')}
                                >
                                    {HORAS.map(h => (
                                        <option key={h.value} value={h.value}>{h.label}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Resumo do próximo envio */}
                        <div className={cn(
                            'flex items-center gap-2 px-3 py-2.5 rounded-lg border text-[13px] transition-colors',
                            data.envio_auto_ativo
                                ? 'border-ecf-yellow/20 bg-ecf-yellow/[0.04] text-white/70'
                                : 'border-white/[0.05] bg-white/[0.01] text-white/25',
                        )}>
                            <span className={cn('w-1.5 h-1.5 rounded-full shrink-0', data.envio_auto_ativo ? 'bg-ecf-yellow' : 'bg-white/20')} />
                            {data.envio_auto_ativo
                                ? <>Próximo envio: <span className="font-medium text-white/90 ml-1">{proximoEnvio()}</span></>
                                : 'Envio automático desativado'}
                        </div>
                    </div>

                    {/* ── Botão salvar ── */}
                    <button
                        type="button"
                        onClick={salvar}
                        disabled={processing}
                        className="h-9 px-4 rounded-lg bg-ecf-yellow text-black text-[13px] font-semibold hover:bg-ecf-yellow/90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        {processing ? 'Salvando...' : 'Salvar configurações'}
                    </button>

                    {/* ── Último envio ── */}
                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.01] px-5 py-4">
                        <p className="text-[12px] text-white/30 uppercase tracking-widest font-semibold mb-1">
                            Último envio automático
                        </p>
                        <p className="text-[14px] text-white/60">
                            {formatarUltimoEnvio(ultimo_envio)}
                        </p>
                    </div>

                </div>
            </main>
        </AppLayout>
    );
}
