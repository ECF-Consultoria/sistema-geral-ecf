import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Copy, Check, RefreshCw, CheckCircle2, Clock, XCircle, Unlink, ExternalLink, ShoppingBag } from 'lucide-react';
import { cn } from '@/lib/utils';

const fmtDate = (iso) => iso ? new Date(iso).toLocaleDateString('pt-BR') : '—';
const fmtDateTime = (iso) => iso ? new Date(iso).toLocaleString('pt-BR') : '—';

function daysLeft(expiresAt) {
    if (!expiresAt) return null;
    return Math.ceil((new Date(expiresAt) - new Date()) / (1000 * 60 * 60 * 24));
}

function StatusBadge({ company, generatedUrl }) {
    const token = company.ml_token;

    if (token?.status === 'active') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-semibold">
                <CheckCircle2 size={11} />
                Conectado
            </span>
        );
    }

    if (token?.status === 'revoked') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-red-400 text-[11px] font-semibold">
                <XCircle size={11} />
                Revogado
            </span>
        );
    }

    // Link gerado (pendente) — pode estar na sessão ou no DB
    const pendingAt = generatedUrl ? new Date().toISOString() : company.ml_link_generated_at;
    if (pendingAt || generatedUrl) {
        const expires = company.ml_link_expires_at;
        const days = daysLeft(expires);
        const expired = days !== null && days <= 0;
        return (
            <span className={cn(
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold',
                expired
                    ? 'bg-red-500/10 border border-red-500/20 text-red-400'
                    : 'bg-amber-400/10 border border-amber-400/20 text-amber-400'
            )}>
                <Clock size={11} />
                {expired ? 'Link expirado' : `Aguardando${days !== null ? ` · ${days}d` : ''}`}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/[0.04] border border-white/[0.08] text-white/30 text-[11px] font-semibold">
            Não conectado
        </span>
    );
}

