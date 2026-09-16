import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    Check, Clock, Copy, KeyRound, Link2, LogIn, ShieldOff, UserPlus,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// O endereço é público; os dados exigem login e vínculo com a empresa.
export default function AcessoDoClienteAoPortal({ companyId, link }) {
    const [copiado, setCopiado] = useState(false);
    const [erroCopia, setErroCopia] = useState(false);

    const acessos = link?.acessos ?? [];

    const copiar = async () => {
        try {
            await navigator.clipboard.writeText(link.url);
            setCopiado(true);
            setErroCopia(false);
            setTimeout(() => setCopiado(false), 2000);
        } catch {
            setErroCopia(true);
        }
    };

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-4">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 min-w-0">
                    <Link2 size={16} className="text-white/40 shrink-0" />
                    <h3 className="text-white font-semibold text-[14px]">Portal do cliente</h3>
                </div>

                {link?.pode_entrar && (
                    <a
                        href={route('companies.portal.abrir', companyId)}
                        target="_blank"
                        rel="noopener"
                        className="shrink-0 inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg bg-white/[0.06] hover:bg-white/[0.11] text-[12px] text-white/80 transition-colors"
                        title="Abre o portal desta empresa no seu nome — fica registrado"
                    >
                        <LogIn size={12} /> Ver o portal
                    </a>
                )}
            </div>

            <div className="space-y-2.5">
                <p className="text-white/50 text-[12px]">
                    O cliente entra com um código enviado ao e-mail cadastrado. Compartilhar este endereço não libera os dados.
                </p>
                <div className="flex items-center gap-2">
                    <input aria-label="Endereço de login do portal" readOnly value={link?.url ?? ''}
                        className="flex-1 min-w-0 rounded-lg bg-white/[0.04] border border-white/[0.08] px-3 py-1.5 text-[12px] text-white/70" />
                    <button onClick={copiar} disabled={!link?.url}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.06] text-[12px] text-white/80">
                        {copiado ? <Check size={13} /> : <Copy size={13} />}
                        {copiado ? 'Copiado' : 'Copiar login'}
                    </button>
                </div>
                {erroCopia && <p role="status" className="text-amber-300 text-[12px]">Selecione e copie o endereço acima.</p>}
            </div>

            {/* ─── Quem entra com login ────────────────────────────────── */}
            <div className="pt-3.5 border-t border-white/[0.06] space-y-2.5">
                <div className="flex items-center justify-between gap-3">
                    <p className="flex items-center gap-1.5 text-white/50 text-[12px] font-medium">
                        <KeyRound size={12} /> Acesso com login
                    </p>

                    <Link
                        href={route('companies.index', { tab: 'onboarding', sub: 'acessos', portal_company: companyId })}
                        className="inline-flex items-center gap-1 text-white/35 hover:text-white/75 text-[11.5px] transition-colors"
                    >
                        <UserPlus size={11} /> {acessos.length ? 'Gerenciar' : 'Dar acesso'}
                    </Link>
                </div>

                {acessos.length === 0 ? (
                    <p className="text-white/30 text-[11.5px] leading-relaxed">
                        Ninguém desta empresa tem login ainda. Com login, o acesso é de uma pessoa — e dá para
                        saber quem entrou, e tirar de quem saiu.
                    </p>
                ) : (
                    <div className="space-y-1.5">
                        {acessos.map((p) => (
                            <div key={p.id} className="flex items-center justify-between gap-2.5">
                                <div className="min-w-0">
                                    <p className="text-white/80 text-[12.5px] truncate">{p.nome}</p>
                                    <p className="text-white/30 text-[11px] truncate">{p.email}</p>
                                </div>

                                <span className={cn(
                                    'shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10.5px] font-medium',
                                    ! p.ativo
                                        ? 'bg-rose-400/12 text-rose-300'
                                        : p.nunca_entrou
                                            ? 'bg-amber-400/12 text-amber-300'
                                            : 'bg-emerald-400/12 text-emerald-300',
                                )}>
                                    {! p.ativo
                                        ? <><ShieldOff size={10} /> Desativado</>
                                        : p.nunca_entrou
                                            ? <><Clock size={10} /> Nunca entrou</>
                                            : <><Check size={10} /> {p.ultimo_acesso}</>}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
