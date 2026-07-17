import { ShieldAlert } from 'lucide-react';

export default function Blocked() {
    return (
        <div className="min-h-screen bg-background flex items-center justify-center p-4">
            <div className="text-center space-y-4 max-w-sm">
                <ShieldAlert className="h-20 w-20 text-yellow-400 mx-auto" />
                <h1 className="text-2xl font-bold text-foreground">Não foi possível registrar sua resposta</h1>
                <p className="text-muted-foreground">
                    Esta pesquisa não pode ser respondida a partir desta janela. Se você
                    é o destinatário, abra o link em uma janela anônima ou em outro
                    navegador para responder normalmente.
                </p>
            </div>
        </div>
    );
}
