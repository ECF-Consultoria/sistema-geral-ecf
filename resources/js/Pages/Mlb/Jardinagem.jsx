import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CheckCircle2, ChevronLeft, ChevronRight, Circle, Copy, ExternalLink, MinusCircle, Sprout } from 'lucide-react';
import { cn } from '@/lib/utils';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { AREA, CAMPO, LINK } from '@/Components/Publicador/Mesa/comum';
import {
    FILTROS_STATUS, ROTULO_ESTADO_ITEM, ROTULO_STATUS,
    comoLista, comoObjeto, fmtConversao, fmtDia, fmtDiaMais, fmtInteiro, fmtQuando, numeroSeguro, queryDosFiltros, textoSeguro,
} from '@/Components/Mlb/Publicador/jardinagem';

// Jardinagem dos publicados (23/10/2026) — "fazer que nem a MAG". Análise mensal de
// cada anúncio criado pelo Publicador, pela árvore da aula de 27/11/2025: visitas e
// vendas do mês → um dos quatro ramos → o checklist do que fazer. Tela GLOBAL, como
// a fila das alavancas. Nada aqui escreve no Mercado Livre: a análise é o comando
// diário, e excluir/criar a nova oferta ainda é a equipe quem faz. Nenhum amarelo
// sólido; "Concluir" só liga com tudo resolvido.

const ROTA = 'mlb.anuncios.publicador.jardinagem';

const BOTAO_FILTRO = 'inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const FILTRO_ATIVO = 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow';
const FILTRO_INATIVO = 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]';

const COR_DIAGNOSTICO = {
    sem_tracao: 'border-amber-400/40 bg-amber-400/10 text-amber-300',
    visita_sem_venda: 'border-sky-400/40 bg-sky-400/10 text-sky-200',
    conversao_baixa: 'border-violet-400/40 bg-violet-400/10 text-violet-200',
    conversao_boa: 'border-emerald-400/40 bg-emerald-400/10 text-emerald-200',
};

const COR_STATUS = {
    pendente: 'border-white/[0.10] text-white/70',
    em_andamento: 'border-sky-400/30 text-sky-200',
    feita: 'border-emerald-400/30 text-emerald-200',
    substituida: 'border-white/[0.08] text-white/40',
};

const COR_ITEM = {
    pendente: 'border-white/[0.10] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
    feito: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200 hover:bg-emerald-400/15',
    nao_se_aplica: 'border-white/[0.08] bg-transparent text-white/45 hover:bg-white/[0.04]',
};

const ICONE_ITEM = { pendente: Circle, feito: CheckCircle2, nao_se_aplica: MinusCircle };

// Ações da fila: a página continua montada (o erro chega no `onError` do cartão vivo)
// e a rolagem não pula; as props voltam frescas.
const NA_MESMA_TELA = { preserveScroll: true, preserveState: true };

/** Enquanto algum título novo está sendo gerado, a tela relê a lista a cada tanto. */
const RELEITURA_MS = 6000;

const primeiroErro = (erros) => textoSeguro(Object.values(comoObjeto(erros))[0], 'Não deu para salvar. Tente de novo.');

function visitar(filtros, extra = {}) {
    router.get(route(`${ROTA}.index`), queryDosFiltros({ ...filtros, ...extra }), {
        preserveState: true, preserveScroll: true, replace: true,
    });
}

/** Um ramo da árvore: o que ele quer dizer, o que fazer, e quantos estão abertos nele. É também o filtro. */
function CartaoDoRamo({ ramo, valor, ativo, onClick }) {
    const r = comoObjeto(ramo);

    return (
        <button
            type="button"
            aria-pressed={ativo}
            onClick={onClick}
            className={cn(
                'rounded-xl border bg-ecf-card p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                ativo ? 'border-ecf-yellow/40' : 'border-white/[0.08] hover:border-white/[0.16]',
            )}
        >
            <span className={cn('inline-flex rounded-md border px-2 py-0.5 text-[11px] font-bold', COR_DIAGNOSTICO[r.chave] ?? COR_STATUS.pendente)}>
                {textoSeguro(r.rotulo)}
            </span>
            <span className="mt-2 block font-display text-[24px] font-bold tabular-nums text-white">{fmtInteiro(valor)}</span>
            <span className="mt-1 block text-[13px] font-normal text-white/70">{textoSeguro(r.regra, '')}</span>
            <span className="mt-1 block text-[11px] font-bold text-white/45">{textoSeguro(r.acao, '')}</span>
        </button>
    );
}

