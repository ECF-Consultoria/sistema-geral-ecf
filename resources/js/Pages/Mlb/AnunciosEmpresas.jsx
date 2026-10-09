import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Rocket, Search } from 'lucide-react';
import SeloConta from '@/Components/Mlb/Publicador/SeloConta';
import SeloPortal from '@/Components/Mlb/Publicador/SeloPortal';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import SeletorPrograma from '@/Components/Mlb/Publicador/SeletorPrograma';
import IndicadoresDoPrograma from '@/Components/Mlb/Publicador/IndicadoresDoPrograma';
import PainelComoFunciona from '@/Components/Mlb/Publicador/PainelComoFunciona';

const ROTULO_PROGRAMA = { polos: 'Polos', incubadora: 'Incubadora', gestao: 'Gestão' };

const FILTROS = [
    { chave: 'todos', rotulo: 'Todos' },
    { chave: 'prontos', rotulo: 'Prontos para publicar' },
    { chave: 'atencao', rotulo: 'Precisam de atenção' },
    { chave: 'nunca', rotulo: 'Nunca sincronizado' },
];

const COLUNAS = ['Empresa', 'Conta ML', 'Portal', 'Produtos', 'Publicados'];

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

const contagem = (n, singular, plural) => (n > 0 ? `${n} ${n === 1 ? singular : plural}` : '—');

// ─── "Recentes" (Fase 173, plano 03) — até 4 contas abertas pelo usuário,
// em localStorage do navegador, chave `publicador.recentes.{user_id}`.
// Sem tabela nova: é dado do próprio usuário, no próprio navegador
// (T-173-07). TODA leitura/escrita passa por try/catch — localStorage pode
// falhar ou vir vazio (modo privado, quota, primeiro acesso, JSON
// corrompido) e a tela precisa funcionar normalmente sem ele.
const MAX_RECENTES = 4;
const chaveRecentes = (userId) => `publicador.recentes.${userId}`;

function lerRecentes(userId) {
    try {
        const bruto = window.localStorage.getItem(chaveRecentes(userId));
        if (!bruto) return [];
        const lista = JSON.parse(bruto);
        if (!Array.isArray(lista)) return [];
        // Descarta item sem `chave` string válida — forma inesperada (lixo
        // gravado por versão antiga, edição manual do localStorage etc.)
        // nunca derruba a tela nem quebra a navegação do clique.
        return lista.filter((item) => item && typeof item.chave === 'string' && item.chave !== '').slice(0, MAX_RECENTES);
    } catch {
        return [];
    }
}

function gravarRecente(userId, item) {
    try {
        const semDuplicata = lerRecentes(userId).filter((r) => r?.chave !== item.chave);
        const novaLista = [item, ...semDuplicata].slice(0, MAX_RECENTES);
        window.localStorage.setItem(chaveRecentes(userId), JSON.stringify(novaLista));
        return novaLista;
    } catch {
        return null;
    }
}

// Casca de esqueleto: 4 cards + 8 linhas de 56px enquanto a visita carrega.
function Esqueleto() {
    return (
        <div aria-hidden="true" className="animate-pulse">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                {[0, 1, 2, 3].map((i) => <div key={i} className="h-[104px] rounded-xl bg-ecf-card" />)}
            </div>
            <div className="mt-8 rounded-xl bg-ecf-card">
                {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => <div key={i} className="h-14 border-b border-white/[0.06]" />)}
            </div>
        </div>
    );
}

/**
 * Tela A do Publicador interno: entrada de /mlb/anuncios.
 *
 * Programa (Polos · Incubadora · Gestão), busca, filtro e página vivem na URL e
 * trocam por router.get com preserveState. Cada linha é uma conta do ML; clicar
 * (ou Enter) abre a tela B de produtos da empresa. Conta não liberada (D21) é
 * estado calmo: selo neutro, a linha continua abrindo. Sem fundo avermelhado em
 * linha alguma: o estado fica só no selo.
 */
