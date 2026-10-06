import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Info, Loader2, Plus, Save } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash } from '@/Components/Portal/Estrutura/comum';
import FichaDadosGerais from '@/Components/Portal/Estrutura/Produtos/FichaDadosGerais';
import CartaoVariacao from '@/Components/Portal/Estrutura/Produtos/CartaoVariacao';
import JanelaExcluirVariacao from '@/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao';
import useFichaProduto from '@/Components/Portal/Estrutura/Produtos/useFichaProduto';
import { textoProdutoSalvo } from '@/lib/produtosEstrutura';
import { urlDeVolta, voltarParaLista } from '@/lib/produtosNavegacao';

// ─── Ficha do produto em página inteira (REF-2, Fase 167-19) ────────────────
//
// D-27: página inteira com URL própria, nada de painel lateral, folha ou lista
// ao lado. D-28: só campos reais do sistema; os calculados vêm do servidor. D-29:
// o quadro da foto não tem upload. D-30: nada do que a referência mostra sem ter
// dado (sem "Ver no Mercado Livre", sino, avatar nem menu ⋮).
//
// A regra de gravação está no hook `useFichaProduto` (POST `linhas` do servidor)
// e a ida e volta da lista em `produtosNavegacao`. Aqui só se monta a página,
// se protege o que foi digitado e se decide para onde voltar.

const CONFIRMA_SAIR = 'Há alterações não salvas neste produto. Sair sem salvar?';

