import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Info, Loader2, Plus, Save } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash } from '@/Components/Portal/Estrutura/comum';
import FichaDadosGerais from '@/Components/Portal/Estrutura/Produtos/FichaDadosGerais';
import FichaTecnica from '@/Components/Portal/Estrutura/Produtos/FichaTecnica';
import CartaoVariacao from '@/Components/Portal/Estrutura/Produtos/CartaoVariacao';
import JanelaExcluirVariacao from '@/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao';
import useFichaProduto from '@/Components/Portal/Estrutura/Produtos/useFichaProduto';
import { textoProdutoSalvo } from '@/lib/produtosEstrutura';
import { definirGuardaDoVoltar } from '@/lib/guardaDoVoltar';
import {
    entradaAtual, esquecerAbertura, fichaAbertaPelaLista, marcarUltimoProduto, passosAte, podeVoltarNoHistorico, urlDeVolta,
    voltarParaLista, voltarPeloHistorico,
} from '@/lib/produtosNavegacao';

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

export default function EstruturaProdutoFicha({ empresa, modulos = [], produto, linhas = [], listas: listasIniciais, vocabulario, limites, ficha_tecnica: fichaTecnica }) {
    const ficha = useFichaProduto({ linhas, produto, vocabulario, limites, fichaTecnica });
    const [listas, setListas] = useState(listasIniciais ?? { familias: [], ambientes: [] });
    const [exclusao, setExclusao] = useState(null);   // { linha, ultima } | null
    const liberado = useRef(false);
    const alteradoRef = useRef(ficha.alterado);
    alteradoRef.current = ficha.alterado;
    const fichaRef = useRef(ficha);
    fichaRef.current = ficha;
    const esperaVolta = useRef(null);   // saída pelo histórico à espera do popstate (FE-WR-05)
    // D-32: o produto que esta ficha representa — a lista destaca o cartão dele na volta, saia como sair.
    const ultimoRef = useRef(produto?.id ?? null);
    useEffect(() => () => marcarUltimoProduto(ultimoRef.current), []);

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
            if (window.confirm(CONFIRMA_SAIR)) { liberado.current = true; fichaRef.current.esquecerRascunho(); return true; }

            return false;
        });
        // A visita liberada terminou e a ficha continua aqui (a rede caiu, a sessão expirou, outra visita
        // cancelou a primeira): a guarda volta a valer, como no usePublicador (FE-WR-03). Saindo de
        // verdade a ficha desmonta e este ouvinte vai junto.
        const tirarFim = router.on('finish', () => { liberado.current = false; });

        return () => {
            window.removeEventListener('beforeunload', aoFecharAba);
            tirarGuarda();
            tirarFim();
        };
    }, []);

    // Voltar do navegador (botão, Alt+←, gesto do celular): o Inertia troca a página sem o evento
    // `before`. A guarda entra pelo ouvinte de `guardaDoVoltar`, registrado no app.jsx ANTES do Inertia
    // (no próprio window a captura não passa à frente; vale a ordem de registro): com alteração não
    // salva, pergunta; quem fica tem o histórico devolvido à entrada da ficha, e nenhum dos dois
    // popstates chega ao Inertia. Se algo falhar, ainda há o rascunho (FE-CR-02).
    const entradaDaFicha = useRef(entradaAtual());
    const ignorarVolta = useRef(false);
    useEffect(() => {
        const aoNavegarNoHistorico = (e) => {
            clearTimeout(esperaVolta.current);   // a saída pelo histórico chegou (FE-WR-05)
            if (ignorarVolta.current) {
                ignorarVolta.current = false;
                e.stopImmediatePropagation();

                return;
            }
            if (liberado.current || ! alteradoRef.current) return;
            if (window.confirm(CONFIRMA_SAIR)) {
                liberado.current = true;
                fichaRef.current.esquecerRascunho();

                return;
            }
            e.stopImmediatePropagation();
            ignorarVolta.current = true;
            setTimeout(() => { ignorarVolta.current = false; }, 1000);
            window.history.go(passosAte(entradaDaFicha.current));
        };
        return definirGuardaDoVoltar(aoNavegarNoHistorico);
    }, []);

    // ─── Saída para a lista (D-27; revisão FE-WR-05) ────────────────────────
    //
    // Aberta pela lista, a ficha sai voltando no histórico: fica UMA entrada da lista, que se
    // recarrega ao montar. Sem a lista atrás (URL direta, outra aba), visita a lista trocando a
    // entrada da ficha. A entrada da ficha que fica à frente recebe antes os dados de agora (`entrada`),
    // para o "avançar" do navegador não abrir a ficha velha.

    const [abertaPelaLista] = useState(() => fichaAbertaPelaLista());
    useEffect(() => { esquecerAbertura(); }, []);
    useEffect(() => () => clearTimeout(esperaVolta.current), []);

    const irParaLista = ({ aviso = null, produtoId = null, entrada = null } = {}) => {
        if (! podeVoltarNoHistorico(abertaPelaLista)) {
            voltarParaLista({ aviso, produtoId, replace: true });

            return;
        }
        const voltar = () => {
            // Se o popstate não vier (histórico diferente do esperado), a visita comum leva para a lista.
            esperaVolta.current = setTimeout(() => voltarParaLista({ aviso, produtoId, replace: true }), 1500);
            voltarPeloHistorico({ aviso, produtoId });
        };
        if (entrada) router.replace({ ...entrada, preserveState: true, preserveScroll: true, onFinish: voltar });
        else voltar();
    };

    /** A entrada da ficha com o que o servidor acabou de devolver: URL do produto e as variações de agora. */
    const entradaDoProduto = (id, data, salvosDaFicha = null) => {
        const porId = new Map();
        (data?.linhas ?? []).filter((l) => l.produto_id === id).forEach((l) => porId.set(l.id, l));
        const atuais = [...porId.values()];

        return {
            url: route('portal.auth.estrutura.produtos.ficha', id, false),
            props: (props) => ({ ...props, produto: { id, nome: atuais[0]?.nome ?? props.produto?.nome ?? '' }, linhas: atuais, listas: data?.listas ?? props.listas,
                ...(salvosDaFicha ? { ficha_tecnica: { salvos: salvosDaFicha } } : {}),
            }),
        };
    };

    // ─── Ações ──────────────────────────────────────────────────────────────

    /** Cancelar e "← Produtos": com alteração não salva, a confirmação de sempre. */
    const sair = () => {
        if (alteradoRef.current && ! liberado.current) {
            if (! window.confirm(CONFIRMA_SAIR)) return;
            liberado.current = true;
            ficha.esquecerRascunho();
        }
        irParaLista();
    };

    const aoClicarProdutos = (e) => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        sair();
    };

    const salvarProduto = async () => {
        const novo = ficha.novoProduto;
        const r = await ficha.salvar();
        if (r.data?.listas) setListas(r.data.listas);
        // Produto novo ganha id no 1º lote gravado, mesmo se outro lote falhar: a volta já sabe qual destacar.
        const idGravado = (r.data?.linhas ?? []).find((l) => l.produto_id)?.produto_id;
        if (idGravado) ultimoRef.current = idGravado;
        if (! r.ok) {
            // Erros nos blocos; a gravação parcial já foi aplicada pelo hook. Produto novo que passou a
            // existir deixa de estar em /novo: recarregar abre o produto, com o rascunho do resto (FE-IN-10).
            if (novo && idGravado) router.replace({ ...entradaDoProduto(idGravado, r.data), preserveState: true, preserveScroll: true });

            return;
        }

        liberado.current = true;
        irParaLista({
            aviso: textoProdutoSalvo(r.data),
            // Produto novo: a lista rola até o cartão dele; editado, volta à mesma rolagem.
            produtoId: novo ? (idGravado ?? null) : null,
            entrada: idGravado ? entradaDoProduto(idGravado, r.data, r.fichaTecnica) : null,
        });
    };

    const excluir = (variacao) => {
        if (! variacao.id) { ficha.removerVariacao(variacao._k); return; }   // ainda não gravada: some sem confirmação
        const ultima = ficha.vars.filter((x) => x.id).length <= 1;
        // Última gravada com variação nova na ficha: excluir levaria o produto e a nova junto (FE-WR-04).
        setExclusao({ linha: variacao, ultima, novasNaoSalvas: ultima && ficha.vars.some((x) => ! x.id) });
    };

    const aoExcluida = (resposta) => {
        const linha = exclusao.linha;
        setExclusao(null);
        const sobraram = ficha.vars.filter((x) => x.id && x._k !== linha._k).length;
        if (resposta?.produto_excluido || sobraram === 0) {
            liberado.current = true;
            ultimoRef.current = null;   // o produto deixou de existir: nada a destacar
            ficha.esquecerRascunho();
            irParaLista({
                aviso: resposta?.mensagem ?? null,
                // A entrada à frente deixa de apontar para o produto excluído: o "avançar" abre uma ficha em branco.
                entrada: { url: route('portal.auth.estrutura.produtos.novo', {}, false), props: (props) => ({ ...props, produto: null, linhas: [] }) },
            });

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
                    <h1 className="mt-4 font-display text-[32px] font-bold leading-tight text-white lg:mt-2.5 lg:text-[42px]">{nome}</h1>
                    <p className="mt-2 text-[15px] text-white/70 lg:mt-0 lg:text-[17px]">Preencha o produto uma vez. Cada variação vira uma oferta na Lista SKUs.</p>
                </div>

                {ficha.rascunho && (
                    <div role="status" className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/60" data-rascunho>
                        <span>Você tinha alterações não salvas neste produto.</span>
                        <span className="flex items-center gap-3">
                            <button type="button" onClick={ficha.recuperarRascunho} disabled={ficha.salvando} data-acao="recuperar-rascunho" className="font-medium text-white/85 hover:text-white hover:underline">Recuperar</button>
                            <button type="button" onClick={ficha.descartarRascunho} disabled={ficha.salvando} data-acao="descartar-rascunho" className="text-white/50 hover:text-white">Descartar</button>
                        </span>
                    </div>
                )}

                {ficha.aviso && (
                    <p role="alert" className="mt-4 rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12px] text-red-200">{ficha.aviso}</p>
                )}

                {/* Durante o "Salvando…" nada se edita: o envio usa a foto do clique e o que se digitasse se perderia (FE-WR-02). */}
                <fieldset disabled={ficha.salvando} className="mt-4 min-w-0 border-0 p-0 lg:mt-2" data-campos-ficha>
                    <FichaDadosGerais ficha={ficha} listas={listas} onListas={setListas} />
                </fieldset>

                {/* Variações ANTES da Ficha técnica: é o miolo do cadastro (Ref, custo, volumes,
                    imagens) e precede a lista longa de características da categoria. Dados gerais
                    continua no topo porque a Ficha técnica só existe depois da categoria escolhida. */}
                <section className="mt-2.5 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:px-5 lg:pb-2 lg:pt-2">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-[20px] font-bold text-white">Variações</h2>
                        <p className="flex items-center gap-2 text-[14px] text-white/70">
                            <span className="hidden sm:inline">Cada variação vira uma oferta na Lista SKUs.</span>
                            {/* No <svg> o `title` não vira dica: ela mora no span (FE-IN-05). */}
                            <span className="inline-flex" title="Nova variação já vem com eixo, volumes e custo da primeira; mude só o que for diferente.">
                                <Info size={16} aria-hidden="true" />
                            </span>
                        </p>
                    </div>

                    <fieldset disabled={ficha.salvando} className="min-w-0 border-0 p-0" data-campos-variacoes>
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
                    </fieldset>
                </section>

                <FichaTecnica tecnica={ficha.tecnica} salvando={ficha.salvando} />

                {/* Cancelar/Salvar saíram de dentro de Variações: com a Ficha técnica depois dela,
                    ali os botões ficariam no meio da página. As margens negativas acompanham o
                    padding da página (px-4 / sm:px-6) para a barra encostada do celular. */}
                <div className="mt-2.5 flex justify-end gap-3 max-lg:sticky max-lg:bottom-0 max-lg:-mx-4 max-lg:border-t max-lg:border-white/[0.08] max-lg:bg-ecf-bg/95 max-lg:px-4 max-lg:py-3 max-sm:-mx-4 sm:max-lg:-mx-6 sm:max-lg:px-6">
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
            </div>

            <JanelaExcluirVariacao aberta={!! exclusao} linha={exclusao?.linha} ultima={exclusao?.ultima} novasNaoSalvas={exclusao?.novasNaoSalvas}
                onFechar={() => setExclusao(null)} onExcluida={aoExcluida} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
