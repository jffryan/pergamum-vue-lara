<template>
  <div>
    <div class="flex justify-between">
      <div class="w-1/2 pr-4">
        <h1>About Pergamum</h1>
        <p>
          The Library of Pergamum was a testament to the relentless human
          pursuit of knowledge that once rivaled Alexandria. Yet, as the sands
          of time swept over its magnificent halls, much of its wisdom faded
          into obscurity.
        </p>
        <h2>Lorem ipsum dolor</h2>
        <p>
          "Pergamum," is inspired by this ancient citadel of information,
          capturing the essence of archiving and preserving while acknowledging
          the reality of impermanence. As you journey through the pages of your
          personal literary world, Pergamum serves as both a guide and a
          guardian, helping you cherish the stories of yesterday and today, even
          as we remain mindful that time erodes even the grandest of archives.
        </p>
        <p>
          But just as the legacy of Pergamum lives on, not in bricks and scrolls
          but in the spirit of inquiry, this platform aims to immortalize your
          literary pursuits in a digital realm. While physical books may weather
          and crumble, the tales they tell and the emotions they evoke remain
          timeless. By tracking, organizing, and reflecting on your reading
          experiences, Pergamum ensures that the stories you love are never
          truly lost.
        </p>
        <h2>Sit amet consectetur ad elipsing elit.</h2>
        <p>
          Dive deep into a world where every chapter you explore becomes a part
          of a lasting digital legacy, an homage to the libraries of old and the
          undying quest for knowledge.
        </p>
        <h3>Veritus glipitae dolarnus?</h3>
        <div>
          <button class="btn btn-primary mr-4">Login</button>
          <router-link to="/" class="btn btn-secondary"
            >Return home</router-link
          >
        </div>
      </div>
      <div class="w-1/2 pl-4">
        <figure class="droplet">
          <img src="/images/pergamum-about.jpg" alt="Pergamum" />
        </figure>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "AboutView",
};
</script>

<style scoped>
.droplet img {
  height: auto;
  -webkit-mask: url(/images/blob-two.svg) no-repeat center / contain;
  mask: url(/images/blob-two.svg) no-repeat center / contain;
}
</style>
