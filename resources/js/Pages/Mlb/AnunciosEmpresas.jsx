import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronRight, Search, X, Zap } from 'lucide-react';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
// "Recentes" mora num módulo próprio desde o plano 177-06: o dropdown de empresa
// do header global lê a MESMA chave de `localStorage`.
import { gravarRecente, lerRecentes } from '@/Components/Mlb/Publicador/recentesDoPublicador';
import {
    ORDEM_PROGRAMAS,
    ROTULO_PROGRAMA,
    filtrarEmpresas,
    iniciaisDe,
    numeroSeguro,
    situacaoDaEmpresa,
    textoDosProgramas,
    textoSeguro,
} from '@/Components/Mlb/Publicador/entradaDoPublicador';

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

/** `empresas` pode chegar `null` do servidor — o default de parâmetro só cobre `undefined`. */
const comoLista = (valor) => (Array.isArray(valor) ? valor : []);

/** `programas` idem: objeto de verdade, ou objeto vazio. */
const comoObjeto = (valor) => ((valor !== null && typeof valor === 'object' && ! Array.isArray(valor)) ? valor : {});

function Avatar({ nome }) {
    return (
        <span
            aria-hidden="true"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12] font-mono text-[11px] font-bold text-ecf-yellow"
        >
            {iniciaisDe(nome)}
        </span>
    );
}

function EtiquetaPrograma({ programa }) {
    const rotulo = ROTULO_PROGRAMA[programa];
    if (! rotulo) return null;

    return (
        <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.04] px-2 py-0.5 text-[11px] font-normal text-white/55">
            {rotulo}
        </span>
    );
}

/**
 * Cartão de "Recentes". `item` é o que está no `localStorage`
 * (`{ chave, nome, identificador, programa }`, e um item antigo pode não ter nem
 * isso); `dados` é a linha viva da mesma conta, quando ela ainda está na lista.
 */
function CartaoRecente({ item, dados, onAbrir }) {
    const chave = textoSeguro(item?.chave, '');
    const nome = textoSeguro(dados?.nome, '') || textoSeguro(item?.nome, '') || chave || 'Empresa';
    const programa = textoSeguro(dados?.programa, '') || textoSeguro(item?.programa, '');

    return (
        <button
            type="button"
            disabled={chave === ''}
            onClick={() => onAbrir?.()}
            className="flex min-w-0 items-center gap-3 rounded-xl border border-white/[0.08] bg-ecf-card px-4 py-3 text-left hover:border-white/20 hover:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:cursor-not-allowed disabled:opacity-40"
        >
            <Avatar nome={nome} />
            <span className="min-w-0">
                <span className="block truncate text-[15px] font-normal text-white" title={nome}>{nome}</span>
                <span className="mt-1 flex"><EtiquetaPrograma programa={programa} /></span>
            </span>
        </button>
    );
}

/**
 * Uma empresa da lista. Componente próprio de propósito: tudo que a linha usa
 * vem das props (armadilha do Rollup deste projeto — variável de escopo da
 * página lida dentro de `.map()` já sumiu do bundle de produção).
 *
 * A linha inteira abre a conta (clique ou Enter). O botão da direita aparece só
 * quando há algo a fazer — ver `situacaoDaEmpresa()`.
 */
