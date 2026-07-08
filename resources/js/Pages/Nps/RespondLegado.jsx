import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import LogoEcf from '@/Components/LogoEcf';
import { cn } from '@/lib/utils';

// ─── Componente local: 5 botoes grandes 1-5 com gradiente vermelho->verde ───
// Substitui o ScorePicker 0-10 anterior. Escala D-06 (1-5) com 5 niveis (D-07).
function RatingPicker({ value, onChange, label }) {
    // Cores por valor selecionado (gradiente Pessimo -> Otimo)
    const selectedStyles = {
        1: 'bg-red-500 border-red-500 text-white',
        2: 'bg-orange-500 border-orange-500 text-white',
        3: 'bg-yellow-500 border-yellow-500 text-black',
        4: 'bg-lime-500 border-lime-500 text-black',
        5: 'bg-emerald-500 border-emerald-500 text-white',
    };

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-white/80">{label}</p>
            <div className="flex gap-2 flex-wrap">
                {[1, 2, 3, 4, 5].map((i) => (
                    <button
                        key={i}
                        type="button"
                        onClick={() => onChange(i)}
                        aria-label={`Nota ${i}`}
                        aria-pressed={value === i}
                        className={cn(
                            'w-12 h-12 rounded-xl border text-base font-bold transition-all',
                            value === i
                                ? selectedStyles[i]
                                : 'border-white/[0.08] bg-white/[0.03] text-white/50 hover:border-ecf-yellow/40 hover:text-ecf-yellow'
                        )}
                    >
                        {i}
                    </button>
                ))}
            </div>
            <div className="flex justify-between text-xs text-white/40">
                <span>Muito ruim</span>
                <span>Excelente</span>
            </div>
        </div>
    );
}

// ─── Phase 33 Plan 03: render dinamico de perguntas customizadas ────────
// Quatro tipos suportados (D-02): escala_1_5 / texto / sim_nao / multipla.
// Reutiliza o RatingPicker existente para o tipo escala_1_5.
function PerguntaExtra({ pergunta, valor, onChange, error }) {
    if (pergunta.tipo === 'escala_1_5') {
        // Sem label aqui — o label da pergunta ja e renderizado no wrapper externo
        return <RatingPicker value={valor} onChange={onChange} />;
    }

    if (pergunta.tipo === 'texto') {
        // Espelha a validacao backend max:2000
        return (
            <textarea
                value={valor || ''}
                onChange={(e) => onChange(e.target.value)}
                maxLength={2000}
                rows={3}
                placeholder="Sua resposta"
                aria-required={pergunta.obrigatorio || undefined}
                className={cn(
                    'w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                    'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors resize-none',
                    error ? 'border-red-500/50' : 'border-white/[0.08]'
                )}
            />
        );
    }

    if (pergunta.tipo === 'sim_nao') {
        // 2 botoes lado a lado — verde (sim) e vermelho (nao) quando selecionado
        return (
            <div className="flex gap-3">
                <button
                    type="button"
                    onClick={() => onChange('sim')}
                    aria-pressed={valor === 'sim'}
                    className={cn(
                        'flex-1 py-3 rounded-xl border font-semibold text-sm transition-colors',
                        valor === 'sim'
                            ? 'bg-emerald-500/25 border-emerald-500 text-emerald-100'
                            : 'bg-white/[0.04] border-white/[0.08] text-white/70 hover:bg-white/[0.06]'
                    )}
                >
                    SIM
                </button>
                <button
                    type="button"
                    onClick={() => onChange('nao')}
                    aria-pressed={valor === 'nao'}
                    className={cn(
                        'flex-1 py-3 rounded-xl border font-semibold text-sm transition-colors',
                        valor === 'nao'
                            ? 'bg-rose-500/25 border-rose-500 text-rose-100'
                            : 'bg-white/[0.04] border-white/[0.08] text-white/70 hover:bg-white/[0.06]'
                    )}
                >
                    NÃO
                </button>
            </div>
        );
    }

    if (pergunta.tipo === 'multipla') {
        // Radio group vertical — valor enviado e o TEXTO da opcao (nao indice).
        // opcoes pode vir null defensivamente em outros tipos; aqui forcamos array.
        return (
            <div className="space-y-2">
                {(pergunta.opcoes || []).map((opt) => (
                    <label
                        key={opt}
                        className={cn(
                            'flex items-center gap-3 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors',
                            valor === opt
                                ? 'border-ecf-yellow bg-ecf-yellow/10'
                                : 'border-white/[0.08] bg-white/[0.04] hover:bg-white/[0.06]'
                        )}
                    >
                        <input
                            type="radio"
                            name={`p${pergunta.id}`}
                            value={opt}
                            checked={valor === opt}
                            onChange={() => onChange(opt)}
                            className="accent-ecf-yellow"
                        />
                        <span className="text-white text-sm">{opt}</span>
                    </label>
                ))}
            </div>
        );
    }

    return null;
}

