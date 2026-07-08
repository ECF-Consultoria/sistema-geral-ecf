import { useMemo } from 'react';
import { useForm, Head } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import PreviewFormulario from '@/Components/Nps/Config/PreviewFormulario';
import RespondLegado from './RespondLegado';

/**
 * Phase 71 Plan 02 — Formulário público dinâmico v15.0.
 *
 * Roteamento por presença do prop `template`:
 *   - `template` presente (survey.template_id !== null) → renderiza fluxo
 *     dinâmico usando o PreviewFormulario controlled (mesmo componente
 *     visual do Phase 70) + campos nome/comentário + submit v15.
 *   - `template` null → delega ao RespondLegado (Phase 33 preservado byte-a-byte).
 *
 * Contrato submit v15 (Phase 69-03 NpsController::submitResponseV15):
 *   POST /nps/{token}
 *   { respondent_name, comment, answers: { [question_id]: option_id } }
 *
 * O submit é desabilitado até que todas as perguntas obrigatórias tenham
 * resposta (client-side guard); server-side também valida com Rule::in
 * (redundância defensiva — cliente contornando via devtools cai no 422).
 *
 * Layout mobile-first (max-w-md), sem AppLayout — o cliente que responde
 * NÃO é usuário logado do painel; página standalone com branding ECF.
 */
export default function Respond({ survey, perguntas_extras, template }) {
    // ─── Rota legacy: template null → delega Phase 33 preservado ────────
    if (!template) {
        return <RespondLegado survey={survey} perguntas_extras={perguntas_extras} />;
    }

    return <RespondV15 survey={survey} template={template} />;
}

/**
 * Componente interno v15.0 — separado para preservar ordem/estabilidade
 * dos hooks (delegação early-return acima nunca chega neste bloco).
 */
function RespondV15({ survey, template }) {
    const { data, setData, post, processing, errors } = useForm({
        respondent_name: '',
        comment: '',
        answers: {},
    });

    // Handler passado ao PreviewFormulario controlado — recebe
    // (questionId, optionId) e mescla no map `answers`.
    const setAnswer = (questionId, optionId) => {
        setData('answers', { ...data.answers, [questionId]: optionId });
    };

    // Client-side guard: botão só habilita quando todas as perguntas
    // marcadas com `obrigatoria` tiverem um `option_id` selecionado.
    const perguntas = template.perguntas ?? [];
    const podeEnviar = useMemo(() => {
        const obrigatorias = perguntas.filter((q) => q.obrigatoria);
        return obrigatorias.every((q) => {
            const v = data.answers[q.id];
            return v !== undefined && v !== null;
        });
    }, [perguntas, data.answers]);

    // Mapa de erros por pergunta — Laravel devolve validation errors com
    // chave `answers.<question_id>`. Convertemos para `{ [qid]: mensagem }`
    // e passamos ao PreviewFormulario para renderizar inline.
    const errorsByQuestion = useMemo(() => {
        const map = {};
        Object.entries(errors || {}).forEach(([key, msg]) => {
            const match = key.match(/^answers\.(\d+)$/);
            if (match) map[Number(match[1])] = msg;
        });
        return map;
    }, [errors]);

    const submit = (e) => {
        e.preventDefault();
        post(route('nps.submit', survey.token), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Pesquisa de satisfação — ECF" />
            <div className="min-h-screen bg-ecf-bg text-white py-8 px-4">
                <div className="max-w-md mx-auto">
                    {/* Header — apenas nome da empresa e título neutro.
                        Zero jargão técnico visível ao cliente. */}
                    <header className="mb-6 text-center space-y-1">
                        <h1 className="text-white text-xl font-semibold tracking-tight">
                            Pesquisa de satisfação
                        </h1>
                        <p className="text-white/60 text-sm">{survey.company_name}</p>
                    </header>

                    <form onSubmit={submit} className="space-y-5">
                        {/* PreviewFormulario controlled: renderiza dinamicamente as
                            perguntas do template + opções (radio group cinza→amarelo).
                            Apenas texto da pergunta e labels visíveis ao cliente —
                            metadados internos ficam escondidos. */}
                        <PreviewFormulario
                            template={template}
                            mode="live"
                            value={data.answers}
                            onChange={setAnswer}
                            errors={errorsByQuestion}
                        />

                        {/* Campo comentário livre — opcional, max 2000 chars.
                            Placeholder em pt-BR sem jargão. */}
                        <div className="max-w-md mx-auto bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-3">
                            <Label htmlFor="comment" className="text-white text-sm font-medium">
                                Comentário (opcional)
                            </Label>
                            <Textarea
                                id="comment"
                                value={data.comment}
                                onChange={(e) => setData('comment', e.target.value)}
                                placeholder="Escreva aqui, se quiser deixar uma observação."
                                rows={4}
                                maxLength={2000}
                                className="bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30"
                            />
                            {errors.comment && (
                                <p className="text-red-400 text-xs">{errors.comment}</p>
                            )}
                        </div>

                        {/* Campo nome — opcional; nullable no backend. */}
                        <div className="max-w-md mx-auto bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-3">
                            <Label htmlFor="respondent_name" className="text-white text-sm font-medium">
                                Seu nome (opcional)
                            </Label>
                            <Input
                                id="respondent_name"
                                type="text"
                                value={data.respondent_name}
                                onChange={(e) => setData('respondent_name', e.target.value)}
                                placeholder="Como você prefere ser chamado(a)."
                                maxLength={255}
                                className="bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30"
                            />
                            {errors.respondent_name && (
                                <p className="text-red-400 text-xs">{errors.respondent_name}</p>
                            )}
                        </div>

                        {/* Submit — disabled até obrigatórias preenchidas + hint pt-BR.
                            Server-side redundante 422 no NpsController::submitResponseV15. */}
                        <div className="max-w-md mx-auto">
                            <Button
                                type="submit"
                                disabled={!podeEnviar || processing}
                                className="w-full bg-ecf-yellow text-[#050507] font-semibold hover:bg-ecf-yellow/90 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? 'Enviando…' : 'Enviar respostas'}
                            </Button>
                            {!podeEnviar && (
                                <p className="text-white/50 text-xs text-center mt-2">
                                    Responda todas as perguntas marcadas com{' '}
                                    <span className="text-red-400">*</span> para enviar.
                                </p>
                            )}
                        </div>
                    </form>

                    {/* Rodapé — branding ECF, sem jargão técnico. */}
                    <footer className="mt-8 text-center text-white/30 text-xs">
                        ECF Consultoria — pesquisa de satisfação
                    </footer>
                </div>
            </div>
        </>
    );
}
