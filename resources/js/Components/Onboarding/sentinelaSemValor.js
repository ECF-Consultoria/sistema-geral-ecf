// Sentinela para a opção "Nenhum" dos Selects opcionais do Onboarding.
//
// O Radix proíbe `<SelectItem value="">`: lança erro em runtime e derruba o
// render inteiro (tela preta). Usamos um valor não-vazio e mapeamos de volta
// para '' antes de enviar ao backend. Mesmo padrão de
// `resources/js/Pages/Mlb/OnboardingFicha.jsx`.
//
// Morava em `Components/Onboarding/Templates/`, junto do builder de template;
// subiu um nível quando o builder saiu, porque o painel (`EmpresaCard.jsx`)
// depende dela para o Select de responsável.
export const SEM_VALOR = '__none__';

export const limparSemValor = (obj) =>
    Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, v === SEM_VALOR ? '' : v]));