function LinhaDeEmpresa({ empresa, onAbrir, onSincronizado, onErro }) {
    const e = (empresa !== null && typeof empresa === 'object') ? empresa : {};
    const chave = textoSeguro(e.chave, '');
    const nome = textoSeguro(e.nome, '') || chave || 'Empresa';
    const identificador = textoSeguro(e.identificador, '');
    const programa = textoSeguro(e.programa, '');
    const linkReconexao = textoSeguro(e.link_reconexao, '');
    const situacao = situacaoDaEmpresa(e);
    const abrir = () => { if (chave !== '') onAbrir?.(e); };

    return (
        <li
            tabIndex={0}
            onClick={abrir}
            onKeyDown={(ev) => {
                if (ev.key === 'Enter' && ev.target === ev.currentTarget) abrir();
            }}
            className="grid min-h-[60px] cursor-pointer grid-cols-[minmax(240px,520px)_minmax(0,1fr)_auto] items-center gap-6 border-b border-white/[0.06] px-4 py-2 last:border-b-0 hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow"
        >
            <div className="flex min-w-0 items-center gap-3">
                <Avatar nome={nome} />
                <div className="min-w-0">
                    <div className="flex min-w-0 items-center gap-2">
                        <p className="truncate text-[15px] font-normal text-white" title={nome}>{nome}</p>
                        <EtiquetaPrograma programa={programa} />
                    </div>
                    <p className="truncate font-mono text-[11px] font-normal text-white/40">
                        {identificador !== '' ? identificador : 'Sem identificador'}
                    </p>
                </div>
            </div>

            <div className="flex min-w-0 items-center gap-3 text-[13px] font-normal">
                <span className={cn('inline-flex shrink-0 items-center gap-2 whitespace-nowrap', situacao.conectada ? 'text-emerald-400' : 'text-amber-300')}>
                    <span aria-hidden="true" className={cn('h-[6px] w-[6px] rounded-full', situacao.conectada ? 'bg-emerald-400' : 'bg-amber-300')} />
                    {situacao.conectada ? 'Conectada' : 'Desconectada'}
                </span>
                {situacao.texto ? <span className="truncate text-white/55">{situacao.texto}</span> : null}
            </div>

            <div className="flex items-center justify-end gap-3">
                {situacao.acao === 'reconectar' ? (
                    <LinkReconexao
                        link={linkReconexao || null}
                        className="border-amber-300/30 bg-amber-300/[0.06] text-amber-200 hover:bg-amber-300/10"
                    />
                ) : null}
                {situacao.acao === 'publicar' ? (
                    <button
                        type="button"
                        onClick={(ev) => { ev.stopPropagation(); abrir(); }}
                        className="inline-flex h-10 items-center whitespace-nowrap rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/10 px-4 text-[13px] font-bold text-ecf-yellow hover:bg-ecf-yellow/[0.16] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        Publicar
                    </button>
                ) : null}
                {situacao.acao === 'sincronizar' ? (
                    <BotaoSincronizarPortal
                        conta={chave}
                        onConcluido={(json) => onSincronizado?.(e, json)}
                        onErro={(texto) => onErro?.(texto)}
                    />
                ) : null}
                <ChevronRight className="h-4 w-4 text-white/30" aria-hidden="true" />
            </div>
        </li>
    );
}

/**
 * Tela A do Publicador interno: entrada de /mlb/anuncios — "busca primeiro".
 *
 * 26/10/2026 (escolha do usuário entre os mockups do Gemini): uma busca grande,
 * os Recentes e a lista de TODAS as empresas, ocupando a largura da tela. Saíram
 * as abas de programa, os quatro cartões de número, o "Como funciona", o botão
 * "Conectar nova empresa — Em breve", as colunas ERP/Catálogo/Anúncios/Fases, os
 * cartões do rodapé, a ordenação e a paginação.
 *
 * A busca e as etiquetas de programa filtram no navegador, sobre a lista inteira
 * que o servidor manda numa visita só (`MlbPublicadorEntradaController::index`).
 */
