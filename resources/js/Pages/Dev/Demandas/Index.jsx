import { useState } from 'react';
import { router } from '@inertiajs/react';
import { BarChart3, ListChecks, ListOrdered, Plus, Video } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import Fila from '@/Components/DemandasDev/Fila';
import ListaDemandas from '@/Components/DemandasDev/ListaDemandas';
import Painel from '@/Components/DemandasDev/Painel';
import Reunioes from '@/Components/DemandasDev/Reunioes';
import DemandaDrawer from '@/Components/DemandasDev/DemandaDrawer';
import { AtualizacaoDialog, DemandaDialog, ReuniaoDialog } from '@/Components/DemandasDev/Formularios';

const ABAS = [
    { id: 'fila',     rotulo: 'Fila',     icone: ListOrdered },
    { id: 'demandas', rotulo: 'Demandas', icone: ListChecks },
    { id: 'painel',   rotulo: 'Painel',   icone: BarChart3 },
    { id: 'reunioes', rotulo: 'Reuniões', icone: Video },
];

// URL atual com o parâmetro `demanda` trocado (ou removido) — o histórico vem por reload parcial.
const urlComDemanda = (id) => {
    const u = new URL(window.location.href);
    if (id) u.searchParams.set('demanda', id);
    else u.searchParams.delete('demanda');
    return u.pathname + u.search;
};

/**
 * Demandas Dev — tarefas do time de desenvolvimento.
 *
 * Mesmo mecanismo da planilha de gestão: a demanda é a fotografia atual e o
 * andamento cresce no diário de atualizações; status, próxima ação e bloqueio
 * saem da atualização mais recente.
 */
export default function DemandasDevIndex({ demandas, painel, reunioes, usuarios, areas, prefixos, pode, eu, hoje, detalhe }) {
    const [aba, setAba] = useState(eu.tem_demandas ? 'fila' : 'demandas');
    const [abertaId, setAbertaId] = useState(detalhe?.id ?? null);
    const [atualizando, setAtualizando] = useState(null);
    const [editandoDemanda, setEditandoDemanda] = useState(null); // null | 'nova' | demanda
    const [editandoReuniao, setEditandoReuniao] = useState(null); // null | 'nova' | reuniao

    const aberta = abertaId ? demandas.find((d) => d.id === abertaId) : null;

    const abrir = (id) => {
        setAbertaId(id);
        router.get(urlComDemanda(id), {}, { only: ['detalhe'], preserveState: true, preserveScroll: true, replace: true });
    };
    const fechar = () => {
        setAbertaId(null);
        router.get(urlComDemanda(null), {}, { only: ['detalhe'], preserveState: true, preserveScroll: true, replace: true });
    };

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
                    {ABAS.map(({ id, rotulo, icone: Icone }) => (
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
                        </button>
                    ))}
                </nav>

                {aba === 'fila' && (
                    <Fila demandas={demandas} usuarios={usuarios} eu={eu} pode={pode} hoje={hoje} onAbrir={abrir} onAtualizar={setAtualizando} />
                )}
                {aba === 'demandas' && <ListaDemandas demandas={demandas} pode={pode} hoje={hoje} onAbrir={abrir} />}
                {aba === 'painel' && <Painel demandas={demandas} hoje={hoje} onAbrir={abrir} />}
                {aba === 'reunioes' && (
                    <Reunioes
                        reunioes={reunioes}
                        pode={pode}
                        hoje={hoje}
                        onAbrir={abrir}
                        onNova={() => setEditandoReuniao('nova')}
                        onEditar={setEditandoReuniao}
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
            />

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
                    key={editandoReuniao === 'nova' ? 'nova' : editandoReuniao.id}
                    reuniao={editandoReuniao === 'nova' ? null : editandoReuniao}
                    demandas={demandas}
                    hoje={hoje}
                    onClose={() => setEditandoReuniao(null)}
                />
            )}
        </AppLayout>
    );
}
