import { LIMITE_DESCRICAO } from '@/Components/Portal/Estrutura/Produtos/useDescricaoProduto';

// ─── Bloco "Descrição do produto" da ficha ──────────────────────────────────
//
// Texto livre escrito pelo cliente; vai junto no "Salvar produto".

export default function FichaDescricao({ descricao, salvando }) {
    return (
        <section className="mt-2.5 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:px-5 lg:pb-5 lg:pt-3" data-ficha-descricao>
            <h2 className="text-[20px] font-bold text-white">Descrição do produto</h2>
            <p className="mt-1 text-[14px] text-white/70">Conte para que serve, os diferenciais, os cuidados e o que acompanha o produto.</p>

            {descricao.erro && (
                <p role="alert" className="mt-3 rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12px] text-red-200" data-erro-descricao>{descricao.erro}</p>
            )}

            <fieldset disabled={salvando} className="mt-3 min-w-0 border-0 p-0">
                <textarea id="descricao-produto" value={descricao.texto} rows={8} maxLength={LIMITE_DESCRICAO}
                    aria-label="Descrição do produto"
                    onChange={(e) => descricao.alterar(e.target.value)}
                    className="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-[14px] text-white placeholder:text-white/40 focus:border-ecf-yellow focus:outline-none" />
                <p className="mt-1 text-right text-[12px] text-white/50" data-contador-descricao>{descricao.texto.length}/{LIMITE_DESCRICAO}</p>
            </fieldset>
        </section>
    );
}
