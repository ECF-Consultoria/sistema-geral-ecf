// ─── Agente ECF Publicador — o Registro de atividade (E1, 23/10/2026) ────────
//
// ⚠️⚠️ **ANTES DESTA PEÇA, o cartão "Atividade" mostrava a MESMA tabela da fila**
// (as execuções mais recentes), com outro título. Não era registro de
// atividade: era a fila repetida. Agora ele é a **projeção das três fontes que
// o agente e o portão já persistem** — preparo, configuração e aprovação —,
// agrupada por dia, filtrável e paginada. **Sem tabela nova** (§5.5 do plano:
// *"dados novos, apenas onde há lacuna"*).
//
// Parte PURA: recebe `atividade` pronto do servidor e devolve a mudança de
// filtro/página pelo callback. Quem fala com o servidor é a página.
//
// ⚠️⚠️ **DUAS PALAVRAS QUE NÃO SE CONFUNDEM, e o servidor já as separa:**
//   · **fonte**  = de qual das três tabelas a linha veio (Preparo/Configuração/Aprovação);
//   · **origem** = quem disparou (o agente ou uma pessoa).
// Prop com dois sentidos foi achada como defeito grave na Fase 186.
//
// ⚠️ **TODO RÓTULO VEM DO SERVIDOR.** Esta peça não traduz estado nem monta
// frase: `fonte_rotulo`, `origem_rotulo`, `titulo` e `resultado_rotulo` chegam
// prontos. Dois vocabulários (um em PHP, um em JS) divergem — foi erro medido
// nas Fases 186/187, e aqui não há `||` aceitando as duas formas.
//
// ⚠️ **Nada de `String(`**: com `{toString: null}` ele **lança** e a página fica
// PRETA — voltou seis vezes nesta milestone. Todo campo passa por `texto()`,
// que é guarda de TIPO, não conversão.
//
// ⚠️ **As flags ficam DENTRO do callback do `.map()`**: o Rollup elimina
// variável de escopo do componente usada dentro de `.map()` (bug medido neste
// projeto), e esta peça mapeia dias, linhas e links.

const texto = (v, padrao = '') => (typeof v === 'string' && v.trim() !== '' ? v : padrao);
const lista = (v) => (Array.isArray(v) ? v : []);
// ⚠️⚠️ **`Number(valor)` LANÇA com `{toString: null}`** — *"Cannot convert object to
// primitive value"* —, porque o ToPrimitive tenta `valueOf` e `toString` e nenhum
// dos dois é chamável. É a MESMA assinatura das seis telas pretas desta milestone,
// por um caminho novo: não é `String(` nem `obj[chave]`, é `Number(`.
// ➡️ Então a guarda é de **TIPO antes da conversão**: objeto nunca chega ao `Number`.
// Achado pelo render adverso em 23/10/2026.
const inteiro = (v) => {
    if (typeof v === 'number') {
        return Number.isFinite(v) ? Math.trunc(v) : 0;
    }
    if (typeof v === 'string' && v.trim() !== '') {
        const n = Number(v);

        return Number.isFinite(n) ? Math.trunc(n) : 0;
    }

    return 0;
};

// ⚠️ A cor por fonte é DECORAÇÃO: o rótulo em texto vem sempre, e a cor nunca é
// o único sinal (VOC-05, regra permanente deste projeto).
const COR_DA_FONTE = {
    preparo: 'border-pub-line-strong text-pub-txt-2',
    configuracao: 'border-pub-line-strong text-pub-txt-3',
    aprovacao: 'border-pub-ok/40 text-pub-ok-txt',
};

/** Um `<select>` do filtro. Opção vem do servidor; a peça não inventa valor. */
function Filtro({ rotulo, valor, opcoes, onMudar, largura = 'w-[150px]' }) {
    return (
        <label className="text-[12px] text-pub-txt-3">
            {rotulo}
            <select
                value={texto(valor)}
                onChange={(ev) => onMudar?.(ev.target.value === '' ? null : ev.target.value)}
                className={`mt-1 block ${largura} max-w-full rounded-md border border-pub-line-strong bg-pub-sunken px-2.5 py-1.5 text-[13px] text-pub-txt`}
            >
                <option value="">Todos</option>
                {lista(opcoes).map((o) => {
                    // ⚠️ DENTRO do callback, por causa do Rollup.
                    const v = texto(o?.valor);
                    const r = texto(o?.rotulo, v);

                    return v === '' ? null : <option key={v} value={v}>{r}</option>;
                })}
            </select>
        </label>
    );
}

