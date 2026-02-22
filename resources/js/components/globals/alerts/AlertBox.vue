<script setup>
defineProps({
    message: {
        type: String,
        required: true,
    },
    alertType: {
        type: String,
        default: "primary",
    },
});
</script>

<template>
    <div class="alert" :class="'alert-' + alertType" role="alert">
        <span class="block font-bold">{{ message }}</span>
    </div>
</template>
