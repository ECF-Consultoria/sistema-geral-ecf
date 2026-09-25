import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarClock, CalendarPlus, ChevronDown, ChevronRight, Sparkles, Trash2 } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import {
    AvisoFlash, Botao, CabecalhoEstrutura, Indicadores, LinkMl, PainelEstrutura, fmtData, fmtDiaSemana, hojeIso, somarDias,
} from '@/Components/Portal/Estrutura/comum';
import FormAnuncio from '@/Components/Portal/Estrutura/FormAnuncio';
import AgendarDialog from '@/Components/Portal/Estrutura/AgendarDialog';
import PropostaAgenda from '@/Components/Portal/Estrutura/PropostaAgenda';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — visão Agenda ───────────────────────────────────
//
// A aba "Planejamento" da planilha, sem os três blocos (unitários/combos,
// kits, combits): eles só existiam para caber SKU1..3 + QTD na grade.
//
// ### Publicação: concluir É cadastrar o anúncio
// Cada publicação mostra os dois lados. O lado que falta tem "Concluir", que
// abre o formulário de anúncio com o MLB obrigatório. Não há checkbox de
// "publicado": na planilha, a CB3 estava OK na agenda e "Publicar" no
// Mapeamento, e aqui isso não consegue acontecer (ADR PORTAL-01).
//
// ### Jardinagem: feito / não feito
// Olhar métricas e ajustar — sem anúncio de onde derivar.
//
// A publicação concluída que ainda não tem Jardinagem ganha o atalho da regra
// de ouro: "7 dias depois, agende a Jardinagem".

const SECOES = [
    { chave: 'atrasadas',  rotulo: 'Atrasadas',  cor: 'text-red-300' },
    { chave: 'hoje',       rotulo: 'Hoje',       cor: 'text-ecf-yellow' },
    { chave: 'proximas',   rotulo: 'Próximos dias', cor: 'text-white/70' },
    { chave: 'concluidas', rotulo: 'Concluídas', cor: 'text-emerald-300', recolhida: true },
];

function LadoDaPublicacao({ tipo, rotulo, anuncios, onConcluir }) {
    const conta = anuncios.filter((a) => a.status !== 'inativo');

    if (conta.length) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/25 bg-emerald-500/[0.07] px-2 py-1 text-[12px] text-emerald-300">
                ✓ {rotulo}
                {conta[0].codigo_mlb
                    ? <LinkMl mlb={conta[0].codigo_mlb} className="text-emerald-200/60" />
                    : <span className="font-mono text-emerald-200/60">sem MLB</span>}
            </span>
        );
    }

    return (
        <Botao className="px-2 py-1 text-[12px]" onClick={onConcluir} data-acao={`concluir-${tipo}`}>
            {rotulo}: concluir
        </Botao>
    );
}

function Item({ item, vocabulario, onConcluir, onJardinagem }) {
    const o = item.oferta;
    const publicacao = item.acao === 'publicacao';
    const classicos = o.anuncios.filter((a) => a.tipo === 'classico');
    const premiums = o.anuncios.filter((a) => a.tipo === 'premium');

    const marcarJardinagem = (feita) => router.patch(route('portal.auth.estrutura.agenda.jardinagem', item.id), { feita }, { preserveScroll: true, preserveState: true });
    const remover = () => router.delete(route('portal.auth.estrutura.agenda.excluir', item.id), { preserveScroll: true, preserveState: true });
    const remarcar = (data) => data && router.patch(route('portal.auth.estrutura.agenda.remarcar', item.id), { data }, { preserveScroll: true, preserveState: true });

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-3" data-item={item.id} data-feita={item.feita ? '1' : '0'}>
            <div className="w-20 shrink-0">
                <p className="text-[13px] text-white">{fmtData(item.data)}</p>
                <p className="text-[11px] text-white/40">{fmtDiaSemana(item.data)}</p>
            </div>
            <div className="min-w-0 flex-1 basis-48">
                <p className="text-[13px]">
                    <span className={cn('mr-1.5 text-[11.5px] font-semibold', publicacao ? 'text-sky-300' : 'text-emerald-300')}>
                        {vocabulario.acoes[item.acao]}
                    </span>
                    <span className="font-mono text-white/90">{o.sku}</span>
                </p>
                {o.nome && <p className="text-[12px] text-white/45 truncate">{o.nome}</p>}
            </div>

            {publicacao ? (
                <div className="flex flex-wrap items-center gap-2">
                    <LadoDaPublicacao tipo="classico" rotulo={vocabulario.tipos_curtos.classico} anuncios={classicos} onConcluir={() => onConcluir(o, 'classico')} />
                    <LadoDaPublicacao tipo="premium" rotulo={vocabulario.tipos_curtos.premium} anuncios={premiums} onConcluir={() => onConcluir(o, 'premium')} />
                    <Indicadores catalogos={o.catalogos} kitsVirtuais={o.kits_virtuais} />
                </div>
            ) : (
                <label className="inline-flex items-center gap-2 text-[13px] text-white/75">
                    <input type="checkbox" checked={item.feita} onChange={(e) => marcarJardinagem(e.target.checked)} data-acao="jardinagem" />
                    Feita — métricas olhadas e anúncio ajustado
                </label>
            )}

            <div className="ml-auto flex items-center gap-1">
                {publicacao && item.feita && ! o.tem_jardinagem && (
                    <Botao variante="fantasma" className="px-2 py-1 text-[12px] text-emerald-300"
                        onClick={() => onJardinagem(o)} data-acao="agendar-jardinagem">
                        <Sparkles size={13} /> Jardinagem em {fmtData(somarDias(hojeIso(), vocabulario.dias_ate_jardinagem))}
                    </Botao>
                )}
                {! item.feita && (
                    <label className="relative p-1 text-white/35 hover:text-white cursor-pointer" title="Remarcar">
                        <CalendarClock size={14} />
                        <input type="date" className="absolute inset-0 opacity-0 cursor-pointer" aria-label="Remarcar"
                            defaultValue={item.data} onChange={(e) => remarcar(e.target.value)} />
                    </label>
                )}
                <button type="button" onClick={remover} className="p-1 text-white/35 hover:text-red-300" aria-label="Remover da agenda"><Trash2 size={14} /></button>
            </div>
        </li>
    );
}

