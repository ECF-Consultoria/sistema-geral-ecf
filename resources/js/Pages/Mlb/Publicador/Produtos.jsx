import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link2, PencilLine, Plus, Search } from 'lucide-react';
import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';
import SeloConta from '@/Components/Mlb/Publicador/SeloConta';
import SeloPortal from '@/Components/Mlb/Publicador/SeloPortal';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import { haQuanto } from '@/Components/Mlb/Publicador/tempo';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';

const ROTULO_PROGRAMA = { polos: 'Polos', incubadora: 'Incubadora', gestao: 'Gestão' };

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
    const [filtro, setFiltro] = useState('todos');
    const [busca, setBusca] = useState('');
    const [modal, setModal] = useState(false);
    const [recarregando, setRecarregando] = useState(false);
    const [status, setStatus] = useState(null); // { tipo: 'ok' | 'erro', texto }
    const [novos, setNovos] = useState(new Set());
    const [erroAbrir, setErroAbrir] = useState(false);
    const esperaStatus = useRef(null);
    const esperaRealce = useRef(null);

    const rotuloPrograma = ROTULO_PROGRAMA[empresa.programa] ?? empresa.programa_rotulo ?? 'Polos';
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

    function aoConcluirSync(json) {
        const texto = json?.criados > 0 ? json.mensagem : 'Nada novo: todos os produtos do Portal já estão aqui.';
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

                {/* Cabeçalho */}
                <nav aria-label="Trilha" className="mb-2 text-[13px] font-normal text-white/55">
                    <Link href={route('mlb.anuncios.index')} className="hover:text-ecf-yellow">Anunciar</Link>
                    <span aria-hidden="true"> › </span>
                    <Link href={route('mlb.anuncios.index', { programa: empresa.programa })} className="hover:text-ecf-yellow">{rotuloPrograma}</Link>
                    <span aria-hidden="true"> › </span>
                    <span className="text-white/70">{empresa.nome}</span>
                </nav>

                <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="font-display text-[24px] font-bold leading-tight text-white">{empresa.nome}</h1>
                        <SeloConta token={empresa.token} />
                        <SeloPortal portal={empresa.portal} />
                        {!liberada && <AvisoContaTravada variante="selo" />}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {podeSincronizar && (
                            <BotaoSincronizarPortal
                                conta={empresa.chave}
                                onConcluido={aoConcluirSync}
                                onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                            />
                        )}
                        <button type="button" onClick={() => setModal(true)} className={BOTAO_SECUNDARIO}>
                            <Plus className="h-4 w-4" aria-hidden="true" />
                            Produto
                        </button>
                    </div>
                </div>

                <div className="mb-6">
                    <ModoAnuncioTabs empresaId={abas.company_id} modo="individual" contaPublicador={empresa.chave} />
                </div>

                {!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

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
