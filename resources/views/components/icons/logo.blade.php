<svg id="mainLogoSvg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
    <defs>
        <!-- Gradientes Dinâmicos -->
        <linearGradient id="gradPrimary" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#8b5cf6" id="stopPrimary1"></stop>
            <stop offset="100%" stop-color="#4f46e5" id="stopPrimary2"></stop>
        </linearGradient>

        <linearGradient id="gradSecondary" x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stop-color="#4f46e5" id="stopSecondary1"></stop>
            <stop offset="100%" stop-color="#030099" id="stopSecondary2"></stop>
        </linearGradient>

        <linearGradient id="gradAccent" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stop-color="#06b6d4" id="stopAccent1"></stop>
            <stop offset="100%" stop-color="#8b5cf6" id="stopAccent2"></stop>
        </linearGradient>

        <linearGradient id="gradCrownHighlight" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#ffffff" stop-opacity="0.6"></stop>
            <stop offset="100%" stop-color="#ffffff" stop-opacity="0.0"></stop>
        </linearGradient>

        <!-- Sombras -->
        <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="8" result="blur"></feGaussianBlur>
            <feComposite in="SourceGraphic" in2="blur" operator="over"></feComposite>
        </filter>
        
        <filter id="dropShadow" x="-10%" y="-10%" width="120%" height="120%">
            <feDropShadow dx="0" dy="8" stdDeviation="6" flood-color="#000000" flood-opacity="0.4"></feDropShadow>
        </filter>
    </defs>

    <!-- GRUPO DO SÍMBOLO (ÍCONE DENTE + TECNOLOGIA) -->
    <g id="symbolGroup" transform="scale(0.1676) translate(-8.4, -13.5)" filter="url(#dropShadow)">
        <!-- Camada Traseira / Estrutura Protética (Hexágono Tecnológico de Suporte) -->
        <path d="M 80 15 L 135 45 L 135 115 L 80 145 L 25 115 L 25 45 Z" fill="none" stroke="url(#gradSecondary)" stroke-width="3" stroke-dasharray="6,4" opacity="0.4"></path>

        <!-- Camada Externa: Silhueta Dinâmica do Dente / Coroa Ceramic Tooth -->
        <path id="toothOuter" d="M 80 25 C 105 25, 128 35, 130 65 C 132 95, 115 110, 105 138 C 98 155, 88 158, 80 142 C 72 158, 62 155, 55 138 C 45 110, 28 95, 30 65 C 32 35, 55 25, 80 25 Z" fill="none" stroke="url(#gradPrimary)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"></path>

        <!-- Faceta Cerâmica Interna (Efeito Sorriso / Cúspide Anatômica) -->
        <path id="toothFacet" d="M 50 60 C 65 50, 95 50, 110 60 C 118 85, 102 118, 80 130 C 58 118, 42 85, 50 60 Z" fill="url(#gradPrimary)" opacity="0.25"></path>

        <!-- Curva Central de Ajuste Digital / Articulação Prótese -->
        <path d="M 52 75 C 70 90, 90 90, 108 75" fill="none" stroke="url(#gradAccent)" stroke-width="5" stroke-linecap="round"></path>

        <!-- Destaque de Brilho / Polimento Esmalte -->
        <path d="M 42 55 C 45 42, 60 33, 80 33" fill="none" stroke="url(#gradCrownHighlight)" stroke-width="4" stroke-linecap="round"></path>

        <!-- Nós Tecnológicos CAD/CAM (Pontos de Escaneamento 3D) -->
        <g id="techNodes" style="display: block;">
            <!-- Ponto Central de Precisão -->
            <circle cx="80" cy="88" r="5" fill="url(#gradAccent)"></circle>
            <circle cx="80" cy="88" r="9" fill="none" stroke="url(#gradAccent)" stroke-width="1.5" opacity="0.6"></circle>
            <!-- Linha Interativa de Laser CAD/CAM -->
            <line x1="20" y1="88" x2="140" y2="88" stroke="url(#gradAccent)" stroke-width="1.5" stroke-dasharray="3,3" opacity="0.5"></line>
        </g>
    </g>
</svg>