export default function EstruturaAgenda({ empresa, modulos = [], agenda, vocabulario }) {
    const [concluir, setConcluir] = useState(null);   // { oferta, tipo }
    const [jardinagem, setJardinagem] = useState(null); // oferta
    const [proposta, setProposta] = useState(false);
    const [aula, setAula] = useState(false);
    const [abertas, setAbertas] = useState({ concluidas: false });

    // A faixa "próximo passo" da outra visão manda para cá com `?proposta=1`:
    // chega já com a sugestão de datas aberta, sem mais um clique.
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('proposta') === '1' && agenda.painel.a_publicar > 0) {
            setProposta(true);
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const vazia = SECOES.every((s) => agenda.secoes[s.chave].total === 0);

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Mapeamento Estrutural · Agenda">
            <div className="max-w-5xl mx-auto px-4 py-6 space-y-4">
                <CabecalhoEstrutura visao="agenda" onComoFunciona={() => setAula(true)} />

                <PainelEstrutura painel={agenda.painel} />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-[12.5px] text-white/45">
                        Ritmo: 1 publicação por dia até zerar a lista. 7 dias depois, a Jardinagem.
                    </p>
                    <Botao variante="primario" onClick={() => setProposta(true)} disabled={agenda.painel.a_publicar === 0} data-acao="agendar-o-que-falta">
                        <CalendarPlus size={14} /> Agendar o que falta
                    </Botao>
                </div>

                {vazia && (
                    <p className="rounded-2xl border border-dashed border-white/[0.12] py-10 text-center text-[13px] text-white/45">
                        Nada agendado ainda. {agenda.painel.a_publicar > 0
                            ? `Há ${agenda.painel.a_publicar} anúncio(s) a publicar — use "Agendar o que falta".`
                            : agenda.painel.ofertas === 0 ? 'Comece listando seus produtos na visão Ofertas.' : 'Tudo publicado.'}
                    </p>
                )}

                {SECOES.map((s) => {
                    const secao = agenda.secoes[s.chave];
                    if (secao.total === 0) return null;
                    const aberta = s.recolhida ? abertas[s.chave] : true;

                    return (
                        <section key={s.chave} className="rounded-2xl border border-white/[0.08] bg-ecf-card" data-secao={s.chave}>
                            <button type="button" disabled={! s.recolhida}
                                onClick={() => setAbertas((a) => ({ ...a, [s.chave]: ! a[s.chave] }))}
                                className="flex w-full items-center gap-2 px-4 py-3 text-left">
                                {s.recolhida && (aberta ? <ChevronDown size={14} className="text-white/40" /> : <ChevronRight size={14} className="text-white/40" />)}
                                <h2 className={cn('text-[13px] font-semibold', s.cor)}>{s.rotulo}</h2>
                                <span className="text-[12px] text-white/35">{secao.total}</span>
                            </button>
                            {aberta && (
                                <>
                                    <ul className="divide-y divide-white/[0.05] border-t border-white/[0.06]">
                                        {secao.itens.map((item) => (
                                            <Item key={item.id} item={item} vocabulario={vocabulario}
                                                onConcluir={(oferta, tipo) => setConcluir({ oferta, tipo })}
                                                onJardinagem={setJardinagem} />
                                        ))}
                                    </ul>
                                    {secao.total > secao.itens.length && (
                                        <p className="px-4 py-2 text-[11.5px] text-white/35">
                                            Mostrando {secao.itens.length} de {secao.total}.
                                        </p>
                                    )}
                                </>
                            )}
                        </section>
                    );
                })}
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
