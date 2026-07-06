// Cor do gasto de ADS por limiar universal — COMPARTILHADA entre /polos/empresas
// (Polos/Empresas.jsx) e o Painel Polos (Polos/Painel.jsx) para não divergir.
//   vermelho (>= alerta2) · amarelo (>= alerta1) · verde (< alerta1)
// Defaults: alerta1 = R$1.000, alerta2 = R$2.000 (teto padrão de R$3.000/empresa).
export function corAds(gasto, limites) {
    const alerta1 = (limites?.alerta1) ?? 1000;
    const alerta2 = (limites?.alerta2) ?? 2000;
    if (gasto >= alerta2) return '#ef4444'; // vermelho
    if (gasto >= alerta1) return '#ffe600'; // amarelo / ecf-yellow
    return '#22c55e';                       // verde
}
