import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Check, Clock, Copy, Eye, EyeOff, KeyRound, Link2, LogIn, ShieldOff, UserPlus,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Como o cliente entra no portal desta empresa ───────────────────────────
//
// Duas portas convivem, e a tela mostra as duas porque elas respondem coisas
// diferentes:
//
//  - **o link** é de quem tem o endereço. "Aberto em tal dia" não diz QUEM
//    abriu — pode ter sido o cliente, o sócio dele, ou alguém a quem ele
//    repassou;
//  - **o login** é de uma pessoa. "Fulano entrou ontem" é uma frase que se
//    pode usar numa cobrança.
//
// Enquanto o link existir, os dois aparecem. O dia em que ele for aposentado,
// some o bloco de cima e fica só o de baixo.
//
// ### Por que "Ver o portal do cliente" mora aqui
// A pergunta que leva alguém a clicar — "o que ele está vendo?" — nasce
// olhando o onboarding. Estava só no menu de ações do cockpit, a dois cliques
// de distância de quem já está na tela certa.

function formatar(iso) {
    if (! iso) return null;

    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

export default function AcessoDoClienteAoPortal({ companyId, link }) {
    const [copiado, setCopiado] = useState(false);
    const [gerando, setGerando] = useState(false);

    const acessos = link?.acessos ?? [];
    const visto = formatar(link?.ultimo_acesso);

    const copiar = () => {
        navigator.clipboard?.writeText(link.url).then(() => {
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        });
    };

    const gerar = () => {
        if (gerando) return;
        setGerando(true);
        router.post(route('onboarding.link.gerar', companyId), {}, {
            preserveScroll: true,
            onFinish: () => setGerando(false),
        });
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

            {/* ─── O link ──────────────────────────────────────────────── */}
            {! link?.existe ? (
                <div>
                    <p className="text-white/40 text-[12px] mb-3">
                        Esta empresa ainda não tem link. Ele é único por empresa e reúne os passos de todos os serviços.
                    </p>
                    <button
                        onClick={gerar}
                        disabled={gerando}
                        className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold transition-all disabled:opacity-50"
                    >
                        {gerando ? 'Gerando…' : 'Gerar link'}
                    </button>
                </div>
            ) : (
                <div className="space-y-2.5">
                    <div className="flex items-center gap-2">
                        <code className="flex-1 min-w-0 truncate rounded-lg bg-white/[0.04] border border-white/[0.08] px-3 py-1.5 text-[12px] text-white/70">
                            {link.url}
                        </code>
                        <button
                            onClick={copiar}
                            className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white/[0.10] text-[12px] text-white/80 transition-all"
                            aria-label="Copiar link do portal do cliente"
                        >
                            {copiado ? <Check size={13} className="text-emerald-400" /> : <Copy size={13} />}
                            {copiado ? 'Copiado' : 'Copiar'}
                        </button>
                    </div>

                    {/* A pergunta que muda a cobrança: ele abriu? */}
                    <div className="flex items-center gap-1.5">
                        {visto ? (
                            <>
                                <Eye size={13} className="text-white/40 shrink-0" />
                                <span className="text-white/50 text-[12px]">Aberto pela última vez em {visto}</span>
                            </>
                        ) : (
                            <>
                                <EyeOff size={13} className="text-amber-400 shrink-0" />
                                <span className="text-amber-400/90 text-[12px]">
                                    O cliente nunca abriu este link — antes de cobrar, confirme que ele recebeu
                                </span>
                            </>
                        )}
                    </div>
                </div>
            )}

            {/* ─── Quem entra com login ────────────────────────────────── */}
            <div className="pt-3.5 border-t border-white/[0.06] space-y-2.5">
                <div className="flex items-center justify-between gap-3">
                    <p className="flex items-center gap-1.5 text-white/50 text-[12px] font-medium">
                        <KeyRound size={12} /> Acesso com login
                    </p>

                    <Link
                        href={route('companies.index', { tab: 'onboarding', sub: 'acessos' })}
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