export default function AnunciosEmpresas({ programa = 'todos', programas = {}, empresas = [] }) {
    const { auth, tarefas_alavancas: tarefasAlavancasBruto } = usePage().props;
    const userId = auth?.user?.id ?? null;
    // Badge do botão "Aguardando alavancas" (prop compartilhada; ausente = 0).
    const tarefasAlavancas = numeroSeguro(tarefasAlavancasBruto) ?? 0;

    const lista = comoLista(empresas);
    const contagens = comoObjeto(programas);
    const programaInicial = ORDEM_PROGRAMAS.includes(programa) ? programa : 'todos';

    const [busca, setBusca] = useState('');
    const [filtroPrograma, setFiltroPrograma] = useState(programaInicial);
    const [status, setStatus] = useState(null); // { tipo: 'ok' | 'erro', texto }
    const [recentes, setRecentes] = useState(() => lerRecentes(userId));
    const campo = useRef(null);

    // "/" leva à busca de qualquer ponto da tela (fora de outro campo de texto).
    useEffect(() => {
        function aoTeclar(ev) {
            if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) return;
            const alvo = ev.target;
            const tag = alvo?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || alvo?.isContentEditable) return;
            ev.preventDefault();
            campo.current?.focus();
        }
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, []);

    // Começar a digitar volta a etiqueta para "Todas": a busca procura em todos os
    // programas (é o que o texto de ajuda promete). Escolher a etiqueta DEPOIS
    // filtra o resultado da busca normalmente.
    function aoDigitar(valor) {
        if (busca.trim() === '' && valor.trim() !== '') setFiltroPrograma('todos');
        setBusca(valor);
    }

    const visiveis = useMemo(
        () => filtrarEmpresas(lista, { programa: filtroPrograma, busca }),
        [lista, filtroPrograma, busca],
    );

    // Abrir uma empresa (linha, "Publicar" ou Recentes) leva a Produtos. Grava a
    // conta em Recentes ANTES de navegar. O item NÃO guarda token (T-173-07).
    const abrirConta = (e) => {
        const item = { chave: e.chave, nome: e.nome, identificador: e.identificador, programa: e.programa };
        const novaLista = gravarRecente(userId, item);
        if (novaLista !== null) setRecentes(novaLista);
        router.get(route('mlb.anuncios.publicador.produtos', { conta: e.chave }));
    };

    function aoConcluirSync(e, json) {
        setStatus({ tipo: 'ok', texto: `${textoSeguro(e?.nome, 'Conta')}: ${textoSeguro(json?.mensagem, 'Nada novo no Portal.')}` });
        router.reload({ only: ['empresas', 'programas'] });
    }

    const aoErrarSync = (texto) => setStatus({ tipo: 'erro', texto: textoSeguro(texto, 'Não foi possível buscar do Portal.') });

    const nomesDosProgramas = textoDosProgramas(contagens);
    const ajuda = nomesDosProgramas.includes(' e ')
        ? `Procura em ${nomesDosProgramas} ao mesmo tempo`
        : 'Procura por nome, CNPJ ou CUST';

    // Etiquetas: "Todas" e só os programas que têm empresa (Incubadora hoje tem 0).
    const etiquetas = [
        { chave: 'todos', rotulo: 'Todas' },
        ...ORDEM_PROGRAMAS
            .filter((p) => (numeroSeguro(contagens[p]) ?? 0) > 0 || p === filtroPrograma)
            .map((p) => ({ chave: p, rotulo: ROTULO_PROGRAMA[p] })),
    ];

    // Recentes × linha viva: cruzado AQUI, fora do `.map()` do JSX.
    const recentesComDados = recentes.map((r) => {
        const chaveRecente = textoSeguro(r?.chave, '');
        const dados = lista.find((e) => textoSeguro(e?.chave, '') === chaveRecente) ?? null;

        return {
            chave: chaveRecente,
            item: r,
            dados,
            abrir: () => (dados !== null
                ? abrirConta(dados)
                : router.get(route('mlb.anuncios.publicador.produtos', { conta: chaveRecente }))),
        };
    });

    const buscaLimpa = busca.trim();
    const rotuloDoFiltro = ROTULO_PROGRAMA[filtroPrograma] ?? '';

    return (
        <AppLayout title="Publicador Mercado Livre">
            <div className="mx-auto w-full max-w-[1760px] px-2 py-2">

                <div className="mb-3 flex flex-wrap items-center justify-between gap-4">
                    <p className="text-[13px] font-normal text-white/55">Publicador Mercado Livre</p>
                    {/* A fila das tarefas pós-publicação; o número é o mesmo do menu. */}
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
                </div>

                <label className="relative block">
                    <span className="sr-only">Buscar empresa</span>
                    <Search className="pointer-events-none absolute left-5 top-1/2 h-5 w-5 -translate-y-1/2 text-white/40" aria-hidden="true" />
                    <input
                        ref={campo}
                        type="search"
                        autoFocus
                        value={busca}
                        onChange={(ev) => aoDigitar(ev.target.value)}
                        onKeyDown={(ev) => { if (ev.key === 'Escape') setBusca(''); }}
                        placeholder="Buscar empresa por nome, CNPJ ou CUST"
                        className="h-14 w-full rounded-xl border border-white/[0.10] bg-ecf-card pl-14 pr-14 text-[15px] font-normal text-white placeholder:text-white/40 focus:border-ecf-yellow/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40 [&::-webkit-search-cancel-button]:appearance-none"
                    />
                    {busca !== '' ? (
                        <button
                            type="button"
                            onClick={() => { setBusca(''); campo.current?.focus(); }}
                            aria-label="Limpar busca"
                            className="absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-white/55 hover:bg-white/[0.06] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        >
                            <X className="h-4 w-4" aria-hidden="true" />
                        </button>
                    ) : (
                        <kbd
                            aria-hidden="true"
                            className="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 rounded-md border border-white/[0.10] bg-white/[0.04] px-2 py-0.5 font-mono text-[11px] font-normal text-white/55"
                        >
                            /
                        </kbd>
                    )}
                </label>
                <p className="mt-2 text-[13px] font-normal text-white/40">{ajuda}</p>

                {recentes.length > 0 && (
                    <section aria-label="Recentes" className="mt-6">
                        <h2 className="mb-3 text-[13px] font-normal text-white/55">Recentes</h2>
                        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                            {recentesComDados.map((rc) => (
                                <CartaoRecente key={rc.chave || 'sem-chave'} item={rc.item} dados={rc.dados} onAbrir={rc.abrir} />
                            ))}
                        </div>
                    </section>
                )}

                <div className="mb-3 mt-8 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <h2 className="text-[15px] font-bold text-white">Todas as empresas</h2>
                        <span className="rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-0.5 font-mono text-[11px] font-normal tabular-nums text-white/55">
                            {visiveis.length}
                        </span>
                    </div>
                    <div role="group" aria-label="Programa" className="flex flex-wrap gap-2">
                        {etiquetas.map((et) => (
                            <button
                                key={et.chave}
                                type="button"
                                aria-pressed={filtroPrograma === et.chave}
                                onClick={() => setFiltroPrograma(et.chave)}
                                className={cn(
                                    'inline-flex h-8 items-center rounded-full border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                    filtroPrograma === et.chave
                                        ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                        : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
                                )}
                            >
                                {et.rotulo}
                            </button>
                        ))}
                    </div>
                </div>

                <div aria-live="polite">
                    {status && (
                        <p
                            className={cn(
                                'mb-3 rounded-lg border px-3 py-2 text-[13px] font-normal',
                                status.tipo === 'ok'
                                    ? 'border-sky-500/25 bg-sky-500/[0.06] text-sky-200'
                                    : 'border-red-500/30 bg-red-500/[0.06] text-red-300',
                            )}
                        >
                            {status.texto}
                        </p>
                    )}
                </div>

                <section aria-label="Empresas" className="overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card">
                    {lista.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">Nenhuma empresa com conta do Mercado Livre.</p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                Quando uma empresa autorizar o Mercado Livre, ela aparece aqui.
                            </p>
                        </div>
                    ) : visiveis.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">
                                {buscaLimpa !== ''
                                    ? `Nenhuma empresa encontrada para “${buscaLimpa}”${filtroPrograma !== 'todos' ? ` em ${rotuloDoFiltro}` : ''}.`
                                    : `Nenhuma empresa em ${rotuloDoFiltro}.`}
                            </p>
                            <div className="mt-4 flex justify-center gap-2">
                                {buscaLimpa !== '' && (
                                    <button type="button" onClick={() => setBusca('')} className={BOTAO_SECUNDARIO}>
                                        Limpar busca
                                    </button>
                                )}
                                {filtroPrograma !== 'todos' && (
                                    <button type="button" onClick={() => setFiltroPrograma('todos')} className={BOTAO_SECUNDARIO}>
                                        Ver todas
                                    </button>
                                )}
                            </div>
                        </div>
                    ) : (
                        <ul>
                            {visiveis.map((e, i) => (
                                <LinhaDeEmpresa
                                    key={textoSeguro(e?.chave, '') || `linha-${i}`}
                                    empresa={e}
                                    onAbrir={abrirConta}
                                    onSincronizado={aoConcluirSync}
                                    onErro={aoErrarSync}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
