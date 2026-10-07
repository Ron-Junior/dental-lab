export default function (Alpine) {
    Alpine.directive('zoom', (el, { expression }, { evaluate, effect }) => {
        if (!expression) return;

        // Estilização do contêiner para permitir o pan
        el.style.position = 'relative';
        el.style.overflow = 'hidden';
        el.style.userSelect = 'none';
        el.style.touchAction = 'none';
        el.style.cursor = 'grab';

        const img = el.querySelector('img') || el;

        if (img !== el) {
            img.style.width = '100%';
            img.style.height = '100%';
        }
        img.style.transformOrigin = '0 0'; // Mudado para 0 0 para cálculo preciso de coordenadas de foco
        img.style.transition = 'transform 100ms ease-out';
        img.style.pointerEvents = 'none'; // Impede a tag img de roubar eventos de clique do pai

        let scale = 1;
        let translateX = 0;
        let translateY = 0;

        // Estados para o Drag/Pan (Arrastar)
        let isDragging = false;
        let dragStartX = 0;
        let dragStartY = 0;

        // Estados para Mobile (Pinça)
        let isZoomingMobile = false;
        let initialDistance = 0;
        let touchStartX = 0;
        let touchStartY = 0;

        const getDistance = (e) => {
            if (e.touches.length < 2) return 0;
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        };

        const getCenter = (e) => {
            if (e.touches.length < 2) return { x: 0, y: 0 };
            return {
                x: (e.touches[0].clientX + e.touches[1].clientX) / 2,
                y: (e.touches[0].clientY + e.touches[1].clientY) / 2
            };
        };

        const applyTransform = () => {
            img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
        };

        const reset = () => {
            scale = 1;
            translateX = 0;
            translateY = 0;
            isDragging = false;
            el.style.cursor = 'grab';
            img.style.transition = 'transform 200ms ease-out'; // Volta suave ao normal
            applyTransform();
            setTimeout(() => {
                img.style.transition = 'transform 100ms ease-out';
            }, 200);
        };

        effect(() => {
            try {
                const currentSrc = evaluate(expression);
                if (currentSrc) {
                    img.src = currentSrc;
                    reset();
                }
            } catch (e) {
                console.error("Erro ao avaliar x-zoom:", e);
            }
        });

        // DESKTOP: Zoom com Ctrl + Roda do Mouse na Posição Exata do Cursor
        el.addEventListener('wheel', (e) => {
            if (e.ctrlKey) {
                e.preventDefault();

                const rect = el.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                const zoomFactor = 0.2;
                const oldScale = scale;

                if (e.deltaY < 0) {
                    scale = Math.min(6, scale + zoomFactor); // Máximo 6x
                } else {
                    scale = Math.max(1, scale - zoomFactor); // Mínimo 1x
                }

                // Ajusta as coordenadas para dar zoom exatamente onde o mouse está apontando
                translateX = mouseX - (mouseX - translateX) * (scale / oldScale);
                translateY = mouseY - (mouseY - translateY) * (scale / oldScale);

                if (scale === 1) {
                    translateX = 0;
                    translateY = 0;
                }

                applyTransform();
            }
        }, { passive: false });

        // DESKTOP: Mover a imagem arrastando (Pan)
        el.addEventListener('mousedown', (e) => {
            if (scale > 1) {
                isDragging = true;
                el.style.cursor = 'grabbing';
                dragStartX = e.clientX - translateX;
                dragStartY = e.clientY - translateY;
            }
        });

        window.addEventListener('mousemove', (e) => {
            if (isDragging) {
                translateX = e.clientX - dragStartX;
                translateY = e.clientY - dragStartY;
                applyTransform();
            }
        });

        window.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                el.style.cursor = 'grab';
            }
        });

        el.addEventListener('mouseleave', () => {
            // Se preferir que resete ao tirar o mouse do quadrado, mantenha a linha abaixo desativada por comentário.
            // reset();
        });

        // Adiciona um duplo clique rápido para resetar a visualização
        el.addEventListener('dblclick', () => {
            reset();
        });

        // MOBILE: Gesto de pinça + Arrastar com dois dedos
        el.addEventListener('touchstart', (e) => {
            if (e.touches.length === 2) {
                isZoomingMobile = true;
                initialDistance = getDistance(e);
                const center = getCenter(e);
                touchStartX = center.x - translateX;
                touchStartY = center.y - translateY;
            }
        });

        el.addEventListener('touchmove', (e) => {
            if (isZoomingMobile && e.touches.length === 2) {
                const currentDistance = getDistance(e);
                scale = Math.max(1, Math.min(6, (currentDistance / initialDistance)));

                const center = getCenter(e);
                translateX = center.x - touchStartX;
                translateY = center.y - touchStartY;
                applyTransform();
            }
        });

        el.addEventListener('touchend', (e) => {
            if (e.touches.length < 2) isZoomingMobile = false;
            if (e.touches.length === 0 && scale > 1) {
                // Mantém o zoom fixado no mobile até que dê um duplo toque ou mude de imagem
            }
        });
    });
}