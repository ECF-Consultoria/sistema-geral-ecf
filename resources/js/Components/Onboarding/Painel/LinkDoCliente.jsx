import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Copy, Link2, Eye, EyeOff } from 'lucide-react';

/**
 * Link do portal do cliente — sempre à vista, e com a informação que decide a
 * cobrança: QUANDO o cliente abriu pela última vez.
 *
 * Antes o link voltava como mensagem verde de flash, que some da tela; e
 * `onboarding_links.ultimo_acesso`, gravado a cada visita desde o começo, não
 * era exibido em lugar nenhum. Sem ele, "o cliente não fez" e "o cliente nem
 * viu" pareciam a mesma coisa — e são cobranças completamente diferentes.
 */

function formatar(iso) {
    if (!iso) return null;

    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

export default function LinkDoCliente({ companyId, link }) {
    const [copiado, setCopiado] = useState(false);
    const [gerando, setGerando] = useState(false);

    function copiar() {
        navigator.clipboard?.writeText(link.url).then(() => {
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        });
    }

    function gerar() {
        if (gerando) return;
        setGerando(true);
        router.post(route('onboarding.link.gerar', companyId), {}, {
            preserveScroll: true,
            onFinish: () => setGerando(false),
        });
    }

    if (!link?.existe) {
        return (
            <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5">
                <div className="flex items-center gap-2 mb-2">
                    <Link2 size={16} className="text-white/40 shrink-0" />
                    <h3 className="text-white font-semibold text-[14px]">Portal do cliente</h3>
                </div>
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
        );
    }

    const visto = formatar(link.ultimo_acesso);

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-3">
            <div className="flex items-center gap-2">
                <Link2 size={16} className="text-white/40 shrink-0" />
                <h3 className="text-white font-semibold text-[14px]">Portal do cliente</h3>
            </div>

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
    );
}
