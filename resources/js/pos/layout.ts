import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import { state } from './store';

/** Big enough screen held sideways: a waiter tablet, not a phone (even a phone turned sideways). */
const wideScreen = useMediaQuery('(min-width: 768px) and (min-height: 500px)');

/**
 * Compact layout for devices registered as waiter tablets: smaller top bar, floor plan zoomed
 * to its tables and the order screen as a side panel next to the products.
 */
export const tabletLayout = computed(() => state.device?.type === 'tablet' && wideScreen.value);
