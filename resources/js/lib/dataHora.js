// ─── Formatação de data/hora do Publicador (Fase 184, plano 12) ────────────
//
// Extraído de `Pages/Mlb/Publicador/Configuracoes.jsx` para não duplicar a
// mesma formatação em `Components/Mlb/Publicador/BlingDaLoja.jsx` — duas
// implementações do mesmo formato é exatamente o tipo de divergência que
// esta fase está evitando em todo lugar (ver o ERP declarado × Visão geral).

/** Formata uma data ISO para "dd/mm/aaaa às HH:mm"; nulo ou inválida não mostra nada. */
export function formatarDataHora(iso) {
    if (typeof iso !== 'string' || iso.trim() === '') return null;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return null;
    const data = d.toLocaleDateString('pt-BR');
    const hora = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    return `${data} às ${hora}`;
}
