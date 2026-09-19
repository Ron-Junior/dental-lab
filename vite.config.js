import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0', // Permite que o container Docker responda às requisições do Vite
        port: 5173,      // Porta interna fixada para o servidor do Vite
        strictPort: true,
        hmr: {
            host: 'localhost', // Endereço de atualização em tempo real no seu navegador
        },
    }
});
