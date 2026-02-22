<template>
    <main>
        <h1 class="text-2xl font-bold mb-4">Admin Home</h1>
       <div>
        <router-link :to="{ name: 'admin.formats' }" class="underline mb-4">Manage Formats</router-link>
       </div>
    </main>
</template>