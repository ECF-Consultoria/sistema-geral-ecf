import { AlertCircle, BookOpen, X } from 'lucide-react';

// ─── Ajuda do passo: tutorial em vídeo + passo a passo em texto ──────────────
//
// Desenho espelhado do portal de Polos (`Pages/Mlb/ImplementacaoPublica.jsx`),
// que é a tela que o cliente já usa há meses: botão vermelho para vídeo, botão
// amarelo para texto, e a mesma caixa âmbar de "Atenção" no fim do modal.
//
// POR QUE ISTO É CÓPIA E NÃO IMPORT: os originais moram dentro de um arquivo de
// 128 KB em produção, sem export. Extrair de lá para cá mexeria na tela que
// funciona bem hoje — risco que este trabalho não pediu. Se um dia o portal de
// Polos for refatorado, ELE passa a importar destes componentes (o desenho já é
// o mesmo), nunca o contrário.
//
// As duas cores são semânticas e não devem ser unificadas: vermelho = vídeo
// (YouTube), amarelo ECF = texto. O cliente aprende a diferença pela cor antes
// de ler o rótulo.

// ─── YouTube embed ───────────────────────────────────────────────────────────

function toEmbedUrl(url) {
    if (!url) return null;
    try {
        const u = new URL(url);
        if (u.hostname === 'youtu.be') return `https://www.youtube.com/embed${u.pathname}`;
        const v = u.searchParams.get('v');
        if (v) return `https://www.youtube.com/embed/${v}`;
        if (u.pathname.startsWith('/embed/')) return url;
    } catch { /* URL inválida */ }
    return null;
}

// Botão do tutorial em vídeo. Sem URL não renderiza nada — é o que permite
// deixar `TUTORIAIS` vazio no backend sem produzir botão morto na tela.
export function TutorialBtn({ url, titulo, onPlay }) {
    if (!url) return null;
    return (
        <button
            onClick={() => onPlay(url, titulo)}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 hover:text-red-300 text-[11px] font-medium transition-all"
        >
            <BookOpen size={11} />
            Tutorial
        </button>
    );
}

export function VideoModal({ url, titulo, onClose }) {
    const embedUrl = toEmbedUrl(url);
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-3xl" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between mb-3">
                    <p className="text-white font-semibold text-[14px]">{titulo}</p>
                    <button onClick={onClose} className="p-1.5 text-white/40 hover:text-white transition-colors">
                        <X size={18} />
                    </button>
                </div>
                <div className="relative w-full rounded-2xl overflow-hidden bg-black" style={{ paddingTop: '56.25%' }}>
                    {embedUrl ? (
                        <iframe
                            src={embedUrl}
                            title={titulo}
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowFullScreen
                            className="absolute inset-0 w-full h-full"
                        />
                    ) : (
                        <div className="absolute inset-0 flex items-center justify-center text-white/40 text-[13px]">
                            URL de vídeo inválida.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// Botão do passo a passo em texto. Só aparece onde a chave do passo tem
// conteúdo (`DefinicaoOnboarding::passoAPassoDe()`).
export function PassoAPassoBtn({ conteudo, onOpen }) {
    if (!conteudo) return null;
    return (
        <button
            onClick={() => onOpen(conteudo)}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[11px] font-medium transition-all"
        >
            <BookOpen size={11} />
            Passo a passo
        </button>
    );
}

// Modal sobreposto: saudação + passos numerados + caixa de atenção (opcional).
// Fecha no X ou no clique do backdrop, igual ao VideoModal.
export function PassoAPassoModal({ conteudo, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" onClick={onClose}>
            <div
                className="w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-2xl border border-white/[0.08] bg-ecf-card shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Cabeçalho fixo */}
                <div className="sticky top-0 flex items-center justify-between gap-3 px-5 py-4 border-b border-white/[0.06] bg-ecf-card">
                    <p className="text-white font-semibold text-[15px]">{conteudo.titulo}</p>
                    <button onClick={onClose} className="p-1.5 text-white/40 hover:text-white transition-colors shrink-0">
                        <X size={18} />
                    </button>
                </div>

                {/* Corpo */}
                <div className="px-5 py-4 space-y-4">
                    <p className="text-white/70 text-[13px] leading-relaxed">{conteudo.saudacao}</p>

                    <ol className="space-y-2.5">
                        {(conteudo.passos ?? []).map((passo, i) => (
                            <li key={i} className="flex items-start gap-3">
                                <span className="flex items-center justify-center w-6 h-6 rounded-full bg-ecf-yellow/15 text-ecf-yellow text-[12px] font-bold shrink-0">
                                    {i + 1}
                                </span>
                                <span className="text-white/70 text-[13px] leading-relaxed pt-0.5">{passo}</span>
                            </li>
                        ))}
                    </ol>

                    {/* Caixa de atenção — só quando a chave tem uma pegadinha real */}
                    {conteudo.atencao && (
                        <div className="flex items-start gap-2.5 p-3.5 rounded-xl bg-amber-500/[0.08] border border-amber-500/25">
                            <AlertCircle size={16} className="text-amber-400 shrink-0 mt-0.5" />
                            <div>
                                <p className="text-amber-300 font-semibold text-[11px] uppercase tracking-wider mb-1">Atenção</p>
                                <p className="text-white/70 text-[13px] leading-relaxed">{conteudo.atencao}</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
