import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Loader2, Plus, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CabecalhoEstrutura, Paginacao } from '@/Components/Portal/Estrutura/comum';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import JanelaSugestoesCategoria from '@/Components/Portal/Estrutura/Produtos/JanelaSugestoesCategoria';
import JanelaListas from '@/Components/Portal/Estrutura/Produtos/JanelaListas';
import JanelaImportacao from '@/Components/Portal/Estrutura/Produtos/JanelaImportacao';
import ListaProdutos from '@/Components/Portal/Estrutura/Produtos/ListaProdutos';
import BarraAcoesProdutos from '@/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos';
import SeletorVisualizacao from '@/Components/Portal/Estrutura/Produtos/SeletorVisualizacao';
import { linhaDoServidor, linhaParaServidor, textoProdutoSalvo } from '@/lib/produtosEstrutura';
import { gravarModo, guardarRetorno, lerModo, mostrarCartao, pegarUltimoProduto, pegarVolta, rolarParaVolta } from '@/lib/produtosNavegacao';

// ─── Mapeamento Estrutural — submódulo Produtos ─────────────────────────────
//
// D-25: o desenho segue a REF-1 (Visual grande) e a REF-3 (Lista) com as cores
// do sistema. D-26: o seletor troca o desenho sem navegar e a escolha fica no
// navegador (`lerModo`/`gravarModo`).
//
// D-23: nada de planilha dentro do sistema. Os produtos aparecem numa lista de
// cartões (uma variação por linha dentro do cartão, D-03) e UMA ficha do
// produto, em PÁGINA INTEIRA com URL própria (167-19, D-27), é o único lugar de
// editar: clicar no cartão ou em "Adicionar produto" navega até ela, e salvar
// volta para cá com a busca, a página, o modo e a rolagem de antes.
//
// D-24: a planilha só existe como ARQUIVO: baixar o modelo (.xlsx), preencher
// fora e importar com prévia. Para cadastrar muitos de uma vez, é por aí.
//
// Logística, peso cubado, frete e "Falta" são calculados no SERVIDOR (D-15) e
// aqui só se exibem — a página não tem conta nenhuma. Família é "linha de
// design" (D-07), um cadastro por empresa, não a cor do produto.

const LOTE_SUGESTOES = 10;
const VOLTAS_FRETE = 10;

