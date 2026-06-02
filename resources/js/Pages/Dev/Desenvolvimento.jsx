import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Activity, AlertTriangle, Check, Code2, Copy, ExternalLink, FileText, Puzzle, RefreshCw } from 'lucide-react';
import { useState } from 'react';

function CopyBtn({ text }) {
    const [copied, setCopied] = useState(false);
    function copy() {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }
    return (
        <button
            onClick={copy}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white transition-colors text-[12px]"
            title="Copiar URL"
        >
            {copied ? <Check size={12} className="text-emerald-400" /> : <Copy size={12} />}
            {copied ? 'Copiado' : 'Copiar'}
        </button>
    );
}

function DevCard({ icon: Icon, title, subtitle, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
            <div className="flex items-start gap-3 mb-4">
                <div className="w-10 h-10 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <Icon size={18} className="text-ecf-yellow" />
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-white font-semibold text-[15px] leading-tight">{title}</h3>
                    {subtitle && <p className="text-white/40 text-[12px] mt-0.5">{subtitle}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}

function LinkRow({ label, url }) {
    const absoluteUrl = url.startsWith('http') ? url : `${window.location.origin}${url}`;
    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-2 rounded-lg bg-black/30 border border-white/[0.04] px-3 py-2.5">
            <div className="flex-1 min-w-0">
                <p className="text-white/40 text-[11px] uppercase tracking-wider mb-0.5">{label}</p>
                <code className="text-white/80 text-[12px] font-mono break-all">{absoluteUrl}</code>
            </div>
            <div className="flex items-center gap-1.5 shrink-0">
                <CopyBtn text={absoluteUrl} />
                <a
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow transition-colors text-[12px]"
                    title="Abrir em nova aba"
                >
                    <ExternalLink size={12} />
                    Abrir
                </a>
            </div>
        </div>
    );
}

// Retorna as classes de cor do badge conforme a severidade do alerta
function sevBadge(sev) {
    return cn(
        'inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium border',
        sev === 'alta'
            ? 'text-red-400 bg-red-400/10 border-red-400/20'
            : sev === 'media'
                ? 'text-amber-400 bg-amber-400/10 border-amber-400/20'
                : 'text-white/40 bg-white/[0.04] border-white/[0.08]',
    );
}

// Linha de alerta com badge de severidade, descrição e botão de re-sync
function AlertRow({ item, resyncing, onResync }) {
    const emEnvio = resyncing === item.company_id;
    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-2 rounded-lg bg-black/30 border border-white/[0.04] px-3 py-2.5">
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-0.5">
                    <span className="text-white/80 text-[13px] font-medium truncate">{item.empresa}</span>
                    <span className={sevBadge(item.severidade)}>
                        {item.severidade}
                    </span>
                </div>
                <p className="text-white/60 text-[12px] leading-relaxed">{item.descricao}</p>
            </div>
            <div className="shrink-0">
                <button
                    onClick={() => onResync(item.company_id)}
                    disabled={emEnvio}
                    className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow transition-colors text-[12px] disabled:opacity-50 disabled:cursor-not-allowed"
                    title="Re-disparar sync para esta empresa"
                >
                    <RefreshCw size={12} className={emEnvio ? 'animate-spin' : ''} />
                    {emEnvio ? 'Disparando…' : 'Re-disparar sync'}
                </button>
            </div>
        </div>
    );
}

export default function Desenvolvimento({ diagnostico }) {
    // Defaults seguros caso a prop chegue undefined (ex.: erros de bootstrap)
    const diag = diagnostico ?? {
        sem_sync: [],
        total: 0,
    };

    // Guarda o company_id do botão de re-sync que está em envio
    const [resyncing, setResyncing] = useState(null);

    function resync(companyId) {
        setResyncing(companyId);
        router.post(
            route('dev.resync'),
            { company_id: companyId },
            {
                preserveScroll: true,
                onFinish: () => setResyncing(null),
            },
        );
    }

    return (
        <AppLayout title="Desenvolvimento">
            <div className="max-w-4xl mx-auto space-y-6">
                <div>
                    <div className="flex items-center gap-2 mb-2">
                        <Code2 size={20} className="text-ecf-yellow" />
                        <h1 className="text-white font-display font-bold text-xl">Desenvolvimento</h1>
                    </div>
                    <p className="text-white/40 text-[13px]">
                        Área interna para projetos paralelos, integrações e ferramentas em desenvolvimento.
                    </p>
                </div>

                <DevCard
                    icon={Puzzle}
                    title="Painel ECF — Extensão Chrome"
                    subtitle="ID: eaofkkacbkmiialocohbibjalhkbmcib"
                >
                    <p className="text-white/60 text-[13px] mb-3">
                        Extensão para Chrome que adiciona funcionalidades de automação e prompts em ChatGPT e Gemini.
                        A URL abaixo é a página oficial de Política de Privacidade — usar no formulário de submissão
                        da Chrome Web Store.
                    </p>
                    <LinkRow
                        label="Política de Privacidade (pública)"
                        url="/privacidade/painel-ecf"
                    />
                </DevCard>

                {/* ─── Diagnóstico Adman ──────────────────────────────────── */}
                {diag.total === 0 ? (
                    // Estado vazio: nenhum alerta de sync no momento
                    <DevCard
                        icon={Activity}
                        title="Diagnóstico Adman"
                        subtitle="Heurística sobre dados locais — sem chamada externa"
                    >
                        <p className="text-white/40 text-[13px]">
                            Tudo certo — nenhum alerta no momento.
                        </p>
                    </DevCard>
                ) : (
                    <>
                        {/* Card: empresas sem sync recente */}
                        {diag.sem_sync.length > 0 && (
                            <DevCard
                                icon={AlertTriangle}
                                title="Empresas sem sync recente"
                                subtitle={`${diag.sem_sync.length} empresa${diag.sem_sync.length > 1 ? 's' : ''} com alerta`}
                            >
                                <div className="space-y-2">
                                    {diag.sem_sync.map((item) => (
                                        <AlertRow
                                            key={`semSync-${item.company_id}`}
                                            item={item}
                                            resyncing={resyncing}
                                            onResync={resync}
                                        />
                                    ))}
                                </div>
                            </DevCard>
                        )}

                    </>
                )}

                <div className="rounded-xl border border-dashed border-white/[0.08] p-5 text-center">
                    <FileText className="mx-auto text-white/20 mb-2" size={28} />
                    <p className="text-white/40 text-[13px]">
                        Outros projetos em desenvolvimento aparecerão aqui.
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
