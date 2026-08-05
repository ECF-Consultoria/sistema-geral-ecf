import PpaKanban from '../../Ppa/Kanban';

// Kanban do PPA Polos (quick 260805-dzu). Mesmo quadro do PPA de carteira — as
// tarefas (ppa.tasks.*) e o link do workspace do cliente são compartilhados.
// Componente em Pages/Ppa/Kanban.jsx; aqui só o nome de página do escopo Polos.
export default function PolosPpaKanban(props) {
    return <PpaKanban {...props} />;
}
