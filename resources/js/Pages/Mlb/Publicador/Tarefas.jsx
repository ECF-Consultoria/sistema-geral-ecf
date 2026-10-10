import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CheckCircle2, ChevronLeft, ChevronRight, Circle, ExternalLink, MinusCircle, Zap } from 'lucide-react';
import { cn } from '@/lib/utils';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { AREA, CAMPO, LINK, SELECT } from '@/Components/Publicador/Mesa/comum';
import {
    ESCOPOS, FILTROS_STATUS, ROTULO_ESTADO_ITEM, ROTULO_SELO, ROTULO_STATUS,
    comoLista, comoObjeto, fmtPrazo, fmtQuando, numeroSeguro, queryDosFiltros, textoSeguro,
} from '@/Components/Mlb/Publicador/tarefasPosPublicacao';

// Fila "Publicados aguardando alavancas" (09/10/2026). Tela GLOBAL do Publicador:
// toda conta numa lista só, sem BarraDaConta. Cada cartão é um produto publicado
// (Clássico e Premium juntos) com o checklist pós-publicação da planilha da ECF.
// Nada de contador "N de 6" (recusado pelo usuário em 04/10): cada item diz o
// próprio estado. Nenhum amarelo sólido: "Concluir" é secundário e só liga com
// tudo resolvido.

const ROTA = 'mlb.anuncios.publicador.tarefas';

const BOTAO_FILTRO = 'inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const FILTRO_ATIVO = 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow';
const FILTRO_INATIVO = 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]';

const COR_SELO = {
    atrasado: 'border-amber-400/40 bg-amber-400/10 text-amber-300',
    hoje: 'border-sky-400/40 bg-sky-400/10 text-sky-200',
    programado: 'border-white/[0.10] bg-white/[0.03] text-white/60',
};

const COR_STATUS = {
    pendente: 'border-white/[0.10] text-white/70',
    em_andamento: 'border-sky-400/30 text-sky-200',
    feita: 'border-emerald-400/30 text-emerald-200',
    cancelada: 'border-white/[0.08] text-white/40',
};

const COR_ITEM = {
    pendente: 'border-white/[0.10] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
    feito: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200 hover:bg-emerald-400/15',
    nao_se_aplica: 'border-white/[0.08] bg-transparent text-white/45 hover:bg-white/[0.04]',
};

const ICONE_ITEM = { pendente: Circle, feito: CheckCircle2, nao_se_aplica: MinusCircle };

// Ações da fila: a página continua montada (o erro de validação chega no `onError` do
// cartão vivo, não num cartão remontado) e a rolagem não pula; as props voltam frescas.
const NA_MESMA_TELA = { preserveScroll: true, preserveState: true };

/** Primeira mensagem de um objeto de erros do Inertia. */
const primeiroErro = (erros) => textoSeguro(Object.values(comoObjeto(erros))[0], 'Não deu para salvar. Tente de novo.');

function visitar(filtros, extra = {}) {
    router.get(route(`${ROTA}.index`), queryDosFiltros({ ...filtros, ...extra }), {
        preserveState: true, preserveScroll: true, replace: true,
    });
}

/** Número do cabeçalho: rótulo, valor e, quando há atraso, o tom âmbar. */
function Numero({ rotulo, valor, alerta = false }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <p className="text-[11px] font-bold text-white/40">{rotulo}</p>
            <p className={cn('mt-1 font-display text-[24px] font-bold tabular-nums', alerta ? 'text-amber-300' : 'text-white')}>
                {valor ?? '—'}
            </p>
        </div>
    );
}

