import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Link2, PencilLine, Plus, Search } from 'lucide-react';
import BarraDaConta from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import ResumoDoSincronizar from '@/Components/Mlb/Publicador/ResumoDoSincronizar';
import { criarAcompanhamento } from '@/Components/Mlb/Publicador/acompanhamentoDoSincronizar.js';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import { haQuanto } from '@/Components/Mlb/Publicador/tempo';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';

const FILTROS = [
    { chave: 'todos', rotulo: 'Todos' },
    { chave: 'rascunho', rotulo: 'Rascunho' },
    { chave: 'conferidos', rotulo: 'Conferidos' },
    { chave: 'publicados', rotulo: 'Publicados' },
    { chave: 'com_problema', rotulo: 'Com problema' },
];

// Quais situações (prontidao().chave) entram em cada chip — espelha as contagens do servidor.
const CHAVES_DO_FILTRO = {
    rascunho: ['rascunho', 'conferir', 'publicando'],
    conferidos: ['pronto'],
    publicados: ['publicado', 'parcial'],
    com_problema: ['erro'],
};

const COLUNAS = ['SKU', 'Produto', 'Origem', 'Situação', 'Anúncios', 'Atualizado'];

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

const TITLE_PORTAL_APAGADO = 'Veio do Portal; a oferta foi apagada lá e o produto ficou aqui.';

// Leitura ÚNICA na montagem (sem sincronizar de volta pra URL ao trocar à
// mão — não muda o comportamento de voltar/avançar do navegador). Link
// ?filtro=X vindo da Visão geral (Fase 173, plano 06) só pré-seleciona
// quando X é uma chave válida de CHAVES_DO_FILTRO; ausente ou inválido cai
// no mesmo 'todos' de sempre.
function filtroInicial() {
    const pedido = new URLSearchParams(window.location.search).get('filtro');
    return (pedido === 'todos' || Object.prototype.hasOwnProperty.call(CHAVES_DO_FILTRO, pedido)) ? pedido : 'todos';
}

// Pílula de origem. D27: decidida por oferta_id (vínculo vivo com o Portal),
// nunca por `origem`, que é só a origem histórica do produto.
function PilulaOrigem({ produto }) {
    const base = 'inline-flex items-center gap-1 rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/70';
    if (produto.oferta_id) {
        return (
            <span className={base} title="Título e preço seguem o Portal">
                <Link2 className="h-3 w-3" aria-hidden="true" />
                Portal
            </span>
        );
    }
    return (
        <span className={base} title={produto.origem === 'portal' ? TITLE_PORTAL_APAGADO : undefined}>
            <PencilLine className="h-3 w-3" aria-hidden="true" />
            Publicador
        </span>
    );
}

function Esqueleto() {
    return (
        <div aria-hidden="true" className="animate-pulse">
            {[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="h-14 border-b border-white/[0.06]" />)}
        </div>
    );
}

/**
 * Tela B do Publicador interno: produtos de uma empresa (conta do ML).
 * Lista produtos vindos do Portal e cadastrados aqui; a linha abre o editor.
 * Enquanto houver produto "publicando", recarrega só `produtos` a cada 5 s.
 */
