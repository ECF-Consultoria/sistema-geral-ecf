import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { CheckCircle2, Plus, Search, Trash2, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CabecalhoEstrutura, Paginacao } from '@/Components/Portal/Estrutura/comum';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import JanelaExcluirVariacao from '@/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao';
import { SpreadsheetGrid } from '@/Components/SpreadsheetGrid';
import { campoEditaveis, colunasDaGrade, linhaDaGrade, linhaParaServidor, lerBlocoComCabecalho, mudou } from '@/lib/produtosEstrutura';

// ─── Mapeamento Estrutural — submódulo Produtos ─────────────────────────────
//
// A forma RÁPIDA de cadastrar ~70 produtos (D-12): uma tabela editável, uma
// linha por variação (D-03), com produtos agrupados. A pessoa digita, usa Tab/
// Enter e cola do Excel; cada linha alterada é gravada sozinha, 800 ms depois
// de parar, e só quando tem Ref e Produto. Erro de uma linha aparece embaixo
// dela, depois da tentativa, e não trava as outras.
//
// Logística, peso cubado, frete e "Falta" são calculados no SERVIDOR (D-15) e
// aqui só se exibem — a página não tem conta nenhuma. Família é "linha de
// design" (D-07), um cadastro por empresa, não a cor do produto.
//
// Os editores de Família/Ambiente/Categoria/Volumes entram em planos seguintes
// pela prop `editores` de `colunasDaGrade`; por ora essas células recebem texto
// digitado ou colado e o servidor normaliza.

const ESPERA_GRAVAR_MS = 800;
const ESPERA_REDE_MS = 5000;

let contadorDeChave = 0;
const novaChave = () => `n${++contadorDeChave}`;

const linhaEmBranco = () => ({
    _k: novaChave(), codigo: '', nome: '', eixo_rotulo: '', valor: '', familia: '',
    ambientes_texto: '', categoria: '', volumes_texto: '', custo: '', _primeira: true, _ultima: true,
});

/** Marca, em cada linha, se ela abre ou fecha um produto (variações seguidas). */
function derivar(rows) {
    return rows.map((r, i) => {
        const ant = rows[i - 1];
        const prox = rows[i + 1];
        const mesmoAnt = !! r.produto_id && ant?.produto_id === r.produto_id;
        const mesmoProx = !! r.produto_id && prox?.produto_id === r.produto_id;

        return { ...r, _primeira: ! mesmoAnt, _ultima: ! mesmoProx };
    });
}

