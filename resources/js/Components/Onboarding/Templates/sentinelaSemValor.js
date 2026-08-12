// Sentinela para a opção "Nenhum" dos Selects opcionais desta tela (`setor_id`,
// `auto_fonte`, `condicao`): o Radix proíbe `<SelectItem value="">` — lança erro
// em runtime e derruba o render inteiro (tela preta). Usamos um valor não-vazio
// e mapeamos de volta para '' antes de enviar ao backend. Mesmo padrão de
// `resources/js/Pages/Mlb/OnboardingFicha.jsx:32-37` — compartilhado aqui num
// arquivo próprio porque tanto a página (`Index.jsx`) quanto o editor de passo
// (`PassoEditor.jsx`) precisam da mesma constante.
export const SEM_VALOR = '__none__';

export const limparSemValor = (obj) =>
    Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, v === SEM_VALOR ? '' : v]));
