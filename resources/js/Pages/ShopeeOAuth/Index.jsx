import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Copy, Check, RefreshCw, CheckCircle2, Clock, XCircle, Unlink, ExternalLink, Store, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

const SHOPEE = '#ee4d2d'; // laranja da marca Shopee

const fmtDate = (iso) => iso ? new Date(iso).toLocaleDateString('pt-BR') : '—';

// "há 3h", "há 2 dias" — tempo desde a última renovação do token.
function tempoDesde(iso) {
    if (!iso) return null;
    const horas = Math.floor((new Date() - new Date(iso)) / (1000 * 60 * 60));
    if (horas < 1) return 'há menos de 1h';
    if (horas < 48) return `há ${horas}h`;
    return `há ${Math.floor(horas / 24)} dias`;
}

function daysLeft(expiresAt) {
    if (!expiresAt) return null;
    return Math.ceil((new Date(expiresAt) - new Date()) / (1000 * 60 * 60 * 24));
}

function StatusBadge({ company }) {
    const token = company.shopee_token;
    const ads   = company.shopee_ads_token;

    if (token?.status === 'active' && (token.com_problema || ads?.com_problema)) {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-400/10 border border-amber-400/20 text-amber-400 text-[11px] font-semibold">
                <AlertTriangle size={11} />
                Falha na renovação
            </span>
        );
    }

    if (token?.status === 'active') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-semibold">
                <CheckCircle2 size={11} />
                Conectada
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

    // Link gerado (pendente) — a expiração vem do banco ou do "Regerar link" desta sessão
    if (company.shopee_link_generated_at) {
        const expires = company.shopee_link_expires_at;
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
                {expired ? 'Convite expirado' : `Aguardando cliente${days !== null ? ` · ${days}d` : ''}`}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/[0.04] border border-white/[0.08] text-white/30 text-[11px] font-semibold">
            Não conectada
        </span>
    );
}

