import { useState } from 'react';
import { router } from '@inertiajs/react';
import { BarChart3, ListChecks, ListOrdered, Plus, Ticket, Video } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import Fila from '@/Components/DemandasDev/Fila';
import ListaDemandas from '@/Components/DemandasDev/ListaDemandas';
import Painel from '@/Components/DemandasDev/Painel';
import Reunioes from '@/Components/DemandasDev/Reunioes';
import DemandaDrawer from '@/Components/DemandasDev/DemandaDrawer';
import Chamados from '@/Components/DemandasDev/Chamados';
import ChamadoDrawer from '@/Components/DemandasDev/ChamadoDrawer';
import { AtualizacaoDialog, DemandaDialog, ReuniaoDialog } from '@/Components/DemandasDev/Formularios';

// "Tickets" só entra para a equipe dev (admin ou cargo Dev) — ver `equipe`.
const ABAS = [
    { id: 'fila',     rotulo: 'Fila',     icone: ListOrdered },
    { id: 'tickets', rotulo: 'Tickets', icone: Ticket, soEquipe: true },
    { id: 'demandas', rotulo: 'Demandas', icone: ListChecks },
    { id: 'painel',   rotulo: 'Painel',   icone: BarChart3 },
    { id: 'reunioes', rotulo: 'Reuniões', icone: Video },
];

// URL atual com `demanda`/`chamado` trocados — o detalhe vem por reload parcial.
// Abrir um tira o outro: só um painel lateral por vez.
const urlCom = (param, id) => {
    const u = new URL(window.location.href);
    u.searchParams.delete(param === 'demanda' ? 'ticket' : 'demanda');
    if (id) u.searchParams.set(param, id);
    else u.searchParams.delete(param);
    return u.pathname + u.search;
};
const abaDaUrl = () => (typeof window === 'undefined' ? null : new URL(window.location.href).searchParams.get('aba'));

/**
 * Demandas Dev — tarefas do time de desenvolvimento.
 *
 * Mesmo mecanismo da planilha de gestão: a demanda é a fotografia atual e o
 * andamento cresce no diário de atualizações; status, próxima ação e bloqueio
 * saem da atualização mais recente.
 */
