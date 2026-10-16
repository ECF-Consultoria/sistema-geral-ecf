import AppLayout from '@/Layouts/AppLayout';
import Planejador from '@/Components/PlanejadorFull/Planejador';

// Planejador FULL na tela interna (equipe logada). A tela em si é o componente Planejador,
// o mesmo do link aberto do cliente (PlanejadorFull/Publico).
export default function Show(props) {
    return (
        <AppLayout title={`Planejador FULL · ${props.empresa.nome}`}>
            {/* key: trocar de empresa remonta tudo (os ajustes locais são da empresa). */}
            <Planejador key={props.empresa.id} modo="interno" {...props} />
        </AppLayout>
    );
}
