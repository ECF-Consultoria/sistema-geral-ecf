import { useEffect, useState } from 'react';
import { formatarDataHora } from '@/lib/dataHora';

// ─── Agente ECF Publicador — o conteúdo da tela (AG-07, 20/10/2026) ──────────
//
// Parte PURA de apresentação: recebe tudo pronto (props do controller + os
// callbacks da página) e não fala com o servidor — por isso dá para testar por
// render real (`tests/js/publicador-agente-render.test.js`), e campo em formato
// inesperado nunca derruba a tela.
//
// Três blocos, como o plano pede para a primeira entrega: Rotina e fila,
// Configuração essencial e Atividade. A aprovação NÃO mora aqui — os links
// levam à Aprovação que já existe. Os contadores dizem a UNIDADE: o preparo
// conta produtos; a Aprovação e a publicação contam ofertas.
//
// O Publicador só tem tema escuro (tokens `pub-*` fixos no tailwind.config.js);
// não há tema claro no produto para respeitar.

const MODALIDADES = { gold_special: 'Clássico', gold_pro: 'Premium' };

const ESTADOS = {
    admitida: { rotulo: 'Na fila do preparo', cor: 'text-pub-txt-2' },
    preparando: { rotulo: 'Preparando', cor: 'text-pub-warn-txt' },
    aguardando_aprovacao: { rotulo: 'Na Aprovação', cor: 'text-pub-ok-txt' },
    concluida: { rotulo: 'Concluído', cor: 'text-pub-ok-txt' },
    bloqueada: { rotulo: 'Faltam dados', cor: 'text-pub-danger-txt' },
    falhou: { rotulo: 'Falhou', cor: 'text-pub-danger-txt' },
    cancelada: { rotulo: 'Cancelado', cor: 'text-pub-txt-4' },
};

const ETAPAS = {
    elegibilidade: 'conferindo os dados do produto',
    gerando_texto: 'escrevendo título, Modelo e descrição',
    texto_pronto: 'texto pronto',
    recuperando_texto: 'tentando o texto de novo',
    encaminhada: 'enviado para a Aprovação',
};

const ORIGENS = { automatica: 'Automático', manual: 'Preparar agora', ao_salvar: 'Cliente salvou no Portal' };

const ANDAMENTOS = {
    preparando: 'Preparando',
    aguardando_aprovacao: 'Aguardando aprovação',
    em_revisao_ecf: 'Em revisão',
    ajustes_solicitados: 'Ajustes pedidos',
    aprovado: 'Aprovado',
    publicando: 'Publicando',
    falha: 'Falhou ao publicar',
    publicado: 'Publicado',
};

const texto = (v, padrao = '—') => (typeof v === 'string' && v.trim() !== '' ? v : padrao);
const lista = (v) => (Array.isArray(v) ? v : []);
const numero = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

