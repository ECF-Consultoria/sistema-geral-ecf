import { useMemo, useState } from 'react';
import { useForm, Head } from '@inertiajs/react';
import RespondLegado from './RespondLegado';

/**
 * Nps/Respond — formulário público v15.0 (redesenho 2026-07-08).
 *
 * Design importado do Claude Design MCP (ECF NPS Survey.dc.html):
 *   - Background com radial gradients (laranja→transparente no topo direito,
 *     roxo→transparente no canto inferior esquerdo) sobre #08080A.
 *   - Cabeçalho de marca ECF centralizado: logo + barra gradient laranja→roxo
 *     + subtítulo "CONSULTORIA & ASSESSORIA".
 *   - Chip com nome da empresa e bolinha degradê.
 *   - Card "Sua opinião move a ECF" com contador N/M e barra de progresso
 *     que anima conforme respostas são marcadas.
 *   - Cards de pergunta numerados. Escala 1-5 usa cores de sentimento
 *     (vermelho → verde) mapeadas ao peso da option. Perguntas de opções
 *     livres viram pills com gradient laranja→roxo quando selecionadas.
 *   - Botão submit gigante com gradient quando podeEnviar; cinza c/ mensagem
 *     "Responda todas para enviar" caso contrário.
 *   - Rodapé de confidencialidade.
 *
 * Rota legacy preservada: quando `template` é null, delega ao RespondLegado
 * (Phase 33 preservado byte-a-byte para surveys anteriores à Phase 68).
 *
 * Contrato submit v15 (Phase 69-03):
 *   POST /nps/{token}
 *   { respondent_name, comment, answers: { [question_id]: option_id } }
 */
export default function Respond({ survey, perguntas_extras, template }) {
    if (!template) {
        return <RespondLegado survey={survey} perguntas_extras={perguntas_extras} />;
    }
    return <RespondV15 survey={survey} template={template} />;
}

const ACCENT = '#FF5A19';
const ACCENT_ALT = '#C94FE0';

// Sentiment palette (vermelho → verde) usada nas escala 1..5.
const SENTIMENT = ['#E5484D', '#F76B15', '#F5A623', '#7CB342', '#2FA85A'];
const sentimentForPeso = (peso) => SENTIMENT[Math.max(0, Math.min(4, (peso ?? 3) - 1))] ?? ACCENT;

