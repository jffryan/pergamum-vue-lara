module.exports = {
  env: {
    browser: true,
    es2021: true,
  },
  extends: [
    "airbnb-base",
    "plugin:vue/vue3-essential",
    "plugin:prettier/recommended",
  ],
  settings: {
    "import/resolver": {
      alias: {
        map: [["@", "./resources/js"]],
      },
    },
  },
  overrides: [
    {
      env: {
        node: true,
      },
      files: [".eslintrc.{js,cjs}"],
      parserOptions: {
        sourceType: "script",
      },
    },
  ],
  parserOptions: {
    ecmaVersion: "latest",
    sourceType: "module",
  },
  plugins: ["vue"],
  rules: {
    quotes: ["error", "double"],
    "linebreak-style": "off",
    "no-multiple-empty-lines": 0,
    "no-console": "off",
  },
};
