<template>
    <main>
        <div>
            <div class="w-1/2 flex justify-center flex-col">
                <h1 class="text-6xl font-bold">Pergamum</h1>
                <p class="text-3xl">Library management</p>
                <p class="text-xl">v {{ version }}</p>
            </div>
        </div>
    </main>
</template>

<script>
export default {
    name: "HomeView",
    data() {
        return {
            // Injected by `define` in vite.config.js from the newest
            // CHANGELOG.md entry — see resources/js/utils/changelogVersion.js.
            version: __APP_VERSION__,
        };
    },
};
</script>

<style scoped>
.droplet img {
    height: auto;
    border-radius: 100%;
}
</style>
