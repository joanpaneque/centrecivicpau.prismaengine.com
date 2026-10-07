<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    strokeWidth: {
        type: Number,
        default: 34,
    },
    strokeColor: {
        type: String,
        default: 'currentColor',
    },
    cameraDistance: {
        type: Number,
        default: 5.2,
    },
    cameraPitchDeg: {
        type: Number,
        default: 5,
    },
    fovDeg: {
        type: Number,
        default: 36,
    },
    /** Si es false, solo el canvas (sin sliders ni panel de controles). */
    showControls: {
        type: Boolean,
        default: true,
    },
    /** Desplazamiento vertical del dibujo en px CSS (+ abajo en el canvas). */
    offsetYPx: {
        type: Number,
        default: 0,
    },
    /**
     * Si es true: sin hover la rotación Y del modelo es fija (`idleRotationYDeg`);
     * con hover rota con el tiempo como siempre. Requiere `isHovered` desde el padre.
     */
    spinWhenHovered: {
        type: Boolean,
        default: false,
    },
    isHovered: {
        type: Boolean,
        default: false,
    },
    /** Rotación Y en grados cuando `spinWhenHovered` y no hay hover. */
    idleRotationYDeg: {
        type: Number,
        default: 45,
    },
    /**
     * Sin `spinWhenHovered`: si es > 0, la velocidad angular pasa de alta a `AUTO_Y_SPEED`
     * en esa duración (ms), ease-out. Si es 0, giro uniforme como siempre.
     */
    autoSpinRampMs: {
        type: Number,
        default: 0,
    },
    /**
     * Sin `spinWhenHovered`: ms quieto al montar antes de que empiece el giro automático.
     */
    autoSpinDelayMs: {
        type: Number,
        default: 0,
    },
    /**
     * Solo sin `spinWhenHovered`: si es true, con `isHovered` la rotación automática
     * se congela; al salir del hover sigue desde el mismo ángulo.
     */
    pauseAutoRotateWhenHovered: {
        type: Boolean,
        default: false,
    },
    /** Ms para volver al ángulo de reposo al quitar el hover (`spinWhenHovered`). 0 = instantáneo. */
    spinOutMs: {
        type: Number,
        default: 2200,
    },
});

const canvasRef = ref(null);
let rafId = 0;

/** Hover spin: ancla temporal, ángulo acumulado (rad) y último frame para Δt. */
const hoverSpinAnchorMs = ref(null);
const hoverSpinAngleRad = ref(null);
const hoverSpinLastFrameMs = ref(null);

/** Cámara (sliders; se inicializan desde props donde aplica). */
const camPitchDeg = ref(props.cameraPitchDeg);
const camYawDeg = ref(0);

watch(
    () => props.cameraPitchDeg,
    (v) => {
        camPitchDeg.value = v;
    },
);

const autoRotatePyramid = ref(true);

/** Rotación manual de la pirámide (grados). Con auto-rotación, Y suma como desfase. */
const pyrRotXDeg = ref(0);
const pyrRotYDeg = ref(0);
const pyrRotZDeg = ref(0);

/** Color del trazo (#rrggbb). Si el prop no es un color CSS fijo, se usa un gris por defecto. */
const pyramidColor = ref(
    props.strokeColor && props.strokeColor !== 'currentColor'
        ? props.strokeColor
        : '#262626',
);

watch(
    () => props.strokeColor,
    (v) => {
        if (v && v !== 'currentColor') {
            pyramidColor.value = v;
        }
    },
);

/** Espejo vertical de la vista (pirámide al revés en pantalla). */
const invertVertical = ref(true);

const AUTO_Y_SPEED = 0.0012;

/** Duración del arranque en hover (rápido → lento); luego ω constante. */
const HOVER_SPIN_EASE_MS = 2800;
const HOVER_SPIN_OMEGA_START = AUTO_Y_SPEED * 6;
const HOVER_SPIN_OMEGA_END = AUTO_Y_SPEED;

const spinOutActive = ref(false);
const spinOutStartMs = ref(null);
const spinOutStartAngleRad = ref(null);
const spinOutTargetAngleRad = ref(null);
const hoverIntroSpinRad = ref(0);

