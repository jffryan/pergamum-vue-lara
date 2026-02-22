<template>
    <main>
        <div class="flex">
            <div class="w-1/2 flex justify-center flex-col">
                <h1 class="text-6xl font-bold">ERROR 404</h1>
                <p class="text-3xl">Page not found</p>
            </div>
            <div class="w-1/2">
                <figure class="droplet">
                    <img src="/images/pergamum-home.jpg" alt="Pergamum" />
                </figure>
            </div>
        </div>
    </main>
</template>

<script>
export default {
    name: "ErrorNotFoundView",
};
</script>

<style scoped>
.droplet img {
    height: auto;
    border-radius: 100%;
    /* -webkit-mask: url(/images/blob-two.svg) no-repeat center / contain;
  mask: url(/images/blob-two.svg) no-repeat center / contain; */
}
</style>
