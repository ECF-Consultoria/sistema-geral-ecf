import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarClock, CalendarPlus, ChevronDown, ChevronRight, Sparkles, Trash2 } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import {
    AvisoFlash, Botao, CabecalhoEstrutura, PainelEstrutura, fmtData, fmtDiaSemana, hojeIso, somarDias,
} from '@/Components/Portal/Estrutura/comum';
import FormAnuncio from '@/Components/Portal/Estrutura/FormAnuncio';
import AgendarDialog from '@/Components/Portal/Estrutura/AgendarDialog';
import PropostaAgenda from '@/Components/Portal/Estrutura/PropostaAgenda';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — visão Agenda ───────────────────────────────────
//
// A aba "Planejamento" da planilha, como TABELA (24/09): Data · SKU · Ação ·
// Clássico · Premium · Catálogo. Seções em cards foram recusadas — "mais
// complexo para o cliente que não sabe usar um sistema". Os três blocos da
// planilha (unitários/combos, kits, combits) continuam fora: só existiam para
// caber SKU1..3 + QTD na grade; fase e composição já estão na oferta.
//
// A ordem da tabela é a da urgência: atrasadas (data em vermelho), hoje,
// próximos dias. As concluídas ficam numa segunda tabela, recolhida.
//
// ### Publicação: concluir É cadastrar o anúncio
// A célula do lado que falta tem "Concluir", que abre o formulário de anúncio
// com o MLB obrigatório. Não há checkbox de "publicado": na planilha, a CB3
// estava OK na agenda e "Publicar" no Mapeamento, e aqui isso não consegue
// acontecer (ADR PORTAL-01).
//
// ### Jardinagem: feito / não feito
// Olhar métricas e ajustar — sem anúncio de onde derivar. A publicação
// concluída que ainda não tem Jardinagem ganha o atalho da regra de ouro.

function CelulaLado({ tipo, anuncios, onConcluir }) {
    const conta = anuncios.filter((a) => a.tipo === tipo && a.status !== 'inativo');

    if (conta.length) {
        return (
            <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-emerald-500/15 px-1.5 py-0.5 text-[12px] text-emerald-300">
                ✓ <span className="font-mono text-emerald-200/70">{conta[0].codigo_mlb ?? 'sem MLB'}</span>
            </span>
        );
    }

    return (
        <button type="button" onClick={onConcluir} data-acao={`concluir-${tipo}`}
            className="whitespace-nowrap rounded-md bg-red-500/15 px-2 py-0.5 text-[12px] font-medium text-red-300 hover:bg-red-500/25">
            Concluir
        </button>
    );
}

