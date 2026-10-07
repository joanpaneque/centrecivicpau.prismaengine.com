/** Revelación letra a letra en el splash (opacidad + X + blur). */
export const OPACITY_STAGGER_STEP_MS = 55;
export const OPACITY_STAGGER_CHAR_MS = 220;

/** Desplazamiento inicial leve desde la izquierda (px). */
export const REVEAL_OFFSET_X_PX = 10;
/** Blur inicial por letra (px). */
export const REVEAL_BLUR_PX = 5;

export function opacityStaggerTotalMs(charCount) {
    if (charCount <= 1) {
        return OPACITY_STAGGER_CHAR_MS;
    }

    return (charCount - 1) * OPACITY_STAGGER_STEP_MS + OPACITY_STAGGER_CHAR_MS;
}
