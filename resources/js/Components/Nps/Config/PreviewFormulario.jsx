import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * PreviewFormulario — Phase 70 Plan 05 v15.0.
 *
 * Componente **PURO** que renderiza a versão pública do formulário NPS
 * a partir de um template normalizado (shape do `POST /preview` — Plan 70-04).
 *
 *   - `mode === 'preview'` — usado no admin (Configuracao.jsx). Botões
 *     aparecem clicáveis para simular seleção, mas o rodapé indica que é
 *     preview e não persiste nada.
 *   - `mode === 'live'`    — reservado para a Phase 71 (Respond.jsx público).
 *     Neste modo os botões são interativos e o rodapé mostra CTA "Enviar".
 *
 * REGRA CRÍTICA DE PORTABILIDADE (research §5):
 *   Nenhuma chamada de rede. Nenhum hook de submissao Inertia. Nenhum
 *   dispatcher de navegacao. O componente recebe o template ja pronto e
 *   renderiza. A Phase 71 vai importar ESTE arquivo identico e envolve-lo
 *   com o hook de submit do Respond.jsx.
 *
 * Shape esperado do prop `template`:
 *   {
 *     nome: string,
 *     descricao?: string | null,
 *     perguntas: Array<{
 *       ordem: number,
 *       texto: string,
 *       tipo: 'escala' | 'opcoes',
 *       dimensao: 'estrategista' | 'analista' | 'empresa' | 'geral',
 *       obrigatoria: boolean,
 *       options: Array<{ ordem: number, label: string, peso: number }>,
 *     }>
 *   }
 *
 * Layout mobile-first (max-w-md) — mesma largura útil do form real no cliente.
 */
export default function PreviewFormulario({ template, mode = 'preview' }) {
    // Estado local só para feedback visual do "botão selecionado" — não é
    // persistido em lugar nenhum. `{ [perguntaOrdem]: opcaoPeso }`.
    const [respostas, setRespostas] = useState({});

    if (!template) {
        return (
            <div className="max-w-md mx-auto bg-ecf-card border border-white/[0.08] rounded-2xl p-6 text-center">
                <p className="text-white/40 text-sm">Nenhum template para exibir.</p>
            </div>
        );
    }

    const perguntas = template.perguntas ?? template.questions ?? [];

    const selecionar = (perguntaOrdem, opcaoPeso) => {
        // Preview permite feedback visual — não faz nada além do state local.
        setRespostas((prev) => ({ ...prev, [perguntaOrdem]: opcaoPeso }));
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
                const selecionado = respostas[q.ordem ?? idx];
                // Grid heurístico: até 5 opções usa grid-cols-N (compacto); acima
                // disso empilha em 1 coluna para não quebrar em mobile.
                // ATENÇÃO: Tailwind JIT não detecta classes dinâmicas via template
                // literal, então precisamos usar strings literais explícitas para
                // que o safelist não seja necessário.
                const gridByCount = {
                    1: 'grid-cols-1',
                    2: 'grid-cols-2',
                    3: 'grid-cols-3',
                    4: 'grid-cols-4',
                    5: 'grid-cols-5',
                };
                const gridClass = opcoes.length === 0
                    ? 'grid-cols-1'
                    : opcoes.length <= 5
                        ? gridByCount[opcoes.length]
                        : 'grid-cols-1';

                return (
                    <div key={q.ordem ?? idx} className="space-y-2.5">
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
                            <div className={cn('grid gap-1.5', gridClass)}>
                                {opcoes.map((o, j) => {
                                    const isActive = selecionado === o.peso;
                                    return (
                                        <button
                                            type="button"
                                            key={o.ordem ?? j}
                                            onClick={() => selecionar(q.ordem ?? idx, o.peso)}
                                            className={cn(
                                                'px-2.5 py-2 rounded-lg border text-[12.5px] font-medium transition-colors text-center break-words',
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
                    </div>
                );
            })}

            {/* Rodapé — muda conforme mode */}
            <footer className="pt-4 border-t border-white/[0.06]">
                {mode === 'preview' ? (
                    <p className="text-white/40 text-[11.5px] text-center italic">
                        Preview — as respostas não são salvas.
                    </p>
                ) : (
                    <button
                        type="button"
                        disabled
                        className="w-full py-2.5 rounded-lg bg-ecf-yellow text-[#050507] font-semibold text-[13.5px] opacity-70 cursor-not-allowed"
                        title="Wrapper Phase 71 substitui este botão pelo submit real."
                    >
                        Enviar respostas
                    </button>
                )}
            </footer>
        </div>
    );
}
