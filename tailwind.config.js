/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./index.html", "./resources/**/*.{js,vue,php}"],
  theme: {
    extend: {
      maxWidth: {
        "8xl": "90rem",
      },
    },
  },
  plugins: [],
};