/** Freeze del auto-giro (hero) mientras `isHovered` con `pauseAutoRotateWhenHovered`. */
const pauseHoverFrozenAutoRad = ref(null);

/** Auto-giro con arranque rápido → lento (`autoSpinRampMs` > 0). */
const autoSpinSessionStartMs = ref(null);
const autoSpinSpinStartMs = ref(null);
const autoSpinRampAnchorMs = ref(null);
const autoSpinLastFrameMs = ref(null);
const autoSpinAngleRad = ref(0);

/** ω inicial del ramp respecto a la velocidad base (más alto = más rápido al inicio). */
const AUTO_SPIN_RAMP_OMEGA_MULT = 14;

const TWO_PI = 2 * Math.PI;

/** Al completar esta vuelta (7×360°) se resta 1×360° → equivalente a 6 vueltas; misma orientación visible. */
const HOVER_MAX_FULL_TURNS = 7;
const HOVER_MAX_EXTRA_RAD = HOVER_MAX_FULL_TURNS * TWO_PI;

/**
 * Mantiene el desplazamiento Y respecto a idle en [0, 7×360°).
 * Si completa la 7.ª vuelta (≥ 7×360°), resta 360° una o más veces → como si llevara 6 vueltas.
 */
function foldHoverExtraPastMaxTurns(idleRad, absoluteRad) {
    let extra = absoluteRad - idleRad;

    if (extra < 0) {
        extra = 0;
    }

    while (extra >= HOVER_MAX_EXTRA_RAD) {
        extra -= TWO_PI;
    }

    return idleRad + extra;
}

function positiveModulo(value, period) {
    return ((value % period) + period) % period;
}

