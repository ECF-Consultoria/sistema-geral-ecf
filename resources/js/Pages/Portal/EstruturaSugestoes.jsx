import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, Paginacao } from '@/Components/Portal/Estrutura/comum';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import Janela from '@/Components/Portal/Estrutura/Janela';
import CartaoSugestao from '@/Components/Portal/Estrutura/Sugestoes/CartaoSugestao';
import CabecalhoFamilia from '@/Components/Portal/Estrutura/Sugestoes/CabecalhoFamilia';
import FiltrosSugestoes from '@/Components/Portal/Estrutura/Sugestoes/FiltrosSugestoes';
import BarraDeMarcadas from '@/Components/Portal/Estrutura/Sugestoes/BarraDeMarcadas';
import ExplicacaoDasOfertas from '@/Components/Portal/Estrutura/Sugestoes/ExplicacaoDasOfertas';
import AvisoSugestoes from '@/Components/Portal/Estrutura/Sugestoes/AvisoSugestoes';
import {
    aceitarMarcadas, alternarMarca, desfazerEdicao, descartarChaves, desmarcarVarias, editarCampo,
    estadoInicial, limparMarcacao, marcarVarias, podeAceitar,
} from '@/lib/sugestoesSelecao';
import {
    MSG_FALHA_REDE, msgLimiteDoLote, msgMarcamosPrimeiras, textoDescarte, textoRestauracao, textoResultadoAceite,
} from '@/lib/sugestoesEstrutura';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — Sugestões de ofertas (Fase 168-14) ─────────────
//
// D-01: a pessoa aceita uma ou várias e descarta; nada é criado sozinho.
// D-08: cada cartão mostra composição, o porquê, logística e frete estimado.
// D-19: nome e código editáveis só no navegador até Aceitar (limites 60/120 do servidor).
// D-20: nenhum submódulo novo; a tela entra por Produtos e pela Lista SKUs.
//
// SEM PLANILHA NA TELA (D-23 da 167): cartões, listas e janelas. Filtros, abas e páginas
// vão ao servidor (learnings §25/§27). A marcação e as edições ficam em `estado`, que
// sobrevive a trocar de filtro, aba e página (preserveState). Toda a lógica (marcar, editar,
// montar o pedido de aceite, aplicar o resultado) está em `sugestoesSelecao.js`: aqui só se
// liga a lib à tela. As abas "Sem tipo" e "Descartadas" têm o corpo no 168-15.

const ABAS = [
    ['sugestoes', 'Sugestões'],
    ['sem_tipo', 'Sem tipo'],
    ['descartadas', 'Descartadas'],
];

const chaveDaFamilia = (item) => String(item.familia?.id ?? 'sem');

/** Agrupa os itens da página por família, na ordem em que o servidor mandou (a página não "anda"). */
function agruparPorFamilia(itens) {
    const grupos = [];
    for (const item of itens) {
        const chave = chaveDaFamilia(item);
        const ultimo = grupos[grupos.length - 1];
        if (ultimo && ultimo.chave === chave) ultimo.itens.push(item);
        else grupos.push({ chave, nome: item.familia?.nome ?? null, itens: [item] });
    }

    return grupos;
}