export default function EstruturaProdutoFicha({ empresa, modulos = [], produto, linhas = [], listas: listasIniciais, vocabulario, limites }) {
    const ficha = useFichaProduto({ linhas, produto, vocabulario, limites });
    const [listas, setListas] = useState(listasIniciais ?? { familias: [], ambientes: [] });
    const [exclusao, setExclusao] = useState(null);   // { linha, ultima } | null
    const liberado = useRef(false);
    const alteradoRef = useRef(ficha.alterado);
    alteradoRef.current = ficha.alterado;

    const primeira = ficha.primeira;
    const nome = primeira.nome || 'Novo produto';

    // ─── Proteção do que foi digitado (padrão do usePublicador) ─────────────

    useEffect(() => {
        const aoFecharAba = (e) => {
            if (liberado.current || ! alteradoRef.current) return undefined;
            e.preventDefault();
            e.returnValue = '';

            return '';
        };
        window.addEventListener('beforeunload', aoFecharAba);
        const tirarGuarda = router.on('before', (event) => {
            const visita = event.detail.visit;
            if (liberado.current || visita.prefetch || visita.only?.length || visita.except?.length) return true;
            if (! alteradoRef.current) return true;
            if (window.confirm(CONFIRMA_SAIR)) { liberado.current = true; return true; }

            return false;
        });

        return () => {
            window.removeEventListener('beforeunload', aoFecharAba);
            tirarGuarda();
        };
    }, []);

    // ─── Ações ──────────────────────────────────────────────────────────────

    const sair = () => voltarParaLista();

    const aoClicarProdutos = (e) => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        sair();
    };

    const salvarProduto = async () => {
        const novo = ficha.novoProduto;
        const r = await ficha.salvar();
        if (r.data?.listas) setListas(r.data.listas);
        if (! r.ok) return;   // erros nos blocos; gravação parcial já aplicada pelo hook

        liberado.current = true;
        voltarParaLista({
            aviso: textoProdutoSalvo(r.data),
            // Produto novo: a lista rola até o cartão dele; editado, volta à mesma rolagem.
            produtoId: novo ? (r.data.linhas?.[0]?.produto_id ?? null) : null,
            replace: true,
        });
    };

    const excluir = (variacao) => {
        if (! variacao.id) { ficha.removerVariacao(variacao._k); return; }   // ainda não gravada: some sem confirmação
        setExclusao({ linha: variacao, ultima: ficha.vars.filter((x) => x.id).length <= 1 });
    };

    const aoExcluida = (resposta) => {
        const linha = exclusao.linha;
        setExclusao(null);
        const sobraram = ficha.vars.filter((x) => x.id && x._k !== linha._k).length;
        if (resposta?.produto_excluido || sobraram === 0) {
            liberado.current = true;
            voltarParaLista({ aviso: resposta?.mensagem ?? null, replace: true });

            return;
        }
        ficha.removerVariacao(linha._k);
    };

    const novaVariacao = () => {
        const k = ficha.novaVariacao();
        requestAnimationFrame(() => {
            document.getElementById(`valor-${k}`)?.focus();
            document.getElementById(`variacao-${k}`)?.scrollIntoView({ block: 'nearest' });
        });
    };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo={nome}>
            <div className="mx-auto w-full max-w-[1600px] px-4 pb-28 pt-5 sm:px-6 lg:pb-8" data-ficha-produto>
                <div className="lg:px-4">
                    <nav aria-label="Caminho" className="flex items-center gap-2 text-[14px] text-white/70">
                        <ArrowLeft size={18} aria-hidden="true" />
                        <a href={urlDeVolta()} onClick={aoClicarProdutos} className="hover:text-white">Produtos</a>
                        <ChevronRight size={14} aria-hidden="true" className="text-white/40" />
                        <span className="truncate text-white">{nome}</span>
                    </nav>
                    <h1 className="mt-4 font-display text-[32px] font-bold leading-tight text-white lg:text-[40px]">{nome}</h1>
                    <p className="mt-2 text-[15px] text-white/70 lg:text-[17px]">Preencha o produto uma vez. Cada variação vira uma oferta na Lista SKUs.</p>
                </div>

                {ficha.aviso && (
                    <p role="alert" className="mt-4 rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12px] text-red-200">{ficha.aviso}</p>
                )}

                <div className="mt-4">
                    <FichaDadosGerais ficha={ficha} listas={listas} onListas={setListas} />
                </div>

                <section className="mt-2.5 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-[20px] font-bold text-white">Variações</h2>
                        <p className="flex items-center gap-2 text-[14px] text-white/70">
                            <span className="hidden sm:inline">Cada variação vira uma oferta na Lista SKUs.</span>
                            <Info size={16} aria-hidden="true" title="Nova variação já vem com eixo, volumes e custo da primeira; mude só o que for diferente." />
                        </p>
                    </div>

                    <div className="mt-4 space-y-2.5">
                        {ficha.vars.map((v) => (
                            <CartaoVariacao key={v._k} variacao={v} ficha={ficha} vocabulario={vocabulario}
                                podeExcluir={!! v.id || ficha.vars.length > 1} onExcluir={excluir} />
                        ))}
                    </div>

                    <button type="button" onClick={novaVariacao} data-acao="nova-variacao"
                        className="mt-2.5 flex h-11 w-full items-center justify-center gap-2 rounded-[10px] border border-dashed border-white/[0.14] text-[14px] text-white/80 hover:bg-white/[0.03] lg:h-8">
                        <Plus size={14} /> Nova variação
                    </button>

                    <div className="mt-2 flex justify-end gap-3 max-lg:sticky max-lg:bottom-0 max-lg:-mx-4 max-lg:border-t max-lg:border-white/[0.08] max-lg:bg-ecf-bg/95 max-lg:px-4 max-lg:py-3">
                        <button type="button" onClick={sair} data-acao="cancelar"
                            className="h-11 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[14px] text-white hover:bg-white/[0.07] lg:h-9 lg:w-[151px]">
                            Cancelar
                        </button>
                        <button type="button" onClick={salvarProduto} disabled={ficha.salvando} data-acao="salvar-produto"
                            className="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-ecf-yellow px-4 text-[14px] font-semibold text-black hover:brightness-95 disabled:opacity-60 lg:h-9 lg:w-[284px] lg:flex-none">
                            {ficha.salvando ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                            {ficha.salvando ? 'Salvando…' : 'Salvar produto'}
                        </button>
                    </div>
                </section>
            </div>

            <JanelaExcluirVariacao aberta={!! exclusao} linha={exclusao?.linha} ultima={exclusao?.ultima}
                onFechar={() => setExclusao(null)} onExcluida={aoExcluida} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
