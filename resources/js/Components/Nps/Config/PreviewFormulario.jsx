import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * PreviewFormulario — Phase 70 Plan 05 v15.0 + Phase 71 Plan 02 refactor.
 *
 * Componente **PURO** que renderiza a versão pública do formulário NPS
 * a partir de um template normalizado (shape do `POST /preview` — Plan 70-04).
 *
 *   - `mode === 'preview'` — usado no admin (Configuracao.jsx). Botões
 *     aparecem clicáveis para simular seleção, mas o rodapé indica que é
 *     preview e não persiste nada. Sem props `value/onChange` → usa state interno.
 *   - `mode === 'live'`    — usado no público (Nps/Respond.jsx). Neste modo
 *     o componente é **controlled**: recebe `value` (map { [pergunta_id]:
 *     option_id }) + `onChange` (setter). Também aceita `errors` (map
 *     { [pergunta_id]: mensagem }) para renderizar mensagem de erro por
 *     pergunta. O rodapé em `live` NÃO renderiza nada — o wrapper Respond.jsx
 *     posiciona seus próprios campos (nome/comentário/submit) fora.
 *
 * REGRA CRÍTICA DE PORTABILIDADE (research §5):
 *   Nenhuma chamada de rede. Nenhum hook de submissao Inertia. Nenhum
 *   dispatcher de navegacao. O componente recebe o template ja pronto e
 *   renderiza. O Respond.jsx envolve esse componente com o hook de submit.
 *
 * Shape esperado do prop `template`:
 *   {
 *     nome: string,
 *     descricao?: string | null,
 *     perguntas: Array<{
 *       id?: number,          // presente em Phase 71 (v15 público), ausente em preview cru
 *       ordem: number,
 *       texto: string,
 *       tipo: 'escala' | 'opcoes',
 *       dimensao: 'estrategista' | 'analista' | 'empresa' | 'geral',
 *       obrigatoria: boolean,
 *       options: Array<{ id?: number, ordem: number, label: string, peso: number }>,
 *     }>
 *   }
 *
 * Identificador de pergunta/opção normalizado — Phase 71 (público) precisa
 * mandar `option_id` para o backend; Phase 70 (preview cru sem save) só
 * precisa de um key visual, então cai no `ordem`/`peso`. Ver helpers
 * `perguntaKey` e `optionKey` abaixo.
 *
 * Layout mobile-first (max-w-md) — mesma largura útil do form real no cliente.
 *
 * @param {object}   props
 * @param {object}   props.template  Template normalizado (obrigatório em runtime).
 * @param {'preview'|'live'} [props.mode='preview']
 * @param {object}   [props.value]    Map { [perguntaId]: optionId } — modo controlled.
 * @param {function} [props.onChange] Handler `(perguntaId, optionId) => void` — modo controlled.
 * @param {object}   [props.errors]   Map { [perguntaId]: string } para erro por pergunta.
 */
