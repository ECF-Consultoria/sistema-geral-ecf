import { AlertTriangle } from 'lucide-react';

export default function Expired() {
    return (
        <div className="min-h-screen bg-background flex items-center justify-center p-4">
            <div className="text-center space-y-4 max-w-sm">
                <AlertTriangle className="h-20 w-20 text-yellow-400 mx-auto" />
                <h1 className="text-2xl font-bold text-foreground">Link Expirado</h1>
                <p className="text-muted-foreground">Este link de pesquisa expirou. Por favor, solicite um novo link à ECF Consultoria.</p>
            </div>
        </div>
    );
}
