import { cn } from '@/lib/utils';
import { useEffect, useRef, useState } from 'react';
import { Sparkles, Loader2, Check, AlertTriangle, ChevronDown, ChevronRight, FileCheck2 } from 'lucide-react';

// Um pouco acima dos 15 min em que o servidor encerra a análise: quem decide
// é o servidor; este teto só vale se nem ele responder.
const LIMITE_ESPERA_MS = 17 * 60 * 1000;

/**
 * "Anunciar por IA" — cadastra o anúncio inteiro e deixa em RASCUNHO.
 *
 * O publicador informa produto e especificações (ou escolhe um produto da
 * planilha do cliente); a loja vem da conta ML conectada (o servidor deriva,
 * não aceita do cliente). A IA roda a análise MAG T8 (títulos e descrição) e,
 * desde 30/09/2026, escolhe a categoria, preenche a ficha técnica, variações,
 * pacote e garantia, e grava tudo como rascunho — que abre sozinho no wizard.
 *
 * NUNCA publica: fotos, conferência e o clique de publicar são do publicador.
 *
 * Assíncrono por necessidade — a geração levou 103s na medição de 21/09/2026.
 * Por isso: POST enfileira, e daqui em diante é polling. Não existe versão
 * síncrona disso que sobreviva a um request.
 */