const dataBr = (iso) => {
    if (! iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');

    return `${d}/${m}/${a}`;
};

const LINK_SECUNDARIO = 'inline-flex items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-[13px] font-medium text-white/80 transition-colors hover:bg-white/[0.07] hover:text-white';

export default function EstruturaProdutos({ empresa, modulos = [], produtos, filtros, vocabulario, ml_conectado = false, frete_tabela, limites, listas: listasIniciais }) {
    const [linhas, setLinhas] = useState(() => produtos.linhas.map((l) => linhaDoServidor(l, vocabulario.pendencias)));
    const [aviso, setAviso] = useState(null);
    const [modo, setModo] = useState(() => lerModo());   // 'grande' | 'lista' — lido já no 1º render, para a rolagem da volta cair no lugar
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [aula, setAula] = useState(false);
    const [sugerindo, setSugerindo] = useState(false);
    const [consultando, setConsultando] = useState(() => new Set());   // ids de variação em consulta de frete
    const [gerindoListas, setGerindoListas] = useState(false);   // janela Famílias e ambientes
    const [importando, setImportando] = useState(false);   // janela de importação da planilha
    const [sugestoes, setSugestoes] = useState(null);      // { itens, indisponivel } enquanto a janela de revisão está aberta
    const [voltouDe, setVoltouDe] = useState(null);        // D-32: produto de onde a pessoa acabou de voltar
    const [destaqueForte, setDestaqueForte] = useState(true);

    // Listas da empresa (família e ambiente): criar um nome na ficha atualiza as duas.
    const [listas, setListas] = useState(listasIniciais ?? { familias: [], ambientes: [] });

    const temProdutos = produtos.tem_produtos || linhas.length > 0;

    const trocarModo = (m) => {
        setModo(m);
        gravarModo(m);
    };

    // ─── Dados vindos do servidor (busca, página, importação, listas) ───────

    const primeira = useRef(true);
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return; }
        setLinhas(produtos.linhas.map((l) => linhaDoServidor(l, vocabulario.pendencias)));
    }, [produtos.linhas]); // eslint-disable-line react-hooks/exhaustive-deps

    // As listas também vêm do servidor de novo (importação que criou família/ambiente, recarga
    // parcial): o estado local acompanha, com o `em_uso` de agora (revisão FE-IN-01).
    const primeiraListas = useRef(true);
    useEffect(() => {
        if (primeiraListas.current) { primeiraListas.current = false; return; }
        setListas(listasIniciais ?? { familias: [], ambientes: [] });
    }, [listasIniciais]);

    const visitar = (params) => {
        router.get(route('portal.auth.estrutura.produtos'), params, {
            preserveState: true, preserveScroll: false, replace: true, only: ['produtos', 'filtros'],
        });
    };

    const buscaInicial = useRef(true);
    useEffect(() => {
        if (buscaInicial.current) { buscaInicial.current = false; return; }
        const t = setTimeout(() => visitar({ q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    // ─── Ficha do produto ───────────────────────────────────────────────────

    /** Navega até a ficha (D-27): guarda onde a lista estava para a volta preservar busca, página e rolagem. */
    const abrirFicha = (produtoId = null) => {
        guardarRetorno();
        router.visit(produtoId
            ? route('portal.auth.estrutura.produtos.ficha', produtoId)
            : route('portal.auth.estrutura.produtos.novo'));
    };

    // Voltando da ficha: o aviso de "Produto salvo.", a rolagem de antes (ou até o produto novo) e,
    // saia como sair da ficha, o cartão daquele produto destacado (D-32) — forte por alguns segundos,
    // depois um contorno leve que fica até sair da página.
    useEffect(() => {
        const volta = pegarVolta();
        if (volta) {
            if (volta.aviso) setAviso(volta.aviso);
            rolarParaVolta(volta);
        }
        const ultimo = pegarUltimoProduto();
        if (! ultimo) return undefined;
        setVoltouDe(ultimo);
        mostrarCartao(ultimo);
        const t = setTimeout(() => setDestaqueForte(false), 2500);

        return () => clearTimeout(t);
    }, []);

    /** As sugestões gravaram: troca no lugar a variação de mesmo id; variação nova entra junto do produto. */
    const mesclar = (data) => {
        const atuais = linhas.filter((r) => r.id);
        let todas = atuais;
        (data.linhas ?? []).forEach((servidor) => {
            const pronta = linhaDoServidor(servidor, vocabulario.pendencias);
            const i = todas.findIndex((r) => r.id === servidor.id);
            if (i >= 0) {
                todas = todas.map((r, j) => (j === i ? pronta : r));

                return;
            }
            let ultimo = -1;
            todas.forEach((r, j) => { if (r.produto_id && r.produto_id === servidor.produto_id) ultimo = j; });
            todas = ultimo >= 0 ? [...todas.slice(0, ultimo + 1), pronta, ...todas.slice(ultimo + 1)] : [...todas, pronta];
        });
        setLinhas(todas);

        if (data.listas) setListas(data.listas);
        setAviso(textoProdutoSalvo(data));
    };

    // Renomear/excluir item de lista muda o que as variações mostram: recarrega do servidor.
    const recarregarProdutos = () => {
        router.reload({ only: ['produtos', 'listas'], preserveScroll: true });
    };

    // ─── Categoria sugerida em lote (D-06): nada é aceito sozinho ───────────

    const haPendenteDeCategoria = linhas.some((r) => r.id && r.categoria_estado !== 'confirmada');

    const sugerirCategorias = async () => {
        if (sugerindo) return;
        const ids = [...new Set(linhas.filter((r) => r.id && r.produto_id && r.categoria_estado !== 'confirmada').map((r) => r.produto_id))];
        if (ids.length === 0) return;
        setSugerindo(true);
        setAviso(null);
        const itens = [];
        let indisponivel = false;
        try {
            for (let i = 0; i < ids.length; i += LOTE_SUGESTOES) {
                const { data } = await axios.post(route('portal.auth.estrutura.produtos.categorias.sugerir'), { produto_ids: ids.slice(i, i + LOTE_SUGESTOES) });
                itens.push(...(data.sugestoes ?? []));
                if (data.indisponivel) indisponivel = true;
            }
            setSugestoes({ itens, indisponivel });
        } catch (e) {
            setAviso('Não deu para buscar sugestões agora. Você pode tentar de novo ou escolher no produto.');
        } finally {
            setSugerindo(false);
        }
    };

    /** Aceitar marcadas: grava pela 1ª variação de cada produto; o servidor confere a folha e devolve o grupo. */
    const aceitarSugestoes = async (marcadas) => {
        const prontas = marcadas.map((m) => {
            const variacao = linhas.find((r) => r.produto_id === m.produto_id && r.primeira) ?? linhas.find((r) => r.produto_id === m.produto_id);
            if (! variacao || ! m.sugestao) return null;

            return { ...variacao, categoria_ml_id: m.sugestao.id, categoria_ml_nome: m.sugestao.nome, categoria_ml_caminho: m.sugestao.caminho_texto,
                categoria: m.sugestao.nome, _categoriaEscolhida: true };
        }).filter(Boolean);
        if (prontas.length === 0) { setSugestoes(null); return; }

        setSugerindo(true);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.linhas'), { linhas: prontas.slice(0, limites.colar).map(linhaParaServidor) });
            setSugestoes(null);
            mesclar(data);
            const falhas = (data.erros ?? []).length;
            setAviso(falhas === 0
                ? 'Categorias salvas.'
                : `Não salvamos a categoria de ${falhas} ${falhas === 1 ? 'produto' : 'produtos'}. Abra o produto e escolha de novo.`);
        } catch (e) {
            setSugestoes(null);
            setAviso('Não foi possível salvar agora. Tente de novo.');
        } finally {
            setSugerindo(false);
        }
    };

    // ─── Frete real pela conta do cliente (D-16): só quando a pessoa pede ────

    const linhasMe2 = linhas.filter((r) => r.id && (r.logistica === 'me2' || r.logistica === 'me2_full'));

    const consultarFretes = async () => {
        if (consultando.size > 0) return;
        const ids = linhas.filter((r) => r.id && (r.logistica === 'me2' || r.logistica === 'me2_full')).map((r) => r.id);
        if (ids.length === 0) return;
        setConsultando(new Set(ids));
        setAviso(null);
        let falhou = false;
        try {
            for (let volta = 0; volta < VOLTAS_FRETE; volta++) {
                const { data } = await axios.post(route('portal.auth.estrutura.produtos.fretes'), { variacao_ids: ids });
                const fretes = data.fretes ?? {};
                setLinhas((atuais) => atuais.map((r) => (fretes[r.id] ? { ...r, frete: fretes[r.id] } : r)));
                if (data.falhou) falhou = true;
                if (! data.pendentes || data.pendentes <= 0) break;
            }
            setAviso(falhou
                ? 'Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa.'
                : 'Fretes atualizados.');
        } catch (e) {
            setAviso('Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa.');
        } finally {
            setConsultando(new Set());
        }
    };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Produtos">
            <div className="mx-auto w-full max-w-[1600px] px-4 pb-10 pt-6 sm:px-6 lg:pl-10 lg:pr-8 lg:pt-11">
                <CabecalhoEstrutura etapa="produtos" amplo onComoFunciona={() => setAula(true)}
                    descricao="Cadastre cada produto uma vez, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs." />

                <div className="mt-8">
                    <BarraAcoesProdutos temProdutos={temProdutos} busca={busca} onBusca={setBusca}
                        onAdicionar={() => abrirFicha(null)} onListas={() => setGerindoListas(true)} onImportar={() => setImportando(true)}
                        onSugerir={sugerirCategorias} sugerindo={sugerindo} podeSugerir={haPendenteDeCategoria} />
                </div>

                {temProdutos && (
                    <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                        <SeletorVisualizacao modo={modo} onModo={trocarModo} />
                        {ml_conectado && linhasMe2.length > 0 && (
                            <Botao variante="fantasma" onClick={consultarFretes} disabled={consultando.size > 0} data-acao="consultar-fretes">
                                {consultando.size > 0 ? <Loader2 size={14} className="animate-spin" /> : null}
                                {consultando.size > 0 ? 'Consultando…' : 'Consultar fretes no Mercado Livre'}
                            </Botao>
                        )}
                    </div>
                )}

                {aviso && (
                    <div role="status" className="mt-4 flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/60" data-aviso-lote>
                        <span>{aviso}</span>
                        <button type="button" onClick={() => setAviso(null)} className="text-white/35 hover:text-white" aria-label="Dispensar aviso"><X size={13} /></button>
                    </div>
                )}

                {! temProdutos && (
                    <section className="mt-6 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-estado-vazio>
                        <h2 className="text-[15px] font-semibold text-white">Cadastre seus produtos uma vez</h2>
                        <p className="mx-auto mt-2 max-w-lg text-[13px] text-white/50">
                            Aqui ficam os produtos que você vende, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs. Cadastre um produto por vez aqui ou importe a planilha-modelo preenchida.
                        </p>
                        <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                            <Botao variante="primario" onClick={() => abrirFicha(null)} data-acao="primeiro-produto">
                                <Plus size={14} /> Cadastrar o primeiro produto
                            </Botao>
                            <a href={route('portal.auth.estrutura.produtos.modelo')} download data-acao="baixar-planilha-modelo" className={LINK_SECUNDARIO}>
                                Baixar planilha-modelo
                            </a>
                            <Botao variante="secundario" onClick={() => setImportando(true)} data-acao="importar-planilha-vazio">Importar planilha</Botao>
                        </div>
                    </section>
                )}

                {temProdutos && filtros.q && produtos.linhas.length === 0 && (
                    <p className="mt-6 py-10 text-center text-[13px] text-white/45">Nenhum produto com essa busca.</p>
                )}

                <div className="mt-5">
                    <ListaProdutos linhas={linhas} vocabulario={vocabulario} consultando={consultando} modo={modo} onAbrir={abrirFicha}
                        voltouDe={voltouDe} destaqueForte={destaqueForte} />
                </div>

                {! ml_conectado && (
                    <p className="mt-4 text-[12px] text-white/45" data-nota-frete>
                        O frete é uma estimativa pela tabela da ECF (vigente desde {dataBr(frete_tabela?.vigente_desde)}, reputação {frete_tabela?.reputacao}). Conectando sua conta do Mercado Livre, mostramos o valor real.
                    </p>
                )}

                {produtos.paginacao.paginas > 1 && (
                    <div className="mt-4"><Paginacao rotulo="produtos" paginacao={{ ...produtos.paginacao, blocos: produtos.paginacao.total, por_pagina: 100 }}
                        onIr={(pagina) => visitar({ q: busca || undefined, pagina })} /></div>
                )}
            </div>

            <JanelaListas aberta={gerindoListas} onFechar={() => setGerindoListas(false)} listas={listas} onListas={setListas} onRecarregar={recarregarProdutos} />
            <JanelaImportacao aberta={importando} onFechar={() => setImportando(false)} limites={limites} />
            <JanelaSugestoesCategoria aberta={!! sugestoes} sugestoes={sugestoes?.itens ?? []} indisponivel={sugestoes?.indisponivel ?? false}
                onAceitar={aceitarSugestoes} onFechar={() => setSugestoes(null)} />
            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} passos={[
                '1. Cadastre o produto e as variações (cor, tamanho…).',
                '2. Informe medidas, peso e custo — o sistema mostra o tipo de envio e o frete.',
                '3. Cada variação já vira uma oferta na Lista SKUs.',
            ]} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