export default function EstruturaSugestoes({ empresa, modulos = [], sugestoes, filtros, ml_conectado = false, vocabulario }) {
    const limites = sugestoes.limites;
    const [estado, setEstado] = useState(estadoInicial);
    const [errosPorChave, setErrosPorChave] = useState({});
    const [aceitando, setAceitando] = useState(() => new Set());   // chaves em aceite individual
    const [emLote, setEmLote] = useState(false);                   // aceite/descarte em lote em andamento
    const [aviso, setAviso] = useState(null);                      // { texto, erro?, acao? }
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [visitando, setVisitando] = useState(false);
    const [aula, setAula] = useState(false);
    const [confirmaDescarte, setConfirmaDescarte] = useState(false);

    const ehAbaSugestoes = sugestoes.aba === 'sugestoes';
    const itens = sugestoes.itens ?? [];
    const grupos = useMemo(() => agruparPorFamilia(itens), [itens]);
    const marcadas = estado.marcadas;
    const barraVisivel = marcadas.length > 0;

    // ─── Navegação dentro da tela (servidor) ────────────────────────────────

    // Os filtros "de agora": atualizados na hora do clique, para duas mudanças seguidas
    // (limpar filtros e a busca esvaziando) não pisarem uma na outra com as props antigas.
    const filtrosRef = useRef({ fase: filtros.fase ?? undefined, familia: filtros.familia ?? undefined, tipo: filtros.tipo ?? undefined, q: filtros.q || undefined });
    useEffect(() => {
        filtrosRef.current = { fase: filtros.fase ?? undefined, familia: filtros.familia ?? undefined, tipo: filtros.tipo ?? undefined, q: filtros.q || undefined };
    }, [filtros.fase, filtros.familia, filtros.tipo, filtros.q]);

    const visitar = (mudancas = {}) => {
        const proximo = { ...filtrosRef.current, ...mudancas };
        filtrosRef.current = { fase: proximo.fase, familia: proximo.familia, tipo: proximo.tipo, q: proximo.q };
        const params = { ...(sugestoes.aba !== 'sugestoes' ? { aba: sugestoes.aba } : {}) };
        for (const [k, v] of Object.entries({ ...proximo, pagina: mudancas.pagina })) {
            if (v !== undefined && v !== null && v !== '') params[k] = v;
        }
        router.get(route('portal.auth.estrutura.sugestoes'), params, {
            preserveState: true, preserveScroll: true, replace: true, only: ['sugestoes', 'filtros'],
            onStart: () => setVisitando(true), onFinish: () => setVisitando(false),
        });
    };

    const primeiraBusca = useRef(true);
    useEffect(() => {
        if (primeiraBusca.current) { primeiraBusca.current = false; return undefined; }
        if ((busca || '') === (filtrosRef.current.q ?? '')) return undefined;
        const t = setTimeout(() => visitar({ q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    const limparFiltros = () => {
        setBusca('');
        visitar({ fase: undefined, familia: undefined, tipo: undefined, q: undefined });
    };

    const hrefAba = (aba) => route('portal.auth.estrutura.sugestoes', aba === 'sugestoes' ? {} : { aba });

    // ─── Marcação e edição (libs do 168-05) ─────────────────────────────────

    const marcar = (chave) => {
        const r = alternarMarca(estado, chave, limites.lote);
        setEstado(r.estado);
        if (r.recusou) setAviso({ texto: msgLimiteDoLote(limites.lote) });
    };

    /** Marca (ou desmarca, se já estão todas) as que podem ser aceitas dentre `chaves`. */
    const alternarVarias = (chaves) => {
        const aceitaveis = itens.filter((i) => chaves.includes(i.chave) && podeAceitar(i, estado, limites)).map((i) => i.chave);
        if (aceitaveis.length === 0) return;
        if (aceitaveis.every((c) => marcadas.includes(c))) {
            setEstado(desmarcarVarias(estado, aceitaveis));

            return;
        }
        const r = marcarVarias(estado, aceitaveis, limites.lote);
        setEstado(r.estado);
        if (r.recusadas.length > 0) setAviso({ texto: msgMarcamosPrimeiras(limites.lote) });
    };

    const marcarDoFiltro = () => {
        const r = marcarVarias(estado, sugestoes.chaves_filtradas ?? [], limites.lote);
        setEstado(r.estado);
        if (r.recusadas.length > 0 || (sugestoes.chaves_filtradas ?? []).length >= limites.lote) setAviso({ texto: msgMarcamosPrimeiras(limites.lote) });
    };

    const editar = (chave, campo, valor, sugerido) => setEstado((e) => editarCampo(e, chave, campo, valor, sugerido));
    const desfazer = (chave) => setEstado((e) => desfazerEdicao(e, chave));

    // ─── Escritas: aceitar e descartar ──────────────────────────────────────

    const recarregar = () => router.reload({ only: ['sugestoes'], preserveScroll: true });

    const enviarAceite = async (pedidos) => (await axios.post(route('portal.auth.estrutura.sugestoes.aceitar'), { sugestoes: pedidos })).data;
    const enviarDescarte = async (chaves) => (await axios.post(route('portal.auth.estrutura.sugestoes.descartar'), { chaves })).data;

    /** Aceita as chaves pela lib e mostra o resultado único; as com erro ficam e aparecem no cartão. */
    const aceitar = async (chaves) => {
        setAviso(null);
        const r = await aceitarMarcadas(estado, chaves, { enviar: enviarAceite, limite: limites.lote });
        if (r.falhaDeRede || (! r.resultado && Object.keys(r.errosPorChave).length === 0)) {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });

            return;
        }
        setEstado(r.estado);
        setErrosPorChave((antes) => {
            const proximo = { ...antes };
            for (const c of chaves) delete proximo[c];

            return { ...proximo, ...r.errosPorChave };
        });
        if (r.resultado) {
            const criadas = r.resultado.criadas.length;
            setAviso({
                texto: textoResultadoAceite(r.resultado),
                acao: criadas > 0 ? { rotulo: 'Ver na Lista SKUs', href: route('portal.auth.estrutura.lista') } : null,
            });
            recarregar();
        }
    };

    const aceitarUma = async (chave) => {
        setAceitando((s) => new Set(s).add(chave));
        try {
            await aceitar([chave]);
        } finally {
            setAceitando((s) => { const n = new Set(s); n.delete(chave); return n; });
        }
    };

    const aceitarMarcadasEmLote = async () => {
        setEmLote(true);
        try {
            await aceitar(marcadas);
        } finally {
            setEmLote(false);
        }
    };

    /** "Desfazer" do descarte de uma: devolve a sugestão à lista. */
    const desfazerDescarte = async (chave) => {
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.restaurar'), { chaves: [chave] });
            setAviso({ texto: textoRestauracao(data?.restauradas ?? 0, data?.ja_existem ?? 0) });
            recarregar();
        } catch {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });
        }
    };

    const descartar = async (chaves) => {
        setAviso(null);
        const r = await descartarChaves(estado, chaves, { enviar: enviarDescarte });
        if (! r.resultado) {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });

            return;
        }
        setEstado(r.estado);
        setAviso({
            texto: textoDescarte(chaves.length),
            acao: chaves.length === 1 ? { rotulo: 'Desfazer', onClick: () => desfazerDescarte(chaves[0]) } : null,
        });
        recarregar();
    };

    const descartarUma = async (chave) => {
        setAceitando((s) => new Set(s).add(chave));
        try {
            await descartar([chave]);
        } finally {
            setAceitando((s) => { const n = new Set(s); n.delete(chave); return n; });
        }
    };

    const descartarMarcadas = async () => {
        if (marcadas.length > 1) { setConfirmaDescarte(true); return; }
        setEmLote(true);
        try {
            await descartar(marcadas);
        } finally {
            setEmLote(false);
        }
    };

    const confirmarDescarte = async () => {
        setConfirmaDescarte(false);
        setEmLote(true);
        try {
            await descartar(marcadas);
        } finally {
            setEmLote(false);
        }
    };

    // ─── Tela ───────────────────────────────────────────────────────────────

    const naPagina = itens.filter((i) => podeAceitar(i, estado, limites));
    const todasDaPaginaMarcadas = naPagina.length > 0 && naPagina.every((i) => marcadas.includes(i.chave));
    const filtroAtivo = Boolean(filtros.fase || filtros.familia || filtros.tipo || filtros.q);

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Sugestões de ofertas">
            <div className={cn('mx-auto w-full max-w-[1600px] px-4 pt-6 sm:px-6 lg:pl-10 lg:pr-8 lg:pt-11', barraVisivel ? 'pb-28' : 'pb-10')} data-sugestoes-pagina>
                <header className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-[13px] font-medium tracking-[0.2em] text-white/55">Mapeamento Estrutural</p>
                        <nav aria-label="Caminho" className="mt-3 flex items-center gap-2 text-[14px] text-white/70">
                            <ArrowLeft size={18} aria-hidden="true" />
                            <Link href={route('portal.auth.estrutura.produtos')} className="hover:text-white">Produtos</Link>
                            <ChevronRight size={14} aria-hidden="true" className="text-white/40" />
                            <span className="truncate text-white">Sugestões de ofertas</span>
                        </nav>
                        <h1 className="mt-3 font-display text-[24px] font-bold leading-tight text-white">Sugestões de ofertas</h1>
                        <p className="mt-1 max-w-[900px] text-[15px] leading-relaxed text-white/70">
                            Combinamos os seus produtos em Combo, Kit e Combit. Você escolhe o que vira oferta. Nada é criado sozinho.
                        </p>
                    </div>
                    <Botao variante="fantasma" onClick={() => setAula(true)} data-acao="como-funciona" className="h-11 shrink-0">Como funciona</Botao>
                </header>

                <ExplicacaoDasOfertas />

                <nav aria-label="Seções" className="mt-5 grid grid-cols-3 gap-1 sm:flex">
                    {ABAS.map(([aba, rotulo]) => {
                        const ativa = sugestoes.aba === aba;

                        return (
                            <Link key={aba} href={hrefAba(aba)} preserveState preserveScroll replace aria-current={ativa ? 'page' : undefined}
                                className={cn('inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-[12px] font-semibold transition-colors sm:text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
                                    ativa ? 'bg-white/[0.08] text-white' : 'text-white/60 hover:bg-white/[0.04] hover:text-white')}>
                                {rotulo} ({sugestoes.contagens?.[aba] ?? 0})
                            </Link>
                        );
                    })}
                </nav>

                {ehAbaSugestoes && sugestoes.tem_produtos && (
                    <>
                        {sugestoes.excedeu_teto && (
                            <p className="mt-4 rounded-xl border border-amber-400/20 bg-amber-500/[0.06] px-3 py-2 text-[12px] text-amber-200/90" data-faixa-teto>
                                Há muitas sugestões. Mostramos as primeiras {sugestoes.teto}. Escolha uma família para ver o restante.
                            </p>
                        )}

                        <FiltrosSugestoes sugestoes={sugestoes} filtros={filtros} busca={busca} onBusca={setBusca}
                            onFiltro={(m) => visitar(m)} onLimpar={limparFiltros}
                            naPagina={naPagina.length} todasMarcadas={todasDaPaginaMarcadas}
                            onMarcarPagina={() => alternarVarias(itens.map((i) => i.chave))}
                            limiteDoLote={limites.lote} onMarcarFiltro={marcarDoFiltro}
                            mlConectado={ml_conectado} temMe2={false} consultando={false} onConsultar={() => {}} />

                        <div className={cn('mt-4', visitando && 'opacity-60')} aria-busy={visitando} data-lista-sugestoes>
                            {grupos.map((g, indice) => (
                                <section key={`${g.chave}-${indice}`} aria-label={g.chave === 'sem' ? 'Sem família' : g.nome} data-grupo-familia={g.chave}>
                                    <CabecalhoFamilia nome={g.nome} semFamilia={g.chave === 'sem'} primeiro={indice === 0}
                                        naPagina={g.itens.length} total={sugestoes.familia_totais?.[g.chave] ?? g.itens.length}
                                        continua={indice === 0 && sugestoes.familia_continua !== null && sugestoes.familia_continua !== undefined && String(sugestoes.familia_continua) === g.chave}
                                        todasMarcadas={g.itens.filter((i) => podeAceitar(i, estado, limites)).every((i) => marcadas.includes(i.chave)) && g.itens.some((i) => podeAceitar(i, estado, limites))}
                                        onMarcarTodas={() => alternarVarias(g.itens.map((i) => i.chave))} />
                                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                                        {g.itens.map((item) => (
                                            <CartaoSugestao key={item.chave} sugestao={item} estado={estado} limites={limites} vocabulario={vocabulario}
                                                marcada={marcadas.includes(item.chave)} aceitando={aceitando.has(item.chave)} bloqueado={emLote}
                                                erro={errosPorChave[item.chave] ?? null}
                                                onMarcar={marcar} onEditar={editar} onDesfazer={desfazer}
                                                onAceitar={aceitarUma} onDescartar={descartarUma} />
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>

                        {filtroAtivo && itens.length === 0 && (
                            <p className="mt-6 py-10 text-center text-[13px] text-white/60">Nada com esses filtros.</p>
                        )}

                        {sugestoes.paginacao.paginas > 1 && (
                            <div className="mt-6">
                                <Paginacao rotulo="sugestões" paginacao={sugestoes.paginacao} onIr={(pagina) => visitar({ pagina })} />
                            </div>
                        )}
                    </>
                )}
            </div>

            {ehAbaSugestoes && (
                <BarraDeMarcadas total={marcadas.length} ocupada={emLote} onLimpar={() => setEstado(limparMarcacao(estado))}
                    onAceitar={aceitarMarcadasEmLote} onDescartar={descartarMarcadas} />
            )}

            <Janela aberta={confirmaDescarte} onFechar={() => setConfirmaDescarte(false)} titulo={`Descartar ${marcadas.length} sugestões?`}
                descricao="Elas saem da lista e não voltam sozinhas. Você pode restaurá-las na aba Descartadas.">
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Botao variante="secundario" onClick={() => setConfirmaDescarte(false)} className="h-11">Cancelar</Botao>
                    <Botao variante="primario" onClick={confirmarDescarte} className="h-11" data-acao="confirmar-descarte">Descartar {marcadas.length}</Botao>
                </div>
            </Janela>

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} passos={[
                'Combo: o mesmo produto em mais unidades — Kit 4 cadeiras.',
                'Kit: produtos diferentes juntos — mesa + banco.',
                'Combit: um kit com mais unidades de um item — mesa + 4 cadeiras.',
                'Confira o nome e o código de cada sugestão, aceite o que fizer sentido e descarte o resto. Nada é criado sozinho.',
            ]} />
            <AvisoSugestoes aviso={aviso} onFechar={() => setAviso(null)} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