/** Um item do checklist aberto para marcar: feito, não se aplica (com motivo) ou de volta a pendente. */
function EditorDoItem({ jardinagemId, item, onFechar, onErro }) {
    const [motivo, setMotivo] = useState(textoSeguro(item.motivo, ''));
    const [enviando, setEnviando] = useState(false);

    function marcar(estado) {
        setEnviando(true);
        router.put(route(`${ROTA}.marcar`, { jardinagem: jardinagemId, chave: item.chave }), { estado, motivo: estado === 'nao_se_aplica' ? motivo : null }, {
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
                        placeholder="Ex.: as vendas não se repetem, não é curva A"
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

/** A observação do mês: texto livre, vale até depois de concluída. */
function Observacao({ jardinagemId, texto, onErro }) {
    const [editando, setEditando] = useState(false);
    const [valor, setValor] = useState(textoSeguro(texto, ''));

    function salvar() {
        router.put(route(`${ROTA}.observacao`, { jardinagem: jardinagemId }), { observacao: valor }, {
            ...NA_MESMA_TELA,
            onSuccess: () => setEditando(false),
            onError: (erros) => onErro(primeiroErro(erros)),
        });
    }

    if (! editando) {
        return (
            <div className="flex flex-wrap items-baseline gap-2">
                {typeof texto === 'string' && texto !== '' ? (
                    <p className="text-[13px] font-normal text-white/70"><span className="font-bold text-white/55">Observação:</span> {texto}</p>
                ) : null}
                <button type="button" onClick={() => { setValor(textoSeguro(texto, '')); setEditando(true); }} className={LINK}>
                    {typeof texto === 'string' && texto !== '' ? 'Editar observação' : 'Escrever observação'}
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

/**
 * O título da nova oferta (só no anúncio sem tração): o de agora, o sugerido com os termos da
 * semana e quais termos são novos. É sugestão: quem cria a nova oferta é a equipe.
 */
export function PropostaDeTitulo({ jardinagemId, proposta, aberta, onErro }) {
    const p = comoObjeto(proposta);
    const termos = comoLista(p.termos_novos).filter((t) => typeof t === 'string');
    const [copiado, setCopiado] = useState(false);
    const [pedindo, setPedindo] = useState(false);
    const novo = typeof p.titulo_novo === 'string' && p.titulo_novo !== '' ? p.titulo_novo : null;

    function outra() {
        setPedindo(true);
        router.post(route(`${ROTA}.proposta`, { jardinagem: jardinagemId }), {}, {
            ...NA_MESMA_TELA,
            onError: (erros) => onErro(primeiroErro(erros)),
            onFinish: () => setPedindo(false),
        });
    }

    async function copiar() {
        try {
            await navigator.clipboard.writeText(novo ?? '');
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        } catch {
            onErro('Não deu para copiar. Selecione o título e copie à mão.');
        }
    }

    return (
        <div className="mt-4 rounded-lg border border-white/[0.08] bg-white/[0.02] p-4" data-proposta={textoSeguro(p.estado, '')}>
            <p className="text-[11px] font-bold text-white/55">Título para a nova oferta · termos mais buscados desta semana</p>
            <p className="mt-2 text-[13px] font-normal text-white/55">
                Agora: <span className="text-white/80">{textoSeguro(p.titulo_atual, '—')}</span>
            </p>

            {p.estado === 'gerando' && (
                <p className="mt-2 text-[13px] font-normal text-sky-200">Gerando o título com os termos da semana…</p>
            )}
            {p.estado === 'erro' && (
                <p className="mt-2 text-[13px] font-normal text-amber-300">{textoSeguro(p.erro, 'A IA não gerou o título.')}</p>
            )}
            {p.estado === 'pronta' && novo && (
                <div className="mt-2 flex flex-wrap items-center gap-3">
                    <p className="text-[15px] font-bold text-white">{novo}</p>
                    <button type="button" onClick={copiar} className={LINK}>
                        <Copy className="h-3.5 w-3.5" aria-hidden="true" /> {copiado ? 'Copiado' : 'Copiar'}
                    </button>
                </div>
            )}

            {termos.length > 0 && (
                <div className="mt-3">
                    <p className="text-[11px] font-normal text-white/45">Termos da semana que o título de agora não tem:</p>
                    <ul className="mt-1.5 flex flex-wrap gap-1.5">
                        {termos.map((t) => (
                            <li key={t} className="rounded-md border border-white/[0.10] bg-white/[0.03] px-2 py-0.5 text-[11px] font-normal text-white/70">{t}</li>
                        ))}
                    </ul>
                </div>
            )}

            {aberta && p.estado !== 'gerando' && (
                <div className="mt-3">
                    <BotaoAcao disabled={pedindo} onClick={outra}>{p.estado === 'erro' ? 'Tentar de novo' : 'Gerar outro título'}</BotaoAcao>
                </div>
            )}
        </div>
    );
}

/** Um mês de um anúncio: os números, o ramo da árvore e o que fazer. */
export function CartaoJardinagem({ linha, ramo }) {
    const j = comoObjeto(linha);
    const r = comoObjeto(ramo);
    const empresa = comoObjeto(j.empresa);
    const produto = comoObjeto(j.produto);
    const janela = comoObjeto(j.janela);
    const checklist = comoLista(j.checklist).map((i) => comoObjeto(i));
    const aberta = j.status === 'pendente' || j.status === 'em_andamento';
    const [aberto, setAberto] = useState(null);
    const [erro, setErro] = useState(null);
    const itemAberto = checklist.find((i) => i.chave === aberto) ?? null;
    // Cada botão do checklist já sai pronto daqui (o .map() do JSX só lê o próprio item — armadilha do Rollup).
    const botoes = checklist.map((item) => ({
        item,
        Icone: ICONE_ITEM[item.estado] ?? Circle,
        expandido: aberto === item.chave,
        desligado: ! (j.status === 'pendente' || j.status === 'em_andamento'),
        detalhe: item.estado === 'nao_se_aplica'
            ? `Não se aplica: ${textoSeguro(item.motivo, '')}`
            : item.estado === 'feito' ? `Feito${item.por ? ` por ${textoSeguro(item.por, '')}` : ''}` : 'Pendente',
    }));

    function concluir() {
        setErro(null);
        router.post(route(`${ROTA}.concluir`, { jardinagem: j.id }), {}, { ...NA_MESMA_TELA, onError: (erros) => setErro(primeiroErro(erros)) });
    }

    return (
        <article className="rounded-xl border border-white/[0.08] bg-ecf-card p-5" data-diagnostico={textoSeguro(j.diagnostico, '')}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[15px] font-bold text-white">{textoSeguro(empresa.nome)}</p>
                    <p className="text-[13px] font-normal text-white/70">
                        {textoSeguro(produto.nome)}
                        {typeof produto.sku === 'string' && produto.sku !== '' ? <span className="ml-2 font-mono text-[11px] text-white/40">{produto.sku}</span> : null}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span className={cn('rounded-md border px-2 py-0.5 text-[11px] font-bold', COR_DIAGNOSTICO[j.diagnostico] ?? COR_STATUS.pendente)}>
                        {textoSeguro(r.rotulo, textoSeguro(j.diagnostico))}
                    </span>
                    <span className={cn('rounded-md border px-2 py-0.5 text-[11px] font-bold', COR_STATUS[j.status] ?? COR_STATUS.pendente)}>
                        {ROTULO_STATUS[j.status] ?? textoSeguro(j.status)}
                    </span>
                </div>
            </div>

            <p className="mt-3 flex flex-wrap items-center gap-2 text-[13px] font-normal text-white/70">
                <span className="font-bold text-white/55">{textoSeguro(j.tipo, '')}</span>
                {typeof j.permalink === 'string' ? (
                    <a href={j.permalink} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-mono text-[13px] text-white/85 hover:text-ecf-yellow">
                        {textoSeguro(j.ml_item_id)} <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                ) : (
                    <span className="font-mono text-[13px] text-white/85">{textoSeguro(j.ml_item_id)}</span>
                )}
                <span className="text-white/45">· mês {numeroSeguro(j.mes) ?? '—'} · de {fmtDia(janela.inicio)} a {fmtDia(janela.fim)}</span>
            </p>
            {/* O título no ML, só quando difere do nome do produto (igual, seria a mesma linha duas vezes). */}
            {typeof j.titulo === 'string' && j.titulo !== '' && j.titulo !== produto.nome && (
                <p className="mt-1 text-[13px] font-normal text-white/55">{j.titulo}</p>
            )}

            <dl className="mt-4 grid grid-cols-3 gap-3 sm:max-w-[480px]">
                <div>
                    <dt className="text-[11px] font-bold text-white/40">Visitas</dt>
                    <dd className="font-display text-[24px] font-bold tabular-nums text-white">{fmtInteiro(j.visitas)}</dd>
                </div>
                <div>
                    <dt className="text-[11px] font-bold text-white/40">Vendas</dt>
                    <dd className="font-display text-[24px] font-bold tabular-nums text-white">{fmtInteiro(j.vendas)}</dd>
                </div>
                <div>
                    <dt className="text-[11px] font-bold text-white/40">Conversão</dt>
                    <dd className="font-display text-[24px] font-bold tabular-nums text-white">{fmtConversao(j.conversao)}</dd>
                </div>
            </dl>

            <p className="mt-3 text-[13px] font-normal text-white/70">
                <span className="font-bold text-white/55">O que a árvore manda:</span> {textoSeguro(r.acao, '—')}
            </p>

            {j.diagnostico === 'sem_tracao' && j.proposta !== null && typeof j.proposta === 'object' && (
                <PropostaDeTitulo jardinagemId={j.id} proposta={j.proposta} aberta={aberta} onErro={setErro} />
            )}

            <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="O que fazer">
                {botoes.map((b) => (
                    <button
                        key={textoSeguro(b.item.chave, '')}
                        type="button"
                        disabled={b.desligado}
                        aria-expanded={b.expandido}
                        title={b.detalhe}
                        onClick={() => setAberto((a) => (a === b.item.chave ? null : b.item.chave))}
                        className={cn(
                            'inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:cursor-default',
                            COR_ITEM[b.item.estado] ?? COR_ITEM.pendente,
                            b.item.estado === 'nao_se_aplica' && 'line-through',
                        )}
                    >
                        <b.Icone className="h-4 w-4 shrink-0" aria-hidden="true" />
                        {textoSeguro(b.item.rotulo)}
                        <span className="sr-only"> — {ROTULO_ESTADO_ITEM[b.item.estado] ?? ''}</span>
                    </button>
                ))}
            </div>

            {itemAberto && aberta && (
                <EditorDoItem key={itemAberto.chave} jardinagemId={j.id} item={itemAberto} onFechar={() => setAberto(null)} onErro={setErro} />
            )}

            <div className="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-white/[0.06] pt-3">
                <div className="min-w-0 flex-1">
                    <Observacao key={textoSeguro(j.observacao, '')} jardinagemId={j.id} texto={j.observacao} onErro={setErro} />
                </div>
                {aberta && (
                    <BotaoAcao
                        disabled={j.resolvida !== true}
                        title={j.resolvida === true ? undefined : 'Marque cada item como feito ou "não se aplica" para concluir.'}
                        onClick={concluir}
                    >
                        Concluir
                    </BotaoAcao>
                )}
                {! aberta && typeof j.concluida_em === 'string' && (
                    <p className="text-[11px] font-normal text-white/40">
                        Concluída em {fmtQuando(j.concluida_em)}{typeof j.responsavel === 'string' ? ` por ${j.responsavel}` : ''}
                    </p>
                )}
            </div>

            {erro && <p className="mt-2 text-[13px] font-normal text-red-300">{erro}</p>}
        </article>
    );
}

/** Os anúncios ainda dentro do 1º mês: quando cada um será analisado. */
export function EmObservacao({ itens, diasDoCiclo }) {
    const lista = comoLista(itens).map((i) => {
        const x = comoObjeto(i);

        return { ...x, analise: fmtDiaMais(x.inicio, numeroSeguro(diasDoCiclo) ?? 30) };
    });
    if (lista.length === 0) return null;

    return (
        <section aria-label="Em observação" className="mt-10">
            <h2 className="text-[15px] font-bold text-white">Em observação</h2>
            <p className="mt-1 text-[13px] font-normal text-white/55">
                Publicados há menos de um mês: cada um é analisado quando o 1º mês dele fecha.
            </p>
            <ul className="mt-3 divide-y divide-white/[0.06] rounded-xl border border-white/[0.08] bg-ecf-card">
                {lista.map((x) => (
                    <li key={textoSeguro(x.ml_item_id, '')} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-[13px] font-normal text-white/70">
                        <span className="font-bold text-white/55">{textoSeguro(x.tipo, '')}</span>
                        {typeof x.permalink === 'string' ? (
                            <a href={x.permalink} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-mono text-white/85 hover:text-ecf-yellow">
                                {textoSeguro(x.ml_item_id)} <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                            </a>
                        ) : (
                            <span className="font-mono text-white/85">{textoSeguro(x.ml_item_id)}</span>
                        )}
                        <span className="min-w-0 flex-1 truncate">{textoSeguro(x.titulo, '')}</span>
                        <span className="text-white/45">mês {numeroSeguro(x.mes) ?? '—'} · análise em {x.analise}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Página da Jardinagem. Filtros vivem na URL (router.get com preserveState), como na
 * fila das alavancas. Os quatro cartões dos ramos são ao mesmo tempo a régua e o filtro.
 */
export default function Jardinagem(props) {
    const lista = comoLista(props.linhas);
    const filtros = comoObjeto(props.filtros);
    const contagens = comoObjeto(props.contagens);
    const porDiagnostico = comoObjeto(contagens.por_diagnostico);
    const paginacao = comoObjeto(props.paginacao);
    const regras = comoObjeto(props.regras);
    const ramos = comoLista(regras.diagnosticos).map((d) => comoObjeto(d)).filter((d) => typeof d.chave === 'string');
    const status = textoSeguro(filtros.status, 'abertas');
    const diagnostico = typeof filtros.diagnostico === 'string' ? filtros.diagnostico : null;
    const conta = typeof filtros.conta === 'string' ? filtros.conta : null;
    const pagina = numeroSeguro(paginacao.pagina) ?? 1;
    const ultima = numeroSeguro(paginacao.ultima) ?? 1;
    const dias = numeroSeguro(regras.dias_do_ciclo) ?? 30;
    const atuais = { status, diagnostico, conta };

    // Montados AQUI, não dentro do .map() do JSX (armadilha do Rollup): o callback só lê o item.
    const cartoesDosRamos = ramos.map((r) => ({
        ramo: r,
        valor: numeroSeguro(porDiagnostico[r.chave]) ?? 0,
        ativo: diagnostico === r.chave,
        filtro: diagnostico === r.chave ? null : r.chave,
    }));
    const cartoes = lista.map((l) => ({
        linha: l,
        ramo: ramos.find((r) => r.chave === comoObjeto(l).diagnostico) ?? null,
        chave: textoSeguro(comoObjeto(l).id, ''),
    }));
    const gerando = lista.some((l) => comoObjeto(comoObjeto(l).proposta).estado === 'gerando');

    useEffect(() => {
        if (! gerando) return undefined;
        const t = setInterval(() => router.reload({ only: ['linhas'] }), RELEITURA_MS);

        return () => clearInterval(t);
    }, [gerando]);

    return (
        <AppLayout title="Jardinagem">
            <div className="mx-auto max-w-[1240px] px-8 py-8">
                <div className="mb-8 flex items-start gap-4">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12]">
                        <Sprout className="h-[18px] w-[18px] text-ecf-yellow" aria-hidden="true" />
                    </div>
                    <div className="max-w-[720px]">
                        <h1 className="font-display text-[24px] font-bold leading-tight text-white">Jardinagem</h1>
                        <p className="mt-1 text-[13px] font-normal text-white/55">
                            Cada anúncio criado pelo Publicador é analisado uma vez por mês ({dias} dias), pela árvore do MAG:
                            visitas e vendas do mês dizem o que fazer. O anúncio sem tração ganha um título novo com os termos
                            mais buscados da semana para a nova oferta.
                        </p>
                        <p className="mt-2 text-[13px] font-normal text-white/55">
                            {fmtInteiro(numeroSeguro(contagens.em_observacao) ?? 0)} em observação · {fmtInteiro(numeroSeguro(contagens.mes_fechado) ?? 0)} com o mês fechado aguardando a análise do dia
                        </p>
                    </div>
                </div>

                {props.ativa !== true && (
                    <div className="mb-6 rounded-xl border border-amber-400/30 bg-amber-400/[0.06] p-4" data-jardinagem-desligada>
                        <p className="text-[13px] font-bold text-amber-200">A Jardinagem está desligada</p>
                        <p className="mt-1 text-[13px] font-normal text-white/70">
                            A análise diária ainda não roda: nenhum anúncio é lido no Mercado Livre e nada é gravado. Esta é a tela
                            como vai ficar — depois de ligada, cada anúncio entra aqui quando o mês dele fechar.
                        </p>
                    </div>
                )}

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cartoesDosRamos.map((c) => (
                        <CartaoDoRamo
                            key={c.ramo.chave}
                            ramo={c.ramo}
                            valor={c.valor}
                            ativo={c.ativo}
                            onClick={() => visitar(atuais, { diagnostico: c.filtro, pagina: 1 })}
                        />
                    ))}
                </div>

                <div className="mb-6 flex flex-wrap items-center gap-4 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
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
                    {diagnostico && (
                        <button type="button" onClick={() => visitar(atuais, { diagnostico: null, pagina: 1 })} className={LINK}>ver todos os ramos</button>
                    )}
                    {conta && (
                        <p className="text-[13px] font-normal text-white/70">
                            Conta: <span className="font-bold">{textoSeguro(filtros.conta_nome, conta)}</span>{' '}
                            <button type="button" onClick={() => visitar({ status, diagnostico, conta: null })} className={LINK}>ver todas as contas</button>
                        </p>
                    )}
                </div>

                <section aria-label="Jardinagem do mês" className="space-y-4">
                    {lista.length === 0 ? (
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-6">
                            <p className="text-[15px] font-bold text-white">Nenhum anúncio para jardinar aqui.</p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                Quando o mês de um anúncio publicado pelo Publicador fechar, a análise dele aparece nesta lista.
                            </p>
                        </div>
                    ) : cartoes.map((c) => (
                        <CartaoJardinagem key={c.chave} linha={c.linha} ramo={c.ramo} />
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

                <EmObservacao itens={props.em_observacao} diasDoCiclo={dias} />

                {podeVoltarAoPublicador(props) && (
                    <p className="mt-8 text-[13px] font-normal text-white/55">
                        <Link href={route('mlb.anuncios.index')} className={LINK}>Voltar ao Publicador</Link>
                    </p>
                )}
            </div>
        </AppLayout>
    );
}

/** O link para o Publicador só aparece para quem abre o Publicador (hoje, admin). */
function podeVoltarAoPublicador(props) {
    return props.pode_abrir_publicador === true;
}