function CompanyRow({ company, adsConfigured, onDisconnect, onLinkGenerated }) {
    const [loading, setLoading]             = useState(false);
    const [sessionUrl, setSessionUrl]       = useState(null); // URL gerada nesta sessão
    const [copied, setCopied]               = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);

    const token = company.shopee_token;
    const ads   = company.shopee_ads_token;
    const isConnected = token?.status === 'active';
    const linkDays = daysLeft(company.shopee_link_expires_at);
    const linkExpirado = linkDays !== null && linkDays <= 0;
    // Falhas do ERP e do Ads que ainda não foram superadas por uma renovação.
    const falhas = [['Shopee', token], ['Ads', ads]]
        .filter(([, t]) => t?.com_problema)
        .map(([nome, t]) => `${nome}: ${t.last_error ?? `sem renovação ${tempoDesde(t.last_refreshed_at) ?? 'registrada'}`}`);
    // URL disponível: prioriza sessão atual (mais fresca), cai para banco
    const linkUrl = sessionUrl ?? company.shopee_link_url;

    const gerarLink = async () => {
        setLoading(true);
        try {
            const res = await fetch(route('shopee.oauth.initiate', company.id), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.url) {
                setSessionUrl(data.url);
                onLinkGenerated?.(company.id, data);
            }
        } finally {
            setLoading(false);
        }
    };

    const copiar = () => {
        if (!linkUrl) return;
        navigator.clipboard.writeText(linkUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const desconectar = async () => {
        if (!confirm(`Desconectar Shopee de "${company.name}"?`)) return;
        setDisconnecting(true);
        try {
            await fetch(route('shopee.oauth.disconnect', company.id), {
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
                        {token?.shop_id && (
                            <span className="text-white/25 text-[11px] font-mono shrink-0">#{token.shop_id}</span>
                        )}
                    </div>
                    {isConnected && (
                        <p className="text-white/30 text-[11px] mt-0.5">
                            Conectada em {fmtDate(token.connected_at)}
                            {token.last_refreshed_at && ` · renovada ${tempoDesde(token.last_refreshed_at)}`}
                            {adsConfigured && (
                                ads?.status === 'active'
                                    ? ' · Ads conectado'
                                    : <span className="text-amber-400/70">{ads?.status === 'revoked' ? ' · Ads revogado' : ' · Ads não conectado'}</span>
                            )}
                        </p>
                    )}
                    {token?.status === 'revoked' && (
                        <p className="text-white/30 text-[11px] mt-0.5">
                            A Shopee recusou a renovação{token.last_error_at && ` em ${fmtDate(token.last_error_at)}`}. Gere um novo link e peça ao cliente para autorizar de novo.
                        </p>
                    )}
                    {!token && linkExpirado && (
                        <p className="text-white/30 text-[11px] mt-0.5">
                            O cliente não concluiu a autorização em 7 dias. Gere um novo link e reenvie.
                        </p>
                    )}
                </div>

                <StatusBadge company={company} />

                <div className="flex items-center gap-2 shrink-0">
                    {/* Botão copiar — visível sempre que há URL (sessão ou banco) */}
                    {!isConnected && linkUrl && (
                        <button
                            onClick={copiar}
                            title="Copiar link"
                            className="inline-flex items-center justify-center w-7 h-7 rounded-lg border border-white/[0.08] text-white/40 hover:text-white hover:border-white/20 transition-colors"
                        >
                            {copied ? <Check size={12} className="text-emerald-400" /> : <Copy size={12} />}
                        </button>
                    )}
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
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium transition-colors disabled:opacity-40"
                            style={{ backgroundColor: `${SHOPEE}1a`, border: `1px solid ${SHOPEE}33`, color: SHOPEE }}
                        >
                            {loading
                                ? <RefreshCw size={11} className="animate-spin" />
                                : <Store size={11} />
                            }
                            {linkUrl ? 'Regerar link' : 'Gerar link'}
                        </button>
                    )}
                </div>
            </div>

            {/* Falha de renovação: o token segue ativo, mas a última tentativa falhou */}
            {isConnected && falhas.length > 0 && (
                <div className="rounded-lg border border-amber-400/15 bg-amber-400/[0.04] px-3 py-2 text-[11px] text-amber-300/80 space-y-0.5">
                    {falhas.map((f) => <p key={f} className="break-words">{f}</p>)}
                    <p className="text-white/30">O sistema tenta de novo às 03:00 e no sync das 11:15. Se continuar falhando, desconecte e gere um novo link.</p>
                </div>
            )}

            {/* Link disponível (sessão atual ou salvo no banco) */}
            {!isConnected && linkUrl && (
                <div className="rounded-lg border border-amber-400/10 bg-amber-400/[0.03] px-3 py-2 flex items-center gap-2">
                    <code className="flex-1 text-[11px] text-white/50 truncate font-mono">{linkUrl}</code>
                    <span className="text-white/20 text-[10px] shrink-0">
                        {linkDays !== null && (linkDays > 0 ? `${linkDays}d restantes` : 'vencido')}
                    </span>
                </div>
            )}
        </div>
    );
}

export default function ShopeeOAuthIndex({ companies: initial, ads_configured: adsConfigured }) {
    const [companies, setCompanies] = useState(initial);
    const [search, setSearch] = useState('');

    const handleDisconnect = (id) => {
        setCompanies(prev => prev.map(c =>
            c.id === id ? { ...c, shopee_token: null, shopee_ads_token: null, shopee_link_generated_at: null } : c
        ));
    };

    // Usa a data de expiração devolvida pelo servidor. Sem isso, regerar um link
    // vencido continuava mostrando "expirado".
    const handleLinkGenerated = (id, link) => {
        setCompanies(prev => prev.map(c =>
            c.id === id ? {
                ...c,
                shopee_link_generated_at: link.generated_at,
                shopee_link_expires_at:   link.expires_at,
                shopee_link_url:          link.url,
            } : c
        ));
    };

    const renderRow = (c) => (
        <CompanyRow key={c.id} company={c} adsConfigured={adsConfigured} onDisconnect={handleDisconnect} onLinkGenerated={handleLinkGenerated} />
    );

    const connected = companies.filter(c => c.shopee_token?.status === 'active');
    const comFalha  = connected.filter(c => c.shopee_token.com_problema || c.shopee_ads_token?.com_problema);
    const pending   = companies.filter(c => c.shopee_token?.status !== 'active' && c.shopee_link_generated_at);
    const rest      = companies.filter(c => c.shopee_token?.status !== 'active' && !c.shopee_link_generated_at);

    const filtered = search.trim()
        ? companies.filter(c => c.name.toLowerCase().includes(search.toLowerCase()))
        : null;

    const renderList = (list, emptyMsg) => (
        list.length === 0
            ? <p className="text-white/20 text-[12px] px-1">{emptyMsg}</p>
            : <div className="space-y-2">{list.map(renderRow)}</div>
    );

    return (
        <AppLayout title="Shopee OAuth">
            <div className="space-y-6 max-w-3xl">
                {/* Header */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-white font-semibold text-[18px]">Shopee OAuth</h1>
                        <p className="text-white/30 text-[12px] mt-0.5">
                            {connected.length} conectada{connected.length !== 1 ? 's' : ''} · {pending.length} aguardando autorização
                            {comFalha.length > 0 && <span className="text-amber-400"> · {comFalha.length} com falha na renovação</span>}
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
                                : filtered.map(renderRow)
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
                                    <h2 className="text-amber-400 text-[13px] font-semibold">Aguardando autorização do cliente</h2>
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
