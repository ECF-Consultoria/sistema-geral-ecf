import { useEffect, useState } from 'react';
import { formatarDataHora } from '@/lib/dataHora';
import RegistroDeAtividade from './RegistroDeAtividade';

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
// ⚠️⚠️ **`Number(valor)` LANÇA com `{toString: null}`** — *"Cannot convert object to
// primitive value"* —, porque o ToPrimitive tenta `valueOf` e `toString` e nenhum
// dos dois é chamável. É a MESMA assinatura das seis telas pretas desta milestone,
// por um caminho novo: não é `String(` nem `obj[chave]`, é `Number(`.
// ➡️ Então a guarda é de **TIPO antes da conversão**: objeto nunca chega ao `Number`.
// Achado pelo render adverso em 23/10/2026.
// ⚠️ Este `numero()` tinha o defeito desde 20/10 e **o teste de lixo não o pegava**:
// ele nunca pôs objeto num campo numérico (`contadores`, `versao`). Consertado junto
// porque é a mesma linha de uma falha e o arquivo já estava aberto.
const numero = (v) => {
    if (typeof v === 'number') {
        return Number.isFinite(v) ? v : 0;
    }
    if (typeof v === 'string' && v.trim() !== '') {
        const n = Number(v);

        return Number.isFinite(n) ? n : 0;
    }

    return 0;
};

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
function FormularioDaConfiguracao({ configuracao, diasOpcoes = [], copia = null, origensDeCopia = [], processando, onSalvar, onCopiarDe }) {
    // ⚠️⚠️ **O RASCUNHO DA CÓPIA SOBREPÕE O FORMULÁRIO, MAS NÃO O BANCO.** O servidor só LEU a
    // outra loja; nada foi gravado. Quem salva é a pessoa, com a `versao_esperada` que ela viu —
    // então a cópia herda a trava de concorrência de sempre.
    const doRascunho = typeof copia?.valores === 'object' && copia?.valores !== null ? copia.valores : null;
    const inicial = () => ({
        // ⚠️ O rascunho da cópia vence a configuração gravada **no formulário**. `habilitado` NÃO
        // entra nessa regra de propósito: a cópia nasce DESLIGADA (*"regras copiadas começam
        // inativas"*), e o servidor já força `false` — isto aqui é a segunda guarda.
        habilitado: doRascunho !== null ? false : Boolean(configuracao?.habilitado),
        // ⚠️⚠️ **`texto()` NOS TRÊS CAMPOS DE STRING, e isto consertou um defeito LATENTE do
        // formulário original.** Eles iam direto para o `value=` de um `<select>`/`<input>`; com
        // `{toString: null}` o React **LANÇA** ao converter, e a página fica PRETA. O teste de lixo
        // antigo nunca punha objeto em `modalidade` nem nas janelas, então passava calado — e a
        // cópia de outra loja abriu um caminho novo para o mesmo campo. Achado pelo render adverso
        // em 23/10/2026, mesma família do `Number(` consertado mais cedo.
        modalidade: texto(doRascunho?.modalidade ?? configuracao?.modalidade, ''),
        estoque_minimo: numero(doRascunho?.estoque_minimo ?? configuracao?.estoque_minimo ?? 1),
        limite_diario: numero(doRascunho?.limite_diario ?? configuracao?.limite_diario ?? 5),
        teto_fila: numero(doRascunho?.teto_fila ?? configuracao?.teto_fila ?? 10),
        janela_inicio: texto(doRascunho?.janela_inicio ?? configuracao?.janela_inicio, ''),
        janela_fim: texto(doRascunho?.janela_fim ?? configuracao?.janela_fim, ''),
        // ⚠️ ISO: 1 = segunda … 7 = domingo. Vazio = todo dia (o servidor normaliza para `null`).
        dias_semana: lista(doRascunho?.dias_semana ?? configuracao?.dias_semana).map((d) => numero(d)).filter((d) => d >= 1 && d <= 7),
    });
    const [form, setForm] = useState(inicial);
    // Outra versão chegou do servidor (salvou, ou recarregou depois de um conflito): o formulário a acompanha.
    // ⚠️ E a CHAVE DA ORIGEM entra na dependência: sem ela, pedir a cópia de outra loja chegaria
    // nas props e **o formulário não se mexeria**, porque `versao` do destino não mudou.
    useEffect(() => setForm(inicial()), [configuracao?.versao, copia?.origem?.chave]); // eslint-disable-line react-hooks/exhaustive-deps

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
            dias_semana: lista(form.dias_semana),
            versao_esperada: numero(configuracao?.versao),
        });
    };
    const entrada = 'mt-1 w-full rounded-md border border-pub-line-strong bg-pub-sunken px-2.5 py-1.5 text-[13px] text-pub-txt';

    return (
        <form onSubmit={enviar} className="mt-4 space-y-4">
            {/* ⚠️ COPIAR DE OUTRA LOJA (E1). Só aparece quando existe de onde copiar — seletor
                vazio seria um controle que não faz nada. */}
            {lista(origensDeCopia).length > 0 && (
                <div className="rounded-lg border border-pub-line-subtle bg-pub-sunken px-3 py-2.5">
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="text-[12px] text-pub-txt-3">
                            Começar a partir de outra loja
                            <select
                                value={texto(copia?.origem?.chave)} disabled={processando}
                                onChange={(ev) => onCopiarDe?.(ev.target.value === '' ? null : ev.target.value)}
                                className="mt-1 block w-[320px] max-w-full rounded-md border border-pub-line-strong bg-pub-surface px-2.5 py-1.5 text-[13px] text-pub-txt"
                            >
                                <option value="">Escolha uma loja…</option>
                                {lista(origensDeCopia).map((o) => {
                                    // ⚠️ DENTRO do callback (Rollup).
                                    const chave = texto(o?.chave);
                                    const nome = texto(o?.nome, chave);

                                    return chave === '' ? null : <option key={chave} value={chave}>{nome}</option>;
                                })}
                            </select>
                        </label>
                    </div>

                    {/* ⚠️⚠️ **O AVISO É A PARTE QUE PROTEGE.** Sem ele a pessoa acha que a loja ficou
                        igual à outra — e o escopo de produtos e o liga/desliga NÃO vieram. A lista do
                        que faltou vem do SERVIDOR, para não divergir do que ele de fato copiou. */}
                    {doRascunho !== null && (
                        <div className="mt-2 text-[12px] text-pub-warn-txt">
                            <p>
                                Valores de <strong>{texto(copia?.origem?.nome, 'outra loja')}</strong> carregados no
                                formulário. <strong>Nada foi salvo ainda</strong> — confira e clique em
                                "Salvar configuração".
                            </p>
                            {lista(copia?.nao_copiados).length > 0 && (
                                <p className="mt-0.5 text-pub-txt-3">
                                    Não veio: {lista(copia?.nao_copiados).map((n) => texto(n)).filter((n) => n !== '').join('; ')}.
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

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

            {/* ⚠️ O CALENDÁRIO (E1, 23/10/2026). Botão de alternar, não `<select multiple>`: sete opções
                cabem na linha e `multiple` exige Ctrl+clique, que ninguém descobre.
                ⚠️ **A cor NÃO é o único sinal** (VOC-05): o dia marcado tem `aria-pressed` e a frase
                abaixo diz, por extenso, quais dias valem. */}
            <div>
                <span className="text-[12px] text-pub-txt-3">Dias em que ele prepara sozinho</span>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {lista(diasOpcoes).map((o) => {
                        // ⚠️ TUDO dentro do callback: o Rollup elimina variável de escopo do componente
                        // usada dentro de `.map()` (defeito medido neste projeto).
                        const valor = numero(o?.valor);
                        const rotulo = texto(o?.rotulo, String(valor));
                        const marcado = lista(form.dias_semana).includes(valor);

                        return valor < 1 || valor > 7 ? null : (
                            <button
                                key={valor} type="button" aria-pressed={marcado} disabled={processando}
                                onClick={() => setForm((f) => {
                                    const atuais = lista(f.dias_semana);

                                    return {
                                        ...f,
                                        dias_semana: atuais.includes(valor)
                                            ? atuais.filter((d) => d !== valor)
                                            : [...atuais, valor].sort((a, b) => a - b),
                                    };
                                })}
                                className={`rounded-md border px-2.5 py-1 text-[12px] ${marcado
                                    ? 'border-pub-accent bg-pub-accent/[0.12] text-pub-txt'
                                    : 'border-pub-line-strong text-pub-txt-3'}`}
                            >
                                {rotulo}
                            </button>
                        );
                    })}
                </div>
            </div>

            <p className="text-[11px] text-pub-txt-4">
                Sem horário, ele pode preparar a qualquer hora. O horário vale só para o que ele faz sozinho; o
                "Preparar agora" funciona sempre. Margem e custos vêm da configuração financeira da empresa.
            </p>
            {/* ⚠️ A frase diz o estado por EXTENSO, e distingue os dois casos: "nenhum marcado" significa
                TODO dia (mesma convenção do horário em branco), e deixar isso implícito faria a pessoa achar
                que desmarcar tudo trava o agente. */}
            <p className="text-[11px] text-pub-txt-4">
                {lista(form.dias_semana).length === 0
                    ? 'Nenhum dia marcado: ele pode preparar em qualquer dia da semana.'
                    : `Ele prepara sozinho às ${lista(form.dias_semana)
                        .map((d) => texto(lista(diasOpcoes).find((o) => numero(o?.valor) === d)?.rotulo, String(d)))
                        .join(', ')}.`}
                {form.janela_inicio && form.janela_fim && form.janela_inicio > form.janela_fim
                    ? ' ⚠️ Como o horário passa da meia-noite, a madrugada conta como o dia em que a janela começou.'
                    : ''}
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

export default function ConteudoDoAgente({ agente, produtos = [], atividade = null, processando = false, mensagem = null, erro = null, onSalvar, onExecutar, onControle, onFiltrarAtividade, onCopiarDe }) {
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
                        {/* ⚠️⚠️ **GUARDA DE TIPO, e aqui havia DOIS defeitos**, latentes desde 20/10 e
                            achados pelo render adverso em 23/10:
                              1. `MODALIDADES[configuracao.modalidade]` — **`obj[chave]` LANÇA** com
                                 `{toString: null}`, porque converter a chave em nome de propriedade
                                 chama o ToPrimitive. É a armadilha de tela preta mais citada deste
                                 projeto, e estava aqui;
                              2. o fallback `?? configuracao.modalidade` renderizaria o **objeto cru**,
                                 e prop de nó chegando objeto comum faz o React lançar.
                            ➡️ `texto()` resolve os dois: a chave vira string antes do `[]`, e o
                            fallback é string. O teste de lixo antigo não pegava porque
                            `configuracao: VENENO` deixava as chaves `undefined` — nunca punha o
                            veneno NO CAMPO. */}
                        {texto(configuracao?.modalidade) !== '' && (
                            <span className="text-[12px] text-pub-txt-3">Prepara: {MODALIDADES[texto(configuracao?.modalidade)] ?? texto(configuracao?.modalidade)}</span>
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
                <FormularioDaConfiguracao
                    configuracao={configuracao}
                    diasOpcoes={lista(agente?.dias_opcoes)}
                    copia={agente?.copia ?? null}
                    origensDeCopia={lista(agente?.origens_de_copia)}
                    processando={processando}
                    onSalvar={onSalvar}
                    onCopiarDe={onCopiarDe}
                />
            </Cartao>
            {/* ⚠️⚠️ **ANTES ESTE CARTÃO MOSTRAVA A MESMA TABELA DA FILA, com outro título** —
                as execuções mais recentes. Não era registro de atividade: era a fila repetida, e
                o docblock do `LeituraDoAgente` já prometia "atividade" que não existia.
                ➡️ Agora é a **projeção das três fontes** que o agente e o portão já persistem
                (preparo, configuração e aprovação), agrupada por dia, filtrável e paginada —
                **sem tabela nova** (§5.5 do plano). */}
            <Cartao titulo="Registro de atividade">
                <RegistroDeAtividade
                    atividade={atividade}
                    processando={processando}
                    onFiltrar={onFiltrarAtividade}
                />
            </Cartao>
        </div>
    );
}