export default function DemandasDevIndex({
    demandas, painel, reunioes, usuarios, areas, prefixos, pode, eu, hoje, detalhe, google,
    equipe = false, chamados = [], devs = [], chamado_detalhe: chamadoDetalhe = null,
}) {
    const abasVisiveis = ABAS.filter((a) => !a.soEquipe || equipe);
    const [aba, setAba] = useState(() => {
        if (chamadoDetalhe) return 'tickets';
        const pedida = abaDaUrl();
        if (pedida && abasVisiveis.some((a) => a.id === pedida)) return pedida;
        return eu.tem_demandas ? 'fila' : equipe ? 'tickets' : 'demandas';
    });
    const [abertaId, setAbertaId] = useState(detalhe?.id ?? null);
    const [chamadoId, setChamadoId] = useState(chamadoDetalhe?.id ?? null);
    const [convertendo, setConvertendo] = useState(null); // chamado sendo transformado em demanda
    const chamadosAtencao = chamados.filter((c) => c.precisa_atencao).length;
    const [atualizando, setAtualizando] = useState(null);
    const [editandoDemanda, setEditandoDemanda] = useState(null); // null | 'nova' | demanda
    // null | { reuniao } (editar) | { preset } (agendar, opcionalmente já com demanda e participante)
    const [editandoReuniao, setEditandoReuniao] = useState(null);

    // Agendar a partir de uma demanda: ela entra na pauta e o responsável, como participante.
    const agendarReuniao = (demanda) => setEditandoReuniao({
        preset: demanda ? {
            titulo: `${demanda.codigo} — ${demanda.titulo}`.slice(0, 255),
            modulo: demanda.area ?? '',
            demandas: [demanda.id],
            participantes: demanda.responsavel ? [demanda.responsavel.id] : [],
        } : null,
    });

    const aberta = abertaId ? demandas.find((d) => d.id === abertaId) : null;

    const abrir = (id) => {
        setChamadoId(null);
        setAbertaId(id);
        router.get(urlCom('demanda', id), {}, { only: ['detalhe'], preserveState: true, preserveScroll: true, replace: true });
    };
    const fechar = () => {
        setAbertaId(null);
        router.get(urlCom('demanda', null), {}, { only: ['detalhe'], preserveState: true, preserveScroll: true, replace: true });
    };
    const abrirChamado = (id) => {
        setAbertaId(null);
        setChamadoId(id);
        router.get(urlCom('ticket', id), {}, { only: ['chamado_detalhe'], preserveState: true, preserveScroll: true, replace: true });
    };
    const fecharChamado = () => {
        setChamadoId(null);
        router.get(urlCom('ticket', null), {}, { only: ['chamado_detalhe'], preserveState: true, preserveScroll: true, replace: true });
    };

    // "Criar demanda a partir deste ticket": o mesmo formulário de demanda, já preenchido.
    // Prioridade, critério de conclusão e prazo ficam para a equipe decidir.
    const origemDoChamado = (c) => ({
        chamadoId: c.id,
        codigo: c.codigo,
        prioridadeSugerida: c.prioridade_sugerida,
        preset: {
            titulo: c.titulo,
            area: c.area ?? '',
            responsavel_id: c.responsavel?.id ?? null,
            data_entrada: c.criado_em.slice(0, 10),
            escopo: [
                c.descricao,
                c.contexto_tentando && `Tentando fazer: ${c.contexto_tentando}`,
                c.contexto_aconteceu && `O que aconteceu: ${c.contexto_aconteceu}`,
                c.contexto_esperado && `O que esperava: ${c.contexto_esperado}`,
            ].filter(Boolean).join('\n\n'),
            observacoes: `Origem: ticket ${c.codigo} (${c.solicitante})`,
        },
    });

    const abertas = painel.abertas;
    const bloqueadas = painel.por_situacao.bloqueado ?? 0;
    const atrasadas = painel.por_situacao.atrasada ?? 0;

    return (
        <AppLayout title="Demandas Dev">
            <div className="mx-auto max-w-[1400px] space-y-5 px-4 py-6 sm:px-6">
                <header className="flex flex-wrap items-end gap-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2.5">
                            <ListChecks size={22} className="text-ecf-yellow" />
                            <h1 className="font-display text-xl font-semibold text-white">Demandas Dev</h1>
                        </div>
                        <p className="mt-1 text-[13px] text-white/50">
                            {abertas} aberta{abertas === 1 ? '' : 's'}
                            {bloqueadas > 0 && <> · <span className="text-orange-400">{bloqueadas} bloqueada{bloqueadas === 1 ? '' : 's'}</span></>}
                            {atrasadas > 0 && <> · <span className="text-red-400">{atrasadas} atrasada{atrasadas === 1 ? '' : 's'}</span></>}
                            {!pode.gerenciar && ' · suas demandas'}
                        </p>
                    </div>
                    {pode.gerenciar && (
                        <button
                            type="button"
                            onClick={() => setEditandoDemanda('nova')}
                            className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3.5 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2"
                        >
                            <Plus size={15} /> Nova demanda
                        </button>
                    )}
                </header>

                <nav className="-mx-4 flex gap-1 overflow-x-auto border-b border-white/[0.06] px-4 sm:mx-0 sm:px-0">
                    {abasVisiveis.map(({ id, rotulo, icone: Icone }) => (
                        <button
                            key={id}
                            type="button"
                            onClick={() => setAba(id)}
                            className={cn(
                                '-mb-px inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-3.5 py-2.5 text-[13px] font-medium transition-colors',
                                aba === id ? 'border-ecf-yellow text-white' : 'border-transparent text-white/50 hover:text-white/80',
                            )}
                        >
                            <Icone size={15} />
                            {rotulo}
                            {id === 'reunioes' && reunioes.length > 0 && <span className="text-[11px] text-white/30">{reunioes.length}</span>}
                            {id === 'tickets' && chamadosAtencao > 0 && (
                                <span className="inline-flex items-center gap-1 text-[11.5px] text-ecf-yellow" title="Tickets que precisam da equipe">
                                    <span className="h-1.5 w-1.5 rounded-full bg-ecf-yellow" />{chamadosAtencao}
                                </span>
                            )}
                        </button>
                    ))}
                </nav>

                {aba === 'fila' && (
                    <Fila demandas={demandas} usuarios={usuarios} eu={eu} pode={pode} hoje={hoje} onAbrir={abrir} onAtualizar={setAtualizando} />
                )}
                {aba === 'tickets' && equipe && <Chamados chamados={chamados} eu={eu} onAbrir={abrirChamado} />}
                {aba === 'demandas' && <ListaDemandas demandas={demandas} pode={pode} hoje={hoje} onAbrir={abrir} />}
                {aba === 'painel' && <Painel demandas={demandas} areas={areas} hoje={hoje} />}
                {aba === 'reunioes' && (
                    <Reunioes
                        reunioes={reunioes}
                        pode={pode}
                        hoje={hoje}
                        onAbrir={abrir}
                        onAgendar={agendarReuniao}
                        onEditar={(reuniao) => setEditandoReuniao({ reuniao })}
                    />
                )}
            </div>

            <DemandaDrawer
                demanda={aberta}
                detalhe={detalhe}
                eu={eu}
                pode={pode}
                hoje={hoje}
                onClose={fechar}
                onAtualizar={setAtualizando}
                onEditar={setEditandoDemanda}
                onAgendarReuniao={pode.gerenciar ? agendarReuniao : null}
                onAbrirChamado={equipe ? abrirChamado : null}
            />

            {equipe && (
                <ChamadoDrawer
                    aberto={!!chamadoId}
                    chamado={chamadoDetalhe?.id === chamadoId ? chamadoDetalhe : null}
                    devs={devs}
                    eu={eu}
                    onClose={fecharChamado}
                    onCriarDemanda={(c) => setConvertendo(c)}
                    onAbrirDemanda={abrir}
                />
            )}
            {convertendo && (
                <DemandaDialog
                    key={`chamado-${convertendo.id}`}
                    origem={origemDoChamado(convertendo)}
                    usuarios={usuarios}
                    areas={areas}
                    prefixos={prefixos}
                    hoje={hoje}
                    onClose={() => setConvertendo(null)}
                />
            )}

            {atualizando && (
                <AtualizacaoDialog key={atualizando.id} demanda={atualizando} hoje={hoje} onClose={() => setAtualizando(null)} />
            )}
            {editandoDemanda && (
                <DemandaDialog
                    key={editandoDemanda === 'nova' ? 'nova' : editandoDemanda.id}
                    demanda={editandoDemanda === 'nova' ? null : editandoDemanda}
                    usuarios={usuarios}
                    areas={areas}
                    prefixos={prefixos}
                    hoje={hoje}
                    onClose={() => setEditandoDemanda(null)}
                />
            )}
            {editandoReuniao && (
                <ReuniaoDialog
                    key={editandoReuniao.reuniao?.id ?? 'nova'}
                    reuniao={editandoReuniao.reuniao ?? null}
                    preset={editandoReuniao.preset ?? null}
                    demandas={demandas}
                    usuarios={usuarios}
                    areas={areas}
                    google={google}
                    hoje={hoje}
                    onClose={() => setEditandoReuniao(null)}
                />
            )}
        </AppLayout>
    );
}
