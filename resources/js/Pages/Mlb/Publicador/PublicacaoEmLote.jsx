import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { ChevronLeft, Loader2, Search, Send, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import BarraDaConta from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import LinhaDoLote, { COLUNAS_DO_LOTE } from '@/Components/Mlb/Publicador/Lote/LinhaDoLote';
import PainelDaFila from '@/Components/Mlb/Publicador/Lote/PainelDaFila';
import DialogoAgendar from '@/Components/Mlb/Publicador/Lote/DialogoAgendar';
import {
    FILTROS, comoLista, comoObjeto, conferiveisDaSelecao, contagensDoLote, filtrarLinhas, numeroSeguro,
    precisaAcompanhar, prontosDaSelecao, selecaoInicial, textoSeguro,
} from '@/Components/Mlb/Publicador/Lote/regrasDoLote.js';
import { mensagemDe } from '@/Components/Publicador/apoio.js';
import { BASE_BOTAO, PRIMARIO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';

// ─── Publicação em lote da conta (10/10/2026, pedido do usuário) ────────────
//
// "De primeira": o que o cliente preencheu no Portal vira anúncio sem abrir
// produto por produto. Na mesma tela: a VISÃO RÁPIDA (títulos, preço, custo,
// frete, margem, pendências), "Conferir selecionados" (o servidor confere um a
// um, 10 s entre eles) e "Agendar publicação" — uma FILA que publica um produto
// (Clássico + Premium, todas as cores) a cada N minutos, com pausar, retomar e
// cancelar. Quem anda a fila é o servidor, todo minuto; esta tela acompanha por
// polling (10 s) enquanto há conferência rodando ou fila viva.
//
// Um só amarelo sólido por tela (regra do editor): "Agendar publicação".

const ROTA = 'mlb.anuncios.publicador.lote';

const SEGMENTO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap border-r border-white/[0.08] px-3 text-[13px] last:border-r-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow';
const BOTAO_PEQUENO = 'inline-flex h-8 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const CABECALHO = 'whitespace-nowrap text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

/** "Fulano: motivo" para cada id recusado/ignorado, com o nome que a tela conhece. */
export function detalhesDosRecusados(recusados, linhas) {
    const nomes = new Map(comoLista(linhas).map((l) => [l?.produto_id, textoSeguro(l?.nome, '')]));

    return Object.entries(comoObjeto(recusados)).map(([id, motivo]) => {
        const nome = nomes.get(Number(id)) || `Produto #${id}`;

        return `${nome}: ${textoSeguro(motivo, 'não entrou')}`;
    });
}

export default function PublicacaoEmLote({
    empresa,
    liberada = false,
    abas = { company_id: null },
    linhas: linhasIniciais = [],
    fila: filaInicial = null,
    selecionados = [],
    config = {},
}) {
    const [linhas, setLinhas] = useState(() => comoLista(linhasIniciais));
    const [fila, setFila] = useState(() => (filaInicial && typeof filaInicial === 'object' ? filaInicial : null));
    const [selecao, setSelecao] = useState(() => selecaoInicial(linhasIniciais, selecionados));
    const [filtro, setFiltro] = useState('todos');
    const [busca, setBusca] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const [aviso, setAviso] = useState(null); // { tipo: 'ok' | 'erro', texto, detalhes }
    const [dialogo, setDialogo] = useState(false);
    const [erroDialogo, setErroDialogo] = useState(null);
    const contaRef = useRef(empresa?.chave);
    contaRef.current = empresa?.chave;

    const cfg = comoObjeto(config);
    const visiveis = useMemo(() => filtrarLinhas(linhas, filtro, busca), [linhas, filtro, busca]);
    const contagens = useMemo(() => contagensDoLote(linhas), [linhas]);
    const prontos = useMemo(() => prontosDaSelecao(linhas, selecao), [linhas, selecao]);
    const conferiveis = useMemo(() => conferiveisDaSelecao(linhas, selecao), [linhas, selecao]);
    const acompanhar = precisaAcompanhar(linhas, fila);
    const filaViva = comoObjeto(fila).viva === true;

    function aplicar(data) {
        if (Array.isArray(data?.linhas)) setLinhas(data.linhas);
        if (data && Object.prototype.hasOwnProperty.call(data, 'fila')) setFila(data.fila && typeof data.fila === 'object' ? data.fila : null);
    }

    // Polling só enquanto há o que acompanhar; limpo no unmount.
    useEffect(() => {
        if (! acompanhar) return undefined;
        const id = setInterval(async () => {
            try {
                const { data } = await axios.get(route(`${ROTA}.dados`, { conta: contaRef.current }));
                aplicar(data);
            } catch {
                // Uma leitura que falha não para o acompanhamento: tenta na próxima volta.
            }
        }, Math.max(5, numeroSeguro(cfg.polling_s) ?? 10) * 1000);

        return () => clearInterval(id);
    }, [acompanhar]); // eslint-disable-line react-hooks/exhaustive-deps

    function alternar(id) {
        setSelecao((atual) => {
            const proxima = new Set(atual);
            if (proxima.has(id)) proxima.delete(id); else proxima.add(id);

            return proxima;
        });
    }

    async function conferir() {
        if (conferiveis.length === 0 || ocupado) return;
        setOcupado(true);
        setAviso(null);
        try {
            const { data } = await axios.post(route(`${ROTA}.conferir`, { conta: contaRef.current }), { produtos: conferiveis.slice(0, numeroSeguro(cfg.conferir_max) ?? 100) });
            aplicar(data);
            setAviso({ tipo: 'ok', texto: textoSeguro(data?.mensagem, 'Conferindo.'), detalhes: detalhesDosRecusados(data?.ignorados, linhas) });
        } catch (e) {
            aplicar(e?.response?.data);
            setAviso({ tipo: 'erro', texto: textoSeguro(e?.response?.data?.mensagem, mensagemDe(e)), detalhes: detalhesDosRecusados(e?.response?.data?.ignorados, linhas) });
        } finally {
            setOcupado(false);
        }
    }

    async function agendar(opcoes) {
        setOcupado(true);
        setErroDialogo(null);
        try {
            const { data } = await axios.post(route(`${ROTA}.agendar`, { conta: contaRef.current }), { produtos: prontos.ids, ...opcoes });
            aplicar(data);
            setDialogo(false);
            setSelecao(new Set());
            setAviso({ tipo: 'ok', texto: textoSeguro(data?.mensagem, 'Agendado.'), detalhes: detalhesDosRecusados(data?.recusados, linhas) });
        } catch (e) {
            aplicar(e?.response?.data);
            const recusados = detalhesDosRecusados(e?.response?.data?.recusados, linhas);
            setErroDialogo([mensagemDe(e), ...recusados].join(' '));
        } finally {
            setOcupado(false);
        }
    }

    async function naFila(acao, metodo = 'post', extra = {}) {
        setOcupado(true);
        setAviso(null);
        try {
            const { data } = await axios[metodo](route(`${ROTA}.${acao}`, { conta: contaRef.current, ...extra }));
            aplicar(data);
        } catch (e) {
            setAviso({ tipo: 'erro', texto: mensagemDe(e), detalhes: [] });
        } finally {
            setOcupado(false);
        }
    }

    const idsVisiveis = visiveis.map((l) => l?.produto_id).filter((id) => id !== undefined);
    const todosVisiveisMarcados = idsVisiveis.length > 0 && idsVisiveis.every((id) => selecao.has(id));

    return (
        <AppLayout title={`Publicação em lote — ${textoSeguro(empresa?.nome, 'Conta')}`}>
            <div className="mx-auto max-w-[1240px] px-8 py-8">
                <BarraDaConta
                    empresa={empresa}
                    liberada={liberada}
                    acoes={(
                        <Link href={route('mlb.anuncios.publicador.produtos', { conta: empresa?.chave })} className={cn(BASE_BOTAO, SECUNDARIO, 'font-normal')}>
                            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                            Produtos
                        </Link>
                    )}
                />

                <div className="mb-6">
                    <AbasDaConta aba="produtos" conta={empresa?.chave} companyId={abas?.company_id ?? null} contagemAlavancas={abas?.alavancas_pendentes ?? null} />
                </div>

                {! liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

                <header className="mb-6">
                    <h1 className="text-[24px] font-bold text-white">Publicação em lote</h1>
                    <p className="mt-1 max-w-[860px] text-[13px] font-normal text-white/60">
                        Veja título, preço e margem de cada produto, confira com o Mercado Livre os que você escolher e agende a publicação:
                        um produto por vez, com intervalo entre eles, para não arriscar restrição na conta.
                    </p>
                </header>

                <PainelDaFila
                    fila={fila}
                    ocupado={ocupado}
                    aoPausar={() => naFila('pausar')}
                    aoRetomar={() => naFila('retomar')}
                    aoCancelar={() => naFila('cancelar')}
                    aoRemover={(itemId) => naFila('itens.remover', 'delete', { item: itemId })}
                />

                <div aria-live="polite">
                    {aviso && (
                        <div
                            className={cn(
                                'mb-4 rounded-lg border px-3 py-2 text-[13px] font-normal',
                                aviso.tipo === 'ok' ? 'border-sky-500/25 bg-sky-500/[0.06] text-sky-200' : 'border-red-500/30 bg-red-500/[0.06] text-red-300',
                            )}
                        >
                            <p>{textoSeguro(aviso.texto)}</p>
                            {comoLista(aviso.detalhes).length > 0 && (
                                <ul className="mt-1 list-disc pl-5 text-[11px] text-white/60">
                                    {comoLista(aviso.detalhes).slice(0, 8).map((d, i) => <li key={`${i}-${d}`}>{d}</li>)}
                                </ul>
                            )}
                        </div>
                    )}
                </div>

                <section className="rounded-xl bg-ecf-card">
                    <div className="sticky top-0 z-10 rounded-t-xl bg-ecf-card">
                        <div className="flex flex-wrap items-center gap-3 p-4">
                            <div className="inline-flex overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.03]" role="group" aria-label="Filtro">
                                {FILTROS.map((f) => {
                                    // Flag calculada DENTRO do callback (armadilha do Rollup).
                                    const ativo = filtro === f.chave;

                                    return (
                                        <button
                                            key={f.chave}
                                            type="button"
                                            aria-pressed={ativo}
                                            onClick={() => setFiltro(f.chave)}
                                            className={cn(SEGMENTO, ativo ? 'bg-ecf-yellow/10 font-bold text-ecf-yellow' : 'font-normal text-white/70 hover:bg-white/[0.06]')}
                                        >
                                            {f.rotulo}
                                            <span className="font-mono text-[11px] tabular-nums">{contagens[f.chave] ?? 0}</span>
                                        </button>
                                    );
                                })}
                            </div>

                            <label className="relative block w-[220px]">
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

                            <div className="ml-auto flex flex-wrap items-center gap-2">
                                <button type="button" onClick={conferir} disabled={conferiveis.length === 0 || ocupado} className={cn(BASE_BOTAO, SECUNDARIO)}>
                                    {ocupado ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <ShieldCheck className="h-4 w-4" aria-hidden="true" />}
                                    {conferiveis.length > 0 ? `Conferir selecionados (${conferiveis.length})` : 'Conferir selecionados'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => { setErroDialogo(null); setDialogo(true); }}
                                    disabled={prontos.ids.length === 0 || ocupado || ! liberada}
                                    title={! liberada ? 'A publicação ainda não foi liberada para esta conta.' : undefined}
                                    className={cn(BASE_BOTAO, PRIMARIO)}
                                >
                                    <Send className="h-4 w-4" aria-hidden="true" />
                                    {prontos.ids.length > 0 ? `Agendar publicação (${prontos.ids.length})` : 'Agendar publicação'}
                                </button>
                            </div>
                        </div>

                        {selecao.size > 0 && (
                            <div className="flex flex-wrap items-center gap-3 border-t border-ecf-yellow/20 bg-ecf-yellow/[0.06] px-4 py-2">
                                <p className="text-[13px] font-bold text-ecf-yellow">
                                    {selecao.size === 1 ? '1 selecionado' : `${selecao.size} selecionados`}
                                    <span className="ml-2 font-normal text-white/60">
                                        {prontos.ids.length === 1 ? '1 pronto para agendar' : `${prontos.ids.length} prontos para agendar`}
                                    </span>
                                </p>
                                <button type="button" onClick={() => setSelecao(new Set())} className={BOTAO_PEQUENO}>Limpar seleção</button>
                            </div>
                        )}

                        {visiveis.length > 0 && (
                            <div role="row" style={{ gridTemplateColumns: COLUNAS_DO_LOTE }} className="grid items-center gap-4 border-y border-white/[0.06] px-4 py-2">
                                <div className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={todosVisiveisMarcados}
                                        onChange={() => setSelecao(todosVisiveisMarcados ? new Set() : new Set(idsVisiveis))}
                                        aria-label="Selecionar todos os produtos visíveis"
                                        className="h-4 w-4 rounded border-white/[0.20] bg-white/[0.04] accent-ecf-yellow"
                                    />
                                </div>
                                <div className={CABECALHO}>Produto</div>
                                <div className={CABECALHO}>Clássico e Premium · título · preço · margem estimada · promoção</div>
                                <div className={CABECALHO}>Conferência</div>
                            </div>
                        )}
                    </div>

                    {linhas.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">Nenhum rascunho para publicar nesta conta.</p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">Sincronize os produtos do Portal ou cadastre um produto na aba Produtos.</p>
                        </div>
                    ) : visiveis.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">Nenhum produto neste filtro.</p>
                            <button type="button" onClick={() => { setFiltro('todos'); setBusca(''); }} className={cn(BASE_BOTAO, SECUNDARIO, 'mt-4')}>
                                Limpar busca e filtro
                            </button>
                        </div>
                    ) : (
                        <div role="rowgroup" aria-label="Produtos para publicar">
                            {visiveis.map((l) => {
                                // ⚠️ Tudo da linha calculado DENTRO do callback (armadilha do Rollup).
                                const id = l?.produto_id;
                                const marcada = selecao.has(id);

                                return <LinhaDoLote key={textoSeguro(id, '')} linha={l} selecionada={marcada} aoSelecionar={() => alternar(id)} />;
                            })}
                        </div>
                    )}
                </section>

                <p className="mt-4 text-[11px] font-normal text-white/40">
                    A margem é uma estimativa: preço − custo − frete − (comissão + imposto) × preço, com a comissão e o imposto da Precificação do Portal.
                    Publicados saem desta lista; os que entraram pela fila aparecem no painel acima com o link do anúncio.
                </p>
            </div>

            <DialogoAgendar
                aberto={dialogo}
                produtos={prontos.ids.length}
                anuncios={prontos.anuncios}
                comAvisos={prontos.comAvisos}
                filaViva={filaViva}
                intervaloAtual={filaViva ? comoObjeto(fila).intervalo_minutos : null}
                janelaAtual={filaViva ? comoObjeto(fila).janela : null}
                intervaloPadrao={numeroSeguro(cfg.intervalo_padrao) ?? 10}
                intervaloMinimo={numeroSeguro(cfg.intervalo_minimo) ?? 2}
                intervaloMaximo={numeroSeguro(cfg.intervalo_maximo) ?? 240}
                enviando={ocupado}
                erro={erroDialogo}
                onFechar={() => setDialogo(false)}
                onConfirmar={agendar}
            />
        </AppLayout>
    );
}
