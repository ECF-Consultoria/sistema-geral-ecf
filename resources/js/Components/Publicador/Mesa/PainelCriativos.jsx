import { useEffect, useRef, useState } from 'react';
import { Check, Loader2, RefreshCw, Sparkles, Upload, X } from 'lucide-react';
import { BotaoAcao } from './botoes';
import { AREA, LINK } from './comum';

// ─── Painel do kit de criativos por IA (Fase 165, D-08) ─────────────────────
//
// Abre logo abaixo do bloco de fotos de onde foi pedido (cartão da variação
// ou "Fotos para todas as variações", na etapa Detalhes) — a mesma
// experiência do painel do assistente antigo (planejar → confirmar custo →
// grade → regenerar → aprovar), num componente novo, na identidade do editor
// em 3 etapas. Apresentação pura: nenhum `route(`, nenhum axios — tudo o
// que chama o servidor mora em `useCriativosDoPublicador` (prop `c`).
//
// As imagens usadas entram no FIM destas fotos e entram no anúncio do
// Mercado Livre só na publicação (D-04) — o painel nunca diz que subiu ao ML
// na hora.

/** Mesmas 5 chaves/textos do painel antigo (`PainelCriativosIa.jsx`), copiadas — não importado de lá (D-08). */
const ETAPA_LABEL = {
    contexto: 'montando o contexto',
    truth: 'conferindo os fatos do produto',
    prompt: 'escrevendo o prompt',
    geracao: 'gerando a imagem',
    salvando: 'salvando',
};

const CUSTO_POR_IMAGEM_USD = 0.101;
const dolares = (v) => v.toFixed(2).replace('.', ',');

/**
 * Um cartão da grade — 165-04-SUMMARY.md: o presenter manda `validacao_status`/`pode_aprovar`/
 * `exige_confirmacao_risco` por slot (gate da Fase 162, que o `165-06-PLAN.md` não previa porque
 * é anterior a ela). Uma imagem reprovada pelo juiz Gemini NUNCA pode parecer aprovável sem
 * confirmar o risco — por isso "Usar no anúncio" só aparece com `pode_aprovar`, e `exige_
 * confirmacao_risco` abre a pergunta explícita antes de mandar `confirmar_risco: true`.
 */
