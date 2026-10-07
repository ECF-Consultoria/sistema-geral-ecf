import { useEffect, useId, useRef, useState } from 'react';
import { Check, Loader2, RefreshCw, Sparkles, Upload, X } from 'lucide-react';
import { BotaoAcao } from './botoes';
import { AREA, CAMPO, LINK, SELECT } from './comum';

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
 * 261007 — tela preta em produção (kit id 2): `kit.estrategia` é um OBJETO (3 campos de texto do
 * plano da IA — `publico`/`direcao_visual`/`proposta_de_valor`), e o painel renderizava
 * `{kit.estrategia}` cru. React recusa objeto como filho ("Objects are not valid as a React
 * child") e derruba a árvore inteira — não um erro de rede, a PÁGINA morre.
 *
 * `textoSeguro` é a única porta de entrada para qualquer campo de texto do presenter que venha de
 * texto livre da IA (estratégia, objetivo do slot, mensagem de validação, explicação de um
 * problema): string e número passam, qualquer outra coisa (objeto, array) nunca chega como filho
 * do React — vira `null` (o chamador decide o texto de reserva). Nenhum campo assim tem garantia
 * de formato em tempo de execução só porque o PHP documenta um shape num docblock.
 */
const textoSeguro = (v) => (typeof v === 'string' || typeof v === 'number' ? v : null);

/**
 * `kit.estrategia` hoje SEMPRE chega como objeto (`CreativePlanner`/`CreativePlan::$estrategia`,
 * sempre os 3 campos abaixo) — mas a tela aceita também string (caminho antigo ou futuro) e
 * qualquer outro formato sem quebrar (vira nada, nunca o objeto cru).
 */
function Estrategia({ estrategia }) {
    const texto = textoSeguro(estrategia);
    if (texto) return <p className="text-[13px] font-normal text-white/70">{texto}</p>;
    if (typeof estrategia !== 'object' || estrategia === null) return null;

    const campos = [
        ['publico', 'Para quem é'],
        ['proposta_de_valor', 'O que destaca'],
        ['direcao_visual', 'Como vai parecer'],
    ];
    const linhas = campos
        .map(([chave, rotulo]) => [rotulo, textoSeguro(estrategia[chave])])
        .filter(([, valor]) => !! valor);

    if (linhas.length === 0) return null;

    return (
        <div className="space-y-1">
            {linhas.map(([rotulo, valor]) => (
                <p key={rotulo} className="text-[13px] font-normal text-white/70">
                    <span className="font-bold text-white/90">{rotulo}:</span> {valor}
                </p>
            ))}
        </div>
    );
}

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
            <p className="text-[13px] font-bold text-white/90">{s.indice}. {textoSeguro(s.rotulo)}</p>
            <p className="text-[11px] font-normal text-white/50">{textoSeguro(s.objetivo)}</p>

            {s.imagem_url ? (
                <img src={s.imagem_url} alt={textoSeguro(s.rotulo) ?? ''} loading="lazy" className="w-full rounded object-cover" />
            ) : (
                <p className="text-[11px] font-normal text-white/50">
                    {s.status === 'pendente' && 'Na fila'}
                    {s.status === 'rodando' && `${ETAPA_LABEL[s.etapa] ?? 'gerando'}…`}
                    {s.status === 'erro' && textoSeguro(s.erro)}
                </p>
            )}

            {reprovada && (
                <div className="space-y-1 rounded border border-red-400/40 bg-red-500/10 p-1.5">
                    <p className="text-[11px] font-normal text-red-300">{textoSeguro(s.validacao_mensagem) ?? 'Risco apontado pela validação automática.'}</p>
                    {s.validacao_problemas?.length > 0 && (
                        <ul className="space-y-0.5">
                            {s.validacao_problemas.map((p, i) => (
                                <li key={i} className="text-[11px] font-normal text-white/50">{textoSeguro(p.explicacao) ?? 'Risco apontado automaticamente.'}</li>
                            ))}
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
 * Fase 169 (TXT-01/04) — o operador confirma um ponto forte ou uma medida do produto para a IA
 * poder escrever esse texto de verdade numa das imagens, e vê a lista do que já confirmou. Sem
 * fato nenhum (nem confirmado aqui, nem cadastro suficiente no Mercado Livre), nenhuma imagem sai
 * com texto — o aviso abaixo (texto pronto do servidor, `CreativeSlotCatalog::faltamParaTexto()`)
 * já diz o que fazer, não só o que falta. Não renderiza nada enquanto `c.fatos` ainda não chegou
 * (carregando) ou falhou em silêncio — o bloco é complementar, nunca trava o resto do painel.
 *
 * REND-01/02: `item.texto` e cada linha de `faltam` passam por `textoSeguro` antes de virar filho
 * do React — o mesmo cuidado de `Estrategia`/`CartaoSlot` acima, pela mesma fronteira (presenter
 * PHP → React) que derrubou a tela em 261007.
 */
function FatosDoProduto({ c }) {
    const idBase = useId();
    const [tipo, setTipo] = useState('beneficio');
    const [texto, setTexto] = useState('');

    if (! c.fatos) return null;

    const confirmados = Array.isArray(c.fatos.confirmados) ? c.fatos.confirmados : [];
    const faltam = Array.isArray(c.fatos.faltam) ? c.fatos.faltam : [];
    const ocupado = !! c.processando;
    const naoPodeConfirmar = ocupado || texto.trim() === '';

    const confirmar = () => {
        const valor = texto.trim();
        if (! valor) return;
        c.salvarFato(tipo, valor);
        setTexto('');
    };

    return (
        <div data-fatos-do-produto className="space-y-2 rounded-lg border border-white/20 bg-black/20 p-3">
            <p className="text-[13px] font-bold text-white/90">Pontos fortes e medidas do produto</p>
            <p className="text-[11px] font-normal text-white/50">O que você confirmar aqui pode aparecer escrito numa das imagens geradas.</p>

            {! c.fatos.podeTerTexto && faltam.length > 0 && (
                <div className="space-y-1 rounded border border-amber-400/30 bg-amber-500/10 p-1.5">
                    <p className="text-[11px] font-normal text-amber-300">Ainda não é possível colocar texto em nenhuma imagem:</p>
                    {faltam.map((item, i) => {
                        const linha = textoSeguro(item);

                        return linha ? <p key={i} className="text-[11px] font-normal text-white/70">{linha}</p> : null;
                    })}
                </div>
            )}

            {confirmados.length > 0 && (
                <ul className="space-y-1">
                    {confirmados.map((item, i) => {
                        const idFato = typeof item?.id === 'number' ? item.id : null;
                        const rotulo = item?.tipo === 'medida' ? 'Medida' : 'Ponto forte';
                        const valor = textoSeguro(item?.texto);

                        return (
                            <li key={idFato ?? i} className="flex flex-wrap items-center justify-between gap-2 text-[13px] font-normal text-white/70">
                                <span><span className="font-bold text-white/90">{rotulo}:</span> {valor ?? '—'}</span>
                                <button type="button" onClick={() => idFato !== null && c.removerFato(idFato)} disabled={ocupado || idFato === null} className={LINK}>
                                    remover
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="space-y-1.5">
                <label className="text-[11px] font-normal text-white/50" htmlFor={`${idBase}-tipo`}>O que você quer confirmar?</label>
                <select id={`${idBase}-tipo`} value={tipo} onChange={(e) => setTipo(e.target.value)} disabled={ocupado} className={SELECT}>
                    <option value="beneficio">Ponto forte</option>
                    <option value="medida">Medida</option>
                </select>
                <input
                    id={`${idBase}-texto`}
                    type="text"
                    value={texto}
                    onChange={(e) => setTexto(e.target.value)}
                    maxLength={300}
                    disabled={ocupado}
                    placeholder="ex.: motor silencioso"
                    aria-label="Texto do ponto forte ou da medida"
                    className={CAMPO}
                />
                <button type="button" onClick={confirmar} disabled={naoPodeConfirmar} className={LINK}>
                    {c.processando === 'salvar-fato' ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />} Confirmar
                </button>
            </div>
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
    const [miniaturas, setMiniaturas] = useState([]);
    const arquivoRef = useRef(null);

    // Miniatura do arquivo escolhido no computador (261005-si3): sem isto o único sinal de que
    // "deu certo" era um nome de arquivo em texto cinza, fácil de não notar — a pessoa que
    // escolhe a foto não tinha como saber se tinha funcionado. `URL.createObjectURL` só lê o
    // arquivo local (nada sobe ao servidor aqui); a limpeza evita vazar memória a cada escolha.
    useEffect(() => {
        const urls = arquivos.map((a) => URL.createObjectURL(a));
        setMiniaturas(urls);

        return () => urls.forEach((u) => URL.revokeObjectURL(u));
    }, [arquivos]);

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
                {(c.fase === 'escolhendo' || c.fase === 'kit') && <FatosDoProduto c={c} />}

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
                            <div className="space-y-1.5 rounded-lg border border-emerald-500/25 bg-emerald-500/[0.06] p-2" data-arquivos-escolhidos={arquivos.length}>
                                <p className="flex items-center gap-1.5 text-[13px] font-normal text-emerald-300">
                                    <Check size={14} /> {arquivos.length} {arquivos.length === 1 ? 'foto escolhida do computador' : 'fotos escolhidas do computador'}
                                </p>
                                <ul className="flex flex-wrap gap-2">
                                    {arquivos.map((a, i) => (
                                        <li key={`${a.name}-${i}`} className="flex items-center gap-1.5 text-[13px] font-normal text-white/70">
                                            <img src={miniaturas[i]} alt={a.name} className="h-10 w-10 rounded object-cover" />
                                            <span className="max-w-[12ch] truncate" title={a.name}>{a.name}</span>
                                            <button type="button" onClick={() => tirarArquivo(i)} className={LINK}><X size={12} /> tirar</button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <p className="text-[13px] font-normal text-white/50">
                            A IA usa estas fotos só como referência. A cópia usada é apagada quando o kit é aprovado (ou em até 48 horas).
                        </p>
                        {/* 261005-si3 (Task 3): o usuário tinha razão — o tamanho mínimo do Mercado Livre vale para a foto
                            do ANÚNCIO, não para esta. Conferido no servidor: `referencias.*` valida só imagem + até 10 MB,
                            nenhuma dimensão. A imagem GERADA pela IA é que passa pela mesma conferência das demais fotos
                            quando usada no anúncio — mas isso é sobre o resultado, não sobre a foto que você envia aqui. */}
                        <p className="text-[13px] font-normal text-white/50">
                            Esta foto é só para a IA se inspirar: pode ter qualquer tamanho, não precisa ser do tamanho que o Mercado Livre exige para o anúncio.
                        </p>
                        <p className="text-[13px] font-normal text-white/50">{totalEscolhido} de até 14 fotos de referência escolhidas.</p>

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
                                    {kit.estrategia && <Estrategia estrategia={kit.estrategia} />}
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
                            <p className="text-[13px] font-normal text-red-300">{textoSeguro(kit.erro)}</p>
                        )}

                        {temSlots && (
                            <div className="space-y-3">
                                {kit.referencias?.length > 0 && (
                                    <div className="space-y-1">
                                        <p className="text-[13px] font-normal text-white/50">Fotos de referência</p>
                                        <div className="flex flex-wrap gap-2">
                                            {kit.referencias.map((r) => (
                                                <img key={r.indice} src={r.url} alt={textoSeguro(r.nome) ?? ''} className="h-12 w-12 rounded object-cover" />
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
                                {kit.status === 'erro' && temSlots && kit.erro && <p className="mt-1.5 text-[13px] font-normal text-red-300">{textoSeguro(kit.erro)}</p>}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