/** Uma linha do registro. */
export function LinhaDaAtividade({ linha }) {
    // ⚠️ Tudo DENTRO do componente da linha: ele é chamado de dentro de um `.map()`.
    const hora = texto(linha?.hora, '--:--');
    const titulo = texto(linha?.titulo, 'Evento');
    const fonteRotulo = texto(linha?.fonte_rotulo);
    const origemRotulo = texto(linha?.origem_rotulo);
    const resultado = texto(linha?.resultado_rotulo);
    const motivo = texto(linha?.motivo);
    const autor = texto(linha?.autor);
    const fonte = texto(linha?.fonte);
    const corDaFonte = COR_DA_FONTE[fonte] ?? 'border-pub-line-strong text-pub-txt-3';

    const produto = linha?.produto;
    const nomeDoProduto = typeof produto === 'object' && produto !== null ? texto(produto.nome) : '';
    const skuDoProduto = typeof produto === 'object' && produto !== null ? texto(produto.sku) : '';

    // ⚠️ Os links só existem quando o servidor os mandou — **link só nasce com
    // destino** (decisão D4: "botão sem destino não nasce"; *"desabilitado com
    // explicação"* foi oferecido e RECUSADO). E a conta sem `Company` não ganha
    // o link da Aprovação de propósito: ele daria 404.
    const links = typeof linha?.links === 'object' && linha?.links !== null ? linha.links : {};
    const hrefProduto = texto(links.produto);
    const hrefPrevia = texto(links.previa);
    const hrefOferta = texto(links.oferta);

    return (
        <li className="flex gap-3 border-t border-pub-line-subtle py-2.5 first:border-t-0">
            <span className="w-[42px] shrink-0 pt-0.5 text-[12px] tabular-nums text-pub-txt-4">{hora}</span>

            <span className={`h-fit shrink-0 rounded-md border px-1.5 py-0.5 text-[11px] ${corDaFonte}`}>
                {fonteRotulo || '—'}
            </span>

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 text-[13px] text-pub-txt">
                    <span className="font-medium">{titulo}</span>
                    {resultado !== '' && <span className="text-[12px] text-pub-txt-2">· {resultado}</span>}
                </div>

                {nomeDoProduto !== '' && (
                    <div className="mt-0.5 truncate text-[12px] text-pub-txt-3">
                        {nomeDoProduto}
                        {skuDoProduto !== '' && <span className="text-pub-txt-4"> · {skuDoProduto}</span>}
                    </div>
                )}

                {motivo !== '' && <div className="mt-0.5 text-[12px] text-pub-txt-3">{motivo}</div>}

                {/* ⚠️⚠️ O DIFF da configuração (23/10/2026): **campo por campo, em pt-BR, vindo do
                    SERVIDOR.** Lista vazia significa *"não sei dizer"* (versão 1, ou sem a anterior no
                    banco) e **não** *"nada mudou"* — por isso a tela simplesmente não desenha nada,
                    em vez de escrever "sem mudanças", que seria uma afirmação sem prova. */}
                {lista(linha?.mudancas).length > 0 && (
                    <ul className="mt-1 space-y-0.5">
                        {lista(linha?.mudancas).map((m, i) => {
                            // ⚠️ DENTRO do callback (Rollup elimina var de escopo usada no `.map()`).
                            const campo = texto(m?.campo, `c-${i}`);
                            const rotuloDoCampo = texto(m?.rotulo, campo);
                            const de = texto(m?.de, '—');
                            const para = texto(m?.para, '—');

                            return (
                                <li key={campo} className="text-[12px] text-pub-txt-3">
                                    <span className="text-pub-txt-4">{rotuloDoCampo}:</span>{' '}
                                    <span className="line-through">{de}</span>{' → '}
                                    <span className="text-pub-txt-2">{para}</span>
                                </li>
                            );
                        })}
                    </ul>
                )}

                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-pub-txt-4">
                    {origemRotulo !== '' && <span>{origemRotulo}</span>}
                    {autor !== '' && <span>{autor}</span>}
                    {hrefProduto !== '' && (
                        <a href={hrefProduto} className="text-pub-txt-2 underline underline-offset-[3px]">Abrir no editor</a>
                    )}
                    {hrefPrevia !== '' && (
                        <a href={hrefPrevia} className="text-pub-txt-2 underline underline-offset-[3px]">Ver a prévia</a>
                    )}
                    {hrefOferta !== '' && (
                        <a href={hrefOferta} className="text-pub-txt-2 underline underline-offset-[3px]">Ver na Aprovação</a>
                    )}
                </div>
            </div>
        </li>
    );
}

/**
 * ⚠️ `export` nomeado de propósito: o renderizador de SERVIDOR do React **não
 * renderiza portal**, e peça interna testada só pela árvore da página passa por
 * OMISSÃO. Aqui não há portal, mas a disciplina é a mesma que as Fases 178 e
 * 187-09 fixaram: a peça que carrega a decisão é testável direto.
 */
