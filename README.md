Pergamum was an ancient library. This is a modern one.

WIP: Uses Laravel, Vue, Tailwind, Axios, Pinia

## Running locally

The stack is dockerized. Two modes:

```bash
docker compose up -d                  # prod-style: nginx serves public/build, no vite
docker compose --profile dev up -d    # dev: adds the vite container for HMR
```

The `vite` service sits behind the `dev` Compose profile, so a plain `up` will look like vite is missing — that's intentional. Reach for `--profile dev` whenever you're actually working on the frontend.
