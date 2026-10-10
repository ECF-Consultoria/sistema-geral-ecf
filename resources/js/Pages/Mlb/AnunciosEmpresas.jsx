import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowUpDown, BookOpenCheck, ChevronLeft, ChevronRight, History, PlugZap, Plus, Rocket, Search, Boxes, Zap } from 'lucide-react';
import SeloConta from '@/Components/Mlb/Publicador/SeloConta';
import SeloPortal from '@/Components/Mlb/Publicador/SeloPortal';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import SeletorPrograma from '@/Components/Mlb/Publicador/SeletorPrograma';
import IndicadoresDoPrograma from '@/Components/Mlb/Publicador/IndicadoresDoPrograma';
import PainelComoFunciona from '@/Components/Mlb/Publicador/PainelComoFunciona';
import CartaoAcessoRapido, { iniciaisDe, numeroSeguro, textoSeguro } from '@/Components/Mlb/Publicador/CartaoAcessoRapido';

const ROTULO_PROGRAMA = { polos: 'Polos', incubadora: 'Incubadora', gestao: 'Gestão' };

const FILTROS = [
    { chave: 'todos', rotulo: 'Todos' },
    { chave: 'prontos', rotulo: 'Prontos para publicar' },
    { chave: 'atencao', rotulo: 'Precisam de atenção' },
    { chave: 'nunca', rotulo: 'Nunca sincronizado' },
];

// 7 colunas do redesign (quick 261009-t01). ⚠️ Portal FICA: o mockup do Stitch
// a trocou por "ERP & SYNC", mas Portal é dado REAL (situação, ofertas novas,
// quando sincronizou) e o ERP é justamente o que não tem integração. As duas
// convivem — a decisão 1 do plano.
const COLUNAS = ['Empresa', 'Conta ML', 'Portal', 'ERP', 'Catálogo', 'Anúncios', 'Fases / pendências'];

// Ordenação do CLIENTE: reordena as linhas EXIBIDAS, sem ida ao servidor e sem
// mexer no filtro. O dropdown "ERP" do mockup não entra: não há dado para filtrar.
const ORDENS = [
    { chave: 'nome', rotulo: 'Nome (A–Z)' },
    { chave: 'produtos', rotulo: 'Mais produtos' },
    { chave: 'anuncios', rotulo: 'Mais anúncios' },
    { chave: 'prontos', rotulo: 'Prontos primeiro' },
];

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

const contagem = (n, singular, plural) => (n > 0 ? `${n} ${n === 1 ? singular : plural}` : '—');

/** `empresas` pode chegar `null` do servidor — o default de parâmetro só cobre `undefined`. */
const comoLista = (valor) => (Array.isArray(valor) ? valor : []);

/** `paginacao`/`filtros`/`indicadores` idem: objeto de verdade, ou objeto vazio. */
const comoObjeto = (valor) => ((valor !== null && typeof valor === 'object' && ! Array.isArray(valor)) ? valor : {});

/**
 * Ordena uma CÓPIA das linhas exibidas. Nunca muta a prop do Inertia (mutar
 * `empresas` faria a lista mudar de ordem sozinha na próxima renderização).
 */
function ordenar(linhas, ordem) {
    const copia = [...linhas];
    if (ordem === 'produtos') return copia.sort((a, b) => (numeroSeguro(b?.produtos) ?? 0) - (numeroSeguro(a?.produtos) ?? 0));
    if (ordem === 'anuncios') return copia.sort((a, b) => (numeroSeguro(b?.publicados) ?? 0) - (numeroSeguro(a?.publicados) ?? 0));
    if (ordem === 'prontos') return copia.sort((a, b) => (numeroSeguro(b?.prontos) ?? 0) - (numeroSeguro(a?.prontos) ?? 0));

    return copia.sort((a, b) => textoSeguro(a?.nome, '').localeCompare(textoSeguro(b?.nome, ''), 'pt-BR'));
}