export default function PainelAnunciarIa({ empresa, analiseInicial, produtos = [], onAplicarTitulo, onAplicarDescricao, onAbrirRascunho }) {
    // Estado inicial vem do servidor quando existe análise recente: é isso que
    // faz o F5 não parecer perda de trabalho. A geração leva minutos e mora no
    // banco — recarregar a página nunca cancelou nada, só escondia.
    const inicial = analiseInicial ?? null;

    const [aberto, setAberto]   = useState(Boolean(inicial));
    const [produto, setProduto] = useState(inicial?.produto ?? '');
    const [specs, setSpecs]     = useState(inicial?.specs ?? '');
    // SKU da planilha do cliente (opcional). O servidor lê preço, estoque e
    // medidas do pacote a partir dele — o navegador só diz qual produto.
    const [sku, setSku]         = useState(inicial?.sku ?? '');

    const [estado, setEstado]   = useState(
        !inicial ? 'parado'
            : inicial.em_andamento ? 'gerando'
            : inicial.status === 'erro' ? 'erro'
            : 'pronto',
    );
    const [erro, setErro]       = useState(inicial?.erro ?? null);
    // Parciais contam: a análise chega antes dos títulos, que chegam antes da
    // descrição. Mostrar o que já existe é melhor que esconder tudo até o fim.
    const [dados, setDados]     = useState(inicial ?? null);
    const [etapa, setEtapa]     = useState(inicial?.etapa ?? null);
    const [inicioEm, setInicioEm] = useState(inicial?.started_at ?? null);
    const [segundos, setSegundos] = useState(0);
    const [verAnalise, setVerAnalise] = useState(false);
    const [aplicado, setAplicado] = useState({});

    const pollRef   = useRef(null);
    const cronoRef  = useRef(null);
    // Quando o acompanhamento começou, para o teto de espera abaixo.
    const pollDesdeRef = useRef(null);
    // Rascunho já aberto automaticamente — abre UMA vez, não a cada consulta.
    const abertoAutoRef = useRef(null);

    // O relógio conta desde o `started_at` do SERVIDOR, não desde o momento em
    // que este componente montou. Sem isso, um F5 zerava a contagem e passava
    // a impressão de que a geração tinha recomeçado do nada.
    function tique(desde) {
        if (!desde) { setSegundos(s => s + 1); return; }
        setSegundos(Math.max(0, Math.round((Date.now() - new Date(desde).getTime()) / 1000)));
    }

    const ETAPA_LABEL = {
        analise:   '1/5 · analisando o produto e a persona',
        titulos:   '2/5 · escrevendo os títulos',
        descricao: '3/5 · escrevendo a descrição',
        ficha:     '4/5 · escolhendo a categoria e preenchendo a ficha técnica',
        rascunho:  '5/5 · salvando o rascunho',
    };

    // Produto da planilha do cliente: nome e especificações entram nos campos
    // (o publicador ainda pode editar). Medidas NÃO entram nas especificações:
    // na planilha são do pacote, e a IA as tomaria por medida do produto.
    function escolherProdutoCliente(valor) {
        setSku(valor);
        const p = produtos.find(x => x.sku === valor);
        if (!p) return;
        setProduto(p.produto ?? '');
        setSpecs([p.especificacoes, p.descricao].map(t => (t ?? '').trim()).filter(Boolean).join('\n'));
    }

    const produtoCliente = produtos.find(x => x.sku === sku) ?? null;

    // Um único lugar para desarmar os dois timers. Sem isto, sair da etapa no
    // meio da geração deixa polling rodando contra um componente desmontado.
    function pararTimers() {
        if (pollRef.current)  { clearInterval(pollRef.current);  pollRef.current = null; }
        if (cronoRef.current) { clearInterval(cronoRef.current); cronoRef.current = null; }
        pollDesdeRef.current = null;
    }

    // Retoma o acompanhamento de uma geração que já estava correndo quando a
    // página foi recarregada. Sem isto o card ficava em "Gerando…" para sempre,
    // porque ninguém mais perguntava ao servidor como ela terminou.
    useEffect(() => {
        if (inicial?.em_andamento) {
            tique(inicial.started_at);
            cronoRef.current = setInterval(() => tique(inicial.started_at), 1000);
            pollRef.current  = setInterval(() => consultar(inicial.id), 5000);
            consultar(inicial.id);
        }

        return pararTimers;
    }, []);

    async function gerar() {
        if (!produto.trim()) return;

        pararTimers();
        setEstado('gerando');
        setErro(null);
        setDados(null);
        setEtapa(null);
        setInicioEm(null);
        setAplicado({});
        setSegundos(0);
        abertoAutoRef.current = null;

        // Sem `started_at` ainda (o job nem começou): conta local até a
        // primeira consulta trazer o horário do servidor.
        cronoRef.current = setInterval(() => setSegundos(s => s + 1), 1000);

        try {
            const { data } = await window.axios.post(route('mlb.anuncios.ia.analise.store'), {
                company_id: empresa.company_id ?? empresa.id,
                produto: produto.trim(),
                specs: specs.trim() || null,
                sku: sku || null,
            });

            // 5s entre consultas: a geração leva ~100s, então perguntar mais
            // vezes só gera ruído no log sem chegar mais rápido.
            pollRef.current = setInterval(() => consultar(data.id), 5000);
            consultar(data.id);
        } catch (e) {
            pararTimers();
            setEstado('erro');
            setErro(e?.response?.data?.message ?? 'Não foi possível iniciar a geração.');
        }
    }

    async function consultar(id) {
        // Teto de espera no navegador. O servidor já encerra a análise em 15
        // min (MlAnuncioIaAnalise::LIMITE_MINUTOS); isto cobre o caso em que
        // nem o servidor responde. Sem teto, a tela perguntava para sempre.
        pollDesdeRef.current ??= Date.now();
        if (Date.now() - pollDesdeRef.current > LIMITE_ESPERA_MS) {
            pararTimers();
            setEstado('erro');
            setErro('A geração passou do tempo limite e foi interrompida. Tente novamente.');
            return;
        }

        try {
            const { data } = await window.axios.get(route('mlb.anuncios.ia.analise.status', { analise: id }));

            // Parciais entram na tela na hora: a análise aparece enquanto os
            // títulos ainda estão saindo.
            setDados(data);
            setEtapa(data.etapa ?? null);
            if (data.started_at && !inicioEm) setInicioEm(data.started_at);

            if (data.em_andamento) return;

            pararTimers();

            if (data.status === 'erro') {
                setEstado('erro');
                setErro(data.erro ?? 'A geração falhou.');
                return;
            }

            setDados(data);
            setEstado('pronto');

            // Terminou com o rascunho gravado: abre no wizard na hora. Só
            // aqui (fim acompanhado ao vivo) — num F5 de análise já pronta o
            // publicador pode estar editando outra coisa; lá vira botão.
            if (data.rascunho && abertoAutoRef.current !== data.rascunho.id) {
                abertoAutoRef.current = data.rascunho.id;
                onAbrirRascunho?.(data.rascunho);
            }
        } catch {
            pararTimers();
            setEstado('erro');
            setErro('Perdi o contato com a geração. Tente novamente.');
        }
    }

    function aplicarTitulo(t, i) {
        onAplicarTitulo?.(t.texto);
        setAplicado(a => ({ ...a, [`t${i}`]: true }));
    }

    function aplicarDescricao() {
        onAplicarDescricao?.(dados.descricao);
        setAplicado(a => ({ ...a, desc: true }));
    }

    return (
        <section className="mb-4 rounded-xl border border-violet-500/20 bg-violet-500/[0.04] p-4">
            <button
                type="button"
                onClick={() => setAberto(a => !a)}
                className="flex w-full items-center gap-2 text-left"
            >
                <Sparkles className="h-4 w-4 shrink-0 text-violet-300" />
                <span className="text-sm font-semibold text-white">Anunciar por IA</span>
                <span className="text-[11px] text-white/35">cadastra tudo e deixa em rascunho</span>
                {aberto
                    ? <ChevronDown className="ml-auto h-4 w-4 text-white/30" />
                    : <ChevronRight className="ml-auto h-4 w-4 text-white/30" />}
            </button>

            {aberto && (
                <div className="mt-4 space-y-3">
                    {produtos.length > 0 && (
                        <div>
                            <label className="mb-1 block text-[11px] text-white/50">
                                Produto da planilha do cliente
                                <span className="ml-1 text-white/25">— opcional</span>
                            </label>
                            <select
                                value={sku}
                                onChange={e => escolherProdutoCliente(e.target.value)}
                                disabled={estado === 'gerando'}
                                className="w-full rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-sm text-white focus:outline-none disabled:opacity-50"
                            >
                                <option value="">Nenhum — vou descrever o produto</option>
                                {produtos.filter(p => p.sku).map(p => (
                                    <option key={p.sku} value={p.sku}>{p.sku} · {p.produto || '—'}</option>
                                ))}
                            </select>
                            {produtoCliente && (
                                <p className={cn('mt-1 text-[11px]', produtoCliente.tem_preco ? 'text-white/40' : 'text-amber-300/80')}>
                                    {produtoCliente.tem_preco
                                        ? 'Preço, estoque e medidas do pacote vêm da planilha do cliente.'
                                        : 'Sem custo na precificação do cliente: o rascunho sai sem preço.'}
                                </p>
                            )}
                        </div>
                    )}

                    <div>
                        <label className="mb-1 block text-[11px] text-white/50">Nome do produto</label>
                        <input
                            value={produto}
                            onChange={e => setProduto(e.target.value)}
                            placeholder="Ex.: Cadeira Gamer Ergonômica Reclinável 180 graus"
                            className="w-full rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-sm text-white placeholder-white/25 focus:outline-none"
                        />
                    </div>

                    <div>
                        <label className="mb-1 block text-[11px] text-white/50">Empresa / loja</label>
                        {/* Somente leitura: vem da conta ML conectada. O servidor
                            deriva esse valor e ignora o que o navegador mandar. */}
                        <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2 text-sm text-white/50">
                            {empresa?.nome ?? '—'}
                        </div>
                    </div>

                    <div>
                        <label className="mb-1 block text-[11px] text-white/50">
                            Especificações do produto
                            <span className="ml-1 text-white/25">— a IA se baseia nelas e não inventa</span>
                        </label>
                        <textarea
                            value={specs}
                            onChange={e => setSpecs(e.target.value)}
                            rows={5}
                            placeholder={'Estrutura em aço carbono\nEncosto reclinável até 180 graus\nSuporta até 150kg'}
                            className="w-full rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-sm text-white placeholder-white/25 focus:outline-none"
                        />
                    </div>

                    <button
                        type="button"
                        onClick={gerar}
                        disabled={estado === 'gerando' || !produto.trim()}
                        className="flex items-center gap-2 rounded-lg bg-violet-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                    >
                        {estado === 'gerando'
                            ? <><Loader2 className="h-4 w-4 animate-spin" /> Gerando… {segundos}s</>
                            : <><Sparkles className="h-4 w-4" /> Gerar anúncio completo</>}
                    </button>

                    <p className="text-[11px] text-white/35">
                        A IA preenche título, categoria, ficha técnica, variações, descrição, pacote e
                        garantia e <b className="text-white/55">salva como rascunho</b>. Ela não publica:
                        você confere, envia as fotos e publica.
                    </p>

                    {estado === 'gerando' && (
                        // Expectativa honesta e sinal de vida. Sem dizer em que
                        // etapa está, "Gerando…" por minutos parece travamento —
                        // foi exatamente assim que o publicador desistiu e deu F5.
                        <div className="space-y-1">
                            {etapa && (
                                <p className="text-[11px] text-violet-300/80">
                                    Etapa {ETAPA_LABEL[etapa] ?? etapa}
                                </p>
                            )}
                            <p className="text-[11px] text-white/40">
                                São cinco etapas e leva alguns minutos. Cada uma aparece aqui assim
                                que fica pronta, e o rascunho abre sozinho no fim. Pode recarregar a
                                página — a geração roda no servidor e você volta para o mesmo ponto.
                            </p>
                        </div>
                    )}

                    {/* O rascunho que a IA gravou. Abre sozinho quando a geração
                        termina com a tela aberta; depois de um F5 fica o botão. */}
                    {dados?.rascunho && (
                        <div className="rounded-lg border border-emerald-500/25 bg-emerald-500/[0.06] px-3 py-2.5">
                            <div className="flex items-start gap-2">
                                <FileCheck2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <p className="text-[12px] font-semibold text-emerald-300">
                                        {dados.rascunho.status === 'publicado'
                                            ? `Rascunho #${dados.rascunho.id} — já publicado`
                                            : `Rascunho #${dados.rascunho.id} salvo — não publicado`}
                                    </p>
                                    {dados.ficha?.caminho && (
                                        <p className="truncate text-[11px] text-white/55">{dados.ficha.caminho}</p>
                                    )}
                                    {dados.ficha && (
                                        <p className="text-[11px] text-white/45">
                                            {dados.ficha.atributos} atributo{dados.ficha.atributos === 1 ? '' : 's'} da ficha
                                            {dados.ficha.variacoes > 0 && ` · ${dados.ficha.variacoes} variaç${dados.ficha.variacoes === 1 ? 'ão' : 'ões'}`}
                                            {dados.ficha.pacote_completo ? ' · pacote preenchido' : ' · pacote incompleto'}
                                        </p>
                                    )}
                                    {dados.ficha?.obrigatorios_faltando?.length > 0 && (
                                        <p className="text-[11px] text-amber-300/80">
                                            Falta preencher: {dados.ficha.obrigatorios_faltando.join(', ')}
                                        </p>
                                    )}
                                    {dados.ficha?.aviso && (
                                        <p className="text-[11px] text-amber-300/80">{dados.ficha.aviso}</p>
                                    )}
                                    <p className="text-[11px] text-white/40">
                                        Confira os campos com o selo IA, envie as fotos e publique quando estiver certo.
                                    </p>
                                </div>
                                {onAbrirRascunho && dados.rascunho.status !== 'publicado' && (
                                    <button
                                        type="button"
                                        onClick={() => onAbrirRascunho(dados.rascunho)}
                                        className="shrink-0 rounded-md border border-emerald-500/30 px-2 py-1 text-[11px] font-medium text-emerald-300 hover:bg-emerald-500/10"
                                    >
                                        Abrir rascunho
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    {estado === 'erro' && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                            <p className="text-[12px] text-red-300">{erro}</p>
                        </div>
                    )}

                    {/* Mostra o que JA existe, mesmo com a geracao correndo:
                        a analise chega antes dos titulos, que chegam antes da
                        descricao. Esconder tudo ate o fim desperdicaria minutos
                        em que o publicador ja poderia estar lendo. */}
                    {dados && (dados.titulos?.length || dados.descricao || Object.keys(dados.analise ?? {}).length) && (
                        <div className="space-y-4 border-t border-white/[0.06] pt-4">
                            <div>
                                <p className="mb-2 text-[11px] font-medium text-white/50">
                                    Títulos sugeridos — clique para usar
                                </p>
                                <div className="space-y-1.5">
                                    {(dados.titulos ?? []).map((t, i) => (
                                        <button
                                            key={i}
                                            type="button"
                                            onClick={() => aplicarTitulo(t, i)}
                                            className="flex w-full items-start gap-2 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2 text-left hover:border-violet-400/40"
                                        >
                                            <span className="flex-1 text-[13px] text-white">
                                                {t.texto}
                                                {/* Aviso específico: o motivo mais comum de
                                                    reprovação é o modelo enfiar a loja no
                                                    fim para fechar os 60 caracteres. */}
                                                {t.tem_loja && (
                                                    <span className="mt-0.5 block text-[10px] text-amber-300/80">
                                                        contém o nome da loja
                                                    </span>
                                                )}
                                            </span>
                                            {/* Tudo medido no servidor: tamanho, preposição e
                                                nome da loja. O modelo erra a própria conta. */}
                                            <span className={cn(
                                                'shrink-0 rounded px-1.5 py-0.5 text-[10px]',
                                                t.dentro_da_regra
                                                    ? 'bg-emerald-500/10 text-emerald-400'
                                                    : 'bg-amber-500/10 text-amber-300',
                                            )}>
                                                {t.caracteres}
                                            </span>
                                            {aplicado[`t${i}`] && <Check className="h-3.5 w-3.5 shrink-0 text-emerald-400" />}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {dados.descricao && (
                                <div>
                                    <div className="mb-1.5 flex items-center justify-between">
                                        <p className="text-[11px] font-medium text-white/50">Descrição</p>
                                        <button
                                            type="button"
                                            onClick={aplicarDescricao}
                                            className="flex items-center gap-1 text-[11px] text-violet-300 hover:text-violet-200"
                                        >
                                            {aplicado.desc ? <><Check className="h-3 w-3" /> aplicada</> : 'usar esta descrição'}
                                        </button>
                                    </div>
                                    <pre className="max-h-40 overflow-y-auto whitespace-pre-wrap rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2 font-sans text-[12px] leading-relaxed text-white/70">
                                        {dados.descricao}
                                    </pre>
                                </div>
                            )}

                            <div>
                                <button
                                    type="button"
                                    onClick={() => setVerAnalise(v => !v)}
                                    className="flex items-center gap-1 text-[11px] text-white/45 hover:text-white/70"
                                >
                                    {verAnalise ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
                                    Análise estratégica completa
                                </button>

                                {verAnalise && (
                                    <div className="mt-2 space-y-2 rounded-lg border border-white/[0.06] bg-ecf-bg p-3">
                                        {Object.entries(dados.analise ?? {}).map(([chave, valor]) => (
                                            <div key={chave}>
                                                <p className="text-[10px] uppercase tracking-wide text-white/35">
                                                    {chave.replace(/_/g, ' ')}
                                                </p>
                                                <p className="text-[12px] leading-relaxed text-white/70">
                                                    {Array.isArray(valor) ? valor.join(' · ') : String(valor)}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <p className="text-[10px] text-white/25">
                                {dados.modelo}{dados.duracao_ms ? ` · ${Math.round(dados.duracao_ms / 1000)}s` : ''}
                            </p>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