export default function PreviewFormulario({
    template,
    mode = 'preview',
    value,
    onChange,
    errors,
}) {
    // ─── Detecção de modo controlled ────────────────────────────────────────
    // Se o wrapper (Phase 71 Respond.jsx) passou value + onChange, delegamos
    // a fonte da verdade ao pai. Caso contrário (Phase 70 Configuracao.jsx),
    // mantemos o estado local original — retrocompat total.
    const isControlled = typeof value === 'object' && value !== null && typeof onChange === 'function';
    const [internalRespostas, setInternalRespostas] = useState({});
    const respostas = isControlled ? value : internalRespostas;

    // ─── Helpers de chave estável ──────────────────────────────────────────
    // Public (Phase 71) precisa mandar `option_id` real ao backend — usa `id`.
    // Preview cru (Phase 70) recebe template sem `id` (ex: rascunho em edição)
    // — cai no `peso`/`ordem` só para diferenciar visualmente as opções.
    const perguntaKey = (q) => q.id ?? q.ordem;
    const optionKey = (o) => o.id ?? o.peso;

    if (!template) {
        return (
            <div className="max-w-md mx-auto bg-ecf-card border border-white/[0.08] rounded-2xl p-6 text-center">
                <p className="text-white/40 text-sm">Nenhum template para exibir.</p>
            </div>
        );
    }

    const perguntas = template.perguntas ?? template.questions ?? [];

    const selecionar = (pergunta, opcao) => {
        const pk = perguntaKey(pergunta);
        const ok = optionKey(opcao);
        if (isControlled) {
            onChange(pk, ok);
        } else {
            // Preview permite feedback visual — state local só para UX.
            setInternalRespostas((prev) => ({ ...prev, [pk]: ok }));
        }
    };

    return (
        <div className="max-w-md mx-auto bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-5">
            {/* Cabeçalho — nome + descrição do template */}
            <header className="space-y-1.5 pb-4 border-b border-white/[0.06]">
                <h2 className="text-white text-lg font-semibold tracking-tight">
                    {template.nome || '(Sem título)'}
                </h2>
                {template.descricao && (
                    <p className="text-white/60 text-[13px] leading-relaxed">
                        {template.descricao}
                    </p>
                )}
            </header>

            {/* Corpo: cada pergunta é um bloco */}
            {perguntas.length === 0 && (
                <p className="text-white/40 text-[13px] italic text-center py-4">
                    Nenhuma pergunta cadastrada — adicione perguntas para ver o preview.
                </p>
            )}

            {perguntas.map((q, idx) => {
                const opcoes = q.options ?? [];
                const pk = perguntaKey(q);
                const selecionado = respostas[pk];
                // Bugfix 2026-07-08 — flex-wrap adaptativo em vez de grid-cols
                // rígido. Labels longas (ex: "Na maioria das vezes", "Sim,
                // totalmente") ficavam quebrando palavras em botões de 20% de
                // largura. Agora cada botão cresce até o conteúdo caber e
                // quebra para a próxima linha só quando necessário.
                //   - flex-1: cresce igualmente para preencher a linha
                //   - min-w: garante clique confortável em mobile
                //   - basis: ancora o tamanho ideal antes do wrap decidir
                // Labels curtas (1..5 da escala) continuam alinhadas em linha
                // única. Labels longas quebram naturalmente sem cortar palavras.
                const hasLongLabels = opcoes.some(
                    (o) => (o.label ?? '').length > 4,
                );
                const buttonBasis = hasLongLabels
                    ? 'basis-[calc(50%-4px)] sm:basis-[calc(33%-6px)]'
                    : 'basis-0';

                return (
                    <div key={pk ?? idx} className="space-y-2.5">
                        <label className="block">
                            <span className="text-white text-[13.5px] font-medium leading-snug">
                                {q.texto || '(Pergunta sem texto)'}
                                {q.obrigatoria && (
                                    <span className="text-red-400 ml-1" title="Obrigatória">*</span>
                                )}
                            </span>
                        </label>

                        {opcoes.length === 0 ? (
                            <p className="text-white/40 text-[11.5px] italic">
                                Nenhuma opção cadastrada nesta pergunta.
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-1.5">
                                {opcoes.map((o, j) => {
                                    const ok = optionKey(o);
                                    const isActive = selecionado === ok;
                                    return (
                                        <button
                                            type="button"
                                            key={ok ?? j}
                                            onClick={() => selecionar(q, o)}
                                            className={cn(
                                                'flex-1 min-w-[64px] px-2.5 py-2 rounded-lg border text-[12.5px] font-medium transition-colors text-center leading-tight break-words',
                                                buttonBasis,
                                                isActive
                                                    ? 'bg-ecf-yellow text-[#050507] border-ecf-yellow'
                                                    : 'bg-white/[0.03] text-white/70 border-white/[0.08] hover:bg-white/[0.08] hover:text-white',
                                            )}
                                        >
                                            {o.label}
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        {/* Erro server-side por pergunta — só quando errors[pk] presente
                            (modo live com useForm errors mapeados para questão). */}
                        {errors?.[pk] && (
                            <p className="text-red-400 text-xs mt-1">{errors[pk]}</p>
                        )}
                    </div>
                );
            })}

            {/* Rodapé — muda conforme mode.
                'preview' → aviso de que respostas não são salvas (Phase 70).
                'live'    → NADA renderizado aqui; o Respond.jsx (Phase 71) posiciona
                            fora do componente os campos nome/comentário/submit. */}
            <footer className="pt-4 border-t border-white/[0.06]">
                {mode === 'preview' && (
                    <p className="text-white/40 text-[11.5px] text-center italic">
                        Preview — as respostas não são salvas.
                    </p>
                )}
            </footer>
        </div>
    );
}