/** Card do rodapé: título, número (quando há) e uma linha de explicação. */
function CartaoDeRodape({ icone: Icone, titulo, destaque, texto, onClick, rotuloBotao }) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/[0.08] bg-white/[0.03]">
                <Icone className="h-4 w-4 text-white/55" />
            </span>
            <div className="min-w-0">
                <p className="text-[13px] font-bold text-white">{titulo}</p>
                {destaque ? <p className="mt-1 text-[13px] font-normal tabular-nums text-white/70">{destaque}</p> : null}
                <p className="mt-1 text-[11px] font-normal text-white/40">{texto}</p>
                {onClick ? (
                    <button type="button" onClick={onClick} className="mt-2 text-[11px] font-bold text-ecf-yellow hover:underline">
                        {rotuloBotao}
                    </button>
                ) : null}
            </div>
        </div>
    );
}

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
    const { auth, tarefas_alavancas: tarefasAlavancasBruto } = usePage().props;
    const userId = auth?.user?.id ?? null;
    // Badge do botão "Aguardando alavancas" (prop compartilhada; ausente = 0).
    const tarefasAlavancas = numeroSeguro(tarefasAlavancasBruto) ?? 0;

    // ⚠️ `= []` / `= {}` na assinatura só cobrem `undefined`. O servidor pode
    // mandar `null` (e um payload estranho pode mandar string). Normalizar ANTES
    // de qualquer leitura — inclusive antes do `useState`, que lia `filtros.busca`
    // direto e derrubava a tela inteira com `filtros: null`.
    const lista = comoLista(empresas);
    const pag = comoObjeto(paginacao);
    const filtrosSeguros = comoObjeto(filtros);
    const indicadoresSeguros = comoObjeto(indicadores);
    const buscaDoServidor = textoSeguro(filtrosSeguros.busca, '');
    const filtroAtual = textoSeguro(filtrosSeguros.filtro, 'todos');

    const [busca, setBusca] = useState(buscaDoServidor);
    const [ordem, setOrdem] = useState('nome');
    const [carregando, setCarregando] = useState(false);
    const [erroCarga, setErroCarga] = useState(false);
    const [status, setStatus] = useState(null); // { tipo: 'ok' | 'erro', texto }
    const [recentes, setRecentes] = useState(() => lerRecentes(userId));
    const espera = useRef(null);
    const primeira = useRef(true);

    const rotuloPrograma = ROTULO_PROGRAMA[programa] ?? 'Polos';
    const temBuscaOuFiltro = buscaDoServidor !== '' || filtroAtual !== 'todos';

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
        if (busca === buscaDoServidor) return undefined;
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
        setStatus({ tipo: 'ok', texto: `${textoSeguro(e?.nome, 'Conta')}: ${textoSeguro(json?.mensagem, 'Nada novo no Portal.')}` });
        router.reload({ only: ['empresas', 'indicadores', 'programas'] });
    }

    const vazioDoPrograma = (numeroSeguro(indicadoresSeguros.empresas) ?? 0) === 0 && ! temBuscaOuFiltro;
    const total = numeroSeguro(pag.total) ?? 0;
    const porPagina = Math.max(1, numeroSeguro(pag.por_pagina) ?? 50);
    const paginaAtual = numeroSeguro(pag.pagina) ?? 1;
    const precisaPaginar = total > porPagina;
    const ultimaPagina = Math.max(1, Math.ceil(total / porPagina));
    const linhas = ordenar(lista, ordem);

    // Números do cabeçalho e do rodapé. Cada um diz, no próprio texto, de onde
    // vem: `indicadores` é do PROGRAMA inteiro; o resto é da página exibida.
    const aReconectar = lista.filter((e) => e?.token !== 'ativo').length;
    const kitsNaLista = lista.reduce((soma, e) => soma + (numeroSeguro(e?.fases?.kits) ?? 0), 0);
    const prontosDoPrograma = numeroSeguro(indicadoresSeguros.prontos) ?? 0;

    // ⚠️ Este `.map()` é JS do corpo do componente, NÃO um callback de JSX: é aqui
    // que o cruzamento Recentes × linha viva acontece, para o `.map()` do JSX lá
    // embaixo ler só o próprio item (armadilha do Rollup —
    // feedback_rollup_map_scope_bug.md). O cartão só mostra número quando a conta
    // está na página exibida: número guardado no localStorage envelhece sem avisar.
    const recentesComDados = recentes.map((r) => {
        const chaveRecente = textoSeguro(r?.chave, '');

        return {
            chave: chaveRecente,
            item: r,
            dados: lista.find((e) => textoSeguro(e?.chave, '') === chaveRecente) ?? null,
        };
    });

    return (
        <AppLayout title="Publicador Mercado Livre">
            <div className="mx-auto max-w-[1240px] px-8 py-8">

                {/* Programa — as abas do topo do mockup */}
                <div className="mb-6">
                    <SeletorPrograma programa={programa} programas={comoObjeto(programas)} onTrocar={trocarPrograma} />
                </div>

                {/* Cabeçalho */}
                <div className="mb-8 flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12]">
                            <Rocket className="h-[18px] w-[18px] text-ecf-yellow" aria-hidden="true" />
                        </div>
                        <div>
                            <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-ecf-yellow/70">
                                Publicador Mercado Livre
                            </p>
                            <h1 className="font-display text-[24px] font-bold leading-tight text-white">Seleção de Empresas</h1>
                            <p className="text-[13px] font-normal text-white/55">
                                Escolha a conta para ver o catálogo, o que já está no ar e o que está pronto para publicar.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* 09/10/2026 — a fila das tarefas pós-publicação ("Publicados
                            aguardando alavancas"); o número é o mesmo do menu: abertas
                            minhas ou de ninguém. */}
                        <button
                            type="button"
                            onClick={() => router.get(route('mlb.anuncios.publicador.tarefas.index'))}
                            className={BOTAO_SECUNDARIO}
                        >
                            <Zap className="h-4 w-4" aria-hidden="true" />
                            Aguardando alavancas
                            {tarefasAlavancas > 0 && (
                                <span className="rounded-full border border-amber-400/40 bg-amber-400/10 px-2 py-0.5 font-mono text-[11px] font-bold tabular-nums text-amber-300">
                                    {tarefasAlavancas}
                                </span>
                            )}
                        </button>

                        {/*
                          Decisão 3 do plano: há DOIS destinos possíveis no sistema
                          (cadastrar a empresa no Comercial e conectar o OAuth de uma já
                          cadastrada) e o usuário ainda não escolheu qual é este botão.
                          Desabilitado com "Em breve" — o mesmo vocabulário que o próprio
                          mockup usa — em vez de escolher por ele.
                        */}
                        <button
                            type="button"
                            disabled
                            title="Ainda não definido por onde esta tela conecta uma empresa nova."
                            className={cn(BOTAO_SECUNDARIO, 'cursor-not-allowed opacity-60')}
                        >
                            <Plus className="h-4 w-4" aria-hidden="true" />
                            Conectar nova empresa
                            <span className="rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/40">
                                Em breve
                            </span>
                        </button>
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
                                <section aria-label="Acesso rápido">
                                    <div className="mb-4 flex items-center gap-2">
                                        <History className="h-4 w-4 text-white/40" aria-hidden="true" />
                                        <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                                            Acesso rápido — contas que você abriu
                                        </h2>
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                        {recentesComDados.map((rc) => (
                                            <CartaoAcessoRapido
                                                key={rc.chave}
                                                item={rc.item}
                                                dados={rc.dados}
                                                onAbrir={() => router.get(route('mlb.anuncios.publicador.visao-geral', { conta: rc.chave }))}
                                            />
                                        ))}
                                    </div>
                                </section>
                            )}

                            {/*
                              Barra de filtros no formato do mockup. ⚠️ Os 4 filtros de
                              hoje continuam com o MESMO efeito — só mudaram de lugar. O
                              dropdown "ERP" do mockup não entra: não existe dado de ERP
                              suficiente para filtrar por ele sem inventar.
                            */}
                            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <div className="flex flex-wrap items-center gap-4">
                                    <label className="relative block min-w-[260px] flex-1">
                                        <span className="sr-only">Buscar empresa</span>
                                        <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-white/40" aria-hidden="true" />
                                        <input
                                            type="search"
                                            value={busca}
                                            onChange={(ev) => setBusca(ev.target.value)}
                                            placeholder="Buscar por razão social, CNPJ ou CUST…"
                                            className="h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] pl-10 pr-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                        />
                                    </label>

                                    <label className="flex items-center gap-2">
                                        <ArrowUpDown className="h-4 w-4 text-white/40" aria-hidden="true" />
                                        <span className="sr-only">Ordenar</span>
                                        <select
                                            value={ordem}
                                            onChange={(ev) => setOrdem(ev.target.value)}
                                            title="Reordena as empresas exibidas nesta página. Não muda o filtro nem busca no servidor."
                                            className="h-10 rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 text-[13px] font-normal text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                        >
                                            {ORDENS.map((o) => (
                                                <option key={o.chave} value={o.chave}>{o.rotulo}</option>
                                            ))}
                                        </select>
                                    </label>
                                </div>

                                <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filtro da lista">
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
                            </div>

                            <section className="rounded-xl bg-ecf-card">
                                <div className="flex flex-wrap items-center justify-between gap-4 p-4">
                                    <h2 className="text-[15px] font-bold text-white">Empresas de {rotuloPrograma}</h2>
                                    <span className="font-mono text-[13px] tabular-nums text-white/55">{lista.length} exibidas</span>
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
                                ) : linhas.length === 0 ? (
                                    <div className="px-4 py-12 text-center">
                                        <p className="text-[15px] font-bold text-white">
                                            {buscaDoServidor !== ''
                                                ? `Nenhuma empresa encontrada para “${buscaDoServidor}”.`
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
                                                {linhas.map((e) => {
                                                    // ⚠️ TUDO que a linha usa é calculado AQUI DENTRO, a partir
                                                    // de `e`: flag booleana lida do escopo do componente dentro
                                                    // de um `.map()` já foi eliminada pelo Rollup no bundle de
                                                    // produção desta base (ReferenceError em tela).
                                                    // ⚠️ E TODO campo passa por textoSeguro/numeroSeguro: foi um
                                                    // campo que chegou como objeto e foi renderizado cru que
                                                    // derrubou a tela de Produtos em 07/10.
                                                    const chaveLinha = textoSeguro(e?.chave, '');
                                                    const nomeLinha = textoSeguro(e?.nome, '') || chaveLinha || 'Empresa';
                                                    const identificadorLinha = textoSeguro(e?.identificador, '');
                                                    const erpLinha = textoSeguro(e?.erp?.nome, '');
                                                    const kitsLinha = numeroSeguro(e?.fases?.kits) ?? 0;
                                                    const prontosLinha = numeroSeguro(e?.prontos) ?? 0;
                                                    const travada = e?.liberada === false;
                                                    const temToken = e?.token !== 'sem_token';
                                                    const podeSincronizar = Boolean(e?.company_id) && e?.portal?.situacao !== 'sem_portal';

                                                    return (
                                                        <tr
                                                            key={chaveLinha || nomeLinha}
                                                            tabIndex={0}
                                                            onClick={() => abrirConta(e)}
                                                            onKeyDown={(ev) => {
                                                                if (ev.key === 'Enter' && ev.target === ev.currentTarget) abrirConta(e);
                                                            }}
                                                            className="h-14 cursor-pointer border-b border-white/[0.06] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow"
                                                        >
                                                            <td className="px-4 py-2">
                                                                <div className="flex items-center gap-3">
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12] font-mono text-[11px] font-bold text-ecf-yellow"
                                                                    >
                                                                        {iniciaisDe(nomeLinha)}
                                                                    </span>
                                                                    <div className="min-w-0">
                                                                        <p className="text-[13px] font-normal text-white">{nomeLinha}</p>
                                                                        <p className="font-mono text-[11px] font-normal text-white/40">
                                                                            {identificadorLinha !== '' ? identificadorLinha : 'Sem identificador'}
                                                                        </p>
                                                                        {travada ? <AvisoContaTravada variante="selo" className="mt-1" /> : null}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="px-4 py-2"><SeloConta token={e?.token} /></td>
                                                            <td className="px-4 py-2"><SeloPortal portal={e?.portal} /></td>
                                                            <td className="px-4 py-2">
                                                                {/*
                                                                  ⚠️ Decisão 8 do handoff: o ERP é apenas DECLARADO no
                                                                  onboarding. O mockup escrevia "Bling · há 14m /
                                                                  Sincronizado"; não existe integração com ERP nenhum, e
                                                                  carimbar sincronização aqui afirmaria um fato falso
                                                                  sobre a conta de um cliente.
                                                                */}
                                                                {erpLinha !== '' ? (
                                                                    <span
                                                                        className="inline-flex items-center gap-2 whitespace-nowrap"
                                                                        title="ERP declarado no onboarding — não existe integração ativa com o ERP."
                                                                    >
                                                                        <span className="rounded-md border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/70">
                                                                            {erpLinha}
                                                                        </span>
                                                                        <span className="text-[11px] font-normal text-white/40">declarado</span>
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-[11px] font-normal text-white/40">não informado</span>
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-2 text-[13px] font-normal tabular-nums text-white/70">
                                                                {contagem(numeroSeguro(e?.produtos) ?? 0, 'produto', 'produtos')}
                                                            </td>
                                                            <td className="px-4 py-2 text-[13px] font-normal tabular-nums text-white/70">
                                                                {contagem(numeroSeguro(e?.publicados) ?? 0, 'anúncio', 'anúncios')}
                                                            </td>
                                                            <td className="px-4 py-2">
                                                                {/*
                                                                  Só o que é real: quantos produtos da conta já são kit
                                                                  (Fase 2 em diante) e quantos rascunhos estão prontos
                                                                  para publicar. Sem nenhum dos dois, um traço — nada de
                                                                  "4 em revisão"/"2 fiscais" do mockup, que não existem.
                                                                */}
                                                                {(kitsLinha > 0 || prontosLinha > 0) ? (
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        {kitsLinha > 0 ? (
                                                                            <span
                                                                                title="Produtos desta conta que já têm kit (Fase 2 em diante)."
                                                                                className="whitespace-nowrap rounded-md border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold tabular-nums text-white/70"
                                                                            >
                                                                                {kitsLinha} kits
                                                                            </span>
                                                                        ) : null}
                                                                        {prontosLinha > 0 ? (
                                                                            <span
                                                                                title="Rascunhos conferidos, prontos para publicar."
                                                                                className="whitespace-nowrap rounded-md border border-ecf-yellow/40 bg-ecf-yellow/10 px-2 py-1 text-[11px] font-bold tabular-nums text-ecf-yellow"
                                                                            >
                                                                                {prontosLinha} prontos
                                                                            </span>
                                                                        ) : null}
                                                                    </div>
                                                                ) : (
                                                                    <span className="text-[11px] font-normal text-white/40">—</span>
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-2">
                                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                                    {podeSincronizar ? (
                                                                        <BotaoSincronizarPortal
                                                                            conta={chaveLinha}
                                                                            onConcluido={(json) => aoConcluirSync(e, json)}
                                                                            onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                                                                        />
                                                                    ) : null}
                                                                    {temToken ? (
                                                                        <button
                                                                            type="button"
                                                                            onClick={(ev) => { ev.stopPropagation(); abrirConta(e); }}
                                                                            className={BOTAO_SECUNDARIO}
                                                                        >
                                                                            Publicar →
                                                                        </button>
                                                                    ) : (
                                                                        <LinkReconexao link={e?.link_reconexao} />
                                                                    )}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                {total > 0 && (
                                    <div className="flex flex-wrap items-center justify-between gap-4 p-4">
                                        <p className="text-[13px] font-normal tabular-nums text-white/55">
                                            Mostrando {numeroSeguro(pag.de) ?? 0}–{numeroSeguro(pag.ate) ?? 0} de {total}
                                            {precisaPaginar ? ` · Página ${paginaAtual} de ${ultimaPagina}` : ''}
                                        </p>
                                        {precisaPaginar && (
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    disabled={paginaAtual <= 1}
                                                    onClick={() => irParaPagina(paginaAtual - 1)}
                                                    className={cn(BOTAO_SECUNDARIO, 'disabled:cursor-not-allowed disabled:opacity-40')}
                                                >
                                                    <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={paginaAtual >= ultimaPagina}
                                                    onClick={() => irParaPagina(paginaAtual + 1)}
                                                    className={cn(BOTAO_SECUNDARIO, 'disabled:cursor-not-allowed disabled:opacity-40')}
                                                >
                                                    Próxima <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </section>

                            {/*
                              Rodapé do mockup, com UM card a menos de ficção: dois leem
                              número real e o terceiro é texto. O card do ⌘K virou
                              explicação de como trocar de conta — a busca global é outra
                              tarefa, e prometer um atalho que não existe é pior que não
                              ter o card.
                            */}
                            <div className="grid gap-4 md:grid-cols-3">
                                <CartaoDeRodape
                                    icone={PlugZap}
                                    titulo="Contas a reconectar"
                                    destaque={`${aReconectar} ${aReconectar === 1 ? 'conta' : 'contas'} nesta lista`}
                                    texto="Token do Mercado Livre expirado ou ainda não gravado. O cliente precisa abrir o link de reconexão no navegador dele."
                                    onClick={() => aplicarFiltro('atencao')}
                                    rotuloBotao="Ver só essas →"
                                />
                                <CartaoDeRodape
                                    icone={BookOpenCheck}
                                    titulo="Prontos para publicar"
                                    destaque={`${prontosDoPrograma} ${prontosDoPrograma === 1 ? 'produto' : 'produtos'} em ${rotuloPrograma}`}
                                    texto="Rascunhos já conferidos, esperando só a publicação. Número do programa inteiro, não só desta página."
                                    onClick={() => aplicarFiltro('prontos')}
                                    rotuloBotao="Ver só essas →"
                                />
                                <CartaoDeRodape
                                    icone={Boxes}
                                    titulo="Kits em jogo"
                                    destaque={`${kitsNaLista} ${kitsNaLista === 1 ? 'kit' : 'kits'} nesta lista`}
                                    texto="Produtos que já ganharam uma Fase 2 ou além. Abra a conta para ver quais."
                                />
                            </div>

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