/** "Responsável padrão": só o admin escolhe; vale para as próximas publicações. */
function ResponsavelPadrao({ atual, candidatos, podeConfigurar }) {
    const r = comoObjeto(atual);
    const lista = comoLista(candidatos);
    const [erro, setErro] = useState(null);

    if (! podeConfigurar) {
        return (
            <p className="text-[13px] font-normal text-white/55">
                Responsável padrão: <span className="font-bold text-white/80">{textoSeguro(r.nome, 'fila comum')}</span>
            </p>
        );
    }

    function trocar(valor) {
        setErro(null);
        router.put(route(`${ROTA}.responsavel-padrao`), { responsavel_id: valor === '' ? null : Number(valor) }, {
            ...NA_MESMA_TELA,
            onError: (erros) => setErro(primeiroErro(erros)),
        });
    }

    return (
        <div className="w-full max-w-[320px]">
            <label htmlFor="responsavel-padrao" className="mb-1.5 block text-[13px] font-bold text-white/90">Responsável padrão</label>
            <select
                id="responsavel-padrao"
                value={numeroSeguro(r.id) ?? ''}
                onChange={(ev) => trocar(ev.target.value)}
                className={cn(SELECT, 'h-10 text-[13px]')}
            >
                <option value="">Fila comum (todos com acesso)</option>
                {lista.map((c) => (
                    <option key={textoSeguro(c?.id, '')} value={textoSeguro(c?.id, '')}>{textoSeguro(c?.nome)}</option>
                ))}
            </select>
            <p className="mt-1 text-[11px] font-normal text-white/40">Recebe cada produto publicado daqui em diante.</p>
            {r.invalido && (
                <p className="mt-1 text-[11px] font-normal text-amber-300">
                    O responsável escolhido saiu ou perdeu o acesso: as próximas tarefas vão para a fila comum.
                </p>
            )}
            {erro && <p className="mt-1 text-[11px] font-normal text-red-300">{erro}</p>}
        </div>
    );
}

/** Um item do checklist aberto para marcar: feito, não se aplica (com motivo) ou de volta a pendente. */
function EditorDoItem({ tarefaId, item, onFechar, onErro }) {
    const [motivo, setMotivo] = useState(textoSeguro(item.motivo, ''));
    const [enviando, setEnviando] = useState(false);

    function marcar(estado) {
        setEnviando(true);
        router.put(route(`${ROTA}.marcar`, { tarefa: tarefaId, chave: item.chave }), { estado, motivo: estado === 'nao_se_aplica' ? motivo : null }, {
            ...NA_MESMA_TELA,
            onSuccess: () => onFechar(),
            onError: (erros) => onErro(primeiroErro(erros)),
            onFinish: () => setEnviando(false),
        });
    }

    return (
        <div className="mt-3 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
            <p className="text-[13px] font-bold text-white/90">{textoSeguro(item.rotulo)}</p>
            <div className="mt-3 flex flex-wrap items-end gap-2">
                <BotaoAcao disabled={enviando || item.estado === 'feito'} onClick={() => marcar('feito')}>Feito</BotaoAcao>
                <label className="block min-w-[240px] flex-1">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Motivo, se não se aplica</span>
                    <input
                        type="text"
                        value={motivo}
                        maxLength={300}
                        onChange={(ev) => setMotivo(ev.target.value)}
                        placeholder="Ex.: a loja não tem afiliados"
                        className={cn(CAMPO, 'h-10 text-[13px]')}
                    />
                </label>
                <BotaoAcao disabled={enviando || motivo.trim() === ''} onClick={() => marcar('nao_se_aplica')}>Não se aplica</BotaoAcao>
                {item.estado !== 'pendente' && (
                    <BotaoAcao disabled={enviando} onClick={() => marcar('pendente')}>Voltar a pendente</BotaoAcao>
                )}
                <button type="button" onClick={onFechar} className={cn(LINK, 'h-10')}>Fechar</button>
            </div>
        </div>
    );
}