export default function RespondLegado({ survey, perguntas_extras }) {
    // Phase 33 Plan 03: perguntas extras vem do payload Inertia ja filtradas
    // (apenas ativas) e ordenadas (ordem ASC, fallback id ASC) pelo NpsController::respond.
    const perguntasExtras = perguntas_extras || [];

    // Estado do form — chaves alinhadas com NpsController::submitResponse (Plan 31-02 Task 3c)
    // + respostas_extras (Plan 33-01 D-07): objeto {pergunta_id: valor}
    // Inicializa cada pergunta com null (escala_1_5, para combinar com RatingPicker)
    // ou '' (texto / sim_nao / multipla) — backend trata vazio como ausencia.
    const { data, setData, post, processing, errors } = useForm({
        respondent_name: '',
        score_estrategista: null,
        score_analista: null,
        score_empresa: null,
        comment: '',
        respostas_extras: Object.fromEntries(
            perguntasExtras.map((p) => [p.id, p.tipo === 'escala_1_5' ? null : ''])
        ),
    });

    const submit = (e) => {
        e.preventDefault();
        // Quando empresa nao tem analista (mentoria pura), garantimos que score_analista seja null
        // para o backend aceitar (nullable). useForm envia tudo, mas como o usuario nunca tocou
        // o campo ele continua null — comportamento correto.
        post(route('nps.submit', survey.token));
    };

    // Validacao client-side: estrategista + empresa sempre obrigatorios; analista so quando
    // survey.tem_analista === true (D-07 mentoria pura omite o slider).
    // Plan 33-03: + todas as perguntas extras obrigatorias precisam de valor preenchido.
    const todasObrigatoriasExtrasOk = perguntasExtras
        .filter((p) => p.obrigatorio)
        .every((p) => {
            const v = data.respostas_extras[p.id];
            return v !== null && v !== '' && v !== undefined;
        });

    const isValid =
        data.score_estrategista !== null &&
        data.score_empresa !== null &&
        (!survey.tem_analista || data.score_analista !== null) &&
        todasObrigatoriasExtrasOk;

    // ─── Phase 32 Plan 03: textos dinamicos vindos do backend ──────────────
    // Backend ja substitui {nome_estrategista}, {nome_analista}, {nome_empresa}
    // antes de enviar. Fallback defensivo pra strings legadas caso a prop nao
    // venha (ex: survey criada antes da Phase 32 sem rerender do controller).
    const textos = survey.textos || {};
    const txtPergEstrategista =
        textos.perg_estrategista ||
        `O atendimento do ${survey.estrategista_name || 'Estrategista'}`;
    const txtPergAnalista =
        textos.perg_analista ||
        `O atendimento do ${survey.analista_name || 'Analista'}`;
    const txtPergEmpresa =
        textos.perg_empresa || 'A ECF está atendendo suas expectativas?';
    const txtComentarioLabel =
        textos.perg_comentario_label || 'Comentário (opcional)';
    const txtComentarioPlaceholder =
        textos.perg_comentario_placeholder ||
        'Opiniões, sugestões ou outra coisa que queira compartilhar';
    const txtNomeLabel = textos.perg_nome_label || 'Seu nome (opcional)';

    return (
        <div className="min-h-screen bg-ecf-bg flex items-center justify-center p-4">
            <div className="w-full max-w-xl">
                {/* Header com logo ECF oficial (D-01) — Montserrat + barra gradiente */}
                <div className="text-center mb-8">
                    <div className="flex justify-center mb-4">
                        <LogoEcf theme="dark" />
                    </div>
                    <h1 className="text-2xl font-bold text-white">Avaliação de Atendimento</h1>
                    <p className="text-white/60 mt-1">{survey.company_name}</p>
                </div>

                {/* Card principal do form */}
                <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-6 space-y-6">
                    <form onSubmit={submit} className="space-y-6">
                        {/* Nome opcional — D-07: respondent_name nullable */}
                        <div className="space-y-1.5">
                            <Label className="text-white/80">{txtNomeLabel}</Label>
                            <Input
                                value={data.respondent_name}
                                onChange={(e) => setData('respondent_name', e.target.value)}
                                placeholder="Como podemos te chamar?"
                                maxLength={255}
                                className="bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30"
                            />
                            {errors.respondent_name && (
                                <p className="text-red-400 text-xs">{errors.respondent_name}</p>
                            )}
                        </div>

                        {/* Bloco das 3 notas 1-5 */}
                        <div className="space-y-5">
                            <h2 className="text-sm font-semibold text-white/70 uppercase tracking-wide">
                                Avalie de 1 a 5
                            </h2>

                            {/* Estrategista — sempre presente (required) */}
                            <div className="space-y-1.5">
                                <RatingPicker
                                    label={txtPergEstrategista}
                                    value={data.score_estrategista}
                                    onChange={(v) => setData('score_estrategista', v)}
                                />
                                {errors.score_estrategista && (
                                    <p className="text-red-400 text-xs">{errors.score_estrategista}</p>
                                )}
                            </div>

                            {/* Analista — so renderiza quando survey.tem_analista === true (D-07) */}
                            {survey.tem_analista && (
                                <div className="space-y-1.5">
                                    <RatingPicker
                                        label={txtPergAnalista}
                                        value={data.score_analista}
                                        onChange={(v) => setData('score_analista', v)}
                                    />
                                    {errors.score_analista && (
                                        <p className="text-red-400 text-xs">{errors.score_analista}</p>
                                    )}
                                </div>
                            )}

                            {/* Empresa — sempre presente (required) */}
                            <div className="space-y-1.5">
                                <RatingPicker
                                    label={txtPergEmpresa}
                                    value={data.score_empresa}
                                    onChange={(v) => setData('score_empresa', v)}
                                />
                                {errors.score_empresa && (
                                    <p className="text-red-400 text-xs">{errors.score_empresa}</p>
                                )}
                            </div>
                        </div>

                        {/* Phase 33 Plan 03: perguntas customizadas ativas — D-03 ordem:
                            entram apos as 3 fixas e antes do comentario livre.
                            Cada pergunta delega o render ao componente PerguntaExtra (4 tipos). */}
                        {perguntasExtras.length > 0 && (
                            <div className="space-y-5">
                                {perguntasExtras.map((p) => (
                                    <div key={p.id} className="space-y-3">
                                        <label className="block text-xs text-white/70 font-medium uppercase tracking-wide">
                                            {p.texto}
                                            {p.obrigatorio && (
                                                <span className="text-ecf-yellow ml-1">*</span>
                                            )}
                                        </label>
                                        <PerguntaExtra
                                            pergunta={p}
                                            valor={data.respostas_extras[p.id]}
                                            onChange={(v) =>
                                                setData('respostas_extras', {
                                                    ...data.respostas_extras,
                                                    [p.id]: v,
                                                })
                                            }
                                            error={errors[`respostas_extras.${p.id}`]}
                                        />
                                        {errors[`respostas_extras.${p.id}`] && (
                                            <p className="text-red-400 text-xs">
                                                {errors[`respostas_extras.${p.id}`]}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Comentario livre — D-08: textarea unica, max 2000, nullable */}
                        <div className="space-y-1.5">
                            <Label className="text-white/80">{txtComentarioLabel}</Label>
                            <Textarea
                                value={data.comment}
                                onChange={(e) => setData('comment', e.target.value)}
                                placeholder={txtComentarioPlaceholder}
                                rows={4}
                                maxLength={2000}
                                className="bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 min-h-[100px]"
                            />
                            {errors.comment && (
                                <p className="text-red-400 text-xs">{errors.comment}</p>
                            )}
                        </div>

                        {/* Botao submit — desabilitado ate validar */}
                        <Button
                            type="submit"
                            className="w-full bg-ecf-yellow text-black hover:bg-ecf-yellow/90 font-semibold"
                            disabled={processing || !isValid}
                        >
                            {processing ? 'Enviando...' : 'Enviar avaliação'}
                        </Button>
                    </form>
                </div>

                <p className="text-center text-white/40 text-xs mt-4">
                    ECF Consultoria · Suas respostas são confidenciais
                </p>
            </div>
        </div>
    );
}