function RespondV15({ survey, template }) {
    const perguntas = template.perguntas ?? [];
    const total = perguntas.length;

    const { data, setData, post, processing, errors } = useForm({
        respondent_name: '',
        comment: '',
        answers: {},
    });

    const [attempted, setAttempted] = useState(false);

    const pick = (questionId, optionId) => {
        setData('answers', { ...data.answers, [questionId]: optionId });
    };

    // Ajuste 2026-07-13 · pra texto_livre, resposta é uma STRING; "respondida"
    // significa string não-vazia após trim. Pra escala/opcoes continua option_id.
    const isRespondida = (v) => {
        if (v == null) return false;
        if (typeof v === 'string') return v.trim().length > 0;
        return true;
    };

    const answered = useMemo(
        () => Object.keys(data.answers).filter((k) => isRespondida(data.answers[k])).length,
        [data.answers],
    );
    const pct = total > 0 ? Math.round((answered / total) * 100) : 0;

    const obrigatorias = useMemo(() => perguntas.filter((q) => q.obrigatoria), [perguntas]);
    const podeEnviar = obrigatorias.every((q) => isRespondida(data.answers[q.id]));
    const completoTudo = answered === total && total > 0;

    // Erros server-side por pergunta — Laravel devolve `answers.{qid}`.
    const errorsByQuestion = useMemo(() => {
        const map = {};
        Object.entries(errors || {}).forEach(([key, msg]) => {
            const m = key.match(/^answers\.(\d+)$/);
            if (m) map[Number(m[1])] = msg;
        });
        return map;
    }, [errors]);

    const submit = (e) => {
        e.preventDefault();
        if (!podeEnviar) {
            setAttempted(true);
            return;
        }
        post(route('nps.submit', survey.token), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Pesquisa de satisfação — ECF">
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link
                    href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
                    rel="stylesheet"
                />
            </Head>

            {/* Animações + reset body */}
            <style>{`
                html, body { margin: 0; padding: 0; }
                body { background: #08080A; font-family: 'Manrope', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
                @keyframes ecfrise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
            `}</style>

            <div style={{
                minHeight: '100vh',
                width: '100%',
                background:
                    'radial-gradient(1100px 620px at 78% -8%, rgba(255,90,25,0.18), transparent 60%),' +
                    'radial-gradient(900px 560px at 6% 108%, rgba(124,79,224,0.14), transparent 55%),' +
                    '#08080A',
                display: 'flex',
                justifyContent: 'center',
                padding: '56px 20px 80px',
                fontFamily: "'Manrope', system-ui, sans-serif",
                color: '#F4F4F6',
            }}>
                <div style={{ width: '100%', maxWidth: 660 }}>
                    <BrandHeader companyName={survey.company_name} />

                    <form onSubmit={submit} style={{ animation: 'ecfrise .55s ease both' }}>
                        <IntroCard
                            total={total}
                            answered={answered}
                            pct={pct}
                            complete={completoTudo}
                        />

                        {/* Perguntas */}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                            {perguntas.map((q, idx) => (
                                <QuestionCard
                                    key={q.id}
                                    numero={idx + 1}
                                    pergunta={q}
                                    selecionadoId={data.answers[q.id]}
                                    missing={attempted && q.obrigatoria && data.answers[q.id] == null}
                                    onPick={(optId) => pick(q.id, optId)}
                                    error={errorsByQuestion[q.id]}
                                />
                            ))}
                        </div>

                        {/* Comentário e nome — opcionais, escondidos por default para não poluir */}
                        <ExtraFields
                            data={data}
                            setData={setData}
                            errors={errors}
                        />

                        <button
                            type="submit"
                            disabled={processing}
                            style={{
                                width: '100%',
                                marginTop: 24,
                                padding: 17,
                                borderRadius: 15,
                                border: 'none',
                                fontFamily: "'Space Grotesk', sans-serif",
                                fontWeight: 700,
                                fontSize: 16,
                                cursor: processing ? 'wait' : 'pointer',
                                background: podeEnviar
                                    ? `linear-gradient(135deg, ${ACCENT}, #FF8A4D)`
                                    : 'rgba(255,255,255,.05)',
                                color: podeEnviar ? '#fff' : '#7A7A82',
                                boxShadow: podeEnviar ? '0 14px 40px rgba(255,90,25,.35)' : 'none',
                                transition: 'all .2s ease',
                            }}
                        >
                            {processing ? 'Enviando…' : (podeEnviar ? 'Enviar avaliação' : 'Responda todas para enviar')}
                        </button>

                        <div style={{
                            textAlign: 'center',
                            fontSize: 12,
                            color: '#6A6A72',
                            marginTop: 16,
                        }}>
                            Suas respostas são confidenciais e usadas apenas para melhorar nosso trabalho.
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

// ─── Header de marca ─────────────────────────────────────────────────────
function BrandHeader({ companyName }) {
    return (
        <div style={{
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            textAlign: 'center',
            gap: 6,
            marginBottom: 34,
            animation: 'ecfrise .5s ease both',
        }}>
            <div style={{
                display: 'flex',
                alignItems: 'center',
                gap: 14,
                marginBottom: 6,
            }}>
                <div style={{
                    fontFamily: "'Space Grotesk', sans-serif",
                    fontWeight: 700,
                    fontSize: 34,
                    letterSpacing: 1,
                    color: '#fff',
                }}>ECF</div>
                <div style={{
                    width: 3,
                    height: 34,
                    borderRadius: 3,
                    background: 'linear-gradient(180deg,#FF5A19,#C94FE0 55%,#6B4FE0)',
                }} />
                <div style={{
                    textAlign: 'left',
                    fontFamily: "'Space Grotesk', sans-serif",
                    fontWeight: 600,
                    fontSize: 11.5,
                    letterSpacing: 2.4,
                    lineHeight: 1.35,
                    color: '#B7B7C0',
                }}>
                    CONSULTORIA<br />&amp; ASSESSORIA
                </div>
            </div>
            <div style={{
                fontFamily: "'Space Grotesk', sans-serif",
                fontWeight: 600,
                fontSize: 24,
                color: '#fff',
                letterSpacing: '-.3px',
            }}>
                Pesquisa de satisfação
            </div>
            {companyName && (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '6px 14px',
                    borderRadius: 999,
                    background: 'rgba(255,255,255,.05)',
                    border: '1px solid rgba(255,255,255,.09)',
                    fontSize: 12,
                    fontWeight: 600,
                    letterSpacing: 1.6,
                    color: '#C7C7CF',
                    textTransform: 'uppercase',
                }}>
                    <span style={{
                        width: 6,
                        height: 6,
                        borderRadius: '50%',
                        background: 'linear-gradient(135deg,#FF6B2C,#C94FE0)',
                    }} />
                    {companyName}
                </div>
            )}
        </div>
    );
}

// ─── Card intro + progresso ──────────────────────────────────────────────
function IntroCard({ total, answered, pct, complete }) {
    return (
        <div style={{
            background: 'linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.02))',
            border: '1px solid rgba(255,255,255,.08)',
            borderRadius: 20,
            padding: '22px 24px',
            marginBottom: 22,
        }}>
            <div style={{
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'space-between',
                gap: 16,
                flexWrap: 'wrap',
            }}>
                <div style={{ maxWidth: 410 }}>
                    <div style={{
                        fontFamily: "'Space Grotesk', sans-serif",
                        fontWeight: 600,
                        fontSize: 17,
                        color: '#fff',
                        marginBottom: 5,
                    }}>
                        Sua opinião move a ECF
                    </div>
                    <div style={{
                        fontSize: 13.5,
                        lineHeight: 1.55,
                        color: '#9A9AA4',
                    }}>
                        São {total} pergunta{total === 1 ? '' : 's'} sobre o atendimento e os resultados. Leva menos de 2 minutos e é totalmente confidencial.
                    </div>
                </div>
                <div style={{ textAlign: 'right', flexShrink: 0 }}>
                    <div style={{
                        fontFamily: "'Space Grotesk', sans-serif",
                        fontWeight: 700,
                        fontSize: 26,
                        color: '#fff',
                        lineHeight: 1,
                    }}>
                        <span style={{ color: complete ? '#2FA85A' : ACCENT }}>{answered}</span>
                        <span style={{ color: '#55555E', fontSize: 18 }}>/{total}</span>
                    </div>
                    <div style={{
                        fontSize: 11,
                        fontWeight: 600,
                        letterSpacing: 1,
                        color: '#77777F',
                        marginTop: 4,
                    }}>
                        RESPONDIDAS
                    </div>
                </div>
            </div>
            <div style={{
                marginTop: 18,
                height: 7,
                borderRadius: 99,
                background: 'rgba(255,255,255,.07)',
                overflow: 'hidden',
            }}>
                <div style={{
                    height: '100%',
                    width: pct + '%',
                    borderRadius: 99,
                    background: `linear-gradient(90deg, ${ACCENT}, ${ACCENT_ALT})`,
                    transition: 'width .4s cubic-bezier(.4,0,.2,1)',
                }} />
            </div>
        </div>
    );
}

// ─── Card de pergunta ────────────────────────────────────────────────────
function QuestionCard({ numero, pergunta, selecionadoId, missing, onPick, error }) {
    const isTextoLivre = pergunta.tipo === 'texto_livre';
    const isEscala     = pergunta.tipo === 'escala';
    // Pra texto_livre, "marcada" = string não-vazia. Pra escala/opcoes = option
    // encontrada nas opções.
    const chosenOption = isTextoLivre
        ? null
        : (pergunta.options?.find((o) => o.id === selecionadoId) ?? null);
    const marcada = isTextoLivre
        ? (typeof selecionadoId === 'string' && selecionadoId.trim().length > 0)
        : chosenOption != null;

    const cardStyle = {
        background: 'linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.018))',
        border: '1px solid ' + (
            missing ? 'rgba(229,72,77,.5)'
            : marcada ? 'rgba(255,255,255,.13)'
            : 'rgba(255,255,255,.07)'
        ),
        borderRadius: 18,
        padding: '22px 22px 20px',
        transition: 'border-color .2s',
    };

    const numStyle = {
        flexShrink: 0,
        width: 26,
        height: 26,
        borderRadius: 8,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontFamily: "'Space Grotesk', sans-serif",
        fontWeight: 700,
        fontSize: 13,
        background: marcada ? ACCENT : 'rgba(255,255,255,.06)',
        color: marcada ? '#fff' : '#8A8A93',
        transition: 'background .2s, color .2s',
    };

    const options = pergunta.options ?? [];

    return (
        <div style={cardStyle}>
            <div style={{
                display: 'flex',
                gap: 12,
                alignItems: 'flex-start',
                marginBottom: 16,
            }}>
                <div style={numStyle}>{numero}</div>
                <div style={{
                    fontFamily: "'Space Grotesk', sans-serif",
                    fontWeight: 600,
                    fontSize: 16,
                    lineHeight: 1.4,
                    color: '#F1F1F4',
                    paddingTop: 2,
                }}>
                    {pergunta.texto}
                    {pergunta.obrigatoria && (
                        <span style={{ color: ACCENT, marginLeft: 3 }}>*</span>
                    )}
                </div>
            </div>

            {isTextoLivre ? (
                <TextoLivreRow value={selecionadoId} onPick={onPick} />
            ) : isEscala ? (
                <ScaleRow options={options} selecionadoId={selecionadoId} onPick={onPick} />
            ) : (
                <ChoiceRow options={options} selecionadoId={selecionadoId} onPick={onPick} />
            )}

            {error && (
                <p style={{
                    marginTop: 10,
                    color: '#E5484D',
                    fontSize: 12,
                    fontWeight: 500,
                }}>{error}</p>
            )}
        </div>
    );
}

// ─── Escala 1-5 com cores de sentimento ──────────────────────────────────
function ScaleRow({ options, selecionadoId, onPick }) {
    // Ordena por peso ASC (1..5) para garantir gradient da esquerda pra direita.
    const ordered = [...options].sort((a, b) => (a.peso ?? 0) - (b.peso ?? 0));

    // Ajuste 2026-07-13 · quando existe algum label longo (labels de texto tipo
    // "Discordo parcialmente" no lugar do número), reduz fontSize e permite
    // quebra de linha pra não quebrar a palavra ao meio no botão de 20% da
    // largura. Números como "1", "2" ficam com o tamanho grande original.
    const maxLabelLen = ordered.reduce((m, o) => Math.max(m, (o.label ?? '').length), 0);
    // Escala de tamanho por comprimento máximo do label:
    //   ≤ 3 → 18px (números 1..5 e labels curtinhas)
    //   4-6 → 15px
    //   7-10 → 13px
    //   > 10 → 11.5px
    const labelFontSize = maxLabelLen <= 3
        ? 18
        : maxLabelLen <= 6
            ? 15
            : maxLabelLen <= 10
                ? 13
                : 11.5;
    const podeQuebrar = maxLabelLen > 3;

    return (
        <>
            <div style={{ display: 'flex', gap: 10 }}>
                {ordered.map((o) => {
                    const sel = selecionadoId === o.id;
                    const col = sentimentForPeso(o.peso);
                    return (
                        <button
                            type="button"
                            key={o.id}
                            onClick={() => onPick(o.id)}
                            style={{
                                flex: 1,
                                minWidth: 0, // deixa o flex distribuir sem overflow horizontal
                                height: 52,
                                padding: podeQuebrar ? '4px 6px' : 0,
                                borderRadius: 12,
                                cursor: 'pointer',
                                fontFamily: "'Space Grotesk', sans-serif",
                                fontWeight: 700,
                                fontSize: labelFontSize,
                                lineHeight: 1.15,
                                textAlign: 'center',
                                whiteSpace: podeQuebrar ? 'normal' : 'nowrap',
                                overflowWrap: 'break-word',
                                wordBreak: 'break-word',
                                hyphens: 'auto',
                                border: '1px solid ' + (sel ? col : 'rgba(255,255,255,.09)'),
                                background: sel ? col : 'rgba(255,255,255,.03)',
                                color: sel ? '#fff' : '#B7B7C0',
                                boxShadow: sel ? `0 8px 22px ${col}55` : 'none',
                                transform: sel ? 'translateY(-2px)' : 'none',
                                transition: 'all .18s ease',
                            }}
                        >
                            {o.label}
                        </button>
                    );
                })}
            </div>
            <div style={{
                display: 'flex',
                justifyContent: 'space-between',
                marginTop: 11,
                padding: '0 2px',
            }}>
                <span style={{ fontSize: 11.5, fontWeight: 600, letterSpacing: .3, color: '#6E6E77' }}>
                    Ruim
                </span>
                <span style={{ fontSize: 11.5, fontWeight: 600, letterSpacing: .3, color: '#6E6E77' }}>
                    Excelente
                </span>
            </div>
        </>
    );
}

// ─── Opções livres em formato pill com cor sentiment por peso ────────────
// Ajuste 2026-07-08 (feedback pós-deploy): antes as pills usavam gradient
// laranja→roxo fixo no selected. Agora aplicam a MESMA cor sentiment das
// escalas 1-5, baseada em `option.peso`. Assim "Sempre" (peso=5) fica verde
// escuro, "Raramente" (peso=2) laranja, etc. Independente do texto — o
// admin controla a cor via peso interno na configuração do template.
function ChoiceRow({ options, selecionadoId, onPick }) {
    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
            {options.map((o) => {
                const sel = selecionadoId === o.id;
                const col = sentimentForPeso(o.peso);
                return (
                    <button
                        type="button"
                        key={o.id}
                        onClick={() => onPick(o.id)}
                        style={{
                            padding: '11px 18px',
                            borderRadius: 999,
                            cursor: 'pointer',
                            fontFamily: "'Manrope', sans-serif",
                            fontWeight: 600,
                            fontSize: 13.5,
                            border: '1px solid ' + (sel ? col : 'rgba(255,255,255,.1)'),
                            background: sel ? col : 'rgba(255,255,255,.03)',
                            color: sel ? '#fff' : '#C1C1C9',
                            boxShadow: sel ? `0 8px 22px ${col}55` : 'none',
                            transition: 'all .18s ease',
                        }}
                    >
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

// ─── Texto livre (caixa de texto) — pergunta tipo texto_livre ────────────
// Ajuste 2026-07-13: nova pergunta aberta. `value` chega e sai como STRING —
// diferente das escala/opcoes que trabalham com option_id numérico. O parent
// (RespondV15) trata a diferença ao contar respostas e validar podeEnviar.
function TextoLivreRow({ value, onPick }) {
    return (
        <textarea
            value={typeof value === 'string' ? value : ''}
            onChange={(e) => onPick(e.target.value)}
            placeholder="Escreva sua resposta aqui…"
            rows={4}
            style={{
                width: '100%',
                boxSizing: 'border-box',
                padding: '12px 14px',
                borderRadius: 12,
                border: '1px solid rgba(255,255,255,.09)',
                background: 'rgba(255,255,255,.03)',
                color: '#F1F1F4',
                fontFamily: "'Manrope', sans-serif",
                fontSize: 14,
                lineHeight: 1.5,
                resize: 'vertical',
                outline: 'none',
                transition: 'border-color .2s',
            }}
            onFocus={(e) => (e.target.style.borderColor = 'rgba(255,90,25,.5)')}
            onBlur={(e) => (e.target.style.borderColor = 'rgba(255,255,255,.09)')}
        />
    );
}

// ─── Campos extras: comentário + nome (opcionais) ────────────────────────
function ExtraFields({ data, setData, errors }) {
    const [showExtras, setShowExtras] = useState(false);

    const fieldStyle = {
        width: '100%',
        padding: '12px 14px',
        borderRadius: 12,
        border: '1px solid rgba(255,255,255,.09)',
        background: 'rgba(255,255,255,.03)',
        color: '#F4F4F6',
        fontFamily: "'Manrope', sans-serif",
        fontSize: 14,
        outline: 'none',
        transition: 'border-color .18s ease',
    };

    return (
        <div style={{ marginTop: 22 }}>
            {!showExtras ? (
                <button
                    type="button"
                    onClick={() => setShowExtras(true)}
                    style={{
                        background: 'transparent',
                        border: 'none',
                        color: '#B7B7C0',
                        fontFamily: "'Manrope', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: 'pointer',
                        textDecoration: 'underline',
                        textUnderlineOffset: 3,
                    }}
                >
                    Adicionar comentário ou nome (opcional)
                </button>
            ) : (
                <div style={{
                    background: 'linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.018))',
                    border: '1px solid rgba(255,255,255,.08)',
                    borderRadius: 18,
                    padding: '20px 22px',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                }}>
                    <div>
                        <label style={{
                            display: 'block',
                            fontSize: 12,
                            fontWeight: 600,
                            letterSpacing: 0.5,
                            color: '#B7B7C0',
                            marginBottom: 6,
                            textTransform: 'uppercase',
                        }}>
                            Comentário (opcional)
                        </label>
                        <textarea
                            value={data.comment}
                            onChange={(e) => setData('comment', e.target.value)}
                            placeholder="Escreva aqui, se quiser deixar uma observação."
                            rows={3}
                            maxLength={2000}
                            style={{ ...fieldStyle, resize: 'vertical', minHeight: 72 }}
                        />
                        {errors.comment && (
                            <p style={{ color: '#E5484D', fontSize: 12, marginTop: 4 }}>{errors.comment}</p>
                        )}
                    </div>
                    <div>
                        <label style={{
                            display: 'block',
                            fontSize: 12,
                            fontWeight: 600,
                            letterSpacing: 0.5,
                            color: '#B7B7C0',
                            marginBottom: 6,
                            textTransform: 'uppercase',
                        }}>
                            Seu nome (opcional)
                        </label>
                        <input
                            type="text"
                            value={data.respondent_name}
                            onChange={(e) => setData('respondent_name', e.target.value)}
                            placeholder="Como você prefere ser chamado(a)."
                            maxLength={255}
                            style={fieldStyle}
                        />
                        {errors.respondent_name && (
                            <p style={{ color: '#E5484D', fontSize: 12, marginTop: 4 }}>{errors.respondent_name}</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