const dataBr = (iso) => {
    if (! iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');

    return `${d}/${m}/${a}`;
};

const lista = (itens) => (itens.length > 1 ? `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}` : itens[0]);

export default function EstruturaProdutos({ empresa, modulos = [], produtos, filtros, vocabulario, ml_conectado = false, frete_tabela, limites }) {
    const [rows, setRows] = useState(() => derivar(produtos.linhas.length ? produtos.linhas.map((l) => linhaDaGrade(l, vocabulario.pendencias)) : [linhaEmBranco()]));
    const rowsRef = useRef(rows);
    const [estado, setEstado] = useState('ocioso');       // ocioso | salvando | salvo | rede
    const [erros, setErros] = useState({});               // { _k: motivo }
    const [aviso, setAviso] = useState(null);
    const [selecionar, setSelecionar] = useState(null);
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [aula, setAula] = useState(false);
    const [exclusao, setExclusao] = useState(null);       // { linha, ultima }

    const sujas = useRef(new Map());                      // _k → versão da última edição
    const versao = useRef(0);
    const temporizador = useRef(null);
    const enviando = useRef(false);
    const colado = useRef(0);

    const aplicar = useCallback((proximas) => {
        rowsRef.current = proximas;
        setRows(proximas);
    }, []);

    const colunas = useMemo(
        () => colunasDaGrade({ eixos: vocabulario.eixos, logisticas: vocabulario.logisticas }),
        [vocabulario.eixos, vocabulario.logisticas],
    );

    const temProdutos = produtos.tem_produtos || rows.some((r) => r.id);

    // ─── Gravação por linha ─────────────────────────────────────────────────

    const enviar = useCallback(async () => {
        if (enviando.current) return;
        const prontas = rowsRef.current
            .filter((r) => sujas.current.has(r._k) && String(r.codigo).trim() !== '' && String(r.nome).trim() !== '')
            .slice(0, limites.colar);
        if (prontas.length === 0) return;

        enviando.current = true;
        setEstado('salvando');
        const versoes = new Map(prontas.map((r) => [r._k, sujas.current.get(r._k)]));
        const adicionadas = colado.current;
        colado.current = 0;

        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.linhas'), { linhas: prontas.map(linhaParaServidor) });
            const porChave = new Map();
            const porId = new Map();
            data.linhas.forEach((l) => { if (l.chave) porChave.set(l.chave, l); else porId.set(l.id, l); });
            data.linhas.forEach((l) => { if (l.chave) porId.set(l.id, l); });

            const proximas = rowsRef.current.map((r) => {
                const servidor = porChave.get(r._k) ?? (r.id ? porId.get(r.id) : null);
                if (! servidor) return r;
                const nova = { ...linhaDaGrade(servidor, vocabulario.pendencias), _k: r._k };
                // Editou de novo durante o envio: guarda o que a pessoa digitou, só aprende os ids.
                if (versoes.has(r._k) && sujas.current.get(r._k) !== versoes.get(r._k)) {
                    return { ...r, id: nova.id, produto_id: nova.produto_id, grupo: nova.grupo, _base: nova._base };
                }

                return nova;
            });

            const novosErros = {};
            const comErro = new Set();
            (data.erros ?? []).forEach((e) => {
                if (! e.chave) return;
                comErro.add(e.chave);
                novosErros[e.chave] = `Não salvamos esta linha: ${String(e.mensagem).replace(/\.$/, '')}. Corrija e tente de novo.`;
            });
            versoes.forEach((v, k) => {
                if (sujas.current.get(k) === v) sujas.current.delete(k);
            });
            setErros((atual) => {
                const saida = { ...atual };
                versoes.forEach((_, k) => delete saida[k]);

                return { ...saida, ...novosErros };
            });

            aplicar(derivar(proximas));

            const criadas = data.criadas_nas_listas ?? { familias: [], ambientes: [] };
            const partes = [];
            if (criadas.familias?.length) partes.push(`${criadas.familias.length > 1 ? 'as famílias' : 'a família'} ${lista(criadas.familias)}`);
            if (criadas.ambientes?.length) partes.push(`${criadas.ambientes.length > 1 ? 'os ambientes' : 'o ambiente'} ${lista(criadas.ambientes)}`);
            if (partes.length || adicionadas > 0) {
                setAviso(`${adicionadas > 0 ? `Coladas ${adicionadas} linhas. ` : ''}${partes.length ? `Criamos ${partes.join(' e ')}.` : ''}`.trim());
            }
            setEstado(comErro.size === versoes.size ? 'ocioso' : 'salvo');
        } catch (e) {
            if (e.response && e.response.status < 500) {
                setEstado('ocioso');
                setAviso(e.response.data?.message ?? 'Não foi possível salvar agora. Suas alterações ficam na tela.');
            } else {
                // Sem resposta (rede caiu) ou erro do servidor: o que foi digitado fica na tela e tentamos de novo.
                setEstado('rede');
                clearTimeout(temporizador.current);
                temporizador.current = setTimeout(enviar, ESPERA_REDE_MS);
            }
        } finally {
            enviando.current = false;
            // Sobrou linha pronta que mudou durante o envio (ou além do lote): nova rodada.
            const sobrou = rowsRef.current.some((r) => sujas.current.has(r._k)
                && String(r.codigo).trim() !== '' && String(r.nome).trim() !== ''
                && sujas.current.get(r._k) !== versoes.get(r._k));
            if (sobrou) {
                clearTimeout(temporizador.current);
                temporizador.current = setTimeout(enviar, ESPERA_GRAVAR_MS);
            }
        }
    }, [aplicar, limites.colar, vocabulario.pendencias]);

    const agendar = useCallback(() => {
        clearTimeout(temporizador.current);
        temporizador.current = setTimeout(enviar, ESPERA_GRAVAR_MS);
    }, [enviar]);

    useEffect(() => () => clearTimeout(temporizador.current), []);

    /** A grade avisou que linhas mudaram: marca só as que a pessoa de fato alterou. */
    const aoMudarLinhas = (antes, depois) => {
        const anteriores = new Map(antes.map((r) => [r._k, r]));
        let marcou = false;
        depois.forEach((r) => {
            const a = anteriores.get(r._k);
            const alterou = a ? mudou(a, r) : mudou({}, r);
            if (alterou) {
                sujas.current.set(r._k, ++versao.current);
                marcou = true;
            }
        });
        if (marcou) agendar();
    };

    // ─── Dados vindos do servidor (busca, página) ───────────────────────────

    const primeira = useRef(true);
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return; }
        sujas.current.clear();
        aplicar(derivar(produtos.linhas.length ? produtos.linhas.map((l) => linhaDaGrade(l, vocabulario.pendencias)) : [linhaEmBranco()]));
    }, [produtos.linhas]); // eslint-disable-line react-hooks/exhaustive-deps

    const visitar = (params) => {
        clearTimeout(temporizador.current);
        enviar();
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

    // ─── Ações ──────────────────────────────────────────────────────────────

    const focar = (chave, coluna) => setSelecionar((s) => ({ chave, coluna, n: (s?.n ?? 0) + 1 }));

    const adicionarProduto = () => {
        const nova = linhaEmBranco();
        aplicar(derivar([...rowsRef.current, nova]));
        focar(nova._k, 'codigo');
    };

    /** D-04: a nova variação nasce com tudo da 1ª; só o Valor muda, e recebe o foco. */
    const novaVariacao = (row) => {
        const todas = rowsRef.current;
        const i = todas.findIndex((r) => r._k === row._k);
        let ini = i;
        while (ini > 0 && todas[ini - 1].produto_id === row.produto_id) ini--;
        let fim = i;
        while (fim < todas.length - 1 && todas[fim + 1].produto_id === row.produto_id) fim++;
        const primeiraDoGrupo = todas[ini];
        const quantas = fim - ini + 1;

        const nova = {
            ...primeiraDoGrupo,
            _k: novaChave(),
            id: undefined, oferta: null, frete: null, pendencias: [], falta: '', peso_cubado: null, peso_faturado: null,
            cubado_cobrado: false, logistica: null, oferta_id: undefined,
            codigo: `${primeiraDoGrupo.grupo ?? primeiraDoGrupo.codigo}-${quantas + 1}`,
            valor: '',
        };
        // O retrato diz "isto o servidor já sabe": só Ref e Valor vão no POST, o resto ele copia da 1ª.
        nova._base = { ...campoEditaveis(nova), codigo: '', valor: '' };
        aplicar(derivar([...todas.slice(0, fim + 1), nova, ...todas.slice(fim + 1)]));
        focar(nova._k, 'valor');
    };

    const removerLocal = (chave) => {
        sujas.current.delete(chave);
        setErros((e) => { const s = { ...e }; delete s[chave]; return s; });
        const resto = rowsRef.current.filter((r) => r._k !== chave);
        aplicar(derivar(resto.length ? resto : [linhaEmBranco()]));
    };

    const excluir = (row) => {
        // Linha que ainda não foi gravada some sem pedir confirmação.
        if (! row.id) { removerLocal(row._k); return; }
        const irmas = rowsRef.current.filter((r) => r.produto_id === row.produto_id).length;
        setExclusao({ linha: row, ultima: irmas <= 1 });
    };

    /** Colar com os cabeçalhos do modelo: mapeia por NOME de coluna e vira linhas novas. */
    const aoColarBloco = (matriz, { row }) => {
        const dados = lerBlocoComCabecalho(matriz);
        if (! dados) {
            colado.current = matriz.length > 1 ? matriz.length : 0;

            return false;
        }
        if (dados.length > limites.colar) {
            setAviso(`Cole até ${limites.colar} linhas por vez. Para mais, use Importar planilha.`);

            return true;
        }
        const novas = dados.map((d) => ({ ...linhaEmBranco(), ...d }));
        const todas = rowsRef.current;
        const ativa = todas[row];
        const emBranco = ativa && ! ativa.id && String(ativa.codigo).trim() === '' && String(ativa.nome).trim() === '';
        const proximas = emBranco
            ? [...todas.slice(0, row), ...novas, ...todas.slice(row + 1)]
            : [...todas, ...novas];
        novas.forEach((n) => { sujas.current.set(n._k, ++versao.current); });
        colado.current = novas.length;
        aplicar(derivar(proximas));
        agendar();

        return true;
    };

    const estadoTexto = { salvando: 'Salvando…', salvo: 'Salvo', rede: 'Não foi possível salvar agora. Suas alterações ficam na tela; vamos tentar de novo.' }[estado];

    const acoes = (
        <Botao variante={temProdutos ? 'primario' : 'secundario'} onClick={adicionarProduto} data-acao="adicionar-produto">
            <Plus size={14} /> Adicionar produto
        </Botao>
    );

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Produtos">
            <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="produtos" onComoFunciona={() => setAula(true)} acoes={acoes}
                    descricao="Cadastre cada produto uma vez, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs." />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[200px] flex-1">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar código ou nome…"
                            className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] py-2 pl-8 pr-8 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                            data-busca />
                        {busca && (
                            <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                <X size={14} />
                            </button>
                        )}
                    </div>
                    <p role="status" className="flex items-center gap-1 text-[12px] text-white/45" data-estado-gravacao={estado}>
                        {estado === 'salvo' && <CheckCircle2 size={12} />}
                        {estadoTexto}
                    </p>
                </div>

                {aviso && (
                    <div className="flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/60" data-aviso-lote>
                        <span>{aviso}</span>
                        <button type="button" onClick={() => setAviso(null)} className="text-white/35 hover:text-white" aria-label="Dispensar aviso"><X size={13} /></button>
                    </div>
                )}

                {! temProdutos && (
                    <section className="rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-estado-vazio>
                        <h2 className="text-[15px] font-semibold text-white">Cadastre seus produtos uma vez</h2>
                        <p className="mx-auto mt-2 max-w-lg text-[13px] text-white/50">
                            Aqui ficam os produtos que você vende, com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs. Digite na tabela abaixo ou cole as linhas do Excel.
                        </p>
                        <div className="mt-4 flex justify-center">
                            <Botao variante="primario" onClick={() => focar(rows[0]._k, 'codigo')} data-acao="primeiro-produto">
                                <Plus size={14} /> Cadastrar o primeiro produto
                            </Botao>
                        </div>
                    </section>
                )}

                {temProdutos && filtros.q && produtos.linhas.length === 0 && (
                    <p className="py-10 text-center text-[13px] text-white/45">Nenhum produto com essa busca.</p>
                )}

                <div className="overflow-x-auto rounded-2xl border border-white/[0.08] bg-ecf-card" data-tabela-produtos>
                    <SpreadsheetGrid
                        columns={colunas}
                        rows={rows}
                        onChange={(proximas) => aplicar(derivar(proximas))}
                        onRowsCommit={aoMudarLinhas}
                        variant="portal"
                        ariaLabel="Produtos"
                        rowKey="_k"
                        makeRow={linhaEmBranco}
                        growOnPaste
                        tabWrap
                        minRows={1}
                        showImportExport={false}
                        maxPasteRows={limites.colar}
                        onPasteLimit={() => setAviso(`Cole até ${limites.colar} linhas por vez. Para mais, use Importar planilha.`)}
                        onPasteBlock={aoColarBloco}
                        selecionar={selecionar}
                        rowClassName={(row) => (row._primeira ? 'border-t border-white/[0.08]' : '')}
                        rowNote={(row) => (erros[row._k]
                            ? <p role="alert" className="px-3 py-1 text-[12px] text-red-300" data-erro-linha>{erros[row._k]}</p>
                            : null)}
                        rowActions={(row) => (
                            <span className="flex items-center justify-center gap-1">
                                {row._ultima && row.id && (
                                    <button type="button" onClick={() => novaVariacao(row)} aria-label={`Nova variação de ${row.codigo}`} title="Nova variação"
                                        data-acao="nova-variacao" className="rounded-lg p-1.5 text-white/35 hover:bg-white/[0.06] hover:text-white">
                                        <Plus size={14} />
                                    </button>
                                )}
                                <button type="button" onClick={() => excluir(row)} aria-label={`Excluir ${row.codigo || 'linha'}`} title="Excluir"
                                    data-acao="excluir-variacao" className="rounded-lg p-1.5 text-white/35 hover:bg-red-500/10 hover:text-red-300">
                                    <Trash2 size={14} />
                                </button>
                            </span>
                        )}
                    />
                </div>

                {! ml_conectado && (
                    <p className="text-[12px] text-white/45" data-nota-frete>
                        O frete é uma estimativa pela tabela da ECF (vigente desde {dataBr(frete_tabela?.vigente_desde)}, reputação {frete_tabela?.reputacao}). Conectando sua conta do Mercado Livre, mostramos o valor real.
                    </p>
                )}

                {produtos.paginacao.paginas > 1 && (
                    <Paginacao rotulo="produtos" paginacao={{ ...produtos.paginacao, blocos: produtos.paginacao.total, por_pagina: 100 }}
                        onIr={(pagina) => visitar({ q: busca || undefined, pagina })} />
                )}
            </div>

            <JanelaExcluirVariacao aberta={!! exclusao} linha={exclusao?.linha} ultima={exclusao?.ultima}
                onFechar={() => setExclusao(null)}
                onExcluida={(resposta) => {
                    removerLocal(exclusao.linha._k);
                    setExclusao(null);
                    if (resposta?.mensagem) setAviso(resposta.mensagem);
                }} />
            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} passos={[
                '1. Cadastre o produto e as variações (cor, tamanho…).',
                '2. Informe medidas, peso e custo — o sistema mostra o tipo de envio e o frete.',
                '3. Cada variação já vira uma oferta na Lista SKUs.',
            ]} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