function Linha({ item, secao, vocabulario, onConcluir, onJardinagem }) {
    const o = item.oferta;
    const publicacao = item.acao === 'publicacao';

    const marcarJardinagem = (feita) => router.patch(route('portal.auth.estrutura.agenda.jardinagem', item.id), { feita }, { preserveScroll: true, preserveState: true });
    const remover = () => router.delete(route('portal.auth.estrutura.agenda.excluir', item.id), { preserveScroll: true, preserveState: true });
    const remarcar = (data) => data && router.patch(route('portal.auth.estrutura.agenda.remarcar', item.id), { data }, { preserveScroll: true, preserveState: true });

    const td = 'px-3 py-2 align-middle';

    return (
        <tr data-item={item.id} data-secao={secao} data-feita={item.feita ? '1' : '0'}
            className={cn(secao === 'atrasadas' && 'bg-red-500/[0.04]', secao === 'hoje' && 'bg-ecf-yellow/[0.04]')}>
            <td className={cn(td, 'whitespace-nowrap')}>
                <span className={cn('tabular-nums', secao === 'atrasadas' ? 'text-red-300' : secao === 'hoje' ? 'text-ecf-yellow' : 'text-white/80')}>
                    {fmtData(item.data)}
                </span>
                <span className="ml-1.5 text-[11px] text-white/35">{secao === 'hoje' ? 'hoje' : fmtDiaSemana(item.data)}</span>
            </td>
            <td className={td}>
                <span className="font-mono text-white/90">{o.sku}</span>
                {o.nome && <p className="text-[11.5px] text-white/40">{o.nome}</p>}
            </td>
            <td className={cn(td, 'whitespace-nowrap', publicacao ? 'text-sky-300' : 'text-emerald-300')}>{vocabulario.acoes[item.acao]}</td>

            {publicacao ? (
                <>
                    <td className={cn(td, 'text-center')}><CelulaLado tipo="classico" anuncios={o.anuncios} onConcluir={() => onConcluir(o, 'classico')} /></td>
                    <td className={cn(td, 'text-center')}><CelulaLado tipo="premium" anuncios={o.anuncios} onConcluir={() => onConcluir(o, 'premium')} /></td>
                    <td className={cn(td, 'hidden text-center sm:table-cell')}>
                        {o.catalogos > 0
                            ? <span className="rounded-md bg-emerald-500/15 px-1.5 py-0.5 text-[12px] text-emerald-300">{o.catalogos}</span>
                            : <span className="text-white/30">0</span>}
                        {o.kits_virtuais > 0 && <span className="ml-1 rounded bg-violet-500/10 px-1 text-[10.5px] text-violet-300" title="Kit virtual montado">KV</span>}
                    </td>
                </>
            ) : (
                <td className={td} colSpan={3}>
                    <label className="inline-flex items-center gap-2 text-[12.5px] text-white/70">
                        <input type="checkbox" checked={item.feita} onChange={(e) => marcarJardinagem(e.target.checked)} data-acao="jardinagem" />
                        Feita
                    </label>
                </td>
            )}

            <td className={cn(td, 'whitespace-nowrap text-right')}>
                {publicacao && item.feita && ! o.tem_jardinagem && (
                    <button type="button" onClick={() => onJardinagem(o)} data-acao="agendar-jardinagem"
                        className="mr-1 inline-flex items-center gap-1 text-[12px] text-emerald-300 hover:underline">
                        <Sparkles size={12} /> Jardinagem {fmtData(somarDias(hojeIso(), vocabulario.dias_ate_jardinagem))}
                    </button>
                )}
                {! item.feita && (
                    <label className="relative inline-block p-1 text-white/35 hover:text-white cursor-pointer align-middle" title="Remarcar">
                        <CalendarClock size={14} />
                        <input type="date" className="absolute inset-0 opacity-0 cursor-pointer" aria-label="Remarcar"
                            defaultValue={item.data} onChange={(e) => remarcar(e.target.value)} />
                    </label>
                )}
                <button type="button" onClick={remover} className="p-1 text-white/35 hover:text-red-300 align-middle" aria-label="Remover da agenda"><Trash2 size={14} /></button>
            </td>
        </tr>
    );
}

function Tabela({ linhas, vocabulario, onConcluir, onJardinagem, rodape = null, ...props }) {
    const th = 'px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wide text-white/40';

    return (
        <div className="relative overflow-x-auto rounded-xl border border-white/[0.08] bg-ecf-card" {...props}>
            <table className="w-full text-[13px]">
                <thead className="border-b border-white/[0.08]">
                    <tr>
                        <th className={th}>Data</th>
                        <th className={th}>SKU</th>
                        <th className={th}>Ação</th>
                        <th className={cn(th, 'text-center')}>Clássico</th>
                        <th className={cn(th, 'text-center')}>Premium</th>
                        <th className={cn(th, 'hidden text-center sm:table-cell')}>Catálogo</th>
                        <th className={th}><span className="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-white/[0.05]">
                    {linhas.map(({ item, secao }) => (
                        <Linha key={item.id} item={item} secao={secao} vocabulario={vocabulario}
                            onConcluir={onConcluir} onJardinagem={onJardinagem} />
                    ))}
                </tbody>
            </table>
            {rodape && <p className="border-t border-white/[0.05] px-3 py-2 text-[11.5px] text-white/35">{rodape}</p>}
        </div>
    );
}

