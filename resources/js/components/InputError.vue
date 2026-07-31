<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="font-label-sm text-label-sm text-red-500 mt-1">
            {{ message }}
        </p>
    </div>
</template>
