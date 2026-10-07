export default function (Alpine) {
    Alpine.directive('zoom', (el, { expression }, { evaluate, effect }) => {
        if (!expression) return;

        // Estilização do contêiner
        el.style.position = 'relative';
        el.style.overflow = 'hidden';
        el.style.userSelect = 'none';
        el.style.touchAction = 'none';
        el.style.cursor = 'grab';

        const img = el.querySelector('img') || el;

        if (img !== el) {
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'contain';
        }
        img.style.transformOrigin = '0 0';
        img.style.transition = 'transform 100ms ease-out';
        img.style.pointerEvents = 'none';

        let scale = 1;
        let translateX = 0;
        let translateY = 0;

        // Estados para Drag / Touch
        let isDragging = false;
        let startX = 0;
        let startY = 0;

        // Estados para Pinça (Mobile)
        let isPinching = false;
        let initialDistance = 0;
        let initialScale = 1;
        let initialTranslateX = 0;
        let initialTranslateY = 0;
        let centerPoint = { x: 0, y: 0 };

        const getDistance = (touches) => {
            const dx = touches[0].clientX - touches[1].clientX;
            const dy = touches[0].clientY - touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        };

        const getCenter = (touches, rect) => {
            return {
                x: ((touches[0].clientX + touches[1].clientX) / 2) - rect.left,
                y: ((touches[0].clientY + touches[1].clientY) / 2) - rect.top
            };
        };

        // Mantém a imagem dentro dos limites do contêiner quando expandida
        const clampBounds = () => {
            const rect = el.getBoundingClientRect();
            const maxTranslateX = 0;
            const minTranslateX = rect.width * (1 - scale);
            const maxTranslateY = 0;
            const minTranslateY = rect.height * (1 - scale);

            if (scale > 1) {
                translateX = Math.min(maxTranslateX, Math.max(minTranslateX, translateX));
                translateY = Math.min(maxTranslateY, Math.max(minTranslateY, translateY));
            } else {
                translateX = 0;
                translateY = 0;
            }
        };

        const applyTransform = () => {
            clampBounds();
            img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
        };

        const reset = () => {
            scale = 1;
            translateX = 0;
            translateY = 0;
            isDragging = false;
            isPinching = false;
            el.style.cursor = 'grab';
            img.style.transition = 'transform 200ms ease-out';
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

        // DESKTOP: Wheel Zoom
        el.addEventListener('wheel', (e) => {
            if (e.ctrlKey || e.metaKey || true) { // Permite zoom via wheel direto ou com Ctrl
                e.preventDefault();

                const rect = el.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                const zoomFactor = 0.2;
                const oldScale = scale;

                if (e.deltaY < 0) {
                    scale = Math.min(6, scale + zoomFactor);
                } else {
                    scale = Math.max(1, scale - zoomFactor);
                }

                translateX = mouseX - (mouseX - translateX) * (scale / oldScale);
                translateY = mouseY - (mouseY - translateY) * (scale / oldScale);

                applyTransform();
            }
        }, { passive: false });

        // DESKTOP: Mouse Drag
        el.addEventListener('mousedown', (e) => {
            if (scale > 1) {
                isDragging = true;
                el.style.cursor = 'grabbing';
                startX = e.clientX - translateX;
                startY = e.clientY - translateY;
            }
        });

        window.addEventListener('mousemove', (e) => {
            if (isDragging) {
                translateX = e.clientX - startX;
                translateY = e.clientY - startY;
                applyTransform();
            }
        });

        window.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                el.style.cursor = 'grab';
            }
        });

        el.addEventListener('dblclick', reset);

        // MOBILE: Touch Events
        el.addEventListener('touchstart', (e) => {
            const rect = el.getBoundingClientRect();

            if (e.touches.length === 2) {
                // Início do Zoom com dois dedos (Pinça)
                isPinching = true;
                isDragging = false;
                initialDistance = getDistance(e.touches);
                initialScale = scale;
                initialTranslateX = translateX;
                initialTranslateY = translateY;
                centerPoint = getCenter(e.touches, rect);
            } else if (e.touches.length === 1 && scale > 1) {
                // Início do Pan/Mover com um dedo (quando ampliado)
                isDragging = true;
                isPinching = false;
                startX = e.touches[0].clientX - translateX;
                startY = e.touches[0].clientY - translateY;
            }
        }, { passive: true });

        el.addEventListener('touchmove', (e) => {
            const rect = el.getBoundingClientRect();

            if (isPinching && e.touches.length === 2) {
                e.preventDefault();
                const currentDistance = getDistance(e.touches);
                const factor = currentDistance / initialDistance;
                const newScale = Math.max(1, Math.min(6, initialScale * factor));

                const currentCenter = getCenter(e.touches, rect);

                // Aplica o zoom proporcional ao centro dos dedos
                translateX = currentCenter.x - (centerPoint.x - initialTranslateX) * (newScale / initialScale);
                translateY = currentCenter.y - (centerPoint.y - initialTranslateY) * (newScale / initialScale);
                scale = newScale;

                applyTransform();
            } else if (isDragging && e.touches.length === 1 && scale > 1) {
                e.preventDefault();
                translateX = e.touches[0].clientX - startX;
                translateY = e.touches[0].clientY - startY;
                applyTransform();
            }
        }, { passive: false });

        el.addEventListener('touchend', (e) => {
            if (e.touches.length < 2) {
                isPinching = false;
            }
            if (e.touches.length === 0) {
                isDragging = false;
            }
        });
    });
}