export default function Produtos({
    empresa,
    liberada = false,
    produtos = [],
    contagens = {},
    rascunhos_antigos = { total: 0, url: null },
    criativos_ia = { url: null },
    abas = { company_id: null },
}) {
    const [filtro, setFiltro] = useState(filtroInicial);
    const [busca, setBusca] = useState('');
    const [modal, setModal] = useState(false);
    const [recarregando, setRecarregando] = useState(false);
    const [status, setStatus] = useState(null); // { tipo: 'ok' | 'erro', texto }
    const [novos, setNovos] = useState(new Set());
    const [erroAbrir, setErroAbrir] = useState(false);
    const [resumo, setResumo] = useState(null); // resumo do preenchimento dos rascunhos (172-12)
    const resumoPronto = useRef(false);
    const [avisosDoClique, setAvisosDoClique] = useState([]); // avisos do próprio Sincronizar (cores avulsas etc.)
    const [absorvidosDoClique, setAbsorvidosDoClique] = useState(0); // linhas antigas de cor juntadas ao grupo
    const aoLerRef = useRef(null);
    const [acompanhando, setAcompanhando] = useState(false);
    // O acompanhamento mora na PÁGINA (review 172 CR-01): o botão do estado vazio desmonta quando a
    // lista recarrega, e com ele morria o polling — o resumo nunca aparecia e a lista não recarregava.
    const contaRef = useRef(empresa.chave);
    contaRef.current = empresa.chave;
    const acompanhamento = useRef(null);
    if (acompanhamento.current === null) {
        acompanhamento.current = criarAcompanhamento({
            ler: async (pedido) => (await axios.get(route('mlb.anuncios.publicador.sincronizar.resumo', { conta: contaRef.current, pedido }))).data,
            aoLer: (r) => aoLerRef.current?.(r),
            aoMudar: setAcompanhando,
            // Parou de acompanhar sem ficar pronto: o painel diz isso em vez de girar para sempre (WR-03).
            aoExpirar: () => setResumo((r) => (r ? { ...r, status: 'expirou' } : r)),
        });
    }
    useEffect(() => () => acompanhamento.current.cancelar(), []);
    const esperaStatus = useRef(null);
    const esperaRealce = useRef(null);

    const temPortal = empresa.portal?.situacao !== 'sem_portal';
    const podeSincronizar = Boolean(empresa.company_id) && temPortal;

    // Polling de 5 s só enquanto houver produto publicando; limpo no unmount.
    const publicando = produtos.some((p) => p.status?.chave === 'publicando');
    useEffect(() => {
        if (!publicando) return undefined;
        const id = setInterval(() => router.reload({ only: ['produtos', 'contagens'] }), 5000);
        return () => clearInterval(id);
    }, [publicando]);

    useEffect(() => () => {
        clearTimeout(esperaStatus.current);
        clearTimeout(esperaRealce.current);
    }, []);

    const visiveis = useMemo(() => {
        const termo = busca.trim().toLowerCase();
        return produtos.filter((p) => {
            if (filtro !== 'todos' && !CHAVES_DO_FILTRO[filtro].includes(p.status?.chave)) return false;
            if (!termo) return true;
            return `${p.sku ?? ''} ${p.nome ?? ''}`.toLowerCase().includes(termo);
        });
    }, [produtos, filtro, busca]);

    const skus = useMemo(() => produtos.map((p) => p.sku).filter(Boolean), [produtos]);

    function abrir(p) {
        router.get(route('mlb.anuncios.publicador.editor', { produto: p.id }), {}, {
            onError: () => setErroAbrir(true),
        });
    }

    // Cada leitura do resumo; ao ficar pronto, recarrega a lista (variantes e status mudaram).
    aoLerRef.current = aoLerResumo;
    function aoLerResumo(r) {
        setResumo(r);
        if (r?.status === 'pronto' && !resumoPronto.current) {
            resumoPronto.current = true;
            router.reload({ only: ['produtos', 'contagens'] });
        }
    }

    // Fechar o painel também para o acompanhamento: senão a próxima leitura o reabria (WR-03).
    function fecharResumo() {
        acompanhamento.current.cancelar();
        setResumo(null);
        setAvisosDoClique([]);
        setAbsorvidosDoClique(0);
    }

    function aoConcluirSync(json) {
        resumoPronto.current = false;
        const avisos = json?.avisos ?? [];
        const absorvidos = Number(json?.absorvidos ?? 0);
        setAvisosDoClique(avisos);
        setAbsorvidosDoClique(absorvidos);
        if (json?.pedido) {
            setResumo({ status: 'preenchendo', total: json.preenchendo ?? 0, concluidos: 0 });
            acompanhamento.current.acompanhar(json.pedido);
        } else {
            acompanhamento.current.cancelar();
            // Sem nada a preencher, os avisos do clique (e as linhas antigas juntadas) ainda precisam aparecer.
            setResumo(avisos.length > 0 || absorvidos > 0 ? { status: 'pronto', so_avisos: true } : null);
        }
        const texto = json?.criados > 0 || absorvidos > 0 ? json.mensagem : 'Nada novo: todos os produtos do Portal já estão aqui.';
        setStatus({ tipo: 'ok', texto });
        setNovos(new Set(json?.ids ?? []));
        setRecarregando(true);
        router.reload({
            only: ['produtos', 'contagens', 'empresa'],
            onFinish: () => setRecarregando(false),
        });
        clearTimeout(esperaStatus.current);
        clearTimeout(esperaRealce.current);
        esperaStatus.current = setTimeout(() => setStatus(null), 6000);
        esperaRealce.current = setTimeout(() => setNovos(new Set()), 2000);
    }

    const vazio = produtos.length === 0;
    const total = (chave) => contagens?.[chave] ?? 0;

    return (
        <AppLayout title={`Publicador — ${empresa.nome}`}>
            <div className="mx-auto max-w-[1240px] px-8 py-8">

                {/* Cabeçalho único da conta (trilha, nome+selos, Trocar empresa) + abas unificadas (172-01/172-03) */}
                <BarraDaConta
                    empresa={empresa}
                    liberada={liberada}
                    acoes={(
                        <>
                            {podeSincronizar && (
                                <BotaoSincronizarPortal
                                    conta={empresa.chave}
                                    onConcluido={aoConcluirSync}
                                    desabilitado={acompanhando}
                                    onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                                />
                            )}
                            <button type="button" onClick={() => setModal(true)} className={BOTAO_SECUNDARIO}>
                                <Plus className="h-4 w-4" aria-hidden="true" />
                                Produto
                            </button>
                        </>
                    )}
                />

                <div className="mb-6">
                    <AbasDaConta aba="produtos" conta={empresa.chave} companyId={abas.company_id} contagemProdutos={contagens.todos ?? null} />
                </div>

                {!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

                <ResumoDoSincronizar resumo={resumo} avisosDoClique={avisosDoClique} absorvidos={absorvidosDoClique} onFechar={fecharResumo} />

                <section className="rounded-xl bg-ecf-card">
                    <div className="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtro por situação">
                            {FILTROS.map((f) => {
                                const ativo = filtro === f.chave;
                                return (
                                    <button
                                        key={f.chave}
                                        type="button"
                                        aria-pressed={ativo}
                                        onClick={() => setFiltro(f.chave)}
                                        className={cn(
                                            'inline-flex h-10 items-center gap-2 rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                            ativo
                                                ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                                : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
                                        )}
                                    >
                                        {f.rotulo}
                                        <span className="font-mono text-[11px] tabular-nums">{total(f.chave)}</span>
                                    </button>
                                );
                            })}
                        </div>
                        <button
                            type="button"
                            disabled={!abas.company_id}
                            title={!abas.company_id ? 'Disponível só para empresas cadastradas no sistema' : undefined}
                            onClick={() => { if (abas.company_id) router.get(route('mlb.anuncios.massa', { company: abas.company_id })); }}
                            className={cn(BOTAO_SECUNDARIO, !abas.company_id && 'opacity-40 cursor-not-allowed')}
                        >
                            Editar em grade
                        </button>
                        <label className="relative block w-[280px]">
                            <span className="sr-only">Buscar por SKU ou nome</span>
                            <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-white/40" aria-hidden="true" />
                            <input
                                type="search"
                                value={busca}
                                onChange={(ev) => setBusca(ev.target.value)}
                                placeholder="Buscar SKU ou nome…"
                                className="h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] pl-10 pr-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                            />
                        </label>
                    </div>

                    <div aria-live="polite" className="px-4">
                        {status && (
                            <p
                                className={cn(
                                    'mb-4 rounded-lg border px-3 py-2 text-[13px] font-normal',
                                    status.tipo === 'ok'
                                        ? 'border-sky-500/25 bg-sky-500/[0.06] text-sky-200'
                                        : 'border-red-500/30 bg-red-500/[0.06] text-red-300',
                                )}
                            >
                                {status.texto}
                            </p>
                        )}
                        {erroAbrir && (
                            <p className="mb-4 rounded-lg border border-red-500/30 bg-red-500/[0.06] px-3 py-2 text-[13px] font-normal text-red-300">
                                Não foi possível abrir o produto.
                            </p>
                        )}
                    </div>

                    {recarregando ? (
                        <Esqueleto />
                    ) : vazio ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">
                                {temPortal ? 'Esta empresa ainda não tem produtos.' : 'Nenhum produto cadastrado.'}
                            </p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                {temPortal
                                    ? 'Traga os produtos que o cliente listou no Portal ou cadastre o primeiro à mão.'
                                    : 'Cadastre o primeiro produto para começar a anunciar.'}
                            </p>
                            <div className="mt-4 flex justify-center gap-2">
                                {podeSincronizar && (
                                    <BotaoSincronizarPortal
                                        conta={empresa.chave}
                                        onConcluido={aoConcluirSync}
                                        desabilitado={acompanhando}
                                        onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                                    />
                                )}
                                <button type="button" onClick={() => setModal(true)} className={BOTAO_SECUNDARIO}>
                                    <Plus className="h-4 w-4" aria-hidden="true" />
                                    Produto
                                </button>
                            </div>
                        </div>
                    ) : visiveis.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">Nenhum produto neste filtro.</p>
                            <button
                                type="button"
                                onClick={() => { setFiltro('todos'); setBusca(''); }}
                                className={cn(BOTAO_SECUNDARIO, 'mt-4')}
                            >
                                Limpar busca
                            </button>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <thead>
                                    <tr className="border-y border-white/[0.06]">
                                        {COLUNAS.map((c) => (
                                            <th key={c} scope="col" className="px-4 py-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                                                {c}
                                            </th>
                                        ))}
                                        <th scope="col" className="px-4 py-2"><span className="sr-only">Ações</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visiveis.map((p) => (
                                        <tr
                                            key={p.id}
                                            tabIndex={0}
                                            onClick={() => abrir(p)}
                                            onKeyDown={(ev) => {
                                                if (ev.key === 'Enter' && ev.target === ev.currentTarget) abrir(p);
                                            }}
                                            className={cn(
                                                'h-14 cursor-pointer border-b border-white/[0.06] transition-colors duration-[2000ms] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow',
                                                novos.has(p.id) && 'bg-sky-500/[0.06]',
                                            )}
                                        >
                                            <td className="px-4 py-2 font-mono text-[13px] font-normal text-white/70">{p.sku}</td>
                                            <td className="max-w-[320px] px-4 py-2">
                                                <p className="truncate text-[13px] font-normal text-white" title={p.nome}>{p.nome}</p>
                                            </td>
                                            <td className="px-4 py-2"><PilulaOrigem produto={p} /></td>
                                            <td className="px-4 py-2"><SeloStatusProduto status={p.status} /></td>
                                            <td className="px-4 py-2 text-[13px] font-normal text-white/70">
                                                {p.parcial ? (
                                                    <span className="tabular-nums">{p.parcial.publicados} de {p.parcial.total}</span>
                                                ) : p.anuncios?.length > 0 ? (
                                                    <span className="flex flex-col gap-1">
                                                        {p.anuncios.map((a) => <LinkMl key={a.ml_item_id} mlb={a.ml_item_id} className="text-[11px]" />)}
                                                    </span>
                                                ) : '—'}
                                            </td>
                                            <td className="px-4 py-2 font-mono text-[11px] tabular-nums text-white/40">
                                                {haQuanto(p.atualizado_em) ?? '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                <div className="flex justify-end">
                                                    <button
                                                        type="button"
                                                        onClick={(ev) => { ev.stopPropagation(); abrir(p); }}
                                                        className={BOTAO_SECUNDARIO}
                                                    >
                                                        {p.rascunho_id ? 'Abrir produto' : 'Começar rascunho'}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {/* Ponte até a Fase 165: os criativos por IA ainda ficam no assistente antigo. */}
                {criativos_ia?.url && (
                    <p className="mt-6 text-[13px] font-normal text-white/40">
                        Criativos por IA ainda ficam no assistente antigo, na etapa Imagem e frete
                        {' · '}
                        <Link href={criativos_ia.url} className="underline decoration-dotted underline-offset-2 hover:text-ecf-yellow">
                            Gerar criativos no assistente antigo
                        </Link>
                    </p>
                )}

                {rascunhos_antigos?.url && (
                    <p className="mt-6 text-[13px] font-normal text-white/40">
                        {rascunhos_antigos.total} {rascunhos_antigos.total === 1 ? 'rascunho do assistente antigo ainda aberto' : 'rascunhos do assistente antigo ainda abertos'}
                        {' · '}
                        <Link href={rascunhos_antigos.url} className="underline decoration-dotted underline-offset-2 hover:text-ecf-yellow">
                            Abrir no assistente antigo
                        </Link>
                    </p>
                )}
            </div>

            <ModalNovoProduto
                aberto={modal}
                onFechar={() => setModal(false)}
                conta={empresa.chave}
                skusExistentes={skus}
            />
        </AppLayout>
    );
}
