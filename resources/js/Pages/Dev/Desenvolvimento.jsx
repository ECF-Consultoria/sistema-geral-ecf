import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Code2, ExternalLink, Copy, Check, FileText, Puzzle, RefreshCw, ChevronDown, AlertTriangle, Activity } from 'lucide-react';
import { format } from 'date-fns';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';

// Formata timestamp ISO para exibição compacta no painel de sync
const fmtTs = (iso) => iso ? format(new Date(iso), 'dd/MM HH:mm') : null;

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

// Exibe contagem de um tipo de diff (criados/atualizados/ignorados)
function DiffBadge({ label, count, variant }) {
    const estilos = {
        criados:     'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        atualizados: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
        ignorados:   'bg-white/[0.05] text-white/40 border-white/[0.08]',
    };
    return (
        <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded text-[12px] font-mono border', estilos[variant])}>
            <span className="font-semibold">{count ?? 0}</span>
            <span className="text-[11px]">{label}</span>
        </span>
    );
}

// Exibe payload JSON formatado do sync Adman
function JsonViewer({ data }) {
    if (!data) {
        return <p className="text-white/30 text-[12px] italic">Payload não disponível.</p>;
    }
    return (
        <pre className="text-[12px] font-mono text-white/70 bg-black/40 rounded-lg p-3 overflow-x-auto max-h-64 leading-relaxed">
            {JSON.stringify(data, null, 2)}
        </pre>
    );
}

// Painel expandido com diff e payload bruto de uma empresa
function EmpresaAccordion({ empresa }) {
    return (
        <div className="px-4 py-3 bg-black/30 border border-white/[0.04]">
            {empresa.error && (
                <>
                    <p className="text-[11px] uppercase tracking-wider text-white/40">Erro do último sync</p>
                    <pre className="text-red-400 text-[12px] font-mono whitespace-pre-wrap mt-1">{empresa.error}</pre>
                </>
            )}
            <p className={cn('text-[11px] uppercase tracking-wider text-white/40 mb-2', empresa.error && 'mt-3')}>
                Resultado do último sync
            </p>
            <div className="flex gap-2 mb-4">
                <DiffBadge label="criados" count={empresa.criados} variant="criados" />
                <DiffBadge label="atualizados" count={empresa.atualizados} variant="atualizados" />
                <DiffBadge label="ignorados" count={empresa.ignorados} variant="ignorados" />
            </div>
            <p className="text-[11px] uppercase tracking-wider text-white/40 mb-2">Payload bruto</p>
            <JsonViewer data={empresa.raw_data} />
        </div>
    );
}

// Linha de empresa com status, timestamp e botão de dispatch
function EmpresaRow({ empresa, expandida, onToggle }) {
    const [disparando, setDisparando] = useState(false);
    const tsFormatado = fmtTs(empresa.synced_at);

    function disparar(e) {
        e.stopPropagation(); // evita propagar ao accordion
        router.post(
            route('dev.adman.sync', empresa.id),
            {},
            {
                onStart:  () => setDisparando(true),
                onFinish: () => setDisparando(false),
            }
        );
    }

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-4 px-2 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]'
            )}
        >
            <ChevronDown
                size={14}
                className={cn('text-white/40 transition-transform duration-200 shrink-0', expandida && 'rotate-180 text-ecf-yellow')}
            />
            <span className="flex-1 text-white font-semibold text-[13px] truncate">{empresa.name}</span>
            {tsFormatado ? (
                <code className="text-white/60 text-[12px] font-mono shrink-0" title={empresa.synced_at}>
                    {tsFormatado}
                </code>
            ) : (
                <span className="text-white/30 text-[12px] italic shrink-0">Nunca sincronizado</span>
            )}
            <Badge variant={empresa.status === 'erro' ? 'destructive' : 'success'} className="shrink-0">
                {empresa.status === 'erro' ? 'Erro' : 'OK'}
            </Badge>
            <Button
                variant="ghost"
                size="sm"
                disabled={disparando}
                onClick={disparar}
                className="bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[12px] shrink-0"
            >
                <RefreshCw size={12} className={cn(disparando && 'animate-spin')} />
                {disparando ? 'Disparando...' : 'Disparar sync'}
            </Button>
        </div>
    );
}

// Seção principal do painel de sync Adman com lista de empresas
function SyncAdmanSection({ empresas }) {
    const [aberta, setAberta] = useState(null);

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    if (!empresas || empresas.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 py-8 text-center">
                <AlertTriangle size={24} className="text-white/20" />
                <p className="text-white/40 text-[13px]">Nenhuma empresa com conta Adman configurada.</p>
                <p className="text-white/30 text-[11px]">Adicione o campo `adman_account_id` a uma empresa para monitorar o sync.</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <div key={empresa.id}>
                    <EmpresaRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => toggleEmpresa(empresa.id)}
                    />
                    {aberta === empresa.id && <EmpresaAccordion empresa={empresa} />}
                </div>
            ))}
        </div>
    );
}

export default function Desenvolvimento({ empresas = [] }) {
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

                <DevCard icon={Activity} title="Sync Adman" subtitle="Status e controle do sync de dados por empresa">
                    <SyncAdmanSection empresas={empresas} />
                </DevCard>


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