export default function RegistroDeAtividade({ atividade, processando = false, onFiltrar }) {
    const dados = typeof atividade === 'object' && atividade !== null ? atividade : {};
    const dias = lista(dados.dias);
    const total = inteiro(dados.total);
    const pagina = Math.max(1, inteiro(dados.pagina) || 1);
    const paginas = Math.max(1, inteiro(dados.paginas) || 1);

    const filtros = typeof dados.filtros === 'object' && dados.filtros !== null ? dados.filtros : {};
    const opcoes = typeof dados.opcoes === 'object' && dados.opcoes !== null ? dados.opcoes : {};

    const mudar = (chave, valor) => onFiltrar?.({ ...filtros, [chave]: valor, pagina: 1 });
    const irPara = (n) => onFiltrar?.({ ...filtros, pagina: n });

    const temFiltro = ['de', 'ate', 'fonte', 'origem', 'resultado', 'produto_id']
        .some((k) => filtros[k] !== null && filtros[k] !== undefined && filtros[k] !== '');

    return (
        <div>
            {/* ⚠️ A contagem diz a UNIDADE e o RECORTE: "registros" (não "eventos", que o
                vocabulário do portão usa para outra coisa), e "no recorte" quando há filtro —
                sem isso a pessoa lê o número filtrado como se fosse o total da loja. */}
            <p className="mt-1 text-[12px] text-pub-txt-3">
                {total === 0
                    ? (temFiltro ? 'Nenhum registro neste recorte.' : 'O agente ainda não fez nada nesta loja.')
                    : `${total} ${total === 1 ? 'registro' : 'registros'}${temFiltro ? ' no recorte' : ''}.`}
            </p>

            <div className="mt-3 flex flex-wrap items-end gap-3">
                <Filtro rotulo="Tipo de registro" valor={filtros.fonte} opcoes={opcoes.fontes} onMudar={(v) => mudar('fonte', v)} />
                <Filtro rotulo="Quem fez" valor={filtros.origem} opcoes={opcoes.origens} onMudar={(v) => mudar('origem', v)} largura="w-[140px]" />
                <Filtro rotulo="Resultado do preparo" valor={filtros.resultado} opcoes={opcoes.resultados} onMudar={(v) => mudar('resultado', v)} largura="w-[190px]" />

                <label className="text-[12px] text-pub-txt-3">
                    De
                    <input
                        type="date" value={texto(filtros.de)}
                        onChange={(ev) => mudar('de', ev.target.value === '' ? null : ev.target.value)}
                        className="mt-1 block w-[150px] rounded-md border border-pub-line-strong bg-pub-sunken px-2.5 py-1.5 text-[13px] text-pub-txt"
                    />
                </label>
                <label className="text-[12px] text-pub-txt-3">
                    Até
                    <input
                        type="date" value={texto(filtros.ate)}
                        onChange={(ev) => mudar('ate', ev.target.value === '' ? null : ev.target.value)}
                        className="mt-1 block w-[150px] rounded-md border border-pub-line-strong bg-pub-sunken px-2.5 py-1.5 text-[13px] text-pub-txt"
                    />
                </label>

                {/* ⚠️ "Limpar filtros" só aparece COM filtro — botão que não muda nada é ruído, e
                    é a mesma regra da fila de Aprovação (187-06). */}
                {temFiltro && (
                    <button
                        type="button" disabled={processando}
                        onClick={() => onFiltrar?.({ pagina: 1 })}
                        className="text-[12px] text-pub-txt-2 underline underline-offset-[3px] disabled:opacity-40"
                    >
                        Limpar filtros
                    </button>
                )}
            </div>

            {/* ⚠️ O filtro "Resultado do preparo" se aplica SÓ ao preparo, e a tela DIZ isso: ele
                deixa de fora configuração e aprovação, que não têm estado de preparo. Filtrar e
                ver sumir o que não tem a coluna faria a pessoa achar que perdeu registro. */}
            {texto(filtros.resultado) !== '' && (
                <p className="mt-2 text-[12px] text-pub-warn-txt">
                    O resultado só existe no preparo — configuração e aprovação ficam de fora deste recorte.
                </p>
            )}

            {dias.length > 0 && (
                <div className="mt-4">
                    {dias.map((d) => {
                        // ⚠️ DENTRO do callback.
                        const dia = texto(d?.dia);
                        const rotuloDoDia = texto(d?.rotulo, dia);
                        const linhasDoDia = lista(d?.linhas);

                        return (
                            <section key={dia || rotuloDoDia} className="mt-4 first:mt-0">
                                <h3 className="text-[12px] font-semibold uppercase tracking-wide text-pub-txt-4">
                                    {rotuloDoDia}
                                </h3>
                                <ul className="mt-1.5">
                                    {linhasDoDia.map((l, i) => (
                                        <LinhaDaAtividade key={texto(l?.id, `l-${i}`)} linha={l} />
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                </div>
            )}

            {/* ⚠️ A paginação aparece só com mais de uma página, e diz ONDE a pessoa está. */}
            {paginas > 1 && (
                <div className="mt-4 flex items-center gap-3 border-t border-pub-line-subtle pt-3">
                    <button
                        type="button" disabled={processando || pagina <= 1} onClick={() => irPara(pagina - 1)}
                        className="rounded-md border border-pub-line-strong px-2.5 py-1 text-[12px] text-pub-txt-2 disabled:opacity-40"
                    >
                        Anterior
                    </button>
                    <span className="text-[12px] tabular-nums text-pub-txt-3">Página {pagina} de {paginas}</span>
                    <button
                        type="button" disabled={processando || pagina >= paginas} onClick={() => irPara(pagina + 1)}
                        className="rounded-md border border-pub-line-strong px-2.5 py-1 text-[12px] text-pub-txt-2 disabled:opacity-40"
                    >
                        Próxima
                    </button>
                </div>
            )}
        </div>
    );
}
