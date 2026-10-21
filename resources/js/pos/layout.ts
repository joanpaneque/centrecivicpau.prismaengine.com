import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import { state } from './store';

/**
 * A tablet, not a phone: the short side of the physical screen (phones stay under ~430px
 * even sideways). The window height is not used, the browser bars eat too much of it.
 */
const tabletScreen = typeof window !== 'undefined' && Math.min(window.screen.width, window.screen.height) >= 500;
const wideWindow = useMediaQuery('(min-width: 640px)');

/**
 * Compact layout for devices registered as waiter tablets: smaller top bar, floor plan zoomed
 * to its tables and the order screen as a side panel next to the products.
 */
export const tabletLayout = computed(() => state.device?.type === 'tablet' && tabletScreen && wideWindow.value);
