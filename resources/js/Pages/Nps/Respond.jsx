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

export default function NpsRespond({ survey }) {
    // Estado do form — chaves alinhadas com NpsController::submitResponse (Plan 31-02 Task 3c)
    const { data, setData, post, processing, errors } = useForm({
        respondent_name: '',
        score_estrategista: null,
        score_analista: null,
        score_empresa: null,
        comment: '',
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
    const isValid =
        data.score_estrategista !== null &&
        data.score_empresa !== null &&
        (!survey.tem_analista || data.score_analista !== null);

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