export default function AnunciosEmpresas({
    programa = 'polos',
    programas = {},
    indicadores = {},
    empresas = [],
    paginacao = { pagina: 1, por_pagina: 50, total: 0, de: 0, ate: 0 },
    filtros = { busca: '', filtro: 'todos' },
}) {
    const { auth } = usePage().props;
    const userId = auth?.user?.id ?? null;

    const [busca, setBusca] = useState(filtros.busca ?? '');
    const [carregando, setCarregando] = useState(false);
    const [erroCarga, setErroCarga] = useState(false);
    const [status, setStatus] = useState(null); // { tipo: 'ok' | 'erro', texto }
    const [recentes, setRecentes] = useState(() => lerRecentes(userId));
    const espera = useRef(null);
    const primeira = useRef(true);

    const rotuloPrograma = ROTULO_PROGRAMA[programa] ?? 'Polos';
    const filtroAtual = filtros.filtro ?? 'todos';
    const temBuscaOuFiltro = (filtros.busca ?? '') !== '' || filtroAtual !== 'todos';

    function visitar(parametros) {
        router.get(route('mlb.anuncios.index'), { programa, ...parametros }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => { setCarregando(true); setErroCarga(false); },
            onFinish: () => setCarregando(false),
            onError: () => setErroCarga(true),
        });
    }

    // Busca com espera de 350 ms; nunca dispara na montagem.
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return undefined; }
        if (busca === (filtros.busca ?? '')) return undefined;
        espera.current = setTimeout(() => {
            visitar({ filtro: filtroAtual, busca: busca || undefined });
        }, 350);
        return () => clearTimeout(espera.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca]);

    const trocarPrograma = (p) => {
        setBusca('');
        router.get(route('mlb.anuncios.index'), { programa: p }, {
            preserveState: true,
            onStart: () => { setCarregando(true); setErroCarga(false); },
            onFinish: () => setCarregando(false),
            onError: () => setErroCarga(true),
        });
    };

    const aplicarFiltro = (filtro) => visitar({ filtro, busca: busca || undefined });
    const irParaPagina = (pagina) => visitar({ filtro: filtroAtual, busca: busca || undefined, pagina });
    const limparBusca = () => { setBusca(''); visitar({ filtro: filtroAtual }); };

    // Abrir uma empresa (linha ou botão "Publicar →") leva à Visão geral —
    // a URL de Produtos não muda, continua acessível pela aba Produtos de
    // dentro da conta. Grava a conta em Recentes ANTES de navegar.
    const abrirConta = (e) => {
        const item = { chave: e.chave, nome: e.nome, identificador: e.identificador, programa: e.programa ?? programa };
        const novaLista = gravarRecente(userId, item);
        if (novaLista !== null) setRecentes(novaLista);
        router.get(route('mlb.anuncios.publicador.visao-geral', { conta: e.chave }));
    };

    function aoConcluirSync(e, json) {
        setStatus({ tipo: 'ok', texto: `${e.nome}: ${json?.mensagem ?? 'Nada novo no Portal.'}` });
        router.reload({ only: ['empresas', 'indicadores', 'programas'] });
    }

    const sincronizando = (e) => e.company_id && e.portal?.situacao !== 'sem_portal';
    const vazioDoPrograma = (indicadores.empresas ?? 0) === 0 && !temBuscaOuFiltro;
    const precisaPaginar = paginacao.total > paginacao.por_pagina;
    const ultimaPagina = Math.ceil(paginacao.total / paginacao.por_pagina);

    return (
        <AppLayout title="Publicador Mercado Livre">
            <div className="mx-auto max-w-[1240px] px-8 py-8">

                {/* Cabeçalho */}
                <div className="mb-8 flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12]">
                            <Rocket className="h-[18px] w-[18px] text-ecf-yellow" aria-hidden="true" />
                        </div>
                        <div>
                            <h1 className="font-display text-[24px] font-bold leading-tight text-white">Publicador Mercado Livre</h1>
                            <p className="text-[13px] font-normal text-white/55">
                                Publique no Mercado Livre o que o cliente preparou no Portal.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <label className="relative block w-[280px]">
                            <span className="sr-only">Buscar empresa</span>
                            <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-white/40" aria-hidden="true" />
                            <input
                                type="search"
                                value={busca}
                                onChange={(ev) => setBusca(ev.target.value)}
                                placeholder="Buscar empresa…"
                                className="h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] pl-10 pr-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                            />
                        </label>
                        <SeletorPrograma programa={programa} programas={programas} onTrocar={trocarPrograma} />
                    </div>
                </div>

                {erroCarga ? (
                    <div className="rounded-xl border border-red-500/30 bg-red-500/[0.06] p-6 text-center">
                        <p className="text-[13px] font-normal text-red-300">
                            Não foi possível carregar as empresas. Atualize a página; se continuar, avise o time de desenvolvimento.
                        </p>
                        <button type="button" onClick={() => router.reload()} className={cn(BOTAO_SECUNDARIO, 'mt-4')}>
                            Tentar de novo
                        </button>
                    </div>
                ) : carregando ? (
                    <Esqueleto />
                ) : (
                    <div className="grid gap-8 min-[1600px]:grid-cols-[1fr_320px]">
                        <div className="min-w-0 space-y-8">
                            <IndicadoresDoPrograma indicadores={indicadores} onFiltrarProntos={() => aplicarFiltro('prontos')} />

                            {recentes.length > 0 && (
                                <div className="flex flex-wrap items-center gap-2" aria-label="Empresas recentes">
                                    <span className="text-[13px] font-normal text-white/55">Recentes:</span>
                                    {recentes.map((r) => (
                                        <button
                                            key={r.chave}
                                            type="button"
                                            onClick={() => router.get(route('mlb.anuncios.publicador.visao-geral', { conta: r.chave }))}
                                            className={cn(BOTAO_SECUNDARIO, 'h-9')}
                                        >
                                            <span className="max-w-[160px] truncate">
                                                {typeof r.nome === 'string' && r.nome !== '' ? r.nome : r.chave}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}

                            <section className="rounded-xl bg-ecf-card">
                                <div className="flex flex-wrap items-center justify-between gap-4 p-4">
                                    <h2 className="text-[15px] font-bold text-white">Empresas de {rotuloPrograma}</h2>
                                    <span className="font-mono text-[13px] tabular-nums text-white/55">{empresas.length} exibidas</span>
                                </div>

                                <div className="flex flex-wrap gap-2 px-4 pb-4" role="group" aria-label="Filtro da lista">
                                    {FILTROS.map((f) => {
                                        const ativo = filtroAtual === f.chave;
                                        return (
                                            <button
                                                key={f.chave}
                                                type="button"
                                                aria-pressed={ativo}
                                                onClick={() => aplicarFiltro(f.chave)}
                                                className={cn(
                                                    'inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                                    ativo
                                                        ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                                        : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
                                                )}
                                            >
                                                {f.rotulo}
                                            </button>
                                        );
                                    })}
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
                                </div>

                                {vazioDoPrograma ? (
                                    <div className="px-4 py-12 text-center">
                                        <p className="text-[15px] font-bold text-white">
                                            Nenhuma empresa de {rotuloPrograma} com conta do Mercado Livre.
                                        </p>
                                        <p className="mt-1 text-[13px] font-normal text-white/55">
                                            Quando uma empresa do programa autorizar o Mercado Livre, ela aparece aqui.
                                        </p>
                                    </div>
                                ) : empresas.length === 0 ? (
                                    <div className="px-4 py-12 text-center">
                                        <p className="text-[15px] font-bold text-white">
                                            {(filtros.busca ?? '') !== ''
                                                ? `Nenhuma empresa encontrada para “${filtros.busca}”.`
                                                : 'Nenhuma empresa neste filtro.'}
                                        </p>
                                        <button type="button" onClick={limparBusca} className={cn(BOTAO_SECUNDARIO, 'mt-4')}>
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
                                                {empresas.map((e) => (
                                                    <tr
                                                        key={e.chave}
                                                        tabIndex={0}
                                                        onClick={() => abrirConta(e)}
                                                        onKeyDown={(ev) => {
                                                            if (ev.key === 'Enter' && ev.target === ev.currentTarget) abrirConta(e);
                                                        }}
                                                        className="h-14 cursor-pointer border-b border-white/[0.06] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow"
                                                    >
                                                        <td className="px-4 py-2">
                                                            <p className="text-[13px] font-normal text-white">{e.nome}</p>
                                                            <p className="font-mono text-[11px] text-white/40">{e.identificador}</p>
                                                            {!e.liberada && <AvisoContaTravada variante="selo" className="mt-1" />}
                                                        </td>
                                                        <td className="px-4 py-2"><SeloConta token={e.token} /></td>
                                                        <td className="px-4 py-2"><SeloPortal portal={e.portal} /></td>
                                                        <td className="px-4 py-2 text-[13px] font-normal tabular-nums text-white/70">
                                                            {contagem(e.produtos, 'produto', 'produtos')}
                                                        </td>
                                                        <td className="px-4 py-2 text-[13px] font-normal tabular-nums text-white/70">
                                                            {contagem(e.publicados, 'anúncio', 'anúncios')}
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <div className="flex flex-wrap items-center justify-end gap-2">
                                                                {sincronizando(e) && (
                                                                    <BotaoSincronizarPortal
                                                                        conta={e.chave}
                                                                        onConcluido={(json) => aoConcluirSync(e, json)}
                                                                        onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                                                                    />
                                                                )}
                                                                {e.token !== 'sem_token' ? (
                                                                    <button
                                                                        type="button"
                                                                        onClick={(ev) => { ev.stopPropagation(); abrirConta(e); }}
                                                                        className={BOTAO_SECUNDARIO}
                                                                    >
                                                                        Publicar →
                                                                    </button>
                                                                ) : (
                                                                    <LinkReconexao link={e.link_reconexao} />
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                {paginacao.total > 0 && (
                                    <div className="flex items-center justify-between gap-4 p-4">
                                        <p className="text-[13px] font-normal tabular-nums text-white/55">
                                            Mostrando {paginacao.de}–{paginacao.ate} de {paginacao.total}
                                        </p>
                                        {precisaPaginar && (
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    disabled={paginacao.pagina <= 1}
                                                    onClick={() => irParaPagina(paginacao.pagina - 1)}
                                                    className={cn(BOTAO_SECUNDARIO, 'disabled:cursor-not-allowed disabled:opacity-40')}
                                                >
                                                    <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={paginacao.pagina >= ultimaPagina}
                                                    onClick={() => irParaPagina(paginacao.pagina + 1)}
                                                    className={cn(BOTAO_SECUNDARIO, 'disabled:cursor-not-allowed disabled:opacity-40')}
                                                >
                                                    Próxima <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </section>

                            {/* Abaixo de 1600px (a 1440px a tabela com 2 botões por linha não cabe ao lado do painel) o painel vira card recolhido no fim da página */}
                            <div className="min-[1600px]:hidden">
                                <PainelComoFunciona variante="recolhido" />
                            </div>
                        </div>

                        <div className="hidden min-[1600px]:block">
                            <PainelComoFunciona variante="lateral" />
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
