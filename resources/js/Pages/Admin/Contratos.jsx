import AppLayout from '@/Layouts/AppLayout';

// Fase 131 Plano 03 (Task 1) — placeholder MÍNIMO. Existe aqui só para o
// Inertia conseguir resolver o componente e o manifest do Vite ter a
// entrada (sem isso o teste de gate da Task 1 nem chega a 200 — a
// resolução da view acontece antes do componente renderizar). A tela real
// (grid de resumo, tabela, busca, paginação) é construída na Task 2 deste
// mesmo plano, no mesmo arquivo.
export default function Contratos() {
    return (
        <AppLayout title="Adm · Contratos">
            <main className="p-6" />
        </AppLayout>
    );
}
