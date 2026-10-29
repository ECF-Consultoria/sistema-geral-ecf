import { useLayoutEffect, useRef } from 'react';
import Explicacao from '@/Components/Explicacao';
import { LIMITE_DESCRICAO } from '@/Components/Portal/Estrutura/Produtos/useDescricaoProduto';

// ─── Bloco "Descrição do produto" da ficha ──────────────────────────────────
//
// Texto livre escrito pelo cliente; vai junto no "Salvar produto". `explicacao`:
// o "o que é isto?" ao lado do título (texto do servidor).
//
// 29/10/2026: a caixa cresce com o texto (eram 8 linhas fixas com rolagem própria — os
// 746 caracteres da Poltrona Opala ficavam escondidos) e o contador vai para o cabeçalho.

export default function FichaDescricao({ descricao, salvando, explicacao = null }) {
    const caixa = useRef(null);
    useLayoutEffect(() => {
        const el = caixa.current;
        if (! el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.max(160, el.scrollHeight + 2)}px`;
    }, [descricao.texto]);

    return (
        <section id="secao-descricao" className="scroll-mt-4 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5" data-ficha-descricao>
            <div className="flex items-start gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-1">
                        <h2 className="text-[19px] font-bold text-white">Descrição do produto</h2>
                        <Explicacao texto={explicacao} nome="Descrição do produto" />
                    </div>
                    <p className="mt-0.5 text-[13.5px] text-white/65">Conte para que serve, os diferenciais, os cuidados e o que acompanha o produto.</p>
                </div>
                <p className="ml-auto shrink-0 pt-1 text-[12px] tabular-nums text-white/45" data-contador-descricao>{descricao.texto.length}/{LIMITE_DESCRICAO}</p>
            </div>

            {descricao.erro && (
                <p role="alert" className="mt-3 rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12px] text-red-200" data-erro-descricao>{descricao.erro}</p>
            )}

            <fieldset disabled={salvando} className="mt-3 min-w-0 border-0 p-0">
                <textarea ref={caixa} id="descricao-produto" value={descricao.texto} rows={6} maxLength={LIMITE_DESCRICAO}
                    aria-label="Descrição do produto"
                    onChange={(e) => descricao.alterar(e.target.value)}
                    className="w-full resize-none overflow-hidden rounded-xl border border-white/[0.12] bg-black/30 px-4 py-3 text-[14px] leading-relaxed text-white placeholder:text-white/40 focus:border-ecf-yellow/50 focus:outline-none" />
            </fieldset>
        </section>
    );
}
