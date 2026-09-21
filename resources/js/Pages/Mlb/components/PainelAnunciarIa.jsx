import { cn } from '@/lib/utils';
import { useEffect, useRef, useState } from 'react';
import { Sparkles, Loader2, Check, AlertTriangle, ChevronDown, ChevronRight } from 'lucide-react';

/**
 * "Anunciar por IA" — metodologia MAG T8, Parte 1 (Análise Estratégica).
 *
 * O publicador informa produto e especificações; a loja vem da conta ML
 * conectada (o servidor deriva, não aceita do cliente). A IA devolve a análise
 * e os campos prontos: títulos e descrição.
 *
 * Assíncrono por necessidade — a geração levou 103s na medição de 21/09/2026.
 * Por isso: POST enfileira, e daqui em diante é polling. Não existe versão
 * síncrona disso que sobreviva a um request.
 */
export default function PainelAnunciarIa({ empresa, onAplicarTitulo, onAplicarDescricao }) {
    const [aberto, setAberto]   = useState(false);
    const [produto, setProduto] = useState('');
    const [specs, setSpecs]     = useState('');

    const [estado, setEstado]   = useState('parado'); // parado | gerando | pronto | erro
    const [erro, setErro]       = useState(null);
    const [dados, setDados]     = useState(null);
    const [segundos, setSegundos] = useState(0);
    const [verAnalise, setVerAnalise] = useState(false);
    const [aplicado, setAplicado] = useState({});

    const pollRef   = useRef(null);
    const cronoRef  = useRef(null);

    // Um único lugar para desarmar os dois timers. Sem isto, sair da etapa no
    // meio da geração deixa polling rodando contra um componente desmontado.
    function pararTimers() {
        if (pollRef.current)  { clearInterval(pollRef.current);  pollRef.current = null; }
        if (cronoRef.current) { clearInterval(cronoRef.current); cronoRef.current = null; }
    }

    useEffect(() => pararTimers, []);

    async function gerar() {
        if (!produto.trim()) return;

        pararTimers();
        setEstado('gerando');
        setErro(null);
        setDados(null);
        setAplicado({});
        setSegundos(0);

        cronoRef.current = setInterval(() => setSegundos(s => s + 1), 1000);

        try {
            const { data } = await window.axios.post(route('mlb.anuncios.ia.analise.store'), {
                company_id: empresa.company_id ?? empresa.id,
                produto: produto.trim(),
                specs: specs.trim() || null,
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
        try {
            const { data } = await window.axios.get(route('mlb.anuncios.ia.analise.status', { analise: id }));

            if (data.em_andamento) return;

            pararTimers();

            if (data.status === 'erro') {
                setEstado('erro');
                setErro(data.erro ?? 'A geração falhou.');
                return;
            }

            setDados(data);
            setEstado('pronto');
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
                <span className="text-[11px] text-white/35">metodologia MAG T8</span>
                {aberto
                    ? <ChevronDown className="ml-auto h-4 w-4 text-white/30" />
                    : <ChevronRight className="ml-auto h-4 w-4 text-white/30" />}
            </button>

            {aberto && (
                <div className="mt-4 space-y-3">
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
                            : <><Sparkles className="h-4 w-4" /> Gerar análise</>}
                    </button>

                    {estado === 'gerando' && (
                        // Expectativa honesta: sem isto o publicador acha que travou
                        // no segundo 30 e recarrega a página no meio da geração.
                        <p className="text-[11px] text-white/40">
                            Costuma levar cerca de 2 minutos. Pode continuar preenchendo o resto —
                            o resultado aparece aqui quando ficar pronto.
                        </p>
                    )}

                    {estado === 'erro' && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                            <p className="text-[12px] text-red-300">{erro}</p>
                        </div>
                    )}

                    {estado === 'pronto' && dados && (
                        <div className="space-y-4 border-t border-white/[0.06] pt-4">
                            <div>
                                <p className="mb-2 text-[11px] font-medium text-white/50">
                                    Títulos sugeridos — clique para usar
                                </p>
                                <div className="space-y-1.5">
                                    {dados.titulos.map((t, i) => (
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