/** A observação da tarefa: texto livre, vale até depois de concluída. */
function Observacao({ tarefaId, texto, onErro }) {
    const [editando, setEditando] = useState(false);
    const [valor, setValor] = useState(textoSeguro(texto, ''));

    function salvar() {
        router.put(route(`${ROTA}.observacao`, { tarefa: tarefaId }), { observacao: valor }, {
            ...NA_MESMA_TELA,
            onSuccess: () => setEditando(false),
            onError: (erros) => onErro(primeiroErro(erros)),
        });
    }

    if (! editando) {
        return (
            <div className="flex flex-wrap items-baseline gap-2">
                {texto ? (
                    <p className="text-[13px] font-normal text-white/70"><span className="font-bold text-white/55">Observação:</span> {textoSeguro(texto)}</p>
                ) : null}
                <button type="button" onClick={() => { setValor(textoSeguro(texto, '')); setEditando(true); }} className={LINK}>
                    {texto ? 'Editar observação' : 'Escrever observação'}
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <textarea value={valor} maxLength={2000} rows={3} onChange={(ev) => setValor(ev.target.value)} className={cn(AREA, 'text-[13px]')} />
            <div className="flex gap-2">
                <BotaoAcao onClick={salvar}>Salvar observação</BotaoAcao>
                <button type="button" onClick={() => setEditando(false)} className={LINK}>Cancelar</button>
            </div>
        </div>
    );
}

/** Um produto publicado e o que falta fazer nele. */
function CartaoTarefa({ tarefa, destacada, podeEscrever }) {
    const t = comoObjeto(tarefa);
    const empresa = comoObjeto(t.empresa);
    const produto = comoObjeto(t.produto);
    const itens = comoLista(t.itens);
    const checklist = comoLista(t.checklist);
    const aberta = t.status === 'pendente' || t.status === 'em_andamento';
    const [aberto, setAberto] = useState(null);
    const [erro, setErro] = useState(null);
    const itemAberto = checklist.find((i) => comoObjeto(i).chave === aberto) ?? null;

    function pegar() {
        setErro(null);
        router.post(route(`${ROTA}.pegar`, { tarefa: t.id }), {}, { ...NA_MESMA_TELA, onError: (erros) => setErro(primeiroErro(erros)) });
    }

    function concluir() {
        setErro(null);
        router.post(route(`${ROTA}.concluir`, { tarefa: t.id }), {}, { ...NA_MESMA_TELA, onError: (erros) => setErro(primeiroErro(erros)) });
    }

    return (
        <article
            id={`tarefa-${textoSeguro(t.id, '')}`}
            className={cn('rounded-xl border bg-ecf-card p-5', destacada ? 'border-ecf-yellow/50 ring-2 ring-ecf-yellow/30' : 'border-white/[0.08]')}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[15px] font-bold text-white">{textoSeguro(empresa.nome)}</p>
                    <p className="text-[13px] font-normal text-white/70">
                        {textoSeguro(produto.nome)}
                        {produto.sku ? <span className="ml-2 font-mono text-[11px] text-white/40">{textoSeguro(produto.sku)}</span> : null}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {t.selo ? (
                        <span className={cn('rounded-md border px-2 py-0.5 text-[11px] font-bold', COR_SELO[t.selo] ?? COR_SELO.programado)}>
                            {ROTULO_SELO[t.selo] ?? textoSeguro(t.selo)}{t.selo === 'programado' ? ` · ${fmtPrazo(t.prazo)}` : ''}
                        </span>
                    ) : null}
                    <span className={cn('rounded-md border px-2 py-0.5 text-[11px] font-bold', COR_STATUS[t.status] ?? COR_STATUS.pendente)}>
                        {ROTULO_STATUS[t.status] ?? textoSeguro(t.status)}
                    </span>
                </div>
            </div>

            <ul className="mt-3 flex flex-wrap gap-x-6 gap-y-2" aria-label="Anúncios publicados">
                {itens.map((i) => (
                    <li key={textoSeguro(i?.ml_item_id, '')} className="flex flex-wrap items-center gap-2 text-[13px] font-normal text-white/70">
                        <span className="font-bold text-white/55">{textoSeguro(i?.tipo, '')}</span>
                        {i?.permalink ? (
                            <a href={i.permalink} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-mono text-[13px] text-white/85 hover:text-ecf-yellow">
                                {textoSeguro(i?.ml_item_id)} <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                            </a>
                        ) : (
                            <span className="font-mono text-[13px] text-white/85">{textoSeguro(i?.ml_item_id)}</span>
                        )}
                        {i?.url_alavancas ? (
                            <Link href={i.url_alavancas} className={LINK}>Abrir Alavancas</Link>
                        ) : null}
                    </li>
                ))}
            </ul>

            <p className="mt-2 text-[11px] font-normal text-white/40">
                Publicado por {textoSeguro(t.publicado_por, 'equipe')} em {fmtQuando(t.publicado_em)} · prazo {fmtPrazo(t.prazo)} · responsável{' '}
                <span className="font-bold text-white/60">{textoSeguro(comoObjeto(t.responsavel).nome, 'fila comum')}</span>
            </p>

            {! t.liberada && aberta && (
                <p className="mt-2 text-[13px] font-normal text-white/55">
                    Esta conta não escreve pelo sistema: faça no Seller Center e marque aqui.
                </p>
            )}

            <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="Checklist pós-publicação">
                {checklist.map((i) => {
                    const item = comoObjeto(i);
                    const Icone = ICONE_ITEM[item.estado] ?? Circle;
                    const detalhe = item.estado === 'nao_se_aplica'
                        ? `Não se aplica: ${textoSeguro(item.motivo, '')}`
                        : item.estado === 'feito'
                            ? `Feito${item.automatico ? ' pelo sistema' : ''}${item.por ? ` por ${textoSeguro(item.por, '')}` : ''}`
                            : 'Pendente';

                    return (
                        <button
                            key={textoSeguro(item.chave, '')}
                            type="button"
                            disabled={! (t.status === 'pendente' || t.status === 'em_andamento')}
                            aria-expanded={aberto === item.chave}
                            title={detalhe}
                            onClick={() => setAberto((a) => (a === item.chave ? null : item.chave))}
                            className={cn(
                                'inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:cursor-default',
                                COR_ITEM[item.estado] ?? COR_ITEM.pendente,
                                item.estado === 'nao_se_aplica' && 'line-through',
                            )}
                        >
                            <Icone className="h-4 w-4 shrink-0" aria-hidden="true" />
                            {textoSeguro(item.rotulo)}
                            {item.estado === 'feito' && item.automatico === true && (
                                <span className="text-[11px] font-normal text-emerald-300/70">pelo sistema</span>
                            )}
                            <span className="sr-only"> — {ROTULO_ESTADO_ITEM[item.estado] ?? ''}</span>
                        </button>
                    );
                })}
            </div>

            {itemAberto && aberta && (
                <EditorDoItem key={itemAberto.chave} tarefaId={t.id} item={comoObjeto(itemAberto)} onFechar={() => setAberto(null)} onErro={setErro} />
            )}

            <div className="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-white/[0.06] pt-3">
                <div className="min-w-0 flex-1">
                    <Observacao key={textoSeguro(t.observacao, '')} tarefaId={t.id} texto={t.observacao} onErro={setErro} />
                </div>
                {aberta && (
                    <div className="flex flex-wrap gap-2">
                        {! t.minha && <BotaoAcao onClick={pegar}>Pegar</BotaoAcao>}
                        <BotaoAcao
                            disabled={! t.resolvida}
                            title={t.resolvida ? undefined : 'Marque cada item como feito ou "não se aplica" para concluir.'}
                            onClick={concluir}
                        >
                            Concluir
                        </BotaoAcao>
                    </div>
                )}
                {! aberta && t.concluida_em && (
                    <p className="text-[11px] font-normal text-white/40">Concluída em {fmtQuando(t.concluida_em)}</p>
                )}
            </div>

            {erro && <p className="mt-2 text-[13px] font-normal text-red-300">{erro}</p>}
            {! podeEscrever && aberta && itens.length > 0 && (
                <p className="mt-2 text-[11px] font-normal text-white/40">As alavancas pelo sistema são abertas por um administrador; você pode marcar o que fez no Seller Center.</p>
            )}
        </article>
    );
}

/**
 * Página da fila. Filtros vivem na URL (router.get com preserveState), como na
 * entrada do Publicador. `tarefa_destacada` (vinda do sino) ganha a moldura e
 * rola para a vista.
 */
export default function Tarefas(props) {
    const lista = comoLista(props.tarefas);
    const filtros = comoObjeto(props.filtros);
    const contagens = comoObjeto(props.contagens);
    const paginacao = comoObjeto(props.paginacao);
    const destacadaId = numeroSeguro(props.tarefa_destacada);
    const podeEscrever = props.pode_escrever === true;
    const escopo = textoSeguro(filtros.escopo, 'todas');
    const status = textoSeguro(filtros.status, 'abertas');
    const conta = typeof filtros.conta === 'string' ? filtros.conta : null;
    const pagina = numeroSeguro(paginacao.pagina) ?? 1;
    const ultima = numeroSeguro(paginacao.ultima) ?? 1;
    const atuais = { escopo, status, conta };

    // Calculado AQUI, não dentro do .map() do JSX (armadilha do Rollup deste projeto):
    // o callback do JSX só lê o próprio item.
    const cartoes = lista.map((t) => ({ tarefa: t, destacada: destacadaId !== null && comoObjeto(t).id === destacadaId, podeEscrever }));

    useEffect(() => {
        if (destacadaId === null) return;
        document.getElementById(`tarefa-${destacadaId}`)?.scrollIntoView({ block: 'center' });
    }, [destacadaId]);

    return (
        <AppLayout title="Publicados aguardando alavancas">
            <div className="mx-auto max-w-[1240px] px-8 py-8">
                {podeEscrever && (
                    <nav aria-label="Trilha" className="mb-2 text-[13px] font-normal text-white/55">
                        <Link href={route('mlb.anuncios.index')} className="hover:text-ecf-yellow">Publicador</Link>
                        <span aria-hidden="true"> › </span>
                        <span className="text-white/70">Publicados aguardando alavancas</span>
                    </nav>
                )}

                <div className="mb-8 flex flex-wrap items-start justify-between gap-6">
                    <div className="flex items-start gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12]">
                            <Zap className="h-[18px] w-[18px] text-ecf-yellow" aria-hidden="true" />
                        </div>
                        <div className="max-w-[640px]">
                            <h1 className="font-display text-[24px] font-bold leading-tight text-white">Publicados aguardando alavancas</h1>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                Cada produto publicado pelo Publicador vira uma tarefa: Central de Promoções, ADS de lançamento, atacado,
                                cupom, afiliados e lista de transmissão. O prazo é o próximo dia útil depois da publicação.
                            </p>
                        </div>
                    </div>
                    <ResponsavelPadrao atual={props.responsavel_padrao} candidatos={props.candidatos} podeConfigurar={props.pode_configurar === true} />
                </div>

                <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Numero rotulo="Abertas" valor={numeroSeguro(contagens.abertas)} />
                    <Numero rotulo="Minhas" valor={numeroSeguro(contagens.minhas)} />
                    <Numero rotulo="Vencem hoje" valor={numeroSeguro(contagens.hoje)} />
                    <Numero rotulo="Atrasadas" valor={numeroSeguro(contagens.atrasadas)} alerta={(numeroSeguro(contagens.atrasadas) ?? 0) > 0} />
                </div>

                <div className="mb-6 flex flex-wrap items-center gap-4 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                    <div className="flex flex-wrap gap-2" role="group" aria-label="De quem">
                        {ESCOPOS.map((e) => (
                            <button
                                key={e.chave}
                                type="button"
                                aria-pressed={escopo === e.chave}
                                onClick={() => visitar(atuais, { escopo: e.chave, pagina: 1 })}
                                className={cn(BOTAO_FILTRO, escopo === e.chave ? FILTRO_ATIVO : FILTRO_INATIVO)}
                            >
                                {e.rotulo}
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2" role="group" aria-label="Situação">
                        {FILTROS_STATUS.map((f) => (
                            <button
                                key={f.chave}
                                type="button"
                                aria-pressed={status === f.chave}
                                onClick={() => visitar(atuais, { status: f.chave, pagina: 1 })}
                                className={cn(BOTAO_FILTRO, status === f.chave ? FILTRO_ATIVO : FILTRO_INATIVO)}
                            >
                                {f.rotulo}
                            </button>
                        ))}
                    </div>
                    {conta && (
                        <p className="text-[13px] font-normal text-white/70">
                            Conta: <span className="font-bold">{textoSeguro(filtros.conta_nome, conta)}</span>{' '}
                            <button type="button" onClick={() => visitar({ escopo, status, conta: null })} className={LINK}>ver todas as contas</button>
                        </p>
                    )}
                </div>

                <section aria-label="Tarefas" className="space-y-4">
                    {lista.length === 0 ? (
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-6">
                            <p className="text-[15px] font-bold text-white">Nada aguardando alavancas aqui.</p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                Quando um produto for publicado pelo Publicador, a tarefa aparece nesta fila.
                            </p>
                        </div>
                    ) : cartoes.map((c) => (
                        <CartaoTarefa key={textoSeguro(comoObjeto(c.tarefa).id, '')} tarefa={c.tarefa} destacada={c.destacada} podeEscrever={c.podeEscrever} />
                    ))}
                </section>

                {ultima > 1 && (
                    <div className="mt-6 flex flex-wrap items-center gap-3">
                        <BotaoAcao disabled={pagina <= 1} onClick={() => visitar(atuais, { pagina: pagina - 1 })}>
                            <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
                        </BotaoAcao>
                        <span className="text-[13px] font-normal text-white/55">Página {pagina} de {ultima}</span>
                        <BotaoAcao disabled={pagina >= ultima} onClick={() => visitar(atuais, { pagina: pagina + 1 })}>
                            Próxima <ChevronRight className="h-4 w-4" aria-hidden="true" />
                        </BotaoAcao>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
