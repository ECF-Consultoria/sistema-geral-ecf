import PpaIndex from '../../Ppa/Index';

// PPA Polos (quick 260805-dzu) — mesma tela do PPA de carteira, recortada nas
// empresas do projeto POLOS. O controller (PolosPpaController) manda `escopo` e
// `rotas`; o componente de verdade vive em Pages/Ppa/Index.jsx.
//
// Página própria (e não reuso direto de 'Ppa/Index') porque o menu marca o item
// ativo por prefixo do nome do componente — 'Polos/Ppa/...' não colide com 'Ppa'.
export default function PolosPpaIndex(props) {
    return <PpaIndex {...props} />;
}