function subtract(a, b) {
    return [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
}

function dot(a, b) {
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
}

function cross(a, b) {
    return [
        a[1] * b[2] - a[2] * b[1],
        a[2] * b[0] - a[0] * b[2],
        a[0] * b[1] - a[1] * b[0],
    ];
}

function normalize(v) {
    const L = Math.hypot(v[0], v[1], v[2]);

    return L > 1e-12 ? [v[0] / L, v[1] / L, v[2] / L] : [0, 0, 0];
}

function rotateX(x, y, z, a) {
    const c = Math.cos(a);
    const s = Math.sin(a);

    return { x, y: y * c - z * s, z: y * s + z * c };
}

function rotateY(x, y, z, a) {
    const c = Math.cos(a);
    const s = Math.sin(a);

    return { x: x * c + z * s, y, z: -x * s + z * c };
}

function rotateZ(x, y, z, a) {
    const c = Math.cos(a);
    const s = Math.sin(a);

    return { x: x * c - y * s, y: x * s + y * c, z };
}

/** Orden: X → Y → Z (mundo). */
function applyModelRotation(x, y, z, rx, ry, rz) {
    let p = rotateX(x, y, z, rx);
    p = rotateY(p.x, p.y, p.z, ry);
    p = rotateZ(p.x, p.y, p.z, rz);

    return [p.x, p.y, p.z];
}

function buildCameraBasis(eye, target) {
    let worldUp = [0, 1, 0];
    const forward = normalize(subtract(target, eye));

    if (Math.abs(dot(forward, worldUp)) > 0.998) {
        worldUp = [0, 0, 1];
    }

    const right = normalize(cross(worldUp, forward));
    const up = normalize(cross(forward, right));

    return { right, up, forward };
}

/** Esféricas: yaw alrededor de Y, pitch desde el plano horizontal. +Z frente con yaw 0. */
function cameraEye(dist, pitchRad, yawRad) {
    const cp = Math.cos(pitchRad);

    return [
        dist * cp * Math.sin(yawRad),
        dist * Math.sin(pitchRad),
        dist * cp * Math.cos(yawRad),
    ];
}

function projectWorldToScreen(
    world,
    rx,
    ry,
    rz,
    centerY,
    eye,
    right,
    up,
    forward,
    cx,
    cy,
    focal,
    invertY,
    offsetYPx,
) {
    const x = world[0];
    const y = world[1] - centerY;
    const z = world[2];
    const pw = applyModelRotation(x, y, z, rx, ry, rz);

    const v = subtract(pw, eye);
    const xCam = dot(v, right);
    const yCam = dot(v, up);
    const zCam = dot(v, forward);

    if (zCam <= 1e-4) {
        return null;
    }

    const k = focal / zCam;
    const sy = cy - yCam * k;
    const yScreen = invertY ? 2 * cy - sy : sy;

    return {
        x: cx + xCam * k,
        y: yScreen + offsetYPx,
    };
}

function drawFrame(timeMs) {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.clientWidth;
    const cssH = canvas.clientHeight;

    if (cssW === 0 || cssH === 0) {
        rafId = requestAnimationFrame(drawFrame);

        return;
    }

    const bw = Math.round(cssW * dpr);
    const bh = Math.round(cssH * dpr);

    if (canvas.width !== bw || canvas.height !== bh) {
        canvas.width = bw;
        canvas.height = bh;
    }

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    const cx = cssW / 2;
    const cy = cssH / 2;
    const minDim = Math.min(cssW, cssH);
    const fovRad = (props.fovDeg * Math.PI) / 180;
    /** Perspectiva: encuadre estable para cualquier tamaño de canvas. */
    let focal = minDim / 2 / Math.tan(fovRad / 2);

    /** Modo logo (cuadrado ~150×150): un poco más de zoom para que la pirámide llene el área. */
    if (!props.showControls) {
        focal *= 1.12;
    }

    /**
     * Grosor nominal (prop) pensado para vistas grandes (~480px+). En canvas pequeños
     * escala hacia abajo para que la forma siga leyéndose (p. ej. 150×150).
     */
    const strokeRef = 480;
    const lineW = Math.max(
        0.75,
        props.strokeWidth * Math.min(1, minDim / strokeRef),
    );

    /** Escala del modelo 3D (base + altura) respecto a la unidad base. */
    const modelScale = 0.82;
    const half = 1 * modelScale;
    const height = 2.25 * modelScale;
    const centerY = height / 2;
    const base = [
        [half, 0, half],
        [half, 0, -half],
        [-half, 0, -half],
        [-half, 0, half],
    ];
    const apex = [0, height, 0];

    const pitchRad = (camPitchDeg.value * Math.PI) / 180;
    const yawRad = (camYawDeg.value * Math.PI) / 180;
    const eye = cameraEye(props.cameraDistance, pitchRad, yawRad);
    const target = [0, 0, 0];
    const { right, up, forward } = buildCameraBasis(eye, target);

    const rx = (pyrRotXDeg.value * Math.PI) / 180;
    const rz = (pyrRotZDeg.value * Math.PI) / 180;
    const ryManual = (pyrRotYDeg.value * Math.PI) / 180;
    const idleRad = (props.idleRotationYDeg * Math.PI) / 180;
    let ry;

    if (props.spinWhenHovered) {
        if (props.isHovered) {
            if (
                spinOutActive.value &&
                spinOutStartAngleRad.value !== null &&
                spinOutTargetAngleRad.value !== null
            ) {
                const spinOutMs = Math.max(0, props.spinOutMs);
                const soElapsed = timeMs - spinOutStartMs.value;
                const soU =
                    spinOutMs > 0 ? Math.min(soElapsed / spinOutMs, 1) : 1;
                const soEase = 1 - (1 - soU) ** 3;
                hoverSpinAngleRad.value =
                    spinOutStartAngleRad.value +
                    (spinOutTargetAngleRad.value - spinOutStartAngleRad.value) *
                        soEase;
                spinOutActive.value = false;
                spinOutStartMs.value = null;
                spinOutStartAngleRad.value = null;
                spinOutTargetAngleRad.value = null;
            }

            if (hoverSpinAnchorMs.value === null) {
                hoverSpinAnchorMs.value = timeMs;
                hoverSpinLastFrameMs.value = timeMs;
                hoverIntroSpinRad.value = 0;

                if (hoverSpinAngleRad.value === null) {
                    hoverSpinAngleRad.value = idleRad;
                }
            }

            const deltaMs = Math.min(
                Math.max(0, timeMs - hoverSpinLastFrameMs.value),
                64,
            );
            hoverSpinLastFrameMs.value = timeMs;

            const elapsed = timeMs - hoverSpinAnchorMs.value;
            const previousElapsed = Math.max(0, elapsed - deltaMs);
            const u = Math.min(elapsed / HOVER_SPIN_EASE_MS, 1);
            /** Ease-out cúbico: ω alta al inicio y tendiendo a ω constante. */
            const ease = 1 - (1 - u) ** 3;
            const omega =
                HOVER_SPIN_OMEGA_START +
                (HOVER_SPIN_OMEGA_END - HOVER_SPIN_OMEGA_START) * ease;

            hoverSpinAngleRad.value += omega * deltaMs;
            const introDeltaMs =
                Math.min(elapsed, HOVER_SPIN_EASE_MS) -
                Math.min(previousElapsed, HOVER_SPIN_EASE_MS);

            if (introDeltaMs > 0) {
                hoverIntroSpinRad.value += omega * introDeltaMs;
            }

            hoverSpinAngleRad.value = foldHoverExtraPastMaxTurns(
                idleRad,
                hoverSpinAngleRad.value,
            );
            ry = hoverSpinAngleRad.value + ryManual;
        } else {
            if (
                hoverSpinAnchorMs.value !== null &&
                hoverSpinAngleRad.value !== null
            ) {
                if (props.spinOutMs > 0) {
                    const visualExtra = positiveModulo(
                        hoverSpinAngleRad.value - idleRad,
                        TWO_PI,
                    );
                    const introFullTurns =
                        Math.floor(hoverIntroSpinRad.value / TWO_PI) * TWO_PI;
                    spinOutStartAngleRad.value = idleRad + visualExtra;
                    spinOutTargetAngleRad.value = idleRad - introFullTurns;
                    spinOutStartMs.value = timeMs;
                    spinOutActive.value = true;
                } else {
                    spinOutActive.value = false;
                    spinOutStartMs.value = null;
                    spinOutStartAngleRad.value = null;
                    spinOutTargetAngleRad.value = null;
                    hoverIntroSpinRad.value = 0;
                }
            }

            hoverSpinAnchorMs.value = null;
            hoverSpinLastFrameMs.value = null;
            hoverSpinAngleRad.value = null;

            if (spinOutActive.value) {
                const elapsedOut = timeMs - spinOutStartMs.value;
                const tOut = Math.min(elapsedOut / props.spinOutMs, 1);
                const easeOut = 1 - (1 - tOut) ** 3;
                const angleOut =
                    spinOutStartAngleRad.value +
                    (spinOutTargetAngleRad.value - spinOutStartAngleRad.value) *
                        easeOut;
                ry = angleOut + ryManual;

                if (tOut >= 1) {
                    spinOutActive.value = false;
                    spinOutStartMs.value = null;
                    spinOutStartAngleRad.value = null;
                    spinOutTargetAngleRad.value = null;
                    hoverIntroSpinRad.value = 0;
                }
            } else {
                ry = idleRad + ryManual;
            }
        }
    } else {
        spinOutActive.value = false;
        spinOutStartMs.value = null;
        spinOutStartAngleRad.value = null;
        spinOutTargetAngleRad.value = null;
        hoverSpinAnchorMs.value = null;
        hoverSpinLastFrameMs.value = null;
        hoverSpinAngleRad.value = null;
        hoverIntroSpinRad.value = 0;

        if (
            props.pauseAutoRotateWhenHovered &&
            pauseHoverFrozenAutoRad.value !== null &&
            !props.isHovered
        ) {
            const frozen = pauseHoverFrozenAutoRad.value;
            pauseHoverFrozenAutoRad.value = null;
            const delay = props.autoSpinDelayMs;
            const pastDelay =
                autoSpinSessionStartMs.value !== null &&
                timeMs - autoSpinSessionStartMs.value >= delay;

            if (pastDelay && autoSpinSpinStartMs.value !== null) {
                if (props.autoSpinRampMs > 0) {
                    autoSpinAngleRad.value = frozen;
                    autoSpinLastFrameMs.value = timeMs;

                    if (autoSpinRampAnchorMs.value === null) {
                        autoSpinRampAnchorMs.value = timeMs;
                    }
                } else {
                    autoSpinSpinStartMs.value = timeMs - frozen / AUTO_Y_SPEED;
                }
            }
        }

        if (!autoRotatePyramid.value) {
            autoSpinSessionStartMs.value = null;
            autoSpinSpinStartMs.value = null;
            autoSpinRampAnchorMs.value = null;
            autoSpinLastFrameMs.value = null;
            ry = ryManual;
        } else {
            if (autoSpinSessionStartMs.value === null) {
                autoSpinSessionStartMs.value = timeMs;
            }

            const delay = props.autoSpinDelayMs;

            if (timeMs - autoSpinSessionStartMs.value < delay) {
                ry = ryManual;
            } else {
                if (autoSpinSpinStartMs.value === null) {
                    autoSpinSpinStartMs.value = timeMs;
                }

                if (props.autoSpinRampMs > 0) {
                    if (autoSpinRampAnchorMs.value === null) {
                        autoSpinRampAnchorMs.value = autoSpinSpinStartMs.value;
                        autoSpinLastFrameMs.value = timeMs;
                        autoSpinAngleRad.value = 0;
                    }

                    const deltaMs = Math.min(
                        Math.max(0, timeMs - autoSpinLastFrameMs.value),
                        64,
                    );
                    autoSpinLastFrameMs.value = timeMs;

                    const elapsed = timeMs - autoSpinRampAnchorMs.value;
                    const ramp = props.autoSpinRampMs;
                    const omegaEnd = AUTO_Y_SPEED;
                    const omegaStart = AUTO_Y_SPEED * AUTO_SPIN_RAMP_OMEGA_MULT;
                    let omega;

                    if (elapsed >= ramp) {
                        omega = omegaEnd;
                    } else {
                        const u = elapsed / ramp;
                        /** Ease-out cúbico: rápido al inicio, suave al final. */
                        const ease = 1 - (1 - u) ** 3;
                        omega = omegaStart + (omegaEnd - omegaStart) * ease;
                    }

                    autoSpinAngleRad.value += omega * deltaMs;
                    ry = autoSpinAngleRad.value + ryManual;
                } else {
                    autoSpinRampAnchorMs.value = null;
                    autoSpinLastFrameMs.value = null;
                    ry =
                        (timeMs - autoSpinSpinStartMs.value) * AUTO_Y_SPEED +
                        ryManual;
                }
            }
        }

        if (props.pauseAutoRotateWhenHovered && props.isHovered) {
            const autoPart = ry - ryManual;

            if (pauseHoverFrozenAutoRad.value === null) {
                pauseHoverFrozenAutoRad.value = autoPart;
            }

            ry = pauseHoverFrozenAutoRad.value + ryManual;
        }
    }

    const invY = invertVertical.value;
    const offsetY = props.offsetYPx;

    ctx.strokeStyle =
        props.strokeColor === 'currentColor'
            ? getComputedStyle(canvas).color
            : pyramidColor.value;
    ctx.lineWidth = lineW;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';

    function line3(a, b) {
        const p0 = projectWorldToScreen(
            a,
            rx,
            ry,
            rz,
            centerY,
            eye,
            right,
            up,
            forward,
            cx,
            cy,
            focal,
            invY,
            offsetY,
        );
        const p1 = projectWorldToScreen(
            b,
            rx,
            ry,
            rz,
            centerY,
            eye,
            right,
            up,
            forward,
            cx,
            cy,
            focal,
            invY,
            offsetY,
        );

        if (!p0 || !p1) {
            return;
        }

        ctx.beginPath();
        ctx.moveTo(p0.x, p0.y);
        ctx.lineTo(p1.x, p1.y);
        ctx.stroke();
    }

    for (let i = 0; i < 4; i++) {
        line3(base[i], base[(i + 1) % 4]);
    }

    for (let i = 0; i < 4; i++) {
        line3(apex, base[i]);
    }

    rafId = requestAnimationFrame(drawFrame);
}

function startLoop() {
    cancelAnimationFrame(rafId);
    rafId = requestAnimationFrame(drawFrame);
}

onMounted(() => {
    startLoop();
});

onUnmounted(() => {
    cancelAnimationFrame(rafId);
});
</script>

<template>
    <div
        class="flex min-h-0 w-full flex-col"
        :class="showControls ? 'gap-4' : 'h-full'"
        suppressHydrationWarning
    >
        <div
            class="relative"
            :class="
                showControls ? 'min-h-[200px] flex-1' : 'h-full min-h-0 w-full'
            "
        >
            <canvas
                ref="canvasRef"
                class="block h-full w-full"
                :class="{ 'min-h-[200px]': showControls }"
                aria-hidden="true"
            />
        </div>

        <div
            v-if="showControls"
            class="flex shrink-0 flex-col gap-3 border-t border-neutral-200 pt-4 text-sm text-neutral-700 dark:border-neutral-700 dark:text-neutral-300"
        >
            <p class="font-medium text-neutral-900 dark:text-neutral-100">
                Cámara
            </p>
            <label class="flex flex-col gap-1">
                <span class="flex justify-between gap-2">
                    <span>Inclinación X (pitch)</span>
                    <span class="text-neutral-500 tabular-nums"
                        >{{ Math.round(camPitchDeg) }}°</span
                    >
                </span>
                <input
                    v-model.number="camPitchDeg"
                    type="range"
                    min="-55"
                    max="55"
                    step="1"
                    class="w-full accent-[#0040c1]"
                />
            </label>
            <label class="flex flex-col gap-1">
                <span class="flex justify-between gap-2">
                    <span>Rotación Y (yaw)</span>
                    <span class="text-neutral-500 tabular-nums"
                        >{{ Math.round(camYawDeg) }}°</span
                    >
                </span>
                <input
                    v-model.number="camYawDeg"
                    type="range"
                    min="-180"
                    max="180"
                    step="1"
                    class="w-full accent-[#0040c1]"
                />
            </label>

            <p class="mt-1 font-medium text-neutral-900 dark:text-neutral-100">
                Pirámide
            </p>
            <label class="flex flex-col gap-2">
                <span>Color del trazo</span>
                <div class="flex items-center gap-3">
                    <input
                        v-model="pyramidColor"
                        type="color"
                        class="h-10 w-14 cursor-pointer rounded border border-neutral-300 bg-neutral-100 p-1 dark:border-neutral-600 dark:bg-neutral-800"
                    />
                    <code
                        class="rounded bg-neutral-100 px-2 py-1 font-mono text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400"
                        >{{ pyramidColor }}</code
                    >
                </div>
            </label>
            <label class="flex cursor-pointer items-center gap-2">
                <input
                    v-model="invertVertical"
                    type="checkbox"
                    class="size-4 rounded border-neutral-300 accent-[#0040c1]"
                />
                <span>Invertir verticalmente (del revés)</span>
            </label>
            <label class="flex cursor-pointer items-center gap-2">
                <input
                    v-model="autoRotatePyramid"
                    type="checkbox"
                    class="size-4 rounded border-neutral-300 accent-[#0040c1]"
                />
                <span>Auto rotar (eje Y)</span>
            </label>
            <label class="flex flex-col gap-1">
                <span class="flex justify-between gap-2">
                    <span>Rotación X</span>
                    <span class="text-neutral-500 tabular-nums"
                        >{{ Math.round(pyrRotXDeg) }}°</span
                    >
                </span>
                <input
                    v-model.number="pyrRotXDeg"
                    type="range"
                    min="-180"
                    max="180"
                    step="1"
                    class="w-full accent-[#0040c1]"
                />
            </label>
            <label class="flex flex-col gap-1">
                <span class="flex justify-between gap-2">
                    <span
                        >Rotación Y{{
                            autoRotatePyramid ? ' (desfase)' : ''
                        }}</span
                    >
                    <span class="text-neutral-500 tabular-nums"
                        >{{ Math.round(pyrRotYDeg) }}°</span
                    >
                </span>
                <input
                    v-model.number="pyrRotYDeg"
                    type="range"
                    min="-180"
                    max="180"
                    step="1"
                    class="w-full accent-[#0040c1]"
                />
            </label>
            <label class="flex flex-col gap-1">
                <span class="flex justify-between gap-2">
                    <span>Rotación Z</span>
                    <span class="text-neutral-500 tabular-nums"
                        >{{ Math.round(pyrRotZDeg) }}°</span
                    >
                </span>
                <input
                    v-model.number="pyrRotZDeg"
                    type="range"
                    min="-180"
                    max="180"
                    step="1"
                    class="w-full accent-[#0040c1]"
                />
            </label>
        </div>
    </div>
</template>