function Cartao({ titulo, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-pub-line bg-pub-surface p-5 ${className}`}>
            {titulo && <h2 className="text-[14px] font-semibold text-pub-txt">{titulo}</h2>}
            {children}
        </section>
    );
}

function Contador({ rotulo, valor, unidade }) {
    return (
        <div className="rounded-lg border border-pub-line-subtle bg-pub-sunken px-4 py-3">
            <div className="text-[12px] text-pub-txt-3">{rotulo}</div>
            <div className="mt-1 text-[22px] font-semibold tabular-nums text-pub-txt">{numero(valor)}</div>
            <div className="text-[11px] text-pub-txt-4">{unidade}</div>
        </div>
    );
}

function SituacaoDoAgente({ configuracao }) {
    if (!configuracao?.existe || !configuracao?.habilitado) {
        return <span className="rounded-full bg-pub-hover-3 px-2.5 py-1 text-[12px] text-pub-txt-2">Desligado</span>;
    }
    if (configuracao.controle === 'suspenso') {
        return <span className="rounded-full bg-pub-danger/[0.14] px-2.5 py-1 text-[12px] text-pub-danger-txt">Suspenso</span>;
    }
    if (configuracao.controle === 'pausado') {
        return <span className="rounded-full bg-pub-warn/[0.14] px-2.5 py-1 text-[12px] text-pub-warn-txt">Pausado</span>;
    }

    return <span className="rounded-full bg-pub-ok/[0.14] px-2.5 py-1 text-[12px] text-pub-ok-txt">Ligado</span>;
}

function Botao({ children, onClick, disabled, principal = false, tipo = 'button' }) {
    const base = 'rounded-md px-3 py-1.5 text-[13px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
    const estilo = principal
        ? 'bg-pub-accent text-pub-page hover:bg-pub-accent-hover'
        : 'border border-pub-line-strong text-pub-txt hover:bg-pub-hover-2';

    return (
        <button type={tipo} onClick={onClick} disabled={disabled} className={`${base} ${estilo}`}>
            {children}
        </button>
    );
}

/** Uma execução: o produto, onde ela está, o porquê e os destinos reais (Aprovação e editor). */
function LinhaDaExecucao({ e }) {
    const estado = ESTADOS[e?.estado] ?? { rotulo: texto(e?.estado), cor: 'text-pub-txt-2' };
    const bloqueios = lista(e?.bloqueios);

    return (
        <tr className="border-t border-pub-line-subtle align-top">
            <td className="py-2.5 pr-3 text-[12px] tabular-nums text-pub-txt-3">{formatarDataHora(e?.atualizada_em) ?? '—'}</td>
            <td className="py-2.5 pr-3">
                <div className="text-[13px] text-pub-txt">{texto(e?.produto?.nome, 'Produto removido')}</div>
                <div className="text-[11px] text-pub-txt-4">{texto(e?.produto?.sku, '')}</div>
            </td>
            <td className="py-2.5 pr-3">
                <div className={`text-[13px] ${estado.cor}`}>{estado.rotulo}</div>
                {e?.etapa && <div className="text-[11px] text-pub-txt-4">{ETAPAS[e.etapa] ?? e.etapa}</div>}
            </td>
            <td className="py-2.5 pr-3 text-[12px] text-pub-txt-2">
                {ORIGENS[e?.origem] ?? texto(e?.origem)}
                {e?.solicitada_por && <div className="text-[11px] text-pub-txt-4">{e.solicitada_por}</div>}
            </td>
            <td className="py-2.5 pr-3 text-[12px] text-pub-txt-2">
                {bloqueios.length > 0 ? (
                    <ul className="space-y-1">
                        {bloqueios.map((b, i) => (
                            <li key={`${b?.codigo}-${i}`}>
                                {texto(b?.mensagem)}
                                {b?.url_correcao && (
                                    <a href={b.url_correcao} className="ml-1.5 text-pub-accent hover:underline">corrigir</a>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    texto(e?.motivo, '')
                )}
            </td>
            <td className="py-2.5 text-right text-[12px] whitespace-nowrap">
                {e?.oferta?.url && (
                    <a href={e.oferta.url} className="text-pub-accent hover:underline">
                        {ANDAMENTOS[e.oferta.andamento] ?? 'Abrir'} na Aprovação
                    </a>
                )}
                {e?.url_editor && (
                    <a href={e.url_editor} className="ml-3 text-pub-txt-2 hover:text-pub-txt hover:underline">Editor</a>
                )}
            </td>
        </tr>
    );
}

function TabelaDeExecucoes({ linhas, vazio }) {
    if (linhas.length === 0) {
        return <p className="mt-3 text-[13px] text-pub-txt-3">{vazio}</p>;
    }

    return (
        <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[860px] text-left">
                <thead>
                    <tr className="text-[11px] uppercase tracking-wide text-pub-txt-4">
                        <th className="pb-2 pr-3 font-medium">Quando</th>
                        <th className="pb-2 pr-3 font-medium">Produto</th>
                        <th className="pb-2 pr-3 font-medium">Situação</th>
                        <th className="pb-2 pr-3 font-medium">Origem</th>
                        <th className="pb-2 pr-3 font-medium">Motivo</th>
                        <th className="pb-2 font-medium" />
                    </tr>
                </thead>
                <tbody>
                    {linhas.map((e, i) => <LinhaDaExecucao key={e?.id ?? i} e={e} />)}
                </tbody>
            </table>
        </div>
    );
}

/** O formulário da configuração essencial. Envia a versão que a pessoa VIU (`versao_esperada`). */
function FormularioDaConfiguracao({ configuracao, processando, onSalvar }) {
    const inicial = () => ({
        habilitado: Boolean(configuracao?.habilitado),
        modalidade: configuracao?.modalidade ?? '',
        estoque_minimo: numero(configuracao?.estoque_minimo ?? 1),
        limite_diario: numero(configuracao?.limite_diario ?? 5),
        teto_fila: numero(configuracao?.teto_fila ?? 10),
        janela_inicio: configuracao?.janela_inicio ?? '',
        janela_fim: configuracao?.janela_fim ?? '',
    });
    const [form, setForm] = useState(inicial);
    // Outra versão chegou do servidor (salvou, ou recarregou depois de um conflito): o formulário a acompanha.
    useEffect(() => setForm(inicial()), [configuracao?.versao]); // eslint-disable-line react-hooks/exhaustive-deps

    const campo = (nome) => (ev) => setForm((f) => ({ ...f, [nome]: ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value }));
    const enviar = (ev) => {
        ev.preventDefault();
        onSalvar?.({
            ...form,
            modalidade: form.modalidade || null,
            estoque_minimo: numero(form.estoque_minimo),
            limite_diario: numero(form.limite_diario),
            teto_fila: numero(form.teto_fila),
            janela_inicio: form.janela_inicio || null,
            janela_fim: form.janela_fim || null,
            versao_esperada: numero(configuracao?.versao),
        });
    };
    const entrada = 'mt-1 w-full rounded-md border border-pub-line-strong bg-pub-sunken px-2.5 py-1.5 text-[13px] text-pub-txt';

    return (
        <form onSubmit={enviar} className="mt-4 space-y-4">
            <label className="flex items-center gap-2 text-[13px] text-pub-txt">
                <input type="checkbox" checked={form.habilitado} onChange={campo('habilitado')} />
                Ligar o agente nesta loja
            </label>
            <div className="grid grid-cols-3 gap-4">
                <label className="text-[12px] text-pub-txt-3">
                    Modalidade que ele prepara
                    <select value={form.modalidade} onChange={campo('modalidade')} className={entrada}>
                        <option value="">Escolha…</option>
                        {Object.entries(MODALIDADES).map(([v, r]) => <option key={v} value={v}>{r}</option>)}
                    </select>
                </label>
                <label className="text-[12px] text-pub-txt-3">
                    Estoque mínimo (unidades)
                    <input type="number" min="0" value={form.estoque_minimo} onChange={campo('estoque_minimo')} className={entrada} />
                </label>
                <label className="text-[12px] text-pub-txt-3">
                    Limite por dia (produtos)
                    <input type="number" min="0" value={form.limite_diario} onChange={campo('limite_diario')} className={entrada} />
                </label>
                <label className="text-[12px] text-pub-txt-3">
                    Máximo esperando revisão (ofertas)
                    <input type="number" min="0" value={form.teto_fila} onChange={campo('teto_fila')} className={entrada} />
                </label>
                <label className="text-[12px] text-pub-txt-3">
                    Prepara sozinho a partir de
                    <input type="time" value={form.janela_inicio} onChange={campo('janela_inicio')} className={entrada} />
                </label>
                <label className="text-[12px] text-pub-txt-3">
                    até
                    <input type="time" value={form.janela_fim} onChange={campo('janela_fim')} className={entrada} />
                </label>
            </div>
            <p className="text-[11px] text-pub-txt-4">
                Sem horário, ele pode preparar a qualquer hora. O horário vale só para o que ele faz sozinho; o
                "Preparar agora" funciona sempre. Margem e custos vêm da configuração financeira da empresa.
            </p>
            <div className="flex items-center justify-between">
                <span className="text-[11px] text-pub-txt-4">
                    {configuracao?.atualizado_em ? `Salvo em ${formatarDataHora(configuracao.atualizado_em) ?? '—'}` : 'Ainda não configurado'}
                </span>
                <Botao tipo="submit" principal disabled={processando}>Salvar configuração</Botao>
            </div>
        </form>
    );
}

export default function ConteudoDoAgente({ agente, produtos = [], processando = false, mensagem = null, erro = null, onSalvar, onExecutar, onControle }) {
    const configuracao = agente?.configuracao ?? {};
    const contadores = agente?.contadores ?? {};
    const fila = lista(agente?.fila);
    const emCurso = fila.filter((e) => ['admitida', 'preparando', 'bloqueada'].includes(e?.estado));
    const [produtoId, setProdutoId] = useState('');
    const ligado = Boolean(configuracao?.existe && configuracao?.habilitado);
    // Pausado/suspenso o servidor recusa o "Preparar agora" (AG-01-CONTROLE): o botão não promete o que não faz.
    const ativo = ligado && configuracao?.controle === 'ativo';

    return (
        <div className="space-y-5">
            <Cartao>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <h1 className="text-[18px] font-semibold text-pub-txt">Agente da loja</h1>
                        <SituacaoDoAgente configuracao={configuracao} />
                        {configuracao?.modalidade && (
                            <span className="text-[12px] text-pub-txt-3">Prepara: {MODALIDADES[configuracao.modalidade] ?? configuracao.modalidade}</span>
                        )}
                    </div>
                    {ligado && (
                        <div className="flex gap-2">
                            {configuracao.controle === 'ativo' ? (
                                <>
                                    <Botao onClick={() => onControle?.('pausar')} disabled={processando}>Pausar</Botao>
                                    <Botao onClick={() => onControle?.('suspender')} disabled={processando}>Suspender</Botao>
                                </>
                            ) : (
                                <Botao principal onClick={() => onControle?.('retomar')} disabled={processando}>Retomar</Botao>
                            )}
                        </div>
                    )}
                </div>
                <p className="mt-2 text-[12px] text-pub-txt-3">
                    O agente prepara o anúncio e manda para a Aprovação. Nada vai ao Mercado Livre sem uma pessoa
                    aprovar. Pausar ou suspender não mexe em anúncios no ar nem em ofertas que já estão na Aprovação.
                </p>
                {configuracao?.motivo_controle && configuracao?.controle !== 'ativo' && (
                    <p className="mt-1 text-[12px] text-pub-warn-txt">Motivo: {configuracao.motivo_controle}</p>
                )}
                {mensagem && <p className="mt-3 rounded-md bg-pub-ok/[0.10] px-3 py-2 text-[13px] text-pub-ok-txt">{mensagem}</p>}
                {erro && <p className="mt-3 rounded-md bg-pub-danger/[0.10] px-3 py-2 text-[13px] text-pub-danger-txt">{erro}</p>}
            </Cartao>

            <Cartao titulo="Rotina e fila">
                <div className="mt-3 grid grid-cols-5 gap-3">
                    <Contador rotulo="Faltam dados" valor={contadores.aguardando_dados} unidade="produtos · últimas 24 h" />
                    <Contador rotulo="Preparando" valor={contadores.preparando} unidade="produtos" />
                    <Contador rotulo="Aguardando aprovação" valor={contadores.aguardando_aprovacao} unidade="ofertas" />
                    <Contador rotulo="Publicação" valor={contadores.publicacao} unidade="ofertas aprovadas" />
                    <Contador rotulo="Falhas" valor={contadores.falhas} unidade="ofertas" />
                </div>
                <div className="mt-4 flex flex-wrap items-end gap-3">
                    <label className="text-[12px] text-pub-txt-3">
                        Preparar agora
                        <select value={produtoId} onChange={(ev) => setProdutoId(ev.target.value)}
                            className="mt-1 block w-[420px] max-w-full rounded-md border border-pub-line-strong bg-pub-sunken px-2.5 py-1.5 text-[13px] text-pub-txt">
                            <option value="">Escolha um produto…</option>
                            {lista(produtos).map((p) => (
                                <option key={p?.id} value={p?.id}>{texto(p?.nome)}{p?.sku ? ` · ${p.sku}` : ''}</option>
                            ))}
                        </select>
                    </label>
                    <Botao principal disabled={processando || !ativo || produtoId === ''} onClick={() => onExecutar?.(Number(produtoId))}>
                        Preparar agora
                    </Botao>
                    {!ligado && <span className="text-[12px] text-pub-txt-4">Ligue o agente na configuração para preparar.</span>}
                    {ligado && !ativo && <span className="text-[12px] text-pub-txt-4">Retome o agente para preparar.</span>}
                </div>
                <TabelaDeExecucoes linhas={emCurso} vazio="Nada em preparo nem esperando dados agora." />
            </Cartao>

            {/* Largura toda nos dois: a tabela da Atividade tem 6 colunas e o motivo é texto longo — numa coluna
                estreita ao lado do formulário ela cortava o motivo (visto no screenshot de 20/10/2026). */}
            <Cartao titulo="Configuração essencial">
                <FormularioDaConfiguracao configuracao={configuracao} processando={processando} onSalvar={onSalvar} />
            </Cartao>
            <Cartao titulo="Atividade">
                <p className="mt-1 text-[12px] text-pub-txt-3">Os {fila.length} registros mais recentes desta loja.</p>
                <TabelaDeExecucoes linhas={fila} vazio="O agente ainda não fez nada nesta loja." />
            </Cartao>
        </div>
    );
}