function CartaoSlot({ s, c, disabled, podeRegenerarKit }) {
    const [confirmandoRisco, setConfirmandoRisco] = useState(false);
    const ocupado = disabled || !! c.processando;
    const emAndamento = c.processando === `aprovar-${s.indice}` || c.processando === `regenerar-${s.indice}`;
    const podeRegenerar = podeRegenerarKit && (s.status === 'pronto' || s.status === 'erro') && s.regeneracoes_restantes > 0;
    const reprovada = s.validacao_status === 'reprovada';
    const pendenteValidacao = s.validacao_status === 'pendente';

    return (
        <div data-slot-criativo={s.indice} className="space-y-1.5 rounded-lg border border-white/20 bg-black/20 p-2.5">
            <p className="text-[13px] font-bold text-white/90">{s.indice}. {s.rotulo}</p>
            <p className="text-[11px] font-normal text-white/50">{s.objetivo}</p>

            {s.imagem_url ? (
                <img src={s.imagem_url} alt={s.rotulo} loading="lazy" className="w-full rounded object-cover" />
            ) : (
                <p className="text-[11px] font-normal text-white/50">
                    {s.status === 'pendente' && 'Na fila'}
                    {s.status === 'rodando' && `${ETAPA_LABEL[s.etapa] ?? 'gerando'}…`}
                    {s.status === 'erro' && s.erro}
                </p>
            )}

            {reprovada && (
                <div className="space-y-1 rounded border border-red-400/40 bg-red-500/10 p-1.5">
                    <p className="text-[11px] font-normal text-red-300">{s.validacao_mensagem ?? 'Risco apontado pela validação automática.'}</p>
                    {s.validacao_problemas?.length > 0 && (
                        <ul className="space-y-0.5">
                            {s.validacao_problemas.map((p, i) => <li key={i} className="text-[11px] font-normal text-white/50">{p.explicacao}</li>)}
                        </ul>
                    )}
                </div>
            )}
            {pendenteValidacao && <p className="text-[11px] font-normal text-white/50">Validando automaticamente…</p>}

            {s.status === 'pronto' && s.pode_aprovar && (
                <BotaoAcao disabled={ocupado} onClick={() => c.aprovar(s.indice)}>
                    {c.processando === `aprovar-${s.indice}` ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Usar no anúncio
                </BotaoAcao>
            )}

            {s.status === 'pronto' && s.exige_confirmacao_risco && ! confirmandoRisco && (
                <button type="button" onClick={() => setConfirmandoRisco(true)} disabled={ocupado} className={LINK}>
                    Aprovar mesmo assim
                </button>
            )}
            {s.status === 'pronto' && s.exige_confirmacao_risco && confirmandoRisco && (
                <div className="space-y-1">
                    <p className="text-[11px] font-normal text-red-300">A validação automática apontou risco nesta imagem. Usar ela mesmo assim no anúncio?</p>
                    <div className="flex items-center gap-3">
                        <button type="button" onClick={() => c.aprovar(s.indice, true)} disabled={ocupado} className={LINK}>Sim, usar esta imagem</button>
                        <button type="button" onClick={() => setConfirmandoRisco(false)} disabled={ocupado} className={LINK}>Cancelar</button>
                    </div>
                </div>
            )}

            {s.status === 'aprovado' && s.no_anuncio && (
                <p className="flex items-center gap-1 text-[11px] font-normal text-emerald-400"><Check size={12} /> No anúncio</p>
            )}
            {s.status === 'aprovado' && ! s.no_anuncio && (
                <button type="button" onClick={() => c.aprovar(s.indice)} disabled={ocupado} className={LINK}>Pôr de novo no anúncio</button>
            )}

            {podeRegenerar && (
                <div className="space-y-1">
                    <label className="text-[11px] font-normal text-white/50" htmlFor={`motivo-slot-${s.indice}`}>O que não ficou bom? (opcional)</label>
                    <textarea
                        id={`motivo-slot-${s.indice}`}
                        rows={2}
                        maxLength={300}
                        value={c.motivos[s.indice] ?? ''}
                        onChange={(e) => c.mudarMotivo(s.indice, e.target.value)}
                        placeholder="ex.: o produto ficou pequeno demais no quadro"
                        disabled={ocupado}
                        className={AREA}
                    />
                    <button type="button" onClick={() => c.regenerar(s.indice)} disabled={ocupado} className={LINK}>
                        {emAndamento ? <Loader2 size={12} className="animate-spin" /> : <RefreshCw size={12} />} Gerar de novo
                    </button>
                    <p className="text-[11px] font-normal text-white/35">pode gerar de novo mais {s.regeneracoes_restantes} vez(es)</p>
                </div>
            )}
        </div>
    );
}

/**
 * `c` é o valor de `useCriativosDoPublicador`. `sugeridas` são as fotos do anúncio com arquivo
 * guardado (`{ id, url }`), calculadas pelo bloco que montou o painel. `disabled` só desabilita
 * as ações — o painel não se desmonta (a releitura do rascunho depois de "Usar no anúncio" deixa
 * o editor só-leitura por um instante).
 */
export default function PainelCriativos({ c, titulo, sugeridas = [], fotosNoGrupo = 0, maxFotos = 10, disabled = false }) {
    const [marcadas, setMarcadas] = useState(() => new Set(sugeridas.slice(0, 14).map((f) => f.id)));
    const [arquivos, setArquivos] = useState([]);
    const arquivoRef = useRef(null);

    // Chave ESTÁVEL (ids, não a identidade do array): `sugeridas` chega pronta do `BlocoDeFotos`
    // (que já memoiza, 261005-si3), mas depender da IDENTIDADE do array aqui de novo reabriria o
    // mesmo risco caso algum chamador futuro pare de memoizar — o efeito só deve reagir quando as
    // fotos sugeridas de fato mudam de conteúdo, nunca a cada render.
    const chaveSugeridas = sugeridas.map((f) => f.id).join(',');
    useEffect(() => {
        if (c.fase === 'escolhendo') setMarcadas(new Set(sugeridas.slice(0, 14).map((f) => f.id)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [c.fase, chaveSugeridas]);

    if (c.fase === 'parado') return null;

    const alternar = (id) => setMarcadas((m) => {
        const novo = new Set(m);
        if (novo.has(id)) novo.delete(id); else novo.add(id);

        return novo;
    });
    const tirarArquivo = (indice) => setArquivos((a) => a.filter((_, i) => i !== indice));
    const totalEscolhido = marcadas.size + arquivos.length;
    const naoPodePlanejar = disabled || !! c.processando || totalEscolhido === 0 || totalEscolhido > 14;

    const kit = c.kit;
    const temSlots = kit !== null && (kit?.slots?.length ?? 0) > 0;
    const podeRegenerarKit = kit !== null && kit.status !== 'aprovado' && (kit.referencias?.length ?? 0) > 0;
    const podeUsarKit = kit !== null && kit.prontas > 0 && kit.prontas + kit.aprovadas >= kit.minimo_aprovadas;

    return (
        <section data-painel-criativos={c.alvo?.grupo} aria-label={`Gerar fotos com IA — ${titulo}`} className="mt-3 rounded-lg border border-white/20 bg-black/40 p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h5 className="flex items-center gap-1.5 text-[15px] font-bold text-white"><Sparkles size={15} /> Gerar fotos com IA</h5>
                <button type="button" onClick={c.fechar} className={LINK}><X size={14} /> Fechar</button>
            </div>

            {c.erro && (
                <div className="mb-3 flex items-start justify-between gap-2 rounded-lg border border-red-400/40 bg-red-500/10 px-3 py-2">
                    <p className="text-[13px] font-normal text-red-300">{c.erro}</p>
                    <button type="button" onClick={c.limparErro} className={LINK}>Fechar aviso</button>
                </div>
            )}

            <div aria-live="polite" className="space-y-3">
                {c.fase === 'carregando' && (
                    <p className="flex items-center gap-2 text-[13px] font-normal text-white/70"><Loader2 size={14} className="animate-spin" /> Abrindo…</p>
                )}

                {c.fase === 'escolhendo' && (
                    <div className="space-y-3">
                        {sugeridas.length === 0 ? (
                            <p className="text-[13px] font-normal text-white/50">Este bloco ainda não tem foto guardada — envie uma foto do produto.</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {sugeridas.map((f) => (
                                    <label key={f.id} className="flex items-center gap-1.5 text-[13px] font-normal text-white/70">
                                        <input type="checkbox" checked={marcadas.has(f.id)} onChange={() => alternar(f.id)}
                                            className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" />
                                        <img src={f.url} alt={`Foto ${f.id}`} className="h-10 w-10 rounded object-cover" />
                                    </label>
                                ))}
                            </div>
                        )}

                        <div>
                            <button type="button" onClick={() => arquivoRef.current?.click()} className={LINK}>
                                <Upload size={14} /> Enviar foto do computador
                            </button>
                            <input ref={arquivoRef} type="file" accept="image/jpeg,image/png" multiple className="hidden"
                                onChange={(e) => { setArquivos((a) => [...a, ...Array.from(e.target.files ?? [])]); e.target.value = ''; }} />
                        </div>
                        {arquivos.length > 0 && (
                            <ul className="space-y-1">
                                {arquivos.map((a, i) => (
                                    <li key={`${a.name}-${i}`} className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                                        {a.name}
                                        <button type="button" onClick={() => tirarArquivo(i)} className={LINK}><X size={12} /> tirar</button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <p className="text-[13px] font-normal text-white/50">
                            A IA usa estas fotos só como referência. A cópia usada é apagada quando o kit é aprovado (ou em até 48 horas).
                        </p>
                        <p className="text-[13px] font-normal text-white/50">Até 14 fotos de referência.</p>

                        <BotaoAcao data-acao="planejar-criativos" disabled={naoPodePlanejar}
                            onClick={() => c.planejar({ imagens: [...marcadas], arquivos })}>
                            {c.processando === 'planejar' ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />} Planejar as imagens
                        </BotaoAcao>
                    </div>
                )}

                {c.fase === 'kit' && kit && (
                    <div className="space-y-3">
                        {kit.status === 'planejando' && (
                            <p className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                                <Loader2 size={14} className="animate-spin" />
                                Planejando as imagens… (não custa nada){kit.etapa && ETAPA_LABEL[kit.etapa] ? ` — ${ETAPA_LABEL[kit.etapa]}` : ''}
                            </p>
                        )}

                        {kit.status === 'planejado' && (
                            ! c.confirmacaoRecusada ? (
                                <div data-confirmar-custo className="space-y-2 rounded-lg border border-white/20 bg-black/20 p-3">
                                    {kit.estrategia && <p className="text-[13px] font-normal text-white/70">{kit.estrategia}</p>}
                                    <p className="text-[13px] font-normal text-white/90">
                                        Gerar {kit.slots.length} imagens custa cerca de US$ {dolares(kit.slots.length * CUSTO_POR_IMAGEM_USD)}. Confirma?
                                    </p>
                                    <div className="flex flex-wrap items-center gap-3">
                                        <BotaoAcao data-acao="gerar-criativos" disabled={disabled || !! c.processando} onClick={c.gerar}>
                                            {c.processando === 'gerar' ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />}
                                            Gerar agora (≈ US$ {dolares(kit.slots.length * CUSTO_POR_IMAGEM_USD)})
                                        </BotaoAcao>
                                        <button type="button" onClick={c.recusarConfirmacao} disabled={disabled || !! c.processando} className={LINK}>
                                            Agora não
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <BotaoAcao disabled={disabled || !! c.processando} onClick={c.mostrarConfirmacao}>
                                    <Sparkles size={14} /> Gerar as imagens
                                </BotaoAcao>
                            )
                        )}

                        {kit.status === 'gerando' && (
                            <p className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                                <Loader2 size={14} className="animate-spin" />
                                Gerando as imagens em ondas — pode levar alguns minutos, e a tela continua acompanhando mesmo se você recarregar a página.
                            </p>
                        )}

                        {kit.status === 'erro' && ! temSlots && kit.erro && (
                            <p className="text-[13px] font-normal text-red-300">{kit.erro}</p>
                        )}

                        {temSlots && (
                            <div className="space-y-3">
                                {kit.referencias?.length > 0 && (
                                    <div className="space-y-1">
                                        <p className="text-[13px] font-normal text-white/50">Fotos de referência</p>
                                        <div className="flex flex-wrap gap-2">
                                            {kit.referencias.map((r) => (
                                                <img key={r.indice} src={r.url} alt={r.nome} className="h-12 w-12 rounded object-cover" />
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                    {kit.slots.map((s) => (
                                        <CartaoSlot key={s.indice} s={s} c={c} disabled={disabled} podeRegenerarKit={podeRegenerarKit} />
                                    ))}
                                </div>
                            </div>
                        )}

                        {(kit.status === 'pronto' || kit.status === 'parcial') && (
                            <div className="space-y-2 border-t border-white/[0.08] pt-3">
                                {kit.prontas > 0 && (
                                    <BotaoAcao data-acao="aprovar-kit" disabled={disabled || !! c.processando || ! podeUsarKit} onClick={c.aprovarKit}>
                                        {c.processando === 'aprovar-kit' ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
                                        Usar todas as prontas no anúncio
                                    </BotaoAcao>
                                )}
                                {! podeUsarKit && (
                                    <p className="text-[13px] font-normal text-white/50">
                                        Para usar o kit inteiro são precisas ao menos {kit.minimo_aprovadas} imagens prontas — gere de novo as que deram erro ou use uma por uma.
                                    </p>
                                )}
                                {fotosNoGrupo + kit.prontas > maxFotos && (
                                    <p data-aviso-capacidade className="text-[13px] font-normal text-amber-300">
                                        Usar todas deixa estas fotos acima do limite de {maxFotos}. Depois tire as que sobrarem — a publicação não sai acima do limite.
                                    </p>
                                )}
                                <p className="text-[13px] font-normal text-white/50">
                                    As imagens usadas entram no fim destas fotos e entram no anúncio do Mercado Livre só na publicação.
                                </p>
                            </div>
                        )}

                        {(kit.status === 'aprovado' || kit.status === 'erro') && (
                            <div className="border-t border-white/[0.08] pt-3">
                                <button type="button" onClick={c.novoKit} className={LINK}><Sparkles size={14} /> Gerar outro kit</button>
                                {kit.status === 'erro' && temSlots && kit.erro && <p className="mt-1.5 text-[13px] font-normal text-red-300">{kit.erro}</p>}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
