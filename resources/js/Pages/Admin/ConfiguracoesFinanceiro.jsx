import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Mail, Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Página de configuração de destinatários do relatório mensal de fechamento.
 * Permite adicionar/remover emails e salvar via Inertia POST.
 */
export default function ConfiguracoesFinanceiro({ destinatarios, ultimo_envio }) {
    const { data, setData, post, processing } = useForm({
        destinatarios: destinatarios || [],
    });

    const [novoEmail, setNovoEmail] = useState('');
    const [erroEmail, setErroEmail] = useState('');

    // Adiciona um email à lista após validação básica
    function adicionarEmail() {
        const email = novoEmail.trim();
        const regex = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

        if (!regex.test(email)) {
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

    // Trata tecla Enter no input de email
    function handleKeyDown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            adicionarEmail();
        }
    }

    // Remove um email da lista pelo índice
    function removerEmail(idx) {
        setData('destinatarios', data.destinatarios.filter((_, i) => i !== idx));
    }

    // Envia o form via Inertia POST
    function salvar() {
        post(route('admin.configuracoes.financeiro.salvar'), { preserveScroll: true });
    }

    // Formata a data do último envio em pt-BR
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

    return (
        <AppLayout title="Configurações do Financeiro">
            <main className="p-6">
                <div className="max-w-xl space-y-6">

                    {/* Botão de voltar */}
                    <Link
                        href={route('admin.financeiro')}
                        className="inline-flex items-center gap-1.5 text-[13px] text-white/40 hover:text-white/70 transition-colors"
                    >
                        <ArrowLeft size={14} />
                        Voltar para Fechamento
                    </Link>

                    {/* Card principal de destinatários */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-6 space-y-5">

                        {/* Header do card */}
                        <div className="flex items-center gap-3">
                            <div className="flex items-center justify-center w-9 h-9 rounded-lg bg-ecf-yellow/10 shrink-0">
                                <Mail size={16} className="text-ecf-yellow" />
                            </div>
                            <div>
                                <h1 className="text-[17px] font-semibold font-display text-white">
                                    Destinatários do Relatório Mensal
                                </h1>
                                <p className="text-[13px] text-white/40 mt-0.5">
                                    Os emails listados receberão o Relatório Geral de Fechamento
                                    no dia 5 de cada mês às 09:00, e quando enviado manualmente.
                                </p>
                            </div>
                        </div>

                        {/* Input + botão adicionar */}
                        <div className="space-y-1.5">
                            <div className="flex gap-2">
                                <input
                                    type="email"
                                    value={novoEmail}
                                    onChange={e => { setNovoEmail(e.target.value); setErroEmail(''); }}
                                    onKeyDown={handleKeyDown}
                                    placeholder="novo@email.com"
                                    className={cn(
                                        'flex-1 h-9 px-3 rounded-lg border bg-white/[0.03] text-[13px] text-white/80 placeholder:text-white/20',
                                        'focus:outline-none transition-colors',
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
                            {erroEmail && (
                                <p className="text-[12px] text-red-400">{erroEmail}</p>
                            )}
                        </div>

                        {/* Lista de emails cadastrados */}
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
                                            title="Remover"
                                        >
                                            <X size={14} />
                                        </button>
                                    </div>
                                ))
                            )}
                        </div>

                        {/* Botão salvar */}
                        <button
                            type="button"
                            onClick={salvar}
                            disabled={processing}
                            className="h-9 px-4 rounded-lg bg-ecf-yellow text-black text-[13px] font-semibold hover:bg-ecf-yellow/90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            {processing ? 'Salvando...' : 'Salvar configurações'}
                        </button>
                    </div>

                    {/* Card informativo de último envio */}
                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.01] px-5 py-4">
                        <p className="text-[12px] text-white/30 uppercase tracking-widest font-semibold mb-1">
                            Último envio automático
                        </p>
                        <p className="text-[14px] text-white/60">
                            {formatarUltimoEnvio(ultimo_envio)}
                        </p>
                        <p className="text-[12px] text-white/25 mt-1.5">
                            Próximo envio automático: dia 5 de cada mês às 09:00.
                        </p>
                    </div>

                </div>
            </main>
        </AppLayout>
    );
}
