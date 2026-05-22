import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

function ScorePicker({ value, onChange, label }) {
    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-foreground">{label}</p>
            <div className="flex gap-2 flex-wrap">
                {Array.from({ length: 11 }, (_, i) => (
                    <button
                        key={i}
                        type="button"
                        onClick={() => onChange(i)}
                        className={cn(
                            'w-10 h-10 rounded-lg border text-sm font-bold transition-all',
                            value === i
                                ? i >= 9 ? 'bg-emerald-500 border-emerald-500 text-white'
                                    : i >= 7 ? 'bg-yellow-500 border-yellow-500 text-white'
                                        : 'bg-red-500 border-red-500 text-white'
                                : 'border-border text-muted-foreground hover:border-primary hover:text-primary bg-card'
                        )}
                    >
                        {i}
                    </button>
                ))}
            </div>
            <div className="flex justify-between text-xs text-muted-foreground">
                <span>Muito ruim</span>
                <span>Excelente</span>
            </div>
        </div>
    );
}

export default function NpsRespond({ survey }) {
    const { data, setData, post, processing, errors } = useForm({
        respondent_name: '',
        score_consultant: null,
        score_mentor: null,
        score_overall: null,
        comment: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('nps.submit', survey.token));
    };

    const isValid = data.respondent_name.trim() &&
        data.score_consultant !== null &&
        data.score_mentor !== null &&
        data.score_overall !== null;

    return (
        <div className="min-h-screen bg-background flex items-center justify-center p-4">
            <div className="w-full max-w-xl">
                <div className="text-center mb-8">
                    <div className="w-16 h-16 rounded-2xl bg-primary flex items-center justify-center mx-auto mb-4">
                        <span className="text-primary-foreground font-bold text-2xl">E</span>
                    </div>
                    <h1 className="text-2xl font-bold text-foreground">Avaliação de Atendimento</h1>
                    <p className="text-muted-foreground mt-1">{survey.company_name}</p>
                    <p className="text-muted-foreground text-sm mt-1">ECF Consultoria</p>
                </div>

                <div className="rounded-xl border border-border bg-card p-6 space-y-6">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-1.5">
                            <Label>Seu nome *</Label>
                            <Input
                                value={data.respondent_name}
                                onChange={e => setData('respondent_name', e.target.value)}
                                placeholder="Digite seu nome"
                                required
                            />
                            {errors.respondent_name && <p className="text-destructive text-xs">{errors.respondent_name}</p>}
                        </div>

                        <div className="space-y-4">
                            <h2 className="text-sm font-semibold text-foreground uppercase tracking-wide">Avalie de 0 a 10</h2>

                            <ScorePicker
                                label={`O que acho do atendimento do ${survey.consultant_name || 'Analista'}`}
                                value={data.score_consultant}
                                onChange={v => setData('score_consultant', v)}
                            />
                            {errors.score_consultant && <p className="text-destructive text-xs">{errors.score_consultant}</p>}

                            <ScorePicker
                                label={`O que acho do atendimento do ${survey.estrategista_name || survey.mentor_name || 'Estrategista'}`}
                                value={data.score_mentor}
                                onChange={v => setData('score_mentor', v)}
                            />
                            {errors.score_mentor && <p className="text-destructive text-xs">{errors.score_mentor}</p>}

                            <ScorePicker
                                label="Nota Geral — Recomendaria a ECF Consultoria?"
                                value={data.score_overall}
                                onChange={v => setData('score_overall', v)}
                            />
                            {errors.score_overall && <p className="text-destructive text-xs">{errors.score_overall}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Comentário (opcional)</Label>
                            <Textarea
                                value={data.comment}
                                onChange={e => setData('comment', e.target.value)}
                                placeholder="Deixe um comentário sobre o atendimento..."
                                rows={3}
                            />
                        </div>

                        <Button type="submit" className="w-full" disabled={processing || !isValid}>
                            {processing ? 'Enviando...' : 'Enviar Avaliação'}
                        </Button>
                    </form>
                </div>

                <p className="text-center text-muted-foreground text-xs mt-4">ECF Consultoria · Suas respostas são confidenciais</p>
            </div>
        </div>
    );
}
