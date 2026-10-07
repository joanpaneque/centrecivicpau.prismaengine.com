<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PrismaEngineLogo from '@/components/PrismaEngineLogo.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const page = usePage();

const sessionHref = computed(() =>
    page.props.auth.user ? dashboard() : login(),
);

const sessionLabel = computed(() =>
    page.props.auth.user ? 'Ir al panel' : 'Iniciar sesión',
);
</script>

<template>
    <Head :title="page.props.name" />

    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background px-6 py-16 text-foreground"
    >
        <main class="flex max-w-sm flex-col items-center text-center">
            <h1>
                <img
                    src="/images/logo-centre-civic.png"
                    :alt="page.props.name"
                    class="h-28 w-auto"
                />
            </h1>

            <Button
                type="button"
                class="mt-8 min-w-40"
                @click="router.visit(sessionHref)"
            >
                {{ sessionLabel }}
            </Button>

            <div class="mt-14 flex flex-col items-center gap-3">
                <p class="text-sm text-muted-foreground">Software hecho por</p>
                <a
                    href="https://prismaengine.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="Prisma Engine"
                >
                    <PrismaEngineLogo
                        :size="36 / 150"
                        product="Engine"
                        accent-color="#0040c1"
                    />
                </a>
            </div>
        </main>
    </div>
</template>
