<script setup>
import StorefrontLayout from '@/Layouts/StorefrontLayout.vue';
import Button from '@/Components/Button.vue';

defineOptions({ layout: StorefrontLayout });
</script>

<template>
    <div>
        <!-- Hero Section -->
        <section class="relative overflow-hidden bg-surface-container-low">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16 sm:py-24 lg:py-32">
                <div class="max-w-lg">
                    <p class="label-gilt text-xs text-primary">Seleccion de Temporada</p>
                    <h1 class="headline-serif mt-3 text-4xl sm:text-5xl font-semibold text-on-surface leading-tight">
                        Elegancia en<br>
                        <em>Cada Detalle</em>
                    </h1>
                    <p class="mt-4 text-base text-on-surface-variant leading-relaxed">
                        Descubre nuestra coleccion curada de arreglos y regalos artesanales,
                        disenados para convertir momentos fugaces en recuerdos eternos.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-4">
                        <Button variant="primary" size="lg">
                            Explorar Catalogo
                        </Button>
                        <Button variant="secondary" size="lg">
                            Nuestra Historia
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
            <div class="flex items-center justify-between">
                <h2 class="headline-serif text-2xl font-semibold text-on-surface">
                    Colecciones Curadas
                </h2>
                <p class="hidden sm:block text-sm italic font-serif text-on-surface-variant">
                    Seleccionadas con cuidado para cada ocasion
                </p>
            </div>

            <div class="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div
                    v-for="cat in ['Rosas Eternas', 'Peluches', 'Carteras', 'Llaveros']"
                    :key="cat"
                    class="rounded-xl bg-surface-container-lowest p-6 text-center hover:shadow-ambient transition-all duration-200 cursor-pointer"
                >
                    <div class="mx-auto h-12 w-12 rounded-xl bg-surface-container-high flex items-center justify-center">
                        <span class="text-lg text-primary">*</span>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-on-surface">{{ cat }}</p>
                    <p class="mt-1 text-xs text-on-surface-variant">Ver coleccion</p>
                </div>
            </div>
        </section>

        <!-- Products placeholder -->
        <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
            <div class="flex items-center justify-center h-64 rounded-xl bg-surface-container-low">
                <p class="text-on-surface-variant">Productos disponibles pronto</p>
            </div>
        </section>
    </div>
</template>
