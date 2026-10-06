// ─── Guarda do voltar do navegador (Fase 167, revisão FE-CR-02) ─────────────
//
// O Inertia troca a página no popstate sem o evento `before`, então a guarda de
// "alterações não salvas" de uma tela precisa ver o popstate ANTES dele. No
// próprio `window` os ouvintes rodam na ordem em que foram registrados — pedir
// captura não passa à frente (medido no Chrome 152) —, e o do Inertia nasce
// quando o app monta. Por isso este ouvinte é registrado na importação do
// app.jsx, antes do `createInertiaApp`, e cada tela só liga e desliga a sua
// guarda. Sem guarda ligada, não faz nada.
//
// A guarda recebe o evento e, para segurar a pessoa na tela, chama
// `e.stopImmediatePropagation()`: o Inertia não chega a ver o popstate.

let guarda = null;

/** Liga a guarda da tela; devolve a função que a desliga (só desliga se ainda for a mesma). */
export function definirGuardaDoVoltar(fn) {
    guarda = fn;

    return () => {
        if (guarda === fn) guarda = null;
    };
}

/** O ouvinte único, exportado para teste. */
export function aoVoltarNoHistorico(e) {
    if (guarda) guarda(e);
}

if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    window.addEventListener('popstate', aoVoltarNoHistorico);
}
