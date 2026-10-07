import { reactive } from 'vue';
import type { PendingPrintJob } from './types';

export type { PendingPrintJob };

const done = new Set<string>();

export const printQueue = reactive({
    jobs: [] as PendingPrintJob[],
});

export function setPrintJobs(jobs: PendingPrintJob[]): void {
    printQueue.jobs = jobs.filter((job) => !done.has(job.uuid));
}

export function markPrintJobDone(uuid: string): void {
    done.add(uuid);
    printQueue.jobs = printQueue.jobs.filter((job) => job.uuid !== uuid);
}