function CompanyRow({ company, onDisconnect }) {
    const [loading, setLoading]       = useState(false);
    const [generatedUrl, setGeneratedUrl] = useState(null);
    const [copied, setCopied]         = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);

    const token = company.ml_token;
    const isConnected = token?.status === 'active';

    const gerarLink = async () => {
        setLoading(true);
        try {
            const res = await fetch(route('ml.oauth.initiate', company.id), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.url) setGeneratedUrl(data.url);
        } finally {
            setLoading(false);
        }
    };

    const copiar = () => {
        const url = generatedUrl ?? company.ml_link_generated_at;
        if (!url) return;
        navigator.clipboard.writeText(generatedUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const desconectar = async () => {
        if (!confirm(`Desconectar Mercado Livre de "${company.name}"?`)) return;
        setDisconnecting(true);
        try {
            await fetch(route('ml.oauth.disconnect', company.id), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
            });
            onDisconnect(company.id);
        } finally {
            setDisconnecting(false);
        }
    };

    return (
        <div className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-4 space-y-3">
            {/* Linha principal */}
            <div className="flex items-center gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="text-white font-medium text-[13px] truncate">{company.name}</span>
                        {company.ml_store_id && (
                            <span className="text-white/25 text-[11px] font-mono shrink-0">#{company.ml_store_id}</span>
                        )}
                    </div>
                    {isConnected && token?.connected_at && (
                        <p className="text-white/30 text-[11px] mt-0.5">
                            Conectado em {fmtDate(token.connected_at)}
                            {token.expires_at && ` · token expira ${fmtDate(token.expires_at)}`}
                        </p>
                    )}
                </div>

                <StatusBadge company={company} generatedUrl={generatedUrl} />

                <div className="flex items-center gap-2 shrink-0">
                    {isConnected ? (
                        <>
                            <Link
                                href={route('companies.show', company.id)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-white/[0.08] text-white/40 hover:text-white hover:border-white/20 text-[11px] transition-colors"
                            >
                                <ExternalLink size={11} />
                                Ver empresa
                            </Link>
                            <button
                                onClick={desconectar}
                                disabled={disconnecting}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-500/20 text-red-400/70 hover:text-red-400 hover:border-red-500/40 text-[11px] transition-colors disabled:opacity-40"
                            >
                                <Unlink size={11} />
                                {disconnecting ? 'Removendo…' : 'Desconectar'}
                            </button>
                        </>
                    ) : (
                        <button
                            onClick={gerarLink}
                            disabled={loading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#ffe116]/10 border border-[#ffe116]/20 text-[#ffe116] hover:bg-[#ffe116]/15 text-[11px] font-medium transition-colors disabled:opacity-40"
                        >
                            {loading
                                ? <RefreshCw size={11} className="animate-spin" />
                                : <ShoppingBag size={11} />
                            }
                            {generatedUrl || company.ml_link_generated_at ? 'Regerar link' : 'Gerar link'}
                        </button>
                    )}
                </div>
            </div>

            {/* Link gerado — aparece após clicar "Gerar link" */}
            {generatedUrl && (
                <div className="rounded-lg border border-[#ffe116]/15 bg-[#ffe116]/[0.03] p-3 space-y-2">
                    <p className="text-white/40 text-[11px] font-medium">Link para enviar ao cliente — válido por 7 dias</p>
                    <div className="flex items-center gap-2">
                        <code className="flex-1 text-[11px] text-white/60 bg-white/[0.04] rounded px-2 py-1.5 truncate font-mono">
                            {generatedUrl}
                        </code>
                        <button
                            onClick={copiar}
                            className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-white/[0.08] text-white/50 hover:text-white hover:border-white/20 text-[11px] transition-colors"
                        >
                            {copied ? <Check size={11} className="text-emerald-400" /> : <Copy size={11} />}
                            {copied ? 'Copiado!' : 'Copiar'}
                        </button>
                    </div>
                </div>
            )}

            {/* Link pendente (gerado em sessão anterior, sem URL disponível) */}
            {!generatedUrl && !isConnected && company.ml_link_generated_at && (
                <div className="rounded-lg border border-amber-400/10 bg-amber-400/[0.03] px-3 py-2">
                    <p className="text-amber-400/60 text-[11px]">
                        Link gerado em {fmtDateTime(company.ml_link_generated_at)}
                        {company.ml_link_expires_at && (() => {
                            const days = daysLeft(company.ml_link_expires_at);
                            return days !== null
                                ? (days > 0 ? ` · expira em ${days} dia${days !== 1 ? 's' : ''}` : ' · expirado')
                                : '';
                        })()}
                        . Clique em "Regerar link" para obter uma nova URL.
                    </p>
                </div>
            )}
        </div>
    );
}

export default function MlOAuthIndex({ companies: initial }) {
    const [companies, setCompanies] = useState(initial);
    const [search, setSearch] = useState('');

    const handleDisconnect = (id) => {
        setCompanies(prev => prev.map(c =>
            c.id === id ? { ...c, ml_token: null, ml_link_generated_at: null } : c
        ));
    };

    const connected = companies.filter(c => c.ml_token?.status === 'active');
    const pending   = companies.filter(c => !c.ml_token?.status !== 'active' && c.ml_link_generated_at && c.ml_token?.status !== 'active');
    const rest      = companies.filter(c => !c.ml_token && !c.ml_link_generated_at);

    const filtered = search.trim()
        ? companies.filter(c => c.name.toLowerCase().includes(search.toLowerCase()))
        : null;

    const renderList = (list, emptyMsg) => (
        list.length === 0
            ? <p className="text-white/20 text-[12px] px-1">{emptyMsg}</p>
            : <div className="space-y-2">{list.map(c => <CompanyRow key={c.id} company={c} onDisconnect={handleDisconnect} />)}</div>
    );

    return (
        <AppLayout title="Mercado Livre OAuth">
            <div className="space-y-6 max-w-3xl">
                {/* Header */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-white font-semibold text-[18px]">Mercado Livre OAuth</h1>
                        <p className="text-white/30 text-[12px] mt-0.5">
                            {connected.length} conectada{connected.length !== 1 ? 's' : ''} · {pending.length} aguardando autorização
                        </p>
                    </div>
                    <input
                        type="text"
                        placeholder="Buscar empresa…"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="w-56 bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-1.5 text-[13px] text-white placeholder-white/25 outline-none focus:border-white/20"
                    />
                </div>

                {/* Busca */}
                {filtered ? (
                    <section>
                        <div className="space-y-2">
                            {filtered.length === 0
                                ? <p className="text-white/20 text-[12px] px-1">Nenhuma empresa encontrada.</p>
                                : filtered.map(c => <CompanyRow key={c.id} company={c} onDisconnect={handleDisconnect} />)
                            }
                        </div>
                    </section>
                ) : (
                    <>
                        {/* Conectadas */}
                        <section className="space-y-3">
                            <div className="flex items-center gap-2">
                                <CheckCircle2 size={13} className="text-emerald-400" />
                                <h2 className="text-emerald-400 text-[13px] font-semibold">Conectadas</h2>
                                <span className="text-white/20 text-[11px]">({connected.length})</span>
                            </div>
                            {renderList(connected, 'Nenhuma empresa conectada ainda.')}
                        </section>

                        {/* Aguardando */}
                        {pending.length > 0 && (
                            <section className="space-y-3">
                                <div className="flex items-center gap-2">
                                    <Clock size={13} className="text-amber-400" />
                                    <h2 className="text-amber-400 text-[13px] font-semibold">Aguardando autorização</h2>
                                    <span className="text-white/20 text-[11px]">({pending.length})</span>
                                </div>
                                {renderList(pending, '')}
                            </section>
                        )}

                        {/* Não conectadas */}
                        <section className="space-y-3">
                            <div className="flex items-center gap-2">
                                <span className="w-3 h-3 rounded-full border border-white/20" />
                                <h2 className="text-white/40 text-[13px] font-semibold">Não conectadas</h2>
                                <span className="text-white/20 text-[11px]">({rest.length})</span>
                            </div>
                            {renderList(rest, 'Todas as empresas estão conectadas.')}
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