export default function EstruturaAgenda({ empresa, modulos = [], agenda, vocabulario }) {
    const [concluir, setConcluir] = useState(null);   // { oferta, tipo }
    const [jardinagem, setJardinagem] = useState(null); // oferta
    const [proposta, setProposta] = useState(false);
    const [aula, setAula] = useState(false);
    const [verConcluidas, setVerConcluidas] = useState(false);

    // A faixa "próximo passo" da outra visão manda para cá com `?proposta=1`:
    // chega já com a sugestão de datas aberta, sem mais um clique.
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('proposta') === '1' && agenda.painel.a_publicar > 0) {
            setProposta(true);
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const { secoes } = agenda;
    const abertas = ['atrasadas', 'hoje', 'proximas'].flatMap((s) => secoes[s].itens.map((item) => ({ item, secao: s })));
    const cortadas = ['atrasadas', 'proximas'].reduce((n, s) => n + secoes[s].total - secoes[s].itens.length, 0);
    const concluidas = secoes.concluidas.itens.map((item) => ({ item, secao: 'concluidas' }));

    const aoConcluir = (oferta, tipo) => setConcluir({ oferta, tipo });

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Mapeamento Estrutural · Agenda">
            <div className="max-w-5xl mx-auto px-4 py-6 space-y-4">
                <CabecalhoEstrutura visao="agenda" onComoFunciona={() => setAula(true)} />

                <PainelEstrutura painel={agenda.painel} />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-[12.5px] text-white/45">
                        Ritmo: 1 publicação por dia até zerar a lista. 7 dias depois, a Jardinagem.
                        {secoes.atrasadas.total > 0 && <span className="ml-1 text-red-300">{secoes.atrasadas.total} atrasada(s).</span>}
                    </p>
                    <Botao variante="primario" onClick={() => setProposta(true)} disabled={agenda.painel.a_publicar === 0} data-acao="agendar-o-que-falta">
                        <CalendarPlus size={14} /> Agendar o que falta
                    </Botao>
                </div>

                {abertas.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-white/[0.12] py-10 text-center text-[13px] text-white/45">
                        Nada pendente na agenda. {agenda.painel.a_publicar > 0
                            ? `Há ${agenda.painel.a_publicar} anúncio(s) a publicar — use "Agendar o que falta".`
                            : agenda.painel.ofertas === 0 ? 'Comece listando seus produtos na visão Ofertas.' : 'Tudo publicado.'}
                    </p>
                ) : (
                    <Tabela linhas={abertas} vocabulario={vocabulario} onConcluir={aoConcluir} onJardinagem={setJardinagem}
                        rodape={cortadas > 0 ? `E mais ${cortadas} tarefa(s) mais adiante.` : null} data-tabela-agenda />
                )}

                {secoes.concluidas.total > 0 && (
                    <div className="space-y-2">
                        <button type="button" onClick={() => setVerConcluidas(! verConcluidas)} aria-expanded={verConcluidas}
                            className="inline-flex items-center gap-1.5 text-[13px] text-emerald-300" data-acao="ver-concluidas">
                            {verConcluidas ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                            Concluídas <span className="text-white/35">{secoes.concluidas.total}</span>
                        </button>
                        {verConcluidas && (
                            <Tabela linhas={concluidas} vocabulario={vocabulario} onConcluir={aoConcluir} onJardinagem={setJardinagem}
                                rodape={secoes.concluidas.total > concluidas.length ? `Mostrando as ${concluidas.length} mais recentes.` : null} />
                        )}
                    </div>
                )}
            </div>

            <FormAnuncio
                aberta={!! concluir}
                onFechar={() => setConcluir(null)}
                oferta={concluir?.oferta}
                tipoFixo={concluir?.tipo}
                viaAgenda
                vocabulario={vocabulario}
            />

            <AgendarDialog
                aberta={!! jardinagem}
                onFechar={() => setJardinagem(null)}
                oferta={jardinagem}
                acaoInicial="jardinagem"
                dataInicial={somarDias(hojeIso(), vocabulario.dias_ate_jardinagem)}
                vocabulario={vocabulario}
            />

            <PropostaAgenda aberta={proposta} onFechar={() => setProposta(false)} vocabulario={vocabulario} />

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />

            <AvisoFlash />
        </PortalClienteLayout>
    );
}
